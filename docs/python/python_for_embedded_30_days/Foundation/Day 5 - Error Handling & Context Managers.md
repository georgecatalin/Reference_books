### Don't use bare `except`

python

```python
# Wrong — silently swallows everything including KeyboardInterrupt, SystemExit
try:
    connect()
except:
    pass

# Wrong — too broad, hides real bugs
try:
    connect()
except Exception:
    pass

# Right — catch what you expect, let the rest propagate
try:
    connect()
except ConnectionRefusedError:
    logger.warning("Broker refused connection")
except OSError as e:
    logger.error("Network error: %s", e)
```

The exception hierarchy matters. `Exception` catches almost everything user-facing. `BaseException` catches everything including `KeyboardInterrupt` and `SystemExit` — never catch those unless you're writing a process supervisor.

---

### The `as` clause and exception chaining

python

```python
try:
    value = float(payload.decode("utf-8"))
except (ValueError, UnicodeDecodeError) as e:
    # e contains the original exception — log it, don't lose it
    raise ValueError(f"Bad payload from {device_id!r}: {payload!r}") from e
    #                                                                   ^^^^^^
    # `from e` preserves the original traceback — critical for debugging
```

`raise X from Y` chains exceptions. Without `from e` you lose the original cause. In production IoT code where a payload parse fails at 3am, you want the full chain.

---

### `else` and `finally` — the full try block

python

```python
try:
    data = parse(payload)
except ValueError as e:
    log_error(e)
    return None
else:
    # Runs only if no exception was raised — not "finally"
    # Good place for code that should only run on success
    publish_result(data)
finally:
    # Always runs — exception or not, return or not
    # Guaranteed cleanup — like a C destructor
    metrics.increment("parse_attempts")
```

`else` is underused and valuable — it separates "success path logic" from "error path logic" cleanly.

---

### Context managers — guaranteed resource cleanup

The `with` statement calls `__enter__` on open and `__exit__` on close — even if an exception is raised inside the block. This is your RAII equivalent in Python.

python

```python
# File — always closed, even on exception
with open("log.txt", "a") as f:
    f.write(f"{ts},{device_id},{value}\n")

# Multiple resources in one with
with open("in.csv") as src, open("out.csv", "w") as dst:
    dst.write(src.read())

# Serial port — closed on exit even if code crashes mid-read
import serial
with serial.Serial("/dev/ttyUSB0", 115200, timeout=1.0) as port:
    data = port.read(64)
```

---

### Writing your own context manager

Two ways. The class-based way is explicit and reusable:

python

```python
class MQTTSession:
    def __init__(self, host: str, port: int) -> None:
        self.host = host
        self.port = port
        self._client = None

    def __enter__(self) -> "MQTTSession":
        self._client = connect(self.host, self.port)
        return self          # value bound to `as` variable

    def __exit__(self, exc_type, exc_val, exc_tb) -> bool:
        if self._client:
            self._client.disconnect()
        return False         # False = don't suppress exceptions
                             # True  = swallow the exception (rarely correct)

with MQTTSession("localhost", 1883) as session:
    session.publish("test/topic", b"hello")
```

The `contextlib.contextmanager` decorator way is faster to write for simple cases:

python

```python
from contextlib import contextmanager
import serial

@contextmanager
def serial_port(device: str, baud: int):
    port = serial.Serial(device, baud, timeout=1.0)
    try:
        yield port           # value bound to `as` variable
    finally:
        port.close()         # guaranteed cleanup

with serial_port("/dev/ttyUSB0", 115200) as port:
    data = port.read(64)
```

Everything before `yield` is `__enter__`. Everything after (in `finally`) is `__exit__`. The `try/finally` inside is required — without it, an exception inside the `with` block skips the cleanup.

---

### `contextlib` utilities worth knowing

python

```python
from contextlib import suppress, nullcontext, ExitStack

# suppress — intentionally ignore specific exceptions
with suppress(FileNotFoundError):
    os.remove("temp_lock.pid")   # don't care if it didn't exist

# nullcontext — placeholder when a context manager is optional
def process(port=None):
    ctx = serial_port("/dev/ttyUSB0", 115200) if port is None else nullcontext(port)
    with ctx as p:
        return p.read(64)

# ExitStack — manage a dynamic number of context managers
with ExitStack() as stack:
    files = [stack.enter_context(open(f)) for f in filenames]
    # all files closed on exit, even if one fails mid-open
```

---

### Today's deliverable

python

```python
# robust_reader.py
import time
import random
from contextlib import contextmanager
from typing import Iterator, Optional

# --- Simulated hardware (replace with real serial/socket in production) ---

class SimulatedPort:
    """Fake serial port that occasionally fails."""
    def __init__(self, failure_rate: float = 0.15) -> None:
        self._failure_rate = failure_rate
        self._open = False

    def open(self) -> None:
        self._open = True
        print("  [port] opened")

    def close(self) -> None:
        self._open = False
        print("  [port] closed")

    def read_line(self) -> bytes:
        if not self._open:
            raise IOError("Port not open")
        if random.random() < self._failure_rate:
            raise IOError("Read timeout")
        value = round(20.0 + random.uniform(-5, 5), 2)
        return f"{value}\n".encode()


# --- Context manager ---

@contextmanager
def open_port(failure_rate: float = 0.15) -> Iterator[SimulatedPort]:
    port = SimulatedPort(failure_rate)
    port.open()
    try:
        yield port
    finally:
        port.close()


# --- Robust reading with proper exception handling ---

def read_temperature(port: SimulatedPort) -> Optional[float]:
    try:
        raw = port.read_line()
        return float(raw.decode("utf-8").strip())
    except IOError as e:
        print(f"  [warn] read failed: {e}")
        return None
    except (ValueError, UnicodeDecodeError) as e:
        print(f"  [warn] parse failed: {e}")
        return None


def collect_samples(
    n: int,
    failure_rate: float = 0.15,
) -> list[float]:
    samples: list[float] = []

    with open_port(failure_rate) as port:
        attempts = 0
        while len(samples) < n:
            attempts += 1
            if attempts > n * 3:
                print("  [error] too many failures, aborting")
                break
            value = read_temperature(port)
            if value is not None:
                samples.append(value)
            else:
                time.sleep(0.01)   # back off on failure

    return samples


if __name__ == "__main__":
    random.seed(42)
    print("Collecting 10 temperature samples...")
    samples = collect_samples(10, failure_rate=0.3)
    print(f"Got {len(samples)} samples: {samples}")
    if samples:
        print(f"Average: {sum(samples)/len(samples):.2f}")
```
[[Foundation]]