

Day 5 finished with a frame parser built from free functions and plain structs. That works, but it leaves ownership questions unanswered: who allocates the buffer? Who frees it? What happens if an error occurs halfway through setup? Classes answer these questions by tying resource lifetime directly to object lifetime. The constructor/destructor pair is the mechanism — and once you understand it deeply, you'll see that RAII (Day 7) is just this idea applied consistently.

---

## 1. Structs vs Classes — One Real Difference

In C++, `struct` and `class` are almost identical. The only difference is default access:

```cpp
struct Foo {
    int x;      // public by default
};

class Bar {
    int x;      // private by default
};
```

That's it. A `struct` can have constructors, destructors, member functions, inheritance — everything a `class` can. The convention in modern C++:

- `struct` — passive data holder, all members public, no invariants to protect
- `class` — has invariants, encapsulates implementation, members private by default

Your `SensorReading` and `FrameHeader` from earlier days are structs — they're just data. Today's `SensorBuffer` is a class — it manages a resource and has an invariant (the buffer is always valid while the object is alive).

---

## 2. Access Control

```cpp
class SensorBuffer {
public:
    // Accessible to everyone
    void push(float value);
    float get(size_t index) const;
    size_t size() const;

protected:
    // Accessible to this class and derived classes
    void reset_internal();

private:
    // Accessible only to this class
    float*  data_;     // trailing underscore = member variable convention
    size_t  size_;
    size_t  capacity_;
};
```

The trailing underscore on member variables (`data_`, `size_`, `capacity_`) is a common convention to distinguish members from local variables and parameters. You'll see `m_data` in some codebases — pick one and be consistent.

**Why private?** Because private members are an implementation detail. If you change them, only this class needs updating. If everything is public, any change breaks every caller. Encapsulation is about controlling the surface area of change.

---

## 3. Constructors — Establishing the Invariant

A constructor's job is to bring the object from uninitialized memory into a valid, usable state. When the constructor returns, the object's invariant must hold — or the constructor must have thrown (which means the object was never considered "created").

```cpp
class SensorBuffer {
public:
    // Constructor — takes capacity, allocates buffer
    SensorBuffer(size_t capacity)
        : data_(new float[capacity])   // member initializer list
        , size_(0)
        , capacity_(capacity)
    {
        // Constructor body — invariant is established by the time we get here
        // data_ is valid, size_ is 0, capacity_ is set
    }

private:
    float*  data_;
    size_t  size_;
    size_t  capacity_;
};
```

### The Member Initializer List

The `: data_(...), size_(...), capacity_(...)` syntax before the constructor body is the **member initializer list**. This is not optional style — it's how members are actually constructed. The constructor body runs _after_ all members are initialized.

```cpp
class Foo {
    std::string name_;
    int         count_;
public:
    // WRONG — name_ is default-constructed first, then assigned
    // Two operations: construct + assign
    Foo(const std::string& name) {
        name_ = name;    // assignment, not initialization
        count_ = 0;
    }

    // RIGHT — name_ is copy-constructed directly from name
    // One operation: construct
    Foo(const std::string& name)
        : name_(name)    // direct initialization
        , count_(0)
    {}
};
```

For `std::string` and other non-trivial types, using the initializer list avoids a default construction followed by an assignment — it constructs in place. For `int` and `float`, it doesn't matter for performance but it's consistent and good habit.

**Some members MUST use the initializer list:**

- `const` members — can't be assigned after construction
- Reference members — must be bound at construction
- Members without default constructors

```cpp
class Config {
    const int    baud_rate_;   // const — must use initializer list
    SerialPort&  port_;        // reference — must use initializer list
    int          timeout_;

public:
    Config(int baud, SerialPort& port)
        : baud_rate_(baud)   // required
        , port_(port)        // required
        , timeout_(100)      // good practice
    {}
};
```

### Initialization Order

Members are initialized **in the order they are declared in the class**, not in the order they appear in the initializer list. This is a subtle trap:

```cpp
class Bad {
    int capacity_;
    int* data_;
public:
    Bad(int cap)
        : data_(new int[capacity_])  // WRONG — capacity_ not initialized yet
        , capacity_(cap)
    {}
};

class Good {
    int  capacity_;   // declared first
    int* data_;       // declared second
public:
    Good(int cap)
        : capacity_(cap)             // initialized first (declared first)
        , data_(new int[capacity_])  // capacity_ is valid now
    {}
};
```

The order of the initializer list entries doesn't control initialization order — declaration order does. Keep them in the same order to avoid confusion.

---

## 4. Multiple Constructors & Constructor Delegation

A class can have multiple constructors — overloaded on their parameters:

```cpp
class SensorBuffer {
public:
    // Default constructor — empty buffer with default capacity
    SensorBuffer()
        : SensorBuffer(64)   // delegates to the (size_t) constructor
    {}

    // Primary constructor
    explicit SensorBuffer(size_t capacity)
        : data_(new float[capacity])
        , size_(0)
        , capacity_(capacity)
    {}

    // Construct from an existing array (copy the data in)
    SensorBuffer(const float* src, size_t count)
        : SensorBuffer(count)   // delegate to allocate
    {
        std::memcpy(data_, src, count * sizeof(float));
        size_ = count;
    }

private:
    float*  data_;
    size_t  size_;
    size_t  capacity_;
};

SensorBuffer a;           // default — 64 elements
SensorBuffer b(128);      // 128 elements
float init[] = {1,2,3};
SensorBuffer c(init, 3);  // copy from array
```

Constructor delegation (`: SensorBuffer(64)`) lets you avoid duplicating initialization logic. The delegated constructor runs first, then the delegating constructor's body runs.

---

## 5. `explicit` — Preventing Implicit Conversion

Without `explicit`, single-argument constructors create an implicit conversion path:

```cpp
class SensorBuffer {
public:
    SensorBuffer(size_t capacity);  // no explicit
};

void process(SensorBuffer buf);

process(64);   // silently converts 64 to SensorBuffer(64) — surprising
```

This is almost never what you want. Mark single-argument constructors `explicit` by default:

```cpp
explicit SensorBuffer(size_t capacity);

process(64);                      // compile error — no implicit conversion
process(SensorBuffer(64));        // fine — explicit
process(SensorBuffer{64});        // fine — brace initialization
```

The exception: types designed to be transparently convertible, like a `string_view` from `const char*`. For most classes — use `explicit`.

---

## 6. The Destructor — Guaranteed Cleanup

The destructor runs when the object's lifetime ends:

- For stack objects: when execution leaves the scope
- For heap objects: when `delete` is called
- For member objects: when the containing object is destroyed

```cpp
class SensorBuffer {
public:
    explicit SensorBuffer(size_t capacity)
        : data_(new float[capacity])
        , size_(0)
        , capacity_(capacity)
    {}

    ~SensorBuffer() {          // destructor — tilde prefix
        delete[] data_;        // release the heap allocation
        data_ = nullptr;       // defensive — prevent use-after-free in UB cases
    }

private:
    float*  data_;
    size_t  size_;
    size_t  capacity_;
};
```

The destructor is called **automatically**. You never call it directly (with rare exceptions involving placement new). This is the guarantee that makes RAII work — no matter how the scope exits (normal return, early return, exception), the destructor runs.

```cpp
void process() {
    SensorBuffer buf(128);   // constructor: allocates 128 floats

    if (error_condition) {
        return;              // destructor runs here — memory freed
    }

    do_work(buf);

}   // destructor runs here normally — memory freed
```

In C, either path requires a manual `free()`. Miss one and you have a leak. In C++, the destructor handles both paths automatically.

### Destructor Rules

- No parameters, no return type
- A class has exactly one destructor
- If you don't write one, the compiler generates a default that calls member destructors — usually correct for classes with no raw resources
- If your class manages a raw resource (pointer, file handle, socket fd), you need to write one

---

## 7. Object Layout in Memory

Understanding how a class is laid out in memory matters for protocol work and embedded code:

```cpp
class SensorReading {
    float   value_;      // 4 bytes, offset 0
    int32_t timestamp_;  // 4 bytes, offset 4
    uint8_t sensor_id_;  // 1 byte,  offset 8
    // 3 bytes padding here
};
// sizeof(SensorReading) == 12

class WithVirtual {
    void* vtable_ptr_;   // 8 bytes (64-bit) — added by compiler for virtual functions
    float value_;        // 4 bytes
    // 4 bytes padding
};
// sizeof(WithVirtual) == 16 on 64-bit
```

The vtable pointer is added automatically when you have `virtual` functions. It costs one pointer per object — 8 bytes on 64-bit systems. For a class you're instantiating millions of times, or mapping directly onto hardware registers, that matters.

---

## 8. Putting It Together — `SensorBuffer` Class

Full implementation combining everything from today:

```cpp
// sensor_buffer.hpp
#pragma once
#include <cstddef>
#include <cstdint>
#include <cstring>
#include <cstdio>
#include <cassert>
#include <stdexcept>

class SensorBuffer {
public:
    // Default: 64-element buffer
    SensorBuffer()
        : SensorBuffer(64)
    {}

    // Primary constructor — allocates on heap
    explicit SensorBuffer(size_t capacity)
        : data_(new float[capacity])
        , size_(0)
        , capacity_(capacity)
    {
        assert(capacity > 0);
    }

    // Construct from existing data — copies in
    SensorBuffer(const float* src, size_t count)
        : SensorBuffer(count)
    {
        std::memcpy(data_, src, count * sizeof(float));
        size_ = count;
    }

    // Destructor — guaranteed cleanup
    ~SensorBuffer() {
        delete[] data_;
        data_ = nullptr;
    }

    // --- Modifiers ---

    bool push(float value) {
        if (size_ >= capacity_) return false;  // full
        data_[size_++] = value;
        return true;
    }

    void clear() {
        size_ = 0;
    }

    // --- Accessors ---

    float get(size_t index) const {
        if (index >= size_) throw std::out_of_range("SensorBuffer: index out of range");
        return data_[index];
    }

    float operator[](size_t index) const {
        assert(index < size_);  // debug check only — no throw
        return data_[index];
    }

    size_t size()     const { return size_; }
    size_t capacity() const { return capacity_; }
    bool   empty()    const { return size_ == 0; }
    bool   full()     const { return size_ == capacity_; }

    // --- Computed ---

    float average() const {
        if (size_ == 0) return 0.0f;
        float sum = 0.0f;
        for (size_t i = 0; i < size_; ++i) sum += data_[i];
        return sum / static_cast<float>(size_);
    }

    float min() const {
        assert(size_ > 0);
        float m = data_[0];
        for (size_t i = 1; i < size_; ++i)
            if (data_[i] < m) m = data_[i];
        return m;
    }

    float max() const {
        assert(size_ > 0);
        float m = data_[0];
        for (size_t i = 1; i < size_; ++i)
            if (data_[i] > m) m = data_[i];
        return m;
    }

    void print() const {
        printf("SensorBuffer[%zu/%zu]: ", size_, capacity_);
        for (size_t i = 0; i < size_; ++i)
            printf("%.2f ", data_[i]);
        printf("\n");
        if (size_ > 0)
            printf("  avg=%.2f min=%.2f max=%.2f\n", average(), min(), max());
    }

private:
    float*  data_;
    size_t  size_;
    size_t  capacity_;
};
```

```cpp
// main.cpp
#include "sensor_buffer.hpp"

void demonstrate_automatic_cleanup() {
    printf("--- entering scope ---\n");
    SensorBuffer buf(4);       // constructor: allocates 4 floats
    buf.push(23.5f);
    buf.push(24.1f);
    buf.push(22.8f);
    buf.print();
    printf("--- leaving scope ---\n");
}   // destructor: frees memory automatically

int main() {
    // Basic usage
    SensorBuffer readings(8);
    readings.push(23.5f);
    readings.push(24.1f);
    readings.push(22.8f);
    readings.push(21.9f);
    readings.print();

    // Construct from array
    float init_data[] = {10.0f, 20.0f, 30.0f};
    SensorBuffer from_array(init_data, 3);
    from_array.print();

    // Default constructor
    SensorBuffer default_buf;
    printf("Default capacity: %zu\n", default_buf.capacity());

    // Bounds checking
    try {
        float val = readings.get(999);
        (void)val;
    } catch (const std::out_of_range& e) {
        printf("Caught expected exception: %s\n", e.what());
    }

    // Full buffer
    SensorBuffer tiny(2);
    printf("push 1: %s\n", tiny.push(1.0f) ? "ok" : "full");
    printf("push 2: %s\n", tiny.push(2.0f) ? "ok" : "full");
    printf("push 3: %s\n", tiny.push(3.0f) ? "ok" : "full");  // returns false

    // Automatic cleanup
    demonstrate_automatic_cleanup();

    printf("main done — all SensorBuffers destroyed\n");
    return 0;
}
```

Compile and run under AddressSanitizer to verify no leaks:

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address -o sensor_buf main.cpp
./sensor_buf
```

AddressSanitizer will report any leak, use-after-free, or double-free. A clean run confirms the destructor is working correctly.

---

## 9. What's Still Missing — The Copy Problem

Run this and watch it crash:

```cpp
SensorBuffer a(4);
a.push(23.5f);

SensorBuffer b = a;    // copy — what actually happens?
// b.data_ and a.data_ now point to the SAME heap allocation

// When both go out of scope:
// ~SensorBuffer() for b: delete[] data_  — first delete, fine
// ~SensorBuffer() for a: delete[] data_  — DOUBLE FREE — undefined behavior
```

This is the exact problem that the Rule of Three (Day 8) solves. Right now, `SensorBuffer` is dangerous to copy because the compiler-generated copy constructor just copies the pointer — it doesn't allocate a new buffer. Tomorrow we add RAII thinking; Day 8 we fix the copy and move problem completely.

For now, you can prevent copying entirely:

```cpp
class SensorBuffer {
public:
    SensorBuffer(const SensorBuffer&)            = delete;  // no copying
    SensorBuffer& operator=(const SensorBuffer&) = delete;  // no copy assignment
    // ...
};

SensorBuffer a(4);
SensorBuffer b = a;   // compile error — copying disabled
```

`= delete` on the copy constructor and copy assignment operator tells the compiler: "this operation does not exist." The error happens at compile time, not as a crash at runtime.

---

## Key Takeaways for Day 6

- The constructor's job is to establish the invariant — the object is fully valid when the constructor returns
- The member initializer list is how members are initialized, not the constructor body — use it for every member, always in declaration order
- `explicit` on single-argument constructors prevents silent implicit conversions — use it by default
- The destructor runs automatically on scope exit — this is the foundation of RAII
- Members with raw resources (pointers, fds) need a destructor; members without them usually don't
- The compiler-generated copy constructor does a shallow copy — dangerous when members are heap pointers
- `= delete` disables copy/move operations at compile time — the right temporary fix until Day 8

Day 7 takes this foundation and builds RAII properly — wrapping file descriptors, socket handles, and locks in classes that can never leak, no matter how the code exits.