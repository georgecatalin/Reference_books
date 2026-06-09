No code today. This session is architecture — you design the system before writing a single line. Professional developers who skip this step spend twice as long debugging structural problems that design would have caught.

---

### The system you're building

A production-ready Python MQTT device monitor with:

- Async MQTT ingestion with reconnect and backpressure
- Pydantic-validated payload parsing
- SQLite persistence with WAL mode
- FastAPI REST API served alongside the ingester
- Structured JSON logging
- pytest test coverage on critical paths
- Docker deployment with mosquitto

This is not a toy. By the end of Day 30 this should be something you can actually deploy against your existing device fleet.

---

### Step 1 — Module map

Here is the target structure. Your job today is to understand every boundary and be able to explain why each module exists separately:

```
mqtt_monitor/
├── __init__.py
├── __main__.py              # entry point: asyncio.run(main())
├── config.py                # all settings from env vars, one place
├── models/
│   ├── __init__.py
│   └── payload.py           # Pydantic models: DevicePayload, ReadingIn, ReadingOut
├── ingester/
│   ├── __init__.py
│   ├── client.py            # ProductionMQTTClient (Day 22)
│   └── pipeline.py          # parse → validate → enqueue
├── storage/
│   ├── __init__.py
│   └── db.py                # TelemetryDB (Day 24): init, insert, query, purge
├── api/
│   ├── __init__.py
│   └── routes.py            # FastAPI app + all route handlers
├── logging_config.py        # JSONFormatter, setup_logging()
└── tests/
    ├── conftest.py           # shared fixtures
    ├── test_models.py
    ├── test_pipeline.py
    ├── test_storage.py
    └── test_api.py
```

---

### Step 2 — Data flow diagram

Trace a single MQTT message from arrival to REST API response:

```
MQTT Broker
    │
    │  bytes (topic + payload)
    ▼
ingester/client.py
  _on_message() callback
  [paho network thread]
    │
    │  raw (topic: str, payload: bytes)
    ▼
ingester/pipeline.py
  parse_and_validate()
  - json.loads()
  - DevicePayload.model_validate()
  - on error: log + discard
    │
    │  DevicePayload (validated Pydantic model)
    ▼
asyncio.Queue  (maxsize=500, backpressure)
    │
    │  DevicePayload
    ▼
ingester/pipeline.py
  worker coroutine (3 workers)
  - db.upsert_device()
  - db.insert_readings()
    │
    │  written to SQLite (WAL mode)
    ▼
storage/db.py  (TelemetryDB)
    │
    │  queried on HTTP request
    ▼
api/routes.py  (FastAPI)
    │
    │  JSON response
    ▼
HTTP Client
```

Write this out yourself — on paper or in a text file. The act of drawing it reveals gaps.

---

### Step 3 — Data models

Define these before writing any code. Every other module depends on them:

python

```python
# models/payload.py

from pydantic import BaseModel, Field, field_validator
from typing import Optional
import time

class RawReading(BaseModel):
    variable: str
    value:    float

    @field_validator("value")
    @classmethod
    def value_in_physical_range(cls, v: float) -> float:
        if not (-300.0 <= v <= 100_000.0):
            raise ValueError(f"Value {v} outside physical range")
        return round(v, 6)

class DevicePayload(BaseModel):
    device_id: str
    firmware:  Optional[str]  = None
    ts:        float          = Field(default_factory=time.time)
    readings:  list[RawReading]

    @field_validator("device_id")
    @classmethod
    def normalize_device_id(cls, v: str) -> str:
        v = v.strip().lower()
        if not v:
            raise ValueError("device_id cannot be empty")
        return v

    @field_validator("readings")
    @classmethod
    def at_least_one_reading(cls, v: list) -> list:
        if not v:
            raise ValueError("readings list cannot be empty")
        return v

class ReadingOut(BaseModel):
    device_id: str
    variable:  str
    value:     float
    ts:        float

class DeviceOut(BaseModel):
    device_id:     str
    last_seen:     float
    firmware:      Optional[str]
    reading_count: int
    latest:        dict[str, float]   # variable → latest value
```

---

### Step 4 — Interface contracts

Write the function signatures for every public function in every module. No implementations — just signatures and docstrings. This is the contract between modules:

python

```python
# storage/db.py — public interface

class TelemetryDB:
    def __init__(self, path: str) -> None: ...

    def init_schema(self) -> None:
        """Create tables and indexes if they don't exist."""

    def upsert_device(self, device_id: str, firmware: Optional[str] = None) -> None:
        """Insert or update device record. Updates last_seen and firmware."""

    def insert_readings(self, device_id: str, readings: list[RawReading], ts: float) -> None:
        """Batch insert readings for a device in a single transaction."""

    def get_latest(self, device_id: str) -> dict[str, float]:
        """Return {variable: latest_value} for a device."""

    def get_history(self, device_id: str, variable: str, limit: int) -> list[ReadingOut]:
        """Return last N readings for a device/variable, newest first."""

    def list_devices(self) -> list[DeviceOut]:
        """Return all known devices with summary stats."""

    def purge_older_than(self, seconds: float) -> int:
        """Delete readings older than N seconds. Returns count deleted."""

    def close(self) -> None: ...
```

python

```python
# ingester/pipeline.py — public interface

async def run_ingester(
    client:     ProductionMQTTClient,
    db:         TelemetryDB,
    topics:     list[str],
    queue_size: int,
    n_workers:  int,
    stop:       asyncio.Event,
) -> None:
    """
    Subscribe to topics, validate incoming messages, write to db.
    Runs until stop is set. Logs parse errors and drops bad messages.
    """

def parse_payload(topic: str, raw: bytes) -> Optional[DevicePayload]:
    """
    Parse raw MQTT message into DevicePayload.
    Returns None on any parse or validation error.
    """
```

python

```python
# api/routes.py — public interface

def create_app(db: TelemetryDB) -> FastAPI:
    """
    Build and return the FastAPI app.
    db is injected — no global state.
    """
```

---

### Step 5 — Concurrency model

Write out explicitly how the two main loops coexist:

```
Process: mqtt_monitor
│
├── asyncio event loop (main thread)
│   ├── Task: run_ingester()
│   │   ├── paho callback thread → asyncio.Queue (thread-safe bridge)
│   │   └── 3× worker coroutines: Queue → TelemetryDB
│   ├── Task: uvicorn HTTP server (serves FastAPI)
│   └── Task: periodic purge (every 1 hour, delete readings > 7 days)
│
└── paho network thread (daemon, managed by paho loop_start())
    └── calls _on_message → puts to asyncio.Queue via call_soon_threadsafe()
```

The critical bridge: paho callbacks run in paho's thread, but asyncio.Queue operations must happen in the event loop thread. The correct bridge:

python

```python
def _on_message(self, client, userdata, message: mqtt.MQTTMessage) -> None:
    # We are in paho's thread — cannot await or put directly to asyncio.Queue
    loop.call_soon_threadsafe(
        lambda: asyncio.ensure_future(queue.put(message))
    )
    # OR simpler for non-async queues:
    # threading.Queue.put_nowait() is thread-safe — use this on the ingester side
```

---

### Step 6 — Error handling matrix

For every failure mode, decide the behavior before you write the code:

|Failure|Detection|Response|
|---|---|---|
|MQTT broker unreachable|`on_disconnect` rc != 0|Exponential backoff reconnect, buffer outgoing messages|
|Malformed JSON payload|`json.JSONDecodeError`|Log warning with raw payload hex, discard, increment counter|
|Pydantic validation failure|`ValidationError`|Log warning with field errors, discard|
|SQLite write error|`sqlite3.Error`|Log error, retry once, then discard + increment counter|
|asyncio.Queue full|`QueueFull`|Log warning, discard oldest OR drop new (decide: drop new)|
|HTTP request to unknown device|FastAPI route|`HTTPException(404)`|
|Startup: DB path not writable|`sqlite3.OperationalError`|Log critical, exit with code 1|
|Startup: broker unreachable|connection timeout|Log warning, continue with reconnect loop|

---

### Step 7 — Test plan

List exactly what you will test before writing tests. Tests you didn't plan tend not to exist:

```
test_models.py
  ✓ DevicePayload validates correctly with all fields
  ✓ DevicePayload normalizes device_id (strip, lowercase)
  ✓ DevicePayload rejects empty device_id
  ✓ DevicePayload rejects empty readings list
  ✓ RawReading rejects out-of-range values
  ✓ RawReading accepts boundary values

test_pipeline.py
  ✓ parse_payload returns DevicePayload on valid JSON
  ✓ parse_payload returns None on invalid JSON
  ✓ parse_payload returns None on Pydantic validation failure
  ✓ parse_payload returns None on missing required fields

test_storage.py
  ✓ upsert_device creates new device
  ✓ upsert_device updates last_seen on repeat call
  ✓ insert_readings stores all readings in one transaction
  ✓ get_latest returns most recent value per variable
  ✓ get_history returns correct limit and order
  ✓ purge_older_than deletes correct rows and returns count
  ✓ list_devices returns all devices with correct stats

test_api.py
  ✓ GET /health returns 200
  ✓ GET /devices returns list with correct structure
  ✓ GET /devices/{id} returns device data
  ✓ GET /devices/{id} returns 404 for unknown device
  ✓ GET /devices/{id}/history returns paginated history
```

---

### Your deliverable for Day 29

Create this file — it is your design document:

```
mqtt_monitor/
└── DESIGN.md
```

markdown

```markdown
# mqtt_monitor — Design Document

## System overview
[one paragraph: what this does, for whom, in what environment]

## Module map
[paste the directory tree, annotate each file with one sentence]

## Data flow
[the message path from MQTT → SQLite → HTTP in plain English + ASCII diagram]

## Data models
[paste the Pydantic models with all validators]

## Interface contracts
[paste the function signatures for db.py, pipeline.py, routes.py]

## Concurrency model
[explain the thread/coroutine architecture and the paho→asyncio bridge]

## Error handling
[paste the error matrix]

## Test plan
[paste the test list]

## Configuration
[list every env var, its type, default, and what it controls]

## Deployment
[Docker command, docker-compose snippet, systemd unit]
```

Fill every section. If a section is vague, the implementation will be vague. The design document is done when you could hand it to another developer and they could implement the system without asking you questions.

[[Mastery]]