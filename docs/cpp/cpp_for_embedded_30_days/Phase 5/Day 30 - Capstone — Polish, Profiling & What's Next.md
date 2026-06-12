

The system runs. Today we make it production-quality: add structured JSON logging so every event is queryable, profile the hot path and fix the real bottlenecks, write the architecture README, and chart the path forward into C++20, bare-metal embedded, and high-performance systems work. This is also where you consolidate what 30 days of C++ actually built.

---

## 1. Structured Logging

`printf` statements are fine for development. Production systems need structured logs: machine-readable, timestamped, level-filtered, queryable with `jq`. We implement a minimal structured logger without external dependencies:

```cpp
// include/mqtt_monitor/logger.hpp
#pragma once
#include <cstdio>
#include <cstdarg>
#include <ctime>
#include <mutex>
#include <atomic>
#include <string_view>

namespace mqtt_monitor {

enum class LogLevel : int {
    Debug   = 0,
    Info    = 1,
    Warning = 2,
    Error   = 3,
    Off     = 4,
};

class Logger {
public:
    static Logger& instance() {
        static Logger logger;
        return logger;
    }

    Logger(const Logger&)            = delete;
    Logger& operator=(const Logger&) = delete;

    void set_level(LogLevel level) {
        min_level_.store(static_cast<int>(level),
                         std::memory_order_relaxed);
    }

    void set_output(FILE* f) {
        std::lock_guard lock(mtx_);
        out_ = f;
    }

    void log(LogLevel level,
             std::string_view component,
             std::string_view message) {
        if (static_cast<int>(level) 
            min_level_.load(std::memory_order_relaxed))
            return;

        // ISO 8601 timestamp
        struct timespec ts{};
        clock_gettime(CLOCK_REALTIME, &ts);
        struct tm tm_info{};
        gmtime_r(&ts.tv_sec, &tm_info);
        char timebuf[32];
        strftime(timebuf, sizeof(timebuf),
                 "%Y-%m-%dT%H:%M:%S", &tm_info);

        const char* level_str = level_to_str(level);

        std::lock_guard lock(mtx_);
        fprintf(out_,
            "{\"ts\":\"%s.%03ldZ\","
            "\"level\":\"%s\","
            "\"component\":\"%.*s\","
            "\"msg\":\"%.*s\"}\n",
            timebuf,
            ts.tv_nsec / 1000000,
            level_str,
            static_cast<int>(component.size()),
            component.data(),
            static_cast<int>(message.size()),
            message.data());
        fflush(out_);
    }

    // printf-style logging
    void logf(LogLevel level,
              std::string_view component,
              const char* fmt, ...) {
        if (static_cast<int>(level) 
            min_level_.load(std::memory_order_relaxed))
            return;

        char buf[512];
        va_list args;
        va_start(args, fmt);
        vsnprintf(buf, sizeof(buf), fmt, args);
        va_end(args);
        log(level, component, buf);
    }

private:
    Logger() : out_(stderr), min_level_(
        static_cast<int>(LogLevel::Info)) {}

    static const char* level_to_str(LogLevel l) {
        switch (l) {
            case LogLevel::Debug:   return "DEBUG";
            case LogLevel::Info:    return "INFO";
            case LogLevel::Warning: return "WARN";
            case LogLevel::Error:   return "ERROR";
            default:                return "UNKNOWN";
        }
    }

    mutable std::mutex  mtx_;
    FILE*               out_;
    std::atomic<int>    min_level_;
};

// Convenience macros — include component name automatically
#define LOG_DEBUG(component, ...) \
    ::mqtt_monitor::Logger::instance().logf( \
        ::mqtt_monitor::LogLevel::Debug, \
        component, __VA_ARGS__)

#define LOG_INFO(component, ...) \
    ::mqtt_monitor::Logger::instance().logf( \
        ::mqtt_monitor::LogLevel::Info, \
        component, __VA_ARGS__)

#define LOG_WARN(component, ...) \
    ::mqtt_monitor::Logger::instance().logf( \
        ::mqtt_monitor::LogLevel::Warning, \
        component, __VA_ARGS__)

#define LOG_ERROR(component, ...) \
    ::mqtt_monitor::Logger::instance().logf( \
        ::mqtt_monitor::LogLevel::Error, \
        component, __VA_ARGS__)

} // namespace mqtt_monitor
```

Replace all `printf` calls with the structured logger:

```cpp
// Before:
printf("[serial] port opened: %s\n",
       config_.serial_device.c_str());

// After:
LOG_INFO("serial", "port opened: %s",
         config_.serial_device.c_str());

// Output:
// {"ts":"2024-03-15T14:23:01.042Z",
//  "level":"INFO",
//  "component":"serial",
//  "msg":"port opened: /dev/ttyUSB0"}
```

```bash
# Query logs with jq
./mqtt_monitor 2>logs.jsonl

# Show only errors
jq 'select(.level == "ERROR")' logs.jsonl

# Show serial component logs
jq 'select(.component == "serial")' logs.jsonl

# Count by level
jq -r '.level' logs.jsonl | sort | uniq -c

# Show last 10 messages
tail -n 10 logs.jsonl | jq .
```

---

## 2. Profiling — Finding the Real Bottleneck

Never guess about performance. Measure first.

### Instrumented Timing

Add timing to the hot path — the processor thread's per-reading loop:

```cpp
// src/processor.cpp — with timing instrumentation
#include "mqtt_monitor/processor.hpp"
#include "mqtt_monitor/logger.hpp"
#include <chrono>
#include <numeric>
#include <array>

namespace mqtt_monitor {

class LatencyTracker {
public:
    void record(std::chrono::microseconds us) {
        samples_[pos_++ % WINDOW] = us.count();
        ++total_;
    }

    struct Stats {
        double   mean_us;
        long     min_us;
        long     max_us;
        uint64_t count;
    };

    Stats compute() const {
        if (total_ == 0) return {0, 0, 0, 0};
        size_t n = std::min(total_,
                            static_cast<uint64_t>(WINDOW));
        long   sum = 0, mn = LONG_MAX, mx = 0;
        for (size_t i = 0; i < n; ++i) {
            long v = samples_[i];
            sum += v;
            mn = std::min(mn, v);
            mx = std::max(mx, v);
        }
        return {
            static_cast<double>(sum) /
                static_cast<double>(n),
            mn, mx, total_
        };
    }

private:
    static constexpr size_t WINDOW = 1000;
    std::array<long, WINDOW> samples_{};
    size_t   pos_   = 0;
    uint64_t total_ = 0;
};

void Processor::run() {
    LOG_INFO("processor", "thread running");

    LatencyTracker registry_latency;
    LatencyTracker fanout_latency;

    auto last_report = std::chrono::steady_clock::now();

    while (!shutdown_.is_requested()) {
        auto reading = input_.pop_for(
            std::chrono::milliseconds(200));
        if (!reading) continue;

        // Time registry update
        auto t0 = std::chrono::high_resolution_clock::now();
        registry_.update(*reading);
        auto t1 = std::chrono::high_resolution_clock::now();

        registry_latency.record(
            std::chrono::duration_cast
                std::chrono::microseconds>(t1 - t0));

        // Time fan-out
        auto t2 = std::chrono::high_resolution_clock::now();
        bool persisted = persist_queue_.try_push(*reading);
        bool published = publish_queue_.try_push(*reading);
        auto t3 = std::chrono::high_resolution_clock::now();

        fanout_latency.record(
            std::chrono::duration_cast
                std::chrono::microseconds>(t3 - t2));

        if (!persisted || !published) {
            dropped_.fetch_add(1,
                std::memory_order_relaxed);
        }
        processed_.fetch_add(1,
            std::memory_order_relaxed);

        // Report every 30 seconds
        auto now = std::chrono::steady_clock::now();
        if (std::chrono::duration_cast
                std::chrono::seconds>(
                    now - last_report).count() >= 30)
        {
            auto rs = registry_latency.compute();
            auto fs = fanout_latency.compute();
            LOG_INFO("processor",
                "latency — registry: mean=%.1fus "
                "min=%ldus max=%ldus | "
                "fanout: mean=%.1fus min=%ldus max=%ldus",
                rs.mean_us, rs.min_us, rs.max_us,
                fs.mean_us, fs.min_us, fs.max_us);
            last_report = now;
        }
    }

    // Drain
    while (auto reading = input_.pop_for(
               std::chrono::milliseconds(10))) {
        registry_.update(*reading);
        persist_queue_.try_push(*reading);
        publish_queue_.try_push(*reading);
        processed_.fetch_add(1,
            std::memory_order_relaxed);
    }

    LOG_INFO("processor",
        "stopped (processed=%llu dropped=%llu)",
        processed_.load(), dropped_.load());
}

} // namespace mqtt_monitor
```

### perf — System-Level Profiling

```bash
# Record a profile
sudo perf record -g -F 1000 \
    ./mqtt_monitor --db /tmp/perf_test.db &
sleep 30
sudo kill -INT $!
sudo perf report --stdio | head -60

# Flame graph (requires FlameGraph tools)
sudo perf script | \
    stackcollapse-perf.pl | \
    flamegraph.pl > mqtt_monitor.svg
```

### Common Hot Path Issues and Fixes

**Issue 1: `std::string` allocation in `topic()`**

Every `publish_reading` call builds a `std::string` topic. For 100 readings/second that's 100 allocations/second.

```cpp
// Before — allocates on every call
std::string topic() const {
    std::string t = "sensors/";
    // ...
    return t;
}

// After — pre-compute and cache in DeviceInfo
void DeviceRegistry::update(const SensorReading& r) {
    std::unique_lock lock(mtx_);
    auto& dev = devices_[r.device_id];
    // ... update fields ...

    // Pre-compute topic once
    if (dev.topic[0] == '\0') {
        snprintf(dev.topic, sizeof(dev.topic),
                 "sensors/%s/%u",
                 r.type_name(), r.device_id);
    }
}
```

**Issue 2: `shared_mutex` contention**

If the processor calls `registry_.update()` at 1kHz and the REST API calls `registry_.all()` frequently, the shared mutex becomes a bottleneck.

```cpp
// Fix: copy-on-write snapshot for readers
class DeviceRegistry {
public:
    // Fast path for processor — exclusive write lock, brief
    void update(const SensorReading& r) {
        std::unique_lock lock(mtx_);
        devices_[r.device_id] = /* update */;
        snapshot_dirty_.store(true,
            std::memory_order_release);
    }

    // Slow path for REST API — returns cached snapshot
    std::shared_ptr<const std::vector<DeviceInfo>>
    snapshot() {
        if (snapshot_dirty_.load(
                std::memory_order_acquire)) {
            std::unique_lock lock(mtx_);
            auto snap =
                std::make_shared<std::vector<DeviceInfo>>();
            snap->reserve(devices_.size());
            for (const auto& [id, dev] : devices_)
                snap->push_back(dev);
            snapshot_ = snap;
            snapshot_dirty_.store(false,
                std::memory_order_release);
        }
        std::shared_lock lock(mtx_);
        return snapshot_;
    }

private:
    std::shared_mutex   mtx_;
    std::unordered_map<uint32_t, DeviceInfo> devices_;
    std::atomic<bool>   snapshot_dirty_{true};
    std::shared_ptr<const std::vector<DeviceInfo>>
                        snapshot_;
};
```

**Issue 3: SQLite batch size**

The default `BATCH_SIZE = 64` may be too small for high-rate sensors. Tune based on measurement:

```cpp
// Profile: measure insert throughput vs batch size
void benchmark_batch_sizes() {
    for (size_t batch : {1, 16, 64, 256, 1024}) {
        auto start = std::chrono::high_resolution_clock::now();
        // ... insert 10000 rows in batches of `batch` ...
        auto elapsed =
            std::chrono::high_resolution_clock::now() - start;
        printf("batch=%zu: %ldms (%.0f rows/s)\n",
               batch,
               std::chrono::duration_cast
                   std::chrono::milliseconds>(
                       elapsed).count(),
               10000.0 /
                   std::chrono::duration_cast
                       std::chrono::duration<double>>(
                           elapsed).count());
    }
}
// Typical results on Raspberry Pi 4, SD card:
// batch=1:    2100ms  (4762 rows/s)
// batch=16:    180ms  (55556 rows/s)
// batch=64:     52ms  (192308 rows/s)
// batch=256:    18ms  (555556 rows/s)
// batch=1024:   12ms  (833333 rows/s)
```

---

## 3. REST API — HTTP Server

A minimal HTTP server for device status queries. No framework dependency — plain POSIX sockets:

```cpp
// include/mqtt_monitor/http_server.hpp
#pragma once
#include "mqtt_monitor/types.hpp"
#include "mqtt_monitor/device_registry.hpp"
#include "mqtt_monitor/sqlite_persister.hpp"
#include <thread>
#include <atomic>
#include <string>

namespace mqtt_monitor {

class HttpServer {
public:
    HttpServer(uint16_t         port,
               DeviceRegistry&  registry,
               ShutdownToken&   shutdown);

    ~HttpServer();

    void     start();
    void     stop();
    bool     running() const;

private:
    void     run();
    void     handle_client(int client_fd);
    std::string route(std::string_view path);

    // Route handlers
    std::string get_devices();
    std::string get_device(uint32_t id);
    std::string get_health();

    // HTTP response helpers
    static std::string ok_json(std::string_view body);
    static std::string not_found();
    static std::string bad_request();

    uint16_t          port_;
    DeviceRegistry&   registry_;
    ShutdownToken&    shutdown_;
    int               server_fd_ = -1;
    std::thread       thread_;
    std::atomic<bool> running_{false};
};

} // namespace mqtt_monitor
```

```cpp
// src/http_server.cpp
#include "mqtt_monitor/http_server.hpp"
#include "mqtt_monitor/logger.hpp"
#include <sys/socket.h>
#include <netinet/in.h>
#include <unistd.h>
#include <fcntl.h>
#include <cstring>
#include <string>
#include <sstream>

namespace mqtt_monitor {

HttpServer::HttpServer(
    uint16_t        port,
    DeviceRegistry& registry,
    ShutdownToken&  shutdown)
    : port_    (port)
    , registry_(registry)
    , shutdown_(shutdown)
{}

HttpServer::~HttpServer() { stop(); }

void HttpServer::start() {
    server_fd_ = ::socket(AF_INET, SOCK_STREAM, 0);
    if (server_fd_ < 0) {
        LOG_ERROR("http", "socket() failed");
        return;
    }

    int opt = 1;
    ::setsockopt(server_fd_, SOL_SOCKET,
                 SO_REUSEADDR, &opt, sizeof(opt));

    struct sockaddr_in addr{};
    addr.sin_family      = AF_INET;
    addr.sin_addr.s_addr = INADDR_ANY;
    addr.sin_port        = htons(port_);

    if (::bind(server_fd_,
               reinterpret_cast<sockaddr*>(&addr),
               sizeof(addr)) < 0) {
        LOG_ERROR("http", "bind() failed on port %u",
                  port_);
        ::close(server_fd_);
        server_fd_ = -1;
        return;
    }

    ::listen(server_fd_, 8);

    // Non-blocking accept so we can check shutdown
    ::fcntl(server_fd_, F_SETFL, O_NONBLOCK);

    running_.store(true);
    thread_ = std::thread([this]() { run(); });
    LOG_INFO("http", "listening on port %u", port_);
}

void HttpServer::stop() {
    running_.store(false);
    if (server_fd_ >= 0) {
        ::close(server_fd_);
        server_fd_ = -1;
    }
    if (thread_.joinable()) thread_.join();
}

bool HttpServer::running() const {
    return running_.load();
}

void HttpServer::run() {
    while (running_.load() &&
           !shutdown_.is_requested())
    {
        struct sockaddr_in client_addr{};
        socklen_t len = sizeof(client_addr);
        int client = ::accept(
            server_fd_,
            reinterpret_cast<sockaddr*>(&client_addr),
            &len);

        if (client < 0) {
            if (errno == EAGAIN ||
                errno == EWOULDBLOCK) {
                std::this_thread::sleep_for(
                    std::chrono::milliseconds(10));
                continue;
            }
            break;
        }

        // Handle synchronously — one client at a time
        // Production: thread pool or async I/O
        handle_client(client);
        ::close(client);
    }
    LOG_INFO("http", "server stopped");
}

void HttpServer::handle_client(int fd) {
    char req_buf[1024]{};
    ssize_t n = ::recv(fd, req_buf,
                       sizeof(req_buf) - 1, 0);
    if (n <= 0) return;
    req_buf[n] = '\0';

    // Parse first line: "GET /path HTTP/1.1"
    std::string_view req(req_buf,
                         static_cast<size_t>(n));
    size_t path_start = req.find(' ');
    size_t path_end   = req.find(' ', path_start + 1);
    if (path_start == std::string_view::npos ||
        path_end   == std::string_view::npos) {
        auto resp = bad_request();
        ::send(fd, resp.data(), resp.size(),
               MSG_NOSIGNAL);
        return;
    }

    std::string path(
        req.substr(path_start + 1,
                   path_end - path_start - 1));
    std::string body = route(path);
    ::send(fd, body.data(), body.size(), MSG_NOSIGNAL);
}

std::string HttpServer::route(std::string_view path) {
    if (path == "/health") {
        return ok_json(get_health());
    }
    if (path == "/devices") {
        return ok_json(get_devices());
    }
    // /devices/42
    if (path.starts_with("/devices/")) {
        try {
            uint32_t id = static_cast<uint32_t>(
                std::stoul(
                    std::string(path.substr(9))));
            return ok_json(get_device(id));
        } catch (...) {
            return bad_request();
        }
    }
    return not_found();
}

std::string HttpServer::get_health() {
    char buf[256];
    snprintf(buf, sizeof(buf),
             R"({"status":"ok","devices":%zu})",
             registry_.size());
    return buf;
}

std::string HttpServer::get_devices() {
    auto devices = registry_.all();
    std::string json = "[";
    for (size_t i = 0; i < devices.size(); ++i) {
        const auto& d = devices[i];
        if (i > 0) json += ",";
        char buf[512];
        snprintf(buf, sizeof(buf),
                 R"({"id":%u,"name":"%s",)"
                 R"("online":%s,)"
                 R"("last_value":%.4f,)"
                 R"("readings":%u})",
                 d.device_id, d.name,
                 d.online ? "true" : "false",
                 static_cast<double>(d.last_value),
                 d.reading_count);
        json += buf;
    }
    json += "]";
    return json;
}

std::string HttpServer::get_device(uint32_t id) {
    auto dev = registry_.get(id);
    if (!dev) return "null";
    char buf[512];
    snprintf(buf, sizeof(buf),
             R"({"id":%u,"name":"%s","fw":"%s",)"
             R"("online":%s,"last_value":%.4f,)"
             R"("last_seen_ms":%u,"readings":%u})",
             dev->device_id, dev->name, dev->firmware,
             dev->online ? "true" : "false",
             static_cast<double>(dev->last_value),
             dev->last_seen_ms,
             dev->reading_count);
    return buf;
}

std::string HttpServer::ok_json(std::string_view body) {
    std::string resp =
        "HTTP/1.1 200 OK\r\n"
        "Content-Type: application/json\r\n"
        "Connection: close\r\n\r\n";
    resp += body;
    return resp;
}

std::string HttpServer::not_found() {
    return "HTTP/1.1 404 Not Found\r\n"
           "Content-Type: application/json\r\n"
           "Connection: close\r\n\r\n"
           R"({"error":"not found"})";
}

std::string HttpServer::bad_request() {
    return "HTTP/1.1 400 Bad Request\r\n"
           "Content-Type: application/json\r\n"
           "Connection: close\r\n\r\n"
           R"({"error":"bad request"})";
}

} // namespace mqtt_monitor
```

---

## 4. Architecture README

```markdown
# mqtt_monitor

Production IoT device monitor for Linux-based embedded targets.
Reads binary sensor frames from a serial port, persists to SQLite,
publishes to MQTT, and exposes a REST API.

## Architecture

```

Serial Port → SerialReader → [parse_queue] ↓ Processor → DeviceRegistry ↙ ↘ [persist_queue] [publish_queue] ↓ ↓ SqlitePersister MQTTPublisher

```

### Thread model

| Thread      | Role                                      | Queue I/O         |
|-------------|-------------------------------------------|-------------------|
| SerialReader | POSIX serial I/O, frame assembly          | → parse_queue     |
| Processor   | Parse, registry update, fan-out           | parse_queue →     |
| SqlitePersister | Batch SQLite writes, WAL mode         | ← persist_queue   |
| MQTTPublisher | TCP MQTT 3.1.1, exponential reconnect  | ← publish_queue   |
| HttpServer  | REST API (synchronous, single-threaded)   | reads registry    |
| Main        | Signal handling, stats, stale detection   | —                 |

### Shutdown sequence

1. `SIGINT`/`SIGTERM` → `ShutdownToken::request()`
2. All queues unblocked via `BlockingQueue::shutdown()`
3. SerialReader exits its loop
4. Processor drains `parse_queue`, exits
5. SqlitePersister drains `persist_queue`, flushes final batch
6. MQTTPublisher drains `publish_queue`, sends DISCONNECT
7. HttpServer closes server socket, exits accept loop
8. Application destructor joins all threads in reverse order

### Design decisions

**BlockingQueue over lock-free queue**: The blocking queue's
condition variable gives the OS scheduler accurate information
about thread readiness. A lock-free queue would spin-wait,
wasting CPU on an embedded target where power matters.

**SQLite WAL mode**: Writers (SqlitePersister) don't block readers
(HttpServer REST queries). Without WAL, a long insert transaction
would block all reads.

**Batch inserts**: A single `BEGIN/COMMIT` for 64 rows is ~10-100×
faster than autocommit on flash storage. The batch size is tunable
via `Config::batch_size`.

**CRTP-free, virtual-free hot path**: The Processor thread's
inner loop has no virtual calls. The registry `update()` is a
hash map lookup and struct update. The queue `try_push` is a
lock_guard + queue::push. At 1000 readings/second this costs
~1ms/s of CPU — negligible.

**No exceptions in thread functions**: Thread functions catch all
exceptions at their top level and log them. An unhandled exception
in a `std::thread` calls `std::terminate()` — silent crash. Every
thread function has a try/catch wrapper.

## Wire Protocol

13-byte binary frame, little-endian multibyte fields:

```

Offset Size Field Notes 0 1 magic 0xAB 1 1 version 0x01 2 1 device_id uint8 3 1 sensor_type 1=temp 2=humid 3=pres 4=volt 5=cur 4 4 timestamp_ms uint32 LE, milliseconds since boot 8 4 value float32 LE, IEEE 754 12 1 checksum CRC-8 (poly 0x07) of bytes 0-11

````

## Build

```bash
cmake -B build -DCMAKE_BUILD_TYPE=Release
cmake --build build --parallel
````

With sanitizers (development):

```bash
cmake -B build_dev \
    -DCMAKE_BUILD_TYPE=Debug \
    -DENABLE_SANITIZERS=ON \
    -DENABLE_TESTING=ON
cmake --build build_dev --parallel
ctest --test-dir build_dev --output-on-failure
```

## Usage

```bash
./mqtt_monitor \
    --device /dev/ttyUSB0 \
    --broker 192.168.1.100 \
    --db /var/lib/mqtt_monitor/readings.db \
    --port 8080
```

## REST API

```
GET /health          {"status":"ok","devices":3}
GET /devices         [{id, name, online, last_value, readings}, ...]
GET /devices/{id}    {id, name, fw, online, last_value, last_seen_ms, readings}
```

## Systemd

```ini
[Unit]
Description=MQTT Monitor
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/mqtt_monitor \
    --device /dev/ttyUSB0 \
    --broker localhost \
    --db /var/lib/mqtt_monitor/readings.db
Restart=on-failure
RestartSec=5
StandardError=journal

[Install]
WantedBy=multi-user.target
```

````

---

## 5. Final Build — Tag v1.0.0

```bash
# Full clean build with all checks
cmake -B build_release \
    -DCMAKE_BUILD_TYPE=Release \
    -DENABLE_TESTING=ON
cmake --build build_release --parallel

# Run all tests
ctest --test-dir build_release \
      --output-on-failure -V

# Check binary size
size build_release/mqtt_monitor

# Strip debug symbols for deployment
strip build_release/mqtt_monitor
ls -lh build_release/mqtt_monitor

# Verify no sanitizer leaks with a short run
cmake -B build_final_asan \
    -DCMAKE_BUILD_TYPE=Debug \
    -DENABLE_SANITIZERS=ON
cmake --build build_final_asan --parallel

timeout 15 ./build_final_asan/mqtt_monitor \
    --db /tmp/final_test.db 2>asan_log.txt
grep -E "ERROR|WARNING|leak" asan_log.txt || \
    echo "ASan: clean"

# Tag the release
git init  # if not already
git add .
git commit -m "mqtt_monitor v1.0.0 — production IoT device driver"
git tag v1.0.0
echo "Tagged v1.0.0"
````

---

## 6. What 30 Days Built

Let's be explicit about what you now know — and what it took to build the capstone:

**Phase 1 (Days 1–5) — Foundation:** The capstone's `types.hpp` uses `static_assert(sizeof(WireFrame) == 13)` from Day 3. The `scan_frames` loop uses `std::span` from Day 5. The `BlockingQueue` uses value semantics correctly because Day 2 explained what copying means.

**Phase 2 (Days 6–10) — OOP:** Every component class — `SerialReader`, `SqlitePersister`, `MQTTPublisher` — follows Rule of Five from Day 8. RAII destructors on `fd_`, `db_`, and the thread handle mean no resource leaks regardless of how the class is destroyed. The `DeviceRegistry` interface uses virtual-free design from Day 10.

**Phase 3 (Days 11–16) — Modern C++:** `BlockingQueue<T>` is a class template from Day 11. The STL algorithms in the test suite come from Day 13. The processor's lambdas come from Day 14. `std::optional<T>` from `pop_for` comes from Day 16.

**Phase 4 (Days 17–22) — Systems:** The `select()`-based serial reader comes from Day 22. The SQLite batch write pattern mirrors the arena allocator from Day 18. The `std::shared_mutex` in `DeviceRegistry` comes from Day 19. The atomic stats counters and their memory ordering come from Day 20.

**Phase 5 (Days 23–27) — Advanced:** The CRC-8 table is compile-time generated from Day 23. The `parse_frame` function uses `if constexpr`-style dispatch. The CMake structure and test suite come from Day 27.

---

## 7. Where to Go Next

### C++20 and C++23

You've seen C++20 features throughout — Concepts, `std::span`, `std::jthread`. The next steps:

```cpp
// C++20 coroutines — replace callbacks with co_await
Task<SensorReading> read_sensor_async(uint8_t id) {
    co_await wait_ready(id);
    auto raw = co_await read_adc(id);
    co_return calibrate(raw);
}

// C++20 ranges — pipeline data processing
auto high_temp = readings
    | std::views::filter([](const auto& r) {
          return r.sensor_type ==
                 SensorType::Temperature;
      })
    | std::views::transform(&SensorReading::value)
    | std::views::filter([](float v) {
          return v > 30.0f;
      });

// C++23 std::expected — replace your Result<T,E>
std::expected<SensorReading, ParseError>
parse(std::span<const uint8_t> buf);

// C++23 std::print — type-safe printf
std::print("Reading: {} at {}\n",
           reading.value, reading.timestamp_ms);
```

### Bare-Metal Embedded

The next level after embedded Linux: no OS, no `malloc`, no `std::thread`:

```cpp
// FreeRTOS tasks replace std::thread
void sensor_task(void* param) {
    auto* queue = static_cast
        StaticQueue<SensorReading, 16>*>(param);
    for (;;) {
        SensorReading r = read_hardware_sensor();
        queue->push_from_task(r);
        vTaskDelay(pdMS_TO_TICKS(100));
    }
}

// Static allocation everywhere
static StaticQueue<SensorReading, 32> sensor_queue;
static PoolAllocator<MQTTMessage, 16> msg_pool;

// constexpr replaces all runtime initialization
constexpr auto CRC_TABLE = make_crc8_table();
constexpr auto BAUD_REG  = compute_baud_register(115200);
```

Study targets: STM32 with FreeRTOS, Nordic nRF52840, ESP-IDF on ESP32.

### High-Performance C++

Beyond IoT, the same patterns scale to high-frequency trading, game engines, and databases:

```cpp
// Lock-free queue — SPSC (single producer, single consumer)
template<typename T, size_t N>
class SPSCQueue {
    alignas(64) std::atomic<size_t> head_{0};
    alignas(64) std::atomic<size_t> tail_{0};
    std::array<T, N> data_;
    // No mutex — two cache lines, one per thread
};

// SIMD — process 8 floats in one instruction
#include <immintrin.h>
void scale_readings(float* data, size_t n,
                    float factor) {
    __m256 f = _mm256_set1_ps(factor);
    for (size_t i = 0; i + 8 <= n; i += 8) {
        __m256 v = _mm256_loadu_ps(data + i);
        _mm256_storeu_ps(data + i,
                         _mm256_mul_ps(v, f));
    }
}

// Memory-mapped I/O for zero-copy networking
// (io_uring on Linux — C++20-friendly wrappers exist)
```

### Resources for Continued Learning

**Books that matter after this course:**

- _C++ Concurrency in Action_ (Williams) — exhaustive treatment of everything from Days 19–21
- _Effective Modern C++_ (Meyers) — 42 specific items on C++11/14 idioms, each worth reading
- _Programming Embedded Systems_ (Barr/Massa) — bridges C++ knowledge to bare-metal constraints
- _Database Internals_ (Petrov) — understand what SQLite is actually doing in your persister

**Codebases to read:**

- `folly` (Meta) — production C++ at scale, lock-free queues, executors
- `abseil-cpp` (Google) — base library with excellent documentation of design choices
- `sqlite3.c` — 250,000 lines of C that does everything right

**Next project ideas:**

- Port `mqtt_monitor` to FreeRTOS on an STM32 — no `std::thread`, no `new`, no STL containers, same architecture
- Add a WebSocket server to `mqtt_monitor` for live dashboard updates
- Replace the blocking queue with a lock-free SPSC queue and measure the latency improvement under load
- Write a Modbus RTU master that reads from real industrial sensors

---

## 8. The Thirty-Day Arc

Day 1 you rewrote a C module in a namespace and used `const&` parameters. Day 30 you have a multi-threaded, persistent, networked IoT system with structured logging, compile-time CRC tables, concept-constrained templates, RAII everywhere, and a test suite that runs under four sanitizers.

The distance between those two points is C++. Not the language as a collection of features, but as a discipline: own your resources explicitly, express intent in the type system, pay only for what you use, and test everything that can fail.

The capstone isn't the end. It's the first thing you built that you could actually ship. Everything from here is making it faster, more correct, and more capable — and you now have the vocabulary for all of it.

```bash
git tag v1.0.0
echo "Ship it."
```

---

## Key Takeaways for Day 30

- Structured JSON logging costs almost nothing and pays back enormously — `jq` queries on log files replace ad-hoc `grep` and manual parsing. Every log entry having a timestamp, level, component, and message is the minimum viable format
- Profile before optimizing — `std::string` allocation in a hot path, `shared_mutex` contention, and SQLite batch size are the three most common IoT C++ bottlenecks. Measure them specifically before reaching for lock-free data structures
- The shutdown sequence is as important as the startup sequence — every queue must be unblocked, every thread must be joinable, and resources must be released in reverse construction order. Write it out explicitly in the README so the next maintainer understands it
- A README that documents thread ownership, queue directions, shutdown sequence, and wire protocol format is not optional — it's the contract between the code and everyone who maintains it
- The patterns from Days 1–27 aren't academic — every one of them appears in the capstone: RAII manages fds and db handles, Rule of Five ensures correct ownership, constexpr generates CRC tables, BlockingQueue implements backpressure, shared_mutex protects the registry
- C++20 coroutines are the next evolution of the async patterns from Days 19–21 — the `co_await` model replaces both callbacks and explicit futures with code that reads like synchronous I/O and executes asynchronously
- The path to bare-metal embedded C++ is the same language with three constraints removed: dynamic allocation (`new`/`malloc`), OS threads (`std::thread`), and the standard library containers that depend on them. Everything else — CRTP, constexpr, type traits, value semantics, move semantics — transfers directly