
You have `RingBuffer<T, N>` for fixed-size, stack-allocated queues. But most of the time you need containers whose size isn't known at compile time — a registry of connected devices, a queue of pending messages, a set of active subscriptions. The STL gives you a toolkit of containers, each with different performance characteristics. Picking the wrong one is rarely catastrophic, but picking the right one is free performance and cleaner code.

Today: what each container actually is under the hood, what it costs, and when to use it.

---

## 1. The Mental Model — Data Structures Under the Hood

Before the API, the physical layout:

```
std::vector<T>
┌───┬───┬───┬───┬───┬───┬───┬───┐
│ 0 │ 1 │ 2 │ 3 │ 4 │ 5 │   │   │  contiguous heap block
└───┴───┴───┴───┴───┴───┴───┴───┘
 ↑ data ptr         ↑ size=6  ↑ capacity=8

std::list<T>
┌───┐   ┌───┐   ┌───┐   ┌───┐
│ 0 │──▶│ 1 │──▶│ 2 │──▶│ 3 │  separate heap nodes, pointer chain
└───┘   └───┘   └───┘   └───┘

std::unordered_map<K,V>
bucket[0]: ──▶ {key3, val3} ──▶ {key7, val7}
bucket[1]: ──▶ null
bucket[2]: ──▶ {key1, val1}    hash table with chaining

std::map<K,V>
        [key4]
       /      \
   [key2]    [key6]   red-black tree — sorted, O(log n)
   /    \
[key1] [key3]
```

Layout determines performance. `vector`'s contiguous memory is cache-friendly — hardware prefetchers love sequential access. `list`'s pointer chain means every node access is a potential cache miss. `unordered_map`'s hash table gives O(1) average lookup. `map`'s tree gives O(log n) but guaranteed sorted order.

---

## 2. `std::vector` — The Default Container

Use `vector` until you have a reason not to. It's contiguous, cache-friendly, and the STL algorithms work on it natively.

```cpp
#include <vector>

std::vector<SensorReading> readings;

// Building up
readings.reserve(1000);              // pre-allocate — avoids reallocation
readings.push_back({23.5f, 1000});   // append — O(1) amortized
readings.emplace_back(24.1f, 1001);  // construct in-place — avoids temp object

// Access
readings[0];                         // O(1), no bounds check
readings.at(0);                      // O(1), throws if out of range
readings.front();                    // first element
readings.back();                     // last element
readings.data();                     // raw pointer — for C APIs

// Size management
readings.size();                     // current element count
readings.capacity();                 // allocated space
readings.empty();                    // size() == 0
readings.clear();                    // size → 0, capacity unchanged
readings.shrink_to_fit();            // release excess capacity

// Insert / remove
readings.insert(readings.begin() + 2, {99.0f, 999});  // O(n) — shifts elements
readings.erase(readings.begin() + 2);                  // O(n) — shifts elements

// Fast removal when order doesn't matter — O(1)
void fast_erase(std::vector<SensorReading>& v, size_t i) {
    v[i] = std::move(v.back());   // overwrite with last element
    v.pop_back();                  // remove last
}
```

### The Reallocation Trap

```cpp
std::vector<int> v;
int* p = v.data();         // pointer to internal buffer

v.push_back(1);            // may reallocate
v.push_back(2);            // may reallocate — p is now DANGLING

// Safe pattern: never store pointers/iterators into a vector
// across operations that might reallocate
```

If you store iterators or pointers into a `vector`, any operation that changes capacity invalidates them. `reserve()` upfront prevents reallocation — iterators remain valid as long as capacity doesn't change.

---

## 3. `std::deque` — Double-Ended Queue

`std::deque` (pronounced "deck") supports O(1) push and pop at both ends. Internally it's a sequence of fixed-size chunks — not fully contiguous like `vector`, but more cache-friendly than `list`.

```cpp
#include <deque>

std::deque<uint8_t> packet_queue;

packet_queue.push_back(0xFF);    // O(1) — add to back
packet_queue.push_front(0x00);   // O(1) — add to front
packet_queue.pop_front();        // O(1) — remove from front
packet_queue.pop_back();         // O(1) — remove from back

packet_queue[3];                 // O(1) random access — slower than vector
```

Use `deque` when you need O(1) at both ends. `std::queue` and `std::stack` use `deque` as their default underlying container.

---

## 4. `std::list` and `std::forward_list`

`std::list` is a doubly-linked list. `std::forward_list` is singly-linked. Both give O(1) insertion and deletion anywhere — if you already have an iterator to the position.

```cpp
#include <list>

std::list<SensorReading> pending;
pending.push_back({23.5f, 1000});
pending.push_front({0.0f, 999});

auto it = pending.begin();
++it;
pending.insert(it, {11.0f, 1000});  // O(1) — no element shifting
pending.erase(it);                   // O(1) — just pointer update

// No random access — must iterate
for (const auto& r : pending) { ... }
```

**The honest truth about `std::list`:** in modern code on modern hardware, `list` is almost never the right choice. Cache misses from pointer chasing cost more than the O(n) shifting in a `vector` for most realistic sizes. Benchmarks consistently show `vector` beating `list` up to tens of thousands of elements. Use `list` only when you have iterator stability requirements (iterators remain valid across insertions/deletions anywhere) and you've measured that `vector` is too slow.

---

## 5. `std::map` — Sorted Key-Value Tree

`std::map<K, V>` is a red-black tree. Keys are always sorted. Operations are O(log n).

```cpp
#include <map>

std::map<std::string, DeviceInfo> devices;

// Insert
devices["device_01"] = {.ip = "192.168.1.10", .port = 1883};
devices.emplace("device_02", DeviceInfo{.ip = "192.168.1.11", .port = 1883});
devices.insert({"device_03", DeviceInfo{...}});

// Lookup — O(log n)
auto it = devices.find("device_01");
if (it != devices.end()) {
    printf("Found: %s\n", it->second.ip.c_str());
}

// operator[] — inserts default value if key absent (often a bug)
DeviceInfo& d = devices["device_04"];  // creates empty DeviceInfo if not present

// Safer lookup
if (devices.count("device_01")) { ... }               // exists check
if (auto it = devices.find("device_01"); it != devices.end()) { ... }  // C++17

// Iteration is always sorted by key
for (const auto& [key, value] : devices) {   // structured binding — C++17
    printf("%s: %s\n", key.c_str(), value.ip.c_str());
}

// Erase
devices.erase("device_01");                  // by key
devices.erase(devices.find("device_02"));    // by iterator
```

### `operator[]` Trap

```cpp
// This INSERTS a default-constructed value if key doesn't exist
int count = word_count["hello"];   // inserts {"hello", 0} if absent
// word_count now has a spurious entry

// Use find() when you don't want insertion:
auto it = word_count.find("hello");
int count = (it != word_count.end()) ? it->second : 0;
```

Use `map` when: you need sorted iteration, you need ordered range queries (`lower_bound`, `upper_bound`), or your key type doesn't have a good hash function.

---

## 6. `std::unordered_map` — Hash Table

`std::unordered_map<K, V>` is a hash table. Average O(1) lookup and insertion. Keys are unordered.

```cpp
#include <unordered_map>

std::unordered_map<std::string, DeviceInfo> devices;

// Same interface as map for basic operations
devices["device_01"] = {...};
auto it = devices.find("device_01");   // O(1) average

// Performance tuning
devices.reserve(100);          // pre-allocate for 100 elements
devices.max_load_factor(0.7f); // rehash when 70% full (default is 1.0)

// Bucket inspection
printf("buckets: %zu, load: %.2f\n",
       devices.bucket_count(),
       devices.load_factor());
```

### Custom Hash for Your Types

`unordered_map` with `std::string` keys works out of the box. With custom types, you provide a hash:

```cpp
struct DeviceKey {
    uint8_t  bus_id;
    uint16_t device_address;
};

struct DeviceKeyHash {
    size_t operator()(const DeviceKey& k) const {
        // Combine hashes — FNV-style mixing
        size_t h = std::hash<uint8_t>{}(k.bus_id);
        h ^= std::hash<uint16_t>{}(k.device_address) + 0x9e3779b9 + (h << 6) + (h >> 2);
        return h;
    }
};

struct DeviceKeyEqual {
    bool operator()(const DeviceKey& a, const DeviceKey& b) const {
        return a.bus_id == b.bus_id && a.device_address == b.device_address;
    }
};

std::unordered_map<DeviceKey, DeviceInfo, DeviceKeyHash, DeviceKeyEqual> registry;
registry[{0, 0x48}] = {"TMP102 temperature sensor"};
```

**`map` vs `unordered_map` decision:**

|Factor|`map`|`unordered_map`|
|---|---|---|
|Lookup complexity|O(log n)|O(1) average|
|Sorted iteration|✓|✗|
|Memory layout|tree nodes — scattered|bucket array — more contiguous|
|Worst case|O(log n) — predictable|O(n) — hash collision|
|Key requirement|`operator<`|hash function + `operator==`|
|Embedded / real-time|acceptable|avoid if hash worst case matters|

For a device registry with string keys and no ordering requirement — `unordered_map`. For a sorted event log keyed by timestamp — `map`.

---

## 7. `std::set` and `std::unordered_set`

Like `map` and `unordered_map`, but storing only keys — no associated value. Use when you need membership testing or deduplication:

```cpp
#include <set>
#include <unordered_set>

std::unordered_set<std::string> active_topics;
active_topics.insert("sensors/temp");
active_topics.insert("sensors/humidity");
active_topics.insert("sensors/temp");   // duplicate — ignored

active_topics.count("sensors/temp");    // 1 — present
active_topics.count("sensors/other");   // 0 — absent

// Deduplicate a vector
std::vector<std::string> with_dupes = {"a", "b", "a", "c", "b"};
std::unordered_set<std::string> seen(with_dupes.begin(), with_dupes.end());
// seen = {"a", "b", "c"}
```

---

## 8. `std::stack`, `std::queue`, `std::priority_queue` — Adaptors

These aren't separate data structures — they're wrappers (adaptors) around other containers that restrict the interface:

```cpp
#include <stack>
#include <queue>
#include <priority_queue>  // actually in <queue>

// stack — LIFO, default uses deque
std::stack<int> s;
s.push(1); s.push(2); s.push(3);
s.top();    // 3 — peek without removing
s.pop();    // removes 3

// queue — FIFO, default uses deque
std::queue<SensorReading> q;
q.push({23.5f, 1000});
q.front();  // oldest element
q.back();   // newest element
q.pop();    // removes front

// priority_queue — max-heap by default
std::priority_queue<int> pq;
pq.push(3); pq.push(1); pq.push(4); pq.push(1); pq.push(5);
pq.top();   // 5 — always the max
pq.pop();   // removes 5

// Min-heap — for deadline scheduling
std::priority_queue<int, std::vector<int>, std::greater<int>> min_pq;
```

For IoT work: `std::queue` is useful for message staging, `std::priority_queue` for alarm prioritization.

---

## 9. Container Selection Guide

```
Do you know the size at compile time?
    YES → std::array<T, N> or RingBuffer<T, N>
    NO  → continue

Do you need sorted order or range queries?
    YES → std::map<K,V> or std::set<K>
    NO  → continue

Is it a key-value lookup?
    YES → std::unordered_map<K,V>  (O(1) avg)
    NO  → continue

Do you need O(1) push/pop at both ends?
    YES → std::deque<T>
    NO  → continue

Do you need O(1) insert/erase in the middle with stable iterators?
    YES → std::list<T>  (measure first — vector often wins anyway)
    NO  → std::vector<T>   ← default answer
```

---

## 10. Putting It Together — `DeviceRegistry`

A realistic IoT device registry: O(1) lookup by device ID, sorted enumeration by name, subscription tracking:

```cpp
// device_registry.cpp
#include <cstdio>
#include <cstdint>
#include <string>
#include <vector>
#include <unordered_map>
#include <map>
#include <unordered_set>
#include <optional>
#include <chrono>
#include <cassert>

// ---- Data types ----

enum class DeviceStatus { Offline, Online, Error };

struct DeviceInfo {
    std::string   device_id;
    std::string   name;
    std::string   ip_address;
    uint16_t      port;
    DeviceStatus  status;
    uint64_t      last_seen_ms;
    float         last_reading;

    void print() const {
        const char* status_str[] = {"Offline", "Online", "Error"};
        printf("  [%s] %-20s %s:%u  status=%-7s  reading=%.2f  seen=%llu\n",
               device_id.c_str(), name.c_str(),
               ip_address.c_str(), port,
               status_str[static_cast<int>(status)],
               last_reading,
               static_cast<unsigned long long>(last_seen_ms));
    }
};

// ---- DeviceRegistry ----

class DeviceRegistry {
public:
    // Register a new device — O(1) average
    bool add(DeviceInfo info) {
        const std::string id = info.device_id;
        if (by_id_.count(id)) {
            printf("  [registry] duplicate id: %s\n", id.c_str());
            return false;
        }
        by_name_.emplace(info.name, id);   // sorted index
        by_id_.emplace(id, std::move(info));
        printf("  [registry] registered: %s\n", id.c_str());
        return true;
    }

    // Remove a device — O(1) average for hash, O(log n) for tree
    bool remove(const std::string& id) {
        auto it = by_id_.find(id);
        if (it == by_id_.end()) return false;

        // Remove from sorted name index
        auto range = by_name_.equal_range(it->second.name);
        for (auto nit = range.first; nit != range.second; ++nit) {
            if (nit->second == id) {
                by_name_.erase(nit);
                break;
            }
        }

        by_id_.erase(it);
        printf("  [registry] removed: %s\n", id.c_str());
        return true;
    }

    // O(1) average lookup by ID
    DeviceInfo* find(const std::string& id) {
        auto it = by_id_.find(id);
        return it != by_id_.end() ? &it->second : nullptr;
    }

    const DeviceInfo* find(const std::string& id) const {
        auto it = by_id_.find(id);
        return it != by_id_.end() ? &it->second : nullptr;
    }

    // Update reading and timestamp — O(1)
    bool update(const std::string& id, float reading, uint64_t timestamp_ms) {
        auto* dev = find(id);
        if (!dev) return false;
        dev->last_reading = reading;
        dev->last_seen_ms = timestamp_ms;
        dev->status       = DeviceStatus::Online;
        return true;
    }

    // Mark stale devices offline — O(n)
    int mark_stale(uint64_t now_ms, uint64_t timeout_ms) {
        int marked = 0;
        for (auto& [id, info] : by_id_) {
            if (info.status == DeviceStatus::Online &&
                (now_ms - info.last_seen_ms) > timeout_ms)
            {
                info.status = DeviceStatus::Offline;
                ++marked;
            }
        }
        return marked;
    }

    // Subscribe to a topic — O(1)
    void subscribe(const std::string& device_id, const std::string& topic) {
        subscriptions_[device_id].insert(topic);
    }

    // Get topics for a device — O(1)
    const std::unordered_set<std::string>*
    get_subscriptions(const std::string& device_id) const {
        auto it = subscriptions_.find(device_id);
        return it != subscriptions_.end() ? &it->second : nullptr;
    }

    // Enumerate all devices sorted by name — O(n log n) already sorted
    void print_sorted() const {
        printf("  Devices sorted by name (%zu total):\n", by_id_.size());
        for (const auto& [name, id] : by_name_) {
            if (const auto* dev = find(id)) {
                dev->print();
            }
        }
    }

    // Enumerate online devices only
    void print_online() const {
        printf("  Online devices:\n");
        for (const auto& [id, info] : by_id_) {
            if (info.status == DeviceStatus::Online) info.print();
        }
    }

    size_t size()  const { return by_id_.size(); }
    bool   empty() const { return by_id_.empty(); }

    // Collect all devices of a given status into a vector
    std::vector<const DeviceInfo*> filter_by_status(DeviceStatus s) const {
        std::vector<const DeviceInfo*> result;
        result.reserve(by_id_.size());
        for (const auto& [id, info] : by_id_) {
            if (info.status == s) result.push_back(&info);
        }
        return result;
    }

private:
    // Primary storage — O(1) avg lookup by ID
    std::unordered_map<std::string, DeviceInfo> by_id_;

    // Sorted index — O(log n) sorted enumeration
    // multimap because multiple devices can share a name prefix
    std::multimap<std::string, std::string> by_name_;

    // Subscription tracking — per device
    std::unordered_map<std::string,
                       std::unordered_set<std::string>> subscriptions_;
};

// ---- Main ----

int main() {
    printf("=== DeviceRegistry Demo ===\n\n");

    DeviceRegistry reg;

    // Register devices
    printf("--- Registering devices ---\n");
    reg.add({"dev_001", "Warehouse Temp A",  "192.168.1.10", 1883,
             DeviceStatus::Offline, 0, 0.0f});
    reg.add({"dev_002", "Warehouse Humid B", "192.168.1.11", 1883,
             DeviceStatus::Offline, 0, 0.0f});
    reg.add({"dev_003", "Office Temp C",     "192.168.1.12", 1883,
             DeviceStatus::Offline, 0, 0.0f});
    reg.add({"dev_004", "Basement Pressure", "192.168.1.13", 1883,
             DeviceStatus::Offline, 0, 0.0f});

    // Duplicate rejection
    reg.add({"dev_001", "Duplicate", "0.0.0.0", 0,
             DeviceStatus::Offline, 0, 0.0f});

    printf("\nTotal registered: %zu\n", reg.size());

    // Subscriptions
    printf("\n--- Subscriptions ---\n");
    reg.subscribe("dev_001", "sensors/temperature");
    reg.subscribe("dev_001", "sensors/alerts");
    reg.subscribe("dev_002", "sensors/humidity");
    reg.subscribe("dev_003", "sensors/temperature");

    if (auto* subs = reg.get_subscriptions("dev_001")) {
        printf("dev_001 subscriptions:\n");
        for (const auto& topic : *subs) printf("  %s\n", topic.c_str());
    }

    // Simulate incoming readings
    printf("\n--- Incoming readings ---\n");
    reg.update("dev_001", 23.5f,  1000);
    reg.update("dev_002", 65.0f,  1001);
    reg.update("dev_003", 22.1f,  1002);
    reg.update("dev_004", 1013.f, 5);    // old timestamp — will go stale

    // Sorted enumeration
    printf("\n--- Sorted by name ---\n");
    reg.print_sorted();

    // Stale detection
    printf("\n--- Stale detection (timeout=100ms, now=2000ms) ---\n");
    int stale = reg.mark_stale(2000, 100);
    printf("Marked %d device(s) offline\n", stale);

    // Online only
    printf("\n--- Online devices ---\n");
    reg.print_online();

    // Filter
    auto offline = reg.filter_by_status(DeviceStatus::Offline);
    printf("\nOffline count: %zu\n", offline.size());

    // Lookup benchmark context
    printf("\n--- O(1) lookup verification ---\n");
    if (auto* dev = reg.find("dev_003")) {
        printf("Found dev_003: %s  reading=%.2f\n",
               dev->name.c_str(), dev->last_reading);
    }

    if (!reg.find("dev_999")) {
        printf("dev_999 not found — correct\n");
    }

    // Remove
    printf("\n--- Remove ---\n");
    reg.remove("dev_002");
    printf("After remove: %zu devices\n", reg.size());
    reg.print_sorted();

    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -fsanitize=address -o registry device_registry.cpp
./registry
```

### What to observe

Two indexes maintained in parallel — `by_id_` (hash map, O(1) lookup) and `by_name_` (sorted multimap, sorted enumeration). Neither is "the right one" in isolation — the dual-index pattern is how real registries give you both O(1) point lookup and sorted enumeration without compromise. The cost is keeping them in sync on add/remove, which is why those operations touch both.

The structured binding `for (const auto& [id, info] : by_id_)` is C++17 — it unpacks `std::pair<const std::string, DeviceInfo>` into named variables. Without it you'd write `it->first` and `it->second` everywhere.

---

## Key Takeaways for Day 12

- `std::vector` is the default — contiguous memory, cache-friendly, O(1) amortized push_back. Use it unless you have a specific reason not to
- `reserve()` before bulk insertions — prevents reallocation and iterator invalidation
- `std::unordered_map` is O(1) average for lookup/insert — right for device registries, subscription tables, any key-value store without ordering needs
- `std::map` is O(log n) but always sorted — right for sorted enumeration, range queries, ordered event logs
- `operator[]` on maps inserts a default value if the key is absent — use `find()` when you don't want that
- `std::list` is almost never faster than `vector` in practice — cache misses from pointer chasing dominate. Measure before choosing it
- The dual-index pattern (hash map + sorted map on different keys) gives O(1) lookup AND sorted enumeration — useful for any registry with multiple access patterns
- Structured bindings (`auto& [key, value]`) make map iteration clean — use them everywhere in C++17

Day 13 covers STL algorithms — the 80 functions that operate on any container through iterators, eliminating hand-written loops for filtering, sorting, transforming, and aggregating.