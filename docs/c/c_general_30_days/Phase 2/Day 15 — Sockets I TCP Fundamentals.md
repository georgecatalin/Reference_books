

Pipes gave you byte streams between processes on the same machine. Sockets extend the exact same model across a network — or between processes on the same machine when you need a more flexible addressing scheme. TCP sockets give you a reliable, ordered, connection-oriented byte stream. The API is more complex than pipes but follows the same mental model: open a connection, read and write bytes, close it. Today you build a working TCP server and client from scratch using the BSD socket API that underpins every networked C program on Linux.

---

## The BSD socket API

The socket API was designed in the early 1980s at Berkeley and has remained essentially unchanged since. It is the foundation of all network programming on Unix, Linux, and Windows (via Winsock). Every web server, every database client, every MQTT broker you have ever used is built on these same six functions at the bottom: `socket`, `bind`, `listen`, `accept`, `connect`, `close`.

The API is verbose and requires careful sequencing. There is no shortcut version for production code. Understanding each step precisely is what separates network programmers who debug in minutes from those who debug for hours.

---

## Server side: socket, bind, listen, accept

A TCP server follows a fixed sequence of calls before it can exchange data with a client.

`socket` creates a socket file descriptor. It takes an address family, a socket type, and a protocol.

```c
int sockfd = socket(AF_INET, SOCK_STREAM, 0);
if (sockfd == -1) {
    perror("socket");
    return 1;
}
```

`AF_INET` means IPv4. `AF_INET6` means IPv6. `SOCK_STREAM` means TCP — a reliable ordered byte stream. `SOCK_DGRAM` means UDP — unreliable unordered datagrams. The third argument is 0 to let the OS choose the appropriate protocol for the socket type.

`setsockopt` with `SO_REUSEADDR` must be set before `bind`. Without it, if your server crashes or exits, the port remains in the `TIME_WAIT` state for up to two minutes and your next attempt to start the server fails with `Address already in use`. This is not optional — always set it.

```c
int opt = 1;
if (setsockopt(sockfd, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt)) == -1) {
    perror("setsockopt");
    close(sockfd);
    return 1;
}
```

`bind` assigns a local address and port to the socket. You fill in a `struct sockaddr_in` with the address family, port in network byte order, and the IP address to listen on.

```c
#include <netinet/in.h>
#include <string.h>

struct sockaddr_in addr = {0};
addr.sin_family      = AF_INET;
addr.sin_port        = htons(8080);      /* host to network short */
addr.sin_addr.s_addr = INADDR_ANY;       /* listen on all interfaces */

if (bind(sockfd, (struct sockaddr *)&addr, sizeof(addr)) == -1) {
    perror("bind");
    close(sockfd);
    return 1;
}
```

`htons` converts the port number from host byte order to network byte order — big-endian. On little-endian machines like x86 this is a byte swap. On big-endian machines it is a no-op. Always use `htons` for port numbers and `htonl` for 32-bit addresses regardless of your platform.

`listen` marks the socket as passive — ready to accept incoming connections. The second argument is the backlog: the maximum number of pending connections the kernel will queue before refusing new ones.

```c
if (listen(sockfd, 128) == -1) {
    perror("listen");
    close(sockfd);
    return 1;
}
```

`accept` blocks until a client connects and returns a new socket file descriptor for that specific connection. The original listening socket remains open and continues accepting new connections. Every accepted connection gets its own fd.

```c
struct sockaddr_in client_addr = {0};
socklen_t client_len = sizeof(client_addr);

int clientfd = accept(sockfd, (struct sockaddr *)&client_addr, &client_len);
if (clientfd == -1) {
    perror("accept");
    continue;   /* in a loop — try again */
}

char client_ip[INET_ADDRSTRLEN];
inet_ntop(AF_INET, &client_addr.sin_addr, client_ip, sizeof(client_ip));
printf("connection from %s:%d\n", client_ip, ntohs(client_addr.sin_port));
```

`inet_ntop` converts a binary IP address to a human-readable string. `ntohs` converts a port from network byte order back to host byte order for display.

---

## Client side: socket, connect

The client side is simpler. Create a socket, fill in the server address, call `connect`.

```c
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <unistd.h>
#include <string.h>
#include <stdio.h>

int main(void) {
    int sockfd = socket(AF_INET, SOCK_STREAM, 0);
    if (sockfd == -1) { perror("socket"); return 1; }

    struct sockaddr_in addr = {0};
    addr.sin_family = AF_INET;
    addr.sin_port   = htons(8080);
    inet_pton(AF_INET, "127.0.0.1", &addr.sin_addr);

    if (connect(sockfd, (struct sockaddr *)&addr, sizeof(addr)) == -1) {
        perror("connect");
        close(sockfd);
        return 1;
    }

    /* now read and write through sockfd */
    const char *msg = "hello server\n";
    write(sockfd, msg, strlen(msg));

    char buf[256];
    ssize_t n = read(sockfd, buf, sizeof(buf) - 1);
    if (n > 0) {
        buf[n] = '\0';
        printf("server says: %s", buf);
    }

    close(sockfd);
    return 0;
}
```

`inet_pton` converts a human-readable IP string to binary. It is the inverse of `inet_ntop`. For a production client you would use `getaddrinfo` to resolve hostnames, but for direct IP connections `inet_pton` is appropriate.

---

## Reading and writing on sockets

Sockets use `read` and `write` just like file descriptors and pipes. The same partial read and partial write behavior applies — you must use loops as you built on Day 11. The `write_all` and `read_all` functions from Day 11 work unchanged on sockets.

Two additional functions exist specifically for sockets: `send` and `recv`. They are identical to `write` and `read` but accept a flags argument for special behaviors.

```c
send(sockfd, buf, len, 0);          /* flags=0: identical to write */
send(sockfd, buf, len, MSG_NOSIGNAL); /* suppress SIGPIPE on broken connection */
recv(sockfd, buf, len, 0);          /* flags=0: identical to read */
recv(sockfd, buf, len, MSG_WAITALL); /* block until all len bytes received */
```

`MSG_NOSIGNAL` on `send` suppresses the `SIGPIPE` signal for that call, returning -1 with `errno == EPIPE` instead. If you have already installed `SIG_IGN` for `SIGPIPE` (as covered on Day 13), this flag is redundant but harmless. Use whichever approach is consistent in your codebase.

---

## The accept loop

A real server loops on `accept`, handling each connection. The simplest structure handles one connection at a time:

```c
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <errno.h>
#include <signal.h>

static volatile sig_atomic_t g_running = 1;
static void handle_shutdown(int sig) { (void)sig; g_running = 0; }

static int create_server(uint16_t port) {
    int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd == -1) { perror("socket"); return -1; }

    int opt = 1;
    setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));

    struct sockaddr_in addr = {0};
    addr.sin_family      = AF_INET;
    addr.sin_port        = htons(port);
    addr.sin_addr.s_addr = INADDR_ANY;

    if (bind(fd, (struct sockaddr *)&addr, sizeof(addr)) == -1) {
        perror("bind"); close(fd); return -1;
    }
    if (listen(fd, 128) == -1) {
        perror("listen"); close(fd); return -1;
    }
    return fd;
}

static void handle_client(int clientfd) {
    char buf[4096];
    ssize_t n;
    while ((n = read(clientfd, buf, sizeof(buf))) > 0) {
        /* echo back */
        write(clientfd, buf, (size_t)n);
    }
    close(clientfd);
}

int main(void) {
    struct sigaction sa = {0};
    sa.sa_handler = handle_shutdown;
    sa.sa_flags   = SA_RESTART;
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    struct sigaction sa_ign = {0};
    sa_ign.sa_handler = SIG_IGN;
    sigemptyset(&sa_ign.sa_mask);
    sigaction(SIGPIPE, &sa_ign, NULL);

    int serverfd = create_server(8080);
    if (serverfd == -1) return 1;

    printf("listening on port 8080\n");

    while (g_running) {
        struct sockaddr_in client_addr = {0};
        socklen_t client_len = sizeof(client_addr);

        int clientfd = accept(serverfd, (struct sockaddr *)&client_addr, &client_len);
        if (clientfd == -1) {
            if (errno == EINTR) continue;   /* signal interrupted accept — check g_running */
            perror("accept");
            break;
        }

        handle_client(clientfd);   /* blocks until client disconnects */
    }

    close(serverfd);
    printf("server stopped\n");
    return 0;
}
```

This server handles one client at a time — while one client is connected, no other client can connect. It is the simplest possible correct server. Day 16 makes it concurrent with non-blocking I/O.

---

## SO_REUSEADDR in depth

`SO_REUSEADDR` does two things. First, it allows binding to a port that is in `TIME_WAIT` from a recent connection. Second, it allows multiple sockets to bind to the same port if each uses a different local address. For a server that you restart frequently during development — or that must restart quickly after a crash in production — `SO_REUSEADDR` is mandatory.

`SO_REUSEPORT`, available on Linux 3.9 and later, goes further: it allows multiple sockets to bind to the exact same address and port combination, with the kernel load-balancing incoming connections across them. This is used by multi-process servers where each worker process binds independently.

---

## TCP keepalive

A TCP connection can silently die — the remote end crashes, a NAT table entry expires, a cable is unplugged — without either side knowing. The connection appears open but is permanently broken. TCP keepalive tells the kernel to periodically send probe packets and tear down the connection if no response comes.

```c
int keep = 1;
setsockopt(clientfd, SOL_SOCKET, SO_KEEPALIVE, &keep, sizeof(keep));

int idle     = 60;   /* seconds before first probe */
int interval = 10;   /* seconds between probes */
int count    = 3;    /* probes before declaring dead */
setsockopt(clientfd, IPPROTO_TCP, TCP_KEEPIDLE,   &idle,     sizeof(idle));
setsockopt(clientfd, IPPROTO_TCP, TCP_KEEPINTVL,  &interval, sizeof(interval));
setsockopt(clientfd, IPPROTO_TCP, TCP_KEEPCNT,    &count,    sizeof(count));
```

For long-lived connections — MQTT broker connections, persistent device connections, database connections — always enable keepalive. Without it a crashed device may leave a connection open on the server for hours or days.

---

## getaddrinfo — resolving hostnames

For a client that connects to a hostname rather than a hardcoded IP:

```c
#include <netdb.h>

struct addrinfo hints = {0};
hints.ai_family   = AF_UNSPEC;      /* accept IPv4 or IPv6 */
hints.ai_socktype = SOCK_STREAM;

struct addrinfo *results;
int err = getaddrinfo("example.com", "8080", &hints, &results);
if (err != 0) {
    fprintf(stderr, "getaddrinfo: %s\n", gai_strerror(err));
    return 1;
}

int sockfd = -1;
for (struct addrinfo *rp = results; rp != NULL; rp = rp->ai_next) {
    sockfd = socket(rp->ai_family, rp->ai_socktype, rp->ai_protocol);
    if (sockfd == -1) continue;

    if (connect(sockfd, rp->ai_addr, rp->ai_addrlen) == 0) break;

    close(sockfd);
    sockfd = -1;
}
freeaddrinfo(results);

if (sockfd == -1) {
    fprintf(stderr, "could not connect\n");
    return 1;
}
```

`getaddrinfo` returns a linked list of address structures. You try each one until a `connect` succeeds. This handles both IPv4 and IPv6 addresses, multiple A records, and DNS round-robin transparently. Always use `getaddrinfo` for hostname resolution in production code — never `gethostbyname`, which is not thread-safe and does not support IPv6.

---

## Practical exercise

Build a complete echo server and test client.

The server listens on port 9000, accepts connections sequentially, reads lines from each client, and echoes them back with a line number prepended: `001: hello` for the first line received from that connection. When the client disconnects, the server logs the total number of lines exchanged and returns to accepting. Install `SIGTERM` and `SIGINT` handlers for graceful shutdown, and ignore `SIGPIPE`. Set `SO_REUSEADDR` and TCP keepalive on every accepted connection.

The client connects to localhost:9000, sends five lines read from `argv` or hardcoded, reads and prints each echoed response, then closes the connection.

Then extend the server: after closing a client connection, if fewer than five total clients have been served, accept the next one. After five clients, shut down gracefully. Use `WEXITSTATUS` to verify the exit code is 0.

Test with `telnet localhost 9000` as an alternative client. Type lines and observe echoes. Press Ctrl+] then `quit` to disconnect and watch the server log the session.

---

## What to carry forward

The TCP server sequence is socket, setsockopt SO_REUSEADDR, bind, listen, accept loop. The client sequence is socket, connect. Always handle partial reads and writes with retry loops. Always ignore SIGPIPE. Always set SO_REUSEADDR before bind. Use getaddrinfo for hostname resolution. Enable TCP keepalive on long-lived connections. This blocking single-client server is correct but limited — tomorrow you make it handle many clients simultaneously without threads.

Tomorrow: non-blocking I/O and select/poll — the foundation of concurrent servers.