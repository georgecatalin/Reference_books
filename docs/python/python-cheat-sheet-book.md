# Python Cheat Sheet Reference Book

> A compact but thorough study guide and long-term reference for Python fundamentals, core syntax, object-oriented programming, and everyday standard-library utilities.

---

## How to Use This Book

This book is designed to work as both:

- a **study guide** for learning Python step by step
- a **cheat sheet** for quickly finding syntax and patterns later

It emphasizes:

- core theory in plain language
- practical code snippets
- edge cases and common mistakes
- Pythonic habits and conventions

---

# Table of Contents

1. [Python at a Glance](#1-python-at-a-glance)
2. [Variables and Basic Data Types](#2-variables-and-basic-data-types)
3. [Operators and Expressions](#3-operators-and-expressions)
4. [Control Flow](#4-control-flow)
5. [Functions](#5-functions)
6. [Strings](#6-strings)
7. [Lists, Tuples, Sets, and Dictionaries](#7-lists-tuples-sets-and-dictionaries)
8. [Comprehensions and Generator Expressions](#8-comprehensions-and-generator-expressions)
9. [Modules, Imports, and Packages](#9-modules-imports-and-packages)
10. [File Handling](#10-file-handling)
11. [Exceptions and Error Handling](#11-exceptions-and-error-handling)
12. [Object-Oriented Programming](#12-object-oriented-programming)
13. [Encapsulation, Access Modifiers, Inheritance, and Polymorphism](#13-encapsulation-access-modifiers-inheritance-and-polymorphism)
14. [Iterators, Generators, and Decorators](#14-iterators-generators-and-decorators)
15. [Useful Built-in Functions](#15-useful-built-in-functions)
16. [JSON Utilities](#16-json-utilities)
17. [Date and Time Utilities](#17-date-and-time-utilities)
18. [Operating System Utilities](#18-operating-system-utilities)
19. [Threading Basics](#19-threading-basics)
20. [Typing, Dataclasses, and Modern Python Style](#20-typing-dataclasses-and-modern-python-style)
21. [Testing and Debugging Basics](#21-testing-and-debugging-basics)
22. [Common Pitfalls and Edge Cases](#22-common-pitfalls-and-edge-cases)
23. [Mini Practical Patterns](#23-mini-practical-patterns)
24. [Final Quick Reference](#24-final-quick-reference)
25. [Running Python and the REPL](#25-running-python-and-the-repl)
26. [Virtual Environments and Packaging](#26-virtual-environments-and-packaging)

---

# 1. Python at a Glance

## What Python Is

Python is a **high-level, interpreted, general-purpose programming language** known for:

- readable syntax
- fast development speed
- a huge standard library
- strong support for scripting, automation, web development, data work, and tooling

## Why Python Is Popular

Python lets you express ideas with relatively little code. It is often used for:

- automation scripts
- backend services
- APIs
- data processing
- machine learning
- testing
- DevOps tasks
- education

## Hello World

```python
print("Hello, world!")
```

## Comments

```python
# Single-line comment

"""
This is a multi-line string.
It is often used as a docstring.
"""
```

## Key Characteristics

- dynamically typed
- strongly typed
- garbage collected
- indentation-based syntax
- everything is an object

## Zen of Python

A famous design philosophy can be viewed with:

```python
import this
```

---

# 2. Variables and Basic Data Types

## Variables

Variables are names bound to objects.

```python
name = "Alice"
age = 30
price = 19.99
is_active = True
```

Python does **not** require explicit type declarations.

```python
x = 10
x = "now I am a string"
```

## Naming Rules

Valid names:

- can contain letters, digits, and `_`
- cannot start with a digit
- are case-sensitive
- should not use Python keywords

```python
user_name = "john"
count2 = 5
_total = 100
```

Invalid:

```python
# 2count = 5
# class = "bad"
```

## Common Naming Conventions

- `snake_case` for variables and functions
- `UPPER_CASE` for constants by convention
- `CamelCase` for classes

```python
MAX_RETRIES = 3
user_email = "a@example.com"
```

## Core Data Types

### Integer

```python
x = 42
```

### Float

```python
y = 3.14
```

### Boolean

```python
flag = True
```

### String

```python
text = "Python"
```

### None

```python
result = None
```

`None` means “no value” or “missing value”.

## Type Checking

```python
x = 10
print(type(x))          # <class 'int'>
print(isinstance(x, int))
```

Prefer `isinstance()` over direct `type(...) == ...` in most real code.

## Multiple Assignment

```python
a, b, c = 1, 2, 3
```

## Swapping Variables

```python
a = 5
b = 10
a, b = b, a
```

## Constants

Python has no true constant enforcement, but convention uses uppercase names.

```python
PI = 3.14159
```

## Truthy and Falsy Values

Falsy values include:

- `False`
- `None`
- `0`
- `0.0`
- `""`
- `[]`
- `{}`
- `set()`

```python
if not []:
    print("Empty list is falsy")
```

## Type Conversion

```python
int("123")
float("3.14")
str(100)
bool(1)
list("abc")
```

Edge case:

```python
bool("False")   # True, because non-empty strings are truthy
```

---

# 3. Operators and Expressions

## Arithmetic Operators

```python
a + b   # addition
a - b   # subtraction
a * b   # multiplication
a / b   # true division
a // b  # floor division
a % b   # modulus
a ** b  # power
```

## Comparison Operators

```python
== != < <= > >=
```

## Logical Operators

```python
and
or
not
```

Example:

```python
age = 20
has_id = True
if age >= 18 and has_id:
    print("Allowed")
```

## Membership Operators

```python
"a" in "cat"
3 in [1, 2, 3]
"key" in {"key": 1}
```

## Identity Operators

```python
is
is not
```

Use `is` for identity, especially with `None`:

```python
if value is None:
    print("Missing")
```

Do not use `is` instead of `==` for normal value comparison.

Bad:

```python
# if x is 5:
#     ...
```

## Chained Comparisons

```python
x = 5
print(1 < x < 10)   # True
```

## Ternary Expression

```python
status = "adult" if age >= 18 else "minor"
```

---

# 4. Control Flow

## if / elif / else

```python
score = 82

if score >= 90:
    grade = "A"
elif score >= 80:
    grade = "B"
else:
    grade = "C"
```

## for Loop

```python
for i in range(5):
    print(i)
```

## while Loop

```python
count = 3
while count > 0:
    print(count)
    count -= 1
```

## break, continue, pass

```python
for n in range(10):
    if n == 3:
        continue
    if n == 7:
        break
    print(n)
```

```python
def todo():
    pass
```

## for-else

The `else` runs if the loop finishes without `break`.

```python
for n in [1, 3, 5]:
    if n == 2:
        print("found")
        break
else:
    print("not found")
```

## match / case

Available in modern Python.

```python
def http_status(code):
    match code:
        case 200:
            return "OK"
        case 404:
            return "Not Found"
        case 500:
            return "Server Error"
        case _:
            return "Unknown"
```

---

# 5. Functions

## Defining Functions

```python
def greet(name):
    return f"Hello, {name}!"
```

## Calling Functions

```python
message = greet("Alice")
print(message)
```

## Parameters vs Arguments

- **parameter**: name in function definition
- **argument**: value passed at call time

## Default Arguments

```python
def power(base, exponent=2):
    return base ** exponent
```

## Keyword Arguments

```python
print(power(exponent=3, base=2))
```

## Positional and Keyword-Only Patterns

```python
def connect(host, port, *, timeout=30, ssl=True):
    return host, port, timeout, ssl
```

`timeout` and `ssl` must be passed by keyword.

## Variable-Length Arguments

```python
def add_all(*args):
    return sum(args)

print(add_all(1, 2, 3))
```

```python
def print_info(**kwargs):
    for key, value in kwargs.items():
        print(key, value)
```

## Returning Multiple Values

```python
def min_max(values):
    return min(values), max(values)

low, high = min_max([3, 7, 1])
```

This returns a tuple.

## Docstrings

```python
def square(x):
    """Return the square of x."""
    return x * x
```

## Scope: LEGB

Python resolves names in this order:

- Local
- Enclosing
- Global
- Built-in

```python
x = "global"

def outer():
    x = "enclosing"

    def inner():
        x = "local"
        print(x)

    inner()

outer()
```

## global and nonlocal

```python
count = 0

def increment():
    global count
    count += 1
```

```python
def outer():
    count = 0

    def inner():
        nonlocal count
        count += 1
        return count

    return inner
```

## Important Edge Case: Mutable Default Arguments

Bad:

```python
def append_item(item, items=[]):
    items.append(item)
    return items
```

Why it is bad: the same list is reused across calls.

Correct:

```python
def append_item(item, items=None):
    if items is None:
        items = []
    items.append(item)
    return items
```

## Lambda Functions

```python
square = lambda x: x * x
print(square(4))
```

Use lambdas for small expressions, not complex logic.

---

# 6. Strings

## Creating Strings

```python
single = 'hello'
double = "hello"
multiline = """line1
line2"""
```

## String Indexing and Slicing

```python
text = "Python"
print(text[0])     # P
print(text[-1])    # n
print(text[0:4])   # Pyth
print(text[::2])   # Pto
```

## Strings Are Immutable

```python
text = "cat"
# text[0] = "b"   # Error
text = "b" + text[1:]
```

## Common String Methods

```python
s = "  hello,python  "
print(s.strip())
print(s.lower())
print(s.upper())
print(s.title())
print(s.replace("python", "world"))
print(s.split(","))
print("-".join(["a", "b", "c"]))
```

## Search Methods

```python
text = "banana"
print(text.find("na"))    # first index or -1
print(text.count("a"))
print(text.startswith("ba"))
print(text.endswith("na"))
```

## Safer Prefix Checking

```python
filename = "report.csv"
if filename.endswith(".csv"):
    print("CSV file")
```

## String Formatting

### f-strings

```python
name = "Alice"
age = 30
print(f"{name} is {age} years old")
```

### format()

```python
print("{} scored {}".format("Bob", 95))
```

### Percent formatting

```python
print("%s scored %d" % ("Bob", 95))
```

Prefer f-strings in modern Python.

## Useful String Predicates

```python
"abc".isalpha()
"123".isdigit()
"abc123".isalnum()
"   ".isspace()
"Hello".istitle()
```

## Splitting Lines

```python
text = "a\nb\nc"
print(text.splitlines())
```

## Encoding and Decoding

```python
text = "café"
data = text.encode("utf-8")
restored = data.decode("utf-8")
```

---

# 7. Lists, Tuples, Sets, and Dictionaries

## Lists

Ordered, mutable collections.

```python
numbers = [1, 2, 3]
numbers.append(4)
numbers.extend([5, 6])
numbers.insert(0, 0)
numbers.remove(2)
popped = numbers.pop()
```

## Common List Operations

```python
nums = [3, 1, 2]
print(len(nums))
print(sorted(nums))
nums.sort()
nums.reverse()
```

## List Slicing

```python
items = [10, 20, 30, 40, 50]
print(items[1:4])
print(items[::-1])
```

## Copying Lists

```python
a = [1, 2, 3]
b = a.copy()
c = a[:]
```

Edge case:

```python
a = [[1], [2]]
b = a.copy()
b[0].append(99)
print(a)  # inner objects are still shared
```

For nested structures, use `copy.deepcopy()`.

## Tuples

Ordered, immutable collections.

```python
point = (10, 20)
```

Single-item tuple needs a trailing comma:

```python
x = (5,)
```

## Sets

Unordered collections of unique values.

```python
s = {1, 2, 3}
s.add(4)
s.remove(2)
```

Set operations:

```python
a = {1, 2, 3}
b = {3, 4, 5}
print(a | b)   # union
print(a & b)   # intersection
print(a - b)   # difference
print(a ^ b)   # symmetric difference
```

## Dictionaries

Key-value mappings.

```python
user = {"name": "Alice", "age": 30}
print(user["name"])
user["email"] = "alice@example.com"
```

## Dictionary Methods

```python
user.get("name")
user.get("country", "Unknown")
user.keys()
user.values()
user.items()
user.pop("age")
user.update({"age": 31})
```

Use `get()` when a key may be missing.

Bad if key may not exist:

```python
# country = user["country"]
```

Better:

```python
country = user.get("country")
```

## Iterating Over Collections

```python
for item in [1, 2, 3]:
    print(item)

for key in user:
    print(key)

for key, value in user.items():
    print(key, value)
```

---

# 8. Comprehensions and Generator Expressions

## List Comprehension

```python
squares = [x * x for x in range(5)]
```

## Conditional List Comprehension

```python
evens = [x for x in range(10) if x % 2 == 0]
```

## Dictionary Comprehension

```python
squares = {x: x * x for x in range(5)}
```

## Set Comprehension

```python
unique_lengths = {len(word) for word in ["a", "bb", "cc", "ddd"]}
```

## Generator Expression

```python
gen = (x * x for x in range(5))
print(next(gen))
```

Generators are lazy and memory-efficient.

---

# 9. Modules, Imports, and Packages

## Importing Modules

```python
import math
print(math.sqrt(16))
```

## Import Specific Names

```python
from math import sqrt
print(sqrt(25))
```

## Aliases

```python
import datetime as dt
```

## Avoid Wildcard Imports

```python
# from math import *
```

This can pollute the namespace.

## Creating a Module

If `mymath.py` contains:

```python
def add(a, b):
    return a + b
```

Then:

```python
import mymath
print(mymath.add(2, 3))
```

## `__name__ == "__main__"`

```python
def main():
    print("Running as script")

if __name__ == "__main__":
    main()
```

---

# 10. File Handling

## Reading a File

```python
with open("data.txt", "r", encoding="utf-8") as f:
    content = f.read()
```

## Writing a File

```python
with open("output.txt", "w", encoding="utf-8") as f:
    f.write("Hello")
```

## Appending

```python
with open("log.txt", "a", encoding="utf-8") as f:
    f.write("New line\n")
```

## Reading Line by Line

```python
with open("data.txt", "r", encoding="utf-8") as f:
    for line in f:
        print(line.rstrip())
```

## Common Modes

- `r` read
- `w` write, truncates file
- `a` append
- `x` create, fail if exists
- `b` binary mode
- `t` text mode

---

# 11. Exceptions and Error Handling

## Basic try/except

```python
try:
    value = int("abc")
except ValueError:
    print("Invalid integer")
```

## Multiple except Blocks

```python
try:
    result = 10 / 0
except ZeroDivisionError:
    print("Cannot divide by zero")
except Exception as exc:
    print("Other error:", exc)
```

## else and finally

```python
try:
    value = int("42")
except ValueError:
    print("Bad input")
else:
    print("Parsed:", value)
finally:
    print("Always runs")
```

## Raising Exceptions

```python
def set_age(age):
    if age < 0:
        raise ValueError("age cannot be negative")
```

## Custom Exception

```python
class ConfigError(Exception):
    pass
```

---

# 12. Object-Oriented Programming

## Defining a Class

```python
class Dog:
    def __init__(self, name, age):
        self.name = name
        self.age = age

    def bark(self):
        return f"{self.name} says woof!"
```

## Creating Objects

```python
d = Dog("Buddy", 3)
print(d.name)
print(d.bark())
```

## Instance Attributes

Each object can hold its own data.

## Class Attributes

```python
class Dog:
    species = "Canis familiaris"

    def __init__(self, name):
        self.name = name
```

## Instance Methods

Methods receive `self`.

## Class Methods

```python
class Counter:
    total = 0

    def __init__(self):
        Counter.total += 1

    @classmethod
    def get_total(cls):
        return cls.total
```

## Static Methods

```python
class MathUtils:
    @staticmethod
    def add(a, b):
        return a + b
```

## `__str__` and `__repr__`

```python
class User:
    def __init__(self, name):
        self.name = name

    def __str__(self):
        return f"User({self.name})"

    def __repr__(self):
        return f"User(name={self.name!r})"
```

---

# 13. Encapsulation, Access Modifiers, Inheritance, and Polymorphism

## Encapsulation

Encapsulation means bundling data and behavior together and controlling access to internal state.

Python does not enforce access modifiers like Java or C++, but uses conventions.

## Access Modifiers in Python: The Practical Reality

### Public

```python
class Account:
    def __init__(self, owner):
        self.owner = owner
```

Public members are intended for normal external use.

### Protected by Convention: Single Underscore

```python
class Account:
    def __init__(self, owner, balance):
        self.owner = owner
        self._balance = balance
```

`_balance` means: internal use by convention. It is still accessible.

### Name Mangling: Double Underscore

```python
class Account:
    def __init__(self, owner, balance):
        self.owner = owner
        self.__balance = balance
```

Python rewrites `__balance` internally to reduce accidental access:

```python
acc = Account("Alice", 100)
# print(acc.__balance)       # AttributeError
print(acc._Account__balance) # possible, but discouraged
```

This is not true privacy, but name mangling.

## Encapsulation with Properties

```python
class Account:
    def __init__(self, owner, balance=0):
        self.owner = owner
        self._balance = balance

    @property
    def balance(self):
        return self._balance

    @balance.setter
    def balance(self, value):
        if value < 0:
            raise ValueError("Balance cannot be negative")
        self._balance = value
```

Usage:

```python
acc = Account("Alice", 100)
print(acc.balance)
acc.balance = 200
```

## Inheritance

Inheritance allows a class to reuse and extend behavior from another class.

```python
class Animal:
    def speak(self):
        return "Some sound"

class Dog(Animal):
    def speak(self):
        return "Woof"
```

## Using `super()`

```python
class Animal:
    def __init__(self, name):
        self.name = name

class Dog(Animal):
    def __init__(self, name, breed):
        super().__init__(name)
        self.breed = breed
```

## Polymorphism

Polymorphism means different classes can provide the same interface differently.

```python
class Cat:
    def speak(self):
        return "Meow"

class Dog:
    def speak(self):
        return "Woof"

def animal_sound(animal):
    print(animal.speak())
```

```python
animal_sound(Cat())
animal_sound(Dog())
```

This is common Python style: if it behaves correctly, it is accepted.

## Duck Typing

Python often focuses on behavior instead of exact type.

```python
class Duck:
    def quack(self):
        print("quack")

class Person:
    def quack(self):
        print("I can imitate a duck")

def make_it_quack(obj):
    obj.quack()
```

If the object supports the expected method, it works.

---

# 14. Iterators, Generators, and Decorators

## Iterators

An iterator is an object that produces values one at a time.

```python
nums = iter([1, 2, 3])
print(next(nums))
print(next(nums))
```

## Generators

```python
def countdown(n):
    while n > 0:
        yield n
        n -= 1
```

```python
for x in countdown(3):
    print(x)
```

## Decorators

```python
def logger(func):
    def wrapper(*args, **kwargs):
        print(f"Calling {func.__name__}")
        return func(*args, **kwargs)
    return wrapper

@logger
def greet(name):
    return f"Hello {name}"
```

---

# 15. Useful Built-in Functions

## Frequently Used Built-ins

```python
len([1, 2, 3])
min(3, 1, 2)
max(3, 1, 2)
sum([1, 2, 3])
sorted([3, 1, 2])
reversed([1, 2, 3])
enumerate(["a", "b"])
zip([1, 2], ["a", "b"])
any([False, True, False])
all([True, True, True])
```

## map, filter

```python
nums = [1, 2, 3]
print(list(map(lambda x: x * 2, nums)))
print(list(filter(lambda x: x % 2 == 1, nums)))
```

List comprehensions are often more readable.

## `range()`

```python
range(5)
range(1, 10)
range(0, 20, 2)
```

---

# 16. JSON Utilities

The `json` module is used to convert between Python objects and JSON text.

## Python to JSON String

```python
import json

data = {"name": "Alice", "age": 30}
json_text = json.dumps(data)
print(json_text)
```

## Pretty Printing JSON

```python
print(json.dumps(data, indent=2, sort_keys=True))
```

## JSON String to Python

```python
text = '{"name": "Bob", "age": 25}'
obj = json.loads(text)
print(obj["name"])
```

## Read JSON File

```python
import json

with open("data.json", "r", encoding="utf-8") as f:
    obj = json.load(f)
```

## Write JSON File

```python
with open("data.json", "w", encoding="utf-8") as f:
    json.dump(data, f, indent=2)
```

## Common Edge Cases

- JSON keys are strings.
- Python tuples become JSON arrays.
- `None` becomes `null`.
- JSON does not support Python sets directly.

```python
json.dumps({"value": None})   # {"value": null}
```

For unsupported types, use `default=`.

```python
def custom_serializer(obj):
    return str(obj)
```

---

# 17. Date and Time Utilities

Python commonly uses `datetime` for date and time work.

## Current Date and Time

```python
from datetime import datetime, date

now = datetime.now()
today = date.today()
```

## Create Specific Dates

```python
from datetime import datetime

dt = datetime(2026, 5, 20, 14, 30)
```

## Formatting Dates

```python
print(now.strftime("%Y-%m-%d %H:%M:%S"))
```

Common directives:

- `%Y` four-digit year
- `%m` month
- `%d` day
- `%H` hour (24h)
- `%M` minute
- `%S` second

## Parsing Strings into Dates

```python
from datetime import datetime

dt = datetime.strptime("2026-05-20", "%Y-%m-%d")
```

## Timedelta

```python
from datetime import timedelta

future = now + timedelta(days=7)
past = now - timedelta(hours=3)
```

## Time Zones

```python
from datetime import datetime, timezone

utc_now = datetime.now(timezone.utc)
```

Prefer timezone-aware datetimes for systems that cross regions.

---

# 18. Operating System Utilities

Python includes useful OS-level tools, especially through `os`, `pathlib`, and `shutil`.

## Current Working Directory

```python
import os
print(os.getcwd())
```

## Environment Variables

```python
import os
api_key = os.getenv("API_KEY")
```

## Path Handling with `pathlib`

Prefer `pathlib` in modern Python.

```python
from pathlib import Path

path = Path("docs") / "notes.txt"
print(path.exists())
print(path.name)
print(path.suffix)
print(path.stem)
```

## Read and Write with `pathlib`

```python
from pathlib import Path

p = Path("example.txt")
p.write_text("hello", encoding="utf-8")
print(p.read_text(encoding="utf-8"))
```

## Create Directories

```python
from pathlib import Path

Path("output/logs").mkdir(parents=True, exist_ok=True)
```

## Listing Files

```python
from pathlib import Path

for item in Path(".").iterdir():
    print(item)
```

## Copying Files

```python
import shutil
shutil.copy("source.txt", "backup.txt")
```

## Running Commands

Prefer `subprocess` instead of old `os.system`.

```python
import subprocess

result = subprocess.run(
    ["python", "--version"],
    capture_output=True,
    text=True,
    check=False,
)
print(result.stdout)
print(result.returncode)
```

---

# 19. Threading Basics

Threading is useful for I/O-bound tasks, such as waiting for files, network, or external systems.

## Basic Thread Example

```python
import threading
import time

def worker(name):
    for i in range(3):
        print(f"{name}: {i}")
        time.sleep(0.5)

thread = threading.Thread(target=worker, args=("A",))
thread.start()
thread.join()
```

## Multiple Threads

```python
threads = []
for i in range(3):
    t = threading.Thread(target=worker, args=(f"T{i}",))
    t.start()
    threads.append(t)

for t in threads:
    t.join()
```

## Lock for Shared Data

```python
import threading

counter = 0
lock = threading.Lock()

def increment():
    global counter
    for _ in range(100000):
        with lock:
            counter += 1
```

## Important Note

Because of the GIL in standard CPython, threading is often best for I/O-bound tasks, not CPU-bound parallelism.

For CPU-heavy workloads, look at:

- `multiprocessing`
- native extensions
- vectorized libraries

---

# 20. Typing, Dataclasses, and Modern Python Style

## Type Hints

```python
def greet(name: str) -> str:
    return f"Hello, {name}"
```

## Common Generic Types

```python
from typing import List, Dict, Optional

names: List[str] = ["Alice", "Bob"]
ages: Dict[str, int] = {"Alice": 30}
email: Optional[str] = None
```

In newer Python, built-in generic syntax is common:

```python
names: list[str] = ["Alice", "Bob"]
ages: dict[str, int] = {"Alice": 30}
```

## Dataclasses

```python
from dataclasses import dataclass

@dataclass
class User:
    name: str
    age: int
```

This automatically gives you useful methods like init and repr.

---

# 21. Testing and Debugging Basics

## Assertions

```python
assert 2 + 2 == 4
```

## Simple Unit Test Example

```python
import unittest

def add(a, b):
    return a + b

class TestMath(unittest.TestCase):
    def test_add(self):
        self.assertEqual(add(2, 3), 5)

if __name__ == "__main__":
    unittest.main()
```

## Debug Prints and `pprint`

```python
from pprint import pprint

pprint({"users": [{"name": "Alice", "age": 30}]})
```

---

# 22. Common Pitfalls and Edge Cases

## 1. Mutable Default Arguments

Already covered, but important enough to repeat.

## 2. Late Binding in Closures

```python
funcs = []
for i in range(3):
    funcs.append(lambda: i)

print([f() for f in funcs])   # [2, 2, 2]
```

Fix:

```python
funcs = []
for i in range(3):
    funcs.append(lambda i=i: i)

print([f() for f in funcs])   # [0, 1, 2]
```

## 3. Floating-Point Precision

```python
print(0.1 + 0.2)   # not exactly 0.3
```

Use `decimal.Decimal` for exact decimal arithmetic when needed.

## 4. Modifying a List While Iterating

Problem:

```python
nums = [1, 2, 3, 4]
for n in nums:
    if n % 2 == 0:
        nums.remove(n)
```

Safer:

```python
nums = [1, 2, 3, 4]
nums = [n for n in nums if n % 2 != 0]
```

## 5. `==` vs `is`

- `==` compares value
- `is` compares object identity

## 6. Shallow vs Deep Copy

```python
import copy

nested = [[1], [2]]
cloned = copy.deepcopy(nested)
```

---

# 23. Mini Practical Patterns

## Count Frequencies

```python
from collections import Counter

words = ["a", "b", "a", "c", "b", "a"]
counts = Counter(words)
print(counts)
```

## Grouping Values

```python
from collections import defaultdict

groups = defaultdict(list)
for name, dept in [("Ana", "IT"), ("Bob", "HR"), ("Eve", "IT")]:
    groups[dept].append(name)
```

## Safe Dictionary Merge

```python
a = {"x": 1}
b = {"y": 2}
merged = a | b
```

## Enumerate with Index

```python
for index, value in enumerate(["a", "b", "c"], start=1):
    print(index, value)
```

## Sorting by Key

```python
users = [
    {"name": "Alice", "age": 30},
    {"name": "Bob", "age": 25},
]

users.sort(key=lambda u: u["age"])
```

## Read JSON Config and Validate Fields

```python
import json

with open("config.json", "r", encoding="utf-8") as f:
    config = json.load(f)

host = config.get("host")
if not host:
    raise ValueError("Missing 'host' in config")
```

---

# 24. Final Quick Reference

## Variables

```python
x = 10
name = "Alice"
flag = True
value = None
```

## Function Template

```python
def function_name(arg1, arg2=default_value):
    return arg1, arg2
```

## Collections

```python
lst = [1, 2, 3]
tpl = (1, 2, 3)
st = {1, 2, 3}
dct = {"a": 1, "b": 2}
```

## OOP Template

```python
class Person:
    def __init__(self, name):
        self.name = name

    def greet(self):
        return f"Hello, {self.name}"
```

## Property Template

```python
class Product:
    def __init__(self, price):
        self._price = price

    @property
    def price(self):
        return self._price

    @price.setter
    def price(self, value):
        if value < 0:
            raise ValueError("price must be >= 0")
        self._price = value
```

## JSON

```python
import json
json.dumps(data)
json.loads(text)
json.dump(data, file)
json.load(file)
```

## Datetime

```python
from datetime import datetime, timedelta
now = datetime.now()
later = now + timedelta(days=1)
```

## OS / Paths

```python
from pathlib import Path
p = Path("file.txt")
p.exists()
p.read_text(encoding="utf-8")
```

## Threading

```python
import threading
thread = threading.Thread(target=worker)
thread.start()
thread.join()
```

---

# 25. Running Python and the REPL

## Running Python Scripts

You can run a Python file from the command line:

```bash
python script.py
```

On some systems you may need:

```bash
python3 script.py
```

## Running a Module as a Script

If a module contains a `main()` function and a `__name__ == "__main__"` guard, you can run it with:

```bash
python -m mymodule
```

This is useful when a package or module is meant to be executed directly.

## The REPL

REPL means **Read-Eval-Print Loop**. It is an interactive Python prompt where you type expressions and see results immediately.

Start it with:

```bash
python
```

or:

```bash
python3
```

Example session:

```python
>>> 2 + 2
4
>>> name = "Alice"
>>> name.upper()
'ALICE'
```

## REPL Shortcuts and Tips

- Use it to test small expressions quickly.
- Great for learning built-ins and methods.
- Works well for debugging small code snippets.

A few handy examples:

```python
>>> import math
>>> math.sqrt(16)
4.0
>>> help(str)
```

## Running One-Liners

You can execute a short command without opening a file:

```bash
python -c "print('Hello from Python')"
```

## Exiting the REPL

Exit with:

```python
exit()
```

or:

- `Ctrl+D` on macOS/Linux
- `Ctrl+Z` then `Enter` on Windows

## Common `__main__` Pattern

```python
def main():
    print("Program started")

if __name__ == "__main__":
    main()
```

This keeps code reusable when imported, but executable when run directly.

---

# 26. Virtual Environments and Packaging

## Why Virtual Environments Matter

A virtual environment isolates project dependencies so one project does not interfere with another.

Use them to:

- keep dependencies project-specific
- avoid version conflicts
- make setups reproducible

## Creating a Virtual Environment

Create one with the built-in `venv` module:

```bash
python -m venv .venv
```

## Activating the Environment

### macOS / Linux

```bash
source .venv/bin/activate
```

### Windows PowerShell

```powershell
.venv\Scripts\Activate.ps1
```

### Windows Command Prompt

```bat
.venv\Scripts\activate.bat
```

When active, your shell usually shows the environment name.

## Upgrading Packaging Tools

Inside the virtual environment, upgrade basic tooling:

```bash
python -m pip install --upgrade pip setuptools wheel
```

## Installing Packages

Install a package with:

```bash
pip install requests
```

Or install from a requirements file:

```bash
pip install -r requirements.txt
```

## Freezing Dependencies

Capture installed packages to a file:

```bash
pip freeze > requirements.txt
```

This is often used to reproduce an environment later.

## Uninstalling Packages

```bash
pip uninstall requests
```

## Checking Installed Packages

```bash
pip list
```

## Basic Packaging Concepts

A Python project often includes:

- source code
- dependency metadata
- package configuration
- documentation

Common packaging files include:

- `pyproject.toml`
- `README.md`
- `requirements.txt`

## `pyproject.toml`

Modern Python packaging commonly uses `pyproject.toml` to describe build system and project metadata.

Example structure:

```toml
[build-system]
requires = ["setuptools>=61"]
build-backend = "setuptools.build_meta"

[project]
name = "my-package"
version = "0.1.0"
description = "Example package"
requires-python = ">=3.10"
```

## Source Layout Example

A common package layout looks like this:

```text
my-project/
├── pyproject.toml
├── README.md
├── src/
│   └── my_package/
│       ├── __init__.py
│       └── core.py
└── tests/
```

## `__init__.py`

A file named `__init__.py` marks a directory as a Python package in many workflows.

```python
# my_package/__init__.py
from .core import some_function
```

## Editable Installs for Local Development

For development, you can install a package in editable mode:

```bash
pip install -e .
```

This lets your code changes be picked up without reinstalling every time.

## Building Distributions

Common distribution formats:

- **source distribution**: `.tar.gz`
- **wheel**: `.whl`

If your project is configured for packaging, you can build artifacts with modern build tools such as `python -m build`.

## Best Practices

- Use a virtual environment for every project.
- Commit `pyproject.toml` and/or `requirements.txt` as appropriate.
- Do not commit `.venv/`.
- Pin dependencies when reproducibility matters.
- Separate runtime dependencies from development-only tools when possible.

## Example Development Workflow

```bash
python -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
pip install -r requirements.txt
```

---

# Appendix A: Recommended Learning Order

1. Variables and data types
2. Control flow
3. Functions
4. Strings and collections
5. Files and exceptions
6. OOP
7. Standard library utilities
8. Typing and testing

---

# Appendix B: Pythonic Habits Checklist

- Prefer readable code over clever code.
- Use `with` for files and locks.
- Use `pathlib` for path manipulation.
- Use f-strings for formatting.
- Use `is None` and `is not None`.
- Prefer `enumerate()` over manual index counters.
- Prefer `dict.get()` when keys may be missing.
- Avoid mutable default arguments.
- Keep functions small and focused.
- Use exceptions for exceptional situations, not normal control flow.

---

# Appendix C: One-Page OOP Memory Aid

- **Class**: blueprint
- **Object**: instance of a class
- **Attribute**: data attached to object/class
- **Method**: function inside class
- **Encapsulation**: protect internal state through interface
- **Inheritance**: derive class from another
- **Polymorphism**: same method name, different behavior
- **Abstraction**: expose what matters, hide implementation details

---

# Closing Notes

Python is easiest to remember when you practice small examples often. Keep this guide nearby as a quick refresher, but also try writing little scripts for:

- file parsing
- JSON config loading
- path manipulation
- class design
- date processing
- automation tasks

That combination of theory and repetition is what turns a cheat sheet into real working knowledge.
