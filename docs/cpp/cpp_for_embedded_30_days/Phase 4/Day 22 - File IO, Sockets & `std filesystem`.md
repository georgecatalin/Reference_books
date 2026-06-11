

Every IoT system writes logs, reads configuration, replays state on restart, and communicates over a network. Today covers all three pillars: `std::fstream` for file I/O, `std::filesystem` for path and directory operations, and POSIX sockets wrapped in RAII for the network layer. These are the primitives that sit under every MQTT client, every data logger, and every OTA update handler you'll write.

---

## 1. File Streams — `std::ifstream`, `std::ofstream`, `std::fstream`

C++ file streams are RAII wrappers around `FILE*`. They open on construction, close on destruction.

```cpp
#include <fstream>
#include <iostream>
#include <string>

// Write a text file
{
    std::ofstream out("/tmp/sensor_log.txt");
    if (!out) throw std::runtime_error("failed to open for writing");

    out << "timestamp,sensor_id,value\n";
    out << "1000,0,23.5\n";
    out << "1001,1,65.2\n";
}   // file closed here — destructor

// Read a text file
{
    std::ifstream in("/tmp/sensor_log.txt");
    if (!in) throw std::runtime_error("failed to open for reading");

    std::string line;
    while (std::getline(in, line)) {
        printf("%s\n", line.c_str());
    }
}

// Read/write — std::fstream
{
    std::fstream rw("/tmp/sensor_log.txt",
                    std::ios::in | std::ios::out | std::ios::app);
    rw << "1002,2,1013.2\n";
    rw.seekg(0);   // seek to beginning for reading
    std::string first_line;
    std::getline(rw, first_line);
    printf("First line: %s\n", first_line.c_str());
}
```

### Open Modes

```cpp
std::ios::in       // read
std::ios::out      // write (truncates by default)
std::ios::app      // append — all writes go to end
std::ios::binary   // binary mode — no newline translation
std::ios::trunc    // truncate on open (default for out)
std::ios::ate      // seek to end on open (not append — seeks, doesn't lock)

// Common combinations
std::ifstream in(path, std::ios::binary);            // binary read
std::ofstream out(path, std::ios::binary);           // binary write
std::ofstream append(path, std::ios::binary | std::ios::app);  // binary append
std::fstream rw(path, std::ios::binary | std::ios::in | std::ios::out);
```

### Error Checking

Stream state bits:

```cpp
std::ifstream f("/tmp/data.bin", std::ios::binary);

f.good()  // true if no error flags set
f.eof()   // true if end-of-file reached
f.fail()  // true if logical error (bad format, operation failed)
f.bad()   // true if unrecoverable I/O error

if (!f) { /* same as f.fail() || f.bad() */ }

f.exceptions(std::ios::failbit | std::ios::badbit);
// Now f throws std::ios::failure on error — no manual checking needed
```

---

## 2. Binary I/O — Reading and Writing Structs Directly

For sensor data, binary I/O is faster and more space-efficient than text. Write the struct bytes directly:

```cpp
#include <fstream>
#include <cstdint>
#include <cstring>
#include <cassert>

struct SensorRecord {
    uint32_t timestamp_ms;
    uint8_t  sensor_id;
    float    value;
    uint8_t  checksum;

    uint8_t compute_checksum() const {
        uint8_t sum = 0;
        sum ^= static_cast<uint8_t>(timestamp_ms);
        sum ^= static_cast<uint8_t>(timestamp_ms >> 8);
        sum ^= static_cast<uint8_t>(timestamp_ms >> 16);
        sum ^= static_cast<uint8_t>(timestamp_ms >> 24);
        sum ^= sensor_id;
        uint8_t val_bytes[4];
        std::memcpy(val_bytes, &value, 4);
        for (uint8_t b : val_bytes) sum ^= b;
        return sum;
    }
};

static_assert(sizeof(SensorRecord) == 12,
              "SensorRecord layout must be exactly 12 bytes");

// Write
void write_record(std::ofstream& out, const SensorRecord& r) {
    SensorRecord rec = r;
    rec.checksum = rec.compute_checksum();
    out.write(reinterpret_cast<const char*>(&rec), sizeof(rec));
}

// Read
bool read_record(std::ifstream& in, SensorRecord& out) {
    in.read(reinterpret_cast<char*>(&out), sizeof(out));
    if (in.gcount() != sizeof(out)) return false;
    return out.checksum == out.compute_checksum();
}

// Append to log file
void append_reading(const std::string& path,
                    uint8_t id, float value, uint32_t ts) {
    std::ofstream out(path,
                      std::ios::binary | std::ios::app);
    if (!out) throw std::runtime_error("cannot open: " + path);
    write_record(out, {ts, id, value, 0});
}

// Replay full log
std::vector<SensorRecord> replay_log(const std::string& path) {
    std::ifstream in(path, std::ios::binary);
    if (!in) return {};

    std::vector<SensorRecord> records;
    SensorRecord rec{};
    while (read_record(in, rec)) {
        records.push_back(rec);
    }
    return records;
}
```

### Stream Seeking

```cpp
std::fstream f(path, std::ios::binary | std::ios::in | std::ios::out);

// Seek positions
f.seekg(0, std::ios::beg);   // seek read position to beginning
f.seekg(0, std::ios::end);   // seek to end
f.seekg(-12, std::ios::end); // 12 bytes from end (last record)
f.seekp(24);                 // seek write position to byte 24

auto pos = f.tellg();        // get current read position
f.seekg(pos);                // restore

// Read the Nth record
size_t N = 3;
f.seekg(static_cast<std::streamoff>(N * sizeof(SensorRecord)));
SensorRecord rec{};
read_record(f, rec);
```

---

## 3. `std::filesystem` — Path and Directory Operations

`std::filesystem` (C++17) provides a portable, type-safe interface for filesystem operations:

```cpp
#include <filesystem>
namespace fs = std::filesystem;

// Path construction and manipulation
fs::path p = "/var/log/sensors";
p /= "device_01";         // operator/ appends component
p /= "readings.bin";
// p == "/var/log/sensors/device_01/readings.bin"

p.filename();             // "readings.bin"
p.stem();                 // "readings"
p.extension();            // ".bin"
p.parent_path();          // "/var/log/sensors/device_01"
p.root_path();            // "/"
p.string();               // std::string

// Existence and type checks
fs::exists(p)             // true/false
fs::is_regular_file(p)    // true if it's a file
fs::is_directory(p)       // true if it's a directory
fs::is_symlink(p)

// File size and times
fs::file_size(p)          // size in bytes
fs::last_write_time(p)    // file_time_type

// Create/remove
fs::create_directory(p)           // create one level
fs::create_directories(p)         // create all levels — like mkdir -p
fs::remove(p)                     // remove file or empty directory
fs::remove_all(p)                 // recursive remove
fs::rename(old_p, new_p)          // atomic rename on same filesystem
fs::copy_file(src, dst)           // copy file
```

### Directory Iteration

```cpp
// Non-recursive — one level
for (const auto& entry : fs::directory_iterator("/var/log/sensors")) {
    if (entry.is_regular_file() && entry.path().extension() == ".bin") {
        printf("  %s (%zu bytes)\n",
               entry.path().filename().c_str(),
               entry.file_size());
    }
}

// Recursive
for (const auto& entry : fs::recursive_directory_iterator("/var/log")) {
    printf("  %s\n", entry.path().c_str());
}

// Collect and sort
std::vector<fs::path> log_files;
for (const auto& e : fs::directory_iterator("/var/log/sensors")) {
    if (e.path().extension() == ".bin") {
        log_files.push_back(e.path());
    }
}
std::sort(log_files.begin(), log_files.end());
```

### Error Handling

`std::filesystem` functions throw `fs::filesystem_error` by default. Use the two-argument overloads with an `std::error_code` to avoid exceptions:

```cpp
std::error_code ec;

// Non-throwing versions
bool exists = fs::exists(p, ec);
if (ec) printf("Error checking existence: %s\n", ec.message().c_str());

fs::create_directories(log_dir, ec);
if (ec) printf("Error creating dirs: %s\n", ec.message().c_str());

auto size = fs::file_size(p, ec);
if (ec) size = 0;
```

---

## 4. POSIX Sockets in C++ — RAII Wrapper

Network communication in IoT is almost always POSIX sockets under the hood — even when you're using a library like paho-mqtt or libcurl. Understanding the raw layer helps you debug, tune, and extend.

```cpp
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <netdb.h>
#include <unistd.h>
#include <fcntl.h>
#include <cerrno>
#include <cstring>
#include <stdexcept>
#include <string>

class TcpSocket {
public:
    TcpSocket() : fd_(-1) {}

    // Connect to host:port
    void connect(const std::string& host, uint16_t port) {
        struct addrinfo hints{}, *res = nullptr;
        hints.ai_family   = AF_INET;
        hints.ai_socktype = SOCK_STREAM;

        std::string port_str = std::to_string(port);
        int rc = ::getaddrinfo(host.c_str(), port_str.c_str(),
                               &hints, &res);
        if (rc != 0) {
            throw std::runtime_error(
                std::string("getaddrinfo: ") + gai_strerror(rc));
        }

        fd_ = ::socket(res->ai_family, res->ai_socktype, res->ai_protocol);
        if (fd_ < 0) {
            ::freeaddrinfo(res);
            throw std::runtime_error(
                std::string("socket: ") + strerror(errno));
        }

        if (::connect(fd_, res->ai_addr, res->ai_addrlen) < 0) {
            ::freeaddrinfo(res);
            int saved = errno;
            ::close(fd_); fd_ = -1;
            throw std::runtime_error(
                std::string("connect: ") + strerror(saved));
        }

        ::freeaddrinfo(res);
        printf("[socket] connected to %s:%u (fd=%d)\n",
               host.c_str(), port, fd_);
    }

    // Set non-blocking mode
    void set_nonblocking(bool enable) {
        int flags = ::fcntl(fd_, F_GETFL, 0);
        if (enable) flags |=  O_NONBLOCK;
        else        flags &= ~O_NONBLOCK;
        ::fcntl(fd_, F_SETFL, flags);
    }

    // Set TCP keepalive
    void set_keepalive(bool enable) {
        int opt = enable ? 1 : 0;
        ::setsockopt(fd_, SOL_SOCKET, SO_KEEPALIVE,
                     &opt, sizeof(opt));
    }

    // Set receive timeout
    void set_recv_timeout(std::chrono::milliseconds ms) {
        struct timeval tv{};
        tv.tv_sec  = ms.count() / 1000;
        tv.tv_usec = (ms.count() % 1000) * 1000;
        ::setsockopt(fd_, SOL_SOCKET, SO_RCVTIMEO,
                     &tv, sizeof(tv));
    }

    // Send — handles partial sends
    ssize_t send_all(const void* buf, size_t len) {
        const char* ptr = static_cast<const char*>(buf);
        size_t remaining = len;
        while (remaining > 0) {
            ssize_t sent = ::send(fd_, ptr, remaining, MSG_NOSIGNAL);
            if (sent < 0) return -1;
            ptr       += sent;
            remaining -= static_cast<size_t>(sent);
        }
        return static_cast<ssize_t>(len);
    }

    // Receive exactly n bytes — blocks until all received or error
    ssize_t recv_exact(void* buf, size_t n) {
        char* ptr = static_cast<char*>(buf);
        size_t received = 0;
        while (received < n) {
            ssize_t got = ::recv(fd_, ptr + received,
                                 n - received, 0);
            if (got <= 0) return got == 0 ? 0 : -1;
            received += static_cast<size_t>(got);
        }
        return static_cast<ssize_t>(n);
    }

    // Receive up to n bytes — non-blocking if set
    ssize_t recv(void* buf, size_t n) {
        return ::recv(fd_, static_cast<char*>(buf), n, 0);
    }

    void close() {
        if (fd_ >= 0) {
            ::close(fd_);
            printf("[socket] closed fd=%d\n", fd_);
            fd_ = -1;
        }
    }

    bool is_open() const { return fd_ >= 0; }
    int  fd()      const { return fd_; }

    // RAII — close on destruction
    ~TcpSocket() { close(); }

    // Non-copyable, movable
    TcpSocket(const TcpSocket&)            = delete;
    TcpSocket& operator=(const TcpSocket&) = delete;

    TcpSocket(TcpSocket&& o) noexcept : fd_(o.fd_) { o.fd_ = -1; }
    TcpSocket& operator=(TcpSocket&& o) noexcept {
        if (this != &o) { close(); fd_ = o.fd_; o.fd_ = -1; }
        return *this;
    }

private:
    int fd_;
};
```

### TCP Server Socket

```cpp
class TcpServer {
public:
    explicit TcpServer(uint16_t port) : fd_(-1) {
        fd_ = ::socket(AF_INET, SOCK_STREAM, 0);
        if (fd_ < 0) throw std::runtime_error("socket failed");

        // SO_REUSEADDR — allows immediate restart after crash
        int opt = 1;
        ::setsockopt(fd_, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));

        struct sockaddr_in addr{};
        addr.sin_family      = AF_INET;
        addr.sin_port        = htons(port);
        addr.sin_addr.s_addr = INADDR_ANY;

        if (::bind(fd_, reinterpret_cast<sockaddr*>(&addr), sizeof(addr)) < 0)
            throw std::runtime_error("bind failed");

        if (::listen(fd_, SOMAXCONN) < 0)
            throw std::runtime_error("listen failed");

        printf("[server] listening on port %u\n", port);
    }

    ~TcpServer() { if (fd_ >= 0) ::close(fd_); }

    TcpSocket accept_client() {
        struct sockaddr_in client_addr{};
        socklen_t len = sizeof(client_addr);
        int client_fd = ::accept(fd_,
                                 reinterpret_cast<sockaddr*>(&client_addr),
                                 &len);
        if (client_fd < 0)
            throw std::runtime_error("accept failed");

        printf("[server] accepted client from %s:%u\n",
               inet_ntoa(client_addr.sin_addr),
               ntohs(client_addr.sin_port));

        TcpSocket sock;
        sock.fd_ = client_fd;   // need friend or setter
        return sock;
    }

    int fd() const { return fd_; }

private:
    int fd_;
};
```

---

## 5. Putting It Together — Data Logger with Network Replay

A complete system: binary log writer, filesystem management (rotation, size limits), and a TCP server that replays the log to connected clients:

```cpp
// data_logger.cpp
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <fstream>
#include <filesystem>
#include <vector>
#include <string>
#include <stdexcept>
#include <optional>
#include <chrono>
#include <thread>
#include <mutex>
#include <atomic>
#include <cassert>

namespace fs = std::filesystem;
using namespace std::chrono_literals;

// ---- Sensor record ----

struct SensorRecord {
    uint32_t timestamp_ms;
    uint8_t  sensor_id;
    uint8_t  _pad[3] = {};
    float    value;
    uint32_t sequence;

    void print() const {
        printf("  [seq=%u t=%u id=%u] %.3f\n",
               sequence, timestamp_ms, sensor_id, value);
    }
};
static_assert(sizeof(SensorRecord) == 16,
              "SensorRecord must be 16 bytes");

// ---- Binary data logger ----

class DataLogger {
public:
    struct Config {
        fs::path   log_dir       = "/tmp/sensor_logs";
        size_t     max_file_size = 1024 * 4;   // 4KB for demo
        size_t     max_files     = 5;
        std::string prefix       = "readings";
    };

    explicit DataLogger(Config cfg)
        : cfg_(std::move(cfg))
        , sequence_(0)
        , records_written_(0)
    {
        // Create log directory if it doesn't exist
        std::error_code ec;
        fs::create_directories(cfg_.log_dir, ec);
        if (ec) throw std::runtime_error(
            "Cannot create log dir: " + ec.message());

        open_current_file();
        printf("[logger] opened: %s\n",
               current_path_.c_str());
    }

    ~DataLogger() {
        if (out_.is_open()) out_.close();
    }

    DataLogger(const DataLogger&)            = delete;
    DataLogger& operator=(const DataLogger&) = delete;

    // Write a reading — thread-safe
    void write(uint8_t sensor_id, float value,
               uint32_t timestamp_ms) {
        std::lock_guard<std::mutex> lock(mtx_);

        SensorRecord rec{
            timestamp_ms,
            sensor_id,
            {},
            value,
            sequence_++
        };

        out_.write(reinterpret_cast<const char*>(&rec),
                   sizeof(rec));
        out_.flush();
        ++records_written_;

        // Rotate if file too large
        if (static_cast<size_t>(out_.tellp()) >= cfg_.max_file_size) {
            rotate();
        }
    }

    // Read all records from all log files — sorted by sequence
    std::vector<SensorRecord> replay_all() const {
        std::vector<SensorRecord> all;

        auto files = list_log_files();
        for (const auto& path : files) {
            auto records = read_file(path);
            all.insert(all.end(), records.begin(), records.end());
        }

        std::sort(all.begin(), all.end(),
            [](const SensorRecord& a, const SensorRecord& b) {
                return a.sequence < b.sequence;
            });

        return all;
    }

    // Stats
    uint64_t records_written()   const { return records_written_.load(); }
    fs::path current_log_path()  const { return current_path_; }

    // List all log files sorted oldest-first
    std::vector<fs::path> list_log_files() const {
        std::vector<fs::path> files;
        std::error_code ec;

        for (const auto& entry :
             fs::directory_iterator(cfg_.log_dir, ec))
        {
            if (ec) break;
            if (entry.path().extension() == ".bin" &&
                entry.path().filename().string()
                     .starts_with(cfg_.prefix))
            {
                files.push_back(entry.path());
            }
        }

        std::sort(files.begin(), files.end());
        return files;
    }

    // Total size of all log files
    size_t total_log_size() const {
        size_t total = 0;
        std::error_code ec;
        for (const auto& p : list_log_files()) {
            total += fs::file_size(p, ec);
        }
        return total;
    }

private:
    void open_current_file() {
        // Generate timestamped filename
        auto now = std::chrono::system_clock::now()
                       .time_since_epoch().count();
        current_path_ = cfg_.log_dir /
            (cfg_.prefix + "_" +
             std::to_string(now) + ".bin");

        out_.open(current_path_,
                  std::ios::binary | std::ios::out);
        if (!out_) throw std::runtime_error(
            "Cannot open log file: " + current_path_.string());
    }

    void rotate() {
        out_.close();
        printf("[logger] rotated: %s (%.1fKB)\n",
               current_path_.filename().c_str(),
               static_cast<float>(
                   fs::file_size(current_path_)) / 1024.0f);

        // Delete oldest files if over limit
        auto files = list_log_files();
        while (files.size() >= cfg_.max_files) {
            std::error_code ec;
            fs::remove(files.front(), ec);
            printf("[logger] deleted old log: %s\n",
                   files.front().filename().c_str());
            files.erase(files.begin());
        }

        open_current_file();
        printf("[logger] new file: %s\n",
               current_path_.c_str());
    }

    static std::vector<SensorRecord> read_file(const fs::path& p) {
        std::vector<SensorRecord> records;
        std::ifstream in(p, std::ios::binary);
        if (!in) return records;

        SensorRecord rec{};
        while (in.read(reinterpret_cast<char*>(&rec),
                       sizeof(rec))) {
            records.push_back(rec);
        }
        return records;
    }

    Config              cfg_;
    fs::path            current_path_;
    std::ofstream       out_;
    mutable std::mutex  mtx_;
    uint32_t            sequence_;
    std::atomic<uint64_t> records_written_;
};

// ---- Filesystem utility functions ----

void print_log_directory(const DataLogger& logger) {
    printf("\n--- Log directory contents ---\n");
    auto files = logger.list_log_files();
    for (const auto& p : files) {
        std::error_code ec;
        auto size = fs::file_size(p, ec);
        auto records = size / sizeof(SensorRecord);
        printf("  %-40s  %5zu bytes  (%zu records)\n",
               p.filename().c_str(),
               size, records);
    }
    printf("  Total: %zu bytes across %zu files\n",
           logger.total_log_size(), files.size());
}

int main() {
    printf("=== Data Logger with std::filesystem ===\n\n");

    // ---- Setup ----
    DataLogger::Config cfg;
    cfg.log_dir       = "/tmp/sensor_logs_demo";
    cfg.max_file_size = 16 * sizeof(SensorRecord); // rotate every 16 records
    cfg.max_files     = 3;
    cfg.prefix        = "sensors";

    // Clean up from previous runs
    std::error_code ec;
    fs::remove_all(cfg.log_dir, ec);

    DataLogger logger(cfg);

    // ---- Write records ----
    printf("\n--- Writing 50 sensor records ---\n");
    for (int i = 0; i < 50; ++i) {
        logger.write(
            static_cast<uint8_t>(i % 4),     // 4 sensors
            20.0f + static_cast<float>(i) * 0.3f,
            static_cast<uint32_t>(i * 100)
        );
        if (i % 10 == 9) {
            printf("  Written %d records "
                   "(total_size=%zu bytes)\n",
                   i + 1, logger.total_log_size());
        }
    }

    print_log_directory(logger);

    // ---- Replay ----
    printf("\n--- Replaying all records ---\n");
    auto all = logger.replay_all();
    printf("Replayed %zu records total\n", all.size());

    // Print first and last 3
    printf("First 3:\n");
    for (size_t i = 0; i < std::min(size_t(3), all.size()); ++i)
        all[i].print();

    printf("Last 3:\n");
    size_t last = all.size() > 3 ? all.size() - 3 : 0;
    for (size_t i = last; i < all.size(); ++i)
        all[i].print();

    // ---- Verify sequence continuity ----
    bool sequential = true;
    for (size_t i = 1; i < all.size(); ++i) {
        if (all[i].sequence != all[i-1].sequence + 1) {
            sequential = false;
            printf("Gap at sequence %u → %u\n",
                   all[i-1].sequence, all[i].sequence);
        }
    }
    printf("Sequence continuity: %s\n",
           sequential ? "PASS" : "FAIL");

    // ---- std::filesystem operations ----
    printf("\n--- Filesystem operations ---\n");

    fs::path log_dir = cfg.log_dir;
    printf("Log dir exists: %s\n",
           fs::exists(log_dir) ? "yes" : "no");
    printf("Is directory:   %s\n",
           fs::is_directory(log_dir) ? "yes" : "no");

    // Count records per sensor across all files
    printf("\n--- Per-sensor record counts ---\n");
    std::array<int, 4> sensor_counts{};
    for (const auto& rec : all) {
        if (rec.sensor_id < 4) ++sensor_counts[rec.sensor_id];
    }
    for (int s = 0; s < 4; ++s) {
        printf("  Sensor %d: %d records\n", s, sensor_counts[s]);
    }

    // ---- Concurrent writes ----
    printf("\n--- Concurrent writes from 3 threads ---\n");
    {
        std::vector<std::thread> writers;
        for (int t = 0; t < 3; ++t) {
            writers.emplace_back([&logger, t]() {
                for (int i = 0; i < 10; ++i) {
                    logger.write(
                        static_cast<uint8_t>(t),
                        static_cast<float>(t * 100 + i),
                        static_cast<uint32_t>(
                            t * 1000 + i)
                    );
                    std::this_thread::sleep_for(1ms);
                }
            });
        }
        for (auto& w : writers) w.join();
    }
    printf("After concurrent writes: %llu total records\n",
           logger.records_written());

    // ---- Path manipulation ----
    printf("\n--- Path manipulation ---\n");
    fs::path p = cfg.log_dir;
    p /= "sensors_12345.bin";
    printf("filename:  %s\n", p.filename().c_str());
    printf("stem:      %s\n", p.stem().c_str());
    printf("extension: %s\n", p.extension().c_str());
    printf("parent:    %s\n", p.parent_path().c_str());

    // Cleanup
    fs::remove_all(cfg.log_dir, ec);
    printf("\nCleanup done.\n");

    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address,thread -o data_logger data_logger.cpp -lpthread
./data_logger
```

### What to observe

The rotation logic in `DataLogger::rotate()` closes the current file, lists existing files, deletes the oldest if over the limit, then opens a new file. With `max_files=3` and 50 records at 16 records per file, you'll see roughly 3 files after the run — older ones deleted automatically.

The `replay_all()` function reads all files, collects all records, then sorts by sequence number. Sequence numbers are monotonically increasing across file boundaries, so the sort reconstructs the original write order even if files are read in arbitrary order.

The mutex in `DataLogger::write()` makes concurrent writes safe — three threads writing simultaneously all get their records into the log without corruption. TSan confirms this.

---

## Key Takeaways for Day 22

- `std::ifstream`, `std::ofstream`, `std::fstream` are RAII — files open on construction, close on destruction. Never call `close()` manually except to reopen
- Binary mode (`std::ios::binary`) disables newline translation — always use it for non-text data
- `out.write(reinterpret_cast<const char*>(&obj), sizeof(obj))` writes a struct's bytes directly — fast, compact, but not portable across architectures with different endianness or padding
- `static_assert(sizeof(T) == N)` on serialized structs — catches padding surprises at compile time, before they corrupt your on-disk format
- `std::filesystem` operations have two-argument overloads with `std::error_code` — use them in production code to avoid exceptions in error paths like "file not found"
- `fs::create_directories` creates all intermediate directories — like `mkdir -p`. `fs::remove_all` removes recursively — like `rm -rf`
- `send_all` and `recv_exact` loops are necessary for TCP — a single `send` or `recv` may transfer less than requested. Always loop until all bytes are transferred or an error occurs
- `MSG_NOSIGNAL` on `send()` prevents SIGPIPE when the remote closes the connection — without it, writing to a closed socket kills your process
- Log rotation: close current file, delete oldest if over limit, open new file — three operations, all with error handling. The `max_files` limit bounds disk usage for long-running embedded processes

Phase 4 is complete. Day 23 starts Phase 5: compile-time programming — `constexpr`, `if constexpr`, and generating CRC tables at compile time so your protocol parser has zero runtime initialization cost.