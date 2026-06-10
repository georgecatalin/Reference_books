#### How Professional Python Projects Run Themselves

## Why tooling matters

Every professional Python project has the same problem: code quality degrades over time without enforcement. Inconsistent formatting, unused imports, type errors, failing tests — these accumulate until the codebase becomes hard to work with.

The solution is automation. Tools that run on every save, every commit, and every push — catching problems before they become problems. By the end of this day your project enforces quality without you thinking about it.

---

## The tool stack

```
ruff          — linting + formatting (replaces flake8, black, isort)
mypy          — static type checking
pytest        — testing
pre-commit    — runs checks automatically before every git commit
GitHub Actions — runs everything in CI on every push and pull request
```

All configured in `pyproject.toml` — one file, no scattered config.

---

## ruff — linting and formatting

ruff is written in Rust. It's 10-100x faster than the Python tools it replaces and handles linting and formatting in one command.

```bash
pip install ruff
```

```toml
# pyproject.toml

[tool.ruff]
line-length = 88
target-version = "py311"
src = ["src", "tests"]

[tool.ruff.lint]
select = [
    "E",      # pycodestyle errors
    "W",      # pycodestyle warnings
    "F",      # pyflakes — unused imports, undefined names
    "I",      # isort — import ordering
    "N",      # pep8-naming — naming conventions
    "UP",     # pyupgrade — use modern Python syntax
    "B",      # flake8-bugbear — likely bugs
    "C4",     # flake8-comprehensions — better comprehensions
    "SIM",    # flake8-simplify — simpler code
    "TCH",    # flake8-type-checking — type-only imports
    "RUF",    # ruff-specific rules
]
ignore = [
    "E501",   # line too long — ruff format handles this
    "B008",   # do not perform function call in default argument
    "SIM108", # ternary operator — sometimes if/else is clearer
]

[tool.ruff.lint.per-file-ignores]
"tests/*" = ["S101"]    # allow assert in tests
"__init__.py" = ["F401"] # allow unused imports in __init__.py

[tool.ruff.lint.isort]
known-first-party = ["task_manager"]
force-sort-within-sections = true

[tool.ruff.format]
quote-style = "double"
indent-style = "space"
line-ending = "auto"
```

```bash
# Check for issues
ruff check src/

# Fix automatically — most issues are auto-fixable
ruff check --fix src/

# Format code
ruff format src/

# Check without changing anything — for CI
ruff check --no-fix src/
ruff format --check src/

# Run on specific file
ruff check src/task_manager/manager.py
```

**What ruff catches:**

```python
# F401 — unused import
import os    # never used
import sys

# F841 — local variable assigned but never used
def process():
    result = compute()    # result never used
    return None

# E711 — comparison to None
if x == None:    # wrong
if x is None:    # correct

# UP006 — use modern type syntax
from typing import List, Dict    # old
def func(items: List[Dict]) -> None:    # old

def func(items: list[dict]) -> None:    # modern (Python 3.9+)

# B006 — mutable default argument
def add_item(item, items=[]):    # bug
def add_item(item, items=None):  # correct

# SIM118 — use 'key in dict' instead of 'key in dict.keys()'
if key in my_dict.keys():    # unnecessary
if key in my_dict:           # better

# C401 — unnecessary list comprehension in set()
result = set([x for x in items])    # unnecessary list
result = {x for x in items}         # set comprehension

# I001 — imports not sorted
import sys
import os          # should come before sys
from pathlib import Path
```

---

## mypy — catching type errors before runtime

```bash
pip install mypy
```

```toml
# pyproject.toml

[tool.mypy]
python_version = "3.11"
strict = false                  # strict = true is aggressive for existing codebases

# These are the important ones to enable
disallow_untyped_defs = true    # all functions must have type annotations
warn_return_any = true          # warn when returning Any
warn_unused_ignores = true      # warn when # type: ignore is unnecessary
warn_redundant_casts = true     # warn on unnecessary cast()
no_implicit_optional = true     # Optional[X] must be explicit
check_untyped_defs = true       # check bodies of untyped functions

ignore_missing_imports = true   # don't error on missing stubs for third-party libs

[[tool.mypy.overrides]]
module = "tests.*"
disallow_untyped_defs = false   # relax rules for tests
```

```bash
# Check a module
mypy src/task_manager/

# Check a specific file
mypy src/task_manager/manager.py

# Show error codes (useful for # type: ignore[error-code])
mypy src/ --show-error-codes

# Generate a report
mypy src/ --html-report mypy_report/
```

**What mypy catches:**

```python
# Incompatible types
def greet(name: str) -> str:
    return f"Hello, {name}"

greet(42)    # error: Argument 1 to "greet" has incompatible type "int"; expected "str"


# None dereference
def get_user(id: int) -> dict | None:
    return None

user = get_user(1)
print(user["name"])    # error: Item "None" of "dict | None" has no attribute "__getitem__"

# Fix:
if user is not None:
    print(user["name"])


# Missing return
def divide(a: float, b: float) -> float:
    if b != 0:
        return a / b
    # error: Missing return statement
    # Fix: return 0.0 or raise ValueError


# Wrong return type
def get_count() -> int:
    return "five"    # error: Incompatible return value type (got "str", expected "int")


# Suppressing false positives
result = some_library_function()    # type: ignore[no-any-return]
```

---

## pytest configuration — beyond the basics

```toml
# pyproject.toml

[tool.pytest.ini_options]
testpaths = ["tests"]
addopts = [
    "-v",                    # verbose output
    "--tb=short",            # shorter tracebacks
    "--strict-markers",      # fail on unknown markers
    "--strict-config",       # fail on unknown config options
    "-p no:warnings",        # suppress warnings in output (or use -W error to fail on them)
]
markers = [
    "slow: marks tests as slow (deselect with '-m \"not slow\"')",
    "integration: marks integration tests",
    "unit: marks unit tests",
]
```

```bash
# Run specific markers
pytest -m unit                    # only unit tests
pytest -m "not slow"              # skip slow tests
pytest -m "unit and not slow"     # combine

# Run with coverage
pip install pytest-cov
pytest --cov=src/task_manager --cov-report=term-missing --cov-report=html

# Run in parallel — significantly faster for large test suites
pip install pytest-xdist
pytest -n auto        # use all available CPU cores
pytest -n 4           # use 4 cores

# Run only failed tests from last run
pytest --lf           # last failed
pytest --ff           # failed first, then rest

# Stop on first failure
pytest -x

# Show local variables on failure
pytest -l
```

```toml
# pyproject.toml — coverage config
[tool.coverage.run]
source = ["src"]
omit = [
    "tests/*",
    "src/task_manager/__main__.py",
]
branch = true    # measure branch coverage, not just line coverage

[tool.coverage.report]
show_missing = true
skip_covered = false
fail_under = 80    # fail if coverage drops below 80%
exclude_lines = [
    "pragma: no cover",
    "def __repr__",
    "raise NotImplementedError",
    "if TYPE_CHECKING:",
    "@abstractmethod",
]
```

---

## pre-commit — automated checks before every commit

pre-commit runs hooks automatically when you run `git commit`. Bad code never enters the repository.

```bash
pip install pre-commit
```

```yaml
# .pre-commit-config.yaml

repos:
  # ruff — lint and format
  - repo: https://github.com/astral-sh/ruff-pre-commit
    rev: v0.1.9
    hooks:
      - id: ruff
        args: [--fix, --exit-non-zero-on-fix]
      - id: ruff-format

  # Standard file hygiene
  - repo: https://github.com/pre-commit/pre-commit-hooks
    rev: v4.5.0
    hooks:
      - id: trailing-whitespace      # remove trailing spaces
      - id: end-of-file-fixer        # ensure files end with newline
      - id: check-yaml               # validate YAML syntax
      - id: check-toml               # validate TOML syntax
      - id: check-json               # validate JSON syntax
      - id: check-merge-conflict     # catch unresolved merge conflicts
      - id: check-added-large-files  # prevent committing large files
        args: [--maxkb=500]
      - id: debug-statements         # catch forgotten breakpoint() calls
      - id: detect-private-key       # prevent committing private keys
      - id: no-commit-to-branch      # protect main branch
        args: [--branch, main]

  # mypy — type checking
  - repo: https://github.com/pre-commit/mirrors-mypy
    rev: v1.8.0
    hooks:
      - id: mypy
        additional_dependencies: [pydantic>=2.0.0]
        args: [--ignore-missing-imports]
```

```bash
# Install the git hooks
pre-commit install

# Run all hooks on all files manually
pre-commit run --all-files

# Update hook versions
pre-commit autoupdate

# Skip hooks for one commit (use sparingly)
git commit --no-verify -m "WIP: skip hooks this once"

# Run a specific hook
pre-commit run ruff --all-files
pre-commit run mypy --all-files
```

**What happens on `git commit`:**

```
$ git commit -m "Add task completion feature"
ruff.....................................................................Passed
ruff-format..............................................................Passed
trailing-whitespace......................................................Passed
end-of-file-fixer........................................................Passed
check-yaml...............................................................Passed
check-toml...............................................................Passed
debug-statements.........................................................Passed
mypy.....................................................................Failed
- hook id: mypy
- exit code: 1

src/task_manager/manager.py:45: error: Item "None" of "Task | None" has no attribute "complete"
```

The commit is blocked. Fix the error, stage the change, commit again. Quality is enforced at the source.

---

## GitHub Actions — CI that runs on every push

```yaml
# .github/workflows/ci.yml

name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  quality:
    name: Code Quality
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Set up Python
        uses: actions/setup-python@v5
        with:
          python-version: "3.11"
          cache: "pip"    # cache pip downloads between runs

      - name: Install dependencies
        run: |
          pip install --upgrade pip
          pip install ruff mypy

      - name: Lint with ruff
        run: |
          ruff check --no-fix src/ tests/
          ruff format --check src/ tests/

      - name: Type check with mypy
        run: mypy src/


  test:
    name: Tests (Python ${{ matrix.python-version }})
    runs-on: ${{ matrix.os }}

    strategy:
      fail-fast: false    # don't cancel other jobs if one fails
      matrix:
        python-version: ["3.11", "3.12"]
        os: [ubuntu-latest, windows-latest, macos-latest]

    steps:
      - uses: actions/checkout@v4

      - name: Set up Python ${{ matrix.python-version }}
        uses: actions/setup-python@v5
        with:
          python-version: ${{ matrix.python-version }}
          cache: "pip"

      - name: Install package and dev dependencies
        run: pip install -e ".[dev]"

      - name: Run tests with coverage
        run: pytest --cov=src/task_manager --cov-report=xml --cov-fail-under=80

      - name: Upload coverage to Codecov
        uses: codecov/codecov-action@v4
        if: matrix.python-version == '3.11' && matrix.os == 'ubuntu-latest'
        with:
          token: ${{ secrets.CODECOV_TOKEN }}
          file: coverage.xml


  security:
    name: Security Scan
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Set up Python
        uses: actions/setup-python@v5
        with:
          python-version: "3.11"

      - name: Install bandit
        run: pip install bandit[toml]

      - name: Run security scan
        run: bandit -r src/ -c pyproject.toml
```

---

## Dependency management and security

```bash
# Check for known security vulnerabilities in dependencies
pip install pip-audit
pip-audit

# Output:
# Name          Version  ID                  Fix Versions
# ----------    -------  ------------------  ------------
# requests      2.28.0   GHSA-j8r2-6x86-q33q 2.31.0

# Update requirements to fix vulnerabilities
pip install requests==2.31.0
pip freeze > requirements.txt
```

```toml
# pyproject.toml — bandit security config
[tool.bandit]
exclude_dirs = ["tests", "venv"]
skips = ["B101"]    # B101 = assert_used — we use assert in tests
```

```bash
# Run bandit security scan
pip install bandit
bandit -r src/

# Common issues bandit catches:
# B105 — hardcoded password
# B106 — hardcoded password in function call
# B201 — flask debug mode enabled
# B301 — pickle deserialize — security risk
# B501 — SSL certificate verification disabled
# B601 — shell injection via subprocess
```

---

## Makefile — the developer interface

```makefile
# Makefile
.PHONY: install install-dev test test-cov lint format typecheck clean build

# Default target
help:
	@echo "Available targets:"
	@echo "  install      Install package"
	@echo "  install-dev  Install with dev dependencies"
	@echo "  test         Run tests"
	@echo "  test-cov     Run tests with coverage report"
	@echo "  lint         Run ruff linter"
	@echo "  format       Format code with ruff"
	@echo "  typecheck    Run mypy"
	@echo "  check        Run all checks (lint + typecheck + test)"
	@echo "  clean        Remove build artifacts"
	@echo "  build        Build distribution packages"

install:
	pip install -e .

install-dev:
	pip install -e ".[dev]"
	pre-commit install

test:
	pytest tests/ -v

test-cov:
	pytest tests/ --cov=src/task_manager --cov-report=html --cov-report=term-missing
	@echo "Coverage report: htmlcov/index.html"

lint:
	ruff check src/ tests/

lint-fix:
	ruff check --fix src/ tests/
	ruff format src/ tests/

format:
	ruff format src/ tests/

typecheck:
	mypy src/

check: lint typecheck test
	@echo "All checks passed."

security:
	bandit -r src/
	pip-audit

clean:
	find . -type d -name __pycache__ -exec rm -rf {} + 2>/dev/null || true
	find . -type f -name "*.pyc" -delete
	rm -rf dist/ build/ *.egg-info src/*.egg-info
	rm -rf .coverage htmlcov/ .mypy_cache/ .ruff_cache/ .pytest_cache/

build: clean
	python -m build

publish-test: build
	twine upload --repository testpypi dist/*

publish: build
	twine upload dist/*
```

```bash
make install-dev    # first-time setup
make check          # run all checks before committing
make test-cov       # see coverage report
```

---

## The complete project configuration

A fully configured `pyproject.toml` that handles everything:

```toml
# pyproject.toml

[build-system]
requires = ["hatchling"]
build-backend = "hatchling.build"


[project]
name = "task-manager-cli"
version = "0.1.0"
description = "A command-line task manager"
readme = "README.md"
requires-python = ">=3.11"
license = {text = "MIT"}
dependencies = []

[project.optional-dependencies]
dev = [
    "pytest>=7.4.0",
    "pytest-cov>=4.0.0",
    "pytest-xdist>=3.0.0",
    "mypy>=1.8.0",
    "ruff>=0.1.9",
    "pre-commit>=3.6.0",
    "bandit>=1.7.0",
    "pip-audit>=2.6.0",
]

[project.scripts]
task = "task_manager.cli:main"

[tool.hatchling.build.targets.wheel]
packages = ["src/task_manager"]


# ── pytest ──────────────────────────────────────────────────────
[tool.pytest.ini_options]
testpaths = ["tests"]
addopts = ["-v", "--tb=short", "--strict-markers"]
markers = [
    "slow: marks slow tests",
    "integration: marks integration tests",
    "unit: marks unit tests",
]

[tool.coverage.run]
source = ["src"]
branch = true
omit = ["tests/*"]

[tool.coverage.report]
show_missing = true
fail_under = 80


# ── ruff ────────────────────────────────────────────────────────
[tool.ruff]
line-length = 88
target-version = "py311"
src = ["src", "tests"]

[tool.ruff.lint]
select = ["E", "W", "F", "I", "N", "UP", "B", "C4", "SIM", "RUF"]
ignore = ["E501", "B008"]

[tool.ruff.lint.per-file-ignores]
"tests/*" = ["S101"]
"__init__.py" = ["F401"]

[tool.ruff.format]
quote-style = "double"


# ── mypy ────────────────────────────────────────────────────────
[tool.mypy]
python_version = "3.11"
disallow_untyped_defs = true
warn_return_any = true
warn_unused_ignores = true
no_implicit_optional = true
ignore_missing_imports = true

[[tool.mypy.overrides]]
module = "tests.*"
disallow_untyped_defs = false


# ── bandit ──────────────────────────────────────────────────────
[tool.bandit]
exclude_dirs = ["tests", "venv", ".venv"]
skips = ["B101"]
```

---

## The developer workflow

**Day-to-day:**

```bash
# Start work
git checkout -b feature/bulk-delete

# Write code, run tests frequently
make test

# Before committing — pre-commit runs automatically
git add .
git commit -m "Add bulk delete feature"
# pre-commit hooks run: ruff, mypy, file checks
# If anything fails: fix it, git add, commit again

# Push
git push origin feature/bulk-delete
# CI runs: quality + test matrix + security
# If CI passes: open pull request
```

**New team member onboarding:**

```bash
git clone https://github.com/team/task-manager-cli
cd task-manager-cli
python -m venv venv && source venv/bin/activate
make install-dev    # installs everything including pre-commit hooks
make check          # verify everything works
```

That's it. One command to get a working, fully-checked development environment.

---

## The mental model to carry forward

Tools enforce what code review can't. A reviewer catches logic bugs. Tools catch style, type errors, security issues, and test coverage — automatically, on every commit.

**The enforcement layers:**

```
Editor        → real-time feedback (ruff, mypy extensions)
pre-commit    → blocks bad commits locally
CI            → blocks merging bad code to main
Coverage      → quantifies what's untested
```

**The return on investment:**

- `ruff`: zero — it's instant, auto-fixes most issues
- `mypy`: high — catches entire classes of runtime errors before deployment
- `pre-commit`: high — 30-second setup, saves hours of code review comments
- `GitHub Actions`: very high — every push is tested on clean environments across Python versions and OSes

**The rule:** any check that can be automated should be automated. Code review time is expensive. CI time is cheap. Use CI for the mechanical stuff, save human review for logic and design.

---

Day 29 is architecture — structuring large Python projects, separation of concerns, dependency injection, and the patterns that keep a growing codebase maintainable. Ready when you are.