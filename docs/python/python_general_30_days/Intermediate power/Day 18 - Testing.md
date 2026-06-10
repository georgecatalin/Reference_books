#### Writing Code You Can Trust

## Why testing is not optional

Every developer tests their code. The question is whether you do it manually — running the program, clicking around, checking outputs — or automatically, with tests that run in seconds and tell you exactly what broke.

Manual testing:

- Takes minutes every time
- Misses edge cases you forgot to check
- Gives no safety net when you change code
- Scales to zero as the codebase grows

Automated tests:

- Run in seconds
- Check the same cases every time
- Tell you immediately when a change breaks something
- Are documentation that never goes stale

---

## pytest — the standard

```bash
pip install pytest
```

pytest finds and runs test files automatically. Its rules:

- Test files: `test_*.py` or `*_test.py`
- Test functions: `def test_*()`
- Test classes: `class Test*`

```python
# test_math.py

def add(x, y):
    return x + y

def test_add_positive_numbers():
    assert add(2, 3) == 5

def test_add_negative_numbers():
    assert add(-1, -1) == -2

def test_add_zero():
    assert add(5, 0) == 5
```

```bash
pytest test_math.py          # run one file
pytest                       # run all tests in current directory
pytest -v                    # verbose — shows each test name
pytest -v test_math.py       # verbose for one file
pytest -k "add"              # run only tests with "add" in the name
pytest --tb=short            # shorter traceback on failure
```

pytest output on failure:

```
FAILED test_math.py::test_add_positive_numbers
AssertionError: assert 4 == 5
  where 4 = add(2, 2)
```

pytest rewrites `assert` statements to show you what both sides evaluated to. No need for `assertEqual(a, b)` like in `unittest` — plain `assert a == b` gives you full information.

---

## What to test — the anatomy of a good test

```python
# The AAA pattern — Arrange, Act, Assert
def test_task_creation():
    # Arrange — set up the inputs
    title = "Fix login bug"
    priority = "high"

    # Act — do the thing
    task = Task(id=1, title=title, priority=priority)

    # Assert — verify the outcome
    assert task.title == "Fix login bug"
    assert task.priority == "high"
    assert task.done == False
    assert task.id == 1
```

Each test should:

- Test one behavior — not one function, one behavior
- Have a name that reads like a sentence: `test_task_is_marked_done_when_completed`
- Be independent — not rely on other tests running first
- Be fast — no sleep, no real network calls, no real file system if possible

---

## Testing exceptions

```python
import pytest
from task_manager.models import Task
from task_manager.exceptions import InvalidPriorityError, InvalidTaskError

def test_task_raises_on_empty_title():
    with pytest.raises(InvalidTaskError):
        Task(id=1, title="")

def test_task_raises_on_invalid_priority():
    with pytest.raises(InvalidPriorityError):
        Task(id=1, title="Fix bug", priority="urgent")

def test_task_raises_with_correct_message():
    with pytest.raises(InvalidPriorityError, match="urgent"):
        Task(id=1, title="Fix bug", priority="urgent")

# match is a regex — checks that the error message contains the pattern
def test_task_raises_with_specific_priority_in_message():
    with pytest.raises(InvalidPriorityError) as exc_info:
        Task(id=1, title="Fix bug", priority="critical")

    assert exc_info.value.priority == "critical"    # access the exception object
    assert "critical" in str(exc_info.value)
```

---

## Fixtures — shared setup without repetition

A fixture is a function that provides test inputs. pytest injects them automatically by name.

```python
import pytest
from task_manager.models import Task
from task_manager.manager import TaskManager


@pytest.fixture
def sample_task():
    """A valid task for use in tests."""
    return Task(id=1, title="Fix login bug", priority="high")


@pytest.fixture
def manager(tmp_path):
    """A TaskManager using a temporary directory."""
    # tmp_path is a built-in pytest fixture — gives you a temp directory
    # that's automatically cleaned up after each test
    filepath = tmp_path / "test_tasks.json"
    return TaskManager(filepath=str(filepath))


# Fixtures are injected by matching parameter names
def test_task_complete(sample_task):
    assert sample_task.done == False
    sample_task.complete()
    assert sample_task.done == True


def test_manager_add_task(manager):
    task = manager.add("Write tests", "high")
    assert task.title == "Write tests"
    assert task.priority == "high"
    assert task.id == 1


def test_manager_add_increments_id(manager):
    task1 = manager.add("First task")
    task2 = manager.add("Second task")
    assert task2.id == task1.id + 1
```

**Fixture scope — how long a fixture lives:**

```python
@pytest.fixture(scope="function")    # default — new instance per test
def manager(tmp_path):
    return TaskManager(tmp_path / "tasks.json")


@pytest.fixture(scope="module")     # one instance for the whole test file
def api_client():
    client = APIClient("https://httpbin.org")
    yield client
    client.close()


@pytest.fixture(scope="session")    # one instance for the entire test run
def db_connection():
    conn = create_connection()
    yield conn
    conn.close()
```

Use `function` scope (the default) for anything with state that could leak between tests. Use `module` or `session` for expensive setup like database connections or network clients.

**Fixtures with cleanup using yield:**

```python
@pytest.fixture
def temp_file(tmp_path):
    """Create a temp file and clean it up after the test."""
    filepath = tmp_path / "test.json"
    filepath.write_text('{"tasks": [], "next_id": 1}')
    yield filepath                  # test runs here
    # cleanup runs after the test, even if it fails
    if filepath.exists():
        filepath.unlink()


@pytest.fixture
def manager(tmp_path):
    m = TaskManager(tmp_path / "tasks.json")
    yield m
    # nothing to clean up — tmp_path handles it
```

---

## Parametrize — testing multiple inputs cleanly

```python
import pytest
from task_manager.models import Task, VALID_PRIORITIES


@pytest.mark.parametrize("priority", ["low", "medium", "high"])
def test_valid_priorities_accepted(priority):
    task = Task(id=1, title="Test", priority=priority)
    assert task.priority == priority


@pytest.mark.parametrize("invalid_priority", [
    "urgent", "critical", "HIGH", "Low", "", "1", None
])
def test_invalid_priorities_rejected(invalid_priority):
    from task_manager.exceptions import InvalidPriorityError
    with pytest.raises((InvalidPriorityError, Exception)):
        Task(id=1, title="Test", priority=invalid_priority)


@pytest.mark.parametrize("title,expected", [
    ("Fix bug", "Fix bug"),
    ("  Fix bug  ", "Fix bug"),       # strips whitespace
    ("FIX BUG", "FIX BUG"),          # preserves case
])
def test_title_normalization(title, expected):
    task = Task(id=1, title=title)
    assert task.title == expected


# Parametrize with IDs — makes output readable
@pytest.mark.parametrize("a,b,expected", [
    (1, 2, 3),
    (0, 0, 0),
    (-1, 1, 0),
    (100, -50, 50),
], ids=["positive", "zeros", "cancel_out", "large"])
def test_addition(a, b, expected):
    assert a + b == expected
```

One parametrized test generates N separate test cases. Each runs independently, each shows up separately in output. This is cleaner than a loop inside a test.

---

## Mocking — isolating code from the outside world

Tests should be fast and deterministic. Real network calls, real file systems, real clocks — these make tests slow, flaky, and environment-dependent. Mocking replaces them with controlled fakes.

```python
from unittest.mock import Mock, MagicMock, patch, call
import pytest


# Mock — a fake object that records what you do to it
def test_mock_basics():
    mock = Mock()

    mock.some_method(1, 2)
    mock.some_method(3, 4)

    mock.some_method.assert_called_once_with(1, 2)   # fails — called twice
    mock.some_method.assert_called_with(3, 4)         # passes — last call
    assert mock.some_method.call_count == 2

    # Configure return values
    mock.get_user.return_value = {"id": 1, "name": "Alice"}
    result = mock.get_user(1)
    assert result["name"] == "Alice"


# patch — replace a real object with a mock during a test
def get_current_time():
    from datetime import datetime
    return datetime.now().strftime("%H:%M")

def test_get_current_time():
    with patch("datetime.datetime") as mock_dt:
        mock_dt.now.return_value.strftime.return_value = "14:30"
        result = get_current_time()
        assert result == "14:30"


# Patching requests — the most common real-world use case
import requests

def fetch_user(user_id):
    response = requests.get(f"https://api.example.com/users/{user_id}")
    response.raise_for_status()
    return response.json()

def test_fetch_user_success():
    with patch("requests.get") as mock_get:
        # Configure the mock response
        mock_response = Mock()
        mock_response.status_code = 200
        mock_response.json.return_value = {"id": 1, "name": "Alice"}
        mock_response.raise_for_status.return_value = None
        mock_get.return_value = mock_response

        user = fetch_user(1)

        assert user["name"] == "Alice"
        mock_get.assert_called_once_with("https://api.example.com/users/1")

def test_fetch_user_not_found():
    with patch("requests.get") as mock_get:
        mock_response = Mock()
        mock_response.raise_for_status.side_effect = requests.HTTPError(
            response=Mock(status_code=404)
        )
        mock_get.return_value = mock_response

        with pytest.raises(requests.HTTPError):
            fetch_user(999)
```

**patch as a decorator — cleaner for multiple tests:**

```python
from unittest.mock import patch, Mock

class TestAPIClient:

    @patch("requests.Session")
    def test_session_created_with_auth(self, mock_session_class):
        mock_session = Mock()
        mock_session_class.return_value = mock_session

        client = APIClient("https://api.example.com", api_key="test_key")

        mock_session.headers.update.assert_called()
        call_args = mock_session.headers.update.call_args_list
        auth_header_set = any(
            "Authorization" in str(c) for c in call_args
        )
        assert auth_header_set
```

---

## Testing the task manager — a complete test suite

```python
# tests/test_manager.py

import pytest
import json
from task_manager.manager import TaskManager
from task_manager.models import Task
from task_manager.exceptions import (
    TaskNotFoundError,
    InvalidPriorityError,
    InvalidTaskError,
)


@pytest.fixture
def manager(tmp_path):
    return TaskManager(filepath=str(tmp_path / "tasks.json"))


@pytest.fixture
def populated_manager(manager):
    """Manager pre-loaded with sample tasks."""
    manager.add("High priority task", "high")
    manager.add("Medium priority task", "medium")
    manager.add("Low priority task", "low")
    manager.add("Another high task", "high")
    return manager


class TestAddTask:

    def test_add_returns_task(self, manager):
        task = manager.add("Write tests")
        assert isinstance(task, Task)

    def test_add_sets_title(self, manager):
        task = manager.add("Write tests")
        assert task.title == "Write tests"

    def test_add_default_priority_is_medium(self, manager):
        task = manager.add("Write tests")
        assert task.priority == "medium"

    def test_add_custom_priority(self, manager):
        task = manager.add("Urgent fix", priority="high")
        assert task.priority == "high"

    def test_add_increments_id(self, manager):
        t1 = manager.add("First")
        t2 = manager.add("Second")
        t3 = manager.add("Third")
        assert t1.id == 1
        assert t2.id == 2
        assert t3.id == 3

    def test_add_strips_title_whitespace(self, manager):
        task = manager.add("  Fix bug  ")
        assert task.title == "Fix bug"

    def test_add_empty_title_raises(self, manager):
        with pytest.raises(InvalidTaskError):
            manager.add("")

    def test_add_whitespace_title_raises(self, manager):
        with pytest.raises(InvalidTaskError):
            manager.add("   ")

    def test_add_invalid_priority_raises(self, manager):
        with pytest.raises(InvalidPriorityError):
            manager.add("Task", priority="urgent")

    @pytest.mark.parametrize("priority", ["low", "medium", "high"])
    def test_add_all_valid_priorities(self, manager, priority):
        task = manager.add("Test task", priority=priority)
        assert task.priority == priority


class TestCompleteTask:

    def test_complete_marks_done(self, manager):
        task = manager.add("Fix bug")
        manager.complete(task.id)
        updated = manager.get(task.id)
        assert updated.done == True

    def test_complete_returns_task(self, manager):
        task = manager.add("Fix bug")
        result = manager.complete(task.id)
        assert result.id == task.id

    def test_complete_nonexistent_raises(self, manager):
        with pytest.raises(TaskNotFoundError) as exc_info:
            manager.complete(999)
        assert exc_info.value.task_id == 999


class TestDeleteTask:

    def test_delete_removes_task(self, manager):
        task = manager.add("To delete")
        manager.delete(task.id)
        with pytest.raises(TaskNotFoundError):
            manager.get(task.id)

    def test_delete_returns_deleted_task(self, manager):
        task = manager.add("To delete")
        deleted = manager.delete(task.id)
        assert deleted.title == "To delete"

    def test_delete_nonexistent_raises(self, manager):
        with pytest.raises(TaskNotFoundError):
            manager.delete(999)

    def test_delete_does_not_affect_other_tasks(self, manager):
        t1 = manager.add("Keep this")
        t2 = manager.add("Delete this")
        t3 = manager.add("Keep this too")
        manager.delete(t2.id)
        assert manager.get(t1.id).title == "Keep this"
        assert manager.get(t3.id).title == "Keep this too"


class TestListTasks:

    def test_list_returns_all(self, populated_manager):
        tasks = populated_manager.list()
        assert len(tasks) == 4

    def test_list_sorted_by_priority(self, populated_manager):
        tasks = populated_manager.list()
        priorities = [t.priority for t in tasks]
        high_indices = [i for i, p in enumerate(priorities) if p == "high"]
        low_indices = [i for i, p in enumerate(priorities) if p == "low"]
        assert max(high_indices) < min(low_indices)

    def test_list_filter_done(self, populated_manager):
        task = populated_manager.list()[0]
        populated_manager.complete(task.id)
        done = populated_manager.list(status="done")
        assert len(done) == 1
        assert all(t.done for t in done)

    def test_list_filter_pending(self, populated_manager):
        task = populated_manager.list()[0]
        populated_manager.complete(task.id)
        pending = populated_manager.list(status="pending")
        assert len(pending) == 3
        assert all(not t.done for t in pending)

    def test_list_filter_by_priority(self, populated_manager):
        high_tasks = populated_manager.list(priority="high")
        assert len(high_tasks) == 2
        assert all(t.priority == "high" for t in high_tasks)

    def test_list_search(self, populated_manager):
        results = populated_manager.list(search="high")
        assert len(results) == 2

    def test_list_empty_manager_returns_empty(self, manager):
        assert manager.list() == []


class TestPersistence:

    def test_tasks_survive_restart(self, tmp_path):
        filepath = str(tmp_path / "tasks.json")

        # Create and populate manager
        m1 = TaskManager(filepath=filepath)
        m1.add("Survive restart", "high")
        m1.add("Me too")

        # Create new manager pointing to same file
        m2 = TaskManager(filepath=filepath)
        tasks = m2.list()

        assert len(tasks) == 2
        assert tasks[0].title in ["Survive restart", "Me too"]

    def test_completed_status_persists(self, tmp_path):
        filepath = str(tmp_path / "tasks.json")

        m1 = TaskManager(filepath=filepath)
        task = m1.add("Complete me")
        m1.complete(task.id)

        m2 = TaskManager(filepath=filepath)
        loaded_task = m2.get(task.id)
        assert loaded_task.done == True

    def test_next_id_persists(self, tmp_path):
        filepath = str(tmp_path / "tasks.json")

        m1 = TaskManager(filepath=filepath)
        m1.add("Task 1")
        m1.add("Task 2")

        m2 = TaskManager(filepath=filepath)
        new_task = m2.add("Task 3")
        assert new_task.id == 3    # not 1 — continues from where we left off


class TestSummary:

    def test_summary_counts(self, populated_manager):
        summary = populated_manager.summary
        assert summary["total"] == 4
        assert summary["pending"] == 4
        assert summary["done"] == 0

    def test_summary_updates_after_complete(self, populated_manager):
        task = populated_manager.list()[0]
        populated_manager.complete(task.id)
        summary = populated_manager.summary
        assert summary["done"] == 1
        assert summary["pending"] == 3

    def test_summary_empty_manager(self, manager):
        summary = manager.summary
        assert summary["total"] == 0
        assert summary["done"] == 0
        assert summary["pending"] == 0
```

Run it:

```bash
cd task_manager
pytest tests/ -v
```

---

## Test organization principles

**What makes a test suite good:**

```python
# Tests are documentation — names explain behavior
def test_complete_task_marks_it_as_done():           # clear
def test_thing():                                     # useless

# Test behavior, not implementation
def test_adding_task_increases_count():              # tests what matters
def test_tasks_dict_has_new_key():                   # tests internals — fragile

# One assertion per concept — not one assertion per test
def test_task_creation_sets_all_fields():
    task = Task(id=1, title="Test", priority="high")
    assert task.id == 1
    assert task.title == "Test"          # multiple assertions are fine
    assert task.priority == "high"       # when they test the same concept
    assert task.done == False
```

**Test coverage — what it means and what it doesn't:**

```bash
pip install pytest-cov
pytest --cov=task_manager --cov-report=term-missing
```

Coverage tells you which lines were executed during tests. 80%+ is a reasonable target. 100% is often not worth the effort — focus on covering critical paths, edge cases, and error conditions, not every getter.

Coverage is a floor, not a ceiling. 100% coverage with bad tests means nothing.

---

## The testing mindset

Good tests ask three questions about every function:

**1. Does it work with valid input?**

```python
def test_add_normal_case():
    assert add(2, 3) == 5
```

**2. Does it handle edge cases?**

```python
def test_add_zero():
    assert add(0, 0) == 0
def test_add_negative():
    assert add(-5, 5) == 0
```

**3. Does it fail correctly on invalid input?**

```python
def test_add_raises_on_string():
    with pytest.raises(TypeError):
        add("2", 3)
```

Write these three categories for every non-trivial function. The edge cases and failure cases are where bugs actually live.

---

## The mental model to carry forward

Tests are a safety net. They don't prove your code is correct — they prove it behaves the way you think it does. When you change code, they tell you immediately what broke.

**The workflow that actually works:**

- Write the function
- Write tests for the happy path
- Write tests for edge cases
- Write tests for failure cases
- Run them: `pytest -v`
- If a test fails, fix the code or fix the test — never delete the test

**The rule for legacy code:** before you change anything, write tests for the current behavior first. Then change. If the tests break, you know exactly what changed.

**pytest's built-in fixtures worth knowing:**

- `tmp_path` — temporary directory, cleaned up automatically
- `capsys` — capture stdout/stderr
- `monkeypatch` — patch environment variables, attributes, functions
- `caplog` — capture log output

Day 19 is concurrency — threading, multiprocessing, and async/await. When to use each, and how Python's GIL affects everything. Ready when you are.

[[Intermediate Power]]