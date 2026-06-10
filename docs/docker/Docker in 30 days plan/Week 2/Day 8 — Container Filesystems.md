#### Container Filesystems and Ephemeral Data

Week 2 begins with storage.

Until now, you built containers that could be removed and recreated without losing anything important. That works well for application code, because application code belongs in the image.

However, many applications create data while running:

- Database records
    
- Uploaded files
    
- MQTT persistence files
    
- Generated reports
    
- Application state
    
- Certificates
    
- Configuration changes
    
- Logs
    
- Cache files
    

Today you will learn what happens to those files inside a container.

The central lesson is:

> A container’s writable filesystem survives stopping and restarting that same container, but it is lost when the container is removed.

By the end of Day 8, you should understand:

- How an image filesystem differs from a container filesystem
    
- What the container writable layer is
    
- Why stopping does not delete data
    
- Why removing a container does delete its writable layer
    
- Why containers should be replaceable
    
- Why databases need external persistent storage
    
- How bind mounts and volumes solve persistence problems
    
- Which files should and should not be stored inside containers
    
- How to inspect filesystem changes
    

---

## 1. The container filesystem model

A container filesystem is built from two main parts:

```text
Read-only image layers
        +
Container writable layer
```

For example:

```text
Python base-image layers
        +
Installed dependencies layer
        +
Application source layer
        +
Container writable layer
```

The image layers are read-only.

When an application changes a file, Docker writes the change into the container’s writable layer.

Conceptually:

```text
Image:
├── /app/app.py
├── /usr/local/bin/python
└── /etc/os-release

Container writable layer:
├── /app/generated-report.txt
├── /tmp/session-data
└── modified version of /app/config.ini
```

The image remains unchanged.

Only that particular container receives the writable changes.

---

# 2. Image content versus runtime content

Suppose your Dockerfile contains:

```dockerfile
FROM alpine

WORKDIR /app

COPY application.sh .

CMD ["./application.sh"]
```

The copied script becomes part of the image:

```text
Image content:
└── /app/application.sh
```

Now suppose the application creates:

```text
/app/state.txt
```

while running.

That new file belongs to the container’s writable layer:

```text
Container content:
└── /app/state.txt
```

Creating another container from the same image does not automatically include `state.txt`.

---

# 3. Create a filesystem test container

Run:

```bash
docker run -it \
  --name day8-storage-test \
  alpine \
  sh
```

Inside the container, create a directory:

```sh
mkdir -p /data
```

Create a file:

```sh
echo "Important container data" > /data/message.txt
```

Read it:

```sh
cat /data/message.txt
```

Expected:

```text
Important container data
```

Inspect the filesystem:

```sh
ls -la /data
```

Exit:

```sh
exit
```

The container stops, but it still exists.

---

# 4. Stopping does not remove the writable layer

Check the container:

```bash
docker ps -a \
  --filter name=day8-storage-test
```

Its state should be:

```text
Exited
```

Restart and attach:

```bash
docker start -ai day8-storage-test
```

Inside:

```sh
cat /data/message.txt
```

The file still exists.

Why?

Because you restarted the same container.

The lifecycle was:

```text
Create container
      ↓
Write file into writable layer
      ↓
Stop container
      ↓
Writable layer remains
      ↓
Start same container
      ↓
File still exists
```

Exit again:

```sh
exit
```

---

# 5. Removing the container removes its writable layer

Remove it:

```bash
docker rm day8-storage-test
```

Confirm:

```bash
docker ps -a \
  --filter name=day8-storage-test
```

Now create a new container from the same image:

```bash
docker run -it \
  --name day8-storage-test \
  alpine \
  sh
```

Inside:

```sh
cat /data/message.txt
```

Expected:

```text
cat: can't open '/data/message.txt': No such file or directory
```

The new container has a new writable layer.

Even though:

- The image is the same
    
- The container name is the same
    
- The command is the same
    

it is a different container.

---

# 6. Container name does not preserve identity

This is important.

You removed:

```text
day8-storage-test
```

and created another container with the same name.

But a container name is only a label.

The old and new containers have different IDs.

Inspect the current container ID from another terminal:

```bash
docker inspect day8-storage-test \
  --format '{{.Id}}'
```

If you had recorded the original ID, it would be different.

The model is:

```text
Old container:
name=day8-storage-test
ID=AAA
writable layer=AAA

Remove it

New container:
name=day8-storage-test
ID=BBB
writable layer=BBB
```

Reusing the name does not restore the previous filesystem.

Exit and remove the container:

```sh
exit
```

```bash
docker rm day8-storage-test
```

---

# 7. Demonstrate independent writable layers

Start two containers from the same Alpine image:

```bash
docker run -d \
  --name day8-container-a \
  alpine \
  sleep 3600
```

```bash
docker run -d \
  --name day8-container-b \
  alpine \
  sleep 3600
```

Create a file in container A:

```bash
docker exec day8-container-a \
  sh -c 'echo "Data from A" > /container.txt'
```

Create a different file in container B:

```bash
docker exec day8-container-b \
  sh -c 'echo "Data from B" > /container.txt'
```

Read from A:

```bash
docker exec day8-container-a \
  cat /container.txt
```

Expected:

```text
Data from A
```

Read from B:

```bash
docker exec day8-container-b \
  cat /container.txt
```

Expected:

```text
Data from B
```

Both containers started from the same image, but they have separate writable layers.

---

# 8. Changes do not flow back into the image

Check whether the Alpine image now contains `/container.txt`:

```bash
docker run --rm alpine \
  sh -c 'ls -l /container.txt'
```

It should fail.

The image was not changed.

The relationship is:

```text
alpine image
├── container A writable layer
└── container B writable layer
```

The writable layers are not merged back into the image.

---

# 9. Inspect filesystem changes with `docker diff`

Docker can show filesystem changes made in a container.

Run:

```bash
docker diff day8-container-a
```

You may see output similar to:

```text
A /container.txt
```

The letters mean:

|Letter|Meaning|
|---|---|
|`A`|Added|
|`C`|Changed|
|`D`|Deleted|

Create and modify more files:

```bash
docker exec day8-container-a \
  sh -c '
    mkdir -p /example &&
    echo first > /example/file.txt &&
    echo changed > /etc/hostname-copy
  '
```

Run:

```bash
docker diff day8-container-a
```

The output shows differences between the original image filesystem and the current container filesystem.

This is useful for:

- Training
    
- Debugging
    
- Finding unexpected writes
    
- Understanding application behavior
    
- Identifying files that may need persistent storage
    

---

# 10. Copy-on-write behavior

Docker commonly uses a layered filesystem with copy-on-write behavior.

Suppose an image contains:

```text
/etc/example.conf
```

When the container only reads it, Docker can use the read-only image layer.

If the container changes it, Docker creates a changed version in the writable layer.

Conceptually:

```text
Image layer:
  /etc/example.conf = original

Container writable layer:
  /etc/example.conf = modified
```

The container sees the modified version.

The underlying image still contains the original version.

A new container sees the original.

---

# 11. Modify an image-provided file

Run:

```bash
docker exec day8-container-a \
  sh -c 'cat /etc/os-release'
```

Now add a harmless custom line to that container’s copy:

```bash
docker exec day8-container-a \
  sh -c 'echo "TRAINING=day8" >> /etc/os-release'
```

Read it:

```bash
docker exec day8-container-a \
  tail /etc/os-release
```

Now check container B:

```bash
docker exec day8-container-b \
  tail /etc/os-release
```

Container B will not contain the new line.

Check a fresh container:

```bash
docker run --rm alpine \
  tail /etc/os-release
```

It also will not contain the change.

Only container A sees its modified copy.

---

# 12. Why manual container modification is unreliable

Suppose you enter a production container:

```bash
docker exec -it application sh
```

Then manually edit:

```text
/app/config.ini
```

The application may work afterward.

But that change exists only in that container.

Problems:

- The image does not contain the fix.
    
- Another replica does not receive the fix.
    
- Recreating the container removes the fix.
    
- The change is not version-controlled.
    
- Nobody knows exactly what changed.
    
- Rollback is unclear.
    
- Automation cannot reproduce it.
    

The correct response depends on the file type:

```text
Application code or packaged configuration
→ change source/Dockerfile
→ rebuild image

Environment-specific configuration
→ provide at runtime

Persistent application data
→ store in a volume or external service
```

---

# 13. Containers should be disposable

A well-designed application container should be replaceable.

You should be able to do:

```bash
docker rm -f application
```

and recreate it from:

- The same image
    
- The same runtime configuration
    
- The same persistent storage
    

without losing important business data.

The desired model is:

```text
Disposable:
- container process
- writable layer
- temporary cache
- runtime instance

Persistent:
- database records
- user uploads
- broker persistence
- important generated files
- secrets and certificates where appropriate
```

---

# 14. Ephemeral does not mean “immediately temporary”

People often say container filesystems are ephemeral.

That does not mean files disappear whenever the container stops.

The precise behavior is:

```text
Container stopped:
writable layer remains

Container restarted:
writable layer remains

Container removed:
writable layer is removed

New container created:
new writable layer
```

Therefore, container-local data can appear persistent during testing.

That can create a false sense of safety.

You may restart a database container ten times and see all records remain. Then one day you remove and recreate it, and all records disappear.

---

# 15. A database-loss demonstration

Run a simple container that stores a value in a file:

```bash
docker run -it \
  --name day8-database-simulation \
  alpine \
  sh
```

Inside:

```sh
mkdir -p /var/lib/database
echo "customer_id=1001" > /var/lib/database/records.txt
cat /var/lib/database/records.txt
exit
```

Restart:

```bash
docker start -ai day8-database-simulation
```

Inside:

```sh
cat /var/lib/database/records.txt
```

The record remains.

Exit:

```sh
exit
```

Remove the container:

```bash
docker rm day8-database-simulation
```

Create another:

```bash
docker run -it \
  --name day8-database-simulation \
  alpine \
  sh
```

Inside:

```sh
cat /var/lib/database/records.txt
```

The record is gone.

This is exactly why database storage should not depend only on the container writable layer.

Exit and remove:

```sh
exit
```

```bash
docker rm day8-database-simulation
```

---

# 16. Application code and data have different lifecycles

Application code should usually be packaged into the image.

Business data should normally live outside the container writable layer.

For example:

```text
Dashboard image:
- PHP/Python source
- Libraries
- HTML/CSS
- Startup command

Database storage:
- Device records
- User accounts
- Historical telemetry
- Application settings
```

The image may be replaced frequently.

The database data may need to survive for years.

These lifecycles should not be coupled.

---

# 17. What belongs in the image?

Good candidates for image content:

- Application executable
    
- Application source where appropriate
    
- Runtime libraries
    
- Static HTML/CSS/JavaScript
    
- Default configuration templates
    
- Startup scripts
    
- Trusted CA certificates needed by the application
    
- Dependency manifests
    
- Migration scripts
    

These files should be reproducible from source control and the build process.

---

# 18. What belongs in persistent storage?

Good candidates for volumes or external storage:

- PostgreSQL data directory
    
- SQLite database file
    
- Mosquitto persistence database
    
- Uploaded documents
    
- User-generated images
    
- Application-generated reports that must survive
    
- Search indexes that are expensive or important to rebuild
    
- Long-lived keys or certificates
    
- Backup archives
    

The exact mechanism depends on the data.

You will learn:

- Bind mounts on Day 9
    
- Docker volumes on Day 10
    

---

# 19. What can remain ephemeral?

Some data can safely stay in the container writable layer:

- Temporary files
    
- Short-lived caches
    
- Intermediate processing files
    
- Runtime-generated PID files
    
- Data that can be recreated cheaply
    
- Debug files during development
    

Even then, you should understand:

- How large it may grow
    
- Whether cleanup happens
    
- Whether the application expects a writable directory
    
- Whether a read-only root filesystem will later be possible
    

---

# 20. Logs need special consideration

Logs are important, but storing them only inside the container is usually a poor design.

Bad model:

```text
Container
└── /app/logs/application.log
```

When the container is removed, the log disappears.

Better model:

```text
Application
    ↓ stdout/stderr
Docker logging system
    ↓
Centralized log destination
```

For now, continue writing application logs to:

- Standard output
    
- Standard error
    

View them with:

```bash
docker logs CONTAINER
```

Later, production systems may forward logs to:

- Loki
    
- Elasticsearch
    
- Splunk
    
- Cloud logging services
    
- Journald
    
- Other log collectors
    

---

# 21. Inspect a container’s storage driver

Run:

```bash
docker info
```

Look for:

```text
Storage Driver
```

On modern Linux systems, this is commonly:

```text
overlay2
```

You can extract it:

```bash
docker info \
  --format '{{.Driver}}'
```

The storage driver controls how Docker manages image layers and container writable layers.

You do not need to manipulate its internal files manually.

Do not edit Docker’s storage directories directly.

---

# 22. Docker’s internal storage location

On Linux, Docker commonly stores its data under:

```text
/var/lib/docker
```

Check the configured root:

```bash
docker info \
  --format '{{.DockerRootDir}}'
```

You may see:

```text
/var/lib/docker
```

This area contains Docker-managed data such as:

- Images
    
- Container writable layers
    
- Volumes
    
- Metadata
    
- Build cache
    

Do not treat it like a normal application directory.

Avoid manually modifying files there.

Use Docker commands to manage Docker resources.

---

# 23. Inspect container size

Run:

```bash
docker ps -s
```

You may see two storage-related values:

```text
SIZE
```

The size of the container’s writable layer.

```text
virtual
```

The combined apparent size including image layers.

Create a larger file in container A:

```bash
docker exec day8-container-a \
  sh -c 'dd if=/dev/zero of=/large-file.bin bs=1M count=20'
```

Now run:

```bash
docker ps -s \
  --filter name=day8-container-a
```

The writable-layer size should increase.

This demonstrates that application writes consume host storage.

---

# 24. A container can fill the host disk

Container filesystem writes ultimately consume space on the Docker host.

Potential causes include:

- Unbounded log files
    
- Temporary files never removed
    
- Large downloads
    
- Application caches
    
- Database files incorrectly stored in writable layers
    
- Crash dumps
    
- Generated reports
    

A container does not have infinite storage merely because it is isolated.

Monitor usage:

```bash
docker system df
```

Detailed:

```bash
docker system df -v
```

Also monitor host storage:

```bash
df -h
```

---

# 25. Removing a container and writable storage

Create a temporary container:

```bash
docker run -d \
  --name day8-large-container \
  alpine \
  sleep 3600
```

Create a 50 MB file:

```bash
docker exec day8-large-container \
  sh -c 'dd if=/dev/zero of=/temporary.bin bs=1M count=50'
```

Inspect:

```bash
docker ps -s \
  --filter name=day8-large-container
```

Remove it:

```bash
docker rm -f day8-large-container
```

The container’s writable layer is deleted.

The image remains:

```bash
docker image ls alpine
```

This reinforces:

```text
Container writable data
→ belongs to the container

Image
→ reusable and separate
```

---

# 26. `--rm` and ephemeral containers

You have often used:

```bash
docker run --rm ...
```

`--rm` tells Docker to automatically remove the container after its main process exits.

Therefore, writable-layer data is also automatically removed.

Example:

```bash
docker run --rm \
  alpine \
  sh -c 'echo temporary > /file.txt'
```

After completion:

- The container exits.
    
- Docker removes it.
    
- `/file.txt` disappears with the container.
    

Use `--rm` for:

- One-off commands
    
- Validation checks
    
- Debugging tools
    
- Temporary jobs
    
- Build utilities
    

Do not use `--rm` when you need to inspect the stopped container after failure.

---

# 27. Temporary data inside `/tmp`

Applications commonly use:

```text
/tmp
```

for temporary data.

Run:

```bash
docker run -it \
  --name day8-temp-test \
  alpine \
  sh
```

Inside:

```sh
echo "temporary session" > /tmp/session.txt
exit
```

Restart:

```bash
docker start -ai day8-temp-test
```

The file may still exist because this is the same container.

But the application should not assume `/tmp` survives container replacement.

Exit and remove:

```sh
exit
```

```bash
docker rm day8-temp-test
```

For long-lived business data, `/tmp` is never the correct location.

---

# 28. Container restart policies do not provide storage persistence

Suppose a container uses:

```bash
--restart unless-stopped
```

Docker may restart it automatically after:

- Application crash
    
- Docker daemon restart
    
- Host reboot
    

The same container’s writable layer may remain available.

However, a restart policy does not protect against:

- `docker rm`
    
- Container replacement during deployment
    
- Host disk failure
    
- Docker storage corruption
    
- Manual cleanup
    
- Migration to another host
    

Restart policy and persistent storage solve different problems.

```text
Restart policy:
keeps a service process running

Persistent storage:
keeps important data independent of the container
```

---

# 29. Host reboot versus container replacement

A host reboot normally does not delete containers.

If configured with a suitable restart policy, Docker can restart them.

Their writable layers may still exist.

But deployment workflows commonly replace containers:

```text
Pull new image
Remove old container
Create new container
```

Therefore, important data still needs external persistence.

Do not design storage around the assumption that a particular container ID will live forever.

---

# 30. Read-only image layers are shared

Suppose ten containers use:

```text
python:3.13-slim
```

Docker does not normally create ten complete copies of the base image.

The containers can share the read-only image layers.

Each receives its own writable layer:

```text
Shared Python image layers
├── writable layer: container 1
├── writable layer: container 2
├── writable layer: container 3
└── ...
```

This sharing is one reason containers are storage-efficient compared with duplicating complete virtual-machine disks.

---

# 31. Data duplication in writable layers

Although image layers are shared, each container’s runtime changes are separate.

If ten containers each write a 500 MB cache file, Docker may consume approximately:

```text
10 × 500 MB
```

in writable-layer data.

Shared image layers do not automatically deduplicate arbitrary runtime writes.

Therefore:

- Avoid large unnecessary container-local caches.
    
- Understand application write behavior.
    
- Set cleanup policies.
    
- Use appropriate storage mechanisms.
    

---

# 32. Deleting an image versus deleting a container

These operations are separate.

Remove a container:

```bash
docker rm container-name
```

This removes:

- Container metadata
    
- Container writable layer
    
- Normal container-local logs
    

It does not normally remove:

- The source image
    
- Named volumes
    
- Bind-mounted host files
    

Remove an image:

```bash
docker image rm image-name:tag
```

This removes an image reference and possibly image layers if unused.

It does not remove already-created container data automatically.

Docker may refuse to remove an image if containers still reference it.

---

# 33. `docker cp` and the writable layer

Start a container:

```bash
docker run -d \
  --name day8-copy-test \
  alpine \
  sleep 3600
```

Create a host file:

```bash
echo "Copied from host" > host-file.txt
```

Copy it into the container:

```bash
docker cp \
  host-file.txt \
  day8-copy-test:/copied-file.txt
```

Read it:

```bash
docker exec day8-copy-test \
  cat /copied-file.txt
```

The copied file is stored in the container’s writable layer.

Remove the container:

```bash
docker rm -f day8-copy-test
```

The file disappears with it.

`docker cp` is not a persistence mechanism.

---

# 34. Extracting data before removing a container

Suppose important data was accidentally written inside a container.

You can copy it out before removal:

```bash
docker cp \
  container-name:/path/to/important-file \
  ./important-file
```

Example:

```bash
docker cp \
  day8-container-a:/container.txt \
  ./recovered-container.txt
```

Read it:

```bash
cat recovered-container.txt
```

This can rescue files, but it is a manual recovery technique, not a proper storage design.

---

# 35. A practical SQLite warning

Your PHP dashboard uses SQLite.

If the database file is stored inside the image or container:

```text
/var/www/html/data/cockpit.sqlite
```

then:

- It may survive stopping the same container.
    
- It disappears when the container is removed.
    
- Rebuilding the image does not safely preserve runtime records.
    
- Multiple application containers cannot safely assume independent copies represent one shared database.
    

For SQLite, you will eventually mount persistent storage for the database directory:

```text
Host or Docker volume
        ↓
Container /var/www/html/data
```

You will learn the mechanisms on Days 9 and 10.

---

# 36. A practical Mosquitto warning

Mosquitto may persist broker state, depending on configuration.

Potential persistent files include:

- Retained messages
    
- Persistent sessions
    
- Queued messages
    
- Subscription state
    

If Mosquitto writes its persistence database only inside the container:

```text
/mosquitto/data
```

removing the container may remove that state.

A proper deployment commonly provides persistent storage for:

```text
/mosquitto/data
```

and separate configuration storage for:

```text
/mosquitto/config
```

Logs are often sent to standard output or a deliberate logging destination.

---

# 37. Data categories for your MQTT platform

Your eventual system may classify data like this:

|Data|Recommended location|
|---|---|
|Dashboard source code|Image|
|Python/PHP dependencies|Image|
|C daemon executable|Image|
|Mosquitto configuration|Image or read-only mount|
|Mosquitto persistence|Volume|
|PostgreSQL data|Volume|
|SQLite database|Volume or bind mount|
|Uploaded reports|Volume/object storage|
|Runtime secrets|Secret mechanism/runtime mount|
|Application logs|stdout/stderr|
|Temporary cache|Container or temporary filesystem|

The important question is not merely:

> “Where can I write this file?”

The better question is:

> “What lifecycle should this file have?”

---

# 38. Persistence options overview

Docker offers several storage patterns.

## Container writable layer

```text
Lifetime:
container lifetime

Use:
temporary or disposable data
```

## Bind mount

```text
Host path mapped into container

Lifetime:
independent of container

Managed by:
you and the host filesystem
```

## Docker volume

```text
Docker-managed storage mapped into container

Lifetime:
independent of container

Managed by:
Docker
```

## tmpfs mount

```text
Memory-backed temporary storage

Lifetime:
container/runtime lifetime

Use:
sensitive or temporary nonpersistent data
```

Days 9 and 10 focus on bind mounts and volumes.

---

# 39. Why persistent storage is independent of images

A useful deployment model is:

```text
Application image v1
       ↓
Container v1 ───┐
                │
                ├── Persistent data
                │
Application image v2
       ↓        │
Container v2 ───┘
```

The container changes.

The data remains.

This allows:

- Upgrades
    
- Rollbacks
    
- Container replacement
    
- Image rebuilds
    
- Process restarts
    
- Migration strategies
    

without automatically deleting business data.

---

# 40. Do not bake runtime database data into an image

It is technically possible to copy a database file into an image:

```dockerfile
COPY cockpit.sqlite /app/data/cockpit.sqlite
```

That may be suitable only for:

- A read-only demonstration database
    
- Test fixtures
    
- Seed data
    
- A known initial state
    

It is not appropriate for a live database that changes at runtime.

If the container updates the copied database, the changes exist only in its writable layer.

A new container starts again from the database state embedded in the image.

---

# 41. Seed data versus live data

These concepts should be separated.

## Seed data

Initial data used to create or initialize a system.

Examples:

- Database schema
    
- Default administrator role
    
- Test records
    
- Initial lookup values
    

Seed data can live in:

- Migration scripts
    
- SQL initialization scripts
    
- Application source
    
- Image files
    

## Live data

Data created and updated after deployment.

Examples:

- Device heartbeats
    
- User records
    
- Telemetry
    
- Session data
    
- Application settings
    

Live data should use persistent storage or an external data service.

---

# 42. Inspect mounts on a container

Your Day 8 containers currently have no deliberate persistent mounts.

Inspect:

```bash
docker inspect day8-container-a \
  --format '{{json .Mounts}}'
```

You may see:

```json
[]
```

That means the container is using only its normal filesystem layers, with no bind mounts or named volumes.

On Days 9 and 10, this field will show mounted storage.

---

# 43. Inspect the writable layer’s driver data

Run:

```bash
docker inspect day8-container-a \
  --format '{{json .GraphDriver}}'
```

You may see storage-driver-specific information.

Do not build application logic around these internal paths.

They are Docker implementation details.

Use supported mechanisms:

- `docker cp`
    
- Bind mounts
    
- Volumes
    
- Docker backup procedures
    

Do not directly edit the graph-driver directories.

---

# 44. Container commit demonstration

You can turn container changes into an image:

```bash
docker commit \
  day8-container-a \
  day8-committed-image:1.0
```

Run:

```bash
docker run --rm \
  day8-committed-image:1.0 \
  cat /container.txt
```

The file may now appear because it was captured into the committed image.

However, this is not the recommended normal workflow.

Problems:

- Changes are poorly documented.
    
- The build is not reproducible.
    
- Source control does not explain the image.
    
- Secrets or temporary files may be captured.
    
- Image history is less meaningful.
    

Use a Dockerfile for maintainable images.

Remove the training image later:

```bash
docker image rm day8-committed-image:1.0
```

---

# 45. Build reproducibly instead of committing containers

Instead of:

```text
Run container
Modify it manually
docker commit
```

use:

```text
Change Dockerfile or source
docker build
Create a new container
```

Example Dockerfile:

```dockerfile
FROM alpine

RUN echo "Data intentionally packaged in image" \
    > /container.txt

CMD ["cat", "/container.txt"]
```

This provides a transparent and repeatable build.

---

# 46. Container data loss during deployment

Imagine this deployment:

```bash
docker run -d \
  --name dashboard \
  dashboard:1.0
```

The application writes important data to:

```text
/app/data/database.sqlite
```

You then upgrade:

```bash
docker rm -f dashboard
```

```bash
docker run -d \
  --name dashboard \
  dashboard:2.0
```

The new container does not receive the old writable layer.

The database is lost unless it was:

- Mounted from the host
    
- Stored in a Docker volume
    
- Stored in an external database
    
- Backed up and restored
    

This is one of the most serious beginner mistakes.

---

# 47. Persistent does not mean backed up

Even when you later use a volume, remember:

```text
Persistence ≠ Backup
```

A volume can survive container deletion, but it may still be lost through:

- Disk failure
    
- Accidental volume deletion
    
- Corruption
    
- Host loss
    
- Ransomware
    
- Incorrect application migrations
    

A complete storage strategy requires:

- Persistence
    
- Backup
    
- Restore testing
    
- Access control
    
- Monitoring
    
- Upgrade planning
    

You will practice backup and restore later in the course.

---

# 48. Day 8 practical laboratory

## Exercise 1 — Create and restart

Run:

```bash
docker run -it \
  --name day8-lab \
  alpine \
  sh
```

Inside:

```sh
mkdir -p /data
echo "Day 8 data" > /data/message.txt
exit
```

Restart:

```bash
docker start -ai day8-lab
```

Verify:

```sh
cat /data/message.txt
exit
```

Explain why it remains.

---

## Exercise 2 — Remove and recreate

Remove:

```bash
docker rm day8-lab
```

Recreate:

```bash
docker run -it \
  --name day8-lab \
  alpine \
  sh
```

Check:

```sh
cat /data/message.txt
```

Explain why it is gone.

Exit and remove:

```sh
exit
```

```bash
docker rm day8-lab
```

---

## Exercise 3 — Independent writable layers

Run:

```bash
docker run -d \
  --name day8-a \
  alpine \
  sleep 3600
```

```bash
docker run -d \
  --name day8-b \
  alpine \
  sleep 3600
```

Write different content:

```bash
docker exec day8-a \
  sh -c 'echo A > /identity.txt'
```

```bash
docker exec day8-b \
  sh -c 'echo B > /identity.txt'
```

Verify both.

---

## Exercise 4 — Inspect changes

Run:

```bash
docker diff day8-a
```

Add and remove files:

```bash
docker exec day8-a \
  sh -c '
    mkdir /example &&
    echo test > /example/file.txt &&
    rm /identity.txt
  '
```

Run:

```bash
docker diff day8-a
```

Identify added, changed, and deleted paths.

---

## Exercise 5 — Inspect writable size

Create a file:

```bash
docker exec day8-a \
  sh -c 'dd if=/dev/zero of=/data.bin bs=1M count=25'
```

Inspect:

```bash
docker ps -s \
  --filter name=day8-a
```

Remove the file:

```bash
docker exec day8-a \
  rm /data.bin
```

Inspect again.

Storage reclamation details may vary by storage driver, but the exercise demonstrates that writes consume host storage.

---

## Exercise 6 — Recover a file

Create:

```bash
docker exec day8-b \
  sh -c 'echo "Recover me" > /important.txt'
```

Copy it to the host:

```bash
docker cp \
  day8-b:/important.txt \
  ./important-recovered.txt
```

Read:

```bash
cat important-recovered.txt
```

Remove the container and confirm the host copy remains.

---

## Exercise 7 — Simulate database loss

Run:

```bash
docker run -it \
  --name fake-database \
  alpine \
  sh
```

Inside:

```sh
mkdir -p /var/lib/fake-db
echo "record-001" > /var/lib/fake-db/data.txt
exit
```

Restart and verify the record.

Then remove and recreate the container.

Confirm the record is lost.

---

## Exercise 8 — Inspect mounts

Run:

```bash
docker inspect day8-a \
  --format '{{json .Mounts}}'
```

Confirm there are no deliberate external mounts.

This means all new files exist in the container writable layer.

---

## Exercise 9 — Commit for demonstration

Create a file in `day8-a`:

```bash
docker exec day8-a \
  sh -c 'echo "Captured by commit" > /committed.txt'
```

Commit:

```bash
docker commit \
  day8-a \
  day8-commit-demo:1.0
```

Test:

```bash
docker run --rm \
  day8-commit-demo:1.0 \
  cat /committed.txt
```

Then explain why a Dockerfile is still preferable.

---

## Exercise 10 — Cleanup

Remove containers:

```bash
docker rm -f \
  day8-a \
  day8-b \
  day8-container-a \
  day8-container-b \
  day8-storage-test \
  fake-database \
  2>/dev/null
```

Remove the committed image:

```bash
docker image rm \
  day8-commit-demo:1.0 \
  day8-committed-image:1.0 \
  2>/dev/null
```

Remove recovered training files if desired:

```bash
rm -f \
  host-file.txt \
  recovered-container.txt \
  important-recovered.txt
```

---

# 49. Common misunderstandings

## “Stopping a container deletes its files.”

Incorrect.

Stopping preserves the container and its writable layer.

---

## “Starting another container from the same image gives me the old files.”

Incorrect.

Each new container gets a new writable layer.

---

## “Using the same container name restores the old container.”

Incorrect.

The name may be reused, but the old writable layer is gone after removal.

---

## “Restart policies protect my database.”

Incorrect.

They restart the same container but do not protect against removal, host loss, or storage failure.

---

## “A volume is a backup.”

Incorrect.

A volume provides persistence, not a complete backup strategy.

---

## “Files created with `docker cp` are persistent.”

Only for the lifetime of that container unless copied into mounted persistent storage.

---

## “I can manually configure a container and keep it forever.”

Technically possible, operationally fragile.

Build reproducible images and externalize important data.

---

# 50. Day 8 command reference

```bash
# Inspect containers, including stopped ones
docker ps -a

# Restart and attach to the same container
docker start -ai CONTAINER

# Show filesystem differences
docker diff CONTAINER

# Show container writable size
docker ps -s

# Copy a file into a container
docker cp SOURCE CONTAINER:DESTINATION

# Copy a file out of a container
docker cp CONTAINER:SOURCE DESTINATION

# Inspect deliberate mounts
docker inspect CONTAINER \
  --format '{{json .Mounts}}'

# Inspect storage-driver information
docker inspect CONTAINER \
  --format '{{json .GraphDriver}}'

# Inspect Docker storage driver
docker info \
  --format '{{.Driver}}'

# Inspect Docker root directory
docker info \
  --format '{{.DockerRootDir}}'

# Inspect Docker disk usage
docker system df

# Detailed disk usage
docker system df -v

# Create an image from a container, for demonstration
docker commit CONTAINER IMAGE:TAG
```

---

# 51. Knowledge check

## Question 1

What is the container writable layer?

It is the container-specific filesystem layer where files created or modified at runtime are stored.

## Question 2

Does stopping a container delete its writable layer?

No. The writable layer remains while the container exists.

## Question 3

What happens when the container is removed?

Its writable layer is normally removed with it.

## Question 4

Does creating a new container from the same image restore the previous container’s files?

No. The new container receives a new writable layer.

## Question 5

Do two containers created from the same image share runtime files?

No. They have separate writable layers unless both use deliberately shared external storage.

## Question 6

Does modifying a file inside a container modify the image?

No. The change is stored in the container writable layer.

## Question 7

What does `docker diff` show?

Files and directories added, changed, or deleted relative to the image.

## Question 8

Why should database files not remain only in the writable layer?

Because removing or replacing the container deletes that layer and may destroy the database.

## Question 9

What kinds of data can safely remain ephemeral?

Temporary files, recreatable caches, and disposable processing data.

## Question 10

Does a restart policy replace a persistent-storage strategy?

No. Restart policies manage process recovery; persistent storage manages data lifetime.

---

# 52. Day 8 completion challenge

Complete this without copying the earlier commands:

1. Start an Alpine container named `day8-challenge`.
    
2. Create `/data/state.txt` inside it.
    
3. Stop the container.
    
4. Restart the same container.
    
5. Verify the file remains.
    
6. Record the container ID.
    
7. Remove the container.
    
8. Recreate a container with the same name.
    
9. Record the new ID.
    
10. Confirm the IDs differ.
    
11. Confirm the file no longer exists.
    
12. Start two containers from the same image.
    
13. Create a different `/state.txt` in each.
    
14. Verify they do not share those files.
    
15. Use `docker diff` on both containers.
    
16. Create a 20 MB file in one container.
    
17. Inspect its writable-layer size.
    
18. Copy an important file from the container to the host.
    
19. Remove the container.
    
20. Confirm the host copy remains.
    
21. Explain which files in your MQTT dashboard belong in the image.
    
22. Explain which files require persistent storage.
    
23. Explain why SQLite inside an unmounted container is unsafe.
    
24. Explain why Mosquitto persistence should not depend on the container writable layer.
    
25. Clean up all challenge containers.
    

The central Day 8 model is:

```text
Read-only image
       +
Container-specific writable layer
       ↓
Running container
```

And the critical lifecycle rule is:

```text
Stop/start same container:
data remains

Remove/recreate container:
writable-layer data disappears
```

The most important operational lesson is:

> Treat containers as replaceable. Keep application code in images, temporary data in ephemeral storage, and important data in storage whose lifetime is independent of the container.