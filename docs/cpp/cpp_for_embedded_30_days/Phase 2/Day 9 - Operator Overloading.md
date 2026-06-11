

You've built `SensorBuffer` over the last three days — it manages memory, copies correctly, moves efficiently. But using it still feels like using a C struct: `buf.get(i)` instead of `buf[i]`, `buf.equals(other)` instead of `buf == other`, custom print functions instead of `std::cout << buf`. Operator overloading closes that gap. The goal isn't cleverness — it's making user-defined types feel as natural to use as built-in types.

---

## 1. The Core Principle — Don't Surprise Anyone

Operator overloading has one rule above all others: **behave the way the operator is expected to behave.** `operator+` should return a new value, not modify in place. `operator==` should be symmetric and return `bool`. `operator[]` should return a reference so assignment works through it.

Violating these expectations creates code that compiles but confuses everyone who reads it — including yourself three months later.

Operators you should overload when they make sense:

- `operator[]` — element access
- `operator==` / `operator!=` — equality
- `operator<` (and `<=>` in C++20) — ordering
- `operator<<` — stream output for logging
- `operator+`, `operator-`, etc. — arithmetic on value types
- `operator bool` — truthiness check

Operators you should almost never overload:

- `operator&&`, `operator||` — short-circuit evaluation breaks
- `operator,` — no legitimate use
- `operator&` (unary address-of) — breaks standard library machinery

---

## 2. Member vs Non-Member Operators

Operators can be defined as member functions or as free functions. The choice matters:

**Member function:** left-hand side is always `*this`. Works for `operator[]`, `operator()`, `operator=`, `operator->`.

**Non-member function:** needed when the left-hand side is not your type — `operator<<` with `std::ostream`, or symmetric operators where either side could be your type. Non-member operators that need access to private members are declared `friend`.

```cpp
class SensorBuffer {
public:
    // Member — lhs is always SensorBuffer
    float& operator[](size_t index);
    bool   operator==(const SensorBuffer& rhs) const;

    // Friend non-member — lhs is std::ostream, not SensorBuffer
    friend std::ostream& operator<<(std::ostream& os, const SensorBuffer& buf);
};
```

---

## 3. `operator[]` — Element Access

The most important operator for a buffer class. Provide two versions: const and non-const:

```cpp
// Non-const — allows modification: buf[i] = 23.5f
float& operator[](size_t index) {
    assert(index < size_);
    return data_[index];
}

// Const — read-only: float v = buf[i] on a const SensorBuffer
const float& operator[](size_t index) const {
    assert(index < size_);
    return data_[index];
}
```

Returning a reference means `buf[i] = 99.0f` works — the assignment goes through the reference directly into `data_[i]`. If you returned by value, assignment would silently do nothing.

The const version returns `const float&` — a const object can be read but not written through `operator[]`.

```cpp
SensorBuffer buf(8);
buf.push(23.5f);
buf.push(24.1f);

buf[0] = 99.0f;         // non-const operator[] — writes through reference
float v = buf[1];       // non-const operator[] — reads

const SensorBuffer& cbuf = buf;
float cv = cbuf[0];     // const operator[] — reads
// cbuf[0] = 1.0f;      // compile error — const reference, can't assign
```

---

## 4. `operator==` and `operator!=`

Equality should be symmetric (`a == b` iff `b == a`), reflexive (`a == a` always), and consistent.

```cpp
bool operator==(const SensorBuffer& rhs) const {
    if (size_ != rhs.size_) return false;
    for (size_t i = 0; i < size_; ++i) {
        if (data_[i] != rhs.data_[i]) return false;
    }
    return true;
}

bool operator!=(const SensorBuffer& rhs) const {
    return !(*this == rhs);   // implement in terms of == — one source of truth
}
```

Always implement `operator!=` in terms of `operator==`. Any other approach risks inconsistency.

**Floating-point equality warning:** comparing `float` with `==` is exact comparison — `0.1f + 0.2f != 0.3f` due to floating-point representation. For sensor readings, you often want epsilon comparison:

```cpp
bool approximately_equal(const SensorBuffer& rhs, float epsilon = 1e-5f) const {
    if (size_ != rhs.size_) return false;
    for (size_t i = 0; i < size_; ++i) {
        if (std::abs(data_[i] - rhs.data_[i]) > epsilon) return false;
    }
    return true;
}
```

Keep `operator==` as exact comparison (predictable semantics), provide a named function for fuzzy comparison.

---

## 5. `operator<=>` — The Spaceship Operator (C++20)

C++20 introduced the three-way comparison operator, which generates all six comparison operators from one definition:

```cpp
#include <compare>

auto operator<=>(const SensorBuffer& rhs) const {
    // Compare by size first, then lexicographically by content
    if (auto cmp = size_ <=> rhs.size_; cmp != 0) return cmp;
    for (size_t i = 0; i < size_; ++i) {
        if (auto cmp = data_[i] <=> rhs.data_[i]; cmp != 0) return cmp;
    }
    return std::strong_ordering::equal;
}
```

With `operator<=>` defined, the compiler auto-generates `<`, `>`, `<=`, `>=`. Combined with `operator==`, you get all six comparison operators from two definitions.

For C++17, write `operator<` manually and implement the rest in terms of it:

```cpp
bool operator<(const SensorBuffer& rhs) const {
    if (size_ != rhs.size_) return size_ < rhs.size_;
    for (size_t i = 0; i < size_; ++i) {
        if (data_[i] != rhs.data_[i]) return data_[i] < rhs.data_[i];
    }
    return false;
}
bool operator> (const SensorBuffer& rhs) const { return rhs < *this; }
bool operator<=(const SensorBuffer& rhs) const { return !(rhs < *this); }
bool operator>=(const SensorBuffer& rhs) const { return !(*this < rhs); }
```

---

## 6. `operator<<` — Stream Output

This is the standard C++ logging/debugging operator. It must be a non-member (the left-hand side is `std::ostream`, not your type):

```cpp
#include <ostream>

// Friend declaration inside the class
class SensorBuffer {
    friend std::ostream& operator<<(std::ostream& os, const SensorBuffer& buf);
    // ...
};

// Definition outside the class
std::ostream& operator<<(std::ostream& os, const SensorBuffer& buf) {
    os << "SensorBuffer[" << buf.size_ << "/" << buf.capacity_ << "](";
    for (size_t i = 0; i < buf.size_; ++i) {
        if (i > 0) os << ", ";
        os << buf.data_[i];
    }
    os << ")";
    return os;   // return os to enable chaining: cout << a << b << c
}
```

Always return `os` by reference — this enables chaining:

```cpp
std::cout << "Buffer: " << buf << " avg=" << buf.average() << "\n";
```

Each `operator<<` call returns the same `os`, so the next `<<` applies to the same stream.

---

## 7. `operator bool` — Truthiness

A buffer is "true" if it has data, "false" if empty. The `explicit` keyword prevents it from being used in arithmetic contexts:

```cpp
explicit operator bool() const {
    return size_ > 0;
}
```

```cpp
SensorBuffer buf(8);

if (buf) {                          // calls operator bool
    printf("has data\n");
}

// Without explicit, this would compile silently:
// int n = buf;  // implicit bool->int conversion — almost certainly a bug
// With explicit, this is a compile error — correct
```

`explicit` on `operator bool` is the right default — always.

---

## 8. `operator+` and Arithmetic Operators

For value types (a `Vector3`, a `Duration`, a `Reading`), arithmetic operators make sense. The pattern: binary operators are non-members that call compound-assignment members:

```cpp
// Compound assignment — modifies *this, returns reference
SensorBuffer& operator+=(const SensorBuffer& rhs) {
    for (size_t i = 0; i < rhs.size_ && size_ < capacity_; ++i) {
        push(rhs.data_[i]);
    }
    return *this;
}

// Binary — creates new object, uses += internally
// Non-member: either side can invoke it, and it doesn't need private access
// (if it does need private access, make it friend)
SensorBuffer operator+(SensorBuffer lhs, const SensorBuffer& rhs) {
    lhs += rhs;   // lhs is already a copy (passed by value)
    return lhs;   // NRVO will elide the copy here
}
```

Taking `lhs` by value (not const reference) in `operator+` means the copy happens in the function call — the compiler can then apply NRVO to elide the return copy. This is the canonical efficient implementation.

---

## 9. `operator()` — Callable Objects (Functors)

`operator()` makes an object callable like a function. Useful for stateful callbacks, comparators, and transform operations:

```cpp
class ScaledReading {
    float scale_;
    float offset_;
public:
    ScaledReading(float scale, float offset)
        : scale_(scale), offset_(offset) {}

    float operator()(float raw) const {
        return raw * scale_ + offset_;
    }
};

ScaledReading celsius_to_fahrenheit(1.8f, 32.0f);
float f = celsius_to_fahrenheit(23.5f);  // calls operator()
printf("%.1f°C = %.1f°F\n", 23.5f, f);  // 23.5°C = 74.3°F

// Works with STL algorithms
SensorBuffer raw_temps(8);
raw_temps.push(20.0f); raw_temps.push(23.5f); raw_temps.push(18.2f);

SensorBuffer fahrenheit(raw_temps.size());
for (size_t i = 0; i < raw_temps.size(); ++i) {
    fahrenheit.push(celsius_to_fahrenheit(raw_temps[i]));
}
```

This is what lambdas compile to under the hood — a struct with `operator()`. Understanding functors makes lambdas (Day 14) completely demystified.

---

## 10. Putting It Together — Complete `SensorBuffer`

Full class with every operator from today added to the Rule of Five base from Day 8:

```cpp
// sensor_buffer.hpp
#pragma once
#include <cstddef>
#include <cstring>
#include <cmath>
#include <cassert>
#include <stdexcept>
#include <ostream>
#include <compare>

class SensorBuffer {
public:
    // --- Constructors / Rule of Five ---

    explicit SensorBuffer(size_t capacity = 64)
        : data_(new float[capacity])
        , size_(0)
        , capacity_(capacity)
    {
        assert(capacity > 0);
    }

    SensorBuffer(const float* src, size_t count)
        : SensorBuffer(count)
    {
        std::memcpy(data_, src, count * sizeof(float));
        size_ = count;
    }

    ~SensorBuffer() { delete[] data_; }

    SensorBuffer(const SensorBuffer& o)
        : data_(new float[o.capacity_])
        , size_(o.size_)
        , capacity_(o.capacity_)
    {
        std::memcpy(data_, o.data_, size_ * sizeof(float));
    }

    SensorBuffer& operator=(SensorBuffer o) {   // copy-and-swap
        swap(o);
        return *this;
    }

    SensorBuffer(SensorBuffer&& o) noexcept
        : data_(o.data_), size_(o.size_), capacity_(o.capacity_)
    {
        o.data_ = nullptr; o.size_ = 0; o.capacity_ = 0;
    }

    SensorBuffer& operator=(SensorBuffer&& o) noexcept {
        if (this != &o) {
            delete[] data_;
            data_ = o.data_; size_ = o.size_; capacity_ = o.capacity_;
            o.data_ = nullptr; o.size_ = 0; o.capacity_ = 0;
        }
        return *this;
    }

    void swap(SensorBuffer& o) noexcept {
        std::swap(data_, o.data_);
        std::swap(size_, o.size_);
        std::swap(capacity_, o.capacity_);
    }

    // --- Modifiers ---

    bool push(float value) {
        if (size_ >= capacity_) return false;
        data_[size_++] = value;
        return true;
    }

    void clear() { size_ = 0; }

    // --- operator[] ---

    float& operator[](size_t i) {
        assert(i < size_);
        return data_[i];
    }

    const float& operator[](size_t i) const {
        assert(i < size_);
        return data_[i];
    }

    // --- Equality ---

    bool operator==(const SensorBuffer& rhs) const {
        if (size_ != rhs.size_) return false;
        for (size_t i = 0; i < size_; ++i)
            if (data_[i] != rhs.data_[i]) return false;
        return true;
    }

    bool operator!=(const SensorBuffer& rhs) const {
        return !(*this == rhs);
    }

    // --- Ordering (C++17 style) ---

    bool operator<(const SensorBuffer& rhs) const {
        if (size_ != rhs.size_) return size_ < rhs.size_;
        for (size_t i = 0; i < size_; ++i)
            if (data_[i] != rhs.data_[i]) return data_[i] < rhs.data_[i];
        return false;
    }

    // --- Truthiness ---

    explicit operator bool() const { return size_ > 0; }

    // --- Arithmetic ---

    SensorBuffer& operator+=(const SensorBuffer& rhs) {
        for (size_t i = 0; i < rhs.size_ && size_ < capacity_; ++i)
            push(rhs.data_[i]);
        return *this;
    }

    friend SensorBuffer operator+(SensorBuffer lhs, const SensorBuffer& rhs) {
        lhs += rhs;
        return lhs;
    }

    // --- Stream output ---

    friend std::ostream& operator<<(std::ostream& os, const SensorBuffer& buf) {
        os << "SensorBuffer[" << buf.size_ << "/" << buf.capacity_ << "](";
        for (size_t i = 0; i < buf.size_; ++i) {
            if (i > 0) os << ", ";
            os << buf.data_[i];
        }
        return os << ")";
    }

    // --- Accessors ---

    size_t size()     const { return size_; }
    size_t capacity() const { return capacity_; }
    bool   empty()    const { return size_ == 0; }
    bool   full()     const { return size_ == capacity_; }

    float average() const {
        if (size_ == 0) return 0.0f;
        float sum = 0.0f;
        for (size_t i = 0; i < size_; ++i) sum += data_[i];
        return sum / static_cast<float>(size_);
    }

    // --- Iterator support (enables range-for) ---

    float*       begin()       { return data_; }
    float*       end()         { return data_ + size_; }
    const float* begin() const { return data_; }
    const float* end()   const { return data_ + size_; }

private:
    float*  data_;
    size_t  size_;
    size_t  capacity_;
};
```

```cpp
// main.cpp
#include "sensor_buffer.hpp"
#include <iostream>
#include <algorithm>
#include <vector>

int main() {
    // operator[]
    SensorBuffer buf(8);
    buf.push(23.5f); buf.push(24.1f); buf.push(22.8f);

    buf[0] = 99.0f;                    // write through non-const ref
    std::cout << "buf[0] = " << buf[0] << "\n";

    // operator
    std::cout << buf << "\n";

    // operator bool
    if (buf) std::cout << "buf has data\n";
    SensorBuffer empty(4);
    if (!empty) std::cout << "empty has no data\n";

    // operator== / !=
    SensorBuffer a(4), b(4);
    a.push(1.0f); a.push(2.0f);
    b.push(1.0f); b.push(2.0f);
    std::cout << "a == b: " << (a == b ? "true" : "false") << "\n";
    b[1] = 9.0f;
    std::cout << "a == b: " << (a == b ? "true" : "false") << "\n";

    // operator< — works with std::sort
    std::vector<SensorBuffer> bufs;
    bufs.push_back(SensorBuffer(4));
    bufs.back().push(5.0f);
    bufs.push_back(SensorBuffer(4));
    bufs.back().push(1.0f);
    bufs.push_back(SensorBuffer(4));
    bufs.back().push(3.0f);

    std::sort(bufs.begin(), bufs.end());
    std::cout << "Sorted: ";
    for (const auto& sb : bufs) std::cout << sb << " ";
    std::cout << "\n";

    // operator+ / +=
    SensorBuffer c(4), d(4);
    c.push(10.0f); c.push(20.0f);
    d.push(30.0f); d.push(40.0f);
    SensorBuffer e = c + d;
    std::cout << "c + d = " << e << "\n";

    // range-for (uses begin/end)
    std::cout << "Values: ";
    for (float v : e) std::cout << v << " ";
    std::cout << "\n";

    // ScaledReading functor
    ScaledReading to_f(1.8f, 32.0f);
    SensorBuffer celsius(4);
    celsius.push(0.0f); celsius.push(20.0f);
    celsius.push(100.0f); celsius.push(23.5f);

    SensorBuffer fahrenheit(celsius.size());
    for (float c_val : celsius) fahrenheit.push(to_f(c_val));
    std::cout << "Celsius:    " << celsius    << "\n";
    std::cout << "Fahrenheit: " << fahrenheit << "\n";

    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address -o op_demo main.cpp
./op_demo
```

---

## Key Takeaways for Day 9

- Overload operators to make types feel natural — not to be clever. If the semantics aren't obvious, use a named function instead
- `operator[]` returns a reference — both const and non-const versions, so it works on both const and non-const objects
- `operator!=` implements in terms of `operator==` — one source of truth, no inconsistency possible
- `operator<<` is a non-member friend — lhs is `std::ostream`, always return `os` by reference to enable chaining
- `explicit operator bool` prevents accidental arithmetic conversions — always use `explicit`
- Binary arithmetic operators (`+`, `-`) are non-members that call compound-assignment members (`+=`, `-=`) — canonical pattern
- Adding `begin()`/`end()` member functions enables range-for loops and full STL algorithm compatibility — always add these to container types
- `operator()` makes objects callable — this is exactly what lambdas compile to, a struct with `operator()` and captured state

Day 10 covers inheritance, polymorphism, and the vtable — when virtual functions are worth their cost, when composition is better, and how to design a `Sensor` interface hierarchy that stores mixed types in a single container.