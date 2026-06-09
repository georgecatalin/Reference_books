### What a decorator actually is

A decorator is a callable that takes a function and returns a function. The `@` syntax is just shorthand:

python

```python
@my_decorator
def func(): ...

# Exactly equivalent to:
def func(): ...
func = my_decorator(func)
```

That's it. No magic.

---

### The basic pattern

python

```python
import functools

def log_call(func):
    @functools.wraps(func)   # preserves __name__, __doc__, etc.
    def wrapper(*args, **kwargs):
        print(f"Calling {func.__name__}")
        result = func(*args, **kwargs)
        print(f"{func.__name__} returned {result!r}")
        return result
    return wrapper

@log_call
def connect(host: str, port: int) -> bool:
    return True

connect("localhost", 1883)
# Calling connect
# connect returned True
```

`functools.wraps` is mandatory — without it, `func.__name__` becomes `"wrapper"` and stack traces become unreadable.

---

### Decorators with arguments — the extra layer

When your decorator takes arguments, you need a factory (a function that returns a decorator):

python

```python
def retry(max_attempts: int = 3, delay: float = 1.0):
    """Decorator factory — returns the actual decorator."""
    def decorator(func):
        @functools.wraps(func)
        def wrapper(*args, **kwargs):
            last_exc = None
            for attempt in range(1, max_attempts + 1):
                try:
                    return func(*args, **kwargs)
                except (OSError, ConnectionError) as e:
                    last_exc = e
                    print(f"  attempt {attempt}/{max_attempts} failed: {e}")
                    if attempt < max_attempts:
                        import time; time.sleep(delay)
            raise last_exc
        return wrapper
    return decorator

@retry(max_attempts=3, delay=0.5)
def connect(host: str) -> bool:
    import random
    if random.random() < 0.7:
        raise ConnectionError("refused")
    return True
```

The three-layer structure: factory → decorator → wrapper. The factory captures `max_attempts` and `delay` in a closure.

---

### Class-based decorators

When the decorator needs to maintain state, a class is cleaner than a closure:

python

```python
class RateLimit:
    """Allow at most `calls` calls per `period` seconds."""

    def __init__(self, calls: int, period: float) -> None:
        self._calls   = calls
        self._period  = period
        self._history: list[float] = []

    def __call__(self, func):
        @functools.wraps(func)
        def wrapper(*args, **kwargs):
            import time
            now = time.time()
            # Remove calls outside the window
            self._history = [t for t in self._history if now - t < self._period]
            if len(self._history) >= self._calls:
                raise RuntimeError(f"Rate limit: max {self._calls}/{self._period}s")
            self._history.append(now)
            return func(*args, **kwargs)
        return wrapper


@RateLimit(calls=5, period=1.0)
def publish(topic: str, payload: bytes) -> None:
    print(f"Publishing {payload!r} to {topic}")
```

---

### Practical decorators for IoT work

python

```python
import time, functools, logging

logger = logging.getLogger(__name__)

# --- Timing ---
def timed(func):
    @functools.wraps(func)
    def wrapper(*args, **kwargs):
        t0 = time.perf_counter()
        result = func(*args, **kwargs)
        elapsed = time.perf_counter() - t0
        logger.debug("%s took %.3fms", func.__name__, elapsed * 1000)
        return result
    return wrapper

# --- Ensure connected ---
def requires_connection(func):
    @functools.wraps(func)
    def wrapper(self, *args, **kwargs):
        if not self._connected:
            raise RuntimeError(f"Call connect() before {func.__name__}()")
        return func(self, *args, **kwargs)
    return wrapper

class MQTTClient:
    def __init__(self) -> None:
        self._connected = False

    def connect(self) -> None:
        self._connected = True

    @requires_connection
    @timed
    def publish(self, topic: str, payload: bytes) -> None:
        print(f"pub: {topic}")
```

Multiple decorators stack bottom-up: `@timed` wraps the already-`@requires_connection`-wrapped function.

---

### `functools` tools worth knowing

python

```python
from functools import lru_cache, cache, partial, reduce

# lru_cache — memoize expensive function calls
@lru_cache(maxsize=128)
def load_device_config(device_id: str) -> dict:
    return fetch_from_db(device_id)   # called once per unique device_id

# cache — same as lru_cache(maxsize=None), Python 3.9+
@cache
def parse_topic_pattern(pattern: str) -> re.Pattern:
    return re.compile(pattern.replace("+", "[^/]+").replace("#", ".*"))

# partial — fix some arguments of a function
def publish(client, qos, topic, payload):
    client.send(topic, payload, qos)

publish_qos1 = partial(publish, my_client, 1)  # client and qos fixed
publish_qos1("devices/temp", b"22.4")           # just topic + payload
```

---

### Today's deliverable

python

```python
# decorators.py
import time, functools, random, logging
from typing import Callable, TypeVar, Any

logging.basicConfig(level=logging.DEBUG, format="%(levelname)s %(message)s")
logger = logging.getLogger(__name__)

F = TypeVar("F", bound=Callable[..., Any])

# --- 1. @retry(max_attempts, delay, exceptions) ---
def retry(
    max_attempts: int = 3,
    delay:        float = 0.5,
    exceptions:   tuple = (OSError, ConnectionError),
):
    def decorator(func: F) -> F:
        @functools.wraps(func)
        def wrapper(*args, **kwargs):
            last_exc: Exception = RuntimeError("no attempts made")
            for attempt in range(1, max_attempts + 1):
                try:
                    return func(*args, **kwargs)
                except exceptions as e:
                    last_exc = e
                    logger.warning("%s attempt %d/%d failed: %s",
                                   func.__name__, attempt, max_attempts, e)
                    if attempt < max_attempts:
                        time.sleep(delay)
            raise last_exc
        return wrapper  # type: ignore[return-value]
    return decorator


# --- 2. @timed ---
def timed(func: F) -> F:
    @functools.wraps(func)
    def wrapper(*args, **kwargs):
        t0 = time.perf_counter()
        result = func(*args, **kwargs)
        logger.debug("%s: %.2fms", func.__name__, (time.perf_counter() - t0) * 1000)
        return result
    return wrapper  # type: ignore[return-value]


# --- 3. @once — call a function only once, cache result ---
def once(func: F) -> F:
    result_store: dict = {}
    @functools.wraps(func)
    def wrapper(*args, **kwargs):
        if "result" not in result_store:
            result_store["result"] = func(*args, **kwargs)
        return result_store["result"]
    return wrapper  # type: ignore[return-value]


# --- Demo ---
random.seed(42)

@retry(max_attempts=4, delay=0.0, exceptions=(ConnectionError,))
@timed
def flaky_connect(host: str) -> str:
    if random.random() < 0.65:
        raise ConnectionError(f"refused by {host}")
    return f"connected to {host}"

@once
@timed
def load_config() -> dict:
    logger.info("Loading config (should only happen once)")
    return {"host": "localhost", "port": 1883}

if __name__ == "__main__":
    print(flaky_connect("broker.local"))
    print(load_config())
    print(load_config())   # second call — function body should NOT run again
```

Add a fourth decorator `@validate_args` that checks all arguments against a predicate before calling the function. Signature: `@validate_args(lambda host, port: isinstance(host, str) and 1 <= port <= 65535)`. Apply it to a `connect(host: str, port: int)` function.

[[OOP and Design]]