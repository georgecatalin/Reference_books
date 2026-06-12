

If there is one day in this curriculum where you slow down and re-read until it clicks, this is that day. Pointers are not complicated, but they are precise. One wrong assumption and your program corrupts memory silently, crashes unpredictably, or produces wrong results with no error message. Get the mental model right today and everything else in C becomes straightforward.

---

## What a pointer actually is

A pointer is a variable that stores a memory address. That's the complete definition. Nothing magical, nothing abstract — it's an integer that happens to represent a location in your process's address space.

When you declare `int x = 42`, the compiler reserves 4 bytes somewhere in memory and stores the value 42 there. That location has an address — a number like `0x7ffee4b2c`. A pointer variable stores that number.

```c
int  x = 42;       // an integer variable
int *p = &x;       // a pointer variable storing the address of x
```

The `&` operator means "give me the address of". The `*` in the declaration means "this variable holds an address of an int". These two uses of `*` — in declarations and in expressions — mean different things and you need to keep them separate in your head.

---

## The two operators: & and *

`&` is the address-of operator. Applied to a variable, it gives you the memory address where that variable lives.

`*` in an expression is the dereference operator. Applied to a pointer, it gives you the value at the address the pointer holds. You are following the pointer to what it points at.

```c
#include <stdio.h>

int main(void) {
    int  x = 42;
    int *p = &x;

    printf("value of x:       %d\n",  x);   // 42
    printf("address of x:     %p\n",  (void*)&x);  // e.g. 0x7ffee4b2c
    printf("value of p:       %p\n",  (void*)p);   // same address
    printf("value at *p:      %d\n",  *p);  // 42 — following the pointer

    *p = 100;    // write through the pointer
    printf("x is now:         %d\n",  x);   // 100 — x changed via p

    return 0;
}
```

Study this output carefully. `p` and `&x` are the same value — the same address. `*p` and `x` refer to the same memory. Writing to `*p` is writing to `x`. They are two names for the same location.

---

## Pointer types and what they mean

Every pointer has a type. `int *p` is a pointer to int. `char *p` is a pointer to char. The type tells the compiler two things: how many bytes to read or write when you dereference it, and how far to move when you do pointer arithmetic.

```c
int    x = 42;
int   *pi = &x;    // pointer to int  — reads 4 bytes on dereference
char  *pc = (char*)&x;  // pointer to char — reads 1 byte on dereference

printf("%d\n",  *pi);   // 42
printf("%d\n",  *pc);   // the first byte of x's 4-byte representation
```

The type of a pointer is not stored at runtime. When you cast a pointer to a different type, the compiler simply changes how it interprets the bytes at that address. This is powerful for protocol parsing and register access in embedded work, and dangerous if misused.

---

## NULL — what it is and why you always check it

`NULL` is a pointer value that means "points to nothing". Its numeric value is 0. It is not a valid memory address for any object in your program.

Functions that return pointers use `NULL` to signal failure. `malloc` returns `NULL` when allocation fails. `fopen` returns `NULL` when the file doesn't exist. If you dereference a `NULL` pointer, your program receives a segmentation fault and terminates. That's actually the good outcome — at least it crashes visibly. The worse outcome is dereferencing a pointer that is not NULL but points to garbage, which corrupts memory without any immediate signal.

The rule is simple and absolute: every pointer returned from a function must be checked before use.

```c
#include <stdlib.h>
#include <stdio.h>

int main(void) {
    int *p = malloc(sizeof(int));
    if (p == NULL) {
        fprintf(stderr, "allocation failed\n");
        return 1;
    }

    *p = 42;
    printf("%d\n", *p);
    free(p);
    return 0;
}
```

You will be tempted to skip this check because "it never fails on a modern machine." Resist that. In embedded systems memory is scarce and allocations fail. In long-running daemons memory leaks until allocation fails. Write the check every time until it is muscle memory.

---

## Pointer arithmetic

When you add an integer to a pointer, the pointer advances by that many elements of its type — not by that many bytes.

```c
int arr[4] = {10, 20, 30, 40};
int *p = arr;     // points to arr[0]

printf("%d\n", *p);       // 10
p++;                       // advance by sizeof(int) bytes — now points to arr[1]
printf("%d\n", *p);       // 20
printf("%d\n", *(p+1));   // 30 — p itself unchanged
```

If `p` is an `int *` and `sizeof(int)` is 4, then `p + 1` adds 4 to the raw address. If `p` is a `char *`, then `p + 1` adds 1. The compiler handles the scaling for you. This is why pointer type matters for arithmetic.

Pointer arithmetic is only defined within the bounds of an array (including one past the last element). Arithmetic that moves a pointer outside those bounds is undefined behavior, even if you never dereference it.

---

## Pointers as function parameters

This is the most important practical use of pointers. C passes all function arguments by value — the function receives a copy. If you want a function to modify a variable in the caller, you pass a pointer to that variable.

```c
#include <stdio.h>

void increment(int *n) {
    *n = *n + 1;    // modify the original via the pointer
}

int main(void) {
    int x = 10;
    increment(&x);           // pass the address of x
    printf("%d\n", x);       // 11 — x was modified
    return 0;
}
```

Without the pointer, `increment` would receive a copy of `x`, increment the copy, and throw it away. The original `x` in `main` would be unchanged. This is the single most common reason beginners write functions that "don't work" — they forget to pass by pointer when they need the caller's variable modified.

The same mechanism is used to return multiple values from a function, since C only has one return value:

```c
void divide(int a, int b, int *quotient, int *remainder) {
    *quotient  = a / b;
    *remainder = a % b;
}

int q, r;
divide(17, 5, &q, &r);
// q == 3, r == 2
```

---

## Wild pointers and uninitialized pointers

An uninitialized pointer holds whatever garbage bytes happen to be in memory at that location. Dereferencing it is undefined behavior — the program may crash, silently corrupt data, or appear to work correctly and fail later.

```c
int *p;        // uninitialized — contains garbage
*p = 42;       // undefined behavior — writing to a random address
```

A wild pointer is a pointer that previously pointed to valid memory but no longer does — typically because the memory was freed or the variable it pointed to went out of scope.

```c
int *p = malloc(sizeof(int));
free(p);
*p = 42;    // use-after-free — undefined behavior
            // the memory may have been given to something else
```

The discipline is: initialize every pointer when you declare it. If you don't have a valid address yet, initialize to `NULL`. Then you get a clean crash on dereference instead of silent corruption.

```c
int *p = NULL;    // explicit: this pointer points to nothing yet

// ... later, when you have a valid address:
p = &some_variable;
```

---

## The const qualifier with pointers

`const` with pointers has two positions and they mean different things:

```c
const int *p = &x;    // pointer to const int
                      // you cannot modify *p, but you can move p

int * const p = &x;   // const pointer to int
                      // you can modify *x, but you cannot move p

const int * const p = &x;  // const pointer to const int
                            // neither the pointer nor the value can change
```

The practical rule: if a function takes a pointer and does not need to modify what it points to, declare the parameter `const`. This documents intent, prevents accidental writes, and lets the compiler warn you if you violate it.

```c
void print_value(const int *p) {
    printf("%d\n", *p);
    // *p = 42;   -- compiler error, p is const
}
```

---

## Practical exercise

Write a program with three functions:

First, `swap(int *a, int *b)` — exchanges the values of two integers by working through pointers. Call it with two variables and print their values before and after to confirm the swap worked.

Second, `minmax(int *arr, int len, int *min, int *max)` — finds the minimum and maximum values in an array and writes them into the provided output pointers. Test it with an array of five values.

Third, deliberately write an uninitialized pointer bug, compile with `-fsanitize=address`, run the program, and read the sanitizer output. Understanding how to read that output is a skill you'll use constantly.

```bash
gcc -Wall -Wextra -Werror -fsanitize=address -g -o day3 day3.c
./day3
```

The `-g` flag includes debug information so the sanitizer can show you file names and line numbers. The `-fsanitize=address` flag instruments your binary to detect memory errors at runtime. Keep both flags during development — remove only for production builds.

---

## What to carry forward

A pointer is an address. `&` gives you an address. `*` follows an address to the value. Pointer type controls how many bytes are read and how arithmetic scales. Always initialize pointers, always check pointers returned from functions, and always use `-fsanitize=address` during development to catch what your eyes miss.

Tomorrow: pointers applied to arrays and strings — where most real C code lives.