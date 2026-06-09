

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

#### Explanation

- This gets right to the heart of how Python manages memory over long periods. 
- In standard desktop scripts that run for five seconds, you rarely notice memory management
- But for an IoT daemon—a background process running on a Raspberry Pi or an edge gateway for months at a time—this behavior can be the difference between a stable system and a device that suddenly crashes in the middle of the night.

Here is exactly what that quote means, broken down by the mechanics and the real-world IoT risk.

##### 1. The Core Problem: Circular References

As we touched on earlier, Python’s primary way of cleaning up memory is **Reference Counting**. Every time you assign an object to a variable, its counter goes up by 1. When that variable goes out of scope, the counter goes down by 1. When it hits 0, Python immediately deletes it.

A **circular reference** completely breaks this system. It happens when Object A points to Object B, and Object B points to Object A.

Let's look at it in code:

Python

```
class Node:
    def __init__(self):
        self.scout = None

# 1. Create two nodes
alpha = Node()
beta = Node()

# 2. Link them to each other (Circular Reference)
alpha.scout = beta
beta.scout = alpha

# 3. Sever the ties from our main program
del alpha
del beta
```

When we run `del alpha` and `del beta`, we destroy our program's access to those objects. However, because they are still pointing to _each other_ on the heap, **their reference counts only drop from 2 to 1.** Since the count is `1` (not `0`), Python's reference counter thinks they are still being used. They are now completely stranded, unreachable by your code, but occupying memory. This is a classic **memory leak**.

##### Going deeper in explanation if needed

To make this crystal clear, let’s look at exactly how Python tracks these numbers on the heap before and after you run `del`.

Behind the scenes, every Python object has a hidden header that keeps track of its **Reference Count (rc)**. This count is a simple integer: "How many things are currently pointing at me?"

Here is the step-by-step breakdown of how the memory trap springs shut.

##### Phase 1: Creating the Loop

When you instantiate the objects and link them together, you have two variables on the **Stack** (`alpha` and `beta`) pointing to two nodes on the **Heap**.

Python

```
alpha = Node()
beta = Node()
alpha.scout = beta
beta.scout = alpha
```

Because two separate things point to each node, their reference counts look like this:

- **Node A** is pointed to by: the stack variable `alpha` AND the property `beta.scout`. **(rc = 2)**
    
- **Node B** is pointed to by: the stack variable `beta` AND the property `alpha.scout`. **(rc = 2)**
    

##### Phase 2: Running `del alpha` and `del beta`

The `del` keyword in Python is slightly misunderstood. It **does not delete objects**. It only deletes the _variable name_ from your current scope (the Stack) and decreases the target object's reference count by 1.

When you execute:

Python

```
del alpha
del beta
```

1. The variable `alpha` vanishes from the stack. **Node A's reference count drops from 2 to 1.**
    
2. The variable `beta` vanishes from the stack. **Node B's reference count drops from 2 to 1.**
    

##### The "Stranded" State

Look at the heap now. The reference counts for both Node A and Node B are sitting at **1**.

Because Python's standard memory manager only frees memory when a count hits **0**, it looks at these nodes and says: _"A count of 1 means someone is still using them. Leave them alone."_

But look at your code: you no longer have the variables `alpha` or `beta`. You have no way to type a command to access them, modify them, or delete them. They are completely orphaned in a ghost loop—invisible to your code, but completely alive to the CPU.

This is the memory leak. They will sit there occupying RAM forever until the secondary **Cyclic Garbage Collector** triggers a global sweep to track down these unreachable islands.

## 2. The Solution: The Cyclic Garbage Collector (gc)

To fix this, Python runs a secondary system in the background: the **Cyclic Garbage Collector**.

Instead of just watching counters, the Garbage Collector periodically pauses your program, goes to the heap, and walks the entire graph of objects. It asks: _"Can I actually reach this object starting from the global scope or the current stack?"_

If it finds a group of objects (like `alpha` and `beta`) that are talking to each other but are completely cut off from the rest of the application, it steps in, breaks the loop, and clears them out.

## 3. Why this is a "Real Memory Leak Class" for IoT Daemons

The quote specifically calls out **long-running IoT daemons** using **caches** or **subscriber lists**. Here is why that environment is uniquely vulnerable:

### The "Subscriber List" Trap (Observer Pattern)

In IoT, devices constantly listen for events (e.g., a temperature sensor readings, MQTT broker messages). You often have a central `EventManager` and various `SensorListener` objects.

1. A `SensorListener` registers itself to the `EventManager` (the manager now holds a reference to the listener).
    
2. The `SensorListener` keeps a reference to the `EventManager` so it knows where to send data.
    
3. **The Leak:** If your daemon dynamically creates and destroys sensor tasks (e.g., a temporary Bluetooth connection to a nearby beacon), you might think deleting the task frees the memory. But because the `EventManager` and the task still reference each other, they stay alive forever.
    

### The Problem with Edge Hardware

The Garbage Collector doesn't run continuously; it triggers based on a threshold of object allocations. On a powerful server, the GC runs frequently and smoothly.

On a restricted **IoT edge device** (like a micro-linux gateway with limited RAM and a slower CPU):

- **CPU Spikes:** When the cyclic GC finally triggers to clean up thousands of leaked circular references, it can cause the CPU to spike, temporarily freezing your real-time IoT daemon (causing missed sensor data or dropped network packets).
    
- **Out Of Memory (OOM) Crashes:** If the daemon leaks memory faster than the GC runs, the Linux kernel will eventually step in and violently kill your daemon process (`OOM-killer`) to save the operating system.
    

## How IoT Developers Fight This

To prevent the daemon from bloating over months of runtime, Python developers use `weakref` (Weak References).

A weak reference allows you to point to an object **without increasing its reference count**. If the original object is deleted, the weak reference automatically becomes `None`, breaking the cycle before a leak can even form.

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

[[Foundation]]