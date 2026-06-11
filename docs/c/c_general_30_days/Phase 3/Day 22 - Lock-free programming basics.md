
Mutexes are correct and usually fast enough. But in two specific scenarios they become a liability: when you need to share data between a signal handler and normal code (signal handlers can't use mutexes safely), and when profiling shows lock contention is a genuine bottleneck in a tight data path. Lock-free programming using C11 atomics addresses both. It is also significantly harder to get right than mutex-based code — this lesson teaches you when to use it and, more importantly, when not to.

---

## What "lock-free" actually means

Lock-free does not mean "no synchronisation." It means synchronisation without blocking — at least one thread always makes progress even if others are delayed. The mechanism is **atomic operations**: reads, writes, and read-modify-write operations that the CPU executes as a single indivisible unit, with no possibility of another thread observing an intermediate state.

The C11 `<stdatomic.h>` header gives you portable atomic types and operations. GCC and Clang both support it fully.

---

## C11 atomic types and basic operations

```c
#include <stdatomic.h>
#include <stdint.h>
#include <stdbool.h>

/* Declare atomic variables */
_Atomic uint32_t counter = 0;
_Atomic bool     flag    = false;
atomic_int       score   = 0;   /* typedef: atomic_int = _Atomic int */

/* All atomic types have these operations */

/* Load — read the current value */
uint32_t val = atomic_load(&counter);

/* Store — write a new value */
atomic_store(&counter, 42);

/* Fetch-and-add — add and return the OLD value */
uint32_t old = atomic_fetch_add(&counter, 1);   /* counter++ */
uint32_t old = atomic_fetch_sub(&counter, 1);   /* counter-- */
uint32_t old = atomic_fetch_or(&counter,  mask);
uint32_t old = atomic_fetch_and(&counter, mask);
uint32_t old = atomic_fetch_xor(&counter, mask);

/* Exchange — write and return the old value */
uint32_t prev = atomic_exchange(&counter, 99);

/* Compare-and-swap — the foundation of all lock-free algorithms */
uint32_t expected = 5;
bool swapped = atomic_compare_exchange_strong(&counter, &expected, 10);
/* If counter == expected (5): sets counter = 10, returns true  */
/* If counter != expected:     sets expected = counter, returns false */
```

The `_Atomic` qualifier on a type makes every access to that variable atomic. You cannot take a race condition on an `_Atomic` variable — the compiler and CPU guarantee each operation is indivisible.

---

## Memory ordering — the hard part

Every atomic operation takes an optional memory order argument. This controls how the CPU and compiler may reorder other memory operations around the atomic one. Getting this wrong produces bugs that only appear on multi-core ARM systems under load.

```c
/* Six memory orders, from weakest to strongest */

memory_order_relaxed   /* No ordering guarantees — only atomicity */
memory_order_acquire   /* No reads/writes after this can move before it */
memory_order_release   /* No reads/writes before this can move after it */
memory_order_acq_rel   /* acquire + release combined */
memory_order_consume   /* dependency-ordered acquire — avoid in practice */
memory_order_seq_cst   /* Total sequential consistency — the default */
```

In practice, three patterns cover almost all use cases:

**Pattern 1 — Relaxed counter**: you only care about the final value, not ordering relative to other operations.

```c
/* Hit counter, statistics — only the count matters */
atomic_fetch_add_explicit(&hit_count, 1, memory_order_relaxed);
```

**Pattern 2 — Release/acquire for publishing data**: one thread writes data then sets a flag; another thread checks the flag then reads the data. The flag must use release on the write side and acquire on the read side to guarantee the data is visible before the flag:

```c
/* Writer thread */
g_config.port    = 9000;           /* ordinary write */
g_config.timeout = 30;             /* ordinary write */
atomic_store_explicit(&g_config_ready, true,
                      memory_order_release);   /* publish */

/* Reader thread */
if (atomic_load_explicit(&g_config_ready,
                         memory_order_acquire)) {  /* observe publish */
    use(g_config.port);    /* safe — all writes before release are visible */
    use(g_config.timeout);
}
```

**Pattern 3 — Sequential consistency (default)**: when in doubt, use the default. It is the strongest, the safest, and slower only on architectures with weak memory models (ARM, POWER). On x86 it compiles to the same instructions as relaxed.

```c
/* Default — no explicit memory_order argument needed */
atomic_store(&flag, true);
bool f = atomic_load(&flag);
```

**The practical rule**: use `seq_cst` (the default) until profiling shows atomic operations are a bottleneck, then carefully apply `release`/`acquire` pairs. Never use `relaxed` for anything except counters and statistics that don't gate other operations.

---

## Compare-and-swap — the primitive behind everything

CAS is the building block of all lock-free data structures. The pattern is always the same: read the current value, compute a new value, attempt to swap — if the value changed since you read it, retry:

```c
/* Lock-free increment — equivalent to mutex-protected counter++ */
uint32_t old, new;
do {
    old = atomic_load_explicit(&counter, memory_order_relaxed);
    new = old + 1;
} while (!atomic_compare_exchange_weak_explicit(
            &counter, &old, new,
            memory_order_release,
            memory_order_relaxed));
```

`weak` vs `strong` CAS: `_weak` can spuriously fail even when the values match — allowed to fail for performance on LL/SC architectures (ARM). Always use `_weak` in a retry loop (cheaper), `_strong` when you need exactly one attempt.

The ABA problem: CAS checks that a value equals `expected`. If another thread changes A→B→A between your load and your CAS, you see A and swap — but the world changed. For simple counters this doesn't matter. For linked list nodes it's catastrophic. The fix is a version counter embedded in the value:

```c
/* ABA-safe pointer: pack pointer + version into a 64-bit atomic */
typedef struct {
    uintptr_t ptr     : 48;   /* pointer */
    uintptr_t version : 16;   /* incremented on every change */
} TaggedPtr;

_Atomic TaggedPtr head;
```

---

## Lock-free ring buffer

The single-producer single-consumer (SPSC) ring buffer is the most practically useful lock-free data structure in embedded and IoT work. One thread writes sensor data; another reads and processes it. No mutex needed — two atomic indices do all the synchronisation:

```c
/* spsc_ring.h */
#pragma once
#include <stdatomic.h>
#include <stdint.h>
#include <stdbool.h>
#include <string.h>
#include <stdlib.h>

/*
 * Single-producer single-consumer ring buffer.
 * Capacity must be a power of two.
 * Safe to use between exactly ONE producer thread and ONE consumer thread.
 * Not safe for multiple producers or multiple consumers.
 */
typedef struct {
    uint8_t         *buf;
    size_t           cap;      /* must be power of two */
    size_t           mask;     /* cap - 1 */
    size_t           item_sz;  /* size of one element */
    _Atomic size_t   head;     /* producer writes here — index of next write */
    _Atomic size_t   tail;     /* consumer reads here — index of next read */
} SPSCRing;

static inline bool is_power_of_two(size_t n) {
    return n > 0 && (n & (n - 1)) == 0;
}

static inline int spsc_init(SPSCRing *r, size_t capacity, size_t item_sz) {
    if (!is_power_of_two(capacity)) return -1;
    r->buf     = malloc(capacity * item_sz);
    if (!r->buf) return -1;
    r->cap     = capacity;
    r->mask    = capacity - 1;
    r->item_sz = item_sz;
    atomic_init(&r->head, 0);
    atomic_init(&r->tail, 0);
    return 0;
}

static inline void spsc_free(SPSCRing *r) {
    free(r->buf);
    r->buf = NULL;
}

/*
 * Push one item. Call from producer thread only.
 * Returns true on success, false if ring is full.
 */
static inline bool spsc_push(SPSCRing *r, const void *item) {
    size_t head = atomic_load_explicit(&r->head, memory_order_relaxed);
    size_t tail = atomic_load_explicit(&r->tail, memory_order_acquire);

    if (head - tail >= r->cap) return false;   /* full */

    memcpy(r->buf + (head & r->mask) * r->item_sz, item, r->item_sz);

    /* Release: ensure data write is visible before head update */
    atomic_store_explicit(&r->head, head + 1, memory_order_release);
    return true;
}

/*
 * Pop one item. Call from consumer thread only.
 * Returns true on success, false if ring is empty.
 */
static inline bool spsc_pop(SPSCRing *r, void *out) {
    size_t tail = atomic_load_explicit(&r->tail, memory_order_relaxed);
    size_t head = atomic_load_explicit(&r->head, memory_order_acquire);

    if (head == tail) return false;   /* empty */

    memcpy(out, r->buf + (tail & r->mask) * r->item_sz, r->item_sz);

    /* Release: ensure data read is complete before tail update */
    atomic_store_explicit(&r->tail, tail + 1, memory_order_release);
    return true;
}

static inline size_t spsc_len(SPSCRing *r) {
    size_t head = atomic_load_explicit(&r->head, memory_order_acquire);
    size_t tail = atomic_load_explicit(&r->tail, memory_order_acquire);
    return head - tail;
}

static inline bool spsc_full(SPSCRing *r) {
    return spsc_len(r) >= r->cap;
}

static inline bool spsc_empty(SPSCRing *r) {
    return spsc_len(r) == 0;
}
```

The key insight: `head` is only written by the producer and only read by the consumer. `tail` is only written by the consumer and only read by the producer. No CAS needed — a single store with release semantics is sufficient because there is only one writer per index.

---

## Using the ring buffer in a sensor pipeline

```c
#include <stdio.h>
#include <pthread.h>
#include <unistd.h>
#include <time.h>
#include "spsc_ring.h"
#include "log.h"

typedef struct {
    uint32_t timestamp;
    uint8_t  device_id;
    float    value;
} SensorReading;

#define RING_CAP 256   /* must be power of two */

static SPSCRing g_ring;

/* ── producer: reads hardware, pushes to ring ─────────────────── */
static void *producer(void *arg) {
    (void)arg;
    uint32_t seq = 0;

    for (int i = 0; i < 1000; i++) {
        SensorReading r = {
            .timestamp = (uint32_t)time(NULL),
            .device_id = (uint8_t)(i % 4),
            .value     = 20.0f + (float)(i % 100) * 0.1f,
        };

        /* Spin on backpressure — ring is full */
        while (!spsc_push(&g_ring, &r)) {
            /* In real code: yield or sleep briefly */
            usleep(100);
        }
        seq++;

        usleep(500);   /* simulate 2kHz sensor */
    }

    LOG_INFO("producer done: %u readings", seq);
    return NULL;
}

/* ── consumer: pops from ring, processes ──────────────────────── */
static void *consumer(void *arg) {
    (void)arg;
    SensorReading r;
    uint32_t processed = 0;

    while (processed < 1000) {
        if (spsc_pop(&g_ring, &r)) {
            processed++;
            if (processed % 100 == 0) {
                LOG_INFO("consumer: %u readings, last=%.2f dev=%u",
                         processed, r.value, r.device_id);
            }
        } else {
            usleep(100);   /* ring empty — yield */
        }
    }

    LOG_INFO("consumer done: %u readings", processed);
    return NULL;
}

int main(void) {
    if (spsc_init(&g_ring, RING_CAP, sizeof(SensorReading)) < 0) {
        LOG_ERROR("ring init failed");
        return 1;
    }

    pthread_t prod_t, cons_t;
    pthread_create(&prod_t, NULL, producer, NULL);
    pthread_create(&cons_t, NULL, consumer, NULL);

    pthread_join(prod_t, NULL);
    pthread_join(cons_t, NULL);

    LOG_INFO("ring: remaining=%zu", spsc_len(&g_ring));
    spsc_free(&g_ring);
    return 0;
}
```

Build with ThreadSanitizer to verify the memory ordering is correct:

```bash
gcc -Wall -Wextra -g -fsanitize=thread -lpthread \
    -o pipeline main.c log.c errors.c
```

---

## Atomic flags for signal-handler communication

Day 13 used `volatile sig_atomic_t`. C11 atomics are the modern replacement — they provide the same signal safety with explicit memory ordering guarantees:

```c
#include <stdatomic.h>
#include <signal.h>

/* C11 atomic — safe to write in signal handler, read in main loop */
static _Atomic int g_quit   = 0;
static _Atomic int g_reload = 0;

static void handle_term(int sig) {
    (void)sig;
    /* atomic_store with seq_cst — visible to main loop immediately */
    atomic_store(&g_quit, 1);
}

static void handle_hup(int sig) {
    (void)sig;
    atomic_store(&g_reload, 1);
}

/* Main loop reads with seq_cst — guaranteed to see signal handler's write */
while (!atomic_load(&g_quit)) {
    if (atomic_exchange(&g_reload, 0)) {
        /* Use exchange so we don't miss a second SIGHUP */
        reload_config();
    }
    do_work();
}
```

`atomic_exchange` for the reload flag is better than a plain load+store — it clears the flag and reads its old value atomically, so a second `SIGHUP` arriving between load and store can't be silently lost.

---

## When NOT to use lock-free programming

This is as important as knowing how to use it.

Use a mutex when any of these apply — they represent the vast majority of real code:

**Multiple producers or consumers**: the SPSC ring buffer works only with one of each. An MPMC ring buffer is dramatically more complex — use a mutex-protected queue from Day 17 instead.

**The critical section does anything other than a single store or load**: updating a linked list, resizing a buffer, or any operation that touches multiple memory locations non-atomically cannot be made lock-free without extremely careful design.

**You haven't profiled and confirmed lock contention is the bottleneck**: mutex operations are fast — typically 20–50ns on modern hardware. A lock-free algorithm that's ten times more complex to reason about and verify saves at most 40ns per operation. Write the mutex version first. Profile. Optimise only if the data shows it's necessary.

**The code will be maintained by others**: lock-free code requires understanding memory ordering, CAS loops, and the ABA problem. A correctly written mutex-based solution is almost always preferable to a subtly wrong lock-free one.

---

## Day 22 exercise

1. Implement `SPSCRing` and the sensor pipeline from the lesson. Run it under ThreadSanitizer and verify zero races. Then deliberately break the memory ordering — change the `memory_order_release` in `spsc_push` to `memory_order_relaxed` and run under TSan again. Observe whether TSan catches it (it may not — this is the insidious nature of memory ordering bugs).
    
2. Write a lock-free hit counter used by multiple threads: use `atomic_fetch_add` with `memory_order_relaxed` to count requests. Have 8 threads each increment 1,000,000 times. Verify the final count is exactly 8,000,000. Compare performance against a mutex-protected counter using `clock_gettime`.
    
3. Implement a lock-free stack using CAS. The push operation is:
    
    ```
    new_node->next = head
    while (!CAS(&head, &new_node->next, new_node)) {}
    ```
    
    Implement push and pop. Test with a single producer and single consumer. Then explain in a comment why this implementation has the ABA problem for pop, and what the consequence would be.
    
4. Replace the `WorkQueue` from Day 17 (mutex + condition variable) with an `SPSCRing` in the sensor pipeline. Benchmark both: measure throughput (items per second) with `clock_gettime(CLOCK_MONOTONIC)`. Record which is faster at what queue depths and explain the result.
    

Day 23 covers dynamic libraries and plugin architectures — `dlopen`, `dlsym`, loading drivers at runtime, and building a system where new sensor types can be added without recompiling the core.