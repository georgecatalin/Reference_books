

Day 8 showed you move constructors and move assignment as part of the Rule of Five — you wrote them to avoid unnecessary copies. Today we go to the root: what lvalues and rvalues actually are, what `std::move` actually does (spoiler: nothing at runtime), why move semantics exist at all, and how `std::forward` solves the perfect forwarding problem that shows up constantly in template code. This is the topic where C++ veterans say "it finally clicked" — once it does, all the `&&` syntax you see in library code becomes readable.

---

## 1. The Problem Move Semantics Solves

Before C++11, returning a large object from a function meant copying it:

```cpp
std::vector<SensorReading> load_readings() {
    std::vector<SensorReading> result;
    result.reserve(10000);
    // ... fill result ...
    return result;   // C++03: copies 10000 elements — expensive
}

auto readings = load_readings();  // copy constructor called
```

The function built a vector, then copied it into the caller's variable, then destroyed the original. For a 10,000-element vector that's 10,000 copy constructions and a heap deallocation — for no reason, because the source is about to be destroyed anyway.

Move semantics solves this: instead of copying the data, steal the heap pointer. The source becomes empty, the destination has the data. Zero allocation, zero element construction, constant time regardless of size.

---

## 2. Lvalues and Rvalues — What They Actually Mean

Every expression in C++ is either an lvalue or an rvalue. The names are historical (left/right side of assignment) but the real meaning is:

**Lvalue** — has an identity, has a persistent address, can appear on the left side of assignment. You can take its address with `&`. It sticks around after the expression.

**Rvalue** — temporary, no persistent address, about to be destroyed. Can't take its address. After the expression it's gone.

```cpp
int x = 42;
int y = x;

x;          // lvalue — has address, persists
42;         // rvalue — temporary literal, no address
x + 1;      // rvalue — temporary result, gone after the expression
std::string("hello");  // rvalue — temporary object

int* p = &x;    // fine — lvalue has address
int* q = &42;   // error — can't take address of rvalue
```

Functions return rvalues (usually). Named variables are lvalues. The result of most operators is an rvalue.

### Lvalue References and Rvalue References

C++ has two reference types:

```cpp
int x = 42;

int&  lref = x;    // lvalue reference — binds to lvalues
int&& rref = 42;   // rvalue reference — binds to rvalues

int&  bad  = 42;   // error — lvalue reference can't bind to rvalue
int&& bad2 = x;    // error — rvalue reference can't bind to named lvalue
```

An rvalue reference `T&&` is how you express "I'm going to steal from this." It binds to things that are about to be destroyed — temporaries, function return values, things explicitly cast to rvalue.

A `const T&` also binds to rvalues (it's how C++03 passed temporaries), but you can't steal from a const — you can only read it.

---

## 3. `std::move` — It Does Nothing at Runtime

This is the biggest misconception. `std::move` does not move anything. It's a cast:

```cpp
// The entire implementation of std::move:
template<typename T>
std::remove_reference_t<T>&& move(T&& t) noexcept {
    return static_cast<std::remove_reference_t<T>&&>(t);
}
```

`std::move(x)` casts `x` from lvalue to rvalue reference. That's it. No data is moved. No memory is touched. Zero instructions generated.

The move happens when the move constructor or move assignment operator is subsequently called on that rvalue reference. `std::move` just says "treat this as a temporary — it's okay to steal from it."

```cpp
std::string a = "hello world, this is a long string";
std::string b = std::move(a);  // cast a to rvalue, then calls move constructor

// After this:
// b owns the string data — the heap allocation transferred
// a is in a valid but unspecified state — probably empty string
// Zero allocation, zero copying
```

The move constructor for `std::string`:

1. Takes the heap pointer from `a`
2. Sets `a`'s pointer to null (or empty)
3. Assigns that pointer to `b`

One pointer assignment. That's the "move."

### What's Left After Moving

A moved-from object is in a **valid but unspecified state**. You can safely assign to it or destroy it. You cannot safely use it for anything else without re-assigning:

```cpp
std::vector<int> v = {1, 2, 3, 4, 5};
std::vector<int> w = std::move(v);

// v is now valid but unspecified — likely empty, but not guaranteed
v.size();         // probably 0, don't count on it
v.push_back(99);  // safe — assignment to moved-from is always safe
v.clear();        // safe — clearing a moved-from is safe
```

---

## 4. Move Constructor and Move Assignment — How They Work

For your own types, the move constructor steals the resource and leaves the source in a null state:

```cpp
class SensorBuffer {
    float*  data_;
    size_t  size_;
    size_t  capacity_;

public:
    // Move constructor — steal the pointer
    SensorBuffer(SensorBuffer&& other) noexcept
        : data_    (other.data_)       // take the pointer
        , size_    (other.size_)
        , capacity_(other.capacity_)
    {
        other.data_     = nullptr;     // leave source in null state
        other.size_     = 0;           // destructor checks nullptr before delete
        other.capacity_ = 0;
    }

    // Move assignment — release current resource, steal from source
    SensorBuffer& operator=(SensorBuffer&& other) noexcept {
        if (this != &other) {
            delete[] data_;            // release what we currently own

            data_     = other.data_;   // steal
            size_     = other.size_;
            capacity_ = other.capacity_;

            other.data_     = nullptr; // null the source
            other.size_     = 0;
            other.capacity_ = 0;
        }
        return *this;
    }

    ~SensorBuffer() {
        delete[] data_;   // safe: delete nullptr is a no-op
    }
};
```

The `noexcept` on move operations is load-bearing — as covered on Day 8, `std::vector` uses the move constructor during reallocation only if it's `noexcept`. Without it, vector falls back to copying.

---

## 5. When the Compiler Moves Automatically — NRVO and RVO

You don't always need `std::move`. The compiler moves (or elides entirely) in two important cases:

### Return Value Optimization (RVO) and Named RVO (NRVO)

When you return a local variable from a function, the compiler constructs it directly in the caller's storage — no copy, no move. This is guaranteed in C++17 for unnamed temporaries (RVO), and applied in practice for named returns (NRVO):

```cpp
std::vector<float> build_readings() {
    std::vector<float> result;   // NRVO: constructed directly in caller's space
    result.reserve(1000);
    for (int i = 0; i < 1000; ++i) result.push_back(static_cast<float>(i));
    return result;               // no copy, no move — elided entirely (NRVO)
}

auto readings = build_readings();  // readings IS result — same memory
```

**Don't `std::move` return values.** It actually prevents NRVO:

```cpp
std::vector<float> bad_return() {
    std::vector<float> result;
    return std::move(result);   // WRONG — disables NRVO, forces move instead
}                                // of elision — move is worse than elision

std::vector<float> good_return() {
    std::vector<float> result;
    return result;              // CORRECT — NRVO applies, zero copies or moves
}
```

Return local variables by name, without `std::move`. The compiler handles it.

### Implicit Move on Return

If NRVO doesn't apply (multiple return paths returning different objects), the compiler implicitly moves rather than copies:

```cpp
std::vector<float> conditional_return(bool flag) {
    std::vector<float> a, b;
    // fill a and b...
    if (flag) return a;    // implicit move — not copy
    return b;              // implicit move — not copy
}
```

---

## 6. Universal References — `T&&` in Templates

Here's where confusion often strikes. `T&&` means different things depending on context:

```cpp
void f(int&& x);           // rvalue reference — only binds to rvalues

template<typename T>
void g(T&& x);             // universal reference — binds to ANYTHING
```

When `T&&` appears in a template where `T` is deduced, it's a **universal reference** (also called forwarding reference). It binds to both lvalues and rvalues through reference collapsing:

- If you pass an lvalue `int&`, `T` deduces to `int&`, and `T&&` becomes `int& &&` → collapsed to `int&`
- If you pass an rvalue `int&&`, `T` deduces to `int`, and `T&&` becomes `int&&`

```cpp
template<typename T>
void inspect(T&& x) {
    if constexpr (std::is_lvalue_reference_v<T>) {
        printf("lvalue reference\n");
    } else {
        printf("rvalue reference\n");
    }
}

int a = 5;
inspect(a);        // T = int& → lvalue reference
inspect(5);        // T = int  → rvalue reference
inspect(std::move(a));  // T = int → rvalue reference
```

Universal references are the mechanism that makes perfect forwarding possible.

---

## 7. `std::forward` — Perfect Forwarding

The problem: you have a template function that receives an argument and passes it to another function. You want to preserve whether the original call was with an lvalue or rvalue. Without `std::forward`, everything becomes an lvalue inside the function (because named parameters are always lvalues):

```cpp
template<typename T>
void wrapper_bad(T&& arg) {
    // arg is named — it's an lvalue here, even if an rvalue was passed
    target(arg);   // always passes as lvalue — loses rvalue-ness
}

template<typename T>
void wrapper_good(T&& arg) {
    target(std::forward<T>(arg));   // preserves lvalue/rvalue category
}

std::string s = "hello";
wrapper_good(s);             // passes as lvalue — target sees lvalue ref
wrapper_good(std::string("hello"));  // passes as rvalue — target can move
```

`std::forward<T>(arg)` is a conditional cast:

- If `T` is an lvalue reference type (deduced from lvalue input) → cast to lvalue reference — pass as lvalue
- If `T` is a non-reference type (deduced from rvalue input) → cast to rvalue reference — pass as rvalue

```cpp
// The implementation of std::forward:
template<typename T>
T&& forward(std::remove_reference_t<T>& t) noexcept {
    return static_cast<T&&>(t);
}
```

`std::forward` only makes sense inside a template function with a universal reference. Elsewhere it's meaningless.

### The Canonical Forwarding Pattern

```cpp
// Construct T from forwarded arguments — the standard library does this constantly
template<typename T, typename... Args>
std::unique_ptr<T> make_unique(Args&&... args) {
    return std::unique_ptr<T>(new T(std::forward<Args>(args)...));
}

// Your own factory:
template<typename SensorT, typename... Args>
std::unique_ptr<Sensor> make_sensor(Args&&... args) {
    return std::make_unique<SensorT>(std::forward<Args>(args)...);
}

// Usage — args are forwarded as-is, no unnecessary copies
auto temp = make_sensor<TemperatureSensor>(0, 1.02f);
auto humid = make_sensor<HumiditySensor>(0x40);
```

---

## 8. Move Semantics in Practice — Common Patterns

### Moving into containers

```cpp
std::vector<std::string> topics;

std::string topic = "sensors/temperature/device_01";
topics.push_back(std::move(topic));  // move into vector — no copy
// topic is now in unspecified state — don't use it

// Or use emplace_back — constructs directly, no move needed
topics.emplace_back("sensors/humidity/device_02");
```

### Moving out of functions

```cpp
// Factory functions — always return by value, NRVO handles it
DeviceConfig make_default_config() {
    DeviceConfig cfg;
    cfg.baud_rate  = 115200;
    cfg.timeout_ms = 1000;
    return cfg;  // NRVO — constructed in caller's space
}
```

### Moving unique_ptr

`std::unique_ptr` can't be copied — it must be moved:

```cpp
std::unique_ptr<Sensor> create_sensor() {
    return std::make_unique<TemperatureSensor>(0);  // move out of function
}

std::vector<std::unique_ptr<Sensor>> sensors;
sensors.push_back(create_sensor());  // move into vector

// Transfer ownership between containers
auto sensor = std::move(sensors[0]);  // move out of vector
sensors[0] = nullptr;                  // vector slot is now null
```

### Sink parameters — take by value, then move

When a function needs to store a parameter and owns it afterward, take by value:

```cpp
class DataLogger {
    std::string path_;
    std::vector<SensorReading> buffer_;
public:
    // Takes path by value — caller can pass lvalue (copy) or rvalue (move)
    explicit DataLogger(std::string path)  // by value, not const ref
        : path_(std::move(path))           // move into member
    {}

    void store(std::vector<SensorReading> readings) {  // by value
        buffer_ = std::move(readings);                  // move into member
    }
};

std::string p = "/var/log/sensors.bin";
DataLogger logger(p);                    // copies p into DataLogger
DataLogger logger2(std::move(p));        // moves p into DataLogger
DataLogger logger3("/var/log/other");    // temporary — moved in, no copy
```

This pattern ("sink parameter") lets the caller decide: pass an lvalue if you need to keep it, pass an rvalue (or temporary) if you don't. One parameter type handles both cases optimally.

---

## 9. Putting It Together — Move-Aware Message Queue

Full exercise combining move semantics, perfect forwarding, and real ownership patterns:

```cpp
// message_queue.cpp
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <string>
#include <vector>
#include <optional>
#include <memory>
#include <cassert>
#include <utility>

// ---- Message type — owns its payload ----

struct MQTTMessage {
    std::string topic;
    std::vector<uint8_t> payload;
    uint8_t  qos;
    bool     retain;

    // Default constructor
    MQTTMessage() : qos(0), retain(false) {}

    // Primary constructor
    MQTTMessage(std::string topic,
                std::vector<uint8_t> payload,
                uint8_t qos = 0,
                bool retain = false)
        : topic  (std::move(topic))    // move in — no copy
        , payload(std::move(payload))  // move in — no copy
        , qos    (qos)
        , retain (retain)
    {}

    // Rule of Zero — compiler generates correct copy/move
    // std::string and std::vector handle their own memory

    void print() const {
        printf("  MQTT{topic='%s' qos=%u retain=%d payload=%zu bytes}\n",
               topic.c_str(), qos, retain, payload.size());
    }

    size_t byte_size() const {
        return topic.size() + payload.size() + sizeof(qos) + sizeof(retain);
    }
};

// ---- Move-aware bounded queue ----

template<typename T>
class BoundedQueue {
public:
    explicit BoundedQueue(size_t max_size) : max_size_(max_size) {
        buffer_.reserve(max_size);
    }

    // Push by copy
    bool push(const T& item) {
        if (buffer_.size() >= max_size_) return false;
        buffer_.push_back(item);   // copy
        return true;
    }

    // Push by move — no copy
    bool push(T&& item) {
        if (buffer_.size() >= max_size_) return false;
        buffer_.push_back(std::move(item));  // move
        return true;
    }

    // Perfect forwarding emplace — construct in-place
    template<typename... Args>
    bool emplace(Args&&... args) {
        if (buffer_.size() >= max_size_) return false;
        buffer_.emplace_back(std::forward<Args>(args)...);
        return true;
    }

    // Pop — move out of the queue
    std::optional<T> pop() {
        if (buffer_.empty()) return std::nullopt;
        T item = std::move(buffer_.front());   // move out
        buffer_.erase(buffer_.begin());        // O(n) — acceptable for small queues
        return item;
    }

    // Pop batch — move multiple items out
    std::vector<T> pop_batch(size_t count) {
        std::vector<T> batch;
        size_t n = std::min(count, buffer_.size());
        batch.reserve(n);

        for (size_t i = 0; i < n; ++i) {
            batch.push_back(std::move(buffer_[i]));
        }
        buffer_.erase(buffer_.begin(),
                      buffer_.begin() + static_cast<ptrdiff_t>(n));
        return batch;  // NRVO — no copy
    }

    const T* peek() const {
        return buffer_.empty() ? nullptr : &buffer_.front();
    }

    size_t size()     const { return buffer_.size(); }
    size_t capacity() const { return max_size_; }
    bool   empty()    const { return buffer_.empty(); }
    bool   full()     const { return buffer_.size() >= max_size_; }

private:
    std::vector<T> buffer_;
    size_t         max_size_;
};

// ---- Perfect forwarding factory ----

template<typename SensorT, typename... Args>
std::unique_ptr<SensorT> make_sensor(Args&&... args) {
    printf("  [factory] creating %s\n", typeid(SensorT).name());
    return std::make_unique<SensorT>(std::forward<Args>(args)...);
}

// ---- Copy counter — instruments copy vs move ----

struct CopyCounter {
    int id;
    static inline int copy_count = 0;
    static inline int move_count = 0;

    explicit CopyCounter(int id) : id(id) {}

    CopyCounter(const CopyCounter& o) : id(o.id) {
        ++copy_count;
        printf("    copy #%d (total copies: %d)\n", id, copy_count);
    }
    CopyCounter& operator=(const CopyCounter& o) {
        id = o.id; ++copy_count;
        printf("    copy= #%d\n", id);
        return *this;
    }
    CopyCounter(CopyCounter&& o) noexcept : id(o.id) {
        o.id = -1; ++move_count;
        printf("    move #%d (total moves: %d)\n", id, move_count);
    }
    CopyCounter& operator=(CopyCounter&& o) noexcept {
        id = o.id; o.id = -1; ++move_count;
        printf("    move= #%d\n", id);
        return *this;
    }
};

int main() {
    printf("=== Move Semantics Demo ===\n\n");

    // ---- Message construction — moves vs copies ----
    printf("--- Message construction ---\n");
    std::string topic = "sensors/temperature/device_01";
    std::vector<uint8_t> payload = {0x9A, 0x99, 0xBB, 0x41};

    printf("Constructing with move:\n");
    MQTTMessage msg1(std::move(topic), std::move(payload));
    msg1.print();
    printf("  topic after move: '%s'\n", topic.c_str());   // unspecified — likely empty
    printf("  payload size after move: %zu\n", payload.size());

    printf("\nConstructing with copy:\n");
    std::string topic2   = "sensors/humidity";
    std::vector<uint8_t> payload2 = {0x00, 0x41};
    MQTTMessage msg2(topic2, payload2);  // copies
    msg2.print();
    printf("  topic2 still valid: '%s'\n", topic2.c_str());  // unchanged

    // ---- BoundedQueue — move semantics ----
    printf("\n--- BoundedQueue<MQTTMessage> ---\n");
    BoundedQueue<MQTTMessage> q(4);

    // Push by move — payload not copied
    q.push(std::move(msg1));
    printf("  pushed msg1 by move\n");

    // Emplace — construct directly in queue
    q.emplace("sensors/pressure", std::vector<uint8_t>{0x01, 0x02, 0x03}, 1, false);
    printf("  emplaced message in-place\n");

    // Push temporary — rvalue, moved in
    q.push(MQTTMessage("sensors/status", {0xFF}, 0, true));
    printf("  pushed temporary by move\n");

    printf("  Queue depth: %zu/%zu\n", q.size(), q.capacity());

    // Pop — move out
    printf("\nPopping:\n");
    while (auto m = q.pop()) {
        m->print();
    }

    // ---- CopyCounter — visualize copies vs moves ----
    printf("\n--- Copy vs Move instrumentation ---\n");
    BoundedQueue<CopyCounter> cq(8);

    printf("push lvalue (copy):\n");
    CopyCounter c1(1);
    cq.push(c1);   // copy

    printf("push rvalue (move):\n");
    cq.push(CopyCounter(2));  // move

    printf("push with std::move (move):\n");
    CopyCounter c3(3);
    cq.push(std::move(c3));   // move

    printf("emplace (no copy/move — constructed in-place):\n");
    cq.emplace(4);            // constructed in-place — no copy, no move

    printf("\nTotal copies: %d  moves: %d\n",
           CopyCounter::copy_count, CopyCounter::move_count);

    // Pop batch — moves items out
    printf("\npop_batch(3):\n");
    auto batch = cq.pop_batch(3);
    for (const auto& c : batch) printf("  got #%d\n", c.id);

    // ---- Don't move return values ----
    printf("\n--- NRVO demonstration ---\n");
    auto make_messages = []() {
        std::vector<MQTTMessage> result;
        result.emplace_back("t/1", std::vector<uint8_t>{1});
        result.emplace_back("t/2", std::vector<uint8_t>{2});
        result.emplace_back("t/3", std::vector<uint8_t>{3});
        return result;   // NRVO — not std::move(result)
    };

    auto messages = make_messages();  // NRVO applies — zero copies
    printf("Received %zu messages via NRVO\n", messages.size());

    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address -o move_demo message_queue.cpp
./move_demo
```

### What to observe

`CopyCounter` makes the copy/move count visible. The `push(c1)` (lvalue) triggers a copy. The `push(CopyCounter(2))` (temporary) triggers a move. The `emplace(4)` triggers neither — the object is constructed directly in the vector. This is the hierarchy of efficiency: copy > move > emplace.

The `pop_batch` return uses NRVO — no copy or move of the vector itself, even though it's returning a local variable.

After `push(std::move(c3))`, `c3.id` is `-1` — the moved-from state the move constructor sets. Don't use `c3` after moving from it.

---

## Key Takeaways for Day 15

- Lvalues have identity and persist — rvalues are temporaries about to be destroyed. Rvalue references (`T&&`) bind to rvalues and express "I can steal from this"
- `std::move` is a cast, not an operation — it produces an rvalue reference, enabling the move constructor/assignment to be called. Zero instructions at runtime
- Move constructors steal the resource (pointer, fd, handle) and leave the source in a valid-but-empty state — the destructor must handle that state safely
- `noexcept` on move operations enables `std::vector` to move elements during reallocation — without it, vector falls back to copying
- Don't `std::move` return values — NRVO elides the operation entirely. Moving a return value prevents elision and forces a move where nothing was needed
- Universal references (`T&&` in template context) bind to both lvalues and rvalues via reference collapsing — `&&` in a template doesn't mean rvalue-only
- `std::forward<T>` preserves the value category through a template — pass lvalues as lvalues, rvalues as rvalues. Only meaningful in template functions with universal references
- Sink parameters (take by value, then move into member) let callers choose: pass lvalue to copy, pass rvalue to move — one parameter type handles both

Day 16 covers error handling: when to use exceptions, when they're unavailable (embedded targets with `-fno-exceptions`), `std::optional` for nullable returns, and `std::expected` for error-carrying results — the right tool for each context.