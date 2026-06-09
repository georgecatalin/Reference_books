### Observer — the IoT pattern

MQTT is publish/subscribe. The Observer pattern is the software equivalent: publishers emit events, subscribers react. This is the backbone of any event-driven IoT system.

python

```python
from collections import defaultdict
from typing import Callable, Any

class EventBus:
    """Simple synchronous event bus — async version comes on Day 19."""

    def __init__(self) -> None:
        self._handlers: defaultdict[str, list[Callable]] = defaultdict(list)

    def subscribe(self, event: str, handler: Callable) -> None:
        self._handlers[event].append(handler)

    def unsubscribe(self, event: str, handler: Callable) -> None:
        self._handlers[event].remove(handler)

    def publish(self, event: str, **data: Any) -> None:
        for handler in self._handlers[event]:
            handler(**data)


bus = EventBus()

def on_temp(device_id: str, value: float, **_) -> None:
    print(f"TEMP: {device_id} = {value}")

def on_alarm(device_id: str, value: float, threshold: float, **_) -> None:
    print(f"ALARM: {device_id} exceeded {threshold} (got {value})")

bus.subscribe("temperature", on_temp)
bus.subscribe("temperature", lambda **kw: print(f"LOG: {kw}"))
bus.subscribe("alarm", on_alarm)

bus.publish("temperature", device_id="dev_01", value=22.4)
bus.publish("alarm", device_id="dev_01", value=85.0, threshold=80.0)
```

---

### Singleton — configuration and connection managers

Use a Singleton for objects that must exist exactly once: a config store, a connection pool, a metrics collector. The Pythonic way uses `__new__`:

python

```python
class Config:
    _instance: "Config | None" = None

    def __new__(cls) -> "Config":
        if cls._instance is None:
            cls._instance = super().__new__(cls)
            cls._instance._loaded = False
        return cls._instance

    def load(self, path: str) -> None:
        if self._loaded:
            return
        import json
        with open(path) as f:
            self._data = json.load(f)
        self._loaded = True

    def get(self, key: str, default=None):
        return self._data.get(key, default)


cfg1 = Config()
cfg2 = Config()
print(cfg1 is cfg2)   # True — same object
```

A module-level instance is often cleaner and more Pythonic than a Singleton class:

python

```python
# config.py
import json

_data: dict = {}

def load(path: str) -> None:
    global _data
    with open(path) as f:
        _data = json.load(f)

def get(key: str, default=None):
    return _data.get(key, default)
```

Import `config` from anywhere — Python caches modules, so `config._data` is shared across all importers.

---

### Factory — creating objects from config

A Factory creates objects without the caller knowing the concrete class. Essential when the type to create is determined by runtime data (a config file, an MQTT message type):

python

```python
from typing import Protocol

class SensorDriver(Protocol):
    def read(self) -> float: ...
    def device_id(self) -> str: ...


class TempDriver:
    def __init__(self, did: str) -> None: self._id = did
    def read(self) -> float: return 22.4
    def device_id(self) -> str: return self._id

class HumidityDriver:
    def __init__(self, did: str) -> None: self._id = did
    def read(self) -> float: return 65.0
    def device_id(self) -> str: return self._id


# Registry-based factory — extensible without editing the factory
_REGISTRY: dict[str, type] = {
    "temperature": TempDriver,
    "humidity":    HumidityDriver,
}

def create_driver(sensor_type: str, device_id: str) -> SensorDriver:
    cls = _REGISTRY.get(sensor_type)
    if cls is None:
        raise ValueError(f"Unknown sensor type: {sensor_type!r}")
    return cls(device_id)


# Adding a new type requires zero changes to the factory
_REGISTRY["pressure"] = PressureDriver

driver = create_driver("temperature", "dev_01")
print(driver.read())
```

---

### Today's deliverable — putting it all together

python

```python
# iot_patterns.py
from __future__ import annotations
from collections import defaultdict
from typing import Callable, Any, Protocol
import time, random

# === Observer: typed event bus ===

class DeviceEvent:
    __slots__ = ("device_id", "variable", "value", "ts")
    def __init__(self, device_id: str, variable: str, value: float) -> None:
        self.device_id = device_id
        self.variable  = variable
        self.value     = value
        self.ts        = time.time()

EventHandler = Callable[[DeviceEvent], None]

class TypedEventBus:
    def __init__(self) -> None:
        self._handlers: defaultdict[str, list[EventHandler]] = defaultdict(list)

    def on(self, variable: str) -> Callable[[EventHandler], EventHandler]:
        """Decorator: @bus.on('temperature')"""
        def decorator(func: EventHandler) -> EventHandler:
            self._handlers[variable].append(func)
            return func
        return decorator

    def emit(self, event: DeviceEvent) -> None:
        for handler in self._handlers.get(event.variable, []):
            handler(event)
        for handler in self._handlers.get("*", []):
            handler(event)


# === Factory: registry-based driver creation ===

class SensorDriver(Protocol):
    def read(self) -> float: ...
    def device_id(self) -> str: ...

_DRIVER_REGISTRY: dict[str, type] = {}

def register_driver(name: str):
    """Class decorator for self-registering drivers."""
    def decorator(cls):
        _DRIVER_REGISTRY[name] = cls
        return cls
    return decorator

def create_driver(sensor_type: str, device_id: str, **kwargs) -> SensorDriver:
    cls = _DRIVER_REGISTRY.get(sensor_type)
    if not cls:
        raise ValueError(f"No driver registered for {sensor_type!r}")
    return cls(device_id, **kwargs)

@register_driver("temperature")
class TempDriver:
    def __init__(self, did: str, base: float = 20.0) -> None:
        self._id, self._base = did, base
    def read(self) -> float:
        return round(self._base + random.gauss(0, 1.5), 2)
    def device_id(self) -> str:
        return self._id

@register_driver("humidity")
class HumidityDriver:
    def __init__(self, did: str, base: float = 55.0) -> None:
        self._id, self._base = did, base
    def read(self) -> float:
        return round(self._base + random.gauss(0, 5), 2)
    def device_id(self) -> str:
        return self._id


# === Wire them together ===

if __name__ == "__main__":
    random.seed(42)

    bus = TypedEventBus()

    @bus.on("temperature")
    def log_temp(e: DeviceEvent) -> None:
        print(f"  TEMP  {e.device_id}: {e.value}")

    @bus.on("temperature")
    def alarm_temp(e: DeviceEvent) -> None:
        if e.value > 25.0:
            print(f"  ALARM {e.device_id}: {e.value} exceeds threshold")

    @bus.on("*")
    def audit_all(e: DeviceEvent) -> None:
        pass   # would write to audit log in production

    # Create drivers from config (simulating what a real config file would drive)
    device_configs = [
        {"type": "temperature", "id": "dev_01", "base": 22.0},
        {"type": "humidity",    "id": "dev_02", "base": 60.0},
        {"type": "temperature", "id": "dev_03", "base": 26.0},
    ]

    drivers = [create_driver(c["type"], c["id"], base=c["base"]) for c in device_configs]

    # Simulate 3 read cycles
    for cycle in range(3):
        print(f"\n--- Cycle {cycle + 1} ---")
        for driver in drivers:
            value = driver.read()
            event = DeviceEvent(driver.device_id(), "temperature"
                                if isinstance(driver, TempDriver) else "humidity", value)
            bus.emit(event)
```

Extend `TypedEventBus` with an `unsubscribe` method and a `once` method that calls the handler exactly once then removes it automatically.

---

[[OOP and Design]]