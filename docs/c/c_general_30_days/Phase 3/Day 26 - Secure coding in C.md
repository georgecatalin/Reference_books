

C gives you complete control over memory and execution. That control cuts both ways — every input you don't validate, every buffer you don't bound-check, every integer you don't range-check is a potential vulnerability. This lesson isn't about making code theoretically secure; it's about the specific bugs that appear repeatedly in real systems and the mechanical habits that prevent them.

---

## The threat model for IoT and systems code

Before writing a single defensive line, be clear about what you're defending against. In embedded and IoT systems the realistic threats are:

**Malformed input from the network or serial port** — a sensor sending a corrupted frame, a client sending an oversized MQTT payload, a configuration file with unexpected values. This is the most common attack surface and the one most worth defending.

**Integer overflows in size calculations** — computing a buffer size with `len * sizeof(item)` that wraps to zero, then allocating a zero-byte buffer and overflowing it. This pattern has produced critical vulnerabilities in production systems.

**Format string injection** — passing user-controlled data as the format string to `printf`. Trivial to prevent, catastrophic when present.

**Use of freed memory or stack memory after function return** — structural bugs that become exploitable when the freed slot is reallocated to attacker-controlled data.

Privilege escalation, cryptographic weaknesses, and supply-chain attacks are real but outside the scope of C-specific secure coding — those require system-level hardening.

---

## Input validation as a security boundary

Every byte that arrives from outside your process is untrusted. The validation layer must be the first thing that touches input data, and it must be strict:

```c
#include <stdint.h>
#include <string.h>
#include <errno.h>
#include "errors.h"
#include "log.h"

#define MAX_TOPIC_LEN    256
#define MAX_PAYLOAD_LEN  65535
#define MAX_DEVICE_ID    255
#define MIN_TEMP_C       (-55.0f)
#define MAX_TEMP_C       125.0f

/*
 * Validate an inbound MQTT-style message header.
 * Returns ERR_OK if valid, ERR_INVALID_ARG with a log message if not.
 *
 * Rule: validate ALL fields before using ANY of them.
 * Partial validation that uses fields before all are checked is
 * a common source of TOCTOU bugs.
 */
Error validate_message_header(const uint8_t *buf, size_t buf_len,
                               uint8_t  *out_device_id,
                               uint16_t *out_payload_len,
                               uint8_t  *out_topic_len) {
    /* Minimum header: device_id(1) + topic_len(1) + payload_len(2) = 4 */
    if (buf_len < 4) {
        LOG_WARN("header too short: %zu bytes", buf_len);
        return ERR_INVALID_ARG;
    }

    uint8_t  device_id   = buf[0];
    uint8_t  topic_len   = buf[1];
    uint16_t payload_len;
    memcpy(&payload_len, buf + 2, 2);
    payload_len = ntohs(payload_len);

    /* Validate each field independently */
    if (device_id > MAX_DEVICE_ID) {
        LOG_WARN("invalid device_id: %u", device_id);
        return ERR_INVALID_ARG;
    }
    if (topic_len == 0 || topic_len > MAX_TOPIC_LEN) {
        LOG_WARN("invalid topic_len: %u", topic_len);
        return ERR_INVALID_ARG;
    }
    if (payload_len > MAX_PAYLOAD_LEN) {
        LOG_WARN("invalid payload_len: %u", payload_len);
        return ERR_INVALID_ARG;
    }

    /* Validate that buf contains what the header claims */
    size_t claimed_total = 4 + topic_len + payload_len;
    if (buf_len < claimed_total) {
        LOG_WARN("buf_len %zu < claimed total %zu", buf_len, claimed_total);
        return ERR_INVALID_ARG;
    }

    /* All fields valid — write outputs */
    *out_device_id   = device_id;
    *out_payload_len = payload_len;
    *out_topic_len   = topic_len;
    return ERR_OK;
}
```

The discipline: validate all fields first, write outputs last. A function that partially validates and partially uses data creates windows where a partially-valid message causes undefined behaviour.

---

## Integer overflow in size calculations

This is the single most under-appreciated vulnerability class in C. It appears in allocation code, length calculations, and loop bounds:

```c
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

/*
 * VULNERABLE: integer overflow in size calculation
 */
void *bad_alloc(size_t count, size_t item_size) {
    /* If count=0x80000001, item_size=2:
       count * item_size = 0x100000002 — truncates to 2 on 32-bit,
       or wraps on overflow. malloc(2) succeeds, then you write
       0x80000001 * 2 bytes into a 2-byte buffer. */
    return malloc(count * item_size);
}

/*
 * SAFE: checked multiplication
 */
void *safe_alloc(size_t count, size_t item_size) {
    /* Detect overflow before it happens */
    if (item_size > 0 && count > SIZE_MAX / item_size) {
        LOG_ERROR("size overflow: count=%zu item_size=%zu", count, item_size);
        return NULL;
    }
    return malloc(count * item_size);
}

/*
 * SAFE: use calloc — it performs the overflow check internally
 * and zero-initialises the memory
 */
void *safe_calloc(size_t count, size_t item_size) {
    void *p = calloc(count, item_size);
    if (!p) {
        LOG_ERROR("calloc failed: count=%zu item_size=%zu", count, item_size);
    }
    return p;
}

/*
 * Integer overflow in length arithmetic — equally dangerous
 */
Error safe_append(char *dst, size_t dst_size,
                  const char *src, size_t src_len) {
    size_t dst_len = strlen(dst);

    /* dst_len + src_len can overflow if both are large */
    if (src_len > dst_size - 1 - dst_len) {
        LOG_ERROR("append would overflow: dst_len=%zu src_len=%zu cap=%zu",
                  dst_len, src_len, dst_size);
        return ERR_INVALID_ARG;
    }
    memcpy(dst + dst_len, src, src_len);
    dst[dst_len + src_len] = '\0';
    return ERR_OK;
}

/*
 * Signed integer overflow — undefined behaviour, not just wraparound
 */
bool safe_add_signed(int a, int b, int *result) {
    /* Undefined behaviour to overflow a signed int — check first */
    if (b > 0 && a > INT_MAX - b) return false;   /* would overflow */
    if (b < 0 && a < INT_MIN - b) return false;   /* would underflow */
    *result = a + b;
    return true;
}
```

`calloc(count, size)` is not just `malloc(count * size)` — the C standard requires implementations to check for multiplication overflow. Use `calloc` for arrays whenever the memory should start zeroed; use `safe_alloc` for non-zero-initialised arrays.

---

## Format string vulnerabilities

The simplest bug in this list and the easiest to prevent:

```c
#include <stdio.h>

/* CATASTROPHIC: user controls the format string */
void log_bad(const char *user_input) {
    printf(user_input);        /* if user_input is "%s%s%s%s%s%n" ... */
    fprintf(logfile, user_input);   /* same bug, just to a file */
}

/* SAFE: user input is always an argument, never the format string */
void log_good(const char *user_input) {
    printf("%s", user_input);            /* user_input is data, not format */
    fprintf(logfile, "%s\n", user_input);
    LOG_INFO("%s", user_input);          /* same rule for your own macros */
}

/* ALSO VULNERABLE: constructing format strings at runtime */
char fmt[64];
snprintf(fmt, sizeof(fmt), "device %s: %%f\n", device_name);
printf(fmt, value);   /* if device_name contains % characters — bad */

/* SAFE: don't construct format strings from untrusted data */
printf("device %s: %f\n", device_name, value);
```

`-Wformat-security` (included in `-Wall`) warns when a non-literal format string is passed to `printf`. Enable it and treat it as an error. If `-Wformat-security` fires on your code, it is always a bug.

---

## Buffer operations — the mechanical rules

```c
#include <string.h>
#include <stdio.h>

/*
 * Rule 1: always pass buffer capacity to string functions,
 *         never a hardcoded number
 */
char buf[64];
/* WRONG: */
strncpy(buf, input, 64);          /* 64 hardcoded — breaks if buf is resized */
/* RIGHT: */
strncpy(buf, input, sizeof(buf) - 1);
buf[sizeof(buf) - 1] = '\0';      /* strncpy doesn't guarantee termination */

/*
 * Rule 2: snprintf is always safer than sprintf or strcpy+strcat
 *         snprintf returns bytes that WOULD be written — check for truncation
 */
int n = snprintf(buf, sizeof(buf), "device/%u/temp", device_id);
if (n < 0 || (size_t)n >= sizeof(buf)) {
    LOG_ERROR("snprintf truncated or failed");
    return ERR_INVALID_ARG;
}

/*
 * Rule 3: use memcpy for binary data, not strcpy
 *         strcpy stops at null bytes — wrong for binary frames
 */
uint8_t frame[16];
memcpy(frame, buf, 16);     /* copies exactly 16 bytes regardless of content */

/*
 * Rule 4: validate before indexing — always
 */
uint8_t idx = packet[2];
if (idx >= ARRAY_LEN(g_handlers)) {
    LOG_WARN("invalid handler index: %u", idx);
    return ERR_BAD_PACKET;
}
g_handlers[idx](packet);   /* safe — bounds checked */

/*
 * Rule 5: for receiving into a buffer, always leave room for
 *         a null terminator if the result will be treated as a string
 */
ssize_t n2 = read(fd, buf, sizeof(buf) - 1);
if (n2 > 0) {
    buf[n2] = '\0';   /* safe — we reserved space for this */
}
```

---

## Stack protection and OS mitigations

The compiler and OS provide several mitigations that raise the cost of exploiting memory bugs. Know what they are and how to verify they're active:

```bash
# Stack canaries — compile-time (enabled by default with -O)
gcc -fstack-protector-strong -o prog main.c
# Inserts a random value between locals and return address.
# If a buffer overflow overwrites it, the program aborts before returning.
# -fstack-protector-strong protects functions with arrays or address-taken locals.

# Verify mitigations on a binary
checksec --file=prog
# or:
readelf -l prog | grep GNU_STACK   # should show RW (not RWE — not executable)

# ASLR — kernel feature, not compiler
cat /proc/sys/kernel/randomize_va_space
# 0 = disabled, 1 = partial, 2 = full (default on Linux)

# Verify a specific binary has PIE (needed for ASLR to cover code)
file prog
# should include "pie executable" — not "executable"
gcc -fPIE -pie -o prog main.c      # enable PIE explicitly
```

```c
/*
 * Compile-time assertion for struct sizes and field offsets —
 * catches accidental layout changes before they become security issues
 */
#include <assert.h>
#include <stddef.h>

static_assert(sizeof(PacketHeader)          == 12,
              "PacketHeader layout changed — audit all parsers");
static_assert(offsetof(PacketHeader, length) == 4,
              "length field moved — update all serialisers");
```

---

## Static analysis — finding bugs without running code

Static analysers read source code and report suspicious patterns. Run them as part of your build pipeline, not just when you suspect a bug:

```bash
# cppcheck — lightweight, good signal-to-noise ratio
cppcheck --enable=all --error-exitcode=1 src/

# clang-tidy — deep analysis, integrates with build system
clang-tidy src/*.c -- -Iinclude -std=c11

# Specific clang-tidy checks useful for security
clang-tidy src/*.c -checks='cert-*,clang-analyzer-security*,bugprone-*' \
    -- -Iinclude -std=c11

# GCC's analyser — available since GCC 10
gcc -fanalyzer -Wall -Wextra -o /dev/null main.c

# AddressSanitizer + UndefinedBehaviorSanitizer (runtime, from Day 7)
gcc -fsanitize=address,undefined -g -o prog main.c
./prog    # runs your test suite; any sanitizer finding is a real bug
```

Add `cppcheck` to your Makefile as a `lint` target that runs on every CI push:

```makefile
lint:
	cppcheck --enable=all --error-exitcode=1 \
	    --suppress=missingIncludeSystem \
	    -Iinclude src/

.PHONY: lint
```

---

## A secure frame parser — putting it all together

A parser that applies all the defensive patterns from this lesson:

```c
#include <stdint.h>
#include <string.h>
#include <stdlib.h>
#include "errors.h"
#include "log.h"

#define FRAME_MAGIC      0xBEEF
#define FRAME_VERSION    1
#define MAX_FRAME_PAYLOAD 4096
#define FRAME_HEADER_SZ  8

typedef struct {
    uint16_t magic;
    uint8_t  version;
    uint8_t  type;
    uint32_t payload_len;
} __attribute__((packed)) FrameHeader;

static_assert(sizeof(FrameHeader) == FRAME_HEADER_SZ,
              "FrameHeader size mismatch");

typedef struct {
    uint8_t  type;
    uint32_t payload_len;
    uint8_t *payload;   /* heap allocated — caller must free */
} ParsedFrame;

Error parse_frame_secure(const uint8_t *buf, size_t buf_len,
                          ParsedFrame *out) {
    /* 1. Validate minimum length before touching any fields */
    if (buf_len < FRAME_HEADER_SZ) {
        LOG_WARN("frame too short: %zu bytes (need %d)",
                 buf_len, FRAME_HEADER_SZ);
        return ERR_BAD_PACKET;
    }

    /* 2. memcpy into aligned struct — never cast raw buffer */
    FrameHeader hdr;
    memcpy(&hdr, buf, sizeof(hdr));

    /* 3. Validate magic and version before trusting other fields */
    uint16_t magic = ntohs(hdr.magic);
    if (magic != FRAME_MAGIC) {
        LOG_WARN("bad magic: 0x%04X", magic);
        return ERR_BAD_PACKET;
    }
    if (hdr.version != FRAME_VERSION) {
        LOG_WARN("unsupported version: %u", hdr.version);
        return ERR_BAD_PACKET;
    }

    /* 4. Validate payload_len before using it in any arithmetic */
    uint32_t payload_len = ntohl(hdr.payload_len);
    if (payload_len > MAX_FRAME_PAYLOAD) {
        LOG_WARN("payload_len %u exceeds max %d",
                 payload_len, MAX_FRAME_PAYLOAD);
        return ERR_BAD_PACKET;
    }

    /* 5. Check buffer contains claimed payload — prevent read overflow */
    size_t total = FRAME_HEADER_SZ + (size_t)payload_len;
    if (total < FRAME_HEADER_SZ) {   /* overflow check */
        LOG_WARN("size overflow");
        return ERR_BAD_PACKET;
    }
    if (buf_len < total) {
        LOG_WARN("incomplete frame: have %zu need %zu", buf_len, total);
        return ERR_BAD_PACKET;
    }

    /* 6. Allocate exactly what's needed — checked via calloc */
    uint8_t *payload = NULL;
    if (payload_len > 0) {
        payload = calloc(1, payload_len);
        if (!payload) {
            LOG_ERROR("allocation failed: %u bytes", payload_len);
            return ERR_NO_MEMORY;
        }
        memcpy(payload, buf + FRAME_HEADER_SZ, payload_len);
    }

    /* 7. Write outputs only after all validation passes */
    out->type        = hdr.type;
    out->payload_len = payload_len;
    out->payload     = payload;   /* caller owns this memory */
    return ERR_OK;
}
```

---

## Day 26 exercise

1. Audit the Day 19 `serial_read_frame` function against the rules in this lesson: does it validate `payload_len` before allocation? Does it check for integer overflow in size arithmetic? Does it use safe string functions? Fix any issues found and re-run under AddressSanitizer.
    
2. Write `safe_alloc` and a test that calls it with values designed to trigger overflow: `count=SIZE_MAX, item_size=2`. Verify it returns NULL and logs an error rather than allocating a tiny buffer.
    
3. Take `parse_sensor_payload` from Day 20 and run `cppcheck` and `clang-tidy` against it. Fix every finding. Add a `static_assert` on the `SensorData` struct size and verify it fires if you add a field.
    
4. Compile the Day 15 echo server with `-fstack-protector-strong -fPIE -pie -D_FORTIFY_SOURCE=2`. Run `checksec` on the resulting binary and verify all mitigations show as enabled. Then run it under `valgrind --tool=memcheck` with a client that sends maximum-size messages and verify zero memory errors.
    

Day 27 covers embedded C patterns — `volatile`, register access, ISR constraints, state machines, and avoiding dynamic allocation in constrained systems.