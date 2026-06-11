

Day 13's pipeline used lambdas as throwaway predicates passed to algorithms. Today we go deep on what lambdas actually are, how capture works at the machine level, where the lifetime traps are, and when `std::function` is the right tool versus when it's expensive overhead. Lambdas aren't syntax sugar — they're a mechanism for creating closures, and understanding them fully changes how you design callbacks, event systems, and async handlers in IoT code.

---

## 1. What a Lambda Actually Is

A lambda is syntactic sugar for an anonymous struct with `operator()`. The compiler generates the struct for you. Understanding this makes every lambda question answerable from first principles.

```cpp
// This lambda:
auto add = [](int a, int b) { return a + b; };

// Is exactly equivalent to this compiler-generated struct:
struct __lambda_add {
    int operator()(int a, int b) const {
        return a + b;
    }
};
__lambda_add add{};
```

When you capture variables, they become members of that struct:

```cpp
float threshold = 25.0f;

// This lambda:
auto is_high = [threshold](float v) { return v > threshold; };

// Is exactly equivalent to:
struct __lambda_is_high {
    float threshold;   // captured by value — copy of the outer threshold
    bool operator()(float v) const {
        return v > threshold;
    }
};
__lambda_is_high is_high{threshold};  // threshold copied in at construction
```

Every question about lambda behavior reduces to: "what would this struct do?"

---

## 2. Lambda Syntax — All the Parts

```cpp
[capture](parameters) mutable noexcept -> return_type {
    body
}
```

Only the capture list and body are required. Everything else is optional:

```cpp
[]() {}                    // minimal lambda — captures nothing, no params, no body
[](int x) { return x; }   // one parameter, deduced return type
[x](int y) { return x+y; } // captures x by value
[&x](int y) { x += y; }   // captures x by reference
[=]() { return val; }      // captures all locals by value
[&]() { val += 1; }        // captures all locals by reference
[=, &val]() {}             // all by value EXCEPT val by reference
[&, val]() {}              // all by reference EXCEPT val by value
```

Return type is deduced if omitted — works for single-expression bodies and simple cases. Specify it explicitly when the body is complex or you want to document the return type:

```cpp
auto f = [](float raw) -> std::optional<float> {
    if (raw < 0.0f || raw > 4095.0f) return std::nullopt;
    return raw / 4095.0f * 3.3f;
};
```

---

## 3. Capture Modes — What They Actually Do

### Capture by Value — `[x]` or `[=]`

The captured variable is **copied into the lambda struct at the point of lambda creation**. The lambda has its own copy — changes to the original don't affect the lambda, and changes inside the lambda (if mutable) don't affect the original.

```cpp
float threshold = 25.0f;
auto check = [threshold](float v) { return v > threshold; };

threshold = 50.0f;          // change original
check(30.0f);               // still compares against 25.0f — lambda has its OWN copy
```

By-value capture is a snapshot at creation time. Use it when the lambda needs to outlive the variable, or when you want the current value locked in.

### Capture by Reference — `[&x]` or `[&]`

The lambda holds a **reference** to the original variable. Changes to the original are visible inside the lambda, and modifications inside the lambda affect the original.

```cpp
int count = 0;
auto increment = [&count]() { ++count; };

increment();
increment();
printf("%d\n", count);  // 2 — lambda modified the original
```

By-reference capture is the lifetime trap: **if the lambda outlives the variable it captures by reference, it holds a dangling reference.**

```cpp
// DANGER — classic capture lifetime bug
std::function<bool(float)> make_checker(float threshold) {
    return [&threshold](float v) {    // captures threshold BY REFERENCE
        return v > threshold;         // threshold is a parameter — destroyed on return
    };
    // threshold goes out of scope here — lambda holds dangling reference
}

auto checker = make_checker(25.0f);
checker(30.0f);   // undefined behavior — dereferences dangling reference
```

Fix: capture by value when the lambda outlives the captured variable:

```cpp
std::function<bool(float)> make_checker(float threshold) {
    return [threshold](float v) {    // capture by VALUE — copy lives in the lambda
        return v > threshold;
    };
}   // safe — lambda owns its copy of threshold
```

### The `[=]` and `[&]` Default Capture — Use Carefully

`[=]` captures everything used in the body by value. `[&]` captures everything by reference. Both are convenient but can hide ownership issues:

```cpp
class SensorMonitor {
    float threshold_ = 25.0f;

    void setup_timer() {
        // [&] captures *this by reference — timer callback may outlive *this
        // DANGEROUS if SensorMonitor is destroyed before the timer fires
        auto callback = [&]() { check_threshold(); };

        // [this] or [=] captures *this — same danger, just explicit
        auto callback2 = [this]() { check_threshold(); };

        // Safe: [threshold = threshold_] captures the VALUE, not the object
        auto callback3 = [threshold = threshold_]() {
            return threshold > 20.0f;
        };
    }
    void check_threshold() {}
};
```

In callback-heavy IoT code (MQTT handlers, timer callbacks, interrupt callbacks), by-reference capture of `this` is a common source of use-after-free bugs. Default to by-value capture in callbacks. Capture `shared_ptr<this>` if you need the object to stay alive.

---

## 4. `mutable` — Modifying Captured Values

By default, `operator()` is `const` — the lambda can't modify captured-by-value variables. `mutable` removes the const:

```cpp
int counter = 0;
auto count_calls = [counter]() mutable {
    return ++counter;   // modifies the lambda's OWN copy — not the outer counter
};

count_calls();  // returns 1
count_calls();  // returns 2
count_calls();  // returns 3
printf("%d\n", counter);  // still 0 — outer unchanged
```

Use `mutable` for stateful lambdas that track their own internal state — a counter, a running average, a sequence number. The state lives in the lambda object, not the outer scope.

---

## 5. Generic Lambdas — `auto` Parameters

C++14 introduced generic lambdas — `auto` parameters that work like templates:

```cpp
// Generic lambda — works for any type with operator
auto less_than = [](auto a, auto b) { return a < b; };

less_than(3, 5);          // int
less_than(3.14, 2.72);    // double
less_than("abc"s, "xyz"s); // std::string

// Generic lambda with perfect forwarding
auto wrap_call = [](auto&& func, auto&&... args) {
    return std::forward<decltype(func)>(func)(
        std::forward<decltype(args)>(args)...
    );
};
```

Under the hood, each call with different types instantiates a different `operator()` — same as a function template.

---

## 6. Immediately Invoked Lambdas

Lambdas can be called immediately at the point of definition. This is useful for initializing `const` variables with complex logic:

```cpp
// Without immediately invoked lambda — verbose
DeviceConfig config;
if (use_debug_mode) {
    config.log_level = LogLevel::Debug;
    config.baud_rate = 9600;
} else {
    config.log_level = LogLevel::Error;
    config.baud_rate = 115200;
}

// With immediately invoked lambda — config can be const
const DeviceConfig config = [&]() {
    DeviceConfig c;
    c.log_level = use_debug_mode ? LogLevel::Debug : LogLevel::Error;
    c.baud_rate = use_debug_mode ? 9600 : 115200;
    return c;
}();   // <— the () calls it immediately
```

This pattern also scopes complex initialization — the temporary variables inside the lambda don't pollute the outer scope.

---

## 7. `std::function` — Type-Erased Callable

A lambda has a unique, unnameable type. You can store it in `auto`, pass it as a template parameter — but you can't put two different lambdas with the same signature in a `std::vector` without type erasure.

`std::function<Ret(Args...)>` is a type-erased container for any callable with that signature — lambdas, function pointers, functors, bound member functions:

```cpp
#include <functional>

// Can hold any callable that takes float and returns bool
std::function<bool(float)> checker;

checker = [](float v) { return v > 25.0f; };   // lambda
checker = &validate_reading;                    // function pointer
checker = SensorValidator{};                    // functor object

// Store different lambdas in a vector — requires std::function
std::vector<std::function<void(const SensorReading&)>> handlers;
handlers.push_back([](const SensorReading& r) { log_reading(r); });
handlers.push_back([](const SensorReading& r) { store_reading(r); });
handlers.push_back([threshold = 25.0f](const SensorReading& r) {
    if (r.value > threshold) send_alert(r);
});

for (const auto& h : handlers) h(reading);
```

### The Cost of `std::function`

`std::function` is not free. It uses type erasure internally — it stores the callable on the heap (for large callables) or inline (Small Buffer Optimization for small callables). Every call goes through an indirect function call (like a virtual call). Every copy may allocate.

```cpp
// Zero cost — template parameter, inlined by compiler
template<typename Func>
void for_each(const std::vector<float>& v, Func f) {
    for (float x : v) f(x);
}
for_each(values, [](float x) { printf("%.2f\n", x); });

// Has cost — type erasure, heap allocation, indirect call
void for_each_slow(const std::vector<float>& v, std::function<void(float)> f) {
    for (float x : v) f(x);
}
```

**When to use `std::function`:**

- Storing callbacks in a container (`std::vector<std::function<...>>`)
- Storing a callback as a class member for later invocation
- Passing a callback across an API boundary where the concrete type can't be a template parameter

**When NOT to use `std::function`:**

- Hot paths — the indirect call and potential allocation are real costs
- Functions that immediately call the callable — use a template parameter instead

---

## 8. `std::bind` — Partial Application

`std::bind` creates a new callable from an existing one with some arguments pre-filled. In modern C++, lambdas almost always do this more clearly:

```cpp
void log(LogLevel level, const std::string& msg);

// std::bind — harder to read
auto error_log = std::bind(log, LogLevel::Error, std::placeholders::_1);

// Lambda — clearer, same result
auto error_log = [](const std::string& msg) {
    log(LogLevel::Error, msg);
};

// Binding member functions — where bind still sometimes appears
class MQTTClient {
    void on_message(const std::string& topic, const std::string& payload);
public:
    void register_handler(EventBus& bus) {
        // Lambda capturing this — preferred
        bus.subscribe([this](const auto& t, const auto& p) {
            on_message(t, p);
        });

        // std::bind alternative — more verbose
        bus.subscribe(std::bind(&MQTTClient::on_message, this,
                                std::placeholders::_1,
                                std::placeholders::_2));
    }
};
```

Prefer lambdas over `std::bind` for all new code. `std::bind` exists in older codebases you'll read — know how it works, don't write it.

---

## 9. Putting It Together — Event Bus

An event bus is the central pattern for decoupled IoT systems: publishers emit events, subscribers receive them, neither knows about the other. Here's a full implementation using everything from today:

```cpp
// event_bus.cpp
#include <functional>
#include <vector>
#include <unordered_map>
#include <string>
#include <cstdint>
#include <cstdio>
#include <cassert>
#include <optional>

// ---- Event types ----

struct SensorEvent {
    uint8_t     sensor_id;
    float       value;
    uint32_t    timestamp_ms;
    std::string topic;

    void print() const {
        printf("  SensorEvent{id=%u val=%.2f t=%u topic='%s'}\n",
               sensor_id, value, timestamp_ms, topic.c_str());
    }
};

struct AlertEvent {
    std::string message;
    float       threshold;
    float       actual;
    bool        is_high;  // true = above high, false = below low
};

// ---- Generic typed event bus ----

template<typename EventT>
class EventBus {
public:
    using Handler   = std::function<void(const EventT&)>;
    using HandlerID = uint32_t;

    // Subscribe — returns an ID for later unsubscription
    HandlerID subscribe(Handler handler) {
        HandlerID id = next_id_++;
        handlers_.emplace(id, std::move(handler));
        printf("  [bus] subscriber %u registered (%zu total)\n",
               id, handlers_.size());
        return id;
    }

    // Subscribe with filter predicate — only fires if predicate returns true
    HandlerID subscribe_if(
        std::function<bool(const EventT&)> predicate,
        Handler handler)
    {
        return subscribe([pred = std::move(predicate),
                          h    = std::move(handler)](const EventT& e) {
            if (pred(e)) h(e);
        });
    }

    // Unsubscribe by ID
    bool unsubscribe(HandlerID id) {
        auto erased = handlers_.erase(id);
        if (erased) printf("  [bus] subscriber %u removed\n", id);
        return erased > 0;
    }

    // Publish — dispatch to all subscribers
    void publish(const EventT& event) const {
        for (const auto& [id, handler] : handlers_) {
            handler(event);
        }
    }

    size_t subscriber_count() const { return handlers_.size(); }

private:
    std::unordered_map<HandlerID, Handler> handlers_;
    HandlerID next_id_ = 0;
};

// ---- Stateful subscriber — running average ----

class RunningAverage {
public:
    explicit RunningAverage(uint8_t sensor_id, int window = 4)
        : sensor_id_(sensor_id)
        , window_(window)
        , count_(0)
        , sum_(0.0f)
    {}

    // Returns the handler to register with the event bus
    EventBus<SensorEvent>::Handler make_handler() {
        // Capture this — RunningAverage must outlive the handler
        return [this](const SensorEvent& e) {
            if (e.sensor_id != sensor_id_) return;
            sum_ += e.value;
            ++count_;
            if (count_ >= window_) {
                printf("  [avg sensor=%u] window avg = %.3f  (n=%u)\n",
                       sensor_id_, sum_ / static_cast<float>(count_), count_);
                sum_   = 0.0f;
                count_ = 0;
            }
        };
    }

private:
    uint8_t sensor_id_;
    int     window_;
    int     count_;
    float   sum_;
};

// ---- Alert detector ----

EventBus<SensorEvent>::Handler make_alert_handler(
    EventBus<AlertEvent>& alert_bus,
    uint8_t sensor_id,
    float   low,
    float   high)
{
    // Capture alert_bus by reference — must remain alive
    // Capture sensor_id, low, high by value — safe, primitives
    return [&alert_bus, sensor_id, low, high](const SensorEvent& e) {
        if (e.sensor_id != sensor_id) return;
        if (e.value > high) {
            alert_bus.publish({"High threshold exceeded",
                               high, e.value, true});
        } else if (e.value < low) {
            alert_bus.publish({"Low threshold exceeded",
                               low, e.value, false});
        }
    };
}

int main() {
    printf("=== Event Bus Demo ===\n\n");

    EventBus<SensorEvent> sensor_bus;
    EventBus<AlertEvent>  alert_bus;

    // ---- Subscriber 1: log all events ----
    auto log_id = sensor_bus.subscribe([](const SensorEvent& e) {
        printf("  [logger] ");
        e.print();
    });

    // ---- Subscriber 2: filtered — only sensor 0 ----
    sensor_bus.subscribe_if(
        [](const SensorEvent& e) { return e.sensor_id == 0; },
        [](const SensorEvent& e) {
            printf("  [sensor0 handler] value=%.2f\n", e.value);
        }
    );

    // ---- Subscriber 3: running average per sensor ----
    RunningAverage avg0(0, 3);   // sensor 0, window=3
    RunningAverage avg1(1, 2);   // sensor 1, window=2

    sensor_bus.subscribe(avg0.make_handler());
    sensor_bus.subscribe(avg1.make_handler());

    // ---- Subscriber 4: alert generator ----
    sensor_bus.subscribe(
        make_alert_handler(alert_bus, 0, 20.0f, 25.0f)
    );

    // ---- Alert subscriber ----
    alert_bus.subscribe([](const AlertEvent& a) {
        printf("  [ALERT] %s — threshold=%.2f actual=%.2f %s\n",
               a.message.c_str(), a.threshold, a.actual,
               a.is_high ? "(HIGH)" : "(LOW)");
    });

    printf("\nSubscribers: %zu\n\n", sensor_bus.subscriber_count());

    // ---- Publish events ----
    printf("--- Publishing events ---\n");
    sensor_bus.publish({0, 23.5f, 1000, "sensors/temperature"});
    sensor_bus.publish({1, 65.0f, 1001, "sensors/humidity"});
    sensor_bus.publish({0, 24.1f, 1002, "sensors/temperature"});
    sensor_bus.publish({1, 63.5f, 1003, "sensors/humidity"});
    sensor_bus.publish({0, 26.8f, 1004, "sensors/temperature"}); // triggers HIGH alert
    sensor_bus.publish({0, 22.3f, 1005, "sensors/temperature"});
    sensor_bus.publish({0, 18.9f, 1006, "sensors/temperature"}); // triggers LOW alert

    // ---- Unsubscribe the logger ----
    printf("\n--- Unsubscribing logger (id=%u) ---\n", log_id);
    sensor_bus.unsubscribe(log_id);

    printf("\n--- Events after unsubscribe ---\n");
    sensor_bus.publish({0, 23.0f, 1007, "sensors/temperature"});

    // ---- Stateful mutable lambda — sequence numbering ----
    printf("\n--- Mutable lambda: sequence numbering ---\n");
    uint32_t seq = 0;
    auto sequencer = sensor_bus.subscribe(
        [seq](const SensorEvent& e) mutable {
            printf("  [seq %u] sensor=%u val=%.2f\n",
                   ++seq, e.sensor_id, e.value);
        }
    );

    sensor_bus.publish({0, 23.1f, 1008, "sensors/temperature"});
    sensor_bus.publish({1, 64.2f, 1009, "sensors/humidity"});
    sensor_bus.publish({0, 23.4f, 1010, "sensors/temperature"});

    printf("\nseq in outer scope: %u (unchanged — lambda has its own copy)\n", seq);

    // ---- Immediately invoked lambda for config ----
    printf("\n--- Immediately invoked lambda ---\n");
    const bool debug_mode = true;
    const auto config = [debug_mode]() {
        struct Config { int log_level; int baud_rate; const char* label; };
        if (debug_mode) return Config{0, 9600,   "debug"};
        else            return Config{3, 115200,  "production"};
    }();
    printf("Config: log=%d baud=%d mode=%s\n",
           config.log_level, config.baud_rate, config.label);

    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address -o event_bus event_bus.cpp
./event_bus
```

### What to observe

`RunningAverage::make_handler()` returns a `std::function` that captures `this`. The `RunningAverage` objects (`avg0`, `avg1`) must outlive the event bus — if you moved them into a shorter scope, the lambda would hold a dangling `this`. This is the pattern to watch for in callback-heavy code.

The mutable lambda `sequencer` has its own `seq` copy — the outer `seq` stays at 0. The lambda's `seq` increments independently. This is exactly the closure semantics you'd expect from Python, except it's explicit here — you see the by-value capture at the declaration site.

`subscribe_if` composes two lambdas — the outer lambda captures the predicate and handler by move (`std::move`) then calls `pred(e)` before `h(e)`. This is lambda composition — building higher-order behavior from simpler pieces.

---

## Key Takeaways for Day 14

- A lambda is a compiler-generated struct with `operator()` — captured variables are members. Every lambda question reduces to: "what would this struct do?"
- Capture by value is a snapshot at creation time — the lambda has its own copy, immune to later changes to the original
- Capture by reference holds a reference to the original — if the lambda outlives the variable, it's a dangling reference. Default to by-value in callbacks that outlive the current scope
- `mutable` removes the `const` from `operator()` — allows modification of captured-by-value variables within the lambda's own copy
- Generic lambdas with `auto` parameters are function templates — each distinct type generates a new `operator()` instantiation
- `std::function` is type-erased storage for any callable — enables storing mixed lambdas in containers and as class members, but has overhead (indirect call, potential heap allocation)
- Use template parameters for immediate-call scenarios (STL algorithms), `std::function` for stored callbacks
- Immediately invoked lambdas initialize `const` variables with complex logic — keeps initialization scoped and the result immutable
- `std::bind` is legacy — lambdas are always clearer for the same purpose

Day 15 covers move semantics and perfect forwarding in depth — `std::move`, rvalue references, universal references, and `std::forward`. After today's lambdas, Day 15 explains why `std::move(handler)` in the event bus matters and what it actually does at the machine level.