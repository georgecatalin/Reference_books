### pytest — the standard

bash

```bash
pip install pytest pytest-asyncio
```

Test files are named `test_*.py`. Test functions are named `test_*`. That's the convention — pytest finds them automatically:

python

```python
# test_reading.py
from models.reading import SensorReading

def test_reading_equality():
    r1 = SensorReading("dev_01", "temp", 22.4, ts=1000.0)
    r2 = SensorReading("dev_01", "temp", 22.4, ts=1000.0)
    assert r1 == r2
    assert r1 is not r2

def test_reading_ordering():
    r1 = SensorReading("dev_01", "temp", 22.4, ts=1000.0)
    r2 = SensorReading("dev_01", "temp", 23.0, ts=2000.0)
    assert r1 < r2
    assert sorted([r2, r1]) == [r1, r2]

def test_reading_alarm():
    r = SensorReading("dev_01", "temp", 85.0, ts=1000.0)
    assert r.is_alarm(0.0, 80.0)
    assert not r.is_alarm(0.0, 90.0)
```

---

### Fixtures — reusable setup

python

```python
import pytest
from models.reading import SensorReading
from storage.db import TelemetryDB

@pytest.fixture
def sample_reading() -> SensorReading:
    return SensorReading("dev_01", "temperature", 22.4, ts=1000.0)

@pytest.fixture
def db() -> TelemetryDB:
    """In-memory DB for each test — no file cleanup needed."""
    database = TelemetryDB(":memory:")
    yield database
    database.close()

def test_db_insert(db: TelemetryDB, sample_reading: SensorReading):
    db.upsert_device(sample_reading.device_id)
    db.insert_readings([{
        "device_id": sample_reading.device_id,
        "variable":  sample_reading.variable,
        "value":     sample_reading.value,
        "ts":        sample_reading.ts,
    }])
    results = db.latest(sample_reading.device_id, sample_reading.variable)
    assert len(results) == 1
    assert results[0]["value"] == sample_reading.value
```

Fixtures with `yield` are like context managers — code before `yield` is setup, after `yield` is teardown.

---

### `unittest.mock` — replace hardware with fakes

python

```python
from unittest.mock import MagicMock, patch, AsyncMock
import pytest

def test_mqtt_publish_called():
    client = MagicMock()
    publish_fn = client.publish

    # Simulate your code calling publish
    publish_fn("devices/dev_01/temp", b"22.4", qos=1)

    publish_fn.assert_called_once_with("devices/dev_01/temp", b"22.4", qos=1)

def test_with_patch():
    """Patch a module-level object during the test."""
    with patch("mymodule.serial.Serial") as mock_serial:
        mock_serial.return_value.__enter__.return_value.read.return_value = b"22.4\n"
        result = read_temperature("/dev/ttyUSB0")
        assert result == 22.4

@pytest.mark.asyncio
async def test_async_fetch():
    mock_client = AsyncMock()
    mock_client.get.return_value.json.return_value = {"value": 22.4}
    result = await fetch_reading(mock_client, "dev_01")
    assert result == 22.4
```

---

### Parametrize — test multiple inputs

python

```python
@pytest.mark.parametrize("value,low,high,expected_alarm", [
    (22.4,  0.0,  50.0, False),   # normal range
    (85.0,  0.0,  80.0, True),    # too high
    (-5.0,  0.0,  50.0, True),    # too low
    (50.0,  0.0,  50.0, False),   # exactly at boundary
])
def test_alarm_threshold(value, low, high, expected_alarm):
    r = SensorReading("dev_01", "temp", value, ts=1000.0)
    assert r.is_alarm(low, high) == expected_alarm
```

---

### Today's deliverable

python

```python
# test_suite.py
import pytest
import json
import time
from unittest.mock import MagicMock, patch
from collections import defaultdict


# --- Code under test (inline for self-contained deliverable) ---

class SensorReading:
    __slots__ = ("device_id", "variable", "value", "ts")

    def __init__(self, device_id: str, variable: str, value: float, ts: float) -> None:
        self.device_id = device_id
        self.variable  = variable
        self.value     = value
        self.ts        = ts

    def __eq__(self, other: object) -> bool:
        if not isinstance(other, SensorReading):
            return NotImplemented
        return (self.device_id, self.variable, self.value, self.ts) == \
               (other.device_id, other.variable, other.value, other.ts)

    def __lt__(self, other: object) -> bool:
        if not isinstance(other, SensorReading):
            return NotImplemented
        return self.ts < other.ts

    def is_alarm(self, low: float, high: float) -> bool:
        return not (low <= self.value <= high)

    @classmethod
    def from_mqtt(cls, topic: str, payload: bytes) -> "SensorReading":
        parts = topic.split("/")
        data  = json.loads(payload)
        return cls(parts[1], parts[2], float(data["value"]), float(data.get("ts", time.time())))


class TelemetryBuffer:
    def __init__(self) -> None:
        from collections import deque
        self._data: dict = defaultdict(lambda: __import__('collections').deque(maxlen=50))

    def push(self, r: SensorReading) -> None:
        self._data[r.device_id].append(r)

    def latest(self, device_id: str) -> "SensorReading | None":
        buf = self._data.get(device_id)
        return buf[-1] if buf else None

    def count(self, device_id: str) -> int:
        return len(self._data.get(device_id, []))


class MQTTIngester:
    def __init__(self, buffer: TelemetryBuffer, mqtt_client) -> None:
        self._buffer = buffer
        self._client = mqtt_client
        self._errors = 0

    def start(self) -> None:
        self._client.subscribe("devices/+/+", self._on_message)
        self._client.connect()

    def _on_message(self, topic: str, payload: bytes) -> None:
        try:
            reading = SensorReading.from_mqtt(topic, payload)
            self._buffer.push(reading)
        except Exception:
            self._errors += 1

    @property
    def error_count(self) -> int:
        return self._errors


# --- Fixtures ---

@pytest.fixture
def reading():
    return SensorReading("dev_01", "temperature", 22.4, ts=1000.0)

@pytest.fixture
def buffer():
    return TelemetryBuffer()

@pytest.fixture
def mock_mqtt():
    client = MagicMock()
    client.connect = MagicMock()
    client.subscribe = MagicMock()
    return client


# --- Tests ---

class TestSensorReading:
    def test_equality(self, reading):
        other = SensorReading("dev_01", "temperature", 22.4, ts=1000.0)
        assert reading == other
        assert reading is not other

    def test_inequality_value(self, reading):
        other = SensorReading("dev_01", "temperature", 23.0, ts=1000.0)
        assert reading != other

    def test_ordering(self):
        r1 = SensorReading("dev_01", "temp", 22.4, ts=1000.0)
        r2 = SensorReading("dev_01", "temp", 22.4, ts=2000.0)
        assert r1 < r2
        assert sorted([r2, r1]) == [r1, r2]

    @pytest.mark.parametrize("value,low,high,alarm", [
        (22.4,  0.0,  50.0, False),
        (85.0,  0.0,  80.0, True),
        (-5.0,  0.0,  50.0, True),
        (50.0,  0.0,  50.0, False),
        (50.01, 0.0,  50.0, True),
    ])
    def test_alarm(self, value, low, high, alarm):
        r = SensorReading("dev_01", "temp", value, ts=1000.0)
        assert r.is_alarm(low, high) == alarm

    def test_from_mqtt_valid(self):
        topic   = "devices/dev_01/temperature"
        payload = json.dumps({"value": "22.4", "ts": 1000.0}).encode()
        r = SensorReading.from_mqtt(topic, payload)
        assert r.device_id == "dev_01"
        assert r.variable  == "temperature"
        assert r.value     == 22.4

    def test_from_mqtt_invalid_json(self):
        with pytest.raises(Exception):
            SensorReading.from_mqtt("devices/dev_01/temp", b"not-json")


class TestTelemetryBuffer:
    def test_push_and_latest(self, buffer, reading):
        buffer.push(reading)
        assert buffer.latest("dev_01") == reading

    def test_unknown_device_returns_none(self, buffer):
        assert buffer.latest("nonexistent") is None

    def test_count(self, buffer, reading):
        buffer.push(reading)
        buffer.push(SensorReading("dev_01", "temperature", 23.0, ts=2000.0))
        assert buffer.count("dev_01") == 2

    def test_multiple_devices(self, buffer):
        buffer.push(SensorReading("dev_01", "temp", 22.4, ts=1000.0))
        buffer.push(SensorReading("dev_02", "temp", 30.0, ts=1001.0))
        assert buffer.count("dev_01") == 1
        assert buffer.count("dev_02") == 1
        assert buffer.latest("dev_01").value == 22.4


class TestMQTTIngester:
    def test_subscribe_on_start(self, mock_mqtt, buffer):
        ingester = MQTTIngester(buffer, mock_mqtt)
        ingester.start()
        mock_mqtt.subscribe.assert_called_once()
        mock_mqtt.connect.assert_called_once()

    def test_valid_message_stored(self, mock_mqtt, buffer):
        ingester = MQTTIngester(buffer, mock_mqtt)
        ingester.start()

        # Simulate message arrival by calling the callback directly
        callback = mock_mqtt.subscribe.call_args[0][1]
        callback(
            "devices/dev_01/temperature",
            json.dumps({"value": 22.4, "ts": 1000.0}).encode(),
        )

        assert buffer.count("dev_01") == 1
        assert buffer.latest("dev_01").value == 22.4
        assert ingester.error_count == 0

    def test_bad_message_increments_error(self, mock_mqtt, buffer):
        ingester = MQTTIngester(buffer, mock_mqtt)
        ingester.start()

        callback = mock_mqtt.subscribe.call_args[0][1]
        callback("devices/dev_01/temperature", b"not-valid-json")

        assert ingester.error_count == 1
        assert buffer.count("dev_01") == 0
```

Run with `pytest test_suite.py -v`. All tests should pass.

[[IoT and Integration]]