### The problem dataclasses solve

Every model class you write by hand has the same boilerplate: `__init__` that assigns fields, `__repr__`, `__eq__`. Dataclasses generate all of it from field declarations:

python

```python
from dataclasses import dataclass, field
from typing import Optional
import time

@dataclass
class SensorReading:
    device_id: str
    variable:  str
    value:     float
    ts:        float = field(default_factory=time.time)

    def is_alarm(self, low: float, high: float) -> bool:
        return not (low <= self.value <= high)
```

That generates `__init__`, `__repr__`, and `__eq__` automatically. The `field(default_factory=...)` pattern handles mutable defaults — never write `ts: float = time.time()` as a plain default (it evaluates once at class definition time, not per instance).

---

### Dataclass options

python

```python
@dataclass(frozen=True)   # immutable — sets __hash__ too, safe for sets/dicts
class DeviceID:
    value: str

@dataclass(order=True)    # generates __lt__, __le__, __gt__, __ge__
class Reading:
    ts:    float
    value: float

@dataclass(slots=True)    # Python 3.10+ — adds __slots__ automatically
class FastReading:
    device_id: str
    value:     float
```

`frozen=True` is the right default for value objects (readings, events, IDs) that shouldn't change after creation. `slots=True` is the right default for high-frequency objects.

---

### `field()` in detail

python

```python
from dataclasses import dataclass, field

@dataclass
class DeviceConfig:
    device_id:  str
    host:       str
    port:       int             = 1883
    topics:     list[str]       = field(default_factory=list)
    metadata:   dict[str, str]  = field(default_factory=dict)
    _internal:  str             = field(default="", repr=False, compare=False)
    #                                              ^^^^^^^^^^^^^^^^^^^^^^^^^
    #                                              excluded from repr and ==
```

Use `repr=False` for fields that are noisy or sensitive (connection objects, raw bytes). Use `compare=False` for fields that shouldn't affect equality (timestamps when comparing by value, internal state).

---

### `post_init` — validation after generation

python

```python
from dataclasses import dataclass
from typing import ClassVar

@dataclass
class SensorReading:
    device_id: str
    variable:  str
    value:     float
    ts:        float

    VALID_VARIABLES: ClassVar[set[str]] = {"temperature", "humidity", "pressure"}

    def __post_init__(self) -> None:
        if not self.device_id:
            raise ValueError("device_id cannot be empty")
        if self.variable not in self.VALID_VARIABLES:
            raise ValueError(f"Unknown variable: {self.variable!r}")
        # Coerce value to float in case an int was passed
        object.__setattr__(self, "value", float(self.value))
        #  ^^^ needed for frozen=True dataclasses — can't assign directly
```

`__post_init__` runs after the generated `__init__`. It's where you put validation, coercion, and derived field computation.

---

### Pydantic — dataclasses with real validation

`dataclass` validates types at development time (mypy). Pydantic validates at runtime — it actually coerces and rejects data. This is what you want at system boundaries: incoming MQTT JSON, REST API responses, config files.

bash

```bash
pip install pydantic
```

python

```python
from pydantic import BaseModel, Field, field_validator, model_validator
from typing import Optional
import time

class SensorReading(BaseModel):
    device_id: str
    variable:  str
    value:     float
    ts:        float = Field(default_factory=time.time)

    @field_validator("device_id")
    @classmethod
    def validate_device_id(cls, v: str) -> str:
        if not v.startswith("dev_") and not v.startswith("sensor_"):
            raise ValueError(f"device_id must start with 'dev_' or 'sensor_': {v!r}")
        return v.lower()

    @field_validator("value")
    @classmethod
    def validate_value(cls, v: float) -> float:
        if not (-273.15 <= v <= 10000):
            raise ValueError(f"value out of physical range: {v}")
        return v


class MQTTPayload(BaseModel):
    device_id: str
    readings:  list[SensorReading]
    firmware:  Optional[str] = None

    model_config = {"strict": False}   # allow coercion (str "22.4" → float 22.4)
```

Usage:

python

```python
# Valid payload
raw = {
    "device_id": "dev_01",
    "readings": [
        {"device_id": "dev_01", "variable": "temperature", "value": "22.4"},
        #                                                             ^^^^^ string — Pydantic coerces to float
    ]
}
payload = MQTTPayload.model_validate(raw)
print(payload.readings[0].value)   # 22.4 (float)

# Invalid payload
try:
    bad = SensorReading(device_id="unknown_01", variable="temp", value=22.4)
except ValueError as e:
    print(e)   # device_id must start with 'dev_' or 'sensor_'

# Serialize back to dict / JSON
print(payload.model_dump())
print(payload.model_dump_json())
```

---

### Dataclass vs Pydantic — when to use which

||`dataclass`|Pydantic `BaseModel`|
|---|---|---|
|Runtime validation|No|Yes|
|Type coercion|No|Yes|
|JSON serialization|Manual|Built-in|
|Performance|Faster|Slightly slower|
|Best for|Internal data, known-good data|System boundaries, external input|

Rule of thumb: use `dataclass` for internal data that you control, Pydantic for anything crossing a boundary (MQTT payload, HTTP response, config file, database row).

---

### Today's deliverable

python

```python
# payload_models.py
from __future__ import annotations
from dataclasses import dataclass, field
from pydantic import BaseModel, Field, field_validator
from typing import Optional
import time, json

# --- Internal model (dataclass — fast, no validation overhead) ---
@dataclass(slots=True, frozen=True)
class InternalReading:
    device_id: str
    variable:  str
    value:     float
    ts:        float


# --- Boundary model (Pydantic — validates incoming MQTT JSON) ---
class RawReading(BaseModel):
    variable: str
    value:    float

    @field_validator("value")
    @classmethod
    def clamp_check(cls, v: float) -> float:
        if not (-200.0 <= v <= 2000.0):
            raise ValueError(f"Physically implausible value: {v}")
        return round(v, 4)


class DevicePayload(BaseModel):
    device_id: str
    ts:        float        = Field(default_factory=time.time)
    readings:  list[RawReading]
    firmware:  Optional[str] = None

    @field_validator("device_id")
    @classmethod
    def normalize_id(cls, v: str) -> str:
        return v.strip().lower()

    def to_internal(self) -> list[InternalReading]:
        return [
            InternalReading(
                device_id=self.device_id,
                variable=r.variable,
                value=r.value,
                ts=self.ts,
            )
            for r in self.readings
        ]


# --- Simulate MQTT message arrival ---
GOOD_PAYLOAD = json.dumps({
    "device_id": "  SENSOR_01  ",
    "readings": [
        {"variable": "temperature", "value": "22.4"},   # string value
        {"variable": "humidity",    "value": 65},        # int value
    ],
    "firmware": "v1.2.3",
})

BAD_PAYLOAD = json.dumps({
    "device_id": "sensor_02",
    "readings": [
        {"variable": "temperature", "value": 9999.9},   # out of range
    ],
})

if __name__ == "__main__":
    # Good path
    payload = DevicePayload.model_validate_json(GOOD_PAYLOAD)
    print("Parsed:", payload)
    internals = payload.to_internal()
    print("Internal readings:", internals)
    print("Is frozen (try mutation):")
    try:
        internals[0].value = 99.9   # type: ignore
    except Exception as e:
        print(" ", e)

    # Bad path
    print("\nBad payload:")
    try:
        DevicePayload.model_validate_json(BAD_PAYLOAD)
    except Exception as e:
        print(" ", e)
```

Extend this with one more validator: reject any `device_id` that contains spaces after stripping, and raise a clear error message. Verify it fires correctly.
[[OOP and Design]]