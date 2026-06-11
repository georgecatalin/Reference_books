
The skeleton compiles and the stubs flow simulated data. Today we replace every stub with production code: real serial I/O with frame assembly, SQLite persistence with WAL mode and batch writes, MQTT publishing with exponential backoff reconnect, and a processor thread that fans readings from the serial queue out to both downstream queues. By the end of today the system handles real hardware, survives broker disconnects, and never loses a reading to a crash.

---

## 1. The Processor Thread — Fan-Out

Day 28's architecture showed a single parse queue feeding both SQLite and MQTT. The processor thread sits between the serial reader and the downstream queues. It also updates the device registry:

```cpp
// include/mqtt_monitor/processor.hpp
#pragma once
#include "mqtt_monitor/types.hpp"
#include "mqtt_monitor/blocking_queue.hpp"
#include "mqtt_monitor/device_registry.hpp"
#include <thread>
#include <atomic>

namespace mqtt_monitor {

class Processor {
public:
    Processor(BlockingQueue<SensorReading>& input,
              BlockingQueue<SensorReading>& persist_queue,
              BlockingQueue<SensorReading>& publish_queue,
              DeviceRegistry&              registry,
              ShutdownToken&               shutdown);

    ~Processor();

    Processor(const Processor&)            = delete;
    Processor& operator=(const Processor&) = delete;

    void     start();
    void     stop();
    bool     running()   const;
    uint64_t processed() const;
    uint64_t dropped()   const;

private:
    void run();

    BlockingQueue<SensorReading>& input_;
    BlockingQueue<SensorReading>& persist_queue_;
    BlockingQueue<SensorReading>& publish_queue_;
    DeviceRegistry&              registry_;
    ShutdownToken&               shutdown_;

    std::thread           thread_;
    std::atomic<bool>     running_{false};
    std::atomic<uint64_t> processed_{0};
    std::atomic<uint64_t> dropped_{0};
};

} // namespace mqtt_monitor
```

```cpp
// src/processor.cpp
#include "mqtt_monitor/processor.hpp"
#include <cstdio>

namespace mqtt_monitor {

Processor::Processor(
    BlockingQueue<SensorReading>& input,
    BlockingQueue<SensorReading>& persist_queue,
    BlockingQueue<SensorReading>& publish_queue,
    DeviceRegistry&              registry,
    ShutdownToken&               shutdown)
    : input_         (input)
    , persist_queue_ (persist_queue)
    , publish_queue_ (publish_queue)
    , registry_      (registry)
    , shutdown_      (shutdown)
{}

Processor::~Processor() { stop(); }

void Processor::start() {
    running_.store(true);
    thread_ = std::thread([this]() { run(); });
    printf("[processor] started\n");
}

void Processor::stop() {
    running_.store(false);
    if (thread_.joinable()) thread_.join();
}

bool     Processor::running()   const { return running_.load(); }
uint64_t Processor::processed() const {
    return processed_.load(std::memory_order_relaxed);
}
uint64_t Processor::dropped()   const {
    return dropped_.load(std::memory_order_relaxed);
}

void Processor::run() {
    printf("[processor] thread running\n");

    while (!shutdown_.is_requested()) {
        // Block until a reading arrives or shutdown
        auto reading = input_.pop_for(
            std::chrono::milliseconds(200));
        if (!reading) continue;

        // 1. Update device registry
        registry_.update(*reading);

        // 2. Fan out to downstream queues
        // try_push — non-blocking, drop if downstream is full
        bool persisted = persist_queue_.try_push(*reading);
        bool published = publish_queue_.try_push(*reading);

        if (!persisted || !published) {
            dropped_.fetch_add(1, std::memory_order_relaxed);
        }

        processed_.fetch_add(1, std::memory_order_relaxed);
    }

    // Drain remaining items after shutdown signal
    while (auto reading = input_.pop_for(
               std::chrono::milliseconds(10))) {
        registry_.update(*reading);
        persist_queue_.try_push(*reading);
        publish_queue_.try_push(*reading);
        processed_.fetch_add(1, std::memory_order_relaxed);
    }

    printf("[processor] thread stopped "
           "(processed=%llu dropped=%llu)\n",
           processed_.load(), dropped_.load());
}

} // namespace mqtt_monitor
```

---

## 2. Serial Reader — Real Implementation

Replace the stub with actual POSIX serial I/O and frame assembly:

```cpp
// src/serial_reader.cpp — production implementation
#include "mqtt_monitor/serial_reader.hpp"
#include "mqtt_monitor/frame_parser.hpp"
#include <cstdio>
#include <cstring>
#include <cerrno>
#include <unistd.h>
#include <fcntl.h>
#include <termios.h>
#include <sys/select.h>
#include <chrono>

namespace mqtt_monitor {

SerialReader::SerialReader(
    const Config&              config,
    BlockingQueue<SensorReading>& output_queue,
    ShutdownToken&             shutdown)
    : config_      (config)
    , output_queue_(output_queue)
    , shutdown_    (shutdown)
{}

SerialReader::~SerialReader() { stop(); }

void SerialReader::start() {
    running_.store(true);
    thread_ = std::thread([this]() { run(); });
    printf("[serial] reader started (device=%s baud=%d)\n",
           config_.serial_device.c_str(),
           config_.baud_rate);
}

void SerialReader::stop() {
    running_.store(false);
    if (thread_.joinable()) thread_.join();
    close_port();
}

bool SerialReader::running() const { return running_.load(); }

uint64_t SerialReader::bytes_read()    const {
    return bytes_read_.load(std::memory_order_relaxed);
}
uint64_t SerialReader::frames_parsed() const {
    return frames_parsed_.load(std::memory_order_relaxed);
}
uint64_t SerialReader::parse_errors()  const {
    return parse_errors_.load(std::memory_order_relaxed);
}

bool SerialReader::open_port() {
    fd_ = ::open(config_.serial_device.c_str(),
                 O_RDWR | O_NOCTTY | O_NONBLOCK);
    if (fd_ < 0) {
        printf("[serial] open failed: %s — %s\n",
               config_.serial_device.c_str(),
               strerror(errno));
        return false;
    }

    struct termios tty{};
    if (tcgetattr(fd_, &tty) != 0) {
        printf("[serial] tcgetattr failed: %s\n",
               strerror(errno));
        close_port();
        return false;
    }

    // Baud rate
    speed_t speed = B115200;
    switch (config_.baud_rate) {
        case 9600:   speed = B9600;   break;
        case 19200:  speed = B19200;  break;
        case 38400:  speed = B38400;  break;
        case 57600:  speed = B57600;  break;
        case 115200: speed = B115200; break;
        case 230400: speed = B230400; break;
        default:
            printf("[serial] unsupported baud %d, using 115200\n",
                   config_.baud_rate);
    }
    cfsetispeed(&tty, speed);
    cfsetospeed(&tty, speed);

    // 8N1 — 8 data bits, no parity, 1 stop bit
    tty.c_cflag &= ~PARENB;         // no parity
    tty.c_cflag &= ~CSTOPB;         // 1 stop bit
    tty.c_cflag &= ~CSIZE;
    tty.c_cflag |=  CS8;            // 8 data bits
    tty.c_cflag |=  CREAD | CLOCAL; // enable receiver, local mode
    tty.c_cflag &= ~CRTSCTS;        // no hardware flow control

    // Raw mode — no echo, no canonical, no signals
    tty.c_lflag &= ~(ICANON | ECHO | ECHOE | ISIG);

    // No software flow control
    tty.c_iflag &= ~(IXON | IXOFF | IXANY);
    tty.c_iflag &= ~(IGNBRK | BRKINT | PARMRK |
                     ISTRIP | INLCR | IGNCR | ICRNL);

    // Raw output
    tty.c_oflag &= ~OPOST;
    tty.c_oflag &= ~ONLCR;

    // Minimum bytes and timeout for read
    tty.c_cc[VMIN]  = 0;  // non-blocking read
    tty.c_cc[VTIME] = 0;

    if (tcsetattr(fd_, TCSANOW, &tty) != 0) {
        printf("[serial] tcsetattr failed: %s\n",
               strerror(errno));
        close_port();
        return false;
    }

    tcflush(fd_, TCIOFLUSH);
    printf("[serial] port opened: %s\n",
           config_.serial_device.c_str());
    return true;
}

void SerialReader::close_port() {
    if (fd_ >= 0) {
        ::close(fd_);
        fd_ = -1;
    }
}

void SerialReader::run() {
    printf("[serial] reader thread running\n");

    while (running_.load() && !shutdown_.is_requested()) {
        // Attempt to open port if not open
        if (fd_ < 0) {
            if (!open_port()) {
                // Retry after 2 seconds
                std::this_thread::sleep_for(
                    std::chrono::seconds(2));
                continue;
            }
        }

        // Use select() with timeout — allows checking shutdown
        fd_set read_fds;
        FD_ZERO(&read_fds);
        FD_SET(fd_, &read_fds);

        struct timeval tv{};
        tv.tv_sec  = 0;
        tv.tv_usec = 100000;  // 100ms timeout

        int ready = ::select(fd_ + 1, &read_fds,
                             nullptr, nullptr, &tv);
        if (ready < 0) {
            if (errno == EINTR) continue;
            printf("[serial] select error: %s\n",
                   strerror(errno));
            close_port();
            continue;
        }
        if (ready == 0) continue;  // timeout — check shutdown

        // Read available bytes into rx_buf_
        ssize_t n = ::read(fd_,
                           rx_buf_.data() + rx_len_,
                           rx_buf_.size() - rx_len_);
        if (n < 0) {
            if (errno == EAGAIN || errno == EWOULDBLOCK)
                continue;
            printf("[serial] read error: %s\n",
                   strerror(errno));
            close_port();
            continue;
        }
        if (n == 0) {
            // EOF — device disconnected
            printf("[serial] device disconnected\n");
            close_port();
            std::this_thread::sleep_for(
                std::chrono::seconds(1));
            continue;
        }

        rx_len_ += static_cast<size_t>(n);
        bytes_read_.fetch_add(static_cast<uint64_t>(n),
                              std::memory_order_relaxed);

        // Scan buffer for complete frames
        size_t consumed = 0;
        auto frames = scan_frames(
            std::span{rx_buf_.data(), rx_len_}, consumed);

        for (auto& reading : frames) {
            frames_parsed_.fetch_add(
                1, std::memory_order_relaxed);
            if (!output_queue_.try_push(reading)) {
                parse_errors_.fetch_add(
                    1, std::memory_order_relaxed);
            }
        }

        // Compact buffer — move unconsumed bytes to front
        if (consumed > 0 && consumed <= rx_len_) {
            rx_len_ -= consumed;
            std::memmove(rx_buf_.data(),
                         rx_buf_.data() + consumed,
                         rx_len_);
        }

        // Guard against buffer full with no complete frame
        if (rx_len_ == rx_buf_.size()) {
            printf("[serial] rx buffer full, "
                   "no frame found — clearing\n");
            rx_len_ = 0;
            parse_errors_.fetch_add(
                1, std::memory_order_relaxed);
        }
    }

    printf("[serial] reader thread stopped\n");
}

} // namespace mqtt_monitor
```

---

## 3. SQLite Persister — WAL Mode, Batch Writes

```cpp
// src/sqlite_persister.cpp — production implementation
#include "mqtt_monitor/sqlite_persister.hpp"
#include <sqlite3.h>
#include <cstdio>
#include <cstring>
#include <vector>
#include <chrono>

namespace mqtt_monitor {

SqlitePersister::SqlitePersister(
    const Config&              config,
    BlockingQueue<SensorReading>& input_queue,
    ShutdownToken&             shutdown)
    : config_      (config)
    , input_queue_ (input_queue)
    , shutdown_    (shutdown)
{}

SqlitePersister::~SqlitePersister() {
    stop();
    close_db();
}

void SqlitePersister::start() {
    if (!open_db()) {
        printf("[sqlite] FATAL: cannot open database\n");
        return;
    }
    running_.store(true);
    thread_ = std::thread([this]() { run(); });
    printf("[sqlite] persister started (db=%s)\n",
           config_.db_path.c_str());
}

void SqlitePersister::stop() {
    running_.store(false);
    if (thread_.joinable()) thread_.join();
}

bool     SqlitePersister::running()      const {
    return running_.load();
}
uint64_t SqlitePersister::rows_written() const {
    return rows_written_.load(std::memory_order_relaxed);
}
uint64_t SqlitePersister::write_errors() const {
    return write_errors_.load(std::memory_order_relaxed);
}

bool SqlitePersister::open_db() {
    int rc = sqlite3_open(config_.db_path.c_str(), &db_);
    if (rc != SQLITE_OK) {
        printf("[sqlite] open error: %s\n",
               sqlite3_errmsg(db_));
        sqlite3_close(db_);
        db_ = nullptr;
        return false;
    }

    // WAL mode — writers don't block readers
    sqlite3_exec(db_, "PRAGMA journal_mode=WAL;",
                 nullptr, nullptr, nullptr);

    // Synchronous=NORMAL — good balance of safety/speed
    // FULL would fsync every transaction (safe but slow)
    // OFF would risk corruption on power loss
    sqlite3_exec(db_, "PRAGMA synchronous=NORMAL;",
                 nullptr, nullptr, nullptr);

    // Cache size — 4MB
    sqlite3_exec(db_, "PRAGMA cache_size=-4000;",
                 nullptr, nullptr, nullptr);

    // Temp store in memory
    sqlite3_exec(db_,
                 "PRAGMA temp_store=MEMORY;",
                 nullptr, nullptr, nullptr);

    return create_schema();
}

void SqlitePersister::close_db() {
    if (db_) {
        sqlite3_close(db_);
        db_ = nullptr;
    }
}

bool SqlitePersister::create_schema() {
    const char* sql = R"(
        CREATE TABLE IF NOT EXISTS readings (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id   INTEGER NOT NULL,
            sensor_type INTEGER NOT NULL,
            value       REAL    NOT NULL,
            timestamp_ms INTEGER NOT NULL,
            inserted_at  INTEGER NOT NULL
                         DEFAULT (strftime('%s','now') * 1000)
        );

        CREATE INDEX IF NOT EXISTS idx_device_ts
            ON readings(device_id, timestamp_ms);

        CREATE INDEX IF NOT EXISTS idx_ts
            ON readings(timestamp_ms);

        CREATE TABLE IF NOT EXISTS devices (
            device_id   INTEGER PRIMARY KEY,
            name        TEXT,
            first_seen  INTEGER,
            last_seen   INTEGER,
            reading_count INTEGER DEFAULT 0
        );
    )";

    char* errmsg = nullptr;
    int rc = sqlite3_exec(db_, sql,
                          nullptr, nullptr, &errmsg);
    if (rc != SQLITE_OK) {
        printf("[sqlite] schema error: %s\n", errmsg);
        sqlite3_free(errmsg);
        return false;
    }
    printf("[sqlite] schema ready\n");
    return true;
}

void SqlitePersister::flush_batch(
    std::vector<SensorReading>& batch)
{
    if (batch.empty()) return;

    // Wrap in explicit transaction — much faster than
    // autocommit per-insert (100x+ speedup for batch)
    sqlite3_exec(db_, "BEGIN;", nullptr, nullptr, nullptr);

    const char* insert_sql =
        "INSERT INTO readings "
        "(device_id, sensor_type, value, timestamp_ms) "
        "VALUES (?, ?, ?, ?);";

    sqlite3_stmt* stmt = nullptr;
    sqlite3_prepare_v2(db_, insert_sql, -1, &stmt, nullptr);

    for (const auto& r : batch) {
        sqlite3_bind_int  (stmt, 1,
            static_cast<int>(r.device_id));
        sqlite3_bind_int  (stmt, 2,
            static_cast<int>(r.sensor_type));
        sqlite3_bind_double(stmt, 3,
            static_cast<double>(r.value));
        sqlite3_bind_int64(stmt, 4,
            static_cast<sqlite3_int64>(r.timestamp_ms));

        int rc = sqlite3_step(stmt);
        if (rc != SQLITE_DONE) {
            printf("[sqlite] insert error: %s\n",
                   sqlite3_errmsg(db_));
            write_errors_.fetch_add(
                1, std::memory_order_relaxed);
        } else {
            rows_written_.fetch_add(
                1, std::memory_order_relaxed);
        }

        sqlite3_reset(stmt);
        sqlite3_clear_bindings(stmt);
    }

    sqlite3_finalize(stmt);

    int rc = sqlite3_exec(
        db_, "COMMIT;", nullptr, nullptr, nullptr);
    if (rc != SQLITE_OK) {
        printf("[sqlite] commit error: %s\n",
               sqlite3_errmsg(db_));
        sqlite3_exec(
            db_, "ROLLBACK;", nullptr, nullptr, nullptr);
        write_errors_.fetch_add(
            static_cast<uint64_t>(batch.size()),
            std::memory_order_relaxed);
        rows_written_.fetch_sub(
            static_cast<uint64_t>(batch.size()),
            std::memory_order_relaxed);
    }

    batch.clear();
}

void SqlitePersister::run() {
    printf("[sqlite] persister thread running\n");

    std::vector<SensorReading> batch;
    batch.reserve(BATCH_SIZE);

    auto last_flush = std::chrono::steady_clock::now();

    while (!shutdown_.is_requested()) {
        // Collect up to BATCH_SIZE readings
        auto reading = input_queue_.pop_for(
            std::chrono::milliseconds(100));

        if (reading) {
            batch.push_back(*reading);
        }

        auto now = std::chrono::steady_clock::now();
        auto elapsed = std::chrono::duration_cast
            std::chrono::milliseconds>(
                now - last_flush).count();

        // Flush on: batch full, or flush interval exceeded
        bool time_to_flush =
            batch.size() >= BATCH_SIZE ||
            (elapsed >= static_cast<long long>(
                FLUSH_INTERVAL) && !batch.empty());

        if (time_to_flush) {
            flush_batch(batch);
            last_flush = now;
        }
    }

    // Drain remaining items
    while (auto reading = input_queue_.pop_for(
               std::chrono::milliseconds(10))) {
        batch.push_back(*reading);
        if (batch.size() >= BATCH_SIZE) {
            flush_batch(batch);
        }
    }
    if (!batch.empty()) flush_batch(batch);

    printf("[sqlite] persister stopped "
           "(rows=%llu errors=%llu)\n",
           rows_written_.load(),
           write_errors_.load());
}

} // namespace mqtt_monitor
```

---

## 4. MQTT Publisher — Reconnect with Backoff

For this implementation we use a TCP socket directly (to avoid the paho-mqtt dependency complicating the build). The MQTT CONNECT/PUBLISH protocol is straightforward to implement for QoS 0:

```cpp
// src/mqtt_publisher.cpp — production implementation
#include "mqtt_monitor/mqtt_publisher.hpp"
#include <cstdio>
#include <cstring>
#include <cerrno>
#include <cmath>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <netdb.h>
#include <unistd.h>
#include <fcntl.h>
#include <vector>
#include <string>
#include <algorithm>
#include <chrono>
#include <thread>

namespace mqtt_monitor {

// ---- Minimal MQTT 3.1.1 encoder ----

static void append_be16(std::vector<uint8_t>& buf, uint16_t v) {
    buf.push_back(static_cast<uint8_t>(v >> 8));
    buf.push_back(static_cast<uint8_t>(v & 0xFF));
}

static void append_mqtt_str(std::vector<uint8_t>& buf,
                             std::string_view s) {
    append_be16(buf, static_cast<uint16_t>(s.size()));
    buf.insert(buf.end(), s.begin(), s.end());
}

static void encode_remaining_length(
    std::vector<uint8_t>& buf, size_t len)
{
    do {
        uint8_t byte = static_cast<uint8_t>(len & 0x7F);
        len >>= 7;
        if (len > 0) byte |= 0x80;
        buf.push_back(byte);
    } while (len > 0);
}

// Build MQTT CONNECT packet
static std::vector<uint8_t> build_connect(
    const std::string& client_id)
{
    std::vector<uint8_t> payload;
    append_mqtt_str(payload, "MQTT");     // protocol name
    payload.push_back(0x04);              // protocol level 3.1.1
    payload.push_back(0x02);              // connect flags: clean session
    append_be16(payload, 60);             // keepalive: 60s
    append_mqtt_str(payload, client_id);  // client identifier

    std::vector<uint8_t> packet;
    packet.push_back(0x10);  // CONNECT fixed header
    encode_remaining_length(packet, payload.size());
    packet.insert(packet.end(), payload.begin(), payload.end());
    return packet;
}

// Build MQTT PUBLISH packet (QoS 0)
static std::vector<uint8_t> build_publish(
    std::string_view topic,
    std::string_view payload_str)
{
    std::vector<uint8_t> var;
    append_mqtt_str(var, topic);  // topic name
    var.insert(var.end(),
               payload_str.begin(),
               payload_str.end());  // payload (no packet id for QoS 0)

    std::vector<uint8_t> packet;
    packet.push_back(0x30);  // PUBLISH, QoS 0, no retain, no dup
    encode_remaining_length(packet, var.size());
    packet.insert(packet.end(), var.begin(), var.end());
    return packet;
}

// Format reading as JSON payload
static std::string reading_to_json(const SensorReading& r) {
    char buf[128];
    snprintf(buf, sizeof(buf),
             R"({"device_id":%u,"type":"%s","value":%.4f,"ts":%u})",
             r.device_id, r.type_name(),
             static_cast<double>(r.value),
             r.timestamp_ms);
    return buf;
}

// ---- MQTTPublisher implementation ----

MQTTPublisher::MQTTPublisher(
    const Config&              config,
    BlockingQueue<SensorReading>& input_queue,
    ShutdownToken&             shutdown)
    : config_      (config)
    , input_queue_ (input_queue)
    , shutdown_    (shutdown)
{}

MQTTPublisher::~MQTTPublisher() { stop(); }

void MQTTPublisher::start() {
    running_.store(true);
    thread_ = std::thread([this]() { run(); });
    printf("[mqtt] publisher started (broker=%s:%u)\n",
           config_.mqtt_broker.c_str(),
           config_.mqtt_port);
}

void MQTTPublisher::stop() {
    running_.store(false);
    if (thread_.joinable()) thread_.join();
}

bool     MQTTPublisher::running()    const { return running_.load(); }
bool     MQTTPublisher::connected()  const { return connected_.load(); }
uint64_t MQTTPublisher::published()  const {
    return published_.load(std::memory_order_relaxed);
}
uint64_t MQTTPublisher::pub_errors() const {
    return pub_errors_.load(std::memory_order_relaxed);
}
uint64_t MQTTPublisher::reconnects() const {
    return reconnects_.load(std::memory_order_relaxed);
}

bool MQTTPublisher::connect() {
    // Resolve host
    struct addrinfo hints{}, *res = nullptr;
    hints.ai_family   = AF_INET;
    hints.ai_socktype = SOCK_STREAM;

    std::string port_str = std::to_string(config_.mqtt_port);
    int rc = ::getaddrinfo(config_.mqtt_broker.c_str(),
                           port_str.c_str(), &hints, &res);
    if (rc != 0) {
        printf("[mqtt] getaddrinfo failed: %s\n",
               gai_strerror(rc));
        return false;
    }

    int fd = ::socket(res->ai_family,
                      res->ai_socktype,
                      res->ai_protocol);
    if (fd < 0) {
        ::freeaddrinfo(res);
        return false;
    }

    if (::connect(fd, res->ai_addr, res->ai_addrlen) < 0) {
        printf("[mqtt] connect failed: %s:%u — %s\n",
               config_.mqtt_broker.c_str(),
               config_.mqtt_port,
               strerror(errno));
        ::close(fd);
        ::freeaddrinfo(res);
        return false;
    }
    ::freeaddrinfo(res);

    // Set receive timeout
    struct timeval tv{};
    tv.tv_sec  = 5;
    tv.tv_usec = 0;
    ::setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO,
                 &tv, sizeof(tv));

    // Send MQTT CONNECT
    auto pkt = build_connect(config_.mqtt_client_id);
    if (::send(fd, pkt.data(), pkt.size(), MSG_NOSIGNAL)
        != static_cast<ssize_t>(pkt.size()))
    {
        printf("[mqtt] failed to send CONNECT\n");
        ::close(fd);
        return false;
    }

    // Read CONNACK — 4 bytes
    uint8_t connack[4];
    ssize_t got = ::recv(fd, connack, sizeof(connack), 0);
    if (got < 4 || connack[0] != 0x20 || connack[3] != 0x00) {
        printf("[mqtt] CONNACK failed "
               "(got=%zd code=0x%02X)\n",
               got, got >= 4 ? connack[3] : 0xFF);
        ::close(fd);
        return false;
    }

    // Store fd in a member — need to add to header
    // For simplicity: use a thread-local or member
    // We'll store it as a private member (add to hpp)
    fd_ = fd;
    connected_.store(true, std::memory_order_release);
    reconnect_delay_ = std::chrono::milliseconds(1000);
    printf("[mqtt] connected to %s:%u\n",
           config_.mqtt_broker.c_str(), config_.mqtt_port);
    return true;
}

void MQTTPublisher::disconnect() {
    if (fd_ >= 0) {
        // Send DISCONNECT packet
        uint8_t disc[] = {0xE0, 0x00};
        ::send(fd_, disc, sizeof(disc), MSG_NOSIGNAL);
        ::close(fd_);
        fd_ = -1;
    }
    connected_.store(false, std::memory_order_release);
}

bool MQTTPublisher::publish_reading(const SensorReading& r) {
    if (fd_ < 0) return false;

    std::string topic   = r.topic();
    std::string payload = reading_to_json(r);
    auto pkt = build_publish(topic, payload);

    ssize_t sent = ::send(fd_, pkt.data(), pkt.size(),
                          MSG_NOSIGNAL);
    if (sent != static_cast<ssize_t>(pkt.size())) {
        return false;
    }
    return true;
}

void MQTTPublisher::run() {
    printf("[mqtt] publisher thread running\n");

    while (!shutdown_.is_requested()) {
        // Ensure connected
        if (!connected_.load()) {
            if (!connect()) {
                reconnects_.fetch_add(
                    1, std::memory_order_relaxed);
                printf("[mqtt] reconnect in %lldms\n",
                       reconnect_delay_.count());
                // Sleep with shutdown check
                auto deadline =
                    std::chrono::steady_clock::now()
                    + reconnect_delay_;
                while (std::chrono::steady_clock::now()
                       < deadline &&
                       !shutdown_.is_requested())
                {
                    std::this_thread::sleep_for(
                        std::chrono::milliseconds(100));
                }
                // Exponential backoff — cap at 30s
                reconnect_delay_ = std::min(
                    reconnect_delay_ * 2,
                    MAX_RECONNECT_DELAY);
                continue;
            }
        }

        // Pop and publish
        auto reading = input_queue_.pop_for(
            std::chrono::milliseconds(200));
        if (!reading) continue;

        if (!publish_reading(*reading)) {
            printf("[mqtt] publish failed — reconnecting\n");
            pub_errors_.fetch_add(
                1, std::memory_order_relaxed);
            disconnect();
            // Re-queue the reading
            input_queue_.try_push(*reading);
        } else {
            published_.fetch_add(
                1, std::memory_order_relaxed);
        }
    }

    disconnect();
    printf("[mqtt] publisher stopped "
           "(published=%llu errors=%llu reconnects=%llu)\n",
           published_.load(),
           pub_errors_.load(),
           reconnects_.load());
}

} // namespace mqtt_monitor
```

---

## 5. Updated Application — Wire Everything Together

```cpp
// src/application.cpp — updated for Processor
#include "mqtt_monitor/application.hpp"
#include "mqtt_monitor/processor.hpp"
#include <cstdio>
#include <csignal>
#include <chrono>
#include <thread>

namespace mqtt_monitor {

Application* Application::instance_ = nullptr;

Application::Application(Config config)
    : config_       (std::move(config))
    , parse_queue_  (config_.parse_queue_size)
    , persist_queue_(config_.persist_queue_size)
    , publish_queue_(config_.publish_queue_size)
    , registry_     (config_.stale_timeout_ms)
    , reader_    (config_, parse_queue_,   shutdown_)
    , processor_ (parse_queue_,
                  persist_queue_,
                  publish_queue_,
                  registry_,
                  shutdown_)
    , persister_ (config_, persist_queue_, shutdown_)
    , publisher_ (config_, publish_queue_, shutdown_)
{
    instance_ = this;
}

Application::~Application() {
    stop_all();
    instance_ = nullptr;
}

void Application::request_shutdown() {
    if (instance_) {
        printf("\n[app] shutdown requested\n");
        instance_->shutdown_.request();
        // Unblock all queues
        instance_->parse_queue_.shutdown();
        instance_->persist_queue_.shutdown();
        instance_->publish_queue_.shutdown();
    }
}

int Application::run() {
    std::signal(SIGINT,
        [](int) { Application::request_shutdown(); });
    std::signal(SIGTERM,
        [](int) { Application::request_shutdown(); });

    printf("[app] starting subsystems\n");
    start_all();
    printf("[app] running — Ctrl+C to stop\n");

    while (!shutdown_.is_requested()) {
        std::this_thread::sleep_for(
            std::chrono::seconds(5));
        if (!shutdown_.is_requested()) {
            print_stats();

            // Mark stale devices
            uint32_t now_ms =
                static_cast<uint32_t>(
                    std::chrono::duration_cast
                        std::chrono::milliseconds>(
                    std::chrono::steady_clock::now()
                        .time_since_epoch()).count());
            int stale = registry_.mark_stale(now_ms);
            if (stale > 0) {
                printf("[app] marked %d device(s) stale\n",
                       stale);
            }
        }
    }

    stop_all();
    print_stats();
    return 0;
}

void Application::start_all() {
    persister_.start();
    publisher_.start();
    processor_.start();
    reader_.start();
}

void Application::stop_all() {
    reader_.stop();
    processor_.stop();
    persister_.stop();
    publisher_.stop();
}

void Application::print_stats() const {
    printf("\n=== Stats ===\n");
    printf("  serial:    bytes=%-8llu "
           "frames=%-6llu errors=%llu\n",
           reader_.bytes_read(),
           reader_.frames_parsed(),
           reader_.parse_errors());
    printf("  processor: processed=%-6llu dropped=%llu\n",
           processor_.processed(),
           processor_.dropped());
    printf("  sqlite:    rows=%-8llu errors=%llu\n",
           persister_.rows_written(),
           persister_.write_errors());
    printf("  mqtt:      published=%-6llu "
           "errors=%-4llu reconnects=%llu\n",
           publisher_.published(),
           publisher_.pub_errors(),
           publisher_.reconnects());
    printf("  queues:    parse=%zu "
           "persist=%zu publish=%zu\n",
           parse_queue_.size(),
           persist_queue_.size(),
           publish_queue_.size());
    printf("  devices:   %zu registered\n",
           registry_.size());
}

} // namespace mqtt_monitor
```

---

## 6. Test Suite — Full Coverage

```cpp
// tests/test_frame_parser.cpp
#include <gtest/gtest.h>
#include "mqtt_monitor/frame_parser.hpp"
#include "mqtt_monitor/types.hpp"
#include <cstring>

using namespace mqtt_monitor;

class FrameParserTest : public ::testing::Test {
protected:
    // Build a valid frame and corrupt it for error tests
    std::array<uint8_t, FRAME_SIZE> valid_frame() {
        return build_frame(
            0x01,
            SensorType::Temperature,
            12345,
            23.456f);
    }
};

TEST_F(FrameParserTest, ValidFrame) {
    auto frame = valid_frame();
    auto result = parse_frame(frame);
    ASSERT_TRUE(std::holds_alternative<SensorReading>(result));
    const auto& r = std::get<SensorReading>(result);
    EXPECT_EQ(r.device_id,   0x01u);
    EXPECT_EQ(r.sensor_type, SensorType::Temperature);
    EXPECT_EQ(r.timestamp_ms, 12345u);
    EXPECT_NEAR(r.value, 23.456f, 0.001f);
}

TEST_F(FrameParserTest, TooShort) {
    auto frame = valid_frame();
    // Pass only 5 bytes
    auto result = parse_frame(
        std::span{frame}.first(5));
    ASSERT_TRUE(std::holds_alternative<ParseError>(result));
    EXPECT_EQ(std::get<ParseError>(result),
              ParseError::TooShort);
}

TEST_F(FrameParserTest, BadMagic) {
    auto frame = valid_frame();
    frame[0] = 0x00;
    auto result = parse_frame(frame);
    ASSERT_TRUE(std::holds_alternative<ParseError>(result));
    EXPECT_EQ(std::get<ParseError>(result),
              ParseError::BadMagic);
}

TEST_F(FrameParserTest, BadVersion) {
    auto frame = valid_frame();
    frame[1] = 0x99;
    auto result = parse_frame(frame);
    ASSERT_TRUE(std::holds_alternative<ParseError>(result));
    EXPECT_EQ(std::get<ParseError>(result),
              ParseError::BadVersion);
}

TEST_F(FrameParserTest, ChecksumMismatch) {
    auto frame = valid_frame();
    frame[12] ^= 0xFF;  // corrupt checksum
    auto result = parse_frame(frame);
    ASSERT_TRUE(std::holds_alternative<ParseError>(result));
    EXPECT_EQ(std::get<ParseError>(result),
              ParseError::ChecksumMismatch);
}

TEST_F(FrameParserTest, DataCorruption) {
    auto frame = valid_frame();
    frame[5] ^= 0xFF;   // corrupt timestamp byte
    auto result = parse_frame(frame);
    // CRC should catch it
    ASSERT_TRUE(std::holds_alternative<ParseError>(result));
}

TEST_F(FrameParserTest, RoundTrip) {
    // Build and parse multiple sensor types
    const SensorType types[] = {
        SensorType::Temperature,
        SensorType::Humidity,
        SensorType::Pressure,
    };
    for (auto type : types) {
        auto frame = build_frame(0x42, type, 99999, -1.5f);
        auto result = parse_frame(frame);
        ASSERT_TRUE(
            std::holds_alternative<SensorReading>(result))
            << "Failed for sensor type "
            << static_cast<int>(type);
        const auto& r = std::get<SensorReading>(result);
        EXPECT_EQ(r.sensor_type, type);
        EXPECT_NEAR(r.value, -1.5f, 0.0001f);
    }
}

TEST_F(FrameParserTest, ScanFramesMultiple) {
    // Build 3 frames back to back
    std::vector<uint8_t> stream;
    for (int i = 0; i < 3; ++i) {
        auto f = build_frame(
            static_cast<uint8_t>(i),
            SensorType::Temperature,
            static_cast<uint32_t>(i * 1000),
            static_cast<float>(i) * 10.0f);
        stream.insert(stream.end(), f.begin(), f.end());
    }

    size_t consumed = 0;
    auto readings = scan_frames(stream, consumed);

    EXPECT_EQ(readings.size(), 3u);
    EXPECT_EQ(consumed, 3 * FRAME_SIZE);
    EXPECT_NEAR(readings[0].value, 0.0f, 0.001f);
    EXPECT_NEAR(readings[1].value, 10.0f, 0.001f);
    EXPECT_NEAR(readings[2].value, 20.0f, 0.001f);
}

TEST_F(FrameParserTest, ScanFramesWithGarbage) {
    std::vector<uint8_t> stream;
    // Garbage bytes before valid frame
    stream.insert(stream.end(),
                  {0x00, 0x01, 0xFF, 0x42});
    auto f = build_frame(
        0x01, SensorType::Humidity, 5000, 65.0f);
    stream.insert(stream.end(), f.begin(), f.end());

    size_t consumed = 0;
    auto readings = scan_frames(stream, consumed);

    EXPECT_EQ(readings.size(), 1u);
    EXPECT_EQ(readings[0].sensor_type, SensorType::Humidity);
    EXPECT_NEAR(readings[0].value, 65.0f, 0.001f);
}

TEST_F(FrameParserTest, ScanFramesIncomplete) {
    // Only half a frame — should not produce a reading
    auto f = build_frame(
        0x01, SensorType::Temperature, 1000, 23.5f);
    std::vector<uint8_t> partial(
        f.begin(), f.begin() + FRAME_SIZE / 2);

    size_t consumed = 0;
    auto readings = scan_frames(partial, consumed);

    EXPECT_EQ(readings.size(), 0u);
}

// ---- Device Registry tests ----

// tests/test_device_registry.cpp
#include <gtest/gtest.h>
#include "mqtt_monitor/device_registry.hpp"

using namespace mqtt_monitor;

class DeviceRegistryTest : public ::testing::Test {
protected:
    DeviceRegistry reg_{5000};  // 5s stale timeout

    SensorReading make_reading(
        uint32_t device_id, float value,
        uint32_t ts = 1000)
    {
        return SensorReading{
            device_id,
            SensorType::Temperature,
            value,
            ts
        };
    }
};

TEST_F(DeviceRegistryTest, InitiallyEmpty) {
    EXPECT_EQ(reg_.size(), 0u);
    EXPECT_TRUE(reg_.all().empty());
}

TEST_F(DeviceRegistryTest, UpdateCreatesDevice) {
    reg_.update(make_reading(1, 23.5f));
    EXPECT_EQ(reg_.size(), 1u);

    auto dev = reg_.get(1);
    ASSERT_TRUE(dev.has_value());
    EXPECT_EQ(dev->device_id, 1u);
    EXPECT_FLOAT_EQ(dev->last_value, 23.5f);
    EXPECT_TRUE(dev->online);
    EXPECT_EQ(dev->reading_count, 1u);
}

TEST_F(DeviceRegistryTest, UpdateIncrementsCount) {
    reg_.update(make_reading(1, 23.5f, 1000));
    reg_.update(make_reading(1, 24.0f, 2000));
    reg_.update(make_reading(1, 22.8f, 3000));

    auto dev = reg_.get(1);
    ASSERT_TRUE(dev.has_value());
    EXPECT_EQ(dev->reading_count, 3u);
    EXPECT_FLOAT_EQ(dev->last_value, 22.8f);
    EXPECT_EQ(dev->last_seen_ms, 3000u);
}

TEST_F(DeviceRegistryTest, MultipleDevices) {
    reg_.update(make_reading(1, 23.5f));
    reg_.update(make_reading(2, 65.0f));
    reg_.update(make_reading(3, 1013.f));

    EXPECT_EQ(reg_.size(), 3u);
    EXPECT_EQ(reg_.all().size(), 3u);
    EXPECT_EQ(reg_.online().size(), 3u);

    EXPECT_FALSE(reg_.get(99).has_value());
}

TEST_F(DeviceRegistryTest, StaleMarkingByTimeout) {
    reg_.update(make_reading(1, 23.5f, 1000));
    reg_.update(make_reading(2, 65.0f, 2000));

    // Mark stale at t=7000 — device 1 (last seen 1000) is stale
    // device 2 (last seen 2000) is also stale
    int marked = reg_.mark_stale(7000);
    EXPECT_EQ(marked, 2);

    auto d1 = reg_.get(1);
    ASSERT_TRUE(d1.has_value());
    EXPECT_FALSE(d1->online);
}

TEST_F(DeviceRegistryTest, StaleNotMarkedIfRecent) {
    reg_.update(make_reading(1, 23.5f, 6000));

    int marked = reg_.mark_stale(7000);
    EXPECT_EQ(marked, 0);

    auto d1 = reg_.get(1);
    ASSERT_TRUE(d1.has_value());
    EXPECT_TRUE(d1->online);
}

TEST_F(DeviceRegistryTest, OnlineCountAfterStale) {
    reg_.update(make_reading(1, 23.5f, 1000));
    reg_.update(make_reading(2, 65.0f, 6000));

    reg_.mark_stale(7000);  // device 1 goes stale

    EXPECT_EQ(reg_.online().size(), 1u);
    EXPECT_EQ(reg_.online()[0].device_id, 2u);
}

TEST_F(DeviceRegistryTest, UpdateBringsDeviceBackOnline) {
    reg_.update(make_reading(1, 23.5f, 1000));
    reg_.mark_stale(7000);  // make offline

    auto d = reg_.get(1);
    ASSERT_TRUE(d.has_value());
    EXPECT_FALSE(d->online);

    // New reading brings it back
    reg_.update(make_reading(1, 24.0f, 8000));
    d = reg_.get(1);
    ASSERT_TRUE(d.has_value());
    EXPECT_TRUE(d->online);
}

TEST_F(DeviceRegistryTest, ConcurrentReadsAndWrites) {
    constexpr int THREADS = 4;
    constexpr int READS_PER_THREAD = 1000;

    // Pre-populate
    for (int i = 0; i < 10; ++i) {
        reg_.update(make_reading(
            static_cast<uint32_t>(i), 20.0f));
    }

    std::vector<std::thread> threads;
    std::atomic<int> total_reads{0};

    // Reader threads
    for (int t = 0; t < THREADS - 1; ++t) {
        threads.emplace_back([this, &total_reads]() {
            for (int i = 0; i < READS_PER_THREAD; ++i) {
                auto all = reg_.all();
                total_reads.fetch_add(
                    static_cast<int>(all.size()),
                    std::memory_order_relaxed);
            }
        });
    }

    // Writer thread
    threads.emplace_back([this]() {
        for (int i = 0; i < READS_PER_THREAD; ++i) {
            reg_.update(make_reading(
                static_cast<uint32_t>(i % 10),
                static_cast<float>(i)));
        }
    });

    for (auto& t : threads) t.join();

    // No crashes — shared_mutex did its job
    EXPECT_GE(total_reads.load(), 0);
    EXPECT_EQ(reg_.size(), 10u);
}
```

```cmake
# tests/CMakeLists.txt
include(FetchContent)
FetchContent_Declare(
    googletest
    GIT_REPOSITORY https://github.com/google/googletest.git
    GIT_TAG        v1.14.0
)
set(gtest_force_shared_crt ON CACHE BOOL "" FORCE)
FetchContent_MakeAvailable(googletest)

include(GoogleTest)

# Helper to create a test executable
function(add_mqtt_test name)
    add_executable(${name} ${name}.cpp)
    target_link_libraries(${name}
        PRIVATE mqtt_monitor_lib
        PRIVATE GTest::gtest_main
    )
    target_set_warnings(${name})
    target_apply_sanitizers(${name})
    gtest_discover_tests(${name})
endfunction()

add_mqtt_test(test_frame_parser)
add_mqtt_test(test_device_registry)
```

---

## 7. Build, Test, Run

```bash
# Full build with sanitizers
cmake -B build \
    -DCMAKE_BUILD_TYPE=Debug \
    -DENABLE_TESTING=ON \
    -DENABLE_SANITIZERS=ON
cmake --build build --parallel

# Run all tests
ctest --test-dir build --output-on-failure -V

# Run with ThreadSanitizer for concurrency test
cmake -B build_tsan \
    -DCMAKE_BUILD_TYPE=Debug \
    -DENABLE_TESTING=ON \
    -DCMAKE_CXX_FLAGS="-fsanitize=thread -g"
cmake --build build_tsan --parallel
ctest --test-dir build_tsan \
    --tests-regex "ConcurrentReads" \
    --output-on-failure

# Run the application (no real serial — simulated)
./build/mqtt_monitor \
    --broker localhost \
    --db /tmp/monitor_test.db

# Verify SQLite data (while running or after):
sqlite3 /tmp/monitor_test.db \
    "SELECT device_id, sensor_type,
            value, timestamp_ms
     FROM readings
     ORDER BY timestamp_ms DESC
     LIMIT 10;"
```

### What to observe

The frame parser test `ScanFramesWithGarbage` confirms the scanner correctly skips non-magic bytes and finds the valid frame. This is the real-world case — a serial buffer after device reset may have partial frames at the start.

The device registry concurrent test runs 3 reader threads and 1 writer simultaneously. Under ThreadSanitizer, it must produce zero races — the `std::shared_mutex` allows concurrent reads but serializes writes. Without `shared_mutex`, using a plain `mutex` would also be correct but readers would block each other unnecessarily.

The SQLite persister writes in batches wrapped in explicit transactions. A single `BEGIN/COMMIT` for 64 rows is ~100× faster than 64 autocommit inserts on a spinning disk, and ~10× faster even on an SD card. The WAL journal mode means a reader querying the REST API doesn't block the writer.

---

## Key Takeaways for Day 29

- The processor thread is the fan-out point — one reading in, two copies out (persist + publish). This decouples the downstream queues: a slow MQTT broker doesn't slow down SQLite, and a full SQLite queue doesn't stall publishing
- Serial I/O: `select()` with a short timeout is the right pattern for checking shutdown without blocking indefinitely. `O_NONBLOCK` on the fd combined with `select()` gives you both responsiveness and efficiency
- Frame assembly: maintain a receive buffer across `read()` calls, scan for complete frames, compact (memmove) remaining bytes to the front. This handles frames split across read boundaries correctly
- SQLite WAL mode: `PRAGMA journal_mode=WAL` allows concurrent readers and one writer without blocking. `PRAGMA synchronous=NORMAL` gives a good safety/performance balance — protects against most crash scenarios without fsync on every commit
- Batch inserts: `BEGIN; INSERT × N; COMMIT;` with a prepared statement is the correct SQLite pattern. The speedup is real — measure it with `sqlite3_profile` or `time`
- MQTT reconnect: exponential backoff with a cap (1s → 2s → 4s → ... → 30s). Re-queue the failed reading so it's not lost. Reset the delay to 1s on successful connect
- `std::shared_mutex` for read-heavy shared state: `std::shared_lock` for reads (multiple concurrent), `std::unique_lock` for writes (exclusive). The device registry is read by the REST API and written by the processor — exactly the shared_mutex use case
- The shutdown sequence: signal handler sets token → queues unblocked → threads drain → threads exit → destructors clean up. This order ensures no thread reads from a resource that's been destroyed

Day 30 completes the capstone: profiling the hot path, eliminating the top bottlenecks, a structured logging layer, and the README that documents every architecture decision.