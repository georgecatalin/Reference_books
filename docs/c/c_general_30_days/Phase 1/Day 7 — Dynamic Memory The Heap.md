
You have been working with stack memory, which is automatic and bounded. Today you work with the heap — memory you request explicitly, control the lifetime of manually, and are entirely responsible for releasing. The heap is where most real program data lives: buffers whose size is not known at compile time, data structures that outlive the function that created them, and allocations that must persist across many function calls. Get heap discipline right and your programs are stable. Get it wrong and you have memory leaks, corruption, and crashes that appear hours or days after the bug was introduced.

---

## The four heap functions

Everything in C heap management comes down to four functions declared in `<stdlib.h>`.

`malloc(size)` allocates exactly `size` bytes and returns a pointer to the first byte. The memory is uninitialized — it contains whatever garbage bytes were left there by previous allocations. Returns `NULL` on failure.

`calloc(count, size)` allocates `count * size` bytes and initializes every byte to zero. Useful when you need a zeroed buffer and do not want to call `memset` separately. Returns `NULL` on failure.

`realloc(ptr, new_size)` resizes a previously allocated block. It may move the block to a new location, copy the existing data, and free the old location. Returns a pointer to the resized block, or `NULL` on failure — critically, the original block is not freed on failure. Returns `NULL` on failure.

`free(ptr)` releases a previously allocated block back to the heap. After this call, `ptr` is a dangling pointer — it holds the old address but the memory is no longer yours. Using `ptr` after `free` is undefined behavior.

---

## malloc — the right way

```c
#include <stdio.h>
#include <stdlib.h>
#include <stdint.h>

int main(void) {
    size_t count = 10;
    uint32_t *arr = malloc(count * sizeof(uint32_t));
    if (arr == NULL) {
        fprintf(stderr, "allocation failed\n");
        return 1;
    }

    for (size_t i = 0; i < count; i++) {
        arr[i] = (uint32_t)(i * i);
    }

    for (size_t i = 0; i < count; i++) {
        printf("%u\n", arr[i]);
    }

    free(arr);
    arr = NULL;    // immediately null the pointer after free
    return 0;
}
```

Three habits demonstrated here that you should make automatic:

Always check the return value. Skipping the NULL check is not dangerous on a modern desktop with gigabytes of RAM until the day it is — then it is a crash with no diagnostic. In embedded systems it is dangerous on the very first allocation.

Use `sizeof` with the type, not a literal number. `malloc(count * sizeof(uint32_t))` is correct regardless of platform. `malloc(count * 4)` breaks silently if you ever change the type.

Set the pointer to NULL immediately after `free`. A pointer set to NULL produces a clean segfault if accidentally dereferenced. A dangling pointer points to memory that may have been reallocated for something else, so writing to it silently corrupts another data structure. The NULL assignment costs nothing and eliminates an entire class of hard-to-debug bugs.

---

## calloc for zeroed memory

```c
uint32_t *arr = calloc(10, sizeof(uint32_t));
if (arr == NULL) {
    fprintf(stderr, "allocation failed\n");
    return 1;
}
// all 10 elements are guaranteed zero
```

Use `calloc` when you need zeroed memory — it communicates intent clearly and is often implemented more efficiently than `malloc` followed by `memset` because the operating system may already provide zeroed pages.

One subtle difference: `calloc(count, size)` checks for integer overflow in the multiplication internally. `malloc(count * size)` does not — if `count * size` overflows `size_t`, you allocate a tiny buffer and write far past its end. For large allocations where overflow is possible, prefer `calloc`.

---

## realloc — growing a buffer

The canonical use of `realloc` is implementing a dynamic array — a buffer that grows as you append to it.

```c
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct {
    uint32_t *data;
    size_t    len;
    size_t    cap;
} vec_t;

int vec_push(vec_t *v, uint32_t value) {
    if (v->len == v->cap) {
        size_t new_cap = v->cap == 0 ? 8 : v->cap * 2;
        uint32_t *tmp = realloc(v->data, new_cap * sizeof(uint32_t));
        if (tmp == NULL) {
            return -1;    // original v->data still valid — realloc did not free it
        }
        v->data = tmp;
        v->cap  = new_cap;
    }
    v->data[v->len++] = value;
    return 0;
}

void vec_free(vec_t *v) {
    free(v->data);
    v->data = NULL;
    v->len  = 0;
    v->cap  = 0;
}

int main(void) {
    vec_t v = {0};

    for (uint32_t i = 0; i < 20; i++) {
        if (vec_push(&v, i * i) != 0) {
            fprintf(stderr, "push failed\n");
            vec_free(&v);
            return 1;
        }
    }

    for (size_t i = 0; i < v.len; i++) {
        printf("%u\n", v.data[i]);
    }

    vec_free(&v);
    return 0;
}
```

The critical detail in `vec_push`: the result of `realloc` is stored in a temporary pointer `tmp`, not directly in `v->data`. If `realloc` fails and returns `NULL`, `v->data` still points to the original allocation. Writing `v->data = realloc(v->data, new_size)` is a memory leak — if realloc fails, you have lost the only pointer to the original block and cannot free it.

The doubling growth strategy — `new_cap = cap * 2` — gives amortized O(1) appends. Each element is moved at most once on average across all appends. This is the same strategy used by dynamic arrays in every language.

---

## Memory leaks — how they happen

A memory leak is an allocation whose pointer is lost before `free` is called. The memory remains reserved by your process for its entire lifetime, unavailable for any other use.

```c
void leaky(void) {
    uint32_t *p = malloc(64 * sizeof(uint32_t));
    // ... do some work ...
    return;   // p goes out of scope — the 256 bytes are leaked
}
```

In a program that runs briefly and exits, leaks are harmless — the OS reclaims all memory on exit. In a daemon or embedded firmware that runs for weeks or months, leaks accumulate until the process exhausts available memory and crashes or the system becomes unresponsive. In production IoT firmware that cannot be easily updated, a slow leak is a serious reliability problem.

The less obvious leak: losing a pointer by overwriting it before freeing.

```c
uint32_t *p = malloc(64 * sizeof(uint32_t));
p = malloc(128 * sizeof(uint32_t));   // first allocation leaked — pointer overwritten
```

The early return leak: a function allocates memory, then hits an error condition and returns early without freeing.

```c
int process(void) {
    uint8_t *buf = malloc(1024);
    if (buf == NULL) return -1;

    if (open_file() != 0) {
        return -1;    // leak — buf not freed
    }

    if (read_data(buf) != 0) {
        return -1;    // leak — buf not freed
    }

    free(buf);
    return 0;
}
```

The correct pattern uses a single exit point with cleanup:

```c
int process(void) {
    int result = -1;
    uint8_t *buf = malloc(1024);
    if (buf == NULL) goto done;

    if (open_file() != 0)  goto done;
    if (read_data(buf) != 0) goto done;

    result = 0;

done:
    free(buf);    // always runs — free(NULL) is safe and does nothing
    return result;
}
```

`free(NULL)` is defined to do nothing. This means you can call `free` unconditionally on a pointer that was initialized to NULL and only set if allocation succeeded. The goto cleanup pattern is idiomatic in systems C and you will see it in the Linux kernel and most professional C codebases.

---

## Double free and use-after-free

A double free happens when you call `free` on the same pointer twice. The heap's internal metadata is corrupted, typically causing a crash or silent data corruption at a completely unrelated point in the program.

```c
uint32_t *p = malloc(sizeof(uint32_t));
free(p);
free(p);   // undefined behavior — heap corruption
```

Setting the pointer to NULL after free prevents this:

```c
free(p);
p = NULL;
free(p);   // free(NULL) is safe — no-op
```

Use-after-free means reading or writing through a pointer after its memory has been freed. The freed memory may have been reallocated for a completely different purpose by the time you access it. You may be reading data from a different allocation or corrupting an unrelated data structure. This class of bug is particularly difficult to diagnose because the symptom appears far from the cause.

```c
uint32_t *p = malloc(sizeof(uint32_t));
*p = 42;
free(p);
printf("%u\n", *p);   // use-after-free — undefined behavior
                      // may print 42, may print garbage, may crash
```

---

## Valgrind and AddressSanitizer

Two tools catch heap errors that your eyes miss.

Valgrind instruments your binary at the instruction level and tracks every allocation and free. It reports leaks, double frees, use-after-free, and reads of uninitialized memory. It slows execution significantly — typically 10 to 50 times — making it unsuitable for performance testing but invaluable for correctness testing.

```bash
gcc -g -o program program.c
valgrind --leak-check=full --track-origins=yes ./program
```

AddressSanitizer is a compiler instrumentation pass that catches the same classes of errors at much lower overhead — typically 2x slowdown. It is built into GCC and Clang.

```bash
gcc -g -fsanitize=address -fsanitize=undefined -o program program.c
./program
```

Use AddressSanitizer during all development and testing. Run Valgrind on suspicious code or when you need leak detection in a test suite. Neither tool replaces careful discipline — they catch errors after they happen, not before.

---

## Heap fragmentation

When you allocate and free many blocks of varying sizes over time, the heap can become fragmented — there is enough total free memory to satisfy a request, but no single contiguous free block is large enough. This is rare in short-lived programs but common in long-running daemons and embedded firmware.

Mitigation strategies: allocate large buffers once at startup and reuse them. Use a pool allocator for fixed-size objects — allocate a large block once, subdivide it into equal-sized chunks, and hand out chunks from a free list. For embedded systems with no dynamic allocator at all, allocate everything statically at compile time.

```c
// simple fixed-size pool allocator concept
#define POOL_SIZE 32
#define BLOCK_SIZE 64

static uint8_t pool_memory[POOL_SIZE * BLOCK_SIZE];
static uint8_t pool_used[POOL_SIZE];

void *pool_alloc(void) {
    for (int i = 0; i < POOL_SIZE; i++) {
        if (!pool_used[i]) {
            pool_used[i] = 1;
            return &pool_memory[i * BLOCK_SIZE];
        }
    }
    return NULL;   // pool exhausted
}

void pool_free(void *ptr) {
    int i = ((uint8_t*)ptr - pool_memory) / BLOCK_SIZE;
    pool_used[i] = 0;
}
```

This gives you O(n) allocation in the worst case, but zero fragmentation, no heap overhead, and completely deterministic memory usage — essential properties in safety-critical embedded firmware.

---

## Practical exercise

Build on the dynamic array from the vec_t example. Extend it with these functions:

`int vec_insert(vec_t *v, size_t index, uint32_t value)` — inserts a value at the given index, shifting subsequent elements right. Grow the backing array if needed.

`void vec_remove(vec_t *v, size_t index)` — removes the element at the given index, shifting subsequent elements left. Do not reallocate.

`void vec_shrink(vec_t *v)` — resizes the backing allocation to exactly `v->len` elements using `realloc`, freeing unused capacity.

Test every function with Valgrind or AddressSanitizer. Deliberately introduce a double-free and a use-after-free, run under AddressSanitizer, and read the error output carefully. Understanding that output is a skill you will use every time you debug a heap error in production.

---

## What to carry forward

The heap gives you memory whose lifetime you control. That control is entirely your responsibility. Every `malloc` must have exactly one matching `free`. Check every allocation for NULL. Never overwrite a pointer without freeing what it points to. Set pointers to NULL after freeing them. Use AddressSanitizer in development without exception. These disciplines are not optional caution — they are the minimum standard for correct C programs that manage dynamic memory.

Tomorrow: the preprocessor and header files — the mechanism that organizes C programs across multiple files.