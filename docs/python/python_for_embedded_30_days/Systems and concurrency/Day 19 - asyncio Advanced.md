### `asyncio.gather` vs `asyncio.wait` vs `TaskGroup`

python

```python
import asyncio

# gather — run all, return all results, fail fast on first exception
results = await asyncio.gather(coro1(), coro2(), coro3())

# gather with return_exceptions — exceptions become results, nothing cancelled
results = await asyncio.gather(coro1(), coro2(), coro3(), return_exceptions=True)
for r in results:
    if isinstance(r, Exception):
        print(f"Task failed: {r}")

# wait — more control: wait for FIRST_COMPLETED or FIRST_EXCEPTION
tasks = [asyncio.create_task(c) for c in [coro1(), coro2(), coro3()]]
done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
for task in pending:
    task.cancel()

# TaskGroup (Python 3.11+) — clearest API, cancels all on first failure
async with asyncio.TaskGroup() as tg:
    t1 = tg.create_task(coro1())
    t2 = tg.create_task(coro2())
# All tasks done here — any exception propagates
```

Use `TaskGroup` for new code on Python 3.11+. Use `gather(return_exceptions=True)` when you want all results regardless of failures.

---

### Graceful shutdown — the pattern you must get right

python

```python
import asyncio
import signal

async def main() -> None:
    stop = asyncio.Event()

    def handle_signal():
        print("\nShutdown signal received")
        stop.set()

    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, handle_signal)

    tasks: list[asyncio.Task] = []
    tasks.append(asyncio.create_task(mqtt_ingester(stop)))
    tasks.append(asyncio.create_task(status_reporter(stop)))
    tasks.append(asyncio.create_task(heartbeat_sender(stop)))

    await stop.wait()   # block until signal

    print("Cancelling tasks...")
    for task in tasks:
        task.cancel()

    await asyncio.gather(*tasks, return_exceptions=True)
    print("Shutdown complete")

asyncio.run(main())
```

The pattern: signal handler sets an event, tasks check the event or get cancelled, `gather` waits for all cleanup to finish.

---

### `asyncio.Queue` — async producer/consumer

Same concept as `queue.Queue` but for coroutines:

python

```python
async def mqtt_producer(q: asyncio.Queue, stop: asyncio.Event) -> None:
    i = 0
    while not stop.is_set():
        msg = {"topic": f"devices/dev_{i%3:02d}/temp", "payload": b"22.4"}
        await q.put(msg)
        i += 1
        await asyncio.sleep(0.05)

async def mqtt_consumer(worker_id: int, q: asyncio.Queue, stop: asyncio.Event) -> None:
    while not stop.is_set():
        try:
            msg = await asyncio.wait_for(q.get(), timeout=0.1)
            await process_message(msg)
            q.task_done()
        except asyncio.TimeoutError:
            continue
```

---

### Running blocking code in asyncio — `run_in_executor`

Blocking code (file I/O, a synchronous library, CPU work) inside a coroutine blocks the entire event loop. Offload it:

python

```python
import asyncio
from concurrent.futures import ThreadPoolExecutor, ProcessPoolExecutor

async def main():
    loop = asyncio.get_running_loop()

    # Run blocking I/O in a thread pool (doesn't block event loop)
    with ThreadPoolExecutor() as pool:
        result = await loop.run_in_executor(pool, blocking_file_read, "data.csv")

    # Run CPU-bound work in a process pool
    with ProcessPoolExecutor() as pool:
        result = await loop.run_in_executor(pool, cpu_heavy_computation, data)
```

This bridges the sync/async boundary cleanly — the event loop stays responsive while the blocking work runs in a thread or process.

---

### Today's deliverable

python

```python
# async_mqtt_monitor.py
import asyncio
import json
import random
import signal
import time
from collections import defaultdict
from typing import Optional

# --- Async MQTT client simulation ---

class AsyncMQTTClient:
    def __init__(self, host: str, port: int) -> None:
        self.host = host
        self.port = port
        self._connected = False
        self._message_queue: asyncio.Queue = asyncio.Queue(maxsize=200)

    async def connect(self) -> None:
        await asyncio.sleep(0.05)   # simulate handshake
        self._connected = True
        print(f"  [mqtt] connected to {self.host}:{self.port}")

    async def disconnect(self) -> None:
        self._connected = False
        print("  [mqtt] disconnected")

    async def __aenter__(self):
        await self.connect()
        return self

    async def __aexit__(self, *args):
        await self.disconnect()

    async def message_stream(self, stop: asyncio.Event):
        """Async generator — yields messages until stop is set."""
        devices   = [f"dev_{i:02d}" for i in range(6)]
        variables = ["temperature", "humidity", "pressure"]
        while not stop.is_set():
            device = random.choice(devices)
            var    = random.choice(variables)
            value  = round(20 + random.gauss(0, 4), 2)
            yield {
                "topic":   f"devices/{device}/{var}",
                "payload": json.dumps({"value": value, "ts": time.time()}).encode(),
            }
            await asyncio.sleep(random.uniform(0.01, 0.05))


# --- Processing pipeline ---

async def ingest(
    client:  AsyncMQTTClient,
    q:       asyncio.Queue,
    stop:    asyncio.Event,
) -> None:
    async for msg in client.message_stream(stop):
        try:
            await asyncio.wait_for(q.put(msg), timeout=0.1)
        except asyncio.TimeoutError:
            pass   # queue full — drop


async def process(
    worker_id: int,
    q:         asyncio.Queue,
    store:     dict,
    counters:  dict,
    stop:      asyncio.Event,
) -> None:
    while not stop.is_set():
        try:
            msg = await asyncio.wait_for(q.get(), timeout=0.1)
            parts  = msg["topic"].split("/")
            did, var = parts[1], parts[2]
            data   = json.loads(msg["payload"])
            store[did][var] = round(data["value"], 2)
            counters[did] += 1
            q.task_done()
        except (asyncio.TimeoutError, KeyError, json.JSONDecodeError):
            continue


async def report(store: dict, counters: dict, stop: asyncio.Event) -> None:
    while not stop.is_set():
        try:
            await asyncio.wait_for(stop.wait(), timeout=3.0)
        except asyncio.TimeoutError:
            total = sum(counters.values())
            print(f"\n  [report] {total} total messages | {len(store)} devices")
            for did in sorted(store):
                print(f"    {did} ({counters[did]} msgs): {store[did]}")


# --- Main with graceful shutdown ---

async def run() -> None:
    random.seed(42)

    stop   = asyncio.Event()
    q      = asyncio.Queue(maxsize=300)
    store: dict  = defaultdict(dict)
    counts: dict = defaultdict(int)

    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, stop.set)

    async with AsyncMQTTClient("localhost", 1883) as client:
        async with asyncio.TaskGroup() as tg:
            tg.create_task(ingest(client, q, stop))
            for i in range(3):
                tg.create_task(process(i, q, store, counts, stop))
            tg.create_task(report(store, counts, stop))
            tg.create_task(
                asyncio.sleep(8)  # auto-stop after 8 seconds for demo
            )
        stop.set()

    print(f"\nFinal: {sum(counts.values())} messages processed")


if __name__ == "__main__":
    asyncio.run(run())
```
[[Systems and concurrency]]