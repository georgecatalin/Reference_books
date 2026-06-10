[[Advanced]]

## What design patterns actually are

A design pattern is a named solution to a recurring problem. The name matters as much as the solution — when you say "that's a factory" or "use an observer here," everyone on the team immediately understands the structure without explaining it.

This is not the full Gang of Four catalog. This is the patterns that appear constantly in real Python code, with practical implementations — not textbook UML diagrams.

---

## Singleton — one instance, globally accessible

Ensures a class has exactly one instance. Used for configuration, logging, database connection pools.

```python
class SingletonMeta(type):
    _instances = {}

    def __call__(cls, *args, **kwargs):
        if cls not in cls._instances:
            cls._instances[cls] = super().__call__(*args, **kwargs)
        return cls._instances[cls]


class Config(metaclass=SingletonMeta):
    def __init__(self):
        self.debug = False
        self.db_url = "sqlite:///app.db"
        self.api_key = ""

    def load(self, path):
        import json
        with open(path) as f:
            data = json.load(f)
        self.debug = data.get("debug", False)
        self.db_url = data.get("db_url", self.db_url)
        self.api_key = data.get("api_key", "")


config1 = Config()
config2 = Config()
print(config1 is config2)    # True — same object

config1.debug = True
print(config2.debug)         # True — same object
```

**The simpler Python way — module-level instance:**

```python
# config.py
class _Config:
    def __init__(self):
        self.debug = False
        self.db_url = "sqlite:///app.db"

config = _Config()    # module-level singleton

# usage
from config import config
config.debug = True
```

Modules are singletons by nature — Python caches them after first import. A module-level instance is the idiomatic Python singleton. The metaclass approach is for when you need to enforce the pattern at the class level.

---

## Factory — create objects without specifying exact class

Decouples the code that uses an object from the code that creates it.

```python
from abc import ABC, abstractmethod
from dataclasses import dataclass


class Notification(ABC):
    @abstractmethod
    def send(self, recipient: str, message: str) -> bool:
        pass


@dataclass
class EmailNotification(Notification):
    smtp_host: str = "smtp.gmail.com"

    def send(self, recipient: str, message: str) -> bool:
        print(f"Email → {recipient}: {message}")
        return True


@dataclass
class SMSNotification(Notification):
    from_number: str = "+1234567890"

    def send(self, recipient: str, message: str) -> bool:
        if len(message) > 160:
            return False
        print(f"SMS → {recipient}: {message}")
        return True


@dataclass
class SlackNotification(Notification):
    webhook_url: str = ""

    def send(self, recipient: str, message: str) -> bool:
        print(f"Slack → #{recipient}: {message}")
        return True


# Simple factory function — most common in Python
def create_notification(channel: str, **kwargs) -> Notification:
    channels = {
        "email": EmailNotification,
        "sms": SMSNotification,
        "slack": SlackNotification,
    }
    cls = channels.get(channel)
    if cls is None:
        raise ValueError(f"Unknown channel {channel!r}. Options: {list(channels)}")
    return cls(**kwargs)


# Usage — caller doesn't know which class is instantiated
notifier = create_notification("email", smtp_host="smtp.company.com")
notifier.send("alice@example.com", "Your order shipped")

notifier = create_notification("sms")
notifier.send("+44123456789", "Code: 4821")

# Loading from config — the factory shines here
import os
channel = os.environ.get("NOTIFICATION_CHANNEL", "email")
notifier = create_notification(channel)
```

**Registry factory — self-registering, extensible:**

```python
class NotificationRegistry:
    _registry: dict[str, type] = {}

    @classmethod
    def register(cls, name: str):
        """Decorator that registers a notification class."""
        def decorator(notification_cls):
            cls._registry[name] = notification_cls
            return notification_cls
        return decorator

    @classmethod
    def create(cls, name: str, **kwargs) -> Notification:
        if name not in cls._registry:
            raise ValueError(f"Unknown: {name!r}. Registered: {list(cls._registry)}")
        return cls._registry[name](**kwargs)


@NotificationRegistry.register("email")
class EmailNotification(Notification):
    def send(self, recipient, message):
        print(f"Email → {recipient}: {message}")
        return True


@NotificationRegistry.register("push")
class PushNotification(Notification):
    def send(self, recipient, message):
        print(f"Push → {recipient}: {message}")
        return True


# Third-party code can extend without modifying NotificationRegistry
@NotificationRegistry.register("discord")
class DiscordNotification(Notification):
    def send(self, recipient, message):
        print(f"Discord → {recipient}: {message}")
        return True


notifier = NotificationRegistry.create("discord")
notifier.send("general", "Deployment complete")
```

---

## Strategy — swap algorithms at runtime

Defines a family of algorithms, encapsulates each one, makes them interchangeable.

```python
from typing import Protocol


class SortStrategy(Protocol):
    def sort(self, data: list) -> list: ...


class BubbleSort:
    def sort(self, data: list) -> list:
        result = data.copy()
        n = len(result)
        for i in range(n):
            for j in range(n - i - 1):
                if result[j] > result[j + 1]:
                    result[j], result[j + 1] = result[j + 1], result[j]
        return result


class QuickSort:
    def sort(self, data: list) -> list:
        if len(data) <= 1:
            return data
        pivot = data[len(data) // 2]
        left = [x for x in data if x < pivot]
        middle = [x for x in data if x == pivot]
        right = [x for x in data if x > pivot]
        return self.sort(left) + middle + self.sort(right)


class Sorter:
    def __init__(self, strategy: SortStrategy):
        self._strategy = strategy

    def set_strategy(self, strategy: SortStrategy):
        self._strategy = strategy

    def sort(self, data: list) -> list:
        return self._strategy.sort(data)


data = [64, 34, 25, 12, 22, 11, 90]
sorter = Sorter(QuickSort())
print(sorter.sort(data))

sorter.set_strategy(BubbleSort())
print(sorter.sort(data))
```

**The Pythonic strategy — functions as strategies:**

```python
from typing import Callable

# In Python, strategies are often just functions — no class needed
def sort_by_name(items: list[dict]) -> list[dict]:
    return sorted(items, key=lambda x: x["name"])

def sort_by_age(items: list[dict]) -> list[dict]:
    return sorted(items, key=lambda x: x["age"])

def sort_by_score_desc(items: list[dict]) -> list[dict]:
    return sorted(items, key=lambda x: x["score"], reverse=True)


class DataProcessor:
    def __init__(self, sort_strategy: Callable[[list[dict]], list[dict]] = sort_by_name):
        self._sort = sort_strategy

    def process(self, data: list[dict]) -> list[dict]:
        sorted_data = self._sort(data)
        return sorted_data


people = [
    {"name": "Charlie", "age": 35, "score": 78},
    {"name": "Alice", "age": 30, "score": 95},
    {"name": "Bob", "age": 25, "score": 87},
]

processor = DataProcessor(sort_strategy=sort_by_score_desc)
print(processor.process(people))

# Swap strategy at runtime
processor._sort = sort_by_age
print(processor.process(people))
```

---

## Observer — notify multiple objects when something changes

One object (the subject) maintains a list of dependents (observers) and notifies them automatically when state changes. The foundation of event systems, reactive frameworks, and GUIs.

```python
from typing import Callable, Any
from dataclasses import dataclass, field


class EventEmitter:
    """
    Simple event system. Objects subscribe to events by name,
    get called when those events are emitted.
    """

    def __init__(self):
        self._listeners: dict[str, list[Callable]] = {}

    def on(self, event: str, callback: Callable) -> None:
        """Subscribe to an event."""
        if event not in self._listeners:
            self._listeners[event] = []
        self._listeners[event].append(callback)

    def off(self, event: str, callback: Callable) -> None:
        """Unsubscribe from an event."""
        if event in self._listeners:
            self._listeners[event].remove(callback)

    def emit(self, event: str, *args, **kwargs) -> None:
        """Emit an event — calls all subscribed callbacks."""
        for callback in self._listeners.get(event, []):
            callback(*args, **kwargs)


class TaskManager(EventEmitter):
    def __init__(self):
        super().__init__()
        self._tasks = {}
        self._next_id = 1

    def add(self, title: str, priority: str = "medium") -> dict:
        task = {"id": self._next_id, "title": title, "priority": priority, "done": False}
        self._tasks[self._next_id] = task
        self._next_id += 1
        self.emit("task_added", task)        # notify observers
        return task

    def complete(self, task_id: int) -> dict:
        task = self._tasks[task_id]
        task["done"] = True
        self.emit("task_completed", task)    # notify observers
        return task

    def delete(self, task_id: int) -> dict:
        task = self._tasks.pop(task_id)
        self.emit("task_deleted", task)
        return task


# Observers — any function with the right signature
def log_event(task: dict) -> None:
    from datetime import datetime
    print(f"[{datetime.now():%H:%M:%S}] Task event: [{task['id']}] {task['title']}")

def send_notification(task: dict) -> None:
    print(f"Notification: Task {task['id']!r} status changed")

def update_dashboard(task: dict) -> None:
    print(f"Dashboard updated: {task['priority']} task {'completed' if task['done'] else 'added'}")


manager = TaskManager()

# Subscribe
manager.on("task_added", log_event)
manager.on("task_added", update_dashboard)
manager.on("task_completed", log_event)
manager.on("task_completed", send_notification)
manager.on("task_deleted", log_event)

# Use the manager — observers fire automatically
t = manager.add("Fix login bug", "high")
manager.complete(t["id"])
manager.delete(t["id"])
```

---

## Decorator pattern — wrapping objects (not `@decorator` syntax)

Wraps an object to add behavior, while keeping the same interface. Different from Python's `@` decorator syntax — this is the structural pattern.

```python
from abc import ABC, abstractmethod


class DataStore(ABC):
    @abstractmethod
    def read(self, key: str) -> str | None: ...

    @abstractmethod
    def write(self, key: str, value: str) -> None: ...


class FileStore(DataStore):
    """Base implementation — reads/writes a simple dict."""

    def __init__(self):
        self._data = {}

    def read(self, key: str) -> str | None:
        return self._data.get(key)

    def write(self, key: str, value: str) -> None:
        self._data[key] = value


class CachedStore(DataStore):
    """Decorator — adds caching to any DataStore."""

    def __init__(self, store: DataStore):
        self._store = store
        self._cache = {}

    def read(self, key: str) -> str | None:
        if key not in self._cache:
            self._cache[key] = self._store.read(key)
        return self._cache[key]

    def write(self, key: str, value: str) -> None:
        self._cache.pop(key, None)    # invalidate cache
        self._store.write(key, value)


class LoggedStore(DataStore):
    """Decorator — adds logging to any DataStore."""

    def __init__(self, store: DataStore):
        self._store = store

    def read(self, key: str) -> str | None:
        result = self._store.read(key)
        print(f"READ {key!r} → {result!r}")
        return result

    def write(self, key: str, value: str) -> None:
        print(f"WRITE {key!r} = {value!r}")
        self._store.write(key, value)


# Compose decorators — each wraps the previous
store = FileStore()
store = CachedStore(store)     # add caching
store = LoggedStore(store)     # add logging

store.write("user:1", "Alice")
store.read("user:1")    # from cache on second read
store.read("user:1")
```

---

## Command — encapsulate operations as objects

Turns a request into a standalone object. Enables undo/redo, queuing, and logging of operations.

```python
from abc import ABC, abstractmethod
from dataclasses import dataclass
from typing import Optional


class Command(ABC):
    @abstractmethod
    def execute(self) -> None: ...

    @abstractmethod
    def undo(self) -> None: ...


@dataclass
class AddTaskCommand(Command):
    manager: "TaskStore"
    title: str
    priority: str = "medium"
    _created_id: Optional[int] = None

    def execute(self) -> None:
        task = self.manager.add(self.title, self.priority)
        self._created_id = task["id"]
        print(f"Added task [{self._created_id}]: {self.title}")

    def undo(self) -> None:
        if self._created_id is not None:
            self.manager.delete(self._created_id)
            print(f"Undid: removed task [{self._created_id}]")


@dataclass
class CompleteTaskCommand(Command):
    manager: "TaskStore"
    task_id: int
    _was_done: bool = False

    def execute(self) -> None:
        task = self.manager.get(self.task_id)
        self._was_done = task["done"]
        task["done"] = True
        print(f"Completed task [{self.task_id}]")

    def undo(self) -> None:
        task = self.manager.get(self.task_id)
        task["done"] = self._was_done
        print(f"Undid: task [{self.task_id}] done={self._was_done}")


class CommandHistory:
    """Maintains undo/redo history."""

    def __init__(self):
        self._history: list[Command] = []
        self._undone: list[Command] = []

    def execute(self, command: Command) -> None:
        command.execute()
        self._history.append(command)
        self._undone.clear()    # new command clears redo history

    def undo(self) -> None:
        if not self._history:
            print("Nothing to undo")
            return
        command = self._history.pop()
        command.undo()
        self._undone.append(command)

    def redo(self) -> None:
        if not self._undone:
            print("Nothing to redo")
            return
        command = self._undone.pop()
        command.execute()
        self._history.append(command)


class TaskStore:
    def __init__(self):
        self._tasks = {}
        self._next_id = 1

    def add(self, title, priority="medium"):
        task = {"id": self._next_id, "title": title, "priority": priority, "done": False}
        self._tasks[self._next_id] = task
        self._next_id += 1
        return task

    def get(self, task_id):
        return self._tasks[task_id]

    def delete(self, task_id):
        return self._tasks.pop(task_id)


store = TaskStore()
history = CommandHistory()

history.execute(AddTaskCommand(store, "Fix bug", "high"))
history.execute(AddTaskCommand(store, "Write tests", "medium"))
history.execute(CompleteTaskCommand(store, 1))

print("\n--- Undo ---")
history.undo()    # undo complete
history.undo()    # undo add "Write tests"

print("\n--- Redo ---")
history.redo()    # redo add "Write tests"
```

---

## Repository — abstract data access

Separates business logic from data access. Your business logic doesn't know if data comes from SQLite, PostgreSQL, an API, or in-memory storage.

```python
from abc import ABC, abstractmethod
from typing import Optional
from dataclasses import dataclass, field


@dataclass
class Task:
    id: int
    title: str
    priority: str = "medium"
    done: bool = False


class TaskRepository(ABC):
    """Abstract interface — all storage backends implement this."""

    @abstractmethod
    def get(self, task_id: int) -> Optional[Task]: ...

    @abstractmethod
    def get_all(self) -> list[Task]: ...

    @abstractmethod
    def save(self, task: Task) -> Task: ...

    @abstractmethod
    def delete(self, task_id: int) -> bool: ...

    @abstractmethod
    def find_by_priority(self, priority: str) -> list[Task]: ...


class InMemoryTaskRepository(TaskRepository):
    """In-memory implementation — fast, used in tests."""

    def __init__(self):
        self._tasks: dict[int, Task] = {}
        self._next_id = 1

    def get(self, task_id: int) -> Optional[Task]:
        return self._tasks.get(task_id)

    def get_all(self) -> list[Task]:
        return list(self._tasks.values())

    def save(self, task: Task) -> Task:
        if task.id == 0:    # new task
            task.id = self._next_id
            self._next_id += 1
        self._tasks[task.id] = task
        return task

    def delete(self, task_id: int) -> bool:
        if task_id in self._tasks:
            del self._tasks[task_id]
            return True
        return False

    def find_by_priority(self, priority: str) -> list[Task]:
        return [t for t in self._tasks.values() if t.priority == priority]


class SQLiteTaskRepository(TaskRepository):
    """SQLite implementation — used in production."""

    def __init__(self, db_path: str = "tasks.db"):
        import sqlite3
        self._db_path = db_path
        self._conn = sqlite3.connect(db_path)
        self._conn.row_factory = sqlite3.Row
        self._init()

    def _init(self):
        self._conn.execute("""
            CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                priority TEXT DEFAULT 'medium',
                done INTEGER DEFAULT 0
            )
        """)
        self._conn.commit()

    def _row_to_task(self, row) -> Task:
        return Task(id=row["id"], title=row["title"],
                    priority=row["priority"], done=bool(row["done"]))

    def get(self, task_id: int) -> Optional[Task]:
        row = self._conn.execute(
            "SELECT * FROM tasks WHERE id = ?", (task_id,)
        ).fetchone()
        return self._row_to_task(row) if row else None

    def get_all(self) -> list[Task]:
        rows = self._conn.execute("SELECT * FROM tasks").fetchall()
        return [self._row_to_task(r) for r in rows]

    def save(self, task: Task) -> Task:
        if task.id == 0:
            cursor = self._conn.execute(
                "INSERT INTO tasks (title, priority, done) VALUES (?, ?, ?)",
                (task.title, task.priority, int(task.done))
            )
            task.id = cursor.lastrowid
        else:
            self._conn.execute(
                "UPDATE tasks SET title=?, priority=?, done=? WHERE id=?",
                (task.title, task.priority, int(task.done), task.id)
            )
        self._conn.commit()
        return task

    def delete(self, task_id: int) -> bool:
        cursor = self._conn.execute("DELETE FROM tasks WHERE id=?", (task_id,))
        self._conn.commit()
        return cursor.rowcount > 0

    def find_by_priority(self, priority: str) -> list[Task]:
        rows = self._conn.execute(
            "SELECT * FROM tasks WHERE priority = ?", (priority,)
        ).fetchall()
        return [self._row_to_task(r) for r in rows]


# Business logic — uses the repository interface, not the implementation
class TaskService:
    def __init__(self, repo: TaskRepository):
        self._repo = repo    # inject the repository

    def create_task(self, title: str, priority: str = "medium") -> Task:
        task = Task(id=0, title=title, priority=priority)
        return self._repo.save(task)

    def complete_task(self, task_id: int) -> Optional[Task]:
        task = self._repo.get(task_id)
        if task is None:
            return None
        task.done = True
        return self._repo.save(task)

    def get_pending_high_priority(self) -> list[Task]:
        return [
            t for t in self._repo.find_by_priority("high")
            if not t.done
        ]


# Swap storage without changing business logic
repo = InMemoryTaskRepository()    # tests
# repo = SQLiteTaskRepository()    # production

service = TaskService(repo)
t1 = service.create_task("Fix login bug", "high")
t2 = service.create_task("Write tests", "high")
service.complete_task(t1.id)

pending = service.get_pending_high_priority()
print([t.title for t in pending])    # ['Write tests']
```

---

## Context Manager — resource lifecycle pattern

You've used `with` throughout this course. Writing your own context managers is a design pattern for resource management.

```python
from contextlib import contextmanager
import time


class Timer:
    """Context manager that measures elapsed time."""

    def __init__(self, name: str = ""):
        self.name = name
        self.elapsed = 0.0

    def __enter__(self):
        self._start = time.perf_counter()
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        self.elapsed = time.perf_counter() - self._start
        label = f"{self.name}: " if self.name else ""
        print(f"{label}{self.elapsed:.4f}s")
        return False    # False = don't suppress exceptions


class Transaction:
    """Context manager for database transactions."""

    def __init__(self, connection):
        self._conn = connection

    def __enter__(self):
        self._conn.execute("BEGIN")
        return self._conn

    def __exit__(self, exc_type, exc_val, exc_tb):
        if exc_type is None:
            self._conn.execute("COMMIT")
        else:
            self._conn.execute("ROLLBACK")
        return False


# Usage
with Timer("sorting"):
    data = list(range(1_000_000, 0, -1))
    data.sort()

# contextmanager decorator — simpler for straightforward cases
@contextmanager
def temporary_directory():
    import tempfile, shutil
    path = tempfile.mkdtemp()
    try:
        yield path
    finally:
        shutil.rmtree(path)

with temporary_directory() as tmpdir:
    print(f"Working in {tmpdir}")
    # tmpdir deleted automatically
```

---

## The mental model to carry forward

Patterns are vocabulary, not rules. When you recognize a problem:

```
Multiple objects need to react to state changes?   → Observer
Need to create objects without specifying class?   → Factory
Need to swap algorithms at runtime?                → Strategy
Need undo/redo or operation queuing?               → Command
Need one global instance?                          → Singleton (module-level)
Need to separate business logic from storage?      → Repository
Need to add behavior without changing interface?   → Decorator pattern
Need to manage resource lifecycle?                 → Context Manager
```

**The Python-specific reality:**

- Functions replace many class-based patterns — Strategy is often just a callable
- Modules replace Singleton — don't fight it
- `@decorator` syntax handles many Decorator pattern use cases
- `Protocol` replaces abstract base classes for interfaces
- Dependency injection (passing the repo/strategy in) replaces complex wiring frameworks

The best code uses patterns where they clarify intent and ignores them where they add complexity without benefit. A pattern that requires explanation defeats its own purpose.

---

Day 24 is performance — profiling, finding bottlenecks, caching, and the rules for when to optimize and when to leave it alone. Ready when you are.