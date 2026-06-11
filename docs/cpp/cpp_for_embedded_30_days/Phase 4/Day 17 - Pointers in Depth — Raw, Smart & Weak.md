

You've used `unique_ptr` and `shared_ptr` since Day 7. Today we go under the hood: what the compiler actually generates, what the control block looks like in memory, where the costs are, and when raw pointers are still the right tool. This is also where pointer arithmetic, `std::byte` aliasing, and `void*` patterns get their full treatment — the machinery you need for custom allocators (Day 18) and the lock-free data structures you'll encounter in production embedded code.

---

## 1. Raw Pointers — What They Still Do Well

Raw pointers are not deprecated. They're the right tool in specific situations:

```cpp
// 1. Non-owning observer — points at something owned elsewhere
void process(const SensorReading* reading);  // observes, doesn't own

// 2. Optional reference — a reference that might be null
SensorReading* find_reading(uint32_t timestamp);  // null = not found

// 3. Pointer arithmetic over a buffer — iterating raw memory
void parse_buffer(const uint8_t* buf, size_t len) {
    const uint8_t* end = buf + len;
    while (buf < end) {
        uint8_t byte = *buf++;  // read and advance
    }
}

// 4. C API interop — hardware HALs, OS calls
write(fd, buffer, len);   // POSIX write — takes void*
```

The rule: **raw pointers observe, smart pointers own.** If a pointer owns something (is responsible for its lifetime), use a smart pointer. If it merely points at something owned elsewhere, a raw pointer is fine and correct.

---

## 2. `unique_ptr` Internals — Zero Overhead

`std::unique_ptr<T>` is exactly a raw pointer plus a destructor call. The compiler generates no extra code in the common case:

```cpp
// These compile to IDENTICAL machine code (with optimization):

// Version 1: raw pointer
{
    SensorBuffer* p = new SensorBuffer(64);
    p->push(23.5f);
    delete p;
}

// Version 2: unique_ptr
{
    auto p = std::make_unique<SensorBuffer>(64);
    p->push(23.5f);
    // delete called automatically
}
```

Verify this yourself: compile both versions with `-O2 -S` and diff the assembly. They're identical. The "zero overhead abstraction" claim is literally true for `unique_ptr`.

### What `unique_ptr` Stores

```cpp
template<typename T, typename Deleter = std::default_delete<T>>
class unique_ptr {
    T*      ptr_;      // the managed pointer — 8 bytes on 64-bit
    Deleter deleter_;  // usually empty — zero bytes (empty base optimization)
};

// sizeof(unique_ptr<T>) == sizeof(T*) == 8   (for default deleter)
// sizeof(unique_ptr<T, CustomDeleter>) may be larger if deleter has state
```

With default deleter, `unique_ptr<T>` is exactly one pointer. No control block, no reference count, no overhead.

### Custom Deleters

When you use a custom deleter (for a C library handle, a file descriptor, a mapped memory region), `unique_ptr` carries the deleter as part of its storage:

```cpp
// Stateless deleter — no extra storage (empty base optimization)
struct FreeDeleter {
    void operator()(void* p) const { std::free(p); }
};
std::unique_ptr<uint8_t, FreeDeleter> malloced(
    static_cast<uint8_t*>(std::malloc(256))
);
// sizeof == sizeof(uint8_t*)

// Lambda deleter — has state? No, it's stateless
auto close_fd = [](int* fd) { ::close(*fd); delete fd; };
// This would work but it's awkward — use a struct for clarity

// File descriptor RAII via unique_ptr with custom deleter
struct FdDeleter {
    void operator()(int* fd) const {
        if (fd && *fd >= 0) ::close(*fd);
        delete fd;
    }
};
auto fd = std::unique_ptr<int, FdDeleter>(new int(::open("/dev/ttyUSB0", O_RDWR)));
```

---

## 3. `shared_ptr` Internals — The Control Block

`shared_ptr<T>` stores two pointers: one to the managed object, one to a control block:

```
shared_ptr<T> object (16 bytes on 64-bit):
┌──────────────┬──────────────┐
│  T* ptr      │  CB* control │
└──────────────┴──────────────┘
      │                │
      ▼                ▼
 [T object]     Control Block:
                ┌──────────────────────┐
                │ strong_ref_count (atomic uint32) │
                │ weak_ref_count   (atomic uint32) │
                │ deleter                          │
                │ allocator                        │
                └──────────────────────┘
```

The control block is heap-allocated separately from the managed object — unless you use `make_shared`.

### `make_shared` vs `new` + `shared_ptr`

```cpp
// Two allocations: one for T, one for control block
std::shared_ptr<SensorBuffer> p1(new SensorBuffer(64));

// One allocation: T and control block in single block
auto p2 = std::make_shared<SensorBuffer>(64);
```

`make_shared` is almost always correct. The single allocation means:

- One call to `malloc` instead of two — faster
- Better cache locality — T and its ref count are adjacent
- Exception safety — no risk of leaking T if the `shared_ptr` constructor throws

The one case where `make_shared` is wrong: when you need a custom deleter, or when you're using weak_ptr and need the T's memory freed before all weak_ptrs expire (with `make_shared`, T and the control block are in the same allocation — T's memory isn't freed until the weak count also hits zero).

### Reference Count Operations

```cpp
auto p1 = std::make_shared<SensorBuffer>(64);   // strong=1 weak=1
auto p2 = p1;                                    // strong=2 — atomic increment
auto w  = std::weak_ptr<SensorBuffer>(p1);       // weak=2 — atomic increment

p2.reset();  // strong=1 — atomic decrement
p1.reset();  // strong=0 — object destroyed; weak=1 — control block stays
             // (T is destroyed, but control block alive because weak_count > 0)

auto locked = w.lock();  // weak=1, lock() checks strong==0 → returns null
```

Each copy of `shared_ptr` does an atomic increment. Each destruction does an atomic decrement. On a multicore ARM (every IoT SoC), atomic operations require cache coherency traffic — they're not free. For a `shared_ptr` that's copied 10 million times per second, this is measurable.

### `shared_ptr` Size and Alias Constructor

```cpp
// shared_ptr is two pointers — 16 bytes on 64-bit
static_assert(sizeof(std::shared_ptr<int>) == 16);

// Alias constructor — shared_ptr to a member, lifetime of the whole object
struct Config {
    int baud_rate;
    float calibration;
};

auto cfg = std::make_shared<Config>(Config{9600, 1.02f});

// Points to cfg->baud_rate, keeps cfg alive
std::shared_ptr<int> baud_ptr(cfg, &cfg->baud_rate);
// baud_ptr.use_count() == 2 (shares ownership with cfg)
// *baud_ptr == 9600
cfg.reset();  // cfg destroyed, but Config object still alive — baud_ptr keeps it
```

---

## 4. `weak_ptr` — Non-Owning Observer With Safety

`weak_ptr` solves two problems:

**Breaking cycles:** if two objects hold `shared_ptr` to each other, the ref count never reaches zero — leak. Make one side `weak_ptr`.

**Safe observation:** check if an object is still alive before using it.

```cpp
// ---- Cycle problem ----
struct Node {
    std::shared_ptr<Node> next;   // cycle if two nodes point at each other
};

struct SafeNode {
    std::weak_ptr<SafeNode> next;  // no cycle — weak doesn't increment strong count
};

// ---- Observer pattern with weak_ptr ----
class SensorManager {
    std::vector<std::weak_ptr<Sensor>> observers_;

public:
    void add_observer(std::weak_ptr<Sensor> s) {
        observers_.push_back(s);
    }

    void notify_all(float value) {
        // Prune expired observers while notifying
        auto it = observers_.begin();
        while (it != observers_.end()) {
            if (auto sensor = it->lock()) {   // lock: weak → shared (or null)
                sensor->on_value(value);
                ++it;
            } else {
                it = observers_.erase(it);    // expired — remove from list
            }
        }
    }
};
```

### `weak_ptr` and `enable_shared_from_this`

A common pattern: an object needs to pass a `shared_ptr` to itself to a callback. You can't do `shared_ptr<T>(this)` — that creates a second, independent control block. Use `enable_shared_from_this`:

```cpp
class MQTTSession : public std::enable_shared_from_this<MQTTSession> {
public:
    void schedule_reconnect() {
        // shared_from_this() returns a shared_ptr that shares the existing
        // control block — safe to capture in a callback
        auto self = shared_from_this();
        timer_.schedule(1000, [self]() {
            self->reconnect();   // self keeps MQTTSession alive until callback fires
        });
    }

    void reconnect() { /* ... */ }
private:
    Timer timer_;
};

// Must be managed by shared_ptr for shared_from_this() to work
auto session = std::make_shared<MQTTSession>();
session->schedule_reconnect();
```

---

## 5. Pointer Arithmetic and Memory Layout

Raw pointer arithmetic is how you navigate raw buffers, implement allocators, and interface with DMA hardware. It's also how undefined behavior happens if you're not careful.

```cpp
uint8_t buffer[256];

uint8_t* p = buffer;
uint8_t* q = p + 16;    // points to buffer[16]
ptrdiff_t diff = q - p; // 16 — difference in elements, not bytes

// Pointer to arbitrary offset
uint8_t* mid = buffer + 128;
size_t offset = mid - buffer;  // 128

// Walking a buffer
for (uint8_t* cur = buffer; cur < buffer + 256; ++cur) {
    *cur = 0xFF;
}
```

### Alignment and `std::align`

Placing objects at misaligned addresses causes:

- Hardware fault on strict-alignment architectures (ARM Cortex-M without unaligned access support)
- Performance penalty on x86 (usually, not always)
- Undefined behavior in C++ (always, regardless of hardware)

```cpp
#include <memory>

uint8_t raw[128];
void*   ptr  = raw;
size_t  space = sizeof(raw);

// Align for a uint32_t — advances ptr and reduces space
void* aligned = std::align(alignof(uint32_t), sizeof(uint32_t), ptr, space);
if (aligned) {
    new (aligned) uint32_t(0xDEADBEEF);   // placement new — construct at aligned addr
}

// Compute alignment manually
uintptr_t addr      = reinterpret_cast<uintptr_t>(raw);
uintptr_t aligned_addr = (addr + alignof(uint32_t) - 1) & ~(alignof(uint32_t) - 1);
// This is exactly what std::align does
```

### `void*` and `std::byte*` — Raw Memory Operations

```cpp
// void* — opaque pointer, no arithmetic allowed
void* raw = malloc(256);
// raw + 1;   // compile error — can't do arithmetic on void*

// Cast to uint8_t* or std::byte* for arithmetic
uint8_t* bytes = static_cast<uint8_t*>(raw);
bytes += 16;    // advance 16 bytes

// std::byte* — preferred in modern C++ for raw memory
std::byte* bp = static_cast<std::byte*>(raw);
bp += 16;

// std::memcpy — only safe way to copy between unrelated types
float f = 3.14f;
uint32_t bits;
std::memcpy(&bits, &f, sizeof(float));  // safe — no aliasing violation
```

---

## 6. Pointer Safety Checklist

The most common pointer bugs, with their modern C++ fixes:

```cpp
// 1. Dangling pointer — points to destroyed object
int* bad() {
    int x = 42;
    return &x;   // x destroyed on return — caller has dangling pointer
}
// Fix: return by value, or allocate on heap with unique_ptr

// 2. Use after free
int* p = new int(42);
delete p;
*p = 99;   // UB — memory may have been reused
// Fix: unique_ptr — can't delete manually, can't use after move

// 3. Double free
int* p = new int(42);
delete p;
delete p;   // UB — corrupts heap
// Fix: unique_ptr — destructor only runs once

// 4. Array/scalar mismatch
int* arr = new int[10];
delete arr;    // UB — should be delete[]
// Fix: unique_ptr<int[]> or std::vector

// 5. Null dereference
std::unique_ptr<int> p;   // null
*p;   // UB — dereferencing null
// Fix: check before dereferencing
if (p) *p = 42;

// 6. Pointer invalidation
std::vector<int> v = {1,2,3};
int* ptr = &v[0];
v.push_back(4);   // may reallocate — ptr is now dangling
// Fix: use indices, or reserve() before taking pointers
```

---

## 7. Putting It Together — Smart Pointer Internals Demo

```cpp
// smart_ptr_demo.cpp
#include <cstdio>
#include <cstdint>
#include <cstdlib>
#include <cstring>
#include <memory>
#include <vector>
#include <cassert>
#include <functional>

// ---- Instrumented type ----

struct Tracked {
    int id;
    static inline int live_count = 0;

    explicit Tracked(int id) : id(id) {
        ++live_count;
        printf("  [Tracked #%d] constructed (live=%d)\n", id, live_count);
    }
    ~Tracked() {
        --live_count;
        printf("  [Tracked #%d] destroyed   (live=%d)\n", id, live_count);
    }
    Tracked(const Tracked&) = delete;
    Tracked& operator=(const Tracked&) = delete;
};

// ---- Custom deleter for malloc'd memory ----

struct MallocDeleter {
    void operator()(void* p) const {
        printf("  [MallocDeleter] freeing %p\n", p);
        std::free(p);
    }
};

// ---- Cache: stores observers via weak_ptr ----

class SensorCache {
public:
    void register_sensor(std::weak_ptr<Tracked> sensor) {
        sensors_.push_back(sensor);
        printf("  [cache] registered sensor (total=%zu)\n", sensors_.size());
    }

    // Returns count of still-live sensors
    int poll() {
        int live = 0;
        for (auto& w : sensors_) {
            if (auto s = w.lock()) {
                printf("  [cache] sensor #%d is alive\n", s->id);
                ++live;
            } else {
                printf("  [cache] sensor expired\n");
            }
        }
        return live;
    }

    // Prune expired entries
    void prune() {
        size_t before = sensors_.size();
        sensors_.erase(
            std::remove_if(sensors_.begin(), sensors_.end(),
                [](const std::weak_ptr<Tracked>& w) { return w.expired(); }),
            sensors_.end()
        );
        printf("  [cache] pruned %zu expired entries\n",
               before - sensors_.size());
    }

private:
    std::vector<std::weak_ptr<Tracked>> sensors_;
};

// ---- enable_shared_from_this demo ----

class Session : public std::enable_shared_from_this<Session> {
public:
    explicit Session(int id) : id_(id) {
        printf("  [Session %d] created\n", id_);
    }
    ~Session() {
        printf("  [Session %d] destroyed\n", id_);
    }

    // Schedule a callback that keeps *this alive
    std::function<void()> make_callback() {
        auto self = shared_from_this();   // safe — shares control block
        return [self]() {
            printf("  [callback] Session %d still alive (use_count=%ld)\n",
                   self->id_,
                   self.use_count());
        };
    }

    int id() const { return id_; }

private:
    int id_;
};

int main() {
    printf("=== Smart Pointer Internals ===\n\n");

    // ---- unique_ptr size ----
    printf("--- Sizes ---\n");
    printf("  sizeof(raw int*):                %zu\n", sizeof(int*));
    printf("  sizeof(unique_ptr<int>):         %zu\n", sizeof(std::unique_ptr<int>));
    printf("  sizeof(shared_ptr<int>):         %zu\n", sizeof(std::shared_ptr<int>));
    printf("  sizeof(weak_ptr<int>):           %zu\n", sizeof(std::weak_ptr<int>));
    // unique_ptr == raw pointer; shared_ptr == two pointers

    // ---- unique_ptr lifecycle ----
    printf("\n--- unique_ptr lifecycle ---\n");
    {
        auto p = std::make_unique<Tracked>(1);
        printf("  live after make_unique: %d\n", Tracked::live_count);
        // p destroyed here — Tracked::~Tracked called
    }
    printf("  live after scope: %d\n", Tracked::live_count);

    // ---- shared_ptr ref count ----
    printf("\n--- shared_ptr ref count ---\n");
    {
        auto p1 = std::make_shared<Tracked>(2);
        printf("  use_count after make_shared: %ld\n", p1.use_count());
        {
            auto p2 = p1;
            printf("  use_count after copy: %ld\n", p1.use_count());
            auto p3 = p1;
            printf("  use_count after 2nd copy: %ld\n", p1.use_count());
        }
        printf("  use_count after inner scope: %ld\n", p1.use_count());
    }
    printf("  live after shared_ptr scope: %d\n", Tracked::live_count);

    // ---- weak_ptr observation ----
    printf("\n--- weak_ptr observation ---\n");
    SensorCache cache;
    {
        auto s1 = std::make_shared<Tracked>(3);
        auto s2 = std::make_shared<Tracked>(4);
        cache.register_sensor(s1);
        cache.register_sensor(s2);

        printf("  poll while alive:\n");
        int live = cache.poll();
        printf("  live count: %d\n", live);

        printf("  destroying s1:\n");
        s1.reset();  // explicitly destroy

        printf("  poll after s1 destroyed:\n");
        cache.poll();
    }
    printf("  poll after s2 destroyed:\n");
    cache.poll();
    cache.prune();

    // ---- enable_shared_from_this ----
    printf("\n--- enable_shared_from_this ---\n");
    std::function<void()> callback;
    {
        auto session = std::make_shared<Session>(42);
        callback = session->make_callback();
        printf("  use_count before scope exit: %ld\n", session.use_count());
    }
    // session variable is gone, but callback holds shared_from_this
    printf("  invoking callback after Session variable destroyed:\n");
    callback();
    printf("  resetting callback:\n");
    callback = nullptr;  // releases the shared_ptr — Session destroyed here
    printf("  live Tracked: %d\n", Tracked::live_count);

    // ---- Custom deleter ----
    printf("\n--- Custom deleter (malloc/free) ---\n");
    {
        auto raw = static_cast<uint8_t*>(std::malloc(64));
        std::memset(raw, 0xFF, 64);
        std::unique_ptr<uint8_t, MallocDeleter> buf(raw);
        printf("  buf[0] = 0x%02X\n", buf.get()[0]);
        // MallocDeleter called on scope exit — not delete, but free()
    }

    // ---- Pointer arithmetic ----
    printf("\n--- Pointer arithmetic ---\n");
    alignas(8) uint8_t memory[64];
    std::memset(memory, 0, sizeof(memory));

    uint8_t* base = memory;
    uint32_t* words = reinterpret_cast<uint32_t*>(base);  // aligned — safe

    words[0] = 0x01020304;
    words[1] = 0xDEADBEEF;

    printf("  memory[0..7]:");
    for (int i = 0; i < 8; ++i) printf(" %02X", memory[i]);
    printf("\n");
    printf("  words[0] = 0x%08X\n", words[0]);
    printf("  words[1] = 0x%08X\n", words[1]);

    // Pointer difference
    uint8_t* p1 = memory + 16;
    uint8_t* p2 = memory + 48;
    ptrdiff_t delta = p2 - p1;
    printf("  pointer delta: %td bytes\n", delta);

    // std::align
    uint8_t  unaligned_buf[32];
    uint8_t* raw_ptr  = unaligned_buf + 1;  // deliberately misalign
    size_t   avail    = sizeof(unaligned_buf) - 1;
    void*    vptr     = raw_ptr;
    void*    aligned  = std::align(alignof(uint64_t), sizeof(uint64_t), vptr, avail);
    if (aligned) {
        uintptr_t offset = static_cast<uint8_t*>(aligned) - unaligned_buf;
        printf("  aligned for uint64_t at offset %tu from buffer start\n", offset);
    }

    printf("\nDone. Live Tracked objects: %d\n", Tracked::live_count);
    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address -o smart_ptr smart_ptr_demo.cpp
./smart_ptr
```

### What to observe

`sizeof(unique_ptr<int>) == sizeof(int*)` — one pointer, zero overhead. `sizeof(shared_ptr<int>) == 16` — two pointers. The size difference is the entire cost model in one line.

When `s1.reset()` is called and the poll happens, the weak_ptr's `lock()` returns null — the `Tracked` destructor ran but the control block is still alive (weak count > 0). After the `cache.prune()`, the expired entries are gone.

The `enable_shared_from_this` demo shows that after the `session` variable goes out of scope, the `Session` object is still alive because `callback` holds a `shared_ptr` (via `shared_from_this`). When `callback = nullptr`, the count drops to zero and `~Session` runs.

---

## Key Takeaways for Day 17

- Raw pointers observe, smart pointers own — non-owning pointers to things owned elsewhere are raw pointers by design
- `unique_ptr` compiles to exactly a raw pointer plus a destructor call — zero overhead, verified in assembly
- `shared_ptr` stores two pointers (to object and control block) — 16 bytes. The control block has two atomic ref counts plus deleter and allocator
- Use `make_shared` over `new` + `shared_ptr` — single allocation, exception-safe, better cache locality
- `weak_ptr` doesn't affect strong count — `lock()` returns a valid `shared_ptr` or null, never a dangling pointer
- `enable_shared_from_this` — inherit from it when an object needs to create a `shared_ptr` to itself. Never do `shared_ptr<T>(this)` — creates a second independent control block, double-free
- `std::align` computes the next aligned address in a buffer — use it for placement new and custom allocators
- `std::memcpy` is the only safe way to read bits of one type as another type — `reinterpret_cast` through a pointer violates strict aliasing rules

Day 18 builds directly on this: custom allocators, pool allocation, and arena allocation — how to eliminate heap fragmentation entirely in embedded systems where `malloc` is either unavailable or dangerous.