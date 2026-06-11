# Type Traits & SFINAE / Concepts (C++20)

Day 11's `RingBuffer<T, N>` had a `static_assert` requiring power-of-2 capacity. That's a constraint expressed as a runtime check that fires at compile time. Today we go further: constraining template parameters so that misuse produces clear errors at the call site, not deep inside template instantiation. We also build the machinery to make different code paths compile for different types — zero-overhead type dispatch at compile time.

---

## 1. Why Constraints Matter

Without constraints, template errors are notoriously bad:

```cpp
template<typename T>
T clamp(T value, T low, T high) {
    return value < low ? low : value > high ? high : value;
}

struct Config { int baud; };
Config a{9600}, b{115200}, c{57600};
clamp(a, b, c);  // What error do you get?
```

The error points inside `clamp` — "no match for operator<" applied to `Config` — not at the call site. With 10 levels of template nesting, this becomes unreadable. Constraints move the error to the call site with a message you control.

---

## 2. Type Traits — Querying Types at Compile Time

Type traits are compile-time predicates and transformations on types. They live in `<type_traits>`:

```cpp
#include <type_traits>

// ---- Category traits ----
std::is_integral_v<int>          // true
std::is_integral_v<float>        // false
std::is_floating_point_v<float>  // true
std::is_arithmetic_v<int>        // true — integral or floating point
std::is_pointer_v<int*>          // true
std::is_reference_v<int&>        // true
std::is_class_v<std::string>     // true
std::is_enum_v<SensorType>       // true
std::is_void_v<void>             // true

// ---- Qualifiers ----
std::is_const_v<const int>       // true
std::is_volatile_v<volatile int> // true

// ---- Memory layout traits ----
std::is_trivially_copyable_v<int>         // true — safe for memcpy
std::is_trivially_copyable_v<std::string> // false — has non-trivial copy
std::is_standard_layout_v<SensorRecord>   // true — C-compatible layout
std::is_trivial_v<int>                    // true — trivially constructible + copyable
std::is_pod_v<SensorRecord>               // true — plain old data (deprecated C++20)

// ---- Relationship traits ----
std::is_same_v<int, int>         // true
std::is_same_v<int, uint32_t>    // false — different types even if same size
std::is_base_of_v<Base, Derived> // true if Derived inherits from Base
std::is_convertible_v<float, int> // true — float converts to int

// ---- Compound checks ----
std::is_signed_v<int>            // true
std::is_unsigned_v<uint32_t>     // true
std::is_array_v<int[4]>          // true
std::is_function_v<void(int)>    // true
```

### Type Transformations

```cpp
// Remove qualifiers and references
std::remove_const_t<const int>       // int
std::remove_reference_t<int&>        // int
std::remove_cv_t<const volatile int> // int
std::decay_t<const int&>             // int — removes const, ref, array→ptr

// Add qualifiers
std::add_const_t<int>                // const int
std::add_pointer_t<int>              // int*

// Conditional type selection
std::conditional_t<true,  int, float>  // int
std::conditional_t<false, int, float>  // float

// Enable/disable based on condition
std::enable_if_t<std::is_integral_v<int>, int>  // int — condition true
// std::enable_if_t<std::is_integral_v<float>, int> // error — condition false

// Common underlying type
std::common_type_t<int, float>        // float — int converts to float
std::common_type_t<int, double>       // double
```

### Building Custom Traits

```cpp
// Custom trait: is T safe for DMA transfer?
// Requirements: trivially copyable + standard layout
template<typename T>
struct is_dma_safe
    : std::bool_constant
        std::is_trivially_copyable_v<T> &&
        std::is_standard_layout_v<T>>
{};

template<typename T>
constexpr bool is_dma_safe_v = is_dma_safe<T>::value;

// Verify
struct SensorReading { float value; uint32_t ts; };
static_assert( is_dma_safe_v<SensorReading>);  // passes
static_assert(!is_dma_safe_v<std::string>);    // passes — string has heap ptr

// Custom trait: does T have a .size() member?
template<typename T, typename = void>
struct has_size_member : std::false_type {};

template<typename T>
struct has_size_member<T,
    std::void_t<decltype(std::declval<T>().size())>>
    : std::true_type {};

template<typename T>
constexpr bool has_size_member_v = has_size_member<T>::value;

static_assert( has_size_member_v<std::vector<int>>);
static_assert( has_size_member_v<std::string>);
static_assert(!has_size_member_v<int>);
```

`std::void_t<...>` is a C++17 utility that maps any well-formed type expression to `void`, and causes substitution failure if the expression is ill-formed. This is the detection idiom — the foundation of trait-based dispatch.

---

## 3. SFINAE — Substitution Failure Is Not An Error

SFINAE is the mechanism by which template overloads are silently discarded when substitution fails, rather than causing a hard error. It enables compile-time overload selection based on type properties.

```cpp
// enable_if — include this overload only for integral types
template<typename T>
std::enable_if_t<std::is_integral_v<T>, T>
serialize(T value) {
    printf("serialize integral: %lld\n",
           static_cast<long long>(value));
    return value;
}

// Different overload for floating-point types
template<typename T>
std::enable_if_t<std::is_floating_point_v<T>, T>
serialize(T value) {
    printf("serialize float: %f\n",
           static_cast<double>(value));
    return value;
}

serialize(42);      // calls integral version
serialize(3.14f);   // calls float version
// serialize("hi");  // no match — compile error
```

### SFINAE on Template Parameters

The cleanest SFINAE syntax uses a defaulted template parameter:

```cpp
// Method 1: return type SFINAE (most common pre-C++20)
template<typename T,
         typename = std::enable_if_t<std::is_integral_v<T>>>
void process_integral(T value) {
    printf("integral: %lld\n", static_cast<long long>(value));
}

// Method 2: non-type template parameter SFINAE
template<typename T,
         std::enable_if_t<std::is_integral_v<T>, int> = 0>
void process_integral2(T value) {
    printf("integral2: %lld\n", static_cast<long long>(value));
}

// Method 3: in return type
template<typename T>
auto to_bytes(T value)
    -> std::enable_if_t<std::is_trivially_copyable_v<T>,
                        std::array<uint8_t, sizeof(T)>>
{
    std::array<uint8_t, sizeof(T)> bytes;
    std::memcpy(bytes.data(), &value, sizeof(T));
    return bytes;
}
```

SFINAE works but the syntax is verbose and error messages are poor. C++20 Concepts replace all of this.

---

## 4. C++20 Concepts — Constraints Done Right

Concepts give you named, composable constraints on template parameters. They produce clean error messages at the call site, and the syntax reads like what it means:

```cpp
#include <concepts>

// ---- Built-in concepts (C++20 standard library) ----
std::integral<int>           // true — integral type
std::floating_point<float>   // true — floating-point type
std::arithmetic<double>      // true — integral or floating point
std::signed_integral<int>    // true
std::unsigned_integral<uint32_t> // true
std::same_as<int, int>       // true
std::derived_from<Derived, Base> // true
std::convertible_to<float, int>  // true
std::invocable<Func, Args...>    // true if callable with given args
std::copy_constructible<T>       // true
std::move_constructible<T>       // true
std::default_initializable<T>    // true
std::totally_ordered<T>          // true if T has <, >, <=, >=, ==, !=

// ---- Defining a concept ----
template<typename T>
concept Numeric = std::integral<T> || std::floating_point<T>;

template<typename T>
concept DmaSafe = std::is_trivially_copyable_v<T>
               && std::is_standard_layout_v<T>;

template<typename T>
concept Sensor = requires(T sensor) {
    { sensor.read()  } -> std::convertible_to<float>;
    { sensor.name()  } -> std::convertible_to<std::string_view>;
    { sensor.ready() } -> std::same_as<bool>;
};

// ---- Using concepts ----

// Syntax 1: requires clause
template<typename T>
requires Numeric<T>
T clamp(T value, T low, T high) {
    return value < low ? low : value > high ? high : value;
}

// Syntax 2: abbreviated (C++20 preferred)
template<Numeric T>
T clamp2(T value, T low, T high) {
    return value < low ? low : value > high ? high : value;
}

// Syntax 3: auto with concept
auto clamp3(Numeric auto value,
            Numeric auto low,
            Numeric auto high) {
    return value < low ? low : value > high ? high : value;
}

clamp(23.5f, 0.0f, 100.0f);  // fine — float is Numeric
clamp(42, 0, 100);             // fine — int is Numeric
// clamp(Config{}, Config{}, Config{});  // error: Config doesn't satisfy Numeric
// Error message: "Config does not satisfy Numeric"  ← clear, at call site
```

### `requires` Expressions — Specifying Interface Requirements

A `requires` expression checks whether a type supports specific operations:

```cpp
// Does T support operator+ with itself?
template<typename T>
concept Addable = requires(T a, T b) {
    { a + b } -> std::same_as<T>;
};

// Does T have a serialize() method returning std::vector<uint8_t>?
template<typename T>
concept Serializable = requires(const T& obj) {
    { obj.serialize() } -> std::same_as<std::vector<uint8_t>>;
    { T::deserialize(std::declval<std::span<const uint8_t>>()) }
        -> std::same_as<T>;
};

// Does T support container operations?
template<typename T>
concept Container = requires(T c) {
    typename T::value_type;           // has value_type typedef
    { c.begin() } -> std::input_or_output_iterator;
    { c.end()   } -> std::input_or_output_iterator;
    { c.size()  } -> std::convertible_to<std::size_t>;
    { c.empty() } -> std::same_as<bool>;
};

// Compound requires — nesting
template<typename T>
concept SensorBuffer = Container<T>
                    && requires(T buf) {
    { buf.push(std::declval<typename T::value_type>()) }
        -> std::same_as<bool>;
    { buf.full()  } -> std::same_as<bool>;
    requires std::floating_point<typename T::value_type>;
};
```

### Concepts and Function Overloading

Concepts enable clean overload sets — each overload with a different constraint:

```cpp
template<std::integral T>
void log_value(T v) {
    printf("int: %lld\n", static_cast<long long>(v));
}

template<std::floating_point T>
void log_value(T v) {
    printf("float: %.6f\n", static_cast<double>(v));
}

template<typename T>
requires (!std::integral<T> && !std::floating_point<T>)
void log_value(T v) {
    printf("other type\n");
}

log_value(42);      // → int: 42
log_value(3.14f);   // → float: 3.140000
log_value("hello"); // → other type
```

---

## 5. `std::ranges` — Algorithms With Concepts

C++20's `std::ranges` namespace provides algorithm versions that take a range directly (instead of iterator pairs) and use Concepts to express requirements:

```cpp
#include <algorithm>
#include <ranges>
#include <vector>

std::vector<float> readings = {23.5f, 24.1f, 21.8f, 25.6f, 22.3f};

// ranges algorithms — take container directly
std::ranges::sort(readings);                          // sort in place
auto it = std::ranges::find(readings, 24.1f);         // find element
bool any = std::ranges::any_of(readings,
    [](float v) { return v > 25.0f; });               // predicate check

// Projections — apply a transform for comparison
struct Reading { float value; uint32_t ts; };
std::vector<Reading> data = { {23.5f, 100}, {21.1f, 200}, {24.8f, 150} };

std::ranges::sort(data, {}, &Reading::value);         // sort by .value
std::ranges::sort(data, std::greater{}, &Reading::ts); // sort by .ts descending

auto max_it = std::ranges::max_element(data, {}, &Reading::value);
printf("max reading: %.2f\n", max_it->value);

// Views — lazy, composable range operations
auto high = readings
    | std::views::filter([](float v) { return v > 23.0f; })
    | std::views::transform([](float v) { return v * 1.8f + 32.0f; });
// high is a lazy view — nothing computed yet

for (float f : high) {
    printf("%.2f°F  ", f);   // computed on demand
}
printf("\n");
```

Views are lazy — no allocation, no intermediate storage. Each element is computed as it's consumed. For IoT code processing sensor streams, this is the right model.

---

## 6. Putting It Together — Constrained `RingBuffer`

Apply Concepts to constrain the `RingBuffer` from Day 11, and add a type-dispatched `serialize` that handles different element types correctly:

```cpp
// constrained_ring_buffer.cpp
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <cmath>
#include <array>
#include <span>
#include <optional>
#include <type_traits>
#include <concepts>
#include <cassert>
#include <vector>
#include <string_view>
#include <algorithm>

// ---- Custom concepts ----

// T is safe to copy via memcpy — POD-like
template<typename T>
concept TriviallyCopyable =
    std::is_trivially_copyable_v<T> &&
    std::is_standard_layout_v<T>;

// T supports the ring buffer element interface
template<typename T>
concept RingElement =
    std::move_constructible<T> &&
    std::is_destructible_v<T>;

// T is a numeric sensor value type
template<typename T>
concept SensorValue =
    std::floating_point<T> || std::unsigned_integral<T>;

// T behaves like a Sensor (has read() and name())
template<typename T>
concept SensorInterface = requires(T sensor) {
    { sensor.read()   } -> SensorValue;
    { sensor.name()   } -> std::convertible_to<std::string_view>;
    { sensor.ready()  } -> std::same_as<bool>;
};

// ---- Constrained RingBuffer ----

template<RingElement T, size_t Capacity>
    requires (Capacity > 0)
          && ((Capacity & (Capacity - 1)) == 0)   // power of 2
class RingBuffer {
    static constexpr size_t MASK = Capacity - 1;

public:
    RingBuffer() : head_(0), tail_(0), count_(0) {}

    bool push(const T& item)
        requires std::copy_constructible<T>
    {
        if (full()) return false;
        data_[head_] = item;
        head_ = (head_ + 1) & MASK;
        ++count_;
        return true;
    }

    bool push(T&& item)
        requires std::move_constructible<T>
    {
        if (full()) return false;
        data_[head_] = std::move(item);
        head_ = (head_ + 1) & MASK;
        ++count_;
        return true;
    }

    template<typename... Args>
        requires std::constructible_from<T, Args...>
    bool emplace(Args&&... args) {
        if (full()) return false;
        data_[head_] = T(std::forward<Args>(args)...);
        head_ = (head_ + 1) & MASK;
        ++count_;
        return true;
    }

    std::optional<T> pop() {
        if (empty()) return std::nullopt;
        T item = std::move(data_[tail_]);
        tail_ = (tail_ + 1) & MASK;
        --count_;
        return item;
    }

    const T* peek() const {
        return empty() ? nullptr : &data_[tail_];
    }

    // Serialize entire contents to bytes — only for TriviallyCopyable T
    std::vector<uint8_t> to_bytes() const
        requires TriviallyCopyable<T>
    {
        std::vector<uint8_t> out;
        out.reserve(count_ * sizeof(T));
        for (size_t i = 0; i < count_; ++i) {
            size_t idx = (tail_ + i) & MASK;
            const uint8_t* p =
                reinterpret_cast<const uint8_t*>(&data_[idx]);
            out.insert(out.end(), p, p + sizeof(T));
        }
        return out;
    }

    // Average — only for SensorValue T
    T average() const
        requires SensorValue<T>
    {
        if (count_ == 0) return T{0};
        double sum = 0.0;
        for (size_t i = 0; i < count_; ++i) {
            sum += static_cast<double>(data_[(tail_ + i) & MASK]);
        }
        return static_cast<T>(sum / static_cast<double>(count_));
    }

    // Standard deviation — only for floating_point T
    T stddev() const
        requires std::floating_point<T>
    {
        if (count_ < 2) return T{0};
        T avg = average();
        double var = 0.0;
        for (size_t i = 0; i < count_; ++i) {
            double d = static_cast<double>(
                data_[(tail_ + i) & MASK]) -
                static_cast<double>(avg);
            var += d * d;
        }
        return static_cast<T>(
            std::sqrt(var / static_cast<double>(count_)));
    }

    bool   empty()    const { return count_ == 0; }
    bool   full()     const { return count_ == Capacity; }
    size_t size()     const { return count_; }
    size_t capacity() const { return Capacity; }

private:
    std::array<T, Capacity> data_;
    size_t head_, tail_, count_;
};

// ---- Type-dispatched serializer using concepts ----

// Serialize any arithmetic type to little-endian bytes
template<std::integral T>
std::vector<uint8_t> to_bytes(T value) {
    std::vector<uint8_t> out(sizeof(T));
    for (size_t i = 0; i < sizeof(T); ++i) {
        out[i] = static_cast<uint8_t>(
            (static_cast<std::make_unsigned_t<T>>(value) >>
             (i * 8)) & 0xFF);
    }
    return out;
}

template<std::floating_point T>
std::vector<uint8_t> to_bytes(T value) {
    std::vector<uint8_t> out(sizeof(T));
    std::memcpy(out.data(), &value, sizeof(T));
    return out;
}

template<TriviallyCopyable T>
    requires (!std::integral<T>) && (!std::floating_point<T>)
std::vector<uint8_t> to_bytes(const T& value) {
    std::vector<uint8_t> out(sizeof(T));
    std::memcpy(out.data(), &value, sizeof(T));
    return out;
}

// ---- Concept-checked sensor reader ----

template<SensorInterface S>
void poll_sensor(S& sensor) {
    if (!sensor.ready()) {
        printf("  [%.*s] not ready\n",
               static_cast<int>(sensor.name().size()),
               sensor.name().data());
        return;
    }
    auto v = sensor.read();
    printf("  [%.*s] %.3f\n",
           static_cast<int>(sensor.name().size()),
           sensor.name().data(),
           static_cast<double>(v));
}

// ---- Concrete sensors that satisfy SensorInterface ----

class TemperatureSensor {
    float value_;
    int   reads_ = 0;
public:
    explicit TemperatureSensor(float v) : value_(v) {}
    float            read()  { ++reads_; return value_; }
    std::string_view name()  const { return "temperature"; }
    bool             ready() const { return true; }
};

class HumiditySensor {
    float value_;
    bool  warmed_up_ = false;
    int   reads_     = 0;
public:
    explicit HumiditySensor(float v) : value_(v) {}
    float            read()  {
        ++reads_;
        if (reads_ >= 2) warmed_up_ = true;
        return warmed_up_ ? value_ : 0.0f;
    }
    std::string_view name()  const { return "humidity"; }
    bool             ready() const { return warmed_up_; }
};

// Does NOT satisfy SensorInterface — missing ready()
class BadSensor {
public:
    float read() { return 0.0f; }
    std::string_view name() const { return "bad"; }
    // no ready() member
};

// Verify concepts at compile time
static_assert( SensorInterface<TemperatureSensor>);
static_assert( SensorInterface<HumiditySensor>);
static_assert(!SensorInterface<BadSensor>);          // missing ready()
static_assert(!SensorInterface<int>);

static_assert( TriviallyCopyable<float>);
static_assert( TriviallyCopyable<uint32_t>);
static_assert(!TriviallyCopyable<std::string>);
static_assert(!TriviallyCopyable<std::vector<int>>);

struct SensorReading { float value; uint32_t ts; };
static_assert(TriviallyCopyable<SensorReading>);
static_assert(SensorValue<float>);
static_assert(SensorValue<uint16_t>);
static_assert(!SensorValue<std::string>);

// ---- Compile-time constraint violations ----
// These would be compile errors — uncomment to verify:
// RingBuffer<int, 3>   bad_cap;      // error: 3 not power of 2
// RingBuffer<int, 0>   zero_cap;     // error: Capacity > 0 violated
// RingBuffer<std::unique_ptr<int>, 8> no_copy; // OK — move only

int main() {
    printf("=== Concepts & Type Traits ===\n\n");

    // ---- Float ring buffer — SensorValue methods available ----
    printf("--- float RingBuffer (SensorValue) ---\n");
    {
        RingBuffer<float, 8> buf;
        for (float v : {23.5f, 24.1f, 22.8f, 25.6f, 23.1f}) {
            buf.push(v);
        }

        printf("Size: %zu  avg: %.3f  stddev: %.3f\n",
               buf.size(), buf.average(), buf.stddev());

        // to_bytes available — float is TriviallyCopyable
        auto bytes = buf.to_bytes();
        printf("Serialized: %zu bytes for %zu floats\n",
               bytes.size(), buf.size());
        assert(bytes.size() == buf.size() * sizeof(float));
    }

    // ---- uint16_t ring buffer — ADC values ----
    printf("\n--- uint16_t RingBuffer (ADC samples) ---\n");
    {
        RingBuffer<uint16_t, 16> adc;
        for (uint16_t v = 0; v < 10; ++v) {
            adc.push(static_cast<uint16_t>(1000 + v * 50));
        }

        printf("ADC avg: %u  (raw count)\n", adc.average());
        // stddev() not available — uint16_t is not floating_point
        // adc.stddev();  // compile error — correct

        auto bytes = adc.to_bytes();
        printf("Serialized %zu bytes\n", bytes.size());
    }

    // ---- SensorReading ring buffer ----
    printf("\n--- SensorReading RingBuffer (TriviallyCopyable) ---\n");
    {
        RingBuffer<SensorReading, 8> readings;
        readings.emplace(23.5f, uint32_t(1000));
        readings.emplace(24.1f, uint32_t(1001));
        readings.emplace(22.8f, uint32_t(1002));

        // to_bytes available — SensorReading is TriviallyCopyable
        auto bytes = readings.to_bytes();
        printf("Serialized %zu readings = %zu bytes\n",
               readings.size(), bytes.size());

        // average() NOT available — SensorReading is not SensorValue
        // readings.average();  // compile error — correct

        // Verify round-trip
        assert(bytes.size() == readings.size() * sizeof(SensorReading));
        SensorReading rec{};
        std::memcpy(&rec, bytes.data(), sizeof(SensorReading));
        printf("First record: value=%.2f ts=%u\n",
               rec.value, rec.ts);
    }

    // ---- Type-dispatched to_bytes ----
    printf("\n--- Type-dispatched to_bytes ---\n");
    {
        auto b_int   = to_bytes(uint32_t(0xDEADBEEF));
        auto b_float = to_bytes(3.14f);
        auto b_rec   = to_bytes(SensorReading{23.5f, 1000});

        printf("uint32_t: ");
        for (uint8_t b : b_int)   printf("%02X ", b);
        printf("\nfloat:    ");
        for (uint8_t b : b_float) printf("%02X ", b);
        printf("\nReading:  ");
        for (uint8_t b : b_rec)   printf("%02X ", b);
        printf("\n");
    }

    // ---- Concept-checked sensor polling ----
    printf("\n--- Concept-checked sensors ---\n");
    {
        TemperatureSensor temp(23.5f);
        HumiditySensor    humid(65.2f);

        // First poll — humidity not ready
        poll_sensor(temp);
        poll_sensor(humid);
        humid.read();  // warm up
        poll_sensor(humid);  // now ready

        // poll_sensor(BadSensor{});  // compile error — no ready()
        // Error: "BadSensor does not satisfy SensorInterface"
    }

    // ---- std::ranges with projections ----
    printf("\n--- std::ranges with projections ---\n");
    {
        struct Device {
            std::string name;
            float       last_reading;
            uint32_t    timestamp;
        };

        std::vector<Device> devices = {
            {"temp_01", 23.5f,  1005},
            {"hum_01",  65.2f,  1001},
            {"pres_01", 1013.f, 1008},
            {"temp_02", 22.1f,  1003},
        };

        // Sort by last_reading (projection onto member)
        std::ranges::sort(devices, {}, &Device::last_reading);
        printf("Sorted by reading:\n");
        for (const auto& d : devices) {
            printf("  %-10s %.2f\n",
                   d.name.c_str(), d.last_reading);
        }

        // Filter + transform with views
        auto recent_high = devices
            | std::views::filter([](const Device& d) {
                  return d.timestamp > 1002;
              })
            | std::views::transform([](const Device& d) {
                  return d.last_reading;
              });

        printf("Recent high readings:");
        for (float v : recent_high) printf(" %.2f", v);
        printf("\n");

        // Find device with max reading
        auto max_it = std::ranges::max_element(
            devices, {}, &Device::last_reading);
        printf("Max reading device: %s (%.2f)\n",
               max_it->name.c_str(),
               max_it->last_reading);
    }

    printf("\nAll static_asserts passed. All tests passed.\n");
    return 0;
}
```

```bash
g++ -std=c++20 -Wall -Wextra -fsanitize=address,undefined \
    -o concepts constrained_ring_buffer.cpp
./concepts
```

### What to observe

The `static_assert(!SensorInterface<BadSensor>)` confirms the concept machinery works. Uncomment `poll_sensor(BadSensor{})` and observe the error message — it says `BadSensor does not satisfy SensorInterface` at the call site, not deep inside `poll_sensor`. Compare this to what you'd get from a raw `static_assert` buried in a template.

The `stddev()` method on `RingBuffer<uint16_t, 16>` doesn't compile — `uint16_t` doesn't satisfy `std::floating_point`. Try calling it and observe the error. This is concept-constrained member functions: the method exists in source but isn't visible to callers whose type doesn't satisfy the constraint.

`to_bytes` has three overloads, selected by concept. Call it with `int`, `float`, and `SensorReading` — each dispatches to the correct implementation with no runtime overhead.

---

## Key Takeaways for Day 24

- Type traits are compile-time predicates on types — `std::is_integral_v<T>`, `std::is_trivially_copyable_v<T>`, `std::is_same_v<T,U>`. They return `bool` constants usable in `static_assert`, `if constexpr`, and SFINAE
- `std::void_t<expr>` maps any well-formed expression to `void` and causes substitution failure if ill-formed — the detection idiom for checking whether a type has a specific member or operation
- SFINAE (`std::enable_if_t`) selects template overloads at compile time — powerful but verbose. The syntax is the reason Concepts were added to C++20
- C++20 Concepts are named, composable constraints — `template<Numeric T>` reads like what it means, and errors appear at the call site with a message you control
- `requires` expressions check whether a type supports specific operations — `{ expr } -> concept` verifies both that the expression is valid and that its type satisfies the constraint
- Concept-constrained member functions — `T average() const requires SensorValue<T>` — make methods conditionally available based on the element type. The method doesn't exist for types that don't satisfy the constraint
- `std::ranges` algorithms take containers directly (not iterator pairs) and use Concepts to express requirements. Projections (`&Device::last_reading`) apply a transform for comparison without modifying data
- `std::views` are lazy, composable range operations — filter, transform, take, drop. No intermediate containers, no allocation, computed on demand

Day 25 covers binary protocols and bit manipulation — the full toolkit for parsing Modbus RTU frames, hardware registers, and packed protocol fields with zero allocation.