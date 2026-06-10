## Reading, Writing, and Working with the File System

## Why file I/O matters immediately

Every real program eventually needs to persist data. The task manager from Day 7 loses everything on exit. Today fixes that conceptually — and Day 14's project will wire it in fully.

---

## The open() function — the foundation of everything

```python
# open(filename, mode, encoding)
f = open("data.txt", "r", encoding="utf-8")
content = f.read()
f.close()    # you must close the file — releases the OS resource
```

This works but it's wrong in practice. If anything raises an exception between `open()` and `close()`, the file never gets closed — resource leak.

**Always use the `with` statement:**

```python
with open("data.txt", "r", encoding="utf-8") as f:
    content = f.read()
# file is automatically closed here, even if an exception occurs
```

The `with` statement is a context manager. It guarantees cleanup regardless of what happens inside the block. Use it every single time you open a file — no exceptions.

---

## File modes

```python
"r"   # read — default, file must exist
"w"   # write — creates file if missing, OVERWRITES if exists
"a"   # append — creates if missing, adds to end if exists
"x"   # exclusive create — fails if file already exists
"r+"  # read and write — file must exist
"b"   # binary mode — combine with others: "rb", "wb"
```

The mode you pick has consequences:

```python
# "w" destroys existing content — no warning, no confirmation
with open("important.txt", "w") as f:
    f.write("new content")    # everything that was there before is gone

# "a" is safe for adding without destroying
with open("log.txt", "a") as f:
    f.write("new log entry\n")

# "x" is the safe way to create — fails rather than overwrite
try:
    with open("config.txt", "x") as f:
        f.write("initial config")
except FileExistsError:
    print("Config already exists, not overwriting")
```

Always specify `encoding="utf-8"` explicitly. Without it, Python uses the system default encoding — which differs between Windows, Mac, and Linux. This causes bugs that only appear on certain machines.

---

## Reading files — four ways

```python
# Assume data.txt contains:
# Alice,30,London
# Bob,25,Manchester
# Charlie,35,Bristol

# 1. Read entire file as one string
with open("data.txt", "r", encoding="utf-8") as f:
    content = f.read()
print(content)       # entire file as a string

# 2. Read all lines into a list
with open("data.txt", "r", encoding="utf-8") as f:
    lines = f.readlines()
print(lines)         # ['Alice,30,London\n', 'Bob,25,Manchester\n', ...]

# 3. Read one line at a time
with open("data.txt", "r", encoding="utf-8") as f:
    line = f.readline()    # reads one line, moves cursor forward
    print(line)            # Alice,30,London\n

# 4. Iterate line by line — best for large files
with open("data.txt", "r", encoding="utf-8") as f:
    for line in f:         # f is iterable — most memory efficient
        print(line.strip())  # strip removes the trailing \n
```

**Which to use:**

- Small files that fit in memory: `read()` or `readlines()`
- Large files (logs, datasets): iterate line by line — never loads the whole file
- Processing line by line: iterate, always strip the line

---

## Writing files

```python
# Write a string
with open("output.txt", "w", encoding="utf-8") as f:
    f.write("Hello, World\n")    # write does NOT add newline automatically
    f.write("Second line\n")

# Write multiple lines at once
lines = ["Alice\n", "Bob\n", "Charlie\n"]
with open("output.txt", "w", encoding="utf-8") as f:
    f.writelines(lines)    # writes each item — still no newlines added automatically

# Cleaner approach with join
names = ["Alice", "Bob", "Charlie"]
with open("output.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(names))    # joins with newlines, write once

# Append — add without destroying
with open("log.txt", "a", encoding="utf-8") as f:
    f.write("2024-01-15: User logged in\n")
```

---

## Working with paths — use pathlib, not strings

Old Python used string concatenation for paths. Modern Python uses `pathlib` — use it for everything.

```python
from pathlib import Path

# Create path objects
p = Path("data/users.txt")
home = Path.home()              # current user's home directory
cwd = Path.cwd()                # current working directory

# Building paths — no string concatenation, no OS-specific separators
data_dir = Path("data")
file_path = data_dir / "users" / "alice.txt"    # / operator joins paths
print(file_path)    # data/users/alice.txt

# Path information
p = Path("data/reports/sales.csv")
print(p.name)       # sales.csv
print(p.stem)       # sales
print(p.suffix)     # .csv
print(p.parent)     # data/reports

# Check existence
print(p.exists())   # True/False
print(p.is_file())  # True if it's a file
print(p.is_dir())   # True if it's a directory

# Create directories
Path("data/reports").mkdir(parents=True, exist_ok=True)
# parents=True creates intermediate directories
# exist_ok=True doesn't fail if directory already exists

# List directory contents
for item in Path(".").iterdir():
    print(item)

# Find files matching a pattern
for csv_file in Path("data").glob("*.csv"):
    print(csv_file)

for py_file in Path(".").rglob("*.py"):    # recursive
    print(py_file)

# Read and write directly via pathlib
text = Path("data.txt").read_text(encoding="utf-8")
Path("output.txt").write_text("Hello", encoding="utf-8")
```

`pathlib` works on Windows, Mac, and Linux without changes. String paths break on Windows because of backslash vs forward slash differences.

---

## CSV files — structured text data

CSV is the most common format for moving data between systems.

```python
import csv

# Writing CSV
users = [
    ["Alice", 30, "London"],
    ["Bob", 25, "Manchester"],
    ["Charlie", 35, "Bristol"]
]

with open("users.csv", "w", newline="", encoding="utf-8") as f:
    writer = csv.writer(f)
    writer.writerow(["name", "age", "city"])    # header
    writer.writerows(users)                      # all rows at once

# Reading CSV
with open("users.csv", "r", encoding="utf-8") as f:
    reader = csv.reader(f)
    header = next(reader)    # skip the header row
    for row in reader:
        name, age, city = row
        print(f"{name} is {age} from {city}")

# DictReader/DictWriter — rows as dicts, much cleaner
with open("users.csv", "r", encoding="utf-8") as f:
    reader = csv.DictReader(f)    # uses first row as field names
    for row in reader:
        print(row["name"], row["age"])    # access by column name, not index

# Writing with DictWriter
users = [
    {"name": "Alice", "age": 30, "city": "London"},
    {"name": "Bob", "age": 25, "city": "Manchester"},
]

with open("users.csv", "w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=["name", "age", "city"])
    writer.writeheader()
    writer.writerows(users)
```

Always use `newline=""` when opening CSV files for writing — without it, Windows adds extra blank lines.

---

## JSON files — the format of the web

JSON is how APIs talk to each other and how config files are structured.

```python
import json

# Python dict → JSON file
data = {
    "users": [
        {"id": 1, "name": "Alice", "active": True},
        {"id": 2, "name": "Bob", "active": False}
    ],
    "total": 2
}

with open("data.json", "w", encoding="utf-8") as f:
    json.dump(data, f, indent=2)    # indent makes it human-readable

# JSON file → Python dict
with open("data.json", "r", encoding="utf-8") as f:
    loaded = json.load(f)

print(loaded["users"][0]["name"])    # Alice
print(type(loaded["users"]))         # <class 'list'>

# JSON type mapping
# JSON          Python
# object    →   dict
# array     →   list
# string    →   str
# number    →   int or float
# true/false→   True/False
# null      →   None

# String ↔ JSON (without files)
json_string = json.dumps(data, indent=2)    # dict → string
parsed = json.loads(json_string)            # string → dict
```

**Handling non-serializable types:**

```python
from datetime import datetime
import json

data = {"name": "Alice", "created_at": datetime.now()}

# This fails — datetime is not JSON serializable
json.dumps(data)    # TypeError

# Fix — convert to string before serializing
data["created_at"] = data["created_at"].isoformat()
json.dumps(data)    # works
```

---

## Error handling with files — what can go wrong

```python
def read_file_safe(filepath):
    """Read a file and return its contents, or None on failure."""
    try:
        with open(filepath, "r", encoding="utf-8") as f:
            return f.read()
    except FileNotFoundError:
        print(f"File not found: {filepath}")
        return None
    except PermissionError:
        print(f"Permission denied: {filepath}")
        return None
    except UnicodeDecodeError:
        print(f"File is not valid UTF-8: {filepath}")
        return None

# Checking before opening — sometimes cleaner
from pathlib import Path

def load_config(path):
    p = Path(path)
    if not p.exists():
        return {}    # return default, don't crash
    if not p.is_file():
        raise ValueError(f"{path} is a directory, not a file")
    return json.loads(p.read_text(encoding="utf-8"))
```

---

## Persisting the task manager — the preview

Here's exactly how Day 7's task manager will load and save with files. You don't need to wire this in yet — Day 14 does that — but see how clean it is:

```python
import json
from pathlib import Path

TASKS_FILE = Path("tasks.json")

def save_tasks(tasks, next_id):
    """Save tasks to disk."""
    data = {"tasks": tasks, "next_id": next_id}
    with open(TASKS_FILE, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2)

def load_tasks():
    """Load tasks from disk, return defaults if file missing."""
    if not TASKS_FILE.exists():
        return [], 1
    with open(TASKS_FILE, "r", encoding="utf-8") as f:
        data = json.load(f)
    return data["tasks"], data["next_id"]

# Usage
tasks, next_id = load_tasks()
# ... run the program ...
save_tasks(tasks, next_id)
```

That's it. The task manager from Day 7 needs exactly these two functions and two calls — one at startup, one at exit. Every task survives a restart.

---

## The mental model to carry forward

Files are a sequence of bytes on disk. When you open a file, the OS gives you a handle — a cursor into that sequence. Reading moves the cursor forward. Writing places bytes at the cursor position.

The `with` statement isn't optional style — it's the mechanism that guarantees the OS handle is released. Programs that open files without closing them leak resources, slow down, and eventually crash.

**Four things to always do:**

- Use `with` — always
- Specify `encoding="utf-8"` — always
- Use `pathlib.Path` for file paths — always
- Handle `FileNotFoundError` when the file might not exist — always

---

Day 9 is error handling — `try/except`, raising exceptions, and writing code that fails gracefully instead of crashing. Ready when you are.

[[Real Python]]