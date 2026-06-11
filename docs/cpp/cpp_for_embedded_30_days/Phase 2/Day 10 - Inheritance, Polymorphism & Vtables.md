
You now have a complete, well-behaved value type in `SensorBuffer`. Today we shift to a different design question: what do you do when you have multiple things that share a common interface but different implementations? A temperature sensor and a humidity sensor both have `read()` and `name()` — but the read implementation is completely different. Inheritance and virtual functions solve this. The vtable is the mechanism. Understanding the cost before you use it is the discipline.

---

## 1. The Problem — Storing Mixed Types

Without polymorphism, storing different sensor types together requires ugly workarounds:

```cpp
// Bad — type tags and manual dispatch
enum class SensorType { Temperature, Humidity, Pressure };

struct AnySensor {
    SensorType type;
    union {
        TemperatureSensor* temp;
        HumiditySensor*    humidity;
        PressureSensor*    pressure;
    };
};

void read_sensor(AnySensor& s) {
    switch (s.type) {
        case SensorType::Temperature: s.temp->read();     break;
        case SensorType::Humidity:    s.humidity->read(); break;
        case SensorType::Pressure:    s.pressure->read(); break;
        // Add a new sensor type — must update every switch everywhere
    }
}
```

Every time you add a sensor type, you update every switch statement in the codebase. This is the Open/Closed Principle violation — the code isn't closed to modification when extended.

Virtual functions solve this by moving the dispatch into the object itself.

---

## 2. Inheritance Basics

Inheritance creates an is-a relationship. A `TemperatureSensor` is a `Sensor`. It inherits all of `Sensor`'s interface and can add or override behavior.

```cpp
class Sensor {                    // base class
public:
    float read();
    const char* name();
};

class TemperatureSensor : public Sensor {   // derived class
public:
    float read();         // hides Sensor::read — but doesn't override (no virtual)
    const char* name();   // same
};
```

Without `virtual`, calling `read()` through a `Sensor*` always calls `Sensor::read` — even if the pointer points to a `TemperatureSensor`. This is static dispatch — resolved at compile time based on the pointer type, not the object type.

```cpp
TemperatureSensor ts;
Sensor* p = &ts;

p->read();    // calls Sensor::read — WRONG if you wanted TemperatureSensor::read
ts.read();    // calls TemperatureSensor::read — correct, static dispatch
```

This is almost never what you want when using base class pointers. `virtual` fixes it.

---

## 3. Virtual Functions — Dynamic Dispatch

Add `virtual` to the base class function. Add `override` to the derived class function. Now the call dispatches based on the actual object type at runtime:

```cpp
class Sensor {
public:
    virtual float       read()  = 0;    // pure virtual — no implementation here
    virtual const char* name()  = 0;    // pure virtual
    virtual ~Sensor()           = default;  // virtual destructor — mandatory
};

class TemperatureSensor : public Sensor {
public:
    float       read()  override { return read_adc() * calibration_; }
    const char* name()  override { return "temperature"; }

private:
    float read_adc()  { return 23.5f; }  // simulate hardware read
    float calibration_ = 1.02f;
};

class HumiditySensor : public Sensor {
public:
    float       read()  override { return read_i2c_register() / 100.0f; }
    const char* name()  override { return "humidity"; }

private:
    float read_i2c_register() { return 6500.0f; }  // 65.00%
};
```

`= 0` makes a function **pure virtual** — no implementation in the base class, derived classes must provide one. A class with any pure virtual function is **abstract** — you cannot instantiate it directly. This is exactly what you want for an interface.

```cpp
Sensor s;             // compile error — Sensor is abstract
TemperatureSensor ts; // fine — all pure virtuals are overridden
```

Now the dispatch works correctly through a base class pointer:

```cpp
Sensor* p = new TemperatureSensor();
p->read();    // calls TemperatureSensor::read — correct
p->name();    // calls TemperatureSensor::name — correct

delete p;     // calls TemperatureSensor::~TemperatureSensor, then Sensor::~Sensor
              // ONLY if Sensor has a virtual destructor
```

---

## 4. The Virtual Destructor — Non-Negotiable

If you delete a derived object through a base pointer, and the base destructor is not virtual, only the base destructor runs. The derived destructor is skipped. This leaks any resources the derived class owns.

```cpp
class Bad {
public:
    ~Bad() {}   // non-virtual destructor
};

class Derived : public Bad {
    float* data_;
public:
    Derived() : data_(new float[1024]) {}
    ~Derived() { delete[] data_; }   // this never runs if deleted through Bad*
};

Bad* p = new Derived();
delete p;   // Bad::~Bad runs, Derived::~Derived does NOT — 1024 floats leaked
```

**Rule: if a class has any virtual function, give it a virtual destructor.** The cost is zero — you already have a vtable pointer.

```cpp
class Good {
public:
    virtual ~Good() = default;   // virtual — correct destruction through base pointer
};
```

---

## 5. The Vtable — What's Actually Happening

When you add any `virtual` function to a class, the compiler does two things:

**1. Adds a hidden vtable pointer (`vptr`) to every instance** — 8 bytes on 64-bit. It points to a table of function pointers for that class.

**2. Creates a vtable (virtual dispatch table) for each class** — a static array of function pointers, one per virtual function, in the order they're declared.

```
TemperatureSensor instance:
┌─────────────────┐
│ vptr ──────────────────────→ TemperatureSensor vtable:
│ calibration_    │             [0] TemperatureSensor::read
└─────────────────┘             [1] TemperatureSensor::name
                                [2] TemperatureSensor::~TemperatureSensor

HumiditySensor instance:
┌─────────────────┐
│ vptr ──────────────────────→ HumiditySensor vtable:
│ (members)       │             [0] HumiditySensor::read
└─────────────────┘             [1] HumiditySensor::name
                                [2] HumiditySensor::~HumiditySensor
```

A virtual call `p->read()` compiles to:

1. Load `vptr` from the object (one memory read)
2. Load function pointer from vtable at the right offset (one memory read)
3. Call through the function pointer (indirect call)

That's two extra memory reads and an indirect branch compared to a direct function call. The branch predictor handles this well for uniform collections (all the same type), poorly for randomly mixed types. For IoT code reading one sensor per second, this is irrelevant. For code calling a virtual function ten million times per second in a DSP loop, it matters.

### Object Size With Virtual Functions

```cpp
struct Plain       { float value; };                    // sizeof = 4
struct WithVirtual { virtual ~WithVirtual() = default;
                     float value; };                    // sizeof = 16 (8 vptr + 4 float + 4 padding)
```

For a sensor type instantiated once or a dozen times — negligible. For an array of 10 million small objects — 12 bytes overhead per object adds up.

---

## 6. `override` and `final` — Correctness Guarantees

`override` tells the compiler "this function is intended to override a virtual function in the base." If it doesn't match any base function exactly (wrong signature, wrong name), you get a compile error:

```cpp
class Sensor {
    virtual float read() = 0;
};

class Bad : public Sensor {
    float Read() override;    // compile error — no Sensor::Read to override
    float read(int) override; // compile error — signature mismatch
    float read();             // silently does NOT override — new function, hides base
};

class Good : public Sensor {
    float read() override;    // correct — matches Sensor::read exactly
};
```

Always use `override`. Without it, a typo or signature mismatch silently creates a new function instead of overriding — the base class virtual is still pure, and the compiler may not catch it.

`final` prevents further overriding or inheritance:

```cpp
class CalibrationSensor final : public Sensor {
    float read() override final;  // no further override allowed
};

class SpecialSensor : public CalibrationSensor {};  // compile error — final class
```

Use `final` when you know the hierarchy ends here — it also enables devirtualization optimizations.

---

## 7. Inheritance vs Composition

Inheritance is often overused. Before adding a base class, ask: is this an is-a relationship, or does it just share some behavior?

```cpp
// Wrong use of inheritance — Logger is not a Sensor
class LoggingSensor : public Sensor, public Logger { ... };

// Right — composition
class LoggingSensor : public Sensor {
    Logger logger_;   // has-a Logger, doesn't inherit it
public:
    float read() override {
        float v = do_read();
        logger_.log(v);
        return v;
    }
};
```

**Prefer composition when:**

- The relationship is has-a, not is-a
- You want to change behavior at runtime (swap the composed object)
- The base class has state you don't want (fat base class problem)
- You'd need multiple inheritance to get all the behavior

**Use inheritance when:**

- You genuinely need runtime polymorphism through a common interface
- The is-a relationship is semantically correct and stable

In modern C++ (and especially in embedded/IoT code), deep inheritance hierarchies are rare. A single abstract interface layer (`Sensor`, `Transport`, `Storage`) with concrete implementations is the common pattern.

---

## 8. Putting It Together — Sensor Hierarchy

Full implementation: abstract `Sensor` interface, three concrete sensors, stored in a mixed container, dispatched polymorphically:

```cpp
// sensor_hierarchy.cpp
#include <cstdio>
#include <cstdint>
#include <cmath>
#include <cstring>
#include <memory>
#include <vector>
#include <string>
#include <cassert>

// ---- Abstract Sensor Interface ----

class Sensor {
public:
    // Pure virtual interface — every sensor must implement these
    virtual float       read()           = 0;
    virtual const char* name()     const = 0;
    virtual const char* unit()     const = 0;
    virtual bool        is_ready() const = 0;

    // Non-pure virtual with default implementation
    virtual void        calibrate()      { /* default: no-op */ }
    virtual void        reset()          { sample_count_ = 0; }

    // Concrete utility built on the interface
    float read_averaged(int samples) {
        float sum = 0.0f;
        for (int i = 0; i < samples; ++i) sum += read();
        return sum / static_cast<float>(samples);
    }

    uint32_t sample_count() const { return sample_count_; }

    // Virtual destructor — mandatory
    virtual ~Sensor() = default;

protected:
    uint32_t sample_count_ = 0;   // shared state all sensors track
};

// ---- Concrete Sensor: Temperature (simulated ADC) ----

class TemperatureSensor : public Sensor {
public:
    explicit TemperatureSensor(uint8_t channel, float calibration = 1.0f)
        : channel_(channel)
        , calibration_(calibration)
        , noise_seed_(channel * 1234567u)
    {}

    float read() override {
        ++sample_count_;
        // Simulate ADC read with noise
        float raw = 23.5f + noise() * 0.5f;
        return raw * calibration_;
    }

    const char* name()     const override { return "temperature"; }
    const char* unit()     const override { return "°C"; }
    bool        is_ready() const override { return true; }

    void calibrate() override {
        calibration_ = 1.0f / read() * 23.5f;  // calibrate to known reference
        printf("  [%s ch%d] calibrated: factor=%.4f\n",
               name(), channel_, calibration_);
    }

private:
    float noise() {
        noise_seed_ ^= noise_seed_ << 13;
        noise_seed_ ^= noise_seed_ >> 7;
        noise_seed_ ^= noise_seed_ << 17;
        return static_cast<float>(noise_seed_ & 0xFF) / 255.0f - 0.5f;
    }

    uint8_t  channel_;
    float    calibration_;
    uint32_t noise_seed_;
};

// ---- Concrete Sensor: Humidity (simulated I2C) ----

class HumiditySensor : public Sensor {
public:
    explicit HumiditySensor(uint8_t address)
        : address_(address)
        , ready_(false)
        , warmup_reads_(0)
    {}

    float read() override {
        ++sample_count_;
        if (warmup_reads_ < 3) {
            ++warmup_reads_;
            if (warmup_reads_ >= 3) ready_ = true;
            return 0.0f;   // not warmed up yet
        }
        // Simulate I2C register read
        return 65.0f + static_cast<float>(address_) * 0.1f;
    }

    const char* name()     const override { return "humidity"; }
    const char* unit()     const override { return "%RH"; }
    bool        is_ready() const override { return ready_; }

    void reset() override {
        Sensor::reset();           // call base — resets sample_count_
        ready_       = false;
        warmup_reads_ = 0;
        printf("  [%s 0x%02X] reset — warmup required\n", name(), address_);
    }

private:
    uint8_t  address_;
    bool     ready_;
    int      warmup_reads_;
};

// ---- Concrete Sensor: Pressure (simulated SPI) ----

class PressureSensor : public Sensor {
public:
    explicit PressureSensor(float sea_level_hpa = 1013.25f)
        : sea_level_(sea_level_hpa)
    {}

    float read() override {
        ++sample_count_;
        return sea_level_ - static_cast<float>(sample_count_ % 10) * 0.1f;
    }

    const char* name()     const override { return "pressure"; }
    const char* unit()     const override { return "hPa"; }
    bool        is_ready() const override { return true; }

    // Altitude derived from pressure — built on the interface
    float altitude() {
        float p = read();
        return 44330.0f * (1.0f - std::pow(p / sea_level_, 0.1903f));
    }

private:
    float sea_level_;
};

// ---- SensorManager — polymorphic container ----

class SensorManager {
public:
    void add(std::unique_ptr<Sensor> sensor) {
        printf("  [manager] registered: %s\n", sensor->name());
        sensors_.push_back(std::move(sensor));
    }

    // Poll all ready sensors — dynamic dispatch here
    void poll_all() {
        printf("\n--- poll_all ---\n");
        for (auto& s : sensors_) {
            if (!s->is_ready()) {
                printf("  %-12s NOT READY (warming up)\n", s->name());
                continue;
            }
            float value = s->read();
            printf("  %-12s %.2f %s  (samples: %u)\n",
                   s->name(), value, s->unit(), s->sample_count());
        }
    }

    // Calibrate all sensors
    void calibrate_all() {
        printf("\n--- calibrate_all ---\n");
        for (auto& s : sensors_) s->calibrate();
    }

    // Reset all sensors
    void reset_all() {
        printf("\n--- reset_all ---\n");
        for (auto& s : sensors_) s->reset();
    }

    // Find by name
    Sensor* find(const char* name) {
        for (auto& s : sensors_) {
            if (std::strcmp(s->name(), name) == 0) return s.get();
        }
        return nullptr;
    }

    size_t count() const { return sensors_.size(); }

private:
    std::vector<std::unique_ptr<Sensor>> sensors_;
};

// ---- Main ----

int main() {
    printf("=== Sensor Hierarchy Demo ===\n\n");

    SensorManager manager;

    // Add mixed sensor types — stored as unique_ptr<Sensor>
    manager.add(std::make_unique<TemperatureSensor>(0, 1.0f));
    manager.add(std::make_unique<TemperatureSensor>(1, 0.98f));
    manager.add(std::make_unique<HumiditySensor>(0x40));
    manager.add(std::make_unique<PressureSensor>(1013.25f));

    printf("\nRegistered %zu sensors\n", manager.count());

    // First poll — humidity not ready yet
    manager.poll_all();
    manager.poll_all();
    manager.poll_all();
    // By third poll, humidity has warmed up
    manager.poll_all();

    // Calibrate all
    manager.calibrate_all();

    // Find a specific sensor by name and use it directly
    Sensor* temp = manager.find("temperature");
    if (temp) {
        printf("\nAveraged temperature: %.2f %s\n",
               temp->read_averaged(5), temp->unit());
    }

    // PressureSensor has extra capability — downcast needed
    // (in production, prefer designing the interface to avoid this)
    Sensor* pressure = manager.find("pressure");
    if (auto* ps = dynamic_cast<PressureSensor*>(pressure)) {
        printf("Altitude estimate: %.1f m\n", ps->altitude());
    }

    // Reset and re-poll
    manager.reset_all();
    manager.poll_all();

    printf("\nDone.\n");
    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address -o sensor_hier sensor_hierarchy.cpp
./sensor_hier
```

### What to observe

- `poll_all()` calls `s->read()`, `s->is_ready()`, `s->name()` on `unique_ptr<Sensor>` — the correct derived implementation runs for each, without any switch statement or type check
- `HumiditySensor::reset()` calls `Sensor::reset()` explicitly — the derived override can extend rather than replace the base behavior
- `dynamic_cast<PressureSensor*>` — the one place a downcast is used, to access `altitude()` which isn't in the base interface. In a real design you'd either add `altitude()` to the interface or not use the downcast
- Adding a new sensor type requires zero changes to `SensorManager` or `poll_all()` — just implement the interface and `add()` it

### Memory layout verification

Add this to observe the vtable pointer cost:

```cpp
printf("\nSizeof:\n");
printf("  Sensor (abstract):   %zu bytes\n", sizeof(TemperatureSensor) - sizeof(float)*2);
printf("  TemperatureSensor:   %zu bytes\n", sizeof(TemperatureSensor));
printf("  HumiditySensor:      %zu bytes\n", sizeof(HumiditySensor));
printf("  PressureSensor:      %zu bytes\n", sizeof(PressureSensor));
// First 8 bytes of any instance is the vptr on 64-bit
```

---

## Key Takeaways for Day 10

- `virtual` enables runtime dispatch based on actual object type — not pointer type. Without it, derived functions hide rather than override
- Pure virtual (`= 0`) makes a function mandatory for derived classes and makes the base class abstract — cannot be instantiated
- Virtual destructor is mandatory on any class with virtual functions — without it, deleting through a base pointer skips the derived destructor
- `override` on derived functions is mandatory in production code — catches signature mismatches and typos at compile time
- The vtable adds one pointer per instance (8 bytes on 64-bit) and two memory reads per virtual call — irrelevant for most IoT work, real for high-frequency DSP
- Composition over inheritance — use inheritance only for genuine is-a runtime polymorphism. Shared behavior that doesn't need runtime dispatch belongs in composition or free functions
- `dynamic_cast` is the escape hatch for downcasting — use it rarely, and treat its necessity as a design smell
- `std::vector<std::unique_ptr<Sensor>>` is the idiomatic heterogeneous container in C++ — polymorphism through pointers, ownership through `unique_ptr`

Phase 2 is complete. You now have a full toolkit: memory model, RAII, Rule of Five, operator overloading, and polymorphism. Phase 3 starts with templates — generic code that costs nothing at runtime and catches errors at compile time.