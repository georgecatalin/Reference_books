####  Kubernetes CI/CD and GitOps: Automated Build, Test, Release, Deployment, Promotion, and Rollback

Until now, you performed most deployment operations manually:

```text
Change source code
    ↓
Build container image
    ↓
Push image
    ↓
Update Helm values
    ↓
Run helm upgrade
    ↓
Check Pods
    ↓
Test application
```

This is acceptable while learning, but it becomes risky when deployments depend on manually typed commands.

Typical manual-deployment problems include:

```text
Wrong Kubernetes context
Wrong namespace
Wrong image tag
Forgotten security scan
Incorrect Helm values file
No deployment record
No health verification
Unclear rollback version
Production changed differently from Git
```

Today you will convert the process into an automated delivery pipeline.

The target workflow is:

```text
Developer commits code
        ↓
CI tests source
        ↓
Container image built once
        ↓
Image scanned
        ↓
Image pushed with immutable identity
        ↓
Helm chart linted and rendered
        ↓
Deployment tested in Kubernetes
        ↓
Exact image promoted to staging
        ↓
Approval
        ↓
Exact image promoted to production
        ↓
Health and smoke tests
        ↓
Rollback if verification fails
```

The central lesson is:

> A trustworthy delivery pipeline builds an artifact once, verifies it, records its immutable identity, and promotes that exact artifact through each environment. Production should never rebuild what staging tested.

---

## 1. Day 28 objectives

By the end of today, you should understand:

- Continuous Integration, Delivery, and Deployment
    
- Pipeline stages and jobs
    
- Artifact promotion
    
- GitLab CI/CD fundamentals
    
- Container image tagging in pipelines
    
- Image digests
    
- Test, scan, build, and deploy separation
    
- Helm chart validation
    
- Temporary test namespaces
    
- Kubernetes deployment identities
    
- Push-based deployment
    
- GitOps pull-based deployment
    
- GitLab Kubernetes Agent
    
- Deployment environments
    
- Manual production approval
    
- Protected branches, tags, and variables
    
- Smoke tests
    
- Rollout verification
    
- Automatic deployment rollback
    
- Helm release rollback
    
- Database migration safety
    
- Pipeline concurrency
    
- Environment locking
    
- Deployment evidence
    
- Argo CD fundamentals
    
- Drift detection and reconciliation
    
- A production-ready pipeline structure
    

---

# 2. CI, Continuous Delivery, and Continuous Deployment

These terms are related but not identical.

## Continuous Integration

Developers frequently merge changes, and automation verifies them.

Typical CI activities:

```text
Compile
Unit test
Static analysis
Lint
Dependency scan
Container build
Image scan
Helm lint
Manifest validation
```

The goal is:

```text
Every commit receives fast automated feedback.
```

## Continuous Delivery

Every accepted change is kept deployable, but production deployment may require approval.

```text
Commit
  ↓
Automated build and verification
  ↓
Deploy staging
  ↓
Manual production approval
  ↓
Deploy production
```

## Continuous Deployment

Every change that passes all automated gates is deployed automatically:

```text
Commit
  ↓
All checks pass
  ↓
Production deployment
```

For your first production Kubernetes pipelines, **continuous delivery with manual production approval** is usually safer than fully automatic production deployment.

---

# 3. The artifact-promotion principle

A bad pipeline does this:

```text
Development build
    ↓
Staging rebuild
    ↓
Production rebuild
```

Even if source code is unchanged, each build may produce different content because of:

- Updated package repositories
    
- Changed base images
    
- Dependency resolution
    
- Build timestamps
    
- Different build arguments
    
- Different tool versions
    
- Different network results
    

A better pipeline is:

```text
Build image once
        ↓
Record image digest
        ↓
Deploy same digest to test
        ↓
Deploy same digest to staging
        ↓
Deploy same digest to production
```

Example identity:

```text
registry.example.com/team/device-api@sha256:8d7a...
```

The digest identifies the exact image content.

---

# 4. Do not promote source branches as artifacts

Avoid this production process:

```text
Check out main
Build whatever main contains now
Deploy
```

Between approval and deployment, `main` may change.

Instead, promote one of:

```text
Immutable image digest
Signed release artifact
Versioned Helm package
Git commit SHA tied to immutable artifacts
```

A release record should contain:

```text
Source commit
Pipeline ID
Image repository
Image digest
Chart version
Helm release revision
Target environment
Deployment time
Deployer identity
```

---

# 5. Two Kubernetes deployment models

There are two major delivery models.

## Push-based deployment

The CI/CD pipeline connects to Kubernetes and runs commands such as:

```bash
kubectl apply
helm upgrade
```

Conceptually:

```text
GitLab pipeline
      ↓ pushes changes
Kubernetes API
      ↓
Cluster resources
```

GitLab supports using its Kubernetes Agent to expose a Kubernetes context inside a CI/CD pipeline, allowing pipeline jobs to run commands such as `kubectl` and `helm` against an authorized cluster. ([GitLab Docs](https://docs.gitlab.com/user/clusters/agent/ci_cd_workflow/?utm_source=chatgpt.com "Using GitLab CI/CD with a Kubernetes cluster"))

## Pull-based GitOps deployment

A controller inside or connected to the cluster watches a Git repository:

```text
Git repository
      ↓ watched by
GitOps controller
      ↓ reconciles
Kubernetes cluster
```

The CI pipeline changes Git rather than directly changing the cluster.

Argo CD uses Git repositories as the source of truth and can automatically synchronize the cluster when the desired state in Git differs from the live state. ([Argo CD](https://argo-cd.readthedocs.io/en/latest/user-guide/auto_sync/?utm_source=chatgpt.com "Automated Sync Policy - Declarative GitOps CD for Kubernetes"))

You will practice the push-based model first, then learn the GitOps model.

---

# 6. Push pipeline versus GitOps

## Push-based CI/CD

```text
Pipeline has cluster credentials
        ↓
Pipeline performs Helm upgrade
```

Advantages:

- Straightforward
    
- Easy to understand initially
    
- Deployment logic stays in pipeline
    
- Easy integration with tests and approval jobs
    

Risks:

- CI requires cluster access
    
- Compromised pipeline credentials may modify the cluster
    
- Manual cluster changes can create drift
    
- Pipeline failure may leave unclear state
    
- Deployment logic can become complex
    

## GitOps

```text
Pipeline updates desired state in Git
        ↓
Cluster controller notices Git change
        ↓
Controller applies it
```

Advantages:

- Git records desired state
    
- Cluster access can remain with the controller
    
- Drift can be detected and corrected
    
- Rollback can be a Git revert
    
- Deployment history is easier to audit
    

Risks:

- Another controller and operating model
    
- Incorrect automatic reconciliation may propagate mistakes
    
- Secret handling needs a dedicated design
    
- Database migrations still need care
    
- Teams must avoid direct manual cluster changes
    

---

# 7. Today's target pipeline

You will design this pipeline:

```text
validate
    ├── source lint
    ├── unit tests
    ├── Helm lint
    └── manifest validation

build
    └── build image once

security
    ├── dependency scan
    ├── image scan
    └── SBOM generation

publish
    ├── push commit tag
    ├── resolve digest
    └── package Helm chart

test_deploy
    ├── create temporary namespace
    ├── install Helm chart
    ├── wait for rollout
    ├── run smoke tests
    └── remove namespace

staging
    ├── deploy approved digest
    ├── wait
    └── integration test

production
    ├── manual approval
    ├── deploy same digest
    ├── verify
    └── record release
```

---

# 8. Suggested repository structure

```text
device-api/
├── src/
├── tests/
├── Dockerfile
├── .dockerignore
├── requirements.txt
├── helm/
│   └── device-monitor/
│       ├── Chart.yaml
│       ├── values.yaml
│       ├── values.schema.json
│       └── templates/
├── environments/
│   ├── development.yaml
│   ├── staging.yaml
│   └── production.yaml
├── scripts/
│   ├── smoke-test.sh
│   ├── verify-deployment.sh
│   └── create-release-manifest.sh
└── .gitlab-ci.yml
```

Keep generic defaults in:

```text
helm/device-monitor/values.yaml
```

Keep environment-specific non-secret values in:

```text
environments/staging.yaml
environments/production.yaml
```

Do not commit plaintext production passwords.

---

# 9. Environment values

Example `environments/staging.yaml`:

```yaml
api:
  replicaCount: 2

  ingress:
    enabled: true
    className: nginx
    host: api.staging.example.com

    tls:
      enabled: true
      secretName: staging-device-api-tls

config:
  appEnvironment: staging
  logLevel: INFO

database:
  enabled: false

  external:
    host: postgresql.staging.internal
    port: 5432

existingDatabaseSecret: staging-database-credentials

networkPolicy:
  enabled: true
```

Production:

```yaml
api:
  replicaCount: 3

  ingress:
    enabled: true
    className: nginx
    host: api.example.com

    tls:
      enabled: true
      secretName: production-device-api-tls

config:
  appEnvironment: production
  logLevel: INFO

database:
  enabled: false

  external:
    host: postgresql.production.internal
    port: 5432

existingDatabaseSecret: production-database-credentials

networkPolicy:
  enabled: true
```

The image digest should be injected by the release pipeline rather than manually copied.

---

# 10. GitLab pipeline fundamentals

GitLab pipelines are defined in:

```text
.gitlab-ci.yml
```

A simple pipeline:

```yaml
stages:
  - test
  - build
  - deploy

unit-test:
  stage: test
  script:
    - pytest

build-image:
  stage: build
  script:
    - docker build -t "$CI_REGISTRY_IMAGE:$CI_COMMIT_SHA" .

deploy-staging:
  stage: deploy
  script:
    - helm upgrade --install ...
```

GitLab’s CI/CD YAML defines jobs and the pipeline structure, including stages, scripts, rules, artifacts, environments, and dependencies. ([GitLab Docs](https://docs.gitlab.com/ci/yaml/?utm_source=chatgpt.com "CI/CD YAML syntax reference"))

---

# 11. Stages versus jobs

A stage is a broad phase:

```yaml
stages:
  - validate
  - build
  - security
  - test-deploy
  - deploy
```

A job performs a specific operation:

```yaml
helm-lint:
  stage: validate

unit-tests:
  stage: validate

build-image:
  stage: build
```

Jobs in the same stage may run in parallel when runners are available.

The next stage normally begins after required jobs in the previous stage succeed.

---

# 12. Use `needs` for an explicit dependency graph

Without `needs`:

```text
All validate jobs finish
    ↓
All build jobs start
```

With `needs`, a job can start as soon as its required dependencies complete.

Example:

```yaml
build-image:
  stage: build
  needs:
    - unit-tests
    - source-lint
```

A deployment job may need:

```yaml
deploy-staging:
  needs:
    - build-image
    - image-scan
    - helm-validate
```

This makes the pipeline dependency graph explicit rather than relying only on stage order.

---

# 13. Use commit SHA tags

A useful image tag is:

```text
$CI_COMMIT_SHA
```

Example:

```text
registry.example.com/team/device-api:
3d5f52a55d...
```

A shorter display tag may use:

```text
$CI_COMMIT_SHORT_SHA
```

However, the full SHA is less ambiguous.

Other useful tags:

```text
Commit SHA
Release version
Git tag
Branch-specific temporary tag
```

Avoid using only:

```text
latest
```

The final deployment should use the image digest.

---

# 14. Image naming variables

Example:

```yaml
variables:
  IMAGE_REPOSITORY: "$CI_REGISTRY_IMAGE/device-api"
  IMAGE_TAG: "$CI_COMMIT_SHA"
  CHART_PATH: "helm/device-monitor"
  RELEASE_NAME: "device-monitor"
```

The initial image reference becomes:

```text
$IMAGE_REPOSITORY:$IMAGE_TAG
```

After pushing, resolve:

```text
$IMAGE_REPOSITORY@sha256:...
```

Store that exact value for deployment jobs.

---

# 15. Pipeline outline

```yaml
stages:
  - validate
  - test
  - build
  - security
  - package
  - integration
  - deploy-staging
  - verify-staging
  - deploy-production
  - verify-production
```

This is deliberately explicit.

In smaller projects, some stages may be combined, but do not combine unrelated operations merely to shorten the file.

---

# 16. A reusable default configuration

```yaml
default:
  interruptible: true

variables:
  CHART_PATH: helm/device-monitor
  RELEASE_NAME: device-monitor
  IMAGE_REPOSITORY: "$CI_REGISTRY_IMAGE/device-api"
  IMAGE_TAG: "$CI_COMMIT_SHA"

cache:
  key:
    files:
      - requirements.txt

  paths:
    - .cache/pip
```

`interruptible: true` permits newer pipelines to cancel obsolete jobs where GitLab supports that behavior and the job is safe to interrupt.

Production deployment jobs should generally not be interrupted midway.

Override:

```yaml
deploy-production:
  interruptible: false
```

---

# 17. Source lint job

Example Python lint job:

```yaml
python-lint:
  stage: validate

  image: python:3.13-slim

  before_script:
    - python -m pip install --upgrade pip
    - pip install ruff

  script:
    - ruff check src tests

  rules:
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
    - if: '$CI_COMMIT_BRANCH'
    - if: '$CI_COMMIT_TAG'
```

For your C MQTT daemon, equivalent checks might include:

```text
Compiler warnings
clang-format
clang-tidy
cppcheck
Unit tests
Sanitizer builds
```

Use the checks appropriate to the source language.

---

# 18. Unit-test job

```yaml
unit-tests:
  stage: test

  image: python:3.13-slim

  before_script:
    - python -m pip install --upgrade pip
    - pip install -r requirements.txt
    - pip install pytest

  script:
    - pytest --junitxml=reports/unit-tests.xml

  artifacts:
    when: always

    reports:
      junit: reports/unit-tests.xml

    paths:
      - reports/

    expire_in: 7 days
```

Test reports should be preserved even when tests fail so developers can diagnose the failure.

Unit tests should not depend on production services.

---

# 19. Helm lint job

```yaml
helm-lint:
  stage: validate

  image:
    name: alpine/helm:3
    entrypoint: [""]

  script:
    - helm lint "$CHART_PATH"
    - helm lint "$CHART_PATH" -f environments/staging.yaml
    - helm lint "$CHART_PATH" -f environments/production.yaml
```

Test all supported value combinations:

```text
Internal database enabled
External database enabled
Ingress disabled
Ingress with TLS
NetworkPolicy enabled
```

One successful default rendering does not prove every conditional path works.

---

# 20. Render manifests in CI

```yaml
helm-render:
  stage: validate

  image:
    name: alpine/helm:3
    entrypoint: [""]

  script:
    - mkdir -p rendered

    - >
      helm template staging "$CHART_PATH"
      --namespace device-monitor-staging
      -f environments/staging.yaml
      --set-string api.image.digest=sha256:placeholder
      > rendered/staging.yaml

    - >
      helm template production "$CHART_PATH"
      --namespace device-monitor-production
      -f environments/production.yaml
      --set-string api.image.digest=sha256:placeholder
      > rendered/production.yaml

  artifacts:
    paths:
      - rendered/

    expire_in: 7 days
```

Rendered manifests are useful review artifacts.

They show exactly what the templates generated.

---

# 21. Schema and policy validation

A stronger validation stage should inspect the rendered Kubernetes YAML.

Possible checks include:

```text
Kubernetes schema validation
Pod Security compatibility
Required resources
Prohibited privileged mode
No hostPath
No latest tags
Resource requests and limits present
Approved registries only
```

When a validation cluster is available:

```bash
helm template ... \
  | kubectl apply \
      --dry-run=server \
      -f -
```

Server-side dry-run can exercise Kubernetes schema validation and configured admission policies without persisting resources.

Example:

```yaml
kubernetes-server-validation:
  stage: validate

  image:
    name: alpine/k8s:1.35.0
    entrypoint: [""]

  script:
    - >
      helm template validation "$CHART_PATH"
      --namespace validation
      -f environments/staging.yaml
      --set-string api.image.digest=sha256:placeholder
      |
      kubectl apply
      --dry-run=server
      -f -
```

Pin the tool image version used by your organization rather than relying on a floating tag.

---

# 22. Build the container image once

A simple Docker-in-Docker example:

```yaml
build-image:
  stage: build

  image: docker:stable

  services:
    - name: docker:dind
      command:
        - --tls=false

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  before_script:
    - >
      printf '%s' "$CI_REGISTRY_PASSWORD"
      |
      docker login
      --username "$CI_REGISTRY_USER"
      --password-stdin
      "$CI_REGISTRY"

  script:
    - >
      docker build
      --pull
      --tag "$IMAGE_REPOSITORY:$IMAGE_TAG"
      .

    - docker push "$IMAGE_REPOSITORY:$IMAGE_TAG"
```

This is easy to understand but requires a privileged Docker runner in many configurations.

Alternative builders include:

- BuildKit
    
- Rootless BuildKit
    
- Kaniko
    
- Buildah
    
- Cloud-native registry builders
    

Choose a builder compatible with your runner security policy.

---

# 23. Security implications of Docker-in-Docker

A privileged CI runner can be highly sensitive.

A malicious build may potentially affect:

- Runner host
    
- Other jobs
    
- Cached credentials
    
- Registry credentials
    
- Build infrastructure
    

Safer options include:

- Ephemeral isolated runners
    
- Rootless builders
    
- Dedicated runners for trusted branches
    
- No shared production credentials in untrusted pipelines
    
- Protected release jobs
    
- Network isolation
    
- Short-lived credentials
    

Do not allow unreviewed fork pipelines to access production registry or cluster credentials.

---

# 24. Generate an SBOM

An SBOM records components in the image:

```text
Operating-system packages
Python packages
Shared libraries
Application metadata
Licenses
Versions
```

Conceptual job:

```yaml
generate-sbom:
  stage: security

  image:
    name: anchore/syft:latest
    entrypoint: [""]

  needs:
    - build-image

  script:
    - >
      syft
      "$IMAGE_REPOSITORY:$IMAGE_TAG"
      -o cyclonedx-json
      > reports/sbom.cdx.json

  artifacts:
    paths:
      - reports/sbom.cdx.json

    expire_in: 30 days
```

Pin the tool image by approved version or digest in a real pipeline.

The SBOM does not prove the image is safe; it provides inventory for vulnerability and license analysis.

---

# 25. Scan the image

Conceptual example:

```yaml
image-scan:
  stage: security

  image:
    name: aquasec/trivy:latest
    entrypoint: [""]

  needs:
    - build-image

  script:
    - >
      trivy image
      --exit-code 1
      --severity CRITICAL
      --ignore-unfixed
      "$IMAGE_REPOSITORY:$IMAGE_TAG"
```

Your policy might be:

```text
Critical vulnerabilities:
fail pipeline

High vulnerabilities:
review or time-bound exception

Medium vulnerabilities:
track and prioritize
```

Do not blindly ignore all vulnerabilities with no owner or expiry date.

Scanner findings require context:

- Is the vulnerable component reachable?
    
- Is a fix available?
    
- Is it used at runtime?
    
- Is the base image supported?
    
- Does compensating control exist?
    

---

# 26. Resolve the pushed image digest

After push, obtain the registry digest.

A Docker-based example:

```bash
docker buildx imagetools inspect \
  "$IMAGE_REPOSITORY:$IMAGE_TAG"
```

Or inspect a registry response using an approved registry tool.

The result should identify:

```text
sha256:...
```

Store it in a dotenv artifact:

```yaml
resolve-image-digest:
  stage: package

  image: docker:stable

  services:
    - name: docker:dind
      command:
        - --tls=false

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  needs:
    - build-image

  before_script:
    - >
      printf '%s' "$CI_REGISTRY_PASSWORD"
      |
      docker login
      --username "$CI_REGISTRY_USER"
      --password-stdin
      "$CI_REGISTRY"

  script:
    - >
      IMAGE_DIGEST="$(
        docker buildx imagetools inspect
        "$IMAGE_REPOSITORY:$IMAGE_TAG"
        --format '{{json .Manifest.Digest}}'
        |
        tr -d '"'
      )"

    - test -n "$IMAGE_DIGEST"
    - printf 'IMAGE_DIGEST=%s\n' "$IMAGE_DIGEST" > image.env
    - printf 'IMAGE_REFERENCE=%s@%s\n' "$IMAGE_REPOSITORY" "$IMAGE_DIGEST" >> image.env

  artifacts:
    reports:
      dotenv: image.env

    paths:
      - image.env
```

Verify the exact syntax against the builder version used by your runner.

---

# 27. Why use a dotenv artifact?

A downstream job receives:

```text
IMAGE_DIGEST=sha256:...
IMAGE_REFERENCE=registry.example.com/team/device-api@sha256:...
```

The deployment job does not search for:

```text
latest image
most recent image
image for branch
```

It deploys the exact output from the build pipeline.

This establishes traceability:

```text
Pipeline
→ digest
→ environment
```

---

# 28. Package the Helm chart

```yaml
package-chart:
  stage: package

  image:
    name: alpine/helm:3
    entrypoint: [""]

  needs:
    - helm-lint
    - helm-render

  script:
    - mkdir -p packages
    - helm dependency build "$CHART_PATH"
    - helm package "$CHART_PATH" --destination packages

  artifacts:
    paths:
      - packages/

    expire_in: 30 days
```

The chart package should have its own version from:

```text
Chart.yaml
```

The image digest remains a deployment value.

---

# 29. Temporary integration environment

For each merge request or selected branch pipeline, create a temporary namespace:

```text
device-monitor-ci-12345
```

Use a predictable but unique name:

```yaml
variables:
  TEST_NAMESPACE: "device-monitor-ci-$CI_PIPELINE_ID"
```

The lifecycle:

```text
Create namespace
    ↓
Install chart
    ↓
Wait for resources
    ↓
Run migrations
    ↓
Run smoke tests
    ↓
Collect evidence
    ↓
Delete namespace
```

This catches failures that lint and unit tests cannot detect.

---

# 30. Integration deployment job

```yaml
deploy-integration:
  stage: integration

  image:
    name: alpine/helm:3
    entrypoint: [""]

  needs:
    - resolve-image-digest
    - package-chart
    - image-scan

  variables:
    TEST_NAMESPACE: "device-monitor-ci-$CI_PIPELINE_ID"

  script:
    - kubectl create namespace "$TEST_NAMESPACE"

    - >
      helm upgrade
      --install
      device-monitor
      "$CHART_PATH"
      --namespace "$TEST_NAMESPACE"
      -f values-development.yaml
      --set-string api.image.repository="$IMAGE_REPOSITORY"
      --set-string api.image.tag=""
      --set-string api.image.digest="$IMAGE_DIGEST"
      --atomic
      --wait
      --timeout 10m

  after_script:
    - kubectl get all --namespace "$TEST_NAMESPACE" || true
    - kubectl get events --namespace "$TEST_NAMESPACE" || true
```

Helm upgrades a release from a chart and supplied values. Using `--atomic` requests rollback behavior if the upgrade fails, while waiting for the release operation to complete. ([Helm](https://helm.sh/docs/helm/helm_upgrade/?utm_source=chatgpt.com "helm upgrade"))

---

# 31. Do not immediately delete failed evidence

If integration deployment fails, you need:

```text
Pod state
Events
Logs
Rendered Helm manifest
Release status
Failed Job logs
Image pull errors
Probe failures
```

Instead of unconditional immediate deletion, collect evidence first:

```yaml
after_script:
  - mkdir -p diagnostics

  - >
    kubectl get all
    --namespace "$TEST_NAMESPACE"
    -o wide
    > diagnostics/resources.txt
    || true

  - >
    kubectl get events
    --namespace "$TEST_NAMESPACE"
    --sort-by=.metadata.creationTimestamp
    > diagnostics/events.txt
    || true

  - >
    kubectl logs
    --namespace "$TEST_NAMESPACE"
    --all-containers=true
    --prefix=true
    --tail=500
    -l app.kubernetes.io/instance=device-monitor
    > diagnostics/logs.txt
    || true

  - >
    helm get all
    device-monitor
    --namespace "$TEST_NAMESPACE"
    > diagnostics/helm-release.txt
    || true
```

Publish diagnostics as job artifacts.

Then clean up in a separate `when: always` job.

---

# 32. Integration smoke test

Create `scripts/smoke-test.sh`:

```bash
#!/usr/bin/env bash

set -Eeuo pipefail

BASE_URL="${1:?Usage: smoke-test.sh BASE_URL}"

echo "Checking health endpoint..."

curl \
  --fail \
  --silent \
  --show-error \
  --retry 20 \
  --retry-delay 3 \
  --retry-connrefused \
  "${BASE_URL}/health"

echo
echo "Checking device list..."

curl \
  --fail \
  --silent \
  --show-error \
  "${BASE_URL}/api/devices" \
  >/dev/null

echo "Smoke test passed."
```

Make executable:

```bash
chmod 750 scripts/smoke-test.sh
```

A smoke test verifies a small number of critical operations rather than every edge case.

---

# 33. Test through the Kubernetes Service

You can run a Kubernetes test Pod:

```yaml
apiVersion: v1
kind: Pod

metadata:
  name: smoke-test

spec:
  restartPolicy: Never

  containers:
    - name: smoke-test
      image: curlimages/curl:8.12.1

      command:
        - sh
        - -c
        - |
          curl \
            --fail \
            --retry 20 \
            --retry-delay 3 \
            http://device-monitor-api/health
```

Or use the Helm test you created on Day 27:

```bash
helm test \
  device-monitor \
  --namespace "$TEST_NAMESPACE" \
  --logs
```

Testing through the Service validates:

```text
Service selector
Pod readiness
Pod networking
Application response
```

---

# 34. Verify the exact image running

After deployment:

```bash
kubectl get deployment \
  --namespace "$TEST_NAMESPACE" \
  device-monitor-api \
  -o jsonpath='{.spec.template.spec.containers[0].image}'
```

Expected:

```text
registry.example.com/team/device-api@sha256:...
```

Then inspect actual Pods:

```bash
kubectl get pods \
  --namespace "$TEST_NAMESPACE" \
  -l app.kubernetes.io/component=api \
  -o jsonpath='{range .items[*]}{.metadata.name}{"\t"}{.status.containerStatuses[0].imageID}{"\n"}{end}'
```

Compare the runtime image IDs to the expected digest.

---

# 35. Clean up the temporary namespace

Create a dedicated cleanup job:

```yaml
cleanup-integration:
  stage: integration

  image:
    name: alpine/k8s:1.35.0
    entrypoint: [""]

  variables:
    TEST_NAMESPACE: "device-monitor-ci-$CI_PIPELINE_ID"

  script:
    - kubectl delete namespace "$TEST_NAMESPACE" --wait=false

  when: always

  needs:
    - job: deploy-integration
      optional: true
    - job: integration-smoke-test
      optional: true
```

Namespace deletion can take time, so production CI systems may also run a scheduled cleanup process for abandoned temporary namespaces.

Use labels such as:

```text
ci.gitlab.com/pipeline-id
environment=temporary
expires-at
```

to identify stale resources.

---

# 36. Staging deployment

Staging should use the same artifact digest tested in integration.

```yaml
deploy-staging:
  stage: deploy-staging

  image:
    name: alpine/helm:3
    entrypoint: [""]

  needs:
    - resolve-image-digest
    - integration-smoke-test

  environment:
    name: staging
    url: https://api.staging.example.com

  resource_group: device-monitor-staging

  script:
    - kubectl config use-context "$KUBE_CONTEXT_STAGING"

    - >
      helm upgrade
      --install
      "$RELEASE_NAME"
      "$CHART_PATH"
      --namespace device-monitor-staging
      --create-namespace
      -f environments/staging.yaml
      --set-string api.image.repository="$IMAGE_REPOSITORY"
      --set-string api.image.tag=""
      --set-string api.image.digest="$IMAGE_DIGEST"
      --atomic
      --wait
      --timeout 10m
```

A GitLab Kubernetes Agent configuration can provide authorized Kubernetes contexts to CI jobs, avoiding the need to manually distribute static administrator kubeconfig files. ([GitLab Docs](https://docs.gitlab.com/user/clusters/agent/ci_cd_workflow/?utm_source=chatgpt.com "Using GitLab CI/CD with a Kubernetes cluster"))

---

# 37. Why use a resource group?

Two pipelines may attempt to deploy staging simultaneously:

```text
Pipeline A deploys version A
Pipeline B deploys version B
```

Without serialization, operations may overlap.

GitLab resource groups can be used to ensure only one job operates on a particular deployment environment at a time.

Conceptually:

```yaml
resource_group: device-monitor-staging
```

This creates an environment deployment lock.

Production should have a separate resource group:

```yaml
resource_group: device-monitor-production
```

---

# 38. Verify staging

```yaml
verify-staging:
  stage: verify-staging

  image: curlimages/curl:8.12.1

  needs:
    - deploy-staging

  script:
    - >
      curl
      --fail
      --retry 30
      --retry-delay 5
      --retry-all-errors
      https://api.staging.example.com/health

    - >
      curl
      --fail
      https://api.staging.example.com/api/devices
      >/dev/null
```

Additional staging verification may include:

```text
Database write/read test
MQTT message processing
Authentication
NetworkPolicy behavior
Metrics endpoint
Log availability
Migration completion
Certificate validity
```

Use synthetic test data that can be clearly identified and safely cleaned up.

---

# 39. Production approval

Production should deploy only from an approved source:

```text
Protected main branch
or
Signed release tag
```

Example rules:

```yaml
deploy-production:
  rules:
    - if: '$CI_COMMIT_TAG =~ /^v\d+\.\d+\.\d+$/'
      when: manual

    - when: never
```

This means:

```text
Only semantic release tags
+
manual approval
→ production deployment job available
```

Also protect:

- Release tags
    
- Production environment
    
- CI/CD variables
    
- Deployment runners
    
- Cluster Agent authorization
    

---

# 40. Production deployment job

```yaml
deploy-production:
  stage: deploy-production

  image:
    name: alpine/helm:3
    entrypoint: [""]

  needs:
    - resolve-image-digest
    - verify-staging

  environment:
    name: production
    url: https://api.example.com

  resource_group: device-monitor-production

  interruptible: false

  rules:
    - if: '$CI_COMMIT_TAG =~ /^v\d+\.\d+\.\d+$/'
      when: manual

    - when: never

  script:
    - kubectl config use-context "$KUBE_CONTEXT_PRODUCTION"

    - >
      helm upgrade
      --install
      "$RELEASE_NAME"
      "$CHART_PATH"
      --namespace device-monitor-production
      --create-namespace
      -f environments/production.yaml
      --set-string api.image.repository="$IMAGE_REPOSITORY"
      --set-string api.image.tag=""
      --set-string api.image.digest="$IMAGE_DIGEST"
      --atomic
      --wait
      --timeout 15m
```

Production uses the same `IMAGE_DIGEST` that passed the previous stages.

---

# 41. Verify Kubernetes rollout explicitly

Although Helm waits for relevant resources, add explicit workload verification where useful:

```bash
kubectl rollout status \
  deployment/device-monitor-api \
  --namespace device-monitor-production \
  --timeout=5m
```

`kubectl rollout status` watches the latest rollout until it completes or the configured timeout or other failure condition is reached. ([Kubernetes](https://kubernetes.io/docs/reference/kubectl/generated/kubectl_rollout/kubectl_rollout_status/?utm_source=chatgpt.com "kubectl rollout status"))

Check StatefulSet where applicable:

```bash
kubectl rollout status \
  statefulset/device-monitor-database \
  --namespace device-monitor-production \
  --timeout=10m
```

---

# 42. Production smoke tests

```yaml
verify-production:
  stage: verify-production

  image: curlimages/curl:8.12.1

  needs:
    - deploy-production

  environment:
    name: production
    url: https://api.example.com

  resource_group: device-monitor-production

  script:
    - >
      curl
      --fail
      --silent
      --show-error
      --retry 30
      --retry-delay 5
      --retry-all-errors
      https://api.example.com/health

    - >
      curl
      --fail
      --silent
      --show-error
      https://api.example.com/api/devices
      >/dev/null
```

Production smoke tests must be safe and idempotent.

Avoid tests that:

- Delete real records
    
- Send real customer notifications
    
- Trigger firmware updates
    
- Create uncontrolled MQTT commands
    
- Modify financial data
    
- Restore databases
    

---

# 43. Deployment manifest artifact

Create `scripts/create-release-manifest.sh`:

```bash
#!/usr/bin/env bash

set -Eeuo pipefail

OUTPUT="${1:-release-manifest.txt}"

cat > "$OUTPUT" <<EOF
deployment_time=$(date -u +%Y-%m-%dT%H:%M:%SZ)
git_commit=${CI_COMMIT_SHA:-unknown}
git_tag=${CI_COMMIT_TAG:-}
pipeline_id=${CI_PIPELINE_ID:-unknown}
pipeline_url=${CI_PIPELINE_URL:-unknown}
image_repository=${IMAGE_REPOSITORY:-unknown}
image_digest=${IMAGE_DIGEST:-unknown}
chart_version=$(awk '/^version:/ {print $2}' helm/device-monitor/Chart.yaml)
app_version=$(awk '/^appVersion:/ {gsub(/"/, "", $2); print $2}' helm/device-monitor/Chart.yaml)
environment=${CI_ENVIRONMENT_NAME:-unknown}
deployment_user=${GITLAB_USER_LOGIN:-unknown}
EOF
```

Publish it as a long-lived release artifact.

---

# 44. Rollback strategies

There are several rollback layers.

## Kubernetes Deployment rollback

```bash
kubectl rollout undo \
  deployment/device-monitor-api \
  --namespace device-monitor-production
```

Kubernetes Deployments preserve rollout revisions and support undoing to a previous revision. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/controllers/deployment/?utm_source=chatgpt.com "Deployments"))

## Helm release rollback

```bash
helm rollback \
  device-monitor \
  PREVIOUS_REVISION \
  --namespace device-monitor-production \
  --wait \
  --timeout 10m
```

## GitOps rollback

```text
Revert desired-state commit
    ↓
GitOps controller reconciles previous version
```

## Artifact redeployment

Deploy the previously approved image digest and chart version.

Use the rollback mechanism that corresponds to the deployment ownership model.

---

# 45. Avoid mixing rollback ownership

Do not simultaneously use:

```text
Helm CI deployment
Argo CD automatic reconciliation
Manual kubectl edits
```

on the same workload without clear ownership.

Example failure:

```text
Pipeline rolls back Helm release
        ↓
Argo CD sees difference from Git
        ↓
Argo CD reapplies newer version
```

Select a single source of desired state.

For GitOps-managed resources:

```text
Change or revert Git
```

For CI-managed Helm releases:

```text
Use Helm pipeline or approved Helm rollback
```

---

# 46. Automated rollback after failed smoke test

`helm --atomic` handles some release failures, but a release may become Kubernetes-ready while application smoke tests still fail.

A deployment script can capture the previous Helm revision:

```bash
PREVIOUS_REVISION="$(
  helm history "$RELEASE_NAME" \
    --namespace "$NAMESPACE" \
    --output json \
  |
  jq -r 'map(select(.status == "deployed")) | last | .revision'
)"
```

Deploy:

```bash
helm upgrade \
  --install \
  "$RELEASE_NAME" \
  "$CHART_PATH" \
  ...
```

Run smoke test.

On failure:

```bash
helm rollback \
  "$RELEASE_NAME" \
  "$PREVIOUS_REVISION" \
  --namespace "$NAMESPACE" \
  --wait \
  --timeout 10m
```

Then verify the rollback itself.

Do not claim rollback succeeded until the old application is healthy again.

---

# 47. Safer deployment script

```bash
#!/usr/bin/env bash

set -Eeuo pipefail

RELEASE_NAME="${RELEASE_NAME:?}"
NAMESPACE="${NAMESPACE:?}"
CHART_PATH="${CHART_PATH:?}"
VALUES_FILE="${VALUES_FILE:?}"
IMAGE_REPOSITORY="${IMAGE_REPOSITORY:?}"
IMAGE_DIGEST="${IMAGE_DIGEST:?}"
HEALTH_URL="${HEALTH_URL:?}"

previous_revision="$(
  helm history "$RELEASE_NAME" \
    --namespace "$NAMESPACE" \
    --output json 2>/dev/null \
  |
  jq -r '
    map(select(.status == "deployed"))
    | last
    | .revision // empty
  '
)"

deploy_failed=0

helm upgrade \
  --install \
  "$RELEASE_NAME" \
  "$CHART_PATH" \
  --namespace "$NAMESPACE" \
  --create-namespace \
  --values "$VALUES_FILE" \
  --set-string api.image.repository="$IMAGE_REPOSITORY" \
  --set-string api.image.tag="" \
  --set-string api.image.digest="$IMAGE_DIGEST" \
  --wait \
  --timeout 10m \
  || deploy_failed=1

if (( deploy_failed != 0 )); then
    echo "Helm deployment failed." >&2
    exit 1
fi

if ! curl \
    --fail \
    --silent \
    --show-error \
    --retry 20 \
    --retry-delay 5 \
    --retry-all-errors \
    "$HEALTH_URL"; then

    echo "Smoke test failed." >&2

    if [[ -n "$previous_revision" ]]; then
        echo "Rolling back to Helm revision ${previous_revision}..."

        helm rollback \
          "$RELEASE_NAME" \
          "$previous_revision" \
          --namespace "$NAMESPACE" \
          --wait \
          --timeout 10m

        curl \
          --fail \
          --silent \
          --show-error \
          --retry 20 \
          --retry-delay 5 \
          --retry-all-errors \
          "$HEALTH_URL"

        echo "Rollback verified."
    fi

    exit 1
fi

echo "Deployment verified."
```

This still does not address incompatible database migrations.

---

# 48. Database migrations in CI/CD

A deployment pipeline must treat schema migration as a separate risk.

Potential sequence:

```text
Backup or verified recovery point
        ↓
Run backward-compatible migration
        ↓
Deploy new application
        ↓
Verify
        ↓
Later remove obsolete schema
```

Avoid:

```text
Drop old columns
    ↓
Deploy new application
    ↓
Application deployment fails
    ↓
Roll back old application
    ↓
Old application cannot use database
```

Helm or Kubernetes rollback changes workload definitions, not database history.

---

# 49. Expand-and-contract migrations

A safer pattern:

## Release A — Expand

```text
Add new nullable column
Keep old column
```

Both old and new applications can run.

## Release B — Migrate

```text
New application writes both fields
Backfill existing data
```

## Release C — Switch

```text
Application reads new field
Old field remains temporarily
```

## Release D — Contract

```text
Remove obsolete field
```

This supports rolling updates and application rollback across releases.

---

# 50. Migration job design

A migration job should:

- Use an exact image digest
    
- Be idempotent where possible
    
- Acquire a migration lock
    
- Record applied migrations
    
- Fail clearly
    
- Produce logs
    
- Have a deadline
    
- Avoid concurrent execution
    
- Be compatible with old and new application versions
    

Example Kubernetes Job settings:

```yaml
spec:
  backoffLimit: 1
  activeDeadlineSeconds: 600

  template:
    spec:
      restartPolicy: Never
```

Do not let several parallel pipelines run the same migration simultaneously.

Use an environment resource group and application-level migration locking.

---

# 51. Separate build and deployment credentials

The build job needs:

```text
Read source
Pull base images
Push application image
Push chart package
```

The deployment job needs:

```text
Read application image
Read chart
Modify resources in one namespace
Read rollout state
Read logs where required
```

It should not automatically need:

```text
Push images
Delete registry projects
Modify cluster-wide RBAC
Manage nodes
Read all Secrets
Delete namespaces outside its environment
```

Use separate credentials and service accounts.

---

# 52. Protect CI/CD variables

Sensitive variables may include:

```text
Registry password
Signing key
Kubernetes Agent authorization
External secret-manager credentials
Production API test token
```

Configure them as appropriate:

```text
Masked
Protected
Environment-scoped
Short-lived where possible
```

Do not print them:

```bash
set -x
```

should not be enabled around secret-handling commands.

Use password input through standard input where supported.

---

# 53. Environment-scoped variables

A variable for production should not automatically be visible to staging or review environments.

Conceptually:

```text
STAGING_TEST_TOKEN
→ staging only

PRODUCTION_TEST_TOKEN
→ production only
```

This limits the impact of a compromised lower-environment job.

Similarly, use different:

- Database credentials
    
- TLS certificates
    
- Kubernetes identities
    
- External API tokens
    
- MQTT accounts
    

across environments.

---

# 54. Protected runners

Production deployment jobs should run only on trusted runners.

A production runner should be:

- Dedicated or strongly isolated
    
- Restricted to protected branches or tags
    
- Patched
    
- Monitored
    
- Free of untrusted shared workloads
    
- Configured with minimal credentials
    
- Ephemeral where practical
    

Do not run production deployments on an untrusted developer-controlled shared runner.

---

# 55. Deployment environments

GitLab environments give deployments a named target:

```yaml
environment:
  name: staging
  url: https://api.staging.example.com
```

Production:

```yaml
environment:
  name: production
  url: https://api.example.com
```

This helps associate:

- Job
    
- Commit
    
- Pipeline
    
- Deployment
    
- Environment URL
    
- Deployment history
    

Use consistent environment names.

---

# 56. Review environments

A merge request can receive a temporary environment:

```text
review/feature-123
```

Example:

```yaml
environment:
  name: "review/$CI_COMMIT_REF_SLUG"
  url: "https://$CI_ENVIRONMENT_SLUG.review.example.com"
  on_stop: stop-review
```

The pipeline:

```text
Creates namespace
Deploys application
Provides temporary URL
Runs tests
Deletes environment after merge or expiry
```

Review environments are useful but consume:

- Pods
    
- Storage
    
- Ingress hostnames
    
- Certificates
    
- Database resources
    

Use quotas and expiration policies.

---

# 57. Branch and tag pipeline rules

A useful policy:

```text
Merge request:
lint, unit test, render, build, scan, integration

Main branch:
all checks + deploy staging

Release tag:
all checks + staging verification + manual production
```

Example:

```yaml
workflow:
  rules:
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
    - if: '$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH'
    - if: '$CI_COMMIT_TAG'
```

Per-job rules refine this behavior.

---

# 58. Full simplified `.gitlab-ci.yml`

```yaml
stages:
  - validate
  - test
  - build
  - security
  - integration
  - staging
  - production

variables:
  CHART_PATH: helm/device-monitor
  RELEASE_NAME: device-monitor
  IMAGE_REPOSITORY: "$CI_REGISTRY_IMAGE/device-api"
  IMAGE_TAG: "$CI_COMMIT_SHA"

workflow:
  rules:
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
    - if: '$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH'
    - if: '$CI_COMMIT_TAG'

python-lint:
  stage: validate
  image: python:3.13-slim

  script:
    - pip install ruff
    - ruff check src tests

unit-tests:
  stage: test
  image: python:3.13-slim

  script:
    - pip install -r requirements.txt
    - pip install pytest
    - pytest

helm-lint:
  stage: validate

  image:
    name: alpine/helm:3
    entrypoint: [""]

  script:
    - helm lint "$CHART_PATH"
    - helm lint "$CHART_PATH" -f environments/staging.yaml
    - helm lint "$CHART_PATH" -f environments/production.yaml

build-image:
  stage: build

  image: docker:stable

  services:
    - name: docker:dind
      command: ["--tls=false"]

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  before_script:
    - >
      printf '%s' "$CI_REGISTRY_PASSWORD"
      |
      docker login
      -u "$CI_REGISTRY_USER"
      --password-stdin
      "$CI_REGISTRY"

  script:
    - docker build --pull -t "$IMAGE_REPOSITORY:$IMAGE_TAG" .
    - docker push "$IMAGE_REPOSITORY:$IMAGE_TAG"

image-scan:
  stage: security

  image:
    name: aquasec/trivy:latest
    entrypoint: [""]

  needs:
    - build-image

  script:
    - >
      trivy image
      --exit-code 1
      --severity CRITICAL
      "$IMAGE_REPOSITORY:$IMAGE_TAG"

resolve-image-digest:
  stage: security

  image: docker:stable

  services:
    - name: docker:dind
      command: ["--tls=false"]

  variables:
    DOCKER_HOST: tcp://docker:2375
    DOCKER_TLS_CERTDIR: ""

  needs:
    - build-image
    - image-scan

  before_script:
    - >
      printf '%s' "$CI_REGISTRY_PASSWORD"
      |
      docker login
      -u "$CI_REGISTRY_USER"
      --password-stdin
      "$CI_REGISTRY"

  script:
    - |
      IMAGE_DIGEST="$(
        docker buildx imagetools inspect \
          "$IMAGE_REPOSITORY:$IMAGE_TAG" \
          --format '{{json .Manifest.Digest}}' \
        | tr -d '"'
      )"

      test -n "$IMAGE_DIGEST"

      {
        printf 'IMAGE_DIGEST=%s\n' "$IMAGE_DIGEST"
        printf 'IMAGE_REFERENCE=%s@%s\n' \
          "$IMAGE_REPOSITORY" \
          "$IMAGE_DIGEST"
      } > image.env

  artifacts:
    reports:
      dotenv: image.env

deploy-integration:
  stage: integration

  image:
    name: alpine/helm:3
    entrypoint: [""]

  needs:
    - resolve-image-digest
    - helm-lint
    - unit-tests

  variables:
    TEST_NAMESPACE: "device-monitor-ci-$CI_PIPELINE_ID"

  script:
    - kubectl create namespace "$TEST_NAMESPACE"

    - >
      helm upgrade
      --install
      "$RELEASE_NAME"
      "$CHART_PATH"
      --namespace "$TEST_NAMESPACE"
      -f values-development.yaml
      --set-string api.image.repository="$IMAGE_REPOSITORY"
      --set-string api.image.tag=""
      --set-string api.image.digest="$IMAGE_DIGEST"
      --atomic
      --wait
      --timeout 10m

    - >
      helm test
      "$RELEASE_NAME"
      --namespace "$TEST_NAMESPACE"
      --logs

  after_script:
    - kubectl get all -n "$TEST_NAMESPACE" || true
    - kubectl get events -n "$TEST_NAMESPACE" || true
    - kubectl delete namespace "$TEST_NAMESPACE" --wait=false || true

deploy-staging:
  stage: staging

  image:
    name: alpine/helm:3
    entrypoint: [""]

  needs:
    - deploy-integration
    - resolve-image-digest

  resource_group: device-monitor-staging

  environment:
    name: staging
    url: https://api.staging.example.com

  rules:
    - if: '$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH'
    - if: '$CI_COMMIT_TAG'

  script:
    - kubectl config use-context "$KUBE_CONTEXT_STAGING"

    - >
      helm upgrade
      --install
      "$RELEASE_NAME"
      "$CHART_PATH"
      --namespace device-monitor-staging
      --create-namespace
      -f environments/staging.yaml
      --set-string api.image.repository="$IMAGE_REPOSITORY"
      --set-string api.image.tag=""
      --set-string api.image.digest="$IMAGE_DIGEST"
      --atomic
      --wait
      --timeout 10m

    - >
      curl
      --fail
      --retry 30
      --retry-delay 5
      --retry-all-errors
      https://api.staging.example.com/health

deploy-production:
  stage: production

  image:
    name: alpine/helm:3
    entrypoint: [""]

  needs:
    - deploy-staging
    - resolve-image-digest

  resource_group: device-monitor-production

  interruptible: false

  environment:
    name: production
    url: https://api.example.com

  rules:
    - if: '$CI_COMMIT_TAG =~ /^v\d+\.\d+\.\d+$/'
      when: manual

    - when: never

  script:
    - kubectl config use-context "$KUBE_CONTEXT_PRODUCTION"

    - >
      helm upgrade
      --install
      "$RELEASE_NAME"
      "$CHART_PATH"
      --namespace device-monitor-production
      --create-namespace
      -f environments/production.yaml
      --set-string api.image.repository="$IMAGE_REPOSITORY"
      --set-string api.image.tag=""
      --set-string api.image.digest="$IMAGE_DIGEST"
      --atomic
      --wait
      --timeout 15m

    - >
      curl
      --fail
      --retry 30
      --retry-delay 5
      --retry-all-errors
      https://api.example.com/health
```

This is an educational baseline. Pin all CI images and adapt authentication to your actual GitLab and Kubernetes infrastructure.

---

# 59. Connect GitLab to Kubernetes safely

GitLab’s Kubernetes Agent can provide CI jobs with a Kubernetes context and authorized access. This avoids exposing the Kubernetes API publicly solely for CI and allows cluster access to be controlled through the Agent configuration. ([GitLab Docs](https://docs.gitlab.com/user/clusters/agent/ci_cd_workflow/?utm_source=chatgpt.com "Using GitLab CI/CD with a Kubernetes cluster"))

The general process is:

```text
Register Agent in GitLab
        ↓
Install Agent in Kubernetes
        ↓
Authorize project or group
        ↓
Pipeline receives Kubernetes context
        ↓
Deployment job runs kubectl or Helm
```

The exact installation and authorization format depends on your GitLab version, so use the current GitLab documentation for your self-managed server.

---

# 60. Agent permissions still matter

The Agent does not eliminate Kubernetes authorization.

The pipeline identity should be restricted by:

- Agent project/group authorization
    
- Kubernetes RBAC
    
- Namespace scoping
    
- Protected pipelines
    
- Environment protection
    

A staging deployment identity should not automatically be able to:

```text
Modify production namespace
Read production Secrets
Create ClusterRoleBindings
Delete nodes
Change admission policies
```

Apply the Day 26 least-privilege principles.

---

# 61. GitOps fundamentals

In GitOps, the desired environment state is stored in Git.

Example configuration repository:

```text
device-platform-config/
├── applications/
│   ├── staging/
│   │   └── device-monitor-values.yaml
│   └── production/
│       └── device-monitor-values.yaml
├── charts/
└── clusters/
```

The CI build pipeline does this:

```text
Build and verify image
        ↓
Produce digest
        ↓
Update staging values in config repository
        ↓
Commit and push
```

A GitOps controller detects the commit and applies it.

Argo CD supports plain YAML, Helm charts, Kustomize, Jsonnet, and custom configuration-management plugins as desired-state sources. ([Argo CD](https://argo-cd.readthedocs.io/en/stable/?utm_source=chatgpt.com "Argo CD - Read the Docs"))

---

# 62. GitOps image promotion

Staging commit:

```yaml
api:
  image:
    repository: registry.example.com/team/device-api
    digest: sha256:abc123...
```

After staging approval, production pull request changes:

```yaml
api:
  image:
    digest: sha256:abc123...
```

The digest is identical.

Promotion means:

```text
Change desired environment reference
```

not:

```text
Rebuild application
```

---

# 63. Basic Argo CD Application

Conceptual example:

```yaml
apiVersion: argoproj.io/v1alpha1
kind: Application

metadata:
  name: device-monitor-staging
  namespace: argocd

spec:
  project: default

  source:
    repoURL: https://git.example.com/platform/device-platform-config.git
    targetRevision: main
    path: applications/staging

    helm:
      valueFiles:
        - values.yaml

  destination:
    server: https://kubernetes.default.svc
    namespace: device-monitor-staging

  syncPolicy:
    automated:
      enabled: true
      prune: true
      selfHeal: true

    syncOptions:
      - CreateNamespace=true
```

Argo CD automatic sync can detect Git-to-cluster differences and synchronize automatically. The pipeline can therefore deploy by changing Git rather than directly calling the Argo CD API or Kubernetes API. ([Argo CD](https://argo-cd.readthedocs.io/en/latest/user-guide/auto_sync/?utm_source=chatgpt.com "Automated Sync Policy - Declarative GitOps CD for Kubernetes"))

---

# 64. Understand GitOps self-healing

Suppose Git declares:

```text
replicas: 3
```

An administrator manually changes the Deployment:

```bash
kubectl scale deployment device-api --replicas=1
```

Argo CD detects:

```text
Git desired state: 3
Live cluster state: 1
```

With self-healing enabled, it can restore:

```text
replicas: 3
```

This is drift remediation.

Manual changes to GitOps-managed resources are temporary unless the desired state in Git is updated.

---

# 65. Understand pruning

Suppose a Service exists in the cluster because it was previously in Git.

The Service is removed from Git.

With pruning enabled:

```text
Resource no longer desired
    ↓
GitOps controller removes live resource
```

Pruning is powerful and potentially destructive.

Be especially careful with:

- Namespaces
    
- PVCs
    
- Stateful workloads
    
- Custom resources
    
- Shared Services
    
- Resources created outside GitOps
    

Use retention policies and project boundaries.

---

# 66. GitOps sync phases and waves

Some deployments need ordering:

```text
Secret and configuration
    ↓
Database migration
    ↓
Application
    ↓
Smoke test
```

Argo CD supports sync phases and waves that influence when resources are applied. Hooks can run in phases such as pre-sync, sync, and post-sync. ([Argo CD](https://argo-cd.readthedocs.io/en/release-3.0/user-guide/sync-waves/?utm_source=chatgpt.com "Sync Phases and Waves - Argo CD - Read the Docs"))

Example annotation:

```yaml
metadata:
  annotations:
    argocd.argoproj.io/sync-wave: "-1"
```

A migration Job might use:

```yaml
metadata:
  annotations:
    argocd.argoproj.io/hook: PreSync
```

As with Helm hooks, database changes must be designed for failure and rollback independently.

---

# 67. GitOps rollback

A GitOps rollback should normally be:

```text
Revert Git commit
    ↓
Review revert
    ↓
Merge
    ↓
Controller reconciles previous desired state
```

This has a clear audit trail.

Avoid using:

```bash
kubectl rollout undo
```

as the permanent GitOps rollback, because the controller may see that state as drift and reapply the Git version.

The long-term rollback source is Git.

---

# 68. GitOps deployment advantages for your company

For a company managing:

- SAP-connected services
    
- Infor LN integration
    
- MQTT device services
    
- Several plants or sites
    
- Development, testing, and production environments
    

GitOps can provide:

```text
One reviewed source of desired state
Environment-specific directories
Change approval through merge requests
Audit history
Repeatable site deployment
Drift detection
Controlled image promotion
```

A possible structure:

```text
platform-config/
├── clusters/
│   ├── karlsfeld/
│   ├── tirana/
│   └── galati/
├── applications/
│   ├── mqtt-platform/
│   ├── erp-adapter/
│   └── device-api/
└── environments/
    ├── testing/
    └── production/
```

Do not place plaintext site credentials in the repository.

---

# 69. Secrets in GitOps

Plain Kubernetes Secret values do not become safe merely because the repository is private.

Options include:

- External Secrets Operator
    
- Cloud or organizational secret manager
    
- Sealed Secrets
    
- SOPS-encrypted files
    
- Vault integrations
    
- Secret injection by deployment platform
    

The desired state in Git should usually contain:

```text
Reference to external Secret
or
Encrypted Secret representation
```

not:

```text
Plain production password
```

The decryption keys must not be stored beside the encrypted data.

---

# 70. Progressive delivery

A normal rolling update sends traffic to new replicas as they become ready.

More advanced strategies include:

## Blue-green

```text
Old version remains active
New version deployed separately
Verification
Traffic switch
```

## Canary

```text
Small percentage receives new version
Metrics evaluated
Traffic gradually increased
```

## Feature flags

```text
Code deployed
Feature remains disabled
Feature enabled for selected users
```

Kubernetes Deployments provide rolling updates and rollback, but sophisticated traffic-based rollout normally needs additional tooling or service-mesh/gateway integration. Kubernetes’ Deployment controller supports controlled rollout and revision rollback as its core mechanism. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/controllers/deployment/?utm_source=chatgpt.com "Deployments"))

---

# 71. Canary release logic

Conceptually:

```text
Production version A: 95% traffic
Canary version B: 5% traffic
        ↓
Observe:
error rate
latency
business success
resource use
        ↓
Healthy?
Increase traffic
        ↓
Unhealthy?
Send traffic back to A
```

A canary is not useful without automated or disciplined metric evaluation.

Do not decide success only from:

```text
Pod is Running
```

Evaluate:

```text
Error percentage
Latency
Dependency failures
Business transaction success
MQTT processing success
```

---

# 72. Rollback based on observability

A production deployment should have explicit failure criteria.

Examples:

```text
Health endpoint unavailable for 3 minutes
Ready replicas below 2
HTTP 5xx rate above 5%
p95 latency doubles
Database errors increase sharply
MQTT processing stops
Critical logs appear
```

Some failures should automatically halt or reverse a rollout.

Others require human review.

Use the Day 25 metrics and logs as deployment gates.

---

# 73. Pipeline failure categories

Classify failures.

## Source failure

```text
Compile error
Unit-test failure
Lint failure
```

No image should be published as a release.

## Build failure

```text
Dockerfile error
Dependency download failure
Registry push failure
```

No deployment.

## Security failure

```text
Critical vulnerability
Unapproved base image
Signature failure
```

Deployment blocked.

## Manifest failure

```text
Helm rendering error
Schema failure
Policy rejection
```

Deployment blocked.

## Runtime deployment failure

```text
ImagePullBackOff
CrashLoopBackOff
Readiness failure
Migration failure
```

Collect cluster evidence.

## Verification failure

```text
Pods ready but API test fails
```

Rollback or stop promotion.

---

# 74. Do not retry every failure blindly

Retry is reasonable for temporary:

```text
Registry network timeout
Runner network interruption
Transient cluster API failure
```

Retry is harmful for deterministic:

```text
Unit-test failure
Invalid YAML
Missing Secret reference
Application crash
Rejected security policy
Incompatible schema migration
```

Excessive retries can hide failures and consume infrastructure.

Make retry policies error-specific.

---

# 75. Pipeline timeouts

Every job should have a reasonable timeout.

Examples:

```text
Unit tests: 10 minutes
Image build: 20 minutes
Integration deployment: 15 minutes
Production rollout: 15 minutes
Migration: determined by safe operational limit
```

A deployment that hangs indefinitely:

- Holds environment lock
    
- Blocks later releases
    
- Hides failure
    
- Consumes runner capacity
    

Kubernetes resources should also have deadlines where appropriate:

```yaml
activeDeadlineSeconds: 600
```

---

# 76. Pipeline cancellation safety

Jobs that are usually safe to interrupt:

```text
Lint
Unit tests
Manifest rendering
Static analysis
```

Jobs that require caution:

```text
Database migration
Production Helm upgrade
Rollback
Secret rotation
Data backfill
```

Mark critical jobs:

```yaml
interruptible: false
```

Also use resource groups so a newer deployment cannot overlap an unfinished older deployment.

---

# 77. Pipeline evidence to retain

For every release, retain:

```text
Unit-test report
Container build log
Image digest
SBOM
Vulnerability report
Helm package
Rendered manifests
Policy-validation result
Integration-test results
Staging verification
Production verification
Release manifest
Deployment logs
Rollback record if applicable
```

Retention should match operational and compliance needs.

Avoid retaining secrets in logs or artifacts.

---

# 78. Day 28 practical laboratory

## Exercise 1 — Build pipeline skeleton

Create `.gitlab-ci.yml` with:

```text
validate
test
build
security
integration
staging
production
```

## Exercise 2 — Source checks

Add:

- Lint
    
- Unit tests
    
- Test reports
    

Make failures stop the pipeline.

## Exercise 3 — Helm validation

Run:

- `helm lint`
    
- `helm template`
    
- Server-side dry-run
    

Validate development and production values.

## Exercise 4 — Build once

Build the API image.

Tag it with the full commit SHA.

Push it to the registry.

## Exercise 5 — Resolve digest

Obtain the registry digest.

Store it as a dotenv artifact.

Use it in every deployment job.

## Exercise 6 — Security

Generate an SBOM.

Scan the image.

Block at least one chosen severity.

## Exercise 7 — Integration namespace

Create a unique namespace.

Install the Helm chart.

Wait for the rollout.

Run `helm test`.

Collect logs and Events.

Clean up.

## Exercise 8 — Staging

Deploy the exact image digest.

Verify externally.

Confirm running Pods use the expected digest.

## Exercise 9 — Production gate

Permit production only from a protected version tag.

Require manual approval.

Serialize production deployments.

## Exercise 10 — Rollback

Deploy an intentionally unhealthy image.

Observe the failed verification.

Roll back and verify the old release.

---

# 79. Day 28 command reference

```bash
# Lint chart
helm lint \
  helm/device-monitor \
  -f environments/production.yaml

# Render
helm template \
  device-monitor \
  helm/device-monitor \
  --namespace validation \
  -f environments/production.yaml \
  --set-string api.image.digest=sha256:placeholder

# Server-side validation
helm template \
  device-monitor \
  helm/device-monitor \
  -f environments/production.yaml \
  --set-string api.image.digest=sha256:placeholder \
  |
  kubectl apply \
    --dry-run=server \
    -f -

# Build image
docker build \
  --tag "$IMAGE_REPOSITORY:$CI_COMMIT_SHA" \
  .

# Push
docker push \
  "$IMAGE_REPOSITORY:$CI_COMMIT_SHA"

# Inspect digest
docker buildx imagetools inspect \
  "$IMAGE_REPOSITORY:$CI_COMMIT_SHA"

# Deploy exact digest
helm upgrade \
  --install \
  device-monitor \
  helm/device-monitor \
  --namespace device-monitor-staging \
  --create-namespace \
  -f environments/staging.yaml \
  --set-string api.image.repository="$IMAGE_REPOSITORY" \
  --set-string api.image.tag="" \
  --set-string api.image.digest="$IMAGE_DIGEST" \
  --atomic \
  --wait \
  --timeout 10m

# Check Helm status
helm status \
  device-monitor \
  --namespace device-monitor-staging

# Check history
helm history \
  device-monitor \
  --namespace device-monitor-staging

# Rollout status
kubectl rollout status \
  deployment/device-monitor-api \
  --namespace device-monitor-staging \
  --timeout 5m

# Verify actual image
kubectl get deployment \
  device-monitor-api \
  --namespace device-monitor-staging \
  -o jsonpath='{.spec.template.spec.containers[0].image}'

# Run chart tests
helm test \
  device-monitor \
  --namespace device-monitor-staging \
  --logs

# Roll back Helm
helm rollback \
  device-monitor \
  PREVIOUS_REVISION \
  --namespace device-monitor-staging \
  --wait \
  --timeout 10m

# Kubernetes Deployment rollback
kubectl rollout undo \
  deployment/device-monitor-api \
  --namespace device-monitor-staging
```

---

# 80. Knowledge check

## What is Continuous Integration?

Frequently integrating code changes and automatically verifying them through tests and checks.

## What is Continuous Delivery?

Keeping changes deployable while allowing a controlled approval before production.

## What is Continuous Deployment?

Automatically deploying every change that passes required gates.

## What does “build once, promote many” mean?

Build one immutable artifact and deploy the exact same artifact to testing, staging, and production.

## Why deploy by image digest?

The digest identifies the exact image content that was tested.

## What is a push-based deployment?

The pipeline directly calls the Kubernetes API or Helm to change the cluster.

## What is GitOps?

An operational model where Git contains desired state and a controller reconciles the cluster to match it.

## What does Argo CD automatic sync do?

It can detect differences between Git and the live cluster and synchronize the cluster to the desired Git state. ([Argo CD](https://argo-cd.readthedocs.io/en/latest/user-guide/auto_sync/?utm_source=chatgpt.com "Automated Sync Policy - Declarative GitOps CD for Kubernetes"))

## Why use temporary namespaces?

They provide isolated integration environments that can be created and removed per pipeline.

## What does `helm --atomic` provide?

It treats a failed install or upgrade as an operation that should be cleaned up or rolled back as supported by Helm.

## Is Helm success enough to prove the application works?

No. Run smoke and integration tests.

## Does application rollback restore database state?

No.

## Why serialize environment deployments?

To prevent two pipelines from modifying the same environment concurrently.

## Should production deployment credentials be available to merge-request pipelines?

No.

## Why retain the SBOM?

It records the components contained in the released artifact and supports vulnerability and compliance analysis.

## In GitOps, how should you perform a durable rollback?

Revert or change the desired-state commit in Git and allow the controller to reconcile it.

---

# 81. Day 28 completion challenge

Complete this independently:

1. Create a GitLab CI/CD pipeline.
    
2. Define validation, test, build, security, integration, staging, and production stages.
    
3. Run source linting.
    
4. Run unit tests.
    
5. Publish test reports.
    
6. Lint the Helm chart.
    
7. Render every supported environment.
    
8. Validate rendered resources against Kubernetes.
    
9. Build the container once.
    
10. Tag it with the full Git commit SHA.
    
11. Push the image.
    
12. Resolve the registry digest.
    
13. Store the digest as an artifact.
    
14. Generate an SBOM.
    
15. Run an image vulnerability scan.
    
16. Block critical findings.
    
17. Package the Helm chart.
    
18. Store the chart package.
    
19. Create a unique integration namespace.
    
20. Create required test Secrets safely.
    
21. Deploy the chart using the exact digest.
    
22. Wait for the Helm release.
    
23. Wait for Kubernetes rollouts.
    
24. Run Helm tests.
    
25. Run HTTP smoke tests.
    
26. Run a database read/write integration test.
    
27. Verify the exact image running.
    
28. Collect Kubernetes Events.
    
29. Collect failed Pod logs.
    
30. Collect the Helm release manifest.
    
31. Publish diagnostics as pipeline artifacts.
    
32. Remove the temporary namespace.
    
33. Deploy the same digest to staging.
    
34. Verify staging externally.
    
35. Prevent simultaneous staging deployments.
    
36. Restrict staging cluster access.
    
37. Create a version tag.
    
38. Protect the release tag.
    
39. Require manual production approval.
    
40. Prevent simultaneous production deployments.
    
41. Deploy the same digest to production.
    
42. Verify production health.
    
43. Record chart version and image digest.
    
44. Record Helm revision.
    
45. Record Git commit and pipeline ID.
    
46. Deploy a broken image deliberately in the lab.
    
47. Observe Helm or rollout failure.
    
48. Collect evidence.
    
49. Roll back.
    
50. Verify rollback health.
    
51. Explain why database rollback is separate.
    
52. Create a backward-compatible migration plan.
    
53. Configure a deployment timeout.
    
54. Mark production deployment non-interruptible.
    
55. Scope sensitive variables by environment.
    
56. Use separate build and deployment credentials.
    
57. Connect a Kubernetes cluster through the GitLab Agent.
    
58. Restrict the pipeline identity with RBAC.
    
59. Create a small GitOps configuration repository.
    
60. Update the image digest through a Git commit.
    
61. Create an Argo CD Application.
    
62. Observe automatic synchronization.
    
63. Make a manual cluster change.
    
64. Observe drift detection.
    
65. Revert the desired-state commit.
    
66. Observe GitOps rollback.
    
67. Document which system owns deployment state.
    
68. Document emergency rollback.
    
69. Document migration failure recovery.
    
70. Produce a complete release runbook.
    

The central Day 28 model is:

```text
Source commit
      ↓
Automated validation and tests
      ↓
Build image once
      ↓
Scan + SBOM + immutable digest
      ↓
Validate Helm package
      ↓
Deploy temporary integration environment
      ↓
Smoke and integration tests
      ↓
Promote same digest to staging
      ↓
Verify and approve
      ↓
Promote same digest to production
      ↓
Observe, record, and roll back safely
```

The most important operational lesson is:

> A CI/CD pipeline is not merely a script that runs `helm upgrade`. It is a controlled chain of evidence: tested source, one immutable build, security results, validated manifests, isolated integration deployment, staged promotion, protected production approval, post-deployment verification, and a rollback path that has already been tested.