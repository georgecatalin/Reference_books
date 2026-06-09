### The mental model shift

Threading: multiple threads, one runs at a time (GIL), OS decides when to switch. asyncio: one thread, one event loop, you decide when to yield control (at `await` points).

```
Threading:    T1 ----[GIL switch]---- T2 ----[GIL switch]---- T1
asyncio:      C1 --[await]--> C2 --[await]--> C1 --[await]--> C3
```

asyncio is cooperative multitasking. A coroutine runs until it hits an `await`, then the event loop can run another coroutine. If a coroutine never awaits, it blocks the entire loop — there's no preemption.

---

### Coroutines — the building block

python

```python
import asyncio

async def fetch_reading(device_id: str) -> float:
    """async def makes this a coroutine function."""
    await asyncio.sleep(0.1)   # yields control to event loop during the wait
    return 22.4

# Calling fetch_reading() returns a coroutine object — nothing runs yet
coro = fetch_reading("dev_01")
print(type(coro))   # <class 'coroutine'>

# To run it, you need the event loop
result = asyncio.run(fetch_reading("dev_01"))   # runs until coroutine completes
print(result)   # 22.4
```

`await` can only be used inside an `async def` function. It suspends the current coroutine and gives control back to the event loop until the awaited thing completes.

---

### Running coroutines concurrently — `asyncio.gather`

This is where asyncio's value shows. Run multiple I/O operations concurrently with a single thread:

python

```python
import asyncio
import time

async def read_device(device_id: str, delay: float) -> tuple[str, float]:
    print(f"  starting {device_id}")
    await asyncio.sleep(delay)   # simulates network/serial I/O
    print(f"  done {device_id}")
    return device_id, 22.4

async def main():
    t0 = time.perf_counter()

    # Sequential — total time = sum of delays
    r1 = await read_device("dev_01", 0.3)
    r2 = await read_device("dev_02", 0.3)
    r3 = await read_device("dev_03", 0.3)
    print(f"Sequential: {time.perf_counter() - t0:.2f}s")   # ~0.9s

    t0 = time.perf_counter()

    # Concurrent — total time = max of delays
    results = await asyncio.gather(
        read_device("dev_01", 0.3),
        read_device("dev_02", 0.3),
        read_device("dev_03", 0.3),
    )
    print(f"Concurrent: {time.perf_counter() - t0:.2f}s")   # ~0.3s
    print(results)

asyncio.run(main())
```

`gather` runs all coroutines concurrently and returns when all complete. Exceptions from any coroutine propagate — if one fails, `gather` raises immediately (unless `return_exceptions=True`).

---

### Tasks — fire and forget

`asyncio.create_task()` schedules a coroutine to run concurrently without waiting for it immediately:

python

```python
async def background_heartbeat(interval: float) -> None:
    while True:
        await send_heartbeat()
        await asyncio.sleep(interval)

async def main():
    # Start heartbeat — don't await, it runs in background
    hb_task = asyncio.create_task(background_heartbeat(30))

    # Do main work
    await process_messages()

    # Cancel background task on shutdown
    hb_task.cancel()
    try:
        await hb_task
    except asyncio.CancelledError:
        pass   # expected — task was cancelled
```

`create_task` is non-blocking. The task runs concurrently with the rest of `main()` at `await` points.

---

### Timeouts — `asyncio.wait_for`

python

```python
async def read_with_timeout(device_id: str) -> float:
    try:
        return await asyncio.wait_for(
            read_device(device_id),
            timeout=5.0,
        )
    except asyncio.TimeoutError:
        raise IOError(f"Device {device_id} timed out")
```

---

### Async context managers and iterators

python

```python
class AsyncMQTTClient:
    async def __aenter__(self):
        await self.connect()
        return self

    async def __aexit__(self, *args):
        await self.disconnect()

    async def messages(self):
        """Async generator — yield messages as they arrive."""
        while True:
            msg = await self._receive()
            yield msg

async def main():
    async with AsyncMQTTClient("localhost", 1883) as client:
        async for msg in client.messages():
            await handle(msg)
```

`async with` requires `__aenter__`/`__aexit__`. `async for` requires `__aiter__`/`__anext__`. These are the async equivalents of the context manager and iterator protocols.

---

### Today's deliverable

python

```python
# async_ingester.py
import asyncio
import json
import random
import time
from collections import defaultdict

# --- Simulated async device reads ---

async def read_device(device_id: str, variable: str) -> tuple[str, str, float]:
    """Simulates an async read from a device (serial, HTTP, MQTT)."""
    delay = random.uniform(0.05, 0.3)
    await asyncio.sleep(delay)
    if random.random() < 0.1:
        raise IOError(f"Timeout reading {device_id}/{variable}")
    value = round(20 + random.gauss(0, 3), 2)
    return device_id, variable, value


async def read_device_safe(
    device_id: str,
    variable:  str,
    timeout:   float = 0.5,
) -> tuple[str, str, float] | None:
    try:
        return await asyncio.wait_for(
            read_device(device_id, variable),
            timeout=timeout,
        )
    except (asyncio.TimeoutError, IOError) as e:
        print(f"  [warn] {device_id}/{variable}: {e}")
        return None


# --- Collector: poll all devices concurrently ---

async def poll_all_devices(
    devices:   list[tuple[str, str]],  # (device_id, variable) pairs
    results:   dict,
    interval:  float,
    stop_event: asyncio.Event,
) -> None:
    cycle = 0
    while not stop_event.is_set():
        cycle += 1
        t0 = time.perf_counter()

        readings = await asyncio.gather(
            *[read_device_safe(did, var) for did, var in devices],
            return_exceptions=False,
        )

        for reading in readings:
            if reading:
                did, var, val = reading
                results[did][var] = val

        elapsed = time.perf_counter() - t0
        print(f"  cycle {cycle}: {sum(1 for r in readings if r)} "
              f"of {len(devices)} succeeded in {elapsed*1000:.0f}ms")

        try:
            await asyncio.wait_for(stop_event.wait(), timeout=interval)
        except asyncio.TimeoutError:
            pass


# --- Background status reporter ---

async def status_reporter(results: dict, stop_event: asyncio.Event) -> None:
    while not stop_event.is_set():
        try:
            await asyncio.wait_for(stop_event.wait(), timeout=2.0)
        except asyncio.TimeoutError:
            print(f"\n  [status] {len(results)} devices:")
            for did, vars_ in sorted(results.items()):
                print(f"    {did}: {vars_}")
            print()


# --- Main ---

async def main() -> None:
    random.seed(42)

    devices = [
        (f"dev_{i:02d}", var)
        for i in range(5)
        for var in ["temperature", "humidity"]
    ]

    results: dict = defaultdict(dict)
    stop_event = asyncio.Event()

    poller   = asyncio.create_task(poll_all_devices(devices, results, 0.5, stop_event))
    reporter = asyncio.create_task(status_reporter(results, stop_event))

    await asyncio.sleep(5.0)

    print("Shutting down...")
    stop_event.set()
    await asyncio.gather(poller, reporter, return_exceptions=True)
    print(f"Final: {sum(len(v) for v in results.values())} readings across "
          f"{len(results)} devices")


if __name__ == "__main__":
    asyncio.run(main())
```
[[Systems and concurrency]]