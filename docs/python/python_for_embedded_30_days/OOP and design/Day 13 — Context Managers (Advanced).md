Day 5 covered the basics. Today covers the patterns you'll actually reach for in production IoT code.

---

### `ExitStack` — dynamic resource management

When you don't know at write-time how many resources you'll manage:

python

```python
from contextlib import ExitStack

def process_devices(device_configs: list[dict]) -> None:
    with ExitStack() as stack:
        # Open a connection per device — each registered for cleanup
        connections = [
            stack.enter_context(open_connection(cfg["host"], cfg["port"]))
            for cfg in device_configs
        ]
        # If any open_connection raises, all already-opened ones are closed
        for conn in connections:
            conn.poll()
```

`ExitStack` is also useful for optional context managers:

python

```python
def run(debug: bool = False) -> None:
    with ExitStack() as stack:
        if debug:
            stack.enter_context(enable_debug_logging())
        stack.enter_context(open_mqtt_session())
        main_loop()
```

---

### `asynccontextmanager` — async resource management

You'll need this on Day 18 when asyncio arrives. Preview it now:

python

```python
from contextlib import asynccontextmanager
import asyncio

@asynccontextmanager
async def async_mqtt_session(host: str, port: int):
    client = AsyncMQTTClient(host, port)
    await client.connect()
    try:
        yield client
    finally:
        await client.disconnect()

async def main():
    async with async_mqtt_session("localhost", 1883) as client:
        await client.publish("test/topic", b"hello")
```

Same `try/yield/finally` pattern — just with `async/await`.

---

### Context managers for transaction-like patterns

python

```python
from contextlib import contextmanager
from typing import Iterator
import sqlite3

@contextmanager
def db_transaction(conn: sqlite3.Connection) -> Iterator[sqlite3.Cursor]:
    cursor = conn.cursor()
    try:
        yield cursor
        conn.commit()    # only reached if no exception
    except Exception:
        conn.rollback()  # exception path — undo everything
        raise            # re-raise — don't swallow it

with db_transaction(conn) as cur:
    cur.execute("INSERT INTO readings VALUES (?, ?, ?)", (dev_id, var, val))
    cur.execute("UPDATE devices SET last_seen=? WHERE id=?", (ts, dev_id))
    # Both execute or neither — atomicity guaranteed
```

---

### Today's deliverable

python

```python
# resource_manager.py
from contextlib import contextmanager, ExitStack
from typing import Iterator, Optional
import time, random, threading

class SimulatedDB:
    def __init__(self, name: str) -> None:
        self.name = name
        self._open = False
        self._data: list[tuple] = []

    def open(self) -> None:
        self._open = True
        print(f"  [db:{self.name}] opened")

    def close(self) -> None:
        self._open = False
        print(f"  [db:{self.name}] closed")

    def insert(self, row: tuple) -> None:
        if not self._open:
            raise RuntimeError("DB not open")
        self._data.append(row)

    def count(self) -> int:
        return len(self._data)


@contextmanager
def open_db(name: str) -> Iterator[SimulatedDB]:
    db = SimulatedDB(name)
    db.open()
    try:
        yield db
    finally:
        db.close()


@contextmanager
def db_batch(db: SimulatedDB) -> Iterator[list]:
    """Collect rows and insert atomically; rollback (skip) on error."""
    batch: list[tuple] = []
    try:
        yield batch
        for row in batch:
            db.insert(row)
        print(f"  [batch] committed {len(batch)} rows")
    except Exception as e:
        print(f"  [batch] rolled back: {e}")
        raise


def process_all(db_configs: list[str], fail_on: Optional[str] = None) -> None:
    with ExitStack() as stack:
        dbs = [stack.enter_context(open_db(name)) for name in db_configs]
        for db in dbs:
            try:
                with db_batch(db) as batch:
                    batch.append(("sensor_01", "temp", 22.4))
                    batch.append(("sensor_01", "humidity", 65.0))
                    if db.name == fail_on:
                        raise ValueError(f"Simulated failure in {db.name}")
            except ValueError:
                pass   # already printed, continue with other DBs
        for db in dbs:
            print(f"  {db.name}: {db.count()} rows")


if __name__ == "__main__":
    print("=== Normal run ===")
    process_all(["primary", "replica"])

    print("\n=== With failure in replica ===")
    process_all(["primary", "replica"], fail_on="replica")
```
[[OOP and Design]]