

Before you write a single line of C, you need to understand what actually happens between source code and a running program. Most tutorials skip this — they hand you a compile command and move on. We won't. The toolchain is your workshop; knowing your tools changes how you read errors and debug problems.

---

## The compilation pipeline

C is not interpreted. When you hit compile, four distinct stages run in sequence:

**1. Preprocessing** — the C preprocessor (`cpp`) runs first. It handles all `#include`, `#define`, and `#ifdef` directives. It's pure text substitution — it knows nothing about C syntax. The output is a single expanded `.i` file, sometimes megabytes larger than your source.

**2. Compilation** — the compiler (`cc1` internally) takes that preprocessed text and produces assembly code (`.s`). This is where syntax errors are caught, types are checked, and optimizations are applied.

**3. Assembly** — the assembler (`as`) converts assembly text into machine code, producing an object file (`.o`). The object file contains your compiled functions but with unresolved references — calls to functions in other files are left as placeholders.

**4. Linking** — the linker (`ld`) combines all `.o` files plus any libraries (`.a`, `.so`) into the final executable. It resolves those placeholders, wires up addresses, and produces the binary you actually run.

GCC runs all four stages for you with one command, but you can stop at any stage and inspect the output. You'll do exactly that in today's exercise.

---

## Setting up your environment---

## Your first C program — and what to actually look at

Create a file `hello.c`:

```c
#include <stdio.h>

int main(void) {
    printf("Hello, world\n");
    return 0;
}
```

A few things worth noting that tutorials gloss over:

`int main(void)` — not `main()` and not `main(int argc, char **argv)` unless you need arguments. `void` explicitly says "takes no parameters." In C (unlike C++), an empty parameter list `()` means "unspecified parameters" — a subtle but real difference.

`return 0` — this is the process exit code. Zero means success. The OS sees this. Your shell can check it with `$?`. This matters the moment you write anything that runs as a daemon or in a pipeline.

---

## The compile command — every flag matters

```bash
gcc -Wall -Wextra -Werror -std=c11 -o hello hello.c
```

Let's break down what each flag does in practice:

|Flag|What it does|Why it matters|
|---|---|---|
|`-Wall`|Enables most important warnings|Catches ~80% of beginner bugs at compile time|
|`-Wextra`|Additional warnings not in `-Wall`|Catches unused parameters, sign comparison issues|
|`-Werror`|Treats all warnings as errors|Forces you to fix warnings instead of ignoring them|
|`-std=c11`|Compile to C11 standard|Consistent behavior across compilers; gives you `_Bool`, atomics, `_Generic`|
|`-o hello`|Name the output binary `hello`|Without this you get `a.out` — always name your output|

**In production and embedded work**, you'll also see `-O2` (optimize) and `-g` (include debug symbols). We'll use both from Day 7 onwards.

---

## Inspecting each compilation stage

This is the exercise that builds real intuition. Run these four commands and look at each output:

```bash
# Stage 1: Preprocessing only — see what #include actually expands to
gcc -E hello.c -o hello.i
wc -l hello.i          # You'll see ~800+ lines from one #include <stdio.h>

# Stage 2: Compile to assembly — readable machine instructions
gcc -S -std=c11 hello.c -o hello.s
cat hello.s            # Find your printf call in there

# Stage 3: Assemble to object file — binary, but not yet linked
gcc -c -std=c11 hello.c -o hello.o
file hello.o           # "ELF 64-bit relocatable"

# Stage 4: Link to final binary
gcc hello.o -o hello
./hello
```

The `hello.i` output is the most surprising one. A single `#include <stdio.h>` pulls in hundreds of lines of type declarations and function prototypes. This is why large C projects with many headers compile slowly — the preprocessor is doing real work.

---

## Your first Makefile

Once you have more than one file, typing the full GCC command every time is error-prone. A Makefile automates this:

```makefile
CC     = gcc
CFLAGS = -Wall -Wextra -Werror -std=c11 -g

hello: hello.o
	$(CC) $(CFLAGS) -o hello hello.o

hello.o: hello.c
	$(CC) $(CFLAGS) -c hello.c

clean:
	rm -f hello hello.o
```

**Critical**: the indentation on action lines must be a real tab character, not spaces. This is the most common Makefile mistake. If you copy-paste and it breaks, check your editor's tab settings.

Run it:

```bash
make          # builds hello
make          # run again — "Nothing to be done" (incremental build works)
touch hello.c # simulate a file change
make          # recompiles only what changed
make clean    # removes build artifacts
```

---

## Why `-Wall -Wextra -Werror` catches real bugs

Here's an example of something the compiler will catch for you — before you ever run the program:

```c
#include <stdio.h>

int add(int a, int b) {
    int result;          // declared but never initialized
    return result;       // returns garbage — undefined behavior
}

int main(void) {
    int x = 5;
    if (x = 10) {        // assignment inside if — probably meant ==
        printf("x is 10\n");
    }
    return 0;
}
```

With `-Wall -Wextra -Werror`, this refuses to compile and tells you exactly which lines are wrong. Without those flags, it compiles silently and produces a broken program. **Always compile with warnings enabled.** In embedded and IoT work especially, silent bugs that only manifest on the target hardware are expensive to debug.

---

## Day 1 exercise

1. Install the toolchain and verify all four tools respond to `--version`
2. Write `hello.c`, compile it with all four flags, run it
3. Run the four-stage pipeline and inspect each intermediate file — find your `printf` call in the `.s` assembly output
4. Write the Makefile above, verify that `make` recompiles after `touch hello.c` and does nothing on a second `make`
5. Write the broken program above, confirm that `-Wall -Wextra -Werror` rejects it, then fix both warnings and compile clean

When you're done, Day 2 opens up the type system and the memory model — the mental framework that makes everything in C make sense.