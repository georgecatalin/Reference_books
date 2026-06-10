#### Docker Images, Tags, Registries, and Image Management

Day 4 is about understanding the object from which containers are created: the **Docker image**.

You already know:

```text
Image → docker run → Container
```

Today you will learn what an image contains, how Docker names and downloads images, how tags work, how to inspect image metadata, and how to manage images safely.

By the end of Day 4, you should understand:

- What a Docker image really is
    
- How image names are structured
    
- What repositories, registries, tags, and digests mean
    
- Why `latest` is not a version guarantee
    
- How `docker pull` works
    
- How Docker decides whether an image already exists locally
    
- How to inspect image configuration and layers
    
- How to compare image variants
    
- How to tag and remove images
    
- The difference between deleting an image and deleting a container
    

---

# 1. What a Docker image is

A Docker image is a packaged template used to create containers.

It usually contains:

- Application binaries
    
- Runtime libraries
    
- A minimal filesystem
    
- Default environment variables
    
- Default command
    
- Entrypoint configuration
    
- User configuration
    
- Working directory
    
- Port metadata
    
- Files copied during the image build
    

For example, the Nginx image may contain:

```text
Nginx executable
Nginx configuration files
Shared libraries
Default HTML files
Startup scripts
Minimal Linux filesystem
Default startup command
```

The image itself is not a running process.

It becomes useful when Docker creates a container from it.

```text
nginx image
    ↓ docker run
nginx container
    ↓
Nginx process starts
```

---

# 2. Image versus container

This distinction must become automatic.

## Image

An image is:

- Read-only
    
- Reusable
    
- Versioned
    
- Stored locally or in a registry
    
- Used as a template
    

## Container

A container is:

- Created from an image
    
- Running or stopped
    
- Given its own writable layer
    
- Given its own name and ID
    
- Given runtime configuration
    

One image can create many containers:

```text
nginx:1.28 image
├── frontend-1 container
├── frontend-2 container
└── frontend-3 container
```

Removing one container does not remove the image.

Removing the image does not automatically remove containers that still depend on it.

---

# 3. Image naming structure

A complete image reference can look like:

```text
registry.example.com/team/application:1.4.2
```

Breakdown:

```text
registry.example.com
```

The registry server.

```text
team
```

The namespace or organization.

```text
application
```

The repository name.

```text
1.4.2
```

The tag.

The general structure is:

```text
[registry/][namespace/]repository[:tag]
```

For example:

```text
docker.io/library/nginx:latest
```

is the expanded form of:

```text
nginx
```

When you type:

```bash
docker pull nginx
```

Docker normally interprets it as:

```text
docker.io/library/nginx:latest
```

---

# 4. What a registry is

A registry stores and distributes images.

Common registries include:

- Docker Hub
    
- GitLab Container Registry
    
- GitHub Container Registry
    
- Amazon ECR
    
- Azure Container Registry
    
- Google Artifact Registry
    
- Harbor
    
- Private company registries
    

A registry is similar to a package server for container images.

You can:

- Pull images
    
- Push images
    
- Store multiple versions
    
- Restrict access
    
- Scan images
    
- Organize images by project or team
    

Example:

```bash
docker pull postgres:17
```

Docker downloads the image from a registry.

Later, you may push your own image:

```bash
docker push registry.example.com/team/dashboard:1.0
```

---

# 5. What a repository is

A repository groups related image versions.

For example, the `nginx` repository may contain:

```text
nginx:latest
nginx:1.28
nginx:1.28-alpine
nginx:alpine
nginx:mainline
```

These are not necessarily completely different applications.

They are different image versions or variants stored under the same repository name.

A useful comparison is:

```text
Repository = application family
Tag = version or variant
```

---

# 6. What a tag is

A tag is a human-readable label pointing to an image.

Examples:

```text
ubuntu:24.04
postgres:17
python:3.13-slim
nginx:alpine
php:8.4-apache
```

The tag follows the colon:

```text
repository:tag
```

For:

```text
python:3.13-slim
```

- Repository: `python`
    
- Tag: `3.13-slim`
    

Tags often describe:

- Version
    
- Linux distribution
    
- Build variant
    
- Runtime type
    
- Architecture strategy
    

Examples:

```text
python:3.13
python:3.13-slim
python:3.13-alpine
python:3.13-bookworm
```

They all provide Python 3.13, but with different base systems and trade-offs.

---

# 7. Why `latest` is dangerous to misunderstand

If you omit the tag:

```bash
docker pull nginx
```

Docker usually assumes:

```bash
docker pull nginx:latest
```

But `latest` does not mean:

- Newest secure version
    
- Highest semantic version
    
- Most recently published image
    
- Recommended production version
    
- Automatically updated image
    

It is simply a tag named `latest`.

The image publisher decides what it points to.

For production, prefer explicit tags:

```text
nginx:1.28
postgres:17
python:3.13-slim
```

Rather than relying only on:

```text
nginx:latest
postgres:latest
python:latest
```

Explicit versions make deployments more predictable.

---

# 8. Pull your first images explicitly

Run:

```bash
docker pull alpine:latest
```

Then:

```bash
docker pull ubuntu:24.04
```

And:

```bash
docker pull nginx:alpine
```

The output may show multiple lines such as:

```text
Pulling fs layer
Downloading
Verifying Checksum
Download complete
Pull complete
```

These lines correspond to image layers.

Docker downloads only layers that are not already present locally.

---

# 9. List local images

Use:

```bash
docker image ls
```

Or the older equivalent:

```bash
docker images
```

Typical output:

```text
REPOSITORY   TAG       IMAGE ID       CREATED        SIZE
nginx        alpine    abc123...      2 weeks ago    50MB
ubuntu       24.04     def456...      3 weeks ago    78MB
alpine       latest    ghi789...      1 month ago    8MB
```

Important columns:

|Column|Meaning|
|---|---|
|`REPOSITORY`|Image repository name|
|`TAG`|Image tag|
|`IMAGE ID`|Local identifier|
|`CREATED`|When the image was built|
|`SIZE`|Approximate local image size|

---

# 10. Image IDs

Every image has an ID.

Display images:

```bash
docker image ls
```

You may see:

```text
IMAGE ID
a1b2c3d4e5f6
```

The displayed ID is a shortened version.

Inspect the full ID:

```bash
docker image inspect alpine:latest \
  --format '{{.Id}}'
```

You may see something similar to:

```text
sha256:...
```

Image IDs are content-based identifiers.

If the image content changes, its ID changes.

---

# 11. Pulling the same image twice

Run:

```bash
docker pull alpine:latest
```

Then run it again:

```bash
docker pull alpine:latest
```

The second time, Docker may report that the image is already up to date.

Docker does not unnecessarily redownload identical layers.

This demonstrates Docker’s content-addressed storage.

If the tag now points to a newer image, Docker downloads the changed layers.

---

# 12. Tags are references, not immutable objects

A tag can move from one image to another.

For example, today:

```text
application:latest → image A
```

Later:

```text
application:latest → image B
```

The tag stayed the same, but the underlying image changed.

This is why two servers pulling `application:latest` at different times may receive different content.

For repeatable production deployments, use:

- Explicit version tags
    
- Image digests
    
- Immutable registry policies
    

---

# 13. What an image digest is

A digest identifies image content more precisely.

A digest looks like:

```text
sha256:very-long-hash
```

You may see an image reference such as:

```text
nginx@sha256:...
```

A tag is human-friendly:

```text
nginx:1.28
```

A digest is content-specific:

```text
nginx@sha256:...
```

If you pull by digest, you request exact image content.

Example form:

```bash
docker pull nginx@sha256:IMAGE_DIGEST
```

This helps create reproducible deployments because the digest does not silently move to another image.

---

# 14. Inspect image repository digests

Run:

```bash
docker image inspect nginx:alpine \
  --format '{{json .RepoDigests}}'
```

You may see:

```json
["nginx@sha256:..."]
```

You can also use:

```bash
docker image ls --digests
```

This adds a `DIGEST` column.

---

# 15. Inspect image metadata

Run:

```bash
docker image inspect nginx:alpine
```

The output is JSON.

Important areas include:

- Image ID
    
- Repository tags
    
- Repository digests
    
- Creation time
    
- Architecture
    
- Operating system
    
- Environment variables
    
- Working directory
    
- Entrypoint
    
- Default command
    
- Exposed ports
    
- Layers
    

You do not need to memorize the entire structure.

Use formatting to extract only what you need.

---

# 16. Inspect the default command

Run:

```bash
docker image inspect nginx:alpine \
  --format '{{json .Config.Cmd}}'
```

This shows the default command configured in the image.

Also inspect the entrypoint:

```bash
docker image inspect nginx:alpine \
  --format '{{json .Config.Entrypoint}}'
```

Together, `ENTRYPOINT` and `CMD` define the process Docker starts by default.

You will study this more deeply when writing Dockerfiles.

---

# 17. Inspect environment variables

Run:

```bash
docker image inspect nginx:alpine \
  --format '{{json .Config.Env}}'
```

You may see variables such as:

```text
PATH=...
NGINX_VERSION=...
```

These are default environment variables stored in the image configuration.

At runtime, you can override or add variables using:

```bash
docker run -e NAME=value IMAGE
```

---

# 18. Inspect exposed ports

Run:

```bash
docker image inspect nginx:alpine \
  --format '{{json .Config.ExposedPorts}}'
```

You may see:

```json
{"80/tcp":{}}
```

This means the image declares port 80 as an expected application port.

It does not publish the port to the host.

You still need:

```bash
docker run -p 8080:80 nginx:alpine
```

---

# 19. Inspect the working directory

Run:

```bash
docker image inspect nginx:alpine \
  --format '{{.Config.WorkingDir}}'
```

Some images define a default working directory.

If none is configured, the result may be empty.

When a container starts, this directory becomes the initial working directory for the main process.

---

# 20. Inspect the default user

Run:

```bash
docker image inspect nginx:alpine \
  --format '{{.Config.User}}'
```

If the output is empty, the image normally starts as root unless changed at runtime.

Some images define a non-root user.

Running as non-root is generally safer, but the image must be designed with correct permissions.

---

# 21. Image layers

Docker images are built as a sequence of layers.

Conceptually:

```text
Base filesystem layer
        ↓
Installed packages layer
        ↓
Copied application files layer
        ↓
Configuration layer
```

For example:

```dockerfile
FROM python:3.13-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install -r requirements.txt
COPY app.py .
```

This may create layers corresponding to:

```text
python base image
working-directory metadata
requirements file
installed dependencies
application source
```

Layers are:

- Read-only
    
- Reusable
    
- Cached
    
- Shared between related images
    

---

# 22. Why layers matter

Layers provide several advantages.

## Faster downloads

If two images share layers, Docker downloads the shared content only once.

## Faster builds

Unchanged build steps can be reused from cache.

## Storage efficiency

Related images can share common layers.

## Better distribution

Registries transfer only missing layers.

This is why Docker output often shows:

```text
Layer already exists
```

or:

```text
Already exists
```

---

# 23. Inspect image history

Run:

```bash
docker image history nginx:alpine
```

This shows the commands or build steps that contributed to the image.

You may see columns such as:

```text
IMAGE
CREATED
CREATED BY
SIZE
COMMENT
```

Use the non-truncated view:

```bash
docker image history --no-trunc nginx:alpine
```

This can help you understand:

- Which steps added size
    
- How the image was built
    
- Whether unnecessary packages were included
    
- Whether suspicious commands appear in image history
    

However, it does not always reveal the original Dockerfile exactly.

---

# 24. Compare image variants

Pull several variants:

```bash
docker pull nginx:latest
docker pull nginx:alpine
```

Then:

```bash
docker image ls nginx
```

Compare:

- Image size
    
- Default shell
    
- Package manager
    
- Filesystem contents
    
- Library compatibility
    
- Available debugging tools
    

Enter the standard image:

```bash
docker run --rm -it nginx:latest sh
```

Inside:

```sh
cat /etc/os-release
exit
```

Enter the Alpine variant:

```bash
docker run --rm -it nginx:alpine sh
```

Inside:

```sh
cat /etc/os-release
exit
```

The images provide the same main application but use different base distributions.

---

# 25. Standard versus slim versus Alpine images

Many official images provide variants.

For example:

```text
python:3.13
python:3.13-slim
python:3.13-alpine
```

## Standard image

Advantages:

- More tools available
    
- Easier debugging
    
- Broader package compatibility
    

Disadvantages:

- Larger image
    
- More packages
    
- Larger attack surface
    

## Slim image

Advantages:

- Smaller than standard
    
- Usually still based on Debian
    
- Good compatibility
    
- Often a strong production default
    

Disadvantages:

- Some tools and libraries are missing
    
- May require installing build dependencies
    

## Alpine image

Advantages:

- Very small
    
- Minimal filesystem
    
- Fast transfer
    

Disadvantages:

- Uses musl libc instead of glibc
    
- Some packages compile differently
    
- Some Python or native dependencies may be difficult
    
- Fewer debugging tools
    
- Small image size does not automatically mean simpler maintenance
    

Do not select Alpine only because it is small.

Choose based on compatibility, security, maintainability, and operational needs.

---

# 26. Architecture information

Inspect:

```bash
docker image inspect alpine \
  --format 'OS={{.Os}} Architecture={{.Architecture}}'
```

Possible output:

```text
OS=linux Architecture=amd64
```

On an ARM system, you may see:

```text
Architecture=arm64
```

Image architecture matters because a binary built for AMD64 normally cannot run directly on ARM64 without emulation or a matching image variant.

Later, you will learn multi-platform images and Buildx.

---

# 27. Multi-platform image manifests

Some image tags support multiple architectures.

For example, a single tag such as:

```text
alpine:latest
```

may provide variants for:

- `linux/amd64`
    
- `linux/arm64`
    
- Other supported architectures
    

Docker selects the appropriate image for the host platform.

You can inspect remote manifest information using:

```bash
docker buildx imagetools inspect alpine:latest
```

Or, depending on your Docker version:

```bash
docker manifest inspect alpine:latest
```

You do not need to master this today, but recognize that one tag may represent multiple architecture-specific images.

---

# 28. Pull a specific platform

On a system with suitable support, you can request a platform:

```bash
docker pull --platform linux/amd64 alpine
```

Or:

```bash
docker pull --platform linux/arm64 alpine
```

Running an image for a foreign architecture may require emulation and can be slower.

For production, use a native image whenever possible.

---

# 29. Tagging an image locally

You can create another tag pointing to an existing local image.

Run:

```bash
docker tag nginx:alpine my-nginx:1.0
```

List images:

```bash
docker image ls
```

You may see:

```text
nginx       alpine   IMAGE_ID
my-nginx    1.0      SAME_IMAGE_ID
```

No complete image copy was created.

Both tags point to the same underlying image.

This is similar to creating another label for the same content.

---

# 30. Why image tagging is useful

Tags are used when:

- Assigning a version
    
- Preparing an image for a registry
    
- Creating environment-specific references
    
- Marking a release
    
- Preserving an old image reference
    
- Naming a custom build
    

Example:

```bash
docker tag dashboard:latest dashboard:1.0.0
```

For a private registry:

```bash
docker tag dashboard:1.0.0 \
  registry.example.com/mqtt/dashboard:1.0.0
```

Then it can be pushed using:

```bash
docker push registry.example.com/mqtt/dashboard:1.0.0
```

---

# 31. Tag naming does not change image content

Suppose:

```bash
docker tag alpine:latest company-app:production
```

The Alpine image did not become your company application.

You only assigned another name to the same image.

The image content remains unchanged.

Tags describe or reference images. They do not rebuild or modify them.

---

# 32. Remove a tag

Suppose these two tags share one image:

```text
nginx:alpine
my-nginx:1.0
```

Remove one tag:

```bash
docker image rm my-nginx:1.0
```

The underlying image may remain because `nginx:alpine` still references it.

Check:

```bash
docker image ls
```

This is sometimes called untagging.

---

# 33. Remove an image

Use:

```bash
docker image rm IMAGE
```

For example:

```bash
docker image rm alpine:latest
```

Docker may refuse if a container still references the image.

Check:

```bash
docker ps -a --filter ancestor=alpine
```

Remove the containers first:

```bash
docker rm CONTAINER_NAME
```

Then remove the image.

---

# 34. Why Docker refuses to remove some images

Suppose you create a container:

```bash
docker create --name alpine-test alpine
```

Then try:

```bash
docker image rm alpine
```

Docker may reject the removal because the container depends on that image.

Even though the container is not running, it still references the image.

Remove the container:

```bash
docker rm alpine-test
```

Then:

```bash
docker image rm alpine
```

This protects container consistency.

---

# 35. Force-removing an image

You can use:

```bash
docker image rm -f IMAGE
```

But forcing removal can make the local image references confusing and should not be your default approach.

Prefer to understand what references the image.

Check containers based on it:

```bash
docker ps -a --filter ancestor=IMAGE
```

Then remove or replace those containers deliberately.

---

# 36. Dangling images

A dangling image is usually an image without a repository name or tag.

It may appear as:

```text
REPOSITORY   TAG       IMAGE ID
<none>       <none>    abc123...
```

This often happens after rebuilding an image with the same tag.

The previous image loses the tag and becomes dangling.

List dangling images:

```bash
docker image ls --filter dangling=true
```

Remove them:

```bash
docker image prune
```

Docker asks for confirmation.

---

# 37. Unused images versus dangling images

These are not exactly the same.

## Dangling image

An untagged image layer or image, often left after rebuilding.

## Unused image

An image that is not used by any container.

By default:

```bash
docker image prune
```

removes dangling images.

To remove all unused images:

```bash
docker image prune -a
```

Use `-a` carefully.

It can remove images you planned to use later, forcing Docker to download or rebuild them again.

---

# 38. Inspect Docker disk usage

Run:

```bash
docker system df
```

This shows disk usage for:

- Images
    
- Containers
    
- Local volumes
    
- Build cache
    

For more detail:

```bash
docker system df -v
```

This helps identify:

- Large images
    
- Shared image sizes
    
- Reclaimable space
    
- Old containers
    
- Build cache growth
    

---

# 39. Image size is not always straightforward

The `SIZE` column in:

```bash
docker image ls
```

shows the virtual size of an image.

Related images may share layers, so their combined actual disk use can be lower than adding the displayed sizes.

Use:

```bash
docker system df -v
```

to understand:

- Unique size
    
- Shared size
    
- Reclaimable size
    

---

# 40. Exporting and saving images

Docker can save an image to a tar archive.

Example:

```bash
docker image save \
  -o nginx-alpine.tar \
  nginx:alpine
```

This preserves image metadata and layers.

Load it later:

```bash
docker image load \
  -i nginx-alpine.tar
```

This is useful when:

- Moving images to offline systems
    
- Creating local backups
    
- Transferring images without a registry
    
- Working in restricted environments
    

---

# 41. `docker save` versus `docker export`

These commands are often confused.

## `docker image save`

Works with images:

```bash
docker image save -o image.tar nginx:alpine
```

It preserves:

- Image layers
    
- Tags
    
- Metadata
    
- History
    

## `docker container export`

Works with a container filesystem:

```bash
docker container export \
  -o container-filesystem.tar \
  container-name
```

It exports a flattened filesystem snapshot.

It does not preserve full image history and normal image metadata.

For transferring reusable images, prefer:

```bash
docker image save
docker image load
```

---

# 42. Pull policies and local image reuse

When you run:

```bash
docker run nginx:1.28
```

Docker typically checks whether the image exists locally.

If it is missing, Docker pulls it.

You can control pull behavior.

## Always check the registry

```bash
docker run --pull=always nginx:1.28
```

## Pull only if missing

```bash
docker run --pull=missing nginx:1.28
```

## Never pull

```bash
docker run --pull=never nginx:1.28
```

With `never`, the command fails if the image is not already local.

This can be useful in offline or controlled environments.

---

# 43. Image trust and source selection

Do not blindly run images from unknown publishers.

Before using an image, evaluate:

- Who publishes it?
    
- Is it an official image?
    
- Is it actively maintained?
    
- Are versions documented?
    
- Is the source Dockerfile available?
    
- Is the image scanned?
    
- Does it run as root?
    
- How large is it?
    
- What software does it contain?
    
- Are there known vulnerabilities?
    
- Does the image have a clear update policy?
    

Prefer:

- Official images
    
- Verified publishers
    
- Your organization’s approved registry
    
- Images built from reviewed Dockerfiles
    

Avoid random images with unclear ownership.

---

# 44. Official images

Official images are maintained under established publishing processes.

Common examples include:

```text
nginx
postgres
redis
python
php
ubuntu
debian
alpine
eclipse-mosquitto
```

However, “official” does not mean:

- No vulnerabilities
    
- Perfect configuration
    
- Suitable for every use
    
- Automatically secure
    
- No maintenance required
    

You still need to select an appropriate version and configure it correctly.

---

# 45. Never store secrets in image layers

Suppose a Dockerfile contains:

```dockerfile
COPY password.txt /app/password.txt
```

Later, another build step removes it:

```dockerfile
RUN rm /app/password.txt
```

The secret may still exist in an earlier image layer.

Similarly, avoid:

```dockerfile
ENV DATABASE_PASSWORD=secret
```

or:

```dockerfile
ARG API_TOKEN=secret
```

for sensitive production values.

Image layers and metadata may be inspected.

Secrets should be provided securely at runtime or during builds using dedicated secret mechanisms.

---

# 46. Practical comparison: Ubuntu and Alpine

Pull both:

```bash
docker pull ubuntu:24.04
docker pull alpine:latest
```

Compare sizes:

```bash
docker image ls ubuntu alpine
```

Run Ubuntu:

```bash
docker run --rm -it ubuntu:24.04 bash
```

Inside:

```bash
cat /etc/os-release
which bash
which apt
exit
```

Run Alpine:

```bash
docker run --rm -it alpine sh
```

Inside:

```sh
cat /etc/os-release
which sh
which apk
which bash
exit
```

You will likely observe:

- Ubuntu uses `apt`
    
- Alpine uses `apk`
    
- Ubuntu includes Bash
    
- Alpine usually provides BusyBox `sh`
    
- Alpine is much smaller
    
- Ubuntu is often easier for familiar troubleshooting
    

---

# 47. Practical comparison: Nginx variants

Pull:

```bash
docker pull nginx:latest
docker pull nginx:alpine
```

Compare:

```bash
docker image ls nginx
```

Inspect base operating systems:

```bash
docker run --rm nginx:latest \
  cat /etc/os-release
```

Then:

```bash
docker run --rm nginx:alpine \
  cat /etc/os-release
```

Inspect commands:

```bash
docker image inspect nginx:latest \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'
```

```bash
docker image inspect nginx:alpine \
  --format 'Entrypoint={{json .Config.Entrypoint}} Cmd={{json .Config.Cmd}}'
```

Both run Nginx, but their supporting filesystem and libraries differ.

---

# 48. Practical laboratory

## Exercise 1 — Pull explicit versions

Run:

```bash
docker pull alpine:latest
docker pull ubuntu:24.04
docker pull nginx:alpine
```

List them:

```bash
docker image ls
```

Identify:

- Repository
    
- Tag
    
- Image ID
    
- Size
    

---

## Exercise 2 — Inspect image configuration

Inspect Nginx:

```bash
docker image inspect nginx:alpine
```

Then extract selected fields:

```bash
docker image inspect nginx:alpine \
  --format 'OS={{.Os}} Architecture={{.Architecture}}'
```

```bash
docker image inspect nginx:alpine \
  --format 'Entrypoint={{json .Config.Entrypoint}}'
```

```bash
docker image inspect nginx:alpine \
  --format 'Cmd={{json .Config.Cmd}}'
```

```bash
docker image inspect nginx:alpine \
  --format 'Ports={{json .Config.ExposedPorts}}'
```

```bash
docker image inspect nginx:alpine \
  --format 'User={{.Config.User}}'
```

---

## Exercise 3 — Inspect image history

Run:

```bash
docker image history nginx:alpine
```

Then:

```bash
docker image history --no-trunc nginx:alpine
```

Look for:

- Large layers
    
- Copied files
    
- Installed packages
    
- Startup configuration
    

---

## Exercise 4 — Compare base images

Run:

```bash
docker run --rm ubuntu:24.04 \
  cat /etc/os-release
```

Then:

```bash
docker run --rm alpine \
  cat /etc/os-release
```

Compare package managers:

```bash
docker run --rm ubuntu:24.04 \
  sh -c 'command -v apt || true'
```

```bash
docker run --rm alpine \
  sh -c 'command -v apk || true'
```

---

## Exercise 5 — Create another tag

Run:

```bash
docker tag nginx:alpine george-nginx:1.0
```

Check:

```bash
docker image ls
```

Confirm both tags have the same image ID.

Remove the new tag:

```bash
docker image rm george-nginx:1.0
```

Confirm `nginx:alpine` remains.

---

## Exercise 6 — Understand image dependencies

Create a container:

```bash
docker create \
  --name image-dependency-test \
  alpine \
  echo hello
```

Try to remove the image:

```bash
docker image rm alpine
```

Observe the conflict.

Remove the container:

```bash
docker rm image-dependency-test
```

Then retry:

```bash
docker image rm alpine
```

Pull it again for later lessons:

```bash
docker pull alpine
```

---

## Exercise 7 — Inspect digests

Run:

```bash
docker image ls --digests
```

Then:

```bash
docker image inspect nginx:alpine \
  --format '{{json .RepoDigests}}'
```

Explain the difference:

```text
Tag → human-readable reference
Digest → content-specific reference
```

---

## Exercise 8 — Save and load an image

Create a working directory:

```bash
mkdir -p ~/docker-course/day4
cd ~/docker-course/day4
```

Save Alpine:

```bash
docker image save \
  -o alpine-image.tar \
  alpine:latest
```

Inspect the file:

```bash
ls -lh alpine-image.tar
```

Remove the local image, provided no container uses it:

```bash
docker image rm alpine:latest
```

Load it:

```bash
docker image load \
  -i alpine-image.tar
```

Confirm:

```bash
docker image ls alpine
```

---

## Exercise 9 — Check disk usage

Run:

```bash
docker system df
```

Then:

```bash
docker system df -v
```

Identify:

- Total image size
    
- Shared layers
    
- Reclaimable images
    
- Build cache
    

---

## Exercise 10 — Clean dangling images

List dangling images:

```bash
docker image ls \
  --filter dangling=true
```

If any exist:

```bash
docker image prune
```

Do not use `docker image prune -a` casually.

---

# 49. Troubleshooting common image problems

## Problem: Docker cannot find an image locally

Example:

```text
Unable to find image locally
```

This is often not an error.

Docker will attempt to pull the image.

If pulling fails, check:

- Image name
    
- Tag spelling
    
- Internet access
    
- Registry authentication
    
- Proxy configuration
    
- Registry availability
    

---

## Problem: Manifest unknown

Example:

```text
manifest unknown
```

This usually means:

- The tag does not exist
    
- The repository name is wrong
    
- The registry path is wrong
    

Check the exact image reference.

For example:

```bash
docker pull python:3.13-slim
```

may exist, while a mistyped variant may not.

---

## Problem: Image platform does not match host

You may see a warning about:

```text
requested image platform does not match detected host platform
```

This usually means:

- AMD64 image on ARM64 host
    
- ARM64 image on AMD64 host
    
- Wrong platform requested explicitly
    

Use the correct image architecture or a multi-platform tag.

---

## Problem: Image is in use by a container

Check:

```bash
docker ps -a --filter ancestor=IMAGE
```

Remove or replace the dependent containers first.

---

## Problem: Disk usage is growing

Check:

```bash
docker system df -v
```

Possible causes:

- Old images
    
- Dangling images
    
- Many stopped containers
    
- Large build cache
    
- Unused volumes
    

Clean selectively rather than using aggressive global cleanup without inspection.

---

## Problem: Pull access denied

Possible causes:

- Private repository
    
- Wrong repository name
    
- Not authenticated
    
- Missing permissions
    
- Registry URL omitted
    

Authenticate where appropriate:

```bash
docker login registry.example.com
```

Never paste passwords into scripts or command history carelessly.

---

# 50. Day 4 command reference

```bash
# Download an image
docker pull IMAGE:TAG

# List local images
docker image ls

# Include digests
docker image ls --digests

# Inspect image metadata
docker image inspect IMAGE

# Inspect build history
docker image history IMAGE

# Show full history commands
docker image history --no-trunc IMAGE

# Add another tag
docker tag SOURCE_IMAGE TARGET_IMAGE

# Remove a tag or image
docker image rm IMAGE

# Force removal
docker image rm -f IMAGE

# List dangling images
docker image ls --filter dangling=true

# Remove dangling images
docker image prune

# Remove all unused images
docker image prune -a

# Inspect Docker disk use
docker system df

# Detailed disk use
docker system df -v

# Save an image archive
docker image save -o image.tar IMAGE

# Load an image archive
docker image load -i image.tar

# Inspect a multi-platform image
docker buildx imagetools inspect IMAGE

# Find containers using an image
docker ps -a --filter ancestor=IMAGE
```

---

# 51. Knowledge check

## Question 1

What is the difference between an image and a container?

**Answer:**

An image is a read-only reusable template. A container is a runtime instance created from that image.

---

## Question 2

What does this reference mean?

```text
python:3.13-slim
```

**Answer:**

`python` is the repository, and `3.13-slim` is the tag.

---

## Question 3

What tag does Docker usually assume when no tag is specified?

**Answer:**

`latest`.

---

## Question 4

Does `latest` guarantee the newest software version?

**Answer:**

No. It is only a tag chosen and maintained by the image publisher.

---

## Question 5

What is the difference between a tag and a digest?

**Answer:**

A tag is a movable, human-readable reference. A digest identifies specific image content.

---

## Question 6

Does `docker tag` copy the complete image?

**Answer:**

No. It creates another reference to the same underlying image content.

---

## Question 7

Why can related images consume less disk space than their displayed sizes suggest?

**Answer:**

They may share common read-only layers.

---

## Question 8

Why might Docker refuse to remove an image?

**Answer:**

A running or stopped container may still reference it.

---

## Question 9

What is a dangling image?

**Answer:**

An image without a repository name or tag, often left after rebuilding or retagging.

---

## Question 10

What is the difference between `docker image save` and `docker container export`?

**Answer:**

`docker image save` preserves image layers, tags, and metadata. `docker container export` creates a flattened archive of a container’s filesystem.

---

# 52. Day 4 completion challenge

Complete this without copying the earlier commands:

1. Pull `ubuntu:24.04`.
    
2. Pull `nginx:alpine`.
    
3. List both images with their digests.
    
4. Inspect the architecture of each image.
    
5. Inspect the default command of the Nginx image.
    
6. Inspect its declared ports.
    
7. Display its image history.
    
8. Create a new local tag named `training-nginx:1.0`.
    
9. Confirm the new tag shares the same image ID as `nginx:alpine`.
    
10. Create a container from `training-nginx:1.0`.
    
11. Stop the container.
    
12. Attempt to remove the image tag.
    
13. Explain why Docker may refuse.
    
14. Remove the container.
    
15. Remove the custom tag.
    
16. Confirm the original `nginx:alpine` tag still exists.
    
17. Save the Nginx image to a tar archive.
    
18. Inspect Docker disk usage.
    
19. List dangling images.
    
20. Remove only dangling images.
    

The central Day 4 model is:

```text
Registry
   ↓ docker pull
Repository and tag
   ↓ resolve to
Image content and layers
   ↓ docker run
Container
```

The most important production lesson is:

```text
A tag is convenient.
A digest is exact.
An image should be reproducible.
A container should be replaceable.
```

[[30 days Docker]]