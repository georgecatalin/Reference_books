### `subprocess` — launching and controlling processes

python

```python
import subprocess
import shlex

# Run and capture output — the standard pattern
result = subprocess.run(
    ["ls", "-la", "/var/log"],
    capture_output=True,
    text=True,           # decode stdout/stderr as str (not bytes)
    timeout=10,
)

print(result.stdout)
print(result.returncode)   # 0 = success

# With shell=True — convenient but avoid in production (injection risk)
result = subprocess.run("ls -la | grep mqtt", shell=True, capture_output=True, text=True)

# Safe construction of commands from user input
cmd = shlex.split("ffmpeg -i input.mp4 output.gif")   # safe tokenization
```

Never build shell commands with string concatenation (`f"ls {user_input}"`) — that's a shell injection vulnerability. Use argument lists or `shlex.split` from trusted strings.

---

### `Popen` — long-running processes with pipes

python

```python
import subprocess
import threading

def stream_output(process: subprocess.Popen, prefix: str) -> None:
    """Read and print process output line by line in a thread."""
    for line in process.stdout:
        print(f"  [{prefix}] {line.rstrip()}")

proc = subprocess.Popen(
    ["python", "-u", "sensor_daemon.py"],   # -u = unbuffered output
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
    text=True,
)

# Stream stdout in background thread
t = threading.Thread(target=stream_output, args=(proc, "sensor"), daemon=True)
t.start()

# Wait with timeout
try:
    proc.wait(timeout=30)
except subprocess.TimeoutExpired:
    proc.kill()
    proc.wait()
```

---

### Signal handling — clean shutdown

python

```python
import signal
import sys

def handle_sigterm(signum, frame):
    print("SIGTERM received — cleaning up")
    cleanup()
    sys.exit(0)

signal.signal(signal.SIGTERM, handle_sigterm)
signal.signal(signal.SIGINT,  handle_sigterm)   # Ctrl+C

# In asyncio — use loop.add_signal_handler() instead (Day 19)
```

---

### Today's deliverable

python

```python
# process_supervisor.py
import subprocess
import threading
import time
import sys
import signal
import os
from typing import Optional

WORKER_SCRIPT = """
import time, random, sys
random.seed(int(sys.argv[1]) if len(sys.argv) > 1 else 0)
count = 0
while True:
    val = round(20 + random.gauss(0, 2), 2)
    print(f"reading: {val}", flush=True)
    count += 1
    if count == 10 and random.random() < 0.5:
        print("ERROR: simulated crash", flush=True)
        sys.exit(1)
    time.sleep(0.2)
"""


class ManagedProcess:
    def __init__(self, name: str, script: str, seed: int = 0) -> None:
        self.name        = name
        self._script     = script
        self._seed       = seed
        self._proc: Optional[subprocess.Popen] = None
        self._restarts   = 0
        self._stop_flag  = threading.Event()
        self._log_thread: Optional[threading.Thread] = None

    def start(self) -> None:
        self._proc = subprocess.Popen(
            [sys.executable, "-c", self._script, str(self._seed)],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
        )
        self._log_thread = threading.Thread(
            target=self._stream_logs, daemon=True
        )
        self._log_thread.start()
        print(f"  [{self.name}] started (PID {self._proc.pid})")

    def _stream_logs(self) -> None:
        for line in self._proc.stdout:
            print(f"  [{self.name}] {line.rstrip()}")

    def is_alive(self) -> bool:
        return self._proc is not None and self._proc.poll() is None

    def restart(self) -> None:
        self.stop()
        time.sleep(0.5)
        self._restarts += 1
        print(f"  [{self.name}] restarting (attempt {self._restarts})")
        self.start()

    def stop(self) -> None:
        if self._proc and self.is_alive():
            self._proc.terminate()
            try:
                self._proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                self._proc.kill()
                self._proc.wait()
        print(f"  [{self.name}] stopped")


class Supervisor:
    def __init__(self, max_restarts: int = 5) -> None:
        self._processes: list[ManagedProcess] = []
        self._max_restarts = max_restarts
        self._running = True

    def add(self, proc: ManagedProcess) -> None:
        self._processes.append(proc)

    def start_all(self) -> None:
        for p in self._processes:
            p.start()

    def stop_all(self) -> None:
        self._running = False
        for p in self._processes:
            p.stop()

    def supervise(self) -> None:
        """Monitor loop — restart crashed processes."""
        while self._running:
            for proc in self._processes:
                if not proc.is_alive():
                    if proc._restarts < self._max_restarts:
                        proc.restart()
                    else:
                        print(f"  [{proc.name}] max restarts reached — giving up")
                        self._processes.remove(proc)
                        break
            time.sleep(0.5)


if __name__ == "__main__":
    supervisor = Supervisor(max_restarts=3)

    for i in range(3):
        supervisor.add(ManagedProcess(f"sensor_{i}", WORKER_SCRIPT, seed=i))

    supervisor.start_all()

    def shutdown(sig, frame):
        print("\nShutting down supervisor...")
        supervisor.stop_all()
        sys.exit(0)

    signal.signal(signal.SIGINT, shutdown)
    signal.signal(signal.SIGTERM, shutdown)

    print("Supervisor running — Ctrl+C to stop\n")
    supervisor.supervise()
```
[[Systems and concurrency]]