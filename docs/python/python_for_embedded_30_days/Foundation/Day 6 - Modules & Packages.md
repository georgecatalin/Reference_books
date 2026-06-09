### How `import` actually works

When you write `import mqtt_client`, Python does three things:

1. Searches `sys.path` for a file named `mqtt_client.py` or a directory named `mqtt_client/`
2. Executes the module file top to bottom (once — cached in `sys.modules` after)
3. Binds the result to the name `mqtt_client` in your namespace

The cache matters: importing the same module twice doesn't re-execute it. This is why module-level state (a logger, a connection pool, a config object) persists across imports.

python

```python
import sys
print(sys.path)          # where Python looks, in order
print(sys.modules.keys()) # everything already imported this session
```

---

### Import styles and when to use each

python

```python
import os                          # access as os.path.join()
import os.path                     # access as os.path.join()
from os.path import join, exists   # access as join(), exists()
from os.path import join as j      # alias — use sparingly, reduces clarity

# Absolute import (always preferred)
from mypackage.comms import MQTTClient

# Relative import (only inside a package)
from .comms import MQTTClient      # same package
from ..config import settings      # parent package
```

Never use `from module import *` in production code. It pollutes your namespace with unknown names and breaks static analysis.

---

### Package structure for an IoT project

A package is a directory with an `__init__.py` file. Here's a structure that scales:

```
mqtt_monitor/
├── __init__.py          # makes this a package; often empty or exports public API
├── __main__.py          # enables `python -m mqtt_monitor`
├── config.py            # settings, constants
├── comms/
│   ├── __init__.py
│   ├── mqtt_client.py
│   └── serial_reader.py
├── models/
│   ├── __init__.py
│   └── reading.py
├── storage/
│   ├── __init__.py
│   └── db.py
└── utils/
    ├── __init__.py
    └── parsing.py
```

`__init__.py` controls what `from mqtt_monitor import X` exposes:

python

```python
# mqtt_monitor/__init__.py
from .comms.mqtt_client import MQTTClient
from .models.reading import SensorReading
from .config import settings

__all__ = ["MQTTClient", "SensorReading", "settings"]
```

Now users can write `from mqtt_monitor import MQTTClient` instead of the full path.

---

### Avoiding circular imports

Circular imports are the most common package-structure bug. They happen when A imports B and B imports A:

python

```python
# models.py
from .comms import MQTTClient    # imports comms

# comms.py
from .models import SensorReading  # imports models — CIRCULAR
```

Python partially initializes modules, so one of them will see an incomplete import. Fixes:

1. Move shared types to a third module (`types.py` or `interfaces.py`) that neither A nor B imports from
2. Move the import inside the function that needs it (lazy import)
3. Use `TYPE_CHECKING` for imports only needed in type hints:

python

```python
from __future__ import annotations
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from .comms import MQTTClient   # only imported during type checking, not runtime
```

---

### `__main__.py` — making packages executable

python

```python
# mqtt_monitor/__main__.py
import sys
from .config import settings
from .comms.mqtt_client import MQTTClient

def main() -> None:
    client = MQTTClient(settings.host, settings.port)
    client.connect()

if __name__ == "__main__":
    sys.exit(main())
```

Now `python -m mqtt_monitor` works from anywhere.

---

### Today's deliverable

Restructure the code from Days 1–5 into a proper package:

```
iot_toolkit/
├── __init__.py
├── __main__.py
├── config.py          # constants: DEFAULT_PORT, MAX_READINGS, etc.
├── models/
│   ├── __init__.py
│   └── reading.py     # SensorReading from Day 2
├── buffers/
│   ├── __init__.py
│   └── telemetry.py   # TelemetryBuffer from Day 3
├── pipeline/
│   ├── __init__.py
│   └── csv_pipeline.py  # generator pipeline from Day 4
└── io/
    ├── __init__.py
    └── port.py          # SimulatedPort + context manager from Day 5
```

Each module should only import what it needs. `models/reading.py` should not import from `buffers/`. The `__init__.py` at the top level should export `SensorReading`, `TelemetryBuffer`, and `open_port`. Running `python -m iot_toolkit` should instantiate a buffer, push 5 readings, and print the average.

---

[[Foundation]]