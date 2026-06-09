### The problem with `isinstance` checks everywhere

python

```python
def process(device):
    if isinstance(device, MQTTDevice):
        device.publish(...)
    elif isinstance(device, SerialDevice):
        device.write(...)
```

This is closed to extension — every new device type requires editing `process`. The fix is an interface: define what methods something must have, then trust any object that has them.

---

### Abstract Base Classes — explicit contracts

`ABC` defines a contract. Any subclass that doesn't implement all `@abstractmethod` methods cannot be instantiated:

python

```python
from abc import ABC, abstractmethod

class DeviceDriver(ABC):

    @abstractmethod
    def connect(self) -> bool:
        """Establish connection. Return True on success."""
        ...

    @abstractmethod
    def disconnect(self) -> None:
        ...

    @abstractmethod
    def send(self, payload: bytes) -> None:
        ...

    @abstractmethod
    def receive(self, timeout: float) -> bytes:
        ...

    # Concrete method — shared implementation all subclasses inherit
    def ping(self) -> bool:
        try:
            self.send(b"\x00")
            return True
        except OSError:
            return False
```

python

```python
class MQTTDriver(DeviceDriver):
    def connect(self) -> bool: ...
    def disconnect(self) -> None: ...
    def send(self, payload: bytes) -> None: ...
    def receive(self, timeout: float) -> bytes: ...

class IncompleteDriver(DeviceDriver):
    def connect(self) -> bool: ...
    # Missing disconnect, send, receive

driver = IncompleteDriver()   # TypeError: Can't instantiate abstract class
```

---

### Protocols — structural subtyping

`Protocol` is more Pythonic. A class satisfies a Protocol if it has the right methods — no inheritance required. This is duck typing made explicit and checkable:

python

```python
from typing import Protocol, runtime_checkable

@runtime_checkable
class Readable(Protocol):
    def read(self, n: int) -> bytes: ...

@runtime_checkable
class Writable(Protocol):
    def write(self, data: bytes) -> int: ...

class ReadWritable(Readable, Writable, Protocol):
    """Composable protocol — anything with both read and write."""
    ...
```

Now any class with a `read` method satisfies `Readable` — no inheritance needed:

python

```python
import serial
import io

def read_frame(source: Readable, size: int) -> bytes:
    return source.read(size)

# All of these work — none inherit from Readable
read_frame(serial.Serial("/dev/ttyUSB0"), 64)   # real serial port
read_frame(io.BytesIO(b"\x00\xFF"), 2)           # in-memory buffer
read_frame(open("data.bin", "rb"), 128)          # file
```

mypy checks that every object passed to `read_frame` actually has a `read` method with the right signature — at analysis time, not runtime.

---

### ABC vs Protocol — the decision

||ABC|Protocol|
|---|---|---|
|Enforcement|Runtime (instantiation fails)|Static (mypy)|
|Requires inheritance|Yes|No|
|Shared implementation|Yes (`@abstractmethod` + concrete methods)|No|
|Best for|Framework base classes, plugin systems|Function parameters, duck-typed interfaces|

In IoT driver code: use `ABC` when you're building a framework where all drivers must inherit your base. Use `Protocol` when you're writing functions that accept anything with the right interface.

---

### Today's deliverable

python

```python
# interfaces.py
from abc import ABC, abstractmethod
from typing import Protocol, runtime_checkable, Optional
import time, random

# --- Protocol: anything that can produce a float reading ---
@runtime_checkable
class Sampler(Protocol):
    def sample(self) -> float: ...
    def device_id(self) -> str: ...


# --- ABC: full driver contract with shared behavior ---
class SensorDriver(ABC):

    def __init__(self, device_id: str) -> None:
        self._device_id   = device_id
        self._connected   = False
        self._read_count  = 0

    @abstractmethod
    def connect(self) -> bool: ...

    @abstractmethod
    def disconnect(self) -> None: ...

    @abstractmethod
    def _read_raw(self) -> bytes: ...

    # Concrete shared behavior
    def read(self) -> Optional[float]:
        if not self._connected:
            raise RuntimeError(f"{self._device_id} not connected")
        try:
            raw = self._read_raw()
            value = float(raw.decode("utf-8").strip())
            self._read_count += 1
            return value
        except (ValueError, UnicodeDecodeError):
            return None

    def sample(self) -> float:
        """Satisfies the Sampler Protocol."""
        result = self.read()
        if result is None:
            raise IOError("Read failed")
        return result

    def device_id(self) -> str:
        return self._device_id

    @property
    def read_count(self) -> int:
        return self._read_count


# --- Concrete implementations ---
class SimulatedTempDriver(SensorDriver):
    def connect(self) -> bool:
        self._connected = True
        return True

    def disconnect(self) -> None:
        self._connected = False

    def _read_raw(self) -> bytes:
        value = round(20 + random.gauss(0, 2), 2)
        return str(value).encode()


class FixedDriver(SensorDriver):
    """Always returns the same value — for testing."""
    def __init__(self, device_id: str, fixed_value: float) -> None:
        super().__init__(device_id)
        self._value = fixed_value

    def connect(self) -> bool:
        self._connected = True
        return True

    def disconnect(self) -> None:
        self._connected = False

    def _read_raw(self) -> bytes:
        return str(self._value).encode()


# --- Function using Protocol (not ABC) ---
def collect_n(source: Sampler, n: int) -> list[float]:
    """Works with anything satisfying Sampler — no inheritance required."""
    return [source.sample() for _ in range(n)]


if __name__ == "__main__":
    random.seed(42)

    drivers: list[SensorDriver] = [
        SimulatedTempDriver("sensor_01"),
        FixedDriver("sensor_02", 22.5),
    ]

    for d in drivers:
        d.connect()
        readings = collect_n(d, 5)   # passes Protocol check
        print(f"{d.device_id()}: {readings}, reads={d.read_count}")
        d.disconnect()

    # Verify Protocol check at runtime
    print("\nProtocol check:")
    print("SimulatedTempDriver satisfies Sampler:", isinstance(drivers[0], Sampler))
```

[[OOP and Design]]