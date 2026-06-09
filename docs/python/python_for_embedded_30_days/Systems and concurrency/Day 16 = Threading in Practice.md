### The three synchronization primitives you'll actually use

#### `Lock` — mutual exclusion

python

```python
import threading

class SafeCounter:
    def __init__(self) -> None:
        self._value = 0
        self._lock  = threading.Lock()

    def increment(self) -> None:
        with self._lock:          # acquire on enter, release on exit
            self._value += 1      # only one thread here at a time

    def get(self) -> int:
        with self._lock:
            return self._value
```

Always use `with lock:` — never `lock.acquire()` / `lock.release()` manually. The `with` block guarantees release even if an exception is raised inside.

#### `RLock` — reentrant lock

A regular `Lock` deadlocks if the same thread tries to acquire it twice. `RLock` (reentrant lock) allows the same thread to acquire it multiple times:

python

```python
class DeviceRegistry:
    def __init__(self) -> None:
        self._lock    = threading.RLock()
        self._devices: dict[str, dict] = {}

    def register(self, device_id: str, info: dict) -> None:
        with self._lock:
            self._devices[device_id] = info
            self._log(device_id, "registered")   # calls a method that also takes the lock

    def _log(self, device_id: str, event: str) -> None:
        with self._lock:   # RLock — same thread can acquire again, no deadlock
            print(f"{device_id}: {event}")
```

Use `RLock` when a locked method calls other locked methods on the same object.

#### `Event` — signaling between threads

python

```python
class MQTTIngester:
    def __init__(self) -> None:
        self._stop_event = threading.Event()

    def start(self) -> None:
        self._thread = threading.Thread(target=self._run, daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop_event.set()     # signal the thread to stop
        self._thread.join()        # wait for it to actually finish

    def _run(self) -> None:
        while not self._stop_event.is_set():
            self._poll()
            self._stop_event.wait(timeout=0.1)  # sleep but wake early if set
```

`Event.wait(timeout)` is better than `time.sleep()` in loops — it wakes immediately when the event is set rather than waiting out the full sleep duration.

---

### `queue.Queue` — the producer-consumer pattern

`Queue` is the right tool for passing data between threads. It's thread-safe by design — no explicit locking needed:

python

```python
import queue
import threading
import time

def producer(q: queue.Queue, stop: threading.Event) -> None:
    """Simulates MQTT message arrival."""
    i = 0
    while not stop.is_set():
        msg = {"topic": f"devices/dev_{i%3:02d}/temp", "payload": b"22.4"}
        q.put(msg)          # blocks if queue is full (if maxsize set)
        i += 1
        time.sleep(0.05)

def consumer(q: queue.Queue, stop: threading.Event, worker_id: int) -> None:
    """Processes messages from the queue."""
    while not stop.is_set():
        try:
            msg = q.get(timeout=0.1)   # blocks up to 0.1s, then raises Empty
            process(msg, worker_id)
            q.task_done()              # signals that this item is done
        except queue.Empty:
            continue                   # check stop_event and try again

def process(msg: dict, worker_id: int) -> None:
    print(f"  worker {worker_id}: {msg['topic']}")
```

`Queue.task_done()` + `Queue.join()` lets you wait until all queued work is complete:

python

```python
q = queue.Queue(maxsize=100)   # maxsize prevents unbounded memory growth
stop = threading.Event()

prod = threading.Thread(target=producer, args=(q, stop), daemon=True)
workers = [
    threading.Thread(target=consumer, args=(q, stop, i), daemon=True)
    for i in range(3)
]

prod.start()
for w in workers: w.start()

time.sleep(1.0)
stop.set()
```

`maxsize=100` is important in production — without it, a slow consumer and fast producer will exhaust memory. The producer blocks when the queue is full, providing natural backpressure.

---

### `threading.local` — per-thread state

Sometimes you need state that's isolated per thread — a database connection, a random seed, a request context. `threading.local` gives each thread its own copy:

python

```python
_thread_local = threading.local()

def get_connection() -> SimulatedDB:
    if not hasattr(_thread_local, "conn"):
        _thread_local.conn = SimulatedDB(f"thread-{threading.current_thread().name}")
        _thread_local.conn.open()
    return _thread_local.conn

def worker() -> None:
    conn = get_connection()   # each thread gets its own connection
    conn.insert(("data",))
```

This is how web frameworks (Flask, Django) isolate request state per thread.

---

### Deadlock — and how to avoid it

Deadlock happens when Thread A holds Lock 1 and waits for Lock 2, while Thread B holds Lock 2 and waits for Lock 1. Both wait forever.

python

```python
lock_a = threading.Lock()
lock_b = threading.Lock()

def task_1():
    with lock_a:
        time.sleep(0.01)
        with lock_b:    # waits for lock_b — held by task_2
            pass

def task_2():
    with lock_b:
        time.sleep(0.01)
        with lock_a:    # waits for lock_a — held by task_1 — DEADLOCK
            pass
```

Prevention rules:

1. Always acquire locks in the same order across all threads
2. Use timeouts: `lock.acquire(timeout=5.0)` — fail fast rather than hang
3. Prefer `Queue` over manual locking where possible — it eliminates most lock contention

---

### Today's deliverable

Build the MQTT ingester thread architecture from the Day 3 deliverable description — now with proper thread safety:

python

```python
# threaded_ingester.py
import threading
import queue
import time
import random
import json
from collections import defaultdict
from typing import Optional

# --- Message types ---

class MQTTMessage:
    __slots__ = ("topic", "payload", "ts")
    def __init__(self, topic: str, payload: bytes) -> None:
        self.topic   = topic
        self.payload = payload
        self.ts      = time.time()


# --- Thread-safe device state store ---

class DeviceStateStore:
    def __init__(self) -> None:
        self._lock   = threading.RLock()
        self._state: dict[str, dict] = defaultdict(dict)
        self._counts: dict[str, int] = defaultdict(int)

    def update(self, device_id: str, variable: str, value: float) -> None:
        with self._lock:
            self._state[device_id][variable] = value
            self._counts[device_id] += 1

    def snapshot(self) -> dict:
        with self._lock:
            return {k: dict(v) for k, v in self._state.items()}

    def message_counts(self) -> dict[str, int]:
        with self._lock:
            return dict(self._counts)


# --- Producer: simulates MQTT broker pushing messages ---

class MessageProducer(threading.Thread):
    def __init__(self, out_queue: queue.Queue, stop_event: threading.Event) -> None:
        super().__init__(name="Producer", daemon=True)
        self._q     = out_queue
        self._stop  = stop_event
        self._sent  = 0

    def run(self) -> None:
        devices   = [f"dev_{i:02d}" for i in range(5)]
        variables = ["temperature", "humidity", "pressure"]
        while not self._stop.is_set():
            device = random.choice(devices)
            var    = random.choice(variables)
            value  = round(20 + random.gauss(0, 5), 2)
            topic  = f"devices/{device}/{var}"
            payload = json.dumps({"value": value}).encode()
            try:
                self._q.put(MQTTMessage(topic, payload), timeout=0.1)
                self._sent += 1
            except queue.Full:
                pass   # drop message — backpressure in action
            self._stop.wait(timeout=0.02)
        print(f"  [producer] sent {self._sent} messages")


# --- Consumer: parses and stores messages ---

class MessageConsumer(threading.Thread):
    def __init__(
        self,
        worker_id:   int,
        in_queue:    queue.Queue,
        store:       DeviceStateStore,
        stop_event:  threading.Event,
    ) -> None:
        super().__init__(name=f"Consumer-{worker_id}", daemon=True)
        self._id     = worker_id
        self._q      = in_queue
        self._store  = store
        self._stop   = stop_event
        self._processed = 0

    def run(self) -> None:
        while not self._stop.is_set():
            try:
                msg: MQTTMessage = self._q.get(timeout=0.1)
                self._handle(msg)
                self._q.task_done()
                self._processed += 1
            except queue.Empty:
                continue
        print(f"  [consumer-{self._id}] processed {self._processed} messages")

    def _handle(self, msg: MQTTMessage) -> None:
        try:
            parts     = msg.topic.split("/")
            device_id = parts[1]
            variable  = parts[2]
            data      = json.loads(msg.payload)
            self._store.update(device_id, variable, float(data["value"]))
        except (IndexError, KeyError, ValueError, json.JSONDecodeError):
            pass   # malformed message — discard


# --- Supervisor ---

class IngesterSupervisor:
    def __init__(self, num_workers: int = 3) -> None:
        self._q     = queue.Queue(maxsize=500)
        self._stop  = threading.Event()
        self._store = DeviceStateStore()
        self._producer = MessageProducer(self._q, self._stop)
        self._consumers = [
            MessageConsumer(i, self._q, self._store, self._stop)
            for i in range(num_workers)
        ]

    def start(self) -> None:
        self._producer.start()
        for c in self._consumers:
            c.start()

    def stop(self) -> None:
        self._stop.set()
        self._producer.join()
        for c in self._consumers:
            c.join()

    def status(self) -> None:
        snapshot = self._store.snapshot()
        counts   = self._store.message_counts()
        print(f"\n  Queue size: {self._q.qsize()}")
        print(f"  Devices seen: {len(snapshot)}")
        for dev, vars_ in sorted(snapshot.items()):
            print(f"    {dev} ({counts.get(dev, 0)} msgs): {vars_}")


if __name__ == "__main__":
    random.seed(42)
    supervisor = IngesterSupervisor(num_workers=3)
    supervisor.start()

    for i in range(5):
        time.sleep(0.5)
        print(f"\n--- Status at t={0.5*(i+1):.1f}s ---")
        supervisor.status()

    print("\nStopping...")
    supervisor.stop()
    print("Final state:")
    supervisor.status()
```

[[Systems and concurrency]]