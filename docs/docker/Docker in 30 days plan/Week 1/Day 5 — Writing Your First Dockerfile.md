[[30 days Docker]]

Until now, you have used images created by other people:

```bash
docker run nginx
docker run alpine
docker run ubuntu:24.04
```

Today you will create your own image.

The central workflow is:

```text
Application files + Dockerfile
              ↓
         docker build
              ↓
          Docker image
              ↓
          docker run
              ↓
           Container
```

By the end of Day 5, you should understand:

- What a Dockerfile is
    
- What the build context is
    
- How `FROM`, `COPY`, `RUN`, `WORKDIR`, `EXPOSE`, and `CMD` work
    
- The difference between image-build time and container runtime
    
- How to build, tag, run, test, and rebuild an image
    
- How Docker build caching works at a basic level
    
- Why a Dockerfile is better than manually modifying containers
    
- How to create a simple custom Nginx image
    

---

# 1. The problem with manually configured containers

Suppose you start Ubuntu:

```bash
docker run -it ubuntu:24.04 bash
```

Then manually install software:

```bash
apt update
apt install -y nginx
```

Then edit files and configure the application.

This may work, but it creates several problems:

- Nobody knows exactly what commands you ran.
    
- Another developer cannot reproduce the environment reliably.
    
- You may forget a configuration step.
    
- Rebuilding the server becomes difficult.
    
- Version control cannot track your manual changes.
    
- Removing the container destroys the work.
    
- Testing and production may differ.
    

A Dockerfile solves this by recording the image-construction steps as text.

Instead of explaining:

> “Start Ubuntu, install Nginx, copy this file, edit that configuration, and run this command.”

you write those operations in a Dockerfile.

---

# 2. What a Dockerfile is

A Dockerfile is a text file containing instructions Docker uses to build an image.

A simple example:

```dockerfile
FROM nginx:alpine

COPY index.html /usr/share/nginx/html/index.html
```

This means:

1. Begin with the existing `nginx:alpine` image.
    
2. Copy your `index.html` into the image.
    
3. Produce a new image containing Nginx and your webpage.
    

The filename is normally exactly:

```text
Dockerfile
```

It has:

- No extension
    
- A capital `D`
    
- A lowercase remainder
    

Correct:

```text
Dockerfile
```

Not normally:

```text
Dockerfile.txt
dockerfile
DockerFile
```

Linux filenames are case-sensitive.

---

# 3. Your first custom image

Create a project directory:

```bash
mkdir -p ~/docker-course/day5/static-site
cd ~/docker-course/day5/static-site
```

Confirm your location:

```bash
pwd
```

You should be somewhere similar to:

```text
/home/georgeca/docker-course/day5/static-site
```

List the directory:

```bash
ls -la
```

It should initially be empty.

---

# 4. Create the webpage

Create `index.html`:

```bash
nano index.html
```

Add:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Docker Day 5</title>
</head>
<body>
    <h1>My first custom Docker image</h1>
    <p>This page is served by Nginx inside a container.</p>
</body>
</html>
```

Save and exit.

Check the file:

```bash
cat index.html
```

Your directory should now contain:

```text
static-site/
└── index.html
```

---

# 5. Create the Dockerfile

Create:

```bash
nano Dockerfile
```

Add:

```dockerfile
FROM nginx:alpine

COPY index.html /usr/share/nginx/html/index.html
```

Save it.

Your project now looks like:

```text
static-site/
├── Dockerfile
└── index.html
```

Check:

```bash
ls -la
```

---

# 6. Understanding `FROM`

The first instruction is:

```dockerfile
FROM nginx:alpine
```

`FROM` defines the base image.

Your custom image does not need to contain an entire operating system assembled from nothing. It starts with an existing image that already provides:

- Nginx
    
- Required libraries
    
- Default Nginx configuration
    
- Startup scripts
    
- A minimal Alpine Linux filesystem
    
- A default command that starts Nginx
    

Conceptually:

```text
nginx:alpine base image
          +
your files and configuration
          =
your custom image
```

Most Dockerfiles begin with `FROM`.

Examples:

```dockerfile
FROM ubuntu:24.04
```

```dockerfile
FROM python:3.13-slim
```

```dockerfile
FROM php:8.4-apache
```

```dockerfile
FROM debian:13-slim
```

Use a specific, appropriate base-image tag rather than relying blindly on `latest`.

---

# 7. Understanding `COPY`

The second instruction is:

```dockerfile
COPY index.html /usr/share/nginx/html/index.html
```

The syntax is:

```dockerfile
COPY SOURCE DESTINATION
```

Here:

```text
SOURCE:
index.html
```

This is a file in the Docker build context.

```text
DESTINATION:
/usr/share/nginx/html/index.html
```

This is the path inside the image.

The default Nginx image serves files from:

```text
/usr/share/nginx/html
```

Therefore, replacing its default `index.html` changes the page Nginx serves.

`COPY` happens while building the image, not every time the container starts.

---

# 8. Build the image

Run this command from the project directory:

```bash
docker build -t day5-static-site:1.0 .
```

Break it down.

## `docker build`

```text
Build a Docker image.
```

## `-t day5-static-site:1.0`

```text
Assign the image repository name day5-static-site
and the tag 1.0.
```

## Final `.`

```text
Use the current directory as the build context.
```

The final dot is essential.

The complete command means:

> Build an image using the current directory and tag the result as `day5-static-site:1.0`.

---

# 9. What happens during the build

You may see output similar to:

```text
[1/2] FROM docker.io/library/nginx:alpine
[2/2] COPY index.html /usr/share/nginx/html/index.html
exporting to image
naming to docker.io/library/day5-static-site:1.0
```

Docker performs roughly these operations:

1. Reads the Dockerfile.
    
2. Processes the build context.
    
3. Resolves `nginx:alpine`.
    
4. Downloads the base image if necessary.
    
5. Creates a new layer containing your HTML file.
    
6. Creates the final image configuration.
    
7. Assigns the tag `day5-static-site:1.0`.
    

Confirm the image exists:

```bash
docker image ls day5-static-site
```

You should see:

```text
REPOSITORY          TAG    IMAGE ID       SIZE
day5-static-site    1.0    ...
```

---

# 10. Understanding the build context

When you run:

```bash
docker build -t day5-static-site:1.0 .
```

the final dot identifies the build context.

The build context is the collection of files Docker can use during the build.

In this case:

```text
.
```

means the current directory.

Docker can access:

```text
Dockerfile
index.html
```

and any other files beneath this directory.

Docker cannot normally copy a file located outside the build context.

For example, this will not work reliably:

```dockerfile
COPY ../secret.txt /app/secret.txt
```

because `secret.txt` is outside the selected context.

The build context creates an important boundary:

```text
Project directory
├── Dockerfile
├── index.html
└── assets/
```

Only files within that context can be copied by normal `COPY` instructions.

---

# 11. Run your custom image

Run:

```bash
docker run -d \
  --name day5-website \
  -p 8080:80 \
  day5-static-site:1.0
```

Check:

```bash
docker ps
```

Test:

```bash
curl http://localhost:8080
```

You should receive your custom HTML.

Open in a browser:

```text
http://localhost:8080
```

The path is:

```text
Browser
   ↓
Docker host port 8080
   ↓
Container port 80
   ↓
Nginx
   ↓
/usr/share/nginx/html/index.html
```

---

# 12. Verify the file inside the container

Enter the container:

```bash
docker exec -it day5-website sh
```

Inside:

```sh
cat /usr/share/nginx/html/index.html
```

You should see your webpage.

Check the operating system:

```sh
cat /etc/os-release
```

It should show Alpine Linux because your image was based on:

```dockerfile
FROM nginx:alpine
```

Exit:

```sh
exit
```

---

# 13. Your image inherited behavior from the base image

Your Dockerfile did not contain:

```dockerfile
CMD ["nginx", "-g", "daemon off;"]
```

Yet Nginx started automatically.

Why?

Because your image inherited configuration from `nginx:alpine`, including:

- Entrypoint
    
- Default command
    
- Environment variables
    
- Exposed-port metadata
    
- Filesystem
    
- Startup scripts
    

Your Dockerfile changed only what you explicitly added or replaced.

Conceptually:

```text
Base image configuration
          +
Dockerfile modifications
          =
Final image configuration
```

Inspect your image:

```bash
docker image inspect day5-static-site:1.0 \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'
```

Compare it with:

```bash
docker image inspect nginx:alpine \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'
```

They should be similar because your image inherited the Nginx startup behavior.

---

# 14. Change the webpage

Edit `index.html`:

```bash
nano index.html
```

Change the body to:

```html
<body>
    <h1>Docker image version 1.1</h1>
    <p>I rebuilt this page using a new image version.</p>
</body>
```

Save it.

Now refresh your browser.

You will probably still see the old page.

Why?

Because changing a host file does not modify an already-built image or an already-created container.

The current relationship is:

```text
Host index.html: new content

Image day5-static-site:1.0:
old content copied during its build

Container day5-website:
created from image 1.0 with old content
```

You must build a new image.

---

# 15. Build version 1.1

Run:

```bash
docker build -t day5-static-site:1.1 .
```

List images:

```bash
docker image ls day5-static-site
```

You should now have:

```text
day5-static-site   1.1
day5-static-site   1.0
```

These are two image versions.

The original container still uses version 1.0.

Check:

```bash
docker inspect day5-website \
  --format '{{.Config.Image}}'
```

Expected:

```text
day5-static-site:1.0
```

Building a new image does not automatically replace running containers.

---

# 16. Run both versions simultaneously

Leave version 1.0 on host port 8080.

Run version 1.1 on port 8081:

```bash
docker run -d \
  --name day5-website-v11 \
  -p 8081:80 \
  day5-static-site:1.1
```

Test both:

```bash
curl http://localhost:8080
```

```bash
curl http://localhost:8081
```

Architecture:

```text
Host port 8080 → container using image 1.0 → old webpage
Host port 8081 → container using image 1.1 → new webpage
```

This demonstrates an important deployment principle:

> Images are versioned artifacts. Existing containers continue using the image from which they were created.

---

# 17. Replacing a container with a new image

Suppose port 8080 should now serve version 1.1.

Stop and remove the old container:

```bash
docker rm -f day5-website
```

Create a replacement:

```bash
docker run -d \
  --name day5-website \
  -p 8080:80 \
  day5-static-site:1.1
```

Test:

```bash
curl http://localhost:8080
```

You should see version 1.1.

The standard update pattern is:

```text
Build new image
      ↓
Test new image
      ↓
Stop/remove old container
      ↓
Create new container from new image
```

You do not normally update a container’s underlying image in place.

---

# 18. Build time versus runtime

This is one of the most important Docker distinctions.

## Build time

Build time occurs when you run:

```bash
docker build ...
```

Dockerfile instructions such as these generally execute at build time:

```dockerfile
FROM
COPY
RUN
WORKDIR
```

The result is an image.

## Runtime

Runtime begins when you run:

```bash
docker run ...
```

Docker creates a container and starts its main process.

Instructions such as `CMD` and `ENTRYPOINT` define runtime behavior.

A useful model:

```text
Dockerfile
   ↓ docker build
Image
   ↓ docker run
Container process
```

---

# 19. `RUN` does not mean “run the container”

Consider:

```dockerfile
RUN apk add --no-cache curl
```

This executes while the image is being built.

It creates a new image layer containing `curl`.

It does not define the main container process.

Compare:

```dockerfile
RUN echo "Built at image-build time"
```

with:

```dockerfile
CMD ["echo", "Executed at container runtime"]
```

`RUN`:

```text
docker build
→ executes once
→ stores the resulting filesystem changes
```

`CMD`:

```text
docker run
→ executes whenever a container starts
```

---

# 20. Create an image using `RUN`

Create a new directory:

```bash
mkdir -p ~/docker-course/day5/run-example
cd ~/docker-course/day5/run-example
```

Create a Dockerfile:

```dockerfile
FROM alpine:latest

RUN echo "This file was created while building the image" \
    > /build-message.txt

CMD ["cat", "/build-message.txt"]
```

Build:

```bash
docker build -t day5-run-example:1.0 .
```

Run:

```bash
docker run --rm day5-run-example:1.0
```

Expected output:

```text
This file was created while building the image
```

What happened?

During build:

```dockerfile
RUN echo ... > /build-message.txt
```

created a file inside the image.

During runtime:

```dockerfile
CMD ["cat", "/build-message.txt"]
```

printed it.

---

# 21. Understanding `CMD`

`CMD` defines the default command that runs when a container starts.

Example:

```dockerfile
FROM alpine

CMD ["echo", "Hello from the container"]
```

Build:

```bash
docker build -t day5-cmd-example .
```

Run:

```bash
docker run --rm day5-cmd-example
```

Output:

```text
Hello from the container
```

Every new container executes the default command unless you override it.

Override it:

```bash
docker run --rm day5-cmd-example \
  echo "I replaced the default command"
```

Output:

```text
I replaced the default command
```

The command after the image name replaces the image’s default `CMD`.

---

# 22. Exec form versus shell form

There are two common forms of `CMD`.

## Exec form

```dockerfile
CMD ["nginx", "-g", "daemon off;"]
```

This is a JSON array.

Advantages:

- Runs the executable directly
    
- Clear argument handling
    
- Better signal handling
    
- No implicit shell
    
- Preferred for long-running applications
    

## Shell form

```dockerfile
CMD nginx -g "daemon off;"
```

This normally runs through a shell similar to:

```text
/bin/sh -c
```

Shell form allows features such as:

- Environment-variable expansion
    
- Pipes
    
- Redirection
    
- Shell operators
    

But it adds a shell process and may complicate signal handling.

Prefer exec form for the main application:

```dockerfile
CMD ["python", "app.py"]
```

---

# 23. Why foreground processes matter

Nginx traditionally supports running as a background daemon.

Inside a container, the primary application should normally remain in the foreground.

That is why the Nginx image uses behavior equivalent to:

```bash
nginx -g "daemon off;"
```

If the main process starts a background daemon and then exits:

```text
PID 1 exits
     ↓
Docker considers the container stopped
```

Even if a child or background process was intended to continue, container behavior becomes incorrect.

The main service should usually run as PID 1 in the foreground.

---

# 24. Understanding `WORKDIR`

`WORKDIR` sets the current directory for later Dockerfile instructions and for the container’s default runtime directory.

Example:

```dockerfile
FROM alpine

WORKDIR /app

COPY message.txt .

CMD ["cat", "message.txt"]
```

After:

```dockerfile
WORKDIR /app
```

this:

```dockerfile
COPY message.txt .
```

means:

```text
Copy message.txt to /app/message.txt
```

And:

```dockerfile
CMD ["cat", "message.txt"]
```

runs with `/app` as the current working directory.

Without `WORKDIR`, you may need absolute paths:

```dockerfile
COPY message.txt /app/message.txt
CMD ["cat", "/app/message.txt"]
```

---

# 25. Practical `WORKDIR` example

Create:

```bash
mkdir -p ~/docker-course/day5/workdir-example
cd ~/docker-course/day5/workdir-example
```

Create `message.txt`:

```bash
echo "Hello from /app" > message.txt
```

Create `Dockerfile`:

```dockerfile
FROM alpine

WORKDIR /app

COPY message.txt .

CMD ["sh", "-c", "echo Current directory: $(pwd); cat message.txt"]
```

Build:

```bash
docker build -t day5-workdir-example .
```

Run:

```bash
docker run --rm day5-workdir-example
```

Expected output:

```text
Current directory: /app
Hello from /app
```

Inspect the image:

```bash
docker image inspect day5-workdir-example \
  --format '{{.Config.WorkingDir}}'
```

Expected:

```text
/app
```

---

# 26. Understanding `EXPOSE`

You can add this to a Dockerfile:

```dockerfile
EXPOSE 80
```

Example:

```dockerfile
FROM nginx:alpine

COPY index.html /usr/share/nginx/html/index.html

EXPOSE 80
```

`EXPOSE` declares that the application is expected to use port 80.

It does not publish the port to the host.

This does not work by itself for host access:

```dockerfile
EXPOSE 80
```

You still need:

```bash
docker run -p 8080:80 image
```

Think of `EXPOSE` as image metadata and documentation.

---

# 27. Does the Nginx Dockerfile need `EXPOSE 80`?

Your custom image inherits:

```text
EXPOSE 80
```

from the base `nginx:alpine` image.

Therefore, adding it again is not necessary.

You can verify:

```bash
docker image inspect day5-static-site:1.1 \
  --format '{{json .Config.ExposedPorts}}'
```

Expected:

```json
{"80/tcp":{}}
```

Adding `EXPOSE 80` to your own Dockerfile could make your intent clearer, but it does not change the actual host mapping.

---

# 28. A more complete first Dockerfile

A clearer version of your static-site Dockerfile could be:

```dockerfile
FROM nginx:alpine

COPY index.html /usr/share/nginx/html/index.html

EXPOSE 80
```

There is no `CMD` because the base image already supplies the correct Nginx startup command.

Do not add instructions merely to make the Dockerfile longer.

A good Dockerfile contains only the instructions needed to produce the intended image.

---

# 29. Docker build cache

Build the image again:

```bash
docker build -t day5-static-site:1.2 .
```

You may see output such as:

```text
CACHED
```

Docker reuses previous build results when:

- The instruction is unchanged
    
- Relevant source files are unchanged
    
- Previous layers are still available
    
- Earlier build conditions still match
    

For your Dockerfile:

```dockerfile
FROM nginx:alpine
COPY index.html /usr/share/nginx/html/index.html
```

Docker can reuse the base-image layer.

If `index.html` has not changed, it may reuse the `COPY` result too.

---

# 30. Cache invalidation

Modify `index.html`:

```bash
echo '<h1>Cache test</h1>' > index.html
```

Build:

```bash
docker build -t day5-static-site:1.3 .
```

The `FROM` step may remain cached, but the `COPY` step must be repeated because its source changed.

Conceptually:

```text
FROM nginx:alpine
→ unchanged
→ cache may be reused

COPY index.html ...
→ source content changed
→ rebuild this layer
```

Build caching becomes especially important for applications with large dependency-installation steps.

---

# 31. Build without using cache

You can force Docker not to reuse build cache:

```bash
docker build \
  --no-cache \
  -t day5-static-site:no-cache \
  .
```

This is useful when:

- Testing whether cache hides a build problem
    
- Forcing package installation steps to rerun
    
- Diagnosing stale build behavior
    

Do not use `--no-cache` automatically for every build. It can make builds significantly slower.

---

# 32. Pulling an updated base image during build

A cached build might continue using a locally available base image.

To request newer registry metadata and pull an updated base image where available:

```bash
docker build \
  --pull \
  -t day5-static-site:updated \
  .
```

`--pull` means Docker should check for a newer version of the referenced base image.

Even with a fixed tag such as:

```dockerfile
FROM nginx:alpine
```

the tag may point to updated content over time.

For strict reproducibility, later you can pin base images using digests.

---

# 33. View image history

Run:

```bash
docker image history day5-static-site:1.1
```

You should see layers inherited from Nginx and a layer corresponding to your `COPY`.

Use:

```bash
docker image history --no-trunc day5-static-site:1.1
```

Look for the instruction associated with your HTML file.

This helps you understand that your image is based on layers rather than one giant mutable filesystem snapshot.

---

# 34. Inspect which image a container uses

Run:

```bash
docker inspect day5-website \
  --format 'Configured image={{.Config.Image}}'
```

To obtain the exact underlying image ID:

```bash
docker inspect day5-website \
  --format '{{.Image}}'
```

Compare with:

```bash
docker image inspect day5-static-site:1.1 \
  --format '{{.Id}}'
```

The IDs should correspond.

---

# 35. Dockerfile instructions are processed in order

Consider:

```dockerfile
FROM alpine

COPY message.txt /message.txt

RUN cat /message.txt
```

Docker processes:

1. `FROM`
    
2. `COPY`
    
3. `RUN`
    

This works because the file exists before `RUN` attempts to read it.

This would fail:

```dockerfile
FROM alpine

RUN cat /message.txt

COPY message.txt /message.txt
```

At the time `RUN` executes, the file has not yet been copied.

Dockerfile order defines both:

- Build behavior
    
- Layer structure
    
- Cache effectiveness
    

---

# 36. Difference between `COPY` and a bind mount

This is an important distinction.

## `COPY` in Dockerfile

```dockerfile
COPY index.html /usr/share/nginx/html/index.html
```

The file becomes part of the image during build.

Characteristics:

- Included in the image
    
- Available wherever the image runs
    
- Versioned with the image
    
- Does not automatically change when the host file changes
    

## Bind mount at runtime

```bash
docker run \
  -v "$PWD/index.html:/usr/share/nginx/html/index.html:ro" \
  nginx
```

The container reads the host file directly.

Characteristics:

- Host file is not embedded in the image
    
- Host changes may appear immediately
    
- Depends on the host path
    
- Common during development
    

You will study storage and bind mounts more deeply later.

For production artifacts, application code is often copied into the image.

---

# 37. Difference between `COPY` and `docker cp`

You can manually copy a file into a container:

```bash
docker cp index.html \
  day5-website:/usr/share/nginx/html/index.html
```

This changes that particular container.

It does not change the image.

If the container is removed, the manually copied change disappears.

Compare:

```text
Dockerfile COPY
→ modifies the built image
→ every new container receives the file
```

```text
docker cp
→ modifies one container
→ change is not reproducible
```

Use `docker cp` mainly for:

- Debugging
    
- Retrieving diagnostic files
    
- Temporary testing
    
- One-time inspection
    

Do not use it as your normal deployment process.

---

# 38. Why not use `docker commit`?

Docker can create an image from a modified container:

```bash
docker commit container-name image-name
```

However, this is usually not the preferred application-build process.

Problems include:

- Changes are not clearly documented.
    
- Reproduction is difficult.
    
- The result is harder to review.
    
- Version control does not contain the build procedure.
    
- Manual mistakes become part of the image.
    

Prefer:

```text
Dockerfile
→ reviewed
→ version controlled
→ repeatable
```

rather than:

```text
Manually modified container
→ docker commit
→ undocumented image
```

`docker commit` can be useful for experiments, but Dockerfiles are the proper foundation for maintainable images.

---

# 39. Build errors you are likely to encounter

## Error: Dockerfile not found

Example:

```text
failed to read dockerfile
```

Check:

```bash
ls -la
```

Make sure:

- The file is named `Dockerfile`
    
- You are in the correct directory
    
- The file is not actually `Dockerfile.txt`
    

You can specify another filename:

```bash
docker build \
  -f Dockerfile.dev \
  -t application:dev \
  .
```

---

## Error: Source file not found

Example:

```text
COPY failed
```

or:

```text
failed to calculate checksum
```

Check:

- Is the file inside the build context?
    
- Is the filename spelled correctly?
    
- Does case match exactly?
    
- Is `.dockerignore` excluding it?
    
- Did you run the command from the correct directory?
    

---

## Error: Invalid reference format

Example:

```text
invalid reference format
```

Image names must use an appropriate format.

Good:

```text
day5-static-site:1.0
my-company/application:2.3
```

Problematic examples may include:

```text
My Application
Application:Version One
```

Prefer lowercase repository names and no spaces.

---

## Error: Port already allocated

If this fails:

```bash
docker run -p 8080:80 day5-static-site:1.0
```

check:

```bash
docker ps --format 'table {{.Names}}\t{{.Ports}}'
```

Another container or host process may already use port 8080.

Use another port or remove the existing listener.

---

## Error: Container name already in use

Check stopped containers too:

```bash
docker ps -a --filter name=day5-website
```

Either restart it:

```bash
docker start day5-website
```

or replace it:

```bash
docker rm -f day5-website
```

---

# 40. Add labels to an image

Labels store metadata in the image.

Example:

```dockerfile
FROM nginx:alpine

LABEL org.opencontainers.image.title="Docker Day 5 Static Site"
LABEL org.opencontainers.image.version="1.0"
LABEL org.opencontainers.image.description="Training website served by Nginx"

COPY index.html /usr/share/nginx/html/index.html
```

Build:

```bash
docker build -t day5-labeled-site:1.0 .
```

Inspect labels:

```bash
docker image inspect day5-labeled-site:1.0 \
  --format '{{json .Config.Labels}}'
```

Labels can record:

- Application name
    
- Version
    
- Description
    
- Source repository
    
- Maintainer information
    
- Build metadata
    
- Licensing information
    

Labels do not affect how the application runs unless tools explicitly use them.

---

# 41. Comments in Dockerfiles

Use `#` for comments:

```dockerfile
# Start from the official Nginx Alpine image
FROM nginx:alpine

# Replace the default homepage
COPY index.html /usr/share/nginx/html/index.html
```

Comments should explain decisions, not obvious syntax.

Less useful:

```dockerfile
# Copy index.html
COPY index.html /usr/share/nginx/html/index.html
```

More useful:

```dockerfile
# Nginx serves static content from this directory by default.
COPY index.html /usr/share/nginx/html/index.html
```

Keep Dockerfiles readable without filling them with unnecessary explanations.

---

# 42. A second practical image: command-line utility

Create:

```bash
mkdir -p ~/docker-course/day5/greeting-app
cd ~/docker-course/day5/greeting-app
```

Create `greet.sh`:

```sh
#!/bin/sh

echo "Hello, ${NAME:-Docker student}!"
echo "This container is running on:"
uname -a
```

Make it executable:

```bash
chmod +x greet.sh
```

Create `Dockerfile`:

```dockerfile
FROM alpine:latest

WORKDIR /app

COPY greet.sh .

CMD ["./greet.sh"]
```

Build:

```bash
docker build -t greeting-app:1.0 .
```

Run:

```bash
docker run --rm greeting-app:1.0
```

Override an environment variable:

```bash
docker run --rm \
  -e NAME=George \
  greeting-app:1.0
```

The script uses:

```sh
${NAME:-Docker student}
```

Meaning:

```text
Use NAME if supplied.
Otherwise use Docker student.
```

Environment variables will be covered more deeply later.

---

# 43. Script execution problems

If your container reports:

```text
permission denied
```

check that the script is executable:

```bash
chmod +x greet.sh
```

You can also set permission during the build:

```dockerfile
COPY greet.sh .

RUN chmod +x greet.sh
```

A more modern `COPY` form supported by current Docker builders is:

```dockerfile
COPY --chmod=755 greet.sh .
```

Another frequent problem is Windows line endings.

A script created on Windows may contain CRLF line endings and produce errors such as:

```text
/bin/sh^M: bad interpreter
```

Convert it to Unix line endings:

```bash
dos2unix greet.sh
```

Or configure your editor to save shell scripts using LF endings.

---

# 44. Do not install software manually in the final container

Suppose your application requires `curl`.

Bad operational process:

```bash
docker exec -it application sh
apk add curl
```

This modifies only the running container.

Better Dockerfile:

```dockerfile
FROM alpine

RUN apk add --no-cache curl

CMD ["curl", "--version"]
```

Build:

```bash
docker build -t curl-tool:1.0 .
```

Now every container created from the image contains `curl`.

The image becomes the documented and reusable artifact.

---

# 45. Package installation example using Alpine

Create:

```bash
mkdir -p ~/docker-course/day5/package-example
cd ~/docker-course/day5/package-example
```

Dockerfile:

```dockerfile
FROM alpine:latest

RUN apk add --no-cache curl

CMD ["curl", "--version"]
```

Build:

```bash
docker build -t day5-curl-tool:1.0 .
```

Run:

```bash
docker run --rm day5-curl-tool:1.0
```

Compare with the original Alpine image:

```bash
docker run --rm alpine curl --version
```

The original should fail because `curl` is not normally included.

Your custom image succeeds because the package was installed during the build.

---

# 46. Package installation example using Debian or Ubuntu

For Debian-based images, package installation commonly looks like:

```dockerfile
FROM debian:13-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

CMD ["curl", "--version"]
```

Why combine the commands?

```dockerfile
RUN apt-get update \
    && apt-get install ...
```

The package index update and installation belong in the same build step.

Why remove:

```text
/var/lib/apt/lists/*
```

The downloaded package index is not usually required in the final runtime image, so removing it reduces unnecessary image content.

Why use:

```text
--no-install-recommends
```

It reduces extra packages that are recommended but not strictly required.

---

# 47. One process per container: useful guideline

You will often hear:

> Run one process per container.

A more accurate practical interpretation is:

> Give each container one main responsibility and one clearly managed primary process.

For example:

```text
Nginx container
PostgreSQL container
MQTT broker container
Dashboard container
MQTT consumer container
```

Avoid building one container that manually starts:

- Nginx
    
- PostgreSQL
    
- Mosquitto
    
- PHP worker
    
- Cron
    
- SSH server
    

Such containers are harder to:

- Update
    
- Scale
    
- Monitor
    
- Restart
    
- Secure
    
- Troubleshoot
    

There are legitimate exceptions, but one service responsibility per container is an excellent default.

---

# 48. Your MQTT-project implications

For your MQTT device-monitor project, you may eventually have separate Dockerfiles:

```text
mqtt-platform/
├── dashboard/
│   └── Dockerfile
├── consumer/
│   └── Dockerfile
├── mqtt-daemon/
│   └── Dockerfile
└── proxy/
    └── Dockerfile
```

Each image should package one component.

For example, a PHP/Apache dashboard may begin with:

```dockerfile
FROM php:8.4-apache

WORKDIR /var/www/html

COPY . .

EXPOSE 80
```

A compiled C daemon may eventually use a build stage and a runtime stage:

```dockerfile
FROM debian:13 AS builder
# Compile the C application

FROM debian:13-slim
# Copy only the runtime executable
```

Multi-stage builds will be taught later.

---

# 49. Day 5 practical laboratory

## Exercise 1 — Build the static site

Create:

```text
static-site/
├── Dockerfile
└── index.html
```

Dockerfile:

```dockerfile
FROM nginx:alpine

COPY index.html /usr/share/nginx/html/index.html

EXPOSE 80
```

Build:

```bash
docker build -t day5-site:1.0 .
```

Run:

```bash
docker run -d \
  --name day5-site-v1 \
  -p 8080:80 \
  day5-site:1.0
```

Test:

```bash
curl http://localhost:8080
```

---

## Exercise 2 — Inspect the custom image

Run:

```bash
docker image inspect day5-site:1.0
```

Extract important properties:

```bash
docker image inspect day5-site:1.0 \
  --format 'Base OS={{.Os}} Architecture={{.Architecture}}'
```

```bash
docker image inspect day5-site:1.0 \
  --format 'Entrypoint={{json .Config.Entrypoint}}'
```

```bash
docker image inspect day5-site:1.0 \
  --format 'Cmd={{json .Config.Cmd}}'
```

```bash
docker image inspect day5-site:1.0 \
  --format 'Ports={{json .Config.ExposedPorts}}'
```

---

## Exercise 3 — Verify image content

Enter the container:

```bash
docker exec -it day5-site-v1 sh
```

Inside:

```sh
cat /usr/share/nginx/html/index.html
cat /etc/os-release
ps
exit
```

Confirm:

- Your HTML is inside the container
    
- The filesystem is based on Alpine
    
- Nginx is the main service
    

---

## Exercise 4 — Create version 2.0

Modify the HTML.

Build:

```bash
docker build -t day5-site:2.0 .
```

Run simultaneously:

```bash
docker run -d \
  --name day5-site-v2 \
  -p 8081:80 \
  day5-site:2.0
```

Compare:

```bash
curl http://localhost:8080
curl http://localhost:8081
```

Explain why version 1 did not change when version 2 was built.

---

## Exercise 5 — Replace the old version

Remove version 1’s container:

```bash
docker rm -f day5-site-v1
```

Run version 2 on port 8080:

```bash
docker run -d \
  --name day5-site-current \
  -p 8080:80 \
  day5-site:2.0
```

Verify:

```bash
curl http://localhost:8080
```

---

## Exercise 6 — Test build cache

Build without changing files:

```bash
docker build -t day5-site:2.1 .
```

Observe cached steps.

Modify `index.html`, then build:

```bash
docker build -t day5-site:2.2 .
```

Observe which step is rebuilt.

Finally:

```bash
docker build \
  --no-cache \
  -t day5-site:no-cache \
  .
```

Compare the output.

---

## Exercise 7 — Create a command-line image

Dockerfile:

```dockerfile
FROM alpine

WORKDIR /app

RUN echo "Created during image build" > message.txt

CMD ["cat", "message.txt"]
```

Build:

```bash
docker build -t day5-message:1.0 .
```

Run it several times:

```bash
docker run --rm day5-message:1.0
docker run --rm day5-message:1.0
```

Each container should produce the same output.

---

## Exercise 8 — Override `CMD`

Run:

```bash
docker run --rm \
  day5-message:1.0 \
  echo "Overridden runtime command"
```

Explain:

- The image is unchanged.
    
- The container used the supplied command instead of the default `CMD`.
    

---

## Exercise 9 — Install a package at build time

Create:

```dockerfile
FROM alpine

RUN apk add --no-cache curl

CMD ["curl", "--version"]
```

Build:

```bash
docker build -t day5-curl:1.0 .
```

Run:

```bash
docker run --rm day5-curl:1.0
```

Confirm that `curl` is present in every new container created from this image.

---

## Exercise 10 — Clean up

List Day 5 containers:

```bash
docker ps -a \
  --filter name=day5
```

Remove them:

```bash
docker rm -f \
  day5-site-v2 \
  day5-site-current \
  day5-website-v11 \
  day5-website \
  2>/dev/null
```

List your custom images:

```bash
docker image ls \
  --filter reference='day5-*'
```

Keep useful images for inspection, or remove selected ones:

```bash
docker image rm IMAGE_NAME:TAG
```

---

# 50. Day 5 command reference

```bash
# Build using the current directory
docker build -t IMAGE_NAME:TAG .

# Use a different Dockerfile
docker build -f Dockerfile.dev -t IMAGE_NAME:TAG .

# Build without cache
docker build --no-cache -t IMAGE_NAME:TAG .

# Check for a newer base image
docker build --pull -t IMAGE_NAME:TAG .

# List custom images
docker image ls

# Inspect an image
docker image inspect IMAGE_NAME:TAG

# Display image layers/history
docker image history IMAGE_NAME:TAG

# Run the custom image
docker run IMAGE_NAME:TAG

# Run in detached mode with a port
docker run -d \
  --name CONTAINER_NAME \
  -p HOST_PORT:CONTAINER_PORT \
  IMAGE_NAME:TAG

# Check which image a container uses
docker inspect CONTAINER_NAME \
  --format '{{.Config.Image}}'

# Copy a file to a container temporarily
docker cp SOURCE CONTAINER:DESTINATION
```

---

# 51. Dockerfile instruction summary

|Instruction|Primary purpose|
|---|---|
|`FROM`|Select the base image|
|`COPY`|Copy files into the image|
|`RUN`|Execute a command during image build|
|`WORKDIR`|Set the working directory|
|`EXPOSE`|Document an intended container port|
|`CMD`|Define the default runtime command|
|`LABEL`|Add image metadata|

Example:

```dockerfile
FROM alpine:latest

LABEL org.opencontainers.image.title="Example Application"

WORKDIR /app

COPY app.sh .

RUN chmod +x app.sh

CMD ["./app.sh"]
```

---

# 52. Knowledge check

## Question 1

What does a Dockerfile do?

**Answer:**

It records the instructions Docker uses to build a reproducible image.

## Question 2

What does the final dot mean here?

```bash
docker build -t application:1.0 .
```

**Answer:**

It sets the current directory as the build context.

## Question 3

What is the difference between `RUN` and `CMD`?

**Answer:**

`RUN` executes while building the image and stores its filesystem result. `CMD` defines the default command executed when a container starts.

## Question 4

Does `COPY` read files from the running container?

**Answer:**

No. It copies files from the build context into the image during the build.

## Question 5

Does `EXPOSE 80` publish host port 80?

**Answer:**

No. It only records intended port metadata. Host publishing still requires `-p` or equivalent configuration.

## Question 6

Why did editing `index.html` not change the running container?

**Answer:**

The file was copied into the image during the build. Changing the host source does not modify an existing image or container.

## Question 7

Does building image version 2 replace containers running version 1?

**Answer:**

No. Existing containers continue using the image from which they were created.

## Question 8

How do you deploy a new image version?

**Answer:**

Build the new image, test it, remove or stop the old container, and create a replacement container using the new image.

## Question 9

Why is exec-form `CMD` generally preferred?

**Answer:**

It runs the application directly, handles arguments clearly, and generally provides better signal behavior.

## Question 10

Why is a Dockerfile preferable to manually editing a container?

**Answer:**

It is reproducible, reviewable, version-controlled, and can reliably produce the same image again.

---

# 53. Day 5 completion challenge

Complete this without copying the previous commands.

1. Create a directory named `day5-challenge`.
    
2. Create an HTML page containing your name and the text `Docker Day 5`.
    
3. Create a Dockerfile based on `nginx:alpine`.
    
4. Copy the page into the default Nginx document root.
    
5. Build it as `challenge-site:1.0`.
    
6. Run it as `challenge-site-v1`.
    
7. Publish it on host port 9080.
    
8. Test it with `curl`.
    
9. Inspect the exact image used by the container.
    
10. Inspect the image’s default command.
    
11. Modify the page.
    
12. Build `challenge-site:2.0`.
    
13. Run version 2 on host port 9081.
    
14. Verify that versions 1 and 2 serve different content.
    
15. Explain why version 1 remained unchanged.
    
16. Remove the version 1 container.
    
17. Run version 2 on port 9080.
    
18. Enter the new container and inspect the HTML file.
    
19. View the image history.
    
20. Remove all challenge containers.
    

The central Day 5 model is:

```text
Dockerfile instructions
        +
Application files
        ↓
docker build
        ↓
Versioned image
        ↓
docker run
        ↓
Replaceable container
```

The most important lesson is:

> Do not manually construct important containers. Describe the desired image in a Dockerfile, build it reproducibly, and replace containers when the image changes.