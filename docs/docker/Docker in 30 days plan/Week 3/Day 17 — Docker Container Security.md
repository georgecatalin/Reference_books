#### Docker Container Security and Least Privilege

Docker provides process, filesystem, network, and namespace isolation, but a container is not automatically secure simply because it runs inside Docker.

Security depends on several layers:

```text
Trusted image
    +
Minimal software
    +
Non-root process
    +
Limited Linux capabilities
    +
Restricted filesystem access
    +
Safe mounts
    +
Protected secrets
    +
Network isolation
    +
Regular vulnerability scanning
    +
Secure Docker host
```

The central lesson is:

> Give each container only the user privileges, filesystem access, network access, capabilities, secrets, and resources it genuinely needs.

Docker’s isolation mechanisms reduce risk, but privileged containers, host namespace sharing, dangerous mounts, vulnerable images, and excessive permissions can weaken or bypass those protections. ([Docker Documentation](https://docs.docker.com/engine/security/?utm_source=chatgpt.com "Docker Engine security"))

---

# 1. Day 17 objectives

By the end of today, you should understand:

- Why containers are not virtual machines
    
- The importance of protecting the Docker daemon
    
- Root inside a container versus root on the host
    
- How to run applications as non-root users
    
- Numeric user and group IDs
    
- Linux capabilities
    
- Why `--privileged` is dangerous
    
- How to drop capabilities
    
- `no-new-privileges`
    
- Read-only root filesystems
    
- Writable temporary and persistent paths
    
- Safe and dangerous mounts
    
- Docker socket risks
    
- Default seccomp protection
    
- User namespaces and rootless Docker
    
- Runtime secrets in Compose
    
- Build-time secrets
    
- Image vulnerability scanning
    
- Image provenance and trust
    
- Secure Compose defaults
    
- A practical security review workflow
    

---

# 2. The container security model

A container is fundamentally a group of processes running on the Docker host, isolated using Linux features such as:

- Namespaces
    
- Control groups
    
- Linux capabilities
    
- Seccomp
    
- Filesystem isolation
    
- Network isolation
    

Containers share the host kernel.

This differs from a traditional virtual machine:

```text
Virtual machine
├── Guest operating-system kernel
├── Guest user space
└── Applications
```

```text
Container
├── Shares host kernel
├── Isolated user space
└── Application processes
```

A container escape or Docker daemon compromise can therefore be serious because the host kernel and container runtime form part of the security boundary.

---

# 3. Protect the Docker daemon

The Docker daemon manages:

- Containers
    
- Images
    
- Networks
    
- Volumes
    
- Host mounts
    
- Container privileges
    
- Host namespace access
    

Anyone who can fully control Docker can usually start a container with dangerous options such as:

```bash
docker run \
  --privileged \
  --mount type=bind,source=/,target=/host \
  IMAGE
```

That container may gain extensive access to the host.

Therefore, membership in the `docker` group should be treated as highly privileged access.

Check your groups:

```bash
groups
```

You may see:

```text
georgeca sudo docker users
```

Do not casually add untrusted users to the `docker` group.

---

# 4. The Docker socket is highly privileged

The Docker Unix socket is commonly:

```text
/var/run/docker.sock
```

The Docker CLI communicates with Docker Engine through this socket.

This mount is dangerous:

```bash
docker run \
  --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock \
  IMAGE
```

A process with access to the Docker API may be able to:

- Start privileged containers
    
- Mount the host filesystem
    
- Read container environment variables
    
- Stop production services
    
- Read or replace application images
    
- Access sensitive volumes
    
- Effectively gain extensive control over the Docker host
    

The Docker Engine API can perform the same types of actions as the Docker CLI, so access to the daemon API should be tightly controlled. ([Docker Documentation](https://docs.docker.com/reference/cli/dockerd/?utm_source=chatgpt.com "dockerd | Docker Docs"))

Do not expose the Docker socket to ordinary web applications.

---

# 5. Root inside a container

Many images run as UID `0` unless configured otherwise.

Check:

```bash
docker run --rm alpine id
```

You will likely see:

```text
uid=0(root) gid=0(root)
```

Root inside a normally isolated container is not automatically equivalent to unrestricted host root.

Docker normally applies restrictions such as:

- Namespaces
    
- Reduced capabilities
    
- Seccomp
    
- Filesystem boundaries
    
- Network namespaces
    

However, root inside the container still has more power than an unprivileged container user.

If an application vulnerability allows command execution, an attacker running as container root may find it easier to:

- Modify application files
    
- Install tools
    
- Change ownership
    
- Access writable mounts
    
- Exploit kernel or runtime vulnerabilities
    
- Abuse excessive capabilities
    

Docker recommends running applications as unprivileged users where practical. ([Docker Documentation](https://docs.docker.com/engine/security/userns-remap/?utm_source=chatgpt.com "Isolate containers with a user namespace"))

---

# 6. Run your application as non-root

A Python application Dockerfile can create a dedicated user:

```dockerfile
FROM python:3.13-slim

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
    -r requirements.txt

COPY --chown=appuser:appgroup app.py .
COPY --chown=appuser:appgroup templates/ ./templates/

USER appuser

CMD ["gunicorn", "--bind", "0.0.0.0:5000", "app:app"]
```

The important line is:

```dockerfile
USER appuser
```

All following runtime operations use that identity unless explicitly overridden.

Build:

```bash
docker build \
  -t day17-nonroot-api:1.0 \
  .
```

Check:

```bash
docker run --rm \
  day17-nonroot-api:1.0 \
  id
```

---

# 7. Verify the image user

Inspect:

```bash
docker image inspect \
  day17-nonroot-api:1.0 \
  --format 'User={{.Config.User}}'
```

Expected:

```text
User=appuser
```

Run a container:

```bash
docker run -d \
  --name day17-api \
  day17-nonroot-api:1.0
```

Check:

```bash
docker exec day17-api id
```

You should not see:

```text
uid=0(root)
```

---

# 8. Usernames are less important than numeric IDs

Linux permission checks use numeric identifiers:

```text
UID
GID
```

The username is a human-readable mapping.

Inside a container:

```bash
docker exec day17-api id
```

may show:

```text
uid=999(appuser) gid=995(appgroup)
```

On the host, a bind-mounted file may belong to:

```text
UID 1000
GID 1000
```

These are different users from the kernel’s perspective.

Inspect host ownership numerically:

```bash
ls -ln PATH
```

Inspect inside the container:

```bash
docker exec day17-api \
  ls -ln /app
```

Permission troubleshooting should compare numeric IDs, not only names.

---

# 9. Do not solve every problem with root

Suppose a non-root application cannot write to:

```text
/app/data
```

A poor response is to remove:

```dockerfile
USER appuser
```

and run everything as root.

A better process is:

1. Determine whether the application really needs write access.
    
2. Identify the exact writable directory.
    
3. Set appropriate ownership.
    
4. Keep application code read-only.
    
5. Mount persistent data separately.
    

Example:

```dockerfile
RUN mkdir -p /app/data \
    && chown appuser:appgroup /app/data
```

Or initialize volume ownership with a temporary container:

```bash
docker run --rm \
  --user root \
  --mount type=volume,source=application-data,target=/data \
  alpine \
  chown -R 10001:10001 /data
```

Then run the application as UID `10001`.

---

# 10. Avoid `chmod 777`

This command:

```bash
chmod -R 777 /app
```

grants read, write, and execute permission to everyone.

It can:

- Allow unrelated processes to modify files
    
- Hide ownership mistakes
    
- Permit source-code tampering
    
- Make mounted host files unnecessarily writable
    
- Expand the effect of an application compromise
    

Instead use minimum permissions.

Typical application code:

```text
Directories: 755
Files:       644
Executables: 755
```

A private writable data directory might use:

```text
Owner: application user
Mode: 750 or 700
```

The correct values depend on which users and groups require access.

---

# 11. Test non-root restrictions

Run:

```bash
docker run --rm \
  --user 10001:10001 \
  alpine \
  sh -c '
    id
    touch /root/test-file
  '
```

The write should fail.

Test a writable temporary location:

```bash
docker run --rm \
  --user 10001:10001 \
  alpine \
  sh -c '
    touch /tmp/test-file
    ls -l /tmp/test-file
  '
```

This demonstrates that a non-root process has limited write access unless the filesystem permissions permit it.

---

# 12. Linux capabilities

Traditional Unix root has many privileges.

Linux capabilities split root authority into smaller units.

Examples include capabilities related to:

- Binding low-numbered ports
    
- Changing file ownership
    
- Changing process identities
    
- Network administration
    
- Loading kernel modules
    
- Tracing processes
    
- Overriding file permissions
    
- Mount operations
    

Docker normally provides containers with a restricted capability set rather than every possible root privilege.

Inspect capabilities inside a container:

```bash
docker run --rm \
  alpine \
  sh -c '
    grep Cap /proc/self/status
  '
```

For a more human-readable demonstration, use a trusted diagnostic image containing `capsh`:

```bash
docker run --rm \
  debian:13-slim \
  sh -c '
    apt-get update >/dev/null &&
    apt-get install -y --no-install-recommends libcap2-bin >/dev/null &&
    capsh --print
  '
```

---

# 13. Drop all capabilities by default

A strong starting point for many web applications is:

```bash
docker run \
  --cap-drop ALL \
  IMAGE
```

In Compose:

```yaml
services:
  api:
    cap_drop:
      - ALL
```

Then add back only capabilities that are genuinely required.

Example:

```yaml
services:
  special-service:
    cap_drop:
      - ALL
    cap_add:
      - NET_BIND_SERVICE
```

Many normal applications listening on ports above 1024 need no added capabilities.

Your Flask API listening on:

```text
5000
```

normally does not require `NET_BIND_SERVICE`.

---

# 14. Test a container with no capabilities

Run:

```bash
docker run --rm \
  --cap-drop ALL \
  alpine \
  sh -c '
    id
    echo "Container runs with all capabilities dropped"
  '
```

Try a restricted operation:

```bash
docker run --rm \
  --cap-drop ALL \
  alpine \
  sh -c '
    chown 1000:1000 /etc/hosts
  '
```

The operation should fail even though the process may report UID 0.

This demonstrates:

```text
UID 0
≠
all traditional root powers
```

Capabilities influence what container root can actually do.

---

# 15. Add only a required capability

Ports below 1024 were traditionally considered privileged ports on Linux.

A process may require:

```text
CAP_NET_BIND_SERVICE
```

to bind such a port, depending on the system and runtime configuration.

A least-privilege pattern is:

```bash
docker run \
  --user 10001:10001 \
  --cap-drop ALL \
  --cap-add NET_BIND_SERVICE \
  IMAGE
```

This is preferable to granting every capability or using privileged mode merely to bind one port.

A simpler design is often to make the application listen internally on an unprivileged port:

```text
Container port 8080
```

and publish:

```text
Host port 80 → container port 8080
```

Example:

```bash
docker run \
  -p 80:8080 \
  IMAGE
```

The application does not need to bind port 80 inside the container.

---

# 16. Why `--privileged` is dangerous

Run syntax:

```bash
docker run --privileged IMAGE
```

Privileged mode grants broad permissions and weakens several isolation controls.

Privileged containers can receive extensive access to devices and kernel-related functionality. Docker explicitly warns that privileged containers and other elevated options expose more of the Docker environment and host internals. ([Docker Documentation](https://docs.docker.com/security/faqs/containers/?utm_source=chatgpt.com "Container security FAQs"))

Do not use `--privileged` as a generic fix for:

```text
Permission denied
Device not found
Mount failed
Network operation not permitted
```

Instead identify the exact requirement.

Possible targeted solutions include:

```bash
--device /dev/DEVICE
```

```bash
--cap-add SPECIFIC_CAPABILITY
```

```bash
--mount ...
```

```bash
--security-opt ...
```

Only use privileged mode for workloads that genuinely require near-host-level privileges and whose risks are understood.

---

# 17. Dangerous host namespace sharing

These options reduce isolation:

```bash
--pid=host
```

```bash
--network=host
```

```bash
--ipc=host
```

```bash
--uts=host
```

Examples of risk:

- Host PID namespace allows visibility into host processes.
    
- Host networking removes normal container network separation.
    
- Host IPC permits closer interaction with host interprocess communication.
    
- Broad namespace sharing can expose host information and resources.
    

Use default namespace isolation unless a specific operational requirement justifies otherwise.

---

# 18. `no-new-privileges`

Use:

```bash
docker run \
  --security-opt no-new-privileges=true \
  IMAGE
```

In Compose:

```yaml
services:
  api:
    security_opt:
      - no-new-privileges:true
```

This prevents a process and its children from gaining additional privileges through mechanisms such as set-user-ID executables.

It complements:

- Non-root execution
    
- Capability dropping
    
- Read-only filesystems
    
- Seccomp
    
- Restricted mounts
    

It does not replace those controls.

---

# 19. Read-only root filesystem

Start a container with:

```bash
docker run \
  --read-only \
  IMAGE
```

The container’s root filesystem becomes read-only.

In Compose:

```yaml
services:
  api:
    read_only: true
```

Test:

```bash
docker run --rm \
  --read-only \
  alpine \
  sh -c '
    echo test > /test.txt
  '
```

The write should fail.

This helps prevent:

- Modification of packaged application code
    
- Installation of tools by an attacker
    
- Unexpected application writes
    
- Persistence inside the writable container layer
    
- Tampering with configuration
    

---

# 20. Provide explicit writable locations

Some applications need temporary storage.

Use a temporary filesystem:

```bash
docker run \
  --read-only \
  --tmpfs /tmp \
  IMAGE
```

In Compose:

```yaml
services:
  api:
    read_only: true
    tmpfs:
      - /tmp
```

Test:

```bash
docker run --rm \
  --read-only \
  --tmpfs /tmp \
  alpine \
  sh -c '
    echo temporary > /tmp/file.txt
    cat /tmp/file.txt
    echo permanent > /file.txt
  '
```

The `/tmp` write succeeds.

The root filesystem write fails.

For persistent application data, use a volume:

```yaml
services:
  api:
    read_only: true
    volumes:
      - application-data:/app/data
```

---

# 21. Identify unexpected writes

Run your application normally, then inspect:

```bash
docker diff CONTAINER
```

Suppose you see:

```text
A /app/cache
C /app/config.ini
A /app/logs/application.log
```

Ask:

- Should the cache be in `/tmp`?
    
- Should configuration be immutable?
    
- Should logs go to stdout?
    
- Should application data use a named volume?
    
- Can the root filesystem become read-only?
    

`docker diff` helps you prepare an application for:

```yaml
read_only: true
```

---

# 22. Secure mount design

Every mount grants the container access to something outside its image.

A good design uses the smallest possible scope.

Safer:

```bash
--mount \
  type=bind,source="$PWD/config/app.conf",target=/etc/app/app.conf,readonly
```

Less safe:

```bash
--mount \
  type=bind,source="$PWD",target=/host-project
```

Very dangerous:

```bash
--mount \
  type=bind,source=/,target=/host
```

Prefer:

- Specific file instead of whole directory
    
- Specific directory instead of parent filesystem
    
- Read-only instead of writable
    
- Named volume instead of arbitrary sensitive host path
    
- Per-service mounts instead of sharing every volume with every container
    

---

# 23. Read-only bind mounts

Configuration should usually be mounted read-only:

```yaml
services:
  mosquitto:
    volumes:
      - type: bind
        source: ./mosquitto.conf
        target: /mosquitto/config/mosquitto.conf
        read_only: true
```

Certificates should also normally be read-only:

```yaml
volumes:
  - type: bind
    source: ./certificates
    target: /etc/application/certificates
    read_only: true
```

Do not give the application permission to replace trusted certificates or configuration unless that is explicitly required.

---

# 24. Restrict volume access per service

Suppose your platform has:

```text
PostgreSQL
Dashboard
Backup service
Mosquitto
```

The PostgreSQL volume should be mounted by:

```text
PostgreSQL: read-write
Backup tool: read-only where possible
```

It should not be mounted into:

```text
Dashboard
Mosquitto
Reverse proxy
```

Example:

```yaml
services:
  database:
    volumes:
      - postgres-data:/var/lib/postgresql/data

  backup:
    volumes:
      - postgres-data:/source:ro

  api:
    # No direct PostgreSQL filesystem access
```

The API should access PostgreSQL through SQL over the network, not by reading PostgreSQL’s raw storage files.

---

# 25. Default seccomp protection

Seccomp restricts which Linux system calls a process may use.

Docker applies a default seccomp profile unless it is explicitly overridden.

Docker recommends retaining the default seccomp profile rather than disabling it casually. ([Docker Documentation](https://docs.docker.com/engine/security/seccomp/?utm_source=chatgpt.com "Seccomp security profiles for Docker"))

Dangerous:

```bash
docker run \
  --security-opt seccomp=unconfined \
  IMAGE
```

This removes the default syscall filtering.

Only use custom or unconfined seccomp settings when:

- You have identified a specific blocked syscall.
    
- The application genuinely requires it.
    
- You understand the security consequence.
    
- A narrower custom profile is not practical.
    

Do not disable seccomp as a first response to an application error.

---

# 26. AppArmor and SELinux

Depending on the Linux distribution, additional mandatory access control may be used.

Common systems include:

```text
Ubuntu/Debian:
AppArmor commonly used

Fedora/RHEL:
SELinux commonly used
```

These controls can restrict:

- File access
    
- Process behavior
    
- Mount access
    
- Device access
    
- Network-related operations
    

Symptoms can include permission failures even when Unix file modes appear correct.

Check for AppArmor messages:

```bash
sudo journalctl -k \
  | grep -i apparmor
```

Check SELinux status:

```bash
getenforce
```

Do not disable AppArmor or SELinux globally merely to make one container work.

Investigate and adjust the specific policy or mount labeling.

---

# 27. User namespace remapping

User namespaces can map root inside a container to an unprivileged numeric identity on the host.

Conceptually:

```text
Container UID 0
        ↓ mapped to
Host UID 100000
```

This reduces the host privileges associated with container root.

Docker supports user namespace remapping, and its documentation describes this as an additional mitigation for containers that need to run as root internally. ([Docker Documentation](https://docs.docker.com/engine/security/userns-remap/?utm_source=chatgpt.com "Isolate containers with a user namespace"))

This is a Docker daemon-level configuration and can affect:

- File ownership
    
- Bind mounts
    
- Existing Docker resources
    
- Volume permissions
    
- Operational workflows
    

Do not enable it on an important host without planning and testing.

---

# 28. Rootless Docker

Rootless mode runs both:

- Docker daemon
    
- Containers
    

without root privileges.

Docker describes rootless mode as a way to reduce risk from vulnerabilities in the Docker daemon and container runtime. ([Docker Documentation](https://docs.docker.com/engine/security/rootless/?utm_source=chatgpt.com "Rootless mode"))

Check whether your environment is rootless:

```bash
docker info \
  | grep -i rootless
```

Or:

```bash
docker info \
  --format '{{json .SecurityOptions}}'
```

Rootless Docker can have limitations or different behavior involving:

- Low-numbered ports
    
- Networking
    
- Storage
    
- Cgroup support
    
- Device access
    
- Host networking
    

Rootless Docker improves the daemon security model, but applications should still run as non-root inside their containers.

These are different layers:

```text
Rootless Docker
→ Docker daemon is non-root

USER appuser
→ application process is non-root
```

---

# 29. Runtime configuration is not necessarily secret

This is convenient:

```yaml
environment:
  DB_PASSWORD: development-password
```

But environment variables may be visible through:

```bash
docker inspect CONTAINER
```

```bash
docker compose config
```

```bash
docker compose exec SERVICE env
```

They may also appear in:

- Process diagnostics
    
- Debug output
    
- Support bundles
    
- Misconfigured logging
    

Do not expose secrets through:

- API endpoints
    
- Logs
    
- Image labels
    
- Dockerfile `ENV`
    
- Dockerfile `ARG`
    
- Git repositories
    

---

# 30. Compose secrets

Docker Compose supports service-specific secrets.

Secrets are mounted inside a service as files under:

```text
/run/secrets/SECRET_NAME
```

Only services that explicitly request a secret receive access to it. ([Docker Documentation](https://docs.docker.com/compose/how-tos/use-secrets/?utm_source=chatgpt.com "Manage secrets securely in Docker Compose"))

Create a secret file:

```bash
mkdir -p secrets

printf '%s' 'development-password' \
  > secrets/database-password.txt

chmod 600 secrets/database-password.txt
```

Compose:

```yaml
services:
  api:
    secrets:
      - database-password

  database:
    secrets:
      - database-password

secrets:
  database-password:
    file: ./secrets/database-password.txt
```

Inside the containers:

```text
/run/secrets/database-password
```

---

# 31. Applications must read file-based secrets

Your Python application currently reads:

```python
os.getenv("DB_PASSWORD")
```

Modify it to support a secret file:

```python
import os
from pathlib import Path


def read_secret(
    environment_name: str,
    file_environment_name: str,
) -> str:
    file_path = os.getenv(file_environment_name)

    if file_path:
        return Path(file_path).read_text(
            encoding="utf-8"
        ).strip()

    value = os.getenv(environment_name)

    if not value:
        raise RuntimeError(
            f"{environment_name} or "
            f"{file_environment_name} must be set"
        )

    return value
```

Use:

```python
password = read_secret(
    "DB_PASSWORD",
    "DB_PASSWORD_FILE",
)
```

Compose:

```yaml
services:
  api:
    environment:
      DB_PASSWORD_FILE: /run/secrets/database-password
    secrets:
      - database-password
```

---

# 32. PostgreSQL `_FILE` variables

Many official images support a convention where an environment variable can be read from a file using a corresponding `_FILE` variable.

For example, an image may support:

```yaml
environment:
  POSTGRES_PASSWORD_FILE: /run/secrets/database-password
```

Then:

```yaml
secrets:
  - database-password
```

Always verify the specific image documentation.

Do not assume every image or every variable supports the `_FILE` convention.

---

# 33. Compose secret example

A more secure database configuration is:

```yaml
services:
  api:
    environment:
      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: device_monitor
      DB_USER: device_app
      DB_PASSWORD_FILE: /run/secrets/database-password
    secrets:
      - database-password

  database:
    image: postgres:17
    environment:
      POSTGRES_USER: device_app
      POSTGRES_DB: device_monitor
      POSTGRES_PASSWORD_FILE: /run/secrets/database-password
    secrets:
      - database-password

secrets:
  database-password:
    file: ./secrets/database-password.txt
```

The secret is not stored directly in the Compose service environment.

The file on the Docker host still requires protection:

```bash
chmod 600 secrets/database-password.txt
```

Local Compose file-backed secrets are useful, but they do not provide all properties of an enterprise secret-management platform.

---

# 34. Swarm secrets versus local Compose secrets

Docker Swarm secrets are encrypted in transit and at rest within the Swarm’s management system and are granted only to selected services. ([Docker Documentation](https://docs.docker.com/engine/swarm/secrets/?utm_source=chatgpt.com "Manage sensitive data with Docker secrets"))

Local Compose secrets commonly originate from local files.

Therefore, with local Compose you must still secure:

- The source secret file
    
- Host backups
    
- File permissions
    
- CI variables
    
- Repository exclusions
    
- Server access
    

Do not commit:

```text
secrets/database-password.txt
```

Add:

```text
secrets/
```

to `.gitignore`.

Consider keeping a safe example:

```text
secrets/README.md
```

explaining how to create the required secret files.

---

# 35. Build-time secrets

Build-time and runtime secrets are different.

A build secret may be needed to:

- Download a private dependency
    
- Access a private Git repository
    
- Authenticate to a package registry
    
- Retrieve an internal build artifact
    

Do not use:

```dockerfile
ARG PRIVATE_TOKEN
```

or:

```dockerfile
ENV PRIVATE_TOKEN=...
```

for build secrets.

Docker warns that build arguments and environment variables are inappropriate for secrets because they can persist in the final image or its metadata. BuildKit secret mounts and SSH mounts are intended for this purpose. ([Docker Documentation](https://docs.docker.com/build/building/secrets/?utm_source=chatgpt.com "Build secrets"))

---

# 36. Use a BuildKit secret mount

Dockerfile:

```dockerfile
# syntax=docker/dockerfile:1

FROM alpine AS builder

RUN --mount=type=secret,id=private_token \
    TOKEN="$(cat /run/secrets/private_token)" \
    && test -n "$TOKEN" \
    && echo "Authenticated operation completed"
```

Create a local training secret:

```bash
printf '%s' 'training-token' \
  > private-token.txt

chmod 600 private-token.txt
```

Build:

```bash
docker build \
  --secret id=private_token,src=private-token.txt \
  -t day17-build-secret:1.0 \
  .
```

The secret exists temporarily during that `RUN` instruction.

It should not be copied into the final image.

---

# 37. Verify the build secret was not copied

Run:

```bash
docker run --rm \
  day17-build-secret:1.0 \
  sh -c '
    test ! -f /run/secrets/private_token &&
    echo "Secret absent"
  '
```

Inspect history:

```bash
docker image history \
  --no-trunc \
  day17-build-secret:1.0
```

Do not print the secret during the build.

Even when a secret mount is used correctly, this would be unsafe:

```dockerfile
RUN --mount=type=secret,id=private_token \
    cat /run/secrets/private_token
```

The value could appear in build logs.

---

# 38. Choose trusted base images

A base image becomes part of your application’s supply chain.

Prefer images that are:

- Official or from a trusted organization
    
- Actively maintained
    
- Appropriate for the runtime
    
- Minimal without sacrificing compatibility
    
- Regularly patched
    
- Explicitly versioned
    

Docker’s build recommendations include choosing appropriate trusted base images, using multi-stage builds, minimizing unnecessary contents, and rebuilding frequently. ([Docker Documentation](https://docs.docker.com/build/building/best-practices/?utm_source=chatgpt.com "Building best practices"))

Avoid unexplained images such as:

```dockerfile
FROM random-user/python-super-complete:latest
```

unless you have reviewed:

- Publisher
    
- Dockerfile or build process
    
- Update history
    
- Included software
    
- Vulnerability state
    
- Image signatures or provenance
    
- Licensing
    

---

# 39. Avoid relying only on `latest`

This is ambiguous:

```dockerfile
FROM python:latest
```

A better choice is:

```dockerfile
FROM python:3.13-slim
```

For exact reproducibility, use a digest:

```dockerfile
FROM python:3.13-slim@sha256:...
```

Tags can move to newer image contents.

Digests identify exact image contents.

However, digest pinning also means security fixes are not received until you deliberately update the digest and rebuild.

A responsible workflow is:

```text
Pin
→ monitor
→ update intentionally
→ rebuild
→ scan
→ test
→ deploy
```

---

# 40. Rebuild images regularly

A previously built image does not automatically receive:

- Base-image security updates
    
- Operating-system package updates
    
- Dependency fixes
    
- New CA certificates
    

Rebuild:

```bash
docker build \
  --pull \
  -t application:1.0.1 \
  .
```

The `--pull` option asks Docker to check for a newer version of the referenced base image.

Then:

- Run tests
    
- Scan the image
    
- Review changes
    
- Deploy the tested image
    

Do not update production by manually installing packages inside a running container.

---

# 41. Scan images with Docker Scout

Docker Scout can analyze image contents, generate an SBOM-style inventory, and compare packages against vulnerability data. ([Docker Documentation](https://docs.docker.com/scout/explore/analysis/?utm_source=chatgpt.com "Docker Scout image analysis"))

Check availability:

```bash
docker scout version
```

Scan an image:

```bash
docker scout cves \
  day15-device-api:2.0.0
```

The command can report known vulnerabilities found in supported image artifacts. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/scout/cves/?utm_source=chatgpt.com "docker scout cves"))

Scan only high and critical findings:

```bash
docker scout cves \
  --only-severity critical,high \
  day15-device-api:2.0.0
```

The exact available options depend on the installed Docker Scout version.

---

# 42. Understand vulnerability scan results

A scanner may report:

```text
Package
Installed version
Vulnerability identifier
Severity
Fixed version
Advisory
```

Do not react only to the number of findings.

For each important result, determine:

- Is the vulnerable package present in the final runtime image?
    
- Is the vulnerable code reachable?
    
- Is a fixed package available?
    
- Can the base image be updated?
    
- Is the dependency needed?
    
- Can the package be removed?
    
- Is the vulnerability relevant to the configured environment?
    
- Is there a vendor assessment or VEX statement?
    

Docker Scout supports vulnerability context and exceptions because the presence of a vulnerable package does not always mean the vulnerability is exploitable in that image’s actual use. ([Docker Documentation](https://docs.docker.com/scout/explore/exceptions/?utm_source=chatgpt.com "Manage vulnerability exceptions - Docker Scout"))

Do not silently ignore critical findings without documented analysis.

---

# 43. Generate or inspect an SBOM

An SBOM is a software bill of materials: an inventory of software components inside an artifact.

Docker Scout uses image analysis to compile component information and match it against vulnerability data. ([Docker Documentation](https://docs.docker.com/scout/?utm_source=chatgpt.com "Docker Scout"))

Depending on your Docker tooling, inspect package information with:

```bash
docker scout sbom IMAGE
```

Or use another trusted SBOM tool available in your environment.

An SBOM helps answer:

```text
Which operating-system packages are present?
Which application dependencies are included?
Which versions are deployed?
Does this image contain a newly vulnerable library?
```

An SBOM does not by itself make the image secure. It improves visibility.

---

# 44. Minimize the final image

Every unnecessary component can create:

- Additional vulnerabilities
    
- More patching work
    
- Larger transfer size
    
- More tools for an attacker
    
- More configuration complexity
    

Use multi-stage builds:

```text
Builder:
compiler + source + tests

Runtime:
application artifact + runtime libraries
```

Do not leave these in production unless required:

```text
gcc
make
git
curl
nano
vim
debuggers
package caches
source archives
test data
```

The multi-stage techniques from Day 15 directly improve security by reducing unnecessary runtime contents. ([Docker Documentation](https://docs.docker.com/build/?utm_source=chatgpt.com "Docker Build"))

---

# 45. Do not install a shell merely for convenience

Some minimal production images omit a shell.

That makes interactive debugging harder, but it also removes a useful tool that an attacker could abuse.

Do not force every runtime image to contain:

```text
bash
curl
git
package manager
compiler
```

because you might need them someday.

Instead use:

- A separate debug target
    
- A diagnostic container
    
- Logs
    
- Health checks
    
- Metrics
    
- `docker inspect`
    
- `docker top`
    
- Network debugging containers
    

Security and observability should be designed together.

---

# 46. Restrict network exposure

Publish only services that external clients need.

Good:

```yaml
services:
  api:
    ports:
      - "127.0.0.1:8080:5000"

  database:
    # No host port
```

The API may be reachable through a reverse proxy.

PostgreSQL remains internal.

Use separate networks:

```yaml
networks:
  frontend:
  backend:
    internal: true
```

Attach:

```text
Reverse proxy:
frontend only

API:
frontend + backend

Database:
backend only
```

This limits unnecessary communication paths.

---

# 47. Bind published ports deliberately

This:

```yaml
ports:
  - "8080:5000"
```

typically exposes the port through host interfaces according to Docker’s configuration.

This:

```yaml
ports:
  - "127.0.0.1:8080:5000"
```

restricts access to the host’s loopback interface.

Use loopback binding when:

- A reverse proxy runs on the same host
    
- Only local administrative access is required
    
- The service should not be directly exposed to the LAN
    

Use firewall rules and infrastructure controls as well.

Port binding is one layer, not the complete network-security strategy.

---

# 48. Resource limits are also security controls

A compromised or faulty container can consume:

- CPU
    
- Memory
    
- Disk
    
- Process IDs
    
- Network bandwidth
    

Apply reasonable limits:

```yaml
services:
  api:
    mem_limit: 512m
    cpus: 1.0
    pids_limit: 200
```

Run equivalent:

```bash
docker run \
  --memory 512m \
  --cpus 1.0 \
  --pids-limit 200 \
  IMAGE
```

Limits help reduce denial-of-service impact.

They must be chosen through observation and testing.

Too-low limits can cause legitimate failures.

---

# 49. Limit process creation

A fork bomb or process leak can exhaust host process resources.

Use:

```bash
docker run \
  --pids-limit 100 \
  IMAGE
```

Compose:

```yaml
services:
  api:
    pids_limit: 100
```

Check process usage:

```bash
docker stats
```

The `PIDS` column shows the number of processes and threads counted by Docker’s resource view.

A normal API may need more than one process because of:

- Gunicorn master
    
- Workers
    
- Threads
    
- Helper processes
    

Set the limit above the tested normal maximum.

---

# 50. Secure Compose definition

A hardened starting point for the API service is:

```yaml
services:
  api:
    image: device-api:2.0.0

    user: "10001:10001"

    ports:
      - "127.0.0.1:8080:5000"

    environment:
      APP_ENV: production
      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: device_monitor
      DB_USER: device_app
      DB_PASSWORD_FILE: /run/secrets/database-password

    secrets:
      - database-password

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

    restart: unless-stopped
    stop_grace_period: 30s

    networks:
      - frontend
      - backend
```

Test each restriction.

Do not add security settings blindly without confirming the application still functions.

---

# 51. Harden the database carefully

A database needs persistent write access.

Example:

```yaml
services:
  database:
    image: postgres:17

    environment:
      POSTGRES_USER: device_app
      POSTGRES_DB: device_monitor
      POSTGRES_PASSWORD_FILE: /run/secrets/database-password

    secrets:
      - database-password

    volumes:
      - postgres-data:/var/lib/postgresql/data

    security_opt:
      - no-new-privileges:true

    restart: unless-stopped
    stop_grace_period: 60s

    networks:
      - backend
```

Do not automatically apply:

```yaml
read_only: true
```

without identifying every location PostgreSQL must write.

PostgreSQL requires writable:

- Data directory
    
- Runtime socket or temporary locations
    
- Possibly `/tmp`
    
- Internal state paths depending on the image
    

Security hardening must preserve application correctness.

---

# 52. Complete secure Compose example

```yaml
services:
  api:
    image: device-api:2.0.0

    user: "10001:10001"

    ports:
      - "127.0.0.1:8080:5000"

    environment:
      APP_ENV: production
      APP_VERSION: "2.0.0"
      LOG_LEVEL: INFO

      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: device_monitor
      DB_USER: device_app
      DB_PASSWORD_FILE: /run/secrets/database-password

    secrets:
      - database-password

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

    restart: unless-stopped
    stop_grace_period: 30s

    logging:
      driver: local
      options:
        max-size: "10m"
        max-file: "3"

    networks:
      - frontend
      - backend

  database:
    image: postgres:17

    environment:
      POSTGRES_USER: device_app
      POSTGRES_DB: device_monitor
      POSTGRES_PASSWORD_FILE: /run/secrets/database-password

    secrets:
      - database-password

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

    security_opt:
      - no-new-privileges:true

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
  frontend:

  backend:
    internal: true

secrets:
  database-password:
    file: ./secrets/database-password.txt
```

Validate:

```bash
docker compose config
```

Be aware that resolved configuration output can still expose non-secret environment values and operational details.

---

# 53. Test the hardened stack incrementally

Do not enable every restriction at once.

Use this order:

```text
1. Confirm the normal application works.
2. Run as non-root.
3. Drop all capabilities.
4. Add no-new-privileges.
5. Make root filesystem read-only.
6. Add tmpfs for required temporary paths.
7. Configure exact writable volumes.
8. Restrict published ports.
9. Separate networks.
10. Introduce secrets.
11. Add resource limits.
12. Scan the final image.
```

After each step:

```bash
docker compose up -d
docker compose ps
docker compose logs
curl http://127.0.0.1:8080/health
```

This makes failures easier to attribute.

---

# 54. Verify runtime security settings

Inspect user:

```bash
docker compose exec api id
```

Inspect capabilities:

```bash
docker compose exec api \
  sh -c 'grep Cap /proc/self/status'
```

Test root filesystem:

```bash
docker compose exec api \
  sh -c 'echo test > /application-test.txt'
```

It should fail.

Test temporary storage:

```bash
docker compose exec api \
  sh -c '
    echo temporary > /tmp/test.txt
    cat /tmp/test.txt
  '
```

Inspect mounts:

```bash
docker inspect \
  "$(docker compose ps -q api)" \
  --format '{{json .Mounts}}'
```

Inspect security options:

```bash
docker inspect \
  "$(docker compose ps -q api)" \
  --format '{{json .HostConfig.SecurityOpt}}'
```

Inspect capability configuration:

```bash
docker inspect \
  "$(docker compose ps -q api)" \
  --format 'CapDrop={{json .HostConfig.CapDrop}} CapAdd={{json .HostConfig.CapAdd}}'
```

---

# 55. Verify secrets

Check the mounted secret:

```bash
docker compose exec api \
  sh -c '
    ls -l /run/secrets
    test -r /run/secrets/database-password
  '
```

Do not print the secret value.

Confirm it is not an environment variable:

```bash
docker compose exec api \
  env \
  | grep DB_PASSWORD
```

You should ideally see only:

```text
DB_PASSWORD_FILE=/run/secrets/database-password
```

not the actual password.

Inspect the service environment:

```bash
docker inspect \
  "$(docker compose ps -q api)" \
  --format '{{json .Config.Env}}'
```

The raw password should not appear there.

---

# 56. Security review checklist for an image

For every production image, ask:

```text
Source
- Is the publisher trusted?
- Is the image actively maintained?
- Is the version explicit?

Contents
- Is the image minimal?
- Are compilers and debuggers absent?
- Is source code included only when required?
- Are secrets absent?

User
- Does the application run as non-root?
- Are file permissions minimal?

Build
- Are multi-stage builds used?
- Are build secrets passed through secret mounts?
- Is .dockerignore strict?

Vulnerabilities
- Was the image scanned?
- Are critical and high findings reviewed?
- Is the base image current?

Metadata
- Is the image traceable to source revision and version?
```

---

# 57. Security review checklist for a container

Ask:

```text
Privileges
- Is --privileged absent?
- Are host namespaces avoided?
- Are capabilities dropped?
- Is no-new-privileges enabled?

Filesystem
- Is the root filesystem read-only where practical?
- Are only necessary paths writable?
- Are mounts narrow and preferably read-only?
- Is the Docker socket absent?

Network
- Are only necessary ports published?
- Are sensitive services internal?
- Are frontend and backend networks separated?

Secrets
- Are secrets mounted only into required services?
- Are secrets absent from environment variables and logs?

Resources
- Are memory, CPU, and PID limits appropriate?

Operations
- Are logs rotated?
- Are health checks configured?
- Is graceful shutdown supported?
```

---

# 58. Security review for your MQTT platform

A secure starting architecture is:

```text
Reverse proxy
- Frontend network
- Public 443
- Read-only configuration
- TLS certificates read-only
- No database volume

Dashboard/API
- Frontend + backend
- Non-root
- No capabilities
- Read-only root filesystem
- Database password secret
- No direct PostgreSQL storage mount

Mosquitto
- Backend plus published MQTT ports
- Configuration read-only
- Data volume writable
- TLS private key secret/read-only mount
- Only required ports published

MQTT consumer
- Backend only
- Non-root
- No host port
- Broker credentials secret
- Database credentials secret

PostgreSQL
- Backend only
- No published port
- Persistent database volume
- Password secret
- Graceful shutdown
```

Avoid giving every service access to every network, secret, or volume.

---

# 59. Practical MQTT daemon Dockerfile

A hardened multi-stage Dockerfile could begin with:

```dockerfile
# syntax=docker/dockerfile:1

FROM debian:13 AS builder

RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       build-essential \
       pkg-config \
       libmosquitto-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /src

COPY Makefile ./
COPY src/ ./src/
COPY include/ ./include/

RUN make clean \
    && make

FROM debian:13-slim AS runtime

RUN apt-get update \
    && apt-get install -y \
       --no-install-recommends \
       libmosquitto1 \
       ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd \
      --system \
      --gid 10001 \
      mqttgroup \
    && useradd \
      --system \
      --uid 10001 \
      --gid mqttgroup \
      --home-dir /nonexistent \
      --shell /usr/sbin/nologin \
      mqttuser

COPY --from=builder \
    /src/build/mqtt-service-daemon \
    /usr/local/bin/mqtt-service-daemon

USER 10001:10001

ENTRYPOINT ["/usr/local/bin/mqtt-service-daemon"]

CMD [
    "--config",
    "/etc/mqtt-service-daemon/config.conf"
]
```

Runtime configuration should be mounted read-only.

---

# 60. Secure MQTT daemon Compose service

```yaml
services:
  mqtt-daemon:
    image: mqtt-service-daemon:1.0.0

    user: "10001:10001"

    command:
      - --config
      - /etc/mqtt-service-daemon/config.conf

    environment:
      MQTT_PASSWORD_FILE: /run/secrets/mqtt-password

    secrets:
      - mqtt-password

    volumes:
      - type: bind
        source: ./config/mqtt-service-daemon.conf
        target: /etc/mqtt-service-daemon/config.conf
        read_only: true

    read_only: true

    tmpfs:
      - /tmp:size=16m,mode=1777

    cap_drop:
      - ALL

    security_opt:
      - no-new-privileges:true

    mem_limit: 128m
    cpus: 0.5
    pids_limit: 50

    restart: unless-stopped

    networks:
      - backend

secrets:
  mqtt-password:
    file: ./secrets/mqtt-password.txt
```

The daemon normally needs no published host port because it initiates the MQTT connection itself.

---

# 61. Common security mistakes

## Running every container as root

Fix:

```dockerfile
USER application-user
```

---

## Using `--privileged` to solve permissions

Fix the specific requirement instead:

```text
Correct ownership
Specific device
Specific capability
Specific mount
```

---

## Mounting the Docker socket into a web application

Do not do this unless the application is explicitly designed and secured as a Docker management service.

---

## Storing passwords in Dockerfiles

Never:

```dockerfile
ENV DB_PASSWORD=secret
```

---

## Passing build secrets through `ARG`

Never:

```dockerfile
ARG PRIVATE_TOKEN
```

Use BuildKit secret mounts.

---

## Publishing PostgreSQL to every interface

Avoid:

```yaml
ports:
  - "5432:5432"
```

when only internal containers need access.

---

## Using writable mounts for configuration

Prefer:

```yaml
read_only: true
```

for configuration and certificates.

---

## Disabling seccomp

Avoid:

```bash
--security-opt seccomp=unconfined
```

unless a verified requirement exists.

---

## Using untrusted `latest` images

Prefer explicit versions and review image provenance.

---

## Ignoring vulnerability results forever

Review, patch, rebuild, test, and document accepted risks.

---

# 62. Day 17 practical laboratory

## Exercise 1 — Root versus non-root

Run Alpine as root.

Run it again as UID 10001.

Attempt to write to:

```text
/root
/tmp
```

Compare results.

---

## Exercise 2 — Non-root application image

Modify the device API image to:

- Create UID 10001
    
- Copy files with appropriate ownership
    
- Run as UID 10001
    
- Confirm the API still works
    

---

## Exercise 3 — Capability dropping

Run the application with:

```text
cap_drop: ALL
```

Test every endpoint.

Confirm it needs no additional capability.

---

## Exercise 4 — No-new-privileges

Enable:

```yaml
security_opt:
  - no-new-privileges:true
```

Inspect the resulting container configuration.

---

## Exercise 5 — Read-only filesystem

Enable:

```yaml
read_only: true
```

Test the application.

Use `docker diff` and logs to discover any attempted writes.

Add only required `tmpfs` or volume mounts.

---

## Exercise 6 — Secure mounts

Review every mount in your project.

For each, classify it as:

```text
Read-only configuration
Writable persistent data
Temporary data
Unnecessary mount
Dangerous host exposure
```

---

## Exercise 7 — Compose secrets

Move the PostgreSQL password from the environment into a Compose secret.

Modify the API to read:

```text
DB_PASSWORD_FILE
```

Confirm the password does not appear in the API container environment.

---

## Exercise 8 — Build secret

Create a training build secret.

Use:

```dockerfile
RUN --mount=type=secret
```

Confirm the secret is not present in the final image.

---

## Exercise 9 — Image scan

Run:

```bash
docker scout cves IMAGE
```

Review high and critical findings.

Compare:

- Builder image
    
- Runtime image
    

Explain why the runtime image generally has fewer unnecessary packages.

---

## Exercise 10 — Network restriction

Create:

```text
frontend
backend internal
```

Place:

- API on both
    
- Database only on backend
    

Confirm the database has no published host port.

---

# 63. Day 17 command reference

```bash
# Run as a specific user
docker run \
  --user 10001:10001 \
  IMAGE

# Drop all Linux capabilities
docker run \
  --cap-drop ALL \
  IMAGE

# Add one capability
docker run \
  --cap-drop ALL \
  --cap-add NET_BIND_SERVICE \
  IMAGE

# Prevent privilege escalation
docker run \
  --security-opt no-new-privileges=true \
  IMAGE

# Make root filesystem read-only
docker run \
  --read-only \
  IMAGE

# Add temporary writable storage
docker run \
  --read-only \
  --tmpfs /tmp \
  IMAGE

# Limit processes
docker run \
  --pids-limit 100 \
  IMAGE

# Inspect configured user
docker image inspect IMAGE \
  --format '{{.Config.User}}'

# Inspect capabilities
docker inspect CONTAINER \
  --format 'CapDrop={{json .HostConfig.CapDrop}} CapAdd={{json .HostConfig.CapAdd}}'

# Inspect security options
docker inspect CONTAINER \
  --format '{{json .HostConfig.SecurityOpt}}'

# Inspect read-only setting
docker inspect CONTAINER \
  --format '{{.HostConfig.ReadonlyRootfs}}'

# Scan an image
docker scout cves IMAGE

# Inspect image components
docker scout sbom IMAGE

# Pass a build secret
docker build \
  --secret id=token,src=token.txt \
  -t IMAGE \
  .
```

---

# 64. Knowledge check

## Is root inside a container identical to unrestricted host root?

No. Docker normally applies namespace, capability, seccomp, and filesystem restrictions. However, container root remains more privileged than a non-root container user.

## Why run as a non-root user?

It limits what an attacker or faulty application can do after compromising the process.

## What are Linux capabilities?

Individually separated privilege units that replace the traditional all-or-nothing root privilege model.

## What does `cap_drop: ALL` do?

It removes all Linux capabilities from the container’s processes unless specific ones are added back.

## Why is `--privileged` dangerous?

It grants broad access and weakens important isolation mechanisms.

## What does `no-new-privileges` do?

It prevents processes from gaining additional privileges through execution mechanisms such as set-user-ID binaries.

## What does `read_only: true` do?

It makes the container’s normal root filesystem read-only.

## How should temporary writes be supported?

Through an explicit `tmpfs` such as `/tmp`.

## How should persistent writes be supported?

Through explicit named volumes or carefully controlled bind mounts.

## Why is mounting the Docker socket dangerous?

It can give the container control over Docker Engine and therefore extensive control over the host.

## Should seccomp normally be disabled?

No. Docker recommends retaining the default seccomp profile unless a carefully understood requirement exists. ([Docker Documentation](https://docs.docker.com/engine/security/seccomp/?utm_source=chatgpt.com "Seccomp security profiles for Docker"))

## Should passwords be stored in Dockerfile `ARG` or `ENV`?

No. Use build-secret mounts for build-time credentials and runtime secret mechanisms for application credentials. ([Docker Documentation](https://docs.docker.com/build/building/secrets/?utm_source=chatgpt.com "Build secrets"))

## What does Docker Scout do?

It analyzes image contents and reports software components and known vulnerabilities. ([Docker Documentation](https://docs.docker.com/scout/explore/analysis/?utm_source=chatgpt.com "Docker Scout image analysis"))

## Does zero reported vulnerabilities prove an application is secure?

No. Scanning covers only part of security. Application vulnerabilities, configuration mistakes, secrets exposure, excessive privileges, and host weaknesses may still exist.

---

# 65. Day 17 completion challenge

Complete this independently:

1. Inspect the default user of your API image.
    
2. Create a dedicated UID and GID.
    
3. Run the API as that user.
    
4. Confirm it cannot write to `/root`.
    
5. Confirm it can use `/tmp`.
    
6. Run the API with all capabilities dropped.
    
7. Confirm all endpoints still work.
    
8. Add `no-new-privileges`.
    
9. Inspect the applied security options.
    
10. Enable a read-only root filesystem.
    
11. Identify all failed write attempts.
    
12. Add a `tmpfs` only where required.
    
13. Keep application source immutable.
    
14. Mount configuration read-only.
    
15. Mount database storage only into PostgreSQL.
    
16. Remove unnecessary mounts.
    
17. Confirm the Docker socket is not mounted.
    
18. Confirm the API does not use host PID, IPC, or network namespaces.
    
19. Remove the database host port.
    
20. Bind the API host port only to `127.0.0.1`.
    
21. Create frontend and internal backend networks.
    
22. Attach PostgreSQL only to the backend.
    
23. Move the database password into a Compose secret.
    
24. Modify the API to support `DB_PASSWORD_FILE`.
    
25. Confirm the raw password is absent from container environment variables.
    
26. Protect the host secret file with restrictive permissions.
    
27. Exclude secret files from Git.
    
28. Create a BuildKit training secret.
    
29. Use it only during one build step.
    
30. Confirm it is absent from the final image.
    
31. Confirm it is absent from Dockerfile `ARG` and `ENV`.
    
32. Scan the final API image.
    
33. Review critical and high findings.
    
34. Rebuild with the latest approved base image.
    
35. Rescan and compare.
    
36. Confirm the runtime image contains no compiler.
    
37. Add memory, CPU, and PID limits.
    
38. Add log rotation.
    
39. Add health checks.
    
40. Add graceful stop periods.
    
41. Review every capability, mount, network, port, and secret.
    
42. Create a secure Compose configuration for your MQTT daemon.
    
43. Mount its configuration read-only.
    
44. Provide its MQTT password through a secret file.
    
45. Explain why least privilege reduces the impact of a successful application attack.
    

The central Day 17 model is:

```text
Trusted minimal image
        +
Non-root user
        +
Dropped capabilities
        +
No privilege escalation
        +
Read-only root filesystem
        +
Explicit writable paths
        +
Restricted networks and ports
        +
Protected secrets
        +
Resource limits
        +
Regular scanning and rebuilding
        ↓
Reduced attack surface
```

The most important operational lesson is:

> Container security is not one switch. It is the combined result of trusted images, minimal runtime contents, non-root execution, narrow privileges, safe mounts, protected secrets, restricted networking, resource limits, continuous scanning, and a well-protected Docker host.