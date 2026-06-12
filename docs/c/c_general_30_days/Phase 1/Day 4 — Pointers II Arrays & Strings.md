
Yesterday you built the pointer mental model from first principles. Today you apply it to the two most common data structures in C: arrays and strings. This is where pointer arithmetic stops being abstract and becomes the thing you use every single day.

---

## Arrays and pointers — the real relationship

In C, the name of an array decays to a pointer to its first element in almost every context. This is not a cast, not a copy — it is a direct relationship baked into the language.

```c
int arr[5] = {10, 20, 30, 40, 50};
int *p = arr;    // no & needed — arr already decays to &arr[0]

printf("%d\n", arr[0]);   // 10
printf("%d\n", *p);       // 10 — same thing
printf("%d\n", p[1]);     // 20 — pointer indexing works identically to array indexing
printf("%d\n", *(p+1));   // 20 — these two are exactly equivalent
```

`p[i]` and `*(p+i)` are identical expressions. The compiler rewrites one into the other. There is no difference in the generated machine code. This means array indexing in C is pointer arithmetic with syntactic sugar.

The one important distinction: `arr` is not a variable. It is a fixed address baked into the binary. You cannot do `arr++` because you cannot reassign a fixed address. A pointer variable like `p` can be moved freely.

```c
p++;    // legal — p now points to arr[1]
arr++;  // compiler error — arr is not an lvalue
```

---

## What sizeof does to arrays vs pointers

This is a trap that catches everyone at least once.

```c
int arr[5] = {10, 20, 30, 40, 50};
int *p = arr;

printf("%zu\n", sizeof(arr));   // 20 — the full array: 5 * sizeof(int)
printf("%zu\n", sizeof(p));     // 8  — just the pointer itself (on 64-bit)
```

When you pass an array to a function, it decays to a pointer. The function receives that pointer and `sizeof` inside the function gives you the pointer size, not the array size. You have lost the size information. This is why C functions that operate on arrays always take a separate length parameter — there is no other way to know how many elements are in the array.

```c
void print_array(int *arr, size_t len) {
    for (size_t i = 0; i < len; i++) {
        printf("%d\n", arr[i]);
    }
}

int arr[5] = {10, 20, 30, 40, 50};
print_array(arr, 5);    // you must pass the length explicitly
```

Always pass the length. Never try to compute it from sizeof inside the receiving function. If you need both the array and its size together, wrap them in a struct — a pattern worth adopting on Day 6.

---

## Strings in C

A string in C is a sequence of `char` values in contiguous memory, terminated by a null byte — a byte with value 0, written `'\0'`. There is no string type. There is only a convention: a valid string ends with a zero byte, and every function in `<string.h>` relies on that convention.

```c
char str[] = "hello";
// stored as: 'h' 'e' 'l' 'l' 'o' '\0'
// that is 6 bytes, not 5
```

When you write a string literal like `"hello"`, the compiler allocates space for 6 bytes and fills in the null terminator automatically. The variable `str` is an array of 6 chars. If you declare the array with an explicit size, it must be large enough to include the terminator:

```c
char str[5] = "hello";   // WRONG — no room for '\0'
char str[6] = "hello";   // correct
char str[]  = "hello";   // best — let the compiler count
```

A string pointer points to the first character. The string continues until you hit a zero byte. If there is no zero byte — because you forgot it, overflowed a buffer, or corrupted memory — string functions will run past the end of your buffer and read into whatever memory follows. That is a buffer overread, and it is one of the most common security vulnerabilities in C programs.

---

## The essential string functions

All of these live in `<string.h>`. All of them operate by walking the pointer forward until they find a null terminator — which means all of them are vulnerable if you give them a buffer without one.

`strlen` returns the number of characters before the null terminator. It does not count the terminator itself.

```c
char str[] = "hello";
printf("%zu\n", strlen(str));   // 5, not 6
```

Never use `strlen` in a loop condition without storing the result first. Each call walks the entire string. In a loop it turns O(n) work into O(n²):

```c
// bad — strlen called on every iteration
for (size_t i = 0; i < strlen(str); i++) { ... }

// correct — compute once
size_t len = strlen(str);
for (size_t i = 0; i < len; i++) { ... }
```

`strcpy` copies a string including its null terminator into a destination buffer. It does not know how large the destination is. If the source is longer than the destination, it writes past the end. This is the classic buffer overflow.

```c
char src[]  = "hello world";
char dst[5];
strcpy(dst, src);   // overflow — writes 12 bytes into a 5-byte buffer
```

Use `strncpy` instead, which takes a maximum number of characters to copy. But `strncpy` has its own trap: if the source is longer than the limit, it does not write a null terminator. You must add it manually:

```c
char dst[8];
strncpy(dst, src, sizeof(dst) - 1);
dst[sizeof(dst) - 1] = '\0';    // always terminate explicitly
```

On Linux you have `strnlen` and `strlcpy` (via `<bsd/string.h>`) which are safer. In new code, consider `snprintf` for building strings — it always null-terminates:

```c
char dst[8];
snprintf(dst, sizeof(dst), "%s", src);   // always safe, always terminated
```

`strcmp` compares two strings lexicographically. It returns 0 if they are equal, negative if the first is less, positive if the first is greater. Never use `==` to compare strings — that compares pointer addresses, not contents.

```c
if (strcmp(a, b) == 0) {
    // strings are equal
}
```

`strcat` appends one string to another. Like `strcpy`, it has no size awareness. Use `strncat` or `snprintf` instead.

---

## Buffer overflows — the most classic C bug

A buffer overflow happens when you write more bytes into a buffer than it can hold. The bytes go somewhere — they overwrite whatever happens to follow the buffer in memory. On the stack, that is often the return address of the current function. Overwriting a return address lets an attacker redirect execution to arbitrary code. This is not hypothetical — it is the basis of an enormous fraction of real-world software exploits.

```c
void vulnerable(char *input) {
    char buf[16];
    strcpy(buf, input);   // if input > 15 chars, overflow
}
```

The defense is systematic: always know the size of your destination buffer, always use size-aware functions, always write the null terminator explicitly when using functions that might omit it. Compile with `-fsanitize=address` during development and it will catch overflows at runtime before they reach production.

---

## Iterating over strings with pointers

You can iterate a string with an index or with a pointer. The pointer form is idiomatic C and worth knowing:

```c
char str[] = "hello";

// index form
for (size_t i = 0; str[i] != '\0'; i++) {
    putchar(str[i]);
}

// pointer form — idiomatic C
for (char *p = str; *p != '\0'; p++) {
    putchar(*p);
}
```

Both are correct. The pointer form makes the underlying mechanism visible: advance the pointer one byte at a time, stop when you hit the zero byte. Prefer whichever is clearer in context. In embedded parsing code, the pointer form is common because you are often operating on byte streams where the "string" is not text.

---

## String literals and read-only memory

A string literal in code like `"hello"` is stored in the read-only data segment of your binary. A pointer to a string literal points into that read-only region.

```c
char *p = "hello";   // p points to read-only memory
p[0] = 'H';          // undefined behavior — may segfault
```

An array initialized from a literal is different — the compiler copies the literal into writable stack or data memory:

```c
char arr[] = "hello";   // copied to writable memory
arr[0] = 'H';           // fine — arr is a local array
```

The practical rule: if you need to modify a string, use a character array, not a pointer to a literal. If you only need to read it, a pointer to a literal is fine — but some programmers always use `const char *` for string literals to make the read-only intent explicit and get a compiler warning if they accidentally try to write.

```c
const char *p = "hello";   // explicit: this is read-only
```

---

## Two-dimensional arrays and arrays of strings

A 2D array is a contiguous block of memory laid out in row-major order:

```c
int matrix[3][4];   // 3 rows, 4 columns — 12 ints contiguous in memory
matrix[1][2] = 99;  // row 1, column 2
```

An array of strings is an array of `char` pointers, each pointing to a string:

```c
const char *names[] = {"alice", "bob", "carol"};
printf("%s\n", names[1]);    // "bob"
printf("%c\n", names[1][0]); // 'b' — index into the string itself
```

Each element of `names` is a `const char *` pointing to a string literal. The string data is not contiguous in memory — only the pointers are. This distinction matters when you write these structures to a file or transmit them over a network: you must follow each pointer and serialize the actual bytes, not the pointer values themselves.

---

## Practical exercise

Write three functions and test each one:

First, `int string_length(const char *s)` — implement `strlen` from scratch without calling the library version. Walk the pointer forward until you hit `'\0'` and count the steps.

Second, `void reverse_string(char *s)` — reverse a string in place. Use two pointers, one starting at the beginning and one at the end, swap characters and move them toward each other until they meet.

Third, `int count_words(const char *s)` — count the number of words in a string, where words are separated by spaces. Walk the string character by character and count transitions from space to non-space.

Compile with `-fsanitize=address` and test with edge cases: an empty string, a single character, a string of all spaces. The sanitizer will catch any out-of-bounds access your logic produces.

---

## What to carry forward

Arrays and strings in C are pointers and conventions, nothing more. The array name decays to a pointer. A string is a byte sequence terminated by zero. The size information is not stored anywhere — you carry it yourself as a separate variable or encode it in the terminator. Every function that writes into a buffer is your responsibility to size-check. These are not optional practices; they are what separates correct C from vulnerable C.

Tomorrow: functions and the call stack — how C manages execution flow in memory.