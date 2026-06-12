
Yesterday you built the workshop. Today you learn what C actually stores in memory when you declare a variable — and why getting this wrong silently destroys programs.

---

## The fundamental mental model

In C, a variable is a named region of memory with a fixed size and a type that tells the compiler how to interpret the bytes stored there. That's it. There is no runtime type information, no bounds checking, no garbage collector watching over you. You are directly manipulating memory, and the compiler trusts you completely.

This is C's power and its danger in the same sentence.

---

## Integer types and their sizes

```c
#include <stdio.h>

int main(void) {
    char   a = 'A';
    short  b = 1000;
    int    c = 100000;
    long   d = 1000000L;

    printf("char:  %zu bytes\n", sizeof(a));
    printf("short: %zu bytes\n", sizeof(b));
    printf("int:   %zu bytes\n", sizeof(c));
    printf("long:  %zu bytes\n", sizeof(d));

    return 0;
}
```

Run this and note the output. Now understand why the sizes matter: `int` is 4 bytes on most 32-bit and 64-bit platforms, but the C standard only guarantees it is _at least_ 16 bits. `long` is 4 bytes on 32-bit Windows and 8 bytes on 64-bit Linux. These platform differences bite you when reading binary data from a file or a serial port — you think you're reading a 4-byte field and you're actually reading 8.

The fix is `<stdint.h>`, which you should use any time the exact size matters:

```c
#include <stdint.h>

uint8_t  byte_val  = 255;
uint16_t word_val  = 65535;
uint32_t dword_val = 0xDEADBEEF;
int32_t  signed_val = -1000;
```

These types are exact on every platform. In embedded and protocol work, use them everywhere. In general application code, `int` is fine for loop counters and small arithmetic.

---

## sizeof — always measure, never assume

`sizeof` is evaluated at compile time and returns the number of bytes occupied by a type or variable. It returns a value of type `size_t`, which is the correct unsigned integer type for sizes and counts. Print it with `%zu`.

```c
printf("%zu\n", sizeof(int));        // a type
printf("%zu\n", sizeof(c));          // a variable — same result
printf("%zu\n", sizeof(uint32_t));   // an exact-width type
```

Get into the habit of using `sizeof` whenever you allocate memory, copy structs, or parse binary data. Never write the literal number 4 where you mean "the size of an int". The day you port that code to a different architecture, every hardcoded size becomes a bug.

---

## Signed vs unsigned — a quiet source of bugs

Signed integers store both positive and negative values using two's complement. Unsigned integers store only non-negative values but with twice the positive range.

```c
int8_t  signed_byte   = 127;
uint8_t unsigned_byte = 255;

signed_byte++;    // wraps to -128 — undefined behavior in C
unsigned_byte++;  // wraps to 0   — well-defined in C
```

Signed overflow is undefined behavior in C. The compiler is allowed to assume it never happens and optimize accordingly. This produces security vulnerabilities and logic bugs that are extremely hard to trace. Use unsigned types when a value is logically non-negative and you need wrapping arithmetic. Use signed types everywhere else.

The comparison trap:

```c
int len = get_length();   // returns -1 on error
size_t size = 10;

if (len < size) {         // WARNING: signed/unsigned comparison
    // if len is -1, this comparison is false
    // because -1 becomes a huge positive number when cast to size_t
}
```

This is exactly the class of bug `-Wsign-conversion` catches. Take those warnings seriously.

---

## Where variables live — stack vs heap vs data segment

Every variable in your program lives in one of three places. Understanding this is the foundation of everything that comes later.

**The stack** is a region of memory managed automatically by the CPU as functions are called and return. When you enter `main`, a stack frame is created. When you call another function, a new frame is pushed on top. When that function returns, its frame is popped and the memory is reclaimed instantly.

```c
void foo(void) {
    int x = 42;       // lives on the stack
    char buf[64];     // also stack — 64 bytes reserved in the frame
}                     // x and buf are gone the moment foo returns
```

The stack is fast and automatic, but it has two constraints: it is limited in size (typically 1–8 MB per thread), and variables on it cannot outlive the function that owns them.

**The heap** is a large pool of memory you request and release manually with `malloc` and `free`. You control its lifetime explicitly. We cover this fully on Day 7.

**The data segment** holds global and static variables. They exist for the entire duration of the program.

```c
int global_counter = 0;     // data segment — lives forever

void increment(void) {
    static int calls = 0;   // data segment — persists between calls
    calls++;
    global_counter++;
}
```

The `static` keyword on a local variable does not put it on the stack. It moves it to the data segment, meaning the variable retains its value across calls to that function. This is useful but can cause bugs in multithreaded code — all threads share the same static variable.

---

## Integer overflow — the silent destroyer

```c
#include <stdio.h>
#include <stdint.h>

int main(void) {
    uint8_t x = 250;
    x += 10;              // 260 doesn't fit in 8 bits
    printf("%u\n", x);   // prints 4 — wraps around silently

    int32_t y = 2147483647;   // INT32_MAX
    y++;                       // undefined behavior
    printf("%d\n", y);        // may print -2147483648, or anything else
    return 0;
}
```

No crash, no warning at runtime, no indication anything went wrong. The program just produces a wrong answer and keeps going. In security-sensitive code — calculating a buffer size, for instance — this is how vulnerabilities are born.

Always be conscious of the range of your type. When in doubt, use a wider type or check before the operation:

```c
if (x > UINT8_MAX - 10) {
    // handle overflow
}
x += 10;
```

---

## Characters and the char trap

`char` is either signed or unsigned depending on the platform and compiler settings. The standard leaves it unspecified. This means:

```c
char c = 200;    // may be -56 on a platform with signed char
```

If you need to store arbitrary byte values, use `uint8_t`. If you need to store a character for text processing, use `char` and use the `<ctype.h>` functions (`isalpha`, `isdigit`, etc.) to classify it. Never compare a `char` to an integer constant above 127 without thinking about signedness first.

---

## Floating point — what you need to know

```c
float  f = 3.14f;     // 32-bit, ~7 significant decimal digits
double d = 3.14;      // 64-bit, ~15 significant decimal digits
```

Floating-point numbers cannot represent most decimal fractions exactly. `0.1` in binary is a repeating fraction, just like `1/3` in decimal. This means:

```c
if (0.1 + 0.2 == 0.3)    // this is false
```

Never compare floating-point values with `==`. Compare within a tolerance:

```c
#include <math.h>
if (fabs(a - b) < 1e-9)  // "close enough" comparison
```

In embedded and IoT work you'll often avoid floating point entirely on systems without an FPU, using fixed-point arithmetic instead.

---

## Practical exercise

Write a program that declares one variable of each type: `int8_t`, `uint8_t`, `int16_t`, `uint16_t`, `int32_t`, `uint32_t`, `float`, `double`. Print the size of each with `sizeof` and `%zu`. Then deliberately overflow a `uint8_t` by assigning it the value 255 and adding 1, printing the result before and after. Observe that it wraps to 0 without any error.

Then add this to your Makefile's `CFLAGS`:

```makefile
CFLAGS = -Wall -Wextra -Werror -Wshadow -Wconversion -std=c11
```

Recompile your code and fix every warning `-Wconversion` raises. That exercise alone will teach you more about implicit type conversions than any explanation could.

---

## What to carry forward

Types in C are just interpretations of raw bytes. The compiler does not protect you from storing a value that doesn't fit, from comparing signed and unsigned values, or from reading memory as the wrong type. These are programmer responsibilities. The tools — `<stdint.h>`, `sizeof`, `-Wconversion` — exist to help you meet them.

Tomorrow: pointers. The feature that makes C what it is and trips up every beginner at least once.