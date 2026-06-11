

You've built the nine skills that make up C fundamentals. Today you wire them together into a build system that scales. A well-structured project with a solid Makefile is the difference between code you can hand to someone else and code only you can build. This is also the last day of Phase 1 — at the end you'll have a working multi-module project skeleton that Phase 2 builds on.

---

## How `make` actually works

Most people use make as a "run my compile command" shortcut. It's actually a dependency-tracking build system. Understanding the model makes the difference between a Makefile that works and one that silently does the wrong thing.The visualizer is timing out — I'll deliver the full lesson in text directly.

---

## How `make` actually works

Make tracks a dependency graph of targets and prerequisites. A target is rebuilt only when it is **older than any of its prerequisites** — timestamps, not content. This is both its power and its main footgun.

```makefile
# Anatomy of a rule
target: prerequisite1 prerequisite2
	command_to_build_target   # MUST be a real tab character

# Concrete example
sensor.o: src/sensor.c include/sensor.h include/errors.h
	$(CC) $(CFLAGS) -c src/sensor.c -o sensor.o
```

The critical implication: if `include/sensor.h` is not listed as a prerequisite, changes to it are silently ignored. Your object file is stale but make doesn't know. This is the most common Makefile correctness bug, and it produces maddening "I changed the code but nothing happened" problems.

---

## Automatic variables

These make pattern rules work generically:

|Variable|Expands to|Example value|
|---|---|---|
|`$@`|The target name|`build/sensor.o`|
|`$<`|First prerequisite|`src/sensor.c`|
|`$^`|All prerequisites|`sensor.o mqtt.o main.o`|
|`$*`|Stem matched by `%`|`sensor` (from `%.o: %.c`)|
|`$(@D)`|Directory part of `$@`|`build`|

```makefile
# Pattern rule — one rule handles every .c → .o conversion
$(BUILD_DIR)/%.o: src/%.c
	@mkdir -p $(@D)              # create build/ if needed
	$(CC) $(CFLAGS) -c $< -o $@ # $< = the .c, $@ = the .o

# Link rule
$(TARGET): $(OBJS)
	$(CC) $(LDFLAGS) -o $@ $^   # $^ = all object files
```

The `@` prefix on a command suppresses it from being echoed — cleaner output. Remove it when debugging Makefile execution.

---

## Auto-generated dependency files — `-MMD -MP`

GCC can read your `#include` directives and generate the prerequisite list automatically. This permanently solves the header-tracking problem:

```makefile
DEPS = $(OBJS:.o=.d)

$(BUILD_DIR)/%.o: src/%.c
	@mkdir -p $(@D)
	$(CC) $(CFLAGS) -MMD -MP -c $< -o $@

# Include all generated .d files
# The leading - suppresses errors on first build (no .d files yet)
-include $(DEPS)
```

What `-MMD` generates alongside `build/sensor.o` as `build/sensor.d`:

```makefile
build/sensor.o: src/sensor.c include/sensor.h \
  include/errors.h include/log.h
include/sensor.h:
include/errors.h:
include/log.h:
```

The empty phony targets for headers (from `-MP`) prevent make from erroring when a header is deleted and the old `.d` file still references it. Add `-MMD -MP` and `-include $(DEPS)` to every project from now on.

---

## The complete production Makefile

This is the Makefile template for everything you build in Phase 2 and 3. Copy it, change the target name and source list, and it works:

```makefile
# ── configuration ────────────────────────────────────────────────
TARGET   := prog
BUILD    := build
SRCDIR   := src
INCDIR   := include

CC       := gcc
CFLAGS   := -Wall -Wextra -Werror -std=c11 -I$(INCDIR)
LDFLAGS  :=
LDLIBS   :=

# ── source discovery ─────────────────────────────────────────────
SRCS := $(wildcard $(SRCDIR)/*.c)
OBJS := $(patsubst $(SRCDIR)/%.c, $(BUILD)/%.o, $(SRCS))
DEPS := $(OBJS:.o=.d)

# ── build targets ────────────────────────────────────────────────
.DEFAULT_GOAL := release

release: CFLAGS += -O2
release: $(TARGET)

debug: CFLAGS += -g -O0 -fsanitize=address,undefined
debug: LDFLAGS += -fsanitize=address,undefined
debug: $(TARGET)

$(TARGET): $(OBJS)
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ $^ $(LDLIBS)

$(BUILD)/%.o: $(SRCDIR)/%.c
	@mkdir -p $(@D)
	$(CC) $(CFLAGS) -MMD -MP -c $< -o $@

-include $(DEPS)

# ── static library target (optional) ─────────────────────────────
lib$(TARGET).a: $(filter-out $(BUILD)/main.o, $(OBJS))
	ar rcs $@ $^

# ── utility targets ───────────────────────────────────────────────
clean:
	rm -rf $(BUILD) $(TARGET) lib$(TARGET).a

.PHONY: release debug clean
```

The `wildcard` and `patsubst` functions auto-discover source files — add a new `.c` to `src/` and it's automatically compiled on the next `make`. The `filter-out` on the library target excludes `main.o` since a library shouldn't contain a `main` function.

---

## Static libraries

A static library is an archive of object files. The linker pulls out only the `.o` files that satisfy unresolved references in the program being linked:

```makefile
# Build the library
libsensor.a: build/sensor.o build/errors.o build/log.o
	ar rcs $@ $^
# ar rcs: r=insert/replace, c=create if needed, s=write symbol index

# Link against it — two equivalent forms:
prog: build/main.o libsensor.a
	$(CC) -o $@ build/main.o libsensor.a

# or via -L and -l flags:
	$(CC) -o $@ build/main.o -L. -lsensor
```

The symbol index (`s` flag to `ar`) is non-optional — without it the linker can't find functions in the archive. If you ever get "undefined reference" errors for functions you know are compiled, a missing symbol index is one of the first things to check.

---

## Phase 1 capstone project structure

Create this layout now. You'll extend it throughout Phase 2:

```
sensor_base/
├── Makefile               (the full template above)
├── .gitignore             (build/ *.a *.d)
├── include/
│   ├── sensor.h           (Days 6, 7 — Sensor struct, create/destroy/update)
│   ├── ringbuf.h          (Day 8 — float ring buffer)
│   ├── errors.h           (Day 9 — Error enum, error_str)
│   └── log.h              (Day 9 — LOG_INFO, LOG_ERROR, LOG_DEBUG)
├── src/
│   ├── main.c             (ties everything together)
│   ├── sensor.c
│   ├── ringbuf.c
│   ├── errors.c
│   └── log.c
└── build/                 (generated — in .gitignore)
```

`main.c` for the capstone:

```c
#include <stdio.h>
#include <string.h>
#include "sensor.h"
#include "ringbuf.h"
#include "errors.h"
#include "log.h"

int main(int argc, char *argv[]) {
    /* Enable debug logging with -v flag */
    if (argc > 1 && strcmp(argv[1], "-v") == 0) {
        log_init(LOG_LEVEL_DEBUG);
    }

    LOG_INFO("sensor_base starting");

    /* Create two sensors */
    Sensor *temp = sensor_create(1, "temperature");
    Sensor *humi = sensor_create(2, "humidity");
    if (!temp || !humi) {
        LOG_ERROR("sensor_create failed");
        sensor_destroy(temp);
        sensor_destroy(humi);
        return 1;
    }

    /* Each sensor gets a ring buffer for its last 8 readings */
    float temp_storage[8], humi_storage[8];
    RingBuf temp_buf, humi_buf;
    ringbuf_init(&temp_buf, temp_storage, 8);
    ringbuf_init(&humi_buf, humi_storage, 8);

    /* Simulate 12 readings (buffer wraps at 8) */
    float temp_readings[] = {21.1f,21.4f,21.8f,22.0f,22.3f,
                              22.1f,21.9f,22.4f,22.7f,23.0f,
                              23.2f,23.1f};
    for (int i = 0; i < 12; i++) {
        sensor_update(temp, temp_readings[i]);
        ringbuf_push(&temp_buf, temp_readings[i]);
        LOG_DEBUG("pushed %.1f (reading %d)", temp_readings[i], i + 1);
    }

    sensor_print(temp);

    /* Drain the ring buffer */
    float val;
    int count = 0;
    printf("last 8 readings: ");
    while (ringbuf_pop(&temp_buf, &val)) {
        printf("%.1f ", val);
        count++;
    }
    printf("(%d values)\n", count);

    sensor_destroy(temp);
    sensor_destroy(humi);
    LOG_INFO("done");
    return 0;
}
```

---

## Day 10 exercise

1. Build the `sensor_base` project layout above using all modules from Days 6–9. Confirm `make debug` and `make release` both produce a working binary. Verify `make` after touching a header triggers recompilation of all `.c` files that include it.
    
2. Add a `lib` target to the Makefile that builds `libsensor.a` from everything except `main.o`. Then write a second program `tools/inspect.c` that links against `libsensor.a` and calls `sensor_print`. Confirm it compiles without access to `sensor.c`.
    
3. Add `make test` to the Makefile that compiles and runs `tests/test_sensor.c` — a standalone program that creates a sensor, updates it, and asserts the reading is correct using your Day 8 `ASSERT` macro. Exit code 0 = pass, non-zero = fail.
    
4. Run the complete debug binary under Valgrind and confirm zero leaks and zero errors. Fix anything it finds.
    

---

## Phase 1 complete

You now have a solid foundation across every dimension of C: types and memory layout, pointers and arrays, functions and the call stack, structs and unions, heap allocation, the preprocessor, error handling, and build systems. More importantly, you understand _why_ each thing works the way it does — the mental model, not just the syntax.

Phase 2 begins with Day 11 and moves into systems programming: file I/O, processes, signals, pipes, sockets, threads, shared memory, and serial communication. Everything from Phase 1 will be used daily — the struct layouts in protocol parsers, the error handling patterns in I/O code, the build system for multi-module projects.