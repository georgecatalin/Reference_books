

Day 17 showed you what smart pointers compile to. Today we go one level lower: what `new` and `delete` actually do, why the default allocator is problematic for embedded and real-time code, and how to build allocators that give you deterministic timing, zero fragmentation, and full control over where objects live in memory. This is foundational for the concurrency work ahead — a lock-free queue needs a lock-free allocator to be truly lock-free.

---

## 1. What `new` and `delete` Actually Do

`new` is not a primitive. It calls `operator new`, which calls `malloc`, which eventually calls `brk()` or `mmap()` on Linux, or the platform heap manager on embedded:

```cpp
// What new T(args...) actually does:
// 1. Call operator new(sizeof(T)) — allocates raw memory
// 2. Call T::T(args...) via placement new — constructs object in that memory
// 3. Return the pointer

// What delete p actually does:
// 1. Call p->~T() — destroys the object
// 2. Call operator delete(p) — frees the raw memory

// You can intercept both steps:
void* raw = ::operator new(sizeof(SensorBuffer));    // step 1 only
SensorBuffer* p = new (raw) SensorBuffer(64);        // step 2 only (placement new)
p->~SensorBuffer();                                  // step 1 of delete only
::operator delete(raw);                              // step 2 of delete only
```

This separation — allocation vs construction — is the key insight for custom allocators. You control where the memory comes from. The constructor still runs normally.

### Why the Default Allocator Is Problematic

```
malloc/free issues for embedded/real-time code:

1. Non-deterministic timing
   — malloc walks a free list, may call sbrk() or mmap()
   — time varies from nanoseconds to milliseconds
   — unacceptable in interrupt handlers or hard real-time tasks

2. Fragmentation
   — repeated alloc/free of different sizes fragments the heap
   — a system with 100KB free may fail to allocate 50KB contiguous
   — embedded systems run for months/years — fragmentation accumulates

3. Thread safety cost
   — malloc acquires a global lock on most platforms
   — contention is measurable in multithreaded code

4. No placement control
   — can't place objects in specific memory regions
   — can't use DMA-capable memory, tightly coupled memory (TCM), etc.

5. May be unavailable
   — bare-metal without OS has no malloc by default
   — some safety-critical standards (MISRA, DO-178) prohibit dynamic allocation
```

---

## 2. Placement New — Constructing in Pre-Allocated Memory

Placement new constructs an object at a specific address you provide. No allocation happens:

```cpp
#include <new>

alignas(SensorBuffer) uint8_t storage[sizeof(SensorBuffer)];

// Construct SensorBuffer in storage — no heap allocation
SensorBuffer* p = new (storage) SensorBuffer(64);
p->push(23.5f);

// Must call destructor explicitly — no delete (we didn't use new)
p->~SensorBuffer();
// storage can now be reused
```

The alignment annotation is mandatory — constructing a type at a misaligned address is undefined behavior. `alignas(T)` ensures the storage has at least the alignment requirement of `T`.

### Placement New in a Buffer

```cpp
template<typename T>
class ManualStorage {
public:
    T* construct(auto&&... args) {
        return new (storage_) T(std::forward<decltype(args)>(args)...);
    }
    void destroy() {
        reinterpret_cast<T*>(storage_)->~T();
    }
    T* get() { return reinterpret_cast<T*>(storage_); }

private:
    alignas(T) uint8_t storage_[sizeof(T)];
};

ManualStorage<SensorBuffer> s;
s.construct(64);         // constructs in-place
s.get()->push(23.5f);
s.destroy();             // explicit destructor call
```

This is exactly what `std::optional<T>` does internally — it has aligned storage and conditionally constructs/destroys the T.

---

## 3. Pool Allocator — Fixed-Size Block Allocation

A pool allocator pre-allocates a fixed number of same-size blocks. Allocation is O(1): pop a free block off a list. Deallocation is O(1): push the block back. No fragmentation. Deterministic timing.

```cpp
// pool_allocator.hpp
#pragma once
#include <cstddef>
#include <cstdint>
#include <cassert>
#include <array>
#include <new>

template<typename T, size_t Capacity>
class PoolAllocator {
    static_assert(Capacity > 0, "Pool must have capacity > 0");
    // Each block must be large enough to hold either a T or a pointer
    // (when free, blocks store the next-free pointer)
    static constexpr size_t BLOCK_SIZE = sizeof(T) > sizeof(void*)
                                       ? sizeof(T) : sizeof(void*);
public:
    PoolAllocator() {
        // Initialize free list — each block points to the next
        free_list_ = reinterpret_cast<void**>(pool_[0].data());
        void** current = free_list_;
        for (size_t i = 1; i < Capacity; ++i) {
            *current = reinterpret_cast<void**>(pool_[i].data());
            current  = reinterpret_cast<void**>(*current);
        }
        *current = nullptr;  // last block points to null — end of list
        free_count_ = Capacity;
    }

    // Not copyable or movable — pool is tied to its storage
    PoolAllocator(const PoolAllocator&)            = delete;
    PoolAllocator& operator=(const PoolAllocator&) = delete;
    PoolAllocator(PoolAllocator&&)                 = delete;
    PoolAllocator& operator=(PoolAllocator&&)      = delete;

    // Allocate one block — O(1), deterministic
    void* allocate() {
        if (!free_list_) return nullptr;   // pool exhausted

        void* block = free_list_;          // take from front of free list
        free_list_ = reinterpret_cast<void**>(*free_list_);  // advance list
        --free_count_;
        return block;
    }

    // Deallocate one block — O(1), deterministic
    void deallocate(void* ptr) {
        assert(owns(ptr) && "Pointer not from this pool");
        // Push back onto the free list
        *reinterpret_cast<void**>(ptr) = free_list_;
        free_list_ = reinterpret_cast<void**>(ptr);
        ++free_count_;
    }

    // Construct T in a pool block — returns nullptr if pool exhausted
    template<typename... Args>
    T* construct(Args&&... args) {
        void* block = allocate();
        if (!block) return nullptr;
        return new (block) T(std::forward<Args>(args)...);
    }

    // Destroy T and return block to pool
    void destroy(T* ptr) {
        ptr->~T();
        deallocate(ptr);
    }

    // State
    size_t free_count()  const { return free_count_; }
    size_t used_count()  const { return Capacity - free_count_; }
    size_t capacity()    const { return Capacity; }
    bool   exhausted()   const { return free_count_ == 0; }

    // Check if a pointer belongs to this pool
    bool owns(void* ptr) const {
        auto p   = reinterpret_cast<uintptr_t>(ptr);
        auto beg = reinterpret_cast<uintptr_t>(pool_.data());
        auto end = beg + sizeof(pool_);
        return p >= beg && p < end;
    }

private:
    // Each pool slot: aligned storage for one T
    using Block = std::array<alignas(T) uint8_t, BLOCK_SIZE>;
    std::array<Block, Capacity> pool_;
    void**  free_list_;
    size_t  free_count_;
};
```

The free list is embedded in the free blocks themselves — when a block is free, its first bytes store the next-free pointer. When the block is allocated and in use, those bytes are overwritten by the T's data. Same memory, two roles. This is a classic embedded systems trick — no separate metadata array needed.

---

## 4. Arena Allocator — Bump Pointer

An arena allocator allocates from a linear buffer using a "bump pointer" — advance the pointer by the requested size. Allocation is a pointer add and alignment adjustment. Deallocation is a no-op for individual objects — you free the entire arena at once.

```cpp
// arena_allocator.hpp
#pragma once
#include <cstddef>
#include <cstdint>
#include <cstring>
#include <cassert>
#include <memory>

class ArenaAllocator {
public:
    // Construct from external buffer — arena doesn't own the memory
    ArenaAllocator(void* buf, size_t size)
        : begin_(static_cast<uint8_t*>(buf))
        , current_(begin_)
        , end_(begin_ + size)
    {}

    // Construct with internal storage
    template<size_t N>
    explicit ArenaAllocator(uint8_t (&buf)[N])
        : ArenaAllocator(buf, N)
    {}

    // Allocate size bytes with given alignment — O(1)
    void* allocate(size_t size, size_t alignment = alignof(std::max_align_t)) {
        // Align current pointer up to alignment
        uintptr_t current_addr = reinterpret_cast<uintptr_t>(current_);
        uintptr_t aligned_addr = (current_addr + alignment - 1) & ~(alignment - 1);

        uint8_t* aligned_ptr = reinterpret_cast<uint8_t*>(aligned_addr);
        uint8_t* next_ptr    = aligned_ptr + size;

        if (next_ptr > end_) return nullptr;  // out of space

        current_ = next_ptr;
        return aligned_ptr;
    }

    // Construct T in arena — O(1)
    template<typename T, typename... Args>
    T* construct(Args&&... args) {
        void* mem = allocate(sizeof(T), alignof(T));
        if (!mem) return nullptr;
        return new (mem) T(std::forward<Args>(args)...);
    }

    // Individual deallocation is a no-op — objects live until reset()
    void deallocate(void*, size_t) noexcept {}

    // Free all allocations at once — reset to beginning
    void reset() {
        current_ = begin_;
    }

    // Scoped reset — restore to a saved position
    struct Marker {
        uint8_t* position;
    };

    Marker mark() const { return {current_}; }

    void restore(Marker m) {
        assert(m.position >= begin_ && m.position <= end_);
        current_ = m.position;
    }

    // State
    size_t used()      const { return static_cast<size_t>(current_ - begin_); }
    size_t remaining() const { return static_cast<size_t>(end_ - current_); }
    size_t capacity()  const { return static_cast<size_t>(end_ - begin_); }
    bool   empty()     const { return current_ == begin_; }

private:
    uint8_t* begin_;
    uint8_t* current_;  // next allocation starts here (bump pointer)
    uint8_t* end_;
};
```

The marker/restore pattern is powerful — save the arena state before a temporary allocation burst, restore it afterward. One restore call reclaims all temporary objects at once, no individual destructor calls needed (unless the objects need cleanup — in that case you'd call destructors manually before restoring).

---

## 5. `std::pmr` — Polymorphic Memory Resources

C++17 added `std::pmr` (polymorphic memory resource) — a standard interface for custom allocators that works with standard containers:

```cpp
#include <memory_resource>
#include <vector>
#include <string>

// Use a stack buffer as backing for a vector — zero heap allocation
uint8_t stack_buffer[4096];
std::pmr::monotonic_buffer_resource arena(stack_buffer, sizeof(stack_buffer));

// pmr::vector uses the arena for all allocations
std::pmr::vector<float> readings(&arena);
readings.reserve(100);
for (int i = 0; i < 100; ++i) readings.push_back(static_cast<float>(i));
// All 100 floats allocated from stack_buffer — no heap

// pmr::string also uses the arena
std::pmr::string topic("sensors/temperature/device_01", &arena);
// String data allocated from stack_buffer
```

### Standard PMR Resources

```cpp
// monotonic_buffer_resource — bump allocator, no deallocation
// Fast, no overhead per deallocation
std::pmr::monotonic_buffer_resource mono(buffer, size);

// unsynchronized_pool_resource — pool for different sizes, single thread
std::pmr::unsynchronized_pool_resource pool;

// synchronized_pool_resource — thread-safe pool
std::pmr::synchronized_pool_resource sync_pool;

// null_memory_resource — always throws/returns null — for testing
auto* null = std::pmr::null_memory_resource();

// Chaining — fall back to heap if stack buffer exhausted
std::pmr::monotonic_buffer_resource arena(
    stack_buf, sizeof(stack_buf),
    std::pmr::new_delete_resource()   // fallback allocator
);
```

### Writing a PMR-Compatible Allocator

```cpp
class PoolResource : public std::pmr::memory_resource {
public:
    explicit PoolResource(size_t block_size, size_t block_count)
        : block_size_(block_size)
        , storage_(block_size * block_count)
    {
        // Initialize free list
        for (size_t i = 0; i + block_size <= storage_.size(); i += block_size) {
            free_list_.push_back(&storage_[i]);
        }
    }

protected:
    void* do_allocate(size_t bytes, size_t alignment) override {
        if (bytes > block_size_ || free_list_.empty())
            throw std::bad_alloc();
        void* p = free_list_.back();
        free_list_.pop_back();
        return p;
    }

    void do_deallocate(void* p, size_t, size_t) override {
        free_list_.push_back(static_cast<uint8_t*>(p));
    }

    bool do_is_equal(const memory_resource& other) const noexcept override {
        return this == &other;
    }

private:
    size_t               block_size_;
    std::vector<uint8_t> storage_;
    std::vector<void*>   free_list_;
};
```

---

## 6. Putting It Together — Zero-Heap IoT Message Pipeline

Full exercise: a complete message processing pipeline that never calls `malloc` after startup:

```cpp
// zero_heap_pipeline.cpp
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <cstdlib>
#include <cassert>
#include <new>
#include <array>
#include <span>
#include <optional>
#include <string_view>
#include <memory_resource>
#include <vector>

// ---- Pool-allocated message ----

struct MQTTMessage {
    static constexpr size_t MAX_TOPIC   = 64;
    static constexpr size_t MAX_PAYLOAD = 128;

    char     topic[MAX_TOPIC];
    uint8_t  payload[MAX_PAYLOAD];
    uint16_t payload_len;
    uint8_t  qos;
    bool     retain;

    void set_topic(std::string_view t) {
        size_t n = std::min(t.size(), MAX_TOPIC - 1);
        std::memcpy(topic, t.data(), n);
        topic[n] = '\0';
    }

    void set_payload(std::span<const uint8_t> p) {
        payload_len = static_cast<uint16_t>(
            std::min(p.size(), MAX_PAYLOAD)
        );
        std::memcpy(payload, p.data(), payload_len);
    }

    void print() const {
        printf("  MQTT{topic='%s' qos=%u retain=%d payload=%u bytes}\n",
               topic, qos, retain, payload_len);
    }
};

// ---- Pool for messages — 16 slots ----

template<typename T, size_t N>
class PoolAllocator {
    static constexpr size_t BLOCK = sizeof(T) > sizeof(void*)
                                  ? sizeof(T) : sizeof(void*);
    using Block = std::array<alignas(T) uint8_t, BLOCK>;

public:
    PoolAllocator() {
        free_ = reinterpret_cast<void**>(pool_[0].data());
        void** cur = free_;
        for (size_t i = 1; i < N; ++i) {
            *cur = reinterpret_cast<void**>(pool_[i].data());
            cur  = reinterpret_cast<void**>(*cur);
        }
        *cur  = nullptr;
        free_count_ = N;
    }

    PoolAllocator(const PoolAllocator&)            = delete;
    PoolAllocator& operator=(const PoolAllocator&) = delete;

    template<typename... Args>
    T* construct(Args&&... args) {
        void* blk = alloc();
        if (!blk) return nullptr;
        return new (blk) T(std::forward<Args>(args)...);
    }

    void destroy(T* p) {
        if (!p) return;
        p->~T();
        dealloc(p);
    }

    size_t free_count() const { return free_count_; }
    size_t used_count() const { return N - free_count_; }
    bool   full()       const { return free_count_ == 0; }
    bool   empty()      const { return free_count_ == N; }

private:
    void* alloc() {
        if (!free_) return nullptr;
        void* b = free_;
        free_ = reinterpret_cast<void**>(*free_);
        --free_count_;
        return b;
    }
    void dealloc(void* p) {
        *reinterpret_cast<void**>(p) = free_;
        free_ = reinterpret_cast<void**>(p);
        ++free_count_;
    }

    std::array<Block, N> pool_;
    void**  free_       = nullptr;
    size_t  free_count_ = 0;
};

// ---- Arena for temporary parse state ----

class ArenaAllocator {
public:
    ArenaAllocator(void* buf, size_t size)
        : begin_(static_cast<uint8_t*>(buf))
        , current_(begin_)
        , end_(begin_ + size)
    {}

    void* allocate(size_t size, size_t align = alignof(std::max_align_t)) {
        uintptr_t a = (reinterpret_cast<uintptr_t>(current_) + align - 1)
                    & ~(align - 1);
        uint8_t*  p = reinterpret_cast<uint8_t*>(a);
        if (p + size > end_) return nullptr;
        current_ = p + size;
        return p;
    }

    template<typename T, typename... Args>
    T* construct(Args&&... args) {
        void* m = allocate(sizeof(T), alignof(T));
        if (!m) return nullptr;
        return new (m) T(std::forward<Args>(args)...);
    }

    void   reset()     { current_ = begin_; }
    size_t used()      const { return static_cast<size_t>(current_ - begin_); }
    size_t remaining() const { return static_cast<size_t>(end_ - current_); }

private:
    uint8_t* begin_;
    uint8_t* current_;
    uint8_t* end_;
};

// ---- Ring buffer for message pointers ----

template<typename T, size_t N>
class PointerRing {
    static_assert((N & (N-1)) == 0, "N must be power of 2");
public:
    bool push(T* p) {
        if (count_ == N) return false;
        buf_[head_++ & (N-1)] = p;
        ++count_;
        return true;
    }
    T* pop() {
        if (count_ == 0) return nullptr;
        T* p = buf_[tail_++ & (N-1)];
        --count_;
        return p;
    }
    size_t size()  const { return count_; }
    bool   empty() const { return count_ == 0; }
    bool   full()  const { return count_ == N; }

private:
    std::array<T*, N> buf_{};
    size_t head_  = 0;
    size_t tail_  = 0;
    size_t count_ = 0;
};

// ---- Pipeline ----

class MessagePipeline {
public:
    MessagePipeline() {
        printf("[pipeline] initialized\n");
        printf("  Pool:  %zu messages × %zu bytes = %zu bytes (static)\n",
               POOL_SIZE, sizeof(MQTTMessage),
               POOL_SIZE * sizeof(MQTTMessage));
        printf("  Arena: %zu bytes (static)\n", ARENA_SIZE);
    }

    // Receive a raw frame — parse into pool-allocated message
    bool receive(std::string_view topic,
                 std::span<const uint8_t> payload,
                 uint8_t qos = 0, bool retain = false)
    {
        if (pool_.full()) {
            printf("  [pipeline] POOL FULL — dropping message\n");
            return false;
        }

        MQTTMessage* msg = pool_.construct();
        if (!msg) return false;

        msg->set_topic(topic);
        msg->set_payload(payload);
        msg->qos    = qos;
        msg->retain = retain;

        if (!inbox_.push(msg)) {
            pool_.destroy(msg);
            printf("  [pipeline] QUEUE FULL — dropping message\n");
            return false;
        }

        printf("  [pipeline] received '%s' (pool used=%zu)\n",
               msg->topic, pool_.used_count());
        return true;
    }

    // Process one message from the queue
    bool process_one() {
        MQTTMessage* msg = inbox_.pop();
        if (!msg) return false;

        // Use arena for temporary processing state
        arena_.reset();

        // Simulate: build a response in the arena
        struct Response {
            char   topic[64];
            float  value;
            uint32_t timestamp;
        };

        auto* resp = arena_.construct<Response>();
        if (resp) {
            snprintf(resp->topic, sizeof(resp->topic),
                     "%s/ack", msg->topic);
            resp->value     = 0.0f;
            resp->timestamp = 12345u;
            printf("  [pipeline] processed '%s' → ack='%s' (arena used=%zu)\n",
                   msg->topic, resp->topic, arena_.used());
        }

        // arena_.reset() frees resp without calling destructor
        // (Response is trivial — safe here)
        // For non-trivial types: call destructor manually before reset

        // Return message to pool
        pool_.destroy(msg);
        printf("  [pipeline] message returned to pool (pool free=%zu)\n",
               pool_.free_count());
        return true;
    }

    // Drain the inbox
    void process_all() {
        while (process_one()) {}
    }

    void print_stats() const {
        printf("\n[pipeline] stats:\n");
        printf("  pool: %zu used / %zu total\n",
               pool_.used_count(), POOL_SIZE);
        printf("  inbox: %zu queued\n", inbox_.size());
        printf("  arena: %zu / %zu bytes used\n",
               arena_.used(), ARENA_SIZE);
    }

private:
    static constexpr size_t POOL_SIZE  = 16;
    static constexpr size_t ARENA_SIZE = 1024;

    PoolAllocator<MQTTMessage, POOL_SIZE> pool_;
    PointerRing<MQTTMessage, 16>          inbox_;

    alignas(std::max_align_t) uint8_t     arena_buf_[ARENA_SIZE];
    ArenaAllocator                        arena_{arena_buf_, ARENA_SIZE};
};

// ---- std::pmr demonstration ----

void pmr_demo() {
    printf("\n=== std::pmr demonstration ===\n");

    // Stack buffer — no heap
    alignas(std::max_align_t) uint8_t stack_buf[2048];
    std::pmr::monotonic_buffer_resource arena(stack_buf, sizeof(stack_buf));

    // Vector using stack memory
    std::pmr::vector<float> readings(&arena);
    readings.reserve(64);
    for (int i = 0; i < 10; ++i) readings.push_back(static_cast<float>(i) * 1.1f);

    printf("  pmr::vector with %zu elements (stack-allocated)\n",
           readings.size());

    // Nested allocation — string also uses the arena
    std::pmr::vector<std::pmr::string> topics(&arena);
    topics.emplace_back("sensors/temperature", &arena);
    topics.emplace_back("sensors/humidity",    &arena);
    topics.emplace_back("sensors/pressure",    &arena);

    printf("  pmr::vector<pmr::string> with %zu topics\n", topics.size());
    for (const auto& t : topics) printf("    %s\n", t.c_str());

    // Compute total bytes used from stack_buf
    // (monotonic_buffer_resource doesn't expose used bytes directly,
    //  but we can verify nothing went to heap by not seeing any malloc)
    printf("  All allocations from stack_buf — no heap used\n");
}

int main() {
    printf("=== Zero-Heap IoT Pipeline ===\n\n");

    MessagePipeline pipeline;

    // Simulate incoming messages
    printf("\n--- Receiving messages ---\n");
    const uint8_t temp_payload[] = {0x9A, 0x99, 0xBB, 0x41};  // 23.45f
    const uint8_t hum_payload[]  = {0x00, 0x41};
    const uint8_t pres_payload[] = {0x01, 0x02, 0x03, 0x04};

    pipeline.receive("sensors/temperature/dev_01",
                     std::span{temp_payload}, 1, false);
    pipeline.receive("sensors/humidity/dev_01",
                     std::span{hum_payload},  0, false);
    pipeline.receive("sensors/pressure/dev_01",
                     std::span{pres_payload}, 0, true);

    pipeline.print_stats();

    // Process messages
    printf("\n--- Processing messages ---\n");
    pipeline.process_all();

    pipeline.print_stats();

    // Stress test — fill the pool
    printf("\n--- Pool stress test ---\n");
    for (int i = 0; i < 20; ++i) {
        uint8_t p[] = {static_cast<uint8_t>(i)};
        char topic[32];
        snprintf(topic, sizeof(topic), "sensors/stress/%d", i);
        bool ok = pipeline.receive(topic, std::span{p});
        if (!ok) printf("  message %d dropped\n", i);
    }

    pipeline.print_stats();
    pipeline.process_all();
    pipeline.print_stats();

    // std::pmr demo
    pmr_demo();

    printf("\nDone — no heap allocations after startup.\n");
    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address -o zero_heap zero_heap_pipeline.cpp
./zero_heap
```

To verify zero heap allocation after startup, run under Valgrind:

```bash
valgrind --tool=massif --pages-as-heap=yes ./zero_heap
ms_print massif.out.* | head -40
```

Or use `LD_PRELOAD` to intercept malloc:

```bash
# Write a malloc interceptor
cat > malloc_tracker.cpp << 'EOF'
#include <cstdio>
extern "C" {
    void* malloc(size_t s);
    void  __real_malloc(size_t);
}
// This approach requires linker wrapping — simpler: just use AddressSanitizer
// with malloc_stats() before and after
EOF
```

### What to observe

The pool allocator's free list is embedded in the free blocks — when `free_count_` drops to 0 and you try to `receive` more messages, it correctly returns false and the message is dropped rather than allocating from the heap. The dropped message count tells you whether your pool is sized correctly for your message rate.

The arena reset after each `process_one` call means the temporary `Response` object is "freed" in one pointer assignment — no destructor called, no free list update. For trivial types this is safe. For types with non-trivial destructors, call the destructor manually before `reset()`.

---

## Key Takeaways for Day 18

- `new T(args)` = `operator new(sizeof(T))` + placement new. Separating them gives you control over where objects live
- Placement new constructs an object at a provided address — no allocation. The storage must be properly aligned (`alignas(T)`) and large enough (`sizeof(T)`)
- Pool allocators: fixed-size blocks, O(1) alloc/dealloc, zero fragmentation, deterministic timing — right for embedded message queues, event objects, sensor readings
- Arena allocators: bump pointer, O(1) alloc, no individual dealloc — free everything at once with `reset()`. Right for temporary parse state, per-request allocations, frame processing
- The free list embedded in free blocks is the standard embedded trick — same memory serves as data storage when allocated and as metadata when free
- `std::pmr` is the C++17 standard interface for custom allocators — `pmr::vector`, `pmr::string`, and `pmr::unordered_map` accept a `memory_resource*` and use it for all allocations
- `std::pmr::monotonic_buffer_resource` is a standard arena allocator — use it with a stack buffer for zero-heap-after-startup standard containers
- Mark deallocation functions `noexcept` — they run in destructors which must not throw

Day 19 starts Phase 4: concurrency. We build on today's allocators by implementing a thread-safe sensor queue — `std::thread`, `std::mutex`, `std::lock_guard`, and the exact patterns that prevent deadlock and data races.