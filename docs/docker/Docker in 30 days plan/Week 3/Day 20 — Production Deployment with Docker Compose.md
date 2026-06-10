

Days 18 and 19 created a trustworthy release artifact:

```text
Committed source
    ↓
CI pipeline
    ↓
Tests and scanning
    ↓
Versioned container image
    ↓
Container registry
```

Today you will deploy that image to a Linux server and operate it safely.

The central lesson is:

> Production deployment means changing a running system from one known, tested state to another while preserving data, limiting downtime, verifying health, and retaining a clear rollback path.

Docker Compose is suitable for deploying a multi-container application on one Docker host. Docker’s production guidance recommends using production-specific Compose configuration and pulling prebuilt images rather than rebuilding application images independently on the deployment server. ([Docker Documentation](https://docs.docker.com/compose/how-tos/production/?utm_source=chatgpt.com "Use Compose in production"))

---

## 1. Day 20 objectives

By the end of today, you should understand:

- Development versus production deployment
    
- Preparing a Linux Docker host
    
- Production filesystem layout
    
- Deploying prebuilt registry images
    
- Base and production Compose files
    
- External configuration and secrets
    
- Persistent volume planning
    
- Database backup before deployment
    
- Pulling and inspecting a release
    
- Updating one service without replacing dependencies
    
- Health verification
    
- Rollback
    
- Restart policies
    
- Docker daemon startup
    
- Logging and disk-space protection
    
- Server reboot testing
    
- Deployment records
    
- Why Compose updates are not automatically zero-downtime
    
- Blue-green deployment fundamentals
    
- A practical production runbook
    

---

# 2. The production architecture

You will deploy:

```text
                       Linux production server

External browser
       │
       │ HTTPS or local reverse proxy
       ▼
┌──────────────────────────────┐
│ Device API                   │
│ Registry image               │
│ 127.0.0.1:8080 → 5000        │
└──────────────┬───────────────┘
               │ backend network
               ▼
┌──────────────────────────────┐
│ PostgreSQL                   │
│ No published host port       │
└──────────────┬───────────────┘
               │
               ▼
      Named persistent volume
```

The API image comes from your registry:

```text
registry.example.com/team/device-api:1.0.0
```

The database uses a trusted PostgreSQL image and persistent volume.

---

# 3. Development and production are different

Your development configuration may contain:

```yaml
services:
  api:
    build:
      context: .
    ports:
      - "8080:5000"
    environment:
      LOG_LEVEL: DEBUG
    develop:
      watch:
        - action: sync
          path: .
          target: /app
```

Production should not normally contain:

- Source-code synchronization
    
- Debug mode
    
- Local bind-mounted source
    
- Development servers
    
- Test tools
    
- Automatically rebuilt images
    
- Database ports exposed to the LAN
    
- Hard-coded passwords
    
- Unversioned application images
    

Production should normally use:

```yaml
services:
  api:
    image: registry.example.com/team/device-api:1.0.0
```

not:

```yaml
build:
  context: .
```

Build once in CI and deploy the same tested artifact.

---

# 4. Prepare a dedicated deployment server

Do not use your laptop as the production runtime.

A basic single-host deployment server should have:

- A maintained Linux distribution
    
- Docker Engine from an approved source
    
- Docker Compose plugin
    
- Time synchronization
    
- Sufficient CPU, memory, and disk
    
- Firewall configuration
    
- Backup destination
    
- Restricted SSH access
    
- Monitoring
    
- Regular operating-system updates
    

Verify:

```bash
docker version
docker compose version
```

Check Docker:

```bash
sudo systemctl status docker
```

Enable Docker at boot:

```bash
sudo systemctl enable docker.service
sudo systemctl enable containerd.service
```

Docker’s Linux post-installation guidance documents enabling Docker and containerd through systemd so that the engine starts with the host. ([Docker Documentation](https://docs.docker.com/engine/install/linux-postinstall/?utm_source=chatgpt.com "Linux post-installation steps for Docker Engine"))

---

# 5. Docker access is privileged access

Check who belongs to the Docker group:

```bash
getent group docker
```

Membership in this group should be restricted.

A user who can control Docker can usually:

- Start privileged containers
    
- Mount host directories
    
- Read container configuration
    
- Access volumes
    
- Stop applications
    
- Replace images
    

Use a dedicated deployment account rather than sharing personal credentials.

Example:

```text
deploy
```

That account may need Docker access, but it should not automatically have broad unrelated server privileges.

---

# 6. Production directory layout

Create a stable location:

```bash
sudo mkdir -p /opt/device-api
sudo chown deploy:deploy /opt/device-api
```

Recommended structure:

```text
/opt/device-api/
├── compose.yaml
├── compose.production.yaml
├── .env
├── secrets/
│   └── database-password.txt
├── backups/
├── releases/
│   └── release-manifest.txt
└── scripts/
    ├── deploy.sh
    ├── verify.sh
    ├── backup.sh
    └── rollback.sh
```

Application source code does not need to be present when the server only pulls prebuilt images.

---

# 7. Base Compose file

Create `/opt/device-api/compose.yaml`:

```yaml
services:
  api:
    image: "${API_IMAGE:?API_IMAGE must be defined}"

    environment:
      APP_ENV: production
      APP_VERSION: "${APP_VERSION:-unknown}"
      LOG_LEVEL: INFO

      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: "${DB_NAME:-device_monitor}"
      DB_USER: "${DB_USER:-device_app}"
      DB_PASSWORD_FILE: /run/secrets/database-password

    secrets:
      - database-password

    depends_on:
      database:
        condition: service_healthy
        restart: true

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
      start_period: 20s

    restart: unless-stopped

    networks:
      - frontend
      - backend

  database:
    image: "${POSTGRES_IMAGE:-postgres:17}"

    environment:
      POSTGRES_USER: "${DB_USER:-device_app}"
      POSTGRES_DB: "${DB_NAME:-device_monitor}"
      POSTGRES_PASSWORD_FILE: /run/secrets/database-password

    secrets:
      - database-password

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
      retries: 12
      start_period: 15s

    restart: unless-stopped
    stop_grace_period: 60s

    networks:
      - backend

volumes:
  postgres-data:

networks:
  frontend:

  backend:
    internal: true

secrets:
  database-password:
    file: ./secrets/database-password.txt
```

Compose supports dependency ordering and can wait for a dependency marked `service_healthy`; the application should still retain its own retry logic for runtime outages. ([Docker Documentation](https://docs.docker.com/compose/how-tos/startup-order/?utm_source=chatgpt.com "Control startup and shutdown order in Compose"))

---

# 8. Production override

Create `compose.production.yaml`:

```yaml
services:
  api:
    ports:
      - "127.0.0.1:${API_HOST_PORT:-8080}:5000"

    read_only: true

    tmpfs:
      - /tmp:size=64m,mode=1777

    cap_drop:
      - ALL

    security_opt:
      - no-new-privileges:true

    mem_limit: 512m
    cpus: 1.0
    pids_limit: 200

    stop_grace_period: 30s

    logging:
      driver: local
      options:
        max-size: "10m"
        max-file: "3"

  database:
    security_opt:
      - no-new-privileges:true

    mem_limit: 1g
    cpus: 2.0
    pids_limit: 300

    logging:
      driver: local
      options:
        max-size: "20m"
        max-file: "5"
```

Multiple Compose files are merged in command-line order. Later files modify or add to the base model, while relative paths are resolved from the first Compose file. Always inspect the merged result before applying it. ([Docker Documentation](https://docs.docker.com/compose/how-tos/multiple-compose-files/merge/?utm_source=chatgpt.com "Merge Compose files"))

---

# 9. Why bind the API to localhost?

This mapping:

```yaml
ports:
  - "127.0.0.1:8080:5000"
```

means the API is reachable only through the server’s loopback interface:

```text
127.0.0.1:8080
```

It is appropriate when:

- Nginx or another reverse proxy runs on the host
    
- TLS terminates at the proxy
    
- The API should not be exposed directly to the LAN
    
- Local monitoring needs access
    

Without a reverse proxy, you may deliberately expose:

```yaml
ports:
  - "8080:5000"
```

But that should be an explicit network-security decision.

---

# 10. Create production secrets

Create:

```bash
mkdir -p /opt/device-api/secrets
```

Generate a strong password:

```bash
openssl rand -base64 36 \
  > /opt/device-api/secrets/database-password.txt
```

Restrict it:

```bash
chmod 600 /opt/device-api/secrets/database-password.txt
```

Do not:

- Commit it to Git
    
- Put it in the image
    
- Print it in logs
    
- Store it inside `compose.yaml`
    
- Include it in public support output
    

The application reads:

```text
/run/secrets/database-password
```

rather than receiving the raw value through an ordinary environment variable.

---

# 11. Create the production `.env`

Create `/opt/device-api/.env`:

```text
API_IMAGE=registry.example.com/team/device-api:1.0.0
APP_VERSION=1.0.0
API_HOST_PORT=8080

POSTGRES_IMAGE=postgres:17

DB_NAME=device_monitor
DB_USER=device_app
```

Protect it:

```bash
chmod 600 /opt/device-api/.env
```

The `.env` file contains deployment configuration but should still be treated as operationally sensitive.

The database password is not stored there.

---

# 12. Validate before deployment

Move to the deployment directory:

```bash
cd /opt/device-api
```

Render the production configuration:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  config
```

Check services:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  config --services
```

Check images:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  config --images
```

Expected:

```text
registry.example.com/team/device-api:1.0.0
postgres:17
```

Do not deploy before `docker compose config` succeeds.

---

# 13. Review the resolved configuration

Confirm:

```text
API:
- Exact versioned image
- Localhost port binding
- Non-debug environment
- Database secret file
- Read-only filesystem
- Health check
- Resource limits

Database:
- No published host port
- Named data volume
- Health check
- Backend-only network
- Graceful stop period
```

Also check that there is no unexpected:

```text
privileged: true
network_mode: host
pid: host
Docker socket mount
host root filesystem mount
```

Compose files are trusted input: Docker applies requested privileges and host access as written, so production Compose changes require code review. ([Docker Documentation](https://docs.docker.com/compose/trust-model/?utm_source=chatgpt.com "Trust model for Compose files"))

---

# 14. Authenticate to the registry

On the server:

```bash
printf '%s' "$REGISTRY_TOKEN" \
  | docker login registry.example.com \
      --username "$REGISTRY_USER" \
      --password-stdin
```

Use a pull-only deployment token where possible.

The production server normally needs:

```text
Read registry
```

It does not need:

```text
Push registry
Delete images
Manage repositories
```

Apply least privilege.

---

# 15. Pull images before changing the running stack

Run:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  pull
```

This downloads images without starting or replacing containers.

Why pull first?

- Authentication failures are detected early
    
- Registry problems are detected early
    
- Disk-space problems are detected early
    
- Image availability is confirmed
    
- The currently running application remains unchanged during the pull
    

Inspect:

```bash
docker image inspect \
  registry.example.com/team/device-api:1.0.0 \
  --format 'ID={{.Id}} Digests={{json .RepoDigests}}'
```

Record the digest.

---

# 16. First deployment

Run:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  up -d
```

Compose creates:

- API container
    
- PostgreSQL container
    
- Frontend network
    
- Internal backend network
    
- PostgreSQL volume
    
- Secret mounts
    

`docker compose up` reconciles the running project with the Compose model, creating or recreating services as required. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/compose/up/?utm_source=chatgpt.com "docker compose up"))

Check:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  ps
```

---

# 17. Follow startup logs

Run:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  logs -f
```

You should observe:

```text
Database initializes
Database health becomes healthy
API starts
API connects to database
API health becomes healthy
```

Stop log following with `Ctrl+C`.

This does not stop the services.

---

# 18. Verify application health

From the server:

```bash
curl --fail \
  http://127.0.0.1:8080/health
```

Use retries during deployment:

```bash
curl --fail \
  --retry 15 \
  --retry-delay 2 \
  --retry-connrefused \
  http://127.0.0.1:8080/health
```

Expected conceptually:

```json
{
  "status": "healthy",
  "database": "connected"
}
```

Also inspect Docker health:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  ps
```

Both services should eventually be healthy.

---

# 19. Verify the deployed image

Find the API container:

```bash
API_CONTAINER="$(
  docker compose \
    -f compose.yaml \
    -f compose.production.yaml \
    ps -q api
)"
```

Inspect the configured reference:

```bash
docker inspect "$API_CONTAINER" \
  --format 'ConfiguredImage={{.Config.Image}}'
```

Inspect the image ID:

```bash
docker inspect "$API_CONTAINER" \
  --format 'ImageID={{.Image}}'
```

Compare it with:

```bash
docker image inspect "$API_IMAGE" \
  --format '{{.Id}}'
```

This confirms which image actually runs.

---

# 20. Create a deployment manifest

Create:

```bash
mkdir -p releases
```

Record the release:

```bash
cat > releases/current-release.txt <<EOF
deployment_time=$(date -u +%Y-%m-%dT%H:%M:%SZ)
api_image=$API_IMAGE
api_image_id=$(docker image inspect "$API_IMAGE" --format '{{.Id}}')
api_digest=$(docker image inspect "$API_IMAGE" --format '{{index .RepoDigests 0}}')
postgres_image=$POSTGRES_IMAGE
compose_project=$(basename "$PWD")
deployed_by=$(whoami)
server=$(hostname -f)
EOF
```

View:

```bash
cat releases/current-release.txt
```

Keep the previous manifest before every deployment:

```bash
cp releases/current-release.txt \
   releases/previous-release.txt
```

---

# 21. Confirm data persistence

Add a record through the API.

Then inspect the volume:

```bash
docker volume ls
```

Find the project volume:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  config --volumes
```

Inspect the database container mounts:

```bash
docker inspect \
  "$(docker compose \
      -f compose.yaml \
      -f compose.production.yaml \
      ps -q database)" \
  --format '{{json .Mounts}}'
```

Docker volumes persist independently from container removal and are managed through Docker rather than through direct manipulation of their internal host paths. ([Docker Documentation](https://docs.docker.com/engine/storage/volumes/?utm_source=chatgpt.com "Volumes | Docker Docs"))

---

# 22. Persistence is not backup

A named volume protects data against:

```text
Container replacement
Container recreation
Image update
```

It does not protect against:

```text
Accidental deletion
docker compose down -v
Database corruption
Bad application migration
Disk failure
VM deletion
Ransomware
Host loss
```

The PostgreSQL volume must be backed up separately.

Docker image export also does not include mounted volume data. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/container/export/?utm_source=chatgpt.com "docker container export"))

---

# 23. Logical PostgreSQL backup

Create a backup directory:

```bash
mkdir -p /opt/device-api/backups
chmod 700 /opt/device-api/backups
```

Create a database dump:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  exec -T database \
  pg_dump \
    -U device_app \
    -d device_monitor \
    --format=custom \
  > "backups/device-monitor-$(date -u +%Y%m%dT%H%M%SZ).dump"
```

Check:

```bash
ls -lh backups/
```

The custom PostgreSQL dump format is restored with `pg_restore`.

For important deployments, store another copy outside the Docker host.

---

# 24. Verify the backup

Listing a backup file is not enough.

Inspect it:

```bash
pg_restore --list \
  backups/device-monitor-TIMESTAMP.dump \
  | head
```

If `pg_restore` is unavailable on the host, use a temporary PostgreSQL container:

```bash
docker run --rm \
  --mount type=bind,source="$PWD/backups",target=/backup,readonly \
  postgres:17 \
  pg_restore --list /backup/device-monitor-TIMESTAMP.dump
```

A backup is trustworthy only after you have demonstrated that it can be restored.

---

# 25. Create a deployment script

Create `scripts/deploy.sh`:

```bash
#!/usr/bin/env bash

set -Eeuo pipefail

COMPOSE_FILES=(
  -f compose.yaml
  -f compose.production.yaml
)

EXPECTED_IMAGE="${1:?Usage: deploy.sh IMAGE_REFERENCE}"

export API_IMAGE="$EXPECTED_IMAGE"

echo "Validating Compose configuration..."
docker compose "${COMPOSE_FILES[@]}" config --quiet

echo "Pulling release image..."
docker compose "${COMPOSE_FILES[@]}" pull api

echo "Backing up database..."
mkdir -p backups

docker compose "${COMPOSE_FILES[@]}" \
  exec -T database \
  pg_dump \
    -U "${DB_USER:-device_app}" \
    -d "${DB_NAME:-device_monitor}" \
    --format=custom \
  > "backups/pre-deploy-$(date -u +%Y%m%dT%H%M%SZ).dump"

echo "Updating API service..."
docker compose "${COMPOSE_FILES[@]}" \
  up -d \
  --no-deps \
  api

echo "Waiting for health..."
for attempt in $(seq 1 30); do
  if curl --fail --silent \
    http://127.0.0.1:${API_HOST_PORT:-8080}/health \
    >/dev/null; then

    echo "Deployment healthy."
    exit 0
  fi

  sleep 2
done

echo "Deployment failed health verification." >&2
docker compose "${COMPOSE_FILES[@]}" logs --tail 100 api >&2
exit 1
```

Make executable:

```bash
chmod 750 scripts/deploy.sh
```

---

# 26. Updating only the API

Suppose version `1.0.1` is available.

Update:

```bash
./scripts/deploy.sh \
  registry.example.com/team/device-api:1.0.1
```

The important Compose command is:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  up -d \
  --no-deps \
  api
```

`--no-deps` prevents PostgreSQL from being unnecessarily recreated.

The database volume and database container remain untouched unless their own configuration changed.

---

# 27. What happens during a normal Compose update?

For one API container, Compose typically:

```text
Stops old API container
    ↓
Removes/replaces old API container
    ↓
Starts new API container
    ↓
New API becomes ready
```

There may be a brief interruption.

Docker Compose on a single host does not automatically provide the same rolling-update behavior as an orchestrator with multiple replicas and traffic management.

Do not promise “zero downtime” merely because the update uses containers.

---

# 28. Estimate the interruption path

Downtime depends on:

- Graceful shutdown duration
    
- Container replacement time
    
- Application startup time
    
- Database migration time
    
- Health-check delay
    
- Reverse-proxy behavior
    
- Client retry behavior
    

Measure:

```bash
time docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  up -d \
  --no-deps \
  api
```

In another terminal, probe continuously:

```bash
while true; do
  date -Iseconds
  curl --silent \
       --output /dev/null \
       --write-out '%{http_code}\n' \
       http://127.0.0.1:8080/health
  sleep 0.5
done
```

This shows actual interruption rather than guessed interruption.

---

# 29. Graceful shutdown

Your API should handle `SIGTERM`.

The Compose setting:

```yaml
stop_grace_period: 30s
```

allows up to 30 seconds for normal shutdown before forced termination.

The database receives:

```yaml
stop_grace_period: 60s
```

because databases may need more time to flush and close cleanly.

Avoid:

```bash
docker kill
```

for ordinary deployment.

Use controlled Compose replacement.

---

# 30. Restart policies

The services use:

```yaml
restart: unless-stopped
```

This causes Docker to restart containers after many failures and after daemon restarts unless they were deliberately stopped.

Docker recommends restart policies for container startup management rather than using host process managers to launch containers individually. ([Docker Documentation](https://docs.docker.com/engine/containers/start-containers-automatically/?utm_source=chatgpt.com "Start containers automatically"))

A restart policy does not fix:

- Wrong credentials
    
- Broken image
    
- Failed migration
    
- Missing configuration
    
- Corrupt database
    
- Unhealthy but still-running application
    

It may instead create a restart loop.

Always inspect logs.

---

# 31. Verify behavior after a server reboot

Before rebooting, record:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  ps
```

Then reboot during a controlled maintenance test:

```bash
sudo reboot
```

After reconnection:

```bash
sudo systemctl status docker
```

Then:

```bash
cd /opt/device-api

docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  ps
```

Verify:

```bash
curl --fail \
  http://127.0.0.1:8080/health
```

A deployment is incomplete until restart behavior has been tested.

---

# 32. Rollback prerequisites

A useful rollback requires:

- Previous image still available
    
- Previous image reference recorded
    
- Previous configuration preserved
    
- Database schema still compatible
    
- Backup created
    
- Rollback procedure tested
    

For example:

```text
Current:
device-api:1.0.1

Previous:
device-api:1.0.0
```

Do not overwrite `1.0.0`.

Immutable version tags make rollback practical.

---

# 33. Manual rollback

Set the previous image:

```bash
export API_IMAGE="registry.example.com/team/device-api:1.0.0"
```

Pull it:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  pull api
```

Replace the API:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  up -d \
  --no-deps \
  api
```

Verify:

```bash
curl --fail \
  --retry 15 \
  --retry-delay 2 \
  --retry-connrefused \
  http://127.0.0.1:8080/health
```

Inspect:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  logs --tail 100 api
```

---

# 34. Create a rollback script

Create `scripts/rollback.sh`:

```bash
#!/usr/bin/env bash

set -Eeuo pipefail

COMPOSE_FILES=(
  -f compose.yaml
  -f compose.production.yaml
)

PREVIOUS_IMAGE="${1:?Usage: rollback.sh PREVIOUS_IMAGE}"

export API_IMAGE="$PREVIOUS_IMAGE"

echo "Pulling rollback image..."
docker compose "${COMPOSE_FILES[@]}" pull api

echo "Applying rollback..."
docker compose "${COMPOSE_FILES[@]}" \
  up -d \
  --no-deps \
  api

echo "Verifying rollback..."
for attempt in $(seq 1 30); do
  if curl --fail --silent \
    http://127.0.0.1:${API_HOST_PORT:-8080}/health \
    >/dev/null; then

    echo "Rollback healthy."
    exit 0
  fi

  sleep 2
done

echo "Rollback failed health verification." >&2
docker compose "${COMPOSE_FILES[@]}" logs --tail 100 api >&2
exit 1
```

Set:

```bash
chmod 750 scripts/rollback.sh
```

---

# 35. Application rollback versus database rollback

These are not the same.

Application rollback:

```text
API 1.0.1
→ API 1.0.0
```

Database rollback:

```text
Schema version 8
→ Schema version 7
```

An older application may fail if the newer release performed incompatible schema changes.

Safer database migrations are often:

```text
Backward compatible first
    ↓
Deploy new application
    ↓
Migrate usage
    ↓
Remove old structures later
```

For example:

1. Add a nullable column.
    
2. Deploy code that can use it.
    
3. Backfill data.
    
4. Later enforce stricter constraints.
    
5. Remove obsolete columns in a future release.
    

---

# 36. Never automatically restore the database during ordinary rollback

Automatically restoring a pre-deployment database backup can delete legitimate data created after deployment.

Example:

```text
14:00 backup
14:05 deployment
14:10 customer creates records
14:15 application rollback
```

Restoring the 14:00 backup would remove the records created between 14:00 and 14:15.

Database restoration is an incident-recovery decision, not a normal application rollback step.

---

# 37. Use exact image digests in critical deployment

Instead of:

```text
registry.example.com/team/device-api:1.0.1
```

deploy:

```text
registry.example.com/team/device-api@sha256:EXACT_DIGEST
```

Set:

```bash
export API_IMAGE='registry.example.com/team/device-api@sha256:...'
```

This guarantees that the deployment uses the approved image content even if a tag is later moved.

Your release record can contain:

```text
Human version: 1.0.1
Deployment reference: repository@sha256:...
```

---

# 38. Create a verification script

Create `scripts/verify.sh`:

```bash
#!/usr/bin/env bash

set -Eeuo pipefail

COMPOSE_FILES=(
  -f compose.yaml
  -f compose.production.yaml
)

echo "Container state:"
docker compose "${COMPOSE_FILES[@]}" ps

echo
echo "API health:"
curl --fail \
  --silent \
  --show-error \
  "http://127.0.0.1:${API_HOST_PORT:-8080}/health"

echo
echo "API image:"
docker inspect \
  "$(docker compose "${COMPOSE_FILES[@]}" ps -q api)" \
  --format '{{.Config.Image}}'

echo
echo "Database readiness:"
docker compose "${COMPOSE_FILES[@]}" \
  exec -T database \
  pg_isready \
    -U "${DB_USER:-device_app}" \
    -d "${DB_NAME:-device_monitor}"

echo
echo "Recent errors:"
docker compose "${COMPOSE_FILES[@]}" \
  logs \
  --since 10m \
  --tail 100
```

Make executable:

```bash
chmod 750 scripts/verify.sh
```

---

# 39. Deployment health checks should test more than the process

Weak deployment verification:

```bash
docker ps
```

This proves only that a process is running.

Better:

```text
API endpoint responds
Database connection succeeds
Expected version is running
Basic read operation works
Basic write operation works where safe
Logs contain no startup failure
```

Possible checks:

```bash
curl --fail http://127.0.0.1:8080/health
curl --fail http://127.0.0.1:8080/api/config
curl --fail http://127.0.0.1:8080/api/devices
```

Avoid destructive verification against production data.

---

# 40. Log rotation

The production override uses:

```yaml
logging:
  driver: local
  options:
    max-size: "10m"
    max-file: "3"
```

Docker supports configurable logging drivers, and containers use the daemon’s default driver unless one is specified per container. ([Docker Documentation](https://docs.docker.com/engine/logging/configure/?utm_source=chatgpt.com "Configure logging drivers"))

Check:

```bash
docker inspect "$API_CONTAINER" \
  --format '{{json .HostConfig.LogConfig}}'
```

Also monitor:

```bash
df -h
docker system df -v
```

Unbounded logs can fill the host filesystem.

---

# 41. Monitor production state

Useful routine commands:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  ps
```

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  logs --since 30m
```

```bash
docker stats --no-stream
```

```bash
docker system df -v
```

```bash
df -h
```

```bash
free -h
```

```bash
uptime
```

For serious production operation, connect these signals to a monitoring and alerting system.

---

# 42. Disk-space planning

Docker host disk usage includes:

- Pulled images
    
- Old images
    
- Build cache
    
- Container logs
    
- Writable container layers
    
- Named volumes
    
- PostgreSQL data
    
- Backups
    

A production server that only deploys prebuilt images should normally have little build cache.

Inspect:

```bash
docker system df -v
```

Do not blindly schedule:

```bash
docker system prune -a --volumes
```

That can remove useful images and unattached but important volumes.

Clean deliberately.

---

# 43. Safe image cleanup

List images:

```bash
docker image ls
```

Find dangling images:

```bash
docker image ls \
  --filter dangling=true
```

Remove an explicitly identified old image:

```bash
docker image rm \
  registry.example.com/team/device-api:0.9.0
```

Keep at least:

- Current release
    
- Previous known-good release
    
- Any release required by rollback policy
    

Images remain available in the registry, but retaining the previous local image can make rollback faster during a registry outage.

---

# 44. Never use `docker compose down -v` casually

Normal project removal:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  down
```

normally preserves named volumes.

This:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  down -v
```

removes the declared named volumes and therefore the PostgreSQL data.

On a production server, `down -v` should be treated as destructive data-deletion behavior.

---

# 45. Deploying configuration changes

Suppose you change:

```yaml
mem_limit: 512m
```

to:

```yaml
mem_limit: 768m
```

Validate:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  config
```

Apply:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  up -d api
```

Compose recreates the service container when required.

A simple:

```bash
docker compose restart api
```

does not apply most changed container configuration.

Use `up -d` to reconcile the project model.

---

# 46. Do not edit running containers

Avoid:

```bash
docker exec -it api sh
apt install ...
nano /app/app.py
```

Changes inside a running container are:

- Untracked
    
- Difficult to reproduce
    
- Lost during replacement
    
- Not represented in the registry image
    
- Not reviewed
    
- Not tested by CI
    

Correct workflow:

```text
Change source or configuration
    ↓
Commit
    ↓
CI builds and tests
    ↓
Push new image
    ↓
Deploy new version
```

---

# 47. Emergency changes

An emergency does not justify destroying traceability.

A controlled emergency fix should still:

1. Create a source change.
    
2. Commit it.
    
3. Build through the pipeline.
    
4. Produce a new patch version.
    
5. Deploy it.
    
6. Record the incident and release.
    

Example:

```text
1.0.1
→ urgent fix
→ 1.0.2
```

Do not silently overwrite:

```text
1.0.1
```

with different content.

---

# 48. Basic blue-green deployment concept

A single Compose API container produces a short replacement interruption.

Blue-green deployment reduces this by running old and new versions simultaneously:

```text
Reverse proxy
     │
     ├── blue: API 1.0.0
     └── green: API 1.0.1
```

Workflow:

```text
Blue receives traffic
    ↓
Start green
    ↓
Verify green
    ↓
Switch proxy to green
    ↓
Keep blue temporarily
    ↓
Remove blue later
```

This requires:

- Two API instances
    
- Separate host ports or service names
    
- A reverse proxy
    
- Shared database compatibility
    
- Careful migrations
    
- Traffic-switching procedure
    

---

# 49. Simple blue-green example

Run blue:

```yaml
services:
  api-blue:
    image: "${BLUE_IMAGE}"
    ports:
      - "127.0.0.1:8081:5000"
```

Run green:

```yaml
services:
  api-green:
    image: "${GREEN_IMAGE}"
    ports:
      - "127.0.0.1:8082:5000"
```

Test green:

```bash
curl --fail \
  http://127.0.0.1:8082/health
```

Then change the reverse proxy from:

```text
127.0.0.1:8081
```

to:

```text
127.0.0.1:8082
```

Reload the proxy gracefully.

Blue-green deployment is more operationally complex, but it separates “start the new release” from “send production traffic to it.”

---

# 50. Why scaling one Compose service is not enough

You might try:

```bash
docker compose up -d \
  --scale api=2
```

But if the service has:

```yaml
ports:
  - "8080:5000"
```

both containers cannot bind the same host port.

Multiple replicas normally require:

- A reverse proxy or load balancer
    
- Dynamic service discovery
    
- No unique global container name
    
- Stateless application design
    
- Shared external persistence
    
- Proper session management
    

A database should not be scaled using ordinary Compose `--scale` as though it were a stateless web application.

---

# 51. Compose versus orchestration

Single-host Compose gives you:

- Declarative services
    
- Networks
    
- Volumes
    
- Health checks
    
- Restart policies
    
- Reproducible deployments
    

It does not by itself provide full cluster orchestration such as:

- Multi-node scheduling
    
- Automatic rescheduling to another server
    
- Native rolling updates across nodes
    
- Integrated load balancing across replicas
    
- Cluster-level secrets
    
- Quorum management
    

Docker Swarm supports service rolling updates and rollback controls, while Kubernetes provides its own deployment mechanisms. ([Docker Documentation](https://docs.docker.com/engine/swarm/?utm_source=chatgpt.com "Swarm mode"))

You will study orchestration in later days.

---

# 52. Deployment from CI

Your GitLab release job can connect to the server and execute:

```bash
cd /opt/device-api

export API_IMAGE='registry.example.com/team/device-api@sha256:...'

./scripts/deploy.sh "$API_IMAGE"
```

Production credentials should be:

- Protected
    
- Limited to protected release tags
    
- Restricted to the deployment server
    
- Unavailable to feature branches
    
- Rotated
    

The deployment server should use a registry credential with pull-only permission.

---

# 53. Do not pass secrets through SSH command strings

Avoid:

```bash
ssh server "
  export DB_PASSWORD=secret-value
  docker compose up -d
"
```

The value may appear in:

- CI logs
    
- Shell history
    
- Process arguments
    
- Debugging output
    

Place long-lived runtime secrets securely on the server or obtain them from an approved secret manager.

The pipeline should deploy image identity, not expose all application credentials.

---

# 54. Remote Docker context alternative

Docker Compose can operate against a remote Docker host when the Docker client is configured to connect to it. Docker’s production documentation describes remote Compose operation using Docker host connection settings. ([Docker Documentation](https://docs.docker.com/compose/how-tos/production/?utm_source=chatgpt.com "Use Compose in production"))

However, remote Docker access is highly privileged.

Prefer:

- SSH-based Docker contexts
    
- Mutual TLS
    
- Network restrictions
    
- Dedicated deployment identities
    

Do not expose an unauthenticated Docker TCP socket.

---

# 55. Production incident evidence

When a deployment fails, collect:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  ps -a
```

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  logs \
  --timestamps \
  --tail 200
```

```bash
docker inspect "$API_CONTAINER" \
  --format '{{json .State}}'
```

```bash
docker inspect "$API_CONTAINER" \
  --format '{{json .State.Health}}'
```

```bash
docker stats --no-stream
```

```bash
df -h
```

```bash
docker system df -v
```

Record the image reference and digest.

Do not begin by deleting containers, volumes, or logs.

Preserve evidence.

---

# 56. Common production deployment failures

## Registry authentication fails

Symptoms:

```text
unauthorized
denied
```

Check:

- Registry hostname
    
- Deployment token
    
- Token expiration
    
- Pull permission
    
- Certificate trust
    

---

## Image tag does not exist

Symptoms:

```text
manifest unknown
not found
```

Check the exact tag or digest produced by CI.

Do not substitute `latest`.

---

## API fails after replacement

Check:

```bash
docker compose logs api
```

Possible causes:

- Missing runtime secret
    
- Database migration problem
    
- Wrong image architecture
    
- Incorrect environment
    
- Read-only filesystem conflict
    
- Health-check failure
    
- Missing shared library
    

---

## Database is healthy but API cannot connect

Check:

```bash
docker compose exec api \
  getent hosts database
```

Then test port connectivity if the image contains tools or use a diagnostic container on the backend network.

Check credentials and database name.

---

## Host port is already allocated

Check:

```bash
sudo ss -lntp \
  | grep ':8080'
```

and:

```bash
docker ps \
  --format 'table {{.Names}}\t{{.Ports}}'
```

---

## No space left on device

Check:

```bash
df -h
docker system df -v
du -sh backups/
```

Possible consumers:

- Database
    
- Backups
    
- Logs
    
- Old images
    
- Old volumes
    

---

## New version is unhealthy

Do not leave it running indefinitely.

Collect logs, then roll back to the previously verified image.

---

# 57. Production deployment runbook

Before deployment:

```text
1. Release image exists in registry.
2. Image tag and digest are recorded.
3. CI tests passed.
4. Security scan reviewed.
5. Compose configuration validates.
6. Database backup completed.
7. Backup is readable.
8. Previous image is known.
9. Rollback compatibility is understood.
10. Maintenance communication completed if needed.
```

During deployment:

```text
1. Pull image.
2. Verify digest.
3. Update API only.
4. Observe container state.
5. Wait for health.
6. Test critical endpoints.
7. Inspect logs.
8. Record release.
```

After deployment:

```text
1. Verify database connectivity.
2. Verify API version.
3. Monitor errors and resources.
4. Keep previous image.
5. Confirm backup was copied off-host.
6. Close deployment record.
```

---

# 58. Day 20 practical laboratory

## Exercise 1 — Production server directory

Create:

```text
/opt/device-api
```

Add:

- Compose files
    
- `.env`
    
- Secret directory
    
- Backup directory
    
- Scripts directory
    

Set appropriate ownership and modes.

---

## Exercise 2 — Production Compose configuration

Create a base file and production override.

Use:

- Registry image
    
- Internal database network
    
- Localhost API binding
    
- Health checks
    
- Restart policies
    
- Read-only API filesystem
    
- Resource limits
    
- Log rotation
    

---

## Exercise 3 — Configuration validation

Run:

```bash
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  config
```

Review the full result.

---

## Exercise 4 — Secret configuration

Create a PostgreSQL password file.

Mount it as a Compose secret.

Confirm the raw password does not appear in the API environment.

---

## Exercise 5 — First deployment

Pull the images.

Start the stack.

Verify:

- API healthy
    
- Database healthy
    
- PostgreSQL not published
    
- API reachable only on localhost
    
- Persistent volume attached
    

---

## Exercise 6 — Backup

Create a PostgreSQL logical dump.

List its contents.

Copy it outside the Docker host or simulate an off-host copy.

---

## Exercise 7 — Application update

Deploy a newer API version.

Use:

```bash
docker compose up -d --no-deps api
```

Confirm PostgreSQL was not recreated.

---

## Exercise 8 — Failure and rollback

Deploy a deliberately broken API image.

Observe failed health.

Collect logs.

Rollback to the previous exact image.

Confirm health recovers.

---

## Exercise 9 — Server reboot

Reboot the test server.

Confirm:

- Docker starts
    
- Containers restart
    
- Database data remains
    
- API becomes healthy
    
- Host port binding remains correct
    

---

## Exercise 10 — Blue-green experiment

Run API version A on port 8081.

Run version B on port 8082.

Verify B.

Simulate switching traffic by changing which port you query.

---

# 59. Day 20 command reference

```bash
# Validate production configuration
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  config

# Pull all service images
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  pull

# Start or reconcile the project
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  up -d

# Update only the API
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  up -d \
  --no-deps \
  api

# Show project state
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  ps

# View recent logs
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  logs \
  --since 10m \
  --tail 100

# Verify health
curl --fail \
  --retry 15 \
  --retry-delay 2 \
  --retry-connrefused \
  http://127.0.0.1:8080/health

# Create PostgreSQL backup
docker compose \
  -f compose.yaml \
  -f compose.production.yaml \
  exec -T database \
  pg_dump \
    -U device_app \
    -d device_monitor \
    --format=custom \
  > backup.dump

# Inspect deployed image
docker inspect \
  "$(docker compose \
      -f compose.yaml \
      -f compose.production.yaml \
      ps -q api)" \
  --format '{{.Config.Image}}'

# Inspect volumes
docker inspect \
  "$(docker compose \
      -f compose.yaml \
      -f compose.production.yaml \
      ps -q database)" \
  --format '{{json .Mounts}}'

# Check disk usage
df -h
docker system df -v
```

---

# 60. Knowledge check

## Should the production server build the application image?

Normally no. It should pull the exact image built and tested by the CI pipeline.

## Why use two Compose files?

To keep shared architecture in a base file and production-specific settings in an override.

## Why run `docker compose config`?

To validate and inspect the final merged configuration before deployment.

## Why pull before replacing containers?

It detects registry, authentication, storage, and image-availability failures before changing the running application.

## What does `--no-deps` do?

It updates the selected service without unnecessarily recreating its dependency services.

## Does a healthy container prove the whole business workflow works?

Not necessarily. Add post-deployment functional checks for critical behavior.

## Does a named volume replace backups?

No. It provides persistence across container replacement, not protection from corruption, deletion, or host failure.

## Why keep the previous image?

To make rollback faster and possible even during a temporary registry failure.

## Does ordinary Compose guarantee zero-downtime updates?

No. Replacing a single container commonly causes a brief interruption.

## What is blue-green deployment?

Running old and new versions simultaneously, verifying the new version, then switching traffic.

## Why can database migrations block application rollback?

An older application may not understand a newer incompatible schema.

## Should ordinary rollback automatically restore the database backup?

No. That could delete valid data created since the backup.

## Why deploy by digest?

It guarantees the exact approved image content.

---

# 61. Day 20 completion challenge

Complete this independently:

1. Prepare a dedicated Linux Docker server.
    
2. Enable Docker at boot.
    
3. Create a restricted deployment user.
    
4. Create `/opt/device-api`.
    
5. Create the production directory structure.
    
6. Copy only deployment configuration—not source code.
    
7. Create a base Compose file.
    
8. Create a production override.
    
9. Reference the API through a registry image.
    
10. Require `API_IMAGE` through interpolation.
    
11. Bind the API to `127.0.0.1`.
    
12. Leave PostgreSQL unpublished.
    
13. Create an internal backend network.
    
14. Add PostgreSQL persistence.
    
15. Create a database secret file.
    
16. Restrict its permissions.
    
17. Configure both services to read the secret file.
    
18. Add health checks.
    
19. Add restart policies.
    
20. Add graceful shutdown periods.
    
21. Add resource limits.
    
22. Add log rotation.
    
23. Validate the merged configuration.
    
24. Authenticate using a pull-only registry token.
    
25. Pull the release image.
    
26. Record its digest.
    
27. Deploy the stack.
    
28. Verify both service health states.
    
29. Verify the API externally through its intended access path.
    
30. Verify PostgreSQL has no host port.
    
31. Create a database record.
    
32. Create a logical database backup.
    
33. Verify the backup can be read.
    
34. Copy the backup off-host.
    
35. Deploy a newer API version.
    
36. Confirm PostgreSQL was not recreated.
    
37. Confirm the record remains.
    
38. Record the new release manifest.
    
39. Deploy a deliberately unhealthy image.
    
40. Collect logs and health evidence.
    
41. Roll back to the previous version.
    
42. Confirm the application recovers.
    
43. Reboot the server.
    
44. Confirm Docker and the application return automatically.
    
45. Measure update interruption.
    
46. Run two API versions simultaneously.
    
47. Verify the new version before traffic switching.
    
48. Explain why database rollback is separate from application rollback.
    
49. Document the complete deployment runbook.
    
50. Perform the full procedure again without improvising commands.
    

The central Day 20 model is:

```text
Tested registry image
        ↓
Validate deployment configuration
        ↓
Pull without changing production
        ↓
Back up persistent data
        ↓
Replace selected service
        ↓
Verify health and functionality
        ↓
Record image tag and digest
        ↓
Monitor
        ↓
Rollback if verification fails
```

The most important operational lesson is:

> A production deployment is not merely `docker compose up -d`. It is a controlled procedure that validates configuration, pulls an exact tested artifact, protects persistent data, updates only the intended services, verifies real application health, records the deployed identity, and preserves a tested rollback path.