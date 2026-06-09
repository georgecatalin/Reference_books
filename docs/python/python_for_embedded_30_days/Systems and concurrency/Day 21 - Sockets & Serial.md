### Raw TCP — direct socket programming

Python's `socket` module maps almost 1:1 to POSIX sockets. Your C knowledge transfers directly:

python

```python
import socket
import struct
import threading

# --- TCP Server ---
def run_server(host: str = "0.0.0.0", port: int = 9000) -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as server:
        server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        server.bind((host, port))
        server.listen(5)
        print(f"Listening on {host}:{port}")

        while True:
            conn, addr = server.accept()
            t = threading.Thread(target=handle_client, args=(conn, addr), daemon=True)
            t.start()

def handle_client(conn: socket.socket, addr: tuple) -> None:
    with conn:
        print(f"  Connection from {addr}")
        while True:
            # Read a 4-byte length prefix, then that many bytes of data
            header = recv_exact(conn, 4)
            if not header:
                break
            length = struct.unpack(">I", header)[0]   # big-endian uint32
            data   = recv_exact(conn, length)
            if not data:
                break
            print(f"  Received: {data.decode()}")
            conn.sendall(b"ACK")

def recv_exact(conn: socket.socket, n: int) -> bytes:
    """Read exactly n bytes — handles partial reads."""
    buf = b""
    while len(buf) < n:
        chunk = conn.recv(n - len(buf))
        if not chunk:
            return b""
        buf += chunk
    return buf
```

`recv_exact` is critical — TCP is a stream protocol, not a message protocol. `recv(n)` may return fewer than `n` bytes. Always loop until you have exactly what you need.

---

### Non-blocking sockets and `select`

python

```python
import select

def multiplex_clients(sockets: list[socket.socket], timeout: float = 0.1) -> None:
    """Handle multiple sockets without threads."""
    while True:
        readable, _, exceptional = select.select(sockets, [], sockets, timeout)

        for s in readable:
            data = s.recv(1024)
            if data:
                process(data)
            else:
                s.close()
                sockets.remove(s)

        for s in exceptional:
            s.close()
            sockets.remove(s)
```

`select` is the polling mechanism you know from C. For new code, prefer `asyncio` (Day 18) — it's higher level and handles edge cases. Use `select` when you're integrating with existing synchronous code or need fine control.

---

### `pyserial` — serial port communication

python

```python
import serial
import serial.tools.list_ports

# List available ports
for port in serial.tools.list_ports.comports():
    print(f"  {port.device}: {port.description}")

# Open a port
with serial.Serial(
    port="/dev/ttyUSB0",
    baudrate=115200,
    bytesize=serial.EIGHTBITS,
    parity=serial.PARITY_NONE,
    stopbits=serial.STOPBITS_ONE,
    timeout=1.0,        # read timeout in seconds
    write_timeout=1.0,
) as port:
    # Write
    port.write(b"AT\r\n")

    # Read line
    line = port.readline()   # reads until \n or timeout
    print(line.decode("ascii").strip())

    # Read exact bytes
    data = port.read(16)

    # Check bytes waiting
    print(f"Bytes in buffer: {port.in_waiting}")
```

---

### Today's deliverable

python

```python
# tcp_telemetry_server.py
import socket
import struct
import threading
import json
import time
import random
from collections import defaultdict

# Wire protocol: [4-byte length (big-endian uint32)][JSON payload]

def encode_message(data: dict) -> bytes:
    payload = json.dumps(data).encode("utf-8")
    header  = struct.pack(">I", len(payload))
    return header + payload

def decode_message(raw: bytes) -> dict:
    return json.loads(raw.decode("utf-8"))

def recv_exact(conn: socket.socket, n: int) -> bytes:
    buf = b""
    while len(buf) < n:
        chunk = conn.recv(n - len(buf))
        if not chunk:
            raise ConnectionError("Connection closed")
        buf += chunk
    return buf


# --- Server ---

class TelemetryStore:
    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._data: dict = defaultdict(dict)

    def record(self, device_id: str, variable: str, value: float) -> None:
        with self._lock:
            self._data[device_id][variable] = value

    def dump(self) -> dict:
        with self._lock:
            return {k: dict(v) for k, v in self._data.items()}


def handle_client(conn: socket.socket, addr: tuple, store: TelemetryStore) -> None:
    print(f"  [server] client connected: {addr}")
    with conn:
        while True:
            try:
                header = recv_exact(conn, 4)
                length = struct.unpack(">I", header)[0]
                raw    = recv_exact(conn, length)
                msg    = decode_message(raw)
                store.record(msg["device_id"], msg["variable"], msg["value"])
                conn.sendall(encode_message({"status": "ok"}))
            except (ConnectionError, KeyError, json.JSONDecodeError) as e:
                print(f"  [server] client {addr} error: {e}")
                break
    print(f"  [server] client disconnected: {addr}")


def run_server(host: str, port: int, store: TelemetryStore, stop: threading.Event) -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as srv:
        srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        srv.bind((host, port))
        srv.listen(10)
        srv.settimeout(0.5)
        print(f"  [server] listening on {host}:{port}")
        while not stop.is_set():
            try:
                conn, addr = srv.accept()
                t = threading.Thread(
                    target=handle_client, args=(conn, addr, store), daemon=True
                )
                t.start()
            except socket.timeout:
                continue


# --- Client (simulated device) ---

def run_device_client(device_id: str, host: str, port: int, n_messages: int) -> None:
    variables = ["temperature", "humidity"]
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.connect((host, port))
        for i in range(n_messages):
            var = variables[i % 2]
            val = round(20 + random.gauss(0, 3), 2)
            msg = {"device_id": device_id, "variable": var, "value": val}
            s.sendall(encode_message(msg))
            header = recv_exact(s, 4)
            length = struct.unpack(">I", header)[0]
            ack    = decode_message(recv_exact(s, length))
            time.sleep(0.02)


if __name__ == "__main__":
    random.seed(42)
    HOST, PORT = "127.0.0.1", 19000

    store     = TelemetryStore()
    stop_flag = threading.Event()

    server_thread = threading.Thread(
        target=run_server, args=(HOST, PORT, store, stop_flag), daemon=True
    )
    server_thread.start()
    time.sleep(0.1)   # let server start

    # Launch 5 simulated device clients
    clients = [
        threading.Thread(
            target=run_device_client,
            args=(f"dev_{i:02d}", HOST, PORT, 10),
            daemon=True,
        )
        for i in range(5)
    ]
    for c in clients: c.start()
    for c in clients: c.join()

    time.sleep(0.2)
    stop_flag.set()

    print("\nFinal telemetry store:")
    for dev, vars_ in sorted(store.dump().items()):
        print(f"  {dev}: {vars_}")
```
[[Systems and concurrency]]