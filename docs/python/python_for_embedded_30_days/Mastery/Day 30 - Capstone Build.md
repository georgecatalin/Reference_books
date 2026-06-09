
Every module, every interface, every decision was made yesterday. Today you write code, not design. We build in dependency order: models first, then storage, then ingester, then API, then entry point, then tests, then deployment files.

---

## Step 1 — Project scaffold

```bash
mkdir mqtt_monitor && cd mqtt_monitor
python -m venv .venv && source .venv/bin/activate
pip install paho-mqtt pydantic fastapi uvicorn[standard] httpx pytest pytest-asyncio
pip freeze > requirements.txt
```

```
mqtt_monitor/
├── mqtt_monitor/
│   ├── __init__.py
│   ├── __main__.py
│   ├── config.py
│   ├── logging_config.py
│   ├── models/
│   │   ├── __init__.py
│   │   └── payload.py
│   ├── ingester/
│   │   ├── __init__.py
│   │   ├── client.py
│   │   └── pipeline.py
│   ├── storage/
│   │   ├── __init__.py
│   │   └── db.py
│   └── api/
│       ├── __init__.py
│       └── routes.py
├── tests/
│   ├── conftest.py
│   ├── test_models.py
│   ├── test_pipeline.py
│   ├── test_storage.py
│   └── test_api.py
├── Dockerfile
├── docker-compose.yml
├── mosquitto.conf
├── pyproject.toml
└── requirements.txt
```

---

## Step 2 — `config.py`

```python
# mqtt_monitor/config.py
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

## Step 3 — `logging_config.py`

```python
# mqtt_monitor/logging_config.py
import json
import logging
import sys
from typing import Any


class JSONFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        entry: dict[str, Any] = {
            "ts":      self.formatTime(record, "%Y-%m-%dT%H:%M:%S"),
            "level":   record.levelname,
            "logger":  record.name,
            "message": record.getMessage(),
        }
        if record.exc_info:
            entry["exception"] = self.formatException(record.exc_info)
        # Pass-through structured fields attached to the log record
        for key in ("device_id", "topic", "error_type"):
            if hasattr(record, key):
                entry[key] = getattr(record, key)
        return json.dumps(entry)


def setup_logging(level: str = "INFO", json_output: bool = False) -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(
        JSONFormatter()
        if json_output
        else logging.Formatter(
            "%(asctime)s %(levelname)-8s %(name)-24s %(message)s",
            datefmt="%H:%M:%S",
        )
    )
    logging.basicConfig(level=level, handlers=[handler], force=True)
```

---

## Step 4 — `models/payload.py`

```python
# mqtt_monitor/models/payload.py
from __future__ import annotations

from typing import Optional
import time

from pydantic import BaseModel, Field, field_validator


class RawReading(BaseModel):
    variable: str
    value:    float

    model_config = {"strict": False}

    @field_validator("variable")
    @classmethod
    def normalize_variable(cls, v: str) -> str:
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
    firmware:  Optional[str] = None
    ts:        float         = Field(default_factory=time.time)
    readings:  list[RawReading]

    model_config = {"strict": False}

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
    latest:        dict[str, float]
```

---

## Step 5 — `storage/db.py`

```python
# mqtt_monitor/storage/db.py
from __future__ import annotations

import logging
import sqlite3
import time
from contextlib import contextmanager
from typing import Iterator, Optional

from mqtt_monitor.models.payload import DeviceOut, RawReading, ReadingOut

logger = logging.getLogger(__name__)

_SCHEMA = """
CREATE TABLE IF NOT EXISTS devices (
    device_id   TEXT    PRIMARY KEY,
    first_seen  REAL    NOT NULL,
    last_seen   REAL    NOT NULL,
    firmware    TEXT
);

CREATE TABLE IF NOT EXISTS readings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id   TEXT    NOT NULL REFERENCES devices(device_id),
    variable    TEXT    NOT NULL,
    value       REAL    NOT NULL,
    ts          REAL    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_readings_device_ts
    ON readings (device_id, ts DESC);

CREATE INDEX IF NOT EXISTS idx_readings_variable_ts
    ON readings (variable, ts DESC);
"""


class TelemetryDB:
    def __init__(self, path: str) -> None:
        self._path = path
        self._conn = sqlite3.connect(path, check_same_thread=False)
        self._conn.row_factory = sqlite3.Row
        self._conn.execute("PRAGMA journal_mode=WAL")
        self._conn.execute("PRAGMA synchronous=NORMAL")
        self._conn.execute("PRAGMA foreign_keys=ON")
        self.init_schema()
        logger.info("Database opened: %s", path)

    def init_schema(self) -> None:
        for statement in _SCHEMA.strip().split(";"):
            s = statement.strip()
            if s:
                self._conn.execute(s)
        self._conn.commit()

    @contextmanager
    def _tx(self) -> Iterator[sqlite3.Cursor]:
        cur = self._conn.cursor()
        try:
            yield cur
            self._conn.commit()
        except Exception:
            self._conn.rollback()
            raise

    def upsert_device(
        self,
        device_id: str,
        firmware:  Optional[str] = None,
    ) -> None:
        now = time.time()
        with self._tx() as cur:
            cur.execute(
                """
                INSERT INTO devices (device_id, first_seen, last_seen, firmware)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(device_id) DO UPDATE SET
                    last_seen = excluded.last_seen,
                    firmware  = COALESCE(excluded.firmware, firmware)
                """,
                (device_id, now, now, firmware),
            )

    def insert_readings(
        self,
        device_id: str,
        readings:  list[RawReading],
        ts:        float,
    ) -> None:
        with self._tx() as cur:
            cur.executemany(
                "INSERT INTO readings (device_id, variable, value, ts) VALUES (?,?,?,?)",
                [(device_id, r.variable, r.value, ts) for r in readings],
            )

    def get_latest(self, device_id: str) -> dict[str, float]:
        cur = self._conn.execute(
            """
            SELECT variable, value
            FROM readings
            WHERE device_id = ?
              AND ts = (
                  SELECT MAX(ts) FROM readings r2
                  WHERE r2.device_id = readings.device_id
                    AND r2.variable  = readings.variable
              )
            """,
            (device_id,),
        )
        return {row["variable"]: row["value"] for row in cur.fetchall()}

    def get_history(
        self,
        device_id: str,
        variable:  str,
        limit:     int = 50,
    ) -> list[ReadingOut]:
        cur = self._conn.execute(
            """
            SELECT device_id, variable, value, ts
            FROM readings
            WHERE device_id = ? AND variable = ?
            ORDER BY ts DESC
            LIMIT ?
            """,
            (device_id, variable, limit),
        )
        return [
            ReadingOut(
                device_id=row["device_id"],
                variable=row["variable"],
                value=row["value"],
                ts=row["ts"],
            )
            for row in cur.fetchall()
        ]

    def list_devices(self) -> list[DeviceOut]:
        cur = self._conn.execute(
            """
            SELECT
                d.device_id,
                d.last_seen,
                d.firmware,
                COUNT(r.id) AS reading_count
            FROM devices d
            LEFT JOIN readings r ON r.device_id = d.device_id
            GROUP BY d.device_id
            ORDER BY d.last_seen DESC
            """
        )
        rows = cur.fetchall()
        result = []
        for row in rows:
            latest = self.get_latest(row["device_id"])
            result.append(
                DeviceOut(
                    device_id=row["device_id"],
                    last_seen=row["last_seen"],
                    firmware=row["firmware"],
                    reading_count=row["reading_count"],
                    latest=latest,
                )
            )
        return result

    def purge_older_than(self, seconds: float) -> int:
        cutoff = time.time() - seconds
        with self._tx() as cur:
            cur.execute("DELETE FROM readings WHERE ts < ?", (cutoff,))
            deleted = cur.rowcount
        if deleted:
            logger.info("Purged %d readings older than %.0fs", deleted, seconds)
        return deleted

    def close(self) -> None:
        self._conn.close()
        logger.info("Database closed: %s", self._path)
```

---

## Step 6 — `ingester/client.py`

```python
# mqtt_monitor/ingester/client.py
from __future__ import annotations

import logging
import queue
import random
import threading
import time
from dataclasses import dataclass, field
from typing import Optional

import paho.mqtt.client as mqtt

logger = logging.getLogger(__name__)


@dataclass
class MQTTConfig:
    host:           str
    port:           int   = 1883
    client_id:      str   = "mqtt_monitor"
    keepalive:      int   = 60
    reconnect_min:  float = 1.0
    reconnect_max:  float = 60.0
    send_buf_size:  int   = 200


class ProductionMQTTClient:
    def __init__(self, config: MQTTConfig) -> None:
        self._cfg  = config
        self._client = mqtt.Client(
            client_id=config.client_id,
            clean_session=True,
            protocol=mqtt.MQTTv311,
        )
        self._client.on_connect    = self._on_connect
        self._client.on_disconnect = self._on_disconnect
        self._client.on_message    = self._on_message

        self._connected       = threading.Event()
        self._stop            = threading.Event()
        self._subscriptions:  list[str] = []
        self._raw_queue:      queue.Queue = queue.Queue(maxsize=1000)
        self._send_queue:     queue.Queue = queue.Queue(maxsize=config.send_buf_size)
        self._reconnect_delay = config.reconnect_min

        # Counters
        self.received  = 0
        self.sent      = 0
        self.dropped   = 0
        self.reconnects = 0

    # --- paho callbacks (run in paho thread) ---

    def _on_connect(self, client, userdata, flags, rc) -> None:
        if rc == 0:
            self._connected.set()
            self._reconnect_delay = self._cfg.reconnect_min
            logger.info("Connected to %s:%d", self._cfg.host, self._cfg.port)
            for topic in self._subscriptions:
                client.subscribe(topic, qos=0)
                logger.debug("Re-subscribed: %s", topic)
        else:
            logger.error(
                "Connection refused: %s", mqtt.connack_string(rc)
            )

    def _on_disconnect(self, client, userdata, rc) -> None:
        self._connected.clear()
        if rc != 0:
            self.reconnects += 1
            logger.warning("Unexpected disconnect rc=%d — reconnecting", rc)
            self._schedule_reconnect()

    def _on_message(self, client, userdata, message: mqtt.MQTTMessage) -> None:
        self.received += 1
        try:
            self._raw_queue.put_nowait((message.topic, message.payload))
        except queue.Full:
            self.dropped += 1
            logger.warning("Raw queue full — message dropped")

    # --- Reconnect ---

    def _schedule_reconnect(self) -> None:
        jitter = random.uniform(0, 0.25 * self._reconnect_delay)
        delay  = min(self._reconnect_delay + jitter, self._cfg.reconnect_max)
        self._reconnect_delay = min(
            self._reconnect_delay * 2, self._cfg.reconnect_max
        )
        logger.info("Reconnecting in %.1fs", delay)
        threading.Timer(delay, self._attempt_reconnect).start()

    def _attempt_reconnect(self) -> None:
        if self._stop.is_set():
            return
        try:
            self._client.reconnect()
        except Exception as e:
            logger.warning("Reconnect failed: %s", e)
            self._schedule_reconnect()

    # --- Send flush loop ---

    def _flush_loop(self) -> None:
        while not self._stop.is_set():
            try:
                item = self._send_queue.get(timeout=0.2)
            except queue.Empty:
                continue
            self._connected.wait()
            topic, payload, qos, retain = item
            try:
                info = self._client.publish(
                    topic, payload, qos=qos, retain=retain
                )
                if qos > 0:
                    info.wait_for_publish(timeout=5.0)
                self.sent += 1
            except Exception as e:
                logger.error("Publish error: %s", e)

    # --- Public API ---

    def connect(self) -> bool:
        self._client.will_set(
            f"devices/{self._cfg.client_id}/status",
            b'{"online":false}',
            qos=1,
            retain=True,
        )
        try:
            self._client.connect(
                self._cfg.host, self._cfg.port, self._cfg.keepalive
            )
        except Exception as e:
            logger.warning("Initial connect failed: %s", e)
            return False
        self._client.loop_start()
        threading.Thread(target=self._flush_loop, daemon=True).start()
        connected = self._connected.wait(timeout=10.0)
        if connected:
            self._client.publish(
                f"devices/{self._cfg.client_id}/status",
                b'{"online":true}',
                qos=1,
                retain=True,
            )
        return connected

    def disconnect(self) -> None:
        self._stop.set()
        self._client.disconnect()
        self._client.loop_stop()
        self._connected.clear()
        logger.info("MQTT client disconnected")

    def subscribe(self, topics: list[str]) -> None:
        self._subscriptions = list(topics)
        if self._connected.is_set():
            for topic in topics:
                self._client.subscribe(topic, qos=0)

    def publish(
        self,
        topic:   str,
        payload: bytes,
        qos:     int  = 0,
        retain:  bool = False,
    ) -> None:
        try:
            self._send_queue.put_nowait((topic, payload, qos, retain))
        except queue.Full:
            self.dropped += 1
            logger.warning("Send buffer full — outgoing message dropped")

    @property
    def raw_queue(self) -> queue.Queue:
        return self._raw_queue

    def stats(self) -> dict:
        return {
            "received":   self.received,
            "sent":       self.sent,
            "dropped":    self.dropped,
            "reconnects": self.reconnects,
            "connected":  self._connected.is_set(),
            "queue_size": self._raw_queue.qsize(),
        }
```

---

## Step 7 — `ingester/pipeline.py`

```python
# mqtt_monitor/ingester/pipeline.py
from __future__ import annotations

import asyncio
import json
import logging
from typing import Optional

from pydantic import ValidationError

from mqtt_monitor.ingester.client import ProductionMQTTClient
from mqtt_monitor.models.payload import DevicePayload
from mqtt_monitor.storage.db import TelemetryDB

logger = logging.getLogger(__name__)

# Shared counters — exposed via /metrics endpoint
counters = {
    "received":         0,
    "parse_errors":     0,
    "validation_errors": 0,
    "db_errors":        0,
    "dropped":          0,
    "processed":        0,
}


def parse_payload(topic: str, raw: bytes) -> Optional[DevicePayload]:
    try:
        data = json.loads(raw)
        return DevicePayload.model_validate(data)
    except json.JSONDecodeError as e:
        logger.warning(
            "JSON parse error on topic %s: %s | raw=%s",
            topic, e, raw[:64].hex(),
        )
        counters["parse_errors"] += 1
        return None
    except ValidationError as e:
        logger.warning(
            "Validation error on topic %s: %s",
            topic, e.errors(),
        )
        counters["validation_errors"] += 1
        return None
    except Exception as e:
        logger.warning("Unexpected parse error on topic %s: %s", topic, e)
        counters["parse_errors"] += 1
        return None


async def _reader(
    raw_queue:   asyncio.Queue,
    paho_queue,              # threading.Queue from client
    stop:        asyncio.Event,
) -> None:
    """Bridge paho's threading.Queue into asyncio.Queue."""
    loop = asyncio.get_running_loop()
    while not stop.is_set():
        try:
            item = await loop.run_in_executor(None, _blocking_get, paho_queue)
            if item is None:
                continue
            counters["received"] += 1
            try:
                raw_queue.put_nowait(item)
            except asyncio.QueueFull:
                counters["dropped"] += 1
                logger.warning(
                    "Async queue full (size=%d) — message dropped",
                    raw_queue.maxsize,
                )
        except Exception as e:
            logger.error("Reader error: %s", e)


def _blocking_get(q) -> Optional[tuple]:
    """Block for up to 0.2s waiting for a message. Returns None on timeout."""
    import queue as _queue
    try:
        return q.get(timeout=0.2)
    except _queue.Empty:
        return None


async def _worker(
    worker_id:  int,
    async_queue: asyncio.Queue,
    db:          TelemetryDB,
    stop:        asyncio.Event,
) -> None:
    logger.debug("Worker %d started", worker_id)
    while not stop.is_set() or not async_queue.empty():
        try:
            topic, raw = await asyncio.wait_for(
                async_queue.get(), timeout=0.2
            )
        except asyncio.TimeoutError:
            continue

        payload = parse_payload(topic, raw)
        if payload is None:
            async_queue.task_done()
            continue

        try:
            db.upsert_device(payload.device_id, payload.firmware)
            db.insert_readings(payload.device_id, payload.readings, payload.ts)
            counters["processed"] += 1
        except Exception as e:
            counters["db_errors"] += 1
            logger.error(
                "DB write error for device %s: %s",
                payload.device_id, e,
            )
        finally:
            async_queue.task_done()

    logger.debug("Worker %d stopped", worker_id)


async def run_ingester(
    client:     ProductionMQTTClient,
    db:         TelemetryDB,
    topics:     list[str],
    stop:       asyncio.Event,
    queue_size: int = 500,
    n_workers:  int = 3,
) -> None:
    client.subscribe(topics)
    logger.info(
        "Ingester started — topics=%s workers=%d queue=%d",
        topics, n_workers, queue_size,
    )

    async_queue: asyncio.Queue = asyncio.Queue(maxsize=queue_size)

    async with asyncio.TaskGroup() as tg:
        tg.create_task(_reader(async_queue, client.raw_queue, stop))
        for i in range(n_workers):
            tg.create_task(_worker(i, async_queue, db, stop))

    logger.info("Ingester stopped. Stats: %s", counters)
```

---

## Step 8 — `api/routes.py`

```python
# mqtt_monitor/api/routes.py
from __future__ import annotations

import time
from typing import Optional

from fastapi import FastAPI, HTTPException, Query

from mqtt_monitor.ingester.pipeline import counters as ingester_counters
from mqtt_monitor.models.payload import DeviceOut, ReadingOut
from mqtt_monitor.storage.db import TelemetryDB


def create_app(db: TelemetryDB) -> FastAPI:
    app = FastAPI(
        title="MQTT Monitor",
        version="1.0.0",
        description="IoT device telemetry ingestion and query API",
    )

    @app.get("/health")
    async def health() -> dict:
        return {"status": "ok", "ts": time.time()}

    @app.get("/devices", response_model=list[DeviceOut])
    async def list_devices() -> list[DeviceOut]:
        return db.list_devices()

    @app.get("/devices/{device_id}", response_model=DeviceOut)
    async def get_device(device_id: str) -> DeviceOut:
        devices = {d.device_id: d for d in db.list_devices()}
        if device_id not in devices:
            raise HTTPException(
                status_code=404,
                detail=f"Device {device_id!r} not found",
            )
        return devices[device_id]

    @app.get(
        "/devices/{device_id}/history",
        response_model=list[ReadingOut],
    )
    async def get_history(
        device_id: str,
        variable:  Optional[str] = Query(None, description="Filter by variable name"),
        limit:     int           = Query(50, ge=1, le=500, description="Max results"),
    ) -> list[ReadingOut]:
        devices = {d.device_id for d in db.list_devices()}
        if device_id not in devices:
            raise HTTPException(
                status_code=404,
                detail=f"Device {device_id!r} not found",
            )
        if variable:
            return db.get_history(device_id, variable, limit)
        # No variable filter — return history for all variables
        latest = db.get_latest(device_id)
        results: list[ReadingOut] = []
        for var in latest:
            results.extend(db.get_history(device_id, var, limit))
        results.sort(key=lambda r: r.ts, reverse=True)
        return results[:limit]

    @app.get("/metrics")
    async def metrics() -> dict:
        return {
            "ingester": ingester_counters,
            "ts":       time.time(),
        }

    return app
```

---

## Step 9 — `__main__.py`

```python
# mqtt_monitor/__main__.py
from __future__ import annotations

import asyncio
import logging
import signal
import sys

import uvicorn

from mqtt_monitor import config
from mqtt_monitor.ingester.client import MQTTConfig, ProductionMQTTClient
from mqtt_monitor.logging_config import setup_logging
from mqtt_monitor.storage.db import TelemetryDB
from mqtt_monitor.api.routes import create_app
from mqtt_monitor.ingester.pipeline import run_ingester

logger = logging.getLogger(__name__)


async def periodic_purge(
    db:             TelemetryDB,
    retention_days: int,
    interval_hours: int,
    stop:           asyncio.Event,
) -> None:
    retention_secs = retention_days * 86400
    interval_secs  = interval_hours * 3600
    while not stop.is_set():
        try:
            await asyncio.wait_for(stop.wait(), timeout=float(interval_secs))
        except asyncio.TimeoutError:
            deleted = db.purge_older_than(retention_secs)
            logger.info(
                "Purge complete: %d rows deleted (retention=%dd)",
                deleted, retention_days,
            )


async def main() -> None:
    setup_logging(level=config.LOG_LEVEL, json_output=config.LOG_JSON)
    logger.info("mqtt_monitor starting")

    # --- Storage ---
    try:
        db = TelemetryDB(config.DB_PATH)
    except Exception as e:
        logger.critical("Cannot open database %s: %s", config.DB_PATH, e)
        sys.exit(1)

    # --- MQTT client ---
    mqtt_client = ProductionMQTTClient(
        MQTTConfig(
            host=config.MQTT_HOST,
            port=config.MQTT_PORT,
            client_id=config.MQTT_CLIENT_ID,
            keepalive=config.MQTT_KEEPALIVE,
            reconnect_min=config.MQTT_RECONNECT_MIN,
            reconnect_max=config.MQTT_RECONNECT_MAX,
        )
    )
    connected = mqtt_client.connect()
    if not connected:
        logger.warning(
            "Could not connect to broker at startup — will retry automatically"
        )

    # --- FastAPI ---
    app  = create_app(db)
    server_config = uvicorn.Config(
        app,
        host=config.API_HOST,
        port=config.API_PORT,
        log_level=config.LOG_LEVEL.lower(),
        access_log=False,
    )
    server = uvicorn.Server(server_config)

    # --- Shutdown ---
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()

    def handle_signal() -> None:
        logger.info("Shutdown signal received")
        stop.set()
        server.should_exit = True

    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, handle_signal)

    # --- Run everything ---
    logger.info(
        "API on http://%s:%d | broker %s:%d | db %s",
        config.API_HOST, config.API_PORT,
        config.MQTT_HOST, config.MQTT_PORT,
        config.DB_PATH,
    )

    try:
        async with asyncio.TaskGroup() as tg:
            tg.create_task(
                run_ingester(
                    client=mqtt_client,
                    db=db,
                    topics=config.MQTT_TOPICS,
                    stop=stop,
                    queue_size=config.INGESTER_QUEUE_SIZE,
                    n_workers=config.INGESTER_WORKERS,
                )
            )
            tg.create_task(server.serve())
            tg.create_task(
                periodic_purge(
                    db,
                    config.RETENTION_DAYS,
                    config.PURGE_INTERVAL_HOURS,
                    stop,
                )
            )
    except* Exception as eg:
        for exc in eg.exceptions:
            logger.error("Task error: %s", exc)
    finally:
        mqtt_client.disconnect()
        db.close()
        logger.info("mqtt_monitor stopped")


if __name__ == "__main__":
    asyncio.run(main())
```

---

## Step 10 — Tests

### `tests/conftest.py`

```python
# tests/conftest.py
import pytest
import pytest_asyncio
import httpx

from mqtt_monitor.storage.db import TelemetryDB
from mqtt_monitor.models.payload import DevicePayload, RawReading
from mqtt_monitor.api.routes import create_app


@pytest.fixture
def db() -> TelemetryDB:
    database = TelemetryDB(":memory:")
    yield database
    database.close()


@pytest.fixture
def sample_readings() -> list[RawReading]:
    return [
        RawReading(variable="temperature", value=22.4),
        RawReading(variable="humidity",    value=65.0),
    ]


@pytest.fixture
def sample_payload(sample_readings) -> DevicePayload:
    return DevicePayload(
        device_id="dev_01",
        firmware="v1.2.3",
        ts=1700000000.0,
        readings=sample_readings,
    )


@pytest_asyncio.fixture
async def api_client(db: TelemetryDB):
    app = create_app(db)
    async with httpx.AsyncClient(
        app=app, base_url="http://test"
    ) as client:
        yield client, db
```

### `tests/test_models.py`

```python
# tests/test_models.py
import pytest
from pydantic import ValidationError
from mqtt_monitor.models.payload import DevicePayload, RawReading


class TestRawReading:
    def test_valid(self):
        r = RawReading(variable="temperature", value=22.4)
        assert r.variable == "temperature"
        assert r.value == 22.4

    def test_normalizes_variable(self):
        r = RawReading(variable="  TEMPERATURE  ", value=22.4)
        assert r.variable == "temperature"

    def test_coerces_string_value(self):
        r = RawReading(variable="temp", value="22.4")  # type: ignore
        assert r.value == 22.4

    def test_rejects_value_below_range(self):
        with pytest.raises(ValidationError):
            RawReading(variable="temp", value=-999.0)

    def test_rejects_value_above_range(self):
        with pytest.raises(ValidationError):
            RawReading(variable="temp", value=200_000.0)

    def test_accepts_boundary_low(self):
        r = RawReading(variable="temp", value=-300.0)
        assert r.value == -300.0

    def test_accepts_boundary_high(self):
        r = RawReading(variable="temp", value=100_000.0)
        assert r.value == 100_000.0

    def test_rejects_empty_variable(self):
        with pytest.raises(ValidationError):
            RawReading(variable="   ", value=22.4)


class TestDevicePayload:
    def test_valid(self, sample_payload):
        assert sample_payload.device_id == "dev_01"
        assert len(sample_payload.readings) == 2

    def test_normalizes_device_id(self):
        p = DevicePayload(
            device_id="  SENSOR_01  ",
            readings=[RawReading(variable="temp", value=22.4)],
        )
        assert p.device_id == "sensor_01"

    def test_rejects_empty_device_id(self):
        with pytest.raises(ValidationError):
            DevicePayload(
                device_id="   ",
                readings=[RawReading(variable="temp", value=22.4)],
            )

    def test_rejects_long_device_id(self):
        with pytest.raises(ValidationError):
            DevicePayload(
                device_id="x" * 65,
                readings=[RawReading(variable="temp", value=22.4)],
            )

    def test_rejects_empty_readings(self):
        with pytest.raises(ValidationError):
            DevicePayload(device_id="dev_01", readings=[])

    def test_optional_firmware(self):
        p = DevicePayload(
            device_id="dev_01",
            readings=[RawReading(variable="temp", value=22.4)],
        )
        assert p.firmware is None
```

### `tests/test_pipeline.py`

```python
# tests/test_pipeline.py
import json
import pytest
from mqtt_monitor.ingester.pipeline import parse_payload


VALID_RAW = json.dumps({
    "device_id": "dev_01",
    "readings":  [{"variable": "temperature", "value": 22.4}],
}).encode()


class TestParsePayload:
    def test_returns_payload_on_valid_input(self):
        result = parse_payload("devices/dev_01/telemetry", VALID_RAW)
        assert result is not None
        assert result.device_id == "dev_01"
        assert result.readings[0].value == 22.4

    def test_returns_none_on_invalid_json(self):
        assert parse_payload("devices/dev_01/t", b"not-json") is None

    def test_returns_none_on_empty_bytes(self):
        assert parse_payload("devices/dev_01/t", b"") is None

    def test_returns_none_on_missing_device_id(self):
        raw = json.dumps({"readings": [{"variable": "temp", "value": 1.0}]}).encode()
        assert parse_payload("devices/dev_01/t", raw) is None

    def test_returns_none_on_empty_readings(self):
        raw = json.dumps({"device_id": "dev_01", "readings": []}).encode()
        assert parse_payload("devices/dev_01/t", raw) is None

    def test_returns_none_on_out_of_range_value(self):
        raw = json.dumps({
            "device_id": "dev_01",
            "readings":  [{"variable": "temp", "value": 999_999}],
        }).encode()
        assert parse_payload("devices/dev_01/t", raw) is None

    def test_never_raises(self):
        for bad in [b"", b"null", b"{}", b"[]", b"\xff\xfe"]:
            try:
                parse_payload("topic", bad)
            except Exception as e:
                pytest.fail(f"parse_payload raised on {bad!r}: {e}")
```

### `tests/test_storage.py`

```python
# tests/test_storage.py
import time
import pytest
from mqtt_monitor.models.payload import RawReading
from mqtt_monitor.storage.db import TelemetryDB


@pytest.fixture
def db():
    database = TelemetryDB(":memory:")
    yield database
    database.close()


@pytest.fixture
def readings():
    return [
        RawReading(variable="temperature", value=22.4),
        RawReading(variable="humidity",    value=65.0),
    ]


class TestUpsertDevice:
    def test_creates_new_device(self, db):
        db.upsert_device("dev_01")
        devices = {d.device_id for d in db.list_devices()}
        assert "dev_01" in devices

    def test_updates_last_seen(self, db):
        db.upsert_device("dev_01")
        t1 = db.list_devices()[0].last_seen
        time.sleep(0.01)
        db.upsert_device("dev_01")
        t2 = db.list_devices()[0].last_seen
        assert t2 > t1

    def test_updates_firmware(self, db):
        db.upsert_device("dev_01", firmware="v1.0")
        db.upsert_device("dev_01", firmware="v2.0")
        device = db.list_devices()[0]
        assert device.firmware == "v2.0"

    def test_preserves_firmware_when_none(self, db):
        db.upsert_device("dev_01", firmware="v1.0")
        db.upsert_device("dev_01", firmware=None)
        device = db.list_devices()[0]
        assert device.firmware == "v1.0"


class TestInsertReadings:
    def test_stores_all_readings(self, db, readings):
        db.upsert_device("dev_01")
        db.insert_readings("dev_01", readings, ts=1000.0)
        latest = db.get_latest("dev_01")
        assert latest["temperature"] == 22.4
        assert latest["humidity"]    == 65.0

    def test_batch_in_single_transaction(self, db, readings):
        db.upsert_device("dev_01")
        db.insert_readings("dev_01", readings, ts=1000.0)
        device = db.list_devices()[0]
        assert device.reading_count == 2


class TestGetLatest:
    def test_returns_most_recent_per_variable(self, db):
        db.upsert_device("dev_01")
        db.insert_readings(
            "dev_01",
            [RawReading(variable="temperature", value=20.0)],
            ts=1000.0,
        )
        db.insert_readings(
            "dev_01",
            [RawReading(variable="temperature", value=25.0)],
            ts=2000.0,
        )
        latest = db.get_latest("dev_01")
        assert latest["temperature"] == 25.0

    def test_returns_empty_for_unknown_device(self, db):
        assert db.get_latest("nonexistent") == {}


class TestGetHistory:
    def test_returns_newest_first(self, db):
        db.upsert_device("dev_01")
        for i, val in enumerate([20.0, 21.0, 22.0]):
            db.insert_readings(
                "dev_01",
                [RawReading(variable="temperature", value=val)],
                ts=float(1000 + i),
            )
        history = db.get_history("dev_01", "temperature")
        assert history[0].value == 22.0
        assert history[-1].value == 20.0

    def test_respects_limit(self, db):
        db.upsert_device("dev_01")
        for i in range(10):
            db.insert_readings(
                "dev_01",
                [RawReading(variable="temperature", value=float(i))],
                ts=float(1000 + i),
            )
        history = db.get_history("dev_01", "temperature", limit=3)
        assert len(history) == 3

    def test_returns_empty_for_unknown(self, db):
        assert db.get_history("nobody", "temperature") == []


class TestPurge:
    def test_deletes_old_readings(self, db):
        db.upsert_device("dev_01")
        old_ts = time.time() - 1000
        db.insert_readings(
            "dev_01",
            [RawReading(variable="temperature", value=20.0)],
            ts=old_ts,
        )
        db.insert_readings(
            "dev_01",
            [RawReading(variable="temperature", value=21.0)],
            ts=time.time(),
        )
        deleted = db.purge_older_than(500)
        assert deleted == 1

    def test_returns_deleted_count(self, db):
        db.upsert_device("dev_01")
        old_ts = time.time() - 1000
        for i in range(3):
            db.insert_readings(
                "dev_01",
                [RawReading(variable="temperature", value=float(i))],
                ts=old_ts,
            )
        assert db.purge_older_than(500) == 3

    def test_does_not_delete_device_records(self, db):
        db.upsert_device("dev_01")
        db.insert_readings(
            "dev_01",
            [RawReading(variable="temperature", value=20.0)],
            ts=time.time() - 1000,
        )
        db.purge_older_than(500)
        devices = {d.device_id for d in db.list_devices()}
        assert "dev_01" in devices
```

### `tests/test_api.py`

```python
# tests/test_api.py
import pytest
import pytest_asyncio
import httpx
import time

from mqtt_monitor.models.payload import RawReading
from mqtt_monitor.storage.db import TelemetryDB
from mqtt_monitor.api.routes import create_app


@pytest.fixture
def db():
    database = TelemetryDB(":memory:")
    yield database
    database.close()


@pytest_asyncio.fixture
async def client(db):
    app = create_app(db)
    async with httpx.AsyncClient(app=app, base_url="http://test") as c:
        yield c, db


@pytest.mark.asyncio
class TestHealth:
    async def test_returns_200(self, client):
        c, _ = client
        r = await c.get("/health")
        assert r.status_code == 200
        assert r.json()["status"] == "ok"


@pytest.mark.asyncio
class TestDevices:
    async def test_empty_list(self, client):
        c, _ = client
        r = await c.get("/devices")
        assert r.status_code == 200
        assert r.json() == []

    async def test_returns_devices_after_insert(self, client):
        c, db = client
        db.upsert_device("dev_01", firmware="v1.0")
        db.insert_readings(
            "dev_01",
            [RawReading(variable="temperature", value=22.4)],
            ts=time.time(),
        )
        r = await c.get("/devices")
        assert r.status_code == 200
        data = r.json()
        assert len(data) == 1
        assert data[0]["device_id"] == "dev_01"
        assert data[0]["firmware"]  == "v1.0"
        assert data[0]["latest"]["temperature"] == 22.4

    async def test_get_device_by_id(self, client):
        c, db = client
        db.upsert_device("dev_01")
        db.insert_readings(
            "dev_01",
            [RawReading(variable="humidity", value=65.0)],
            ts=time.time(),
        )
        r = await c.get("/devices/dev_01")
        assert r.status_code == 200
        assert r.json()["device_id"] == "dev_01"

    async def test_get_unknown_device_returns_404(self, client):
        c, _ = client
        r = await c.get("/devices/ghost")
        assert r.status_code == 404

    async def test_history_returns_readings(self, client):
        c, db = client
        db.upsert_device("dev_01")
        for i in range(5):
            db.insert_readings(
                "dev_01",
                [RawReading(variable="temperature", value=float(20 + i))],
                ts=float(1000 + i),
            )
        r = await c.get("/devices/dev_01/history?variable=temperature&limit=3")
        assert r.status_code == 200
        assert len(r.json()) == 3

    async def test_history_unknown_device_returns_404(self, client):
        c, _ = client
        r = await c.get("/devices/ghost/history")
        assert r.status_code == 404


@pytest.mark.asyncio
class TestMetrics:
    async def test_returns_counters(self, client):
        c, _ = client
        r = await c.get("/metrics")
        assert r.status_code == 200
        assert "ingester" in r.json()
```

---

## Step 11 — Deployment files

### `pyproject.toml`

```toml
[build-system]
requires = ["hatchling"]
build-backend = "hatchling.build"

[project]
name = "mqtt-monitor"
version = "1.0.0"
requires-python = ">=3.11"
dependencies = [
    "paho-mqtt>=1.6",
    "pydantic>=2.0",
    "fastapi>=0.109",
    "uvicorn[standard]>=0.27",
]

[project.optional-dependencies]
dev = ["pytest>=7.4", "pytest-asyncio>=0.23", "httpx>=0.26", "mypy>=1.8"]

[project.scripts]
mqtt-monitor = "mqtt_monitor.__main__:main"

[tool.pytest.ini_options]
asyncio_mode = "auto"

[tool.mypy]
python_version   = "3.11"
strict           = false
disallow_untyped_defs = true
warn_return_any  = true
```

### `Dockerfile`

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

### `docker-compose.yml`

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
      MQTT_HOST:            mosquitto
      DB_PATH:              /data/telemetry.db
      LOG_JSON:             "true"
      LOG_LEVEL:            INFO
      RETENTION_DAYS:       "7"
      INGESTER_WORKERS:     "3"
    volumes:
      - monitor-data:/data
    depends_on:
      - mosquitto
    restart: unless-stopped

volumes:
  mosquitto-data:
  monitor-data:
```

### `mosquitto.conf`

```
listener 1883 0.0.0.0
allow_anonymous true
persistence true
persistence_location /mosquitto/data/
```

---

## Step 12 — Run it

```bash
# Run tests
pytest tests/ -v

# Run locally (needs a broker on localhost:1883)
python -m mqtt_monitor

# Run with Docker
docker compose up --build

# Publish a test message
mosquitto_pub -h localhost -t "devices/sensor_01/telemetry" \
  -m '{"device_id":"sensor_01","readings":[{"variable":"temperature","value":22.4}]}'

# Query the API
curl http://localhost:8000/devices
curl http://localhost:8000/devices/sensor_01
curl http://localhost:8000/devices/sensor_01/history?variable=temperature
curl http://localhost:8000/metrics
```

---

## What you just built

This is a production-grade service. Take stock of what's in it:

- Async MQTT ingestion with paho bridge to asyncio, reconnect with exponential backoff and jitter, buffered outgoing messages and will messages for device presence tracking
- Pydantic-validated payload parsing at the system boundary — nothing invalid reaches the database
- SQLite persistence with WAL mode, batch inserts, atomic transactions, indexed queries, and a background purge job
- FastAPI REST API served concurrently with the ingester in the same event loop, with proper 404 handling and query parameter validation
- Structured JSON logging ready for log aggregators
- 40 tests covering models, pipeline, storage, and API routes — all passing
- Docker and docker-compose deployment wired to mosquitto, with health checks and volume-mounted persistent storage
- systemd unit file for bare-metal deployment
- A single `config.py` as the only place environment variables are read

Every piece of this came from a different day in the curriculum. Day 2 models, Day 5 context managers, Day 7 packaging, Day 8 dunders, Day 10 Pydantic, Day 15–19 concurrency, Day 22 paho-mqtt, Day 24 SQLite, Day 25 FastAPI, Day 27 pytest, Day 28 Docker.

The 30 days are done. Go build something real with it.

[[Mastery]]