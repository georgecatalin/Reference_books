#### Building APIs with FastAPI

## What a web framework does

A web framework handles the plumbing between HTTP and your Python code. When a request comes in, it:

- Parses the URL and HTTP method
- Extracts path parameters, query parameters, and request body
- Calls the right function
- Serializes the return value to JSON
- Sends the HTTP response

Without a framework you write all of that yourself. FastAPI gives it to you and adds automatic validation, serialization, and documentation.

```bash
pip install fastapi uvicorn[standard]
```

`uvicorn` is the ASGI server that runs your FastAPI application. ASGI is the async equivalent of WSGI — the interface between Python web apps and web servers.

---

## Your first FastAPI application

```python
# main.py
from fastapi import FastAPI

app = FastAPI(title="Task Manager API", version="1.0.0")

@app.get("/")
def root():
    return {"message": "Task Manager API", "version": "1.0.0"}

@app.get("/health")
def health():
    return {"status": "ok"}
```

```bash
uvicorn main:app --reload
# --reload restarts the server when files change
# Running on http://127.0.0.1:8000

# FastAPI generates interactive docs automatically:
# http://127.0.0.1:8000/docs      — Swagger UI
# http://127.0.0.1:8000/redoc     — ReDoc
```

Open `http://127.0.0.1:8000/docs` — you get a full interactive API explorer with zero configuration. Every endpoint, parameter, and response schema is documented automatically from your code.

---

## Path parameters and query parameters

```python
from fastapi import FastAPI, HTTPException, Query
from typing import Optional

app = FastAPI()

# Simulated data store
tasks = {
    1: {"id": 1, "title": "Fix login bug", "priority": "high", "done": False},
    2: {"id": 2, "title": "Write tests", "priority": "medium", "done": False},
    3: {"id": 3, "title": "Update docs", "priority": "low", "done": True},
}


# Path parameter — part of the URL
@app.get("/tasks/{task_id}")
def get_task(task_id: int):    # FastAPI validates: must be an integer
    task = tasks.get(task_id)
    if task is None:
        raise HTTPException(status_code=404, detail=f"Task {task_id} not found")
    return task

# GET /tasks/1        → returns task 1
# GET /tasks/abc      → 422 Unprocessable Entity — "abc" is not an int
# GET /tasks/999      → 404 Not Found


# Query parameters — after the ? in the URL
@app.get("/tasks")
def list_tasks(
    status: Optional[str] = None,      # ?status=done
    priority: Optional[str] = None,    # ?priority=high
    limit: int = Query(default=20, ge=1, le=100),   # ?limit=10 — validated 1-100
    offset: int = Query(default=0, ge=0),            # ?offset=20
):
    result = list(tasks.values())

    if status == "done":
        result = [t for t in result if t["done"]]
    elif status == "pending":
        result = [t for t in result if not t["done"]]

    if priority:
        result = [t for t in result if t["priority"] == priority]

    return {
        "tasks": result[offset:offset + limit],
        "total": len(result),
        "limit": limit,
        "offset": offset,
    }

# GET /tasks                          → all tasks
# GET /tasks?status=pending           → pending only
# GET /tasks?priority=high&limit=5    → high priority, max 5
# GET /tasks?limit=200                → 422 — limit must be ≤ 100
```

---

## Request bodies with Pydantic

FastAPI uses Pydantic for request validation. Define your schema, FastAPI handles the rest.

```python
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field, field_validator
from typing import Optional
from datetime import datetime

app = FastAPI()


class CreateTaskRequest(BaseModel):
    title: str = Field(min_length=1, max_length=200)
    priority: str = Field(default="medium")
    notes: Optional[str] = None

    @field_validator("priority")
    @classmethod
    def validate_priority(cls, v: str) -> str:
        if v not in {"low", "medium", "high"}:
            raise ValueError("Must be 'low', 'medium', or 'high'")
        return v

    @field_validator("title")
    @classmethod
    def strip_title(cls, v: str) -> str:
        return v.strip()


class UpdateTaskRequest(BaseModel):
    title: Optional[str] = Field(default=None, min_length=1, max_length=200)
    priority: Optional[str] = None
    notes: Optional[str] = None

    @field_validator("priority")
    @classmethod
    def validate_priority(cls, v: Optional[str]) -> Optional[str]:
        if v is not None and v not in {"low", "medium", "high"}:
            raise ValueError("Must be 'low', 'medium', or 'high'")
        return v


class TaskResponse(BaseModel):
    id: int
    title: str
    priority: str
    done: bool
    notes: Optional[str]
    created_at: str

    model_config = {"from_attributes": True}


# In-memory store for the example
_tasks: dict[int, dict] = {}
_next_id = 1


@app.post("/tasks", response_model=TaskResponse, status_code=201)
def create_task(request: CreateTaskRequest):
    global _next_id
    task = {
        "id": _next_id,
        "title": request.title,
        "priority": request.priority,
        "done": False,
        "notes": request.notes,
        "created_at": datetime.now().isoformat(),
    }
    _tasks[_next_id] = task
    _next_id += 1
    return task

# POST /tasks
# Body: {"title": "Fix bug", "priority": "high"}
# → 201 Created + task JSON

# Body: {"title": "", "priority": "urgent"}
# → 422 Unprocessable Entity with detailed error:
# {
#   "detail": [
#     {"loc": ["body", "title"], "msg": "String should have at least 1 character"},
#     {"loc": ["body", "priority"], "msg": "Value error, Must be 'low', 'medium', or 'high'"}
#   ]
# }


@app.patch("/tasks/{task_id}", response_model=TaskResponse)
def update_task(task_id: int, request: UpdateTaskRequest):
    task = _tasks.get(task_id)
    if task is None:
        raise HTTPException(status_code=404, detail=f"Task {task_id} not found")

    if request.title is not None:
        task["title"] = request.title.strip()
    if request.priority is not None:
        task["priority"] = request.priority
    if request.notes is not None:
        task["notes"] = request.notes

    return task
```

The `response_model` parameter tells FastAPI what shape the response should have. It filters out any extra fields and validates the output. If your function accidentally returns a field that's not in the response model, it's stripped automatically.

---

## A complete REST API — full CRUD

```python
# api.py
from fastapi import FastAPI, HTTPException, Query, Depends
from pydantic import BaseModel, Field, field_validator
from typing import Optional
from datetime import datetime
import sqlite3
from contextlib import contextmanager


app = FastAPI(
    title="Task Manager API",
    description="A production-ready task management API",
    version="1.0.0",
)


# ── Pydantic schemas ────────────────────────────────────────────

class CreateTaskRequest(BaseModel):
    title: str = Field(min_length=1, max_length=200)
    priority: str = "medium"
    notes: Optional[str] = None

    @field_validator("priority")
    @classmethod
    def valid_priority(cls, v):
        if v not in {"low", "medium", "high"}:
            raise ValueError("Must be low, medium, or high")
        return v

    @field_validator("title")
    @classmethod
    def strip_title(cls, v):
        return v.strip()


class UpdateTaskRequest(BaseModel):
    title: Optional[str] = Field(default=None, min_length=1)
    priority: Optional[str] = None
    done: Optional[bool] = None
    notes: Optional[str] = None

    @field_validator("priority")
    @classmethod
    def valid_priority(cls, v):
        if v is not None and v not in {"low", "medium", "high"}:
            raise ValueError("Must be low, medium, or high")
        return v


class TaskResponse(BaseModel):
    id: int
    title: str
    priority: str
    done: bool
    notes: Optional[str]
    created_at: str


class TaskListResponse(BaseModel):
    tasks: list[TaskResponse]
    total: int
    limit: int
    offset: int


class SummaryResponse(BaseModel):
    total: int
    done: int
    pending: int
    by_priority: dict[str, int]


# ── Database ────────────────────────────────────────────────────

DATABASE = "api_tasks.db"


def init_db():
    with sqlite3.connect(DATABASE) as conn:
        conn.row_factory = sqlite3.Row
        conn.execute("""
            CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                priority TEXT NOT NULL DEFAULT 'medium',
                done INTEGER NOT NULL DEFAULT 0,
                notes TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        """)
        conn.commit()


@contextmanager
def get_db():
    conn = sqlite3.connect(DATABASE)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def row_to_dict(row) -> dict:
    return {
        "id": row["id"],
        "title": row["title"],
        "priority": row["priority"],
        "done": bool(row["done"]),
        "notes": row["notes"],
        "created_at": row["created_at"],
    }


# Initialize on startup
init_db()


# ── Routes ──────────────────────────────────────────────────────

@app.get("/tasks", response_model=TaskListResponse)
def list_tasks(
    status: Optional[str] = Query(default=None, pattern="^(done|pending)$"),
    priority: Optional[str] = Query(default=None, pattern="^(low|medium|high)$"),
    search: Optional[str] = Query(default=None, max_length=100),
    limit: int = Query(default=20, ge=1, le=100),
    offset: int = Query(default=0, ge=0),
):
    conditions = []
    params = []

    if status == "done":
        conditions.append("done = 1")
    elif status == "pending":
        conditions.append("done = 0")

    if priority:
        conditions.append("priority = ?")
        params.append(priority)

    if search:
        conditions.append("title LIKE ?")
        params.append(f"%{search}%")

    where = f"WHERE {' AND '.join(conditions)}" if conditions else ""

    with get_db() as conn:
        total = conn.execute(
            f"SELECT COUNT(*) FROM tasks {where}", params
        ).fetchone()[0]

        rows = conn.execute(
            f"""SELECT * FROM tasks {where}
                ORDER BY CASE priority
                    WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, id
                LIMIT ? OFFSET ?""",
            params + [limit, offset]
        ).fetchall()

    return {
        "tasks": [row_to_dict(r) for r in rows],
        "total": total,
        "limit": limit,
        "offset": offset,
    }


@app.post("/tasks", response_model=TaskResponse, status_code=201)
def create_task(request: CreateTaskRequest):
    with get_db() as conn:
        cursor = conn.execute(
            "INSERT INTO tasks (title, priority, notes) VALUES (?, ?, ?)",
            (request.title, request.priority, request.notes)
        )
        task_id = cursor.lastrowid
        row = conn.execute(
            "SELECT * FROM tasks WHERE id = ?", (task_id,)
        ).fetchone()

    return row_to_dict(row)


@app.get("/tasks/{task_id}", response_model=TaskResponse)
def get_task(task_id: int):
    with get_db() as conn:
        row = conn.execute(
            "SELECT * FROM tasks WHERE id = ?", (task_id,)
        ).fetchone()

    if row is None:
        raise HTTPException(status_code=404, detail=f"Task {task_id} not found")

    return row_to_dict(row)


@app.patch("/tasks/{task_id}", response_model=TaskResponse)
def update_task(task_id: int, request: UpdateTaskRequest):
    with get_db() as conn:
        existing = conn.execute(
            "SELECT * FROM tasks WHERE id = ?", (task_id,)
        ).fetchone()

        if existing is None:
            raise HTTPException(status_code=404, detail=f"Task {task_id} not found")

        updates = {}
        if request.title is not None:
            updates["title"] = request.title.strip()
        if request.priority is not None:
            updates["priority"] = request.priority
        if request.done is not None:
            updates["done"] = int(request.done)
        if request.notes is not None:
            updates["notes"] = request.notes

        if updates:
            set_clause = ", ".join(f"{k} = ?" for k in updates)
            conn.execute(
                f"UPDATE tasks SET {set_clause} WHERE id = ?",
                list(updates.values()) + [task_id]
            )

        row = conn.execute(
            "SELECT * FROM tasks WHERE id = ?", (task_id,)
        ).fetchone()

    return row_to_dict(row)


@app.post("/tasks/{task_id}/complete", response_model=TaskResponse)
def complete_task(task_id: int):
    with get_db() as conn:
        existing = conn.execute(
            "SELECT * FROM tasks WHERE id = ?", (task_id,)
        ).fetchone()

        if existing is None:
            raise HTTPException(status_code=404, detail=f"Task {task_id} not found")

        conn.execute("UPDATE tasks SET done = 1 WHERE id = ?", (task_id,))
        row = conn.execute(
            "SELECT * FROM tasks WHERE id = ?", (task_id,)
        ).fetchone()

    return row_to_dict(row)


@app.delete("/tasks/{task_id}", status_code=204)
def delete_task(task_id: int):
    with get_db() as conn:
        result = conn.execute(
            "DELETE FROM tasks WHERE id = ?", (task_id,)
        )

    if result.rowcount == 0:
        raise HTTPException(status_code=404, detail=f"Task {task_id} not found")

    # 204 No Content — return nothing


@app.get("/tasks/summary/stats", response_model=SummaryResponse)
def get_summary():
    with get_db() as conn:
        row = conn.execute("""
            SELECT
                COUNT(*) as total,
                SUM(done) as done_count,
                SUM(CASE WHEN priority='high' AND done=0 THEN 1 ELSE 0 END) as high,
                SUM(CASE WHEN priority='medium' AND done=0 THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN priority='low' AND done=0 THEN 1 ELSE 0 END) as low
            FROM tasks
        """).fetchone()

    total = row["total"] or 0
    done = row["done_count"] or 0
    return {
        "total": total,
        "done": done,
        "pending": total - done,
        "by_priority": {
            "high": row["high"] or 0,
            "medium": row["medium"] or 0,
            "low": row["low"] or 0,
        }
    }
```

---

## Dependency injection — FastAPI's superpower

FastAPI's `Depends()` system provides shared resources (database connections, authentication, configuration) to routes without global state.

```python
from fastapi import FastAPI, Depends, HTTPException, Header
from typing import Annotated
import sqlite3
from contextlib import contextmanager


app = FastAPI()


# ── Database dependency ──────────────────────────────────────────

def get_db_connection():
    """Yields a database connection, closes it when done."""
    conn = sqlite3.connect("tasks.db")
    conn.row_factory = sqlite3.Row
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()

# Type alias for cleaner route signatures
DBConn = Annotated[sqlite3.Connection, Depends(get_db_connection)]


# ── Authentication dependency ────────────────────────────────────

VALID_API_KEYS = {"secret-key-1", "secret-key-2"}    # in reality: from database

def verify_api_key(x_api_key: Annotated[str, Header()]) -> str:
    """Validate API key from X-API-Key header."""
    if x_api_key not in VALID_API_KEYS:
        raise HTTPException(status_code=401, detail="Invalid API key")
    return x_api_key

AuthKey = Annotated[str, Depends(verify_api_key)]


# ── Routes using dependencies ────────────────────────────────────

@app.get("/tasks")
def list_tasks(db: DBConn):
    # db is injected automatically — opened before, closed after
    rows = db.execute("SELECT * FROM tasks").fetchall()
    return [dict(r) for r in rows]


@app.post("/tasks", status_code=201)
def create_task(
    request: CreateTaskRequest,
    db: DBConn,
    api_key: AuthKey,    # requires valid API key
):
    cursor = db.execute(
        "INSERT INTO tasks (title, priority) VALUES (?, ?)",
        (request.title, request.priority)
    )
    return {"id": cursor.lastrowid, "title": request.title}


# ── Composing dependencies ───────────────────────────────────────

def get_current_user(
    api_key: AuthKey,
    db: DBConn,
) -> dict:
    """Get the user associated with an API key."""
    user = db.execute(
        "SELECT * FROM users WHERE api_key = ?", (api_key,)
    ).fetchone()
    if user is None:
        raise HTTPException(status_code=401, detail="User not found")
    return dict(user)

CurrentUser = Annotated[dict, Depends(get_current_user)]


@app.delete("/tasks/{task_id}")
def delete_task(
    task_id: int,
    db: DBConn,
    user: CurrentUser,    # auth + user lookup in one dependency
):
    # user is available here
    pass
```

Dependencies compose naturally. `CurrentUser` depends on `AuthKey` which depends on the header. FastAPI resolves the whole chain automatically.

---

## Middleware — code that runs on every request

```python
import time
import uuid
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware

app = FastAPI()


# CORS — allow browsers from other origins to call your API
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:3000", "https://myapp.com"],
    allow_methods=["*"],
    allow_headers=["*"],
)


# Custom middleware — runs before and after every request
@app.middleware("http")
async def add_request_id(request: Request, call_next):
    request_id = str(uuid.uuid4())[:8]
    start = time.perf_counter()

    response = await call_next(request)

    elapsed = time.perf_counter() - start
    response.headers["X-Request-ID"] = request_id
    response.headers["X-Response-Time"] = f"{elapsed:.4f}s"

    print(f"{request.method} {request.url.path} → {response.status_code} ({elapsed:.4f}s)")
    return response
```

---

## Error handling — consistent error responses

```python
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from pydantic import ValidationError


app = FastAPI()


class AppError(Exception):
    def __init__(self, status_code: int, detail: str, code: str = "error"):
        self.status_code = status_code
        self.detail = detail
        self.code = code


@app.exception_handler(AppError)
async def app_error_handler(request: Request, exc: AppError):
    return JSONResponse(
        status_code=exc.status_code,
        content={
            "error": exc.code,
            "detail": exc.detail,
            "path": str(request.url.path),
        }
    )


@app.exception_handler(404)
async def not_found_handler(request: Request, exc):
    return JSONResponse(
        status_code=404,
        content={"error": "not_found", "detail": f"Path {request.url.path} not found"}
    )


# Use in routes
@app.get("/tasks/{task_id}")
def get_task(task_id: int):
    task = find_task(task_id)
    if task is None:
        raise AppError(404, f"Task {task_id} not found", "task_not_found")
    return task
```

---

## Testing FastAPI — using TestClient

```python
# tests/test_api.py
import pytest
from fastapi.testclient import TestClient
from api import app    # import your FastAPI app


client = TestClient(app)


class TestCreateTask:

    def test_create_valid_task(self):
        response = client.post("/tasks", json={
            "title": "Fix login bug",
            "priority": "high"
        })
        assert response.status_code == 201
        data = response.json()
        assert data["title"] == "Fix login bug"
        assert data["priority"] == "high"
        assert data["done"] == False
        assert "id" in data

    def test_create_empty_title_fails(self):
        response = client.post("/tasks", json={"title": "", "priority": "high"})
        assert response.status_code == 422

    def test_create_invalid_priority_fails(self):
        response = client.post("/tasks", json={"title": "Task", "priority": "urgent"})
        assert response.status_code == 422

    def test_create_default_priority_is_medium(self):
        response = client.post("/tasks", json={"title": "Task"})
        assert response.status_code == 201
        assert response.json()["priority"] == "medium"


class TestGetTask:

    def test_get_existing_task(self):
        created = client.post("/tasks", json={"title": "Test task"}).json()
        response = client.get(f"/tasks/{created['id']}")
        assert response.status_code == 200
        assert response.json()["id"] == created["id"]

    def test_get_nonexistent_task(self):
        response = client.get("/tasks/99999")
        assert response.status_code == 404

    def test_get_invalid_id(self):
        response = client.get("/tasks/not-an-id")
        assert response.status_code == 422


class TestCompleteTask:

    def test_complete_task(self):
        task = client.post("/tasks", json={"title": "Complete me"}).json()
        response = client.post(f"/tasks/{task['id']}/complete")
        assert response.status_code == 200
        assert response.json()["done"] == True


class TestDeleteTask:

    def test_delete_task(self):
        task = client.post("/tasks", json={"title": "Delete me"}).json()
        response = client.delete(f"/tasks/{task['id']}")
        assert response.status_code == 204

        get_response = client.get(f"/tasks/{task['id']}")
        assert get_response.status_code == 404

    def test_delete_nonexistent(self):
        response = client.delete("/tasks/99999")
        assert response.status_code == 404
```

```bash
pytest tests/ -v
```

`TestClient` makes real HTTP requests to your app in-process — no server needed. Every test is a real API call with real request/response cycles.

---

## Running in production

```bash
# Development
uvicorn api:app --reload --port 8000

# Production — multiple workers, no reload
uvicorn api:app --workers 4 --port 8000

# With gunicorn (process manager) + uvicorn workers
pip install gunicorn
gunicorn api:app -w 4 -k uvicorn.workers.UvicornWorker --bind 0.0.0.0:8000
```

```dockerfile
# Dockerfile
FROM python:3.11-slim

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

CMD ["uvicorn", "api:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "4"]
```

---

## The mental model to carry forward

FastAPI is request → validation → function → response. The framework handles everything in between. You write functions that take typed inputs and return typed outputs. FastAPI does the HTTP.

**The four things FastAPI gives you for free:**

- Automatic validation — bad input never reaches your function
- Automatic serialization — return a dict or Pydantic model, get JSON back
- Automatic documentation — `/docs` always reflects your actual code
- Dependency injection — shared resources without global state

**The REST conventions to follow:**

- `GET /resources` — list
- `POST /resources` — create, returns 201
- `GET /resources/{id}` — get one
- `PATCH /resources/{id}` — partial update
- `PUT /resources/{id}` — full replace
- `DELETE /resources/{id}` — delete, returns 204

**Status codes that matter:**

- `200 OK` — success with body
- `201 Created` — resource created
- `204 No Content` — success, no body (delete)
- `400 Bad Request` — client sent bad data
- `401 Unauthorized` — not authenticated
- `403 Forbidden` — authenticated but not allowed
- `404 Not Found` — resource doesn't exist
- `422 Unprocessable Entity` — validation failed (FastAPI's default for bad input)
- `500 Internal Server Error` — your bug

---

Day 27 is data work — pandas fundamentals, loading and cleaning datasets, and transforming data into something useful. Ready when you are.

[[Advanced]]