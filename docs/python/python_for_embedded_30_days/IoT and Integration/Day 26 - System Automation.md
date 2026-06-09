### `paramiko` — SSH in Python

bash

```bash
pip install paramiko
```

python

```python
import paramiko
from contextlib import contextmanager
from typing import Iterator

@contextmanager
def ssh_session(host: str, username: str, key_path: str) -> Iterator[paramiko.SSHClient]:
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(host, username=username, key_filename=key_path, timeout=10)
    try:
        yield client
    finally:
        client.close()

def run_remote(client: paramiko.SSHClient, command: str) -> tuple[str, str, int]:
    stdin, stdout, stderr = client.exec_command(command)
    out    = stdout.read().decode()
    err    = stderr.read().decode()
    status = stdout.channel.recv_exit_status()
    return out, err, status

# Usage
with ssh_session("192.168.1.100", "pi", "~/.ssh/id_rsa") as ssh:
    out, err, status = run_remote(ssh, "cat /proc/temperature")
    print(out)
```

---

### SSH tunneling — the Python equivalent of your PHP session manager

python

```python
import paramiko
import socket
import threading

class SSHTunnel:
    """Forward a remote port to a local port via SSH."""

    def __init__(
        self,
        ssh_host:    str,
        ssh_user:    str,
        remote_host: str,
        remote_port: int,
        local_port:  int,
        key_path:    str,
    ) -> None:
        self._ssh_host    = ssh_host
        self._ssh_user    = ssh_user
        self._remote_host = remote_host
        self._remote_port = remote_port
        self._local_port  = local_port
        self._key_path    = key_path
        self._client: paramiko.SSHClient | None = None
        self._stop        = threading.Event()

    def open(self) -> None:
        self._client = paramiko.SSHClient()
        self._client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        self._client.connect(
            self._ssh_host,
            username=self._ssh_user,
            key_filename=self._key_path,
        )
        transport = self._client.get_transport()
        self._server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._server.bind(("127.0.0.1", self._local_port))
        self._server.listen(5)
        self._thread = threading.Thread(target=self._accept_loop, args=(transport,), daemon=True)
        self._thread.start()
        print(f"Tunnel: localhost:{self._local_port} → {self._remote_host}:{self._remote_port}")

    def _accept_loop(self, transport: paramiko.Transport) -> None:
        while not self._stop.is_set():
            try:
                self._server.settimeout(0.5)
                local_conn, _ = self._server.accept()
            except socket.timeout:
                continue
            channel = transport.open_channel(
                "direct-tcpip",
                (self._remote_host, self._remote_port),
                local_conn.getpeername(),
            )
            threading.Thread(
                target=self._bridge, args=(local_conn, channel), daemon=True
            ).start()

    def _bridge(self, local: socket.socket, remote: paramiko.Channel) -> None:
        while True:
            r, _, _ = select.select([local, remote], [], [], 1.0)
            if local in r:
                data = local.recv(4096)
                if not data: break
                remote.send(data)
            if remote in r:
                data = remote.recv(4096)
                if not data: break
                local.send(data)
        local.close()
        remote.close()

    def close(self) -> None:
        self._stop.set()
        if self._client:
            self._client.close()
```

---

### Today's deliverable

python

```python
# remote_manager.py
import subprocess
import threading
import time
import random
from typing import Optional

# Simulated SSH operations (replace with real paramiko calls)

class SimulatedSSHClient:
    """Fake SSH client — replace with real paramiko for production."""

    def __init__(self, host: str, user: str) -> None:
        self.host = host
        self.user = user
        self._connected = False

    def connect(self) -> None:
        time.sleep(0.05)   # simulate handshake
        self._connected = True
        print(f"  [ssh] connected to {self.user}@{self.host}")

    def close(self) -> None:
        self._connected = False

    def run(self, command: str) -> tuple[str, str, int]:
        if not self._connected:
            raise RuntimeError("Not connected")
        time.sleep(random.uniform(0.01, 0.05))
        # Fake responses
        if "uptime" in command:
            return "up 3 days, 4:20", "", 0
        if "df " in command:
            return "/dev/sda1  8G  2G  6G  25% /", "", 0
        if "cat /sys/class/thermal" in command:
            return str(round(45 + random.gauss(0, 5), 1)), "", 0
        return f"output of: {command}", "", 0

    def __enter__(self):
        self.connect()
        return self

    def __exit__(self, *args):
        self.close()


class DeviceManager:
    def __init__(self, devices: list[dict]) -> None:
        self._devices = {d["id"]: d for d in devices}

    def _get_client(self, device_id: str) -> SimulatedSSHClient:
        d = self._devices.get(device_id)
        if not d:
            raise KeyError(f"Unknown device: {device_id}")
        return SimulatedSSHClient(d["host"], d.get("user", "pi"))

    def run_on_device(self, device_id: str, command: str) -> dict:
        try:
            with self._get_client(device_id) as ssh:
                out, err, status = ssh.run(command)
                return {
                    "device_id": device_id,
                    "command":   command,
                    "stdout":    out.strip(),
                    "stderr":    err.strip(),
                    "status":    status,
                    "ok":        status == 0,
                }
        except Exception as e:
            return {"device_id": device_id, "command": command, "error": str(e), "ok": False}

    def run_on_all(self, command: str, max_workers: int = 4) -> list[dict]:
        results: list[dict] = [None] * len(self._devices)   # type: ignore
        device_ids = list(self._devices.keys())
        lock  = threading.Lock()
        idx   = [0]

        def worker():
            while True:
                with lock:
                    if idx[0] >= len(device_ids):
                        return
                    device_id = device_ids[idx[0]]
                    i = idx[0]
                    idx[0] += 1
                results[i] = self.run_on_device(device_id, command)

        threads = [threading.Thread(target=worker, daemon=True) for _ in range(max_workers)]
        for t in threads: t.start()
        for t in threads: t.join()
        return results

    def collect_diagnostics(self, device_id: str) -> dict:
        commands = {
            "uptime":      "uptime",
            "disk":        "df -h /",
            "temperature": "cat /sys/class/thermal/thermal_zone0/temp",
        }
        return {
            "device_id": device_id,
            "diagnostics": {
                name: self.run_on_device(device_id, cmd)
                for name, cmd in commands.items()
            },
        }


if __name__ == "__main__":
    random.seed(42)

    devices = [
        {"id": f"dev_{i:02d}", "host": f"192.168.1.{100+i}", "user": "pi"}
        for i in range(5)
    ]

    manager = DeviceManager(devices)

    print("=== Run command on single device ===")
    result = manager.run_on_device("dev_00", "uptime")
    print(f"  {result}")

    print("\n=== Run command on all devices (parallel) ===")
    t0 = time.perf_counter()
    results = manager.run_on_all("uptime")
    elapsed = time.perf_counter() - t0
    for r in results:
        print(f"  {r['device_id']}: {r.get('stdout', r.get('error'))}")
    print(f"  ({elapsed*1000:.0f}ms with parallel execution)")

    print("\n=== Collect diagnostics ===")
    diag = manager.collect_diagnostics("dev_02")
    for name, result in diag["diagnostics"].items():
        print(f"  {name}: {result.get('stdout', result.get('error'))}")
```

[[IoT and Integration]]