### Docker — containerizing your Python service

dockerfile

```dockerfile
# Dockerfile
FROM python:3.11-slim

WORKDIR /app

# Install dependencies first (cached layer)
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy source
COPY . .

# Non-root user — security best practice
RUN useradd -m appuser && chown -R appuser /app
USER appuser

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
    CMD python -c "import httpx; httpx.get('http://localhost:8000/health').raise_for_status()"

EXPOSE 8000

CMD ["uvicorn", "iot_api:app", "--host", "0.0.0.0", "--port", "8000"]
```

bash

```bash
# Build
docker build -t iot-monitor:latest .

# Run
docker run -d \
  --name iot-monitor \
  -p 8000:8000 \
  -v /var/lib/iot:/data \
  -e DB_PATH=/data/telemetry.db \
  iot-monitor:latest

# Logs
docker logs -f iot-monitor
```

---

### `docker-compose` — service orchestration

yaml

```yaml
# docker-compose.yml
services:
  mqtt-broker:
    image: eclipse-mosquitto:2
    ports:
      - "1883:1883"
    volumes:
      - ./mosquitto.conf:/mosquitto/config/mosquitto.conf

  iot-monitor:
    build: .
    ports:
      - "8000:8000"
    environment:
      - MQTT_HOST=mqtt-broker
      - DB_PATH=/data/telemetry.db
    volumes:
      - iot-data:/data
    depends_on:
      - mqtt-broker
    restart: unless-stopped

volumes:
  iot-data:
```

---

### systemd — running on bare metal

ini

```ini
# /etc/systemd/system/iot-monitor.service
[Unit]
Description=IoT Monitor Service
After=network.target mosquitto.service
Wants=mosquitto.service

[Service]
Type=simple
User=iot
WorkingDirectory=/opt/iot-monitor
Environment=PYTHONPATH=/opt/iot-monitor
ExecStart=/opt/iot-monitor/.venv/bin/uvicorn iot_api:app --host 0.0.0.0 --port 8000
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

# Limits
LimitNOFILE=65536
MemoryMax=256M

[Install]
WantedBy=multi-user.target
```

bash

```bash
sudo systemctl daemon-reload
sudo systemctl enable iot-monitor
sudo systemctl start iot-monitor
sudo journalctl -u iot-monitor -f   # follow logs
```

---

### Structured logging — production-ready logs

python

```python
import logging
import json
import sys
from typing import Any

class JSONFormatter(logging.Formatter):
    """Emit logs as JSON — parseable by log aggregators (Loki, CloudWatch, etc.)"""

    def format(self, record: logging.LogRecord) -> str:
        log: dict[str, Any] = {
            "ts":      self.formatTime(record, "%Y-%m-%dT%H:%M:%S"),
            "level":   record.levelname,
            "logger":  record.name,
            "message": record.getMessage(),
        }
        if record.exc_info:
            log["exception"] = self.formatException(record.exc_info)
        if hasattr(record, "device_id"):
            log["device_id"] = record.device_id
        return json.dumps(log)

def setup_logging(level: str = "INFO", json_output: bool = False) -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(
        JSONFormatter() if json_output else
        logging.Formatter("%(asctime)s %(levelname)-8s %(name)s: %(message)s")
    )
    logging.basicConfig(level=level, handlers=[handler], force=True)
```

---

### Today's deliverable

Create the full deployment package for `iot_toolkit`:

```
iot_toolkit/
├── Dockerfile
├── docker-compose.yml
├── pyproject.toml
├── requirements.txt
├── requirements-dev.txt
├── .dockerignore
├── .env.example
└── iot_toolkit/
    ├── __init__.py
    ├── __main__.py
    ├── config.py          ← reads from env vars with fallbacks
    └── ... (your modules from previous days)
```

`config.py` should read from environment variables:

python

```python
# iot_toolkit/config.py
import os

MQTT_HOST    = os.getenv("MQTT_HOST",    "localhost")
MQTT_PORT    = int(os.getenv("MQTT_PORT", "1883"))
DB_PATH      = os.getenv("DB_PATH",      ":memory:")
LOG_LEVEL    = os.getenv("LOG_LEVEL",    "INFO")
LOG_JSON     = os.getenv("LOG_JSON",     "false").lower() == "true"
API_PORT     = int(os.getenv("API_PORT", "8000"))
```

Write a `Dockerfile` that builds the package, runs as a non-root user, exposes port 8000, and includes a health check. Write a `docker-compose.yml` that brings up mosquitto and the monitor together. Write a systemd unit file for bare-metal deployment.

Verify `docker build -t iot-toolkit .` completes without errors.

[[IoT and Integration]]