#### Task Manager Rebuilt

Week 2 project. The Day 7 task manager gets a complete rebuild — file persistence, proper error handling, a clean module structure, and the class-based design from Days 12 and 13.

---

## What's new vs Day 7

|Day 7|Day 14|
|---|---|
|Global variables|Class with encapsulated state|
|No persistence|JSON file persistence|
|No error handling|Custom exceptions, try/except|
|One giant file|Multiple modules|
|Basic input parsing|Robust command parsing|
|No data validation|Full validation with clear errors|

---

## Project structure

```
task_manager/
├── task_manager/
│   ├── __init__.py
│   ├── exceptions.py
│   ├── models.py
│   ├── storage.py
│   ├── manager.py
│   └── cli.py
├── requirements.txt
└── main.py
```

Create this structure first:

```bash
mkdir -p task_manager/task_manager
cd task_manager
touch task_manager/__init__.py
touch task_manager/exceptions.py
touch task_manager/models.py
touch task_manager/storage.py
touch task_manager/manager.py
touch task_manager/cli.py
touch main.py
touch requirements.txt
```

---

## exceptions.py — custom exceptions first

Always define exceptions before anything else. They have no dependencies.

```python
# task_manager/exceptions.py


class TaskError(Exception):
    """Base exception for all task manager errors."""
    pass


class TaskNotFoundError(TaskError):
    """Raised when a task ID does not exist."""

    def __init__(self, task_id):
        self.task_id = task_id
        super().__init__(f"No task with ID {task_id}")


class InvalidPriorityError(TaskError):
    """Raised when an invalid priority is given."""

    VALID = ("low", "medium", "high")

    def __init__(self, priority):
        self.priority = priority
        super().__init__(
            f"Invalid priority {priority!r}. Must be one of: {self.VALID}"
        )


class InvalidTaskError(TaskError):
    """Raised when task data fails validation."""
    pass


class StorageError(TaskError):
    """Raised when reading or writing tasks fails."""
    pass
```

---

## models.py — the Task dataclass

```python
# task_manager/models.py

from dataclasses import dataclass, field
from datetime import datetime
from typing import Optional


VALID_PRIORITIES = {"low", "medium", "high"}


@dataclass
class Task:
    id: int
    title: str
    priority: str = "medium"
    done: bool = False
    created_at: str = field(default_factory=lambda: datetime.now().isoformat())
    notes: Optional[str] = None

    def __post_init__(self):
        """Runs after __init__ — validate the data."""
        from .exceptions import InvalidPriorityError, InvalidTaskError

        if not self.title or not self.title.strip():
            raise InvalidTaskError("Title cannot be empty")
        if self.priority not in VALID_PRIORITIES:
            raise InvalidPriorityError(self.priority)
        self.title = self.title.strip()

    def complete(self):
        """Mark this task as done."""
        self.done = True

    def to_dict(self):
        """Serialize to a plain dict for JSON storage."""
        return {
            "id": self.id,
            "title": self.title,
            "priority": self.priority,
            "done": self.done,
            "created_at": self.created_at,
            "notes": self.notes,
        }

    @classmethod
    def from_dict(cls, data):
        """Deserialize from a plain dict."""
        return cls(
            id=data["id"],
            title=data["title"],
            priority=data.get("priority", "medium"),
            done=data.get("done", False),
            created_at=data.get("created_at", datetime.now().isoformat()),
            notes=data.get("notes"),
        )

    def __str__(self):
        status = "✓" if self.done else "○"
        notes_indicator = " [has notes]" if self.notes else ""
        return (
            f"[{self.id:>3}] {status} [{self.priority.upper():<6}] "
            f"{self.title}{notes_indicator}"
        )
```

`__post_init__` is a dataclass hook — it runs automatically after the generated `__init__`. Use it for validation and post-processing.

---

## storage.py — all file I/O in one place

```python
# task_manager/storage.py

import json
from pathlib import Path
from .exceptions import StorageError


class TaskStorage:
    """Handles reading and writing tasks to disk."""

    VERSION = 1

    def __init__(self, filepath="tasks.json"):
        self.filepath = Path(filepath)

    def load(self):
        """
        Load task data from disk.
        Returns (tasks_data, next_id) tuple.
        Returns ([], 1) if file doesn't exist.
        Raises StorageError if file is corrupt or unreadable.
        """
        if not self.filepath.exists():
            return [], 1

        try:
            with open(self.filepath, "r", encoding="utf-8") as f:
                data = json.load(f)
        except json.JSONDecodeError as e:
            raise StorageError(
                f"Tasks file is corrupt: {self.filepath}\n{e}"
            ) from e
        except PermissionError as e:
            raise StorageError(
                f"Cannot read tasks file: {self.filepath}"
            ) from e
        except OSError as e:
            raise StorageError(f"File error: {e}") from e

        # Handle version migrations here in future
        tasks_data = data.get("tasks", [])
        next_id = data.get("next_id", 1)
        return tasks_data, next_id

    def save(self, tasks_data, next_id):
        """
        Save task data to disk.
        Raises StorageError on failure.
        """
        data = {
            "version": self.VERSION,
            "next_id": next_id,
            "tasks": tasks_data,
        }

        # Write to temp file first, then rename — atomic write
        # Prevents corruption if the program crashes mid-write
        tmp_path = self.filepath.with_suffix(".tmp")

        try:
            self.filepath.parent.mkdir(parents=True, exist_ok=True)
            with open(tmp_path, "w", encoding="utf-8") as f:
                json.dump(data, f, indent=2)
            tmp_path.replace(self.filepath)    # atomic on most systems
        except PermissionError as e:
            raise StorageError(
                f"Cannot write tasks file: {self.filepath}"
            ) from e
        except OSError as e:
            raise StorageError(f"File error: {e}") from e
        finally:
            # Clean up temp file if it still exists
            if tmp_path.exists():
                tmp_path.unlink(missing_ok=True)

    def backup(self):
        """Create a timestamped backup of the tasks file."""
        if not self.filepath.exists():
            return None

        from datetime import datetime
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        backup_path = self.filepath.with_stem(
            f"{self.filepath.stem}_backup_{timestamp}"
        )
        import shutil
        shutil.copy2(self.filepath, backup_path)
        return backup_path
```

The atomic write pattern (write to `.tmp`, then rename) is used in real applications. If your program crashes mid-write, you end up with either the old file or the new file — never a half-written corrupt one.

---

## manager.py — the core logic

```python
# task_manager/manager.py

from .models import Task, VALID_PRIORITIES
from .storage import TaskStorage
from .exceptions import TaskNotFoundError, InvalidPriorityError, InvalidTaskError


PRIORITY_ORDER = {"high": 0, "medium": 1, "low": 2}


class TaskManager:
    """
    Manages a collection of tasks with file persistence.

    Usage:
        manager = TaskManager()
        task = manager.add("Fix login bug", priority="high")
        manager.complete(task.id)
        tasks = manager.list(status="pending")
    """

    def __init__(self, filepath="tasks.json"):
        self._storage = TaskStorage(filepath)
        self._tasks: dict[int, Task] = {}
        self._next_id = 1
        self._load()

    def _load(self):
        """Load tasks from storage into memory."""
        tasks_data, self._next_id = self._storage.load()
        self._tasks = {
            d["id"]: Task.from_dict(d)
            for d in tasks_data
        }

    def _save(self):
        """Persist current tasks to storage."""
        tasks_data = [t.to_dict() for t in self._tasks.values()]
        self._storage.save(tasks_data, self._next_id)

    def add(self, title, priority="medium", notes=None):
        """
        Add a new task.

        Args:
            title: Task title (non-empty string)
            priority: "low", "medium", or "high"
            notes: Optional notes string

        Returns:
            The created Task object

        Raises:
            InvalidTaskError: if title is empty
            InvalidPriorityError: if priority is invalid
        """
        task = Task(
            id=self._next_id,
            title=title,
            priority=priority,
            notes=notes,
        )
        self._tasks[task.id] = task
        self._next_id += 1
        self._save()
        return task

    def get(self, task_id):
        """
        Get a task by ID.

        Raises:
            TaskNotFoundError: if task_id doesn't exist
        """
        task = self._tasks.get(task_id)
        if task is None:
            raise TaskNotFoundError(task_id)
        return task

    def complete(self, task_id):
        """
        Mark a task as done.

        Returns the completed Task.
        Raises TaskNotFoundError if task doesn't exist.
        """
        task = self.get(task_id)
        task.complete()
        self._save()
        return task

    def delete(self, task_id):
        """
        Delete a task by ID.

        Returns the deleted Task.
        Raises TaskNotFoundError if task doesn't exist.
        """
        task = self.get(task_id)
        del self._tasks[task_id]
        self._save()
        return task

    def update(self, task_id, title=None, priority=None, notes=None):
        """
        Update task fields. Only provided fields are changed.

        Returns the updated Task.
        Raises TaskNotFoundError, InvalidPriorityError, InvalidTaskError.
        """
        task = self.get(task_id)

        if title is not None:
            if not title.strip():
                raise InvalidTaskError("Title cannot be empty")
            task.title = title.strip()

        if priority is not None:
            if priority not in VALID_PRIORITIES:
                raise InvalidPriorityError(priority)
            task.priority = priority

        if notes is not None:
            task.notes = notes if notes.strip() else None

        self._save()
        return task

    def list(self, status=None, priority=None, search=None):
        """
        Return filtered and sorted tasks.

        Args:
            status: "done", "pending", or None (all)
            priority: "low", "medium", "high", or None (all)
            search: string to search in title (case-insensitive)

        Returns:
            List of Task objects sorted by priority then id
        """
        tasks = list(self._tasks.values())

        if status == "done":
            tasks = [t for t in tasks if t.done]
        elif status == "pending":
            tasks = [t for t in tasks if not t.done]

        if priority:
            if priority not in VALID_PRIORITIES:
                raise InvalidPriorityError(priority)
            tasks = [t for t in tasks if t.priority == priority]

        if search:
            search_lower = search.lower()
            tasks = [t for t in tasks if search_lower in t.title.lower()]

        return sorted(tasks, key=lambda t: (PRIORITY_ORDER[t.priority], t.id))

    def bulk_complete(self, task_ids):
        """
        Complete multiple tasks at once.

        Returns dict of {id: Task or Exception}
        """
        results = {}
        for task_id in task_ids:
            try:
                results[task_id] = self.complete(task_id)
            except TaskNotFoundError as e:
                results[task_id] = e
        return results

    @property
    def summary(self):
        """Return a dict of task statistics."""
        all_tasks = list(self._tasks.values())
        total = len(all_tasks)
        done = sum(1 for t in all_tasks if t.done)

        by_priority = {
            p: sum(1 for t in all_tasks if t.priority == p and not t.done)
            for p in VALID_PRIORITIES
        }

        return {
            "total": total,
            "done": done,
            "pending": total - done,
            "by_priority": by_priority,
        }

    def __len__(self):
        return len(self._tasks)

    def __repr__(self):
        return f"TaskManager(tasks={len(self)}, file={self._storage.filepath!r})"
```

Using a dict (`{id: Task}`) instead of a list for `_tasks` makes `get()` O(1) instead of O(n). For a task manager it doesn't matter — but the habit of choosing the right data structure does.

---

## cli.py — the user interface

```python
# task_manager/cli.py

from .manager import TaskManager
from .exceptions import TaskError, TaskNotFoundError


def print_tasks(tasks):
    """Print a formatted table of tasks."""
    if not tasks:
        print("  No tasks found.")
        return

    print(f"\n  {'ID':<5} {'ST':<3} {'PRIORITY':<9} TITLE")
    print("  " + "-" * 50)
    for task in tasks:
        print(f"  {task}")
    print()


def print_summary(summary):
    """Print task summary statistics."""
    print(f"\n  Total: {summary['total']} | "
          f"Done: {summary['done']} | "
          f"Pending: {summary['pending']}")
    bp = summary["by_priority"]
    print(f"  Pending by priority — "
          f"High: {bp['high']} | "
          f"Medium: {bp['medium']} | "
          f"Low: {bp['low']}\n")


def parse_id(value):
    """Parse a task ID string. Returns int or None."""
    try:
        return int(value)
    except (ValueError, TypeError):
        return None


def handle_command(manager, parts):
    """
    Parse and execute one command.
    Returns "quit" to signal exit, None otherwise.
    """
    if not parts:
        return

    command = parts[0].lower()

    # --- add ---
    if command == "add":
        if len(parts) < 2:
            print("  Usage: add <title> [low|medium|high]")
            return
        if parts[-1] in ("low", "medium", "high") and len(parts) > 2:
            title = " ".join(parts[1:-1])
            priority = parts[-1]
        else:
            title = " ".join(parts[1:])
            priority = "medium"
        try:
            task = manager.add(title, priority)
            print(f"  Added: {task}")
        except TaskError as e:
            print(f"  Error: {e}")

    # --- list ---
    elif command == "list":
        status = None
        priority = None
        search = None

        args = parts[1:]
        i = 0
        while i < len(args):
            arg = args[i].lower()
            if arg in ("done", "pending"):
                status = arg
            elif arg in ("low", "medium", "high"):
                priority = arg
            elif arg == "search" and i + 1 < len(args):
                search = args[i + 1]
                i += 1
            i += 1

        try:
            tasks = manager.list(status=status, priority=priority, search=search)
            print_tasks(tasks)
        except TaskError as e:
            print(f"  Error: {e}")

    # --- done ---
    elif command == "done":
        if len(parts) < 2:
            print("  Usage: done <id> [id2 id3 ...]")
            return
        ids = [parse_id(p) for p in parts[1:]]
        invalid = [p for p, i in zip(parts[1:], ids) if i is None]
        if invalid:
            print(f"  Invalid IDs: {', '.join(invalid)}")
            return
        if len(ids) == 1:
            try:
                task = manager.complete(ids[0])
                print(f"  Completed: {task}")
            except TaskError as e:
                print(f"  Error: {e}")
        else:
            results = manager.bulk_complete(ids)
            for task_id, result in results.items():
                if isinstance(result, Exception):
                    print(f"  Error [{task_id}]: {result}")
                else:
                    print(f"  Completed: {result}")

    # --- delete ---
    elif command == "delete":
        if len(parts) < 2:
            print("  Usage: delete <id>")
            return
        task_id = parse_id(parts[1])
        if task_id is None:
            print(f"  Invalid ID: {parts[1]!r}")
            return
        try:
            task = manager.delete(task_id)
            print(f"  Deleted: [{task.id}] {task.title}")
        except TaskError as e:
            print(f"  Error: {e}")

    # --- update ---
    elif command == "update":
        if len(parts) < 3:
            print("  Usage: update <id> <new title> [priority]")
            return
        task_id = parse_id(parts[1])
        if task_id is None:
            print(f"  Invalid ID: {parts[1]!r}")
            return
        if parts[-1] in ("low", "medium", "high") and len(parts) > 3:
            title = " ".join(parts[2:-1])
            priority = parts[-1]
        else:
            title = " ".join(parts[2:])
            priority = None
        try:
            task = manager.update(task_id, title=title, priority=priority)
            print(f"  Updated: {task}")
        except TaskError as e:
            print(f"  Error: {e}")

    # --- summary ---
    elif command == "summary":
        print_summary(manager.summary)

    # --- backup ---
    elif command == "backup":
        path = manager._storage.backup()
        if path:
            print(f"  Backup created: {path}")
        else:
            print("  No tasks file to back up yet.")

    # --- help ---
    elif command == "help":
        print("""
  Commands:
    add <title> [low|medium|high]          Add a task
    list                                   List all tasks
    list done|pending                      Filter by status
    list low|medium|high                   Filter by priority
    list search <term>                     Search by title
    done <id> [id2 id3 ...]               Complete one or more tasks
    delete <id>                            Delete a task
    update <id> <new title> [priority]     Update a task
    summary                                Show statistics
    backup                                 Back up tasks file
    quit                                   Exit
        """)

    # --- quit ---
    elif command in ("quit", "exit", "q"):
        return "quit"

    else:
        print(f"  Unknown command {command!r}. Type 'help' for commands.")


def run(manager):
    """Main CLI loop."""
    print("Task Manager v2  —  type 'help' for commands\n")
    print_summary(manager.summary)

    while True:
        try:
            raw = input("> ").strip()
            if not raw:
                continue
            parts = raw.split()
            result = handle_command(manager, parts)
            if result == "quit":
                print("Goodbye.")
                break
        except KeyboardInterrupt:
            print("\nGoodbye.")
            break
        except Exception as e:
            # Top-level safety net — should never reach here in practice
            print(f"  Unexpected error: {e}")
```

---

## `__init__.py` — clean public interface

```python
# task_manager/__init__.py

from .manager import TaskManager
from .models import Task
from .exceptions import TaskError, TaskNotFoundError, InvalidPriorityError

__all__ = ["TaskManager", "Task", "TaskError", "TaskNotFoundError", "InvalidPriorityError"]
```

This lets callers write `from task_manager import TaskManager` instead of `from task_manager.manager import TaskManager`.

---

## main.py — the entry point

```python
# main.py

import sys
from task_manager import TaskManager
from task_manager.cli import run
from task_manager.exceptions import StorageError


def main():
    filepath = sys.argv[1] if len(sys.argv) > 1 else "tasks.json"

    try:
        manager = TaskManager(filepath)
    except StorageError as e:
        print(f"Failed to load tasks: {e}")
        sys.exit(1)

    run(manager)


if __name__ == "__main__":
    main()
```

Accepting the filepath as a command-line argument means you can run separate task lists:

```bash
python main.py                    # uses tasks.json
python main.py work_tasks.json    # separate work list
python main.py personal.json      # separate personal list
```

---

## Run it

```bash
cd task_manager
python main.py
```

Test sequence:

```
add Fix authentication bug high
add Write API documentation medium
add Update dependencies low
add Deploy to staging high
add Write unit tests high
list
list high
list pending
done 1
done 2 3
list done
summary
list search bug
update 4 Deploy to production high
backup
list
summary
quit
```

Restart the program and run `list` — your tasks are still there.

---

## What this project demonstrates

**Module separation pays off immediately.** When you want to change how tasks are stored — say, SQLite instead of JSON — you only touch `storage.py`. The manager, CLI, and models don't change.

**Custom exceptions make error handling clean.** The CLI catches `TaskError` and prints a friendly message. It doesn't need to know whether it was a `TaskNotFoundError` or `InvalidPriorityError` unless it wants to handle them differently.

**The atomic write is real engineering.** Without it, a crash mid-save corrupts your data. With it, you either have the old file or the new one — never garbage.

**The dict vs list choice matters.** `self._tasks` is a `dict[int, Task]`. Looking up task 99 is O(1) — Python jumps directly to it. If it were a list, you'd scan every task every time.

---

Week 2 complete. Two weeks in, you have a real application with persistence, error handling, clean module structure, and OOP design.

Week 3 starts with Day 15 — decorators. Ready when you are.

[[Real Python]]