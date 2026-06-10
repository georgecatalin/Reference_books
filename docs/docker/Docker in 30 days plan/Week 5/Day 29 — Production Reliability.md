####  Autoscaling, High Availability, Disruption Control, Backups, and Disaster Recovery

Until now, you have learned how to:

```text
Build container images
Deploy applications
Use Kubernetes
Secure workloads
Package applications with Helm
Automate releases with CI/CD and GitOps
```

Today’s question is different:

> What happens when production infrastructure fails, traffic suddenly increases, a node is maintained, storage becomes unavailable, or an entire cluster is lost?

A production platform must survive more than application crashes.

The central Day 29 lesson is:

> High availability is not one Kubernetes option. It is the combined result of redundant infrastructure, correctly distributed replicas, automated scaling, disruption controls, durable storage, tested backups, dependency resilience, observability, and rehearsed recovery procedures.

Kubernetes production environments normally use multiple control-plane and worker nodes to provide fault tolerance; critical environments require deliberate resilience planning rather than simply installing Kubernetes. ([Kubernetes](https://kubernetes.io/docs/setup/production-environment/?utm_source=chatgpt.com "Production environment"))

---

## 1. Day 29 objectives

By the end of today, you should understand:

- Availability and reliability concepts
    
- Failure domains
    
- Redundancy and fault tolerance
    
- Horizontal Pod Autoscaling
    
- Vertical Pod Autoscaling
    
- Node autoscaling
    
- Scaling based on CPU, memory, and application metrics
    
- Autoscaling stabilization and behavior
    
- Pod topology spread constraints
    
- Pod anti-affinity
    
- PodDisruptionBudgets
    
- Graceful shutdown
    
- Rolling-update availability
    
- Node draining and maintenance
    
- Application priority
    
- Control-plane high availability
    
- Stateful-service reliability
    
- Persistent-volume limitations
    
- Database replication versus Kubernetes replicas
    
- Backup versus snapshot versus replication
    
- etcd backup
    
- Recovery Point Objective
    
- Recovery Time Objective
    
- Disaster-recovery architectures
    
- Failure testing
    
- Capacity and cost planning
    
- A production-readiness checklist
    

---

# 2. Availability is an end-to-end property

Suppose your API has three replicas:

```text
API Pod 1
API Pod 2
API Pod 3
```

That does not automatically make the complete service highly available.

The system may still depend on:

```text
One database
One storage system
One ingress controller
One load balancer
One worker node
One control-plane node
One DNS service
One network link
One data center
```

The actual availability chain is:

```text
Client
  ↓
DNS
  ↓
External load balancer
  ↓
Ingress controller
  ↓
API Service
  ↓
API Pods
  ↓
PostgreSQL
  ↓
Persistent storage
```

If any required component is a single point of failure, the full service may still fail.

---

# 3. Availability terminology

## Availability

The proportion of time during which a service is usable.

Example:

```text
99.9% monthly availability
```

A 30-day month contains approximately 43.2 minutes outside the target availability.

Availability must be defined from the user’s perspective:

```text
Can the client complete the required operation?
```

not merely:

```text
Is at least one Pod in Running phase?
```

## Reliability

The probability that a system performs correctly over a period under expected conditions.

A service can be running but unreliable if it:

- Loses messages
    
- Produces incorrect results
    
- Frequently times out
    
- Duplicates transactions
    
- Corrupts data
    

## Durability

The probability that committed data remains available and uncorrupted.

## Fault tolerance

The ability to continue operating despite one or more component failures.

## Resilience

The ability to tolerate, recover from, and adapt to failures.

---

# 4. Failure domains

A failure domain is a set of components that may fail together.

Examples:

```text
Container
Pod
Worker node
Rack
Power circuit
Availability zone
Storage array
Data center
Cloud region
DNS provider
Identity provider
```

Suppose all three API replicas run on one worker:

```text
worker1
├── API Pod 1
├── API Pod 2
└── API Pod 3
```

A worker failure removes all replicas.

A better arrangement is:

```text
worker1 → API Pod 1
worker2 → API Pod 2
worker3 → API Pod 3
```

For zone resilience:

```text
zone-a → API Pod 1
zone-b → API Pod 2
zone-c → API Pod 3
```

Kubernetes topology-spread constraints let you distribute Pods across failure domains such as nodes, zones, or user-defined topology labels. ([Kubernetes](https://kubernetes.io/docs/concepts/scheduling-eviction/topology-spread-constraints/?utm_source=chatgpt.com "Pod Topology Spread Constraints"))

---

# 5. Redundancy does not automatically provide resilience

Consider:

```text
Three API replicas
```

They may all share:

```text
One incorrect ConfigMap
One invalid image
One broken database
One NetworkPolicy mistake
One software defect
```

Redundancy helps with independent failures such as:

```text
Container crash
Pod removal
Worker failure
```

It does not protect against correlated failures such as:

```text
Faulty release deployed everywhere
Expired shared certificate
Corrupted shared data
Incorrect DNS record
Shared dependency outage
```

You need different controls for different failure types:

|Failure|Useful protection|
|---|---|
|One container crashes|Replica replacement|
|One worker fails|Replica distribution|
|One zone fails|Multi-zone placement|
|Bad application version|Canary and rollback|
|Database corruption|Backup and point-in-time recovery|
|Cluster loss|Off-cluster backups and rebuild automation|
|Credential compromise|Rotation and isolation|
|Traffic spike|Autoscaling and capacity reserve|

---

# 6. Manual horizontal scaling

You already know:

```bash
kubectl scale deployment device-api \
  --replicas=5
```

This changes the number of Pod replicas:

```text
3 Pods
  ↓
5 Pods
```

Horizontal scaling is useful when:

- Work can be distributed
    
- Replicas are interchangeable
    
- Shared state is external
    
- Downstream services can handle added load
    

It does not help when:

- The application has a global lock
    
- One database is already saturated
    
- Every replica processes every MQTT message
    
- A single external dependency limits throughput
    
- The application stores session state locally
    

---

# 7. Horizontal Pod Autoscaler

A HorizontalPodAutoscaler, or HPA, automatically adjusts replica count based on observed metrics.

Conceptually:

```text
Measured demand rises
        ↓
HPA calculates desired replicas
        ↓
Deployment replica count increases
        ↓
Additional Pods begin serving
```

When demand decreases:

```text
Measured demand falls
        ↓
Stabilization period
        ↓
Replica count decreases
```

The HPA supports scalable workload resources such as Deployments and StatefulSets through their scale interface. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/autoscaling/horizontal-pod-autoscale/?utm_source=chatgpt.com "Horizontal Pod Autoscaling"))

---

# 8. HPA prerequisites

For CPU- or memory-based HPA, you commonly need:

```text
Metrics Server
Resource requests
A scalable workload
Multiple-instance-safe application behavior
```

Enable Metrics Server in Minikube:

```bash
minikube addons enable metrics-server
```

Check:

```bash
kubectl get deployment \
  --namespace kube-system \
  metrics-server
```

Verify metrics:

```bash
kubectl top pods \
  --namespace device-monitor
```

Without meaningful resource requests, CPU utilization percentages may not represent what you intend.

---

# 9. CPU utilization calculation

Suppose the API container has:

```yaml
resources:
  requests:
    cpu: 200m
```

Actual usage:

```text
100m
```

Relative CPU utilization is approximately:

```text
100m / 200m = 50%
```

If the HPA target is:

```text
60%
```

that Pod is below the target.

If actual use reaches:

```text
240m
```

then:

```text
240m / 200m = 120%
```

The HPA may increase replicas.

This illustrates why an unrealistic CPU request produces misleading autoscaling behavior.

---

# 10. Create a CPU-based HPA

Create `20-api-hpa.yaml`:

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler

metadata:
  name: device-api
  namespace: device-monitor

spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: device-api

  minReplicas: 2
  maxReplicas: 10

  metrics:
    - type: Resource

      resource:
        name: cpu

        target:
          type: Utilization
          averageUtilization: 60
```

Apply:

```bash
kubectl apply \
  -f 20-api-hpa.yaml
```

Inspect:

```bash
kubectl get hpa \
  --namespace device-monitor
```

Detailed view:

```bash
kubectl describe hpa \
  --namespace device-monitor \
  device-api
```

---

# 11. Understand HPA fields

## `scaleTargetRef`

Identifies the workload:

```yaml
scaleTargetRef:
  apiVersion: apps/v1
  kind: Deployment
  name: device-api
```

## `minReplicas`

Lowest permitted replica count:

```yaml
minReplicas: 2
```

This preserves a minimum availability baseline.

## `maxReplicas`

Highest permitted count:

```yaml
maxReplicas: 10
```

This protects:

- Cluster resources
    
- Downstream dependencies
    
- Cost
    
- Database connection limits
    

## `averageUtilization`

Target average across eligible Pods:

```yaml
averageUtilization: 60
```

---

# 12. Generate load

Create a temporary load generator:

```bash
kubectl run load-generator \
  --namespace device-monitor \
  --image=busybox:1.36 \
  --restart=Never \
  -- \
  sh -c '
    while true; do
      wget -qO- http://device-api/health >/dev/null
    done
  '
```

Watch HPA:

```bash
kubectl get hpa \
  --namespace device-monitor \
  --watch
```

In another terminal:

```bash
kubectl get pods \
  --namespace device-monitor \
  --watch
```

Observe resource use:

```bash
watch -n 2 \
  'kubectl top pods --namespace device-monitor'
```

Remove load:

```bash
kubectl delete pod \
  --namespace device-monitor \
  load-generator
```

Scaling down is intentionally more conservative than instantly removing Pods after a short quiet period.

---

# 13. HPA behavior configuration

A production HPA should control how aggressively it scales.

Example:

```yaml
behavior:
  scaleUp:
    stabilizationWindowSeconds: 0

    policies:
      - type: Percent
        value: 100
        periodSeconds: 60

      - type: Pods
        value: 4
        periodSeconds: 60

    selectPolicy: Max

  scaleDown:
    stabilizationWindowSeconds: 300

    policies:
      - type: Percent
        value: 25
        periodSeconds: 60
```

Meaning:

```text
Scale up:
React quickly and add substantial capacity.

Scale down:
Wait five minutes and remove capacity gradually.
```

This reduces replica oscillation.

---

# 14. Scaling oscillation

Without stabilization:

```text
Traffic increases
→ scale from 2 to 5

Traffic temporarily decreases
→ scale from 5 to 2

Traffic increases again
→ scale back to 5
```

This is sometimes called thrashing or flapping.

Consequences:

- Repeated Pod startup
    
- Cold caches
    
- More image pulls
    
- More database connections
    
- Increased scheduling work
    
- Unstable latency
    
- Unnecessary cost changes
    

Use:

- Stabilization windows
    
- Realistic metrics
    
- Minimum replicas
    
- Controlled scaling policies
    
- Sufficient observation windows
    

---

# 15. CPU is not always the correct scaling metric

CPU-based scaling works well when CPU use correlates with workload demand.

For your MQTT system, better metrics may include:

```text
Message backlog
Messages processed per second
Oldest unprocessed-message age
Queue depth
Active jobs
Database-write latency
```

For an API:

```text
Requests per second
Concurrent requests
Request queue length
p95 latency
```

For a worker:

```text
Pending jobs per replica
```

Example principle:

```text
1000 queued messages
5 consumers
= 200 queued messages per consumer
```

You might scale to maintain:

```text
50 queued messages per consumer
```

This requires a custom or external metrics adapter.

---

# 16. Memory-based HPA

Example:

```yaml
metrics:
  - type: Resource

    resource:
      name: memory

      target:
        type: Utilization
        averageUtilization: 70
```

Memory-based scaling requires caution.

Many applications retain memory:

```text
Cache grows
Heap grows
Memory never quickly falls
```

Scaling out may not solve:

- Memory leak
    
- Per-process unbounded cache
    
- Large static data set
    
- Excessive object retention
    

Memory pressure may require:

- Application correction
    
- Vertical resizing
    
- Cache limits
    
- Different workload architecture
    

---

# 17. Multiple HPA metrics

Example:

```yaml
metrics:
  - type: Resource

    resource:
      name: cpu

      target:
        type: Utilization
        averageUtilization: 60

  - type: Resource

    resource:
      name: memory

      target:
        type: Utilization
        averageUtilization: 70
```

The controller evaluates the metrics and chooses a replica recommendation that satisfies the strongest scaling demand.

A workload may therefore scale because of CPU even when memory is low.

Do not add many metrics without understanding their interactions.

---

# 18. External and custom metrics

HPA v2 supports metric categories including:

```text
Resource
Pods
Object
External
ContainerResource
```

Conceptual external queue metric:

```yaml
metrics:
  - type: External

    external:
      metric:
        name: mqtt_pending_messages

      target:
        type: AverageValue
        averageValue: "100"
```

Meaning:

```text
Aim for approximately 100 pending messages per replica.
```

This requires infrastructure that exposes the metric to the Kubernetes autoscaling API.

Examples include:

- Prometheus adapters
    
- Cloud-provider metric adapters
    
- Event-driven autoscaling components
    

---

# 19. Event-driven autoscaling

Message-processing applications often scale better from event backlog than CPU.

Conceptually:

```text
MQTT or queue backlog = 0
→ minimum consumers

Backlog = 10,000
→ add consumers

Backlog falls
→ gradually remove consumers
```

However, your MQTT consumer design must support several replicas.

You must first solve:

- Shared subscriptions
    
- Duplicate delivery
    
- Idempotency
    
- Ordering
    
- Database concurrency
    
- Broker connection load
    
- Per-device sequencing
    

Autoscaling an incorrectly designed consumer can multiply errors.

---

# 20. HPA and Helm replica conflicts

Your Helm chart may define:

```yaml
api:
  replicaCount: 3
```

The HPA may change the live Deployment to:

```text
7 replicas
```

A later Helm upgrade may reapply:

```text
3 replicas
```

This creates competing controllers.

When HPA is enabled, a common chart pattern is:

```yaml
{{- if not .Values.autoscaling.enabled }}
replicas: {{ .Values.api.replicaCount }}
{{- end }}
```

Values:

```yaml
autoscaling:
  enabled: true
  minReplicas: 2
  maxReplicas: 10
  targetCPUUtilizationPercentage: 60
```

Helm then stops continuously declaring a fixed replica count while autoscaling is active.

---

# 21. Vertical Pod Autoscaling

Vertical scaling changes the resources assigned to each Pod rather than changing Pod count.

```text
Before:
3 Pods × 250m CPU

After:
3 Pods × 500m CPU
```

Vertical Pod Autoscaling, or VPA, can recommend or apply resource-request adjustments based on observed resource usage. Vertical scaling means assigning more or fewer resources to existing workload Pods rather than adding replicas. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/autoscaling/vertical-pod-autoscale/?utm_source=chatgpt.com "Vertical Pod Autoscaling"))

VPA is not necessarily installed by default.

Its components normally include:

- Recommender
    
- Updater
    
- Admission controller
    

---

# 22. VPA modes

Conceptual VPA modes include:

## Off

```text
Generate recommendations only.
```

Useful for rightsizing analysis.

## Initial

```text
Apply recommendations when Pods are created.
```

## Auto or Recreate

```text
Evict and recreate Pods to apply recommendations.
```

Exact supported settings depend on the VPA version installed.

Begin with recommendation-only mode in production.

Automatically evicting stateful or latency-sensitive workloads requires careful testing.

---

# 23. HPA and VPA interaction

Do not let HPA and VPA independently modify the same CPU or memory signal without understanding the control loops.

Example conflict:

```text
High CPU
  ↓
HPA adds Pods

VPA also increases CPU requests
  ↓
Relative utilization percentage decreases

HPA removes Pods
```

A common approach is:

```text
HPA scales on CPU
VPA recommends memory only
```

or:

```text
HPA scales on application backlog
VPA adjusts CPU and memory requests
```

Test combinations carefully.

---

# 24. Node autoscaling

HPA can create more Pod replicas, but the cluster may lack capacity.

Then Pods remain:

```text
Pending
```

The flow becomes:

```text
Traffic rises
    ↓
HPA requests more replicas
    ↓
No node has enough capacity
    ↓
New Pods Pending
```

A node autoscaler can provision or consolidate worker nodes according to demand and infrastructure capabilities. ([Kubernetes](https://kubernetes.io/docs/concepts/cluster-administration/node-autoscaling/?utm_source=chatgpt.com "Node Autoscaling"))

Conceptually:

```text
Unschedulable Pods
      ↓
Node autoscaler adds node
      ↓
Node becomes Ready
      ↓
Scheduler places Pods
```

---

# 25. HPA versus node autoscaling

They operate at different levels:

```text
HPA
→ changes Pod count

Node autoscaler
→ changes worker-node count
```

Typical sequence:

```text
1. Traffic increases.
2. HPA requests more Pods.
3. Some Pods cannot be scheduled.
4. Node autoscaler adds capacity.
5. Pending Pods become scheduled.
```

Scaling latency includes:

- HPA observation
    
- Pod scheduling
    
- Node provisioning
    
- OS startup
    
- kubelet registration
    
- Image download
    
- Application startup
    
- Readiness checks
    

Therefore, keep some spare capacity for workloads that cannot wait several minutes.

---

# 26. Autoscaling cannot create infinite downstream capacity

Suppose every API Pod opens:

```text
20 database connections
```

At 3 replicas:

```text
60 connections
```

At 20 replicas:

```text
400 connections
```

If PostgreSQL supports only:

```text
200 connections
```

autoscaling creates an outage.

Before increasing `maxReplicas`, calculate:

```text
Maximum replicas
×
Connections per replica
≤
Safe database connection capacity
```

Also consider:

- MQTT broker connections
    
- External API quotas
    
- License limits
    
- Storage IOPS
    
- Network bandwidth
    
- Memory on shared caches
    

---

# 27. Graceful shutdown

When Kubernetes terminates a Pod, the application should stop accepting new work and finish or safely abandon active work.

Conceptual sequence:

```text
Pod selected for termination
      ↓
Readiness becomes false or endpoint is removed
      ↓
SIGTERM sent
      ↓
Application stops accepting new work
      ↓
Active work completes
      ↓
Process exits
```

If the application does not exit before the grace period, Kubernetes may force termination.

Configure:

```yaml
spec:
  terminationGracePeriodSeconds: 30
```

Your application must handle `SIGTERM`.

---

# 28. Graceful API shutdown

An API should:

1. Receive `SIGTERM`.
    
2. Mark itself unready.
    
3. Stop accepting new requests.
    
4. Complete active requests where practical.
    
5. Close database connections.
    
6. Flush logs and telemetry.
    
7. Exit before the grace period.
    

For long requests, choose a grace period longer than normal request duration.

Do not choose:

```text
terminationGracePeriodSeconds: 2
```

when requests may legitimately take 20 seconds.

---

# 29. Graceful MQTT consumer shutdown

An MQTT consumer should:

1. Stop accepting or subscribing to new work.
    
2. Finish current message processing.
    
3. Commit database changes.
    
4. Acknowledge the message according to QoS design.
    
5. Disconnect cleanly.
    
6. Exit.
    

A dangerous sequence is:

```text
Message acknowledged
      ↓
Pod terminated
      ↓
Database write never completed
```

A safer sequence depends on your broker and delivery semantics:

```text
Process durably
      ↓
Acknowledge
```

Design idempotency because termination may occur at any point.

---

# 30. `preStop` hook

A lifecycle hook can run before container termination:

```yaml
lifecycle:
  preStop:
    exec:
      command:
        - sh
        - -c
        - sleep 5
```

This is sometimes used to allow endpoint updates to propagate before process shutdown.

However:

- It consumes the termination grace period.
    
- It should not replace proper SIGTERM handling.
    
- Shell may not exist in minimal images.
    
- Fixed sleeps are approximate.
    

Prefer application-aware graceful shutdown.

---

# 31. Rolling-update availability

Deployment strategy:

```yaml
strategy:
  type: RollingUpdate

  rollingUpdate:
    maxUnavailable: 0
    maxSurge: 1
```

For three replicas:

```text
At least 3 should remain available.
At most 1 temporary extra Pod may be created.
```

This can reduce rollout interruption, but requires spare capacity.

Alternative:

```yaml
maxUnavailable: 1
maxSurge: 1
```

This permits one unavailable replica during the rollout.

Readiness probes determine when new Pods become eligible for traffic.

---

# 32. `minReadySeconds`

Add:

```yaml
spec:
  minReadySeconds: 20
```

Meaning:

```text
A new Pod must remain ready for 20 seconds
before being considered available.
```

This catches applications that:

```text
Start
Become ready
Crash after 5 seconds
```

It is not a complete soak test, but it improves rollout confidence.

---

# 33. Progress deadlines

Add:

```yaml
spec:
  progressDeadlineSeconds: 600
```

If the Deployment stops progressing for too long, it reports a failed progress condition.

Check:

```bash
kubectl rollout status \
  deployment/device-api \
  --namespace device-monitor \
  --timeout=10m
```

The progress deadline reports stalled rollout status.

It does not independently restore the previous application version.

Your CI/CD or GitOps process must react.

---

# 34. Voluntary and involuntary disruptions

## Involuntary disruption

Examples:

```text
Worker hardware failure
Kernel crash
Network partition
Power failure
Node lost
```

## Voluntary disruption

Examples:

```text
Node drain
Cluster upgrade
Node replacement
Autoscaler consolidation
Administrator eviction
```

PodDisruptionBudgets mainly influence certain voluntary evictions.

They do not prevent every form of deletion or failure. For example, deleting a Deployment or directly deleting its Pods can bypass the intended PDB protection. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/pods/disruptions/?utm_source=chatgpt.com "Disruptions"))

---

# 35. PodDisruptionBudget

A PodDisruptionBudget, or PDB, limits how many selected replicas can be voluntarily disrupted simultaneously.

Example:

```yaml
apiVersion: policy/v1
kind: PodDisruptionBudget

metadata:
  name: device-api
  namespace: device-monitor

spec:
  minAvailable: 2

  selector:
    matchLabels:
      app.kubernetes.io/name: device-api
      app.kubernetes.io/component: api
```

If three replicas are healthy:

```text
Minimum available: 2
Maximum voluntary disruption at once: 1
```

PDBs help preserve application availability during operations such as node maintenance. ([Kubernetes](https://kubernetes.io/docs/tasks/run-application/configure-pdb/?utm_source=chatgpt.com "Specifying a Disruption Budget for your Application"))

---

# 36. `minAvailable` versus `maxUnavailable`

Use one of:

```yaml
minAvailable: 2
```

or:

```yaml
maxUnavailable: 1
```

Percentages are also possible:

```yaml
minAvailable: 75%
```

For small replica counts, integers are usually easier to reason about.

For three API replicas:

```text
minAvailable: 2
```

is clear.

For a single database Pod:

```text
minAvailable: 1
```

may block voluntary eviction completely because no second replica exists.

That does not make the database highly available.

---

# 37. PDB limitations

A PDB does not:

- Restore failed Pods
    
- Replicate data
    
- Prevent node crashes
    
- Protect against application bugs
    
- Stop direct Deployment deletion
    
- Guarantee capacity on other nodes
    
- Make a single-replica database available during maintenance
    

A too-strict PDB can block:

- Node draining
    
- Cluster upgrade
    
- Node autoscaler consolidation
    

For example:

```text
2 API replicas
PDB minAvailable: 2
```

No voluntary disruption is permitted.

That may be correct temporarily, but it requires enough spare nodes and careful maintenance planning.

---

# 38. Test a PDB

Apply:

```bash
kubectl apply \
  -f 21-api-pdb.yaml
```

Inspect:

```bash
kubectl get pdb \
  --namespace device-monitor
```

Detailed:

```bash
kubectl describe pdb \
  --namespace device-monitor \
  device-api
```

You may see:

```text
Allowed disruptions: 1
Current: 3
Desired: 2
Total: 3
```

When one Pod becomes unavailable:

```text
Allowed disruptions: 0
```

---

# 39. Draining a node

Before planned maintenance:

```bash
kubectl cordon worker1
```

This marks the node unschedulable for new ordinary Pods.

Then:

```bash
kubectl drain worker1 \
  --ignore-daemonsets \
  --delete-emptydir-data
```

The drain operation attempts to evict managed Pods.

PDBs may block unsafe eviction.

After maintenance:

```bash
kubectl uncordon worker1
```

Do not drain a production node before checking:

- Available capacity elsewhere
    
- PDBs
    
- Local storage
    
- Stateful workloads
    
- DaemonSets
    
- Critical system Pods
    
- Maintenance window
    

---

# 40. `emptyDir` during drain

This option:

```text
--delete-emptydir-data
```

acknowledges that data stored in `emptyDir` will be lost when the Pod moves.

Examples of acceptable `emptyDir` content:

```text
Temporary files
Cache
Scratch processing data
Generated runtime files
```

Do not place unrecoverable business data in `emptyDir`.

---

# 41. Distribute replicas across nodes

A simple anti-affinity rule:

```yaml
affinity:
  podAntiAffinity:
    preferredDuringSchedulingIgnoredDuringExecution:
      - weight: 100

        podAffinityTerm:
          topologyKey: kubernetes.io/hostname

          labelSelector:
            matchLabels:
              app.kubernetes.io/name: device-api
              app.kubernetes.io/component: api
```

Meaning:

```text
Prefer not to place matching API Pods on the same node.
```

Because this is preferred rather than required, scheduling can still proceed in a small cluster.

---

# 42. Required anti-affinity

Stricter configuration:

```yaml
affinity:
  podAntiAffinity:
    requiredDuringSchedulingIgnoredDuringExecution:
      - topologyKey: kubernetes.io/hostname

        labelSelector:
          matchLabels:
            app.kubernetes.io/name: device-api
            app.kubernetes.io/component: api
```

Meaning:

```text
Never place two matching API Pods on the same node.
```

With three replicas and two workers, one Pod remains Pending.

Use required anti-affinity only when the cluster has enough failure domains and spare capacity.

---

# 43. Topology-spread constraints

A more flexible approach:

```yaml
topologySpreadConstraints:
  - maxSkew: 1

    topologyKey: kubernetes.io/hostname

    whenUnsatisfiable: ScheduleAnyway

    labelSelector:
      matchLabels:
        app.kubernetes.io/name: device-api
        app.kubernetes.io/component: api
```

Meaning:

```text
Try to keep replica counts across nodes within a difference of one.
```

For zone distribution:

```yaml
topologyKey: topology.kubernetes.io/zone
```

Topology-spread constraints can improve both availability and resource distribution across defined topology domains. ([Kubernetes](https://kubernetes.io/docs/concepts/scheduling-eviction/topology-spread-constraints/?utm_source=chatgpt.com "Pod Topology Spread Constraints"))

---

# 44. Example multi-level spreading

```yaml
topologySpreadConstraints:
  - maxSkew: 1

    topologyKey: topology.kubernetes.io/zone

    whenUnsatisfiable: DoNotSchedule

    labelSelector:
      matchLabels:
        app.kubernetes.io/name: device-api

  - maxSkew: 1

    topologyKey: kubernetes.io/hostname

    whenUnsatisfiable: ScheduleAnyway

    labelSelector:
      matchLabels:
        app.kubernetes.io/name: device-api
```

Intent:

```text
Require zone distribution.
Prefer node distribution inside zones.
```

Before using it, verify node labels:

```bash
kubectl get nodes \
  --show-labels
```

If the topology labels are absent, the rule cannot work as intended.

---

# 45. Placement constraints versus availability

Suppose the API requires:

```yaml
nodeSelector:
  special-hardware: "true"
```

but only one worker has that label.

Then every API replica may be scheduled on that worker.

Your constraint creates a single failure domain.

Availability planning should ask:

```text
How many eligible nodes satisfy every:
- node selector
- affinity rule
- taint and toleration
- resource requirement
- storage requirement?
```

A Deployment with three replicas needs at least three suitable placement opportunities for node-level resilience.

---

# 46. Taints and tolerations

A taint prevents ordinary workloads from being scheduled on a node unless they tolerate it.

Conceptual taint:

```text
dedicated=database:NoSchedule
```

A PostgreSQL Pod may tolerate it:

```yaml
tolerations:
  - key: dedicated
    operator: Equal
    value: database
    effect: NoSchedule
```

Then add node affinity so the database actively targets that node pool.

A toleration only permits placement.

It does not require placement on the tainted nodes by itself.

---

# 47. Dedicated node pools

Possible node pools:

```text
general workloads
database workloads
high-memory workloads
GPU workloads
system workloads
```

Benefits:

- Capacity isolation
    
- Hardware specialization
    
- Reduced noisy-neighbor effects
    
- Easier cost tracking
    
- Different upgrade schedules
    

Costs:

- Less flexible capacity
    
- Potential underutilization
    
- More operational complexity
    
- Extra failure-domain planning
    

For your platform, PostgreSQL may be better managed externally rather than simply placed on a special Kubernetes worker.

---

# 48. Priority classes

Kubernetes can assign relative scheduling and eviction priority.

Conceptual priorities:

```text
Cluster DNS
→ very high

Ingress
→ high

Device API
→ medium-high

Batch report
→ low
```

When capacity is insufficient, a higher-priority Pod may displace lower-priority workloads where preemption applies.

Do not assign high priority to every application.

If everything is critical, priority cannot distinguish what should survive.

Priority also does not create additional capacity.

---

# 49. Control-plane high availability

A production control plane should not depend on one machine.

Typical HA control-plane architecture:

```text
External load balancer
        ↓
control-plane-1
control-plane-2
control-plane-3
```

The control-plane nodes commonly run:

```text
API server
Controller manager
Scheduler
etcd, depending on topology
```

Kubernetes documentation describes stacked control-plane/etcd and external-etcd designs as the two common kubeadm HA topologies. ([Kubernetes](https://kubernetes.io/docs/setup/production-environment/tools/kubeadm/high-availability/?utm_source=chatgpt.com "Creating Highly Available Clusters with kubeadm"))

---

# 50. Why use an odd number of etcd members?

etcd uses consensus.

Common member counts:

```text
1
3
5
```

A three-member cluster can normally tolerate one unavailable member.

A five-member cluster can normally tolerate two.

Adding a fourth member does not provide the same quorum improvement as moving from three to five.

Quorum planning must consider:

- Failure domains
    
- Network latency
    
- Disk latency
    
- Backup
    
- Member replacement procedures
    

---

# 51. Stacked versus external etcd

## Stacked topology

```text
control-plane-1
├── API components
└── etcd member

control-plane-2
├── API components
└── etcd member

control-plane-3
├── API components
└── etcd member
```

Advantages:

- Less infrastructure
    
- Simpler to start
    

Disadvantage:

- Control-plane and etcd failures share hosts
    

## External etcd

```text
Control-plane nodes
separate from
etcd nodes
```

Advantages:

- Failure-domain separation
    
- Independent scaling and maintenance
    

Disadvantages:

- More infrastructure
    
- More operational complexity
    

---

# 52. Managed versus self-managed Kubernetes

## Managed control plane

Cloud or platform provider operates:

- API servers
    
- etcd
    
- control-plane upgrades
    
- some backup and HA mechanisms
    

You still manage:

- Worker capacity
    
- Applications
    
- Storage
    
- IAM
    
- Networking
    
- Backups for application data
    
- Workload upgrades
    

## Self-managed cluster

Your team operates:

- Control plane
    
- etcd
    
- certificates
    
- upgrades
    
- backup
    
- networking
    
- worker nodes
    
- recovery
    

Use self-managed Kubernetes only when the team has the operational capacity to manage it.

---

# 53. Stateful services need application-level high availability

This is not sufficient:

```yaml
kind: StatefulSet
spec:
  replicas: 3
```

for ordinary PostgreSQL.

It creates three PostgreSQL Pods, but does not automatically configure:

- Primary and replicas
    
- Streaming replication
    
- Leader election
    
- Failover
    
- Fencing
    
- Backup
    
- Restore
    
- Connection routing
    
- Split-brain protection
    

StatefulSets provide stable Pod identities and storage relationships; they do not implement database-specific replication semantics. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/controllers/statefulset/?utm_source=chatgpt.com "StatefulSets"))

---

# 54. PostgreSQL reliability options

Possible strategies include:

## External managed database

Provider handles parts of:

- Replication
    
- Failover
    
- Backup
    
- Maintenance
    
- Monitoring
    

## PostgreSQL operator

A Kubernetes operator manages:

- Cluster membership
    
- Replication
    
- Failover
    
- Backup configuration
    
- Service routing
    

You must still understand and test its behavior.

## Self-managed PostgreSQL cluster

Your team configures:

- Replication
    
- Failover manager
    
- Connection proxy
    
- Backup and recovery
    
- Monitoring
    
- Upgrades
    

This requires significant database expertise.

For a small internal application, an external or managed PostgreSQL service may be operationally safer.

---

# 55. Replication is not backup

Replication copies changes to another live instance.

If someone runs:

```sql
DROP TABLE devices;
```

replication may copy the deletion immediately.

If the database is corrupted logically, replicas may receive the same corruption.

Replication protects primarily against:

```text
Instance failure
Storage failure
Node failure
```

Backups protect against:

```text
Accidental deletion
Logical corruption
Ransomware
Bad migration
Historical recovery
Complete cluster loss
```

You often need both.

---

# 56. Snapshot is not necessarily an application-consistent backup

A volume snapshot captures storage at a point in time.

Kubernetes VolumeSnapshot provides a standardized API for copying a volume’s contents at a particular point in time when the CSI driver supports it. ([Kubernetes](https://kubernetes.io/docs/concepts/storage/volume-snapshots/?utm_source=chatgpt.com "Volume Snapshots"))

But a snapshot may be:

```text
Crash-consistent
```

rather than:

```text
Application-consistent
```

For a database, coordinate with:

- Database flush/checkpoint
    
- Backup mode
    
- WAL retention
    
- Database-native backup tooling
    
- Quiescing where appropriate
    

A storage snapshot alone may not support the recovery behavior you expect.

---

# 57. Logical versus physical database backups

## Logical backup

Example:

```bash
pg_dump
```

Contains logical database objects and data.

Advantages:

- Portable
    
- Selective restore
    
- Useful for schema inspection
    
- Can restore into another compatible server
    

Disadvantages:

- Slower for large databases
    
- Restore may take significant time
    
- Does not include every cluster-level object automatically
    

## Physical backup

Copies database files and required transaction logs in a database-consistent manner.

Advantages:

- Faster large-database recovery
    
- Supports point-in-time recovery with WAL
    

Disadvantages:

- Version and architecture considerations
    
- More operational complexity
    
- Requires correct recovery procedure
    

---

# 58. Recovery Point Objective

Recovery Point Objective, or RPO, answers:

```text
How much recent data may be lost?
```

Examples:

```text
RPO = 24 hours
→ up to one day of data may be lost

RPO = 15 minutes
→ up to 15 minutes

RPO = near zero
→ continuous replication or log shipping required
```

Backup frequency alone does not guarantee RPO.

You must also verify:

- Backup completion
    
- Backup integrity
    
- Off-site transfer
    
- Transaction-log availability
    
- Restore process
    

---

# 59. Recovery Time Objective

Recovery Time Objective, or RTO, answers:

```text
How long may service restoration take?
```

Examples:

```text
RTO = 8 hours
→ manual rebuild may be acceptable

RTO = 30 minutes
→ substantial automation and standby capacity required

RTO = 5 minutes
→ active or hot-standby architecture likely required
```

RTO must include:

```text
Incident detection
Decision time
Infrastructure provisioning
Data restoration
Application deployment
DNS or traffic switch
Verification
```

---

# 60. Define service-specific RPO and RTO

Example:

|Component|RPO|RTO|
|---|--:|--:|
|Device API stateless code|0 via Git/registry|30 min|
|PostgreSQL operational data|15 min|1 hour|
|MQTT retained messages|15 min|30 min|
|Monitoring history|1 hour|4 hours|
|Application logs|1 hour|8 hours|
|Cluster configuration|15 min|1 hour|

These are examples, not universal recommendations.

Business impact determines acceptable targets.

---

# 61. What must be backed up?

A complete platform backup plan may include:

```text
Application databases
Persistent-volume data
Object storage
Kubernetes desired-state repository
External Secrets
TLS and signing-key recovery procedures
Helm chart packages
Container images
Infrastructure-as-code
etcd, for self-managed clusters
DNS configuration
External load-balancer configuration
Monitoring configuration
Runbooks
```

Do not assume backing up the Kubernetes API objects also backs up application data.

---

# 62. etcd backup

etcd stores Kubernetes API state, including critical cluster objects.

Kubernetes documentation recommends periodically backing up etcd for recovery from scenarios such as loss of all control-plane nodes. It also warns that etcd snapshots contain sensitive cluster information and should be encrypted and protected. ([Kubernetes](https://kubernetes.io/docs/tasks/administer-cluster/configure-upgrade-etcd/?utm_source=chatgpt.com "Operating etcd clusters for Kubernetes"))

An etcd backup can contain:

- Deployments
    
- Services
    
- Secrets
    
- ConfigMaps
    
- RBAC
    
- Custom resources
    
- Cluster state
    

It does not automatically contain:

- Persistent-volume contents
    
- External database contents
    
- Container registry images
    
- External DNS state
    

---

# 63. Protect etcd backups

Because etcd may contain Secret values and credentials:

- Encrypt backup files
    
- Restrict access
    
- Store copies outside the cluster
    
- Rotate access credentials
    
- Monitor backup jobs
    
- Define retention
    
- Test restore
    
- Keep recovery tools available
    
- Protect encryption configuration and keys
    

A backup encrypted with a lost key is not recoverable.

A backup stored only on the failed control-plane disk is not useful.

---

# 64. Kubernetes object backup

If your desired state is fully maintained in Git:

```text
Deployments
Services
Ingresses
NetworkPolicies
RBAC
Helm values
```

can often be recreated from Git.

However, Git may not contain:

- Dynamically generated Secrets
    
- Runtime-created PVCs
    
- Operator-managed custom resources
    
- Certificate state
    
- External controller state
    

Inventory what is declarative and what is runtime-generated.

---

# 65. Off-cluster backup storage

Backups should not share every failure domain with the primary system.

Bad:

```text
Production database
and
only backup
on the same Kubernetes volume
```

Better:

```text
Production database in cluster A
Backups in independent object storage
Replicated to another region or site
```

Consider protection against:

- Cluster deletion
    
- Storage account compromise
    
- Region outage
    
- Ransomware
    
- Administrative error
    
- Retention-policy mistake
    

Where needed, use immutable or write-once retention controls.

---

# 66. The most important backup test

A backup job reporting:

```text
Succeeded
```

does not prove restorability.

The actual test is:

```text
Restore into isolated environment
      ↓
Start application
      ↓
Run integrity checks
      ↓
Verify expected data
      ↓
Measure restore time
```

You should know:

- Who initiates restoration
    
- Which credentials are required
    
- Where backups are stored
    
- How encryption keys are obtained
    
- How DNS is switched
    
- How correctness is validated
    

---

# 67. Create a backup verification environment

Example process:

```text
1. Select a recent backup.
2. Create isolated namespace or cluster.
3. Provision empty PostgreSQL.
4. Restore backup.
5. Deploy API using a test hostname.
6. Run database integrity queries.
7. Run application smoke tests.
8. Confirm data freshness.
9. Record actual RPO and RTO.
10. Delete the isolated environment.
```

Automate this periodically.

Do not wait for a real disaster to discover that your restore instructions are incomplete.

---

# 68. Disaster-recovery patterns

## Backup and restore

```text
Primary site fails
      ↓
Provision replacement
      ↓
Restore backup
      ↓
Deploy applications
```

Lowest cost, typically longest RTO.

## Pilot light

```text
Minimal infrastructure exists in secondary site
Data continuously copied
Applications scaled up during disaster
```

## Warm standby

```text
Secondary environment running at reduced capacity
Data replicated
Scale and switch traffic during disaster
```

## Active-passive

```text
Primary handles traffic
Fully prepared secondary waits
Traffic switches on failure
```

## Active-active

```text
Multiple sites actively serve traffic
```

Highest complexity.

Requires distributed data and conflict-resolution design.

---

# 69. Multi-cluster design

Kubernetes cluster administration guidance treats different clusters as separate administrative and failure boundaries; a multi-cluster strategy is generally used rather than expecting one Kubernetes cluster to span unrelated hybrid environments transparently. ([Kubernetes](https://kubernetes.io/docs/concepts/cluster-administration/?utm_source=chatgpt.com "Cluster Administration"))

Possible architecture:

```text
Cluster A — primary
Cluster B — disaster recovery
```

Shared external systems may include:

```text
Container registry
Git repository
Backup storage
DNS
Identity provider
Monitoring
```

Be careful: a shared service can become a multi-cluster single point of failure.

---

# 70. Multi-zone versus multi-region

## Multi-zone cluster

```text
One region
Several availability zones
```

Protects against:

- Worker failure
    
- Rack failure
    
- Zone failure
    

Lower latency between components.

## Multi-region deployment

```text
Region A
Region B
```

Protects against broader regional failure.

Introduces:

- Higher replication latency
    
- Data-consistency challenges
    
- Traffic-routing complexity
    
- Increased cost
    
- More difficult testing
    

Kubernetes availability guidance recommends distributing critical control-plane components across at least three failure zones when strong zone-level availability is required. ([Kubernetes](https://kubernetes.io/docs/setup/best-practices/multiple-zones/?utm_source=chatgpt.com "Running in multiple zones"))

---

# 71. Stateless recovery

Stateless applications should be reconstructable from:

```text
Git repository
Container registry
Helm chart
Environment configuration
External Secrets
Infrastructure automation
```

Recovery process:

```text
Create cluster
      ↓
Install controllers
      ↓
Restore Secrets references
      ↓
Deploy Helm release
      ↓
Route traffic
```

The application Pods themselves do not need backup.

The artifact and configuration sources do.

---

# 72. Stateful recovery

A stateful recovery may require:

```text
Provision compatible storage
Restore database backup
Replay transaction logs
Recover credentials
Validate database
Deploy application
Reconnect dependent services
Verify data correctness
```

You must document:

- Required database version
    
- Extension versions
    
- Character encoding
    
- Time zone
    
- Roles and permissions
    
- Connection settings
    
- Backup format
    
- Encryption keys
    
- Point-in-time recovery target
    

---

# 73. Container registry resilience

If a cluster must recover but the registry is unavailable, workloads may not pull their images.

Protect:

- Application images
    
- Helm charts
    
- Base-image references where relevant
    
- Signing metadata
    
- SBOMs
    
- Provenance
    

Possible controls:

- Registry replication
    
- Retention policies
    
- Backup
    
- Multi-region registry
    
- Prevent automatic deletion of released artifacts
    
- Digest-based release records
    

Do not depend only on worker-node image caches.

---

# 74. Capacity planning

Autoscaling does not eliminate capacity planning.

You must understand:

```text
Normal load
Peak load
Growth rate
Failure capacity
Deployment surge
Maintenance capacity
Recovery capacity
```

Suppose normal usage requires:

```text
6 CPU cores
12 GiB memory
```

To tolerate loss of one of three equal nodes, the remaining two nodes must support the critical workloads.

Also reserve room for:

```text
Rolling update maxSurge
HPA scale-up
System DaemonSets
Monitoring
Node failure
```

---

# 75. N+1 capacity

N+1 means the platform has one additional unit beyond normal requirements.

Example:

```text
Normal demand needs 2 worker nodes.
Operate with 3.
```

If one fails:

```text
2 remain
→ normal demand still fits
```

For zone resilience, capacity must survive the loss of the largest zone.

This can be more expensive but reduces recovery time.

---

# 76. Requests determine scheduler capacity

Kubernetes scheduling primarily considers resource requests, not average observed usage.

Suppose a node has:

```text
4 CPU allocatable
```

Four Pods each request:

```text
1 CPU
```

The node is considered fully requested even if actual use is only:

```text
0.2 CPU each
```

Oversized requests waste schedulable capacity.

Undersized requests create overcommit and possible contention.

Use Day 25 metrics to rightsize.

---

# 77. CPU overcommit versus memory overcommit

CPU is compressible:

```text
High demand
→ CPU throttling or slower execution
```

Memory is not similarly compressible:

```text
Memory exhausted
→ process termination or eviction
```

Therefore:

- CPU limits may cause latency.
    
- Memory limits may cause `OOMKilled`.
    
- Node memory pressure may trigger eviction.
    
- Memory requests need careful sizing.
    

Keep critical services away from sustained node memory pressure.

---

# 78. Cost-aware scaling

More replicas do not always mean better performance.

Each replica may add:

- Database connections
    
- Cache memory
    
- Broker connections
    
- Logging volume
    
- Metrics cardinality
    
- License cost
    
- Storage traffic
    

Set:

```yaml
maxReplicas:
```

using measured downstream capacity and business requirements.

Use scheduled scaling when load is highly predictable:

```text
Increase capacity before work shift starts
Decrease after shift ends
```

This can react faster than waiting for CPU metrics.

---

# 79. Application failure testing

Test expected failures deliberately in a non-production environment.

Examples:

```text
Delete one API Pod
Stop one worker
Drain one worker
Block PostgreSQL network access
Restart Ingress
Expire a test certificate
Fill a test volume
Deploy a broken image
Disable DNS temporarily
Introduce API latency
Stop the MQTT broker
```

For each test, record:

- Expected behavior
    
- Actual user impact
    
- Detection time
    
- Alert behavior
    
- Recovery time
    
- Data correctness
    
- Manual steps required
    

---

# 80. Pod failure test

Delete one API Pod:

```bash
kubectl delete pod \
  --namespace device-monitor \
  API_POD
```

Observe:

```bash
kubectl get pods \
  --namespace device-monitor \
  --watch
```

Verify external access continues:

```bash
while true; do
  curl --fail --silent \
    https://device-api.local/health \
    >/dev/null \
    && echo success \
    || echo failure

  sleep 1
done
```

Expected:

```text
No or minimal user-visible interruption
```

If requests fail, investigate:

- Replica count
    
- Readiness
    
- Service endpoints
    
- Ingress behavior
    
- Connection draining
    

---

# 81. Node failure test

In a multi-node lab:

1. Confirm API replicas are distributed.
    
2. Stop one worker or its kubelet.
    
3. Observe node condition.
    
4. Measure time until replacement Pods run elsewhere.
    
5. Verify user-visible availability.
    
6. Restore node.
    
7. Confirm workloads stabilize.
    

Commands:

```bash
kubectl get nodes \
  --watch
```

```bash
kubectl get pods \
  --namespace device-monitor \
  -o wide \
  --watch
```

Node failure recovery is slower than individual Pod replacement because Kubernetes must determine that the node is unavailable.

---

# 82. Database outage test

Scale test PostgreSQL to zero:

```bash
kubectl scale statefulset database \
  --namespace device-monitor \
  --replicas=0
```

Observe:

- API readiness
    
- Liveness behavior
    
- Error responses
    
- Retry behavior
    
- Alerting
    
- Logs
    
- HPA behavior
    

Restore:

```bash
kubectl scale statefulset database \
  --namespace device-monitor \
  --replicas=1
```

The API should recover automatically.

A database outage should not necessarily cause endless API restart loops.

---

# 83. Network partition test

Apply a temporary NetworkPolicy that blocks API-to-database traffic.

Observe:

```text
DNS may still work.
TCP connection fails.
API readiness may fail.
Liveness should ideally remain healthy.
```

Remove the policy and verify recovery.

This validates whether your application distinguishes:

```text
Process is alive
from
Dependency is reachable
```

---

# 84. Storage-capacity test

In a disposable environment:

1. Set a small PVC.
    
2. Write data until nearly full.
    
3. Observe database behavior.
    
4. Confirm monitoring alerts before complete exhaustion.
    
5. Verify backup still works.
    
6. Expand or restore according to the runbook.
    

Do not intentionally fill a production volume.

Monitor:

```text
Filesystem usage
Database growth
WAL growth
Temporary files
Backup space
```

---

# 85. Failure injection safety

Before any failure experiment:

- Define scope
    
- Use non-production first
    
- Set maximum duration
    
- Define stop conditions
    
- Confirm rollback
    
- Notify affected people
    
- Ensure observability
    
- Protect real data
    
- Avoid simultaneous unrelated changes
    

Chaos testing without limits is simply uncontrolled failure.

The objective is learning, not creating damage.

---

# 86. Runbooks

A runbook should contain executable operational instructions.

Example: **API has zero ready replicas**

```text
1. Confirm external symptom.
2. Check Deployment and Pods.
3. Inspect warning Events.
4. Read current and previous logs.
5. Check image pull state.
6. Check ConfigMap and Secret references.
7. Check database availability.
8. Check NetworkPolicies.
9. Check recent Helm revision.
10. Roll back if release-related.
11. Verify external service.
12. Record incident timeline.
```

Avoid vague instructions such as:

```text
Fix Kubernetes.
```

---

# 87. Disaster-recovery runbook

A DR runbook should answer:

```text
Who declares a disaster?
Who has restore authority?
Where are backups?
How are decryption keys obtained?
Which cluster is created?
Which infrastructure code is used?
Which database backup is selected?
How is DNS switched?
How are integrations disabled during recovery?
How is data correctness verified?
When is service declared restored?
How is failback performed?
```

Test it at least periodically.

Personnel changes can make undocumented recovery knowledge disappear.

---

# 88. Production architecture for the device-monitor platform

A stronger architecture might be:

```text
External DNS
     ↓
Redundant load balancer
     ↓
Ingress controllers across zones
     ↓
Device API Deployment
├── minimum 3 replicas
├── topology spread
├── HPA
├── PDB
└── graceful shutdown
     ↓
Highly available PostgreSQL
├── primary
├── replica
├── automated failover
├── continuous transaction-log archive
└── tested backups
```

MQTT path:

```text
Devices
   ↓
Redundant MQTT broker design
   ↓
Shared-subscription consumers
├── several replicas
├── backlog-based autoscaling
├── idempotent processing
└── graceful termination
   ↓
PostgreSQL
```

---

# 89. Reliability design for MQTT

Important questions:

- What happens if the broker restarts?
    
- Are sessions persistent?
    
- Are subscriptions durable?
    
- Which QoS is used?
    
- Can messages be duplicated?
    
- Can consumers process out of order?
    
- Are retained messages backed up?
    
- Is last-will state reliable after broker recovery?
    
- How does the dashboard determine offline status?
    
- What happens when the database is unavailable?
    
- Is unprocessed telemetry buffered?
    
- Where is buffering stored?
    
- What is the acceptable telemetry RPO?
    

Kubernetes can restart components, but these reliability semantics belong to the MQTT and application design.

---

# 90. Recommended API reliability configuration

```yaml
spec:
  minReadySeconds: 20
  progressDeadlineSeconds: 600
  terminationGracePeriodSeconds: 30

  strategy:
    type: RollingUpdate

    rollingUpdate:
      maxUnavailable: 0
      maxSurge: 1

  template:
    spec:
      topologySpreadConstraints:
        - maxSkew: 1
          topologyKey: kubernetes.io/hostname
          whenUnsatisfiable: ScheduleAnyway

          labelSelector:
            matchLabels:
              app.kubernetes.io/name: device-api
              app.kubernetes.io/component: api
```

Add:

```yaml
apiVersion: policy/v1
kind: PodDisruptionBudget

metadata:
  name: device-api

spec:
  minAvailable: 2

  selector:
    matchLabels:
      app.kubernetes.io/name: device-api
      app.kubernetes.io/component: api
```

Add HPA:

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler

metadata:
  name: device-api

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

---

# 91. Helm values for reliability

Add:

```yaml
autoscaling:
  enabled: true
  minReplicas: 3
  maxReplicas: 10
  targetCPUUtilizationPercentage: 60
  scaleDownStabilizationSeconds: 300

podDisruptionBudget:
  enabled: true
  minAvailable: 2

topologySpread:
  enabled: true
  topologyKey: kubernetes.io/hostname
  maxSkew: 1
  whenUnsatisfiable: ScheduleAnyway

deployment:
  minReadySeconds: 20
  progressDeadlineSeconds: 600
  terminationGracePeriodSeconds: 30
  maxUnavailable: 0
  maxSurge: 1
```

When autoscaling is enabled, omit the Deployment’s fixed `replicas` field.

---

# 92. Validate availability controls together

These controls interact:

```text
HPA minimum replicas: 3
PDB minimum available: 2
Three worker nodes
Topology spreading by hostname
Rolling update maxUnavailable: 0
Rolling update maxSurge: 1
```

Required capacity during rollout:

```text
3 current replicas
+
1 surge replica
=
capacity for 4 Pods
```

During one node failure, remaining nodes need capacity for the required available replicas.

Do not configure each feature independently.

---

# 93. Common reliability mistake: too few replicas

Configuration:

```yaml
replicas: 1
```

plus:

```yaml
maxUnavailable: 0
maxSurge: 1
```

may support a rolling replacement under normal capacity, but it still has only one serving replica.

A runtime failure creates a period with no available Pod until replacement becomes ready.

For availability-sensitive stateless services:

```text
minimum 2 replicas
```

is a typical baseline, with three replicas providing better node or zone distribution opportunities.

---

# 94. Common mistake: all replicas on one node

Symptoms:

```bash
kubectl get pods \
  --namespace device-monitor \
  -o wide
```

shows every API Pod on `worker1`.

Fix using:

- Topology spread
    
- Pod anti-affinity
    
- Sufficient eligible nodes
    
- Correct resource requests
    
- Node labels
    
- Removing over-restrictive affinity
    

Replica count without placement diversity is incomplete redundancy.

---

# 95. Common mistake: PDB blocks upgrades

Symptoms:

```text
Cannot evict pod as it would violate the pod's disruption budget
```

Check:

```bash
kubectl get pdb \
  --all-namespaces
```

```bash
kubectl describe pdb \
  --namespace device-monitor \
  device-api
```

Possible remedies:

- Add healthy replicas
    
- Restore failing Pods
    
- Add cluster capacity
    
- Correct overly strict PDB
    
- Schedule maintenance later
    

Do not force deletion without understanding the availability impact.

---

# 96. Common mistake: HPA shows unknown metrics

Inspect:

```bash
kubectl describe hpa \
  --namespace device-monitor \
  device-api
```

Possible causes:

- Metrics Server unavailable
    
- Resource requests missing
    
- New Pods have no metrics yet
    
- Custom metrics adapter unavailable
    
- Incorrect metric name
    
- Authorization or API issue
    

Check:

```bash
kubectl top pods \
  --namespace device-monitor
```

```bash
kubectl get apiservices \
  | grep metrics
```

---

# 97. Common mistake: HPA scales but Pods remain Pending

Check:

```bash
kubectl get pods \
  --namespace device-monitor
```

Describe Pending Pod:

```bash
kubectl describe pod \
  --namespace device-monitor \
  POD_NAME
```

Possible reasons:

- Insufficient CPU
    
- Insufficient memory
    
- Required anti-affinity
    
- Node selector mismatch
    
- Taint not tolerated
    
- PVC unavailable
    
- Node autoscaler disabled
    
- Maximum node count reached
    

Autoscaling the desired replica count is not the same as providing infrastructure capacity.

---

# 98. Common mistake: backup exists but recovery fails

Possible causes:

- Backup is corrupt
    
- Encryption key missing
    
- Wrong database version
    
- WAL files missing
    
- Credentials unavailable
    
- Restore procedure undocumented
    
- Backup stored in failed region
    
- Backup captured inconsistent data
    
- Restore exceeds RTO
    
- Application schema incompatible
    

A successful restore exercise is the only meaningful evidence that recovery is possible.

---

# 99. Production-readiness review

## Application

```text
Multiple replicas?
Stateless where expected?
Graceful SIGTERM?
Correct probes?
Idempotent operations?
Retry with backoff?
Connection limits?
```

## Placement

```text
Replicas across nodes?
Across zones?
Enough eligible nodes?
Topology labels correct?
```

## Scaling

```text
Resource requests measured?
HPA metric meaningful?
Min and max safe?
Downstream capacity checked?
Scale behavior controlled?
```

## Disruption

```text
PDB?
Rolling-update limits?
Spare surge capacity?
Drain tested?
```

## Data

```text
Replication?
Backup?
Point-in-time recovery?
Off-cluster copy?
Restore tested?
RPO and RTO measured?
```

## Cluster

```text
HA control plane?
etcd backup?
Multiple workers?
Upgrade process?
Node autoscaling?
```

## Operations

```text
Monitoring?
Alerts?
Runbooks?
Failure tests?
On-call ownership?
Deployment rollback?
```

---

# 100. Day 29 practical laboratory

## Exercise 1 — HPA

Create a CPU-based HPA for the API.

Configure:

```text
minReplicas: 2
maxReplicas: 8
target CPU: 60%
```

Generate load and observe scale-up and scale-down.

## Exercise 2 — HPA behavior

Add:

- Rapid scale-up
    
- Five-minute scale-down stabilization
    
- Maximum 25% scale-down per minute
    

Compare behavior.

## Exercise 3 — Custom metric design

Choose a suitable autoscaling metric for your MQTT consumer.

Document:

- Metric source
    
- Target value
    
- Maximum replicas
    
- Downstream limit
    
- Scale-down behavior
    

## Exercise 4 — PDB

Create:

```text
minAvailable: 2
```

Test voluntary eviction.

## Exercise 5 — Topology spread

Run at least three workers.

Spread three API replicas across worker hostnames.

Simulate one worker failure.

## Exercise 6 — Graceful shutdown

Add SIGTERM handling to the API.

Run a long request.

Delete its Pod.

Observe whether the request completes.

## Exercise 7 — Rolling update

Configure:

```text
maxUnavailable: 0
maxSurge: 1
minReadySeconds: 20
```

Perform an image update while sending continuous traffic.

## Exercise 8 — Node maintenance

Cordon and drain one worker.

Confirm PDB behavior and service availability.

## Exercise 9 — Database backup

Create a logical PostgreSQL backup.

Restore it into an isolated namespace.

Run integrity checks and API smoke tests.

## Exercise 10 — Disaster-recovery measurement

Record:

- Backup timestamp
    
- Failure declaration time
    
- Restore start
    
- Database ready time
    
- Application ready time
    
- Verification completion
    

Calculate actual RPO and RTO.

---

# 101. Day 29 command reference

```bash
# Inspect metrics
kubectl top pods \
  --namespace device-monitor

# Apply HPA
kubectl apply \
  -f 20-api-hpa.yaml

# Inspect HPA
kubectl get hpa \
  --namespace device-monitor

kubectl describe hpa \
  --namespace device-monitor \
  device-api

# Apply PDB
kubectl apply \
  -f 21-api-pdb.yaml

# Inspect PDB
kubectl get pdb \
  --namespace device-monitor

kubectl describe pdb \
  --namespace device-monitor \
  device-api

# View placement
kubectl get pods \
  --namespace device-monitor \
  -o wide

# Show topology labels
kubectl get nodes \
  --show-labels

# Cordon node
kubectl cordon WORKER

# Drain node
kubectl drain WORKER \
  --ignore-daemonsets \
  --delete-emptydir-data

# Restore scheduling
kubectl uncordon WORKER

# Check rollout
kubectl rollout status \
  deployment/device-api \
  --namespace device-monitor

# View Deployment conditions
kubectl describe deployment \
  --namespace device-monitor \
  device-api

# Database logical backup
kubectl exec \
  --namespace device-monitor \
  database-0 \
  -- \
  pg_dump \
    -U device_app \
    -d device_monitor \
    --format=custom \
  > device-monitor.dump

# Inspect storage
kubectl get pvc,pv \
  --namespace device-monitor

# Check cluster components
kubectl get nodes
kubectl get pods \
  --namespace kube-system
```

---

# 102. Knowledge check

## What does HPA change?

The number of replicas of a scalable Kubernetes workload.

## What does VPA change?

The CPU or memory resource configuration assigned to Pods.

## What does a node autoscaler change?

The number or size of cluster worker nodes.

## Why are resource requests important for CPU HPA?

Relative CPU utilization is calculated against requested CPU.

## Is CPU always the correct autoscaling metric?

No. Queue depth, backlog age, request concurrency, or another application metric may better represent demand.

## What is a PodDisruptionBudget?

An object limiting how many selected Pods may be voluntarily disrupted simultaneously.

## Does a PDB protect against a worker suddenly crashing?

No. It primarily affects supported voluntary eviction operations.

## What is topology spreading?

Distributing replicas across failure domains such as nodes or zones.

## What does graceful shutdown accomplish?

It lets a terminating application stop receiving new work and safely finish or release active work.

## Does a StatefulSet create database replication?

No.

## Is replication a backup?

No. Replication may copy logical deletion or corruption.

## What is RPO?

The maximum acceptable amount of recent data loss.

## What is RTO?

The maximum acceptable time required to restore service.

## Is a PVC a backup?

No. It is primary persistent storage.

## Is a storage snapshot always database-consistent?

No. It may be only crash-consistent unless coordinated with the application.

## Why back up etcd?

It contains Kubernetes API state required for self-managed cluster recovery. ([Kubernetes](https://kubernetes.io/docs/tasks/administer-cluster/configure-upgrade-etcd/?utm_source=chatgpt.com "Operating etcd clusters for Kubernetes"))

## What is the only strong proof that a backup works?

Successfully restoring and validating it.

---

# 103. Day 29 completion challenge

Complete this independently:

1. Define availability for the device API.
    
2. Define availability for MQTT message processing.
    
3. Identify every current single point of failure.
    
4. Map node, zone, storage, and cluster failure domains.
    
5. Run at least three API replicas.
    
6. Verify they are distributed across workers.
    
7. Add topology-spread constraints.
    
8. Test strict and preferred distribution.
    
9. Create an HPA.
    
10. Configure minimum and maximum replicas.
    
11. Define a CPU target.
    
12. Generate load.
    
13. Observe HPA scale-up.
    
14. Remove load.
    
15. Observe stabilized scale-down.
    
16. Add scale-up policies.
    
17. Add scale-down policies.
    
18. Explain why CPU requests affect HPA.
    
19. Design a backlog metric for MQTT consumers.
    
20. Calculate a safe maximum consumer count.
    
21. Calculate its database-connection impact.
    
22. Create a PodDisruptionBudget.
    
23. Inspect allowed disruptions.
    
24. Make one Pod unready.
    
25. Observe PDB status.
    
26. Cordon a node.
    
27. Drain the node.
    
28. Confirm the API remains available.
    
29. Uncordon the node.
    
30. Implement graceful SIGTERM handling.
    
31. Set a termination grace period.
    
32. Test a long request during termination.
    
33. Configure `maxUnavailable`.
    
34. Configure `maxSurge`.
    
35. Add `minReadySeconds`.
    
36. Add a progress deadline.
    
37. Perform a zero-interruption rolling update.
    
38. Simulate one API Pod failure.
    
39. Simulate one worker failure.
    
40. Simulate PostgreSQL unavailability.
    
41. Simulate blocked database networking.
    
42. Verify readiness and liveness behavior.
    
43. Create a PostgreSQL logical backup.
    
44. Encrypt and store it outside the cluster.
    
45. Restore into an isolated environment.
    
46. Verify row counts and critical records.
    
47. Run API tests against the restored data.
    
48. Record actual restore duration.
    
49. Define the database RPO.
    
50. Define the database RTO.
    
51. Create a point-in-time recovery design.
    
52. Document WAL retention.
    
53. Review volume snapshot support.
    
54. Explain crash-consistent versus application-consistent snapshots.
    
55. Define etcd backup procedures.
    
56. Protect etcd backup encryption keys.
    
57. Inventory runtime state not stored in Git.
    
58. Protect released container images.
    
59. Design a second-cluster recovery strategy.
    
60. Decide between backup/restore, warm standby, and active-passive.
    
61. Create a DNS failover procedure.
    
62. Create an external dependency inventory.
    
63. Calculate N+1 worker capacity.
    
64. Include rollout surge in capacity planning.
    
65. Include HPA maximums in capacity planning.
    
66. Set alerts for replica unavailability.
    
67. Set alerts for storage exhaustion.
    
68. Set alerts for backup failure.
    
69. Set alerts for excessive restore lag.
    
70. Write an API failure runbook.
    
71. Write a database outage runbook.
    
72. Write a cluster-loss runbook.
    
73. Schedule a restore exercise.
    
74. Schedule a node-drain exercise.
    
75. Produce a production-readiness report.
    

The central Day 29 model is:

```text
Traffic and failures
        ↓
Autoscaling and redundant replicas
        ↓
Topology-aware scheduling
        ↓
Graceful lifecycle and disruption budgets
        ↓
Highly available dependencies
        ↓
Replication plus independent backups
        ↓
Measured RPO and RTO
        ↓
Tested restoration and disaster recovery
        ↓
Reliable production service
```

The most important operational lesson is:

> Kubernetes can replace Pods and reschedule workloads, but it cannot decide your availability targets, replicate your database correctly, guarantee backup restorability, or design disaster recovery. Production resilience comes from eliminating shared failure points, distributing replicas, scaling against meaningful demand, protecting planned disruptions, preserving data independently, and repeatedly proving that recovery works.