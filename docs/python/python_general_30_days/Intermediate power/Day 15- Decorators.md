#### Functions That Wrap Functions

## What a decorator actually is

A decorator is a function that takes a function, wraps it with additional behavior, and returns a new function. That's the complete definition. Everything else is just application of that idea.

```python
def my_decorator(func):
    def wrapper(*args, **kwargs):
        print("Before")
        result = func(*args, **kwargs)
        print("After")
        return result
    return wrapper

def greet(name):
    print(f"Hello, {name}")

greet = my_decorator(greet)    # manual decoration
greet("Alice")
# Before
# Hello, Alice
# After
```

The `@` syntax is just shorthand for that last assignment:

```python
@my_decorator
def greet(name):
    print(f"Hello, {name}")

# Exactly equivalent to:
# greet = my_decorator(greet)
```

The `@` line runs at import time — when the module is loaded, not when the function is called.

---

## The foundation — closures

Decorators work because of closures. A closure is a function that remembers variables from its enclosing scope even after that scope has finished.

```python
def make_counter(start=0):
    count = start    # this variable lives in the enclosing scope

    def counter():
        nonlocal count    # tells Python: use the enclosing scope's count
        count += 1
        return count

    return counter    # returns the function, not the result

counter_a = make_counter()
counter_b = make_counter(10)

print(counter_a())    # 1
print(counter_a())    # 2
print(counter_b())    # 11  — independent state
print(counter_a())    # 3   — a is unaffected by b
```

`counter` remembers `count` even though `make_counter` has returned. The wrapper function in a decorator is exactly this — a closure that remembers the original function.

---

## `*args, **kwargs` — why decorators use them

A decorator must work on any function — functions with no arguments, many arguments, keyword arguments, all of it. `*args, **kwargs` captures everything and passes it through unchanged.

```python
def log_calls(func):
    def wrapper(*args, **kwargs):
        print(f"Calling {func.__name__} with args={args}, kwargs={kwargs}")
        result = func(*args, **kwargs)
        print(f"{func.__name__} returned {result!r}")
        return result
    return wrapper

@log_calls
def add(x, y):
    return x + y

@log_calls
def greet(name, greeting="Hello"):
    return f"{greeting}, {name}!"

add(3, 5)
# Calling add with args=(3, 5), kwargs={}
# add returned 8

greet("Alice", greeting="Hi")
# Calling greet with args=('Alice',), kwargs={'greeting': 'Hi'}
# greet returned 'Hi, Alice!'
```

---

## `functools.wraps` — preserving function identity

Without `wraps`, your decorator breaks introspection — the wrapped function loses its name, docstring, and signature.

```python
def bad_decorator(func):
    def wrapper(*args, **kwargs):
        return func(*args, **kwargs)
    return wrapper

@bad_decorator
def my_function():
    """Does something important."""
    pass

print(my_function.__name__)    # wrapper — wrong
print(my_function.__doc__)     # None — lost


# Fix — always use functools.wraps
from functools import wraps

def good_decorator(func):
    @wraps(func)    # copies __name__, __doc__, __module__, __qualname__, __annotations__
    def wrapper(*args, **kwargs):
        return func(*args, **kwargs)
    return wrapper

@good_decorator
def my_function():
    """Does something important."""
    pass

print(my_function.__name__)    # my_function — correct
print(my_function.__doc__)     # Does something important.
```

Every decorator you write should use `@wraps(func)`. No exceptions. Without it, debugging, logging, and documentation tools break in subtle ways.

---

## Decorators with arguments — the extra layer

A plain decorator takes a function and returns a function. A decorator with arguments is a function that takes arguments and _returns a decorator_.

```python
from functools import wraps

# This is a decorator factory — it returns a decorator
def repeat(times):
    def decorator(func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            for _ in range(times):
                result = func(*args, **kwargs)
            return result
        return wrapper
    return decorator

@repeat(3)
def say_hello():
    print("Hello!")

say_hello()
# Hello!
# Hello!
# Hello!

# What's happening:
# repeat(3) returns decorator
# decorator(say_hello) returns wrapper
# say_hello = wrapper
```

Three levels of nesting: the factory, the decorator, the wrapper. This pattern appears everywhere in real Python — `@app.route("/")` in Flask, `@pytest.mark.parametrize` in pytest, `@retry(max_attempts=3)` in your own code.

---

## Real decorators you'll write and use

**Timing:**

```python
import time
from functools import wraps

def timer(func):
    @wraps(func)
    def wrapper(*args, **kwargs):
        start = time.perf_counter()
        result = func(*args, **kwargs)
        elapsed = time.perf_counter() - start
        print(f"{func.__name__} took {elapsed:.4f}s")
        return result
    return wrapper

@timer
def slow_operation():
    time.sleep(0.1)
    return "done"

slow_operation()    # slow_operation took 0.1003s
```

**Retry with backoff:**

```python
import time
from functools import wraps

def retry(max_attempts=3, delay=1.0, exceptions=(Exception,)):
    def decorator(func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            last_exception = None
            for attempt in range(1, max_attempts + 1):
                try:
                    return func(*args, **kwargs)
                except exceptions as e:
                    last_exception = e
                    if attempt < max_attempts:
                        print(f"Attempt {attempt} failed: {e}. Retrying in {delay}s...")
                        time.sleep(delay)
            raise last_exception
        return wrapper
    return decorator

@retry(max_attempts=3, delay=0.5, exceptions=(ConnectionError,))
def fetch_data(url):
    import random
    if random.random() < 0.7:
        raise ConnectionError("Network timeout")
    return f"Data from {url}"
```

**Caching / memoization:**

```python
from functools import wraps

def cache(func):
    """Simple cache — stores results by arguments."""
    _cache = {}

    @wraps(func)
    def wrapper(*args):
        if args not in _cache:
            _cache[args] = func(*args)
        return _cache[args]

    wrapper.cache = _cache          # expose the cache for inspection
    wrapper.cache_clear = _cache.clear

    return wrapper

@cache
def fibonacci(n):
    if n < 2:
        return n
    return fibonacci(n - 1) + fibonacci(n - 2)

print(fibonacci(40))             # instant — each value computed once
print(fibonacci.cache)           # see all cached values

# In production, use functools.lru_cache instead:
from functools import lru_cache

@lru_cache(maxsize=128)
def fibonacci(n):
    if n < 2:
        return n
    return fibonacci(n - 1) + fibonacci(n - 2)
```

**Validation:**

```python
from functools import wraps

def require_positive(*param_names):
    """Decorator that validates specified parameters are positive."""
    def decorator(func):
        import inspect
        sig = inspect.signature(func)

        @wraps(func)
        def wrapper(*args, **kwargs):
            bound = sig.bind(*args, **kwargs)
            bound.apply_defaults()
            for name in param_names:
                if name in bound.arguments:
                    value = bound.arguments[name]
                    if value <= 0:
                        raise ValueError(f"{name} must be positive, got {value}")
            return func(*args, **kwargs)
        return wrapper
    return decorator

@require_positive("width", "height")
def create_rectangle(width, height, color="blue"):
    return {"width": width, "height": height, "color": color}

create_rectangle(10, 5)      # works
create_rectangle(-1, 5)      # ValueError: width must be positive, got -1
```

**Rate limiting:**

```python
import time
from functools import wraps
from collections import deque

def rate_limit(calls_per_second):
    """Allow at most N calls per second."""
    min_interval = 1.0 / calls_per_second
    last_called = [0.0]    # list so we can mutate it in the closure

    def decorator(func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            elapsed = time.monotonic() - last_called[0]
            wait = min_interval - elapsed
            if wait > 0:
                time.sleep(wait)
            last_called[0] = time.monotonic()
            return func(*args, **kwargs)
        return wrapper
    return decorator

@rate_limit(calls_per_second=2)
def call_api(endpoint):
    print(f"Calling {endpoint} at {time.monotonic():.2f}")

for _ in range(4):
    call_api("/users")
# calls spaced at least 0.5 seconds apart
```

---

## Stacking decorators

Multiple decorators apply bottom-up — the closest to the function runs first.

```python
from functools import wraps

@timer
@retry(max_attempts=3)
@log_calls
def fetch_user(user_id):
    # ...
    pass

# Equivalent to:
# fetch_user = timer(retry(max_attempts=3)(log_calls(fetch_user)))
# Order: log_calls wraps fetch_user, retry wraps that, timer wraps that

# Execution order when called:
# timer starts
#   retry starts
#     log_calls logs the call
#       fetch_user runs
#     log_calls logs the return
#   retry catches exceptions if needed
# timer stops and prints elapsed time
```

Order matters. `@timer` outside `@retry` means you're timing the total time including retries and delays. Swap them and you'd time only a single attempt.

---

## Class-based decorators

Sometimes a class makes more sense than a nested function — especially when the decorator needs to maintain state.

```python
from functools import wraps
import time

class RateLimiter:
    """Decorator class that rate-limits function calls."""

    def __init__(self, calls_per_second):
        self.min_interval = 1.0 / calls_per_second
        self.last_called = 0.0

    def __call__(self, func):
        @wraps(func)
        def wrapper(*args, **kwargs):
            elapsed = time.monotonic() - self.last_called
            wait = self.min_interval - elapsed
            if wait > 0:
                time.sleep(wait)
            self.last_called = time.monotonic()
            return func(*args, **kwargs)
        return wrapper


@RateLimiter(calls_per_second=5)
def api_call(endpoint):
    return f"Response from {endpoint}"
```

```python
class CallCounter:
    """Decorator that counts how many times a function is called."""

    def __init__(self, func):
        wraps(func)(self)
        self.func = func
        self.call_count = 0

    def __call__(self, *args, **kwargs):
        self.call_count += 1
        return self.func(*args, **kwargs)

    def reset(self):
        self.call_count = 0

@CallCounter
def process(item):
    return item * 2

process(1)
process(2)
process(3)
print(process.call_count)    # 3
process.reset()
print(process.call_count)    # 0
```

---

## Decorators in the real world

The decorators you'll encounter constantly in real codebases:

```python
# Flask / FastAPI — routing
@app.route("/users/<int:user_id>")
def get_user(user_id):
    ...

@app.get("/items")
async def list_items():
    ...

# pytest — test configuration
@pytest.mark.parametrize("input,expected", [(1, 2), (2, 4), (3, 6)])
def test_double(input, expected):
    assert double(input) == expected

@pytest.fixture
def db_connection():
    ...

# dataclasses
@dataclass
@dataclass(frozen=True)

# standard library
@staticmethod
@classmethod
@property
@lru_cache(maxsize=128)

# your own code
@require_auth
@cache
@timer
@retry(max_attempts=3)
@validate_input
```

All of these are exactly the same mechanism — a callable that takes a function and returns a function.

---

## The mental model to carry forward

A decorator answers the question: "What behavior do I want to add to multiple functions without repeating myself?"

Timing, logging, caching, retrying, validating, rate limiting, authentication checking — these are concerns that cut across many functions. Decorators put that logic in one place and apply it cleanly.

**The three forms:**

```python
# 1. Simple decorator — no arguments
@decorator
def func(): ...

# 2. Decorator factory — takes arguments
@decorator(arg1, arg2)
def func(): ...

# 3. Stacked — multiple decorators
@decorator_a
@decorator_b
def func(): ...
```

**Always use `@wraps(func)`.** Always. It takes one second and prevents a class of bugs that are annoying to diagnose.

**The smell that says "write a decorator":** you're writing the same setup/teardown code at the start or end of multiple functions. Extract it into a decorator, apply it with `@`, and never repeat that code again.

---

Day 16 is generators — `yield`, lazy evaluation, and how to process data that's too large to fit in memory. Ready when you are.

[[Intermediate Power]]