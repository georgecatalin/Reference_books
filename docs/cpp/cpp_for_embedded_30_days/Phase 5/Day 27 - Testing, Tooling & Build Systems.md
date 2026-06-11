

The code from Days 1–26 is only production-ready when it's tested, built reproducibly, and analyzed for bugs you can't see at runtime. Today covers the complete toolchain: modern CMake for multi-target builds, GoogleTest for unit testing, the four sanitizers that catch bugs no code review finds, and static analysis tools that enforce quality automatically. This is the infrastructure that separates code that works on your machine from code that ships.

---

## 1. CMake — Modern Target-Based Build System

CMake before version 3.0 used global variables and directory-level properties — a mess that produced fragile builds. Modern CMake (3.14+) is about targets and their properties. Everything attaches to a target, not the global state.

### Core Concepts

```cmake
# A target is a thing you build:
add_executable(my_app main.cpp)           # executable
add_library(sensor_lib STATIC sensor.cpp) # static library
add_library(mqtt_lib   SHARED mqtt.cpp)   # shared library
add_library(utils      INTERFACE)         # header-only — no compiled output

# Targets have three visibility levels for properties:
# PRIVATE   — only this target uses it
# INTERFACE — only consumers of this target use it
# PUBLIC    — both this target and consumers use it

target_include_directories(sensor_lib
    PUBLIC  include/        # consumers see these headers
    PRIVATE src/internal/   # only sensor_lib sees these
)

target_compile_options(sensor_lib
    PRIVATE -Wall -Wextra -Wpedantic
)

target_link_libraries(my_app
    PRIVATE sensor_lib      # my_app uses sensor_lib
    PRIVATE mqtt_lib
)
```

Properties propagate through the dependency graph. If `sensor_lib` has a `PUBLIC` include directory, anything that links against `sensor_lib` automatically gets that include path — no manual propagation.

### A Real Project Structure

```
project/
├── CMakeLists.txt              # root
├── cmake/
│   └── CompilerWarnings.cmake  # shared warning flags
├── src/
│   ├── CMakeLists.txt
│   ├── sensor/
│   │   ├── CMakeLists.txt
│   │   ├── sensor.hpp
│   │   └── sensor.cpp
│   └── protocol/
│       ├── CMakeLists.txt
│       ├── protocol.hpp
│       └── protocol.cpp
├── tests/
│   ├── CMakeLists.txt
│   ├── test_sensor.cpp
│   └── test_protocol.cpp
└── third_party/
    └── googletest/             # or fetched by CMake
```

```cmake
# CMakeLists.txt (root)
cmake_minimum_required(VERSION 3.20)
project(iot_monitor
    VERSION     1.0.0
    DESCRIPTION "IoT sensor monitoring system"
    LANGUAGES   CXX
)

# Require C++17 globally
set(CMAKE_CXX_STANDARD          17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS        OFF)  # no -std=gnu++17, only -std=c++17

# Build type default
if(NOT CMAKE_BUILD_TYPE)
    set(CMAKE_BUILD_TYPE RelWithDebInfo)
endif()

# Export compile commands for clang-tidy, IDEs
set(CMAKE_EXPORT_COMPILE_COMMANDS ON)

# Options
option(ENABLE_TESTING    "Build tests"             ON)
option(ENABLE_SANITIZERS "Enable sanitizers"       OFF)
option(ENABLE_CLANG_TIDY "Enable clang-tidy"       OFF)

# Compiler warnings helper
include(cmake/CompilerWarnings.cmake)

# Subdirectories
add_subdirectory(src)
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
            -Wall
            -Wextra
            -Wpedantic
            -Wconversion
            -Wsign-conversion
            -Wcast-align
            -Wunused
            -Wshadow
            -Wnull-dereference
            -Wdouble-promotion
            -Wformat=2
            -Werror           # treat warnings as errors in CI
        )
    elseif(MSVC)
        target_compile_options(${target} PRIVATE
            /W4 /WX /permissive-
        )
    endif()
endfunction()
```

```cmake
# src/sensor/CMakeLists.txt
add_library(sensor STATIC
    sensor.cpp
)

target_include_directories(sensor
    PUBLIC  ${CMAKE_CURRENT_SOURCE_DIR}
)

target_link_libraries(sensor
    PUBLIC  std::filesystem  # propagates to consumers
)

target_set_warnings(sensor)

# Sanitizer support
if(ENABLE_SANITIZERS)
    target_compile_options(sensor PRIVATE
        -fsanitize=address,undefined
        -fno-omit-frame-pointer
    )
    target_link_options(sensor PUBLIC
        -fsanitize=address,undefined
    )
endif()
```

```cmake
# tests/CMakeLists.txt

# Fetch GoogleTest automatically
include(FetchContent)
FetchContent_Declare(
    googletest
    GIT_REPOSITORY https://github.com/google/googletest.git
    GIT_TAG        v1.14.0
)
FetchContent_MakeAvailable(googletest)

# Test executable
add_executable(test_sensor
    test_sensor.cpp
)

target_link_libraries(test_sensor
    PRIVATE sensor
    PRIVATE GTest::gtest_main
)

target_set_warnings(test_sensor)

# Register with CTest
include(GoogleTest)
gtest_discover_tests(test_sensor)

# Sanitizers on tests too
if(ENABLE_SANITIZERS)
    target_compile_options(test_sensor PRIVATE
        -fsanitize=address,undefined
        -fno-omit-frame-pointer
    )
    target_link_options(test_sensor PUBLIC
        -fsanitize=address,undefined
    )
endif()
```

### Building

```bash
# Configure
cmake -B build -DCMAKE_BUILD_TYPE=Debug -DENABLE_TESTING=ON

# Build
cmake --build build --parallel

# Run tests
ctest --test-dir build --output-on-failure

# With sanitizers
cmake -B build_asan \
    -DCMAKE_BUILD_TYPE=Debug \
    -DENABLE_TESTING=ON \
    -DENABLE_SANITIZERS=ON
cmake --build build_asan --parallel
ctest --test-dir build_asan --output-on-failure
```

---

## 2. GoogleTest — Unit Testing C++

GoogleTest (gtest) is the de-facto C++ testing framework. Tests are functions; assertions are macros; test execution is automatic.

### Test Structure

```cpp
#include <gtest/gtest.h>

// TEST(TestSuiteName, TestName)
TEST(SensorBufferTest, PushAndPop) {
    SensorBuffer buf(8);

    EXPECT_TRUE(buf.empty());
    EXPECT_EQ(buf.size(), 0u);

    EXPECT_TRUE(buf.push(23.5f));
    EXPECT_EQ(buf.size(), 1u);
    EXPECT_FALSE(buf.empty());

    EXPECT_FLOAT_EQ(buf[0], 23.5f);
}

TEST(SensorBufferTest, FullBuffer) {
    SensorBuffer buf(2);

    EXPECT_TRUE(buf.push(1.0f));
    EXPECT_TRUE(buf.push(2.0f));
    EXPECT_TRUE(buf.full());
    EXPECT_FALSE(buf.push(3.0f));   // full — returns false
    EXPECT_EQ(buf.size(), 2u);      // size unchanged
}
```

### Assertion Reference

```cpp
// Fatal assertions — stop the current test on failure (ASSERT_*)
ASSERT_EQ(a, b);        // a == b
ASSERT_NE(a, b);        // a != b
ASSERT_LT(a, b);        // a < b
ASSERT_LE(a, b);        // a <= b
ASSERT_GT(a, b);        // a > b
ASSERT_GE(a, b);        // a >= b
ASSERT_TRUE(expr);
ASSERT_FALSE(expr);
ASSERT_FLOAT_EQ(a, b);  // float equality within 4 ULP
ASSERT_NEAR(a, b, abs_err);  // |a - b| <= abs_err
ASSERT_THROW(expr, ExceptionType);
ASSERT_NO_THROW(expr);
ASSERT_DEATH(expr, regex);   // process exits/crashes

// Non-fatal — continue test after failure (EXPECT_*)
EXPECT_EQ(a, b);
EXPECT_FLOAT_EQ(a, b);
// ... same names, different prefix
```

### Test Fixtures — Shared Setup

```cpp
class RingBufferTest : public ::testing::Test {
protected:
    void SetUp() override {
        // Called before each test
        buf_ = std::make_unique<RingBuffer<float, 8>>();
        for (float v : {1.0f, 2.0f, 3.0f, 4.0f}) {
            buf_->push(v);
        }
    }

    void TearDown() override {
        // Called after each test
        buf_.reset();
    }

    std::unique_ptr<RingBuffer<float, 8>> buf_;
};

TEST_F(RingBufferTest, Size) {
    EXPECT_EQ(buf_->size(), 4u);
}

TEST_F(RingBufferTest, PopOrder) {
    auto first = buf_->pop();
    ASSERT_TRUE(first.has_value());
    EXPECT_FLOAT_EQ(*first, 1.0f);  // FIFO

    auto second = buf_->pop();
    ASSERT_TRUE(second.has_value());
    EXPECT_FLOAT_EQ(*second, 2.0f);
}

TEST_F(RingBufferTest, EmptyAfterDrain) {
    while (!buf_->empty()) buf_->pop();
    EXPECT_TRUE(buf_->empty());
    EXPECT_FALSE(buf_->pop().has_value());
}
```

### Parameterized Tests

```cpp
// Test the same logic over multiple inputs
class CRC8Test : public ::testing::TestWithParam
    std::tuple<std::vector<uint8_t>, uint8_t>>  // input, expected CRC
{};

TEST_P(CRC8Test, KnownValues) {
    auto [data, expected] = GetParam();
    uint8_t result = crc8(std::span{data});
    EXPECT_EQ(result, expected)
        << "CRC8 mismatch for input size " << data.size();
}

INSTANTIATE_TEST_SUITE_P(
    ProtocolFrames,
    CRC8Test,
    ::testing::Values(
        std::make_tuple(std::vector<uint8_t>{},             uint8_t(0x00)),
        std::make_tuple(std::vector<uint8_t>{0x01},         uint8_t(0x07)),
        std::make_tuple(std::vector<uint8_t>{0x01,0x02,0x03}, uint8_t(0x18))
    )
);
```

### Mocking Hardware — Dependency Injection

IoT code talks to hardware. Tests can't. Use dependency injection to swap real hardware for a mock:

```cpp
// Interface — both real and mock implement this
class ISerialPort {
public:
    virtual ssize_t write(const void* buf, size_t len) = 0;
    virtual ssize_t read(void* buf, size_t len)        = 0;
    virtual bool    is_open() const                    = 0;
    virtual ~ISerialPort() = default;
};

// Real implementation (uses actual fd)
class SerialPort : public ISerialPort { /* ... */ };

// Mock for testing
class MockSerialPort : public ISerialPort {
public:
    // Queue bytes to be returned by read()
    void inject_rx(std::span<const uint8_t> data) {
        rx_buffer_.insert(rx_buffer_.end(),
                          data.begin(), data.end());
    }

    // Read what was written by write()
    const std::vector<uint8_t>& tx_data() const {
        return tx_buffer_;
    }

    ssize_t write(const void* buf, size_t len) override {
        const uint8_t* p = static_cast<const uint8_t*>(buf);
        tx_buffer_.insert(tx_buffer_.end(), p, p + len);
        return static_cast<ssize_t>(len);
    }

    ssize_t read(void* buf, size_t len) override {
        size_t n = std::min(len, rx_buffer_.size());
        if (n == 0) return 0;
        std::memcpy(buf, rx_buffer_.data(), n);
        rx_buffer_.erase(rx_buffer_.begin(),
                         rx_buffer_.begin() + static_cast<ptrdiff_t>(n));
        return static_cast<ssize_t>(n);
    }

    bool is_open() const override { return true; }

private:
    std::vector<uint8_t> tx_buffer_;
    std::vector<uint8_t> rx_buffer_;
};

// Protocol parser that uses ISerialPort — testable
class FrameReader {
public:
    explicit FrameReader(ISerialPort& port) : port_(port) {}

    std::optional<ParsedFrame> read_frame() {
        uint8_t header[2];
        if (port_.read(header, 2) < 2) return std::nullopt;
        // ... parse logic
        return std::nullopt;
    }

private:
    ISerialPort& port_;
};

// Test using mock
TEST(FrameReaderTest, ParsesValidFrame) {
    MockSerialPort mock;
    const uint8_t frame[] = {0xAB, 0x05, 'H','e','l','l','o', 0x42};
    mock.inject_rx(frame);

    FrameReader reader(mock);
    auto result = reader.read_frame();
    // EXPECT_TRUE(result.has_value()); etc.
}
```

---

## 3. The Four Sanitizers

Sanitizers instrument your binary at compile time to detect classes of bugs that are invisible in normal execution:

### AddressSanitizer (ASan) — Memory Errors

```bash
g++ -fsanitize=address -fno-omit-frame-pointer -g -o prog prog.cpp
./prog
```

Catches:

- Heap buffer overflow/underflow
- Stack buffer overflow
- Use after free
- Use after scope (dangling stack references)
- Double free
- Memory leaks

```cpp
// ASan catches this:
int* p = new int[10];
p[10] = 42;   // heap buffer overflow
delete[] p;

// Output: ==ERROR: AddressSanitizer: heap-buffer-overflow
//         on address ... at pc ...
//         WRITE of size 4 at 0x... thread T0
```

### ThreadSanitizer (TSan) — Data Races

```bash
g++ -fsanitize=thread -g -o prog prog.cpp -lpthread
```

Catches:

- Data races (unsynchronized access to shared data)
- Mutex order violations
- Use of uninitialized mutexes

```cpp
// TSan catches this:
int counter = 0;
std::thread t1([&]() { ++counter; });
std::thread t2([&]() { ++counter; });
t1.join(); t2.join();

// Output: WARNING: ThreadSanitizer: data race
//         Write of size 4 at ... by thread T2
//         Previous write of size 4 at ... by thread T1
```

**Cannot run ASan and TSan simultaneously** — they conflict. Run them in separate builds.

### UndefinedBehaviorSanitizer (UBSan) — Undefined Behavior

```bash
g++ -fsanitize=undefined -g -o prog prog.cpp
```

Catches:

- Signed integer overflow
- Null pointer dereference
- Out-of-bounds array access (limited)
- Invalid enum values
- Misaligned pointer access
- Shift by negative or oversized amount

```cpp
// UBSan catches this:
int x = INT_MAX;
int y = x + 1;   // signed overflow — UB

// Output: runtime error: signed integer overflow:
//         2147483647 + 1 cannot be represented in type 'int'
```

### MemorySanitizer (MSan) — Uninitialized Memory Reads

```bash
clang++ -fsanitize=memory -fPIE -pie -g -o prog prog.cpp
# MSan requires Clang, not GCC
```

Catches:

- Reading from uninitialized memory (stack or heap)

```cpp
// MSan catches this:
int x;
if (x > 0) { }  // reading uninitialized x

// Output: WARNING: MemorySanitizer: use-of-uninitialized-value
```

### Sanitizer Strategy

```bash
# Development build — ASan + UBSan always on
cmake -B build_dev -DCMAKE_BUILD_TYPE=Debug \
    -DCMAKE_CXX_FLAGS="-fsanitize=address,undefined -fno-omit-frame-pointer"

# Concurrency testing — TSan
cmake -B build_tsan -DCMAKE_BUILD_TYPE=Debug \
    -DCMAKE_CXX_FLAGS="-fsanitize=thread"

# Uninitialized reads — MSan (Clang only)
cmake -B build_msan -DCMAKE_BUILD_TYPE=Debug \
    -DCMAKE_CXX_COMPILER=clang++ \
    -DCMAKE_CXX_FLAGS="-fsanitize=memory -fPIE"
```

---

## 4. Static Analysis — `clang-tidy` and `cppcheck`

Sanitizers catch runtime bugs. Static analysis catches them without running the program.

### `clang-tidy`

`clang-tidy` applies a configurable set of checks to your source code using the clang AST:

```yaml
# .clang-tidy — place in project root
Checks: >
  -*,
  bugprone-*,
  cert-*,
  cppcoreguidelines-*,
  modernize-*,
  performance-*,
  portability-*,
  readability-*,
  -modernize-use-trailing-return-type,
  -cppcoreguidelines-avoid-magic-numbers,
  -readability-magic-numbers

WarningsAsErrors: "*"

CheckOptions:
  - key: readability-identifier-naming.VariableCase
    value: lower_case
  - key: readability-identifier-naming.MemberCase
    value: lower_case
  - key: readability-identifier-naming.MemberSuffix
    value: "_"
```

```cmake
# CMakeLists.txt — integrate clang-tidy
if(ENABLE_CLANG_TIDY)
    find_program(CLANG_TIDY_EXE NAMES clang-tidy)
    if(CLANG_TIDY_EXE)
        set(CMAKE_CXX_CLANG_TIDY
            "${CLANG_TIDY_EXE}"
            "--config-file=${CMAKE_SOURCE_DIR}/.clang-tidy"
        )
    endif()
endif()
```

```bash
# Run manually
clang-tidy src/sensor.cpp -- -std=c++17 -I include/

# Run via CMake
cmake -B build_tidy -DENABLE_CLANG_TIDY=ON
cmake --build build_tidy 2>&1 | grep "warning:"
```

### `cppcheck`

```bash
cppcheck \
    --enable=all \
    --std=c++17 \
    --suppress=missingIncludeSystem \
    --error-exitcode=1 \
    src/
```

### Compiler Flags That Matter

```cmake
# These flags catch real bugs — enable in all builds
target_compile_options(my_target PRIVATE
    -Wall                  # standard warnings
    -Wextra                # extra warnings
    -Wpedantic             # strict standards compliance
    -Wconversion           # implicit conversions that change value
    -Wsign-conversion      # sign conversion warnings
    -Wcast-align           # misaligned cast warnings
    -Wnull-dereference     # potential null dereference
    -Wdouble-promotion     # float→double promotion
    -Wformat=2             # printf format string checks
    -Wshadow               # variable shadowing
    -O2                    # optimized build reveals more issues
)
```

---

## 5. Putting It Together — Complete Test Suite

```cpp
// tests/test_suite.cpp
#include <gtest/gtest.h>
#include <cstdint>
#include <cstring>
#include <vector>
#include <array>
#include <span>
#include <optional>
#include <thread>
#include <mutex>
#include <atomic>

// ---- Include headers being tested ----
// #include "sensor/ring_buffer.hpp"
// #include "protocol/frame_parser.hpp"
// #include "sensor/sensor_buffer.hpp"

// For this demo, inline minimal implementations:

template<typename T, size_t N>
class RingBuffer {
    static_assert((N & (N-1)) == 0, "N must be power of 2");
    static constexpr size_t MASK = N - 1;
public:
    bool push(const T& v) {
        if (count_ == N) return false;
        data_[head_++ & MASK] = v;
        ++count_;
        return true;
    }
    std::optional<T> pop() {
        if (count_ == 0) return std::nullopt;
        T v = data_[tail_++ & MASK];
        --count_;
        return v;
    }
    bool   empty()    const { return count_ == 0; }
    bool   full()     const { return count_ == N; }
    size_t size()     const { return count_; }
    size_t capacity() const { return N; }
private:
    std::array<T, N> data_{};
    size_t head_ = 0, tail_ = 0, count_ = 0;
};

// Thread-safe queue from Day 20
template<typename T>
class BlockingQueue {
public:
    explicit BlockingQueue(size_t cap) : cap_(cap) {}
    bool try_push(T v) {
        std::lock_guard lock(mtx_);
        if (q_.size() >= cap_) return false;
        q_.push(std::move(v));
        return true;
    }
    std::optional<T> try_pop() {
        std::lock_guard lock(mtx_);
        if (q_.empty()) return std::nullopt;
        T v = std::move(q_.front());
        q_.pop();
        return v;
    }
    size_t size() const {
        std::lock_guard lock(mtx_);
        return q_.size();
    }
private:
    mutable std::mutex   mtx_;
    std::queue<T>        q_;
    size_t               cap_;
};

// ---- RingBuffer tests ----

class RingBufferTest : public ::testing::Test {
protected:
    RingBuffer<int, 4> buf_;
};

TEST_F(RingBufferTest, InitiallyEmpty) {
    EXPECT_TRUE(buf_.empty());
    EXPECT_EQ(buf_.size(), 0u);
    EXPECT_EQ(buf_.capacity(), 4u);
    EXPECT_FALSE(buf_.full());
}

TEST_F(RingBufferTest, PushAndPop) {
    EXPECT_TRUE(buf_.push(1));
    EXPECT_TRUE(buf_.push(2));
    EXPECT_EQ(buf_.size(), 2u);

    auto v1 = buf_.pop();
    ASSERT_TRUE(v1.has_value());
    EXPECT_EQ(*v1, 1);  // FIFO

    auto v2 = buf_.pop();
    ASSERT_TRUE(v2.has_value());
    EXPECT_EQ(*v2, 2);
}

TEST_F(RingBufferTest, FullRejectsPush) {
    for (int i = 0; i < 4; ++i) buf_.push(i);
    EXPECT_TRUE(buf_.full());
    EXPECT_FALSE(buf_.push(99));
    EXPECT_EQ(buf_.size(), 4u);
}

TEST_F(RingBufferTest, EmptyReturnsNullopt) {
    auto v = buf_.pop();
    EXPECT_FALSE(v.has_value());
}

TEST_F(RingBufferTest, WrapAround) {
    // Fill, drain half, fill again — tests wrap-around
    for (int i = 0; i < 4; ++i) buf_.push(i);
    buf_.pop(); buf_.pop();  // drain 2
    EXPECT_TRUE(buf_.push(10));
    EXPECT_TRUE(buf_.push(11));

    EXPECT_EQ(*buf_.pop(), 2);   // original items
    EXPECT_EQ(*buf_.pop(), 3);
    EXPECT_EQ(*buf_.pop(), 10);  // new items after wrap
    EXPECT_EQ(*buf_.pop(), 11);
    EXPECT_TRUE(buf_.empty());
}

TEST_F(RingBufferTest, SizeTracksCorrectly) {
    for (int i = 0; i < 4; ++i) {
        EXPECT_EQ(buf_.size(), static_cast<size_t>(i));
        buf_.push(i);
    }
    for (int i = 4; i > 0; --i) {
        EXPECT_EQ(buf_.size(), static_cast<size_t>(i));
        buf_.pop();
    }
    EXPECT_EQ(buf_.size(), 0u);
}

// ---- BlockingQueue thread-safety tests ----

class BlockingQueueTest : public ::testing::Test {
protected:
    BlockingQueue<int> queue_{16};
};

TEST_F(BlockingQueueTest, SingleThreadPushPop) {
    EXPECT_TRUE(queue_.try_push(42));
    auto v = queue_.try_pop();
    ASSERT_TRUE(v.has_value());
    EXPECT_EQ(*v, 42);
}

TEST_F(BlockingQueueTest, ConcurrentPushersNoLoss) {
    constexpr int THREADS   = 4;
    constexpr int PER_THREAD = 1000;

    // Use a large enough queue
    BlockingQueue<int> q(THREADS * PER_THREAD);
    std::atomic<int>   total_pushed{0};
    std::vector<std::thread> threads;

    for (int t = 0; t < THREADS; ++t) {
        threads.emplace_back([&q, &total_pushed]() {
            for (int i = 0; i < PER_THREAD; ++i) {
                if (q.try_push(i)) {
                    total_pushed.fetch_add(1,
                        std::memory_order_relaxed);
                }
            }
        });
    }
    for (auto& t : threads) t.join();

    EXPECT_EQ(q.size(),
              static_cast<size_t>(total_pushed.load()));
}

TEST_F(BlockingQueueTest, ConcurrentPushAndPop) {
    constexpr int ITEMS = 10000;
    std::atomic<int> produced{0}, consumed{0};
    std::atomic<bool> done{false};

    BlockingQueue<int> q(64);

    std::thread producer([&]() {
        for (int i = 0; i < ITEMS; ++i) {
            while (!q.try_push(i)) {
                std::this_thread::yield();
            }
            produced.fetch_add(1, std::memory_order_relaxed);
        }
        done.store(true);
    });

    std::thread consumer([&]() {
        while (!done.load() || q.size() > 0) {
            if (auto v = q.try_pop()) {
                consumed.fetch_add(1,
                    std::memory_order_relaxed);
            } else {
                std::this_thread::yield();
            }
        }
    });

    producer.join();
    consumer.join();

    EXPECT_EQ(produced.load(), ITEMS);
    EXPECT_EQ(consumed.load(), ITEMS);
    EXPECT_EQ(q.size(), 0u);
}

// ---- CRC tests ----

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
constexpr auto CRC8_TABLE = make_crc8_table();

uint8_t crc8(std::span<const uint8_t> data) {
    uint8_t crc = 0;
    for (uint8_t b : data) crc = CRC8_TABLE[crc ^ b];
    return crc;
}

struct CrcTestCase {
    std::vector<uint8_t> data;
    uint8_t              expected;
    const char*          description;
};

class CRC8Test : public ::testing::TestWithParam<CrcTestCase> {};

TEST_P(CRC8Test, KnownVectors) {
    const auto& tc = GetParam();
    uint8_t result = crc8(tc.data);
    EXPECT_EQ(result, tc.expected)
        << "Failed: " << tc.description;
}

INSTANTIATE_TEST_SUITE_P(
    Vectors, CRC8Test,
    ::testing::Values(
        CrcTestCase{{},          0x00, "empty input"},
        CrcTestCase{{0x00},      0x00, "single zero byte"},
        CrcTestCase{{0xFF},      0x12, "single 0xFF byte"},
        CrcTestCase{{0x01,0x02}, 0x19, "two bytes"},
        CrcTestCase{{0xAB,0x01,0x05,0x48,0x65,0x6C,0x6C,0x6F},
                    crc8(std::span<const uint8_t>{
                        std::vector<uint8_t>{
                            0xAB,0x01,0x05,
                            0x48,0x65,0x6C,0x6C,0x6F}
                    }),
                    "frame bytes"}
    )
);

TEST(CRC8Test, RoundTrip) {
    std::vector<uint8_t> frame = {0xAB, 0x01, 0x03, 'A','B','C'};
    uint8_t checksum = crc8(frame);
    frame.push_back(checksum);

    // CRC of frame+checksum is not 0 for CRC-8/SMBUS
    // but CRC of data portion matches appended checksum
    EXPECT_EQ(crc8(std::span{frame}.first(frame.size()-1)),
              frame.back());
}

// ---- Bit manipulation tests ----

TEST(BitManipTest, SetClearToggle) {
    uint8_t v = 0x00;

    v |= (1u << 3);
    EXPECT_EQ(v, 0x08u);  // bit 3 set

    v &= ~(1u << 3);
    EXPECT_EQ(v, 0x00u);  // bit 3 cleared

    v ^= (1u << 5);
    EXPECT_EQ(v, 0x20u);  // bit 5 toggled on

    v ^= (1u << 5);
    EXPECT_EQ(v, 0x00u);  // bit 5 toggled off
}

TEST(BitManipTest, ExtractField) {
    uint8_t reg = 0b10110101;

    EXPECT_EQ((reg >> 0) & 0x01, 1u);  // bit 0
    EXPECT_EQ((reg >> 2) & 0x07, 5u);  // bits 2-4: 101 = 5
    EXPECT_EQ((reg >> 5) & 0x07, 5u);  // bits 5-7: 101 = 5
}

TEST(BitManipTest, EndianConversion) {
    uint16_t host = 0x1234;
    uint8_t  bytes[2];
    // Big-endian write
    bytes[0] = static_cast<uint8_t>(host >> 8);
    bytes[1] = static_cast<uint8_t>(host & 0xFF);
    EXPECT_EQ(bytes[0], 0x12u);
    EXPECT_EQ(bytes[1], 0x34u);

    // Big-endian read
    uint16_t reconstructed =
        static_cast<uint16_t>(bytes[0] << 8) | bytes[1];
    EXPECT_EQ(reconstructed, host);
}

// ---- Main ----
// gtest_main handles main() via GTest::gtest_main linkage
```

```bash
# Build and run with sanitizers
cmake -B build_test \
    -DCMAKE_BUILD_TYPE=Debug \
    -DENABLE_TESTING=ON \
    -DCMAKE_CXX_FLAGS="-fsanitize=address,undefined -fno-omit-frame-pointer -g"

cmake --build build_test --parallel

ctest --test-dir build_test --output-on-failure -V

# Run the test binary directly for verbose output
./build_test/tests/test_suite --gtest_filter="RingBufferTest.*"
./build_test/tests/test_suite --gtest_filter="*Concurrent*"
./build_test/tests/test_suite --gtest_color=yes
```

### What to observe

The wrap-around test (`WrapAround`) is the most important ring buffer test — it verifies the bitmask indexing works correctly across the array boundary. Bugs in ring buffer implementations almost always appear here.

The concurrent push/pop test (`ConcurrentPushAndPop`) proves that `produced == consumed == ITEMS` — not a single item was lost. Run it under ThreadSanitizer (`-fsanitize=thread`) to confirm no data races.

The parameterized CRC tests run the same test body over multiple input vectors. Adding a new test case requires one line in `INSTANTIATE_TEST_SUITE_P` — no new test function.

---

## Key Takeaways for Day 27

- Modern CMake is target-based — everything attaches to targets via `target_*` commands with `PRIVATE`/`PUBLIC`/`INTERFACE`. Never use global `include_directories()` or `add_definitions()`
- `target_link_libraries` propagates properties — a PUBLIC dependency's include paths and compile options automatically reach consumers. Model your dependency graph correctly and the build just works
- `FetchContent_Declare` + `FetchContent_MakeAvailable` fetches GoogleTest (or any dependency) at configure time — no manual submodule management needed
- Test fixtures (`TEST_F`) share setup/teardown across multiple tests for the same class under test. Prefer fixtures over repeating initialization code
- Parameterized tests (`TEST_P` + `INSTANTIATE_TEST_SUITE_P`) run the same test logic over many inputs — add a new test case with one line, not a new function
- AddressSanitizer catches memory errors at runtime with ~2x slowdown — run it on all debug builds. It's the most valuable tool in the toolchain
- ThreadSanitizer and AddressSanitizer cannot run simultaneously — maintain separate sanitizer build directories
- `-Wconversion` and `-Wsign-conversion` catch a class of implicit narrowing and signedness bugs that silent truncation hides — enable them from the start of a project
- `cmake --build build --parallel` and `ctest --output-on-failure` are the two commands to run in CI on every push

Phase 5 is complete. Days 28–30 bring everything together in the capstone: a production IoT device driver with async MQTT ingestion, binary protocol parsing, SQLite persistence, and a full test suite — all orchestrated from `CMakeLists.txt`.