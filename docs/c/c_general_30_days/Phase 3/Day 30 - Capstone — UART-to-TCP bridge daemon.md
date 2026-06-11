

Every concept from the course converges here. This is a production-quality daemon that reads binary sensor frames from a serial port, parses them, and broadcasts them to multiple TCP clients — with graceful shutdown, structured logging, a test suite, a CMake build, and a systemd unit file. Nothing new is introduced today. Everything is applied.

---

## Architecture

```
serial port (UART)          core daemon                  TCP clients
                    ┌─────────────────────────┐
/dev/ttyUSB0 ──────►│  serial reader thread   │
                    │  (Day 19 termios API)    │
                    │           │              │
                    │           ▼              │
                    │  SPSC ring buffer        │◄── sensor_reader → ring
                    │  (Day 22 lock-free)      │
                    │           │              │
                    │           ▼              │
                    │  frame parser            │◄── ring → parser → queue
                    │  (Day 20 state machine)  │
                    │           │              │
                    │           ▼              │
                    │  work queue              │
                    │  (Day 17 mutex+condvar)  │
                    │           │              │
                    │           ▼              │
                    │  epoll event loop        │◄── queue → broadcast
                    │  (Day 21 epoll)          │
                    │    ├─ TCP listener       │
                    │    ├─ signalfd           │
                    │    ├─ timerfd            │
                    │    └─ client fds         │──► /TCP clients
                    └─────────────────────────┘
```

Two threads. One owns the serial port and fills the ring buffer. The other runs the epoll event loop, drains the ring buffer through the work queue, and broadcasts to all connected TCP clients. Clean separation: serial I/O never blocks the network, network congestion never blocks serial reads.

---

## Project layout

```
uart_bridge/
├── CMakeLists.txt
├── toolchains/
│   └── arm-linux-gnueabihf.cmake
├── include/
│   ├── bridge.h
│   ├── serial.h
│   ├── protocol.h
│   ├── spsc_ring.h
│   ├── workqueue.h
│   ├── errors.h
│   └── log.h
├── src/
│   ├── main.c
│   ├── bridge.c
│   ├── serial.c
│   ├── protocol.c
│   ├── spsc_ring.c
│   ├── workqueue.c
│   ├── errors.c
│   └── log.c
├── tests/
│   ├── CMakeLists.txt
│   ├── unity/
│   ├── test_protocol.c
│   ├── test_bridge.c
│   └── test_workqueue.c
└── systemd/
    └── uart_bridge.service
```

---

## `bridge.h` — the central interface

```c
/* include/bridge.h */
#pragma once
#include <stdint.h>
#include <stdbool.h>
#include <stddef.h>
#include "errors.h"

#define BRIDGE_VERSION        "1.0.0"
#define BRIDGE_MAX_CLIENTS    32
#define BRIDGE_TCP_PORT       7778
#define BRIDGE_RING_CAPACITY  256    /* SPSC ring — must be power of two */
#define BRIDGE_QUEUE_DEPTH    64     /* work queue depth */
#define BRIDGE_SERIAL_BAUD    B115200
#define BRIDGE_IDLE_TIMEOUT   60     /* seconds before idle client dropped */
#define BRIDGE_STATS_INTERVAL 10     /* seconds between stats log lines */

/* Configuration — populated from argv / environment */
typedef struct {
    const char *serial_path;    /* e.g. "/dev/ttyUSB0" */
    int         tcp_port;
    int         idle_timeout;
    bool        verbose;
} BridgeConfig;

/* Per-client state for the epoll loop */
typedef struct {
    int    fd;
    char   addr[46];
    time_t last_active;
    long   bytes_sent;
} ClientConn;

/* Top-level bridge state — owns all resources */
typedef struct {
    BridgeConfig  cfg;

    /* Serial reader thread */
    pthread_t     serial_thread;
    int           serial_fd;

    /* Lock-free ring: serial thread → epoll thread */
    SPSCRing      ring;
    uint8_t       ring_storage[BRIDGE_RING_CAPACITY * (14 + 512)];

    /* Work queue: parser → broadcaster */
    WorkQueue     queue;

    /* epoll */
    int           epfd;
    int           listen_fd;
    int           signal_fd;
    int           timer_fd;

    /* Connected clients */
    ClientConn    clients[BRIDGE_MAX_CLIENTS];
    int           client_count;

    /* Statistics */
    uint64_t      frames_rx;
    uint64_t      frames_dropped;
    uint64_t      bytes_broadcast;

    /* Shutdown flag — written by signalfd handler */
    _Atomic int   quit;
} Bridge;

/* Lifecycle */
Error bridge_init(Bridge *b, const BridgeConfig *cfg);
Error bridge_run(Bridge *b);      /* blocks until shutdown */
void  bridge_destroy(Bridge *b);
```

---

## Serial reader thread

```c
/* src/bridge.c — serial reader thread */
#include "bridge.h"
#include "serial.h"
#include "protocol.h"
#include "spsc_ring.h"
#include "log.h"
#include <unistd.h>
#include <pthread.h>
#include <stdatomic.h>

/*
 * Runs in a dedicated thread.
 * Reads raw bytes from the serial port, feeds them to the streaming
 * parser, and pushes complete frames into the SPSC ring buffer.
 * Never touches the network.
 */
static void *serial_reader_thread(void *arg) {
    Bridge *b = arg;

    Parser   parser;
    uint8_t  buf[256];
    parser_init(&parser);

    LOG_INFO("serial reader started on %s", b->cfg.serial_path);

    /* Callback: called by parser_feed for each complete valid frame */
    void push_frame(const Frame *f, void *userdata) {
        Bridge *bridge = userdata;

        /*
         * Allocate a frame copy for the work queue.
         * The SPSC ring stores raw wire bytes — cheaper to copy
         * the parsed Frame struct into the work queue instead.
         */
        Frame *copy = malloc(sizeof(Frame));
        if (!copy) {
            atomic_fetch_add(&bridge->frames_dropped, 1);
            return;
        }
        *copy = *f;

        if (wq_push(&bridge->queue, copy) != 0) {
            free(copy);
            atomic_fetch_add(&bridge->frames_dropped, 1);
            LOG_WARN("work queue full — frame dropped");
        } else {
            atomic_fetch_add(&bridge->frames_rx, 1);
        }
    }

    int consecutive_errors = 0;

    while (!atomic_load(&b->quit)) {
        ssize_t n = read(b->serial_fd, buf, sizeof(buf));

        if (n < 0) {
            if (errno == EINTR) continue;
            LOG_ERRNO("serial read");
            if (++consecutive_errors >= 5) {
                LOG_ERROR("too many serial errors — attempting reopen");
                serial_close(b->serial_fd);
                sleep(2);
                b->serial_fd = serial_open(b->cfg.serial_path,
                                           BRIDGE_SERIAL_BAUD);
                if (b->serial_fd < 0) {
                    LOG_ERROR("reopen failed — serial thread exiting");
                    atomic_store(&b->quit, 1);
                    break;
                }
                consecutive_errors = 0;
                parser_init(&parser);   /* resync after reconnect */
            }
            continue;
        }

        if (n == 0) {
            /* VTIME timeout — no data, loop back */
            consecutive_errors = 0;
            continue;
        }

        consecutive_errors = 0;
        parser_feed(&parser, buf, (size_t)n, push_frame, b);
    }

    LOG_INFO("serial reader thread exiting");
    return NULL;
}
```

---

## epoll event loop — broadcaster

```c
/* src/bridge.c — epoll thread (runs in main thread) */
#include "bridge.h"
#include "log.h"
#include <sys/epoll.h>
#include <sys/signalfd.h>
#include <sys/timerfd.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <fcntl.h>
#include <unistd.h>
#include <signal.h>
#include <time.h>
#include <string.h>
#include <stdlib.h>
#include <errno.h>

static int set_nonblocking(int fd) {
    int f = fcntl(fd, F_GETFL, 0);
    return fcntl(fd, F_SETFL, f | O_NONBLOCK);
}

static void epoll_add(int epfd, int fd, uint32_t events, void *ptr) {
    struct epoll_event ev = { .events = events, .data.ptr = ptr };
    epoll_ctl(epfd, EPOLL_CTL_ADD, fd, &ev);
}

/* Broadcast a wire-serialised frame to all connected clients */
static void broadcast_frame(Bridge *b, const Frame *f) {
    uint8_t wire[FRAME_OVERHEAD + MAX_PAYLOAD];
    ssize_t wire_len = frame_serialise(f, wire, sizeof(wire));
    if (wire_len <= 0) return;

    int i = 0;
    while (i < b->client_count) {
        ClientConn *c = &b->clients[i];
        ssize_t     n = write(c->fd, wire, (size_t)wire_len);

        if (n < 0 && (errno == EPIPE || errno == ECONNRESET)) {
            /* Client disconnected — compact array */
            LOG_INFO("client gone during broadcast: %s", c->addr);
            epoll_ctl(b->epfd, EPOLL_CTL_DEL, c->fd, NULL);
            close(c->fd);
            b->clients[i] = b->clients[--b->client_count];
            continue;   /* re-check same index */
        }
        if (n > 0) {
            c->bytes_sent  += n;
            c->last_active  = time(NULL);
            b->bytes_broadcast += (uint64_t)n;
        }
        i++;
    }
}

/* Drain work queue and broadcast all pending frames */
static void drain_queue(Bridge *b) {
    Frame *f;
    while ((f = wq_pop_nonblocking(&b->queue)) != NULL) {
        broadcast_frame(b, f);
        free(f);
    }
}

/* Accept a new TCP client */
static void accept_client(Bridge *b) {
    struct sockaddr_in peer;
    socklen_t plen = sizeof(peer);
    int cfd = accept(b->listen_fd,
                     (struct sockaddr *)&peer, &plen);
    if (cfd < 0) {
        if (errno != EAGAIN) LOG_ERRNO("accept");
        return;
    }
    set_nonblocking(cfd);

    if (b->client_count >= BRIDGE_MAX_CLIENTS) {
        LOG_WARN("max clients reached — rejecting %s",
                 inet_ntoa(peer.sin_addr));
        close(cfd);
        return;
    }

    int yes = 1;
    setsockopt(cfd, IPPROTO_TCP, TCP_NODELAY, &yes, sizeof(yes));

    ClientConn *c = &b->clients[b->client_count++];
    c->fd          = cfd;
    c->last_active = time(NULL);
    c->bytes_sent  = 0;
    inet_ntop(AF_INET, &peer.sin_addr, c->addr, sizeof(c->addr));

    /* Watch for readability — we detect disconnects via EPOLLRDHUP */
    epoll_add(b->epfd, cfd, EPOLLIN | EPOLLRDHUP | EPOLLET, c);

    LOG_INFO("client connected: %s:%u (%d/%d)",
             c->addr, ntohs(peer.sin_port),
             b->client_count, BRIDGE_MAX_CLIENTS);
}

/* Handle a client event — usually disconnect detection */
static void handle_client_event(Bridge *b, ClientConn *c,
                                  uint32_t events) {
    if (events & (EPOLLHUP | EPOLLERR | EPOLLRDHUP)) {
        LOG_INFO("client disconnect: %s (%ld bytes sent)",
                 c->addr, c->bytes_sent);
        epoll_ctl(b->epfd, EPOLL_CTL_DEL, c->fd, NULL);
        close(c->fd);

        /* Compact clients array */
        int idx = (int)(c - b->clients);
        b->clients[idx] = b->clients[--b->client_count];
        return;
    }
    /* EPOLLIN on a client: we don't expect data from clients
       in this bridge design — discard it */
    if (events & EPOLLIN) {
        char discard[64];
        while (read(c->fd, discard, sizeof(discard)) > 0) {}
        c->last_active = time(NULL);
    }
}

/* Timer tick — check idle clients and log stats */
static void handle_timer(Bridge *b) {
    uint64_t exp;
    read(b->timer_fd, &exp, sizeof(exp));

    time_t now = time(NULL);

    /* Drop idle clients */
    int i = 0;
    while (i < b->client_count) {
        ClientConn *c = &b->clients[i];
        if (now - c->last_active > b->cfg.idle_timeout) {
            LOG_WARN("idle timeout: %s", c->addr);
            epoll_ctl(b->epfd, EPOLL_CTL_DEL, c->fd, NULL);
            close(c->fd);
            b->clients[i] = b->clients[--b->client_count];
            continue;
        }
        i++;
    }

    /* Stats */
    LOG_INFO("stats: clients=%d frames_rx=%llu dropped=%llu "
             "broadcast=%llu bytes",
             b->client_count,
             (unsigned long long)b->frames_rx,
             (unsigned long long)b->frames_dropped,
             (unsigned long long)b->bytes_broadcast);
}

/* Handle signalfd — SIGTERM / SIGINT / SIGHUP */
static void handle_signal(Bridge *b) {
    struct signalfd_siginfo info;
    if (read(b->signal_fd, &info, sizeof(info)) != sizeof(info)) return;

    switch (info.ssi_signo) {
    case SIGTERM:
    case SIGINT:
        LOG_INFO("shutdown signal received (%s)",
                 info.ssi_signo == SIGTERM ? "SIGTERM" : "SIGINT");
        atomic_store(&b->quit, 1);
        wq_shutdown(&b->queue);   /* unblock work queue */
        break;
    case SIGHUP:
        LOG_INFO("SIGHUP — reload would happen here");
        break;
    }
}
```

---

## Bridge lifecycle

```c
/* src/bridge.c — init and run */

Error bridge_init(Bridge *b, const BridgeConfig *cfg) {
    memset(b, 0, sizeof(*b));
    b->cfg  = *cfg;
    atomic_init(&b->quit, 0);

    /* Serial port */
    b->serial_fd = serial_open(cfg->serial_path, cfg->tcp_port);
    if (b->serial_fd < 0) {
        LOG_ERROR("cannot open serial port: %s", cfg->serial_path);
        return ERR_IO;
    }

    /* Work queue — frames from parser to broadcaster */
    if (wq_init(&b->queue, BRIDGE_QUEUE_DEPTH) < 0)
        return ERR_NO_MEMORY;

    /* epoll */
    b->epfd = epoll_create1(EPOLL_CLOEXEC);
    if (b->epfd < 0) { perror("epoll_create1"); return ERR_IO; }

    /* TCP listener */
    b->listen_fd = socket(AF_INET, SOCK_STREAM, 0);
    int yes = 1;
    setsockopt(b->listen_fd, SOL_SOCKET, SO_REUSEADDR, &yes, sizeof(yes));
    set_nonblocking(b->listen_fd);

    struct sockaddr_in addr = {
        .sin_family      = AF_INET,
        .sin_port        = htons((uint16_t)cfg->tcp_port),
        .sin_addr.s_addr = INADDR_ANY,
    };
    if (bind(b->listen_fd, (struct sockaddr *)&addr, sizeof(addr)) < 0) {
        perror("bind"); return ERR_IO;
    }
    listen(b->listen_fd, 16);
    epoll_add(b->epfd, b->listen_fd, EPOLLIN, (void *)0x01);

    /* signalfd — signals as fd events */
    sigset_t mask;
    sigemptyset(&mask);
    sigaddset(&mask, SIGTERM);
    sigaddset(&mask, SIGINT);
    sigaddset(&mask, SIGHUP);
    sigprocmask(SIG_BLOCK, &mask, NULL);
    signal(SIGPIPE, SIG_IGN);

    b->signal_fd = signalfd(-1, &mask, SFD_NONBLOCK | SFD_CLOEXEC);
    epoll_add(b->epfd, b->signal_fd, EPOLLIN, (void *)0x02);

    /* timerfd — periodic stats and idle checks */
    b->timer_fd = timerfd_create(CLOCK_MONOTONIC,
                                 TFD_NONBLOCK | TFD_CLOEXEC);
    struct itimerspec ts = {
        .it_interval = { .tv_sec = BRIDGE_STATS_INTERVAL },
        .it_value    = { .tv_sec = BRIDGE_STATS_INTERVAL },
    };
    timerfd_settime(b->timer_fd, 0, &ts, NULL);
    epoll_add(b->epfd, b->timer_fd, EPOLLIN, (void *)0x03);

    LOG_INFO("bridge initialised — serial=%s tcp_port=%d",
             cfg->serial_path, cfg->tcp_port);
    return ERR_OK;
}

Error bridge_run(Bridge *b) {
    /* Start serial reader thread */
    if (pthread_create(&b->serial_thread, NULL,
                       serial_reader_thread, b) != 0) {
        perror("pthread_create");
        return ERR_IO;
    }

    LOG_INFO("bridge running — listening on port %d", b->cfg.tcp_port);

    struct epoll_event evs[64];

    while (!atomic_load(&b->quit)) {
        /* 500ms timeout — regularly check work queue for new frames */
        int n = epoll_wait(b->epfd, evs, 64, 500);

        if (n < 0) {
            if (errno == EINTR) continue;
            perror("epoll_wait"); break;
        }

        for (int i = 0; i < n; i++) {
            void    *ptr    = evs[i].data.ptr;
            uint32_t events = evs[i].events;

            if (ptr == (void *)0x01) {
                /* Listener */
                while (b->client_count < BRIDGE_MAX_CLIENTS)
                    accept_client(b);
            } else if (ptr == (void *)0x02) {
                /* signalfd */
                handle_signal(b);
            } else if (ptr == (void *)0x03) {
                /* timerfd */
                handle_timer(b);
            } else {
                /* Client connection */
                handle_client_event(b, (ClientConn *)ptr, events);
            }
        }

        /* Always drain the work queue — even on timeout */
        drain_queue(b);
    }

    LOG_INFO("event loop exiting");

    /* Wait for serial thread to finish */
    pthread_join(b->serial_thread, NULL);

    /* Broadcast any remaining frames */
    drain_queue(b);

    LOG_INFO("shutdown complete — %llu frames, %llu dropped, %llu bytes",
             (unsigned long long)b->frames_rx,
             (unsigned long long)b->frames_dropped,
             (unsigned long long)b->bytes_broadcast);
    return ERR_OK;
}

void bridge_destroy(Bridge *b) {
    wq_destroy(&b->queue);
    if (b->serial_fd >= 0) serial_close(b->serial_fd);
    if (b->epfd       >= 0) close(b->epfd);
    if (b->listen_fd  >= 0) close(b->listen_fd);
    if (b->signal_fd  >= 0) close(b->signal_fd);
    if (b->timer_fd   >= 0) close(b->timer_fd);
    for (int i = 0; i < b->client_count; i++)
        close(b->clients[i].fd);
    memset(b, 0, sizeof(*b));
}
```

---

## `main.c`

```c
/* src/main.c */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "bridge.h"
#include "log.h"
#include "errors.h"

static void print_usage(const char *prog) {
    fprintf(stderr,
            "Usage: %s [options]\n"
            "  -d <device>   Serial port (default: /dev/ttyUSB0)\n"
            "  -p <port>     TCP port    (default: %d)\n"
            "  -t <seconds>  Idle timeout (default: %d)\n"
            "  -v            Verbose (debug) logging\n"
            "  --version     Print version and exit\n",
            prog, BRIDGE_TCP_PORT, BRIDGE_IDLE_TIMEOUT);
}

int main(int argc, char *argv[]) {
    BridgeConfig cfg = {
        .serial_path  = "/dev/ttyUSB0",
        .tcp_port     = BRIDGE_TCP_PORT,
        .idle_timeout = BRIDGE_IDLE_TIMEOUT,
        .verbose      = false,
    };

    for (int i = 1; i < argc; i++) {
        if (strcmp(argv[i], "--version") == 0) {
            printf("uart_bridge %s\n", BRIDGE_VERSION);
            return 0;
        } else if (strcmp(argv[i], "-v") == 0) {
            cfg.verbose = true;
        } else if (strcmp(argv[i], "-d") == 0 && i+1 < argc) {
            cfg.serial_path = argv[++i];
        } else if (strcmp(argv[i], "-p") == 0 && i+1 < argc) {
            cfg.tcp_port = atoi(argv[++i]);
        } else if (strcmp(argv[i], "-t") == 0 && i+1 < argc) {
            cfg.idle_timeout = atoi(argv[++i]);
        } else {
            print_usage(argv[0]);
            return 1;
        }
    }

    log_init(cfg.verbose ? LOG_LEVEL_DEBUG : LOG_LEVEL_INFO);

    LOG_INFO("uart_bridge %s starting", BRIDGE_VERSION);
    LOG_INFO("serial=%s tcp=%d idle_timeout=%ds",
             cfg.serial_path, cfg.tcp_port, cfg.idle_timeout);

    Bridge b;
    Error rc = bridge_init(&b, &cfg);
    if (rc != ERR_OK) {
        fprintf(stderr, "bridge_init: %s\n", error_str(rc));
        return 1;
    }

    rc = bridge_run(&b);
    bridge_destroy(&b);
    return rc == ERR_OK ? 0 : 1;
}
```

---

## Test suite

```c
/* tests/test_bridge.c */
#include "unity.h"
#include "bridge.h"
#include "protocol.h"
#include "workqueue.h"
#include <stdlib.h>
#include <string.h>

void setUp(void)    { crc16_init_table(); }
void tearDown(void) { }

/* ── work queue integration ───────────────────────────────────── */

void test_workqueue_push_pop_roundtrip(void) {
    WorkQueue q;
    wq_init(&q, 8);

    Frame *f = calloc(1, sizeof(Frame));
    f->type     = PTYPE_SENSOR;
    f->sequence = 42;

    TEST_ASSERT_EQUAL_INT(0, wq_push(&q, f));

    Frame *got = wq_pop_nonblocking(&q);
    TEST_ASSERT_NOT_NULL(got);
    TEST_ASSERT_EQUAL_UINT8(PTYPE_SENSOR, got->type);
    TEST_ASSERT_EQUAL_UINT16(42,          got->sequence);

    free(got);
    wq_destroy(&q);
}

void test_workqueue_respects_capacity(void) {
    WorkQueue q;
    wq_init(&q, 2);

    Frame *f1 = calloc(1, sizeof(Frame));
    Frame *f2 = calloc(1, sizeof(Frame));
    Frame *f3 = calloc(1, sizeof(Frame));

    TEST_ASSERT_EQUAL_INT(0,  wq_push(&q, f1));
    TEST_ASSERT_EQUAL_INT(0,  wq_push(&q, f2));
    TEST_ASSERT_EQUAL_INT(-1, wq_push(&q, f3));   /* queue full */

    free(wq_pop_nonblocking(&q));
    free(wq_pop_nonblocking(&q));
    free(f3);
    wq_destroy(&q);
}

/* ── protocol round-trip ──────────────────────────────────────── */

void test_frame_survives_bridge_pipeline(void) {
    /* Simulate: serial bytes → parser → work queue → serialise */
    Frame original = {
        .type        = PTYPE_SENSOR,
        .sequence    = 99,
        .payload_len = 4,
        .timestamp   = 1700000000,
    };
    original.payload[0] = 1;
    original.payload[1] = 2;
    original.payload[2] = 3;
    original.payload[3] = 4;

    /* Serialise to wire */
    uint8_t wire[256];
    ssize_t n = frame_serialise(&original, wire, sizeof(wire));
    TEST_ASSERT_TRUE(n > 0);

    /* Feed to parser one byte at a time */
    Parser p;
    parser_init(&p);
    int frames_seen = 0;
    Frame result;

    void capture(const Frame *f, void *ud) {
        *(Frame *)ud = *f;
        (*(int *)(((char *)ud) + sizeof(Frame)))++;
    }

    /* Use a small context struct to capture both frame and count */
    struct { Frame f; int count; } ctx = {0};
    for (ssize_t i = 0; i < n; i++) {
        parser_feed(&p, wire + i, 1, capture, &ctx);
    }

    TEST_ASSERT_EQUAL_INT(1, ctx.count);
    TEST_ASSERT_EQUAL_UINT8(PTYPE_SENSOR,   ctx.f.type);
    TEST_ASSERT_EQUAL_UINT16(99,            ctx.f.sequence);
    TEST_ASSERT_EQUAL_UINT32(1700000000,    ctx.f.timestamp);
    TEST_ASSERT_EQUAL_UINT16(4,             ctx.f.payload_len);
    TEST_ASSERT_EQUAL_MEMORY(original.payload, ctx.f.payload, 4);
}

void test_corrupt_frame_not_queued(void) {
    Frame f = { .type = PTYPE_SENSOR, .sequence = 1, .payload_len = 0 };
    uint8_t wire[256];
    ssize_t n = frame_serialise(&f, wire, sizeof(wire));
    wire[n - 1] ^= 0xFF;   /* corrupt CRC */

    Parser p;
    parser_init(&p);
    int count = 0;

    void counter(const Frame *fr, void *ud) {
        (void)fr; (*(int *)ud)++;
    }
    parser_feed(&p, wire, (size_t)n, counter, &count);

    TEST_ASSERT_EQUAL_INT(0, count);   /* bad frame not delivered */
}

/* ── stats ────────────────────────────────────────────────────── */

void test_bridge_version_string_nonempty(void) {
    TEST_ASSERT_TRUE(strlen(BRIDGE_VERSION) > 0);
}

int main(void) {
    UNITY_BEGIN();
    RUN_TEST(test_workqueue_push_pop_roundtrip);
    RUN_TEST(test_workqueue_respects_capacity);
    RUN_TEST(test_frame_survives_bridge_pipeline);
    RUN_TEST(test_corrupt_frame_not_queued);
    RUN_TEST(test_bridge_version_string_nonempty);
    return UNITY_END();
}
```

---

## systemd unit file

```ini
# systemd/uart_bridge.service
[Unit]
Description=UART-to-TCP sensor bridge
Documentation=https://github.com/yourname/uart_bridge
After=network.target
Wants=network.target

[Service]
Type=simple
User=sensor
Group=dialout
ExecStart=/usr/local/bin/uart_bridge -d /dev/ttyUSB0 -p 7778
ExecReload=/bin/kill -HUP $MAINPID
Restart=on-failure
RestartSec=5s
StartLimitIntervalSec=60s
StartLimitBurst=5

# Hardening
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/var/log/uart_bridge

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=uart_bridge

# Resource limits
LimitNOFILE=4096
LimitCORE=infinity    # allow core dumps in production

[Install]
WantedBy=multi-user.target
```

```bash
# Install and start
sudo cmake --install build
sudo systemctl daemon-reload
sudo systemctl enable uart_bridge
sudo systemctl start uart_bridge
sudo systemctl status uart_bridge
journalctl -u uart_bridge -f    # follow logs
```

---

## CMakeLists.txt

```cmake
cmake_minimum_required(VERSION 3.16)
project(uart_bridge VERSION 1.0.0 LANGUAGES C)

set(CMAKE_C_STANDARD 11)
set(CMAKE_C_STANDARD_REQUIRED ON)
set(CMAKE_C_EXTENSIONS OFF)

if(NOT CMAKE_BUILD_TYPE)
    set(CMAKE_BUILD_TYPE Debug CACHE STRING "" FORCE)
endif()

add_compile_options(-Wall -Wextra -Werror -Wformat-security)

# ── library ───────────────────────────────────────────────────────
add_library(bridge_lib STATIC
    src/bridge.c
    src/serial.c
    src/protocol.c
    src/workqueue.c
    src/spsc_ring.c
    src/errors.c
    src/log.c
)
target_include_directories(bridge_lib PUBLIC include)
target_link_libraries(bridge_lib PUBLIC pthread)

if(CMAKE_BUILD_TYPE STREQUAL "Debug" AND
   CMAKE_SYSTEM_PROCESSOR STREQUAL CMAKE_HOST_SYSTEM_PROCESSOR)
    target_compile_options(bridge_lib PUBLIC
        -g -O0 -fsanitize=address,undefined,thread)
    target_link_options(bridge_lib PUBLIC
        -fsanitize=address,undefined,thread)
endif()

# ── executable ────────────────────────────────────────────────────
add_executable(uart_bridge src/main.c)
target_link_libraries(uart_bridge PRIVATE bridge_lib)

install(TARGETS uart_bridge RUNTIME DESTINATION bin)
install(FILES systemd/uart_bridge.service
    DESTINATION /etc/systemd/system OPTIONAL)

# ── tests ─────────────────────────────────────────────────────────
if(CMAKE_SYSTEM_PROCESSOR STREQUAL CMAKE_HOST_SYSTEM_PROCESSOR)
    enable_testing()
    add_subdirectory(tests)
endif()
```

---

## Testing end-to-end with socat

```bash
# Terminal 1 — start the bridge on a virtual serial port
socat PTY,link=/tmp/ttyV0,raw,echo=0 \
      PTY,link=/tmp/ttyV1,raw,echo=0 &

./build/uart_bridge -d /tmp/ttyV0 -p 7778 -v

# Terminal 2 — send frames (Python frame generator from Day 19)
python3 -c "
import serial, struct, time, random

def make_frame(seq, device, value):
    payload = struct.pack('>BHI', device, int(value * 16), int(time.time()))
    hdr = struct.pack('>HBBHHI',
        0xBEEF, 1, 0x01, seq, len(payload), int(time.time()))
    data = hdr + payload
    crc = 0xFFFF
    for b in data:
        crc ^= b << 8
        for _ in range(8):
            crc = (crc << 1) ^ 0x1021 if crc & 0x8000 else crc << 1
        crc &= 0xFFFF
    return data + struct.pack('>H', crc)

s = serial.Serial('/tmp/ttyV1', 115200)
for seq in range(100):
    s.write(make_frame(seq, seq % 4, 20.0 + random.uniform(-5, 5)))
    time.sleep(0.1)
"

# Terminal 3 — connect as a TCP client and watch frames arrive
nc localhost 7778 | xxd | head -80

# Terminal 4 — second simultaneous client
nc localhost 7778 | xxd | head -80
```

---

## What you built over 30 days

Phase 1 (Days 1–10) gave you the language: memory model, pointers, arrays, functions, structs, heap allocation, the preprocessor, error handling, and a production build system.

Phase 2 (Days 11–20) gave you the OS interface: file I/O, processes, signals, pipes, TCP sockets, non-blocking I/O with poll, threads and synchronisation, serial communication, and binary protocol parsing.

Phase 3 (Days 21–30) gave you the advanced techniques: epoll for high-performance event loops, lock-free atomics, dynamic plugins, GDB debugging, profiling, secure coding, embedded C patterns, testing, cross-compilation, and this capstone that ties it all together.

The capstone uses every major concept: `termios` serial configuration, a streaming state machine parser, a lock-free SPSC ring buffer, a mutex-protected work queue, an epoll event loop with `signalfd` and `timerfd`, TCP client management with idle timeouts, graceful shutdown via atomic flags, structured logging, a Unity test suite, CMake with cross-compilation support, and a systemd service file with hardening options.

That's production-quality C systems programming. The path from here is depth in whichever direction your work takes you — real-time operating systems, network protocol implementation, kernel development, or high-performance server engineering. The foundation is solid.