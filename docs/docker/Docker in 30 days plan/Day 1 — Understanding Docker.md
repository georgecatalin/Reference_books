#### Understanding Docker and Running Your First Containers

Today’s goal is to build the correct mental model before learning many commands.

By the end of Day 1, you should understand:

- What a container is
    
- What an image is
    
- How containers differ from virtual machines
    
- What Docker Engine, Docker daemon, and Docker CLI do
    
- How to run, inspect, stop, and remove basic containers
    
- Why data created inside a container can disappear
    

Spend approximately 60–90 minutes on this lesson.

---

# 1. The problem Docker solves

Suppose you develop a PHP application on your laptop.

Your application requires:

- PHP 8.4
    
- Apache
    
- SQLite
    
- Specific PHP extensions
    
- A particular configuration file
    
- Certain filesystem permissions
    

When you move the application to another computer, you may encounter:

- A different PHP version
    
- Missing extensions
    
- Different Linux packages
    
- Incorrect permissions
    
- Different configuration paths
    
- Conflicts with existing software
    

This creates the classic problem:

> “It works on my machine.”

Docker helps package the application together with most of its runtime environment so it behaves consistently across different systems.

Instead of manually installing PHP, Apache, and dependencies on every machine, you can run a prepared container image.

---

# 2. What a container is

A container is an isolated process running on the host operating system.

It usually has its own:

- Filesystem view
    
- Process list
    
- Network interfaces
    
- Hostname
    
- Environment variables
    
- User and group configuration
    
- Resource limits
    

However, a container is **not a complete virtual machine**.

It shares the host’s Linux kernel.

A useful mental model is:

> A container is an ordinary Linux process placed inside several isolation boundaries.

These isolation mechanisms are primarily built with Linux features such as:

- Namespaces
    
- Control groups
    
- Capabilities
    
- Layered filesystems
    

You do not need to master those kernel mechanisms today, but you should know Docker is using operating-system isolation rather than emulating an entire physical computer.

---

# 3. Container versus virtual machine

You already work with VirtualBox virtual machines, so this distinction is important.

## Virtual machine

A virtual machine contains:

```text
Application
Libraries
Guest operating system
Guest kernel
Virtual hardware
Hypervisor
Host operating system
Physical hardware
```

For example, an Ubuntu VirtualBox machine has its own Linux kernel.

## Container

A container contains approximately:

```text
Application
Libraries
Application filesystem
Container configuration
```

It uses the host’s kernel:

```text
Container
Docker Engine
Host Linux kernel
Physical hardware
```

## Practical comparison

|Characteristic|Container|Virtual machine|
|---|---|---|
|Kernel|Shares host kernel|Has its own kernel|
|Startup|Usually seconds or less|Usually tens of seconds or minutes|
|Disk size|Often MB or hundreds of MB|Often several GB|
|Isolation|Process-level|Full machine-level|
|Operating system|Must be compatible with host kernel|Can run a separate guest OS|
|Common use|Applications and services|Full operating systems and strong environment separation|

A container is not always a replacement for a virtual machine.

A common production architecture is:

```text
Physical server
└── Linux virtual machine
    └── Docker Engine
        ├── Web container
        ├── Database container
        └── MQTT container
```

Docker and virtual machines are often used together.

---

# 4. Important Docker terminology

## 4.1 Image

An image is a packaged, read-only template used to create containers.

An image may contain:

- A minimal Linux filesystem
    
- Application binaries
    
- Libraries
    
- Configuration defaults
    
- Startup instructions
    
- Metadata
    

Examples:

```text
nginx
ubuntu
alpine
postgres
php
eclipse-mosquitto
```

Think of an image as similar to a class in programming.

It describes what can be created.

---

## 4.2 Container

A container is an instance created from an image.

Think of it as similar to an object created from a class.

From one image, you can create multiple containers:

```text
nginx image
├── nginx-container-1
├── nginx-container-2
└── nginx-container-3
```

Each container has its own:

- Name
    
- ID
    
- Writable filesystem layer
    
- Process state
    
- Network identity
    
- Configuration
    

---

## 4.3 Registry

A registry stores and distributes images.

The most familiar public registry is Docker Hub.

When you run:

```bash
docker pull nginx
```

Docker normally downloads the `nginx` image from Docker Hub.

Companies may also use private registries such as:

- GitLab Container Registry
    
- GitHub Container Registry
    
- AWS Elastic Container Registry
    
- Azure Container Registry
    
- Google Artifact Registry
    
- Self-hosted Harbor
    

---

## 4.4 Repository

A repository is a collection of related image versions.

For example:

```text
nginx:latest
nginx:alpine
nginx:1.28
```

These are different tags from the `nginx` repository.

---

## 4.5 Tag

A tag identifies a particular image version or variant.

Examples:

```text
ubuntu:24.04
postgres:17
nginx:alpine
php:8.4-apache
```

The syntax is:

```text
repository:tag
```

When no tag is specified, Docker normally assumes:

```text
latest
```

Therefore:

```bash
docker pull nginx
```

usually means:

```bash
docker pull nginx:latest
```

Despite its name, `latest` does not guarantee that it is the newest or safest version. It is simply a tag.

---

# 5. Docker architecture

Docker uses a client-server architecture.

The main components are:

```text
Docker CLI
    |
    v
Docker daemon
    |
    ├── Images
    ├── Containers
    ├── Networks
    └── Volumes
```

## Docker CLI

The Docker CLI is the `docker` command you type:

```bash
docker ps
docker run nginx
docker images
```

The CLI sends requests to the Docker daemon.

## Docker daemon

The daemon is a background service, commonly called:

```text
dockerd
```

It performs the actual work:

- Downloads images
    
- Creates containers
    
- Starts processes
    
- Creates networks
    
- Manages volumes
    
- Deletes resources
    

On a Linux system using systemd, you can inspect it with:

```bash
sudo systemctl status docker
```

## Container runtime

Under Docker, lower-level components such as `containerd` and `runc` are involved in actually creating and managing containers.

For now, remember this simplified chain:

```text
docker command
    ↓
Docker daemon
    ↓
containerd
    ↓
container process
```

You do not normally interact with `runc` directly during everyday Docker use.

---

# 6. Verify your Docker installation

Run:

```bash
docker version
```

This should display information about:

- Client
    
- Server
    
- API version
    
- Docker Engine version
    
- Operating system
    
- Architecture
    

A healthy output contains both a `Client` section and a `Server` section.

If you see only the client section followed by a connection error, the Docker daemon may not be running.

Check:

```bash
sudo systemctl status docker
```

Start it if required:

```bash
sudo systemctl start docker
```

Enable it at boot:

```bash
sudo systemctl enable docker
```

You can combine both:

```bash
sudo systemctl enable --now docker
```

---

# 7. Docker permissions on Linux

You may initially need:

```bash
sudo docker ps
```

Docker commonly creates a Unix socket:

```text
/var/run/docker.sock
```

Users in the `docker` group can access that socket.

Check your groups:

```bash
groups
```

Add your current user to the Docker group:

```bash
sudo usermod -aG docker "$USER"
```

This command means:

```text
sudo
```

Run with administrative privileges.

```text
usermod
```

Modify a user account.

```text
-a
```

Append rather than replace existing supplementary groups.

```text
-G docker
```

Add the user to the supplementary group named `docker`.

```text
$USER
```

Use the current username.

After running it, your current terminal session may still have the old group membership.

You can:

- Log out and log back in
    
- Reboot
    
- Or run:
    

```bash
newgrp docker
```

Then test:

```bash
docker ps
```

## Security warning

Membership in the `docker` group is effectively equivalent to having powerful administrative access to the machine.

A user who controls Docker can often:

- Mount host directories
    
- Read host files
    
- Start privileged containers
    
- Access sensitive host resources
    

Do not treat the Docker group as an ordinary harmless group.

---

# 8. Inspect the Docker environment

Run:

```bash
docker info
```

This displays detailed information about the Docker system, including:

- Number of containers
    
- Number of images
    
- Storage driver
    
- Logging driver
    
- Available runtimes
    
- Docker root directory
    
- Kernel version
    
- CPU count
    
- Total memory
    
- Security settings
    

The output is large. Today, look for these fields:

```text
Containers
 Images
 Server Version
 Storage Driver
 Logging Driver
 Cgroup Driver
 Docker Root Dir
 Operating System
 Architecture
```

You do not need to understand every field yet.

---

# 9. Run your first container

Run:

```bash
docker run hello-world
```

This command performs several steps.

## Step 1: Docker checks for the image locally

Docker looks for:

```text
hello-world:latest
```

in the local image store.

## Step 2: Docker downloads it if missing

If the image does not exist locally, Docker pulls it from a registry.

You may see:

```text
Unable to find image 'hello-world:latest' locally
```

This is not an error.

It means Docker will download the image.

## Step 3: Docker creates a container

Docker creates a new container based on the image.

## Step 4: Docker starts the container process

The container runs a small executable that prints a message.

## Step 5: The process exits

Once the message is printed, the process finishes.

The container then enters the stopped state.

---

# 10. Understand `docker run`

The general structure is:

```bash
docker run [OPTIONS] IMAGE [COMMAND] [ARGUMENTS]
```

For example:

```bash
docker run alpine echo "Hello from Alpine"
```

Breakdown:

```text
docker run
```

Create and start a new container.

```text
alpine
```

Use the Alpine Linux image.

```text
echo "Hello from Alpine"
```

Override the image’s default command and run this command instead.

Run it:

```bash
docker run alpine echo "Hello from Alpine"
```

The output should be:

```text
Hello from Alpine
```

The container exits immediately because `echo` finishes immediately.

---

# 11. A container exists only while its main process runs

This is one of the most important Docker principles:

> A container normally runs for as long as its main process is running.

For:

```bash
docker run alpine echo hello
```

the main process is:

```text
echo hello
```

It exits almost immediately, so the container stops.

For:

```bash
docker run nginx
```

the main process is the Nginx server.

Nginx keeps running, so the container remains running.

A container is not a miniature machine that must always remain powered on. It is fundamentally a managed process environment.

---

# 12. List running containers

Run:

```bash
docker ps
```

This displays only running containers.

You will probably not see the `hello-world` container because it already exited.

List all containers, including stopped ones:

```bash
docker ps -a
```

You may see something similar to:

```text
CONTAINER ID   IMAGE         COMMAND    STATUS                     NAMES
ab12cd34ef56   hello-world   "/hello"   Exited (0) 2 minutes ago   eager_morse
```

Important columns:

|Column|Meaning|
|---|---|
|`CONTAINER ID`|Short unique identifier|
|`IMAGE`|Image used to create the container|
|`COMMAND`|Main process started|
|`CREATED`|When the container was created|
|`STATUS`|Running, exited, paused, or restarting|
|`PORTS`|Published network ports|
|`NAMES`|Human-readable container name|

---

# 13. Container IDs and names

Every container receives:

- A long unique ID
    
- A short displayed ID
    
- A name
    

If you do not specify a name, Docker generates one, such as:

```text
eager_morse
peaceful_turing
happy_babbage
```

You can assign a meaningful name:

```bash
docker run --name first-container alpine echo "Hello"
```

Now list it:

```bash
docker ps -a
```

You can refer to the container using either:

```bash
docker inspect first-container
```

or its ID:

```bash
docker inspect ab12cd34ef56
```

Meaningful names are usually easier to manage.

---

# 14. Run an interactive container

Start Alpine Linux with an interactive shell:

```bash
docker run --rm -it alpine sh
```

The options mean:

```text
-i
```

Keep standard input open.

```text
-t
```

Allocate a terminal.

Together:

```text
-it
```

Give you an interactive terminal session.

```text
--rm
```

Automatically remove the container after it exits.

```text
alpine
```

Use the Alpine Linux image.

```text
sh
```

Run the shell.

You should receive a prompt similar to:

```text
/ #
```

You are now inside the container.

---

# 15. Explore the container

Inside the Alpine container, run:

```sh
cat /etc/os-release
```

This shows the operating-system filesystem contained in the image.

Run:

```sh
hostname
```

You will normally see a container-specific hostname, often based on the container ID.

Run:

```sh
pwd
```

Then:

```sh
ls /
```

You may see directories such as:

```text
bin
dev
etc
home
lib
media
mnt
opt
proc
root
run
sbin
srv
sys
tmp
usr
var
```

Run:

```sh
ps
```

You may see only a small number of processes.

This is because the container has an isolated process namespace.

Run:

```sh
id
```

You will probably see:

```text
uid=0(root) gid=0(root)
```

You are root inside the container.

However, root inside a container is not conceptually identical to unrestricted host root, although poorly configured containers may still create serious host security risks.

Exit:

```sh
exit
```

Because the container was started with `--rm`, Docker removes it automatically.

Confirm:

```bash
docker ps -a
```

It should not appear.

---

# 16. Run Ubuntu interactively

Run:

```bash
docker run --rm -it ubuntu:24.04 bash
```

Inside:

```bash
cat /etc/os-release
```

Then:

```bash
apt update
```

Install `curl`:

```bash
apt install -y curl
```

Confirm:

```bash
curl --version
```

Create a file:

```bash
echo "Created inside the container" > /temporary-file.txt
```

Verify:

```bash
cat /temporary-file.txt
```

Exit:

```bash
exit
```

Because you used `--rm`, that container is deleted.

Run a new Ubuntu container:

```bash
docker run --rm -it ubuntu:24.04 bash
```

Check for the file:

```bash
cat /temporary-file.txt
```

You should receive:

```text
No such file or directory
```

Check for `curl`:

```bash
curl --version
```

It will probably not be installed.

---

# 17. Why the changes disappeared

You did not modify the original Ubuntu image.

When Docker created the first container, it effectively used:

```text
Ubuntu image: read-only layers
+
Container-specific writable layer
```

Your changes were written to the container’s writable layer:

```text
Installed curl
Created /temporary-file.txt
```

When the container was removed, its writable layer was removed too.

The original image remained unchanged.

Creating a new container from that image gives you a fresh environment.

This is intentional.

Containers should generally be reproducible and replaceable.

---

# 18. Image immutability

Images are treated as immutable templates.

You do not normally:

1. Start a container
    
2. Enter it manually
    
3. Install packages
    
4. Configure files
    
5. Keep that container permanently
    

That approach produces an environment that is difficult to reproduce.

Instead, you describe the required changes in a Dockerfile:

```dockerfile
FROM ubuntu:24.04

RUN apt-get update \
    && apt-get install -y curl
```

Then build an image.

You will learn Dockerfiles later in the course.

The desired process is:

```text
Dockerfile
    ↓
docker build
    ↓
Custom image
    ↓
docker run
    ↓
Reproducible container
```

---

# 19. Run a long-running container

Run an Nginx web server:

```bash
docker run --name day1-nginx nginx
```

Unlike `hello-world`, Nginx remains running.

Because you did not use detached mode, your terminal is attached to the Nginx process.

You may see log output.

Open another terminal and run:

```bash
docker ps
```

You should see the Nginx container running.

Return to the first terminal and press:

```text
Ctrl+C
```

This sends an interrupt signal to the foreground process.

The container should stop.

Check:

```bash
docker ps
```

It is no longer running.

Check all containers:

```bash
docker ps -a
```

It should appear as exited.

---

# 20. Foreground versus detached mode

## Foreground mode

```bash
docker run nginx
```

The terminal attaches to the container’s output.

This is useful when:

- Learning
    
- Watching immediate output
    
- Debugging startup problems
    

## Detached mode

```bash
docker run -d --name day1-nginx nginx
```

The `-d` option means detached mode.

Docker starts the container in the background and prints its ID.

Check:

```bash
docker ps
```

View logs:

```bash
docker logs day1-nginx
```

Follow logs continuously:

```bash
docker logs -f day1-nginx
```

Stop following logs with:

```text
Ctrl+C
```

This stops the log-following command, not the container.

Confirm:

```bash
docker ps
```

The container should still be running.

---

# 21. Stop and start a container

Stop:

```bash
docker stop day1-nginx
```

Docker sends a graceful termination signal and waits for the process to stop.

Check:

```bash
docker ps -a
```

Start the same container:

```bash
docker start day1-nginx
```

Check:

```bash
docker ps
```

The important distinction is:

```text
docker run
```

creates a new container.

```text
docker start
```

starts an existing stopped container.

Running this repeatedly:

```bash
docker run nginx
```

creates multiple containers.

It does not restart the previous one.

---

# 22. Remove a container

First stop it:

```bash
docker stop day1-nginx
```

Then remove it:

```bash
docker rm day1-nginx
```

You can force removal of a running container:

```bash
docker rm -f day1-nginx
```

Use forced removal carefully.

It terminates the container and deletes it.

---

# 23. List local images

Run:

```bash
docker images
```

or:

```bash
docker image ls
```

You may see:

```text
REPOSITORY    TAG       IMAGE ID       CREATED        SIZE
nginx         latest    ...
ubuntu        24.04     ...
alpine        latest    ...
hello-world   latest    ...
```

Important fields:

|Field|Meaning|
|---|---|
|`REPOSITORY`|Image repository name|
|`TAG`|Image version or variant|
|`IMAGE ID`|Local image identifier|
|`CREATED`|Image build date|
|`SIZE`|Approximate local size|

---

# 24. Pull an image explicitly

You do not need to wait for `docker run` to download an image.

Run:

```bash
docker pull alpine:latest
```

Or:

```bash
docker pull ubuntu:24.04
```

Pulling does not start a container.

It only downloads the image into the local image store.

This distinction matters:

```text
docker pull
```

Downloads an image.

```text
docker run
```

Creates and starts a container from an image.

---

# 25. Inspect a container

Create a stopped container:

```bash
docker run --name inspection-demo alpine echo "Inspect me"
```

Inspect it:

```bash
docker inspect inspection-demo
```

The output is JSON and contains substantial detail:

- Container ID
    
- Image ID
    
- Created timestamp
    
- Command
    
- State
    
- Exit code
    
- Environment variables
    
- Network configuration
    
- Mount configuration
    
- Host configuration
    

To extract the status:

```bash
docker inspect inspection-demo \
  --format '{{.State.Status}}'
```

To extract the exit code:

```bash
docker inspect inspection-demo \
  --format '{{.State.ExitCode}}'
```

The exit code should usually be:

```text
0
```

An exit code of zero generally means the process completed successfully.

A nonzero exit code normally indicates an error or abnormal termination.

---

# 26. Inspect an image

Run:

```bash
docker image inspect alpine
```

Look for:

- Architecture
    
- Operating system
    
- Creation date
    
- Environment variables
    
- Default command
    
- Entrypoint
    
- Layers
    

You do not need to understand the complete output today.

---

# 27. Execute a command in a running container

Start a container that remains active:

```bash
docker run -d \
  --name shell-demo \
  alpine \
  sleep 3600
```

Here, the main process is:

```text
sleep 3600
```

It keeps the container alive for one hour.

Enter the running container:

```bash
docker exec -it shell-demo sh
```

Inside:

```sh
ps
hostname
cat /etc/os-release
```

Exit:

```sh
exit
```

The container is still running because exiting the additional shell does not stop its main `sleep` process.

Check:

```bash
docker ps
```

Now stop and remove it:

```bash
docker rm -f shell-demo
```

## `run` versus `exec`

```text
docker run
```

Creates a new container.

```text
docker exec
```

Runs an additional command inside an already-running container.

---

# 28. Common beginner misunderstandings

## Misunderstanding 1: An image is a running container

Incorrect.

An image is a template. A container is an instance created from it.

---

## Misunderstanding 2: `docker run` restarts an old container

Incorrect.

`docker run` normally creates a new container.

Use:

```bash
docker start container-name
```

to restart an existing container.

---

## Misunderstanding 3: A stopped container no longer exists

Incorrect.

A stopped container still exists until it is removed.

Use:

```bash
docker ps -a
```

to see stopped containers.

---

## Misunderstanding 4: Installing software in a container modifies the image

Incorrect.

The installation changes only that container’s writable layer.

---

## Misunderstanding 5: Containers are miniature virtual machines

This is a misleading mental model.

Containers are isolated processes that share the host kernel.

---

## Misunderstanding 6: A container should continue running even after its application exits

Usually incorrect.

The container normally stops when its main process exits.

---

## Misunderstanding 7: `Ctrl+C` always deletes a container

Incorrect.

It may stop the foreground process, but it does not normally remove the container unless the container was run with:

```bash
--rm
```

---

# 29. Day 1 practical laboratory

Complete the following without skipping steps.

## Exercise 1 — Verify Docker

```bash
docker version
docker info
docker ps
```

Verify that the client can communicate with the Docker daemon.

---

## Exercise 2 — Run a short-lived container

```bash
docker run --name hello-day1 hello-world
```

Then:

```bash
docker ps
docker ps -a
```

Explain why it appears only in the second command.

Expected answer:

> Its main process printed a message and exited, so the container is stopped.

---

## Exercise 3 — Run a command in Alpine

```bash
docker run --name alpine-message \
  alpine \
  echo "Docker Day 1"
```

Inspect its exit code:

```bash
docker inspect alpine-message \
  --format '{{.State.ExitCode}}'
```

Expected result:

```text
0
```

---

## Exercise 4 — Explore a container

```bash
docker run --rm -it alpine sh
```

Inside:

```sh
cat /etc/os-release
hostname
pwd
ls /
ps
id
exit
```

Explain why you see fewer processes than on the host.

Expected answer:

> The container has its own isolated process namespace and sees mainly its own processes.

---

## Exercise 5 — Demonstrate container-local changes

Start Ubuntu without automatic removal:

```bash
docker run -it \
  --name ubuntu-learning \
  ubuntu:24.04 \
  bash
```

Inside:

```bash
echo "Persistent while container exists" > /lesson.txt
exit
```

Start the same container again:

```bash
docker start -ai ubuntu-learning
```

Inside:

```bash
cat /lesson.txt
exit
```

The file remains because this is the same container.

Now remove it:

```bash
docker rm ubuntu-learning
```

Create a new container:

```bash
docker run --rm -it ubuntu:24.04 bash
```

Inside:

```bash
cat /lesson.txt
```

The file does not exist because this is a different container.

This experiment teaches an important distinction:

```text
Stop and start same container
→ writable changes remain

Remove and recreate container
→ writable changes disappear
```

---

## Exercise 6 — Run a background service

```bash
docker run -d \
  --name background-demo \
  nginx
```

Check it:

```bash
docker ps
```

Inspect logs:

```bash
docker logs background-demo
```

Enter it:

```bash
docker exec -it background-demo sh
```

Inside:

```sh
ps
cat /etc/os-release
exit
```

Stop it:

```bash
docker stop background-demo
```

Restart it:

```bash
docker start background-demo
```

Remove it:

```bash
docker rm -f background-demo
```

---

## Exercise 7 — Clean up

List all containers:

```bash
docker ps -a
```

Remove the exercise containers:

```bash
docker rm hello-day1 alpine-message
```

List images:

```bash
docker image ls
```

Do not remove the images yet. You will reuse them.

---

# 30. Day 1 command reference

```bash
# Display client and server versions
docker version

# Display Docker system information
docker info

# Run a container
docker run IMAGE

# Run interactively with a terminal
docker run -it IMAGE COMMAND

# Automatically remove after exit
docker run --rm IMAGE

# Run in the background
docker run -d IMAGE

# Assign a name
docker run --name NAME IMAGE

# List running containers
docker ps

# List all containers
docker ps -a

# View container logs
docker logs CONTAINER

# Follow container logs
docker logs -f CONTAINER

# Run a command in a running container
docker exec -it CONTAINER COMMAND

# Stop a container
docker stop CONTAINER

# Start an existing container
docker start CONTAINER

# Start and attach to an existing container
docker start -ai CONTAINER

# Remove a stopped container
docker rm CONTAINER

# Force-remove a running or stopped container
docker rm -f CONTAINER

# List images
docker image ls

# Download an image
docker pull IMAGE

# Inspect a container or image
docker inspect OBJECT
```

---

# 31. Knowledge check

Answer these without looking back.

### Question 1

What is the difference between an image and a container?

**Answer:**

An image is a read-only template. A container is a running or stopped instance created from that image.

### Question 2

Why does this container stop immediately?

```bash
docker run alpine echo hello
```

**Answer:**

Its main process is `echo`. Once `echo` prints the message and exits, the container stops.

### Question 3

What is the difference between these commands?

```bash
docker ps
docker ps -a
```

**Answer:**

The first shows running containers. The second shows running and stopped containers.

### Question 4

What does `--rm` do?

**Answer:**

It tells Docker to remove the container automatically after its main process exits.

### Question 5

What does `-it` do?

**Answer:**

`-i` keeps standard input open, and `-t` allocates a terminal. Together, they provide an interactive terminal session.

### Question 6

Does installing `curl` inside a container modify the original image?

**Answer:**

No. It changes only that container’s writable layer.

### Question 7

What is the difference between `docker run` and `docker start`?

**Answer:**

`docker run` creates a new container and starts it. `docker start` starts an existing stopped container.

### Question 8

What is the difference between `docker run` and `docker exec`?

**Answer:**

`docker run` creates a new container. `docker exec` starts another command inside an already-running container.

### Question 9

Does stopping a container delete it?

**Answer:**

No. It remains available until removed with `docker rm`.

### Question 10

Why are containers generally smaller than virtual machines?

**Answer:**

Containers share the host kernel and do not include a complete guest operating system and virtual hardware.

---

# 32. Day 1 completion challenge

Without looking at the previous examples, perform the following:

1. Run an Alpine container interactively.
    
2. Display its operating-system version.
    
3. Display its hostname.
    
4. Exit and remove it automatically.
    
5. Start an Nginx container in detached mode.
    
6. Give it the name `day1-web`.
    
7. Confirm it is running.
    
8. View its logs.
    
9. Open a shell inside it.
    
10. Exit the shell without stopping Nginx.
    
11. Stop the container.
    
12. Confirm it appears under `docker ps -a`.
    
13. Start it again.
    
14. Inspect its current state.
    
15. Force-remove it.
    

The principal Day 1 mental model is:

```text
Image
  ↓ docker run
Container
  ↓ starts
Main process
  ↓ exits
Stopped container
  ↓ docker rm
Removed container
```

An image remains reusable after the container is removed.

[[30 days Docker]]