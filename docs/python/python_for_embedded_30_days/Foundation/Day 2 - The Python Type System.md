I'll let the question slide this once, but going forward I'll hold you to the deliverable review — it's where the real learning happens.

The answer: **deep copy, or better yet, treat payloads as immutable by convention.** Shallow copy is the worst choice — looks safe, silently corrupts nested data. In your `mqtt_ingester.php` you parse JSON once and route it. The Python equivalent should do the same: parse once into a frozen/validated structure (Day 10 covers Pydantic for exactly this), pass it read-only. But that's ahead.

---

# Day 2 — The Python Type System

You already know static typing from C. Python is dynamically typed — but that doesn't mean weakly typed, and it doesn't mean type-ignorant. Understanding exactly how Python's type system works will make your code faster to write, easier to debug, and safe to refactor.

---

## Dynamic typing — what it actually means

In C, type is a property of the _variable_. In Python, type is a property of the _object_.

```python
x = 42          # x points to an int object
x = "hello"     # x now points to a str object — perfectly legal
x = [1, 2, 3]   # now a list — the name x has no type, the objects do
```

The variable `x` has no type. The objects it points to always do. You can check at runtime:

```python
print(type(x))          # <class 'list'>
print(isinstance(x, list))   # True
print(isinstance(x, (list, tuple)))  # True — checks either
```

`isinstance` is what you use in real code. `type(x) == list` is an anti-pattern — it breaks with subclasses.

---

## Duck typing — the operating principle

Python doesn't care what _type_ an object is. It cares what _methods and attributes_ it has. If it walks like a duck and quacks like a duck, it's a duck.

```python
def read_data(source):
    return source.read()   # works on File, Socket, BytesIO, serial.Serial
                           # anything with a .read() method — no isinstance needed
```

This is why Python code is so composable. Your MQTT client, a file, a mock object in a test — they're all interchangeable as long as they have the right interface. Day 11 covers how to formalize this with Protocols.

---

## Type hints — write them from day one

Type hints don't change runtime behavior. They are documentation that tools can check. In a team or a project you'll maintain for years, they're non-negotiable.

```python
def parse_payload(raw: bytes) -> dict[str, float]:
    ...

def connect(host: str, port: int = 1883) -> bool:
    ...
```

The syntax uses the colon for parameters and `->` for return type — exactly like a function signature comment, but machine-readable.

### The common types you'll use daily

```python
from typing import Optional, Union, Any
from collections.abc import Callable, Sequence, Mapping

# Primitives
x: int = 5
name: str = "sensor_01"
temp: float = 22.4
flag: bool = True
raw: bytes = b"\x00\xFF"

# Collections (Python 3.9+: use built-in generics directly)
readings: list[float] = [22.1, 22.3]
config: dict[str, str] = {"host": "localhost"}
ids: set[int] = {1, 2, 3}
pair: tuple[str, int] = ("dev_01", 42)

# Optional — value OR None (very common for "not yet connected" state)
connection: Optional[str] = None      # same as str | None (Python 3.10+)

# Union — one of several types
payload: Union[dict, bytes] = b"\x00" # same as dict | bytes (3.10+)

# Callable — a function as a value (callbacks, handlers)
on_message: Callable[[str, bytes], None]   # takes topic+payload, returns nothing

# Any — escape hatch, use sparingly
raw_config: Any = load_json()
```

### Annotating your own classes

```python
class SensorReading:
    device_id: str
    temperature: float
    timestamp: float
    
    def __init__(self, device_id: str, temperature: float, timestamp: float) -> None:
        self.device_id = device_id
        self.temperature = temperature
        self.timestamp = timestamp
    
    def is_alarm(self, threshold: float) -> bool:
        return self.temperature > threshold
```

---

## mypy — static analysis that catches real bugs

Install it: `pip install mypy`

Run it: `mypy your_script.py`

Here's the kind of bug it catches before you ever run the code:

```python
def publish(topic: str, payload: bytes) -> None:
    ...

publish("sensors/temp", "22.4")   # mypy error: Argument 2 has type "str", expected "bytes"
```

That exact bug — passing a string where bytes is expected — is a runtime crash in paho-mqtt that only shows up when a message is actually sent. mypy catches it at analysis time.

### A realistic config for embedded/IoT work

Create `mypy.ini` in your project root:

```ini
[mypy]
python_version = 3.11
strict = False
warn_return_any = True
warn_unused_ignores = True
disallow_untyped_defs = True
```

`strict = True` is too aggressive for a codebase you're annotating incrementally. This config is practical — it requires function signatures to be annotated but doesn't demand every variable.

---

## Special cases worth knowing

### `None` is a type

```python
def disconnect() -> None:    # explicitly returns nothing
    self._client.disconnect()

result: Optional[float] = get_reading()  # might return float or None
if result is not None:
    process(result)          # mypy now knows result is float here
```

### Type narrowing — Python and mypy track it

```python
def handle(value: str | bytes) -> str:
    if isinstance(value, bytes):
        return value.decode("utf-8")   # mypy knows: value is bytes here
    return value                        # mypy knows: value is str here
```

This pattern is everywhere in protocol parsing — you receive something ambiguous and narrow it down.

### `Final` — constants

```python
from typing import Final

MAX_RETRIES: Final = 5       # mypy will error if anything tries to reassign this
BROKER_PORT: Final[int] = 1883
```

Equivalent to `const` in C. Use it for hardware constants, pin numbers, topic prefixes.

---

## Today's deliverable

Build this file, run it, then run `mypy` on it and fix every error it reports:

```python
# mqtt_types.py
from typing import Optional, Callable, Final
from collections.abc import Sequence

# --- Constants ---
DEFAULT_PORT: Final[int] = 1883
DEFAULT_QOS:  Final[int] = 1

# --- Data structures ---
class DeviceConfig:
    def __init__(
        self,
        device_id:  str,
        host:       str,
        port:       int = DEFAULT_PORT,
        topics:     Optional[list[str]] = None,
    ) -> None:
        self.device_id = device_id
        self.host      = host
        self.port      = port
        self.topics    = topics or []

    def topic_count(self) -> int:
        return len(self.topics)


class SensorReading:
    def __init__(
        self,
        device_id:   str,
        variable:    str,
        value:       float,
        timestamp:   float,
        raw_payload: bytes,
    ) -> None:
        self.device_id   = device_id
        self.variable    = variable
        self.value       = value
        self.timestamp   = timestamp
        self.raw_payload = raw_payload

    def __repr__(self) -> str:
        return f"SensorReading({self.device_id!r}, {self.variable}={self.value})"

    def is_in_range(self, low: float, high: float) -> bool:
        return low <= self.value <= high


# --- Callback type alias ---
MessageHandler = Callable[[str, bytes], None]


# --- Functions ---
def make_topic(device_id: str, variable: str) -> str:
    return f"devices/{device_id}/{variable}"


def parse_reading(
    device_id: str,
    topic:     str,
    payload:   bytes,
    timestamp: float,
) -> Optional[SensorReading]:
    """Return a SensorReading or None if payload can't be parsed."""
    try:
        variable = topic.split("/")[-1]
        value    = float(payload.decode("utf-8"))
        return SensorReading(device_id, variable, value, timestamp, payload)
    except (ValueError, UnicodeDecodeError):
        return None


def process_batch(
    readings:  Sequence[SensorReading],
    handler:   MessageHandler,
) -> int:
    """Pass each reading's raw payload back through a handler. Returns count processed."""
    count = 0
    for r in readings:
        topic = make_topic(r.device_id, r.variable)
        handler(topic, r.raw_payload)
        count += 1
    return count


# --- Introduce a deliberate type error for mypy to catch ---
def bad_publish(topic: str, payload: bytes) -> None:
    print(f"Publishing to {topic}: {payload}")

# TODO: fix this line after mypy catches it
bad_publish("devices/sensor_01/temp", "22.4")   # wrong type — should be bytes


if __name__ == "__main__":
    cfg = DeviceConfig("dev_01", "localhost", topics=["temp", "humidity"])
    print(cfg.topic_count())

    reading = parse_reading("dev_01", "devices/dev_01/temp", b"22.4", 1700000000.0)
    print(reading)
    print(reading.is_in_range(0.0, 50.0) if reading else "parse failed")
```

Three things to do with this file:

1. Run it — verify the output is correct
2. Run `mypy mqtt_types.py` — find and fix the deliberate error on the last call to `bad_publish`
3. Add one more function yourself: `filter_readings(readings: list[SensorReading], variable: str) -> list[SensorReading]` — returns only readings matching the given variable name. Annotate it fully and verify mypy is happy with it.

[[Foundation]]