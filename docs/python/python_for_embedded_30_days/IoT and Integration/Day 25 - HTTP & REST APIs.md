### `httpx` — async-first HTTP client

python

```python
import httpx
import asyncio

# Synchronous
with httpx.Client(timeout=10.0) as client:
    response = client.get("https://api.example.com/devices")
    response.raise_for_status()   # raises on 4xx/5xx
    devices = response.json()

# Async — preferred for IoT services
async def fetch_device_config(device_id: str) -> dict:
    async with httpx.AsyncClient(timeout=5.0) as client:
        r = await client.get(f"https://api.example.com/devices/{device_id}")
        r.raise_for_status()
        return r.json()

# Concurrent fetches
async def fetch_all_configs(device_ids: list[str]) -> list[dict]:
    async with httpx.AsyncClient() as client:
        tasks = [client.get(f"https://api.example.com/devices/{did}") for did in device_ids]
        responses = await asyncio.gather(*tasks, return_exceptions=True)
        return [r.json() for r in responses if not isinstance(r, Exception)]
```

---

### FastAPI — building the REST endpoint

bash

```bash
pip install fastapi uvicorn[standard]
```

python

```python
# api.py
from fastapi import FastAPI, HTTPException, BackgroundTasks
from pydantic import BaseModel
from typing import Optional
import asyncio
import time
import random

app = FastAPI(title="IoT Monitor API")

# In production, this would be your TelemetryDB from Day 24
_store: dict = {}

class ReadingIn(BaseModel):
    device_id: str
    variable:  str
    value:     float
    ts:        Optional[float] = None

class ReadingOut(BaseModel):
    device_id: str
    variable:  str
    value:     float
    ts:        float

@app.post("/readings", response_model=ReadingOut)
async def post_reading(reading: ReadingIn) -> ReadingOut:
    ts = reading.ts or time.time()
    _store.setdefault(reading.device_id, {})[reading.variable] = {
        "value": reading.value,
        "ts":    ts,
    }
    return ReadingOut(**reading.model_dump(), ts=ts)

@app.get("/devices")
async def list_devices() -> dict:
    return {"devices": list(_store.keys()), "count": len(_store)}

@app.get("/devices/{device_id}")
async def get_device(device_id: str) -> dict:
    if device_id not in _store:
        raise HTTPException(status_code=404, detail=f"Device {device_id!r} not found")
    return {"device_id": device_id, "readings": _store[device_id]}

@app.get("/health")
async def health() -> dict:
    return {"status": "ok", "ts": time.time()}
```

Run it: `uvicorn api:app --reload`

FastAPI auto-generates OpenAPI docs at `/docs` — immediately usable as a test interface for your device monitor.

---

### Wiring FastAPI to the MQTT ingester

The pattern for a real service: MQTT ingester runs as a background task, FastAPI serves the REST API, both share the same in-memory or SQLite store:

python

```python
from fastapi import FastAPI
from contextlib import asynccontextmanager
import asyncio

@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup: launch background MQTT ingestion
    task = asyncio.create_task(run_mqtt_ingester())
    yield
    # Shutdown: cancel cleanly
    task.cancel()
    try:
        await task
    except asyncio.CancelledError:
        pass

app = FastAPI(lifespan=lifespan)
```

---

### Today's deliverable

python

```python
# iot_api.py
from fastapi import FastAPI, HTTPException, Query
from pydantic import BaseModel, Field
from contextlib import asynccontextmanager
from typing import Optional
import asyncio
import time
import random
import json
from collections import defaultdict

# --- Shared state (replace with TelemetryDB in production) ---

_readings: dict   = defaultdict(dict)
_history:  dict   = defaultdict(list)
_devices:  dict   = {}


# --- Background MQTT simulation ---

async def mock_mqtt_ingester(stop: asyncio.Event) -> None:
    devices   = [f"dev_{i:02d}" for i in range(4)]
    variables = ["temperature", "humidity"]
    i = 0
    while not stop.is_set():
        device = devices[i % len(devices)]
        var    = variables[i % 2]
        value  = round(20 + random.gauss(0, 3), 2)
        ts     = time.time()

        _readings[device][var] = {"value": value, "ts": ts}
        _history[device].append({"variable": var, "value": value, "ts": ts})
        if len(_history[device]) > 100:
            _history[device] = _history[device][-100:]
        _devices[device] = {"last_seen": ts, "firmware": "v1.0.0"}

        i += 1
        try:
            await asyncio.wait_for(stop.wait(), timeout=0.1)
        except asyncio.TimeoutError:
            pass


# --- FastAPI app ---

_stop_event: asyncio.Event = asyncio.Event()

@asynccontextmanager
async def lifespan(app: FastAPI):
    random.seed(42)
    task = asyncio.create_task(mock_mqtt_ingester(_stop_event))
    yield
    _stop_event.set()
    await asyncio.gather(task, return_exceptions=True)

app = FastAPI(title="IoT Monitor", version="1.0.0", lifespan=lifespan)


# --- Models ---

class ReadingOut(BaseModel):
    device_id: str
    variable:  str
    value:     float
    ts:        float

class DeviceOut(BaseModel):
    device_id: str
    last_seen: float
    firmware:  Optional[str]
    variables: dict


# --- Routes ---

@app.get("/health")
async def health():
    return {"status": "ok", "devices": len(_devices), "ts": time.time()}

@app.get("/devices", response_model=list[DeviceOut])
async def list_devices():
    result = []
    for did, info in _devices.items():
        result.append(DeviceOut(
            device_id=did,
            last_seen=info["last_seen"],
            firmware=info.get("firmware"),
            variables=_readings.get(did, {}),
        ))
    return sorted(result, key=lambda d: d.device_id)

@app.get("/devices/{device_id}", response_model=DeviceOut)
async def get_device(device_id: str):
    if device_id not in _devices:
        raise HTTPException(404, f"Device {device_id!r} not found")
    info = _devices[device_id]
    return DeviceOut(
        device_id=device_id,
        last_seen=info["last_seen"],
        firmware=info.get("firmware"),
        variables=_readings.get(device_id, {}),
    )

@app.get("/devices/{device_id}/history")
async def get_history(
    device_id: str,
    variable:  Optional[str] = Query(None),
    limit:     int           = Query(20, ge=1, le=100),
):
    if device_id not in _devices:
        raise HTTPException(404, f"Device {device_id!r} not found")
    history = _history.get(device_id, [])
    if variable:
        history = [h for h in history if h["variable"] == variable]
    return {"device_id": device_id, "history": history[-limit:]}

@app.get("/readings", response_model=list[ReadingOut])
async def all_latest_readings():
    result = []
    for did, vars_ in _readings.items():
        for var, data in vars_.items():
            result.append(ReadingOut(device_id=did, variable=var, **data))
    return result
```

Run with `uvicorn iot_api:app --reload` and hit `http://localhost:8000/docs` to test all endpoints interactively.

[[IoT and Integration]]