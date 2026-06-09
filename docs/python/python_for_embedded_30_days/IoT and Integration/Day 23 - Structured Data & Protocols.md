### JSON — fast enough until it isn't

python

```python
import json
import time

data = {"device_id": "dev_01", "readings": [{"var": "temp", "val": 22.4}] * 100}

# Encode
t0 = time.perf_counter()
for _ in range(10_000):
    raw = json.dumps(data).encode()
json_encode = time.perf_counter() - t0

# Decode
t0 = time.perf_counter()
for _ in range(10_000):
    json.loads(raw)
json_decode = time.perf_counter() - t0

print(f"JSON encode: {json_encode*1000:.1f}ms for 10k ops")
print(f"JSON decode: {json_decode*1000:.1f}ms for 10k ops")
print(f"JSON size:   {len(raw)} bytes")
```

JSON is fine for most IoT work. It becomes a bottleneck when: message rate exceeds ~10k/sec, payload includes large arrays, or devices have tight RAM/flash constraints.

---

### `struct` — binary protocol parsing

`struct.pack` and `struct.unpack` map directly to C struct layouts. This is the tool for parsing binary firmware packets, CAN frames, Modbus registers, sensor protocols:

python

```python
import struct

# Format string reference:
# > = big-endian, < = little-endian, ! = network (big-endian)
# B = uint8,  H = uint16, I = uint32, Q = uint64
# b = int8,   h = int16,  i = int32,  q = int64
# f = float32, d = float64
# s = bytes (prefix with count: 4s = 4 bytes)
# x = padding byte

# A simulated sensor packet:
# [magic: 2 bytes][device_id: 4 bytes][variable: 1 byte][value: 4 bytes float][checksum: 1 byte]
PACKET_FORMAT = "!2sIBfB"
PACKET_SIZE   = struct.calcsize(PACKET_FORMAT)   # 12 bytes

def encode_packet(device_id: int, variable: int, value: float) -> bytes:
    checksum = (device_id + variable) & 0xFF   # trivial checksum
    return struct.pack(PACKET_FORMAT, b"\xAB\xCD", device_id, variable, value, checksum)

def decode_packet(raw: bytes) -> dict:
    if len(raw) != PACKET_SIZE:
        raise ValueError(f"Expected {PACKET_SIZE} bytes, got {len(raw)}")
    magic, device_id, variable, value, checksum = struct.unpack(PACKET_FORMAT, raw)
    if magic != b"\xAB\xCD":
        raise ValueError(f"Bad magic: {magic!r}")
    expected_checksum = (device_id + variable) & 0xFF
    if checksum != expected_checksum:
        raise ValueError("Checksum mismatch")
    return {"device_id": device_id, "variable": variable, "value": value}


# CAN frame: [arbitration_id: 4 bytes][dlc: 1 byte][data: 8 bytes]
CAN_FRAME_FORMAT = "!IB8s"
CAN_FRAME_SIZE   = struct.calcsize(CAN_FRAME_FORMAT)

def decode_can_frame(raw: bytes) -> dict:
    arb_id, dlc, data = struct.unpack(CAN_FRAME_FORMAT, raw)
    return {
        "arbitration_id": hex(arb_id),
        "dlc":            dlc,
        "data":           data[:dlc].hex(),
    }
```

---

### MessagePack — binary JSON

MessagePack encodes JSON-compatible data in binary. Smaller and faster than JSON, no schema required:

bash

```bash
pip install msgpack
```

python

```python
import msgpack

data = {"device_id": "dev_01", "value": 22.4, "ts": 1700000000.0}

# Encode
packed = msgpack.packb(data, use_bin_type=True)
print(f"JSON:    {len(json.dumps(data).encode())} bytes")
print(f"MsgPack: {len(packed)} bytes")   # typically 30-50% smaller

# Decode
unpacked = msgpack.unpackb(packed, raw=False)
```

Use MessagePack when: you control both ends, bandwidth is constrained (cellular IoT), or you need faster serialization than JSON.

---

### Protobuf — schema-defined binary serialization

For large fleets where protocol stability and cross-language compatibility matter:

bash

```bash
pip install protobuf
```

protobuf

```protobuf
// sensor.proto
syntax = "proto3";
message SensorReading {
    string device_id = 1;
    string variable  = 2;
    float  value     = 3;
    double timestamp = 4;
}
```

python

```python
# After running: protoc --python_out=. sensor.proto
from sensor_pb2 import SensorReading

reading = SensorReading(device_id="dev_01", variable="temperature", value=22.4, timestamp=1700000000.0)
serialized = reading.SerializeToString()
print(f"Protobuf: {len(serialized)} bytes")

decoded = SensorReading()
decoded.ParseFromString(serialized)
print(decoded.device_id, decoded.value)
```

Protobuf requires a build step (compile `.proto` → Python code). Overhead pays off at scale with hundreds of message types and multi-language systems.

---

### Today's deliverable

python

```python
# protocol_benchmark.py
import json
import struct
import time
import random
from typing import Any

try:
    import msgpack
    HAS_MSGPACK = True
except ImportError:
    HAS_MSGPACK = False
    print("msgpack not installed — skipping. pip install msgpack")


# --- Binary packet protocol ---

PACKET_FMT  = "!2sIBfI"   # magic, device_id, variable_id, value, timestamp_sec
PACKET_SIZE = struct.calcsize(PACKET_FMT)
MAGIC       = b"\xAB\xCD"

VARIABLE_MAP = {0: "temperature", 1: "humidity", 2: "pressure"}

def pack_reading(device_id: int, variable_id: int, value: float, ts: int) -> bytes:
    return struct.pack(PACKET_FMT, MAGIC, device_id, variable_id, value, ts)

def unpack_reading(raw: bytes) -> dict:
    magic, device_id, variable_id, value, ts = struct.unpack(PACKET_FMT, raw)
    if magic != MAGIC:
        raise ValueError(f"Bad magic bytes: {magic!r}")
    return {
        "device_id":  f"dev_{device_id:04d}",
        "variable":   VARIABLE_MAP.get(variable_id, f"var_{variable_id}"),
        "value":      round(value, 4),
        "timestamp":  ts,
    }


# --- Benchmark ---

def benchmark(name: str, encode_fn, decode_fn, data: Any, n: int = 10_000) -> None:
    t0 = time.perf_counter()
    for _ in range(n):
        encoded = encode_fn(data)
    encode_time = time.perf_counter() - t0

    t0 = time.perf_counter()
    for _ in range(n):
        decoded = decode_fn(encoded)
    decode_time = time.perf_counter() - t0

    print(f"  {name:<12} size={len(encoded):>5}B  "
          f"encode={encode_time*1000:>6.1f}ms  "
          f"decode={decode_time*1000:>6.1f}ms  "
          f"({n} ops)")


if __name__ == "__main__":
    random.seed(42)

    # Test data
    reading_dict = {
        "device_id": "dev_0001",
        "variable":  "temperature",
        "value":     22.4123,
        "timestamp": 1700000000,
    }
    device_id_int = 1
    variable_id   = 0
    value         = 22.4123
    ts_int        = 1700000000

    print(f"Benchmarking serialization formats (10,000 ops each):\n")

    # JSON
    benchmark(
        "JSON",
        lambda d: json.dumps(d).encode(),
        lambda b: json.loads(b),
        reading_dict,
    )

    # struct (binary)
    benchmark(
        "struct",
        lambda _: pack_reading(device_id_int, variable_id, value, ts_int),
        lambda b: unpack_reading(b),
        None,
    )

    # MessagePack
    if HAS_MSGPACK:
        benchmark(
            "msgpack",
            lambda d: msgpack.packb(d, use_bin_type=True),
            lambda b: msgpack.unpackb(b, raw=False),
            reading_dict,
        )

    # Verify struct round-trip
    print("\nStruct round-trip verification:")
    raw = pack_reading(device_id_int, variable_id, value, ts_int)
    decoded = unpack_reading(raw)
    print(f"  Encoded: {raw.hex()}")
    print(f"  Decoded: {decoded}")
    print(f"  Packet size: {PACKET_SIZE} bytes vs JSON: {len(json.dumps(reading_dict
```
[[IoT and Integration]]