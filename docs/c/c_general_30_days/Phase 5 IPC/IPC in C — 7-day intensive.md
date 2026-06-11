

You've used pipes and signals already. This series goes deeper: every IPC mechanism Linux provides, when each is the right tool, and how they compose into real multi-process systems. The capstone on Day 7 builds a multi-process sensor pipeline where each stage is a separate process communicating through the appropriate IPC mechanism.

---

## The seven mechanisms

```
Mechanism          Scope          Persistent?   Structured?   Best for
─────────────────────────────────────────────────────────────────────
Pipes (anon)       parent↔child   no            byte stream   subprocess I/O
FIFOs (named)      any process    filesystem    byte stream   unrelated procs
Message queues     any process    until reboot  messages      typed work items
Shared memory      any process    until reboot  raw bytes     high-throughput
Semaphores         any process    until reboot  counters      sync shared mem
Unix sockets       any process    filesystem    stream/dgram  local RPC
Signals            any process    no            integer only  async events
```

Each day covers one mechanism in full, including the API, the failure modes, the correct cleanup patterns, and a realistic use case from IoT and systems work.

---

# Day 1: Pipes and FIFOs — revisited in depth

Day 14 introduced pipes. Today you go past the basics: `pipe2`, `splice`, capacity limits, atomic writes, and building a reliable unidirectional data channel with flow control.

---

## `pipe2` — the modern pipe

`pipe2` creates a pipe and sets flags atomically — no race window between `pipe` and `fcntl`:

```c
#include <fcntl.h>
#include <unistd.h>
#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <sys/wait.h>
#include "log.h"

/*
 * pipe2 vs pipe + fcntl:
 *
 *   pipe(fds);
 *   fcntl(fds[0], F_SETFL, O_NONBLOCK);    // race: signal between these
 *   fcntl(fds[1], F_SETFL, O_NONBLOCK);    // two calls is a window
 *
 *   pipe2(fds, O_NONBLOCK | O_CLOEXEC);    // atomic — no race
 */

int make_pipe(int fds[2], bool nonblocking) {
    int flags = O_CLOEXEC;
    if (nonblocking) flags |= O_NONBLOCK;
    if (pipe2(fds, flags) < 0) {
        perror("pipe2");
        return -1;
    }
    return 0;
}
```

---

## Pipe capacity and `PIPE_BUF`

The kernel pipe buffer is finite. Understanding its limits prevents deadlocks and data loss:

```c
#include <limits.h>   /* PIPE_BUF */
#include <fcntl.h>

void demonstrate_pipe_semantics(void) {
    int fds[2];
    pipe2(fds, O_CLOEXEC);

    /* Query current pipe buffer size */
    int cap = fcntl(fds[0], F_GETPIPE_SZ);
    printf("pipe capacity: %d bytes (default)\n", cap);
    /* Typically 65536 on Linux */

    /* Increase for high-throughput pipelines */
    fcntl(fds[0], F_SETPIPE_SZ, 1024 * 1024);
    cap = fcntl(fds[0], F_GETPIPE_SZ);
    printf("pipe capacity: %d bytes (after resize)\n", cap);

    /*
     * PIPE_BUF atomicity guarantee:
     * Writes <= PIPE_BUF bytes are atomic — no interleaving from
     * concurrent writers. On Linux, PIPE_BUF = 4096.
     *
     * Writes > PIPE_BUF may be interleaved with other writers.
     * This matters when multiple producer processes share one pipe.
     */
    printf("PIPE_BUF: %d bytes\n", PIPE_BUF);

    close(fds[0]);
    close(fds[1]);
}
```

---

## A reliable framed pipe channel

Raw pipes are byte streams — no message boundaries. For any structured data, add a length prefix:

```c
#include <stdint.h>
#include <unistd.h>
#include <string.h>
#include <errno.h>
#include "errors.h"
#include "log.h"

/*
 * Framed pipe protocol:
 *   [4 bytes] uint32_t length (host byte order — same process, no swap needed)
 *   [N bytes] payload
 *
 * Maximum message size: limited only by pipe buffer and receiver's RAM.
 * Messages <= PIPE_BUF - 4 bytes are written atomically.
 */

/* Write one message atomically if small enough */
Error pipe_send(int fd, const void *data, uint32_t len) {
    /* Header + payload in one write for atomicity */
    if (len > PIPE_BUF - sizeof(uint32_t)) {
        /* Large message: two writes — not atomic between writers */
        /* Only safe if this is the sole writer */
        if (write(fd, &len, sizeof(len)) != sizeof(len)) {
            LOG_ERRNO("pipe_send header");
            return ERR_IO;
        }
        size_t remaining = len;
        const uint8_t *ptr = data;
        while (remaining > 0) {
            ssize_t n = write(fd, ptr, remaining);
            if (n < 0) {
                if (errno == EINTR) continue;
                if (errno == EPIPE) return ERR_NOT_CONNECTED;
                LOG_ERRNO("pipe_send payload");
                return ERR_IO;
            }
            ptr       += n;
            remaining -= (size_t)n;
        }
        return ERR_OK;
    }

    /* Small message: single atomic write */
    uint8_t frame[PIPE_BUF];
    memcpy(frame,                &len,  sizeof(len));
    memcpy(frame + sizeof(len),  data,  len);
    size_t total = sizeof(len) + len;

    ssize_t n = write(fd, frame, total);
    if (n != (ssize_t)total) {
        if (errno == EPIPE) return ERR_NOT_CONNECTED;
        LOG_ERRNO("pipe_send atomic");
        return ERR_IO;
    }
    return ERR_OK;
}

/* Read one message — allocates *out, caller must free */
Error pipe_recv(int fd, void **out, uint32_t *out_len) {
    uint32_t len;
    ssize_t  n;

    /* Read length prefix */
    n = read(fd, &len, sizeof(len));
    if (n == 0)  return ERR_NOT_CONNECTED;   /* EOF — writer closed */
    if (n < 0) {
        if (errno == EINTR) return ERR_TIMEOUT;   /* caller retries */
        LOG_ERRNO("pipe_recv header");
        return ERR_IO;
    }
    if (n != sizeof(len)) {
        LOG_ERROR("short header read: %zd bytes", n);
        return ERR_BAD_PACKET;
    }

    if (len > 1024 * 1024) {   /* sanity: 1MB max */
        LOG_ERROR("message too large: %u bytes", len);
        return ERR_BAD_PACKET;
    }

    uint8_t *buf = malloc(len + 1);   /* +1 for optional null terminator */
    if (!buf) return ERR_NO_MEMORY;

    /* Read payload — loop for robustness */
    size_t  received = 0;
    while (received < len) {
        n = read(fd, buf + received, len - received);
        if (n == 0) { free(buf); return ERR_NOT_CONNECTED; }
        if (n < 0) {
            if (errno == EINTR) continue;
            free(buf);
            LOG_ERRNO("pipe_recv payload");
            return ERR_IO;
        }
        received += (size_t)n;
    }
    buf[len] = '\0';

    *out     = buf;
    *out_len = len;
    return ERR_OK;
}
```

---

## `splice` — zero-copy pipe data movement

`splice` moves data between a pipe and another fd without copying to userspace. Essential for high-throughput pipelines where the data doesn't need to be inspected:

```c
#define _GNU_SOURCE
#include <fcntl.h>
#include <unistd.h>

/*
 * Forward all data from one fd to another via a pipe splice.
 * Useful for: piping a file to a socket, forwarding serial to TCP.
 * No userspace copy — data moves entirely in kernel space.
 */
ssize_t splice_forward(int src_fd, int dst_fd) {
    int pipe_fds[2];
    pipe2(pipe_fds, O_CLOEXEC);

    ssize_t total = 0;
    for (;;) {
        /* Splice from source into pipe */
        ssize_t n = splice(src_fd, NULL, pipe_fds[1], NULL,
                           65536, SPLICE_F_MOVE | SPLICE_F_NONBLOCK);
        if (n == 0) break;      /* EOF */
        if (n < 0) {
            if (errno == EAGAIN) break;   /* no more data right now */
            break;
        }

        /* Splice from pipe to destination */
        ssize_t written = 0;
        while (written < n) {
            ssize_t w = splice(pipe_fds[0], NULL, dst_fd, NULL,
                               (size_t)(n - written),
                               SPLICE_F_MOVE);
            if (w < 0) break;
            written += w;
        }
        total += written;
    }

    close(pipe_fds[0]);
    close(pipe_fds[1]);
    return total;
}
```

---

## FIFOs — named pipes with persistence

FIFOs survive across unrelated process lifetimes. They block on `open` until both ends are connected — a built-in rendezvous mechanism:

```c
#include <sys/stat.h>
#include <fcntl.h>
#include <unistd.h>
#include <errno.h>
#include "log.h"
#include "errors.h"

#define FIFO_PATH "/run/sensor_bridge/data.fifo"

/*
 * Creates the FIFO and opens it for writing.
 * Blocks until a reader opens the other end.
 * Pass O_NONBLOCK to avoid blocking — returns ENXIO if no reader.
 */
int fifo_writer_open(const char *path) {
    /* Create parent directory if needed */
    /* mkdirp omitted for brevity */

    if (mkfifo(path, 0660) < 0 && errno != EEXIST) {
        LOG_ERRNO("mkfifo");
        return -1;
    }

    int fd = open(path, O_WRONLY);   /* blocks until reader connects */
    if (fd < 0) {
        LOG_ERRNO("open fifo write");
        return -1;
    }
    LOG_INFO("fifo writer open: %s", path);
    return fd;
}

int fifo_reader_open(const char *path) {
    if (mkfifo(path, 0660) < 0 && errno != EEXIST) {
        LOG_ERRNO("mkfifo");
        return -1;
    }

    int fd = open(path, O_RDONLY);   /* blocks until writer connects */
    if (fd < 0) {
        LOG_ERRNO("open fifo read");
        return -1;
    }
    LOG_INFO("fifo reader open: %s", path);
    return fd;
}

/*
 * Non-blocking FIFO open — returns -ENXIO if other side not ready.
 * Use in a retry loop with backoff.
 */
int fifo_try_open(const char *path, int flags) {
    int fd = open(path, flags | O_NONBLOCK);
    if (fd < 0) {
        if (errno == ENXIO) return -ENXIO;   /* other end not open */
        LOG_ERRNO("open fifo nonblock");
        return -errno;
    }
    return fd;
}
```

---

## A parent-child pipeline with flow control

A pattern that appears constantly in data processing pipelines — parent spawns child, connects them with pipes, handles backpressure when the child is slow:

```c
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <sys/wait.h>
#include <poll.h>
#include <stdint.h>
#include <string.h>
#include "log.h"
#include "errors.h"

typedef struct {
    uint32_t device_id;
    float    value;
    uint32_t timestamp;
} SensorRecord;

/* Child process: receives records, writes JSON to stdout */
static void child_process(int read_fd) {
    SensorRecord rec;
    ssize_t n;

    while ((n = read(read_fd, &rec, sizeof(rec))) == sizeof(rec)) {
        printf("{\"device\":%u,\"value\":%.3f,\"ts\":%u}\n",
               rec.device_id, rec.value, rec.timestamp);
        fflush(stdout);
    }
    LOG_INFO("child: pipe closed, exiting");
    exit(0);
}

/* Parent process: generates records, writes to pipe with flow control */
int run_pipeline(void) {
    int fds[2];
    if (pipe2(fds, O_CLOEXEC | O_NONBLOCK) < 0) return -1;

    pid_t pid = fork();
    if (pid < 0) { perror("fork"); return -1; }

    if (pid == 0) {
        /* Child: read end only */
        close(fds[1]);
        /* Make read end blocking in child — simpler */
        int flags = fcntl(fds[0], F_GETFL);
        fcntl(fds[0], F_SETFL, flags & ~O_NONBLOCK);
        child_process(fds[0]);
        /* unreachable */
    }

    /* Parent: write end only */
    close(fds[0]);
    int write_fd = fds[1];

    /* Generate 1000 records with flow control */
    for (uint32_t i = 0; i < 1000; i++) {
        SensorRecord rec = {
            .device_id = i % 8,
            .value     = 20.0f + (float)(i % 50),
            .timestamp = 1700000000 + i,
        };

        /* Non-blocking write with poll fallback */
        for (;;) {
            ssize_t n = write(write_fd, &rec, sizeof(rec));
            if (n == sizeof(rec)) break;   /* success */

            if (n < 0 && errno == EAGAIN) {
                /* Pipe full — wait with poll */
                struct pollfd pfd = { .fd = write_fd, .events = POLLOUT };
                poll(&pfd, 1, 1000);   /* 1s timeout */
                continue;
            }
            if (n < 0 && errno == EPIPE) {
                LOG_ERROR("child closed pipe");
                goto done;
            }
            break;
        }
    }

done:
    close(write_fd);
    int status;
    waitpid(pid, &status, 0);
    LOG_INFO("child exit: %d", WEXITSTATUS(status));
    return 0;
}

int main(void) {
    return run_pipeline();
}
```

---

## Day 1 exercise

1. Implement `pipe_send` and `pipe_recv`. Write a test that sends 1000 messages of varying sizes (1 byte to 4000 bytes) through a pipe between parent and child. Verify every message arrives intact and in order. Run under Valgrind — confirm zero leaks.
    
2. Demonstrate the `PIPE_BUF` atomicity guarantee: spawn 4 writer processes each sending 500 messages of exactly `PIPE_BUF - 4` bytes through the same pipe. Verify the reader receives exactly 2000 messages with no interleaving. Then repeat with messages of `PIPE_BUF + 1` bytes and observe corruption.
    
3. Build the FIFO producer/consumer pair from Day 14 but add the framed protocol from this lesson — `pipe_send`/`pipe_recv` over the FIFO. Verify that a producer crash (mid-message) is detected cleanly by the consumer via EOF rather than partial data corruption.
    
4. Profile `splice_forward` against a userspace copy loop. Write a benchmark that transfers 100MB from a file to `/dev/null` via a pipe using both methods. Measure wall time with `clock_gettime(CLOCK_MONOTONIC)`. Record the difference.
    

---

# Day 2: POSIX message queues

Message queues are pipes with structure. Each write is a discrete message with a priority. The kernel delivers messages in priority order, not FIFO order. No framing needed — message boundaries are preserved automatically.

---

## The `mq_` API

```c
#include <mqueue.h>
#include <fcntl.h>
#include <sys/stat.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <errno.h>
#include <time.h>
#include "log.h"
#include "errors.h"
/* Link with: -lrt */

/*
 * Message queue names must start with '/'.
 * They appear under /dev/mqueue if debugfs is mounted.
 * They persist until mq_unlink() or reboot.
 */
#define MQ_NAME    "/sensor_data"
#define MQ_MAXMSG  16       /* max messages in queue */
#define MQ_MSGSIZE 256      /* max bytes per message */

/*
 * Open or create a message queue.
 * flags: O_RDONLY, O_WRONLY, O_RDWR, O_CREAT, O_EXCL, O_NONBLOCK
 */
mqd_t mq_open_queue(const char *name, int flags, bool create) {
    struct mq_attr attr = {
        .mq_flags   = 0,
        .mq_maxmsg  = MQ_MAXMSG,
        .mq_msgsize = MQ_MSGSIZE,
        .mq_curmsgs = 0,
    };

    mqd_t mq;
    if (create) {
        mq = mq_open(name, flags | O_CREAT, 0660, &attr);
    } else {
        mq = mq_open(name, flags);
    }

    if (mq == (mqd_t)-1) {
        LOG_ERRNO("mq_open");
        return (mqd_t)-1;
    }

    /* Query actual attributes (may differ from requested) */
    struct mq_attr actual;
    mq_getattr(mq, &actual);
    LOG_DEBUG("mq opened: maxmsg=%ld msgsize=%ld",
              actual.mq_maxmsg, actual.mq_msgsize);
    return mq;
}
```

---

## Message structure and priority

```c
/* Typed message — fits within MQ_MSGSIZE */
typedef struct __attribute__((packed)) {
    uint8_t  type;        /* MSG_TYPE_* */
    uint8_t  priority;    /* application-level priority (separate from mq prio) */
    uint16_t device_id;
    uint32_t timestamp;
    float    value;
    uint8_t  payload[236]; /* pad to MQ_MSGSIZE */
} SensorMessage;

#define MSG_TYPE_READING  0x01
#define MSG_TYPE_ALARM    0x02
#define MSG_TYPE_HEARTBEAT 0x03

/*
 * Send a message with mq priority.
 * Higher priority = delivered first regardless of send order.
 * Priority range: 0 to sysconf(_SC_MQ_PRIO_MAX)-1 (at least 31).
 */
Error mq_send_sensor(mqd_t mq, const SensorMessage *msg,
                      unsigned int priority) {
    int rc = mq_send(mq, (const char *)msg, sizeof(*msg), priority);
    if (rc < 0) {
        if (errno == EAGAIN) {
            LOG_WARN("message queue full");
            return ERR_BUFFER_FULL;
        }
        LOG_ERRNO("mq_send");
        return ERR_IO;
    }
    return ERR_OK;
}

/*
 * Receive with timeout — blocks at most timeout_ms milliseconds.
 * Returns ERR_TIMEOUT if no message arrives.
 */
Error mq_recv_sensor(mqd_t mq, SensorMessage *out,
                      unsigned int *priority_out,
                      int timeout_ms) {
    struct timespec ts;
    clock_gettime(CLOCK_REALTIME, &ts);
    ts.tv_sec  += timeout_ms / 1000;
    ts.tv_nsec += (timeout_ms % 1000) * 1000000L;
    if (ts.tv_nsec >= 1000000000L) {
        ts.tv_sec++;
        ts.tv_nsec -= 1000000000L;
    }

    ssize_t n = mq_timedreceive(mq, (char *)out, sizeof(*out),
                                  priority_out, &ts);
    if (n < 0) {
        if (errno == ETIMEDOUT) return ERR_TIMEOUT;
        if (errno == EAGAIN)    return ERR_TIMEOUT;
        LOG_ERRNO("mq_timedreceive");
        return ERR_IO;
    }
    if (n != sizeof(*out)) {
        LOG_ERROR("unexpected message size: %zd", n);
        return ERR_BAD_PACKET;
    }
    return ERR_OK;
}
```

---

## Asynchronous notification with `mq_notify`

Instead of blocking on `mq_timedreceive`, receive a signal or spawn a thread when a message arrives:

```c
#include <mqueue.h>
#include <signal.h>
#include <pthread.h>

static mqd_t g_mq;

/* Called in a new thread when message arrives */
static void mq_notification_handler(union sigval sv) {
    (void)sv;

    SensorMessage msg;
    unsigned int  prio;

    /* Drain all available messages */
    while (1) {
        ssize_t n = mq_receive(g_mq, (char *)&msg, sizeof(msg), &prio);
        if (n < 0) {
            if (errno == EAGAIN) break;   /* queue empty */
            LOG_ERRNO("mq_receive in notification");
            break;
        }
        LOG_INFO("notification: type=%u device=%u val=%.2f prio=%u",
                 msg.type, msg.device_id, msg.value, prio);
    }

    /* Re-arm the notification — it's a one-shot */
    struct sigevent sev = {
        .sigev_notify            = SIGEV_THREAD,
        .sigev_notify_function   = mq_notification_handler,
        .sigev_notify_attributes = NULL,
    };
    mq_notify(g_mq, &sev);
}

void setup_mq_notification(mqd_t mq) {
    g_mq = mq;

    /* Open non-blocking — notification only works with O_NONBLOCK */
    struct mq_attr attr;
    mq_getattr(mq, &attr);
    attr.mq_flags |= O_NONBLOCK;
    mq_setattr(mq, &attr, NULL);

    struct sigevent sev = {
        .sigev_notify            = SIGEV_THREAD,
        .sigev_notify_function   = mq_notification_handler,
        .sigev_notify_attributes = NULL,
    };
    if (mq_notify(mq, &sev) < 0) {
        LOG_ERRNO("mq_notify");
    }
}
```

---

## Priority-based sensor alert system

A realistic multi-process design: sensor readers write to a message queue with priority, an alert processor consumes highest-priority messages first:

```c
/* producer.c — sends readings and alarms */
#include <mqueue.h>
#include <stdlib.h>
#include <time.h>
#include <unistd.h>
#include "log.h"

#define MQ_PRIO_ALARM    31   /* highest priority — delivered first */
#define MQ_PRIO_READING   5   /* normal telemetry */
#define MQ_PRIO_HEARTBEAT 1   /* lowest priority */

int main(void) {
    mqd_t mq = mq_open_queue(MQ_NAME, O_WRONLY, true);
    if (mq == (mqd_t)-1) return 1;

    for (int i = 0; i < 50; i++) {
        SensorMessage msg = {
            .device_id = (uint16_t)(i % 4),
            .timestamp = (uint32_t)time(NULL),
            .value     = 20.0f + (float)(i % 30),
        };

        if (msg.value > 40.0f) {
            /* High-temperature alarm — send at highest priority */
            msg.type = MSG_TYPE_ALARM;
            mq_send_sensor(mq, &msg, MQ_PRIO_ALARM);
            LOG_WARN("alarm sent: device=%u temp=%.1f",
                     msg.device_id, msg.value);
        } else {
            msg.type = MSG_TYPE_READING;
            mq_send_sensor(mq, &msg, MQ_PRIO_READING);
        }

        /* Heartbeat every 10 messages — lowest priority */
        if (i % 10 == 0) {
            msg.type  = MSG_TYPE_HEARTBEAT;
            msg.value = 0;
            mq_send_sensor(mq, &msg, MQ_PRIO_HEARTBEAT);
        }

        usleep(50000);   /* 50ms between readings */
    }

    mq_close(mq);
    mq_unlink(MQ_NAME);
    return 0;
}
```

```c
/* consumer.c — processes messages in priority order */
#include <mqueue.h>
#include <signal.h>
#include <stdatomic.h>
#include "log.h"

static _Atomic int g_quit = 0;
static void handle_quit(int s) { (void)s; atomic_store(&g_quit, 1); }

int main(void) {
    struct sigaction sa = { .sa_handler = handle_quit,
                            .sa_flags   = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    mqd_t mq = mq_open_queue(MQ_NAME, O_RDONLY, false);
    if (mq == (mqd_t)-1) return 1;

    uint64_t counts[256] = {0};

    while (!atomic_load(&g_quit)) {
        SensorMessage msg;
        unsigned int  prio;
        Error rc = mq_recv_sensor(mq, &msg, &prio, 1000);

        if (rc == ERR_TIMEOUT) continue;
        if (rc != ERR_OK)      break;

        counts[msg.type]++;

        switch (msg.type) {
        case MSG_TYPE_ALARM:
            LOG_WARN("[ALARM prio=%u] device=%u temp=%.1f",
                      prio, msg.device_id, msg.value);
            break;
        case MSG_TYPE_READING:
            LOG_DEBUG("[READ  prio=%u] device=%u val=%.1f",
                       prio, msg.device_id, msg.value);
            break;
        case MSG_TYPE_HEARTBEAT:
            LOG_INFO("[HB    prio=%u] device=%u",
                      prio, msg.device_id);
            break;
        }
    }

    LOG_INFO("totals: readings=%llu alarms=%llu heartbeats=%llu",
             (unsigned long long)counts[MSG_TYPE_READING],
             (unsigned long long)counts[MSG_TYPE_ALARM],
             (unsigned long long)counts[MSG_TYPE_HEARTBEAT]);

    mq_close(mq);
    return 0;
}
```

---

## Cleanup — message queues are persistent

```c
/*
 * Message queues persist until explicitly unlinked or reboot.
 * A crashed process leaves its queue behind.
 * Always unlink at program start if stale queues are possible.
 */
void mq_safe_unlink(const char *name) {
    if (mq_unlink(name) < 0 && errno != ENOENT) {
        LOG_ERRNO("mq_unlink");
    }
}

/*
 * List open queues (requires /dev/mqueue to be mounted):
 *   mount -t mqueue none /dev/mqueue
 *   ls -la /dev/mqueue/
 *
 * Or via /proc:
 *   cat /proc/*/fd/* 2>/dev/null | grep mqueue
 */
```

---

## Day 2 exercise

1. Build the producer/consumer pair from the lesson. Run them concurrently and verify that alarm messages (sent last in the loop) are received before heartbeat messages that were sent earlier. This demonstrates priority inversion of arrival order.
    
2. Write a multi-producer test: 4 producer processes each send 100 readings through the same message queue simultaneously. The consumer verifies it receives exactly 400 messages. Verify no data corruption under concurrent sends.
    
3. Implement `mq_notify`-based reception. Replace the blocking `mq_timedreceive` loop in the consumer with an `SIGEV_THREAD` notification handler. Verify message processing still works correctly. Measure latency difference between notification and polling using `clock_gettime`.
    
4. Add a message queue monitor: a third process that calls `mq_getattr` every second and prints `mq_curmsgs` (current queue depth). Run it alongside a slow consumer (add `usleep(10000)` per message) and a fast producer. Observe the queue depth growing and the consumer catching up.
    

---

# Day 3: Shared memory

Shared memory is the fastest IPC mechanism — data is written directly to a region both processes can read without any kernel copy. It's also the most dangerous: no built-in synchronisation, no message boundaries, no flow control. You get maximum speed and maximum responsibility.

---

## `shm_open` and `mmap`

POSIX shared memory uses a name in the filesystem namespace like message queues, combined with `mmap` to map it into the process address space:

```c
#include <sys/mman.h>
#include <sys/stat.h>
#include <fcntl.h>
#include <unistd.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <stdatomic.h>
#include "log.h"
#include "errors.h"
/* Link with: -lrt */

#define SHM_NAME     "/sensor_shm"
#define SHM_SIZE     (4 * 1024 * 1024)   /* 4MB shared region */

/*
 * Create or open a POSIX shared memory object and map it.
 * Returns pointer to the mapped region, or NULL on failure.
 *
 * create=true:  creates the object, sets its size, maps it
 * create=false: opens existing object, maps it
 */
void *shm_open_map(const char *name, size_t size, bool create) {
    int flags = create ? (O_CREAT | O_RDWR | O_TRUNC) : O_RDWR;
    int fd    = shm_open(name, flags, 0660);
    if (fd < 0) {
        LOG_ERRNO("shm_open");
        return NULL;
    }

    if (create) {
        if (ftruncate(fd, (off_t)size) < 0) {
            LOG_ERRNO("ftruncate");
            close(fd);
            shm_unlink(name);
            return NULL;
        }
    }

    void *addr = mmap(NULL, size,
                      PROT_READ | PROT_WRITE,
                      MAP_SHARED, fd, 0);
    close(fd);   /* fd can be closed after mmap — mapping persists */

    if (addr == MAP_FAILED) {
        LOG_ERRNO("mmap");
        return NULL;
    }

    LOG_DEBUG("shm mapped: %s size=%zu addr=%p", name, size, addr);
    return addr;
}

void shm_close_map(void *addr, size_t size) {
    if (addr && addr != MAP_FAILED)
        munmap(addr, size);
}

void shm_remove(const char *name) {
    shm_unlink(name);
}
```

---

## A structured shared memory layout

The shared region needs a header that both processes agree on. Always put synchronisation primitives in the shared region itself — not in process-local memory:

```c
#include <pthread.h>   /* for pthread_mutex/condvar in shared memory */
#include <stdatomic.h>

/*
 * Shared memory layout for a sensor data buffer.
 * The producer writes sensor readings into a circular buffer.
 * Consumers read at their own pace with sequence number tracking.
 *
 * No mutex needed for reads if we use seqlock protocol (see below).
 */

#define SHM_MAX_SENSORS  64
#define SHM_MAGIC        0xDEADBEEF
#define SHM_VERSION      1

typedef struct {
    uint32_t    device_id;
    float       value;
    uint32_t    timestamp;
    uint32_t    sequence;   /* monotonically increasing per device */
} SharedReading;

typedef struct {
    /* Metadata — validate on attach */
    uint32_t    magic;
    uint32_t    version;
    uint32_t    shm_size;
    uint32_t    num_sensors;

    /* Writer coordination */
    _Atomic uint64_t write_generation;   /* incremented on each write */
    _Atomic uint32_t writer_pid;         /* PID of writer, 0 if none */

    /* Per-sensor latest reading */
    SharedReading readings[SHM_MAX_SENSORS];

    /* Padding to cache line boundary */
    uint8_t _pad[64];

    /* Extended data area starts here */
    uint8_t data[];   /* flexible array member */
} SharedHeader;

static_assert(offsetof(SharedHeader, readings) % 64 == 0 ||
              sizeof(SharedHeader) < 64,
              "readings should be cache-aligned");

/*
 * Initialise the shared memory header (producer calls this once).
 */
void shm_init_header(SharedHeader *hdr, size_t total_size,
                     uint32_t num_sensors) {
    memset(hdr, 0, sizeof(*hdr));
    hdr->magic        = SHM_MAGIC;
    hdr->version      = SHM_VERSION;
    hdr->shm_size     = (uint32_t)total_size;
    hdr->num_sensors  = num_sensors;
    atomic_init(&hdr->write_generation, 0);
    atomic_init(&hdr->writer_pid, (uint32_t)getpid());
    LOG_INFO("shm header initialised: %u sensors", num_sensors);
}

/*
 * Validate header on consumer attach.
 */
Error shm_validate_header(const SharedHeader *hdr, size_t mapped_size) {
    if (hdr->magic != SHM_MAGIC) {
        LOG_ERROR("bad magic: 0x%08X", hdr->magic);
        return ERR_BAD_PACKET;
    }
    if (hdr->version != SHM_VERSION) {
        LOG_ERROR("version mismatch: got %u want %u",
                  hdr->version, SHM_VERSION);
        return ERR_INVALID_ARG;
    }
    if (hdr->shm_size > mapped_size) {
        LOG_ERROR("shm_size %u > mapped %zu",
                  hdr->shm_size, mapped_size);
        return ERR_INVALID_ARG;
    }
    return ERR_OK;
}
```

---

## Seqlock — fast lockless reads with write detection

A seqlock allows readers to proceed without any lock. The reader detects concurrent writes by comparing a sequence counter before and after reading. If the counter changed, the data may be torn — retry:

```c
/*
 * Seqlock protocol:
 *
 * Writer:
 *   1. Increment generation (odd = write in progress)
 *   2. Write data
 *   3. Increment generation again (even = write complete)
 *
 * Reader:
 *   1. Read generation (must be even — no write in progress)
 *   2. Read data
 *   3. Read generation again
 *   4. If generation changed between steps 1 and 3: retry
 */

void shm_write_reading(SharedHeader *hdr, uint32_t sensor_idx,
                        float value, uint32_t timestamp) {
    if (sensor_idx >= hdr->num_sensors) return;

    /* Step 1: announce write start (make generation odd) */
    uint64_t gen = atomic_fetch_add_explicit(
                       &hdr->write_generation, 1,
                       memory_order_release);
    /* gen is now odd — readers will retry */

    /* Compiler barrier — prevent reordering across this point */
    atomic_thread_fence(memory_order_seq_cst);

    /* Step 2: write data */
    SharedReading *r = &hdr->readings[sensor_idx];
    r->value     = value;
    r->timestamp = timestamp;
    r->sequence++;

    /* Step 3: announce write complete (make generation even) */
    atomic_fetch_add_explicit(&hdr->write_generation, 1,
                               memory_order_release);
}

bool shm_read_reading(const SharedHeader *hdr, uint32_t sensor_idx,
                       SharedReading *out) {
    if (sensor_idx >= hdr->num_sensors) return false;
    const SharedReading *r = &hdr->readings[sensor_idx];

    uint64_t gen_before, gen_after;
    int retries = 0;

    do {
        if (++retries > 100) {
            LOG_WARN("seqlock spinning: writer may be stuck");
            return false;
        }

        gen_before = atomic_load_explicit(&hdr->write_generation,
                                          memory_order_acquire);
        if (gen_before & 1) {
            /* Write in progress — spin */
            continue;
        }

        /* Read data */
        memcpy(out, r, sizeof(*out));

        gen_after = atomic_load_explicit(&hdr->write_generation,
                                         memory_order_acquire);
    } while (gen_before != gen_after);

    return true;
}
```

---

## Complete shared memory producer and consumer

```c
/* shm_producer.c */
#include <stdlib.h>
#include <unistd.h>
#include <time.h>
#include <signal.h>
#include <stdatomic.h>
#include "log.h"

static _Atomic int g_quit = 0;
static void handle_quit(int s) { (void)s; atomic_store(&g_quit, 1); }

int main(void) {
    struct sigaction sa = { .sa_handler = handle_quit,
                            .sa_flags   = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    SharedHeader *hdr = shm_open_map(SHM_NAME, SHM_SIZE, true);
    if (!hdr) return 1;
    shm_init_header(hdr, SHM_SIZE, 8);

    LOG_INFO("producer started — writing to %s", SHM_NAME);

    uint32_t tick = 0;
    while (!atomic_load(&g_quit)) {
        for (uint32_t i = 0; i < 8; i++) {
            float val = 20.0f + (float)(tick % 100) * 0.1f
                        + (float)i * 5.0f;
            shm_write_reading(hdr, i, val, (uint32_t)time(NULL));
        }
        tick++;
        usleep(10000);   /* 100Hz update rate */
    }

    LOG_INFO("producer stopping after %u ticks", tick);
    atomic_store(&hdr->writer_pid, 0);
    shm_close_map(hdr, SHM_SIZE);
    shm_remove(SHM_NAME);
    return 0;
}
```

```c
/* shm_consumer.c */
#include <stdlib.h>
#include <unistd.h>
#include <stdatomic.h>
#include "log.h"

static _Atomic int g_quit = 0;
static void handle_quit(int s) { (void)s; atomic_store(&g_quit, 1); }

int main(void) {
    struct sigaction sa = { .sa_handler = handle_quit,
                            .sa_flags   = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    /* Wait for producer to initialise */
    SharedHeader *hdr = NULL;
    for (int retry = 0; retry < 10; retry++) {
        hdr = shm_open_map(SHM_NAME, SHM_SIZE, false);
        if (hdr) break;
        sleep(1);
    }
    if (!hdr) { LOG_ERROR("cannot open shm"); return 1; }

    if (shm_validate_header(hdr, SHM_SIZE) != ERR_OK) {
        shm_close_map(hdr, SHM_SIZE);
        return 1;
    }

    LOG_INFO("consumer attached: %u sensors, writer_pid=%u",
             hdr->num_sensors,
             atomic_load(&hdr->writer_pid));

    while (!atomic_load(&g_quit)) {
        /* Read all sensors without blocking */
        for (uint32_t i = 0; i < hdr->num_sensors; i++) {
            SharedReading r;
            if (shm_read_reading(hdr, i, &r)) {
                LOG_DEBUG("sensor[%u]: val=%.2f seq=%u ts=%u",
                           i, r.value, r.sequence, r.timestamp);
            }
        }
        usleep(100000);   /* 10Hz read rate */
    }

    shm_close_map(hdr, SHM_SIZE);
    return 0;
}
```

---

## Day 3 exercise

1. Build the producer and consumer. Run them concurrently and verify readings appear correctly. Then add a second consumer — confirm both consumers read without interfering with each other or with the producer (shared memory supports arbitrary readers with no coordination).
    
2. Stress-test the seqlock: run the producer at maximum speed (`usleep(0)`) and spawn 8 concurrent readers each calling `shm_read_reading` in a tight loop. Add a validation check: after reading, verify `r.value` is within the expected range (20–90°C). Log any out-of-range values — a torn read would produce garbage. Run for 10 seconds and confirm zero torn reads.
    
3. Add a `write_generation` monitor: a process that samples `hdr->write_generation` every 100ms and computes write rate (generations per second). Verify the rate matches the producer's `usleep` interval.
    
4. Extend the shared header to include a `last_writer_crash_ts` timestamp. The producer sets it to 0 at startup and updates it every second. Consumers check it: if the timestamp is more than 5 seconds old and `writer_pid` is non-zero but `kill(writer_pid, 0)` returns ESRCH (process doesn't exist), log a producer crash warning.
    

---

# Day 4: POSIX semaphores

Shared memory from Day 3 is unsynchronised — you need external coordination. POSIX semaphores provide it. A semaphore is a counter with two atomic operations: wait (decrement, block if zero) and post (increment, wake a waiter). They're the building blocks of mutual exclusion and producer-consumer signalling across processes.

---

## Named vs unnamed semaphores

```c
#include <semaphore.h>
#include <fcntl.h>
#include <sys/stat.h>
#include <stdio.h>
#include <stdlib.h>
#include "log.h"
#include "errors.h"
/* Link with: -lpthread */

/*
 * Named semaphores: live in filesystem namespace (/dev/shm/sem.name).
 * Work across unrelated processes.
 * Persist until sem_unlink() or reboot.
 */
sem_t *named_sem_create(const char *name, unsigned int initial_value) {
    sem_t *sem = sem_open(name, O_CREAT | O_EXCL, 0660, initial_value);
    if (sem == SEM_FAILED) {
        if (errno == EEXIST) {
            /* Already exists — open existing */
            sem = sem_open(name, 0);
        }
        if (sem == SEM_FAILED) {
            LOG_ERRNO("sem_open");
            return NULL;
        }
    }
    return sem;
}

void named_sem_destroy(sem_t *sem, const char *name) {
    sem_close(sem);
    sem_unlink(name);
}

/*
 * Unnamed semaphores: embedded in shared memory.
 * Both processes access via pointer to the same shm region.
 * pshared=1 means cross-process (must be in shared memory).
 */
int unnamed_sem_init(sem_t *sem, unsigned int initial_value) {
    if (sem_init(sem, 1 /*pshared*/, initial_value) < 0) {
        LOG_ERRNO("sem_init");
        return -1;
    }
    return 0;
}

void unnamed_sem_destroy(sem_t *sem) {
    sem_destroy(sem);
}
```

---

## The full semaphore operation set

```c
/* Decrement (wait) — blocks until value > 0 */
int sem_wait(sem_t *sem);

/* Decrement — returns EAGAIN immediately if value == 0 */
int sem_trywait(sem_t *sem);

/* Decrement — returns ETIMEDOUT after absolute timeout */
int sem_timedwait(sem_t *sem, const struct timespec *abs_timeout);

/* Increment (post) — wakes one waiting thread/process */
int sem_post(sem_t *sem);

/* Read current value without changing it */
int sem_getvalue(sem_t *sem, int *sval);
```

A convenient timed-wait wrapper:

```c
Error sem_wait_timeout(sem_t *sem, int timeout_ms) {
    struct timespec ts;
    clock_gettime(CLOCK_REALTIME, &ts);
    ts.tv_sec  += timeout_ms / 1000;
    ts.tv_nsec += (timeout_ms % 1000) * 1000000L;
    if (ts.tv_nsec >= 1000000000L) {
        ts.tv_sec++;
        ts.tv_nsec -= 1000000000L;
    }

    while (sem_timedwait(sem, &ts) < 0) {
        if (errno == EINTR)     continue;   /* interrupted — retry */
        if (errno == ETIMEDOUT) return ERR_TIMEOUT;
        LOG_ERRNO("sem_timedwait");
        return ERR_IO;
    }
    return ERR_OK;
}
```

---

## Combining semaphores with shared memory

The natural pairing: shared memory for data, semaphores for synchronisation. This implements a proper multi-producer multi-consumer queue in shared memory:

```c
#include <semaphore.h>
#include <sys/mman.h>
#include <stdint.h>
#include <string.h>
#include <stdatomic.h>
#include "log.h"

#define SHM_QUEUE_NAME   "/sensor_queue"
#define SHM_QUEUE_CAP    64

typedef struct {
    uint32_t device_id;
    float    value;
    uint32_t timestamp;
} QueueItem;

/*
 * Shared memory queue with semaphore synchronisation.
 * Supports multiple producers and multiple consumers safely.
 */
typedef struct {
    sem_t   mutex;      /* protects head/tail/count */
    sem_t   not_empty;  /* signalled when item added */
    sem_t   not_full;   /* signalled when item removed */

    uint32_t  head;
    uint32_t  tail;
    uint32_t  count;
    uint32_t  capacity;

    QueueItem items[SHM_QUEUE_CAP];
} SharedQueue;

Error shm_queue_init(SharedQueue *q, uint32_t capacity) {
    if (capacity > SHM_QUEUE_CAP) return ERR_INVALID_ARG;
    memset(q, 0, sizeof(*q));
    q->capacity = capacity;

    /* All three semaphores in shared memory (pshared=1) */
    if (sem_init(&q->mutex,     1, 1)        < 0) goto fail;
    if (sem_init(&q->not_empty, 1, 0)        < 0) goto fail;
    if (sem_init(&q->not_full,  1, capacity) < 0) goto fail;
    return ERR_OK;

fail:
    LOG_ERRNO("sem_init");
    return ERR_IO;
}

void shm_queue_destroy(SharedQueue *q) {
    sem_destroy(&q->mutex);
    sem_destroy(&q->not_empty);
    sem_destroy(&q->not_full);
}

/* Push — blocks if full */
Error shm_queue_push(SharedQueue *q, const QueueItem *item,
                      int timeout_ms) {
    /* Wait for a free slot */
    Error rc = sem_wait_timeout(&q->not_full, timeout_ms);
    if (rc != ERR_OK) return rc;

    /* Acquire mutex */
    rc = sem_wait_timeout(&q->mutex, timeout_ms);
    if (rc != ERR_OK) { sem_post(&q->not_full); return rc; }

    q->items[q->tail] = *item;
    q->tail = (q->tail + 1) % q->capacity;
    q->count++;

    sem_post(&q->mutex);
    sem_post(&q->not_empty);   /* wake a consumer */
    return ERR_OK;
}

/* Pop — blocks if empty */
Error shm_queue_pop(SharedQueue *q, QueueItem *out,
                     int timeout_ms) {
    Error rc = sem_wait_timeout(&q->not_empty, timeout_ms);
    if (rc != ERR_OK) return rc;

    rc = sem_wait_timeout(&q->mutex, timeout_ms);
    if (rc != ERR_OK) { sem_post(&q->not_empty); return rc; }

    *out     = q->items[q->head];
    q->head  = (q->head + 1) % q->capacity;
    q->count--;

    sem_post(&q->mutex);
    sem_post(&q->not_full);   /* wake a producer */
    return ERR_OK;
}
```

---

## Semaphore as a rate limiter

A practical use beyond mutual exclusion — rate-limiting sensor polling to avoid overwhelming a bus:

```c
#define RATE_LIMIT_NAME "/i2c_bus_sem"
#define MAX_CONCURRENT_I2C 3   /* at most 3 simultaneous I2C transactions */

sem_t *g_i2c_sem;

void i2c_init_rate_limiter(void) {
    /* Counting semaphore initialised to max concurrent */
    g_i2c_sem = named_sem_create(RATE_LIMIT_NAME, MAX_CONCURRENT_I2C);
}

/* Acquire before accessing I2C bus */
Error i2c_acquire(void) {
    return sem_wait_timeout(g_i2c_sem, 500);   /* 500ms deadline */
}

/* Release after completing I2C transaction */
void i2c_release(void) {
    sem_post(g_i2c_sem);
}

/* Usage: */
void read_sensor_i2c(uint8_t addr, float *out) {
    if (i2c_acquire() != ERR_OK) {
        LOG_ERROR("I2C bus busy — skipping read");
        return;
    }
    /* ... I2C transaction ... */
    i2c_release();
}
```

---

## Day 4 exercise

1. Build `SharedQueue` backed by real shared memory. Write a multi-process test: 3 producer processes each push 500 items, 2 consumer processes each pop items until they've received a total of 750. Verify all 1500 items are consumed exactly once. Use a sentinel item type to signal consumers when done.
    
2. Implement the rate limiter with `MAX_CONCURRENT_I2C=3`. Spawn 10 concurrent threads each simulating a 50ms I2C transaction (sleep 50ms). Verify with logging that at most 3 ever execute simultaneously, and that the 10 transactions complete in approximately 200ms (ceil(10/3) × 50ms = 4 rounds × 50ms).
    
3. Write a semaphore monitor: a process that samples `sem_getvalue` on all three semaphores in a `SharedQueue` every 100ms and prints `mutex=N not_empty=N not_full=N count=N`. Run it alongside a slow consumer and fast producer. Observe the `not_full` semaphore draining to 0 as the queue fills.
    
4. Implement a binary semaphore-based reader-writer lock using two named semaphores. Rules: multiple simultaneous readers allowed, writer gets exclusive access. Verify with a stress test: 8 reader threads and 2 writer threads, 10000 iterations each, no data corruption.
    

---

# Day 5: Unix domain sockets

Unix domain sockets provide bidirectional, full-duplex communication between processes on the same machine. They use the same socket API as TCP — `socket`, `bind`, `listen`, `accept`, `connect`, `read`, `write` — but communicate through the filesystem rather than the network stack, with no TCP overhead, no byte-order issues, and the ability to pass file descriptors between processes.

---

## Stream vs datagram Unix sockets

```c
#include <sys/socket.h>
#include <sys/un.h>
#include <unistd.h>
#include <fcntl.h>
#include <errno.h>
#include <string.h>
#include <stdlib.h>
#include "log.h"
#include "errors.h"

#define SOCK_PATH    "/run/sensor_bridge/control.sock"
#define SOCK_PATH_DG "/run/sensor_bridge/events.sock"

/*
 * SOCK_STREAM — like TCP: connection-oriented, ordered, reliable.
 * Best for: RPC, command/response, log streaming.
 *
 * SOCK_DGRAM — like UDP: message-oriented, no connection.
 * Best for: events, notifications, fire-and-forget messages.
 * Datagrams are atomic: each sendmsg is one recvmsg — no framing needed.
 *
 * SOCK_SEQPACKET — message-oriented like SOCK_DGRAM but connection-based
 * and ordered like SOCK_STREAM. Best of both worlds for structured IPC.
 */

/* Create and bind a Unix stream server socket */
int unix_server_create(const char *path) {
    int fd = socket(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0);
    if (fd < 0) { LOG_ERRNO("socket"); return -1; }

    /* Remove stale socket file from previous run */
    unlink(path);

    struct sockaddr_un addr;
    memset(&addr, 0, sizeof(addr));
    addr.sun_family = AF_UNIX;
    strncpy(addr.sun_path, path, sizeof(addr.sun_path) - 1);

    if (bind(fd, (struct sockaddr *)&addr, sizeof(addr)) < 0) {
        LOG_ERRNO("bind");
        close(fd); return -1;
    }
    if (listen(fd, 8) < 0) {
        LOG_ERRNO("listen");
        close(fd); return -1;
    }

    LOG_INFO("unix server listening: %s", path);
    return fd;
}

/* Connect to a Unix stream server */
int unix_client_connect(const char *path) {
    int fd = socket(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0);
    if (fd < 0) { LOG_ERRNO("socket"); return -1; }

    struct sockaddr_un addr;
    memset(&addr, 0, sizeof(addr));
    addr.sun_family = AF_UNIX;
    strncpy(addr.sun_path, path, sizeof(addr.sun_path) - 1);

    if (connect(fd, (struct sockaddr *)&addr, sizeof(addr)) < 0) {
        LOG_ERRNO("connect");
        close(fd); return -1;
    }

    LOG_INFO("unix client connected: %s", path);
    return fd;
}
```

---

## Abstract namespace sockets

Linux supports abstract namespace sockets — paths that start with `\0` and don't appear in the filesystem. They're automatically cleaned up when all references are closed:

```c
int unix_abstract_server(const char *name) {
    int fd = socket(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0);
    if (fd < 0) { LOG_ERRNO("socket"); return -1; }

    struct sockaddr_un addr;
    memset(&addr, 0, sizeof(addr));
    addr.sun_family = AF_UNIX;
    /* Abstract: first byte is \0, rest is the name */
    addr.sun_path[0] = '\0';
    strncpy(addr.sun_path + 1, name, sizeof(addr.sun_path) - 2);

    /* Length must include the null byte + name length */
    socklen_t len = offsetof(struct sockaddr_un, sun_path)
                    + 1 + strlen(name);

    if (bind(fd, (struct sockaddr *)&addr, len) < 0) {
        LOG_ERRNO("bind abstract");
        close(fd); return -1;
    }
    listen(fd, 8);
    return fd;
}
```

---

## Passing file descriptors between processes

This is the capability that makes Unix sockets uniquely powerful. A process can send an open file descriptor to another process — the receiving process gets its own fd referring to the same underlying kernel file object:

```c
#include <sys/socket.h>
#include <sys/un.h>

/*
 * Send a file descriptor over a Unix socket.
 * The received fd in the other process is fully functional —
 * it shares the same file description (offset, flags, etc.)
 */
Error send_fd(int sock, int fd_to_send) {
    struct msghdr msg    = {0};
    struct iovec  iov[1] = {0};
    char          cmsg_buf[CMSG_SPACE(sizeof(int))];
    char          data   = 'F';   /* dummy payload — sendmsg needs iov */

    iov[0].iov_base = &data;
    iov[0].iov_len  = 1;
    msg.msg_iov     = iov;
    msg.msg_iovlen  = 1;

    /* Attach fd as ancillary data */
    msg.msg_control    = cmsg_buf;
    msg.msg_controllen = sizeof(cmsg_buf);

    struct cmsghdr *cmsg = CMSG_FIRSTHDR(&msg);
    cmsg->cmsg_level = SOL_SOCKET;
    cmsg->cmsg_type  = SCM_RIGHTS;
    cmsg->cmsg_len   = CMSG_LEN(sizeof(int));
    memcpy(CMSG_DATA(cmsg), &fd_to_send, sizeof(int));

    if (sendmsg(sock, &msg, 0) < 0) {
        LOG_ERRNO("sendmsg");
        return ERR_IO;
    }
    return ERR_OK;
}

/*
 * Receive a file descriptor from a Unix socket.
 * Returns the new fd (>= 0) on success, -1 on failure.
 */
int recv_fd(int sock) {
    struct msghdr msg    = {0};
    struct iovec  iov[1] = {0};
    char          cmsg_buf[CMSG_SPACE(sizeof(int))];
    char          data;

    iov[0].iov_base = &data;
    iov[0].iov_len  = 1;
    msg.msg_iov     = iov;
    msg.msg_iovlen  = 1;
    msg.msg_control    = cmsg_buf;
    msg.msg_controllen = sizeof(cmsg_buf);

    if (recvmsg(sock, &msg, 0) <= 0) {
        LOG_ERRNO("recvmsg");
        return -1;
    }

    struct cmsghdr *cmsg = CMSG_FIRSTHDR(&msg);
    if (!cmsg || cmsg->cmsg_type != SCM_RIGHTS) {
        LOG_ERROR("no fd in ancillary data");
        return -1;
    }

    int received_fd;
    memcpy(&received_fd, CMSG_DATA(cmsg), sizeof(int));
    return received_fd;
}
```

A practical use: a privileged parent opens a raw socket or a device file, then passes the fd to an unprivileged child. The child gets full access to that resource without ever having the privilege to open it itself.

---

## A Unix socket RPC server

A command/response protocol over a Unix socket — cleaner than a pipe pair for interactive control:

```c
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <poll.h>
#include <stdatomic.h>
#include <signal.h>
#include "log.h"
#include "errors.h"

/* Simple text RPC protocol:
 *   Request:  "CMD arg1 arg2\n"
 *   Response: "OK result\n" or "ERR message\n"
 */

#define RPC_SOCK  "/run/sensor_bridge/rpc.sock"
#define RPC_BUFSIZE 512

typedef struct {
    float    readings[8];
    uint32_t uptime;
    uint32_t clients;
} BridgeState;

static BridgeState g_state = {
    .readings = {21.1f, 22.3f, 19.8f, 23.5f,
                 20.0f, 24.1f, 18.9f, 22.7f},
    .uptime   = 0,
    .clients  = 3,
};

/* Handle one RPC command, write response to fd */
static void handle_rpc(int fd, const char *cmd) {
    char resp[RPC_BUFSIZE];

    if (strncmp(cmd, "STATUS", 6) == 0) {
        snprintf(resp, sizeof(resp),
                 "OK uptime=%u clients=%u\n",
                 g_state.uptime, g_state.clients);
    } else if (strncmp(cmd, "READ ", 5) == 0) {
        int idx = atoi(cmd + 5);
        if (idx < 0 || idx >= 8) {
            snprintf(resp, sizeof(resp), "ERR invalid sensor index\n");
        } else {
            snprintf(resp, sizeof(resp),
                     "OK sensor=%d value=%.2f\n",
                     idx, g_state.readings[idx]);
        }
    } else if (strncmp(cmd, "SET ", 4) == 0) {
        int idx; float val;
        if (sscanf(cmd + 4, "%d %f", &idx, &val) == 2
            && idx >= 0 && idx < 8) {
            g_state.readings[idx] = val;
            snprintf(resp, sizeof(resp), "OK\n");
        } else {
            snprintf(resp, sizeof(resp), "ERR bad arguments\n");
        }
    } else {
        snprintf(resp, sizeof(resp), "ERR unknown command\n");
    }

    write(fd, resp, strlen(resp));
}

/* Serve one client connection */
static void serve_client(int cfd) {
    char buf[RPC_BUFSIZE];
    ssize_t n;

    while ((n = read(cfd, buf, sizeof(buf) - 1)) > 0) {
        buf[n] = '\0';
        /* Strip trailing newline */
        char *nl = strchr(buf, '\n');
        if (nl) *nl = '\0';

        LOG_DEBUG("rpc: '%s'", buf);
        handle_rpc(cfd, buf);
    }
    close(cfd);
}

static _Atomic int g_quit = 0;
static void handle_quit(int s) { (void)s; atomic_store(&g_quit, 1); }

int main(void) {
    struct sigaction sa = { .sa_handler = handle_quit,
                            .sa_flags   = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    int srv = unix_server_create(RPC_SOCK);
    if (srv < 0) return 1;

    LOG_INFO("RPC server ready: %s", RPC_SOCK);
    LOG_INFO("Test with: echo 'STATUS' | nc -U %s", RPC_SOCK);

    while (!atomic_load(&g_quit)) {
        struct pollfd pfd = { .fd = srv, .events = POLLIN };
        int rc = poll(&pfd, 1, 1000);
        if (rc <= 0) { if (rc < 0 && errno != EINTR) break; continue; }

        int cfd = accept(srv, NULL, NULL);
        if (cfd < 0) continue;

        pid_t pid = fork();
        if (pid == 0) {
            close(srv);
            serve_client(cfd);
            exit(0);
        }
        close(cfd);
    }

    unlink(RPC_SOCK);
    return 0;
}
```

Test it without writing a client:

```bash
echo "STATUS" | nc -U /run/sensor_bridge/rpc.sock
echo "READ 3"  | nc -U /run/sensor_bridge/rpc.sock
echo "SET 0 99.9" | nc -U /run/sensor_bridge/rpc.sock
```

---

## Day 5 exercise

1. Build the RPC server and test all three commands. Add two more: `LIST` (returns all 8 sensor values as JSON) and `RESET` (sets all readings to 0). Verify with `nc -U`.
    
2. Implement `send_fd` and `recv_fd`. Write a test: parent opens a file for writing, sends the fd to a child via a Unix socket, child writes "hello from child\n" to the received fd, parent reads the file and verifies the content. Demonstrate that the child never needed the filename.
    
3. Replace the `fork`-per-client model in the RPC server with a `poll`-based single-process server that handles multiple concurrent connections. Support at least 8 simultaneous clients. Test with:
    
    ```bash
    for i in $(seq 8); do
      (echo "STATUS"; sleep 1; echo "READ $i") | nc -U /run/sensor_bridge/rpc.sock &
    done
    ```
    
4. Implement `SOCK_DGRAM` Unix sockets for a sensor event bus. Each sensor driver process sends event datagrams (`{device_id, event_type, value}`) to a well-known socket path. An event logger process binds to that path and logs every event. Verify that datagrams from multiple senders don't interleave — each recvfrom returns exactly one complete event.
    

---

# Day 6: Combining IPC mechanisms

Real systems use multiple IPC mechanisms together — each for what it does best. Today you design and build a multi-process sensor pipeline that uses five mechanisms simultaneously.

---

## Design: a multi-process sensor pipeline

```
 sensor_reader  ──pipe──►  frame_parser  ──shm──►  data_store
      │                         │
    signal                   mq_alarm ──mq──►  alert_handler
                                │
                           unix_sock ──◄──  rpc_client (control)
```

Each process has a single responsibility:

- **sensor_reader**: reads raw bytes from serial/socat, writes them to a pipe
- **frame_parser**: reads from pipe, parses frames, writes parsed data to shared memory, sends alarm messages to a message queue
- **data_store**: reads shared memory, persists to a binary log file (Day 11)
- **alert_handler**: reads alarm message queue, sends notifications
- **rpc_client**: connects to Unix socket to query and control the pipeline

---

## The pipeline coordinator

```c
/* coordinator.c — spawns and supervises all pipeline processes */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <signal.h>
#include <sys/wait.h>
#include <fcntl.h>
#include <stdatomic.h>
#include <time.h>
#include "log.h"
#include "errors.h"

#define NUM_WORKERS  4

typedef struct {
    const char  *name;
    const char  *path;
    char *const *argv;
    pid_t        pid;
    int          restart_count;
    time_t       last_start;
} Worker;

static _Atomic int g_quit = 0;
static void handle_quit(int s) { (void)s; atomic_store(&g_quit, 1); }
static void handle_chld(int s) { (void)s; }   /* reap in main loop */

/* Spawn one worker */
static int spawn_worker(Worker *w, int pipe_fds[2]) {
    w->last_start = time(NULL);

    pid_t pid = fork();
    if (pid < 0) { perror("fork"); return -1; }

    if (pid == 0) {
        /* Child: set up pipe if provided */
        if (pipe_fds) {
            /* Pass read end to reader, write end to parser */
            /* Convention: argv[last] is the fd number */
        }
        execvp(w->path, w->argv);
        perror(w->path);
        _exit(127);
    }

    w->pid = pid;
    LOG_INFO("spawned %s (PID=%d attempt=%d)",
             w->name, pid, w->restart_count + 1);
    return 0;
}

/* Check for exited workers and restart if needed */
static void reap_and_restart(Worker *workers, int n,
                              int pipe_fds[2]) {
    int status;
    pid_t pid;

    while ((pid = waitpid(-1, &status, WNOHANG)) > 0) {
        for (int i = 0; i < n; i++) {
            if (workers[i].pid != pid) continue;

            if (WIFEXITED(status) && WEXITSTATUS(status) == 0) {
                LOG_INFO("%s exited cleanly", workers[i].name);
                workers[i].pid = 0;
                continue;
            }

            int exit_code = WIFEXITED(status)
                            ? WEXITSTATUS(status) : -WTERMSIG(status);
            LOG_WARN("%s died (code=%d) — restarting",
                     workers[i].name, exit_code);

            /* Backoff if restarting too fast */
            time_t elapsed = time(NULL) - workers[i].last_start;
            if (elapsed < 2) {
                LOG_WARN("too fast — backing off 3s");
                sleep(3);
            }

            workers[i].restart_count++;
            if (workers[i].restart_count > 10) {
                LOG_ERROR("%s exceeded restart limit", workers[i].name);
                atomic_store(&g_quit, 1);
                return;
            }

            spawn_worker(&workers[i], pipe_fds);
        }
    }
}

int main(int argc, char *argv[]) {
    (void)argc; (void)argv;

    struct sigaction sa;
    memset(&sa, 0, sizeof(sa));
    sigemptyset(&sa.sa_mask);

    sa.sa_handler = handle_quit;
    sa.sa_flags   = SA_RESTART;
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    sa.sa_handler = handle_chld;
    sa.sa_flags   = SA_RESTART | SA_NOCLDSTOP;
    sigaction(SIGCHLD, &sa, NULL);

    /* Create the raw byte pipe: reader → parser */
    int raw_pipe[2];
    if (pipe2(raw_pipe, O_CLOEXEC) < 0) { perror("pipe2"); return 1; }

    /* Worker definitions */
    char *reader_argv[] = { "./sensor_reader",
                             "--pipe-fd", NULL, NULL };
    char  rfd_str[16];
    snprintf(rfd_str, sizeof(rfd_str), "%d", raw_pipe[1]);
    reader_argv[2] = rfd_str;

    char *parser_argv[] = { "./frame_parser",
                             "--read-fd", NULL, NULL };
    char  pfd_str[16];
    snprintf(pfd_str, sizeof(pfd_str), "%d", raw_pipe[0]);
    parser_argv[2] = pfd_str;

    char *store_argv[]  = { "./data_store",  NULL };
    char *alert_argv[]  = { "./alert_handler", NULL };

    Worker workers[NUM_WORKERS] = {
        { "sensor_reader",  "./sensor_reader",  reader_argv, 0, 0, 0 },
        { "frame_parser",   "./frame_parser",   parser_argv, 0, 0, 0 },
        { "data_store",     "./data_store",     store_argv,  0, 0, 0 },
        { "alert_handler",  "./alert_handler",  alert_argv,  0, 0, 0 },
    };

    /* Spawn all workers */
    for (int i = 0; i < NUM_WORKERS; i++) {
        if (spawn_worker(&workers[i], raw_pipe) < 0) return 1;
    }

    /* Close pipe ends we don't need */
    close(raw_pipe[0]);
    close(raw_pipe[1]);

    LOG_INFO("coordinator running — %d workers", NUM_WORKERS);

    /* Supervision loop */
    while (!atomic_load(&g_quit)) {
        sleep(1);
        reap_and_restart(workers, NUM_WORKERS, raw_pipe);
    }

    LOG_INFO("coordinator shutting down — sending SIGTERM to workers");
    for (int i = 0; i < NUM_WORKERS; i++) {
        if (workers[i].pid > 0) {
            kill(workers[i].pid, SIGTERM);
        }
    }

    /* Wait for all workers to exit */
    for (int i = 0; i < NUM_WORKERS; i++) {
        if (workers[i].pid > 0) {
            waitpid(workers[i].pid, NULL, 0);
            LOG_INFO("%s exited", workers[i].name);
        }
    }

    LOG_INFO("coordinator done");
    return 0;
}
```

---

## The frame parser worker

```c
/* frame_parser.c — reads pipe, parses frames, writes shm + mq */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <signal.h>
#include <stdatomic.h>
#include <mqueue.h>
#include "protocol.h"
#include "log.h"
#include "errors.h"

static _Atomic int g_quit = 0;
static void handle_quit(int s) { (void)s; atomic_store(&g_quit, 1); }

int main(int argc, char *argv[]) {
    int read_fd = -1;

    /* Parse --read-fd argument */
    for (int i = 1; i < argc - 1; i++) {
        if (strcmp(argv[i], "--read-fd") == 0) {
            read_fd = atoi(argv[i + 1]);
        }
    }
    if (read_fd < 0) {
        fprintf(stderr, "frame_parser: --read-fd required\n");
        return 1;
    }

    struct sigaction sa = { .sa_handler = handle_quit,
                            .sa_flags   = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    /* Open shared memory (created by data_store) */
    SharedHeader *shm = NULL;
    for (int retry = 0; retry < 5; retry++) {
        shm = shm_open_map(SHM_NAME, SHM_SIZE, false);
        if (shm) break;
        sleep(1);
    }
    if (!shm || shm_validate_header(shm, SHM_SIZE) != ERR_OK) {
        LOG_ERROR("cannot attach to shared memory");
        return 1;
    }

    /* Open alarm message queue */
    mqd_t alarm_mq = mq_open_queue(MQ_NAME, O_WRONLY, false);
    if (alarm_mq == (mqd_t)-1) {
        LOG_ERROR("cannot open alarm queue");
    }

    /* Streaming parser */
    Parser  parser;
    uint8_t buf[4096];
    crc16_init_table();
    parser_init(&parser);

    uint64_t frames_ok  = 0;
    uint64_t frames_bad = 0;

    /* Callback — called for each valid parsed frame */
    typedef struct { SharedHeader *shm; mqd_t mq; uint64_t *ok; } CB;
    CB cb_ctx = { shm, alarm_mq, &frames_ok };

    void on_frame(const Frame *f, void *ud) {
        CB *ctx = ud;
        (*ctx->ok)++;

        if (f->type == PTYPE_SENSOR && f->payload_len >= 8) {
            SensorData sd;
            if (parse_sensor_payload(f->payload, f->payload_len,
                                     &sd) == ERR_OK) {
                /* Write to shared memory */
                shm_write_reading(ctx->shm, sd.device_id,
                                  sensor_to_float(&sd),
                                  f->timestamp);

                /* Send alarm if threshold exceeded */
                float val = sensor_to_float(&sd);
                if (ctx->mq != (mqd_t)-1 && val > 80.0f) {
                    SensorMessage msg = {
                        .type      = MSG_TYPE_ALARM,
                        .device_id = sd.device_id,
                        .timestamp = f->timestamp,
                        .value     = val,
                    };
                    mq_send_sensor(ctx->mq, &msg, MQ_PRIO_ALARM);
                }
            }
        }
    }

    LOG_INFO("frame_parser started (read_fd=%d)", read_fd);

    while (!atomic_load(&g_quit)) {
        ssize_t n = read(read_fd, buf, sizeof(buf));
        if (n == 0) { LOG_INFO("pipe EOF"); break; }
        if (n < 0) {
            if (errno == EINTR) continue;
            LOG_ERRNO("read pipe");
            break;
        }
        parser_feed(&parser, buf, (size_t)n, on_frame, &cb_ctx);
    }

    LOG_INFO("parser done: ok=%llu bad=%llu",
             (unsigned long long)frames_ok,
             (unsigned long long)frames_bad);

    if (alarm_mq != (mqd_t)-1) mq_close(alarm_mq);
    shm_close_map(shm, SHM_SIZE);
    return 0;
}
```

---

## Day 6 exercise

1. Build and run the full coordinator with all four workers using `socat` virtual ports from Day 19. Verify end-to-end flow: frames from the virtual serial port reach shared memory and the message queue.
    
2. Add a fifth worker — `stats_reporter` — that reads shared memory and the Unix RPC socket every 5 seconds, produces a JSON stats line, and writes it to a log file. Verify the coordinator supervises and restarts it correctly.
    
3. Deliberately crash `frame_parser` by sending it `SIGUSR1` and implementing a handler that calls `abort()`. Verify the coordinator detects the crash, waits for the backoff period, and restarts the process. Verify frames resume flowing after restart.
    
4. Add dead-letter handling to the alert_handler: if `mq_timedreceive` times out 3 times in a row with no messages, write a "WATCHDOG: no alarms for Ns" line to a monitoring FIFO that a separate monitoring process reads. This implements a dead-man's switch — absence of alarms itself becomes observable.
    

---

# Day 7: Capstone — multi-process sensor pipeline

Everything from Days 1–6 in a single coherent system. The capstone is a production-quality five-process sensor data pipeline. Each process is independently tested, the coordinator handles crashes and restarts, and the whole system shuts down cleanly on SIGTERM.

---

## Complete system layout

```
/run/sensor_pipeline/
├── data.pipe       (anonymous pipe — sensor_reader → frame_parser)
├── alerts.mq       (POSIX mq — frame_parser → alert_handler)
├── sensor.shm      (POSIX shm — frame_parser → data_store, rpc_server)
├── control.sock    (Unix socket — rpc_server ← rpc_client)
└── dead_letter.fifo (FIFO — alert_handler → monitor)

processes:
├── coordinator     supervises all children
├── sensor_reader   serial bytes → pipe
├── frame_parser    pipe → shm + mq
├── data_store      shm → binary log
├── alert_handler   mq → dead_letter fifo
└── rpc_server      unix socket → shm reads + pipeline control
```

---

## Shared configuration header

```c
/* include/pipeline.h — shared by all processes */
#pragma once
#include <stdint.h>
#include <stdbool.h>
#include "protocol.h"

/* IPC paths */
#define PIPE_PATH_BASE   "/run/sensor_pipeline"
#define MQ_ALERTS        "/sensor_alerts"
#define SHM_SENSORS      "/sensor_readings"
#define SOCK_CONTROL     "/run/sensor_pipeline/control.sock"
#define FIFO_DEADLETTER  "/run/sensor_pipeline/dead_letter.fifo"

/* Shared memory layout */
#define SHM_VERSION      2
#define SHM_MAGIC        0xC0FFEE42
#define SHM_NUM_SENSORS  16
#define SHM_SIZE         (256 * 1024)   /* 256KB */

/* Message queue */
#define MQ_MAX_ALERTS    32
#define MQ_ALERT_SIZE    sizeof(SensorMessage)

/* Priority levels */
#define PRIO_CRITICAL    31
#define PRIO_ALARM       20
#define PRIO_WARNING     10
#define PRIO_INFO         1

/* Thresholds */
#define TEMP_ALARM_C     80.0f
#define TEMP_WARN_C      60.0f

/* RPC commands */
#define RPC_CMD_STATUS   "STATUS"
#define RPC_CMD_READ     "READ"
#define RPC_CMD_LIST     "LIST"
#define RPC_CMD_SHUTDOWN "SHUTDOWN"
```

---

## Complete `sensor_reader` process

```c
/* src/sensor_reader.c */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <fcntl.h>
#include <signal.h>
#include <stdatomic.h>
#include <termios.h>
#include "pipeline.h"
#include "serial.h"
#include "log.h"

static _Atomic int g_quit = 0;
static void handle_quit(int s) { (void)s; atomic_store(&g_quit, 1); }

int main(int argc, char *argv[]) {
    const char *serial_path = "/tmp/ttyV0";
    int         pipe_write_fd = -1;

    for (int i = 1; i < argc - 1; i++) {
        if (strcmp(argv[i], "--serial") == 0)  serial_path   = argv[i+1];
        if (strcmp(argv[i], "--pipe-fd") == 0) pipe_write_fd = atoi(argv[i+1]);
    }
    if (pipe_write_fd < 0) {
        fprintf(stderr, "sensor_reader: --pipe-fd required\n");
        return 1;
    }

    struct sigaction sa = { .sa_handler = handle_quit,
                            .sa_flags   = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);
    signal(SIGPIPE, SIG_IGN);

    int serial_fd = serial_open(serial_path, B115200);
    if (serial_fd < 0) {
        LOG_ERROR("cannot open serial: %s", serial_path);
        return 1;
    }

    LOG_INFO("sensor_reader: %s → pipe_fd=%d", serial_path, pipe_write_fd);

    uint8_t  buf[4096];
    uint64_t bytes_forwarded = 0;

    while (!atomic_load(&g_quit)) {
        ssize_t n = read(serial_fd, buf, sizeof(buf));
        if (n == 0) continue;   /* VTIME timeout */
        if (n < 0) {
            if (errno == EINTR) continue;
            LOG_ERRNO("serial read");
            break;
        }

        /* Forward raw bytes to parser via pipe */
        ssize_t w = write(pipe_write_fd, buf, (size_t)n);
        if (w < 0) {
            if (errno == EPIPE) { LOG_INFO("parser closed pipe"); break; }
            LOG_ERRNO("pipe write");
            break;
        }
        bytes_forwarded += (size_t)w;
    }

    LOG_INFO("sensor_reader done: %llu bytes forwarded",
             (unsigned long long)bytes_forwarded);
    serial_close(serial_fd);
    return 0;
}
```

---

## Complete `data_store` process

```c
/* src/data_store.c */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <signal.h>
#include <time.h>
#include <stdatomic.h>
#include "pipeline.h"
#include "log.h"

/* Reuse LogWriter from Day 11 */
#include "logwriter.h"

static _Atomic int g_quit = 0;
static void handle_quit(int s) { (void)s; atomic_store(&g_quit, 1); }

int main(void) {
    struct sigaction sa = { .sa_handler = handle_quit,
                            .sa_flags   = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    /* Create shared memory — other processes attach to it */
    SharedHeader *shm = shm_open_map(SHM_SENSORS, SHM_SIZE, true);
    if (!shm) { LOG_ERROR("cannot create shm"); return 1; }
    shm_init_header(shm, SHM_SIZE, SHM_NUM_SENSORS);

    /* Create alarm message queue */
    mq_safe_unlink(MQ_ALERTS);
    mqd_t mq = mq_open_queue(MQ_ALERTS, O_RDONLY, true);
    if (mq == (mqd_t)-1) {
        LOG_ERROR("cannot create alert queue");
    }

    /* Open binary log */
    LogWriter log_writer;
    char log_path[128];
    snprintf(log_path, sizeof(log_path),
             "%s/sensors_%ld.log", PIPE_PATH_BASE, (long)time(NULL));
    if (logwriter_open(&log_writer, log_path) != ERR_OK) {
        LOG_ERROR("cannot open log file: %s", log_path);
    }

    LOG_INFO("data_store ready: shm=%s mq=%s log=%s",
             SHM_SENSORS, MQ_ALERTS, log_path);

    uint32_t prev_sequences[SHM_NUM_SENSORS] = {0};
    uint64_t records_written = 0;

    while (!atomic_load(&g_quit)) {
        /* Poll shared memory for new readings */
        for (uint32_t i = 0; i < SHM_NUM_SENSORS; i++) {
            SharedReading r;
            if (!shm_read_reading(shm, i, &r)) continue;
            if (r.sequence == 0) continue;              /* never written */
            if (r.sequence == prev_sequences[i]) continue; /* no update */

            prev_sequences[i] = r.sequence;

            /* Persist to binary log */
            logwriter_append(&log_writer,
                             r.timestamp, (uint8_t)i,
                             0,   /* sensor_type */
                             (uint16_t)(r.value * 16));
            records_written++;
        }

        usleep(50000);   /* 20Hz polling */
    }

    logwriter_close(&log_writer);
    shm_close_map(shm, SHM_SIZE);
    shm_remove(SHM_SENSORS);

    if (mq != (mqd_t)-1) {
        mq_close(mq);
        mq_safe_unlink(MQ_ALERTS);
    }

    LOG_INFO("data_store done: %llu records written",
             (unsigned long long)records_written);
    return 0;
}
```

---

## Complete `alert_handler` process

```c
/* src/alert_handler.c */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <signal.h>
#include <fcntl.h>
#include <sys/stat.h>
#include <stdatomic.h>
#include <mqueue.h>
#include "pipeline.h"
#include "log.h"

static _Atomic int g_quit   = 0;
static void handle_quit(int s) { (void)s; atomic_store(&g_quit, 1); }

int main(void) {
    struct sigaction sa = { .sa_handler = handle_quit,
                            .sa_flags   = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    /* Open alarm message queue for reading */
    mqd_t mq = mq_open_queue(MQ_ALERTS, O_RDONLY, false);
    if (mq == (mqd_t)-1) {
        /* Queue may not exist yet — wait */
        for (int i = 0; i < 5 && mq == (mqd_t)-1; i++) {
            sleep(1);
            mq = mq_open_queue(MQ_ALERTS, O_RDONLY, false);
        }
        if (mq == (mqd_t)-1) {
            LOG_ERROR("cannot open alert queue after retries");
            return 1;
        }
    }

    /* Open dead-letter FIFO — non-blocking, may not have reader yet */
    if (mkfifo(FIFO_DEADLETTER, 0660) < 0 && errno != EEXIST) {
        LOG_ERRNO("mkfifo dead_letter");
    }
    int dl_fd = open(FIFO_DEADLETTER, O_WRONLY | O_NONBLOCK);
    /* dl_fd may be -1 if no reader — that's fine */

    LOG_INFO("alert_handler ready: mq=%s dl_fd=%d", MQ_ALERTS, dl_fd);

    int consecutive_timeouts = 0;
    uint64_t alerts_processed = 0;

    while (!atomic_load(&g_quit)) {
        SensorMessage msg;
        unsigned int  prio;
        Error rc = mq_recv_sensor(mq, &msg, &prio, 2000);

        if (rc == ERR_TIMEOUT) {
            consecutive_timeouts++;
            if (consecutive_timeouts >= 3 && dl_fd >= 0) {
                /* Dead-man's switch: write to dead-letter FIFO */
                char watchdog[128];
                int n = snprintf(watchdog, sizeof(watchdog),
                                 "WATCHDOG: no alarms for %ds\n",
                                 consecutive_timeouts * 2);
                write(dl_fd, watchdog, (size_t)n);
            }
            continue;
        }

        if (rc != ERR_OK) break;

        consecutive_timeouts = 0;
        alerts_processed++;

        /* Format and log the alert */
        const char *level = (prio >= PRIO_ALARM) ? "ALARM" : "WARNING";
        LOG_WARN("[%s prio=%u] device=%u temp=%.1f°C ts=%u",
                 level, prio, msg.device_id, msg.value, msg.timestamp);

        /* In production: send to PagerDuty, Slack, MQTT, etc. */
        if (dl_fd >= 0) {
            char alert[256];
            int n = snprintf(alert, sizeof(alert),
                             "ALERT: device=%u val=%.1f ts=%u\n",
                             msg.device_id, msg.value, msg.timestamp);
            write(dl_fd, alert, (size_t)n);
        }
    }

    LOG_INFO("alert_handler done: %llu alerts processed",
             (unsigned long long)alerts_processed);
    if (dl_fd >= 0) close(dl_fd);
    mq_close(mq);
    return 0;
}
```

---

## Complete `rpc_server` process

```c
/* src/rpc_server.c */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <poll.h>
#include <signal.h>
#include <stdatomic.h>
#include "pipeline.h"
#include "log.h"

static _Atomic int g_quit = 0;
static void handle_quit(int s) { (void)s; atomic_store(&g_quit, 1); }

static SharedHeader *g_shm = NULL;
static _Atomic int  *g_shutdown_flag = NULL;

static void handle_rpc_command(int cfd, const char *cmd) {
    char resp[1024];

    if (strcmp(cmd, RPC_CMD_STATUS) == 0) {
        snprintf(resp, sizeof(resp),
                 "OK writer_pid=%u generation=%llu\n",
                 atomic_load(&g_shm->writer_pid),
                 (unsigned long long)
                     atomic_load(&g_shm->write_generation));

    } else if (strncmp(cmd, RPC_CMD_READ " ", 5) == 0) {
        int idx = atoi(cmd + 5);
        if (idx < 0 || idx >= SHM_NUM_SENSORS) {
            snprintf(resp, sizeof(resp), "ERR bad index\n");
        } else {
            SharedReading r;
            if (shm_read_reading(g_shm, (uint32_t)idx, &r)) {
                snprintf(resp, sizeof(resp),
                         "OK device=%d value=%.3f seq=%u ts=%u\n",
                         idx, r.value, r.sequence, r.timestamp);
            } else {
                snprintf(resp, sizeof(resp), "ERR read failed\n");
            }
        }

    } else if (strcmp(cmd, RPC_CMD_LIST) == 0) {
        char *p   = resp;
        size_t rem = sizeof(resp);
        int   n   = snprintf(p, rem, "OK [");
        p += n; rem -= (size_t)n;

        for (uint32_t i = 0; i < g_shm->num_sensors && rem > 4; i++) {
            SharedReading r;
            float val = 0.0f;
            if (shm_read_reading(g_shm, i, &r)) val = r.value;
            n = snprintf(p, rem, "%.2f%s", val,
                         i < g_shm->num_sensors - 1 ? "," : "");
            p += n; rem -= (size_t)n;
        }
        snprintf(p, rem, "]\n");

    } else if (strcmp(cmd, RPC_CMD_SHUTDOWN) == 0) {
        snprintf(resp, sizeof(resp), "OK shutting down\n");
        write(cfd, resp, strlen(resp));
        if (g_shutdown_flag)
            atomic_store(g_shutdown_flag, 1);
        return;

    } else {
        snprintf(resp, sizeof(resp),
                 "ERR unknown command: %s\n", cmd);
    }

    write(cfd, resp, strlen(resp));
}

int main(void) {
    struct sigaction sa = { .sa_handler = handle_quit,
                            .sa_flags   = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    /* Wait for shared memory to be created by data_store */
    for (int i = 0; i < 10; i++) {
        g_shm = shm_open_map(SHM_SENSORS, SHM_SIZE, false);
        if (g_shm && shm_validate_header(g_shm, SHM_SIZE) == ERR_OK) break;
        if (g_shm) { shm_close_map(g_shm, SHM_SIZE); g_shm = NULL; }
        sleep(1);
    }
    if (!g_shm) { LOG_ERROR("cannot attach shm"); return 1; }

    int srv = unix_server_create(SOCK_CONTROL);
    if (srv < 0) return 1;

    LOG_INFO("rpc_server ready: %s", SOCK_CONTROL);

    /* Simple poll loop — up to 8 concurrent clients */
    struct pollfd fds[9];
    fds[0].fd     = srv;
    fds[0].events = POLLIN;
    int nfds = 1;

    while (!atomic_load(&g_quit)) {
        int rc = poll(fds, (nfds_t)nfds, 1000);
        if (rc < 0) { if (errno == EINTR) continue; break; }
        if (rc == 0) continue;

        /* New connection */
        if (fds[0].revents & POLLIN) {
            int cfd = accept(srv, NULL, NULL);
            if (cfd >= 0 && nfds < 9) {
                fds[nfds].fd     = cfd;
                fds[nfds].events = POLLIN;
                nfds++;
            } else if (cfd >= 0) {
                close(cfd);
            }
        }

        /* Client data */
        for (int i = nfds - 1; i >= 1; i--) {
            if (!fds[i].revents) continue;

            char buf[256];
            ssize_t n = read(fds[i].fd, buf, sizeof(buf) - 1);
            if (n <= 0) {
                close(fds[i].fd);
                fds[i] = fds[--nfds];
                continue;
            }
            buf[n] = '\0';
            char *nl = strchr(buf, '\n'); if (nl) *nl = '\0';
            handle_rpc_command(fds[i].fd, buf);
        }
    }

    for (int i = 0; i < nfds; i++) close(fds[i].fd);
    unlink(SOCK_CONTROL);
    shm_close_map(g_shm, SHM_SIZE);
    return 0;
}
```

---

## End-to-end test script

```bash
#!/bin/bash
# test_pipeline.sh

set -e
mkdir -p /run/sensor_pipeline

echo "=== Starting virtual serial port ==="
socat PTY,link=/tmp/ttyV0,raw,echo=0 \
      PTY,link=/tmp/ttyV1,raw,echo=0 &
SOCAT_PID=$!
sleep 0.5

echo "=== Starting pipeline ==="
./build/coordinator --serial /tmp/ttyV0 &
COORD_PID=$!
sleep 2

echo "=== Sending test frames ==="
python3 tools/frame_generator.py /tmp/ttyV1 --count 100 &
GEN_PID=$!

echo "=== Running RPC queries ==="
sleep 1
echo "STATUS" | nc -U /run/sensor_pipeline/control.sock
echo "LIST"   | nc -U /run/sensor_pipeline/control.sock
echo "READ 0" | nc -U /run/sensor_pipeline/control.sock

wait $GEN_PID
echo "=== Generator done, waiting for pipeline to drain ==="
sleep 2

echo "=== Shutting down ==="
echo "SHUTDOWN" | nc -U /run/sensor_pipeline/control.sock || true
wait $COORD_PID

echo "=== Verifying log file ==="
./build/logfile_dump /run/sensor_pipeline/sensors_*.log

kill $SOCAT_PID 2>/dev/null || true
echo "=== Test complete ==="
```

---

## Final CMakeLists.txt

```cmake
cmake_minimum_required(VERSION 3.16)
project(sensor_pipeline VERSION 1.0.0 LANGUAGES C)

set(CMAKE_C_STANDARD 11)
set(CMAKE_C_STANDARD_REQUIRED ON)
set(CMAKE_C_EXTENSIONS OFF)

if(NOT CMAKE_BUILD_TYPE)
    set(CMAKE_BUILD_TYPE Debug CACHE STRING "" FORCE)
endif()

add_compile_options(-Wall -Wextra -Werror -Wformat-security)

# Common library
add_library(pipeline_lib STATIC
    src/protocol.c src/serial.c src/logwriter.c
    src/shm_utils.c src/mq_utils.c src/unix_sock.c
    src/errors.c src/log.c
)
target_include_directories(pipeline_lib PUBLIC include)
target_link_libraries(pipeline_lib PUBLIC pthread rt)

# Process binaries
foreach(proc coordinator sensor_reader frame_parser
             data_store alert_handler rpc_server)
    add_executable(${proc} src/${proc}.c)
    target_link_libraries(${proc} PRIVATE pipeline_lib)
endforeach()

# Tool
add_executable(logfile_dump tools/logfile_dump.c)
target_link_libraries(logfile_dump PRIVATE pipeline_lib)

# Tests
if(CMAKE_SYSTEM_PROCESSOR STREQUAL CMAKE_HOST_SYSTEM_PROCESSOR)
    enable_testing()
    add_subdirectory(tests)
endif()

# Cross-compilation
if(EXISTS ${CMAKE_CURRENT_SOURCE_DIR}/toolchains/arm-linux-gnueabihf.cmake)
    message(STATUS "ARM toolchain available: "
        "cmake -DCMAKE_TOOLCHAIN_FILE=toolchains/arm-linux-gnueabihf.cmake")
endif()
```

---

## What this capstone demonstrates

The seven-day IPC series produced a complete understanding of every Linux IPC mechanism and when to use each:

**Pipes** carry raw byte streams between parent and child with zero setup cost. The frame generator feeds raw bytes to the parser through a pipe — natural, efficient, automatically cleaned up.

**Message queues** carry typed, prioritised messages between unrelated processes. Alarms travel through the message queue at high priority, overtaking normal telemetry regardless of send order.

**Shared memory** gives all read-heavy processes direct access to the latest sensor state at memory speed, with no intermediate copies. The seqlock pattern keeps it safe without any kernel calls on the reader path.

**Semaphores** synchronise access to the shared memory queue, ensuring the multi-producer multi-consumer pattern is race-free across process boundaries.

**Unix sockets** provide the control plane — bidirectional RPC over a local socket, with fd passing available for privileged resource delegation.

**Signals** handle process lifecycle: graceful shutdown, config reload, and supervisor-to-worker communication for the coordinator.

**FIFOs** decouple the alert handler from its downstream consumer — the dead-letter FIFO persists between restarts and requires no coordination on open.

Every mechanism from the 30-day course is present: binary protocol parsing, structured logging, error handling with goto cleanup, a CMake build with cross-compilation support, a Unity test suite, and systemd-compatible process management.