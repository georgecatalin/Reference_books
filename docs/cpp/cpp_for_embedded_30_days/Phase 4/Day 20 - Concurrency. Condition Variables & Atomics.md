

Day 19's consumer thread spun in a loop calling `try_pop()` and sleeping 1ms when the queue was empty. That's a polling loop — it wastes CPU, adds latency (up to 1ms per reading), and doesn't scale. Condition variables solve this: the consumer sleeps until the producer wakes it. Atomics solve the other case: single variables that need to be read/written safely without a full mutex. Today we replace polling with proper blocking and understand exactly what memory ordering means on real hardware.

---

## 1. The Problem With Polling

```cpp
// Day 19 consumer — polling
while (running_.load() || !queue_.empty()) {
    auto reading = queue_.try_pop();
    if (!reading) {
        std::this_thread::sleep_for(1ms);  // problems:
        continue;                           // 1. up to 1ms extra latency
    }                                       // 2. wastes CPU even when idle
    process(*reading);                      // 3. sleep duration is arbitrary
}
```

The right solution: the consumer blocks when the queue is empty, wakes immediately when data arrives. No sleep, no arbitrary latency, zero CPU when idle.

---

## 2. Condition Variables — The Mechanism

A condition variable lets one thread sleep until another thread signals it. It always works with a mutex — the mutex protects the shared state, the condition variable is the signaling channel.

```cpp
#include <condition_variable>
#include <mutex>

std::mutex              mtx;
std::condition_variable cv;
bool                    data_ready = false;

// Consumer thread — waits for data
void consumer() {
    std::unique_lock<std::mutex> lock(mtx);
    cv.wait(lock, []{ return data_ready; });  // atomic: unlock, sleep, relock on wake
    // lock is held here, data_ready is true
    process_data();
}

// Producer thread — signals consumer
void producer() {
    {
        std::lock_guard<std::mutex> lock(mtx);
        prepare_data();
        data_ready = true;
    }  // unlock before notify — avoids immediate contention
    cv.notify_one();   // wake one waiting thread
}
```

### The Wait Loop — Why the Predicate Is Mandatory

`cv.wait(lock)` without a predicate wakes up on spurious wakeups — the OS may wake the thread for no reason. The predicate version `cv.wait(lock, pred)` rechecks the condition after every wakeup:

```cpp
// Equivalent to:
while (!pred()) {
    cv.wait(lock);   // unlock, sleep, relock — may spuriously wake
}
// pred() is true here — guaranteed

// This is why you always pass a predicate — never write:
cv.wait(lock);  // wrong — spurious wakeup exits without data
```

### `notify_one` vs `notify_all`

```cpp
cv.notify_one();   // wake one waiting thread — for single consumer
cv.notify_all();   // wake all waiting threads — for broadcast events
```

For a queue with one consumer, `notify_one` is correct. For a shutdown signal where all threads need to stop, `notify_all`. Calling `notify_one` when no thread is waiting is safe — the notification is lost, but that's fine if the producer checks the predicate before waiting.

### The Notify-Outside-Lock Pattern

```cpp
// Good — notify after unlock
{
    std::lock_guard lock(mtx);
    data_ready = true;
}  // unlock here
cv.notify_one();  // notify here — consumer wakes and immediately gets lock

// Acceptable but suboptimal — notify while holding lock
{
    std::lock_guard lock(mtx);
    data_ready = true;
    cv.notify_one();  // consumer wakes but blocks immediately waiting for lock
}  // unlock here — consumer finally gets lock
```

Both are correct. The first avoids a context switch into a blocked consumer. In practice for IoT code, the difference rarely matters — prefer whichever is clearer.

---

## 3. `std::atomic<T>` — Lock-Free Primitives

An atomic operation executes as a single, indivisible unit. No other thread can observe it half-done. For simple types — booleans, integers, pointers — atomics give you thread-safe access without a mutex.

```cpp
#include <atomic>

std::atomic<bool>     running(true);     // atomic flag
std::atomic<int>      counter(0);        // atomic counter
std::atomic<uint32_t> sequence(0);       // atomic sequence number
std::atomic<float>    last_reading(0.0f); // atomic float (C++20: wait/notify)

// All basic operations are atomic — no race possible
running.store(false);                   // atomic write
bool r = running.load();                // atomic read
counter.fetch_add(1);                   // atomic increment — returns old value
counter++;                              // same — operator overloaded
int old = counter.exchange(0);         // atomic swap — returns old value

// Compare-and-swap — the fundamental lock-free primitive
int expected = 5;
bool swapped = counter.compare_exchange_strong(expected, 10);
// If counter == 5: sets counter = 10, returns true
// If counter != 5: sets expected = counter's current value, returns false
```

### What `std::atomic` Compiles To

On x86-64, `std::atomic<int>` operations compile to single instructions:

```cpp
counter.fetch_add(1);
// → lock xadd [counter], 1    (single locked instruction)

counter.store(0);
// → mov [counter], 0           (store is naturally atomic on x86)

counter.load();
// → mov eax, [counter]         (load is naturally atomic on x86)
```

On ARM (Cortex-A, your typical IoT SoC):

```cpp
counter.fetch_add(1);
// → ldaex r0, [counter]   (load-acquire exclusive)
// → add   r1, r0, #1
// → stlex r2, r1, [counter]  (store-release exclusive)
// → cbnz  r2, retry           (retry if exclusive failed)
```

The ARM implementation uses load-exclusive/store-exclusive pairs — the hardware tracks if another core touched the memory between the load and store. If it did, the store fails and the loop retries. This is the hardware mechanism behind all lock-free algorithms.

---

## 4. Memory Ordering — What It Actually Means

Memory ordering specifies the visibility guarantees of an atomic operation with respect to other memory operations. This is where hardware realities meet the C++ abstract machine.

Modern CPUs reorder instructions for performance. Compilers reorder instructions for optimization. Memory ordering tells both the compiler and the CPU what reorderings are permitted.

```cpp
std::atomic<int> x(0), y(0);

// Thread 1:
x.store(1, std::memory_order_relaxed);
y.store(2, std::memory_order_relaxed);

// Thread 2:
int ry = y.load(std::memory_order_relaxed);
int rx = x.load(std::memory_order_relaxed);
// With relaxed ordering, Thread 2 might observe ry=2, rx=0
// — the stores appear in a different order to other threads
```

### The Six Memory Orders

```cpp
std::memory_order_relaxed   // no ordering — just atomicity
std::memory_order_consume   // data dependency ordering (deprecated in practice)
std::memory_order_acquire   // no reads/writes can move before this load
std::memory_order_release   // no reads/writes can move after this store
std::memory_order_acq_rel   // both acquire and release (for read-modify-write)
std::memory_order_seq_cst   // sequential consistency — default, strongest
```

### The Acquire-Release Pattern — Producer/Consumer

The most important pattern for IoT code: one thread produces data and sets a flag; another thread checks the flag and reads the data.

```cpp
std::atomic<bool>  ready(false);
float              sensor_value = 0.0f;  // non-atomic shared data

// Producer:
sensor_value = 23.5f;                          // write data
ready.store(true, std::memory_order_release);  // RELEASE: all writes above
                                                // are visible before this store

// Consumer:
while (!ready.load(std::memory_order_acquire)) {}  // ACQUIRE: all reads below
                                                    // see writes from before the release
float v = sensor_value;  // guaranteed to see 23.5f
```

The release store creates a "happens-before" relationship: everything written before the release is visible to any thread that performs an acquire load and sees the released value. Without this, the compiler or CPU might reorder the `sensor_value` write to happen after `ready = true` — the consumer would read a stale value.

### Relaxed vs Acquire/Release vs Sequential Consistency

```cpp
// relaxed — just atomicity, maximum CPU/compiler freedom
// Use for: counters where only the final value matters
std::atomic<uint64_t> total_messages(0);
total_messages.fetch_add(1, std::memory_order_relaxed);  // fine

// acquire/release — the standard producer/consumer pattern
// Use for: flags that signal completion of writes
ready.store(true,  std::memory_order_release);  // producer
ready.load(std::memory_order_acquire);           // consumer

// sequential consistency (default) — globally consistent ordering
// Use for: when in doubt, when correctness > performance
std::atomic<int> x(0);
x.store(1);              // seq_cst — same as memory_order_seq_cst
x.load();                // seq_cst
```

**Practical advice:** use `seq_cst` (the default) until you have a measured performance problem. `relaxed` is only for counters and statistics. `acquire/release` for the producer/consumer flag pattern. Never use `relaxed` for flags that synchronize non-atomic data — it won't work on ARM.

---

## 5. `atomic_flag` — The Minimal Atomic

`std::atomic_flag` is guaranteed lock-free on every platform. It's a boolean that supports only `test_and_set()` and `clear()`:

```cpp
std::atomic_flag flag = ATOMIC_FLAG_INIT;

// Spinlock implementation using atomic_flag
class SpinLock {
    std::atomic_flag flag_ = ATOMIC_FLAG_INIT;
public:
    void lock() {
        while (flag_.test_and_set(std::memory_order_acquire)) {
            // spin — or add std::this_thread::yield() to be polite
        }
    }
    void unlock() {
        flag_.clear(std::memory_order_release);
    }
};
```

Spinlocks are appropriate for very short critical sections where the lock is almost never contended — like updating a single counter in an ISR. For longer critical sections, a mutex is better — it yields the CPU while waiting.

---

## 6. `std::atomic<T>::wait` and `notify` (C++20)

C++20 adds `wait()`, `notify_one()`, and `notify_all()` to `atomic<T>`, enabling condition-variable-like blocking without a separate mutex:

```cpp
std::atomic<int> value(0);

// Consumer — blocks until value changes from 0
void consumer() {
    value.wait(0);   // blocks as long as value == 0
    printf("Value is now %d\n", value.load());
}

// Producer
void producer() {
    value.store(42);
    value.notify_one();   // wake consumer
}
```

For C++17, use condition variables. For C++20 where you need to wait on a simple atomic value, `atomic::wait` is cleaner.

---

## 7. Putting It Together — Blocking Queue with Shutdown

Replace Day 19's polling queue with a proper blocking queue:

```cpp
// blocking_queue.cpp
#include <cstdio>
#include <cstdint>
#include <thread>
#include <mutex>
#include <condition_variable>
#include <queue>
#include <vector>
#include <atomic>
#include <optional>
#include <chrono>
#include <cassert>
#include <string>

using namespace std::chrono_literals;

// ---- Sensor reading ----

struct SensorReading {
    uint8_t  sensor_id;
    float    value;
    uint32_t timestamp_ms;

    void print() const {
        printf("  [t=%4u id=%u] %.3f\n", timestamp_ms, sensor_id, value);
    }
};

// ---- Blocking bounded queue ----

template<typename T>
class BlockingQueue {
public:
    explicit BlockingQueue(size_t capacity)
        : capacity_(capacity)
        , shutdown_(false)
        , pushed_(0)
        , popped_(0)
        , dropped_(0)
    {}

    BlockingQueue(const BlockingQueue&)            = delete;
    BlockingQueue& operator=(const BlockingQueue&) = delete;

    // Push — blocks if full, returns false if shut down
    bool push(T item) {
        std::unique_lock<std::mutex> lock(mtx_);

        // Wait until there's space OR shutdown
        not_full_.wait(lock, [this] {
            return queue_.size() < capacity_ || shutdown_;
        });

        if (shutdown_) return false;

        queue_.push(std::move(item));
        ++pushed_;

        lock.unlock();
        not_empty_.notify_one();   // wake a consumer
        return true;
    }

    // Try push — non-blocking, returns false if full or shut down
    bool try_push(T item) {
        std::lock_guard<std::mutex> lock(mtx_);
        if (shutdown_ || queue_.size() >= capacity_) {
            ++dropped_;
            return false;
        }
        queue_.push(std::move(item));
        ++pushed_;
        not_empty_.notify_one();
        return true;
    }

    // Pop — blocks until item available OR shutdown
    std::optional<T> pop() {
        std::unique_lock<std::mutex> lock(mtx_);

        // Wait until there's data OR shutdown
        not_empty_.wait(lock, [this] {
            return !queue_.empty() || shutdown_;
        });

        if (queue_.empty()) return std::nullopt;  // shutdown with empty queue

        T item = std::move(queue_.front());
        queue_.pop();
        ++popped_;

        lock.unlock();
        not_full_.notify_one();   // wake a producer if it was blocked
        return item;
    }

    // Pop with timeout — returns nullopt if timeout expires
    std::optional<T> pop_for(std::chrono::milliseconds timeout) {
        std::unique_lock<std::mutex> lock(mtx_);

        bool got_data = not_empty_.wait_for(lock, timeout, [this] {
            return !queue_.empty() || shutdown_;
        });

        if (!got_data || queue_.empty()) return std::nullopt;

        T item = std::move(queue_.front());
        queue_.pop();
        ++popped_;

        lock.unlock();
        not_full_.notify_one();
        return item;
    }

    // Signal shutdown — unblocks all waiting threads
    void shutdown() {
        {
            std::lock_guard<std::mutex> lock(mtx_);
            shutdown_ = true;
        }
        not_empty_.notify_all();  // wake all consumers
        not_full_.notify_all();   // wake all producers
        printf("[queue] shutdown signaled\n");
    }

    bool is_shutdown() const {
        std::lock_guard<std::mutex> lock(mtx_);
        return shutdown_;
    }

    // State
    size_t size() const {
        std::lock_guard<std::mutex> lock(mtx_);
        return queue_.size();
    }

    bool empty() const {
        std::lock_guard<std::mutex> lock(mtx_);
        return queue_.empty();
    }

    uint64_t pushed()  const { return pushed_.load(std::memory_order_relaxed); }
    uint64_t popped()  const { return popped_.load(std::memory_order_relaxed); }
    uint64_t dropped() const { return dropped_.load(std::memory_order_relaxed); }

private:
    mutable std::mutex      mtx_;
    std::condition_variable not_empty_;  // signaled when item added
    std::condition_variable not_full_;   // signaled when item removed
    std::queue<T>           queue_;
    size_t                  capacity_;
    bool                    shutdown_;

    std::atomic<uint64_t>   pushed_;
    std::atomic<uint64_t>   popped_;
    std::atomic<uint64_t>   dropped_;
};

// ---- JThread RAII ----

class JThread {
public:
    template<typename F>
    explicit JThread(F&& f) : thread_(std::forward<F>(f)) {}
    ~JThread() { if (thread_.joinable()) thread_.join(); }
    JThread(const JThread&)            = delete;
    JThread& operator=(const JThread&) = delete;
    JThread(JThread&&) noexcept            = default;
    JThread& operator=(JThread&&) noexcept = default;
    void join() { thread_.join(); }

private:
    std::thread thread_;
};

// ---- Atomic flag demo ----

class AtomicStats {
public:
    void record(float v) {
        // Relaxed — only final count matters, no synchronization needed
        total_count_.fetch_add(1, std::memory_order_relaxed);

        // fetch_add returns old value — use to track running sum
        // Note: atomic<float> doesn't have fetch_add — use mutex or
        // compare_exchange loop
        std::lock_guard lock(sum_mtx_);
        sum_ += v;
    }

    uint64_t count() const {
        return total_count_.load(std::memory_order_relaxed);
    }

    float average() const {
        std::lock_guard lock(sum_mtx_);
        uint64_t n = total_count_.load(std::memory_order_relaxed);
        return n > 0 ? sum_ / static_cast<float>(n) : 0.0f;
    }

    // Acquire/release pattern — flag signals completion of writes
    void mark_complete() {
        complete_.store(true, std::memory_order_release);
    }

    bool is_complete() const {
        return complete_.load(std::memory_order_acquire);
    }

private:
    std::atomic<uint64_t>  total_count_{0};
    std::atomic<bool>      complete_{false};
    mutable std::mutex     sum_mtx_;
    float                  sum_{0.0f};
};

int main() {
    printf("=== Blocking Queue with Condition Variables ===\n\n");

    // ---- Basic blocking queue ----
    printf("--- Single producer / single consumer (blocking) ---\n");
    {
        BlockingQueue<SensorReading> queue(8);
        AtomicStats stats;
        const int READINGS = 20;

        // Producer
        JThread producer([&]() {
            printf("[producer] starting\n");
            for (int i = 0; i < READINGS; ++i) {
                SensorReading r{
                    static_cast<uint8_t>(i % 3),
                    20.0f + static_cast<float>(i) * 0.5f,
                    static_cast<uint32_t>(i * 10)
                };
                if (!queue.push(r)) {
                    printf("[producer] shutdown while pushing\n");
                    break;
                }
                // Simulate variable sensor rate
                if (i % 5 == 0) std::this_thread::sleep_for(5ms);
            }
            printf("[producer] done — pushed %llu\n", queue.pushed());
            queue.shutdown();  // signal consumer to drain and exit
        });

        // Consumer — blocks on empty queue, no polling
        JThread consumer([&]() {
            printf("[consumer] starting — blocking pop\n");
            int count = 0;
            while (auto reading = queue.pop()) {
                stats.record(reading->value);
                ++count;
                if (count % 5 == 0) {
                    printf("[consumer] processed %d  "
                           "avg=%.2f  queue=%zu\n",
                           count, stats.average(), queue.size());
                }
                // Consumer slower than producer sometimes
                if (count % 3 == 0) std::this_thread::sleep_for(3ms);
            }
            printf("[consumer] done — processed %d\n", count);
            stats.mark_complete();
        });
    }

    // ---- pop_for with timeout ----
    printf("\n--- pop_for with timeout ---\n");
    {
        BlockingQueue<int> queue(4);

        JThread producer([&]() {
            std::this_thread::sleep_for(50ms);  // delay before first push
            queue.push(42);
            std::this_thread::sleep_for(200ms); // long pause
            queue.push(99);
            queue.shutdown();
        });

        JThread consumer([&]() {
            for (;;) {
                auto item = queue.pop_for(100ms);  // 100ms timeout
                if (!item) {
                    if (queue.is_shutdown()) {
                        printf("[consumer] shutdown — exiting\n");
                        break;
                    }
                    printf("[consumer] timeout — no data in 100ms\n");
                    continue;
                }
                printf("[consumer] got %d\n", *item);
            }
        });
    }

    // ---- Multiple producers / multiple consumers ----
    printf("\n--- 3 producers / 2 consumers ---\n");
    {
        BlockingQueue<SensorReading> queue(16);
        std::atomic<int> total_produced(0);
        std::atomic<int> total_consumed(0);

        // 3 producers
        std::vector<JThread> producers;
        for (int p = 0; p < 3; ++p) {
            producers.emplace_back([&, p]() {
                for (int i = 0; i < 10; ++i) {
                    SensorReading r{
                        static_cast<uint8_t>(p),
                        static_cast<float>(p * 10 + i),
                        static_cast<uint32_t>(i)
                    };
                    if (queue.push(r)) {
                        total_produced.fetch_add(1, std::memory_order_relaxed);
                    }
                    std::this_thread::sleep_for(1ms);
                }
            });
        }

        // 2 consumers
        std::vector<JThread> consumers;
        for (int c = 0; c < 2; ++c) {
            consumers.emplace_back([&, c]() {
                while (auto r = queue.pop()) {
                    total_consumed.fetch_add(1, std::memory_order_relaxed);
                }
                printf("[consumer %d] done\n", c);
            });
        }

        // Wait for all producers, then shut down
        for (auto& p : producers) p.join();
        printf("All producers done. produced=%d\n",
               total_produced.load());
        queue.shutdown();

        // Consumers exit on shutdown
        for (auto& c : consumers) c.join();
        printf("All consumers done. consumed=%d\n",
               total_consumed.load());

        assert(total_produced.load() == total_consumed.load());
        printf("produced == consumed: %s\n",
               total_produced == total_consumed ? "PASS" : "FAIL");
    }

    // ---- Memory ordering demonstration ----
    printf("\n--- Acquire/release ordering ---\n");
    {
        std::atomic<bool>  ready(false);
        float              sensor_value = 0.0f;

        JThread producer([&]() {
            std::this_thread::sleep_for(10ms);
            sensor_value = 23.456f;
            ready.store(true, std::memory_order_release);
            printf("[producer] released value %.3f\n", sensor_value);
        });

        JThread consumer([&]() {
            while (!ready.load(std::memory_order_acquire)) {
                std::this_thread::yield();
            }
            // Acquire guarantees we see sensor_value = 23.456f
            printf("[consumer] acquired value %.3f\n", sensor_value);
            assert(sensor_value == 23.456f);
        });
    }

    printf("\nAll tests done.\n");
    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=thread -o blocking_queue blocking_queue.cpp -lpthread
./blocking_queue
```

### What to observe

The `not_full_` and `not_empty_` condition variables enable true backpressure. When the queue is full, `push()` blocks instead of dropping. When the queue is empty, `pop()` blocks instead of spinning. The CPU is idle when there's nothing to do.

The `shutdown()` path calls `notify_all()` on both condition variables — this unblocks any producers waiting for space and any consumers waiting for data. After `shutdown_` is set to true, `push()` returns false and `pop()` drains the remaining items then returns `nullopt`. This is the correct shutdown sequence: producers stop, consumers drain, then consumers stop.

The `total_produced == total_consumed` assertion verifies nothing was lost — every item pushed was eventually popped. With the blocking queue and proper shutdown, this must hold.

---

## Key Takeaways for Day 20

- Condition variables always pair with a mutex — the mutex protects the shared state, the CV is the signaling mechanism
- Always pass a predicate to `cv.wait()` — without it, spurious wakeups exit the wait incorrectly. The predicate version is equivalent to `while (!pred()) cv.wait(lock)`
- Notify after releasing the lock — the consumer wakes up without immediately blocking on the lock again. Correct either way, but notify-after-unlock is more efficient
- `notify_one` wakes one waiter (single consumer), `notify_all` wakes all waiters (broadcast shutdown signal)
- `std::atomic<T>` operations are indivisible — no thread sees a half-written value. Compiles to native atomic instructions (lock xadd on x86, ldaex/stlex on ARM)
- `memory_order_relaxed` — atomicity only, no ordering. For counters that don't synchronize other data
- `memory_order_acquire`/`release` — the producer/consumer pattern. Release makes all prior writes visible to any thread that acquires. The most important ordering pair for IoT code
- `memory_order_seq_cst` — the default, strongest, safest. Use it until you have a measured performance reason to weaken
- `compare_exchange_strong` is the fundamental lock-free primitive — test-and-set a value atomically. The foundation of all lock-free data structures
- ThreadSanitizer (`-fsanitize=thread`) catches data races and incorrect uses of atomics at runtime — run it on all concurrent code

Day 21 covers `std::future`, `std::promise`, and `std::async` — single-value async channels for dispatching work to other threads and collecting results without manual synchronization.