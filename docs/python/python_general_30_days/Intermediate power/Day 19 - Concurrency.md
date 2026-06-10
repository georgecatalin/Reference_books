#### Threading, Multiprocessing, and Async

## The problem concurrency solves

Some programs spend most of their time waiting — waiting for a network response, waiting for a file to read, waiting for a database query. During that wait, the CPU is idle. Concurrency lets you do useful work during that idle time.

```python
import time

# Sequential — each request waits for the previous one
def fetch_all_sequential(urls):
    results = []
    for url in urls:
        response = requests.get(url)    # waits 200ms per request
        results.append(response.json())
    return results

# 10 URLs × 200ms = 2 seconds total

# Concurrent — all requests in flight simultaneously
# 10 URLs, still ~200ms total — we'll build this today
```

---

## The GIL — Python's most misunderstood feature

The Global Interpreter Lock (GIL) is a mutex that prevents multiple threads from executing Python bytecode simultaneously. One thread runs at a time.

```
Thread A: ----run----wait(IO)----------run----
Thread B:          ----run----wait(IO)----run----
Thread C:                   ----run----wait(IO)----
Time:      0    100ms   200ms   300ms   400ms
```

This sounds like it kills concurrency. It doesn't — because the GIL is released during I/O operations. While Thread A is waiting for a network response, Thread B runs. The GIL matters for CPU-bound work (computation), not I/O-bound work (waiting).

```
I/O bound (network, files, database) → threading works fine
CPU bound (computation, image processing, ML) → multiprocessing needed
```

---

## Threading — concurrent I/O

```python
import threading
import time

def worker(name, duration):
    print(f"{name} starting")
    time.sleep(duration)    # simulates I/O wait — GIL released here
    print(f"{name} done after {duration}s")

# Sequential
start = time.perf_counter()
worker("Task A", 1)
worker("Task B", 1)
worker("Task C", 1)
print(f"Sequential: {time.perf_counter() - start:.2f}s")    # ~3 seconds

# Threaded
start = time.perf_counter()
threads = [
    threading.Thread(target=worker, args=("Task A", 1)),
    threading.Thread(target=worker, args=("Task B", 1)),
    threading.Thread(target=worker, args=("Task C", 1)),
]
for t in threads:
    t.start()       # start all threads
for t in threads:
    t.join()        # wait for all to finish
print(f"Threaded: {time.perf_counter() - start:.2f}s")    # ~1 second
```

`thread.start()` launches the thread. `thread.join()` blocks until it completes. Start all threads before joining any — otherwise you'd wait for each one sequentially.

---

## ThreadPoolExecutor — the practical way to use threads

Manual thread management is tedious. `ThreadPoolExecutor` handles the pool, submission, and result collection.

```python
from concurrent.futures import ThreadPoolExecutor, as_completed
import requests
import time

URLS = [
    "https://httpbin.org/delay/1",
    "https://httpbin.org/get",
    "https://httpbin.org/ip",
    "https://jsonplaceholder.typicode.com/posts/1",
    "https://jsonplaceholder.typicode.com/users/1",
]

def fetch(url):
    """Fetch a URL and return (url, status_code, elapsed)."""
    start = time.perf_counter()
    try:
        response = requests.get(url, timeout=10)
        return url, response.status_code, time.perf_counter() - start
    except Exception as e:
        return url, None, time.perf_counter() - start

# Submit all tasks, collect results as they complete
start = time.perf_counter()
with ThreadPoolExecutor(max_workers=5) as executor:
    # map — simpler, results in submission order
    results = list(executor.map(fetch, URLS))

    # submit — more control, results as they complete
    futures = {executor.submit(fetch, url): url for url in URLS}
    for future in as_completed(futures):
        url, status, elapsed = future.result()
        print(f"{status} {elapsed:.2f}s  {url}")

print(f"Total: {time.perf_counter() - start:.2f}s")
```

**`map` vs `submit`:**

- `map(fn, items)` — returns results in input order, simpler
- `submit(fn, *args)` — returns a Future, results in completion order, handles exceptions per-task

**Handling exceptions from futures:**

```python
with ThreadPoolExecutor(max_workers=5) as executor:
    futures = {executor.submit(fetch, url): url for url in URLS}

    for future in as_completed(futures):
        url = futures[future]    # get the URL from the dict
        try:
            result = future.result()    # raises if the task raised
            print(f"OK: {url}")
        except Exception as e:
            print(f"FAILED: {url} — {e}")
```

---

## Thread safety — the real complexity of threading

Multiple threads sharing data causes race conditions. This is where threading gets dangerous.

```python
import threading

# Race condition — NOT thread safe
counter = 0

def increment():
    global counter
    for _ in range(100_000):
        counter += 1    # read, add 1, write back — three operations, not atomic

threads = [threading.Thread(target=increment) for _ in range(10)]
for t in threads:
    t.start()
for t in threads:
    t.join()

print(counter)    # should be 1,000,000 — will be less, different every run
```

The fix is a Lock — mutual exclusion:

```python
import threading

counter = 0
lock = threading.Lock()

def increment_safe():
    global counter
    for _ in range(100_000):
        with lock:          # only one thread can hold the lock at a time
            counter += 1    # now atomic from other threads' perspective

threads = [threading.Thread(target=increment_safe) for _ in range(10)]
for t in threads:
    t.start()
for t in threads:
    t.join()

print(counter)    # always 1,000,000
```

**Thread-safe data sharing — use Queue:**

```python
from queue import Queue
import threading

def producer(q, items):
    for item in items:
        q.put(item)           # thread-safe
        time.sleep(0.1)
    q.put(None)               # sentinel — signals done

def consumer(q, results):
    while True:
        item = q.get()        # blocks until item available
        if item is None:
            break
        results.append(item * 2)
        q.task_done()

q = Queue()
results = []

prod = threading.Thread(target=producer, args=(q, [1,2,3,4,5]))
cons = threading.Thread(target=consumer, args=(q, results))

prod.start()
cons.start()
prod.join()
cons.join()

print(results)    # [2, 4, 6, 8, 10]
```

`Queue` is the safest way to share data between threads. One thread puts items in, another takes them out — no locks needed, no race conditions.

---

## Multiprocessing — true parallelism for CPU-bound work

Each process gets its own Python interpreter and its own GIL. True parallel execution on multiple CPU cores.

```python
import multiprocessing
import time
import math

def is_prime(n):
    """CPU-intensive — checks if n is prime."""
    if n < 2:
        return False
    for i in range(2, int(math.sqrt(n)) + 1):
        if n % i == 0:
            return False
    return True

def count_primes(limit):
    return sum(1 for n in range(2, limit) if is_prime(n))

numbers = [10_000_000, 10_000_001, 10_000_002, 10_000_003]

# Sequential
start = time.perf_counter()
results = [count_primes(n) for n in numbers]
print(f"Sequential: {time.perf_counter() - start:.2f}s")

# Multiprocessing
start = time.perf_counter()
with multiprocessing.Pool() as pool:    # uses all CPU cores by default
    results = pool.map(count_primes, numbers)
print(f"Multiprocessing: {time.perf_counter() - start:.2f}s")
# roughly 4x faster on a 4-core machine
```

**ProcessPoolExecutor — same API as ThreadPoolExecutor:**

```python
from concurrent.futures import ProcessPoolExecutor

with ProcessPoolExecutor(max_workers=4) as executor:
    results = list(executor.map(count_primes, numbers))
```

**Important multiprocessing constraints:**

```python
# On Windows and macOS, multiprocessing requires this guard
if __name__ == "__main__":
    with multiprocessing.Pool() as pool:
        results = pool.map(count_primes, numbers)

# Functions must be picklable — defined at module level, not lambdas
# This fails:
with Pool() as p:
    results = p.map(lambda x: x**2, [1,2,3])    # PicklingError

# This works:
def square(x):
    return x**2

with Pool() as p:
    results = p.map(square, [1,2,3])
```

---

## asyncio — cooperative concurrency

`async/await` is a third model. Instead of OS-managed threads, a single thread runs an event loop that switches between tasks at `await` points.

```python
import asyncio
import aiohttp    # async HTTP client
import time

# pip install aiohttp

async def fetch(session, url):
    """Async function — can be suspended at await points."""
    async with session.get(url) as response:
        data = await response.json()    # suspends here while waiting
        return url, response.status, data

async def fetch_all(urls):
    """Fetch all URLs concurrently in a single thread."""
    async with aiohttp.ClientSession() as session:
        tasks = [fetch(session, url) for url in urls]
        results = await asyncio.gather(*tasks)    # run all concurrently
    return results

URLS = [
    "https://jsonplaceholder.typicode.com/posts/1",
    "https://jsonplaceholder.typicode.com/posts/2",
    "https://jsonplaceholder.typicode.com/posts/3",
    "https://jsonplaceholder.typicode.com/users/1",
    "https://jsonplaceholder.typicode.com/users/2",
]

start = time.perf_counter()
results = asyncio.run(fetch_all(URLS))
print(f"Async: {time.perf_counter() - start:.2f}s")

for url, status, data in results:
    print(f"{status} {url}")
```

---

## async/await — the mechanics

```python
import asyncio

# async def creates a coroutine function
async def say_hello(name, delay):
    print(f"Starting {name}")
    await asyncio.sleep(delay)    # suspends — event loop runs other coroutines
    print(f"Done {name}")
    return f"Result from {name}"

# await suspends the current coroutine until the awaited thing completes
# only works inside async functions

# Running a single coroutine
result = asyncio.run(say_hello("Alice", 1))

# Running multiple coroutines concurrently
async def main():
    # Sequential — one after the other
    await say_hello("Alice", 1)    # waits 1s
    await say_hello("Bob", 1)      # then waits 1s
    # total: ~2s

    # Concurrent — all at once
    results = await asyncio.gather(
        say_hello("Alice", 1),
        say_hello("Bob", 1),
        say_hello("Charlie", 1),
    )
    # total: ~1s
    print(results)    # ['Result from Alice', 'Result from Bob', 'Result from Charlie']

asyncio.run(main())
```

**Tasks — fire and forget:**

```python
async def background_task(name):
    await asyncio.sleep(2)
    print(f"{name} completed in background")

async def main():
    # Create a task — starts running immediately, we don't wait for it
    task = asyncio.create_task(background_task("cleanup"))

    # Do other work while the task runs
    await asyncio.sleep(0.5)
    print("Doing other work...")
    await asyncio.sleep(0.5)
    print("More work...")

    # Wait for the task to finish when we need it
    await task

asyncio.run(main())
```

**Handling exceptions in gather:**

```python
async def might_fail(n):
    if n == 2:
        raise ValueError(f"Task {n} failed")
    return n * 10

async def main():
    # Default — first exception cancels everything
    try:
        results = await asyncio.gather(
            might_fail(1),
            might_fail(2),
            might_fail(3),
        )
    except ValueError as e:
        print(f"One task failed: {e}")

    # return_exceptions=True — collect exceptions as values
    results = await asyncio.gather(
        might_fail(1),
        might_fail(2),
        might_fail(3),
        return_exceptions=True
    )
    for result in results:
        if isinstance(result, Exception):
            print(f"Failed: {result}")
        else:
            print(f"OK: {result}")

asyncio.run(main())
```

---

## Timeouts — essential for any concurrent code

```python
import asyncio

async def slow_operation():
    await asyncio.sleep(10)
    return "done"

async def main():
    try:
        result = await asyncio.wait_for(slow_operation(), timeout=2.0)
    except asyncio.TimeoutError:
        print("Operation timed out")

asyncio.run(main())


# Threading timeout with future
from concurrent.futures import ThreadPoolExecutor, TimeoutError

with ThreadPoolExecutor() as executor:
    future = executor.submit(slow_blocking_function)
    try:
        result = future.result(timeout=5)
    except TimeoutError:
        print("Timed out")
```

---

## Semaphores — limiting concurrency

Firing 1000 concurrent requests at an API will get you rate-limited or banned. Semaphores limit how many coroutines run simultaneously.

```python
import asyncio
import aiohttp

async def fetch_with_limit(session, url, semaphore):
    async with semaphore:    # only N coroutines can be here at once
        async with session.get(url) as response:
            return await response.json()

async def fetch_all_limited(urls, max_concurrent=10):
    semaphore = asyncio.Semaphore(max_concurrent)
    async with aiohttp.ClientSession() as session:
        tasks = [fetch_with_limit(session, url, semaphore) for url in urls]
        return await asyncio.gather(*tasks)

# 1000 URLs, but only 10 running at a time
urls = [f"https://jsonplaceholder.typicode.com/posts/{i}" for i in range(1, 101)]
results = asyncio.run(fetch_all_limited(urls, max_concurrent=10))
print(f"Fetched {len(results)} posts")
```

---

## Choosing the right model

```
What are you waiting on?
│
├── Network requests, file I/O, database queries
│   │
│   ├── Using async-compatible libraries (aiohttp, asyncpg, etc.)?
│   │   └── asyncio ✓  — most efficient, single thread
│   │
│   └── Using sync libraries (requests, psycopg2, etc.)?
│       └── threading / ThreadPoolExecutor ✓
│
└── CPU computation (math, image processing, parsing)
    └── multiprocessing / ProcessPoolExecutor ✓


How many tasks?
├── Few tasks with complex coordination  → threading with Queue
├── Many independent tasks              → ThreadPoolExecutor or asyncio.gather
└── CPU-intensive batch work            → ProcessPoolExecutor
```

**Real numbers to calibrate:**

- Threads: good up to ~100 concurrent tasks, overhead per thread ~1MB memory
- asyncio: good up to tens of thousands of concurrent connections
- Processes: limited by CPU core count, overhead per process ~50MB memory

---

## A practical async pattern — rate-limited API scraper

```python
import asyncio
import aiohttp
import time
from dataclasses import dataclass
from typing import Optional


@dataclass
class FetchResult:
    url: str
    status: Optional[int]
    data: Optional[dict]
    error: Optional[str]
    elapsed: float


async def fetch_one(session, url, semaphore):
    """Fetch one URL with error handling."""
    start = time.perf_counter()
    async with semaphore:
        try:
            async with session.get(url, timeout=aiohttp.ClientTimeout(total=10)) as resp:
                data = await resp.json()
                return FetchResult(
                    url=url,
                    status=resp.status,
                    data=data,
                    error=None,
                    elapsed=time.perf_counter() - start
                )
        except asyncio.TimeoutError:
            return FetchResult(url, None, None, "Timeout", time.perf_counter() - start)
        except Exception as e:
            return FetchResult(url, None, None, str(e), time.perf_counter() - start)


async def fetch_many(urls, max_concurrent=10):
    """Fetch all URLs with rate limiting and error handling."""
    semaphore = asyncio.Semaphore(max_concurrent)
    async with aiohttp.ClientSession() as session:
        tasks = [fetch_one(session, url, semaphore) for url in urls]
        results = await asyncio.gather(*tasks)

    successful = [r for r in results if r.error is None]
    failed = [r for r in results if r.error is not None]

    print(f"Completed: {len(successful)} OK, {len(failed)} failed")
    if failed:
        for r in failed:
            print(f"  FAILED: {r.url} — {r.error}")

    return results


# Usage
urls = [f"https://jsonplaceholder.typicode.com/posts/{i}" for i in range(1, 21)]
start = time.perf_counter()
results = asyncio.run(fetch_many(urls, max_concurrent=5))
print(f"Total time: {time.perf_counter() - start:.2f}s")
```

---

## The mental model to carry forward

Concurrency is about managing waiting time. Three tools, three use cases:

**Threading** — simple, works with existing sync code, GIL limits CPU parallelism but not I/O. Use `ThreadPoolExecutor`. Always protect shared state with locks or use `Queue`.

**Multiprocessing** — true parallelism, separate memory spaces, higher overhead. Use `ProcessPoolExecutor`. Requires `if __name__ == "__main__"` guard. Functions must be picklable.

**asyncio** — single thread, cooperative multitasking, scales to thousands of connections. Requires async-compatible libraries throughout. Best performance for I/O-heavy code when you control the full stack.

The most common mistake: reaching for threads when asyncio is cleaner, or using asyncio when a simple `ThreadPoolExecutor` would do. Start with `ThreadPoolExecutor` — it works with all existing libraries and is straightforward to reason about. Graduate to asyncio when you're building something that needs thousands of concurrent connections.

---

Day 20 is databases — SQLite, basic SQL, and using Python to store and query structured data. Ready when you are.

[[Intermediate Power]]