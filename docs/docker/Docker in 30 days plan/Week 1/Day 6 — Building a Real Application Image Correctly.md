[[30 days Docker]]

Day 5 taught you how to create a basic image with a Dockerfile.

Day 6 goes deeper. You will now package a small web application and understand how these instructions work together:

```dockerfile
FROM
WORKDIR
COPY
RUN
ENV
EXPOSE
CMD
ENTRYPOINT
USER
```

The practical project will be a small Python web API.

By the end of Day 6, you should understand:

- How to structure an application project for Docker
    
- How dependencies are installed inside an image
    
- Why dependency files should be copied separately
    
- How runtime environment variables work
    
- Why web applications must listen on `0.0.0.0`
    
- How `CMD` and `ENTRYPOINT` differ
    
- How command overriding works
    
- Why exec-form startup commands are preferred
    
- Why a containerized application should log to standard output
    
- How to debug build and startup failures
    

Docker processes Dockerfile instructions in order. `WORKDIR` affects later `RUN`, `COPY`, `CMD`, and `ENTRYPOINT` instructions, while `CMD` and `ENTRYPOINT` define runtime behavior rather than build-time work. ([Docker Documentation](https://docs.docker.com/reference/dockerfile/?utm_source=chatgpt.com "Dockerfile reference"))

---

# 1. The application you will build

You will create this small HTTP API:

```text
Browser or curl
       ↓
Docker host port 5000
       ↓
Container port 5000
       ↓
Python Flask application
```

The API will provide:

```text
/
```

Basic application information.

```text
/health
```

A health endpoint.

```text
/config
```

Selected runtime configuration.

Your project will contain:

```text
day6-flask-app/
├── Dockerfile
├── app.py
├── requirements.txt
└── .dockerignore
```

---

# 2. Create the project directory

Run:

```bash
mkdir -p ~/docker-course/day6/flask-app
cd ~/docker-course/day6/flask-app
```

Check:

```bash
pwd
```

Then:

```bash
ls -la
```

---

# 3. Create the application

Create `app.py`:

```bash
nano app.py
```

Add:

```python
import os
from flask import Flask, jsonify

app = Flask(__name__)


@app.get("/")
def index():
    return jsonify(
        message="Hello from Docker",
        application="day6-flask-app",
        environment=os.getenv("APP_ENV", "development"),
    )


@app.get("/health")
def health():
    return jsonify(
        status="healthy",
        service="day6-flask-app",
    )


@app.get("/config")
def config():
    return jsonify(
        environment=os.getenv("APP_ENV", "development"),
        log_level=os.getenv("LOG_LEVEL", "info"),
        application_version=os.getenv("APP_VERSION", "unknown"),
    )


if __name__ == "__main__":
    port = int(os.getenv("PORT", "5000"))

    print(
        f"Starting application on 0.0.0.0:{port}",
        flush=True,
    )

    app.run(
        host="0.0.0.0",
        port=port,
    )
```

This application reads configuration from environment variables rather than hard-coding every value.

---

# 4. Create the dependency file

Create:

```bash
nano requirements.txt
```

Add:

```text
Flask==3.1.1
```

A dependency file describes what the application requires.

Instead of manually running:

```bash
pip install flask
```

inside every container, the Docker image will install dependencies during its build.

The desired process is:

```text
requirements.txt
       ↓
docker build
       ↓
dependencies installed in image
       ↓
every new container has them
```

---

# 5. Create the Dockerfile

Create:

```bash
nano Dockerfile
```

Add:

```dockerfile
FROM python:3.13-slim

WORKDIR /app

COPY requirements.txt .

RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

ENV APP_ENV=development
ENV LOG_LEVEL=info
ENV PORT=5000
ENV APP_VERSION=1.0.0

EXPOSE 5000

CMD ["python", "app.py"]
```

This is your first complete application Dockerfile.

Docker recommends using trusted, maintained base images and structuring Dockerfiles to make builds understandable and cache-friendly. ([Docker Documentation](https://docs.docker.com/build/building/best-practices/?utm_source=chatgpt.com "Building best practices"))

---

# 6. Understanding the complete Dockerfile

The Dockerfile can be divided into four logical sections:

```dockerfile
FROM python:3.13-slim
```

Select the runtime foundation.

```dockerfile
WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .
```

Prepare the application and dependencies.

```dockerfile
ENV APP_ENV=development
ENV LOG_LEVEL=info
ENV PORT=5000
ENV APP_VERSION=1.0.0
```

Define default configuration.

```dockerfile
EXPOSE 5000

CMD ["python", "app.py"]
```

Document the application port and define its startup command.

---

# 7. Understanding `FROM`

```dockerfile
FROM python:3.13-slim
```

This image already contains:

- A minimal Debian-based filesystem
    
- Python 3.13
    
- `pip`
    
- Required Python runtime libraries
    
- Default environment configuration
    

Your final image becomes:

```text
python:3.13-slim
        +
Flask dependency
        +
app.py
        +
runtime configuration
```

A base image should be selected based on:

- Runtime compatibility
    
- Maintenance
    
- Security updates
    
- Image size
    
- Package availability
    
- Debugging requirements
    

The `slim` variant is often a practical balance between size and compatibility.

---

# 8. Understanding `WORKDIR`

```dockerfile
WORKDIR /app
```

This creates or selects `/app` as the current working directory.

All subsequent relative operations use that directory.

Therefore:

```dockerfile
COPY requirements.txt .
```

means:

```text
Copy requirements.txt to /app/requirements.txt
```

And:

```dockerfile
COPY app.py .
```

means:

```text
Copy app.py to /app/app.py
```

At runtime:

```dockerfile
CMD ["python", "app.py"]
```

runs from `/app`.

Without `WORKDIR`, you would need more absolute paths:

```dockerfile
COPY app.py /app/app.py

CMD ["python", "/app/app.py"]
```

`WORKDIR` makes Dockerfiles easier to read and maintain.

---

# 9. Why dependencies are copied separately

The Dockerfile uses:

```dockerfile
COPY requirements.txt .

RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .
```

It does not immediately use:

```dockerfile
COPY . .
RUN pip install --no-cache-dir -r requirements.txt
```

The separate structure improves build caching.

Suppose only `app.py` changes.

With the better structure:

```text
requirements.txt unchanged
        ↓
dependency installation layer reused

app.py changed
        ↓
only application-copy layer rebuilt
```

If you copy the complete project before installing dependencies:

```text
any source-code change
        ↓
COPY layer changes
        ↓
dependency installation runs again
```

Dependency installation may be slow, so instruction ordering matters.

---

# 10. Understanding `RUN`

```dockerfile
RUN pip install --no-cache-dir -r requirements.txt
```

This command executes during:

```bash
docker build
```

It does not execute every time the container starts.

The result—Flask and its dependencies—is stored in the image.

Conceptually:

```text
Build time:
pip install Flask
       ↓
Flask becomes part of image

Runtime:
python app.py
       ↓
application uses already-installed Flask
```

The option:

```text
--no-cache-dir
```

tells `pip` not to preserve its package-download cache inside the image.

The installed Python packages remain. Only the unnecessary download cache is omitted.

---

# 11. Understanding `ENV`

The Dockerfile contains:

```dockerfile
ENV APP_ENV=development
ENV LOG_LEVEL=info
ENV PORT=5000
ENV APP_VERSION=1.0.0
```

`ENV` defines default environment variables stored in the image.

When a container starts, the application can read them.

In Python:

```python
os.getenv("APP_ENV", "development")
```

The first argument is the variable name.

The second argument is a fallback if the variable is absent.

Inspect the image defaults:

```bash
docker image inspect day6-flask-app:1.0 \
  --format '{{json .Config.Env}}'
```

You will run this after building the image.

---

# 12. Image defaults can be overridden at runtime

An image might define:

```dockerfile
ENV APP_ENV=development
```

But you can override it when creating a container:

```bash
docker run \
  -e APP_ENV=production \
  day6-flask-app:1.0
```

This does not change the image.

It changes only that container’s environment.

The relationship is:

```text
Image default:
APP_ENV=development

Runtime override:
APP_ENV=production

Effective container value:
APP_ENV=production
```

This allows one image to run in different environments.

---

# 13. Do not rebuild an image for every configuration change

Avoid creating separate images like:

```text
dashboard-development:1.0
dashboard-testing:1.0
dashboard-production:1.0
```

when the only differences are:

- Database hostname
    
- Log level
    
- Application environment
    
- Port
    
- Feature flags
    

A better pattern is:

```text
One application image
        +
Different runtime configuration
```

For example:

```bash
docker run \
  -e APP_ENV=development \
  application:1.0
```

```bash
docker run \
  -e APP_ENV=testing \
  application:1.0
```

```bash
docker run \
  -e APP_ENV=production \
  application:1.0
```

The application artifact remains identical.

---

# 14. Environment variables are not automatically secrets

This is convenient:

```bash
docker run \
  -e DATABASE_PASSWORD=secret \
  application
```

But environment variables may be visible through:

- `docker inspect`
    
- Process diagnostics
    
- Container configuration
    
- Host administrators
    
- Debugging tools
    
- Accidental logs
    

Do not assume:

```text
Environment variable = secure secret storage
```

You will study secrets handling later.

For today, use environment variables only for non-sensitive training configuration.

---

# 15. Understanding `EXPOSE`

```dockerfile
EXPOSE 5000
```

This documents that the application is expected to listen on container port 5000.

It does not publish the port on the host.

You still need:

```bash
docker run -p 5000:5000 day6-flask-app:1.0
```

The first `5000` is the host port.

The second `5000` is the container port.

You may choose a different host port:

```bash
docker run -p 8080:5000 day6-flask-app:1.0
```

Then:

```text
Host port 8080 → container port 5000
```

---

# 16. Understanding `CMD`

```dockerfile
CMD ["python", "app.py"]
```

This defines the default runtime command.

It does not run during the image build.

It runs whenever a new container starts.

The runtime process is:

```text
python app.py
```

Inside the container, that Python process becomes the main process.

When it exits, the container stops.

---

# 17. Why exec form is preferred

The Dockerfile uses:

```dockerfile
CMD ["python", "app.py"]
```

This is exec form.

The alternative is shell form:

```dockerfile
CMD python app.py
```

Exec form is generally preferred for the main process because:

- Python is started directly.
    
- Arguments are clearly separated.
    
- No unnecessary shell is inserted.
    
- Signals are delivered more predictably.
    
- Quoting is less ambiguous.
    

Docker distinguishes the container entrypoint from the default command; positional arguments commonly override `CMD`, while overriding `ENTRYPOINT` requires an explicit option. ([Docker Documentation](https://docs.docker.com/engine/containers/run/?utm_source=chatgpt.com "Running containers | Docker Docs"))

---

# 18. Create `.dockerignore`

Create:

```bash
nano .dockerignore
```

Add:

```text
__pycache__/
*.pyc
*.pyo
*.log
.env
.git/
.gitignore
venv/
.venv/
README.md
```

`.dockerignore` prevents unnecessary files from entering the build context.

This can:

- Speed up builds
    
- Reduce accidental copying
    
- Prevent source-control metadata from entering the image
    
- Reduce cache invalidation
    
- Help prevent accidental inclusion of local configuration
    

It does not replace proper secret management, but it is an important protection.

---

# 19. Build the application image

Run:

```bash
docker build -t day6-flask-app:1.0 .
```

During the build, observe the order:

```text
FROM python:3.13-slim
WORKDIR /app
COPY requirements.txt
RUN pip install
COPY app.py
ENV configuration
EXPOSE metadata
CMD metadata
```

List the image:

```bash
docker image ls day6-flask-app
```

Inspect its history:

```bash
docker image history day6-flask-app:1.0
```

---

# 20. Run the application

Run:

```bash
docker run -d \
  --name day6-api \
  -p 5000:5000 \
  day6-flask-app:1.0
```

Check:

```bash
docker ps
```

View logs:

```bash
docker logs day6-api
```

You should see output indicating that the application is listening.

Test the root endpoint:

```bash
curl http://localhost:5000/
```

Expected response:

```json
{
  "application": "day6-flask-app",
  "environment": "development",
  "message": "Hello from Docker"
}
```

Test health:

```bash
curl http://localhost:5000/health
```

Test configuration:

```bash
curl http://localhost:5000/config
```

---

# 21. Why the application listens on `0.0.0.0`

The application contains:

```python
app.run(
    host="0.0.0.0",
    port=port,
)
```

Inside a container:

```text
127.0.0.1
```

means the container’s loopback interface only.

Docker-published traffic arrives through the container’s network interface.

Therefore, this may fail:

```python
app.run(host="127.0.0.1", port=5000)
```

This is appropriate for container access:

```python
app.run(host="0.0.0.0", port=5000)
```

Remember:

```text
Server listens on:
0.0.0.0:5000

Client connects to:
localhost:5000
or
Docker-host-IP:5000
```

Clients do not normally connect to `0.0.0.0`.

---

# 22. Inspect the running container’s environment

Run:

```bash
docker exec day6-api env
```

Filter selected values:

```bash
docker exec day6-api \
  sh -c 'env | grep -E "APP_ENV|LOG_LEVEL|PORT|APP_VERSION"'
```

You should see:

```text
APP_ENV=development
LOG_LEVEL=info
PORT=5000
APP_VERSION=1.0.0
```

Inspect Docker’s recorded environment:

```bash
docker inspect day6-api \
  --format '{{json .Config.Env}}'
```

---

# 23. Override configuration at runtime

Remove the current container:

```bash
docker rm -f day6-api
```

Run it with overrides:

```bash
docker run -d \
  --name day6-api \
  -p 5000:5000 \
  -e APP_ENV=production \
  -e LOG_LEVEL=warning \
  -e APP_VERSION=1.0.0-production \
  day6-flask-app:1.0
```

Test:

```bash
curl http://localhost:5000/config
```

You should see the overridden values.

The image remains unchanged.

Verify the image defaults:

```bash
docker image inspect day6-flask-app:1.0 \
  --format '{{json .Config.Env}}'
```

The image should still contain its original defaults.

---

# 24. Change the host port without changing the application

Remove the container:

```bash
docker rm -f day6-api
```

Run:

```bash
docker run -d \
  --name day6-api \
  -p 8080:5000 \
  day6-flask-app:1.0
```

Now test:

```bash
curl http://localhost:8080/
```

Internally, the application still listens on:

```text
5000
```

Externally, the host publishes:

```text
8080
```

This demonstrates that application ports and host ports are separate concerns.

---

# 25. Change the internal runtime port

Your application reads:

```python
port = int(os.getenv("PORT", "5000"))
```

Therefore, you can change the internal application port.

Run:

```bash
docker rm -f day6-api
```

Then:

```bash
docker run -d \
  --name day6-api \
  -e PORT=7000 \
  -p 8080:7000 \
  day6-flask-app:1.0
```

Test:

```bash
curl http://localhost:8080/
```

The mapping is now:

```text
Host 8080 → container 7000
```

The Dockerfile’s:

```dockerfile
EXPOSE 5000
```

does not prevent using another port.

`EXPOSE` is documentation, not enforcement.

However, changing ports dynamically can make deployment configuration harder to understand. A stable internal application port is usually easier to manage.

---

# 26. Logging to standard output

The application uses:

```python
print(
    f"Starting application on 0.0.0.0:{port}",
    flush=True,
)
```

Flask also writes request information to output.

Docker captures the process’s:

- Standard output
    
- Standard error
    

You can inspect it using:

```bash
docker logs day6-api
```

Follow logs:

```bash
docker logs -f day6-api
```

Make requests in another terminal:

```bash
curl http://localhost:8080/
curl http://localhost:8080/health
```

You should see request records appear.

For containerized applications, logging to standard output and standard error is usually better than writing important logs only inside the container filesystem.

---

# 27. Why file-only logging is problematic

Suppose your application writes only to:

```text
/app/logs/application.log
```

Problems include:

- The log disappears when the container is removed.
    
- `docker logs` cannot display it.
    
- Log collection becomes more complicated.
    
- The container filesystem may grow.
    
- Multiple replicas each contain separate files.
    
- Rotation must be configured manually.
    

A container-friendly application normally writes logs to:

```text
stdout
stderr
```

A logging platform can then collect them.

---

# 28. Inspect the main process

Run:

```bash
docker top day6-api
```

Or enter the container:

```bash
docker exec -it day6-api sh
```

Inside:

```sh
ps
```

Depending on the slim image, `ps` may not be installed.

You can inspect from the host:

```bash
docker inspect day6-api \
  --format 'Path={{.Path}} Args={{json .Args}}'
```

You should see information equivalent to:

```text
python app.py
```

Exit:

```sh
exit
```

---

# 29. Override `CMD` at runtime

The image defines:

```dockerfile
CMD ["python", "app.py"]
```

You can override it by adding a command after the image name.

For example:

```bash
docker run --rm \
  day6-flask-app:1.0 \
  python --version
```

The container does not start the Flask application.

Instead, it executes:

```text
python --version
```

Another example:

```bash
docker run --rm \
  day6-flask-app:1.0 \
  ls -la /app
```

This is useful for:

- Debugging
    
- Running maintenance commands
    
- Inspecting image contents
    
- Running tests
    
- Checking installed dependencies
    

---

# 30. `CMD` versus `ENTRYPOINT`

This is one of the most important Dockerfile concepts.

## `CMD`

Provides a default command or default arguments.

Example:

```dockerfile
CMD ["python", "app.py"]
```

It can easily be replaced:

```bash
docker run image python --version
```

## `ENTRYPOINT`

Defines the image’s primary executable.

Example:

```dockerfile
ENTRYPOINT ["python"]
```

Then:

```dockerfile
CMD ["app.py"]
```

Together, Docker runs:

```text
python app.py
```

With this design:

```bash
docker run image --version
```

becomes:

```text
python --version
```

`ENTRYPOINT` is best when the image should behave like a specific executable. Docker’s best-practices guidance recommends using `ENTRYPOINT` for the image’s main command and `CMD` for sensible defaults when that behavior fits the image. ([Docker Documentation](https://docs.docker.com/build/building/best-practices/?utm_source=chatgpt.com "Building best practices"))

---

# 31. Practical `ENTRYPOINT` demonstration

Create a new directory:

```bash
mkdir -p ~/docker-course/day6/entrypoint-demo
cd ~/docker-course/day6/entrypoint-demo
```

Create this Dockerfile:

```dockerfile
FROM python:3.13-slim

ENTRYPOINT ["python"]

CMD ["--version"]
```

Build:

```bash
docker build -t day6-python-tool:1.0 .
```

Run without extra arguments:

```bash
docker run --rm day6-python-tool:1.0
```

Docker combines:

```text
ENTRYPOINT ["python"]
+
CMD ["--version"]
```

Result:

```text
python --version
```

Now run:

```bash
docker run --rm \
  day6-python-tool:1.0 \
  -c "print('Hello from ENTRYPOINT')"
```

Docker executes:

```text
python -c "print('Hello from ENTRYPOINT')"
```

The supplied arguments replace `CMD`, but `ENTRYPOINT` remains.

---

# 32. Override an entrypoint explicitly

To replace `ENTRYPOINT`, use:

```bash
docker run --rm \
  --entrypoint sh \
  day6-python-tool:1.0 \
  -c 'echo Entrypoint replaced'
```

This runs:

```text
sh -c 'echo Entrypoint replaced'
```

rather than Python.

This can be useful for debugging an image whose normal entrypoint prevents you from opening a shell.

---

# 33. Common `ENTRYPOINT` and `CMD` combinations

## Only `CMD`

```dockerfile
CMD ["python", "app.py"]
```

Runtime:

```text
python app.py
```

Easy to replace completely.

Good for general application images.

## Only `ENTRYPOINT`

```dockerfile
ENTRYPOINT ["python", "app.py"]
```

Runtime arguments are appended.

For example:

```bash
docker run image --debug
```

becomes:

```text
python app.py --debug
```

## `ENTRYPOINT` plus `CMD`

```dockerfile
ENTRYPOINT ["python", "app.py"]

CMD ["--mode", "production"]
```

Runtime:

```text
python app.py --mode production
```

User-provided arguments replace only the default `CMD`:

```bash
docker run image --mode development
```

Result:

```text
python app.py --mode development
```

---

# 34. When to use each startup pattern

Use only `CMD` when:

- The image is a normal application container.
    
- Users may need to replace the complete command.
    
- Simplicity is preferred.
    

Example:

```dockerfile
CMD ["python", "app.py"]
```

Use `ENTRYPOINT` plus `CMD` when:

- The image represents a command-line tool.
    
- The primary executable should remain fixed.
    
- Users should supply arguments.
    

Example:

```dockerfile
ENTRYPOINT ["curl"]
CMD ["--help"]
```

Do not use `ENTRYPOINT` simply because it sounds more important.

Choose based on intended behavior.

---

# 35. Shell form and environment expansion

Consider:

```dockerfile
ENV NAME=George

CMD ["echo", "$NAME"]
```

This may print:

```text
$NAME
```

rather than:

```text
George
```

Why?

Exec form does not automatically invoke a shell to perform variable expansion.

To use shell expansion:

```dockerfile
CMD ["sh", "-c", "echo \"$NAME\""]
```

or shell form:

```dockerfile
CMD echo "$NAME"
```

However, for a real application, it is usually better for the application itself to read environment variables rather than relying on shell expansion.

Your Python application correctly uses:

```python
os.getenv("APP_ENV")
```

---

# 36. Add a non-root user

Many images start processes as root unless configured otherwise.

You can create a dedicated application user.

Modify the Flask Dockerfile:

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

RUN pip install --no-cache-dir -r requirements.txt

COPY --chown=appuser:appgroup app.py .

ENV APP_ENV=development
ENV LOG_LEVEL=info
ENV PORT=5000
ENV APP_VERSION=1.1.0

EXPOSE 5000

USER appuser

CMD ["python", "app.py"]
```

The important new instruction is:

```dockerfile
USER appuser
```

All following runtime operations now use `appuser` unless overridden.

---

# 37. Why run as non-root?

A process running as root inside a container has more privileges than a non-root process.

Running as non-root helps limit the impact of:

- Application vulnerabilities
    
- Accidental filesystem changes
    
- Command injection
    
- Misconfiguration
    
- Compromised dependencies
    

Containers are an isolation mechanism, but they are not a reason to ignore normal least-privilege practices.

You will study container security more deeply later.

---

# 38. Build and test the non-root version

Build:

```bash
docker build -t day6-flask-app:1.1 .
```

Run:

```bash
docker rm -f day6-api 2>/dev/null
```

```bash
docker run -d \
  --name day6-api \
  -p 5000:5000 \
  day6-flask-app:1.1
```

Check the user:

```bash
docker exec day6-api id
```

Expected result should resemble:

```text
uid=... appuser gid=... appgroup
```

Test:

```bash
curl http://localhost:5000/health
```

If it works, the application does not need root privileges.

---

# 39. Understanding file ownership

This instruction:

```dockerfile
COPY --chown=appuser:appgroup app.py .
```

copies the file and gives ownership to:

```text
user: appuser
group: appgroup
```

Without it, files copied during the build are commonly owned by root.

A non-root process can usually read root-owned application files if permissions allow, but it may not be able to modify them.

This is often desirable for source code.

For directories that must be writable, ownership must be configured deliberately.

Example:

```dockerfile
RUN mkdir -p /app/data \
    && chown appuser:appgroup /app/data
```

---

# 40. Do not give unnecessary write access

Application source code usually does not need to be writable at runtime.

A useful model is:

```text
Application code:
read-only at runtime

Temporary files:
writable dedicated location

Persistent data:
mounted volume or external service

Logs:
stdout and stderr
```

Avoid solving permission errors with:

```bash
chmod -R 777 /app
```

That gives every user excessive permissions and hides the real ownership problem.

Instead determine:

- Which user runs the application?
    
- Which directory must be writable?
    
- Which group should own it?
    
- What minimum permissions are required?
    

---

# 41. Development server versus production server

Flask’s built-in server is suitable for learning and development.

It is not normally the preferred production application server.

For production, Python web applications commonly use a WSGI server such as Gunicorn.

Add to `requirements.txt`:

```text
Flask==3.1.1
gunicorn==23.0.0
```

Then change the Dockerfile command:

```dockerfile
CMD [
    "gunicorn",
    "--bind",
    "0.0.0.0:5000",
    "--workers",
    "2",
    "app:app"
]
```

In a real Dockerfile, this should appear on one valid line or use JSON formatting supported by the Dockerfile parser:

```dockerfile
CMD ["gunicorn", "--bind", "0.0.0.0:5000", "--workers", "2", "app:app"]
```

Here:

```text
app:app
```

means:

```text
Python module: app.py
Flask object: app
```

---

# 42. A more realistic Dockerfile

A stronger Day 6 Dockerfile is:

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

RUN pip install --no-cache-dir -r requirements.txt

COPY --chown=appuser:appgroup app.py .

ENV APP_ENV=production
ENV LOG_LEVEL=info
ENV PORT=5000
ENV APP_VERSION=1.2.0
ENV PYTHONUNBUFFERED=1

EXPOSE 5000

USER appuser

CMD ["gunicorn", "--bind", "0.0.0.0:5000", "--workers", "2", "app:app"]
```

`PYTHONUNBUFFERED=1` helps Python write output without unnecessary buffering, making logs appear promptly in:

```bash
docker logs
```

---

# 43. Build the Gunicorn version

Update `requirements.txt`:

```text
Flask==3.1.1
gunicorn==23.0.0
```

Update the Dockerfile to use Gunicorn.

Build:

```bash
docker build -t day6-flask-app:1.2 .
```

Run:

```bash
docker rm -f day6-api 2>/dev/null
```

```bash
docker run -d \
  --name day6-api \
  -p 5000:5000 \
  day6-flask-app:1.2
```

Check logs:

```bash
docker logs day6-api
```

Test:

```bash
curl http://localhost:5000/
curl http://localhost:5000/health
curl http://localhost:5000/config
```

---

# 44. Why the application should not daemonize itself

Some servers support starting in background mode.

Inside a container, the main server should normally remain in the foreground.

Bad pattern:

```text
container starts script
       ↓
script launches server in background
       ↓
script exits
       ↓
container stops
```

Correct pattern:

```text
container starts server directly
       ↓
server stays in foreground as main process
       ↓
container remains running
```

Examples:

```dockerfile
CMD ["gunicorn", "--bind", "0.0.0.0:5000", "app:app"]
```

```dockerfile
CMD ["nginx", "-g", "daemon off;"]
```

```dockerfile
CMD ["/app/mqtt-service-daemon", "--config", "/etc/app/config.conf"]
```

---

# 45. Signal handling and graceful shutdown

When you run:

```bash
docker stop day6-api
```

Docker sends a termination signal to the container’s main process.

The process should:

- Stop accepting new work
    
- Finish or terminate active requests appropriately
    
- Close resources
    
- Flush output
    
- Exit
    

Using exec-form startup helps the actual server process receive signals directly.

This is particularly important for:

- Web servers
    
- MQTT clients
    
- Databases
    
- Background consumers
    
- Your C daemon
    

---

# 46. Diagnose a container that exits immediately

Suppose:

```bash
docker run -d \
  --name broken-api \
  day6-flask-app:1.2
```

Then:

```bash
docker ps
```

does not show it.

Check all containers:

```bash
docker ps -a
```

View logs:

```bash
docker logs broken-api
```

Inspect exit code:

```bash
docker inspect broken-api \
  --format 'Status={{.State.Status}} ExitCode={{.State.ExitCode}} Error={{.State.Error}}'
```

Typical causes:

- Wrong `CMD`
    
- Missing package
    
- Python syntax error
    
- Incorrect working directory
    
- Missing source file
    
- Permission error
    
- Invalid environment value
    
- Application exits normally instead of starting a server
    

---

# 47. Deliberately create a missing-command error

Temporarily change the Dockerfile:

```dockerfile
CMD ["gunicorn-does-not-exist", "app:app"]
```

Build:

```bash
docker build -t day6-flask-app:broken .
```

Run:

```bash
docker run --name broken-command \
  day6-flask-app:broken
```

Docker should report that it cannot find the executable.

Inspect:

```bash
docker inspect broken-command \
  --format '{{.State.ExitCode}}'
```

This commonly results in exit code:

```text
127
```

Restore the correct command afterward.

---

# 48. Deliberately create an application error

Add invalid Python syntax to `app.py`, for example:

```python
this is invalid Python
```

Build:

```bash
docker build -t day6-flask-app:syntax-error .
```

Run:

```bash
docker run --name syntax-error-api \
  day6-flask-app:syntax-error
```

Inspect:

```bash
docker logs syntax-error-api
```

This demonstrates a core troubleshooting method:

```text
Container exited
       ↓
docker ps -a
       ↓
docker logs
       ↓
inspect exit code
       ↓
fix source or Dockerfile
       ↓
rebuild image
       ↓
replace container
```

Do not enter the failed container and manually repair it as your final solution.

---

# 49. Verify installed dependencies

Run:

```bash
docker run --rm \
  day6-flask-app:1.2 \
  pip list
```

This overrides the default command and displays installed Python packages.

Check Flask:

```bash
docker run --rm \
  day6-flask-app:1.2 \
  python -c "import flask; print(flask.__version__)"
```

Check application import:

```bash
docker run --rm \
  day6-flask-app:1.2 \
  python -c "from app import app; print(app.url_map)"
```

These are useful image-validation techniques.

---

# 50. Use an environment file

Create:

```bash
nano development.env
```

Add:

```text
APP_ENV=development
LOG_LEVEL=debug
APP_VERSION=1.2.0-dev
PORT=5000
```

Run:

```bash
docker rm -f day6-api 2>/dev/null
```

```bash
docker run -d \
  --name day6-api \
  --env-file development.env \
  -p 5000:5000 \
  day6-flask-app:1.2
```

Test:

```bash
curl http://localhost:5000/config
```

An environment file avoids repeating many `-e` options.

Do not commit files containing real secrets.

Your `.dockerignore` should include:

```text
*.env
.env
```

Your `.gitignore` should also exclude sensitive environment files.

---

# 51. Inspect the effective container configuration

Run:

```bash
docker inspect day6-api \
  --format 'Image={{.Config.Image}}'
```

Then:

```bash
docker inspect day6-api \
  --format 'WorkingDir={{.Config.WorkingDir}}'
```

Then:

```bash
docker inspect day6-api \
  --format 'User={{.Config.User}}'
```

Then:

```bash
docker inspect day6-api \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'
```

Then:

```bash
docker inspect day6-api \
  --format 'Environment={{json .Config.Env}}'
```

This teaches you to confirm the configuration Docker actually applied rather than assuming the Dockerfile behaved as expected.

---

# 52. Day 6 practical laboratory

## Exercise 1 — Build the Flask image

Create:

```text
flask-app/
├── Dockerfile
├── app.py
├── requirements.txt
└── .dockerignore
```

Build:

```bash
docker build -t day6-api:1.0 .
```

Run:

```bash
docker run -d \
  --name day6-api-v1 \
  -p 5000:5000 \
  day6-api:1.0
```

Test every endpoint.

---

## Exercise 2 — Inspect image defaults

Run:

```bash
docker image inspect day6-api:1.0 \
  --format 'Workdir={{.Config.WorkingDir}}'
```

```bash
docker image inspect day6-api:1.0 \
  --format 'Environment={{json .Config.Env}}'
```

```bash
docker image inspect day6-api:1.0 \
  --format 'Ports={{json .Config.ExposedPorts}}'
```

```bash
docker image inspect day6-api:1.0 \
  --format 'Cmd={{json .Config.Cmd}}'
```

---

## Exercise 3 — Override the environment

Create a second container:

```bash
docker run -d \
  --name day6-api-production \
  -p 5001:5000 \
  -e APP_ENV=production \
  -e LOG_LEVEL=warning \
  day6-api:1.0
```

Compare:

```bash
curl http://localhost:5000/config
curl http://localhost:5001/config
```

Both containers use the same image but return different runtime configuration.

---

## Exercise 4 — Override `CMD`

Run:

```bash
docker run --rm \
  day6-api:1.0 \
  python --version
```

Then:

```bash
docker run --rm \
  day6-api:1.0 \
  ls -la /app
```

Explain why the web application did not start.

---

## Exercise 5 — Test cache behavior

Build again without changes:

```bash
docker build -t day6-api:1.1 .
```

Observe cached steps.

Modify only `app.py`.

Build:

```bash
docker build -t day6-api:1.2 .
```

The dependency-installation step should normally remain cached.

Modify `requirements.txt`.

Build:

```bash
docker build -t day6-api:1.3 .
```

The dependency-installation step should now rerun.

---

## Exercise 6 — Run as non-root

Add an application user and:

```dockerfile
USER appuser
```

Build:

```bash
docker build -t day6-api:nonroot .
```

Run:

```bash
docker run -d \
  --name day6-api-nonroot \
  -p 5002:5000 \
  day6-api:nonroot
```

Confirm:

```bash
docker exec day6-api-nonroot id
```

Test:

```bash
curl http://localhost:5002/health
```

---

## Exercise 7 — Use Gunicorn

Add Gunicorn to `requirements.txt`.

Change `CMD` to:

```dockerfile
CMD ["gunicorn", "--bind", "0.0.0.0:5000", "--workers", "2", "app:app"]
```

Build and run:

```bash
docker build -t day6-api:gunicorn .
```

```bash
docker run -d \
  --name day6-api-gunicorn \
  -p 5003:5000 \
  day6-api:gunicorn
```

Inspect logs and test all endpoints.

---

## Exercise 8 — Break and diagnose it

Change the command to a nonexistent executable.

Build and run the broken image.

Use:

```bash
docker ps -a
docker logs CONTAINER_NAME
docker inspect CONTAINER_NAME \
  --format '{{.State.ExitCode}}'
```

Restore the valid command, rebuild, and replace the container.

---

## Exercise 9 — Use an environment file

Create:

```text
APP_ENV=testing
LOG_LEVEL=debug
APP_VERSION=testing-build
PORT=5000
```

Run:

```bash
docker run -d \
  --env-file testing.env \
  -p 5004:5000 \
  day6-api:gunicorn
```

Test `/config`.

---

## Exercise 10 — Cleanup

List:

```bash
docker ps -a --filter name=day6
```

Remove:

```bash
docker rm -f \
  day6-api-v1 \
  day6-api-production \
  day6-api-nonroot \
  day6-api-gunicorn \
  day6-api \
  broken-command \
  syntax-error-api \
  2>/dev/null
```

Keep at least one working image for Day 7.

---

# 53. Day 6 command reference

```bash
# Build an application image
docker build -t application:1.0 .

# Run with environment variables
docker run \
  -e APP_ENV=production \
  application:1.0

# Run using an environment file
docker run \
  --env-file application.env \
  application:1.0

# Publish the application
docker run -d \
  --name application \
  -p 8080:5000 \
  application:1.0

# View logs
docker logs application

# Follow logs
docker logs -f application

# Inspect runtime environment
docker exec application env

# Inspect runtime user
docker exec application id

# Override CMD
docker run --rm application:1.0 python --version

# Override ENTRYPOINT
docker run --rm \
  --entrypoint sh \
  application:1.0

# Inspect startup configuration
docker inspect application \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'

# Inspect exit status
docker inspect application \
  --format 'Status={{.State.Status}} ExitCode={{.State.ExitCode}}'
```

---

# 54. Dockerfile instruction summary

|Instruction|When applied|Purpose|
|---|---|---|
|`FROM`|Build time|Select the base image|
|`WORKDIR`|Build configuration|Set the current directory|
|`COPY`|Build time|Copy files into the image|
|`RUN`|Build time|Execute installation or setup commands|
|`ENV`|Image/runtime configuration|Define default environment variables|
|`EXPOSE`|Image metadata|Document intended ports|
|`USER`|Build/runtime configuration|Select the user for later instructions and startup|
|`CMD`|Runtime|Define default command or arguments|
|`ENTRYPOINT`|Runtime|Define primary executable|

---

# 55. Knowledge check

## Question 1

Why copy `requirements.txt` before `app.py`?

**Answer:**

So Docker can reuse the dependency-installation layer when only application source code changes.

## Question 2

When does `RUN pip install` execute?

**Answer:**

During image build, not every time the container starts.

## Question 3

Can an `ENV` value be overridden?

**Answer:**

Yes. Runtime options such as `-e` or `--env-file` can override the image default.

## Question 4

Why must the web application listen on `0.0.0.0`?

**Answer:**

So it accepts traffic arriving through the container’s network interface, including Docker-published traffic.

## Question 5

Does `EXPOSE 5000` create a host port?

**Answer:**

No. Host publishing still requires `-p`.

## Question 6

What happens to `CMD` when a command is added after the image name?

**Answer:**

The supplied command replaces the default `CMD`.

## Question 7

What is the main difference between `CMD` and `ENTRYPOINT`?

**Answer:**

`CMD` provides easily replaceable defaults. `ENTRYPOINT` defines the primary executable to which runtime arguments are usually appended.

## Question 8

Why use exec form?

**Answer:**

It starts the executable directly, handles arguments clearly, and generally improves signal handling.

## Question 9

Why run the application as a non-root user?

**Answer:**

It reduces unnecessary privileges and limits the impact of application vulnerabilities or mistakes.

## Question 10

Where should a containerized application normally write its logs?

**Answer:**

To standard output and standard error so Docker and logging systems can collect them.

---

# 56. Day 6 completion challenge

Complete this without copying the earlier commands:

1. Create a Python Flask application with `/`, `/health`, and `/config`.
    
2. Create a pinned dependency file.
    
3. Base the image on `python:3.13-slim`.
    
4. Set `/app` as the working directory.
    
5. Copy and install dependencies before copying application source.
    
6. Configure four environment-variable defaults.
    
7. Make the application listen on `0.0.0.0`.
    
8. Document container port 5000.
    
9. Run the application using exec-form `CMD`.
    
10. Create a `.dockerignore`.
    
11. Build the image as `challenge-api:1.0`.
    
12. Run it on host port 9080.
    
13. Test all endpoints.
    
14. Run another container from the same image with `APP_ENV=production`.
    
15. Publish the second container on port 9081.
    
16. Confirm both containers use the same image.
    
17. Confirm they return different configuration.
    
18. Override `CMD` to display the Python version.
    
19. Add a non-root user.
    
20. Rebuild as `challenge-api:2.0`.
    
21. Confirm the application runs as that user.
    
22. Replace the development server with Gunicorn.
    
23. Deliberately break the startup command.
    
24. Diagnose the failure using logs and exit status.
    
25. Restore the command and rebuild the working image.
    

The central Day 6 model is:

```text
Base runtime
    +
Application dependencies
    +
Application source
    +
Default configuration
    +
Startup command
    ↓
Reproducible application image
    ↓
Runtime configuration
    ↓
Replaceable application container
```

The most important operational lesson is:

> Build application code and dependencies into the image, supply environment-specific configuration at runtime, run one foreground main process, log to standard output, and avoid unnecessary root privileges.