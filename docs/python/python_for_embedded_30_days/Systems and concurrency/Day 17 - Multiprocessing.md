### When to reach for multiprocessing

The decision is simple: if a task is CPU-bound and takes measurable time, multiprocessing is the right tool. In IoT systems this appears in:

- Signal processing (FFT, filtering on sensor data streams)
- Image/video analysis from cameras
- Batch aggregation over large historical datasets
- ML inference (if not using a GPU library that handles parallelism internally)

---

### The `Process` class — explicit process management

python

```python
from multiprocessing import Process, Queue, Value, Array
import os

def worker(name: str, result_queue: Queue) -> None:
    print(f"  {name} running in PID {os.getpid()}")
    result = sum(i * i for i in range(1_000_000))
    result_queue.put((name, result))

if __name__ == "__main__":   # REQUIRED on Windows and for spawn start method
    q = Queue()
    procs = [Process(target=worker, args=(f"worker_{i}", q)) for i in range(4)]
    for p in procs: p.start()
    for p in procs: p.join()
    while not q.empty():
        name, result = q.get()
        print(f"  {name}: {result}")
```

The `if __name__ == "__main__":` guard is mandatory — without it, each spawned process re-imports the module and tries to spawn more processes, causing infinite recursion.

---

### `ProcessPoolExecutor` — the practical API

For most use cases you don't manage `Process` objects directly. `ProcessPoolExecutor` from `concurrent.futures` is cleaner:

python

```python
from concurrent.futures import ProcessPoolExecutor, as_completed
import time

def process_batch(batch: list[float]) -> dict:
    """CPU-intensive aggregation — runs in a worker process."""
    return {
        "count": len(batch),
        "mean":  sum(batch) / len(batch),
        "max":   max(batch),
        "min":   min(batch),
    }

if __name__ == "__main__":
    # Simulate large sensor dataset split into batches
    import random
    random.seed(42)
    all_data = [random.gauss(20, 5) for _ in range(1_000_000)]
    batch_size = 250_000
    batches = [all_data[i:i+batch_size] for i in range(0, len(all_data), batch_size)]

    with ProcessPoolExecutor(max_workers=4) as pool:
        futures = {pool.submit(process_batch, b): i for i, b in enumerate(batches)}
        for future in as_completed(futures):
            idx = futures[future]
            result = future.result()
            print(f"  batch {idx}: {result}")
```

`as_completed` yields futures as they finish — not in submission order. Use `pool.map()` if you need results in order.

---

### Shared memory — `Value` and `Array`

Normal Python objects can't be shared between processes — each process has its own memory space. Use `multiprocessing.Value` and `Array` for simple shared state:

python

```python
from multiprocessing import Process, Value, Array
import ctypes

def sensor_reader(shared_temp: Value, shared_readings: Array, stop_flag: Value) -> None:
    import random, time
    while not stop_flag.value:
        with shared_temp.get_lock():    # Value has a built-in lock
            shared_temp.value = round(20 + random.gauss(0, 2), 2)
        time.sleep(0.1)

if __name__ == "__main__":
    temp      = Value(ctypes.c_double, 0.0)
    readings  = Array(ctypes.c_double, 10)
    stop_flag = Value(ctypes.c_bool, False)

    p = Process(target=sensor_reader, args=(temp, readings, stop_flag))
    p.start()

    import time
    for _ in range(5):
        time.sleep(0.2)
        with temp.get_lock():
            print(f"  current temp: {temp.value}")

    stop_flag.value = True
    p.join()
```

For larger shared datasets, use `multiprocessing.shared_memory` (Python 3.8+) which gives direct access to a shared memory block — no serialization overhead.

---

### `Pipe` — bidirectional communication

python

```python
from multiprocessing import Process, Pipe

def compute_worker(conn) -> None:
    while True:
        data = conn.recv()     # blocks until data arrives
        if data is None:
            break
        result = sum(x * x for x in data)
        conn.send(result)
    conn.close()

if __name__ == "__main__":
    parent_conn, child_conn = Pipe()
    p = Process(target=compute_worker, args=(child_conn,))
    p.start()

    for batch in [[1,2,3,4,5], [10,20,30], [100,200]]:
        parent_conn.send(batch)
        result = parent_conn.recv()
        print(f"  sum of squares: {result}")

    parent_conn.send(None)   # signal shutdown
    p.join()
```

`Pipe` uses pickle for serialization. Keep objects small and pickle-safe — no open file handles, no locks, no lambdas.

---

### Today's deliverable

python

```python
# parallel_processor.py
from concurrent.futures import ProcessPoolExecutor, as_completed
from multiprocessing import Queue as MPQueue, Process, Value
import ctypes
import time
import random
import math

# --- CPU-intensive work: compute statistics over a sensor data batch ---

def analyze_batch(args: tuple) -> dict:
    """
    Runs in a worker process.
    args = (batch_id, data)
    """
    batch_id, data = args
    n = len(data)
    mean = sum(data) / n
    variance = sum((x - mean) ** 2 for x in data) / n
    std_dev = math.sqrt(variance)

    # Simulate extra CPU work (FFT-like computation)
    processed = [math.sin(x) * math.cos(x / 2) for x in data]
    peak = max(processed)

    return {
        "batch_id":  batch_id,
        "count":     n,
        "mean":      round(mean, 4),
        "std_dev":   round(std_dev, 4),
        "peak":      round(peak, 6),
    }


def run_parallel_analysis(
    all_data: list[float],
    batch_size: int,
    max_workers: int,
) -> list[dict]:
    batches = [
        (i, all_data[i * batch_size:(i + 1) * batch_size])
        for i in range(0, len(all_data) // batch_size)
    ]

    results = []
    with ProcessPoolExecutor(max_workers=max_workers) as pool:
        futures = {pool.submit(analyze_batch, b): b[0] for b in batches}
        for future in as_completed(futures):
            results.append(future.result())

    return sorted(results, key=lambda r: r["batch_id"])


if __name__ == "__main__":
    random.seed(42)
    N = 2_000_000
    data = [random.gauss(22.0, 4.0) for _ in range(N)]

    print(f"Dataset: {N:,} readings")
    print(f"Batch size: 250,000  |  Workers: 4\n")

    # Sequential
    t0 = time.perf_counter()
    batches = [(i, data[i*250_000:(i+1)*250_000]) for i in range(8)]
    seq_results = [analyze_batch(b) for b in batches]
    seq_time = time.perf_counter() - t0

    # Parallel
    t0 = time.perf_counter()
    par_results = run_parallel_analysis(data, batch_size=250_000, max_workers=4)
    par_time = time.perf_counter() - t0

    print(f"Sequential: {seq_time:.3f}s")
    print(f"Parallel:   {par_time:.3f}s  ({seq_time/par_time:.1f}x speedup)\n")

    print("Results (first 3 batches):")
    for r in par_results[:3]:
        print(f"  batch {r['batch_id']}: mean={r['mean']}, "
              f"std={r['std_dev']}, peak={r['peak']}")
```
[[Systems and concurrency]]