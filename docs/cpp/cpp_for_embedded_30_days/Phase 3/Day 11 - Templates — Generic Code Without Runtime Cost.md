

Inheritance and virtual functions solve the "different types, same interface, runtime dispatch" problem. Templates solve a different problem: **same code, different types, zero runtime cost**. Where virtual functions defer decisions to runtime, templates resolve everything at compile time — the compiler generates separate, fully optimized code for each type you use.

Your `SensorBuffer<float>` and `SensorBuffer<uint16_t>` are different classes with different machine code, but you wrote the source once. That's the trade-off: compile-time cost (the compiler does more work), binary size (more generated code), and error messages (historically awful, improving with Concepts in C++20) — in exchange for zero runtime overhead.

---

## 1. The Problem Templates Solve

Without templates, generic code has two bad options:

```cpp
// Option 1: copy-paste for every type — maintenance nightmare
class FloatBuffer  { float*    data_; ... };
class IntBuffer    { int*      data_; ... };
class Uint8Buffer  { uint8_t*  data_; ... };

// Option 2: void* — type-unsafe, requires casting everywhere
class Buffer {
    void* data_;
public:
    void  push(void* item);
    void* get(size_t i);
};
Buffer b;
float f = 23.5f;
b.push(&f);
float* out = static_cast<float*>(b.get(0));  // hope you got the type right
```

Templates give you a third option: write the code once, let the compiler generate the type-specific versions:

```cpp
template<typename T>
class Buffer {
    T* data_;
public:
    void push(const T& item);
    T&   get(size_t i);
};

Buffer<float>   fb;   // compiler generates Buffer<float>
Buffer<uint8_t> bb;   // compiler generates Buffer<uint8_t>
// Both are fully type-safe — no casts, no void*
```

---

## 2. Function Templates

Start with the simplest case — a templated free function:

```cpp
// T is a type parameter — replaced by the actual type at the call site
template<typename T>
T clamp(T value, T low, T high) {
    if (value < low)  return low;
    if (value > high) return high;
    return value;
}

// Usage — compiler deduces T from the arguments
float f = clamp(23.5f, 0.0f, 100.0f);   // T = float
int   i = clamp(150,   0,    100);       // T = int
float v = clamp<float>(23.5f, 0.0f, 100.0f);  // explicit T — rarely needed
```

The compiler generates separate `clamp<float>` and `clamp<int>` functions. They're fully inlined and optimized — identical to code you'd write by hand for each type.

### Multiple Type Parameters

```cpp
template<typename From, typename To>
To convert(From value) {
    return static_cast<To>(value);
}

float f = convert<uint16_t, float>(4095);   // ADC raw to float
```

### Non-Type Template Parameters

Templates can be parameterized on values, not just types. This is how you encode buffer size at compile time:

```cpp
template<typename T, size_t N>
class StaticBuffer {
    T      data_[N];   // N is a compile-time constant — stack allocated
    size_t size_ = 0;
public:
    bool push(const T& v) {
        if (size_ >= N) return false;
        data_[size_++] = v;
        return true;
    }
    size_t size()     const { return size_; }
    size_t capacity() const { return N; }
    T&     operator[](size_t i) { return data_[i]; }
};

StaticBuffer<float, 64>   temp_readings;   // 64 floats on the stack
StaticBuffer<uint8_t, 8>  frame_header;    // 8 bytes on the stack
// Zero heap allocation — critical for embedded/real-time code
```

`N` is baked into the type — `StaticBuffer<float, 64>` and `StaticBuffer<float, 128>` are different types. The compiler enforces size constraints at compile time.

---

## 3. Class Templates

A class template is a blueprint for generating classes:

```cpp
template<typename T, size_t Capacity>
class RingBuffer {
public:
    RingBuffer() : head_(0), tail_(0), count_(0) {}

    bool push(const T& item) {
        if (full()) return false;
        data_[head_] = item;
        head_ = (head_ + 1) % Capacity;
        ++count_;
        return true;
    }

    bool pop(T& out) {
        if (empty()) return false;
        out = data_[tail_];
        tail_ = (tail_ + 1) % Capacity;
        --count_;
        return true;
    }

    bool   empty() const { return count_ == 0; }
    bool   full()  const { return count_ == Capacity; }
    size_t size()  const { return count_; }

    // Peek at next item without removing
    const T* peek() const {
        if (empty()) return nullptr;
        return &data_[tail_];
    }

private:
    T      data_[Capacity];
    size_t head_;
    size_t tail_;
    size_t count_;
};

// Usage
RingBuffer<float,    16>  adc_samples;    // 16-element float ring buffer
RingBuffer<uint8_t, 256>  uart_rx;        // 256-byte UART receive buffer
```

`RingBuffer<float, 16>` is a completely different type from `RingBuffer<float, 32>` — the size is part of the type. The compiler generates separate, fully optimized code for each instantiation.

---

## 4. Template Member Functions

Member functions of class templates are themselves templates — they must be defined in the header (not a `.cpp` file) because the compiler needs the full definition at the point of instantiation:

```cpp
// ring_buffer.hpp
template<typename T, size_t Capacity>
class RingBuffer {
public:
    bool push(const T& item);   // declaration
    bool pop(T& out);
    // ...
private:
    T      data_[Capacity];
    size_t head_, tail_, count_;
};

// Definition must also be in the header — not in a .cpp
template<typename T, size_t Capacity>
bool RingBuffer<T, Capacity>::push(const T& item) {
    if (full()) return false;
    data_[head_] = item;
    head_ = (head_ + 1) % Capacity;
    ++count_;
    return true;
}
```

This is the biggest practical difference from regular classes: **template implementations go in headers**. If you put them in a `.cpp` and try to use the template from another file, you'll get linker errors.

---

## 5. Template Specialization

You can provide a custom implementation for a specific type — the general template handles everything else, the specialization handles the specific case:

```cpp
// General template — works for any T
template<typename T>
T byte_swap(T value) {
    T result{};
    auto* src  = reinterpret_cast<uint8_t*>(&value);
    auto* dst  = reinterpret_cast<uint8_t*>(&result);
    for (size_t i = 0; i < sizeof(T); ++i) {
        dst[i] = src[sizeof(T) - 1 - i];
    }
    return result;
}

// Full specialization for uint16_t — use hardware intrinsic
template<>
uint16_t byte_swap<uint16_t>(uint16_t value) {
    return __builtin_bswap16(value);
}

// Full specialization for uint32_t
template<>
uint32_t byte_swap<uint32_t>(uint32_t value) {
    return __builtin_bswap32(value);
}

uint16_t a = byte_swap<uint16_t>(0x1234);  // uses specialization: 0x3412
uint32_t b = byte_swap<uint32_t>(0x12345678);  // uses specialization
double   d = byte_swap<double>(3.14);           // uses general template
```

### Partial Specialization — For Class Templates Only

You can specialize a class template on some but not all parameters:

```cpp
// General template
template<typename T, size_t N>
class StaticBuffer { ... };

// Partial specialization for bool — pack bits
template<size_t N>
class StaticBuffer<bool, N> {
    uint8_t data_[(N + 7) / 8];   // one bit per bool
    // completely different implementation
};
```

Function templates can't be partially specialized (use overloading instead).

---

## 6. `typename` vs `class`

In template parameter lists, `typename` and `class` are interchangeable — both mean "a type":

```cpp
template<typename T>  // same as
template<class T>     // this
```

`typename` is preferred in modern code — it's clearer that T can be any type, not just a class type. `class` as a template parameter keyword is a historical artifact.

`typename` has a second, non-interchangeable use — disambiguating dependent names:

```cpp
template<typename T>
void process() {
    // T::iterator could be a type or a static member — ambiguous
    typename T::iterator it;   // typename tells the compiler: this is a type
}
```

You'll encounter this when writing templates that use types nested inside other template parameters. The compiler requires `typename` to confirm the nested name is a type.

---

## 7. Template Type Deduction

The compiler deduces template arguments from function call arguments. Understanding the deduction rules saves debugging time:

```cpp
template<typename T>
void f(T x);

f(42);       // T = int
f(42.0);     // T = double
f("hello");  // T = const char*

template<typename T>
void g(T& x);

int i = 42;
g(i);        // T = int, parameter type is int&
g(42);       // error — can't bind rvalue to non-const lvalue reference

template<typename T>
void h(const T& x);

h(42);       // T = int, parameter type is const int& — works with rvalues
h(i);        // T = int, parameter type is const int&

template<typename T>
void k(T* x);

k(&i);       // T = int, parameter type is int*
```

The rule: deduction strips references and top-level const from the argument to determine T, then applies the parameter's qualifiers. When in doubt, be explicit: `f<float>(42)`.

---

## 8. Putting It Together — `RingBuffer<T, N>`

Full implementation for today. This is a production-grade ring buffer suitable for UART RX/TX, ADC sample queues, or MQTT message staging:

```cpp
// ring_buffer.hpp
#pragma once
#include <cstddef>
#include <cstdint>
#include <cassert>
#include <optional>
#include <new>       // std::launder

template<typename T, size_t Capacity>
class RingBuffer {
    static_assert(Capacity > 0, "RingBuffer capacity must be > 0");
    static_assert((Capacity & (Capacity - 1)) == 0,
                  "RingBuffer capacity must be a power of 2");
    // Power-of-2 capacity allows masking instead of modulo:
    // (index + 1) % Capacity  →  (index + 1) & MASK
    // Modulo is a division — expensive on small MCUs. & is one cycle.

    static constexpr size_t MASK = Capacity - 1;

public:
    RingBuffer() : head_(0), tail_(0), count_(0) {}

    // Non-copyable — a buffer represents a hardware-backed queue
    RingBuffer(const RingBuffer&)            = delete;
    RingBuffer& operator=(const RingBuffer&) = delete;

    // Movable
    RingBuffer(RingBuffer&&) noexcept            = default;
    RingBuffer& operator=(RingBuffer&&) noexcept = default;

    // --- Push — add to head ---

    // Copy push
    bool push(const T& item) {
        if (full()) return false;
        data_[head_] = item;
        head_ = (head_ + 1) & MASK;
        ++count_;
        return true;
    }

    // Move push — avoids copy for movable types
    bool push(T&& item) {
        if (full()) return false;
        data_[head_] = std::move(item);
        head_ = (head_ + 1) & MASK;
        ++count_;
        return true;
    }

    // Emplace — construct in-place, no copy or move
    template<typename... Args>
    bool emplace(Args&&... args) {
        if (full()) return false;
        data_[head_] = T(std::forward<Args>(args)...);
        head_ = (head_ + 1) & MASK;
        ++count_;
        return true;
    }

    // --- Pop — remove from tail ---

    bool pop(T& out) {
        if (empty()) return false;
        out = std::move(data_[tail_]);
        tail_ = (tail_ + 1) & MASK;
        --count_;
        return true;
    }

    // Pop returning optional — cleaner at the call site
    std::optional<T> pop() {
        if (empty()) return std::nullopt;
        T item = std::move(data_[tail_]);
        tail_ = (tail_ + 1) & MASK;
        --count_;
        return item;
    }

    // --- Peek ---

    T*       peek()       { return empty() ? nullptr : &data_[tail_]; }
    const T* peek() const { return empty() ? nullptr : &data_[tail_]; }

    // --- State ---

    bool   empty()    const { return count_ == 0; }
    bool   full()     const { return count_ == Capacity; }
    size_t size()     const { return count_; }
    size_t capacity() const { return Capacity; }
    size_t available() const { return Capacity - count_; }

    // --- Bulk operations ---

    // Push up to len items from src — returns number actually pushed
    size_t push_bulk(const T* src, size_t len) {
        size_t pushed = 0;
        while (pushed < len && push(src[pushed])) ++pushed;
        return pushed;
    }

    // Pop up to len items into dst — returns number actually popped
    size_t pop_bulk(T* dst, size_t len) {
        size_t popped = 0;
        while (popped < len) {
            auto item = pop();
            if (!item) break;
            dst[popped++] = std::move(*item);
        }
        return popped;
    }

    void clear() {
        head_ = tail_ = count_ = 0;
    }

private:
    T      data_[Capacity];
    size_t head_;    // next write position
    size_t tail_;    // next read position
    size_t count_;   // current number of elements
};
```

```cpp
// main.cpp
#include "ring_buffer.hpp"
#include <cstdio>
#include <cstdint>
#include <cassert>
#include <string>

// ---- Sensor reading type ----

struct SensorReading {
    float    value;
    uint32_t timestamp_ms;
    uint8_t  sensor_id;

    void print() const {
        printf("  [t=%u id=%u] %.2f\n", timestamp_ms, sensor_id, value);
    }
};

// ---- Simulate ISR producing bytes into a ring buffer ----

void simulate_uart_rx(RingBuffer<uint8_t, 256>& rx) {
    // Simulated bytes arriving from hardware
    const uint8_t incoming[] = {0xAB, 0x01, 0x2A, 0x00, 0x64, 0x00, 0xFF};
    for (uint8_t b : incoming) {
        if (!rx.push(b)) {
            printf("  UART RX OVERFLOW — dropped byte 0x%02X\n", b);
        }
    }
}

int main() {
    printf("=== RingBuffer<T, N> Demo ===\n\n");

    // ---- uint8_t ring buffer — UART RX ----
    printf("--- UART RX (uint8_t, 256) ---\n");
    RingBuffer<uint8_t, 256> uart_rx;

    simulate_uart_rx(uart_rx);
    printf("Received %zu bytes\n", uart_rx.size());

    // Drain with optional pop
    while (auto byte = uart_rx.pop()) {
        printf("  0x%02X\n", *byte);
    }
    assert(uart_rx.empty());

    // ---- float ring buffer — ADC samples ----
    printf("\n--- ADC Samples (float, 16) ---\n");
    RingBuffer<float, 16> adc;

    for (int i = 0; i < 10; ++i) {
        adc.push(20.0f + static_cast<float>(i) * 0.5f);
    }
    printf("ADC buffer: %zu/%zu\n", adc.size(), adc.capacity());

    // Process in bulk
    float batch[4];
    size_t got = adc.pop_bulk(batch, 4);
    printf("Bulk popped %zu samples:", got);
    for (size_t i = 0; i < got; ++i) printf(" %.1f", batch[i]);
    printf("\n");

    // ---- SensorReading ring buffer ----
    printf("\n--- Sensor Readings (SensorReading, 8) ---\n");
    RingBuffer<SensorReading, 8> readings;

    // Emplace — construct in-place, no temporaries
    readings.emplace(23.5f, 1000u, uint8_t(1));
    readings.emplace(65.0f, 1001u, uint8_t(2));
    readings.emplace(1013.2f, 1002u, uint8_t(3));

    // Move push
    SensorReading r{22.1f, 1003u, 1};
    readings.push(std::move(r));

    printf("Queue depth: %zu\n", readings.size());
    while (auto reading = readings.pop()) {
        reading->print();
    }

    // ---- Overflow handling ----
    printf("\n--- Overflow Test (uint8_t, 4) ---\n");
    RingBuffer<uint8_t, 4> tiny;
    for (int i = 0; i < 6; ++i) {
        bool ok = tiny.push(static_cast<uint8_t>(i));
        printf("  push(%d): %s\n", i, ok ? "ok" : "FULL — dropped");
    }

    // ---- static_assert verification ----
    // These should fail at compile time — uncomment to verify:
    // RingBuffer<int, 0>  zero_cap;   // error: capacity must be > 0
    // RingBuffer<int, 3>  bad_cap;    // error: capacity must be power of 2

    // ---- Type deduction in function template ----
    printf("\n--- clamp<T> function template ---\n");
    printf("clamp(23.5f, 0, 100):  %.1f\n", clamp(23.5f,  0.0f,  100.0f));
    printf("clamp(150,   0, 100):  %d\n",   clamp(150,    0,     100));
    printf("clamp(-5.0,  0, 100):  %.1f\n", clamp(-5.0f,  0.0f,  100.0f));

    printf("\nAll tests passed.\n");
    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address,undefined -o ring ring_buffer.hpp main.cpp
./ring
```

### What to observe

- The `static_assert` on power-of-2 capacity fires at compile time — try `RingBuffer<int, 3>` and read the error
- `(head_ + 1) & MASK` replaces `% Capacity` — one cycle vs a division on ARM Cortex-M
- `emplace()` uses variadic templates (`Args&&...`) and `std::forward` — we cover these fully on Day 15, but notice the syntax
- `pop()` returning `std::optional<T>` — the call site `while (auto byte = uart_rx.pop())` is cleaner than the out-parameter version
- The same `RingBuffer` class handles `uint8_t`, `float`, and `SensorReading` — one source, three generated classes, zero runtime polymorphism cost

---

## Key Takeaways for Day 11

- Templates generate type-specific code at compile time — zero runtime cost, full type safety, no `void*` casting
- Non-type template parameters (like `size_t N`) encode values in the type — buffer size becomes a compile-time constant, enabling stack allocation and `static_assert` validation
- Template definitions must live in headers — the compiler needs the full definition at every instantiation point
- `static_assert` inside templates fires at instantiation time with a clear message — use it aggressively to document preconditions
- Full specialization overrides the general template for a specific type — use it for hardware intrinsics, platform-specific optimizations
- Power-of-2 buffer sizes with bitmask indexing replace modulo — one instruction vs a division, significant on small MCUs
- `std::optional<T>` as a pop return type is cleaner than out-parameters — the caller uses `if (auto v = buf.pop())` naturally
- Variadic templates (`typename... Args`) and `std::forward` enable perfect in-place construction — we build on this fully on Day 15

Day 12 covers the STL containers — `std::vector`, `std::unordered_map`, `std::deque` — what each one costs, when each one wins, and how to build a `DeviceRegistry` that does O(1) lookup.