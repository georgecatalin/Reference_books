
Day 8 showed that files stored only in a container’s writable layer disappear when the container is removed.

Today you will solve that problem using **bind mounts**.

A bind mount connects an existing host file or directory directly to a path inside a container:

```text
Host directory
/home/george/docker-course/day9/website
                    ↓
Container directory
/usr/share/nginx/html
```

The container reads and writes the actual host files.

By the end of Day 9, you should understand:

- What bind mounts are
    
- Bind mounts versus container writable layers
    
- The `--mount` and `-v` syntaxes
    
- File and directory mounts
    
- Read-only mounts
    
- How mounts can hide image content
    
- Host and container permissions
    
- Relative versus absolute paths
    
- Development use cases
    
- Configuration-file mounting
    
- SQLite and Mosquitto bind-mount examples
    
- Security risks of exposing host paths
    

---

## 1. What a bind mount is

A bind mount maps a specific path on the Docker host into a container.

Example:

```bash
docker run \
  --mount type=bind,source=/home/george/website,target=/usr/share/nginx/html \
  nginx
```

The mapping is:

```text
Docker host:
/home/george/website

        ↓ mounted as

Container:
/usr/share/nginx/html
```

When Nginx reads:

```text
/usr/share/nginx/html/index.html
```

it is actually reading:

```text
/home/george/website/index.html
```

from the host.

The data’s lifetime is now independent of the container.

---

# 2. Bind mount versus container writable layer

Without a mount:

```text
Container
└── /data/file.txt

Storage location:
container writable layer

Container removed:
file disappears
```

With a bind mount:

```text
Host
└── /home/george/data/file.txt
          ↓
Container
└── /data/file.txt

Container removed:
host file remains
```

The container is only given a view of the host path.

---

# 3. First bind-mount experiment

Create a directory on the host:

```bash
mkdir -p ~/docker-course/day9/website
cd ~/docker-course/day9
```

Create a webpage:

```bash
cat > website/index.html <<'EOF'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Day 9</title>
</head>
<body>
    <h1>Docker bind mount</h1>
    <p>This file is stored on the Docker host.</p>
</body>
</html>
EOF
```

Check:

```bash
cat website/index.html
```

Run Nginx:

```bash
docker run -d \
  --name day9-nginx \
  -p 8080:80 \
  --mount type=bind,source="$PWD/website",target=/usr/share/nginx/html \
  nginx:alpine
```

Test:

```bash
curl http://localhost:8080
```

You should see your host HTML file.

---

# 4. Understanding the `--mount` syntax

The command contains:

```bash
--mount type=bind,source="$PWD/website",target=/usr/share/nginx/html
```

Breakdown:

```text
type=bind
```

Use a host filesystem path.

```text
source="$PWD/website"
```

The host directory being mounted.

```text
target=/usr/share/nginx/html
```

The path where it appears inside the container.

The general form is:

```bash
--mount type=bind,source=HOST_PATH,target=CONTAINER_PATH
```

---

# 5. Why `$PWD` is useful

`$PWD` contains your current directory.

Check:

```bash
echo "$PWD"
```

If you are in:

```text
/home/george/docker-course/day9
```

then:

```text
$PWD/website
```

expands to:

```text
/home/george/docker-course/day9/website
```

Bind mounts are clearest when the source is an absolute path.

This is preferable to relying on an ambiguous relative path.

---

# 6. Modify the file on the host

Leave the container running.

Change the host file:

```bash
cat > website/index.html <<'EOF'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Day 9 Updated</title>
</head>
<body>
    <h1>The host file changed</h1>
    <p>Nginx sees the new content immediately.</p>
</body>
</html>
EOF
```

Test again:

```bash
curl http://localhost:8080
```

The new content should appear without:

- Rebuilding the image
    
- Restarting the container
    
- Copying the file with `docker cp`
    

Why?

Because the container reads the host file directly.

---

# 7. Modify the file from inside the container

Enter the container:

```bash
docker exec -it day9-nginx sh
```

Inside:

```sh
echo '<h1>Changed from inside the container</h1>' \
  > /usr/share/nginx/html/index.html
```

Exit:

```sh
exit
```

Now inspect the host file:

```bash
cat website/index.html
```

It was changed.

This demonstrates:

> A writable bind mount allows container processes to modify host files.

That is useful, but also potentially dangerous.

---

# 8. Container removal does not remove host data

Remove the container:

```bash
docker rm -f day9-nginx
```

Check the host file:

```bash
cat website/index.html
```

It still exists.

Create a replacement container:

```bash
docker run -d \
  --name day9-nginx \
  -p 8080:80 \
  --mount type=bind,source="$PWD/website",target=/usr/share/nginx/html \
  nginx:alpine
```

Test:

```bash
curl http://localhost:8080
```

The replacement container sees the same data.

The lifecycle is:

```text
Host directory survives
        ↓
Old container removed
        ↓
New container created
        ↓
Same host directory mounted
        ↓
Data remains available
```

---

# 9. The shorter `-v` syntax

The same mount can be written as:

```bash
docker run -d \
  --name day9-nginx-short \
  -p 8081:80 \
  -v "$PWD/website:/usr/share/nginx/html" \
  nginx:alpine
```

The syntax is:

```text
-v HOST_PATH:CONTAINER_PATH
```

Example:

```bash
-v "$PWD/website:/usr/share/nginx/html"
```

Both `--mount` and `-v` can create bind mounts.

---

# 10. `--mount` versus `-v`

## `--mount`

```bash
--mount type=bind,source="$PWD/website",target=/usr/share/nginx/html
```

Advantages:

- More explicit
    
- Easier to read in complex commands
    
- Clearly identifies source and target
    
- Safer handling when paths are missing
    
- Preferred for documentation and production scripts
    

## `-v`

```bash
-v "$PWD/website:/usr/share/nginx/html"
```

Advantages:

- Shorter
    
- Commonly used
    
- Convenient interactively
    

A good habit is:

- Use `--mount` while learning and in scripts
    
- Understand `-v` because you will see it everywhere
    

---

# 11. Important behavior when the source path is missing

Suppose this host path does not exist:

```text
/home/george/missing-directory
```

With `--mount`:

```bash
docker run --rm \
  --mount type=bind,source=/home/george/missing-directory,target=/data \
  alpine
```

Docker normally reports an error because the source path does not exist.

With `-v`:

```bash
docker run --rm \
  -v /home/george/missing-directory:/data \
  alpine
```

Docker may create the missing host path as a directory.

This can hide mistakes.

For example, you intended to mount a file:

```text
/home/george/config/app.conf
```

but misspelled it. Docker may create a directory with that name, causing confusing application errors.

This is one reason `--mount` is often safer.

---

# 12. Directory bind mounts

The Nginx example mounted a directory:

```bash
--mount type=bind,source="$PWD/website",target=/usr/share/nginx/html
```

Directory contents appear inside the container:

```text
Host website/
├── index.html
├── style.css
└── app.js

Container /usr/share/nginx/html/
├── index.html
├── style.css
└── app.js
```

Add another host file:

```bash
echo 'body { font-family: sans-serif; }' > website/style.css
```

Check inside:

```bash
docker exec day9-nginx \
  ls -la /usr/share/nginx/html
```

The container sees the new file.

---

# 13. File bind mounts

You can mount one file rather than a whole directory.

Create:

```bash
mkdir -p ~/docker-course/day9/config
```

Create an Nginx configuration:

```bash
cat > config/default.conf <<'EOF'
server {
    listen 80;
    server_name _;

    location / {
        root /usr/share/nginx/html;
        index index.html;
    }

    location /health {
        default_type application/json;
        return 200 '{"status":"healthy"}';
    }
}
EOF
```

Run:

```bash
docker rm -f day9-nginx 2>/dev/null
```

```bash
docker run -d \
  --name day9-nginx \
  -p 8080:80 \
  --mount type=bind,source="$PWD/website",target=/usr/share/nginx/html,readonly \
  --mount type=bind,source="$PWD/config/default.conf",target=/etc/nginx/conf.d/default.conf,readonly \
  nginx:alpine
```

Test:

```bash
curl http://localhost:8080
```

Then:

```bash
curl http://localhost:8080/health
```

You mounted:

- A host directory as web content
    
- A host file as Nginx configuration
    

---

# 14. Read-only bind mounts

The previous command used:

```text
readonly
```

Example:

```bash
--mount type=bind,source="$PWD/website",target=/usr/share/nginx/html,readonly
```

This allows the container to read the files but not modify them.

Try:

```bash
docker exec day9-nginx \
  sh -c 'echo changed > /usr/share/nginx/html/index.html'
```

It should fail with a read-only filesystem error.

The shorter syntax is:

```bash
-v "$PWD/website:/usr/share/nginx/html:ro"
```

Prefer read-only mounts when the application does not need write access.

---

# 15. Why read-only is safer

A writable bind mount gives the container write access to the host path.

If the application is compromised, an attacker may be able to:

- Modify host configuration
    
- Delete source code
    
- Replace scripts
    
- Corrupt data
    
- Add malicious files
    

A read-only mount limits this risk.

Use:

```text
readonly
```

or:

```text
:ro
```

for:

- Configuration files
    
- Source code not modified at runtime
    
- TLS certificates
    
- Static website files
    
- Templates
    
- Reference data
    

Use writable mounts only where the application genuinely needs to write.

---

# 16. Mounts hide existing image content

This is one of the most important bind-mount behaviors.

The Nginx image already contains files under:

```text
/usr/share/nginx/html
```

When you mount a host directory there:

```bash
--mount type=bind,source="$PWD/website",target=/usr/share/nginx/html
```

the host directory covers the image directory.

The original image files still exist in the image, but they are hidden while the mount is active.

Conceptually:

```text
Image content:
/usr/share/nginx/html/index.html
    default Nginx page

Bind mount:
host website/ mounted at /usr/share/nginx/html

Container sees:
host website content
```

---

# 17. Demonstrate hidden image files

Run Nginx without a mount:

```bash
docker run --rm \
  nginx:alpine \
  cat /usr/share/nginx/html/index.html
```

This shows the image’s default page.

Now run with the bind mount:

```bash
docker run --rm \
  --mount type=bind,source="$PWD/website",target=/usr/share/nginx/html \
  nginx:alpine \
  cat /usr/share/nginx/html/index.html
```

This shows your host file instead.

The bind mount obscures the image’s original content at that path.

---

# 18. A common mount mistake

Suppose your image contains:

```text
/app
├── app.py
├── requirements.txt
└── templates/
```

You then mount an empty host directory over `/app`:

```bash
docker run \
  --mount type=bind,source="$PWD/empty",target=/app \
  application:1.0
```

Inside the container, `/app` now appears empty.

The application may fail:

```text
python: can't open file '/app/app.py'
```

The image is not broken. The mount is hiding the files.

When a container works without a mount but fails with one, inspect whether the mount covers required image content.

---

# 19. Inspect bind mounts

Run:

```bash
docker inspect day9-nginx \
  --format '{{json .Mounts}}'
```

You should see information including:

- Mount type
    
- Source host path
    
- Destination container path
    
- Read/write state
    

For a more readable form:

```bash
docker inspect day9-nginx \
  --format '{{range .Mounts}}Type={{.Type}} Source={{.Source}} Destination={{.Destination}} RW={{.RW}}{{println}}{{end}}'
```

Example conceptually:

```text
Type=bind
Source=/home/george/docker-course/day9/website
Destination=/usr/share/nginx/html
RW=false
```

---

# 20. Bind mount lifecycle

A bind mount is recorded in the container configuration.

If you stop and start the same container:

```bash
docker stop day9-nginx
docker start day9-nginx
```

the mount remains.

If you remove the container:

```bash
docker rm -f day9-nginx
```

the host data remains, but the mount configuration disappears with the container.

A replacement container must specify the mount again.

---

# 21. Bind mounts are host-dependent

Suppose your command uses:

```text
/home/george/docker-course/day9/website
```

The application depends on that exact host path existing.

On another server, the path may be:

```text
/opt/app/website
```

or may not exist.

This makes bind mounts:

- Easy to understand
    
- Convenient during development
    
- Closely coupled to host filesystem structure
    

Docker volumes, introduced on Day 10, reduce some of this host-path coupling.

---

# 22. Bind mounts are created on the Docker daemon’s host

This matters when the Docker CLI talks to a remote Docker daemon.

Suppose your laptop runs:

```bash
docker --context remote-server run \
  --mount type=bind,source="$PWD/data",target=/data \
  alpine
```

The source path must exist on the **remote Docker host**, not merely on your laptop.

The Docker CLI does not automatically upload the host directory as a bind mount.

The source is interpreted from the Docker daemon’s filesystem.

---

# 23. Docker Desktop and virtualized hosts

On native Linux, the Docker daemon directly accesses Linux host paths.

With Docker Desktop on Windows or macOS, containers run through an additional Linux environment.

Docker Desktop handles file sharing between:

- Windows or macOS filesystem
    
- Docker’s Linux environment
    
- Containers
    

This may introduce:

- Slower file access
    
- File-sharing permission prompts
    
- Path-format differences
    
- Case-sensitivity differences
    
- Line-ending problems
    

For your Linux VMs, bind-mount behavior is more direct.

---

# 24. Host-to-container permissions

Bind mounts preserve host filesystem ownership and permissions.

Suppose the host file belongs to:

```text
UID 1000
GID 1000
```

Inside the container, the application process may run as:

```text
UID 999
GID 999
```

Linux permission checks use numeric IDs.

The usernames do not need to match. The numeric ownership and mode bits matter.

This can cause:

```text
Permission denied
```

even when the file appears to have a familiar username on the host.

---

# 25. Inspect host ownership numerically

Run:

```bash
ls -ln website
```

The `-n` option shows numeric IDs.

Example:

```text
-rw-r--r-- 1 1000 1000 ... index.html
```

Inside the container:

```bash
docker exec day9-nginx \
  ls -ln /usr/share/nginx/html
```

You should see corresponding numeric ownership.

The container does not magically translate host ownership into its own application user.

---

# 26. Read permission example

A static Nginx file typically needs to be readable.

On the host:

```bash
chmod 644 website/index.html
```

This gives:

```text
Owner: read and write
Group: read
Others: read
```

Directory traversal requires execute permission on directories:

```bash
chmod 755 website
```

A common safe static-file arrangement is:

```text
Directory: 755
Files: 644
```

Do not automatically use:

```bash
chmod -R 777
```

That grants unnecessary access and hides the actual permission issue.

---

# 27. Writable directory example

Create a host data directory:

```bash
mkdir -p writable-data
```

Run Alpine as the default root user:

```bash
docker run --rm \
  --mount type=bind,source="$PWD/writable-data",target=/data \
  alpine \
  sh -c 'echo "written by container" > /data/message.txt'
```

Check:

```bash
ls -ln writable-data
cat writable-data/message.txt
```

The created file may be owned by root:

```text
UID 0
GID 0
```

on the host.

This can become inconvenient for your normal host user.

---

# 28. Run the container with your host UID and GID

Check:

```bash
id -u
id -g
```

Run:

```bash
docker run --rm \
  --user "$(id -u):$(id -g)" \
  --mount type=bind,source="$PWD/writable-data",target=/data \
  alpine \
  sh -c 'echo "written using host UID" > /data/user-message.txt'
```

Check:

```bash
ls -ln writable-data
```

The file should be owned by your numeric host user and group.

This technique is useful for development tools that write files into your source directory.

However, the image and application must be able to run correctly under the supplied UID.

---

# 29. Non-root application and writable mounts

Suppose your application image runs as:

```dockerfile
USER appuser
```

and `appuser` has UID 10001.

If the mounted host directory is owned by UID 1000 with mode `755`, the application can read it but cannot write to it.

Possible solutions include:

- Change host directory ownership
    
- Change group ownership
    
- Grant appropriate group write permission
    
- Run with a compatible UID
    
- Design the image with configurable user IDs
    
- Use a Docker volume with suitable initialization
    
- Avoid writes when unnecessary
    

Do not default to root merely to bypass permission design.

---

# 30. Create a controlled writable directory

Create:

```bash
mkdir -p application-data
```

Change ownership to a known UID:

```bash
sudo chown 10001:10001 application-data
```

Set permissions:

```bash
sudo chmod 750 application-data
```

A container process running as UID 10001 can now write there.

Inspect:

```bash
ls -ldn application-data
```

Be deliberate about:

- Owner
    
- Group
    
- Read permission
    
- Write permission
    
- Directory traversal permission
    

---

# 31. Development source-code mounts

Bind mounts are widely used during development.

Suppose your image contains Python and dependencies, but you mount source code from the host:

```bash
docker run --rm \
  -p 5000:5000 \
  --mount type=bind,source="$PWD/src",target=/app/src \
  application-dev:1.0
```

Benefits:

- Edit source on the host
    
- Container sees changes immediately
    
- No image rebuild for every source edit
    
- Use your preferred host editor
    

This is a development workflow.

Production normally uses application source packaged into an immutable image.

---

# 32. Development versus production

## Development

Bind mount source:

```text
Host source code
      ↓
Container runtime
```

Advantages:

- Fast editing
    
- Immediate feedback
    
- Easy debugging
    

Disadvantages:

- Depends on host paths
    
- Host and container may differ
    
- Permission problems
    
- Harder to reproduce exactly
    

## Production

Copy source into image:

```text
Source control
     ↓ build
Versioned image
     ↓ run
Container
```

Advantages:

- Reproducible
    
- Immutable artifact
    
- Portable
    
- Easier rollback
    

Use the right method for the environment.

---

# 33. Build a small live-edit development example

Create:

```bash
mkdir -p live-site
```

Create:

```bash
cat > live-site/index.html <<'EOF'
<h1>Live development version 1</h1>
EOF
```

Run:

```bash
docker run -d \
  --name day9-live-site \
  -p 8082:80 \
  --mount type=bind,source="$PWD/live-site",target=/usr/share/nginx/html,readonly \
  nginx:alpine
```

Test:

```bash
curl http://localhost:8082
```

Change:

```bash
echo '<h1>Live development version 2</h1>' \
  > live-site/index.html
```

Test again:

```bash
curl http://localhost:8082
```

No rebuild was needed.

---

# 34. Mount configuration separately from data

A good structure is:

```text
Host project/
├── config/
│   └── application.conf
├── data/
└── source/
```

Mount according to purpose:

```text
config:
read-only

data:
writable

source:
read-only or writable during development
```

Example:

```bash
docker run \
  --mount type=bind,source="$PWD/config/app.conf",target=/etc/app/app.conf,readonly \
  --mount type=bind,source="$PWD/data",target=/var/lib/app \
  application
```

Separating concerns improves security and clarity.

---

# 35. Mounting a configuration file

Create:

```bash
mkdir -p app-config
```

Create:

```bash
cat > app-config/application.conf <<'EOF'
environment=development
log_level=debug
EOF
```

Run:

```bash
docker run --rm \
  --mount type=bind,source="$PWD/app-config/application.conf",target=/etc/application.conf,readonly \
  alpine \
  cat /etc/application.conf
```

This allows the same image to use different host-provided configuration files.

The container cannot modify the file because the mount is read-only.

---

# 36. Be careful when mounting files onto directories

Suppose the target path should be a file:

```text
/etc/application.conf
```

If the source path is accidentally a directory, the container receives a directory at the target path.

The application may report errors such as:

```text
Is a directory
```

Always verify host source type:

```bash
file app-config/application.conf
```

Inspect:

```bash
ls -ld app-config/application.conf
```

Use `--mount` so missing sources produce clearer errors.

---

# 37. Mounting over `/etc`

Avoid broad mounts such as:

```bash
--mount type=bind,source="$PWD/config",target=/etc
```

This hides the container’s entire `/etc` directory and will likely break the image.

Mount the specific file or subdirectory needed:

```bash
--mount type=bind,source="$PWD/config/app.conf",target=/etc/app/app.conf,readonly
```

Use the smallest possible mount scope.

---

# 38. Security danger: mounting sensitive host directories

This is extremely dangerous:

```bash
docker run \
  --mount type=bind,source=/,target=/host \
  alpine
```

The container can potentially access the entire host filesystem under `/host`.

If writable, it may modify sensitive files.

Similarly dangerous:

```bash
-v /etc:/host-etc
```

```bash
-v /home:/host-home
```

```bash
-v /var/run/docker.sock:/var/run/docker.sock
```

Only mount the exact paths required.

---

# 39. Docker socket warning

The Docker socket is commonly:

```text
/var/run/docker.sock
```

Mounting it:

```bash
-v /var/run/docker.sock:/var/run/docker.sock
```

allows software in the container to control the Docker daemon if it has a compatible client or API access.

That may allow it to:

- Start privileged containers
    
- Mount host filesystems
    
- Read secrets from containers
    
- Stop services
    
- Effectively gain host-level control
    

Do not mount the Docker socket casually.

It is not an ordinary harmless file.

---

# 40. SELinux considerations

On SELinux-enabled systems, bind mounts may need appropriate labeling.

You may see syntax such as:

```bash
-v "$PWD/data:/data:Z"
```

or:

```bash
-v "$PWD/data:/data:z"
```

Conceptually:

- `:Z` gives the content a private container label
    
- `:z` allows shared use by multiple containers
    

This is relevant on distributions such as:

- Fedora
    
- Red Hat Enterprise Linux
    
- CentOS Stream
    

Do not use these options without understanding the host’s SELinux setup.

Ubuntu and Debian commonly use AppArmor rather than SELinux by default.

---

# 41. Mount propagation

Bind mounts support advanced propagation options, controlling how nested mounts are shared between host and container.

Examples include:

```text
rprivate
rshared
rslave
```

This is not required for ordinary application development.

The safe default is usually sufficient.

Mount propagation becomes relevant for:

- Containerized storage systems
    
- Docker-in-Docker-like tools
    
- Kubernetes node components
    
- Advanced system-management containers
    

Do not change propagation settings casually.

---

# 42. Bind mounts and symbolic links

Suppose the source path contains symbolic links.

The container may access the linked target depending on:

- Where the symlink points
    
- Host filesystem permissions
    
- Mount boundaries
    
- Platform behavior
    

Avoid relying on complicated symlink structures in production mounts.

Prefer clear, explicit host directories.

Inspect:

```bash
readlink -f PATH
```

to see the resolved host path.

---

# 43. Bind mounts and line endings

When developing on Windows and running Linux containers, scripts may use CRLF endings.

A mounted shell script may fail with:

```text
/bin/sh^M: bad interpreter
```

Use Unix LF line endings for Linux scripts.

Check:

```bash
file script.sh
```

Convert:

```bash
dos2unix script.sh
```

Also ensure executable permission:

```bash
chmod +x script.sh
```

---

# 44. Bind mount an executable script

Create:

```bash
mkdir -p scripts
```

Create:

```bash
cat > scripts/start.sh <<'EOF'
#!/bin/sh
echo "Started from a bind-mounted script"
echo "Container hostname: $(hostname)"
EOF
```

Set permission:

```bash
chmod +x scripts/start.sh
```

Run:

```bash
docker run --rm \
  --mount type=bind,source="$PWD/scripts/start.sh",target=/usr/local/bin/start.sh,readonly \
  alpine \
  /usr/local/bin/start.sh
```

This works because:

- The file exists on the host
    
- It is executable
    
- It uses Linux line endings
    
- The container has `/bin/sh`
    

---

# 45. SQLite bind-mount design

Your PHP dashboard uses:

```text
data/cockpit.sqlite
```

A simple development arrangement could mount the entire data directory:

```bash
docker run -d \
  --name mqtt-dashboard \
  -p 8080:80 \
  --mount type=bind,source="$PWD/data",target=/var/www/html/data \
  mqtt-dashboard:1.0
```

The application writes:

```text
/var/www/html/data/cockpit.sqlite
```

which corresponds to:

```text
$PWD/data/cockpit.sqlite
```

on the host.

Removing the container no longer removes the database file.

---

# 46. SQLite directory permissions

SQLite needs more than write access to the database file.

It may also create files such as:

```text
cockpit.sqlite-journal
cockpit.sqlite-wal
cockpit.sqlite-shm
```

Therefore, the application commonly needs write permission on the directory containing the database.

This is insufficient in some situations:

```text
database file writable
directory not writable
```

A safer permission check is:

```bash
ls -ldn data
ls -ln data
```

Ensure the application’s container UID can:

- Read the database
    
- Write the database
    
- Create journal/WAL files
    
- Rename temporary files where required
    

Your earlier error:

```text
attempt to write a readonly database
```

can result from directory permission problems, not only file permission problems.

---

# 47. Test SQLite-style directory writing

Create:

```bash
mkdir -p sqlite-data
```

Run as an arbitrary non-root UID:

```bash
docker run --rm \
  --user 10001:10001 \
  --mount type=bind,source="$PWD/sqlite-data",target=/data \
  alpine \
  sh -c '
    echo database > /data/app.sqlite &&
    echo journal > /data/app.sqlite-journal
  '
```

If it fails, inspect:

```bash
ls -ldn sqlite-data
```

Grant ownership deliberately:

```bash
sudo chown 10001:10001 sqlite-data
```

Try again.

This simulates the directory-level requirements of SQLite.

---

# 48. Mosquitto bind-mount design

A development Mosquitto container may use:

```text
project/
└── mosquitto/
    ├── config/
    │   └── mosquitto.conf
    ├── data/
    └── log/
```

Create:

```bash
mkdir -p \
  mosquitto/config \
  mosquitto/data \
  mosquitto/log
```

Example configuration:

```bash
cat > mosquitto/config/mosquitto.conf <<'EOF'
listener 1883
allow_anonymous true

persistence true
persistence_location /mosquitto/data/

log_dest stdout
EOF
```

Run:

```bash
docker run -d \
  --name day9-mosquitto \
  -p 1883:1883 \
  --mount type=bind,source="$PWD/mosquitto/config/mosquitto.conf",target=/mosquitto/config/mosquitto.conf,readonly \
  --mount type=bind,source="$PWD/mosquitto/data",target=/mosquitto/data \
  eclipse-mosquitto:2
```

Here:

- Configuration is read-only
    
- Persistence directory is writable
    
- Logs go to standard output
    

---

# 49. Mosquitto permission considerations

The Mosquitto image may run as a non-root user.

The host data directory must be writable by that user’s numeric UID.

Inspect the image’s configured user:

```bash
docker image inspect eclipse-mosquitto:2 \
  --format '{{.Config.User}}'
```

Inspect the running process:

```bash
docker exec day9-mosquitto id
```

Inspect host ownership:

```bash
ls -ldn mosquitto/data
```

Do not blindly make the directory world-writable.

Set ownership or group access according to the container user.

---

# 50. Test Mosquitto persistence conceptually

Publish a retained message:

```bash
mosquitto_pub \
  -h localhost \
  -p 1883 \
  -t training/day9 \
  -m "Persistent retained message" \
  -r
```

Read it:

```bash
mosquitto_sub \
  -h localhost \
  -p 1883 \
  -t training/day9 \
  -C 1 \
  -v
```

Stop the broker gracefully:

```bash
docker stop day9-mosquitto
```

Remove it:

```bash
docker rm day9-mosquitto
```

Recreate it with the same data mount.

Then subscribe again.

Whether the retained message survives depends on:

- Persistence configuration
    
- Graceful database write
    
- Correct directory permissions
    
- Reusing the same host data directory
    

The key lesson is that broker state can be kept independently of the container.

---

# 51. Bind-mount your Day 7 application source

For a development experiment, use your Day 7 project.

Suppose the image expects source under:

```text
/app
```

Mounting your entire source directory over `/app` may hide installed or generated files expected there.

A safer development image should deliberately separate:

```text
/app/source
```

from runtime dependencies.

For example:

```dockerfile
FROM python:3.13-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

WORKDIR /app/source

CMD ["gunicorn", "--bind", "0.0.0.0:5000", "app:app"]
```

Then run:

```bash
docker run -d \
  --name dashboard-dev \
  -p 8085:5000 \
  --mount type=bind,source="$PWD/source",target=/app/source,readonly \
  dashboard-dev:1.0
```

The dependency installation remains in the image while source is mounted separately.

---

# 52. Bind mounts and hot reload

Some development servers can automatically reload when source files change.

For Flask development:

```bash
flask --app app run \
  --host=0.0.0.0 \
  --port=5000 \
  --debug
```

With source bind-mounted, editing `app.py` may trigger reload.

This is convenient during development.

Do not use debug servers or debug mode in production because they may:

- Be less robust
    
- Expose debugging interfaces
    
- Restart unexpectedly
    
- Handle traffic inefficiently
    

Development convenience and production correctness are different objectives.

---

# 53. Backing up bind-mounted data

Because bind-mounted data exists at a known host path, normal host tools can back it up.

Example:

```bash
tar czf sqlite-data-backup.tar.gz \
  sqlite-data/
```

However, applications such as databases may need:

- Graceful shutdown
    
- A database-aware backup command
    
- Filesystem snapshots
    
- Transaction consistency
    

Copying a database file while it is actively changing may produce an inconsistent backup.

Storage persistence and backup correctness are separate topics.

---

# 54. Bind mount versus copying into the image

Use `COPY` when:

- The file is part of the application release
    
- Every container should receive the same version
    
- You want a self-contained image
    
- The file should be immutable at runtime
    

Use a bind mount when:

- Editing source during development
    
- Supplying host-managed configuration
    
- Persisting files in a known host directory
    
- Sharing host-generated files
    
- Inspecting generated outputs easily
    

Example:

```dockerfile
COPY app.py /app/app.py
```

is appropriate for a production application image.

Example:

```bash
--mount type=bind,source="$PWD/app.py",target=/app/app.py,readonly
```

may be appropriate during development.

---

# 55. Bind mount versus environment variables

Use environment variables for small scalar settings:

```text
APP_ENV=production
LOG_LEVEL=info
DB_HOST=database
```

Use mounted files for:

- Complex configuration
    
- Certificates
    
- Keys
    
- Large configuration documents
    
- Configuration with exact formatting
    
- Applications that expect file-based configuration
    

Example:

```bash
--mount type=bind,source="$PWD/nginx.conf",target=/etc/nginx/nginx.conf,readonly
```

Do not force every configuration type into environment variables.

---

# 56. Avoid mounting host package directories

For a Python application, avoid mounting the host virtual environment into a Linux container:

```text
host .venv/
```

It may contain:

- Host-specific paths
    
- Architecture-specific binaries
    
- Different Python versions
    
- Incompatible shared libraries
    

Install dependencies inside the image.

Similarly, avoid mounting host-built `node_modules` into a container when host and container platforms differ.

Mount source code, but build runtime dependencies for the container environment.

---

# 57. Diagnosing a mount-related failure

Use this order.

## Is the container running?

```bash
docker ps -a
```

## What do the logs say?

```bash
docker logs CONTAINER
```

## What mounts were applied?

```bash
docker inspect CONTAINER \
  --format '{{json .Mounts}}'
```

## Does the source exist on the host?

```bash
ls -ld HOST_PATH
```

## Is it a file or directory?

```bash
file HOST_PATH
```

## What are the numeric permissions?

```bash
ls -ldn HOST_PATH
```

## What user runs inside the container?

```bash
docker exec CONTAINER id
```

## Can that user read or write the target?

```bash
docker exec CONTAINER \
  ls -ldn CONTAINER_PATH
```

This systematic approach is better than immediately applying `chmod 777`.

---

# 58. Common bind-mount problems

## Source path does not exist

Use:

```bash
ls -ld "$PWD/path"
```

Prefer `--mount` for clearer failure behavior.

---

## Wrong source path

Check:

```bash
echo "$PWD"
readlink -f path
```

---

## File mounted where directory is expected

Inspect both source and target expectations.

---

## Directory mounted over application code

The mount may hide image files.

Inspect:

```bash
docker inspect CONTAINER \
  --format '{{json .Mounts}}'
```

---

## Permission denied

Compare:

```bash
ls -ldn HOST_PATH
docker exec CONTAINER id
```

---

## Container creates root-owned host files

Run with an appropriate user or design ownership explicitly.

---

## Changes not visible

Confirm that:

- You edited the mounted source
    
- The container uses the expected target
    
- Application caching is not involved
    
- Browser cache is not involved
    
- The application reloads changed files
    

---

## Read-only filesystem error

The mount may intentionally use:

```text
readonly
```

or:

```text
:ro
```

Remove read-only only when writes are genuinely required.

---

# 59. Day 9 practical laboratory

## Exercise 1 — Static website mount

Create a host website directory.

Run Nginx with:

```bash
--mount type=bind,...
```

Publish it on port 8080.

Verify the page.

Modify the host file and verify the change appears.

---

## Exercise 2 — Container-to-host write

Mount the directory writable.

Change the page from inside the container.

Confirm the host file changes.

Then recreate the container and confirm the change remains.

---

## Exercise 3 — Read-only mount

Recreate the container using:

```text
readonly
```

Attempt to modify the file from inside.

Confirm the write fails.

---

## Exercise 4 — File-based configuration

Mount a custom Nginx configuration file read-only.

Add a `/health` endpoint.

Test:

```bash
curl http://localhost:8080/health
```

---

## Exercise 5 — Hidden image content

Run Nginx without a mount and inspect its default HTML.

Run it with your website directory mounted at the same path.

Explain why the default page disappears.

---

## Exercise 6 — Inspect mounts

Use:

```bash
docker inspect CONTAINER \
  --format '{{range .Mounts}}Type={{.Type}} Source={{.Source}} Destination={{.Destination}} RW={{.RW}}{{println}}{{end}}'
```

Identify every source, destination, and access mode.

---

## Exercise 7 — Permissions

Mount a writable directory into Alpine.

Write a file as root.

Inspect its host UID.

Write another file using:

```bash
--user "$(id -u):$(id -g)"
```

Compare ownership.

---

## Exercise 8 — SQLite simulation

Mount a host directory at `/data`.

Run as UID 10001.

Attempt to create:

```text
/data/application.sqlite
/data/application.sqlite-journal
```

Fix directory ownership without using `777`.

---

## Exercise 9 — Mosquitto

Mount:

- A read-only Mosquitto configuration file
    
- A writable data directory
    

Enable persistence.

Publish and retrieve a retained message.

Remove and recreate the broker with the same host data directory.

---

## Exercise 10 — Security review

Review these mounts and explain their risk:

```bash
-v /:/host
```

```bash
-v /etc:/host-etc
```

```bash
-v /var/run/docker.sock:/var/run/docker.sock
```

Then explain how to reduce the mounted scope.

---

# 60. Day 9 command reference

```bash
# Bind-mount a directory
docker run \
  --mount type=bind,source=HOST_DIR,target=CONTAINER_DIR \
  IMAGE

# Bind-mount a file
docker run \
  --mount type=bind,source=HOST_FILE,target=CONTAINER_FILE \
  IMAGE

# Read-only bind mount
docker run \
  --mount type=bind,source=HOST_PATH,target=CONTAINER_PATH,readonly \
  IMAGE

# Short syntax
docker run \
  -v HOST_PATH:CONTAINER_PATH \
  IMAGE

# Short read-only syntax
docker run \
  -v HOST_PATH:CONTAINER_PATH:ro \
  IMAGE

# Run with host user and group IDs
docker run \
  --user "$(id -u):$(id -g)" \
  IMAGE

# Inspect mounts
docker inspect CONTAINER \
  --format '{{json .Mounts}}'

# Show readable mount information
docker inspect CONTAINER \
  --format '{{range .Mounts}}Type={{.Type}} Source={{.Source}} Destination={{.Destination}} RW={{.RW}}{{println}}{{end}}'

# Inspect numeric ownership
ls -ldn HOST_PATH

# Resolve an absolute path
readlink -f HOST_PATH
```

---

# 61. Knowledge check

## What is a bind mount?

A mapping that makes a specific host file or directory available at a path inside a container.

## Does removing the container delete bind-mounted host files?

No. The files belong to the host filesystem.

## Can a writable bind mount modify host files?

Yes. Container writes are writes to the host source path.

## How do you make a bind mount read-only?

Use `readonly` with `--mount` or `:ro` with `-v`.

## What happens to image files under a mount target?

They are hidden while the mount is active.

## Why is `--mount` often safer than `-v`?

It is more explicit and normally fails when a bind source path does not exist rather than silently creating a directory.

## Why can permission errors occur?

The container process accesses the host filesystem using numeric user and group IDs and host permission bits.

## Why is `chmod 777` a poor default solution?

It grants excessive access and hides the real ownership and permission problem.

## When are bind mounts especially useful?

Development source code, host-managed configuration, and persistent data that should be visible at a known host path.

## Why is mounting the Docker socket dangerous?

It may give the container effective control over the Docker host.

---

# 62. Day 9 completion challenge

Complete this independently:

1. Create a host directory named `challenge-site`.
    
2. Add an HTML page.
    
3. Run Nginx with the directory bind-mounted.
    
4. Publish host port 9080 to container port 80.
    
5. Change the host file and verify the change immediately.
    
6. Change the file from inside the container.
    
7. Confirm the host file changed.
    
8. Remove the container.
    
9. Confirm the host data remains.
    
10. Recreate the container with the same mount.
    
11. Make the mount read-only.
    
12. Confirm container writes fail.
    
13. Mount one custom Nginx configuration file.
    
14. Add and test a `/health` endpoint.
    
15. Inspect the applied mounts.
    
16. Demonstrate that a mount hides image content.
    
17. Create a writable host directory.
    
18. Write a file as container root.
    
19. Inspect its host UID and GID.
    
20. Write another file using your host UID and GID.
    
21. Compare ownership.
    
22. Create a simulated SQLite directory.
    
23. Run a container as UID 10001.
    
24. Configure permissions so it can create database and journal files.
    
25. Explain why the directory, not only the database file, must be writable.
    
26. Create a Mosquitto configuration and data directory.
    
27. Mount configuration read-only.
    
28. Mount broker data writable.
    
29. Explain which broker files must survive container replacement.
    
30. Explain why mounting `/` or the Docker socket is dangerous.
    

The central Day 9 model is:

```text
Host file or directory
          ↓ bind mount
Container path
          ↓
Application reads or writes host data
```

The key lifecycle rule is:

```text
Container removed:
mount configuration disappears

Host source data:
remains
```

The most important operational lesson is:

> Bind mounts provide direct, transparent access to host files. They are excellent for development and host-managed configuration, but they couple containers to host paths and require careful permission and security design.