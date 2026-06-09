### What a list actually is

A list is an ordered, mutable collection of objects. Mutable means you can change it after creation — add, remove, reorder items in place.

python

```python
# Lists hold anything — mixed types are valid
numbers = [1, 2, 3, 4, 5]
names = ["Alice", "Bob", "Charlie"]
mixed = [1, "hello", 3.14, True, None]
nested = [[1, 2], [3, 4], [5, 6]]
empty = []
```

In memory, a list stores references to objects, not the objects themselves. This has real consequences we'll see shortly.

---

### Indexing and slicing — same rules as strings

python

```python
fruits = ["apple", "banana", "cherry", "date", "elderberry"]

# Indexing
print(fruits[0])    # apple
print(fruits[-1])   # elderberry
print(fruits[-2])   # date

# Slicing [start:stop:step] — stop is always excluded
print(fruits[1:3])   # ['banana', 'cherry']
print(fruits[:3])    # ['apple', 'banana', 'cherry']
print(fruits[2:])    # ['cherry', 'date', 'elderberry']
print(fruits[::2])   # ['apple', 'cherry', 'elderberry'] — every second item
print(fruits[::-1])  # reversed list

# Unlike strings, lists are mutable — you can assign to an index
fruits[0] = "avocado"
print(fruits)        # ['avocado', 'banana', 'cherry', 'date', 'elderberry']
```

---

### The core list methods

python

```python
items = [3, 1, 4, 1, 5]

# Adding
items.append(9)          # adds to end — [3, 1, 4, 1, 5, 9]
items.insert(0, 99)      # insert at index — [99, 3, 1, 4, 1, 5, 9]
items.extend([7, 8])     # add multiple items — modifies in place
items + [7, 8]           # creates a NEW list, doesn't modify items

# Removing
items.remove(1)          # removes FIRST occurrence of value 1
popped = items.pop()     # removes and RETURNS last item
popped = items.pop(0)    # removes and returns item at index 0
del items[0]             # removes item at index, no return value
items.clear()            # empties the list

# Searching
nums = [10, 20, 30, 20, 40]
print(nums.index(20))    # 1 — index of first occurrence
print(nums.count(20))    # 2 — how many times 20 appears
print(20 in nums)        # True
print(99 in nums)        # False

# Sorting
nums = [3, 1, 4, 1, 5, 9, 2, 6]
nums.sort()              # sorts IN PLACE, returns None
print(nums)              # [1, 1, 2, 3, 4, 5, 6, 9]

sorted_nums = sorted(nums)     # returns a NEW sorted list, original unchanged
nums.sort(reverse=True)        # descending order
words = ["banana", "apple", "cherry"]
words.sort(key=len)            # sort by string length
```

`sort()` modifies the list in place and returns `None`. This trips people up constantly:

python

```python
result = nums.sort()     # common mistake
print(result)            # None — you lost your list reference
```

---

### The reference trap — the most important thing on this page

python

```python
a = [1, 2, 3]
b = a              # b points to the SAME list, not a copy

b.append(4)
print(a)           # [1, 2, 3, 4] — a changed too!
print(b)           # [1, 2, 3, 4]
print(a is b)      # True — same object in memory
```

This catches everyone. When you assign a list to another variable, you don't copy the list — both variables point to the same object.

**How to actually copy a list:**

python

```python
a = [1, 2, 3]

b = a.copy()       # shallow copy
b = a[:]           # shallow copy via slice
b = list(a)        # shallow copy via constructor

b.append(4)
print(a)           # [1, 2, 3] — unchanged
print(b)           # [1, 2, 3, 4]
```

**Shallow vs deep copy — this matters with nested lists:**

python

```python
import copy

original = [[1, 2], [3, 4]]
shallow = original.copy()

shallow[0].append(99)
print(original)    # [[1, 2, 99], [3, 4]] — inner list still shared!

deep = copy.deepcopy(original)
deep[0].append(99)
print(original)    # [[1, 2, 99], [3, 4]] — untouched
```

Shallow copy creates a new list but the elements inside are still references. For lists of simple values (strings, numbers) shallow copy is fine. For nested structures, use `deepcopy`.

---

### Iterating over lists

python

```python
fruits = ["apple", "banana", "cherry"]

# Basic iteration
for fruit in fruits:
    print(fruit)

# When you need the index too
for i, fruit in enumerate(fruits):
    print(f"{i}: {fruit}")

# enumerate starts at 0 by default, but you can change it
for i, fruit in enumerate(fruits, start=1):
    print(f"{i}. {fruit}")

# Iterate over two lists together
prices = [1.20, 0.50, 2.00]
for fruit, price in zip(fruits, prices):
    print(f"{fruit}: ${price}")

# zip stops at the shortest list
# zip_longest from itertools continues to the end of the longest
```

Use `enumerate` whenever you need both the item and its position. Never do `for i in range(len(fruits))` — that's C-style thinking in Python.

---

### List unpacking

python

```python
first, second, third = [1, 2, 3]
print(first)    # 1

# Star unpacking — grab the rest
first, *rest = [1, 2, 3, 4, 5]
print(first)    # 1
print(rest)     # [2, 3, 4, 5]

*start, last = [1, 2, 3, 4, 5]
print(start)    # [1, 2, 3, 4]
print(last)     # 5

first, *middle, last = [1, 2, 3, 4, 5]
print(middle)   # [2, 3, 4]

# Swap two variables — Pythonic way
a, b = 1, 2
a, b = b, a
print(a, b)     # 2 1
```

---

### Useful list operations

python

```python
nums = [3, 1, 4, 1, 5, 9]

print(len(nums))       # 6
print(sum(nums))       # 23
print(min(nums))       # 1
print(max(nums))       # 9

# Check membership — O(n) for lists
print(4 in nums)       # True

# Flatten a nested list (one level)
nested = [[1, 2], [3, 4], [5, 6]]
flat = [x for sublist in nested for x in sublist]
print(flat)            # [1, 2, 3, 4, 5, 6]

# Repetition
zeros = [0] * 5        # [0, 0, 0, 0, 0]
```

---

### Tuples — immutable sequences

A tuple is like a list but immutable — once created, it cannot be changed. No append, no remove, no sort in place.

python

```python
point = (10, 20)
rgb = (255, 128, 0)
single = (42,)          # trailing comma required for single-element tuple
also_tuple = 1, 2, 3   # parentheses are optional — the comma makes it a tuple
empty = ()

print(type(point))      # <class 'tuple'>
print(point[0])         # 10 — indexing works the same
print(point[-1])        # 20

# This fails — tuples are immutable
point[0] = 99           # TypeError
```

**When to use a tuple vs a list:**

python

```python
# Tuple — fixed structure, heterogeneous data, the position has meaning
person = ("Alice", 30, "Engineer")    # name, age, job — order matters conceptually
coordinates = (40.7128, -74.0060)     # lat, lon — makes no sense to append to this

# List — variable length, homogeneous data, order is incidental
names = ["Alice", "Bob", "Charlie"]  # a collection of the same type of thing
scores = [95, 87, 92, 78]            # items you'll add/remove/sort
```

The rule: if the data structure represents a single entity with fixed fields, use a tuple. If it's a collection of similar items you'll manipulate, use a list.

**Tuples are faster and safer:**

python

```python
import timeit

# Tuple creation is faster than list creation
# Tuples signal to other developers: "this data won't change"
# Tuples can be used as dictionary keys — lists cannot

location = (40.7128, -74.0060)
data = {location: "New York"}   # valid — tuples are hashable
data = {[40.7, -74.0]: "NY"}    # TypeError — lists are not hashable
```

**Tuple unpacking — used everywhere in Python:**

python

```python
point = (10, 20)
x, y = point
print(x, y)    # 10 20

# Functions commonly return tuples
def min_max(numbers):
    return min(numbers), max(numbers)   # returns a tuple

low, high = min_max([3, 1, 4, 1, 5, 9])
print(low, high)    # 1 9

# Named tuples — tuples with field names (more readable)
from collections import namedtuple

Point = namedtuple("Point", ["x", "y"])
p = Point(10, 20)
print(p.x)          # 10 — access by name
print(p.y)          # 20
print(p[0])         # 10 — still works by index
```

---

### The mental model to carry forward

**List** = a dynamic container. Items can come and go. Use when the collection itself is the thing you're working with.

**Tuple** = a record with fixed slots. Each position means something specific. Use when you're grouping related values that belong together.

Both are sequences — they share indexing, slicing, `len()`, `in`, `for` loops, and unpacking. The only real difference is mutability.

One practical thing to internalize: **most Python functions that take a sequence don't care if you pass a list or tuple.** `sum()`, `min()`, `max()`, `sorted()`, `zip()`, `enumerate()` — they all work on both. The distinction matters when you need to modify the collection.

---
[[Foundation]]