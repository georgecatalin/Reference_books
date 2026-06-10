
Day 9 introduced **bind mounts**, where you choose an exact host path:

```text
Host: /home/george/project/data
Container: /app/data
```

Today you will learn **Docker volumes**.

A Docker volume is persistent storage managed by Docker rather than by an application container.

```text
Docker volume
     ↓ mounted into
Container directory
```

The central lesson is:

> A named volume has its own lifecycle. Removing and recreating a container does not remove the volume or its data.

By the end of Day 10, you should understand:

- What Docker volumes are
    
- Named and anonymous volumes
    
- Volumes versus bind mounts
    
- Creating, listing, inspecting, mounting, and removing volumes
    
- How volume initialization works
    
- How to share a volume between containers
    
- Read-only volume mounts
    
- Database persistence
    
- Backup and restore
    
- Permission problems
    
- Why persistence is not the same as backup
    

---

## 1. Why Docker volumes exist

Bind mounts are useful, but they require you to manage an exact host path:

```bash
--mount type=bind,source=/home/george/data,target=/data
```

This couples the container configuration to:

```text
/home/george/data
```

A volume instead uses a Docker-managed name:

```bash
--mount type=volume,source=application-data,target=/data
```

The application cares only about:

```text
application-data
```

Docker determines where the actual files are stored on the host.

Conceptually:

```text
Bind mount:
you manage the host path

Volume:
Docker manages the storage location
```

---

# 2. Volume versus container writable layer

Without a volume:

```text
Container
└── /data/database.db

Container removed
→ database disappears
```

With a volume:

```text
Docker volume: database-data
        ↓
Container: /data/database.db

Container removed
→ volume remains
→ database remains
```

A replacement container can mount the same volume.

---

# 3. Volume versus bind mount

|Characteristic|Docker volume|Bind mount|
|---|---|---|
|Host path chosen by you|No|Yes|
|Managed through Docker CLI|Yes|Partly|
|Portable Docker configuration|Better|Host-dependent|
|Easy host file access|Less direct|Very direct|
|Good for databases|Yes|Possible|
|Good for live source editing|Usually no|Yes|
|Survives container removal|Yes|Yes|
|Can be shared|Yes|Yes|
|Backup required|Yes|Yes|

A practical rule:

```text
Application source during development
→ bind mount

Database or application data
→ named volume

Host-managed configuration file
→ read-only bind mount
```

---

# 4. List existing volumes

Run:

```bash
docker volume ls
```

Typical output:

```text
DRIVER    VOLUME NAME
local     some-project_database-data
local     application-cache
```

The default driver is commonly:

```text
local
```

The `local` driver stores data on the Docker host.

---

# 5. Create your first named volume

Run:

```bash
docker volume create day10-data
```

Docker returns:

```text
day10-data
```

List it:

```bash
docker volume ls
```

Filter:

```bash
docker volume ls \
  --filter name=day10
```

A volume can exist without being attached to a container.

---

# 6. Inspect a volume

Run:

```bash
docker volume inspect day10-data
```

The result contains fields such as:

- Name
    
- Driver
    
- Mountpoint
    
- Scope
    
- Labels
    
- Options
    

Extract selected values:

```bash
docker volume inspect day10-data \
  --format 'Name={{.Name}} Driver={{.Driver}} Mountpoint={{.Mountpoint}}'
```

On native Linux, the mountpoint may resemble:

```text
/var/lib/docker/volumes/day10-data/_data
```

Do not make your application depend on that internal path.

Use the volume name through Docker.

---

# 7. Mount the volume into a container

Run:

```bash
docker run -it \
  --name day10-writer \
  --mount type=volume,source=day10-data,target=/data \
  alpine \
  sh
```

Inside:

```sh
echo "Persistent volume data" > /data/message.txt
cat /data/message.txt
ls -la /data
exit
```

The container stops, but the volume remains.

---

# 8. Remove the container and preserve the data

Remove:

```bash
docker rm day10-writer
```

Confirm the container is gone:

```bash
docker ps -a \
  --filter name=day10-writer
```

Confirm the volume remains:

```bash
docker volume ls \
  --filter name=day10-data
```

Create a new container:

```bash
docker run --rm \
  --mount type=volume,source=day10-data,target=/data \
  alpine \
  cat /data/message.txt
```

Expected:

```text
Persistent volume data
```

The old container’s writable layer is gone, but the volume survived.

---

# 9. The shorter `-v` volume syntax

The same named volume can be mounted using:

```bash
docker run --rm \
  -v day10-data:/data \
  alpine \
  cat /data/message.txt
```

The syntax is:

```text
-v VOLUME_NAME:CONTAINER_PATH
```

Compare:

```bash
-v "$PWD/data:/data"
```

Because the source contains a host path, this is a bind mount.

```bash
-v day10-data:/data
```

Because the source is a volume name, this is a named volume.

---

# 10. Prefer explicit `--mount` syntax while learning

Named-volume syntax:

```bash
--mount type=volume,source=day10-data,target=/data
```

Bind-mount syntax:

```bash
--mount type=bind,source="$PWD/data",target=/data
```

The explicit type removes ambiguity.

For short interactive commands, `-v` is convenient. For documentation and scripts, `--mount` is often clearer.

---

# 11. Docker creates a missing named volume automatically

If this volume does not exist:

```text
automatic-volume
```

this command creates it:

```bash
docker run --rm \
  --mount type=volume,source=automatic-volume,target=/data \
  alpine \
  sh -c 'echo created > /data/file.txt'
```

Check:

```bash
docker volume ls \
  --filter name=automatic-volume
```

Named volumes may therefore be:

- Created explicitly using `docker volume create`
    
- Created automatically when first mounted
    

Explicit creation is clearer when you need labels or driver options.

---

# 12. Named volumes

A named volume has a meaningful name:

```text
postgres-data
mosquitto-data
dashboard-uploads
```

Example:

```bash
docker volume create postgres-data
```

Named volumes are easy to:

- Identify
    
- Reuse
    
- Inspect
    
- Back up
    
- Reference in Compose
    
- Manage deliberately
    

For important persistent data, prefer a meaningful named volume.

---

# 13. Anonymous volumes

An anonymous volume has no user-selected name.

Example:

```bash
docker run -d \
  --name anonymous-test \
  --mount type=volume,target=/data \
  alpine \
  sleep 3600
```

Inspect:

```bash
docker inspect anonymous-test \
  --format '{{json .Mounts}}'
```

Docker assigns a long generated name.

List volumes:

```bash
docker volume ls
```

Anonymous volumes can persist after container removal, making them harder to identify.

They are useful in some automated workflows but are often confusing for beginners.

---

# 14. Removing a container with an anonymous volume

Remove the container normally:

```bash
docker rm -f anonymous-test
```

The anonymous volume may remain.

List dangling volumes:

```bash
docker volume ls \
  --filter dangling=true
```

This can create storage clutter.

A container can be removed together with its anonymous volumes using:

```bash
docker rm -v CONTAINER
```

or for an automatically removed container:

```bash
docker run --rm \
  --mount type=volume,target=/data \
  alpine \
  true
```

Be careful: `-v` on `docker rm` concerns anonymous volumes attached to that container. Named volumes are normally preserved.

---

# 15. Named volumes are not removed with the container

Create:

```bash
docker run -d \
  --name named-volume-test \
  --mount type=volume,source=day10-data,target=/data \
  alpine \
  sleep 3600
```

Remove with:

```bash
docker rm -fv named-volume-test
```

Check:

```bash
docker volume ls \
  --filter name=day10-data
```

The named volume remains.

This is deliberate protection for persistent data.

---

# 16. Share one volume between containers

Write data using one container:

```bash
docker run --rm \
  --mount type=volume,source=day10-shared,target=/shared \
  alpine \
  sh -c 'echo "Written by container A" > /shared/message.txt'
```

Read it using another:

```bash
docker run --rm \
  --mount type=volume,source=day10-shared,target=/shared \
  alpine \
  cat /shared/message.txt
```

The two containers are separate.

They communicate through shared storage:

```text
Container A
     ↓ writes
Docker volume
     ↑ reads
Container B
```

---

# 17. Concurrent volume sharing requires application support

Docker allows multiple containers to mount the same volume.

That does not mean every application can safely share it concurrently.

Safe example:

```text
One container writes static generated files
Another container reads them
```

Potentially unsafe example:

```text
Two SQLite application containers
both writing the same SQLite file
```

Safety depends on:

- Filesystem semantics
    
- Application locking
    
- Database design
    
- Number of writers
    
- Networked versus local storage
    
- Failure behavior
    

Docker only mounts the storage. It does not make application-level concurrency safe.

---

# 18. Read-only volume mounts

Mount a volume read-only:

```bash
docker run --rm \
  --mount type=volume,source=day10-data,target=/data,readonly \
  alpine \
  cat /data/message.txt
```

Attempt to write:

```bash
docker run --rm \
  --mount type=volume,source=day10-data,target=/data,readonly \
  alpine \
  sh -c 'echo test > /data/test.txt'
```

It should fail.

Short syntax:

```bash
-v day10-data:/data:ro
```

Use read-only access for consumers that do not need to modify the data.

---

# 19. A useful producer-consumer pattern

One container writes files:

```bash
docker run --rm \
  --mount type=volume,source=reports,target=/reports \
  alpine \
  sh -c 'echo "Report content" > /reports/report.txt'
```

Another reads them without write access:

```bash
docker run --rm \
  --mount type=volume,source=reports,target=/usr/share/nginx/html,readonly \
  nginx:alpine
```

Architecture:

```text
Report generator
      ↓ writable
reports volume
      ↓ read-only
Web server
```

This limits unnecessary write permissions.

---

# 20. Mounting a volume hides image content

Just like bind mounts, volumes hide files already present at the target path.

Suppose an image contains:

```text
/app/data/default.txt
```

Mounting an existing volume at `/app/data` covers that directory:

```bash
--mount type=volume,source=application-data,target=/app/data
```

The container sees the volume’s contents rather than the image directory beneath it.

However, Docker volumes have an important initialization behavior.

---

# 21. Volume initialization from image content

When an **empty volume** is mounted over a non-empty directory in the image, Docker commonly copies the existing image-directory contents into the volume.

Create a small image:

```bash
mkdir -p ~/docker-course/day10/volume-init
cd ~/docker-course/day10/volume-init
```

Create `Dockerfile`:

```dockerfile
FROM alpine

RUN mkdir -p /app/data \
    && echo "Default seed file" > /app/data/default.txt

CMD ["sleep", "3600"]
```

Build:

```bash
docker build -t day10-volume-init:1.0 .
```

Create and mount a new empty volume:

```bash
docker run -d \
  --name volume-init-test \
  --mount type=volume,source=day10-init-data,target=/app/data \
  day10-volume-init:1.0
```

Read:

```bash
docker exec volume-init-test \
  cat /app/data/default.txt
```

The default file should exist in the volume.

---

# 22. The initialization occurs only when the volume is empty

Add another file:

```bash
docker exec volume-init-test \
  sh -c 'echo "Runtime data" > /app/data/runtime.txt'
```

Remove the container:

```bash
docker rm -f volume-init-test
```

Modify the Dockerfile’s default file:

```dockerfile
RUN mkdir -p /app/data \
    && echo "New image default" > /app/data/default.txt \
    && echo "New image file" > /app/data/new.txt
```

Rebuild:

```bash
docker build -t day10-volume-init:2.0 .
```

Run version 2 with the existing volume:

```bash
docker run -d \
  --name volume-init-test \
  --mount type=volume,source=day10-init-data,target=/app/data \
  day10-volume-init:2.0
```

Inspect:

```bash
docker exec volume-init-test \
  ls -la /app/data
```

The existing volume content remains.

Docker does not overwrite a non-empty volume with every new image version.

This prevents runtime data from being silently replaced.

---

# 23. The `volume-nocopy` option

Sometimes you do not want Docker to copy image content into a new empty volume.

Use:

```bash
docker run --rm \
  --mount type=volume,source=no-copy-data,target=/app/data,volume-nocopy \
  day10-volume-init:1.0 \
  ls -la /app/data
```

The volume should remain empty.

This is an advanced but useful distinction when image seed files must not populate storage automatically.

---

# 24. Volumes and database images

Official database images commonly declare or use persistent data directories.

Examples:

```text
PostgreSQL:
/var/lib/postgresql/data

MariaDB/MySQL:
/var/lib/mysql

MongoDB:
/data/db
```

A named volume should normally be mounted at the database’s documented data directory.

Example:

```bash
docker volume create day10-postgres-data
```

Then:

```bash
docker run -d \
  --name day10-postgres \
  -e POSTGRES_USER=appuser \
  -e POSTGRES_PASSWORD=development-password \
  -e POSTGRES_DB=device_monitor \
  --mount type=volume,source=day10-postgres-data,target=/var/lib/postgresql/data \
  postgres:17
```

Do not place the mount at an arbitrary location unless PostgreSQL is configured to use it.

---

# 25. Inspect PostgreSQL startup

Follow logs:

```bash
docker logs -f day10-postgres
```

Wait until you see that the database is ready to accept connections.

Stop following with `Ctrl+C`.

Check:

```bash
docker ps \
  --filter name=day10-postgres
```

The database container should remain running.

---

# 26. Create persistent database data

Execute SQL:

```bash
docker exec -it day10-postgres \
  psql \
  -U appuser \
  -d device_monitor
```

Inside `psql`:

```sql
CREATE TABLE devices (
    id SERIAL PRIMARY KEY,
    device_name TEXT NOT NULL,
    online BOOLEAN NOT NULL
);
```

Insert data:

```sql
INSERT INTO devices (device_name, online)
VALUES
    ('vm-karlsfeld-01', TRUE),
    ('testing-vm2', TRUE),
    ('remote-device-03', FALSE);
```

Query:

```sql
SELECT * FROM devices;
```

Exit:

```text
\q
```

---

# 27. Remove and recreate PostgreSQL

Remove the container:

```bash
docker rm -f day10-postgres
```

Confirm the volume remains:

```bash
docker volume ls \
  --filter name=day10-postgres-data
```

Recreate the database container with the same settings and volume:

```bash
docker run -d \
  --name day10-postgres \
  -e POSTGRES_USER=appuser \
  -e POSTGRES_PASSWORD=development-password \
  -e POSTGRES_DB=device_monitor \
  --mount type=volume,source=day10-postgres-data,target=/var/lib/postgresql/data \
  postgres:17
```

Wait for readiness:

```bash
docker logs -f day10-postgres
```

Query:

```bash
docker exec day10-postgres \
  psql \
  -U appuser \
  -d device_monitor \
  -c 'SELECT * FROM devices;'
```

The records should remain.

---

# 28. Environment variables do not reinitialize an existing database

Database images often use environment variables only during first initialization of an empty data directory.

For example:

```text
POSTGRES_USER
POSTGRES_PASSWORD
POSTGRES_DB
```

When the volume already contains an initialized database, changing those variables does not recreate the database or automatically change existing users and passwords.

This is a common source of confusion.

The model is:

```text
Empty volume
→ entrypoint initializes database using environment variables

Existing initialized volume
→ database starts from existing files
→ initialization variables are not reapplied as a fresh setup
```

---

# 29. Be careful with database image upgrades

Do not assume that this is always safe:

```text
postgres:17
→ replace directly with postgres:18
→ reuse same data volume
```

Major database versions may require:

- Migration
    
- Upgrade utilities
    
- Dump and restore
    
- Compatibility review
    
- Backup and rollback planning
    

A volume preserves files. It does not make those files compatible with every application version.

---

# 30. SQLite in a named volume

For your PHP dashboard, a named volume could be mounted at:

```text
/var/www/html/data
```

Example:

```bash
docker volume create mqtt-dashboard-data
```

Run:

```bash
docker run -d \
  --name mqtt-dashboard \
  -p 8080:80 \
  --mount type=volume,source=mqtt-dashboard-data,target=/var/www/html/data \
  mqtt-dashboard:1.0
```

The SQLite database:

```text
/var/www/html/data/cockpit.sqlite
```

then lives in the volume.

Removing and recreating the dashboard container preserves the database file.

---

# 31. Named volume or bind mount for SQLite?

Use a named volume when:

- Docker should manage storage
    
- You do not need frequent direct host access
    
- Portability and lifecycle management matter
    
- The application owns the data
    

Use a bind mount when:

- You need to inspect the SQLite file directly
    
- You want a known host path
    
- Host backup tools operate on that directory
    
- You are developing and troubleshooting permissions
    

Both can work. Choose based on operational requirements.

---

# 32. Mosquitto with named volumes

Create:

```bash
docker volume create mosquitto-data
```

Configuration may remain a read-only bind mount:

```bash
mkdir -p mosquitto/config
```

Example run:

```bash
docker run -d \
  --name day10-mosquitto \
  -p 1883:1883 \
  --mount type=bind,source="$PWD/mosquitto/config/mosquitto.conf",target=/mosquitto/config/mosquitto.conf,readonly \
  --mount type=volume,source=mosquitto-data,target=/mosquitto/data \
  eclipse-mosquitto:2
```

This is a useful mixed-storage pattern:

```text
Configuration
→ read-only bind mount

Persistent broker data
→ named volume

Logs
→ stdout
```

---

# 33. Inspect volumes attached to a container

Run:

```bash
docker inspect day10-postgres \
  --format '{{range .Mounts}}Type={{.Type}} Name={{.Name}} Source={{.Source}} Destination={{.Destination}} RW={{.RW}}{{println}}{{end}}'
```

Expected conceptually:

```text
Type=volume
Name=day10-postgres-data
Source=/var/lib/docker/volumes/day10-postgres-data/_data
Destination=/var/lib/postgresql/data
RW=true
```

Applications should rely on the volume name and container destination, not the internal source path.

---

# 34. Find which containers use a volume

The Docker CLI does not have a single perfect human-friendly command for every version, but you can inspect containers:

```bash
docker ps -a \
  --filter volume=day10-postgres-data
```

This lists containers using that volume.

Before removing an important volume, check whether any container depends on it.

---

# 35. Removing a volume in use

Try:

```bash
docker volume rm day10-postgres-data
```

while PostgreSQL is using it.

Docker should refuse.

This protects active storage.

Remove the container first:

```bash
docker rm -f day10-postgres
```

The volume can then be removed—but do not do so until after the backup exercises.

---

# 36. Remove a named volume

Syntax:

```bash
docker volume rm VOLUME_NAME
```

Example:

```bash
docker volume rm automatic-volume
```

This permanently removes the stored data.

A container can be recreated.

A deleted volume cannot be magically reconstructed unless:

- You have a backup
    
- The data can be regenerated
    
- Another copy exists
    

Treat volume deletion as destructive.

---

# 37. Prune unused volumes

List dangling volumes:

```bash
docker volume ls \
  --filter dangling=true
```

Remove unused local volumes:

```bash
docker volume prune
```

Docker asks for confirmation.

This command can delete important but currently unattached data.

A database volume is often unattached during:

- Maintenance
    
- Upgrades
    
- Container replacement
    
- Troubleshooting
    

Do not assume “unused by a container right now” means “unimportant.”

---

# 38. Labels for volume management

Create a labeled volume:

```bash
docker volume create \
  --label project=mqtt-platform \
  --label purpose=database \
  mqtt-platform-database
```

Inspect:

```bash
docker volume inspect mqtt-platform-database \
  --format '{{json .Labels}}'
```

Filter:

```bash
docker volume ls \
  --filter label=project=mqtt-platform
```

Labels help organize storage on hosts with many projects.

---

# 39. Volume permissions

Volumes do not eliminate Linux ownership and permission requirements.

If a process runs as UID 10001 and the volume root is owned by root with restrictive permissions, writes may fail.

Test:

```bash
docker volume create day10-permissions
```

Run as UID 10001:

```bash
docker run --rm \
  --user 10001:10001 \
  --mount type=volume,source=day10-permissions,target=/data \
  alpine \
  sh -c 'echo test > /data/file.txt'
```

Depending on the volume’s current ownership, this may fail.

---

# 40. Initialize volume ownership

One common approach is an initialization container running as root:

```bash
docker run --rm \
  --user root \
  --mount type=volume,source=day10-permissions,target=/data \
  alpine \
  chown -R 10001:10001 /data
```

Then run as the application user:

```bash
docker run --rm \
  --user 10001:10001 \
  --mount type=volume,source=day10-permissions,target=/data \
  alpine \
  sh -c 'echo success > /data/file.txt'
```

Verify:

```bash
docker run --rm \
  --mount type=volume,source=day10-permissions,target=/data \
  alpine \
  ls -ln /data
```

This is better than making the directory world-writable.

---

# 41. Image initialization scripts and permissions

Many official images handle volume ownership during startup.

For example, a database image may:

1. Start initially as root
    
2. Set ownership on the data directory
    
3. Drop privileges
    
4. Start the database as its dedicated user
    

Do not assume every image does this.

Read the image documentation and inspect:

```bash
docker image inspect IMAGE
```

When writing your own image, explicitly design:

- The runtime user
    
- Writable directories
    
- Volume ownership
    
- Startup initialization behavior
    

---

# 42. Back up a named volume using a utility container

Create a backup directory:

```bash
mkdir -p ~/docker-course/day10/backups
cd ~/docker-course/day10
```

Back up `day10-data`:

```bash
docker run --rm \
  --mount type=volume,source=day10-data,target=/source,readonly \
  --mount type=bind,source="$PWD/backups",target=/backup \
  alpine \
  tar czf /backup/day10-data.tar.gz -C /source .
```

Inspect:

```bash
ls -lh backups/day10-data.tar.gz
```

List the archive:

```bash
tar tzf backups/day10-data.tar.gz
```

This pattern combines:

- Read-only source volume
    
- Host bind mount for the backup output
    
- Temporary utility container
    

---

# 43. Understand the backup command

The command inside the utility container is:

```bash
tar czf /backup/day10-data.tar.gz -C /source .
```

Meaning:

```text
tar
→ archive utility

c
→ create archive

z
→ gzip compression

f
→ use the following archive filename

-C /source
→ change to the volume directory

.
→ archive all content there
```

Using `-C /source .` avoids storing an unnecessary absolute path structure in the archive.

---

# 44. Restore into a new volume

Create a new volume:

```bash
docker volume create day10-restored
```

Restore:

```bash
docker run --rm \
  --mount type=volume,source=day10-restored,target=/restore \
  --mount type=bind,source="$PWD/backups",target=/backup,readonly \
  alpine \
  tar xzf /backup/day10-data.tar.gz -C /restore
```

Verify:

```bash
docker run --rm \
  --mount type=volume,source=day10-restored,target=/data \
  alpine \
  find /data -maxdepth 2 -type f -print
```

Read:

```bash
docker run --rm \
  --mount type=volume,source=day10-restored,target=/data \
  alpine \
  cat /data/message.txt
```

---

# 45. Restore should usually target an empty volume

Restoring over existing data can produce:

- Mixed old and new files
    
- Stale database files
    
- Incorrect ownership
    
- Inconsistent state
    

A safer workflow is:

```text
Create new empty volume
        ↓
Restore backup
        ↓
Verify data
        ↓
Start application using restored volume
```

For databases, use database-aware tools whenever possible.

---

# 46. Filesystem backup versus database backup

The tar method can work for files that are not changing.

For a running database, copying raw files may be unsafe.

A database may have:

- In-memory state
    
- Write-ahead logs
    
- Partially completed transactions
    
- Open files
    
- Consistency requirements
    

For PostgreSQL, use tools such as:

```bash
pg_dump
```

or:

```bash
pg_dumpall
```

For SQLite, use:

- SQLite’s backup command
    
- A safe database copy procedure
    
- A stopped application
    
- Filesystem snapshots with correct consistency guarantees
    

---

# 47. Logical PostgreSQL backup

Start PostgreSQL again if necessary.

Create a host backup:

```bash
docker exec day10-postgres \
  pg_dump \
  -U appuser \
  -d device_monitor \
  > backups/device_monitor.sql
```

Inspect:

```bash
head backups/device_monitor.sql
```

This creates a logical SQL backup.

It is usually more portable across environments than copying raw database files.

---

# 48. Restore a PostgreSQL logical backup

Create another database:

```bash
docker exec day10-postgres \
  createdb \
  -U appuser \
  device_monitor_restored
```

Restore:

```bash
docker exec -i day10-postgres \
  psql \
  -U appuser \
  -d device_monitor_restored \
  < backups/device_monitor.sql
```

Verify:

```bash
docker exec day10-postgres \
  psql \
  -U appuser \
  -d device_monitor_restored \
  -c 'SELECT * FROM devices;'
```

This tests whether the backup can actually be restored.

A backup that has never been restored is not fully trusted.

---

# 49. Volume names are scoped to a Docker host

A volume named:

```text
postgres-data
```

on server A is unrelated to a volume with the same name on server B.

Volumes do not automatically replicate between hosts.

To move data, you need:

- Backup and restore
    
- External storage
    
- Storage drivers
    
- Database replication
    
- Filesystem replication
    
- Cloud-managed services
    

The volume name itself is only meaningful on its Docker environment.

---

# 50. Volumes do not automatically follow images

Pushing an image to a registry transfers:

- Image layers
    
- Image configuration
    

It does not transfer:

- Named volumes
    
- Database records
    
- Uploaded files
    
- Container writable layers
    

Therefore:

```text
docker push application:1.0
```

does not back up application data.

Image distribution and data movement are separate concerns.

---

# 51. Volume drivers

The default driver is usually:

```text
local
```

Docker can also use plugins or drivers for storage systems such as:

- NFS
    
- Cloud block storage
    
- Distributed storage
    
- Enterprise storage platforms
    

Create syntax can include:

```bash
docker volume create \
  --driver DRIVER_NAME \
  VOLUME_NAME
```

Storage-driver configuration is infrastructure-specific.

For your current single-host learning environment, use the default local driver.

---

# 52. Local volumes are still host-local data

A Docker-managed local volume is easier to manage than an arbitrary application path, but it still resides on that host.

If the entire VM is deleted, the local volume is also lost unless:

- The VM disk is preserved
    
- The volume is backed up
    
- Storage is external
    
- A snapshot exists
    

Docker volumes solve container-lifecycle persistence, not host-disaster recovery.

---

# 53. `VOLUME` in a Dockerfile

A Dockerfile may contain:

```dockerfile
VOLUME ["/data"]
```

This declares that `/data` is intended for externally managed storage.

However, it can also cause Docker to create anonymous volumes if no explicit mount is supplied.

For application images you control, consider whether the instruction adds value.

It does not:

- Create a meaningful named volume
    
- Define backup policy
    
- Choose a host path
    
- Automatically preserve data across hosts
    

You still need runtime or Compose configuration.

---

# 54. Inspect an image’s declared volumes

Run:

```bash
docker image inspect postgres:17 \
  --format '{{json .Config.Volumes}}'
```

You may see declared volume paths.

Also inspect Mosquitto:

```bash
docker image inspect eclipse-mosquitto:2 \
  --format '{{json .Config.Volumes}}'
```

Image-declared volumes communicate storage intent but do not fully define production storage management.

---

# 55. Avoid writing data to the wrong path

Mounting a volume at:

```text
/data
```

does not help if the application actually writes to:

```text
/app/data
```

Example mistake:

```bash
--mount type=volume,source=app-data,target=/data
```

while the SQLite application writes:

```text
/var/www/html/data/cockpit.sqlite
```

The database still goes into the container writable layer.

Always verify the application’s real write location.

Use:

```bash
docker diff CONTAINER
```

to identify unexpected runtime writes.

---

# 56. Verify persistence deliberately

Do not assume a volume is working merely because the container starts.

Test the full lifecycle:

1. Write recognizable data.
    
2. Stop the container.
    
3. Remove the container.
    
4. Confirm the volume exists.
    
5. Create a replacement container.
    
6. Mount the same volume.
    
7. Verify the data.
    
8. Back it up.
    
9. Restore into another volume.
    
10. Verify the restored copy.
    

This is the correct persistence test.

---

# 57. Volume naming strategy

Use meaningful names:

```text
mqtt-platform-postgres
mqtt-platform-mosquitto
mqtt-platform-uploads
```

Avoid vague names:

```text
data
storage
volume1
test
```

A useful format is:

```text
PROJECT-SERVICE-PURPOSE
```

Examples:

```text
device-monitor-postgres-data
device-monitor-mosquitto-data
device-monitor-dashboard-uploads
```

Compose will later help namespace project volumes automatically.

---

# 58. Do not store everything in one volume

Avoid:

```text
mqtt-platform-all-data
```

containing:

- PostgreSQL files
    
- Mosquitto persistence
    
- Uploads
    
- Certificates
    
- Logs
    

Separate volumes allow:

- Different backup schedules
    
- Different permissions
    
- Independent restoration
    
- Cleaner ownership
    
- Easier upgrades
    
- Reduced accidental access
    

Better:

```text
postgres-data
mosquitto-data
dashboard-uploads
```

---

# 59. Volume security

A container with a writable volume can alter all data in that mounted path.

Apply least privilege:

```text
Database container
→ writable database volume

Backup container
→ read-only database volume where possible

Web server
→ read-only static-content volume

Uploader
→ writable uploads volume
```

Do not mount unrelated volumes into containers that do not need them.

---

# 60. Common volume problems

## Data disappeared after recreation

Possible causes:

- No volume was mounted
    
- Wrong volume name
    
- Wrong container target path
    
- Application wrote elsewhere
    
- Volume was deleted
    
- A new anonymous volume was created
    
- Compose project name changed
    

Inspect:

```bash
docker inspect CONTAINER \
  --format '{{json .Mounts}}'
```

---

## Application reports permission denied

Compare:

```bash
docker exec CONTAINER id
```

with ownership in the volume:

```bash
docker run --rm \
  --mount type=volume,source=VOLUME,target=/data \
  alpine \
  ls -ldn /data
```

---

## Database reinitialized unexpectedly

Possible causes:

- Wrong volume name
    
- Volume was empty
    
- Data mounted at wrong path
    
- Old volume was removed
    
- Container used an anonymous volume
    
- Project configuration changed
    

---

## New image files do not appear

The existing non-empty volume hides the image directory and preserves its old content.

Use migrations or explicit initialization rather than expecting the new image to overwrite existing data.

---

## Cannot remove volume

A container may still reference it.

Check:

```bash
docker ps -a \
  --filter volume=VOLUME_NAME
```

---

## Disk usage keeps growing

Inspect:

```bash
docker system df -v
docker volume ls
```

Review:

- Old test volumes
    
- Database growth
    
- Uploads
    
- Backup archives
    
- Anonymous volumes
    

---

# 61. Day 10 practical laboratory

## Exercise 1 — Named volume lifecycle

Create `day10-lab`.

Mount it at `/data`.

Create a file.

Remove the container.

Create another container using the same volume.

Verify the file remains.

---

## Exercise 2 — Separate container identities

Create two different containers that mount `day10-lab`.

Confirm:

- Different container IDs
    
- Same stored file
    
- Same named volume
    

---

## Exercise 3 — Read-only consumer

Use one container to write into a volume.

Use another to mount it read-only.

Confirm reads work and writes fail.

---

## Exercise 4 — Anonymous volume

Run a container with an anonymous volume.

Inspect its generated name.

Remove the container.

Determine whether the volume remains.

Remove it deliberately.

---

## Exercise 5 — Image-to-volume initialization

Build an image containing seed files under `/app/data`.

Mount a new volume there.

Confirm the files populate the empty volume.

Rebuild the image with different seed files.

Reuse the existing volume and observe that runtime data is preserved.

---

## Exercise 6 — Permissions

Create a volume.

Attempt to write as UID 10001.

If necessary, initialize ownership using a temporary root container.

Write successfully as UID 10001.

Avoid `chmod 777`.

---

## Exercise 7 — PostgreSQL

Create a PostgreSQL named volume.

Start PostgreSQL using it.

Create a table and records.

Remove the container.

Recreate it with the same volume.

Verify the records remain.

---

## Exercise 8 — Backup a volume

Use a temporary Alpine container to create a compressed archive of a named volume.

Store the archive in a bind-mounted host backup directory.

Inspect the archive.

---

## Exercise 9 — Restore a volume

Create a new empty volume.

Restore the archive into it.

Mount the restored volume in a temporary container.

Verify its data.

---

## Exercise 10 — Database-aware backup

Create a PostgreSQL logical dump.

Restore it into another database.

Query the restored records.

---

# 62. Day 10 command reference

```bash
# Create a named volume
docker volume create VOLUME

# List volumes
docker volume ls

# Inspect a volume
docker volume inspect VOLUME

# Remove a volume
docker volume rm VOLUME

# Remove unused volumes
docker volume prune

# Mount a named volume
docker run \
  --mount type=volume,source=VOLUME,target=/data \
  IMAGE

# Short syntax
docker run \
  -v VOLUME:/data \
  IMAGE

# Read-only volume
docker run \
  --mount type=volume,source=VOLUME,target=/data,readonly \
  IMAGE

# Mount without copying image content
docker run \
  --mount type=volume,source=VOLUME,target=/data,volume-nocopy \
  IMAGE

# Find containers using a volume
docker ps -a \
  --filter volume=VOLUME

# Inspect container mounts
docker inspect CONTAINER \
  --format '{{range .Mounts}}Type={{.Type}} Name={{.Name}} Destination={{.Destination}} RW={{.RW}}{{println}}{{end}}'

# Back up a volume
docker run --rm \
  --mount type=volume,source=VOLUME,target=/source,readonly \
  --mount type=bind,source="$PWD/backups",target=/backup \
  alpine \
  tar czf /backup/volume.tar.gz -C /source .

# Restore a volume
docker run --rm \
  --mount type=volume,source=RESTORE_VOLUME,target=/restore \
  --mount type=bind,source="$PWD/backups",target=/backup,readonly \
  alpine \
  tar xzf /backup/volume.tar.gz -C /restore
```

---

# 63. Knowledge check

## What is a Docker volume?

Persistent storage managed through Docker and mounted into one or more containers.

## Does removing a container remove its named volumes?

Normally no.

## What is the difference between a bind mount and a volume?

A bind mount uses a host path chosen by you. A volume uses Docker-managed storage referenced by name.

## What is an anonymous volume?

A volume with a Docker-generated name rather than a user-selected name.

## Can multiple containers mount one volume?

Yes, but the application must support the resulting concurrent access pattern.

## Can a volume be mounted read-only?

Yes, using `readonly` or `:ro`.

## What happens when a new empty volume is mounted over non-empty image content?

Docker commonly copies the image-directory content into the empty volume unless `volume-nocopy` is used.

## Why do new image files not automatically appear in an existing volume?

The non-empty volume has its own persistent content and hides the image directory beneath it.

## Is a volume a backup?

No. It protects data from container replacement, but not necessarily from deletion, corruption, or host failure.

## Why are logical database backups useful?

They create database-aware, portable backups that can be restored and validated independently of raw filesystem storage.

---

# 64. Day 10 completion challenge

Complete this independently:

1. Create a named volume called `challenge-data`.
    
2. Mount it in an Alpine container at `/data`.
    
3. Create three files.
    
4. Remove the container.
    
5. Confirm the volume remains.
    
6. Create a replacement container.
    
7. Verify all three files.
    
8. Mount the volume read-only in another container.
    
9. Confirm reads work.
    
10. Confirm writes fail.
    
11. Create an anonymous volume.
    
12. Inspect its generated name.
    
13. Remove the container.
    
14. Determine whether the anonymous volume remains.
    
15. Remove it explicitly.
    
16. Build an image containing seed data under `/app/data`.
    
17. Mount a new volume there.
    
18. Confirm the seed data is copied.
    
19. Add runtime data.
    
20. Rebuild the image with new seed content.
    
21. Reuse the old volume.
    
22. Explain why its data was not overwritten.
    
23. Create a volume writable by UID 10001.
    
24. Set ownership without using `777`.
    
25. Start PostgreSQL with a named volume.
    
26. Create a table and insert records.
    
27. Remove PostgreSQL.
    
28. Recreate it with the same volume.
    
29. Verify the records.
    
30. Back up one named volume to a tar archive.
    
31. Restore it into a different volume.
    
32. Verify the restored data.
    
33. Create a logical PostgreSQL backup.
    
34. Restore it into another database.
    
35. Explain why persistence and backup are separate requirements.
    

The central Day 10 model is:

```text
Docker-managed volume
        ↓
Mounted into container
        ↓
Application reads and writes data
        ↓
Container replaced
        ↓
Volume and data remain
```

The most important operational lesson is:

> Use named volumes for Docker-managed persistent application data, keep their lifecycle separate from containers, verify persistence through container replacement, and maintain tested backups because persistence alone does not protect against data loss.