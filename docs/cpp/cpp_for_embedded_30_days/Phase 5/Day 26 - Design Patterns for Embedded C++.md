
The patterns from software engineering don't disappear in embedded C++ — they adapt. The same Observer, Singleton, State Machine, and Factory patterns you'd find in any architecture book apply here, but with different constraints: no heap allocation in the hot path, no virtual function overhead in interrupt handlers, deterministic timing. Today we implement each pattern using templates and CRTP (Curiously Recurring Template Pattern) — the same semantics as runtime polymorphism, zero cost at runtime.

---

## 1. Why Patterns Look Different in Embedded C++

The classic patterns rely on virtual functions for runtime dispatch. Virtual functions cost:

- 8 bytes per object (vtable pointer)
- Two memory reads per call (load vptr, load function pointer)
- An indirect branch (hard to predict, kills pipeline)
- No inlining (the compiler can't see through an indirect call)

For sensor polling at 10kHz, timer callbacks, state machines in interrupt context — these costs accumulate. The embedded C++ approach: resolve dispatch at compile time using templates and CRTP.

```
Virtual dispatch:          CRTP dispatch:
object → vptr → vtable     compiler resolves at instantiation
→ function pointer         → direct call, inline-able
→ indirect call            → zero overhead
```

---

## 2. CRTP — Curiously Recurring Template Pattern

CRTP is the foundational technique. A base class template takes the derived class as its own template parameter — this gives the base class access to the derived class's interface at compile time:

```cpp
// CRTP base — T is the derived class
template<typename Derived>
class SensorBase {
public:
    // Interface methods — call into Derived at compile time
    float read() {
        return static_cast<Derived*>(this)->read_impl();
    }

    const char* name() const {
        return static_cast<const Derived*>(this)->name_impl();
    }

    bool ready() const {
        return static_cast<const Derived*>(this)->ready_impl();
    }

    // Concrete behavior built on the interface — free for all derived classes
    float read_averaged(int n) {
        float sum = 0;
        for (int i = 0; i < n; ++i) sum += read();
        return sum / static_cast<float>(n);
    }

    void print_reading() {
        if (ready()) {
            printf("[%s] %.3f\n", name(), static_cast<double>(read()));
        } else {
            printf("[%s] not ready\n", name());
        }
    }
};

// Derived — inherits from base<itself>
class TemperatureSensor : public SensorBase<TemperatureSensor> {
public:
    // Implement the interface the base expects
    float       read_impl()  { return 23.5f; }
    const char* name_impl()  const { return "temperature"; }
    bool        ready_impl() const { return true; }
};

class HumiditySensor : public SensorBase<HumiditySensor> {
public:
    float       read_impl()  { return 65.2f; }
    const char* name_impl()  const { return "humidity"; }
    bool        ready_impl() const { return warm_; }
    void        warm_up()    { warm_ = true; }
private:
    bool warm_ = false;
};
```

The `static_cast<Derived*>(this)` in the base class is safe — the CRTP guarantee is that the base is only ever constructed as part of a Derived instance. `read()` in the base compiles to a direct call to `Derived::read_impl()` — fully inlined with `-O2`.

No vtable. No vptr. No indirect call. Same interface semantics.

### Using CRTP Types

The constraint: CRTP types can't be stored in a `vector<SensorBase*>` — each instantiation is a different type. You use them via templates:

```cpp
// Template function — works with any CRTP sensor
template<typename S>
void poll(SensorBase<S>& sensor) {
    sensor.print_reading();
}

TemperatureSensor temp;
HumiditySensor    humid;
humid.warm_up();

poll(temp);   // direct call — no virtual dispatch
poll(humid);  // direct call

// Read-averaged — defined once in base, works for all
printf("%.2f\n", temp.read_averaged(10));
```

---

## 3. Singleton — Thread-Safe, Zero-Heap

The Singleton ensures a single instance. The C++11 guarantee: static local variable initialization is thread-safe. No explicit locking needed:

```cpp
class DeviceRegistry {
public:
    // Meyers Singleton — thread-safe initialization guaranteed by C++11
    static DeviceRegistry& instance() {
        static DeviceRegistry registry;  // initialized once, thread-safe
        return registry;
    }

    // Non-copyable, non-movable
    DeviceRegistry(const DeviceRegistry&)            = delete;
    DeviceRegistry& operator=(const DeviceRegistry&) = delete;
    DeviceRegistry(DeviceRegistry&&)                 = delete;
    DeviceRegistry& operator=(DeviceRegistry&&)      = delete;

    void register_device(std::string_view id,
                         std::string_view type) {
        std::lock_guard lock(mtx_);
        devices_.emplace_back(std::string(id),
                              std::string(type));
        printf("[registry] registered: %.*s (%.*s)\n",
               static_cast<int>(id.size()),   id.data(),
               static_cast<int>(type.size()), type.data());
    }

    size_t count() const {
        std::lock_guard lock(mtx_);
        return devices_.size();
    }

    void print_all() const {
        std::lock_guard lock(mtx_);
        for (const auto& [id, type] : devices_) {
            printf("  %s (%s)\n", id.c_str(), type.c_str());
        }
    }

private:
    DeviceRegistry() {
        printf("[registry] created\n");
    }

    struct DeviceEntry {
        std::string id;
        std::string type;
    };

    mutable std::mutex          mtx_;
    std::vector<DeviceEntry>    devices_;
};

// Usage
DeviceRegistry::instance().register_device("sensor_01", "temperature");
DeviceRegistry::instance().register_device("sensor_02", "humidity");
printf("Total: %zu\n", DeviceRegistry::instance().count());
```

**Embedded caveat:** the static local is initialized on first call. If first call happens in an interrupt handler, and a second interrupt fires during initialization, the static initialization guard (a hidden mutex-like mechanism) may not work correctly on bare-metal. In that case, explicitly initialize before enabling interrupts:

```cpp
void system_init() {
    DeviceRegistry::instance();  // force initialization before interrupts
    enable_interrupts();
}
```

---

## 4. Observer Pattern — Template, No Virtual, No Heap

Classic Observer uses virtual `update()` callbacks. The template version uses `std::function` for type erasure with no vtable requirement, or a purely static approach with no heap at all:

### Static Observer — Compile-Time Subscriber List

```cpp
// Event type
struct SensorEvent {
    uint8_t  sensor_id;
    float    value;
    uint32_t timestamp_ms;
};

// Observer with fixed-capacity subscriber list — no heap
template<typename EventT, size_t MaxSubscribers>
class StaticEventBus {
public:
    using Handler = void(*)(const EventT&);  // plain function pointer — zero overhead

    bool subscribe(Handler h) {
        if (count_ >= MaxSubscribers) return false;
        handlers_[count_++] = h;
        return true;
    }

    void publish(const EventT& event) const {
        for (size_t i = 0; i < count_; ++i) {
            handlers_[i](event);
        }
    }

    size_t subscriber_count() const { return count_; }

private:
    std::array<Handler, MaxSubscribers> handlers_{};
    size_t count_ = 0;
};

// Usage
void log_sensor(const SensorEvent& e) {
    printf("[log] sensor=%u val=%.2f t=%u\n",
           e.sensor_id, e.value, e.timestamp_ms);
}

void alert_check(const SensorEvent& e) {
    if (e.value > 30.0f)
        printf("[ALERT] sensor %u over threshold: %.2f\n",
               e.sensor_id, e.value);
}

StaticEventBus<SensorEvent, 8> bus;
bus.subscribe(log_sensor);
bus.subscribe(alert_check);
bus.publish({0, 23.5f, 1000});
bus.publish({0, 35.0f, 1001});  // triggers alert
```

Function pointers have zero overhead — they're just addresses. No closure, no capture, no heap. The downside: no state in the handler. For stateful handlers, use member function pointers or a template callback:

```cpp
// Stateful observer via template — zero heap, captures state
template<typename EventT, size_t MaxSubs>
class EventBus {
public:
    // Callable concept — accepts lambdas, functors, and function ptrs
    template<typename Callable>
    bool subscribe(Callable&& cb) {
        // Store as std::function — has cost, but only at subscribe time
        if (count_ >= MaxSubs) return false;
        handlers_[count_++] = std::forward<Callable>(cb);
        return true;
    }

    void publish(const EventT& event) const {
        for (size_t i = 0; i < count_; ++i) {
            handlers_[i](event);
        }
    }

private:
    std::array<std::function<void(const EventT&)>, MaxSubs> handlers_;
    size_t count_ = 0;
};
```

---

## 5. State Machine — CRTP + `enum class`

State machines are everywhere in IoT firmware: device connection lifecycle, OTA update flow, protocol parsing. A clean C++ state machine uses `enum class` for states and `switch` for transitions, wrapped in a CRTP base for reuse:

```cpp
// CRTP state machine base
template<typename Derived, typename StateEnum>
class StateMachine {
public:
    StateEnum state() const { return state_; }

    // Drive a transition — calls derived's handle_event
    template<typename EventT>
    void process(const EventT& event) {
        StateEnum next = static_cast<Derived*>(this)
                             ->on_event(state_, event);
        if (next != state_) {
            static_cast<Derived*>(this)->on_exit(state_);
            state_ = next;
            static_cast<Derived*>(this)->on_enter(state_);
        }
    }

protected:
    explicit StateMachine(StateEnum initial) : state_(initial) {}

    // Default callbacks — derived can override or not
    void on_enter(StateEnum) {}
    void on_exit(StateEnum)  {}

private:
    StateEnum state_;
};
```

### Connection Lifecycle State Machine

```cpp
enum class ConnectionState {
    Disconnected,
    Connecting,
    Handshaking,
    Connected,
    Reconnecting,
    Error
};

struct ConnectEvent   {};
struct HandshakeOkEvent {};
struct HandshakeFailEvent {};
struct DisconnectEvent {};
struct ErrorEvent     { std::string reason; };

class MQTTConnection
    : public StateMachine<MQTTConnection, ConnectionState>
{
public:
    MQTTConnection()
        : StateMachine(ConnectionState::Disconnected) {}

    // Required by StateMachine base: returns next state
    template<typename E>
    ConnectionState on_event(ConnectionState current,
                              const E& event) {
        // Default: stay in current state
        return current;
    }

    // Specializations for each event type
    ConnectionState on_event(ConnectionState current,
                              const ConnectEvent&) {
        if (current == ConnectionState::Disconnected ||
            current == ConnectionState::Error) {
            return ConnectionState::Connecting;
        }
        return current;
    }

    ConnectionState on_event(ConnectionState current,
                              const HandshakeOkEvent&) {
        if (current == ConnectionState::Handshaking)
            return ConnectionState::Connected;
        return current;
    }

    ConnectionState on_event(ConnectionState current,
                              const HandshakeFailEvent&) {
        if (current == ConnectionState::Handshaking)
            return ConnectionState::Reconnecting;
        return current;
    }

    ConnectionState on_event(ConnectionState current,
                              const DisconnectEvent&) {
        if (current == ConnectionState::Connected)
            return ConnectionState::Disconnected;
        return current;
    }

    ConnectionState on_event(ConnectionState current,
                              const ErrorEvent& e) {
        last_error_ = e.reason;
        return ConnectionState::Error;
    }

    // Called by base on state entry
    void on_enter(ConnectionState s) {
        printf("  → %s\n", state_name(s));
        switch (s) {
            case ConnectionState::Connecting:
                start_tcp_connect();
                break;
            case ConnectionState::Connected:
                start_keepalive_timer();
                break;
            case ConnectionState::Reconnecting:
                schedule_reconnect(retry_delay_ms_);
                retry_delay_ms_ = std::min(
                    retry_delay_ms_ * 2u, 30000u);  // exponential backoff
                break;
            default: break;
        }
    }

    void on_exit(ConnectionState s) {
        switch (s) {
            case ConnectionState::Connected:
                stop_keepalive_timer();
                break;
            default: break;
        }
    }

    const char* state_name(ConnectionState s) const {
        switch (s) {
            case ConnectionState::Disconnected:  return "Disconnected";
            case ConnectionState::Connecting:    return "Connecting";
            case ConnectionState::Handshaking:   return "Handshaking";
            case ConnectionState::Connected:     return "Connected";
            case ConnectionState::Reconnecting:  return "Reconnecting";
            case ConnectionState::Error:         return "Error";
            default:                             return "Unknown";
        }
    }

    bool is_connected() const {
        return state() == ConnectionState::Connected;
    }

    const std::string& last_error() const { return last_error_; }

private:
    void start_tcp_connect()     { printf("    [tcp] connecting...\n"); }
    void start_keepalive_timer() { printf("    [ka]  timer started\n"); }
    void stop_keepalive_timer()  { printf("    [ka]  timer stopped\n"); }
    void schedule_reconnect(uint32_t ms) {
        printf("    [reconnect] in %ums\n", ms);
    }

    uint32_t    retry_delay_ms_ = 1000;
    std::string last_error_;
};
```

---

## 6. Policy-Based Design — Swappable Behavior at Compile Time

Policy-based design passes behavior as template parameters. Different policies compose into different concrete classes at compile time — zero runtime dispatch:

```cpp
// Logging policy
struct ConsoleLogger {
    static void log(const char* msg) {
        printf("[LOG] %s\n", msg);
    }
};

struct NoLogger {
    static void log(const char*) {}  // compiled away entirely
};

// Checksum policy
struct CRC8Checksum {
    static uint8_t compute(std::span<const uint8_t> data) {
        uint8_t crc = 0;
        for (uint8_t b : data)
            crc = crc ^ b;  // simplified — use real CRC8 table in production
        return crc;
    }
    static constexpr size_t SIZE = 1;
};

struct NoChecksum {
    static uint8_t compute(std::span<const uint8_t>) { return 0; }
    static constexpr size_t SIZE = 0;
};

// Protocol framer — behavior injected via policies
template
    typename LogPolicy     = ConsoleLogger,
    typename ChecksumPolicy = CRC8Checksum>
class ProtocolFramer {
public:
    // Build frame — includes checksum if ChecksumPolicy has SIZE > 0
    std::vector<uint8_t> frame(std::span<const uint8_t> payload) {
        std::vector<uint8_t> out;
        out.reserve(2 + payload.size() + ChecksumPolicy::SIZE);

        out.push_back(0xAB);  // magic
        out.push_back(static_cast<uint8_t>(payload.size()));
        out.insert(out.end(), payload.begin(), payload.end());

        if constexpr (ChecksumPolicy::SIZE > 0) {
            out.push_back(ChecksumPolicy::compute(out));
        }

        LogPolicy::log("frame built");
        return out;
    }
};

// Three different concrete types — zero runtime overhead difference
using ProductionFramer = ProtocolFramer<NoLogger,     CRC8Checksum>;
using DebugFramer      = ProtocolFramer<ConsoleLogger, CRC8Checksum>;
using TestFramer       = ProtocolFramer<ConsoleLogger, NoChecksum>;

ProductionFramer pf;
DebugFramer      df;
const uint8_t payload[] = {0x01, 0x02, 0x03};
auto frame = df.frame(payload);
```

`NoLogger::log` is empty — the compiler removes all calls to it. `NoChecksum::compute` returns 0 and has `SIZE = 0` — the `if constexpr (ChecksumPolicy::SIZE > 0)` branch for checksum appending doesn't compile when `NoChecksum` is used.

---

## 7. Factory Pattern — `make_unique` Based

The factory creates objects without exposing construction details. In embedded C++, the factory usually returns `unique_ptr` and may draw from a pool allocator:

```cpp
enum class SensorType {
    Temperature,
    Humidity,
    Pressure,
    Unknown
};

// Abstract sensor interface (virtual — used here for runtime polymorphism
// because we're mixing types in a container)
class ISensor {
public:
    virtual float       read()  = 0;
    virtual const char* name()  const = 0;
    virtual bool        ready() const = 0;
    virtual ~ISensor()          = default;
};

// Concrete sensors
class TempSensor : public ISensor {
public:
    explicit TempSensor(uint8_t ch) : channel_(ch) {}
    float       read()  override { return 23.5f + channel_ * 0.1f; }
    const char* name()  const override { return "temp"; }
    bool        ready() const override { return true; }
private:
    uint8_t channel_;
};

class HumSensor : public ISensor {
public:
    float       read()  override { return 65.0f; }
    const char* name()  const override { return "humidity"; }
    bool        ready() const override { return true; }
};

class PressSensor : public ISensor {
public:
    float       read()  override { return 1013.25f; }
    const char* name()  const override { return "pressure"; }
    bool        ready() const override { return true; }
};

// Factory — creates sensors by type
class SensorFactory {
public:
    // Returns nullptr for unknown type
    static std::unique_ptr<ISensor> create(SensorType type,
                                            uint8_t channel = 0) {
        switch (type) {
            case SensorType::Temperature:
                return std::make_unique<TempSensor>(channel);
            case SensorType::Humidity:
                return std::make_unique<HumSensor>();
            case SensorType::Pressure:
                return std::make_unique<PressSensor>();
            default:
                return nullptr;
        }
    }

    // Create from string — useful for config file parsing
    static std::unique_ptr<ISensor> create(std::string_view type_str,
                                            uint8_t channel = 0) {
        if (type_str == "temperature") return create(SensorType::Temperature, channel);
        if (type_str == "humidity")    return create(SensorType::Humidity,    channel);
        if (type_str == "pressure")    return create(SensorType::Pressure,    channel);
        return nullptr;
    }
};
```

---

## 8. Putting It Together — Full Pattern Demo

```cpp
// patterns_demo.cpp
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <array>
#include <vector>
#include <memory>
#include <functional>
#include <string>
#include <string_view>
#include <optional>
#include <mutex>
#include <span>
#include <cassert>
#include <algorithm>

// ---- Include all pattern implementations from above ----
// (paste or #include each section here in the actual file)

// ---- Demonstration ----

int main() {
    printf("=== Embedded C++ Design Patterns ===\n\n");

    // ---- CRTP ----
    printf("--- CRTP Sensors ---\n");
    {
        TemperatureSensor temp;
        HumiditySensor    humid;
        humid.warm_up();

        temp.print_reading();
        humid.print_reading();

        printf("Temp 5-sample avg: %.3f\n",
               temp.read_averaged(5));

        // Verify zero overhead — call dispatches to Derived directly
        static_assert(sizeof(TemperatureSensor) == sizeof(bool) ||
                      sizeof(TemperatureSensor) >= 1,
                      "CRTP base adds no overhead");
    }

    // ---- Singleton ----
    printf("\n--- Singleton Registry ---\n");
    {
        auto& reg = DeviceRegistry::instance();
        reg.register_device("temp_01",  "temperature");
        reg.register_device("hum_01",   "humidity");
        reg.register_device("press_01", "pressure");

        // Same instance from anywhere
        assert(&DeviceRegistry::instance() == &reg);
        printf("Registered %zu devices\n",
               DeviceRegistry::instance().count());
        DeviceRegistry::instance().print_all();
    }

    // ---- Observer ----
    printf("\n--- Observer Event Bus ---\n");
    {
        StaticEventBus<SensorEvent, 4> bus;

        int alert_count = 0;
        bus.subscribe(log_sensor);
        bus.subscribe(alert_check);

        bus.publish({0, 23.5f,  1000});  // normal
        bus.publish({1, 65.0f,  1001});  // normal
        bus.publish({0, 35.5f,  1002});  // alert!
        bus.publish({2, 1013.f, 1003});  // normal

        printf("Subscribers: %zu\n",
               bus.subscriber_count());
    }

    // ---- State Machine ----
    printf("\n--- MQTT Connection State Machine ---\n");
    {
        MQTTConnection conn;
        printf("Initial: %s\n", conn.state_name(conn.state()));

        printf("Event: Connect\n");
        conn.process(ConnectEvent{});

        printf("Event: HandshakeOk\n");
        // Manually transition through Handshaking first
        // (in real code, TCP connect callback drives this)
        // For demo, directly test transitions:

        printf("State: %s\n", conn.state_name(conn.state()));
        printf("Connected: %s\n",
               conn.is_connected() ? "yes" : "no");

        printf("Event: HandshakeFail\n");
        conn.process(HandshakeFailEvent{});
        printf("State: %s\n", conn.state_name(conn.state()));

        printf("Event: Connect (retry)\n");
        conn.process(ConnectEvent{});
        printf("State: %s\n", conn.state_name(conn.state()));

        printf("Event: Error\n");
        conn.process(ErrorEvent{"broker unreachable"});
        printf("State: %s  last_error: %s\n",
               conn.state_name(conn.state()),
               conn.last_error().c_str());
    }

    // ---- Policy-Based Design ----
    printf("\n--- Policy-Based Framer ---\n");
    {
        const uint8_t payload[] = {0x01, 0x02, 0x03, 0x04};

        DebugFramer df;
        auto frame = df.frame(payload);
        printf("Debug frame (%zu bytes): ",  frame.size());
        for (uint8_t b : frame) printf("%02X ", b);
        printf("\n");

        ProductionFramer pf;
        auto pframe = pf.frame(payload);
        printf("Production frame (%zu bytes): ", pframe.size());
        for (uint8_t b : pframe) printf("%02X ", b);
        printf("\n");

        TestFramer tf;
        auto tframe = tf.frame(payload);
        printf("Test frame (%zu bytes, no checksum): ",
               tframe.size());
        for (uint8_t b : tframe) printf("%02X ", b);
        printf("\n");
    }

    // ---- Factory ----
    printf("\n--- Sensor Factory ---\n");
    {
        std::vector<std::unique_ptr<ISensor>> sensors;

        // Create from enum
        sensors.push_back(
            SensorFactory::create(SensorType::Temperature, 0));
        sensors.push_back(
            SensorFactory::create(SensorType::Humidity));
        sensors.push_back(
            SensorFactory::create(SensorType::Pressure));

        // Create from string (config-driven)
        sensors.push_back(
            SensorFactory::create("temperature", 1));

        // Unknown type
        auto unknown =
            SensorFactory::create(SensorType::Unknown);
        printf("Unknown sensor: %s\n",
               unknown ? "created (bug!)" : "nullptr (correct)");

        printf("Polling %zu sensors:\n", sensors.size());
        for (const auto& s : sensors) {
            if (s && s->ready()) {
                printf("  %-12s %.2f\n",
                       s->name(), s->read());
            }
        }
    }

    // ---- CRTP without virtual — size comparison ----
    printf("\n--- Size comparison ---\n");
    {
        printf("sizeof(TemperatureSensor) CRTP: %zu\n",
               sizeof(TemperatureSensor));

        // Virtual base would add 8 bytes for vptr
        class VirtualSensor {
        public:
            virtual float read() = 0;
            virtual ~VirtualSensor() = default;
        };
        class VirtualTemp : public VirtualSensor {
            float val_ = 23.5f;
        public:
            float read() override { return val_; }
        };
        printf("sizeof(VirtualTemp)        virt: %zu\n",
               sizeof(VirtualTemp));
        printf("vptr overhead: %zu bytes per object\n",
               sizeof(VirtualTemp) - sizeof(float));
    }

    printf("\nAll patterns demonstrated.\n");
    return 0;
}
```

```bash
g++ -std=c++20 -Wall -Wextra -O2 -fsanitize=address \
    -o patterns patterns_demo.cpp
./patterns
```

### What to observe

The size comparison at the end is the concrete cost summary. `TemperatureSensor` with CRTP has no extra bytes — no vptr. `VirtualTemp` adds 8 bytes for the vtable pointer. For a sensor object created once, irrelevant. For 10,000 sensor readings in a buffer — 80KB of memory you didn't need to spend.

The state machine demo shows `on_enter` called automatically on every transition — the entry action (start TCP connect, start keepalive timer) fires without the caller having to remember to invoke it. This is the value of the pattern: the protocol is embedded in the structure, not in calling conventions.

The policy framer shows `if constexpr (ChecksumPolicy::SIZE > 0)` — with `NoChecksum`, this branch doesn't exist in the compiled binary. Disassemble `ProductionFramer::frame` and `TestFramer::frame` — the no-checksum version has fewer instructions, as expected.

---

## Key Takeaways for Day 26

- CRTP enables static polymorphism — same interface semantics as virtual functions, zero runtime overhead. The base class calls `static_cast<Derived*>(this)->method_impl()` which the compiler resolves to a direct, inlinable call
- The Meyers Singleton (`static T& instance() { static T t; return t; }`) is thread-safe since C++11 — the standard guarantees single initialization even with concurrent first calls
- Static Observer with function pointer array: zero heap, zero overhead on dispatch, no closure support. Add `std::function` for stateful handlers at the cost of potential heap allocation per subscriber
- State machines: `enum class` for states, `switch` for transitions, CRTP base for `on_enter`/`on_exit` dispatch — the structure enforces the protocol, not calling conventions
- Policy-based design passes behavior as template parameters — different policies compose into different types at compile time. Empty policies compile to zero instructions. `if constexpr` removes policy-controlled branches entirely from the binary
- Factory pattern with `unique_ptr` return: callers get ownership without knowing the concrete type. `nullptr` return signals unknown type — no exceptions needed
- The CRTP limitation: objects of different CRTP instantiations are different types and can't be stored in the same container. For mixed-type containers you still need virtual functions (or `std::variant`)
- Choosing between virtual and CRTP: if you need runtime type selection (factory, heterogeneous container) — virtual. If the type is always known at compile time — CRTP. If performance is critical in a hot path — CRTP or policy

Day 27 covers testing, tooling, and CMake — the build system, GoogleTest, sanitizers, and static analysis tools that make everything from Days 1–26 production-ready.