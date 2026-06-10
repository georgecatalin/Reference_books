#### Advanced Docker Compose: Development, Production, Overrides, Profiles, and Scaling

Day 13 introduced a single `compose.yaml` that defined:

- A Python API
    
- PostgreSQL
    
- A named volume
    
- Networks
    
- Environment variables
    
- Health checks
    
- Service dependencies
    

Today you will make that project usable in different situations:

```text
Development
Testing
Production
Administration
Debugging
```

The central lesson is:

> Keep one stable base Compose definition, then add environment-specific behavior through override files, profiles, variables, and development tooling.

Docker Compose can combine multiple configuration files into one application model. Later files add to or override earlier files, while `docker compose config` shows the final resolved result. ([Docker Documentation](https://docs.docker.com/compose/how-tos/multiple-compose-files/merge/?utm_source=chatgpt.com "Merge Compose files"))

---

# 1. Day 14 objectives

By the end of today, you should understand:

- Why development and production should not use identical configuration
    
- How Compose merges multiple files
    
- How `compose.override.yaml` works
    
- How to use explicit `-f` file combinations
    
- How to inspect the final merged configuration
    
- How environment interpolation differs from container environment variables
    
- How environment-variable precedence works
    
- How to use Compose profiles
    
- How to add development-only and administration services
    
- How Compose Watch updates containers when files change
    
- How bind mounts compare with Compose Watch
    
- How to scale stateless services
    
- Why fixed host ports prevent simple scaling
    
- Why databases should normally not be scaled with `--scale`
    
- How to update one service without recreating its dependencies
    
- How to prepare a more production-oriented Compose configuration
    

---

# 2. The problem with one Compose file

Your Day 13 file likely contains settings such as:

```yaml
services:
  api:
    build:
      context: .
    ports:
      - "8080:5000"
    environment:
      APP_ENV: development
      LOG_LEVEL: INFO
```

This works, but development and production have different needs.

## Development usually needs

- Local source synchronization
    
- Debug logging
    
- Fast rebuilds
    
- Convenient host ports
    
- Optional database administration tools
    
- Access to internal services for debugging
    

## Production usually needs

- Prebuilt versioned images
    
- No source bind mounts
    
- Fewer published ports
    
- Strong restart behavior
    
- Resource constraints
    
- Stable configuration
    
- No debug tools
    
- No development server
    
- External secret management
    
- Predictable image versions
    

Trying to place all of these choices into one service definition can make the file difficult to understand.

---

# 3. Recommended project structure

Create a Day 14 copy of your project:

```bash
mkdir -p ~/docker-course/day14
cp -r ~/docker-course/day12/device-api \
  ~/docker-course/day14/device-api

cd ~/docker-course/day14/device-api
```

Use this structure:

```text
device-api/
├── compose.yaml
├── compose.override.yaml
├── compose.production.yaml
├── compose.tools.yaml
├── Dockerfile
├── Dockerfile.dev
├── .dockerignore
├── .env.example
├── requirements.txt
├── app.py
└── templates/
    └── index.html
```

The role of each file will be:

```text
compose.yaml
→ common application definition

compose.override.yaml
→ automatic local-development changes

compose.production.yaml
→ production-oriented overrides

compose.tools.yaml
→ optional administration services
```

---

# 4. Base Compose file

Replace `compose.yaml` with a neutral base definition:

```yaml
services:
  api:
    image: device-api:${APP_IMAGE_TAG:-1.0.0}
    environment:
      APP_ENV: "${APP_ENV:-production}"
      APP_VERSION: "${APP_VERSION:-1.0.0}"
      LOG_LEVEL: "${LOG_LEVEL:-INFO}"

      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: "${DB_NAME:-device_monitor}"
      DB_USER: "${DB_USER:-device_app}"
      DB_PASSWORD: "${DB_PASSWORD:?DB_PASSWORD must be set}"
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
      start_period: 15s
    restart: unless-stopped
    networks:
      - frontend
      - backend

  database:
    image: postgres:17
    environment:
      POSTGRES_USER: "${DB_USER:-device_app}"
      POSTGRES_PASSWORD: "${DB_PASSWORD:?DB_PASSWORD must be set}"
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

This file deliberately does not contain:

- An API build definition
    
- A published API port
    
- Development bind mounts
    
- Development logging
    
- Administration services
    

Those belong in environment-specific files.

---

# 5. Why the base file should be neutral

A strong base file describes what is common everywhere:

```text
API needs PostgreSQL
API uses port 5000 internally
PostgreSQL needs persistent storage
Both services need health checks
Database remains on the backend network
```

It should avoid assumptions that are only true locally:

```text
Host port must be 8080
Source code is mounted from this workstation
Debug logging should be enabled
The image must be built on every machine
```

The base file becomes the stable architecture.

Override files express how that architecture runs in a particular environment.

---

# 6. Create the development Dockerfile

Create `Dockerfile.dev`:

```dockerfile
FROM python:3.13-slim

RUN groupadd --system appgroup \
    && useradd \
        --system \
        --gid appgroup \
        --home-dir /app \
        appuser

WORKDIR /app

COPY requirements.txt .

RUN pip install \
    --no-cache-dir \
    --disable-pip-version-check \
    -r requirements.txt

ENV PYTHONUNBUFFERED=1
ENV FLASK_APP=app.py
ENV FLASK_DEBUG=1

COPY --chown=appuser:appgroup . .

USER appuser

CMD [
    "flask",
    "--app",
    "app",
    "run",
    "--host=0.0.0.0",
    "--port=5000",
    "--debug"
]
```

This development image uses Flask’s reload behavior.

The production Dockerfile should continue using Gunicorn.

---

# 7. Create `compose.override.yaml`

Compose automatically reads:

```text
compose.yaml
compose.override.yaml
```

when both exist in the working directory. The base file normally contains shared configuration, while the override adds or changes development settings. ([Docker Documentation](https://docs.docker.com/compose/how-tos/multiple-compose-files/merge/?utm_source=chatgpt.com "Merge Compose files"))

Create:

```bash
nano compose.override.yaml
```

Add:

```yaml
services:
  api:
    build:
      context: .
      dockerfile: Dockerfile.dev
    image: device-api:development
    ports:
      - "${API_HOST_PORT:-8080}:5000"
    environment:
      APP_ENV: development
      APP_VERSION: development
      LOG_LEVEL: DEBUG
    develop:
      watch:
        - action: sync
          path: .
          target: /app
          ignore:
            - .git/
            - __pycache__/
            - "*.pyc"
            - .env
            - compose*.yaml
        - action: rebuild
          path: requirements.txt
    restart: "no"

  database:
    ports:
      - "127.0.0.1:${DB_HOST_PORT:-5432}:5432"
```

This development override:

- Builds from `Dockerfile.dev`
    
- Publishes the API
    
- Enables debug settings
    
- Adds Compose Watch
    
- Publishes PostgreSQL only on localhost
    
- Disables automatic restart for easier debugging
    

---

# 8. Validate the automatic merge

Run:

```bash
docker compose config
```

Compose should combine:

```text
compose.yaml
+
compose.override.yaml
```

into one resolved model.

Check the service names:

```bash
docker compose config --services
```

Expected:

```text
api
database
```

Inspect only the API section:

```bash
docker compose config \
  | sed -n '/^  api:/,/^  database:/p'
```

You should see properties originating from both files:

```text
From compose.yaml:
- database configuration
- dependencies
- health check
- networks

From compose.override.yaml:
- build
- development image
- port
- watch configuration
- debug environment
```

---

# 9. How Compose merging works

Compose does not simply replace the complete service when an override file contains the same service name.

It combines fields according to merge rules.

For simple values:

```yaml
# Base
restart: unless-stopped
```

```yaml
# Override
restart: "no"
```

The later file wins.

For mappings:

```yaml
# Base
environment:
  DB_HOST: database
  LOG_LEVEL: INFO
```

```yaml
# Override
environment:
  LOG_LEVEL: DEBUG
```

The merged result is:

```yaml
environment:
  DB_HOST: database
  LOG_LEVEL: DEBUG
```

For many sequence values, entries may be appended rather than completely replaced. Compose has specific exceptions and merge rules for commands, ports, volumes, and other resources, so always inspect the result with `docker compose config`. ([Docker Documentation](https://docs.docker.com/reference/compose-file/merge/?utm_source=chatgpt.com "Merge Compose files"))

---

# 10. Explicitly selecting Compose files

Instead of relying on the automatic override filename, you can supply files explicitly:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  up -d
```

The order matters:

```text
First file
→ base

Second file
→ additions and overrides

Third file
→ further additions and overrides
```

Later files have higher precedence for overridden values. ([Docker Documentation](https://docs.docker.com/compose/how-tos/multiple-compose-files/?utm_source=chatgpt.com "Use multiple Compose files"))

To inspect the result without starting anything:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  config
```

This is essential before applying a production combination.

---

# 11. Relative path rule

When multiple Compose files are merged, relative paths are resolved relative to the base Compose file, normally the first file supplied.

Suppose:

```text
project/
├── compose.yaml
└── environments/
    └── compose.production.yaml
```

If the production file contains:

```yaml
build:
  context: .
```

the path is interpreted relative to the base Compose project directory, not necessarily the override file’s own directory. Docker documents this behavior to keep merged-file path handling consistent. ([Docker Documentation](https://docs.docker.com/compose/how-tos/multiple-compose-files/extends/?utm_source=chatgpt.com "Extend | Docker Docs"))

Always verify paths with:

```bash
docker compose \
  -f compose.yaml \
  -f environments/compose.production.yaml \
  config
```

---

# 12. Start development mode

Create a development `.env`:

```bash
cat > .env <<'EOF'
API_HOST_PORT=8080
DB_HOST_PORT=5432

APP_ENV=development
APP_VERSION=development
LOG_LEVEL=DEBUG

DB_NAME=device_monitor
DB_USER=device_app
DB_PASSWORD=development-password
EOF
```

Protect it:

```bash
chmod 600 .env
```

Start:

```bash
docker compose up -d --build
```

Because `compose.override.yaml` is loaded automatically, the API uses:

- `Dockerfile.dev`
    
- Port 8080
    
- Debug settings
    
- Development image
    
- Watch configuration
    

Check:

```bash
docker compose ps
```

Test:

```bash
curl http://localhost:8080/health
```

---

# 13. Compose Watch

Compose Watch monitors files and performs actions such as:

- Synchronizing changed files
    
- Rebuilding the service image
    
- Restarting or recreating services as needed
    

It is configured in the `develop.watch` section and can be launched with:

```bash
docker compose up --watch
```

or:

```bash
docker compose watch
```

Docker documents both commands; the dedicated `watch` form separates watch events from normal application logs more cleanly. ([Docker Documentation](https://docs.docker.com/compose/how-tos/file-watch/?utm_source=chatgpt.com "Use Compose Watch"))

Run:

```bash
docker compose watch
```

In another terminal, edit:

```bash
nano app.py
```

Change a visible value or response.

The source should synchronize into `/app`.

Test the application again:

```bash
curl http://localhost:8080/api/config
```

---

# 14. Watch actions

A Compose Watch rule can use different actions.

## `sync`

```yaml
develop:
  watch:
    - action: sync
      path: .
      target: /app
```

Changes are copied into the running container.

Use for:

- Python source
    
- Templates
    
- Static files
    
- Configuration that the application reloads
    

## `rebuild`

```yaml
- action: rebuild
  path: requirements.txt
```

When the dependency file changes, Compose rebuilds the image and recreates the service.

Use when a changed file affects image construction:

- `requirements.txt`
    
- `package.json`
    
- Compiler configuration
    
- OS package lists
    

The container must contain any directories used as synchronization targets, and the service must have the required file permissions.

---

# 15. Compose Watch versus bind mounts

Both support fast development, but they work differently.

## Bind mount

```yaml
volumes:
  - .:/app
```

The container accesses host files directly.

Advantages:

- Simple
    
- Immediate file visibility
    
- Familiar
    

Disadvantages:

- Host filesystem tightly coupled to the container
    
- Permission problems
    
- Performance issues on some desktop platforms
    
- May hide image content
    
- Host-specific generated directories can leak into the container
    

## Compose Watch

```yaml
develop:
  watch:
    - action: sync
      path: .
      target: /app
```

Compose copies relevant changes into the container.

Advantages:

- Explicit synchronization rules
    
- Can ignore unnecessary files
    
- Can rebuild only when required
    
- Reduces some host/container filesystem differences
    

Disadvantages:

- Requires a sufficiently recent Compose release
    
- More configuration
    
- Application still needs reload behavior
    

Compose Watch is specifically intended to improve containerized development workflows by syncing or rebuilding services when local files change. ([Docker Documentation](https://docs.docker.com/compose/how-tos/file-watch/?utm_source=chatgpt.com "Use Compose Watch"))

---

# 16. Development bind-mount alternative

You could replace the Watch section with:

```yaml
services:
  api:
    volumes:
      - type: bind
        source: .
        target: /app
```

However, this mount would hide everything originally stored at `/app` in the image.

In your development image, Python packages are installed globally, so the application can still work.

But if dependencies or runtime files were installed under `/app`, the bind mount could hide them.

A safer structure is sometimes:

```text
/app/runtime
/app/source
```

with only source mounted:

```yaml
volumes:
  - .:/app/source
```

---

# 17. Create a production override

Create:

```bash
nano compose.production.yaml
```

Add:

```yaml
services:
  api:
    image: "${REGISTRY_IMAGE:-device-api}:${APP_IMAGE_TAG:-1.0.0}"
    ports:
      - "127.0.0.1:${API_HOST_PORT:-8080}:5000"
    environment:
      APP_ENV: production
      LOG_LEVEL: INFO
    restart: unless-stopped
    read_only: true
    tmpfs:
      - /tmp
    security_opt:
      - no-new-privileges:true

  database:
    restart: unless-stopped
    security_opt:
      - no-new-privileges:true
```

Notice that this file does not contain:

```yaml
build:
```

The production deployment expects a previously built, tested, versioned image.

---

# 18. Production Compose combination

Because `compose.override.yaml` is loaded automatically only when using the default command, explicitly provide the files for production:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  config
```

Inspect carefully.

Then start:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  up -d
```

Docker’s production guidance recommends using an additional Compose file for production-specific changes and combining it with `-f`. ([Docker Documentation](https://docs.docker.com/compose/how-tos/production/?utm_source=chatgpt.com "Use Compose in production"))

---

# 19. Why bind the production API to localhost?

The production override uses:

```yaml
ports:
  - "127.0.0.1:8080:5000"
```

This exposes the API only on the Docker host’s loopback interface.

It is useful when a reverse proxy on the host handles:

- TLS
    
- Public ports 80 and 443
    
- Domain routing
    
- Access logs
    
- Security headers
    

The service would be reachable locally through:

```text
http://127.0.0.1:8080
```

but not directly through every host network interface.

For a direct LAN-facing deployment, the binding could instead be:

```yaml
ports:
  - "8080:5000"
```

That choice must be deliberate.

---

# 20. Read-only root filesystem

The production API uses:

```yaml
read_only: true
```

This prevents writes to the container’s normal root filesystem.

The application can still write to explicitly writable locations such as:

- Volumes
    
- Bind mounts
    
- `tmpfs`
    

The override provides:

```yaml
tmpfs:
  - /tmp
```

so libraries can still create temporary files.

This helps reveal hidden assumptions such as:

```text
Application writes logs into /app/logs
Application creates caches inside its source directory
Application modifies packaged configuration
```

Container-friendly applications should normally:

- Log to standard output
    
- Keep source immutable
    
- Use explicit persistent storage
    
- Use `/tmp` for disposable temporary data
    

---

# 21. No-new-privileges

The production override includes:

```yaml
security_opt:
  - no-new-privileges:true
```

This prevents processes from gaining additional privileges through mechanisms such as set-user-ID executables.

It does not make the container completely secure, but it strengthens least-privilege behavior.

Use it together with:

- A non-root `USER`
    
- Minimal Linux capabilities
    
- Read-only root filesystems where possible
    
- Limited mounts
    
- Restricted networks
    
- Trusted images
    

---

# 22. Environment interpolation

Compose supports expressions such as:

```yaml
image: "${REGISTRY_IMAGE:-device-api}:${APP_IMAGE_TAG:-1.0.0}"
```

Common forms include:

```text
${VARIABLE}
```

Use the variable value.

```text
${VARIABLE:-default}
```

Use the default when the variable is unset or empty.

```text
${VARIABLE-default}
```

Use the default when the variable is unset.

```text
${VARIABLE:?error message}
```

Fail when the variable is missing or empty.

```text
${VARIABLE?error message}
```

Fail when the variable is unset.

Compose uses shell-like interpolation syntax for values in Compose files. ([Docker Documentation](https://docs.docker.com/reference/compose-file/interpolation/?utm_source=chatgpt.com "Interpolation"))

Your database password uses:

```yaml
DB_PASSWORD: "${DB_PASSWORD:?DB_PASSWORD must be set}"
```

This prevents accidentally starting the project without a password value.

---

# 23. Project `.env` file

A project `.env` file is primarily used to provide values for Compose interpolation.

Example:

```text
APP_IMAGE_TAG=1.0.0
API_HOST_PORT=8080
DB_PASSWORD=development-password
```

Then:

```yaml
image: "device-api:${APP_IMAGE_TAG}"
```

Compose substitutes the value before creating containers.

Docker describes the project `.env` file as a central source of values used when Compose resolves the configuration. ([Docker Documentation](https://docs.docker.com/compose/how-tos/environment-variables/variable-interpolation/?utm_source=chatgpt.com "Set, use, and manage variables in a Compose file with ..."))

Inspect substituted values:

```bash
docker compose config
```

Be careful: the resolved output may reveal passwords.

---

# 24. `env_file` for container variables

A service can receive variables from a file:

```yaml
services:
  api:
    env_file:
      - api.env
```

Create:

```bash
cat > api.env <<'EOF'
APP_ENV=development
APP_VERSION=1.0.0
LOG_LEVEL=DEBUG
EOF
```

These variables are passed into the container.

Docker Compose supports `environment` and `env_file` for setting a service container’s environment. Docker advises against using ordinary environment variables for sensitive information and recommends secret-specific mechanisms instead. ([Docker Documentation](https://docs.docker.com/compose/how-tos/environment-variables/set-environment-variables/?utm_source=chatgpt.com "Set environment variables within your container's ..."))

---

# 25. `.env` versus `env_file`

## Project `.env`

Used while Compose interprets the YAML:

```yaml
ports:
  - "${API_HOST_PORT}:5000"
```

## Service `env_file`

Used to populate the container’s environment:

```yaml
env_file:
  - api.env
```