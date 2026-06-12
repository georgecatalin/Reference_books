
Yesterday signals gave you asynchronous notification between processes. Today you get data flow — the ability to stream bytes between processes through pipes. Pipes are the foundation of Unix composition: the reason you can chain `grep | sort | uniq` in a shell is pipes. Understanding them at the syscall level lets you build the same composition into your own programs — spawning subprocesses, capturing their output, feeding them input, and building multi-stage processing pipelines.

---

## What a pipe is

A pipe is a kernel-managed, unidirectional byte stream connecting two file descriptors. One end is the write end, the other is the read end. Bytes written to the write end come out the read end in the same order — a FIFO. The pipe has a fixed kernel buffer, typically 64 KB on Linux. When the buffer is full, writes block until a reader consumes data. When the buffer is empty, reads block until a writer produces data.

Pipes have no message boundaries. If you write 100 bytes and then 200 bytes, the reader may get all 300 in one read, or 150 and 150, or any other split. If you need message boundaries — discrete packets rather than a stream — you either frame the data yourself (length prefix, delimiter) or use a different IPC mechanism.

---

## Creating a pipe

`pipe(fds)` creates a pipe and fills a two-element array: `fds[0]` is the read end, `fds[1]` is the write end.

```c
#include <unistd.h>
#include <stdio.h>
#include <string.h>

int main(void) {
    int fds[2];
    if (pipe(fds) == -1) {
        perror("pipe");
        return 1;
    }

    /* write end: fds[1] */
    const char *msg = "hello from the writer\n";
    write(fds[1], msg, strlen(msg));
    close(fds[1]);   /* close write end — sends EOF to reader */

    /* read end: fds[0] */
    char buf[128];
    ssize_t n = read(fds[0], buf, sizeof(buf) - 1);
    if (n > 0) {
        buf[n] = '\0';
        printf("read: %s", buf);
    }
    close(fds[0]);

    return 0;
}
```

Using a pipe within a single process like this is a toy example — the real use is across a fork. But it illustrates the mechanics: write to `fds[1]`, read from `fds[0]`, close the write end to signal EOF.

---

## Pipes across fork

The pipe must be created before the fork. After fork, both parent and child have both ends open. You must close the ends you are not using — this is not optional. If the parent keeps the write end open while reading, `read` will never see EOF even after the child's write end is closed, because the parent's own copy of the write end is still open.

```c
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <string.h>
#include <sys/wait.h>

int main(void) {
    int fds[2];
    if (pipe(fds) == -1) { perror("pipe"); return 1; }

    pid_t pid = fork();
    if (pid == -1) { perror("fork"); return 1; }

    if (pid == 0) {
        /* child is the writer */
        close(fds[0]);   /* child does not read — close read end */

        const char *msg = "data from child\n";
        write(fds[1], msg, strlen(msg));
        close(fds[1]);

        _exit(0);
    }

    /* parent is the reader */
    close(fds[1]);   /* parent does not write — close write end */

    char buf[256];
    ssize_t n;
    while ((n = read(fds[0], buf, sizeof(buf) - 1)) > 0) {
        buf[n] = '\0';
        printf("parent received: %s", buf);
    }
    close(fds[0]);

    waitpid(pid, NULL, 0);
    return 0;
}
```

The rule is absolute: after fork, every process closes the pipe ends it will not use. The child closes `fds[0]`. The parent closes `fds[1]`. Only then do EOF semantics work correctly — the reader sees EOF when all write ends are closed.

---

## dup2 — redirecting standard I/O

`dup2(oldfd, newfd)` duplicates `oldfd` onto `newfd`, closing `newfd` first if it is open. After the call, `newfd` refers to the same underlying file description as `oldfd`. This is how you connect a pipe to stdin or stdout of a child process.

```c
if (pid == 0) {
    close(fds[0]);              /* close unused read end */
    dup2(fds[1], STDOUT_FILENO); /* stdout now goes into the pipe */
    close(fds[1]);              /* close original — stdout is the only copy now */

    execlp("ls", "ls", "-la", NULL);
    perror("exec");
    _exit(1);
}
```

After `dup2(fds[1], STDOUT_FILENO)`, file descriptor 1 — stdout — points into the write end of the pipe. When `ls` writes to stdout, the bytes go into the pipe. The parent can read them from `fds[0]`. This is exactly how the shell implements `ls -la | grep foo`.

The pattern for bidirectional communication — parent sends to child's stdin, reads from child's stdout — requires two pipes:

```c
int to_child[2];    /* parent writes to to_child[1], child reads from to_child[0] */
int from_child[2];  /* child writes to from_child[1], parent reads from from_child[0] */

pipe(to_child);
pipe(from_child);

pid_t pid = fork();
if (pid == 0) {
    dup2(to_child[0],   STDIN_FILENO);
    dup2(from_child[1], STDOUT_FILENO);
    close(to_child[0]);
    close(to_child[1]);
    close(from_child[0]);
    close(from_child[1]);
    execlp("cat", "cat", NULL);
    _exit(1);
}

close(to_child[0]);
close(from_child[1]);

/* parent writes to to_child[1], reads from from_child[0] */
```

Be careful with bidirectional pipes — if both processes block waiting for the other to write first, you have a deadlock. In practice, the parent typically drives the protocol: write a request, read a response, repeat.

---

## PIPE_BUF and atomicity

`PIPE_BUF` is the minimum number of bytes for which a write to a pipe is guaranteed to be atomic. On Linux it is 4096 bytes. Writes of `PIPE_BUF` bytes or fewer from a single writer to a single pipe are either written completely or not at all — no interleaving with writes from other writers.

Writes larger than `PIPE_BUF` may be split and interleaved with writes from other writers. If multiple processes write to the same pipe, keep individual write sizes at or below `PIPE_BUF` to avoid corruption. This is directly relevant to your MQTT logging pipeline: if multiple device handlers write log lines to a shared pipe, keep each line under 4096 bytes.

---

## Named pipes — FIFOs

Anonymous pipes only work between related processes — processes that share a common ancestor that created the pipe. Named pipes, also called FIFOs, appear as files in the filesystem and can be opened by any process that has permission.

```c
#include <sys/stat.h>
#include <fcntl.h>

/* create the FIFO — typically done once, like mkdir */
mkfifo("/tmp/myfifo", 0644);
```

One process opens it for writing:

```c
int fd = open("/tmp/myfifo", O_WRONLY);
write(fd, data, len);
close(fd);
```

Another process opens it for reading:

```c
int fd = open("/tmp/myfifo", O_RDONLY);
ssize_t n = read(fd, buf, sizeof(buf));
close(fd);
```

`open` on a FIFO blocks until both ends are open — the reader blocks until a writer opens the other end, and vice versa. This synchronization is built in. To open non-blocking, use `O_NONBLOCK` — the open returns immediately but reads return `EAGAIN` when no data is available.

FIFOs are useful for connecting separate programs that are not related by fork — a sensor data producer writing to a FIFO, a monitor daemon reading from it, both started independently by an init system.

---

## Pipe capacity and flow control

The pipe buffer is finite. On Linux, the default capacity is 65536 bytes (64 KB) per pipe. You can query and set it with `fcntl`:

```c
int capacity = fcntl(fds[0], F_GETPIPE_SZ);
fcntl(fds[0], F_SETPIPE_SZ, 131072);   /* request 128 KB */
```

When the pipe is full, `write` blocks until the reader consumes enough data. This is natural backpressure — the writer slows down when the reader cannot keep up. If the writer has `O_NONBLOCK` set, `write` returns -1 with `errno == EAGAIN` instead of blocking.

When the read end is closed and you write to the write end, `SIGPIPE` is sent to the writer. As you learned on Day 13, ignore `SIGPIPE` and check for `errno == EPIPE` in the write return path.

---

## popen — convenience wrapper

`popen` opens a process and returns a `FILE *` connected to its stdin or stdout. It combines pipe creation, fork, exec, and dup2 into one call. It is convenient for simple cases and completely wrong for production code that needs error handling, timeout control, or bidirectional communication.

```c
FILE *f = popen("ls -la /tmp", "r");
if (f == NULL) { perror("popen"); return 1; }

char line[256];
while (fgets(line, sizeof(line), f) != NULL) {
    printf("%s", line);
}

int status = pclose(f);
```

`pclose` returns the exit status of the child in the same format as `waitpid`. Use `WIFEXITED` and `WEXITSTATUS` to inspect it.

Use `popen` for quick scripts and tools. For anything production-critical — daemons, protocol handlers, embedded firmware — build the pipe-fork-exec explicitly so you control every step.

---

## Building a pipeline in code

Implementing a two-stage pipeline — equivalent to `cmd1 | cmd2` — requires two children and one pipe:

```c
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <sys/wait.h>

int main(void) {
    int fds[2];
    if (pipe(fds) == -1) { perror("pipe"); return 1; }

    /* first child: runs cmd1, writes to pipe */
    pid_t pid1 = fork();
    if (pid1 == 0) {
        dup2(fds[1], STDOUT_FILENO);
        close(fds[0]);
        close(fds[1]);
        execlp("ls", "ls", "/tmp", NULL);
        _exit(1);
    }

    /* second child: runs cmd2, reads from pipe */
    pid_t pid2 = fork();
    if (pid2 == 0) {
        dup2(fds[0], STDIN_FILENO);
        close(fds[0]);
        close(fds[1]);
        execlp("sort", "sort", NULL);
        _exit(1);
    }

    /* parent closes both ends and waits */
    close(fds[0]);
    close(fds[1]);

    waitpid(pid1, NULL, 0);
    waitpid(pid2, NULL, 0);

    return 0;
}
```

The parent must close both ends of the pipe — if it keeps the write end open, `sort` will never see EOF and will block forever waiting for more input even after `ls` finishes.

---

## Practical exercise

Build a logging pipeline with three stages.

Stage one is a producer process: it forks a child that generates structured log lines — one per second, formatted as `<timestamp> <level> <message>\n` — and writes them to a pipe connected to stage two.

Stage two is a filter process: it reads log lines from the first pipe, passes only lines containing `ERROR` or `WARN`, and writes them to a second pipe connected to stage three.

Stage three is a consumer: it reads from the second pipe and writes to a log file opened with `O_WRONLY | O_CREAT | O_APPEND`.

The parent process sets up all pipes, forks all three children, installs a `SIGTERM` handler, and waits for all children via `SIGCHLD` with `WNOHANG`. When `SIGTERM` arrives, it sends `SIGTERM` to all children and waits for them to finish.

Generate at least ten log lines with a mix of `INFO`, `WARN`, and `ERROR` levels. Verify that only `WARN` and `ERROR` lines appear in the output file.

---

## What to carry forward

A pipe is a kernel byte stream between file descriptors. Create before fork, close unused ends after fork — always without exception. `dup2` redirects standard I/O to pipe ends before exec. Writes up to `PIPE_BUF` are atomic — keep individual writes below this for multi-writer pipes. Named pipes extend the mechanism to unrelated processes. The parent must close all pipe ends it does not use or EOF will never arrive. These mechanics are the foundation of shell pipelines, log processors, and every program that chains processes together.

Tomorrow: TCP sockets — extending the pipe model across a network.