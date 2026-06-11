

Raw C arrays are the source of more C++ bugs than almost anything else — buffer overruns, size mismatches passed as separate parameters, decay to pointers that lose all size information. C++ gives you three replacements depending on the use case, plus `std::span` which is arguably the most practically important addition in C++20 for systems work. Today we cover all of them, and you'll finish with a zero-allocation frame parser — the kind of thing you write constantly in IoT code.

---

## 1. The Problem With Raw C Arrays

You know this from C, but it's worth stating explicitly because C++ solves each problem:

```cpp
void process(uint8_t* buf, size_t len);  // size is a separate parameter — they can disagree

uint8_t data[64];
process(data, 65);   // silent bug — passed wrong size, no compile error

// Array decays to pointer immediately
uint8_t arr[64];
uint8_t* ptr = arr;  // fine
sizeof(arr);         // 64 — correct
sizeof(ptr);         // 8 — size of a pointer, not the array

// No bounds checking
arr[100] = 0xFF;     // undefined behavior — silent corruption
```

The three C++ replacements fix these in different ways: `std::array` for compile-time fixed-size, `std::vector` for runtime dynamic-size, and `std::span` for non-owning views over any of them.

---

## 2. `std::array` — Fixed-Size Stack Array

`std::array<T, N>` is a zero-overhead wrapper around a C array. Same stack allocation, same performance, but it knows its own size and doesn't decay to a pointer.

```cpp
#include <array>

std::array<uint8_t, 64> buf;       // 64 bytes on the stack
std::array<float, 8>    readings;  // 8 floats on the stack

// Size is always available
size_t n = buf.size();   // 64 — always correct, no separate parameter

// Bounds-checked access (debug mode)
buf.at(100);   // throws std::out_of_range — safe
buf[100];      // undefined behavior — fast, no check

// Aggregate initialization
std::array<uint8_t, 4> header = {0x10, 0x00, 0x00, 0x04};

// Range-based for
for (auto& b : buf) {
    b = 0x00;
}

// Works with all STL algorithms
std::fill(buf.begin(), buf.end(), 0xFF);
auto it = std::find(buf.begin(), buf.end(), 0x10);
```

**What `std::array` does NOT do:** it doesn't grow, it doesn't shrink, its size is a compile-time constant. That's the point — if you know the size at compile time, use `std::array`. It compiles to exactly the same code as a raw array, with none of the footguns.

Passing to functions — the size is part of the type:

```cpp
// Takes exactly a 64-byte array — size mismatch is a compile error
void process(std::array<uint8_t, 64>& buf);

// Takes any size — use a template
template<size_t N>
void process(std::array<uint8_t, N>& buf) {
    size_t len = buf.size();  // N — always available, always correct
}
```

---

## 3. `std::vector` — Dynamic Heap Array

`std::vector<T>` is a heap-allocated, growable array. It's the default container in C++ for most use cases. Internally it manages a heap allocation and a size/capacity pair.

```cpp
#include <vector>

std::vector<uint8_t> buf;          // empty, no allocation yet
buf.reserve(256);                  // allocate space for 256 without setting size
buf.push_back(0x10);               // append — O(1) amortized
buf.push_back(0x00);

std::vector<uint8_t> frame(128);   // 128 bytes, all zero-initialized
std::vector<uint8_t> prefilled(8, 0xFF);  // 8 bytes, all 0xFF

// Size vs capacity
buf.size();       // number of elements currently in the vector
buf.capacity();   // allocated space (>= size)

// Shrink to fit
buf.shrink_to_fit();  // release excess capacity
```

**The growth strategy:** when `push_back` exceeds capacity, `vector` allocates a new (typically 2x larger) block, moves all elements, and frees the old block. This is why `reserve()` matters in hot paths:

```cpp
// Bad — may reallocate multiple times as it grows
std::vector<SensorReading> readings;
for (int i = 0; i < 10000; ++i) {
    readings.push_back(collect_reading());
}

// Good — single allocation upfront
std::vector<SensorReading> readings;
readings.reserve(10000);
for (int i = 0; i < 10000; ++i) {
    readings.push_back(collect_reading());
}
```

**Embedded caveat:** `std::vector` requires a heap allocator. On bare-metal targets without dynamic allocation, it's often unavailable or dangerous. Use `std::array` or a static buffer instead. On Linux-based embedded (Raspberry Pi, BeagleBone, any device running an OS), `vector` is fine.

---

## 4. `std::string` vs `std::string_view`

This is the string version of the ownership question.

### `std::string` — owns its data

```cpp
#include <string>

std::string topic = "sensors/temperature/device_01";
std::string copy = topic;   // copies the entire string — heap allocation

topic += "/raw";            // mutates — may reallocate
topic.size();               // length in bytes
topic.c_str();              // null-terminated const char* — for C APIs
topic.empty();              // true if length == 0

// String operations
topic.find("temperature");      // returns index or std::string::npos
topic.substr(8, 11);            // "temperature" — new allocation
topic.starts_with("sensors/");  // C++20
```

`std::string` allocates on the heap for strings longer than ~15 characters (most implementations use Small String Optimization — SSO — for short strings, storing them inline in the object). Copying a string is a heap allocation.

### `std::string_view` — non-owning view

`std::string_view` is a (pointer, length) pair — it points into existing string data without owning it. No allocation, no copy. This is the right type when you're reading a string but not storing it.

```cpp
#include <string_view>

std::string_view sv = "sensors/temperature/device_01";
// No allocation — sv points into the string literal

void log_topic(std::string_view topic) {
    // Works with: std::string, string literals, char arrays, substrings
    // No copy, no allocation
    printf("%.*s\n", static_cast<int>(topic.size()), topic.data());
}

std::string full = "sensors/temperature/device_01";
log_topic(full);              // no copy
log_topic("sensors/device");  // no copy
log_topic({full.data(), 7});  // "sensors" — substring view, no copy

// Parsing — split on '/' without allocating substrings
std::string_view path = "sensors/temperature/device_01";
size_t pos = path.find('/');
std::string_view prefix = path.substr(0, pos);  // "sensors" — no allocation
```

**The lifetime trap:** `std::string_view` is a view — it doesn't own the data. If the string it views is destroyed, the view dangles:

```cpp
std::string_view dangerous() {
    std::string local = "hello";
    return local;   // local is destroyed, view dangles — UB
}

// Safe: viewing a string literal (static lifetime)
std::string_view safe = "hello";  // fine — string literal lives forever
```

**Rule:** use `std::string_view` for function parameters that read strings. Use `std::string` when you need to store or modify a string.

---

## 5. `std::span` — Non-Owning View Over Any Contiguous Buffer

`std::span` (C++20, also available via `<span>` in C++20 or `gsl::span` earlier) is the most important type for systems and IoT code introduced in recent C++. It's a (pointer, length) pair over any contiguous sequence — `std::array`, `std::vector`, raw arrays, DMA buffers — without taking ownership and without copying.

```cpp
#include <span>

// std::span<T> — mutable view
// std::span<const T> — read-only view

void process_frame(std::span<const uint8_t> frame) {
    size_t len = frame.size();           // always available
    const uint8_t* data = frame.data();  // raw pointer if needed
    uint8_t first = frame[0];            // bounds-checked in debug

    // Slice without copying
    auto header  = frame.first(4);       // first 4 bytes
    auto payload = frame.subspan(4);     // everything after byte 4
    auto middle  = frame.subspan(2, 6);  // 6 bytes starting at offset 2
}

// All of these call the same function — no overloads needed
uint8_t raw[128];
process_frame(raw);                        // raw array

std::array<uint8_t, 64> arr{};
process_frame(arr);                        // std::array

std::vector<uint8_t> vec(64);
process_frame(vec);                        // std::vector

uint8_t* dma_buf = get_dma_buffer();
process_frame({dma_buf, 256});             // pointer + length
```

Before `std::span`, you'd write three overloads or force callers to use a specific container. Now you write one function that works with everything.

### Fixed vs Dynamic Extent

`std::span` has two forms:

```cpp
std::span<uint8_t>       dynamic_span;       // size known at runtime
std::span<uint8_t, 4>    fixed_span;         // size=4 known at compile time

// Fixed extent span — zero overhead, size encoded in type
std::array<uint8_t, 64> buf{};
std::span<uint8_t, 64> s{buf};   // compiler knows it's 64 bytes
```

For most IoT code, dynamic extent (`std::span<const uint8_t>`) is what you want — you're dealing with variable-length frames off a wire.

### `std::span` for Mutable Buffers

```cpp
// Read-only view — can't modify through the span
void parse(std::span<const uint8_t> input);

// Mutable view — can modify the underlying buffer
void encode(std::span<uint8_t> output) {
    output[0] = 0x10;
    std::fill(output.begin(), output.end(), 0x00);
}
```

---

## 6. Range-Based For Loops

Works on anything with `begin()`/`end()` — all STL containers, `std::span`, raw arrays:

```cpp
std::array<float, 8> readings{1.0f, 2.0f, 3.0f};

// By value — copies each element
for (float r : readings) { ... }

// By const reference — no copy, read-only
for (const float& r : readings) { ... }

// By reference — no copy, can modify
for (float& r : readings) { r *= 1.1f; }

// auto& — let the compiler figure out the type
for (auto& r : readings) { r *= 1.1f; }
```

For cheap types (`int`, `float`, `uint8_t`), by-value is fine. For anything larger, use `const auto&` or `auto&`.

---

## 7. Putting It Together — Zero-Copy Frame Parser

Here's today's full exercise. A realistic binary frame format arrives over serial or a socket. Parse it using `std::span` with zero dynamic allocation, zero copies.

```cpp
#include <array>
#include <span>
#include <cstdint>
#include <cstddef>
#include <cstring>
#include <cstdio>
#include <cassert>
#include <string_view>

// Frame format (12 bytes minimum):
// [0]     magic       uint8_t   0xAB
// [1]     version     uint8_t
// [2..3]  device_id   uint16_t  little-endian
// [4..7]  timestamp   uint32_t  little-endian (unix seconds)
// [8..9]  payload_len uint16_t  little-endian
// [10..N] payload     uint8_t[] payload_len bytes
// [N+1]   checksum    uint8_t   XOR of bytes 0..N

constexpr uint8_t FRAME_MAGIC   = 0xAB;
constexpr size_t  FRAME_HEADER_SIZE = 10;

struct FrameHeader {
    uint8_t  magic;
    uint8_t  version;
    uint16_t device_id;
    uint32_t timestamp;
    uint16_t payload_len;
};

static_assert(sizeof(FrameHeader) == 10, "FrameHeader must be exactly 10 bytes");

struct ParsedFrame {
    FrameHeader             header;
    std::span<const uint8_t> payload;   // view into the original buffer — no copy
    uint8_t                 checksum;
    bool                    valid;
};

// Compute XOR checksum over a span
uint8_t compute_checksum(std::span<const uint8_t> data) {
    uint8_t xor_val = 0;
    for (uint8_t b : data) xor_val ^= b;
    return xor_val;
}

// Read a little-endian uint16_t from a 2-byte span
uint16_t read_le16(std::span<const uint8_t, 2> s) {
    return static_cast<uint16_t>(s[0]) |
           static_cast<uint16_t>(s[1]) << 8;
}

// Read a little-endian uint32_t from a 4-byte span
uint32_t read_le32(std::span<const uint8_t, 4> s) {
    return static_cast<uint32_t>(s[0])        |
           static_cast<uint32_t>(s[1]) << 8   |
           static_cast<uint32_t>(s[2]) << 16  |
           static_cast<uint32_t>(s[3]) << 24;
}

// Parse a frame from a raw buffer
// Returns a ParsedFrame with valid=false if anything is wrong
// The payload span points INTO buf — caller must keep buf alive
ParsedFrame parse_frame(std::span<const uint8_t> buf) {
    ParsedFrame result{};

    // Minimum size check
    if (buf.size() < FRAME_HEADER_SIZE + 1) {  // +1 for checksum
        return result;
    }

    // Magic check
    if (buf[0] != FRAME_MAGIC) {
        return result;
    }

    // Parse header fields using fixed-extent subspans
    result.header.magic       = buf[0];
    result.header.version     = buf[1];
    result.header.device_id   = read_le16(buf.subspan<2, 2>());
    result.header.timestamp   = read_le32(buf.subspan<4, 4>());
    result.header.payload_len = read_le16(buf.subspan<8, 2>());

    // Total frame size check
    size_t expected_size = FRAME_HEADER_SIZE
                         + result.header.payload_len
                         + 1;  // checksum byte
    if (buf.size() < expected_size) {
        return result;
    }

    // Payload — zero-copy view into the original buffer
    result.payload = buf.subspan(FRAME_HEADER_SIZE, result.header.payload_len);

    // Checksum — XOR over everything except the checksum byte itself
    size_t checksum_offset = FRAME_HEADER_SIZE + result.header.payload_len;
    result.checksum = buf[checksum_offset];

    uint8_t computed = compute_checksum(buf.first(checksum_offset));
    result.valid = (computed == result.checksum);

    return result;
}

// Helper to print a span as hex
void print_hex(std::span<const uint8_t> data, std::string_view label) {
    printf("%.*s: ", static_cast<int>(label.size()), label.data());
    for (uint8_t b : data) printf("%02X ", b);
    printf("\n");
}

int main() {
    // Build a valid test frame manually
    // Payload: "TEMP" + float32 value
    constexpr uint8_t payload[] = {'T', 'E', 'M', 'P',
                                    0x9A, 0x99, 0xBB, 0x41};  // 23.45f in little-endian

    // Build the frame into a stack buffer
    std::array<uint8_t, 19> frame_buf{};
    frame_buf[0] = FRAME_MAGIC;   // magic
    frame_buf[1] = 0x01;          // version 1
    frame_buf[2] = 0x2A; frame_buf[3] = 0x00;  // device_id = 42, little-endian
    frame_buf[4] = 0x00; frame_buf[5] = 0x52;  // timestamp low bytes
    frame_buf[6] = 0x08; frame_buf[7] = 0x00;  // timestamp high bytes
    frame_buf[8] = 0x08; frame_buf[9] = 0x00;  // payload_len = 8

    std::memcpy(&frame_buf[10], payload, 8);   // copy payload in

    // Compute and append checksum
    uint8_t cksum = compute_checksum(
        std::span<const uint8_t>{frame_buf.data(), 18}
    );
    frame_buf[18] = cksum;

    printf("Raw frame:  ");
    print_hex(frame_buf, "");

    // Parse it
    ParsedFrame f = parse_frame(frame_buf);

    assert(f.valid);
    printf("\nParsed frame:\n");
    printf("  Magic:       0x%02X\n", f.header.magic);
    printf("  Version:     %d\n",     f.header.version);
    printf("  Device ID:   %d\n",     f.header.device_id);
    printf("  Timestamp:   %u\n",     f.header.timestamp);
    printf("  Payload len: %d\n",     f.header.payload_len);
    print_hex(f.payload, "  Payload    ");
    printf("  Checksum:    0x%02X (valid)\n", f.checksum);

    // The payload span points into frame_buf — zero copy confirmed
    assert(f.payload.data() == frame_buf.data() + FRAME_HEADER_SIZE);
    printf("\nZero-copy confirmed: payload points into original buffer\n");

    // Test invalid magic
    std::array<uint8_t, 19> bad_frame = frame_buf;
    bad_frame[0] = 0x00;
    ParsedFrame bad = parse_frame(bad_frame);
    assert(!bad.valid);
    printf("Bad magic correctly rejected\n");

    // Test truncated frame
    ParsedFrame truncated = parse_frame(std::span{frame_buf}.first(5));
    assert(!truncated.valid);
    printf("Truncated frame correctly rejected\n");

    return 0;
}
```

Compile and run:

```bash
g++ -std=c++20 -Wall -Wextra -Wpedantic -o frame_parser frame_parser.cpp
./frame_parser
```

### What to observe

- `parse_frame` takes `std::span<const uint8_t>` — works with the `std::array` directly, no conversion
- `result.payload` is a `std::span` pointing into `buf` — the parsed frame references the original buffer, zero bytes copied
- `buf.subspan<2, 2>()` — compile-time fixed-extent subspan, the compiler knows the size statically
- `read_le16` and `read_le32` take fixed-extent spans — the size mismatch is a compile error, not a runtime bug
- `assert(f.payload.data() == frame_buf.data() + FRAME_HEADER_SIZE)` — proves the zero-copy claim

Now add one more thing: make `parse_frame` work on a DMA receive buffer:

```cpp
// Simulated DMA buffer from a hardware peripheral
alignas(32) static uint8_t dma_rx_buffer[512];

// Fill dma_rx_buffer with your frame bytes somehow...
// Then parse with exactly the same function:
ParsedFrame f = parse_frame({dma_rx_buffer, 19});
```

No changes to `parse_frame`. No overloads. That's the point of `std::span`.

---

## Key Takeaways for Day 5

- Raw C arrays decay to pointers and lose their size — never pass them to functions without a length, or better: stop using them in new code
- `std::array<T, N>` is a zero-overhead, size-aware, stack-allocated fixed buffer — prefer it over raw arrays always
- `std::vector<T>` is a heap-allocated growable array — use `reserve()` when the final size is known to avoid reallocations
- `std::string` owns its data and allocates; `std::string_view` is a non-owning (pointer, length) view — use `string_view` for read-only string parameters
- `std::span<T>` is a non-owning (pointer, length) view over any contiguous buffer — the single function parameter type that accepts arrays, vectors, raw buffers, and DMA regions
- Fixed-extent spans (`std::span<T, N>`) encode the size in the type — size mismatches become compile errors
- Zero-copy parsing is the right architecture for IoT frame processing — parse fields as views into the original receive buffer, copy only when you need to store something

Phase 1 is done. Day 6 starts Phase 2 — classes, constructors, and the destructor contract that makes RAII possible.