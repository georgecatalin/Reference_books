
C has no exceptions. There is no `try/catch`, no automatic unwinding, no runtime that catches failures and reports them cleanly. When something goes wrong, your code is responsible for detecting it, communicating it to the caller, and cleaning up whatever was partially constructed. Done badly, error handling in C is a maze of duplicated cleanup code and ignored return values. Done well, it is explicit, predictable, and auditable. Today you learn the patterns that professional C programmers use.

---

## The C error handling contract

Every function in C that can fail communicates failure through its return value. This is the universal contract. The caller checks the return value and decides what to do. Ignoring a return value is a deliberate choice with consequences — the compiler will warn you with `-Wunused-result` on functions marked with `__attribute__((warn_unused_result))`, and you should mark your own critical functions the same way.

The return value conventions used in practice:

An integer return where 0 means success and a negative value means failure. This is the POSIX convention and what the Linux kernel uses universally.

An integer return where a positive value is a valid result and -1 signals failure with the actual error in `errno`. This is what most standard library and syscall functions use.

A pointer return where a valid pointer means success and NULL means failure. This is what `malloc`, `fopen`, and most functions returning allocated objects use.

A boolean return where true means success. This is common in application-level code where the caller needs only pass/fail without a specific error code.

Choose one convention per project and apply it consistently. Mixing conventions — some functions return 0 for success, others return 1 for success — is how bugs get introduced when someone reads the return value without checking the convention.

---

## errno — the global error code

`errno` is a thread-local integer set by system calls and standard library functions when they fail. It is declared in `<errno.h>` along with symbolic constants for every error code. You read `errno` immediately after a failed call — the next function call may overwrite it.

```c
#include <stdio.h>
#include <errno.h>
#include <string.h>

int main(void) {
    FILE *f = fopen("/nonexistent/path/file.txt", "r");
    if (f == NULL) {
        fprintf(stderr, "fopen failed: %s\n", strerror(errno));
        return 1;
    }
    fclose(f);
    return 0;
}
```

`strerror(errno)` converts the error number to a human-readable string. `perror("context")` does the same but prepends a context string and writes to stderr — useful for quick diagnostics. For production logging, `strerror` gives you a string you can embed in a structured log message.

```c
perror("fopen");   // prints: "fopen: No such file or directory"
```

Important rules for errno: never check errno without first checking that the function actually failed. Many functions leave errno set from a previous failure even on success. Always check the function's primary return value first, then read errno only if that indicates failure.

```c
errno = 0;            // clear before the call if you need to distinguish "no error" from "error 0"
long result = strtol(str, &end, 10);
if (errno != 0) {
    // conversion failed — errno tells you why
}
```

---

## Propagating errors up the call stack

When a function encounters an error it cannot handle itself, it cleans up its own resources and returns an error indicator to its caller. The caller does the same. This continues until the error reaches a level that can handle it — usually by logging and aborting the operation, or by returning an error response to the user.

```c
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <errno.h>

static int read_config_file(const char *path, char *buf, size_t size) {
    FILE *f = fopen(path, "r");
    if (f == NULL) {
        fprintf(stderr, "cannot open config '%s': %s\n", path, strerror(errno));
        return -1;
    }

    size_t n = fread(buf, 1, size - 1, f);
    if (ferror(f)) {
        fprintf(stderr, "read error on '%s': %s\n", path, strerror(errno));
        fclose(f);
        return -1;
    }

    buf[n] = '\0';
    fclose(f);
    return 0;
}

static int parse_config(const char *path, int *port, char *host, size_t host_size) {
    char buf[1024];
    if (read_config_file(path, buf, sizeof(buf)) != 0) {
        return -1;   // propagate — caller already got a message from read_config_file
    }

    if (sscanf(buf, "%255s %d", host, port) != 2) {
        fprintf(stderr, "malformed config in '%s'\n", path);
        return -1;
    }

    return 0;
}

int main(void) {
    int  port;
    char host[256];

    if (parse_config("/etc/myapp/config", &port, host, sizeof(host)) != 0) {
        fprintf(stderr, "startup failed\n");
        return 1;
    }

    printf("connecting to %s:%d\n", host, port);
    return 0;
}
```

One log message per error level is the right discipline. `read_config_file` logs the specific low-level error. `parse_config` propagates the return value but does not log again — the message was already emitted. `main` logs a high-level failure message. Without this discipline every error produces three identical messages in your log, which makes logs unreadable in production.

---

## The goto cleanup pattern

When a function acquires multiple resources and must release all of them on any error path, the naive approach is deeply nested conditionals or duplicated cleanup code. Both are worse than the goto pattern.

```c
#include <stdio.h>
#include <stdlib.h>

int process_data(const char *input_path, const char *output_path) {
    int    result  = -1;
    FILE  *input   = NULL;
    FILE  *output  = NULL;
    char  *buffer  = NULL;

    input = fopen(input_path, "r");
    if (input == NULL) {
        fprintf(stderr, "cannot open input: %s\n", input_path);
        goto done;
    }

    output = fopen(output_path, "w");
    if (output == NULL) {
        fprintf(stderr, "cannot open output: %s\n", output_path);
        goto done;
    }

    buffer = malloc(65536);
    if (buffer == NULL) {
        fprintf(stderr, "allocation failed\n");
        goto done;
    }

    size_t n;
    while ((n = fread(buffer, 1, 65536, input)) > 0) {
        if (fwrite(buffer, 1, n, output) != n) {
            fprintf(stderr, "write error\n");
            goto done;
        }
    }

    if (ferror(input)) {
        fprintf(stderr, "read error\n");
        goto done;
    }

    result = 0;   // only set on full success

done:
    free(buffer);       // free(NULL) is safe
    if (output) fclose(output);
    if (input)  fclose(input);
    return result;
}
```

Every resource is initialized to NULL before any allocation. On any failure, `goto done` jumps to the cleanup block. The cleanup block releases everything — `free(NULL)` is a no-op, `fclose(NULL)` would crash so we guard with an `if`. `result` is only set to 0 if every operation succeeded. This pattern is used throughout the Linux kernel and is the accepted idiomatic way to handle multi-resource cleanup in C.

The goto jumps forward only — always to a label below the current line. Backward gotos create loops and are not part of this pattern. The concern about goto from structured programming applies to backward jumps that replace loops; forward-only cleanup gotos are unambiguous and widely considered best practice in C.

---

## Custom error codes

For a module or library, define your own error codes as an enum. This gives callers precise information about what failed and allows them to make decisions rather than just propagating failure.

```c
// device.h

typedef enum {
    DEVICE_OK           =  0,
    DEVICE_ERR_TIMEOUT  = -1,
    DEVICE_ERR_NODEV    = -2,
    DEVICE_ERR_IO       = -3,
    DEVICE_ERR_OVERFLOW = -4,
} device_err_t;

const char *device_strerror(device_err_t err);
device_err_t device_read(uint8_t id, uint8_t *buf, size_t len);
```

```c
// device.c

const char *device_strerror(device_err_t err) {
    switch (err) {
        case DEVICE_OK:           return "success";
        case DEVICE_ERR_TIMEOUT:  return "timeout";
        case DEVICE_ERR_NODEV:    return "device not found";
        case DEVICE_ERR_IO:       return "I/O error";
        case DEVICE_ERR_OVERFLOW: return "buffer overflow";
        default:                  return "unknown error";
    }
}
```

This mirrors the pattern of POSIX `errno` and `strerror` at the module level. Callers can switch on the error code to take specific recovery actions, and logging always has a human-readable description.

---

## Defensive assertions

`assert` from `<assert.h>` checks a condition and aborts the program with a diagnostic message if it is false. Use it to enforce invariants that must be true for the program to be correct — things that should never fail given correct inputs and correct code.

```c
#include <assert.h>

void process_packet(const uint8_t *buf, size_t len) {
    assert(buf != NULL);    // programmer error if this is NULL
    assert(len > 0);        // programmer error if this is zero
    assert(len <= 65535);   // programmer error if this exceeds protocol limit
    ...
}
```

The distinction between `assert` and runtime error checking is critical. `assert` is for catching programmer errors — violations of preconditions that should never occur if the code is correct. Runtime error checking is for handling external failures — files that do not exist, network connections that drop, users who provide invalid input.

Do not use `assert` to validate user input or external data. If the assertion can fire based on runtime conditions outside your control, it is the wrong tool.

Assertions are compiled out when `NDEBUG` is defined — typically in release builds with `-DNDEBUG`. This means asserts add zero overhead in production. It also means you cannot use `assert` for anything that must run in production — side effects inside an `assert` are silently dropped in release builds.

```c
assert(initialize_hardware() == 0);   // WRONG — call disappears in release build
int result = initialize_hardware();   // correct — call always runs
assert(result == 0);                  // invariant checked in debug only
```

---

## The warn_unused_result attribute

Mark functions whose return value must be checked. GCC and Clang emit a warning when a caller discards the return value of a marked function.

```c
__attribute__((warn_unused_result))
int write_record(int fd, const record_t *rec);
```

With `-Werror` in your build, this becomes a compile error if a caller ignores the return value. Apply it to any function where ignoring the return value is always a bug — write operations, initialization functions, and anything that can fail with consequences.

---

## Logging macros for debug and release builds

A structured logging macro gives you file name, line number, and log level in every message, and compiles to nothing in release builds at lower severity levels.

```c
#include <stdio.h>

typedef enum { LOG_ERROR, LOG_WARN, LOG_INFO, LOG_DEBUG } log_level_t;

#ifndef LOG_LEVEL
#define LOG_LEVEL LOG_INFO
#endif

#define LOG(level, fmt, ...)                                          \
    do {                                                              \
        if ((level) <= LOG_LEVEL) {                                   \
            fprintf(stderr, "[%s] %s:%d: " fmt "\n",                 \
                    log_level_str(level), __FILE__, __LINE__,         \
                    ##__VA_ARGS__);                                    \
        }                                                             \
    } while (0)

#define LOG_ERR(fmt, ...)   LOG(LOG_ERROR, fmt, ##__VA_ARGS__)
#define LOG_WARN(fmt, ...)  LOG(LOG_WARN,  fmt, ##__VA_ARGS__)
#define LOG_INFO(fmt, ...)  LOG(LOG_INFO,  fmt, ##__VA_ARGS__)
#define LOG_DBG(fmt, ...)   LOG(LOG_DEBUG, fmt, ##__VA_ARGS__)
```

The `do { ... } while (0)` wrapper is standard practice for multi-statement macros. It ensures the macro behaves as a single statement in all contexts — including inside an `if` without braces.

`__FILE__` and `__LINE__` are predefined macros expanded by the preprocessor to the current file name and line number. They give you precise location information in every log message without any runtime overhead.

Build with `-DLOG_LEVEL=3` for debug logging, `-DLOG_LEVEL=1` for production where only errors are emitted.

---

## Practical exercise

Write a small program that simulates reading a configuration file, initializing a device, and reading sensor data. Structure it as three functions: `load_config`, `init_device`, and `read_sensor`. Each function should have at least two error paths.

Apply the goto cleanup pattern in any function that acquires more than one resource. Define a module-specific error enum with at least four values and a corresponding `strerror` function. Use the logging macros above to emit messages at appropriate levels — debug for internal state, info for major transitions, error for failures.

Then compile with `-DLOG_LEVEL=0` so only errors appear, and with `-DLOG_LEVEL=3` so everything appears. Observe how the same binary produces different verbosity from a compile-time flag, with no runtime cost for suppressed messages.

Finally, add `__attribute__((warn_unused_result))` to your `read_sensor` function, then call it somewhere without checking the return value. Confirm the compiler emits a warning and that `-Werror` turns it into a build failure.

---

## What to carry forward

C error handling is explicit by design. Return values are the contract. Check every one. Propagate errors upward without duplicating log messages. Use goto for multi-resource cleanup — it is the correct tool for the job. Distinguish between programmer errors caught by assert and runtime failures caught by return value checking. Mark critical functions with `warn_unused_result` so the compiler enforces the contract. A codebase where every error is handled and every failure is logged is a codebase you can operate and debug in production.

Tomorrow: Make and project structure — building multi-file projects correctly and efficiently.