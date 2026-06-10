#### Modeling the World in Code

## What OOP actually solves

Look at the task manager from Day 7. Every function takes `tasks` and `next_id` as arguments. State lives in global variables. Adding a new operation means remembering to pass the right variables in the right order.

```python
# Day 7 approach — state scattered everywhere
tasks = []
next_id = 1

def add_task(tasks, next_id, title, priority):
    ...
    return task, next_id + 1    # have to return next_id back out

def complete_task(tasks, task_id):
    ...
```

OOP solves this by bundling state and the functions that operate on it into a single unit — a **class**. The state lives inside the object. Functions become methods that access it directly.

---

## Classes and objects — the core concept

A **class** is a blueprint. An **object** is an instance built from that blueprint.

```python
class Dog:
    # __init__ is the constructor — called when you create an instance
    def __init__(self, name, breed):
        # self refers to the specific instance being created
        self.name = name      # instance attribute
        self.breed = breed    # instance attribute

    def bark(self):
        return f"{self.name} says: Woof!"

    def describe(self):
        return f"{self.name} is a {self.breed}"

# Creating instances
dog1 = Dog("Rex", "German Shepherd")
dog2 = Dog("Bella", "Labrador")

print(dog1.bark())       # Rex says: Woof!
print(dog2.describe())   # Bella is a Labrador
print(dog1.name)         # Rex — access attribute directly

# Each instance is independent
dog1.name = "Max"
print(dog1.name)    # Max
print(dog2.name)    # Bella — unchanged
```

`self` is not magic — it's just the convention for the first parameter of instance methods. When you call `dog1.bark()`, Python translates it to `Dog.bark(dog1)`. `self` is `dog1`.

---

## `__init__` — initializing state

```python
class BankAccount:
    def __init__(self, owner, balance=0.0):
        self.owner = owner
        self.balance = balance
        self.transactions = []    # each instance gets its OWN list

    def deposit(self, amount):
        if amount <= 0:
            raise ValueError("Deposit amount must be positive")
        self.balance += amount
        self.transactions.append(("deposit", amount))
        return self.balance

    def withdraw(self, amount):
        if amount <= 0:
            raise ValueError("Withdrawal amount must be positive")
        if amount > self.balance:
            raise ValueError(f"Insufficient funds. Balance: {self.balance:.2f}")
        self.balance -= amount
        self.transactions.append(("withdrawal", amount))
        return self.balance

    def get_history(self):
        return list(self.transactions)    # return a copy, not the internal list


account = BankAccount("Alice", 1000.0)
account.deposit(500)
account.withdraw(200)
print(account.balance)        # 1300.0
print(account.get_history())  # [('deposit', 500), ('withdrawal', 200)]
```

Notice `self.transactions = []` is inside `__init__`. This is correct — each instance gets its own list. The mutable default argument trap from Day 6 doesn't apply here because you're creating the list inside `__init__`, not as a default parameter.

---

## Instance methods, class methods, static methods

```python
class Temperature:
    # Class variable — shared across ALL instances
    unit = "Celsius"

    def __init__(self, value):
        self.value = value    # instance variable — unique per instance

    # Instance method — operates on a specific instance
    def to_fahrenheit(self):
        return (self.value * 9/5) + 32

    def to_kelvin(self):
        return self.value + 273.15

    # Class method — operates on the class, not an instance
    # First arg is cls (the class itself), not self
    @classmethod
    def from_fahrenheit(cls, f_value):
        celsius = (f_value - 32) * 5/9
        return cls(celsius)    # creates a new instance

    @classmethod
    def from_kelvin(cls, k_value):
        return cls(k_value - 273.15)

    # Static method — belongs to the class but doesn't access class or instance
    # No self or cls — it's just a regular function namespaced to the class
    @staticmethod
    def is_valid(value):
        return value >= -273.15    # absolute zero

    def __repr__(self):
        return f"Temperature({self.value}°{Temperature.unit})"


t1 = Temperature(100)
print(t1.to_fahrenheit())    # 212.0

t2 = Temperature.from_fahrenheit(32)
print(t2.value)              # 0.0

print(Temperature.is_valid(-300))    # False
print(Temperature.is_valid(25))      # True
```

**When to use each:**

- Instance method: needs access to `self` — the object's state
- Class method: factory methods that create instances, or operations on class-level state
- Static method: utility functions related to the class conceptually but not needing `self` or `cls`

---

## Properties — controlled attribute access

```python
class Circle:
    def __init__(self, radius):
        self._radius = radius    # convention: _ prefix = "internal, handle carefully"

    @property
    def radius(self):
        """Getter — called when you access circle.radius"""
        return self._radius

    @radius.setter
    def radius(self, value):
        """Setter — called when you assign circle.radius = x"""
        if value < 0:
            raise ValueError("Radius cannot be negative")
        self._radius = value

    @property
    def area(self):
        """Computed property — no setter, read-only"""
        import math
        return math.pi * self._radius ** 2

    @property
    def diameter(self):
        return self._radius * 2

    @diameter.setter
    def diameter(self, value):
        self.radius = value / 2    # uses the radius setter — validation runs


c = Circle(5)
print(c.radius)    # 5 — calls the getter
print(c.area)      # 78.53... — computed on access
c.radius = 10      # calls the setter — validates
c.radius = -1      # ValueError: Radius cannot be negative
print(c.diameter)  # 20
c.diameter = 14    # sets radius to 7 via the setter
```

Properties let you start with simple attributes and add validation later without changing the public interface. External code that uses `circle.radius` keeps working whether it's a plain attribute or a property.

---

## `__repr__` and `__str__` — how objects display themselves

```python
class Task:
    def __init__(self, id, title, priority="medium"):
        self.id = id
        self.title = title
        self.priority = priority
        self.done = False

    def __repr__(self):
        """For developers — unambiguous, used in debugger and REPL"""
        return f"Task(id={self.id!r}, title={self.title!r}, priority={self.priority!r}, done={self.done})"

    def __str__(self):
        """For end users — readable"""
        status = "✓" if self.done else "○"
        return f"[{self.id}] {status} [{self.priority.upper()}] {self.title}"


task = Task(1, "Fix login bug", "high")
print(task)          # [1] ○ [HIGH] Fix login bug   — uses __str__
print(repr(task))    # Task(id=1, title='Fix login bug', priority='high', done=False) — uses __repr__

tasks = [Task(1, "Buy milk"), Task(2, "Write tests")]
print(tasks)         # uses __repr__ for items inside collections
```

Always define `__repr__`. Define `__str__` when you want a different human-readable format. If only `__repr__` is defined, Python uses it for both.

---

## Redesigning the task manager as a class

This is the payoff. Compare this to Day 7:

```python
from pathlib import Path
import json


class TaskManager:
    """Manages a collection of tasks with file persistence."""

    VALID_PRIORITIES = {"low", "medium", "high"}

    def __init__(self, filepath="tasks.json"):
        self.filepath = Path(filepath)
        self._tasks = []
        self._next_id = 1
        self._load()

    def _load(self):
        """Load tasks from disk. Private — callers don't need to know about this."""
        if not self.filepath.exists():
            return
        try:
            with open(self.filepath, encoding="utf-8") as f:
                data = json.load(f)
            self._tasks = data.get("tasks", [])
            self._next_id = data.get("next_id", 1)
        except (json.JSONDecodeError, KeyError):
            print(f"Warning: could not load tasks from {self.filepath}")

    def save(self):
        """Save tasks to disk."""
        data = {"tasks": self._tasks, "next_id": self._next_id}
        with open(self.filepath, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2)

    def add(self, title, priority="medium"):
        """Add a task. Returns the new task dict."""
        if not title or not title.strip():
            raise ValueError("Title cannot be empty")
        if priority not in self.VALID_PRIORITIES:
            raise ValueError(f"Priority must be one of {self.VALID_PRIORITIES}")

        task = {
            "id": self._next_id,
            "title": title.strip(),
            "priority": priority,
            "done": False
        }
        self._tasks.append(task)
        self._next_id += 1
        self.save()
        return task

    def get(self, task_id):
        """Get a task by ID. Raises KeyError if not found."""
        for task in self._tasks:
            if task["id"] == task_id:
                return task
        raise KeyError(f"No task with ID {task_id}")

    def complete(self, task_id):
        """Mark a task as done. Returns the task."""
        task = self.get(task_id)    # raises KeyError if not found
        task["done"] = True
        self.save()
        return task

    def delete(self, task_id):
        """Delete a task. Returns the deleted task."""
        task = self.get(task_id)
        self._tasks.remove(task)
        self.save()
        return task

    def list(self, status=None, priority=None):
        """Return filtered tasks, sorted by priority."""
        result = self._tasks

        if status == "done":
            result = [t for t in result if t["done"]]
        elif status == "pending":
            result = [t for t in result if not t["done"]]

        if priority:
            result = [t for t in result if t["priority"] == priority]

        priority_order = {"high": 0, "medium": 1, "low": 2}
        return sorted(result, key=lambda t: priority_order[t["priority"]])

    @property
    def summary(self):
        """Return a summary dict of task counts."""
        total = len(self._tasks)
        done = sum(1 for t in self._tasks if t["done"])
        return {
            "total": total,
            "done": done,
            "pending": total - done,
            "high_pending": sum(
                1 for t in self._tasks
                if t["priority"] == "high" and not t["done"]
            )
        }

    def __len__(self):
        return len(self._tasks)

    def __repr__(self):
        return f"TaskManager(tasks={len(self)}, file={self.filepath!r})"


# Usage
manager = TaskManager()
manager.add("Fix login bug", "high")
manager.add("Update README", "low")
manager.add("Write tests", "high")
manager.complete(1)

for task in manager.list(status="pending"):
    print(task)

print(manager.summary)
print(len(manager))    # uses __len__
```

What changed vs Day 7:

- No global variables — state lives in `self`
- No passing `tasks` and `next_id` to every function
- Auto-saves after every mutation
- Auto-loads on startup
- Clean public interface: `add`, `complete`, `delete`, `list`, `summary`
- Private methods prefixed with `_` — callers don't need to know about `_load`
- `__len__` makes `len(manager)` work naturally

---

## Dunder methods — making objects behave like Python builtins

```python
class NumberList:
    def __init__(self, numbers):
        self._numbers = list(numbers)

    def __len__(self):
        return len(self._numbers)

    def __getitem__(self, index):
        return self._numbers[index]    # enables indexing: obj[0]

    def __contains__(self, item):
        return item in self._numbers   # enables: 5 in obj

    def __iter__(self):
        return iter(self._numbers)     # enables: for x in obj

    def __add__(self, other):
        return NumberList(self._numbers + other._numbers)  # enables: obj1 + obj2

    def __eq__(self, other):
        if isinstance(other, NumberList):
            return self._numbers == other._numbers
        return False

    def __repr__(self):
        return f"NumberList({self._numbers})"


nl = NumberList([1, 2, 3])
print(len(nl))          # 3
print(nl[0])            # 1
print(2 in nl)          # True
for n in nl:
    print(n)            # 1, 2, 3

nl2 = NumberList([4, 5])
nl3 = nl + nl2
print(nl3)              # NumberList([1, 2, 3, 4, 5])
```

You don't need to implement all of these. Implement the ones that make your object feel natural to use. At minimum: `__repr__`. Almost always: `__str__` if objects are displayed to users.

---

## The mental model to carry forward

A class is a contract between the implementation and the caller. The caller sees the public interface — `add`, `complete`, `list`, `summary`. The implementation can change completely as long as the interface stays the same.

**Three things OOP gives you:**

- **Encapsulation** — state and behavior live together, implementation details are hidden
- **A natural place for state** — `self` replaces global variables and parameter passing
- **Reusability** — create multiple independent instances from one class: `TaskManager("work.json")`, `TaskManager("personal.json")`

**The signals that something should be a class:**

- You're passing the same group of variables to multiple functions
- You want multiple independent instances of the same thing
- You need to hide implementation details from callers
- Your data has behavior that logically belongs with it

---

Day 13 covers inheritance, `super()`, dunder methods in depth, and `@dataclass` — the modern way to write simple data-holding classes without boilerplate. Ready when you are.

[[Real Python]]