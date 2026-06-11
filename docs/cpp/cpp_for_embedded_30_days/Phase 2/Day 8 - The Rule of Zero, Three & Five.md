
Day 6 ended with a ticking time bomb — `SensorBuffer` had a destructor but no copy constructor, meaning the compiler generated a shallow copy that double-frees. Day 7 showed you RAII and why destructors matter. Today we close the loop: when you define any of the five special member functions, you need to think about all five. This is the Rule of Three/Five, and getting it right is the difference between a class that works and one that corrupts memory silently.

---

## 1. The Five Special Member Functions

C++ has five operations the compiler can generate automatically:

```cpp
class Foo {
public:
    Foo();                             // 1. Default constructor
    ~Foo();                            // 2. Destructor
    Foo(const Foo& other);             // 3. Copy constructor
    Foo& operator=(const Foo& other);  // 4. Copy assignment operator
    Foo(Foo&& other) noexcept;         // 5. Move constructor
    Foo& operator=(Foo&& other) noexcept; // 6. Move assignment operator
};
```

Six, actually — the default constructor makes it six. But the "Rule of Five" covers the five that interact: destructor, copy constructor, copy assignment, move constructor, move assignment.

---

## 2. What the Compiler Generates by Default

If you write nothing, the compiler generates all five — and they do the obvious thing: memberwise copy/move/destroy:

```cpp
struct Reading {
    float   value;
    int32_t timestamp;
};

Reading a{23.5f, 1000};
Reading b = a;              // compiler-generated copy: copies value and timestamp
Reading c = std::move(a);   // compiler-generated move: same as copy for plain types
```

For classes with only value members (`int`, `float`, `std::string`, `std::vector`), the compiler-generated versions are correct. This is the **Rule of Zero** — the best rule:

> If you can design your class so that the compiler-generated special members do the right thing, do that.

The compiler's generated copy/move/destroy does memberwise operations. For `std::string` and `std::vector` members, "memberwise copy" means calling their copy constructors, which do deep copies. So this just works:

```cpp
class DeviceConfig {
    std::string   device_name;   // std::string manages its own memory
    uint32_t      baud_rate;
    std::vector<uint8_t> init_sequence;  // std::vector manages its own memory
    // No destructor, no copy/move — compiler-generated versions are correct
};

DeviceConfig a{"ttyUSB0", 9600, {0x01, 0x02}};
DeviceConfig b = a;   // deep copy — string and vector are properly copied
```

**Rule of Zero in one sentence:** use standard library types as members, write no special members, get correct behavior for free.

---

## 3. When the Rule of Zero Breaks Down

The Rule of Zero breaks the moment you have a raw resource — a raw pointer, a file descriptor, a socket. The compiler doesn't know what "copy" means for your resource.

```cpp
class SensorBuffer {
    float*  data_;      // raw pointer — compiler doesn't know the size
    size_t  size_;
    size_t  capacity_;
public:
    explicit SensorBuffer(size_t cap)
        : data_(new float[cap]), size_(0), capacity_(cap) {}

    ~SensorBuffer() { delete[] data_; }

    // Compiler generates:
    // SensorBuffer(const SensorBuffer& o)
    //     : data_(o.data_)        <- copies the POINTER, not the data
    //     , size_(o.size_)
    //     , capacity_(o.capacity_) {}
};

SensorBuffer a(64);
SensorBuffer b = a;   // b.data_ == a.data_ — same allocation
// When both are destroyed: double free — UB
```

This is why defining a destructor triggers the Rule of Three.

---

## 4. The Rule of Three

> If you define any of: destructor, copy constructor, copy assignment — you almost certainly need to define all three.

The reasoning: if you wrote a destructor, you're managing a resource manually. If you're managing a resource manually, the compiler-generated copy does the wrong thing (shallow copy). So you must also write the copy constructor and copy assignment.

```cpp
class SensorBuffer {
public:
    explicit SensorBuffer(size_t cap)
        : data_(new float[cap])
        , size_(0)
        , capacity_(cap)
    {}

    // --- Rule of Three ---

    // 1. Destructor
    ~SensorBuffer() {
        delete[] data_;
    }

    // 2. Copy constructor — deep copy
    SensorBuffer(const SensorBuffer& other)
        : data_(new float[other.capacity_])
        , size_(other.size_)
        , capacity_(other.capacity_)
    {
        std::memcpy(data_, other.data_, size_ * sizeof(float));
    }

    // 3. Copy assignment operator
    SensorBuffer& operator=(const SensorBuffer& other) {
        if (this == &other) return *this;   // self-assignment guard

        // Allocate new buffer first — if it throws, *this is still valid
        float* new_data = new float[other.capacity_];
        std::memcpy(new_data, other.data_, other.size_ * sizeof(float));

        // Now swap in the new data — non-throwing from here
        delete[] data_;
        data_     = new_data;
        size_     = other.size_;
        capacity_ = other.capacity_;

        return *this;
    }

private:
    float*  data_;
    size_t  size_;
    size_t  capacity_;
};
```

### The Self-Assignment Guard

```cpp
SensorBuffer a(64);
a = a;   // self-assignment — must handle correctly
```

Without the guard, copy assignment would `delete[] data_` and then try to `memcpy` from the deleted memory. The guard `if (this == &other) return *this` short-circuits this case.

### Copy-and-Swap — The Exception-Safe Alternative

There's a cleaner idiom for copy assignment that is automatically exception-safe:

```cpp
// Add a swap member function
void swap(SensorBuffer& other) noexcept {
    std::swap(data_,     other.data_);
    std::swap(size_,     other.size_);
    std::swap(capacity_, other.capacity_);
}

// Copy assignment using copy-and-swap
SensorBuffer& operator=(SensorBuffer other) {  // takes by VALUE — invokes copy constructor
    swap(other);   // swap *this with the copy
    return *this;
    // other goes out of scope here — its destructor frees the OLD data_
}
```

The parameter `SensorBuffer other` is taken by value — that invokes the copy constructor to make a copy. Then we swap our internals with the copy. When `other` goes out of scope, its destructor deletes what used to be our old data. Exception safety is automatic: if the copy constructor throws, we never reach the swap, so `*this` is unchanged.

---

## 5. The Rule of Five

C++11 added move semantics. Moving is cheaper than copying for types that own resources — instead of allocating new memory and copying data, you steal the resource from the source.

> If you define any of the five special members, think about all five.

Add move constructor and move assignment to complete the picture:

```cpp
class SensorBuffer {
public:
    explicit SensorBuffer(size_t cap)
        : data_(new float[cap])
        , size_(0)
        , capacity_(cap)
    {}

    // --- Rule of Five ---

    // 1. Destructor
    ~SensorBuffer() {
        delete[] data_;
    }

    // 2. Copy constructor
    SensorBuffer(const SensorBuffer& other)
        : data_(new float[other.capacity_])
        , size_(other.size_)
        , capacity_(other.capacity_)
    {
        std::memcpy(data_, other.data_, size_ * sizeof(float));
    }

    // 3. Copy assignment
    SensorBuffer& operator=(SensorBuffer other) {  // copy-and-swap
        swap(other);
        return *this;
    }

    // 4. Move constructor
    SensorBuffer(SensorBuffer&& other) noexcept
        : data_(other.data_)          // steal the pointer
        , size_(other.size_)
        , capacity_(other.capacity_)
    {
        other.data_     = nullptr;    // source no longer owns it
        other.size_     = 0;
        other.capacity_ = 0;
    }

    // 5. Move assignment
    SensorBuffer& operator=(SensorBuffer&& other) noexcept {
        if (this != &other) {
            delete[] data_;           // free current resource

            data_     = other.data_;  // steal
            size_     = other.size_;
            capacity_ = other.capacity_;

            other.data_     = nullptr; // leave source valid but empty
            other.size_     = 0;
            other.capacity_ = 0;
        }
        return *this;
    }

    // --- Operations ---

    bool push(float value) {
        if (size_ >= capacity_) return false;
        data_[size_++] = value;
        return true;
    }

    float operator[](size_t i) const { return data_[i]; }
    size_t size()     const { return size_; }
    size_t capacity() const { return capacity_; }
    bool   empty()    const { return size_ == 0; }

    void swap(SensorBuffer& other) noexcept {
        std::swap(data_,     other.data_);
        std::swap(size_,     other.size_);
        std::swap(capacity_, other.capacity_);
    }

    void print() const {
        printf("SensorBuffer[%zu/%zu]:", size_, capacity_);
        for (size_t i = 0; i < size_; ++i) printf(" %.1f", data_[i]);
        printf("\n");
    }

private:
    float*  data_;
    size_t  size_;
    size_t  capacity_;
};
```

### Why `noexcept` on Move Operations

Move constructors and move assignments should be `noexcept` whenever possible. Here's why it matters practically:

```cpp
std::vector<SensorBuffer> vec;
vec.push_back(SensorBuffer(64));
```

When `std::vector` needs to grow, it moves its elements into the new allocation — but only if the move constructor is `noexcept`. If it might throw, `vector` falls back to copying (to preserve the strong exception guarantee). Mark move operations `noexcept` when they genuinely can't throw — pointer swaps never throw.

---

## 6. `= default` and `= delete` — Being Explicit

Rather than writing the full implementation, you can ask the compiler to generate or suppress operations explicitly:

```cpp
class Sensor {
public:
    Sensor() = default;                            // generate default constructor
    ~Sensor() = default;                           // generate destructor

    Sensor(const Sensor&) = default;               // generate copy constructor
    Sensor& operator=(const Sensor&) = default;    // generate copy assignment

    Sensor(Sensor&&) noexcept = default;           // generate move constructor
    Sensor& operator=(Sensor&&) noexcept = default; // generate move assignment
};
```

`= default` means "generate the compiler's version." This is useful when you've written one special member (which suppresses auto-generation of others) but want the compiler's version for the rest.

`= delete` means "this operation does not exist — compile error if used":

```cpp
class UniqueHandle {
public:
    explicit UniqueHandle(int fd) : fd_(fd) {}
    ~UniqueHandle() { if (fd_ >= 0) close(fd_); }

    // Copying makes no sense — one handle, one owner
    UniqueHandle(const UniqueHandle&)            = delete;
    UniqueHandle& operator=(const UniqueHandle&) = delete;

    // Moving is fine
    UniqueHandle(UniqueHandle&&) noexcept            = default;
    UniqueHandle& operator=(UniqueHandle&&) noexcept = default;

private:
    int fd_;
};

UniqueHandle a(open("/dev/ttyUSB0", O_RDWR));
UniqueHandle b = a;            // compile error — deleted
UniqueHandle c = std::move(a); // fine — move is allowed
```

The error from `= delete` is at compile time, with a clear message. Much better than a runtime crash.

---

## 7. Compiler Generation Rules — The Full Picture

Understanding when the compiler generates what saves debugging time:

|You define|Default ctor|Destructor|Copy ctor|Copy assign|Move ctor|Move assign|
|---|---|---|---|---|---|---|
|Nothing|✓ generated|✓ generated|✓ generated|✓ generated|✓ generated|✓ generated|
|Destructor only|✓|—|✓ (deprecated)|✓ (deprecated)|✗ suppressed|✗ suppressed|
|Copy constructor|✗ suppressed|✓|—|✓ (deprecated)|✗ suppressed|✗ suppressed|
|Move constructor|✗ suppressed|✓|✗ deleted|✗ deleted|—|✗ suppressed|
|Move assignment|✗ suppressed|✓|✗ deleted|✗ deleted|✗ suppressed|—|

Key observations: defining a destructor suppresses move generation (the moves become copies silently — dangerous). Defining a move constructor deletes copy operations. This is why you must be explicit — `= default` or `= delete` — whenever you define any of the five.

---

## 8. Putting It Together — Full Exercise

Write this file, compile under AddressSanitizer, and verify every operation:

```cpp
// rule_of_five.cpp
#include <cstdio>
#include <cstring>
#include <cassert>
#include <utility>
#include <vector>

// ---- SensorBuffer with full Rule of Five ----
// (as written above — paste the full class here)

// ---- Instrumented version to count operations ----
class Instrumented {
public:
    explicit Instrumented(int id) : id_(id) {
        printf("  construct #%d\n", id_);
    }
    ~Instrumented() {
        printf("  destroy   #%d\n", id_);
    }
    Instrumented(const Instrumented& o) : id_(o.id_) {
        printf("  copy      #%d\n", id_);
    }
    Instrumented& operator=(const Instrumented& o) {
        id_ = o.id_;
        printf("  copy=     #%d\n", id_);
        return *this;
    }
    Instrumented(Instrumented&& o) noexcept : id_(o.id_) {
        o.id_ = -1;
        printf("  move      #%d\n", id_);
    }
    Instrumented& operator=(Instrumented&& o) noexcept {
        id_ = o.id_; o.id_ = -1;
        printf("  move=     #%d\n", id_);
        return *this;
    }
    int id() const { return id_; }
private:
    int id_;
};

void test_sensor_buffer() {
    printf("\n=== SensorBuffer Rule of Five ===\n");

    // Construct
    SensorBuffer a(4);
    a.push(1.0f); a.push(2.0f); a.push(3.0f);
    printf("a: "); a.print();

    // Copy construct — deep copy
    SensorBuffer b = a;
    b.push(4.0f);
    printf("a after b.push: "); a.print();  // a unchanged
    printf("b:               "); b.print();

    // Copy assign
    SensorBuffer c(2);
    c = a;                                  // copy assignment
    printf("c after c=a: "); c.print();

    // Move construct — steal a's buffer
    SensorBuffer d = std::move(a);
    printf("d after move from a: "); d.print();
    printf("a after move (empty): size=%zu\n", a.size());

    // Move assign
    SensorBuffer e(1);
    e = std::move(b);
    printf("e after move from b: "); e.print();

    // Self-assign (copy assignment)
    c = c;
    printf("c after self-assign: "); c.print();

    // vector growth — uses move if noexcept
    printf("\n--- vector of SensorBuffers ---\n");
    std::vector<SensorBuffer> vec;
    vec.reserve(3);
    vec.push_back(SensorBuffer(8));
    vec.push_back(SensorBuffer(16));
    vec.push_back(SensorBuffer(32));
    printf("vector has %zu buffers\n", vec.size());
}

void test_instrumented() {
    printf("\n=== Instrumented — watch the operations ===\n");

    std::vector<Instrumented> v;
    v.reserve(4);   // pre-allocate to avoid reallocation moves

    printf("push_back #1:\n");
    v.push_back(Instrumented(1));   // construct + move into vector

    printf("push_back #2:\n");
    v.push_back(Instrumented(2));

    printf("copy vector:\n");
    std::vector<Instrumented> v2 = v;   // copies all elements

    printf("move vector:\n");
    std::vector<Instrumented> v3 = std::move(v);  // moves the buffer, no element ops

    printf("end of test_instrumented:\n");
    // v3, v2 destroyed here
}

int main() {
    test_sensor_buffer();
    test_instrumented();
    printf("\nDone.\n");
    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address,undefined -o r5 rule_of_five.cpp
./r5
```

### What to observe

In `test_instrumented`: `push_back(Instrumented(1))` constructs a temporary, then moves it into the vector — you'll see "construct #1" then "move #1" then "destroy #-1" (the moved-from temporary). Moving the whole vector (`std::move(v)`) produces no individual element operations — the entire buffer pointer is transferred.

Try removing `v.reserve(4)` and watch what happens as the vector grows — elements are moved into the new allocation (if your move constructor is `noexcept`) or copied (if it isn't).

---

## Key Takeaways for Day 8

- Rule of Zero: use standard library types as members, write no special members, get correct semantics for free — this is the goal
- Rule of Three: destructor + copy constructor + copy assignment — define all three whenever you manage a raw resource
- Rule of Five: add move constructor + move assignment — enables efficient transfer without allocation
- Copy-and-swap idiom: take copy by value, swap internals — automatically exception-safe, handles self-assignment
- `noexcept` on move operations is not optional style — `std::vector` and other containers fall back to copying if move might throw
- `= default`: explicitly request the compiler-generated version after you've defined other special members
- `= delete`: make an operation a compile error — prefer this over leaving dangerous operations available
- Defining a destructor suppresses move generation silently — always be explicit with `= default` or `= delete` when you define any of the five

Day 9 covers operator overloading — adding `operator<<`, `operator[]`, and `operator==` to `SensorBuffer` so it behaves like a natural C++ type.