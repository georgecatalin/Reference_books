# C vs C++ — What Actually Changed

Let's start with the right mental model. C++ is **not** "C with classes bolted on." It's a different language that compiles C almost as a subset — but the moment you write C++, you're working with a different compilation model, a different type system, and a different set of guarantees. Understanding exactly where the line is will save you weeks of confusion later.

---

## 1. The Compilation Model

In C, you have `.c` files and `.h` files. C++ has the same structure (`.cpp` and `.h` or `.hpp`), but the compiler does significantly more work.

**Translation units** — this concept exists in C too, but C++ makes it load-bearing. Every `.cpp` file is compiled independently into an object file. The linker then stitches them together. This matters because C++ adds _name mangling_.

### Name Mangling

In C, the function `void send(int x)` produces a symbol called `_send` (or just `send`) in the object file. Simple.

In C++, the same function might produce `_Z4sendi` — the compiler encodes the function name _and_ its parameter types into the symbol. This is how overloading works: two functions named `send` with different parameters become two different linker symbols.

```cpp
// C++ — these are three different linker symbols
void send(int x);         // _Z4sendi
void send(float x);       // _Z4sendf  
void send(int x, int y);  // _Z4sendii
```

This is also why `extern "C"` exists — it tells the C++ compiler to suppress name mangling for a specific function so it's linkable from C code:

```cpp
extern "C" void send(int x);  // symbol becomes _send — C-compatible
```

You'll use this constantly when interfacing with C libraries (hardware HALs, FreeRTOS, lwIP).

---

## 2. Namespaces

C has no namespaces. You work around it with prefixes: `mqtt_connect()`, `mqtt_disconnect()`, `mqtt_publish()`. It works, but it's manual and error-prone.

C++ namespaces are a first-class scoping mechanism:

```cpp
namespace mqtt {
    void connect();
    void disconnect();
    void publish(const char* topic, const char* payload);
}

// Call it:
mqtt::connect();
```

The `::` is the _scope resolution operator_. You'll see it everywhere.

**Nesting is fine:**

```cpp
namespace device {
    namespace sensor {
        float read_temperature();
    }
}

// Usage:
device::sensor::read_temperature();
```

**The `using` declaration** — lets you bring a name into scope without qualifying it every time:

```cpp
using mqtt::publish;   // now you can call publish() directly
using namespace mqtt;  // bring everything in — use sparingly
```

`using namespace std;` is the one you'll see in tutorials everywhere. In production code, avoid it in header files — it pollutes every file that includes the header.

---

## 3. References — Not Just Fancy Pointers

This is one of the most important conceptual shifts from C. C has pointers. C++ has pointers _and_ references.

A reference is an alias — another name for an existing object. It is not a separate variable. It cannot be null. It cannot be reseated to point at something else after initialization.

```cpp
int x = 42;
int& ref = x;   // ref IS x — same memory location

ref = 100;
// x is now 100 — you changed x through ref

int* ptr = &x;  // pointer to x — this is C-style
*ptr = 200;     // x is now 200
```

**When to use each:**

|Situation|Use|
|---|---|
|Parameter that must not be null, won't be reseated|Reference|
|Parameter that might be null (optional input)|Pointer|
|Returning multiple values|Reference parameters or struct|
|Storing a thing that can change what it points to|Pointer|
|Iterating / pointer arithmetic|Pointer|

The most common use of references is function parameters:

```cpp
// C style — caller must dereference manually
void process(SensorReading* r) {
    r->value = 0;
}

// C++ style — cleaner, communicates "this cannot be null"
void process(SensorReading& r) {
    r.value = 0;   // no dereference syntax needed
}
```

### const References — the most important idiom in C++

Passing a large struct by value copies it. Passing by pointer works but implies optional/nullable. The idiomatic solution is `const&`:

```cpp
// Copies the whole struct — expensive for large types
void log_reading(SensorReading r);

// Passes a pointer, no copy — but allows null, allows mutation
void log_reading(SensorReading* r);

// Passes by reference, no copy, no null, no mutation — correct
void log_reading(const SensorReading& r);
```

`const SensorReading&` is the default way to pass any non-trivial type you don't need to modify. Internalize this immediately.

---

## 4. `const` Correctness

C has `const`, but C++ makes it a design discipline. The rule is simple: **if something shouldn't change, say so with `const`.** The compiler enforces it.

```cpp
const int MAX_DEVICES = 32;     // can't be modified

const char* name = "sensor_1";  // pointer to const data — data can't change
char* const ptr = buffer;       // const pointer — can't point elsewhere
const char* const both = "x";   // neither can change

void read(const SensorConfig& cfg) {
    // cfg.threshold = 5;  // compiler error — cfg is const
}
```

**`const` member functions** — for class methods (Day 6), you'll mark functions `const` to promise they don't modify the object:

```cpp
class Sensor {
    float last_reading;
public:
    float get() const { return last_reading; }  // won't modify Sensor
    void update(float v) { last_reading = v; }  // will modify Sensor
};
```

The key habit: start with `const` everywhere, and remove it only when you have a reason.

---

## 5. `nullptr` vs `NULL` vs `0`

In C, `NULL` is typically defined as `(void*)0` or just `0`. These are the same thing to the C compiler. In C++, they aren't — and this causes subtle bugs.

```cpp
void process(int x);
void process(char* p);

process(NULL);     // ambiguous — is this int 0 or char* null? Compiler may warn or error
process(nullptr);  // unambiguous — this is a null pointer
process(0);        // calls process(int) — definitely not what you meant
```

`nullptr` is a keyword in C++ (since C++11) with type `std::nullptr_t`. It only converts to pointer types — never to integers. Use it exclusively. Never use `NULL` or `0` for pointers in C++ code.

---

## 6. `auto` Type Deduction

C++ can deduce the type of a variable from its initializer:

```cpp
auto x = 42;          // int
auto y = 3.14;        // double
auto name = "hello";  // const char*
auto readings = std::vector<float>{1.0, 2.0, 3.0};  // std::vector<float>
```

`auto` doesn't mean "dynamic typing" — the type is fixed at compile time, you just don't write it out. Use it when the type is obvious from context (avoiding repetition), and write the type explicitly when clarity matters:

```cpp
// Good use of auto — type is obvious
auto it = readings.begin();

// Bad use of auto — type is not obvious, reader has to guess
auto result = process_frame(buf);
```

---

## 7. Putting It Together — Today's Exercise

Here's the translation exercise for today. Take this C-style module and convert it to clean C++.

**C version:**

```c
// sensor.h
#define MAX_READINGS 64

typedef struct {
    float value;
    int timestamp;
} SensorReading;

void sensor_init(SensorReading* buffer, int size);
float sensor_get_average(const SensorReading* buffer, int count);
void sensor_print(const SensorReading* r);
```

**Your target C++ version:**

```cpp
// sensor.hpp
#pragma once
#include <array>
#include <cstdio>

namespace sensor {

constexpr int MAX_READINGS = 64;  // not a macro — scoped, typed, debuggable

struct Reading {
    float value;
    int timestamp;
};

// Takes a const reference — no copy, no null, no mutation
void init(std::array<Reading, MAX_READINGS>& buffer);
float get_average(const std::array<Reading, MAX_READINGS>& buffer, int count);
void print(const Reading& r);

} // namespace sensor
```

```cpp
// sensor.cpp
#include "sensor.hpp"

namespace sensor {

void init(std::array<Reading, MAX_READINGS>& buffer) {
    for (auto& r : buffer) {
        r.value = 0.0f;
        r.timestamp = 0;
    }
}

float get_average(const std::array<Reading, MAX_READINGS>& buffer, int count) {
    if (count == 0) return 0.0f;
    float sum = 0.0f;
    for (int i = 0; i < count; ++i) {
        sum += buffer[i].value;
    }
    return sum / static_cast<float>(count);
}

void print(const Reading& r) {
    std::printf("[%d] %.2f\n", r.timestamp, r.value);
}

} // namespace sensor
```

```cpp
// main.cpp
#include "sensor.hpp"

int main() {
    std::array<sensor::Reading, sensor::MAX_READINGS> buf;
    sensor::init(buf);

    buf[0] = {23.5f, 1000};
    buf[1] = {24.1f, 1001};
    buf[2] = {22.8f, 1002};

    float avg = sensor::get_average(buf, 3);
    std::printf("Average: %.2f\n", avg);

    sensor::print(buf[0]);
    return 0;
}
```

**Compile and run it:**

```bash
g++ -std=c++17 -Wall -Wextra -o sensor main.cpp sensor.cpp
./sensor
```

Notice: no raw arrays, no `NULL`, no `#define` constants, no prefix soup. The namespace does the job the prefixes were doing — but it's scoped, not global.

---

## Key Takeaways for Day 1

- Name mangling is why overloading works and why `extern "C"` exists — you'll need both when interfacing with C HALs
- Namespaces replace prefix conventions — same discipline, compiler-enforced
- References are aliases, not pointers — use `const T&` for read-only parameters by default
- `const` is a design tool, not just a qualifier — annotate everything that shouldn't change
- `nullptr` only, never `NULL` or `0` for pointer comparisons
- `auto` deduces at compile time — use it for clarity, not laziness

Day 2 picks up directly from here and goes into the memory model: stack vs heap, how `new`/`delete` work under the hood, and where embedded constraints start to bite.