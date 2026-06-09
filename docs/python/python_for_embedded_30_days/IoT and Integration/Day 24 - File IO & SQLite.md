### Config files — `configparser` and TOML

python

```python
# configparser — INI-style, built-in
import configparser

config = configparser.ConfigParser()
config.read("mqtt_monitor.ini")

host = config.get("broker", "host", fallback="localhost")
port = config.getint("broker", "port", fallback=1883)
```

ini

```ini
# mqtt_monitor.ini
[broker]
host = localhost
port = 1883

[database]
path = /var/lib/mqtt_monitor/telemetry.db
```

python

```python
# TOML — Python 3.11+ built-in (tomllib for read, tomli-w for write)
import tomllib

with open("config.toml", "rb") as f:
    config = tomllib.load(f)

host = config["broker"]["host"]
```

---

### SQLite — local persistent storage

SQLite requires no server, stores everything in a single file, and is production-quality for embedded/IoT use cases (it's used inside Android, iOS, Firefox, and countless embedded systems):

python

```python
import sqlite3
import time
from contextlib import contextmanager
from typing import Iterator

DB_PATH = "telemetry.db"

def init_db(path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(path, check_same_thread=False)
    conn.execute("PRAGMA journal_mode=WAL")    # WAL = concurrent reads + writes
    conn.execute("PRAGMA synchronous=NORMAL")  # safe + fast (vs FULL)
    conn.execute("PRAGMA foreign_keys=ON")
    conn.execute("""
        CREATE TABLE IF NOT EXISTS readings (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id  TEXT    NOT NULL,
            variable   TEXT    NOT NULL,
            value      REAL    NOT NULL,
            ts         REAL    NOT NULL DEFAULT (unixepoch('now', 'subsec'))
        )
    """)
    conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_readings_device_ts
        ON readings(device_id, ts DESC)
    """)
    conn.commit()
    return conn

@contextmanager
def transaction(conn: sqlite3.Connection) -> Iterator[sqlite3.Cursor]:
    cur = conn.cursor()
    try:
        yield cur
        conn.commit()
    except Exception:
        conn.rollback()
        raise
```

WAL (Write-Ahead Log) mode is critical in IoT use: it allows concurrent readers while a write is in progress, and a crash during a write doesn't corrupt the database.

---

### Efficient batch inserts

python

```python
def insert_readings_batch(conn: sqlite3.Connection, readings: list[dict]) -> None:
    with transaction(conn) as cur:
        cur.executemany(
            "INSERT INTO readings (device_id, variable, value, ts) VALUES (?,?,?,?)",
            [(r["device_id"], r["variable"], r["value"], r["ts"]) for r in readings],
        )

# Single insert inside a transaction is MUCH faster than one transaction per insert
# 1000 inserts in one transaction: ~5ms
# 1000 inserts in 1000 transactions: ~500ms (disk sync per transaction)
```

---

### Querying with row factories

python

```python
conn.row_factory = sqlite3.Row   # rows behave like dicts

def latest_readings(conn: sqlite3.Connection, device_id: str, n: int = 10):
    cur = conn.execute(
        """
        SELECT device_id, variable, value, ts
        FROM readings
        WHERE device_id = ?
        ORDER BY ts DESC
        LIMIT ?
        """,
        (device_id, n),
    )
    return [dict(row) for row in cur.fetchall()]

def device_summary(conn: sqlite3.Connection) -> list[dict]:
    cur = conn.execute(
        """
        SELECT
            device_id,
            COUNT(*)            AS total,
            AVG(value)          AS avg_value,
            MAX(ts)             AS last_seen
        FROM readings
        GROUP BY device_id
        ORDER BY last_seen DESC
        """
    )
    return [dict(row) for row in cur.fetchall()]
```

---

### Today's deliverable

python

```python
# telemetry_store.py
import sqlite3
import time
import random
import json
from contextlib import contextmanager
from typing import Iterator, Optional


DB_SCHEMA = """
CREATE TABLE IF NOT EXISTS devices (
    device_id   TEXT PRIMARY KEY,
    first_seen  REAL NOT NULL,
    last_seen   REAL NOT NULL,
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
    ON readings(device_id, ts DESC);

CREATE INDEX IF NOT EXISTS idx_readings_variable
    ON readings(variable, ts DESC);
"""


class TelemetryDB:
    def __init__(self, path: str = ":memory:") -> None:
        self._conn = sqlite3.connect(path, check_same_thread=False)
        self._conn.row_factory = sqlite3.Row
        self._conn.execute("PRAGMA journal_mode=WAL")
        self._conn.execute("PRAGMA synchronous=NORMAL")
        self._conn.execute("PRAGMA foreign_keys=ON")
        for statement in DB_SCHEMA.strip().split(";"):
            if statement.strip():
                self._conn.execute(statement)
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

    def upsert_device(self, device_id: str, firmware: Optional[str] = None) -> None:
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

    def insert_readings(self, readings: list[dict]) -> None:
        if not readings:
            return
        with self._tx() as cur:
            cur.executemany(
                "INSERT INTO readings (device_id, variable, value, ts) VALUES (?,?,?,?)",
                [(r["device_id"], r["variable"], r["value"], r["ts"]) for r in readings],
            )

    def latest(self, device_id: str, variable: str, n: int = 1) -> list[dict]:
        cur = self._conn.execute(
            """
            SELECT device_id, variable, value, ts
            FROM readings
            WHERE device_id = ? AND variable = ?
            ORDER BY ts DESC LIMIT ?
            """,
            (device_id, variable, n),
        )
        return [dict(row) for row in cur.fetchall()]

    def summary(self) -> list[dict]:
        cur = self._conn.execute(
            """
            SELECT
                d.device_id,
                d.firmware,
                d.last_seen,
                COUNT(r.id)         AS reading_count,
                AVG(r.value)        AS avg_value
            FROM devices d
            LEFT JOIN readings r ON r.device_id = d.device_id
            GROUP BY d.device_id
            ORDER BY d.last_seen DESC
            """
        )
        return [dict(row) for row in cur.fetchall()]

    def purge_older_than(self, seconds: float) -> int:
        cutoff = time.time() - seconds
        with self._tx() as cur:
            cur.execute("DELETE FROM readings WHERE ts < ?", (cutoff,))
            return cur.rowcount

    def close(self) -> None:
        self._conn.close()


if __name__ == "__main__":
    random.seed(42)
    db = TelemetryDB(":memory:")

    # Simulate ingestion from 5 devices
    devices   = [f"dev_{i:02d}" for i in range(5)]
    variables = ["temperature", "humidity", "pressure"]

    for device in devices:
        db.upsert_device(device, firmware=f"v1.{random.randint(0,9)}.0")

    readings = []
    base_ts  = time.time() - 300
    for i in range(500):
        readings.append({
            "device_id": random.choice(devices),
            "variable":  random.choice(variables),
            "value":     round(20 + random.gauss(0, 5), 4),
            "ts":        base_ts + i * 0.6,
        })

    t0 = time.perf_counter()
    db.insert_readings(readings)
    insert_time = time.perf_counter() - t0
    print(f"Inserted 500 readings in {insert_time*1000:.1f}ms")

    # Query
    print("\nLatest temperature for dev_00:")
    for r in db.latest("dev_00", "temperature", n=3):
        print(f"  {r}")

    print("\nDevice summary:")
    for row in db.summary():
        print(f"  {row['device_id']} fw={row['firmware']} "
              f"readings={row['reading_count']} avg={round(row['avg_value'] or 0, 2)}")

    purged = db.purge_older_than(200)
    print(f"\nPurged {purged} readings older than 200s")

    db.close()
```
[[IoT and Integration]]