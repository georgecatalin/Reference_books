#### Comprehensions & Functional Tools — Writing Less, Doing More

## Why comprehensions exist

The most common operation in Python is transforming a collection — filter it, map it, reshape it. The loop-and-append pattern works but it's verbose. Comprehensions express the same thing in one line that reads like English.

```python
# Loop approach
squares = []
for n in range(10):
    squares.append(n ** 2)

# Comprehension approach
squares = [n ** 2 for n in range(10)]
```

Same result. The comprehension is not just shorter — it's faster, because Python optimizes list comprehensions internally.

---

## List comprehensions — the full syntax

```python
# [expression for item in iterable]
squares = [n ** 2 for n in range(10)]

# [expression for item in iterable if condition]
evens = [n for n in range(20) if n % 2 == 0]

# [expression for item in iterable if condition else other]
labels = ["even" if n % 2 == 0 else "odd" for n in range(6)]
print(labels)    # ['even', 'odd', 'even', 'odd', 'even', 'odd']

# Transforming strings
names = ["  alice  ", "  BOB  ", "charlie  "]
cleaned = [name.strip().title() for name in names]
print(cleaned)    # ['Alice', 'Bob', 'Charlie']

# Filtering and transforming together
scores = [85, 42, 91, 67, 55, 78, 95, 38]
passing = [s for s in scores if s >= 60]
print(passing)    # [85, 91, 67, 78, 95]

# Calling functions in comprehensions
def double(x):
    return x * 2

doubled = [double(n) for n in range(5)]
print(doubled)    # [0, 2, 4, 6, 8]
```

**Nested comprehensions — flattening structures:**

```python
matrix = [[1, 2, 3], [4, 5, 6], [7, 8, 9]]

# Flatten a 2D list into 1D
flat = [val for row in matrix for val in row]
print(flat)    # [1, 2, 3, 4, 5, 6, 7, 8, 9]

# Read it as: for each row, for each val in row, take val

# Transpose a matrix
transposed = [[row[i] for row in matrix] for i in range(3)]
print(transposed)    # [[1,4,7], [2,5,8], [3,6,9]]
```

**When NOT to use a list comprehension:**

```python
# Too complex — use a regular loop instead
# This is unreadable:
result = [process(item) for sublist in data for item in sublist
          if item.is_valid() and item.value > threshold and not item.flagged]

# Break it into a loop:
result = []
for sublist in data:
    for item in sublist:
        if item.is_valid() and item.value > threshold and not item.flagged:
            result.append(process(item))

# Side effects don't belong in comprehensions
# Wrong — comprehension for its side effects:
[print(item) for item in items]    # works, but wrong use

# Right — use a loop for side effects:
for item in items:
    print(item)
```

The test: can you read it in one breath and understand it? If not, use a loop.

---

## Dict comprehensions

```python
# {key_expr: value_expr for item in iterable}
squares = {n: n**2 for n in range(6)}
print(squares)    # {0: 0, 1: 1, 2: 4, 3: 9, 4: 16, 5: 25}

# Transform an existing dict
prices = {"apple": 1.20, "banana": 0.50, "cherry": 2.00}
discounted = {item: round(price * 0.9, 2) for item, price in prices.items()}
print(discounted)    # {'apple': 1.08, 'banana': 0.45, 'cherry': 1.8}

# Filter a dict
expensive = {item: price for item, price in prices.items() if price > 1.0}
print(expensive)    # {'apple': 1.2, 'cherry': 2.0}

# Invert a dict — swap keys and values
original = {"a": 1, "b": 2, "c": 3}
inverted = {v: k for k, v in original.items()}
print(inverted)    # {1: 'a', 2: 'b', 3: 'c'}

# Build a dict from two lists
keys = ["name", "age", "city"]
values = ["Alice", 30, "London"]
person = {k: v for k, v in zip(keys, values)}
print(person)    # {'name': 'Alice', 'age': 30, 'city': 'London'}

# Group items — build dict of lists
words = ["apple", "banana", "avocado", "blueberry", "cherry", "apricot"]
by_letter = {}
for word in words:
    letter = word[0]
    by_letter.setdefault(letter, []).append(word)
print(by_letter)
# {'a': ['apple', 'avocado', 'apricot'], 'b': ['banana', 'blueberry'], 'c': ['cherry']}

# Cleaner with defaultdict
from collections import defaultdict
by_letter = defaultdict(list)
for word in words:
    by_letter[word[0]].append(word)
```

---

## Set comprehensions

```python
# {expression for item in iterable}
unique_lengths = {len(word) for word in ["apple", "banana", "fig", "date", "kiwi"]}
print(unique_lengths)    # {3, 4, 5, 6} — order not guaranteed

# Deduplicate while transforming
emails = ["Alice@Example.com", "bob@GMAIL.com", "alice@example.com"]
unique_emails = {email.lower() for email in emails}
print(unique_emails)    # {'alice@example.com', 'bob@gmail.com'}
```

---

## Generator expressions — lazy comprehensions

A generator expression looks like a list comprehension but with parentheses. The critical difference: it doesn't build the list in memory — it produces values one at a time on demand.

```python
# List comprehension — builds entire list in memory immediately
squares_list = [n ** 2 for n in range(1_000_000)]    # allocates memory for 1M items

# Generator expression — computes each value only when needed
squares_gen = (n ** 2 for n in range(1_000_000))     # uses almost no memory

# Both work the same way with sum, max, min, any, all
print(sum(n ** 2 for n in range(1_000_000)))    # no [] needed inside sum()

# Checking conditions lazily — stops at first match
data = [4, 8, 15, 16, 23, 42]
has_large = any(n > 20 for n in data)     # stops at 23, doesn't check 42
all_positive = all(n > 0 for n in data)  # True — checks all

# Generator vs list — when to use which
# Use list when: you need to iterate multiple times, access by index, know the length
# Use generator when: iterating once, large datasets, used inside sum/min/max/any/all
```

---

## map() — apply a function to every item

```python
numbers = [1, 2, 3, 4, 5]

# map(function, iterable) — returns a map object (lazy)
doubled = map(lambda x: x * 2, numbers)
print(list(doubled))    # [2, 4, 6, 8, 10]

# With a named function
def celsius_to_fahrenheit(c):
    return (c * 9/5) + 32

temps_c = [0, 20, 37, 100]
temps_f = list(map(celsius_to_fahrenheit, temps_c))
print(temps_f)    # [32.0, 68.0, 98.6, 212.0]

# map with multiple iterables
a = [1, 2, 3]
b = [10, 20, 30]
sums = list(map(lambda x, y: x + y, a, b))
print(sums)    # [11, 22, 33]

# In modern Python, comprehensions are usually preferred over map:
temps_f = [celsius_to_fahrenheit(c) for c in temps_c]    # more readable
```

---

## filter() — keep items that match a condition

```python
numbers = [1, -3, 5, -2, 8, -7, 4]

# filter(function, iterable) — keeps items where function returns True
positives = list(filter(lambda x: x > 0, numbers))
print(positives)    # [1, 5, 8, 4]

# With None as function — keeps truthy values
mixed = [0, 1, "", "hello", None, [], [1, 2], False, True]
truthy = list(filter(None, mixed))
print(truthy)    # [1, 'hello', [1, 2], True]

# Again, comprehension is often cleaner:
positives = [x for x in numbers if x > 0]
```

---

## functools — tools for working with functions

```python
from functools import reduce, partial, lru_cache

# reduce — apply a function cumulatively to reduce a sequence to one value
from functools import reduce

numbers = [1, 2, 3, 4, 5]
product = reduce(lambda x, y: x * y, numbers)
print(product)    # 120 (1*2*3*4*5)

total = reduce(lambda acc, x: acc + x, numbers, 0)    # 0 is the initial value
# Better: just use sum(numbers) — reduce is for custom accumulation


# partial — fix some arguments of a function, create a new one
def power(base, exponent):
    return base ** exponent

square = partial(power, exponent=2)    # fixes exponent=2
cube = partial(power, exponent=3)

print(square(5))    # 25
print(cube(3))      # 27

# Useful for callbacks and configuration
def log(message, level="INFO", prefix="APP"):
    print(f"[{prefix}][{level}] {message}")

error_log = partial(log, level="ERROR", prefix="SYSTEM")
error_log("Disk full")    # [SYSTEM][ERROR] Disk full


# lru_cache — memoization, cache function results
@lru_cache(maxsize=None)
def fibonacci(n):
    if n < 2:
        return n
    return fibonacci(n-1) + fibonacci(n-2)

print(fibonacci(50))    # instant — cached results
print(fibonacci.cache_info())    # CacheInfo(hits=48, misses=51, ...)
```

---

## sorted() with key — the most practical use of lambdas

```python
# sort by a computed value without modifying the original
people = [
    {"name": "Charlie", "age": 35},
    {"name": "Alice", "age": 30},
    {"name": "Bob", "age": 25},
]

by_age = sorted(people, key=lambda p: p["age"])
by_name = sorted(people, key=lambda p: p["name"])
by_age_desc = sorted(people, key=lambda p: p["age"], reverse=True)

# Sort by multiple criteria — sort by age, then name for ties
data = [("Alice", 30), ("Bob", 25), ("Charlie", 30), ("Diana", 25)]
sorted_data = sorted(data, key=lambda x: (x[1], x[0]))
print(sorted_data)    # [('Bob', 25), ('Diana', 25), ('Alice', 30), ('Charlie', 30)]

# operator.itemgetter — faster than lambda for simple key access
from operator import itemgetter, attrgetter

by_age = sorted(people, key=itemgetter("age"))       # dict key
by_name = sorted(people, key=itemgetter("name"))

# For objects with attributes:
# by_age = sorted(objects, key=attrgetter("age"))
```

---

## Putting it together — data transformation pipelines

This is where comprehensions and functional tools shine — chaining operations on data:

```python
# Raw data from an API or CSV
raw_users = [
    {"name": "  alice smith  ", "age": "30", "score": "85", "active": "true"},
    {"name": "BOB JONES", "age": "seventeen", "score": "92", "active": "false"},
    {"name": "Charlie Brown", "age": "25", "score": "78", "active": "true"},
    {"name": "", "age": "40", "score": "88", "active": "true"},
]

def parse_user(raw):
    """Parse and validate a raw user dict. Returns None if invalid."""
    name = raw["name"].strip().title()
    if not name:
        return None

    try:
        age = int(raw["age"])
    except ValueError:
        return None

    return {
        "name": name,
        "age": age,
        "score": int(raw["score"]),
        "active": raw["active"].lower() == "true"
    }

# Parse all users, filter out failed ones, keep only active
users = [
    user for raw in raw_users
    if (user := parse_user(raw)) is not None    # walrus operator — assign and test
    and user["active"]
]

print(users)
# [{'name': 'Alice Smith', 'age': 30, 'score': 85, 'active': True},
#  {'name': 'Charlie Brown', 'age': 25, 'score': 78, 'active': True}]

# Now transform: get names of users scoring above 80, sorted alphabetically
top_names = sorted(
    [u["name"] for u in users if u["score"] >= 80]
)
print(top_names)    # ['Alice Smith']

# Average score of active users
avg_score = sum(u["score"] for u in users) / len(users)
print(f"Average: {avg_score:.1f}")    # Average: 81.5
```

The walrus operator `:=` (Python 3.8+) assigns a value and uses it in the same expression. It's useful specifically in comprehensions where you want to both transform and filter without calling the function twice.

---

## The mental model to carry forward

Comprehensions, `map`, `filter`, and `reduce` are all expressions of the same idea: **transforming data declaratively** — describing _what_ you want, not _how_ to build it step by step.

**The decision tree:**

```
Transforming a list into a new list?    → list comprehension
Building a dict from a sequence?        → dict comprehension
Deduplicating while transforming?       → set comprehension
Large dataset, iterate once?            → generator expression
Applying one function to everything?    → map() or comprehension
Keeping items that match?               → filter() or comprehension [... if ...]
Reducing to a single value?             → reduce(), sum(), any(), all()
Fixing arguments of a function?         → partial()
Caching expensive function results?     → lru_cache
```

In modern Python, comprehensions are preferred over `map` and `filter` for readability. `map` and `filter` still appear in codebases — you need to read them fluently — but when writing new code, reach for comprehensions first.

---

Day 12 is OOP — classes, objects, `__init__`, methods, and why modeling your task manager as a class is cleaner than passing `tasks` and `next_id` everywhere. Ready when you are.

[[Real Python]]