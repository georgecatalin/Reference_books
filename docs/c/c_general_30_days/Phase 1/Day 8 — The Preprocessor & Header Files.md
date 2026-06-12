
The preprocessor runs before the compiler sees your code. It is a text transformation engine — it includes files, expands macros, and conditionally includes or excludes blocks of code based on compile-time conditions. Understanding it precisely is what separates C programmers who fight mysterious compilation errors from those who diagnose them in thirty seconds.

---

## What the preprocessor actually does

The preprocessor operates purely on text. It knows nothing about C syntax, types, or scoping rules. It finds lines beginning with `#`, processes them as directives, and produces a transformed text file that the compiler then parses. Every `#include`, every `#define`, every `#ifdef` is resolved before a single C token is parsed.

You already saw this on Day 1 when you ran `gcc -E` and observed the output. Today you understand the mechanics in depth.

---

## #include — file insertion

`#include` replaces the directive with the entire contents of the named file, recursively. If that file includes other files, those are expanded too. The output of preprocessing can be tens of thousands of lines even for a simple program.

```c
#include <stdio.h>      // angle brackets: search system include paths
#include "myheader.h"   // quotes: search current directory first, then system paths
```

The practical difference between angle brackets and quotes: use angle brackets for system and third-party library headers. Use quotes for your own project headers. This is convention, not a hard rule, but it communicates intent clearly.

What gets put in a header file and what goes in a `.c` file is one of the most important design decisions in a C project. The rule is precise: headers contain declarations, `.c` files contain definitions.

A declaration tells the compiler something exists and what its type is. A definition allocates storage or provides the implementation. If a definition appears in a header and that header is included in multiple `.c` files, the linker sees multiple definitions of the same symbol and errors out.

```c
// correct header content
int add(int a, int b);              // function declaration — no body
extern int global_counter;          // variable declaration — extern means defined elsewhere
typedef struct { int x; } point_t;  // type definition — safe in headers, creates no storage
#define MAX_SIZE 256                 // macro — preprocessor, creates no storage

// WRONG in a header — these are definitions
int add(int a, int b) { return a+b; }  // function definition — linker error if included twice
int global_counter = 0;                // variable definition — linker error if included twice
```

---

## Include guards — preventing double inclusion

When header A includes header B, and header C includes both A and B, header B gets included twice. Without protection, every type and declaration in B appears twice in the translation unit, causing redefinition errors.

The traditional solution is include guards:

```c
#ifndef SENSOR_H
#define SENSOR_H

typedef struct {
    uint32_t timestamp;
    float    value;
    uint8_t  status;
} sensor_reading_t;

int read_sensor(sensor_reading_t *out);

#endif /* SENSOR_H */
```

The first time this file is included, `SENSOR_H` is not defined, so the `#ifndef` is true, the contents are processed, and `SENSOR_H` is defined. The second time this file is included, `SENSOR_H` is already defined, so the `#ifndef` is false, and everything between it and `#endif` is skipped.

The guard symbol must be unique across your entire project. Convention: use the filename converted to uppercase with dots and slashes replaced by underscores. `include/sensor.h` becomes `SENSOR_H` or `INCLUDE_SENSOR_H`.

`#pragma once` is a non-standard but universally supported alternative that achieves the same effect more concisely:

```c
#pragma once

typedef struct { ... } sensor_reading_t;
int read_sensor(sensor_reading_t *out);
```

`#pragma once` is supported by GCC, Clang, and MSVC. It is simpler and eliminates the risk of a guard name collision. The reason to still know include guards: they are what you will see in the Linux kernel, in POSIX headers, and in any portable codebase that targets compilers without `#pragma once` support. Use `#pragma once` in your own projects, know both forms.

---

## #define macros — power and danger

`#define` creates a textual substitution. Every occurrence of the defined name in subsequent code is replaced with the substitution text before the compiler sees it.

Object-like macros define constants:

```c
#define MAX_CLIENTS     64
#define BUFFER_SIZE     4096
#define PROTOCOL_MAGIC  0xDEADBEEF
```

These are visible across the entire translation unit after the definition and carry no type information — the preprocessor simply substitutes the text. For typed constants, prefer `const` variables or `enum`:

```c
static const uint32_t MAX_CLIENTS  = 64;
static const uint32_t BUFFER_SIZE  = 4096;
enum { PROTOCOL_MAGIC = 0xDEADBEEF };
```

Typed constants are visible to the debugger, participate in type checking, and respect scope. Macros do none of these things. Use macros for constants only when you genuinely need preprocessor behavior — conditional compilation, token pasting, or stringification.

Function-like macros take arguments and substitute them into the expansion:

```c
#define SQUARE(x)   ((x) * (x))
#define MAX(a, b)   ((a) > (b) ? (a) : (b))
#define MIN(a, b)   ((a) < (b) ? (a) : (b))
```

The parentheses around every argument and around the entire expansion are not optional. Without them:

```c
#define BAD_SQUARE(x)  x * x
BAD_SQUARE(1 + 2)   // expands to 1 + 2 * 1 + 2 = 5, not 9
```

Even with correct parenthesization, function-like macros have a fundamental problem: arguments are evaluated at every use site in the expansion. A macro with side effects in the argument is a bug:

```c
int i = 3;
int result = SQUARE(i++);
// expands to ((i++) * (i++)) — i incremented twice, undefined behavior
```

Use `static inline` functions instead of function-like macros whenever type safety and correct argument evaluation matter — which is almost always.

---

## Macros that are legitimate

Some uses of macros cannot be replaced by functions and are genuinely appropriate.

Conditional compilation for platform differences and debug builds:

```c
#ifdef DEBUG
    #define LOG(fmt, ...) fprintf(stderr, "[DEBUG] " fmt "\n", ##__VA_ARGS__)
#else
    #define LOG(fmt, ...)   // expands to nothing in release builds
#endif
```

The `##__VA_ARGS__` handles the case where no variadic arguments are passed — it suppresses the trailing comma. This is a GCC/Clang extension; in strict C99 you need at least one variadic argument.

Stringification — converting a token to a string literal:

```c
#define STRINGIFY(x)  #x
#define TOSTRING(x)   STRINGIFY(x)

printf("version: %s\n", TOSTRING(VERSION));   // if -DVERSION=2 passed to compiler
```

Token pasting — joining two tokens into one:

```c
#define REG(n)   REG_ ## n

REG(STATUS)   // expands to REG_STATUS
REG(CONTROL)  // expands to REG_CONTROL
```

This is used in hardware abstraction layers where register names are generated programmatically.

Compile-time assertions:

```c
#define STATIC_ASSERT(cond, msg)  typedef char static_assert_##msg[(cond) ? 1 : -1]

STATIC_ASSERT(sizeof(uint32_t) == 4, uint32_must_be_4_bytes);
```

C11 has `_Static_assert` built in, which is cleaner. Use that in C11 and later code.

---

## Conditional compilation

`#if`, `#ifdef`, `#ifndef`, `#elif`, `#else`, `#endif` conditionally include or exclude blocks of code at preprocessing time. The condition is evaluated with integer constant expressions and `defined()` tests.

```c
#if defined(__linux__)
    #include <sys/epoll.h>
#elif defined(__APPLE__)
    #include <sys/event.h>
#else
    #error "unsupported platform"
#endif
```

`#error` stops compilation with a message. Use it to catch impossible configurations early.

Feature flags passed on the command line with `-D`:

```c
// compile with: gcc -DENABLE_LOGGING -o program program.c
#ifdef ENABLE_LOGGING
    log_event("started");
#endif
```

This lets you build multiple configurations from the same source without editing code — a debug build, a release build, a build with verbose tracing enabled. Your Makefile can define which flags apply to each target.

---

## Organizing a real header file

A well-structured header for a module follows a consistent layout:

```c
#pragma once

#include <stdint.h>     // system includes first
#include <stdbool.h>

#include "config.h"     // project-wide configuration
#include "types.h"      // shared type definitions

/* ---- public types ---- */

typedef struct {
    uint32_t id;
    float    value;
    uint8_t  flags;
} device_reading_t;

/* ---- public constants ---- */

#define MAX_DEVICES     16
#define READING_OK       0
#define READING_ERROR   -1

/* ---- public interface ---- */

int  device_init(uint8_t device_id);
int  device_read(uint8_t device_id, device_reading_t *out);
void device_shutdown(uint8_t device_id);
```

The corresponding `.c` file includes its own header as the first include — this verifies that the header is self-contained and compiles cleanly without relying on includes from other translation units:

```c
#include "device.h"    // own header first — verifies self-containment

#include <stdlib.h>    // other system includes
#include <string.h>

#include "hardware.h"  // other project includes

/* private types and constants not visible outside this file */
static const uint32_t TIMEOUT_MS = 100;

/* function definitions */
int device_init(uint8_t device_id) {
    ...
}
```

The `static` keyword on a file-scope variable or function gives it internal linkage — it is invisible to other translation units. Everything in a `.c` file that is not part of the public interface should be `static`. This prevents symbol name collisions across the project and documents what is internal.

---

## Static inline in headers

Functions defined in headers must be either `static inline` or exist only in one translation unit. A plain function definition in a header causes multiple definition linker errors.

`static inline` puts a copy of the function in every translation unit that includes the header, each with internal linkage. Because the function is inline, the compiler typically eliminates the copies that are inlined at the call site. For small utility functions this is the right pattern:

```c
// in utils.h
static inline uint32_t clamp(uint32_t val, uint32_t lo, uint32_t hi) {
    if (val < lo) return lo;
    if (val > hi) return hi;
    return val;
}
```

For larger functions that should not be duplicated, put the declaration in the header and the definition in a `.c` file.

---

## Practical exercise

Create a small three-file project: `main.c`, `ringbuf.c`, and `ringbuf.h`.

`ringbuf.h` should declare a ring buffer type and its interface: `ringbuf_init`, `ringbuf_push`, `ringbuf_pop`, `ringbuf_is_empty`, `ringbuf_is_full`. Use a proper include guard or `#pragma once`. Include only the headers your declarations actually need — `<stdint.h>` and `<stdbool.h>` are sufficient.

`ringbuf.c` implements the functions. Mark all internal helper functions and file-scope variables `static`.

`main.c` includes `ringbuf.h` and exercises the ring buffer. It should not include `<stdint.h>` directly if it already gets it transitively through `ringbuf.h` — but verify this by temporarily removing includes and confirming what breaks.

Compile with:

```bash
gcc -Wall -Wextra -Werror -std=c11 -c ringbuf.c -o ringbuf.o
gcc -Wall -Wextra -Werror -std=c11 -c main.c -o main.o
gcc -o program main.o ringbuf.o
```

Compiling each file separately before linking is how real multi-file projects work. If `ringbuf.h` is not self-contained — if it relies on an include from `main.c` to compile correctly — the first compile command will fail and tell you exactly what is missing.

---

## What to carry forward

The preprocessor is a text transformer with no knowledge of C. Headers contain declarations, not definitions. Include guards or `#pragma once` prevent double inclusion. Function-like macros evaluate arguments multiple times and have no type safety — use `static inline` instead. Mark everything internal to a `.c` file as `static`. A header that compiles cleanly in isolation is a correctly written header.

Tomorrow: error handling patterns — the discipline that makes C programs robust instead of fragile.