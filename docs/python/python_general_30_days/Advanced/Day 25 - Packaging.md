#### Building and Distributing Python Projects

## What packaging actually means

Packaging is the process of turning your code into something others can install with `pip install your-package`. It also means structuring your project so it works consistently across machines, Python versions, and environments.

Two distinct goals:

- **Application packaging** — deploying your app to a server or sharing it with your team
- **Library packaging** — publishing reusable code to PyPI so anyone can `pip install` it

Both use the same tools. The difference is in intent and what you include.

---

## The modern Python project structure

```
my_package/
├── src/
│   └── my_package/
│       ├── __init__.py
│       ├── core.py
│       ├── utils.py
│       └── exceptions.py
├── tests/
│   ├── __init__.py
│   ├── test_core.py
│   └── test_utils.py
├── docs/
│   └── index.md
├── pyproject.toml       # project metadata and build config — the only config file you need
├── README.md
├── LICENSE
└── .gitignore
```

The `src/` layout is the modern standard. It prevents your package from being accidentally imported from the project root during development — you always import the installed version, which is what users get.

---

## pyproject.toml — the single source of truth

`pyproject.toml` replaced `setup.py`, `setup.cfg`, and `requirements.txt` for packages. One file handles everything.

```toml
# pyproject.toml

[build-system]
requires = ["hatchling"]           # the build backend
build-backend = "hatchling.build"  # what builds your package


[project]
name = "task-manager-cli"          # the name on PyPI — must be unique
version = "0.1.0"
description = "A command-line task manager"
readme = "README.md"
license = {text = "MIT"}
requires-python = ">=3.11"

# Runtime dependencies — installed automatically with your package
dependencies = [
    "click>=8.0.0",
    "rich>=13.0.0",
    "pydantic>=2.0.0",
]

authors = [
    {name = "Your Name", email = "you@example.com"}
]

keywords = ["cli", "tasks", "productivity"]

classifiers = [
    "Development Status :: 3 - Alpha",
    "Intended Audience :: Developers",
    "License :: OSI Approved :: MIT License",
    "Programming Language :: Python :: 3",
    "Programming Language :: Python :: 3.11",
    "Programming Language :: Python :: 3.12",
]


[project.optional-dependencies]
# pip install task-manager-cli[dev]
dev = [
    "pytest>=7.0.0",
    "pytest-cov>=4.0.0",
    "mypy>=1.0.0",
    "ruff>=0.1.0",
]

# pip install task-manager-cli[docs]
docs = [
    "mkdocs>=1.5.0",
    "mkdocs-material>=9.0.0",
]


[project.urls]
Homepage = "https://github.com/yourname/task-manager-cli"
Repository = "https://github.com/yourname/task-manager-cli"
"Bug Tracker" = "https://github.com/yourname/task-manager-cli/issues"


# Entry points — creates command-line scripts when installed
[project.scripts]
task = "task_manager.cli:main"     # `task` command runs task_manager/cli.py:main()
task-manager = "task_manager.cli:main"


[tool.hatchling.build.targets.wheel]
packages = ["src/task_manager"]


# Tool configuration — all in one file
[tool.pytest.ini_options]
testpaths = ["tests"]
addopts = "-v --tb=short"


[tool.mypy]
python_version = "3.11"
strict = false
ignore_missing_imports = true
disallow_untyped_defs = true


[tool.ruff]
line-length = 88
target-version = "py311"

[tool.ruff.lint]
select = ["E", "F", "I", "N", "W"]    # error, pyflakes, isort, naming, warning
ignore = ["E501"]                       # ignore line length (handled by formatter)

[tool.ruff.format]
quote-style = "double"


[tool.coverage.run]
source = ["src"]
omit = ["tests/*"]

[tool.coverage.report]
show_missing = true
skip_covered = false
```

---

## Build backends — what actually builds your package

```
Build backend     Install         When to use
──────────────────────────────────────────────────────────────
hatchling         pip install hatch     Modern, fast, recommended
setuptools        pip install build     Legacy, most compatible
flit              pip install flit      Simple pure-Python packages
poetry            pip install poetry    All-in-one tool with lock files
pdm               pip install pdm       Modern, PEP 582, lock files
```

For new projects: use `hatchling` or `setuptools`. If your team uses `poetry`, use that — its lock files are valuable for applications.

---

## Building your package

```bash
# Install build tool
pip install build

# Build both wheel and source distribution
python -m build

# Output:
# dist/
#   task_manager_cli-0.1.0-py3-none-any.whl   ← wheel (binary distribution)
#   task_manager_cli-0.1.0.tar.gz              ← sdist (source distribution)
```

**Wheel vs sdist:**

- `.whl` (wheel) — pre-built, installs without building. Always publish this.
- `.tar.gz` (sdist) — source code. Needed if your package has C extensions or users build from source.

Pure Python packages: publish both. Always install from wheel when possible.

---

## Versioning — how to number your releases

Use semantic versioning: `MAJOR.MINOR.PATCH`

```
1.0.0  → initial stable release
1.0.1  → patch: bug fix, no new features, backward compatible
1.1.0  → minor: new features, backward compatible
2.0.0  → major: breaking changes

0.x.y  → pre-stable: anything can change
```

```toml
# In pyproject.toml — static version
[project]
version = "1.2.3"
```

```python
# Dynamic version from __init__.py
# pyproject.toml:
# [tool.hatchling.version]
# path = "src/task_manager/__init__.py"

# src/task_manager/__init__.py:
__version__ = "1.2.3"
```

```bash
# Programmatic version access after install
import task_manager
print(task_manager.__version__)

# Or via importlib (works without importing the package)
from importlib.metadata import version
print(version("task-manager-cli"))    # reads from package metadata
```

---

## Dependencies — pinning vs ranges

```toml
# Library (published to PyPI) — use ranges, be permissive
# Let users' applications control exact versions
dependencies = [
    "requests>=2.28.0",          # minimum version
    "click>=8.0.0,<9.0.0",       # compatible range
    "pydantic>=2.0.0",
]

# Application (deployed to a server) — pin exactly
# Reproducibility matters more than flexibility
dependencies = [
    "requests==2.31.0",
    "click==8.1.7",
    "pydantic==2.5.0",
]
```

**Lock files — the right way to pin applications:**

```bash
# Generate a lock file from current environment
pip freeze > requirements.lock

# Install from lock file exactly
pip install -r requirements.lock

# Or use pip-tools for better control
pip install pip-tools
pip-compile pyproject.toml    # generates requirements.txt from pyproject.toml
pip-sync requirements.txt     # install exactly what's in the file
```

Poetry and PDM manage lock files automatically. For applications, a lock file is not optional — it's what guarantees "works on my machine" becomes "works everywhere."

---

## Publishing to PyPI

```bash
# Install twine (the upload tool)
pip install twine

# Check your distribution files are valid
twine check dist/*

# Upload to TestPyPI first — always test here before real PyPI
twine upload --repository testpypi dist/*

# Test the install from TestPyPI
pip install --index-url https://test.pypi.org/simple/ task-manager-cli

# Upload to real PyPI
twine upload dist/*
```

**API tokens — never use your password:**

```bash
# Create an API token at pypi.org/manage/account/token/
# Store in ~/.pypirc or use environment variable

export TWINE_USERNAME=__token__
export TWINE_PASSWORD=pypi-your-token-here

twine upload dist/*
```

```ini
# ~/.pypirc — stores credentials
[pypi]
username = __token__
password = pypi-AgEIcHlwaS5vcmcAI...

[testpypi]
repository = https://test.pypi.org/legacy/
username = __token__
password = pypi-AgEIcHlwaS5vcmcAI...
```

---

## Entry points — making your package a CLI tool

```toml
# pyproject.toml
[project.scripts]
task = "task_manager.cli:main"
```

```python
# src/task_manager/cli.py

def main():
    """Entry point for the `task` command."""
    import sys
    from task_manager.app import run
    run(sys.argv[1:])

if __name__ == "__main__":
    main()
```

After `pip install task-manager-cli`, users can run `task` directly from the terminal. pip creates a script in the virtual environment's `bin/` directory that calls your `main()` function.

---

## A complete installable task manager package

Here's how the task manager from this course looks as a proper package:

```
task-manager-cli/
├── src/
│   └── task_manager/
│       ├── __init__.py
│       ├── cli.py
│       ├── manager.py
│       ├── models.py
│       ├── storage.py
│       └── exceptions.py
├── tests/
│   ├── conftest.py
│   └── test_manager.py
├── pyproject.toml
├── README.md
└── LICENSE
```

```python
# src/task_manager/__init__.py

from .manager import TaskManager
from .models import Task
from .exceptions import TaskError, TaskNotFoundError

__version__ = "0.1.0"
__all__ = ["TaskManager", "Task", "TaskError", "TaskNotFoundError"]
```

```toml
# pyproject.toml

[build-system]
requires = ["hatchling"]
build-backend = "hatchling.build"

[project]
name = "task-manager-cli"
version = "0.1.0"
description = "A command-line task manager built during 30-day Python course"
readme = "README.md"
requires-python = ">=3.11"
license = {text = "MIT"}
dependencies = []    # no runtime dependencies — uses only stdlib

[project.optional-dependencies]
dev = [
    "pytest>=7.0.0",
    "pytest-cov>=4.0.0",
    "ruff>=0.1.0",
    "mypy>=1.0.0",
]

[project.scripts]
task = "task_manager.cli:main"

[tool.hatchling.build.targets.wheel]
packages = ["src/task_manager"]

[tool.pytest.ini_options]
testpaths = ["tests"]
addopts = "-v --cov=src/task_manager --cov-report=term-missing"

[tool.ruff]
line-length = 88
target-version = "py311"
```

```bash
# Install in development mode — changes reflected immediately without reinstall
pip install -e ".[dev]"

# Now `task` command is available
task --help
task add "Fix bug" high
task list
```

`pip install -e .` (editable install) creates a link to your `src/` directory instead of copying files. Changes you make are immediately available without reinstalling. This is how you develop a package locally.

---

## Ruff — linting and formatting in one tool

```bash
pip install ruff

# Check for issues
ruff check src/

# Fix automatically
ruff check --fix src/

# Format code
ruff format src/

# Check and format together
ruff check --fix src/ && ruff format src/
```

```toml
# pyproject.toml
[tool.ruff.lint]
select = [
    "E",    # pycodestyle errors
    "F",    # pyflakes
    "I",    # isort (import sorting)
    "N",    # pep8-naming
    "UP",   # pyupgrade — modernize syntax
    "B",    # flake8-bugbear — likely bugs
    "SIM",  # flake8-simplify
]
ignore = [
    "E501",   # line too long — let formatter handle it
]
```

Ruff replaces: `flake8`, `isort`, `black`, `pyupgrade`, and more. It's written in Rust and runs 10-100x faster than the tools it replaces. Use it.

---

## pre-commit — automated checks before every commit

```bash
pip install pre-commit
```

```yaml
# .pre-commit-config.yaml
repos:
  - repo: https://github.com/astral-sh/ruff-pre-commit
    rev: v0.1.5
    hooks:
      - id: ruff
        args: [--fix]
      - id: ruff-format

  - repo: https://github.com/pre-commit/pre-commit-hooks
    rev: v4.5.0
    hooks:
      - id: trailing-whitespace
      - id: end-of-file-fixer
      - id: check-yaml
      - id: check-toml
      - id: check-merge-conflict
      - id: debug-statements    # catches forgotten breakpoint() and pdb calls
```

```bash
# Install the git hooks
pre-commit install

# Now runs automatically on git commit
# Run manually on all files
pre-commit run --all-files
```

From this point on, every commit automatically lints and formats your code. Bad code doesn't enter the repository.

---

## Makefile — common tasks in one place

```makefile
# Makefile

.PHONY: install test lint format build clean publish-test publish

install:
	pip install -e ".[dev]"

test:
	pytest tests/ -v

test-cov:
	pytest tests/ --cov=src/task_manager --cov-report=html

lint:
	ruff check src/ tests/
	mypy src/

format:
	ruff check --fix src/ tests/
	ruff format src/ tests/

build: clean
	python -m build

clean:
	rm -rf dist/ build/ *.egg-info src/*.egg-info
	find . -type d -name __pycache__ -exec rm -rf {} +
	find . -type f -name "*.pyc" -delete

publish-test: build
	twine upload --repository testpypi dist/*

publish: build
	twine upload dist/*
```

```bash
make install      # set up dev environment
make test         # run tests
make lint         # check code quality
make format       # auto-fix formatting
make build        # build distribution files
make publish      # ship it
```

A `Makefile` at the project root means anyone can clone and contribute without reading documentation about what commands to run.

---

## GitHub Actions — automated CI/CD

```yaml
# .github/workflows/ci.yml

name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        python-version: ["3.11", "3.12"]

    steps:
      - uses: actions/checkout@v4

      - name: Set up Python ${{ matrix.python-version }}
        uses: actions/setup-python@v4
        with:
          python-version: ${{ matrix.python-version }}

      - name: Install dependencies
        run: pip install -e ".[dev]"

      - name: Lint
        run: ruff check src/ tests/

      - name: Type check
        run: mypy src/

      - name: Test
        run: pytest tests/ --cov=src/task_manager --cov-report=xml

      - name: Upload coverage
        uses: codecov/codecov-action@v3


---

# .github/workflows/publish.yml

name: Publish to PyPI

on:
  release:
    types: [published]    # triggers when you create a GitHub release

jobs:
  publish:
    runs-on: ubuntu-latest
    environment: pypi
    permissions:
      id-token: write    # for trusted publishing — no API token needed

    steps:
      - uses: actions/checkout@v4

      - name: Set up Python
        uses: actions/setup-python@v4
        with:
          python-version: "3.11"

      - name: Install build
        run: pip install build

      - name: Build
        run: python -m build

      - name: Publish to PyPI
        uses: pypa/gh-action-pypi-publish@release/v1
```

With this setup:

- Every push and PR runs tests on Python 3.11 and 3.12
- Every GitHub release automatically publishes to PyPI
- You never manually run `twine upload` again

---

## The release workflow

```bash
# 1. Make your changes
# 2. Update version in pyproject.toml (or __init__.py)
# 3. Update CHANGELOG.md
# 4. Commit and push

git add .
git commit -m "Release v0.2.0 — add bulk complete feature"
git tag v0.2.0
git push origin main --tags

# 5. Create a GitHub release from the tag
# → CI/CD publishes to PyPI automatically

# Manual release (without CI/CD):
python -m build
twine check dist/*
twine upload dist/*
```

---

## The mental model to carry forward

A Python package is just a directory with `__init__.py` and a `pyproject.toml` that describes it. Everything else — build tools, CI/CD, versioning — is infrastructure that makes sharing and maintaining that code reliable.

**The minimum viable package:**

- `src/your_package/__init__.py` — your code
- `pyproject.toml` — metadata and build config
- `README.md` — what it does and how to use it
- `LICENSE` — required for PyPI, MIT is fine for most projects

**The professional package adds:**

- Tests in `tests/`
- `ruff` for linting and formatting
- `mypy` for type checking
- `pre-commit` hooks so quality checks are automatic
- GitHub Actions for CI on every push
- Automated publish on release

**The rule for versioning:**

- `0.x.y` while the API is still changing
- `1.0.0` when you commit to stability
- Increment patch for bug fixes, minor for new features, major for breaking changes
- Never break a published version — users depend on it

---

Day 26 is web basics — FastAPI, HTTP routes, request validation with Pydantic, and building your first real API server. Ready when you are.

[[Advanced]]