### The four primitive types

Python has four basic types everything else is built on:

python

```python
name = "Alice"       # str   — text
age = 30             # int   — whole numbers
height = 1.75        # float — decimal numbers
employed = True      # bool  — True or False (capital T/F, always)
```

These aren't just labels. Each type has different capabilities, different memory footprints, and different behaviors when you operate on them.

---

### Strings — the type you'll use most

A string is an immutable sequence of characters. Immutable means once created, it cannot be changed in place — any operation that "modifies" a string actually creates a new one.

python

```python
s = "hello"
s[0] = "H"      # TypeError — you cannot change a string in place
s = "Hello"     # this works because you're creating a NEW string, not modifying the old one
```

**Indexing and slicing** — critical to understand deeply:

python

```python
word = "Python"

# Indexing — get one character
print(word[0])    # P  — first character
print(word[-1])   # n  — last character (negative counts from end)
print(word[-2])   # o  — second to last

# Slicing — get a range [start:stop:step]
print(word[0:3])  # Pyt  — index 0, 1, 2 (stop is excluded)
print(word[2:])   # thon — from index 2 to end
print(word[:3])   # Pyt  — from beginning to index 3
print(word[::2])  # Pto  — every second character
print(word[::-1]) # nohtyP — reversed (step of -1)
```

The `stop` index is always excluded. `word[0:3]` gives characters at positions 0, 1, 2 — not 3. This is consistent everywhere in Python.

**The f-string — your default for any string with variables:**

python

```python
name = "Alice"
age = 30
score = 95.678

print(f"Name: {name}")
print(f"Age: {age}")
print(f"Score: {score:.2f}")    # .2f = 2 decimal places
print(f"Double age: {age * 2}") # expressions work inside {}
print(f"{'hello'.upper()}")     # method calls work too
```

**String methods you'll use constantly:**

python

```python
s = "  Hello, World!  "

s.strip()           # "Hello, World!"  — remove leading/trailing whitespace
s.lower()           # "  hello, world!  "
s.upper()           # "  HELLO, WORLD!  "
s.replace("Hello", "Hi")  # "  Hi, World!  "
s.strip().split(",")      # ["Hello", " World!"]
",".join(["a","b","c"])   # "a,b,c"
s.startswith("  H")       # True
s.endswith("!  ")         # True
s.count("l")              # 3
s.find("World")           # 9 — index where it starts, -1 if not found
"42".zfill(5)             # "00042" — pad with zeros, useful for IDs
```

**Multiline strings:**

python

```python
message = """
This is line one
This is line two
This is line three
"""
print(message)
```

---

### Integers — more than just numbers

python

```python
x = 1_000_000     # underscores are ignored, just for readability
print(x)          # 1000000

# Integer division always gives exact results
print(10 // 3)    # 3
print(-10 // 3)   # -4  — floors toward negative infinity, not toward zero

# int() converts other types
print(int("42"))      # 42
print(int(3.9))       # 3 — truncates, does NOT round
print(int(True))      # 1
print(int(False))     # 0

# Integers in Python have no size limit
print(2 ** 100)   # works fine — Python handles arbitrarily large integers
```

---

### Floats — and the problem every developer hits

python

```python
print(0.1 + 0.2)      # 0.30000000000000004  — NOT a bug, this is how floating point works

# Why: floats are stored in binary, and 0.1 can't be represented exactly in binary
# This is true in every language, Python just shows you more digits

# How to handle it in practice:
print(round(0.1 + 0.2, 2))        # 0.3 — for display
from decimal import Decimal
print(Decimal("0.1") + Decimal("0.2"))  # 0.2 — for financial/precise calculations

# float() converts other types
print(float("3.14"))   # 3.14
print(float(5))        # 5.0
print(float(True))     # 1.0
```

The `Decimal` issue matters in real code. If you're building anything that handles money, never use float. Always use `Decimal` or store values as integers (cents instead of dollars).

---

### Booleans — not just True and False

python

```python
print(True)     # True
print(False)    # False
print(type(True))  # <class 'bool'>

# Booleans are actually integers in Python
print(True + True)   # 2
print(True * 5)      # 5
print(False + 1)     # 1

# Every value in Python is truthy or falsy
# Falsy values — these all evaluate to False in a condition:
bool(0)        # False
bool(0.0)      # False
bool("")       # False  — empty string
bool([])       # False  — empty list
bool({})       # False  — empty dict
bool(None)     # False

# Everything else is truthy
bool(1)        # True
bool(-1)       # True  — any non-zero number
bool("hello")  # True
bool([0])      # True  — list with one item (even if item is falsy)
```

This truthiness behavior is used constantly in real Python:

python

```python
name = ""
if name:              # cleaner than: if name != ""
    print(f"Hello, {name}")
else:
    print("No name provided")

items = []
if not items:         # cleaner than: if len(items) == 0
    print("List is empty")
```

---

### Type checking and conversion

python

```python
x = 42
print(type(x))           # <class 'int'>
print(isinstance(x, int))      # True
print(isinstance(x, (int, float)))  # True — check against multiple types at once

# Common conversions
int("10")       # "10" → 10
str(10)         # 10 → "10"
float("3.14")   # "3.14" → 3.14
bool(0)         # 0 → False
list("abc")     # "abc" → ['a', 'b', 'c']

# What breaks
int("hello")    # ValueError — Python won't guess
int("3.14")     # ValueError — even though it looks numeric
float("abc")    # ValueError
```

Always use `isinstance()` over `type(x) == int` — it handles inheritance correctly and is the Pythonic way.

---

### None — the absence of a value

python

```python
result = None    # not zero, not empty string — it means "nothing"

print(result)           # None
print(type(result))     # <class 'NoneType'>

# Always check for None with 'is', not ==
if result is None:
    print("No result yet")

if result is not None:
    print(f"Got: {result}")
```

`None` is Python's way of saying "this variable exists but has no value yet." You'll see it constantly — functions that don't explicitly return anything return `None`, optional parameters default to `None`, database queries return `None` when nothing is found.

---

### How types interact — the rules

python

```python
# Python will NOT silently convert types for you (mostly)
print("Age: " + 30)      # TypeError — can't add str and int

# You must be explicit
print("Age: " + str(30)) # works
print(f"Age: {30}")      # works — f-strings handle conversion automatically

# Numeric types do mix automatically
print(10 + 3.0)   # 13.0 — int + float = float (int is "widened")
print(True + 1)   # 2    — bool + int = int
```

---

### The mental model to carry forward

Every piece of data in Python is an **object**. Even `42` and `True` are objects with methods and properties. When you write `x = 42`, you're creating an integer object with the value 42 and attaching the label `x` to it.

This matters because:

- Variables don't have types — objects do
- Multiple variables can point to the same object
- When you pass a variable to a function, you're passing the object reference, not a copy

That last point will matter a lot when we hit lists and dictionaries tomorrow.

---

[[Foundation]]