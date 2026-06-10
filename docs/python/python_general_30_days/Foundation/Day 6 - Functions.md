## Writing Code That Does One Thing Well

## What a function actually is

A function is a named, reusable block of code that takes input, does work, and optionally returns output. The keyword is _reusable_ — if you write the same logic twice, it should be a function.

```python
def greet(name):
    return f"Hello, {name}!"

result = greet("Alice")
print(result)    # Hello, Alice!
```

`def` defines the function. The name follows Python naming conventions — lowercase, underscores, descriptive. The body is indented. `return` sends a value back to the caller.

---

## Parameters and arguments

These words are used interchangeably but mean different things. **Parameters** are the variables in the function definition. **Arguments** are the values you pass when calling it.

```python
def add(x, y):       # x and y are parameters
    return x + y

add(3, 5)            # 3 and 5 are arguments
```

**Positional arguments — order matters:**

```python
def describe(name, age, city):
    return f"{name}, {age}, from {city}"

print(describe("Alice", 30, "London"))   # Alice, 30, from London
print(describe(30, "Alice", "London"))   # 30, Alice, from London — wrong but no error
```

**Keyword arguments — order doesn't matter:**

```python
print(describe(age=30, city="London", name="Alice"))   # Alice, 30, from London
print(describe("Alice", city="London", age=30))        # mix of both — positional first
```

**Default parameter values:**

```python
def greet(name, greeting="Hello"):
    return f"{greeting}, {name}!"

print(greet("Alice"))              # Hello, Alice!
print(greet("Alice", "Hi"))        # Hi, Alice!
print(greet("Alice", greeting="Hey"))  # Hey, Alice!
```

Default parameters must come after non-default ones. This fails:

```python
def greet(greeting="Hello", name):   # SyntaxError
    pass
```

**The mutable default argument trap — one of Python's most common bugs:**

```python
# WRONG — don't do this
def add_item(item, items=[]):
    items.append(item)
    return items

print(add_item("apple"))    # ['apple']
print(add_item("banana"))   # ['apple', 'banana'] — the list persists between calls!
print(add_item("cherry"))   # ['apple', 'banana', 'cherry'] — keeps growing

# Why: default values are created ONCE when the function is defined,
# not each time it's called. The same list object is reused every call.

# CORRECT — use None as default, create inside the function
def add_item(item, items=None):
    if items is None:
        items = []
    items.append(item)
    return items

print(add_item("apple"))    # ['apple']
print(add_item("banana"))   # ['banana'] — fresh list each time
```

This catches experienced developers too. Any mutable type — list, dict, set — must never be a default argument directly.

---

## *args and **kwargs — variable arguments

```python
# *args — accept any number of positional arguments
def sum_all(*args):
    print(type(args))    # <class 'tuple'>
    return sum(args)

print(sum_all(1, 2, 3))          # 6
print(sum_all(1, 2, 3, 4, 5))    # 15

# **kwargs — accept any number of keyword arguments
def print_info(**kwargs):
    print(type(kwargs))    # <class 'dict'>
    for key, value in kwargs.items():
        print(f"{key}: {value}")

print_info(name="Alice", age=30, city="London")
# name: Alice
# age: 30
# city: London

# Combining everything — order matters: positional, *args, keyword, **kwargs
def everything(a, b, *args, keyword="default", **kwargs):
    print(f"a={a}, b={b}")
    print(f"args={args}")
    print(f"keyword={keyword}")
    print(f"kwargs={kwargs}")

everything(1, 2, 3, 4, keyword="custom", x=10, y=20)
# a=1, b=2
# args=(3, 4)
# keyword=custom
# kwargs={'x': 10, 'y': 20}
```

**Unpacking into function calls — the flip side:**

```python
def add(x, y, z):
    return x + y + z

nums = [1, 2, 3]
print(add(*nums))      # unpacks list into positional args — same as add(1, 2, 3)

data = {"x": 1, "y": 2, "z": 3}
print(add(**data))     # unpacks dict into keyword args — same as add(x=1, y=2, z=3)
```

---

## Return values

```python
# No return statement — returns None implicitly
def say_hello(name):
    print(f"Hello, {name}")

result = say_hello("Alice")
print(result)    # None

# Return early — exit the function immediately
def divide(a, b):
    if b == 0:
        return None    # early return on bad input
    return a / b

# Return multiple values — actually returns a tuple
def min_max(numbers):
    return min(numbers), max(numbers)

low, high = min_max([3, 1, 4, 1, 5, 9])
print(low, high)    # 1 9

result = min_max([3, 1, 4])
print(result)       # (1, 4) — it's a tuple
print(result[0])    # 1
```

**Return as flow control:**

```python
# Instead of nested if/else, return early and keep the happy path clean
def process_user(user):
    if user is None:
        return "No user provided"
    if not user.get("name"):
        return "User has no name"
    if user.get("age", 0) < 18:
        return "User is a minor"

    # At this point we know the user is valid
    return f"Processing {user['name']}, age {user['age']}"

print(process_user(None))                           # No user provided
print(process_user({"name": "Alice", "age": 30}))  # Processing Alice, age 30
```

This pattern is called **early return** or **guard clauses**. It keeps the main logic at the lowest indentation level and makes the function readable top to bottom without mentally tracking nested conditions.

---

## Scope — where variables live

```python
x = 10    # global scope

def foo():
    x = 20    # local scope — different variable, shadows the global
    print(x)  # 20

foo()
print(x)      # 10 — global unchanged

# Reading a global from inside a function — works fine
name = "Alice"
def greet():
    print(f"Hello, {name}")    # reads global name — no problem

greet()    # Hello, Alice
```

**The global keyword — use sparingly:**

```python
count = 0

def increment():
    global count     # explicitly says "use the global count"
    count += 1

increment()
increment()
print(count)    # 2

# In practice, global variables are a code smell
# Pass values in, return values out — that's the clean way
def increment(count):
    return count + 1

count = 0
count = increment(count)
count = increment(count)
print(count)    # 2
```

**LEGB rule — how Python resolves variable names:**

```python
x = "global"

def outer():
    x = "enclosing"

    def inner():
        x = "local"
        print(x)    # local — checks Local first

    inner()
    print(x)        # enclosing

outer()
print(x)            # global
```

Python looks up names in this order: **L**ocal → **E**nclosing → **G**lobal → **B**uilt-in. The first match wins.

---

## Functions are first-class objects

This is what makes Python powerful. Functions can be stored in variables, passed as arguments, and returned from other functions.

```python
def square(x):
    return x ** 2

def cube(x):
    return x ** 3

# Store in a variable
operation = square
print(operation(4))    # 16

# Store in a list
operations = [square, cube]
for op in operations:
    print(op(3))    # 9, then 27

# Pass as an argument
def apply(func, value):
    return func(value)

print(apply(square, 5))    # 25
print(apply(cube, 3))      # 27

# Pass to built-ins that accept functions
numbers = [-3, -1, 2, 4, -2]
print(sorted(numbers, key=abs))       # [-1, 2, -2, -3, 4] — sort by absolute value

words = ["banana", "apple", "fig", "cherry"]
print(sorted(words, key=len))         # ['fig', 'apple', 'banana', 'cherry']
print(min(words, key=len))            # fig
```

---

## Lambda — anonymous functions

```python
# A lambda is a function defined in one expression
square = lambda x: x ** 2
print(square(4))    # 16

# Equivalent to:
def square(x):
    return x ** 2

# Lambda shines when passed inline — no need to define a named function
numbers = [1, -3, 2, -1, 4]
print(sorted(numbers, key=lambda x: abs(x)))    # [1, -1, 2, -3, 4]

people = [{"name": "Alice", "age": 30}, {"name": "Bob", "age": 25}]
print(sorted(people, key=lambda p: p["age"]))   # Bob first, then Alice

# Lambda limitations — one expression only, no statements
# If you need more than one line, write a proper function
```

Use lambdas for short, throwaway functions passed to `sorted()`, `map()`, `filter()`. For anything more complex, name it.

---

## Docstrings — how to document functions

```python
def calculate_tax(income, rate=0.2):
    """
    Calculate tax based on income and rate.

    Args:
        income: Gross income amount (float or int)
        rate: Tax rate as a decimal, defaults to 0.2 (20%)

    Returns:
        Tax amount as a float

    Raises:
        ValueError: If income is negative
    """
    if income < 0:
        raise ValueError("Income cannot be negative")
    return income * rate

# Access the docstring
print(calculate_tax.__doc__)
help(calculate_tax)
```

Write docstrings for any function that isn't immediately obvious from its name and parameters. In a team, undocumented functions waste everyone's time.

---

## Function design — the principles that matter

**One function, one job:**

```python
# Bad — does too many things
def process_user_data(data):
    # validates data
    # transforms data
    # saves to database
    # sends email
    # logs the action
    pass

# Good — each function does one thing
def validate_user(data): ...
def transform_user(data): ...
def save_user(user): ...
def notify_user(user): ...
def log_action(action): ...

def process_user_data(data):
    user = validate_user(data)
    user = transform_user(user)
    save_user(user)
    notify_user(user)
    log_action("user_created")
```

**Keep functions short.** If a function doesn't fit on your screen, it's probably doing too much. The sweet spot is 5–20 lines. This isn't a hard rule but it's a useful signal.

**Name functions with verbs:**

```python
# Bad names
def user_data(): ...
def tax(): ...
def check(): ...

# Good names
def get_user_data(): ...
def calculate_tax(): ...
def is_valid_email(): ...    # boolean functions start with is/has/can
def has_permission(): ...
def can_access(): ...
```

---

## The mental model to carry forward

A function is a **contract**: here's what I take in, here's what I give back, here's what I do in between. The caller shouldn't need to know the implementation — just the contract.

The practical rules:

- Pass data in through parameters, get data out through return values — avoid globals
- Use early returns to handle edge cases before the main logic
- Default arguments should never be mutable types
- Name functions with verbs that describe what they do
- If you're writing the same logic twice, extract it into a function immediately

Tomorrow is Day 7 — the first project day. You'll build a command-line task manager using everything from Week 1. This is where it all connects.

[[Foundation]]