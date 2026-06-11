

Phase 3 gave you the language machinery. Phase 4 puts it to work in the context where C++ IoT code actually runs: multiple threads, shared state, and the discipline to keep it correct. Today covers `std::thread`, the mutex family, and the RAII lock wrappers that make deadlock-on-early-return impossible. Everything builds on what you already know — threads are just RAII objects, locks are just RAII wrappers, and the patterns that prevent races are the same ownership discipline you've been applying to memory since Day 7.

---

## 1. What a Thread Is — The Physical Model

A thread is an independent execution context sharing the process's address space. On Linux (which underlies every embedded Linux IoT platform), `std::thread` maps directly to a POSIX thread (`pthread_t`) — there's no abstraction layer, no runtime scheduler of your own. The OS schedules it.

```
Process address space (shared by all threads):
┌─────────────────────────────────────┐
│  Text segment  (code — read-only)   │
│  Data / BSS    (globals, statics)   │
│  Heap          (new/malloc)         │
├─────────────────────────────────────┤
│  Thread 1 stack  ← grows down       │
│  Thread 2 stack  ← grows down       │
│  Thread 3 stack  ← grows down       │
└─────────────────────────────────────┘

Each thread has:
  - Its own stack (default 8MB on Linux)
  - Its own program counter (where it's executing)
  - Its own register file
  - Shared access to everything else
```

The "shared access to everything else" is what makes concurrency hard. Two threads writing to the same memory location simultaneously is a data race — undefined behavior in C++, not just a runtime bug.

---

## 2. `std::thread` — Creating and Managing Threads

```cpp
#include <thread>

// Create a thread — starts immediately
std::thread t([]() {
    printf("Hello from thread %zu\n",
           std::hash<std::thread::id>{}(std::this_thread::get_id()));
});

// Must join or detach before thread object is destroyed
t.join();    // wait for thread to finish — blocks caller
// OR
t.detach();  // let thread run independently — caller doesn't wait
// After detach, t no longer refers to a thread
```

If a `std::thread` object is destroyed while joinable (not yet joined or detached), `std::terminate()` is called — the program crashes. This is intentional: the library forces you to make a decision about thread lifetime.

### The Thread Ownership Problem

```cpp
void bad_example() {
    std::thread t([]() {
        std::this_thread::sleep_for(std::chrono::milliseconds(100));
    });
    // t goes out of scope here — thread is joinable — std::terminate()
}

void good_example() {
    std::thread t([]() {
        std::this_thread::sleep_for(std::chrono::milliseconds(100));
    });
    t.join();  // wait for thread — then t destructor is safe
}
```

### `std::jthread` — RAII Thread (C++20)

C++20 adds `std::jthread`, which automatically joins in the destructor and supports cooperative cancellation:

```cpp
#include <thread>

{
    std::jthread t([](std::stop_token stop) {
        while (!stop.stop_requested()) {
            do_work();
            std::this_thread::sleep_for(std::chrono::milliseconds(10));
        }
        printf("Thread stopping cleanly\n");
    });
    // t goes out of scope — automatically requests stop and joins
}
```

For C++17, implement the same pattern manually:

```cpp
class JThread {
public:
    template<typename F>
    explicit JThread(F&& f) : thread_(std::forward<F>(f)) {}

    ~JThread() {
        if (thread_.joinable()) thread_.join();
    }

    JThread(const JThread&)            = delete;
    JThread& operator=(const JThread&) = delete;
    JThread(JThread&&) noexcept            = default;
    JThread& operator=(JThread&&) noexcept = default;

    std::thread& get() { return thread_; }

private:
    std::thread thread_;
};
```

### Passing Arguments to Threads

```cpp
// Arguments are COPIED into the thread — not passed by reference
int sensor_id = 3;
std::thread t(process_sensor, sensor_id);  // sensor_id is copied

// To pass by reference — use std::ref
std::atomic<bool> running = true;
std::thread t2(sensor_loop, std::ref(running));  // running passed by ref
// WARNING: running must outlive t2 — ensure join before running is destroyed

// Lambda captures are usually cleaner than std::ref
std::thread t3([&running]() {
    while (running.load()) { do_work(); }
});
```

---

## 3. Data Races — The Enemy

A data race occurs when two threads access the same memory location, at least one access is a write, and there's no synchronization between them. Data races are undefined behavior — not "wrong result" behavior, but UB, which means the compiler can assume they don't happen and optimize accordingly in ways that break your program.

```cpp
int counter = 0;  // shared between threads

// Thread 1:
++counter;  // read counter, increment, write counter — THREE operations

// Thread 2:
++counter;  // same three operations

// Possible outcome on x86 assembly:
// T1: load counter (0) into register
// T2: load counter (0) into register
// T1: increment register (1)
// T2: increment register (1)
// T1: store 1 to counter
// T2: store 1 to counter
// Result: counter == 1, not 2 — one increment lost
```

The race window is small — nanoseconds. But embedded systems run for months. You will hit it.

---

## 4. `std::mutex` — Mutual Exclusion

A mutex (mutual exclusion object) ensures only one thread executes a critical section at a time. The thread that locks the mutex is the only one that can unlock it.

```cpp
#include <mutex>

std::mutex mtx;
int counter = 0;

void thread_safe_increment() {
    mtx.lock();
    ++counter;   // critical section — only one thread here at a time
    mtx.unlock();
}
```

**Never call `lock()`/`unlock()` directly.** Use RAII wrappers — for the same reason you never call `delete` directly. An early return or exception between `lock()` and `unlock()` leaves the mutex permanently locked — deadlock.

### The Four Lock Types

```cpp
// 1. std::lock_guard — simplest, locks on construction, unlocks on destruction
{
    std::lock_guard<std::mutex> lock(mtx);
    // critical section
}  // unlocked here — always

// 2. std::unique_lock — flexible: can unlock early, try-lock, time-lock
{
    std::unique_lock<std::mutex> lock(mtx);
    // critical section
    lock.unlock();   // unlock before scope end — for condition variables
    // non-critical work
    lock.lock();     // re-lock
}  // unlocked here if still locked

// 3. std::scoped_lock (C++17) — locks multiple mutexes atomically, no deadlock
{
    std::scoped_lock lock(mtx_a, mtx_b);  // both locked atomically
}  // both unlocked

// 4. std::shared_lock (C++17) — reader-writer lock
std::shared_mutex rw_mutex;
{
    std::shared_lock lock(rw_mutex);   // read lock — multiple readers OK
    // read-only access
}
{
    std::unique_lock lock(rw_mutex);   // write lock — exclusive
    // read-write access
}
```

### Mutex Types

```cpp
std::mutex          mtx;       // basic — non-recursive
std::recursive_mutex r_mtx;   // same thread can lock multiple times
                                // each lock needs a corresponding unlock
std::timed_mutex    t_mtx;    // try_lock_for(), try_lock_until()
std::shared_mutex   rw_mtx;   // reader-writer (C++17)
```

`std::recursive_mutex` is tempting when you have a public function that locks, calling a private function that also tries to lock. But it's usually a design smell — restructure so the private function assumes the lock is held:

```cpp
class SensorManager {
    std::mutex mtx_;

    // Private — assumes lock is held by caller
    void update_internal(float v) {
        readings_.push_back(v);   // no lock — caller holds it
    }

public:
    void update(float v) {
        std::lock_guard lock(mtx_);
        update_internal(v);       // safe — we hold the lock
    }
};
```

---

## 5. Deadlock — How It Happens, How to Prevent It

Deadlock: Thread A holds mutex 1, waits for mutex 2. Thread B holds mutex 2, waits for mutex 1. Both wait forever.

```cpp
std::mutex mtx_a, mtx_b;

void thread_a() {
    std::lock_guard lock_a(mtx_a);   // locks A
    // ... work ...
    std::lock_guard lock_b(mtx_b);   // waits for B — B held by thread_b
}

void thread_b() {
    std::lock_guard lock_b(mtx_b);   // locks B
    // ... work ...
    std::lock_guard lock_a(mtx_a);   // waits for A — A held by thread_a
    // DEADLOCK
}
```

**Prevention strategies:**

```cpp
// Strategy 1: consistent lock ordering — always lock A before B
void thread_a() {
    std::lock_guard lock_a(mtx_a);
    std::lock_guard lock_b(mtx_b);   // consistent order
}
void thread_b() {
    std::lock_guard lock_a(mtx_a);   // same order — no deadlock
    std::lock_guard lock_b(mtx_b);
}

// Strategy 2: std::scoped_lock — acquires multiple locks atomically
void any_thread() {
    std::scoped_lock lock(mtx_a, mtx_b);  // deadlock-free by design
    // both held here
}

// Strategy 3: std::lock + adopt_lock
void any_thread() {
    std::lock(mtx_a, mtx_b);   // locks both atomically
    std::lock_guard la(mtx_a, std::adopt_lock);  // RAII adopt
    std::lock_guard lb(mtx_b, std::adopt_lock);
}
```

Prefer `std::scoped_lock` whenever you need to hold multiple mutexes. It uses a deadlock-avoidance algorithm internally.

---

## 6. Putting It Together — Thread-Safe Sensor Queue

A producer thread (simulating ISR or network receive) pushes sensor readings into a shared queue. A consumer thread processes them. No data races, no deadlock, graceful shutdown:

```cpp
// sensor_queue.cpp
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <thread>
#include <mutex>
#include <condition_variable>
#include <queue>
#include <vector>
#include <atomic>
#include <chrono>
#include <optional>
#include <cassert>
#include <string>

using namespace std::chrono_literals;

// ---- Reading type ----

struct SensorReading {
    uint8_t  sensor_id;
    float    value;
    uint32_t timestamp_ms;

    void print() const {
        printf("  Reading{id=%u val=%.2f t=%u}\n",
               sensor_id, value, timestamp_ms);
    }
};

// ---- Thread-safe bounded queue ----

class SensorQueue {
public:
    explicit SensorQueue(size_t max_size)
        : max_size_(max_size)
        , dropped_(0)
        , total_in_(0)
        , total_out_(0)
    {}

    // Non-copyable, non-movable — mutex makes it immovable
    SensorQueue(const SensorQueue&)            = delete;
    SensorQueue& operator=(const SensorQueue&) = delete;

    // Push from producer — returns false if queue full (non-blocking)
    bool try_push(SensorReading reading) {
        std::lock_guard<std::mutex> lock(mtx_);
        if (queue_.size() >= max_size_) {
            ++dropped_;
            return false;
        }
        queue_.push(std::move(reading));
        ++total_in_;
        return true;
    }

    // Pop from consumer — returns nullopt if empty (non-blocking)
    std::optional<SensorReading> try_pop() {
        std::lock_guard<std::mutex> lock(mtx_);
        if (queue_.empty()) return std::nullopt;
        SensorReading r = std::move(queue_.front());
        queue_.pop();
        ++total_out_;
        return r;
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

    uint64_t dropped()   const { return dropped_.load(); }
    uint64_t total_in()  const { return total_in_.load(); }
    uint64_t total_out() const { return total_out_.load(); }

private:
    mutable std::mutex            mtx_;
    std::queue<SensorReading>     queue_;
    size_t                        max_size_;
    std::atomic<uint64_t>         dropped_;
    std::atomic<uint64_t>         total_in_;
    std::atomic<uint64_t>         total_out_;
};

// ---- Statistics — protected by its own mutex ----

struct ProcessingStats {
    mutable std::mutex mtx;
    std::vector<float> values;
    uint32_t           processed = 0;

    void record(float v) {
        std::lock_guard lock(mtx);
        values.push_back(v);
        ++processed;
    }

    float average() const {
        std::lock_guard lock(mtx);
        if (values.empty()) return 0.0f;
        float sum = 0;
        for (float v : values) sum += v;
        return sum / static_cast<float>(values.size());
    }

    uint32_t count() const {
        std::lock_guard lock(mtx);
        return processed;
    }
};

// ---- Producer — simulates sensor data arriving ----

class SensorProducer {
public:
    SensorProducer(SensorQueue& queue,
                   std::atomic<bool>& running,
                   int sensor_count,
                   int readings_per_sensor)
        : queue_(queue)
        , running_(running)
        , sensor_count_(sensor_count)
        , readings_per_sensor_(readings_per_sensor)
    {}

    void run() {
        printf("[producer] starting — %d sensors × %d readings\n",
               sensor_count_, readings_per_sensor_);

        uint32_t timestamp = 0;
        int total = 0;

        for (int r = 0; r < readings_per_sensor_ && running_.load(); ++r) {
            for (int s = 0; s < sensor_count_ && running_.load(); ++s) {
                SensorReading reading{
                    static_cast<uint8_t>(s),
                    20.0f + static_cast<float>(s) * 1.5f
                        + static_cast<float>(r) * 0.1f,
                    timestamp
                };
                ++timestamp;

                if (!queue_.try_push(reading)) {
                    // Queue full — backpressure: wait briefly
                    std::this_thread::sleep_for(1ms);
                    --r;  // retry this reading
                    break;
                }
                ++total;
            }
            std::this_thread::sleep_for(2ms);  // simulate sensor rate
        }

        printf("[producer] done — pushed %d readings "
               "(dropped=%llu)\n", total, queue_.dropped());
    }

private:
    SensorQueue&        queue_;
    std::atomic<bool>&  running_;
    int                 sensor_count_;
    int                 readings_per_sensor_;
};

// ---- Consumer — processes readings from queue ----

class SensorConsumer {
public:
    SensorConsumer(SensorQueue&       queue,
                   std::atomic<bool>& running,
                   ProcessingStats&   stats)
        : queue_(queue)
        , running_(running)
        , stats_(stats)
    {}

    void run() {
        printf("[consumer] starting\n");
        int processed = 0;

        while (running_.load() || !queue_.empty()) {
            auto reading = queue_.try_pop();
            if (!reading) {
                // Queue empty — yield and retry
                std::this_thread::sleep_for(1ms);
                continue;
            }

            // Process the reading
            stats_.record(reading->value);
            ++processed;

            if (processed % 10 == 0) {
                printf("[consumer] processed %d  avg=%.2f  queue=%zu\n",
                       processed,
                       stats_.average(),
                       queue_.size());
            }
        }

        printf("[consumer] done — processed %d readings\n", processed);
    }

private:
    SensorQueue&        queue_;
    std::atomic<bool>&  running_;
    ProcessingStats&    stats_;
};

// ---- JThread RAII wrapper (C++17) ----

class JThread {
public:
    template<typename F>
    explicit JThread(F&& f)
        : thread_(std::forward<F>(f)) {}

    ~JThread() {
        if (thread_.joinable()) {
            printf("[JThread] auto-joining on destruction\n");
            thread_.join();
        }
    }

    JThread(const JThread&)            = delete;
    JThread& operator=(const JThread&) = delete;
    JThread(JThread&&) noexcept            = default;
    JThread& operator=(JThread&&) noexcept = default;

    void join() { thread_.join(); }
    bool joinable() const { return thread_.joinable(); }

private:
    std::thread thread_;
};

int main() {
    printf("=== Thread-Safe Sensor Queue ===\n\n");

    // Shared state
    SensorQueue        queue(32);              // bounded — max 32 readings
    std::atomic<bool>  running(true);
    ProcessingStats    stats;

    // ---- Single producer, single consumer ----
    printf("--- Single producer / single consumer ---\n");
    {
        SensorProducer producer(queue, running, 3, 15);
        SensorConsumer consumer(queue, running, stats);

        JThread consumer_thread([&consumer]() { consumer.run(); });
        JThread producer_thread([&producer]() { producer.run(); });

        producer_thread.join();    // wait for producer to finish
        running.store(false);      // signal consumer to stop after draining
        consumer_thread.join();    // wait for consumer to drain and stop
        running.store(true);       // reset for next test
    }

    printf("\nStats: processed=%u  avg=%.2f\n",
           stats.count(), stats.average());
    printf("Queue: total_in=%llu  total_out=%llu  dropped=%llu\n",
           queue.total_in(), queue.total_out(), queue.dropped());

    // ---- Multiple producers ----
    printf("\n--- Multiple producers (3) / single consumer ---\n");
    {
        SensorConsumer consumer(queue, running, stats);
        JThread consumer_thread([&consumer]() { consumer.run(); });

        // Three producers simultaneously
        std::vector<JThread> producers;
        for (int i = 0; i < 3; ++i) {
            producers.emplace_back([&queue, &running, i]() {
                SensorProducer p(queue, running, 2, 10);
                p.run();
            });
        }

        // Join all producers
        for (auto& p : producers) p.join();

        running.store(false);
        consumer_thread.join();
        running.store(true);
    }

    printf("\nFinal stats: processed=%u  avg=%.2f\n",
           stats.count(), stats.average());

    // ---- Thread ID and hardware concurrency ----
    printf("\n--- Thread info ---\n");
    printf("Main thread id: %zu\n",
           std::hash<std::thread::id>{}(std::this_thread::get_id()));
    printf("Hardware concurrency: %u logical CPUs\n",
           std::thread::hardware_concurrency());

    // ---- scoped_lock demonstration ----
    printf("\n--- Multi-mutex with scoped_lock ---\n");
    std::mutex mtx_a, mtx_b;
    int shared_a = 0, shared_b = 0;

    auto worker = [&](int id) {
        for (int i = 0; i < 5; ++i) {
            std::scoped_lock lock(mtx_a, mtx_b);  // both locked atomically
            shared_a += id;
            shared_b += id * 2;
            std::this_thread::sleep_for(1ms);
        }
    };

    {
        JThread t1([&]() { worker(1); });
        JThread t2([&]() { worker(2); });
        JThread t3([&]() { worker(3); });
        // all auto-joined on scope exit
    }

    printf("shared_a=%d (expected %d)  shared_b=%d (expected %d)\n",
           shared_a, 5*(1+2+3),
           shared_b, 5*(2+4+6));

    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=thread -o sensor_queue sensor_queue.cpp -lpthread
./sensor_queue
```

**Run under ThreadSanitizer (`-fsanitize=thread`) — not AddressSanitizer — for this file.** TSan detects data races at runtime. A clean run confirms the synchronization is correct.

### What to observe

`mutable std::mutex mtx_` in `SensorQueue` — `mutable` allows the mutex to be locked even in `const` member functions (`size()`, `empty()`). Without `mutable`, those `const` functions couldn't call `lock()`. This is the one legitimate use of `mutable` — internal synchronization state that doesn't affect the logical constness of the object.

The `dropped_`, `total_in_`, `total_out_` counters are `std::atomic<uint64_t>` rather than plain integers protected by the mutex. They're updated in the same critical section as the queue, but they're also read without the lock in the stats printout. Using `atomic` lets the non-locked reads be safe — we cover atomics fully on Day 20.

The `while (running_.load() || !queue_.empty())` drain condition in the consumer is the correct shutdown sequence. The producer signals `running_ = false`, and the consumer keeps draining until the queue is empty. Without the `!queue_.empty()` check, the consumer might stop before processing all readings.

---

## Key Takeaways for Day 19

- `std::thread` maps to a native OS thread — it starts immediately on construction and must be joined or detached before destruction (or `std::terminate` is called)
- `std::jthread` (C++20) auto-joins in the destructor and supports `stop_token` cancellation — use the manual `JThread` wrapper for C++17
- Data races are undefined behavior — two threads accessing shared data without synchronization, at least one writing, is not "might give wrong result" but "program behavior is undefined"
- `std::lock_guard` is the RAII mutex wrapper for simple cases — locks on construction, unlocks on destruction, always. Never use raw `lock()`/`unlock()`
- `std::scoped_lock` locks multiple mutexes atomically using deadlock-avoidance — use it whenever you need to hold more than one mutex simultaneously
- `std::unique_lock` is flexible — can unlock early (needed for condition variables), try-lock, time-lock. Use it when `lock_guard` isn't enough
- `mutable` on a mutex member allows locking in `const` member functions — the one legitimate use of `mutable` in production code
- Deadlock requires four conditions simultaneously: mutual exclusion, hold-and-wait, no preemption, circular wait. Break any one to prevent it — consistent lock ordering and `scoped_lock` break circular wait
- ThreadSanitizer (`-fsanitize=thread`) detects data races at runtime — run it on all concurrent code before shipping

Day 20 builds directly on this: condition variables for blocking pop, and `std::atomic` for lock-free primitives. We replace the spin-wait in today's consumer with a proper blocking queue that wakes up only when data is available.