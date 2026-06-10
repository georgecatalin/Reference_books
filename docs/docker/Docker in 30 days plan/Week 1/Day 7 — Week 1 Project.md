#### Week 1 Project: Containerize a Complete Application

Days 1–6 introduced:

- Containers and images
    
- Container lifecycle
    
- Port publishing and networking
    
- Image tags and layers
    
- Dockerfiles
    
- Runtime configuration
    
- `CMD`, `ENTRYPOINT`, and non-root users
    

Day 7 combines these into one practical project.

You will build a small **device-monitoring dashboard API** that resembles part of your MQTT project.

The application will:

- Serve a web dashboard
    
- Provide a JSON API
    
- Read configuration from environment variables
    
- Include a health endpoint
    
- Log requests to standard output
    
- Run with Gunicorn
    
- Run as a non-root user
    
- Be packaged in a reproducible Docker image
    

---

# 1. Day 7 objectives

By the end of today, you should be able to:

- Organize a Docker application project
    
- Write a complete Dockerfile
    
- Build a versioned image
    
- Run multiple containers from the same image
    
- Configure containers differently at runtime
    
- Publish application ports
    
- Inspect image and container configuration
    
- Test application endpoints
    
- Diagnose startup failures
    
- Replace a container with a newer image
    
- Explain the complete path from source code to running process
    

The central workflow is:

```text
Source code
    +
Dependency file
    +
Dockerfile
    +
.dockerignore
    ↓
docker build
    ↓
Versioned image
    ↓
docker run + runtime configuration
    ↓
Running container
    ↓
Test, inspect, diagnose, replace
```

---

# 2. The project architecture

For now, this is a single-container application:

```text
Browser or curl
      ↓
Docker host port 8080
      ↓
Container port 5000
      ↓
Gunicorn
      ↓
Flask application
```

The API will simulate an MQTT-device dashboard.

It will provide:

|Endpoint|Purpose|
|---|---|
|`/`|Simple HTML dashboard|
|`/api/devices`|Example device data|
|`/api/status`|Application configuration|
|`/health`|Health check|
|`/simulate-error`|Deliberate server error|

Later, this application can connect to:

- Mosquitto
    
- PostgreSQL
    
- Redis
    
- A background MQTT consumer
    

Today, keep it self-contained.

---

# 3. Create the project structure

Run:

```bash
mkdir -p ~/docker-course/day7/device-dashboard/templates
cd ~/docker-course/day7/device-dashboard
```

Create this structure:

```text
device-dashboard/
├── Dockerfile
├── .dockerignore
├── requirements.txt
├── app.py
└── templates/
    └── index.html
```

Check it later with:

```bash
find . -maxdepth 3 -type f
```

---

# 4. Create the Python application

Create `app.py`:

```bash
nano app.py
```

Add:

```python
import logging
import os
import socket
from datetime import datetime, timezone

from flask import Flask, jsonify, render_template


def create_app() -> Flask:
    application = Flask(__name__)

    logging.basicConfig(
        level=os.getenv("LOG_LEVEL", "INFO").upper(),
        format=(
            "%(asctime)s level=%(levelname)s "
            "service=device-dashboard message=%(message)s"
        ),
    )

    devices = [
        {
            "device_id": "vm-karlsfeld-01",
            "online": True,
            "firmware_version": "1.4.2",
            "cpu_percent": 12.7,
            "ram_used_mb": 438,
        },
        {
            "device_id": "testing-vm2",
            "online": True,
            "firmware_version": "1.3.8",
            "cpu_percent": 8.1,
            "ram_used_mb": 294,
        },
        {
            "device_id": "remote-device-03",
            "online": False,
            "firmware_version": "1.2.5",
            "cpu_percent": None,
            "ram_used_mb": None,
        },
    ]

    @application.get("/")
    def index():
        application.logger.info("Dashboard page requested")

        return render_template(
            "index.html",
            application_name=os.getenv(
                "APP_NAME",
                "MQTT Device Dashboard",
            ),
            environment=os.getenv(
                "APP_ENV",
                "development",
            ),
            version=os.getenv(
                "APP_VERSION",
                "unknown",
            ),
            devices=devices,
        )

    @application.get("/api/devices")
    def get_devices():
        application.logger.info("Device list requested")

        return jsonify(
            count=len(devices),
            devices=devices,
        )

    @application.get("/api/status")
    def status():
        return jsonify(
            application=os.getenv(
                "APP_NAME",
                "MQTT Device Dashboard",
            ),
            version=os.getenv(
                "APP_VERSION",
                "unknown",
            ),
            environment=os.getenv(
                "APP_ENV",
                "development",
            ),
            hostname=socket.gethostname(),
            timestamp=datetime.now(timezone.utc).isoformat(),
        )

    @application.get("/health")
    def health():
        return jsonify(
            status="healthy",
            service="device-dashboard",
        )

    @application.get("/simulate-error")
    def simulate_error():
        application.logger.error(
            "Deliberate training error requested"
        )

        raise RuntimeError(
            "This is a deliberate Day 7 training error"
        )

    return application


app = create_app()
```

This file does not start Flask directly.

Instead, it exposes:

```python
app
```

for Gunicorn to load.

---

# 5. Understand the application factory

The application uses:

```python
def create_app() -> Flask:
```

This function creates and configures the Flask application.

At the end:

```python
app = create_app()
```

creates the actual application object.

Gunicorn will load:

```text
app:app
```

This means:

```text
First app:
Python module app.py

Second app:
Flask object named app
```

Therefore:

```bash
gunicorn app:app
```

means:

> Import the `app` object from the Python module `app`.

---

# 6. Create the HTML dashboard

Create:

```bash
nano templates/index.html
```

Add:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>{{ application_name }}</title>

    <style>
        body {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
            font-family: Arial, sans-serif;
            background: #f5f5f5;
        }

        h1 {
            margin-bottom: 5px;
        }

        .metadata {
            margin-bottom: 25px;
            color: #555;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #ececec;
        }

        .online {
            font-weight: bold;
        }

        .offline {
            color: #666;
        }
    </style>
</head>

<body>
    <h1>{{ application_name }}</h1>

    <div class="metadata">
        Environment: {{ environment }}
        |
        Version: {{ version }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Device</th>
                <th>Status</th>
                <th>Firmware</th>
                <th>CPU</th>
                <th>RAM used</th>
            </tr>
        </thead>

        <tbody>
            {% for device in devices %}
            <tr>
                <td>{{ device.device_id }}</td>

                <td
                    class="{{
                        'online'
                        if device.online
                        else 'offline'
                    }}"
                >
                    {{
                        'Online'
                        if device.online
                        else 'Offline'
                    }}
                </td>

                <td>{{ device.firmware_version }}</td>

                <td>
                    {% if device.cpu_percent is not none %}
                        {{ device.cpu_percent }}%
                    {% else %}
                        -
                    {% endif %}
                </td>

                <td>
                    {% if device.ram_used_mb is not none %}
                        {{ device.ram_used_mb }} MB
                    {% else %}
                        -
                    {% endif %}
                </td>
            </tr>
            {% endfor %}
        </tbody>
    </table>
</body>
</html>
```

The template is part of the application source and must therefore be copied into the image.

---

# 7. Create the dependency file

Create:

```bash
nano requirements.txt
```

Add:

```text
Flask==3.1.1
gunicorn==23.0.0
```

Pinned versions improve predictability.

Without pinning:

```text
Flask
gunicorn
```

a future image build could install newer versions with changed behavior.

Pinned dependencies do not guarantee perfect reproducibility because package artifacts and transitive dependencies may still change, but they are better than completely unversioned dependencies.

---

# 8. Create `.dockerignore`

Create:

```bash
nano .dockerignore
```

Add:

```text
.git/
.gitignore

__pycache__/
*.pyc
*.pyo

.venv/
venv/

.env
*.env

*.log
coverage/
.pytest_cache/

README.md
Dockerfile.*
```

Do not add:

```text
Dockerfile
```

because Docker must read it.

Do not add:

```text
templates/
```

because the application needs that directory.

The objective is to keep the build context:

- Small
    
- Relevant
    
- Predictable
    
- Free from local artifacts
    

---

# 9. Create a production-oriented Dockerfile

Create:

```bash
nano Dockerfile
```

Add:

```dockerfile
FROM python:3.13-slim

LABEL org.opencontainers.image.title="MQTT Device Dashboard"
LABEL org.opencontainers.image.version="1.0.0"
LABEL org.opencontainers.image.description="Docker Week 1 project"

RUN groupadd --system appgroup \
    && useradd \
        --system \
        --gid appgroup \
        --home-dir /app \
        --shell /usr/sbin/nologin \
        appuser

WORKDIR /app

COPY requirements.txt .

RUN pip install \
        --no-cache-dir \
        --disable-pip-version-check \
        -r requirements.txt

COPY --chown=appuser:appgroup app.py .
COPY --chown=appuser:appgroup templates/ ./templates/

ENV APP_NAME="MQTT Device Dashboard"
ENV APP_ENV="production"
ENV APP_VERSION="1.0.0"
ENV LOG_LEVEL="INFO"
ENV PORT="5000"
ENV PYTHONUNBUFFERED="1"

EXPOSE 5000

USER appuser

CMD [
    "gunicorn",
    "--bind",
    "0.0.0.0:5000",
    "--workers",
    "2",
    "--access-logfile",
    "-",
    "--error-logfile",
    "-",
    "app:app"
]
```

The multiline JSON-form `CMD` is supported by modern Dockerfile syntax. To avoid parser issues on an older setup, write it on one line:

```dockerfile
CMD ["gunicorn", "--bind", "0.0.0.0:5000", "--workers", "2", "--access-logfile", "-", "--error-logfile", "-", "app:app"]
```

---

# 10. Understand every Dockerfile section

## Base image

```dockerfile
FROM python:3.13-slim
```

Provides Python and `pip`.

## Metadata

```dockerfile
LABEL ...
```

Documents the resulting image.

## Non-root account

```dockerfile
RUN groupadd ...
```

Creates:

```text
appgroup
appuser
```

The user has no interactive login shell because it exists only to run the application.

## Working directory

```dockerfile
WORKDIR /app
```

All relative paths now start from `/app`.

## Dependency installation

```dockerfile
COPY requirements.txt .

RUN pip install ...
```

Dependencies are installed before application source is copied, improving cache reuse.

## Application files

```dockerfile
COPY app.py .
COPY templates/ ./templates/
```

The source and template become part of the image.

## Runtime defaults

```dockerfile
ENV ...
```

Provides sensible defaults that can be overridden when creating containers.

## Port metadata

```dockerfile
EXPOSE 5000
```

Documents the expected container port.

## Least privilege

```dockerfile
USER appuser
```

The application runs without root privileges.

## Main process

```dockerfile
CMD [...]
```

Gunicorn remains in the foreground and becomes the managed application process.

---

# 11. Validate the project files

Run:

```bash
find . -maxdepth 3 -type f -print
```

Expected:

```text
./Dockerfile
./.dockerignore
./requirements.txt
./app.py
./templates/index.html
```

Inspect the Dockerfile:

```bash
cat Dockerfile
```

Inspect the dependency file:

```bash
cat requirements.txt
```

---

# 12. Build image version 1.0.0

Run:

```bash
docker build \
  -t device-dashboard:1.0.0 \
  .
```

The final dot is the build context.

Watch the output carefully:

```text
FROM
COPY requirements.txt
RUN pip install
COPY app.py
COPY templates
```

List the image:

```bash
docker image ls device-dashboard
```

Inspect its size:

```bash
docker image inspect device-dashboard:1.0.0 \
  --format '{{.Size}}'
```

The value is in bytes.

For a human-readable view:

```bash
docker image ls device-dashboard:1.0.0
```

---

# 13. Inspect the image before running it

Check the configured user:

```bash
docker image inspect device-dashboard:1.0.0 \
  --format 'User={{.Config.User}}'
```

Expected:

```text
User=appuser
```

Check the working directory:

```bash
docker image inspect device-dashboard:1.0.0 \
  --format 'Workdir={{.Config.WorkingDir}}'
```

Expected:

```text
Workdir=/app
```

Check ports:

```bash
docker image inspect device-dashboard:1.0.0 \
  --format 'Ports={{json .Config.ExposedPorts}}'
```

Expected:

```json
{"5000/tcp":{}}
```

Check the startup command:

```bash
docker image inspect device-dashboard:1.0.0 \
  --format 'Cmd={{json .Config.Cmd}}'
```

Check environment defaults:

```bash
docker image inspect device-dashboard:1.0.0 \
  --format '{{json .Config.Env}}'
```

---

# 14. Perform image-level tests

Before starting the web service, verify the image contents.

Check Python:

```bash
docker run --rm \
  device-dashboard:1.0.0 \
  python --version
```

Because the image uses only `CMD`, the command after the image name overrides Gunicorn.

Check the files:

```bash
docker run --rm \
  device-dashboard:1.0.0 \
  find /app -maxdepth 3 -type f -print
```

Check imports:

```bash
docker run --rm \
  device-dashboard:1.0.0 \
  python -c "from app import app; print(app.url_map)"
```

Check the user:

```bash
docker run --rm \
  device-dashboard:1.0.0 \
  id
```

Expected user:

```text
appuser
```

These tests catch errors before you run the long-lived service.

---

# 15. Run the dashboard

Start the container:

```bash
docker run -d \
  --name device-dashboard-v1 \
  -p 8080:5000 \
  device-dashboard:1.0.0
```

Confirm:

```bash
docker ps
```

Check port mapping:

```bash
docker port device-dashboard-v1
```

Expected conceptually:

```text
5000/tcp -> 0.0.0.0:8080
```

---

# 16. Inspect startup logs

Run:

```bash
docker logs device-dashboard-v1
```

You should see Gunicorn start and bind to:

```text
0.0.0.0:5000
```

Follow logs:

```bash
docker logs -f device-dashboard-v1
```

Stop following with `Ctrl+C`.

The container remains running.

---

# 17. Test the application

Test the dashboard:

```bash
curl http://localhost:8080/
```

Open in a browser:

```text
http://localhost:8080
```

Test the device API:

```bash
curl http://localhost:8080/api/devices
```

For formatted JSON, when Python is available on the host:

```bash
curl -s http://localhost:8080/api/devices \
  | python3 -m json.tool
```

Test status:

```bash
curl -s http://localhost:8080/api/status \
  | python3 -m json.tool
```

Test health:

```bash
curl -s http://localhost:8080/health \
  | python3 -m json.tool
```

---

# 18. Understand the hostname

The `/api/status` response contains:

```json
{
  "hostname": "..."
}
```

That hostname is normally the container’s hostname, often derived from its container ID.

Check directly:

```bash
docker exec device-dashboard-v1 hostname
```

Compare:

```bash
docker inspect device-dashboard-v1 \
  --format '{{.Config.Hostname}}'
```

Each container created from the same image receives its own container identity.

---

# 19. Inspect the running process

Run:

```bash
docker top device-dashboard-v1
```

You should see Gunicorn processes.

Inside the container, you may have:

- Gunicorn master process
    
- Gunicorn worker processes
    

The Docker container has one main responsibility—running the dashboard—even though the application server manages multiple worker processes.

The “one process per container” guideline does not literally prohibit an application server from creating workers. The container still has one primary responsibility.

---

# 20. Confirm the application runs as non-root

Run:

```bash
docker exec device-dashboard-v1 id
```

Expected:

```text
uid=... appuser
gid=... appgroup
```

Check file ownership:

```bash
docker exec device-dashboard-v1 \
  ls -l /app
```

Check the template directory:

```bash
docker exec device-dashboard-v1 \
  ls -l /app/templates
```

The application should be able to read its files without root privileges.

---

# 21. Test runtime configuration

The image contains defaults:

```text
APP_NAME=MQTT Device Dashboard
APP_ENV=production
APP_VERSION=1.0.0
LOG_LEVEL=INFO
```

Create a second container from the same image:

```bash
docker run -d \
  --name device-dashboard-test \
  -p 8081:5000 \
  -e APP_NAME="MQTT Dashboard - Test" \
  -e APP_ENV="testing" \
  -e APP_VERSION="1.0.0-test" \
  -e LOG_LEVEL="DEBUG" \
  device-dashboard:1.0.0
```

Test:

```bash
curl -s http://localhost:8081/api/status \
  | python3 -m json.tool
```

Compare:

```bash
curl -s http://localhost:8080/api/status \
  | python3 -m json.tool
```

Both containers use the same image but different configuration.

---

# 22. Confirm both use the same image

Run:

```bash
docker inspect device-dashboard-v1 \
  --format '{{.Image}}'
```

Then:

```bash
docker inspect device-dashboard-test \
  --format '{{.Image}}'
```

The exact image ID should be the same.

Their container IDs are different:

```bash
docker inspect device-dashboard-v1 \
  --format '{{.Id}}'
```

```bash
docker inspect device-dashboard-test \
  --format '{{.Id}}'
```

The model is:

```text
One image
├── Production-configured container
└── Testing-configured container
```

---

# 23. Use an environment file

Create:

```bash
nano development.env
```

Add:

```text
APP_NAME=MQTT Dashboard - Development
APP_ENV=development
APP_VERSION=1.0.0-dev
LOG_LEVEL=DEBUG
PORT=5000
```

Make sure `.dockerignore` excludes:

```text
*.env
```

Run:

```bash
docker run -d \
  --name device-dashboard-dev \
  --env-file development.env \
  -p 8082:5000 \
  device-dashboard:1.0.0
```

Test:

```bash
curl -s http://localhost:8082/api/status \
  | python3 -m json.tool
```

An environment file simplifies long runtime commands.

Do not place real credentials in a file that is committed to Git.

---

# 24. Trigger and inspect an application error

Call:

```bash
curl -v http://localhost:8080/simulate-error
```

You should receive a server error response.

Inspect logs:

```bash
docker logs --tail 50 device-dashboard-v1
```

You should see:

- Your application error log
    
- Python exception information
    
- HTTP request information
    

The container should remain running:

```bash
docker ps --filter name=device-dashboard-v1
```

This demonstrates an important distinction:

```text
One request failed
≠
The application process necessarily exited
```

Application errors should be logged and handled without always crashing the entire service.

---

# 25. Stop and restart the container

Stop:

```bash
docker stop device-dashboard-v1
```

Check:

```bash
docker ps -a \
  --filter name=device-dashboard-v1
```

Restart the same container:

```bash
docker start device-dashboard-v1
```

Test:

```bash
curl http://localhost:8080/health
```

The container retains:

- Its name
    
- Port mapping
    
- Environment values
    
- Image reference
    
- Container ID
    

because this is the same container.

---

# 26. Demonstrate image immutability

Edit the host template:

```bash
nano templates/index.html
```

Change the heading or add:

```html
<p>This is image version 2.0.0.</p>
```

Refresh:

```text
http://localhost:8080
```

The running application should still show the old content.

Why?

```text
Host source changed
    ≠
Existing image changed
    ≠
Running container changed
```

The original files were copied into the image during its build.

---

# 27. Build version 2.0.0

Update the Dockerfile labels and defaults:

```dockerfile
LABEL org.opencontainers.image.version="2.0.0"
```

Change:

```dockerfile
ENV APP_VERSION="2.0.0"
```

Build:

```bash
docker build \
  -t device-dashboard:2.0.0 \
  .
```

List both versions:

```bash
docker image ls device-dashboard
```

You should now have:

```text
device-dashboard:1.0.0
device-dashboard:2.0.0
```

---

# 28. Observe cache behavior

During the second build:

```dockerfile
COPY requirements.txt .
RUN pip install ...
```

should normally remain cached because `requirements.txt` did not change.

These steps should rebuild:

```dockerfile
COPY app.py .
COPY templates/ ./templates/
```

if the relevant source files changed.

This is why dependency files were copied before application source.

---

# 29. Test version 2 without replacing version 1

Run:

```bash
docker run -d \
  --name device-dashboard-v2 \
  -p 8083:5000 \
  device-dashboard:2.0.0
```

Test:

```bash
curl -s http://localhost:8083/api/status \
  | python3 -m json.tool
```

Open:

```text
http://localhost:8083
```

You now have:

```text
Host 8080 → version 1.0.0
Host 8083 → version 2.0.0
```

This is a simple form of side-by-side deployment testing.

---

# 30. Replace version 1 with version 2

First stop and remove the old production container:

```bash
docker rm -f device-dashboard-v1
```

Create its replacement using version 2:

```bash
docker run -d \
  --name device-dashboard-v1 \
  -p 8080:5000 \
  -e APP_ENV="production" \
  device-dashboard:2.0.0
```

Test:

```bash
curl -s http://localhost:8080/api/status \
  | python3 -m json.tool
```

The port and container name can remain consistent even though the container and image changed.

The actual replacement sequence is:

```text
Old container:
device-dashboard-v1
using image 1.0.0

        ↓ remove

New container:
device-dashboard-v1
using image 2.0.0
```

The name is reused, but it is a new container with a new container ID.

---

# 31. Confirm the container was replaced

Inspect the new container:

```bash
docker inspect device-dashboard-v1 \
  --format 'Container={{.Id}} Image={{.Config.Image}}'
```

It should report:

```text
Image=device-dashboard:2.0.0
```

This demonstrates:

> Containers do not update themselves to newer image versions. You replace them.

---

# 32. Roll back to version 1

Because you kept the old image, rollback is straightforward.

Remove version 2:

```bash
docker rm -f device-dashboard-v1
```

Run version 1 again:

```bash
docker run -d \
  --name device-dashboard-v1 \
  -p 8080:5000 \
  device-dashboard:1.0.0
```

Test:

```bash
curl -s http://localhost:8080/api/status \
  | python3 -m json.tool
```

This demonstrates why explicit version tags matter.

If you had only:

```text
device-dashboard:latest
```

it might be less clear which exact version was running.

---

# 33. Deliberately break the startup command

Create a broken version without modifying your valid Dockerfile permanently.

Copy it:

```bash
cp Dockerfile Dockerfile.broken
```

Edit:

```bash
nano Dockerfile.broken
```

Replace the correct command with:

```dockerfile
CMD ["gunicorn-invalid", "app:app"]
```

Build:

```bash
docker build \
  -f Dockerfile.broken \
  -t device-dashboard:broken \
  .
```

Run:

```bash
docker run \
  --name device-dashboard-broken \
  device-dashboard:broken
```

It should fail.

---

# 34. Diagnose the failed container

First:

```bash
docker ps
```

It will probably not appear.

Check all containers:

```bash
docker ps -a \
  --filter name=device-dashboard-broken
```

Inspect the exit code:

```bash
docker inspect device-dashboard-broken \
  --format 'Status={{.State.Status}} ExitCode={{.State.ExitCode}} Error={{.State.Error}}'
```

View logs:

```bash
docker logs device-dashboard-broken
```

For startup failures where Docker cannot launch the executable, the useful error may appear directly from `docker run` or in:

```text
.State.Error
```

The likely reason is:

```text
gunicorn-invalid:
executable not found
```

This commonly corresponds to exit code `127`, although exact behavior may vary depending on where startup fails.

---

# 35. Correct troubleshooting order

When a container fails, use this sequence:

```bash
docker ps -a
```

Then:

```bash
docker logs CONTAINER
```

Then:

```bash
docker inspect CONTAINER \
  --format 'Status={{.State.Status}} Exit={{.State.ExitCode}} Error={{.State.Error}}'
```

Then inspect the image command:

```bash
docker image inspect IMAGE \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'
```

Then test image contents:

```bash
docker run --rm IMAGE \
  which gunicorn
```

For the working image:

```bash
docker run --rm \
  device-dashboard:1.0.0 \
  which gunicorn
```

Do not begin by randomly modifying permissions or reinstalling Docker.

Follow the evidence.

---

# 36. Deliberately break application imports

Create another broken Dockerfile:

```bash
cp Dockerfile Dockerfile.missing-app
```

Edit it and remove:

```dockerfile
COPY --chown=appuser:appgroup app.py .
```

Build:

```bash
docker build \
  -f Dockerfile.missing-app \
  -t device-dashboard:missing-app \
  .
```

Run:

```bash
docker run \
  --name device-dashboard-missing-app \
  device-dashboard:missing-app
```

Gunicorn should fail because it cannot import:

```text
app:app
```

Inspect:

```bash
docker logs device-dashboard-missing-app
```

This teaches the difference between:

- Docker failing to start the executable
    
- The executable starting but the application failing to load
    

---

# 37. Deliberately break network access

Run a working image without publishing a port:

```bash
docker run -d \
  --name device-dashboard-hidden \
  device-dashboard:1.0.0
```

Confirm the application started:

```bash
docker logs device-dashboard-hidden
```

Try:

```bash
curl http://localhost:5000
```

This will not necessarily reach this container because no host mapping exists.

Check:

```bash
docker port device-dashboard-hidden
```

No mapping should be shown.

The application is working internally, but it is not published to the host.

---

# 38. Access the hidden container from another container

Create a network:

```bash
docker network create day7-network
```

Connect the running dashboard:

```bash
docker network connect \
  day7-network \
  device-dashboard-hidden
```

Use a temporary curl container:

```bash
docker run --rm \
  --network day7-network \
  curlimages/curl \
  http://device-dashboard-hidden:5000/health
```

This should work.

The host does not need a published port for container-to-container communication.

---

# 39. Understand internal and external addresses

For the published application:

```bash
docker run \
  -p 8080:5000 \
  device-dashboard:1.0.0
```

Host access uses:

```text
localhost:8080
```

Another container on the same user-defined network uses:

```text
container-name:5000
```

Not:

```text
container-name:8080
```

The model is:

```text
Outside Docker:
Docker-host-address:published-port

Inside Docker network:
container-name:internal-port
```

---

# 40. Test graceful shutdown

Start following logs:

```bash
docker logs -f device-dashboard-v1
```

In another terminal:

```bash
docker stop device-dashboard-v1
```

Observe Gunicorn’s shutdown messages.

Check:

```bash
docker inspect device-dashboard-v1 \
  --format 'Exit={{.State.ExitCode}} Finished={{.State.FinishedAt}}'
```

A normal graceful shutdown should usually produce exit code `0`.

Restart:

```bash
docker start device-dashboard-v1
```

---

# 41. Review image history

Run:

```bash
docker image history device-dashboard:1.0.0
```

Look for:

- Base-image layers
    
- User-creation layer
    
- Dependency-installation layer
    
- Source-copy layers
    
- Environment metadata
    
- Startup command metadata
    

Use full output:

```bash
docker image history \
  --no-trunc \
  device-dashboard:1.0.0
```

Notice that the dependency-installation layer is likely one of the larger custom layers.

---

# 42. Inspect the build context

Build with detailed progress:

```bash
docker build \
  --progress=plain \
  -t device-dashboard:inspection \
  .
```

Observe the context-transfer size.

Create a harmless ignored file:

```bash
mkdir -p __pycache__
dd if=/dev/zero \
  of=__pycache__/ignored-file.bin \
  bs=1M \
  count=5
```

Build again.

Because `.dockerignore` excludes `__pycache__/`, the file should not significantly increase the context transfer.

Remove it afterward:

```bash
rm -rf __pycache__
```

---

# 43. Validate reproducibility

Remove a test container:

```bash
docker rm -f device-dashboard-test
```

Recreate it from the same image and same configuration:

```bash
docker run -d \
  --name device-dashboard-test \
  -p 8081:5000 \
  -e APP_NAME="MQTT Dashboard - Test" \
  -e APP_ENV="testing" \
  -e APP_VERSION="1.0.0-test" \
  -e LOG_LEVEL="DEBUG" \
  device-dashboard:1.0.0
```

Test:

```bash
curl -s http://localhost:8081/api/status \
  | python3 -m json.tool
```

The result should be functionally equivalent to the previous container.

This is reproducibility:

```text
Same image
+
Same runtime configuration
=
Equivalent container behavior
```

---

# 44. What is part of the image?

Your image includes:

- Python runtime
    
- Gunicorn
    
- Flask
    
- `app.py`
    
- `templates/index.html`
    
- Default environment values
    
- Application user
    
- Startup command
    
- Port metadata
    

Your image does not include:

- Host port 8080
    
- Container name
    
- Runtime override values
    
- Restart policy
    
- Host IP address
    
- Docker network membership added at runtime
    
- Container ID
    

These are runtime concerns.

---

# 45. Build-time versus runtime decisions

## Build-time decisions

Stored in the image:

```text
Python version
Installed dependencies
Application source
Application user
Default command
Default environment variables
Exposed-port metadata
```

## Runtime decisions

Stored in the container configuration:

```text
Container name
Host port mapping
Environment overrides
Network membership
Restart policy
Resource limits
Mounted volumes
```

This separation is central to Docker.

---

# 46. Development versus production workflow

A simple development cycle is:

```text
Edit source
    ↓
Build new image
    ↓
Run test container
    ↓
Test application
    ↓
Fix errors
```

A production-like release cycle is:

```text
Commit source
    ↓
Build versioned image
    ↓
Run tests
    ↓
Tag release
    ↓
Push image to registry
    ↓
Deploy new container
    ↓
Verify health
    ↓
Retain previous image for rollback
```

You will automate more of this later.

---

# 47. Common project mistakes

## Copying the entire host directory too early

Less efficient:

```dockerfile
COPY . .
RUN pip install -r requirements.txt
```

Every source change can invalidate dependency installation.

Better:

```dockerfile
COPY requirements.txt .
RUN pip install -r requirements.txt
COPY app.py .
COPY templates/ ./templates/
```

---

## Running as root without need

Avoid leaving the default user as root when the application requires no privileged operations.

---

## Using Flask’s development server in production

For learning, it works. For a production-style project, Gunicorn is a better model.

---

## Binding to `127.0.0.1`

The application server should listen on:

```text
0.0.0.0:5000
```

inside the container.

---

## Expecting `EXPOSE` to publish the application

It does not.

You still need:

```bash
-p 8080:5000
```

---

## Rebuilding without using a new tag

Repeatedly using only:

```text
device-dashboard:latest
```

makes version tracking and rollback less clear.

Use explicit tags:

```text
device-dashboard:1.0.0
device-dashboard:2.0.0
```

---

## Manually repairing the running container

Avoid:

```bash
docker exec -it container sh
pip install missing-package
edit app.py
```

That repairs only one disposable container.

Correct the source, requirements, or Dockerfile, rebuild the image, and replace the container.

---

# 48. Week 1 practical checklist

You should now be able to perform all of these without detailed instructions:

```bash
docker build -t application:1.0 .
```

```bash
docker run -d \
  --name application \
  -p 8080:5000 \
  -e APP_ENV=production \
  application:1.0
```

```bash
docker ps
docker ps -a
```

```bash
docker logs application
docker logs -f application
```

```bash
docker exec application id
```

```bash
docker inspect application
```

```bash
docker stop application
docker start application
docker restart application
```

```bash
docker rm -f application
```

```bash
docker image inspect application:1.0
docker image history application:1.0
```

---

# 49. Day 7 final laboratory

Complete the following sequence.

## Phase 1 — Build

```bash
docker build \
  -t device-dashboard:1.0.0 \
  .
```

Validate:

```bash
docker run --rm \
  device-dashboard:1.0.0 \
  python -c "from app import app; print('Import successful')"
```

## Phase 2 — Run

```bash
docker run -d \
  --name dashboard-production \
  -p 8080:5000 \
  -e APP_ENV=production \
  device-dashboard:1.0.0
```

## Phase 3 — Verify

```bash
docker ps
docker port dashboard-production
docker logs dashboard-production
```

```bash
curl http://localhost:8080/health
curl http://localhost:8080/api/devices
curl http://localhost:8080/api/status
```

## Phase 4 — Inspect

```bash
docker exec dashboard-production id
```

```bash
docker inspect dashboard-production \
  --format 'Image={{.Config.Image}} User={{.Config.User}} Workdir={{.Config.WorkingDir}}'
```

## Phase 5 — Test another configuration

```bash
docker run -d \
  --name dashboard-testing \
  -p 8081:5000 \
  -e APP_ENV=testing \
  -e APP_NAME="Testing Dashboard" \
  device-dashboard:1.0.0
```

Compare both `/api/status` endpoints.

## Phase 6 — Break

Build an image with an invalid startup executable.

Run it and inspect:

```bash
docker ps -a
docker logs FAILED_CONTAINER
docker inspect FAILED_CONTAINER \
  --format 'Exit={{.State.ExitCode}} Error={{.State.Error}}'
```

## Phase 7 — Upgrade

Modify the page and application version.

Build:

```bash
docker build \
  -t device-dashboard:2.0.0 \
  .
```

Test it on a different port.

## Phase 8 — Replace

Remove the production container and recreate it from version 2.

## Phase 9 — Roll back

Replace version 2 with version 1.

## Phase 10 — Cleanup

Remove all Day 7 containers:

```bash
docker ps -a \
  --filter name=dashboard
```

Then remove them deliberately.

---

# 50. Week 1 knowledge check

## What is the difference between an image and a container?

An image is a reusable, read-only template. A container is a runtime instance created from that image.

## What keeps a container running?

Its main process. When the main process exits, the container stops.

## What does `docker run` do?

It creates and starts a new container.

## What does `docker start` do?

It starts an existing stopped container.

## What does `-p 8080:5000` mean?

Host port 8080 forwards to container port 5000.

## What does `localhost` mean inside a container?

The same container, not the Docker host or another container.

## What does `RUN` do in a Dockerfile?

It executes a command during image build and stores the resulting filesystem changes.

## What does `CMD` do?

It provides the default command executed when a container starts.

## Does `EXPOSE` publish a port?

No. It documents the intended container port.

## Why use `.dockerignore`?

To exclude irrelevant or sensitive files from the build context.

## Why copy dependency files separately?

To preserve dependency-installation cache when only source code changes.

## Why run as non-root?

To reduce unnecessary privileges and limit the impact of vulnerabilities.

## How do you update a container to a new image?

You remove or stop the old container and create a new container from the new image.

## Why use explicit image tags?

They make deployment, inspection, and rollback more predictable.

## Where should application logs go?

Normally to standard output and standard error.

---

# 51. Day 7 completion challenge

Complete this independently:

1. Create a web application with HTML and JSON endpoints.
    
2. Add a health endpoint.
    
3. Read application name, environment, version, and logging level from environment variables.
    
4. Pin application dependencies.
    
5. Create an effective `.dockerignore`.
    
6. Use a maintained slim base image.
    
7. Copy the dependency file before source code.
    
8. Install dependencies during image build.
    
9. Copy source files with correct ownership.
    
10. Create a non-root application user.
    
11. Run Gunicorn in the foreground.
    
12. Log access and errors to standard output.
    
13. Build image version `1.0.0`.
    
14. Validate imports before starting a long-running container.
    
15. Run it on host port 9080.
    
16. Test all routes.
    
17. Inspect its user, working directory, command, environment, and ports.
    
18. Run a second container with different runtime configuration.
    
19. Confirm both containers use the same image ID.
    
20. Trigger an application error and inspect the logs.
    
21. Change source code without rebuilding and observe that the running container does not change.
    
22. Build version `2.0.0`.
    
23. Run versions 1 and 2 simultaneously.
    
24. Replace version 1 with version 2.
    
25. Roll back to version 1.
    
26. Build a deliberately broken startup command.
    
27. Diagnose the failed container.
    
28. Fix the Dockerfile.
    
29. Rebuild and verify the corrected image.
    
30. Remove all temporary containers and broken images.
    

The complete Week 1 mental model is:

```text
Dockerfile + source
        ↓
docker build
        ↓
Image with application and defaults
        ↓
docker run
        ↓
Container with runtime configuration
        ↓
Main process
        ↓
Published service
```

The most important Week 1 principle is:

> The image is the reproducible application artifact. The container is a replaceable runtime instance. Fix the source or image, then replace the container rather than manually repairing it.