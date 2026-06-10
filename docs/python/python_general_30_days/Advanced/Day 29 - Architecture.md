#### Structuring Large Python Projects

## What architecture actually solves

Small projects don't need architecture. A 200-line script in one file is fine. Architecture becomes necessary when:

- Multiple developers work on the same codebase
- Changes in one place unexpectedly break something else
- Adding a feature requires touching ten files
- Testing requires setting up the entire application
- You can't explain what any given file is responsible for

Architecture is about making a codebase predictable. When you know where things live and why, adding features and fixing bugs becomes straightforward instead of archaeological.

---

## The core principle: separation of concerns

Every module, class, and function should have one clear responsibility. When something changes, only the code responsible for that thing should need to change.

```
# Bad — everything mixed together
def handle_create_task(db_conn, user_input):
    # validation
    if not user_input.get("title"):
        return {"error": "title required"}, 400
    if user_input["priority"] not in ("low", "medium", "high"):
        return {"error": "invalid priority"}, 400

    # business logic
    title = user_input["title"].strip()
    task_id = generate_id()

    # data access
    db_conn.execute(
        "INSERT INTO tasks VALUES (?, ?, ?)",
        (task_id, title, user_input["priority"])
    )
    db_conn.commit()

    # notification
    send_email(user_input["user_email"], f"Task created: {title}")

    # response formatting
    return {"id": task_id, "title": title}, 201

# One function does: validation, business logic, data access,
# notification, and response formatting.
# Change the database schema → touch this function.
# Change the email service → touch this function.
# Add a new notification channel → touch this function.
# Test any single concern → set up everything.
```

Separated:

```
request → validation layer → business logic layer → data layer
                                    ↓
                          notification layer
```

Each layer knows about the layer below it, not above it. Change the data layer — only the data layer changes. Change the notification service — only the notification layer changes.

---

## Layered architecture — the practical standard

```
task_manager/
├── src/
│   └── task_manager/
│       ├── api/              # HTTP layer — routes, request/response
│       │   ├── __init__.py
│       │   ├── routes.py
│       │   ├── schemas.py    # Pydantic request/response models
│       │   └── dependencies.py
│       │
│       ├── services/         # business logic layer
│       │   ├── __init__.py
│       │   ├── task_service.py
│       │   └── notification_service.py
│       │
│       ├── repositories/     # data access layer
│       │   ├── __init__.py
│       │   ├── base.py
│       │   ├── task_repository.py
│       │   └── sqlite_task_repository.py
│       │
│       ├── domain/           # core business objects
│       │   ├── __init__.py
│       │   ├── task.py
│       │   └── exceptions.py
│       │
│       ├── infrastructure/   # external services
│       │   ├── __init__.py
│       │   ├── database.py
│       │   ├── email.py
│       │   └── config.py
│       │
│       └── __init__.py
│
├── tests/
│   ├── unit/
│   │   ├── test_task_service.py
│   │   └── test_task_repository.py
│   ├── integration/
│   │   └── test_api.py
│   └── conftest.py
├── pyproject.toml
└── main.py
```

The dependency direction is strict:

```
api → services → repositories → domain
             ↓
       infrastructure
```

`domain` has no dependencies. `repositories` depends only on `domain`. `services` depends on `repositories` and `domain`. `api` depends on `services`. Nothing in a lower layer imports from a higher layer.

---

## Domain layer — pure business objects

The domain layer has no dependencies on databases, HTTP, or external services. It's pure Python.

```python
# src/task_manager/domain/task.py

from dataclasses import dataclass, field
from datetime import datetime
from enum import Enum
from typing import Optional


class Priority(str, Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"


class TaskStatus(str, Enum):
    PENDING = "pending"
    DONE = "done"


@dataclass
class Task:
    id: int
    title: str
    priority: Priority
    status: TaskStatus = TaskStatus.PENDING
    notes: Optional[str] = None
    created_at: datetime = field(default_factory=datetime.now)
    updated_at: datetime = field(default_factory=datetime.now)

    def complete(self) -> None:
        if self.status == TaskStatus.DONE:
            raise ValueError(f"Task {self.id} is already complete")
        self.status = TaskStatus.DONE
        self.updated_at = datetime.now()

    def update_title(self, title: str) -> None:
        title = title.strip()
        if not title:
            raise ValueError("Title cannot be empty")
        self.title = title
        self.updated_at = datetime.now()

    def update_priority(self, priority: Priority) -> None:
        self.priority = priority
        self.updated_at = datetime.now()

    @property
    def is_done(self) -> bool:
        return self.status == TaskStatus.DONE

    @property
    def is_pending(self) -> bool:
        return self.status == TaskStatus.PENDING

    def __repr__(self) -> str:
        return (
            f"Task(id={self.id}, title={self.title!r}, "
            f"priority={self.priority.value}, status={self.status.value})"
        )
```

```python
# src/task_manager/domain/exceptions.py

class DomainError(Exception):
    """Base for all domain errors."""
    pass

class TaskNotFoundError(DomainError):
    def __init__(self, task_id: int):
        self.task_id = task_id
        super().__init__(f"Task {task_id} not found")

class InvalidTaskError(DomainError):
    pass

class TaskAlreadyCompleteError(DomainError):
    def __init__(self, task_id: int):
        self.task_id = task_id
        super().__init__(f"Task {task_id} is already complete")
```

Using `Enum` for priority and status is a significant improvement over raw strings. `Priority.HIGH` is explicit, autocompleted by IDEs, and causes an `AttributeError` if you mistype it. `"hgih"` fails silently.

---

## Repository layer — data access behind an interface

```python
# src/task_manager/repositories/base.py

from abc import ABC, abstractmethod
from typing import Optional
from task_manager.domain.task import Task, Priority, TaskStatus


class TaskRepository(ABC):
    """
    Abstract interface for task persistence.
    Business logic depends on this interface, not on any implementation.
    """

    @abstractmethod
    def get_by_id(self, task_id: int) -> Optional[Task]:
        """Return task or None if not found."""
        ...

    @abstractmethod
    def get_all(
        self,
        status: Optional[TaskStatus] = None,
        priority: Optional[Priority] = None,
        search: Optional[str] = None,
    ) -> list[Task]:
        """Return tasks matching filters, sorted by priority."""
        ...

    @abstractmethod
    def save(self, task: Task) -> Task:
        """Insert or update a task. Returns the saved task."""
        ...

    @abstractmethod
    def delete(self, task_id: int) -> bool:
        """Delete a task. Returns True if deleted, False if not found."""
        ...

    @abstractmethod
    def count(self, status: Optional[TaskStatus] = None) -> int:
        """Count tasks, optionally filtered by status."""
        ...
```

```python
# src/task_manager/repositories/sqlite_task_repository.py

import sqlite3
from contextlib import contextmanager
from pathlib import Path
from typing import Optional

from task_manager.domain.task import Task, Priority, TaskStatus
from task_manager.repositories.base import TaskRepository


PRIORITY_ORDER = {Priority.HIGH: 1, Priority.MEDIUM: 2, Priority.LOW: 3}

SCHEMA = """
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    priority TEXT NOT NULL DEFAULT 'medium',
    status TEXT NOT NULL DEFAULT 'pending',
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status);
CREATE INDEX IF NOT EXISTS idx_tasks_priority ON tasks(priority);
"""


class SQLiteTaskRepository(TaskRepository):

    def __init__(self, db_path: str | Path = "tasks.db"):
        self._db_path = str(db_path)
        self._init_schema()

    @contextmanager
    def _connection(self):
        conn = sqlite3.connect(self._db_path)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA journal_mode=WAL")
        try:
            yield conn
            conn.commit()
        except Exception:
            conn.rollback()
            raise
        finally:
            conn.close()

    def _init_schema(self) -> None:
        with self._connection() as conn:
            conn.executescript(SCHEMA)

    def _row_to_task(self, row: sqlite3.Row) -> Task:
        return Task(
            id=row["id"],
            title=row["title"],
            priority=Priority(row["priority"]),
            status=TaskStatus(row["status"]),
            notes=row["notes"],
        )

    def get_by_id(self, task_id: int) -> Optional[Task]:
        with self._connection() as conn:
            row = conn.execute(
                "SELECT * FROM tasks WHERE id = ?", (task_id,)
            ).fetchone()
        return self._row_to_task(row) if row else None

    def get_all(
        self,
        status: Optional[TaskStatus] = None,
        priority: Optional[Priority] = None,
        search: Optional[str] = None,
    ) -> list[Task]:
        conditions: list[str] = []
        params: list = []

        if status is not None:
            conditions.append("status = ?")
            params.append(status.value)
        if priority is not None:
            conditions.append("priority = ?")
            params.append(priority.value)
        if search is not None:
            conditions.append("title LIKE ?")
            params.append(f"%{search}%")

        where = f"WHERE {' AND '.join(conditions)}" if conditions else ""
        query = f"""
            SELECT * FROM tasks {where}
            ORDER BY
                CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END,
                created_at ASC
        """
        with self._connection() as conn:
            rows = conn.execute(query, params).fetchall()

        return [self._row_to_task(r) for r in rows]

    def save(self, task: Task) -> Task:
        with self._connection() as conn:
            if task.id == 0:
                cursor = conn.execute(
                    """INSERT INTO tasks (title, priority, status, notes)
                       VALUES (?, ?, ?, ?)""",
                    (task.title, task.priority.value,
                     task.status.value, task.notes)
                )
                task.id = cursor.lastrowid
            else:
                conn.execute(
                    """UPDATE tasks
                       SET title=?, priority=?, status=?, notes=?, updated_at=datetime('now')
                       WHERE id=?""",
                    (task.title, task.priority.value,
                     task.status.value, task.notes, task.id)
                )
        return task

    def delete(self, task_id: int) -> bool:
        with self._connection() as conn:
            cursor = conn.execute(
                "DELETE FROM tasks WHERE id = ?", (task_id,)
            )
        return cursor.rowcount > 0

    def count(self, status: Optional[TaskStatus] = None) -> int:
        query = "SELECT COUNT(*) FROM tasks"
        params: list = []
        if status is not None:
            query += " WHERE status = ?"
            params.append(status.value)
        with self._connection() as conn:
            return conn.execute(query, params).fetchone()[0]
```

```python
# src/task_manager/repositories/memory_task_repository.py
# Used in tests — no database required

from typing import Optional
from task_manager.domain.task import Task, Priority, TaskStatus
from task_manager.repositories.base import TaskRepository


class InMemoryTaskRepository(TaskRepository):
    """In-memory implementation — fast, zero setup, perfect for tests."""

    def __init__(self) -> None:
        self._tasks: dict[int, Task] = {}
        self._next_id: int = 1

    def get_by_id(self, task_id: int) -> Optional[Task]:
        return self._tasks.get(task_id)

    def get_all(
        self,
        status: Optional[TaskStatus] = None,
        priority: Optional[Priority] = None,
        search: Optional[str] = None,
    ) -> list[Task]:
        tasks = list(self._tasks.values())
        if status:
            tasks = [t for t in tasks if t.status == status]
        if priority:
            tasks = [t for t in tasks if t.priority == priority]
        if search:
            tasks = [t for t in tasks if search.lower() in t.title.lower()]
        priority_order = {Priority.HIGH: 0, Priority.MEDIUM: 1, Priority.LOW: 2}
        return sorted(tasks, key=lambda t: priority_order[t.priority])

    def save(self, task: Task) -> Task:
        if task.id == 0:
            task.id = self._next_id
            self._next_id += 1
        self._tasks[task.id] = task
        return task

    def delete(self, task_id: int) -> bool:
        if task_id in self._tasks:
            del self._tasks[task_id]
            return True
        return False

    def count(self, status: Optional[TaskStatus] = None) -> int:
        if status is None:
            return len(self._tasks)
        return sum(1 for t in self._tasks.values() if t.status == status)
```

---

## Service layer — business logic

```python
# src/task_manager/services/task_service.py

from typing import Optional
from task_manager.domain.task import Task, Priority, TaskStatus
from task_manager.domain.exceptions import (
    TaskNotFoundError, TaskAlreadyCompleteError, InvalidTaskError
)
from task_manager.repositories.base import TaskRepository


class TaskService:
    """
    Orchestrates task operations.
    Depends on TaskRepository interface — not on any specific implementation.
    """

    def __init__(self, repository: TaskRepository) -> None:
        self._repo = repository

    def create_task(
        self,
        title: str,
        priority: str = "medium",
        notes: Optional[str] = None,
    ) -> Task:
        """Create and persist a new task."""
        title = title.strip()
        if not title:
            raise InvalidTaskError("Title cannot be empty")

        try:
            priority_enum = Priority(priority)
        except ValueError:
            valid = [p.value for p in Priority]
            raise InvalidTaskError(
                f"Invalid priority {priority!r}. Must be one of: {valid}"
            )

        task = Task(
            id=0,    # repository assigns the real ID
            title=title,
            priority=priority_enum,
            notes=notes,
        )
        return self._repo.save(task)

    def get_task(self, task_id: int) -> Task:
        """Get a task by ID. Raises TaskNotFoundError if missing."""
        task = self._repo.get_by_id(task_id)
        if task is None:
            raise TaskNotFoundError(task_id)
        return task

    def list_tasks(
        self,
        status: Optional[str] = None,
        priority: Optional[str] = None,
        search: Optional[str] = None,
    ) -> list[Task]:
        """List tasks with optional filtering."""
        status_enum = TaskStatus(status) if status else None
        priority_enum = Priority(priority) if priority else None
        return self._repo.get_all(
            status=status_enum,
            priority=priority_enum,
            search=search,
        )

    def complete_task(self, task_id: int) -> Task:
        """Mark a task as complete."""
        task = self.get_task(task_id)
        if task.is_done:
            raise TaskAlreadyCompleteError(task_id)
        task.complete()
        return self._repo.save(task)

    def update_task(
        self,
        task_id: int,
        title: Optional[str] = None,
        priority: Optional[str] = None,
        notes: Optional[str] = None,
    ) -> Task:
        """Update task fields. Only provided fields are changed."""
        task = self.get_task(task_id)

        if title is not None:
            task.update_title(title)
        if priority is not None:
            try:
                task.update_priority(Priority(priority))
            except ValueError:
                raise InvalidTaskError(f"Invalid priority: {priority!r}")
        if notes is not None:
            task.notes = notes if notes.strip() else None
            from datetime import datetime
            task.updated_at = datetime.now()

        return self._repo.save(task)

    def delete_task(self, task_id: int) -> None:
        """Delete a task. Raises TaskNotFoundError if missing."""
        if not self._repo.delete(task_id):
            raise TaskNotFoundError(task_id)

    def get_summary(self) -> dict:
        """Return task statistics."""
        total = self._repo.count()
        done = self._repo.count(status=TaskStatus.DONE)
        high_pending = len(self._repo.get_all(
            status=TaskStatus.PENDING,
            priority=Priority.HIGH,
        ))
        return {
            "total": total,
            "done": done,
            "pending": total - done,
            "high_priority_pending": high_pending,
        }
```

The service layer is the heart of the application. It contains all business rules. It knows nothing about HTTP, databases, or the CLI. It takes and returns domain objects.

---

## Configuration — centralized, typed, environment-aware

```python
# src/task_manager/infrastructure/config.py

from pydantic import Field
from pydantic_settings import BaseSettings
from pathlib import Path
from functools import lru_cache


class Settings(BaseSettings):
    """
    Application configuration.
    Values come from environment variables or .env file.
    Pydantic validates and coerces types automatically.
    """

    # Application
    app_name: str = "Task Manager"
    app_version: str = "1.0.0"
    debug: bool = False
    environment: str = Field(default="development", pattern="^(development|staging|production)$")

    # Database
    database_url: str = "sqlite:///tasks.db"
    database_echo: bool = False

    # API
    api_host: str = "0.0.0.0"
    api_port: int = Field(default=8000, ge=1, le=65535)
    api_prefix: str = "/api/v1"

    # Auth
    secret_key: str = "dev-secret-change-in-production"
    api_key_header: str = "X-API-Key"

    # Notifications
    smtp_host: str = "localhost"
    smtp_port: int = 587
    smtp_from: str = "noreply@taskmanager.com"
    notifications_enabled: bool = False

    model_config = {
        "env_file": ".env",
        "env_file_encoding": "utf-8",
        "case_sensitive": False,
    }

    @property
    def is_production(self) -> bool:
        return self.environment == "production"

    @property
    def db_path(self) -> Path:
        if self.database_url.startswith("sqlite:///"):
            return Path(self.database_url[10:])
        raise ValueError("Not a SQLite URL")


@lru_cache
def get_settings() -> Settings:
    """
    Return cached settings instance.
    lru_cache ensures settings are loaded once and reused.
    Call get_settings.cache_clear() in tests to reload.
    """
    return Settings()
```

```bash
pip install pydantic-settings
```

```
# .env
DEBUG=false
ENVIRONMENT=production
DATABASE_URL=sqlite:///production_tasks.db
SECRET_KEY=your-actual-secret-key-here
NOTIFICATIONS_ENABLED=true
SMTP_HOST=smtp.sendgrid.net
```

```python
# Usage anywhere in the application
from task_manager.infrastructure.config import get_settings

settings = get_settings()
print(settings.database_url)
print(settings.is_production)
```

---

## Dependency injection — wiring it all together

Dependency injection means passing dependencies in instead of creating them inside functions. You've seen this throughout the course. Here's how to wire a complete application.

```python
# src/task_manager/infrastructure/container.py

from functools import lru_cache
from task_manager.infrastructure.config import get_settings
from task_manager.repositories.sqlite_task_repository import SQLiteTaskRepository
from task_manager.repositories.memory_task_repository import InMemoryTaskRepository
from task_manager.repositories.base import TaskRepository
from task_manager.services.task_service import TaskService


class Container:
    """
    Dependency injection container.
    Creates and wires together all application components.
    """

    def __init__(self) -> None:
        self._settings = get_settings()
        self._repository: TaskRepository | None = None
        self._task_service: TaskService | None = None

    @property
    def repository(self) -> TaskRepository:
        if self._repository is None:
            if self._settings.environment == "test":
                self._repository = InMemoryTaskRepository()
            else:
                self._repository = SQLiteTaskRepository(
                    self._settings.db_path
                )
        return self._repository

    @property
    def task_service(self) -> TaskService:
        if self._task_service is None:
            self._task_service = TaskService(self.repository)
        return self._task_service


@lru_cache
def get_container() -> Container:
    return Container()


# FastAPI dependency injection
def get_task_service() -> TaskService:
    return get_container().task_service
```

```python
# src/task_manager/api/routes.py

from fastapi import APIRouter, Depends, HTTPException, status
from task_manager.services.task_service import TaskService
from task_manager.domain.exceptions import (
    TaskNotFoundError, InvalidTaskError, TaskAlreadyCompleteError
)
from task_manager.api.schemas import (
    CreateTaskRequest, UpdateTaskRequest, TaskResponse, SummaryResponse
)
from task_manager.infrastructure.container import get_task_service


router = APIRouter(prefix="/tasks", tags=["tasks"])


def handle_domain_errors(func):
    """Decorator that converts domain exceptions to HTTP responses."""
    from functools import wraps

    @wraps(func)
    def wrapper(*args, **kwargs):
        try:
            return func(*args, **kwargs)
        except TaskNotFoundError as e:
            raise HTTPException(status_code=404, detail=str(e))
        except TaskAlreadyCompleteError as e:
            raise HTTPException(status_code=409, detail=str(e))
        except InvalidTaskError as e:
            raise HTTPException(status_code=422, detail=str(e))
    return wrapper


@router.get("", response_model=list[TaskResponse])
@handle_domain_errors
def list_tasks(
    status: str | None = None,
    priority: str | None = None,
    search: str | None = None,
    service: TaskService = Depends(get_task_service),
):
    tasks = service.list_tasks(status=status, priority=priority, search=search)
    return [TaskResponse.model_validate(t.__dict__) for t in tasks]


@router.post("", response_model=TaskResponse, status_code=201)
@handle_domain_errors
def create_task(
    request: CreateTaskRequest,
    service: TaskService = Depends(get_task_service),
):
    task = service.create_task(
        title=request.title,
        priority=request.priority,
        notes=request.notes,
    )
    return TaskResponse.model_validate(task.__dict__)


@router.get("/summary", response_model=SummaryResponse)
def get_summary(service: TaskService = Depends(get_task_service)):
    return service.get_summary()


@router.get("/{task_id}", response_model=TaskResponse)
@handle_domain_errors
def get_task(task_id: int, service: TaskService = Depends(get_task_service)):
    task = service.get_task(task_id)
    return TaskResponse.model_validate(task.__dict__)


@router.patch("/{task_id}", response_model=TaskResponse)
@handle_domain_errors
def update_task(
    task_id: int,
    request: UpdateTaskRequest,
    service: TaskService = Depends(get_task_service),
):
    task = service.update_task(
        task_id,
        title=request.title,
        priority=request.priority,
        notes=request.notes,
    )
    return TaskResponse.model_validate(task.__dict__)


@router.post("/{task_id}/complete", response_model=TaskResponse)
@handle_domain_errors
def complete_task(
    task_id: int,
    service: TaskService = Depends(get_task_service),
):
    task = service.complete_task(task_id)
    return TaskResponse.model_validate(task.__dict__)


@router.delete("/{task_id}", status_code=204)
@handle_domain_errors
def delete_task(
    task_id: int,
    service: TaskService = Depends(get_task_service),
):
    service.delete_task(task_id)
```

---

## Testing with architecture — why layers pay off

```python
# tests/conftest.py

import pytest
from task_manager.repositories.memory_task_repository import InMemoryTaskRepository
from task_manager.services.task_service import TaskService
from task_manager.infrastructure.config import get_settings


@pytest.fixture
def repo():
    """Fresh in-memory repository for each test — no database."""
    return InMemoryTaskRepository()


@pytest.fixture
def service(repo):
    """TaskService wired to in-memory repository."""
    return TaskService(repo)


@pytest.fixture(autouse=True)
def reset_settings_cache():
    """Clear settings cache between tests."""
    get_settings.cache_clear()
    yield
    get_settings.cache_clear()
```

```python
# tests/unit/test_task_service.py

import pytest
from task_manager.domain.task import Priority, TaskStatus
from task_manager.domain.exceptions import (
    TaskNotFoundError, InvalidTaskError, TaskAlreadyCompleteError
)


class TestCreateTask:

    def test_creates_task_with_correct_fields(self, service):
        task = service.create_task("Fix bug", priority="high")
        assert task.title == "Fix bug"
        assert task.priority == Priority.HIGH
        assert task.status == TaskStatus.PENDING
        assert task.id > 0    # repository assigned a real ID

    def test_strips_title_whitespace(self, service):
        task = service.create_task("  Fix bug  ")
        assert task.title == "Fix bug"

    def test_empty_title_raises(self, service):
        with pytest.raises(InvalidTaskError, match="empty"):
            service.create_task("")

    def test_invalid_priority_raises(self, service):
        with pytest.raises(InvalidTaskError, match="Invalid priority"):
            service.create_task("Task", priority="urgent")

    def test_default_priority_is_medium(self, service):
        task = service.create_task("Task")
        assert task.priority == Priority.MEDIUM


class TestCompleteTask:

    def test_completes_pending_task(self, service):
        task = service.create_task("Task")
        completed = service.complete_task(task.id)
        assert completed.status == TaskStatus.DONE

    def test_completing_nonexistent_raises(self, service):
        with pytest.raises(TaskNotFoundError) as exc_info:
            service.complete_task(999)
        assert exc_info.value.task_id == 999

    def test_completing_already_done_raises(self, service):
        task = service.create_task("Task")
        service.complete_task(task.id)
        with pytest.raises(TaskAlreadyCompleteError):
            service.complete_task(task.id)


class TestGetSummary:

    def test_summary_counts(self, service):
        service.create_task("Task 1", "high")
        service.create_task("Task 2", "high")
        task3 = service.create_task("Task 3", "low")
        service.complete_task(task3.id)

        summary = service.get_summary()
        assert summary["total"] == 3
        assert summary["done"] == 1
        assert summary["pending"] == 2
        assert summary["high_priority_pending"] == 2
```

Tests are fast — no database setup, no file I/O, no HTTP. The `InMemoryTaskRepository` makes every test instant. The architecture made this possible without any test-specific code in the production path.

---

## The mental model to carry forward

Architecture is about controlling change. When requirements change — and they always do — well-architected code changes in one place. Poorly-architected code changes everywhere.

**The questions to ask about any piece of code:**

- What is this responsible for? (one thing, stated clearly)
- What does it depend on? (as little as possible, and only lower layers)
- How do I test it without its dependencies? (inject them, replace with fakes)
- If this changes, what else changes? (ideally nothing)

**The signals that architecture is breaking down:**

- Changing a database column requires touching the API layer
- Testing business logic requires a running database
- Adding a feature requires reading ten files to understand the impact
- Two unrelated features share a file because "it seemed related"

**The three rules that prevent most architectural problems:**

- Dependencies flow one way — lower layers never import upper layers
- Business logic lives in services — not in routes, not in models, not in database queries
- Inject dependencies — never instantiate collaborators inside business logic

---

Day 30 is the capstone — you build a complete application from scratch using everything from the course, structured with the architecture from today. Ready when you are.

[[Advanced]]