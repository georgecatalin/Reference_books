

RAII is the single most important design pattern in C++. Everything else in modern C++ — smart pointers, lock guards, file streams, `std::vector` itself — is RAII applied to a specific resource. Once the pattern clicks, you stop thinking about cleanup code entirely and start thinking only about ownership.

The name is terrible marketing. "Resource Acquisition Is Initialization" describes the mechanism, not the benefit. The benefit is: **a resource that is tied to an object's lifetime can never leak, regardless of how the code exits.**

---

## 1. The Problem RAII Solves

You have a resource — something you acquire and must release. In C, you manage this manually:

```c
int fd = open("/dev/ttyUSB0", O_RDWR);
if (fd < 0) return -1;

configure_port(fd);

if (some_condition) {
    close(fd);   // must remember
    return -1;
}

read_data(fd);
close(fd);       // must remember again
```

Every exit path needs a `close()`. Add an early return and forget to close — you have a resource leak. Add an exception in C++ and the `close()` at the bottom never runs. The more complex the function, the more paths there are, the more ways to leak.

RAII solution: tie the resource to an object. The object's destructor releases it. The compiler guarantees the destructor runs at scope exit — every path, every time.

```cpp
// After RAII wrapping
{
    SerialPort port("/dev/ttyUSB0");   // opens in constructor
    port.configure(9600);

    if (some_condition) {
        return;   // destructor runs — port closed automatically
    }

    port.read_data();
}   // destructor runs — port closed automatically
```

There is no `close()` call. There is no way to forget it.

---

## 2. What Counts as a Resource

Any of these should be wrapped in RAII:

|Resource|Acquire|Release|
|---|---|---|
|Heap memory|`new` / `malloc`|`delete` / `free`|
|File descriptor|`open()`|`close()`|
|Serial port|`open()`|`close()`|
|Mutex lock|`lock()`|`unlock()`|
|Network socket|`socket()` / `connect()`|`close()`|
|Database connection|`connect()`|`disconnect()`|
|GPU buffer|`allocate()`|`free()`|
|Shared memory|`shm_open()`|`shm_unlink()`|

All follow the same pattern: acquire in constructor, release in destructor.

---

## 3. Writing Your Own RAII Wrapper

Here's the full pattern applied to a POSIX file descriptor:

```cpp
#include <fcntl.h>
#include <unistd.h>
#include <termios.h>
#include <cstdio>
#include <stdexcept>
#include <string>

class SerialPort {
public:
    // Constructor — acquires the resource
    explicit SerialPort(const std::string& device, int baud_rate = 9600)
        : fd_(-1)
        , device_(device)
    {
        fd_ = ::open(device.c_str(), O_RDWR | O_NOCTTY | O_NONBLOCK);
        if (fd_ < 0) {
            throw std::runtime_error("SerialPort: failed to open " + device);
        }
        configure(baud_rate);
        printf("SerialPort: opened %s (fd=%d)\n", device.c_str(), fd_);
    }

    // Destructor — releases the resource, always
    ~SerialPort() {
        if (fd_ >= 0) {
            ::close(fd_);
            printf("SerialPort: closed %s (fd=%d)\n", device_.c_str(), fd_);
            fd_ = -1;
        }
    }

    // No copying — a file descriptor has one owner
    SerialPort(const SerialPort&)            = delete;
    SerialPort& operator=(const SerialPort&) = delete;

    // Moving is fine — transfer ownership
    SerialPort(SerialPort&& other) noexcept
        : fd_(other.fd_)
        , device_(std::move(other.device_))
    {
        other.fd_ = -1;   // source no longer owns the fd
    }

    SerialPort& operator=(SerialPort&& other) noexcept {
        if (this != &other) {
            if (fd_ >= 0) ::close(fd_);   // release current resource
            fd_     = other.fd_;
            device_ = std::move(other.device_);
            other.fd_ = -1;
        }
        return *this;
    }

    // --- Operations ---

    ssize_t write(const void* buf, size_t len) {
        return ::write(fd_, buf, len);
    }

    ssize_t read(void* buf, size_t len) {
        return ::read(fd_, buf, len);
    }

    int fd() const { return fd_; }
    const std::string& device() const { return device_; }
    bool is_open() const { return fd_ >= 0; }

private:
    void configure(int baud_rate) {
        struct termios tty{};
        if (tcgetattr(fd_, &tty) != 0) return;

        // Set baud rate
        speed_t speed = B9600;
        if (baud_rate == 115200) speed = B115200;
        else if (baud_rate == 57600) speed = B57600;

        cfsetispeed(&tty, speed);
        cfsetospeed(&tty, speed);

        // 8N1
        tty.c_cflag &= ~PARENB;
        tty.c_cflag &= ~CSTOPB;
        tty.c_cflag &= ~CSIZE;
        tty.c_cflag |=  CS8;
        tty.c_cflag |=  CREAD | CLOCAL;

        tcsetattr(fd_, TCSANOW, &tty);
    }

    int         fd_;
    std::string device_;
};
```

Three things to notice:

**Copy is deleted.** A file descriptor has one owner. Copying would mean two objects think they own the same fd — both will try to `close()` it. Double-close is undefined behavior. We'll add proper copy semantics in Day 8 if the resource supports it.

**Move is implemented.** Transferring ownership is valid — one object hands the fd to another. The move constructor takes the fd and sets the source's fd to -1 (the sentinel value meaning "not open"). The destructor checks `fd_ >= 0` before closing, so the moved-from object's destructor is a no-op.

**The destructor is defensive.** It checks `fd_ >= 0` before closing. It resets `fd_` to -1 after closing. Belt and suspenders.

---

## 4. `std::unique_ptr` — RAII for Heap Memory

Writing your own RAII wrapper for every resource is the pattern. But for heap memory specifically, the standard library already did it: `std::unique_ptr`.

`unique_ptr` expresses **sole ownership** — exactly one `unique_ptr` owns a heap object at any time. When the `unique_ptr` is destroyed, it deletes the object.

```cpp
#include <memory>

// Instead of:
SensorBuffer* buf = new SensorBuffer(64);
// ... (must remember delete)
delete buf;

// Use:
std::unique_ptr<SensorBuffer> buf = std::make_unique<SensorBuffer>(64);
// ... (destructor calls delete automatically)
// No delete needed — ever
```

`std::make_unique<T>(args...)` is the correct way to create a `unique_ptr` — it constructs and wraps in a single operation with no way to leak between the `new` and the `unique_ptr` constructor.

### Using `unique_ptr`

```cpp
auto buf = std::make_unique<SensorBuffer>(64);

buf->push(23.5f);          // operator-> forwards to the managed object
buf->push(24.1f);
(*buf).print();            // operator* dereferences

buf.get();                 // raw pointer — use for C API interop only
buf.reset();               // destroys the object now, buf becomes null
buf.reset(new SensorBuffer(128));  // destroy current, own new one

// Transfer ownership — unique_ptr cannot be copied, only moved
auto buf2 = std::move(buf);   // buf2 owns the buffer, buf is now null
if (!buf) printf("buf is null after move\n");
```

### `unique_ptr` for Arrays

```cpp
// For arrays, use the array specialization
auto arr = std::make_unique<float[]>(256);  // allocates float[256]
arr[0] = 23.5f;
arr[1] = 24.1f;
// delete[] called automatically
```

### `unique_ptr` with Custom Deleters

When the resource isn't freed with `delete` — a file descriptor, a C library handle — you can give `unique_ptr` a custom deleter:

```cpp
// RAII for a C library handle using unique_ptr
struct MQTTClientDeleter {
    void operator()(mqtt_client_t* client) {
        mqtt_client_destroy(client);   // C library cleanup
    }
};

using MQTTClientPtr = std::unique_ptr<mqtt_client_t, MQTTClientDeleter>;

MQTTClientPtr client(mqtt_client_create());
// mqtt_client_destroy() called automatically when client goes out of scope
```

This is often cleaner than writing a full wrapper class for simple C handles.

---

## 5. `std::shared_ptr` — Shared Ownership

Sometimes a resource genuinely has multiple owners — a device config shared across multiple subsystems, a buffer being processed by multiple consumers. `shared_ptr` handles this with reference counting.

```cpp
#include <memory>

auto config = std::make_shared<DeviceConfig>();
// ref count = 1

{
    auto copy = config;   // ref count = 2 — both own it
    copy->baud_rate = 115200;
}   // copy destroyed — ref count = 1 — config still alive

// ref count drops to 0 when last shared_ptr is destroyed — object deleted
```

### The Cost of `shared_ptr`

`shared_ptr` is not free. It allocates a **control block** on the heap (in addition to the managed object) containing:

- The reference count (atomic — thread-safe, but atomics aren't free)
- A weak reference count
- The deleter

Every copy increments the atomic reference count. Every destruction decrements it. On a hot path with many copies, this is measurable overhead. `unique_ptr` has zero overhead — it compiles to the same code as a raw pointer with a `delete` call.

**Rule of thumb:** default to `unique_ptr`. Use `shared_ptr` only when you genuinely have multiple owners.

---

## 6. `std::weak_ptr` — Observing Without Owning

`weak_ptr` holds a non-owning reference to a `shared_ptr`-managed object. It doesn't affect the reference count. You use it to break ownership cycles and to observe an object that might be destroyed.

```cpp
std::shared_ptr<SensorBuffer> buf = std::make_shared<SensorBuffer>(64);
std::weak_ptr<SensorBuffer>   observer = buf;   // doesn't increment ref count

// To use a weak_ptr, lock it — produces a shared_ptr or null
if (auto locked = observer.lock()) {
    locked->push(23.5f);   // object is alive, use it
} else {
    printf("Buffer was already destroyed\n");
}

buf.reset();   // destroy the buffer — ref count → 0 → deleted

if (auto locked = observer.lock()) {
    // won't reach here
} else {
    printf("Buffer gone — weak_ptr correctly expired\n");
}
```

The `lock()` call is the key: it either gives you a valid `shared_ptr` (the object is alive) or a null `shared_ptr` (the object was destroyed). No dangling pointer possible.

---

## 7. RAII for Mutexes — `std::lock_guard`

Mutexes are a resource. Lock in constructor, unlock in destructor — RAII. The standard library provides this:

```cpp
#include <mutex>

std::mutex device_mutex;

void update_device_state(DeviceState& state) {
    std::lock_guard<std::mutex> lock(device_mutex);  // locks here
    // ... modify state safely ...
    state.connected = true;
}   // lock_guard destructor: unlocks here — even if exception thrown
```

Without RAII:

```cpp
void dangerous(DeviceState& state) {
    device_mutex.lock();

    if (error_condition) {
        return;   // DEADLOCK — unlock never called
    }

    state.connected = true;
    device_mutex.unlock();
}
```

`lock_guard` makes the deadlock-on-early-return impossible. We cover this in depth on Day 19 (concurrency), but the pattern is pure RAII.

---

## 8. Putting It Together — Full RAII Exercise

Simulate a complete resource lifecycle: a `SerialPort` that can't be opened (device doesn't exist on your machine), wrapped in a way that tests the RAII guarantees. We'll use a file on disk as a stand-in for a device fd:

```cpp
// raii_demo.cpp
#include <cstdio>
#include <cstring>
#include <cerrno>
#include <stdexcept>
#include <memory>
#include <string>
#include <unistd.h>
#include <fcntl.h>

// ---- Generic file descriptor RAII wrapper ----

class FileDescriptor {
public:
    explicit FileDescriptor(int fd) : fd_(fd) {
        if (fd_ < 0) throw std::runtime_error("Invalid file descriptor");
    }

    ~FileDescriptor() {
        if (fd_ >= 0) {
            ::close(fd_);
            printf("  [FileDescriptor] closed fd=%d\n", fd_);
        }
    }

    FileDescriptor(const FileDescriptor&)            = delete;
    FileDescriptor& operator=(const FileDescriptor&) = delete;

    FileDescriptor(FileDescriptor&& other) noexcept
        : fd_(other.fd_) { other.fd_ = -1; }

    FileDescriptor& operator=(FileDescriptor&& other) noexcept {
        if (this != &other) {
            if (fd_ >= 0) ::close(fd_);
            fd_ = other.fd_;
            other.fd_ = -1;
        }
        return *this;
    }

    int get() const { return fd_; }

    ssize_t write(const void* buf, size_t len) {
        return ::write(fd_, buf, len);
    }

    ssize_t read(void* buf, size_t len) {
        return ::read(fd_, buf, len);
    }

private:
    int fd_;
};

// ---- DataLogger built on FileDescriptor ----

class DataLogger {
public:
    explicit DataLogger(const std::string& path)
        : fd_(::open(path.c_str(), O_WRONLY | O_CREAT | O_APPEND, 0644))
        , path_(path)
    {
        printf("  [DataLogger] opened %s\n", path.c_str());
    }

    ~DataLogger() {
        printf("  [DataLogger] closing %s\n", path_.c_str());
        // fd_ destructor runs automatically — no close() needed here
    }

    DataLogger(const DataLogger&)            = delete;
    DataLogger& operator=(const DataLogger&) = delete;
    DataLogger(DataLogger&&)                 = default;
    DataLogger& operator=(DataLogger&&)      = default;

    void log(const char* msg) {
        char buf[256];
        int n = snprintf(buf, sizeof(buf), "%s\n", msg);
        fd_.write(buf, static_cast<size_t>(n));
    }

private:
    FileDescriptor fd_;   // FileDescriptor is a member — its destructor runs
    std::string    path_; // when DataLogger is destroyed
};

// ---- Demonstrate RAII guarantees ----

void test_normal_exit() {
    printf("\n[test_normal_exit]\n");
    DataLogger logger("/tmp/sensor_log.txt");
    logger.log("reading: 23.5");
    logger.log("reading: 24.1");
    printf("  About to return normally\n");
}   // DataLogger destructor → FileDescriptor destructor → close()

void test_early_return(bool trigger) {
    printf("\n[test_early_return trigger=%s]\n", trigger ? "true" : "false");
    DataLogger logger("/tmp/sensor_log2.txt");
    logger.log("start");

    if (trigger) {
        printf("  Early return triggered\n");
        return;   // DataLogger still destroyed — no leak
    }

    logger.log("end");
    printf("  Normal return\n");
}   // DataLogger destroyed in both paths

void test_exception() {
    printf("\n[test_exception]\n");
    try {
        DataLogger logger("/tmp/sensor_log3.txt");
        logger.log("before exception");
        printf("  Throwing exception\n");
        throw std::runtime_error("simulated device error");
        logger.log("after exception");  // never reached
    } catch (const std::runtime_error& e) {
        printf("  Caught: %s\n", e.what());
        // DataLogger was destroyed during stack unwinding — file closed before catch
    }
}

void test_unique_ptr() {
    printf("\n[test_unique_ptr]\n");

    // Heap-allocated DataLogger — unique_ptr manages its lifetime
    auto logger = std::make_unique<DataLogger>("/tmp/sensor_log4.txt");
    logger->log("heap-allocated logger");

    printf("  Resetting unique_ptr\n");
    logger.reset();   // DataLogger destroyed here — not at end of scope
    printf("  After reset — logger is gone\n");

    // logger goes out of scope here — but it's already null, no double-destroy
}

void test_move_ownership() {
    printf("\n[test_move_ownership]\n");

    DataLogger a("/tmp/sensor_log5.txt");
    a.log("written by a");

    printf("  Moving ownership to b\n");
    DataLogger b = std::move(a);   // b now owns the file descriptor
    b.log("written by b");

    printf("  b going out of scope\n");
    // a's destructor: fd_ is -1 (moved-from state) — no close
    // b's destructor: closes the fd
}

int main() {
    test_normal_exit();
    test_early_return(false);
    test_early_return(true);
    test_exception();
    test_unique_ptr();
    test_move_ownership();

    printf("\nAll tests done — verify /tmp/sensor_log*.txt were created\n");
    printf("Run: ls -la /tmp/sensor_log*.txt\n");
    return 0;
}
```

Compile and run:

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address -o raii_demo raii_demo.cpp
./raii_demo
ls -la /tmp/sensor_log*.txt
cat /tmp/sensor_log.txt
```

### What to verify

Every test should print the `[FileDescriptor] closed` line — including the exception test and both early-return paths. AddressSanitizer should report zero leaks. The log files should exist and contain the written data.

Change `FileDescriptor`'s constructor to print the fd value. Then add a test that deliberately leaks (use a raw `int fd = open(...)` without a wrapper) and run under AddressSanitizer — watch it catch the leak. Then wrap it and watch the leak disappear.

---

## Key Takeaways for Day 7

- RAII ties resource lifetime to object lifetime — the destructor releases the resource on every exit path, always
- Any resource you acquire and must release is a candidate: memory, fds, sockets, locks, handles
- `std::unique_ptr` is RAII for heap memory with sole ownership — zero overhead, use it by default
- `std::shared_ptr` is RAII for shared ownership — has runtime cost (atomic ref count + heap control block), use only when genuinely needed
- `std::weak_ptr` observes without owning — `lock()` gives you a valid `shared_ptr` or null, never a dangling pointer
- `std::lock_guard` is RAII for mutexes — unlock on scope exit, no deadlock-on-early-return possible
- Moving a RAII object transfers ownership — the moved-from object must be left in a valid "null" state so its destructor is a no-op
- Copy of a RAII object managing a non-copyable resource should be `= delete` — force the ownership question to be answered explicitly

Day 8 completes the ownership picture: the Rule of Zero, Three, and Five. We fix the copy problem in `SensorBuffer`, implement proper move semantics, and use `= default` and `= delete` to be explicit about every operation the compiler might generate.