

Until now, you built and ran images mainly on one Docker host:

```text
Source code
    ↓
docker build
    ↓
Local Docker image
    ↓
docker run
```

That works for development, but a real deployment usually involves several machines:

```text
Developer workstation
CI build server
Testing server
Production server
Other team members
```

These systems need a reliable way to exchange Docker images.

That is the purpose of a **container registry**.

A registry stores and distributes container images. A repository is a collection of related images inside a registry, normally differentiated by tags such as `1.0.0`, `1.1.0`, or `stable`. ([Docker Documentation](https://docs.docker.com/get-started/docker-concepts/the-basics/what-is-a-registry/?utm_source=chatgpt.com "What is a registry?"))

The central Day 18 lesson is:

> Build an image once, give it an unambiguous repository name and version tag, push it to a registry, and deploy that exact image rather than rebuilding separately on every server.

---

# 1. Day 18 objectives

By the end of today, you should understand:

- What a registry is
    
- Registry versus repository
    
- Image names and references
    
- Image tags
    
- Image digests
    
- How `docker tag` works
    
- How to authenticate using `docker login`
    
- How to push and pull images
    
- Why `latest` is not a release strategy
    
- How to use semantic version tags
    
- How to use immutable digests
    
- How to deploy from Docker Hub
    
- How to operate a local private registry
    
- Why production registries should use TLS and authentication
    
- How image layers are uploaded efficiently
    
- How to remove local images and prove registry-based recovery
    
- How to use registries with Docker Compose
    
- How to design a practical release workflow
    
- How to avoid exposing registry credentials
    
- How to troubleshoot push and pull failures
    

---

# 2. What is a Docker registry?

A registry is a server that stores and distributes container images.

Examples include:

```text
Docker Hub
GitHub Container Registry
GitLab Container Registry
Amazon ECR
Azure Container Registry
Google Artifact Registry
Harbor
Self-hosted OCI registry
```

Docker Hub is Docker’s public registry service and hosts official, verified-publisher, and community images. ([Docker Documentation](https://docs.docker.com/reference/glossary/?utm_source=chatgpt.com "Glossary | Docker Docs"))

You have already used registries even if you did not explicitly think about them.

When you ran:

```bash
docker pull nginx:alpine
```

Docker downloaded the image from a registry.

When you ran:

```bash
docker run postgres:17
```

Docker checked whether the image existed locally and pulled it when necessary.

---

# 3. Registry, repository, image, and tag

Consider this image reference:

```text
docker.io/georgecalin/device-api:1.0.0
```

Break it down:

```text
docker.io
└── Registry

georgecalin/device-api
└── Repository

1.0.0
└── Tag
```

The repository may contain several related image versions:

```text
georgecalin/device-api:1.0.0
georgecalin/device-api:1.1.0
georgecalin/device-api:1.1.1
georgecalin/device-api:stable
```

These are not necessarily four complete independent copies.

They are image references that may share many of the same layers.

---

# 4. Full image-reference format

A general image reference looks like:

```text
REGISTRY/NAMESPACE/REPOSITORY:TAG
```

Example:

```text
registry.example.com/embedded/device-monitor:2.4.1
```

Breakdown:

```text
Registry:
registry.example.com

Namespace or organization:
embedded

Repository:
device-monitor

Tag:
2.4.1
```

A registry with a nonstandard port may look like:

```text
registry.example.com:5000/embedded/device-monitor:2.4.1
```

---

# 5. Docker Hub shorthand

When you write:

```text
nginx:alpine
```

Docker interprets this approximately as:

```text
docker.io/library/nginx:alpine
```

Where:

```text
docker.io
→ Docker Hub registry

library
→ official-image namespace

nginx
→ repository

alpine
→ tag
```

For your own Docker Hub repository:

```text
georgecalin/device-api:1.0.0
```

Docker interprets the registry as Docker Hub unless another registry hostname is provided.

---

# 6. What is an image tag?

A tag is a human-readable label pointing to image content.

Examples:

```text
1.0.0
1.1.0
stable
production
development
latest
```

Tags make image references understandable:

```bash
docker pull georgecalin/device-api:1.0.0
```

Without an explicit tag, Docker normally uses:

```text
latest
```

Therefore:

```bash
docker pull georgecalin/device-api
```

is interpreted as:

```bash
docker pull georgecalin/device-api:latest
```

However, `latest` does not mean:

- Newest by creation date
    
- Highest semantic version
    
- Most secure version
    
- Current production version
    

It is simply an ordinary tag named `latest`.

---

# 7. Why `latest` is dangerous

Suppose today:

```text
device-api:latest
→ image A
```

Tomorrow, someone pushes a new image:

```text
device-api:latest
→ image B
```

Your deployment configuration still says:

```yaml
image: device-api:latest
```

but the meaning has changed.

This creates ambiguity:

```text
Which source revision was deployed?
Which dependencies were included?
Which image was tested?
Can we reproduce the old deployment?
```

For releases, prefer immutable version tags:

```text
device-api:1.0.0
device-api:1.0.1
device-api:1.1.0
```

Treat moving tags such as `latest` or `stable` as convenience pointers, not as the only deployment identity.

---

# 8. Semantic version tags

A useful version format is:

```text
MAJOR.MINOR.PATCH
```

Example:

```text
2.4.1
```

Interpretation:

```text
2
→ Major version

4
→ Minor version

1
→ Patch version
```

A practical image-tagging policy might create several tags for one release:

```text
device-api:2.4.1
device-api:2.4
device-api:2
device-api:stable
```

All four tags may initially point to the same image.

Later:

```text
2.4.1
```

should remain fixed.

But:

```text
2.4
2
stable
```

may move to newer compatible versions.

For the clearest production deployment, use:

```text
2.4.1
```

or the exact image digest.

---

# 9. Inspect your current images

Run:

```bash
docker image ls
```

Filter:

```bash
docker image ls \
  day15-device-api
```

You may see:

```text
REPOSITORY          TAG       IMAGE ID
day15-device-api    2.0.0     abc123...
```

Inspect the full image ID:

```bash
docker image inspect \
  day15-device-api:2.0.0 \
  --format '{{.Id}}'
```

An image ID is content-addressed locally.

Example:

```text
sha256:abc123...
```

---

# 10. What `docker tag` actually does

Suppose you have:

```text
day15-device-api:2.0.0
```

Create another reference:

```bash
docker tag \
  day15-device-api:2.0.0 \
  georgecalin/device-api:2.0.0
```

List:

```bash
docker image ls \
  | grep device-api
```

You should see both names.

Inspect their IDs:

```bash
docker image inspect \
  day15-device-api:2.0.0 \
  --format '{{.Id}}'
```

```bash
docker image inspect \
  georgecalin/device-api:2.0.0 \
  --format '{{.Id}}'
```

They should be the same.

`docker tag` does not normally duplicate all image data.

It creates another reference to the same image.

Conceptually:

```text
Image content: sha256:ABC

References:
day15-device-api:2.0.0
georgecalin/device-api:2.0.0
```

---

# 11. Tag during build

Instead of building and tagging separately:

```bash
docker build \
  -t day15-device-api:2.0.0 \
  .
```

then:

```bash
docker tag \
  day15-device-api:2.0.0 \
  georgecalin/device-api:2.0.0
```

you can build directly with the registry-compatible name:

```bash
docker build \
  -t georgecalin/device-api:2.0.0 \
  .
```

You can assign several tags during one build:

```bash
docker build \
  -t georgecalin/device-api:2.0.0 \
  -t georgecalin/device-api:2.0 \
  -t georgecalin/device-api:2 \
  .
```

All tags point to the resulting image.

---

# 12. Docker Hub repository preparation

To push to Docker Hub, you need:

- A Docker Hub account
    
- A repository name
    
- Authentication
    
- An image tagged under your Docker Hub namespace
    

Docker’s official build-and-push workflow uses the user or organization namespace in the image name and requires authentication before pushing. ([Docker Documentation](https://docs.docker.com/get-started/introduction/build-and-push-first-image/?utm_source=chatgpt.com "Build and push your first image"))

Example namespace:

```text
georgecalin
```

Repository:

```text
device-api
```

Full Docker Hub repository:

```text
georgecalin/device-api
```

Depending on Docker Hub settings, you may create the repository through the Docker Hub interface before pushing.

---

# 13. Authenticate using `docker login`

Run:

```bash
docker login
```

Current Docker CLI versions can use a device-code browser flow for Docker Hub by default. Authentication can also use a username with a password or access token. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/login/?utm_source=chatgpt.com "docker login"))

To specify your username:

```bash
docker login \
  --username YOUR_DOCKER_USERNAME
```

Prefer an access token over typing your main account password into automation.

For another registry:

```bash
docker login registry.example.com
```

For a registry with a custom port:

```bash
docker login registry.example.com:5000
```

---

# 14. Do not put passwords directly in commands

Avoid:

```bash
docker login \
  --username user \
  --password plain-text-password
```

The password may appear in:

- Shell history
    
- Process listings
    
- CI logs
    
- Terminal recordings
    

For noninteractive automation, use:

```bash
printf '%s' "$REGISTRY_TOKEN" \
  | docker login \
      --username "$REGISTRY_USER" \
      --password-stdin
```

This prevents the credential from appearing directly in the command-line arguments.

The token must still be protected in the environment or CI secret system.

---

# 15. Where Docker stores login credentials

Docker client credentials are commonly referenced through:

```text
~/.docker/config.json
```

Inspect carefully:

```bash
cat ~/.docker/config.json
```

Do not share its contents.

Depending on your setup, Docker may use:

- A native credential store
    
- A credential helper
    
- Encoded authentication data in the configuration file
    

Encoded does not mean encrypted.

Use a credential helper where appropriate and protect your user account and home directory.

---

# 16. Push your image

After tagging and logging in:

```bash
docker push \
  georgecalin/device-api:2.0.0
```

`docker image push` uploads an image to Docker Hub or another registry. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/image/push/?utm_source=chatgpt.com "docker image push"))

You should see layers being processed:

```text
layer-a: Pushed
layer-b: Pushed
layer-c: Mounted from another repository
...
2.0.0: digest: sha256:...
```

The exact output varies.

At the end, record the reported digest.

---

# 17. Why images are pushed as layers

Docker images are composed of layers.

Suppose your image contains:

```text
Python base layers
Dependency-installation layer
Application-source layer
Metadata
```

If the registry already has some layers, Docker does not need to upload them again.

For example:

```text
Version 2.0.0:
Base + dependencies + source A

Version 2.0.1:
Base + dependencies + source B
```

Only the changed source-related layers may require new upload.

This is one reason Dockerfile layer ordering matters for build and registry efficiency.

---

# 18. Push several tags

Create related tags:

```bash
docker tag \
  georgecalin/device-api:2.0.0 \
  georgecalin/device-api:2.0
```

```bash
docker tag \
  georgecalin/device-api:2.0.0 \
  georgecalin/device-api:2
```

```bash
docker tag \
  georgecalin/device-api:2.0.0 \
  georgecalin/device-api:stable
```

Push each:

```bash
docker push georgecalin/device-api:2.0
docker push georgecalin/device-api:2
docker push georgecalin/device-api:stable
```

Or push every local tag in that repository:

```bash
docker push \
  --all-tags \
  georgecalin/device-api
```

Be careful: this uploads every local tag for that repository, including tags that may have been intended only for testing.

---

# 19. Verify the pushed image

Inspect local repository digests:

```bash
docker image inspect \
  georgecalin/device-api:2.0.0 \
  --format '{{json .RepoDigests}}'
```

After push, you may see:

```text
georgecalin/device-api@sha256:...
```

You can also inspect the remote manifest:

```bash
docker buildx imagetools inspect \
  georgecalin/device-api:2.0.0
```

This is especially useful for multi-platform images.

---

# 20. Remove the local image

To prove that deployment no longer depends on your local build cache, first remove containers using the image:

```bash
docker ps -a \
  --filter ancestor=georgecalin/device-api:2.0.0
```

Remove any test containers if appropriate.

Then remove the tags:

```bash
docker image rm \
  georgecalin/device-api:2.0.0 \
  georgecalin/device-api:2.0 \
  georgecalin/device-api:2 \
  georgecalin/device-api:stable
```

If another local tag still points to the same image, the underlying layers may remain.

Remove the original local reference if you want a complete pull test:

```bash
docker image rm \
  day15-device-api:2.0.0
```

Check:

```bash
docker image ls \
  | grep device-api
```

---

# 21. Pull the image

Run:

```bash
docker pull \
  georgecalin/device-api:2.0.0
```

Docker pulls image layers from the registry and stores them locally. Registry credentials are used where authentication is required, and Docker communicates with registries using HTTPS unless an insecure registry has been explicitly configured. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/image/pull/?utm_source=chatgpt.com "docker image pull"))

Run:

```bash
docker run --rm \
  georgecalin/device-api:2.0.0 \
  python --version
```

The image now came from the registry rather than from your local Dockerfile build.

---

# 22. Deploy a pulled image with Compose

Production Compose should normally reference a registry image:

```yaml
services:
  api:
    image: georgecalin/device-api:2.0.0
```

It should not need:

```yaml
build:
  context: .
```

on the production server.

A production workflow becomes:

```bash
docker compose pull
```

Then:

```bash
docker compose up -d
```

`docker compose pull` downloads service images without starting containers.

`docker compose up -d` recreates services when required.

---

# 23. Pull only one Compose service

To pull the API:

```bash
docker compose pull api
```

Then update it:

```bash
docker compose up -d \
  --no-deps \
  api
```

`--no-deps` prevents Compose from unnecessarily recreating dependencies such as PostgreSQL.

Check:

```bash
docker compose ps
docker compose logs --tail 50 api
```

Test:

```bash
curl http://127.0.0.1:8080/health
```

---

# 24. Tags are mutable

A registry tag can normally be moved to point to different image content.

Suppose:

```text
device-api:stable
→ digest AAA
```

Later:

```text
device-api:stable
→ digest BBB
```

The old tag reference has changed.

A server that already has `stable` locally might continue using the older local image unless it explicitly pulls again.

Therefore:

```bash
docker compose up -d
```

does not always guarantee that a newer remote image was downloaded.

Use:

```bash
docker compose pull
docker compose up -d
```

or:

```bash
docker compose up -d \
  --pull always
```

when you deliberately want to retrieve the current registry content for a tag.

---

# 25. Image digest

A digest is a cryptographic content identifier, commonly using SHA-256.

Example:

```text
georgecalin/device-api@sha256:abc123...
```

Unlike a mutable tag, a digest refers to exact image content.

Docker registries expose digests so that images can be identified independently of changing tag names. ([Docker Documentation](https://docs.docker.com/dhi/core-concepts/digests/?utm_source=chatgpt.com "Image digests"))

Pull by digest:

```bash
docker pull \
  georgecalin/device-api@sha256:EXACT_DIGEST
```

Run by digest:

```bash
docker run \
  georgecalin/device-api@sha256:EXACT_DIGEST
```

Compose:

```yaml
services:
  api:
    image: georgecalin/device-api@sha256:EXACT_DIGEST
```

---

# 26. Tag versus digest

## Tag

```text
device-api:2.0.0
```

Advantages:

- Human-readable
    
- Easy to communicate
    
- Easy to organize
    

Potential issue:

- Registry may allow the tag to be overwritten
    

## Digest

```text
device-api@sha256:...
```

Advantages:

- Exact content identity
    
- Immutable reference
    
- Prevents unexpected tag movement
    

Disadvantages:

- Harder for humans to read
    
- Must be updated explicitly
    
- Architecture-specific details may require care with manifest indexes
    

A practical approach is:

```text
Release documentation:
2.0.0

Deployment reference:
2.0.0 plus verified digest
```

---

# 27. Inspect digest after pull

Run:

```bash
docker image inspect \
  georgecalin/device-api:2.0.0 \
  --format '{{json .RepoDigests}}'
```

Or:

```bash
docker buildx imagetools inspect \
  georgecalin/device-api:2.0.0
```

Record the digest used for production deployment.

For example:

```text
Release:
2.0.0

Digest:
sha256:abc123...
```

This gives both human readability and exact traceability.

---

# 28. Image ID versus registry digest

These identifiers are related but not always interchangeable.

## Local image ID

```text
sha256:...
```

Identifies local image configuration/content.

Inspect:

```bash
docker image inspect IMAGE \
  --format '{{.Id}}'
```

## Repository digest

```text
repository@sha256:...
```

Identifies the registry-distributed image manifest or manifest index.

Inspect:

```bash
docker image inspect IMAGE \
  --format '{{json .RepoDigests}}'
```

For multi-platform images, the tag may reference a manifest index containing separate platform-specific image manifests.

---

# 29. Multi-platform registry images

A repository tag may support several architectures:

```text
linux/amd64
linux/arm64
```

Inspect:

```bash
docker buildx imagetools inspect \
  python:3.13-slim
```

A multi-platform tag points to a manifest list or image index.

Docker selects the appropriate platform when pulling.

Build and push multiple platforms:

```bash
docker buildx build \
  --platform linux/amd64,linux/arm64 \
  -t georgecalin/device-api:2.0.0 \
  --push \
  .
```

Unlike ordinary `docker build`, a multi-platform build commonly pushes directly to the registry because the classic local image store may not load all resulting platforms together.

---

# 30. Build and push in one command

Using Buildx:

```bash
docker buildx build \
  -t georgecalin/device-api:2.0.0 \
  --push \
  .
```

For one local platform:

```bash
docker buildx build \
  --platform linux/amd64 \
  -t georgecalin/device-api:2.0.0 \
  --push \
  .
```

For two platforms:

```bash
docker buildx build \
  --platform linux/amd64,linux/arm64 \
  -t georgecalin/device-api:2.0.0 \
  --push \
  .
```

This avoids a separate push step.

---

# 31. Use release labels

Your Dockerfile can include traceability metadata:

```dockerfile
ARG APP_VERSION=unknown
ARG VCS_REVISION=unknown
ARG BUILD_DATE=unknown

LABEL org.opencontainers.image.title="Device API"
LABEL org.opencontainers.image.version="${APP_VERSION}"
LABEL org.opencontainers.image.revision="${VCS_REVISION}"
LABEL org.opencontainers.image.created="${BUILD_DATE}"
LABEL org.opencontainers.image.source="internal-git-repository"
```

Build:

```bash
docker build \
  --build-arg APP_VERSION=2.0.0 \
  --build-arg VCS_REVISION="$(git rev-parse HEAD)" \
  --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  -t georgecalin/device-api:2.0.0 \
  .
```

Inspect after pulling on another machine:

```bash
docker image inspect \
  georgecalin/device-api:2.0.0 \
  --format '{{json .Config.Labels}}'
```

The deployment can now be traced to the source revision.

---

# 32. Do not rebuild on the production server

A weak release process is:

```text
Development server:
git pull
docker build

Testing server:
git pull
docker build

Production server:
git pull
docker build
```

Even with the same source revision, builds may differ because:

- Base-image tags changed
    
- Package repositories changed
    
- Dependencies changed
    
- Build arguments differed
    
- Build environment differed
    
- Network downloads differed
    

A stronger process is:

```text
CI or controlled build machine
        ↓
Build once
        ↓
Test exact image
        ↓
Scan exact image
        ↓
Push exact image
        ↓
Pull exact image everywhere
```

The same artifact moves through testing and production.

---

# 33. Recommended release workflow

A practical workflow is:

```text
1. Commit source code.
2. Create a release version.
3. Build a versioned image.
4. Run automated tests.
5. Scan the image.
6. Add source-revision labels.
7. Push the immutable version tag.
8. Record the registry digest.
9. Deploy the exact version or digest.
10. Verify health.
11. Keep the previous release for rollback.
```

Example:

```bash
export VERSION=2.1.0
export IMAGE=georgecalin/device-api
```

Build:

```bash
docker build \
  --pull \
  --build-arg APP_VERSION="$VERSION" \
  --build-arg VCS_REVISION="$(git rev-parse HEAD)" \
  --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  -t "$IMAGE:$VERSION" \
  .
```

Test:

```bash
docker run --rm \
  "$IMAGE:$VERSION" \
  python -c 'import flask, psycopg, gunicorn'
```

Scan:

```bash
docker scout cves \
  --only-severity critical,high \
  "$IMAGE:$VERSION"
```

Push:

```bash
docker push "$IMAGE:$VERSION"
```

---

# 34. Moving convenience tags

After successfully publishing:

```text
2.1.0
```

you may create:

```bash
docker tag \
  "$IMAGE:$VERSION" \
  "$IMAGE:stable"
```

Push:

```bash
docker push "$IMAGE:stable"
```

But production should still record:

```text
Version: 2.1.0
Digest: sha256:...
```

Do not rely only on:

```text
stable
```

for auditability.

---

# 35. Rollback using the registry

Suppose production currently uses:

```text
device-api:2.1.0
```

but a serious problem appears.

Change Compose back to:

```yaml
services:
  api:
    image: georgecalin/device-api:2.0.0
```

Then:

```bash
docker compose pull api
```

```bash
docker compose up -d \
  --no-deps \
  api
```

Verify:

```bash
docker compose ps
docker compose logs --tail 100 api
curl http://127.0.0.1:8080/health
```

A registry makes rollback straightforward because the previous image artifact remains available.

Database schema compatibility still needs consideration.

Rolling back an application image does not automatically roll back database migrations.

---

# 36. Private versus public repositories

## Public repository

Anyone can normally pull the image.

Appropriate for:

- Open-source projects
    
- Public examples
    
- Public base images
    

Risks:

- Application binaries and layers are visible
    
- Packaged configuration may be visible
    
- Accidentally included secrets become exposed
    
- Internal architecture may be disclosed
    

## Private repository

Requires authorization.

Appropriate for:

- Proprietary applications
    
- Internal services
    
- Customer-specific images
    
- Sensitive deployment artifacts
    

Private does not mean secrets may safely be embedded in the image.

Authorized users and systems can still inspect the layers.

---

# 37. Never put secrets in images

Do not copy:

```text
.env
database passwords
TLS private keys
API tokens
SSH keys
registry credentials
```

into an image.

Bad:

```dockerfile
COPY .env /app/.env
```

Bad:

```dockerfile
ENV DB_PASSWORD=secret
```

Bad:

```dockerfile
COPY server.key /etc/application/server.key
```

Pushing the image sends those contents to the registry.

Deleting the current tag may not immediately eliminate every copy from caches, old manifests, other systems, or backups.

Provide secrets at runtime.

Use a strict `.dockerignore`:

```text
.env
*.env
secrets/
*.key
*.pem
id_rsa
credentials.json
```

---

# 38. Log out

Remove stored authentication for Docker Hub:

```bash
docker logout
```

For another registry:

```bash
docker logout registry.example.com
```

Logging out removes the client’s stored credential reference for that registry.

It does not:

- Delete images
    
- Revoke access tokens at the registry
    
- Remove previously pulled images
    
- Stop running containers
    

Revoke compromised or unused tokens through the registry’s account-management system.

---

# 39. Self-host a local registry

For training, start the official registry image:

```bash
docker volume create day18-registry-data
```

Then:

```bash
docker run -d \
  --name day18-registry \
  --restart unless-stopped \
  -p 127.0.0.1:5000:5000 \
  --mount type=volume,source=day18-registry-data,target=/var/lib/registry \
  registry:3
```

Current Docker examples use the `registry:3` image for local-registry testing. ([Docker Documentation](https://docs.docker.com/build/ci/github-actions/local-registry/?utm_source=chatgpt.com "Local registry with GitHub Actions"))

Check:

```bash
docker ps \
  --filter name=day18-registry
```

Test:

```bash
curl http://127.0.0.1:5000/v2/
```

An empty successful response such as:

```text
{}
```

or an HTTP success indicates the registry API is reachable.

---

# 40. Tag for the local registry

A registry name becomes part of the image reference.

Tag:

```bash
docker tag \
  day15-device-api:2.0.0 \
  localhost:5000/device-api:2.0.0
```

Inspect:

```bash
docker image ls \
  localhost:5000/device-api
```

Push:

```bash
docker push \
  localhost:5000/device-api:2.0.0
```

The hostname and port:

```text
localhost:5000
```

tell Docker which registry to contact.

---

# 41. Pull from the local registry

Remove the local registry tag:

```bash
docker image rm \
  localhost:5000/device-api:2.0.0
```

Then pull:

```bash
docker pull \
  localhost:5000/device-api:2.0.0
```

Run:

```bash
docker run --rm \
  localhost:5000/device-api:2.0.0 \
  python --version
```

The image was recovered from your local registry.

---

# 42. Inspect registry persistence

Remove the registry container:

```bash
docker rm -f day18-registry
```

The volume remains:

```bash
docker volume ls \
  --filter name=day18-registry-data
```

Recreate:

```bash
docker run -d \
  --name day18-registry \
  -p 127.0.0.1:5000:5000 \
  --mount type=volume,source=day18-registry-data,target=/var/lib/registry \
  registry:3
```

Remove the local image again and pull it.

The registry data survives because it is stored in the named volume.

---

# 43. Local registry security limitation

The simple registry you started uses:

```text
localhost
HTTP
No user authentication
```

This is acceptable only for a controlled training environment.

Docker normally communicates with registries over HTTPS unless the registry has been explicitly allowed as insecure. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/image/pull/?utm_source=chatgpt.com "docker image pull"))

A production registry should normally include:

- TLS
    
- Authentication
    
- Authorization
    
- Persistent storage
    
- Backup
    
- Log monitoring
    
- Storage limits
    
- Vulnerability scanning
    
- Access-token management
    
- Network restrictions
    
- High availability where required
    

Do not expose the training registry directly to the internet.

---

# 44. Accessing the registry from another machine

This image reference:

```text
localhost:5000/device-api:2.0.0
```

works only for clients on the same host.

From another machine, `localhost` means that other machine itself.

Use the registry server’s hostname:

```text
registry.lan.example:5000/device-api:2.0.0
```

or IP:

```text
192.168.1.50:5000/device-api:2.0.0
```

But if the registry uses plain HTTP, remote Docker clients will normally reject it unless configured as an insecure registry.

For proper deployment, configure TLS with a trusted or explicitly installed certificate.

---

# 45. Do not casually configure insecure registries

An insecure registry may use:

- Plain HTTP
    
- Untrusted TLS
    

Allowing it weakens transport security.

Risks include:

- Credential interception
    
- Image tampering in transit
    
- Man-in-the-middle attacks
    
- Pulling unintended content
    

Use insecure registries only for isolated labs.

For production, configure:

```text
HTTPS
Trusted certificates
Authentication
Network controls
```

---

# 46. Registry Compose configuration

Create:

```yaml
services:
  registry:
    image: registry:3

    ports:
      - "127.0.0.1:5000:5000"

    volumes:
      - registry-data:/var/lib/registry

    restart: unless-stopped

    logging:
      driver: local
      options:
        max-size: "10m"
        max-file: "3"

volumes:
  registry-data:
```

Start:

```bash
docker compose up -d
```

Check:

```bash
docker compose ps
docker compose logs registry
```

Stop while preserving data:

```bash
docker compose down
```

Delete data deliberately:

```bash
docker compose down -v
```

---

# 47. Registry catalog for laboratory use

Some registry API endpoints can list repositories.

For your local training registry:

```bash
curl \
  http://127.0.0.1:5000/v2/_catalog
```

You may see:

```json
{
  "repositories": [
    "device-api"
  ]
}
```

List tags:

```bash
curl \
  http://127.0.0.1:5000/v2/device-api/tags/list
```

Example:

```json
{
  "name": "device-api",
  "tags": [
    "2.0.0"
  ]
}
```

Do not assume unrestricted catalog listing is enabled or appropriate in all production registries.

---

# 48. Image deletion in a registry

Deleting a local image tag:

```bash
docker image rm IMAGE
```

deletes only the local Docker reference.

It does not delete the image from the registry.

Registry deletion depends on:

- Registry implementation
    
- Repository permissions
    
- Retention rules
    
- Garbage collection
    
- User interface or API support
    

Do not manually delete files from a registry’s storage volume.

Use supported registry-management mechanisms.

---

# 49. Registry storage requires backup

A self-hosted registry’s storage contains critical artifacts.

If the registry server or volume is lost, you may lose:

- Release images
    
- Historical versions
    
- Rollback artifacts
    
- Build provenance
    
- Cached base images
    

A registry is not automatically backed up merely because it uses a volume.

Plan:

```text
Persistent storage
Backups
Restore tests
Retention policy
Replication where needed
Access controls
Monitoring
```

If source code and Dockerfiles exist, images may be rebuilt, but a rebuild may not be byte-for-byte identical unless the build inputs were fully pinned and preserved.

---

# 50. Registry authentication concept

When a registry requires authorization, a typical interaction is:

```text
1. Docker client requests push or pull.
2. Registry responds that authorization is required.
3. Client requests an authorization token.
4. Authorization service validates identity and access.
5. Client retries using the token.
```

The Docker Registry v2 authentication flow uses bearer tokens for authorized registry operations. ([Docker Documentation](https://docs.docker.com/reference/api/registry/auth/?utm_source=chatgpt.com "Registry authentication"))

You do not normally implement this protocol manually.

The Docker CLI and registry perform it for you.

---

# 51. Repository permissions

A registry may grant different permissions:

```text
Pull
Push
Delete
Administer
Manage tokens
Manage retention
```

Apply least privilege.

Examples:

```text
Production server:
Pull only

CI build job:
Push to application repository

Developer:
Pull development images
Possibly push to development repository

Administrator:
Manage repository policies
```

A production server should not normally have permission to overwrite release images.

---

# 52. Use access tokens in automation

For CI:

```bash
printf '%s' "$REGISTRY_TOKEN" \
  | docker login \
      registry.example.com \
      --username "$REGISTRY_USER" \
      --password-stdin
```

Then:

```bash
docker push \
  registry.example.com/team/device-api:"$VERSION"
```

CI secrets should:

- Be stored in the CI secret system
    
- Be masked from logs
    
- Have limited scope
    
- Have expiration where practical
    
- Be rotated
    
- Not be written into images
    
- Not be committed to source control
    

After the job, ephemeral build agents should be destroyed or credentials removed.

---

# 53. GitLab registry naming

Since you use a private GitLab server, a GitLab image reference often follows a pattern such as:

```text
GITLAB_REGISTRY/GROUP/PROJECT/IMAGE:TAG
```

Conceptually:

```text
srvdev.lacon.ro/ro_embedded/playground/poc_mqtt_devicemonitor/dashboard:1.0.0
```

The exact registry hostname may differ from the GitLab web hostname.

Check your GitLab project’s container-registry section for the precise login and image path.

Do not guess the registry address from the Git repository URL.

A typical workflow is:

```bash
docker login REGISTRY_HOST
```

```bash
docker build \
  -t REGISTRY_HOST/GROUP/PROJECT/device-api:1.0.0 \
  .
```

```bash
docker push \
  REGISTRY_HOST/GROUP/PROJECT/device-api:1.0.0
```

---

# 54. Deploy from a private GitLab registry

On the production server:

```bash
docker login REGISTRY_HOST
```

Then:

```bash
docker pull \
  REGISTRY_HOST/GROUP/PROJECT/device-api:1.0.0
```

Compose:

```yaml
services:
  api:
    image: REGISTRY_HOST/GROUP/PROJECT/device-api:1.0.0
```

Deploy:

```bash
docker compose pull api
```

```bash
docker compose up -d \
  --no-deps \
  api
```

For automation, use a deploy token with read-registry permission rather than a personal developer password.

---

# 55. Development, staging, and production tags

Avoid tags such as:

```text
final
final2
really-final
new
test-new
```

Use a consistent scheme.

Example:

```text
Commit images:
git-a1b2c3d

Release images:
1.0.0
1.1.0
2.0.0

Moving environment pointers:
development
staging
stable
```

A CI build could tag one image as:

```text
device-api:git-a1b2c3d
device-api:2.0.0
```

Then staging and production can deploy the exact release version.

Environment names should not replace version identity.

---

# 56. Commit-based tags

Git commit identifiers make images traceable.

Example:

```bash
export COMMIT_SHA="$(git rev-parse --short=12 HEAD)"
```

Build:

```bash
docker build \
  -t "$IMAGE:git-$COMMIT_SHA" \
  .
```

Push:

```bash
docker push \
  "$IMAGE:git-$COMMIT_SHA"
```

When a problem is reported, you can identify the exact source revision.

A release can receive both tags:

```text
git-a1b2c3d4e5f6
2.0.0
```

Both point to the same image content.

---

# 57. Do not reuse immutable release tags

Once pushed:

```text
device-api:2.0.0
```

do not overwrite it with another build.

If a correction is needed, publish:

```text
device-api:2.0.1
```

Overwriting `2.0.0` destroys confidence:

```text
Two servers both report 2.0.0
but run different image contents
```

Some registries can enforce immutable tags.

Enable tag immutability for release repositories where available.

---

# 58. Image signing and verification

Image signing adds cryptographic evidence about publisher identity and artifact integrity.

Docker documentation currently notes that the older Docker Content Trust approach is being retired for Docker Official Images and recommends planning toward newer signing systems such as Sigstore or Notation. ([Docker Documentation](https://docs.docker.com/docker-hub/repos/manage/trusted-content/official-images/?utm_source=chatgpt.com "Docker Official Images"))

The broader principle remains:

```text
Digest
→ Which exact content?

Signature
→ Who approved or published that content?

Provenance
→ How and from which source was it built?
```

A signed image does not prove that the application has no vulnerabilities.

It proves a relationship between an identity and an artifact, according to the signing policy.

---

# 59. Trusted image sources

When pulling base images, prefer:

- Docker Official Images
    
- Verified publishers
    
- Trusted internal registries
    
- Images whose source and maintenance process you have reviewed
    

Docker Hub distinguishes official, verified, sponsored open-source, and community content. ([Docker Documentation](https://docs.docker.com/docker-hub/image-library/trusted-content/?utm_source=chatgpt.com "Trusted content | Docker Docs"))

Before using an unknown image:

```text
Check publisher
Check repository
Check update history
Check supported tags
Inspect image contents
Scan vulnerabilities
Review Dockerfile if available
Test behavior
```

Do not run unknown images with:

```text
--privileged
host filesystem mounts
Docker socket access
host networking
sensitive secrets
```

---

# 60. Common registry errors

## Error: requested access is denied

Example:

```text
denied: requested access to the resource is denied
```

Likely causes:

- Not logged in
    
- Wrong registry
    
- Wrong repository namespace
    
- No push permission
    
- Repository does not exist
    
- Token scope is insufficient
    

Check:

```bash
docker login REGISTRY
```

Verify the full image name.

---

## Error: repository does not exist

Possible causes:

- Typo in organization or project
    
- Repository not created
    
- Wrong GitLab group path
    
- Wrong registry hostname
    
- Private repository without authentication
    

Verify the exact registry-provided image path.

---

## Error: unauthorized

Check:

- Username
    
- Token
    
- Token expiration
    
- Token scope
    
- Authentication target
    
- Credential helper
    

You may have logged into:

```text
docker.io
```

while pushing to:

```text
registry.example.com
```

Registry authentication is hostname-specific.

---

## Error: connection refused

Example:

```text
dial tcp ... connection refused
```

Check:

```bash
docker ps
```

```bash
ss -lntp
```

```bash
curl http://REGISTRY:PORT/v2/
```

Possible causes:

- Registry container stopped
    
- Wrong port
    
- Firewall
    
- Wrong address
    
- Registry listening only on loopback
    
- DNS failure
    

---

## Error: HTTP response to HTTPS client

This typically means Docker expected HTTPS but the registry provides plain HTTP.

Do not immediately weaken production security.

For a lab, configure the client deliberately as an insecure registry.

For production, configure TLS.

---

## Error: certificate signed by unknown authority

The registry uses TLS, but the Docker host does not trust the issuing certificate.

Correct solutions include:

- Use a publicly trusted certificate
    
- Install your internal certificate authority properly
    
- Configure Docker’s registry certificate trust
    

Do not disable certificate verification for production.

---

## Error: no space left on device

Check:

```bash
df -h
docker system df -v
```

The problem may exist on:

- Docker client
    
- Registry host
    
- Registry storage volume
    
- Temporary build storage
    

Use retention and cleanup policies carefully.

---

# 61. Troubleshooting sequence for push failures

Use this order:

```text
1. Is the image present locally?
2. Is its repository name correct?
3. Is the registry hostname correct?
4. Can the registry API be reached?
5. Are you authenticated?
6. Does your account have push permission?
7. Is TLS trusted?
8. Is storage available?
9. Are registry logs reporting an error?
```

Commands:

```bash
docker image inspect IMAGE
```

```bash
docker login REGISTRY
```

```bash
curl -v https://REGISTRY/v2/
```

```bash
docker push IMAGE
```

For your local registry:

```bash
docker logs day18-registry
```

---

# 62. Troubleshooting sequence for pull failures

Check:

```text
1. Is the image reference correct?
2. Does the tag exist?
3. Is authentication required?
4. Does the account have pull permission?
5. Can the registry hostname resolve?
6. Is the registry reachable?
7. Is TLS trusted?
8. Is the requested platform available?
9. Is there enough local disk space?
```

Inspect remote platforms:

```bash
docker buildx imagetools inspect IMAGE
```

Force a platform where appropriate:

```bash
docker pull \
  --platform linux/amd64 \
  IMAGE
```

Do this only when the image can actually run through the target platform or emulation environment.

---

# 63. Practical release configuration for your MQTT platform

Possible repositories:

```text
registry.example.com/mqtt-platform/dashboard
registry.example.com/mqtt-platform/mqtt-daemon
registry.example.com/mqtt-platform/mqtt-consumer
```

Release tags:

```text
dashboard:1.0.0
mqtt-daemon:1.0.0
mqtt-consumer:1.0.0
```

Compose:

```yaml
services:
  dashboard:
    image: registry.example.com/mqtt-platform/dashboard:1.0.0

  mqtt-daemon:
    image: registry.example.com/mqtt-platform/mqtt-daemon:1.0.0

  consumer:
    image: registry.example.com/mqtt-platform/mqtt-consumer:1.0.0

  database:
    image: postgres:17
```

Your own services use controlled internal release images.

Third-party infrastructure uses approved upstream or mirrored images.

---

# 64. Record the deployed versions

Create a release manifest:

```text
Release: mqtt-platform-2026.06.09

dashboard:
  tag: 1.0.0
  digest: sha256:AAA
  source: git commit 111

mqtt-daemon:
  tag: 1.0.0
  digest: sha256:BBB
  source: git commit 222

consumer:
  tag: 1.0.0
  digest: sha256:CCC
  source: git commit 333

postgres:
  tag: 17
  digest: sha256:DDD
```

This supports:

- Reproducibility
    
- Auditing
    
- Rollback
    
- Incident response
    
- Vulnerability assessment
    

Do not record only:

```text
dashboard:latest
```

---

# 65. Day 18 practical laboratory

## Exercise 1 — Image naming

Take your Day 15 API image and identify:

- Registry
    
- Namespace
    
- Repository
    
- Tag
    

Retag it under your Docker Hub namespace or a training namespace.

---

## Exercise 2 — Multiple tags

Create:

```text
2.0.0
2.0
2
stable
```

Confirm all local tags initially share one image ID.

---

## Exercise 3 — Docker login

Authenticate to your selected registry.

Inspect the Docker client configuration without exposing credentials.

Log out and log in again.

---

## Exercise 4 — Push

Push `2.0.0`.

Push the remaining tags.

Record the digest returned by the registry.

---

## Exercise 5 — Pull recovery

Remove every local reference to the image.

Pull `2.0.0`.

Run it.

Confirm the image can be deployed without source code or a local Dockerfile.

---

## Exercise 6 — Tag movement

Push one image as `stable`.

Build a changed image.

Move `stable` to the changed image.

Pull it on another environment.

Explain why `stable` alone is not an immutable deployment reference.

---

## Exercise 7 — Digest deployment

Inspect the repository digest.

Pull and run using:

```text
repository@sha256:...
```

Confirm it selects exact content.

---

## Exercise 8 — Compose pull

Modify production Compose to reference the registry image.

Run:

```bash
docker compose pull
docker compose up -d
```

Confirm no application build occurs on the deployment host.

---

## Exercise 9 — Local registry

Run `registry:3` on localhost port 5000.

Back it with a named volume.

Push an image.

Remove it locally.

Pull it again.

---

## Exercise 10 — Registry persistence

Remove and recreate the registry container using the same volume.

Confirm the image remains pullable.

---

# 66. Day 18 command reference

```bash
# Add another tag
docker tag \
  SOURCE_IMAGE:TAG \
  REGISTRY/NAMESPACE/REPOSITORY:TAG

# Authenticate to Docker Hub
docker login

# Authenticate to another registry
docker login REGISTRY_HOST

# Login safely in automation
printf '%s' "$REGISTRY_TOKEN" \
  | docker login \
      REGISTRY_HOST \
      --username "$REGISTRY_USER" \
      --password-stdin

# Push one tag
docker push \
  REGISTRY/NAMESPACE/REPOSITORY:TAG

# Push all local repository tags
docker push \
  --all-tags \
  REGISTRY/NAMESPACE/REPOSITORY

# Pull one tag
docker pull \
  REGISTRY/NAMESPACE/REPOSITORY:TAG

# Pull by digest
docker pull \
  REGISTRY/NAMESPACE/REPOSITORY@sha256:DIGEST

# Inspect local image ID
docker image inspect IMAGE \
  --format '{{.Id}}'

# Inspect repository digests
docker image inspect IMAGE \
  --format '{{json .RepoDigests}}'

# Inspect remote image or manifest index
docker buildx imagetools inspect IMAGE

# Pull Compose services
docker compose pull

# Update one Compose service
docker compose up -d \
  --no-deps \
  SERVICE

# Start local registry
docker run -d \
  --name registry \
  -p 127.0.0.1:5000:5000 \
  -v registry-data:/var/lib/registry \
  registry:3

# List local-registry repositories
curl http://127.0.0.1:5000/v2/_catalog

# List tags
curl \
  http://127.0.0.1:5000/v2/REPOSITORY/tags/list

# Logout
docker logout REGISTRY_HOST
```

---

# 67. Knowledge check

## What is a container registry?

A service that stores, manages, and distributes container images.

## What is a repository?

A collection of related images inside a registry, typically differentiated by tags.

## What does `docker tag` do?

It creates another image reference pointing to existing image content.

## Does a tag guarantee immutable content?

No. Tags can normally be moved or overwritten unless the registry enforces immutability.

## What is `latest`?

An ordinary tag named `latest`. It does not automatically mean newest or safest.

## What does `docker push` do?

It uploads image manifests and missing layers to a registry. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/image/push/?utm_source=chatgpt.com "docker image push"))

## What does `docker pull` do?

It downloads the requested image manifest and required layers from a registry. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/image/pull/?utm_source=chatgpt.com "docker image pull"))

## Why are unchanged layers not uploaded repeatedly?

Registries store content-addressed layers and reuse layers that already exist.

## What is a digest?

A cryptographic identifier for exact registry-distributed image content.

## Why deploy by digest?

It prevents a mutable tag from unexpectedly selecting different content.

## Should production servers build application images?

Preferably no. Build and test once, push the artifact, then pull that exact artifact into production.

## Should credentials be passed using `--password`?

No. Prefer `--password-stdin` for noninteractive login.

## Is a private registry safe for secrets embedded in images?

No. Anyone with authorized image access may inspect those contents.

## Why should release tags not be overwritten?

Because the same version label could otherwise represent different code on different machines.

---

# 68. Day 18 completion challenge

Complete this independently:

1. Choose a registry.
    
2. Choose a repository namespace.
    
3. Build your API image.
    
4. Tag it as `1.0.0`.
    
5. Add the source revision as an OCI label.
    
6. Add the build date as an OCI label.
    
7. Add the release version as an OCI label.
    
8. Create tags `1.0`, `1`, and `stable`.
    
9. Confirm they share the same local image ID.
    
10. Authenticate to the registry.
    
11. Push the immutable `1.0.0` tag.
    
12. Push the convenience tags.
    
13. Record the returned digest.
    
14. Remove every local image reference.
    
15. Pull `1.0.0`.
    
16. Run the application.
    
17. Inspect the labels after pulling.
    
18. Pull the same image by digest.
    
19. Explain the difference between tag and digest.
    
20. Configure Compose to use the registry image.
    
21. Remove the Compose `build` section from production.
    
22. Run `docker compose pull`.
    
23. Deploy the pulled image.
    
24. Confirm the deployment host did not rebuild it.
    
25. Build version `1.0.1`.
    
26. Push it without overwriting `1.0.0`.
    
27. Move only `stable` to `1.0.1`.
    
28. Deploy `1.0.1`.
    
29. Roll back to `1.0.0`.
    
30. Confirm the database volume remains unchanged.
    
31. Start a local `registry:3`.
    
32. Add a persistent registry volume.
    
33. Push your MQTT daemon image into it.
    
34. Remove the local daemon image.
    
35. Pull it from the registry.
    
36. Remove and recreate the registry container.
    
37. Confirm the daemon image remains pullable.
    
38. Explain why the training registry is unsuitable for public production use.
    
39. Describe the TLS and authentication controls a production registry needs.
    
40. Create a written release manifest containing tags, digests, source revisions, and deployment date.
    

The central Day 18 model is:

```text
Source code
    ↓
Controlled build
    ↓
Versioned image
    ↓
Tests and scanning
    ↓
Registry push
    ↓
Immutable digest
    ↓
Pull on target server
    ↓
Container deployment
```

The most important operational lesson is:

> Treat a container image as a release artifact. Build it once, test and scan that exact artifact, tag it consistently, push it to a trusted registry, record its digest, and deploy the same image everywhere rather than rebuilding independently on each server.