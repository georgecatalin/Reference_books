

Day 22 introduced the fundamental Kubernetes objects:

```text
Deployment
    ↓
ReplicaSet
    ↓
Pods
    ↓ selected by
Service
```

Today you will combine those objects into a real multi-component application:

```text
Device API
    ↓
PostgreSQL database
    ↓
Persistent storage
```

You will also add:

- A dedicated namespace
    
- ConfigMaps
    
- Secrets
    
- PersistentVolumeClaims
    
- A PostgreSQL StatefulSet
    
- Internal Services
    
- Init containers
    
- Health probes
    
- Resource controls
    
- A database migration Job
    
- Controlled deployment and troubleshooting
    

The central lesson is:

> A production-style Kubernetes application is not one YAML file containing a container. It is a collection of separate objects, each managing one concern: workload, networking, configuration, secrets, storage, initialization, and one-time operations.

Kubernetes Deployments normally manage stateless application workloads, while StatefulSets provide stable identities and persistent-storage behavior for stateful applications. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/controllers/deployment/?utm_source=chatgpt.com "Deployments"))

---

## 1. Day 23 objectives

By the end of today, you should understand:

- How to organize Kubernetes manifests
    
- How to deploy multiple related components
    
- How Services provide stable internal DNS
    
- How the API connects to PostgreSQL
    
- Why PostgreSQL should not be placed in the API Pod
    
- Deployment versus StatefulSet
    
- Headless and normal Services
    
- ConfigMaps for non-sensitive configuration
    
- Secrets for credentials
    
- Secret file mounts
    
- PersistentVolumeClaims
    
- StatefulSet volume claim templates
    
- Init containers
    
- Database readiness
    
- Kubernetes Jobs
    
- Database migrations
    
- Startup, readiness, and liveness probes
    
- Security contexts
    
- Resource requests and limits
    
- Namespace cleanup
    
- End-to-end troubleshooting
    

---

# 2. Today’s target architecture

You will build this:

```text
                    Local workstation

                         kubectl
                            │
                            ▼
                 Kubernetes API server
                            │
                            ▼

              Namespace: device-monitor

┌──────────────────────────────────────────────────────┐
│                                                      │
│  Service: device-api                                 │
│  Stable DNS: device-api                              │
│         │                                            │
│         ▼                                            │
│  Deployment: device-api                              │
│  ├── API Pod 1                                       │
│  ├── API Pod 2                                       │
│  └── API Pod 3                                       │
│         │                                            │
│         │ database:5432                              │
│         ▼                                            │
│  Service: database                                   │
│  Stable DNS: database                                │
│         │                                            │
│         ▼                                            │
│  StatefulSet: database                               │
│  └── PostgreSQL Pod database-0                       │
│         │                                            │
│         ▼                                            │
│  PersistentVolumeClaim                               │
│         │                                            │
│         ▼                                            │
│  PersistentVolume                                   │
│                                                      │
│  ConfigMap                                           │
│  Secrets                                             │
│  Migration Job                                       │
│                                                      │
└──────────────────────────────────────────────────────┘
```

The API is stateless and can run several interchangeable replicas.

PostgreSQL is stateful and requires persistent data.

---

# 3. Why the API and database use separate Pods

Do not create this:

```text
One Pod
├── API container
└── PostgreSQL container
```

Containers inside one Pod share a lifecycle.

If the Pod is replaced:

```text
API replaced
and
PostgreSQL replaced
```

They also scale together.

If you scale the Pod to three replicas, you accidentally create:

```text
3 API containers
3 independent PostgreSQL containers
```

That is not a valid database architecture.

The correct separation is:

```text
API Deployment
→ independently scalable

PostgreSQL StatefulSet
→ separately managed persistent workload
```

The two components communicate through a Kubernetes Service.

---

# 4. Deployment versus StatefulSet

## Deployment

A Deployment is normally used for stateless workloads.

Examples:

- APIs
    
- Web frontends
    
- Background workers
    
- MQTT consumers, when correctly designed
    
- Reverse proxies
    

A Deployment manages replaceable Pods and rolling updates. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/controllers/deployment/?utm_source=chatgpt.com "Deployments"))

## StatefulSet

A StatefulSet is designed for workloads that need one or more of:

- Stable Pod names
    
- Stable network identities
    
- Persistent storage associated with each replica
    
- Ordered creation
    
- Ordered termination
    
- Controlled scaling
    

StatefulSet Pods receive predictable names such as:

```text
database-0
database-1
database-2
```

StatefulSets maintain sticky identities for their Pods and are useful for applications requiring stable storage or network identity. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/controllers/statefulset/?utm_source=chatgpt.com "StatefulSets"))

For today:

```text
API
→ Deployment

PostgreSQL
→ StatefulSet with one replica
```

One StatefulSet replica does not provide database high availability. It provides structured stateful workload management.

---

# 5. Prepare the project directory

Create:

```bash
mkdir -p ~/docker-course/day23/kubernetes
cd ~/docker-course/day23/kubernetes
```

Use this structure:

```text
kubernetes/
├── 00-namespace.yaml
├── 01-configmap.yaml
├── 02-secret.yaml
├── 03-database-service.yaml
├── 04-database-statefulset.yaml
├── 05-migration-job.yaml
├── 06-api-deployment.yaml
├── 07-api-service.yaml
└── kustomization.yaml
```

The numeric prefixes communicate the intended reading order.

Kubernetes does not guarantee that ordinary resources are applied solely according to filename order, so the applications must still tolerate dependency startup delays.

---

# 6. Confirm your cluster

Start Minikube if needed:

```bash
minikube start --driver=docker
```

Check:

```bash
minikube status
kubectl cluster-info
kubectl get nodes
```

Expected:

```text
NAME       STATUS   ROLES           AGE   VERSION
minikube   Ready    control-plane   ...   ...
```

---

# 7. Create a dedicated namespace

Create `00-namespace.yaml`:

```yaml
apiVersion: v1
kind: Namespace

metadata:
  name: device-monitor

  labels:
    app.kubernetes.io/part-of: device-monitor
```

Apply:

```bash
kubectl apply -f 00-namespace.yaml
```

Set the current context:

```bash
kubectl config set-context \
  --current \
  --namespace=device-monitor
```

Verify:

```bash
kubectl config view \
  --minify \
  -o jsonpath='{..namespace}'

echo
```

Expected:

```text
device-monitor
```

A separate namespace makes it easier to:

- List application resources
    
- Apply quotas later
    
- Apply access controls
    
- Remove the entire lab
    
- Separate environments
    

---

# 8. Create the application ConfigMap

Non-sensitive configuration belongs in a ConfigMap.

Create `01-configmap.yaml`:

```yaml
apiVersion: v1
kind: ConfigMap

metadata:
  name: device-monitor-config

  labels:
    app.kubernetes.io/part-of: device-monitor

data:
  APP_ENV: development
  APP_VERSION: "1.0.0"
  LOG_LEVEL: INFO

  DB_HOST: database
  DB_PORT: "5432"
  DB_NAME: device_monitor
  DB_USER: device_app
```

Apply:

```bash
kubectl apply -f 01-configmap.yaml
```

Inspect:

```bash
kubectl get configmap device-monitor-config
```

```bash
kubectl describe configmap device-monitor-config
```

The ConfigMap stores:

```text
Application mode
Log level
Database hostname
Database port
Database name
Database username
```

It must not contain the database password.

---

# 9. Why `DB_HOST=database`

You will create a Service named:

```text
database
```

Inside the same namespace, Kubernetes DNS allows the API to connect using:

```text
database:5432
```

This remains stable even when the PostgreSQL Pod is replaced.

Do not use:

```text
localhost
```

because PostgreSQL runs in another Pod.

Do not use the PostgreSQL Pod IP because Pod IP addresses are disposable.

The familiar pattern continues:

```text
Docker Compose:
database:5432

Docker Swarm:
database:5432

Kubernetes:
database:5432
```

---

# 10. Create the database Secret

For this training lab, create the Secret manifest with `stringData`.

Create `02-secret.yaml`:

```yaml
apiVersion: v1
kind: Secret

metadata:
  name: database-credentials

  labels:
    app.kubernetes.io/part-of: device-monitor

type: Opaque

stringData:
  username: device_app
  password: development-password
```

Apply:

```bash
kubectl apply -f 02-secret.yaml
```

Inspect only metadata:

```bash
kubectl get secret database-credentials
```

Do not commit real production credentials to Git.

Kubernetes Secrets separate sensitive values from Pod definitions and images, but access still needs to be protected through authorization and encryption practices. Base64 storage alone is not encryption. ([Kubernetes](https://kubernetes.io/docs/concepts/configuration/secret/?utm_source=chatgpt.com "Secrets"))

---

# 11. Safer imperative Secret creation

For a real repository, exclude `02-secret.yaml` and create the Secret separately:

```bash
kubectl create secret generic database-credentials \
  --from-literal=username=device_app \
  --from-literal=password="$(openssl rand -base64 36)"
```

Or from files:

```bash
mkdir -p secrets

printf '%s' 'device_app' \
  > secrets/database-username.txt

openssl rand -base64 36 \
  > secrets/database-password.txt

chmod 600 secrets/*
```

Create:

```bash
kubectl create secret generic database-credentials \
  --from-file=username=secrets/database-username.txt \
  --from-file=password=secrets/database-password.txt
```

Add to `.gitignore`:

```text
secrets/
02-secret.yaml
```

---

# 12. Create the PostgreSQL Service

Create `03-database-service.yaml`:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: database

  labels:
    app.kubernetes.io/name: postgres
    app.kubernetes.io/component: database
    app.kubernetes.io/part-of: device-monitor

spec:
  type: ClusterIP

  selector:
    app.kubernetes.io/name: postgres
    app.kubernetes.io/component: database

  ports:
    - name: postgres
      port: 5432
      targetPort: postgres
      protocol: TCP
```

Apply:

```bash
kubectl apply -f 03-database-service.yaml
```

Inspect:

```bash
kubectl get service database
```

```bash
kubectl describe service database
```

Initially, the Service may show no endpoints because the PostgreSQL Pod does not yet exist.

---

# 13. Normal Service versus headless Service

A normal ClusterIP Service receives a stable virtual cluster address:

```yaml
type: ClusterIP
```

Clients connect to:

```text
database:5432
```

A headless Service uses:

```yaml
clusterIP: None
```

It does not provide the usual virtual IP load-balancing behavior. Instead, it exposes individual Pod DNS information.

Headless Services are often used with StatefulSets requiring direct Pod identity.

For today’s single PostgreSQL instance, a normal ClusterIP Service is sufficient for API access.

For a replicated stateful database, you might need both:

```text
Headless Service
→ direct member identity

Normal Service
→ stable client endpoint
```

---

# 14. Create the PostgreSQL StatefulSet

Create `04-database-statefulset.yaml`:

```yaml
apiVersion: apps/v1
kind: StatefulSet

metadata:
  name: database

  labels:
    app.kubernetes.io/name: postgres
    app.kubernetes.io/component: database
    app.kubernetes.io/part-of: device-monitor

spec:
  serviceName: database

  replicas: 1

  selector:
    matchLabels:
      app.kubernetes.io/name: postgres
      app.kubernetes.io/component: database

  template:
    metadata:
      labels:
        app.kubernetes.io/name: postgres
        app.kubernetes.io/component: database
        app.kubernetes.io/part-of: device-monitor

    spec:
      securityContext:
        fsGroup: 999
        fsGroupChangePolicy: OnRootMismatch

      containers:
        - name: postgres

          image: postgres:17

          imagePullPolicy: IfNotPresent

          ports:
            - name: postgres
              containerPort: 5432
              protocol: TCP

          env:
            - name: POSTGRES_DB
              valueFrom:
                configMapKeyRef:
                  name: device-monitor-config
                  key: DB_NAME

            - name: POSTGRES_USER
              valueFrom:
                secretKeyRef:
                  name: database-credentials
                  key: username

            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: database-credentials
                  key: password

            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata

          volumeMounts:
            - name: postgres-data
              mountPath: /var/lib/postgresql/data

          startupProbe:
            exec:
              command:
                - sh
                - -c
                - >
                  pg_isready
                  -U "$POSTGRES_USER"
                  -d "$POSTGRES_DB"

            periodSeconds: 5
            timeoutSeconds: 3
            failureThreshold: 30

          readinessProbe:
            exec:
              command:
                - sh
                - -c
                - >
                  pg_isready
                  -U "$POSTGRES_USER"
                  -d "$POSTGRES_DB"

            periodSeconds: 5
            timeoutSeconds: 3
            failureThreshold: 3

          livenessProbe:
            exec:
              command:
                - sh
                - -c
                - >
                  pg_isready
                  -U "$POSTGRES_USER"
                  -d "$POSTGRES_DB"

            periodSeconds: 15
            timeoutSeconds: 3
            failureThreshold: 5

          resources:
            requests:
              cpu: "100m"
              memory: "256Mi"

            limits:
              cpu: "1"
              memory: "1Gi"

  volumeClaimTemplates:
    - metadata:
        name: postgres-data

      spec:
        accessModes:
          - ReadWriteOnce

        resources:
          requests:
            storage: 2Gi
```

---

# 15. Understand `serviceName`

The StatefulSet contains:

```yaml
serviceName: database
```

A StatefulSet requires a governing Service identity.

For sophisticated stateful clusters, this is commonly associated with a headless Service so individual members receive stable DNS names.

The Pod name will be:

```text
database-0
```

Its identity remains associated with the StatefulSet ordinal:

```text
0
```

If the Pod is recreated, Kubernetes normally creates another:

```text
database-0
```

rather than a randomly named Deployment Pod.

---

# 16. Understand `volumeClaimTemplates`

The StatefulSet declares:

```yaml
volumeClaimTemplates:
  - metadata:
      name: postgres-data
```

Kubernetes creates a PersistentVolumeClaim for each StatefulSet Pod.

For the first replica, the claim will resemble:

```text
postgres-data-database-0
```

The relationship is:

```text
StatefulSet Pod:
database-0

PersistentVolumeClaim:
postgres-data-database-0
```

If the Pod is replaced, the new `database-0` Pod can mount the same claim.

PersistentVolumes and PersistentVolumeClaims separate storage lifecycle from Pod lifecycle. ([Kubernetes](https://kubernetes.io/docs/concepts/storage/persistent-volumes/?utm_source=chatgpt.com "Persistent Volumes"))

---

# 17. Why use a subdirectory for `PGDATA`

The environment contains:

```yaml
PGDATA: /var/lib/postgresql/data/pgdata
```

Some storage volumes may include existing filesystem content or ownership behavior at their mount root.

Using a dedicated subdirectory often makes PostgreSQL initialization more predictable:

```text
Mounted volume:
/var/lib/postgresql/data

PostgreSQL data:
/var/lib/postgresql/data/pgdata
```

---

# 18. Apply the database

Run:

```bash
kubectl apply \
  -f 04-database-statefulset.yaml
```

Watch:

```bash
kubectl get pods --watch
```

Expected progression:

```text
database-0   Pending
database-0   ContainerCreating
database-0   Running
database-0   Running  READY 1/1
```

Stop watching with `Ctrl+C`.

Inspect StatefulSet:

```bash
kubectl get statefulsets
```

Inspect the Pod:

```bash
kubectl describe pod database-0
```

View logs:

```bash
kubectl logs database-0
```

---

# 19. Inspect persistent storage

List claims:

```bash
kubectl get persistentvolumeclaims
```

Expected:

```text
NAME                       STATUS   VOLUME       CAPACITY
postgres-data-database-0   Bound    pvc-...      2Gi
```

List volumes:

```bash
kubectl get persistentvolumes
```

Inspect the claim:

```bash
kubectl describe pvc \
  postgres-data-database-0
```

Check the StorageClass:

```bash
kubectl get storageclasses
```

Minikube will normally dynamically provision local lab storage for a simple claim.

---

# 20. Verify PostgreSQL readiness

Run:

```bash
kubectl exec database-0 -- \
  pg_isready \
    -U device_app \
    -d device_monitor
```

Expected:

```text
accepting connections
```

Execute SQL:

```bash
kubectl exec database-0 -- \
  psql \
    -U device_app \
    -d device_monitor \
    -c 'SELECT current_database(), current_user;'
```

You should see:

```text
current_database | current_user
device_monitor   | device_app
```

---

# 21. Inspect Service endpoints

Now run:

```bash
kubectl get endpoints database
```

Or:

```bash
kubectl get endpointslices
```

The database Pod should now appear as a ready backend.

If the endpoint remains empty, check:

```bash
kubectl get pod database-0 \
  --show-labels
```

Then compare those labels with:

```bash
kubectl get service database \
  -o yaml
```

---

# 22. Test database DNS from another Pod

Create a temporary client:

```bash
kubectl run postgres-client \
  --image=postgres:17 \
  --restart=Never \
  -- sleep 3600
```

Wait:

```bash
kubectl wait \
  --for=condition=Ready \
  pod/postgres-client \
  --timeout=120s
```

Resolve the Service:

```bash
kubectl exec postgres-client -- \
  getent hosts database
```

Connect:

```bash
kubectl exec postgres-client -- \
  sh -c '
    PGPASSWORD="development-password" \
    psql \
      -h database \
      -U device_app \
      -d device_monitor \
      -c "SELECT 1;"
  '
```

Remove:

```bash
kubectl delete pod postgres-client
```

---

# 23. Init containers

An init container runs before the main application containers in the same Pod.

Regular init containers:

- Run sequentially
    
- Must complete successfully
    
- Run before application containers start
    
- Can use different images
    
- Can prepare files or check prerequisites
    

Kubernetes does not start the app containers until all regular init containers have completed successfully. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/pods/init-containers/?utm_source=chatgpt.com "Init Containers"))

Common uses:

- Wait for a dependency
    
- Prepare writable directories
    
- Download configuration
    
- Generate files
    
- Check network connectivity
    
- Perform lightweight initialization
    

---

# 24. Do not confuse init containers with readiness probes

An init container answers:

```text
Can application startup proceed?
```

A readiness probe answers continuously:

```text
Should this running Pod receive traffic now?
```

An init container runs only during Pod initialization.

A readiness probe continues throughout the application lifecycle.

You often need both:

```text
Init container:
Wait for database DNS and TCP access before API starts

Readiness probe:
Remove API Pod from Service traffic whenever API is temporarily unready
```

---

# 25. Add an API init container

The API Deployment will use an init container to wait for PostgreSQL.

The logic is:

```text
Try database:5432
    ↓
Unavailable?
Wait and retry
    ↓
Available?
Complete init container
    ↓
Start API container
```

This avoids starting the API before basic database networking exists.

The API itself must still implement database retries because PostgreSQL may become unavailable later.

---

# 26. Database schema initialization

There are three common patterns:

## Application startup migration

Every application instance attempts migration during startup.

Risk:

```text
Three API replicas
→ three concurrent migration attempts
```

This may cause conflicts.

## Init container migration

Each API Pod runs the migration init container.

Risk:

```text
Three API Pods
→ three migration executions
```

This is only safe when migrations are designed for concurrent execution.

## Kubernetes Job

A separate one-time Job runs the migration.

This is usually clearer:

```text
Migration Job completes
    ↓
Deploy or restart API
```

A Kubernetes Job creates one or more Pods and retries them until the required successful completions are reached. Jobs are intended for one-time tasks that run to completion. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/controllers/job/?utm_source=chatgpt.com "Jobs"))

Today you will use a Job.

---

# 27. Create the migration Job

This example assumes your API image supports a migration command.

For a Flask application, that could be:

```text
python -m app.migrate
```

or:

```text
flask db upgrade
```

You must adapt the command to your application.

Create `05-migration-job.yaml`:

```yaml
apiVersion: batch/v1
kind: Job

metadata:
  name: device-api-migration

  labels:
    app.kubernetes.io/name: device-api
    app.kubernetes.io/component: migration
    app.kubernetes.io/part-of: device-monitor

spec:
  backoffLimit: 3

  ttlSecondsAfterFinished: 600

  template:
    metadata:
      labels:
        app.kubernetes.io/name: device-api
        app.kubernetes.io/component: migration

    spec:
      restartPolicy: OnFailure

      initContainers:
        - name: wait-for-database

          image: postgres:17

          command:
            - sh
            - -c
            - |
              until pg_isready \
                -h database \
                -p 5432 \
                -U "$DB_USER" \
                -d "$DB_NAME"
              do
                echo "Waiting for PostgreSQL..."
                sleep 2
              done

          env:
            - name: DB_NAME
              valueFrom:
                configMapKeyRef:
                  name: device-monitor-config
                  key: DB_NAME

            - name: DB_USER
              valueFrom:
                secretKeyRef:
                  name: database-credentials
                  key: username

      containers:
        - name: migration

          image: YOUR_REGISTRY/device-api:1.0.0

          imagePullPolicy: IfNotPresent

          command:
            - python
            - -m
            - app.migrate

          envFrom:
            - configMapRef:
                name: device-monitor-config

          env:
            - name: DB_PASSWORD_FILE
              value: /run/secrets/database/password

          volumeMounts:
            - name: database-credentials
              mountPath: /run/secrets/database
              readOnly: true

      volumes:
        - name: database-credentials

          secret:
            secretName: database-credentials

            items:
              - key: password
                path: password
```

Replace:

```text
YOUR_REGISTRY/device-api:1.0.0
```

with your actual image.

Replace:

```text
python -m app.migrate
```

with a real command supported by your application.

---

# 28. Temporary migration alternative

If your current API automatically creates its database table at startup and does not yet have a dedicated migration command, use a training Job that creates a simple table.

Replace the migration container with:

```yaml
containers:
  - name: migration

    image: postgres:17

    command:
      - sh
      - -c
      - |
        export PGPASSWORD="$(cat /run/secrets/database/password)"

        psql \
          -h database \
          -U "$DB_USER" \
          -d "$DB_NAME" \
          -v ON_ERROR_STOP=1 \
          <<'SQL'
        CREATE TABLE IF NOT EXISTS devices (
          id BIGSERIAL PRIMARY KEY,
          device_name TEXT NOT NULL UNIQUE,
          online BOOLEAN NOT NULL DEFAULT FALSE,
          firmware_version TEXT,
          created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );
        SQL

    env:
      - name: DB_NAME
        valueFrom:
          configMapKeyRef:
            name: device-monitor-config
            key: DB_NAME

      - name: DB_USER
        valueFrom:
          secretKeyRef:
            name: database-credentials
            key: username

      - name: DB_PASSWORD_FILE
        value: /run/secrets/database/password

    volumeMounts:
      - name: database-credentials
        mountPath: /run/secrets/database
        readOnly: true
```

This lets you practice the Kubernetes Job lifecycle without modifying your API image.

---

# 29. Apply and monitor the migration

Run:

```bash
kubectl apply \
  -f 05-migration-job.yaml
```

Watch:

```bash
kubectl get jobs
```

```bash
kubectl get pods \
  -l app.kubernetes.io/component=migration
```

Follow logs:

```bash
kubectl logs \
  job/device-api-migration
```

Wait:

```bash
kubectl wait \
  --for=condition=complete \
  job/device-api-migration \
  --timeout=180s
```

Inspect:

```bash
kubectl describe job device-api-migration
```

A completed Job should show:

```text
Complete
```

---

# 30. Re-running a Job

A completed Job does not normally rerun merely because you apply the same object again.

To repeat it:

```bash
kubectl delete job device-api-migration
```

Then:

```bash
kubectl apply \
  -f 05-migration-job.yaml
```

In real CI/CD pipelines, common methods include:

- A unique Job name per release
    
- A generated suffix
    
- Helm hooks
    
- A pipeline-controlled Job
    
- A migration tool that records applied versions
    

Example release-specific name:

```text
device-api-migration-1-0-1
```

---

# 31. Create the API Deployment

Create `06-api-deployment.yaml`:

```yaml
apiVersion: apps/v1
kind: Deployment

metadata:
  name: device-api

  labels:
    app.kubernetes.io/name: device-api
    app.kubernetes.io/component: api
    app.kubernetes.io/part-of: device-monitor

spec:
  replicas: 3

  revisionHistoryLimit: 5

  strategy:
    type: RollingUpdate

    rollingUpdate:
      maxUnavailable: 1
      maxSurge: 1

  selector:
    matchLabels:
      app.kubernetes.io/name: device-api
      app.kubernetes.io/component: api

  template:
    metadata:
      labels:
        app.kubernetes.io/name: device-api
        app.kubernetes.io/component: api
        app.kubernetes.io/part-of: device-monitor

    spec:
      securityContext:
        runAsNonRoot: true
        seccompProfile:
          type: RuntimeDefault

      initContainers:
        - name: wait-for-database

          image: postgres:17

          command:
            - sh
            - -c
            - |
              until pg_isready \
                -h database \
                -p 5432 \
                -U "$DB_USER" \
                -d "$DB_NAME"
              do
                echo "Waiting for PostgreSQL..."
                sleep 2
              done

          env:
            - name: DB_NAME
              valueFrom:
                configMapKeyRef:
                  name: device-monitor-config
                  key: DB_NAME

            - name: DB_USER
              valueFrom:
                secretKeyRef:
                  name: database-credentials
                  key: username

          securityContext:
            allowPrivilegeEscalation: false

            capabilities:
              drop:
                - ALL

      containers:
        - name: device-api

          image: YOUR_REGISTRY/device-api:1.0.0

          imagePullPolicy: IfNotPresent

          ports:
            - name: http
              containerPort: 5000
              protocol: TCP

          envFrom:
            - configMapRef:
                name: device-monitor-config

          env:
            - name: DB_PASSWORD_FILE
              value: /run/secrets/database/password

            - name: PYTHONUNBUFFERED
              value: "1"

          volumeMounts:
            - name: database-credentials
              mountPath: /run/secrets/database
              readOnly: true

            - name: temporary-storage
              mountPath: /tmp

          startupProbe:
            httpGet:
              path: /health
              port: http

            periodSeconds: 5
            timeoutSeconds: 3
            failureThreshold: 30

          readinessProbe:
            httpGet:
              path: /health
              port: http

            periodSeconds: 5
            timeoutSeconds: 3
            failureThreshold: 3

          livenessProbe:
            httpGet:
              path: /health
              port: http

            periodSeconds: 15
            timeoutSeconds: 3
            failureThreshold: 5

          resources:
            requests:
              cpu: "100m"
              memory: "128Mi"

            limits:
              cpu: "1"
              memory: "512Mi"

          securityContext:
            runAsNonRoot: true
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true

            capabilities:
              drop:
                - ALL

      volumes:
        - name: database-credentials

          secret:
            secretName: database-credentials

            items:
              - key: password
                path: password

        - name: temporary-storage
          emptyDir:
            sizeLimit: 64Mi
```

Replace the image reference with your real registry image.

---

# 32. Understand the API security context

At Pod level:

```yaml
securityContext:
  runAsNonRoot: true

  seccompProfile:
    type: RuntimeDefault
```

At container level:

```yaml
securityContext:
  runAsNonRoot: true
  allowPrivilegeEscalation: false
  readOnlyRootFilesystem: true

  capabilities:
    drop:
      - ALL
```

This applies the Docker security lessons from Day 17:

```text
Non-root process
Dropped capabilities
No privilege escalation
Read-only root filesystem
Default seccomp behavior
Explicit writable temporary volume
```

Kubernetes security contexts control privilege and access settings for Pods and containers. ([Kubernetes](https://kubernetes.io/docs/tasks/configure-pod-container/security-context/?utm_source=chatgpt.com "Configure a Security Context for a Pod or Container"))

---

# 33. Why mount `emptyDir` at `/tmp`

The API root filesystem is read-only:

```yaml
readOnlyRootFilesystem: true
```

Some Python libraries may need temporary storage.

The Deployment adds:

```yaml
emptyDir:
  sizeLimit: 64Mi
```

and mounts it at:

```text
/tmp
```

`emptyDir` exists for the lifetime of the Pod.

It survives an individual container restart inside the same Pod but is removed when the Pod is removed.

It must not contain persistent application data.

---

# 34. Understand the three API probes

## Startup probe

```text
Has the application finished starting?
```

Until it succeeds, Kubernetes does not apply the liveness decision in the normal way.

## Readiness probe

```text
Should this Pod receive Service traffic?
```

When readiness fails, the Pod remains running but is removed from Service endpoints.

## Liveness probe

```text
Is the container stuck and should it be restarted?
```

Kubernetes probes are periodic diagnostics. Depending on probe type and result, Kubernetes may stop routing traffic or restart a container. ([Kubernetes](https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/?utm_source=chatgpt.com "Configure Liveness, Readiness and Startup Probes"))

---

# 35. Be careful with the API `/health` endpoint

A common mistake is using the exact same deep health check for:

```text
Startup
Readiness
Liveness
```

Suppose `/health` fails whenever PostgreSQL is unavailable.

Then:

```text
Database outage
    ↓
API liveness fails
    ↓
Kubernetes restarts API
    ↓
Database remains unavailable
    ↓
API restarts repeatedly
```

A better design is:

```text
/startup
→ Application initialized

/live
→ Process and event loop function

/ready
→ API can serve useful requests, including dependencies
```

Then:

```yaml
startupProbe:
  path: /startup

livenessProbe:
  path: /live

readinessProbe:
  path: /ready
```

For today’s lab, `/health` is acceptable, but your production API should separate these concepts.

---

# 36. Apply the API Deployment

Run:

```bash
kubectl apply \
  -f 06-api-deployment.yaml
```

Watch:

```bash
kubectl get pods --watch
```

You may initially see:

```text
Init:0/1
```

This means the init container has not completed.

After PostgreSQL is ready:

```text
PodInitializing
Running
READY 1/1
```

Inspect rollout:

```bash
kubectl rollout status \
  deployment/device-api
```

Inspect Pods:

```bash
kubectl get pods \
  -l app.kubernetes.io/name=device-api \
  -o wide
```

---

# 37. Inspect init-container results

Describe one API Pod:

```bash
kubectl describe pod API_POD_NAME
```

Read init-container logs:

```bash
kubectl logs \
  API_POD_NAME \
  --container wait-for-database
```

Read API logs:

```bash
kubectl logs \
  API_POD_NAME \
  --container device-api
```

If the init container is currently failing:

```bash
kubectl logs \
  API_POD_NAME \
  --container wait-for-database
```

The application container will not start until the regular init container succeeds. ([Kubernetes](https://kubernetes.io/docs/tasks/debug/debug-application/debug-init-containers/?utm_source=chatgpt.com "Debug Init Containers"))

---

# 38. Create the API Service

Create `07-api-service.yaml`:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: device-api

  labels:
    app.kubernetes.io/name: device-api
    app.kubernetes.io/component: api
    app.kubernetes.io/part-of: device-monitor

spec:
  type: ClusterIP

  selector:
    app.kubernetes.io/name: device-api
    app.kubernetes.io/component: api

  ports:
    - name: http
      port: 80
      targetPort: http
      protocol: TCP
```

Apply:

```bash
kubectl apply \
  -f 07-api-service.yaml
```

Inspect:

```bash
kubectl get service device-api
```

```bash
kubectl get endpoints device-api
```

The endpoint list should include only ready API Pods.

---

# 39. Access the API

Forward local port 8080 to Service port 80:

```bash
kubectl port-forward \
  service/device-api \
  8080:80
```

In another terminal:

```bash
curl --fail \
  http://127.0.0.1:8080/health
```

List devices:

```bash
curl --silent \
  http://127.0.0.1:8080/api/devices \
  | python3 -m json.tool
```

Create a device:

```bash
curl --silent \
  --request POST \
  http://127.0.0.1:8080/api/devices \
  --header 'Content-Type: application/json' \
  --data '{
    "device_name": "kubernetes-device-01",
    "online": true,
    "firmware_version": "3.0.0"
  }' \
  | python3 -m json.tool
```

Stop forwarding with `Ctrl+C`.

---

# 40. Verify database persistence

Query PostgreSQL directly:

```bash
kubectl exec database-0 -- \
  psql \
    -U device_app \
    -d device_monitor \
    -c 'SELECT * FROM devices ORDER BY id;'
```

Delete the database Pod:

```bash
kubectl delete pod database-0
```

Watch:

```bash
kubectl get pods --watch
```

The StatefulSet creates a replacement:

```text
database-0
```

After it is ready, query again:

```bash
kubectl exec database-0 -- \
  psql \
    -U device_app \
    -d device_monitor \
    -c 'SELECT * FROM devices ORDER BY id;'
```

The device record should remain because the PersistentVolumeClaim was retained.

---

# 41. Confirm claim reuse

Run:

```bash
kubectl get pvc
```

Before and after deleting the Pod, the claim should remain:

```text
postgres-data-database-0
```

Inspect the recreated Pod:

```bash
kubectl describe pod database-0
```

Look at:

```text
Volumes
Mounts
PersistentVolumeClaim
```

This demonstrates:

```text
Pod lifecycle
≠
Persistent storage lifecycle
```

---

# 42. Test API self-healing

List API Pods:

```bash
kubectl get pods \
  -l app.kubernetes.io/name=device-api
```

Delete one:

```bash
kubectl delete pod API_POD_NAME
```

Watch:

```bash
kubectl get pods \
  -l app.kubernetes.io/name=device-api \
  --watch
```

The Deployment creates another Pod.

The Service routes traffic only to ready Pods, so the other replicas remain available.

---

# 43. Test database outage behavior

Stop PostgreSQL by scaling the StatefulSet:

```bash
kubectl scale statefulset database \
  --replicas=0
```

Check:

```bash
kubectl get pods
```

Test the API:

```bash
kubectl port-forward \
  service/device-api \
  8080:80
```

Then:

```bash
curl -i \
  http://127.0.0.1:8080/health
```

Observe API Pods:

```bash
kubectl get pods
```

Check readiness:

```bash
kubectl describe pod API_POD_NAME
```

Restore:

```bash
kubectl scale statefulset database \
  --replicas=1
```

Watch recovery:

```bash
kubectl get pods --watch
```

The API should implement retries and recover without requiring manual Pod deletion.

---

# 44. ConfigMap update behavior

Change:

```yaml
LOG_LEVEL: INFO
```

to:

```yaml
LOG_LEVEL: DEBUG
```

Apply:

```bash
kubectl apply \
  -f 01-configmap.yaml
```

Because the API reads the ConfigMap through environment variables:

```yaml
envFrom:
```

existing Pods do not automatically receive updated environment values.

Restart the Deployment:

```bash
kubectl rollout restart \
  deployment/device-api
```

Wait:

```bash
kubectl rollout status \
  deployment/device-api
```

New Pods receive the updated value.

Check:

```bash
kubectl exec API_POD_NAME -- \
  printenv LOG_LEVEL
```

---

# 45. ConfigMap mounted as files

When a ConfigMap is mounted as a volume, Kubernetes can update the projected files after the ConfigMap changes, though the update is not instantaneous.

The application must also reread the file.

Example:

```yaml
volumeMounts:
  - name: application-config
    mountPath: /etc/device-api
    readOnly: true

volumes:
  - name: application-config
    configMap:
      name: device-monitor-config
```

This produces files such as:

```text
/etc/device-api/APP_ENV
/etc/device-api/LOG_LEVEL
```

Environment variables remain fixed for the lifetime of the container.

---

# 46. Secret update behavior

Secrets mounted as projected files can be updated in the mounted content.

But:

- The application must reread them.
    
- Some credentials require server-side rotation too.
    
- Environment variables do not update in existing containers.
    
- A new value does not automatically invalidate the old one.
    
- Database password rotation must coordinate PostgreSQL and API configuration.
    

A safe credential rotation generally uses an overlap period or explicit coordinated procedure.

---

# 47. Use Kustomize for the complete set

`kubectl` includes Kustomize-based manifest composition.

Create `kustomization.yaml`:

```yaml
apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: device-monitor

resources:
  - 00-namespace.yaml
  - 01-configmap.yaml
  - 02-secret.yaml
  - 03-database-service.yaml
  - 04-database-statefulset.yaml
  - 05-migration-job.yaml
  - 06-api-deployment.yaml
  - 07-api-service.yaml

commonLabels:
  course: docker-30-days
  day: "23"
```

Preview:

```bash
kubectl kustomize .
```

Apply:

```bash
kubectl apply \
  -k .
```

Delete:

```bash
kubectl delete \
  -k .
```

Do not include a plaintext production Secret resource in a Git-managed Kustomization.

---

# 48. Applying the resources in a safer operational sequence

For a first deployment:

```bash
kubectl apply -f 00-namespace.yaml

kubectl apply -f 01-configmap.yaml
kubectl apply -f 02-secret.yaml

kubectl apply -f 03-database-service.yaml
kubectl apply -f 04-database-statefulset.yaml
```

Wait for PostgreSQL:

```bash
kubectl rollout status \
  statefulset/database \
  --timeout=180s
```

Apply migration:

```bash
kubectl apply -f 05-migration-job.yaml
```

Wait:

```bash
kubectl wait \
  --for=condition=complete \
  job/device-api-migration \
  --timeout=180s
```

Then deploy API:

```bash
kubectl apply -f 06-api-deployment.yaml
kubectl apply -f 07-api-service.yaml
```

Verify:

```bash
kubectl rollout status \
  deployment/device-api
```

Dependency ordering should not replace application-level retry logic.

---

# 49. Rolling API update

Change:

```yaml
image: YOUR_REGISTRY/device-api:1.0.0
```

to:

```yaml
image: YOUR_REGISTRY/device-api:1.0.1
```

Apply:

```bash
kubectl apply \
  -f 06-api-deployment.yaml
```

Watch:

```bash
kubectl rollout status \
  deployment/device-api
```

Inspect ReplicaSets:

```bash
kubectl get replicasets
```

Inspect Pods:

```bash
kubectl get pods \
  -l app.kubernetes.io/name=device-api \
  -o wide
```

The old ReplicaSet remains for rollback history until revision limits remove it.

---

# 50. Roll back the API

View history:

```bash
kubectl rollout history \
  deployment/device-api
```

Undo:

```bash
kubectl rollout undo \
  deployment/device-api
```

Wait:

```bash
kubectl rollout status \
  deployment/device-api
```

Check image:

```bash
kubectl get deployment device-api \
  -o jsonpath='{.spec.template.spec.containers[0].image}'

echo
```

Application rollback does not reverse database migrations.

---

# 51. PostgreSQL update considerations

Do not casually change:

```yaml
image: postgres:17
```

to a new major version such as:

```yaml
image: postgres:18
```

and expect the same data directory to work automatically.

Database major upgrades may require:

- Backup
    
- Compatibility review
    
- Migration tooling
    
- `pg_upgrade`
    
- Export/import
    
- Downtime planning
    
- Rollback planning
    

Container image replacement does not eliminate database upgrade procedures.

---

# 52. Database backup from Kubernetes

Create a local backup:

```bash
kubectl exec database-0 -- \
  pg_dump \
    -U device_app \
    -d device_monitor \
    --format=custom \
  > device-monitor.dump
```

Check:

```bash
ls -lh device-monitor.dump
```

Inspect with a local PostgreSQL tool or container:

```bash
docker run --rm \
  --mount \
    type=bind,source="$PWD",target=/backup,readonly \
  postgres:17 \
  pg_restore \
    --list \
    /backup/device-monitor.dump
```

A PersistentVolume is not a backup.

A database backup should be copied outside the Kubernetes cluster’s storage failure domain.

---

# 53. Use a CronJob for scheduled backups

A CronJob creates Jobs on a recurring schedule and is suitable for repeated tasks such as backups and report generation. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/controllers/cron-jobs/?utm_source=chatgpt.com "CronJob"))

Conceptual example:

```yaml
apiVersion: batch/v1
kind: CronJob

metadata:
  name: database-backup

spec:
  schedule: "0 2 * * *"

  concurrencyPolicy: Forbid

  successfulJobsHistoryLimit: 3
  failedJobsHistoryLimit: 3

  jobTemplate:
    spec:
      template:
        spec:
          restartPolicy: OnFailure

          containers:
            - name: backup

              image: postgres:17

              command:
                - sh
                - -c
                - |
                  export PGPASSWORD="$(cat /run/secrets/database/password)"

                  pg_dump \
                    -h database \
                    -U device_app \
                    -d device_monitor \
                    --format=custom \
                    --file=/backup/device-monitor.dump
```

A real backup CronJob also needs:

- Persistent backup destination
    
- Unique backup filenames
    
- Retention policy
    
- Off-cluster copy
    
- Restore testing
    
- Monitoring and alerting
    
- Encryption where needed
    

---

# 54. Common failure: API Pods stuck in init

Symptom:

```text
Init:0/1
```

Inspect:

```bash
kubectl describe pod API_POD_NAME
```

Read init logs:

```bash
kubectl logs \
  API_POD_NAME \
  --container wait-for-database
```

Possible causes:

- Database Service missing
    
- PostgreSQL Pod not ready
    
- DNS resolution failure
    
- Wrong DB name
    
- Wrong username
    
- NetworkPolicy blocking traffic
    
- Database process failed
    
- Service selector mismatch
    

Check:

```bash
kubectl get service database
kubectl get endpoints database
kubectl get pod database-0
kubectl logs database-0
```

---

# 55. Common failure: PostgreSQL Pod pending

Symptom:

```text
database-0   Pending
```

Describe:

```bash
kubectl describe pod database-0
```

Check the PVC:

```bash
kubectl get pvc
kubectl describe pvc postgres-data-database-0
```

Possible causes:

- No default StorageClass
    
- Provisioner unavailable
    
- Insufficient storage
    
- Access mode unsupported
    
- Node scheduling problem
    
- Resource request too high
    

Events normally explain the scheduling or storage problem.

---

# 56. Common failure: PostgreSQL crash loop

Check:

```bash
kubectl logs database-0
```

Previous container:

```bash
kubectl logs \
  --previous \
  database-0
```

Describe:

```bash
kubectl describe pod database-0
```

Possible causes:

- Wrong volume ownership
    
- Invalid data directory
    
- Incompatible existing data
    
- Missing password
    
- Storage read-only
    
- Memory limit too low
    
- Corrupt database
    
- Major-version mismatch
    

Do not delete the PVC as a generic troubleshooting step.

That may delete or disconnect important data.

---

# 57. Common failure: Service has no endpoints

Check:

```bash
kubectl get endpoints device-api
```

If empty:

```bash
kubectl get pods \
  --show-labels
```

Compare:

```bash
kubectl get service device-api \
  -o yaml
```

Possible causes:

- Selector mismatch
    
- API Pods unready
    
- Pods in another namespace
    
- Deployment failed
    
- Readiness probe failing
    

A Service selects ready Pods through labels and selectors.

---

# 58. Common failure: API is in `CrashLoopBackOff`

Read current logs:

```bash
kubectl logs API_POD_NAME
```

Read previous logs:

```bash
kubectl logs \
  --previous \
  API_POD_NAME
```

Describe:

```bash
kubectl describe pod API_POD_NAME
```

Possible causes:

- Application exception
    
- Missing Python dependency
    
- Secret file not readable
    
- Wrong command
    
- Read-only filesystem conflict
    
- Database authentication error
    
- Incorrect image architecture
    
- Memory limit exceeded
    

Check termination details:

```bash
kubectl get pod API_POD_NAME \
  -o jsonpath='{.status.containerStatuses[0].lastState.terminated}'

echo
```

---

# 59. Common failure: `CreateContainerConfigError`

Inspect:

```bash
kubectl describe pod API_POD_NAME
```

Likely causes:

- Missing ConfigMap
    
- Missing Secret
    
- Wrong Secret key
    
- Invalid volume reference
    
- Invalid environment reference
    

Check:

```bash
kubectl get configmaps
kubectl get secrets
```

Check exact keys:

```bash
kubectl describe configmap device-monitor-config
```

For Secrets, avoid printing sensitive values unnecessarily.

---

# 60. Common failure: image cannot be pulled

Symptoms:

```text
ErrImagePull
ImagePullBackOff
```

Describe:

```bash
kubectl describe pod API_POD_NAME
```

Possible causes:

- Wrong repository path
    
- Tag missing
    
- Private registry credential missing
    
- Expired token
    
- DNS failure
    
- TLS trust failure
    
- No matching platform
    
- Registry unavailable
    

For private images, ensure:

```yaml
imagePullSecrets:
  - name: registry-credentials
```

exists in the same namespace.

---

# 61. Debug with an ephemeral diagnostic Pod

Create:

```bash
kubectl run debug-shell \
  --image=nicolaka/netshoot \
  --restart=Never \
  -- sleep 3600
```

Open:

```bash
kubectl exec -it debug-shell -- \
  bash
```

Test:

```bash
dig database
nc -vz database 5432
curl -v http://device-api/health
```

Remove:

```bash
kubectl delete pod debug-shell
```

Use only trusted diagnostic images.

Do not grant them unnecessary Secrets, host mounts, or privileges.

---

# 62. Verify the complete system

Run:

```bash
kubectl get all
```

Also:

```bash
kubectl get configmaps
kubectl get secrets
kubectl get pvc
kubectl get jobs
```

Expected conceptual state:

```text
Deployment/device-api
→ 3/3 ready

StatefulSet/database
→ 1/1 ready

Service/device-api
→ ClusterIP

Service/database
→ ClusterIP

Job/device-api-migration
→ Complete

PVC/postgres-data-database-0
→ Bound
```

Test:

```bash
kubectl port-forward \
  service/device-api \
  8080:80
```

Then:

```bash
curl --fail \
  http://127.0.0.1:8080/health
```

---

# 63. Inspect resource usage

Enable Metrics Server in Minikube:

```bash
minikube addons enable metrics-server
```

Wait, then:

```bash
kubectl top pods
```

```bash
kubectl top nodes
```

Compare actual usage with:

```yaml
resources:
  requests:
  limits:
```

Use observation to improve resource values.

Do not set requests or limits based only on guesses.

---

# 64. Cleanup without deleting storage

Delete API and migration:

```bash
kubectl delete \
  -f 07-api-service.yaml

kubectl delete \
  -f 06-api-deployment.yaml

kubectl delete \
  -f 05-migration-job.yaml
```

Delete the StatefulSet and database Service:

```bash
kubectl delete \
  -f 04-database-statefulset.yaml

kubectl delete \
  -f 03-database-service.yaml
```

Check:

```bash
kubectl get pvc
```

A StatefulSet’s PersistentVolumeClaims are commonly retained rather than automatically deleted with the Pods, which protects data from accidental workload deletion.

Do not assume cleanup removes the storage.

---

# 65. Delete persistent data deliberately

To delete the lab data:

```bash
kubectl delete pvc \
  postgres-data-database-0
```

This is destructive.

Before doing it, verify:

```bash
kubectl get pvc
kubectl get pv
```

After deleting the claim, the behavior of the bound PersistentVolume depends on its reclaim policy.

For local Minikube storage, deleting the cluster also removes local lab storage.

---

# 66. Delete the full namespace

For complete lab removal:

```bash
kubectl delete namespace device-monitor
```

This removes namespace-scoped objects, including:

- Deployments
    
- StatefulSets
    
- Pods
    
- Services
    
- ConfigMaps
    
- Secrets
    
- Jobs
    
- PersistentVolumeClaims
    

PersistentVolumes may be deleted or retained according to their storage reclaim policy.

Switch back:

```bash
kubectl config set-context \
  --current \
  --namespace=default
```

---

# 67. Day 23 practical laboratory

## Exercise 1 — Namespace and configuration

Create:

- Dedicated namespace
    
- ConfigMap
    
- Database Secret
    

Confirm each exists.

## Exercise 2 — PostgreSQL Service

Create the `database` Service before PostgreSQL.

Observe that it initially has no endpoints.

## Exercise 3 — StatefulSet

Deploy PostgreSQL using:

- One replica
    
- StatefulSet
    
- PVC template
    
- Startup probe
    
- Readiness probe
    
- Liveness probe
    
- Resource controls
    

## Exercise 4 — Persistent data

Create a table and record.

Delete `database-0`.

Confirm the record remains after recreation.

## Exercise 5 — Init container

Deploy the API with an init container that waits for PostgreSQL.

Scale PostgreSQL to zero and create a new API Pod.

Observe the API Pod remaining in init state.

Restore PostgreSQL.

## Exercise 6 — Migration Job

Run a Job that creates the database schema.

Inspect:

- Job
    
- Job Pod
    
- Logs
    
- Completion status
    

## Exercise 7 — API Deployment

Deploy three API replicas.

Use:

- ConfigMap
    
- Secret file
    
- Probes
    
- Security context
    
- Resource requests and limits
    

## Exercise 8 — Services

Create the API ClusterIP Service.

Verify ready endpoints.

Access it with port forwarding.

## Exercise 9 — Failure testing

Test:

- Delete one API Pod
    
- Delete the database Pod
    
- Scale database to zero
    
- Break a Service selector
    
- Use an invalid API image tag
    

Diagnose each failure.

## Exercise 10 — Rolling update

Update the API image.

Observe:

- New ReplicaSet
    
- Old ReplicaSet
    
- Pod transition
    
- Service endpoint changes
    

Then roll back.

---

# 68. Day 23 command reference

```bash
# Apply namespace and configuration
kubectl apply -f 00-namespace.yaml
kubectl apply -f 01-configmap.yaml
kubectl apply -f 02-secret.yaml

# Deploy database
kubectl apply -f 03-database-service.yaml
kubectl apply -f 04-database-statefulset.yaml

# Wait for database
kubectl rollout status \
  statefulset/database

# Run migration
kubectl apply -f 05-migration-job.yaml

kubectl wait \
  --for=condition=complete \
  job/device-api-migration \
  --timeout=180s

# Deploy API
kubectl apply -f 06-api-deployment.yaml
kubectl apply -f 07-api-service.yaml

# Wait for API rollout
kubectl rollout status \
  deployment/device-api

# List resources
kubectl get all
kubectl get pvc
kubectl get jobs

# Check Service backends
kubectl get endpoints database
kubectl get endpoints device-api

# Read logs
kubectl logs database-0

kubectl logs \
  API_POD \
  --container wait-for-database

kubectl logs \
  API_POD \
  --container device-api

# Access API
kubectl port-forward \
  service/device-api \
  8080:80

# Query database
kubectl exec database-0 -- \
  psql \
    -U device_app \
    -d device_monitor \
    -c 'SELECT * FROM devices;'

# Scale database
kubectl scale statefulset database \
  --replicas=0

kubectl scale statefulset database \
  --replicas=1

# Restart API Pods
kubectl rollout restart \
  deployment/device-api

# Inspect rollout
kubectl rollout history \
  deployment/device-api

# Roll back
kubectl rollout undo \
  deployment/device-api

# Apply with Kustomize
kubectl apply -k .

# Delete with Kustomize
kubectl delete -k .
```

---

# 69. Knowledge check

## Why should API and PostgreSQL run in separate Pods?

They have different lifecycles, scaling behavior, storage requirements, and failure modes.

## Why use a Deployment for the API?

The API is stateless and its replicas should be interchangeable and replaceable.

## Why use a StatefulSet for PostgreSQL?

PostgreSQL needs stable identity and persistent storage associated with its workload.

## Does one PostgreSQL StatefulSet replica provide high availability?

No. It provides stateful workload management, not replicated database failover.

## What provides the database hostname?

The Kubernetes Service named `database`.

## Why should the API use `database` instead of a Pod IP?

The Service name is stable; Pod IPs can change.

## What is a ConfigMap for?

Non-sensitive application configuration.

## What is a Secret for?

Sensitive data such as passwords, tokens, and keys.

## Why mount the password as a file?

It avoids directly embedding the raw password in the container image or Pod manifest environment configuration.

## What is an init container?

A container that completes before the normal application containers begin.

## Does an init container handle future dependency outages?

No. The application still needs runtime retries and resilience.

## What is a Job?

A workload object for a task that runs to completion.

## Why use a Job for migrations?

It separates one-time schema work from every application replica’s startup.

## What does a PersistentVolumeClaim do?

It requests persistent storage for a workload.

## What happens when `database-0` is deleted?

The StatefulSet recreates it, and it can remount the same PersistentVolumeClaim.

## Does a PVC replace backups?

No. Persistent storage and backup are different concerns.

## What happens when a ConfigMap-backed environment variable changes?

Existing containers keep their old environment. New Pods are required to receive the updated value.

## What removes an API Pod from Service traffic?

A failing readiness probe.

## What can cause a container restart?

A process exit, failed liveness probe, or other runtime failure.

---

# 70. Day 23 completion challenge

Complete this independently:

1. Create a `device-monitor` namespace.
    
2. Set it as the current namespace.
    
3. Create a ConfigMap containing API and database configuration.
    
4. Create a database Secret.
    
5. Ensure the password is not stored in the container image.
    
6. Create a PostgreSQL ClusterIP Service.
    
7. Confirm it initially has no endpoints.
    
8. Create a PostgreSQL StatefulSet.
    
9. Use one database replica.
    
10. Add matching labels and selectors.
    
11. Add a named PostgreSQL port.
    
12. Add startup, readiness, and liveness probes.
    
13. Add resource requests and limits.
    
14. Add a StatefulSet PVC template.
    
15. Request 2 GiB of storage.
    
16. Wait for the PVC to become bound.
    
17. Wait for `database-0` to become ready.
    
18. Resolve `database` from a temporary Pod.
    
19. Connect to PostgreSQL through the Service.
    
20. Create a database schema.
    
21. Create a migration Job.
    
22. Make the Job wait for PostgreSQL.
    
23. Make the Job run to completion.
    
24. Inspect Job logs.
    
25. Deploy three API replicas.
    
26. Add an init container to wait for PostgreSQL.
    
27. Load non-sensitive settings from the ConfigMap.
    
28. Mount the database password as a file.
    
29. Run the API as non-root.
    
30. Drop all capabilities.
    
31. Disable privilege escalation.
    
32. Use a read-only root filesystem.
    
33. Mount an `emptyDir` at `/tmp`.
    
34. Add startup, readiness, and liveness probes.
    
35. Add API resource requests and limits.
    
36. Create an API ClusterIP Service.
    
37. Confirm all ready API endpoints appear.
    
38. Port-forward the API.
    
39. Create a device record.
    
40. Delete one API Pod.
    
41. Confirm the Deployment replaces it.
    
42. Delete the PostgreSQL Pod.
    
43. Confirm the StatefulSet recreates `database-0`.
    
44. Confirm the database record remains.
    
45. Scale PostgreSQL to zero.
    
46. Observe API readiness behavior.
    
47. Restore PostgreSQL.
    
48. Confirm API recovery.
    
49. Change the API ConfigMap.
    
50. Restart the Deployment to apply environment changes.
    
51. Perform an API image rolling update.
    
52. Observe both ReplicaSets.
    
53. Roll back the API.
    
54. Create a PostgreSQL logical backup.
    
55. Verify the backup.
    
56. Explain why a PVC is not a backup.
    
57. Break the database Service selector.
    
58. Diagnose the missing endpoints.
    
59. Restore the selector.
    
60. Document the full deployment and recovery procedure.
    

The central Day 23 model is:

```text
Configuration
├── ConfigMap
└── Secret
        │
        ▼
PostgreSQL StatefulSet
        │
        ├── Stable Pod identity
        └── PersistentVolumeClaim
        │
        ▼
Database Service
        │
        ▼
Migration Job
        │
        ▼
API Deployment
        │
        ├── Init container
        ├── Probes
        ├── Security context
        └── Three replicas
        │
        ▼
API Service
        │
        ▼
Stable application access
```

The most important operational lesson is:

> Separate every lifecycle concern. Use a Deployment for replaceable API replicas, a StatefulSet and persistent claim for database state, Services for stable networking, ConfigMaps for ordinary configuration, Secrets for credentials, init containers for startup preparation, Jobs for one-time migrations, and probes for ongoing application health.