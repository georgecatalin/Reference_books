#### Services, DNS, Ingress, and NetworkPolicies

Day 23 deployed a complete internal application stack:

```text
API Deployment
      ↓
API Service
      ↓
PostgreSQL Service
      ↓
PostgreSQL StatefulSet
```

The components could communicate inside the cluster, but several important questions remain:

```text
How does traffic actually reach a Pod?
How does Kubernetes find changing Pod addresses?
How do users reach the API from outside the cluster?
How can one hostname route to several Services?
How do we prevent unauthorized Pods from reaching PostgreSQL?
How do we troubleshoot DNS and network failures?
```

Today you will answer those questions.

The central lesson is:

> Kubernetes networking is based on disposable Pod addresses, stable Service identities, cluster DNS, explicit external-entry mechanisms, and optional NetworkPolicies that restrict which workloads may communicate.

Kubernetes networking addresses several different problems: container-to-container communication inside a Pod, Pod-to-Pod communication, Pod-to-Service communication, and external-to-Service communication. ([Kubernetes](https://kubernetes.io/docs/concepts/cluster-administration/networking/?utm_source=chatgpt.com "Cluster Networking"))

---

## 1. Day 24 objectives

By the end of today, you should understand:

- The Kubernetes network model
    
- Pod IP addresses
    
- Service IP addresses
    
- Node IP addresses
    
- Pod-to-Pod communication
    
- Service discovery through DNS
    
- `ClusterIP`, `NodePort`, and `LoadBalancer`
    
- Headless Services
    
- Service selectors and EndpointSlices
    
- `port`, `targetPort`, and `nodePort`
    
- Ingress resources
    
- Ingress controllers
    
- Host-based and path-based routing
    
- TLS termination
    
- Why Ingress is mainly for HTTP and HTTPS
    
- Gateway API at a conceptual level
    
- NetworkPolicy
    
- Default-deny policies
    
- Allowing only API-to-database traffic
    
- DNS requirements under restrictive policies
    
- Egress restrictions
    
- Minikube networking limitations
    
- A systematic networking troubleshooting workflow
    

---

# 2. The Kubernetes networking model

Kubernetes normally gives every Pod its own IP address.

Conceptually:

```text
Node 1
├── Pod A: 10.244.1.10
└── Pod B: 10.244.1.11

Node 2
├── Pod C: 10.244.2.20
└── Pod D: 10.244.2.21
```

The intended model is that Pods can communicate with other Pods using Pod IP addresses without traditional application-level port translation between them.

The actual networking implementation is provided by a cluster network plugin using the Container Network Interface, commonly called CNI. Kubernetes requires a compatible network plugin to implement the cluster network model. ([Kubernetes](https://kubernetes.io/docs/concepts/extend-kubernetes/compute-storage-net/network-plugins/?utm_source=chatgpt.com "Network Plugins"))

Your application should not usually depend directly on Pod IP addresses because:

- Pods are disposable
    
- Recreated Pods may receive new addresses
    
- Scaling creates several Pod addresses
    
- Rolling updates replace old Pods
    
- A Service provides a more stable identity
    

---

# 3. The three important address types

You will frequently encounter three types of addresses.

## Node IP

The IP address of the Kubernetes machine:

```text
192.168.49.2
10.0.0.101
```

Used for:

- Node communication
    
- NodePort access
    
- Cluster administration
    
- External load-balancer targets
    

## Pod IP

Assigned to an individual Pod:

```text
10.244.0.17
```

Used for direct cluster networking.

Pod IPs are temporary identities.

## Service IP

A virtual cluster address assigned to a Service:

```text
10.96.173.28
```

A `ClusterIP` Service provides a stable cluster-scoped virtual IP, and traffic sent to it is distributed to matching backend Pods. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/cluster-ip-allocation/?utm_source=chatgpt.com "Service ClusterIP allocation"))

Inspect all three:

```bash
kubectl get nodes -o wide
kubectl get pods -o wide
kubectl get services
```

---

# 4. Inspect the Day 23 network

Set the namespace:

```bash
kubectl config set-context \
  --current \
  --namespace=device-monitor
```

List Pods with addresses:

```bash
kubectl get pods -o wide
```

Example:

```text
NAME                          IP            NODE
database-0                    10.244.0.20   minikube
device-api-668dd89bcb-2c8fz   10.244.0.23   minikube
device-api-668dd89bcb-g4r9x   10.244.0.24   minikube
device-api-668dd89bcb-v8jkm   10.244.0.25   minikube
```

List Services:

```bash
kubectl get services -o wide
```

Example:

```text
NAME         TYPE        CLUSTER-IP      PORT(S)
database     ClusterIP   10.96.84.15     5432/TCP
device-api   ClusterIP   10.96.173.28    80/TCP
```

Notice:

```text
Three API Pods
→ three Pod IPs

One API Service
→ one stable Service IP
```

---

# 5. Pod-to-Pod communication

Create two temporary Pods:

```bash
kubectl run pod-a \
  --image=alpine \
  --restart=Never \
  -- sleep 3600
```

```bash
kubectl run pod-b \
  --image=nginx:alpine \
  --restart=Never
```

Wait:

```bash
kubectl wait \
  --for=condition=Ready \
  pod/pod-a \
  pod/pod-b \
  --timeout=120s
```

Get Pod B’s IP:

```bash
POD_B_IP="$(
  kubectl get pod pod-b \
    -o jsonpath='{.status.podIP}'
)"
```

Test from Pod A:

```bash
kubectl exec pod-a -- \
  wget -qO- "http://${POD_B_IP}"
```

This demonstrates direct Pod-to-Pod communication.

Now delete Pod B:

```bash
kubectl delete pod pod-b
```

If you recreate it, its address may change:

```bash
kubectl run pod-b \
  --image=nginx:alpine \
  --restart=Never
```

This is why applications should normally use Services rather than recorded Pod IP addresses.

Clean up:

```bash
kubectl delete pod pod-a pod-b
```

---

# 6. How a Service chooses Pods

Your API Service contains a selector:

```yaml
spec:
  selector:
    app.kubernetes.io/name: device-api
    app.kubernetes.io/component: api
```

Your API Pods contain matching labels:

```yaml
metadata:
  labels:
    app.kubernetes.io/name: device-api
    app.kubernetes.io/component: api
```

The relationship is:

```text
Service selector
       ↓ matches
Pod labels
       ↓ creates
Service endpoints
```

Inspect the Service:

```bash
kubectl get service device-api \
  -o yaml
```

Inspect labels:

```bash
kubectl get pods \
  --show-labels
```

Inspect backend addresses:

```bash
kubectl get endpointslices \
  -l kubernetes.io/service-name=device-api
```

EndpointSlices are the scalable Kubernetes API representation of Service backend endpoints.

---

# 7. Break a Service selector deliberately

Edit the Service:

```bash
kubectl patch service device-api \
  --type merge \
  --patch '
spec:
  selector:
    app.kubernetes.io/name: wrong-name
'
```

Inspect:

```bash
kubectl get endpointslices \
  -l kubernetes.io/service-name=device-api
```

The Service should now have no usable application endpoints.

Test through port forwarding:

```bash
kubectl port-forward \
  service/device-api \
  8080:80
```

The request will fail because no Pods match.

Restore the selector:

```bash
kubectl patch service device-api \
  --type merge \
  --patch '
spec:
  selector:
    app.kubernetes.io/name: device-api
    app.kubernetes.io/component: api
'
```

Verify:

```bash
kubectl get endpointslices \
  -l kubernetes.io/service-name=device-api
```

---

# 8. `port`, `targetPort`, and `nodePort`

A Service may contain:

```yaml
ports:
  - name: http
    port: 80
    targetPort: http
```

## `port`

The port clients use on the Service:

```text
device-api:80
```

## `targetPort`

The Pod port to which traffic is sent:

```text
Pod container port 5000
```

Your Deployment defines:

```yaml
ports:
  - name: http
    containerPort: 5000
```

Because `targetPort` is `http`, Kubernetes resolves the named port to 5000.

Flow:

```text
Client
  ↓
device-api:80
  ↓
Service
  ↓
targetPort http
  ↓
Pod:5000
```

## `nodePort`

Used only for a `NodePort` or `LoadBalancer` Service to expose a port on each node:

```text
NodeIP:30080
```

---

# 9. Kubernetes DNS

Kubernetes creates DNS records for Services and Pods. Applications can therefore use stable Service DNS names instead of IP addresses. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/dns-pod-service/?utm_source=chatgpt.com "DNS for Services and Pods"))

Inside the `device-monitor` namespace, the API can reach PostgreSQL through:

```text
database
```

The complete name is generally:

```text
database.device-monitor.svc.cluster.local
```

The parts are:

```text
database
→ Service name

device-monitor
→ Namespace

svc
→ Service DNS domain

cluster.local
→ Common cluster domain
```

The cluster domain may differ in some installations.

---

# 10. Short and fully qualified DNS names

Inside the same namespace:

```text
database
```

From another namespace:

```text
database.device-monitor
```

Fully qualified:

```text
database.device-monitor.svc.cluster.local
```

Create a diagnostic Pod:

```bash
kubectl run dns-test \
  --image=busybox:1.36 \
  --restart=Never \
  -- sleep 3600
```

Resolve the short name:

```bash
kubectl exec dns-test -- \
  nslookup database
```

Resolve the complete name:

```bash
kubectl exec dns-test -- \
  nslookup database.device-monitor.svc.cluster.local
```

Resolve the API:

```bash
kubectl exec dns-test -- \
  nslookup device-api
```

Clean up:

```bash
kubectl delete pod dns-test
```

---

# 11. Inspect Pod DNS configuration

Run:

```bash
kubectl exec \
  "$(kubectl get pod \
      -l app.kubernetes.io/name=device-api \
      -o jsonpath='{.items[0].metadata.name}')" \
  -- cat /etc/resolv.conf
```

You may see:

```text
nameserver 10.96.0.10
search device-monitor.svc.cluster.local svc.cluster.local cluster.local
options ndots:5
```

The search domains allow:

```text
database
```

to be expanded into likely cluster DNS names.

CoreDNS commonly provides Kubernetes cluster DNS in Minikube and many other clusters.

Check it:

```bash
kubectl get pods \
  --namespace kube-system \
  -l k8s-app=kube-dns
```

---

# 12. Debug DNS systematically

When a Service name does not resolve:

```bash
kubectl run dns-debug \
  --image=registry.k8s.io/e2e-test-images/dnsutils:1.3 \
  --restart=Never \
  -- sleep 3600
```

Test:

```bash
kubectl exec dns-debug -- \
  nslookup kubernetes.default
```

Then:

```bash
kubectl exec dns-debug -- \
  nslookup database.device-monitor
```

Inspect DNS Pods:

```bash
kubectl get pods \
  --namespace kube-system \
  -l k8s-app=kube-dns
```

Inspect DNS Service:

```bash
kubectl get service \
  --namespace kube-system \
  kube-dns
```

Inspect logs:

```bash
kubectl logs \
  --namespace kube-system \
  -l k8s-app=kube-dns
```

Kubernetes provides an official DNS troubleshooting workflow because DNS failures can arise from Pod configuration, CoreDNS status, Service definitions, or cluster networking. ([Kubernetes](https://kubernetes.io/docs/tasks/administer-cluster/dns-debugging-resolution/?utm_source=chatgpt.com "Debugging DNS Resolution"))

Clean up:

```bash
kubectl delete pod dns-debug
```

---

# 13. Disable automatic Service environment links

Kubernetes can inject environment variables describing Services into Pods, but DNS is generally the clearer discovery mechanism for modern applications. Kubernetes supports both DNS and environment-variable-based Service discovery. ([Kubernetes](https://kubernetes.io/docs/tutorials/services/connect-applications-service/?utm_source=chatgpt.com "Connecting Applications with Services"))

You can disable Service-link environment injection:

```yaml
spec:
  template:
    spec:
      enableServiceLinks: false
```

Add this to the API Deployment:

```yaml
spec:
  template:
    spec:
      enableServiceLinks: false
```

The API will continue resolving:

```text
database
```

through DNS.

---

# 14. Service types overview

The most important Service types are:

```text
ClusterIP
NodePort
LoadBalancer
ExternalName
```

## ClusterIP

Internal stable endpoint.

```text
API → database
Other applications → API
```

## NodePort

Exposes the Service through a port on each node.

```text
NodeIP:NodePort
```

## LoadBalancer

Requests external load-balancer integration from the infrastructure.

Common in managed cloud clusters and clusters with a load-balancer implementation.

## ExternalName

Provides a DNS alias for an external name.

Services abstract application access and can expose workloads either within the cluster or outside it, depending on Service type. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/service/?utm_source=chatgpt.com "Service"))

---

# 15. Test a NodePort Service

Create `08-api-nodeport.yaml`:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: device-api-nodeport
  namespace: device-monitor

spec:
  type: NodePort

  selector:
    app.kubernetes.io/name: device-api
    app.kubernetes.io/component: api

  ports:
    - name: http
      port: 80
      targetPort: http
      nodePort: 30080
```

Apply:

```bash
kubectl apply \
  -f 08-api-nodeport.yaml
```

Inspect:

```bash
kubectl get service device-api-nodeport
```

With Minikube:

```bash
minikube service \
  --namespace device-monitor \
  device-api-nodeport \
  --url
```

Test the returned URL:

```bash
curl --fail URL/health
```

Depending on the Minikube driver and host operating system, direct `minikube ip` access may or may not behave identically.

---

# 16. Why NodePort is rarely the final web architecture

NodePort is useful for:

- Labs
    
- Simple internal systems
    
- External load-balancer targets
    
- Troubleshooting
    

But it has limitations:

- High numbered external ports
    
- No native hostname routing
    
- No HTTP path routing
    
- No native TLS certificate management
    
- Every exposed Service consumes a node port
    
- External clients must know node addresses
    

For HTTP applications, an Ingress controller or Gateway implementation is generally more suitable.

Delete the training NodePort when finished:

```bash
kubectl delete \
  -f 08-api-nodeport.yaml
```

---

# 17. What is Ingress?

An Ingress is a Kubernetes API object that describes HTTP and HTTPS routing rules from outside the cluster to Services inside the cluster.

It understands web concepts such as:

- Hostnames
    
- URL paths
    
- Backend Services
    
- TLS
    
- Virtual hosting
    

Ingress maps external HTTP or HTTPS traffic to Service backends based on rules. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/ingress/?utm_source=chatgpt.com "Ingress"))

Example:

```text
api.example.com
      ↓
Ingress
      ↓
device-api Service
      ↓
API Pods
```

An Ingress resource alone does nothing unless an Ingress controller is installed and running. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/ingress-controllers/?utm_source=chatgpt.com "Ingress Controllers"))

---

# 18. Ingress resource versus Ingress controller

## Ingress resource

Your declarative routing rules:

```yaml
kind: Ingress
```

It says:

```text
Requests for api.example.test
→ device-api Service
```

## Ingress controller

The software that:

- Watches Ingress resources
    
- Configures a proxy or load balancer
    
- Listens for external traffic
    
- Sends requests to Services
    

Common implementations include:

- ingress-nginx
    
- Traefik
    
- HAProxy
    
- Cloud-provider controllers
    
- Other vendor controllers
    

The relationship is:

```text
Ingress YAML
    ↓ watched by
Ingress controller
    ↓ configures
Reverse proxy or load balancer
    ↓ routes to
Services
```

---

# 19. Enable Minikube Ingress

Enable the addon:

```bash
minikube addons enable ingress
```

Wait for the controller:

```bash
kubectl get pods \
  --namespace ingress-nginx
```

You should eventually see the controller running.

Check:

```bash
kubectl wait \
  --namespace ingress-nginx \
  --for=condition=Ready \
  pod \
  --selector=app.kubernetes.io/component=controller \
  --timeout=180s
```

Minikube’s Ingress addon provides a convenient local Ingress controller for training. The official Minikube tutorial demonstrates using an Ingress resource together with an NGINX Ingress controller. ([Kubernetes](https://v1-32.docs.kubernetes.io/docs/tasks/access-application-cluster/ingress-minikube/?utm_source=chatgpt.com "Set up Ingress on Minikube with the NGINX ..."))

---

# 20. Create your first Ingress

Create `08-api-ingress.yaml`:

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress

metadata:
  name: device-api
  namespace: device-monitor

  labels:
    app.kubernetes.io/name: device-api
    app.kubernetes.io/component: ingress

spec:
  ingressClassName: nginx

  rules:
    - host: device-api.local

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

Apply:

```bash
kubectl apply \
  -f 08-api-ingress.yaml
```

Inspect:

```bash
kubectl get ingress
```

```bash
kubectl describe ingress device-api
```

---

# 21. Configure local hostname resolution

Get Minikube’s IP:

```bash
minikube ip
```

Suppose it returns:

```text
192.168.49.2
```

Add a temporary hosts entry:

```bash
echo "$(minikube ip) device-api.local" \
  | sudo tee -a /etc/hosts
```

Test:

```bash
curl --fail \
  http://device-api.local/health
```

You can also test without editing `/etc/hosts`:

```bash
curl --fail \
  --header 'Host: device-api.local' \
  "http://$(minikube ip)/health"
```

Minikube networking varies by driver and platform. With some Docker-driver environments, `minikube tunnel` or another access method may be required.

---

# 22. Trace the full Ingress request

The request path is:

```text
Browser or curl
      ↓
device-api.local
      ↓ resolves to
Minikube or load-balancer address
      ↓
Ingress controller
      ↓ matches hostname and path
Ingress resource rule
      ↓
Service device-api:80
      ↓
Ready API Pod:5000
```

This is different from Docker host publishing:

```text
HostPort → ContainerPort
```

Kubernetes inserts several stable routing layers:

```text
External entry
→ Ingress controller
→ Service
→ Ready Pod
```

---

# 23. Path-based routing

Suppose you have:

```text
/api
→ API Service

/dashboard
→ Dashboard Service
```

An Ingress can route by path:

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress

metadata:
  name: device-platform
  namespace: device-monitor

spec:
  ingressClassName: nginx

  rules:
    - host: platform.local

      http:
        paths:
          - path: /api
            pathType: Prefix

            backend:
              service:
                name: device-api
                port:
                  name: http

          - path: /dashboard
            pathType: Prefix

            backend:
              service:
                name: dashboard
                port:
                  name: http
```

Be careful: the backend application receives paths according to controller behavior and any rewrite configuration.

If your API only expects:

```text
/health
/api/devices
```

routing it under an additional prefix may require:

- Application awareness
    
- Proxy rewrite rules
    
- An application base path
    
- Separate hostname routing instead
    

---

# 24. Host-based routing

A cleaner structure is often:

```text
api.platform.local
→ API

dashboard.platform.local
→ dashboard
```

Example:

```yaml
spec:
  ingressClassName: nginx

  rules:
    - host: api.platform.local
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: device-api
                port:
                  name: http

    - host: dashboard.platform.local
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: dashboard
                port:
                  name: http
```

This avoids some path-rewriting complications.

---

# 25. `pathType`

Ingress paths require a `pathType`.

Common values:

## `Prefix`

Matches URL path prefixes by path elements.

```yaml
path: /api
pathType: Prefix
```

May match:

```text
/api
/api/devices
/api/devices/15
```

## `Exact`

Matches the exact path:

```yaml
path: /health
pathType: Exact
```

## `ImplementationSpecific`

Behavior depends more heavily on the Ingress controller.

Prefer `Prefix` or `Exact` where they express your intent.

---

# 26. Ingress class

The field:

```yaml
ingressClassName: nginx
```

selects the controller intended to process the Ingress.

List classes:

```bash
kubectl get ingressclasses
```

Example:

```text
NAME    CONTROLLER
nginx   k8s.io/ingress-nginx
```

A cluster may have several controllers:

```text
nginx-public
nginx-internal
cloud-load-balancer
```

An explicit class prevents ambiguous ownership.

---

# 27. TLS termination

Ingress can terminate HTTPS.

The flow becomes:

```text
HTTPS client
      ↓ encrypted
Ingress controller
      ↓ TLS decrypted
HTTP or HTTPS to Service
      ↓
Application Pod
```

Create a local training certificate:

```bash
openssl req \
  -x509 \
  -nodes \
  -newkey rsa:2048 \
  -keyout device-api.key \
  -out device-api.crt \
  -days 30 \
  -subj '/CN=device-api.local/O=Docker Course'
```

Create a TLS Secret:

```bash
kubectl create secret tls \
  device-api-tls \
  --cert=device-api.crt \
  --key=device-api.key
```

Protect the private key:

```bash
chmod 600 device-api.key
```

---

# 28. Add TLS to the Ingress

Update `08-api-ingress.yaml`:

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress

metadata:
  name: device-api
  namespace: device-monitor

spec:
  ingressClassName: nginx

  tls:
    - hosts:
        - device-api.local

      secretName: device-api-tls

  rules:
    - host: device-api.local

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

Apply:

```bash
kubectl apply \
  -f 08-api-ingress.yaml
```

Test the self-signed certificate:

```bash
curl --insecure \
  https://device-api.local/health
```

`--insecure` is acceptable for this self-signed training test, not as a production practice.

---

# 29. Production TLS

In production, use:

- A trusted certificate authority
    
- A managed cloud certificate
    
- An organizational internal CA
    
- An automated certificate controller such as cert-manager
    
- Secure private-key handling
    
- Certificate-expiration monitoring
    

Do not:

- Commit private keys to Git
    
- Build private keys into images
    
- Use self-signed certificates without managed trust
    
- Disable TLS verification in production clients
    

Ingress can expose externally reachable URLs, load balance traffic, terminate TLS, and provide name-based virtual hosting. ([Kubernetes](https://kubernetes.io/docs/reference/kubernetes-api/networking/ingress-v1/?utm_source=chatgpt.com "Ingress"))

---

# 30. Ingress limitations

Ingress is primarily intended for HTTP and HTTPS routing.

It is not a generic solution for every protocol.

Examples:

```text
HTTP API
→ suitable for Ingress

Web dashboard
→ suitable for Ingress

PostgreSQL
→ normally not exposed through standard Ingress

MQTT TCP 1883
→ may require Service LoadBalancer, NodePort,
  controller-specific TCP support, or another gateway

MQTT TLS 8883
→ same consideration
```

Controller-specific extensions may support TCP or UDP, but those are not generic portable Ingress behavior.

---

# 31. Gateway API overview

Gateway API is a newer Kubernetes networking API family designed to provide more expressive and role-oriented traffic management than traditional Ingress.

Conceptually:

```text
GatewayClass
→ infrastructure implementation

Gateway
→ network entry point

HTTPRoute
→ HTTP routing rules

TCPRoute
→ TCP routing where supported

TLSRoute
→ TLS routing where supported
```

You do not need to deploy it today, but recognize that modern Kubernetes networking discussions increasingly include Gateway API.

Ingress remains widely used, but its API is comparatively limited and mostly stable rather than rapidly expanding. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/ingress/?utm_source=chatgpt.com "Ingress"))

---

# 32. What is a NetworkPolicy?

By default, many Kubernetes networks permit broad Pod-to-Pod communication.

A NetworkPolicy selects Pods and declares allowed ingress and/or egress traffic.

NetworkPolicies operate mainly at OSI layer 3 and layer 4:

- IP addressing
    
- TCP
    
- UDP
    
- SCTP
    
- Ports
    

They are not normally HTTP path or application-identity policies.

NetworkPolicy allows control of traffic between Pods and between Pods and external destinations, but enforcement requires a network plugin that supports NetworkPolicy. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/network-policies/?utm_source=chatgpt.com "Network Policies"))

---

# 33. Check whether your cluster supports NetworkPolicy

List Minikube’s networking configuration:

```bash
minikube profile list
```

```bash
kubectl get nodes \
  -o jsonpath='{.items[0].spec.podCIDR}'

echo
```

NetworkPolicy support depends on the CNI implementation.

A policy object may be accepted by the Kubernetes API even when the network plugin does not enforce it.

For reliable NetworkPolicy labs, start Minikube with a policy-capable CNI, for example:

```bash
minikube delete
```

```bash
minikube start \
  --driver=docker \
  --cni=calico
```

This recreates the local cluster and deletes its previous local cluster data, so export anything important first.

---

# 34. NetworkPolicy selection model

A NetworkPolicy uses labels to select Pods:

```yaml
podSelector:
  matchLabels:
    app.kubernetes.io/name: postgres
```

It may then allow traffic from selected sources:

```yaml
from:
  - podSelector:
      matchLabels:
        app.kubernetes.io/name: device-api
```

Meaning:

```text
Selected destination:
PostgreSQL Pods

Allowed source:
device-api Pods
```

NetworkPolicies are additive.

The effective allowed traffic is based on the union of applicable allow rules, while isolation begins when a Pod is selected by a policy for ingress or egress.

---

# 35. Create a default-deny ingress policy

Create `09-default-deny-ingress.yaml`:

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy

metadata:
  name: default-deny-ingress
  namespace: device-monitor

spec:
  podSelector: {}

  policyTypes:
    - Ingress
```

The empty selector:

```yaml
podSelector: {}
```

selects every Pod in the namespace.

No ingress allow rules are defined.

Apply:

```bash
kubectl apply \
  -f 09-default-deny-ingress.yaml
```

This isolates ingress traffic to Pods in the namespace when the CNI enforces NetworkPolicy.

The official Kubernetes NetworkPolicy guide uses default-deny patterns to begin isolating workloads. ([Kubernetes](https://kubernetes.io/docs/tasks/administer-cluster/declare-network-policy/?utm_source=chatgpt.com "Declare Network Policy"))

---

# 36. Observe what default deny breaks

Check:

```bash
kubectl get networkpolicies
```

Your API may become unreachable through the Ingress.

The API may also fail to reach PostgreSQL because the database Pod no longer accepts traffic.

Inspect:

```bash
kubectl get pods
```

```bash
kubectl describe pod \
  "$(kubectl get pod \
      -l app.kubernetes.io/name=device-api \
      -o jsonpath='{.items[0].metadata.name}')"
```

Test API-to-database connectivity with a temporary Pod that carries the API labels:

```bash
kubectl run api-network-test \
  --image=postgres:17 \
  --restart=Never \
  --labels='app.kubernetes.io/name=device-api,app.kubernetes.io/component=api' \
  -- sleep 3600
```

Then:

```bash
kubectl exec api-network-test -- \
  pg_isready \
    -h database \
    -p 5432 \
    -U device_app \
    -d device_monitor
```

The connection should fail until an allow policy exists.

---

# 37. Allow API traffic to PostgreSQL

Create `10-allow-api-to-database.yaml`:

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy

metadata:
  name: allow-api-to-database
  namespace: device-monitor

spec:
  podSelector:
    matchLabels:
      app.kubernetes.io/name: postgres
      app.kubernetes.io/component: database

  policyTypes:
    - Ingress

  ingress:
    - from:
        - podSelector:
            matchLabels:
              app.kubernetes.io/name: device-api
              app.kubernetes.io/component: api

      ports:
        - protocol: TCP
          port: 5432
```

Apply:

```bash
kubectl apply \
  -f 10-allow-api-to-database.yaml
```

Test again:

```bash
kubectl exec api-network-test -- \
  pg_isready \
    -h database \
    -p 5432 \
    -U device_app \
    -d device_monitor
```

It should now succeed.

---

# 38. Prove another Pod is blocked

Create an unlabeled client:

```bash
kubectl run unauthorized-client \
  --image=postgres:17 \
  --restart=Never \
  -- sleep 3600
```

Test:

```bash
kubectl exec unauthorized-client -- \
  pg_isready \
    -h database \
    -p 5432 \
    -U device_app \
    -d device_monitor
```

It should fail under an enforced policy.

This demonstrates:

```text
API Pod
→ PostgreSQL allowed

Unrelated Pod
→ PostgreSQL denied
```

Clean up later:

```bash
kubectl delete pod \
  api-network-test \
  unauthorized-client
```

---

# 39. Allow Ingress controller traffic to the API

Your default deny also blocks traffic arriving from the Ingress controller.

Create `11-allow-ingress-to-api.yaml`:

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy

metadata:
  name: allow-ingress-to-api
  namespace: device-monitor

spec:
  podSelector:
    matchLabels:
      app.kubernetes.io/name: device-api
      app.kubernetes.io/component: api

  policyTypes:
    - Ingress

  ingress:
    - from:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: ingress-nginx

          podSelector:
            matchLabels:
              app.kubernetes.io/component: controller

      ports:
        - protocol: TCP
          port: 5000
```

Apply:

```bash
kubectl apply \
  -f 11-allow-ingress-to-api.yaml
```

Test:

```bash
curl --insecure \
  https://device-api.local/health
```

The exact controller labels may differ. Verify them:

```bash
kubectl get pods \
  --namespace ingress-nginx \
  --show-labels
```

Adjust the policy selector accordingly.

---

# 40. Namespace selector and Pod selector together

This rule:

```yaml
from:
  - namespaceSelector:
      matchLabels:
        kubernetes.io/metadata.name: ingress-nginx

    podSelector:
      matchLabels:
        app.kubernetes.io/component: controller
```

means:

```text
Pod must:
- be in namespace ingress-nginx
and
- have component=controller
```

Be careful with YAML list structure.

These are different:

```yaml
from:
  - namespaceSelector: ...
    podSelector: ...
```

Meaning:

```text
Both conditions
```

Versus:

```yaml
from:
  - namespaceSelector: ...

  - podSelector: ...
```

Meaning:

```text
Either source category
```

Small indentation differences can change security behavior.

---

# 41. Default-deny egress

Create `12-default-deny-egress.yaml`:

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy

metadata:
  name: default-deny-egress
  namespace: device-monitor

spec:
  podSelector: {}

  policyTypes:
    - Egress
```

Apply:

```bash
kubectl apply \
  -f 12-default-deny-egress.yaml
```

This may immediately break:

- DNS resolution
    
- API-to-database traffic
    
- External API calls
    
- Registry access from running workloads
    
- Monitoring exports
    

The application now needs explicit egress permissions.

---

# 42. Allow DNS egress

Create `13-allow-dns-egress.yaml`:

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy

metadata:
  name: allow-dns-egress
  namespace: device-monitor

spec:
  podSelector: {}

  policyTypes:
    - Egress

  egress:
    - to:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: kube-system

          podSelector:
            matchLabels:
              k8s-app: kube-dns

      ports:
        - protocol: UDP
          port: 53

        - protocol: TCP
          port: 53
```

Apply:

```bash
kubectl apply \
  -f 13-allow-dns-egress.yaml
```

Check the actual DNS Pod labels:

```bash
kubectl get pods \
  --namespace kube-system \
  --show-labels \
  -l k8s-app=kube-dns
```

Both UDP and TCP DNS may be needed.

---

# 43. Allow API egress to PostgreSQL

Create `14-allow-api-egress-database.yaml`:

```yaml
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy

metadata:
  name: allow-api-egress-database
  namespace: device-monitor

spec:
  podSelector:
    matchLabels:
      app.kubernetes.io/name: device-api
      app.kubernetes.io/component: api

  policyTypes:
    - Egress

  egress:
    - to:
        - podSelector:
            matchLabels:
              app.kubernetes.io/name: postgres
              app.kubernetes.io/component: database

      ports:
        - protocol: TCP
          port: 5432
```

Apply:

```bash
kubectl apply \
  -f 14-allow-api-egress-database.yaml
```

Now the complete communication requirement is:

```text
API egress policy allows PostgreSQL
+
PostgreSQL ingress policy allows API
=
connection permitted
```

A connection may require both sides’ applicable policies to allow it.

---

# 44. Egress to external systems

Your API may need to contact:

- An MQTT broker
    
- Email services
    
- External APIs
    
- Authentication providers
    
- Monitoring systems
    
- Package or update services
    

A broad temporary rule:

```yaml
egress:
  - {}
```

allows all egress and defeats the purpose of default-deny egress.

A more controlled policy may use:

- Namespace selectors
    
- Pod selectors
    
- `ipBlock`
    
- Specific ports
    
- A controlled egress proxy
    
- CNI-specific domain or identity policies
    

Standard Kubernetes NetworkPolicy does not generally express DNS hostname-based allow rules directly.

---

# 45. `ipBlock`

Example:

```yaml
egress:
  - to:
      - ipBlock:
          cidr: 10.0.0.105/32

    ports:
      - protocol: TCP
        port: 1883
```

This could allow API or consumer access to an external MQTT broker at:

```text
10.0.0.105:1883
```

Be careful:

- NAT may affect the observed addresses
    
- Cloud routing may differ
    
- Service addresses and Pod addresses should generally use selectors
    
- IP addresses can change
    
- `ipBlock` is not application identity
    

For your MQTT platform, a stable internal DNS name or Kubernetes-managed broker Service is usually preferable.

---

# 46. NetworkPolicy is not a firewall for every situation

NetworkPolicy normally controls:

```text
Pod ingress
Pod egress
IP and port relationships
```

It does not normally control:

- HTTP methods
    
- HTTP paths
    
- User permissions
    
- SQL permissions
    
- MQTT topic authorization
    
- TLS certificate validity
    
- Application authentication
    
- Kubernetes API authorization
    

You still need:

```text
Ingress TLS
Application authentication
Database users and grants
MQTT ACLs
RBAC
Host and cloud firewalls
```

NetworkPolicy is one layer.

---

# 47. NetworkPolicy limitations

Important limitations include:

- Enforcement depends on the network plugin.
    
- Existing connections may behave differently when policies change.
    
- Policies are additive rather than processed as ordered firewall rules.
    
- Layer 7 policy generally requires CNI-specific extensions or a service mesh.
    
- Host-networked workloads may behave differently.
    
- Node traffic and platform components require careful testing.
    
- Incorrect selectors can deny essential traffic.
    
- DNS must be allowed under egress isolation.
    

Always test policies in a non-production namespace first.

---

# 48. Safer policy rollout

Use this order:

```text
1. Inventory current communication.
2. Add labels consistently.
3. Observe normal DNS and traffic.
4. Create explicit allow policies.
5. Test the allow policies.
6. Add default-deny ingress.
7. Verify functionality.
8. Add explicit egress rules.
9. Add default-deny egress.
10. Monitor failures and events.
```

Do not begin by denying everything in production without knowing every required communication path.

---

# 49. Visualize the final allowed traffic

Your Day 24 namespace should allow:

```text
Ingress controller
       │ TCP 5000
       ▼
API Pods
       │ TCP 5432
       ▼
PostgreSQL Pod
```

All Pods may need:

```text
DNS
→ CoreDNS TCP/UDP 53
```

Possible additional future flows:

```text
MQTT consumer
→ Mosquitto 1883 or 8883

API
→ authentication service 443

Monitoring agent
→ metrics endpoints
```

Every allowed flow should have a business and technical reason.

---

# 50. Ingress and NetworkPolicy interaction

An external request does not arrive directly as:

```text
Internet client IP
→ API Pod
```

It commonly arrives as:

```text
Client
→ Ingress controller
→ API Service
→ API Pod
```

Therefore, the API ingress NetworkPolicy often needs to permit the Ingress controller Pods, not arbitrary internet client addresses.

Client IP preservation depends on:

- Ingress controller
    
- Service configuration
    
- Load balancer
    
- Proxy headers
    
- Traffic policy
    
- Cluster infrastructure
    

Do not design security policies based on assumed source addresses without verifying actual traffic.

---

# 51. Session affinity

A Service can optionally use:

```yaml
sessionAffinity: ClientIP
```

Example:

```yaml
spec:
  sessionAffinity: ClientIP
```

This tries to keep traffic from one client IP associated with the same backend Pod.

The default is:

```yaml
sessionAffinity: None
```

Kubernetes Service API supports `None` and `ClientIP` session-affinity modes. ([Kubernetes](https://kubernetes.io/docs/reference/kubernetes-api/core/service-v1/?utm_source=chatgpt.com "Service"))

Avoid session affinity unless the application genuinely requires it.

A better scalable design is normally:

- Stateless API instances
    
- Shared session storage
    
- Tokens
    
- External cache or database
    
- No Pod-specific client state
    

---

# 52. External traffic policies

For NodePort and LoadBalancer Services, an external traffic policy can influence routing:

```yaml
externalTrafficPolicy: Cluster
```

or:

```yaml
externalTrafficPolicy: Local
```

Conceptually:

## Cluster

Traffic may be forwarded to a backend on another node.

Advantages:

- Better distribution
    
- Any node can normally receive traffic
    

## Local

Traffic is sent only to local backends on the receiving node.

Possible advantages:

- Source IP preservation
    
- Fewer network hops
    

Possible risks:

- Traffic imbalance
    
- Nodes without local backends cannot serve traffic normally
    

This is an advanced setting. Do not change it without understanding the load-balancer topology.

---

# 53. Internal traffic policy

Kubernetes also supports internal Service traffic policy choices, including routing internal traffic only to node-local endpoints in appropriate configurations. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/service-traffic-policy/?utm_source=chatgpt.com "Service Internal Traffic Policy"))

This can help with:

- Latency
    
- Cross-node traffic reduction
    
- Cost reduction
    

But it may cause failures if a node has no local backend.

For your current Minikube and small application, use the defaults.

---

# 54. Headless Services

A headless Service uses:

```yaml
spec:
  clusterIP: None
```

It does not provide the normal single virtual Service IP.

Instead, DNS can expose individual backend addresses.

Example:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: database-headless

spec:
  clusterIP: None

  selector:
    app.kubernetes.io/name: postgres

  ports:
    - name: postgres
      port: 5432
```

Headless Services are useful when clients need individual workload identities, especially with StatefulSets. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/service/?utm_source=chatgpt.com "Service"))

For your single PostgreSQL database, the normal `database` ClusterIP Service remains the client endpoint.

---

# 55. StatefulSet DNS identity

With a headless Service and StatefulSet, Pod DNS may resemble:

```text
database-0.database-headless.device-monitor.svc.cluster.local
```

This provides:

```text
database-0
→ stable StatefulSet Pod identity

database-headless
→ governing headless Service
```

This is useful for clustered stateful applications whose members need to address one another individually.

It does not by itself create PostgreSQL replication.

---

# 56. Services without selectors

A Service can exist without a Pod selector.

Example:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: external-mqtt

spec:
  ports:
    - name: mqtt
      port: 1883
      targetPort: 1883
```

You then manage corresponding endpoints or EndpointSlices separately.

Possible use case:

```text
Kubernetes clients
→ Service external-mqtt
→ MQTT broker outside the cluster
```

However, for a stable external hostname, `ExternalName` or application DNS configuration may be simpler.

Selectorless Services require careful endpoint management and security review.

---

# 57. ExternalName Service

Example:

```yaml
apiVersion: v1
kind: Service

metadata:
  name: external-broker

spec:
  type: ExternalName

  externalName: mqtt.example.internal
```

A Pod can resolve:

```text
external-broker
```

to the configured external DNS name.

Important:

- It is DNS aliasing, not proxying.
    
- Port information is not embedded in DNS.
    
- TLS hostname validation must still match the actual server identity.
    
- Some protocols behave differently with aliases.
    

---

# 58. Debugging service connectivity

When:

```text
Pod A cannot reach Service B
```

use this sequence.

## Step 1 — Confirm the client Pod is running

```bash
kubectl get pod CLIENT_POD -o wide
```

## Step 2 — Resolve the Service

```bash
kubectl exec CLIENT_POD -- \
  nslookup SERVICE_NAME
```

## Step 3 — Inspect the Service

```bash
kubectl get service SERVICE_NAME -o yaml
```

## Step 4 — Inspect EndpointSlices

```bash
kubectl get endpointslices \
  -l kubernetes.io/service-name=SERVICE_NAME
```

## Step 5 — Check backend Pod labels

```bash
kubectl get pods --show-labels
```

## Step 6 — Check readiness

```bash
kubectl get pods
kubectl describe pod BACKEND_POD
```

## Step 7 — Test the Pod port directly

```bash
kubectl exec CLIENT_POD -- \
  nc -vz POD_IP PORT
```

## Step 8 — Test the Service port

```bash
kubectl exec CLIENT_POD -- \
  nc -vz SERVICE_NAME PORT
```

## Step 9 — Inspect NetworkPolicies

```bash
kubectl get networkpolicies
kubectl describe networkpolicy POLICY
```

This separates:

```text
DNS failure
Service selection failure
Pod readiness failure
TCP failure
NetworkPolicy denial
Application protocol failure
```

---

# 59. Debugging Ingress

When the Service works internally but Ingress fails:

```bash
kubectl get ingress
```

```bash
kubectl describe ingress device-api
```

Check Ingress controller:

```bash
kubectl get pods \
  --namespace ingress-nginx
```

Controller logs:

```bash
kubectl logs \
  --namespace ingress-nginx \
  --selector app.kubernetes.io/component=controller \
  --tail=200
```

Confirm backend Service:

```bash
kubectl get service device-api
kubectl get endpointslices \
  -l kubernetes.io/service-name=device-api
```

Test internally:

```bash
kubectl run internal-curl \
  --image=curlimages/curl \
  --restart=Never \
  -- curl -v http://device-api/health
```

Check hostname:

```bash
getent hosts device-api.local
```

Test Host header directly:

```bash
curl -v \
  --header 'Host: device-api.local' \
  "http://$(minikube ip)/health"
```

---

# 60. Common Ingress failures

## Ingress has no address

Possible causes:

- Controller not installed
    
- Controller not ready
    
- Wrong Ingress class
    
- Local cluster access mechanism required
    

## HTTP 404 from controller

Possible causes:

- Host header does not match
    
- Path does not match
    
- Wrong Ingress selected
    
- Default backend answered
    

## HTTP 502 or 503

Possible causes:

- Service has no endpoints
    
- Pod readiness failing
    
- Wrong Service port
    
- Controller blocked by NetworkPolicy
    
- Application not listening
    
- Protocol mismatch
    

## TLS certificate mismatch

Possible causes:

- Wrong Secret
    
- Wrong hostname
    
- Certificate does not include requested DNS name
    
- Controller serving a default certificate
    

---

# 61. Common NetworkPolicy failures

## Policy appears to do nothing

Likely cause:

- CNI does not enforce NetworkPolicy
    

## DNS stops working

Cause:

- Egress default deny without DNS allow rule
    

## API cannot reach database

Possible causes:

- API egress not allowed
    
- Database ingress not allowed
    
- Selector mismatch
    
- Port mismatch
    

## Ingress returns 503

Possible causes:

- Ingress controller cannot reach API Pods
    
- API Pods unready
    
- Policy selects the wrong namespace or labels
    

## Temporary diagnostic Pod unexpectedly works

Possible causes:

- It is not selected by an egress policy
    
- Destination is not selected by an ingress policy
    
- CNI enforcement missing
    
- Existing connection remained open
    

---

# 62. Verify current policies

List:

```bash
kubectl get networkpolicies
```

Inspect all:

```bash
kubectl describe networkpolicies
```

Expected conceptual policies:

```text
default-deny-ingress
default-deny-egress
allow-dns-egress
allow-api-to-database
allow-api-egress-database
allow-ingress-to-api
```

Test all required traffic:

```text
Ingress → API
API → database
Pods → DNS
Unauthorized Pod → database denied
```

---

# 63. Kubernetes networking for your MQTT architecture

A future design could look like:

```text
Internet or LAN MQTT devices
        ↓ TCP 8883
External load balancer or gateway
        ↓
Mosquitto Service
        ↓
Mosquitto Pods
        ↓
MQTT consumer Pods
        ↓
PostgreSQL Service
        ↓
PostgreSQL
```

Web traffic:

```text
Browser
   ↓ HTTPS 443
Ingress or Gateway
   ↓
Dashboard/API Service
   ↓
API Pods
```

Policies:

```text
Ingress controller
→ API allowed

API
→ PostgreSQL allowed

MQTT consumer
→ Mosquitto allowed

MQTT consumer
→ PostgreSQL allowed

Dashboard
→ direct PostgreSQL denied

Unrelated Pods
→ Mosquitto denied
```

---

# 64. MQTT and Ingress

Traditional Kubernetes Ingress is HTTP/HTTPS oriented.

MQTT requires TCP connectivity:

```text
1883
8883
```

Common exposure choices include:

- `Service` type `LoadBalancer`
    
- `NodePort`
    
- An external TCP load balancer
    
- Gateway API implementation supporting TCP routes
    
- Controller-specific TCP service configuration
    
- Running the broker outside the cluster
    

Do not force MQTT through ordinary HTTP path routing.

MQTT over WebSockets is different and can sometimes pass through HTTP-aware proxies if configured correctly.

---

# 65. Apply Day 24 manifests with Kustomize

Extend `kustomization.yaml`:

```yaml
resources:
  - 00-namespace.yaml
  - 01-configmap.yaml
  - 03-database-service.yaml
  - 04-database-statefulset.yaml
  - 05-migration-job.yaml
  - 06-api-deployment.yaml
  - 07-api-service.yaml
  - 08-api-ingress.yaml
  - 09-default-deny-ingress.yaml
  - 10-allow-api-to-database.yaml
  - 11-allow-ingress-to-api.yaml
  - 12-default-deny-egress.yaml
  - 13-allow-dns-egress.yaml
  - 14-allow-api-egress-database.yaml
```

Keep real Secret creation separate.

Preview:

```bash
kubectl kustomize .
```

Apply:

```bash
kubectl apply -k .
```

---

# 66. End-to-end verification

Check all resources:

```bash
kubectl get pods
kubectl get services
kubectl get ingress
kubectl get endpointslices
kubectl get networkpolicies
```

Verify PostgreSQL:

```bash
kubectl exec database-0 -- \
  pg_isready \
    -U device_app \
    -d device_monitor
```

Verify Service DNS:

```bash
kubectl run dns-check \
  --image=busybox:1.36 \
  --restart=Never \
  -- nslookup device-api
```

Verify external API:

```bash
curl --insecure \
  https://device-api.local/health
```

Verify unauthorized database access is denied:

```bash
kubectl run unauthorized-client \
  --image=postgres:17 \
  --restart=Never \
  --command -- \
  sh -c '
    pg_isready \
      -h database \
      -p 5432 \
      -U device_app \
      -d device_monitor
  '
```

Inspect:

```bash
kubectl logs unauthorized-client
```

---

# 67. Cleanup

Delete temporary Pods:

```bash
kubectl delete pod \
  dns-check \
  unauthorized-client \
  --ignore-not-found
```

Delete Ingress and policies:

```bash
kubectl delete \
  -f 08-api-ingress.yaml \
  -f 09-default-deny-ingress.yaml \
  -f 10-allow-api-to-database.yaml \
  -f 11-allow-ingress-to-api.yaml \
  -f 12-default-deny-egress.yaml \
  -f 13-allow-dns-egress.yaml \
  -f 14-allow-api-egress-database.yaml
```

Remove TLS Secret:

```bash
kubectl delete secret \
  device-api-tls \
  --ignore-not-found
```

Remove local certificate files when no longer needed:

```bash
rm -f device-api.crt device-api.key
```

Disable the Minikube addon if desired:

```bash
minikube addons disable ingress
```

---

# 68. Day 24 practical laboratory

## Exercise 1 — Address inspection

Identify:

- Node IP
    
- API Pod IPs
    
- PostgreSQL Pod IP
    
- API Service IP
    
- Database Service IP
    

Explain which addresses are temporary and which identities applications should use.

## Exercise 2 — Pod-to-Pod networking

Create two Pods.

Connect directly by Pod IP.

Replace one Pod and observe its address.

## Exercise 3 — Service selection

Inspect API Pod labels and Service selectors.

Break the selector.

Observe empty endpoints.

Restore it.

## Exercise 4 — DNS

Resolve:

```text
database
database.device-monitor
database.device-monitor.svc.cluster.local
```

Inspect `/etc/resolv.conf`.

## Exercise 5 — NodePort

Expose the API through a NodePort.

Access it through Minikube.

Remove the NodePort afterward.

## Exercise 6 — Ingress

Enable the Minikube Ingress controller.

Create hostname-based routing to the API.

Test with a Host header and local hosts entry.

## Exercise 7 — TLS

Generate a training certificate.

Create a TLS Secret.

Configure HTTPS on the Ingress.

Inspect the served certificate.

## Exercise 8 — Path routing

Deploy a second simple web Service.

Route:

```text
/api
/web
```

to different Services.

## Exercise 9 — Default deny

Use a NetworkPolicy-capable CNI.

Apply default-deny ingress.

Observe which application paths fail.

## Exercise 10 — Least-privilege networking

Allow only:

```text
Ingress controller → API
API → PostgreSQL
Pods → DNS
```

Confirm unrelated Pods cannot reach PostgreSQL.

---

# 69. Day 24 command reference

```bash
# Inspect addresses
kubectl get nodes -o wide
kubectl get pods -o wide
kubectl get services -o wide

# Inspect labels
kubectl get pods --show-labels

# Inspect Service backends
kubectl get endpointslices \
  -l kubernetes.io/service-name=device-api

# DNS test
kubectl exec POD -- \
  nslookup database

# Inspect Pod DNS configuration
kubectl exec POD -- \
  cat /etc/resolv.conf

# Enable Minikube Ingress
minikube addons enable ingress

# Inspect Ingress
kubectl get ingress
kubectl describe ingress device-api

# Access Minikube Service
minikube service \
  --namespace device-monitor \
  SERVICE \
  --url

# Create TLS Secret
kubectl create secret tls device-api-tls \
  --cert=device-api.crt \
  --key=device-api.key

# List NetworkPolicies
kubectl get networkpolicies

# Describe policies
kubectl describe networkpolicy POLICY

# Debug Service
kubectl get service SERVICE -o yaml
kubectl get endpointslices \
  -l kubernetes.io/service-name=SERVICE

# Debug DNS
kubectl get pods \
  --namespace kube-system \
  -l k8s-app=kube-dns

# Ingress controller logs
kubectl logs \
  --namespace ingress-nginx \
  --selector app.kubernetes.io/component=controller
```

---

# 70. Knowledge check

## Why should applications not store Pod IP addresses?

Pods are disposable, and replacement Pods can receive new addresses.

## What provides a stable internal endpoint?

A Kubernetes Service.

## How does a Service find its Pods?

Through labels and selectors.

## What is a ClusterIP?

A cluster-internal virtual IP assigned to a Service.

## What is `targetPort`?

The backend Pod port to which Service traffic is forwarded.

## What provides Service-name resolution?

Kubernetes cluster DNS, commonly CoreDNS.

## What is the complete Service DNS pattern?

```text
service.namespace.svc.cluster-domain
```

## What is NodePort?

A Service type that exposes a port through each cluster node.

## What is Ingress?

An API object describing external HTTP and HTTPS routing to Services.

## Does an Ingress resource work without a controller?

No. A compatible Ingress controller must process it. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/ingress-controllers/?utm_source=chatgpt.com "Ingress Controllers"))

## Can ordinary Ingress route PostgreSQL or standard MQTT?

Not as standard HTTP/HTTPS Ingress behavior. Use an appropriate TCP exposure mechanism.

## What is a NetworkPolicy?

A Kubernetes object that controls allowed Pod ingress and egress traffic at the network and transport layers.

## Are NetworkPolicies enforced automatically by every cluster?

No. The installed network plugin must support enforcement. ([Kubernetes](https://kubernetes.io/docs/concepts/services-networking/network-policies/?utm_source=chatgpt.com "Network Policies"))

## What does an empty `podSelector` mean?

It selects all Pods in the policy’s namespace.

## Why does default-deny egress often break DNS?

DNS traffic to the cluster DNS service is no longer permitted unless explicitly allowed.

## Must both ingress and egress policies permit a connection?

When both source egress and destination ingress isolation apply, applicable rules on both sides must allow the traffic.

---

# 71. Day 24 completion challenge

Complete this independently:

1. List all node, Pod, and Service addresses.
    
2. Explain the lifecycle of each address type.
    
3. Connect directly from one Pod to another Pod IP.
    
4. Replace the destination Pod.
    
5. Confirm its address changes.
    
6. Connect through a Service instead.
    
7. Inspect the Service selector.
    
8. Inspect matching Pod labels.
    
9. Inspect EndpointSlices.
    
10. Break and restore the Service selector.
    
11. Resolve the database short DNS name.
    
12. Resolve its namespace-qualified name.
    
13. Resolve its fully qualified name.
    
14. Inspect Pod DNS search domains.
    
15. Inspect CoreDNS Pods.
    
16. Create a NodePort API Service.
    
17. Access it through Minikube.
    
18. Remove the NodePort.
    
19. Enable the Ingress controller.
    
20. Create an API Ingress.
    
21. Configure a local hostname.
    
22. Access the API through the hostname.
    
23. Create a second web Service.
    
24. Configure path-based routing.
    
25. Configure host-based routing.
    
26. Generate a self-signed TLS certificate.
    
27. Store it in a TLS Secret.
    
28. Enable HTTPS on the Ingress.
    
29. Inspect the certificate.
    
30. Explain why standard Ingress is unsuitable for ordinary MQTT TCP.
    
31. Recreate Minikube with a NetworkPolicy-capable CNI.
    
32. Apply default-deny ingress.
    
33. Observe the resulting failures.
    
34. Allow API traffic to PostgreSQL.
    
35. Confirm the API can connect.
    
36. Confirm an unrelated Pod cannot connect.
    
37. Allow Ingress controller traffic to the API.
    
38. Confirm external API access returns.
    
39. Apply default-deny egress.
    
40. Observe DNS failure.
    
41. Allow DNS through TCP and UDP port 53.
    
42. Confirm DNS works.
    
43. Allow API egress to PostgreSQL.
    
44. Confirm database access works.
    
45. Keep unrelated egress blocked.
    
46. Document every required network flow.
    
47. Create a networking diagram for your MQTT platform.
    
48. Choose an exposure mechanism for MQTT 8883.
    
49. Design policies for API, consumer, broker, and PostgreSQL.
    
50. Write a Kubernetes networking troubleshooting checklist.
    

The central Day 24 model is:

```text
External client
      ↓
Ingress controller
      ↓
Ingress routing rule
      ↓
ClusterIP Service
      ↓
Ready Pod endpoints
      ↓
Application

Pod
 ↓ DNS Service name
ClusterIP Service
 ↓
Backend Pods

NetworkPolicies
 ↓
Explicitly control which paths are permitted
```

The most important operational lesson is:

> Never build Kubernetes communication around permanent container or Pod addresses. Use Services and DNS for stable internal identity, Ingress or an appropriate gateway for external HTTP traffic, and NetworkPolicies to reduce communication to the smallest set of explicitly required flows.