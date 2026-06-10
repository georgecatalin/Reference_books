
Days 22–24 taught you how to deploy and connect Kubernetes applications.

Today you will learn how to answer the operational questions that appear after deployment:

```text
Is the application actually healthy?
Why is a Pod restarting?
Why is one request slow?
Which container is consuming memory?
Did the scheduler fail to place a Pod?
Why did a rollout stop?
What happened before the container restarted?
How do we detect problems before users report them?
```

The central lesson is:

> Observability means collecting enough evidence—logs, metrics, events, health signals, and traces—to understand the internal state of a system from its external behavior.

Kubernetes exposes several sources of operational evidence, but a complete monitoring system must normally be installed and configured separately. Kubernetes documentation groups observability around metrics, logs, traces, health checks, and system-component information. ([Kubernetes](https://kubernetes.io/docs/concepts/cluster-administration/observability/?utm_source=chatgpt.com "Observability"))

---

## 1. Day 25 objectives

By the end of today, you should understand:

- Monitoring versus observability
    
- Logs, metrics, traces, and events
    
- Kubernetes application logs
    
- Multi-container Pod logs
    
- Current and previous container logs
    
- Kubernetes Events
    
- `kubectl describe`
    
- Container restart reasons
    
- `CrashLoopBackOff`
    
- `OOMKilled`
    
- Metrics Server
    
- `kubectl top`
    
- CPU and memory interpretation
    
- Resource requests versus actual usage
    
- Node resource pressure
    
- Prometheus fundamentals
    
- Metric names and labels
    
- Counters, gauges, histograms, and summaries
    
- Application instrumentation
    
- Exporters
    
- Grafana’s role
    
- Alertmanager’s role
    
- Health probes versus monitoring
    
- Service-level indicators
    
- A repeatable Kubernetes troubleshooting workflow
    

---

# 2. Monitoring versus observability

## Monitoring

Monitoring usually answers predefined questions:

```text
Is CPU above 80%?
Is the API health endpoint failing?
Are fewer than three replicas ready?
Is PostgreSQL unavailable?
```

You decide in advance which conditions matter and monitor them.

## Observability

Observability helps investigate questions that you did not predict:

```text
Why did latency increase only for one endpoint?
Why are requests failing only on one Pod?
Why did a Pod restart at 02:14?
Which deployment introduced the error?
Why can the API resolve DNS but not connect to PostgreSQL?
```

A mature system needs both.

---

# 3. The main observability signals

The traditional core signals are:

```text
Logs
Metrics
Traces
```

Kubernetes also provides:

```text
Events
Object status
Health probes
```

## Logs

Detailed records of individual events:

```text
2026-06-09T10:15:20Z level=INFO request completed
2026-06-09T10:15:21Z level=ERROR database timeout
```

## Metrics

Numerical measurements over time:

```text
CPU usage
Memory usage
Requests per second
Error percentage
Request duration
Active database connections
MQTT messages received
```

## Traces

The path of one request through several components:

```text
Browser
  → Ingress
  → API
  → PostgreSQL
  → response
```

## Kubernetes Events

Short-lived records describing cluster decisions and state changes:

```text
Pod scheduled
Image pull failed
Volume mount failed
Container restarted
Readiness probe failed
```

---

# 4. Kubernetes logging architecture

Containerized applications should normally write their logs to:

```text
stdout
stderr
```

The container runtime and node logging system capture those streams, and `kubectl logs` retrieves them. Kubernetes documentation identifies standard output and standard error as the most common logging method for containerized applications. ([Kubernetes](https://kubernetes.io/docs/concepts/cluster-administration/logging/?utm_source=chatgpt.com "Logging Architecture"))

Good:

```python
print("API started", flush=True)
```

Good:

```c
fprintf(stderr, "Database connection failed\n");
```

Less suitable for ordinary container logging:

```text
/var/log/device-api/application.log
```

A file written only inside the container may disappear when the Pod is replaced and is not automatically returned by `kubectl logs`.

---

# 5. Read Pod logs

List your API Pods:

```bash
kubectl get pods \
  -l app.kubernetes.io/name=device-api
```

Choose one:

```bash
API_POD="$(
  kubectl get pod \
    -l app.kubernetes.io/name=device-api \
    -o jsonpath='{.items[0].metadata.name}'
)"
```

Read logs:

```bash
kubectl logs "$API_POD"
```

Show the final 50 lines:

```bash
kubectl logs \
  --tail=50 \
  "$API_POD"
```

Follow new entries:

```bash
kubectl logs \
  --follow \
  "$API_POD"
```

Stop following with:

```text
Ctrl+C
```

This does not stop the Pod.

---

# 6. Add timestamps

Run:

```bash
kubectl logs \
  --timestamps \
  "$API_POD"
```

Recent logs only:

```bash
kubectl logs \
  --since=10m \
  --timestamps \
  "$API_POD"
```

Since an exact time:

```bash
kubectl logs \
  --since-time='2026-06-09T10:00:00Z' \
  "$API_POD"
```

Operational timestamps should normally use UTC so that logs from different nodes and systems can be correlated reliably.

---

# 7. Multi-container Pod logs

Your API Pod has:

```text
Init container:
wait-for-database

Application container:
device-api
```

List container names:

```bash
kubectl get pod "$API_POD" \
  -o jsonpath='{.spec.initContainers[*].name}{"\n"}{.spec.containers[*].name}{"\n"}'
```

Read application logs:

```bash
kubectl logs \
  "$API_POD" \
  --container=device-api
```

Read init-container logs:

```bash
kubectl logs \
  "$API_POD" \
  --container=wait-for-database
```

The init-container log may reveal:

```text
Waiting for PostgreSQL...
Waiting for PostgreSQL...
PostgreSQL is ready
```

For a Pod with multiple ordinary containers, always specify the correct container when the command is ambiguous.

---

# 8. Read previous container logs

Suppose a container crashed and Kubernetes restarted it.

Current logs:

```bash
kubectl logs \
  "$API_POD" \
  --container=device-api
```

Previous container instance:

```bash
kubectl logs \
  "$API_POD" \
  --container=device-api \
  --previous
```

This is one of the most valuable troubleshooting commands for:

```text
CrashLoopBackOff
Application exception
Segmentation fault
Out-of-memory termination
Failed startup
```

Once the Pod itself is deleted, access to its node-local container logs may be lost unless centralized logging has already collected them.

---

# 9. Logs from all Deployment Pods

Use a label selector:

```bash
kubectl logs \
  -l app.kubernetes.io/name=device-api \
  --all-containers=true \
  --prefix=true \
  --tail=100
```

Follow:

```bash
kubectl logs \
  -l app.kubernetes.io/name=device-api \
  --all-containers=true \
  --prefix=true \
  --follow
```

The prefix helps identify which Pod and container produced each line.

However, `kubectl logs` is not a complete centralized log-analysis solution.

For production, you usually need logs collected into a searchable platform.

---

# 10. Structured logging

Compare these two messages.

Weak:

```text
Database failed
```

Better:

```text
timestamp=2026-06-09T10:15:21Z
level=ERROR
service=device-api
pod=device-api-6cd77c9b7c-8x2gk
request_id=3cb7e1
operation=list_devices
database_host=database
error="connection timed out"
```

JSON-style structured log:

```json
{
  "timestamp": "2026-06-09T10:15:21Z",
  "level": "ERROR",
  "service": "device-api",
  "request_id": "3cb7e1",
  "operation": "list_devices",
  "error": "connection timed out"
}
```

Structured logs are easier to:

- Search
    
- Filter
    
- Aggregate
    
- Parse
    
- Alert on
    
- Connect to request traces
    

Never log:

- Passwords
    
- API tokens
    
- Private keys
    
- Full authorization headers
    
- Sensitive customer payloads
    

---

# 11. Useful application log fields

For the API:

```text
timestamp
severity
service
version
pod
request_id
HTTP method
path
status code
duration
client identity where appropriate
error category
```

For your MQTT daemon or consumer:

```text
timestamp
device_id
topic
QoS
message_id
operation
processing duration
database result
duplicate indicator
connection status
```

A useful MQTT log might be:

```json
{
  "level": "INFO",
  "service": "mqtt-consumer",
  "device_id": "vm-karlsfeld-01",
  "topic": "deviceCluster/vm-karlsfeld-01/status/heartbeat",
  "qos": 1,
  "duration_ms": 12,
  "result": "stored"
}
```

---

# 12. Kubernetes Events

Events describe activity involving Kubernetes objects.

Use:

```bash
kubectl events
```

Only warnings:

```bash
kubectl events \
  --types=Warning
```

For one object:

```bash
kubectl events \
  --for pod/"$API_POD"
```

Watch:

```bash
kubectl events \
  --watch
```

You can also use:

```bash
kubectl get events \
  --sort-by=.metadata.creationTimestamp
```

Kubernetes provides `kubectl events` to display important events for namespaces or selected resources. ([Kubernetes](https://kubernetes.io/docs/reference/kubectl/generated/kubectl_events/?utm_source=chatgpt.com "kubectl events"))

---

# 13. Events are not application logs

Events may show:

```text
Scheduled
Pulling
Pulled
Created
Started
Unhealthy
BackOff
FailedMount
FailedScheduling
```

Application logs may show:

```text
Database authentication failed
Invalid configuration file
HTTP request returned 500
```

Use both.

```text
Kubernetes Event:
Container restarted because liveness probe failed

Application log:
Database query blocked the event loop for 60 seconds
```

Together, they explain the incident.

---

# 14. Events are temporary evidence

Kubernetes Events are not designed as permanent audit history.

They may be:

- Aggregated
    
- Repeated
    
- Expired
    
- Removed after retention periods
    
- Missing after enough time has passed
    

If Events are operationally important, export them into a long-term logging or event platform.

Do not assume you can investigate a month-old incident using only:

```bash
kubectl get events
```

---

# 15. `kubectl describe`

Describe the API Pod:

```bash
kubectl describe pod "$API_POD"
```

Important sections:

```text
Node
IP
Labels
Annotations
Containers
State
Last State
Ready
Restart Count
Requests
Limits
Conditions
Volumes
Events
```

Describe the Deployment:

```bash
kubectl describe deployment device-api
```

Describe the Service:

```bash
kubectl describe service device-api
```

Describe the StatefulSet:

```bash
kubectl describe statefulset database
```

`kubectl describe` combines object configuration, runtime state, and related Events, which makes it one of the first commands to use when diagnosing Kubernetes objects. Kubernetes debugging guidance explicitly recommends `kubectl describe pod` as a standard initial diagnostic step. ([Kubernetes](https://kubernetes.io/docs/tasks/debug/debug-application/debug-running-pod/?utm_source=chatgpt.com "Debug Running Pods"))

---

# 16. Inspect container state precisely

Run:

```bash
kubectl get pod "$API_POD" \
  -o jsonpath='
Current state: {.status.containerStatuses[0].state}
Last state: {.status.containerStatuses[0].lastState}
Restart count: {.status.containerStatuses[0].restartCount}
Ready: {.status.containerStatuses[0].ready}
'
```

A container may be:

```text
waiting
running
terminated
```

A terminated container contains information such as:

```text
exitCode
reason
signal
startedAt
finishedAt
message
```

Readable query:

```bash
kubectl get pod "$API_POD" \
  -o jsonpath='{range .status.containerStatuses[*]}Container={.name}{"\n"}RestartCount={.restartCount}{"\n"}LastReason={.lastState.terminated.reason}{"\n"}LastExitCode={.lastState.terminated.exitCode}{"\n\n"}{end}'
```

---

# 17. Understand `CrashLoopBackOff`

`CrashLoopBackOff` does not mean that Kubernetes itself crashed.

It means:

```text
Container starts
    ↓
Container exits
    ↓
Kubernetes restarts it
    ↓
It exits again
    ↓
Kubernetes waits progressively longer
```

Check:

```bash
kubectl get pods
```

Then:

```bash
kubectl describe pod POD_NAME
```

Current logs:

```bash
kubectl logs POD_NAME
```

Previous logs:

```bash
kubectl logs \
  --previous \
  POD_NAME
```

Possible causes:

- Missing configuration
    
- Incorrect command
    
- Application exception
    
- Unreachable mandatory dependency
    
- Permission error
    
- Read-only filesystem conflict
    
- Wrong architecture
    
- Missing shared library
    
- Failed liveness probe
    
- Out-of-memory termination
    

---

# 18. Create a crash-loop laboratory

Create `crash-loop.yaml`:

```yaml
apiVersion: v1
kind: Pod

metadata:
  name: crash-loop

spec:
  restartPolicy: Always

  containers:
    - name: application
      image: alpine

      command:
        - sh
        - -c
        - |
          echo "Application starting"
          echo "Fatal configuration error" >&2
          sleep 2
          exit 17
```

Apply:

```bash
kubectl apply \
  -f crash-loop.yaml
```

Watch:

```bash
kubectl get pod crash-loop \
  --watch
```

Inspect:

```bash
kubectl describe pod crash-loop
```

Read current and previous logs:

```bash
kubectl logs crash-loop
```

```bash
kubectl logs \
  --previous \
  crash-loop
```

Inspect the exit code:

```bash
kubectl get pod crash-loop \
  -o jsonpath='{.status.containerStatuses[0].lastState.terminated.exitCode}'

echo
```

Expected:

```text
17
```

Clean up:

```bash
kubectl delete pod crash-loop
```

---

# 19. `OOMKilled`

`OOMKilled` means the process was killed because of an out-of-memory condition.

Check:

```bash
kubectl describe pod POD_NAME
```

or:

```bash
kubectl get pod POD_NAME \
  -o jsonpath='{.status.containerStatuses[0].lastState.terminated.reason}'

echo
```

Possible result:

```text
OOMKilled
```

This commonly happens when:

```text
Container memory use
>
Container memory limit
```

A memory leak or unexpectedly large workload may also exhaust node memory where suitable limits are absent.

---

# 20. Create an OOM laboratory

Create `memory-test.yaml`:

```yaml
apiVersion: v1
kind: Pod

metadata:
  name: memory-test

spec:
  restartPolicy: Never

  containers:
    - name: allocator
      image: python:3.13-slim

      command:
        - python
        - -c
        - |
          import time

          chunks = []

          while True:
              chunks.append(bytearray(10 * 1024 * 1024))
              print(
                  f"allocated_mb={len(chunks) * 10}",
                  flush=True,
              )
              time.sleep(1)

      resources:
        requests:
          memory: 32Mi

        limits:
          memory: 100Mi
```

Apply:

```bash
kubectl apply \
  -f memory-test.yaml
```

Follow logs:

```bash
kubectl logs \
  --follow \
  memory-test
```

Inspect:

```bash
kubectl describe pod memory-test
```

Termination reason:

```bash
kubectl get pod memory-test \
  -o jsonpath='{.status.containerStatuses[0].state.terminated.reason}'

echo
```

Clean up:

```bash
kubectl delete pod memory-test
```

---

# 21. Failed scheduling

A Pod can remain:

```text
Pending
```

because no node can satisfy its requests.

Create `unschedulable.yaml`:

```yaml
apiVersion: v1
kind: Pod

metadata:
  name: unschedulable

spec:
  containers:
    - name: application
      image: alpine
      command:
        - sleep
        - "3600"

      resources:
        requests:
          cpu: "100"
          memory: 100Gi
```

Apply:

```bash
kubectl apply \
  -f unschedulable.yaml
```

Inspect:

```bash
kubectl get pod unschedulable
```

```bash
kubectl describe pod unschedulable
```

You should see a scheduling event explaining that nodes lack sufficient resources.

Clean up:

```bash
kubectl delete pod unschedulable
```

---

# 22. Node conditions

Inspect the node:

```bash
kubectl get nodes
```

```bash
kubectl describe node minikube
```

Important conditions include:

```text
Ready
MemoryPressure
DiskPressure
PIDPressure
NetworkUnavailable
```

Conceptually:

```text
Ready=True
→ Node can normally accept workload

MemoryPressure=True
→ Node is under memory pressure

DiskPressure=True
→ Node storage is under pressure

PIDPressure=True
→ Too many processes or threads
```

Node pressure may cause:

- Scheduling failures
    
- Pod eviction
    
- Slow image pulls
    
- Container failures
    
- Control-plane instability
    

---

# 23. Pod conditions

Inspect:

```bash
kubectl get pod "$API_POD" \
  -o jsonpath='{range .status.conditions[*]}{.type}={.status} reason={.reason}{"\n"}{end}'
```

Common conditions include:

```text
PodScheduled
Initialized
ContainersReady
Ready
```

Interpretation:

```text
PodScheduled=True
→ Scheduler assigned a node

Initialized=True
→ Init containers completed

ContainersReady=True
→ Ordinary containers report ready

Ready=True
→ Pod is ready for Service traffic
```

A Pod may be:

```text
Running
but
Ready=False
```

That means the process exists but should not receive normal Service traffic.

---

# 24. Health probes are not full monitoring

Health probes answer narrow local questions.

## Startup probe

```text
Did this application finish starting?
```

## Readiness probe

```text
Should traffic be sent here?
```

## Liveness probe

```text
Should Kubernetes restart this container?
```

Monitoring asks broader questions:

```text
What is the error rate?
Is latency increasing?
How many clients are connected?
Are all replicas failing together?
Is database capacity approaching a limit?
```

Do not attempt to encode every operational condition into a liveness probe.

A dependency outage should often make an application unready without causing continuous restarts.

---

# 25. Enable Metrics Server in Minikube

Metrics Server is a lightweight cluster component that collects resource metrics from kubelets and exposes them through the Kubernetes resource metrics API.

`kubectl top` depends on Metrics Server being installed and running. ([Kubernetes](https://kubernetes.io/docs/reference/kubectl/generated/kubectl_top/?utm_source=chatgpt.com "kubectl top"))

Enable it:

```bash
minikube addons enable metrics-server
```

Check:

```bash
kubectl get deployment \
  --namespace kube-system \
  metrics-server
```

Wait:

```bash
kubectl rollout status \
  --namespace kube-system \
  deployment/metrics-server
```

The metrics API may need a short time before values become available.

---

# 26. Use `kubectl top`

Node usage:

```bash
kubectl top nodes
```

Pod usage:

```bash
kubectl top pods
```

All namespaces:

```bash
kubectl top pods \
  --all-namespaces
```

Containers:

```bash
kubectl top pod "$API_POD" \
  --containers
```

Sort by CPU:

```bash
kubectl top pods \
  --sort-by=cpu
```

Sort by memory:

```bash
kubectl top pods \
  --sort-by=memory
```

`kubectl top` shows recent CPU and memory usage obtained from Metrics Server; it is intended as a compact resource view rather than a complete long-term monitoring system. ([Kubernetes](https://kubernetes.io/docs/reference/kubectl/generated/kubectl_top/?utm_source=chatgpt.com "kubectl top"))

---

# 27. Understanding CPU output

Example:

```text
NAME                         CPU(cores)   MEMORY(bytes)
device-api-7bfc9cc6c-2x9lp  12m          92Mi
```

CPU:

```text
12m
```

means approximately:

```text
0.012 CPU core
```

The unit relationship is:

```text
1000m = 1 CPU
500m  = 0.5 CPU
100m  = 0.1 CPU
```

Compare actual usage with requests:

```yaml
resources:
  requests:
    cpu: 100m
```

If actual usage is usually:

```text
10m
```

the 100m request may still be appropriate for bursts, but it deserves review.

Do not size requests from a single quiet measurement.

---

# 28. Understanding memory output

Example:

```text
92Mi
```

Compare with:

```yaml
requests:
  memory: 128Mi

limits:
  memory: 512Mi
```

Interpretation:

```text
Actual memory: 92Mi
Scheduling request: 128Mi
Maximum limit: 512Mi
```

A container close to its memory limit may be at risk of OOM termination during bursts.

Memory differs from CPU:

- CPU can normally be throttled.
    
- Excess memory use may lead to termination.
    

---

# 29. Requests versus real usage

Resource requests influence scheduling:

```text
Request
→ scheduler capacity accounting
```

Limits constrain runtime use:

```text
Limit
→ maximum or throttle boundary
```

Actual metrics show what the container currently consumes:

```text
kubectl top
→ recent measured usage
```

A good resource-sizing workflow is:

```text
Set initial requests and limits
    ↓
Collect metrics under realistic load
    ↓
Observe normal and peak use
    ↓
Adjust values
    ↓
Repeat
```

Avoid both extremes:

```text
No requests or limits
```

and:

```text
Extremely restrictive guesses
```

---

# 30. Metrics Server is not Prometheus

Metrics Server is primarily intended to provide recent resource metrics for Kubernetes components and commands such as:

```text
kubectl top
Horizontal Pod Autoscaler
```

It is not intended as a complete historical metrics database.

It does not provide all of these by itself:

- Long-term history
    
- Custom application metrics
    
- Complex alert rules
    
- Dashboarding
    
- Business metrics
    
- Distributed queries
    
- Long-term capacity planning
    

For those needs, Prometheus or another monitoring platform is commonly used.

---

# 31. Generate API load

Run a temporary load generator:

```bash
kubectl run load-generator \
  --image=busybox:1.36 \
  --restart=Never \
  -- \
  sh -c '
    while true; do
      wget -qO- http://device-api/health >/dev/null
    done
  '
```

Observe:

```bash
kubectl top pods \
  --sort-by=cpu
```

Watch continuously:

```bash
watch -n 2 \
  'kubectl top pods --sort-by=cpu'
```

Stop `watch` with `Ctrl+C`.

Remove the generator:

```bash
kubectl delete pod load-generator
```

Observe how CPU usage decreases.

---

# 32. Inspect declared resource values

For the API:

```bash
kubectl get deployment device-api \
  -o jsonpath='{range .spec.template.spec.containers[*]}Container={.name}{"\n"}Requests={.resources.requests}{"\n"}Limits={.resources.limits}{"\n"}{end}'
```

For all Pods:

```bash
kubectl get pods \
  -o custom-columns='NAME:.metadata.name,CPU_REQUEST:.spec.containers[*].resources.requests.cpu,MEMORY_REQUEST:.spec.containers[*].resources.requests.memory,CPU_LIMIT:.spec.containers[*].resources.limits.cpu,MEMORY_LIMIT:.spec.containers[*].resources.limits.memory'
```

Compare these with:

```bash
kubectl top pods
```

---

# 33. Kubernetes metrics categories

You will encounter several different metric categories.

## Resource metrics

```text
CPU
Memory
```

Used by:

```text
kubectl top
Horizontal Pod Autoscaler
```

## Kubernetes object-state metrics

Examples:

```text
Desired replicas
Ready replicas
Pod phase
Deployment generation
PVC status
Job completion
```

These may be exposed by a component such as `kube-state-metrics`.

## Node and container metrics

Examples:

```text
Filesystem usage
Network traffic
Container CPU
Container memory
Node load
Process counts
```

## Application metrics

Examples:

```text
HTTP request total
HTTP error total
Request duration
MQTT messages processed
Database-operation duration
Connected-device count
```

Metrics Server covers only a limited resource-metrics use case.

---

# 34. Prometheus fundamentals

Prometheus is an open-source monitoring and alerting system designed around time-series data.

It stores values with timestamps and optional key-value labels. ([prometheus.io](https://prometheus.io/docs/introduction/overview/?utm_source=chatgpt.com "Overview | Prometheus"))

Conceptual metric:

```text
http_requests_total{
  service="device-api",
  method="GET",
  path="/api/devices",
  status="200"
} 1582
```

Parts:

```text
Metric name:
http_requests_total

Labels:
service
method
path
status

Value:
1582

Timestamp:
recorded by Prometheus
```

---

# 35. Prometheus pull model

A common Prometheus workflow is:

```text
Application exposes /metrics
        ↓
Prometheus discovers application
        ↓
Prometheus periodically requests /metrics
        ↓
Prometheus stores time-series samples
        ↓
Queries, dashboards, and alert rules use the data
```

Example endpoint:

```text
http://device-api:5000/metrics
```

Response:

```text
# HELP device_api_http_requests_total Total HTTP requests
# TYPE device_api_http_requests_total counter
device_api_http_requests_total{method="GET",status="200"} 283
```

---

# 36. Prometheus metric types

Prometheus instrumentation libraries define four principal metric types:

```text
Counter
Gauge
Histogram
Summary
```

([prometheus.io](https://prometheus.io/docs/concepts/metric_types/?utm_source=chatgpt.com "Metric types"))

## Counter

A value that normally only increases or resets:

```text
HTTP requests processed
MQTT messages received
Application errors
Jobs completed
```

Example:

```text
device_api_requests_total 1842
```

Do not use a counter for current temperature.

## Gauge

A value that can increase and decrease:

```text
Current connected clients
Queue length
Memory use
Current temperature
Active database connections
```

Example:

```text
mqtt_connected_devices 27
```

## Histogram

Records observations into buckets:

```text
Request duration
Message-processing duration
Payload size
```

Histograms are especially useful for calculating server-side latency percentiles across aggregated instances.

## Summary

Also records observations and may calculate quantiles in the instrumented application.

Summaries can be useful, but their precomputed quantiles are harder to aggregate across replicas.

---

# 37. Good metric naming

Prometheus-style names often use:

```text
snake_case
```

Counters commonly end with:

```text
_total
```

Examples:

```text
device_api_http_requests_total
device_api_http_errors_total
mqtt_messages_received_total
mqtt_messages_failed_total
mqtt_connected_clients
device_api_request_duration_seconds
```

Include units in the metric name:

```text
_seconds
_bytes
_celsius
```

Avoid:

```text
request_time
```

Prefer:

```text
request_duration_seconds
```

---

# 38. Labels

Labels provide dimensions:

```text
device_api_http_requests_total{
  method="GET",
  route="/api/devices",
  status="200"
}
```

Useful labels:

```text
method
route
status
service
version
environment
```

Dangerous high-cardinality labels:

```text
request_id
timestamp
full URL with unique IDs
user email
device serial number when millions exist
random error message
```

Each unique label combination produces another time series.

Uncontrolled cardinality can consume significant memory and storage.

Use logs or traces—not metric labels—for unique request identifiers.

---

# 39. Instrument the Python API

Install the Prometheus Python client in your application:

```text
prometheus-client
```

Example:

```python
from flask import Flask, Response, request
from prometheus_client import (
    CONTENT_TYPE_LATEST,
    Counter,
    Histogram,
    generate_latest,
)

app = Flask(__name__)

REQUESTS = Counter(
    "device_api_http_requests_total",
    "Total HTTP requests handled by the device API",
    ["method", "route", "status"],
)

REQUEST_DURATION = Histogram(
    "device_api_request_duration_seconds",
    "HTTP request duration",
    ["method", "route"],
)


@app.after_request
def record_request(response):
    route = request.url_rule.rule if request.url_rule else "unknown"

    REQUESTS.labels(
        method=request.method,
        route=route,
        status=str(response.status_code),
    ).inc()

    return response


@app.get("/metrics")
def metrics():
    return Response(
        generate_latest(),
        mimetype=CONTENT_TYPE_LATEST,
    )
```

A production implementation should measure duration around the complete request lifecycle and handle exceptions consistently.

---

# 40. Avoid path-cardinality mistakes

Do not label this directly:

```python
request.path
```

when paths contain identifiers:

```text
/api/devices/1
/api/devices/2
/api/devices/3
...
```

That can produce one series per path value.

Prefer route templates:

```text
/api/devices/<device_id>
```

In Flask:

```python
request.url_rule.rule
```

Possible result:

```text
/api/devices/<int:device_id>
```

This keeps cardinality controlled.

---

# 41. Add MQTT metrics

Your MQTT consumer could expose:

```text
mqtt_messages_received_total
mqtt_messages_processed_total
mqtt_messages_failed_total
mqtt_message_processing_duration_seconds
mqtt_broker_connected
mqtt_database_write_failures_total
mqtt_duplicate_messages_total
```

Good labels:

```text
message_type
qos
result
service_version
```

Be careful with:

```text
device_id
topic
job_id
session_id
```

These may produce very high cardinality.

For detailed per-device investigations, use logs and database queries.

---

# 42. What is an exporter?

Some applications do not expose Prometheus metrics natively.

An exporter:

```text
Reads metrics from another system
    ↓
Converts them to Prometheus format
    ↓
Exposes /metrics
```

Prometheus maintains an ecosystem of exporters and integrations for systems that cannot be instrumented directly. ([prometheus.io](https://prometheus.io/docs/instrumenting/exporters/?utm_source=chatgpt.com "Exporters and integrations"))

Examples:

```text
Node exporter
→ Linux host metrics

PostgreSQL exporter
→ database metrics

Mosquitto exporter
→ MQTT broker metrics, depending on implementation

Blackbox exporter
→ probes HTTP, TCP, DNS, and other endpoints
```

Use trusted, maintained exporters and restrict their credentials.

---

# 43. Prometheus targets

A Prometheus target is an endpoint being scraped.

Examples:

```text
device-api:5000/metrics
postgres-exporter:9187/metrics
node-exporter:9100/metrics
```

A target may be:

```text
UP
DOWN
```

Target down means Prometheus could not successfully scrape it.

Possible causes:

- Pod unavailable
    
- Service unavailable
    
- Wrong port
    
- NetworkPolicy block
    
- TLS error
    
- Authentication failure
    
- Invalid metrics output
    
- Scrape timeout
    

A target being up does not prove that every application function works.

---

# 44. Prometheus queries

Prometheus uses PromQL.

Examples:

All API request counters:

```promql
device_api_http_requests_total
```

Requests per second over five minutes:

```promql
rate(device_api_http_requests_total[5m])
```

Error rate:

```promql
sum(
  rate(
    device_api_http_requests_total{
      status=~"5.."
    }[5m]
  )
)
```

Total request rate:

```promql
sum(
  rate(
    device_api_http_requests_total[5m]
  )
)
```

Error proportion:

```promql
sum(
  rate(
    device_api_http_requests_total{
      status=~"5.."
    }[5m]
  )
)
/
sum(
  rate(
    device_api_http_requests_total[5m]
  )
)
```

Use a safe denominator strategy in production alert expressions to avoid misleading division behavior when traffic is zero.

---

# 45. Latency histograms

Suppose your application exposes:

```text
device_api_request_duration_seconds_bucket
device_api_request_duration_seconds_sum
device_api_request_duration_seconds_count
```

Approximate 95th percentile:

```promql
histogram_quantile(
  0.95,
  sum by (le) (
    rate(
      device_api_request_duration_seconds_bucket[5m]
    )
  )
)
```

Broken down by route:

```promql
histogram_quantile(
  0.95,
  sum by (le, route) (
    rate(
      device_api_request_duration_seconds_bucket[5m]
    )
  )
)
```

Histograms need carefully chosen buckets that match your expected latency range.

---

# 46. Grafana’s role

Prometheus stores and queries metrics.

Grafana commonly provides:

- Dashboards
    
- Panels
    
- Graphs
    
- Tables
    
- Variables
    
- Alert visualization
    
- Links among metrics, logs, and traces
    

Conceptually:

```text
Applications and exporters
        ↓
Prometheus
        ↓
Grafana dashboards
```

A dashboard is useful for investigation but does not by itself guarantee notification when nobody is looking.

Alerts are needed for urgent conditions.

---

# 47. Alertmanager’s role

Prometheus evaluates alerting rules.

Alertmanager receives generated alerts and handles:

- Grouping
    
- Deduplication
    
- Routing
    
- Silences
    
- Inhibition
    
- Notification receivers
    

([prometheus.io](https://prometheus.io/docs/alerting/latest/alertmanager/?utm_source=chatgpt.com "Alertmanager"))

Conceptually:

```text
Prometheus rule becomes active
        ↓
Alert sent to Alertmanager
        ↓
Grouped and routed
        ↓
Email, chat, paging platform, or another receiver
```

Alertmanager does not normally collect metrics itself.

---

# 48. Good alerts

A good alert should be:

- Actionable
    
- Specific
    
- Relevant to user impact
    
- Resistant to temporary noise
    
- Linked to a runbook
    
- Assigned an appropriate severity
    

Weak alert:

```text
CPU exceeded 70% for 10 seconds
```

Better:

```text
Device API has had fewer than two ready replicas for five minutes.
```

Better:

```text
More than 5% of API requests returned 5xx responses over 10 minutes.
```

Better:

```text
PostgreSQL PVC is projected to fill within 24 hours.
```

Do not alert on every ordinary container restart.

---

# 49. Alert duration

An alert rule may require a condition to remain true:

```yaml
for: 5m
```

Conceptually:

```text
Condition true for 10 seconds
→ no page yet

Condition true continuously for 5 minutes
→ alert fires
```

This reduces noise from brief transient events.

Some severe conditions may require immediate alerting:

```text
All production API replicas unavailable
```

Choose duration according to impact and expected recovery behavior.

---

# 50. The four golden signals

A useful application-monitoring framework is:

```text
Latency
Traffic
Errors
Saturation
```

## Latency

How long operations take:

```text
HTTP request duration
MQTT processing duration
Database-query duration
```

## Traffic

How much work is occurring:

```text
Requests per second
MQTT messages per second
Connected clients
```

## Errors

How much work fails:

```text
HTTP 5xx rate
Database write failures
MQTT parsing errors
```

## Saturation

How close resources are to capacity:

```text
CPU throttling
Memory usage
Database connections
Queue backlog
Disk usage
```

These signals are more useful than watching CPU alone.

---

# 51. Service-level indicators and objectives

## Service-Level Indicator

A measured aspect of service behavior:

```text
Successful request proportion
95th percentile latency
Availability
Message-processing delay
```

## Service-Level Objective

A target:

```text
99.9% of API requests succeed over 30 days.
```

or:

```text
95% of heartbeat messages are processed within 5 seconds.
```

## Service-Level Agreement

A contractual commitment, often involving consequences.

Start with useful internal objectives before making external contractual promises.

---

# 52. Suggested indicators for your MQTT platform

## Device API

```text
Availability
HTTP 5xx percentage
p95 request latency
Ready replicas
Database-error rate
```

## MQTT broker

```text
Connected clients
Connection failures
Messages received
Messages sent
Dropped messages
Authentication failures
```

## MQTT consumer

```text
Messages processed per second
Processing errors
Processing latency
Queue backlog
Duplicate count
Broker connection status
```

## PostgreSQL

```text
Availability
Connection count
Query latency
Transaction rate
Deadlocks
Disk consumption
Replication status if applicable
```

## Device fleet

```text
Online-device count
Heartbeat age
Offline-device count
Firmware distribution
Telemetry freshness
```

---

# 53. Centralized logging architecture

A production cluster commonly uses a node-level log agent:

```text
Application Pods write stdout/stderr
        ↓
Container runtime stores node-local log files
        ↓
Log collector runs on each node
        ↓
Collector forwards logs
        ↓
Central log storage
        ↓
Search and dashboards
```

The node agent is often deployed as a:

```text
DaemonSet
```

because one collector is needed on every node.

Kubernetes documents node-level logging agents as a standard cluster logging architecture. ([Kubernetes](https://kubernetes.io/docs/concepts/cluster-administration/observability/?utm_source=chatgpt.com "Observability"))

Examples of log stacks include:

```text
Fluent Bit → Elasticsearch/OpenSearch
Fluent Bit → Loki
Vector → centralized storage
Cloud-provider logging agents
```

---

# 54. Why a sidecar is not always needed

One possible pattern is:

```text
Application writes log file
        ↓
Sidecar tails the file
        ↓
Sidecar writes stdout
```

But where possible, it is simpler for the application itself to write directly to stdout and stderr.

Sidecars add:

- Another container
    
- More CPU and memory
    
- More configuration
    
- More failure modes
    
- More log-stream complexity
    

Use them when the application cannot be changed or when specialized transformation is required.

---

# 55. Kubernetes system logs

Application logs are only one layer.

Cluster-system logs may include:

```text
kubelet
container runtime
API server
scheduler
controller manager
kube-proxy
CNI plugin
CoreDNS
Ingress controller
storage provisioner
```

System-component logs record cluster events and can be crucial for debugging scheduling, networking, storage, and runtime problems. ([Kubernetes](https://kubernetes.io/docs/concepts/cluster-administration/system-logs/?utm_source=chatgpt.com "System Logs"))

In Minikube:

```bash
minikube logs
```

For node services on a normal Linux node:

```bash
sudo journalctl \
  --unit kubelet
```

Container runtime:

```bash
sudo journalctl \
  --unit containerd
```

Exact service names depend on the cluster installation.

---

# 56. Debug a running container

Standard commands:

```bash
kubectl exec \
  "$API_POD" \
  -- id
```

```bash
kubectl exec \
  "$API_POD" \
  -- printenv
```

```bash
kubectl exec \
  "$API_POD" \
  -- cat /etc/resolv.conf
```

But minimal or distroless images may not contain:

```text
sh
curl
nslookup
ps
ss
```

Do not install a full debugging toolbox permanently in the production image only for occasional incidents.

---

# 57. `kubectl debug`

Kubernetes provides `kubectl debug` for troubleshooting Pods and nodes.

Add an ephemeral debugging container:

```bash
kubectl debug \
  -it \
  "$API_POD" \
  --image=nicolaka/netshoot \
  --target=device-api
```

Possible tools include:

```text
curl
dig
nslookup
nc
ip
ss
tcpdump
```

Availability and behavior depend on cluster support and security controls.

Kubernetes documentation describes `kubectl debug` and ephemeral containers as an approach for debugging running Pods when the original image lacks diagnostic tools. ([Kubernetes](https://kubernetes.io/docs/tasks/debug/debug-application/debug-running-pod/?utm_source=chatgpt.com "Debug Running Pods"))

Use only approved diagnostic images.

---

# 58. Create a copy for debugging

You can create a modified copy:

```bash
kubectl debug \
  "$API_POD" \
  --copy-to=device-api-debug \
  --container=device-api \
  -- sh
```

This can be useful when:

- The original Pod restarts too quickly
    
- You need to change the command
    
- You need a writable copy
    
- You do not want to disturb the production Pod
    

The copied Pod is a diagnostic object.

Do not accidentally expose it through normal Service selectors.

---

# 59. Debug a node

Conceptual command:

```bash
kubectl debug \
  node/NODE_NAME \
  -it \
  --image=ubuntu
```

This creates a privileged diagnostic environment associated with the node, depending on cluster permissions and policy.

Node debugging is security-sensitive.

It may expose:

- Host filesystem
    
- Process information
    
- Network details
    
- Container runtime state
    

Restrict node-debug privileges to authorized administrators.

---

# 60. Rollout monitoring

Check Deployment status:

```bash
kubectl rollout status \
  deployment/device-api
```

History:

```bash
kubectl rollout history \
  deployment/device-api
```

Replica summary:

```bash
kubectl get deployment device-api
```

Detailed conditions:

```bash
kubectl get deployment device-api \
  -o jsonpath='{range .status.conditions[*]}{.type}={.status} reason={.reason} message={.message}{"\n"}{end}'
```

Typical conditions:

```text
Available
Progressing
ReplicaFailure
```

A rollout can stall because:

- New image fails
    
- Readiness never succeeds
    
- Resource requests cannot be scheduled
    
- Image pull fails
    
- Configuration is missing
    
- Progress deadline is exceeded
    

---

# 61. Progress deadline

A Deployment can define:

```yaml
progressDeadlineSeconds: 600
```

Meaning Kubernetes expects meaningful rollout progress within ten minutes.

Example:

```yaml
spec:
  progressDeadlineSeconds: 300
```

If progress stops long enough, the Deployment can report:

```text
ProgressDeadlineExceeded
```

This does not automatically undo the rollout.

Your deployment pipeline or operator should detect the failure and decide whether to roll back.

---

# 62. Inspect restart counts

All Pods:

```bash
kubectl get pods \
  -o custom-columns='NAME:.metadata.name,READY:.status.containerStatuses[*].ready,RESTARTS:.status.containerStatuses[*].restartCount,STATUS:.status.phase'
```

Watch:

```bash
watch -n 2 \
  "kubectl get pods -o custom-columns='NAME:.metadata.name,RESTARTS:.status.containerStatuses[*].restartCount,STATUS:.status.phase'"
```

A steadily increasing restart count is evidence of a continuing failure.

One restart after a controlled update or temporary fault may not be urgent.

Investigate context.

---

# 63. Detect a failing readiness probe

Describe:

```bash
kubectl describe pod "$API_POD"
```

Events may show:

```text
Readiness probe failed:
HTTP probe failed with status code 500
```

Check manually from inside:

```bash
kubectl exec \
  "$API_POD" \
  -- \
  python -c '
import urllib.request
print(
    urllib.request.urlopen(
        "http://localhost:5000/health",
        timeout=3,
    ).read().decode()
)
'
```

Possible causes:

- Application not ready
    
- Health path incorrect
    
- Port incorrect
    
- Timeout too short
    
- Database unavailable
    
- Handler returned non-success status
    
- Application listens only on another address
    

---

# 64. Detect a failing liveness probe

Repeated liveness failure causes container restarts.

Check:

```bash
kubectl describe pod "$API_POD"
```

Look for:

```text
Liveness probe failed
Killing container
```

Then check previous logs:

```bash
kubectl logs \
  "$API_POD" \
  --container=device-api \
  --previous
```

A liveness probe that is too aggressive can create an outage by restarting a slow but recoverable application.

Do not configure liveness to fail merely because one external dependency is temporarily unavailable.

---

# 65. Troubleshoot Service unavailability

Check Deployment:

```bash
kubectl get deployment device-api
```

Check Pods:

```bash
kubectl get pods \
  -l app.kubernetes.io/name=device-api
```

Check readiness:

```bash
kubectl get pods \
  -l app.kubernetes.io/name=device-api \
  -o custom-columns='NAME:.metadata.name,READY:.status.containerStatuses[*].ready'
```

Check Service:

```bash
kubectl get service device-api
```

Check EndpointSlices:

```bash
kubectl get endpointslices \
  -l kubernetes.io/service-name=device-api
```

Check NetworkPolicies:

```bash
kubectl get networkpolicies
```

Check Ingress where applicable:

```bash
kubectl describe ingress device-api
```

Follow the traffic path one layer at a time.

---

# 66. Troubleshoot high latency

A useful investigation sequence is:

```text
1. Confirm user-visible latency.
2. Check request-rate change.
3. Check error-rate change.
4. Check per-Pod CPU and memory.
5. Check CPU throttling if available.
6. Check database latency.
7. Check queue or connection-pool saturation.
8. Compare all replicas.
9. Check recent deployments.
10. Inspect logs and traces for slow requests.
```

Do not assume:

```text
High latency
=
High CPU
```

Possible causes also include:

- Database locks
    
- Network timeout
    
- DNS delay
    
- External API delay
    
- Insufficient connection pools
    
- Disk latency
    
- Thread starvation
    
- Garbage collection
    
- MQTT backlog
    

---

# 67. Troubleshoot one bad replica

Suppose only one Pod has errors.

Compare:

```bash
kubectl logs POD_A \
  --since=10m
```

```bash
kubectl logs POD_B \
  --since=10m
```

Inspect placement:

```bash
kubectl get pods \
  -o wide
```

Compare:

- Node
    
- Image ID
    
- Environment
    
- Mounted ConfigMaps and Secrets
    
- Restart count
    
- Resource usage
    
- Requests hitting the Pod
    
- Node conditions
    

Delete only after collecting evidence:

```bash
kubectl delete pod BAD_POD
```

The Deployment replaces it, but deleting first may destroy useful diagnostic evidence.

---

# 68. Correlation IDs

Generate or accept a request ID:

```text
X-Request-ID
```

Include it in:

- Ingress access logs
    
- API logs
    
- Database-operation logs
    
- Downstream service calls
    
- Error responses where appropriate
    
- Distributed traces
    

Example:

```text
request_id=fa83a1
```

Then search:

```text
Ingress request
API processing
Database query
MQTT command
Response
```

This helps follow one request across distributed components.

---

# 69. Distributed tracing

Tracing records spans.

Example trace:

```text
Trace: request 8fa31

Span 1:
Ingress request
duration: 140 ms

Span 2:
API processing
duration: 130 ms

Span 3:
PostgreSQL query
duration: 115 ms
```

This reveals that most latency came from PostgreSQL.

OpenTelemetry is a common instrumentation standard for producing and exporting traces, metrics, and logs.

Tracing is especially useful once an application includes many services and asynchronous components.

---

# 70. Do not collect everything forever

Observability data costs:

- CPU
    
- Memory
    
- Network bandwidth
    
- Storage
    
- Backup capacity
    
- Administrative time
    

Define retention:

```text
Debug logs: short
Normal application logs: moderate
Security or audit logs: longer where required
High-resolution metrics: moderate
Aggregated historical metrics: longer
Traces: sampled
```

Control:

- Log severity
    
- Trace sampling
    
- Metric cardinality
    
- Retention duration
    
- Compression
    
- Archive policies
    

More data is not automatically more insight.

---

# 71. A practical incident workflow

When users report that the API is unavailable:

## Step 1 — Confirm the symptom

```bash
curl -v \
  https://device-api.local/health
```

## Step 2 — Check external entry

```bash
kubectl get ingress
kubectl describe ingress device-api
```

## Step 3 — Check Service backends

```bash
kubectl get service device-api

kubectl get endpointslices \
  -l kubernetes.io/service-name=device-api
```

## Step 4 — Check Pods

```bash
kubectl get pods \
  -l app.kubernetes.io/name=device-api \
  -o wide
```

## Step 5 — Describe unhealthy Pods

```bash
kubectl describe pod POD_NAME
```

## Step 6 — Read logs

```bash
kubectl logs POD_NAME \
  --timestamps \
  --tail=200
```

```bash
kubectl logs POD_NAME \
  --previous \
  --timestamps
```

## Step 7 — Check recent Events

```bash
kubectl events \
  --types=Warning
```

## Step 8 — Check resources

```bash
kubectl top pods
kubectl top nodes
```

## Step 9 — Check dependencies

```bash
kubectl get pod database-0

kubectl exec database-0 -- \
  pg_isready \
    -U device_app \
    -d device_monitor
```

## Step 10 — Check recent changes

```bash
kubectl rollout history \
  deployment/device-api
```

---

# 72. Troubleshooting by symptom

## Pod is `Pending`

Check:

```bash
kubectl describe pod POD_NAME
```

Likely areas:

- Scheduling
    
- Resource requests
    
- PVC binding
    
- Node selectors
    
- Taints
    
- Image volume configuration
    

## Pod is `ImagePullBackOff`

Check Events.

Likely areas:

- Image reference
    
- Registry credentials
    
- TLS
    
- DNS
    
- Platform compatibility
    

## Pod is `CrashLoopBackOff`

Check current and previous logs.

## Pod is `Running`, but not ready

Check readiness probe and dependencies.

## Pod restarts with `OOMKilled`

Check memory use, memory limits, workload, and leaks.

## Service has no endpoints

Check labels, selectors, and readiness.

## Ingress returns 503

Check Service endpoints, NetworkPolicy, port selection, and readiness.

## CPU is high

Check traffic, loops, retries, expensive requests, worker counts, and limits.

## Memory rises continuously

Investigate leaks, unbounded caches, queues, payloads, and process count.

---

# 73. Monitoring your database

Useful PostgreSQL operational metrics include:

```text
Database availability
Active connections
Maximum connections
Transaction rate
Query duration
Lock waits
Deadlocks
Cache hit ratio
Temporary-file use
Database size
WAL activity
Replication delay
Disk usage
```

Do not expose PostgreSQL administrative credentials broadly to an exporter.

Create a dedicated monitoring account with only required permissions.

Database metrics complement—but do not replace—application-level query timing.

---

# 74. Monitoring Kubernetes objects

You should monitor:

```text
Deployment desired replicas
Deployment ready replicas
Pod restarts
Pod status
StatefulSet ready replicas
Job failures
PVC status
Node readiness
Node pressure
DaemonSet availability
```

An application may be using little CPU while still being unavailable because:

```text
Deployment desired: 3
Ready: 0
```

Object-state metrics provide this control-plane perspective.

---

# 75. Monitoring the MQTT platform

Suggested dashboard sections:

## Fleet overview

```text
Total devices
Online devices
Offline devices
Heartbeat age distribution
Firmware-version distribution
```

## MQTT broker

```text
Connected clients
Connection rate
Disconnect rate
Messages per second
Authentication failures
Dropped messages
```

## Consumer service

```text
Ready replicas
Messages processed
Messages failed
Processing latency
Database-write latency
Duplicate messages
```

## API

```text
Request rate
Error percentage
p50/p95/p99 latency
Ready replicas
CPU and memory
```

## Database

```text
Connections
Transactions
Slow queries
Locks
Disk consumption
```

---

# 76. Suggested alerts for your platform

## Critical

```text
API has zero ready replicas for 2 minutes.
```

```text
PostgreSQL is unreachable for 2 minutes.
```

```text
MQTT broker has no ready instance.
```

```text
No heartbeat messages have been processed for 10 minutes while devices are expected online.
```

## Warning

```text
API error percentage exceeds 5% for 10 minutes.
```

```text
MQTT processing backlog increases continuously for 15 minutes.
```

```text
Database volume exceeds 80%.
```

```text
One API Pod repeatedly restarts.
```

```text
Certificate expires within 14 days.
```

Every alert should link to a troubleshooting runbook.

---

# 77. Day 25 practical laboratory

## Exercise 1 — Application logs

Inspect:

- API logs
    
- Database logs
    
- Init-container logs
    
- Logs from all API replicas
    

Use:

```text
--tail
--since
--timestamps
--follow
```

## Exercise 2 — Previous logs

Create a crashing Pod.

Read:

```bash
kubectl logs --previous
```

Inspect its exit code and restart count.

## Exercise 3 — Events

Create:

- An invalid image
    
- An unschedulable Pod
    
- A failed readiness probe
    

Identify the relevant Events.

## Exercise 4 — OOM termination

Run the memory allocation Pod with a low memory limit.

Confirm:

```text
OOMKilled
```

## Exercise 5 — Metrics Server

Enable Metrics Server.

Use:

```bash
kubectl top nodes
kubectl top pods
kubectl top pod --containers
```

## Exercise 6 — Resource comparison

Compare API:

- CPU request
    
- CPU use
    
- Memory request
    
- Memory use
    
- Memory limit
    

Explain whether the values appear reasonable.

## Exercise 7 — Load generation

Generate repeated API requests.

Watch CPU usage and request logs.

## Exercise 8 — Structured logging

Modify the API to emit structured logs containing:

- Timestamp
    
- Severity
    
- Request ID
    
- Route
    
- Status
    
- Duration
    

## Exercise 9 — Prometheus endpoint

Add `/metrics` to the API.

Expose:

- Request counter
    
- Error counter
    
- Request-duration histogram
    

## Exercise 10 — MQTT metrics design

Define metrics for:

- Messages received
    
- Messages processed
    
- Failures
    
- Processing duration
    
- Broker connection state
    
- Online-device count
    

---

# 78. Day 25 command reference

```bash
# Current logs
kubectl logs POD

# Follow logs
kubectl logs -f POD

# Recent timestamped logs
kubectl logs \
  --since=10m \
  --timestamps \
  POD

# Specific container
kubectl logs \
  POD \
  --container CONTAINER

# Previous container instance
kubectl logs \
  POD \
  --previous

# Logs from selected Pods
kubectl logs \
  -l app=device-api \
  --all-containers=true \
  --prefix=true

# Show Events
kubectl events

# Warning Events
kubectl events \
  --types=Warning

# Events for one Pod
kubectl events \
  --for pod/POD

# Describe object
kubectl describe pod POD
kubectl describe deployment DEPLOYMENT
kubectl describe node NODE

# Resource metrics
kubectl top nodes
kubectl top pods
kubectl top pod POD --containers

# Restart counts
kubectl get pods \
  -o custom-columns='NAME:.metadata.name,RESTARTS:.status.containerStatuses[*].restartCount'

# Deployment history
kubectl rollout history \
  deployment/DEPLOYMENT

# Debug container
kubectl debug \
  -it \
  POD \
  --image=nicolaka/netshoot \
  --target=CONTAINER

# Minikube system logs
minikube logs
```

---

# 79. Knowledge check

## Where should containerized applications normally write logs?

To standard output and standard error.

## How do you read logs from a previous container restart?

```bash
kubectl logs --previous POD
```

## What is the difference between Events and logs?

Events describe Kubernetes actions and object-state changes; logs describe application or component behavior.

## What does `CrashLoopBackOff` mean?

The container repeatedly starts and exits, and Kubernetes is delaying repeated restart attempts.

## What does `OOMKilled` mean?

A process was terminated due to an out-of-memory condition.

## What does Metrics Server provide?

Recent node and Pod CPU and memory metrics through the resource metrics API.

## What command uses Metrics Server?

```bash
kubectl top
```

## Is Metrics Server a long-term monitoring database?

No.

## What is Prometheus?

A monitoring and alerting system that stores labeled time-series data. ([prometheus.io](https://prometheus.io/docs/introduction/overview/?utm_source=chatgpt.com "Overview | Prometheus"))

## What is a counter?

A metric that normally increases, such as total requests.

## What is a gauge?

A metric that can increase and decrease, such as current connections.

## What is a histogram?

A metric that records observations in buckets, useful for duration and size distributions.

## Why are high-cardinality labels dangerous?

They create very large numbers of unique time series, consuming memory and storage.

## What is Grafana commonly used for?

Querying and visualizing monitoring data through dashboards.

## What is Alertmanager?

The component that groups, deduplicates, silences, routes, and notifies on alerts produced by Prometheus. ([prometheus.io](https://prometheus.io/docs/alerting/latest/alertmanager/?utm_source=chatgpt.com "Alertmanager"))

## Are health probes a replacement for monitoring?

No. Probes make local lifecycle decisions; monitoring measures service behavior and trends.

---

# 80. Day 25 completion challenge

Complete this independently:

1. List every API Pod.
    
2. Read logs from one Pod.
    
3. Add timestamps.
    
4. Show only the last 20 lines.
    
5. Show logs from the last five minutes.
    
6. Follow live logs.
    
7. Read init-container logs.
    
8. Read logs from all API replicas.
    
9. Add Pod prefixes to aggregated logs.
    
10. Create a crashing Pod.
    
11. Observe `CrashLoopBackOff`.
    
12. Read previous container logs.
    
13. Identify the exit code.
    
14. Identify the restart count.
    
15. Inspect warning Events.
    
16. Create an invalid-image Pod.
    
17. Diagnose `ImagePullBackOff`.
    
18. Create an unschedulable Pod.
    
19. Diagnose the scheduling failure.
    
20. Create a memory-limited Pod.
    
21. Confirm `OOMKilled`.
    
22. Inspect node conditions.
    
23. Inspect Pod conditions.
    
24. Enable Metrics Server.
    
25. Run `kubectl top nodes`.
    
26. Run `kubectl top pods`.
    
27. View per-container usage.
    
28. Sort Pods by CPU.
    
29. Sort Pods by memory.
    
30. Compare API use with its requests.
    
31. Compare API use with its limits.
    
32. Generate API load.
    
33. Observe CPU changes.
    
34. Observe request logs.
    
35. Add structured API logging.
    
36. Add request IDs.
    
37. Include route, status, and duration.
    
38. Ensure passwords are not logged.
    
39. Add a `/metrics` endpoint.
    
40. Add an HTTP-request counter.
    
41. Add an error counter.
    
42. Add a duration histogram.
    
43. Avoid raw dynamic URL labels.
    
44. Design MQTT consumer metrics.
    
45. Design PostgreSQL metrics.
    
46. Write four actionable alerts.
    
47. Define one availability SLI.
    
48. Define one latency SLI.
    
49. Define one service-level objective.
    
50. Write a complete incident troubleshooting runbook.
    

The central Day 25 model is:

```text
Application and cluster behavior
          ↓
Logs + Events + Metrics + Traces
          ↓
Collection and storage
          ↓
Queries and dashboards
          ↓
Alerts
          ↓
Human investigation and remediation
```

The most important operational lesson is:

> Never diagnose Kubernetes from one command or one signal. Correlate object state, Events, current and previous logs, health conditions, resource metrics, dependency status, rollout history, and user-visible behavior. Observability is the evidence that turns an unknown failure into a specific, testable diagnosis.