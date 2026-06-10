### Modules, Packages, pip & Virtual Environments

## What a module is

A module is any `.py` file. That's it. When you write `import something`, Python finds a file called `something.py` and runs it. Everything defined in that file becomes accessible under the `something` namespace.

```python
# math_utils.py
PI = 3.14159

def circle_area(radius):
    return PI * radius ** 2

def circle_circumference(radius):
    return 2 * PI * radius
```

```python
# main.py
import math_utils

print(math_utils.PI)                      # 3.14159
print(math_utils.circle_area(5))          # 78.53975
print(math_utils.circle_circumference(5)) # 31.4159
```

Python runs `math_utils.py` once when first imported, caches it, and serves the cached version on subsequent imports. The file doesn't run again.

---

## Import styles — and when to use each

```python
# 1. Import the module — safest, most explicit
import math
print(math.sqrt(16))      # 4.0 — clear where sqrt comes from

# 2. Import specific names — clean for frequently used items
from math import sqrt, pi
print(sqrt(16))           # 4.0
print(pi)                 # 3.141592653589793

# 3. Import with alias — for long names or naming conflicts
import numpy as np                    # industry standard alias
import matplotlib.pyplot as plt       # industry standard alias
from datetime import datetime as dt

# 4. Import everything — avoid this
from math import *        # pollutes namespace, unclear where names come from
print(sqrt(16))           # where did sqrt come from? no idea without reading the import
```

**The rule:** use `import module` for standard library modules and packages. Use `from module import name` when you use `name` frequently and it's unambiguous. Never use `import *` in production code.

---

## How Python finds modules — the import system

When you write `import something`, Python searches in this order:

```python
import sys
print(sys.path)

# Output — a list of directories:
# ['', '/usr/lib/python311.zip', '/usr/lib/python3.11', ...]
# '' = current directory
# then standard library directories
# then site-packages (where pip installs things)
```

```python
# You can inspect where a module was loaded from
import math
print(math.__file__)    # /usr/lib/python3.11/lib-dynload/math.cpython-311-x86_64-linux-gnu.so

import json
print(json.__file__)    # /usr/lib/python3.11/json/__init__.py
```

Understanding this matters when your imports fail — Python is telling you it searched all those directories and didn't find what you asked for.

---

## The standard library — what's already there

Python ships with hundreds of modules. The ones you'll use constantly:

```python
# os — operating system interface
import os
print(os.getcwd())               # current working directory
print(os.environ.get("HOME"))    # environment variables
os.makedirs("data/logs", exist_ok=True)

# sys — interpreter internals
import sys
print(sys.argv)       # command-line arguments: ['script.py', 'arg1', 'arg2']
print(sys.version)    # Python version string
sys.exit(0)           # exit with code 0 (success), 1 (error)

# datetime — dates and times
from datetime import datetime, date, timedelta
now = datetime.now()
print(now)                              # 2024-01-15 14:30:00.123456
print(now.strftime("%Y-%m-%d %H:%M"))  # 2024-01-15 14:30
print(now.isoformat())                  # 2024-01-15T14:30:00.123456
today = date.today()
yesterday = today - timedelta(days=1)

# collections — specialized data structures
from collections import defaultdict, Counter, deque, OrderedDict
word_counts = Counter(["apple", "banana", "apple", "cherry", "apple"])
print(word_counts)              # Counter({'apple': 3, 'banana': 1, 'cherry': 1})
print(word_counts.most_common(2))  # [('apple', 3), ('banana', 1)]

# itertools — iterator building blocks
import itertools
for combo in itertools.combinations([1, 2, 3], 2):
    print(combo)    # (1,2), (1,3), (2,3)

# functools — higher-order functions
from functools import lru_cache
@lru_cache(maxsize=128)
def fibonacci(n):
    if n < 2:
        return n
    return fibonacci(n-1) + fibonacci(n-2)

# re — regular expressions
import re
email = "alice@example.com"
if re.match(r"^[\w.+-]+@[\w-]+\.[a-z]{2,}$", email):
    print("Valid email")

# random — random numbers
import random
print(random.randint(1, 10))         # random integer 1-10
print(random.choice(["a","b","c"]))  # random item from list
random.shuffle([1, 2, 3, 4, 5])     # shuffle in place

# json, pathlib, csv — already covered Day 8
```

Before installing any third-party package, check the standard library first. It probably already has what you need.

---

## Packages — modules organized into directories

A package is a directory with an `__init__.py` file. That file can be empty — its presence is what makes the directory a package.

```
my_project/
├── main.py
├── utils/
│   ├── __init__.py
│   ├── file_utils.py
│   └── string_utils.py
└── models/
    ├── __init__.py
    ├── user.py
    └── task.py
```

```python
# Importing from packages
from utils.file_utils import read_json
from utils import string_utils
from models.user import User
import models.task
```

**`__init__.py` controls what the package exposes:**

```python
# utils/__init__.py
from .file_utils import read_json, write_json    # relative import
from .string_utils import slugify, truncate

# Now callers can do:
from utils import read_json    # instead of from utils.file_utils import read_json
```

The `.` in `from .file_utils` is a relative import — "from this package." Use relative imports inside a package, absolute imports everywhere else.

---

## Virtual environments — the single most important tool for Python projects

A virtual environment is an isolated Python installation for a specific project. Without it, every package you install goes into a global location shared by all projects on your machine — which eventually causes version conflicts that are hell to debug.

**The rule: one virtual environment per project, always.**

```bash
# Create a virtual environment
python -m venv venv        # creates a folder called 'venv'

# Activate it
# Mac/Linux:
source venv/bin/activate

# Windows:
venv\Scripts\activate

# Your prompt changes to show (venv) — you're now isolated
(venv) $

# Deactivate
deactivate
```

**What activation actually does:**

```bash
# Before activation
which python    # /usr/bin/python  — system Python

# After activation
which python    # /path/to/project/venv/bin/python  — isolated Python

# pip now installs into venv/lib/python3.x/site-packages
# not into the system Python
```

---

## pip — installing packages

```bash
# Install a package
pip install requests

# Install a specific version
pip install requests==2.31.0

# Install minimum version
pip install requests>=2.28.0

# Upgrade a package
pip install --upgrade requests

# Uninstall
pip uninstall requests

# List installed packages
pip list

# Show details about a package
pip show requests

# Search (requires PyPI access)
pip install package-name
```

**requirements.txt — the contract for your dependencies:**

```bash
# Generate from current environment
pip freeze > requirements.txt

# Install from requirements.txt
pip install -r requirements.txt
```

```
# requirements.txt
requests==2.31.0
python-dotenv==1.0.0
pytest==7.4.0
```

Always commit `requirements.txt` to version control. Anyone cloning your project runs `pip install -r requirements.txt` and gets the exact same environment.

---

## pyproject.toml — the modern way

Modern Python projects use `pyproject.toml` instead of `requirements.txt`. You'll see both in the wild — `requirements.txt` for applications, `pyproject.toml` for libraries and serious projects.

```toml
# pyproject.toml
[project]
name = "task-manager"
version = "0.1.0"
requires-python = ">=3.11"
dependencies = [
    "requests>=2.28.0",
    "python-dotenv>=1.0.0",
]

[project.optional-dependencies]
dev = [
    "pytest>=7.0.0",
    "ruff>=0.1.0",
]
```

Day 25 covers packaging in full. For now, `requirements.txt` is fine for the projects in this course.

---

## Writing your own module — the right way

```python
# tasks.py — a module that can be imported OR run directly

from pathlib import Path
import json

TASKS_FILE = Path("tasks.json")

def load_tasks():
    if not TASKS_FILE.exists():
        return [], 1
    with open(TASKS_FILE, encoding="utf-8") as f:
        data = json.load(f)
    return data["tasks"], data["next_id"]

def save_tasks(tasks, next_id):
    with open(TASKS_FILE, "w", encoding="utf-8") as f:
        json.dump({"tasks": tasks, "next_id": next_id}, f, indent=2)

def add_task(tasks, next_id, title, priority="medium"):
    task = {"id": next_id, "title": title, "priority": priority, "done": False}
    tasks.append(task)
    return task, next_id + 1

# This block runs ONLY when the file is run directly
# NOT when it's imported as a module
if __name__ == "__main__":
    tasks, next_id = load_tasks()
    task, next_id = add_task(tasks, next_id, "Test task", "high")
    save_tasks(tasks, next_id)
    print(f"Added: {task}")
```

```python
# main.py — imports the module
from tasks import load_tasks, save_tasks, add_task

tasks, next_id = load_tasks()
# __main__ block in tasks.py does NOT run here
```

`if __name__ == "__main__"` is the standard pattern for making a file both importable as a module and runnable as a script. Every Python file you write that has runnable code should have this at the bottom.

---

## Project structure — how to lay out a real project

```
task_manager/
├── venv/                    # virtual environment — never commit this
├── task_manager/            # source package
│   ├── __init__.py
│   ├── cli.py               # command-line interface
│   ├── tasks.py             # task operations
│   ├── storage.py           # file I/O
│   └── exceptions.py        # custom exceptions
├── tests/                   # test files
│   ├── __init__.py
│   ├── test_tasks.py
│   └── test_storage.py
├── requirements.txt
├── README.md
└── main.py                  # entry point
```

```
# .gitignore — what to exclude from version control
venv/
__pycache__/
*.pyc
.env
*.json   # maybe — depends if data files should be committed
```

`__pycache__` is where Python stores compiled bytecode. It's generated automatically and should never be committed to version control.

---

## Environment variables — keeping secrets out of code

```python
# .env file — never commit this
# DATABASE_URL=postgresql://user:password@localhost/mydb
# API_KEY=sk-abc123
# DEBUG=true

# Load with python-dotenv
from dotenv import load_dotenv
import os

load_dotenv()    # reads .env file into environment variables

db_url = os.environ.get("DATABASE_URL")
api_key = os.environ.get("API_KEY")
debug = os.environ.get("DEBUG", "false").lower() == "true"
```

Install it:

```bash
pip install python-dotenv
```

Never hardcode API keys, passwords, or secrets in source code. Use environment variables. Add `.env` to `.gitignore` immediately.

---

## The mental model to carry forward

A Python project is a tree of modules organized into packages. The interpreter finds them by searching `sys.path`. Virtual environments isolate each project's dependencies so they don't interfere with each other.

**The workflow for every new project:**

```bash
mkdir my_project && cd my_project
python -m venv venv
source venv/bin/activate        # or venv\Scripts\activate on Windows
pip install <what you need>
pip freeze > requirements.txt
```

Do this every time, without exception. The 30 seconds it takes saves hours of dependency debugging later.

**Three things that separate professional Python projects from scripts:**

- Virtual environments — always
- `requirements.txt` or `pyproject.toml` — always
- `if __name__ == "__main__"` — in every runnable file

---

Day 11 is list comprehensions, dict comprehensions, lambda, map, and filter — the Python features that make data transformation clean and fast. Ready when you are.

[[Real Python]]