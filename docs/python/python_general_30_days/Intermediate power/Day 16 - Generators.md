#### Lazy Evaluation and Memory-Efficient Pipelines

## The problem generators solve

```python
# Load a million numbers into memory all at once
def get_numbers(n):
    result = []
    for i in range(n):
        result.append(i * 2)
    return result

numbers = get_numbers(1_000_000)    # allocates ~8MB just for this list
total = sum(numbers)
```

You don't need all million numbers at once. You need them one at a time to compute the sum. Generators give you exactly that — values produced one at a time, on demand, using almost no memory.

```python
def generate_numbers(n):
    for i in range(n):
        yield i * 2             # produces one value, then pauses

numbers = generate_numbers(1_000_000)   # no computation yet, no memory used
total = sum(numbers)                    # pulls values one at a time
```

Same result. Negligible memory. This is lazy evaluation — compute only what's needed, only when it's needed.

---

## How `yield` works — the execution model

`yield` transforms a function into a generator function. Calling it returns a generator object without executing the body at all.

```python
def count_up(start, stop):
    print(f"Starting from {start}")
    current = start
    while current <= stop:
        print(f"About to yield {current}")
        yield current
        print(f"Resumed after yielding {current}")
        current += 1
    print("Generator exhausted")

gen = count_up(1, 3)    # nothing printed yet — body hasn't run
print("Generator created")

print(next(gen))    # runs until first yield, pauses
print(next(gen))    # resumes from after yield, runs until next yield
print(next(gen))    # resumes again
# next(gen)         # StopIteration — generator is exhausted

# Output:
# Generator created
# Starting from 1
# About to yield 1
# 1
# Resumed after yielding 1
# About to yield 2
# 2
# Resumed after yielding 2
# About to yield 3
# 3
# Resumed after yielding 3
# Generator exhausted
```

The generator function's local variables, loop counters, and execution position are all preserved between `next()` calls. This is the suspension mechanism — the function literally pauses at `yield` and resumes from that exact point.

---

## Iterating generators

```python
def squares(n):
    for i in range(n):
        yield i ** 2

# For loop calls next() automatically, handles StopIteration
for sq in squares(5):
    print(sq)    # 0, 1, 4, 9, 16

# Unpack into a list when you need all values
result = list(squares(5))    # [0, 1, 4, 9, 16]

# Use directly with any function that accepts an iterable
print(sum(squares(5)))       # 30
print(max(squares(5)))       # 16
print(list(filter(lambda x: x > 5, squares(10))))    # [9, 16, 25, ...]

# Generators are single-use — once exhausted, they're done
gen = squares(3)
print(list(gen))    # [0, 1, 4]
print(list(gen))    # [] — already exhausted
```

---

## Generator expressions — inline generators

```python
# List comprehension — builds entire list in memory
squares_list = [x**2 for x in range(1_000_000)]

# Generator expression — lazy, no memory allocation
squares_gen = (x**2 for x in range(1_000_000))

# Use generator expressions directly inside function calls
# No extra parentheses needed — the function call's parens serve double duty
total = sum(x**2 for x in range(1_000_000))
maximum = max(x**2 for x in range(100))
filtered = list(x**2 for x in range(20) if x % 2 == 0)

# Chaining generator expressions — pipeline with no intermediate lists
data = range(1_000_000)
result = sum(
    x**2
    for x in data
    if x % 3 == 0
    if x % 5 == 0
)
```

---

## Infinite generators — sequences that never end

```python
def integers_from(start=0):
    """Yield integers starting from start, forever."""
    n = start
    while True:
        yield n
        n += 1

def fibonacci():
    """Yield Fibonacci numbers forever."""
    a, b = 0, 1
    while True:
        yield a
        a, b = b, a + b

def cycle(iterable):
    """Cycle through an iterable forever."""
    items = list(iterable)
    while True:
        for item in items:
            yield item

# Use itertools.islice to take the first N values from an infinite generator
from itertools import islice

first_10 = list(islice(integers_from(1), 10))
print(first_10)    # [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]

first_10_fib = list(islice(fibonacci(), 10))
print(first_10_fib)    # [0, 1, 1, 2, 3, 5, 8, 13, 21, 34]

# Cycle through status labels
statuses = cycle(["pending", "processing", "done"])
for _ in range(7):
    print(next(statuses))
# pending, processing, done, pending, processing, done, pending
```

---

## yield from — delegating to sub-generators

```python
def inner():
    yield 1
    yield 2
    yield 3

def outer_manual():
    """Without yield from — verbose."""
    for value in inner():
        yield value
    yield 4
    yield 5

def outer():
    """With yield from — clean delegation."""
    yield from inner()    # delegates to inner(), yields all its values
    yield 4
    yield 5

print(list(outer()))    # [1, 2, 3, 4, 5]

# yield from works with any iterable, not just generators
def flatten(nested):
    """Flatten a nested list of arbitrary depth."""
    for item in nested:
        if isinstance(item, list):
            yield from flatten(item)    # recurse into sublists
        else:
            yield item

data = [1, [2, 3, [4, 5]], 6, [7, [8, [9]]]]
print(list(flatten(data)))    # [1, 2, 3, 4, 5, 6, 7, 8, 9]


# yield from also passes return values from sub-generators
def sub():
    yield 1
    yield 2
    return "sub done"    # return value of a generator

def main():
    result = yield from sub()    # result captures the return value
    print(f"Sub generator said: {result}")
    yield 3

print(list(main()))
# Sub generator said: sub done
# [1, 2, 3]
```

---

## Sending values into generators — two-way communication

Generators are not just sources of data — you can send values back into them with `.send()`.

```python
def accumulator():
    """Receive numbers and yield the running total."""
    total = 0
    while True:
        value = yield total    # yield sends total out, receives value in
        if value is None:
            break
        total += value

gen = accumulator()
next(gen)         # must prime the generator — advance to first yield
                  # returns 0 (initial total)

print(gen.send(10))    # sends 10 in, yields 10 back
print(gen.send(20))    # sends 20 in, yields 30 back
print(gen.send(5))     # sends 5 in, yields 35 back


def logger():
    """A generator that acts as a coroutine — receives log messages."""
    log = []
    while True:
        message = yield len(log)    # yield current count, receive next message
        if message is None:
            return log
        log.append(message)
        print(f"Logged: {message!r}")

log_gen = logger()
next(log_gen)                    # prime
log_gen.send("User logged in")
log_gen.send("Payment processed")
log_gen.send("Order shipped")
try:
    log_gen.send(None)           # triggers return
except StopIteration as e:
    final_log = e.value          # return value is in e.value
    print(final_log)
```

`.send()` is the foundation of Python's `async/await` — coroutines are built on this exact mechanism.

---

## Generator methods — throw and close

```python
def resilient_generator():
    """A generator that handles exceptions thrown into it."""
    for i in range(10):
        try:
            yield i
        except ValueError as e:
            print(f"Caught: {e}, continuing...")
            yield -1    # yield a sentinel value on error

gen = resilient_generator()
print(next(gen))              # 0
print(next(gen))              # 1
print(gen.throw(ValueError, "bad input"))  # Caught: bad input, continuing... → -1
print(next(gen))              # 2


def cleanup_generator():
    """A generator that cleans up when closed."""
    try:
        for i in range(100):
            yield i
    finally:
        print("Cleanup: releasing resources")    # runs when generator is closed

gen = cleanup_generator()
print(next(gen))    # 0
print(next(gen))    # 1
gen.close()         # throws GeneratorExit, triggers finally block
# Cleanup: releasing resources
```

`finally` in a generator runs when it's garbage collected, closed explicitly, or finishes naturally. This makes generators safe for resource management.

---

## Real use cases — where generators shine in production

**Processing large files line by line:**

```python
def read_large_file(filepath):
    """Read a file without loading it all into memory."""
    with open(filepath, encoding="utf-8") as f:
        for line in f:
            yield line.strip()

def parse_csv_rows(filepath):
    """Parse CSV rows lazily."""
    import csv
    with open(filepath, encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
            yield row

# Process a 10GB log file with constant memory usage
def find_errors(logfile):
    for line in read_large_file(logfile):
        if "ERROR" in line:
            yield line

error_count = sum(1 for _ in find_errors("app.log"))
```

**Pagination — fetching data in chunks:**

```python
import time

def paginate_api(base_url, page_size=100):
    """Fetch all pages from a paginated API."""
    page = 1
    while True:
        # In real code: response = requests.get(f"{base_url}?page={page}&size={page_size}")
        # Simulating:
        if page > 3:    # simulated end of data
            break
        print(f"Fetching page {page}")
        yield from [f"item_{page}_{i}" for i in range(page_size)]
        page += 1
        time.sleep(0.1)    # rate limiting

# Caller doesn't think about pagination at all
for item in paginate_api("https://api.example.com/users"):
    process(item)
```

**Data transformation pipelines:**

```python
from pathlib import Path
import csv

def read_transactions(filepath):
    """Stage 1 — read raw rows."""
    with open(filepath, encoding="utf-8") as f:
        yield from csv.DictReader(f)

def parse_amounts(rows):
    """Stage 2 — convert strings to numbers, skip invalid."""
    for row in rows:
        try:
            row["amount"] = float(row["amount"])
            row["tax"] = float(row["tax"])
            yield row
        except (ValueError, KeyError):
            continue    # skip malformed rows

def filter_large(rows, threshold=1000):
    """Stage 3 — keep only large transactions."""
    for row in rows:
        if row["amount"] > threshold:
            yield row

def add_total(rows):
    """Stage 4 — add computed field."""
    for row in rows:
        row["total"] = row["amount"] + row["tax"]
        yield row

def process_transactions(filepath):
    """Compose the pipeline — nothing runs until you iterate."""
    rows = read_transactions(filepath)
    rows = parse_amounts(rows)
    rows = filter_large(rows, threshold=1000)
    rows = add_total(rows)
    return rows

# Each stage is a generator — data flows through one row at a time
# The entire pipeline uses O(1) memory regardless of file size
for transaction in process_transactions("transactions.csv"):
    print(f"{transaction['id']}: ${transaction['total']:.2f}")
```

This pipeline pattern is one of the most powerful things in Python. Each stage is a simple generator. Composing them creates a data processing pipeline that handles arbitrarily large datasets with constant memory.

**Generating test data:**

```python
import random
import string
from itertools import count

def generate_users(n=None):
    """Generate n users, or infinite users if n is None."""
    counter = count(1)
    generated = 0
    while n is None or generated < n:
        user_id = next(counter)
        yield {
            "id": user_id,
            "name": f"User_{user_id}",
            "email": f"user{user_id}@example.com",
            "score": random.randint(0, 100),
            "active": random.choice([True, False])
        }
        generated += 1

# Generate exactly 1000 test users
test_users = list(generate_users(1000))

# Or process lazily
for user in generate_users(10_000):
    if user["score"] > 90:
        notify_high_scorer(user)
```

---

## itertools — the generator toolkit

`itertools` gives you production-ready generators for common patterns:

```python
import itertools

# chain — iterate multiple iterables sequentially
for item in itertools.chain([1,2], [3,4], [5,6]):
    print(item)    # 1 2 3 4 5 6

# islice — take a slice from any iterator
first_5 = list(itertools.islice(fibonacci(), 5))

# takewhile / dropwhile — conditional stopping
from itertools import takewhile, dropwhile
data = [2, 4, 6, 7, 8, 10]
evens_until_odd = list(takewhile(lambda x: x % 2 == 0, data))
print(evens_until_odd)    # [2, 4, 6] — stops at first odd

# groupby — group consecutive elements
from itertools import groupby
data = [("A", 1), ("A", 2), ("B", 3), ("B", 4), ("A", 5)]
for key, group in groupby(data, key=lambda x: x[0]):
    print(key, list(group))
# A [('A', 1), ('A', 2)]
# B [('B', 3), ('B', 4)]
# A [('A', 5)]
# Note: groupby only groups consecutive elements — sort first if needed

# product — cartesian product
for combo in itertools.product([1,2], ["a","b"]):
    print(combo)    # (1,'a'), (1,'b'), (2,'a'), (2,'b')

# combinations / permutations
list(itertools.combinations([1,2,3], 2))     # [(1,2),(1,3),(2,3)]
list(itertools.permutations([1,2,3], 2))     # [(1,2),(1,3),(2,1),(2,3),(3,1),(3,2)]

# accumulate — running totals
from itertools import accumulate
import operator
print(list(accumulate([1,2,3,4,5])))                     # [1, 3, 6, 10, 15]
print(list(accumulate([1,2,3,4,5], operator.mul)))        # [1, 2, 6, 24, 120]
```

---

## The mental model to carry forward

A generator is a function that remembers where it was. Every time you call `next()`, it runs until the next `yield`, hands you the value, and freezes until you ask for more.

**Use a generator when:**

- The dataset is larger than memory
- You only need to iterate once
- You're building a pipeline of transformations
- The sequence is infinite
- Values are expensive to compute and you might not need all of them

**Use a list when:**

- You need to iterate multiple times
- You need random access by index
- You need `len()`
- The data is small and fits comfortably in memory

**The pipeline pattern is the most important takeaway.** Each transformation stage is a generator. Data flows through one item at a time. Memory usage stays constant regardless of dataset size. This is how real data processing systems are built in Python.

---

Day 17 is APIs and JSON — `requests`, calling real public APIs, handling responses, and building tools that talk to the outside world. Ready when you are.

[[Intermediate Power]]