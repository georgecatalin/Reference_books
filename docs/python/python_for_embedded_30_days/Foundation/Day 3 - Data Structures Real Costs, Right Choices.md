I'll let it go, but this is the last time. The deliverable reviews aren't busywork — they're how I know whether to adjust the pace or revisit something. From Day 3 onward, no review = we stay on that day until you answer.

For the record: mypy reported `Argument 2 to "bad_publish" has incompatible type "str"; expected "bytes"`. The fix is `b"22.4"` — the `b` prefix makes it a bytes literal. That distinction matters every time you touch serial or MQTT payloads.

---

# Day 3 — Data Structures: Real Costs, Right Choices

You know these structures exist. Today is about knowing _when_ each one is wrong — because in a high-rate MQTT ingester or a device state tracker, the wrong structure isn't just slow, it's a correctness problem.

---

## The four structures you'll use constantly

### `list` — ordered, mutable, O(1) append/index, O(n) insert/search

```python
readings = []
readings.append(22.4)        # O(1) — fast, add to end
readings.insert(0, 21.9)     # O(n) — slow, shifts every element
readings[0]                  # O(1) — index access
22.4 in readings             # O(n) — linear scan
```

The trap: using a list as a queue. `list.pop(0)` is O(n) — it shifts every remaining element left. At 1000 messages/sec this becomes measurable latency.

### `deque` — double-ended queue, O(1) on both ends

```python
from collections import deque

# Fixed-size ring buffer — exactly what you need for sensor history
buffer = deque(maxlen=100)   # automatically evicts oldest when full

buffer.append(22.4)          # O(1) right append
buffer.appendleft(21.9)      # O(1) left append
buffer.popleft()             # O(1) — correct way to consume a queue
```

`deque` with `maxlen` is a ring buffer. In C you'd implement this yourself with a pointer and modulo arithmetic. In Python it's one line. Use it for: message queues, sliding windows of sensor readings, command history.

### `dict` — hash map, O(1) average for get/set/delete

```python
device_state = {}
device_state["sensor_01"] = {"temp": 22.4, "humidity": 65}  # O(1)
device_state["sensor_01"]                                     # O(1)
"sensor_01" in device_state                                   # O(1) — not O(n)!
del device_state["sensor_01"]                                 # O(1)
```

Since Python 3.7, dicts preserve insertion order. That's a guarantee, not an implementation detail. Use it for: device registries, topic-to-handler maps, config stores.

A dict variant worth knowing — `defaultdict` eliminates the "key might not exist" boilerplate:

```python
from collections import defaultdict

# Without defaultdict
if "sensor_01" not in readings_by_device:
    readings_by_device["sensor_01"] = []
readings_by_device["sensor_01"].append(22.4)

# With defaultdict
readings_by_device = defaultdict(list)
readings_by_device["sensor_01"].append(22.4)  # key auto-created on first access
```

### `set` — hash set, O(1) membership, O(n) iteration, unordered

```python
seen_devices = set()
seen_devices.add("sensor_01")        # O(1)
"sensor_01" in seen_devices          # O(1) — this is the whole point
seen_devices.discard("sensor_99")    # O(1), no error if missing

# Set operations — genuinely useful for device management
active    = {"dev_01", "dev_02", "dev_03"}
reporting = {"dev_01", "dev_03"}

offline = active - reporting          # {"dev_02"} — difference
both    = active & reporting          # {"dev_01", "dev_03"} — intersection
all_    = active | reporting          # union
```

Use sets for: deduplication, tracking which devices have checked in, filtering topic subscriptions.

---

## The complexity table you need in your head

|Operation|list|deque|dict|set|
|---|---|---|---|---|
|Append to end|O(1)|O(1)|—|—|
|Prepend / appendleft|O(n)|O(1)|—|—|
|Index access `[i]`|O(1)|O(n)|O(1) key|—|
|Search / `in`|O(n)|O(n)|O(1)|O(1)|
|Insert middle|O(n)|O(n)|—|—|
|Delete|O(n)|O(n)|O(1)|O(1)|

The one that surprises people: `x in some_list` is O(n). If you're checking membership at message-receive frequency, that list needs to be a set or dict.

---

## `collections` — the structures the standard library already built for you

```python
from collections import Counter, OrderedDict, namedtuple

# Counter — frequency map, built for counting
topic_hits = Counter()
topic_hits["devices/sensor_01/temp"] += 1
topic_hits["devices/sensor_01/temp"] += 1
topic_hits["devices/sensor_02/temp"] += 1
print(topic_hits.most_common(2))   # [('devices/sensor_01/temp', 2), ...]

# namedtuple — lightweight immutable record, no class needed
# Fields are accessible by name AND by index, like a C struct
Point = namedtuple("Point", ["x", "y"])
p = Point(1.0, 2.0)
print(p.x, p[0])    # both work

# For IoT: a lightweight reading record before you need a full class
RawReading = namedtuple("RawReading", ["device_id", "topic", "payload", "ts"])
msg = RawReading("dev_01", "devices/dev_01/temp", b"22.4", 1700000000.0)
print(msg.device_id)
```

---

## The ring buffer pattern — your deliverable today

This is directly applicable to your ingester. You need a fixed-size rolling window of recent readings per device — keep the last N, automatically discard old ones, O(1) on both ends.

```python
# telemetry_buffer.py
from collections import deque, defaultdict
from typing import Optional
import time

MAX_READINGS = 50   # keep last 50 readings per device

class TelemetryBuffer:
    """
    Fixed-size rolling buffer of sensor readings per device.
    Thread-safe reads are NOT guaranteed here — Day 16 adds that.
    """

    def __init__(self, maxlen: int = MAX_READINGS) -> None:
        self._maxlen = maxlen
        # defaultdict of deques — auto-creates buffer on first device seen
        self._buffers: defaultdict[str, deque[tuple[float, float]]] = \
            defaultdict(lambda: deque(maxlen=self._maxlen))
        self._seen: set[str] = set()

    def push(self, device_id: str, value: float, ts: Optional[float] = None) -> None:
        ts = ts or time.time()
        self._buffers[device_id].append((ts, value))
        self._seen.add(device_id)

    def latest(self, device_id: str) -> Optional[tuple[float, float]]:
        buf = self._buffers.get(device_id)
        if not buf:
            return None
        return buf[-1]   # rightmost = most recent

    def window(self, device_id: str, n: int) -> list[tuple[float, float]]:
        """Return last n readings as a list, oldest first."""
        buf = self._buffers.get(device_id)
        if not buf:
            return []
        return list(buf)[-n:]

    def average(self, device_id: str) -> Optional[float]:
        buf = self._buffers.get(device_id)
        if not buf:
            return None
        values = [v for _, v in buf]
        return sum(values) / len(values)

    def active_devices(self) -> set[str]:
        return set(self._seen)

    def offline_devices(self, expected: set[str]) -> set[str]:
        """Return devices we expect but haven't heard from."""
        return expected - self._seen


if __name__ == "__main__":
    buf = TelemetryBuffer(maxlen=5)

    # Simulate 7 readings — buffer should only keep last 5
    for i, val in enumerate([20.1, 20.3, 20.5, 20.8, 21.0, 21.3, 21.6]):
        buf.push("sensor_01", val, ts=float(1700000000 + i))

    print("Latest:", buf.latest("sensor_01"))
    print("Window(3):", buf.window("sensor_01", 3))
    print("Average:", round(buf.average("sensor_01"), 2))

    buf.push("sensor_02", 55.0)
    buf.push("sensor_03", 12.0)

    print("Active:", buf.active_devices())

    expected = {"sensor_01", "sensor_02", "sensor_03", "sensor_04", "sensor_05"}
    print("Offline:", buf.offline_devices(expected))
```

Run this and verify:

- The buffer for `sensor_01` contains exactly 5 readings (not 7)
- `window(3)` returns the 3 most recent
- `offline_devices` correctly identifies `sensor_04` and `sensor_05`

Then extend it yourself: add a `min_max(device_id: str) -> Optional[tuple[float, float]]` method that returns the `(min, max)` of all values in the buffer. One line using built-ins — no loop needed.

