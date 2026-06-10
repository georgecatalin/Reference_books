## Writing Code That Fails Gracefully

## Why error handling is not optional

Every program that touches the outside world — files, networks, user input, databases — will encounter conditions it didn't expect. The question isn't whether errors will happen. It's whether your program crashes with a traceback or handles the situation and keeps running.

```python
# Without error handling
age = int(input("Enter age: "))    # user types "twenty" — program crashes

# With error handling
try:
    age = int(input("Enter age: "))
except ValueError:
    print("Please enter a number")
```

The difference between a script and a production application is largely error handling.

---

## How Python errors work

When something goes wrong, Python raises an **exception** — an object that describes what went wrong and where. If nothing catches it, it propagates up the call stack until it reaches the top and prints a traceback.

```python
def divide(a, b):
    return a / b

def calculate(x, y):
    return divide(x, y)

calculate(10, 0)

# Traceback (most recent call last):
#   File "script.py", line 7, in <module>
#     calculate(10, 0)
#   File "script.py", line 5, in calculate
#     return divide(x, y)
#   File "script.py", line 2, in divide
#     return a / b
# ZeroDivisionError: division by zero
```

Read tracebacks bottom to top — the last line tells you what went wrong, the lines above tell you how you got there.

---

## The exception hierarchy

```
BaseException
├── SystemExit              # sys.exit() — don't catch this
├── KeyboardInterrupt       # Ctrl+C — usually don't catch this
└── Exception               # catch this or its subclasses
    ├── ValueError          # right type, wrong value: int("hello")
    ├── TypeError           # wrong type: "hello" + 5
    ├── NameError           # variable doesn't exist
    ├── AttributeError      # object has no such attribute
    ├── KeyError            # dict key doesn't exist
    ├── IndexError          # list index out of range
    ├── FileNotFoundError   # file doesn't exist (subclass of OSError)
    ├── PermissionError     # no access (subclass of OSError)
    ├── ZeroDivisionError   # division by zero
    ├── StopIteration       # iterator exhausted
    ├── RuntimeError        # generic runtime error
    └── ImportError         # module not found
```

Knowing this tree matters because catching a parent class catches all its children:

```python
except OSError:    # catches FileNotFoundError, PermissionError, and others
except Exception:  # catches everything except SystemExit and KeyboardInterrupt
```

---

## try/except — the full syntax

```python
try:
    # code that might raise an exception
    result = 10 / 0

except ZeroDivisionError:
    # runs if ZeroDivisionError is raised
    print("Cannot divide by zero")

except (ValueError, TypeError) as e:
    # catch multiple exceptions in one clause
    # 'as e' binds the exception object to name e
    print(f"Value or type error: {e}")

except Exception as e:
    # catch-all — runs for any exception not caught above
    print(f"Unexpected error: {e}")

else:
    # runs ONLY if no exception was raised
    print(f"Result: {result}")

finally:
    # runs ALWAYS — exception or not
    # used for cleanup: close files, release locks, etc.
    print("Done")
```

**`else` and `finally` are both optional.** Use `else` when you want to run code only on success. Use `finally` for cleanup that must always happen.

---

## Catching exceptions — the right level of specificity

```python
# Too broad — hides real bugs
try:
    data = process_user(user_input)
except Exception:
    print("Something went wrong")    # what went wrong? no idea

# Too specific — misses related errors
try:
    with open(filepath) as f:
        data = f.read()
except FileNotFoundError:
    print("File not found")
# PermissionError, IsADirectoryError — not handled, will crash

# Right level — catch what you can handle
try:
    with open(filepath, encoding="utf-8") as f:
        data = f.read()
except FileNotFoundError:
    print(f"Config file missing: {filepath}")
    data = default_config()
except PermissionError:
    print(f"Cannot read {filepath} — check permissions")
    data = None
except OSError as e:
    print(f"File error: {e}")
    data = None
```

The rule: catch the most specific exception you can handle meaningfully. Don't catch what you can't handle — let it propagate to something that can.

---

## The exception object — what it tells you

```python
try:
    int("hello")
except ValueError as e:
    print(e)             # invalid literal for int() with base 10: 'hello'
    print(type(e))       # <class 'ValueError'>
    print(repr(e))       # ValueError("invalid literal for int() with base 10: 'hello'")

# For chained exceptions
try:
    open("missing.txt")
except FileNotFoundError as e:
    print(e.filename)    # missing.txt — OSError subclasses have extra attributes
    print(e.strerror)    # No such file or directory
    print(e.errno)       # 2
```

---

## Raising exceptions — telling callers something went wrong

```python
def get_age(value):
    try:
        age = int(value)
    except ValueError:
        raise ValueError(f"Age must be a number, got: {value!r}")

    if age < 0:
        raise ValueError(f"Age cannot be negative, got: {age}")
    if age > 150:
        raise ValueError(f"Age {age} is unrealistically large")

    return age

# Usage
try:
    age = get_age("twenty")
except ValueError as e:
    print(f"Invalid age: {e}")
```

`raise` with no arguments re-raises the current exception — useful when you want to log then re-raise:

```python
try:
    result = risky_operation()
except Exception as e:
    log_error(e)    # log it
    raise           # re-raise the same exception unchanged
```

---

## Exception chaining — preserving context

```python
# When handling one exception causes another, chain them
def load_config(path):
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    except FileNotFoundError as e:
        raise RuntimeError(f"Config file missing: {path}") from e
        # 'from e' attaches the original exception as the cause
        # traceback shows both exceptions and their relationship

# Suppress the original exception — use 'from None'
def parse_id(value):
    try:
        return int(value)
    except ValueError:
        raise ValueError(f"Invalid ID: {value!r}") from None
        # hides the original ValueError — cleaner error message for the caller
```

---

## Custom exceptions — building your own

```python
# Define custom exceptions for your domain
class TaskError(Exception):
    """Base exception for task manager errors."""
    pass

class TaskNotFoundError(TaskError):
    """Raised when a task ID doesn't exist."""
    def __init__(self, task_id):
        self.task_id = task_id
        super().__init__(f"No task with ID {task_id}")

class InvalidPriorityError(TaskError):
    """Raised when an invalid priority is given."""
    def __init__(self, priority):
        self.priority = priority
        valid = ("low", "medium", "high")
        super().__init__(f"Invalid priority '{priority}'. Must be one of: {valid}")

# Usage
def get_task(tasks, task_id):
    for task in tasks:
        if task["id"] == task_id:
            return task
    raise TaskNotFoundError(task_id)

try:
    task = get_task(tasks, 999)
except TaskNotFoundError as e:
    print(e)              # No task with ID 999
    print(e.task_id)      # 999 — access the structured data

# Catching the base class catches all subclasses
try:
    something()
except TaskError as e:
    print(f"Task operation failed: {e}")
```

Custom exceptions serve two purposes: they make your errors identifiable by name (not just message text), and they can carry structured data about what went wrong.

---

## Context managers and guaranteed cleanup

You've seen `with open()`. Here's what's happening underneath — and how to write your own:

```python
# The with statement calls __enter__ on open, __exit__ on leaving
# __exit__ is called even if an exception occurs

# Using contextlib for simple cases
from contextlib import contextmanager

@contextmanager
def managed_resource(name):
    print(f"Acquiring {name}")
    try:
        yield name    # execution pauses here while the with block runs
    except Exception as e:
        print(f"Error during {name}: {e}")
        raise
    finally:
        print(f"Releasing {name}")    # always runs

with managed_resource("database connection") as resource:
    print(f"Using {resource}")
    # raise ValueError("oops")    # uncomment to see cleanup still happens

# Output:
# Acquiring database connection
# Using database connection
# Releasing database connection
```

---

## Real patterns used in production code

**Retry logic:**

```python
import time

def retry(func, max_attempts=3, delay=1.0, exceptions=(Exception,)):
    """Retry a function on failure."""
    for attempt in range(1, max_attempts + 1):
        try:
            return func()
        except exceptions as e:
            if attempt == max_attempts:
                raise    # re-raise on final attempt
            print(f"Attempt {attempt} failed: {e}. Retrying in {delay}s...")
            time.sleep(delay)

def fetch_data():
    # simulating a flaky network call
    import random
    if random.random() < 0.7:
        raise ConnectionError("Network timeout")
    return "data"

result = retry(fetch_data, max_attempts=3, delay=0.5, exceptions=(ConnectionError,))
```

**Validation with early raises:**

```python
def create_user(name, email, age):
    """Validate and create a user dict."""
    if not name or not name.strip():
        raise ValueError("Name cannot be empty")
    if "@" not in email or "." not in email.split("@")[-1]:
        raise ValueError(f"Invalid email: {email!r}")
    if not isinstance(age, int):
        raise TypeError(f"Age must be an integer, got {type(age).__name__}")
    if not 0 <= age <= 150:
        raise ValueError(f"Age must be between 0 and 150, got {age}")

    return {
        "name": name.strip(),
        "email": email.lower().strip(),
        "age": age
    }
```

**Graceful degradation — failing partially instead of completely:**

```python
def process_records(records):
    """Process records, skip failures instead of crashing."""
    results = []
    errors = []

    for i, record in enumerate(records):
        try:
            result = process_single(record)
            results.append(result)
        except ValueError as e:
            errors.append({"index": i, "record": record, "error": str(e)})
        except Exception as e:
            errors.append({"index": i, "record": record, "error": f"Unexpected: {e}"})

    if errors:
        print(f"Processed {len(results)} records, {len(errors)} failed:")
        for err in errors:
            print(f"  Record {err['index']}: {err['error']}")

    return results, errors
```

---

## What not to do — the anti-patterns

```python
# 1. Bare except — catches everything including SystemExit, KeyboardInterrupt
try:
    something()
except:              # never do this
    pass

# 2. Catching Exception and silently passing
try:
    something()
except Exception:
    pass             # swallows errors silently — bugs disappear

# 3. Catching too broadly and losing information
try:
    complex_operation()
except Exception as e:
    print("Error")   # threw away e — what error? where?

# 4. Using exceptions for normal control flow
# Don't do this:
try:
    value = my_dict["key"]
except KeyError:
    value = "default"

# Do this instead:
value = my_dict.get("key", "default")

# 5. Re-raising with 'raise e' instead of 'raise'
try:
    something()
except Exception as e:
    raise e    # loses original traceback location
    raise      # correct — preserves full traceback
```

---

## The mental model to carry forward

Exceptions are not failures — they're signals. They say "something happened that the current code can't handle, and I'm passing it up to whoever can."

Your job as the developer is to decide: at each layer of your program, which exceptions can I handle here, and which should I let bubble up?

**The layered approach:**

- Low-level functions: raise specific exceptions with clear messages
- Mid-level functions: catch specific exceptions, transform them into domain exceptions
- Top-level: catch broadly, log, and show user-friendly messages

```python
# Low level
def read_file(path):
    # raises FileNotFoundError, PermissionError, etc.

# Mid level
def load_config(path):
    try:
        return read_file(path)
    except FileNotFoundError:
        raise ConfigError(f"Config not found: {path}") from None

# Top level
def main():
    try:
        config = load_config("config.json")
    except ConfigError as e:
        print(f"Startup failed: {e}")
        sys.exit(1)
```

Each layer knows about its own domain. `main()` knows about configs. `load_config()` knows about config errors. `read_file()` knows about file system errors. None of them bleed into each other.

---

Day 10 is modules, packages, pip, and virtual environments — how real Python projects are structured and how you bring in external libraries. Ready when you are.

[[Real Python]]