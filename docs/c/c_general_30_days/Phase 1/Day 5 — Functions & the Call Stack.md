

You have been writing functions since Day 1. Today you understand what actually happens in memory when a function is called — the call stack, stack frames, and how arguments travel between functions. This mental model is essential for debugging crashes, understanding recursion limits, and writing functions that correctly modify their caller's data.

---

## What happens when a function is called

When your program calls a function, the CPU and operating system cooperate to set up a new execution context. This context is called a stack frame. It contains everything the function needs to run: its local variables, the values of its parameters, and the return address — the location in the caller's code where execution should resume after the function returns.

The stack grows downward in memory on most architectures. Each function call pushes a new frame onto the top of the stack. When the function returns, its frame is popped and the memory is immediately available for the next call.

```c
#include <stdio.h>

void c(void) {
    int z = 30;
    printf("in c: z is at %p\n", (void*)&z);
}

void b(void) {
    int y = 20;
    printf("in b: y is at %p\n", (void*)&y);
    c();
}

void a(void) {
    int x = 10;
    printf("in a: x is at %p\n", (void*)&x);
    b();
}

int main(void) {
    a();
    return 0;
}
```

Run this and look at the addresses. Each function's local variable is at a lower address than the previous caller's — the stack is growing downward. When `c` returns, its frame is gone. When `b` returns, its frame is gone. By the time `main` finishes, all three frames have been pushed and popped in order.

This is why local variables cannot be returned by address. The frame they live in no longer exists after the function returns.

```c
int *broken(void) {
    int x = 42;
    return &x;    // returning address of a stack variable
}                 // x's frame is gone — the address is now garbage

int *p = broken();
printf("%d\n", *p);   // undefined behavior — reading freed stack memory
```

Compile with `-Wall -Wextra` and GCC will warn you: `warning: function returns address of local variable`. Take that warning seriously — it is telling you about a dangling pointer that will corrupt your program in ways that are difficult to trace.

---

## Pass by value — the complete picture

C passes all function arguments by value. Every argument is copied into the new stack frame. The function works with its own copies and has no connection to the original variables unless you explicitly pass pointers.

```c
#include <stdio.h>

void try_to_modify(int n) {
    n = 999;    // modifies the local copy only
}

int main(void) {
    int x = 42;
    try_to_modify(x);
    printf("%d\n", x);   // still 42 — x was never touched
    return 0;
}
```

This is not a limitation — it is a design. Pass by value gives you isolation. A function cannot accidentally modify your variables. When you want a function to modify a variable, you make that explicit by passing a pointer, and the caller sees the `&` and knows modification is possible.

```c
void actually_modify(int *n) {
    *n = 999;   // modifies the original through the pointer
}

int x = 42;
actually_modify(&x);
printf("%d\n", x);   // 999 — caller sees the & and knows x may change
```

The `&` at the call site is a signal to anyone reading the code: this variable may be modified by this function. That readability is why C programmers sometimes prefer explicit pointer parameters over languages that pass by reference invisibly.

---

## Returning multiple values

C has one return value. When a function needs to produce more than one output, you use output pointer parameters. This is not a workaround — it is the standard C pattern and you will see it everywhere.

```c
#include <stdio.h>
#include <stdbool.h>

bool divide(int a, int b, int *quotient, int *remainder) {
    if (b == 0) {
        return false;   // signal failure via return value
    }
    *quotient  = a / b;
    *remainder = a % b;
    return true;
}

int main(void) {
    int q, r;
    if (!divide(17, 5, &q, &r)) {
        fprintf(stderr, "division by zero\n");
        return 1;
    }
    printf("17 / 5 = %d remainder %d\n", q, r);
    return 0;
}
```

The return value carries the success or failure status. The output pointers carry the computed results. This separation of error signaling from result delivery is the standard C contract and you will use it throughout the curriculum.

---

## Stack frames in detail

A stack frame typically contains, from top to bottom as the compiler lays it out:

The return address — where execution resumes in the caller when this function returns. The saved frame pointer — the caller's stack frame base address, so the CPU can restore it on return. The function's local variables, allocated contiguously. Any padding the compiler adds for alignment.

The compiler decides the exact layout. You cannot rely on specific ordering of local variables in memory. What you can rely on is that each variable has a stable address for the duration of the function call, and that address becomes invalid the moment the function returns.

Stack size is limited. The default on Linux is typically 8 MB per thread. Declaring a large array as a local variable consumes stack space:

```c
void bad_idea(void) {
    int buffer[1000000];   // 4 MB on the stack — likely stack overflow
}
```

Large buffers belong on the heap via `malloc`. As a rough rule, keep local variables well under 64 KB in total per function. In embedded systems the stack may be as small as 512 bytes — there you think carefully about every local variable's size.

---

## Function declarations and the header contract

A function declaration tells the compiler the function's name, return type, and parameter types. A function definition provides the actual body. The declaration must appear before any call to the function.

```c
// declaration — compiler knows the signature
int add(int a, int b);

int main(void) {
    int result = add(3, 4);   // compiler can check argument types
    printf("%d\n", result);
    return 0;
}

// definition — the actual implementation
int add(int a, int b) {
    return a + b;
}
```

In a multi-file project, declarations go in header files and definitions go in `.c` files. The header is the contract between the function's implementation and everything that calls it. When the declaration and definition disagree — different parameter types, different return type — you get undefined behavior that the linker may not catch. This is why header files must always be included in both the `.c` file that defines the function and every `.c` file that calls it.

---

## Inline functions

The `inline` keyword suggests to the compiler that it should insert the function's code directly at the call site rather than generating an actual function call. This eliminates the overhead of setting up a stack frame, which matters in tight inner loops and interrupt handlers in embedded code.

```c
static inline int max(int a, int b) {
    return a > b ? a : b;
}
```

`static inline` in a header file is the correct pattern. `static` prevents multiple-definition linker errors when the header is included in multiple translation units. `inline` is a hint, not a command — the compiler may ignore it. Modern compilers inline small functions automatically even without the keyword when optimization is enabled.

Do not use function-like macros with `#define` to avoid function call overhead. Macros have no type safety, evaluate arguments multiple times, and produce confusing error messages. `static inline` functions give you the same performance with full type checking.

---

## Recursion and stack depth

A recursive function calls itself. Each call pushes a new frame onto the stack. Deep recursion pushes many frames and can exhaust the stack, producing a stack overflow — an immediate program crash with no recovery.

```c
#include <stdio.h>

int factorial(int n) {
    if (n <= 1) return 1;          // base case — stops the recursion
    return n * factorial(n - 1);   // recursive case
}

int main(void) {
    printf("%d\n", factorial(10));   // 3628800
    return 0;
}
```

For `factorial(10)`, the call stack is 10 frames deep. For `factorial(100000)`, it is 100,000 frames deep and will likely crash. In systems programming — daemons, embedded firmware, protocol handlers — recursion on unbounded input is dangerous. The depth is determined by input data you may not control.

The iterative version uses constant stack space regardless of input size:

```c
int factorial_iter(int n) {
    int result = 1;
    for (int i = 2; i <= n; i++) {
        result *= i;
    }
    return result;
}
```

In embedded and systems work, prefer iterative solutions for anything that processes unbounded input. Reserve recursion for cases where the depth is provably small — tree traversal on a tree of known maximum depth, for instance.

Tail call optimization is a compiler technique that converts certain recursive calls into iteration, eliminating the stack growth. GCC performs this under `-O2` when the recursive call is the last operation in the function. Do not rely on it for safety-critical code — it is an optimization, not a language guarantee.

---

## Function pointers

A function pointer stores the address of a function and allows you to call it indirectly. This is how C implements callbacks, dispatch tables, and plugin architectures.

```c
#include <stdio.h>

int add(int a, int b) { return a + b; }
int mul(int a, int b) { return a * b; }

int apply(int a, int b, int (*op)(int, int)) {
    return op(a, b);   // call through the function pointer
}

int main(void) {
    printf("%d\n", apply(3, 4, add));   // 7
    printf("%d\n", apply(3, 4, mul));   // 12
    return 0;
}
```

The declaration `int (*op)(int, int)` reads: `op` is a pointer to a function that takes two ints and returns an int. The parentheses around `*op` are required — without them, `int *op(int, int)` is a declaration of a function that returns `int *`, which is something else entirely.

Function pointers are used extensively in C for event handlers, protocol dispatch, hardware abstraction layers, and the kind of plugin loading you will encounter in embedded system firmware. You will use them from Day 27 onward when the curriculum covers dynamic libraries.

---

## The volatile keyword preview

When a function accesses a variable that can be modified outside the normal flow of execution — by an interrupt handler, by another thread, by hardware — the compiler must not cache it in a register or reorder accesses to it. The `volatile` qualifier enforces this.

```c
volatile int flag = 0;   // may be set by an interrupt handler

void wait_for_flag(void) {
    while (flag == 0) {
        // without volatile, the compiler may hoist flag into a register
        // and loop forever even after the interrupt sets it
    }
}
```

This is an embedded-specific concern covered fully on Day 27. Mention it here because it affects how you reason about function behavior in interrupt-driven and multithreaded code — contexts you will encounter regularly.

---

## Practical exercise

Write four functions:

First, `int sum_array(const int *arr, size_t len)` — returns the sum of all elements. Use a pointer walk rather than index arithmetic.

Second, `void stats(const int *arr, size_t len, int *min, int *max, double *mean)` — computes minimum, maximum, and mean in a single pass, returning all three via output pointers.

Third, `int fibonacci(int n)` — implement recursively. Test it with `n = 10`, then `n = 40`. Observe the time it takes for `n = 40` — the naive recursive Fibonacci has exponential time complexity because it recomputes the same subproblems repeatedly. Then implement `fibonacci_iter(int n)` iteratively and compare.

Fourth, write a small dispatch table: an array of function pointers where index 0 holds a pointer to an add function, index 1 to a subtract function, index 2 to a multiply function. Write a `calculate(int a, int b, int op_index)` function that uses the table to call the right operation. This pattern appears in every protocol handler and state machine you will ever write in C.

---

## What to carry forward

Every function call creates a stack frame. Local variables live in that frame and die when the function returns — never return their addresses. C passes by value always; use pointer parameters when you need to modify the caller's data or return multiple results. Recursion is bounded by stack depth, which is a finite resource. Function pointers are the foundation of callbacks, dispatch, and plugin architecture in C.

Tomorrow: structs, unions, and bitfields — the tools for organizing data in memory the way hardware and protocols actually expect it.