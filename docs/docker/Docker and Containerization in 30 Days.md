[[30 days Docker]]

This program takes you from **running your first container** to designing, securing, troubleshooting, and deploying a realistic multi-container system.

The course will use a practical capstone that matches your technical background:

> **Containerize and deploy an MQTT device-monitoring platform composed of:**
> 
> - Mosquitto MQTT broker
>     
> - PHP or Python web dashboard
>     
> - SQLite initially, then PostgreSQL
>     
> - Background MQTT consumer
>     
> - Nginx reverse proxy
>     
> - Persistent storage
>     
> - Health checks, logging, backups, security, and automated builds
>     

Docker Engine uses a client-server architecture: the Docker CLI communicates with the Docker daemon, which manages images, containers, networks, and volumes. Modern Docker builds use Buildx and BuildKit by default. ([Docker Documentation](https://docs.docker.com/engine/?utm_source=chatgpt.com "Docker Engine"))

---

## Learning approach

Plan for approximately **60–90 minutes per day**:

- 15–20 minutes learning the concept
    
- 30–45 minutes running commands and experimenting
    
- 15–25 minutes completing the daily exercise
    

Do not simply copy commands. After every exercise:

1. Stop the container.
    
2. Remove it.
    
3. Recreate it without looking at the instructions.
    
4. Deliberately break one configuration.
    
5. Diagnose and repair it.
    

By Day 30, you should be comfortable operating Docker without treating it as a collection of memorized commands.

---

# Week 1 — Containers, Images, and Docker Fundamentals

## Day 1 — What containers really are

### Learn

Understand the differences between:

- Physical server
    
- Virtual machine
    
- Container
    
- Image
    
- Container runtime
    
- Docker Engine
    
- Docker daemon
    
- Docker CLI
    
- Registry
    
- Repository
    
- Image tag
    

The most important mental model:

- An **image** is a packaged filesystem plus configuration.
    
- A **container** is a running or stopped instance of an image.
    
- A container is still an ordinary Linux process, but with isolation and resource controls.
    

### Practice

```bash
docker version
docker info
docker run hello-world
docker run alpine echo "Hello from Alpine"
docker ps
docker ps -a
docker images
```

Run an interactive shell:

```bash
docker run --rm -it alpine sh
```

Inside the container:

```sh
cat /etc/os-release
hostname
ps
ls /
exit
```

### Exercise

Run Ubuntu interactively:

```bash
docker run --rm -it ubuntu:24.04 bash
```

Install `curl` inside it:

```bash
apt update
apt install -y curl
```

Exit and recreate the container. Observe that `curl` is gone.

### Key lesson

Changes made inside a container normally belong to that specific container. They are not automatically added to the original image.

---

## Day 2 — The container lifecycle

### Learn

A container can be:

- Created
    
- Running
    
- Paused
    
- Stopped
    
- Restarted
    
- Removed
    

Understand the difference between:

```bash
docker run
docker create
docker start
docker stop
docker restart
docker rm
```

### Practice

```bash
docker run -d --name web nginx
docker ps
docker stop web
docker ps -a
docker start web
docker restart web
docker inspect web
docker rm -f web
```

Follow logs:

```bash
docker run -d --name web nginx
docker logs web
docker logs -f web
```

Execute a command in an existing container:

```bash
docker exec -it web sh
```

### Exercise

Start an Nginx container, inspect it, enter its shell, locate its configuration files, stop it, restart it, and remove it.

---

## Day 3 — Ports and container networking basics

### Learn

A container has its own network namespace.

This command:

```bash
docker run -p 8080:80 nginx
```

means:

```text
host port 8080 → container port 80
```

The two port numbers do not need to match.

### Practice

```bash
docker run -d \
  --name nginx-demo \
  -p 8080:80 \
  nginx
```

Test:

```bash
curl http://localhost:8080
```

Inspect port mappings:

```bash
docker port nginx-demo
docker inspect nginx-demo
```

Try another mapping:

```bash
docker run -d \
  --name nginx-second \
  -p 8081:80 \
  nginx
```

### Exercise

Run three Nginx containers using host ports:

- 8081
    
- 8082
    
- 8083
    

Explain why all three can listen on port 80 internally.

---

## Day 4 — Images and registries

### Learn

An image name commonly follows this form:

```text
registry/namespace/repository:tag
```

Examples:

```text
nginx:1.28
ubuntu:24.04
postgres:17
ghcr.io/company/application:2.1.0
```

A tag is a human-readable reference. It is not necessarily immutable.

### Practice

```bash
docker pull nginx
docker pull nginx:alpine
docker image ls
docker image inspect nginx:alpine
docker history nginx:alpine
```

Remove unused images:

```bash
docker image rm nginx:alpine
docker image prune
```

Inspect exact image digests:

```bash
docker image inspect nginx \
  --format '{{json .RepoDigests}}'
```

### Exercise

Compare:

```bash
docker image inspect nginx
docker image inspect nginx:alpine
```

Look at:

- Size
    
- Architecture
    
- Environment variables
    
- Entrypoint
    
- Command
    
- Exposed ports
    

---

## Day 5 — Writing your first Dockerfile

A Dockerfile describes how an image is built. Docker supports instructions such as `FROM`, `RUN`, `COPY`, `WORKDIR`, `CMD`, `ENTRYPOINT`, `ARG`, and `ENV`. ([Docker Documentation](https://docs.docker.com/reference/dockerfile/?utm_source=chatgpt.com "Dockerfile reference | Docker Docs"))

Create:

```text
day05/
├── Dockerfile
└── index.html
```

`index.html`:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Docker Course</title>
</head>
<body>
    <h1>My first custom Docker image</h1>
</body>
</html>
```

`Dockerfile`:

```dockerfile
FROM nginx:alpine

COPY index.html /usr/share/nginx/html/index.html
```

Build it:

```bash
docker build -t george/static-site:1.0 .
```

Run it:

```bash
docker run -d \
  --name static-site \
  -p 8080:80 \
  george/static-site:1.0
```

### Exercise

Change the HTML, rebuild as version `1.1`, and run both versions simultaneously on different ports.

---

## Day 6 — Dockerfile fundamentals

### Learn

Understand:

```dockerfile
FROM
WORKDIR
COPY
RUN
ENV
EXPOSE
USER
CMD
ENTRYPOINT
```

Example Python application:

`app.py`:

```python
import os
from flask import Flask

app = Flask(__name__)

@app.get("/")
def index():
    environment = os.getenv("APP_ENV", "development")
    return {"message": "Hello from Docker", "environment": environment}

app.run(host="0.0.0.0", port=5000)
```

`requirements.txt`:

```text
flask
```

`Dockerfile`:

```dockerfile
FROM python:3.13-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

ENV APP_ENV=development

EXPOSE 5000

CMD ["python", "app.py"]
```

Build and run:

```bash
docker build -t flask-demo:1.0 .

docker run --rm \
  -p 5000:5000 \
  -e APP_ENV=testing \
  flask-demo:1.0
```

### Important distinction

Shell form:

```dockerfile
CMD python app.py
```

Exec form:

```dockerfile
CMD ["python", "app.py"]
```

Prefer exec form for long-running applications because signal handling is usually clearer and more predictable.

---

## Day 7 — Weekly project: Containerize a small application

Containerize either:

- A PHP application
    
- A Python Flask API
    
- A small C daemon
    
- Your MQTT dashboard
    

Your image must:

- Use a specific base-image tag
    
- Copy only necessary files
    
- Define a working directory
    
- Expose the application port
    
- Accept configuration through environment variables
    
- Write logs to standard output
    
- Start using `CMD` or `ENTRYPOINT`
    

### Week 1 checkpoint

You should now be able to explain:

- Image versus container
    
- Build time versus runtime
    
- Host port versus container port
    
- `docker run` versus `docker exec`
    
- `CMD` versus `RUN`
    
- Why container changes disappear after removal
    

---

# Week 2 — Storage, Networks, and Docker Compose

## Day 8 — Container filesystems and ephemeral data

### Learn

Container filesystems should usually be treated as disposable.

Create a file:

```bash
docker run -it --name storage-test alpine sh
```

Inside:

```sh
echo "important data" > /data.txt
exit
```

Restart it:

```bash
docker start -ai storage-test
cat /data.txt
```

The file remains because the same container still exists.

Now remove it:

```bash
docker rm storage-test
```

Recreate it. The file no longer exists.

### Key lesson

Stopping is not removing.

Persistence tied to the container is not reliable application persistence.

---

## Day 9 — Bind mounts

A bind mount maps a host file or directory directly into a container. Unlike Docker-managed volumes, the host path is explicitly chosen by you. ([Docker Documentation](https://docs.docker.com/engine/storage/bind-mounts/?utm_source=chatgpt.com "Bind mounts"))

### Practice

```bash
mkdir website
echo '<h1>Bind mount example</h1>' > website/index.html
```

Run:

```bash
docker run --rm \
  -p 8080:80 \
  --mount type=bind,source="$PWD/website",target=/usr/share/nginx/html,readonly \
  nginx
```

Edit the host file and refresh the browser.

### Use bind mounts for

- Source code during development
    
- Configuration files
    
- Local development certificates
    
- Files that must remain easily accessible on the host
    

### Risks

- Host/container permission differences
    
- Strong coupling to the host filesystem
    
- Accidental overwriting
    
- Platform-specific paths
    
- Mounting more of the host than necessary
    

---

## Day 10 — Docker volumes

Docker volumes are persistent stores created and managed by Docker. ([Docker Documentation](https://docs.docker.com/engine/storage/volumes/?utm_source=chatgpt.com "Volumes"))

### Practice

```bash
docker volume create postgres-data
docker volume ls
docker volume inspect postgres-data
```

Run PostgreSQL:

```bash
docker run -d \
  --name postgres \
  -e POSTGRES_PASSWORD=secret \
  -e POSTGRES_DB=devices \
  -v postgres-data:/var/lib/postgresql/data \
  postgres:17
```

Remove the container:

```bash
docker rm -f postgres
```

Recreate it with the same volume. The database remains.

### Exercise

Create, inspect, reuse, back up, and restore a named volume.

Backup example:

```bash
docker run --rm \
  -v postgres-data:/source:ro \
  -v "$PWD/backups":/backup \
  alpine \
  tar czf /backup/postgres-data.tar.gz -C /source .
```

---

## Day 11 — Docker networks

### Learn

Docker provides several network drivers, including:

- `bridge`
    
- `host`
    
- `none`
    
- `overlay`
    
- `macvlan`
    

For normal single-host application stacks, user-defined bridge networks are especially important.

### Practice

```bash
docker network create app-network
```

Start PostgreSQL:

```bash
docker run -d \
  --name database \
  --network app-network \
  -e POSTGRES_PASSWORD=secret \
  postgres:17
```

Start a diagnostic container:

```bash
docker run --rm -it \
  --network app-network \
  alpine sh
```

Inside:

```sh
apk add --no-cache bind-tools
nslookup database
```

### Critical lesson

Containers communicate using:

- Container or service DNS name
    
- Internal container port
    

They normally should not use:

```text
localhost
```

to reach another container.

Inside a container, `localhost` means that same container.

---

## Day 12 — Container-to-container communication

Build a small application that connects to PostgreSQL.

Use configuration like:

```text
DB_HOST=database
DB_PORT=5432
DB_NAME=devices
DB_USER=postgres
DB_PASSWORD=secret
```

Do not use the host-published PostgreSQL port for communication between containers on the same Docker network.

### Exercise

Run:

- One web application
    
- One database
    
- One user-defined network
    

Verify that:

- The web container resolves the database by name
    
- PostgreSQL is not exposed publicly
    
- Only the web application has a published host port
    

---

## Day 13 — Docker Compose fundamentals

Docker Compose defines services, networks, and volumes in a YAML file and manages the complete application stack as a unit. By default, Compose creates an application network, and services can discover one another by service name. ([Docker Documentation](https://docs.docker.com/compose/?utm_source=chatgpt.com "Docker Compose"))

`compose.yaml`:

```yaml
services:
  web:
    image: nginx:alpine
    ports:
      - "8080:80"

  database:
    image: postgres:17
    environment:
      POSTGRES_DB: devices
      POSTGRES_USER: appuser
      POSTGRES_PASSWORD: development-password
    volumes:
      - database-data:/var/lib/postgresql/data

volumes:
  database-data:
```

Run:

```bash
docker compose up
docker compose up -d
docker compose ps
docker compose logs
docker compose logs -f web
docker compose down
```

Remove volumes too:

```bash
docker compose down -v
```

### Important warning

`docker compose down -v` deletes declared volumes. For databases, that may mean deleting all persistent data.

---

## Day 14 — Weekly project: Compose application stack

Build:

```text
Browser
   |
   v
Web application
   |
   v
PostgreSQL
```

Your Compose project must contain:

- Web application built from a Dockerfile
    
- PostgreSQL image
    
- Named database volume
    
- Environment-based configuration
    
- Internal service discovery
    
- One published web port
    
- No published database port unless needed for debugging
    

### Week 2 checkpoint

You should confidently distinguish:

|Requirement|Correct mechanism|
|---|---|
|Development source files|Bind mount|
|Database persistence|Named volume|
|Service-to-service communication|Docker network|
|Host access to web server|Published port|
|Multi-container definition|Compose|
|Application configuration|Environment or config files|

---

# Week 3 — Production-Quality Images and Compose

## Day 15 — Build context and `.dockerignore`

When running:

```bash
docker build .
```

the final `.` identifies the build context.

Docker can only `COPY` files from that context.

Create `.dockerignore`:

```text
.git
.gitignore
.env
node_modules
vendor
*.log
tests
backups
data/*.sqlite
```

### Why this matters

A poor build context can:

- Slow builds
    
- Invalidate cache unnecessarily
    
- Leak secrets into build data
    
- Increase image size
    
- Copy development artifacts into production images
    

### Exercise

Compare build output before and after adding `.dockerignore`.

---

## Day 16 — Image layers and build cache

Each major Dockerfile instruction can produce a cached build layer.

Poor ordering:

```dockerfile
COPY . .
RUN pip install -r requirements.txt
```

Better ordering:

```dockerfile
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .
```

The second layout avoids reinstalling dependencies whenever only application code changes.

Docker’s build cache is designed to reuse unchanged work, and cache mounts can preserve package-manager download caches between builds. ([Docker Documentation](https://docs.docker.com/build/cache/optimize/?utm_source=chatgpt.com "Optimize cache usage in builds"))

### Practice

Build repeatedly:

```bash
docker build --progress=plain -t cache-demo .
```

Observe:

```text
CACHED
```

Then modify:

- Application source
    
- Dependency file
    
- An early Dockerfile instruction
    

Observe which layers rebuild.

---

## Day 17 — Multi-stage builds

Multi-stage builds use multiple `FROM` instructions and allow you to copy only final artifacts into the runtime image. This keeps build tools out of the production image. ([Docker Documentation](https://docs.docker.com/build/building/multi-stage/?utm_source=chatgpt.com "Multi-stage builds"))

For a C program:

```dockerfile
FROM debian:13 AS builder

RUN apt-get update \
    && apt-get install -y --no-install-recommends gcc libc6-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /src

COPY main.c .

RUN gcc -O2 -Wall -Wextra -o application main.c


FROM debian:13-slim

WORKDIR /app

COPY --from=builder /src/application /app/application

CMD ["/app/application"]
```

### Exercise

Compare:

```bash
docker image ls
```

for:

- Single-stage build containing the compiler
    
- Multi-stage runtime image without the compiler
    

---

## Day 18 — Users, permissions, and filesystem ownership

Containers should not automatically run application processes as root.

Example:

```dockerfile
FROM python:3.13-slim

RUN groupadd --system appgroup \
    && useradd --system \
       --gid appgroup \
       --home-dir /app \
       appuser

WORKDIR /app

COPY --chown=appuser:appgroup . .

USER appuser

CMD ["python", "app.py"]
```

Docker’s security documentation recommends using non-privileged users inside containers where possible. Rootless Docker can also run both the daemon and containers without root privileges. ([Docker Documentation](https://docs.docker.com/engine/security/?utm_source=chatgpt.com "Docker Engine security"))

### Practice

Check the current user:

```bash
docker run --rm your-image id
```

Inspect processes:

```bash
docker top container-name
```

### Exercise

Convert your application container from root to a dedicated user without breaking file access.

---

## Day 19 — Environment variables, configuration, and secrets

### Runtime variables

```bash
docker run \
  -e APP_ENV=production \
  -e LOG_LEVEL=info \
  application
```

Compose:

```yaml
services:
  app:
    environment:
      APP_ENV: production
      LOG_LEVEL: info
```

From a file:

```yaml
services:
  app:
    env_file:
      - .env
```

### Important rules

Do not place passwords in:

- Dockerfile
    
- Git repository
    
- Image labels
    
- `ARG`
    
- Source code
    
- Public Compose files
    

Do not assume environment variables are secret merely because they are convenient.

Docker Swarm secrets provide encrypted-at-rest and encrypted-in-transit handling for Swarm services, but ordinary local Compose deployments require a deliberate secrets strategy appropriate to the platform. ([Docker Documentation](https://docs.docker.com/engine/swarm/secrets/?utm_source=chatgpt.com "Manage sensitive data with Docker secrets"))

### Exercise

Move all application-specific configuration out of your image.

The same image should run in:

- Development
    
- Testing
    
- Production
    

with only runtime configuration changing.

---

## Day 20 — Health checks and startup dependencies

A running container is not necessarily a healthy application.

Add a health check:

```dockerfile
HEALTHCHECK --interval=30s \
            --timeout=5s \
            --start-period=10s \
            --retries=3 \
    CMD curl --fail http://localhost:5000/health || exit 1
```

Compose:

```yaml
services:
  database:
    image: postgres:17
    environment:
      POSTGRES_PASSWORD: secret
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U postgres"]
      interval: 10s
      timeout: 5s
      retries: 5

  app:
    build: .
    depends_on:
      database:
        condition: service_healthy
```

### Critical lesson

`depends_on` does not eliminate the need for application-level retries.

Databases can temporarily restart, networks can fail, and services can become unavailable after startup.

Your application should reconnect gracefully.

---

## Day 21 — Weekly project: Production-quality Compose stack

Improve your project with:

- `.dockerignore`
    
- Explicit image versions
    
- Multi-stage build where appropriate
    
- Non-root process
    
- Health checks
    
- Restart policy
    
- Named volumes
    
- Internal networks
    
- Externalized configuration
    
- Application retry logic
    
- Read-only mounts where possible
    

Example:

```yaml
services:
  app:
    build:
      context: .
    restart: unless-stopped
    environment:
      DB_HOST: database
    depends_on:
      database:
        condition: service_healthy
    networks:
      - frontend
      - backend

  database:
    image: postgres:17
    restart: unless-stopped
    volumes:
      - database-data:/var/lib/postgresql/data
    networks:
      - backend

networks:
  frontend:
  backend:
    internal: true

volumes:
  database-data:
```

---

# Week 4 — Operations, Security, Deployment, and Advanced Builds

## Day 22 — Logs and observability

Containers should generally write application logs to:

- Standard output
    
- Standard error
    

Inspect:

```bash
docker logs application
docker logs --since 10m application
docker logs --tail 100 application
docker logs -f application
```

Compose:

```bash
docker compose logs
docker compose logs -f app
```

Check resource use:

```bash
docker stats
docker top application
docker inspect application
```

### Avoid

Writing critical logs only to files inside the container.

Those files are harder to collect and may disappear with the container.

### Exercise

Make your application output structured log lines:

```json
{
  "level": "info",
  "service": "mqtt-consumer",
  "message": "Device heartbeat received",
  "device_id": "vm-karlsfeld-01"
}
```

---

## Day 23 — Resource limits and restart behavior

Run with resource limits:

```bash
docker run \
  --memory=256m \
  --cpus=0.5 \
  application
```

Compose:

```yaml
services:
  app:
    image: application:1.0
    mem_limit: 256m
    cpus: 0.5
    restart: unless-stopped
```

Understand restart policies:

```text
no
always
on-failure
unless-stopped
```

### Exercise

Create an application that exits with an error. Observe its behavior under:

```yaml
restart: "no"
```

and:

```yaml
restart: on-failure
```

---

## Day 24 — Docker troubleshooting methodology

Use this sequence:

### 1. Is the container present?

```bash
docker ps -a
```

### 2. What exit code did it return?

```bash
docker inspect container-name \
  --format '{{.State.ExitCode}}'
```

### 3. What do the logs say?

```bash
docker logs container-name
```

### 4. What configuration was actually applied?

```bash
docker inspect container-name
```

### 5. Is the application listening?

```bash
docker exec container-name ss -lntp
```

### 6. Can DNS resolve the dependency?

```bash
docker exec container-name getent hosts database
```

### 7. Can it connect?

```bash
docker exec container-name \
  nc -vz database 5432
```

### 8. Are mounts correct?

```bash
docker inspect container-name \
  --format '{{json .Mounts}}'
```

### Frequent mistakes

- Application listens on `127.0.0.1` instead of `0.0.0.0`
    
- Container uses `localhost` for another service
    
- Wrong host/container port mapping
    
- Bind-mounted directory hides files from the image
    
- Incorrect filesystem ownership
    
- Database starts later than the application
    
- Missing environment variable
    
- Wrong architecture
    
- Container process exits immediately
    
- Shell script lacks execute permission
    
- Windows line endings in Linux scripts
    

---

## Day 25 — Container security hardening

Apply these practical measures:

### Use trusted, maintained base images

```dockerfile
FROM debian:13-slim
```

instead of an unknown image with no clear provenance.

### Use explicit versions

Avoid relying entirely on:

```dockerfile
FROM application:latest
```

### Run as non-root

```dockerfile
USER appuser
```

### Remove unnecessary capabilities

```yaml
services:
  app:
    cap_drop:
      - ALL
```

Add only what is required:

```yaml
cap_add:
  - NET_BIND_SERVICE
```

### Use a read-only root filesystem

```yaml
services:
  app:
    read_only: true
    tmpfs:
      - /tmp
```

### Prevent privilege escalation

```yaml
security_opt:
  - no-new-privileges:true
```

### Avoid dangerous configurations

Do not casually use:

```yaml
privileged: true
```

Avoid mounting:

```text
/var/run/docker.sock
```

into application containers. Access to the Docker socket is effectively highly privileged access to the host’s Docker environment.

### Exercise

Harden your application until it runs with:

- Non-root user
    
- All capabilities dropped
    
- Read-only root filesystem
    
- Temporary writable `/tmp`
    
- No Docker socket
    
- No host networking
    
- No privileged mode
    

---

## Day 26 — Reverse proxies and HTTPS architecture

Build this architecture:

```text
Internet
   |
   v
Nginx or Traefik
   |
   +----> Web dashboard
   |
   +----> API
```

Only the reverse proxy publishes ports:

```yaml
services:
  proxy:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"

  dashboard:
    build: ./dashboard
    expose:
      - "8080"

  api:
    build: ./api
    expose:
      - "5000"
```

### Understand

- `ports` publishes access to the host
    
- `expose` documents or makes an internal container port available without publishing it to the host
    
- Internal services should remain unexposed unless outside access is required
    

### Exercise

Configure Nginx to route:

```text
/       → dashboard
/api/   → API
```

---

## Day 27 — Backups, upgrades, and rollback

### Database backup

PostgreSQL logical backup:

```bash
docker exec database \
  pg_dump -U appuser devices \
  > devices-backup.sql
```

Restore:

```bash
cat devices-backup.sql | \
docker exec -i database \
  psql -U appuser devices
```

### Upgrade sequence

1. Back up persistent data.
    
2. Pull or build the new image.
    
3. Test it separately.
    
4. Stop the old service.
    
5. Start the new version.
    
6. Verify health and logs.
    
7. Keep the previous image available for rollback.
    

Example:

```bash
docker compose pull
docker compose up -d
docker compose ps
docker compose logs --tail 100
```

### Never assume

A newer database image can safely use the old database files without reviewing the database’s upgrade procedure.

---

## Day 28 — Buildx and multi-platform images

Multi-platform builds let one build target combinations such as `linux/amd64` and `linux/arm64`. ([Docker Documentation](https://docs.docker.com/build/building/multi-platform/?utm_source=chatgpt.com "Multi-platform builds"))

Inspect available builders:

```bash
docker buildx ls
```

Create a builder:

```bash
docker buildx create \
  --name multiarch-builder \
  --use
```

Build:

```bash
docker buildx build \
  --platform linux/amd64,linux/arm64 \
  -t username/application:1.0 \
  --push \
  .
```

### Learn

This matters when deploying to:

- Intel/AMD servers
    
- ARM servers
    
- Raspberry Pi
    
- Apple Silicon
    
- Embedded Linux systems
    

### Exercise

Build your small C or Python service for:

```text
linux/amd64
linux/arm64
```

---

## Day 29 — Continuous integration and image publishing

A basic pipeline should:

1. Check out source code.
    
2. Run tests.
    
3. Build the image.
    
4. Tag it.
    
5. Scan or inspect it.
    
6. Authenticate to a registry.
    
7. Push the image.
    
8. Optionally deploy it.
    

Recommended tags:

```text
application:1.4.0
application:git-a84f2c1
application:main
```

Avoid using only:

```text
application:latest
```

Example conceptual GitLab CI job:

```yaml
build-image:
  stage: build
  image: docker:cli
  services:
    - docker:dind

  variables:
    DOCKER_TLS_CERTDIR: "/certs"

  script:
    - docker login -u "$CI_REGISTRY_USER" -p "$CI_REGISTRY_PASSWORD" "$CI_REGISTRY"
    - docker build -t "$CI_REGISTRY_IMAGE:$CI_COMMIT_SHA" .
    - docker push "$CI_REGISTRY_IMAGE:$CI_COMMIT_SHA"
```

For real environments, evaluate the security implications of Docker-in-Docker and use your CI platform’s recommended build mechanism.

### Exercise

Create an automated build that produces an immutable commit-based image tag.

---

## Day 30 — Final production deployment

Docker Compose supports single-host production deployment, including deployment to a remote Docker host. ([Docker Documentation](https://docs.docker.com/compose/how-tos/production/?utm_source=chatgpt.com "Use Compose in production"))

Your final system should resemble:

```text
                         Docker Host
┌──────────────────────────────────────────────────────┐
│                                                      │
│  ┌──────────────┐      ┌─────────────────────────┐  │
│  │ Nginx Proxy  │─────▶│ PHP/Python Dashboard    │  │
│  │ :80 / :443   │      └────────────┬────────────┘  │
│  └──────────────┘                   │               │
│                                     ▼               │
│                         ┌─────────────────────────┐  │
│                         │ PostgreSQL              │  │
│                         │ Persistent volume       │  │
│                         └─────────────────────────┘  │
│                                     ▲               │
│                                     │               │
│  ┌─────────────────────┐            │               │
│  │ MQTT Consumer       │────────────┘               │
│  └──────────┬──────────┘                            │
│             │                                       │
│             ▼                                       │
│  ┌─────────────────────┐                            │
│  │ Mosquitto Broker    │                            │
│  │ :1883 / :8883       │                            │
│  └─────────────────────┘                            │
│                                                      │
└──────────────────────────────────────────────────────┘
```

### Final requirements

Your project should include:

```text
mqtt-platform/
├── compose.yaml
├── compose.production.yaml
├── .env.example
├── .gitignore
├── README.md
├── nginx/
│   ├── Dockerfile
│   └── nginx.conf
├── dashboard/
│   ├── Dockerfile
│   ├── .dockerignore
│   └── src/
├── consumer/
│   ├── Dockerfile
│   └── src/
├── mosquitto/
│   └── config/
├── database/
│   └── init/
├── scripts/
│   ├── backup.sh
│   ├── restore.sh
│   └── deploy.sh
└── backups/
```

### Production validation

Run:

```bash
docker compose config
docker compose build
docker compose up -d
docker compose ps
docker compose logs --tail 100
docker stats
```

Test:

- Dashboard availability
    
- API availability
    
- MQTT connection
    
- MQTT consumer processing
    
- Database writes
    
- Container restart behavior
    
- Host reboot recovery
    
- Failed database connection recovery
    
- Backup and restore
    
- Upgrade and rollback
    
- Health checks
    
- Permission boundaries
    
- Removal and recreation of containers
    
- Persistence after container replacement
    

---

# Essential Docker command map

## Containers

```bash
docker run
docker create
docker start
docker stop
docker restart
docker kill
docker rm
docker ps
docker logs
docker exec
docker inspect
docker stats
docker top
```

## Images

```bash
docker pull
docker build
docker image ls
docker image inspect
docker image history
docker image rm
docker image prune
docker tag
docker push
```

## Volumes

```bash
docker volume create
docker volume ls
docker volume inspect
docker volume rm
docker volume prune
```

## Networks

```bash
docker network create
docker network ls
docker network inspect
docker network connect
docker network disconnect
docker network rm
```

## Compose

```bash
docker compose config
docker compose build
docker compose pull
docker compose up
docker compose down
docker compose ps
docker compose logs
docker compose exec
docker compose run
docker compose restart
```

## Cleanup

```bash
docker container prune
docker image prune
docker volume prune
docker network prune
docker system df
docker system prune
```

Use destructive cleanup commands carefully, especially when volumes contain database data.

---

# Skills you should have after 30 days

At the end of the program, you should be able to:

- Explain how containers differ from virtual machines
    
- Build and debug Docker images
    
- Write maintainable Dockerfiles
    
- Optimize build cache
    
- Use multi-stage builds
    
- Work with bind mounts and named volumes
    
- Design container networks
    
- Use Docker Compose for multi-container applications
    
- Containerize PHP, Python, and C applications
    
- Run databases safely with persistent storage
    
- Configure health checks and restart policies
    
- Troubleshoot startup, networking, DNS, storage, and permissions
    
- Run services as non-root
    
- Reduce container privileges
    
- Back up and restore persistent data
    
- Publish versioned images to registries
    
- Build images for AMD64 and ARM64
    
- Deploy a production-style stack to a Linux server
    
- Design an upgrade and rollback process
    

The primary objective is not to memorize Docker commands. It is to learn how to turn an application into a **reproducible, disposable, observable, secure, and deployable runtime unit**.