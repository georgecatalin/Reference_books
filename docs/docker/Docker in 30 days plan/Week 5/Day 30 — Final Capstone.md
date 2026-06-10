####  Build and Operate a Production-Ready Container Platform

During the previous 29 days, you learned individual skills:

```text
Docker images and containers
        ↓
Docker Compose
        ↓
Registries and image security
        ↓
Docker networking and storage
        ↓
Kubernetes workloads
        ↓
Services, Ingress, and NetworkPolicy
        ↓
Security and observability
        ↓
Helm
        ↓
CI/CD and GitOps
        ↓
Reliability and disaster recovery
```

Today, you will combine these skills into one complete professional project.

The goal is not to learn another isolated technology. The goal is to prove that you can take an application from source code to a secure, monitored, repeatable, recoverable production deployment.

A production-quality container platform requires planning for resilience, security, upgrades, capacity, monitoring, and recovery—not merely a running cluster. ([Kubernetes](https://kubernetes.io/docs/setup/production-environment/?utm_source=chatgpt.com "Production environment"))

---

# 1. Day 30 objectives

By the end of today, you should be able to:

- Design a complete containerized application architecture
    
- Build minimal, secure, reproducible images
    
- Run the application locally with Docker Compose
    
- Publish immutable images to a registry
    
- Deploy the application to Kubernetes
    
- Package Kubernetes resources using Helm
    
- Separate configuration and secrets
    
- Apply least-privilege security
    
- Configure networking and external access
    
- Add probes, resources, scaling, and disruption controls
    
- Add logs, metrics, dashboards, and alerts
    
- Build a CI/CD deployment pipeline
    
- Test upgrades and rollback
    
- Back up and restore application data
    
- Perform controlled failure testing
    
- Produce operational documentation
    
- Conduct a final production-readiness review
    

---

# 2. Final capstone project

You will create a container platform for your MQTT device-monitoring use case.

The system will contain:

```text
MQTT devices
      ↓
Mosquitto broker
      ↓
MQTT consumer service
      ↓
PostgreSQL
      ↑
Device API
      ↑
Web dashboard
```

A more complete architecture:

```text
External users
      ↓ HTTPS
Ingress controller
      ↓
Device API Service
      ↓
Device API Pods
      ↓
PostgreSQL Service
      ↓
PostgreSQL

MQTT devices
      ↓ TLS 8883
External TCP entry point
      ↓
Mosquitto Service
      ↓
Mosquitto broker
      ↓
MQTT consumer Pods
      ↓
PostgreSQL
```

The major components are:

|Component|Purpose|
|---|---|
|Mosquitto|Accept MQTT device connections|
|MQTT consumer|Process heartbeat and telemetry messages|
|PostgreSQL|Store devices and telemetry|
|Device API|Expose data through HTTP|
|Dashboard|Present online/offline device state|
|Prometheus|Collect metrics|
|Grafana|Visualize metrics|
|Log collector|Centralize application logs|
|Ingress controller|Expose HTTP and HTTPS|
|CI/CD pipeline|Build, verify, and deploy releases|

---

# 3. Functional requirements

Your final system should support these operations.

## Device heartbeat ingestion

MQTT topic:

```text
deviceCluster/{device_id}/status/heartbeat
```

Example payload:

```json
{
  "timestamp_utc": "2026-06-10T08:15:00Z",
  "online": true,
  "uptime_s": 18642,
  "firmware_version": "3.2.0",
  "hardware_version": "2.1",
  "cpu_pct": 18.4,
  "ram_used_mb": 347,
  "ram_free_mb": 677,
  "disk_used_mb": 12540,
  "disk_free_mb": 48210,
  "temp_cpu_c": 52.3,
  "power_v": 12.1,
  "power_ok": true
}
```

The consumer should:

1. Validate the topic.
    
2. Validate the JSON.
    
3. Extract the device ID.
    
4. Store or update the device.
    
5. Store the heartbeat.
    
6. Update the last-contact time.
    
7. Log the processing result.
    
8. Increment application metrics.
    
9. Handle duplicate delivery safely.
    

---

## Device API

Recommended endpoints:

```text
GET /health/live
GET /health/ready
GET /metrics
GET /api/devices
GET /api/devices/{id}
GET /api/devices/{id}/heartbeats
```

Optional administrative endpoints:

```text
POST /api/devices/{id}/commands
POST /api/devices/{id}/ota
```

Do not implement sensitive remote-control operations without authentication, authorization, validation, and auditing.

---

## Dashboard

The dashboard should show:

- Device name
    
- Online or offline state
    
- Last contact
    
- Firmware version
    
- CPU usage
    
- Memory usage
    
- Disk usage
    
- Temperature
    
- Power status
    

Offline logic might be:

```text
Current time - last heartbeat > configured threshold
→ device considered offline
```

Do not rely exclusively on MQTT retained messages or LWT data without understanding their freshness and lifecycle.

---

# 4. Recommended project structure

```text
device-monitor-platform/
├── api/
│   ├── src/
│   ├── tests/
│   ├── requirements.txt
│   ├── Dockerfile
│   └── .dockerignore
│
├── mqtt-consumer/
│   ├── src/
│   ├── tests/
│   ├── requirements.txt
│   ├── Dockerfile
│   └── .dockerignore
│
├── dashboard/
│   ├── public/
│   ├── src/
│   ├── package.json
│   ├── Dockerfile
│   └── nginx.conf
│
├── database/
│   └── migrations/
│
├── mosquitto/
│   ├── mosquitto.conf
│   ├── acl
│   └── certificates/
│
├── compose/
│   ├── compose.yaml
│   └── compose.production.yaml
│
├── helm/
│   └── device-monitor/
│       ├── Chart.yaml
│       ├── values.yaml
│       ├── values.schema.json
│       ├── templates/
│       └── tests/
│
├── environments/
│   ├── development.yaml
│   ├── staging.yaml
│   └── production.yaml
│
├── scripts/
│   ├── smoke-test.sh
│   ├── backup-database.sh
│   ├── restore-database.sh
│   └── verify-release.sh
│
├── docs/
│   ├── architecture.md
│   ├── deployment.md
│   ├── backup-and-restore.md
│   ├── troubleshooting.md
│   └── incident-runbook.md
│
├── .gitlab-ci.yml
├── README.md
└── Makefile
```

This structure separates application source, container definitions, local orchestration, Kubernetes packaging, environment values, automation, and documentation.

---

# 5. Phase 1 — Define architecture before writing Dockerfiles

Document these decisions first.

## Component ownership

```text
API
→ HTTP operations and database queries

MQTT consumer
→ message validation and persistence

Mosquitto
→ MQTT protocol and client sessions

PostgreSQL
→ durable relational state

Dashboard
→ browser presentation
```

Do not combine all these processes into one container.

Docker recommends separating concerns and generally using one service per container rather than making one container responsible for several unrelated areas. ([Docker Documentation](https://docs.docker.com/engine/containers/multi-service_container/?utm_source=chatgpt.com "Run multiple processes in a container"))

---

## State ownership

Define where every type of data lives:

|Data|Storage|
|---|---|
|Application code|Container image|
|Application configuration|ConfigMap or environment|
|Passwords|Secret manager or Kubernetes Secret|
|Device records|PostgreSQL|
|Heartbeat history|PostgreSQL|
|Temporary files|`emptyDir` or container temporary storage|
|Metrics|Monitoring platform|
|Logs|Central logging platform|
|Database backups|Off-cluster backup storage|

Anything stored only inside a container’s writable layer should be treated as disposable.

---

## Network flows

Document every required flow:

```text
Browser
→ Ingress controller:443

Ingress controller
→ API:5000

API
→ PostgreSQL:5432

Devices
→ Mosquitto:8883

MQTT consumer
→ Mosquitto:8883

MQTT consumer
→ PostgreSQL:5432

Prometheus
→ API:/metrics

Prometheus
→ consumer:/metrics
```

Every other connection should be denied where practical.

---

# 6. Phase 2 — Build production-quality images

Docker’s current build guidance recommends practices such as multi-stage builds, choosing appropriate base images, excluding unnecessary files with `.dockerignore`, rebuilding regularly, and designing ephemeral containers. ([Docker Documentation](https://docs.docker.com/build/building/best-practices/?utm_source=chatgpt.com "Building best practices"))

## API Dockerfile

Example:

```dockerfile
# syntax=docker/dockerfile:1

FROM python:3.13-slim AS builder

WORKDIR /build

COPY requirements.txt .

RUN python -m venv /opt/venv \
    && /opt/venv/bin/pip install \
       --no-cache-dir \
       --requirement requirements.txt

COPY src/ ./src/


FROM python:3.13-slim AS runtime

ENV PATH="/opt/venv/bin:${PATH}" \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1

RUN groupadd \
      --gid 10001 \
      application \
    && useradd \
      --uid 10001 \
      --gid 10001 \
      --no-create-home \
      --shell /usr/sbin/nologin \
      application

WORKDIR /app

COPY --from=builder /opt/venv /opt/venv
COPY --from=builder /build/src ./src

USER 10001:10001

EXPOSE 5000

ENTRYPOINT ["python", "-m", "src.main"]
```

---

## Important image requirements

Every application image should:

- Use a documented, maintained base image
    
- Use an explicit image version
    
- Use a multi-stage build where useful
    
- Exclude unnecessary build files
    
- Run as a numeric non-root user
    
- Contain no embedded passwords
    
- Contain no SSH server
    
- Contain no package manager caches
    
- Write logs to stdout and stderr
    
- Handle `SIGTERM`
    
- Have an explicit entrypoint
    
- Support read-only root filesystems
    
- Be scanned before release
    
- Be deployed by immutable digest
    

Docker also recommends using trusted image sources such as Docker Official Images where they fit the use case. ([Docker Documentation](https://docs.docker.com/docker-hub/image-library/trusted-content/?utm_source=chatgpt.com "Trusted content"))

---

## `.dockerignore`

Example:

```text
.git
.gitlab-ci.yml
__pycache__
*.pyc
.pytest_cache
.venv
tests
docs
secrets
*.key
*.crt
.env
.env.*
docker-compose*.yaml
rendered
reports
```

Never include private keys or environment-secret files in the build context.

---

# 7. Phase 3 — Add image labels

Example:

```dockerfile
ARG BUILD_VERSION
ARG BUILD_REVISION
ARG BUILD_DATE

LABEL org.opencontainers.image.title="Device API" \
      org.opencontainers.image.description="API for MQTT device monitoring" \
      org.opencontainers.image.version="${BUILD_VERSION}" \
      org.opencontainers.image.revision="${BUILD_REVISION}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.source="https://git.example.com/platform/device-api"
```

Build:

```bash
docker build \
  --build-arg BUILD_VERSION=1.0.0 \
  --build-arg BUILD_REVISION="$(git rev-parse HEAD)" \
  --build-arg BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  --tag device-api:1.0.0 \
  api/
```

Inspect:

```bash
docker image inspect device-api:1.0.0
```

---

# 8. Phase 4 — Test images locally

Build all images:

```bash
docker build \
  --tag device-api:development \
  api/

docker build \
  --tag mqtt-consumer:development \
  mqtt-consumer/

docker build \
  --tag device-dashboard:development \
  dashboard/
```

Test runtime user:

```bash
docker run \
  --rm \
  device-api:development \
  id
```

Expected:

```text
uid=10001(application)
gid=10001(application)
```

Test read-only root filesystem:

```bash
docker run \
  --rm \
  --read-only \
  --tmpfs /tmp \
  device-api:development
```

Test capability removal:

```bash
docker run \
  --rm \
  --cap-drop=ALL \
  --security-opt no-new-privileges \
  device-api:development
```

Inspect size:

```bash
docker image ls \
  device-api:development
```

Inspect history:

```bash
docker history \
  device-api:development
```

---

# 9. Phase 5 — Build a local Compose environment

Create `compose/compose.yaml`:

```yaml
services:
  database:
    image: postgres:17

    environment:
      POSTGRES_DB: device_monitor
      POSTGRES_USER: device_app
      POSTGRES_PASSWORD_FILE: /run/secrets/database_password

    secrets:
      - database_password

    volumes:
      - database_data:/var/lib/postgresql/data

    healthcheck:
      test:
        - CMD-SHELL
        - pg_isready -U device_app -d device_monitor

      interval: 5s
      timeout: 3s
      retries: 20

    networks:
      - backend

  mosquitto:
    image: eclipse-mosquitto:2

    ports:
      - "1883:1883"

    volumes:
      - ../mosquitto/mosquitto.conf:/mosquitto/config/mosquitto.conf:ro
      - mosquitto_data:/mosquitto/data

    networks:
      - mqtt

  mqtt-consumer:
    image: mqtt-consumer:development

    depends_on:
      database:
        condition: service_healthy

      mosquitto:
        condition: service_started

    environment:
      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: device_monitor
      DB_USER: device_app
      DB_PASSWORD_FILE: /run/secrets/database_password

      MQTT_HOST: mosquitto
      MQTT_PORT: "1883"

    secrets:
      - database_password

    networks:
      - backend
      - mqtt

  api:
    image: device-api:development

    depends_on:
      database:
        condition: service_healthy

    environment:
      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: device_monitor
      DB_USER: device_app
      DB_PASSWORD_FILE: /run/secrets/database_password

    secrets:
      - database_password

    ports:
      - "8080:5000"

    networks:
      - backend
      - frontend

  dashboard:
    image: device-dashboard:development

    ports:
      - "8081:80"

    networks:
      - frontend

volumes:
  database_data:
  mosquitto_data:

networks:
  frontend:
  backend:
    internal: true
  mqtt:

secrets:
  database_password:
    file: ./secrets/database-password.txt
```

Compose can be used for production-style single-server deployments as well as development, but it remains a single-Docker-host model unless combined with other infrastructure. ([Docker Documentation](https://docs.docker.com/compose/how-tos/production/?utm_source=chatgpt.com "Use Compose in production"))

---

# 10. Validate the Compose stack

Render configuration:

```bash
docker compose \
  --file compose/compose.yaml \
  config
```

Start:

```bash
docker compose \
  --file compose/compose.yaml \
  up \
  --detach
```

Inspect:

```bash
docker compose \
  --file compose/compose.yaml \
  ps
```

Follow logs:

```bash
docker compose \
  --file compose/compose.yaml \
  logs \
  --follow
```

Verify:

```bash
curl --fail \
  http://127.0.0.1:8080/health/ready
```

Publish a test heartbeat:

```bash
mosquitto_pub \
  --host 127.0.0.1 \
  --port 1883 \
  --topic deviceCluster/test-device/status/heartbeat \
  --message '{
    "timestamp_utc": "2026-06-10T08:15:00Z",
    "online": true,
    "firmware_version": "3.2.0",
    "cpu_pct": 18.4
  }'
```

Query:

```bash
curl --silent \
  http://127.0.0.1:8080/api/devices \
  | python3 -m json.tool
```

---

# 11. Phase 6 — Test persistence

Create data, then remove containers:

```bash
docker compose \
  --file compose/compose.yaml \
  down
```

Start again:

```bash
docker compose \
  --file compose/compose.yaml \
  up \
  --detach
```

Confirm data remains.

Then remove volumes deliberately:

```bash
docker compose \
  --file compose/compose.yaml \
  down \
  --volumes
```

Understand the difference:

```text
down
→ containers and network removed
→ named volumes retained

down --volumes
→ named volumes removed
→ persistent data destroyed
```

---

# 12. Phase 7 — Add automated tests

Your project should include at least four test levels.

## Unit tests

Test isolated functions:

```text
Topic parsing
JSON validation
Offline-status calculation
Database mapping
Configuration loading
```

## Container tests

Test:

```text
Image starts
Process runs non-root
Required port listens
Health endpoint works
No secrets are embedded
```

## Integration tests

Test:

```text
MQTT publish
→ consumer receives
→ PostgreSQL updated
→ API returns device
```

## Deployment smoke tests

Test:

```text
Ingress works
TLS works
API is ready
Database read succeeds
Metrics endpoint works
```

A container being in `Running` state is not evidence that the application works correctly.

---

# 13. Phase 8 — Publish immutable images

Tag with commit SHA:

```bash
IMAGE_REPOSITORY="registry.example.com/platform/device-api"
IMAGE_TAG="$(git rev-parse HEAD)"

docker tag \
  device-api:development \
  "${IMAGE_REPOSITORY}:${IMAGE_TAG}"
```

Push:

```bash
docker push \
  "${IMAGE_REPOSITORY}:${IMAGE_TAG}"
```

Resolve the digest:

```bash
docker buildx imagetools inspect \
  "${IMAGE_REPOSITORY}:${IMAGE_TAG}"
```

Production deployment should reference:

```text
registry.example.com/platform/device-api@sha256:...
```

rather than:

```text
device-api:latest
```

---

# 14. Phase 9 — Generate security evidence

For every release, generate:

```text
Vulnerability report
SBOM
Image digest
Source commit
Build provenance
Test results
```

Example SBOM:

```bash
syft \
  "${IMAGE_REPOSITORY}:${IMAGE_TAG}" \
  --output cyclonedx-json \
  > device-api-sbom.json
```

Example vulnerability scan:

```bash
trivy image \
  --severity HIGH,CRITICAL \
  "${IMAGE_REPOSITORY}:${IMAGE_TAG}"
```

A scanner result should be reviewed in context, but critical unresolved vulnerabilities should normally block promotion unless a documented exception is approved.

---

# 15. Phase 10 — Create Kubernetes namespaces

Recommended environment separation:

```text
device-monitor-development
device-monitor-staging
device-monitor-production
```

Create production namespace:

```yaml
apiVersion: v1
kind: Namespace

metadata:
  name: device-monitor-production

  labels:
    environment: production

    pod-security.kubernetes.io/enforce: restricted
    pod-security.kubernetes.io/audit: restricted
    pod-security.kubernetes.io/warn: restricted
```

Apply:

```bash
kubectl apply \
  -f namespace-production.yaml
```

Kubernetes includes security controls and APIs that should be combined into a wider workload and cluster security strategy. ([Kubernetes](https://kubernetes.io/docs/concepts/security/?utm_source=chatgpt.com "Security"))

---

# 16. Phase 11 — Define Kubernetes workloads

Your Helm chart should generate at least:

```text
ConfigMap
ServiceAccounts
API Deployment
API Service
Consumer Deployment
Mosquitto workload
PostgreSQL or external-database configuration
Ingress
NetworkPolicies
HorizontalPodAutoscaler
PodDisruptionBudget
ServiceMonitor, when applicable
Migration Job
Helm test Pod
```

Helm’s best-practices guide recommends structured, conventional charts and reusable, understandable chart organization. ([Helm](https://helm.sh/docs/chart_best_practices/?utm_source=chatgpt.com "Best Practices"))

---

# 17. Production API Deployment

A condensed example:

```yaml
apiVersion: apps/v1
kind: Deployment

metadata:
  name: device-api
  namespace: device-monitor-production

spec:
  minReadySeconds: 20
  progressDeadlineSeconds: 600

  strategy:
    type: RollingUpdate

    rollingUpdate:
      maxUnavailable: 0
      maxSurge: 1

  selector:
    matchLabels:
      app: device-api

  template:
    metadata:
      labels:
        app: device-api

    spec:
      serviceAccountName: device-api
      automountServiceAccountToken: false
      enableServiceLinks: false

      terminationGracePeriodSeconds: 30

      securityContext:
        runAsNonRoot: true
        runAsUser: 10001
        runAsGroup: 10001
        fsGroup: 10001

        seccompProfile:
          type: RuntimeDefault

      topologySpreadConstraints:
        - maxSkew: 1
          topologyKey: kubernetes.io/hostname
          whenUnsatisfiable: ScheduleAnyway

          labelSelector:
            matchLabels:
              app: device-api

      containers:
        - name: device-api

          image: registry.example.com/platform/device-api@sha256:APPROVED_DIGEST

          ports:
            - name: http
              containerPort: 5000

          securityContext:
            runAsNonRoot: true
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true

            capabilities:
              drop:
                - ALL

          startupProbe:
            httpGet:
              path: /health/live
              port: http

            periodSeconds: 5
            failureThreshold: 30

          readinessProbe:
            httpGet:
              path: /health/ready
              port: http

            periodSeconds: 5
            failureThreshold: 3

          livenessProbe:
            httpGet:
              path: /health/live
              port: http

            periodSeconds: 15
            failureThreshold: 5

          resources:
            requests:
              cpu: 200m
              memory: 256Mi
              ephemeral-storage: 64Mi

            limits:
              cpu: "1"
              memory: 512Mi
              ephemeral-storage: 256Mi

          volumeMounts:
            - name: database-password
              mountPath: /run/secrets/database
              readOnly: true

            - name: temporary-storage
              mountPath: /tmp

      volumes:
        - name: database-password

          secret:
            secretName: production-database-credentials

            items:
              - key: password
                path: password

        - name: temporary-storage

          emptyDir:
            sizeLimit: 64Mi
```

---

# 18. Phase 12 — Add stable networking

API Service:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: device-api
  namespace: device-monitor-production

spec:
  type: ClusterIP

  selector:
    app: device-api

  ports:
    - name: http
      port: 80
      targetPort: http
```

Ingress:

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress

metadata:
  name: device-api
  namespace: device-monitor-production

spec:
  ingressClassName: nginx

  tls:
    - hosts:
        - api.example.com

      secretName: device-api-tls

  rules:
    - host: api.example.com

      http:
        paths:
          - path: /
            pathType: Prefix

            backend:
              service:
                name: device-api

                port:
                  name: http
```

MQTT uses TCP rather than ordinary HTTP Ingress routing. Use an appropriate TCP load-balancer, `LoadBalancer` Service, Gateway implementation, or controlled external broker architecture.

---

# 19. Phase 13 — Add NetworkPolicies

Default deny:

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy

metadata:
  name: default-deny
  namespace: device-monitor-production

spec:
  podSelector: {}

  policyTypes:
    - Ingress
    - Egress
```

Allow Ingress controller to API:

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy

metadata:
  name: allow-ingress-api
  namespace: device-monitor-production

spec:
  podSelector:
    matchLabels:
      app: device-api

  policyTypes:
    - Ingress

  ingress:
    - from:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: ingress-nginx

      ports:
        - protocol: TCP
          port: 5000
```

Allow API to PostgreSQL:

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy

metadata:
  name: allow-api-database
  namespace: device-monitor-production

spec:
  podSelector:
    matchLabels:
      app: device-api

  policyTypes:
    - Egress

  egress:
    - to:
        - podSelector:
            matchLabels:
              app: postgres

      ports:
        - protocol: TCP
          port: 5432
```

Also explicitly allow DNS.

---

# 20. Phase 14 — Add least-privilege RBAC

Most application Pods should not access the Kubernetes API.

API ServiceAccount:

```yaml
apiVersion: v1
kind: ServiceAccount

metadata:
  name: device-api
  namespace: device-monitor-production

automountServiceAccountToken: false
```

Check:

```bash
kubectl auth can-i list pods \
  --namespace device-monitor-production \
  --as=system:serviceaccount:device-monitor-production:device-api
```

Expected:

```text
no
```

GitLab recommends minimizing CI/CD cluster access using normal Kubernetes RBAC and, where appropriate, agent impersonation. ([GitLab Docs](https://docs.gitlab.com/user/clusters/agent/enterprise_considerations/?utm_source=chatgpt.com "Best practices for using the GitLab integration with Kubernetes"))

---

# 21. Phase 15 — Add resource governance

Create a `LimitRange`:

```yaml
apiVersion: v1
kind: LimitRange

metadata:
  name: container-defaults
  namespace: device-monitor-production

spec:
  limits:
    - type: Container

      defaultRequest:
        cpu: 100m
        memory: 128Mi

      default:
        cpu: 500m
        memory: 512Mi
```

Create a `ResourceQuota`:

```yaml
apiVersion: v1
kind: ResourceQuota

metadata:
  name: platform-quota
  namespace: device-monitor-production

spec:
  hard:
    requests.cpu: "8"
    requests.memory: 16Gi

    limits.cpu: "16"
    limits.memory: 32Gi

    pods: "50"
    persistentvolumeclaims: "10"
```

Docker also provides CPU and memory constraints for standalone containers; in Kubernetes, requests, limits, quotas, and scheduling work together at cluster level. ([Docker Documentation](https://docs.docker.com/engine/containers/resource_constraints/?utm_source=chatgpt.com "Resource constraints"))

---

# 22. Phase 16 — Add availability controls

## HorizontalPodAutoscaler

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler

metadata:
  name: device-api
  namespace: device-monitor-production

spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: device-api

  minReplicas: 3
  maxReplicas: 10

  metrics:
    - type: Resource

      resource:
        name: cpu

        target:
          type: Utilization
          averageUtilization: 60

  behavior:
    scaleDown:
      stabilizationWindowSeconds: 300
```

## PodDisruptionBudget

```yaml
apiVersion: policy/v1
kind: PodDisruptionBudget

metadata:
  name: device-api
  namespace: device-monitor-production

spec:
  minAvailable: 2

  selector:
    matchLabels:
      app: device-api
```

Check total capacity for:

```text
Minimum replicas
+
Maximum rollout surge
+
Failure reserve
+
Autoscaling growth
```

---

# 23. Phase 17 — Separate liveness and readiness correctly

## Liveness

Answers:

```text
Is the application process capable of continuing?
```

It should not generally fail merely because PostgreSQL is temporarily unavailable.

## Readiness

Answers:

```text
Can this instance serve useful requests now?
```

It may check:

- Configuration loaded
    
- Required initialization complete
    
- Database reachable
    
- Internal worker ready
    

## Startup

Answers:

```text
Has initial startup completed?
```

Never use an aggressive liveness probe to repeatedly restart an application during a dependency outage.

---

# 24. Phase 18 — Add observability

Each service should emit:

## Logs

Structured JSON:

```json
{
  "timestamp": "2026-06-10T08:15:21Z",
  "level": "INFO",
  "service": "mqtt-consumer",
  "version": "1.0.0",
  "device_id": "test-device",
  "message_type": "heartbeat",
  "duration_ms": 14,
  "result": "stored"
}
```

## Metrics

API:

```text
device_api_http_requests_total
device_api_http_errors_total
device_api_request_duration_seconds
device_api_database_errors_total
```

Consumer:

```text
mqtt_messages_received_total
mqtt_messages_processed_total
mqtt_messages_failed_total
mqtt_processing_duration_seconds
mqtt_broker_connected
mqtt_consumer_backlog
```

Fleet:

```text
device_online_total
device_offline_total
device_heartbeat_age_seconds
```

Avoid high-cardinality metric labels such as request IDs, full error messages, and unlimited device identifiers.

---

# 25. Phase 19 — Define dashboards

Create at least four Grafana dashboards.

## Platform overview

Show:

- Ready replicas
    
- Request rate
    
- Error rate
    
- p95 latency
    
- MQTT ingestion rate
    
- Database availability
    

## API dashboard

Show:

- Requests per route
    
- 2xx, 4xx, and 5xx rates
    
- p50, p95, and p99 latency
    
- CPU and memory
    
- Restarts
    
- Database failures
    

## MQTT dashboard

Show:

- Connected clients
    
- Messages per second
    
- Consumer throughput
    
- Processing failures
    
- Message backlog
    
- Connection state
    

## Device fleet dashboard

Show:

- Total devices
    
- Online devices
    
- Offline devices
    
- Heartbeat-age distribution
    
- Firmware versions
    
- High-temperature devices
    

---

# 26. Phase 20 — Add actionable alerts

Critical alerts:

```text
API has zero ready replicas for two minutes.

PostgreSQL is unreachable for two minutes.

MQTT broker has no available instance.

No heartbeat messages have been processed for ten minutes
while devices are expected online.
```

Warning alerts:

```text
API 5xx rate exceeds 5% for ten minutes.

p95 latency exceeds the defined objective.

Database volume exceeds 80%.

A Pod repeatedly restarts.

Backups have not completed successfully.

TLS certificate expires within fourteen days.
```

Every alert should link to a runbook.

---

# 27. Phase 21 — Build the Helm chart

Your chart should support values such as:

```yaml
api:
  image:
    repository: registry.example.com/platform/device-api
    digest: sha256:...

  autoscaling:
    enabled: true
    minReplicas: 3
    maxReplicas: 10

  podDisruptionBudget:
    enabled: true
    minAvailable: 2

  ingress:
    enabled: true
    host: api.example.com

consumer:
  image:
    repository: registry.example.com/platform/mqtt-consumer
    digest: sha256:...

database:
  enabled: false

  external:
    host: postgresql.production.internal
    port: 5432

networkPolicy:
  enabled: true

serviceAccount:
  automountToken: false
```

Validate:

```bash
helm lint \
  helm/device-monitor \
  -f environments/production.yaml
```

Render:

```bash
helm template \
  device-monitor \
  helm/device-monitor \
  --namespace device-monitor-production \
  -f environments/production.yaml \
  > rendered-production.yaml
```

Server validation:

```bash
kubectl apply \
  --dry-run=server \
  -f rendered-production.yaml
```

---

# 28. Phase 22 — Build the CI/CD pipeline

Required pipeline stages:

```text
validate
test
build
security
package
integration
staging
production
```

Required gates:

```text
Source lint succeeds
Unit tests succeed
Helm lint succeeds
Rendered manifests validate
Image builds
Image scan passes policy
SBOM generated
Integration deployment succeeds
Smoke tests succeed
Staging verification succeeds
Production approval granted
```

GitLab’s Kubernetes Agent provides a supported workflow for securely exposing Kubernetes contexts to CI/CD jobs so commands such as `kubectl` and `helm` can be run from pipelines. ([GitLab Docs](https://docs.gitlab.com/user/clusters/agent/ci_cd_workflow/?utm_source=chatgpt.com "Using GitLab CI/CD with a Kubernetes cluster"))

---

# 29. Phase 23 — Promote one exact artifact

Pipeline output:

```text
Source commit:
3f8a2d...

API image:
registry.example.com/platform/device-api@sha256:abc...

Consumer image:
registry.example.com/platform/mqtt-consumer@sha256:def...

Helm chart:
device-monitor-1.0.0.tgz
```

Use those same digests in:

```text
Integration
Staging
Production
```

Do not rebuild the production image after staging approval.

---

# 30. Phase 24 — Add deployment verification

After Helm deployment:

```bash
helm status \
  device-monitor \
  --namespace device-monitor-production
```

Check rollouts:

```bash
kubectl rollout status \
  deployment/device-api \
  --namespace device-monitor-production \
  --timeout=5m
```

```bash
kubectl rollout status \
  deployment/mqtt-consumer \
  --namespace device-monitor-production \
  --timeout=5m
```

Verify exact images:

```bash
kubectl get deployments \
  --namespace device-monitor-production \
  -o jsonpath='{range .items[*]}{.metadata.name}{"\t"}{.spec.template.spec.containers[*].image}{"\n"}{end}'
```

Run smoke test:

```bash
scripts/smoke-test.sh \
  https://api.example.com
```

---

# 31. Phase 25 — Test rollback

Deploy an intentionally broken API image in the lab:

```text
Application exits during startup
```

Observe:

```text
CrashLoopBackOff
Failed rollout
Failed smoke test
```

Collect:

```bash
kubectl get pods \
  --namespace device-monitor-staging
```

```bash
kubectl events \
  --namespace device-monitor-staging \
  --types=Warning
```

```bash
kubectl logs \
  --namespace device-monitor-staging \
  POD_NAME \
  --previous
```

Inspect Helm:

```bash
helm history \
  device-monitor \
  --namespace device-monitor-staging
```

Roll back:

```bash
helm rollback \
  device-monitor \
  PREVIOUS_REVISION \
  --namespace device-monitor-staging \
  --wait \
  --timeout=10m
```

Then verify externally.

---

# 32. Phase 26 — Test graceful termination

Create continuous traffic:

```bash
while true; do
  curl \
    --silent \
    --output /dev/null \
    --write-out '%{http_code}\n' \
    https://api.staging.example.com/health/ready

  sleep 0.5
done
```

Delete one Pod:

```bash
kubectl delete pod \
  --namespace device-monitor-staging \
  API_POD
```

Expected:

- Service remains available
    
- Pod receives `SIGTERM`
    
- Pod stops receiving new traffic
    
- Active work finishes where practical
    
- Replacement becomes ready
    

Inspect logs for shutdown events.

---

# 33. Phase 27 — Test scaling

Generate load.

Watch:

```bash
kubectl get hpa \
  --namespace device-monitor-staging \
  --watch
```

```bash
kubectl get pods \
  --namespace device-monitor-staging \
  --watch
```

```bash
kubectl top pods \
  --namespace device-monitor-staging
```

Confirm:

- Replicas increase
    
- New Pods become ready
    
- Latency remains acceptable
    
- PostgreSQL connections remain within safe limits
    
- Replicas eventually reduce gradually
    

---

# 34. Phase 28 — Test node maintenance

In a multi-node environment:

```bash
kubectl get pods \
  --namespace device-monitor-staging \
  -o wide
```

Confirm replicas are distributed.

Cordon:

```bash
kubectl cordon WORKER_NODE
```

Drain:

```bash
kubectl drain WORKER_NODE \
  --ignore-daemonsets \
  --delete-emptydir-data
```

Confirm:

- PDB is respected
    
- Required capacity exists elsewhere
    
- API stays available
    
- Consumer processing continues safely
    
- Stateful workloads behave as expected
    

Restore:

```bash
kubectl uncordon WORKER_NODE
```

---

# 35. Phase 29 — Create the backup process

Example PostgreSQL backup script:

```bash
#!/usr/bin/env bash

set -Eeuo pipefail

NAMESPACE="${NAMESPACE:-device-monitor-production}"
POD="${POD:-database-0}"
DATABASE="${DATABASE:-device_monitor}"
USER="${USER:-device_app}"
OUTPUT="${1:-device-monitor-$(date -u +%Y%m%dT%H%M%SZ).dump}"

kubectl exec \
  --namespace "$NAMESPACE" \
  "$POD" \
  -- \
  pg_dump \
    --username "$USER" \
    --dbname "$DATABASE" \
    --format custom \
  > "$OUTPUT"

test -s "$OUTPUT"

echo "Backup created: $OUTPUT"
```

Then:

- Encrypt it
    
- Upload it off-cluster
    
- Verify checksum
    
- Record timestamp
    
- Apply retention
    
- Monitor completion
    

A PVC is not a backup.

---

# 36. Phase 30 — Perform a real restore test

Create an isolated namespace:

```bash
kubectl create namespace \
  device-monitor-restore-test
```

Provision empty PostgreSQL.

Restore:

```bash
pg_restore \
  --dbname device_monitor_restore \
  device-monitor.dump
```

Verify:

```sql
SELECT COUNT(*) FROM devices;
SELECT MAX(last_contact) FROM devices;
SELECT COUNT(*) FROM heartbeats;
```

Deploy the API against the restored database.

Run:

```bash
curl --fail \
  https://restore-test.example.com/api/devices
```

Record:

```text
Backup timestamp
Restore start time
Database-ready time
API-ready time
Verification-complete time
```

Calculate actual:

```text
RPO
RTO
```

---

# 37. Phase 31 — Define disaster recovery

Document your chosen model.

Example:

```text
Primary:
Production Kubernetes cluster

Database:
Managed PostgreSQL with replication

Backup:
Encrypted object-storage backups
plus transaction-log archive

Recovery cluster:
Provisioned from infrastructure-as-code

Application recovery:
Helm chart plus immutable image digests

Traffic recovery:
DNS or load-balancer switch
```

Your runbook must explain:

1. Who declares a disaster.
    
2. Who can access backup credentials.
    
3. How replacement infrastructure is provisioned.
    
4. Which database backup is selected.
    
5. How images and charts are retrieved.
    
6. How Secrets are restored.
    
7. How traffic is switched.
    
8. How data is verified.
    
9. How service restoration is announced.
    
10. How failback is handled.
    

---

# 38. Phase 32 — Build operational runbooks

At minimum, create these documents.

## API unavailable

Include:

```text
External test
Ingress status
Service endpoints
Pod readiness
Current and previous logs
Events
Resource use
Database status
Recent release
Rollback procedure
```

## MQTT messages not processed

Include:

```text
Broker availability
Consumer connection state
Subscription status
Backlog
Consumer logs
Database access
Message validation failures
Recent configuration changes
```

## Database unavailable

Include:

```text
Database Pod or service
Storage status
Connection count
Disk usage
Logs
Backup status
Failover process
Restore process
```

## Failed release

Include:

```text
Helm status
Release history
Pod state
Migration state
Smoke-test output
Rollback command
Rollback verification
```

## Cluster loss

Include:

```text
Replacement cluster procedure
Controller installation
Secrets restoration
Database restoration
Application deployment
DNS switch
Verification
```

---

# 39. Phase 33 — Produce architecture documentation

Your architecture document should contain:

## Context diagram

```text
Devices
Users
ERP systems
MQTT platform
External identity provider
Monitoring
```

## Container diagram

```text
Mosquitto
MQTT consumer
API
Dashboard
PostgreSQL
Prometheus
Grafana
```

## Deployment diagram

```text
Load balancer
Ingress controller
Worker nodes
Pod distribution
Database
Storage
Backup target
```

## Security diagram

```text
Ingress rules
NetworkPolicy flows
ServiceAccounts
Secrets
Registry trust
TLS boundaries
```

Documentation must match the deployed system.

---

# 40. Phase 34 — Define service objectives

Example API objectives:

```text
Availability:
99.9% monthly

Successful requests:
99.5% excluding invalid client requests

p95 latency:
below 500 ms

Recovery time:
below 60 minutes
```

Example MQTT objectives:

```text
99% of valid heartbeat messages processed
within 10 seconds.

No acknowledged message is intentionally lost.

Duplicate processing does not create
duplicate device-state records.
```

Example database objectives:

```text
RPO:
15 minutes

RTO:
60 minutes
```

Metrics and alerts should be aligned with these targets.

---

# 41. Final production-readiness checklist

## Images

-  Multi-stage builds used appropriately
    
-  Maintained base images
    
-  Base versions pinned
    
-  Non-root numeric user
    
-  `.dockerignore` configured
    
-  No secrets in image
    
-  Image scanned
    
-  SBOM generated
    
-  Image digest recorded
    
-  No production `latest` tags
    

## Runtime

-  One primary concern per container
    
-  `SIGTERM` handled
    
-  Logs written to stdout and stderr
    
-  Temporary paths explicit
    
-  Root filesystem read-only where possible
    
-  Capabilities dropped
    
-  Privilege escalation disabled
    
-  Seccomp enabled
    

## Kubernetes

-  Dedicated namespaces
    
-  Dedicated ServiceAccounts
    
-  Token auto-mount disabled
    
-  Least-privilege RBAC
    
-  Pod Security restricted
    
-  Requests and limits
    
-  Startup probe
    
-  Readiness probe
    
-  Liveness probe
    
-  Rolling-update policy
    
-  Topology spreading
    
-  PDB
    
-  HPA
    
-  Quotas
    

## Networking

-  Stable Services
    
-  TLS
    
-  Ingress or suitable gateway
    
-  MQTT exposure designed separately
    
-  Default-deny policies
    
-  DNS explicitly permitted
    
-  Database access restricted
    
-  External dependencies documented
    

## Configuration and Secrets

-  Configuration outside image
    
-  Secrets outside Git
    
-  Secret access minimized
    
-  Secret keys mounted selectively
    
-  Rotation procedure tested
    
-  Registry credentials pull-only
    
-  TLS key handling documented
    

## Observability

-  Structured logs
    
-  Central log collection
    
-  Metrics endpoints
    
-  Dashboards
    
-  Actionable alerts
    
-  Request correlation IDs
    
-  Deployment annotations
    
-  Backup alerts
    
-  Certificate alerts
    

## Delivery

-  Unit tests
    
-  Integration tests
    
-  Helm lint
    
-  Manifest validation
    
-  Policy checks
    
-  Build once
    
-  Deploy by digest
    
-  Staging promotion
    
-  Production approval
    
-  Deployment locking
    
-  Smoke tests
    
-  Rollback tested
    

## Reliability

-  Replica distribution
    
-  Node failure tested
    
-  Pod termination tested
    
-  Dependency outage tested
    
-  Scaling tested
    
-  Downstream limits calculated
    
-  RPO defined
    
-  RTO defined
    
-  Backups off-cluster
    
-  Restore tested
    
-  DR runbook tested
    

---

# 42. Final practical examination

Complete the following without copying the exact commands from the lessons.

## Part A — Docker

1. Write a production Dockerfile for the API.
    
2. Use a multi-stage build.
    
3. Run as UID 10001.
    
4. Exclude unnecessary files.
    
5. Build the image.
    
6. Run it read-only.
    
7. Remove all capabilities.
    
8. Verify health.
    
9. Inspect layers.
    
10. Scan the image.
    

## Part B — Compose

11. Create a Compose stack.
    
12. Add PostgreSQL.
    
13. Add Mosquitto.
    
14. Add the consumer.
    
15. Add the API.
    
16. Add the dashboard.
    
17. Use named volumes.
    
18. Use secrets.
    
19. Separate networks.
    
20. Run an end-to-end heartbeat test.
    

## Part C — Kubernetes

21. Create a namespace.
    
22. Create ServiceAccounts.
    
23. Create configuration.
    
24. Mount required Secrets.
    
25. Deploy PostgreSQL or configure an external database.
    
26. Deploy Mosquitto.
    
27. Deploy the consumer.
    
28. Deploy the API.
    
29. Create Services.
    
30. Configure Ingress.
    
31. Configure MQTT TCP exposure.
    
32. Apply NetworkPolicies.
    
33. Apply security contexts.
    
34. Add resources.
    
35. Add probes.
    
36. Add topology spreading.
    
37. Add PDBs.
    
38. Add HPA.
    
39. Confirm exact image digests.
    
40. Run smoke tests.
    

## Part D — Helm

41. Package all resources as a Helm chart.
    
42. Create development values.
    
43. Create staging values.
    
44. Create production values.
    
45. Add value-schema validation.
    
46. Lint the chart.
    
47. Render it.
    
48. Validate against Kubernetes.
    
49. Install it.
    
50. Upgrade it.
    
51. Run Helm tests.
    
52. Roll it back.
    

## Part E — CI/CD

53. Run source linting.
    
54. Run unit tests.
    
55. Build the image once.
    
56. Push with commit SHA.
    
57. Resolve digest.
    
58. Generate an SBOM.
    
59. Scan the image.
    
60. Package the chart.
    
61. Create a temporary integration namespace.
    
62. Deploy the exact digest.
    
63. Run integration tests.
    
64. Collect diagnostics.
    
65. Remove the namespace.
    
66. Promote to staging.
    
67. Verify staging.
    
68. Require production approval.
    
69. Promote the same digest.
    
70. Record deployment evidence.
    

## Part F — Operations

71. Add structured logs.
    
72. Add metrics.
    
73. Create dashboards.
    
74. Create alerts.
    
75. Delete an API Pod.
    
76. Simulate database failure.
    
77. Test NetworkPolicy failure.
    
78. Drain a node.
    
79. Generate scaling load.
    
80. Test graceful shutdown.
    
81. Deploy a broken release.
    
82. Roll back.
    
83. Create a database backup.
    
84. Restore it.
    
85. Measure RPO and RTO.
    
86. Write operational runbooks.
    
87. Review single points of failure.
    
88. Review secret access.
    
89. Review image provenance.
    
90. Sign off the production-readiness checklist.
    

---

# 43. Final troubleshooting challenge

Diagnose these scenarios.

## Scenario 1

```text
Pod status:
ImagePullBackOff
```

Investigate:

- Image repository
    
- Digest
    
- Pull Secret
    
- Registry access
    
- TLS trust
    
- Architecture support
    

---

## Scenario 2

```text
API Pods Running
READY 0/1
```

Investigate:

- Readiness path
    
- Database access
    
- NetworkPolicy
    
- Secret file
    
- Timeout
    
- Service endpoints
    

---

## Scenario 3

```text
Service exists
but EndpointSlice has no API endpoints
```

Investigate:

- Service selector
    
- Pod labels
    
- Readiness
    
- Namespace
    
- Deployment availability
    

---

## Scenario 4

```text
API restarts whenever PostgreSQL is unavailable
```

Likely design problem:

```text
Liveness probe depends too deeply
on PostgreSQL availability.
```

Fix:

```text
Liveness:
process health only

Readiness:
dependency health
```

---

## Scenario 5

```text
HPA requests ten Pods,
but six remain Pending.
```

Investigate:

- Worker capacity
    
- Node autoscaler
    
- Requests
    
- Anti-affinity
    
- Node selectors
    
- Taints
    
- Zone constraints
    

---

## Scenario 6

```text
Helm rollback succeeded,
but the old API crashes.
```

Likely cause:

```text
Database migration is incompatible
with the old application.
```

Helm rollback does not reverse the database.

---

## Scenario 7

```text
Database backup Job says Complete,
but restore fails.
```

Possible causes:

- Empty output
    
- Corrupt archive
    
- Missing encryption key
    
- Wrong version
    
- Missing WAL
    
- Incomplete backup upload
    
- Untested procedure
    

---

# 44. How to judge your final competence

You have reached a practical intermediate-to-advanced level when you can explain and demonstrate all of these distinctions:

```text
Image versus container
Container versus Pod
Pod versus Deployment
Deployment versus StatefulSet
Service versus Ingress
ConfigMap versus Secret
Volume versus backup
Replication versus backup
Readiness versus liveness
Requests versus limits
HPA versus node autoscaling
Role versus ClusterRole
Helm chart versus Helm release
CI deployment versus GitOps
Rollback versus database recovery
Running versus production-ready
```

The strongest evidence is not that you recognize the terms.

It is that you can:

```text
Build
Deploy
Observe
Diagnose
Secure
Scale
Upgrade
Roll back
Restore
Document
```

the system independently.

---

# 45. Skills gained over the 30 days

You now have practical knowledge of:

## Docker

- Images
    
- Containers
    
- Dockerfiles
    
- Layers
    
- Build cache
    
- Multi-stage builds
    
- Volumes
    
- Networks
    
- Compose
    
- Registries
    
- Security
    
- Resource limits
    

## Kubernetes

- Pods
    
- Deployments
    
- StatefulSets
    
- Services
    
- Ingress
    
- ConfigMaps
    
- Secrets
    
- PVCs
    
- Jobs
    
- Probes
    
- Scheduling
    
- Networking
    
- NetworkPolicy
    
- RBAC
    
- Security contexts
    
- HPA
    
- PDBs
    

## Delivery

- Helm
    
- Environment values
    
- CI/CD
    
- Immutable artifacts
    
- Image digests
    
- SBOMs
    
- Vulnerability scanning
    
- Staging promotion
    
- Production approval
    
- GitOps fundamentals
    

## Operations

- Logging
    
- Metrics
    
- Alerts
    
- Troubleshooting
    
- Capacity planning
    
- High availability
    
- Backups
    
- Restoration
    
- Disaster recovery
    
- Runbooks
    
- Incident response
    

---

# 46. Recommended next 90-day practice plan

## Month 1 — Consolidate Docker and application design

Build two real projects:

```text
1. Device API + PostgreSQL
2. MQTT consumer + Mosquitto
```

Focus on:

- Testing
    
- Signals
    
- Persistence
    
- Security
    
- Image optimization
    

## Month 2 — Operate Kubernetes

Run the complete platform on:

```text
Minikube
kind
or
a small multi-node test cluster
```

Practise:

- Node failures
    
- Networking
    
- Helm
    
- HPA
    
- PDB
    
- Backups
    
- Troubleshooting
    

## Month 3 — Automate and harden

Add:

- GitLab pipeline
    
- Image scanning
    
- Helm packaging
    
- Staging
    
- Production-style approval
    
- GitOps controller
    
- Metrics
    
- Alerts
    
- Restore exercises
    

Do not advance only by reading. Repeat failure and recovery exercises until the commands and mental models become natural.

---

# 47. Final architectural model

```text
Source code
      ↓
Automated tests
      ↓
Secure reproducible container images
      ↓
Registry with immutable digests
      ↓
Helm chart and environment values
      ↓
CI/CD validation and integration testing
      ↓
Kubernetes deployment
      ↓
Services, Ingress, and NetworkPolicies
      ↓
Secure identities, Secrets, and admission policy
      ↓
Logs, metrics, traces, and alerts
      ↓
Autoscaling, topology spreading, and PDBs
      ↓
Backups, restore tests, and disaster recovery
      ↓
Documented and operable production service
```

# Final lesson

> Containerization is not merely putting an application inside an image. Professional container engineering means building immutable artifacts, separating code from state and configuration, deploying them through declarative automation, restricting their privileges and communication, observing their real behavior, and preparing for failure before production failure occurs.

A good platform is not one that never fails.

A good platform:

```text
Detects failure quickly
Limits its impact
Preserves data
Recovers predictably
Explains what happened
Prevents recurrence
```