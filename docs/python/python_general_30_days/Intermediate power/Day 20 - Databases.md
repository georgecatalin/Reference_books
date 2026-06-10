#### SQLite, SQL, and SQLAlchemy

## Why databases over files

The task manager stores data in JSON. That works until:

- Two processes try to write simultaneously — corruption
- You need to find all high-priority tasks — scan every record
- You have 100,000 tasks — loading the whole file is slow
- You need "give me tasks created this week" — JSON has no query language

A database solves all of these. SQLite is a database that lives in a single file — no server, no setup, ships with Python. It's the right choice for local apps, prototypes, and anything that doesn't need concurrent writes from multiple machines.

---

## SQL fundamentals — the five operations you use constantly

```sql
-- CREATE TABLE — define structure
CREATE TABLE tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    priority TEXT DEFAULT 'medium',
    done INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now'))
);

-- INSERT — add data
INSERT INTO tasks (title, priority) VALUES ('Fix login bug', 'high');

-- SELECT — query data
SELECT * FROM tasks;
SELECT id, title FROM tasks WHERE priority = 'high';
SELECT * FROM tasks WHERE done = 0 ORDER BY priority DESC;
SELECT COUNT(*) FROM tasks WHERE done = 1;

-- UPDATE — modify data
UPDATE tasks SET done = 1 WHERE id = 3;
UPDATE tasks SET priority = 'low' WHERE priority = 'medium' AND done = 1;

-- DELETE — remove data
DELETE FROM tasks WHERE id = 5;
DELETE FROM tasks WHERE done = 1;
```

---

## sqlite3 — Python's built-in SQLite interface

```python
import sqlite3

# Connect — creates the file if it doesn't exist
conn = sqlite3.connect("tasks.db")

# Get a cursor — executes SQL statements
cursor = conn.cursor()

# Execute SQL
cursor.execute("""
    CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        priority TEXT DEFAULT 'medium',
        done INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now'))
    )
""")

# Commit changes — required for INSERT/UPDATE/DELETE
conn.commit()

# Always close when done
conn.close()
```

**Use context manager for automatic commit/rollback:**

```python
import sqlite3

with sqlite3.connect("tasks.db") as conn:
    conn.execute("""
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            priority TEXT DEFAULT 'medium',
            done INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now'))
        )
    """)
# Auto-commits on success, auto-rollbacks on exception
# Does NOT auto-close — but that's fine for short-lived connections
```

---

## Inserting data — always use parameterized queries

```python
import sqlite3

conn = sqlite3.connect("tasks.db")

# NEVER do this — SQL injection vulnerability
title = "Fix bug'; DROP TABLE tasks; --"
conn.execute(f"INSERT INTO tasks (title) VALUES ('{title}')")    # DANGEROUS

# Always use parameterized queries — ? placeholders
conn.execute(
    "INSERT INTO tasks (title, priority) VALUES (?, ?)",
    ("Fix login bug", "high")
)

# Insert multiple rows efficiently
tasks = [
    ("Write tests", "medium"),
    ("Update docs", "low"),
    ("Deploy to staging", "high"),
]
conn.executemany(
    "INSERT INTO tasks (title, priority) VALUES (?, ?)",
    tasks
)

conn.commit()

# Get the ID of the last inserted row
cursor = conn.execute(
    "INSERT INTO tasks (title, priority) VALUES (?, ?)",
    ("Final task", "medium")
)
print(cursor.lastrowid)    # the auto-generated ID

conn.close()
```

SQL injection is how databases get destroyed or leaked. Parameterized queries are non-negotiable — always.

---

## Querying data — SELECT in depth

```python
import sqlite3

conn = sqlite3.connect("tasks.db")
conn.row_factory = sqlite3.Row    # rows behave like dicts AND tuples

# Fetch all rows
cursor = conn.execute("SELECT * FROM tasks")
rows = cursor.fetchall()
for row in rows:
    print(row["title"], row["priority"])    # dict-style access

# Fetch one row
cursor = conn.execute("SELECT * FROM tasks WHERE id = ?", (1,))
row = cursor.fetchone()    # None if not found
if row:
    print(dict(row))    # convert to plain dict

# Fetch in batches — memory efficient for large results
cursor = conn.execute("SELECT * FROM tasks WHERE done = 0")
while batch := cursor.fetchmany(100):    # fetch 100 at a time
    for row in batch:
        process(row)

# Iterate directly — most memory efficient
for row in conn.execute("SELECT * FROM tasks ORDER BY created_at DESC"):
    print(row["title"])

conn.close()
```

`conn.row_factory = sqlite3.Row` is the first thing to set on any connection. Without it, rows are plain tuples — you access by index (`row[1]`) not name (`row["title"]`). Name access is safer and more readable.

---

## Filtering, sorting, aggregating

```python
conn = sqlite3.connect("tasks.db")
conn.row_factory = sqlite3.Row

# WHERE — filtering
pending = conn.execute(
    "SELECT * FROM tasks WHERE done = 0"
).fetchall()

high_priority = conn.execute(
    "SELECT * FROM tasks WHERE priority = ? AND done = 0",
    ("high",)
).fetchall()

# IN — match against a list
conn.execute(
    "SELECT * FROM tasks WHERE priority IN (?, ?)",
    ("high", "medium")
).fetchall()

# LIKE — pattern matching (% = wildcard)
conn.execute(
    "SELECT * FROM tasks WHERE title LIKE ?",
    ("%bug%",)        # titles containing "bug"
).fetchall()

# ORDER BY
conn.execute("""
    SELECT * FROM tasks
    ORDER BY
        CASE priority
            WHEN 'high' THEN 1
            WHEN 'medium' THEN 2
            WHEN 'low' THEN 3
        END,
        created_at DESC
""").fetchall()

# Aggregation
stats = conn.execute("""
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN done = 1 THEN 1 ELSE 0 END) as done_count,
        SUM(CASE WHEN done = 0 THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN priority = 'high' AND done = 0 THEN 1 ELSE 0 END) as high_pending
    FROM tasks
""").fetchone()

print(f"Total: {stats['total']}, Done: {stats['done_count']}")

# GROUP BY
by_priority = conn.execute("""
    SELECT priority, COUNT(*) as count
    FROM tasks
    WHERE done = 0
    GROUP BY priority
    ORDER BY count DESC
""").fetchall()

for row in by_priority:
    print(f"{row['priority']}: {row['count']} pending")

conn.close()
```

---

## Transactions — all or nothing

A transaction groups multiple operations so they either all succeed or all fail. This prevents partial updates that leave your data in an inconsistent state.

```python
import sqlite3

conn = sqlite3.connect("tasks.db")

# Explicit transaction
try:
    conn.execute("BEGIN")
    conn.execute("UPDATE tasks SET done = 1 WHERE id = ?", (1,))
    conn.execute("INSERT INTO audit_log (action) VALUES (?)", ("completed task 1",))
    conn.execute("COMMIT")
except Exception:
    conn.execute("ROLLBACK")
    raise

# Context manager — cleaner
with conn:    # auto-commits on success, auto-rollbacks on exception
    conn.execute("UPDATE tasks SET done = 1 WHERE id = ?", (2,))
    conn.execute("INSERT INTO audit_log (action) VALUES (?)", ("completed task 2",))

conn.close()
```

SQLite's default mode auto-commits every statement. Explicit transactions are faster for bulk operations (one disk write instead of N) and safer for operations that must succeed or fail together.

---

## A complete SQLite task storage layer

```python
# storage_sqlite.py

import sqlite3
from pathlib import Path
from contextlib import contextmanager


SCHEMA = """
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    priority TEXT NOT NULL DEFAULT 'medium',
    done INTEGER NOT NULL DEFAULT 0,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_tasks_priority ON tasks(priority);
CREATE INDEX IF NOT EXISTS idx_tasks_done ON tasks(done);
"""


class SQLiteTaskStorage:
    """SQLite-backed task storage — drop-in replacement for JSON storage."""

    def __init__(self, filepath="tasks.db"):
        self.filepath = Path(filepath)
        self._init_db()

    def _connect(self):
        conn = sqlite3.connect(str(self.filepath))
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA journal_mode=WAL")    # better concurrent read performance
        conn.execute("PRAGMA foreign_keys=ON")
        return conn

    @contextmanager
    def _db(self):
        """Context manager that provides a connection and handles transactions."""
        conn = self._connect()
        try:
            yield conn
            conn.commit()
        except Exception:
            conn.rollback()
            raise
        finally:
            conn.close()

    def _init_db(self):
        """Create tables if they don't exist."""
        with self._db() as conn:
            conn.executescript(SCHEMA)

    def add(self, title, priority="medium", notes=None):
        """Insert a task. Returns the new task as a dict."""
        with self._db() as conn:
            cursor = conn.execute(
                "INSERT INTO tasks (title, priority, notes) VALUES (?, ?, ?)",
                (title, priority, notes)
            )
            return self.get_by_id(cursor.lastrowid)

    def get_by_id(self, task_id):
        """Fetch one task by ID. Returns dict or None."""
        with self._db() as conn:
            row = conn.execute(
                "SELECT * FROM tasks WHERE id = ?", (task_id,)
            ).fetchone()
        return dict(row) if row else None

    def update(self, task_id, **fields):
        """Update specific fields of a task."""
        allowed = {"title", "priority", "done", "notes"}
        updates = {k: v for k, v in fields.items() if k in allowed}
        if not updates:
            return self.get_by_id(task_id)

        set_clause = ", ".join(f"{k} = ?" for k in updates)
        values = list(updates.values()) + [task_id]

        with self._db() as conn:
            conn.execute(
                f"UPDATE tasks SET {set_clause} WHERE id = ?",
                values
            )
        return self.get_by_id(task_id)

    def delete(self, task_id):
        """Delete a task. Returns True if deleted, False if not found."""
        with self._db() as conn:
            cursor = conn.execute(
                "DELETE FROM tasks WHERE id = ?", (task_id,)
            )
        return cursor.rowcount > 0

    def list(self, status=None, priority=None, search=None):
        """Query tasks with optional filters."""
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

        query = f"""
            SELECT * FROM tasks
            {where}
            ORDER BY
                CASE priority
                    WHEN 'high' THEN 1
                    WHEN 'medium' THEN 2
                    WHEN 'low' THEN 3
                END,
                created_at ASC
        """

        with self._db() as conn:
            rows = conn.execute(query, params).fetchall()

        return [dict(row) for row in rows]

    def summary(self):
        """Return task statistics."""
        with self._db() as conn:
            stats = conn.execute("""
                SELECT
                    COUNT(*) as total,
                    SUM(done) as done_count,
                    SUM(CASE WHEN priority='high' AND done=0 THEN 1 ELSE 0 END) as high_pending,
                    SUM(CASE WHEN priority='medium' AND done=0 THEN 1 ELSE 0 END) as medium_pending,
                    SUM(CASE WHEN priority='low' AND done=0 THEN 1 ELSE 0 END) as low_pending
                FROM tasks
            """).fetchone()

        total = stats["total"] or 0
        done = stats["done_count"] or 0
        return {
            "total": total,
            "done": done,
            "pending": total - done,
            "by_priority": {
                "high": stats["high_pending"] or 0,
                "medium": stats["medium_pending"] or 0,
                "low": stats["low_pending"] or 0,
            }
        }
```

---

## SQLAlchemy — the Python ORM

SQLAlchemy maps Python classes to database tables. You write Python, it writes SQL.

```bash
pip install sqlalchemy
```

```python
from sqlalchemy import create_engine, Column, Integer, String, Boolean, DateTime
from sqlalchemy.orm import DeclarativeBase, Session
from sqlalchemy.sql import func
from datetime import datetime


# 1. Define the engine — the connection to the database
engine = create_engine(
    "sqlite:///tasks.db",
    echo=False           # echo=True prints all SQL — useful for debugging
)


# 2. Define the base class
class Base(DeclarativeBase):
    pass


# 3. Define models — Python classes that map to tables
class Task(Base):
    __tablename__ = "tasks"

    id = Column(Integer, primary_key=True, autoincrement=True)
    title = Column(String, nullable=False)
    priority = Column(String, default="medium")
    done = Column(Boolean, default=False)
    notes = Column(String, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)

    def __repr__(self):
        return f"Task(id={self.id}, title={self.title!r}, priority={self.priority!r})"


# 4. Create tables
Base.metadata.create_all(engine)


# 5. Use the Session for all operations
with Session(engine) as session:

    # INSERT
    task1 = Task(title="Fix login bug", priority="high")
    task2 = Task(title="Write tests", priority="medium")
    session.add(task1)
    session.add(task2)
    session.add_all([
        Task(title="Update docs", priority="low"),
        Task(title="Deploy to staging", priority="high"),
    ])
    session.commit()

    print(f"Created task with ID: {task1.id}")    # ID populated after commit

    # SELECT — query API
    all_tasks = session.query(Task).all()
    high_tasks = session.query(Task).filter(Task.priority == "high").all()
    first = session.query(Task).filter(Task.id == 1).first()    # None if not found

    # Ordering and limiting
    top_tasks = (session.query(Task)
                 .filter(Task.done == False)
                 .order_by(Task.created_at.desc())
                 .limit(10)
                 .all())

    # UPDATE — modify the object, commit
    task = session.query(Task).filter(Task.id == 1).first()
    if task:
        task.done = True
        session.commit()

    # DELETE
    task = session.query(Task).filter(Task.id == 2).first()
    if task:
        session.delete(task)
        session.commit()

    # Count
    pending_count = session.query(Task).filter(Task.done == False).count()
    print(f"Pending: {pending_count}")
```

**SQLAlchemy 2.0 style — the modern way:**

```python
from sqlalchemy import select, update, delete

with Session(engine) as session:
    # SELECT
    stmt = select(Task).where(Task.priority == "high").order_by(Task.id)
    tasks = session.execute(stmt).scalars().all()

    # UPDATE
    stmt = update(Task).where(Task.id == 1).values(done=True)
    session.execute(stmt)

    # DELETE
    stmt = delete(Task).where(Task.done == True)
    session.execute(stmt)

    session.commit()
```

---

## When to use what

```
sqlite3 (raw)       → scripts, simple storage, learning SQL,
                      when you want full control

SQLAlchemy ORM      → applications, when you want Python objects,
                      when you might switch databases later

SQLAlchemy Core     → performance-critical code, complex queries,
                      somewhere between raw SQL and ORM

PostgreSQL/MySQL    → production, multiple concurrent writers,
                      large datasets, need advanced features
                      (use with SQLAlchemy or psycopg2/pymysql)
```

---

## The mental model to carry forward

A database is not just persistent storage — it's a query engine. The difference between JSON and SQLite is not just durability, it's the ability to ask questions about your data efficiently.

**Three things SQL gives you that files don't:**

- Queries — ask questions without loading everything
- Indexes — find records in O(log n) instead of O(n)
- Transactions — multiple operations that succeed or fail together

**The two rules that prevent most database bugs:**

- Always use parameterized queries — never string-format SQL
- Always use transactions for operations that must succeed together

SQLite is underused. It handles hundreds of thousands of rows without breaking a sweat, supports concurrent reads, and has zero setup. For any application that doesn't need concurrent writes from multiple machines, SQLite is the right default.

---

Day 21 is the Week 3 project — a weather CLI that hits a real API, uses async for concurrent requests, stores history in SQLite, and has a full test suite. Ready when you are.

[[Intermediate Power]]