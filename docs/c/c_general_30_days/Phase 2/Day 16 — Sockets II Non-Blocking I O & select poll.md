
Yesterday's server handled one client at a time. While serving one client, every other client waited. For a device monitoring dashboard with dozens of simultaneous connections, or an MQTT broker handling hundreds of devices, that model collapses immediately. Today you learn how to handle many connections simultaneously in a single thread using non-blocking I/O and I/O multiplexing. This is the foundation of every high-performance server written in C.

---

## Why blocking I/O does not scale

In blocking mode, every I/O call — `read`, `write`, `accept`, `connect` — suspends the calling thread until it can complete. One slow client stalls the entire server. The naive solution is threads: one thread per connection. This works up to a few hundred connections before thread creation overhead, stack memory consumption, and context switching cost become prohibitive. For IoT fleets with thousands of devices, threads per connection is the wrong architecture.

The correct architecture: one thread, many connections, non-blocking I/O, and a multiplexer that tells you which connections are ready for I/O right now. You only call `read` or `write` on connections that are ready — calls that will not block.

---

## O_NONBLOCK — making a file descriptor non-blocking

Setting `O_NONBLOCK` on a file descriptor changes every I/O call on it from blocking to immediate: if the call cannot complete right now, it returns -1 with `errno` set to `EAGAIN` or `EWOULDBLOCK` instead of sleeping.

```c
#include <fcntl.h>
#include <unistd.h>

int set_nonblocking(int fd) {
    int flags = fcntl(fd, F_GETFL, 0);
    if (flags == -1) return -1;
    return fcntl(fd, F_SETFL, flags | O_NONBLOCK);
}
```

Apply this to every client socket after `accept` and to the listening socket itself. With a non-blocking listening socket, `accept` returns -1 with `errno == EAGAIN` when no connection is pending rather than blocking.

`EAGAIN` and `EWOULDBLOCK` are the same value on Linux but are distinct on some other platforms. Check for both to be portable:

```c
if (errno == EAGAIN || errno == EWOULDBLOCK) {
    /* no data available right now — try again later */
}
```

This is not an error. It is the non-blocking I/O contract: the operation would have blocked, so it returned immediately instead. Your response is to note that this fd is not ready and move on.

---

## The event loop mental model

Non-blocking I/O by itself is not useful — you would have to spin in a loop calling read on every connection repeatedly, burning CPU doing nothing. The multiplexer solves this: you tell it which file descriptors you are interested in and what events you want to know about (readable, writable, error), then block in a single call. The multiplexer returns when at least one fd is ready. You service those fds, then go back to waiting.

```
loop:
    wait for any fd to become ready (blocking call to select/poll/epoll)
    for each ready fd:
        if it is the listening socket:
            accept the new connection
            add it to the watch set
        else if it is readable:
            read data from it
            if read returns 0: client disconnected, remove from watch set
        else if it is writable and we have pending data:
            write as much as we can
    go back to loop
```

This is the event loop. It is single-threaded, never blocks on I/O, and can handle thousands of connections. The same pattern is used by nginx, Redis, Node.js, and virtually every high-performance server.

---

## select — the classic multiplexer

`select` has been in Unix since the 1980s. It is portable to everything including Windows. It has significant limitations — maximum 1024 file descriptors and O(n) scanning — but it is the right tool for servers with modest connection counts and the conceptual foundation for understanding poll and epoll.

```c
#include <sys/select.h>

fd_set readfds;
FD_ZERO(&readfds);
FD_SET(sockfd, &readfds);

struct timeval timeout;
timeout.tv_sec  = 5;
timeout.tv_usec = 0;

int nready = select(sockfd + 1, &readfds, NULL, NULL, &timeout);
if (nready == -1) {
    if (errno == EINTR) /* signal interrupted — check flags and retry */;
    else perror("select");
} else if (nready == 0) {
    /* timeout expired — no fds became ready */
} else {
    if (FD_ISSET(sockfd, &readfds)) {
        /* sockfd is readable */
    }
}
```

`select` takes the highest file descriptor number plus one as its first argument — it scans fds from 0 to `nfds-1`. You must rebuild the `fd_set` before every call because select modifies it in place. The timeout is also modified on Linux — after select returns, `timeout` reflects the remaining time. Rebuild it before every call if you need a consistent timeout.

A complete single-threaded server with select managing multiple clients:

```c
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/socket.h>
#include <sys/select.h>
#include <netinet/in.h>
#include <fcntl.h>
#include <errno.h>
#include <signal.h>

#define MAX_CLIENTS 64

static volatile sig_atomic_t g_running = 1;
static void on_shutdown(int s) { (void)s; g_running = 0; }

static int set_nonblocking(int fd) {
    int f = fcntl(fd, F_GETFL, 0);
    return fcntl(fd, F_SETFL, f | O_NONBLOCK);
}

int main(void) {
    struct sigaction sa = {0};
    sa.sa_handler = on_shutdown;
    sa.sa_flags   = 0;   /* no SA_RESTART — let select return EINTR */
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    struct sigaction sa_ign = {0};
    sa_ign.sa_handler = SIG_IGN;
    sigemptyset(&sa_ign.sa_mask);
    sigaction(SIGPIPE, &sa_ign, NULL);

    int listenfd = socket(AF_INET, SOCK_STREAM, 0);
    int opt = 1;
    setsockopt(listenfd, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));
    set_nonblocking(listenfd);

    struct sockaddr_in addr = {0};
    addr.sin_family      = AF_INET;
    addr.sin_port        = htons(9000);
    addr.sin_addr.s_addr = INADDR_ANY;
    bind(listenfd, (struct sockaddr *)&addr, sizeof(addr));
    listen(listenfd, 128);

    int clients[MAX_CLIENTS];
    for (int i = 0; i < MAX_CLIENTS; i++) clients[i] = -1;

    printf("listening on port 9000\n");

    while (g_running) {
        fd_set readfds;
        FD_ZERO(&readfds);
        FD_SET(listenfd, &readfds);
        int maxfd = listenfd;

        for (int i = 0; i < MAX_CLIENTS; i++) {
            if (clients[i] != -1) {
                FD_SET(clients[i], &readfds);
                if (clients[i] > maxfd) maxfd = clients[i];
            }
        }

        struct timeval tv = { .tv_sec = 1, .tv_usec = 0 };
        int nready = select(maxfd + 1, &readfds, NULL, NULL, &tv);

        if (nready == -1) {
            if (errno == EINTR) continue;
            perror("select");
            break;
        }

        if (FD_ISSET(listenfd, &readfds)) {
            struct sockaddr_in ca = {0};
            socklen_t cl = sizeof(ca);
            int cfd = accept(listenfd, (struct sockaddr *)&ca, &cl);
            if (cfd != -1) {
                set_nonblocking(cfd);
                for (int i = 0; i < MAX_CLIENTS; i++) {
                    if (clients[i] == -1) { clients[i] = cfd; break; }
                }
                printf("client connected: fd=%d\n", cfd);
            }
        }

        for (int i = 0; i < MAX_CLIENTS; i++) {
            if (clients[i] == -1) continue;
            if (!FD_ISSET(clients[i], &readfds)) continue;

            char buf[1024];
            ssize_t n = read(clients[i], buf, sizeof(buf));
            if (n <= 0) {
                printf("client fd=%d disconnected\n", clients[i]);
                close(clients[i]);
                clients[i] = -1;
            } else {
                write(clients[i], buf, (size_t)n);   /* echo */
            }
        }
    }

    for (int i = 0; i < MAX_CLIENTS; i++) {
        if (clients[i] != -1) close(clients[i]);
    }
    close(listenfd);
    printf("server stopped\n");
    return 0;
}
```

Notice that `SA_RESTART` is absent for the signal handlers here. Without it, `select` returns -1 with `errno == EINTR` when a signal arrives, letting the loop check `g_running` immediately rather than waiting for the timeout to expire.

---

## poll — cleaner than select

`poll` solves select's two main problems: no fd limit and no need to rebuild the watch set from scratch on every call. You pass an array of `struct pollfd`, each specifying an fd and the events you want.

```c
#include <poll.h>

struct pollfd fds[MAX_CLIENTS + 1];
int nfds = 0;

/* add listening socket */
fds[nfds].fd      = listenfd;
fds[nfds].events  = POLLIN;
fds[nfds].revents = 0;
nfds++;

int ready = poll(fds, nfds, 1000);   /* timeout: 1000ms */
if (ready == -1) {
    if (errno == EINTR) /* handle signal */;
    else perror("poll");
}

for (int i = 0; i < nfds; i++) {
    if (fds[i].revents & POLLIN) {
        /* fds[i].fd is readable */
    }
    if (fds[i].revents & POLLHUP) {
        /* connection hung up */
    }
    if (fds[i].revents & POLLERR) {
        /* error condition */
    }
}
```

`revents` is written by the kernel with the events that actually occurred. `events` is what you asked to be notified about. After poll returns, scan every fd in your array and check `revents`.

To remove an fd from the poll set, set its `fd` field to -1. `poll` ignores entries with negative fd values. To add a new fd, find a slot with fd == -1 and fill it in, updating `nfds` if the slot is at the end.

Poll is the right choice for servers with up to a few thousand connections on any platform. For Linux servers that need to handle tens of thousands of connections, `epoll` — covered on Day 21 — scales better because it is O(1) rather than O(n) in the number of watched fds.

---

## Handling writable events and output buffering

The examples above echo data immediately. A real server often cannot write all response data in one call — the socket's send buffer may be full, producing a partial write. You need an output buffer per connection.

The pattern: keep a per-connection write buffer. When you have data to send, try writing immediately. If the write is partial or returns `EAGAIN`, store the remainder in the buffer and add `POLLOUT` to the connection's event mask. When the socket becomes writable, drain as much of the buffer as possible. When the buffer is empty, remove `POLLOUT` from the event mask.

```c
typedef struct {
    int      fd;
    uint8_t  wbuf[65536];
    size_t   wbuf_len;
} conn_t;

static int conn_flush(conn_t *c) {
    while (c->wbuf_len > 0) {
        ssize_t n = write(c->fd, c->wbuf, c->wbuf_len);
        if (n == -1) {
            if (errno == EAGAIN || errno == EWOULDBLOCK) return 0;  /* not ready */
            return -1;   /* real error */
        }
        memmove(c->wbuf, c->wbuf + n, c->wbuf_len - n);
        c->wbuf_len -= n;
    }
    return 0;
}
```

Do not register for `POLLOUT` permanently. Sockets are almost always writable — registering for POLLOUT when you have nothing to send spins the event loop doing nothing. Only watch for POLLOUT when you have buffered data waiting to go out.

---

## Integrating the signal pipe

From Day 13: the self-pipe trick makes signals visible to the poll loop. Add the read end of the signal pipe to your poll array just like any other fd. When a signal arrives, the handler writes one byte to the write end, poll wakes up, you drain the read end and check your flags.

```c
static int g_sigpipe[2];

static void handle_signal(int sig) {
    (void)sig;
    write(g_sigpipe[1], "\x00", 1);
}

/* setup */
pipe(g_sigpipe);
set_nonblocking(g_sigpipe[0]);
set_nonblocking(g_sigpipe[1]);

/* add to poll array */
fds[0].fd     = g_sigpipe[0];
fds[0].events = POLLIN;

/* in event loop, when fds[0] is readable */
char dummy;
while (read(g_sigpipe[0], &dummy, 1) == 1)
    ;   /* drain all pending signal bytes */
/* now check g_running, g_reload, etc. */
```

With this in place, signals wake the event loop immediately and are handled cleanly without races.

---

## Connection timeouts

A server must close connections that have been idle too long — otherwise a client that connects and sends nothing consumes a slot forever. Track the last activity time per connection and close idle ones on each loop iteration.

```c
#include <time.h>

typedef struct {
    int    fd;
    time_t last_active;
} conn_t;

/* in event loop, before or after poll */
time_t now = time(NULL);
for (int i = 0; i < MAX_CLIENTS; i++) {
    if (clients[i].fd == -1) continue;
    if (now - clients[i].last_active > 60) {
        printf("closing idle connection fd=%d\n", clients[i].fd);
        close(clients[i].fd);
        clients[i].fd = -1;
    }
}
```

Update `last_active` every time you successfully read data from the connection. The poll timeout controls how often the loop runs and therefore how precisely idle connections are reaped — a one-second timeout gives one-second granularity on idle detection.

---

## Practical exercise

Rewrite the Day 15 echo server using `poll` instead of blocking accept. The server should handle up to 64 simultaneous connections, echoing every line received back to the sender. Each echoed line is prefixed with the connection's file descriptor number: `[fd=7] hello`.

Add idle timeout: connections that send nothing for 30 seconds are closed with a log message. Add the self-pipe trick for clean signal handling — `SIGTERM` and `SIGINT` set a shutdown flag that the event loop checks after poll returns.

When shutdown is triggered, stop accepting new connections, send a `BYE\n` message to every connected client, close all connections, and exit cleanly.

Test with three simultaneous `telnet` sessions. Verify that typing in one session does not delay responses in another. Verify that an idle session is closed after 30 seconds. Verify that Ctrl+C triggers the shutdown sequence and all clients receive `BYE`.

---

## What to carry forward

Non-blocking I/O returns immediately with EAGAIN instead of sleeping. The event loop waits on select or poll until fds are ready, then services only ready fds. Never register POLLOUT when you have nothing to write. Use the self-pipe trick to integrate signals with the event loop. Track idle time per connection and enforce timeouts. This single-threaded event loop model is the architecture of Redis, nginx in single-worker mode, and every embedded TCP server you will write in this curriculum. Day 21 replaces poll with epoll for production-scale connection counts.

Tomorrow: POSIX threads — when you need true parallelism alongside the event loop.