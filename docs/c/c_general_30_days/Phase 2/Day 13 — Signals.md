

Yesterday you learned how processes are created and reaped. Today you learn how they communicate asynchronously through signals. Signals are the kernel's mechanism for notifying a process that something happened — a timer fired, a child exited, the user pressed Ctrl+C, a write went to a broken pipe, or another process explicitly sent a notification. Signals interrupt whatever the process is doing and invoke a handler function. Done correctly, signal handling is clean and predictable. Done incorrectly, it produces some of the most difficult bugs in systems programming.

---

## What a signal is

A signal is a small integer sent to a process by the kernel or by another process. When a process receives a signal, one of three things happens depending on the signal's current disposition: the default action executes (which may terminate the process, stop it, or ignore the signal), a registered signal handler function runs, or the signal is ignored.

Signals are asynchronous. They can arrive at any point during execution — between any two instructions, including inside a library function, in the middle of a system call, or while another signal handler is running. This asynchrony is the source of most signal-related bugs.

Common signals you will encounter constantly:

`SIGINT` — sent when the user presses Ctrl+C. Default action: terminate. Used for graceful shutdown in interactive programs.

`SIGTERM` — the standard termination request. Default action: terminate. Sent by `kill` without a signal number. Programs should catch this for graceful shutdown.

`SIGCHLD` — sent to a parent when a child changes state: exits, is killed, or is stopped. Default action: ignore. You saw this on Day 12.

`SIGPIPE` — sent when a write is attempted on a pipe or socket with no readers. Default action: terminate. Commonly ignored in servers.

`SIGHUP` — historically sent when the terminal disconnects. Conventionally used to tell daemons to reload their configuration.

`SIGALRM` — sent when a timer set with `alarm()` expires. Default action: terminate.

`SIGSEGV` — sent when a process accesses memory it is not allowed to access. Default action: terminate with a core dump.

`SIGKILL` and `SIGSTOP` — cannot be caught, blocked, or ignored. `SIGKILL` terminates immediately. `SIGSTOP` suspends execution.

---

## sigaction — the correct way to install handlers

`signal()` is the original signal API. It is unreliable — its behavior on re-entry and across platforms is inconsistent. Never use it in new code. Use `sigaction` instead. It is explicit, portable, and gives you full control over handler behavior.

```c
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

static volatile sig_atomic_t g_running = 1;

static void handle_sigterm(int sig) {
    (void)sig;
    g_running = 0;   /* set flag — do not do real work here */
}

int main(void) {
    struct sigaction sa = {0};
    sa.sa_handler = handle_sigterm;
    sa.sa_flags   = SA_RESTART;
    sigemptyset(&sa.sa_mask);
    sigaddset(&sa.sa_mask, SIGINT);   /* block SIGINT while handler runs */

    if (sigaction(SIGTERM, &sa, NULL) == -1) {
        perror("sigaction");
        return 1;
    }

    /* same handler for SIGINT */
    if (sigaction(SIGINT, &sa, NULL) == -1) {
        perror("sigaction");
        return 1;
    }

    printf("running, PID %d — send SIGTERM or press Ctrl+C to stop\n", getpid());

    while (g_running) {
        sleep(1);
        printf("tick\n");
    }

    printf("shutting down cleanly\n");
    return 0;
}
```

Breaking down the `sigaction` setup:

`sa.sa_handler` is the function to call when the signal arrives. It takes the signal number as its only argument.

`sa.sa_flags` controls behavior. `SA_RESTART` automatically restarts system calls that were interrupted by the signal — without it, `read`, `write`, `accept`, and other blocking calls return -1 with `errno == EINTR` when interrupted. In most programs you want `SA_RESTART`. In programs where you use signals to break out of blocking calls deliberately, you omit it.

`sa.sa_mask` is a set of additional signals to block while the handler runs. The signal being handled is automatically blocked during its own handler. Here we also block `SIGINT` while `SIGTERM` is being handled to prevent re-entrant handler invocations.

`sigemptyset` clears the mask. `sigaddset` adds a signal to it. `sigfillset` sets all signals.

---

## Signal-safe functions — the restricted list

A signal handler can be invoked at any point during your program's execution. If your program is in the middle of `malloc` when the signal arrives, and your handler calls `malloc`, the heap's internal state is corrupted. If your program is in the middle of `printf` when the signal arrives, and your handler calls `printf`, the output stream's buffer is corrupted. Any function that uses global or static state, acquires locks, or is not re-entrant is unsafe to call from a signal handler.

The POSIX standard defines a list of async-signal-safe functions — functions that can safely be called from a signal handler. The important ones you can use:

`_exit`, `read`, `write`, `open`, `close`, `send`, `recv`, `sigaction`, `sigemptyset`, `sigaddset`, `sigprocmask`, `kill`, `getpid`, `waitpid` with `WNOHANG`.

Functions you cannot call from a signal handler:

`printf`, `fprintf`, `sprintf` — use write() directly if you must output from a handler. `malloc`, `free`, `realloc`. `fopen`, `fclose`, `fread`, `fwrite`. `exit` — use `_exit`. Any function that is not on the POSIX async-signal-safe list.

The practical pattern is what you saw above: do nothing in the handler except set a `volatile sig_atomic_t` flag. The main program loop checks the flag and does the real work.

```c
static volatile sig_atomic_t g_reload_config = 0;
static volatile sig_atomic_t g_shutdown      = 0;

static void handle_sighup(int sig)  { (void)sig; g_reload_config = 1; }
static void handle_sigterm(int sig) { (void)sig; g_shutdown = 1; }

/* in main loop */
while (!g_shutdown) {
    if (g_reload_config) {
        g_reload_config = 0;
        load_config();
    }
    do_work();
}
```

`volatile sig_atomic_t` is the correct type for variables shared between a signal handler and the main program. `volatile` prevents the compiler from caching the value in a register and missing updates from the handler. `sig_atomic_t` is an integer type guaranteed to be read and written atomically on the target platform — no torn reads or writes.

---

## Blocking signals — sigprocmask

Sometimes you need a critical section where signals must not be delivered — for example, while updating a data structure that the signal handler also reads. `sigprocmask` adds signals to the process's signal mask, deferring their delivery until you unmask them.

```c
#include <signal.h>

sigset_t block_set, old_set;
sigemptyset(&block_set);
sigaddset(&block_set, SIGTERM);
sigaddset(&block_set, SIGINT);

/* block SIGTERM and SIGINT */
sigprocmask(SIG_BLOCK, &block_set, &old_set);

/* critical section — signals deferred, not lost */
update_shared_state();

/* restore previous mask — deferred signals may now deliver */
sigprocmask(SIG_SETMASK, &old_set, NULL);
```

Blocked signals are not lost — they are pending and deliver as soon as they are unblocked. This is different from ignoring a signal, which discards it permanently.

---

## SIGPIPE — the server killer

`SIGPIPE` is sent when a write is attempted to a pipe or socket whose read end has been closed. The default action is to terminate the process. In a server that writes to client connections, if any client disconnects, the next write to that client's socket sends `SIGPIPE` and kills the entire server.

The solution is to ignore `SIGPIPE` and instead check write return values for errors:

```c
struct sigaction sa = {0};
sa.sa_handler = SIG_IGN;
sigemptyset(&sa.sa_mask);
sigaction(SIGPIPE, &sa, NULL);
```

With `SIGPIPE` ignored, `write` and `send` return -1 with `errno == EPIPE` when the pipe is broken. You handle the error normally in the return value path. Every server that writes to sockets should ignore `SIGPIPE`.

---

## Sending signals with kill

`kill(pid, sig)` sends a signal to a process or group. Despite the name it sends any signal, not just SIGKILL.

```c
#include <signal.h>
#include <sys/types.h>

kill(child_pid, SIGTERM);    /* ask child to shut down gracefully */
kill(child_pid, SIGKILL);    /* force immediate termination */
kill(0, SIGTERM);            /* send to every process in the process group */
kill(-pgid, SIGTERM);        /* send to every process in group pgid */
```

`raise(sig)` sends a signal to the calling process itself — equivalent to `kill(getpid(), sig)`.

---

## Graceful shutdown pattern for daemons

This is the complete pattern for a daemon that handles shutdown cleanly:

```c
#include <stdio.h>
#include <stdlib.h>
#include <signal.h>
#include <unistd.h>
#include <string.h>
#include <errno.h>

static volatile sig_atomic_t g_shutdown = 0;

static void handle_shutdown(int sig) {
    (void)sig;
    g_shutdown = 1;
}

static void install_handlers(void) {
    struct sigaction sa = {0};
    sa.sa_handler = handle_shutdown;
    sa.sa_flags   = SA_RESTART;
    sigemptyset(&sa.sa_mask);
    sigaddset(&sa.sa_mask, SIGTERM);
    sigaddset(&sa.sa_mask, SIGINT);

    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    /* ignore SIGPIPE — handle broken pipes via return values */
    struct sigaction sa_ign = {0};
    sa_ign.sa_handler = SIG_IGN;
    sigemptyset(&sa_ign.sa_mask);
    sigaction(SIGPIPE, &sa_ign, NULL);
}

int main(void) {
    install_handlers();

    /* initialize resources */

    while (!g_shutdown) {
        /* do work */
        sleep(1);
    }

    /* cleanup resources */
    printf("shutdown complete\n");
    return 0;
}
```

This structure — install handlers at startup, loop on a flag, clean up on exit — is the skeleton of every daemon in this curriculum. You will use it verbatim from Day 15 onward.

---

## Self-pipe trick — signals in event loops

When you use `select` or `poll` to wait for I/O events (Day 16), you cannot simultaneously wait for a signal — the signal may arrive between the signal check and the blocking call, and you sleep forever missing it. The self-pipe trick solves this.

Create a pipe before entering the event loop. In the signal handler, write one byte to the write end. In the event loop, add the read end to the select/poll watch set. When a signal arrives, the write wakes up the event loop, which reads the byte and checks the flag.

```c
static int g_signal_pipe[2];

static void handle_signal(int sig) {
    (void)sig;
    /* write is async-signal-safe */
    write(g_signal_pipe[1], "\x00", 1);
}

/* setup */
pipe(g_signal_pipe);

/* in event loop — add g_signal_pipe[0] to select/poll read set */
/* when it becomes readable, read and drain the byte, then check flags */
```

This is the correct way to integrate signals with an event loop. You will implement it fully on Day 16.

---

## Practical exercise

Write a daemon skeleton that:

Installs handlers for `SIGTERM`, `SIGINT`, and `SIGHUP` using `sigaction` with `SA_RESTART`. `SIGTERM` and `SIGINT` set a shutdown flag. `SIGHUP` sets a reload flag. `SIGPIPE` is ignored.

Runs a main loop that sleeps for one second per iteration, prints a tick counter, checks the reload flag and prints a reload message when set, and exits when the shutdown flag is set.

Then verify the behavior: run the program, send it `SIGTERM` with `kill <PID>` from another terminal and confirm it shuts down cleanly. Send `SIGHUP` and confirm the reload message appears. Send `SIGKILL` and confirm it cannot be caught.

Then extend it with the self-pipe trick. Replace `sleep(1)` with a `select` call that waits either one second or until the signal pipe is readable. Verify that signals now wake the event loop immediately rather than waiting for the current sleep to expire.

---

## What to carry forward

Signals are asynchronous. Signal handlers run at unpredictable points. Do nothing in a handler except set a `volatile sig_atomic_t` flag. Use `sigaction` always — never `signal()`. Ignore `SIGPIPE` in any program that writes to sockets or pipes. Use `SA_RESTART` to avoid EINTR noise. Block signals during critical sections with `sigprocmask`. Use the self-pipe trick to integrate signals with event loops. These patterns appear in every daemon, server, and long-running process you will ever write in C.

Tomorrow: pipes and IPC — connecting processes through data streams.