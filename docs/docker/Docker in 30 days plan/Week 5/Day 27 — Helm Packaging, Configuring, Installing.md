#### Helm: Packaging, Configuring, Installing, Upgrading, and Rolling Back Kubernetes Applications

Until now, you deployed Kubernetes applications using separate YAML files:

```text
00-namespace.yaml
01-configmap.yaml
02-secret.yaml
03-database-service.yaml
04-database-statefulset.yaml
05-migration-job.yaml
06-api-deployment.yaml
07-api-service.yaml
08-api-ingress.yaml
...
```

This approach is transparent and useful, but it becomes repetitive when you need several environments:

```text
Development
Testing
Staging
Production
Customer A
Customer B
```

You might need to change:

- Image repository and version
    
- Replica count
    
- Hostname
    
- Storage size
    
- CPU and memory limits
    
- Ingress settings
    
- TLS configuration
    
- Database deployment
    
- Monitoring configuration
    
- Security settings
    

Copying all YAML files for every environment creates duplication and configuration drift.

Today you will package your Kubernetes application as a **Helm chart**.

Helm is a package manager for Kubernetes. A Helm chart contains Kubernetes resource templates, default configuration values, metadata, dependencies, and optional lifecycle hooks. Installing a chart creates a named **release**, and upgrades and rollbacks create new revisions of that release. ([Helm](https://helm.sh/docs/?utm_source=chatgpt.com "Docs Home"))

The central lesson is:

> Helm does not replace Kubernetes. Helm generates Kubernetes manifests from templates and values, submits them to Kubernetes, and tracks the resulting application release history.

---

## 1. Day 27 objectives

By the end of today, you should understand:

- What Helm is
    
- Chart, release, revision, and repository
    
- Helm chart directory structure
    
- `Chart.yaml`
    
- `values.yaml`
    
- The `templates/` directory
    
- Helm template expressions
    
- Built-in objects
    
- Functions and pipelines
    
- Template helpers
    
- Conditions
    
- Loops
    
- Values precedence
    
- Environment-specific values files
    
- Installing a chart
    
- Inspecting a release
    
- Upgrading a release
    
- Rolling back a release
    
- Uninstalling a release
    
- Testing rendered YAML
    
- Linting charts
    
- Packaging charts
    
- OCI chart registries
    
- Chart dependencies
    
- Helm hooks
    
- Secret-management risks
    
- Helm versus Kustomize
    
- CI/CD integration
    
- Building a Helm chart for your device-monitor platform
    

---

# 2. Why Helm exists

Without Helm, an environment-specific deployment might look like this:

```text
kubernetes/
├── development/
│   ├── deployment.yaml
│   ├── service.yaml
│   ├── ingress.yaml
│   └── configmap.yaml
├── staging/
│   ├── deployment.yaml
│   ├── service.yaml
│   ├── ingress.yaml
│   └── configmap.yaml
└── production/
    ├── deployment.yaml
    ├── service.yaml
    ├── ingress.yaml
    └── configmap.yaml
```

Most files contain nearly identical content.

Only a few values differ:

```text
Development:
replicas = 1
hostname = device-api.dev.local
image tag = development

Production:
replicas = 3
hostname = api.example.com
image digest = sha256:...
```

Helm separates:

```text
Kubernetes structure
from
Environment-specific values
```

Conceptually:

```text
Templates
+
Values
=
Rendered Kubernetes manifests
```

---

# 3. Helm vocabulary

## Chart

A package containing templates, metadata, default values, and optional dependencies.

Example:

```text
device-monitor
```

## Release

One installed instance of a chart.

Example:

```text
Release name: production
Chart: device-monitor
Namespace: device-monitor
```

The same chart can be installed several times:

```text
device-monitor-dev
device-monitor-staging
device-monitor-production
```

## Revision

Every successful installation, upgrade, or rollback creates a new release revision.

Example:

```text
Revision 1 → initial installation
Revision 2 → image updated
Revision 3 → replica count changed
Revision 4 → rollback to revision 2 configuration
```

A rollback itself becomes a new revision; it does not erase later history. ([Helm](https://helm.sh/docs/helm/helm_rollback?utm_source=chatgpt.com "helm rollback"))

## Repository

A location from which charts can be discovered and downloaded.

Modern Helm can also store and retrieve charts through OCI-compatible registries.

---

# 4. Helm versus `kubectl apply`

With plain manifests:

```bash
kubectl apply -f kubernetes/
```

You manage the rendered Kubernetes resources directly.

With Helm:

```bash
helm upgrade \
  --install \
  device-monitor \
  ./device-monitor
```

Helm:

1. Loads the chart.
    
2. Loads default and supplied values.
    
3. Renders the templates.
    
4. Validates and submits the resources.
    
5. Stores release metadata.
    
6. Maintains revision history.
    

The generated objects are still ordinary Kubernetes objects.

You can inspect them with:

```bash
kubectl get deployments
kubectl describe pod POD
kubectl logs POD
```

---

# 5. Install Helm

Use the current official Helm installation instructions for your operating system because available releases and package-manager versions can change.

Verify:

```bash
helm version
```

Inspect Helm’s environment:

```bash
helm env
```

View available commands:

```bash
helm help
```

Helm’s command set includes chart creation, linting, rendering, installation, upgrades, rollback, dependencies, repositories, packaging, testing, and OCI registry operations. ([Helm](https://helm.sh/docs/helm/?utm_source=chatgpt.com "Helm Commands"))

---

# 6. Verify cluster access

Helm uses your Kubernetes configuration and current context.

Check:

```bash
kubectl config current-context
```

Check the namespace:

```bash
kubectl config view \
  --minify \
  -o jsonpath='{..namespace}'

echo
```

Check cluster access:

```bash
kubectl get nodes
```

Helm will act using the Kubernetes identity and permissions associated with your active kubeconfig context.

Do not run Helm against production without first confirming:

```text
Current cluster
Current namespace
Release name
Values files
Image version
```

---

# 7. Create your first chart

Prepare the project:

```bash
mkdir -p ~/docker-course/day27
cd ~/docker-course/day27
```

Create a chart:

```bash
helm create device-monitor
```

Inspect:

```bash
find device-monitor \
  -maxdepth 3 \
  -type f \
  | sort
```

You will see a generated structure resembling:

```text
device-monitor/
├── Chart.yaml
├── values.yaml
├── charts/
├── templates/
│   ├── _helpers.tpl
│   ├── deployment.yaml
│   ├── service.yaml
│   ├── serviceaccount.yaml
│   ├── ingress.yaml
│   ├── hpa.yaml
│   ├── httproute.yaml
│   ├── NOTES.txt
│   └── tests/
└── .helmignore
```

The exact generated files can vary by Helm version.

---

# 8. Minimal chart structure

A simple chart needs:

```text
device-monitor/
├── Chart.yaml
├── values.yaml
└── templates/
```

## `Chart.yaml`

Describes the chart.

## `values.yaml`

Contains default configuration values.

## `templates/`

Contains Kubernetes YAML templates.

## `charts/`

Contains downloaded chart dependencies.

## `_helpers.tpl`

Contains reusable named templates.

## `.helmignore`

Excludes files when packaging the chart.

---

# 9. Understand `Chart.yaml`

Open:

```bash
cat device-monitor/Chart.yaml
```

A simplified version:

```yaml
apiVersion: v2

name: device-monitor

description: Kubernetes chart for the device-monitor platform

type: application

version: 0.1.0

appVersion: "1.0.0"
```

## `apiVersion`

For modern Helm charts:

```yaml
apiVersion: v2
```

## `name`

The chart package name:

```yaml
name: device-monitor
```

Chart names should use lowercase letters and numbers, with hyphens where needed; uppercase letters and underscores should be avoided. ([Helm](https://helm.sh/docs/chart_best_practices/conventions?utm_source=chatgpt.com "General Conventions"))

## `description`

Human-readable chart purpose.

## `type`

Usually:

```yaml
type: application
```

A library chart contains reusable template logic but does not normally install application resources independently.

## `version`

The chart package version:

```yaml
version: 0.1.0
```

This changes when chart templates or packaging change.

## `appVersion`

The application version represented by the chart:

```yaml
appVersion: "1.0.0"
```

This is informational and does not automatically set the container image unless your templates use it.

---

# 10. Chart version versus application version

These are different.

```text
Chart version:
0.4.0

Application version:
2.1.3
```

The chart may change without changing the application image.

Example:

```text
Chart 0.4.0:
adds NetworkPolicy

Application:
still 2.1.3
```

The application may change without significantly changing chart structure:

```text
Chart:
0.4.0

Application:
2.1.3 → 2.1.4
```

A practical release record should include both:

```text
Chart: device-monitor-0.4.0
Application image: device-api:2.1.4
Image digest: sha256:...
```

---

# 11. Clean the generated chart

The generated chart is a starting point, not your final design.

Remove templates you will not use yet:

```bash
rm -rf device-monitor/templates/*
```

Recreate the test directory later if needed:

```bash
mkdir -p device-monitor/templates/tests
```

Your chart will initially contain:

```text
device-monitor/
├── Chart.yaml
├── values.yaml
├── templates/
└── .helmignore
```

---

# 12. Design `values.yaml`

Create `device-monitor/values.yaml`:

```yaml
nameOverride: ""
fullnameOverride: ""

api:
  replicaCount: 3

  image:
    repository: registry.example.com/team/device-api
    tag: "1.0.0"
    digest: ""
    pullPolicy: IfNotPresent

  service:
    type: ClusterIP
    port: 80
    targetPort: 5000

  ingress:
    enabled: true
    className: nginx
    host: device-api.local
    path: /
    pathType: Prefix
    tls:
      enabled: false
      secretName: ""

  resources:
    requests:
      cpu: 100m
      memory: 128Mi
      ephemeralStorage: 64Mi

    limits:
      cpu: "1"
      memory: 512Mi
      ephemeralStorage: 256Mi

  securityContext:
    runAsUser: 10001
    runAsGroup: 10001

  probes:
    startup:
      path: /health
      periodSeconds: 5
      failureThreshold: 30

    readiness:
      path: /health
      periodSeconds: 5
      failureThreshold: 3

    liveness:
      path: /health
      periodSeconds: 15
      failureThreshold: 5

database:
  enabled: true

  image:
    repository: postgres
    tag: "17"
    pullPolicy: IfNotPresent

  storage:
    size: 2Gi
    storageClassName: ""

  resources:
    requests:
      cpu: 100m
      memory: 256Mi

    limits:
      cpu: "1"
      memory: 1Gi

config:
  appEnvironment: development
  logLevel: INFO
  databaseName: device_monitor
  databaseUser: device_app

existingDatabaseSecret: database-credentials

serviceAccount:
  create: true
  name: ""
  automountToken: false

networkPolicy:
  enabled: true

podSecurityContext:
  runAsNonRoot: true
  seccompProfile:
    type: RuntimeDefault

imagePullSecrets: []
```

Helm recommends designing values so they are easy to override and understand from the command line and values files. ([Helm](https://helm.sh/docs/chart_best_practices/values/?utm_source=chatgpt.com "Values"))

---

# 13. Avoid deeply nested values without reason

This is difficult to override:

```yaml
platform:
  applications:
    backend:
      services:
        api:
          container:
            image:
              version: "1.0.0"
```

Command:

```bash
helm upgrade \
  --set platform.applications.backend.services.api.container.image.version=1.0.1
```

Prefer a clear structure:

```yaml
api:
  image:
    tag: "1.0.1"
```

Command:

```bash
helm upgrade \
  --set api.image.tag=1.0.1
```

Some nesting is useful, but values should remain understandable.

---

# 14. Helm template syntax

Helm uses Go template syntax.

Expressions are surrounded by:

```text
{{ ... }}
```

Example:

```yaml
replicas: {{ .Values.api.replicaCount }}
```

The leading dot represents the current template context.

Common root objects include:

```text
.Values
.Release
.Chart
.Capabilities
.Template
.Files
```

Helm provides built-in objects such as `.Release`, `.Values`, `.Chart`, and `.Capabilities` to chart templates. ([Helm](https://helm.sh/docs/chart_template_guide/builtin_objects/?utm_source=chatgpt.com "Built-in Objects"))

---

# 15. Important built-in objects

## `.Values`

Values from:

- `values.yaml`
    
- Environment values files
    
- `--set`
    
- Other command-line overrides
    

Example:

```yaml
image: {{ .Values.api.image.repository }}
```

## `.Release`

Information about the current installed release.

Examples:

```text
.Release.Name
.Release.Namespace
.Release.Service
.Release.IsInstall
.Release.IsUpgrade
.Revision
```

## `.Chart`

Information from `Chart.yaml`.

Examples:

```text
.Chart.Name
.Chart.Version
.Chart.AppVersion
```

## `.Capabilities`

Information about the target Kubernetes cluster.

Useful for API-version compatibility checks.

---

# 16. Create template helpers

Create `device-monitor/templates/_helpers.tpl`:

```gotemplate
{{- define "device-monitor.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "device-monitor.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name (include "device-monitor.name" .) | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}

{{- define "device-monitor.labels" -}}
helm.sh/chart: {{ printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" }}
app.kubernetes.io/name: {{ include "device-monitor.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
app.kubernetes.io/part-of: device-monitor
{{- end }}

{{- define "device-monitor.selectorLabels" -}}
app.kubernetes.io/name: {{ include "device-monitor.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{- define "device-monitor.serviceAccountName" -}}
{{- if .Values.serviceAccount.create }}
{{- default (printf "%s-api" (include "device-monitor.fullname" .)) .Values.serviceAccount.name }}
{{- else }}
{{- required "serviceAccount.name must be set when serviceAccount.create is false" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{- define "device-monitor.apiImage" -}}
{{- if .Values.api.image.digest }}
{{- printf "%s@%s" .Values.api.image.repository .Values.api.image.digest }}
{{- else }}
{{- printf "%s:%s" .Values.api.image.repository .Values.api.image.tag }}
{{- end }}
{{- end }}
```

Reusable named templates prevent repeated naming and labeling logic.

Helm’s `include` function is commonly used to call reusable named templates while allowing their result to participate in pipelines. ([Helm](https://helm.sh/docs/howto/charts_tips_and_tricks/?utm_source=chatgpt.com "Chart Development Tips and Tricks"))

---

# 17. Whitespace control

Notice:

```gotemplate
{{- ... -}}
```

The hyphens trim surrounding whitespace.

Without careful trimming, templates can generate:

- Unexpected blank lines
    
- Broken indentation
    
- Invalid YAML
    

Compare:

```gotemplate
{{ .Values.api.replicaCount }}
```

with:

```gotemplate
{{- .Values.api.replicaCount }}
```

Use trimming carefully. Removing too much whitespace can also join lines incorrectly.

Always inspect rendered YAML rather than assuming the template is correct.

---

# 18. Create the ConfigMap template

Create `templates/configmap.yaml`:

```yaml
apiVersion: v1
kind: ConfigMap

metadata:
  name: {{ include "device-monitor.fullname" . }}-config

  labels:
    {{- include "device-monitor.labels" . | nindent 4 }}

data:
  APP_ENV: {{ .Values.config.appEnvironment | quote }}
  APP_VERSION: {{ .Chart.AppVersion | quote }}
  LOG_LEVEL: {{ .Values.config.logLevel | quote }}

  DB_HOST: {{ include "device-monitor.fullname" . }}-database
  DB_PORT: "5432"
  DB_NAME: {{ .Values.config.databaseName | quote }}
  DB_USER: {{ .Values.config.databaseUser | quote }}
```

Important functions:

## `quote`

```gotemplate
{{ .Values.config.logLevel | quote }}
```

Produces:

```yaml
LOG_LEVEL: "INFO"
```

## `nindent`

```gotemplate
{{ include "device-monitor.labels" . | nindent 4 }}
```

Adds a newline and indents every line by four spaces.

This is essential when injecting multi-line YAML fragments.

---

# 19. Template pipelines

Helm supports pipelines:

```gotemplate
VALUE | function
```

Example:

```gotemplate
{{ .Values.config.logLevel | upper | quote }}
```

If value is:

```text
info
```

rendered value becomes:

```yaml
LOG_LEVEL: "INFO"
```

Another example:

```gotemplate
{{ .Release.Name | trunc 63 | trimSuffix "-" }}
```

Read pipelines left to right:

```text
Take release name
→ truncate to 63 characters
→ remove final hyphen
```

---

# 20. Create the ServiceAccount template

Create `templates/serviceaccount.yaml`:

```yaml
{{- if .Values.serviceAccount.create }}
apiVersion: v1
kind: ServiceAccount

metadata:
  name: {{ include "device-monitor.serviceAccountName" . }}

  labels:
    {{- include "device-monitor.labels" . | nindent 4 }}

automountServiceAccountToken: {{ .Values.serviceAccount.automountToken }}
{{- end }}
```

The entire resource is conditional:

```gotemplate
{{- if .Values.serviceAccount.create }}
...
{{- end }}
```

When:

```yaml
serviceAccount:
  create: false
```

the template produces no ServiceAccount.

---

# 21. Create the API Deployment template

Create `templates/api-deployment.yaml`:

```yaml
apiVersion: apps/v1
kind: Deployment

metadata:
  name: {{ include "device-monitor.fullname" . }}-api

  labels:
    {{- include "device-monitor.labels" . | nindent 4 }}
    app.kubernetes.io/component: api

spec:
  replicas: {{ .Values.api.replicaCount }}

  revisionHistoryLimit: 5

  strategy:
    type: RollingUpdate

    rollingUpdate:
      maxUnavailable: 1
      maxSurge: 1

  selector:
    matchLabels:
      {{- include "device-monitor.selectorLabels" . | nindent 6 }}
      app.kubernetes.io/component: api

  template:
    metadata:
      labels:
        {{- include "device-monitor.selectorLabels" . | nindent 8 }}
        app.kubernetes.io/component: api

    spec:
      serviceAccountName: {{ include "device-monitor.serviceAccountName" . }}
      automountServiceAccountToken: {{ .Values.serviceAccount.automountToken }}
      enableServiceLinks: false

      securityContext:
        runAsNonRoot: {{ .Values.podSecurityContext.runAsNonRoot }}

        seccompProfile:
          type: {{ .Values.podSecurityContext.seccompProfile.type }}

      {{- with .Values.imagePullSecrets }}
      imagePullSecrets:
        {{- toYaml . | nindent 8 }}
      {{- end }}

      containers:
        - name: device-api

          image: {{ include "device-monitor.apiImage" . | quote }}

          imagePullPolicy: {{ .Values.api.image.pullPolicy }}

          ports:
            - name: http
              containerPort: {{ .Values.api.service.targetPort }}
              protocol: TCP

          envFrom:
            - configMapRef:
                name: {{ include "device-monitor.fullname" . }}-config

          env:
            - name: DB_PASSWORD_FILE
              value: /run/secrets/database/password

          securityContext:
            runAsNonRoot: true
            runAsUser: {{ .Values.api.securityContext.runAsUser }}
            runAsGroup: {{ .Values.api.securityContext.runAsGroup }}

            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true

            capabilities:
              drop:
                - ALL

          volumeMounts:
            - name: database-password
              mountPath: /run/secrets/database
              readOnly: true

            - name: temporary-storage
              mountPath: /tmp

          startupProbe:
            httpGet:
              path: {{ .Values.api.probes.startup.path }}
              port: http

            periodSeconds: {{ .Values.api.probes.startup.periodSeconds }}
            failureThreshold: {{ .Values.api.probes.startup.failureThreshold }}

          readinessProbe:
            httpGet:
              path: {{ .Values.api.probes.readiness.path }}
              port: http

            periodSeconds: {{ .Values.api.probes.readiness.periodSeconds }}
            failureThreshold: {{ .Values.api.probes.readiness.failureThreshold }}

          livenessProbe:
            httpGet:
              path: {{ .Values.api.probes.liveness.path }}
              port: http

            periodSeconds: {{ .Values.api.probes.liveness.periodSeconds }}
            failureThreshold: {{ .Values.api.probes.liveness.failureThreshold }}

          resources:
            requests:
              cpu: {{ .Values.api.resources.requests.cpu | quote }}
              memory: {{ .Values.api.resources.requests.memory | quote }}
              ephemeral-storage: {{ .Values.api.resources.requests.ephemeralStorage | quote }}

            limits:
              cpu: {{ .Values.api.resources.limits.cpu | quote }}
              memory: {{ .Values.api.resources.limits.memory | quote }}
              ephemeral-storage: {{ .Values.api.resources.limits.ephemeralStorage | quote }}

      volumes:
        - name: database-password

          secret:
            secretName: {{ .Values.existingDatabaseSecret }}

            items:
              - key: password
                path: password

        - name: temporary-storage

          emptyDir:
            sizeLimit: {{ .Values.api.resources.limits.ephemeralStorage }}
```

---

# 22. Understand `with`

This block:

```gotemplate
{{- with .Values.imagePullSecrets }}
imagePullSecrets:
  {{- toYaml . | nindent 8 }}
{{- end }}
```

means:

```text
When imagePullSecrets is non-empty:
- change the current context to imagePullSecrets
- render it as YAML
- indent it
```

With values:

```yaml
imagePullSecrets:
  - name: registry-credentials
```

the rendered result is:

```yaml
imagePullSecrets:
  - name: registry-credentials
```

When the value is empty:

```yaml
imagePullSecrets: []
```

the whole field is omitted.

---

# 23. Understand `toYaml`

Given:

```yaml
resources:
  requests:
    cpu: 100m
    memory: 128Mi
```

A template could use:

```gotemplate
resources:
  {{- toYaml .Values.api.resources | nindent 10 }}
```

This is shorter than rendering every field manually.

Example:

```yaml
resources:
  {{- toYaml .Values.api.resources | nindent 10 }}
```

Use this when the value structure already matches the Kubernetes schema.

Explicit fields are sometimes easier for beginners and allow stricter validation.

---

# 24. Create the API Service template

Create `templates/api-service.yaml`:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: {{ include "device-monitor.fullname" . }}-api

  labels:
    {{- include "device-monitor.labels" . | nindent 4 }}
    app.kubernetes.io/component: api

spec:
  type: {{ .Values.api.service.type }}

  selector:
    {{- include "device-monitor.selectorLabels" . | nindent 4 }}
    app.kubernetes.io/component: api

  ports:
    - name: http
      port: {{ .Values.api.service.port }}
      targetPort: http
      protocol: TCP
```

The Service uses the same selector labels as the Deployment Pod template.

Avoid generating selector labels from values that users may casually change after installation.

Changing immutable selectors can make upgrades fail.

---

# 25. Create the Ingress template

Create `templates/api-ingress.yaml`:

```yaml
{{- if .Values.api.ingress.enabled }}
apiVersion: networking.k8s.io/v1
kind: Ingress

metadata:
  name: {{ include "device-monitor.fullname" . }}-api

  labels:
    {{- include "device-monitor.labels" . | nindent 4 }}
    app.kubernetes.io/component: ingress

spec:
  ingressClassName: {{ .Values.api.ingress.className }}

  {{- if .Values.api.ingress.tls.enabled }}
  tls:
    - hosts:
        - {{ .Values.api.ingress.host | quote }}

      secretName: {{ required "api.ingress.tls.secretName is required when TLS is enabled" .Values.api.ingress.tls.secretName }}
  {{- end }}

  rules:
    - host: {{ required "api.ingress.host must be defined" .Values.api.ingress.host | quote }}

      http:
        paths:
          - path: {{ .Values.api.ingress.path }}
            pathType: {{ .Values.api.ingress.pathType }}

            backend:
              service:
                name: {{ include "device-monitor.fullname" . }}-api

                port:
                  name: http
{{- end }}
```

The `required` function stops rendering when an essential value is missing. Helm’s chart-development guidance recommends it for values that must be supplied. ([Helm](https://helm.sh/docs/howto/charts_tips_and_tricks/?utm_source=chatgpt.com "Chart Development Tips and Tricks"))

---

# 26. Create the database Service template

Create `templates/database-service.yaml`:

```yaml
{{- if .Values.database.enabled }}
apiVersion: v1
kind: Service

metadata:
  name: {{ include "device-monitor.fullname" . }}-database

  labels:
    {{- include "device-monitor.labels" . | nindent 4 }}
    app.kubernetes.io/component: database

spec:
  type: ClusterIP

  selector:
    {{- include "device-monitor.selectorLabels" . | nindent 4 }}
    app.kubernetes.io/component: database

  ports:
    - name: postgres
      port: 5432
      targetPort: postgres
      protocol: TCP
{{- end }}
```

---

# 27. Create the PostgreSQL StatefulSet template

Create `templates/database-statefulset.yaml`:

```yaml
{{- if .Values.database.enabled }}
apiVersion: apps/v1
kind: StatefulSet

metadata:
  name: {{ include "device-monitor.fullname" . }}-database

  labels:
    {{- include "device-monitor.labels" . | nindent 4 }}
    app.kubernetes.io/component: database

spec:
  serviceName: {{ include "device-monitor.fullname" . }}-database

  replicas: 1

  selector:
    matchLabels:
      {{- include "device-monitor.selectorLabels" . | nindent 6 }}
      app.kubernetes.io/component: database

  template:
    metadata:
      labels:
        {{- include "device-monitor.selectorLabels" . | nindent 8 }}
        app.kubernetes.io/component: database

    spec:
      automountServiceAccountToken: false

      securityContext:
        fsGroup: 999
        fsGroupChangePolicy: OnRootMismatch

        seccompProfile:
          type: RuntimeDefault

      containers:
        - name: postgres

          image: "{{ .Values.database.image.repository }}:{{ .Values.database.image.tag }}"

          imagePullPolicy: {{ .Values.database.image.pullPolicy }}

          ports:
            - name: postgres
              containerPort: 5432
              protocol: TCP

          env:
            - name: POSTGRES_DB
              value: {{ .Values.config.databaseName | quote }}

            - name: POSTGRES_USER
              value: {{ .Values.config.databaseUser | quote }}

            - name: POSTGRES_PASSWORD_FILE
              value: /run/secrets/database/password

            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata

          volumeMounts:
            - name: postgres-data
              mountPath: /var/lib/postgresql/data

            - name: database-password
              mountPath: /run/secrets/database
              readOnly: true

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
            failureThreshold: 5

          resources:
            {{- toYaml .Values.database.resources | nindent 12 }}

      volumes:
        - name: database-password

          secret:
            secretName: {{ .Values.existingDatabaseSecret }}

            items:
              - key: password
                path: password

  volumeClaimTemplates:
    - metadata:
        name: postgres-data

      spec:
        accessModes:
          - ReadWriteOnce

        {{- with .Values.database.storage.storageClassName }}
        storageClassName: {{ . | quote }}
        {{- end }}

        resources:
          requests:
            storage: {{ .Values.database.storage.size }}
{{- end }}
```

---

# 28. Be careful with StatefulSet upgrades

Some StatefulSet fields are difficult or impossible to change after creation.

Examples may include:

- Volume claim template details
    
- Certain selectors
    
- Service relationships
    

Changing:

```yaml
database:
  storage:
    size: 2Gi
```

does not necessarily resize an existing PVC automatically through the StatefulSet template.

Storage expansion depends on:

- StorageClass capabilities
    
- PVC modification
    
- Storage provider behavior
    
- Filesystem expansion support
    

Do not assume that every values change can be safely applied to every existing Kubernetes object.

---

# 29. Create NetworkPolicy templates

Create `templates/networkpolicy.yaml`:

```yaml
{{- if .Values.networkPolicy.enabled }}
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy

metadata:
  name: {{ include "device-monitor.fullname" . }}-database

  labels:
    {{- include "device-monitor.labels" . | nindent 4 }}

spec:
  podSelector:
    matchLabels:
      {{- include "device-monitor.selectorLabels" . | nindent 6 }}
      app.kubernetes.io/component: database

  policyTypes:
    - Ingress

  ingress:
    - from:
        - podSelector:
            matchLabels:
              {{- include "device-monitor.selectorLabels" . | nindent 14 }}
              app.kubernetes.io/component: api

      ports:
        - protocol: TCP
          port: 5432
{{- end }}
```

This allows API Pods from the same Helm release to reach the database.

The release-specific selector prevents another installation of the same chart from automatically matching.

---

# 30. Create `NOTES.txt`

Create `templates/NOTES.txt`:

```gotemplate
Device Monitor has been installed.

Release:
  {{ .Release.Name }}

Namespace:
  {{ .Release.Namespace }}

API service:
  {{ include "device-monitor.fullname" . }}-api

{{- if .Values.api.ingress.enabled }}

Ingress host:
  {{ .Values.api.ingress.host }}

Test:
  curl {{ if .Values.api.ingress.tls.enabled }}https{{ else }}http{{ end }}://{{ .Values.api.ingress.host }}/health
{{- else }}

Port-forward:
  kubectl port-forward \
    --namespace {{ .Release.Namespace }} \
    service/{{ include "device-monitor.fullname" . }}-api \
    8080:{{ .Values.api.service.port }}

Then:
  curl http://127.0.0.1:8080/health
{{- end }}
```

After installation, Helm displays these notes.

Keep notes useful and executable.

Do not display passwords, tokens, or sensitive Secret values.

---

# 31. Render the chart locally

Before installation:

```bash
helm template \
  device-monitor \
  ./device-monitor
```

Specify namespace:

```bash
helm template \
  device-monitor \
  ./device-monitor \
  --namespace device-monitor
```

Write output:

```bash
helm template \
  device-monitor \
  ./device-monitor \
  --namespace device-monitor \
  > rendered.yaml
```

Inspect:

```bash
less rendered.yaml
```

`helm template` renders chart templates locally without installing them; cluster lookups are simulated rather than performed as a normal live release operation. ([Helm](https://helm.sh/docs/helm/helm_template?utm_source=chatgpt.com "helm template"))

---

# 32. Render one template

Use:

```bash
helm template \
  device-monitor \
  ./device-monitor \
  --show-only templates/api-deployment.yaml
```

This is useful when debugging one file.

Render with debug information:

```bash
helm template \
  device-monitor \
  ./device-monitor \
  --debug
```

Typical errors include:

```text
nil pointer evaluating interface
unexpected EOF
wrong type for value
YAML parse error
required value missing
```

---

# 33. Lint the chart

Run:

```bash
helm lint ./device-monitor
```

Lint with production values:

```bash
helm lint \
  ./device-monitor \
  --values values-production.yaml
```

Helm lint checks chart structure and detects many template and metadata problems, but it cannot prove that the application will work correctly in the cluster.

You still need:

- Kubernetes schema validation
    
- Admission-policy validation
    
- Installation testing
    
- Rollout testing
    
- Application health checks
    

---

# 34. Validate rendered Kubernetes YAML

Render and validate against the cluster:

```bash
helm template \
  device-monitor \
  ./device-monitor \
  --namespace device-monitor \
  | kubectl apply \
      --dry-run=server \
      -f -
```

This validates:

- Rendered YAML
    
- Kubernetes schemas
    
- API availability
    
- Admission policies
    
- Pod Security Admission
    
- Certain webhook policies
    

Nothing is persisted because of:

```text
--dry-run=server
```

This is one of the most useful CI checks for a Helm chart.

---

# 35. Values precedence

Helm values can come from several sources.

General order from lower to higher precedence:

```text
Chart values.yaml
      ↓
Values files supplied with -f
      ↓
Later values files
      ↓
--set and related command-line overrides
```

Example:

```bash
helm template \
  production \
  ./device-monitor \
  -f values-common.yaml \
  -f values-production.yaml \
  --set api.replicaCount=5
```

Final replica count:

```text
5
```

When multiple files are passed, later overrides take precedence over earlier values for matching keys. `helm upgrade` merges supplied values with release values according to the selected flags and command options. ([Helm](https://helm.sh/docs/helm/helm_upgrade?utm_source=chatgpt.com "helm upgrade"))

---

# 36. Create development values

Create `values-development.yaml`:

```yaml
api:
  replicaCount: 1

  image:
    repository: device-api
    tag: development
    pullPolicy: IfNotPresent

  ingress:
    enabled: true
    className: nginx
    host: device-api.local

  resources:
    requests:
      cpu: 50m
      memory: 64Mi
      ephemeralStorage: 32Mi

    limits:
      cpu: 500m
      memory: 256Mi
      ephemeralStorage: 128Mi

database:
  enabled: true

  storage:
    size: 1Gi

config:
  appEnvironment: development
  logLevel: DEBUG

networkPolicy:
  enabled: false
```

Render:

```bash
helm template \
  development \
  ./device-monitor \
  -f values-development.yaml
```

---

# 37. Create production values

Create `values-production.yaml`:

```yaml
api:
  replicaCount: 3

  image:
    repository: registry.example.com/team/device-api
    tag: ""
    digest: "sha256:REPLACE_WITH_APPROVED_DIGEST"
    pullPolicy: IfNotPresent

  ingress:
    enabled: true
    className: nginx
    host: api.example.com

    tls:
      enabled: true
      secretName: device-api-tls

  resources:
    requests:
      cpu: 250m
      memory: 256Mi
      ephemeralStorage: 64Mi

    limits:
      cpu: "1"
      memory: 512Mi
      ephemeralStorage: 256Mi

database:
  enabled: false

config:
  appEnvironment: production
  logLevel: INFO
  databaseName: device_monitor
  databaseUser: device_app

existingDatabaseSecret: production-database-credentials

serviceAccount:
  create: true
  automountToken: false

networkPolicy:
  enabled: true

imagePullSecrets:
  - name: registry-credentials
```

In many production systems, PostgreSQL should be external or managed independently, hence:

```yaml
database:
  enabled: false
```

Your chart then needs an external database host value. Extend the values model accordingly rather than hard-coding the internal database Service.

---

# 38. Add internal versus external database configuration

Improve `values.yaml`:

```yaml
database:
  enabled: true

  external:
    host: ""
    port: 5432
```

Update the ConfigMap template:

```gotemplate
{{- if .Values.database.enabled }}
DB_HOST: {{ printf "%s-database" (include "device-monitor.fullname" .) }}
{{- else }}
DB_HOST: {{ required "database.external.host is required when database.enabled is false" .Values.database.external.host | quote }}
{{- end }}

DB_PORT: {{ .Values.database.external.port | default 5432 | quote }}
```

Production values:

```yaml
database:
  enabled: false

  external:
    host: postgresql.production.internal
    port: 5432
```

This makes chart behavior explicit.

---

# 39. Install the chart

Create the namespace:

```bash
kubectl create namespace device-monitor \
  --dry-run=client \
  -o yaml \
  | kubectl apply -f -
```

Ensure the database Secret exists:

```bash
kubectl create secret generic database-credentials \
  --namespace device-monitor \
  --from-literal=password=development-password \
  --dry-run=client \
  -o yaml \
  | kubectl apply -f -
```

Install:

```bash
helm install \
  device-monitor \
  ./device-monitor \
  --namespace device-monitor \
  -f values-development.yaml
```

`helm install` can install from an unpacked chart directory, packaged chart archive, repository reference, or URL. ([Helm](https://helm.sh/docs/helm/helm_install?utm_source=chatgpt.com "helm install"))

---

# 40. Inspect the release

List releases:

```bash
helm list \
  --namespace device-monitor
```

Status:

```bash
helm status \
  device-monitor \
  --namespace device-monitor
```

Get configured values:

```bash
helm get values \
  device-monitor \
  --namespace device-monitor
```

Show all computed values:

```bash
helm get values \
  device-monitor \
  --namespace device-monitor \
  --all
```

Show rendered manifest:

```bash
helm get manifest \
  device-monitor \
  --namespace device-monitor
```

Show everything Helm knows:

```bash
helm get all \
  device-monitor \
  --namespace device-monitor
```

---

# 41. Inspect Kubernetes resources

Helm is not the only source of operational truth.

Use Kubernetes:

```bash
kubectl get all \
  --namespace device-monitor
```

Check rollout:

```bash
kubectl rollout status \
  --namespace device-monitor \
  deployment/device-monitor-device-monitor-api
```

The exact generated name depends on your helper templates.

Inspect labels:

```bash
kubectl get pods \
  --namespace device-monitor \
  --show-labels
```

Helm typically labels resources with information including release instance and managing service, helping associate Kubernetes resources with the release. ([Helm](https://helm.sh/docs/chart_best_practices/labels/?utm_source=chatgpt.com "Labels and Annotations"))

---

# 42. Use `upgrade --install`

A common CI/CD command is:

```bash
helm upgrade \
  --install \
  device-monitor \
  ./device-monitor \
  --namespace device-monitor \
  --create-namespace \
  -f values-development.yaml
```

Meaning:

```text
Release does not exist
→ install

Release exists
→ upgrade
```

Helm officially documents `helm upgrade --install` as an install-or-upgrade workflow. ([Helm](https://helm.sh/docs/howto/charts_tips_and_tricks?utm_source=chatgpt.com "Chart Development Tips and Tricks"))

Add safety options:

```bash
helm upgrade \
  --install \
  device-monitor \
  ./device-monitor \
  --namespace device-monitor \
  --create-namespace \
  -f values-development.yaml \
  --wait \
  --timeout 5m \
  --atomic
```

---

# 43. Understand `--wait`, `--timeout`, and `--atomic`

## `--wait`

Waits for selected resources to reach an expected ready state before reporting success.

## `--timeout`

Sets the maximum wait duration.

Example:

```text
--timeout 5m
```

## `--atomic`

Treats the release as one controlled operation.

For installation, a failed operation is cleaned up.

For upgrade, Helm attempts rollback behavior if the upgrade fails.

Use:

```bash
helm upgrade \
  --install \
  RELEASE \
  CHART \
  --atomic \
  --timeout 10m
```

Do not assume this makes database migrations automatically reversible.

---

# 44. Perform an upgrade

Change:

```yaml
api:
  replicaCount: 1
```

to:

```yaml
api:
  replicaCount: 2
```

Upgrade:

```bash
helm upgrade \
  device-monitor \
  ./device-monitor \
  --namespace device-monitor \
  -f values-development.yaml \
  --wait \
  --timeout 5m
```

Check:

```bash
helm status \
  device-monitor \
  --namespace device-monitor
```

Inspect:

```bash
kubectl get deployment \
  --namespace device-monitor
```

You should now see two desired API replicas.

---

# 45. Release history

View:

```bash
helm history \
  device-monitor \
  --namespace device-monitor
```

Example:

```text
REVISION  STATUS       DESCRIPTION
1         superseded   Install complete
2         deployed     Upgrade complete
```

Inspect a revision’s values indirectly through release information and your version-controlled values files.

Your Git repository should remain the authoritative source for intended chart and environment configuration.

---

# 46. Roll back

Roll back to revision 1:

```bash
helm rollback \
  device-monitor \
  1 \
  --namespace device-monitor \
  --wait \
  --timeout 5m
```

Inspect:

```bash
helm history \
  device-monitor \
  --namespace device-monitor
```

A new revision appears.

Conceptually:

```text
Revision 1:
1 replica

Revision 2:
2 replicas

Rollback to revision 1:
creates revision 3
running configuration resembles revision 1
```

`helm rollback RELEASE REVISION` restores a previous release configuration while preserving history. ([Helm](https://helm.sh/docs/helm/helm_rollback?utm_source=chatgpt.com "helm rollback"))

---

# 47. Helm rollback limitations

A Helm rollback can revert Kubernetes manifests tracked by the release.

It does not automatically reverse:

- Database schema migrations
    
- Data written after deployment
    
- External cloud resources
    
- Manually changed external databases
    
- Secret values managed outside Helm
    
- External DNS changes
    
- External certificate operations
    
- PersistentVolume contents
    

Suppose revision 2 performs:

```sql
DROP COLUMN legacy_value;
```

Rolling back the application chart does not recreate that data.

Your migration strategy must support rollback or forward recovery separately.

---

# 48. Avoid manual changes to Helm-managed resources

Suppose Helm manages:

```text
Deployment/device-api
```

You manually run:

```bash
kubectl edit deployment device-api
```

The live object now differs from:

- Chart templates
    
- Values
    
- Helm’s intended release state
    
- Git configuration
    

The next Helm upgrade may overwrite the manual change.

Correct workflow:

```text
Change chart or values
    ↓
Review
    ↓
Render and test
    ↓
Commit
    ↓
Helm upgrade
```

Emergency changes should still be captured back into the chart or values immediately.

---

# 49. Uninstall a release

Run:

```bash
helm uninstall \
  device-monitor \
  --namespace device-monitor
```

This removes the Kubernetes resources Helm tracks for the release.

Inspect:

```bash
helm list \
  --namespace device-monitor
```

Check storage:

```bash
kubectl get pvc \
  --namespace device-monitor
```

PersistentVolumeClaims may remain or be removed depending on:

- How they were created
    
- Resource annotations
    
- StatefulSet behavior
    
- Chart templates
    
- Storage reclaim policy
    

Never assume uninstall safely deletes or preserves all data.

Test the behavior before production use.

---

# 50. Protect persistent resources

A Helm annotation can instruct Helm to retain a resource during uninstall:

```yaml
metadata:
  annotations:
    helm.sh/resource-policy: keep
```

Example for a manually managed PVC:

```yaml
metadata:
  annotations:
    helm.sh/resource-policy: keep
```

This can prevent accidental deletion by Helm, but it creates an orphaned resource that Helm no longer manages after release removal.

Use it intentionally and document cleanup.

Helm’s chart-development guidance discusses using resource-policy annotations when a resource must not be deleted with the release. ([Helm](https://helm.sh/docs/howto/charts_tips_and_tricks/?utm_source=chatgpt.com "Chart Development Tips and Tricks"))

---

# 51. Do not template Secrets casually

This template is risky:

```yaml
apiVersion: v1
kind: Secret

stringData:
  password: {{ .Values.database.password }}
```

Then installation might use:

```bash
helm install \
  --set database.password=secret
```

Risks:

- Shell history
    
- CI logs
    
- Release values
    
- Helm release metadata
    
- Debug output
    
- Git values files
    

Prefer:

```text
Secret created externally
+
Chart references Secret name
```

Example:

```yaml
existingDatabaseSecret: production-database-credentials
```

Your chart mounts the Secret without owning its sensitive value.

---

# 52. Never generate a random password on every render

Tempting template:

```gotemplate
password: {{ randAlphaNum 32 | b64enc }}
```

Templates execute again during upgrades.

A newly generated value can trigger resource changes and may desynchronize application and database credentials.

Helm warns that generated random values can differ on every upgrade because templates are re-executed. ([Helm](https://helm.sh/docs/howto/charts_tips_and_tricks?utm_source=chatgpt.com "Chart Development Tips and Tricks"))

Use externally managed Secrets or carefully preserve existing generated values using a deliberate lookup strategy.

---

# 53. Create chart tests

Create `templates/tests/api-connection.yaml`:

```yaml
apiVersion: v1
kind: Pod

metadata:
  name: "{{ include "device-monitor.fullname" . }}-test-api"

  labels:
    {{- include "device-monitor.labels" . | nindent 4 }}

  annotations:
    helm.sh/hook: test

spec:
  restartPolicy: Never
  automountServiceAccountToken: false

  securityContext:
    runAsNonRoot: true

    seccompProfile:
      type: RuntimeDefault

  containers:
    - name: test

      image: curlimages/curl:latest

      command:
        - sh
        - -c
        - >
          curl
          --fail
          --silent
          http://{{ include "device-monitor.fullname" . }}-api:{{ .Values.api.service.port }}/health

      securityContext:
        runAsNonRoot: true
        allowPrivilegeEscalation: false
        readOnlyRootFilesystem: true

        capabilities:
          drop:
            - ALL
```

For production, pin the test image to an immutable approved version or digest rather than `latest`.

Run:

```bash
helm test \
  device-monitor \
  --namespace device-monitor \
  --logs
```

A chart test is useful after installation, but it is not a replacement for full application integration testing.

---

# 54. Helm hooks

Hooks allow resources to run at release lifecycle points.

Examples include:

```text
pre-install
post-install
pre-upgrade
post-upgrade
pre-delete
post-delete
pre-rollback
post-rollback
test
```

Helm hooks allow chart authors to add actions at specific points in a release lifecycle. ([Helm](https://helm.sh/docs/topics/charts_hooks/?utm_source=chatgpt.com "Chart Hooks"))

A migration Job might use:

```yaml
metadata:
  annotations:
    helm.sh/hook: pre-upgrade
    helm.sh/hook-weight: "0"
    helm.sh/hook-delete-policy: before-hook-creation,hook-succeeded
```

---

# 55. Be careful with migration hooks

A `pre-upgrade` migration runs before updated workloads are applied.

Potential problem:

```text
Migration changes schema
      ↓
Migration succeeds
      ↓
Application upgrade fails
      ↓
Helm rolls application manifests back
      ↓
Old application may not understand new schema
```

Helm rollback does not roll back the database.

Safer migration patterns include:

```text
Backward-compatible schema change
→ deploy compatible application
→ migrate data
→ remove old schema later
```

Use Helm hooks only after understanding failure and recovery paths.

---

# 56. Hook deletion policies

Hook Jobs may accumulate.

Common annotation:

```yaml
helm.sh/hook-delete-policy: before-hook-creation,hook-succeeded
```

Meaning:

```text
Delete old hook resource before creating a new one
Delete successful hook resource
```

You may retain failed hooks temporarily for investigation.

Do not delete failed migration evidence before collecting:

- Logs
    
- Exit code
    
- Database state
    
- Generated manifest
    
- Release history
    

---

# 57. Chart dependencies

A chart can depend on other charts.

Example `Chart.yaml`:

```yaml
dependencies:
  - name: postgresql
    version: "X.Y.Z"
    repository: "oci://registry.example.com/charts"

    condition: database.enabled
```

Manage dependencies:

```bash
helm dependency update \
  ./device-monitor
```

List:

```bash
helm dependency list \
  ./device-monitor
```

Build from lock information:

```bash
helm dependency build \
  ./device-monitor
```

Helm dependencies are declared in `Chart.yaml` and synchronized into the chart’s `charts/` directory using dependency commands. ([Helm](https://helm.sh/docs/helm/helm_dependency?utm_source=chatgpt.com "helm dependency"))

---

# 58. Use dependencies carefully

Using a PostgreSQL dependency chart saves work, but it also introduces:

- Another chart’s values structure
    
- Another release lifecycle
    
- Security settings you must review
    
- Image choices
    
- Storage behavior
    
- Upgrade behavior
    
- Potential CRDs or hooks
    
- Additional supply-chain trust
    

Do not assume a popular chart automatically matches your requirements.

Review:

```text
Images
Default users
Security context
Persistence
NetworkPolicies
Resources
Upgrade notes
Backup strategy
License
Maintenance status
```

---

# 59. Application chart versus umbrella chart

You may create:

## One chart containing everything

```text
device-monitor/
├── API
├── PostgreSQL
├── Ingress
└── NetworkPolicy
```

## An umbrella chart

```text
device-platform/
├── dependency: device-api
├── dependency: PostgreSQL
├── dependency: Mosquitto
└── dependency: monitoring
```

An umbrella chart coordinates several component charts.

Use it when components are separately reusable but should be deployed together.

Do not create a massive chart with hundreds of unrelated controls if independent lifecycle management would be clearer.

---

# 60. Package the chart

First lint:

```bash
helm lint ./device-monitor
```

Package:

```bash
helm package ./device-monitor
```

Result:

```text
device-monitor-0.1.0.tgz
```

Inspect:

```bash
helm show chart \
  device-monitor-0.1.0.tgz
```

Show values:

```bash
helm show values \
  device-monitor-0.1.0.tgz
```

Install the package:

```bash
helm install \
  device-monitor \
  ./device-monitor-0.1.0.tgz \
  --namespace device-monitor
```

---

# 61. Chart repositories and OCI registries

Charts can be distributed using:

- Traditional chart repositories
    
- OCI-compatible registries
    
- Direct chart archives
    
- Git-based delivery workflows
    

For an OCI registry:

```bash
helm registry login registry.example.com
```

Push a packaged chart:

```bash
helm push \
  device-monitor-0.1.0.tgz \
  oci://registry.example.com/helm
```

Install:

```bash
helm install \
  device-monitor \
  oci://registry.example.com/helm/device-monitor \
  --version 0.1.0
```

Use a separate repository path or naming policy for Helm charts and container images where appropriate.

---

# 62. Chart provenance and signing

Helm supports chart package verification mechanisms.

Conceptually:

```text
Chart package
+
Provenance file
+
Trusted signing identity
=
Verifiable chart package
```

Commands include:

```bash
helm verify CHART_PACKAGE
```

A verified chart still needs:

- Template review
    
- Image verification
    
- Admission policies
    
- Security testing
    
- Dependency review
    

Chart signing confirms package provenance according to the signing method; it does not prove that the deployed application is free of vulnerabilities.

---

# 63. Helm in CI/CD

A practical pipeline:

```text
Commit chart changes
       ↓
helm lint
       ↓
helm template
       ↓
Kubernetes server dry run
       ↓
Policy checks
       ↓
Install into temporary namespace
       ↓
helm test
       ↓
Package chart
       ↓
Push chart to registry
       ↓
Deploy approved chart version
```

Example:

```bash
helm lint \
  ./device-monitor \
  -f values-production.yaml
```

```bash
helm template \
  device-monitor \
  ./device-monitor \
  --namespace validation \
  -f values-production.yaml \
  > rendered.yaml
```

```bash
kubectl apply \
  --dry-run=server \
  -f rendered.yaml
```

---

# 64. Deploy from CI

Example deployment command:

```bash
helm upgrade \
  --install \
  device-monitor \
  oci://registry.example.com/helm/device-monitor \
  --version 0.4.0 \
  --namespace device-monitor \
  --create-namespace \
  -f values-production.yaml \
  --set-string api.image.digest="$IMAGE_DIGEST" \
  --atomic \
  --wait \
  --timeout 10m
```

Record:

```text
Chart version
Application image digest
Helm release revision
Git commit
Pipeline ID
Deployment time
```

Do not supply sensitive passwords with `--set`.

---

# 65. `--set`, `--set-string`, and `--set-file`

## `--set`

Sets a value:

```bash
--set api.replicaCount=5
```

Type conversion may occur.

## `--set-string`

Forces a string:

```bash
--set-string api.image.tag=001
```

Without this, a value that looks numeric may be interpreted differently than intended.

## `--set-file`

Loads value content from a file:

```bash
--set-file configuration.applicationConfig=application.conf
```

Do not use these mechanisms to expose production secrets casually.

---

# 66. `--reuse-values` risks

Helm supports reusing existing release values during an upgrade.

Example:

```bash
helm upgrade \
  device-monitor \
  ./device-monitor \
  --reuse-values
```

This may preserve values that no longer exist in your current version-controlled environment file.

That can create hidden configuration drift.

For controlled deployments, prefer explicitly supplying the complete intended values:

```bash
helm upgrade \
  --install \
  ... \
  -f values-production.yaml
```

Treat the Git-managed values as the desired state.

---

# 67. Helm values schema

You can add:

```text
values.schema.json
```

to validate chart values.

Create `device-monitor/values.schema.json`:

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "required": [
    "api",
    "database",
    "config",
    "existingDatabaseSecret"
  ],
  "properties": {
    "api": {
      "type": "object",
      "required": [
        "replicaCount",
        "image",
        "service"
      ],
      "properties": {
        "replicaCount": {
          "type": "integer",
          "minimum": 1,
          "maximum": 20
        },
        "image": {
          "type": "object",
          "required": [
            "repository"
          ],
          "properties": {
            "repository": {
              "type": "string",
              "minLength": 1
            },
            "tag": {
              "type": "string"
            },
            "digest": {
              "type": "string"
            }
          }
        }
      }
    },
    "existingDatabaseSecret": {
      "type": "string",
      "minLength": 1
    }
  }
}
```

Now invalid values can be rejected earlier.

Test:

```bash
helm lint \
  ./device-monitor \
  --set api.replicaCount=0
```

---

# 68. Helm versus Kustomize

Kustomize is integrated with `kubectl` and customizes Kubernetes resources through a `kustomization.yaml` file without template expressions. It transforms ordinary Kubernetes YAML using overlays, patches, generators, prefixes, labels, and image replacements. ([Kubernetes](https://kubernetes.io/docs/tasks/manage-kubernetes-objects/kustomization/?utm_source=chatgpt.com "Declarative Management of Kubernetes Objects Using ..."))

## Helm

Uses:

```text
Templates
+
Values
+
Release management
```

Strengths:

- Application packaging
    
- Reusable parameterized charts
    
- Installation history
    
- Upgrades
    
- Rollbacks
    
- Dependencies
    
- Hooks
    
- Chart repositories
    

## Kustomize

Uses:

```text
Base Kubernetes YAML
+
Patches and transformations
```

Strengths:

- No template language
    
- Rendered configuration stays close to Kubernetes YAML
    
- Strong base-and-overlay model
    
- Built into `kubectl`
    
- Useful for environment overlays
    

---

# 69. When to choose Helm

Helm is well suited when:

- You distribute an application package
    
- Users need many configurable options
    
- You need release history
    
- You want packaged versions
    
- You manage chart dependencies
    
- The same application is installed many times
    
- You publish through a chart or OCI registry
    

Example:

```text
Install the device-monitor platform
with configurable:
- image
- replicas
- ingress
- storage
- database
- security
```

---

# 70. When to choose Kustomize

Kustomize is well suited when:

- You own a small number of deployments
    
- Base manifests are already clear
    
- Environments differ through small patches
    
- You want no template syntax
    
- You prefer plain Kubernetes YAML
    
- You do not need Helm release history
    

Example:

```text
Base:
api Deployment and Service

Development overlay:
1 replica, debug logging

Production overlay:
3 replicas, production Ingress, strict resources
```

Kustomize is available as a standalone tool and as a built-in `kubectl` feature. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/management/?utm_source=chatgpt.com "Managing Workloads"))

---

# 71. Using Helm and Kustomize together

Some organizations use:

```text
Helm
→ package third-party or reusable applications

Kustomize
→ patch rendered or organization-specific resources
```

This can be useful but can also create two configuration layers.

Before combining them, define clearly:

```text
Which values belong to Helm?
Which changes belong to Kustomize?
Which rendered artifacts are committed?
Which tool is the deployment entry point?
```

Avoid an opaque workflow where nobody can predict the final manifest.

---

# 72. Common Helm template error: indentation

Bad:

```yaml
labels:
{{ include "device-monitor.labels" . }}
```

Rendered:

```yaml
labels:
app.kubernetes.io/name: device-monitor
```

The label lines are not nested correctly.

Correct:

```yaml
labels:
  {{- include "device-monitor.labels" . | nindent 2 }}
```

For deeper nesting:

```yaml
template:
  metadata:
    labels:
      {{- include "device-monitor.labels" . | nindent 6 }}
```

Always inspect rendered YAML.

---

# 73. Common error: incorrect context inside loops

Example values:

```yaml
extraEnvironment:
  - name: FEATURE_A
    value: enabled
  - name: FEATURE_B
    value: disabled
```

Template:

```gotemplate
{{- range .Values.extraEnvironment }}
- name: {{ .name }}
  value: {{ .value | quote }}
{{- end }}
```

Inside `range`, the dot refers to the current list item.

To access the root context, save it:

```gotemplate
{{- $root := . }}

{{- range .Values.extraEnvironment }}
- name: {{ .name }}
  value: {{ .value | quote }}

  # Root release:
  # {{ $root.Release.Name }}
{{- end }}
```

The root variable `$` also refers to the top-level context.

---

# 74. Common error: missing required value

Suppose production enables TLS but forgets:

```yaml
secretName:
```

Use:

```gotemplate
{{ required "api.ingress.tls.secretName is required" .Values.api.ingress.tls.secretName }}
```

Rendering fails early rather than creating an incomplete Ingress.

Error messages should identify:

- Exact missing value
    
- Why it is required
    
- When it is required
    

---

# 75. Common error: bool or number quoted incorrectly

For strings:

```yaml
LOG_LEVEL: {{ .Values.config.logLevel | quote }}
```

For integers:

```yaml
replicas: {{ .Values.api.replicaCount }}
```

Bad:

```yaml
replicas: "{{ .Values.api.replicaCount }}"
```

Kubernetes expects an integer, not a string.

For environment variables, numeric-looking values usually need quoting because environment values are strings:

```yaml
- name: DB_PORT
  value: {{ .Values.database.external.port | quote }}
```

Helm chart-development guidance recommends quoting strings but not quoting integer fields that Kubernetes expects as numbers. ([Helm](https://helm.sh/docs/howto/charts_tips_and_tricks/?utm_source=chatgpt.com "Chart Development Tips and Tricks"))

---

# 76. Common error: immutable field changes

An upgrade may fail because a template changed:

- Deployment selector
    
- Service clusterIP
    
- StatefulSet volume claim template
    
- Job immutable Pod template
    
- Certain persistent storage fields
    

Error example:

```text
field is immutable
```

Do not solve this automatically by deleting production resources.

Determine:

- Can the value remain unchanged?
    
- Is a new object name required?
    
- Is a controlled migration needed?
    
- Will deletion affect persistent data?
    
- Does the chart need upgrade documentation?
    

---

# 77. Common error: chart says deployed but application fails

Helm tracks Kubernetes release operations.

It does not understand every application-level business condition.

A release can appear deployed while:

- API returns errors
    
- Database contains wrong schema
    
- MQTT processing is broken
    
- External dependencies fail
    
- NetworkPolicy blocks traffic
    

Use:

```bash
helm test
```

plus:

```bash
kubectl rollout status
curl /health
integration tests
metrics and logs
```

Helm release status is one signal, not complete proof.

---

# 78. Troubleshooting a failed release

Start with:

```bash
helm status \
  RELEASE \
  --namespace NAMESPACE
```

History:

```bash
helm history \
  RELEASE \
  --namespace NAMESPACE
```

Values:

```bash
helm get values \
  RELEASE \
  --namespace NAMESPACE \
  --all
```

Manifest:

```bash
helm get manifest \
  RELEASE \
  --namespace NAMESPACE
```

Then Kubernetes:

```bash
kubectl get pods \
  --namespace NAMESPACE
```

```bash
kubectl describe pod \
  --namespace NAMESPACE \
  POD
```

```bash
kubectl logs \
  --namespace NAMESPACE \
  POD \
  --previous
```

```bash
kubectl events \
  --namespace NAMESPACE \
  --types=Warning
```

---

# 79. Show the difference before upgrading

Render old and new configurations:

```bash
helm get manifest \
  device-monitor \
  --namespace device-monitor \
  > current.yaml
```

```bash
helm template \
  device-monitor \
  ./device-monitor \
  --namespace device-monitor \
  -f values-production.yaml \
  > proposed.yaml
```

Compare:

```bash
diff -u current.yaml proposed.yaml
```

In professional workflows, use a Kubernetes-aware diff tool or CI review output.

The goal is to know before deployment:

```text
Which objects change?
Which images change?
Which resources recreate?
Which storage fields change?
Which security rules change?
```

---

# 80. Chart documentation

Create `device-monitor/README.md` containing:

- Purpose
    
- Prerequisites
    
- Supported Kubernetes versions
    
- Required Secrets
    
- Installation command
    
- Upgrade command
    
- Rollback procedure
    
- Uninstall behavior
    
- Persistent-data behavior
    
- Values table
    
- Backup procedure
    
- Migration notes
    
- Security assumptions
    
- Troubleshooting
    

A chart without operational documentation is incomplete.

---

# 81. Recommended release workflow

```text
1. Modify templates or values.
2. Update Chart.yaml version.
3. Update appVersion where appropriate.
4. Run helm lint.
5. Render all supported environments.
6. Run Kubernetes server dry-run.
7. Run policy validation.
8. Install into a temporary namespace.
9. Execute helm test.
10. Run application integration tests.
11. Package the chart.
12. Sign or verify according to policy.
13. Push to the chart registry.
14. Deploy an exact chart version.
15. Deploy an exact image digest.
16. Wait for readiness.
17. Run post-deployment verification.
18. Record chart, image, and release revision.
```

---

# 82. Day 27 practical laboratory

## Exercise 1 — Create a chart

Run:

```bash
helm create device-monitor
```

Inspect every generated file.

Remove unnecessary templates.

## Exercise 2 — Chart metadata

Configure:

```text
name
description
chart version
application version
```

Explain the difference between chart and app version.

## Exercise 3 — Values

Move these into `values.yaml`:

- Image repository
    
- Image tag or digest
    
- Replicas
    
- Service ports
    
- Ingress hostname
    
- Storage size
    
- Resources
    
- Security IDs
    
- Probe paths
    

## Exercise 4 — Helpers

Create reusable helpers for:

- Name
    
- Full name
    
- Labels
    
- Selector labels
    
- ServiceAccount name
    
- API image reference
    

## Exercise 5 — Templates

Convert these manifests into templates:

- ConfigMap
    
- ServiceAccount
    
- API Deployment
    
- API Service
    
- Ingress
    
- Database Service
    
- Database StatefulSet
    
- NetworkPolicy
    

## Exercise 6 — Rendering

Use:

```bash
helm template
```

Render:

- Development
    
- Production
    
- Database-enabled
    
- External-database configurations
    

## Exercise 7 — Validation

Run:

```bash
helm lint
```

Then:

```bash
helm template ... | kubectl apply --dry-run=server -f -
```

## Exercise 8 — Installation

Install the chart.

Inspect:

- Helm release
    
- Kubernetes resources
    
- Computed values
    
- Rendered release manifest
    

## Exercise 9 — Upgrade

Change:

- Replica count
    
- Image version
    
- Log level
    

Run an upgrade and observe the new revision.

## Exercise 10 — Rollback

Deploy a deliberately unhealthy image.

Observe the failed rollout.

Roll back to the previous revision.

---

# 83. Day 27 command reference

```bash
# Create chart
helm create device-monitor

# Lint chart
helm lint ./device-monitor

# Render chart
helm template \
  RELEASE \
  ./device-monitor \
  --namespace NAMESPACE \
  -f values.yaml

# Render one template
helm template \
  RELEASE \
  ./device-monitor \
  --show-only templates/api-deployment.yaml

# Server-side validation
helm template \
  RELEASE \
  ./device-monitor \
  | kubectl apply \
      --dry-run=server \
      -f -

# Install
helm install \
  RELEASE \
  ./device-monitor \
  --namespace NAMESPACE \
  --create-namespace \
  -f values.yaml

# Install or upgrade
helm upgrade \
  --install \
  RELEASE \
  ./device-monitor \
  --namespace NAMESPACE \
  --create-namespace \
  -f values.yaml \
  --atomic \
  --wait \
  --timeout 10m

# List releases
helm list \
  --namespace NAMESPACE

# Release status
helm status \
  RELEASE \
  --namespace NAMESPACE

# Release history
helm history \
  RELEASE \
  --namespace NAMESPACE

# Release values
helm get values \
  RELEASE \
  --namespace NAMESPACE \
  --all

# Release manifest
helm get manifest \
  RELEASE \
  --namespace NAMESPACE

# Rollback
helm rollback \
  RELEASE \
  REVISION \
  --namespace NAMESPACE \
  --wait

# Run chart tests
helm test \
  RELEASE \
  --namespace NAMESPACE \
  --logs

# Package
helm package ./device-monitor

# Dependency management
helm dependency update ./device-monitor
helm dependency build ./device-monitor
helm dependency list ./device-monitor

# Uninstall
helm uninstall \
  RELEASE \
  --namespace NAMESPACE
```

---

# 84. Knowledge check

## What is Helm?

A Kubernetes package manager and release-management tool that renders chart templates using supplied values.

## What is a chart?

A package containing templates, default values, metadata, and optionally dependencies and hooks.

## What is a release?

One installed instance of a chart.

## What is a revision?

One recorded state in the history of a Helm release.

## Does Helm replace Kubernetes?

No. It generates and manages Kubernetes resources.

## What does `values.yaml` contain?

Default configurable chart values.

## What does the `templates/` directory contain?

Kubernetes resource templates.

## What is `.Values`?

The Helm template object containing resolved values.

## What is `.Release.Name`?

The current release’s name.

## What is `.Chart.AppVersion`?

The application version declared in chart metadata.

## What does `helm template` do?

It renders a chart locally without installing the release. ([Helm](https://helm.sh/docs/helm/helm_template?utm_source=chatgpt.com "helm template"))

## What does `helm lint` do?

It checks chart structure and templates for common issues.

## What does `helm upgrade --install` do?

It installs the release when absent and upgrades it when already present. ([Helm](https://helm.sh/docs/howto/charts_tips_and_tricks?utm_source=chatgpt.com "Chart Development Tips and Tricks"))

## Does rollback restore database data?

No.

## Why should production images use digests?

A digest identifies exact immutable image content.

## Why should passwords not normally be chart values?

They may be exposed through Git, command history, CI logs, or Helm release values.

## What is a chart dependency?

Another chart required or optionally included by the parent chart.

## What is a Helm hook?

A resource executed at a particular release lifecycle point.

## What is the main difference between Helm and Kustomize?

Helm uses templates, values, packaging, and release history; Kustomize transforms plain Kubernetes resources using overlays and patches.

---

# 85. Day 27 completion challenge

Complete this independently:

1. Install and verify Helm.
    
2. Confirm the active Kubernetes context.
    
3. Create a `device-monitor` chart.
    
4. Remove unused generated templates.
    
5. Configure `Chart.yaml`.
    
6. Separate chart version from app version.
    
7. Design a clear `values.yaml`.
    
8. Add API image repository.
    
9. Add tag and digest alternatives.
    
10. Add replica count.
    
11. Add Service configuration.
    
12. Add Ingress configuration.
    
13. Add TLS configuration.
    
14. Add resource requests and limits.
    
15. Add probe configuration.
    
16. Add database enable/disable configuration.
    
17. Add persistent-storage configuration.
    
18. Add ServiceAccount configuration.
    
19. Create naming helpers.
    
20. Create label helpers.
    
21. Create an image-reference helper.
    
22. Template the ConfigMap.
    
23. Template the ServiceAccount.
    
24. Template the API Deployment.
    
25. Template the API Service.
    
26. Template the Ingress.
    
27. Template PostgreSQL.
    
28. Template the PVC mechanism.
    
29. Template a NetworkPolicy.
    
30. Add installation notes.
    
31. Render the complete chart.
    
32. Render one selected template.
    
33. Lint the chart.
    
34. Validate the result with server dry-run.
    
35. Create development values.
    
36. Create staging values.
    
37. Create production values.
    
38. Configure production to use an image digest.
    
39. Configure production to use an external database.
    
40. Install the development release.
    
41. Inspect release status.
    
42. Inspect computed values.
    
43. Inspect the stored manifest.
    
44. Verify Kubernetes objects.
    
45. Add a Helm test.
    
46. Run the Helm test.
    
47. Upgrade the image.
    
48. Upgrade the replica count.
    
49. Inspect release history.
    
50. Deploy a broken image.
    
51. Diagnose the failed rollout.
    
52. Roll back to a known-good revision.
    
53. Verify the application after rollback.
    
54. Package the chart.
    
55. Inspect the packaged metadata.
    
56. Push it to a test OCI registry.
    
57. Pull and install the packaged version.
    
58. Add a values schema.
    
59. Reject an invalid replica count.
    
60. Document installation, upgrade, rollback, backup, and uninstall behavior.
    

The central Day 27 model is:

```text
Chart metadata
      +
Default values
      +
Environment overrides
      +
Kubernetes templates
      ↓
Helm rendering
      ↓
Validated manifests
      ↓
Named release
      ↓
Install / upgrade / test / rollback
      ↓
Ordinary Kubernetes resources
```

The most important operational lesson is:

> Helm is most valuable when it turns a collection of Kubernetes manifests into a versioned, configurable, testable application package. Keep templates understandable, values explicit, Secrets external, images immutable, rendered output validated, and database migration risk separate from Helm’s application rollback mechanism.