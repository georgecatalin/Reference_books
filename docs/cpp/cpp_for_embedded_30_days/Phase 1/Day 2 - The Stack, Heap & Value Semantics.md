

You already understand memory from C and systems work — stack grows down, heap is dynamic, pointers are addresses. What C++ adds is a _formal ownership model_ layered on top of that physical reality. Today we make that model explicit, because everything in modern C++ — smart pointers, RAII, move semantics — is built on top of what we cover here.

---

## 1. The Memory Layout of a Running Program

Before writing a line of C++, lock in the physical picture:

```
High address
┌─────────────────────┐
│       Stack         │  grows downward ↓
│  (automatic storage)│  local variables, function frames
├─────────────────────┤
│         ↓           │
│      (gap)          │
│         ↑           │
├─────────────────────┤
│        Heap         │  grows upward ↑
│  (dynamic storage)  │  new / delete
├─────────────────────┤
│    BSS segment      │  zero-initialized globals
│    Data segment     │  initialized globals, string literals
│    Text segment     │  your compiled code
└─────────────────────┘
Low address
```

This is the same on Linux, bare-metal ARM, or ESP32. The proportions differ wildly — an embedded target might have 256KB of RAM total, with stack capped at 4KB per task.

---

## 2. The Stack — Automatic Storage Duration

"Automatic storage duration" is the C++ standard's term for what you think of as stack variables. The key property: **the compiler manages lifetime automatically.** The variable is created when execution enters its scope, destroyed when execution leaves it.

```cpp
void process_frame() {
    uint8_t buffer[64];     // allocated on entry to process_frame
    int count = 0;          // same
    float temperature;      // same — uninitialized until you write it

    // ... work ...

}   // buffer, count, temperature all destroyed here — no code required
```

The "destruction" here is just moving the stack pointer back. For plain types (`int`, `float`, arrays of POD), nothing else happens. For C++ objects with destructors, the destructor _is_ called — this is the foundation of RAII, which we'll cover on Day 7.

### Stack Allocation Cost

Stack allocation is effectively free — it's a single subtraction from the stack pointer register. Compare that to `malloc()`, which walks a free list, possibly calls `sbrk()`, and acquires a lock in multithreaded programs. For tight loops in embedded code, this difference is real.

### Stack Size Limits

The stack is finite. On Linux, the default stack size per thread is typically 8MB. On FreeRTOS, you configure it per task — often 512 bytes to a few KB. Large arrays on the stack are a common source of embedded crashes:

```cpp
void bad_idea() {
    uint8_t huge_buffer[1024 * 1024];  // 1MB on the stack — stack overflow on embedded
}

void better() {
    static uint8_t huge_buffer[1024 * 1024];  // static storage — not on the stack
    // or allocate on the heap
}
```

`static` local variables live in the BSS/data segment, not the stack. They're initialized once and persist for the program's lifetime. Useful for embedded buffers.

---

## 3. The Heap — Dynamic Storage Duration

The heap gives you memory whose lifetime you control explicitly — it lives until you release it. In C this is `malloc`/`free`. In C++ it's `new`/`delete`.

```cpp
// C style
SensorReading* r = (SensorReading*)malloc(sizeof(SensorReading));
r->value = 23.5f;
free(r);

// C++ style
SensorReading* r = new SensorReading{23.5f, 1000};
delete r;

// Array version
SensorReading* buf = new SensorReading[64];
delete[] buf;   // must use delete[] for arrays — not delete
```

`new` does two things: allocates memory (like `malloc`) and calls the constructor. `delete` calls the destructor then frees memory. This is the critical difference from C — construction and destruction are automatic.

### The Problem with Raw new/delete

```cpp
SensorReading* r = new SensorReading{23.5f, 1000};

if (some_error_condition) {
    return;          // r is leaked — delete never called
}

process(r);
delete r;
```

Every early return, every exception, every forgotten branch is a potential leak. This is why modern C++ barely uses raw `new`/`delete` directly — smart pointers (Day 7) handle this. But you need to understand what they're wrapping first.

---

## 4. Value Semantics vs Reference Semantics

This is the conceptual divide that trips up people coming from Python, Java, or even C.

**Python/Java** — reference semantics by default. Variables hold references to objects. Assignment copies the reference, not the object.

**C** — mixed. Structs have value semantics (assignment copies), pointers have reference semantics.

**C++** — value semantics by default for everything. Assignment copies. If you want reference semantics, you explicitly use pointers or references.

```cpp
struct Reading {
    float value;
    int timestamp;
};

Reading a{23.5f, 1000};
Reading b = a;          // b is a COPY of a — independent object

b.value = 99.0f;
// a.value is still 23.5f — b and a are independent

Reading* p = &a;        // reference semantics — p points to a
p->value = 99.0f;       // NOW a.value is 99.0f
```

This matters enormously for how you design APIs:

```cpp
// Passes a copy — caller's Reading is unaffected
void log(Reading r);

// Passes a reference — caller's Reading can be modified
void update(Reading& r);

// Passes a const reference — no copy, but cannot modify
void display(const Reading& r);
```

When you pass a `std::vector<Reading>` to a function by value, it copies every element. Understanding value semantics is why you'll reflexively reach for `const&` on large types.

---

## 5. Stack vs Heap — When to Use Each

Here's the practical decision table:

|Factor|Stack|Heap|
|---|---|---|
|Size known at compile time|✓ prefer stack||
|Large buffer (>few KB)|risky|✓ use heap|
|Lifetime beyond current scope|✗ can't|✓|
|Shared between threads|unsafe (dangling)|✓ with care|
|Embedded, no heap allocator|✓ only option|often disabled|
|Performance-critical hot path|✓ faster|slower|

In practice on embedded systems: prefer stack for small, short-lived objects. Use `static` local or global storage for large persistent buffers. Avoid heap in interrupt handlers entirely.

---

## 6. Undefined Behavior — The Landmines

C++ inherits C's undefined behavior, and adds a few of its own. These are the most common memory-related ones:

### Use after free

```cpp
SensorReading* r = new SensorReading{23.5f, 1000};
delete r;
float v = r->value;  // undefined behavior — memory may have been reused
```

### Dangling reference

```cpp
SensorReading& get_reading() {
    SensorReading r{23.5f, 1000};
    return r;   // r is destroyed when function returns — reference is dangling
}               // this is undefined behavior
```

The compiler may warn about this, but won't always catch it.

### Stack overflow

```cpp
void recurse(int n) {
    int buffer[4096];   // 4KB per call frame
    recurse(n + 1);     // no base case — stack exhausted
}
```

On embedded, this is silent data corruption — the stack overwrites other memory regions. On Linux, you get a segfault.

### Double delete

```cpp
SensorReading* r = new SensorReading{};
delete r;
delete r;   // undefined behavior — heap metadata is corrupted
```

All of these are things that smart pointers and RAII prevent mechanically. Understanding why they're problems makes you appreciate the solution.

---

## 7. Object Lifetime in Detail

C++ has four storage durations — important to know the vocabulary:

```cpp
// 1. Automatic — stack, destroyed at end of scope
void f() {
    int x = 5;   // automatic
}

// 2. Static — lives for program duration
static int counter = 0;   // file-scope static
void g() {
    static int call_count = 0;  // function-local static — initialized once
    ++call_count;
}

// 3. Dynamic — heap, you control the lifetime
int* p = new int(42);   // created here
delete p;               // destroyed here

// 4. Thread-local — one copy per thread
thread_local int tls_buffer[256];
```

For IoT work, static storage duration is underused. A thread-local receive buffer, a statically allocated device registry — these avoid heap fragmentation entirely.

---

## 8. Today's Exercise

Three parts. Do them in order.

### Part A — Stack vs Heap profiling

```cpp
#include <cstdio>
#include <ctime>

struct SensorReading {
    float value;
    int timestamp;
    char device_id[16];
};

// Measure how long 1 million stack allocations take
void bench_stack() {
    auto start = clock();
    for (int i = 0; i < 1'000'000; ++i) {
        SensorReading r{23.5f, i, "sensor_01"};
        (void)r;  // prevent optimizer from eliminating it
    }
    auto end = clock();
    printf("Stack: %ld ms\n", (end - start) * 1000 / CLOCKS_PER_SEC);
}

// Same but heap
void bench_heap() {
    auto start = clock();
    for (int i = 0; i < 1'000'000; ++i) {
        SensorReading* r = new SensorReading{23.5f, i, "sensor_01"};
        delete r;
    }
    auto end = clock();
    printf("Heap:  %ld ms\n", (end - start) * 1000 / CLOCKS_PER_SEC);
}

int main() {
    bench_stack();
    bench_heap();
}
```

Compile with `-O0` to prevent the optimizer from skipping the work, then again with `-O2`. Observe the ratio.

```bash
g++ -std=c++17 -O0 -o bench bench.cpp && ./bench
g++ -std=c++17 -O2 -o bench bench.cpp && ./bench
```

### Part B — Trigger a stack overflow intentionally

```cpp
#include <cstdio>

void overflow(int depth) {
    char frame_eater[4096];   // consume 4KB per call
    frame_eater[0] = depth;   // touch it so optimizer keeps it
    printf("depth: %d\n", depth);
    overflow(depth + 1);
}

int main() {
    overflow(0);
}
```

Compile and run it. Watch the segfault. Note the last depth printed. This is your stack size divided by ~4KB. On Linux with `ulimit -s` you can control the stack size and repeat the experiment.

### Part C — Spot and fix the bugs

Find every memory bug in this code, explain what each one is, then rewrite it correctly:

```cpp
#include <cstdio>

struct Config {
    int baud_rate;
    bool enabled;
};

Config& get_default_config() {
    Config cfg{9600, true};
    return cfg;
}

void setup() {
    Config& c = get_default_config();
    printf("baud: %d\n", c.baud_rate);

    Config* dynamic = new Config{115200, true};
    printf("baud: %d\n", dynamic->baud_rate);

    Config* copy = dynamic;
    delete dynamic;
    delete copy;

    printf("baud: %d\n", dynamic->baud_rate);
}

int main() { setup(); }
```

There are three distinct bugs. Name each one, explain why it's undefined behavior, and write a corrected version.

---

## Key Takeaways for Day 2

- Stack allocation is a register subtract — heap allocation acquires a lock and walks a free list. The difference is real on embedded targets
- "Automatic storage duration" means the compiler handles lifetime — the destructor is called at scope exit, always
- Value semantics means assignment copies by default — Python's behavior requires explicit pointers or references in C++
- `delete[]` for arrays, `delete` for single objects — mixing them is undefined behavior
- Static local variables live in BSS, not the stack — use them for large embedded buffers
- Dangling references, use-after-free, and double-delete are undefined behavior — not crashes, not errors, _undefined_ — the program can appear to work and then fail later

Day 3 builds directly on this — we go into C++'s type system, the four casts, and how `reinterpret_cast` lets you do the same register-map and protocol-buffer tricks you've done in C, but safely and with the compiler's cooperation.