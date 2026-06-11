

Threads share the same address space — unlike processes, they see the same memory, the same globals, and the same heap. That's their power and their danger. A threading bug doesn't crash only the thread that caused it — it silently corrupts data that every other thread depends on. Today you learn the threading API, the synchronisation primitives that make shared state safe, and the patterns that prevent the concurrency bugs that are hardest to reproduce and debug.

---

## What a process looks like with threads

Every thread gets its own stack and instruction pointer but shares everything else. The kernel schedules threads independently — any thread can run on any CPU core at any time, preempted between any two instructions.

```
process (shared by all threads)
├── heap
├── globals / BSS
├── file descriptors
├── code (text segment)
│
├── thread 1 (main)  ── own stack, registers, errno
├── thread 2         ── own stack, registers, errno
└── thread 3         ── own stack, registers, errno
```

---

## Thread lifecycle — create, join, detach

```c
#include <pthread.h>
/* Compile and link with: gcc ... -lpthread */

/* Thread function — must match this exact signature */
void *worker(void *arg) {
    int id = *(int *)arg;
    printf("thread %d running\n", id);
    return NULL;   /* return value retrievable via pthread_join */
}

int main(void) {
    pthread_t t1, t2;
    int id1 = 1, id2 = 2;

    /* Create — thread starts running immediately after this call */
    pthread_create(&t1, NULL, worker, &id1);
    pthread_create(&t2, NULL, worker, &id2);

    /* Join — block until thread exits, retrieve return value */
    void *ret1, *ret2;
    pthread_join(t1, &ret1);
    pthread_join(t2, &ret2);

    return 0;
}
```

**Detached threads** clean themselves up on exit — use them for fire-and-forget work where the main loop doesn't need the result:

```c
pthread_t t;
pthread_attr_t attr;
pthread_attr_init(&attr);
pthread_attr_setdetachstate(&attr, PTHREAD_CREATE_DETACHED);
pthread_create(&t, &attr, worker, arg);
pthread_attr_destroy(&attr);
/* no pthread_join needed or possible */
```

**The lifetime trap**: if `id1` is a stack variable in `main` and `main` returns before the thread reads it, the thread reads garbage. Always ensure the argument's lifetime exceeds the thread's. Use heap allocation or a global for data passed to long-lived threads.

---

## The race condition

Before showing the fix, you need to viscerally understand the problem. This is what a race looks like:

```c
static int counter = 0;

void *increment_unsafe(void *arg) {
    (void)arg;
    for (int i = 0; i < 100000; i++)
        counter++;   /* NOT atomic — three CPU instructions */
    return NULL;
}

int main(void) {
    pthread_t t1, t2;
    pthread_create(&t1, NULL, increment_unsafe, NULL);
    pthread_create(&t2, NULL, increment_unsafe, NULL);
    pthread_join(t1, NULL);
    pthread_join(t2, NULL);
    printf("counter: %d\n", counter);
    /* Expected: 200000. Actual: anywhere from ~100000 to 200000.
       Run it ten times — different result every time. */
    return 0;
}
```

`counter++` compiles to three instructions: load → add 1 → store. Two threads can both load the same value, both add 1, and both store the same result — one increment is lost. This is the read-modify-write race, and it's the most common data race in concurrent C.

---

## Mutexes

A mutex (mutual exclusion lock) ensures only one thread executes the protected section at a time:

```c
static int             counter = 0;
static pthread_mutex_t lock    = PTHREAD_MUTEX_INITIALIZER;

void *increment_safe(void *arg) {
    (void)arg;
    for (int i = 0; i < 100000; i++) {
        pthread_mutex_lock(&lock);
        counter++;
        pthread_mutex_unlock(&lock);
    }
    return NULL;
}
```

`PTHREAD_MUTEX_INITIALIZER` is a compile-time static initialiser — zero cost. For heap or local mutexes use `pthread_mutex_init(&m, NULL)` and `pthread_mutex_destroy(&m)` when done.

The full mutex API:

|Function|Behaviour|
|---|---|
|`pthread_mutex_lock`|Acquire — blocks if held by another thread|
|`pthread_mutex_trylock`|Acquire — returns `EBUSY` immediately if held|
|`pthread_mutex_timedlock`|Acquire — times out after absolute `timespec`|
|`pthread_mutex_unlock`|Release — must be called by the locking thread|
|`pthread_mutex_destroy`|Free resources — call when no longer needed|

**Lock granularity**: locking the entire function is safe but slow. Locking only the lines that touch shared data is faster but requires care. A common mistake: protecting writes but not reads — a concurrent read of a partially-written value is equally broken.

---

## Condition variables

A condition variable lets one thread sleep efficiently until another signals that state has changed. The canonical pattern is producer-consumer — polling with a mutex alone would burn 100% CPU spinning.

```c
static pthread_mutex_t mu    = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t  cond  = PTHREAD_COND_INITIALIZER;
static int             ready = 0;

/* Consumer — waits for work */
void *consumer(void *arg) {
    (void)arg;
    pthread_mutex_lock(&mu);

    /* ALWAYS a while loop, never if — spurious wakeups are real */
    while (!ready) {
        pthread_cond_wait(&cond, &mu);
        /* pthread_cond_wait atomically:
           1. releases the mutex
           2. sleeps until signalled
           3. reacquires the mutex before returning */
    }
    /* safe to read shared state — we hold the lock */
    printf("consumer: ready=%d\n", ready);
    pthread_mutex_unlock(&mu);
    return NULL;
}

/* Producer — does work, signals consumer */
void *producer(void *arg) {
    (void)arg;
    sleep(1);

    pthread_mutex_lock(&mu);
    ready = 1;
    pthread_cond_signal(&cond);     /* wake one waiting thread */
    /* pthread_cond_broadcast(&cond) — wake ALL waiting threads */
    pthread_mutex_unlock(&mu);
    return NULL;
}
```

**Why `while` not `if`**: POSIX explicitly permits spurious wakeups — `pthread_cond_wait` can return even when no signal was sent. Always re-check the condition after waking. The pattern is invariably: lock → while(!condition) wait → do work → unlock.

---

## Reader-writer locks

When data is read far more often than it's written, a reader-writer lock allows multiple simultaneous readers while giving writers exclusive access:

```c
static pthread_rwlock_t rwlock = PTHREAD_RWLOCK_INITIALIZER;
static Config           g_config;

void config_read(Config *out) {
    pthread_rwlock_rdlock(&rwlock);   /* shared — multiple readers OK */
    *out = g_config;
    pthread_rwlock_unlock(&rwlock);
}

void config_write(const Config *in) {
    pthread_rwlock_wrlock(&rwlock);   /* exclusive — blocks all readers */
    g_config = *in;
    pthread_rwlock_unlock(&rwlock);
}
```

Use this for: config structs, routing tables, sensor value caches — anything with a high read-to-write ratio.

---

## Thread-local storage

Per-thread state that needs no locking — each thread has its own independent copy:

```c
/* C11 standard */
static _Thread_local char tls_errbuf[256];

/* GCC/Clang extension — identical semantics */
static __thread char tls_errbuf[256];

const char *get_thread_error(void) {
    return tls_errbuf;   /* no lock needed — each thread has its own */
}
```

Use for: per-thread error buffers, scratch space, per-thread counters, and anything that would otherwise need a lock just to isolate per-thread state.

---

## Deadlock

Deadlock: two threads each hold a lock the other needs. Both block forever. The program hangs with no error message.

```c
pthread_mutex_t lock_a = PTHREAD_MUTEX_INITIALIZER;
pthread_mutex_t lock_b = PTHREAD_MUTEX_INITIALIZER;

void *thread1(void *arg) {
    pthread_mutex_lock(&lock_a);   /* acquires A */
    sleep(1);
    pthread_mutex_lock(&lock_b);   /* waits for B — thread2 holds it */
    /* DEADLOCK */
    pthread_mutex_unlock(&lock_b);
    pthread_mutex_unlock(&lock_a);
    return NULL;
}

void *thread2(void *arg) {
    pthread_mutex_lock(&lock_b);   /* acquires B */
    sleep(1);
    pthread_mutex_lock(&lock_a);   /* waits for A — thread1 holds it */
    /* DEADLOCK */
    pthread_mutex_unlock(&lock_a);
    pthread_mutex_unlock(&lock_b);
    return NULL;
}
```

**Fix 1 — consistent lock ordering**: always acquire locks in the same global order everywhere in the codebase. If every function acquires A before B, deadlock is impossible:

```c
void *thread1_fixed(void *arg) {
    pthread_mutex_lock(&lock_a);   /* always A first */
    pthread_mutex_lock(&lock_b);   /* then B */
    /* work */
    pthread_mutex_unlock(&lock_b);
    pthread_mutex_unlock(&lock_a);
    return NULL;
}
```

**Fix 2 — trylock with backoff**: release everything and retry if you can't acquire all locks atomically:

```c
while (1) {
    pthread_mutex_lock(&lock_a);
    if (pthread_mutex_trylock(&lock_b) == 0) break;   /* got both */
    pthread_mutex_unlock(&lock_a);   /* release A, retry */
    usleep(1000);
}
```

**Detecting deadlocks in practice**: compile with `-fsanitize=thread` (ThreadSanitizer). For live debugging of a hung process: `gdb ./prog` → `attach <pid>` → `thread apply all bt` — look for threads blocked in `pthread_mutex_lock`.

---

## A thread-safe work queue

The most important threading pattern in systems programming. A producer pushes work; consumers pull and process. The queue decouples production rate from consumption rate and provides natural backpressure via a capacity limit.

```c
/* workqueue.h */
#pragma once
#include <pthread.h>
#include <stddef.h>
#include <stdbool.h>

typedef struct WQItem {
    void           *data;
    struct WQItem  *next;
} WQItem;

typedef struct {
    WQItem         *head;
    WQItem         *tail;
    size_t          count;
    size_t          max_count;    /* 0 = unlimited */
    bool            shutdown;
    pthread_mutex_t lock;
    pthread_cond_t  not_empty;   /* signalled when item pushed */
    pthread_cond_t  not_full;    /* signalled when item popped */
} WorkQueue;

int   wq_init(WorkQueue *q, size_t max_count);
void  wq_destroy(WorkQueue *q);
int   wq_push(WorkQueue *q, void *data);  /* blocks if full */
void *wq_pop(WorkQueue *q);               /* blocks if empty; NULL on shutdown */
void  wq_shutdown(WorkQueue *q);          /* wake all blocked threads */
```

```c
/* workqueue.c */
#include "workqueue.h"
#include <stdlib.h>
#include <errno.h>

int wq_init(WorkQueue *q, size_t max_count) {
    q->head      = NULL;
    q->tail      = NULL;
    q->count     = 0;
    q->max_count = max_count;
    q->shutdown  = false;
    pthread_mutex_init(&q->lock,      NULL);
    pthread_cond_init(&q->not_empty,  NULL);
    pthread_cond_init(&q->not_full,   NULL);
    return 0;
}

void wq_destroy(WorkQueue *q) {
    WQItem *item = q->head;
    while (item) {
        WQItem *next = item->next;
        free(item);
        item = next;
    }
    pthread_mutex_destroy(&q->lock);
    pthread_cond_destroy(&q->not_empty);
    pthread_cond_destroy(&q->not_full);
}

int wq_push(WorkQueue *q, void *data) {
    WQItem *item = malloc(sizeof(WQItem));
    if (!item) return -ENOMEM;
    item->data = data;
    item->next = NULL;

    pthread_mutex_lock(&q->lock);

    while (q->max_count > 0 && q->count >= q->max_count && !q->shutdown) {
        pthread_cond_wait(&q->not_full, &q->lock);
    }
    if (q->shutdown) {
        pthread_mutex_unlock(&q->lock);
        free(item);
        return -1;
    }

    if (q->tail) q->tail->next = item;
    else         q->head       = item;
    q->tail = item;
    q->count++;

    pthread_cond_signal(&q->not_empty);
    pthread_mutex_unlock(&q->lock);
    return 0;
}

void *wq_pop(WorkQueue *q) {
    pthread_mutex_lock(&q->lock);

    while (q->count == 0 && !q->shutdown) {
        pthread_cond_wait(&q->not_empty, &q->lock);
    }
    if (q->count == 0) {
        pthread_mutex_unlock(&q->lock);
        return NULL;
    }

    WQItem *item = q->head;
    q->head = item->next;
    if (!q->head) q->tail = NULL;
    q->count--;

    pthread_cond_signal(&q->not_full);
    pthread_mutex_unlock(&q->lock);

    void *data = item->data;
    free(item);
    return data;
}

void wq_shutdown(WorkQueue *q) {
    pthread_mutex_lock(&q->lock);
    q->shutdown = true;
    pthread_cond_broadcast(&q->not_empty);
    pthread_cond_broadcast(&q->not_full);
    pthread_mutex_unlock(&q->lock);
}
```

Using it with one producer and two consumers:

```c
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include "workqueue.h"
#include "log.h"

static WorkQueue g_queue;

typedef struct { int device_id; float value; } SensorReading;

static void *consumer_thread(void *arg) {
    int id = *(int *)arg;
    SensorReading *r;
    while ((r = wq_pop(&g_queue)) != NULL) {
        LOG_INFO("consumer %d: device=%d val=%.2f",
                 id, r->device_id, r->value);
        free(r);
        usleep(50000);
    }
    LOG_INFO("consumer %d: done", id);
    return NULL;
}

int main(void) {
    wq_init(&g_queue, 16);   /* backpressure at 16 items */

    pthread_t c1, c2;
    int id1 = 1, id2 = 2;
    pthread_create(&c1, NULL, consumer_thread, &id1);
    pthread_create(&c2, NULL, consumer_thread, &id2);

    for (int i = 0; i < 20; i++) {
        SensorReading *r = malloc(sizeof(SensorReading));
        r->device_id = i % 3;
        r->value     = 20.0f + (float)i * 0.3f;
        wq_push(&g_queue, r);
        usleep(10000);
    }

    wq_shutdown(&g_queue);
    pthread_join(c1, NULL);
    pthread_join(c2, NULL);
    wq_destroy(&g_queue);
    return 0;
}
```

Build:

```bash
gcc -Wall -Wextra -g -fsanitize=thread -lpthread \
    -o wq main.c workqueue.c log.c errors.c
```

The `-fsanitize=thread` flag instruments every memory access and lock operation to detect data races at runtime. Run all threading code under ThreadSanitizer during development — it catches races that only manifest under specific scheduling conditions.

---

## Thread-per-connection server

Combining Day 15's socket setup with pthreads gives a clean thread-per-connection model. Simpler than the poll server; appropriate when each connection does substantial work:

```c
typedef struct {
    int                fd;
    struct sockaddr_in peer;
} ConnArgs;

static void *handle_connection(void *arg) {
    ConnArgs *ca = arg;
    int       fd = ca->fd;
    char      peer_str[INET_ADDRSTRLEN];
    inet_ntop(AF_INET, &ca->peer.sin_addr, peer_str, sizeof(peer_str));
    free(ca);   /* we own it — free before doing any work */

    LOG_INFO("thread %lu: client %s",
             (unsigned long)pthread_self(), peer_str);

    char    buf[4096];
    ssize_t n;
    while ((n = read(fd, buf, sizeof(buf))) > 0) {
        write(fd, buf, n);
    }
    close(fd);
    return NULL;
}

/* In the accept loop: */
ConnArgs *ca = malloc(sizeof(ConnArgs));
ca->fd   = cfd;
ca->peer = peer;

pthread_t t;
pthread_attr_t attr;
pthread_attr_init(&attr);
pthread_attr_setdetachstate(&attr, PTHREAD_CREATE_DETACHED);
pthread_create(&t, &attr, handle_connection, ca);
pthread_attr_destroy(&attr);
/* detached — cleans up automatically on exit */
```

---

## Day 17 exercise

1. Write the counter race condition example. Run it without the mutex ten times and observe the non-deterministic results. Add the mutex and confirm it always produces 200000. Then compile with `-fsanitize=thread` and verify ThreadSanitizer reports the race in the unprotected version.
    
2. Implement `WorkQueue` in full. Add `wq_len(WorkQueue *q)` that returns the current item count thread-safely. Test with 1 producer and 4 consumers producing 1000 items. Verify all 1000 are consumed exactly once by having each consumer maintain a local count and printing totals at exit.
    
3. Build the thread-per-connection echo server from the lesson. Connect 10 simultaneous clients with:
    
    ```bash
    for i in $(seq 10); do (echo "hello from $i" | nc localhost 7777) & done
    ```
    
    Verify each gets its echo back and that no connection is silently dropped.
    
4. Write a `RWCache` — a fixed-capacity key/value store (string key, float value, max 64 entries) protected by a reader-writer lock. Implement `rwcache_get` and `rwcache_set`. Benchmark with 8 reader threads doing 100k lookups and 2 writer threads doing 10k updates. Confirm zero races under ThreadSanitizer.
    

Day 18 covers memory-mapped files and shared memory — `mmap()`, anonymous mappings, and `shm_open` for zero-copy data passing between processes.