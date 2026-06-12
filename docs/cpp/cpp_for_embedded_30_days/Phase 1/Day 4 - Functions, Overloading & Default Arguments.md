

Day 3 ended with a binary frame parser that used `memcpy` to safely move bits between types, and `static_assert` to catch layout bugs at compile time. Today we go deep on C++ functions — the features that make them significantly more powerful than C functions. Overloading, default arguments, `constexpr`, `inline`, and `std::function` are the tools. The exercise is a type-safe serializer for your fixed-width integer types.

---

## 1. Function Overloading

In C, every function needs a unique name. If you want to serialize a `uint8_t`, a `uint16_t`, and a `uint32_t`, you write `serialize_u8`, `serialize_u16`, `serialize_u32`. In C++ you write `serialize` three times — the compiler picks the right one based on argument types at the call site:

```cpp
#include <cstdint>
#include <cstdio>

void serialize(uint8_t value, uint8_t* buf) {
    buf[0] = value;
}

void serialize(uint16_t value, uint8_t* buf) {
    buf[0] = static_cast<uint8_t>(value & 0xFF);
    buf[1] = static_cast<uint8_t>(value >> 8);
}

void serialize(uint32_t value, uint8_t* buf) {
    buf[0] = static_cast<uint8_t>(value & 0xFF);
    buf[1] = static_cast<uint8_t>((value >>  8) & 0xFF);
    buf[2] = static_cast<uint8_t>((value >> 16) & 0xFF);
    buf[3] = static_cast<uint8_t>((value >> 24) & 0xFF);
}

uint8_t buf[4];
serialize(uint8_t(0x42),     buf);  // calls first overload
serialize(uint16_t(0x1234),  buf);  // calls second overload
serialize(uint32_t(0xDEAD),  buf);  // calls third overload
```

### How Overload Resolution Works

The compiler ranks candidates and picks the best match. The ranking (simplified):

1. Exact match — argument type matches parameter type exactly
2. Promotion — `float` → `double`, `char` → `int`
3. Standard conversion — `int` → `double`, `double` → `int`
4. User-defined conversion — via constructor or `operator T()`
5. Variadic (`...`)

```cpp
void f(int x)    { printf("int\n"); }
void f(double x) { printf("double\n"); }
void f(float x)  { printf("float\n"); }

f(42);     // int — exact match
f(3.14);   // double — literal 3.14 is double
f(3.14f);  // float — exact match
f('A');    // int — char promotes to int
```

### Ambiguity — When the Compiler Can't Choose

```cpp
void g(int x,    double y) { }
void g(double x, int y)    { }

g(1, 2);      // ambiguous — both require one conversion
g(1.0, 2);    // unambiguous — g(double, int) is exact on first arg
g(1, 2.0);    // unambiguous — g(int, double) is exact on first arg
```

Ambiguity is a compile error. Fix it by being explicit at the call site:

```cpp
g(static_cast<double>(1), 2);  // forces g(double, int)
```

### What Can Be Overloaded

Overloads must differ in parameter **types** or **count** — not in return type:

```cpp
// Legal overloads
void process(int x);
void process(float x);
void process(int x, int y);

// ILLEGAL — same parameters, different return type
int  compute(int x);
void compute(int x);  // error: redefinition
```

---

## 2. Default Arguments

Default arguments let callers omit trailing parameters. The defaults must be specified in the declaration (usually the header):

```cpp
// Declaration — defaults here
void configure_uart(int baud_rate,
                    int data_bits  = 8,
                    int stop_bits  = 1,
                    bool parity    = false);

// Definition — no defaults repeated here
void configure_uart(int baud_rate,
                    int data_bits,
                    int stop_bits,
                    bool parity) {
    printf("baud=%d bits=%d stop=%d parity=%d\n",
           baud_rate, data_bits, stop_bits, parity);
}

// Call sites
configure_uart(9600);               // 8N1 — most common
configure_uart(115200);             // 8N1 at higher rate
configure_uart(9600, 7, 1, true);   // 7E1 — explicit everything
```

### Default Argument Rules

**Must be rightmost:** you can't have a default argument followed by a non-default argument:

```cpp
void bad(int a = 0, int b);     // error — b has no default
void ok(int a, int b = 0);      // fine
void ok2(int a = 0, int b = 0); // fine
```

**One-time declaration:** defaults go in the first declaration the compiler sees. Don't repeat them in the definition:

```cpp
// header.hpp
void read(int id, int samples = 1);

// impl.cpp
void read(int id, int samples = 1) { }  // ERROR — redeclared default
void read(int id, int samples)     { }  // correct
```

**Can reference earlier parameters? No.** Default arguments are evaluated at the call site, not at the declaration:

```cpp
// This is illegal — parameter can't reference another parameter
void f(int a, int b = a);  // error

// But global or static expressions are fine
constexpr int DEFAULT_SAMPLES = 4;
void read(int id, int samples = DEFAULT_SAMPLES);
```

### Default Arguments vs Overloading

They're not interchangeable. Use default arguments when the function does the same thing regardless of how many parameters are provided. Use overloading when the behavior genuinely differs by type:

```cpp
// Default argument — same operation, optional tuning
void send(const uint8_t* buf, size_t len,
          int timeout_ms = 1000);

// Overloading — different behavior per type
void send(const char* str);         // null-terminated string
void send(const uint8_t* buf, size_t len); // binary buffer
void send(uint8_t byte);            // single byte
```

---

## 3. `inline` Functions

`inline` is a hint to the compiler that the function body should be substituted at the call site — no function call overhead. In modern C++, `inline` has a second meaning: it allows the function definition to appear in multiple translation units (headers) without violating the One Definition Rule.

```cpp
// In a header — inline allows multiple TUs to include this
inline int clamp(int val, int lo, int hi) {
    if (val < lo) return lo;
    if (val > hi) return hi;
    return val;
}
```

**The modern reality:** compilers inline aggressively at `-O2` regardless of the `inline` keyword. They also routinely ignore `inline` for large functions. The keyword's primary role today is ODR compliance for header-defined functions, not performance control.

For performance-critical inlining, use `[[nodiscard]]` and profile — don't guess.

---

## 4. `constexpr` Functions

A `constexpr` function can be evaluated at compile time when called with compile-time arguments. The same function evaluates at runtime when called with runtime arguments:

```cpp
constexpr uint32_t bitmask(int bit) {
    return 1u << bit;
}

// Compile-time: BIT3 is a compile-time constant
constexpr uint32_t BIT3 = bitmask(3);  // = 0x00000008
static_assert(BIT3 == 0x00000008);

// Runtime: pin is not known at compile time
int pin = get_user_input();
uint32_t mask = bitmask(pin);  // evaluated at runtime
```

### `constexpr` vs `const`

```cpp
const int a = 42;         // const — value may be runtime or compile-time
constexpr int b = 42;     // constexpr — value must be compile-time
constexpr int c = a;      // fine — a is 42, known at compile time

int x = get_value();
const int d = x;          // fine — d is runtime const
// constexpr int e = x;   // error — x not a compile-time constant
```

### `constexpr` in IoT — Baud Rate Register Calculation

A common embedded pattern: compute hardware register values at compile time:

```cpp
// Clock frequency — known at compile time
constexpr uint32_t CPU_CLOCK_HZ = 72'000'000;  // 72 MHz

// UART baud rate divisor — compile-time computation
constexpr uint32_t baud_divisor(uint32_t baud_rate) {
    return CPU_CLOCK_HZ / (16 * baud_rate);
}

// These become compile-time constants in the binary
constexpr uint32_t BRR_9600   = baud_divisor(9600);
constexpr uint32_t BRR_115200 = baud_divisor(115200);

static_assert(BRR_9600   == 468);
static_assert(BRR_115200 == 39);

// Used at runtime — just a constant load
set_uart_register(UART1_BRR, BRR_115200);
```

Zero runtime cost. The `baud_divisor` function doesn't exist in the binary — its result does.

---

## 5. `[[nodiscard]]` and Other Attributes

C++17 attributes let you annotate functions with compiler-enforced properties:

```cpp
// [[nodiscard]] — compiler warns if return value is ignored
[[nodiscard]] bool connect(const char* host, uint16_t port) {
    // returns false on failure
}

connect("192.168.1.1", 1883);         // warning: ignoring return value
bool ok = connect("192.168.1.1", 1883);  // fine

// [[deprecated]] — warn on use
[[deprecated("Use connect_v2() instead")]]
bool connect_old(const char* host);

// [[nodiscard]] with message (C++20)
[[nodiscard("Check for parse errors")]]
ParseResult parse_frame(std::span<const uint8_t> buf);
```

`[[nodiscard]]` is particularly valuable for functions that return error codes or success flags. It makes ignoring errors a compile warning, not a silent bug.

---

## 6. Function Pointers and `std::function`

Sometimes you need to store or pass a function as a value. C has function pointers. C++ has those plus `std::function`, which wraps any callable including lambdas and member functions:

```cpp
// C-style function pointer — zero overhead
typedef void (*SensorCallback)(float value, uint32_t timestamp);
// or equivalently:
using SensorCallback = void(*)(float, uint32_t);

void on_temperature(float v, uint32_t ts) {
    printf("temp: %.2f at %u\n", v, ts);
}

SensorCallback cb = on_temperature;
cb(23.5f, 1000);  // direct call through pointer

// std::function — type-erased, accepts any callable
#include <functional>
std::function<void(float, uint32_t)> handler;

handler = on_temperature;                  // function pointer
handler = [](float v, uint32_t ts) {       // lambda
    printf("lambda: %.2f\n", v);
};

float threshold = 25.0f;
handler = [threshold](float v, uint32_t) { // lambda with capture
    if (v > threshold) printf("ALERT\n");
};

handler(23.5f, 1000);
```

**Cost:** `std::function` has overhead — it heap-allocates for large callables and uses an indirect call (like a virtual function). For hot paths, prefer raw function pointers or templates. For callback storage where you call infrequently, `std::function` is the right tool.

---

## 7. Putting It Together — Type-Safe Serializer

Full exercise. A serializer and deserializer for the binary frame format from Day 3, now using overloaded functions, `constexpr`, and `[[nodiscard]]`:

```cpp
// serializer.cpp
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <cassert>
#include <span>
#include <array>
#include <optional>
#include <type_traits>

// ---- Compile-time CRC-8 table ----

constexpr std::array<uint8_t, 256> make_crc8_table() {
    std::array<uint8_t, 256> t{};
    for (int i = 0; i < 256; ++i) {
        uint8_t c = static_cast<uint8_t>(i);
        for (int j = 0; j < 8; ++j)
            c = (c & 0x80) ? (c << 1) ^ 0x07 : (c << 1);
        t[i] = c;
    }
    return t;
}

constexpr auto CRC8_TABLE = make_crc8_table();

uint8_t crc8(std::span<const uint8_t> data) {
    uint8_t crc = 0;
    for (uint8_t b : data)
        crc = CRC8_TABLE[crc ^ b];
    return crc;
}

// ---- Serialization — write little-endian ----

// Overload set: serialize any fixed-width integer type
// Returns number of bytes written

size_t serialize(uint8_t value,
                 std::span<uint8_t> buf) {
    assert(buf.size() >= 1);
    buf[0] = value;
    return 1;
}

size_t serialize(uint16_t value,
                 std::span<uint8_t> buf) {
    assert(buf.size() >= 2);
    buf[0] = static_cast<uint8_t>(value & 0xFF);
    buf[1] = static_cast<uint8_t>(value >> 8);
    return 2;
}

size_t serialize(uint32_t value,
                 std::span<uint8_t> buf) {
    assert(buf.size() >= 4);
    buf[0] = static_cast<uint8_t>(value & 0xFF);
    buf[1] = static_cast<uint8_t>((value >>  8) & 0xFF);
    buf[2] = static_cast<uint8_t>((value >> 16) & 0xFF);
    buf[3] = static_cast<uint8_t>((value >> 24) & 0xFF);
    return 4;
}

// float — via memcpy to avoid aliasing
size_t serialize(float value,
                 std::span<uint8_t> buf) {
    assert(buf.size() >= 4);
    uint32_t bits;
    std::memcpy(&bits, &value, 4);
    return serialize(bits, buf);
}

// signed variants — cast to unsigned, then serialize
size_t serialize(int16_t value,
                 std::span<uint8_t> buf) {
    return serialize(static_cast<uint16_t>(value), buf);
}

size_t serialize(int32_t value,
                 std::span<uint8_t> buf) {
    return serialize(static_cast<uint32_t>(value), buf);
}

// ---- Deserialization — read little-endian ----

// [[nodiscard]] — callers must check the return value

[[nodiscard]]
std::optional<uint8_t> deserialize_u8(
    std::span<const uint8_t> buf) {
    if (buf.size() < 1) return std::nullopt;
    return buf[0];
}

[[nodiscard]]
std::optional<uint16_t> deserialize_u16(
    std::span<const uint8_t> buf) {
    if (buf.size() < 2) return std::nullopt;
    return static_cast<uint16_t>(buf[0]) |
           static_cast<uint16_t>(buf[1]) << 8;
}

[[nodiscard]]
std::optional<uint32_t> deserialize_u32(
    std::span<const uint8_t> buf) {
    if (buf.size() < 4) return std::nullopt;
    return static_cast<uint32_t>(buf[0])        |
           static_cast<uint32_t>(buf[1]) <<  8  |
           static_cast<uint32_t>(buf[2]) << 16  |
           static_cast<uint32_t>(buf[3]) << 24;
}

[[nodiscard]]
std::optional<float> deserialize_f32(
    std::span<const uint8_t> buf) {
    auto bits = deserialize_u32(buf);
    if (!bits) return std::nullopt;
    float value;
    std::memcpy(&value, &*bits, 4);
    return value;
}

// ---- Sensor frame builder ----

// Frame format (13 bytes):
// [0]     magic     0xAB
// [1]     version   0x01
// [2]     device_id uint8
// [3]     type      uint8
// [4..7]  timestamp uint32 LE
// [8..11] value     float32 LE
// [12]    checksum  CRC-8 of [0..11]

constexpr uint8_t FRAME_MAGIC   = 0xAB;
constexpr uint8_t FRAME_VERSION = 0x01;
constexpr size_t  FRAME_SIZE    = 13;

enum class SensorType : uint8_t {
    Temperature = 0x01,
    Humidity    = 0x02,
    Pressure    = 0x03,
};

struct SensorFrame {
    uint8_t    device_id;
    SensorType type;
    uint32_t   timestamp_ms;
    float      value;
};

// Build: returns exactly FRAME_SIZE bytes
[[nodiscard]]
std::array<uint8_t, FRAME_SIZE>
build_frame(const SensorFrame& f) {
    std::array<uint8_t, FRAME_SIZE> buf{};
    size_t pos = 0;

    pos += serialize(FRAME_MAGIC,   {buf.data() + pos, buf.size() - pos});
    pos += serialize(FRAME_VERSION, {buf.data() + pos, buf.size() - pos});
    pos += serialize(f.device_id,   {buf.data() + pos, buf.size() - pos});
    pos += serialize(static_cast<uint8_t>(f.type),
                                    {buf.data() + pos, buf.size() - pos});
    pos += serialize(f.timestamp_ms,{buf.data() + pos, buf.size() - pos});
    pos += serialize(f.value,       {buf.data() + pos, buf.size() - pos});

    // Checksum over bytes 0..11
    buf[12] = crc8({buf.data(), 12});

    assert(pos == 12);
    return buf;
}

// Parse: returns SensorFrame or nullopt on any error
[[nodiscard]]
std::optional<SensorFrame>
parse_frame(std::span<const uint8_t> buf) {
    if (buf.size() < FRAME_SIZE)       return std::nullopt;
    if (buf[0] != FRAME_MAGIC)         return std::nullopt;
    if (buf[1] != FRAME_VERSION)       return std::nullopt;

    uint8_t expected = crc8(buf.first(12));
    if (expected != buf[12])           return std::nullopt;

    SensorFrame f{};
    f.device_id    = *deserialize_u8 (buf.subspan(2));
    f.type         = static_cast<SensorType>(
                         *deserialize_u8(buf.subspan(3)));
    f.timestamp_ms = *deserialize_u32(buf.subspan(4));
    f.value        = *deserialize_f32(buf.subspan(8));
    return f;
}

// ---- Verify byte order ----

void print_bytes(std::span<const uint8_t> data,
                 const char* label) {
    printf("%-16s: ", label);
    for (uint8_t b : data) printf("%02X ", b);
    printf("\n");
}

int main() {
    printf("=== Type-Safe Serializer ===\n\n");

    // ---- Verify each serialize overload ----
    printf("--- Serialize overloads ---\n");
    {
        uint8_t buf[8]{};

        serialize(uint8_t(0x42),      {buf, 1});
        printf("u8  0x42:       %02X\n", buf[0]);

        serialize(uint16_t(0x1234),   {buf, 2});
        printf("u16 0x1234 LE:  %02X %02X\n",
               buf[0], buf[1]);
        assert(buf[0] == 0x34 && buf[1] == 0x12);

        serialize(uint32_t(0xDEADBEEF), {buf, 4});
        printf("u32 0xDEADBEEF: ");
        for (int i = 0; i < 4; ++i) printf("%02X ", buf[i]);
        printf("\n");
        assert(buf[0] == 0xEF && buf[3] == 0xDE);

        serialize(3.14f, {buf, 4});
        printf("f32 3.14f:      ");
        for (int i = 0; i < 4; ++i) printf("%02X ", buf[i]);
        printf("\n");

        // Round-trip: serialize then deserialize
        auto v = deserialize_f32({buf, 4});
        assert(v.has_value());
        printf("  round-trip: %.6f (expected 3.14)\n", *v);
    }

    // ---- Verify [[nodiscard]] catches ignored errors ----
    // Uncomment to see the compiler warning:
    // deserialize_u32({nullptr, 0});  // warning: ignoring [[nodiscard]]

    // ---- Build and parse a complete frame ----
    printf("\n--- Frame round-trip ---\n");
    {
        SensorFrame original{
            .device_id    = 0x07,
            .type         = SensorType::Temperature,
            .timestamp_ms = 98765,
            .value        = 23.456f,
        };

        auto frame = build_frame(original);
        printf("Built frame (%zu bytes):\n", frame.size());
        print_bytes(frame, "  raw");
        printf("  magic=0x%02X version=0x%02X "
               "device=0x%02X type=0x%02X\n",
               frame[0], frame[1], frame[2], frame[3]);
        printf("  checksum=0x%02X\n", frame[12]);

        auto parsed = parse_frame(frame);
        assert(parsed.has_value());
        printf("\nParsed frame:\n");
        printf("  device_id:    0x%02X\n",
               parsed->device_id);
        printf("  type:         0x%02X\n",
               static_cast<uint8_t>(parsed->type));
        printf("  timestamp_ms: %u\n",
               parsed->timestamp_ms);
        printf("  value:        %.3f\n", parsed->value);

        assert(parsed->device_id    == original.device_id);
        assert(parsed->type         == original.type);
        assert(parsed->timestamp_ms == original.timestamp_ms);
        assert(std::abs(parsed->value - original.value) < 0.001f);
        printf("  Round-trip: PASS\n");
    }

    // ---- Error detection ----
    printf("\n--- Error detection ---\n");
    {
        auto frame = build_frame({0x01, SensorType::Humidity,
                                  1000, 65.2f});

        // Too short
        assert(!parse_frame(
            std::span{frame}.first(5)).has_value());
        printf("  too short:        rejected\n");

        // Bad magic
        auto bad_magic = frame;
        bad_magic[0] = 0x00;
        assert(!parse_frame(bad_magic).has_value());
        printf("  bad magic:        rejected\n");

        // Bad checksum
        auto bad_cksum = frame;
        bad_cksum[12] ^= 0xFF;
        assert(!parse_frame(bad_cksum).has_value());
        printf("  bad checksum:     rejected\n");

        // Corrupt data byte — CRC catches it
        auto corrupt = frame;
        corrupt[5] ^= 0x01;
        assert(!parse_frame(corrupt).has_value());
        printf("  corrupt data:     rejected\n");

        // Valid frame still works
        assert(parse_frame(frame).has_value());
        printf("  valid frame:      accepted\n");
    }

    // ---- constexpr baud divisor ----
    printf("\n--- constexpr baud divisors ---\n");
    {
        constexpr uint32_t CPU_HZ = 72'000'000;
        constexpr auto divisor = [](uint32_t baud) constexpr {
            return CPU_HZ / (16 * baud);
        };

        constexpr uint32_t BRR_9600   = divisor(9600);
        constexpr uint32_t BRR_115200 = divisor(115200);

        printf("  BRR @ 9600:   %u\n", BRR_9600);
        printf("  BRR @ 115200: %u\n", BRR_115200);
        static_assert(BRR_9600   == 468);
        static_assert(BRR_115200 == 39);
        printf("  static_asserts: PASS\n");
    }

    // ---- Overload selection verification ----
    printf("\n--- Overload selection ---\n");
    {
        uint8_t buf[4]{};

        // These must pick the right overload
        serialize(uint8_t(255),   {buf, 1});
        printf("  u8  255:   %u\n", buf[0]);

        serialize(int16_t(-1),    {buf, 2});
        auto v16 = deserialize_u16({buf, 2});
        printf("  i16 -1:    raw=0x%04X "
               "reinterp=%d\n",
               *v16,
               static_cast<int16_t>(*v16));
        assert(static_cast<int16_t>(*v16) == -1);

        serialize(int32_t(-1000), {buf, 4});
        auto v32 = deserialize_u32({buf, 4});
        printf("  i32 -1000: raw=0x%08X "
               "reinterp=%d\n",
               *v32,
               static_cast<int32_t>(*v32));
        assert(static_cast<int32_t>(*v32) == -1000);
    }

    printf("\nAll assertions passed.\n");
    return 0;
}
```

Compile and run:

```bash
g++ -std=c++20 -Wall -Wextra -Wpedantic \
    -fsanitize=address,undefined \
    -o serializer serializer.cpp
./serializer
```

### What to observe

The `serialize` overloads are selected at compile time based on argument type. Pass `uint8_t(0x42)` and you get the one-byte version. Pass `uint32_t(0xDEADBEEF)` and you get the four-byte version. There's no `if` statement deciding which path to take — the compiler does it at compile time.

The `[[nodiscard]]` on `deserialize_u32` means if you call it and throw away the return value, the compiler warns you. Uncomment the warning line and observe: this is a category of bug (ignoring an error return) that was previously silent.

The `constexpr` baud divisor lambda shows that constexpr works inside function scope and that `static_assert` can verify its result at compile time. The `BRR_9600` and `BRR_115200` constants don't exist as computations in the binary — just as values.

Try adding an overload for `bool`:

```cpp
size_t serialize(bool value, std::span<uint8_t> buf) {
    assert(buf.size() >= 1);
    buf[0] = value ? 0x01 : 0x00;
    return 1;
}
```

Then verify that `serialize(true, buf)` calls this overload and not the `uint8_t` overload — even though `true` is implicitly convertible to `uint8_t(1)`. The `bool` overload is an exact match; `uint8_t` would require a promotion.

---

## Key Takeaways for Day 4

- Overload resolution picks the best match based on parameter types — exact match beats promotion beats standard conversion. Ambiguity is a compile error
- Default arguments go in the declaration (header), not the definition; they must be rightmost; they can't reference other parameters
- `inline` in modern C++ is primarily an ODR mechanism for header-defined functions — the compiler makes inlining decisions independently based on optimization level
- `constexpr` functions run at compile time when all arguments are compile-time constants, at runtime otherwise. One function, two modes, zero overhead in the compile-time case
- `[[nodiscard]]` makes ignoring return values a compile warning — use it on any function that returns an error code, a success flag, or an `optional`
- `std::function<Ret(Args...)>` stores any callable — function pointer, lambda, functor — at the cost of an indirect call and potential heap allocation. Use it for stored callbacks; use templates or raw function pointers for hot paths
- The overloaded `serialize` + `[[nodiscard]] deserialize` pattern is the foundation of every binary protocol implementation: one function per type, compiler picks the right one, callers can't ignore errors

Day 5 covers `std::array`, `std::vector`, `std::string_view`, and `std::span` — the types that replace raw arrays and `char*` in every piece of code you write from here on.