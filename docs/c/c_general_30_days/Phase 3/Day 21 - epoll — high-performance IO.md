

`poll()` from Day 16 scans its entire fd array on every call — O(n) work even if only one fd is active. With 10,000 connections, that's 10,000 fd checks per iteration regardless of how many actually have data. `epoll` inverts the model: you register interest once, and the kernel tells you only which fds are ready. The result is O(1) per active fd regardless of total connection count. This is how nginx, Redis, and Node.js serve millions of connections from a single thread.

---

## The epoll model

Three syscalls do everything. The mental model is a kernel-side set of watched fds plus an event queue:

```
epoll_create1()  →  creates the epoll instance (returns an epfd)
epoll_ctl()      →  add / modify / remove an fd from the watch set
epoll_wait()     →  block until events are ready, returns only ready fds
```

Compare to `poll`: you rebuild the entire watch list on every call. With epoll you build it once and modify it incrementally — adding a new connection is one `epoll_ctl(EPOLL_CTL_ADD)` call; removing it on disconnect is one `epoll_ctl(EPOLL_CTL_DEL)`.

---

## Level-triggered vs edge-triggered

This is the most important design decision when using epoll, and the most common source of bugs:

**Level-triggered (LT)** — the default. epoll reports a fd as readable as long as there is data in the buffer. If you read 100 bytes but 200 are available, the fd will be reported ready again on the next `epoll_wait`. Safe and forgiving — the same mental model as `poll`.

**Edge-triggered (ET)** — set with `EPOLLET`. epoll reports a fd as readable only when its state _changes_ — when new data arrives. If you don't read all available data in one shot, you will never be notified about the remainder until more data arrives. Requires non-blocking I/O and a read-until-EAGAIN loop. More complex, but zero wasted notifications.

```c
/* Level-triggered — easier, correct if you might leave data in the buffer */
ev.events = EPOLLIN;

/* Edge-triggered — must drain completely on every notification */
ev.events = EPOLLIN | EPOLLET;
```

For most IoT gateway and server work, level-triggered is the right choice. Use edge-triggered only when you've profiled and determined that notification overhead is a bottleneck.

---

## The complete epoll API

```c
#include <sys/epoll.h>

/* Create an epoll instance. Returns epfd — treat it like any other fd. */
int epfd = epoll_create1(EPOLL_CLOEXEC);
/* EPOLL_CLOEXEC: close epfd on exec — always set this */

/* Control: add, modify, or remove fds */
struct epoll_event ev;
ev.events   = EPOLLIN;        /* what to watch for */
ev.data.fd  = target_fd;      /* or ev.data.ptr for richer context */

epoll_ctl(epfd, EPOLL_CTL_ADD, target_fd, &ev);   /* start watching */
epoll_ctl(epfd, EPOLL_CTL_MOD, target_fd, &ev);   /* change events */
epoll_ctl(epfd, EPOLL_CTL_DEL, target_fd, NULL);  /* stop watching */

/* Wait for events */
struct epoll_event events[64];
int n = epoll_wait(epfd, events, 64, timeout_ms);
/* n: number of ready events, 0 on timeout, -1 on error */
/* timeout_ms: -1 = block forever, 0 = non-blocking */

for (int i = 0; i < n; i++) {
    int fd = events[i].data.fd;
    if (events[i].events & EPOLLIN)  { /* read ready  */ }
    if (events[i].events & EPOLLOUT) { /* write ready */ }
    if (events[i].events & EPOLLHUP) { /* hang up     */ }
    if (events[i].events & EPOLLERR) { /* error       */ }
}
```

The `epoll_event.data` union is your hook for attaching arbitrary context to a fd. Instead of `data.fd` you can store `data.ptr` — a pointer to a connection struct. This eliminates the need to look up connection state by fd number on every event.

---

## Using `data.ptr` for zero-lookup dispatch

This is the pattern that makes epoll-based servers fast. Each event carries a pointer directly to the connection struct that handles it:

```c
typedef struct Conn Conn;   /* forward declaration */

typedef void (*EventHandler)(Conn *c, uint32_t events);

struct Conn {
    int          fd;
    EventHandler handler;       /* function to call on events */
    char         addr[46];      /* peer address string */
    /* ... per-connection state ... */
};

/* Register a connection with epoll */
void conn_watch(int epfd, Conn *c, uint32_t events) {
    struct epoll_event ev;
    ev.events   = events;
    ev.data.ptr = c;           /* pointer stored in epoll, returned on event */
    epoll_ctl(epfd, EPOLL_CTL_ADD, c->fd, &ev);
}

/* Event loop dispatch — no fd-to-conn lookup needed */
struct epoll_event evs[64];
int n = epoll_wait(epfd, evs, 64, -1);
for (int i = 0; i < n; i++) {
    Conn *c = evs[i].data.ptr;         /* direct pointer — O(1) */
    c->handler(c, evs[i].events);      /* dispatch to handler */
}
```

---

## `signalfd` — signals in the event loop

Day 13's self-pipe trick works but is clunky. Linux's `signalfd` gives you a proper fd that becomes readable when signals arrive — integrates cleanly with epoll:

```c
#include <sys/signalfd.h>
#include <signal.h>

int make_signalfd(void) {
    sigset_t mask;
    sigemptyset(&mask);
    sigaddset(&mask, SIGTERM);
    sigaddset(&mask, SIGINT);
    sigaddset(&mask, SIGHUP);

    /* Block these signals from normal delivery — we'll read them via fd */
    sigprocmask(SIG_BLOCK, &mask, NULL);

    int sfd = signalfd(-1, &mask, SFD_NONBLOCK | SFD_CLOEXEC);
    if (sfd < 0) { perror("signalfd"); return -1; }
    return sfd;
}

/* When signalfd fd is readable: */
void handle_signal_event(int sfd) {
    struct signalfd_siginfo info;
    ssize_t n = read(sfd, &info, sizeof(info));
    if (n != sizeof(info)) return;

    switch (info.ssi_signo) {
    case SIGTERM: /* fall through */
    case SIGINT:  g_quit = 1;         break;
    case SIGHUP:  g_reload = 1;       break;
    }
}
```

---

## `timerfd` — timers in the event loop

Rather than `alarm()` or `SIGALRM`, Linux `timerfd` gives you timer expiry as fd readability — perfect for keepalive checks, reconnect delays, and rate limiting inside an epoll loop:

```c
#include <sys/timerfd.h>

int make_timerfd(int interval_sec) {
    int tfd = timerfd_create(CLOCK_MONOTONIC,
                             TFD_NONBLOCK | TFD_CLOEXEC);
    if (tfd < 0) { perror("timerfd_create"); return -1; }

    struct itimerspec ts = {
        .it_interval = { .tv_sec = interval_sec },  /* repeat every N sec */
        .it_value    = { .tv_sec = interval_sec },  /* first fire after N sec */
    };
    if (timerfd_settime(tfd, 0, &ts, NULL) < 0) {
        perror("timerfd_settime"); close(tfd); return -1;
    }
    return tfd;
}

/* When timerfd is readable: */
void handle_timer_event(int tfd) {
    uint64_t expirations;
    read(tfd, &expirations, sizeof(expirations));
    /* expirations: how many times the timer fired since last read
       normally 1; > 1 means we fell behind */
    if (expirations > 1)
        LOG_WARN("timer: missed %llu expirations", (unsigned long long)expirations - 1);

    /* do periodic work: send keepalives, check idle connections, etc. */
}
```

---

## A complete epoll server

Everything above combined into a production-quality multi-client server with signals, timers, and per-connection state — all in a single thread:

```c
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <errno.h>
#include <fcntl.h>
#include <time.h>
#include <sys/epoll.h>
#include <sys/signalfd.h>
#include <sys/timerfd.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <signal.h>
#include "log.h"
#include "errors.h"

#define PORT          7777
#define MAX_EVENTS    64
#define MAX_CONNS     1024
#define BUFSIZE       4096
#define IDLE_TIMEOUT  30    /* seconds before idle client is dropped */

/* ── connection state ─────────────────────────────────────────── */

typedef enum {
    CONN_LISTENER,
    CONN_CLIENT,
    CONN_SIGNAL,
    CONN_TIMER,
} ConnType;

typedef struct Conn {
    int      fd;
    ConnType type;
    char     addr[46];
    time_t   last_active;
    long     bytes_in;
    long     bytes_out;
} Conn;

/* ── utility ──────────────────────────────────────────────────── */

static int set_nonblocking(int fd) {
    int f = fcntl(fd, F_GETFL, 0);
    return fcntl(fd, F_SETFL, f | O_NONBLOCK);
}

static void conn_watch(int epfd, Conn *c, uint32_t events) {
    struct epoll_event ev = { .events = events, .data.ptr = c };
    epoll_ctl(epfd, EPOLL_CTL_ADD, c->fd, &ev);
}

static void conn_unwatch(int epfd, Conn *c) {
    epoll_ctl(epfd, EPOLL_CTL_DEL, c->fd, NULL);
    close(c->fd);
    c->fd = -1;
}

/* ── handlers ─────────────────────────────────────────────────── */

static int g_quit   = 0;
static int g_reload = 0;

static void handle_signal(Conn *c) {
    struct signalfd_siginfo info;
    if (read(c->fd, &info, sizeof(info)) != sizeof(info)) return;
    switch (info.ssi_signo) {
    case SIGTERM: case SIGINT: g_quit   = 1; break;
    case SIGHUP:               g_reload = 1; break;
    }
}

static void handle_timer(Conn *c, Conn *conns, int nconns) {
    uint64_t exp;
    read(c->fd, &exp, sizeof(exp));

    time_t now = time(NULL);
    int dropped = 0;

    for (int i = 0; i < nconns; i++) {
        if (conns[i].type != CONN_CLIENT) continue;
        if (conns[i].fd < 0) continue;
        if (now - conns[i].last_active > IDLE_TIMEOUT) {
            LOG_INFO("idle timeout: %s", conns[i].addr);
            dropped++;
        }
    }
    if (dropped)
        LOG_INFO("timer: dropped %d idle connections", dropped);
}

static Conn *handle_accept(int epfd, Conn *listener,
                           Conn *conns, int *nconns, int max_conns) {
    struct sockaddr_in peer;
    socklen_t plen = sizeof(peer);

    int cfd = accept(listener->fd, (struct sockaddr *)&peer, &plen);
    if (cfd < 0) {
        if (errno != EAGAIN) LOG_ERRNO("accept");
        return NULL;
    }
    set_nonblocking(cfd);

    if (*nconns >= max_conns) {
        LOG_WARN("max connections reached — rejecting");
        close(cfd);
        return NULL;
    }

    Conn *c = &conns[(*nconns)++];
    c->fd          = cfd;
    c->type        = CONN_CLIENT;
    c->last_active = time(NULL);
    c->bytes_in    = 0;
    c->bytes_out   = 0;
    inet_ntop(AF_INET, &peer.sin_addr, c->addr, sizeof(c->addr));

    conn_watch(epfd, c, EPOLLIN | EPOLLRDHUP | EPOLLET);
    LOG_INFO("connect: %s (slot %d)", c->addr, *nconns - 1);
    return c;
}

static void handle_client(int epfd, Conn *c, uint32_t events) {
    if (events & (EPOLLHUP | EPOLLERR | EPOLLRDHUP)) {
        LOG_INFO("disconnect: %s (%ld in, %ld out)",
                 c->addr, c->bytes_in, c->bytes_out);
        conn_unwatch(epfd, c);
        c->type = -1;   /* mark slot free */
        return;
    }

    if (events & EPOLLIN) {
        char buf[BUFSIZE];
        /* Edge-triggered: must read until EAGAIN */
        for (;;) {
            ssize_t n = read(c->fd, buf, sizeof(buf));
            if (n < 0) {
                if (errno == EAGAIN || errno == EWOULDBLOCK) break;
                LOG_ERRNO("read");
                conn_unwatch(epfd, c);
                c->type = -1;
                return;
            }
            if (n == 0) {
                LOG_INFO("EOF: %s", c->addr);
                conn_unwatch(epfd, c);
                c->type = -1;
                return;
            }
            c->bytes_in    += n;
            c->last_active  = time(NULL);

            /* Echo back */
            ssize_t w = write(c->fd, buf, n);
            if (w > 0) c->bytes_out += w;
        }
    }
}

/* ── main ─────────────────────────────────────────────────────── */

int main(void) {
    /* ── epoll instance ── */
    int epfd = epoll_create1(EPOLL_CLOEXEC);
    if (epfd < 0) { perror("epoll_create1"); return 1; }

    /* ── listening socket ── */
    int lfd = socket(AF_INET, SOCK_STREAM, 0);
    int yes = 1;
    setsockopt(lfd, SOL_SOCKET, SO_REUSEADDR, &yes, sizeof(yes));
    set_nonblocking(lfd);
    struct sockaddr_in addr = {
        .sin_family      = AF_INET,
        .sin_port        = htons(PORT),
        .sin_addr.s_addr = INADDR_ANY,
    };
    bind(lfd, (struct sockaddr *)&addr, sizeof(addr));
    listen(lfd, 128);

    static Conn conns[MAX_CONNS + 4];   /* +4 for listener, signal, timer */
    int nconns = 0;

    Conn *lconn    = &conns[nconns++];
    lconn->fd      = lfd;
    lconn->type    = CONN_LISTENER;
    conn_watch(epfd, lconn, EPOLLIN);

    /* ── signalfd ── */
    sigset_t mask;
    sigemptyset(&mask);
    sigaddset(&mask, SIGTERM); sigaddset(&mask, SIGINT); sigaddset(&mask, SIGHUP);
    sigprocmask(SIG_BLOCK, &mask, NULL);
    int sfd = signalfd(-1, &mask, SFD_NONBLOCK | SFD_CLOEXEC);

    Conn *sconn  = &conns[nconns++];
    sconn->fd    = sfd;
    sconn->type  = CONN_SIGNAL;
    conn_watch(epfd, sconn, EPOLLIN);

    /* ── timerfd: check idle connections every 10 seconds ── */
    int tfd = make_timerfd(10);
    Conn *tconn  = &conns[nconns++];
    tconn->fd    = tfd;
    tconn->type  = CONN_TIMER;
    conn_watch(epfd, tconn, EPOLLIN);

    LOG_INFO("epoll server on port %d", PORT);

    struct epoll_event evs[MAX_EVENTS];

    while (!g_quit) {
        int n = epoll_wait(epfd, evs, MAX_EVENTS, -1);
        if (n < 0) {
            if (errno == EINTR) continue;
            perror("epoll_wait"); break;
        }

        for (int i = 0; i < n; i++) {
            Conn    *c      = evs[i].data.ptr;
            uint32_t events = evs[i].events;

            switch (c->type) {
            case CONN_LISTENER:
                /* Accept all pending connections */
                while (handle_accept(epfd, c, conns, &nconns,
                                     MAX_CONNS + 3) != NULL)
                    ;
                break;
            case CONN_SIGNAL:
                handle_signal(c);
                break;
            case CONN_TIMER:
                handle_timer(c, conns, nconns);
                break;
            case CONN_CLIENT:
                handle_client(epfd, c, events);
                break;
            }
        }

        if (g_reload) {
            g_reload = 0;
            LOG_INFO("reloading config (SIGHUP)");
        }
    }

    /* Clean shutdown */
    for (int i = 0; i < nconns; i++)
        if (conns[i].fd >= 0) close(conns[i].fd);
    close(epfd);
    LOG_INFO("server stopped");
    return 0;
}
```

Build:

```bash
gcc -Wall -Wextra -g -o epoll_server epoll_server.c log.c errors.c
```

Test with simultaneous connections:

```bash
for i in $(seq 20); do
    (while true; do echo "hello $i"; sleep 1; done | nc localhost 7777) &
done
```

---

## The write buffer problem with edge-triggered I/O

With `EPOLLET`, you must also drain writes completely. If `write()` returns `EAGAIN` mid-send, you must queue the remainder and re-enable `EPOLLOUT`:

```c
static void conn_mod_events(int epfd, Conn *c, uint32_t events) {
    struct epoll_event ev = { .events = events, .data.ptr = c };
    epoll_ctl(epfd, EPOLL_CTL_MOD, c->fd, &ev);
}

/* Queue data and attempt to flush */
void conn_enqueue_write(int epfd, Conn *c,
                        const void *data, size_t len) {
    /* Append to write buffer (from Day 16's Conn pattern) */
    conn_send(c, data, len);

    /* Try to flush immediately */
    while (c->wbuf_len > 0) {
        ssize_t n = write(c->fd, c->wbuf, c->wbuf_len);
        if (n < 0) {
            if (errno == EAGAIN) {
                /* Enable EPOLLOUT — kernel will tell us when writable */
                conn_mod_events(epfd, c, EPOLLIN | EPOLLOUT | EPOLLET);
                return;
            }
            /* Real error */
            return;
        }
        c->wbuf_len -= n;
        memmove(c->wbuf, c->wbuf + n, c->wbuf_len);
    }
}

/* When EPOLLOUT fires, flush the remaining write buffer */
if (events & EPOLLOUT) {
    conn_flush(c, /* pfd equivalent */);
    if (c->wbuf_len == 0) {
        /* Nothing left — stop watching EPOLLOUT */
        conn_mod_events(epfd, c, EPOLLIN | EPOLLET);
    }
}
```

---

## Day 21 exercise

1. Build and run the epoll server. Connect 50 clients simultaneously and verify all connections are handled without spawning any threads or processes. Use `strace -e trace=epoll_wait,epoll_ctl ./epoll_server` to observe the epoll syscalls in real time.
    
2. Change the server from edge-triggered (`EPOLLET`) to level-triggered (remove `EPOLLET`). Remove the inner `read`-until-EAGAIN loop and replace it with a single `read()` call. Verify both versions produce identical output for the same inputs. Understand why the LT version is simpler.
    
3. Add a `timerfd` that fires every second and logs a one-line status: number of active connections, total bytes in, total bytes out since the last tick. Test it by connecting a few slow clients and watching the stats update.
    
4. Add `EPOLLRDHUP` handling: when a client closes its write half (half-close), the server should finish sending any queued data and then close its side. Test by sending data with `nc`, then pressing Ctrl+D to close stdin while keeping the connection open — the server should drain and close cleanly.
    

Day 22 covers lock-free programming — C11 atomics, compare-and-swap, memory ordering, and building a lock-free ring buffer that's the backbone of high-throughput IoT data pipelines.