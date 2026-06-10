
[[Advanced]]
## Why types in a dynamic language

Python doesn't enforce types at runtime — you've known this since Day 2. Type hints are annotations that:

- Tell other developers (and your future self) what a function expects and returns
- Enable static analysis tools to catch bugs before you run the code
- Power IDE autocomplete and inline documentation
- Are completely optional and have zero runtime cost by default

```python
# Without type hints — what does this accept? what does it return?
def process(data, config, mode):
    ...

# With type hints — self-documenting
def process(data: list[dict], config: dict[str, str], mode: str) -> list[str]:
    ...
```

The second version tells you everything without reading the implementation.

---

## Basic type hints

```python
# Variables
name: str = "Alice"
age: int = 30
score: float = 95.5
active: bool = True

# Functions — parameters and return type
def greet(name: str) -> str:
    return f"Hello, {name}"

def add(x: int, y: int) -> int:
    return x + y

def process(items: list) -> None:    # None return = no return value
    for item in items:
        print(item)

# No return type annotation = unknown, not None
# Explicit None annotation means "this intentionally returns nothing"
```

---

## The `typing` module — and modern alternatives

Python 3.9+ allows built-in types as generics directly. Before 3.9, you needed `typing`.

```python
# Python 3.9+ — use built-in types directly
def get_names(users: list[dict[str, str]]) -> list[str]:
    return [u["name"] for u in users]

scores: dict[str, int] = {"Alice": 95, "Bob": 87}
coords: tuple[float, float] = (51.5, -0.12)
unique_ids: set[int] = {1, 2, 3}

# Python 3.8 and below — use typing module
from typing import List, Dict, Tuple, Set
def get_names(users: List[Dict[str, str]]) -> List[str]: ...
```

**Optional and Union:**

```python
from typing import Optional, Union

# Optional[X] means the value can be X or None
# Optional[str] is equivalent to str | None
def find_user(user_id: int) -> Optional[dict]:
    ...    # returns dict or None

# Python 3.10+ — use | syntax directly
def find_user(user_id: int) -> dict | None:
    ...

# Union — multiple possible types
def process_id(id: Union[int, str]) -> str:
    return str(id)

# Python 3.10+
def process_id(id: int | str) -> str:
    return str(id)
```

**Any — the escape hatch:**

```python
from typing import Any

def log(data: Any) -> None:    # accepts literally anything
    print(data)

# Use sparingly — it turns off type checking for that value
```

---

## Type aliases — naming complex types

```python
from typing import TypeAlias

# Give complex types a name
TaskDict: TypeAlias = dict[str, str | int | bool]
UserList: TypeAlias = list[dict[str, str]]
Callback: TypeAlias = callable[[int, str], bool]

def process_tasks(tasks: list[TaskDict]) -> None:
    ...

# More readable than:
def process_tasks(tasks: list[dict[str, str | int | bool]]) -> None:
    ...
```

---

## Callable, Generator, Iterator

```python
from typing import Callable, Generator, Iterator

# Callable[[arg_types], return_type]
def apply(func: Callable[[int], int], value: int) -> int:
    return func(value)

# Callback with no args
def run_later(callback: Callable[[], None]) -> None:
    callback()

# Generator[yield_type, send_type, return_type]
def count_up(n: int) -> Generator[int, None, None]:
    for i in range(n):
        yield i

# Iterator
def get_items() -> Iterator[str]:
    yield "a"
    yield "b"
```

---

## Dataclasses in depth — `__post_init__`, field(), ClassVar

You saw dataclasses on Day 13. Here's everything they can do:

```python
from dataclasses import dataclass, field, InitVar, KW_ONLY
from typing import ClassVar
from datetime import datetime


@dataclass
class Task:
    # ClassVar — shared across all instances, not an instance field
    # Does NOT appear in __init__ or __repr__
    VALID_PRIORITIES: ClassVar[set[str]] = {"low", "medium", "high"}
    _count: ClassVar[int] = 0

    # Regular fields — order matters: fields without defaults before those with
    id: int
    title: str

    # Fields with defaults
    priority: str = "medium"
    done: bool = False

    # field() — fine-grained control
    tags: list[str] = field(default_factory=list)   # mutable default
    notes: str | None = field(default=None, repr=False)   # hide from repr
    created_at: datetime = field(
        default_factory=datetime.now,
        compare=False,      # exclude from __eq__
        hash=False,         # exclude from __hash__
    )

    # InitVar — passed to __init__ but NOT stored as an attribute
    # Useful for values only needed during initialization
    validate: InitVar[bool] = True

    def __post_init__(self, validate: bool):
        """Runs after __init__. validate comes from InitVar."""
        Task._count += 1

        if validate:
            if not self.title or not self.title.strip():
                raise ValueError("Title cannot be empty")
            if self.priority not in self.VALID_PRIORITIES:
                raise ValueError(f"Invalid priority: {self.priority!r}")
            self.title = self.title.strip()

    @classmethod
    def get_count(cls) -> int:
        return cls._count

    def complete(self) -> None:
        self.done = True


# Usage
t1 = Task(id=1, title="  Fix bug  ", priority="high")
print(t1.title)        # Fix bug — stripped in __post_init__
print(t1.created_at)   # datetime object — not shown in repr
print(repr(t1))        # Task(id=1, title='Fix bug', priority='high', done=False, tags=[])

t2 = Task(id=2, title="Write tests", validate=False)  # skip validation
print(Task.get_count())    # 2
```

**`KW_ONLY` — force keyword arguments:**

```python
from dataclasses import dataclass, KW_ONLY

@dataclass
class Config:
    host: str
    port: int
    _: KW_ONLY          # everything after this must be keyword-only
    debug: bool = False
    timeout: int = 30

Config("localhost", 8080)                     # works
Config("localhost", 8080, debug=True)         # works
Config("localhost", 8080, True)               # TypeError — debug must be keyword
```

---

## Protocols — structural typing

A `Protocol` defines what methods an object must have — without inheritance. If it walks like a duck and quacks like a duck, it is a duck.

```python
from typing import Protocol, runtime_checkable


@runtime_checkable
class Saveable(Protocol):
    """Any object with a save() method satisfies this protocol."""
    def save(self) -> None: ...


@runtime_checkable
class Loadable(Protocol):
    def load(self) -> dict: ...


class JSONStorage:
    def save(self) -> None:
        print("Saving to JSON")

    def load(self) -> dict:
        return {}


class SQLiteStorage:
    def save(self) -> None:
        print("Saving to SQLite")

    def load(self) -> dict:
        return {}


# Both satisfy Saveable without inheriting from it
def backup(storage: Saveable) -> None:
    storage.save()

backup(JSONStorage())     # works
backup(SQLiteStorage())   # works

# runtime_checkable lets you use isinstance
print(isinstance(JSONStorage(), Saveable))    # True
```

Protocols are how Python does duck typing with type safety. Instead of `class JSONStorage(Saveable)`, you just implement the right methods. The type checker verifies the contract without requiring inheritance.

---

## TypedDict — typed dictionaries

```python
from typing import TypedDict, Required, NotRequired


class UserDict(TypedDict):
    id: int
    name: str
    email: str
    age: NotRequired[int]    # optional field


class TaskDict(TypedDict, total=False):
    # total=False makes all fields optional by default
    id: int
    title: str
    priority: str
    done: bool


def process_user(user: UserDict) -> str:
    return f"{user['name']} ({user['email']})"

# Type checker knows the structure
user: UserDict = {"id": 1, "name": "Alice", "email": "alice@example.com"}
process_user(user)    # valid

# Type checker catches this:
user: UserDict = {"id": 1, "name": "Alice"}    # missing 'email'
```

`TypedDict` is for when you're working with dicts that have a known structure — API responses, config objects, database rows. It's lighter than a dataclass and works with existing dict-based code.

---

## Pydantic — runtime validation

Dataclasses validate in `__post_init__` — you write the validation. Pydantic validates automatically, with rich error messages, and can parse data from JSON/dicts.

```bash
pip install pydantic
```

```python
from pydantic import BaseModel, Field, field_validator, model_validator
from typing import Optional
from datetime import datetime


class Task(BaseModel):
    id: int
    title: str = Field(min_length=1, max_length=200)
    priority: str = Field(default="medium")
    done: bool = False
    tags: list[str] = Field(default_factory=list)
    created_at: datetime = Field(default_factory=datetime.now)
    notes: Optional[str] = None

    # Field-level validator
    @field_validator("priority")
    @classmethod
    def validate_priority(cls, v: str) -> str:
        valid = {"low", "medium", "high"}
        if v not in valid:
            raise ValueError(f"Must be one of {valid}, got {v!r}")
        return v

    @field_validator("title")
    @classmethod
    def strip_title(cls, v: str) -> str:
        return v.strip()

    # Model-level validator — runs after all fields are validated
    @model_validator(mode="after")
    def check_done_has_no_notes(self) -> "Task":
        if self.done and self.notes:
            # just a warning, not an error
            pass
        return self


# Creating from keyword arguments
task = Task(id=1, title="  Fix bug  ", priority="high")
print(task.title)       # Fix bug — stripped
print(task.priority)    # high

# Pydantic gives rich errors
try:
    bad = Task(id=1, title="", priority="urgent")
except Exception as e:
    print(e)
# 2 validation errors for Task
# title: String should have at least 1 character [...]
# priority: Value error, Must be one of {'low', 'medium', 'high'}, got 'urgent'

# Parse from dict — automatic type coercion
task = Task.model_validate({
    "id": "1",          # string "1" coerced to int 1
    "title": "Fix bug",
    "priority": "high",
    "done": "false",    # string "false" coerced to bool False
})
print(task.id)       # 1 (int)
print(task.done)     # False (bool)

# Serialize to dict or JSON
print(task.model_dump())
print(task.model_dump_json())
```

**Pydantic for API request/response models:**

```python
from pydantic import BaseModel, EmailStr, HttpUrl
from typing import Optional


class CreateUserRequest(BaseModel):
    name: str = Field(min_length=1, max_length=100)
    email: str
    age: int = Field(ge=0, le=150)    # ge=greater-than-or-equal, le=less-than-or-equal
    website: Optional[str] = None


class UserResponse(BaseModel):
    id: int
    name: str
    email: str
    created_at: datetime

    model_config = {"from_attributes": True}    # allows creating from ORM objects


# In a FastAPI endpoint (Day 26 preview):
# @app.post("/users", response_model=UserResponse)
# async def create_user(request: CreateUserRequest):
#     user = db.create(request.model_dump())
#     return UserResponse.model_validate(user)
```

**Nested models:**

```python
from pydantic import BaseModel


class Address(BaseModel):
    street: str
    city: str
    country: str
    zip_code: str


class Company(BaseModel):
    name: str
    website: Optional[str] = None


class User(BaseModel):
    id: int
    name: str
    address: Address         # nested model
    company: Optional[Company] = None
    tags: list[str] = []


# Pydantic handles nested validation
user = User.model_validate({
    "id": 1,
    "name": "Alice",
    "address": {
        "street": "123 Main St",
        "city": "London",
        "country": "GB",
        "zip_code": "SW1A 1AA"
    }
})

print(user.address.city)    # London
print(user.address.country) # GB
```

---

## mypy — static type checking

```bash
pip install mypy
mypy your_file.py
mypy weather_cli/        # check entire package
```

```python
# example.py
def greet(name: str) -> str:
    return f"Hello, {name}"

result = greet(42)              # mypy error: Argument 1 has incompatible type "int"; expected "str"
result.upper()
result + 1                      # mypy error: Unsupported left operand type for + ("str")

def get_user(id: int) -> dict | None:
    if id == 1:
        return {"name": "Alice"}
    return None

user = get_user(1)
print(user["name"])             # mypy error: Item "None" of "dict | None" has no attribute "__getitem__"

# Fix:
user = get_user(1)
if user is not None:
    print(user["name"])         # now mypy is happy
```

mypy catches an entire class of bugs — None dereferences, wrong argument types, missing fields — before you run the code. In a team codebase, it's essential.

**mypy config in `pyproject.toml`:**

```toml
[tool.mypy]
python_version = "3.11"
strict = false
ignore_missing_imports = true
disallow_untyped_defs = true    # all functions must have type hints
warn_return_any = true
warn_unused_ignores = true
```

---

## Choosing between dataclass, TypedDict, and Pydantic

```
What do you need?

Plain data container, no validation needed?
→ @dataclass

Dict-shaped data with known structure, working with existing dict code?
→ TypedDict

Data that comes from outside your program (user input, API, JSON)?
→ Pydantic — validation and coercion are automatic

Immutable value object?
→ @dataclass(frozen=True)

FastAPI request/response models?
→ Pydantic — it's what FastAPI is built on

Performance-critical, millions of instances?
→ @dataclass with __slots__
```

---

## Putting it together — typed task manager core

```python
from dataclasses import dataclass, field
from typing import Protocol, TypeAlias
from datetime import datetime
from pydantic import BaseModel, Field, field_validator


# Type aliases make the code self-documenting
TaskId: TypeAlias = int
Priority: TypeAlias = str


# Protocol defines what storage backends must implement
class StorageBackend(Protocol):
    def save(self, tasks: list[dict], next_id: int) -> None: ...
    def load(self) -> tuple[list[dict], int]: ...


# Pydantic model for input validation — when data comes from outside
class CreateTaskInput(BaseModel):
    title: str = Field(min_length=1, max_length=200)
    priority: Priority = "medium"
    notes: str | None = None

    @field_validator("title")
    @classmethod
    def strip_title(cls, v: str) -> str:
        return v.strip()

    @field_validator("priority")
    @classmethod
    def validate_priority(cls, v: str) -> str:
        if v not in {"low", "medium", "high"}:
            raise ValueError(f"Invalid priority: {v!r}")
        return v


# Dataclass for the internal domain object — fast, no validation overhead
@dataclass
class Task:
    id: TaskId
    title: str
    priority: Priority = "medium"
    done: bool = False
    notes: str | None = None
    created_at: datetime = field(default_factory=datetime.now)

    def complete(self) -> None:
        self.done = True

    def to_dict(self) -> dict:
        return {
            "id": self.id,
            "title": self.title,
            "priority": self.priority,
            "done": self.done,
            "notes": self.notes,
            "created_at": self.created_at.isoformat(),
        }


# Typed function signatures — the contract is explicit
def create_task(
    storage: StorageBackend,
    input_data: CreateTaskInput,
    next_id: TaskId,
) -> Task:
    task = Task(
        id=next_id,
        title=input_data.title,
        priority=input_data.priority,
        notes=input_data.notes,
    )
    tasks, _ = storage.load()
    tasks.append(task.to_dict())
    storage.save(tasks, next_id + 1)
    return task
```

---

## The mental model to carry forward

Type hints are documentation that tools can verify. They don't change how Python runs your code — they change how you and your tools understand it.

**The layered approach used in production:**

- `TypedDict` for structured dicts you don't own (API responses, database rows)
- `@dataclass` for internal domain objects where you control creation
- `Pydantic` for the boundary between your code and the outside world (user input, HTTP requests, config files)
- `Protocol` for defining interfaces without inheritance

**The workflow:**

- Add type hints as you write — not as an afterthought
- Run `mypy` in CI so type errors block merges
- Use Pydantic for anything that enters your system from outside
- Use dataclasses for things that live entirely inside your system

---

Day 23 is design patterns — the practical ones that actually appear in real Python codebases. Not the full Gang of Four catalog, just the ones you'll use and encounter. Ready when you are.