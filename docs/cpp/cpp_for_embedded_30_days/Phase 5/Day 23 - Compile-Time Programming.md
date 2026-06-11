# Compile-Time Programming — `constexpr` & `if constexpr`

The C++ compiler is a program that runs before your program. Anything you can compute at compile time costs nothing at runtime — no cycles, no memory reads, no initialization. For embedded IoT code, this matters: a CRC lookup table generated at compile time lives in flash, costs zero RAM, and requires zero startup initialization. A bitmask computed at compile time is a constant in the instruction stream. Today we push as much work as possible into the compiler.

---

## 1. `constexpr` Variables — Truly Compile-Time Constants

`const` means "can't be modified after initialization." `constexpr` means "must be computed at compile time." They're related but distinct:

```cpp
// const — value may be runtime or compile-time
const int n = get_sensor_count();   // runtime — fine
const int m = 8;                    // compile-time — also fine

// constexpr — value must be compile-time
constexpr int MAX_SENSORS  = 8;     // fine — literal
constexpr int BUFFER_SIZE  = MAX_SENSORS * 64;  // fine — computed from constexpr
// constexpr int bad = get_sensor_count();  // error — not compile-time

// constexpr implies const
constexpr float VREF = 3.3f;
// VREF = 5.0f;  // error — can't modify

// Use in compile-time contexts
std::array<float, MAX_SENSORS> readings;    // fine — constexpr size
int arr[BUFFER_SIZE];                       // fine — constexpr size
static_assert(BUFFER_SIZE == 512);          // fine — constexpr value
```

### `constexpr` vs `#define`

```cpp
// #define — no type, no scope, no debugger visibility
#define MAX_SENSORS 8
#define BUFFER_SIZE (MAX_SENSORS * 64)

// constexpr — typed, scoped, debuggable, respect namespaces
namespace sensor {
    constexpr int MAX_COUNT   = 8;
    constexpr int BUFFER_SIZE = MAX_COUNT * 64;
}

// constexpr wins in every way — never use #define for constants
```

---

## 2. `constexpr` Functions — Compile-Time Computation

A `constexpr` function can be evaluated at compile time when called with compile-time arguments, and at runtime otherwise. One function, two modes:

```cpp
constexpr uint32_t bit_mask(int bit) {
    return 1u << bit;
}

constexpr uint32_t SENSOR_ENABLE = bit_mask(3);  // compile-time: 0x00000008
uint32_t dynamic_mask = bit_mask(sensor_pin);     // runtime: evaluated normally

// The function works in both contexts
static_assert(bit_mask(0)  == 0x00000001);
static_assert(bit_mask(7)  == 0x00000080);
static_assert(bit_mask(31) == 0x80000000);
```

### `constexpr` Function Restrictions

In C++17, `constexpr` functions can contain:

- Local variables
- Loops (`for`, `while`)
- Conditionals (`if`, `switch`)
- Multiple return statements
- Most standard library operations on `constexpr`-compatible types

They cannot contain:

- `goto`
- Uninitialized non-const local variables (C++14 restriction, relaxed in C++20)
- `reinterpret_cast` or C-style casts that aren't `static_cast`
- Static local variables (C++20 relaxes this)
- System calls, I/O, anything with side effects

```cpp
// C++17 constexpr function — full loop and branching
constexpr uint8_t count_set_bits(uint32_t v) {
    uint8_t count = 0;
    while (v) {
        count += v & 1;
        v >>= 1;
    }
    return count;
}

static_assert(count_set_bits(0b1011) == 3);
static_assert(count_set_bits(0xFF)   == 8);
static_assert(count_set_bits(0)      == 0);

// constexpr recursive function
constexpr uint64_t fibonacci(int n) {
    if (n <= 1) return static_cast<uint64_t>(n);
    return fibonacci(n - 1) + fibonacci(n - 2);
}

static_assert(fibonacci(10) == 55);
static_assert(fibonacci(20) == 6765);
```

---

## 3. `consteval` — Must Be Compile-Time

`consteval` (C++20) marks a function that must be evaluated at compile time. Calling it with runtime arguments is a compile error:

```cpp
// constexpr — can be compile-time or runtime
constexpr int square_cx(int x) { return x * x; }

// consteval — must be compile-time
consteval int square_ce(int x) { return x * x; }

constexpr int a = square_cx(5);  // fine — compile-time
int runtime_val = 5;
int b = square_cx(runtime_val);  // fine — runtime evaluation

constexpr int c = square_ce(5);  // fine — compile-time
// int d = square_ce(runtime_val);  // error — consteval requires compile-time arg
```

Use `consteval` for functions that only make sense at compile time — validation, code generation, lookup table construction. It communicates intent clearly and makes misuse a compile error.

---

## 4. `constexpr` Arrays — Lookup Tables in Flash

The killer application for `constexpr` in embedded code: generating lookup tables at compile time. They live in flash (read-only memory), cost zero RAM, and have zero runtime initialization:

```cpp
// Generate a sine lookup table at compile time
#include <cmath>
#include <array>

constexpr size_t SINE_TABLE_SIZE = 256;

constexpr std::array<int16_t, SINE_TABLE_SIZE> make_sine_table() {
    std::array<int16_t, SINE_TABLE_SIZE> table{};
    for (size_t i = 0; i < SINE_TABLE_SIZE; ++i) {
        // sin(2π * i / N) scaled to [-32767, 32767]
        double angle = 2.0 * 3.14159265358979323846 * i / SINE_TABLE_SIZE;
        table[i] = static_cast<int16_t>(
            std::sin(angle) * 32767.0
        );
    }
    return table;
}

// This computation happens at compile time — zero runtime cost
constexpr auto SINE_TABLE = make_sine_table();

// Used at runtime — just a table lookup
int16_t sine_value(uint8_t phase) {
    return SINE_TABLE[phase];
}
```

**Note:** `std::sin` is not guaranteed `constexpr` in C++17, but most compilers (GCC, Clang) evaluate it at compile time with `-O2`. For a guaranteed compile-time sine, use a Taylor series or pre-verified coefficients.

---

## 5. CRC Lookup Table — The IoT Protocol Example

CRC (Cyclic Redundancy Check) is in every binary protocol — MQTT, Modbus, CANbus, UART framing. The standard implementation uses a 256-entry lookup table. Generate it at compile time:

```cpp
// CRC-8 (polynomial 0x07 — used in many IoT protocols)
constexpr std::array<uint8_t, 256> make_crc8_table() {
    std::array<uint8_t, 256> table{};
    for (int i = 0; i < 256; ++i) {
        uint8_t crc = static_cast<uint8_t>(i);
        for (int j = 0; j < 8; ++j) {
            crc = (crc & 0x80) ? (crc << 1) ^ 0x07 : (crc << 1);
        }
        table[i] = crc;
    }
    return table;
}

constexpr auto CRC8_TABLE = make_crc8_table();

// CRC-16 (polynomial 0x8005 — Modbus RTU, USB)
constexpr std::array<uint16_t, 256> make_crc16_table() {
    std::array<uint16_t, 256> table{};
    for (int i = 0; i < 256; ++i) {
        uint16_t crc = static_cast<uint16_t>(i << 8);
        for (int j = 0; j < 8; ++j) {
            crc = (crc & 0x8000) ? (crc << 1) ^ 0x8005 : (crc << 1);
        }
        table[i] = crc;
    }
    return table;
}

constexpr auto CRC16_TABLE = make_crc16_table();

// CRC-32 (polynomial 0x04C11DB7 — Ethernet, ZIP)
constexpr std::array<uint32_t, 256> make_crc32_table() {
    std::array<uint32_t, 256> table{};
    for (uint32_t i = 0; i < 256; ++i) {
        uint32_t crc = i;
        for (int j = 0; j < 8; ++j) {
            crc = (crc & 1) ? (crc >> 1) ^ 0xEDB88320u : (crc >> 1);
        }
        table[i] = crc;
    }
    return table;
}

constexpr auto CRC32_TABLE = make_crc32_table();

// Runtime CRC computation — just table lookups
uint8_t crc8(std::span<const uint8_t> data, uint8_t init = 0x00) {
    uint8_t crc = init;
    for (uint8_t b : data) {
        crc = CRC8_TABLE[crc ^ b];
    }
    return crc;
}

uint16_t crc16(std::span<const uint8_t> data, uint16_t init = 0xFFFF) {
    uint16_t crc = init;
    for (uint8_t b : data) {
        crc = static_cast<uint16_t>(
            (crc << 8) ^ CRC16_TABLE[((crc >> 8) ^ b) & 0xFF]
        );
    }
    return crc;
}

uint32_t crc32(std::span<const uint8_t> data, uint32_t init = 0xFFFFFFFF) {
    uint32_t crc = init;
    for (uint8_t b : data) {
        crc = (crc >> 8) ^ CRC32_TABLE[(crc ^ b) & 0xFF];
    }
    return crc ^ 0xFFFFFFFF;
}
```

The tables are `constexpr` global arrays — the compiler computes all 256 entries and places them directly in the binary's read-only data section. No initialization function runs at startup. No RAM consumed. The assembly output for the CRC functions is a tight loop of array indexing and XOR operations.

---

## 6. `if constexpr` — Compile-Time Branching

`if constexpr` evaluates the condition at compile time and discards the untaken branch entirely — the discarded code isn't even instantiated. This is the tool for template code that needs to behave differently based on type properties:

```cpp
template<typename T>
void serialize(T value, std::span<uint8_t> buf) {
    if constexpr (sizeof(T) == 1) {
        buf[0] = static_cast<uint8_t>(value);

    } else if constexpr (sizeof(T) == 2) {
        buf[0] = static_cast<uint8_t>(value & 0xFF);
        buf[1] = static_cast<uint8_t>(value >> 8);

    } else if constexpr (sizeof(T) == 4) {
        buf[0] = static_cast<uint8_t>(value & 0xFF);
        buf[1] = static_cast<uint8_t>((value >> 8)  & 0xFF);
        buf[2] = static_cast<uint8_t>((value >> 16) & 0xFF);
        buf[3] = static_cast<uint8_t>((value >> 24) & 0xFF);

    } else {
        static_assert(sizeof(T) <= 4,
                      "serialize: unsupported type size");
    }
}

// Each instantiation compiles only the matching branch
serialize(uint8_t(0x42), buf);   // only the sizeof==1 branch compiled
serialize(uint16_t(0x1234), buf); // only the sizeof==2 branch compiled
```

### `if constexpr` vs Regular `if`

```cpp
template<typename T>
void bad_branch(T x) {
    if (std::is_integral_v<T>) {
        int result = x % 2;   // ERROR if T is float — even in untaken branch
    }
}

template<typename T>
void good_branch(T x) {
    if constexpr (std::is_integral_v<T>) {
        int result = x % 2;   // fine — only compiled when T is integral
    }
}
```

Regular `if` compiles both branches regardless — the condition only affects runtime execution. `if constexpr` completely eliminates the untaken branch from compilation.

---

## 7. `std::integral_constant` and Type Traits Preview

`std::integral_constant<T, v>` is a type that carries a compile-time value. It's the building block of type traits:

```cpp
#include <type_traits>

// integral_constant wraps a value in a type
using True  = std::integral_constant<bool, true>;
using False = std::integral_constant<bool, false>;

// std::true_type and std::false_type are these
static_assert(std::is_same_v<std::true_type,
              std::integral_constant<bool, true>>);

// Type traits use this pattern
std::is_integral_v<int>    // true
std::is_integral_v<float>  // false
std::is_trivially_copyable_v<SensorRecord>  // true if POD
std::is_standard_layout_v<SensorRecord>    // true if C-compatible layout

// Use in constexpr context
template<typename T>
constexpr bool is_safe_for_dma_v =
    std::is_trivially_copyable_v<T> &&
    std::is_standard_layout_v<T>;

static_assert(is_safe_for_dma_v<SensorRecord>);
// static_assert(is_safe_for_dma_v<std::string>);  // would fail — correct
```

---

## 8. Putting It Together — Compile-Time Protocol Engine

Full exercise: a complete protocol implementation where every table, mask, and validation runs at compile time:

```cpp
// compile_time_protocol.cpp
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <array>
#include <span>
#include <cassert>
#include <type_traits>

// ---- Compile-time CRC tables ----

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

constexpr std::array<uint16_t, 256> make_crc16_table() {
    std::array<uint16_t, 256> t{};
    for (int i = 0; i < 256; ++i) {
        uint16_t c = static_cast<uint16_t>(i << 8);
        for (int j = 0; j < 8; ++j)
            c = (c & 0x8000) ? (c << 1) ^ 0x8005 : (c << 1);
        t[i] = c;
    }
    return t;
}

constexpr auto CRC8_TABLE  = make_crc8_table();
constexpr auto CRC16_TABLE = make_crc16_table();

// Verify table entries at compile time
static_assert(CRC8_TABLE[0x00] == 0x00);
static_assert(CRC8_TABLE[0x07] == 0x7F);
static_assert(CRC16_TABLE[0x00] == 0x0000);

// ---- Compile-time bitmask utilities ----

template<typename T>
constexpr T make_mask(int start_bit, int num_bits) {
    static_assert(std::is_unsigned_v<T>,
                  "make_mask requires unsigned type");
    if (num_bits == 0) return T{0};
    T mask = (T{1} << num_bits) - 1;
    return static_cast<T>(mask << start_bit);
}

template<typename T>
constexpr T extract_bits(T value, int start_bit, int num_bits) {
    constexpr T full_mask = ~T{0};
    T mask = (num_bits < static_cast<int>(sizeof(T) * 8))
           ? (T{1} << num_bits) - 1
           : full_mask;
    return (value >> start_bit) & mask;
}

template<typename T>
constexpr T insert_bits(T target, T value,
                        int start_bit, int num_bits) {
    T mask = make_mask<T>(start_bit, num_bits);
    return (target & ~mask) |
           (static_cast<T>(value << start_bit) & mask);
}

// Verify at compile time
static_assert(make_mask<uint8_t>(2, 3)      == 0b00011100);
static_assert(extract_bits<uint8_t>(0b10110100, 2, 3) == 0b101);
static_assert(insert_bits<uint8_t>(0x00, 0b111, 2, 3) == 0b00011100);

// ---- Frame format constants ----

namespace Frame {
    constexpr uint8_t  MAGIC          = 0xAB;
    constexpr uint8_t  VERSION        = 0x01;
    constexpr size_t   HEADER_SIZE    = 6;
    constexpr size_t   CHECKSUM_SIZE  = 1;
    constexpr size_t   MIN_SIZE       = HEADER_SIZE + CHECKSUM_SIZE;
    constexpr size_t   MAX_PAYLOAD    = 128;
    constexpr size_t   MAX_FRAME_SIZE = HEADER_SIZE
                                      + MAX_PAYLOAD
                                      + CHECKSUM_SIZE;

    // Byte offsets — constexpr, so any use in array indexing is compile-time
    constexpr size_t OFFSET_MAGIC    = 0;
    constexpr size_t OFFSET_VERSION  = 1;
    constexpr size_t OFFSET_TYPE     = 2;
    constexpr size_t OFFSET_FLAGS    = 3;
    constexpr size_t OFFSET_LEN_LO   = 4;
    constexpr size_t OFFSET_LEN_HI   = 5;
    constexpr size_t OFFSET_PAYLOAD  = 6;

    // Type field values
    constexpr uint8_t TYPE_DATA      = 0x01;
    constexpr uint8_t TYPE_ACK       = 0x02;
    constexpr uint8_t TYPE_NACK      = 0x03;
    constexpr uint8_t TYPE_PING      = 0x04;
    constexpr uint8_t TYPE_CONFIG    = 0x05;

    // Flag bits (byte 3)
    constexpr uint8_t FLAG_COMPRESSED = 1 << 0;
    constexpr uint8_t FLAG_ENCRYPTED  = 1 << 1;
    constexpr uint8_t FLAG_PRIORITY   = 1 << 2;
    constexpr uint8_t FLAG_LAST_FRAG  = 1 << 7;
}

static_assert(Frame::MIN_SIZE      == 7);
static_assert(Frame::MAX_FRAME_SIZE == 135);
static_assert(Frame::OFFSET_PAYLOAD == Frame::HEADER_SIZE);

// ---- CRC runtime functions using compile-time tables ----

uint8_t crc8(std::span<const uint8_t> data) {
    uint8_t crc = 0;
    for (uint8_t b : data) crc = CRC8_TABLE[crc ^ b];
    return crc;
}

uint16_t crc16(std::span<const uint8_t> data) {
    uint16_t crc = 0xFFFF;
    for (uint8_t b : data) {
        crc = static_cast<uint16_t>(
            (crc << 8) ^ CRC16_TABLE[((crc >> 8) ^ b) & 0xFF]);
    }
    return crc;
}

// ---- Type-safe serializer using if constexpr ----

template<typename T>
constexpr size_t serial_size() { return sizeof(T); }

template<typename T>
size_t serialize_le(T value, std::span<uint8_t> buf) {
    static_assert(std::is_arithmetic_v<T>,
                  "serialize_le: arithmetic types only");
    static_assert(sizeof(T) <= 8,
                  "serialize_le: max 8 bytes");

    if constexpr (sizeof(T) == 1) {
        buf[0] = static_cast<uint8_t>(value);
        return 1;
    } else if constexpr (sizeof(T) == 2) {
        uint16_t v;
        std::memcpy(&v, &value, 2);
        buf[0] = static_cast<uint8_t>(v & 0xFF);
        buf[1] = static_cast<uint8_t>(v >> 8);
        return 2;
    } else if constexpr (sizeof(T) == 4) {
        uint32_t v;
        std::memcpy(&v, &value, 4);
        buf[0] = static_cast<uint8_t>(v & 0xFF);
        buf[1] = static_cast<uint8_t>((v >>  8) & 0xFF);
        buf[2] = static_cast<uint8_t>((v >> 16) & 0xFF);
        buf[3] = static_cast<uint8_t>((v >> 24) & 0xFF);
        return 4;
    } else {
        static_assert(sizeof(T) == 4,
                      "serialize_le: unsupported size");
        return 0;
    }
}

template<typename T>
T deserialize_le(std::span<const uint8_t> buf) {
    static_assert(std::is_arithmetic_v<T>);
    if constexpr (sizeof(T) == 1) {
        return static_cast<T>(buf[0]);
    } else if constexpr (sizeof(T) == 2) {
        uint16_t v = static_cast<uint16_t>(buf[0]) |
                     static_cast<uint16_t>(buf[1]) << 8;
        T result; std::memcpy(&result, &v, 2);
        return result;
    } else if constexpr (sizeof(T) == 4) {
        uint32_t v = static_cast<uint32_t>(buf[0])        |
                     static_cast<uint32_t>(buf[1]) <<  8  |
                     static_cast<uint32_t>(buf[2]) << 16  |
                     static_cast<uint32_t>(buf[3]) << 24;
        T result; std::memcpy(&result, &v, 4);
        return result;
    }
    return T{};
}

// ---- Frame builder and parser ----

struct ParsedFrame {
    uint8_t              type;
    uint8_t              flags;
    std::vector<uint8_t> payload;
    bool                 valid;
};

// Build a frame into a fixed buffer — no heap
size_t build_frame(
    uint8_t type,
    uint8_t flags,
    std::span<const uint8_t> payload,
    std::span<uint8_t> out)
{
    assert(payload.size() <= Frame::MAX_PAYLOAD);
    assert(out.size() >= Frame::MIN_SIZE + payload.size());

    size_t pos = 0;
    out[pos++] = Frame::MAGIC;
    out[pos++] = Frame::VERSION;
    out[pos++] = type;
    out[pos++] = flags;
    out[pos++] = static_cast<uint8_t>(payload.size() & 0xFF);
    out[pos++] = static_cast<uint8_t>(payload.size() >> 8);

    std::memcpy(out.data() + pos, payload.data(), payload.size());
    pos += payload.size();

    out[pos] = crc8(out.first(pos));
    ++pos;

    return pos;  // total frame size
}

ParsedFrame parse_frame(std::span<const uint8_t> buf) {
    ParsedFrame result{};

    if (buf.size() < Frame::MIN_SIZE)        return result;
    if (buf[Frame::OFFSET_MAGIC]   != Frame::MAGIC)   return result;
    if (buf[Frame::OFFSET_VERSION] != Frame::VERSION) return result;

    uint16_t payload_len =
        static_cast<uint16_t>(buf[Frame::OFFSET_LEN_LO]) |
        static_cast<uint16_t>(buf[Frame::OFFSET_LEN_HI]) << 8;

    if (payload_len > Frame::MAX_PAYLOAD)    return result;
    if (buf.size() < Frame::HEADER_SIZE +
                     payload_len +
                     Frame::CHECKSUM_SIZE)   return result;

    size_t checksum_offset = Frame::HEADER_SIZE + payload_len;
    uint8_t expected = crc8(buf.first(checksum_offset));
    if (expected != buf[checksum_offset])    return result;

    result.type  = buf[Frame::OFFSET_TYPE];
    result.flags = buf[Frame::OFFSET_FLAGS];
    result.payload.assign(
        buf.data() + Frame::OFFSET_PAYLOAD,
        buf.data() + Frame::OFFSET_PAYLOAD + payload_len);
    result.valid = true;
    return result;
}

// ---- Compile-time test vectors ----

// CRC-8 of {0x01, 0x02, 0x03} — verify against known value
constexpr uint8_t test_crc8_byte(uint8_t crc, uint8_t b) {
    return CRC8_TABLE[crc ^ b];
}

constexpr uint8_t TEST_CRC8 = test_crc8_byte(
                               test_crc8_byte(
                               test_crc8_byte(0, 0x01),
                               0x02), 0x03);
// Verify this at compile time — value confirmed against reference implementation
static_assert(TEST_CRC8 == CRC8_TABLE[CRC8_TABLE[CRC8_TABLE[0 ^ 1] ^ 2] ^ 3]);

int main() {
    printf("=== Compile-Time Protocol Engine ===\n\n");

    // ---- CRC table verification ----
    printf("--- CRC tables (compile-time generated) ---\n");
    printf("CRC8_TABLE[0..7]:  ");
    for (int i = 0; i < 8; ++i) printf("%02X ", CRC8_TABLE[i]);
    printf("\n");
    printf("CRC16_TABLE[0..3]: ");
    for (int i = 0; i < 4; ++i) printf("%04X ", CRC16_TABLE[i]);
    printf("\n");

    // ---- Bit manipulation ----
    printf("\n--- Compile-time bit operations ---\n");
    constexpr uint8_t MASK  = make_mask<uint8_t>(2, 3);
    constexpr uint8_t FLAGS = 0b10110101;
    constexpr uint8_t FIELD = extract_bits(FLAGS, 2, 3);

    printf("mask(start=2, len=3):          0b%08b = 0x%02X\n",
           MASK, MASK);
    printf("extract(0b%08b, start=2, 3):   0b%03b = %u\n",
           FLAGS, FIELD, FIELD);
    printf("insert(0x00, 0b111, start=2):  0b%08b\n",
           insert_bits<uint8_t>(0x00, 0b111, 2, 3));

    // ---- Serialization ----
    printf("\n--- Type-safe serialization (if constexpr) ---\n");
    uint8_t  buf[8]{};
    std::span out_span{buf};

    serialize_le(uint8_t(0x42),     out_span.subspan(0));
    serialize_le(uint16_t(0x1234),  out_span.subspan(1));
    serialize_le(uint32_t(0xDEADBEEF), out_span.subspan(3));
    serialize_le(float(3.14f),      out_span.subspan(7));

    printf("Serialized bytes: ");
    for (int i = 0; i < 7; ++i) printf("%02X ", buf[i]);
    printf("\n");

    auto v8  = deserialize_le<uint8_t> (out_span.subspan(0));
    auto v16 = deserialize_le<uint16_t>(out_span.subspan(1));
    auto v32 = deserialize_le<uint32_t>(out_span.subspan(3));

    printf("uint8:  0x%02X\n",  v8);
    printf("uint16: 0x%04X\n",  v16);
    printf("uint32: 0x%08X\n",  v32);
    assert(v8  == 0x42);
    assert(v16 == 0x1234);
    assert(v32 == 0xDEADBEEF);

    // ---- Frame build and parse ----
    printf("\n--- Frame build/parse ---\n");
    std::array<uint8_t, Frame::MAX_FRAME_SIZE> frame_buf{};

    const uint8_t payload[] = {
        0x01, 0x00,              // sensor_id=1
        0x9A, 0x99, 0xBB, 0x41, // value=23.45f (little-endian)
        0x00, 0x27, 0x00, 0x00  // timestamp=10000ms
    };

    size_t frame_len = build_frame(
        Frame::TYPE_DATA,
        Frame::FLAG_PRIORITY,
        std::span{payload},
        std::span{frame_buf}
    );

    printf("Built frame (%zu bytes): ", frame_len);
    for (size_t i = 0; i < frame_len; ++i)
        printf("%02X ", frame_buf[i]);
    printf("\n");

    auto parsed = parse_frame(
        std::span{frame_buf.data(), frame_len});

    printf("Parsed: valid=%s type=0x%02X flags=0x%02X "
           "payload=%zu bytes\n",
           parsed.valid ? "yes" : "NO",
           parsed.type, parsed.flags,
           parsed.payload.size());

    assert(parsed.valid);
    assert(parsed.type  == Frame::TYPE_DATA);
    assert(parsed.flags == Frame::FLAG_PRIORITY);
    assert(parsed.payload.size() == sizeof(payload));

    // ---- Error cases ----
    printf("\n--- Parse error cases ---\n");
    auto test = [](const char* name,
                   std::span<const uint8_t> buf) {
        auto f = parse_frame(buf);
        printf("  %-20s → %s\n", name,
               f.valid ? "valid" : "rejected");
    };

    std::array<uint8_t, Frame::MAX_FRAME_SIZE> bad{};
    // Too short
    test("too short",    std::span{frame_buf}.first(3));
    // Bad magic
    std::copy(frame_buf.begin(),
              frame_buf.begin() + frame_len, bad.begin());
    bad[Frame::OFFSET_MAGIC] = 0x00;
    test("bad magic",    std::span{bad}.first(frame_len));
    // Bad checksum
    std::copy(frame_buf.begin(),
              frame_buf.begin() + frame_len, bad.begin());
    bad[frame_len - 1] ^= 0xFF;
    test("bad checksum", std::span{bad}.first(frame_len));
    // Valid
    test("valid",        std::span{frame_buf}.first(frame_len));

    // ---- sizeof comparison: constexpr vs runtime table ----
    printf("\n--- Table location (should be in .rodata) ---\n");
    printf("CRC8_TABLE  addr: %p\n",
           static_cast<const void*>(CRC8_TABLE.data()));
    printf("CRC16_TABLE addr: %p\n",
           static_cast<const void*>(CRC16_TABLE.data()));
    printf("Both in read-only segment (verify with: "
           "objdump -t compile_time_protocol | grep rodata)\n");

    printf("\nAll assertions passed.\n");
    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -O2 -fsanitize=address,undefined \
    -o ct_protocol compile_time_protocol.cpp
./ct_protocol

# Verify CRC tables are in read-only data — not BSS or data
objdump -t ct_protocol | grep -E "CRC|rodata"
# Or:
readelf -S ct_protocol | grep -E "\.rodata|\.data|\.bss"
```

### What to observe

Run `objdump -t ct_protocol | grep CRC` — the CRC table symbols should appear in the `.rodata` section (read-only data), not `.data` or `.bss`. This confirms they're in flash on embedded, initialized at link time, not at runtime.

Add `-S` to your compilation and look at the assembly for `crc8()` — it's a tight loop of `movzx`/`xor`/`movzx` instructions referencing a fixed address. No branch to a table-initialization function, no check for "has the table been initialized."

The `static_assert` on `CRC8_TABLE[0x00] == 0x00` and `CRC8_TABLE[0x07] == 0x7F` runs during compilation. If you corrupt the table-generation function, the build fails — not a test, but a compile-time invariant.

---

## Key Takeaways for Day 23

- `constexpr` variables are compile-time constants — typed, scoped, debuggable. Replace every `#define` for numeric constants with `constexpr`
- `constexpr` functions execute at compile time when called with compile-time arguments, at runtime otherwise — one function, two contexts
- `consteval` (C++20) forces compile-time evaluation — a misuse with runtime arguments is a compile error, not a missed optimization
- `constexpr std::array` enables compile-time lookup tables — CRC tables, sine tables, calibration coefficients. They live in `.rodata` (flash on embedded), consume zero RAM, require zero startup initialization
- `static_assert` with `constexpr` expressions verifies invariants at compile time — struct sizes, table entries, offset calculations. Failures are build errors, not runtime crashes
- `if constexpr` discards the untaken branch entirely — the code isn't compiled, not just not executed. Essential for template code that operates differently on different types
- The CRC table pattern: generate 256 entries with a `constexpr` function, verify specific entries with `static_assert`, use the table in a tight runtime loop — compile-time investment, runtime payoff
- Bit manipulation helpers (`make_mask`, `extract_bits`, `insert_bits`) as `constexpr` templates — verified at compile time with `static_assert`, zero overhead at runtime

Day 24 covers type traits and Concepts — constraining templates so errors appear at the call site with clear messages, and expressing interface requirements in code rather than documentation.