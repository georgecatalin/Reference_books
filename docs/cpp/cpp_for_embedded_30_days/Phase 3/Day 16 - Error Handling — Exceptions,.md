

Every function that can fail needs a strategy for communicating that failure to the caller. C++ gives you more options than C — and more ways to pick the wrong one. The right choice depends on context: exceptions are appropriate on hosted platforms where stack unwinding is available; `std::optional` handles "no value" cleanly; `std::expected` carries error information without exceptions. On embedded targets, exceptions are often disabled entirely. Knowing all three and when to use each is what separates production C++ from tutorial C++.

---

## 1. The Four Strategies — When Each Applies

```
Error handling strategies:

1. Exceptions          — for truly exceptional conditions on hosted platforms
2. std::optional<T>    — for "might not have a value" — absence is normal
3. std::expected<T,E>  — for operations that succeed OR fail with a reason
4. Error codes (int/enum) — for C interop, real-time, embedded without RTTI
```

The decision tree:

```
Is the error truly exceptional (programmer error, impossible to recover)?
    YES → assert() in debug, crash in release — not an exception
    NO  → continue

Is "no value" a normal outcome (not an error)?
    YES → std::optional<T>
    NO  → continue

Does the caller need to know WHY it failed?
    YES → std::expected<T,E> (C++23) or your own Result<T,E>
    NO  → std::optional<T> still works

Are you on bare-metal or -fno-exceptions?
    YES → std::expected or error codes only
    NO  → exceptions are available — use judgment
```

---

## 2. Exceptions — The Mechanics

An exception propagates up the call stack until a matching `catch` block is found. If none is found, `std::terminate()` is called. During propagation, destructors for all local objects in unwound frames are called — this is how RAII interacts with exceptions.

```cpp
#include <stdexcept>

// Throw
void configure_port(int fd, int baud) {
    if (fd < 0) throw std::invalid_argument("invalid file descriptor");
    if (baud != 9600 && baud != 115200)
        throw std::runtime_error("unsupported baud rate: " + std::to_string(baud));
}

// Catch
void setup() {
    try {
        SerialPort port("/dev/ttyUSB0");    // RAII — opens port
        configure_port(port.fd(), 9600);    // may throw
        port.write("AT\r\n", 4);            // may throw
        // port closes here normally
    }
    catch (const std::invalid_argument& e) {
        printf("Bad argument: %s\n", e.what());
        // port's destructor already ran during unwinding — no leak
    }
    catch (const std::runtime_error& e) {
        printf("Runtime error: %s\n", e.what());
    }
    catch (...) {
        printf("Unknown error\n");  // catch-all — use sparingly
    }
}
```

### Standard Exception Hierarchy

```
std::exception
├── std::logic_error          — programmer errors (wrong preconditions)
│   ├── std::invalid_argument — bad argument value
│   ├── std::out_of_range     — index or value out of valid range
│   └── std::length_error     — length exceeded
└── std::runtime_error        — problems outside program's control
    ├── std::overflow_error   — arithmetic overflow
    ├── std::underflow_error
    └── std::system_error     — OS-level errors (errno-based)
```

Always catch by `const&` — never by value (slicing) or by pointer:

```cpp
catch (const std::exception& e) { ... }  // correct
catch (std::exception e)        { ... }  // wrong — slices the object
catch (std::exception* e)       { ... }  // wrong — pointer to what?
```

### `noexcept` — Documenting and Enforcing No-Throw

```cpp
// Promise: this function never throws
void reset_sensor() noexcept {
    // if something here did throw, std::terminate() is called — no propagation
}

// Conditional noexcept — depends on whether T's move is noexcept
template<typename T>
void swap_values(T& a, T& b) noexcept(std::is_nothrow_move_constructible_v<T> &&
                                       std::is_nothrow_move_assignable_v<T>) {
    T tmp = std::move(a);
    a     = std::move(b);
    b     = std::move(tmp);
}
```

`noexcept` functions enable compiler optimizations — the compiler doesn't need to generate unwinding tables for them. Mark destructors, move operations, and swap as `noexcept` whenever they genuinely can't throw.

### When NOT to Use Exceptions

- **Real-time code** — stack unwinding has unpredictable latency
- **Embedded targets with `-fno-exceptions`** — exceptions are compiled out
- **Constructor failure** — actually fine for exceptions (it's what they're for), but confusing; sometimes `static` factory functions returning `optional` are cleaner
- **Expected failure paths** — if failing is common and expected, exception overhead is wasteful and the control flow becomes awkward

---

## 3. `std::optional<T>` — Nullable Without Pointers

`std::optional<T>` holds either a `T` or nothing. It's the right return type when "no value" is a normal, non-error outcome — not a failure, just absence.

```cpp
#include <optional>

// Returns a reading if available, nothing if sensor not ready
std::optional<float> try_read_sensor(uint8_t id) {
    if (!sensor_ready(id)) return std::nullopt;
    return read_adc(id);   // implicit construction of optional<float>
}

// At the call site
if (auto val = try_read_sensor(0)) {
    printf("Reading: %.2f\n", *val);   // dereference with *
} else {
    printf("Sensor not ready\n");
}

// With value_or — provide default
float reading = try_read_sensor(0).value_or(0.0f);

// value() throws std::bad_optional_access if empty
try {
    float v = try_read_sensor(0).value();  // throws if empty
} catch (const std::bad_optional_access&) { ... }
```

### `optional` for Configuration Parsing

```cpp
struct Config {
    int         baud_rate   = 9600;
    std::string device_path = "/dev/ttyUSB0";
    std::optional<float> calibration_offset;  // not always provided
    std::optional<int>   timeout_ms;           // not always provided
};

void apply_config(const Config& cfg) {
    if (cfg.calibration_offset) {
        apply_offset(*cfg.calibration_offset);
    }
    int timeout = cfg.timeout_ms.value_or(1000);  // default if absent
}
```

### `optional` Performance

`std::optional<T>` stores `T` inline plus one byte for the "has value" flag. No heap allocation. The size is `sizeof(T) + alignment_padding + 1`. For `optional<float>` that's typically 8 bytes.

---

## 4. `std::expected<T, E>` — Result Type

`std::expected<T, E>` (C++23, but implementable in C++17) holds either a success value `T` or an error value `E`. It's the right return type when callers need to know what went wrong — not just that something went wrong.

```cpp
#include <expected>  // C++23

enum class ParseError {
    InvalidMagic,
    TruncatedFrame,
    ChecksumMismatch,
    UnsupportedVersion,
};

std::expected<MQTTMessage, ParseError>
parse_frame(std::span<const uint8_t> buf) {
    if (buf.size() < 2)
        return std::unexpected(ParseError::TruncatedFrame);
    if (buf[0] != 0xAB)
        return std::unexpected(ParseError::InvalidMagic);
    if (!verify_checksum(buf))
        return std::unexpected(ParseError::ChecksumMismatch);

    MQTTMessage msg;
    // ... parse ...
    return msg;   // success — implicit construction
}

// At the call site
auto result = parse_frame(buffer);
if (result) {
    process(*result);            // dereference for success value
} else {
    switch (result.error()) {
        case ParseError::InvalidMagic:
            printf("Bad magic byte\n"); break;
        case ParseError::TruncatedFrame:
            printf("Frame too short\n"); break;
        case ParseError::ChecksumMismatch:
            printf("Checksum failed\n"); break;
        default: break;
    }
}

// value_or — default on error
auto msg = parse_frame(buf).value_or(MQTTMessage{});

// and_then — chain operations (monadic)
auto process_result = parse_frame(buf)
    .and_then(validate_message)
    .and_then(dispatch_message);
```

### C++17 — `expected` Without C++23

Since C++23 isn't universally available yet, here's a minimal `Result<T, E>` for C++17:

```cpp
// result.hpp — minimal Result type for C++17
#pragma once
#include <variant>
#include <stdexcept>

template<typename T, typename E>
class Result {
public:
    // Success
    static Result ok(T value) {
        Result r;
        r.data_ = std::move(value);
        return r;
    }

    // Error
    static Result err(E error) {
        Result r;
        r.data_ = std::move(error);
        return r;
    }

    bool     is_ok()    const { return std::holds_alternative<T>(data_); }
    bool     is_err()   const { return std::holds_alternative<E>(data_); }
    explicit operator bool() const { return is_ok(); }

    T& value() {
        if (!is_ok()) throw std::runtime_error("Result::value() on error");
        return std::get<T>(data_);
    }
    const T& value() const {
        if (!is_ok()) throw std::runtime_error("Result::value() on error");
        return std::get<T>(data_);
    }

    E& error() {
        if (!is_err()) throw std::runtime_error("Result::error() on ok");
        return std::get<E>(data_);
    }

    T value_or(T default_val) const {
        return is_ok() ? std::get<T>(data_) : std::move(default_val);
    }

    // Monadic chain — if ok, apply f; if err, propagate error
    template<typename F>
    auto and_then(F&& f) -> Result<decltype(f(value()).value()), E> {
        using RetT = decltype(f(value()).value());
        if (is_ok()) return f(std::get<T>(data_));
        return Result<RetT, E>::err(std::get<E>(data_));
    }

private:
    Result() = default;
    std::variant<T, E> data_;
};

// Convenience helpers
template<typename T, typename E>
Result<T,E> Ok(T v)  { return Result<T,E>::ok(std::move(v)); }

template<typename T, typename E>
Result<T,E> Err(E e) { return Result<T,E>::err(std::move(e)); }
```

---

## 5. Error Codes — For C Interop and Real-Time Code

For embedded code and C interop, `std::error_code` provides a standardized error-code mechanism:

```cpp
#include <system_error>

// Define your own error category
enum class SensorError {
    NotReady    = 1,
    Timeout     = 2,
    HardwareFault = 3,
    CalibrationNeeded = 4,
};

// Register with the std::error_code system
struct SensorErrorCategory : std::error_category {
    const char* name() const noexcept override {
        return "sensor";
    }
    std::string message(int ev) const override {
        switch (static_cast<SensorError>(ev)) {
            case SensorError::NotReady:          return "sensor not ready";
            case SensorError::Timeout:           return "read timeout";
            case SensorError::HardwareFault:     return "hardware fault";
            case SensorError::CalibrationNeeded: return "calibration needed";
            default:                             return "unknown sensor error";
        }
    }
};

const SensorErrorCategory& sensor_error_category() {
    static SensorErrorCategory cat;
    return cat;
}

std::error_code make_error_code(SensorError e) {
    return {static_cast<int>(e), sensor_error_category()};
}

// Enable implicit conversion
namespace std {
    template<>
    struct is_error_code_enum<SensorError> : true_type {};
}

// Usage
std::error_code read_sensor(uint8_t id, float& out) {
    if (!sensor_ready(id))
        return SensorError::NotReady;
    if (!wait_for_data(id, 100))
        return SensorError::Timeout;
    out = read_adc(id);
    return {};  // success — default-constructed error_code is "no error"
}

// At the call site
float value;
if (auto ec = read_sensor(0, value)) {  // error_code is truthy when there's an error
    printf("Error: %s\n", ec.message().c_str());
} else {
    printf("Reading: %.2f\n", value);
}
```

---

## 6. Putting It Together — Serial Protocol with Full Error Handling

A complete frame parser and sender using the right error strategy for each situation:

```cpp
// serial_protocol.cpp
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <cstdlib>
#include <span>
#include <array>
#include <vector>
#include <optional>
#include <variant>
#include <string>
#include <string_view>
#include <cassert>

// ---- Error types ----

enum class FrameError {
    TooShort,
    BadMagic,
    BadVersion,
    ChecksumMismatch,
    PayloadTooLarge,
};

std::string_view frame_error_str(FrameError e) {
    switch (e) {
        case FrameError::TooShort:          return "frame too short";
        case FrameError::BadMagic:          return "bad magic byte";
        case FrameError::BadVersion:        return "unsupported version";
        case FrameError::ChecksumMismatch:  return "checksum mismatch";
        case FrameError::PayloadTooLarge:   return "payload too large";
        default:                            return "unknown error";
    }
}

// ---- Result type (C++17) ----

template<typename T>
using ParseResult = std::variant<T, FrameError>;

template<typename T>
bool is_ok(const ParseResult<T>& r) {
    return std::holds_alternative<T>(r);
}

template<typename T>
const T& get_value(const ParseResult<T>& r) {
    return std::get<T>(r);
}

template<typename T>
FrameError get_error(const ParseResult<T>& r) {
    return std::get<FrameError>(r);
}

// ---- Frame format ----
// [0]     magic       0xAB
// [1]     version     0x01
// [2..3]  length      uint16_t LE  — payload length
// [4..N]  payload     bytes
// [N+1]   checksum    XOR of [0..N]

constexpr uint8_t MAGIC   = 0xAB;
constexpr uint8_t VERSION = 0x01;
constexpr size_t  HEADER  = 4;
constexpr size_t  MAX_PAYLOAD = 256;

struct Frame {
    uint8_t              version;
    std::vector<uint8_t> payload;

    void print() const {
        printf("  Frame{ver=%u payload=%zu bytes: ",
               version, payload.size());
        for (size_t i = 0; i < std::min(payload.size(), size_t(8)); ++i)
            printf("%02X ", payload[i]);
        if (payload.size() > 8) printf("...");
        printf("}\n");
    }
};

// ---- Parser ----

uint8_t compute_xor(std::span<const uint8_t> data) {
    uint8_t x = 0;
    for (uint8_t b : data) x ^= b;
    return x;
}

uint16_t read_le16(std::span<const uint8_t, 2> s) {
    return static_cast<uint16_t>(s[0]) |
           static_cast<uint16_t>(s[1]) << 8;
}

ParseResult<Frame> parse_frame(std::span<const uint8_t> buf) {
    // Minimum: header + 0 payload + 1 checksum
    if (buf.size() < HEADER + 1)
        return FrameError::TooShort;

    if (buf[0] != MAGIC)
        return FrameError::BadMagic;

    if (buf[1] != VERSION)
        return FrameError::BadVersion;

    uint16_t payload_len = read_le16(buf.subspan<2, 2>());

    if (payload_len > MAX_PAYLOAD)
        return FrameError::PayloadTooLarge;

    if (buf.size() < HEADER + payload_len + 1)
        return FrameError::TooShort;

    // Verify checksum
    size_t  checksum_offset = HEADER + payload_len;
    uint8_t expected = compute_xor(buf.first(checksum_offset));
    uint8_t actual   = buf[checksum_offset];

    if (expected != actual)
        return FrameError::ChecksumMismatch;

    // Build the frame
    Frame f;
    f.version = buf[1];
    f.payload.assign(buf.data() + HEADER,
                     buf.data() + HEADER + payload_len);
    return f;
}

// ---- Serializer ----

// Returns nullopt if payload too large
std::optional<std::vector<uint8_t>>
serialize_frame(const std::vector<uint8_t>& payload) {
    if (payload.size() > MAX_PAYLOAD) return std::nullopt;

    std::vector<uint8_t> buf;
    buf.reserve(HEADER + payload.size() + 1);

    buf.push_back(MAGIC);
    buf.push_back(VERSION);
    buf.push_back(static_cast<uint8_t>(payload.size() & 0xFF));
    buf.push_back(static_cast<uint8_t>((payload.size() >> 8) & 0xFF));
    buf.insert(buf.end(), payload.begin(), payload.end());

    uint8_t checksum = compute_xor(buf);
    buf.push_back(checksum);

    return buf;
}

// ---- Higher-level: try to parse, log errors ----

std::optional<Frame> try_parse(std::span<const uint8_t> buf) {
    auto result = parse_frame(buf);
    if (is_ok(result)) {
        return get_value(result);
    } else {
        printf("  Parse error: %.*s\n",
               static_cast<int>(frame_error_str(get_error(result)).size()),
               frame_error_str(get_error(result)).data());
        return std::nullopt;
    }
}

// ---- Demonstrate noexcept / exception boundary ----

class FrameProcessor {
public:
    // Returns number processed — never throws
    int process_batch(std::span<const std::span<const uint8_t>> frames) noexcept {
        int ok = 0;
        for (const auto& frame_buf : frames) {
            try {
                process_one(frame_buf);
                ++ok;
            } catch (const std::exception& e) {
                printf("  [processor] caught: %s\n", e.what());
            } catch (...) {
                printf("  [processor] caught unknown exception\n");
            }
        }
        return ok;
    }

private:
    void process_one(std::span<const uint8_t> buf) {
        auto result = parse_frame(buf);
        if (!is_ok(result)) {
            throw std::runtime_error(
                std::string(frame_error_str(get_error(result)))
            );
        }
        get_value(result).print();
    }
};

int main() {
    printf("=== Serial Protocol Error Handling ===\n\n");

    // ---- Build valid frame ----
    std::vector<uint8_t> payload = {'H', 'E', 'L', 'L', 'O'};
    auto serialized = serialize_frame(payload);
    assert(serialized.has_value());

    printf("Serialized frame (%zu bytes):", serialized->size());
    for (uint8_t b : *serialized) printf(" %02X", b);
    printf("\n");

    // ---- Parse valid frame ----
    printf("\n--- Parse valid frame ---\n");
    auto result = parse_frame(*serialized);
    if (is_ok(result)) {
        printf("OK: ");
        get_value(result).print();
    }

    // ---- Error cases ----
    printf("\n--- Error cases ---\n");

    struct TestCase { const char* name; std::vector<uint8_t> buf; };
    std::vector<TestCase> cases = {
        {"too short",  {0xAB}},
        {"bad magic",  {0x00, 0x01, 0x01, 0x00, 0xFF, 0xFF}},
        {"bad version",{0xAB, 0x02, 0x01, 0x00, 0xFF, 0xFF}},
        {"bad checksum",{0xAB, 0x01, 0x01, 0x00, 0xFF, 0x00}},  // wrong checksum
        {"ok",         *serialized},
    };

    for (const auto& tc : cases) {
        printf("  %-15s → ", tc.name);
        auto r = parse_frame(tc.buf);
        if (is_ok(r)) {
            printf("OK (%zu bytes payload)\n", get_value(r).payload.size());
        } else {
            printf("ERROR: %.*s\n",
                   static_cast<int>(frame_error_str(get_error(r)).size()),
                   frame_error_str(get_error(r)).data());
        }
    }

    // ---- optional — sensor reading ----
    printf("\n--- std::optional: sensor readings ---\n");
    auto try_read = [](int id) -> std::optional<float> {
        if (id == 0) return 23.5f;
        if (id == 1) return 65.0f;
        return std::nullopt;  // sensor not available
    };

    for (int id = 0; id < 4; ++id) {
        float v = try_read(id).value_or(-1.0f);
        printf("  sensor %d: %s\n",
               id, v < 0 ? "not available" : std::to_string(v).c_str());
    }

    // ---- noexcept boundary ----
    printf("\n--- noexcept exception boundary ---\n");
    FrameProcessor processor;

    // Build spans for the test frames
    auto& good = *serialized;
    std::vector<uint8_t> bad  = {0x00, 0x01, 0x02};

    std::array<std::span<const uint8_t>, 3> frame_spans = {
        std::span<const uint8_t>{good},
        std::span<const uint8_t>{bad},
        std::span<const uint8_t>{good},
    };

    int processed = processor.process_batch(frame_spans);
    printf("Processed %d/%zu frames successfully\n", processed, frame_spans.size());

    // ---- Payload too large ----
    printf("\n--- Oversized payload ---\n");
    std::vector<uint8_t> huge_payload(300, 0xFF);
    auto huge = serialize_frame(huge_payload);
    if (!huge) {
        printf("serialize_frame correctly returned nullopt for %zu-byte payload\n",
               huge_payload.size());
    }

    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address,undefined -o error_demo serial_protocol.cpp
./error_demo
```

### What to observe

`parse_frame` returns `ParseResult<Frame>` — a `std::variant<Frame, FrameError>`. The caller explicitly handles both cases. There's no way to accidentally ignore the error — accessing the wrong variant member would throw `std::bad_variant_access`.

`serialize_frame` returns `std::optional` — because "payload too large" is the only failure mode and the caller just needs to know if it worked, not why it failed.

`FrameProcessor::process_batch` is `noexcept` — it's the exception boundary. Internally it calls code that may throw, but it catches everything and returns a count. The caller gets a clean integer result with no exception handling required.

---

## Key Takeaways for Day 16

- Exceptions are for truly exceptional conditions — not for expected failure paths or on `-fno-exceptions` targets
- Always catch exceptions by `const&` — catching by value slices the object, catching by pointer is almost never right
- `noexcept` documents and enforces no-throw — enables compiler optimizations, required for move operations in standard containers
- `std::optional<T>` is for "might not have a value" — absence is a normal outcome, not an error. Zero heap allocation, clean call-site syntax with `if (auto v = f())`
- `std::expected<T,E>` (C++23) or a `Result<T,E>` variant carries both success and failure information — callers must handle both, unlike return codes which are easy to ignore
- `std::error_code` integrates with OS error codes and provides category-based error description — useful for system programming and C interop
- Use `noexcept` exception boundaries at module interfaces — internal code can throw, the boundary catches and converts to a return value
- The right strategy depends on context: availability of exceptions, frequency of failure, whether the caller needs to know why it failed, and whether the code runs in real-time or interrupt context

Phase 3 is complete. You now have templates, the full STL, lambdas, move semantics, and error handling. Phase 4 begins with Day 17: deep pointers, smart pointer internals, and custom allocators — the foundation for the concurrency work that follows.