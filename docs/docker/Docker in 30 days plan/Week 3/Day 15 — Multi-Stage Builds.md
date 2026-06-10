#### Multi-Stage Builds and Production Image Optimization

Until now, most Dockerfiles used one build environment for everything:

```dockerfile
FROM python:3.13-slim

COPY requirements.txt .
RUN pip install -r requirements.txt

COPY . .

CMD ["python", "app.py"]
```

This is acceptable for simple interpreted applications. But compiled applications and applications with native dependencies often require tools that are needed only while building:

- Compilers
    
- Header files
    
- Linkers
    
- CMake
    
- Make
    
- Package-manager caches
    
- Source code
    
- Test tools
    
- Static-analysis tools
    

Those tools do not necessarily belong in the final production image.

Today you will learn to separate:

```text
Build environment
        ↓ produces artifacts
Runtime environment
        ↓ runs artifacts
```

Docker multi-stage builds allow a Dockerfile to contain multiple `FROM` instructions. Each one begins a separate stage, and selected artifacts can be copied from one stage into another. This lets the final image exclude build tools and other unnecessary files. ([Docker Documentation](https://docs.docker.com/build/building/multi-stage/?utm_source=chatgpt.com "Multi-stage builds"))

---

# 1. Day 15 objectives

By the end of Day 15, you should understand:

- What a multi-stage Dockerfile is
    
- Why build-time and runtime dependencies should be separated
    
- How to name build stages
    
- How `COPY --from` works
    
- How to build a specific stage
    
- How multi-stage builds reduce image size and attack surface
    
- How to containerize a compiled C application
    
- How to optimize a Python application image
    
- How build cache ordering affects build speed
    
- How BuildKit cache mounts work
    
- How `.dockerignore` reduces build context
    
- How `ARG` differs from `ENV`
    
- Why `ARG` and `ENV` must not hold secrets
    
- How build-secret mounts work
    
- How to inspect and compare final images
    
- How to debug a builder stage
    
- How to produce a production-ready runtime image
    

---

# 2. The problem with a single-stage build

Consider a C application.

A simple Dockerfile might look like:

```dockerfile
FROM debian:13

RUN apt-get update \
    && apt-get install -y \
       gcc \
       make \
       libc6-dev

WORKDIR /src

COPY . .

RUN gcc -Wall -Wextra -O2 \
    -o application \
    main.c

CMD ["./application"]
```

This works, but the final image contains:

```text
Compiler
Make
Development headers
Package metadata
Source code
Compiled binary
Runtime libraries
```

At runtime, the application generally needs only:

```text
Compiled binary
Required shared libraries
Configuration
Certificates
Runtime user
```

The compiler and source code are no longer needed.

---

# 3. Build dependencies versus runtime dependencies

A dependency can belong to one of two categories.

## Build dependency

Needed to produce the application artifact.

Examples:

```text
gcc
g++
clang
make
cmake
pkg-config
libmosquitto-dev
Python build headers
Node.js build tools
Source code
Unit-test tools
```

## Runtime dependency

Needed when the final application executes.

Examples:

```text
libc
libmosquitto runtime library
OpenSSL runtime library
CA certificates
Application executable
Application configuration
```

A production image should normally contain only the runtime requirements.

---

# 4. What a multi-stage Dockerfile looks like

A multi-stage Dockerfile contains multiple `FROM` instructions:

```dockerfile
FROM debian:13 AS builder

# Compile the application here.

FROM debian:13-slim AS runtime

# Copy only the compiled artifact.
COPY --from=builder /build/application /usr/local/bin/application

CMD ["application"]
```

The stages are:

```text
Stage 1: builder
- Compiler
- Headers
- Source
- Build process
- Compiled artifact

Stage 2: runtime
- Minimal runtime system
- Compiled artifact
- Runtime libraries
```

The final image is normally produced from the last stage unless another target is specified.

---

# 5. Naming stages

Name a stage with:

```dockerfile
FROM debian:13 AS builder
```

The name is:

```text
builder
```

You can then reference it:

```dockerfile
COPY --from=builder \
    /build/application \
    /usr/local/bin/application
```

Without a name, you could reference the stage numerically:

```dockerfile
COPY --from=0 \
    /build/application \
    /usr/local/bin/application
```

Named stages are preferable because they remain understandable even if Dockerfile stages are reordered.

---

# 6. How `COPY --from` works

Normal `COPY` reads from the build context:

```dockerfile
COPY main.c /src/main.c
```

Multi-stage copy reads from another stage:

```dockerfile
COPY --from=builder \
    /build/application \
    /usr/local/bin/application
```

This means:

```text
Source:
filesystem of stage named builder

Path:
/build/application

Destination:
current stage filesystem

Path:
/usr/local/bin/application
```

Only the selected artifact is copied.

The builder’s compiler, source files, and temporary objects remain outside the final runtime image.

---

# 7. First multi-stage project: a C HTTP-style utility

Create the project:

```bash
mkdir -p ~/docker-course/day15/c-application
cd ~/docker-course/day15/c-application
```

Create:

```text
c-application/
├── Dockerfile
├── Makefile
└── main.c
```

---

# 8. Create the C application

Create `main.c`:

```c
#include <signal.h>
#include <stdbool.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>

static volatile sig_atomic_t keep_running = 1;

static void handle_signal(int signal_number)
{
    printf(
        "Received signal %d, shutting down\n",
        signal_number
    );

    keep_running = 0;
}

int main(void)
{
    if (signal(SIGINT, handle_signal) == SIG_ERR) {
        perror("signal SIGINT");
        return EXIT_FAILURE;
    }

    if (signal(SIGTERM, handle_signal) == SIG_ERR) {
        perror("signal SIGTERM");
        return EXIT_FAILURE;
    }

    printf(
        "Day 15 C application started. PID=%ld\n",
        (long)getpid()
    );

    fflush(stdout);

    while (keep_running) {
        printf("Application heartbeat\n");
        fflush(stdout);
        sleep(5);
    }

    printf("Application stopped cleanly\n");
    fflush(stdout);

    return EXIT_SUCCESS;
}
```

This program:

- Runs continuously
    
- Prints heartbeats
    
- Handles `SIGINT`
    
- Handles `SIGTERM`
    
- Shuts down cleanly
    

It behaves like a small service or daemon, but it remains in the foreground as a container process.

---

# 9. Create the Makefile

Create `Makefile`:

```makefile
CC := gcc
CFLAGS := -std=c11 -Wall -Wextra -Wpedantic -O2
TARGET := day15-service

.PHONY: all clean

all: $(TARGET)

$(TARGET): main.c
	$(CC) $(CFLAGS) -o $(TARGET) main.c

clean:
	rm -f $(TARGET)
```

Important: the command lines under the targets must begin with a tab.

Test locally if GCC exists:

```bash
make
```

Run:

```bash
./day15-service
```

Stop it with:

```text
Ctrl+C
```

Clean:

```bash
make clean
```

The local compiler is optional because Docker will perform the actual build.

---

# 10. Write the first multi-stage Dockerfile

Create `Dockerfile`:

```dockerfile
FROM debian:13 AS builder

RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       gcc \
       make \
       libc6-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /src

COPY Makefile main.c ./

RUN make

FROM debian:13-slim AS runtime

RUN groupadd --system appgroup \
    && useradd \
       --system \
       --gid appgroup \
       --home-dir /nonexistent \
       --shell /usr/sbin/nologin \
       appuser

COPY --from=builder \
    /src/day15-service \
    /usr/local/bin/day15-service

USER appuser

ENTRYPOINT ["/usr/local/bin/day15-service"]
```

---

# 11. Understand the builder stage

The builder stage is:

```dockerfile
FROM debian:13 AS builder
```

It installs:

```text
gcc
make
libc6-dev
```

It copies:

```text
Makefile
main.c
```

It executes:

```dockerfile
RUN make
```

At the end, the builder contains:

```text
/src/main.c
/src/Makefile
/src/day15-service
Compiler
Headers
Build tools
```

This stage is not the final production image.

---

# 12. Understand the runtime stage

The runtime stage starts fresh:

```dockerfile
FROM debian:13-slim AS runtime
```

It does not inherit the builder filesystem.

It creates a non-root user and copies only:

```text
/src/day15-service
```

from the builder.

The resulting runtime image contains:

```text
Minimal Debian runtime
C standard runtime libraries
Application binary
Non-root user
Entrypoint
```

It does not contain:

```text
GCC
Make
Development headers
Source code
Object files
```

Separating the build environment from the final runtime environment can significantly reduce final image size and the number of components present in production. ([Docker Documentation](https://docs.docker.com/get-started/docker-concepts/building-images/multi-stage-builds/?utm_source=chatgpt.com "Multi-stage builds"))

---

# 13. Build the image

Run:

```bash
docker build \
  -t day15-c-service:1.0 \
  .
```

Inspect the build output.

You should see two stages:

```text
builder
runtime
```

List the image:

```bash
docker image ls day15-c-service
```

Run it:

```bash
docker run --rm \
  --name day15-c-service \
  day15-c-service:1.0
```

You should see heartbeats.

In another terminal:

```bash
docker stop day15-c-service
```

The application should receive `SIGTERM` and exit cleanly.

---

# 14. Confirm the compiler is absent

Run:

```bash
docker run --rm \
  day15-c-service:1.0 \
  /usr/bin/gcc --version
```

This should fail because `gcc` is not in the runtime image.

However, because the image uses `ENTRYPOINT`, the supplied command will normally be appended to the entrypoint rather than replacing it.

Override the entrypoint:

```bash
docker run --rm \
  --entrypoint sh \
  day15-c-service:1.0 \
  -c 'command -v gcc || echo "gcc is not installed"'
```

Expected:

```text
gcc is not installed
```

Check whether source code exists:

```bash
docker run --rm \
  --entrypoint sh \
  day15-c-service:1.0 \
  -c 'find / -name main.c 2>/dev/null'
```

It should not find your source file.

---

# 15. Build the builder stage directly

You can stop the build at a named stage:

```bash
docker build \
  --target builder \
  -t day15-c-service:builder \
  .
```

Run an interactive shell:

```bash
docker run --rm -it \
  day15-c-service:builder \
  bash
```

Inside:

```bash
gcc --version
ls -la /src
file /src/day15-service
```

Exit:

```bash
exit
```

Building a specific target is useful for:

- Debugging build failures
    
- Inspecting intermediate artifacts
    
- Running tests
    
- Running static analysis
    
- Creating separate development images
    

Docker supports stopping at a named stage with `--target`. ([Docker Documentation](https://docs.docker.com/build/building/multi-stage/?utm_source=chatgpt.com "Multi-stage builds"))

---

# 16. Compare builder and runtime image sizes

List:

```bash
docker image ls \
  day15-c-service
```

You should see:

```text
day15-c-service:builder
day15-c-service:1.0
```

The builder image should normally be larger because it includes:

- Compiler
    
- Headers
    
- Package-manager content
    
- Source
    
- Build artifacts
    

Inspect exact sizes:

```bash
docker image inspect \
  day15-c-service:builder \
  --format '{{.Size}}'
```

```bash
docker image inspect \
  day15-c-service:1.0 \
  --format '{{.Size}}'
```

Use:

```bash
docker system df -v
```

to understand shared and unique layer storage.

---

# 17. Dynamic versus static linking

Run:

```bash
docker run --rm \
  --entrypoint sh \
  day15-c-service:1.0 \
  -c 'ldd /usr/local/bin/day15-service'
```

You will likely see dependencies such as:

```text
libc.so.6
dynamic linker
```

This means the executable is dynamically linked.

The runtime image must contain compatible runtime libraries.

A statically linked executable embeds many library components directly into the binary and may run in a smaller or more minimal image.

However, static linking has trade-offs:

- Larger binary
    
- Licensing considerations
    
- Different DNS and libc behavior
    
- More complex security-update strategy
    
- Library compatibility issues
    
- Some libraries cannot be easily linked statically
    

Do not assume static is automatically better.

---

# 18. Why not copy random libraries manually?

A beginner may try:

```dockerfile
COPY --from=builder /lib/x86_64-linux-gnu/libc.so.6 /lib/
```

This is fragile because a dynamically linked program may require:

- Several shared libraries
    
- A dynamic linker
    
- Correct library paths
    
- Compatible glibc versions
    
- CA certificates
    
- Name-service configuration
    

It is safer to use a compatible runtime base and install documented runtime packages.

For example:

```dockerfile
FROM debian:13-slim
```

matches the builder’s Debian generation.

---

# 19. Multi-stage build for your MQTT C daemon

Your real MQTT daemon may require:

```text
gcc
make
pkg-config
libmosquitto-dev
```

during build.

At runtime, it may require only:

```text
Compiled daemon
libmosquitto runtime library
libc
OpenSSL runtime libraries
CA certificates
Configuration
```

A suitable starting structure is:

```dockerfile
FROM debian:13 AS builder

RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       build-essential \
       pkg-config \
       libmosquitto-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /src

COPY . .

RUN make clean \
    && make

FROM debian:13-slim AS runtime

RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       libmosquitto1 \
       ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --system mqttgroup \
    && useradd \
       --system \
       --gid mqttgroup \
       --home-dir /nonexistent \
       --shell /usr/sbin/nologin \
       mqttuser

COPY --from=builder \
    /src/build/mqtt-service-daemon \
    /usr/local/bin/mqtt-service-daemon

USER mqttuser

ENTRYPOINT ["/usr/local/bin/mqtt-service-daemon"]
CMD ["--config", "/etc/mqtt-service-daemon/config.conf"]
```

You would need to adjust:

```text
Build output path
Runtime library package
Configuration path
Command-line arguments
Writable directories
```

to match your actual project.

---

# 20. `ENTRYPOINT` and `CMD` in a service image

The MQTT example uses:

```dockerfile
ENTRYPOINT ["/usr/local/bin/mqtt-service-daemon"]
CMD ["--config", "/etc/mqtt-service-daemon/config.conf"]
```

The default execution becomes:

```text
/usr/local/bin/mqtt-service-daemon
--config
/etc/mqtt-service-daemon/config.conf
```

You can override the arguments:

```bash
docker run --rm \
  mqtt-daemon:1.0 \
  --config /run/config.conf
```

This becomes:

```text
mqtt-service-daemon --config /run/config.conf
```

The executable remains fixed.

This pattern is useful when the image represents one specific command-line application.

---

# 21. Add a test stage

A multi-stage build can contain more than builder and runtime stages.

Example:

```dockerfile
FROM builder AS test

RUN make test
```

Full structure:

```dockerfile
FROM debian:13 AS builder

# Install tools and build.

FROM builder AS test

RUN make test

FROM debian:13-slim AS runtime

# Copy production artifact.
```

Build the tests:

```bash
docker build \
  --target test \
  -t day15-c-service:test \
  .
```

Build production:

```bash
docker build \
  -t day15-c-service:1.0 \
  .
```

The production image does not contain the test tools or test outputs unless explicitly copied.

---

# 22. Add a debugging stage

You could create:

```dockerfile
FROM runtime AS debug

USER root

RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       gdb \
       strace \
       procps \
       iproute2 \
    && rm -rf /var/lib/apt/lists/*

USER appuser
```

Build:

```bash
docker build \
  --target debug \
  -t day15-c-service:debug \
  .
```

Production remains minimal:

```text
day15-c-service:1.0
```

Debug version contains diagnostic tools:

```text
day15-c-service:debug
```

Do not install debugging tools into production simply because they may occasionally be useful.

Use a separate debug target or a dedicated diagnostic container.

---

# 23. Multi-stage Python build

Python is interpreted, but multi-stage builds can still help when dependencies require compilation.

For example, some Python packages require:

```text
gcc
Python development headers
PostgreSQL development headers
Rust compiler
Build tools
```

Those tools are not needed after wheels are built.

A useful pattern is:

```dockerfile
FROM python:3.13-slim AS builder

RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       build-essential \
       libpq-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /build

COPY requirements.txt .

RUN pip wheel \
    --no-cache-dir \
    --wheel-dir /wheels \
    -r requirements.txt

FROM python:3.13-slim AS runtime

RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       libpq5 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=builder /wheels /wheels
COPY requirements.txt .

RUN pip install \
    --no-cache-dir \
    --no-index \
    --find-links=/wheels \
    -r requirements.txt \
    && rm -rf /wheels

COPY app.py .
COPY templates/ ./templates/

CMD ["gunicorn", "--bind", "0.0.0.0:5000", "app:app"]
```

---

# 24. Why build Python wheels?

The builder executes:

```bash
pip wheel
```

This produces installable package archives under:

```text
/wheels
```

The runtime stage installs from those prepared artifacts:

```bash
pip install \
  --no-index \
  --find-links=/wheels \
  -r requirements.txt
```

Therefore, the runtime stage does not need:

```text
C compiler
Development headers
Package download cache
Source distributions
```

This is especially useful when dependencies contain native extensions.

---

# 25. Use a virtual environment as the copied artifact

Another Python pattern is to build a virtual environment:

```dockerfile
FROM python:3.13-slim AS builder

WORKDIR /app

RUN python -m venv /opt/venv

ENV PATH="/opt/venv/bin:$PATH"

COPY requirements.txt .

RUN pip install \
    --no-cache-dir \
    -r requirements.txt

FROM python:3.13-slim AS runtime

COPY --from=builder \
    /opt/venv \
    /opt/venv

ENV PATH="/opt/venv/bin:$PATH"

WORKDIR /app

COPY app.py .
COPY templates/ ./templates/

CMD ["gunicorn", "--bind", "0.0.0.0:5000", "app:app"]
```

This works best when builder and runtime stages use compatible Python versions and operating-system libraries.

---

# 26. Multi-stage Node.js pattern

A frontend or Node.js build may use:

```dockerfile
FROM node:24-bookworm-slim AS builder

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY . .

RUN npm run build

FROM nginx:alpine AS runtime

COPY --from=builder \
    /app/dist \
    /usr/share/nginx/html
```

The final image contains:

```text
Nginx
Built static files
```

It does not contain:

```text
Node.js
npm
Source code
Development dependencies
node_modules
Build configuration
```

This is one of the clearest uses of multi-stage builds.

---

# 27. Build-cache fundamentals

Docker evaluates Dockerfile instructions in order.

When a layer cannot be reused, later dependent layers normally need to be rebuilt.

Consider:

```dockerfile
COPY . .

RUN pip install -r requirements.txt
```

Changing any source file invalidates `COPY . .`.

That causes dependency installation to rerun even when `requirements.txt` did not change.

Better:

```dockerfile
COPY requirements.txt .

RUN pip install -r requirements.txt

COPY . .
```

Now source-code changes do not invalidate the dependency layer.

Docker recommends ordering expensive, stable steps before frequently changing steps to make better use of the build cache. ([Docker Documentation](https://docs.docker.com/build/cache/optimize/?utm_source=chatgpt.com "Optimize cache usage in builds"))

---

# 28. Cache invalidation example

Create:

```bash
mkdir -p ~/docker-course/day15/cache-demo
cd ~/docker-course/day15/cache-demo
```

Create `requirements.txt`:

```text
Flask==3.1.1
```

Create `app.py`:

```python
print("Cache demonstration")
```

Create:

```dockerfile
FROM python:3.13-slim

WORKDIR /app

COPY requirements.txt .

RUN pip install \
    --no-cache-dir \
    -r requirements.txt

COPY app.py .

CMD ["python", "app.py"]
```

Build:

```bash
docker build \
  -t day15-cache-demo:1 \
  .
```

Build again:

```bash
docker build \
  -t day15-cache-demo:2 \
  .
```

Most steps should be cached.

Modify only `app.py`:

```bash
echo 'print("Source changed")' > app.py
```

Build:

```bash
docker build \
  -t day15-cache-demo:3 \
  .
```

The dependency installation should remain cached.

---

# 29. `.dockerignore` and the build context

Before Docker can execute many build steps, it processes the build context.

A large context can contain:

- `.git`
    
- Virtual environments
    
- Build outputs
    
- Database files
    
- Logs
    
- IDE configuration
    
- Test coverage
    
- Backup files
    
- Secrets
    
- `node_modules`
    

Create a `.dockerignore`:

```text
.git/
.gitignore

__pycache__/
*.pyc
*.pyo

.venv/
venv/

build/
dist/
*.o

*.log
*.sqlite
*.sqlite-wal
*.sqlite-shm

.env
*.env

backups/
```

Keeping the build context small reduces unnecessary file transfer and can improve cache behavior. ([Docker Documentation](https://docs.docker.com/build/cache/optimize/?utm_source=chatgpt.com "Optimize cache usage in builds"))

---

# 30. Inspect build-context size

Build with plain progress:

```bash
docker build \
  --progress=plain \
  -t day15-cache-demo:context-test \
  .
```

Look for context-transfer output.

Create an unnecessary file:

```bash
dd if=/dev/zero \
  of=large-debug-file.bin \
  bs=1M \
  count=50
```

Build again and observe the context.

Then add:

```text
large-debug-file.bin
```

to `.dockerignore`.

Build again.

Remove the training file:

```bash
rm -f large-debug-file.bin
```

---

# 31. BuildKit cache mounts

A normal build layer may repeatedly download package-manager content.

BuildKit supports cache mounts that persist package caches between builds without copying the cache into the final image layer.

For `apt`:

```dockerfile
# syntax=docker/dockerfile:1

FROM debian:13 AS builder

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       gcc \
       make \
       libc6-dev
```

For `pip`:

```dockerfile
RUN --mount=type=cache,target=/root/.cache/pip \
    pip install \
    -r requirements.txt
```

Docker identifies cache mounts as a way to reuse package-manager download caches across builds and improve build speed. ([Docker Documentation](https://docs.docker.com/build/cache/optimize/?utm_source=chatgpt.com "Optimize cache usage in builds"))

---

# 32. Cache mount versus image layer

A cache mount:

```dockerfile
RUN --mount=type=cache,target=/root/.cache/pip ...
```

is available while that build step executes.

It is not copied into the final image automatically.

This differs from:

```dockerfile
RUN pip install ...
```

where installed packages become part of the image filesystem layer.

The cache holds reusable downloaded artifacts.

The image layer holds the installed runtime result.

---

# 33. Improve the Python build with a cache mount

Use:

```dockerfile
# syntax=docker/dockerfile:1

FROM python:3.13-slim AS builder

WORKDIR /build

COPY requirements.txt .

RUN --mount=type=cache,target=/root/.cache/pip \
    pip wheel \
    --wheel-dir /wheels \
    -r requirements.txt
```

The first build downloads packages.

Subsequent builds can reuse cached downloads when appropriate.

Do not combine:

```text
Build cache
```

with:

```text
Production application cache
```

Build caches exist to accelerate image creation.

---

# 34. `ARG` variables

`ARG` defines a build-time variable.

Example:

```dockerfile
ARG APP_VERSION=1.0.0

RUN echo "Building version ${APP_VERSION}"
```

Build:

```bash
docker build \
  --build-arg APP_VERSION=2.0.0 \
  -t application:2.0.0 \
  .
```

Build arguments are useful for parameterizing Dockerfile behavior, such as dependency versions or metadata. ([Docker Documentation](https://docs.docker.com/build/building/variables/?utm_source=chatgpt.com "Build variables"))

---

# 35. `ARG` scope

Example:

```dockerfile
ARG DEBIAN_VERSION=13

FROM debian:${DEBIAN_VERSION} AS builder
```

An `ARG` declared before the first `FROM` can be used in `FROM`.

To use it again inside a stage, redeclare it:

```dockerfile
ARG DEBIAN_VERSION=13

FROM debian:${DEBIAN_VERSION} AS builder

ARG DEBIAN_VERSION

RUN echo "Building with Debian ${DEBIAN_VERSION}"
```

Build arguments have scope rules. Do not assume every stage automatically receives every `ARG`.

---

# 36. `ARG` versus `ENV`

## `ARG`

Primarily available during image build:

```dockerfile
ARG BUILD_MODE=release
```

Supply with:

```bash
docker build \
  --build-arg BUILD_MODE=debug \
  .
```

## `ENV`

Stored as image/runtime configuration:

```dockerfile
ENV APP_ENV=production
```

Available when the container runs:

```bash
docker run image env
```

A common pattern is:

```dockerfile
ARG APP_VERSION=unknown
ENV APP_VERSION=${APP_VERSION}
```

This copies the build-time value into the image’s runtime environment.

---

# 37. Add OCI image metadata from build arguments

Example:

```dockerfile
ARG APP_VERSION=unknown
ARG VCS_REVISION=unknown
ARG BUILD_DATE=unknown

LABEL org.opencontainers.image.version="${APP_VERSION}"
LABEL org.opencontainers.image.revision="${VCS_REVISION}"
LABEL org.opencontainers.image.created="${BUILD_DATE}"
```

Build:

```bash
docker build \
  --build-arg APP_VERSION=1.5.0 \
  --build-arg VCS_REVISION="$(git rev-parse HEAD)" \
  --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  -t application:1.5.0 \
  .
```

Inspect:

```bash
docker image inspect application:1.5.0 \
  --format '{{json .Config.Labels}}'
```

This helps connect an image to:

- Source revision
    
- Release version
    
- Build date
    

---

# 38. Do not use `ARG` for secrets

This is unsafe:

```dockerfile
ARG PRIVATE_TOKEN

RUN curl \
    -H "Authorization: Bearer ${PRIVATE_TOKEN}" \
    https://private.example/package
```

Build:

```bash
docker build \
  --build-arg PRIVATE_TOKEN=secret \
  .
```

Build arguments and environment variables are inappropriate for build secrets because sensitive values may persist in image metadata, history, cache, or provenance. Docker recommends secret mounts or SSH mounts instead. ([Docker Documentation](https://docs.docker.com/build/building/secrets/?utm_source=chatgpt.com "Build secrets"))

Do not use:

```dockerfile
ARG PASSWORD
ENV PASSWORD=...
```

for credentials.

---

# 39. Build secrets

BuildKit supports secret mounts.

Dockerfile:

```dockerfile
# syntax=docker/dockerfile:1

FROM alpine AS builder

RUN --mount=type=secret,id=private_token \
    TOKEN="$(cat /run/secrets/private_token)" \
    && echo "Use token without storing it in the image" \
    && test -n "$TOKEN"
```

Create a local secret file:

```bash
printf '%s' 'development-secret' \
  > private-token.txt
```

Build:

```bash
docker build \
  --secret id=private_token,src=private-token.txt \
  -t day15-secret-demo:1.0 \
  .
```

During the `RUN` step, the secret appears at:

```text
/run/secrets/private_token
```

It is not copied into the final image unless your Dockerfile explicitly does something unsafe with it.

Build secret mounts expose sensitive information temporarily to a build step rather than persisting it through ordinary `ARG` or `ENV` instructions. ([Docker Documentation](https://docs.docker.com/build/building/secrets/?utm_source=chatgpt.com "Build secrets"))

---

# 40. Never copy a secret into an image

This defeats the purpose:

```dockerfile
RUN --mount=type=secret,id=private_token \
    cp /run/secrets/private_token /app/token.txt
```

The secret becomes part of the image layer.

Similarly, this is unsafe:

```dockerfile
RUN --mount=type=secret,id=private_token \
    echo "$(cat /run/secrets/private_token)" \
    > /app/config
```

Use the secret only to authenticate a temporary build operation, such as:

- Downloading a private package
    
- Accessing a private repository
    
- Authenticating to a dependency service
    

The resulting downloaded artifact may be copied forward, but not the credential.

---

# 41. SSH mounts

For private Git repositories, BuildKit can temporarily expose an SSH agent:

```dockerfile
# syntax=docker/dockerfile:1

FROM alpine AS builder

RUN apk add --no-cache git openssh-client

RUN --mount=type=ssh \
    git clone \
    git@example.com:company/private-project.git \
    /src/private-project
```

Build:

```bash
docker build \
  --ssh default \
  -t private-build:1.0 \
  .
```

This is preferable to copying a private key into the build context or image.

Docker Build supports secret mounts and SSH mounts for sensitive build-time access. ([Docker Documentation](https://docs.docker.com/build/building/secrets/?utm_source=chatgpt.com "Build secrets"))

---

# 42. Bind mounts during build

BuildKit can also mount source files temporarily during a `RUN` instruction.

Example:

```dockerfile
# syntax=docker/dockerfile:1

FROM gcc:15 AS builder

WORKDIR /build

RUN --mount=type=bind,source=.,target=/src,readonly \
    gcc \
    -O2 \
    -o application \
    /src/main.c
```

The source is available during that build step without necessarily being permanently copied into the builder filesystem layer.

Docker lists build bind mounts as another method for improving cache use and avoiding unnecessary context content in layers. ([Docker Documentation](https://docs.docker.com/build/cache/optimize/?utm_source=chatgpt.com "Optimize cache usage in builds"))

For ordinary projects, conventional `COPY` remains easier to understand. Use build mounts when their cache or storage behavior provides a clear benefit.

---

# 43. Targeting different outputs from one Dockerfile

One Dockerfile can produce:

```text
builder image
test image
debug image
production runtime image
```

Example:

```dockerfile
FROM debian:13 AS builder
# Compile.

FROM builder AS test
# Run tests.

FROM debian:13-slim AS runtime
# Minimal service.

FROM runtime AS debug
# Add diagnostic tools.
```

Commands:

```bash
docker build \
  --target builder \
  -t application:builder \
  .
```

```bash
docker build \
  --target test \
  -t application:test \
  .
```

```bash
docker build \
  --target runtime \
  -t application:1.0 \
  .
```

```bash
docker build \
  --target debug \
  -t application:debug \
  .
```

This avoids maintaining several mostly duplicated Dockerfiles.

---

# 44. Integrate a multi-stage build with Compose

Update your Compose API build:

```yaml
services:
  api:
    build:
      context: .
      target: runtime
    image: device-api:${APP_IMAGE_TAG:-1.0.0}
```

For development:

```yaml
services:
  api:
    build:
      context: .
      target: development
```

For tests:

```yaml
services:
  api-tests:
    build:
      context: .
      target: test
    profiles:
      - test
```

Compose can request a named build target from a multi-stage Dockerfile.

---

# 45. Production C Dockerfile with tests

A stronger C Dockerfile could be:

```dockerfile
# syntax=docker/dockerfile:1

ARG DEBIAN_VERSION=13

FROM debian:${DEBIAN_VERSION} AS builder

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       gcc \
       make \
       libc6-dev

WORKDIR /src

COPY Makefile ./
COPY main.c ./

RUN make

FROM builder AS test

RUN ./day15-service & \
    PID=$!; \
    sleep 1; \
    kill -TERM "$PID"; \
    wait "$PID"

FROM debian:${DEBIAN_VERSION}-slim AS runtime

RUN groupadd --system appgroup \
    && useradd \
       --system \
       --gid appgroup \
       --home-dir /nonexistent \
       --shell /usr/sbin/nologin \
       appuser

COPY --from=builder \
    /src/day15-service \
    /usr/local/bin/day15-service

USER appuser

ENTRYPOINT ["/usr/local/bin/day15-service"]
```

Build tests:

```bash
docker build \
  --target test \
  -t day15-c-service:test \
  .
```

Build production:

```bash
docker build \
  --target runtime \
  -t day15-c-service:2.0 \
  .
```

---

# 46. Verify the final runtime artifact

Check executable ownership and permissions:

```bash
docker run --rm \
  --entrypoint ls \
  day15-c-service:2.0 \
  -l /usr/local/bin/day15-service
```

Check runtime user:

```bash
docker image inspect \
  day15-c-service:2.0 \
  --format 'User={{.Config.User}}'
```

Check command:

```bash
docker image inspect \
  day15-c-service:2.0 \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'
```

Check shared libraries:

```bash
docker run --rm \
  --entrypoint ldd \
  day15-c-service:2.0 \
  /usr/local/bin/day15-service
```

Check that compiler is missing:

```bash
docker run --rm \
  --entrypoint sh \
  day15-c-service:2.0 \
  -c 'command -v gcc || true'
```

---

# 47. Inspect image history

Run:

```bash
docker image history \
  day15-c-service:2.0
```

Then:

```bash
docker image history \
  --no-trunc \
  day15-c-service:2.0
```

The final image history should include runtime-stage operations.

The large compiler-installation layers belong to the builder stage and should not become part of the final runtime image merely because the builder existed.

However, build cache still occupies host storage separately.

Inspect:

```bash
docker system df -v
```

---

# 48. Builder stages and disk usage

Multi-stage builds reduce the final image but do not mean the builder cache consumes no disk.

Docker may retain:

- Builder layers
    
- Downloaded packages
    
- Intermediate stages
    
- Cache records
    

This speeds up future builds.

Inspect build cache:

```bash
docker system df
```

Detailed BuildKit usage:

```bash
docker buildx du
```

Clean build cache selectively:

```bash
docker builder prune
```

Or:

```bash
docker buildx prune
```

Use pruning carefully. Removing cache makes future builds slower.

Docker includes garbage collection and pruning mechanisms for unused build cache. ([Docker Documentation](https://docs.docker.com/build/cache/garbage-collection/?utm_source=chatgpt.com "Build garbage collection"))

---

# 49. Do not optimize only for image size

A very small image is not automatically the best image.

Evaluate:

- Runtime compatibility
    
- Security updates
    
- Debuggability
    
- Package availability
    
- libc compatibility
    
- Certificate support
    
- Time-zone requirements
    
- Operations experience
    
- Vulnerability-management process
    

For example:

```text
Alpine
```

may be smaller, but it uses musl libc and can introduce compatibility differences.

```text
Debian slim
```

may be larger but easier to maintain and more compatible.

Optimize for:

```text
Correctness
Security
Maintainability
Predictability
Then size
```

---

# 50. Avoid unnecessary package installation

Bad:

```dockerfile
RUN apt-get update \
    && apt-get install -y \
       curl \
       vim \
       nano \
       git \
       gcc \
       make \
       net-tools \
       iputils-ping \
       procps
```

Ask which packages the runtime genuinely requires.

A production application may need only:

```dockerfile
RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       ca-certificates \
       libmosquitto1 \
    && rm -rf /var/lib/apt/lists/*
```

Use separate diagnostic containers or a debug target for troubleshooting tools.

---

# 51. Avoid installing and deleting in separate layers

This is ineffective:

```dockerfile
RUN apt-get update \
    && apt-get install -y gcc

RUN apt-get remove -y gcc
```

The earlier layer still contains the installed files in image history.

Deleting in a later layer does not remove the bytes from the earlier layer.

Multi-stage builds solve this correctly:

```text
Compiler exists only in builder stage
Final stage never receives it
```

---

# 52. Package cleanup in the same `RUN`

For runtime packages:

```dockerfile
RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       ca-certificates \
       libpq5 \
    && rm -rf /var/lib/apt/lists/*
```

This keeps package-index files out of the resulting layer.

The installation and cleanup belong in the same `RUN` instruction because layers are immutable.

---

# 53. Copy only required application files

Avoid:

```dockerfile
COPY . .
```

when the project contains:

```text
Documentation
Tests
Backups
Database files
Build artifacts
IDE settings
Git history
Development scripts
Secrets
```

Prefer explicit copies:

```dockerfile
COPY app.py .
COPY templates/ ./templates/
COPY static/ ./static/
```

Or maintain a strict `.dockerignore`.

Explicit copying improves clarity but requires updates when new application files are added.

---

# 54. Use immutable dependency inputs

For predictable builds, pin dependency versions.

Python:

```text
Flask==3.1.1
gunicorn==23.0.0
```

Node.js:

```text
package-lock.json
npm ci
```

Operating-system packages are more difficult because repository contents change over time.

For stronger reproducibility:

- Pin base-image digests
    
- Record application dependency hashes
    
- Use controlled package repositories
    
- Build in CI
    
- Store tested images in a registry
    
- Deploy the exact built image rather than rebuilding on production
    

---

# 55. Base image tags can move

This:

```dockerfile
FROM python:3.13-slim
```

may resolve to updated image content in the future.

For exact content:

```dockerfile
FROM python:3.13-slim@sha256:...
```

A tag is human-readable and movable.

A digest identifies specific image content.

A practical release process can use both:

```dockerfile
FROM python:3.13-slim@sha256:...
```

This retains the readable tag while pinning the exact digest.

Remember to update the digest deliberately for security patches.

---

# 56. Rebuild to receive security updates

A previously built image does not automatically update when its base image changes.

To check and use newer base-image content:

```bash
docker build \
  --pull \
  -t application:1.0.1 \
  .
```

Then test and deploy the newly built image.

The production process should include regular rebuilding and vulnerability review.

A stable container is not necessarily a patched container.

---

# 57. Use `docker build --check`

Modern Docker build tooling can perform Dockerfile build checks:

```bash
docker build --check .
```

This may detect issues such as:

- Secret-like values used in `ARG` or `ENV`
    
- Invalid syntax
    
- Stage-name problems
    
- Legacy instruction formatting
    
- Platform-related warnings
    

Available checks depend on the Docker and BuildKit versions installed.

A successful syntax check does not guarantee that the application works. You still need to build and test the image.

---

# 58. Multi-platform builds overview

An image built on an AMD64 machine normally targets:

```text
linux/amd64
```

Your image may also need to run on:

```text
linux/arm64
```

A multi-platform build can target several OS/architecture combinations from one invocation. ([Docker Documentation](https://docs.docker.com/build/building/multi-platform/?utm_source=chatgpt.com "Multi-platform builds"))

Inspect your builder:

```bash
docker buildx ls
```

Inspect an image manifest:

```bash
docker buildx imagetools inspect \
  python:3.13-slim
```

Build syntax:

```bash
docker buildx build \
  --platform linux/amd64,linux/arm64 \
  -t registry.example.com/application:1.0 \
  --push \
  .
```

Multi-platform builds will be covered more deeply later. Today, recognize that build and runtime architecture must be compatible.

---

# 59. Build arguments for platform-aware compilation

BuildKit provides automatic platform arguments, including concepts such as:

```text
BUILDPLATFORM
TARGETPLATFORM
TARGETOS
TARGETARCH
```

Example:

```dockerfile
FROM --platform=$BUILDPLATFORM golang:1.25 AS builder

ARG TARGETOS
ARG TARGETARCH

RUN GOOS=$TARGETOS \
    GOARCH=$TARGETARCH \
    go build -o /out/application
```

This is especially useful for languages with cross-compilation support.

For C and C++, cross-platform compilation usually requires an appropriate cross-toolchain and compatible libraries.

---

# 60. Day 15 practical project: optimize the Day 12 API

Create a multi-stage Dockerfile for your device API:

```dockerfile
# syntax=docker/dockerfile:1

FROM python:3.13-slim AS builder

WORKDIR /build

RUN python -m venv /opt/venv

ENV PATH="/opt/venv/bin:$PATH"

COPY requirements.txt .

RUN --mount=type=cache,target=/root/.cache/pip \
    pip install \
    -r requirements.txt

FROM python:3.13-slim AS runtime

ARG APP_VERSION=unknown
ARG VCS_REVISION=unknown
ARG BUILD_DATE=unknown

LABEL org.opencontainers.image.title="Device API"
LABEL org.opencontainers.image.version="${APP_VERSION}"
LABEL org.opencontainers.image.revision="${VCS_REVISION}"
LABEL org.opencontainers.image.created="${BUILD_DATE}"

RUN groupadd --system appgroup \
    && useradd \
       --system \
       --gid appgroup \
       --home-dir /app \
       --shell /usr/sbin/nologin \
       appuser

COPY --from=builder \
    /opt/venv \
    /opt/venv

ENV PATH="/opt/venv/bin:$PATH"
ENV PYTHONUNBUFFERED=1
ENV APP_VERSION="${APP_VERSION}"

WORKDIR /app

COPY --chown=appuser:appgroup app.py .
COPY --chown=appuser:appgroup templates/ ./templates/

USER appuser

EXPOSE 5000

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

Build:

```bash
docker build \
  --build-arg APP_VERSION=2.0.0 \
  --build-arg VCS_REVISION="$(git rev-parse HEAD 2>/dev/null || echo unknown)" \
  --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  -t day15-device-api:2.0.0 \
  .
```

---

# 61. Test the optimized API image

Check imports:

```bash
docker run --rm \
  day15-device-api:2.0.0 \
  python -c \
  "import flask, psycopg, gunicorn; print('Dependencies OK')"
```

Because the image’s `CMD` is replaceable, the command after the image name runs instead of Gunicorn.

Check runtime user:

```bash
docker run --rm \
  day15-device-api:2.0.0 \
  id
```

Check labels:

```bash
docker image inspect \
  day15-device-api:2.0.0 \
  --format '{{json .Config.Labels}}'
```

Check that compiler tools are absent:

```bash
docker run --rm \
  day15-device-api:2.0.0 \
  sh -c '
    command -v gcc || true
    command -v make || true
  '
```

---

# 62. Run with Compose

Update Compose:

```yaml
services:
  api:
    build:
      context: .
      target: runtime
      args:
        APP_VERSION: "${APP_VERSION:-2.0.0}"
        VCS_REVISION: "${VCS_REVISION:-unknown}"
        BUILD_DATE: "${BUILD_DATE:-unknown}"
    image: day15-device-api:${APP_VERSION:-2.0.0}
```

Set:

```bash
export APP_VERSION=2.0.0
export VCS_REVISION="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
export BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
```

Build:

```bash
docker compose build api
```

Start:

```bash
docker compose up -d
```

Inspect:

```bash
docker compose images
docker compose ps
docker compose logs api
```

---

# 63. Troubleshooting multi-stage builds

## Error: stage not found

Example:

```text
invalid from flag value builder
```

Check stage name:

```dockerfile
FROM debian:13 AS builder
```

and:

```dockerfile
COPY --from=builder ...
```

Stage names are case-sensitive in practice and should be consistent.

---

## Error: copied artifact does not exist

Example:

```text
failed to calculate checksum
"/src/application": not found
```

Inspect the builder stage:

```bash
docker build \
  --target builder \
  -t application:builder \
  .
```

Then:

```bash
docker run --rm \
  application:builder \
  find / -name application -type f 2>/dev/null
```

The build output path may differ from the path used by `COPY --from`.

---

## Runtime: executable not found

Possible causes:

- Wrong destination
    
- File not executable
    
- Missing dynamic linker
    
- Wrong architecture
    
- Script has invalid interpreter
    
- Shared library missing
    

Inspect:

```bash
docker run --rm \
  --entrypoint sh \
  IMAGE \
  -c '
    ls -l /usr/local/bin/application
    file /usr/local/bin/application
    ldd /usr/local/bin/application || true
  '
```

---

## Runtime: shared library not found

Example:

```text
error while loading shared libraries:
libmosquitto.so.1: cannot open shared object file
```

The builder had the development library, but the runtime image lacks the runtime library.

Install the appropriate runtime package in the final stage:

```dockerfile
RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       libmosquitto1 \
    && rm -rf /var/lib/apt/lists/*
```

---

## Build cache does not behave as expected

Check:

- Did a file copied early change?
    
- Is the build context changing?
    
- Did the base image change?
    
- Did build arguments change?
    
- Are timestamps embedded in a layer?
    
- Did you use `--no-cache`?
    
- Is the builder cache still present?
    

Use:

```bash
docker build \
  --progress=plain \
  .
```

---

## Final image is unexpectedly large

Check:

```bash
docker image history IMAGE
```

Possible causes:

- Accidentally copied source tree
    
- Runtime stage based on the builder stage
    
- Build tools installed in the final stage
    
- Dependency cache copied forward
    
- Large assets
    
- Backup files in build context
    
- Virtual environment contains unnecessary packages
    

---

# 64. Day 15 command reference

```bash
# Build the final stage
docker build \
  -t application:1.0 \
  .

# Build a named intermediate stage
docker build \
  --target builder \
  -t application:builder \
  .

# Build a test stage
docker build \
  --target test \
  -t application:test \
  .

# Force fresh build
docker build \
  --no-cache \
  -t application:1.0 \
  .

# Check for updated base images
docker build \
  --pull \
  -t application:1.0 \
  .

# Pass a build argument
docker build \
  --build-arg APP_VERSION=1.0.0 \
  -t application:1.0.0 \
  .

# Pass a build secret
docker build \
  --secret id=token,src=token.txt \
  -t application:1.0 \
  .

# Pass SSH agent access
docker build \
  --ssh default \
  -t application:1.0 \
  .

# Inspect image history
docker image history IMAGE

# Inspect final image size
docker image inspect IMAGE \
  --format '{{.Size}}'

# Inspect build storage
docker system df -v
docker buildx du

# Remove unused build cache
docker builder prune

# Run Dockerfile checks
docker build --check .
```

---

# 65. Knowledge check

## What is a multi-stage build?

A Dockerfile containing multiple build stages, usually defined with multiple `FROM` instructions, where artifacts can be copied from one stage into another.

## Why use a builder stage?

To compile or prepare artifacts using tools that should not be present in the final production image.

## What does `COPY --from=builder` do?

It copies a file or directory from the filesystem of the named builder stage into the current stage.

## Does the final image automatically contain all builder-stage files?

No. Only files explicitly copied into the final stage are included.

## How do you build an intermediate stage?

Use:

```bash
docker build --target STAGE_NAME .
```

## Why is a smaller runtime image useful?

It generally transfers faster and contains fewer unnecessary packages and tools, reducing operational complexity and potential attack surface.

## Does multi-stage building eliminate build-cache disk usage?

No. Builder layers and caches may remain on the Docker host for future builds.

## Why copy dependency files before application source?

So dependency-installation layers can remain cached when only source code changes.

## What is an `ARG`?

A build-time Dockerfile variable.

## What is an `ENV`?

An image/runtime environment variable.

## Should passwords be supplied through `ARG`?

No. Use BuildKit secret mounts or another appropriate secret mechanism.

## What is a cache mount?

Temporary reusable build storage used to accelerate operations such as package downloading without automatically becoming part of the final image.

## Why can a compiled binary fail in the runtime image?

It may require shared libraries, a compatible dynamic linker, the correct CPU architecture, or executable permissions.

---

# 66. Day 15 practical laboratory

## Exercise 1 — Single-stage C image

Create a single-stage Dockerfile containing:

- GCC
    
- Make
    
- Source
    
- Compiled binary
    

Build it and record its size.

---

## Exercise 2 — Multi-stage C image

Convert it to:

```text
builder
runtime
```

Copy only the executable.

Build and record the final size.

Compare the two images.

---

## Exercise 3 — Inspect runtime contents

Confirm the runtime image does not contain:

- GCC
    
- Make
    
- `main.c`
    
- Development headers
    

Confirm it does contain:

- Application binary
    
- Required shared libraries
    
- Runtime user
    

---

## Exercise 4 — Stop at builder stage

Build:

```bash
docker build --target builder ...
```

Enter the builder and inspect:

- Compiler
    
- Source
    
- Build artifact
    
- Shared-library requirements
    

---

## Exercise 5 — Test stage

Add a test stage.

Make the build fail if the application cannot start and stop cleanly.

Build the test target separately.

---

## Exercise 6 — Debug stage

Add a debug target containing:

- `strace`
    
- `procps`
    
- `iproute2`
    

Keep the normal runtime target unchanged.

---

## Exercise 7 — Python multi-stage build

Build dependencies in a virtual environment or wheel directory.

Copy only the prepared environment into the runtime stage.

Confirm the runtime image can import all dependencies.

---

## Exercise 8 — Cache ordering

Build a Python image where dependency installation occurs after `COPY . .`.

Change one source file and observe dependency reinstallation.

Reorder the Dockerfile.

Repeat and compare.

---

## Exercise 9 — `.dockerignore`

Create a large ignored file.

Compare the build-context transfer before and after adding it to `.dockerignore`.

---

## Exercise 10 — Cache mounts

Add a BuildKit pip or apt cache mount.

Build twice.

Compare download and build behavior.

---

## Exercise 11 — Build arguments

Add:

```text
APP_VERSION
VCS_REVISION
BUILD_DATE
```

as build arguments and OCI labels.

Inspect the final labels.

---

## Exercise 12 — Build secrets

Create a training secret file.

Use a secret mount during the build.

Confirm the secret file is not present in the final image.

Do not print the secret into image history or logs.

---

# 67. Day 15 completion challenge

Complete this independently:

1. Create a small C service that runs continuously.
    
2. Handle `SIGTERM` and exit cleanly.
    
3. Write a Makefile with strict compiler warnings.
    
4. Create a single-stage Dockerfile.
    
5. Build it and record its size.
    
6. Convert it to a multi-stage Dockerfile.
    
7. Name the first stage `builder`.
    
8. Compile the binary in that stage.
    
9. Create a minimal runtime stage.
    
10. Install only required runtime libraries.
    
11. Copy only the binary with `COPY --from`.
    
12. Create a non-root runtime user.
    
13. Run the binary as that user.
    
14. Confirm graceful shutdown with `docker stop`.
    
15. Confirm GCC is absent.
    
16. Confirm source code is absent.
    
17. Use `ldd` to inspect runtime dependencies.
    
18. Build the builder stage separately.
    
19. Add a test stage.
    
20. Add a debug stage.
    
21. Compare builder, debug, and runtime image sizes.
    
22. Add a strict `.dockerignore`.
    
23. Demonstrate build-cache reuse.
    
24. Modify only source and confirm dependency layers remain cached.
    
25. Add an `APP_VERSION` build argument.
    
26. Store the version as an OCI label.
    
27. Add source-revision and build-date labels.
    
28. Inspect the labels.
    
29. Add a BuildKit package-cache mount.
    
30. Build twice and compare behavior.
    
31. Create a training build secret.
    
32. Mount it using `RUN --mount=type=secret`.
    
33. Confirm it is absent from the final filesystem.
    
34. Confirm it is not defined through `ARG` or `ENV`.
    
35. Create a multi-stage Dockerfile for your MQTT C daemon.
    
36. Install `libmosquitto-dev` only in the builder.
    
37. Install the Mosquitto runtime library in the final image.
    
38. Copy only the compiled daemon.
    
39. Mount the daemon configuration at runtime.
    
40. Explain why the final image is safer and more maintainable than the builder image.
    

The central Day 15 model is:

```text
Source code
Build tools
Development headers
        ↓
Builder stage
        ↓ produces
Application artifact
        ↓ COPY --from
Runtime stage
        ↓
Minimal production image
```

The most important operational lesson is:

> Use the builder stage to create the application and the runtime stage to run it. Copy only the artifacts and runtime dependencies needed in production, structure Dockerfiles for effective caching, and never pass build secrets through ordinary `ARG` or `ENV` instructions.