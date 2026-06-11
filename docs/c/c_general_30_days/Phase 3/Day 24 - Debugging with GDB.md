

Every serious bug you encounter in C eventually requires a debugger. `printf` debugging works for simple cases but fails completely for crashes in production binaries, heisenbugs that disappear when you add print statements, and multi-threaded races. GDB gives you full visibility into a running or crashed process: where it is, what it's doing, what every variable contains, and exactly how it got there.

---

## Compiling for debugging

GDB needs debug symbols — a mapping from machine addresses back to source lines and variable names. Without them you see raw addresses and register values:

```bash
# Debug build — symbols, no optimisation
gcc -Wall -Wextra -g -O0 -o prog main.c

# -g3 includes macro definitions — useful for debugging #define constants
gcc -Wall -Wextra -g3 -O0 -o prog main.c

# Production binary with separate debug symbols
gcc -O2 -g -o prog main.c
objcopy --only-keep-debug prog prog.debug   # extract symbols
strip prog                                   # remove from binary
# GDB loads prog.debug automatically if in same directory
```

Your Makefile debug target from Day 10 already includes `-g -O0`. Always use it when debugging.

---

## Starting GDB

```bash
# Start with a program
gdb ./prog

# Start with arguments
gdb --args ./prog arg1 arg2

# Attach to a running process
gdb ./prog $(pgrep prog)
# or inside GDB:
# (gdb) attach 12345

# Load a core dump
gdb ./prog core

# Batch mode — run commands non-interactively
gdb -batch -ex "bt" ./prog core
```

---

## The essential command set

You can debug most bugs with about fifteen commands. Learn these before anything else:

```
── execution control ──────────────────────────────────────────
run [args]          Start or restart the program
r                   Shorthand for run
continue            Resume after a breakpoint (shorthand: c)
next                Step over — execute one source line (shorthand: n)
step                Step into — follow function calls (shorthand: s)
finish              Run until current function returns (shorthand: fin)
until [line]        Run until a specific line number
kill                Kill the running program

── breakpoints ────────────────────────────────────────────────
break main          Break at function entry
break sensor.c:42   Break at file:line
break *0x401234     Break at raw address
break sensor.c:42 if i == 99   Conditional breakpoint
tbreak main         Temporary — fires once then deletes itself
watch g_counter     Hardware watchpoint — break when value changes
rwatch g_counter    Break when value is read
awatch g_counter    Break on read or write
info breakpoints    List all breakpoints (shorthand: info b)
delete 3            Delete breakpoint 3
disable 3           Disable without deleting
enable 3            Re-enable

── stack inspection ───────────────────────────────────────────
backtrace           Show the call stack (shorthand: bt)
bt full             Show locals in every frame
frame 2             Switch to stack frame 2
up / down           Move up/down one frame
info locals         Show local variables in current frame
info args           Show function arguments in current frame

── variable inspection ────────────────────────────────────────
print x             Print value of expression (shorthand: p)
print *ptr          Dereference and print
print arr[0]@10     Print 10 elements of arr starting at [0]
print/x val         Print in hex
print/d val         Print as decimal
print/b val         Print as binary
print/c val         Print as character
display x           Auto-print x on every step
undisplay 1         Remove auto-display 1
info display        List auto-displays
set variable x = 5  Change a variable's value at runtime

── memory examination ─────────────────────────────────────────
x/10xb ptr          Examine 10 bytes in hex at ptr
x/4xw ptr           Examine 4 words (4 bytes each) in hex
x/8xg ptr           Examine 8 giant words (8 bytes) in hex
x/10i main          Disassemble 10 instructions at main
x/s ptr             Examine as null-terminated string

── threads ────────────────────────────────────────────────────
info threads        List all threads
thread 3            Switch to thread 3
thread apply all bt Show backtrace of every thread
thread apply all frame 2   Switch all threads to frame 2

── miscellaneous ──────────────────────────────────────────────
list                Show source code around current line
list sensor.c:40    Show source around line 40
info registers      Show CPU register values
disassemble         Disassemble current function
quit                Exit GDB (shorthand: q)
help [command]      Built-in help
```

---

## `.gdbinit` — your persistent configuration

GDB reads `~/.gdbinit` on startup. Put your common settings here:

```
# ~/.gdbinit

# Better display
set print pretty on          # pretty-print structs
set print array on           # one element per line in arrays
set print array-indexes on   # show array indices
set pagination off           # don't pause on long output

# Save command history
set history save on
set history size 1000
set history filename ~/.gdb_history

# Default to Intel syntax for disassembly
set disassembly-flavor intel

# Confirm before quitting with a running inferior
set confirm on

# Useful define — print a struct as hex bytes
define xxd
  set $i = 0
  while $i < $arg1
    printf "%02x ", *(unsigned char*)($arg0 + $i)
    set $i = $i + 1
  end
  printf "\n"
end
```

---

## Debugging a real crash — worked example

A program that crashes with a segfault. The full GDB workflow:

```c
/* buggy.c — has several deliberate bugs */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>

typedef struct {
    uint8_t id;
    char   *name;
    float   readings[8];
    int     count;
} Sensor;

Sensor *sensor_create(uint8_t id, const char *name) {
    Sensor *s = malloc(sizeof(Sensor));
    /* BUG 1: no NULL check */
    s->id    = id;
    s->name  = strdup(name);
    s->count = 0;
    return s;
}

void sensor_add_reading(Sensor *s, float val) {
    /* BUG 2: no bounds check — writes past readings[7] */
    s->readings[s->count++] = val;
}

float sensor_average(Sensor *s) {
    /* BUG 3: division by zero if count == 0 */
    float sum = 0;
    for (int i = 0; i < s->count; i++)
        sum += s->readings[i];
    return sum / s->count;
}

int main(void) {
    Sensor *s = sensor_create(1, "temperature");

    /* Add 10 readings — overflows the 8-element array */
    for (int i = 0; i < 10; i++)
        sensor_add_reading(s, 20.0f + (float)i);

    printf("average: %.2f\n", sensor_average(s));
    free(s->name);
    free(s);
    return 0;
}
```

```bash
gcc -Wall -Wextra -g -O0 -o buggy buggy.c
./buggy
# Segmentation fault (core dumped)

gdb ./buggy
```

```
(gdb) run
Starting program: /home/george/buggy

Program received signal SIGSEGV, Segmentation fault.
0x00007ffff7a3c2d1 in __printf_fp () from /lib/x86_64-linux-gnu/libc.so.6

(gdb) bt
#0  0x00007ffff7a3c2d1 in __printf_fp ()
#1  0x00007ffff7a389c3 in __vfprintf_internal ()
#2  0x00007ffff7a27bee in printf ()
#3  0x0000000000401295 in main () at buggy.c:34

(gdb) frame 3
#3  0x0000000000401295 in main () at buggy.c:34
34          printf("average: %.2f\n", sensor_average(s));

(gdb) print s
$1 = (Sensor *) 0x4052a0

(gdb) print *s
$2 = {id = 1, name = 0x405260 "temperature",
      readings = {20, 21, 22, 23, 24, 25, 26, 27},
      count = 10}

(gdb) print s->count
$3 = 10

# count is 10 but readings only has 8 slots — already corrupted
# The writes to readings[8] and readings[9] went past the struct
# and corrupted adjacent heap metadata

(gdb) break sensor_add_reading
Breakpoint 1 at 0x40119a: file buggy.c, line 23.

(gdb) run
Breakpoint 1, sensor_add_reading (s=0x4052a0, val=20) at buggy.c:23
23          s->readings[s->count++] = val;

(gdb) watch s->count
Hardware watchpoint 2: s->count

(gdb) continue
Hardware watchpoint 2: s->count
Old value = 0
New value = 1
sensor_add_reading (s=0x4052a0, val=20) at buggy.c:24

(gdb) continue 7
# ... fires 7 more times ...

Hardware watchpoint 2: s->count
Old value = 7
New value = 8
sensor_add_reading (s=0x4052a0, val=27) at buggy.c:24

(gdb) continue
Hardware watchpoint 2: s->count
Old value = 8
New value = 9
# count == 9 means we just wrote to readings[8] — one past the end
# This is the overflow

(gdb) x/16xw &s->readings
0x4052ac: 0x41a00000 0x41a80000 0x41b00000 0x41b80000
0x4052bc: 0x41c00000 0x41c80000 0x41d00000 0x41d80000
0x4052cc: 0x41e00000 0x41e80000 0x0000000a 0x00000000
#                    ^readings[8]^  ^this is s->count — corrupted
```

The watchpoint pinpointed exactly when the overflow happened. Now fix it:

```c
void sensor_add_reading(Sensor *s, float val) {
    if (s->count >= 8) {
        LOG_WARN("readings full — discarding");
        return;
    }
    s->readings[s->count++] = val;
}
```

---

## Examining memory with `x`

The `x` command is essential for understanding what's actually in memory, especially for binary protocol bugs:

```
x/[count][format][size] address

Format:
  x = hex
  d = decimal
  u = unsigned decimal
  o = octal
  t = binary
  a = address
  c = character
  s = string
  i = instruction

Size:
  b = byte (1)
  h = halfword (2)
  w = word (4)
  g = giant (8)
```

```
(gdb) x/16xb buf
0x7fffffffd9c0: 0xaa 0x01 0x04 0x00 0x14 0x00 0x1e 0x00
0x7fffffffd9c8: 0x28 0x00 0xf3 0xff 0xff 0xff 0x00 0x00

(gdb) x/4xw buf         # same data as 4 32-bit words
0x7fffffffd9c0: 0x0401aa 0x0014000 0x0028001e 0xfffff328

(gdb) x/s buf+3         # interpret offset 3 as a string
0x7fffffffd9c3: ""      # null byte there — good

(gdb) x/10i main        # disassemble first 10 instructions
0x401180 <main>:      push   rbp
0x401181 <main+1>:    mov    rbp,rsp
...
```

---

## Debugging a running process

For a daemon that's already running — a sensor reader, an MQTT client — you can attach without restarting:

```bash
# Find the PID
pgrep sensor_daemon

# Attach — this pauses the process
gdb ./sensor_daemon 12345

# Or inside GDB
(gdb) attach 12345
(gdb) info threads          # see all threads
(gdb) thread apply all bt   # backtrace every thread
(gdb) thread 2              # switch to thread 2
(gdb) continue              # resume the process
(gdb) detach                # detach without killing
```

When you attach, the process stops. All threads are frozen. Use `continue` to let it run, set breakpoints before continuing, or use `info threads` + `thread apply all bt` to get a full snapshot of what every thread is doing at that moment.

---

## Core dumps — post-mortem debugging

A core dump is a snapshot of process memory at the time of crash. You can debug it hours or days later on a different machine — invaluable for production crashes you can't reproduce:

```bash
# Enable core dumps
ulimit -c unlimited

# Set core dump filename pattern (as root or via /etc/sysctl.conf)
echo "core.%e.%p.%t" > /proc/sys/kernel/core_pattern

# Run the crashing program
./buggy
# Core dumped: creates core.buggy.12345.1700000000

# Debug the core dump
gdb ./buggy core.buggy.12345.1700000000
(gdb) bt                    # where did it crash?
(gdb) frame 3               # which frame has your code?
(gdb) info locals           # what were the local variables?
(gdb) print *s              # inspect heap-allocated structs
```

For production binaries stripped of debug symbols, keep the unstripped binary or the separate `.debug` file. The core dump alone is useless without matching debug symbols.

---

## GDB with threads — finding the bug in the Day 17 race

```bash
gdb --args ./counter_race
(gdb) run

# Program appears to hang or produce wrong output
# Ctrl+C to interrupt

(gdb) info threads
  Id   Target Id         Frame
* 1    Thread 0x... (main)   pthread_join () from libpthread
  2    Thread 0x... (t1)     increment_safe (arg=0x0) at counter.c:8
  3    Thread 0x... (t2)     increment_safe (arg=0x0) at counter.c:8

(gdb) thread apply all bt
Thread 1 (main):
#0  pthread_join () ...
#1  main () at counter.c:28

Thread 2:
#0  increment_safe (arg=0x0) at counter.c:8
#1  pthread_create () ...

Thread 3:
#0  increment_safe (arg=0x0) at counter.c:8
#1  pthread_create () ...

# Threads 2 and 3 are both in increment_safe
# Set a breakpoint in the racing function
(gdb) break counter.c:8
(gdb) continue

(gdb) thread 2
(gdb) print counter    # see current value
(gdb) next             # step thread 2 one line
(gdb) thread 3
(gdb) print counter    # see if thread 3 sees same value — race confirmed
```

---

## Conditional breakpoints for IoT bugs

The most powerful GDB feature for debugging protocol parsers and sensor readers — break only when specific conditions are met:

```bash
# Break in frame parser only when magic byte is wrong
(gdb) break frame_deserialise if magic != 0xBEEF

# Break in sensor reader only for a specific device
(gdb) break sensor_add_reading if s->id == 3

# Break after the 500th iteration of a loop
(gdb) break sensor.c:88 if i == 499

# Break when a pointer becomes NULL unexpectedly
(gdb) break process_frame if f == 0

# Break when a value goes out of range
(gdb) break temp_read if *value < -40.0 || *value > 125.0
```

Conditional breakpoints let you ignore the 499 correct iterations and stop exactly at the 500th where the bug manifests — without modifying source code.

---

## Day 24 exercise

1. Compile `buggy.c` without the fix and work through the full GDB session from the lesson. Set the watchpoint on `s->count`, observe it fire at iteration 8, and examine the memory corruption with `x/16xw`. Then apply the fix, recompile, and confirm the crash is gone.
    
2. Take the Day 17 race condition program (the unsafe counter version without the mutex). Compile it with `-g -O0`. Run it under GDB, interrupt it mid-execution with Ctrl+C, and use `thread apply all bt` to find both threads in `increment_unsafe`. Use `next` to step one thread while the other is frozen — manually demonstrate the race.
    
3. Enable core dumps with `ulimit -c unlimited`. Write a program that dereferences NULL (guaranteed segfault). Let it crash, producing a core file. Load the core in GDB and use `bt`, `frame`, and `info locals` to identify exactly which line caused the crash — without running the program again.
    
4. Write a program with a `sensor_read` function that returns bad data when `device_id == 2`. Set a conditional breakpoint: `break sensor_read if device_id == 2`. Verify GDB stops only for device 2 across 20 calls to devices 0 through 4. Then add a `commands` block that automatically prints the device_id and continues — turning it into a trace without modifying source:
    
    ```
    (gdb) commands 1
    > print device_id
    > continue
    > end
    ```
    

Day 25 covers performance profiling — `gprof`, `perf`, cache effects, and the discipline of measuring before optimising.