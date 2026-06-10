#### Automatically Build, Test, Scan, and Publish Images

On Day 18, you manually performed the release workflow:

```text
Build image
    ↓
Test image
    ↓
Tag image
    ↓
Authenticate to registry
    ↓
Push image
    ↓
Pull image on deployment server
```

Today you will automate that work using a **CI/CD pipeline**.

CI/CD means:

```text
Continuous Integration
+
Continuous Delivery or Deployment
```

The central lesson is:

> Every image release should be produced by a repeatable pipeline from committed source code—not by someone manually building an image on a workstation or production server.

You use a private GitLab server, so the main practical example will use:

```text
GitLab repository
GitLab Runner
GitLab CI/CD
GitLab Container Registry
```

A GitHub Actions example is included later so you understand the equivalent workflow.

GitLab pipelines are defined in `.gitlab-ci.yml`, where jobs describe the commands that a runner executes. GitLab also provides predefined registry variables that allow pipeline jobs to authenticate, build, and push images to the project’s container registry. ([GitLab Docs](https://docs.gitlab.com/user/packages/container_registry/build_and_push_images/?utm_source=chatgpt.com "Build and push container images to the container registry"))

---

# 1. Day 19 objectives

By the end of today, you should understand:

- What continuous integration means
    
- What continuous delivery means
    
- What a CI runner is
    
- Why CI builds are preferable to workstation builds
    
- How to create `.gitlab-ci.yml`
    
- Stages, jobs, scripts, rules, artifacts, and services
    
- How to test source code before building an image
    
- How to test the built image before pushing
    
- How to authenticate to the GitLab Container Registry
    
- How to tag images using commit and release identifiers
    
- How to push immutable and convenience tags
    
- Docker-in-Docker
    
- Shell-runner Docker builds
    
- BuildKit in CI
    
- Docker build caching
    
- CI/CD variables and protected variables
    
- Branch pipelines versus tag pipelines
    
- Merge-request validation
    
- Release-image publishing
    
- SBOM and provenance attestations
    
- Deployment jobs
    
- Manual production approval
    
- Rollback-friendly release design
    

---

# 2. Manual releases are difficult to reproduce

A manual workflow might be:

```bash
git pull
docker build -t application:latest .
docker login registry.example.com
docker push application:latest
```

This leaves many questions:

```text
Which Git commit was built?
Were tests run?
Was the Dockerfile validated?
Was the image scanned?
Which build arguments were used?
Was the correct registry used?
Did the developer have uncommitted files?
Was the base image refreshed?
Was the exact same image deployed to testing and production?
```

A pipeline records and automates these steps.

A better model is:

```text
Git commit
    ↓
Automated pipeline
    ├── source tests
    ├── Dockerfile checks
    ├── image build
    ├── image tests
    ├── vulnerability scan
    ├── release tagging
    └── registry push
            ↓
Versioned deployment artifact
```

---

# 3. Continuous Integration

Continuous Integration usually means that code changes are regularly integrated into a shared repository and automatically validated.

A CI pipeline may run after:

- A branch push
    
- A merge request
    
- A commit to the default branch
    
- A version tag
    
- A scheduled event
    
- A manual trigger
    

Typical CI checks include:

```text
Compile code
Run unit tests
Run static analysis
Validate formatting
Validate Dockerfile
Build container image
Run image smoke tests
Scan dependencies
```

The main goal is early detection:

```text
Developer creates defect
        ↓
Pipeline detects defect
        ↓
Defect does not reach release
```

---

# 4. Continuous Delivery versus deployment

## Continuous delivery

The pipeline prepares a deployable release automatically, but production deployment requires approval.

```text
Commit
  ↓
Build
  ↓
Test
  ↓
Push release image
  ↓
Manual production approval
```

## Continuous deployment

Every successful qualifying change is automatically deployed.

```text
Commit
  ↓
Build
  ↓
Test
  ↓
Push
  ↓
Automatic production deployment
```

For your MQTT platform, start with **continuous delivery**.

Production changes should initially require a deliberate manual approval.

---

# 5. What is a GitLab Runner?

GitLab coordinates pipelines, but a **GitLab Runner** executes the jobs.

Conceptually:

```text
GitLab server
    ↓ assigns job
GitLab Runner
    ↓ executes commands
Build environment
```

A runner can use different executors, such as:

- Shell executor
    
- Docker executor
    
- Kubernetes executor
    
- Custom executors
    

With the Docker executor, the CI job itself runs in a container. GitLab documents that a runner must be registered and configured with an executor before it can execute jobs. ([GitLab Docs](https://docs.gitlab.com/ci/docker/using_docker_images/?utm_source=chatgpt.com "Run your CI/CD jobs in Docker containers - GitLab Docs"))

---

# 6. Shell executor versus Docker executor

## Shell executor

The commands run directly on the runner host:

```text
Runner host
├── Git checkout
├── docker build
├── docker push
└── Test commands
```

Advantages:

- Straightforward
    
- Can use the host Docker daemon
    
- Fast when correctly configured
    
- Easy to understand
    

Risks:

- Jobs affect the runner host
    
- Build files and caches remain on the host
    
- Runner user may require Docker-group access
    
- Less isolation between jobs
    
- A malicious CI job may endanger the runner
    

Use a dedicated runner host rather than an important production server.

## Docker executor

Each job runs inside a container:

```text
Runner
  ↓
Job container
  ↓
Commands
```

To build Docker images, the job still needs access to a Docker builder through one of several methods:

- Docker-in-Docker
    
- Host Docker socket
    
- Rootless BuildKit
    
- BuildKit daemon
    
- Kaniko or another builder
    
- Docker Build Cloud
    

Never assume that merely running a job inside a Docker image automatically allows it to build other images.

---

# 7. Prepare the Day 19 project

Copy the previous project:

```bash
mkdir -p ~/docker-course/day19

cp -r \
  ~/docker-course/day14/device-api \
  ~/docker-course/day19/device-api

cd ~/docker-course/day19/device-api
```

The project will eventually contain:

```text
device-api/
├── .gitlab-ci.yml
├── compose.yaml
├── compose.ci.yaml
├── Dockerfile
├── requirements.txt
├── app.py
├── tests/
│   └── test_app.py
└── templates/
    └── index.html
```

---

# 8. Pipeline stages

A practical pipeline will use:

```yaml
stages:
  - validate
  - test
  - build
  - image-test
  - publish
  - deploy
```

The normal flow is:

```text
validate
   ↓
test
   ↓
build
   ↓
image-test
   ↓
publish
   ↓
deploy
```

Jobs within the same stage can normally run in parallel when runners are available.

Later stages normally wait until earlier stages succeed.

---

# 9. Create the first `.gitlab-ci.yml`

Create:

```bash
nano .gitlab-ci.yml
```

Start with:

```yaml
stages:
  - validate
  - test
  - build
  - image-test
  - publish
```

Add a simple validation job:

```yaml
validate-python:
  stage: validate
  image: python:3.13-slim

  script:
    - python --version
    - python -m compileall app.py
```

This job:

1. Starts a `python:3.13-slim` job container.
    
2. Checks Python availability.
    
3. Compiles `app.py` to detect syntax errors.
    

Commit:

```bash
git add .gitlab-ci.yml
git commit -m "Add initial GitLab CI pipeline"
git push
```

GitLab should create a pipeline.

---

# 10. Job structure

A GitLab job commonly contains:

```yaml
job-name:
  stage: test
  image: python:3.13-slim

  before_script:
    - command before main work

  script:
    - main command
    - another command

  after_script:
    - cleanup or diagnostics
```

Important concepts:

```text
job-name
→ Identifier shown in pipeline

stage
→ Execution phase

image
→ Environment used by Docker executor

script
→ Commands that determine success or failure
```

If a `script` command exits non-zero, the job normally fails.

---

# 11. Add application tests

Create a basic test dependency file:

```bash
cat > requirements-test.txt <<'EOF'
pytest==8.4.0
EOF
```

Create the directory:

```bash
mkdir -p tests
```

Your current `app.py` initializes the database during import, making isolated unit testing more difficult.

For now, create a simpler configuration test:

```bash
cat > tests/test_environment.py <<'EOF'
import os

import pytest


def required_environment(name: str) -> str:
    value = os.getenv(name)

    if not value:
        raise RuntimeError(
            f"Required environment variable {name} is missing"
        )

    return value


def test_required_environment_returns_value(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("EXAMPLE_SETTING", "configured")

    assert (
        required_environment("EXAMPLE_SETTING")
        == "configured"
    )


def test_required_environment_rejects_missing_value(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.delenv(
        "EXAMPLE_SETTING",
        raising=False,
    )

    with pytest.raises(RuntimeError):
        required_environment("EXAMPLE_SETTING")
EOF
```

---

# 12. Add the unit-test job

Update `.gitlab-ci.yml`:

```yaml
unit-tests:
  stage: test
  image: python:3.13-slim

  before_script:
    - python -m pip install --no-cache-dir -r requirements-test.txt

  script:
    - pytest -v tests/
```

The pipeline now becomes:

```text
validate-python
       ↓
unit-tests
```

If tests fail, the image should not be published.

---

# 13. Validate the Dockerfile

Modern Docker Build can run Dockerfile build checks:

```bash
docker build --check .
```

Add:

```yaml
dockerfile-check:
  stage: validate
  image: docker:cli

  services:
    - name: docker:dind

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  script:
    - docker version
    - docker build --check .
```

The exact availability of build checks depends on the Docker CLI and BuildKit versions.

Docker’s GitHub Actions integration can also fail builds on Dockerfile-check warnings, illustrating that build validation should be part of CI rather than a manual optional step. ([Docker Documentation](https://docs.docker.com/build/ci/github-actions/checks/?utm_source=chatgpt.com "Validating build configuration with GitHub Actions"))

---

# 14. Docker-in-Docker

The previous job used:

```yaml
image: docker:cli

services:
  - docker:dind
```

This creates:

```text
Job container
    ↓ communicates over TCP
Docker daemon service container
```

The job configures:

```yaml
DOCKER_HOST: tcp://docker:2375
DOCKER_TLS_CERTDIR: ""
```

This tells the Docker CLI to use the Docker-in-Docker daemon.

The runner must normally allow privileged operation for traditional Docker-in-Docker.

That is a security-sensitive configuration.

Use dedicated trusted runners for container builds.

---

# 15. Do not casually mount the host Docker socket

Another pattern is:

```text
/var/run/docker.sock
```

mounted into the CI job container.

This is fast because the job uses the host Docker daemon.

However, the job effectively receives powerful control over the runner host.

A malicious repository pipeline might:

- Start privileged containers
    
- Mount the host filesystem
    
- Read other container environments
    
- Stop runner services
    
- Modify images and volumes
    

Do not expose a shared runner’s Docker socket to untrusted projects.

---

# 16. GitLab registry predefined variables

GitLab commonly provides variables such as:

```text
CI_REGISTRY
CI_REGISTRY_IMAGE
CI_REGISTRY_USER
CI_REGISTRY_PASSWORD
CI_COMMIT_SHA
CI_COMMIT_SHORT_SHA
CI_COMMIT_BRANCH
CI_COMMIT_TAG
CI_DEFAULT_BRANCH
CI_PIPELINE_ID
```

The important registry variables are:

```text
CI_REGISTRY
→ Registry hostname

CI_REGISTRY_IMAGE
→ Project-specific registry image path

CI_REGISTRY_USER
→ Per-job registry user

CI_REGISTRY_PASSWORD
→ Per-job registry password
```

GitLab documents using `CI_REGISTRY_USER` and `CI_REGISTRY_PASSWORD` to log in from CI, with the password passed through standard input. ([GitLab Docs](https://docs.gitlab.com/user/packages/container_registry/authenticate_with_container_registry/?utm_source=chatgpt.com "Authenticate with the container registry - GitLab Docs"))

Login:

```bash
echo "$CI_REGISTRY_PASSWORD" \
  | docker login "$CI_REGISTRY" \
      --username "$CI_REGISTRY_USER" \
      --password-stdin
```

---

# 17. Image-tagging strategy for CI

Do not publish every commit only as:

```text
latest
```

Use traceable tags.

For every commit:

```text
git-<short-commit-sha>
```

Example:

```text
git-a17b29c3
```

For the default branch:

```text
main
```

For a release tag:

```text
1.2.0
```

Potential image names:

```text
$CI_REGISTRY_IMAGE:git-$CI_COMMIT_SHORT_SHA
$CI_REGISTRY_IMAGE:$CI_COMMIT_REF_SLUG
$CI_REGISTRY_IMAGE:$CI_COMMIT_TAG
$CI_REGISTRY_IMAGE:stable
```

An immutable commit tag is especially valuable:

```text
git-a17b29c3
```

It creates a direct link between source and image.

---

# 18. Build an image in CI

Add a build job:

```yaml
build-image:
  stage: build
  image: docker:cli

  services:
    - name: docker:dind

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

    IMAGE_COMMIT:
      value: "$CI_REGISTRY_IMAGE:git-$CI_COMMIT_SHORT_SHA"

  before_script:
    - docker version

  script:
    - >
      docker build
      --pull
      --build-arg APP_VERSION="git-$CI_COMMIT_SHORT_SHA"
      --build-arg VCS_REVISION="$CI_COMMIT_SHA"
      --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
      --tag "$CI_REGISTRY_IMAGE:git-$CI_COMMIT_SHORT_SHA"
      .
```

This job builds but does not yet push.

However, a major issue exists:

> The image exists only inside this job’s Docker daemon.

The next job may run on a different runner or a fresh Docker-in-Docker daemon.

CI jobs are normally isolated.

You need a method to transfer the built image.

---

# 19. Strategies for sharing an image between jobs

Several approaches are possible.

## Strategy A — Push a temporary commit tag

Build and push:

```text
registry/project:git-commit
```

Later jobs pull and test it.

Advantages:

- Simple
    
- Works across runners
    
- Registry becomes the artifact store
    

Disadvantage:

- Image is pushed before all tests complete
    

You can use a staging repository or commit tag and promote only after tests.

## Strategy B — Save the image as a job artifact

```bash
docker save IMAGE | gzip > image.tar.gz
```

Upload it as a CI artifact.

Next job:

```bash
gzip -dc image.tar.gz | docker load
```

Advantages:

- Image is not published before testing
    

Disadvantages:

- Large artifacts
    
- Slower upload/download
    
- Artifact-size limits
    

## Strategy C — Build and test in one job

Build image, run tests, then push if everything succeeds.

This is the simplest Day 19 approach.

## Strategy D — BuildKit registry output/cache

Advanced pipelines can push build output and cache efficiently using BuildKit.

For today, combine build, image test, and push in one release job.

---

# 20. Build, test, and publish in one job

Add:

```yaml
publish-commit-image:
  stage: publish
  image: docker:cli

  services:
    - name: docker:dind

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

    IMAGE_COMMIT:
      value: "$CI_REGISTRY_IMAGE:git-$CI_COMMIT_SHORT_SHA"

  before_script:
    - docker version
    - >
      echo "$CI_REGISTRY_PASSWORD"
      | docker login "$CI_REGISTRY"
          --username "$CI_REGISTRY_USER"
          --password-stdin

  script:
    - >
      docker build
      --pull
      --build-arg APP_VERSION="git-$CI_COMMIT_SHORT_SHA"
      --build-arg VCS_REVISION="$CI_COMMIT_SHA"
      --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
      --tag "$IMAGE_COMMIT"
      .

    - >
      docker run --rm
      "$IMAGE_COMMIT"
      python -c
      "import flask, psycopg, gunicorn; print('Import test passed')"

    - >
      test "$(
        docker image inspect "$IMAGE_COMMIT"
        --format '{{.Config.User}}'
      )" != ""

    - docker push "$IMAGE_COMMIT"

  after_script:
    - docker logout "$CI_REGISTRY"
```

This job:

1. Authenticates to the registry.
    
2. Builds the image.
    
3. Runs an image-level import test.
    
4. Checks that the image defines a runtime user.
    
5. Pushes the immutable commit tag.
    

---

# 21. Why test the image itself?

Source tests do not prove the image works.

Image-specific failures may include:

- Missing copied file
    
- Missing runtime library
    
- Incorrect `CMD`
    
- Wrong user permissions
    
- Missing Python dependency
    
- Wrong architecture
    
- Broken entrypoint
    
- Incomplete multi-stage copy
    
- Invalid environment defaults
    

Therefore, test both:

```text
Source code
and
Final built image
```

Docker’s official CI guidance includes workflows that build an image, load it for testing, and only push after validation succeeds. ([Docker Documentation](https://docs.docker.com/build/ci/github-actions/test-before-push/?utm_source=chatgpt.com "Test before push with GitHub Actions"))

---

# 22. Integration testing with PostgreSQL

Your API requires PostgreSQL during normal startup.

You can use a GitLab CI service:

```yaml
integration-tests:
  stage: test
  image: python:3.13-slim

  services:
    - name: postgres:17
      alias: database

  variables:
    POSTGRES_USER: device_app
    POSTGRES_PASSWORD: testing-password
    POSTGRES_DB: device_monitor

    DB_HOST: database
    DB_PORT: "5432"
    DB_NAME: device_monitor
    DB_USER: device_app
    DB_PASSWORD: testing-password

  before_script:
    - python -m pip install --no-cache-dir -r requirements.txt
    - python -m pip install --no-cache-dir -r requirements-test.txt

  script:
    - |
      python - <<'PY'
      import os
      import time

      import psycopg

      for attempt in range(30):
          try:
              connection = psycopg.connect(
                  host=os.environ["DB_HOST"],
                  port=int(os.environ["DB_PORT"]),
                  dbname=os.environ["DB_NAME"],
                  user=os.environ["DB_USER"],
                  password=os.environ["DB_PASSWORD"],
              )
              connection.close()
              print("PostgreSQL is ready")
              break
          except psycopg.OperationalError:
              if attempt == 29:
                  raise
              time.sleep(1)
      PY

    - python -m pytest -v tests/
```

The alias:

```text
database
```

becomes the service hostname inside the CI job network.

GitLab’s Docker-executor documentation supports running dependency services such as databases alongside a job container. ([GitLab Docs](https://docs.gitlab.com/ci/docker/using_docker_images/?utm_source=chatgpt.com "Run your CI/CD jobs in Docker containers - GitLab Docs"))

---

# 23. Add a real API smoke test

A full image test can start PostgreSQL and the API.

Create `compose.ci.yaml`:

```yaml
services:
  api:
    image: "${TEST_IMAGE}"
    environment:
      APP_ENV: testing
      APP_VERSION: "${CI_COMMIT_SHORT_SHA:-local}"

      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: device_monitor
      DB_USER: device_app
      DB_PASSWORD: testing-password
    depends_on:
      database:
        condition: service_healthy
    ports:
      - "127.0.0.1:18080:5000"

  database:
    image: postgres:17
    environment:
      POSTGRES_USER: device_app
      POSTGRES_PASSWORD: testing-password
      POSTGRES_DB: device_monitor
    healthcheck:
      test:
        - CMD-SHELL
        - pg_isready -U device_app -d device_monitor
      interval: 2s
      timeout: 2s
      retries: 30
```

In the CI job:

```yaml
script:
  - docker build -t "$IMAGE_COMMIT" .
  - export TEST_IMAGE="$IMAGE_COMMIT"
  - docker compose -f compose.ci.yaml up -d
  - |
    for attempt in $(seq 1 30); do
      if wget -qO- http://127.0.0.1:18080/health; then
        exit 0
      fi

      sleep 2
    done

    docker compose -f compose.ci.yaml logs
    exit 1
```

Cleanup:

```yaml
after_script:
  - docker compose -f compose.ci.yaml down -v || true
```

---

# 24. Always clean up CI integration resources

A CI integration test may create:

- Containers
    
- Networks
    
- Volumes
    
- Temporary images
    
- Test databases
    

Always clean them up:

```yaml
after_script:
  - docker compose -f compose.ci.yaml down -v || true
```

The `|| true` prevents cleanup failure from hiding the original test result.

On an ephemeral Docker-in-Docker service, resources disappear with the job anyway, but explicit cleanup is still good discipline and is essential on persistent shell runners.

---

# 25. Branch rules

You usually do not want every pipeline to publish every kind of tag.

A common policy:

```text
Merge request:
validate and test only

Feature branch:
validate, test, optionally push commit image

Default branch:
validate, test, push commit and main tags

Git release tag:
validate, test, push version and stable tags
```

Use `rules`.

Example:

```yaml
publish-commit-image:
  rules:
    - if: '$CI_COMMIT_BRANCH'
```

Only default branch:

```yaml
rules:
  - if: '$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH'
```

Only Git tags:

```yaml
rules:
  - if: '$CI_COMMIT_TAG'
```

Merge-request pipelines:

```yaml
rules:
  - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
```

---

# 26. Publish an immutable release tag

Create a release job:

```yaml
publish-release-image:
  stage: publish
  image: docker:cli

  services:
    - name: docker:dind

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  rules:
    - if: '$CI_COMMIT_TAG'

  before_script:
    - >
      echo "$CI_REGISTRY_PASSWORD"
      | docker login "$CI_REGISTRY"
          --username "$CI_REGISTRY_USER"
          --password-stdin

  script:
    - export IMAGE_VERSION="$CI_REGISTRY_IMAGE:$CI_COMMIT_TAG"
    - export IMAGE_COMMIT="$CI_REGISTRY_IMAGE:git-$CI_COMMIT_SHORT_SHA"

    - >
      docker build
      --pull
      --build-arg APP_VERSION="$CI_COMMIT_TAG"
      --build-arg VCS_REVISION="$CI_COMMIT_SHA"
      --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
      --tag "$IMAGE_VERSION"
      --tag "$IMAGE_COMMIT"
      .

    - >
      docker run --rm
      "$IMAGE_VERSION"
      python -c
      "import flask, psycopg, gunicorn"

    - docker push "$IMAGE_VERSION"
    - docker push "$IMAGE_COMMIT"
```

If you create the Git tag:

```bash
git tag 1.0.0
git push origin 1.0.0
```

the pipeline publishes:

```text
registry/project:1.0.0
registry/project:git-<commit>
```

---

# 27. Validate release-tag format

Someone could create an unsuitable tag:

```text
temporary-test
```

Use a rule or script to require semantic versions:

```yaml
script:
  - |
    echo "$CI_COMMIT_TAG" \
      | grep -Eq '^v?[0-9]+\.[0-9]+\.[0-9]+$'
```

Accepted:

```text
1.0.0
v1.0.0
2.5.13
```

Rejected:

```text
release
final
1.0
test
```

A stricter production pipeline may support prereleases:

```text
1.0.0-rc.1
```

but define the policy explicitly.

---

# 28. Convenience tags

A release job may also publish:

```text
stable
major version
major.minor version
```

For release `2.4.1`:

```text
2.4.1
2.4
2
stable
```

However, generating version fragments safely in shell requires care.

Example:

```bash
VERSION="${CI_COMMIT_TAG#v}"
MAJOR="${VERSION%%.*}"
MINOR="${VERSION%.*}"
```

Tags:

```bash
docker tag "$IMAGE_VERSION" "$CI_REGISTRY_IMAGE:$MAJOR"
docker tag "$IMAGE_VERSION" "$CI_REGISTRY_IMAGE:$MINOR"
docker tag "$IMAGE_VERSION" "$CI_REGISTRY_IMAGE:stable"
```

Push:

```bash
docker push "$CI_REGISTRY_IMAGE:$MAJOR"
docker push "$CI_REGISTRY_IMAGE:$MINOR"
docker push "$CI_REGISTRY_IMAGE:stable"
```

Never overwrite the immutable full release tag.

---

# 29. Cache builds in CI

CI runners often begin with empty or limited local cache.

Without cache:

```text
Every pipeline
→ download base image
→ reinstall all dependencies
→ rebuild every layer
```

BuildKit supports external cache backends such as:

- Inline cache
    
- Registry cache
    
- Local cache
    
- GitHub Actions cache
    

Registry-based cache can be stored separately from the final image and can preserve intermediate build layers. ([Docker Documentation](https://docs.docker.com/build/cache/backends/?utm_source=chatgpt.com "Cache storage backends"))

---

# 30. Use Buildx with a registry cache

A GitLab CI build can use:

```bash
docker buildx create \
  --driver docker-container \
  --use
```

Then:

```bash
docker buildx build \
  --cache-from \
    type=registry,ref="$CI_REGISTRY_IMAGE:buildcache" \
  --cache-to \
    type=registry,ref="$CI_REGISTRY_IMAGE:buildcache",mode=max \
  --tag "$IMAGE_COMMIT" \
  --push \
  .
```

Meaning:

```text
cache-from
→ Import previous build cache

cache-to
→ Export updated cache

mode=max
→ Include intermediate-stage cache
```

Do not store secrets in layers used as cache.

Docker specifically warns that secrets should be passed using dedicated secret mechanisms rather than `COPY` or `ARG`, because cached layers can expose them. ([Docker Documentation](https://docs.docker.com/build/cache/backends/?utm_source=chatgpt.com "Cache storage backends"))

---

# 31. BuildKit CI job

A more modern publishing job:

```yaml
publish-commit-image:
  stage: publish
  image: docker:cli

  services:
    - name: docker:dind

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  rules:
    - if: '$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH'

  before_script:
    - >
      echo "$CI_REGISTRY_PASSWORD"
      | docker login "$CI_REGISTRY"
          --username "$CI_REGISTRY_USER"
          --password-stdin

    - docker buildx create --use
    - docker buildx inspect --bootstrap

  script:
    - export IMAGE_COMMIT="$CI_REGISTRY_IMAGE:git-$CI_COMMIT_SHORT_SHA"

    - >
      docker buildx build
      --pull
      --cache-from
      type=registry,ref="$CI_REGISTRY_IMAGE:buildcache"
      --cache-to
      type=registry,ref="$CI_REGISTRY_IMAGE:buildcache",mode=max
      --build-arg APP_VERSION="git-$CI_COMMIT_SHORT_SHA"
      --build-arg VCS_REVISION="$CI_COMMIT_SHA"
      --tag "$IMAGE_COMMIT"
      --push
      .
```

A pushed Buildx image may not automatically be loaded into the local Docker daemon.

If you must run it locally in that job, either:

- Pull it after push
    
- Use `--load` for a single platform
    
- Separate test and push builds
    
- Export to a local image store
    

---

# 32. Test before Buildx push

For a single platform:

```bash
docker buildx build \
  --load \
  --tag "$IMAGE_COMMIT" \
  .
```

Test:

```bash
docker run --rm "$IMAGE_COMMIT" \
  python -c 'import flask, psycopg, gunicorn'
```

Then push:

```bash
docker push "$IMAGE_COMMIT"
```

Alternatively, push to a commit tag, pull it back, and run integration tests.

For a learning pipeline, correctness is more important than eliminating one duplicate build operation.

---

# 33. Multi-platform image publishing

You may eventually need:

```text
linux/amd64
linux/arm64
```

Buildx:

```bash
docker buildx build \
  --platform linux/amd64,linux/arm64 \
  --tag "$CI_REGISTRY_IMAGE:$CI_COMMIT_TAG" \
  --push \
  .
```

The resulting registry tag points to a multi-platform image index.

Docker’s Buildx-based CI tooling supports multi-platform image builds and publishing. ([Docker Documentation](https://docs.docker.com/build/ci/github-actions/multi-platform/?utm_source=chatgpt.com "Multi-platform image with GitHub Actions"))

Do not enable multi-platform publishing until:

- The application supports both architectures
    
- Native dependencies build correctly
    
- Each platform is tested
    
- The target base images provide those platforms
    

---

# 34. Build provenance

Provenance describes how an image was built.

It may include information such as:

- Builder identity
    
- Source repository
    
- Source revision
    
- Build parameters
    
- Build timestamps
    
- Build environment
    

Docker supports provenance attestations through Buildx. ([Docker Documentation](https://docs.docker.com/build/metadata/attestations/?utm_source=chatgpt.com "Build attestations"))

Build:

```bash
docker buildx build \
  --provenance=mode=max \
  --tag "$IMAGE" \
  --push \
  .
```

This increases release traceability.

---

# 35. SBOM attestations

An SBOM is a software bill of materials.

It records software components inside the image, such as:

- Package name
    
- Version
    
- Package identifier
    
- License information
    
- Dependency details
    

Docker Buildx can generate an SBOM attestation at build time. ([Docker Documentation](https://docs.docker.com/build/metadata/attestations/sbom/?utm_source=chatgpt.com "SBOM attestations"))

Example:

```bash
docker buildx build \
  --sbom=true \
  --provenance=mode=max \
  --tag "$IMAGE" \
  --push \
  .
```

A release pipeline can therefore produce:

```text
Container image
+
SBOM
+
Build provenance
```

---

# 36. Be careful with provenance and build arguments

Build provenance may include build parameters.

Never pass secrets through:

```text
--build-arg PASSWORD=...
```

Docker warns that misuse of build arguments for secrets can expose those values in build provenance. Use secret mounts instead. ([Docker Documentation](https://docs.docker.com/build/ci/github-actions/attestations/?utm_source=chatgpt.com "Add SBOM and provenance attestations with GitHub Actions"))

Correct:

```bash
docker buildx build \
  --secret id=private_token,env=PRIVATE_TOKEN \
  .
```

Dockerfile:

```dockerfile
RUN --mount=type=secret,id=private_token \
    token="$(cat /run/secrets/private_token)" \
    && use-token-without-saving-it
```

---

# 37. CI/CD variables

Store sensitive values in GitLab’s CI/CD variable system rather than in `.gitlab-ci.yml`.

Examples:

```text
DEPLOY_HOST
DEPLOY_USER
DEPLOY_SSH_KEY
PRODUCTION_URL
SCANNER_TOKEN
EXTERNAL_REGISTRY_TOKEN
```

Important variable properties include:

- Masked
    
- Protected
    
- Environment scope
    
- File type
    
- Variable type
    

Do not store the GitLab Registry password manually when predefined per-job variables already provide appropriate registry access.

---

# 38. Protected variables

A protected variable is available only to pipelines on protected branches or tags.

Use protected variables for:

- Production SSH keys
    
- Production registry tokens
    
- Deployment credentials
    
- Signing keys
    
- Cloud credentials
    

A feature-branch pipeline should not receive production credentials.

Security model:

```text
Feature branch
→ tests only
→ no production secret

Protected release tag
→ verified release pipeline
→ production credentials available
```

---

# 39. Masked variables

Masked variables are hidden from ordinary job-log output when GitLab recognizes their exact values.

Do not depend entirely on masking.

A command could:

- Transform the value
    
- Encode it
    
- Write it to an artifact
    
- Send it over a network
    
- Copy it into an image
    

Treat pipeline code as trusted code whenever it receives secrets.

Never run unreviewed merge-request code with production credentials.

---

# 40. Pipeline job artifacts

Artifacts are files uploaded by a job and made available to later jobs or for download.

Example test report:

```yaml
unit-tests:
  stage: test
  image: python:3.13-slim

  before_script:
    - pip install -r requirements-test.txt

  script:
    - pytest --junitxml=reports/pytest.xml tests/

  artifacts:
    when: always
    reports:
      junit: reports/pytest.xml
    paths:
      - reports/
    expire_in: 7 days
```

Artifacts can include:

- Test reports
    
- Coverage reports
    
- Lint results
    
- Build logs
    
- Deployment manifests
    

Avoid uploading secrets or large unnecessary image archives.

---

# 41. Job dependencies and `needs`

By default, later stages wait for earlier stages.

`needs` can define more precise relationships:

```yaml
publish-release-image:
  stage: publish

  needs:
    - unit-tests
    - integration-tests
    - dockerfile-check
```

This communicates:

```text
Do not publish unless these exact validation jobs succeeded.
```

It may also allow jobs to start earlier than strict stage ordering would otherwise permit.

Use `needs` to model the real dependency graph.

---

# 42. `allow_failure`

A job can be marked:

```yaml
allow_failure: true
```

This means its failure does not necessarily fail the pipeline.

Appropriate for:

- Experimental analysis
    
- Informational reports
    
- Non-blocking warnings
    

Do not use it for:

- Unit tests
    
- Image integration tests
    
- Critical vulnerability scans
    
- Release-image publishing
    
- Production deployment validation
    

A red security scan should not be cosmetically turned green by `allow_failure` without a conscious risk policy.

---

# 43. Vulnerability scanning in CI

A scanning stage may run after image build.

Conceptual job:

```yaml
scan-image:
  stage: image-test
  image: docker:cli

  services:
    - name: docker:dind

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  script:
    - docker pull "$IMAGE_COMMIT"
    - docker scout cves --only-severity critical,high "$IMAGE_COMMIT"
```

Tool availability and authentication depend on your Docker Scout setup.

Alternatives include:

- GitLab container scanning
    
- Trivy
    
- Grype
    
- Registry-integrated scanning
    
- Harbor scanning
    

Define the release policy:

```text
Critical fix available
→ release fails

High severity
→ review required

Medium/low
→ tracked according to policy
```

Do not treat the raw vulnerability count as the only decision criterion.

---

# 44. Release manifest artifact

Generate a release manifest:

```bash
cat > release-manifest.txt <<EOF
release_tag=$CI_COMMIT_TAG
commit_sha=$CI_COMMIT_SHA
pipeline_id=$CI_PIPELINE_ID
image=$CI_REGISTRY_IMAGE:$CI_COMMIT_TAG
build_time=$(date -u +%Y-%m-%dT%H:%M:%SZ)
EOF
```

After pushing, record the digest:

```bash
docker pull "$CI_REGISTRY_IMAGE:$CI_COMMIT_TAG"

DIGEST="$(
  docker image inspect \
    "$CI_REGISTRY_IMAGE:$CI_COMMIT_TAG" \
    --format '{{index .RepoDigests 0}}'
)"
```

Append:

```bash
echo "digest=$DIGEST" >> release-manifest.txt
```

Upload:

```yaml
artifacts:
  paths:
    - release-manifest.txt
  expire_in: 1 year
```

This provides a durable link among:

```text
Git tag
Git commit
Pipeline
Image tag
Image digest
Build time
```

---

# 45. Deployment pipeline structure

A controlled release pipeline may be:

```text
validate
   ↓
unit tests
   ↓
integration tests
   ↓
build image
   ↓
scan image
   ↓
push immutable release
   ↓
deploy staging
   ↓
staging health verification
   ↓
manual production approval
   ↓
production deployment
   ↓
production health verification
```

The image should not be rebuilt between staging and production.

Promote the exact same digest.

---

# 46. Deployment by SSH

A simple deployment job can connect to a Docker host through SSH.

Conceptually:

```yaml
deploy-staging:
  stage: deploy

  rules:
    - if: '$CI_COMMIT_TAG'

  script:
    - ssh "$DEPLOY_USER@$STAGING_HOST" "
        cd /opt/device-api &&
        export API_IMAGE='$CI_REGISTRY_IMAGE:$CI_COMMIT_TAG' &&
        docker compose pull api &&
        docker compose up -d --no-deps api
      "
```

This requires:

- SSH key management
    
- Host-key verification
    
- Registry authentication on target server
    
- Restricted deployment user
    
- Protected CI variables
    
- Controlled Compose files on target
    

Do not disable SSH host-key checking merely for convenience.

---

# 47. Production deployment should be manual initially

Example:

```yaml
deploy-production:
  stage: deploy

  rules:
    - if: '$CI_COMMIT_TAG'

  when: manual

  environment:
    name: production

  script:
    - ./scripts/deploy-production.sh "$CI_COMMIT_TAG"
```

The pipeline pauses until an authorized person approves the deployment.

This gives you:

- Automation
    
- Audit trail
    
- Controlled approval
    
- Reproducible commands
    
- Reduced typing mistakes
    

---

# 48. Deployment Compose file

On the server, use interpolation:

```yaml
services:
  api:
    image: "${API_IMAGE:?API_IMAGE must be set}"

    ports:
      - "127.0.0.1:8080:5000"

    environment:
      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: device_monitor
      DB_USER: device_app
      DB_PASSWORD_FILE: /run/secrets/database-password
```

Deployment command:

```bash
export API_IMAGE="registry.example.com/group/project:1.2.0"

docker compose pull api

docker compose up -d \
  --no-deps \
  api
```

The deployment variable changes the image version without editing the Compose file.

---

# 49. Deploy by digest

A stronger deployment uses:

```text
registry/project@sha256:...
```

Pipeline:

```bash
API_IMAGE="$CI_REGISTRY_IMAGE@sha256:EXACT_DIGEST"
```

Then:

```bash
docker compose pull api
docker compose up -d --no-deps api
```

This prevents a mutable tag from changing the release after approval.

The version tag remains useful for humans, while the digest defines exact content.

---

# 50. Health verification after deployment

Do not treat:

```bash
docker compose up -d
```

as proof of success.

Verify:

```bash
docker compose ps
```

Then:

```bash
curl --fail \
  --retry 10 \
  --retry-delay 3 \
  http://127.0.0.1:8080/health
```

If health verification fails:

```text
Deployment job fails
Release marked unsuccessful
Operator investigates
Rollback may be initiated
```

Also verify the deployed image:

```bash
docker inspect \
  "$(docker compose ps -q api)" \
  --format '{{.Config.Image}}'
```

---

# 51. Rollback job

A simple rollback might use a manually supplied previous image reference.

```yaml
rollback-production:
  stage: deploy

  when: manual

  variables:
    ROLLBACK_IMAGE:
      description: "Exact previous image tag or digest"

  script:
    - test -n "$ROLLBACK_IMAGE"
    - ./scripts/deploy-production.sh "$ROLLBACK_IMAGE"
```

A rollback should:

1. Deploy the previous image.
    
2. Preserve persistent volumes.
    
3. Verify health.
    
4. Record the rollback event.
    

Database schema compatibility must be considered.

An older application may not work with newer database migrations.

---

# 52. A complete beginner GitLab pipeline

Here is a practical starting `.gitlab-ci.yml`:

```yaml
stages:
  - validate
  - test
  - publish

variables:
  PIP_DISABLE_PIP_VERSION_CHECK: "1"

validate-python:
  stage: validate
  image: python:3.13-slim

  script:
    - python -m compileall app.py tests/

unit-tests:
  stage: test
  image: python:3.13-slim

  before_script:
    - python -m pip install --no-cache-dir -r requirements-test.txt

  script:
    - pytest -v tests/

  artifacts:
    when: always
    reports:
      junit: reports/pytest.xml
    paths:
      - reports/
    expire_in: 7 days

publish-commit-image:
  stage: publish
  image: docker:cli

  services:
    - name: docker:dind

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  rules:
    - if: '$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH'

  needs:
    - validate-python
    - unit-tests

  before_script:
    - docker version
    - >
      echo "$CI_REGISTRY_PASSWORD"
      | docker login "$CI_REGISTRY"
          --username "$CI_REGISTRY_USER"
          --password-stdin

  script:
    - export IMAGE="$CI_REGISTRY_IMAGE:git-$CI_COMMIT_SHORT_SHA"

    - >
      docker build
      --pull
      --build-arg APP_VERSION="git-$CI_COMMIT_SHORT_SHA"
      --build-arg VCS_REVISION="$CI_COMMIT_SHA"
      --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
      --tag "$IMAGE"
      .

    - >
      docker run --rm
      "$IMAGE"
      python -c
      "import flask, psycopg, gunicorn"

    - docker push "$IMAGE"

  after_script:
    - docker logout "$CI_REGISTRY" || true

publish-release-image:
  stage: publish
  image: docker:cli

  services:
    - name: docker:dind

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  rules:
    - if: '$CI_COMMIT_TAG'

  needs:
    - validate-python
    - unit-tests

  before_script:
    - >
      echo "$CI_REGISTRY_PASSWORD"
      | docker login "$CI_REGISTRY"
          --username "$CI_REGISTRY_USER"
          --password-stdin

  script:
    - >
      echo "$CI_COMMIT_TAG"
      | grep -Eq '^v?[0-9]+\.[0-9]+\.[0-9]+$'

    - export VERSION="${CI_COMMIT_TAG#v}"
    - export IMAGE_VERSION="$CI_REGISTRY_IMAGE:$VERSION"
    - export IMAGE_COMMIT="$CI_REGISTRY_IMAGE:git-$CI_COMMIT_SHORT_SHA"

    - >
      docker build
      --pull
      --build-arg APP_VERSION="$VERSION"
      --build-arg VCS_REVISION="$CI_COMMIT_SHA"
      --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
      --tag "$IMAGE_VERSION"
      --tag "$IMAGE_COMMIT"
      .

    - >
      docker run --rm
      "$IMAGE_VERSION"
      python -c
      "import flask, psycopg, gunicorn"

    - docker push "$IMAGE_VERSION"
    - docker push "$IMAGE_COMMIT"

  after_script:
    - docker logout "$CI_REGISTRY" || true
```

---

# 53. Correct the JUnit report example

The unit-test job declares:

```yaml
reports:
  junit: reports/pytest.xml
```

Therefore, the test command must generate that file:

```yaml
script:
  - mkdir -p reports
  - >
    pytest
    -v
    --junitxml=reports/pytest.xml
    tests/
```

The complete job becomes:

```yaml
unit-tests:
  stage: test
  image: python:3.13-slim

  before_script:
    - python -m pip install --no-cache-dir -r requirements-test.txt

  script:
    - mkdir -p reports
    - >
      pytest
      -v
      --junitxml=reports/pytest.xml
      tests/

  artifacts:
    when: always
    reports:
      junit: reports/pytest.xml
    paths:
      - reports/
    expire_in: 7 days
```

Always ensure declared artifacts are actually created.

---

# 54. Pipeline for a C MQTT daemon

Your C daemon pipeline could use:

```yaml
stages:
  - validate
  - test
  - publish
```

Compile with warnings treated as errors:

```yaml
c-build-test:
  stage: test
  image: debian:13

  before_script:
    - apt-get update
    - >
      apt-get install -y
      --no-install-recommends
      build-essential
      pkg-config
      libmosquitto-dev

  script:
    - make clean
    - make CFLAGS="-Wall -Wextra -Wpedantic -Werror -O2"
    - ./tests/run-tests.sh
```

Container build:

```yaml
publish-daemon-image:
  stage: publish
  image: docker:cli

  services:
    - name: docker:dind

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  script:
    - docker build -t "$IMAGE" .
    - >
      docker run --rm
      --entrypoint /usr/local/bin/mqtt-service-daemon
      "$IMAGE"
      --version
    - docker push "$IMAGE"
```

Your daemon may need a dedicated:

```text
--version
```

or:

```text
--check-config
```

option to support useful image smoke tests.

---

# 55. Design applications for pipeline testing

A container-friendly application should provide testable operations.

Useful commands include:

```text
--version
--help
--check-config
--migrate
--health-check
--self-test
```

For your C MQTT daemon:

```bash
mqtt-service-daemon --check-config \
  /etc/mqtt-service-daemon/config.conf
```

could validate configuration and exit.

For the API:

```bash
python -m compileall app.py
```

and:

```bash
python -c 'import app'
```

can detect certain startup failures.

Good software design improves CI/CD quality.

---

# 56. GitHub Actions equivalent

A similar GitHub Actions workflow would be stored in:

```text
.github/workflows/docker.yml
```

Example:

```yaml
name: Docker CI

on:
  push:
    branches:
      - main
    tags:
      - "v*"

  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - name: Check out source
        uses: actions/checkout@v4

      - name: Set up Python
        uses: actions/setup-python@v5
        with:
          python-version: "3.13"

      - name: Install tests
        run: |
          pip install -r requirements-test.txt

      - name: Run tests
        run: |
          pytest -v tests/

  docker:
    runs-on: ubuntu-latest
    needs:
      - test

    permissions:
      contents: read
      packages: write

    steps:
      - name: Check out source
        uses: actions/checkout@v4

      - name: Set up Buildx
        uses: docker/setup-buildx-action@v3

      - name: Log in to registry
        uses: docker/login-action@v4
        with:
          username: ${{ vars.DOCKERHUB_USERNAME }}
          password: ${{ secrets.DOCKERHUB_TOKEN }}

      - name: Generate metadata
        id: metadata
        uses: docker/metadata-action@v5
        with:
          images: YOUR_NAMESPACE/device-api
          tags: |
            type=sha,prefix=git-
            type=ref,event=tag
            type=raw,value=stable,enable=${{ startsWith(github.ref, 'refs/tags/') }}

      - name: Build and push
        uses: docker/build-push-action@v6
        with:
          context: .
          push: ${{ github.event_name != 'pull_request' }}
          tags: ${{ steps.metadata.outputs.tags }}
          labels: ${{ steps.metadata.outputs.labels }}
          cache-from: type=gha
          cache-to: type=gha,mode=max
          provenance: mode=max
          sbom: true
```

Docker provides official GitHub Actions for Buildx setup, registry login, metadata generation, image build, caching, multi-platform output, and push operations. ([Docker Documentation](https://docs.docker.com/build/ci/github-actions/?utm_source=chatgpt.com "Docker Build GitHub Actions"))

---

# 57. Why GitHub pull requests should not push releases

The example uses:

```yaml
push: ${{ github.event_name != 'pull_request' }}
```

A pull request from untrusted or unmerged code should generally:

```text
Build
Test
Validate
```

but not:

```text
Receive production registry credentials
Push stable release tags
Deploy production
```

The same principle applies to GitLab merge requests.

Separate untrusted validation from privileged release operations.

---

# 58. Pipeline troubleshooting

## Pipeline does not start

Check:

- `.gitlab-ci.yml` is committed
    
- YAML syntax is valid
    
- Pipeline rules permit the event
    
- CI/CD is enabled
    
- A runner is assigned
    

## Job remains pending

Likely causes:

- No runner available
    
- Runner tags do not match
    
- Runner offline
    
- Protected runner restrictions
    
- Concurrency limit reached
    

## Docker daemon unavailable

Error:

```text
Cannot connect to the Docker daemon
```

Check:

- Docker-in-Docker service
    
- `DOCKER_HOST`
    
- Runner privileged configuration
    
- Docker socket availability
    
- TLS settings
    

## Registry login fails

Check:

- `CI_REGISTRY`
    
- `CI_REGISTRY_USER`
    
- `CI_REGISTRY_PASSWORD`
    
- Registry availability
    
- Token scope
    
- TLS trust
    

## Push is denied

Check:

- Image path begins with correct `CI_REGISTRY_IMAGE`
    
- Job token has write access
    
- Project registry is enabled
    
- Protected branch/tag policy
    
- Repository permissions
    

## Image tests fail only in CI

Check:

- Architecture
    
- Missing environment variables
    
- Dependency service readiness
    
- Relative paths
    
- Case sensitivity
    
- Files excluded by `.dockerignore`
    
- Different base image content
    

---

# 59. Avoid these CI/CD mistakes

## Building on the production server

Production should pull tested artifacts.

## Publishing only `latest`

Publish immutable commit and version tags.

## Giving production secrets to every branch

Use protected variables and protected environments.

## Passing secrets through build arguments

Use BuildKit secret mounts.

## Mounting a shared runner’s Docker socket into untrusted jobs

Use isolated trusted builders.

## Pushing before any image-level test

At minimum, test imports, startup, user, and health.

## Rebuilding separately for staging and production

Promote the same digest.

## Ignoring cleanup on persistent runners

Remove temporary networks, containers, and volumes.

## Suppressing every scan failure

Create an explicit severity and exception policy.

## Deploying without health verification

A successful `docker compose up` does not prove the application works.

---

# 60. Recommended pipeline for your MQTT platform

Use separate image repositories:

```text
registry/project/dashboard
registry/project/mqtt-daemon
registry/project/mqtt-consumer
```

Pipeline:

```text
Source validation
├── Python lint/tests
├── C compiler warnings
├── Dockerfile checks
└── Compose validation

Build and image tests
├── Dashboard image
├── C daemon image
└── MQTT consumer image

Integration environment
├── Mosquitto
├── PostgreSQL
├── Dashboard
└── MQTT client simulator

Security
├── Image scan
├── SBOM
└── Provenance

Publish
├── Commit tags
├── Release tags
└── Digests

Deploy
├── Staging
├── Health tests
├── Manual approval
└── Production
```

---

# 61. MQTT integration test idea

The integration pipeline can:

1. Start Mosquitto.
    
2. Start PostgreSQL.
    
3. Start the dashboard.
    
4. Start your C daemon with a test identity.
    
5. Wait for a heartbeat message.
    
6. Confirm the dashboard records the device.
    
7. Stop the daemon.
    
8. Confirm the broker or dashboard eventually records offline state.
    

Conceptually:

```text
CI starts full stack
      ↓
C daemon publishes heartbeat
      ↓
MQTT broker receives message
      ↓
Consumer stores state
      ↓
API exposes device
      ↓
Test queries API
      ↓
Pipeline passes
```

This validates the actual application architecture, not merely individual source files.

---

# 62. Day 19 practical laboratory

## Exercise 1 — Initial pipeline

Create `.gitlab-ci.yml`.

Add:

- `validate`
    
- `test`
    
- `publish`
    

stages.

Push and inspect the pipeline.

## Exercise 2 — Python validation

Run:

```bash
python -m compileall
```

inside a Python CI image.

Deliberately create a syntax error and verify the pipeline fails.

## Exercise 3 — Unit tests

Add pytest.

Generate a JUnit report.

Verify GitLab displays the test result.

## Exercise 4 — Registry authentication

Use GitLab predefined variables.

Authenticate with:

```bash
--password-stdin
```

Confirm credentials do not appear directly in `.gitlab-ci.yml`.

## Exercise 5 — Commit image

Build:

```text
git-$CI_COMMIT_SHORT_SHA
```

Push it only from the default branch.

## Exercise 6 — Image smoke test

Before push, confirm:

- Python imports work
    
- Runtime user is non-root
    
- Required files exist
    
- Compiler is absent
    
- Image starts correctly
    

## Exercise 7 — Release pipeline

Create Git tag:

```text
1.0.0
```

Publish:

```text
1.0.0
git-<commit>
```

Confirm both reference the same source revision.

## Exercise 8 — Integration service

Run PostgreSQL as a CI service.

Wait for readiness.

Connect and execute `SELECT 1`.

## Exercise 9 — Compose smoke test

Start the API and PostgreSQL with `compose.ci.yaml`.

Call `/health`.

Collect logs if the test fails.

## Exercise 10 — Manual deployment

Create a manual staging or production deployment job.

Deploy the image by exact tag or digest.

Verify health after deployment.

---

# 63. Day 19 command and variable reference

```bash
# Validate GitLab CI YAML locally only where suitable tooling exists
# Primary validation should also occur through GitLab's CI lint interface.

# Safe registry login
echo "$CI_REGISTRY_PASSWORD" \
  | docker login "$CI_REGISTRY" \
      --username "$CI_REGISTRY_USER" \
      --password-stdin

# Commit-specific image
IMAGE="$CI_REGISTRY_IMAGE:git-$CI_COMMIT_SHORT_SHA"

# Release image
IMAGE="$CI_REGISTRY_IMAGE:$CI_COMMIT_TAG"

# Build traceable image
docker build \
  --pull \
  --build-arg APP_VERSION="$CI_COMMIT_TAG" \
  --build-arg VCS_REVISION="$CI_COMMIT_SHA" \
  --tag "$IMAGE" \
  .

# Image smoke test
docker run --rm \
  "$IMAGE" \
  python -c 'import flask, psycopg, gunicorn'

# Push
docker push "$IMAGE"

# Buildx registry cache
docker buildx build \
  --cache-from type=registry,ref="$CI_REGISTRY_IMAGE:buildcache" \
  --cache-to type=registry,ref="$CI_REGISTRY_IMAGE:buildcache",mode=max \
  --tag "$IMAGE" \
  --push \
  .

# Build with SBOM and provenance
docker buildx build \
  --sbom=true \
  --provenance=mode=max \
  --tag "$IMAGE" \
  --push \
  .
```

Important GitLab variables:

```text
CI_REGISTRY
CI_REGISTRY_IMAGE
CI_REGISTRY_USER
CI_REGISTRY_PASSWORD
CI_COMMIT_SHA
CI_COMMIT_SHORT_SHA
CI_COMMIT_BRANCH
CI_COMMIT_TAG
CI_DEFAULT_BRANCH
CI_PIPELINE_ID
CI_PIPELINE_SOURCE
```

---

# 64. Knowledge check

## What is CI?

Automated validation of integrated source-code changes.

## What is continuous delivery?

Automatically preparing a deployable release while retaining a controlled deployment decision.

## What executes a GitLab CI job?

A GitLab Runner.

## Why build an image in CI?

To make the build repeatable, traceable, testable, and independent of a developer workstation.

## Why use commit-specific image tags?

They link an image directly to a source revision.

## Should merge-request pipelines receive production credentials?

Normally no.

## What is Docker-in-Docker?

A Docker daemon running as a service container that a CI job uses to build and run images.

## Why is Docker-in-Docker security-sensitive?

Traditional operation often requires privileged runner configuration.

## Why can’t one CI job always use an image built in another job?

Jobs are isolated and may run on separate runners or fresh Docker daemons.

## What are ways to transfer a built image?

Push it to a registry, save it as an artifact, or combine build and test in one job.

## Why test the final image?

Source tests cannot detect missing runtime files, libraries, users, or commands.

## What is a protected CI variable?

A variable available only to pipelines on protected branches or tags.

## What is an SBOM attestation?

Build-associated metadata describing software components contained in an image.

## What is provenance?

Metadata describing how and from which source an image was built.

## Should staging and production rebuild separately?

No. Promote the same tested image digest.

## What should happen after deployment?

Verify container state, application health, deployed image identity, and logs.

---

# 65. Day 19 completion challenge

Complete this independently:

1. Add `.gitlab-ci.yml` to the device API project.
    
2. Define `validate`, `test`, `publish`, and `deploy` stages.
    
3. Validate Python syntax.
    
4. Add unit tests.
    
5. Generate a JUnit test report.
    
6. Add a Dockerfile check.
    
7. Configure a trusted Docker-capable runner.
    
8. Authenticate to the GitLab Registry using predefined variables.
    
9. Use `--password-stdin`.
    
10. Build an image tagged with the short Git commit SHA.
    
11. Add full commit SHA as an OCI label.
    
12. Add pipeline ID as metadata.
    
13. Test the final image before pushing.
    
14. Confirm the runtime user is non-root.
    
15. Confirm required Python modules import.
    
16. Confirm the compiler is absent.
    
17. Push the commit image only from the default branch.
    
18. Create a semantic Git release tag.
    
19. Validate the release-tag format.
    
20. Publish the immutable semantic-version image.
    
21. Keep the commit image tag.
    
22. Add a PostgreSQL CI service.
    
23. Wait for PostgreSQL readiness.
    
24. Run an integration database query.
    
25. Create `compose.ci.yaml`.
    
26. Start the API and database.
    
27. Query `/health`.
    
28. Print Compose logs when health fails.
    
29. Remove test containers, networks, and volumes.
    
30. Add a registry-backed BuildKit cache.
    
31. Compare cached and uncached build duration.
    
32. Generate an SBOM attestation.
    
33. Generate provenance.
    
34. Confirm no secrets are passed through build arguments.
    
35. Create a release-manifest artifact.
    
36. Record image tag, digest, commit, pipeline ID, and build time.
    
37. Add a staging deployment job.
    
38. Deploy the exact registry image.
    
39. Verify staging health.
    
40. Add a manual production deployment job.
    
41. Restrict production credentials to protected tags.
    
42. Deploy by exact version or digest.
    
43. Verify the deployed image reference.
    
44. Implement a manual rollback job.
    
45. Explain why the same image—not a rebuild—must move from testing to production.
    

The central Day 19 model is:

```text
Git commit or release tag
          ↓
CI runner
          ↓
Validate source
          ↓
Run tests
          ↓
Build container image
          ↓
Test exact image
          ↓
Scan and attest
          ↓
Push immutable image
          ↓
Deploy exact digest
          ↓
Verify application health
```

The most important operational lesson is:

> A trustworthy container release is not just a successful `docker build`. It is a traceable pipeline result tied to committed source, validated by automated tests, published under immutable identifiers, and deployed as the same exact image that passed validation.