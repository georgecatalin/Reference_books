#### Kubernetes Security: RBAC, ServiceAccounts, Pod Security, Secrets, and Supply-Chain Protection

Until now, your Kubernetes application could:

- Run several replicas
    
- Connect through Services
    
- Store persistent data
    
- Use Ingress
    
- Restrict network traffic
    
- Expose logs and metrics
    

Today you will secure the cluster and workloads.

The central lesson is:

> Kubernetes security is layered. Authentication identifies who is making a request, authorization limits what they may do, admission controls reject unsafe objects, security contexts restrict containers, NetworkPolicies limit communication, and supply-chain controls determine which images may run.

Kubernetes security is not one configuration switch. It combines cluster security, workload isolation, API permissions, image trust, secret protection, and operational auditing. ([Kubernetes](https://kubernetes.io/docs/concepts/security/security-checklist/?utm_source=chatgpt.com "Security Checklist"))

---

## 1. Day 26 objectives

By the end of today, you should understand:

- Authentication, authorization, and admission
    
- Kubernetes users versus ServiceAccounts
    
- Role-Based Access Control
    
- Roles and ClusterRoles
    
- RoleBindings and ClusterRoleBindings
    
- Least-privilege permission design
    
- `kubectl auth can-i`
    
- ServiceAccount token mounting
    
- Why most applications do not need Kubernetes API access
    
- Pod and container security contexts
    
- Non-root containers
    
- Linux capabilities
    
- Seccomp
    
- Privilege escalation
    
- Read-only root filesystems
    
- Pod Security Standards
    
- Pod Security Admission
    
- Privileged, baseline, and restricted policies
    
- Secret protection
    
- Private registry credentials
    
- Image tags versus digests
    
- Admission policies
    
- Audit logs
    
- Kubernetes security troubleshooting
    
- A hardened configuration for your device API
    

---

# 2. The Kubernetes API is the control center

Most cluster changes go through the Kubernetes API:

```text
kubectl
CI/CD pipeline
Kubernetes controllers
Operators
Applications using the Kubernetes API
        ↓
API server
        ↓
Cluster state changes
```

An API request commonly passes through these stages:

```text
Authentication
      ↓
Authorization
      ↓
Admission control
      ↓
Object validation and persistence
```

## Authentication

Answers:

```text
Who are you?
```

Examples:

- Human administrator
    
- Developer
    
- CI/CD deployment identity
    
- Pod ServiceAccount
    
- Kubernetes node
    

## Authorization

Answers:

```text
Are you permitted to perform this action?
```

Example:

```text
Can this identity delete Secrets?
Can it create Deployments?
Can it list Pods?
```

## Admission

Answers:

```text
Even if the user has permission, is this object acceptable?
```

An admission controller can reject or modify API requests after authentication and authorization but before the object is stored. ([Kubernetes](https://kubernetes.io/docs/reference/access-authn-authz/admission-controllers/?utm_source=chatgpt.com "Admission Control in Kubernetes"))

---

# 3. Kubernetes users and ServiceAccounts

Kubernetes distinguishes between:

```text
Human or external identities
and
Workload identities
```

## Human or external user

Examples:

```text
george
cluster-admin
gitlab-deployer
company-oidc-user
```

Kubernetes does not normally manage human users as ordinary API objects in the same way it manages Pods or Services. Authentication often comes from:

- Client certificates
    
- OpenID Connect
    
- Cloud identity integration
    
- Authentication proxy
    
- External identity provider
    

## ServiceAccount

A ServiceAccount is a Kubernetes identity intended primarily for workloads running inside Pods.

Typical process:

```text
Create ServiceAccount
      ↓
Grant permissions with RBAC
      ↓
Assign ServiceAccount to Pod
      ↓
Pod can authenticate to Kubernetes API
```

Kubernetes documentation describes those as the three main steps for using a ServiceAccount. ([Kubernetes](https://kubernetes.io/docs/concepts/security/service-accounts/?utm_source=chatgpt.com "Service Accounts"))

---

# 4. Default ServiceAccount

Every namespace normally has a ServiceAccount named:

```text
default
```

Inspect:

```bash
kubectl get serviceaccounts
```

In the `device-monitor` namespace:

```bash
kubectl get serviceaccount default \
  --namespace device-monitor \
  -o yaml
```

A Pod that does not explicitly specify a ServiceAccount normally uses:

```text
default
```

Check one API Pod:

```bash
API_POD="$(
  kubectl get pod \
    --namespace device-monitor \
    -l app.kubernetes.io/name=device-api \
    -o jsonpath='{.items[0].metadata.name}'
)"
```

Then:

```bash
kubectl get pod \
  --namespace device-monitor \
  "$API_POD" \
  -o jsonpath='{.spec.serviceAccountName}'

echo
```

Expected:

```text
default
```

---

# 5. Does your application need Kubernetes API access?

Most ordinary applications do not need to call the Kubernetes API.

Your device API needs:

```text
HTTP traffic
PostgreSQL access
Configuration
Secrets
Temporary storage
```

It does not normally need to:

```text
List Pods
Read Secrets
Delete Deployments
Create Jobs
Inspect nodes
```

Therefore:

> Do not grant Kubernetes API permissions merely because the application runs in Kubernetes.

This applies least privilege:

```text
No API requirement
→ no RBAC permissions
→ no usable API credential needed
```

---

# 6. Disable automatic ServiceAccount token mounting

By default, Kubernetes may mount a projected ServiceAccount credential inside a Pod unless you disable it.

Check inside an API Pod:

```bash
kubectl exec \
  --namespace device-monitor \
  "$API_POD" \
  -- \
  ls -la /var/run/secrets/kubernetes.io/serviceaccount
```

You may see files such as:

```text
token
ca.crt
namespace
```

If the application does not need Kubernetes API access, add:

```yaml
spec:
  template:
    spec:
      automountServiceAccountToken: false
```

A dedicated ServiceAccount can also disable it:

```yaml
apiVersion: v1
kind: ServiceAccount

metadata:
  name: device-api
  namespace: device-monitor

automountServiceAccountToken: false
```

Then assign it:

```yaml
spec:
  template:
    spec:
      serviceAccountName: device-api
      automountServiceAccountToken: false
```

This removes an unnecessary credential from the Pod.

---

# 7. Create a dedicated API ServiceAccount

Create `15-api-serviceaccount.yaml`:

```yaml
apiVersion: v1
kind: ServiceAccount

metadata:
  name: device-api
  namespace: device-monitor

  labels:
    app.kubernetes.io/name: device-api
    app.kubernetes.io/component: api
    app.kubernetes.io/part-of: device-monitor

automountServiceAccountToken: false
```

Apply:

```bash
kubectl apply \
  -f 15-api-serviceaccount.yaml
```

Update the Deployment:

```yaml
spec:
  template:
    spec:
      serviceAccountName: device-api
      automountServiceAccountToken: false
```

Apply:

```bash
kubectl apply \
  -f 06-api-deployment.yaml
```

Wait:

```bash
kubectl rollout status \
  --namespace device-monitor \
  deployment/device-api
```

Verify:

```bash
API_POD="$(
  kubectl get pod \
    --namespace device-monitor \
    -l app.kubernetes.io/name=device-api \
    -o jsonpath='{.items[0].metadata.name}'
)"
```

```bash
kubectl get pod \
  --namespace device-monitor \
  "$API_POD" \
  -o jsonpath='{.spec.serviceAccountName}{"\n"}{.spec.automountServiceAccountToken}{"\n"}'
```

---

# 8. What is RBAC?

RBAC means:

```text
Role-Based Access Control
```

It controls which API actions an identity may perform.

Kubernetes RBAC uses the:

```text
rbac.authorization.k8s.io
```

API group. ([Kubernetes](https://kubernetes.io/docs/reference/access-authn-authz/rbac/?utm_source=chatgpt.com "Using RBAC Authorization"))

The four main RBAC objects are:

```text
Role
ClusterRole
RoleBinding
ClusterRoleBinding
```

The relationship is:

```text
Role or ClusterRole
       ↓ describes permissions

RoleBinding or ClusterRoleBinding
       ↓ grants those permissions

User, group, or ServiceAccount
```

---

# 9. Role versus ClusterRole

## Role

A Role contains permissions that apply inside one namespace.

Example:

```text
List Pods in device-monitor
Read ConfigMaps in device-monitor
Create Jobs in device-monitor
```

## ClusterRole

A ClusterRole can contain:

- Cluster-scoped permissions
    
- Permissions reusable across namespaces
    
- Permissions for resources such as nodes or namespaces
    

Example:

```text
List nodes
Read namespaces
Manage CustomResourceDefinitions
```

A ClusterRole does not automatically grant permissions.

It must be connected to an identity through a binding.

---

# 10. RoleBinding versus ClusterRoleBinding

## RoleBinding

Grants permissions inside one namespace.

It may reference:

- A Role in that namespace
    
- A ClusterRole, limited to the RoleBinding’s namespace
    

## ClusterRoleBinding

Grants ClusterRole permissions cluster-wide.

Use ClusterRoleBinding carefully.

This:

```text
ClusterRole: cluster-admin
+
ClusterRoleBinding
```

can grant near-complete cluster control.

Kubernetes RBAC guidance recommends minimizing permissions and understanding privilege-escalation paths. ([Kubernetes](https://kubernetes.io/docs/concepts/security/rbac-good-practices/?utm_source=chatgpt.com "Role Based Access Control Good Practices"))

---

# 11. RBAC rule structure

Example Role:

```yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: Role

metadata:
  name: pod-reader
  namespace: device-monitor

rules:
  - apiGroups:
      - ""

    resources:
      - pods

    verbs:
      - get
      - list
      - watch
```

Important fields:

## `apiGroups`

For core resources such as Pods and Secrets:

```yaml
apiGroups:
  - ""
```

For Deployments:

```yaml
apiGroups:
  - apps
```

For Jobs:

```yaml
apiGroups:
  - batch
```

## `resources`

Examples:

```text
pods
pods/log
deployments
configmaps
secrets
jobs
```

## `verbs`

Examples:

```text
get
list
watch
create
update
patch
delete
```

---

# 12. `get`, `list`, and `watch` are different

These permissions differ:

## `get`

Read one known object:

```bash
kubectl get pod device-api-abc123
```

## `list`

List multiple objects:

```bash
kubectl get pods
```

## `watch`

Receive ongoing changes:

```bash
kubectl get pods --watch
```

A controller often needs:

```text
get
list
watch
```

An application reading only one known ConfigMap may need only:

```text
get
```

Grant only what is required.

---

# 13. Test permissions with `kubectl auth can-i`

Check your own permissions:

```bash
kubectl auth can-i get pods \
  --namespace device-monitor
```

```bash
kubectl auth can-i delete secrets \
  --namespace device-monitor
```

List allowed actions:

```bash
kubectl auth can-i --list \
  --namespace device-monitor
```

Test another identity:

```bash
kubectl auth can-i list pods \
  --namespace device-monitor \
  --as=system:serviceaccount:device-monitor:device-api
```

Expected for your hardened API:

```text
no
```

Check Secrets:

```bash
kubectl auth can-i get secrets \
  --namespace device-monitor \
  --as=system:serviceaccount:device-monitor:device-api
```

Expected:

```text
no
```

---

# 14. Create a read-only diagnostic identity

Suppose a monitoring helper needs to list Pods and read Pod logs.

Create `16-observer-rbac.yaml`:

```yaml
apiVersion: v1
kind: ServiceAccount

metadata:
  name: workload-observer
  namespace: device-monitor

automountServiceAccountToken: true

---
apiVersion: rbac.authorization.k8s.io/v1
kind: Role

metadata:
  name: workload-observer
  namespace: device-monitor

rules:
  - apiGroups:
      - ""

    resources:
      - pods

    verbs:
      - get
      - list
      - watch

  - apiGroups:
      - ""

    resources:
      - pods/log

    verbs:
      - get

---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding

metadata:
  name: workload-observer
  namespace: device-monitor

subjects:
  - kind: ServiceAccount
    name: workload-observer
    namespace: device-monitor

roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: Role
  name: workload-observer
```

Apply:

```bash
kubectl apply \
  -f 16-observer-rbac.yaml
```

Test:

```bash
kubectl auth can-i list pods \
  --namespace device-monitor \
  --as=system:serviceaccount:device-monitor:workload-observer
```

Expected:

```text
yes
```

Test deletion:

```bash
kubectl auth can-i delete pods \
  --namespace device-monitor \
  --as=system:serviceaccount:device-monitor:workload-observer
```

Expected:

```text
no
```

---

# 15. Why `pods/log` is a separate resource

Reading Pod metadata:

```text
pods
```

and reading application logs:

```text
pods/log
```

are separate API permissions.

This Role:

```yaml
resources:
  - pods
```

does not necessarily grant:

```bash
kubectl logs POD
```

Add:

```yaml
resources:
  - pods/log

verbs:
  - get
```

when log access is genuinely required.

Logs may contain sensitive operational information, so log access should not be granted casually.

---

# 16. Dangerous RBAC patterns

Avoid broad rules such as:

```yaml
apiGroups:
  - "*"

resources:
  - "*"

verbs:
  - "*"
```

This is effectively unrestricted permission within the binding’s scope.

Also avoid:

```yaml
resources:
  - secrets

verbs:
  - get
  - list
```

unless the workload genuinely needs Secret values.

Reading Secrets may allow an identity to obtain:

- Database credentials
    
- Registry credentials
    
- TLS private keys
    
- Application tokens
    
- Credentials of other workloads
    

---

# 17. RBAC privilege-escalation paths

A permission may be more powerful than it first appears.

For example, an identity that can create Pods in a namespace may potentially:

- Mount accessible Secrets
    
- Use powerful ServiceAccounts
    
- Mount persistent volumes
    
- Run arbitrary images
    
- Attempt node or network access
    
- Create privileged workloads if admission permits them
    

An identity that can modify RoleBindings may grant itself additional permissions.

An identity that can create workloads using a privileged ServiceAccount may indirectly obtain that ServiceAccount’s authority.

Therefore:

> Evaluate permissions by what they enable indirectly, not only by the resource name.

Kubernetes’ RBAC good-practices guidance specifically warns about privilege-escalation risks in permission design. ([Kubernetes](https://kubernetes.io/docs/concepts/security/rbac-good-practices/?utm_source=chatgpt.com "Role Based Access Control Good Practices"))

---

# 18. Do not give applications permission to read Secrets unnecessarily

Your API already receives its database password through a mounted Secret.

It does not need to call the Kubernetes API and retrieve the Secret dynamically.

Prefer:

```text
Kubernetes mounts selected Secret
→ application reads only its file
```

over:

```text
Application has API permission to list all Secrets
```

This reduces the impact of an application compromise.

Kubernetes recommends least-privilege Secret access and restricting Secrets to the containers that need them. ([Kubernetes](https://kubernetes.io/docs/concepts/configuration/secret/?utm_source=chatgpt.com "Secrets"))

---

# 19. Security contexts

A security context defines privilege and access-control settings for a Pod or container.

These include:

- User and group IDs
    
- Privileged mode
    
- Capabilities
    
- Seccomp
    
- SELinux settings
    
- Read-only root filesystem
    
- Privilege escalation
    

Kubernetes documents these through Pod- and container-level `securityContext` fields. ([Kubernetes](https://kubernetes.io/docs/tasks/configure-pod-container/security-context/?utm_source=chatgpt.com "Configure a Security Context for a Pod or Container"))

Example:

```yaml
spec:
  securityContext:
    runAsNonRoot: true
    seccompProfile:
      type: RuntimeDefault

  containers:
    - name: device-api

      securityContext:
        runAsNonRoot: true
        allowPrivilegeEscalation: false
        readOnlyRootFilesystem: true

        capabilities:
          drop:
            - ALL
```

---

# 20. Pod security context versus container security context

## Pod-level security context

Applies default security settings across containers in the Pod.

Example:

```yaml
spec:
  securityContext:
    runAsNonRoot: true
    runAsUser: 10001
    runAsGroup: 10001
    fsGroup: 10001
```

## Container-level security context

Applies to one container and can override applicable Pod defaults.

Example:

```yaml
containers:
  - name: application

    securityContext:
      allowPrivilegeEscalation: false
      readOnlyRootFilesystem: true
```

Use Pod level for shared identity defaults and container level for container-specific restrictions.

---

# 21. Require a numeric non-root identity

A strong application configuration is:

```yaml
spec:
  securityContext:
    runAsNonRoot: true
    runAsUser: 10001
    runAsGroup: 10001
    fsGroup: 10001
```

Container:

```yaml
securityContext:
  runAsNonRoot: true
  allowPrivilegeEscalation: false
```

Why use a numeric UID?

The runtime can verify:

```text
UID 10001
≠ root UID 0
```

If only a username is configured in the image, some policy or runtime checks may not be able to determine reliably that it is non-root.

Your Dockerfile should create and use the same identity:

```dockerfile
USER 10001:10001
```

---

# 22. Verify runtime identity

Run:

```bash
kubectl exec \
  --namespace device-monitor \
  "$API_POD" \
  -- id
```

Expected:

```text
uid=10001
gid=10001
groups=10001
```

Verify that it is not root:

```bash
kubectl exec \
  --namespace device-monitor \
  "$API_POD" \
  -- sh -c 'test "$(id -u)" -ne 0'
```

Minimal images may not include `sh`; in that case, use `id` directly or inspect the Pod specification.

---

# 23. Privileged containers

Dangerous:

```yaml
securityContext:
  privileged: true
```

Privileged containers receive broad access to host and kernel functionality.

They may be required for rare infrastructure components, but they should not be used for ordinary:

- APIs
    
- Databases
    
- Web applications
    
- MQTT consumers
    
- Business applications
    

Do not enable privileged mode to solve an unexplained permission error.

Find the specific requirement instead.

---

# 24. Linux capabilities

Drop all capabilities:

```yaml
securityContext:
  capabilities:
    drop:
      - ALL
```

Add back a capability only when required:

```yaml
securityContext:
  capabilities:
    drop:
      - ALL

    add:
      - NET_BIND_SERVICE
```

For your API listening on container port 5000:

```text
No added capability should normally be required.
```

Using a Service port 80 does not require the application itself to bind port 80:

```text
Service port 80
→ targetPort 5000
```

Therefore, the application can remain unprivileged.

---

# 25. Prevent privilege escalation

Add:

```yaml
securityContext:
  allowPrivilegeEscalation: false
```

This prevents the container process from gaining more privileges through mechanisms such as set-user-ID binaries.

It complements:

- Non-root execution
    
- Capability dropping
    
- Seccomp
    
- Read-only root filesystem
    

It does not replace them.

---

# 26. Read-only root filesystem

Add:

```yaml
securityContext:
  readOnlyRootFilesystem: true
```

Then explicitly provide writable paths.

Example:

```yaml
volumeMounts:
  - name: temporary-storage
    mountPath: /tmp

volumes:
  - name: temporary-storage
    emptyDir:
      sizeLimit: 64Mi
```

This creates:

```text
Immutable application filesystem
+
Explicit temporary write location
```

Test:

```bash
kubectl exec \
  --namespace device-monitor \
  "$API_POD" \
  -- \
  sh -c 'echo test > /application-test'
```

Expected:

```text
Read-only file system
```

Test `/tmp`:

```bash
kubectl exec \
  --namespace device-monitor \
  "$API_POD" \
  -- \
  sh -c 'echo test > /tmp/test && cat /tmp/test'
```

Expected:

```text
test
```

---

# 27. Seccomp

Seccomp limits the Linux system calls available to a process.

Use the runtime’s default profile:

```yaml
securityContext:
  seccompProfile:
    type: RuntimeDefault
```

Avoid:

```yaml
seccompProfile:
  type: Unconfined
```

unless you have a documented, tested requirement.

Linux kernel security controls such as seccomp and capabilities provide additional isolation layers for Pods and containers. ([Kubernetes](https://kubernetes.io/docs/concepts/security/linux-kernel-security-constraints/?utm_source=chatgpt.com "Linux kernel security constraints for Pods and containers"))

---

# 28. Host namespaces

Avoid these fields for ordinary applications:

```yaml
spec:
  hostNetwork: true
  hostPID: true
  hostIPC: true
```

They cause the Pod to share host namespaces.

Potential consequences:

## `hostNetwork`

- Pod uses node networking directly
    
- Port collisions become possible
    
- Network isolation is reduced
    

## `hostPID`

- Pod can see host processes
    
- Process isolation is reduced
    

## `hostIPC`

- Pod shares host interprocess communication
    
- IPC isolation is reduced
    

Your API, database, and MQTT consumer should normally use Kubernetes’ isolated defaults.

---

# 29. Dangerous hostPath volumes

Dangerous:

```yaml
volumes:
  - name: host-root
    hostPath:
      path: /
```

This exposes the node filesystem.

Other dangerous paths include:

```text
/var/run/containerd
/var/run/docker.sock
/etc
/proc
/sys
/var/lib/kubelet
```

`hostPath` ties a Pod to node-specific storage and can expose sensitive host resources.

Prefer:

- ConfigMap
    
- Secret
    
- `emptyDir`
    
- PersistentVolumeClaim
    
- CSI-managed storage
    

Use `hostPath` only for trusted infrastructure workloads with documented requirements.

---

# 30. Pod Security Standards

Kubernetes defines three Pod Security Standard profiles:

```text
Privileged
Baseline
Restricted
```

They are cumulative from permissive to strongly restricted. ([Kubernetes](https://kubernetes.io/docs/concepts/security/pod-security-standards/?utm_source=chatgpt.com "Pod Security Standards"))

## Privileged

Allows broad and largely unrestricted Pod configurations.

Appropriate only for trusted infrastructure workloads that genuinely require host-level access.

## Baseline

Blocks commonly known privilege escalations while remaining compatible with many ordinary workloads.

## Restricted

Enforces stronger current security-hardening practices.

Typical restricted expectations include:

- Non-root execution
    
- No privilege escalation
    
- Restricted volume types
    
- Seccomp profile
    
- Capabilities dropped
    
- No privileged containers
    
- No host namespace sharing
    

Your ordinary application namespace should aim for:

```text
restricted
```

---

# 31. Pod Security Admission

Kubernetes includes a built-in Pod Security admission controller that can enforce Pod Security Standards at namespace scope.

It supports three modes:

```text
enforce
audit
warn
```

## `enforce`

Rejects Pods that violate the selected policy.

## `audit`

Allows the request but records policy violations in audit information.

## `warn`

Allows the request but returns warnings to the user.

Pod Security Admission has been stable since Kubernetes 1.25. ([Kubernetes](https://kubernetes.io/docs/concepts/security/pod-security-admission/?utm_source=chatgpt.com "Pod Security Admission"))

---

# 32. Apply Pod Security labels to the namespace

Start carefully with warnings and audit:

```bash
kubectl label namespace device-monitor \
  pod-security.kubernetes.io/warn=restricted \
  pod-security.kubernetes.io/audit=restricted \
  --overwrite
```

You can optionally pin a Kubernetes policy version:

```bash
kubectl label namespace device-monitor \
  pod-security.kubernetes.io/warn-version=latest \
  pod-security.kubernetes.io/audit-version=latest \
  --overwrite
```

Inspect:

```bash
kubectl get namespace device-monitor \
  --show-labels
```

Kubernetes supports namespace labels for configuring privileged, baseline, or restricted Pod Security Admission levels. ([Kubernetes](https://kubernetes.io/docs/tasks/configure-pod-container/enforce-standards-namespace-labels/?utm_source=chatgpt.com "Enforce Pod Security Standards with Namespace Labels"))

---

# 33. Test a policy violation

Create `privileged-test.yaml`:

```yaml
apiVersion: v1
kind: Pod

metadata:
  name: privileged-test
  namespace: device-monitor

spec:
  containers:
    - name: test
      image: alpine
      command:
        - sleep
        - "3600"

      securityContext:
        privileged: true
```

Apply while the namespace uses `warn`:

```bash
kubectl apply \
  -f privileged-test.yaml
```

You should receive a warning, but the Pod may still be created.

Delete it:

```bash
kubectl delete pod \
  --namespace device-monitor \
  privileged-test
```

---

# 34. Enforce the restricted standard

After your workloads pass the warning phase:

```bash
kubectl label namespace device-monitor \
  pod-security.kubernetes.io/enforce=restricted \
  pod-security.kubernetes.io/enforce-version=latest \
  --overwrite
```

Try again:

```bash
kubectl apply \
  -f privileged-test.yaml
```

The API server should reject it.

This demonstrates:

```text
User has permission to create Pods
+
Pod violates security policy
=
Admission rejects request
```

That is the distinction between RBAC authorization and admission control.

---

# 35. A restricted-compatible test Pod

Create `restricted-test.yaml`:

```yaml
apiVersion: v1
kind: Pod

metadata:
  name: restricted-test
  namespace: device-monitor

spec:
  automountServiceAccountToken: false

  securityContext:
    runAsNonRoot: true

    seccompProfile:
      type: RuntimeDefault

  containers:
    - name: test

      image: alpine

      command:
        - sleep
        - "3600"

      securityContext:
        runAsNonRoot: true
        runAsUser: 10001
        runAsGroup: 10001

        allowPrivilegeEscalation: false
        readOnlyRootFilesystem: true

        capabilities:
          drop:
            - ALL

      volumeMounts:
        - name: temporary-storage
          mountPath: /tmp

  volumes:
    - name: temporary-storage
      emptyDir: {}
```

Apply:

```bash
kubectl apply \
  -f restricted-test.yaml
```

Inspect:

```bash
kubectl get pod \
  --namespace device-monitor \
  restricted-test
```

Clean up:

```bash
kubectl delete pod \
  --namespace device-monitor \
  restricted-test
```

---

# 36. Why enforce gradually?

Applying `restricted` immediately to an existing namespace may block:

- Existing application updates
    
- Debug Pods
    
- Migration Jobs
    
- Database Pods
    
- Monitoring agents
    
- Ingress components
    
- Init containers
    

A safer rollout is:

```text
1. Add warn=restricted
2. Observe violations
3. Add audit=restricted
4. Fix workloads
5. Test new rollouts
6. Add enforce=restricted
```

Kubernetes migration guidance recommends using warning and audit modes to identify violations before full enforcement. ([Kubernetes](https://kubernetes.io/docs/tasks/configure-pod-container/migrate-from-psp/?utm_source=chatgpt.com "Migrate from PodSecurityPolicy to the Built-In PodSecurity ..."))

---

# 37. PostgreSQL and restricted policies

Your PostgreSQL image may require specific filesystem ownership or runtime user behavior.

Inspect:

```bash
kubectl get pod \
  --namespace device-monitor \
  database-0 \
  -o yaml
```

Important fields include:

```yaml
securityContext:
  fsGroup: 999
```

and the image-defined user.

Test with:

```bash
kubectl exec \
  --namespace device-monitor \
  database-0 \
  -- id
```

Do not assume every third-party image automatically complies with your restricted policy.

You may need to:

- Choose a better-maintained image
    
- Set explicit numeric user IDs
    
- Configure writable volumes
    
- Remove unnecessary capabilities
    
- Adjust ownership initialization safely
    

Never weaken the whole namespace merely because one image is poorly designed without first considering a safer image.

---

# 38. Secrets are not automatically fully protected

A Kubernetes Secret separates sensitive data from ordinary configuration, but it is not magic.

Risks include:

- Excessive RBAC permissions
    
- Secret values committed to Git
    
- Secrets displayed with `kubectl`
    
- Secrets copied into logs
    
- Secrets exposed through application diagnostics
    
- Unencrypted storage in the cluster datastore
    
- Workloads mounting Secrets they do not need
    
- Backups containing cluster Secrets
    

Kubernetes recommends encryption at rest, least-privilege RBAC, restricting access to specific containers, and considering external secret stores. ([Kubernetes](https://kubernetes.io/docs/concepts/configuration/secret/?utm_source=chatgpt.com "Secrets"))

---

# 39. Base64 is not encryption

A Secret manifest may contain:

```yaml
data:
  password: ZGV2ZWxvcG1lbnQtcGFzc3dvcmQ=
```

Decode:

```bash
printf '%s' \
  'ZGV2ZWxvcG1lbnQtcGFzc3dvcmQ=' \
  | base64 --decode
```

Result:

```text
development-password
```

Therefore:

```text
Base64
≠ encryption
```

Base64 is only an encoding.

Do not commit a Secret manifest merely because the values look unreadable.

---

# 40. Secret mounting practices

Prefer mounting only required keys:

```yaml
volumes:
  - name: database-password

    secret:
      secretName: database-credentials

      items:
        - key: password
          path: password
```

Mount:

```yaml
volumeMounts:
  - name: database-password
    mountPath: /run/secrets/database
    readOnly: true
```

Result:

```text
/run/secrets/database/password
```

This is better than mounting a broad Secret containing unrelated credentials.

Do not mount the same credential Secret into every Pod in the namespace.

---

# 41. Separate Secrets by responsibility

Avoid one giant Secret:

```text
all-platform-secrets
├── database password
├── MQTT password
├── TLS key
├── registry token
├── email token
└── cloud credential
```

Prefer:

```text
database-credentials
mqtt-consumer-credentials
api-tls
email-service-token
registry-pull-credentials
```

Then grant each workload only what it requires.

Example:

```text
API
→ database password

MQTT consumer
→ MQTT password + database password

Ingress controller
→ TLS certificate

PostgreSQL
→ database initialization password
```

---

# 42. Private registry credentials

Create a pull-only registry Secret:

```bash
kubectl create secret docker-registry registry-credentials \
  --namespace device-monitor \
  --docker-server=registry.example.com \
  --docker-username="$REGISTRY_USER" \
  --docker-password="$REGISTRY_TOKEN"
```

Reference:

```yaml
spec:
  template:
    spec:
      imagePullSecrets:
        - name: registry-credentials
```

Kubernetes documents using `imagePullSecrets` to pull images from private registries. ([Kubernetes](https://kubernetes.io/docs/tasks/configure-pod-container/pull-image-private-registry/?utm_source=chatgpt.com "Pull an Image from a Private Registry"))

Use a token that has:

```text
Pull permission only
```

not:

```text
Push
Delete
Administrative registry access
```

---

# 43. Attach registry credentials to a ServiceAccount

You can associate the pull Secret with the API ServiceAccount:

```yaml
apiVersion: v1
kind: ServiceAccount

metadata:
  name: device-api
  namespace: device-monitor

automountServiceAccountToken: false

imagePullSecrets:
  - name: registry-credentials
```

Then Pods using that ServiceAccount can use the configured image-pull credential.

This avoids repeating:

```yaml
imagePullSecrets:
```

in every Pod template using the ServiceAccount.

Remember:

```text
Image pull credential
≠ Kubernetes API ServiceAccount token
```

They serve different purposes.

---

# 44. Image tags are mutable

This reference:

```yaml
image: registry.example.com/team/device-api:stable
```

may point to different content over time.

Safer:

```yaml
image: registry.example.com/team/device-api:2.0.1
```

Strongest exact identity:

```yaml
image: registry.example.com/team/device-api@sha256:EXACT_DIGEST
```

A digest prevents later tag movement from changing the selected image content.

Continue the release practice from Day 18:

```text
Human version:
2.0.1

Deployment identity:
repository@sha256:...
```

---

# 45. `imagePullPolicy`

Common values:

```text
Always
IfNotPresent
Never
```

## `Always`

The kubelet resolves the image reference through the registry when starting the container.

## `IfNotPresent`

Uses a local image when already available.

## `Never`

Requires the image to exist locally.

For immutable digests:

```yaml
image: registry.example.com/team/device-api@sha256:...
imagePullPolicy: IfNotPresent
```

is usually predictable.

For mutable tags, `Always` reduces stale local-tag behavior but does not make the tag immutable.

The real security improvement is an approved immutable image reference.

---

# 46. Do not use `latest` in production

Avoid:

```yaml
image: device-api:latest
```

Problems:

- No reliable version identity
    
- Tag can move
    
- Rollback is ambiguous
    
- Audit trail is weak
    
- One node may have different cached content from another under incorrect policy assumptions
    
- It is difficult to correlate incidents to source code
    

Use:

```text
Versioned tag
+
Recorded digest
+
CI provenance
```

---

# 47. Image supply-chain controls

A trustworthy image lifecycle is:

```text
Reviewed source
    ↓
Controlled CI build
    ↓
Tests
    ↓
Vulnerability scan
    ↓
SBOM
    ↓
Build provenance
    ↓
Image signature
    ↓
Trusted registry
    ↓
Admission verification
    ↓
Kubernetes deployment
```

Kubernetes itself runs the image reference it is given. Stronger supply-chain security commonly requires additional tooling or policy.

Possible admission requirements include:

```text
Only approved registries
No latest tag
Digest required
Signature required
No critical known vulnerabilities
Required SBOM or provenance
```

---

# 48. Admission controllers

Admission controllers intercept API requests after authentication and authorization but before object persistence.

They may:

- Reject unsafe requests
    
- Apply defaults
    
- Validate policies
    
- Add sidecars
    
- Enforce security requirements
    
- Limit resources
    
- Enforce Pod Security Standards
    

Examples of policy systems include:

- Built-in Pod Security Admission
    
- ValidatingAdmissionPolicy
    
- Kyverno
    
- Gatekeeper
    
- Custom admission webhooks
    

Do not deploy arbitrary admission webhooks without understanding their availability and failure behavior.

A failing webhook can potentially block workload deployment if configured with strict failure handling.

---

# 49. Useful admission policies

Examples:

```text
Reject privileged containers
Reject hostNetwork
Reject hostPID
Reject hostPath
Require resource requests
Require resource limits
Require non-root
Require read-only root filesystem
Require approved registries
Reject latest tags
Require ownership labels
Require environment labels
Require image digests
```

Admission policy converts security expectations from documentation into enforced controls.

Without enforcement:

```text
Policy says “do not use privileged containers”
but
any authorized developer can still create one
```

With enforcement:

```text
Unsafe object
→ rejected by API
```

---

# 50. Resource controls are security controls

A malicious or faulty workload can exhaust:

- CPU
    
- Memory
    
- Process IDs
    
- Ephemeral storage
    
- Persistent storage
    
- API requests
    
- Network bandwidth
    

Set requests and limits:

```yaml
resources:
  requests:
    cpu: 100m
    memory: 128Mi
    ephemeral-storage: 64Mi

  limits:
    cpu: "1"
    memory: 512Mi
    ephemeral-storage: 256Mi
```

Also consider:

- Namespace ResourceQuota
    
- LimitRange
    
- Pod count limits
    
- PVC limits
    
- Storage quotas
    

Resource controls reduce denial-of-service impact inside shared clusters.

---

# 51. Create a LimitRange

A LimitRange can define default or minimum/maximum container resources in a namespace.

Create `17-limitrange.yaml`:

```yaml
apiVersion: v1
kind: LimitRange

metadata:
  name: container-resources
  namespace: device-monitor

spec:
  limits:
    - type: Container

      defaultRequest:
        cpu: 100m
        memory: 128Mi

      default:
        cpu: 500m
        memory: 512Mi

      min:
        cpu: 10m
        memory: 32Mi

      max:
        cpu: "2"
        memory: 2Gi
```

Apply:

```bash
kubectl apply \
  -f 17-limitrange.yaml
```

Inspect:

```bash
kubectl describe limitrange \
  --namespace device-monitor \
  container-resources
```

This supplies defaults where workloads omit resource values.

Explicit workload values are still preferable because they document actual intent.

---

# 52. Create a ResourceQuota

Create `18-resourcequota.yaml`:

```yaml
apiVersion: v1
kind: ResourceQuota

metadata:
  name: device-monitor-quota
  namespace: device-monitor

spec:
  hard:
    requests.cpu: "4"
    requests.memory: 4Gi

    limits.cpu: "8"
    limits.memory: 8Gi

    pods: "30"
    services: "10"
    secrets: "20"
    configmaps: "20"
    persistentvolumeclaims: "5"

    requests.storage: 20Gi
```

Apply:

```bash
kubectl apply \
  -f 18-resourcequota.yaml
```

Inspect:

```bash
kubectl describe resourcequota \
  --namespace device-monitor \
  device-monitor-quota
```

A ResourceQuota limits aggregate namespace consumption.

It protects the cluster from one namespace consuming unlimited resources.

---

# 53. Network security remains necessary

RBAC controls Kubernetes API access:

```text
Can the Pod list Secrets?
```

NetworkPolicy controls runtime network traffic:

```text
Can the API connect to PostgreSQL?
```

They solve different problems.

A Pod may have:

```text
No Kubernetes API permissions
```

but still connect to:

```text
PostgreSQL
MQTT broker
external internet services
```

Use both:

```text
RBAC
+
NetworkPolicy
+
application authentication
```

---

# 54. Namespace isolation is not complete isolation

Namespaces help scope:

- Roles
    
- RoleBindings
    
- NetworkPolicies
    
- ResourceQuotas
    
- LimitRanges
    
- Object names
    

But namespaces alone do not automatically provide strong tenant isolation.

You still need:

- RBAC boundaries
    
- NetworkPolicies
    
- Pod Security Admission
    
- Resource quotas
    
- Secret isolation
    
- Node isolation where appropriate
    
- Admission policies
    
- Audit logs
    

Kubernetes multi-tenancy guidance notes that many controls are namespace-scoped, including Roles and NetworkPolicies, but those controls must be configured. ([Kubernetes](https://kubernetes.io/docs/concepts/security/multi-tenancy/?utm_source=chatgpt.com "Multi-tenancy"))

---

# 55. Audit logs

Kubernetes auditing records a chronological, security-relevant history of API activity by:

- Users
    
- Applications
    
- ServiceAccounts
    
- Control-plane components
    

Audit records help answer:

```text
Who deleted the Deployment?
Who read the Secret?
Which identity created the privileged Pod?
When was the RoleBinding changed?
Which API request failed authorization?
```

Kubernetes auditing is specifically designed to record security-relevant cluster actions. ([Kubernetes](https://kubernetes.io/docs/tasks/debug/debug-cluster/audit/?utm_source=chatgpt.com "Auditing"))

---

# 56. Audit stages

Kubernetes audit events may represent stages such as:

```text
RequestReceived
ResponseStarted
ResponseComplete
Panic
```

An audit policy decides:

- Which requests are recorded
    
- At which detail level
    
- Which resources are excluded
    
- Whether request or response bodies are included
    

Possible levels include:

```text
None
Metadata
Request
RequestResponse
```

Be cautious: logging complete request bodies for Secret-related operations may expose sensitive data.

---

# 57. Audit logs are not application logs

Audit log:

```text
User deployer patched Deployment/device-api.
```

Application log:

```text
Device API failed to authenticate to PostgreSQL.
```

Ingress log:

```text
Client requested /api/devices and received HTTP 500.
```

All three are different evidence sources.

For an incident, correlate:

```text
Deployment changed at 14:01
API Pods restarted at 14:02
Database errors began at 14:03
User reports arrived at 14:05
```

---

# 58. Secure `kubectl` and kubeconfig

Your kubeconfig may contain:

- Client certificates
    
- Tokens
    
- Cluster endpoints
    
- Authentication-provider settings
    
- Contexts
    

Protect:

```bash
chmod 600 ~/.kube/config
```

Do not:

- Commit kubeconfig to Git
    
- Email it
    
- Place it in a container image
    
- Share administrator credentials
    
- Use production admin credentials in ordinary CI jobs
    

Use separate identities:

```text
Developer
Read-only observer
CI deployer
Cluster administrator
```

Each should receive only required permissions.

---

# 59. Separate CI/CD permissions

A deployment pipeline may need:

```text
Get Deployments
Patch Deployments
Read rollout state
Create migration Jobs
Read Pod status
```

It may not need:

```text
Read all Secrets
Delete namespaces
Manage nodes
Create ClusterRoleBindings
Modify admission policies
```

Create a dedicated CI ServiceAccount or external identity with namespace-scoped deployment permissions.

Do not use:

```text
cluster-admin kubeconfig
```

as the easiest CI solution.

---

# 60. Example deployment Role

Create `19-deployer-rbac.yaml`:

```yaml
apiVersion: v1
kind: ServiceAccount

metadata:
  name: application-deployer
  namespace: device-monitor

automountServiceAccountToken: false

---
apiVersion: rbac.authorization.k8s.io/v1
kind: Role

metadata:
  name: application-deployer
  namespace: device-monitor

rules:
  - apiGroups:
      - apps

    resources:
      - deployments
      - statefulsets
      - replicasets

    verbs:
      - get
      - list
      - watch
      - create
      - update
      - patch

  - apiGroups:
      - batch

    resources:
      - jobs

    verbs:
      - get
      - list
      - watch
      - create
      - delete

  - apiGroups:
      - ""

    resources:
      - pods

    verbs:
      - get
      - list
      - watch

  - apiGroups:
      - ""

    resources:
      - pods/log

    verbs:
      - get

  - apiGroups:
      - ""

    resources:
      - services
      - configmaps

    verbs:
      - get
      - list
      - watch
      - create
      - update
      - patch

---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding

metadata:
  name: application-deployer
  namespace: device-monitor

subjects:
  - kind: ServiceAccount
    name: application-deployer
    namespace: device-monitor

roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: Role
  name: application-deployer
```

Notice that it does not grant general Secret access.

Whether a deployer needs to create or update Secrets should be considered separately.

---

# 61. Impersonation testing

Test deployer rights:

```bash
kubectl auth can-i patch deployments \
  --namespace device-monitor \
  --as=system:serviceaccount:device-monitor:application-deployer
```

Expected:

```text
yes
```

Test node deletion:

```bash
kubectl auth can-i delete nodes \
  --as=system:serviceaccount:device-monitor:application-deployer
```

Expected:

```text
no
```

Test Secret listing:

```bash
kubectl auth can-i list secrets \
  --namespace device-monitor \
  --as=system:serviceaccount:device-monitor:application-deployer
```

Expected:

```text
no
```

---

# 62. Hardened device API Deployment

A stronger API Pod template:

```yaml
apiVersion: apps/v1
kind: Deployment

metadata:
  name: device-api
  namespace: device-monitor

spec:
  replicas: 3

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
      serviceAccountName: device-api
      automountServiceAccountToken: false
      enableServiceLinks: false

      securityContext:
        runAsNonRoot: true
        runAsUser: 10001
        runAsGroup: 10001
        fsGroup: 10001

        seccompProfile:
          type: RuntimeDefault

      imagePullSecrets:
        - name: registry-credentials

      containers:
        - name: device-api

          image: registry.example.com/team/device-api@sha256:EXACT_DIGEST

          imagePullPolicy: IfNotPresent

          ports:
            - name: http
              containerPort: 5000
              protocol: TCP

          securityContext:
            runAsNonRoot: true
            runAsUser: 10001
            runAsGroup: 10001

            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true

            capabilities:
              drop:
                - ALL

          resources:
            requests:
              cpu: 100m
              memory: 128Mi
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
            secretName: database-credentials

            items:
              - key: password
                path: password

        - name: temporary-storage

          emptyDir:
            sizeLimit: 64Mi
```

---

# 63. Validate the Deployment before applying

Client-side dry run:

```bash
kubectl apply \
  --dry-run=client \
  -f 06-api-deployment.yaml
```

Server-side dry run:

```bash
kubectl apply \
  --dry-run=server \
  -f 06-api-deployment.yaml
```

Server-side dry run is especially useful because it exercises:

- API schema validation
    
- Admission controls
    
- Pod Security Admission
    
- Certain policy webhooks
    

It does not persist the object.

---

# 64. Inspect security fields across Pods

List ServiceAccounts:

```bash
kubectl get pods \
  --namespace device-monitor \
  -o custom-columns='NAME:.metadata.name,SERVICEACCOUNT:.spec.serviceAccountName,AUTOMOUNT:.spec.automountServiceAccountToken'
```

List privileged fields:

```bash
kubectl get pods \
  --all-namespaces \
  -o jsonpath='{range .items[*]}{.metadata.namespace}{"\t"}{.metadata.name}{"\t"}{range .spec.containers[*]}{.name}={.securityContext.privileged}{" "}{end}{"\n"}{end}'
```

List host namespaces:

```bash
kubectl get pods \
  --all-namespaces \
  -o custom-columns='NAMESPACE:.metadata.namespace,NAME:.metadata.name,HOSTNETWORK:.spec.hostNetwork,HOSTPID:.spec.hostPID,HOSTIPC:.spec.hostIPC'
```

List images:

```bash
kubectl get pods \
  --all-namespaces \
  -o jsonpath='{.items[*].spec["initContainers","containers"][*].image}' \
  | tr ' ' '\n' \
  | sort \
  | uniq
```

Kubernetes documents querying all running container image references using `kubectl` and JSONPath. ([Kubernetes](https://kubernetes.io/docs/tasks/access-application-cluster/list-all-running-container-images/?utm_source=chatgpt.com "List All Container Images Running in a Cluster"))

---

# 65. Security incident workflow

Suppose an unauthorized privileged Pod appears.

## Step 1 — Preserve evidence

```bash
kubectl get pod suspicious-pod \
  --namespace suspicious-namespace \
  -o yaml \
  > suspicious-pod.yaml
```

## Step 2 — Identify the ServiceAccount

```bash
kubectl get pod suspicious-pod \
  --namespace suspicious-namespace \
  -o jsonpath='{.spec.serviceAccountName}'

echo
```

## Step 3 — Inspect owner references

```bash
kubectl get pod suspicious-pod \
  --namespace suspicious-namespace \
  -o jsonpath='{.metadata.ownerReferences}'

echo
```

It may belong to:

- Deployment
    
- DaemonSet
    
- Job
    
- StatefulSet
    

## Step 4 — Inspect RBAC

```bash
kubectl auth can-i create pods \
  --namespace suspicious-namespace \
  --as=IDENTITY
```

## Step 5 — Review audit logs

Determine:

```text
Who created it?
When?
From which client?
Was it modified afterward?
```

## Step 6 — Contain carefully

- Disable the offending identity
    
- Remove dangerous RoleBindings
    
- Isolate the workload
    
- Preserve relevant logs
    
- Rotate exposed credentials
    
- Remove malicious objects
    

Do not immediately delete all evidence before collecting it.

---

# 66. Common RBAC troubleshooting

## `Forbidden`

Example:

```text
pods is forbidden:
User ... cannot list resource "pods"
```

Check:

```bash
kubectl auth can-i list pods \
  --namespace device-monitor \
  --as=IDENTITY
```

Inspect:

```bash
kubectl get roles,rolebindings \
  --namespace device-monitor
```

```bash
kubectl describe role ROLE \
  --namespace device-monitor
```

```bash
kubectl describe rolebinding BINDING \
  --namespace device-monitor
```

Common causes:

- Wrong namespace
    
- Binding references wrong Role
    
- ServiceAccount name incorrect
    
- Missing verb
    
- Missing subresource such as `pods/log`
    
- ClusterRole not bound
    
- Token belongs to another namespace
    

---

# 67. Common Pod Security Admission failure

Error may mention:

```text
violates PodSecurity "restricted"
```

Check the warning details.

Common violations include:

- `privileged: true`
    
- Missing `allowPrivilegeEscalation: false`
    
- Missing capability drop
    
- Running as root
    
- Missing seccomp profile
    
- Host namespace use
    
- Disallowed volume type
    

Use:

```bash
kubectl apply \
  --dry-run=server \
  -f workload.yaml
```

Then modify the workload rather than weakening namespace policy immediately.

---

# 68. Common `runAsNonRoot` failure

Error:

```text
container has runAsNonRoot and image will run as root
```

Possible causes:

- Image defaults to root
    
- Dockerfile has no `USER`
    
- Username is non-numeric and runtime cannot verify it
    
- Explicit `runAsUser: 0`
    
- Init container still runs as root
    

Fix the image:

```dockerfile
USER 10001:10001
```

and the manifest:

```yaml
runAsNonRoot: true
runAsUser: 10001
runAsGroup: 10001
```

Check every container, including init containers.

---

# 69. Common read-only filesystem failure

Logs may show:

```text
Permission denied
Read-only file system
Cannot create cache
Cannot write PID file
```

Identify the required path.

Do not disable:

```yaml
readOnlyRootFilesystem: true
```

immediately.

Provide the narrowest writable volume:

```yaml
emptyDir:
```

for temporary data or:

```yaml
persistentVolumeClaim:
```

for durable data.

Examples:

```text
/tmp
/app/cache
/run/application
```

Keep application code immutable.

---

# 70. Common private registry failure

Pod state:

```text
ImagePullBackOff
```

Inspect:

```bash
kubectl describe pod POD
```

Check:

- Secret exists in same namespace
    
- `imagePullSecrets` name is correct
    
- Registry hostname exactly matches
    
- Token is valid
    
- Token has pull permission
    
- Image reference exists
    
- Node trusts registry TLS certificate
    
- Requested architecture exists
    

Inspect Secret type:

```bash
kubectl get secret registry-credentials \
  --namespace device-monitor \
  -o jsonpath='{.type}'

echo
```

Expected:

```text
kubernetes.io/dockerconfigjson
```

---

# 71. Common quota failure

Error may say:

```text
exceeded quota
```

Inspect:

```bash
kubectl describe resourcequota \
  --namespace device-monitor
```

Check:

```bash
kubectl get resourcequota \
  --namespace device-monitor
```

Possible causes:

- Too many Pods
    
- CPU requests exceed quota
    
- Memory limits exceed quota
    
- PVC count exceeded
    
- Requested storage exceeded
    
- Secret or ConfigMap count exceeded
    

Do not remove quotas automatically.

Determine whether:

- Old resources should be deleted
    
- Requests are excessive
    
- Quota should legitimately increase
    

---

# 72. Security review checklist for a workload

## Identity

```text
Dedicated ServiceAccount?
Token mounting disabled when unnecessary?
RBAC permissions minimal?
```

## Container privileges

```text
Runs as non-root?
Numeric UID and GID?
Privilege escalation disabled?
All capabilities dropped?
RuntimeDefault seccomp?
Not privileged?
```

## Filesystem

```text
Root filesystem read-only?
Only required writable volumes?
No dangerous hostPath?
Secret mounts read-only?
```

## Networking

```text
NetworkPolicy applied?
Only required ingress?
Only required egress?
No hostNetwork?
```

## Resources

```text
CPU request and limit?
Memory request and limit?
Ephemeral storage control?
Namespace quota?
```

## Images

```text
Trusted registry?
Immutable version or digest?
Scanned?
Signed?
SBOM and provenance available?
No latest?
```

## Secrets

```text
No plaintext Secret in Git?
Only required keys mounted?
No Secret logging?
Rotation process?
Encryption at rest?
```

---

# 73. Security review for your MQTT platform

## Device API

```text
Dedicated ServiceAccount
No Kubernetes API access
Database Secret only
Ingress → API policy
API → PostgreSQL policy
Non-root
Read-only root filesystem
Digest-pinned image
```

## MQTT consumer

```text
Dedicated ServiceAccount
No Kubernetes API access unless required
MQTT and database Secrets only
Consumer → broker policy
Consumer → PostgreSQL policy
Idempotent message processing
```

## Mosquitto

```text
Dedicated ServiceAccount
TLS key mounted read-only
Configuration mounted read-only
Persistent storage where required
Strict MQTT authentication and ACLs
Only MQTT ports exposed
```

## PostgreSQL

```text
Dedicated ServiceAccount
No API access
Persistent storage
Restricted network access
Credential Secret
Backup identity separate
Monitoring account least privileged
```

## Backup Job

```text
Dedicated ServiceAccount
Database network access
Backup storage access
No ability to modify Deployments
No broad Secret listing
```

---

# 74. Day 26 practical laboratory

## Exercise 1 — ServiceAccount inspection

Identify the ServiceAccount used by:

- API Pods
    
- PostgreSQL Pod
    
- Migration Job
    

Determine whether each needs Kubernetes API access.

## Exercise 2 — Disable token mounting

Create a dedicated API ServiceAccount.

Disable automatic token mounting.

Confirm the token path is absent.

## Exercise 3 — Read-only observer

Create a ServiceAccount that can:

- List Pods
    
- Watch Pods
    
- Read Pod logs
    

Confirm it cannot:

- Delete Pods
    
- Read Secrets
    
- Modify Deployments
    

## Exercise 4 — RBAC testing

Use:

```bash
kubectl auth can-i
```

to test at least ten permission combinations.

## Exercise 5 — Harden the API

Configure:

- Numeric non-root UID
    
- `allowPrivilegeEscalation: false`
    
- Capability drop
    
- RuntimeDefault seccomp
    
- Read-only filesystem
    
- Writable `/tmp`
    

## Exercise 6 — Privileged workload

Create a privileged Pod in warn mode.

Observe the warning.

Enable restricted enforcement.

Confirm the Pod is rejected.

## Exercise 7 — Restricted-compatible Pod

Create a Pod compatible with the restricted policy.

Confirm it runs.

## Exercise 8 — Secrets

Mount only the database password key.

Confirm the username key is not mounted when not required.

## Exercise 9 — Private registry

Create a pull-only registry Secret.

Associate it with the API ServiceAccount.

Deploy a private image.

## Exercise 10 — Resource controls

Create a LimitRange and ResourceQuota.

Attempt to exceed each quota deliberately.

---

# 75. Day 26 command reference

```bash
# List ServiceAccounts
kubectl get serviceaccounts \
  --namespace device-monitor

# Check Pod ServiceAccount
kubectl get pod POD \
  --namespace device-monitor \
  -o jsonpath='{.spec.serviceAccountName}'

# Test permission
kubectl auth can-i VERB RESOURCE \
  --namespace device-monitor

# Test another identity
kubectl auth can-i list pods \
  --namespace device-monitor \
  --as=system:serviceaccount:device-monitor:SERVICEACCOUNT

# List Roles and bindings
kubectl get roles,rolebindings \
  --namespace device-monitor

# List cluster-wide RBAC
kubectl get clusterroles,clusterrolebindings

# Inspect Role
kubectl describe role ROLE \
  --namespace device-monitor

# Label namespace for restricted warnings
kubectl label namespace device-monitor \
  pod-security.kubernetes.io/warn=restricted \
  --overwrite

# Enforce restricted policy
kubectl label namespace device-monitor \
  pod-security.kubernetes.io/enforce=restricted \
  --overwrite

# Server-side policy validation
kubectl apply \
  --dry-run=server \
  -f workload.yaml

# Inspect namespace labels
kubectl get namespace device-monitor \
  --show-labels

# Inspect quota
kubectl describe resourcequota \
  --namespace device-monitor

# Inspect LimitRange
kubectl describe limitrange \
  --namespace device-monitor

# List all running images
kubectl get pods \
  --all-namespaces \
  -o jsonpath='{.items[*].spec["initContainers","containers"][*].image}' \
  | tr ' ' '\n' \
  | sort \
  | uniq
```

---

# 76. Knowledge check

## What is authentication?

The process of determining who is making an API request.

## What is authorization?

The process of deciding whether that identity may perform the requested action.

## What is admission control?

A stage that validates or modifies an authorized API request before persistence.

## What is a ServiceAccount?

A Kubernetes identity primarily used by workloads.

## What is RBAC?

An authorization mechanism that grants API permissions through Roles and bindings.

## What is the difference between a Role and ClusterRole?

A Role is namespace-scoped; a ClusterRole can contain cluster-scoped or reusable permissions.

## What is the difference between RoleBinding and ClusterRoleBinding?

A RoleBinding grants permissions within one namespace; a ClusterRoleBinding grants them cluster-wide.

## Why disable automatic ServiceAccount token mounting?

Because workloads that do not use the Kubernetes API should not receive unnecessary API credentials.

## What does `runAsNonRoot` do?

It requires the container to run using a non-root identity.

## What does `allowPrivilegeEscalation: false` do?

It prevents the process from gaining additional privileges through execution mechanisms.

## Why drop all capabilities?

To remove unnecessary Linux privilege units from the container.

## What does `RuntimeDefault` seccomp do?

It applies the container runtime’s default syscall-filtering profile.

## What are the Pod Security Standard levels?

```text
privileged
baseline
restricted
```

## What does `enforce=restricted` do?

It causes Pod Security Admission to reject workloads that violate the restricted standard.

## Does RBAC stop a Pod from connecting to PostgreSQL?

No. RBAC controls Kubernetes API operations; NetworkPolicy controls Pod network communication.

## Is a Kubernetes Secret encrypted because it uses base64?

No.

## Why deploy images by digest?

A digest identifies exact immutable image content.

## What is Kubernetes auditing?

A security-relevant chronological record of Kubernetes API actions. ([Kubernetes](https://kubernetes.io/docs/tasks/debug/debug-cluster/audit/?utm_source=chatgpt.com "Auditing"))

---

# 77. Day 26 completion challenge

Complete this independently:

1. List all ServiceAccounts in `device-monitor`.
    
2. Identify which ServiceAccount every Pod uses.
    
3. Check whether API tokens are mounted.
    
4. Create a dedicated API ServiceAccount.
    
5. Disable token auto-mounting.
    
6. Confirm the API still works.
    
7. Confirm the Kubernetes API token directory is absent.
    
8. Create a pod-reader Role.
    
9. Add `get`, `list`, and `watch`.
    
10. Bind it to a ServiceAccount.
    
11. Test its permissions.
    
12. Confirm it cannot delete Pods.
    
13. Add permission for `pods/log`.
    
14. Confirm it can read logs.
    
15. Confirm it cannot read Secrets.
    
16. Create a deployment identity.
    
17. Allow it to patch Deployments.
    
18. Deny it node and ClusterRole access.
    
19. Review every wildcard RBAC rule in the cluster.
    
20. Harden the API security context.
    
21. Run it as UID 10001.
    
22. Run it as GID 10001.
    
23. Disable privilege escalation.
    
24. Drop all Linux capabilities.
    
25. Apply RuntimeDefault seccomp.
    
26. Enable a read-only root filesystem.
    
27. Add a writable `emptyDir` for `/tmp`.
    
28. Confirm root-filesystem writes fail.
    
29. Confirm `/tmp` writes succeed.
    
30. Confirm the Pod does not use host networking.
    
31. Confirm it does not use host PID or IPC.
    
32. Search for privileged containers cluster-wide.
    
33. Search for hostPath volumes.
    
34. Add restricted Pod Security warnings.
    
35. Review all warnings.
    
36. Fix every API violation.
    
37. Fix every migration Job violation.
    
38. Fix every PostgreSQL violation possible.
    
39. Enable audit mode.
    
40. Enable restricted enforcement.
    
41. Confirm a privileged test Pod is rejected.
    
42. Confirm a restricted-compatible Pod is admitted.
    
43. Separate platform Secrets by responsibility.
    
44. Mount only required Secret keys.
    
45. Ensure production Secret manifests are excluded from Git.
    
46. Create a pull-only image registry Secret.
    
47. Associate it with a ServiceAccount.
    
48. Pin the API image by digest.
    
49. Confirm no workload uses `latest`.
    
50. List all running images cluster-wide.
    
51. Create a LimitRange.
    
52. Create a ResourceQuota.
    
53. Attempt to exceed Pod quota.
    
54. Attempt to exceed CPU quota.
    
55. Review NetworkPolicies alongside RBAC.
    
56. Verify API access is allowed only through intended paths.
    
57. Verify unrelated Pods cannot reach PostgreSQL.
    
58. Describe how audit logs would identify a malicious deployment.
    
59. Write a security incident response checklist.
    
60. Produce a security review for API, MQTT consumer, broker, PostgreSQL, and backup Job.
    

The central Day 26 model is:

```text
Identity
  ↓
Authentication
  ↓
RBAC authorization
  ↓
Admission policies
  ↓
Restricted Pod specification
  ↓
Non-root container
  ↓
Minimal capabilities
  ↓
Restricted filesystem and network
  ↓
Trusted image and protected Secrets
  ↓
Auditing and monitoring
```

The most important operational lesson is:

> Do not trust a workload simply because it runs in your cluster. Give it a dedicated identity, no API authority unless required, a restricted container security context, narrowly scoped Secrets, explicit network access, bounded resources, and a verified immutable image. Then enforce those expectations through admission controls rather than relying only on developer discipline.