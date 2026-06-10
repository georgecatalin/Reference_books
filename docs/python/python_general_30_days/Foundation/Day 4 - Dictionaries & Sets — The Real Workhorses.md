
### What a dictionary actually is

A dictionary is an unordered collection of key-value pairs with O(1) lookup. That last part matters — finding a value by key in a dictionary with 10 items takes the same time as finding it in one with 10 million items. Lists can't do that.

python

```python
# Basic creation
person = {"name": "Alice", "age": 30, "city": "London"}
empty = {}
also_dict = dict(name="Alice", age=30)   # alternative syntax

print(type(person))    # <class 'dict'>
```

Under the hood, Python uses a hash table. When you store a key, Python computes a hash of that key and uses it to determine where to store the value in memory. That's why lookup is O(1) — and why keys must be hashable (immutable types only).

---

### Accessing values

python

```python
person = {"name": "Alice", "age": 30, "city": "London"}

# Direct access — raises KeyError if key doesn't exist
print(person["name"])       # Alice
print(person["salary"])     # KeyError — crashes your program

# .get() — safe access, returns None by default
print(person.get("name"))       # Alice
print(person.get("salary"))     # None — no crash
print(person.get("salary", 0))  # 0 — custom default value

# In production code, use .get() unless you're certain the key exists
# KeyError in production = unhandled crash
```

The difference between `dict["key"]` and `dict.get("key")` is one of the most practically important things on this page. Use `.get()` when the key might not exist. Use `[]` when its absence should be an error.

---

### Modifying dictionaries

python

```python
person = {"name": "Alice", "age": 30}

# Add or update — same syntax
person["city"] = "London"       # adds new key
person["age"] = 31              # updates existing key
print(person)    # {'name': 'Alice', 'age': 31, 'city': 'London'}

# Update multiple keys at once
person.update({"age": 32, "job": "Engineer"})
person.update(age=33, job="Developer")   # keyword argument syntax

# Removing
del person["city"]                  # removes key, raises KeyError if missing
removed = person.pop("age")         # removes and returns value
removed = person.pop("age", None)   # safe — returns None if missing
last = person.popitem()             # removes and returns last inserted (key, value) pair
person.clear()                      # empties the dict
```

---

### Iterating — three ways

python

```python
scores = {"Alice": 95, "Bob": 87, "Charlie": 92}

# Keys only — default behavior
for name in scores:
    print(name)

# Values only
for score in scores.values():
    print(score)

# Both — this is what you'll use most
for name, score in scores.items():
    print(f"{name}: {score}")

# Check membership — checks keys by default
print("Alice" in scores)     # True
print(95 in scores)          # False — doesn't search values
print(95 in scores.values()) # True
```

---

### Dict views — not copies

python

```python
scores = {"Alice": 95, "Bob": 87}

keys = scores.keys()
values = scores.values()
items = scores.items()

# These are LIVE VIEWS, not snapshots
scores["Charlie"] = 92
print(keys)    # dict_keys(['Alice', 'Bob', 'Charlie']) — updated automatically

# Convert to list if you need a static snapshot
key_list = list(scores.keys())
```

---

### Dictionary patterns used constantly in real code

**Counting occurrences:**

python

```python
words = ["apple", "banana", "apple", "cherry", "banana", "apple"]

counts = {}
for word in words:
    counts[word] = counts.get(word, 0) + 1

print(counts)   # {'apple': 3, 'banana': 2, 'cherry': 1}

# Same thing with defaultdict — cleaner
from collections import defaultdict

counts = defaultdict(int)   # default value is int() = 0
for word in words:
    counts[word] += 1
```

#### Explained 
This is one of the most classic, practical patterns in Python: **building a frequency counter** (counting how many times items appear in a list).

You've shown two ways to solve the exact same problem. The second way (`defaultdict`) exists purely to eliminate a common, annoying "boilerplate" error in Python.

Here is exactly what is happening under the hood in both examples.

##### The Problem Being Solved

If you try to count items using a standard, empty dictionary like this:

Python

```
counts = {}
for word in words:
    counts[word] += 1  # ❌ KeyError!
```

Python will crash with a `KeyError` the very first time it sees `"apple"`. Why? Because you can't add `1` to something that doesn't exist yet in the dictionary.

## Approach 1: The `.get()` Method (The Guard)

To fix the crash, your first example uses `counts.get(word, 0)`.

The `.get()` method is a safe way to look up a key. It says: _"Look for `word` in the dictionary. If you find it, give me its current count. If you don't find it, **don't crash—just give me `0`**."_

Here is how the loop processes step-by-step:

|**Step**|**Word**|**counts.get(word, 0) behaves like...**|**What actually happens**|
|---|---|---|---|
|**1**|`"apple"`|`0 + 1` (Not found, defaults to 0)|`counts["apple"] = 1`|
|**2**|`"banana"`|`0 + 1` (Not found, defaults to 0)|`counts["banana"] = 1`|
|**3**|`"apple"`|`1 + 1` (Found! It grabs the current `1`)|`counts["apple"] = 2`|

It works perfectly, but writing `counts[word] = counts.get(word, 0) + 1` is a bit wordy and redundant.

## Approach 2: `defaultdict` (The Automated Way)

Your second example uses `defaultdict(int)` from the built-in `collections` module to make the code much cleaner.

When you initialize `defaultdict(int)`, you are telling Python: _"If I ever try to access or modify a key that doesn't exist yet, don't crash. Instead, automatically create that key and give it a default value of `int()` (which is `0`)."_

Because Python automatically handles the creation of missing keys behind the scenes, you can completely skip the `.get()` logic and write:

Python

```
counts[word] += 1
```

- **When `"apple"` is seen for the first time:** Python notices `"apple"` isn't in the dictionary. It silently drops `0` into `counts["apple"]`, and then immediately adds `1` to it.
    
- **When `"apple"` is seen again:** It already exists, so Python just adds `1` to the existing total normally.
    

## Bonus Python Tip: `Counter`

While `defaultdict` is incredibly clean and widely used for all sorts of default structures (like lists or sets), Python has an even more specialized tool specifically for _counting_ called `Counter`.

If you just want the absolute cleanest way to do this in production code, you can use:

Python

```
from collections import Counter

words = ["apple", "banana", "apple", "cherry", "banana", "apple"]
counts = Counter(words)

print(counts)  # Counter({'apple': 3, 'banana': 2, 'cherry': 1})
```

No loops required at all! But understanding how `defaultdict` works is a fundamental Python superpower.



**Grouping items:**

python

```python
people = [
    {"name": "Alice", "dept": "Engineering"},
    {"name": "Bob", "dept": "Marketing"},
    {"name": "Charlie", "dept": "Engineering"},
    {"name": "Diana", "dept": "Marketing"},
]

by_dept = defaultdict(list)
for person in people:
    by_dept[person["dept"]].append(person["name"])

print(dict(by_dept))
# {'Engineering': ['Alice', 'Charlie'], 'Marketing': ['Bob', 'Diana']}
```

**setdefault — add key only if it doesn't exist:**

python

```python
data = {"name": "Alice"}

data.setdefault("age", 0)       # adds age: 0 only if age not present
data.setdefault("name", "Bob")  # does nothing — name already exists
print(data)    # {'name': 'Alice', 'age': 0}
```

**Merging dictionaries:**

python

```python
defaults = {"color": "blue", "size": "medium", "weight": 1.0}
overrides = {"color": "red", "weight": 2.5}

# Python 3.9+ — cleanest way
merged = defaults | overrides
print(merged)   # {'color': 'red', 'size': 'medium', 'weight': 2.5}

# Python 3.5+ — works everywhere
merged = {**defaults, **overrides}   # later keys win

# Update in place
defaults.update(overrides)
```

**Dict comprehension:**

python

```python
names = ["alice", "bob", "charlie"]

# Create a dict from a list
name_lengths = {name: len(name) for name in names}
print(name_lengths)    # {'alice': 5, 'bob': 3, 'charlie': 7}

# Filter while building
long_names = {name: len(name) for name in names if len(name) > 3}
print(long_names)      # {'alice': 5, 'charlie': 7}

# Invert a dictionary
original = {"a": 1, "b": 2, "c": 3}
inverted = {v: k for k, v in original.items()}
print(inverted)        # {1: 'a', 2: 'b', 3: 'c'}
```

---

### Nested dictionaries — how real data looks

python

```python
users = {
    "alice": {
        "age": 30,
        "scores": [95, 87, 92],
        "address": {
            "city": "London",
            "zip": "SW1A 1AA"
        }
    },
    "bob": {
        "age": 25,
        "scores": [78, 82, 88],
        "address": {
            "city": "Manchester",
            "zip": "M1 1AE"
        }
    }
}

# Accessing nested data
print(users["alice"]["address"]["city"])         # London
print(users["bob"]["scores"][1])                 # 82

# Safe access through nested dicts
city = users.get("charlie", {}).get("address", {}).get("city", "Unknown")
print(city)    # Unknown — no crash even though "charlie" doesn't exist
```

That chained `.get()` pattern is how you safely navigate nested data from APIs and databases without crashing on missing keys.

---

### Sets — unordered, unique, fast

A set stores unique values with O(1) membership testing — same hash table mechanism as dict keys.

python

```python
# Creation
fruits = {"apple", "banana", "cherry"}
nums = {1, 2, 3, 2, 1}        # duplicates removed automatically
print(nums)                    # {1, 2, 3}

empty = set()                  # NOT {} — that creates an empty dict
from_list = set([1, 2, 2, 3]) # convert list to set, removes duplicates
```

**Core set operations:**

python

```python
s = {1, 2, 3, 4}

s.add(5)            # {1, 2, 3, 4, 5}
s.remove(3)         # removes 3, raises KeyError if missing
s.discard(99)       # removes 99 if present, no error if missing
popped = s.pop()    # removes and returns an arbitrary element

print(3 in s)       # O(1) — instant, regardless of set size
print(3 in [1,2,3,4,5,6,7,8,9])  # O(n) — has to scan the list
```

**Set math — the reason sets exist:**

python

```python
a = {1, 2, 3, 4, 5}
b = {3, 4, 5, 6, 7}

# Union — all elements from both
print(a | b)           # {1, 2, 3, 4, 5, 6, 7}
print(a.union(b))      # same thing

# Intersection — only elements in both
print(a & b)           # {3, 4, 5}
print(a.intersection(b))

# Difference — in a but not b
print(a - b)           # {1, 2}
print(a.difference(b))

# Symmetric difference — in either but not both
print(a ^ b)           # {1, 2, 6, 7}

# Subset / superset
print({3, 4} <= a)     # True — {3,4} is a subset of a
print(a >= {3, 4})     # True — a is a superset of {3,4}
```

**Real use cases for sets:**

python

```python
# 1. Deduplicate a list while preserving nothing about order
ids = [1, 2, 3, 2, 1, 4, 3, 5]
unique_ids = list(set(ids))

# 2. Fast membership testing — much faster than list for large collections
valid_users = {"alice", "bob", "charlie"}   # set
username = "alice"
if username in valid_users:     # O(1) lookup
    print("Access granted")

# 3. Find common elements between two collections
team_a = {"alice", "bob", "charlie"}
team_b = {"bob", "diana", "charlie"}
on_both = team_a & team_b
print(on_both)    # {'bob', 'charlie'}

# 4. Find what's missing
required_fields = {"name", "email", "age"}
provided_fields = {"name", "email"}
missing = required_fields - provided_fields
print(missing)    # {'age'}
```

---

### Choosing the right data structure — the decision you'll make constantly

```
Data has key-value pairs?          → dict
Need fast lookup by identifier?    → dict
Need uniqueness + fast membership? → set
Need to do set math?               → set
Ordered collection, will modify?   → list
Fixed structure, won't modify?     → tuple
```

In practice, most real programs use dicts for almost everything — configuration, API responses, database records, grouping data. Sets appear specifically when you care about uniqueness or need set operations. Lists hold sequences. Tuples hold records.

---

### The mental model to carry forward

A dict is not just "a thing that stores key-value pairs." It's a hash map — the most important data structure in computing. When you look up `person["name"]`, Python computes a hash of the string `"name"`, goes directly to that memory location, and retrieves the value. No scanning. That's why it's O(1).

A set is a dict with no values — just keys. Everything that's true about dict keys is true about set elements: they must be hashable, lookup is O(1), duplicates are impossible.

Understanding this — not just using these structures but knowing _why_ they work — is what separates a developer who writes slow, buggy code from one who reaches for the right tool automatically.

---
[[Foundation]]