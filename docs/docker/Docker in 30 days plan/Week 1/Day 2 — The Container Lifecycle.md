[[30 days Docker]]

Day 1 introduced images, containers, and basic commands. Day 2 focuses on the complete lifecycle of a container:

```text
Image
  ↓
Created container
  ↓
Running container
  ↓
Stopped container
  ↓
Restarted or removed
```

By the end of this lesson, you should understand:

- How Docker creates and starts containers
    
- The difference between `run`, `create`, and `start`
    
- How to stop, restart, pause, kill, and remove containers
    
- How the main process controls container state
    
- How exit codes help diagnose failures
    
- How container names and restart policies work
    
- How to inspect container state reliably
    

---

# 1. The lifecycle model

A Docker container can exist in several states:

|State|Meaning|
|---|---|
|`created`|Container exists but its main process has not started|
|`running`|Main process is active|
|`paused`|Processes are temporarily frozen|
|`restarting`|Docker is repeatedly restarting the container|
|`exited`|Main process finished or was terminated|
|`dead`|Docker could not fully stop or remove the container|
|`removing`|Container is currently being deleted|

The main lifecycle is:

```text
docker create
      ↓
   created
      ↓ docker start
   running
      ↓ docker stop
   exited
      ↓ docker start
   running
      ↓ docker rm
   removed
```

A removed container no longer appears in:

```bash
docker ps -a
```

Its source image normally remains available.

---

# 2. `docker run` is a convenience command

When you execute:

```bash
docker run nginx
```

Docker effectively performs two operations:

```bash
docker create nginx
docker start <container>
```

Therefore:

```text
docker run = docker create + docker start
```

This is an important distinction.

`docker run` always creates a new container.

It does not search for and restart an existing container created earlier.

---

# 3. `docker create`

`docker create` creates a container without starting it.

Try:

```bash
docker create --name day2-created alpine echo "Container started"
```

Docker prints a container ID, but the command inside the container has not yet run.

Check:

```bash
docker ps
```

The container should not appear because it is not running.

Now check:

```bash
docker ps -a
```

You should see a status similar to:

```text
Created
```

Inspect the status:

```bash
docker inspect day2-created \
  --format '{{.State.Status}}'
```

Expected result:

```text
created
```

Start it:

```bash
docker start day2-created
```

The container runs:

```text
echo "Container started"
```

and immediately exits.

Check:

```bash
docker ps -a
```

Its state is now similar to:

```text
Exited (0)
```

## Why use `docker create`?

Most everyday work uses `docker run`, but `docker create` is useful when you want to:

- Prepare a container without starting it
    
- Inspect its configuration first
    
- Attach volumes or networks through automation
    
- Separate creation from execution
    
- Demonstrate how Docker manages lifecycle stages
    

---

# 4. `docker start`

`docker start` starts an existing stopped or created container.

Example:

```bash
docker start day2-created
```

By default, it prints only the container name.

It does not automatically show the container’s output.

To start and attach to its output:

```bash
docker start -a day2-created
```

The `-a` option means:

```text
attach
```

For an interactive container, use:

```bash
docker start -ai container-name
```

Here:

- `-a` attaches to output
    
- `-i` attaches standard input
    

---

# 5. Repeated `docker run` creates repeated containers

Run:

```bash
docker run alpine echo "Hello"
docker run alpine echo "Hello"
docker run alpine echo "Hello"
```

Now inspect:

```bash
docker ps -a
```

You will see three separate containers.

They may all use the same image, but each has:

- Its own container ID
    
- Its own name
    
- Its own writable layer
    
- Its own lifecycle state
    
- Its own metadata
    

This is the difference between:

```bash
docker run IMAGE
```

and:

```bash
docker start EXISTING_CONTAINER
```

Use `run` when you need a new container.

Use `start` when you need to restart the same container.

---

# 6. Starting a long-running container

Create an Nginx container:

```bash
docker run -d \
  --name day2-nginx \
  nginx
```

Breakdown:

```text
docker run
```

Create and start a new container.

```text
-d
```

Run in detached mode.

```text
--name day2-nginx
```

Assign a meaningful name.

```text
nginx
```

Use the Nginx image.

Check:

```bash
docker ps
```

You should see:

```text
STATUS: Up ...
```

This means the main process is active.

---

# 7. What keeps the container alive?

A container normally runs only while its main process runs.

For Nginx, the main process remains active:

```text
nginx: master process
```

For this container:

```bash
docker run alpine echo "Hello"
```

the main process is:

```text
echo
```

It finishes immediately, so the container stops.

For this example:

```bash
docker run -d \
  --name sleeper \
  alpine \
  sleep 300
```

the main process is:

```text
sleep 300
```

The container remains alive for 300 seconds.

You can verify:

```bash
docker top sleeper
```

Output should show a `sleep` process.

---

# 8. PID 1 inside the container

The main process started by Docker becomes process ID 1 inside the container.

Run:

```bash
docker run --rm -it alpine sh
```

Inside:

```sh
ps
```

You may see something similar to:

```text
PID   USER     TIME  COMMAND
1     root      0:00 sh
```

The shell is PID 1.

This matters because PID 1:

- Determines whether the container remains alive
    
- Receives termination signals from Docker
    
- Is responsible for handling certain child-process cleanup behavior
    
- Influences graceful shutdown
    

When PID 1 exits, the container stops.

---

# 9. `docker stop`

Stop the Nginx container:

```bash
docker stop day2-nginx
```

Docker attempts a graceful shutdown.

Conceptually, Docker does this:

1. Sends a termination signal to PID 1
    
2. Waits for the application to exit
    
3. Sends a forceful kill signal if the application does not stop before the timeout
    

On Linux, the normal first signal is commonly:

```text
SIGTERM
```

If the process does not exit in time, Docker uses:

```text
SIGKILL
```

Check:

```bash
docker ps -a
```

The container should now show:

```text
Exited
```

The container still exists.

Its writable layer and configuration remain available.

---

# 10. Graceful shutdown versus forced termination

A graceful shutdown allows the application to:

- Finish active requests
    
- Flush logs
    
- Close files
    
- Close database connections
    
- Release locks
    
- Publish an MQTT last-will-related status where appropriate
    
- Remove temporary state
    
- Exit cleanly
    

A forced termination gives the application no opportunity to clean up.

This distinction is important for:

- Databases
    
- Message brokers
    
- Web servers
    
- Background workers
    
- Your MQTT C daemon
    

Whenever possible, design applications to respond correctly to `SIGTERM`.

---

# 11. Change the stop timeout

Docker waits before force-killing the process.

Specify a timeout:

```bash
docker stop --time 20 day2-nginx
```

This allows up to 20 seconds for graceful shutdown.

The shorter form is:

```bash
docker stop -t 20 day2-nginx
```

For an application that needs time to finish operations, a longer timeout may be appropriate.

For example:

```yaml
services:
  worker:
    image: worker:1.0
    stop_grace_period: 30s
```

This Compose setting will be covered later.

---

# 12. `docker kill`

`docker kill` immediately terminates a running container by default.

Start Nginx again:

```bash
docker start day2-nginx
```

Then:

```bash
docker kill day2-nginx
```

Unlike `docker stop`, this does not normally wait for graceful shutdown.

The conceptual difference is:

```text
docker stop
→ politely asks the application to exit
→ waits
→ force-kills only if necessary
```

```text
docker kill
→ terminates immediately by default
```

Use `docker kill` when:

- The process is frozen
    
- Graceful shutdown does not work
    
- You deliberately need an immediate termination
    
- You are testing failure handling
    

Do not use it as the normal shutdown method for databases.

---

# 13. Sending specific signals

Despite its name, `docker kill` can send signals other than `SIGKILL`.

For example:

```bash
docker kill --signal=SIGTERM day2-nginx
```

Or:

```bash
docker kill --signal=SIGHUP day2-nginx
```

Some applications interpret `SIGHUP` as a request to reload configuration.

Whether this works depends on the application.

For your own C daemon, you might handle:

- `SIGTERM` for graceful shutdown
    
- `SIGINT` for interactive shutdown
    
- `SIGHUP` for configuration reload
    

Docker does not define the application’s behavior. It only sends the signal.

---

# 14. `docker restart`

Restart a container:

```bash
docker restart day2-nginx
```

This performs approximately:

```text
stop existing container
start same container again
```

It does not create a new container.

Confirm the container ID before restarting:

```bash
docker inspect day2-nginx \
  --format '{{.Id}}'
```

Restart:

```bash
docker restart day2-nginx
```

Inspect again:

```bash
docker inspect day2-nginx \
  --format '{{.Id}}'
```

The ID remains the same because it is the same container.

Its process is new, but its container identity remains.

---

# 15. `docker pause` and `docker unpause`

Pausing freezes the processes inside a container.

Start a test container:

```bash
docker run -d \
  --name day2-counter \
  alpine \
  sh -c 'while true; do date; sleep 1; done'
```

View logs:

```bash
docker logs -f day2-counter
```

You should see a timestamp every second.

In another terminal:

```bash
docker pause day2-counter
```

The process is frozen.

Check:

```bash
docker ps
```

The status should contain:

```text
Paused
```

Resume it:

```bash
docker unpause day2-counter
```

The process continues.

## Pause is not stop

When paused:

- The container remains in memory
    
- Processes are frozen rather than terminated
    
- PID 1 still exists
    
- The container is not restarted
    
- No normal shutdown occurs
    

This is useful for:

- Temporary diagnostics
    
- Testing timeout behavior
    
- Freezing workloads during troubleshooting
    
- Controlled experiments
    

It is uncommon in ordinary production management.

---

# 16. `docker wait`

`docker wait` waits until a container stops and then prints its exit code.

Run:

```bash
docker run -d \
  --name short-job \
  alpine \
  sh -c 'sleep 5; exit 7'
```

Then:

```bash
docker wait short-job
```

After approximately five seconds, Docker prints:

```text
7
```

This is useful in:

- Scripts
    
- CI pipelines
    
- Batch jobs
    
- Automated testing
    
- Monitoring task completion
    

---

# 17. Understanding exit codes

When a container stops, Docker records the exit code of its main process.

Inspect it:

```bash
docker inspect short-job \
  --format '{{.State.ExitCode}}'
```

Expected:

```text
7
```

Common exit-code meanings include:

|Exit code|Typical meaning|
|--:|---|
|`0`|Successful completion|
|`1`|General application error|
|`2`|Incorrect command usage or application-specific error|
|`126`|Command found but cannot be executed|
|`127`|Command not found|
|`130`|Terminated by `Ctrl+C`, commonly signal 2|
|`137`|Killed, commonly signal 9 or out-of-memory termination|
|`143`|Terminated by `SIGTERM`, commonly signal 15|

These meanings are conventions rather than absolute rules. Applications may define their own codes.

---

# 18. Example: command not found

Run:

```bash
docker run --name bad-command alpine nonexistent-command
```

You should receive an error.

Inspect:

```bash
docker inspect bad-command \
  --format '{{.State.ExitCode}}'
```

You may see:

```text
127
```

That usually means the requested command could not be found.

This is common when:

- The executable is not installed
    
- `PATH` is incorrect
    
- The Dockerfile `CMD` contains the wrong command
    
- A script uses an incorrect interpreter
    
- The binary was copied to the wrong location
    

---

# 19. Example: executable permission problem

Create a script:

```bash
mkdir -p day2-permissions
cd day2-permissions
```

Create `start.sh`:

```sh
#!/bin/sh
echo "Application started"
```

Remove executable permission:

```bash
chmod -x start.sh
```

Run it through a bind mount:

```bash
docker run --name permission-demo \
  -v "$PWD/start.sh:/start.sh:ro" \
  alpine \
  /start.sh
```

It should fail because the file is not executable.

This type of failure commonly produces exit code `126`.

Fix it:

```bash
chmod +x start.sh
```

Remove the failed container:

```bash
docker rm permission-demo
```

Run again:

```bash
docker run --rm \
  -v "$PWD/start.sh:/start.sh:ro" \
  alpine \
  /start.sh
```

---

# 20. Container names must be unique

Create:

```bash
docker run -d \
  --name unique-demo \
  alpine sleep 300
```

Try creating another container with the same name:

```bash
docker run -d \
  --name unique-demo \
  alpine sleep 300
```

Docker rejects it because container names must be unique on that Docker host.

You can resolve this by:

### Starting the existing container

```bash
docker start unique-demo
```

### Removing the existing container

```bash
docker rm -f unique-demo
```

Then recreate it.

### Choosing a different name

```bash
docker run -d \
  --name unique-demo-2 \
  alpine sleep 300
```

This is a frequent beginner error:

```text
Conflict. The container name is already in use.
```

It usually means a stopped container with that name still exists.

Check:

```bash
docker ps -a
```

---

# 21. Rename a container

You can rename an existing container:

```bash
docker rename old-name new-name
```

Example:

```bash
docker run -d \
  --name temporary-name \
  alpine sleep 300
```

Rename it:

```bash
docker rename temporary-name worker-service
```

Check:

```bash
docker ps
```

The container ID remains unchanged.

Only the name changes.

---

# 22. `docker rm`

A stopped container can be removed with:

```bash
docker rm container-name
```

Example:

```bash
docker stop worker-service
docker rm worker-service
```

Trying to remove a running container normally fails:

```bash
docker rm day2-nginx
```

Docker protects you from accidental removal.

To remove it forcibly:

```bash
docker rm -f day2-nginx
```

This stops and removes the container.

Use forced removal carefully because it does not guarantee a graceful application shutdown.

---

# 23. Automatically remove a container

For temporary containers, use:

```bash
docker run --rm alpine echo "Temporary container"
```

After the command exits, Docker removes the container automatically.

Check:

```bash
docker ps -a
```

It should not appear.

Use `--rm` for:

- One-off commands
    
- Temporary debugging shells
    
- Tests
    
- Utility containers
    
- Short administrative operations
    

Avoid `--rm` when you need to inspect the stopped container after a failure.

For debugging, it is often helpful to leave the failed container available.

---

# 24. Remove multiple containers

Create several stopped containers:

```bash
docker run --name cleanup-1 alpine true
docker run --name cleanup-2 alpine true
docker run --name cleanup-3 alpine true
```

Remove all three:

```bash
docker rm cleanup-1 cleanup-2 cleanup-3
```

Remove every stopped container:

```bash
docker container prune
```

Docker asks for confirmation.

This removes all stopped containers, not only your exercise containers.

Check first:

```bash
docker ps -a
```

---

# 25. Container logs and lifecycle

Start a logging container:

```bash
docker run -d \
  --name lifecycle-logger \
  alpine \
  sh -c 'i=1; while true; do echo "Message $i"; i=$((i+1)); sleep 2; done'
```

View logs:

```bash
docker logs lifecycle-logger
```

Follow them:

```bash
docker logs -f lifecycle-logger
```

Stop following with `Ctrl+C`.

The container continues running.

Stop the container:

```bash
docker stop lifecycle-logger
```

View logs again:

```bash
docker logs lifecycle-logger
```

Logs remain associated with the stopped container.

Restart it:

```bash
docker start lifecycle-logger
```

View logs:

```bash
docker logs lifecycle-logger
```

New logs are appended to the existing log history.

Remove it:

```bash
docker rm -f lifecycle-logger
```

Once the container is removed, its normal Docker-managed container log is removed with it.

For important production logs, use centralized log collection rather than relying solely on a container’s local log file.

---

# 26. Inspecting state accurately

Use:

```bash
docker inspect container-name
```

For a concise state view:

```bash
docker inspect container-name \
  --format 'Status={{.State.Status}} Running={{.State.Running}} ExitCode={{.State.ExitCode}}'
```

Example output:

```text
Status=exited Running=false ExitCode=0
```

Inspect timestamps:

```bash
docker inspect container-name \
  --format 'Started={{.State.StartedAt}} Finished={{.State.FinishedAt}}'
```

Inspect whether the container was killed by an out-of-memory event:

```bash
docker inspect container-name \
  --format '{{.State.OOMKilled}}'
```

Inspect the recorded error:

```bash
docker inspect container-name \
  --format '{{.State.Error}}'
```

These fields are valuable when diagnosing failures.

---

# 27. Filtering `docker ps`

List only containers with a particular status:

```bash
docker ps -a --filter status=running
```

Stopped containers:

```bash
docker ps -a --filter status=exited
```

Containers created from Alpine:

```bash
docker ps -a --filter ancestor=alpine
```

A specific name:

```bash
docker ps -a --filter name=day2
```

Show only IDs:

```bash
docker ps -aq
```

Show names:

```bash
docker ps -a \
  --format '{{.Names}}'
```

Custom format:

```bash
docker ps -a \
  --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
```

---

# 28. Restart policies

By default, Docker does not necessarily restart a stopped container automatically.

You can specify a restart policy:

```bash
docker run -d \
  --name restart-demo \
  --restart unless-stopped \
  nginx
```

Common policies:

|Policy|Meaning|
|---|---|
|`no`|Do not restart automatically|
|`on-failure`|Restart only after a nonzero exit code|
|`always`|Restart whenever the container stops|
|`unless-stopped`|Restart unless it was intentionally stopped|

## `no`

```bash
docker run -d \
  --name no-restart \
  --restart no \
  nginx
```

No automatic restart policy.

## `on-failure`

```bash
docker run -d \
  --name failure-restart \
  --restart on-failure \
  alpine \
  sh -c 'sleep 2; exit 1'
```

Docker restarts it because the process exits with code `1`.

Check:

```bash
docker ps -a
```

The status may show:

```text
Restarting
```

Stop the restart loop:

```bash
docker rm -f failure-restart
```

You can limit retries:

```bash
docker run -d \
  --name limited-restart \
  --restart on-failure:3 \
  alpine \
  sh -c 'sleep 1; exit 1'
```

Docker attempts a limited number of restarts.

## `always`

```bash
docker run -d \
  --name always-restart \
  --restart always \
  nginx
```

This is suitable for services expected to remain running, but it can also hide repeated failures if monitoring is weak.

## `unless-stopped`

```bash
docker run -d \
  --name service-demo \
  --restart unless-stopped \
  nginx
```

This is commonly used for persistent services on a single Docker host.

---

# 29. Inspect restart count

For a repeatedly restarting container:

```bash
docker inspect container-name \
  --format '{{.RestartCount}}'
```

You can also inspect its restart policy:

```bash
docker inspect container-name \
  --format '{{json .HostConfig.RestartPolicy}}'
```

Example output:

```json
{"Name":"unless-stopped","MaximumRetryCount":0}
```

---

# 30. Update a restart policy

You do not always need to recreate the container.

Update an existing one:

```bash
docker update \
  --restart unless-stopped \
  day2-nginx
```

Verify:

```bash
docker inspect day2-nginx \
  --format '{{json .HostConfig.RestartPolicy}}'
```

Restart policies help recover from process failures, but they are not substitutes for:

- Correct application error handling
    
- Health checks
    
- Monitoring
    
- Logging
    
- Dependency retry logic
    
- Root-cause investigation
    

A container restarting every five seconds is not a healthy service.

---

# 31. Attached versus detached containers

## Attached

```bash
docker run --name attached-demo alpine sh -c 'while true; do echo running; sleep 2; done'
```

Your terminal is connected to the container’s standard output.

Pressing `Ctrl+C` typically sends an interrupt to the foreground process.

## Detached

```bash
docker run -d \
  --name detached-demo \
  alpine \
  sh -c 'while true; do echo running; sleep 2; done'
```

Your terminal returns immediately.

View output using:

```bash
docker logs -f detached-demo
```

Detached mode does not mean the container is more persistent. It only means your terminal is not attached to it.

---

# 32. Attaching to an existing container

Attach to the main process:

```bash
docker attach detached-demo
```

Be careful: pressing `Ctrl+C` may send a signal to the container’s main process and stop it.

To detach without stopping it, Docker commonly uses:

```text
Ctrl+P, then Ctrl+Q
```

In most everyday troubleshooting, this is safer:

```bash
docker logs -f detached-demo
```

or:

```bash
docker exec -it detached-demo sh
```

`docker exec` starts an additional process and does not directly attach you to PID 1.

---

# 33. `docker exec` requires a running container

Try:

```bash
docker stop detached-demo
docker exec -it detached-demo sh
```

Docker rejects the command because `exec` can operate only inside a running container.

Start it:

```bash
docker start detached-demo
```

Then:

```bash
docker exec -it detached-demo sh
```

Inside, run:

```sh
ps
```

You should see:

- The main long-running process
    
- The shell started by `docker exec`
    
- The `ps` command itself
    

Exit the shell:

```sh
exit
```

The container continues because the main process is still active.

---

# 34. Container configuration is mostly fixed after creation

When you create a container, Docker stores configuration such as:

- Image
    
- Command
    
- Environment variables
    
- Port mappings
    
- Volume mounts
    
- Networks
    
- Restart policy
    
- Resource limits
    

Stopping and starting the same container reuses that configuration.

For example:

```bash
docker run -d \
  --name configured-container \
  -e APP_ENV=development \
  nginx
```

Stopping and restarting does not let you change `APP_ENV` through `docker start`.

To change most runtime configuration, you normally:

1. Remove the old container
    
2. Create a new container with the new settings
    

Example:

```bash
docker rm -f configured-container
```

Then:

```bash
docker run -d \
  --name configured-container \
  -e APP_ENV=production \
  nginx
```

This is normal Docker practice.

Containers should be replaceable rather than manually reconfigured indefinitely.

---

# 35. Containers are disposable, images are reproducible

A useful operational pattern is:

```text
Do not repair an application container manually.
Fix the configuration or image.
Then replace the container.
```

Bad long-term practice:

```text
docker exec into production container
install missing packages
edit configuration manually
restart process
hope nobody deletes the container
```

Better practice:

```text
edit Dockerfile or configuration source
build a corrected image
remove old container
start replacement container
```

This gives you:

- Reproducibility
    
- Version control
    
- Easier rollback
    
- Consistent environments
    
- Clear documentation
    

---

# 36. Daily practical laboratory

## Exercise 1 — Create without starting

```bash
docker create \
  --name lifecycle-test \
  alpine \
  sh -c 'echo "Container executed"; exit 0'
```

Inspect:

```bash
docker ps -a
docker inspect lifecycle-test \
  --format '{{.State.Status}}'
```

Start and attach:

```bash
docker start -a lifecycle-test
```

Inspect:

```bash
docker inspect lifecycle-test \
  --format 'Status={{.State.Status}} ExitCode={{.State.ExitCode}}'
```

Expected:

```text
Status=exited ExitCode=0
```

---

## Exercise 2 — Start the same container again

Run:

```bash
docker start -a lifecycle-test
```

It prints the message again.

The same container configuration is reused, and the same command executes again.

Check its ID:

```bash
docker inspect lifecycle-test \
  --format '{{.Id}}'
```

The ID does not change.

---

## Exercise 3 — Long-running process

```bash
docker run -d \
  --name long-running \
  alpine \
  sh -c 'while true; do echo "$(date) service alive"; sleep 3; done'
```

Verify:

```bash
docker ps
docker logs --tail 5 long-running
```

Follow logs:

```bash
docker logs -f long-running
```

Exit log following with `Ctrl+C`.

Confirm the container remains running:

```bash
docker ps
```

---

## Exercise 4 — Pause and resume

```bash
docker pause long-running
docker ps
```

Wait a few seconds.

Resume:

```bash
docker unpause long-running
docker logs --tail 10 long-running
```

Observe that output stopped while the process was frozen.

---

## Exercise 5 — Stop and restart

```bash
docker stop long-running
docker ps
docker ps -a
```

Inspect:

```bash
docker inspect long-running \
  --format 'Status={{.State.Status}} ExitCode={{.State.ExitCode}}'
```

Restart:

```bash
docker start long-running
```

Confirm:

```bash
docker ps
docker logs --tail 5 long-running
```

---

## Exercise 6 — Use `exec`

```bash
docker exec -it long-running sh
```

Inside:

```sh
ps
hostname
echo "Created from exec" > /exec-file.txt
cat /exec-file.txt
exit
```

The container remains running after the shell exits.

---

## Exercise 7 — Inspect a failure

```bash
docker run \
  --name failed-job \
  alpine \
  sh -c 'echo "Starting job"; sleep 1; echo "Job failed"; exit 12'
```

Inspect:

```bash
docker logs failed-job
```

Then:

```bash
docker inspect failed-job \
  --format 'Status={{.State.Status}} ExitCode={{.State.ExitCode}} Error={{.State.Error}}'
```

Expected exit code:

```text
12
```

This represents an application-defined failure code.

---

## Exercise 8 — Restart policy

```bash
docker run -d \
  --name unstable-service \
  --restart on-failure:3 \
  alpine \
  sh -c 'echo "Starting"; sleep 2; echo "Crashing"; exit 1'
```

Observe:

```bash
docker ps -a
docker logs unstable-service
```

Inspect:

```bash
docker inspect unstable-service \
  --format 'Status={{.State.Status}} Restarts={{.RestartCount}} ExitCode={{.State.ExitCode}}'
```

After the configured retries, it should stop restarting.

---

## Exercise 9 — Name conflict

Create:

```bash
docker run -d \
  --name name-test \
  alpine sleep 300
```

Try the same name again:

```bash
docker run -d \
  --name name-test \
  alpine sleep 300
```

Read the error carefully.

Resolve it:

```bash
docker rm -f name-test
```

---

## Exercise 10 — Cleanup

```bash
docker rm -f \
  lifecycle-test \
  long-running \
  failed-job \
  unstable-service
```

Check:

```bash
docker ps -a
```

Remove any remaining stopped exercise containers manually.

---

# 37. Troubleshooting lifecycle problems

## Problem: Container exits immediately

Check:

```bash
docker ps -a
docker logs container-name
docker inspect container-name \
  --format '{{.State.ExitCode}}'
```

Likely causes:

- Main process completed normally
    
- Application startup failed
    
- Configuration is missing
    
- Command not found
    
- Permission denied
    
- Dependency unavailable
    
- Application is incorrectly starting in the background
    

---

## Problem: Container name already exists

Check:

```bash
docker ps -a --filter name=container-name
```

Then either:

```bash
docker start container-name
```

or:

```bash
docker rm container-name
```

---

## Problem: Container constantly restarts

Inspect:

```bash
docker ps -a
docker logs container-name
docker inspect container-name \
  --format 'Restarts={{.RestartCount}} Exit={{.State.ExitCode}}'
```

Temporarily disable automatic restart:

```bash
docker update --restart no container-name
docker stop container-name
```

Then investigate the actual error.

---

## Problem: Cannot run `docker exec`

Confirm the container is running:

```bash
docker ps
```

If stopped:

```bash
docker start container-name
```

Then retry `exec`.

---

## Problem: Container will not stop

Try graceful shutdown with a longer timeout:

```bash
docker stop -t 30 container-name
```

If it still does not stop:

```bash
docker kill container-name
```

Then inspect its application behavior and signal handling.

---

# 38. Day 2 command reference

```bash
# Create but do not start
docker create IMAGE

# Create and start
docker run IMAGE

# Start an existing container
docker start CONTAINER

# Start and attach
docker start -a CONTAINER

# Start and attach interactively
docker start -ai CONTAINER

# Gracefully stop
docker stop CONTAINER

# Stop with a timeout
docker stop -t 20 CONTAINER

# Immediately terminate
docker kill CONTAINER

# Send a specific signal
docker kill --signal=SIGTERM CONTAINER

# Restart
docker restart CONTAINER

# Pause processes
docker pause CONTAINER

# Resume processes
docker unpause CONTAINER

# Wait until exit and print exit code
docker wait CONTAINER

# Run another process inside a running container
docker exec -it CONTAINER sh

# Attach to the main process
docker attach CONTAINER

# Rename
docker rename OLD_NAME NEW_NAME

# Remove a stopped container
docker rm CONTAINER

# Force-remove
docker rm -f CONTAINER

# Remove all stopped containers
docker container prune

# Inspect detailed state
docker inspect CONTAINER

# Show processes
docker top CONTAINER

# View logs
docker logs CONTAINER

# Show recent logs
docker logs --tail 100 CONTAINER

# Follow logs
docker logs -f CONTAINER
```

---

# 39. Knowledge check

## Question 1

What does `docker run` do compared with `docker create`?

**Answer:**

`docker create` creates a container without starting it. `docker run` creates and immediately starts a new container.

---

## Question 2

Does `docker start` create a new container?

**Answer:**

No. It starts an existing created or stopped container.

---

## Question 3

Why does a container stop?

**Answer:**

A container normally stops when its main process, PID 1, exits.

---

## Question 4

What is the difference between `docker stop` and `docker kill`?

**Answer:**

`docker stop` requests graceful termination and waits before force-killing. `docker kill` terminates immediately by default.

---

## Question 5

Does restarting a container change its container ID?

**Answer:**

No. It remains the same container with the same ID and stored configuration.

---

## Question 6

Does pausing a container terminate its processes?

**Answer:**

No. It freezes them temporarily. `docker unpause` allows them to continue.

---

## Question 7

What does exit code `0` usually mean?

**Answer:**

The main process completed successfully.

---

## Question 8

What commonly causes exit code `127`?

**Answer:**

The requested command was not found.

---

## Question 9

Why can `docker exec` not be used on a stopped container?

**Answer:**

There is no running container process environment in which to start the additional command.

---

## Question 10

What is wrong with a container repeatedly restarting every few seconds?

**Answer:**

The restart policy is masking an application failure. Logs and exit codes must be investigated.

---

# 40. Day 2 completion challenge

Complete this without copying the commands above:

1. Create an Alpine container without starting it.
    
2. Name it `day2-challenge`.
    
3. Configure its command to print a message and exit with code `5`.
    
4. Confirm that its initial status is `created`.
    
5. Start it and attach to its output.
    
6. Inspect its exit code.
    
7. Start it a second time.
    
8. Confirm its container ID remains unchanged.
    
9. Remove it.
    
10. Start another Alpine container that prints the current date every two seconds.
    
11. Run it in detached mode.
    
12. Pause it.
    
13. Confirm its paused state.
    
14. Unpause it.
    
15. Enter it using `docker exec`.
    
16. Inspect its processes.
    
17. Exit the shell without stopping the container.
    
18. Stop it gracefully.
    
19. Restart it.
    
20. Force-remove it.
    

The central Day 2 model is:

```text
docker create
    ↓
created

docker start
    ↓
running

main process exits
or docker stop
    ↓
exited

docker start
    ↓
running again

docker rm
    ↓
container no longer exists
```