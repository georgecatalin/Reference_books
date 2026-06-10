#### Container Observability, Health Checks, Logs, and Troubleshooting

A container being listed as **running** does not prove that its application is working correctly.

For example:

```text
Container process: running
Web server: frozen
Database: unreachable
Disk: full
Health endpoint: failing
```

Today you will learn how to determine what a container is actually doing and why it fails.

The central lesson is:

> Troubleshoot containers from evidence: state, health, logs, processes, resource usage, networking, mounts, and exit codes.

By the end of Day 16, you should understand:

- Container states and exit codes
    
- Docker health checks
    
- Container logs
    
- Standard output and standard error
    
- Log following, filtering, and timestamps
    
- Docker logging drivers
    
- Resource monitoring with `docker stats`
    
- Process inspection with `docker top`
    
- Runtime inspection with `docker inspect`
    
- Docker events
    
- Restart policies
    
- Out-of-memory failures
    
- Common container failure patterns
    
- A repeatable troubleshooting workflow
    
- Observability in Docker Compose
    

---

# 1. Container state versus application health

Run:

```bash
docker run -d \
  --name day16-running \
  alpine \
  sleep 3600
```

Docker reports it as running:

```bash
docker ps
```

But this container provides no useful application service. It only runs `sleep`.

Docker knows:

```text
The main process exists.
```

Docker does not automatically know:

```text
The application answers HTTP requests.
The database accepts SQL queries.
The MQTT broker accepts connections.
The application can reach its dependencies.
```

Without a health check:

```text
running ≠ healthy
```

---

# 2. Important container states

List all containers:

```bash
docker ps -a
```

Common states include:

|State|Meaning|
|---|---|
|`created`|Container exists but has not started|
|`running`|Main process is executing|
|`paused`|Processes are suspended|
|`restarting`|Docker repeatedly restarts it|
|`exited`|Main process ended|
|`dead`|Docker could not cleanly manage/remove it|
|`removing`|Container removal is in progress|

Inspect the exact state:

```bash
docker inspect day16-running \
  --format 'Status={{.State.Status}} Running={{.State.Running}} ExitCode={{.State.ExitCode}}'
```

---

# 3. The container follows its main process

Run:

```bash
docker run --name day16-exit alpine \
  sh -c 'echo "Work completed"; exit 0'
```

Check:

```bash
docker ps -a \
  --filter name=day16-exit
```

The container exited because its main process exited.

Inspect:

```bash
docker inspect day16-exit \
  --format 'Status={{.State.Status}} ExitCode={{.State.ExitCode}}'
```

Expected:

```text
Status=exited ExitCode=0
```

An exited container is not necessarily broken. It may have completed a one-time job successfully.

---

# 4. Understanding exit codes

An exit code communicates how a process ended.

The general convention is:

```text
0
→ successful completion

non-zero
→ error or abnormal result
```

Create a failed container:

```bash
docker run --name day16-failure alpine \
  sh -c 'echo "Something failed" >&2; exit 42'
```

Inspect:

```bash
docker inspect day16-failure \
  --format 'ExitCode={{.State.ExitCode}}'
```

Expected:

```text
ExitCode=42
```

The application chose exit code 42.

---

# 5. Common Docker-related exit codes

These are common interpretations, although exact meaning depends on the application:

|Exit code|Common meaning|
|--:|---|
|`0`|Successful completion|
|`1`|General application error|
|`2`|Misuse or invalid arguments|
|`126`|Command found but cannot execute|
|`127`|Command not found|
|`128+n`|Process terminated by signal `n`|
|`137`|Often killed by `SIGKILL`, commonly OOM or forced stop|
|`139`|Segmentation fault, usually signal 11|
|`143`|Terminated by `SIGTERM`, signal 15|

The formula for signal-related exits is commonly:

```text
128 + signal number
```

For `SIGTERM`:

```text
128 + 15 = 143
```

For `SIGKILL`:

```text
128 + 9 = 137
```

An exit code is evidence, not always a complete diagnosis.

---

# 6. Inspect the complete state

Use:

```bash
docker inspect day16-failure \
  --format '{{json .State}}'
```

You may see fields such as:

```text
Status
Running
Paused
Restarting
OOMKilled
Dead
Pid
ExitCode
Error
StartedAt
FinishedAt
Health
```

A readable version:

```bash
docker inspect day16-failure \
  --format '
Status={{.State.Status}}
ExitCode={{.State.ExitCode}}
OOMKilled={{.State.OOMKilled}}
Error={{.State.Error}}
StartedAt={{.State.StartedAt}}
FinishedAt={{.State.FinishedAt}}
'
```

---

# 7. Logs are the first application-level evidence

Read logs:

```bash
docker logs day16-failure
```

Output written by the process to:

```text
stdout
stderr
```

is captured by Docker’s configured logging system.

Your process used:

```bash
echo "Something failed" >&2
```

The `>&2` sends the message to standard error.

Docker logs can normally display both streams.

---

# 8. Standard output and standard error

Applications should generally send:

```text
Normal events
→ stdout

Warnings and failures
→ stderr
```

Example shell script:

```sh
#!/bin/sh

echo "Application starting"
echo "Configuration warning" >&2
echo "Application ready"
```

Docker captures both streams.

For containerized services, this is generally preferable to storing important logs only in:

```text
/var/log/application.log
```

Files inside the container may disappear when the container is removed and are not automatically shown by `docker logs`.

---

# 9. Basic `docker logs` commands

Show all available logs:

```bash
docker logs CONTAINER
```

Follow new logs:

```bash
docker logs -f CONTAINER
```

Show only the last 50 lines:

```bash
docker logs --tail 50 CONTAINER
```

Add timestamps:

```bash
docker logs --timestamps CONTAINER
```

Show recent logs:

```bash
docker logs --since 10m CONTAINER
```

Show logs since an absolute time:

```bash
docker logs \
  --since "2026-06-09T18:00:00" \
  CONTAINER
```

Stop following with:

```text
Ctrl+C
```

This does not stop the container.

---

# 10. Create a continuously logging container

Run:

```bash
docker run -d \
  --name day16-logger \
  alpine \
  sh -c '
    counter=1
    while true; do
      echo "$(date -Iseconds) heartbeat=$counter"
      counter=$((counter + 1))
      sleep 2
    done
  '
```

Follow:

```bash
docker logs -f day16-logger
```

In another terminal:

```bash
docker logs \
  --tail 5 \
  --timestamps \
  day16-logger
```

This demonstrates a normal long-running process that logs periodically.

---

# 11. Application log buffering

Some applications buffer output.

For Python, this may cause log messages to appear late.

Useful solutions include:

```dockerfile
ENV PYTHONUNBUFFERED=1
```

or:

```bash
python -u app.py
```

In Python code:

```python
print("Application started", flush=True)
```

Your earlier Python images used:

```dockerfile
ENV PYTHONUNBUFFERED=1
```

This makes logs more useful in real time.

For C:

```c
printf("Application started\n");
fflush(stdout);
```

Without flushing, output may remain buffered.

---

# 12. Logging drivers

Docker uses a logging driver to handle container output.

Inspect the Docker daemon’s default:

```bash
docker info \
  --format '{{.LoggingDriver}}'
```

A common default is:

```text
json-file
```

Inspect one container:

```bash
docker inspect day16-logger \
  --format '{{.HostConfig.LogConfig.Type}}'
```

Common logging drivers include:

- `json-file`
    
- `local`
    
- `journald`
    
- `syslog`
    
- `fluentd`
    
- `gelf`
    
- `awslogs`
    
- `splunk`
    
- `none`
    

Available drivers depend on your Docker environment.

---

# 13. Log files can consume disk space

With file-based Docker logging, continuously generated logs consume host storage.

Inspect Docker disk use:

```bash
docker system df
```

Inspect the logging configuration:

```bash
docker inspect day16-logger \
  --format '{{json .HostConfig.LogConfig}}'
```

For a standalone container, configure rotation:

```bash
docker run -d \
  --name day16-rotating-logs \
  --log-driver local \
  --log-opt max-size=10m \
  --log-opt max-file=3 \
  alpine \
  sh -c '
    while true; do
      echo "$(date -Iseconds) application event"
      sleep 1
    done
  '
```

The exact supported options vary by logging driver.

Never assume logs have unlimited safe storage.

---

# 14. Logging in Compose

A Compose service can define:

```yaml
services:
  api:
    logging:
      driver: local
      options:
        max-size: "10m"
        max-file: "3"
```

View logs:

```bash
docker compose logs api
```

Follow the entire stack:

```bash
docker compose logs -f
```

Follow selected services:

```bash
docker compose logs -f api database
```

Keep application logs structured and useful:

```text
timestamp
severity
service
request ID
device ID
operation
error
```

Avoid logging passwords, tokens, private keys, or full sensitive payloads.

---

# 15. What is a Docker health check?

A health check is a command Docker runs periodically inside the container to determine whether the application is functioning.

Possible health states:

```text
starting
healthy
unhealthy
```

The container can remain:

```text
running
```

while its health is:

```text
unhealthy
```

This distinction is extremely valuable.

---

# 16. Add a health check with `docker run`

Start Nginx:

```bash
docker run -d \
  --name day16-nginx \
  -p 8080:80 \
  --health-cmd='wget -q --spider http://localhost/ || exit 1' \
  --health-interval=5s \
  --health-timeout=3s \
  --health-retries=3 \
  --health-start-period=5s \
  nginx:alpine
```

Check:

```bash
docker ps \
  --filter name=day16-nginx
```

After initialization, its status should include:

```text
healthy
```

Inspect:

```bash
docker inspect day16-nginx \
  --format '{{.State.Health.Status}}'
```

---

# 17. Health-check settings

The command defined:

```text
Health command:
wget -q --spider http://localhost/
```

```text
Interval:
run every 5 seconds
```

```text
Timeout:
one check may take at most 3 seconds
```

```text
Retries:
three consecutive failures cause unhealthy status
```

```text
Start period:
allow startup time before failures count normally
```

The health command runs inside the container.

Therefore:

```text
localhost
```

means that same container.

---

# 18. Inspect health-check history

Run:

```bash
docker inspect day16-nginx \
  --format '{{json .State.Health}}'
```

For a readable view:

```bash
docker inspect day16-nginx \
  --format '{{range .State.Health.Log}}Exit={{.ExitCode}} Start={{.Start}} End={{.End}} Output={{printf "%q" .Output}}{{println}}{{end}}'
```

Docker records recent health-check executions, including:

- Start time
    
- End time
    
- Exit code
    
- Command output
    

Health command exit code:

```text
0
→ healthy check result

non-zero
→ failed check result
```

---

# 19. Deliberately make a container unhealthy

Start:

```bash
docker run -d \
  --name day16-unhealthy \
  --health-cmd='test -f /tmp/healthy' \
  --health-interval=3s \
  --health-timeout=2s \
  --health-retries=2 \
  alpine \
  sleep 3600
```

Check:

```bash
docker ps \
  --filter name=day16-unhealthy
```

After repeated failures, it becomes unhealthy.

The main process still runs:

```bash
docker inspect day16-unhealthy \
  --format 'Running={{.State.Running}} Health={{.State.Health.Status}}'
```

Expected conceptually:

```text
Running=true Health=unhealthy
```

---

# 20. Recover an unhealthy container

Create the required file:

```bash
docker exec day16-unhealthy \
  touch /tmp/healthy
```

Wait for the next check.

Inspect:

```bash
docker inspect day16-unhealthy \
  --format '{{.State.Health.Status}}'
```

It should become healthy.

Remove the file:

```bash
docker exec day16-unhealthy \
  rm /tmp/healthy
```

After enough failures, it becomes unhealthy again.

This proves that health can change without restarting the container.

---

# 21. Health checks do not automatically restart containers

A common misunderstanding is:

```text
unhealthy
→ Docker automatically restarts container
```

Ordinary Docker restart policies react mainly to the main process exiting.

An unhealthy container whose main process continues running normally remains running.

Health status is used by:

- Operators
    
- Docker Compose dependency conditions
    
- Monitoring systems
    
- Orchestrators
    
- Deployment logic
    

Your application may need to exit when it cannot recover, or an external system must react to unhealthy status.

---

# 22. Dockerfile `HEALTHCHECK`

A health check can be included in an image:

```dockerfile
HEALTHCHECK \
  --interval=10s \
  --timeout=3s \
  --start-period=10s \
  --retries=3 \
  CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:5000/health', timeout=2)"
```

Or exec-style:

```dockerfile
HEALTHCHECK CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://localhost:5000/health', timeout=2)"]
```

Only one effective health check applies to the final image configuration.

Runtime or Compose configuration can override the image’s health check.

---

# 23. Health check in Compose

For your device API:

```yaml
services:
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
      start_period: 15s
```

For PostgreSQL:

```yaml
services:
  database:
    healthcheck:
      test:
        - CMD-SHELL
        - pg_isready -U device_app -d device_monitor
      interval: 5s
      timeout: 3s
      retries: 10
      start_period: 10s
```

Check:

```bash
docker compose ps
```

---

# 24. A good health check tests real usefulness

Weak health check:

```bash
pgrep gunicorn
```

This proves only that a process exists.

Better:

```text
HTTP GET /health
```

This may verify:

- Web server accepts connections
    
- Application routes work
    
- Database can be queried
    
- Critical dependencies are available
    

But avoid making a health check too expensive.

A health check runs repeatedly and should be:

- Fast
    
- Predictable
    
- Safe
    
- Non-destructive
    
- Time-bounded
    

---

# 25. Liveness versus readiness

Docker health checks commonly combine concepts that larger orchestrators distinguish.

## Liveness

Question:

```text
Is the process fundamentally alive, or should it be restarted?
```

Example:

```text
Application event loop still responds.
```

## Readiness

Question:

```text
Can this instance accept useful traffic now?
```

Example:

```text
Web server works and database is reachable.
```

An application might be:

```text
alive but not ready
```

during startup or a temporary database outage.

In basic Docker, you usually have one health status. Design it according to how the status will be used.

---

# 26. `docker stats`

Monitor live resource usage:

```bash
docker stats
```

The output includes values such as:

|Column|Meaning|
|---|---|
|`CPU %`|CPU usage|
|`MEM USAGE / LIMIT`|Memory used and configured limit|
|`MEM %`|Percentage of memory limit|
|`NET I/O`|Network traffic|
|`BLOCK I/O`|Storage reads/writes|
|`PIDS`|Process count|

Stop with:

```text
Ctrl+C
```

Monitor selected containers:

```bash
docker stats day16-nginx day16-logger
```

One-time snapshot:

```bash
docker stats --no-stream
```

---

# 27. Understanding CPU percentage

CPU percentage depends on:

- Number of CPU cores
    
- Docker platform
    
- Container workload
    
- Sampling period
    

A multi-threaded process may show more than 100% on a multi-core system.

For example:

```text
200%
```

can represent approximately two fully used CPU cores.

Do not treat CPU percentage in isolation.

Investigate:

- Normal application baseline
    
- Request volume
    
- Infinite loops
    
- Excessive retries
    
- Compression or encryption workload
    
- Database queries
    
- Number of worker processes
    

---

# 28. Generate CPU load

Start:

```bash
docker run -d \
  --name day16-cpu \
  alpine \
  sh -c 'while true; do :; done'
```

Observe:

```bash
docker stats day16-cpu
```

The process continuously executes an empty shell operation.

Inspect processes:

```bash
docker top day16-cpu
```

Remove it after testing:

```bash
docker rm -f day16-cpu
```

Unbounded busy loops consume host resources even though the container is isolated.

---

# 29. Memory monitoring

Start a Python container that allocates memory gradually:

```bash
docker run -d \
  --name day16-memory \
  python:3.13-slim \
  python -c '
import time

chunks = []

while True:
    chunks.append(bytearray(10 * 1024 * 1024))
    print(
        f"allocated_mb={len(chunks) * 10}",
        flush=True,
    )
    time.sleep(1)
'
```

Observe:

```bash
docker stats day16-memory
```

Follow logs:

```bash
docker logs -f day16-memory
```

Without a memory limit, the container may consume substantial host memory.

Remove before it endangers the host:

```bash
docker rm -f day16-memory
```

---

# 30. Set a memory limit

Run:

```bash
docker run -d \
  --name day16-memory-limited \
  --memory 100m \
  python:3.13-slim \
  python -c '
import time

chunks = []

while True:
    chunks.append(bytearray(10 * 1024 * 1024))
    print(
        f"allocated_mb={len(chunks) * 10}",
        flush=True,
    )
    time.sleep(1)
'
```

Monitor:

```bash
docker stats day16-memory-limited
```

Eventually, the process may be terminated after reaching the memory constraint.

Inspect:

```bash
docker inspect day16-memory-limited \
  --format 'Status={{.State.Status}} ExitCode={{.State.ExitCode}} OOMKilled={{.State.OOMKilled}}'
```

You may see:

```text
ExitCode=137
OOMKilled=true
```

Exact behavior can vary by kernel and Docker configuration.

---

# 31. OOMKilled

`OOM` means:

```text
Out Of Memory
```

When a container exceeds its enforceable memory limit, the kernel may kill a process.

Check:

```bash
docker inspect CONTAINER \
  --format '{{.State.OOMKilled}}'
```

Do not diagnose exit code 137 only as an OOM event.

It can also result from a forced `SIGKILL`, such as:

```bash
docker kill CONTAINER
```

Use both:

```text
ExitCode
OOMKilled field
```

and inspect daemon/application evidence.

---

# 32. Set CPU limits

Limit a container to approximately half a CPU:

```bash
docker run -d \
  --name day16-cpu-limited \
  --cpus 0.5 \
  alpine \
  sh -c 'while true; do :; done'
```

Observe:

```bash
docker stats day16-cpu-limited
```

Other controls include:

```bash
--cpu-shares
--cpuset-cpus
--cpu-quota
--cpu-period
```

For normal use, `--cpus` is easier to understand.

Remove:

```bash
docker rm -f day16-cpu-limited
```

---

# 33. Resource limits in Compose

Example:

```yaml
services:
  api:
    mem_limit: 512m
    cpus: 1.0
```

For a learning Compose deployment, this limits the container approximately to:

```text
512 MB memory
1 CPU
```

Choose limits from observation and load testing.

Limits that are too high provide little protection.

Limits that are too low cause:

- Slowdowns
    
- Request failures
    
- OOM kills
    
- Restart loops
    
- Database instability
    

Databases require especially careful memory planning.

---

# 34. Inspect configured limits

For a running container:

```bash
docker inspect CONTAINER \
  --format 'Memory={{.HostConfig.Memory}} NanoCPUs={{.HostConfig.NanoCpus}}'
```

Memory is represented in bytes.

For a 100 MB limit, the value may resemble:

```text
104857600
```

Inspect process limits from inside where useful:

```bash
docker exec CONTAINER \
  sh -c 'ulimit -a'
```

Linux cgroup limits and shell `ulimit` settings are related but different mechanisms.

---

# 35. `docker top`

Show the processes running inside a container:

```bash
docker top day16-nginx
```

You may see:

- PID
    
- User
    
- Command
    
- Process hierarchy details
    

For Gunicorn, you may see:

```text
Master process
Worker process 1
Worker process 2
```

This does not violate the “one responsibility per container” principle. The service still has one application responsibility while managing worker processes.

---

# 36. Process inspection inside the container

Some minimal images do not include:

```text
ps
top
ss
netstat
```

That does not mean the container has no processes or sockets.

Use host-side commands:

```bash
docker top CONTAINER
docker stats CONTAINER
docker inspect CONTAINER
```

Or use a diagnostic container that shares namespaces where appropriate.

Avoid installing a full diagnostic toolbox permanently in production images merely for occasional troubleshooting.

---

# 37. `docker inspect`

`docker inspect` is one of the most important troubleshooting tools.

Inspect everything:

```bash
docker inspect CONTAINER
```

Useful targeted queries:

```bash
docker inspect CONTAINER \
  --format '{{.State.Status}}'
```

```bash
docker inspect CONTAINER \
  --format '{{.State.ExitCode}}'
```

```bash
docker inspect CONTAINER \
  --format '{{.State.OOMKilled}}'
```

```bash
docker inspect CONTAINER \
  --format '{{json .Config.Env}}'
```

```bash
docker inspect CONTAINER \
  --format '{{json .Mounts}}'
```

```bash
docker inspect CONTAINER \
  --format '{{json .NetworkSettings.Networks}}'
```

```bash
docker inspect CONTAINER \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'
```

---

# 38. Inspect environment carefully

Run:

```bash
docker inspect CONTAINER \
  --format '{{json .Config.Env}}'
```

This can expose:

- Database passwords
    
- Tokens
    
- API keys
    
- Internal hostnames
    
- Runtime settings
    

Do not paste unredacted inspect output into:

- Public bug reports
    
- Tickets
    
- Chat rooms
    
- Documentation
    
- Source repositories
    

Docker access is privileged access.

Treat inspection results as potentially sensitive.

---

# 39. `docker events`

Docker events show activity from the Docker daemon.

Run:

```bash
docker events
```

In another terminal:

```bash
docker run --rm alpine echo hello
```

You may see events such as:

```text
create
attach
start
die
destroy
```

Filter by container:

```bash
docker events \
  --filter container=day16-nginx
```

Filter by event type:

```bash
docker events \
  --filter event=die
```

Show recent history:

```bash
docker events \
  --since 10m
```

Events help answer:

```text
Was the container restarted?
Who or what stopped it?
Was it killed?
Was a network connected?
Was a health status changed?
```

---

# 40. Health events

Run:

```bash
docker events \
  --filter container=day16-unhealthy
```

Then make it healthy:

```bash
docker exec day16-unhealthy \
  touch /tmp/healthy
```

Remove the health marker:

```bash
docker exec day16-unhealthy \
  rm /tmp/healthy
```

You may see events resembling:

```text
health_status: healthy
health_status: unhealthy
```

This allows monitoring systems to react to health transitions.

---

# 41. Restart policies

Common restart policies are:

|Policy|Behavior|
|---|---|
|`no`|Do not automatically restart|
|`on-failure`|Restart after non-zero exit|
|`always`|Restart unless Docker itself prevents it|
|`unless-stopped`|Restart except when deliberately stopped|

Examples:

```bash
docker run -d \
  --restart on-failure:5 \
  IMAGE
```

```bash
docker run -d \
  --restart unless-stopped \
  IMAGE
```

Inspect:

```bash
docker inspect CONTAINER \
  --format '{{.HostConfig.RestartPolicy.Name}}'
```

---

# 42. Test `on-failure`

Run:

```bash
docker run -d \
  --name day16-restart-failure \
  --restart on-failure:3 \
  alpine \
  sh -c '
    echo "Starting and failing"
    sleep 1
    exit 1
  '
```

Observe:

```bash
docker ps -a \
  --filter name=day16-restart-failure
```

Inspect restart count:

```bash
docker inspect day16-restart-failure \
  --format 'Status={{.State.Status}} RestartCount={{.RestartCount}} ExitCode={{.State.ExitCode}}'
```

View logs:

```bash
docker logs day16-restart-failure
```

The same message may appear several times.

---

# 43. Restart loops

A restart policy can hide a persistent configuration error.

Example:

```text
Application starts
Missing DB_PASSWORD
Application exits
Docker restarts it
Application exits again
```

Symptoms:

```bash
docker ps
```

shows:

```text
Restarting
```

Diagnose:

```bash
docker logs --tail 100 CONTAINER
```

```bash
docker inspect CONTAINER \
  --format 'RestartCount={{.RestartCount}} Exit={{.State.ExitCode}} Error={{.State.Error}}'
```

Do not “fix” a restart loop by increasing restart attempts.

Fix the underlying failure.

---

# 44. Update restart policy

For an existing container:

```bash
docker update \
  --restart unless-stopped \
  CONTAINER
```

Disable automatic restart:

```bash
docker update \
  --restart no \
  CONTAINER
```

For Compose:

```yaml
services:
  api:
    restart: unless-stopped
```

Then apply:

```bash
docker compose up -d
```

---

# 45. `docker stop` versus `docker kill`

`docker stop` performs a graceful sequence:

```text
Send SIGTERM
Wait for stop timeout
Send SIGKILL if still running
```

Use:

```bash
docker stop CONTAINER
```

`docker kill` sends a signal immediately, by default `SIGKILL`:

```bash
docker kill CONTAINER
```

You can send another signal:

```bash
docker kill \
  --signal SIGTERM \
  CONTAINER
```

Normal operations should prefer graceful stopping.

Databases and stateful services especially need time to shut down safely.

---

# 46. Stop timeout

Set a stop timeout during creation:

```bash
docker run -d \
  --stop-timeout 30 \
  IMAGE
```

In Compose:

```yaml
services:
  api:
    stop_grace_period: 30s
```

Your application should respond to `SIGTERM`.

For a C service:

```c
signal(SIGTERM, handle_signal);
```

For process managers and web servers, verify that the actual application process receives the signal.

Exec-form `CMD` and `ENTRYPOINT` help avoid unnecessary signal-forwarding problems.

---

# 47. Pause and unpause

Pause a container:

```bash
docker pause day16-logger
```

Check:

```bash
docker ps \
  --filter name=day16-logger
```

The processes are frozen.

Unpause:

```bash
docker unpause day16-logger
```

Pausing is not the same as graceful application shutdown.

Use cases are limited:

- Debugging
    
- Temporary resource suspension
    
- Controlled experiments
    

Do not treat pause as normal service maintenance.

---

# 48. Inspect filesystem changes

Use:

```bash
docker diff CONTAINER
```

This reports files:

```text
A
→ added

C
→ changed

D
→ deleted
```

This can reveal unexpected behavior:

```text
Application writes logs into image filesystem
Configuration is modified at runtime
Cache directory grows
Database file is not on a volume
```

For your MQTT dashboard, unexpected output might reveal:

```text
C /var/www/html/data/cockpit.sqlite
```

If that path is not mounted persistently, database data is at risk.

---

# 49. Inspect mounts

Use:

```bash
docker inspect CONTAINER \
  --format '{{range .Mounts}}Type={{.Type}} Name={{.Name}} Source={{.Source}} Destination={{.Destination}} RW={{.RW}}{{println}}{{end}}'
```

Questions to answer:

- Is the expected volume attached?
    
- Is the source correct?
    
- Is the target correct?
    
- Is the mount writable?
    
- Was an anonymous volume created?
    
- Is a bind mount hiding application files?
    

Many “application” errors are actually mount configuration errors.

---

# 50. Inspect networking

Use:

```bash
docker inspect CONTAINER \
  --format '{{range $name, $network := .NetworkSettings.Networks}}Network={{$name}} IP={{$network.IPAddress}} Gateway={{$network.Gateway}} Aliases={{json $network.Aliases}}{{println}}{{end}}'
```

Then test from another container on the same network:

```bash
docker run --rm \
  --network NETWORK \
  alpine \
  getent hosts SERVICE
```

Test a TCP port:

```bash
docker run --rm \
  --network NETWORK \
  alpine \
  sh -c '
    apk add --no-cache busybox-extras >/dev/null &&
    nc -vz SERVICE PORT
  '
```

Separate:

```text
DNS problem
TCP problem
Protocol problem
Authentication problem
```

---

# 51. A systematic troubleshooting workflow

When a containerized service fails, follow this sequence.

## Step 1 — Identify the affected container

```bash
docker ps -a
```

For Compose:

```bash
docker compose ps -a
```

## Step 2 — Inspect status and exit evidence

```bash
docker inspect CONTAINER \
  --format 'Status={{.State.Status}} Exit={{.State.ExitCode}} OOM={{.State.OOMKilled}} Error={{.State.Error}} RestartCount={{.RestartCount}}'
```

## Step 3 — Read logs

```bash
docker logs \
  --tail 100 \
  --timestamps \
  CONTAINER
```

## Step 4 — Inspect startup configuration

```bash
docker inspect CONTAINER \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'
```

## Step 5 — Inspect environment

```bash
docker inspect CONTAINER \
  --format '{{json .Config.Env}}'
```

Redact secrets.

## Step 6 — Inspect mounts

```bash
docker inspect CONTAINER \
  --format '{{json .Mounts}}'
```

## Step 7 — Inspect networks

```bash
docker inspect CONTAINER \
  --format '{{json .NetworkSettings.Networks}}'
```

## Step 8 — Check health history

```bash
docker inspect CONTAINER \
  --format '{{json .State.Health}}'
```

## Step 9 — Check resource usage

```bash
docker stats --no-stream CONTAINER
```

## Step 10 — Reproduce with a one-off diagnostic command

```bash
docker run --rm \
  --entrypoint sh \
  IMAGE \
  -c 'COMMAND'
```

Follow evidence rather than changing several things at once.

---

# 52. Failure category: command not found

Symptoms:

```text
executable file not found
Exit code 127
```

Inspect:

```bash
docker image inspect IMAGE \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'
```

Test:

```bash
docker run --rm \
  --entrypoint sh \
  IMAGE \
  -c 'command -v application || true'
```

Possible causes:

- Misspelled executable
    
- Package absent
    
- Wrong destination path
    
- `PATH` missing location
    
- Bind mount hiding executable
    
- Script interpreter absent
    

---

# 53. Failure category: permission denied

Symptoms:

```text
permission denied
Exit code 126
```

Inspect:

```bash
docker run --rm \
  --entrypoint ls \
  IMAGE \
  -l /path/to/application
```

Check runtime user:

```bash
docker image inspect IMAGE \
  --format '{{.Config.User}}'
```

Possible causes:

- File is not executable
    
- Directory traversal permission missing
    
- Mounted host file has wrong permissions
    
- Non-root user cannot access path
    
- Filesystem is read-only
    
- Security policy denies execution
    

Do not immediately use:

```bash
chmod -R 777
```

Find the minimum necessary permission.

---

# 54. Failure category: application exits immediately

Check:

```bash
docker ps -a
docker logs CONTAINER
```

Possible causes:

- Application completed normally
    
- Configuration missing
    
- Invalid command-line arguments
    
- Dependency unavailable
    
- Port binding failure
    
- Syntax error
    
- Runtime library absent
    
- Application daemonized and parent exited
    

A container remains running only while its main process remains running.

---

# 55. Failure category: service unreachable

First determine who is the client:

```text
Host?
Another container?
External machine?
```

For host access, check:

```bash
docker port CONTAINER
```

For container access, check shared networks and use:

```text
service-name:internal-port
```

Check the application listening address.

Inside a containerized server, use:

```text
0.0.0.0
```

not only:

```text
127.0.0.1
```

when other containers or published traffic must reach it.

---

# 56. Failure category: port already allocated

Symptoms:

```text
bind: address already in use
port is already allocated
```

Check Docker:

```bash
docker ps \
  --format 'table {{.Names}}\t{{.Ports}}'
```

Check host listeners:

```bash
sudo ss -lntp
```

Use another host port:

```bash
-p 8081:80
```

or stop the conflicting service.

Remember:

```text
Host ports must be unique.
Container ports may repeat across containers.
```

---

# 57. Failure category: volume data appears missing

Inspect:

```bash
docker inspect CONTAINER \
  --format '{{json .Mounts}}'
```

Check:

- Exact volume name
    
- Destination path
    
- Compose project name
    
- Whether a new volume was created
    
- Whether application writes to another directory
    
- Whether `down -v` was used
    
- Whether a bind-mount source changed
    

Use:

```bash
docker volume ls
```

and:

```bash
docker ps -a \
  --filter volume=VOLUME
```

Data often still exists in an old volume after a naming mistake.

---

# 58. Failure category: high disk use

Check host filesystem:

```bash
df -h
```

Check Docker:

```bash
docker system df -v
```

Potential causes:

- Images
    
- Build cache
    
- Container writable layers
    
- Named volumes
    
- Unrotated logs
    
- Database growth
    
- Uploads
    
- Anonymous volumes
    

Do not start with:

```bash
docker system prune -a --volumes
```

That can delete important resources.

Identify the consumer first.

---

# 59. Failure category: high CPU

Use:

```bash
docker stats
docker top CONTAINER
```

Inspect application logs:

```bash
docker logs --tail 200 CONTAINER
```

Possible causes:

- Busy loop
    
- Too many workers
    
- Repeated connection retries
    
- Excessive logging
    
- Compression/encryption
    
- Expensive SQL queries
    
- Application deadlock with spinning
    
- Malformed input triggering heavy work
    

Apply a CPU limit as protection, but still fix the cause.

---

# 60. Failure category: high memory

Use:

```bash
docker stats
```

Inspect limits:

```bash
docker inspect CONTAINER \
  --format 'MemoryLimit={{.HostConfig.Memory}} OOMKilled={{.State.OOMKilled}}'
```

Possible causes:

- Memory leak
    
- Oversized cache
    
- Too many workers
    
- Large response buffering
    
- Unbounded queues
    
- Large in-memory files
    
- Incorrect JVM/runtime memory configuration
    

Resource limits reduce host risk but do not repair memory leaks.

---

# 61. Debug a stopped container

You cannot normally use:

```bash
docker exec
```

on a stopped container.

Instead inspect:

```bash
docker logs STOPPED_CONTAINER
docker inspect STOPPED_CONTAINER
```

Then start a new diagnostic container from the same image with a replaced entrypoint:

```bash
docker run --rm -it \
  --entrypoint sh \
  IMAGE
```

Apply the same environment and mounts if they are relevant.

For Compose:

```bash
docker compose run --rm \
  --entrypoint sh \
  SERVICE
```

This creates a one-off diagnostic container.

---

# 62. Debug a minimal image without a shell

Some production images do not include:

```text
/bin/sh
/bin/bash
```

This is common with distroless or scratch-based images.

You cannot use:

```bash
docker exec -it CONTAINER sh
```

Use:

- `docker logs`
    
- `docker inspect`
    
- `docker top`
    
- `docker stats`
    
- Health information
    
- A debug image/stage
    
- A separate diagnostic container sharing the network
    
- Host-side namespace tools where appropriate
    

Minimal production images require stronger external observability.

---

# 63. Compose troubleshooting

List state:

```bash
docker compose ps -a
```

Follow logs:

```bash
docker compose logs -f
```

Inspect one service:

```bash
docker inspect \
  "$(docker compose ps -q api)"
```

Execute in a running service:

```bash
docker compose exec api COMMAND
```

Run a one-off diagnostic container:

```bash
docker compose run --rm \
  --entrypoint sh \
  api
```

Validate resolved configuration:

```bash
docker compose config
```

Check what environment values Compose used:

```bash
docker compose config --environment
```

Be careful: output may contain sensitive values.

---

# 64. Add observability to the device API stack

A useful Compose configuration is:

```yaml
services:
  api:
    build:
      context: .
    image: device-api:observability
    ports:
      - "8080:5000"
    environment:
      APP_ENV: production
      LOG_LEVEL: INFO

      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: device_monitor
      DB_USER: device_app
      DB_PASSWORD: development-password

      PYTHONUNBUFFERED: "1"
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
    mem_limit: 512m
    cpus: 1.0
    stop_grace_period: 30s
    logging:
      driver: local
      options:
        max-size: "10m"
        max-file: "3"
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
    restart: unless-stopped
    stop_grace_period: 60s
    logging:
      driver: local
      options:
        max-size: "20m"
        max-file: "5"
    networks:
      - backend

volumes:
  postgres-data:

networks:
  backend:
```

---

# 65. Test database health propagation

Start:

```bash
docker compose up -d --build
```

Check:

```bash
docker compose ps
```

Stop the database process by stopping its container:

```bash
docker compose stop database
```

Test the API:

```bash
curl -i http://localhost:8080/health
```

Depending on application error handling, it should return unhealthy or fail.

Inspect API health:

```bash
docker inspect \
  "$(docker compose ps -q api)" \
  --format '{{.State.Health.Status}}'
```

After repeated checks, the API may become unhealthy while remaining running.

Restart PostgreSQL:

```bash
docker compose start database
```

Watch recovery:

```bash
watch -n 2 'docker compose ps'
```

Stop `watch` with `Ctrl+C`.

---

# 66. Use a request correlation ID

For production troubleshooting, logs should connect related events.

Example request flow:

```text
Browser request
→ API request
→ Database query
→ MQTT action
→ Response
```

Assign a request ID:

```text
request_id=8bbdbf...
```

Include it in every related log line.

Example:

```text
timestamp=...
level=INFO
service=device-api
request_id=8bbdbf
method=POST
path=/api/devices
status=201
duration_ms=27
```

This is an application feature, not a Docker feature, but it makes Docker-collected logs far more useful.

---

# 67. Logs versus metrics versus traces

These are separate observability signals.

## Logs

Discrete events:

```text
Database connection failed
Device registered
Request returned 500
```

## Metrics

Numeric measurements over time:

```text
CPU percentage
Request count
Error rate
Response duration
MQTT messages per second
Active database connections
```

## Traces

A request’s journey through several components:

```text
Dashboard
→ API
→ Database
→ MQTT broker
```

Today you are using basic Docker logs and statistics.

Later production systems commonly use:

- Prometheus
    
- Grafana
    
- Loki
    
- OpenTelemetry
    
- Elasticsearch
    
- Other observability platforms
    

---

# 68. Day 16 practical laboratory

## Exercise 1 — Container states

Create containers that end with:

```text
Exit 0
Exit 1
Exit 42
```

Inspect their statuses and exit codes.

---

## Exercise 2 — Signal exit

Start a long-running container.

Stop it gracefully.

Inspect the exit code.

Start another and kill it with `SIGKILL`.

Compare the results.

---

## Exercise 3 — Logs

Create a service that writes:

- Normal messages to stdout
    
- Errors to stderr
    
- One message every two seconds
    

Use:

```bash
docker logs
docker logs -f
docker logs --tail
docker logs --since
docker logs --timestamps
```

---

## Exercise 4 — Health status

Create a health check based on a file.

Observe:

```text
starting
unhealthy
healthy
```

Create and remove the health marker while the container continues running.

---

## Exercise 5 — HTTP health check

Start Nginx with an HTTP health check.

Inspect the health-check history.

Break the server configuration or stop its worker process and observe the result.

---

## Exercise 6 — Resource monitoring

Create one CPU-intensive container and one memory-intensive container.

Use:

```bash
docker stats
docker top
```

Remove them after observation.

---

## Exercise 7 — Memory limit

Run the memory-growing Python program with a 100 MB limit.

Inspect:

- Exit code
    
- `OOMKilled`
    
- Logs
    
- Restart count if a policy is configured
    

---

## Exercise 8 — Restart policy

Create a container that exits with code 1.

Use:

```text
on-failure:3
```

Observe restart count and repeated logs.

Fix the command rather than increasing restarts.

---

## Exercise 9 — Mount diagnosis

Run a container with a named volume.

Create data.

Inspect mounts and use `docker diff`.

Explain which changes belong to:

- Writable layer
    
- Volume
    

---

## Exercise 10 — Compose observability

Add to your API and database:

- Health checks
    
- Restart policies
    
- Log rotation
    
- Memory limits
    
- CPU limits
    
- Stop grace periods
    

Start the stack and inspect all settings.

---

# 69. Day 16 command reference

```bash
# List all container states
docker ps -a

# Inspect state and exit information
docker inspect CONTAINER \
  --format 'Status={{.State.Status}} Exit={{.State.ExitCode}} OOM={{.State.OOMKilled}} Error={{.State.Error}}'

# Read logs
docker logs CONTAINER

# Follow logs
docker logs -f CONTAINER

# Show recent timestamped logs
docker logs \
  --tail 100 \
  --timestamps \
  CONTAINER

# Show logs from a recent period
docker logs \
  --since 10m \
  CONTAINER

# Inspect health
docker inspect CONTAINER \
  --format '{{.State.Health.Status}}'

# Inspect health history
docker inspect CONTAINER \
  --format '{{json .State.Health}}'

# Monitor resources
docker stats

# One-time resource snapshot
docker stats --no-stream CONTAINER

# Inspect processes
docker top CONTAINER

# Inspect filesystem changes
docker diff CONTAINER

# Watch Docker daemon events
docker events

# Inspect restart count
docker inspect CONTAINER \
  --format '{{.RestartCount}}'

# Change restart policy
docker update \
  --restart unless-stopped \
  CONTAINER

# Graceful stop
docker stop CONTAINER

# Immediate kill
docker kill CONTAINER

# Compose state
docker compose ps -a

# Compose logs
docker compose logs -f

# Validate Compose configuration
docker compose config
```

---

# 70. Knowledge check

## Does `running` mean the application is healthy?

No. It means only that the container’s main process is running.

## What does a Docker health check do?

It periodically runs a command inside the container and records whether it succeeds.

## What are the health states?

```text
starting
healthy
unhealthy
```

## Does Docker automatically restart every unhealthy container?

No. A normal restart policy generally reacts to process exit, not merely health status.

## Where should container applications normally write logs?

To standard output and standard error.

## What does exit code 0 normally mean?

Successful completion.

## What does exit code 127 commonly indicate?

The requested command was not found.

## What can exit code 137 indicate?

The process received `SIGKILL`, commonly from an OOM kill or forced termination.

## How do you confirm an OOM kill?

Inspect:

```text
.State.OOMKilled
```

## What does `docker stats` show?

Live CPU, memory, network, block I/O, and process usage.

## What does `docker top` show?

Processes running inside the container.

## Why are restart loops dangerous?

They repeatedly run a broken service, consume resources, and can hide the underlying configuration error.

## What is the first command for a failed Compose project?

Usually:

```bash
docker compose ps -a
```

followed by:

```bash
docker compose logs
```

---

# 71. Day 16 completion challenge

Complete this independently:

1. Create a container that exits successfully.
    
2. Inspect exit code 0.
    
3. Create a container that exits with code 27.
    
4. Inspect its logs and exit code.
    
5. Create a container with a missing command.
    
6. Identify exit code 127 or the Docker startup error.
    
7. Create a non-executable script.
    
8. Diagnose the permission failure.
    
9. Create a continuously logging service.
    
10. Follow its logs.
    
11. Filter logs to the last five lines.
    
12. Show timestamps.
    
13. Show logs from the last minute.
    
14. Configure log rotation.
    
15. Create a file-based health check.
    
16. Observe the `starting` state.
    
17. Observe the `unhealthy` state.
    
18. Make the container healthy without restarting it.
    
19. Make it unhealthy again.
    
20. Inspect health-check history.
    
21. Create an HTTP health check.
    
22. Explain why checking only a PID is weak.
    
23. Create a CPU-intensive container.
    
24. Observe it with `docker stats`.
    
25. Inspect it with `docker top`.
    
26. Apply a CPU limit.
    
27. Compare resource usage.
    
28. Create a memory-growing container.
    
29. Apply a 100 MB memory limit.
    
30. Inspect whether it was OOM-killed.
    
31. Create an `on-failure:3` restart loop.
    
32. Inspect restart count.
    
33. Diagnose the real error from logs.
    
34. Add health checks to your API and PostgreSQL services.
    
35. Add restart policies.
    
36. Add log rotation.
    
37. Add memory and CPU limits.
    
38. Add graceful stop periods.
    
39. Stop PostgreSQL and observe API health.
    
40. Restart PostgreSQL and observe recovery.
    
41. Inspect mounts and networks for both services.
    
42. Use Docker events to observe start, stop, die, and health transitions.
    
43. Write a troubleshooting checklist for your MQTT dashboard.
    
44. Explain how you would distinguish DNS, TCP, authentication, and application failures.
    
45. Clean up the temporary Day 16 containers.
    

The central Day 16 model is:

```text
Container state
      +
Health status
      +
Application logs
      +
Exit information
      +
Processes
      +
Resource usage
      +
Networks and mounts
      ↓
Evidence-based diagnosis
```

The most important operational lesson is:

> Do not diagnose Docker problems from a single symptom. Start with container state and exit information, inspect logs and health history, verify resources, networks, and mounts, then reproduce the failing operation one layer at a time.