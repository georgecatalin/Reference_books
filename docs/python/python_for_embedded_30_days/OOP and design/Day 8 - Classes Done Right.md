You've written classes before. Today is about writing them the way Python actually expects — using the dunder (double-underscore) protocol that makes your objects behave like built-in types. This is the difference between a class that works and a class that fits naturally into the language.

---

### `__init__` is not a constructor

In C++ the constructor allocates and initializes. In Python, `__new__` allocates (you almost never touch it) and `__init__` initializes an already-created object. `__init__` never returns a value — if you write `return something` in it, Python raises a `TypeError`.

python

```python
class SensorReading:
    def __init__(self, device_id: str, value: float, ts: float) -> None:
        self.device_id = device_id
        self.value     = value
        self.ts        = ts
```

That's the baseline. Now let's make it behave properly.

---

### `__repr__` and `__str__` — object representation

Every class you write should have `__repr__`. It's the unambiguous developer representation — what you see in the REPL, in logs, in debuggers.

python

```python
def __repr__(self) -> str:
    return f"SensorReading(device_id={self.device_id!r}, value={self.value}, ts={self.ts})"
```

The `!r` applies `repr()` to the value inside the f-string — strings get quotes, None stays as `None`. The goal: `eval(repr(obj))` should ideally recreate the object.

`__str__` is the human-readable version — used by `print()` and `str()`. If you don't define it, Python falls back to `__repr__`. Only define `__str__` when you want a genuinely different human-facing format:

python

```python
def __str__(self) -> str:
    return f"{self.device_id}: {self.value} @ {self.ts}"
```

---

### `__eq__` and `__hash__` — equality and collections

By default, `==` on your objects compares identity (same as `is`). Define `__eq__` to compare by value:

python

```python
def __eq__(self, other: object) -> bool:
    if not isinstance(other, SensorReading):
        return NotImplemented    # not False — lets Python try the other side
    return (self.device_id == other.device_id and
            self.value     == other.value and
            self.ts        == other.ts)
```

Return `NotImplemented` (not `False`) when the types don't match — it tells Python to try the reflected operation on the other object. Returning `False` silently breaks comparisons with other types.

Critical rule: **if you define `__eq__`, you must define `__hash__`**. Python automatically sets `__hash__ = None` when you define `__eq__`, making your objects unhashable (can't be put in sets or used as dict keys). Fix it:

python

```python
def __hash__(self) -> int:
    return hash((self.device_id, self.value, self.ts))
```

Hash only immutable fields. If `value` can change after creation, don't include it in the hash — objects must hash consistently for their lifetime in a collection.

---

### `__lt__`, `__le__` etc. — ordering

Define these to make your objects sortable. But there's a shortcut — `functools.total_ordering` fills in the missing comparison methods from just `__eq__` and one of the ordering methods:

python

```python
from functools import total_ordering

@total_ordering
class SensorReading:
    # ... __init__, __repr__, __eq__, __hash__ ...

    def __lt__(self, other: object) -> bool:
        if not isinstance(other, SensorReading):
            return NotImplemented
        return self.ts < other.ts    # sort by timestamp
```

Now `sorted(readings)`, `min(readings)`, `max(readings)` all work.

---

### `__len__`, `__contains__`, `__iter__` — container protocol

Make your class behave like a collection:

python

```python
class DeviceBuffer:
    def __init__(self) -> None:
        self._readings: list[SensorReading] = []

    def push(self, r: SensorReading) -> None:
        self._readings.append(r)

    def __len__(self) -> int:
        return len(self._readings)

    def __contains__(self, item: object) -> bool:
        return item in self._readings

    def __iter__(self):
        return iter(self._readings)

    def __getitem__(self, index: int) -> SensorReading:
        return self._readings[index]
```

Now `len(buf)`, `reading in buf`, `for r in buf`, and `buf[0]` all work naturally — your object behaves like a built-in sequence.

---

### `__enter__` and `__exit__` — context manager protocol

You wrote these on Day 5. They belong here because they're dunders like everything else:

python

```python
class MQTTConnection:
    def __enter__(self) -> "MQTTConnection":
        self.connect()
        return self

    def __exit__(self, exc_type, exc_val, exc_tb) -> bool:
        self.disconnect()
        return False
```

---

### Properties — computed attributes with access control

`@property` turns a method into an attribute. No parentheses on access, validation on set:

python

```python
class DeviceConfig:
    def __init__(self, port: int) -> None:
        self._port = port   # private by convention — single underscore

    @property
    def port(self) -> int:
        return self._port

    @port.setter
    def port(self, value: int) -> None:
        if not (1 <= value <= 65535):
            raise ValueError(f"Invalid port: {value}")
        self._port = value

    @port.deleter
    def port(self) -> None:
        raise AttributeError("Cannot delete port")
```

python

```python
cfg = DeviceConfig(1883)
print(cfg.port)       # 1883 — looks like attribute access, runs the getter
cfg.port = 9999       # runs the setter, validates
cfg.port = 99999      # raises ValueError
```

Use properties when: an attribute needs validation on write, a value is computed from other attributes, or you need to add validation to an existing attribute without breaking callers.

---

### `__slots__` — memory optimization for high-frequency objects

By default, every Python object has a `__dict__` — a hash map storing all its attributes. For objects created thousands of times per second (sensor readings, parsed MQTT messages), this is significant overhead.

`__slots__` replaces `__dict__` with a fixed C-level array:

python

```python
class SensorReading:
    __slots__ = ("device_id", "variable", "value", "ts")

    def __init__(self, device_id: str, variable: str, value: float, ts: float) -> None:
        self.device_id = device_id
        self.variable  = variable
        self.value     = value
        self.ts        = ts
```

Consequences:

- Memory per instance drops by ~40–50% (no `__dict__`)
- Attribute access is faster (fixed offset vs hash lookup)
- You cannot add arbitrary attributes after creation
- Subclasses need their own `__slots__` or they get `__dict__` back

In an MQTT ingester receiving 1000 messages/second, each parsed into a `SensorReading` object, `__slots__` is the right default.

---

### Putting it all together

python

```python
# models/reading.py
from __future__ import annotations
from functools import total_ordering
from typing import Optional
import time

@total_ordering
class SensorReading:
    __slots__ = ("device_id", "variable", "value", "ts")

    def __init__(
        self,
        device_id: str,
        variable:  str,
        value:     float,
        ts:        Optional[float] = None,
    ) -> None:
        self.device_id = device_id
        self.variable  = variable
        self.value     = value
        self.ts        = ts if ts is not None else time.time()

    # --- Representation ---
    def __repr__(self) -> str:
        return (f"SensorReading("
                f"device_id={self.device_id!r}, "
                f"variable={self.variable!r}, "
                f"value={self.value}, "
                f"ts={self.ts})")

    def __str__(self) -> str:
        return f"[{self.device_id}] {self.variable}={self.value}"

    # --- Equality ---
    def __eq__(self, other: object) -> bool:
        if not isinstance(other, SensorReading):
            return NotImplemented
        return (self.device_id == other.device_id and
                self.variable  == other.variable  and
                self.value     == other.value      and
                self.ts        == other.ts)

    def __hash__(self) -> int:
        return hash((self.device_id, self.variable, self.ts))

    # --- Ordering (by timestamp) ---
    def __lt__(self, other: object) -> bool:
        if not isinstance(other, SensorReading):
            return NotImplemented
        return self.ts < other.ts

    # --- Domain logic ---
    def is_alarm(self, low: float, high: float) -> bool:
        return not (low <= self.value <= high)

    @classmethod
    def from_dict(cls, data: dict) -> SensorReading:
        """Factory: build from a parsed MQTT JSON payload dict."""
        return cls(
            device_id=data["device_id"],
            variable=data["variable"],
            value=float(data["value"]),
            ts=float(data.get("ts", time.time())),
        )
```

---

### Today's deliverable

Extend the `SensorReading` class above with these additions, then write a test script that exercises every dunder:

1. Add a `DeviceBuffer` class that wraps a list of `SensorReading` objects and implements `__len__`, `__iter__`, `__contains__`, and `__getitem__`. Add a `push` method and a `latest` property that returns the most recent reading by timestamp.

2. Add a `@classmethod from_bytes(cls, device_id: str, topic: str, payload: bytes)` to `SensorReading` that parses a raw MQTT payload (e.g. `b"22.4"`) and extracts the variable name from the last segment of the topic string.

3. Write a script that:
    - Creates 5 `SensorReading` objects with different timestamps
    - Puts them in a `DeviceBuffer`
    - Verifies `len(buf) == 5`
    - Verifies `sorted(buf)` returns them in timestamp order
    - Verifies two readings with identical fields compare as equal (`==`) but are different objects (`is not`)
    - Verifies readings can be stored in a `set` (requires working `__hash__`)
    - Calls `SensorReading.from_bytes("dev_01", "devices/dev_01/temperature", b"22.4")` and prints the result

python

```python
# test_reading.py
from models.reading import SensorReading, DeviceBuffer

def test_all():
    r1 = SensorReading("dev_01", "temp", 22.4, ts=1000.0)
    r2 = SensorReading("dev_01", "temp", 23.1, ts=1001.0)
    r3 = SensorReading("dev_02", "humidity", 65.0, ts=999.0)
    r4 = SensorReading("dev_01", "temp", 22.4, ts=1000.0)   # identical to r1
    r5 = SensorReading.from_bytes("dev_01", "devices/dev_01/pressure", b"101.3")

    buf = DeviceBuffer()
    for r in [r2, r1, r3]:
        buf.push(r)

    print("len:", len(buf))                          # 3
    print("sorted:", sorted(buf))                    # r3, r1, r2 by ts
    print("r1 in buf:", r1 in buf)                   # True
    print("r1 == r4:", r1 == r4)                     # True
    print("r1 is r4:", r1 is r4)                     # False
    print("set works:", len({r1, r2, r3, r4}))       # 3 (r1 and r4 are equal)
    print("latest:", buf.latest)                      # r2 (ts=1001)
    print("from_bytes:", r5)                          # variable=pressure, value=101.3

if __name__ == "__main__":
    test_all()
```

[[OOP and Design]]