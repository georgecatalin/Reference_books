
Day 20 gave you blocking queues and condition variables for streaming data between threads. Today covers a different problem: you need to dispatch a single unit of work to another thread and retrieve its result later — without manually setting up a mutex, a condition variable, and shared state for each operation. `std::future` and `std::promise` are the standard library's answer. They're a one-shot async channel: the promise is the write end, the future is the read end.

---

## 1. The Problem — Single-Value Async Results

```cpp
// Without futures — manual setup for every async operation
struct AsyncResult {
    std::mutex              mtx;
    std::condition_variable cv;
    float                   value;
    bool                    ready = false;
    std::string             error;
};

// Too much boilerplate for something this common
// futures eliminate all of it
```

Every async operation needs: shared state, a mutex, a condition variable, an error channel, and cleanup. Futures package all of this in a standard, composable way.

---

## 2. `std::promise` and `std::future` — The Basics

A `promise<T>` is the producer end. A `future<T>` is the consumer end. One promise produces exactly one value; one future consumes it.

```cpp
#include <future>
#include <thread>

std::promise<float> promise;
std::future<float>  future = promise.get_future();

// Producer thread — sets the value
std::thread producer([&promise]() {
    std::this_thread::sleep_for(std::chrono::milliseconds(100));
    float result = read_sensor();
    promise.set_value(result);          // wakes the consumer
});

// Consumer — blocks until value is ready
float value = future.get();             // blocks here
printf("Got: %.2f\n", value);

producer.join();
```

`future.get()` blocks until the promise sets a value, then returns it. After `get()` returns, the shared state is consumed — calling `get()` a second time throws `std::future_error`.

### Exceptions Through Futures

Exceptions propagate through the future channel — the producer can set an exception instead of a value:

```cpp
std::promise<float> promise;
auto future = promise.get_future();

std::thread producer([&promise]() {
    try {
        float v = read_sensor_that_throws();
        promise.set_value(v);
    } catch (...) {
        promise.set_exception(std::current_exception());  // forward exception
    }
});

try {
    float v = future.get();   // throws if producer set an exception
} catch (const std::runtime_error& e) {
    printf("Sensor error: %s\n", e.what());
}

producer.join();
```

If the promise is destroyed without setting a value or exception, `future.get()` throws `std::future_error` with `broken_promise`. This prevents deadlock — the consumer always gets a result or an exception.

---

## 3. `std::async` — Fire and Forget (or Fire and Collect)

`std::async` launches a callable asynchronously and returns a future for its return value. No manual thread management:

```cpp
#include <future>

// Launch immediately in a new thread
auto future = std::async(std::launch::async,
    []() -> float {
        return read_temperature_sensor();
    });

// Do other work while sensor reads...
update_display();

// Collect result — blocks if not ready yet
float temp = future.get();
printf("Temperature: %.2f\n", temp);
```

### Launch Policies

```cpp
// std::launch::async — guaranteed new thread, starts immediately
auto f1 = std::async(std::launch::async, task);

// std::launch::deferred — lazy evaluation, runs on future.get() in caller's thread
auto f2 = std::async(std::launch::deferred, task);

// Default — implementation choice (may be deferred on some platforms)
auto f3 = std::async(task);   // AVOID — behavior is implementation-defined
```

Always specify `std::launch::async` explicitly. The default policy is underspecified — some implementations defer execution, meaning the task never runs until `get()` is called in the calling thread, defeating the purpose of async.

### `future::wait` and `future::wait_for`

```cpp
auto f = std::async(std::launch::async, long_running_task);

// Check without blocking
if (f.wait_for(std::chrono::milliseconds(0)) == std::future_status::ready) {
    printf("Already done\n");
}

// Poll with timeout
auto status = f.wait_for(std::chrono::milliseconds(100));
switch (status) {
    case std::future_status::ready:   printf("Done\n"); break;
    case std::future_status::timeout: printf("Still running\n"); break;
    case std::future_status::deferred: printf("Not started\n"); break;
}

f.wait();   // block until ready without retrieving value
float v = f.get();
```

---

## 4. `std::packaged_task` — Wrapping Callables

`std::packaged_task<Signature>` wraps a callable and gives it a future. Unlike `std::async`, it doesn't launch automatically — you control when and where it runs:

```cpp
#include <future>

// Wrap a sensor read operation
std::packaged_task<float(uint8_t)> task(
    [](uint8_t sensor_id) -> float {
        return read_sensor(sensor_id);
    }
);

auto future = task.get_future();

// Run the task on a specific thread
std::thread worker(std::move(task), uint8_t(3));  // pass sensor_id=3

float result = future.get();
worker.join();
```

`packaged_task` is useful when you have a thread pool — wrap each work item as a `packaged_task`, push it to the pool's queue, and collect results via futures.

---

## 5. `std::shared_future` — Multiple Consumers

`std::future` can only be `get()`-called once. `std::shared_future` allows multiple threads to wait on the same result:

```cpp
std::promise<std::string> promise;
std::shared_future<std::string> shared = promise.get_future().share();

// Multiple threads can all wait on the same result
std::thread t1([shared]() {
    auto config = shared.get();   // blocks until ready
    printf("T1 got config: %s\n", config.c_str());
});

std::thread t2([shared]() {
    auto config = shared.get();   // same result, no race
    printf("T2 got config: %s\n", config.c_str());
});

promise.set_value("device_id=42,baud=115200");

t1.join();
t2.join();
```

---

## 6. Building a Thread Pool With Futures

A thread pool is the production pattern for managing async work. Fixed number of threads, unbounded work queue, each work item gets a future:

```cpp
// thread_pool.hpp
#pragma once
#include <thread>
#include <mutex>
#include <condition_variable>
#include <queue>
#include <vector>
#include <future>
#include <functional>
#include <stdexcept>

class ThreadPool {
public:
    explicit ThreadPool(size_t thread_count) {
        for (size_t i = 0; i < thread_count; ++i) {
            workers_.emplace_back([this, i]() {
                worker_loop(i);
            });
        }
        printf("[pool] started %zu workers\n", thread_count);
    }

    ~ThreadPool() {
        shutdown();
    }

    ThreadPool(const ThreadPool&)            = delete;
    ThreadPool& operator=(const ThreadPool&) = delete;

    // Submit a callable — returns a future for its result
    template<typename F, typename... Args>
    auto submit(F&& f, Args&&... args)
        -> std::future<std::invoke_result_t<F, Args...>>
    {
        using RetT = std::invoke_result_t<F, Args...>;

        // Package the task
        auto task = std::make_shared<std::packaged_task<RetT()>>(
            [f = std::forward<F>(f),
             args_tuple = std::make_tuple(std::forward<Args>(args)...)]() mutable {
                return std::apply(std::move(f), std::move(args_tuple));
            }
        );

        auto future = task->get_future();

        {
            std::lock_guard<std::mutex> lock(mtx_);
            if (shutdown_) throw std::runtime_error("ThreadPool is shut down");
            tasks_.push([task]() { (*task)(); });
        }
        cv_.notify_one();

        return future;
    }

    void shutdown() {
        {
            std::lock_guard<std::mutex> lock(mtx_);
            if (shutdown_) return;
            shutdown_ = true;
        }
        cv_.notify_all();
        for (auto& w : workers_) {
            if (w.joinable()) w.join();
        }
        printf("[pool] shut down\n");
    }

    size_t thread_count() const { return workers_.size(); }

private:
    void worker_loop(size_t id) {
        for (;;) {
            std::function<void()> task;
            {
                std::unique_lock<std::mutex> lock(mtx_);
                cv_.wait(lock, [this] {
                    return !tasks_.empty() || shutdown_;
                });
                if (shutdown_ && tasks_.empty()) return;
                task = std::move(tasks_.front());
                tasks_.pop();
            }
            task();
        }
    }

    std::vector<std::thread>          workers_;
    std::queue<std::function<void()>> tasks_;
    std::mutex                        mtx_;
    std::condition_variable           cv_;
    bool                              shutdown_ = false;
};
```

---

## 7. C++20 Coroutines — A Preview

C++20 adds coroutines — functions that can be suspended and resumed. They're the foundation of efficient async I/O without callbacks or explicit future chaining. A full treatment is a topic on its own, but the mental model is important:

```cpp
// C++20 coroutine — requires a coroutine framework (cppcoro, or your own)
// The compiler transforms this into a state machine

Task<float> read_sensor_async(uint8_t id) {
    co_await wait_for_ready(id);        // suspend here — don't block the thread
    float raw = co_await read_adc(id);  // suspend here — come back when done
    co_return raw * calibration_[id];   // return value through the coroutine handle
}

// Caller
Task<void> process() {
    float temp = co_await read_sensor_async(0);   // async, but reads like sync
    float hum  = co_await read_sensor_async(1);
    printf("temp=%.2f hum=%.2f\n", temp, hum);
}
```

The `co_await` expression suspends the coroutine — the thread is freed to do other work — and resumes when the awaited operation completes. No callback hell, no explicit future chaining, reads like synchronous code. This is the direction C++ async is heading. For embedded systems, the appeal is significant: a single thread can interleave multiple I/O operations without blocking.

---

## 8. Putting It Together — Device Discovery with Async

Parallel device discovery: probe multiple addresses simultaneously, collect results, timeout on slow devices:

```cpp
// device_discovery.cpp
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <future>
#include <thread>
#include <vector>
#include <optional>
#include <chrono>
#include <random>
#include <string>
#include <map>
#include <mutex>
#include <cassert>
#include <algorithm>

using namespace std::chrono_literals;

// ---- Device info ----

struct DeviceInfo {
    uint8_t     address;
    std::string name;
    std::string firmware;
    float       temperature;
    bool        reachable;

    void print() const {
        if (reachable) {
            printf("  [0x%02X] %-20s fw=%-8s temp=%.1f°C\n",
                   address, name.c_str(), firmware.c_str(), temperature);
        } else {
            printf("  [0x%02X] unreachable\n", address);
        }
    }
};

// ---- Simulate I2C device probe — variable latency ----

DeviceInfo probe_device(uint8_t address) {
    // Simulate variable response time
    static thread_local std::mt19937 rng(
        std::hash<std::thread::id>{}(std::this_thread::get_id())
    );
    std::uniform_int_distribution<int> latency_ms(5, 80);
    std::uniform_real_distribution<float> temp_dist(20.0f, 35.0f);

    int delay = latency_ms(rng);
    std::this_thread::sleep_for(std::chrono::milliseconds(delay));

    // Simulate some addresses having devices
    static const std::map<uint8_t, std::pair<std::string,std::string>> devices = {
        {0x40, {"TMP117 Temp",   "v2.1.0"}},
        {0x44, {"SHT31 Humid",   "v1.4.2"}},
        {0x48, {"ADS1115 ADC",   "v3.0.1"}},
        {0x60, {"MPL3115 Baro",  "v2.0.0"}},
        {0x68, {"MPU6050 IMU",   "v1.9.3"}},
        {0x76, {"BME280 EnvSns", "v4.1.0"}},
    };

    auto it = devices.find(address);
    if (it != devices.end()) {
        return DeviceInfo{
            address,
            it->second.first,
            it->second.second,
            temp_dist(rng),
            true
        };
    }
    return DeviceInfo{address, "", "", 0.0f, false};
}

// ---- Sequential discovery (baseline) ----

std::vector<DeviceInfo> discover_sequential(
    const std::vector<uint8_t>& addresses)
{
    std::vector<DeviceInfo> results;
    results.reserve(addresses.size());
    for (uint8_t addr : addresses) {
        results.push_back(probe_device(addr));
    }
    return results;
}

// ---- Parallel discovery using async ----

std::vector<DeviceInfo> discover_parallel(
    const std::vector<uint8_t>& addresses,
    std::chrono::milliseconds    timeout)
{
    // Launch all probes simultaneously
    std::vector<std::future<DeviceInfo>> futures;
    futures.reserve(addresses.size());

    for (uint8_t addr : addresses) {
        futures.push_back(
            std::async(std::launch::async,
                       probe_device, addr)  // passes addr by value
        );
    }

    // Collect results with timeout
    std::vector<DeviceInfo> results;
    results.reserve(addresses.size());

    auto deadline = std::chrono::steady_clock::now() + timeout;

    for (size_t i = 0; i < futures.size(); ++i) {
        auto remaining = deadline - std::chrono::steady_clock::now();

        if (remaining <= 0ms) {
            // Timeout expired — mark remaining as unreachable
            printf("  [discovery] timeout — %zu probes still pending\n",
                   futures.size() - i);
            for (; i < futures.size(); ++i) {
                results.push_back({addresses[i], "", "", 0.0f, false});
            }
            break;
        }

        auto status = futures[i].wait_for(remaining);
        if (status == std::future_status::ready) {
            results.push_back(futures[i].get());
        } else {
            results.push_back({addresses[i], "", "", 0.0f, false});
        }
    }

    return results;
}

// ---- Thread pool discovery ----

std::vector<DeviceInfo> discover_pooled(
    const std::vector<uint8_t>& addresses,
    ThreadPool& pool)
{
    std::vector<std::future<DeviceInfo>> futures;
    futures.reserve(addresses.size());

    for (uint8_t addr : addresses) {
        futures.push_back(pool.submit(probe_device, addr));
    }

    std::vector<DeviceInfo> results;
    results.reserve(addresses.size());
    for (auto& f : futures) {
        results.push_back(f.get());
    }
    return results;
}

// ---- Promise/future: async config load ----

class ConfigLoader {
public:
    // Start loading config in background
    void start_load(const std::string& source) {
        promise_ = std::promise<std::string>();
        config_future_ = promise_.get_future().share();

        std::thread([this, source]() {
            try {
                // Simulate config load from flash/network
                std::this_thread::sleep_for(30ms);
                if (source == "bad_source") {
                    throw std::runtime_error("config source not found: " + source);
                }
                promise_.set_value(
                    "device_id=42,baud=115200,timeout=1000,topic=sensors/"
                );
            } catch (...) {
                promise_.set_exception(std::current_exception());
            }
        }).detach();

        printf("[config] loading from '%s' in background\n", source.c_str());
    }

    // Get config — blocks until ready or throws on error
    std::string get(std::chrono::milliseconds timeout = 500ms) {
        if (config_future_.wait_for(timeout) != std::future_status::ready) {
            throw std::runtime_error("config load timed out");
        }
        return config_future_.get();  // may throw stored exception
    }

    bool is_ready() const {
        return config_future_.wait_for(0ms) == std::future_status::ready;
    }

private:
    std::promise<std::string>      promise_;
    std::shared_future<std::string> config_future_;
};

int main() {
    printf("=== Device Discovery with Futures ===\n\n");

    std::vector<uint8_t> addresses = {
        0x40, 0x41, 0x44, 0x45, 0x48,
        0x60, 0x68, 0x70, 0x76, 0x77
    };

    // ---- Sequential baseline ----
    printf("--- Sequential discovery (%zu addresses) ---\n",
           addresses.size());
    {
        auto start = std::chrono::steady_clock::now();
        auto results = discover_sequential(addresses);
        auto elapsed = std::chrono::steady_clock::now() - start;

        int found = 0;
        for (const auto& d : results) {
            d.print();
            if (d.reachable) ++found;
        }
        printf("Found %d devices in %ldms (sequential)\n\n",
               found,
               std::chrono::duration_cast<std::chrono::milliseconds>(elapsed).count());
    }

    // ---- Parallel discovery ----
    printf("--- Parallel discovery (200ms timeout) ---\n");
    {
        auto start   = std::chrono::steady_clock::now();
        auto results = discover_parallel(addresses, 200ms);
        auto elapsed = std::chrono::steady_clock::now() - start;

        int found = 0;
        for (const auto& d : results) {
            d.print();
            if (d.reachable) ++found;
        }
        printf("Found %d devices in %ldms (parallel)\n\n",
               found,
               std::chrono::duration_cast<std::chrono::milliseconds>(elapsed).count());
    }

    // ---- Thread pool discovery ----
    printf("--- Thread pool discovery (4 workers) ---\n");
    {
        ThreadPool pool(4);
        auto start   = std::chrono::steady_clock::now();
        auto results = discover_pooled(addresses, pool);
        auto elapsed = std::chrono::steady_clock::now() - start;

        int found = 0;
        for (const auto& d : results) {
            d.print();
            if (d.reachable) ++found;
        }
        printf("Found %d devices in %ldms (pooled, 4 workers)\n\n",
               found,
               std::chrono::duration_cast<std::chrono::milliseconds>(elapsed).count());
    }

    // ---- Promise/future: config loading ----
    printf("--- Async config loading ---\n");
    {
        ConfigLoader loader;
        loader.start_load("flash://config.bin");

        // Do other work while config loads
        printf("[main] doing other initialization...\n");
        std::this_thread::sleep_for(10ms);
        printf("[main] config ready: %s\n",
               loader.is_ready() ? "yes" : "not yet");

        try {
            std::string config = loader.get(200ms);
            printf("[main] config: %s\n", config.c_str());
        } catch (const std::exception& e) {
            printf("[main] config error: %s\n", e.what());
        }
    }

    // ---- Exception propagation through future ----
    printf("\n--- Exception through future ---\n");
    {
        ConfigLoader loader;
        loader.start_load("bad_source");
        std::this_thread::sleep_for(50ms);

        try {
            auto config = loader.get();
            printf("Got: %s\n", config.c_str());
        } catch (const std::runtime_error& e) {
            printf("[main] caught forwarded exception: %s\n", e.what());
        }
    }

    // ---- shared_future: broadcast result ----
    printf("\n--- shared_future: broadcast to 3 threads ---\n");
    {
        std::promise<float>       promise;
        std::shared_future<float> shared = promise.get_future().share();

        std::vector<std::thread> waiters;
        std::mutex print_mtx;

        for (int i = 0; i < 3; ++i) {
            waiters.emplace_back([shared, i, &print_mtx]() {
                float v = shared.get();  // all three block here
                std::lock_guard lock(print_mtx);
                printf("  waiter %d got calibration: %.4f\n", i, v);
            });
        }

        std::this_thread::sleep_for(20ms);
        printf("[main] broadcasting calibration value\n");
        promise.set_value(1.0234f);

        for (auto& t : waiters) t.join();
    }

    printf("\nDone.\n");
    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=thread -o discovery device_discovery.cpp -lpthread
./discovery
```

### What to observe

Sequential discovery sums the latencies of all probes — if each takes up to 80ms, 10 probes can take up to 800ms. Parallel discovery runs all probes simultaneously — total time is bounded by the slowest single probe, not their sum. This is the fundamental win of async: I/O-bound work overlaps.

The timeout handling in `discover_parallel` uses `wait_for` with a deadline computed from the current time. Once the deadline passes, remaining futures are abandoned — their threads are still running, but the caller doesn't wait. The futures are destroyed when they go out of scope, which detaches the background threads. In production, you'd want a cancellation mechanism — this is where coroutines or a proper async framework adds value.

The `ConfigLoader` uses `.detach()` on the loading thread, which is usually a smell. Here it's acceptable because the thread captures `this` by pointer and the `shared_future` keeps the promise alive. In production code, store the thread and join it in the destructor.

---

## Key Takeaways for Day 21

- `std::promise<T>` is the write end, `std::future<T>` is the read end — a one-shot async channel with built-in exception propagation
- Always specify `std::launch::async` explicitly — the default policy is implementation-defined and may be deferred
- `future.get()` blocks until ready and returns the value — or rethrows the stored exception. Can only be called once
- `future.wait_for(duration)` polls without consuming the value — returns `ready`, `timeout`, or `deferred`
- Destroying a promise without setting a value or exception makes `future.get()` throw `broken_promise` — no deadlock possible
- `std::packaged_task` wraps a callable and gives it a future — decouples task creation from execution. The foundation of thread pool implementations
- `std::shared_future` allows multiple threads to wait on the same result — `future.share()` converts a `future` to `shared_future`
- The thread pool pattern: fixed workers, shared task queue, each task wrapped in `packaged_task`, futures returned to caller — eliminates thread creation overhead for many short tasks
- C++20 coroutines (`co_await`, `co_return`) are the future of async C++ — suspend and resume functions without threads, composable without callbacks. Study `cppcoro` or `libunifex` for production-ready coroutine frameworks

Day 22 covers file I/O, sockets, and `std::filesystem` — reading and writing binary data, navigating the filesystem, and the POSIX socket patterns that underlie every MQTT client and HTTP handler you'll write.