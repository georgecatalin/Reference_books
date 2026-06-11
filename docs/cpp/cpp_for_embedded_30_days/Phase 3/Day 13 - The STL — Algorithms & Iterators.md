

Day 12 gave you the containers. Today gives you the operations that work on all of them. The STL algorithm library is about 80 functions in `<algorithm>` and `<numeric>` — each one a named, tested, readable building block that replaces a hand-written loop. The goal isn't memorizing all 80. It's internalizing the iterator model so you can use any of them, and knowing the dozen you'll reach for every day.

---

## 1. The Iterator Model — The Glue Between Containers and Algorithms

Algorithms don't know about containers. Containers don't know about algorithms. Iterators are the interface between them. An iterator is a generalized pointer — it points to an element, can be incremented, and can be dereferenced.

```
Container:     [0]  [1]  [2]  [3]  [4]  [5]
                ↑                        ↑
           begin()                    end()
           (first element)       (one past last — never dereference)
```

`end()` points one past the last element — it's a sentinel, not a valid element. The half-open range `[begin, end)` is the universal convention.

```cpp
std::vector<int> v = {1, 2, 3, 4, 5};

// Manual iterator loop — rare in modern C++, but good to understand
for (auto it = v.begin(); it != v.end(); ++it) {
    printf("%d ", *it);   // dereference iterator to get value
}

// Range-based for — syntactic sugar over iterators
for (int x : v) { printf("%d ", x); }

// Both are equivalent — the compiler desugars range-for into iterator form
```

### Iterator Categories

Algorithms require different capabilities from iterators. The categories from weakest to strongest:

```
Input iterator      — read once, forward only (std::istream_iterator)
Forward iterator    — read multiple times, forward only (std::forward_list)
Bidirectional       — forward and backward (std::list, std::map)
Random access       — jump to any position in O(1) (std::vector, std::array)
Contiguous          — random access + guaranteed contiguous memory (std::vector, std::array, raw arrays)
```

`std::sort` requires random access iterators — you can't sort a `std::list` with it. `std::find` only needs input iterators — it works on everything. The compiler tells you at the call site if you use an algorithm with an incompatible iterator category.

### Reverse and Other Iterator Adaptors

```cpp
std::vector<int> v = {1, 2, 3, 4, 5};

// Reverse iteration
for (auto it = v.rbegin(); it != v.rend(); ++it) {
    printf("%d ", *it);   // 5 4 3 2 1
}

// Insert iterator — algorithms that write output can write to a container
std::vector<int> result;
std::back_inserter(result);   // iterator that calls push_back on each write
```

---

## 2. The Algorithms You'll Use Daily

### Finding and Searching

```cpp
#include <algorithm>
std::vector<int> v = {3, 1, 4, 1, 5, 9, 2, 6};

// Find first element equal to value — O(n)
auto it = std::find(v.begin(), v.end(), 5);
if (it != v.end()) printf("found 5 at index %td\n", it - v.begin());

// Find first element matching predicate — O(n)
auto it2 = std::find_if(v.begin(), v.end(),
    [](int x) { return x > 4; });   // first element > 4

// Find first element NOT matching predicate
auto it3 = std::find_if_not(v.begin(), v.end(),
    [](int x) { return x < 5; });

// Count elements matching predicate — O(n)
int count = std::count_if(v.begin(), v.end(),
    [](int x) { return x % 2 == 0; });   // count evens

// Check if ANY element matches — O(n), short-circuits
bool any_large = std::any_of(v.begin(), v.end(),
    [](int x) { return x > 8; });

// Check if ALL elements match
bool all_positive = std::all_of(v.begin(), v.end(),
    [](int x) { return x > 0; });

// Check if NO element matches
bool none_negative = std::none_of(v.begin(), v.end(),
    [](int x) { return x < 0; });

// Binary search on SORTED range — O(log n)
std::vector<int> sorted = {1, 2, 3, 4, 5, 6, 7, 8, 9};
bool found = std::binary_search(sorted.begin(), sorted.end(), 5);

// Lower/upper bound on sorted range — O(log n)
auto lb = std::lower_bound(sorted.begin(), sorted.end(), 4);  // first >= 4
auto ub = std::upper_bound(sorted.begin(), sorted.end(), 6);  // first > 6
// [lb, ub) contains all elements in [4, 6]
```

### Sorting

```cpp
std::vector<SensorReading> readings = {
    {23.5f, 1002}, {21.1f, 1000}, {24.8f, 1001}
};

// Sort ascending by default (uses operator<) — O(n log n)
std::sort(readings.begin(), readings.end(),
    [](const SensorReading& a, const SensorReading& b) {
        return a.timestamp < b.timestamp;   // sort by timestamp
    });

// Stable sort — preserves relative order of equal elements
std::stable_sort(readings.begin(), readings.end(),
    [](const SensorReading& a, const SensorReading& b) {
        return a.value < b.value;
    });

// Partial sort — find the N smallest, O(n log k)
std::partial_sort(readings.begin(), readings.begin() + 2, readings.end(),
    [](const SensorReading& a, const SensorReading& b) {
        return a.value < b.value;
    });
// First 2 elements are now the 2 smallest by value

// nth_element — O(n) — element at position n is what would be there if sorted
// Elements before n are <= it, elements after are >= it (not sorted)
std::nth_element(readings.begin(), readings.begin() + 1, readings.end(),
    [](const SensorReading& a, const SensorReading& b) {
        return a.value < b.value;
    });
// readings[1] is the median — others are partitioned but not sorted
```

`std::sort` is not stable — equal elements may be reordered. `std::stable_sort` is stable but slower. Use `sort` by default; use `stable_sort` when relative order of equals matters (e.g., sorting by one field while preserving a prior sort by another).

### Transforming

```cpp
std::vector<float> raw_adc = {1000.0f, 2048.0f, 3500.0f, 4095.0f};

// Transform each element — output can be same or different range
std::vector<float> voltage(raw_adc.size());
std::transform(raw_adc.begin(), raw_adc.end(), voltage.begin(),
    [](float adc) { return adc / 4095.0f * 3.3f; });  // ADC to voltage

// Transform two ranges into one — zip operation
std::vector<float> calibration = {1.01f, 0.99f, 1.02f, 1.00f};
std::vector<float> calibrated(raw_adc.size());
std::transform(voltage.begin(), voltage.end(),
               calibration.begin(),
               calibrated.begin(),
               [](float v, float c) { return v * c; });  // apply calibration

// In-place transform
std::transform(voltage.begin(), voltage.end(), voltage.begin(),
    [](float v) { return v * 1000.0f; });  // volts to millivolts
```

### Filtering — Remove/Erase Idiom

`std::remove_if` doesn't actually remove elements — it partitions the range, moving "removed" elements to the end, and returns an iterator to the new end. You then erase the tail:

```cpp
std::vector<SensorReading> readings;
// ... fill with readings ...

// Erase-remove idiom — remove stale readings
readings.erase(
    std::remove_if(readings.begin(), readings.end(),
        [](const SensorReading& r) { return r.timestamp < 1000; }),
    readings.end()
);

// C++20: std::erase_if — cleaner syntax, same operation
std::erase_if(readings, [](const SensorReading& r) {
    return r.timestamp < 1000;
});
```

This two-step (remove_if + erase) is a frequent source of confusion. The `remove_if` step rearranges elements — elements to keep are moved to the front, elements to discard are moved to the back (in unspecified state). The `erase` step removes the discarded tail. Together they're O(n).

### Filling and Generating

```cpp
std::vector<int> v(10);

std::fill(v.begin(), v.end(), 42);         // fill with constant value
std::fill_n(v.begin(), 5, 99);             // fill first 5 elements

// Generate — call a function for each element
int counter = 0;
std::generate(v.begin(), v.end(), [&counter]() { return counter++; });
// v = {0, 1, 2, 3, 4, 5, 6, 7, 8, 9}

std::generate_n(v.begin(), 5, []() { return rand() % 100; });

// iota — fill with incrementing values
#include <numeric>
std::iota(v.begin(), v.end(), 0);  // v = {0, 1, 2, ..., 9}
```

### Copying and Moving

```cpp
std::vector<int> src = {1, 2, 3, 4, 5};
std::vector<int> dst(5);

std::copy(src.begin(), src.end(), dst.begin());        // copy range
std::copy_if(src.begin(), src.end(),
             std::back_inserter(dst),
             [](int x) { return x % 2 == 0; });        // conditional copy
std::copy_n(src.begin(), 3, dst.begin());               // copy first 3

std::move(src.begin(), src.end(), dst.begin());         // move range
// src elements are in valid but unspecified state after this
```

### Reduction and Aggregation

```cpp
#include <numeric>

std::vector<float> values = {23.5f, 24.1f, 22.8f, 25.0f};

// accumulate — left fold with initial value
float sum = std::accumulate(values.begin(), values.end(), 0.0f);
float product = std::accumulate(values.begin(), values.end(), 1.0f,
    [](float acc, float x) { return acc * x; });

// reduce — like accumulate but can execute in any order (parallelizable)
// C++17
float sum2 = std::reduce(values.begin(), values.end(), 0.0f);

// transform_reduce — transform then reduce in one pass
float sum_sq = std::transform_reduce(
    values.begin(), values.end(),
    0.0f,
    std::plus<float>{},                     // reduction op
    [](float x) { return x * x; });         // transform op
// sum of squares

// inner_product — dot product
std::vector<float> weights = {0.25f, 0.25f, 0.25f, 0.25f};
float weighted_avg = std::inner_product(
    values.begin(), values.end(),
    weights.begin(),
    0.0f);

// min/max
auto [min_it, max_it] = std::minmax_element(values.begin(), values.end());
printf("min=%.2f max=%.2f\n", *min_it, *max_it);

float min_val = std::min_element(values.begin(), values.end()).operator*();
// or more cleanly:
auto min_ptr = std::min_element(values.begin(), values.end());
```

### Partitioning

```cpp
std::vector<SensorReading> readings = { /* mixed */ };

// partition — elements matching predicate come first
auto pivot = std::partition(readings.begin(), readings.end(),
    [](const SensorReading& r) { return r.value > 25.0f; });
// [begin, pivot) = readings with value > 25
// [pivot, end)   = readings with value <= 25

// stable_partition — preserves relative order within each group
auto pivot2 = std::stable_partition(readings.begin(), readings.end(),
    [](const SensorReading& r) { return r.status == Status::Online; });

// is_partitioned — check if partition condition holds
bool partitioned = std::is_partitioned(readings.begin(), readings.end(),
    [](const SensorReading& r) { return r.value > 0; });
```

---

## 3. Iterators with STL Containers Summary

```cpp
// vector, array, deque — random access iterators — support all algorithms
std::vector<int> v;
std::sort(v.begin(), v.end());         // ✓

// list — bidirectional iterators — no random access algorithms
std::list<int> l;
// std::sort(l.begin(), l.end());      // ✗ compile error
l.sort();                              // list has its own sort member

// map — bidirectional iterators over key-value pairs
std::map<std::string, int> m;
std::find_if(m.begin(), m.end(),
    [](const auto& pair) { return pair.second > 5; });  // ✓

// unordered_map — forward iterators
std::unordered_map<std::string, int> um;
std::count_if(um.begin(), um.end(),
    [](const auto& pair) { return pair.second > 5; });  // ✓
```

---

## 4. Putting It Together — Sensor Data Pipeline

A realistic processing pipeline: receive raw sensor readings, filter invalid ones, calibrate, sort by timestamp, compute statistics, and publish alerts:

```cpp
// sensor_pipeline.cpp
#include <algorithm>
#include <numeric>
#include <vector>
#include <string>
#include <cstdio>
#include <cstdint>
#include <cmath>
#include <cassert>
#include <optional>

struct SensorReading {
    float    value;
    uint32_t timestamp_ms;
    uint8_t  sensor_id;
    bool     valid;

    void print() const {
        printf("  [t=%4u id=%u] %6.2f  %s\n",
               timestamp_ms, sensor_id, value,
               valid ? "OK" : "INVALID");
    }
};

struct SensorStats {
    float    min, max, mean, stddev;
    uint32_t count;
    void print() const {
        printf("  count=%u min=%.2f max=%.2f mean=%.2f stddev=%.2f\n",
               count, min, max, mean, stddev);
    }
};

// Calibration: apply per-sensor offset and scale
struct CalibrationParams {
    float offset;
    float scale;
};

// ---- Pipeline stages ----

// Stage 1: filter invalid readings
std::vector<SensorReading> filter_valid(std::vector<SensorReading> readings) {
    std::erase_if(readings, [](const SensorReading& r) { return !r.valid; });
    return readings;
}

// Stage 2: filter out-of-range values (hardware fault detection)
std::vector<SensorReading> filter_range(
    std::vector<SensorReading> readings,
    float low, float high)
{
    std::erase_if(readings, [low, high](const SensorReading& r) {
        return r.value < low || r.value > high;
    });
    return readings;
}

// Stage 3: apply calibration per sensor
std::vector<SensorReading> calibrate(
    std::vector<SensorReading> readings,
    const std::vector<CalibrationParams>& params)
{
    std::transform(readings.begin(), readings.end(), readings.begin(),
        [&params](SensorReading r) {
            if (r.sensor_id < params.size()) {
                const auto& p = params[r.sensor_id];
                r.value = r.value * p.scale + p.offset;
            }
            return r;
        });
    return readings;
}

// Stage 4: sort by timestamp
std::vector<SensorReading> sort_by_time(std::vector<SensorReading> readings) {
    std::sort(readings.begin(), readings.end(),
        [](const SensorReading& a, const SensorReading& b) {
            return a.timestamp_ms < b.timestamp_ms;
        });
    return readings;
}

// Stage 5: compute statistics
SensorStats compute_stats(const std::vector<SensorReading>& readings) {
    if (readings.empty()) return {0, 0, 0, 0, 0};

    auto [min_it, max_it] = std::minmax_element(
        readings.begin(), readings.end(),
        [](const SensorReading& a, const SensorReading& b) {
            return a.value < b.value;
        });

    float sum = std::accumulate(readings.begin(), readings.end(), 0.0f,
        [](float acc, const SensorReading& r) { return acc + r.value; });

    float mean = sum / static_cast<float>(readings.size());

    float variance = std::transform_reduce(
        readings.begin(), readings.end(),
        0.0f,
        std::plus<float>{},
        [mean](const SensorReading& r) {
            float diff = r.value - mean;
            return diff * diff;
        }) / static_cast<float>(readings.size());

    return {
        min_it->value,
        max_it->value,
        mean,
        std::sqrt(variance),
        static_cast<uint32_t>(readings.size())
    };
}

// Stage 6: detect threshold violations
std::vector<SensorReading> find_alerts(
    const std::vector<SensorReading>& readings,
    float threshold_high, float threshold_low)
{
    std::vector<SensorReading> alerts;
    std::copy_if(readings.begin(), readings.end(),
        std::back_inserter(alerts),
        [threshold_high, threshold_low](const SensorReading& r) {
            return r.value > threshold_high || r.value < threshold_low;
        });
    return alerts;
}

// Per-sensor statistics using partition
void per_sensor_stats(const std::vector<SensorReading>& readings) {
    // Collect unique sensor IDs
    std::vector<uint8_t> ids;
    std::transform(readings.begin(), readings.end(), std::back_inserter(ids),
        [](const SensorReading& r) { return r.sensor_id; });
    std::sort(ids.begin(), ids.end());
    ids.erase(std::unique(ids.begin(), ids.end()), ids.end());

    for (uint8_t id : ids) {
        // Count and sum for this sensor
        int count = std::count_if(readings.begin(), readings.end(),
            [id](const SensorReading& r) { return r.sensor_id == id; });

        float sum = std::accumulate(readings.begin(), readings.end(), 0.0f,
            [id](float acc, const SensorReading& r) {
                return r.sensor_id == id ? acc + r.value : acc;
            });

        printf("  Sensor %u: count=%d avg=%.2f\n",
               id, count, sum / static_cast<float>(count));
    }
}

int main() {
    printf("=== Sensor Data Pipeline ===\n\n");

    // Raw readings — mixed valid/invalid, unsorted, uncalibrated
    std::vector<SensorReading> raw = {
        {23.5f,  1005, 0, true },
        {999.9f, 1001, 1, true },   // out of range — hardware fault
        {65.2f,  1003, 1, true },
        {-1.0f,  1002, 0, false},   // invalid flag
        {24.1f,  1008, 0, true },
        {63.8f,  1006, 1, true },
        {500.0f, 1004, 2, true },   // out of range
        {22.9f,  1007, 0, true },
        {1013.f, 1009, 2, true },
        {23.8f,  1010, 0, false},   // invalid flag
        {1011.f, 1000, 2, true },
        {24.6f,  1011, 0, true },
    };

    printf("Raw readings (%zu):\n", raw.size());
    for (const auto& r : raw) r.print();

    // Calibration params per sensor
    std::vector<CalibrationParams> cal_params = {
        {-0.5f, 1.02f},   // sensor 0: offset=-0.5, scale=1.02
        {2.0f,  0.98f},   // sensor 1: offset=+2.0, scale=0.98
        {0.0f,  1.00f},   // sensor 2: no calibration
    };

    // Run pipeline
    auto valid     = filter_valid(raw);
    auto in_range  = filter_range(valid, 0.0f, 2000.0f);
    auto calibrated = calibrate(in_range, cal_params);
    auto sorted    = sort_by_time(calibrated);

    printf("\nAfter pipeline (%zu readings):\n", sorted.size());
    for (const auto& r : sorted) r.print();

    // Statistics on processed data
    printf("\nOverall statistics:\n");
    auto stats = compute_stats(sorted);
    stats.print();

    // Per-sensor breakdown
    printf("\nPer-sensor statistics:\n");
    per_sensor_stats(sorted);

    // Alert detection — temperature > 24.5 or < 22.0
    printf("\nAlerts (temp > 24.5 or < 22.0):\n");
    auto temp_only = sorted;
    std::erase_if(temp_only, [](const SensorReading& r) {
        return r.sensor_id != 0;
    });
    auto alerts = find_alerts(temp_only, 24.5f, 22.0f);
    if (alerts.empty()) {
        printf("  No alerts\n");
    } else {
        for (const auto& a : alerts) a.print();
    }

    // Verify sort correctness
    assert(std::is_sorted(sorted.begin(), sorted.end(),
        [](const SensorReading& a, const SensorReading& b) {
            return a.timestamp_ms < b.timestamp_ms;
        }));
    printf("\nTimestamp sort verified.\n");

    // any_of / all_of / none_of
    bool any_invalid = std::any_of(sorted.begin(), sorted.end(),
        [](const SensorReading& r) { return !r.valid; });
    printf("Any invalid after filter: %s\n", any_invalid ? "YES (bug!)" : "no");

    bool all_in_range = std::all_of(sorted.begin(), sorted.end(),
        [](const SensorReading& r) {
            return r.value >= 0.0f && r.value <= 2000.0f;
        });
    printf("All in range: %s\n", all_in_range ? "yes" : "NO (bug!)");

    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address -o pipeline sensor_pipeline.cpp
./pipeline
```

### What to observe

Each pipeline stage is a pure function — takes a vector by value, returns a transformed vector. This is intentional: each stage is independently testable and the data flow is explicit. In production you'd profile and potentially move to in-place transforms to avoid copies — but start with correctness, optimize with measurement.

`std::erase_if` (C++20, but available via `<vector>` in most C++17 compilers as an extension) replaces the erase-remove idiom. If your compiler doesn't have it, use the erase-remove pattern shown in section 2.

`std::is_sorted` with a custom comparator as the final assertion is a habit worth forming — verify your sort invariant rather than assuming it.

---

## Key Takeaways for Day 13

- Iterators are generalized pointers — `begin()` points to the first element, `end()` points one past the last — never dereference `end()`
- Algorithms work on iterator ranges, not containers — the same algorithm works on `vector`, `array`, `deque`, and raw arrays
- `std::find_if`, `std::count_if`, `std::any_of`, `std::all_of` — predicate-based searching covers most lookup needs
- `std::sort` is not stable — `std::stable_sort` preserves relative order of equals at a small cost. Use `sort` by default
- Erase-remove idiom: `v.erase(std::remove_if(v.begin(), v.end(), pred), v.end())` — `remove_if` partitions, `erase` removes the tail. C++20 `std::erase_if` does both in one call
- `std::accumulate` is a left fold — the initial value type determines the accumulation type. Pass `0.0f` not `0` when accumulating floats
- `std::transform_reduce` applies a transform and reduction in a single pass — use it for weighted sums, sum of squares, dot products
- `std::back_inserter` turns a `push_back` call into an output iterator — use it when the destination size isn't known upfront
- `std::is_sorted`, `std::is_partitioned` — use these in assertions to verify invariants after operations

Day 14 covers lambdas in full — capture mechanics, mutable lambdas, generic lambdas, and how they replace functors. After Day 14 the pipeline from today becomes significantly more concise.