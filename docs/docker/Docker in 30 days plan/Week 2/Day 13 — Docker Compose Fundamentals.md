
On Day 12, you manually created and connected:

- An application image
    
- A PostgreSQL container
    
- A Docker network
    
- A named volume
    
- Environment variables
    
- Port mappings
    
- Startup dependencies
    

The commands worked, but they were long and easy to mistype.

Today you will describe the whole system in one file:

```text
compose.yaml
```

Then start everything with:

```bash
docker compose up
```

Docker Compose is designed for defining and running multi-container applications. A Compose file can describe services, networks, volumes, configuration, dependencies, and runtime settings as one application model. ([Docker Documentation](https://docs.docker.com/compose/?utm_source=chatgpt.com "Docker Compose"))

---

# 1. Day 13 objectives

By the end of today, you should understand:

- What Docker Compose is
    
- The difference between Dockerfile and Compose
    
- The modern `docker compose` command
    
- How to write `compose.yaml`
    
- How to define application and database services
    
- How Compose creates networks and volumes
    
- How services discover each other by service name
    
- How to build an image through Compose
    
- How to start, stop, inspect, rebuild, and remove a stack
    
- The difference between `up`, `start`, `stop`, `down`, and `restart`
    
- How `depends_on` and health checks work
    
- How to inspect the resolved Compose configuration
    
- How persistent data behaves when the stack is removed
    
- How to troubleshoot a Compose application
    

The main workflow becomes:

```text
Dockerfile
Application source
compose.yaml
      ↓
docker compose up
      ↓
Application container
Database container
Network
Volume
```

---

# 2. Dockerfile versus Compose file

These two files solve different problems.

## Dockerfile

A Dockerfile describes how to build one image:

```dockerfile
FROM python:3.13-slim

WORKDIR /app

COPY requirements.txt .

RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

CMD ["gunicorn", "--bind", "0.0.0.0:5000", "app:app"]
```

It answers:

> What should be inside the application image?

## Compose file

A Compose file describes how one or more services should run together:

```yaml
services:
  api:
    build: .
    ports:
      - "8080:5000"

  database:
    image: postgres:17
```

It answers:

> Which containers, networks, volumes, ports, and settings make up the application?

The relationship is:

```text
Dockerfile
→ builds application image

compose.yaml
→ runs the complete application system
```

---

# 3. Verify Docker Compose

Use the modern command:

```bash
docker compose version
```

Notice the space:

```text
docker compose
```

The old standalone tool used:

```text
docker-compose
```

with a hyphen. Docker documents the standalone version as a legacy compatibility option; the current standard syntax is `docker compose`. ([Docker Documentation](https://docs.docker.com/compose/install/standalone/?utm_source=chatgpt.com "Install the Docker Compose standalone (Legacy)"))

Expected output resembles:

```text
Docker Compose version ...
```

If the command is missing on Ubuntu or Debian, the official Docker repository package is:

```bash
sudo apt update
sudo apt install docker-compose-plugin
```

Then verify again:

```bash
docker compose version
```

Docker recommends installing the Compose plugin through the package repository so it can be updated through the package manager. ([Docker Documentation](https://docs.docker.com/compose/install/linux/?utm_source=chatgpt.com "Install the Docker Compose plugin"))

---

# 4. Reuse the Day 12 project

Move to the Day 12 application directory:

```bash
cd ~/docker-course/day12/device-api
```

You should have:

```text
device-api/
├── Dockerfile
├── .dockerignore
├── requirements.txt
├── app.py
└── templates/
    └── index.html
```

Today you will add:

```text
compose.yaml
```

The completed directory becomes:

```text
device-api/
├── compose.yaml
├── Dockerfile
├── .dockerignore
├── requirements.txt
├── app.py
└── templates/
    └── index.html
```

---

# 5. Remove the manually created resources

Before starting the Compose version, remove the Day 12 containers:

```bash
docker rm -f device-api database 2>/dev/null
```

The old network can also be removed:

```bash
docker network rm day12-backend 2>/dev/null
```

You may keep or remove the old volume depending on whether you want a fresh Compose exercise.

To start clean:

```bash
docker volume rm day12-postgres-data 2>/dev/null
```

Be aware that deleting the volume permanently deletes its PostgreSQL data.

---

# 6. Create the first Compose file

Create:

```bash
nano compose.yaml
```

Add:

```yaml
services:
  api:
    build:
      context: .
    ports:
      - "8080:5000"
    environment:
      APP_ENV: development
      APP_VERSION: 1.0.0
      LOG_LEVEL: INFO

      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: device_monitor
      DB_USER: device_app
      DB_PASSWORD: development-password
    depends_on:
      database:
        condition: service_healthy
    networks:
      - backend

  database:
    image: postgres:17
    environment:
      POSTGRES_USER: device_app
      POSTGRES_PASSWORD: development-password
      POSTGRES_DB: device_monitor
    volumes:
      - postgres-data:/var/lib/postgresql/data
    healthcheck:
      test:
        - CMD-SHELL
        - pg_isready -U device_app -d device_monitor
      interval: 5s
      timeout: 3s
      retries: 10
      start_period: 10s
    networks:
      - backend

volumes:
  postgres-data:

networks:
  backend:
```

This single file replaces most of the manual Day 12 commands.

---

# 7. YAML fundamentals

Compose files use YAML.

YAML depends on indentation.

Correct:

```yaml
services:
  api:
    build:
      context: .
```

Incorrect:

```yaml
services:
api:
build:
context: .
```

Use spaces, not tabs.

A common convention is two spaces per indentation level.

## Mapping

```yaml
environment:
  APP_ENV: development
  LOG_LEVEL: INFO
```

This represents key-value pairs.

## List

```yaml
ports:
  - "8080:5000"
```

The dash introduces a list item.

## Nested configuration

```yaml
healthcheck:
  interval: 5s
  timeout: 3s
```

These properties belong to `healthcheck`.

---

# 8. No `version:` field is needed

Older Compose examples often begin with:

```yaml
version: "3.8"
```

Modern Compose uses the Compose Specification, so you should normally omit the old version declaration and begin directly with:

```yaml
services:
```

The current Compose Specification is the recommended format, while the older versioned file formats are treated as legacy. ([Docker Documentation](https://docs.docker.com/reference/compose-file/?utm_source=chatgpt.com "Compose file reference"))

Your file should therefore start with:

```yaml
services:
```

---

# 9. Understanding `services`

The top-level section:

```yaml
services:
```

contains the application’s runnable components.

You defined two services:

```yaml
services:
  api:
  database:
```

A service is a logical application component.

Compose creates containers from service definitions.

In your project:

```text
Service: api
→ Python Flask/Gunicorn application

Service: database
→ PostgreSQL
```

The service name also becomes a stable DNS name on shared Compose networks.

Therefore:

```text
DB_HOST=database
```

refers to the `database` service.

---

# 10. Understanding the `api` service

The beginning is:

```yaml
api:
  build:
    context: .
```

This tells Compose to build the service image using the current directory as the build context.

It is similar to:

```bash
docker build .
```

Compose automatically looks for:

```text
Dockerfile
```

inside that context.

A more explicit form is:

```yaml
build:
  context: .
  dockerfile: Dockerfile
```

The explicit form becomes useful when the Dockerfile has a different name.

---

# 11. Build versus image

You can define a service using an existing image:

```yaml
database:
  image: postgres:17
```

Or build one locally:

```yaml
api:
  build:
    context: .
```

You can also supply both:

```yaml
api:
  build:
    context: .
  image: day13-device-api:1.0.0
```

This tells Compose:

- Build using the Dockerfile
    
- Assign the resulting image the specified name and tag
    

Update the API service to use:

```yaml
api:
  build:
    context: .
  image: day13-device-api:1.0.0
```

This makes the built image easier to identify with:

```bash
docker image ls day13-device-api
```

---

# 12. Understanding `ports`

The API service contains:

```yaml
ports:
  - "8080:5000"
```

This is equivalent to:

```bash
-p 8080:5000
```

Meaning:

```text
Docker host port 8080
        ↓
API container port 5000
```

The quotes are useful because port mappings contain a colon and should be interpreted as strings.

The PostgreSQL service does not define:

```yaml
ports:
```

Therefore, PostgreSQL is not published to the Docker host.

The API can still contact it internally.

---

# 13. Understanding `environment`

The API service contains:

```yaml
environment:
  APP_ENV: development
  APP_VERSION: 1.0.0
  LOG_LEVEL: INFO

  DB_HOST: database
  DB_PORT: "5432"
  DB_NAME: device_monitor
  DB_USER: device_app
  DB_PASSWORD: development-password
```

This is similar to passing multiple:

```bash
-e KEY=value
```

arguments to `docker run`.

The database service receives:

```yaml
environment:
  POSTGRES_USER: device_app
  POSTGRES_PASSWORD: development-password
  POSTGRES_DB: device_monitor
```

These initialize PostgreSQL when its volume is empty.

---

# 14. YAML data types and quoted values

YAML may interpret values as:

- Strings
    
- Numbers
    
- Booleans
    
- Null values
    

For example:

```yaml
DB_PORT: 5432
```

may be parsed as a number.

Environment variables ultimately become strings inside the container, but explicit quoting avoids ambiguity:

```yaml
DB_PORT: "5432"
```

Be especially careful with values such as:

```text
true
false
yes
no
null
0123
```

When a value must be treated literally, quote it:

```yaml
FEATURE_ENABLED: "false"
CODE: "0123"
```

---

# 15. Understanding Compose networks

At the bottom:

```yaml
networks:
  backend:
```

declares a Compose-managed network.

The two services join it:

```yaml
networks:
  - backend
```

Therefore:

```text
api
database
```

can communicate internally.

The API uses:

```text
database:5432
```

Compose creates project-scoped resource names. The physical Docker network name will typically include the project name, such as:

```text
device-api_backend
```

The exact prefix depends on the Compose project name.

---

# 16. Service discovery in Compose

Inside the API container:

```text
database
```

resolves to the current database container’s address.

You do not need:

- A fixed IP
    
- `--link`
    
- A host port
    
- `/etc/hosts` editing
    
- Manual `docker network connect`
    

Compose networking provides service-name-based discovery for services on the same network. ([Docker Documentation](https://docs.docker.com/compose/gettingstarted/?utm_source=chatgpt.com "Docker Compose Quickstart"))

The correct connection is:

```text
database:5432
```

not:

```text
localhost:5432
```

and not:

```text
Docker-host:5432
```

---

# 17. Understanding named volumes

The top-level declaration:

```yaml
volumes:
  postgres-data:
```

defines a named volume managed by Compose.

The database service mounts it:

```yaml
volumes:
  - postgres-data:/var/lib/postgresql/data
```

This is equivalent to:

```bash
-v postgres-data:/var/lib/postgresql/data
```

Conceptually:

```text
Compose-managed named volume
          ↓
PostgreSQL data directory
```

The physical Docker volume name will normally be project-prefixed, such as:

```text
device-api_postgres-data
```

---

# 18. Understanding `depends_on`

The API service declares:

```yaml
depends_on:
  database:
    condition: service_healthy
```

This expresses that the API depends on the database.

Compose starts and stops services according to declared dependencies. When the dependency condition is `service_healthy`, Compose waits for the dependency’s health check to report healthy before starting the dependent service. ([Docker Documentation](https://docs.docker.com/compose/how-tos/startup-order/?utm_source=chatgpt.com "Control startup and shutdown order in Compose"))

Without the health condition, short-form syntax would be:

```yaml
depends_on:
  - database
```

That only establishes startup order.

It does not prove PostgreSQL is ready to accept connections.

---

# 19. Startup order versus readiness

This:

```yaml
depends_on:
  - database
```

means roughly:

```text
Start database container
Then start API container
```

But the database container may still be initializing.

This:

```yaml
depends_on:
  database:
    condition: service_healthy
```

means:

```text
Start database container
Wait until its health check succeeds
Then start API container
```

Docker’s Compose startup-order documentation explicitly distinguishes container startup from service readiness and recommends health checks with `service_healthy` where readiness matters. ([Docker Documentation](https://docs.docker.com/compose/how-tos/startup-order/?utm_source=chatgpt.com "Control startup and shutdown order in Compose"))

You should still keep application-level retry logic.

Health checks and retries complement each other:

```text
Compose health dependency
→ avoids many premature starts

Application retry
→ handles restarts and later temporary outages
```

---

# 20. Understanding the PostgreSQL health check

The database service defines:

```yaml
healthcheck:
  test:
    - CMD-SHELL
    - pg_isready -U device_app -d device_monitor
  interval: 5s
  timeout: 3s
  retries: 10
  start_period: 10s
```

The health command:

```bash
pg_isready -U device_app -d device_monitor
```

checks whether PostgreSQL is accepting connections.

## `interval`

```yaml
interval: 5s
```

Run the health check every five seconds.

## `timeout`

```yaml
timeout: 3s
```

Fail an individual check if it takes more than three seconds.

## `retries`

```yaml
retries: 10
```

Mark the service unhealthy after repeated failures.

## `start_period`

```yaml
start_period: 10s
```

Allow an initial startup grace period before failures count normally.

---

# 21. Validate the Compose file

Before starting the stack, run:

```bash
docker compose config
```

This:

- Parses the YAML
    
- Validates the Compose model
    
- Expands defaults and variables
    
- Shows the resolved configuration
    

Compose’s official quickstart recommends using Compose to manage services, networks, and volumes from a unified YAML configuration. ([Docker Documentation](https://docs.docker.com/compose/gettingstarted/?utm_source=chatgpt.com "Docker Compose Quickstart"))

If the YAML indentation is wrong, this command will usually expose it before containers are created.

Show only service names:

```bash
docker compose config --services
```

Expected:

```text
database
api
```

Show declared volumes:

```bash
docker compose config --volumes
```

Expected:

```text
postgres-data
```

Show declared networks:

```bash
docker compose config --networks
```

Expected:

```text
backend
```

---

# 22. Start the application in the foreground

Run:

```bash
docker compose up
```

Compose will:

1. Read `compose.yaml`
    
2. Build the API image
    
3. Pull PostgreSQL if needed
    
4. Create the project network
    
5. Create the volume
    
6. Create the PostgreSQL container
    
7. Start PostgreSQL
    
8. Check PostgreSQL health
    
9. Start the API container
    
10. Attach your terminal to combined logs
    

Compose is intended to create and start all defined application services from a single configuration and command. ([Docker Documentation](https://docs.docker.com/compose/?utm_source=chatgpt.com "Docker Compose"))

You should see logs from both services, usually prefixed with service names.

Example:

```text
database-1  | ...
api-1       | ...
```

---

# 23. Stop foreground Compose safely

While `docker compose up` is attached, press:

```text
Ctrl+C
```

Compose stops the running services.

The containers normally still exist.

Check:

```bash
docker compose ps -a
```

You should see them in an exited state.

The network and named volume also remain.

---

# 24. Start in detached mode

Run:

```bash
docker compose up -d
```

The `-d` option means detached mode.

Compose starts the stack and returns your shell prompt.

Check:

```bash
docker compose ps
```

You should see something similar to:

```text
NAME                    SERVICE    STATUS
device-api-api-1        api        running
device-api-database-1   database   running (healthy)
```

Test:

```bash
curl http://localhost:8080/health
```

Then:

```bash
curl -s http://localhost:8080/api/devices \
  | python3 -m json.tool
```

---

# 25. Compose service names versus container names

Compose distinguishes:

```text
Service name:
api

Generated container name:
device-api-api-1
```

and:

```text
Service name:
database

Generated container name:
device-api-database-1
```

Inside the Compose network, use the service name:

```text
database
```

Do not configure the application with the generated container name.

The generated container name contains project and instance details that may change.

The service name is the stable application-level identity.

---

# 26. Do not set `container_name` unnecessarily

You may see examples such as:

```yaml
database:
  container_name: database
```

This can make names look simpler, but it is normally better to let Compose generate container names.

Reasons include:

- Project names prevent collisions.
    
- Compose can manage multiple copies more cleanly.
    
- Generated names communicate project ownership.
    
- Hard-coded global container names can conflict.
    
- Scaling a service becomes more restrictive.
    

Use service names for internal DNS.

You rarely need `container_name`.

---

# 27. Inspect the Compose stack

List services and status:

```bash
docker compose ps
```

Include stopped containers:

```bash
docker compose ps -a
```

Show container IDs:

```bash
docker compose ps -q
```

Show images:

```bash
docker compose images
```

Show running processes:

```bash
docker compose top
```

Inspect underlying Docker resources:

```bash
docker network ls
docker volume ls
docker ps
```

Compose does not replace Docker Engine. It organizes Docker resources as a project.

---

# 28. View logs

View all service logs:

```bash
docker compose logs
```

Follow logs:

```bash
docker compose logs -f
```

Show only API logs:

```bash
docker compose logs api
```

Follow database logs:

```bash
docker compose logs -f database
```

Show the last 50 lines:

```bash
docker compose logs --tail 50 api
```

Compose log prefixes make it easier to distinguish services.

---

# 29. Execute commands inside services

Run a command in the running API container:

```bash
docker compose exec api id
```

Check environment:

```bash
docker compose exec api env
```

Resolve PostgreSQL:

```bash
docker compose exec api getent hosts database
```

Run a PostgreSQL query:

```bash
docker compose exec database \
  psql \
  -U device_app \
  -d device_monitor \
  -c 'SELECT * FROM devices ORDER BY id;'
```

`docker compose exec` targets a service instead of requiring you to find the generated container name.

---

# 30. `exec` versus `run`

These commands are different.

## `docker compose exec`

```bash
docker compose exec api id
```

Runs a command inside an already-running service container.

## `docker compose run`

```bash
docker compose run --rm api python --version
```

Creates a new one-off container using the service configuration.

Use `run` for:

- Administrative jobs
    
- Database migrations
    
- Tests
    
- One-time commands
    
- Validation
    

By default, a one-off `run` command does not necessarily publish the service’s ports because doing so could conflict with the running service.

---

# 31. Add a device

Run:

```bash
curl -s \
  -X POST \
  http://localhost:8080/api/devices \
  -H 'Content-Type: application/json' \
  -d '{
    "device_name": "compose-device-01",
    "online": true,
    "firmware_version": "2.0.0"
  }' \
  | python3 -m json.tool
```

Verify through the API:

```bash
curl -s http://localhost:8080/api/devices \
  | python3 -m json.tool
```

Verify directly:

```bash
docker compose exec database \
  psql \
  -U device_app \
  -d device_monitor \
  -c 'SELECT id, device_name, online FROM devices ORDER BY id;'
```

---

# 32. Stop services without removing them

Run:

```bash
docker compose stop
```

This stops service containers but preserves:

- Containers
    
- Network
    
- Volume
    
- Container configuration
    

Check:

```bash
docker compose ps -a
```

Start them again:

```bash
docker compose start
```

This starts the same existing containers.

The analogy is:

```text
docker stop/start
→ individual container lifecycle

docker compose stop/start
→ project service-container lifecycle
```

---

# 33. Restart services

Restart everything:

```bash
docker compose restart
```

Restart only the API:

```bash
docker compose restart api
```

Restart only the database:

```bash
docker compose restart database
```

Restart does not rebuild images.

It also does not apply most changed Compose configuration.

If you change environment variables or port mappings, use:

```bash
docker compose up -d
```

so Compose can recreate the affected service.

---

# 34. What `docker compose up` really does

`docker compose up` is not only a start command.

It compares the desired configuration with existing project resources.

Depending on changes, it may:

- Create missing services
    
- Start stopped services
    
- Recreate changed service containers
    
- Build requested images
    
- Reuse unchanged containers
    
- Create networks
    
- Create volumes
    

Therefore:

```bash
docker compose up -d
```

is the normal reconciliation command.

It means approximately:

> Make the running project match the Compose configuration.

---

# 35. Change an environment variable

In `compose.yaml`, change:

```yaml
APP_VERSION: 1.0.0
```

to:

```yaml
APP_VERSION: 1.1.0
```

Run:

```bash
docker compose up -d
```

Compose should recreate the API container because its configuration changed.

The database container should normally remain unchanged.

Test:

```bash
curl -s http://localhost:8080/api/config \
  | python3 -m json.tool
```

Expected version:

```text
1.1.0
```

This demonstrates targeted service recreation.

---

# 36. Modify application source code

Change something in `app.py`, such as the application version response or a message.

Then run:

```bash
docker compose up -d
```

The image may not be rebuilt automatically merely because you expect it to be.

Use:

```bash
docker compose up -d --build
```

This tells Compose to build before starting or recreating services.

Alternatively:

```bash
docker compose build api
```

Then:

```bash
docker compose up -d
```

---

# 37. Build commands

Build every buildable service:

```bash
docker compose build
```

Build only the API:

```bash
docker compose build api
```

Build without cache:

```bash
docker compose build --no-cache api
```

Pull newer base images where appropriate:

```bash
docker compose build --pull api
```

Build and start:

```bash
docker compose up -d --build
```

The last form is common during development.

---

# 38. Force recreation

Force Compose to recreate containers even if their configuration appears unchanged:

```bash
docker compose up -d --force-recreate
```

Force only one service:

```bash
docker compose up -d \
  --force-recreate \
  api
```

Do not use this automatically for every operation.

Normally Compose can determine which services need recreation.

---

# 39. Remove the project containers and network

Run:

```bash
docker compose down
```

This removes:

- Compose service containers
    
- Compose project network
    

It normally preserves:

- Named volumes
    
- Built images
    

Check:

```bash
docker compose ps -a
```

The service containers should be gone.

Check the volume:

```bash
docker volume ls
```

The project’s PostgreSQL volume should still exist.

---

# 40. `stop` versus `down`

## `docker compose stop`

```text
Stops containers
Keeps containers
Keeps network
Keeps volumes
```

You can resume with:

```bash
docker compose start
```

## `docker compose down`

```text
Stops containers
Removes containers
Removes project networks
Keeps named volumes by default
```

To recreate:

```bash
docker compose up -d
```

This creates new containers.

The difference mirrors:

```text
Stop same runtime instances
versus
Remove and recreate runtime instances
```

---

# 41. Verify database persistence through `down`

Before running `down`, add a record.

Then:

```bash
docker compose down
```

Confirm the volume remains:

```bash
docker volume ls \
  --filter name=postgres-data
```

Start again:

```bash
docker compose up -d
```

Wait for health:

```bash
docker compose ps
```

Then verify:

```bash
curl -s http://localhost:8080/api/devices \
  | python3 -m json.tool
```

The record should remain.

The PostgreSQL container was replaced, but the named volume survived.

---

# 42. Remove volumes deliberately

To remove the stack and its named volumes:

```bash
docker compose down -v
```

This is destructive.

It removes:

- Containers
    
- Project networks
    
- Named volumes declared by the project
    
- Database data stored in those volumes
    

After:

```bash
docker compose down -v
docker compose up -d
```

PostgreSQL initializes a fresh empty database and the application recreates only its seed records.

Use `-v` only when you genuinely intend to delete project data.

---

# 43. Remove images with `down`

You can also remove images:

```bash
docker compose down --rmi local
```

Or:

```bash
docker compose down --rmi all
```

This is useful for cleanup, but it is not part of normal stop/start operation.

Do not combine destructive flags casually:

```bash
docker compose down -v --rmi all
```

That removes:

- Containers
    
- Networks
    
- Volumes
    
- Images
    

---

# 44. Project names

Compose assigns a project name.

By default, it is usually derived from the project directory.

For a directory:

```text
device-api
```

resources may be named:

```text
device-api-api-1
device-api-database-1
device-api_backend
device-api_postgres-data
```

Specify another project name:

```bash
docker compose \
  --project-name day13 \
  up -d
```

Short form:

```bash
docker compose -p day13 up -d
```

Resources then use the `day13` prefix.

Project names let you run multiple isolated copies of the same Compose application.

---

# 45. Run two copies of the application

The same host port cannot be reused by both projects.

Create a second Compose override later, or temporarily change the port.

First project:

```bash
docker compose -p day13a up -d
```

If the second project also tries:

```text
8080:5000
```

it will fail because host port 8080 is already allocated.

The network and volume names are isolated by project, but published host ports remain global to the Docker host.

This distinction is important:

```text
Compose project-scoped:
containers
networks
volumes

Docker-host-scoped:
published ports
```

---

# 46. Environment variable substitution

Compose can read variables from your shell or a project `.env` file.

Modify `compose.yaml`:

```yaml
services:
  api:
    ports:
      - "${API_HOST_PORT:-8080}:5000"
    environment:
      APP_ENV: "${APP_ENV:-development}"
      APP_VERSION: "${APP_VERSION:-1.0.0}"

      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: "${DB_NAME:-device_monitor}"
      DB_USER: "${DB_USER:-device_app}"
      DB_PASSWORD: "${DB_PASSWORD}"
```

Database:

```yaml
database:
  environment:
    POSTGRES_USER: "${DB_USER:-device_app}"
    POSTGRES_PASSWORD: "${DB_PASSWORD}"
    POSTGRES_DB: "${DB_NAME:-device_monitor}"
```

The syntax:

```text
${VARIABLE}
```

requires the variable.

The syntax:

```text
${VARIABLE:-default}
```

uses a default when unset or empty.

---

# 47. Create a project `.env` file

Create:

```bash
nano .env
```

Add:

```text
API_HOST_PORT=8080
APP_ENV=development
APP_VERSION=1.0.0

DB_NAME=device_monitor
DB_USER=device_app
DB_PASSWORD=development-password
```

Set permissions:

```bash
chmod 600 .env
```

Add it to `.gitignore`:

```bash
echo '.env' >> .gitignore
```

Then validate:

```bash
docker compose config
```

Compose substitutes the variables into the resolved configuration.

Be aware: this `.env` file is primarily used for Compose interpolation. It is not automatically the same thing as passing an `env_file` into containers.

---

# 48. `.env` versus `env_file`

These mechanisms are related but different.

## Project `.env`

Used by Compose to substitute variables in `compose.yaml`.

Example:

```yaml
ports:
  - "${API_HOST_PORT}:5000"
```

## Service `env_file`

Passes variables into a container.

Example:

```yaml
api:
  env_file:
    - api.env
```

Create:

```text
api.env
```

with:

```text
APP_ENV=development
APP_VERSION=1.0.0
LOG_LEVEL=INFO
```

You can use both mechanisms, but do not confuse them.

---

# 49. A cleaner Compose file with interpolation

Use this improved version:

```yaml
services:
  api:
    build:
      context: .
    image: day13-device-api:${APP_IMAGE_TAG:-1.0.0}
    ports:
      - "${API_HOST_PORT:-8080}:5000"
    environment:
      APP_ENV: "${APP_ENV:-development}"
      APP_VERSION: "${APP_VERSION:-1.0.0}"
      LOG_LEVEL: "${LOG_LEVEL:-INFO}"

      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: "${DB_NAME:-device_monitor}"
      DB_USER: "${DB_USER:-device_app}"
      DB_PASSWORD: "${DB_PASSWORD}"
    depends_on:
      database:
        condition: service_healthy
    restart: unless-stopped
    networks:
      - backend

  database:
    image: postgres:17
    environment:
      POSTGRES_USER: "${DB_USER:-device_app}"
      POSTGRES_PASSWORD: "${DB_PASSWORD}"
      POSTGRES_DB: "${DB_NAME:-device_monitor}"
    volumes:
      - postgres-data:/var/lib/postgresql/data
    healthcheck:
      test:
        - CMD-SHELL
        - >
          pg_isready
          -U ${DB_USER:-device_app}
          -d ${DB_NAME:-device_monitor}
      interval: 5s
      timeout: 3s
      retries: 10
      start_period: 10s
    restart: unless-stopped
    networks:
      - backend

volumes:
  postgres-data:

networks:
  backend:
```

---

# 50. Understanding `restart`

The service contains:

```yaml
restart: unless-stopped
```

This is equivalent to the Docker restart policy you learned earlier.

It tells Docker to restart the service container after failures or daemon restarts unless it was deliberately stopped.

This does not mean the service is healthy.

A broken application can restart repeatedly.

Always inspect:

```bash
docker compose ps
docker compose logs
```

---

# 51. Check service health

Run:

```bash
docker compose ps
```

The database may show:

```text
running (healthy)
```

Inspect its health details:

```bash
docker inspect \
  "$(docker compose ps -q database)" \
  --format '{{json .State.Health}}'
```

A simpler status:

```bash
docker inspect \
  "$(docker compose ps -q database)" \
  --format '{{.State.Health.Status}}'
```

Expected:

```text
healthy
```

The API itself does not yet have a Docker health check.

Its `/health` endpoint exists, but Docker does not automatically call it.

---

# 52. Add an API health check

Your Python image may not contain `curl`.

Use Python’s standard library in the health check:

```yaml
api:
  healthcheck:
    test:
      - CMD
      - python
      - -c
      - >
        import urllib.request;
        urllib.request.urlopen(
            'http://localhost:5000/health',
            timeout=3
        )
    interval: 10s
    timeout: 5s
    retries: 3
    start_period: 10s
```

The service becomes:

```yaml
api:
  build:
    context: .
  image: day13-device-api:${APP_IMAGE_TAG:-1.0.0}
  ports:
    - "${API_HOST_PORT:-8080}:5000"
  environment:
    APP_ENV: "${APP_ENV:-development}"
    APP_VERSION: "${APP_VERSION:-1.0.0}"
    LOG_LEVEL: "${LOG_LEVEL:-INFO}"

    DB_HOST: database
    DB_PORT: "5432"
    DB_NAME: "${DB_NAME:-device_monitor}"
    DB_USER: "${DB_USER:-device_app}"
    DB_PASSWORD: "${DB_PASSWORD}"
  depends_on:
    database:
      condition: service_healthy
  healthcheck:
    test:
      - CMD
      - python
      - -c
      - >
        import urllib.request;
        urllib.request.urlopen(
            'http://localhost:5000/health',
            timeout=3
        )
    interval: 10s
    timeout: 5s
    retries: 3
    start_period: 10s
  restart: unless-stopped
  networks:
    - backend
```

Apply:

```bash
docker compose up -d --build
```

Inspect:

```bash
docker compose ps
```

---

# 53. Health check path uses container networking

The API health check calls:

```text
http://localhost:5000/health
```

This is correct because the check runs inside the API container.

Inside that container:

```text
localhost:5000
```

refers to the API itself.

This differs from the API’s database connection:

```text
database:5432
```

because PostgreSQL runs in another service container.

The rule remains:

```text
Same container:
localhost

Another Compose service:
service-name
```

---

# 54. Deliberately break the database password

Change the API password only:

```yaml
DB_PASSWORD: wrong-password
```

Leave PostgreSQL configured with the correct password.

Run:

```bash
docker compose up -d
```

Inspect:

```bash
docker compose ps
```

Then:

```bash
docker compose logs api
```

The API may:

- Fail startup after retries
    
- Restart according to policy
    
- Remain unhealthy
    
- Produce authentication errors
    

Restore:

```yaml
DB_PASSWORD: "${DB_PASSWORD}"
```

Then:

```bash
docker compose up -d
```

This exercise demonstrates how Compose manages the desired configuration but cannot fix incorrect application settings.

---

# 55. Deliberately break network membership

Remove the API’s backend network:

```yaml
api:
  networks: []
```

or temporarily comment out:

```yaml
networks:
  - backend
```

Be cautious: when no network is specified, Compose normally attaches the service to its default project network. Since the database still uses only `backend`, the two services will not share a network.

Apply:

```bash
docker compose up -d
```

Inspect:

```bash
docker compose logs api
```

The error should indicate failure to resolve:

```text
database
```

Restore the shared network and apply again.

---

# 56. Compose creates a default network automatically

You can simplify the first file by removing all explicit network declarations:

```yaml
services:
  api:
    ...

  database:
    ...

volumes:
  postgres-data:
```

Compose creates one default project network and attaches both services automatically.

Then:

```text
api → database:5432
```

still works.

Why define `backend` explicitly?

- It documents architectural intent.
    
- It prepares the project for multiple network tiers.
    
- It makes service membership explicit.
    
- It is easier to extend later.
    

For a simple two-service application, the default network is acceptable.

---

# 57. Use multiple Compose networks

You can introduce:

```yaml
networks:
  frontend:
  backend:
    internal: true
```

Attach the API to both:

```yaml
api:
  networks:
    - frontend
    - backend
```

Attach PostgreSQL only to backend:

```yaml
database:
  networks:
    - backend
```

The model becomes:

```text
frontend network
└── API

backend internal network
├── API
└── database
```

Because the API publishes its host port, browsers still access it through:

```text
localhost:8080
```

PostgreSQL remains backend-only.

---

# 58. Complete improved Compose file

A more structured version is:

```yaml
services:
  api:
    build:
      context: .
    image: day13-device-api:${APP_IMAGE_TAG:-1.0.0}
    ports:
      - "${API_HOST_PORT:-8080}:5000"
    environment:
      APP_ENV: "${APP_ENV:-development}"
      APP_VERSION: "${APP_VERSION:-1.0.0}"
      LOG_LEVEL: "${LOG_LEVEL:-INFO}"

      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: "${DB_NAME:-device_monitor}"
      DB_USER: "${DB_USER:-device_app}"
      DB_PASSWORD: "${DB_PASSWORD}"
    depends_on:
      database:
        condition: service_healthy
    healthcheck:
      test:
        - CMD
        - python
        - -c
        - >
          import urllib.request;
          urllib.request.urlopen(
              'http://localhost:5000/health',
              timeout=3
          )
      interval: 10s
      timeout: 5s
      retries: 3
      start_period: 10s
    restart: unless-stopped
    networks:
      - frontend
      - backend

  database:
    image: postgres:17
    environment:
      POSTGRES_USER: "${DB_USER:-device_app}"
      POSTGRES_PASSWORD: "${DB_PASSWORD}"
      POSTGRES_DB: "${DB_NAME:-device_monitor}"
    volumes:
      - postgres-data:/var/lib/postgresql/data
    healthcheck:
      test:
        - CMD-SHELL
        - >
          pg_isready
          -U ${DB_USER:-device_app}
          -d ${DB_NAME:-device_monitor}
      interval: 5s
      timeout: 3s
      retries: 10
      start_period: 10s
    restart: unless-stopped
    networks:
      - backend

volumes:
  postgres-data:

networks:
  frontend:
  backend:
    internal: true
```

Create `.env`:

```text
API_HOST_PORT=8080
APP_IMAGE_TAG=1.0.0
APP_ENV=development
APP_VERSION=1.0.0
LOG_LEVEL=INFO

DB_NAME=device_monitor
DB_USER=device_app
DB_PASSWORD=development-password
```

---

# 59. Compose troubleshooting sequence

When the project fails, use this order.

## Validate configuration

```bash
docker compose config
```

## Check services

```bash
docker compose ps -a
```

## View logs

```bash
docker compose logs
```

Specific service:

```bash
docker compose logs api
docker compose logs database
```

## Check health

```bash
docker inspect \
  "$(docker compose ps -q database)" \
  --format '{{.State.Health.Status}}'
```

## Inspect networks

```bash
docker compose exec api \
  getent hosts database
```

## Test database access

```bash
docker compose exec database \
  pg_isready \
  -U device_app \
  -d device_monitor
```

## Inspect resolved environment

```bash
docker compose exec api env
```

## Check volume

```bash
docker inspect \
  "$(docker compose ps -q database)" \
  --format '{{json .Mounts}}'
```

---

# 60. Common Compose mistakes

## Incorrect indentation

Run:

```bash
docker compose config
```

to locate YAML errors.

## Using tabs

YAML should use spaces.

## Using `localhost` between services

Use:

```text
database
```

not:

```text
localhost
```

## Using the host port internally

The API connects to:

```text
database:5432
```

not:

```text
database:8080
```

## Publishing every service

Only expose services needed outside the Compose networks.

## Expecting `depends_on` alone to guarantee readiness

Use health checks and keep application retries.

## Changing source without rebuilding

Use:

```bash
docker compose up -d --build
```

## Running `restart` after configuration changes

Use:

```bash
docker compose up -d
```

so Compose can recreate affected containers.

## Accidentally using `down -v`

This deletes named volume data.

## Mistyping a volume name

A new empty volume may be created, making data appear lost.

## Hard-coding generated container names

Use Compose service names.

---

# 61. Day 13 practical laboratory

## Exercise 1 — Verify Compose

Run:

```bash
docker compose version
```

Confirm the plugin works.

---

## Exercise 2 — Define the stack

Create `compose.yaml` containing:

- API service
    
- PostgreSQL service
    
- Named volume
    
- Backend network
    
- API port mapping
    
- Runtime environment values
    

---

## Exercise 3 — Validate

Run:

```bash
docker compose config
docker compose config --services
docker compose config --volumes
docker compose config --networks
```

Resolve all errors before starting.

---

## Exercise 4 — Start in foreground

Run:

```bash
docker compose up
```

Observe:

- Image building
    
- Image pulling
    
- Network creation
    
- Volume creation
    
- PostgreSQL initialization
    
- Health-check behavior
    
- API startup
    
- Combined logs
    

Stop using `Ctrl+C`.

---

## Exercise 5 — Start detached

Run:

```bash
docker compose up -d
```

Check:

```bash
docker compose ps
docker compose logs
```

Test the API.

---

## Exercise 6 — Execute service commands

Use:

```bash
docker compose exec api id
```

Then:

```bash
docker compose exec api getent hosts database
```

Then query PostgreSQL through:

```bash
docker compose exec database psql ...
```

---

## Exercise 7 — Test persistence

Create a device.

Run:

```bash
docker compose down
```

Start again:

```bash
docker compose up -d
```

Confirm the record remains.

---

## Exercise 8 — Compare `stop` and `down`

Run:

```bash
docker compose stop
docker compose ps -a
docker compose start
```

Then:

```bash
docker compose down
```

Explain which resources remain in each case.

---

## Exercise 9 — Rebuild the API

Modify `app.py`.

Run:

```bash
docker compose up -d --build
```

Confirm:

- API image rebuilt
    
- API container replaced
    
- Database records remain
    
- PostgreSQL container may remain unchanged
    

---

## Exercise 10 — Test health dependencies

Stop and remove the stack:

```bash
docker compose down
```

Start it:

```bash
docker compose up
```

Observe whether the API waits for the database health check.

---

## Exercise 11 — Test configuration interpolation

Move values into `.env`.

Use:

```yaml
${VARIABLE}
```

and:

```yaml
${VARIABLE:-default}
```

Run:

```bash
docker compose config
```

Confirm substituted values.

---

## Exercise 12 — Test data deletion

After confirming you understand the consequence:

```bash
docker compose down -v
```

Start again:

```bash
docker compose up -d
```

Confirm the database is fresh and only seed records remain.

---

# 62. Day 13 command reference

```bash
# Show Compose version
docker compose version

# Validate and render configuration
docker compose config

# List defined services
docker compose config --services

# Build services
docker compose build

# Build without cache
docker compose build --no-cache

# Create and start in foreground
docker compose up

# Create and start detached
docker compose up -d

# Build and start
docker compose up -d --build

# List project containers
docker compose ps

# Include stopped containers
docker compose ps -a

# View logs
docker compose logs

# Follow logs
docker compose logs -f

# View one service
docker compose logs api

# Execute inside a running service
docker compose exec api COMMAND

# Create a one-off service container
docker compose run --rm api COMMAND

# Stop without removing
docker compose stop

# Start existing service containers
docker compose start

# Restart services
docker compose restart

# Remove containers and project networks
docker compose down

# Also delete named volumes
docker compose down -v

# Force service recreation
docker compose up -d --force-recreate

# Use a custom project name
docker compose -p PROJECT_NAME up -d
```

---

# 63. Knowledge check

## What problem does Docker Compose solve?

It defines and manages a multi-container application, including its services, networks, volumes, ports, builds, and configuration.

## What is the difference between Dockerfile and Compose?

A Dockerfile builds an image. Compose describes how containers and supporting resources run together.

## What is the modern command?

```bash
docker compose
```

## What is a Compose service?

A logical application component that Compose runs using an image or build definition.

## How does the API find PostgreSQL?

Through the Compose service name:

```text
database
```

## Does PostgreSQL need a published port?

No, because the API connects through the internal Compose network.

## What does `depends_on` do?

It declares service dependencies and startup ordering. With `condition: service_healthy`, it can wait for a dependency’s health check.

## Does `depends_on` replace application retry logic?

No. Retries remain important for later outages and restarts.

## What does `docker compose up` do?

It creates, starts, and reconciles the Compose application resources.

## What does `docker compose stop` do?

It stops the service containers but preserves them.

## What does `docker compose down` do?

It removes the project containers and networks but normally preserves named volumes.

## What does `docker compose down -v` do?

It also removes the project’s named volumes and their stored data.

## When should you use `--build`?

When source code or image-building inputs changed and you need the service image rebuilt.

## Why should you use service names rather than generated container names?

Service names are stable application identities and are used for Compose DNS discovery.

---

# 64. Day 13 completion challenge

Complete this independently:

1. Verify the Docker Compose plugin.
    
2. Add `compose.yaml` to the Day 12 project.
    
3. Define the API as a buildable service.
    
4. Assign an explicit image name and tag.
    
5. Define PostgreSQL using `postgres:17`.
    
6. Publish only the API.
    
7. Give PostgreSQL no host port.
    
8. Set `DB_HOST` to the database service name.
    
9. Declare a PostgreSQL named volume.
    
10. Mount it at the correct data directory.
    
11. Declare a backend network.
    
12. Attach both services.
    
13. Add a PostgreSQL health check.
    
14. Make the API depend on database health.
    
15. Validate the Compose configuration.
    
16. Start the stack in the foreground.
    
17. Observe combined logs.
    
18. Stop it with `Ctrl+C`.
    
19. Start it detached.
    
20. List service states.
    
21. Confirm PostgreSQL is healthy.
    
22. Test the API.
    
23. Add a database record.
    
24. Execute a SQL query with `docker compose exec`.
    
25. Stop the project without removing containers.
    
26. Start the same containers again.
    
27. Remove the project using `down`.
    
28. Confirm the named volume remains.
    
29. Recreate the project.
    
30. Confirm the record remains.
    
31. Modify an application source file.
    
32. Rebuild only the application service.
    
33. Recreate the API without deleting the database.
    
34. Move configuration values into `.env`.
    
35. Add safe default interpolation.
    
36. Add an API health check.
    
37. Deliberately configure the wrong database password.
    
38. Diagnose the failure through Compose logs.
    
39. Restore the correct password.
    
40. Run `down -v` only after confirming that you intend to delete all database data.
    

The central Day 13 model is:

```text
compose.yaml
├── api service
│   ├── Dockerfile build
│   ├── host port 8080
│   └── backend network
│
├── database service
│   ├── postgres image
│   ├── health check
│   ├── backend network
│   └── persistent volume
│
├── backend network
└── postgres-data volume
```

The most important operational lesson is:

> Docker Compose turns a collection of manual Docker commands into a declarative application definition. Describe the desired services, networks, volumes, health checks, and configuration in `compose.yaml`, then use `docker compose up` to make the running system match that definition.