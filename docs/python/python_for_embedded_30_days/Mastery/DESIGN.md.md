Here's your `DESIGN.md`. Read every section carefully before Day 30 — this document drives the entire build.

---

```markdown
# mqtt_monitor — Design Document

## System overview

mqtt_monitor is a Python daemon that ingests telemetry from an MQTT broker,
validates and persists it to a local SQLite database, and exposes it via a
REST API. It is designed for small-to-medium industrial IoT fleets (tens to
low hundreds of devices) running on a Linux gateway or server — bare metal,
VM, or container. It replaces ad-hoc PHP ingestion scripts with a
structured, tested, deployable Python service.

---

## Module map

```

mqtt_monitor/ 
├── **init**.py # package marker, exports VERSION 
├── **main**.py # entry point: asyncio.run(main()) 
├── config.py # all settings read from env vars, typed, with defaults 
├── logging_config.py # JSONFormatter + setup_logging() 
├── models/ │ 
├── **init**.py │ └── payload.py # Pydantic: DevicePayload, RawReading, ReadingOut, DeviceOut 
├── ingester/ │ 
├── **init**.py │ ├── client.py # ProductionMQTTClient: connect, subscribe, publish, reconnect │ 
└── pipeline.py # parse_payload(), run_ingester() coroutine + workers 
├── storage/ │ 
├── **init**.py │ └── db.py # TelemetryDB: schema init, upsert, insert, query, purge 
├── api/ │ 
├── **init**.py │ └── routes.py # create_app(db) → FastAPI instance with all routes 
└── tests/ 
├── conftest.py # shared fixtures: in-memory db, sample payloads, mock client 
├── test_models.py # Pydantic model validation tests 
├── test_pipeline.py # parse_payload() unit tests 
├── test_storage.py # TelemetryDB integration tests (SQLite :memory:) 
└── test_api.py # FastAPI route tests via httpx.AsyncClient

```

---

## Data flow

A single MQTT message travels this path from arrival to HTTP response:

```

MQTT Broker (mosquitto) │ │ raw bytes: topic (str) + payload (bytes) ▼ ingester/client.py — _on_message() Running in paho's background network thread. Bridges to asyncio via loop.call_soon_threadsafe(): raw_queue.put_nowait((topic, payload)) raw_queue is a threading.Queue (thread-safe, no await needed). │ │ (topic: str, payload: bytes) ▼ ingester/pipeline.py — reader coroutine Drains raw_queue into async_queue (asyncio.Queue, maxsize=500). On QueueFull: log warning, drop message, increment drop counter. │ │ (topic: str, payload: bytes) ▼ ingester/pipeline.py — parse_and_validate() json.loads(payload) DevicePayload.model_validate(data) On any error: log warning + hex dump of raw payload, return None. │ │ DevicePayload (validated Pydantic model) or None (discarded) ▼ ingester/pipeline.py — worker coroutine (×3) db.upsert_device(payload.device_id, payload.firmware) db.insert_readings(payload.device_id, payload.readings, payload.ts) │ │ SQL INSERT (batch, single transaction per payload) ▼ storage/db.py — TelemetryDB (SQLite, WAL mode) Tables: devices, readings Indexes: (device_id, ts DESC), (variable, ts DESC) │ │ queried on incoming HTTP request ▼ api/routes.py — FastAPI route handler db.get_latest() / db.get_history() / db.list_devices() Serializes to ReadingOut / DeviceOut Pydantic models │ │ JSON response ▼ HTTP client

````

---

## Data models

```python
# models/payload.py

from pydantic import BaseModel, Field, field_validator
from typing import Optional
import time


class RawReading(BaseModel):
    variable: str
    value:    float

    @field_validator("variable")
    @classmethod
    def variable_not_empty(cls, v: str) -> str:
        v = v.strip().lower()
        if not v:
            raise ValueError("variable cannot be empty")
        return v

    @field_validator("value")
    @classmethod
    def value_in_physical_range(cls, v: float) -> float:
        if not (-300.0 <= v <= 100_000.0):
            raise ValueError(f"Value {v} outside physical range [-300, 100000]")
        return round(v, 6)


class DevicePayload(BaseModel):
    device_id: str
    firmware:  Optional[str]  = None
    ts:        float          = Field(default_factory=time.time)
    readings:  list[RawReading]

    model_config = {"strict": False}   # allow int→float coercion

    @field_validator("device_id")
    @classmethod
    def normalize_device_id(cls, v: str) -> str:
        v = v.strip().lower()
        if not v:
            raise ValueError("device_id cannot be empty")
        if len(v) > 64:
            raise ValueError("device_id exceeds 64 characters")
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
````

---

## Interface contracts

### storage/db.py

```python
class TelemetryDB:

    def __init__(self, path: str) -> None:
        """
        Open (or create) the SQLite database at path.
        Use path=':memory:' for tests.
        Applies WAL mode, NORMAL sync, foreign keys ON.
        Calls init_schema() automatically.
        """

    def init_schema(self) -> None:
        """
        CREATE TABLE IF NOT EXISTS for devices and readings.
        CREATE INDEX IF NOT EXISTS for (device_id, ts) and (variable, ts).
        Safe to call multiple times.
        """

    def upsert_device(self, device_id: str, firmware: Optional[str] = None) -> None:
        """
        Insert device if new, otherwise update last_seen and firmware.
        firmware=None leaves existing firmware value unchanged.
        """

    def insert_readings(
        self,
        device_id: str,
        readings:  list[RawReading],
        ts:        float,
    ) -> None:
        """
        Batch insert all readings for a payload in a single transaction.
        Rolls back entirely on any error — no partial writes.
        """

    def get_latest(self, device_id: str) -> dict[str, float]:
        """
        Return {variable: latest_value} for a device.
        Returns empty dict if device unknown or has no readings.
        """

    def get_history(
        self,
        device_id: str,
        variable:  str,
        limit:     int = 50,
    ) -> list[ReadingOut]:
        """
        Return last `limit` readings for device+variable, newest first.
        Returns empty list if none found.
        """

    def list_devices(self) -> list[DeviceOut]:
        """
        Return all known devices with last_seen, firmware, reading_count,
        and latest value per variable.
        Ordered by last_seen DESC.
        """

    def purge_older_than(self, seconds: float) -> int:
        """
        Delete readings with ts < (now - seconds).
        Returns count of deleted rows.
        Does NOT delete device records.
        """

    def close(self) -> None:
        """Close the underlying SQLite connection."""
```

### ingester/pipeline.py

```python
def parse_payload(topic: str, raw: bytes) -> Optional[DevicePayload]:
    """
    Parse a raw MQTT message into a validated DevicePayload.

    Steps:
      1. json.loads(raw)
      2. DevicePayload.model_validate(data)

    Returns None on JSONDecodeError, ValidationError, or any other exception.
    Logs a WARNING with the topic and raw payload hex on failure.
    Never raises.
    """

async def run_ingester(
    client:     ProductionMQTTClient,
    db:         TelemetryDB,
    topics:     list[str],
    stop:       asyncio.Event,
    queue_size: int = 500,
    n_workers:  int = 3,
) -> None:
    """
    Full ingestion pipeline. Runs until stop is set.

    Responsibilities:
    - Subscribe client to all topics
    - Bridge paho thread → asyncio.Queue via call_soon_threadsafe
    - Spawn n_workers coroutines to drain the queue
    - Each worker: parse → validate → db.upsert_device + db.insert_readings
    - On queue full: log warning, drop message
    - On parse failure: log warning, discard
    - On db error: log error, discard (no retry — prevents queue backup)
    - On stop: drain remaining queue items, then return
    """
```

### api/routes.py

```python
def create_app(db: TelemetryDB) -> FastAPI:
    """
    Construct and return the FastAPI application.

    db is injected at construction time — no module-level globals.
    Registers lifespan handler (none needed: db lifecycle managed externally).

    Routes:
      GET  /health                          → {"status": "ok", "ts": float}
      GET  /devices                         → list[DeviceOut]
      GET  /devices/{device_id}             → DeviceOut | 404
      GET  /devices/{device_id}/history     → list[ReadingOut] (query: variable, limit)
      GET  /metrics                         → ingester counters (received, dropped, errors)
    """
```

### ingester/client.py

```python
class ProductionMQTTClient:

    def __init__(self, config: MQTTConfig) -> None:
        """
        Configure paho client with will message, reconnect parameters,
        and threading.Queue for raw message output.
        Does not connect yet.
        """

    def connect(self) -> bool:
        """
        Connect to broker, start loop_start().
        Returns True if connected within timeout, False otherwise.
        Never raises — logs and returns False on failure.
        """

    def disconnect(self) -> None:
        """Disconnect cleanly. Stops loop. Clears connected event."""

    def subscribe(self, topics: list[str]) -> None:
        """
        Subscribe to all topics at QoS 0.
        Stores subscriptions for re-subscribe after reconnect.
        """

    def publish(
        self,
        topic:   str,
        payload: bytes,
        qos:     int  = 0,
        retain:  bool = False,
    ) -> None:
        """
        Enqueue message for sending. Non-blocking.
        Drops and logs if send buffer full.
        """

    @property
    def raw_queue(self) -> queue.Queue:
        """
        Thread-safe queue of (topic: str, payload: bytes) tuples.
        Populated by paho callback. Consumed by pipeline reader coroutine.
        """
```

---

## Concurrency model

```
Process: mqtt_monitor (single OS process)
│
├── Main thread — asyncio event loop
│   │
│   ├── Task: run_ingester()
│   │   ├── reader coroutine
│   │   │     Polls client.raw_queue (threading.Queue) via
│   │   │     asyncio.get_event_loop().run_in_executor() or
│   │   │     asyncio.sleep(0) poll loop.
│   │   │     Puts validated items into asyncio.Queue.
│   │   │
│   │   └── worker coroutines ×3
│   │         Each awaits asyncio.Queue.get()
│   │         Calls db.upsert_device() + db.insert_readings()
│   │         (SQLite calls are synchronous — acceptable because
│   │          WAL mode makes writes fast and non-blocking for readers.
│   │          If write latency becomes an issue, wrap in run_in_executor.)
│   │
│   ├── Task: uvicorn server
│   │     Serves FastAPI on 0.0.0.0:API_PORT
│   │     Shares TelemetryDB instance with ingester.
│   │     Read queries are safe concurrent with WAL writes.
│   │
│   └── Task: periodic_purge()
│         Runs every PURGE_INTERVAL_HOURS (default: 1).
│         Calls db.purge_older_than(RETENTION_SECONDS).
│         Logs rows deleted.
│
└── paho network thread (daemon, managed by loop_start())
      Handles TCP I/O with broker.
      On message: calls _on_message() in THIS thread.
      _on_message() calls raw_queue.put_nowait() — thread-safe.
      On disconnect: schedules reconnect via threading.Timer.
```

Thread-safety rules:

- `threading.Queue` (raw_queue): safe from any thread, no lock needed
- `asyncio.Queue` (async_queue): only touched from event loop thread
- `TelemetryDB`: SQLite with WAL — concurrent reads safe, writes serialized by SQLite
- No shared mutable Python objects between paho thread and event loop thread

---

## Error handling matrix

|Failure|Where detected|Response|
|---|---|---|
|Broker unreachable at startup|`connect()` timeout|Log WARNING, return False, ingester retries via reconnect loop|
|Broker disconnects mid-run|`on_disconnect` rc != 0|Log WARNING, exponential backoff reconnect (1s→60s + jitter)|
|Malformed JSON payload|`json.JSONDecodeError` in `parse_payload`|Log WARNING + hex(raw[:64]), discard, increment parse_errors counter|
|Pydantic validation failure|`ValidationError` in `parse_payload`|Log WARNING + field errors, discard, increment validation_errors counter|
|asyncio.Queue full (backpressure)|`QueueFull` in reader coroutine|Log WARNING + queue size, drop newest message, increment drop_counter|
|SQLite write error|`sqlite3.Error` in worker|Log ERROR + exception, discard payload, increment db_errors counter|
|SQLite file not writable at startup|`sqlite3.OperationalError` in `__init__`|Log CRITICAL, raise — caught in `__main__`, exit code 1|
|HTTP request for unknown device|FastAPI route handler|`HTTPException(status_code=404, detail="Device not found")`|
|HTTP query param out of range|FastAPI Query validation|Automatic 422 Unprocessable Entity from FastAPI|
|Graceful shutdown (SIGTERM/SIGINT)|Signal handler in `__main__`|Set stop event, drain queue, disconnect client, close db, exit 0|

---

## Test plan

### tests/conftest.py

- Fixture: `db` — TelemetryDB(":memory:"), yields, calls close()
- Fixture: `sample_payload` — valid DevicePayload with 2 readings
- Fixture: `sample_readings` — list of 5 RawReading objects
- Fixture: `api_client` — httpx.AsyncClient wrapping create_app(db)

### tests/test_models.py

- DevicePayload accepts valid payload with all fields
- DevicePayload accepts payload without optional firmware field
- DevicePayload normalizes device_id: strips whitespace, lowercases
- DevicePayload rejects empty string device_id
- DevicePayload rejects device_id longer than 64 characters
- DevicePayload rejects empty readings list
- DevicePayload coerces string value "22.4" to float 22.4
- RawReading rejects value below -300.0
- RawReading rejects value above 100000.0
- RawReading accepts boundary values -300.0 and 100000.0
- RawReading normalizes variable name: strips and lowercases

### tests/test_pipeline.py

- parse_payload returns DevicePayload on valid JSON
- parse_payload returns None on invalid JSON (not parseable)
- parse_payload returns None when device_id missing
- parse_payload returns None when readings is empty list
- parse_payload returns None when value is out of range
- parse_payload never raises — catches all exceptions

### tests/test_storage.py

- upsert_device creates new record with correct fields
- upsert_device updates last_seen on second call
- upsert_device updates firmware when provided
- upsert_device leaves firmware unchanged when None passed
- insert_readings stores all readings in one call
- insert_readings is atomic: partial failure rolls back all rows
- get_latest returns most recent value per variable
- get_latest returns empty dict for unknown device
- get_history returns results newest-first
- get_history respects limit parameter
- get_history returns empty list for unknown device/variable
- list_devices returns all devices with correct reading_count
- list_devices includes latest values in correct structure
- purge_older_than deletes correct rows
- purge_older_than returns accurate deleted count
- purge_older_than does not delete device records

### tests/test_api.py

- GET /health returns 200 with status "ok"
- GET /devices returns 200 with empty list when no devices
- GET /devices returns correct device list after ingestion
- GET /devices/{id} returns 200 with correct device data
- GET /devices/{id} returns 404 for unknown device_id
- GET /devices/{id}/history returns 200 with correct readings
- GET /devices/{id}/history respects limit query param
- GET /devices/{id}/history filters by variable query param
- GET /metrics returns ingester counters

---

## Configuration

All settings are read from environment variables in `config.py`. Defaults allow the service to run locally with no configuration.

|Variable|Type|Default|Description|
|---|---|---|---|
|`MQTT_HOST`|str|`localhost`|MQTT broker hostname or IP|
|`MQTT_PORT`|int|`1883`|MQTT broker port|
|`MQTT_CLIENT_ID`|str|`mqtt_monitor`|paho client ID (must be unique per broker)|
|`MQTT_KEEPALIVE`|int|`60`|MQTT keepalive interval in seconds|
|`MQTT_TOPICS`|str|`devices/#`|Comma-separated topic subscriptions|
|`MQTT_RECONNECT_MIN`|float|`1.0`|Minimum reconnect delay in seconds|
|`MQTT_RECONNECT_MAX`|float|`60.0`|Maximum reconnect delay in seconds|
|`DB_PATH`|str|`telemetry.db`|SQLite file path (`:memory:` for tests)|
|`RETENTION_DAYS`|int|`7`|Delete readings older than N days|
|`PURGE_INTERVAL_HOURS`|int|`1`|How often to run purge job|
|`API_HOST`|str|`0.0.0.0`|uvicorn bind host|
|`API_PORT`|int|`8000`|uvicorn bind port|
|`INGESTER_WORKERS`|int|`3`|Number of async worker coroutines|
|`INGESTER_QUEUE_SIZE`|int|`500`|Max messages in async queue before dropping|
|`LOG_LEVEL`|str|`INFO`|Python logging level|
|`LOG_JSON`|bool|`false`|Emit logs as JSON (for log aggregators)|

```python
# config.py
import os

MQTT_HOST            = os.getenv("MQTT_HOST",           "localhost")
MQTT_PORT            = int(os.getenv("MQTT_PORT",        "1883"))
MQTT_CLIENT_ID       = os.getenv("MQTT_CLIENT_ID",      "mqtt_monitor")
MQTT_KEEPALIVE       = int(os.getenv("MQTT_KEEPALIVE",  "60"))
MQTT_TOPICS          = os.getenv("MQTT_TOPICS",         "devices/#").split(",")
MQTT_RECONNECT_MIN   = float(os.getenv("MQTT_RECONNECT_MIN", "1.0"))
MQTT_RECONNECT_MAX   = float(os.getenv("MQTT_RECONNECT_MAX", "60.0"))

DB_PATH              = os.getenv("DB_PATH",             "telemetry.db")
RETENTION_DAYS       = int(os.getenv("RETENTION_DAYS",  "7"))
PURGE_INTERVAL_HOURS = int(os.getenv("PURGE_INTERVAL_HOURS", "1"))

API_HOST             = os.getenv("API_HOST",            "0.0.0.0")
API_PORT             = int(os.getenv("API_PORT",        "8000"))

INGESTER_WORKERS     = int(os.getenv("INGESTER_WORKERS",    "3"))
INGESTER_QUEUE_SIZE  = int(os.getenv("INGESTER_QUEUE_SIZE", "500"))

LOG_LEVEL            = os.getenv("LOG_LEVEL", "INFO")
LOG_JSON             = os.getenv("LOG_JSON",  "false").lower() == "true"
```

---

## Deployment

### Docker

```dockerfile
FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

RUN useradd -m appuser && chown -R appuser /app
USER appuser

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s \
  CMD python -c \
    "import urllib.request; urllib.request.urlopen('http://localhost:8000/health')"

EXPOSE 8000

CMD ["python", "-m", "mqtt_monitor"]
```

```bash
docker build -t mqtt-monitor:latest .

docker run -d \
  --name mqtt-monitor \
  -p 8000:8000 \
  -v /var/lib/mqtt-monitor:/data \
  -e DB_PATH=/data/telemetry.db \
  -e MQTT_HOST=192.168.1.10 \
  -e LOG_JSON=true \
  mqtt-monitor:latest
```

### docker-compose

```yaml
services:
  mosquitto:
    image: eclipse-mosquitto:2
    ports:
      - "1883:1883"
    volumes:
      - ./mosquitto.conf:/mosquitto/config/mosquitto.conf
      - mosquitto-data:/mosquitto/data
    restart: unless-stopped

  mqtt-monitor:
    build: .
    ports:
      - "8000:8000"
    environment:
      MQTT_HOST:   mosquitto
      DB_PATH:     /data/telemetry.db
      LOG_JSON:    "true"
      LOG_LEVEL:   INFO
    volumes:
      - monitor-data:/data
    depends_on:
      - mosquitto
    restart: unless-stopped

volumes:
  mosquitto-data:
  monitor-data:
```

### systemd (bare metal)

```ini
# /etc/systemd/system/mqtt-monitor.service
[Unit]
Description=MQTT Device Monitor
After=network.target mosquitto.service
Wants=mosquitto.service

[Service]
Type=simple
User=iot
WorkingDirectory=/opt/mqtt-monitor
EnvironmentFile=/etc/mqtt-monitor/env
ExecStart=/opt/mqtt-monitor/.venv/bin/python -m mqtt_monitor
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
LimitNOFILE=65536
MemoryMax=256M

[Install]
WantedBy=multi-user.target
```

```bash
# /etc/mqtt-monitor/env
MQTT_HOST=localhost
DB_PATH=/var/lib/mqtt-monitor/telemetry.db
LOG_JSON=true
LOG_LEVEL=INFO
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable mqtt-monitor
sudo systemctl start mqtt-monitor
sudo journalctl -u mqtt-monitor -f
```

```

---

That's your complete design document. Every decision is explicit and every interface is defined before a line of implementation exists.

When you're ready, say "Day 30" and we build the entire system from this document.
```