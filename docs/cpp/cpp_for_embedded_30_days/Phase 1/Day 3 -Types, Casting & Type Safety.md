

In C, casting is a blunt instrument — `(int)x` tells the compiler to stop asking questions. C++ replaces that with four named casts, each with a specific purpose and specific guarantees. This isn't bureaucracy — it's documentation. When you see `reinterpret_cast` in a code review, you immediately know "raw memory interpretation happening here." When you see `static_cast`, you know "safe, checked conversion." The cast type tells you the intent.

Today also covers the type machinery that matters most for IoT and protocol work: fixed-width types, struct layout, alignment, and how to safely map C++ objects onto raw bytes.

---

## 1. Why C-Style Casts Are Dangerous

The C-style cast `(T)x` is actually five different operations depending on context, and the compiler picks one silently:

```cpp
float f = 3.14f;
int i = (int)f;           // static_cast — fine, narrowing conversion

const int ci = 42;
int* p = (int*)&ci;       // const_cast — strips const, dangerous

SensorReading* sr = (SensorReading*)buffer;  // reinterpret_cast — raw reinterpretation

Base* b = new Derived();
Derived* d = (Derived*)b; // dynamic_cast (without the check) — unchecked downcast
```

All four look identical at the call site. C++ casts make the intent explicit and let both the compiler and the next programmer know exactly what's happening.

---

## 2. `static_cast` — Safe, Compile-Time Conversions

`static_cast` handles conversions the compiler knows are valid at compile time. It's the right cast for numeric conversions, enum-to-int, and navigating class hierarchies when you know the types.

```cpp
float f = 23.7f;
int i = static_cast<int>(f);        // truncates to 23 — explicit, intentional

int raw = 2;
enum class BaudRate { B9600 = 9600, B115200 = 115200 };
// Can't static_cast int to enum class directly without care
// but can go the other way:
int val = static_cast<int>(BaudRate::B9600);  // 9600

// Upcasting in a hierarchy — always safe
Derived* d = new Derived();
Base* b = static_cast<Base*>(d);    // fine — Derived IS-A Base
```

**The key rule:** `static_cast` never changes the bits. It reinterprets what those bits mean according to standard conversion rules. A `float` to `int` cast changes the bit pattern (3.14f → 3), but does so through a well-defined conversion, not raw memory aliasing.

Use `static_cast` any time you'd have written a C-style cast for a numeric conversion. It's the most common of the four.

---

## 3. `reinterpret_cast` — Raw Memory Reinterpretation

This is the one you'll use for protocol parsing and hardware register access. It tells the compiler: "take these bits and treat them as a completely different type." No conversion happens — you get the same bits, read through a different lens.

```cpp
uint8_t buffer[4] = {0x01, 0x02, 0x03, 0x04};
uint32_t* as_uint32 = reinterpret_cast<uint32_t*>(buffer);
// *as_uint32 is 0x04030201 on little-endian (or 0x01020304 on big-endian)
```

This is exactly how you'd read a hardware register or parse a binary protocol frame in C — `reinterpret_cast` is the C++ way to say "I know what I'm doing here."

### The Strict Aliasing Rule — Where `reinterpret_cast` Gets Dangerous

C++ has a rule the optimizer relies on: **pointers to different types cannot alias the same memory.** The compiler assumes `float* f` and `int* i` never point to the same address, and optimizes accordingly. Violating this is undefined behavior, even if the result looks correct at -O0.

The safe exceptions (types you can always alias through):

- `char*`, `unsigned char*`, `std::byte*` — always safe to inspect raw memory
- The type itself or a compatible type

```cpp
float f = 3.14f;

// WRONG — violates strict aliasing, UB even though it "works"
int bits = *reinterpret_cast<int*>(&f);

// CORRECT — use memcpy, which the optimizer knows about
int bits;
std::memcpy(&bits, &f, sizeof(float));
// Modern compilers optimize this to a single register instruction
```

For protocol buffers and struct overlays, use `memcpy` or `std::byte*`. For hardware register maps where you own the hardware definition, `reinterpret_cast` to a volatile pointer is the standard pattern:

```cpp
// Hardware register map — this is the legitimate use
struct UartRegisters {
    volatile uint32_t DR;    // data register
    volatile uint32_t SR;    // status register
    volatile uint32_t BRR;   // baud rate register
};

constexpr uint32_t UART1_BASE = 0x40011000;
auto* uart = reinterpret_cast<UartRegisters*>(UART1_BASE);
uart->DR = 'A';  // write to hardware
```

`volatile` tells the compiler: don't optimize away reads/writes to this address — a hardware peripheral may change it independently.

---

## 4. `const_cast` — Removing const

`const_cast` removes or adds `const`. It's the narrowest-use cast — you'll rarely write it in new code. Its legitimate use is interoperating with old C APIs that take non-const pointers even though they don't modify the data:

```cpp
// Old C library — bad signature, can't modify
void legacy_print(char* str);  // should be const char*, but isn't

const char* message = "hello";
legacy_print(const_cast<char*>(message));  // acceptable — legacy_print won't modify it
```

**Never use `const_cast` to actually modify a const object.** That's undefined behavior:

```cpp
const int x = 42;
int* p = const_cast<int*>(&x);
*p = 99;  // undefined behavior — x is in read-only memory on many platforms
```

---

## 5. `dynamic_cast` — Runtime Type Checking

`dynamic_cast` is the only cast that operates at runtime. It's used to safely downcast in a class hierarchy — checking at runtime whether the conversion is valid. It requires virtual functions (specifically a vtable) to work.

```cpp
Base* b = new Derived();
Derived* d = dynamic_cast<Derived*>(b);  // succeeds — b really is a Derived
if (d != nullptr) {
    d->derived_only_method();
}

Base* b2 = new Base();
Derived* d2 = dynamic_cast<Derived*>(b2);  // returns nullptr — b2 is not a Derived
```

For references, `dynamic_cast` throws `std::bad_cast` on failure instead of returning null.

**Cost:** `dynamic_cast` involves a runtime type info (RTTI) lookup — it's not free. On embedded targets, RTTI is sometimes disabled (`-fno-rtti`) to save flash space, which disables `dynamic_cast` entirely. In that case, use virtual functions or explicit type tags instead.

In well-designed C++ code, `dynamic_cast` appears rarely. If you're using it often, it's usually a sign the class hierarchy needs redesigning.

---

## 6. Fixed-Width Integer Types

From `<cstdint>` — use these for anything protocol-related or hardware-facing:

```cpp
#include <cstdint>

uint8_t  byte_val;    // exactly 8 bits, unsigned
int8_t   signed_byte; // exactly 8 bits, signed
uint16_t word;        // exactly 16 bits, unsigned
int16_t  sword;       // exactly 16 bits, signed
uint32_t dword;       // exactly 32 bits, unsigned
int32_t  sdword;      // exactly 32 bits, signed
uint64_t qword;       // exactly 64 bits, unsigned

// Pointer-sized integer — use for array indices and sizes
size_t   index;       // unsigned, pointer-sized
ptrdiff_t diff;       // signed, pointer-sized (pointer subtraction result)
uintptr_t addr;       // unsigned integer that can hold a pointer value
```

**Never use `int` for protocol fields.** `int` is "at least 16 bits" — it's 32 bits on every modern platform, but the standard doesn't guarantee it. `uint16_t` is exactly 16 bits, everywhere, always.

```cpp
// Bad — what is the size of this on-wire?
struct BadHeader {
    int magic;          // 4 bytes? 2 bytes? depends on platform
    unsigned short len; // "probably" 2 bytes
};

// Good — unambiguous layout
struct GoodHeader {
    uint32_t magic;     // exactly 4 bytes
    uint16_t length;    // exactly 2 bytes
    uint8_t  version;   // exactly 1 byte
    uint8_t  flags;     // exactly 1 byte
};  // total: 8 bytes — on any platform
```

---

## 7. `sizeof`, `alignof`, `alignas` & Struct Padding

The compiler is allowed to insert padding bytes between struct members to satisfy alignment requirements. This catches people who assume their struct maps directly onto a wire format.

```cpp
struct Padded {
    uint8_t  a;   // 1 byte
    // 3 bytes padding inserted here
    uint32_t b;   // 4 bytes — must start at 4-byte boundary
    uint16_t c;   // 2 bytes
    // 2 bytes padding here
};
// sizeof(Padded) == 12, not 7

struct NoPadding {
    uint32_t b;   // 4 bytes
    uint16_t c;   // 2 bytes
    uint8_t  a;   // 1 byte
    uint8_t  pad; // explicit padding for documentation
};
// sizeof(NoPadding) == 8
```

The rule: **order struct members from largest to smallest alignment requirement** to minimize padding.

`static_assert` lets you verify layout assumptions at compile time — zero runtime cost:

```cpp
static_assert(sizeof(GoodHeader) == 8, "GoodHeader must be exactly 8 bytes");
static_assert(offsetof(GoodHeader, length) == 4, "length must be at byte 4");
```

If these fail, you get a compile error — not a mysterious runtime bug three weeks later.

### `alignas` — Forcing Alignment

Sometimes you need a buffer aligned to a specific boundary — DMA engines often require 4-byte or 32-byte aligned buffers:

```cpp
alignas(32) uint8_t dma_buffer[256];   // 32-byte aligned for DMA

alignas(alignof(double)) uint8_t raw[sizeof(double)];  // aligned for double
```

`alignof(T)` returns the alignment requirement of type T. `alignas(N)` forces a variable to be aligned to N bytes.

---

## 8. `std::byte` — The Right Type for Raw Memory

Since C++17, `std::byte` is the correct type for raw memory buffers. Unlike `char` or `uint8_t`, it has no arithmetic operators — it's purely a bag of bits. This tells both the compiler and the reader "this is not text, not a number, it's raw memory":

```cpp
#include <cstddef>

std::byte buffer[64];

// Bitwise ops work
buffer[0] = std::byte{0xFF};
buffer[1] = buffer[0] & std::byte{0x0F};

// Arithmetic does NOT work — intentional
// buffer[0] + 1;  // compile error

// Convert to/from integers explicitly
uint8_t val = std::to_integer<uint8_t>(buffer[0]);
buffer[0] = static_cast<std::byte>(42);
```

Use `std::byte` for protocol buffers and DMA regions. Use `uint8_t` when you actually need arithmetic on the values.

---

## 9. Putting It Together — Parsing a Binary Frame

Here's a realistic example combining everything from today: parsing a simplified MQTT fixed header from a raw byte buffer.

```cpp
#include <cstdint>
#include <cstddef>
#include <cstring>
#include <cassert>
#include <cstdio>

// MQTT fixed header — first two bytes of every packet
// Byte 0: [packet_type (4 bits)] [flags (4 bits)]
// Byte 1: remaining_length (simplified — single byte, max 127)

enum class MQTTPacketType : uint8_t {
    CONNECT     = 1,
    CONNACK     = 2,
    PUBLISH     = 3,
    PUBACK      = 4,
    SUBSCRIBE   = 8,
    SUBACK      = 9,
    PINGREQ     = 12,
    PINGRESP    = 13,
    DISCONNECT  = 14,
};

struct MQTTFixedHeader {
    MQTTPacketType packet_type;
    uint8_t        flags;
    uint8_t        remaining_length;
};

static_assert(sizeof(MQTTFixedHeader) == 3, "Header struct must be 3 bytes");

// Parse from raw buffer — no dynamic allocation, no exceptions
bool parse_fixed_header(const std::byte* buf, size_t len, MQTTFixedHeader& out) {
    if (len < 2) return false;

    uint8_t byte0;
    std::memcpy(&byte0, buf, sizeof(byte0));  // safe — no aliasing violation

    out.packet_type      = static_cast<MQTTPacketType>(byte0 >> 4);
    out.flags            = byte0 & 0x0F;

    uint8_t byte1;
    std::memcpy(&byte1, buf + 1, sizeof(byte1));
    out.remaining_length = byte1;

    return true;
}

// Serialize back to bytes
void serialize_fixed_header(const MQTTFixedHeader& hdr, std::byte* buf) {
    uint8_t byte0 = (static_cast<uint8_t>(hdr.packet_type) << 4) | (hdr.flags & 0x0F);
    std::memcpy(buf,     &byte0,              1);
    std::memcpy(buf + 1, &hdr.remaining_length, 1);
}

int main() {
    // Simulate a raw PUBLISH packet arriving from a socket
    // Byte 0: 0x30 = packet_type=3 (PUBLISH), flags=0
    // Byte 1: 0x0A = remaining_length=10
    std::byte raw[2] = { std::byte{0x30}, std::byte{0x0A} };

    MQTTFixedHeader hdr{};
    bool ok = parse_fixed_header(raw, sizeof(raw), hdr);

    assert(ok);
    assert(hdr.packet_type == MQTTPacketType::PUBLISH);
    assert(hdr.remaining_length == 10);

    printf("Packet type: %d\n", static_cast<int>(hdr.packet_type));
    printf("Flags:       0x%02X\n", hdr.flags);
    printf("Remaining:   %d bytes\n", hdr.remaining_length);

    // Round-trip: serialize back and verify bytes
    std::byte out[2]{};
    serialize_fixed_header(hdr, out);

    assert(out[0] == raw[0]);
    assert(out[1] == raw[1]);
    printf("Round-trip: OK\n");

    return 0;
}
```

Compile and run:

```bash
g++ -std=c++17 -Wall -Wextra -Wpedantic -o mqtt_parse mqtt_parse.cpp
./mqtt_parse
```

### What to observe in this code

- `static_assert` catches layout bugs at compile time — if you add a padding byte, the build breaks immediately
- `memcpy` for reading bytes into integers — not `reinterpret_cast` — because of strict aliasing
- `static_cast` for the enum conversion — explicit, documented intent
- `std::byte` for the raw buffer — not `char*`, not `uint8_t*`
- Bit shift and mask operations for packing/unpacking the nibble fields — this is the same bit manipulation you'd do in C, but the types make the intent clearer

---

## Key Takeaways for Day 3

- Four casts, four purposes — `static_cast` for safe conversions, `reinterpret_cast` for raw memory, `const_cast` for legacy interop, `dynamic_cast` for runtime hierarchy navigation
- Strict aliasing: the compiler assumes different pointer types don't alias — use `memcpy` to move bits between types safely, not `reinterpret_cast` through a pointer dereference
- `volatile` for hardware registers — tells the optimizer reads/writes have side effects it can't see
- Fixed-width types (`uint8_t`, `uint32_t`) for any protocol or hardware-facing code — never `int` or `short`
- Struct padding is real — order members large-to-small, verify with `static_assert` and `sizeof`
- `std::byte` for raw memory buffers — no arithmetic, no aliasing ambiguity, clear intent
- `static_assert` is free at runtime — use it aggressively to document and enforce assumptions

Day 4 covers functions in depth: overloading, `constexpr`, and how to write a type-safe serialize function that works correctly across all your integer types.