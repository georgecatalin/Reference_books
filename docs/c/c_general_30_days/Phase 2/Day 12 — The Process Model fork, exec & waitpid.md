
Yesterday you worked with files as the fundamental I/O abstraction. Today you work with processes — the fundamental execution abstraction. Understanding how the OS creates and manages processes is essential for writing daemons, build tools, test harnesses, shell-like programs, and any C program that needs to run other programs or isolate work into separate execution contexts. Everything in this space flows from three syscalls: `fork`, `exec`, and `waitpid`.

---

## What a process is

A process is an instance of a running program. It has its own address space — a private view of memory that no other process can touch without explicit sharing. It has its own file descriptor table — a list of open files, sockets, and pipes. It has a process ID (PID), a parent PID, a working directory, environment variables, and a set of signal handlers. The kernel schedules processes for CPU time independently.

When your program calls `fork`, the kernel creates a new process that is an almost exact copy of the calling process. When it calls `exec`, the kernel replaces the current process image with a new program. When a process finishes, its parent calls `waitpid` to collect its exit status. These three operations are the entire process lifecycle in Unix.

---

## fork — creating a child process

`fork` creates a child process by duplicating the calling process. After `fork` returns, two processes are running the same code. The only way to tell them apart is the return value: `fork` returns 0 in the child and the child's PID in the parent. On failure it returns -1.

```c
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <sys/types.h>
#include <sys/wait.h>

int main(void) {
    pid_t pid = fork();

    if (pid == -1) {
        perror("fork");
        return 1;
    }

    if (pid == 0) {
        /* child process */
        printf("child: my PID is %d, parent PID is %d\n",
               getpid(), getppid());
        exit(0);
    }

    /* parent process */
    printf("parent: my PID is %d, child PID is %d\n",
           getpid(), pid);

    int status;
    if (waitpid(pid, &status, 0) == -1) {
        perror("waitpid");
        return 1;
    }

    if (WIFEXITED(status)) {
        printf("child exited with status %d\n", WEXITSTATUS(status));
    }

    return 0;
}
```

The child inherits a copy of the parent's address space at the moment of fork. This is implemented with copy-on-write — the kernel initially shares physical memory pages between parent and child. When either process writes to a page, the kernel creates a private copy for that process. This makes fork fast even for large processes, because most pages are typically never written after fork.

The child also inherits the parent's file descriptors. Every open file, socket, and pipe in the parent is also open in the child after fork, pointing to the same underlying kernel file descriptions. This inheritance is the mechanism behind pipes — you create a pipe before forking, and both parent and child have access to both ends.

---

## What is not shared after fork

Knowing what the child does not inherit is as important as knowing what it does.

Memory writes after fork are private. If the parent modifies a variable after fork, the child does not see the change, and vice versa. They have diverged from the moment fork returns.

Threads are not inherited. If the parent is multithreaded, only the thread that called fork exists in the child. The other threads simply do not exist in the child's address space. Any mutexes those threads held are now permanently locked in the child. This makes fork in multithreaded programs extremely dangerous unless immediately followed by exec. The safe rule: in a multithreaded program, only call fork if you intend to immediately exec.

Signal dispositions are inherited but pending signals are not. Timers set with `setitimer` are not inherited. Memory locks set with `mlock` are inherited.

---

## exec — replacing the process image

The exec family of functions replaces the current process's code, data, stack, and heap with a new program loaded from disk. The PID stays the same. File descriptors marked without `O_CLOEXEC` stay open. Signal dispositions reset to defaults.

```c
#include <unistd.h>

execl("/bin/ls", "ls", "-l", "/tmp", NULL);
/* if execl returns, it failed */
perror("execl");
exit(1);
```

`execl` takes a path and a NULL-terminated argument list. The first argument after the path is `argv[0]` — conventionally the program name. The list must end with NULL.

The exec variants differ in how they receive arguments and how they find the program:

`execl` — path, argument list ending in NULL. `execv` — path, argument array ending in NULL pointer. `execlp` / `execvp` — filename, searches PATH like the shell. `execle` / `execve` — explicit environment array as last argument.

In practice, `execvp` is the most useful for shell-like programs because it searches PATH and takes arguments as an array, which is easy to construct programmatically:

```c
char *args[] = {"gcc", "-Wall", "-o", "program", "program.c", NULL};
execvp("gcc", args);
perror("execvp");
exit(1);
```

The fork-exec combination is the Unix way to run a program:

```c
pid_t pid = fork();
if (pid == -1) { perror("fork"); exit(1); }

if (pid == 0) {
    /* child: replace image with new program */
    char *args[] = {"ls", "-la", NULL};
    execvp("ls", args);
    perror("execvp");   /* only reached if exec fails */
    exit(127);          /* 127 is the conventional "command not found" exit code */
}

/* parent: wait for child */
int status;
waitpid(pid, &status, 0);
```

---

## waitpid — reaping children

When a child process exits, it does not immediately disappear. It becomes a zombie — its PID and exit status are retained in the kernel's process table until the parent collects them with `wait` or `waitpid`. A process that exits without being waited on is a zombie. Zombies consume a process table entry. If your program forks many children and never waits for them, you eventually exhaust the process table.

`waitpid(pid, &status, options)` waits for a specific child. `pid = -1` waits for any child. `options = 0` blocks until the child exits. `options = WNOHANG` returns immediately if no child has exited yet — useful for polling.

```c
int status;
pid_t result = waitpid(pid, &status, 0);
if (result == -1) {
    perror("waitpid");
} else {
    if (WIFEXITED(status)) {
        printf("exited normally, status %d\n", WEXITSTATUS(status));
    } else if (WIFSIGNALED(status)) {
        printf("killed by signal %d\n", WTERMSIG(status));
    } else if (WIFSTOPPED(status)) {
        printf("stopped by signal %d\n", WSTOPSIG(status));
    }
}
```

The macros `WIFEXITED`, `WIFSIGNALED`, and `WIFSTOPPED` extract the reason for the state change from the status integer. Always use these macros — do not inspect the status integer directly.

---

## Zombie processes and SIGCHLD

In a long-running daemon that forks workers, you cannot call `waitpid` in a blocking loop — that would freeze the daemon. The solution is to handle SIGCHLD, the signal the kernel sends to the parent when a child changes state.

```c
#include <signal.h>
#include <sys/wait.h>

static void sigchld_handler(int sig) {
    (void)sig;
    /* reap all available children without blocking */
    while (waitpid(-1, NULL, WNOHANG) > 0)
        ;
}

int main(void) {
    struct sigaction sa = {0};
    sa.sa_handler = sigchld_handler;
    sa.sa_flags   = SA_RESTART;   /* restart interrupted syscalls */
    sigemptyset(&sa.sa_mask);
    sigaction(SIGCHLD, &sa, NULL);

    /* fork children as needed — the handler reaps them asynchronously */
    ...
}
```

`WNOHANG` in the loop means waitpid returns 0 when no more children are immediately available, stopping the loop. The loop is needed because multiple children may exit between signal deliveries — signals are not queued, so one SIGCHLD delivery may correspond to multiple children exiting.

---

## File descriptor inheritance and O_CLOEXEC

After fork-exec, file descriptors the parent had open remain open in the child unless explicitly closed or marked with `O_CLOEXEC`. This is a security concern — if a parent has a socket or sensitive file open and execs an untrusted program, that program inherits those file descriptors.

`O_CLOEXEC` marks a file descriptor to be automatically closed when any exec call succeeds:

```c
int fd = open("data.bin", O_RDONLY | O_CLOEXEC);
```

For existing file descriptors, set the flag with `fcntl`:

```c
int flags = fcntl(fd, F_GETFD);
fcntl(fd, F_SETFD, flags | FD_CLOEXEC);
```

The rule in modern code: always open file descriptors with `O_CLOEXEC` unless you specifically need them inherited across exec.

---

## The exit status contract

`exit(status)` terminates the process and makes `status & 0xFF` available to the parent via `waitpid`. By convention: 0 means success, 1 means general error, 2 means misuse (wrong arguments), 127 means command not found. Programs that are killed by a signal do not have an exit status — `WIFSIGNALED` is true and `WTERMSIG` gives the signal number.

`exit` runs `atexit` handlers and flushes stdio buffers. `_exit` terminates immediately without any cleanup — use this in the child after fork if you are not going to exec, to avoid flushing the parent's stdio buffers from the child.

```c
pid_t pid = fork();
if (pid == 0) {
    /* do minimal work */
    _exit(0);   /* not exit() — avoid double-flushing parent's stdio */
}
```

---

## A practical shell-like command runner

Putting fork, exec, and waitpid together into a function you can reuse:

```c
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <sys/wait.h>
#include <errno.h>
#include <string.h>

int run_command(char *const argv[]) {
    pid_t pid = fork();
    if (pid == -1) {
        fprintf(stderr, "fork: %s\n", strerror(errno));
        return -1;
    }

    if (pid == 0) {
        execvp(argv[0], argv);
        fprintf(stderr, "exec '%s': %s\n", argv[0], strerror(errno));
        _exit(127);
    }

    int status;
    if (waitpid(pid, &status, 0) == -1) {
        fprintf(stderr, "waitpid: %s\n", strerror(errno));
        return -1;
    }

    if (WIFEXITED(status))   return WEXITSTATUS(status);
    if (WIFSIGNALED(status)) return 128 + WTERMSIG(status);
    return -1;
}

int main(void) {
    char *cmd[] = {"ls", "-la", "/tmp", NULL};
    int ret = run_command(cmd);
    printf("exit code: %d\n", ret);
    return ret == 0 ? 0 : 1;
}
```

This pattern — fork, exec in child, waitpid in parent — is the foundation of every shell, build system, test runner, and process supervisor written in C.

---

## Practical exercise

Write a minimal process supervisor. It should maintain a list of up to eight commands to run, each specified as a NULL-terminated argv array. The supervisor forks a child for each command, runs them all concurrently, and waits for all of them to finish using a SIGCHLD handler with `WNOHANG`. When each child exits, log its PID and exit status. When all children have exited, the supervisor exits.

Then extend it: if any child exits with a non-zero status, restart it up to three times before giving up. Track per-command restart counts. This is the core logic of a production process supervisor like runit or s6.

Compile with `-Wall -Wextra -Werror` and verify with Valgrind that there are no leaks. Test with a command that succeeds, one that fails with exit code 1, and one that does not exist so exec fails with errno ENOENT.

---

## What to carry forward

A process is a private address space, a file descriptor table, and an execution context. Fork duplicates the process. Exec replaces it. Waitpid reaps it. Every forked child must be waited on or it becomes a zombie. Use `O_CLOEXEC` on every file descriptor you do not intend to inherit across exec. In multithreaded programs, only fork if you immediately exec. These mechanics are the foundation of daemons, shells, and every program that manages other programs.

Tomorrow: signals — asynchronous notifications between processes and the kernel.