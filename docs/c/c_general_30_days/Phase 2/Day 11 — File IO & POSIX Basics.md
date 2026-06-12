

You skipped Day 10 deliberately — you have been writing Makefiles since Day 1 and the concepts are already in your hands. Today you move into systems programming proper. File I/O is where C stops being a language exercise and starts being a tool for real work. You will use two distinct APIs today: the C standard library's buffered I/O and the POSIX low-level file descriptor interface. Understanding both, and knowing when to use each, is foundational to everything from daemon development to binary protocol parsing.

---

## Two APIs for the same thing

C gives you two ways to work with files. They coexist, serve different purposes, and are sometimes mixed in the same program.

The standard library API — `FILE *`, `fopen`, `fread`, `fwrite`, `fclose` — is portable across every platform that runs C. It buffers data internally: writes accumulate in memory and are flushed to the OS in large chunks, and reads pull ahead of what you asked for. This buffering makes it fast for sequential text and structured data access.

The POSIX API — file descriptors, `open`, `read`, `write`, `close` — is the raw interface to the operating system. It is not portable to Windows without a compatibility layer, but it is the foundation of everything on Linux and every Unix-like system. It gives you direct control over how the OS handles the file: you choose blocking versus non-blocking behavior, you control exactly when data is flushed to disk, and you can use file descriptors for things that are not files at all — sockets, pipes, terminals, and devices.

---

## The FILE * API

```c
#include <stdio.h>
#include <stdlib.h>
#include <errno.h>
#include <string.h>

int main(void) {
    FILE *f = fopen("data.bin", "wb");
    if (f == NULL) {
        fprintf(stderr, "fopen: %s\n", strerror(errno));
        return 1;
    }

    uint32_t values[4] = {0xDEADBEEF, 0xCAFEBABE, 0x12345678, 0xABCDEF01};
    size_t written = fwrite(values, sizeof(uint32_t), 4, f);
    if (written != 4) {
        fprintf(stderr, "fwrite: short write (%zu of 4)\n", written);
        fclose(f);
        return 1;
    }

    fclose(f);
    return 0;
}
```

`fopen` mode strings encode both the operation and the data interpretation. `"r"` reads text. `"rb"` reads binary. `"w"` writes text, creating or truncating. `"wb"` writes binary. `"a"` appends. `"r+"` reads and writes without truncating. Always use `"b"` for binary data — on Windows, text mode translates newline characters and corrupts binary content. On Linux it makes no difference, but the explicit `"b"` documents your intent and makes the code portable.

`fwrite(ptr, size, count, stream)` writes `count` elements of `size` bytes each. It returns the number of elements written. A return value less than `count` indicates a write error — check `ferror(f)` for details. Never assume a write succeeded because it did not crash.

`fread` is the mirror:

```c
FILE *f = fopen("data.bin", "rb");
if (f == NULL) { ... }

uint32_t values[4];
size_t n = fread(values, sizeof(uint32_t), 4, f);
if (n != 4) {
    if (feof(f)) {
        fprintf(stderr, "file too short: got %zu elements\n", n);
    } else {
        fprintf(stderr, "fread error: %s\n", strerror(errno));
    }
    fclose(f);
    return 1;
}
fclose(f);
```

`feof(f)` is true only after a read attempt has hit the end of file — it is not a predictor. The pattern of checking `feof` and `ferror` after a short read is the correct idiom.

---

## Buffering behavior

The standard library buffers data in three modes. Full buffering accumulates data until the buffer is full or you call `fflush`. Line buffering flushes on newlines — this is the default for stdout when writing to a terminal. No buffering writes every byte immediately — this is the default for stderr.

Regular files opened with `fopen` use full buffering by default with an 8 KB buffer. This is efficient for sequential access. For a daemon writing a log file, full buffering means log messages may sit in memory for seconds before appearing on disk after a crash. For predictable log behavior, call `fflush(f)` after writing important messages, or set the buffer behavior explicitly:

```c
setvbuf(f, NULL, _IOLBF, 0);   // line-buffered
setvbuf(f, NULL, _IONBF, 0);   // unbuffered — every write goes to the OS immediately
```

---

## File positioning

`fseek` and `ftell` let you jump to any position in a file. This is useful for reading a file header to find offsets to data sections, then seeking directly to them.

```c
fseek(f, 0, SEEK_END);
long size = ftell(f);
fseek(f, 0, SEEK_SET);
```

`SEEK_SET` positions from the beginning. `SEEK_CUR` positions relative to the current position. `SEEK_END` positions from the end. For large files on 64-bit systems, use `fseeko` and `ftello` which take and return `off_t` — a 64-bit type on 64-bit Linux.

---

## The POSIX file descriptor API

```c
#include <fcntl.h>
#include <unistd.h>
#include <sys/types.h>
#include <sys/stat.h>
#include <errno.h>
#include <string.h>
#include <stdio.h>
#include <stdint.h>

int main(void) {
    int fd = open("data.bin", O_WRONLY | O_CREAT | O_TRUNC, 0644);
    if (fd == -1) {
        fprintf(stderr, "open: %s\n", strerror(errno));
        return 1;
    }

    uint32_t value = 0xDEADBEEF;
    ssize_t n = write(fd, &value, sizeof(value));
    if (n == -1) {
        fprintf(stderr, "write: %s\n", strerror(errno));
        close(fd);
        return 1;
    }
    if ((size_t)n != sizeof(value)) {
        fprintf(stderr, "short write: %zd of %zu bytes\n", n, sizeof(value));
        close(fd);
        return 1;
    }

    close(fd);
    return 0;
}
```

`open` takes a path, flags, and optionally a mode. The flags are bitwise-OR'd together. `O_RDONLY`, `O_WRONLY`, `O_RDWR` set the access mode. `O_CREAT` creates the file if it does not exist — requires a mode argument. `O_TRUNC` truncates an existing file to zero length. `O_APPEND` makes every write go to the end of the file atomically. `O_NONBLOCK` makes the file descriptor non-blocking — covered fully on Day 16.

The mode `0644` is an octal permission mask: owner read/write, group read, others read. When `O_CREAT` is used, the actual permissions are modified by the process's umask.

`write` returns the number of bytes written, which may be less than requested. This is not an error — it is a partial write, common on sockets and pipes, less common on regular files. The correct response is to write the remaining bytes in a loop:

```c
ssize_t write_all(int fd, const void *buf, size_t len) {
    const uint8_t *p = buf;
    size_t remaining = len;

    while (remaining > 0) {
        ssize_t n = write(fd, p, remaining);
        if (n == -1) {
            if (errno == EINTR) continue;   // interrupted by signal — retry
            return -1;
        }
        p         += n;
        remaining -= n;
    }
    return (ssize_t)len;
}
```

`EINTR` means the call was interrupted by a signal before any bytes were transferred. The correct response is to retry immediately. You will encounter this constantly when writing signal-aware programs. Every blocking syscall should handle `EINTR` this way.

`read` follows the same pattern:

```c
ssize_t read_all(int fd, void *buf, size_t len) {
    uint8_t *p = buf;
    size_t remaining = len;

    while (remaining > 0) {
        ssize_t n = read(fd, p, remaining);
        if (n == -1) {
            if (errno == EINTR) continue;
            return -1;
        }
        if (n == 0) break;   // EOF
        p         += n;
        remaining -= n;
    }
    return (ssize_t)(len - remaining);
}
```

`read` returning 0 means end of file. On a socket it means the remote side closed the connection. On a pipe it means all write ends are closed. Always distinguish between 0 (EOF) and -1 (error).

---

## Struct reading and writing over file descriptors

Reading binary protocol data directly into a struct is a common embedded and systems pattern. You write the raw bytes of the struct to a file and read them back directly:

```c
#include <stdint.h>

typedef struct __attribute__((packed)) {
    uint8_t  magic;
    uint16_t length;
    uint32_t sequence;
    uint8_t  checksum;
} frame_header_t;

int write_header(int fd, const frame_header_t *hdr) {
    return write_all(fd, hdr, sizeof(frame_header_t)) == sizeof(frame_header_t) ? 0 : -1;
}

int read_header(int fd, frame_header_t *hdr) {
    ssize_t n = read_all(fd, hdr, sizeof(frame_header_t));
    if (n != (ssize_t)sizeof(frame_header_t)) return -1;
    return 0;
}
```

The `__attribute__((packed))` from Day 6 is critical here — without it the struct may have padding bytes and the binary layout will not match what you wrote or what the other side expects. Verify the size with a static assert:

```c
_Static_assert(sizeof(frame_header_t) == 8, "frame_header_t must be 8 bytes");
```

This compile-time check fails immediately if padding creeps in, rather than producing a silent protocol mismatch at runtime.

---

## Error checking every syscall

Every syscall can fail. Even `close` can fail — if the file descriptor was written with buffered writes that only flush on close, a write error may surface at `close` time. Ignoring `close` errors on output files can silently discard data.

```c
if (close(fd) == -1) {
    fprintf(stderr, "close: %s\n", strerror(errno));
    return 1;
}
```

The discipline: every syscall return value is checked. This is not paranoia — it is the difference between a program that handles adversarial conditions and one that silently produces wrong output.

---

## Getting file metadata

`stat` fills a structure with file metadata without opening the file:

```c
#include <sys/stat.h>

struct stat st;
if (stat("data.bin", &st) == -1) {
    fprintf(stderr, "stat: %s\n", strerror(errno));
    return 1;
}

printf("size:  %lld bytes\n", (long long)st.st_size);
printf("inode: %llu\n",       (unsigned long long)st.st_ino);
printf("mode:  %o\n",         st.st_mode);
```

`fstat(fd, &st)` does the same thing on an already-open file descriptor — useful when you have the fd but not the path, or when you want to avoid a race condition between stat and open.

---

## Mixing FILE * and file descriptors

You can convert between the two APIs. `fileno(f)` extracts the underlying file descriptor from a `FILE *`. `fdopen(fd, mode)` wraps a file descriptor in a `FILE *`. Mixing them requires care — the `FILE *` buffer may have data that has not yet been written to the fd, so you must `fflush` before using the fd directly.

```c
FILE *f = fopen("data.txt", "w");
int fd = fileno(f);
// write through f
fprintf(f, "line 1\n");
fflush(f);       // flush buffer before using fd directly
write(fd, "line 2\n", 7);
fclose(f);       // closes the underlying fd too
```

---

## Practical exercise

Write a program that implements a simple binary log file format. Define a log record struct with a 4-byte magic number, a 4-byte timestamp (use `time(NULL)` cast to `uint32_t`), a 1-byte severity level, a 1-byte message length, and a variable-length message field up to 255 bytes. Use `__attribute__((packed))` and verify the fixed header size with `_Static_assert`.

Write two programs: `log_write` takes a severity level and message as command-line arguments and appends a record to `app.log`. `log_read` opens `app.log` and prints every record, verifying the magic number on each one and reporting corruption if it does not match.

Use the POSIX API for `log_write` with `O_WRONLY | O_CREAT | O_APPEND` so multiple concurrent writers do not corrupt each other. Use `read_all` as defined above to read fixed-size headers, then read the variable-length message separately.

Test by running `log_write` several times with different messages, then running `log_read` to verify all records appear correctly. Then deliberately corrupt the file by writing garbage bytes into the middle and verify that `log_read` detects and reports the corruption rather than crashing or printing garbage.

---

## What to carry forward

Two APIs exist for files: `FILE *` for buffered portable access, file descriptors for direct OS-level control. Always check return values from both — short reads and writes are not errors, they require retry loops. Handle `EINTR` on every blocking syscall. Use `__attribute__((packed))` and `_Static_assert` when reading structs from binary streams. The discipline of treating every file operation as potentially failing is what makes the difference between programs that work in your test environment and programs that work in production.

Tomorrow: the process model — fork, exec, and how the OS creates and manages processes.