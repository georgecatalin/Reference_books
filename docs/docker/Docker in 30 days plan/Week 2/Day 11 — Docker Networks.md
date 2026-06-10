#### Docker Networks and Container-to-Container Communication

Day 3 introduced basic networking and port publishing:

```text
Host port 8080 → container port 80
```

Today you will go deeper into how containers communicate with each other.

The central lesson is:

> Containers that belong to the same user-defined Docker network can communicate using container names or network aliases instead of fixed IP addresses.

By the end of Day 11, you should understand:

- Docker network drivers
    
- The default bridge network
    
- User-defined bridge networks
    
- Automatic DNS-based service discovery
    
- Container names and network aliases
    
- Internal ports versus published host ports
    
- Network isolation
    
- Connecting and disconnecting running containers
    
- Containers attached to multiple networks
    
- Basic subnet and gateway configuration
    
- Why container IP addresses should rarely be hard-coded
    
- Network troubleshooting with `docker network inspect`, `getent`, `nc`, and `curl`
    

Docker provides several built-in network drivers. For normal multi-container applications on one Docker host, a **user-defined bridge network** is the most important. Docker recommends user-defined bridges over the default bridge because they provide automatic DNS name resolution, stronger isolation, and easier runtime connection and disconnection. ([Docker Documentation](https://docs.docker.com/engine/network/drivers/bridge/?utm_source=chatgpt.com "Bridge network driver"))

---

# 1. Why containers need networks

Consider a system with:

```text
Web dashboard
PostgreSQL database
Mosquitto broker
MQTT consumer
```

These components need to communicate:

```text
Dashboard → PostgreSQL
Consumer → Mosquitto
Consumer → PostgreSQL
External MQTT clients → Mosquitto
Browser → Dashboard
```

Not every component should be exposed to the host or LAN.

A sensible architecture is:

```text
External browser
      ↓ published port
Dashboard container
      ↓ internal Docker network
PostgreSQL container
```

The database needs to be reachable by the dashboard, but it may not need a published host port.

Docker networking allows internal communication without exposing every service externally.

---

# 2. Docker network drivers

List available networks:

```bash
docker network ls
```

A typical Docker Engine installation shows:

```text
NETWORK ID     NAME      DRIVER    SCOPE
...            bridge    bridge    local
...            host      host      local
...            none      null      local
```

Docker Engine provides several network drivers. The most relevant are:

|Driver|Purpose|
|---|---|
|`bridge`|Isolated networking between containers on one Docker host|
|`host`|Container shares the host network namespace|
|`none`|Container receives no external networking|
|`overlay`|Networking between containers across multiple Docker hosts|
|`macvlan`|Gives containers identities on a physical network|
|`ipvlan`|Advanced integration with physical network addressing|

For now, focus on:

```text
bridge
host
none
```

User-defined bridge networks are normally used for multi-container applications running on a single Docker host. Overlay networks are intended for multi-host environments. ([Docker Documentation](https://docs.docker.com/engine/network/?utm_source=chatgpt.com "Networking | Docker Docs"))

---

# 3. The default bridge network

Docker automatically creates a network called:

```text
bridge
```

Inspect it:

```bash
docker network inspect bridge
```

When you start a container without specifying a network:

```bash
docker run -d \
  --name default-web \
  nginx:alpine
```

Docker normally attaches it to the default bridge network.

Inspect the container:

```bash
docker inspect default-web \
  --format '{{json .NetworkSettings.Networks}}'
```

You should see a network named:

```text
bridge
```

The default bridge is created automatically when Docker Engine starts. Containers started without a specific network are normally attached to it. ([Docker Documentation](https://docs.docker.com/engine/network/drivers/bridge/?utm_source=chatgpt.com "Bridge network driver"))

---

# 4. Inspect the default bridge

Run:

```bash
docker network inspect bridge
```

Look for:

- Network name
    
- Driver
    
- Scope
    
- Subnet
    
- Gateway
    
- Connected containers
    
- Network options
    

Extract the subnet and gateway:

```bash
docker network inspect bridge \
  --format '{{range .IPAM.Config}}Subnet={{.Subnet}} Gateway={{.Gateway}}{{end}}'
```

A common result might resemble:

```text
Subnet=172.17.0.0/16 Gateway=172.17.0.1
```

Your values may differ.

The Docker host commonly owns the bridge gateway address, while connected containers receive addresses from the subnet.

---

# 5. Inspect a container IP address

Run:

```bash
docker inspect default-web \
  --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}'
```

You might see:

```text
172.17.0.2
```

This address is assigned dynamically.

Remove and recreate the container:

```bash
docker rm -f default-web
```

```bash
docker run -d \
  --name default-web \
  nginx:alpine
```

Inspect the IP again:

```bash
docker inspect default-web \
  --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}'
```

It may change.

This is why application configuration should not normally hard-code container IP addresses.

---

# 6. Problems with hard-coded container IP addresses

Suppose the dashboard configuration contains:

```text
DB_HOST=172.17.0.3
```

This may work temporarily.

But after:

- Container replacement
    
- Network recreation
    
- Docker daemon changes
    
- Starting containers in a different order
    
- Deployment to another server
    

the database may receive a different IP.

The dashboard then fails.

Better:

```text
DB_HOST=database
```

where `database` is a stable container or service DNS name.

---

# 7. The default bridge has limited name discovery

Containers on the default bridge do not receive the same straightforward automatic name-based discovery that user-defined bridge networks provide.

Docker documentation specifically identifies automatic DNS resolution as a benefit of user-defined bridges. On those networks, containers can resolve one another by container name or alias. ([Docker Documentation](https://docs.docker.com/engine/network/drivers/bridge/?utm_source=chatgpt.com "Bridge network driver"))

You may still see older tutorials using:

```bash
--link
```

Example:

```bash
docker run --link database:db application
```

Treat `--link` as legacy.

Use user-defined networks instead.

---

# 8. Create a user-defined bridge network

Run:

```bash
docker network create day11-network
```

If you do not specify a driver, Docker creates a bridge network by default. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/network/create/?utm_source=chatgpt.com "docker network create"))

Confirm:

```bash
docker network ls \
  --filter name=day11-network
```

Inspect:

```bash
docker network inspect day11-network
```

Extract driver and subnet:

```bash
docker network inspect day11-network \
  --format 'Driver={{.Driver}} {{range .IPAM.Config}}Subnet={{.Subnet}} Gateway={{.Gateway}}{{end}}'
```

---

# 9. Start an internal web server

Run Nginx on the new network:

```bash
docker run -d \
  --name day11-web \
  --network day11-network \
  nginx:alpine
```

Notice:

- No host port was published.
    
- The container is connected to `day11-network`.
    
- Nginx listens on port 80 inside the container.
    

Check:

```bash
docker port day11-web
```

There should be no host mapping.

From the Docker host, this normally will not reach the container through a published port:

```bash
curl http://localhost:80
```

Another host service might answer, but the Nginx container itself was not published.

---

# 10. Connect from another container

Run a temporary curl container on the same network:

```bash
docker run --rm \
  --network day11-network \
  curlimages/curl \
  http://day11-web
```

This should return the Nginx page.

The temporary container resolves:

```text
day11-web
```

using Docker’s embedded DNS service.

The communication path is:

```text
curl container
      ↓ DNS lookup
day11-web
      ↓ resolved container IP
port 80
      ↓
Nginx
```

User-defined bridge networks provide automatic container-name and alias resolution. ([Docker Documentation](https://docs.docker.com/engine/network/drivers/bridge/?utm_source=chatgpt.com "Bridge network driver"))

---

# 11. You did not need a published port

The two containers communicated using:

```text
day11-web:80
```

No host port was required.

This is an essential rule:

```text
Container-to-container communication:
use container DNS name + internal container port
```

Host publishing is only required when a client outside that Docker network must access the service.

---

# 12. Internal port versus host port

Now recreate the web server with a published port:

```bash
docker rm -f day11-web
```

```bash
docker run -d \
  --name day11-web \
  --network day11-network \
  -p 8080:80 \
  nginx:alpine
```

From the Docker host:

```bash
curl http://localhost:8080
```

From another container on the network:

```bash
docker run --rm \
  --network day11-network \
  curlimages/curl \
  http://day11-web:80
```

Do not normally use:

```bash
docker run --rm \
  --network day11-network \
  curlimages/curl \
  http://day11-web:8080
```

Why?

Because:

```text
8080 = host port
80   = container port
```

The destination container listens internally on 80.

---

# 13. The most important networking rule

Memorize this distinction:

```text
Client outside Docker network:
Docker-host-IP:published-host-port
```

Example:

```text
192.168.1.50:8080
```

```text
Client inside same Docker network:
container-name:internal-port
```

Example:

```text
day11-web:80
```

For your MQTT project:

```text
External MQTT daemon:
broker-host-IP:1883
```

```text
Containerized dashboard:
mosquitto:1883
```

---

# 14. Test DNS resolution

Start a diagnostic container:

```bash
docker run -it \
  --name day11-debug \
  --network day11-network \
  alpine \
  sh
```

Inside, install useful tools:

```sh
apk add --no-cache bind-tools curl busybox-extras
```

Resolve the web container:

```sh
nslookup day11-web
```

Or:

```sh
getent hosts day11-web
```

Depending on installed utilities, one or both commands should work.

Test HTTP:

```sh
curl http://day11-web
```

Test the TCP port:

```sh
nc -vz day11-web 80
```

Exit:

```sh
exit
```

The debug container stops but still exists because `--rm` was not used.

---

# 15. Docker DNS is network-scoped

Create another network:

```bash
docker network create isolated-network
```

Start a container on it:

```bash
docker run -d \
  --name isolated-web \
  --network isolated-network \
  nginx:alpine
```

Try to reach `isolated-web` from `day11-network`:

```bash
docker run --rm \
  --network day11-network \
  curlimages/curl \
  --connect-timeout 3 \
  http://isolated-web
```

It should fail because the source container and destination container do not share a network.

User-defined networks create scoped isolation: containers on unrelated networks cannot automatically communicate with each other. ([Docker Documentation](https://docs.docker.com/engine/network/drivers/bridge/?utm_source=chatgpt.com "Bridge network driver"))

---

# 16. Network isolation model

You currently have:

```text
day11-network
├── day11-web
└── temporary curl containers

isolated-network
└── isolated-web
```

Containers in `day11-network` can resolve other members of `day11-network`.

Containers in `isolated-network` can resolve other members of `isolated-network`.

Membership controls connectivity.

A useful architecture is:

```text
frontend-network
├── reverse-proxy
└── dashboard

backend-network
├── dashboard
└── database
```

The reverse proxy cannot access the database because it does not belong to the backend network.

---

# 17. Connect a running container to another network

You can attach a running container to a user-defined network:

```bash
docker network connect \
  day11-network \
  isolated-web
```

Verify:

```bash
docker inspect isolated-web \
  --format '{{json .NetworkSettings.Networks}}'
```

The container should now belong to:

- `isolated-network`
    
- `day11-network`
    

Test from `day11-network`:

```bash
docker run --rm \
  --network day11-network \
  curlimages/curl \
  http://isolated-web
```

It should now work.

Docker supports connecting running containers to user-defined networks. Use `docker network inspect` to verify membership. ([Docker Documentation](https://docs.docker.com/engine/network/drivers/bridge/?utm_source=chatgpt.com "Bridge network driver"))

---

# 18. Disconnect a running container

Disconnect:

```bash
docker network disconnect \
  day11-network \
  isolated-web
```

Verify:

```bash
docker inspect isolated-web \
  --format '{{json .NetworkSettings.Networks}}'
```

Try again:

```bash
docker run --rm \
  --network day11-network \
  curlimages/curl \
  --connect-timeout 3 \
  http://isolated-web
```

It should fail again.

Docker’s `network disconnect` command removes a running container from a network. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/network/disconnect/?utm_source=chatgpt.com "docker network disconnect"))

---

# 19. A container can belong to multiple networks

Start an application container on a frontend network:

```bash
docker network create frontend-network
docker network create backend-network
```

Run:

```bash
docker run -d \
  --name multi-network-app \
  --network frontend-network \
  nginx:alpine
```

Attach it to the backend network:

```bash
docker network connect \
  backend-network \
  multi-network-app
```

Inspect:

```bash
docker inspect multi-network-app \
  --format '{{range $name, $network := .NetworkSettings.Networks}}{{$name}} IP={{$network.IPAddress}}{{println}}{{end}}'
```

The container should have a different IP address on each network.

Conceptually:

```text
multi-network-app
├── frontend-network IP
└── backend-network IP
```

Each network interface gives the container access to a different communication domain.

---

# 20. Practical two-network architecture

A production-style layout might be:

```text
                   frontend-network
                         │
Browser → reverse-proxy ─┼─ dashboard
                             │
                             │ backend-network
                             │
                         PostgreSQL
```

Membership:

|Container|Frontend|Backend|
|---|--:|--:|
|Reverse proxy|Yes|No|
|Dashboard|Yes|Yes|
|PostgreSQL|No|Yes|

The reverse proxy can contact the dashboard.

The dashboard can contact PostgreSQL.

The reverse proxy cannot directly contact PostgreSQL.

This reduces unnecessary connectivity.

---

# 21. Create the network layout manually

Create networks:

```bash
docker network create day11-frontend
docker network create day11-backend
```

Run PostgreSQL on the backend:

```bash
docker run -d \
  --name day11-database \
  --network day11-backend \
  -e POSTGRES_USER=appuser \
  -e POSTGRES_PASSWORD=development-password \
  -e POSTGRES_DB=device_monitor \
  postgres:17
```

Run a diagnostic application container on both networks:

```bash
docker run -d \
  --name day11-application \
  --network day11-frontend \
  alpine \
  sleep 3600
```

Connect it to the backend:

```bash
docker network connect \
  day11-backend \
  day11-application
```

Run Nginx as the frontend proxy placeholder:

```bash
docker run -d \
  --name day11-proxy \
  --network day11-frontend \
  -p 8080:80 \
  nginx:alpine
```

---

# 22. Test connectivity by role

Install tools in the application container:

```bash
docker exec day11-application \
  apk add --no-cache bind-tools busybox-extras
```

From the application, resolve PostgreSQL:

```bash
docker exec day11-application \
  getent hosts day11-database
```

Test the PostgreSQL TCP port:

```bash
docker exec day11-application \
  nc -vz day11-database 5432
```

Test the proxy:

```bash
docker exec day11-application \
  nc -vz day11-proxy 80
```

Because the application belongs to both networks, both should work.

---

# 23. Confirm proxy isolation from database

The proxy belongs only to the frontend network.

The Nginx image may not include diagnostic tools, so use a temporary container sharing the proxy’s network:

```bash
docker run --rm \
  --network day11-frontend \
  alpine \
  sh -c '
    apk add --no-cache busybox-extras >/dev/null &&
    nc -vz -w 3 day11-database 5432
  '
```

This should fail because `day11-database` belongs only to `day11-backend`.

This is intentional isolation.

---

# 24. Container names as DNS names

A container named:

```text
day11-database
```

can normally be resolved by that name on its user-defined network.

Your application configuration could therefore use:

```text
DB_HOST=day11-database
DB_PORT=5432
```

Container names are more stable than dynamic IP addresses, but in larger orchestration environments, service names are preferred.

Docker Compose, which you will study soon, creates networks and service-level DNS discovery automatically. ([Docker Documentation](https://docs.docker.com/compose/how-tos/networking/?utm_source=chatgpt.com "Networking - Docker Compose"))

---

# 25. Network aliases

A container can have additional DNS names on a network.

Start a container with an alias:

```bash
docker run -d \
  --name alias-database \
  --network day11-network \
  --network-alias database \
  --network-alias postgres \
  postgres:17
```

This example would also need PostgreSQL environment variables to run successfully, so use:

```bash
docker rm -f alias-database 2>/dev/null
```

```bash
docker run -d \
  --name alias-database \
  --network day11-network \
  --network-alias database \
  --network-alias postgres \
  -e POSTGRES_PASSWORD=development-password \
  postgres:17
```

From another container:

```bash
docker run --rm \
  --network day11-network \
  alpine \
  getent hosts database
```

Then:

```bash
docker run --rm \
  --network day11-network \
  alpine \
  getent hosts postgres
```

Both aliases should resolve to the same container on that network.

---

# 26. Why aliases are useful

Aliases can separate logical service names from container names.

Container name:

```text
device-monitor-postgres-01
```

Network alias:

```text
database
```

The application uses:

```text
DB_HOST=database
```

If the underlying container name changes, the logical alias can remain stable.

Aliases are network-specific.

A container can be called:

```text
database
```

on one network and use a different alias on another.

---

# 27. Do not depend on IP addresses returned by DNS forever

Docker DNS returns the current address associated with the destination.

Applications should:

- Resolve names when connecting
    
- Reconnect after failures
    
- Avoid storing resolved IPs indefinitely
    
- Handle container replacement
    

For example, if PostgreSQL is replaced:

```text
Old database container IP: 172.20.0.3
New database container IP: 172.20.0.5
```

the DNS name:

```text
database
```

can point to the replacement.

An application that cached the old IP forever might fail.

---

# 28. DNS configuration inside a container

Enter a container:

```bash
docker exec -it day11-application sh
```

Inspect:

```sh
cat /etc/resolv.conf
```

On a user-defined network, you may see Docker’s embedded DNS address:

```text
127.0.0.11
```

The exact file content may vary.

Docker’s embedded DNS handles:

- Container names
    
- Network aliases
    
- Forwarding external DNS requests
    

Exit:

```sh
exit
```

---

# 29. Containers can access the internet

A bridge-network container can normally reach external networks through NAT or masquerading provided by Docker.

Test:

```bash
docker run --rm \
  --network day11-network \
  curlimages/curl \
  https://example.com
```

The path is approximately:

```text
Container
   ↓ bridge network
Docker host
   ↓ NAT/masquerading
External network
```

On Linux, Docker creates firewall rules for bridge networking, isolation, and port publishing. Docker warns against manually modifying Docker-created rules without understanding the consequences. ([Docker Documentation](https://docs.docker.com/engine/network/packet-filtering-firewalls/?utm_source=chatgpt.com "Packet filtering and firewalls"))

---

# 30. Internal networks

Docker can create a bridge network intended to be externally isolated:

```bash
docker network create \
  --internal \
  private-backend
```

Run:

```bash
docker run -d \
  --name private-service \
  --network private-backend \
  nginx:alpine
```

Another container on that network can reach it:

```bash
docker run --rm \
  --network private-backend \
  curlimages/curl \
  http://private-service
```

External internet access from containers on an internal network is restricted.

Test:

```bash
docker run --rm \
  --network private-backend \
  curlimages/curl \
  --connect-timeout 3 \
  https://example.com
```

It should fail or be unreachable.

An internal network is useful for:

- Databases
    
- Internal message queues
    
- Backend-only services
    
- Sensitive application tiers
    

---

# 31. A service can use both internal and external networks

Suppose an application needs:

- Internet access
    
- Database access on an internal network
    

Create:

```bash
docker network create external-app-network
docker network create --internal internal-db-network
```

Run the application on the external network:

```bash
docker run -d \
  --name dual-network-app \
  --network external-app-network \
  alpine \
  sleep 3600
```

Connect it to the database network:

```bash
docker network connect \
  internal-db-network \
  dual-network-app
```

Run the database on the internal network:

```bash
docker run -d \
  --name internal-db \
  --network internal-db-network \
  -e POSTGRES_PASSWORD=development-password \
  postgres:17
```

The application can:

- Reach external networks through `external-app-network`
    
- Reach the database through `internal-db-network`
    

The database remains isolated from ordinary external routing.

---

# 32. Custom subnet configuration

Docker can select a subnet automatically:

```bash
docker network create custom-network
```

You can also specify one:

```bash
docker network create \
  --driver bridge \
  --subnet 172.30.0.0/24 \
  --gateway 172.30.0.1 \
  custom-subnet-network
```

Inspect:

```bash
docker network inspect custom-subnet-network \
  --format '{{json .IPAM.Config}}'
```

Use custom subnets only when needed, for example:

- Avoiding overlap with corporate networks
    
- VPN compatibility
    
- Controlled lab environments
    
- Integration with routing rules
    

Poorly chosen subnets can conflict with:

- VPN routes
    
- LAN addresses
    
- Other Docker networks
    
- Cloud networks
    

---

# 33. Avoid overlapping networks

Suppose your company VPN uses:

```text
172.30.0.0/16
```

Creating a Docker network inside that range may cause ambiguous routing.

Symptoms can include:

- Company systems becoming unreachable
    
- Containers unable to reach VPN resources
    
- Traffic routed to the wrong interface
    
- Intermittent failures
    

Inspect host routes:

```bash
ip route
```

Inspect Docker networks:

```bash
docker network ls
```

Then inspect their subnets:

```bash
docker network inspect NETWORK_NAME \
  --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}'
```

Choose non-overlapping address ranges.

---

# 34. Static container IP addresses

You can assign a static IP on a user-defined network:

```bash
docker network create \
  --subnet 172.31.0.0/24 \
  static-network
```

Then:

```bash
docker run -d \
  --name static-web \
  --network static-network \
  --ip 172.31.0.10 \
  nginx:alpine
```

Inspect:

```bash
docker inspect static-web \
  --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}'
```

Docker supports static address assignment on appropriately configured networks. However, Docker documentation recommends ensuring static addresses do not collide with dynamically assigned ranges. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/network/connect/?utm_source=chatgpt.com "docker network connect"))

---

# 35. Prefer DNS names over static IPs

Static addresses are sometimes necessary for:

- Legacy software
    
- Firewall policies
    
- Special network integration
    
- Controlled infrastructure
    

For ordinary application communication, prefer:

```text
database:5432
```

instead of:

```text
172.31.0.10:5432
```

DNS names are:

- Easier to read
    
- More portable
    
- Less coupled to subnet design
    
- Better for container replacement
    
- Naturally supported by Compose
    

---

# 36. Network-scoped aliases with `docker network connect`

You can add an alias when connecting a container:

```bash
docker network connect \
  --alias backend-api \
  day11-network \
  multi-network-app
```

Resolve it:

```bash
docker run --rm \
  --network day11-network \
  alpine \
  getent hosts backend-api
```

Inspect:

```bash
docker inspect multi-network-app \
  --format '{{json .NetworkSettings.Networks}}'
```

Aliases exist within the network where they were assigned.

---

# 37. The `none` network driver

Run:

```bash
docker run --rm -it \
  --network none \
  alpine \
  sh
```

Inside:

```sh
ip address
```

You should normally see only:

```text
lo
```

the loopback interface.

Try:

```sh
ping -c 1 8.8.8.8
```

It should fail.

Try:

```sh
wget https://example.com
```

It should fail.

The `none` driver creates a container with only loopback networking, providing complete network isolation. ([Docker Documentation](https://docs.docker.com/engine/network/drivers/none/?utm_source=chatgpt.com "None network driver"))

Use cases include:

- Offline processing
    
- Security-sensitive jobs
    
- Testing network failure
    
- Applications that should not communicate externally
    

---

# 38. The `host` network driver

On native Linux, this command:

```bash
docker run --rm \
  --network host \
  nginx:alpine
```

makes the container share the host’s network namespace.

Consequences:

- No separate container IP
    
- No normal `-p` translation needed
    
- The application binds directly to host ports
    
- Port conflicts occur directly with host services
    

Docker documents host networking as sharing the host network rather than providing normal container network isolation. It is supported by Docker Engine on Linux; recent Docker Desktop versions also provide optional support. ([Docker Documentation](https://docs.docker.com/engine/network/drivers/?utm_source=chatgpt.com "Network drivers | Docker Docs"))

---

# 39. Host networking example

First confirm host port 8085 is free:

```bash
sudo ss -lntp | grep ':8085'
```

Nginx normally listens on port 80, so host networking would attempt to use host port 80.

A clearer test uses Python:

```bash
docker run --rm \
  --network host \
  python:3.13-slim \
  python -m http.server 8085
```

From another terminal:

```bash
curl http://localhost:8085
```

The server binds directly to the host network.

Do not add:

```bash
-p 8085:8085
```

because port publishing is not meaningful in the normal way with host networking.

---

# 40. When host networking is appropriate

Possible uses:

- Network diagnostic tools
    
- Performance-sensitive specialized workloads
    
- Applications requiring many dynamic ports
    
- Software that must observe host interfaces
    
- Certain monitoring tools
    

Disadvantages:

- Reduced network isolation
    
- Direct host port conflicts
    
- Less portability
    
- More security exposure
    
- Different behavior across platforms
    

Use bridge networks by default.

Use host networking only for a clear reason.

---

# 41. Published ports and bridge networks

A container on a bridge network can publish ports:

```bash
docker run -d \
  --name published-web \
  --network day11-network \
  -p 8088:80 \
  nginx:alpine
```

This provides two access paths:

```text
Inside day11-network:
published-web:80
```

```text
Outside day11-network:
Docker-host:8088
```

Publishing does not replace internal networking.

It adds an external entry point.

---

# 42. Binding published ports to localhost

For host-only access:

```bash
docker run -d \
  --name local-web \
  --network day11-network \
  -p 127.0.0.1:8089:80 \
  nginx:alpine
```

Host access:

```bash
curl http://127.0.0.1:8089
```

Other containers still use:

```text
local-web:80
```

A localhost-only host binding can reduce exposure while preserving internal container connectivity.

---

# 43. DNS does not replace application readiness

A container name can resolve before the application is ready.

Example sequence:

```text
Database container starts
      ↓
DNS entry exists
      ↓
PostgreSQL still initializing
      ↓
Application connects too early
      ↓
Connection refused
```

Network connectivity and application readiness are separate.

Your application should:

- Retry failed connections
    
- Use sensible timeouts
    
- Handle temporary dependency outages
    
- Avoid crashing permanently after one connection failure
    

Health checks and startup dependencies will be covered later.

---

# 44. “Connection refused” versus “name not found”

These errors indicate different problems.

## Name resolution failure

Example:

```text
Could not resolve host: database
```

Likely causes:

- Containers do not share a network
    
- Wrong container or alias name
    
- Destination container is not attached
    
- DNS configuration problem
    

## Connection refused

Example:

```text
Connection refused
```

Meaning:

- DNS probably resolved
    
- Routing probably worked
    
- Nothing is listening on the requested port
    
- Application may still be starting
    
- Wrong internal port was used
    
- Application listens only on loopback
    

## Timeout

Example:

```text
Connection timed out
```

Possible causes:

- Firewall or filtering
    
- Routing problem
    
- Unresponsive service
    
- Network isolation
    
- Incorrect address
    

Distinguishing these errors speeds up troubleshooting.

---

# 45. Application listening address still matters

Suppose a service listens only on:

```text
127.0.0.1:5000
```

inside its container.

Another container attempts:

```text
application:5000
```

The request reaches the application container’s network interface, not its loopback interface.

The connection fails.

The application should usually listen on:

```text
0.0.0.0:5000
```

for container network access.

Docker networking cannot make an application accept traffic on an interface where it is not listening.

---

# 46. Troubleshooting container networking systematically

Use this order.

## Step 1: Are both containers running?

```bash
docker ps -a
```

## Step 2: What networks are attached?

```bash
docker inspect CONTAINER \
  --format '{{json .NetworkSettings.Networks}}'
```

## Step 3: Do the containers share a network?

```bash
docker network inspect NETWORK
```

## Step 4: Does DNS resolve?

```bash
docker exec SOURCE_CONTAINER \
  getent hosts DESTINATION_NAME
```

## Step 5: Is the destination port reachable?

```bash
docker exec SOURCE_CONTAINER \
  nc -vz DESTINATION_NAME PORT
```

## Step 6: Does the protocol work?

For HTTP:

```bash
docker exec SOURCE_CONTAINER \
  curl -v http://DESTINATION_NAME:PORT
```

## Step 7: Is the destination application listening correctly?

```bash
docker exec DESTINATION_CONTAINER \
  ss -lntp
```

## Step 8: Check logs

```bash
docker logs DESTINATION_CONTAINER
```

---

# 47. Create a reusable diagnostic container

Run:

```bash
docker run -it \
  --name network-debug \
  --network day11-network \
  nicolaka/netshoot
```

A networking-focused image may provide tools such as:

- `curl`
    
- `dig`
    
- `nslookup`
    
- `ping`
    
- `nc`
    
- `tcpdump`
    
- `ip`
    
- `ss`
    
- `traceroute`
    

Only use diagnostic images from sources you trust.

Alternatively, use Alpine and install only the tools needed:

```bash
docker run --rm -it \
  --network day11-network \
  alpine \
  sh
```

Inside:

```sh
apk add --no-cache bind-tools curl busybox-extras iproute2
```

---

# 48. Inspect a network’s connected containers

Run:

```bash
docker network inspect day11-network \
  --format '{{json .Containers}}'
```

For a readable form:

```bash
docker network inspect day11-network \
  --format '{{range $id, $container := .Containers}}Name={{$container.Name}} IPv4={{$container.IPv4Address}} IPv6={{$container.IPv6Address}}{{println}}{{end}}'
```

This shows:

- Container name
    
- Endpoint ID
    
- MAC address
    
- IPv4 address
    
- IPv6 address where configured
    

`docker network inspect` provides detailed network configuration and connected-container information. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/network/inspect/?utm_source=chatgpt.com "docker network inspect"))

---

# 49. Inspect from the container side

Run:

```bash
docker inspect day11-web \
  --format '{{range $name, $network := .NetworkSettings.Networks}}Network={{$name}} IP={{$network.IPAddress}} Gateway={{$network.Gateway}} Aliases={{json $network.Aliases}}{{println}}{{end}}'
```

This shows the network view for one container.

Use:

```text
docker network inspect
```

when starting from the network.

Use:

```text
docker inspect CONTAINER
```

when starting from the container.

---

# 50. Removing a network

Try removing a network that still has containers attached:

```bash
docker network rm day11-network
```

Docker should refuse.

Disconnect or remove attached containers first.

Docker requires containers to be disconnected before a network can be removed. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/network/rm/?utm_source=chatgpt.com "docker network rm"))

Find members:

```bash
docker network inspect day11-network \
  --format '{{range .Containers}}{{.Name}}{{println}}{{end}}'
```

Remove or disconnect them, then:

```bash
docker network rm day11-network
```

---

# 51. Pruning unused networks

List networks:

```bash
docker network ls
```

Remove unused custom networks:

```bash
docker network prune
```

Docker does not remove the built-in networks:

```text
bridge
host
none
```

Use pruning carefully in shared environments.

You can filter network listings:

```bash
docker network ls \
  --filter type=custom
```

Docker distinguishes built-in and user-defined networks in network listing filters. ([Docker Documentation](https://docs.docker.com/reference/cli/docker/network/ls/?utm_source=chatgpt.com "docker network ls"))

---

# 52. Practical MQTT network design

A containerized MQTT platform could use:

```text
mqtt-frontend
├── dashboard
└── reverse-proxy

mqtt-backend
├── dashboard
├── mqtt-consumer
├── mosquitto
└── database
```

Possible communication:

```text
Reverse proxy → dashboard:5000
Dashboard → database:5432
Consumer → mosquitto:1883
Consumer → database:5432
External devices → Docker-host:1883
Browser → Docker-host:443
```

Only these ports may need host publishing:

```text
443  → reverse proxy
1883 → Mosquitto
8883 → Mosquitto TLS
```

PostgreSQL can remain internal.

---

# 53. Example MQTT network commands

Create networks:

```bash
docker network create mqtt-frontend
docker network create --internal mqtt-backend
```

Run Mosquitto:

```bash
docker run -d \
  --name mosquitto \
  --network mqtt-backend \
  -p 1883:1883 \
  eclipse-mosquitto:2
```

Run PostgreSQL:

```bash
docker run -d \
  --name database \
  --network mqtt-backend \
  -e POSTGRES_PASSWORD=development-password \
  postgres:17
```

Run dashboard on frontend:

```bash
docker run -d \
  --name dashboard \
  --network mqtt-frontend \
  -p 8080:5000 \
  device-dashboard:1.0.0
```

Connect dashboard to backend:

```bash
docker network connect \
  mqtt-backend \
  dashboard
```

Runtime configuration:

```text
DB_HOST=database
DB_PORT=5432
MQTT_HOST=mosquitto
MQTT_PORT=1883
```

---

# 54. Why not publish PostgreSQL?

Publishing:

```bash
-p 5432:5432
```

is unnecessary when only internal containers need the database.

Without publishing:

```text
Dashboard → database:5432
Consumer → database:5432
```

still works through the backend Docker network.

Benefits:

- Smaller attack surface
    
- Fewer host port conflicts
    
- Cleaner architecture
    
- Less accidental LAN exposure
    

Publish only what external clients need.

---

# 55. Why service names are better than `localhost`

Inside the dashboard container:

```text
DB_HOST=localhost
```

means:

```text
Look for PostgreSQL inside the dashboard container itself.
```

Correct:

```text
DB_HOST=database
```

Inside the consumer container:

```text
MQTT_HOST=localhost
```

means:

```text
Look for Mosquitto inside the consumer container.
```

Correct:

```text
MQTT_HOST=mosquitto
```

Every container has its own loopback interface.

---

# 56. Day 11 practical laboratory

## Exercise 1 — Create a custom bridge

Create:

```text
day11-lab-network
```

Inspect:

- Driver
    
- Subnet
    
- Gateway
    

---

## Exercise 2 — DNS discovery

Run Nginx named:

```text
lab-web
```

on the network.

Run a temporary curl container on the same network.

Access:

```text
http://lab-web
```

---

## Exercise 3 — No host publishing

Confirm:

```bash
docker port lab-web
```

shows no host mapping.

Explain why another container can still reach it.

---

## Exercise 4 — Publish the service

Recreate Nginx with:

```text
host 9080 → container 80
```

Test:

```text
Host: localhost:9080
Container: lab-web:80
```

---

## Exercise 5 — Isolation

Create a second network.

Run another web container on it.

Confirm containers on the first network cannot resolve or reach the second web container.

---

## Exercise 6 — Dynamic attachment

Connect the second web container to the first network.

Confirm communication works.

Disconnect it.

Confirm communication fails again.

---

## Exercise 7 — Network alias

Run PostgreSQL or Nginx with aliases:

```text
database
backend
```

Confirm both names resolve.

---

## Exercise 8 — Two-tier architecture

Create:

```text
frontend-network
backend-network
```

Run:

- Proxy only on frontend
    
- Database only on backend
    
- Application on both
    

Confirm the intended communication paths.

---

## Exercise 9 — Internal network

Create an internal backend network.

Confirm containers on it can communicate with one another.

Test whether they can reach an external website.

---

## Exercise 10 — Troubleshooting

Deliberately create these failures:

1. Wrong network
    
2. Wrong DNS name
    
3. Wrong internal port
    
4. Application listening only on loopback
    
5. Destination container stopped
    

For each, identify whether the error is:

- DNS failure
    
- Connection refused
    
- Timeout
    

---

# 57. Day 11 command reference

```bash
# List networks
docker network ls

# Create a user-defined bridge
docker network create NETWORK

# Create an internal network
docker network create \
  --internal \
  NETWORK

# Create a custom subnet
docker network create \
  --driver bridge \
  --subnet 172.30.0.0/24 \
  --gateway 172.30.0.1 \
  NETWORK

# Inspect a network
docker network inspect NETWORK

# Run a container on a network
docker run \
  --network NETWORK \
  IMAGE

# Add a network alias
docker run \
  --network NETWORK \
  --network-alias ALIAS \
  IMAGE

# Connect a running container
docker network connect \
  NETWORK \
  CONTAINER

# Connect with an alias
docker network connect \
  --alias ALIAS \
  NETWORK \
  CONTAINER

# Disconnect a running container
docker network disconnect \
  NETWORK \
  CONTAINER

# Remove a network
docker network rm NETWORK

# Remove unused custom networks
docker network prune

# Inspect container networks
docker inspect CONTAINER \
  --format '{{json .NetworkSettings.Networks}}'

# Resolve another container
docker exec SOURCE \
  getent hosts DESTINATION

# Test a TCP port
docker exec SOURCE \
  nc -vz DESTINATION PORT

# Test HTTP
docker exec SOURCE \
  curl http://DESTINATION:PORT
```

---

# 58. Knowledge check

## What is a user-defined bridge network?

A Docker-managed isolated network that allows containers on the same host to communicate and resolve one another using names and aliases.

## Why is it better than the default bridge?

It provides automatic DNS name resolution, better isolation, configurable network settings, and runtime connect/disconnect support.

## Do containers need published ports to communicate internally?

No. Containers on the same network use internal ports directly.

## Which port should one container use to reach another?

The destination container’s internal listening port.

## What does `localhost` mean inside a container?

That same container.

## Why should container IP addresses not normally be hard-coded?

They may change when containers or networks are recreated.

## What should be used instead?

Container names, service names, or network aliases.

## Can a container belong to multiple networks?

Yes.

## Why use separate frontend and backend networks?

To limit which services can communicate directly.

## What does an internal network do?

It provides container-to-container communication while restricting external network access.

## What is the `none` driver?

A network mode with only the container’s loopback interface and no normal external networking.

## What is the `host` driver?

A mode where the container shares the Docker host’s network namespace.

## What does “connection refused” usually mean?

The destination was reached, but no application accepted the connection on that port.

## What does “could not resolve host” mean?

DNS name resolution failed, often because the containers do not share a network or the name is wrong.

---

# 59. Day 11 completion challenge

Complete this independently:

1. List all Docker networks.
    
2. Identify the built-in networks.
    
3. Inspect the default bridge.
    
4. Create `challenge-frontend`.
    
5. Create `challenge-backend` as an internal network.
    
6. Start an Nginx container called `challenge-proxy` on the frontend network.
    
7. Publish it on host port 9080.
    
8. Start a PostgreSQL container called `challenge-database` on the backend network.
    
9. Do not publish PostgreSQL.
    
10. Start an Alpine container called `challenge-app` on the frontend.
    
11. Connect `challenge-app` to the backend.
    
12. Confirm it resolves `challenge-proxy`.
    
13. Confirm it resolves `challenge-database`.
    
14. Test TCP port 80 on the proxy.
    
15. Test TCP port 5432 on the database.
    
16. Confirm the proxy network cannot resolve the database.
    
17. Add the alias `database` to PostgreSQL.
    
18. Confirm the application resolves the alias.
    
19. Inspect the application’s IP on both networks.
    
20. Record the two different IP addresses.
    
21. Disconnect the application from the backend.
    
22. Confirm database access fails.
    
23. Reconnect it.
    
24. Confirm access works.
    
25. Run a container with `--network none`.
    
26. Confirm only loopback networking exists.
    
27. Explain why host networking reduces isolation.
    
28. Explain why PostgreSQL should remain unpublished.
    
29. Explain why the application should use `database:5432`, not `localhost:5432`.
    
30. Remove all challenge containers and networks.
    

The central Day 11 model is:

```text
User-defined Docker network
        ↓
Automatic DNS discovery
        ↓
Container name or alias
        ↓
Internal container port
        ↓
Destination service
```

The most important operational lesson is:

> Build application networks around service relationships, not container IP addresses. Use names and aliases for discovery, internal ports for container communication, published ports only for external access, and separate networks to enforce architectural boundaries.