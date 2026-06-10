

Day 11 taught you how containers communicate through a user-defined Docker network using names such as:

```text
database:5432
```

Today you will build a real two-container application:

```text
Browser
   ↓
Host port 8080
   ↓
Flask application container
   ↓ Docker network
PostgreSQL container
   ↓
Named volume
```

The application will:

- Connect to PostgreSQL using the hostname `database`
    
- Create its database table automatically
    
- Store and retrieve device records
    
- Retry when PostgreSQL is not ready
    
- Expose only the web application to the host
    
- Keep PostgreSQL private inside Docker
    
- Preserve database records in a named volume
    

By the end of Day 12, you should understand:

- How one container connects to another
    
- Why the database hostname is a Docker DNS name
    
- Why the database uses its internal port
    
- Why PostgreSQL does not need a published port
    
- How runtime environment variables configure connections
    
- Why network availability does not equal application readiness
    
- How to implement connection retries
    
- How to diagnose DNS, TCP, authentication, and database errors
    
- How application and database lifecycles differ
    
- How to replace containers without losing database records
    

---

# 1. The final architecture

You will create:

```text
                      Docker host

Browser
   │
   │ http://localhost:8080
   ▼
┌───────────────────────────────┐
│ Application container         │
│                               │
│ Flask + Gunicorn              │
│ Internal port: 5000           │
│ Published host port: 8080     │
└──────────────┬────────────────┘
               │
               │ database:5432
               │
               ▼
┌───────────────────────────────┐
│ PostgreSQL container          │
│                               │
│ Internal port: 5432           │
│ No published host port        │
└──────────────┬────────────────┘
               │
               ▼
       Named Docker volume
```

Both containers belong to:

```text
day12-backend
```

Only the application publishes a host port.

---

# 2. Create the project structure

Create the project:

```bash
mkdir -p ~/docker-course/day12/device-api/templates
cd ~/docker-course/day12/device-api
```

The final project will contain:

```text
device-api/
├── Dockerfile
├── .dockerignore
├── requirements.txt
├── app.py
└── templates/
    └── index.html
```

For now, you will operate the two containers manually. Docker Compose will simplify this in the next lessons.

---

# 3. Create the Python dependencies

Create `requirements.txt`:

```bash
nano requirements.txt
```

Add:

```text
Flask==3.1.1
gunicorn==23.0.0
psycopg[binary]==3.2.9
```

The new dependency is:

```text
psycopg
```

It is a PostgreSQL driver for Python.

It allows Python code to:

- Open PostgreSQL connections
    
- Execute SQL statements
    
- Read query results
    
- Insert and update records
    
- Handle database transactions
    

The `[binary]` variant provides prebuilt binary components, making installation simpler for this training image.

---

# 4. Create the application

Create `app.py`:

```bash
nano app.py
```

Add:

```python
import logging
import os
import time
from contextlib import contextmanager
from typing import Iterator

import psycopg
from flask import Flask, jsonify, render_template, request
from psycopg import Connection
from psycopg.rows import dict_row


logging.basicConfig(
    level=os.getenv("LOG_LEVEL", "INFO").upper(),
    format=(
        "%(asctime)s level=%(levelname)s "
        "service=device-api message=%(message)s"
    ),
)

logger = logging.getLogger(__name__)


def required_environment(name: str) -> str:
    value = os.getenv(name)

    if not value:
        raise RuntimeError(
            f"Required environment variable {name} is missing"
        )

    return value


def database_configuration() -> dict[str, object]:
    return {
        "host": required_environment("DB_HOST"),
        "port": int(os.getenv("DB_PORT", "5432")),
        "dbname": required_environment("DB_NAME"),
        "user": required_environment("DB_USER"),
        "password": required_environment("DB_PASSWORD"),
        "connect_timeout": 5,
    }


@contextmanager
def database_connection() -> Iterator[Connection]:
    connection = psycopg.connect(
        **database_configuration(),
        row_factory=dict_row,
    )

    try:
        yield connection
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


def wait_for_database(
    attempts: int = 15,
    delay_seconds: int = 2,
) -> None:
    config = database_configuration()

    for attempt in range(1, attempts + 1):
        try:
            with psycopg.connect(**config) as connection:
                with connection.cursor() as cursor:
                    cursor.execute("SELECT 1")

            logger.info(
                "Database connection established on attempt %d",
                attempt,
            )
            return

        except psycopg.OperationalError as error:
            logger.warning(
                "Database unavailable on attempt %d/%d: %s",
                attempt,
                attempts,
                error,
            )

            if attempt < attempts:
                time.sleep(delay_seconds)

    raise RuntimeError(
        f"Database remained unavailable after {attempts} attempts"
    )


def initialize_database() -> None:
    create_table_sql = """
        CREATE TABLE IF NOT EXISTS devices (
            id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            device_name TEXT NOT NULL UNIQUE,
            online BOOLEAN NOT NULL DEFAULT FALSE,
            firmware_version TEXT NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    """

    seed_data_sql = """
        INSERT INTO devices (
            device_name,
            online,
            firmware_version
        )
        VALUES
            ('vm-karlsfeld-01', TRUE, '1.4.2'),
            ('testing-vm2', TRUE, '1.3.8'),
            ('remote-device-03', FALSE, '1.2.5')
        ON CONFLICT (device_name) DO NOTHING
    """

    with database_connection() as connection:
        with connection.cursor() as cursor:
            cursor.execute(create_table_sql)
            cursor.execute(seed_data_sql)

    logger.info("Database schema initialized")


def create_app() -> Flask:
    application = Flask(__name__)

    wait_for_database()
    initialize_database()

    @application.get("/")
    def index():
        with database_connection() as connection:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    SELECT
                        id,
                        device_name,
                        online,
                        firmware_version,
                        created_at,
                        updated_at
                    FROM devices
                    ORDER BY id
                    """
                )

                devices = cursor.fetchall()

        return render_template(
            "index.html",
            devices=devices,
            environment=os.getenv("APP_ENV", "development"),
            application_version=os.getenv(
                "APP_VERSION",
                "unknown",
            ),
        )

    @application.get("/api/devices")
    def list_devices():
        with database_connection() as connection:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    SELECT
                        id,
                        device_name,
                        online,
                        firmware_version,
                        created_at,
                        updated_at
                    FROM devices
                    ORDER BY id
                    """
                )

                devices = cursor.fetchall()

        return jsonify(
            count=len(devices),
            devices=devices,
        )

    @application.post("/api/devices")
    def create_device():
        payload = request.get_json(silent=True) or {}

        device_name = payload.get("device_name")
        firmware_version = payload.get("firmware_version")
        online = payload.get("online", False)

        if not device_name or not firmware_version:
            return jsonify(
                error=(
                    "device_name and firmware_version "
                    "are required"
                )
            ), 400

        try:
            with database_connection() as connection:
                with connection.cursor() as cursor:
                    cursor.execute(
                        """
                        INSERT INTO devices (
                            device_name,
                            online,
                            firmware_version
                        )
                        VALUES (%s, %s, %s)
                        RETURNING
                            id,
                            device_name,
                            online,
                            firmware_version,
                            created_at,
                            updated_at
                        """,
                        (
                            device_name,
                            bool(online),
                            firmware_version,
                        ),
                    )

                    device = cursor.fetchone()

        except psycopg.errors.UniqueViolation:
            return jsonify(
                error=f"Device {device_name} already exists"
            ), 409

        return jsonify(device=device), 201

    @application.patch("/api/devices/<int:device_id>")
    def update_device(device_id: int):
        payload = request.get_json(silent=True) or {}

        if "online" not in payload:
            return jsonify(
                error="The online field is required"
            ), 400

        with database_connection() as connection:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    UPDATE devices
                    SET
                        online = %s,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = %s
                    RETURNING
                        id,
                        device_name,
                        online,
                        firmware_version,
                        created_at,
                        updated_at
                    """,
                    (
                        bool(payload["online"]),
                        device_id,
                    ),
                )

                device = cursor.fetchone()

        if device is None:
            return jsonify(
                error=f"Device ID {device_id} was not found"
            ), 404

        return jsonify(device=device)

    @application.get("/health")
    def health():
        try:
            with database_connection() as connection:
                with connection.cursor() as cursor:
                    cursor.execute("SELECT 1")

            return jsonify(
                status="healthy",
                database="connected",
            )

        except psycopg.Error as error:
            logger.error(
                "Health check database failure: %s",
                error,
            )

            return jsonify(
                status="unhealthy",
                database="unavailable",
            ), 503

    @application.get("/api/config")
    def configuration():
        return jsonify(
            application_environment=os.getenv(
                "APP_ENV",
                "development",
            ),
            application_version=os.getenv(
                "APP_VERSION",
                "unknown",
            ),
            database_host=os.getenv("DB_HOST"),
            database_port=int(
                os.getenv("DB_PORT", "5432")
            ),
            database_name=os.getenv("DB_NAME"),
        )

    return application


app = create_app()
```

---

# 5. What the application does at startup

When Gunicorn imports:

```text
app:app
```

Python executes:

```python
app = create_app()
```

Inside `create_app()`:

```python
wait_for_database()
initialize_database()
```

Therefore, startup follows this sequence:

```text
Application process starts
        ↓
Read database environment variables
        ↓
Resolve DB_HOST using Docker DNS
        ↓
Attempt TCP connection to PostgreSQL
        ↓
Authenticate
        ↓
Create table if missing
        ↓
Insert seed records if missing
        ↓
Start serving HTTP requests
```

If PostgreSQL is still starting, the application retries.

---

# 6. Why use a context manager for connections?

The application defines:

```python
@contextmanager
def database_connection():
```

This allows:

```python
with database_connection() as connection:
```

The helper guarantees that the application:

- Opens the connection
    
- Commits successful work
    
- Rolls back failed work
    
- Closes the connection
    

Without correct cleanup, a web application can leak database connections.

For production systems with significant traffic, you would normally use connection pooling. Today’s direct-connection pattern keeps the learning objective clear.

---

# 7. Why SQL parameters use `%s`

The insert query uses:

```python
VALUES (%s, %s, %s)
```

and passes values separately:

```python
(
    device_name,
    bool(online),
    firmware_version,
)
```

This is correct parameterized SQL.

Do not construct SQL like this:

```python
sql = (
    "INSERT INTO devices VALUES ('"
    + device_name
    + "')"
)
```

String concatenation can cause:

- SQL injection vulnerabilities
    
- Incorrect quoting
    
- Errors with special characters
    
- Type-conversion problems
    

Parameterized queries keep SQL code and data separate.

---

# 8. Create the HTML template

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

    <title>Device Monitor</title>

    <style>
        body {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
            font-family: Arial, sans-serif;
            background: #f4f4f4;
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
            background: #e9e9e9;
        }

        .online {
            font-weight: bold;
        }

        .offline {
            color: #777;
        }
    </style>
</head>

<body>
    <h1>Device Monitor</h1>

    <div class="metadata">
        Environment: {{ environment }}
        |
        Version: {{ application_version }}
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Device</th>
                <th>Status</th>
                <th>Firmware</th>
                <th>Last updated</th>
            </tr>
        </thead>

        <tbody>
            {% for device in devices %}
            <tr>
                <td>{{ device.id }}</td>

                <td>{{ device.device_name }}</td>

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

                <td>{{ device.updated_at }}</td>
            </tr>
            {% endfor %}
        </tbody>
    </table>
</body>
</html>
```

---

# 9. Create `.dockerignore`

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
.pytest_cache/
coverage/
```

---

# 10. Create the application Dockerfile

Create:

```bash
nano Dockerfile
```

Add:

```dockerfile
FROM python:3.13-slim

LABEL org.opencontainers.image.title="Day 12 Device API"
LABEL org.opencontainers.image.version="1.0.0"

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

ENV APP_ENV="production"
ENV APP_VERSION="1.0.0"
ENV LOG_LEVEL="INFO"
ENV DB_PORT="5432"
ENV PYTHONUNBUFFERED="1"

EXPOSE 5000

USER appuser

CMD ["gunicorn", "--bind", "0.0.0.0:5000", "--workers", "1", "--access-logfile", "-", "--error-logfile", "-", "--timeout", "30", "app:app"]
```

---

# 11. Why use one Gunicorn worker today?

The command uses:

```text
--workers 1
```

With multiple Gunicorn workers, each worker imports the application and may execute:

```python
initialize_database()
```

The SQL is written to be safe enough for repeated execution because it uses:

```sql
CREATE TABLE IF NOT EXISTS
```

and:

```sql
ON CONFLICT DO NOTHING
```

However, database schema migrations should not normally depend on every web worker running initialization.

For today, one worker makes startup behavior easier to observe.

In a more mature application, you would use a dedicated migration step.

---

# 12. Build the application image

Run:

```bash
docker build \
  -t day12-device-api:1.0.0 \
  .
```

Validate the image:

```bash
docker run --rm \
  day12-device-api:1.0.0 \
  python --version
```

Check installed packages:

```bash
docker run --rm \
  day12-device-api:1.0.0 \
  python -c \
  "import flask, psycopg; print('Dependencies OK')"
```

Do not start the normal application yet because it requires database configuration and a reachable PostgreSQL server.

---

# 13. Create the Docker network

Create a user-defined bridge:

```bash
docker network create day12-backend
```

Inspect:

```bash
docker network inspect day12-backend \
  --format 'Driver={{.Driver}} {{range .IPAM.Config}}Subnet={{.Subnet}} Gateway={{.Gateway}}{{end}}'
```

The application and database will join this network.

---

# 14. Create the database volume

Create:

```bash
docker volume create day12-postgres-data
```

Inspect:

```bash
docker volume inspect day12-postgres-data
```

This volume will preserve PostgreSQL records when the database container is removed.

---

# 15. Start PostgreSQL

Run:

```bash
docker run -d \
  --name database \
  --network day12-backend \
  -e POSTGRES_USER=device_app \
  -e POSTGRES_PASSWORD=development-password \
  -e POSTGRES_DB=device_monitor \
  --mount type=volume,source=day12-postgres-data,target=/var/lib/postgresql/data \
  postgres:17
```

Notice that there is no:

```bash
-p 5432:5432
```

The database is not published to the Docker host.

Check:

```bash
docker ps \
  --filter name=database
```

Check ports:

```bash
docker port database
```

No published port should be displayed.

PostgreSQL still listens internally on port 5432.

---

# 16. Inspect PostgreSQL logs

Run:

```bash
docker logs -f database
```

You should eventually see a message indicating PostgreSQL is ready to accept connections.

Stop following with:

```text
Ctrl+C
```

The database continues running.

---

# 17. Test PostgreSQL from another container

Use a temporary PostgreSQL client on the same network:

```bash
docker run --rm \
  --network day12-backend \
  -e PGPASSWORD=development-password \
  postgres:17 \
  psql \
  -h database \
  -p 5432 \
  -U device_app \
  -d device_monitor \
  -c 'SELECT current_database(), current_user;'
```

Important values:

```text
Host: database
Port: 5432
```

The hostname comes from Docker DNS.

The port is PostgreSQL’s internal port.

---

# 18. Start the application container

Run:

```bash
docker run -d \
  --name device-api \
  --network day12-backend \
  -p 8080:5000 \
  -e APP_ENV=development \
  -e APP_VERSION=1.0.0 \
  -e LOG_LEVEL=INFO \
  -e DB_HOST=database \
  -e DB_PORT=5432 \
  -e DB_NAME=device_monitor \
  -e DB_USER=device_app \
  -e DB_PASSWORD=development-password \
  day12-device-api:1.0.0
```

The critical connection configuration is:

```text
DB_HOST=database
DB_PORT=5432
```

Not:

```text
DB_HOST=localhost
```

Not:

```text
DB_PORT=8080
```

Not a hard-coded container IP.

---

# 19. Inspect application startup

Follow logs:

```bash
docker logs -f device-api
```

You should see messages similar to:

```text
Database connection established on attempt 1
Database schema initialized
```

Then Gunicorn starts listening on:

```text
0.0.0.0:5000
```

Stop following with `Ctrl+C`.

---

# 20. Test the web application

Test health:

```bash
curl -s http://localhost:8080/health \
  | python3 -m json.tool
```

Expected:

```json
{
    "database": "connected",
    "status": "healthy"
}
```

Test devices:

```bash
curl -s http://localhost:8080/api/devices \
  | python3 -m json.tool
```

Open the dashboard:

```text
http://localhost:8080
```

---

# 21. Test the effective configuration

Run:

```bash
curl -s http://localhost:8080/api/config \
  | python3 -m json.tool
```

You should see:

```json
{
    "application_environment": "development",
    "application_version": "1.0.0",
    "database_host": "database",
    "database_name": "device_monitor",
    "database_port": 5432
}
```

The password is deliberately not returned.

Avoid exposing credentials through:

- API endpoints
    
- Logs
    
- Error messages
    
- Health responses
    
- Debug pages
    

---

# 22. Add a device through the API

Create a new device:

```bash
curl -s \
  -X POST \
  http://localhost:8080/api/devices \
  -H 'Content-Type: application/json' \
  -d '{
    "device_name": "mqtt-client-04",
    "online": true,
    "firmware_version": "1.5.0"
  }' \
  | python3 -m json.tool
```

List devices:

```bash
curl -s http://localhost:8080/api/devices \
  | python3 -m json.tool
```

The new record is stored in PostgreSQL, not inside the application container.

---

# 23. Update a device

Suppose the new device received ID 4.

Set it offline:

```bash
curl -s \
  -X PATCH \
  http://localhost:8080/api/devices/4 \
  -H 'Content-Type: application/json' \
  -d '{
    "online": false
  }' \
  | python3 -m json.tool
```

Refresh:

```text
http://localhost:8080
```

The database record should show the updated status.

---

# 24. Verify the data directly in PostgreSQL

Run:

```bash
docker exec database \
  psql \
  -U device_app \
  -d device_monitor \
  -c '
    SELECT
        id,
        device_name,
        online,
        firmware_version
    FROM devices
    ORDER BY id;
  '
```

This proves the records are stored in PostgreSQL.

---

# 25. Why PostgreSQL does not need a host port

The application and PostgreSQL share:

```text
day12-backend
```

Therefore, the application can use:

```text
database:5432
```

Publishing PostgreSQL would add another path:

```text
Docker-host:5432 → database:5432
```

But no external client currently needs it.

Leaving it unpublished provides:

- Fewer exposed services
    
- Fewer host port conflicts
    
- Reduced attack surface
    
- Clearer network architecture
    

The application’s published port is enough:

```text
Docker-host:8080 → device-api:5000
```

---

# 26. Why `localhost` is wrong

Inside the `device-api` container:

```text
localhost
```

means the `device-api` container itself.

If you configure:

```text
DB_HOST=localhost
```

the application attempts:

```text
device-api container port 5432
```

But PostgreSQL is running in another container.

The correct path is:

```text
device-api
    ↓ Docker DNS resolves "database"
database container
    ↓
port 5432
```

---

# 27. Deliberately test the `localhost` mistake

Remove the application container:

```bash
docker rm -f device-api
```

Run it incorrectly:

```bash
docker run \
  --name device-api-localhost-error \
  --network day12-backend \
  -e DB_HOST=localhost \
  -e DB_PORT=5432 \
  -e DB_NAME=device_monitor \
  -e DB_USER=device_app \
  -e DB_PASSWORD=development-password \
  day12-device-api:1.0.0
```

The application should repeatedly fail to connect.

The error will probably resemble:

```text
connection refused
```

because no PostgreSQL server is listening inside the application container on port 5432.

Remove the failed container:

```bash
docker rm -f device-api-localhost-error
```

---

# 28. Deliberately test the wrong-network problem

Run the application without attaching it to `day12-backend`:

```bash
docker run \
  --name device-api-network-error \
  -e DB_HOST=database \
  -e DB_PORT=5432 \
  -e DB_NAME=device_monitor \
  -e DB_USER=device_app \
  -e DB_PASSWORD=development-password \
  day12-device-api:1.0.0
```

The container will use the default bridge.

It does not share `day12-backend` with the database.

The likely error is name-resolution failure:

```text
could not translate host name
```

or:

```text
name or service not known
```

This is different from `connection refused`.

Remove:

```bash
docker rm -f device-api-network-error
```

---

# 29. Deliberately test the wrong port

Run:

```bash
docker run \
  --name device-api-port-error \
  --network day12-backend \
  -e DB_HOST=database \
  -e DB_PORT=9999 \
  -e DB_NAME=device_monitor \
  -e DB_USER=device_app \
  -e DB_PASSWORD=development-password \
  day12-device-api:1.0.0
```

Docker DNS should resolve `database`, but nothing listens on port 9999.

The likely result is:

```text
connection refused
```

Remove:

```bash
docker rm -f device-api-port-error
```

---

# 30. Deliberately test incorrect credentials

Run:

```bash
docker run \
  --name device-api-auth-error \
  --network day12-backend \
  -e DB_HOST=database \
  -e DB_PORT=5432 \
  -e DB_NAME=device_monitor \
  -e DB_USER=device_app \
  -e DB_PASSWORD=wrong-password \
  day12-device-api:1.0.0
```

DNS and TCP connectivity work, but PostgreSQL rejects authentication.

The error should indicate password authentication failure.

Remove:

```bash
docker rm -f device-api-auth-error
```

This demonstrates several different layers:

```text
DNS resolution
    ↓
TCP connectivity
    ↓
Authentication
    ↓
Database selection
    ↓
SQL execution
```

A failure at each layer produces different evidence.

---

# 31. Start the correct application again

Run:

```bash
docker run -d \
  --name device-api \
  --network day12-backend \
  -p 8080:5000 \
  -e APP_ENV=development \
  -e APP_VERSION=1.0.0 \
  -e LOG_LEVEL=INFO \
  -e DB_HOST=database \
  -e DB_PORT=5432 \
  -e DB_NAME=device_monitor \
  -e DB_USER=device_app \
  -e DB_PASSWORD=development-password \
  day12-device-api:1.0.0
```

Test:

```bash
curl http://localhost:8080/health
```

---

# 32. Why retries are necessary

A common beginner assumption is:

```text
Container is running
=
Application is ready
```

This is false.

PostgreSQL startup includes:

- Reading configuration
    
- Initializing or opening the data directory
    
- Recovering transaction logs
    
- Starting background processes
    
- Opening the listening socket
    
- Becoming ready for client connections
    

The container may exist and its DNS name may resolve before PostgreSQL accepts connections.

Without retries:

```text
Application starts
      ↓
First database connection fails
      ↓
Application exits
```

With retries:

```text
Application starts
      ↓
Database unavailable
      ↓
Wait
      ↓
Retry
      ↓
Database becomes ready
      ↓
Application starts normally
```

---

# 33. Test startup ordering

Remove both containers, but preserve the volume:

```bash
docker rm -f device-api database
```

Start the application first:

```bash
docker run -d \
  --name device-api \
  --network day12-backend \
  -p 8080:5000 \
  -e APP_ENV=development \
  -e APP_VERSION=1.0.0 \
  -e LOG_LEVEL=INFO \
  -e DB_HOST=database \
  -e DB_PORT=5432 \
  -e DB_NAME=device_monitor \
  -e DB_USER=device_app \
  -e DB_PASSWORD=development-password \
  day12-device-api:1.0.0
```

At this point, the DNS name `database` does not exist because the database container has not been created.

Watch:

```bash
docker logs -f device-api
```

Now, before the application exhausts its retries, start PostgreSQL in another terminal:

```bash
docker run -d \
  --name database \
  --network day12-backend \
  -e POSTGRES_USER=device_app \
  -e POSTGRES_PASSWORD=development-password \
  -e POSTGRES_DB=device_monitor \
  --mount type=volume,source=day12-postgres-data,target=/var/lib/postgresql/data \
  postgres:17
```

The application should eventually connect and continue starting.

This demonstrates why retry logic is valuable.

---

# 34. A limitation in the retry design

The current retry logic runs during application startup.

After startup, each HTTP request opens a database connection.

If PostgreSQL later becomes unavailable:

- The application process remains running.
    
- Database-backed endpoints may return errors.
    
- The health endpoint returns HTTP 503.
    
- The application does not permanently reconnect a stored connection because it opens new connections per request.
    

This is acceptable for training.

A production application should additionally include:

- Central error handling
    
- Connection pooling
    
- Timeouts
    
- Retry policies for appropriate operations
    
- Monitoring
    
- Circuit-breaking where appropriate
    
- Graceful degradation where possible
    

---

# 35. Test a runtime database outage

Confirm both containers are running:

```bash
docker ps
```

Stop PostgreSQL:

```bash
docker stop database
```

Test health:

```bash
curl -i http://localhost:8080/health
```

Expected:

```text
HTTP/1.1 503 SERVICE UNAVAILABLE
```

Test devices:

```bash
curl -i http://localhost:8080/api/devices
```

This endpoint may return an internal server error because it lacks a general database error handler.

Inspect application logs:

```bash
docker logs --tail 100 device-api
```

Restart PostgreSQL:

```bash
docker start database
```

Wait until PostgreSQL is ready, then:

```bash
curl http://localhost:8080/health
```

The application should recover because new requests create new database connections.

---

# 36. Health means more than “process exists”

A container can be running while its application is unusable.

Examples:

```text
Gunicorn running
PostgreSQL unavailable
```

```text
Web process running
Database authentication broken
```

```text
MQTT consumer running
Broker unreachable
```

A meaningful health endpoint should test critical dependencies.

Your `/health` endpoint checks:

```sql
SELECT 1
```

This verifies:

- DNS resolution
    
- Network connection
    
- PostgreSQL listener
    
- Authentication
    
- Database access
    
- Basic SQL execution
    

---

# 37. Inspect container network membership

Application:

```bash
docker inspect device-api \
  --format '{{json .NetworkSettings.Networks}}'
```

Database:

```bash
docker inspect database \
  --format '{{json .NetworkSettings.Networks}}'
```

Both should include:

```text
day12-backend
```

Inspect the network:

```bash
docker network inspect day12-backend \
  --format '{{range .Containers}}Name={{.Name}} IPv4={{.IPv4Address}}{{println}}{{end}}'
```

---

# 38. Verify DNS from the application container

The application image may not contain `getent` or network debugging tools beyond the minimal Debian utilities.

Try:

```bash
docker exec device-api \
  getent hosts database
```

You should see the database IP.

You can also run a temporary diagnostic container:

```bash
docker run --rm \
  --network day12-backend \
  alpine \
  getent hosts database
```

Test the port:

```bash
docker run --rm \
  --network day12-backend \
  alpine \
  sh -c '
    apk add --no-cache busybox-extras >/dev/null &&
    nc -vz database 5432
  '
```

---

# 39. Why application configuration belongs at runtime

The image does not hard-code:

```text
database host
database name
database username
database password
```

Instead, the container receives them through environment variables.

This allows the same image to connect to:

```text
Development PostgreSQL
Testing PostgreSQL
Production PostgreSQL
```

Example:

```text
Development:
DB_HOST=database-dev

Testing:
DB_HOST=database-test

Production:
DB_HOST=database-prod
```

The application image remains the same artifact.

---

# 40. Required versus optional configuration

The helper:

```python
required_environment("DB_HOST")
```

causes startup to fail clearly when a required setting is absent.

This is better than silently using an incorrect fallback.

Required settings:

```text
DB_HOST
DB_NAME
DB_USER
DB_PASSWORD
```

Optional setting with a sensible default:

```text
DB_PORT=5432
```

Good configuration design distinguishes between:

- Required values
    
- Optional values
    
- Safe defaults
    
- Secret values
    
- Invalid combinations
    

---

# 41. Test missing configuration

Run:

```bash
docker run --rm \
  --network day12-backend \
  -e DB_HOST=database \
  day12-device-api:1.0.0
```

The container should fail with a clear error such as:

```text
Required environment variable DB_NAME is missing
```

This is preferable to a vague failure later.

---

# 42. Inspect runtime environment carefully

Run:

```bash
docker inspect device-api \
  --format '{{json .Config.Env}}'
```

You will see the database password.

This demonstrates an important security lesson:

> Ordinary environment variables are convenient configuration, but they are not a dedicated secret-management system.

People with Docker access can inspect them.

Later lessons will cover stronger secret-management patterns.

For now:

- Do not commit passwords to Git.
    
- Do not expose them through APIs.
    
- Do not print them in logs.
    
- Use separate development credentials.
    
- Treat Docker host access as privileged access.
    

---

# 43. Use an environment file

Create:

```bash
nano day12.env
```

Add:

```text
APP_ENV=development
APP_VERSION=1.0.0
LOG_LEVEL=INFO

DB_HOST=database
DB_PORT=5432
DB_NAME=device_monitor
DB_USER=device_app
DB_PASSWORD=development-password
```

Ensure it is excluded from Git:

```bash
echo 'day12.env' >> .gitignore
```

Recreate the application:

```bash
docker rm -f device-api
```

```bash
docker run -d \
  --name device-api \
  --network day12-backend \
  -p 8080:5000 \
  --env-file day12.env \
  day12-device-api:1.0.0
```

An environment file improves command readability.

It does not automatically encrypt its contents.

Set restrictive host permissions:

```bash
chmod 600 day12.env
```

---

# 44. Test application-container replacement

Add a recognizable record:

```bash
curl -s \
  -X POST \
  http://localhost:8080/api/devices \
  -H 'Content-Type: application/json' \
  -d '{
    "device_name": "persistent-device",
    "online": true,
    "firmware_version": "9.9.9"
  }' \
  | python3 -m json.tool
```

Remove only the application:

```bash
docker rm -f device-api
```

Recreate it:

```bash
docker run -d \
  --name device-api \
  --network day12-backend \
  -p 8080:5000 \
  --env-file day12.env \
  day12-device-api:1.0.0
```

Check:

```bash
curl -s http://localhost:8080/api/devices \
  | python3 -m json.tool
```

The record remains because it is stored in PostgreSQL.

---

# 45. Test database-container replacement

Remove PostgreSQL:

```bash
docker rm -f database
```

The application is now unhealthy.

Check:

```bash
curl -i http://localhost:8080/health
```

Confirm the volume remains:

```bash
docker volume ls \
  --filter name=day12-postgres-data
```

Recreate PostgreSQL using the same volume:

```bash
docker run -d \
  --name database \
  --network day12-backend \
  -e POSTGRES_USER=device_app \
  -e POSTGRES_PASSWORD=development-password \
  -e POSTGRES_DB=device_monitor \
  --mount type=volume,source=day12-postgres-data,target=/var/lib/postgresql/data \
  postgres:17
```

After readiness:

```bash
curl -s http://localhost:8080/api/devices \
  | python3 -m json.tool
```

The records remain because the database volume survived.

---

# 46. Database initialization variables apply mainly to an empty volume

The first time PostgreSQL starts with an empty volume, it uses:

```text
POSTGRES_USER
POSTGRES_PASSWORD
POSTGRES_DB
```

to initialize the database.

Once the volume contains an initialized PostgreSQL data directory, changing these variables does not automatically:

- Rename the user
    
- Change the existing password
    
- Rename the database
    
- Reinitialize records
    

The volume content becomes the source of truth.

This explains why reusing a volume with different initialization variables can lead to authentication confusion.

---

# 47. Avoid accidental new database volumes

Suppose you mistype the volume name:

```text
day12-postgres-date
```

instead of:

```text
day12-postgres-data
```

Docker may create a new empty volume.

PostgreSQL initializes a fresh database.

Your application appears to have lost all records, although the old volume still exists.

Inspect:

```bash
docker volume ls
```

Inspect the database mount:

```bash
docker inspect database \
  --format '{{range .Mounts}}Name={{.Name}} Destination={{.Destination}}{{println}}{{end}}'
```

Always confirm the exact volume name during restoration or replacement.

---

# 48. Application-level retry versus Docker restart policy

Two mechanisms solve different problems.

## Application retry

```text
Database temporarily unavailable
      ↓
Application waits and retries
```

This handles expected dependency delays.

## Docker restart policy

```text
Application process exits
      ↓
Docker restarts the container
```

This handles process termination.

You could run:

```bash
docker update \
  --restart on-failure:5 \
  device-api
```

But restart policy alone is weaker:

```text
Start
Fail immediately
Restart
Fail immediately
Restart
```

Application-level retry handles dependency readiness more gracefully.

A robust system may use both.

---

# 49. Connection pooling

The current application opens a new database connection for every operation.

That is simple, but under load it can be inefficient.

A connection pool maintains reusable connections:

```text
HTTP request
    ↓
Borrow database connection
    ↓
Execute query
    ↓
Return connection to pool
```

Benefits:

- Reduced connection setup overhead
    
- Controlled maximum connections
    
- Better performance
    
- Easier connection reuse
    

Risks if misconfigured:

- Too many connections
    
- Exhausted pool
    
- Stale connections
    
- Long-held transactions
    

Connection pooling is an application concern, not something Docker automatically provides.

---

# 50. Transactions

The helper commits successful operations:

```python
connection.commit()
```

and rolls back errors:

```python
connection.rollback()
```

A transaction groups database operations into an atomic unit.

For example:

```text
Insert device
Update audit record
Publish internal state
```

If the second database operation fails, you may want all related database changes rolled back.

Without correct transactions, partial data may remain.

Docker networking does not replace correct database programming.

---

# 51. Schema management

Today the web application executes:

```sql
CREATE TABLE IF NOT EXISTS
```

This is acceptable for a small training project.

Real production systems usually use migration tools such as:

- Alembic
    
- Flask-Migrate
    
- Flyway
    
- Liquibase
    
- Doctrine migrations
    
- Custom versioned SQL migrations
    

A mature deployment separates:

```text
Database migration
```

from:

```text
Web request processing
```

This allows controlled schema upgrades and rollback planning.

---

# 52. One database per container?

The PostgreSQL server can contain multiple databases.

But the common container design is not necessarily “one database per container.” It is closer to:

> One PostgreSQL server responsibility per container.

That PostgreSQL instance may host:

- One application database
    
- Several closely related databases
    
- Separate schemas
    

Architectural decisions depend on:

- Isolation requirements
    
- Backup needs
    
- Upgrade schedules
    
- Resource usage
    
- Security boundaries
    
- Operational complexity
    

---

# 53. Do not combine application and PostgreSQL in one container

Avoid creating one container that runs:

```text
Gunicorn
PostgreSQL
Nginx
MQTT consumer
```

Separate containers provide:

- Independent lifecycle
    
- Independent upgrades
    
- Clear logs
    
- Separate health checks
    
- Resource controls
    
- Easier troubleshooting
    
- Better security boundaries
    

The application can be replaced without rebuilding PostgreSQL.

PostgreSQL can be maintained without rebuilding the application image.

---

# 54. Troubleshooting matrix

## Error: hostname cannot be resolved

Check:

```bash
docker inspect device-api \
  --format '{{json .NetworkSettings.Networks}}'
```

```bash
docker inspect database \
  --format '{{json .NetworkSettings.Networks}}'
```

Both must share `day12-backend`.

Verify:

```bash
docker run --rm \
  --network day12-backend \
  alpine \
  getent hosts database
```

---

## Error: connection refused

Likely causes:

- PostgreSQL still starting
    
- PostgreSQL stopped
    
- Wrong port
    
- Wrong destination service
    
- PostgreSQL not listening correctly
    

Check:

```bash
docker logs database
```

```bash
docker run --rm \
  --network day12-backend \
  alpine \
  sh -c '
    apk add --no-cache busybox-extras >/dev/null &&
    nc -vz database 5432
  '
```

---

## Error: password authentication failed

Check:

- `DB_USER`
    
- `DB_PASSWORD`
    
- Initial PostgreSQL credentials
    
- Whether the volume was initialized previously
    
- Whether changed environment variables were expected to alter existing credentials
    

---

## Error: database does not exist

Check:

```text
DB_NAME
POSTGRES_DB
```

Remember that initialization values are applied when the volume is first initialized.

---

## Error: relation `devices` does not exist

Possible causes:

- Initialization did not run
    
- Application connected to another database
    
- SQL failed
    
- Insufficient database permissions
    
- A different schema is active
    

Inspect application logs.

Connect directly and list tables:

```bash
docker exec database \
  psql \
  -U device_app \
  -d device_monitor \
  -c '\dt'
```

---

## Error: duplicate device

The application returns HTTP 409 because `device_name` is unique.

This is an application/database constraint, not a Docker error.

---

# 55. Diagnostic sequence

When the application cannot use the database:

```text
1. Is the database container running?
2. Is the application container running?
3. Do they share a network?
4. Does DB_HOST resolve?
5. Is port 5432 reachable?
6. Is PostgreSQL ready?
7. Are credentials correct?
8. Does the database exist?
9. Does the user have permission?
10. Does the table exist?
11. Does the SQL query work?
```

Commands:

```bash
docker ps -a
```

```bash
docker logs database
docker logs device-api
```

```bash
docker network inspect day12-backend
```

```bash
docker run --rm \
  --network day12-backend \
  alpine \
  getent hosts database
```

```bash
docker run --rm \
  --network day12-backend \
  -e PGPASSWORD=development-password \
  postgres:17 \
  psql \
  -h database \
  -U device_app \
  -d device_monitor \
  -c 'SELECT 1;'
```

---

# 56. Day 12 practical laboratory

## Exercise 1 — Build the application

Create the project files and build:

```text
day12-device-api:1.0.0
```

Validate Python imports without starting the server.

---

## Exercise 2 — Create infrastructure

Create:

```text
Network:
day12-backend

Volume:
day12-postgres-data
```

Inspect both resources.

---

## Exercise 3 — Start PostgreSQL privately

Start PostgreSQL on `day12-backend`.

Do not publish port 5432.

Confirm:

```bash
docker port database
```

shows no host mapping.

---

## Exercise 4 — Test the database internally

Use a temporary PostgreSQL client container.

Connect using:

```text
database:5432
```

Execute:

```sql
SELECT current_database(), current_user;
```

---

## Exercise 5 — Start the application

Configure it with:

```text
DB_HOST=database
DB_PORT=5432
```

Publish only application port 5000 through host port 8080.

Test every endpoint.

---

## Exercise 6 — Create and update records

Use HTTP requests to:

- Create a device
    
- List devices
    
- Update online status
    
- Confirm the change directly in PostgreSQL
    

---

## Exercise 7 — Diagnose four failures

Create and identify:

1. `DB_HOST=localhost`
    
2. Application on the wrong network
    
3. `DB_PORT=9999`
    
4. Wrong password
    

Classify each error as:

- DNS failure
    
- TCP failure
    
- Authentication failure
    

---

## Exercise 8 — Test retry behavior

Start the application before PostgreSQL.

Observe retries.

Start PostgreSQL before the retry limit is reached.

Confirm the application recovers.

---

## Exercise 9 — Test persistence

Create a record.

Remove and recreate the application container.

Verify the record.

Remove and recreate PostgreSQL with the same volume.

Verify the record again.

---

## Exercise 10 — Test runtime outage

Stop PostgreSQL while the application remains running.

Confirm `/health` returns 503.

Restart PostgreSQL.

Confirm `/health` returns 200 again.

---

# 57. Day 12 command reference

```bash
# Create application network
docker network create day12-backend

# Create database volume
docker volume create day12-postgres-data

# Start PostgreSQL without publishing it
docker run -d \
  --name database \
  --network day12-backend \
  -e POSTGRES_USER=device_app \
  -e POSTGRES_PASSWORD=development-password \
  -e POSTGRES_DB=device_monitor \
  --mount type=volume,source=day12-postgres-data,target=/var/lib/postgresql/data \
  postgres:17

# Test PostgreSQL from the same Docker network
docker run --rm \
  --network day12-backend \
  -e PGPASSWORD=development-password \
  postgres:17 \
  psql \
  -h database \
  -U device_app \
  -d device_monitor \
  -c 'SELECT 1;'

# Start the application
docker run -d \
  --name device-api \
  --network day12-backend \
  -p 8080:5000 \
  --env-file day12.env \
  day12-device-api:1.0.0

# Test application health
curl http://localhost:8080/health

# Inspect network membership
docker network inspect day12-backend

# Resolve the database
docker run --rm \
  --network day12-backend \
  alpine \
  getent hosts database

# Inspect database records
docker exec database \
  psql \
  -U device_app \
  -d device_monitor \
  -c 'SELECT * FROM devices;'

# Inspect container mounts
docker inspect database \
  --format '{{json .Mounts}}'

# View logs
docker logs database
docker logs device-api
```

---

# 58. Knowledge check

## Why does the application use `database` as the host?

Because `database` is the PostgreSQL container name and Docker DNS resolves it on the shared user-defined network.

## Why does the application use port 5432?

Because that is PostgreSQL’s internal listening port.

## Why is host port 5432 unnecessary?

Only containers on the Docker network need access to PostgreSQL. They connect directly using `database:5432`.

## Why is `localhost` incorrect?

Inside the application container, `localhost` refers to the application container itself.

## Does DNS resolution prove PostgreSQL is ready?

No. The container name may resolve before PostgreSQL accepts connections.

## Why does the application retry?

To tolerate temporary database unavailability during initialization or restart.

## What does connection refused mean?

The destination was reached, but no service accepted the connection on the requested port.

## What does an authentication error mean?

DNS and network connectivity worked, but PostgreSQL rejected the supplied identity or password.

## Where are the device records stored?

In PostgreSQL’s data directory, backed by the named Docker volume.

## Why do records survive application replacement?

The application container does not own the database files.

## Why do records survive PostgreSQL-container replacement?

The named volume survives and is mounted into the replacement PostgreSQL container.

## Is an environment variable a secure secret store?

No. It is runtime configuration and can be inspected by users with sufficient Docker access.

---

# 59. Day 12 completion challenge

Complete this independently:

1. Create a user-defined bridge network.
    
2. Create a named PostgreSQL volume.
    
3. Start PostgreSQL on the network.
    
4. Do not publish the database port.
    
5. Create a database, user, and password through initialization variables.
    
6. Verify PostgreSQL internally using a temporary client container.
    
7. Build a web API image containing a PostgreSQL driver.
    
8. Configure the API entirely through runtime variables.
    
9. Use the database container name as `DB_HOST`.
    
10. Use port 5432 as the database port.
    
11. Implement database connection retries.
    
12. Create a table automatically.
    
13. Insert seed data without duplicating it on every startup.
    
14. Publish only the web API.
    
15. Confirm the database has no host port.
    
16. List records through HTTP.
    
17. Insert a record through HTTP.
    
18. Verify it directly in PostgreSQL.
    
19. Update the record through HTTP.
    
20. Replace the application container.
    
21. Confirm the data remains.
    
22. Replace the PostgreSQL container while reusing the volume.
    
23. Confirm the data remains.
    
24. Configure `DB_HOST=localhost`.
    
25. Explain the resulting failure.
    
26. Place the application on the wrong network.
    
27. Explain the DNS failure.
    
28. Configure the wrong database port.
    
29. Explain the TCP failure.
    
30. Configure an incorrect password.
    
31. Explain the authentication failure.
    
32. Start the application before PostgreSQL.
    
33. Observe and explain retry behavior.
    
34. Stop PostgreSQL while the API is running.
    
35. Confirm health changes to unhealthy.
    
36. Restart PostgreSQL.
    
37. Confirm the application recovers.
    
38. Explain why container running state and application readiness are different.
    
39. Explain why the database should remain unpublished.
    
40. Remove the containers while retaining the volume for the next lesson.
    

The central Day 12 model is:

```text
Application container
        ↓ Docker DNS
database
        ↓ internal TCP port 5432
PostgreSQL container
        ↓
Named persistent volume
```

The most important operational lesson is:

> Containers should connect through stable service names on shared Docker networks, use internal service ports, retry temporary dependency failures, expose only necessary entry points, and keep state in persistent storage whose lifecycle is independent of both application and database containers.