### Why virtual environments exist

Python has one global site-packages directory per interpreter. Without venvs, every project shares the same package versions. Project A needs `paho-mqtt==1.6`, Project B needs `paho-mqtt==2.0` — they can't coexist globally. A venv is an isolated copy of the interpreter with its own site-packages.

---

### `venv` — the built-in tool

bash

```bash
# Create
python -m venv .venv

# Activate (Linux/macOS)
source .venv/bin/activate

# Activate (Windows PowerShell)
.venv\Scripts\Activate.ps1

# Verify you're inside it
which python        # should point to .venv/bin/python
pip list            # only what's installed in this venv

# Deactivate
deactivate
```

Name it `.venv` — that's the convention most tools (VS Code, PyCharm, mypy) recognize automatically. Add `.venv/` to `.gitignore`.

---

### `pip` — installing packages

bash

```bash
pip install paho-mqtt              # latest
pip install paho-mqtt==2.0.0       # exact version
pip install "paho-mqtt>=1.6,<3"    # version range
pip install -e .                   # editable install of current package
pip install -r requirements.txt    # install from file

pip list                           # what's installed
pip show paho-mqtt                 # details + dependencies
pip freeze                         # all installed with exact versions
```

---

### `requirements.txt` — reproducible installs

bash

```bash
# Generate
pip freeze > requirements.txt

# Install from it (on another machine or CI)
pip install -r requirements.txt
```

A minimal `requirements.txt` for IoT work:

```
paho-mqtt==2.0.0
pyserial==3.5
pydantic==2.5.0
httpx==0.26.0
fastapi==0.109.0
uvicorn==0.27.0
mypy==1.8.0
pytest==7.4.0
```

Two files are a common pattern: `requirements.txt` for exact pinned production versions, `requirements-dev.txt` for additional dev tools:

```
# requirements-dev.txt
-r requirements.txt
mypy==1.8.0
pytest==7.4.0
black==24.1.0
ruff==0.2.0
```

---

### `pyproject.toml` — the modern approach

For packages you'll distribute or deploy as a unit, `pyproject.toml` replaces `setup.py`:

toml

```toml
[build-system]
requires = ["hatchling"]
build-backend = "hatchling.build"

[project]
name = "iot-toolkit"
version = "0.1.0"
requires-python = ">=3.11"
dependencies = [
    "paho-mqtt>=2.0.0",
    "pyserial>=3.5",
    "pydantic>=2.0",
]

[project.optional-dependencies]
dev = [
    "mypy>=1.8",
    "pytest>=7.4",
    "ruff>=0.2",
]

[project.scripts]
iot-toolkit = "iot_toolkit.__main__:main"

[tool.mypy]
python_version = "3.11"
disallow_untyped_defs = true
warn_return_any = true

[tool.ruff]
line-length = 100
```

With this file, `pip install -e ".[dev]"` installs the package in editable mode with dev dependencies.

---

### Today's deliverable

Set up the `iot_toolkit` package from Day 6 as a proper Python project:

bash

```bash
# Structure
iot_toolkit/          # package directory from Day 6
pyproject.toml        # project metadata + dependencies
requirements.txt      # pinned for deployment
requirements-dev.txt  # dev tools
.gitignore            # includes .venv/, __pycache__/, *.egg-info/
README.md             # one paragraph: what it does, how to run it
```

Steps:

1. Create a fresh venv: `python -m venv .venv && source .venv/bin/activate`
2. Install `paho-mqtt`, `pyserial`, `pydantic`, `mypy`, `pytest`
3. Write `pyproject.toml` with correct metadata and dependencies
4. Run `pip freeze > requirements.txt`
5. Verify `pip install -e .` works (installs your package)
6. Verify `python -m iot_toolkit` runs without import errors

By the end of Week 1 you have: a correctly structured Python package, with type-annotated models, a streaming data pipeline, robust error handling, and a reproducible development environment. That's the foundation everything else builds on.

---

**Week 1 complete.** Days 8–14 move into OOP and design patterns — where you'll take the raw structures from this week and learn to design them at a professional level.