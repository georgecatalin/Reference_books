This is the most misunderstood topic in Python. Developers either avoid threads entirely because "the GIL prevents parallelism", or they use them everywhere and hit subtle bugs. Neither is right. You need a precise mental model.

---

### What the GIL actually is

The Global Interpreter Lock is a mutex inside CPython that ensures only one thread executes Python bytecode at a time. It exists because CPython's memory management (reference counting) is not thread-safe — without the GIL, two threads incrementing/decrementing refcounts simultaneously would corrupt memory.

The C analogy: imagine your entire program running with a single global mutex that every function acquires before executing and releases after. That's the GIL.

```
Thread 1: [acquire GIL] [execute bytecodes] [release GIL]
Thread 2:               [waiting]            [acquire GIL] [execute bytecodes]
```

Only one thread runs Python bytecode at any instant. This is a hard constraint.

---

### Why threads still work — the I/O release

The GIL is released during I/O operations. This is the critical fact:

```
Thread 1: [GIL] [compute] [release GIL → start network read] ............. [GIL] [process result]
Thread 2:                 [GIL] [compute] [release GIL → start serial read] ... [GIL] [process]
Thread 3:                       [GIL] [compute] ........................................
```

While Thread 1 is waiting for a network response, it holds no GIL — Threads 2 and 3 run freely. For I/O-bound work — which is most of what an MQTT ingester does — threads give you genuine concurrency.

The GIL is also released by C extensions that explicitly drop it. NumPy, OpenCV, and most database drivers release the GIL during their heavy operations, so Python threads can run alongside them.

---

### CPU-bound vs I/O-bound — the only question that matters

python

```python
import time
import threading
import multiprocessing

# CPU-bound: pure Python computation — GIL prevents parallelism
def cpu_task(n: int) -> int:
    return sum(i * i for i in range(n))

# I/O-bound: waiting on external systems — GIL released during wait
def io_task(duration: float) -> None:
    time.sleep(duration)   # sleep releases the GIL


def run_sequential(task, args_list):
    t0 = time.perf_counter()
    results = [task(*args) for args in args_list]
    return time.perf_counter() - t0, results


def run_threaded(task, args_list):
    t0 = time.perf_counter()
    threads = [threading.Thread(target=task, args=args) for args in args_list]
    for t in threads: t.start()
    for t in threads: t.join()
    return time.perf_counter() - t0


def run_multiprocess(task, args_list):
    t0 = time.perf_counter()
    procs = [multiprocessing.Process(target=task, args=args) for args in args_list]
    for p in procs: p.start()
    for p in procs: p.join()
    return time.perf_counter() - t0
```

Run this and observe the timing differences:

python

```python
if __name__ == "__main__":
    N = 4   # number of parallel tasks

    # I/O-bound: each task sleeps 1 second
    io_args = [(1.0,)] * N
    seq_time, _ = run_sequential(io_task, io_args)
    thr_time     = run_threaded(io_task, io_args)
    print(f"I/O-bound sequential: {seq_time:.2f}s")   # ~4.0s
    print(f"I/O-bound threaded:   {thr_time:.2f}s")   # ~1.0s — real concurrency

    # CPU-bound: each task does heavy computation
    cpu_args = [(5_000_000,)] * N
    seq_time, _ = run_sequential(cpu_task, cpu_args)
    thr_time     = run_threaded(cpu_task, cpu_args)
    mp_time      = run_multiprocess(cpu_task, cpu_args)
    print(f"CPU-bound sequential:    {seq_time:.2f}s")
    print(f"CPU-bound threaded:      {thr_time:.2f}s")  # same or SLOWER than sequential
    print(f"CPU-bound multiprocess:  {mp_time:.2f}s")   # ~sequential/N — true parallelism
```

The numbers tell the whole story:

- I/O-bound + threading = near-linear speedup
- CPU-bound + threading = no speedup (GIL) or slower (GIL contention overhead)
- CPU-bound + multiprocessing = near-linear speedup (separate GIL per process)

---

### What this means for your MQTT ingester

Your ingester does:

- Network I/O (receiving MQTT messages) — I/O bound, threads work
- JSON parsing (CPU, but tiny) — negligible
- Database writes (I/O bound) — threads work
- Serial port reads (I/O bound) — threads work

Threading is the right model for your use case. Multiprocessing would be overkill and introduces serialization overhead for what is fundamentally I/O-bound work.

The one exception: if you add signal processing (FFT over sensor data, image processing from a camera) — offload that specific work to a process. Day 17 covers this.

---

### GIL switching — when threads interleave

The GIL switches between threads periodically (every 5ms by default, or on I/O release). This means Python code is not atomic at the statement level — a thread can be preempted between any two bytecodes:

python

```python
# This looks atomic but is NOT
counter = 0

def increment():
    global counter
    for _ in range(100_000):
        counter += 1   # READ counter, ADD 1, WRITE counter — three bytecodes
                       # another thread can run between any of them

threads = [threading.Thread(target=increment) for _ in range(4)]
for t in threads: t.start()
for t in threads: t.join()

print(counter)   # Should be 400_000 — will be less. Race condition.
```

One operation that IS atomic in CPython: `list.append()`. The GIL guarantees this single bytecode completes before switching. But never rely on this — it's an implementation detail of CPython, not a language guarantee.

The fix is always explicit synchronization — Day 16 covers this in full.

---

### Inspecting threads

python

```python
import threading

def worker(name: str) -> None:
    print(f"  {name}: running in thread {threading.current_thread().name}")
    time.sleep(0.1)

t = threading.Thread(target=worker, args=("task_1",), name="WorkerThread-1")
t.start()

print("Active threads:", threading.active_count())
print("All threads:", [t.name for t in threading.enumerate()])
t.join()   # wait for completion
```

---

### Daemon threads — background workers that don't block exit

By default, Python waits for all threads to finish before exiting. Daemon threads are the exception — they die when the main thread exits:

python

```python
def heartbeat_loop():
    while True:
        send_heartbeat()
        time.sleep(30)

# Daemon — won't prevent process exit
t = threading.Thread(target=heartbeat_loop, daemon=True)
t.start()

# Main thread does its work and exits
# Daemon thread is killed automatically — no cleanup
```

Use daemon threads for: background heartbeats, metric collectors, log flushers. Never for threads that hold open files or database connections — they won't get a chance to close them.

---

### Today's deliverable

python

```python
# gil_benchmark.py
import time
import threading
import multiprocessing
from typing import Callable

# --- Tasks ---

def cpu_bound(n: int) -> int:
    """Pure Python computation — GIL held throughout."""
    return sum(i * i for i in range(n))

def io_bound(duration: float) -> None:
    """Simulated I/O wait — GIL released."""
    time.sleep(duration)

def mixed_bound(n: int, io_sec: float) -> float:
    """Realistic: some compute, some I/O wait."""
    result = sum(i * i for i in range(n))
    time.sleep(io_sec)
    return float(result)


# --- Runners ---

def run_sequential(fn: Callable, args_list: list) -> float:
    t0 = time.perf_counter()
    for args in args_list:
        fn(*args)
    return time.perf_counter() - t0

def run_threaded(fn: Callable, args_list: list) -> float:
    t0 = time.perf_counter()
    threads = [threading.Thread(target=fn, args=a) for a in args_list]
    for t in threads: t.start()
    for t in threads: t.join()
    return time.perf_counter() - t0

def run_multiprocess(fn: Callable, args_list: list) -> float:
    t0 = time.perf_counter()
    procs = [multiprocessing.Process(target=fn, args=a) for a in args_list]
    for p in procs: p.start()
    for p in procs: p.join()
    return time.perf_counter() - t0

def report(label: str, seq: float, thr: float, mp: float) -> None:
    print(f"\n{label}")
    print(f"  sequential:    {seq:.3f}s  (baseline)")
    print(f"  threaded:      {thr:.3f}s  ({seq/thr:.1f}x vs sequential)")
    print(f"  multiprocess:  {mp:.3f}s  ({seq/mp:.1f}x vs sequential)")


if __name__ == "__main__":
    WORKERS = 4

    # I/O-bound
    io_args = [(0.5,)] * WORKERS
    report(
        "I/O-bound (sleep 0.5s × 4)",
        run_sequential(io_bound, io_args),
        run_threaded(io_bound, io_args),
        run_multiprocess(io_bound, io_args),
    )

    # CPU-bound
    cpu_args = [(2_000_000,)] * WORKERS
    report(
        "CPU-bound (sum of squares × 4)",
        run_sequential(cpu_bound, cpu_args),
        run_threaded(cpu_bound, cpu_args),
        run_multiprocess(cpu_bound, cpu_args),
    )

    # Mixed (realistic IoT: small compute + network wait)
    mix_args = [(100_000, 0.3)] * WORKERS
    report(
        "Mixed (compute + 0.3s I/O × 4)",
        run_sequential(mixed_bound, mix_args),
        run_threaded(mixed_bound, mix_args),
        run_multiprocess(mixed_bound, mix_args),
    )
```

After running, answer for yourself before moving on: for your MQTT ingester that receives messages, parses JSON, and writes to a database — which execution model is correct, and why? The numbers from this benchmark are your evidence.

[[Systems and concurrency]]