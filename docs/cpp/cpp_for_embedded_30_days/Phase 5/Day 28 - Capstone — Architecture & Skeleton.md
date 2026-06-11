
Everything from Days 1–27 converges here. Today you design and scaffold a production-grade IoT device driver: a complete system that reads from multiple sensors over a serial protocol, parses binary frames, publishes to MQTT, persists data to SQLite, and exposes a REST API. No toy examples — this is the architecture you'd deploy on a Raspberry Pi, BeagleBone, or any embedded Linux target.

The goal today is architecture and skeleton: get the full structure in place, every interface defined, every dependency wired up, and the build system configured. Days 29 and 30 implement, test, and ship it.

---

## 1. System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    mqtt_monitor                             │
│                                                             │
│  ┌──────────┐    ┌──────────┐    ┌──────────┐             │
│  │  Serial  │    │  Frame   │    │  Device  │             │
│  │  Reader  │───▶│  Parser  │───▶│ Registry │             │
│  │ (thread) │    │          │    │          │             │
│  └──────────┘    └──────────┘    └──────────┘             │
│       │                               │                    │
│       │          ┌──────────┐         │                    │
│       └─────────▶│Blocking  │◀────────┘                   │
│                  │  Queue   │                              │
│                  └──────────┘                              │
│                       │                                    │
│          ┌────────────┼────────────┐                       │
│          ▼            ▼            ▼                       │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐                │
│  │  SQLite  │  │   MQTT   │  │  FastAPI │                │
│  │ Persister│  │Publisher │  │(via REST)│                │
│  │(thread)  │  │(thread)  │  │          │                │
│  └──────────┘  └──────────┘  └──────────┘                │
└─────────────────────────────────────────────────────────────┘
```

**Thread model:**

- Main thread: orchestration, signal handling, graceful shutdown
- Reader thread: serial I/O, frame assembly
- Processor thread: parse frames, update registry, fan out to queues
- Persister thread: drain SQLite queue, batch writes
- Publisher thread: drain MQTT queue, publish with backpressure
- HTTP thread: serve REST API (synchronous, simple)

**Ownership model:**

- `BlockingQueue` instances owned by `Application`, shared via reference
- `DeviceRegistry` owned by `Application`, accessed under shared mutex
- Each thread object RAII-managed in `Application`
- All resources released in reverse construction order on shutdown

---

## 2. Project Structure

```
mqtt_monitor/
├── CMakeLists.txt
├── cmake/
│   ├── CompilerWarnings.cmake
│   └── Sanitizers.cmake
├── include/
│   └── mqtt_monitor/
│       ├── types.hpp          — shared data types
│       ├── ring_buffer.hpp    — lock-free ring buffer
│       ├── blocking_queue.hpp — thread-safe blocking queue
│       ├── frame_parser.hpp   — binary protocol parser
│       ├── device_registry.hpp
│       ├── serial_reader.hpp
│       ├── mqtt_publisher.hpp
│       ├── sqlite_persister.hpp
│       └── application.hpp
├── src/
│   ├── frame_parser.cpp
│   ├── device_registry.cpp
│   ├── serial_reader.cpp
│   ├── mqtt_publisher.cpp
│   ├── sqlite_persister.cpp
│   ├── application.cpp
│   └── main.cpp
└── tests/
    ├── CMakeLists.txt
    ├── test_frame_parser.cpp
    ├── test_device_registry.cpp
    ├── test_ring_buffer.cpp
    └── test_blocking_queue.cpp
```

---

## 3. CMakeLists.txt

```cmake
# CMakeLists.txt
cmake_minimum_required(VERSION 3.20)
project(mqtt_monitor
    VERSION     1.0.0
    DESCRIPTION "IoT MQTT device monitor"
    LANGUAGES   CXX
)

set(CMAKE_CXX_STANDARD          17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS        OFF)
set(CMAKE_EXPORT_COMPILE_COMMANDS ON)

if(NOT CMAKE_BUILD_TYPE)
    set(CMAKE_BUILD_TYPE RelWithDebInfo)
endif()

# Options
option(ENABLE_TESTING    "Build tests"        ON)
option(ENABLE_SANITIZERS "Enable sanitizers"  OFF)
option(ENABLE_CLANG_TIDY "Enable clang-tidy"  OFF)

include(cmake/CompilerWarnings.cmake)
include(cmake/Sanitizers.cmake)

# Dependencies
find_package(Threads REQUIRED)

# SQLite — use system or bundled
find_package(SQLite3)
if(NOT SQLite3_FOUND)
    # Fetch minimal sqlite amalgamation
    include(FetchContent)
    FetchContent_Declare(sqlite3
        URL https://www.sqlite.org/2024/sqlite-amalgamation-3450100.zip
    )
    FetchContent_MakeAvailable(sqlite3)
    add_library(sqlite3_lib STATIC
        ${sqlite3_SOURCE_DIR}/sqlite3.c)
    target_include_directories(sqlite3_lib PUBLIC
        ${sqlite3_SOURCE_DIR})
    add_library(SQLite::SQLite3 ALIAS sqlite3_lib)
endif()

# Core library — everything except main()
add_library(mqtt_monitor_lib STATIC
    src/frame_parser.cpp
    src/device_registry.cpp
    src/serial_reader.cpp
    src/mqtt_publisher.cpp
    src/sqlite_persister.cpp
    src/application.cpp
)

target_include_directories(mqtt_monitor_lib
    PUBLIC include/
)

target_link_libraries(mqtt_monitor_lib
    PUBLIC  Threads::Threads
    PRIVATE SQLite::SQLite3
)

target_set_warnings(mqtt_monitor_lib)
target_apply_sanitizers(mqtt_monitor_lib)

# Executable
add_executable(mqtt_monitor src/main.cpp)
target_link_libraries(mqtt_monitor PRIVATE mqtt_monitor_lib)
target_set_warnings(mqtt_monitor)
target_apply_sanitizers(mqtt_monitor)

# Tests
if(ENABLE_TESTING)
    enable_testing()
    add_subdirectory(tests)
endif()
```

```cmake
# cmake/CompilerWarnings.cmake
function(target_set_warnings target)
    if(CMAKE_CXX_COMPILER_ID MATCHES "GNU|Clang")
        target_compile_options(${target} PRIVATE
            -Wall -Wextra -Wpedantic
            -Wconversion -Wsign-conversion
            -Wcast-align -Wnull-dereference
            -Wdouble-promotion -Wshadow
        )
    endif()
endfunction()
```

```cmake
# cmake/Sanitizers.cmake
function(target_apply_sanitizers target)
    if(ENABLE_SANITIZERS)
        if(CMAKE_CXX_COMPILER_ID MATCHES "GNU|Clang")
            target_compile_options(${target} PRIVATE
                -fsanitize=address,undefined
                -fno-omit-frame-pointer
            )
            target_link_options(${target} PUBLIC
                -fsanitize=address,undefined
            )
        endif()
    endif()
endfunction()
```

---

## 4. Shared Types

```cpp
// include/mqtt_monitor/types.hpp
#pragma once
#include <cstdint>
#include <cstring>
#include <string>
#include <string_view>
#include <chrono>
#include <array>

namespace mqtt_monitor {

// ---- Sensor reading ----

enum class SensorType : uint8_t {
    Temperature = 0x01,
    Humidity    = 0x02,
    Pressure    = 0x03,
    Voltage     = 0x04,
    Current     = 0x05,
    Unknown     = 0xFF,
};

struct SensorReading {
    uint32_t   device_id;
    SensorType sensor_type;
    float      value;
    uint32_t   timestamp_ms;

    // For SQLite and REST API
    std::string topic() const {
        std::string t = "sensors/";
        switch (sensor_type) {
            case SensorType::Temperature: t += "temperature"; break;
            case SensorType::Humidity:    t += "humidity";    break;
            case SensorType::Pressure:    t += "pressure";    break;
            case SensorType::Voltage:     t += "voltage";     break;
            case SensorType::Current:     t += "current";     break;
            default:                      t += "unknown";     break;
        }
        t += "/" + std::to_string(device_id);
        return t;
    }

    const char* type_name() const {
        switch (sensor_type) {
            case SensorType::Temperature: return "temperature";
            case SensorType::Humidity:    return "humidity";
            case SensorType::Pressure:    return "pressure";
            case SensorType::Voltage:     return "voltage";
            case SensorType::Current:     return "current";
            default:                      return "unknown";
        }
    }
};

static_assert(sizeof(SensorReading) == 16,
              "SensorReading must be 16 bytes");

// ---- Device info ----

struct DeviceInfo {
    uint32_t    device_id;
    char        name[32];
    char        firmware[16];
    uint32_t    last_seen_ms;
    float       last_value;
    bool        online;
    uint32_t    reading_count;

    void set_name(std::string_view n) {
        size_t len = std::min(n.size(), sizeof(name) - 1);
        std::memcpy(name, n.data(), len);
        name[len] = '\0';
    }

    void set_firmware(std::string_view fw) {
        size_t len = std::min(fw.size(), sizeof(firmware) - 1);
        std::memcpy(firmware, fw.data(), len);
        firmware[len] = '\0';
    }
};

// ---- Wire frame format ----
// [0]     magic       0xAB
// [1]     version     0x01
// [2]     device_id   uint8_t (high nibble: type, low: id)
// [3]     sensor_type uint8_t
// [4..7]  timestamp   uint32_t LE
// [8..11] value       float32 LE
// [12]    checksum    CRC-8 of bytes [0..11]

constexpr uint8_t  FRAME_MAGIC   = 0xAB;
constexpr uint8_t  FRAME_VERSION = 0x01;
constexpr size_t   FRAME_SIZE    = 13;

struct WireFrame {
    uint8_t  magic;
    uint8_t  version;
    uint8_t  device_id;
    uint8_t  sensor_type;
    uint8_t  timestamp[4];   // LE uint32
    uint8_t  value[4];       // LE float32
    uint8_t  checksum;
};

static_assert(sizeof(WireFrame) == FRAME_SIZE,
              "WireFrame must be exactly 13 bytes");

// ---- Shutdown token ----

struct ShutdownToken {
    std::atomic<bool> requested{false};

    void request() {
        requested.store(true, std::memory_order_release);
    }

    bool is_requested() const {
        return requested.load(std::memory_order_acquire);
    }
};

// ---- Application config ----

struct Config {
    // Serial
    std::string serial_device   = "/dev/ttyUSB0";
    int         baud_rate       = 115200;

    // MQTT (simulated — paho-mqtt integration on Day 29)
    std::string mqtt_broker     = "localhost";
    uint16_t    mqtt_port       = 1883;
    std::string mqtt_client_id  = "mqtt_monitor";

    // SQLite
    std::string db_path         = "/tmp/mqtt_monitor.db";

    // Queue sizes
    size_t      parse_queue_size  = 256;
    size_t      persist_queue_size = 512;
    size_t      publish_queue_size = 256;

    // Timing
    uint32_t    stale_timeout_ms  = 30000;
    uint32_t    mqtt_reconnect_ms = 5000;
};

} // namespace mqtt_monitor
```

---

## 5. Core Data Structures

```cpp
// include/mqtt_monitor/blocking_queue.hpp
#pragma once
#include <mutex>
#include <condition_variable>
#include <queue>
#include <optional>
#include <atomic>
#include <chrono>

namespace mqtt_monitor {

template<typename T>
class BlockingQueue {
public:
    explicit BlockingQueue(size_t capacity)
        : capacity_(capacity)
        , shutdown_(false)
        , pushed_(0), popped_(0), dropped_(0)
    {}

    BlockingQueue(const BlockingQueue&)            = delete;
    BlockingQueue& operator=(const BlockingQueue&) = delete;

    bool push(T item) {
        std::unique_lock<std::mutex> lock(mtx_);
        not_full_.wait(lock, [this] {
            return queue_.size() < capacity_ || shutdown_;
        });
        if (shutdown_) return false;
        queue_.push(std::move(item));
        ++pushed_;
        lock.unlock();
        not_empty_.notify_one();
        return true;
    }

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

    std::optional<T> pop() {
        std::unique_lock<std::mutex> lock(mtx_);
        not_empty_.wait(lock, [this] {
            return !queue_.empty() || shutdown_;
        });
        if (queue_.empty()) return std::nullopt;
        T item = std::move(queue_.front());
        queue_.pop();
        ++popped_;
        lock.unlock();
        not_full_.notify_one();
        return item;
    }

    std::optional<T> pop_for(std::chrono::milliseconds timeout) {
        std::unique_lock<std::mutex> lock(mtx_);
        bool ok = not_empty_.wait_for(lock, timeout, [this] {
            return !queue_.empty() || shutdown_;
        });
        if (!ok || queue_.empty()) return std::nullopt;
        T item = std::move(queue_.front());
        queue_.pop();
        ++popped_;
        lock.unlock();
        not_full_.notify_one();
        return item;
    }

    void shutdown() {
        {
            std::lock_guard<std::mutex> lock(mtx_);
            shutdown_ = true;
        }
        not_empty_.notify_all();
        not_full_.notify_all();
    }

    bool   is_shutdown() const {
        std::lock_guard<std::mutex> lock(mtx_);
        return shutdown_;
    }

    size_t   size()    const {
        std::lock_guard<std::mutex> lock(mtx_);
        return queue_.size();
    }

    bool     empty()   const {
        std::lock_guard<std::mutex> lock(mtx_);
        return queue_.empty();
    }

    uint64_t pushed()  const {
        return pushed_.load(std::memory_order_relaxed);
    }
    uint64_t popped()  const {
        return popped_.load(std::memory_order_relaxed);
    }
    uint64_t dropped() const {
        return dropped_.load(std::memory_order_relaxed);
    }

private:
    mutable std::mutex      mtx_;
    std::condition_variable not_empty_;
    std::condition_variable not_full_;
    std::queue<T>           queue_;
    size_t                  capacity_;
    bool                    shutdown_;
    std::atomic<uint64_t>   pushed_;
    std::atomic<uint64_t>   popped_;
    std::atomic<uint64_t>   dropped_;
};

} // namespace mqtt_monitor
```

---

## 6. Interface Headers

```cpp
// include/mqtt_monitor/frame_parser.hpp
#pragma once
#include "mqtt_monitor/types.hpp"
#include <span>
#include <optional>
#include <array>
#include <cstdint>

namespace mqtt_monitor {

enum class ParseError {
    TooShort,
    BadMagic,
    BadVersion,
    ChecksumMismatch,
    InvalidSensorType,
};

std::string_view parse_error_str(ParseError e);

using ParseResult = std::variant<SensorReading, ParseError>;

// Parse a single 13-byte wire frame
ParseResult parse_frame(std::span<const uint8_t> frame);

// Scan a byte stream for complete frames
// Returns frames found and advances the buffer
std::vector<SensorReading> scan_frames(
    std::span<const uint8_t> buffer,
    size_t& bytes_consumed);

// Build a wire frame (for testing and simulation)
std::array<uint8_t, FRAME_SIZE> build_frame(
    uint8_t    device_id,
    SensorType sensor_type,
    uint32_t   timestamp_ms,
    float      value);

// CRC-8 computation
uint8_t crc8(std::span<const uint8_t> data);

} // namespace mqtt_monitor
```

```cpp
// include/mqtt_monitor/device_registry.hpp
#pragma once
#include "mqtt_monitor/types.hpp"
#include <unordered_map>
#include <vector>
#include <mutex>
#include <optional>
#include <functional>

namespace mqtt_monitor {

class DeviceRegistry {
public:
    explicit DeviceRegistry(uint32_t stale_timeout_ms = 30000);

    // Update or insert device from a reading
    void update(const SensorReading& reading);

    // Get device info by ID
    std::optional<DeviceInfo> get(uint32_t device_id) const;

    // Enumerate all devices
    std::vector<DeviceInfo> all() const;

    // Enumerate online devices
    std::vector<DeviceInfo> online() const;

    // Mark stale devices offline
    int mark_stale(uint32_t now_ms);

    // Count
    size_t size() const;

    // Visit all devices under lock
    void for_each(std::function<void(const DeviceInfo&)> fn) const;

private:
    mutable std::shared_mutex                    mtx_;
    std::unordered_map<uint32_t, DeviceInfo>    devices_;
    uint32_t                                    stale_timeout_ms_;
};

} // namespace mqtt_monitor
```

```cpp
// include/mqtt_monitor/serial_reader.hpp
#pragma once
#include "mqtt_monitor/types.hpp"
#include "mqtt_monitor/blocking_queue.hpp"
#include <string>
#include <thread>
#include <atomic>
#include <array>

namespace mqtt_monitor {

class SerialReader {
public:
    SerialReader(const Config&              config,
                 BlockingQueue<SensorReading>& output_queue,
                 ShutdownToken&             shutdown);

    ~SerialReader();

    SerialReader(const SerialReader&)            = delete;
    SerialReader& operator=(const SerialReader&) = delete;

    void start();
    void stop();
    bool running() const;

    // Stats
    uint64_t bytes_read()     const;
    uint64_t frames_parsed()  const;
    uint64_t parse_errors()   const;

private:
    void run();
    bool open_port();
    void close_port();

    const Config&                config_;
    BlockingQueue<SensorReading>& output_queue_;
    ShutdownToken&               shutdown_;

    int                          fd_      = -1;
    std::thread                  thread_;
    std::atomic<bool>            running_{false};

    std::atomic<uint64_t>        bytes_read_{0};
    std::atomic<uint64_t>        frames_parsed_{0};
    std::atomic<uint64_t>        parse_errors_{0};

    // Receive buffer — accumulates bytes between reads
    static constexpr size_t RX_BUF_SIZE = 1024;
    std::array<uint8_t, RX_BUF_SIZE> rx_buf_{};
    size_t                           rx_len_ = 0;
};

} // namespace mqtt_monitor
```

```cpp
// include/mqtt_monitor/sqlite_persister.hpp
#pragma once
#include "mqtt_monitor/types.hpp"
#include "mqtt_monitor/blocking_queue.hpp"
#include <string>
#include <thread>
#include <atomic>

struct sqlite3;  // forward declare — don't include sqlite3.h in header

namespace mqtt_monitor {

class SqlitePersister {
public:
    SqlitePersister(const Config&              config,
                    BlockingQueue<SensorReading>& input_queue,
                    ShutdownToken&             shutdown);

    ~SqlitePersister();

    SqlitePersister(const SqlitePersister&)            = delete;
    SqlitePersister& operator=(const SqlitePersister&) = delete;

    void start();
    void stop();
    bool running() const;

    uint64_t rows_written()  const;
    uint64_t write_errors()  const;

private:
    void run();
    bool open_db();
    void close_db();
    bool create_schema();
    bool insert_reading(const SensorReading& r);
    void flush_batch(std::vector<SensorReading>& batch);

    const Config&                config_;
    BlockingQueue<SensorReading>& input_queue_;
    ShutdownToken&               shutdown_;

    sqlite3*                     db_      = nullptr;
    std::thread                  thread_;
    std::atomic<bool>            running_{false};

    std::atomic<uint64_t>        rows_written_{0};
    std::atomic<uint64_t>        write_errors_{0};

    static constexpr size_t   BATCH_SIZE     = 64;
    static constexpr uint32_t FLUSH_INTERVAL = 1000;  // ms
};

} // namespace mqtt_monitor
```

```cpp
// include/mqtt_monitor/mqtt_publisher.hpp
#pragma once
#include "mqtt_monitor/types.hpp"
#include "mqtt_monitor/blocking_queue.hpp"
#include <string>
#include <thread>
#include <atomic>
#include <chrono>

namespace mqtt_monitor {

class MQTTPublisher {
public:
    MQTTPublisher(const Config&              config,
                  BlockingQueue<SensorReading>& input_queue,
                  ShutdownToken&             shutdown);

    ~MQTTPublisher();

    MQTTPublisher(const MQTTPublisher&)            = delete;
    MQTTPublisher& operator=(const MQTTPublisher&) = delete;

    void start();
    void stop();
    bool running()   const;
    bool connected() const;

    uint64_t published()    const;
    uint64_t pub_errors()   const;
    uint64_t reconnects()   const;

private:
    void run();
    bool connect();
    void disconnect();
    bool publish_reading(const SensorReading& r);

    const Config&                config_;
    BlockingQueue<SensorReading>& input_queue_;
    ShutdownToken&               shutdown_;

    std::thread                  thread_;
    std::atomic<bool>            running_{false};
    std::atomic<bool>            connected_{false};

    std::atomic<uint64_t>        published_{0};
    std::atomic<uint64_t>        pub_errors_{0};
    std::atomic<uint64_t>        reconnects_{0};

    // Reconnect backoff
    std::chrono::milliseconds    reconnect_delay_{1000};
    static constexpr auto        MAX_RECONNECT_DELAY =
                                     std::chrono::seconds(30);
};

} // namespace mqtt_monitor
```

---

## 7. Application Shell

```cpp
// include/mqtt_monitor/application.hpp
#pragma once
#include "mqtt_monitor/types.hpp"
#include "mqtt_monitor/blocking_queue.hpp"
#include "mqtt_monitor/device_registry.hpp"
#include "mqtt_monitor/serial_reader.hpp"
#include "mqtt_monitor/sqlite_persister.hpp"
#include "mqtt_monitor/mqtt_publisher.hpp"
#include <memory>
#include <csignal>

namespace mqtt_monitor {

class Application {
public:
    explicit Application(Config config);
    ~Application();

    Application(const Application&)            = delete;
    Application& operator=(const Application&) = delete;

    // Run until shutdown — blocks
    int run();

    // Signal from signal handler
    static void request_shutdown();

private:
    void start_all();
    void stop_all();
    void print_stats() const;

    Config config_;

    // Shared shutdown token
    ShutdownToken shutdown_;

    // Queues — owned here, shared by reference
    BlockingQueue<SensorReading> parse_queue_;
    BlockingQueue<SensorReading> persist_queue_;
    BlockingQueue<SensorReading> publish_queue_;

    // Subsystems — construction order = startup order
    DeviceRegistry   registry_;
    SerialReader     reader_;
    SqlitePersister  persister_;
    MQTTPublisher    publisher_;

    // Global instance for signal handler
    static Application* instance_;
};

} // namespace mqtt_monitor
```

```cpp
// src/application.cpp
#include "mqtt_monitor/application.hpp"
#include <cstdio>
#include <csignal>
#include <chrono>
#include <thread>

namespace mqtt_monitor {

Application* Application::instance_ = nullptr;

Application::Application(Config config)
    : config_    (std::move(config))
    , parse_queue_  (config_.parse_queue_size)
    , persist_queue_(config_.persist_queue_size)
    , publish_queue_(config_.publish_queue_size)
    , registry_  (config_.stale_timeout_ms)
    , reader_    (config_, parse_queue_,   shutdown_)
    , persister_ (config_, persist_queue_, shutdown_)
    , publisher_ (config_, publish_queue_, shutdown_)
{
    instance_ = this;
    printf("[app] constructed\n");
}

Application::~Application() {
    stop_all();
    instance_ = nullptr;
}

void Application::request_shutdown() {
    if (instance_) {
        printf("\n[app] shutdown requested\n");
        instance_->shutdown_.request();
    }
}

int Application::run() {
    // Install signal handlers
    std::signal(SIGINT,  [](int) { Application::request_shutdown(); });
    std::signal(SIGTERM, [](int) { Application::request_shutdown(); });

    printf("[app] starting subsystems\n");
    start_all();
    printf("[app] running — Ctrl+C to stop\n");

    // Main loop — monitors health, prints stats
    while (!shutdown_.is_requested()) {
        std::this_thread::sleep_for(std::chrono::seconds(5));
        if (!shutdown_.is_requested()) {
            print_stats();
        }
    }

    printf("[app] shutting down\n");
    stop_all();
    print_stats();
    return 0;
}

void Application::start_all() {
    persister_.start();
    publisher_.start();
    reader_.start();
    printf("[app] all subsystems started\n");
}

void Application::stop_all() {
    // Stop reader first — no new data after this
    reader_.stop();

    // Drain queues — give persisters time to finish
    parse_queue_.shutdown();
    persist_queue_.shutdown();
    publish_queue_.shutdown();

    persister_.stop();
    publisher_.stop();
    printf("[app] all subsystems stopped\n");
}

void Application::print_stats() const {
    printf("\n=== Stats ===\n");
    printf("  serial:    bytes_read=%-8llu frames=%-6llu errors=%llu\n",
           reader_.bytes_read(),
           reader_.frames_parsed(),
           reader_.parse_errors());
    printf("  sqlite:    rows=%-8llu errors=%llu\n",
           persister_.rows_written(),
           persister_.write_errors());
    printf("  mqtt:      published=%-6llu errors=%-4llu reconnects=%llu\n",
           publisher_.published(),
           publisher_.pub_errors(),
           publisher_.reconnects());
    printf("  queues:    parse=%zu persist=%zu publish=%zu\n",
           parse_queue_.size(),
           persist_queue_.size(),
           publish_queue_.size());
    printf("  devices:   %zu registered\n",
           registry_.size());
}

} // namespace mqtt_monitor
```

---

## 8. Stub Implementations

These stubs compile cleanly and let you verify the build system before Day 29 fills in the real implementations:

```cpp
// src/frame_parser.cpp
#include "mqtt_monitor/frame_parser.hpp"
#include <cstring>
#include <variant>

namespace mqtt_monitor {

constexpr std::array<uint8_t, 256> make_crc8_table() {
    std::array<uint8_t, 256> t{};
    for (int i = 0; i < 256; ++i) {
        uint8_t c = static_cast<uint8_t>(i);
        for (int j = 0; j < 8; ++j)
            c = (c & 0x80) ? (c << 1) ^ 0x07 : (c << 1);
        t[i] = c;
    }
    return t;
}

static constexpr auto CRC8_TABLE = make_crc8_table();

uint8_t crc8(std::span<const uint8_t> data) {
    uint8_t crc = 0;
    for (uint8_t b : data) crc = CRC8_TABLE[crc ^ b];
    return crc;
}

std::string_view parse_error_str(ParseError e) {
    switch (e) {
        case ParseError::TooShort:          return "too short";
        case ParseError::BadMagic:          return "bad magic";
        case ParseError::BadVersion:        return "bad version";
        case ParseError::ChecksumMismatch:  return "checksum mismatch";
        case ParseError::InvalidSensorType: return "invalid sensor type";
        default:                            return "unknown";
    }
}

ParseResult parse_frame(std::span<const uint8_t> frame) {
    if (frame.size() < FRAME_SIZE)
        return ParseError::TooShort;
    if (frame[0] != FRAME_MAGIC)
        return ParseError::BadMagic;
    if (frame[1] != FRAME_VERSION)
        return ParseError::BadVersion;

    uint8_t expected = crc8(frame.first(FRAME_SIZE - 1));
    if (expected != frame[FRAME_SIZE - 1])
        return ParseError::ChecksumMismatch;

    SensorReading r{};
    r.device_id   = frame[2];
    r.sensor_type = static_cast<SensorType>(frame[3]);

    uint32_t ts = static_cast<uint32_t>(frame[4])        |
                  static_cast<uint32_t>(frame[5]) <<  8  |
                  static_cast<uint32_t>(frame[6]) << 16  |
                  static_cast<uint32_t>(frame[7]) << 24;
    r.timestamp_ms = ts;

    uint32_t val_bits =
        static_cast<uint32_t>(frame[8])         |
        static_cast<uint32_t>(frame[9])  <<  8  |
        static_cast<uint32_t>(frame[10]) << 16  |
        static_cast<uint32_t>(frame[11]) << 24;
    std::memcpy(&r.value, &val_bits, 4);

    return r;
}

std::vector<SensorReading> scan_frames(
    std::span<const uint8_t> buffer,
    size_t& bytes_consumed)
{
    std::vector<SensorReading> results;
    bytes_consumed = 0;

    while (buffer.size() >= FRAME_SIZE) {
        // Scan for magic byte
        if (buffer[0] != FRAME_MAGIC) {
            buffer = buffer.subspan(1);
            ++bytes_consumed;
            continue;
        }

        auto result = parse_frame(buffer.first(FRAME_SIZE));
        if (std::holds_alternative<SensorReading>(result)) {
            results.push_back(std::get<SensorReading>(result));
            buffer = buffer.subspan(FRAME_SIZE);
            bytes_consumed += FRAME_SIZE;
        } else {
            // Bad frame — skip one byte and try again
            buffer = buffer.subspan(1);
            ++bytes_consumed;
        }
    }

    return results;
}

std::array<uint8_t, FRAME_SIZE> build_frame(
    uint8_t    device_id,
    SensorType sensor_type,
    uint32_t   timestamp_ms,
    float      value)
{
    std::array<uint8_t, FRAME_SIZE> f{};
    f[0] = FRAME_MAGIC;
    f[1] = FRAME_VERSION;
    f[2] = device_id;
    f[3] = static_cast<uint8_t>(sensor_type);
    f[4] = static_cast<uint8_t>(timestamp_ms & 0xFF);
    f[5] = static_cast<uint8_t>((timestamp_ms >>  8) & 0xFF);
    f[6] = static_cast<uint8_t>((timestamp_ms >> 16) & 0xFF);
    f[7] = static_cast<uint8_t>((timestamp_ms >> 24) & 0xFF);
    uint32_t v_bits;
    std::memcpy(&v_bits, &value, 4);
    f[8]  = static_cast<uint8_t>(v_bits & 0xFF);
    f[9]  = static_cast<uint8_t>((v_bits >>  8) & 0xFF);
    f[10] = static_cast<uint8_t>((v_bits >> 16) & 0xFF);
    f[11] = static_cast<uint8_t>((v_bits >> 24) & 0xFF);
    f[12] = crc8(std::span{f}.first(12));
    return f;
}

} // namespace mqtt_monitor
```

```cpp
// src/device_registry.cpp
#include "mqtt_monitor/device_registry.hpp"
#include <shared_mutex>

namespace mqtt_monitor {

DeviceRegistry::DeviceRegistry(uint32_t stale_timeout_ms)
    : stale_timeout_ms_(stale_timeout_ms) {}

void DeviceRegistry::update(const SensorReading& r) {
    std::unique_lock lock(mtx_);
    auto& dev = devices_[r.device_id];
    dev.device_id     = r.device_id;
    dev.last_seen_ms  = r.timestamp_ms;
    dev.last_value    = r.value;
    dev.online        = true;
    ++dev.reading_count;
    if (dev.name[0] == '\0') {
        dev.set_name("device_" + std::to_string(r.device_id));
        dev.set_firmware("v1.0");
    }
}

std::optional<DeviceInfo> DeviceRegistry::get(
    uint32_t device_id) const
{
    std::shared_lock lock(mtx_);
    auto it = devices_.find(device_id);
    if (it == devices_.end()) return std::nullopt;
    return it->second;
}

std::vector<DeviceInfo> DeviceRegistry::all() const {
    std::shared_lock lock(mtx_);
    std::vector<DeviceInfo> result;
    result.reserve(devices_.size());
    for (const auto& [id, dev] : devices_)
        result.push_back(dev);
    return result;
}

std::vector<DeviceInfo> DeviceRegistry::online() const {
    std::shared_lock lock(mtx_);
    std::vector<DeviceInfo> result;
    for (const auto& [id, dev] : devices_)
        if (dev.online) result.push_back(dev);
    return result;
}

int DeviceRegistry::mark_stale(uint32_t now_ms) {
    std::unique_lock lock(mtx_);
    int marked = 0;
    for (auto& [id, dev] : devices_) {
        if (dev.online &&
            (now_ms - dev.last_seen_ms) > stale_timeout_ms_) {
            dev.online = false;
            ++marked;
        }
    }
    return marked;
}

size_t DeviceRegistry::size() const {
    std::shared_lock lock(mtx_);
    return devices_.size();
}

void DeviceRegistry::for_each(
    std::function<void(const DeviceInfo&)> fn) const
{
    std::shared_lock lock(mtx_);
    for (const auto& [id, dev] : devices_) fn(dev);
}

} // namespace mqtt_monitor
```

```cpp
// src/serial_reader.cpp — stub
#include "mqtt_monitor/serial_reader.hpp"
#include <cstdio>
#include <cstring>

namespace mqtt_monitor {

SerialReader::SerialReader(
    const Config& config,
    BlockingQueue<SensorReading>& output_queue,
    ShutdownToken& shutdown)
    : config_(config)
    , output_queue_(output_queue)
    , shutdown_(shutdown)
{}

SerialReader::~SerialReader() { stop(); }

void SerialReader::start() {
    running_.store(true);
    thread_ = std::thread([this]() { run(); });
    printf("[serial] started (device=%s)\n",
           config_.serial_device.c_str());
}

void SerialReader::stop() {
    running_.store(false);
    if (thread_.joinable()) thread_.join();
}

bool SerialReader::running() const {
    return running_.load();
}

uint64_t SerialReader::bytes_read()    const {
    return bytes_read_.load(std::memory_order_relaxed);
}
uint64_t SerialReader::frames_parsed() const {
    return frames_parsed_.load(std::memory_order_relaxed);
}
uint64_t SerialReader::parse_errors()  const {
    return parse_errors_.load(std::memory_order_relaxed);
}

void SerialReader::run() {
    // Stub: Day 29 will implement real serial I/O
    // For now: generate simulated frames
    printf("[serial] reader thread running (simulated)\n");

    uint32_t ts = 0;
    while (running_.load() && !shutdown_.is_requested()) {
        // Simulate a frame arriving every 100ms
        std::this_thread::sleep_for(
            std::chrono::milliseconds(100));

        // Build and push a simulated reading
        SensorReading r{};
        r.device_id    = static_cast<uint32_t>(ts % 3);
        r.sensor_type  = static_cast<SensorType>(
            (ts % 3) + 1);
        r.timestamp_ms = ts * 100;
        r.value        = 20.0f + static_cast<float>(ts % 10);
        ++ts;

        frames_parsed_.fetch_add(1,
            std::memory_order_relaxed);

        if (!output_queue_.try_push(r)) {
            // Queue full — backpressure
            parse_errors_.fetch_add(1,
                std::memory_order_relaxed);
        }
    }

    printf("[serial] reader thread stopped\n");
}

} // namespace mqtt_monitor
```

```cpp
// src/sqlite_persister.cpp — stub
#include "mqtt_monitor/sqlite_persister.hpp"
#include <cstdio>

namespace mqtt_monitor {

SqlitePersister::SqlitePersister(
    const Config& config,
    BlockingQueue<SensorReading>& input_queue,
    ShutdownToken& shutdown)
    : config_(config)
    , input_queue_(input_queue)
    , shutdown_(shutdown)
{}

SqlitePersister::~SqlitePersister() { stop(); }

void SqlitePersister::start() {
    running_.store(true);
    thread_ = std::thread([this]() { run(); });
    printf("[sqlite] started (db=%s)\n",
           config_.db_path.c_str());
}

void SqlitePersister::stop() {
    running_.store(false);
    if (thread_.joinable()) thread_.join();
}

bool     SqlitePersister::running()      const { return running_.load(); }
uint64_t SqlitePersister::rows_written() const {
    return rows_written_.load(std::memory_order_relaxed);
}
uint64_t SqlitePersister::write_errors() const {
    return write_errors_.load(std::memory_order_relaxed);
}

void SqlitePersister::run() {
    printf("[sqlite] persister thread running (stub)\n");

    while (!shutdown_.is_requested()) {
        auto reading = input_queue_.pop_for(
            std::chrono::milliseconds(500));
        if (!reading) continue;

        // Stub: just count — Day 29 does real SQLite
        rows_written_.fetch_add(1,
            std::memory_order_relaxed);
        printf("[sqlite] stub persist: "
               "device=%u type=%u val=%.2f\n",
               reading->device_id,
               static_cast<uint8_t>(reading->sensor_type),
               reading->value);
    }

    printf("[sqlite] persister thread stopped\n");
}

} // namespace mqtt_monitor
```

```cpp
// src/mqtt_publisher.cpp — stub
#include "mqtt_monitor/mqtt_publisher.hpp"
#include <cstdio>

namespace mqtt_monitor {

MQTTPublisher::MQTTPublisher(
    const Config& config,
    BlockingQueue<SensorReading>& input_queue,
    ShutdownToken& shutdown)
    : config_(config)
    , input_queue_(input_queue)
    , shutdown_(shutdown)
{}

MQTTPublisher::~MQTTPublisher() { stop(); }

void MQTTPublisher::start() {
    running_.store(true);
    thread_ = std::thread([this]() { run(); });
    printf("[mqtt] publisher started "
           "(broker=%s:%u)\n",
           config_.mqtt_broker.c_str(),
           config_.mqtt_port);
}

void MQTTPublisher::stop() {
    running_.store(false);
    if (thread_.joinable()) thread_.join();
}

bool     MQTTPublisher::running()   const { return running_.load(); }
bool     MQTTPublisher::connected() const { return connected_.load(); }
uint64_t MQTTPublisher::published() const {
    return published_.load(std::memory_order_relaxed);
}
uint64_t MQTTPublisher::pub_errors() const {
    return pub_errors_.load(std::memory_order_relaxed);
}
uint64_t MQTTPublisher::reconnects() const {
    return reconnects_.load(std::memory_order_relaxed);
}

void MQTTPublisher::run() {
    printf("[mqtt] publisher thread running (stub)\n");
    connected_.store(true);  // pretend we're connected

    while (!shutdown_.is_requested()) {
        auto reading = input_queue_.pop_for(
            std::chrono::milliseconds(500));
        if (!reading) continue;

        // Stub: log the publish — Day 29 does real MQTT
        printf("[mqtt] stub publish: topic=%s val=%.2f\n",
               reading->topic().c_str(),
               reading->value);
        published_.fetch_add(1, std::memory_order_relaxed);
    }

    printf("[mqtt] publisher thread stopped\n");
}

} // namespace mqtt_monitor
```

```cpp
// src/main.cpp
#include "mqtt_monitor/application.hpp"
#include <cstdio>

int main(int argc, char* argv[]) {
    mqtt_monitor::Config config;

    // Simple argument parsing
    for (int i = 1; i < argc; ++i) {
        std::string arg = argv[i];
        if (arg == "--device" && i + 1 < argc) {
            config.serial_device = argv[++i];
        } else if (arg == "--broker" && i + 1 < argc) {
            config.mqtt_broker = argv[++i];
        } else if (arg == "--db" && i + 1 < argc) {
            config.db_path = argv[++i];
        }
    }

    printf("mqtt_monitor v1.0.0\n");
    printf("  device: %s\n", config.serial_device.c_str());
    printf("  broker: %s:%u\n",
           config.mqtt_broker.c_str(), config.mqtt_port);
    printf("  db:     %s\n\n", config.db_path.c_str());

    mqtt_monitor::Application app(config);
    return app.run();
}
```

---

## 9. Build and Verify

```bash
# Create and configure
mkdir -p mqtt_monitor && cd mqtt_monitor
# (create all files as above)

cmake -B build -DCMAKE_BUILD_TYPE=Debug -DENABLE_TESTING=ON
cmake --build build --parallel

# Run the skeleton
./build/mqtt_monitor --broker localhost --db /tmp/test.db

# Should print:
# mqtt_monitor v1.0.0
#   device: /dev/ttyUSB0
#   broker: localhost:1883
#   db:     /tmp/test.db
# [app] constructed
# [app] starting subsystems
# [sqlite] started ...
# [mqtt] publisher started ...
# [serial] started ...
# [serial] reader thread running (simulated)
# [mqtt] publisher thread running (stub)
# [sqlite] persister thread running (stub)
# [app] running — Ctrl+C to stop
# ... simulated readings flowing through ...
# ^C
# [app] shutdown requested
# [app] shutting down
```

---

## Key Takeaways for Day 28

- Architecture first: define thread ownership, queue directions, and shutdown sequence before writing any implementation. Changing these later requires rewriting interfaces
- Queues are the boundaries between threads — each thread owns its input queue and reads from it; the upstream thread writes to it. Never share a mutex across thread boundaries when a queue will do
- Construction order is shutdown order in reverse — `Application`'s member initialization list order determines startup; the destructor reverses it. Get this right or you'll join threads that are still reading from queues you've already shut down
- Forward-declare heavy dependencies in headers (`struct sqlite3`) — keep `#include <sqlite3.h>` in the `.cpp` file. Headers that include SQLite include SQLite in every translation unit that includes your header
- Stub implementations that compile and run are infinitely more valuable than perfect implementations that don't build yet. Every stub logs what it would do — you can see the data flowing before the real implementation exists
- `std::shared_mutex` for the device registry — multiple reader threads can query simultaneously (shared lock), the writer thread updates exclusively (unique lock). Use `std::shared_lock` for reads and `std::unique_lock` for writes
- `ShutdownToken` with `memory_order_acquire`/`release` on the atomic bool — every thread checks `shutdown_.is_requested()` in its loop condition, and the main thread's `shutdown_.request()` is visible to all threads immediately

Day 29 implements the real serial I/O loop, SQLite persistence with WAL mode, and MQTT publishing with reconnect logic — replacing every stub with production code.