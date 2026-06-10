#### Kubernetes Fundamentals: Pods, Deployments, Services, and Local Clusters

Day 21 introduced Docker Swarm and the idea of orchestration:

```text
Desired state
    ↓
Scheduler
    ↓
Tasks across nodes
    ↓
Self-healing, scaling, updates
```

Today you begin Kubernetes.

Kubernetes, commonly abbreviated as **K8s**, is an open-source platform for deploying, scaling, and managing containerized applications. Like Swarm, it uses declarative desired state, but it provides a larger set of abstractions and a broader ecosystem. ([Kubernetes](https://kubernetes.io/docs/concepts/overview/?utm_source=chatgpt.com "Overview"))

The central lesson is:

> In Kubernetes, you normally do not manage individual containers directly. You define Kubernetes objects such as Deployments and Services, and Kubernetes creates, replaces, connects, and scales Pods to match the declared state.

---

## 1. Day 22 objectives

By the end of today, you should understand:

- What Kubernetes is
    
- Kubernetes versus Docker and Docker Compose
    
- Control plane and worker nodes
    
- Pods
    
- Deployments
    
- ReplicaSets
    
- Services
    
- Labels and selectors
    
- Declarative YAML manifests
    
- `kubectl`
    
- `kubeconfig`
    
- Minikube
    
- Creating and inspecting workloads
    
- Scaling
    
- Self-healing
    
- Rolling updates
    
- Rollback
    
- Port forwarding
    
- ClusterIP, NodePort, and LoadBalancer Services
    
- ConfigMaps and Secrets
    
- Resource requests and limits
    
- Persistent storage fundamentals
    
- Basic Kubernetes troubleshooting
    
- How your Docker knowledge transfers to Kubernetes
    

---

# 2. Where Kubernetes fits

Your progression has been:

```text
docker run
    ↓
Docker Compose
    ↓
Docker Swarm
    ↓
Kubernetes
```

Each level solves a broader problem.

## Docker

Runs containers on one Docker Engine:

```bash
docker run nginx
```

## Docker Compose

Runs a group of containers on one Docker Engine:

```bash
docker compose up -d
```

## Docker Swarm

Runs services across a Docker cluster:

```bash
docker stack deploy
```

## Kubernetes

Runs declarative workloads through Kubernetes API objects:

```bash
kubectl apply -f deployment.yaml
```

Kubernetes is not a replacement for container images.

You still need to:

1. Build the image.
    
2. Test it.
    
3. Push it to a registry.
    
4. Reference it from Kubernetes.
    

---

# 3. Kubernetes does not normally build images

Kubernetes expects an image such as:

```text
registry.example.com/team/device-api:2.0.0
```

Kubernetes then asks a container runtime to pull and run that image.

The normal workflow remains:

```text
Source code
    ↓
Dockerfile
    ↓
CI pipeline
    ↓
Container image
    ↓
Registry
    ↓
Kubernetes Deployment
```

Do not treat a Kubernetes cluster as your normal image-building environment.

---

# 4. Kubernetes cluster architecture

A Kubernetes cluster contains:

```text
Control plane
+
Worker nodes
```

Every cluster needs at least one worker node to run application Pods. ([Kubernetes](https://kubernetes.io/docs/concepts/architecture/?utm_source=chatgpt.com "Cluster Architecture"))

Conceptually:

```text
Kubernetes cluster

┌─────────────────────────────┐
│ Control plane               │
│                             │
│ API server                  │
│ Scheduler                   │
│ Controllers                 │
│ Cluster state store         │
└──────────────┬──────────────┘
               │
        Kubernetes API
               │
    ┌──────────┴──────────┐
    ▼                     ▼
┌─────────────┐      ┌─────────────┐
│ Worker node │      │ Worker node │
│             │      │             │
│ Pods        │      │ Pods        │
│ kubelet     │      │ kubelet     │
│ runtime     │      │ runtime     │
└─────────────┘      └─────────────┘
```

---

# 5. Control-plane components

You do not need to administer every component today, but you need the mental model.

## API server

The central interface to Kubernetes.

Commands such as:

```bash
kubectl get pods
```

communicate with the Kubernetes API server.

## Scheduler

Chooses which eligible node should run a new Pod.

It considers information such as:

- Available resources
    
- Pod resource requests
    
- Scheduling constraints
    
- Node conditions
    
- Affinity rules
    
- Taints and tolerations
    

## Controller manager

Runs controllers that continuously compare desired and actual state.

For example:

```text
Desired Deployment replicas: 3
Actual Pods: 2
        ↓
Controller creates another Pod
```

## Cluster state store

Kubernetes stores cluster configuration and state in `etcd`.

You normally interact with Kubernetes through the API rather than editing its state storage directly.

---

# 6. Worker-node components

A worker node normally contains:

## kubelet

The node agent.

It:

- Receives Pod specifications
    
- Ensures required containers run
    
- Reports node and Pod status
    
- Executes health checks
    
- Works with the container runtime
    

## Container runtime

Actually runs the containers.

Common runtime implementations include:

- containerd
    
- CRI-O
    

Kubernetes no longer requires Docker Engine as its internal runtime.

Your Docker-built OCI-compatible images still work because the image format is standardized.

## Network proxy or equivalent networking component

Implements parts of Kubernetes Service networking.

The exact implementation depends on the cluster and networking stack.

---

# 7. Kubernetes objects

Kubernetes represents desired state using API objects.

Common objects include:

```text
Pod
Deployment
ReplicaSet
Service
ConfigMap
Secret
Namespace
PersistentVolumeClaim
StatefulSet
DaemonSet
Job
CronJob
Ingress
```

Today you will focus on:

```text
Pod
Deployment
Service
ConfigMap
Secret
```

---

# 8. What is a Pod?

A Pod is Kubernetes’ smallest normal deployable workload unit.

A Pod contains one or more containers that share:

- Network namespace
    
- IP address
    
- Port space
    
- Certain volumes
    
- Pod lifecycle
    

Typical Pod:

```text
Pod
└── Application container
```

Multi-container Pod:

```text
Pod
├── Main application
└── Closely coupled helper container
```

Containers inside the same Pod can communicate through:

```text
localhost
```

because they share the Pod network namespace.

---

# 9. Pod versus container

A Pod is not simply another word for container.

Conceptually:

```text
Pod
├── Metadata
├── Networking
├── Storage definitions
├── Restart behavior
├── Scheduling requirements
└── One or more containers
```

For example:

```text
Pod: device-api-abc123
├── device-api container
└── log-helper container
```

Most application Pods contain one main container.

Do not place unrelated services in one Pod merely because Kubernetes allows multiple containers.

This is usually wrong:

```text
One Pod
├── API
├── PostgreSQL
├── Mosquitto
└── Nginx
```

Those services have different lifecycles and should normally be managed separately.

---

# 10. Pods are disposable

Pod names and IP addresses should not be treated as permanent infrastructure.

Suppose Kubernetes replaces:

```text
device-api-7cfd5f6d8b-x7t4k
```

with:

```text
device-api-7cfd5f6d8b-p9d2n
```

The new Pod may have:

- A different name
    
- A different IP address
    
- A new container instance
    
- The same application role
    

You should connect to a stable Service rather than directly to a particular Pod IP.

The mental model is:

```text
Pod
→ disposable runtime instance

Deployment
→ durable desired-state object
```

---

# 11. Pod lifecycle phases

Common Pod phases include:

```text
Pending
Running
Succeeded
Failed
Unknown
```

A Pod normally starts as `Pending`, moves to `Running` when its primary containers start, and eventually reaches `Succeeded` or `Failed` for terminating workloads. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/pods/pod-lifecycle/?utm_source=chatgpt.com "Pod Lifecycle"))

Important distinction:

```text
Pod phase: Running
```

does not necessarily mean:

```text
Application is ready to receive requests
```

Readiness probes address that distinction.

---

# 12. Do not normally create standalone Pods

You can create a Pod directly, but for long-running applications this is usually not the right management model.

If the node or Pod fails, a standalone Pod has no higher-level controller responsible for recreating it.

Instead, use a Deployment:

```text
Deployment
    ↓
ReplicaSet
    ↓
Pods
```

Use standalone Pods mainly for:

- Learning
    
- Short diagnostics
    
- Special low-level cases
    

Use Deployments for ordinary stateless applications.

---

# 13. What is a Deployment?

A Deployment manages a set of Pods for an application workload and provides declarative updates through ReplicaSets. You describe the desired state, and the Deployment controller changes the actual state toward it. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/controllers/deployment/?utm_source=chatgpt.com "Deployments"))

Example desired state:

```text
Image: nginx:alpine
Replicas: 3
Container port: 80
```

The Deployment makes Kubernetes maintain:

```text
Three matching Pods
```

If one Pod disappears:

```text
Actual: 2
Desired: 3
    ↓
New Pod created
```

---

# 14. Deployment, ReplicaSet, and Pod relationship

The normal chain is:

```text
Deployment
    ↓ manages
ReplicaSet
    ↓ manages
Pods
```

Example:

```text
Deployment: device-api
    ↓
ReplicaSet: device-api-7cb97c9b68
    ↓
Pods:
device-api-7cb97c9b68-a1b2c
device-api-7cb97c9b68-d3e4f
device-api-7cb97c9b68-g5h6i
```

You normally manage the Deployment.

Kubernetes manages the ReplicaSet and Pods.

Do not usually edit the generated ReplicaSet directly.

---

# 15. What is `kubectl`?

`kubectl` is the primary Kubernetes command-line client.

It communicates with the Kubernetes API server.

Examples:

```bash
kubectl get pods
kubectl apply -f deployment.yaml
kubectl logs POD_NAME
kubectl describe pod POD_NAME
kubectl scale deployment device-api --replicas=3
```

Your `kubectl` client should be within one minor version of the cluster control plane for supported compatibility. ([Kubernetes](https://kubernetes.io/docs/tasks/tools/install-kubectl-linux/?utm_source=chatgpt.com "Install and Set Up kubectl on Linux"))

Verify installation:

```bash
kubectl version --client
```

---

# 16. What is kubeconfig?

`kubectl` needs connection information and credentials for the cluster.

This is normally stored in:

```text
~/.kube/config
```

A kubeconfig file may contain:

```text
Clusters
Users or credentials
Contexts
Current context
```

A context combines:

```text
Cluster
+
User
+
Namespace
```

Inspect:

```bash
kubectl config view
```

Show current context:

```bash
kubectl config current-context
```

List contexts:

```bash
kubectl config get-contexts
```

Switch context:

```bash
kubectl config use-context CONTEXT_NAME
```

Kubeconfig files can contain sensitive authentication information.

Do not commit them to Git.

---

# 17. Local Kubernetes options

For learning, common local choices include:

- Minikube
    
- kind
    
- k3d
    
- Docker Desktop Kubernetes
    
- Rancher Desktop
    
- MicroK8s
    

Today’s main lab uses **Minikube**.

Minikube runs a local Kubernetes cluster and supports several drivers, including container and virtual-machine-based approaches. Current documentation lists at least 2 CPUs, 2 GB of free memory, 20 GB of disk space, internet access, and a suitable container or VM manager as baseline requirements. ([Kubernetes](https://kubernetes.io/docs/tasks/tools/install-minikube?utm_source=chatgpt.com "minikube start - Kubernetes"))

---

# 18. Install Minikube on Linux

Follow the current official installation page for your CPU architecture.

On an `amd64` Linux system, the pattern is:

```bash
curl -LO \
  https://github.com/kubernetes/minikube/releases/latest/download/minikube-linux-amd64
```

Install:

```bash
sudo install \
  minikube-linux-amd64 \
  /usr/local/bin/minikube
```

Verify:

```bash
minikube version
```

Remove the downloaded installer:

```bash
rm minikube-linux-amd64
```

The exact release can change, so use the current official Minikube installation instructions rather than copying an old version number. ([Kubernetes](https://kubernetes.io/docs/tasks/tools/install-minikube?utm_source=chatgpt.com "minikube start - Kubernetes"))

---

# 19. Start the local cluster

With Docker installed:

```bash
minikube start \
  --driver=docker
```

Inspect:

```bash
minikube status
```

Expected conceptually:

```text
host: Running
kubelet: Running
apiserver: Running
kubeconfig: Configured
```

Check Kubernetes:

```bash
kubectl cluster-info
```

List nodes:

```bash
kubectl get nodes
```

You should see a node named:

```text
minikube
```

---

# 20. Inspect the cluster

Run:

```bash
kubectl get nodes -o wide
```

Describe the node:

```bash
kubectl describe node minikube
```

You will see information such as:

- Kubernetes version
    
- Internal IP
    
- Operating system
    
- Container runtime
    
- Capacity
    
- Allocatable resources
    
- Node conditions
    
- Running Pods
    

Do not try to understand every field immediately.

Today, focus on:

```text
Status
Roles
Version
CPU
Memory
Conditions
```

---

# 21. Namespaces

Namespaces divide Kubernetes objects into logical groups.

List:

```bash
kubectl get namespaces
```

Typical namespaces include:

```text
default
kube-system
kube-public
kube-node-lease
```

Your application objects will initially use:

```text
default
```

View system Pods:

```bash
kubectl get pods \
  --namespace kube-system
```

Create a learning namespace:

```bash
kubectl create namespace docker-course
```

Set it for the current context:

```bash
kubectl config set-context \
  --current \
  --namespace=docker-course
```

Verify:

```bash
kubectl config view \
  --minify \
  --output 'jsonpath={..namespace}'
```

Namespaces provide logical separation, but they are not complete security boundaries by themselves.

---

# 22. Create your first Deployment imperatively

Run:

```bash
kubectl create deployment day22-web \
  --image=nginx:alpine
```

Inspect:

```bash
kubectl get deployments
```

```bash
kubectl get replicasets
```

```bash
kubectl get pods
```

You should see:

```text
Deployment
    ↓
ReplicaSet
    ↓
Pod
```

Display labels:

```bash
kubectl get pods \
  --show-labels
```

---

# 23. Inspect the Pod

Get the Pod name:

```bash
kubectl get pods
```

Describe it:

```bash
kubectl describe pod POD_NAME
```

Important sections include:

- Name
    
- Namespace
    
- Node
    
- Labels
    
- Status
    
- IP
    
- Containers
    
- Image
    
- Ports
    
- Conditions
    
- Events
    

Events near the bottom often provide the best evidence for startup problems.

---

# 24. View Pod logs

Run:

```bash
kubectl logs POD_NAME
```

Follow:

```bash
kubectl logs -f POD_NAME
```

Show recent lines:

```bash
kubectl logs \
  --tail=50 \
  POD_NAME
```

Show logs from the previous container instance after a restart:

```bash
kubectl logs \
  --previous \
  POD_NAME
```

This is especially useful for crash loops.

For a multi-container Pod:

```bash
kubectl logs \
  POD_NAME \
  --container CONTAINER_NAME
```

---

# 25. Execute a command inside a Pod

Run:

```bash
kubectl exec POD_NAME -- \
  id
```

Open a shell where the image contains one:

```bash
kubectl exec -it POD_NAME -- \
  sh
```

Inside:

```sh
hostname
ip address
cat /etc/hosts
```

Exit:

```sh
exit
```

As with Docker, minimal images may not include a shell.

---

# 26. Access the application with port forwarding

The Pod is not yet exposed outside the cluster.

Forward a local port to the Deployment:

```bash
kubectl port-forward \
  deployment/day22-web \
  8080:80
```

Test in another terminal:

```bash
curl http://127.0.0.1:8080
```

Stop forwarding with:

```text
Ctrl+C
```

Port forwarding is useful for:

- Local testing
    
- Debugging
    
- Administrative access
    
- Development
    

It is not normally the final production exposure mechanism.

---

# 27. Scale the Deployment

Scale to three Pods:

```bash
kubectl scale deployment day22-web \
  --replicas=3
```

Inspect:

```bash
kubectl get deployments
kubectl get replicasets
kubectl get pods -o wide
```

Expected:

```text
READY   UP-TO-DATE   AVAILABLE
3/3     3            3
```

Kubernetes maintains the declared number of replicas through the Deployment and its ReplicaSet.

---

# 28. Test self-healing

List Pods:

```bash
kubectl get pods
```

Delete one:

```bash
kubectl delete pod POD_NAME
```

Immediately watch:

```bash
kubectl get pods --watch
```

You should observe:

```text
Old Pod terminating
New Pod created
New Pod running
```

Why?

Because you deleted a disposable Pod, not the Deployment.

The desired state still says:

```text
Replicas: 3
```

Kubernetes’ self-healing capabilities include replacing failed containers and rescheduling workloads when required. ([Kubernetes](https://kubernetes.io/docs/concepts/overview/?utm_source=chatgpt.com "Overview"))

---

# 29. Declarative management

Imperative commands are useful for quick experiments:

```bash
kubectl create deployment ...
kubectl scale ...
```

For real systems, use YAML manifests.

Declarative workflow:

```text
Write desired state in YAML
        ↓
kubectl apply
        ↓
Kubernetes compares desired and current state
        ↓
Kubernetes changes what is necessary
```

This resembles:

```text
compose.yaml
docker compose up
```

but uses Kubernetes objects.

---

# 30. Create a Deployment manifest

Delete the temporary Deployment:

```bash
kubectl delete deployment day22-web
```

Create a project:

```bash
mkdir -p ~/docker-course/day22/kubernetes
cd ~/docker-course/day22/kubernetes
```

Create `web-deployment.yaml`:

```yaml
apiVersion: apps/v1
kind: Deployment

metadata:
  name: day22-web
  labels:
    app: day22-web

spec:
  replicas: 3

  selector:
    matchLabels:
      app: day22-web

  template:
    metadata:
      labels:
        app: day22-web

    spec:
      containers:
        - name: nginx
          image: nginx:alpine

          ports:
            - name: http
              containerPort: 80
```

Apply:

```bash
kubectl apply \
  -f web-deployment.yaml
```

---

# 31. Anatomy of a Kubernetes manifest

Most Kubernetes manifests contain:

```yaml
apiVersion:
kind:
metadata:
spec:
```

## `apiVersion`

Which Kubernetes API version defines the object:

```yaml
apiVersion: apps/v1
```

## `kind`

The object type:

```yaml
kind: Deployment
```

## `metadata`

Identity and descriptive information:

```yaml
metadata:
  name: day22-web
  labels:
    app: day22-web
```

## `spec`

The desired state:

```yaml
spec:
  replicas: 3
```

Different object kinds have different `spec` structures.

---

# 32. Labels

Labels are key-value metadata used to organize and select objects.

Example:

```yaml
labels:
  app: device-api
  environment: production
  component: backend
```

View labels:

```bash
kubectl get pods \
  --show-labels
```

Select by label:

```bash
kubectl get pods \
  -l app=day22-web
```

Use labels consistently.

A practical convention might include:

```text
app
component
environment
version
managed-by
```

---

# 33. Selectors

The Deployment selector is:

```yaml
selector:
  matchLabels:
    app: day22-web
```

The Pod template contains:

```yaml
template:
  metadata:
    labels:
      app: day22-web
```

These must match.

The Deployment interprets Pods with that label as its managed replicas.

Conceptually:

```text
Deployment selector
app=day22-web
        ↓ selects
Pods carrying
app=day22-web
```

Selectors also connect Services to Pods.

---

# 34. What is a Kubernetes Service?

Pods are replaceable and their IP addresses can change.

A Service provides stable networking for a selected group of Pods.

Conceptually:

```text
Client
   ↓
Service: day22-web
Stable virtual address and DNS
   ↓
Pod 1
Pod 2
Pod 3
```

The Service does not contain the application.

It selects matching Pods and forwards network traffic to them.

---

# 35. Create a ClusterIP Service

Create `web-service.yaml`:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: day22-web

spec:
  type: ClusterIP

  selector:
    app: day22-web

  ports:
    - name: http
      port: 80
      targetPort: http
```

Apply:

```bash
kubectl apply \
  -f web-service.yaml
```

Inspect:

```bash
kubectl get services
```

Describe:

```bash
kubectl describe service day22-web
```

---

# 36. Service port versus target port

The Service declares:

```yaml
port: 80
targetPort: http
```

Meaning:

```text
Service port 80
      ↓
Container port named http
      ↓
Container port 80
```

The Deployment defined:

```yaml
ports:
  - name: http
    containerPort: 80
```

Using named ports helps avoid repeating numeric values in several places.

---

# 37. Service discovery

Inside the same namespace, another Pod can reach:

```text
day22-web
```

or:

```text
day22-web:80
```

A more complete cluster DNS name resembles:

```text
day22-web.docker-course.svc.cluster.local
```

You normally use the short service name for same-namespace communication.

This is the Kubernetes equivalent of using a Compose service name:

```text
database:5432
```

Do not connect through a specific Pod IP.

---

# 38. Test the Service from inside the cluster

Create a temporary diagnostic Pod:

```bash
kubectl run network-test \
  --image=alpine \
  --restart=Never \
  -- sleep 3600
```

Open a shell:

```bash
kubectl exec -it network-test -- \
  sh
```

Install a client:

```sh
apk add --no-cache curl
```

Test:

```sh
curl http://day22-web
```

Run several times:

```sh
for i in 1 2 3 4 5; do
  curl -s http://day22-web >/dev/null
  echo "request $i succeeded"
done
```

Exit:

```sh
exit
```

Remove:

```bash
kubectl delete pod network-test
```

---

# 39. Inspect Service endpoints

Run:

```bash
kubectl get endpoints day22-web
```

Depending on the cluster version and API presentation, you may also inspect EndpointSlices:

```bash
kubectl get endpointslices
```

The selected Pod addresses should appear as backends.

If a Service has no backends, common causes are:

- Selector does not match Pod labels
    
- Pods are not ready
    
- Pods do not exist
    
- Wrong namespace
    
- Incorrect label spelling
    

---

# 40. Kubernetes Service types

Common Service types are:

```text
ClusterIP
NodePort
LoadBalancer
ExternalName
```

## ClusterIP

Reachable inside the cluster.

Use for:

- API to database
    
- Application to cache
    
- Internal services
    

## NodePort

Publishes a port on each node.

Useful for:

- Labs
    
- Simple environments
    
- Some external load-balancer setups
    

## LoadBalancer

Requests an external load balancer from the cluster infrastructure.

Common in cloud or integrated load-balancer environments.

## ExternalName

Maps the Service to an external DNS name.

For ordinary internal application communication, start with `ClusterIP`.

---

# 41. Expose as NodePort for the lab

Change `web-service.yaml`:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: day22-web

spec:
  type: NodePort

  selector:
    app: day22-web

  ports:
    - name: http
      port: 80
      targetPort: http
      nodePort: 30080
```

Apply:

```bash
kubectl apply \
  -f web-service.yaml
```

Get Minikube IP:

```bash
minikube ip
```

Test:

```bash
curl http://$(minikube ip):30080
```

Depending on your Minikube driver and host networking, you may instead use:

```bash
minikube service day22-web \
  --url
```

---

# 42. Declarative inspection

Show the live object:

```bash
kubectl get deployment day22-web \
  -o yaml
```

Show a more concise description:

```bash
kubectl describe deployment day22-web
```

Validate a manifest without persisting it:

```bash
kubectl apply \
  --dry-run=server \
  -f web-deployment.yaml
```

Show the proposed object:

```bash
kubectl apply \
  --dry-run=client \
  -f web-deployment.yaml \
  -o yaml
```

Use server-side validation when the cluster is available.

---

# 43. Change replica count declaratively

Edit:

```yaml
spec:
  replicas: 5
```

Apply:

```bash
kubectl apply \
  -f web-deployment.yaml
```

Watch:

```bash
kubectl get pods \
  --watch
```

Kubernetes creates two additional Pods.

The manifest remains the recorded desired state.

This is better than relying only on:

```bash
kubectl scale ...
```

because a future `kubectl apply` from the file would otherwise restore the file’s replica value.

---

# 44. Rolling updates

Change:

```yaml
image: nginx:alpine
```

to a specific newer approved image tag, for example:

```yaml
image: nginx:1.27-alpine
```

Apply:

```bash
kubectl apply \
  -f web-deployment.yaml
```

Watch rollout:

```bash
kubectl rollout status \
  deployment/day22-web
```

Kubernetes Deployments gradually replace old Pods with new Pods during rolling updates, allowing availability to be maintained when enough healthy replicas and capacity exist. ([Kubernetes](https://kubernetes.io/docs/tutorials/kubernetes-basics/update/update-intro/?utm_source=chatgpt.com "Performing a Rolling Update"))

Inspect:

```bash
kubectl get replicasets
kubectl get pods
```

You should see a new ReplicaSet.

---

# 45. Deployment update strategy

Add:

```yaml
spec:
  strategy:
    type: RollingUpdate

    rollingUpdate:
      maxUnavailable: 1
      maxSurge: 1
```

Full idea:

```text
maxUnavailable: 1
→ At most one desired replica may be unavailable

maxSurge: 1
→ At most one extra Pod may exist during update
```

For three replicas, Kubernetes may temporarily run four Pods while replacing the old version.

A rolling update reduces interruption only when:

- The application has multiple replicas
    
- Readiness probes are correct
    
- New Pods can start
    
- Sufficient resources exist
    
- Old and new versions can overlap
    
- Dependencies support the transition
    

---

# 46. Rollout history

View:

```bash
kubectl rollout history \
  deployment/day22-web
```

Add a change cause through an annotation:

```bash
kubectl annotate deployment day22-web \
  kubernetes.io/change-cause="Update Nginx image" \
  --overwrite
```

Inspect history again:

```bash
kubectl rollout history \
  deployment/day22-web
```

For serious release tracking, also use:

- Immutable image tags
    
- Image digests
    
- Git commits
    
- CI pipeline records
    
- Deployment annotations
    

---

# 47. Roll back a Deployment

Undo the latest rollout:

```bash
kubectl rollout undo \
  deployment/day22-web
```

Wait:

```bash
kubectl rollout status \
  deployment/day22-web
```

Undo to a specific revision:

```bash
kubectl rollout undo \
  deployment/day22-web \
  --to-revision=REVISION_NUMBER
```

A Deployment rollback changes the Pod template to a previous revision.

It does not roll back:

- Database changes
    
- External configuration
    
- Persistent data
    
- Third-party services
    

---

# 48. Readiness probes

A readiness probe answers:

```text
Can this Pod receive traffic now?
```

If readiness fails:

- The Pod can remain running.
    
- The Service stops routing ordinary traffic to it.
    
- The application may later recover.
    

Add to the container:

```yaml
readinessProbe:
  httpGet:
    path: /
    port: http

  initialDelaySeconds: 3
  periodSeconds: 5
  timeoutSeconds: 2
  failureThreshold: 3
```

This is crucial for rolling updates.

Without a meaningful readiness probe, traffic may reach a Pod before the application is genuinely ready.

---

# 49. Liveness probes

A liveness probe answers:

```text
Is this container stuck or irrecoverably unhealthy?
```

Example:

```yaml
livenessProbe:
  httpGet:
    path: /
    port: http

  initialDelaySeconds: 10
  periodSeconds: 10
  timeoutSeconds: 2
  failureThreshold: 3
```

After repeated liveness failures, Kubernetes may restart the container.

Do not make the liveness probe depend on every external dependency.

For example, restarting an API continuously because PostgreSQL is briefly unavailable can make the outage worse.

---

# 50. Startup probes

A startup probe is useful for slow-starting applications.

Example:

```yaml
startupProbe:
  httpGet:
    path: /
    port: http

  periodSeconds: 5
  failureThreshold: 30
```

This permits up to approximately:

```text
5 seconds × 30 attempts = 150 seconds
```

for startup.

While the startup probe has not succeeded, Kubernetes does not use the liveness probe to kill the slow-starting application.

Use startup probes when startup can legitimately take much longer than normal runtime responses.

---

# 51. Complete probe example

Update the container:

```yaml
containers:
  - name: nginx
    image: nginx:1.27-alpine

    ports:
      - name: http
        containerPort: 80

    startupProbe:
      httpGet:
        path: /
        port: http
      periodSeconds: 2
      failureThreshold: 15

    readinessProbe:
      httpGet:
        path: /
        port: http
      periodSeconds: 5
      timeoutSeconds: 2
      failureThreshold: 3

    livenessProbe:
      httpGet:
        path: /
        port: http
      periodSeconds: 10
      timeoutSeconds: 2
      failureThreshold: 3
```

Apply:

```bash
kubectl apply \
  -f web-deployment.yaml
```

Inspect:

```bash
kubectl describe pod POD_NAME
```

---

# 52. Resource requests and limits

Kubernetes allows container resource requirements to be declared.

The most common resources are CPU and memory. ([Kubernetes](https://kubernetes.io/docs/concepts/configuration/manage-resources-containers/?utm_source=chatgpt.com "Resource Management for Pods and Containers"))

Example:

```yaml
resources:
  requests:
    cpu: "100m"
    memory: "64Mi"

  limits:
    cpu: "500m"
    memory: "256Mi"
```

## Request

Used by the scheduler when choosing a node.

Meaning:

```text
This container needs at least this amount reserved for scheduling.
```

## Limit

Runtime upper boundary.

Meaning:

```text
This container must not exceed this amount according to the applicable resource mechanism.
```

---

# 53. CPU units

Kubernetes CPU can be expressed as:

```text
1
500m
250m
100m
```

Where:

```text
1000m = 1 CPU
500m  = 0.5 CPU
100m  = 0.1 CPU
```

Example:

```yaml
requests:
  cpu: "100m"

limits:
  cpu: "500m"
```

This asks the scheduler to reserve 0.1 CPU and restricts the container to approximately 0.5 CPU.

---

# 54. Memory units

Common memory units include:

```text
64Mi
256Mi
1Gi
```

Example:

```yaml
requests:
  memory: "64Mi"

limits:
  memory: "256Mi"
```

If a container exceeds an enforced memory limit, it may be terminated due to an out-of-memory condition.

Inspect:

```bash
kubectl describe pod POD_NAME
```

Look for:

```text
OOMKilled
```

---

# 55. Add resource settings

Add to `web-deployment.yaml`:

```yaml
resources:
  requests:
    cpu: "100m"
    memory: "64Mi"

  limits:
    cpu: "500m"
    memory: "256Mi"
```

Apply:

```bash
kubectl apply \
  -f web-deployment.yaml
```

Inspect:

```bash
kubectl describe pod POD_NAME
```

View node allocation:

```bash
kubectl describe node minikube
```

Do not choose production values without measurement and load testing.

---

# 56. ConfigMaps

A ConfigMap stores non-sensitive configuration.

Create:

```bash
kubectl create configmap device-api-config \
  --from-literal=APP_ENV=development \
  --from-literal=LOG_LEVEL=INFO
```

Inspect:

```bash
kubectl get configmap device-api-config \
  -o yaml
```

Use in a Pod:

```yaml
envFrom:
  - configMapRef:
      name: device-api-config
```

Or use one key:

```yaml
env:
  - name: LOG_LEVEL
    valueFrom:
      configMapKeyRef:
        name: device-api-config
        key: LOG_LEVEL
```

ConfigMaps are not intended for passwords or private keys.

---

# 57. Kubernetes Secrets

A Secret stores small amounts of sensitive data such as passwords, tokens, or keys, allowing that data to remain separate from Pod definitions and container images. ([Kubernetes](https://kubernetes.io/docs/concepts/configuration/secret/?utm_source=chatgpt.com "Secrets"))

Create:

```bash
kubectl create secret generic database-credentials \
  --from-literal=username=device_app \
  --from-literal=password=development-password
```

Inspect metadata:

```bash
kubectl get secret database-credentials
```

Do not casually display:

```bash
kubectl get secret database-credentials \
  -o yaml
```

because the values can be decoded.

Base64 encoding is not encryption.

Production clusters need:

- Proper authorization
    
- Encryption at rest where required
    
- Restricted API access
    
- Secret rotation
    
- External secret-management integration where appropriate
    

Kubernetes’ own guidance recommends careful access restriction and encryption practices for Secrets. ([Kubernetes](https://kubernetes.io/docs/concepts/security/secrets-good-practices/?utm_source=chatgpt.com "Good practices for Kubernetes Secrets"))

---

# 58. Use a Secret as environment variables

Example:

```yaml
env:
  - name: DB_USER
    valueFrom:
      secretKeyRef:
        name: database-credentials
        key: username

  - name: DB_PASSWORD
    valueFrom:
      secretKeyRef:
        name: database-credentials
        key: password
```

This puts the values into the container environment.

Applications and users with appropriate Pod access may still inspect environment-related information.

For many applications, mounting Secrets as files is preferable.

---

# 59. Mount a Secret as files

Example:

```yaml
volumes:
  - name: database-credentials
    secret:
      secretName: database-credentials

containers:
  - name: device-api

    volumeMounts:
      - name: database-credentials
        mountPath: /run/secrets/database
        readOnly: true
```

Inside the container:

```text
/run/secrets/database/username
/run/secrets/database/password
```

Your existing application design using:

```text
DB_PASSWORD_FILE
```

transfers naturally to Kubernetes.

---

# 60. Volumes in Kubernetes

Containers in a Pod have disposable writable layers.

Kubernetes volumes provide filesystem data that can be shared by containers in a Pod or persist according to the specific volume type. ([Kubernetes](https://kubernetes.io/docs/concepts/storage/volumes/?utm_source=chatgpt.com "Volumes"))

Common volume types include:

- `emptyDir`
    
- Secret volume
    
- ConfigMap volume
    
- PersistentVolumeClaim
    
- Projected volume
    
- CSI-backed volume
    

## `emptyDir`

Created when a Pod is assigned to a node.

Survives container restarts within the Pod.

Deleted when the Pod is removed.

Use for:

- Temporary files
    
- Shared scratch space
    
- Caches
    
- Generated intermediate data
    

Do not use it for durable database storage.

---

# 61. Persistent storage concepts

Kubernetes persistent storage commonly uses:

```text
PersistentVolume
PersistentVolumeClaim
StorageClass
```

## PersistentVolume

A cluster storage resource.

## PersistentVolumeClaim

An application’s request for storage.

## StorageClass

Describes a storage class and often supports dynamic provisioning.

Dynamic provisioning allows storage to be created on demand from a claim rather than being manually prepared for every application. ([Kubernetes](https://kubernetes.io/docs/concepts/storage/storage-classes/?utm_source=chatgpt.com "Storage Classes"))

The model is:

```text
Application
    ↓ requests
PersistentVolumeClaim
    ↓ bound to
PersistentVolume
    ↓ implemented by
Storage system
```

---

# 62. Create a basic PersistentVolumeClaim

Create `data-pvc.yaml`:

```yaml
apiVersion: v1
kind: PersistentVolumeClaim

metadata:
  name: application-data

spec:
  accessModes:
    - ReadWriteOnce

  resources:
    requests:
      storage: 1Gi
```

Apply:

```bash
kubectl apply \
  -f data-pvc.yaml
```

Inspect:

```bash
kubectl get persistentvolumeclaims
```

```bash
kubectl get persistentvolumes
```

Minikube normally provides a default StorageClass capable of provisioning storage for simple local labs.

---

# 63. Use the claim in a Pod template

Example:

```yaml
spec:
  template:
    spec:
      containers:
        - name: application
          image: alpine
          command:
            - sleep
            - "3600"

          volumeMounts:
            - name: application-data
              mountPath: /data

      volumes:
        - name: application-data
          persistentVolumeClaim:
            claimName: application-data
```

The application writes to:

```text
/data
```

The storage lifecycle is separated from the container’s writable layer.

Persistent storage in multi-node production clusters requires careful storage-system design.

---

# 64. Deployment versus StatefulSet

Use a Deployment for stateless workloads:

```text
API
Frontend
Stateless worker
```

Use a StatefulSet when workloads require:

- Stable Pod identities
    
- Stable network identities
    
- Ordered deployment
    
- Ordered scaling
    
- Persistent storage associated with each replica
    

StatefulSets maintain a sticky identity for each managed Pod and are designed for stateful applications requiring stable identity or storage. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/controllers/statefulset/?utm_source=chatgpt.com "StatefulSets"))

Do not conclude:

```text
StatefulSet replicas: 3
=
correct PostgreSQL cluster
```

Database-native replication and failover still need to be designed correctly.

---

# 65. Kubernetes self-healing boundaries

Kubernetes can:

- Restart failed containers
    
- Replace failed Pods
    
- Reschedule workloads from unavailable nodes
    
- Maintain replica counts
    
- Remove unready Pods from Service traffic
    

Kubernetes cannot automatically:

- Repair application logic
    
- Fix incorrect passwords
    
- Repair corrupt database data
    
- Design database replication
    
- Prevent duplicate MQTT processing
    
- Decide whether a schema migration is safe
    
- Understand your business transaction semantics
    

Orchestration handles infrastructure state.

Application correctness remains your responsibility.

---

# 66. Horizontal Pod Autoscaling

Kubernetes can adjust workload replica counts automatically using a HorizontalPodAutoscaler.

The HPA controller works with scalable resources such as Deployments and StatefulSets through their scale interface. ([Kubernetes](https://kubernetes.io/docs/concepts/workloads/autoscaling/horizontal-pod-autoscale/?utm_source=chatgpt.com "Horizontal Pod Autoscaling"))

Conceptually:

```text
CPU or another metric increases
        ↓
HPA increases replicas
        ↓
Metric decreases
        ↓
HPA reduces replicas
```

This normally requires a metrics source, commonly Metrics Server for basic CPU and memory metrics.

You will study autoscaling in a later lesson.

Do not enable autoscaling before defining correct resource requests and validating application behavior with multiple replicas.

---

# 67. Kubernetes YAML for your device API

A simplified Deployment:

```yaml
apiVersion: apps/v1
kind: Deployment

metadata:
  name: device-api

spec:
  replicas: 3

  selector:
    matchLabels:
      app: device-api

  template:
    metadata:
      labels:
        app: device-api

    spec:
      containers:
        - name: device-api

          image: registry.example.com/team/device-api:2.0.0

          ports:
            - name: http
              containerPort: 5000

          env:
            - name: APP_ENV
              value: production

            - name: DB_HOST
              value: database

            - name: DB_PORT
              value: "5432"

            - name: DB_NAME
              value: device_monitor

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

          startupProbe:
            httpGet:
              path: /health
              port: http
            periodSeconds: 5
            failureThreshold: 20

          readinessProbe:
            httpGet:
              path: /health
              port: http
            periodSeconds: 5
            timeoutSeconds: 3

          livenessProbe:
            httpGet:
              path: /health
              port: http
            periodSeconds: 15
            timeoutSeconds: 3

          resources:
            requests:
              cpu: "100m"
              memory: "128Mi"

            limits:
              cpu: "1"
              memory: "512Mi"

      volumes:
        - name: database-credentials
          secret:
            secretName: database-credentials
```

---

# 68. Device API Service

```yaml
apiVersion: v1
kind: Service

metadata:
  name: device-api

spec:
  type: ClusterIP

  selector:
    app: device-api

  ports:
    - name: http
      port: 80
      targetPort: http
```

Other workloads in the namespace connect to:

```text
http://device-api
```

The Service selects Pods using:

```text
app=device-api
```

---

# 69. PostgreSQL service identity

Your API should connect to a Service called:

```text
database
```

Example:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: database

spec:
  type: ClusterIP

  selector:
    app: database

  ports:
    - name: postgres
      port: 5432
      targetPort: postgres
```

The API configuration remains familiar:

```text
DB_HOST=database
DB_PORT=5432
```

The same service-name pattern appeared in:

- Docker Compose
    
- Docker Swarm
    
- Kubernetes
    

---

# 70. Container image access

If your image repository is private, Kubernetes nodes need registry credentials.

Create an image pull Secret:

```bash
kubectl create secret docker-registry registry-credentials \
  --docker-server=registry.example.com \
  --docker-username=REGISTRY_USER \
  --docker-password=REGISTRY_TOKEN
```

Reference it:

```yaml
spec:
  template:
    spec:
      imagePullSecrets:
        - name: registry-credentials
```

Use a pull-only credential where possible.

Do not put the registry password directly in the Deployment YAML.

---

# 71. Image tags and pull policy

Example:

```yaml
image: registry.example.com/team/device-api:2.0.0
imagePullPolicy: IfNotPresent
```

Common policies:

```text
Always
IfNotPresent
Never
```

For production releases:

- Use immutable version tags or digests.
    
- Do not rely only on `latest`.
    
- Record the deployed digest.
    

Strongest identity:

```yaml
image: registry.example.com/team/device-api@sha256:EXACT_DIGEST
```

Kubernetes deployment does not replace the release discipline you learned on Days 18–20.

---

# 72. Basic troubleshooting workflow

Use this order.

## Check nodes

```bash
kubectl get nodes
```

## Check workload objects

```bash
kubectl get deployments
kubectl get replicasets
kubectl get pods
```

## Show detailed Pod placement and IPs

```bash
kubectl get pods -o wide
```

## Describe failing Pod

```bash
kubectl describe pod POD_NAME
```

## Read logs

```bash
kubectl logs POD_NAME
```

## Read previous crash logs

```bash
kubectl logs \
  --previous \
  POD_NAME
```

## Inspect Service

```bash
kubectl describe service SERVICE_NAME
```

## Inspect endpoints

```bash
kubectl get endpoints
kubectl get endpointslices
```

## Check recent events

```bash
kubectl get events \
  --sort-by=.metadata.creationTimestamp
```

---

# 73. Common Pod states and errors

## `Pending`

Possible causes:

- Image not yet pulled
    
- No node has sufficient resources
    
- Persistent volume unavailable
    
- Placement rules cannot be satisfied
    
- Node unavailable
    

Inspect:

```bash
kubectl describe pod POD_NAME
```

## `ImagePullBackOff`

Possible causes:

- Wrong image name
    
- Tag does not exist
    
- Registry authentication failure
    
- Registry DNS or network failure
    
- Certificate trust problem
    

## `CrashLoopBackOff`

The container repeatedly starts and exits.

Check:

```bash
kubectl logs POD_NAME
kubectl logs --previous POD_NAME
kubectl describe pod POD_NAME
```

## `ErrImagePull`

The runtime could not pull the image.

## `CreateContainerConfigError`

Possible causes:

- Missing Secret
    
- Missing ConfigMap
    
- Invalid volume reference
    
- Invalid container configuration
    

## `OOMKilled`

The container exceeded an effective memory boundary.

---

# 74. Service has no traffic

Symptoms:

```text
Service exists
but requests fail
```

Check selectors:

```bash
kubectl get service day22-web \
  -o yaml
```

Check Pod labels:

```bash
kubectl get pods \
  --show-labels
```

Check endpoints:

```bash
kubectl get endpoints day22-web
```

If endpoints are empty:

```text
Service selector
does not match
ready Pods
```

Also check:

- Container listens on the expected address
    
- `targetPort` matches the container port
    
- Readiness probe succeeds
    
- Client uses the correct namespace and port
    

---

# 75. `kubectl explain`

Use Kubernetes’ built-in schema documentation:

```bash
kubectl explain deployment
```

Go deeper:

```bash
kubectl explain deployment.spec
```

```bash
kubectl explain deployment.spec.template.spec.containers
```

```bash
kubectl explain service.spec.ports
```

This is extremely useful while writing YAML.

Do not rely entirely on copied manifests without understanding their fields.

---

# 76. Useful output formats

Standard table:

```bash
kubectl get pods
```

Wide:

```bash
kubectl get pods -o wide
```

YAML:

```bash
kubectl get deployment day22-web \
  -o yaml
```

JSON:

```bash
kubectl get deployment day22-web \
  -o json
```

Selected value:

```bash
kubectl get pod POD_NAME \
  -o jsonpath='{.status.podIP}'
```

Custom columns:

```bash
kubectl get pods \
  -o custom-columns=NAME:.metadata.name,STATUS:.status.phase,IP:.status.podIP
```

---

# 77. Clean up the lab

Delete the objects:

```bash
kubectl delete \
  -f web-service.yaml
```

```bash
kubectl delete \
  -f web-deployment.yaml
```

```bash
kubectl delete \
  -f data-pvc.yaml
```

Delete ConfigMap and Secret:

```bash
kubectl delete configmap device-api-config
```

```bash
kubectl delete secret database-credentials
```

Delete the namespace:

```bash
kubectl delete namespace docker-course
```

Switch context back to default:

```bash
kubectl config set-context \
  --current \
  --namespace=default
```

Stop Minikube:

```bash
minikube stop
```

Delete the local cluster completely:

```bash
minikube delete
```

Use `delete` only when you intend to remove the local cluster and its local data.

---

# 78. Day 22 practical laboratory

## Exercise 1 — Start Kubernetes

Install:

- `kubectl`
    
- Minikube
    

Start a cluster with the Docker driver.

Verify the node is ready.

## Exercise 2 — Create a namespace

Create:

```text
docker-course
```

Set it as the current namespace.

## Exercise 3 — Create a Deployment

Deploy Nginx with one replica.

Inspect:

- Deployment
    
- ReplicaSet
    
- Pod
    
- Labels
    
- Events
    

## Exercise 4 — Port forwarding

Forward:

```text
localhost:8080
→ Deployment port 80
```

Access it with `curl`.

## Exercise 5 — Scaling

Scale to three replicas.

Delete one Pod.

Observe replacement.

## Exercise 6 — Declarative YAML

Replace imperative configuration with:

```text
web-deployment.yaml
```

Apply and inspect it.

## Exercise 7 — Service

Create a ClusterIP Service.

Access it from a diagnostic Pod using the Service name.

## Exercise 8 — NodePort

Change the Service to NodePort.

Access it through Minikube.

## Exercise 9 — Rolling update

Change the image tag.

Observe:

- New ReplicaSet
    
- New Pods
    
- Old Pods terminating
    
- Rollout status
    

## Exercise 10 — Rollback

Undo the Deployment.

Confirm the old image returns.

## Exercise 11 — Probes

Add:

- Startup probe
    
- Readiness probe
    
- Liveness probe
    

Inspect Pod conditions.

## Exercise 12 — Resources

Add CPU and memory:

- Requests
    
- Limits
    

Inspect node resource allocation.

---

# 79. Day 22 command reference

```bash
# Start local cluster
minikube start --driver=docker

# Check cluster
minikube status
kubectl cluster-info

# List nodes
kubectl get nodes -o wide

# Create namespace
kubectl create namespace docker-course

# Set current namespace
kubectl config set-context \
  --current \
  --namespace=docker-course

# Create Deployment
kubectl create deployment day22-web \
  --image=nginx:alpine

# List workload resources
kubectl get deployments
kubectl get replicasets
kubectl get pods

# Inspect
kubectl describe deployment day22-web
kubectl describe pod POD_NAME

# Logs
kubectl logs POD_NAME
kubectl logs -f POD_NAME
kubectl logs --previous POD_NAME

# Execute command
kubectl exec POD_NAME -- id
kubectl exec -it POD_NAME -- sh

# Scale
kubectl scale deployment day22-web \
  --replicas=3

# Apply manifests
kubectl apply -f web-deployment.yaml
kubectl apply -f web-service.yaml

# Check rollout
kubectl rollout status \
  deployment/day22-web

# Rollout history
kubectl rollout history \
  deployment/day22-web

# Rollback
kubectl rollout undo \
  deployment/day22-web

# Port forwarding
kubectl port-forward \
  deployment/day22-web \
  8080:80

# Inspect Service
kubectl get services
kubectl describe service day22-web
kubectl get endpoints day22-web

# Events
kubectl get events \
  --sort-by=.metadata.creationTimestamp

# Delete manifest resources
kubectl delete -f web-service.yaml
kubectl delete -f web-deployment.yaml

# Stop local cluster
minikube stop

# Delete local cluster
minikube delete
```

---

# 80. Knowledge check

## What is Kubernetes?

A platform for declaratively deploying, scaling, and managing containerized applications.

## What is a node?

A worker machine that runs Pods.

## What is the control plane?

The components that expose the Kubernetes API, maintain cluster state, schedule workloads, and run controllers.

## What is a Pod?

The smallest normal Kubernetes workload unit, containing one or more closely coupled containers.

## Should applications connect directly to Pod IP addresses?

Normally no. Pod identities and IPs are disposable. Use Services.

## What is a Deployment?

A controller that declaratively manages replica Pods through ReplicaSets and supports updates and rollback.

## What is a ReplicaSet?

A controller that maintains the required number of matching Pods.

## What is a Service?

A stable network identity and traffic endpoint for selected Pods.

## How does a Service find Pods?

Through label selectors.

## What does `kubectl apply` do?

It sends a declarative object definition to the Kubernetes API so the live state can be reconciled with the manifest.

## What is a readiness probe?

A check determining whether a Pod should receive Service traffic.

## What is a liveness probe?

A check determining whether a container should be restarted because it is unhealthy.

## What is a startup probe?

A check that allows a slow application to finish starting before liveness checks take effect.

## What is a resource request?

The resource amount used primarily for scheduling decisions.

## What is a resource limit?

The upper runtime boundary configured for the container.

## What is a ConfigMap?

An object for non-sensitive configuration.

## What is a Secret?

An object for sensitive data such as passwords, tokens, and keys.

## Does base64 make a Secret encrypted?

No.

## What is a PersistentVolumeClaim?

An application request for persistent storage.

## Should PostgreSQL use an ordinary Deployment with three replicas?

Not as a complete database high-availability solution. Database replication and storage semantics need a stateful design.

---

# 81. Day 22 completion challenge

Complete this independently:

1. Install `kubectl`.
    
2. Install Minikube.
    
3. Start Minikube with the Docker driver.
    
4. Verify the cluster and node.
    
5. Inspect the current kubeconfig context.
    
6. Create a namespace.
    
7. Set it as the current namespace.
    
8. Create an Nginx Deployment imperatively.
    
9. Inspect its Deployment.
    
10. Inspect its ReplicaSet.
    
11. Inspect its Pod.
    
12. View the Pod’s labels.
    
13. View the Pod’s events.
    
14. Read its logs.
    
15. Execute `id` inside the Pod.
    
16. Port-forward the Deployment.
    
17. Access it through localhost.
    
18. Scale to three replicas.
    
19. Delete one Pod.
    
20. Observe automatic replacement.
    
21. Recreate the Deployment using YAML.
    
22. Add explicit labels.
    
23. Add a matching selector.
    
24. Add a named container port.
    
25. Create a ClusterIP Service.
    
26. Verify Service endpoints.
    
27. Access the Service from a diagnostic Pod.
    
28. Change the Service to NodePort.
    
29. Access it through Minikube.
    
30. Add a startup probe.
    
31. Add a readiness probe.
    
32. Add a liveness probe.
    
33. Add resource requests.
    
34. Add resource limits.
    
35. Perform a rolling image update.
    
36. Observe both ReplicaSets.
    
37. Monitor rollout status.
    
38. View rollout history.
    
39. Roll back the Deployment.
    
40. Create a ConfigMap.
    
41. Inject a ConfigMap value.
    
42. Create a Secret.
    
43. Mount the Secret as files.
    
44. Confirm the Secret is not part of the image.
    
45. Create a PersistentVolumeClaim.
    
46. Mount it into a test workload.
    
47. Write data to the volume.
    
48. Replace the Pod.
    
49. Confirm the data remains.
    
50. Explain the relationship between Docker images, Pods, Deployments, ReplicaSets, and Services.
    

The central Day 22 model is:

```text
Container image
      ↓ referenced by
Deployment
      ↓ creates and updates
ReplicaSet
      ↓ maintains
Pods
      ↓ selected by
Service
      ↓ provides
Stable network access
```

The most important operational lesson is:

> Pods are disposable application instances. Deployments maintain the required replicas and manage releases, while Services provide stable networking to ready Pods. Define all of these declaratively, use probes and resources correctly, and treat container images, configuration, secrets, and persistent storage as separate lifecycle concerns.