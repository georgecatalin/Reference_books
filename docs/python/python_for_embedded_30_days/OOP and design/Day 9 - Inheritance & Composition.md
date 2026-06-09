### Inheritance — what it's actually for

Inheritance models an "is-a" relationship. A `TemperatureSensor` IS A `Sensor`. Use it when the subclass genuinely extends or specializes the parent — not just to share code.

python

```python
class Sensor:
    def __init__(self, device_id: str, location: str) -> None:
        self.device_id = device_id
        self.location  = location

    def read(self) -> float:
        raise NotImplementedError

    def __repr__(self) -> str:
        return f"{self.__class__.__name__}({self.device_id!r})"


class TemperatureSensor(Sensor):
    def __init__(self, device_id: str, location: str, unit: str = "C") -> None:
        super().__init__(device_id, location)   # always call super().__init__
        self.unit = unit

    def read(self) -> float:
        import random
        return round(20.0 + random.uniform(-5, 5), 2)

    def read_fahrenheit(self) -> float:
        return self.read() * 1.8 + 32
```

`super()` gives you the parent class. Always call `super().__init__()` in `__init__` — skipping it leaves the parent uninitialized, which causes subtle bugs with multiple inheritance.

---

### MRO — Method Resolution Order

Python uses C3 linearization to determine which method gets called in a class hierarchy. You can inspect it:

python

```python
class A:
    def hello(self): print("A")

class B(A):
    def hello(self): print("B")

class C(A):
    def hello(self): print("C")

class D(B, C):
    pass

print(D.__mro__)   # D → B → C → A → object
D().hello()        # "B" — leftmost parent wins
```

When `super()` is called in B, it doesn't call A directly — it calls the next class in D's MRO, which is C. This is cooperative multiple inheritance. It's powerful but gets complex fast — which is exactly why experienced Python developers prefer composition over deep inheritance hierarchies.

---

### Composition — the better default

Composition models a "has-a" relationship. A `MQTTDevice` HAS A connection, HAS A buffer, HAS A parser. Each component does one thing. The device coordinates them.

python

```python
class ConnectionManager:
    def __init__(self, host: str, port: int) -> None:
        self.host = host
        self.port = port
        self._connected = False

    def connect(self) -> None:
        self._connected = True
        print(f"Connected to {self.host}:{self.port}")

    def disconnect(self) -> None:
        self._connected = False

    @property
    def is_connected(self) -> bool:
        return self._connected


class MessageParser:
    def parse(self, payload: bytes) -> dict:
        import json
        return json.loads(payload.decode("utf-8"))


class MQTTDevice:
    """Composes connection management + parsing — doesn't inherit either."""

    def __init__(self, device_id: str, host: str, port: int = 1883) -> None:
        self.device_id  = device_id
        self._conn      = ConnectionManager(host, port)   # has-a
        self._parser    = MessageParser()                  # has-a

    def connect(self) -> None:
        self._conn.connect()

    def handle_message(self, payload: bytes) -> dict:
        return self._parser.parse(payload)

    @property
    def is_connected(self) -> bool:
        return self._conn.is_connected
```

When you need to swap out the parser (real JSON vs MessagePack vs binary struct), you replace `self._parser` without touching the rest of the class. With inheritance you'd need a parallel class hierarchy.

---

### The practical rule

Use inheritance when:

- There's a genuine is-a relationship
- The subclass needs to be usable everywhere the parent is (Liskov Substitution Principle)
- You're extending behavior, not just reusing code

Use composition when:

- You want to reuse implementation
- The relationship is "uses" or "has"
- You might want to swap the component later (testing, configuration)
- The hierarchy would go deeper than 2 levels

In IoT code: `TemperatureSensor(Sensor)` is good inheritance. `MQTTClient(TCPSocket)` is bad inheritance — an MQTT client isn't a TCP socket, it uses one.

---

### Today's deliverable

Build both versions of a device abstraction and compare them directly:

python

```python
# device_models.py
from __future__ import annotations
from typing import Optional
import json, time, random

# === VERSION 1: Inheritance-based ===

class BaseDevice:
    def __init__(self, device_id: str, location: str) -> None:
        self.device_id = device_id
        self.location  = location
        self._last_seen: Optional[float] = None

    def ping(self) -> None:
        self._last_seen = time.time()

    def status(self) -> str:
        if self._last_seen is None:
            return "never seen"
        age = time.time() - self._last_seen
        return "online" if age < 60 else "stale"

    def read(self) -> float:
        raise NotImplementedError


class TempDevice(BaseDevice):
    def read(self) -> float:
        self.ping()
        return round(20 + random.uniform(-3, 3), 2)


class HumidityDevice(BaseDevice):
    def read(self) -> float:
        self.ping()
        return round(55 + random.uniform(-10, 10), 2)


# === VERSION 2: Composition-based ===

class PresenceTracker:
    def __init__(self) -> None:
        self._last_seen: Optional[float] = None

    def mark_seen(self) -> None:
        self._last_seen = time.time()

    def status(self) -> str:
        if self._last_seen is None:
            return "never seen"
        return "online" if time.time() - self._last_seen < 60 else "stale"


class ValueSimulator:
    def __init__(self, base: float, spread: float) -> None:
        self._base   = base
        self._spread = spread

    def sample(self) -> float:
        return round(self._base + random.uniform(-self._spread, self._spread), 2)


class ComposedDevice:
    def __init__(
        self,
        device_id: str,
        location:  str,
        base:      float,
        spread:    float,
    ) -> None:
        self.device_id = device_id
        self.location  = location
        self._tracker  = PresenceTracker()
        self._sensor   = ValueSimulator(base, spread)

    def read(self) -> float:
        self._tracker.mark_seen()
        return self._sensor.sample()

    def status(self) -> str:
        return self._tracker.status()


if __name__ == "__main__":
    random.seed(42)

    # Inheritance version
    print("=== Inheritance ===")
    devices_inh = [TempDevice("t01", "room1"), HumidityDevice("h01", "room1")]
    for d in devices_inh:
        print(f"{d.device_id}: {d.read()}, status={d.status()}")

    # Composition version
    print("\n=== Composition ===")
    devices_comp = [
        ComposedDevice("t01", "room1", base=20.0, spread=3.0),
        ComposedDevice("h01", "room1", base=55.0, spread=10.0),
    ]
    for d in devices_comp:
        print(f"{d.device_id}: {d.read()}, status={d.status()}")

    # Now answer: if you needed to add a MockSensor that always returns
    # a fixed value (for testing), which version is easier to extend?
    # Implement it for the composition version — add MockSimulator and
    # construct a ComposedDevice using it.
```
[[OOP and Design]]