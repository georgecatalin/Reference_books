# Day 1 — Python Memory Model

Before any code: the single most important mental shift from C to Python.

In C, a variable _is_ a memory location. When you write `int x = 5`, you own a box at a specific address, and `5` lives in it.

In Python, **variables are names. Objects are the things that exist.** A variable is just a label stuck onto an object that lives on the heap. This changes everything.

---

## The object model

Every value in Python — integers, strings, lists, your custom classes — is a heap-allocated object with three fields:

- **type** — what kind of thing it is
- **value** — the actual data
- **reference count** — how many names are pointing at itThis is the root of almost every Python gotcha. In C, `y = x` copies the value into a new box. In Python, `y = x` gives a second name to the same object. Let that settle in — we're about to see exactly why it matters.

---

## Reference counting and the GC

Python tracks how many names point to each object. When that count drops to zero, the memory is freed automatically. No `malloc`, no `free`, no ownership.

```python
import sys

x = [1, 2, 3]
print(sys.getrefcount(x))   # 2 — x itself + the argument passed to getrefcount

y = x                        # same object, second name
print(sys.getrefcount(x))   # 3

del y                        # removes one name, not the object
print(sys.getrefcount(x))   # back to 2 — object still alive

del x                        # refcount hits 0 — object is freed
```

The cyclic garbage collector handles cases where two objects reference each other (neither can reach zero on its own). For long-running IoT daemons this matters — circular references in caches or subscriber lists are a real memory leak class.

---

## The mutation trap — where C instincts fail you

This is the most common real-world bug for developers coming from C:

```python
# This looks innocent
a = [1, 2, 3]
b = a            # NOT a copy — b points to the same list

b.append(4)
print(a)         # [1, 2, 3, 4]  <-- a was "modified" too
```

With immutable types (int, str, tuple) this doesn't bite you — Python can't mutate them. With mutable types (list, dict, custom objects) it absolutely does. In an MQTT ingester, if you pass a `payload_dict` around without copying it, any handler that mutates it corrupts the others.

### Shallow copy vs deep copy

```python
import copy

original = {"device": "sensor_01", "readings": [22.1, 22.3, 22.5]}

# Shallow copy — new dict, but inner list is still shared
shallow = original.copy()           # or dict(original) or {**original}
shallow["device"] = "sensor_02"     # safe — new key/value
shallow["readings"].append(22.7)    # mutates original["readings"] too!

print(original["readings"])         # [22.1, 22.3, 22.5, 22.7]  -- surprise

# Deep copy — fully independent at every level
deep = copy.deepcopy(original)
deep["readings"].append(99.9)
print(original["readings"])         # unchanged — truly independent
```

The rule: `copy()` goes one level. `deepcopy()` goes all the way down. In embedded/IoT code with nested state structures, `deepcopy` is what you usually want when snapshotting device state.

---

## Interning — a memory optimization that causes surprising `is` behavior

Python interns (reuses) small integers (-5 to 256) and short strings. This is a CPython optimization, not a language guarantee:

```python
a = 256
b = 256
print(a is b)   # True  — same interned object

a = 257
b = 257
print(a is b)   # False — two separate objects (may vary by implementation)

# The rule: always use == for value equality, never `is`
# `is` means "same object in memory", not "same value"
```

This trips up C developers who think of `==` and `is` as equivalent. In Python they are not. `is` is pointer equality. `==` calls `__eq__`. Use `==` for comparing values, always.

---

## Today's deliverable

Build this script from scratch. Don't copy-paste — type it, run it, understand every line:

```python
import copy
import sys

# === Part 1: demonstrate reference sharing ===
def demo_references():
    a = {"device_id": "dev_01", "sensors": [20.1, 20.3]}
    b = a

    print("Same object?", a is b)               # True
    print("Refcount:", sys.getrefcount(a))       # 3 (a, b, getrefcount arg)

    b["device_id"] = "dev_02"
    print("a after mutating b:", a["device_id"]) # dev_02 — same object

# === Part 2: shallow copy breaks on nested data ===
def demo_shallow_copy():
    original = {"device_id": "dev_01", "readings": [20.1, 20.3]}
    shallow  = original.copy()

    shallow["device_id"] = "dev_99"              # safe — top-level key
    shallow["readings"].append(99.9)             # DANGER — mutates original

    print("original device_id:", original["device_id"])   # dev_01 (unchanged)
    print("original readings:",  original["readings"])    # has 99.9! (shared)

# === Part 3: deep copy is truly independent ===
def demo_deep_copy():
    original = {"device_id": "dev_01", "readings": [20.1, 20.3]}
    deep     = copy.deepcopy(original)

    deep["readings"].append(99.9)
    print("original readings:", original["readings"])     # unchanged

# === Part 4: is vs == ===
def demo_identity():
    x = [1, 2, 3]
    y = [1, 2, 3]
    print("== :", x == y)   # True  — same value
    print("is :", x is y)   # False — different objects

if __name__ == "__main__":
    demo_references()
    demo_shallow_copy()
    demo_deep_copy()
    demo_identity()
```

