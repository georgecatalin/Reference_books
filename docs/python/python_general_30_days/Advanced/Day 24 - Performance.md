#### Profiling, Optimization, and Knowing When to Stop

## The first rule of optimization

Don't. Then: don't yet.

Premature optimization is writing faster code before you know what's slow. It produces code that's harder to read, harder to maintain, and often doesn't even solve the right problem. The correct order is:

1. Make it work
2. Make it correct (tests)
3. Measure — find the actual bottleneck
4. Optimize the bottleneck
5. Measure again — verify the improvement

Skipping step 3 is the mistake. Developers are consistently wrong about where their code is slow. The code you think is slow almost never is. Profile first, always.

---

## Profiling — finding the actual bottleneck

### cProfile — the standard profiler

```python
import cProfile
import pstats
from pstats import SortKey

def slow_function():
    total = 0
    for i in range(100_000):
        total += sum(range(i % 100))
    return total

# Profile it
profiler = cProfile.Profile()
profiler.enable()
slow_function()
profiler.disable()

# Print stats — sorted by cumulative time
stats = pstats.Stats(profiler)
stats.sort_stats(SortKey.CUMULATIVE)
stats.print_stats(10)    # top 10 functions

# Output:
# ncalls  tottime  percall  cumtime  percall filename:lineno(function)
# 100000    0.123    0.000    0.234    0.000 script.py:5(<genexpr>)
# ...
```

```bash
# Run profiler from command line — no code changes needed
python -m cProfile -s cumulative your_script.py

# Save to file, analyze later
python -m cProfile -o profile.out your_script.py
python -c "import pstats; p = pstats.Stats('profile.out'); p.sort_stats('cumulative'); p.print_stats(20)"
```

**Column meanings:**

- `ncalls` — how many times the function was called
- `tottime` — time spent in this function (not counting called functions)
- `cumtime` — total time including all called functions
- `percall` — average time per call

The function with the highest `tottime` is where the CPU is actually spending time. That's your target.

### timeit — measuring small code snippets

```python
import timeit

# Time a single expression
time = timeit.timeit(
    stmt="sum(range(1000))",
    number=10_000    # run 10,000 times, return total seconds
)
print(f"sum(range(1000)): {time:.4f}s for 10,000 runs")
print(f"per call: {time/10_000*1_000_000:.2f}µs")

# Compare approaches
approaches = {
    "list comprehension": "[x**2 for x in range(100)]",
    "map":               "list(map(lambda x: x**2, range(100)))",
    "for loop":          """
result = []
for x in range(100):
    result.append(x**2)
""",
}

for name, code in approaches.items():
    t = timeit.timeit(code, number=100_000)
    print(f"{name:25}: {t:.4f}s")
```

```python
# timeit in Jupyter / interactive use
%timeit sum(range(1000))           # runs automatically until stable
%timeit -n 10000 sum(range(1000))  # specify number of runs
```

### memory_profiler — measuring memory usage

```bash
pip install memory-profiler
```

```python
from memory_profiler import profile

@profile
def memory_heavy():
    # line-by-line memory usage
    a = [i for i in range(1_000_000)]    # ~8MB
    b = {i: i**2 for i in range(100_000)}  # ~8MB
    del a                                   # freed
    return b

memory_heavy()

# Output:
# Line #    Mem usage    Increment   Line Contents
# ================================================
#      5   45.3 MiB    45.3 MiB   def memory_heavy():
#      6   52.8 MiB     7.5 MiB       a = [i for i in range(1_000_000)]
#      7   61.1 MiB     8.3 MiB       b = {i: i**2 for i in range(100_000)}
#      8   53.6 MiB    -7.5 MiB       del a
#      9   53.6 MiB     0.0 MiB       return b
```

### line_profiler — line-by-line CPU profiling

```bash
pip install line-profiler
```

```python
from line_profiler import LineProfiler

def process_data(data):
    result = []
    for item in data:
        cleaned = item.strip().lower()
        if len(cleaned) > 3:
            result.append(cleaned)
    return sorted(result)

profiler = LineProfiler()
profiler.add_function(process_data)
profiler.enable()
process_data(["  Hello  ", "hi", "  World  ", "ok", "  Python  "] * 10_000)
profiler.disable()
profiler.print_stats()
```

---

## Data structure performance — knowing the costs

The biggest performance wins usually come from using the right data structure, not from micro-optimizing code.

```python
import timeit

# Membership testing
small_list = list(range(100))
large_list = list(range(10_000))
small_set = set(range(100))
large_set = set(range(10_000))

# List — O(n) — scans until found
timeit.timeit(lambda: 9999 in large_list, number=100_000)   # ~50ms

# Set — O(1) — hash lookup
timeit.timeit(lambda: 9999 in large_set, number=100_000)    # ~3ms

# 15x faster for large collections
```

```python
# Dictionary lookups vs repeated list scanning
users_list = [{"id": i, "name": f"User{i}"} for i in range(10_000)]
users_dict = {u["id"]: u for u in users_list}

def find_in_list(user_id):
    for user in users_list:
        if user["id"] == user_id:
            return user

def find_in_dict(user_id):
    return users_dict.get(user_id)

# List — O(n) — up to 10,000 comparisons
timeit.timeit(lambda: find_in_list(9999), number=10_000)    # ~2s

# Dict — O(1) — direct hash lookup
timeit.timeit(lambda: find_in_dict(9999), number=10_000)    # ~0.001s
```

```python
# String concatenation — O(n²) vs O(n)
def concat_bad(words):
    result = ""
    for word in words:
        result += word    # creates a new string every iteration
    return result

def concat_good(words):
    return "".join(words)    # O(n) — one allocation

words = ["hello"] * 10_000

timeit.timeit(lambda: concat_bad(words), number=100)    # slow
timeit.timeit(lambda: concat_good(words), number=100)   # fast
```

**The complexity reference card:**

```
Operation               list        dict/set    sorted list
─────────────────────────────────────────────────────────
Append/Add              O(1)*       O(1)*       O(log n)
Insert at index         O(n)        —           O(log n)
Delete by value         O(n)        O(1)*       O(log n)
Search (x in y)         O(n)        O(1)*       O(log n)
Access by index         O(1)        —           O(1)
Access by key           —           O(1)*       —

* amortized
```

---

## Caching — computing things only once

```python
from functools import lru_cache, cache
import time


# Without caching — recomputes every call
def fibonacci_slow(n):
    if n < 2:
        return n
    return fibonacci_slow(n-1) + fibonacci_slow(n-2)

# With lru_cache — each unique argument computed once
@lru_cache(maxsize=128)
def fibonacci(n):
    if n < 2:
        return n
    return fibonacci(n-1) + fibonacci(n-2)

start = time.perf_counter()
fibonacci_slow(35)    # ~3 seconds
print(f"Slow: {time.perf_counter() - start:.3f}s")

start = time.perf_counter()
fibonacci(35)         # instant
print(f"Cached: {time.perf_counter() - start:.6f}s")

# cache (Python 3.9+) = lru_cache(maxsize=None) — unlimited cache
@cache
def expensive_lookup(key: str) -> dict:
    time.sleep(0.1)    # simulate slow operation
    return {"key": key, "data": "..."}

# Cache info
print(fibonacci.cache_info())
# CacheInfo(hits=33, misses=36, maxsize=128, currsize=36)

fibonacci.cache_clear()    # clear when needed
```

**Manual caching — for more control:**

```python
from datetime import datetime, timedelta
from typing import Any, Optional
import threading


class TTLCache:
    """Cache with time-to-live expiration."""

    def __init__(self, ttl_seconds: int = 300):
        self._cache: dict[str, tuple[Any, datetime]] = {}
        self._ttl = timedelta(seconds=ttl_seconds)
        self._lock = threading.Lock()

    def get(self, key: str) -> Optional[Any]:
        with self._lock:
            if key not in self._cache:
                return None
            value, expires_at = self._cache[key]
            if datetime.now() > expires_at:
                del self._cache[key]
                return None
            return value

    def set(self, key: str, value: Any) -> None:
        with self._lock:
            self._cache[key] = (value, datetime.now() + self._ttl)

    def invalidate(self, key: str) -> None:
        with self._lock:
            self._cache.pop(key, None)

    def clear(self) -> None:
        with self._lock:
            self._cache.clear()


_weather_cache = TTLCache(ttl_seconds=600)    # 10 minute cache

def get_weather(city: str) -> dict:
    cached = _weather_cache.get(city)
    if cached is not None:
        return cached

    # Real API call
    result = fetch_from_api(city)
    _weather_cache.set(city, result)
    return result
```

---

## Generators for memory efficiency

Already covered on Day 16, but the performance angle is worth reinforcing.

```python
import sys

# Building a list — all in memory at once
numbers_list = [x**2 for x in range(1_000_000)]
print(f"List: {sys.getsizeof(numbers_list):,} bytes")    # ~8,000,056 bytes

# Generator — computes on demand
numbers_gen = (x**2 for x in range(1_000_000))
print(f"Generator: {sys.getsizeof(numbers_gen)} bytes")  # 104 bytes

# Same result for operations that iterate once
sum(x**2 for x in range(1_000_000))    # use generator — no list needed


# Reading large files — memory matters
def process_large_file_bad(filepath):
    with open(filepath) as f:
        lines = f.readlines()    # loads entire file into memory
    return [line.strip() for line in lines if "ERROR" in line]

def process_large_file_good(filepath):
    with open(filepath) as f:
        for line in f:              # one line at a time
            if "ERROR" in line:
                yield line.strip()  # yields, doesn't accumulate
```

---

## Algorithmic improvements — the biggest wins

No micro-optimization beats fixing a bad algorithm.

```python
import time

data = list(range(10_000))

# O(n²) — nested loops
def find_duplicates_slow(items):
    duplicates = []
    for i in range(len(items)):
        for j in range(i + 1, len(items)):
            if items[i] == items[j] and items[i] not in duplicates:
                duplicates.append(items[i])
    return duplicates

# O(n) — single pass with a set
def find_duplicates_fast(items):
    seen = set()
    duplicates = set()
    for item in items:
        if item in seen:
            duplicates.add(item)
        seen.add(item)
    return list(duplicates)

test = list(range(500)) + list(range(250))    # has duplicates

start = time.perf_counter()
find_duplicates_slow(test)
print(f"O(n²): {time.perf_counter() - start:.4f}s")

start = time.perf_counter()
find_duplicates_fast(test)
print(f"O(n):  {time.perf_counter() - start:.4f}s")
# O(n) is typically 100-1000x faster on large inputs
```

---

## Python-specific optimizations

These are micro-optimizations — apply only after profiling confirms they matter.

**Local variable lookup is faster than global:**

```python
import math
import timeit

# Global lookup — slower
def compute_global(n):
    result = 0
    for i in range(n):
        result += math.sqrt(i)
    return result

# Local reference — faster
def compute_local(n):
    sqrt = math.sqrt    # local reference, looked up once
    result = 0
    for i in range(n):
        result += sqrt(i)
    return result

print(timeit.timeit(lambda: compute_global(10_000), number=1_000))
print(timeit.timeit(lambda: compute_local(10_000), number=1_000))
# local is ~15% faster — matters in tight loops, nowhere else
```

**List comprehensions vs loops:**

```python
# Loop with append — slower
def squares_loop(n):
    result = []
    for i in range(n):
        result.append(i**2)
    return result

# List comprehension — faster (optimized bytecode)
def squares_comp(n):
    return [i**2 for n in range(n)]

# map with built-in — fastest for simple operations
def squares_map(n):
    return list(map(lambda x: x**2, range(n)))
```

**Avoid repeated attribute lookups in loops:**

```python
# Slow — looks up result.append on every iteration
def build_list_slow(data):
    result = []
    for item in data:
        result.append(item * 2)
    return result

# Fast — one attribute lookup
def build_list_fast(data):
    result = []
    append = result.append    # local reference
    for item in data:
        append(item * 2)
    return result

# Fastest for simple cases — list comprehension
def build_list_fastest(data):
    return [item * 2 for item in data]
```

**Slots for memory-efficient objects:**

```python
import sys
from dataclasses import dataclass


class PointRegular:
    def __init__(self, x, y, z):
        self.x = x
        self.y = y
        self.z = z


class PointSlots:
    __slots__ = ("x", "y", "z")

    def __init__(self, x, y, z):
        self.x = x
        self.y = y
        self.z = z


regular = PointRegular(1.0, 2.0, 3.0)
slotted = PointSlots(1.0, 2.0, 3.0)

print(sys.getsizeof(regular))    # ~48 bytes + __dict__ (~232 bytes)
print(sys.getsizeof(slotted))    # ~56 bytes — no __dict__

# For 1,000,000 objects:
# Regular: ~280MB
# Slotted: ~56MB
# 5x memory reduction
```

---

## NumPy — vectorized operations for numerical work

When you're doing numerical computation on large arrays, Python loops are the wrong tool. NumPy operations run in C, not Python.

```bash
pip install numpy
```

```python
import numpy as np
import timeit

# Python loop — slow for numerical work
def sum_squares_python(n):
    return sum(i**2 for i in range(n))

# NumPy — vectorized, runs in C
def sum_squares_numpy(n):
    arr = np.arange(n)
    return np.sum(arr**2)

n = 1_000_000
print(timeit.timeit(lambda: sum_squares_python(n), number=10))  # ~3s
print(timeit.timeit(lambda: sum_squares_numpy(n), number=10))   # ~0.03s
# 100x faster

# NumPy operations — no Python loops
a = np.array([1, 2, 3, 4, 5], dtype=np.float64)
b = np.array([10, 20, 30, 40, 50], dtype=np.float64)

print(a + b)            # [11. 22. 33. 44. 55.]  — element-wise
print(a * 2)            # [ 2.  4.  6.  8. 10.]
print(np.sqrt(a))       # [1.  1.41 1.73 2.  2.24]
print(a[a > 2])         # [3. 4. 5.] — boolean indexing
print(np.mean(a))       # 3.0
print(np.dot(a, b))     # 550.0 — dot product
```

---

## Concurrency as a performance tool

Sometimes the bottleneck isn't computation — it's waiting.

```python
import asyncio
import aiohttp
import time

# Sequential I/O — each request waits for the previous
def fetch_sequential(urls):
    import requests
    return [requests.get(url).json() for url in urls]

# Concurrent I/O — all requests in flight simultaneously
async def fetch_concurrent(urls):
    async with aiohttp.ClientSession() as session:
        tasks = [session.get(url) for url in urls]
        responses = await asyncio.gather(*tasks)
        return [await r.json() for r in responses]

urls = [f"https://jsonplaceholder.typicode.com/posts/{i}" for i in range(1, 11)]

start = time.perf_counter()
fetch_sequential(urls)
print(f"Sequential: {time.perf_counter() - start:.2f}s")    # ~2s

start = time.perf_counter()
asyncio.run(fetch_concurrent(urls))
print(f"Concurrent: {time.perf_counter() - start:.2f}s")    # ~0.3s
```

---

## When to stop optimizing

```python
# BEFORE optimizing — measure
import cProfile
cProfile.run("your_function()")

# Ask these questions:
# 1. Is the program actually too slow? (measured, not felt)
# 2. Is this the function that's actually slow? (profiler says so)
# 3. Will users notice the improvement?
# 4. Is the code still readable after optimization?

# The optimization hierarchy — go in order, stop when fast enough:
# 1. Fix the algorithm — O(n) instead of O(n²)
# 2. Use the right data structure — set instead of list for membership
# 3. Cache expensive results — @lru_cache, TTLCache
# 4. Use generators for large datasets
# 5. Use libraries (NumPy, pandas) for numerical/data work
# 6. Use concurrency for I/O-bound work (asyncio, ThreadPoolExecutor)
# 7. Use multiprocessing for CPU-bound work
# 8. Micro-optimizations — local variables, slots, comprehensions
# 9. Rewrite hot path in C (Cython, ctypes) — almost never needed
```

---

## A complete profiling workflow

```python
# Step 1 — you notice the program is slow
# Step 2 — profile to find where

import cProfile
import pstats
import io

def profile_function(func, *args, **kwargs):
    """Profile a function call and return the stats."""
    pr = cProfile.Profile()
    pr.enable()
    result = func(*args, **kwargs)
    pr.disable()

    stream = io.StringIO()
    stats = pstats.Stats(pr, stream=stream)
    stats.sort_stats("cumulative")
    stats.print_stats(15)
    print(stream.getvalue())

    return result


# Example — finding the bottleneck in a data pipeline
def load_data():
    return [{"id": i, "value": i**2, "name": f"item_{i}"} for i in range(50_000)]

def filter_data(data):
    return [item for item in data if item["value"] > 100]

def transform_data(data):
    return [{"id": item["id"], "result": item["value"] * 1.5} for item in data]

def summarize(data):
    return sum(item["result"] for item in data)

def full_pipeline():
    data = load_data()
    filtered = filter_data(data)
    transformed = transform_data(filtered)
    return summarize(transformed)

profile_function(full_pipeline)

# Step 3 — profiler shows load_data() takes 80% of the time
# Step 4 — optimize load_data()
# Step 5 — measure again, confirm improvement
```

---

## The mental model to carry forward

Performance is a feature you add when users need it, not a quality you pursue for its own sake. Unoptimized readable code beats unreadable fast code that you can't maintain.

**The hierarchy of performance improvements by impact:**

- Algorithm choice (O(n) vs O(n²)) — 10x to 1000x
- Data structure choice (set vs list for membership) — 10x to 100x
- Caching (compute once, reuse) — 10x to 100x
- Concurrency (I/O overlap) — 2x to 10x
- Libraries (NumPy vs Python loops) — 10x to 100x for numerical
- Micro-optimizations (local vars, slots) — 5% to 20%

The top of that list is always worth thinking about. The bottom of that list requires profiler evidence before touching.

**The three questions before any optimization:**

- What does the profiler say is slow?
- How much faster does it need to be?
- Is the code still maintainable after the change?

---

Day 25 is packaging — `pyproject.toml`, building a distributable package, and publishing to PyPI. Ready when you are.

[[Advanced]]