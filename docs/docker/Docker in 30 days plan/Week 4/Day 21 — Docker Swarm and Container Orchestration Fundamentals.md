

Until now, you have managed containers on one Docker host:

```text
Docker host
├── API container
├── PostgreSQL container
├── MQTT broker
└── Named volumes
```

Docker Compose made this reproducible, but the host remained a single point of failure.

If that server stops:

```text
Server failure
    ↓
Docker Engine unavailable
    ↓
All containers unavailable
```

Today you will move from **container management** to **container orchestration**.

The central lesson is:

> An orchestrator manages the desired state of applications across a cluster. You declare how many replicas should run, which image they should use, how they should update, and what resources they need; the orchestrator continuously works to make the actual state match that declaration.

Docker Swarm mode is built into Docker Engine and manages a cluster of Docker daemons as one logical system. Docker recommends continuing with ordinary Compose when you only need a single-host deployment; Swarm is relevant when you intentionally want a clustered runtime. ([Docker Documentation](https://docs.docker.com/engine/swarm/?utm_source=chatgpt.com "Swarm mode"))

---

# 1. Day 21 objectives

By the end of today, you should understand:

- What container orchestration means
    
- Docker Compose versus Docker Swarm
    
- Swarm managers and workers
    
- Nodes, services, tasks, and containers
    
- Desired state reconciliation
    
- Replicated and global services
    
- Initializing a swarm
    
- Joining worker nodes
    
- Swarm join tokens
    
- Creating and inspecting services
    
- Scaling services
    
- Service self-healing
    
- Overlay networks
    
- Service discovery
    
- Routing mesh
    
- Rolling updates
    
- Automatic and manual rollback
    
- Placement constraints
    
- Resource reservations and limits
    
- Draining nodes
    
- Swarm secrets and configs
    
- Deploying a stack from a Compose file
    
- Important Swarm limitations
    
- When Swarm is appropriate
    
- How Swarm relates to your MQTT platform
    

---

# 2. What is container orchestration?

Container orchestration coordinates containers across one or more machines.

Instead of manually doing this:

```text
SSH to server 1
Start API container

SSH to server 2
Start another API container

Configure networking
Configure load balancing
Replace failed containers
Update every instance
Track which version runs where
```

you declare:

```text
Run three API replicas
Use image device-api:2.0.0
Keep them available
Publish port 8080
Update one replica at a time
Roll back if the update fails
```

The orchestrator then manages the details.

Typical orchestration responsibilities include:

```text
Scheduling
Scaling
Networking
Service discovery
Load distribution
Restarting failed workloads
Rolling updates
Rollback
Configuration distribution
Secret distribution
Node maintenance
```

---

# 3. Compose versus Swarm

## Docker Compose

Compose manages a multi-container application, normally on one Docker Engine:

```text
One Docker host
├── api
├── database
└── broker
```

You run:

```bash
docker compose up -d
```

Compose creates ordinary containers.

## Docker Swarm

Swarm manages services across a cluster of Docker Engines:

```text
Swarm cluster
├── manager1
├── worker1
└── worker2
```

You run:

```bash
docker service create ...
```

or:

```bash
docker stack deploy ...
```

Swarm creates and manages **tasks**, each of which runs a container.

Docker’s documentation describes Swarm as the cluster-management and orchestration feature of Docker Engine. ([Docker Documentation](https://docs.docker.com/engine/swarm/?utm_source=chatgpt.com "Swarm mode"))

---

# 4. Container versus service

With ordinary Docker:

```bash
docker run -d nginx
```

you create one container.

With Swarm:

```bash
docker service create \
  --name web \
  --replicas 3 \
  nginx
```

you declare a service whose desired state contains three replicas.

Conceptually:

```text
Service: web
Desired replicas: 3
Image: nginx
        ↓
Task 1 → container
Task 2 → container
Task 3 → container
```

A service is the long-lived declaration.

A task is one scheduled instance of that service.

A container is the runtime process created for a task.

Docker defines a service with properties including image, command, networks, resources, published ports, update policy, and replica count. The swarm manager schedules replica tasks to nodes. ([Docker Documentation](https://docs.docker.com/engine/swarm/how-swarm-mode-works/services/?utm_source=chatgpt.com "How services work"))

---

# 5. Desired state

Desired state is the most important orchestration concept.

Suppose you declare:

```text
Service: api
Replicas: 3
```

The manager continuously compares:

```text
Desired state: 3 running tasks
Actual state: 2 running tasks
```

It then creates another task.

The reconciliation loop is:

```text
Desired state
      ↓
Compare with actual state
      ↓
Create, stop, or replace tasks
      ↓
Compare again
```

You do not normally tell Swarm:

```text
Start one container on worker2.
```

You tell it:

```text
Keep three replicas running.
```

Swarm determines where to schedule them based on node availability, constraints, and resources.

---

# 6. Swarm nodes

A Docker Engine participating in the cluster is called a **node**.

There are two main roles:

```text
Manager node
Worker node
```

## Manager

Managers:

- Maintain cluster state
    
- Accept service-management commands
    
- Schedule tasks
    
- Reconcile desired state
    
- Store Swarm configuration in the Raft log
    
- Manage membership
    
- Issue certificates
    

Cluster-management commands such as `docker service create`, `docker service update`, and `docker stack deploy` must be executed against a manager. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/service/create/?utm_source=chatgpt.com "docker service create"))

## Worker

Workers:

- Receive tasks from managers
    
- Run service containers
    
- Report task status
    

Workers do not normally control cluster state.

A manager can also run service tasks unless you restrict its availability or use placement rules.

---

# 7. Recommended Day 21 laboratory

The ideal lab uses three Linux virtual machines:

```text
manager1
worker1
worker2
```

Example addresses:

```text
manager1: 10.0.0.101
worker1:  10.0.0.102
worker2:  10.0.0.103
```

Each machine needs:

- Docker Engine
    
- Network connectivity
    
- Unique hostname
    
- Synchronized time
    
- Required firewall ports
    
- Compatible Docker versions
    

For a simpler introduction, you can enable Swarm on one machine. You can create services and learn most commands, but you will not observe true multi-node scheduling or node-failure recovery.

---

# 8. Set unique hostnames

On each virtual machine:

```bash
sudo hostnamectl set-hostname manager1
```

```bash
sudo hostnamectl set-hostname worker1
```

```bash
sudo hostnamectl set-hostname worker2
```

Confirm:

```bash
hostname
```

Check IP addresses:

```bash
ip address
```

Test connectivity:

```bash
ping -c 2 10.0.0.102
ping -c 2 10.0.0.103
```

Use stable IP addressing or reliable DNS. Cluster nodes must be able to reach one another consistently.

---

# 9. Swarm network ports

For a multi-node Swarm lab, nodes need appropriate connectivity.

Common Swarm traffic uses:

```text
TCP 2377
→ Cluster management

TCP/UDP 7946
→ Node communication and discovery

UDP 4789
→ Overlay-network data traffic
```

Restrict these ports to trusted cluster networks rather than exposing them broadly.

If encrypted overlay-network traffic is used, remember that the overlay path and firewall configuration must permit the required traffic. Swarm management traffic is encrypted, while application data-plane behavior depends on the network configuration. ([Docker Documentation](https://docs.docker.com/engine/swarm/networking/?utm_source=chatgpt.com "Manage swarm service networks"))

---

# 10. Initialize the Swarm

On `manager1`:

```bash
docker swarm init \
  --advertise-addr 10.0.0.101
```

The advertised address tells other nodes how to contact the manager.

Docker will output something similar to:

```text
Swarm initialized: current node (...) is now a manager.

To add a worker to this swarm, run:

docker swarm join \
  --token SWMTKN-1-... \
  10.0.0.101:2377
```

Docker’s official tutorial initializes a swarm with `docker swarm init --advertise-addr <MANAGER-IP>`. ([Docker Documentation](https://docs.docker.com/engine/swarm/swarm-tutorial/create-swarm/?utm_source=chatgpt.com "Create a swarm"))

---

# 11. Inspect the local Swarm state

Run:

```bash
docker info
```

Look for a section resembling:

```text
Swarm: active
 Is Manager: true
 Nodes: 1
 Managers: 1
```

List nodes:

```bash
docker node ls
```

Expected conceptually:

```text
ID      HOSTNAME   STATUS  AVAILABILITY  MANAGER STATUS
...     manager1   Ready   Active        Leader
```

`docker node ls` is a manager command.

A worker cannot use it to manage the cluster.

---

# 12. Swarm join tokens

Swarm creates separate tokens for:

```text
Workers
Managers
```

Display the worker join command:

```bash
docker swarm join-token worker
```

Display the manager join command:

```bash
docker swarm join-token manager
```

Docker generates distinct worker and manager tokens during swarm initialization. The token presented during `docker swarm join` determines the node’s role. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/swarm/init/?utm_source=chatgpt.com "docker swarm init"))

Treat manager tokens more carefully because they allow new nodes to join the control plane.

Do not place join tokens in:

- Public documentation
    
- Source control
    
- Unprotected tickets
    
- Chat messages
    
- Container images
    

---

# 13. Join worker nodes

On `worker1`, run the command produced by the manager:

```bash
docker swarm join \
  --token WORKER_TOKEN \
  10.0.0.101:2377
```

Repeat on `worker2`:

```bash
docker swarm join \
  --token WORKER_TOKEN \
  10.0.0.101:2377
```

Docker’s tutorial uses `docker swarm join --token ... MANAGER-IP:2377` to add worker nodes. ([Docker Documentation](https://docs.docker.com/engine/swarm/swarm-tutorial/add-nodes/?utm_source=chatgpt.com "Add nodes to the swarm"))

Return to `manager1`:

```bash
docker node ls
```

Expected:

```text
manager1   Ready   Active   Leader
worker1    Ready   Active
worker2    Ready   Active
```

---

# 14. Node status and availability

Important node properties include:

## Status

```text
Ready
Down
Unknown
```

## Availability

```text
Active
Pause
Drain
```

### Active

The node may receive new tasks.

### Pause

Existing tasks continue, but new tasks are not scheduled there.

### Drain

Swarm moves service tasks away from that node where possible.

Node availability is an administrative scheduling control.

---

# 15. Create your first service

On the manager:

```bash
docker service create \
  --name day21-web \
  --replicas 3 \
  nginx:alpine
```

The command returns a service ID.

List services:

```bash
docker service ls
```

Expected conceptually:

```text
NAME        MODE        REPLICAS  IMAGE
day21-web   replicated  3/3       nginx:alpine
```

Creating services is a manager-level cluster operation. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/service/create/?utm_source=chatgpt.com "docker service create"))

---

# 16. Inspect service tasks

Run:

```bash
docker service ps day21-web
```

Example:

```text
NAME            IMAGE          NODE      DESIRED STATE  CURRENT STATE
day21-web.1     nginx:alpine   worker1   Running        Running
day21-web.2     nginx:alpine   worker2   Running        Running
day21-web.3     nginx:alpine   manager1  Running        Running
```

This tells you:

- Task identity
    
- Image
    
- Assigned node
    
- Desired state
    
- Current state
    
- Error information where relevant
    

The manager may distribute replicas across available nodes.

---

# 17. Inspect containers on each node

On `worker1`:

```bash
docker ps
```

On `worker2`:

```bash
docker ps
```

On `manager1`:

```bash
docker ps
```

Each node only lists containers running locally.

Swarm-wide service state is viewed through:

```bash
docker service ps day21-web
```

This distinction is important:

```text
docker ps
→ Local node containers

docker service ps
→ Cluster service tasks
```

---

# 18. Service self-healing

Find one task on `worker1`:

```bash
docker ps
```

Remove its container manually:

```bash
docker rm -f CONTAINER_ID
```

Then, on the manager:

```bash
docker service ps day21-web
```

Swarm should create a replacement task because the desired state remains:

```text
3 replicas
```

The removed task may remain visible in task history as failed or shutdown, while a new task runs.

This demonstrates reconciliation:

```text
Actual replicas: 2
Desired replicas: 3
        ↓
Swarm starts replacement
```

---

# 19. Stop a worker node

On `worker1`:

```bash
sudo systemctl stop docker
```

On the manager:

```bash
docker node ls
```

After failure detection, the worker may appear unavailable.

Inspect:

```bash
docker service ps day21-web
```

Swarm attempts to maintain the desired replica count by scheduling replacements on available nodes.

Restart Docker:

```bash
sudo systemctl start docker
```

The node can rejoin the cluster, but the old replacement decisions are not necessarily reversed immediately.

---

# 20. Scaling a service

Scale to five replicas:

```bash
docker service scale \
  day21-web=5
```

Inspect:

```bash
docker service ls
docker service ps day21-web
```

Scale down:

```bash
docker service scale \
  day21-web=2
```

The `docker service scale` command changes the desired replica count for replicated services; the operation returns before all scheduling work necessarily completes. ([Docker Documentation](https://docs.docker.com/engine/swarm/swarm-tutorial/scale-service/?utm_source=chatgpt.com "Scale the service in the swarm"))

You can also update the replica count with:

```bash
docker service update \
  --replicas 4 \
  day21-web
```

---

# 21. Replicated service mode

A replicated service declares a specific number of tasks:

```bash
docker service create \
  --mode replicated \
  --replicas 3 \
  nginx
```

Use replicated mode for:

- Web applications
    
- APIs
    
- Stateless workers
    
- Message consumers
    
- Processing services
    

Swarm chooses nodes for the replicas.

---

# 22. Global service mode

A global service runs one task on every eligible node:

```bash
docker service create \
  --name node-monitor \
  --mode global \
  alpine \
  sleep 3600
```

If the swarm has three eligible nodes:

```text
manager1 → one task
worker1  → one task
worker2  → one task
```

Use global services for:

- Node monitoring agents
    
- Log collectors
    
- Security agents
    
- Host metrics exporters
    
- Node-local networking components
    

You cannot scale a global service with a numeric replica count because its desired state is determined by eligible nodes. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/service/scale/?utm_source=chatgpt.com "docker service scale"))

---

# 23. Remove a service

Run:

```bash
docker service rm day21-web
```

This removes the service declaration and its tasks.

It is different from removing one underlying container.

Service removal is a manager-level cluster action. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/service/rm/?utm_source=chatgpt.com "docker service rm"))

Remove the global test service:

```bash
docker service rm node-monitor
```

---

# 24. Publish a service port

Create:

```bash
docker service create \
  --name day21-web \
  --replicas 3 \
  --publish published=8080,target=80 \
  nginx:alpine
```

Test from any swarm node:

```bash
curl http://10.0.0.101:8080
curl http://10.0.0.102:8080
curl http://10.0.0.103:8080
```

Swarm’s routing mesh allows each node to accept traffic on a published service port and route it to an active service task, even when that particular node does not run a task for the service. ([Docker Documentation](https://docs.docker.com/engine/swarm/ingress/?utm_source=chatgpt.com "Use Swarm mode routing mesh"))

Conceptually:

```text
Request to worker1:8080
        ↓
Swarm ingress routing mesh
        ↓
Any healthy web task
```

---

# 25. Routing mesh consequences

The routing mesh provides convenient cluster-wide port access:

```text
Every node:8080
→ service day21-web
```

You do not need to know which nodes currently run the tasks.

However, understand:

- Traffic may be forwarded between nodes.
    
- The client may not directly reach the local task.
    
- Source-address behavior may differ from direct host publishing.
    
- External load balancers may still be used.
    
- Production network design needs firewall planning.
    

The routing mesh is useful, but it is not a substitute for understanding your application’s network and load-balancing requirements.

---

# 26. Overlay networks

A bridge network connects containers on one Docker host.

An overlay network connects service tasks across multiple Docker hosts.

Create:

```bash
docker network create \
  --driver overlay \
  day21-backend
```

Inspect:

```bash
docker network inspect day21-backend
```

The overlay driver creates a distributed network spanning multiple Docker daemons, allowing attached service tasks on different hosts to communicate as one logical network. ([Docker Documentation](https://docs.docker.com/engine/network/drivers/overlay/?utm_source=chatgpt.com "Overlay network driver"))

---

# 27. Create services on the overlay network

Create a Redis service:

```bash
docker service create \
  --name cache \
  --network day21-backend \
  redis:7-alpine
```

Create a diagnostic service:

```bash
docker service create \
  --name network-client \
  --network day21-backend \
  alpine \
  sleep 3600
```

Inspect:

```bash
docker service ps cache
docker service ps network-client
```

The tasks may run on different nodes but still share the overlay network.

---

# 28. Service discovery

Swarm services on the same overlay network can use service names.

For example:

```text
cache
```

resolves as the Redis service.

Find the diagnostic task’s container and node:

```bash
docker service ps network-client
```

SSH to that node and enter the local task container:

```bash
docker ps
docker exec -it CONTAINER_ID sh
```

Inside:

```sh
getent hosts cache
```

Or install tools if needed:

```sh
apk add --no-cache busybox-extras
nc -vz cache 6379
```

The service name is the stable identity.

Do not hard-code task container IP addresses.

---

# 29. Virtual IP and DNS behavior

By default, a Swarm service commonly receives a virtual IP on an attached overlay network.

Clients use:

```text
service-name:port
```

Swarm distributes the connection among service tasks.

Another endpoint mode is DNS round robin:

```text
dnsrr
```

where DNS returns task addresses rather than using the service virtual IP.

For most introductory service communication, use the default VIP behavior.

---

# 30. Inspect service details

Run:

```bash
docker service inspect day21-web
```

Readable output:

```bash
docker service inspect \
  --pretty \
  day21-web
```

Inspect selected fields:

```bash
docker service inspect day21-web \
  --format '{{json .Spec}}'
```

Useful information includes:

- Image reference
    
- Replica count
    
- Networks
    
- Published ports
    
- Update policy
    
- Rollback policy
    
- Resources
    
- Placement rules
    
- Environment
    
- Secrets and configs
    

---

# 31. Service logs

View:

```bash
docker service logs day21-web
```

Follow:

```bash
docker service logs -f day21-web
```

Show timestamps:

```bash
docker service logs \
  --timestamps \
  --tail 100 \
  day21-web
```

The service command aggregates logs from service tasks when the configured logging driver supports service log retrieval. The Docker CLI provides service-level log management as part of the Swarm service commands. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/service/?utm_source=chatgpt.com "docker service"))

For robust production observability, use centralized logging rather than depending only on interactive CLI retrieval.

---

# 32. Rolling updates

Suppose the service runs:

```text
nginx:1.26-alpine
```

Create it with an update policy:

```bash
docker service create \
  --name rolling-web \
  --replicas 3 \
  --publish published=8081,target=80 \
  --update-parallelism 1 \
  --update-delay 10s \
  --update-failure-action rollback \
  nginx:1.26-alpine
```

Update:

```bash
docker service update \
  --image nginx:1.27-alpine \
  rolling-web
```

Swarm replaces tasks according to the update policy rather than stopping every replica simultaneously.

Rolling update policy can specify delay and how many tasks to update concurrently. ([Docker Documentation](https://docs.docker.com/engine/swarm/swarm-tutorial/rolling-update/?utm_source=chatgpt.com "Apply rolling updates to a service"))

---

# 33. Update parallelism

This option:

```text
--update-parallelism 1
```

means:

```text
Update one task at a time.
```

With three replicas:

```text
Replica 1 updated
Wait
Replica 2 updated
Wait
Replica 3 updated
```

If set to:

```text
--update-parallelism 2
```

two tasks may update together.

Lower parallelism generally preserves more capacity but makes the rollout slower.

---

# 34. Update delay

This option:

```text
--update-delay 10s
```

means Swarm waits ten seconds between update groups.

This can provide time to observe:

- Task startup
    
- Health
    
- Crash behavior
    
- Error rate
    
- Dependency impact
    

The delay is not itself a guarantee that the application is healthy.

Use health checks and update monitoring.

---

# 35. Update order

Possible update ordering includes:

```text
stop-first
start-first
```

## Stop-first

```text
Stop old task
Start new task
```

This is commonly the default and requires no temporary extra replica.

## Start-first

```text
Start new task
Verify it sufficiently
Stop old task
```

This can reduce interruption but temporarily requires extra resources and may create a period where old and new versions run simultaneously.

Example:

```bash
docker service update \
  --update-order start-first \
  rolling-web
```

Use `start-first` only when the application and published-port strategy support overlapping versions.

---

# 36. Service health checks

Swarm can use an image’s `HEALTHCHECK` or service health-check options.

Example:

```bash
docker service create \
  --name health-web \
  --replicas 3 \
  --health-cmd 'wget -q --spider http://localhost/ || exit 1' \
  --health-interval 10s \
  --health-timeout 3s \
  --health-retries 3 \
  nginx:alpine
```

A task whose container becomes unhealthy can be replaced according to Swarm service behavior.

Health checks should be:

- Fast
    
- Non-destructive
    
- Reliable
    
- Representative of usefulness
    
- Time-bounded
    

---

# 37. Update failure actions

An update policy can define:

```text
pause
continue
rollback
```

Example:

```bash
docker service update \
  --update-failure-action rollback \
  rolling-web
```

The Compose Deploy Specification supports failure actions including pausing or automatically rolling back an update, along with monitoring duration and tolerated failure ratios. ([Docker Documentation](https://docs.docker.com/reference/compose-file/deploy/?utm_source=chatgpt.com "Compose Deploy Specification"))

For important services, automatic rollback can be useful, but it must be tested.

A rollback of application containers does not undo database migrations.

---

# 38. Inspect update status

Run:

```bash
docker service inspect \
  rolling-web \
  --pretty
```

Or:

```bash
docker service inspect rolling-web \
  --format '{{json .UpdateStatus}}'
```

Possible information includes:

- State
    
- Start time
    
- Completion message
    
- Failure message
    

Also inspect task history:

```bash
docker service ps \
  --no-trunc \
  rolling-web
```

Do not rely only on the service showing `3/3`.

Check application functionality and logs.

---

# 39. Manual rollback

Roll back the service:

```bash
docker service rollback rolling-web
```

This reverts the service to its previous configuration.

`docker service rollback` must be run against a manager and restores the preceding service specification. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/service/rollback/?utm_source=chatgpt.com "docker service rollback"))

Inspect:

```bash
docker service ps rolling-web
```

```bash
docker service inspect \
  --pretty \
  rolling-web
```

Remember:

```text
Service rollback
→ previous container configuration

Not:
→ database restoration
→ volume rollback
→ external dependency rollback
```

---

# 40. Resource limits and reservations

A service can declare:

```text
Limit
Reservation
```

## Limit

Maximum resource usage allowed.

## Reservation

Amount used by the scheduler when deciding whether a node has enough capacity.

Example:

```bash
docker service create \
  --name resource-api \
  --replicas 3 \
  --limit-cpu 1 \
  --limit-memory 512M \
  --reserve-cpu 0.25 \
  --reserve-memory 128M \
  nginx:alpine
```

Swarm service definitions support resource constraints and reservations as part of scheduling and runtime control. ([Docker Documentation](https://docs.docker.com/engine/swarm/services/?utm_source=chatgpt.com "Deploy services to a swarm"))

Reservations help prevent the scheduler from placing more declared workload on a node than it should reasonably support.

---

# 41. Placement constraints

Constraints restrict which nodes can run a service.

Examples:

```text
node.role == manager
node.role == worker
node.hostname == worker1
node.labels.storage == ssd
node.labels.environment == production
```

Add a label:

```bash
docker node update \
  --label-add storage=ssd \
  worker1
```

Create a constrained service:

```bash
docker service create \
  --name storage-worker \
  --constraint 'node.labels.storage == ssd' \
  alpine \
  sleep 3600
```

Inspect:

```bash
docker service ps storage-worker
```

It should run only on eligible nodes.

---

# 42. Node labels

Add useful labels:

```bash
docker node update \
  --label-add environment=production \
  worker1
```

```bash
docker node update \
  --label-add database=true \
  worker2
```

Inspect:

```bash
docker node inspect \
  --pretty \
  worker2
```

Use labels for infrastructure properties:

```text
storage=ssd
zone=tirana-a
database=true
gpu=true
environment=production
```

Do not use arbitrary labels without documenting their meaning.

---

# 43. Placement preferences

Constraints are mandatory:

```text
Task must satisfy this.
```

Preferences are best-effort distribution guidance:

```text
Try to spread tasks across zones.
```

For example, spreading replicas across node labels can improve resilience.

Do not assume replicas are automatically distributed perfectly for every failure domain.

Model availability zones, racks, or other failure domains explicitly where relevant.

---

# 44. Drain a node

Before maintaining `worker1`:

```bash
docker node update \
  --availability drain \
  worker1
```

Inspect:

```bash
docker node ls
docker service ps day21-web
```

Swarm removes service tasks from the drained node and creates replacements on active eligible nodes to maintain desired state. ([Docker Documentation](https://docs.docker.com/engine/swarm/swarm-tutorial/drain-node/?utm_source=chatgpt.com "Drain a node on the swarm"))

You can then:

- Reboot the node
    
- Update Docker
    
- Patch the OS
    
- Replace hardware
    
- Diagnose storage
    

Return it to service:

```bash
docker node update \
  --availability active \
  worker1
```

---

# 45. Drain does not migrate ordinary containers

Swarm manages **service tasks**.

It does not automatically reschedule containers created manually with:

```bash
docker run
```

If `worker1` contains:

```text
Swarm service task
Manual docker run container
```

draining affects the service task, not necessarily the manually created container.

Mixing unmanaged production containers with Swarm services makes operations harder to understand.

---

# 46. Persistent data challenge

Stateless web replicas are relatively easy:

```text
API replica 1
API replica 2
API replica 3
```

Any replica can be replaced.

Stateful databases are harder:

```text
PostgreSQL data
```

A Docker named volume is usually local to one Docker node.

If a PostgreSQL task moves from `worker1` to `worker2`, the local volume from `worker1` does not automatically follow it.

This is one of the most important orchestration lessons:

> Scheduling a container on another node is not the same as moving its persistent data.

---

# 47. Stateful-service options

For stateful services, possible approaches include:

- Pinning the service to a node
    
- Using shared or networked storage
    
- Using a storage plugin
    
- Running an external managed database
    
- Implementing database-native replication
    
- Using a clustered database
    
- Using backups and explicit recovery processes
    

For an introductory Swarm deployment, you might pin PostgreSQL:

```text
node.labels.database == true
```

But that does not provide database high availability.

If that node fails, the service may remain unavailable until the node and data are recovered.

---

# 48. Pin PostgreSQL to a labeled node

Label a node:

```bash
docker node update \
  --label-add database=true \
  worker2
```

A stack service could contain:

```yaml
deploy:
  placement:
    constraints:
      - node.labels.database == true
```

This ensures the database task is scheduled only on nodes with that label.

The attached local volume remains associated with the selected node.

This is scheduling control, not data replication.

---

# 49. Manager quorum

Swarm managers use the Raft consensus algorithm to maintain cluster state.

A production Swarm normally uses an odd number of managers, commonly:

```text
1 manager
3 managers
5 managers
```

A one-manager Swarm is simple but has no manager fault tolerance.

With three managers, the cluster can normally tolerate the loss of one manager while retaining quorum.

Avoid making every node a manager merely because it seems more redundant. Managers maintain replicated cluster state and quorum, and their availability must be planned carefully. Docker identifies manager nodes as the critical components that maintain Swarm state. ([Docker Documentation](https://docs.docker.com/engine/swarm/admin_guide/?utm_source=chatgpt.com "Administer and maintain a swarm of Docker Engines"))

---

# 50. Worker versus manager token risk

A worker token permits joining as a worker.

A manager token permits joining the manager control plane.

Rotate a worker token:

```bash
docker swarm join-token \
  --rotate \
  worker
```

Rotate a manager token:

```bash
docker swarm join-token \
  --rotate \
  manager
```

Rotation changes the token for future joins.

It does not automatically remove existing nodes.

If a node is compromised, also remove or demote it as appropriate.

---

# 51. Swarm PKI

Swarm includes a built-in public key infrastructure.

Nodes use mutual TLS to authenticate, authorize, and encrypt control-plane communication. ([Docker Documentation](https://docs.docker.com/engine/swarm/how-swarm-mode-works/pki/?utm_source=chatgpt.com "Manage swarm security with public key infrastructure (PKI)"))

The cluster manages node certificates automatically.

Inspect:

```bash
docker info
```

Swarm security includes:

- Node identities
    
- Certificate issuance
    
- Mutual authentication
    
- Encrypted manager communication
    
- Certificate rotation
    

This does not eliminate the need for:

- Host hardening
    
- Firewall rules
    
- Restricted Docker access
    
- Registry security
    
- Secret management
    
- Application TLS where required
    

---

# 52. Swarm secrets

Create a secret from standard input:

```bash
printf '%s' 'development-password' \
  | docker secret create \
      database-password \
      -
```

List:

```bash
docker secret ls
```

Inspect metadata:

```bash
docker secret inspect database-password
```

The secret value is not displayed by inspection.

Swarm stores secrets in the encrypted Raft log and distributes them only to authorized service tasks. ([Docker Documentation](https://docs.docker.com/engine/swarm/secrets/?utm_source=chatgpt.com "Manage sensitive data with Docker secrets"))

---

# 53. Use a secret in a service

Create:

```bash
docker service create \
  --name secret-demo \
  --secret database-password \
  alpine \
  sh -c '
    test -r /run/secrets/database-password &&
    sleep 3600
  '
```

Inside the service task, the secret appears as:

```text
/run/secrets/database-password
```

Only services granted the secret receive it.

Remove:

```bash
docker service rm secret-demo
```

Remove the secret after no service uses it:

```bash
docker secret rm database-password
```

---

# 54. Secret rotation

Swarm secrets are immutable.

To rotate:

```text
database-password-v1
→ database-password-v2
```

Typical workflow:

1. Create the new secret.
    
2. Update services to use it.
    
3. Confirm services work.
    
4. Remove the old secret from services.
    
5. Delete the old secret.
    

Example:

```bash
printf '%s' 'new-password' \
  | docker secret create \
      database-password-v2 \
      -
```

Then update the relevant services.

Database password rotation must also be coordinated with the database account itself.

---

# 55. Swarm configs

Secrets are for sensitive information.

Configs are for non-sensitive configuration files.

Create:

```bash
docker config create \
  nginx-config \
  ./nginx.conf
```

List:

```bash
docker config ls
```

Swarm configs store non-sensitive configuration outside images and make it available to selected services. ([Docker Documentation](https://docs.docker.com/engine/swarm/configs/?utm_source=chatgpt.com "Store configuration data using Docker Configs"))

Use configs for:

- Web-server configuration
    
- Application settings without secrets
    
- Broker configuration
    
- Feature configuration
    
- Static policy files
    

Do not use configs for passwords or private keys.

---

# 56. Deploy a stack

Instead of several `docker service create` commands, define a stack in a Compose-style file.

Create `stack.yaml`:

```yaml
services:
  web:
    image: nginx:alpine

    ports:
      - target: 80
        published: 8080
        protocol: tcp
        mode: ingress

    networks:
      - frontend

    deploy:
      mode: replicated

      replicas: 3

      update_config:
        parallelism: 1
        delay: 10s
        failure_action: rollback
        order: start-first

      rollback_config:
        parallelism: 1
        delay: 5s
        order: stop-first

      restart_policy:
        condition: on-failure
        delay: 5s
        max_attempts: 3
        window: 30s

      resources:
        limits:
          cpus: "0.50"
          memory: 256M

        reservations:
          cpus: "0.10"
          memory: 64M

networks:
  frontend:
    driver: overlay
```

Deploy:

```bash
docker stack deploy \
  --compose-file stack.yaml \
  day21
```

`docker stack deploy` creates or updates a stack from a Compose-format file and must be run against a manager. ([Docker Documentation](https://docs.docker.com/engine/swarm/stack-deploy/?utm_source=chatgpt.com "Deploy a stack to a swarm"))

---

# 57. Inspect a stack

List stacks:

```bash
docker stack ls
```

List services:

```bash
docker stack services day21
```

List tasks:

```bash
docker stack ps day21
```

The service name receives the stack prefix:

```text
day21_web
```

Inspect:

```bash
docker service inspect \
  --pretty \
  day21_web
```

Test:

```bash
curl http://10.0.0.101:8080
```

---

# 58. Stack image requirement

Every node that may run a task must be able to obtain the service image.

Therefore, use an image in a registry:

```yaml
services:
  api:
    image: registry.example.com/team/device-api:2.0.0
```

Do not depend on an image that exists only in the manager’s local image cache.

Docker’s stack deployment guidance uses a registry so each swarm node can retrieve the application image. ([Docker Documentation](https://docs.docker.com/engine/swarm/stack-deploy/?utm_source=chatgpt.com "Deploy a stack to a swarm"))

If the registry is private, nodes need appropriate credentials or stack deployment must provide registry authentication.

---

# 59. Deploy with registry authentication

Log in on the manager:

```bash
docker login registry.example.com
```

Deploy with:

```bash
docker stack deploy \
  --with-registry-auth \
  --compose-file stack.yaml \
  mqtt-platform
```

`--with-registry-auth` forwards registry authentication information needed for service image pulls.

Use limited-scope registry credentials.

Do not put registry passwords into the stack file.

---

# 60. Stack update

Change:

```yaml
image: registry.example.com/team/device-api:2.0.0
```

to:

```yaml
image: registry.example.com/team/device-api:2.0.1
```

Redeploy:

```bash
docker stack deploy \
  --with-registry-auth \
  --compose-file stack.yaml \
  mqtt-platform
```

Swarm compares the existing service specification with the new stack definition and updates changed services according to their update policy.

Inspect:

```bash
docker stack services mqtt-platform
docker stack ps mqtt-platform
```

---

# 61. Remove a stack

Run:

```bash
docker stack rm day21
```

This removes the services and stack networks created for the stack.

Be careful with persistent data.

Removing a stack does not necessarily imply that every external volume or storage resource should be deleted, but storage behavior depends on how volumes are defined and managed.

Do not experiment with production database storage until you have tested exactly what the stack removal process does in your environment.

---

# 62. Important Compose and Stack differences

A Compose file can be used in different contexts, but not every Compose feature behaves identically with `docker stack deploy`.

Important differences include:

- `build:` is not a cluster build system.
    
- Swarm nodes need registry-accessible images.
    
- `deploy:` settings become important for Swarm.
    
- Development features such as Compose Watch are irrelevant.
    
- Some modern Compose Specification fields may not be supported by stack deployment.
    
- Bind mounts require the host path to exist on every eligible node.
    
- Local volumes are node-local.
    
- `depends_on` does not provide the same startup coordination expected from ordinary Compose workflows.
    
- Application retry behavior remains essential.
    

Always validate the stack in a lab.

---

# 63. Swarm stack for the device API

A simplified stack:

```yaml
services:
  api:
    image: "${API_IMAGE}"

    environment:
      APP_ENV: production
      DB_HOST: database
      DB_PORT: "5432"
      DB_NAME: device_monitor
      DB_USER: device_app
      DB_PASSWORD_FILE: /run/secrets/database-password

    secrets:
      - database-password

    networks:
      - frontend
      - backend

    ports:
      - target: 5000
        published: 8080
        protocol: tcp
        mode: ingress

    healthcheck:
      test:
        - CMD
        - python
        - -c
        - >
          import urllib.request;
          urllib.request.urlopen(
            'http://localhost:5000/health',
            timeout=3
          )
      interval: 10s
      timeout: 5s
      retries: 3
      start_period: 20s

    deploy:
      replicas: 3

      update_config:
        parallelism: 1
        delay: 10s
        failure_action: rollback
        monitor: 30s
        max_failure_ratio: 0.25
        order: start-first

      rollback_config:
        parallelism: 1
        delay: 5s
        order: stop-first

      restart_policy:
        condition: on-failure
        delay: 5s

      resources:
        limits:
          cpus: "1.0"
          memory: 512M

        reservations:
          cpus: "0.25"
          memory: 128M

  database:
    image: postgres:17

    environment:
      POSTGRES_USER: device_app
      POSTGRES_DB: device_monitor
      POSTGRES_PASSWORD_FILE: /run/secrets/database-password

    secrets:
      - database-password

    volumes:
      - postgres-data:/var/lib/postgresql/data

    networks:
      - backend

    deploy:
      replicas: 1

      placement:
        constraints:
          - node.labels.database == true

      restart_policy:
        condition: on-failure

networks:
  frontend:
    driver: overlay

  backend:
    driver: overlay
    internal: true

volumes:
  postgres-data:

secrets:
  database-password:
    external: true
```

This is educational, not a complete highly available PostgreSQL design.

---

# 64. Why only the API has three replicas

The API is designed to be stateless:

```text
Request arrives
    ↓
API reads or writes PostgreSQL
    ↓
Response returned
```

Multiple replicas can share the external database.

PostgreSQL is stateful:

```text
Data files
Transaction log
Database consistency
```

Simply setting:

```yaml
deploy:
  replicas: 3
```

on PostgreSQL would not create a correct replicated database cluster.

Application scaling and database replication are different engineering problems.

---

# 65. MQTT platform Swarm design

A possible architecture:

```text
External MQTT devices
          ↓
Mosquitto service
          ↓
MQTT consumer replicas
          ↓
PostgreSQL
          ↓
API replicas
          ↓
Reverse proxy
```

Potential service modes:

|Service|Suggested mode|
|---|---|
|API|Replicated|
|MQTT consumer|Replicated, if message design supports it|
|Reverse proxy|Replicated or global|
|Node monitoring|Global|
|Mosquitto|Single or properly clustered design|
|PostgreSQL|External or database-native HA design|

Do not scale MQTT consumers blindly.

You must understand:

- Subscription identifiers
    
- Shared subscriptions
    
- QoS
    
- Retained messages
    
- Duplicate delivery
    
- Idempotency
    
- Database locking
    
- Offline detection
    

---

# 66. MQTT consumer scaling

Suppose three consumer replicas all subscribe to:

```text
deviceCluster/+/status/heartbeat
```

With ordinary subscriptions, all three may receive each heartbeat.

That may cause duplicate database processing.

Possible solutions include:

- MQTT shared subscriptions where broker support exists
    
- Idempotent database updates
    
- Consumer-group semantics
    
- Partitioned topics
    
- A queue between broker and workers
    
- One active consumer with failover
    

Container orchestration can start replicas, but it cannot define correct application-level message-processing semantics for you.

---

# 67. Service update example for your API

Update to version `2.1.0`:

```bash
docker service update \
  --image registry.example.com/team/device-api:2.1.0 \
  --update-parallelism 1 \
  --update-delay 10s \
  --update-order start-first \
  --update-failure-action rollback \
  mqtt-platform_api
```

Monitor:

```bash
watch -n 2 \
  'docker service ps mqtt-platform_api'
```

In another terminal:

```bash
while true; do
  curl --silent \
       --output /dev/null \
       --write-out '%{http_code}\n' \
       http://10.0.0.101:8080/health
  sleep 1
done
```

This lets you observe whether requests remain available through the rollout.

---

# 68. Swarm service troubleshooting sequence

## Step 1 — Check nodes

```bash
docker node ls
```

## Step 2 — Check services

```bash
docker service ls
```

## Step 3 — Inspect tasks

```bash
docker service ps \
  --no-trunc \
  SERVICE
```

## Step 4 — Inspect the service

```bash
docker service inspect \
  --pretty \
  SERVICE
```

## Step 5 — Read service logs

```bash
docker service logs \
  --timestamps \
  --tail 200 \
  SERVICE
```

## Step 6 — Identify the task node

```bash
docker service ps SERVICE
```

## Step 7 — Inspect local container on that node

```bash
docker ps -a
docker inspect CONTAINER
docker logs CONTAINER
```

## Step 8 — Verify image pull

```bash
docker image ls
```

## Step 9 — Verify network and ports

```bash
docker network inspect NETWORK
```

## Step 10 — Verify constraints and resources

```bash
docker service inspect SERVICE \
  --format '{{json .Spec.TaskTemplate.Placement}}'
```

---

# 69. Pending tasks

A service may show:

```text
0/3 replicas
```

or tasks in:

```text
Pending
Rejected
Failed
```

Common causes:

- No eligible node satisfies placement constraints
    
- Insufficient reserved CPU or memory
    
- Image cannot be pulled
    
- Registry authentication failure
    
- Required secret or config is missing
    
- Required host bind path is absent
    
- Published port conflict
    
- Unsupported architecture
    
- Node unavailable
    
- Volume or plugin issue
    

Inspect:

```bash
docker service ps \
  --no-trunc \
  SERVICE
```

The `ERROR` field often provides the first useful evidence.

---

# 70. Image-pull failures

Symptoms:

```text
No such image
unauthorized
manifest unknown
no matching manifest
```

Check:

- Registry hostname
    
- Image tag or digest
    
- Registry credentials
    
- Node DNS
    
- TLS trust on every node
    
- Platform support
    
- Whether `--with-registry-auth` was used
    

Remember that the manager having the image locally does not mean workers have it.

Each scheduled node must pull or already possess the image.

---

# 71. Constraint failures

Suppose a service requires:

```text
node.labels.database == true
```

but no active node has that label.

The task remains pending.

Inspect node labels:

```bash
docker node inspect \
  --pretty \
  worker1
```

Add:

```bash
docker node update \
  --label-add database=true \
  worker1
```

The scheduler can then place the task.

---

# 72. Cluster cleanup

Remove training stacks:

```bash
docker stack rm day21
```

Remove remaining services:

```bash
docker service rm \
  day21-web \
  rolling-web \
  cache \
  network-client \
  resource-api \
  storage-worker
```

Remove unused overlay networks after services detach:

```bash
docker network rm day21-backend
```

Remove training secrets and configs:

```bash
docker secret rm database-password
docker config rm nginx-config
```

Do not perform broad pruning on production swarm nodes without understanding what is still required.

---

# 73. Leave the Swarm

On a worker:

```bash
docker swarm leave
```

On a manager that is the final manager or in a disposable lab:

```bash
docker swarm leave --force
```

Do not force a production manager to leave casually.

Manager removal affects cluster quorum and control-plane availability.

For production, follow a planned demotion and removal procedure.

---

# 74. When Docker Swarm is appropriate

Swarm may be appropriate when:

- You already use Docker Engine extensively
    
- You need multi-node service scheduling
    
- You want relatively simple orchestration
    
- You need replicas and rolling updates
    
- You need overlay networking
    
- You need built-in secrets
    
- Your operational team wants a smaller learning curve than Kubernetes
    
- Your application architecture is not extremely complex
    

Swarm may not be the best choice when you need:

- A large cloud-native ecosystem
    
- Complex admission policies
    
- Advanced autoscaling
    
- Extensive operator support
    
- Sophisticated storage orchestration
    
- Broad managed-service integration
    
- Standardized Kubernetes-based platforms
    

The correct tool depends on operational requirements, not popularity alone.

---

# 75. Day 21 practical laboratory

## Exercise 1 — Initialize Swarm

Create:

```text
manager1
worker1
worker2
```

Initialize the manager and join both workers.

Confirm all nodes are `Ready`.

---

## Exercise 2 — Replicated service

Create an Nginx service with three replicas.

Inspect:

```bash
docker service ls
docker service ps
```

Confirm tasks run across nodes.

---

## Exercise 3 — Self-healing

Delete one service task container manually.

Observe Swarm create a replacement.

Explain why deleting the task container did not change the desired replica count.

---

## Exercise 4 — Scaling

Scale from three to five replicas.

Then scale down to two.

Observe task creation and shutdown.

---

## Exercise 5 — Worker failure

Stop Docker on one worker.

Observe node and task status.

Confirm replacement tasks are scheduled elsewhere.

Restart the worker.

---

## Exercise 6 — Routing mesh

Publish Nginx on port 8080.

Send requests to all node IP addresses.

Confirm the service responds through nodes without local tasks.

---

## Exercise 7 — Overlay network

Create an overlay network.

Attach two services.

Confirm service-name resolution across different nodes.

---

## Exercise 8 — Rolling update

Deploy three replicas of an older image version.

Configure:

```text
parallelism: 1
delay: 10s
failure action: rollback
```

Update the image and observe the rollout.

---

## Exercise 9 — Rollback

Perform a manual rollback.

Confirm the service returns to its previous specification.

---

## Exercise 10 — Node draining

Drain one worker.

Observe tasks move.

Return it to active availability.

---

## Exercise 11 — Placement constraint

Label one node:

```text
storage=ssd
```

Deploy a service constrained to that node.

Remove the label and observe scheduling behavior.

---

## Exercise 12 — Stack deployment

Create a stack file with:

- Three Nginx replicas
    
- Overlay network
    
- Rolling update policy
    
- Resource limits
    
- Published port
    

Deploy, inspect, update, and remove the stack.

---

# 76. Day 21 command reference

```bash
# Initialize a swarm
docker swarm init \
  --advertise-addr MANAGER_IP

# Show worker join command
docker swarm join-token worker

# Show manager join command
docker swarm join-token manager

# Join a swarm
docker swarm join \
  --token TOKEN \
  MANAGER_IP:2377

# List nodes
docker node ls

# Inspect a node
docker node inspect \
  --pretty \
  NODE

# Add a node label
docker node update \
  --label-add KEY=VALUE \
  NODE

# Drain a node
docker node update \
  --availability drain \
  NODE

# Reactivate a node
docker node update \
  --availability active \
  NODE

# Create a replicated service
docker service create \
  --name SERVICE \
  --replicas 3 \
  IMAGE

# List services
docker service ls

# List service tasks
docker service ps SERVICE

# Inspect service
docker service inspect \
  --pretty \
  SERVICE

# Scale service
docker service scale \
  SERVICE=5

# Update service image
docker service update \
  --image IMAGE:TAG \
  SERVICE

# Roll back service
docker service rollback SERVICE

# View service logs
docker service logs \
  --tail 100 \
  SERVICE

# Remove service
docker service rm SERVICE

# Create overlay network
docker network create \
  --driver overlay \
  NETWORK

# Create secret
printf '%s' 'value' \
  | docker secret create \
      SECRET_NAME \
      -

# List secrets
docker secret ls

# Create config
docker config create \
  CONFIG_NAME \
  FILE

# Deploy stack
docker stack deploy \
  --with-registry-auth \
  --compose-file stack.yaml \
  STACK

# List stacks
docker stack ls

# List stack services
docker stack services STACK

# List stack tasks
docker stack ps STACK

# Remove stack
docker stack rm STACK
```

---

# 77. Knowledge check

## What is a Swarm?

A cluster of Docker Engines managed as one orchestration system.

## What is a manager node?

A node that maintains cluster state, schedules tasks, and accepts cluster-management commands.

## What is a worker node?

A node that executes tasks assigned by managers.

## What is a service?

A desired-state declaration describing how application tasks should run.

## What is a task?

One scheduled instance of a service that normally runs one container.

## What does desired state mean?

The declared target state, such as three running replicas, that Swarm continuously attempts to maintain.

## What happens if a task container is deleted?

Swarm normally creates a replacement because the desired service replica count remains unchanged.

## What is a replicated service?

A service with a specified number of task replicas.

## What is a global service?

A service with one task on every eligible node.

## What is an overlay network?

A distributed Docker network that allows service tasks on different Docker hosts to communicate.

## What is the routing mesh?

Swarm’s mechanism that lets every node accept connections on a published service port and route them to active tasks. ([Docker Documentation](https://docs.docker.com/engine/swarm/ingress/?utm_source=chatgpt.com "Use Swarm mode routing mesh"))

## What does draining a node do?

It prevents new scheduling and moves managed service tasks away while maintaining desired state. ([Docker Documentation](https://docs.docker.com/engine/swarm/swarm-tutorial/drain-node/?utm_source=chatgpt.com "Drain a node on the swarm"))

## Does Swarm automatically replicate named-volume data?

No. Scheduling and persistent-data replication are separate problems.

## What is a Swarm secret?

Sensitive data stored in the encrypted Swarm Raft state and mounted only into authorized service tasks. ([Docker Documentation](https://docs.docker.com/engine/swarm/secrets/?utm_source=chatgpt.com "Manage sensitive data with Docker secrets"))

## What does `docker stack deploy` do?

It creates or updates a group of Swarm services from a Compose-format stack file. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/stack/deploy/?utm_source=chatgpt.com "docker stack deploy"))

## Does rolling back a service restore the database?

No. It restores the previous service configuration, not database state.

---

# 78. Day 21 completion challenge

Complete this independently:

1. Prepare three Linux Docker hosts.
    
2. Configure unique hostnames and stable addresses.
    
3. Verify node-to-node connectivity.
    
4. Configure only the required Swarm firewall ports.
    
5. Initialize `manager1`.
    
6. Save the worker join command securely.
    
7. Join `worker1`.
    
8. Join `worker2`.
    
9. Confirm all nodes are ready.
    
10. Create a service with three replicas.
    
11. Identify the node running each task.
    
12. Delete one task container manually.
    
13. Confirm Swarm replaces it.
    
14. Scale the service to five replicas.
    
15. Scale it back to two.
    
16. Stop Docker on one worker.
    
17. Observe replacement scheduling.
    
18. Restart the worker.
    
19. Publish the service through the routing mesh.
    
20. Access the service through every node.
    
21. Create an overlay network.
    
22. Attach services on different nodes.
    
23. Verify service-name resolution.
    
24. Create a global monitoring service.
    
25. Confirm one task runs per eligible node.
    
26. Add labels to worker nodes.
    
27. Apply a placement constraint.
    
28. Confirm scheduling follows the constraint.
    
29. Drain one worker.
    
30. Confirm its tasks move elsewhere.
    
31. Return the worker to active status.
    
32. Create a service with CPU and memory reservations.
    
33. Create a service with runtime limits.
    
34. Configure a rolling update.
    
35. Update one task at a time.
    
36. Observe update task history.
    
37. Deploy a deliberately failing version.
    
38. Observe update failure behavior.
    
39. Roll back manually.
    
40. Configure automatic rollback.
    
41. Create a Swarm secret.
    
42. Grant it to one service only.
    
43. Confirm another service cannot access it.
    
44. Create a non-sensitive Swarm config.
    
45. Mount it into a service.
    
46. Build a stack file.
    
47. Deploy the stack.
    
48. Update the stack image.
    
49. Inspect stack services and tasks.
    
50. Remove the stack safely.
    
51. Explain why PostgreSQL cannot be made highly available by merely setting three replicas.
    
52. Design placement and storage rules for your PostgreSQL service.
    
53. Design replica behavior for your MQTT consumers.
    
54. Explain how you will prevent duplicate MQTT message processing.
    
55. Write a Swarm troubleshooting checklist.
    

The central Day 21 model is:

```text
Service definition
      ↓
Desired state
      ↓
Swarm manager
      ↓
Scheduler
      ↓
Tasks across nodes
      ↓
Health and state monitoring
      ↓
Replacement, scaling, update, or rollback
```

The most important operational lesson is:

> Swarm does not manage individual containers as permanent pets. It manages service specifications and disposable tasks. Declare the required replicas, networks, resources, placement, updates, and secrets, then let the orchestrator continually reconcile the cluster toward that desired state.