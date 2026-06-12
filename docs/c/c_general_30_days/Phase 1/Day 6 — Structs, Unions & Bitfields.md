
So far you have worked with individual variables and arrays of a single type. Real programs need to group related data together — a network packet has a header, a payload length, and a checksum. A sensor reading has a timestamp, a value, and a status flag. C gives you three mechanisms for this: structs, unions, and bitfields. Today you learn all three, plus the padding and alignment behavior that will bite you in embedded and protocol work if you do not understand it.

---

## Structs — grouping related data

A struct is a named collection of fields stored contiguously in memory. Each field has its own type and name. The struct as a whole has a single address — the address of its first field.

```c
#include <stdio.h>
#include <stdint.h>

struct sensor_reading {
    uint32_t timestamp;    // seconds since epoch
    float    value;        // measured value
    uint8_t  status;       // 0 = ok, 1 = error, 2 = saturated
};

int main(void) {
    struct sensor_reading r;
    r.timestamp = 1700000000;
    r.value     = 23.5f;
    r.status    = 0;

    printf("timestamp: %u\n", r.timestamp);
    printf("value:     %.1f\n", r.value);
    printf("status:    %u\n", r.status);
    printf("size:      %zu\n", sizeof(r));

    return 0;
}
```

Fields are accessed with the dot operator when you have a struct variable, and with the arrow operator when you have a pointer to a struct.

```c
struct sensor_reading *p = &r;
printf("%u\n", p->timestamp);   // arrow: pointer to struct
printf("%u\n", (*p).timestamp); // equivalent but ugly — always use ->
```

`p->field` is exactly `(*p).field`. The arrow operator exists solely to avoid writing the dereference and dot every time. Always use `->` with pointers to structs.

---

## typedef — cleaning up the syntax

Writing `struct sensor_reading` everywhere is verbose. `typedef` creates an alias:

```c
typedef struct {
    uint32_t timestamp;
    float    value;
    uint8_t  status;
} sensor_reading_t;

sensor_reading_t r;   // no struct keyword needed
```

The `_t` suffix is a common convention in C for typedef'd types. POSIX reserves some `_t` names, so in your own code some teams prefer suffixes like `_s` for structs or no suffix at all. Choose a convention and be consistent. Throughout this curriculum we use `_t` for clarity.

---

## Struct padding and alignment — critical knowledge

The compiler inserts invisible padding bytes between fields to ensure each field is naturally aligned. A `uint32_t` must start at an address divisible by 4. A `uint16_t` must start at an address divisible by 2. If the field order in your struct would violate these rules, the compiler inserts padding silently.

```c
#include <stdio.h>
#include <stdint.h>

typedef struct {
    uint8_t  a;    // 1 byte
                   // 3 bytes padding inserted here
    uint32_t b;    // 4 bytes — must be 4-byte aligned
    uint8_t  c;    // 1 byte
                   // 3 bytes padding inserted here
} padded_t;        // total: 12 bytes, not 6

typedef struct {
    uint32_t b;    // 4 bytes
    uint8_t  a;    // 1 byte
    uint8_t  c;    // 1 byte
                   // 2 bytes padding at end — struct size must be multiple of largest alignment
} packed_t;        // total: 8 bytes

int main(void) {
    printf("padded_t: %zu\n", sizeof(padded_t));   // 12
    printf("packed_t: %zu\n", sizeof(packed_t));   // 8
    return 0;
}
```

The rule for minimizing padding: order your fields from largest to smallest alignment requirement. Put `uint64_t` and `double` first, then `uint32_t` and `float`, then `uint16_t`, then `uint8_t` and `char`. The compiler still adds trailing padding to make the struct size a multiple of the largest field's alignment, but you eliminate the internal gaps.

This is not just about memory efficiency. When you cast a struct to a byte array to transmit over a network or write to a file, the receiver must have the exact same struct layout. If you add a field or reorder fields, both sides break. Padding bytes also contain garbage values, so memcmp on two structs that contain the same logical data may return non-zero because the padding differs.

---

## Packed structs for protocol work

When you need a struct to exactly match a binary wire format — a serial protocol frame, a CAN bus message, a file header — you need to suppress the compiler's padding. GCC and Clang both support `__attribute__((packed))`:

```c
typedef struct __attribute__((packed)) {
    uint8_t  start_byte;    // 1 byte
    uint16_t length;        // 2 bytes — immediately after start_byte
    uint32_t sequence;      // 4 bytes — immediately after length
    uint8_t  checksum;      // 1 byte
} protocol_frame_t;         // total: 8 bytes, no padding

printf("%zu\n", sizeof(protocol_frame_t));   // 8
```

With `__attribute__((packed))`, every field is placed at the next available byte regardless of alignment. The trade-off is performance: on some architectures unaligned accesses are slower or require the compiler to emit multiple instructions. On x86 they work but are slower. On some ARM cores an unaligned access generates a hardware fault. Use packed structs only where you are matching an external binary format, and keep them at the boundary layer — copy their fields into normal aligned structs for processing.

---

## Initializing structs

Designated initializers, introduced in C99, let you initialize fields by name. They are cleaner than positional initialization and do not break when you add fields:

```c
sensor_reading_t r = {
    .timestamp = 1700000000,
    .value     = 23.5f,
    .status    = 0,
};
```

Any field not explicitly initialized is set to zero. This is a guarantee from the C standard. Initializing a struct with `= {0}` zeroes every field:

```c
sensor_reading_t r = {0};   // all fields zero
```

This is cleaner and safer than `memset(&r, 0, sizeof(r))` for zeroing, though both are correct.

---

## Pointers to structs and dynamic allocation

Structs are almost always passed by pointer, not by value. Passing a struct by value copies every byte of it onto the stack — for a large struct that is expensive and unnecessary. Pass a pointer and you pay only for the pointer.

```c
void print_reading(const sensor_reading_t *r) {
    printf("t=%u v=%.1f s=%u\n", r->timestamp, r->value, r->status);
}

sensor_reading_t r = { .timestamp=1700000000, .value=23.5f, .status=0 };
print_reading(&r);
```

When you allocate a struct on the heap, you get a pointer:

```c
#include <stdlib.h>

sensor_reading_t *r = malloc(sizeof(sensor_reading_t));
if (r == NULL) {
    fprintf(stderr, "allocation failed\n");
    return 1;
}
r->timestamp = 1700000000;
r->value     = 23.5f;
r->status    = 0;
print_reading(r);
free(r);
```

Always use `sizeof(sensor_reading_t)` or `sizeof(*r)` — not a hardcoded number — in the `malloc` call. If you later add a field to the struct, `sizeof` picks up the change automatically.

---

## Unions — one field at a time

A union is like a struct except all fields share the same memory. The union's size is the size of its largest field. Only one field is valid at any given time — writing to one field and reading from another is generally undefined behavior, with one important exception.

```c
#include <stdio.h>
#include <stdint.h>

typedef union {
    uint32_t as_u32;
    float    as_float;
    uint8_t  bytes[4];
} word_t;

int main(void) {
    word_t w;
    w.as_float = 1.0f;

    printf("float:  %f\n",  w.as_float);
    printf("uint32: 0x%08X\n", w.as_u32);
    printf("bytes:  %02X %02X %02X %02X\n",
           w.bytes[0], w.bytes[1], w.bytes[2], w.bytes[3]);

    return 0;
}
```

This is type punning — inspecting the raw byte representation of a value by reading it through a different type. C99 officially allows reading any union member's bytes via `uint8_t` or `unsigned char`. Reading a `uint32_t` through a `float` union member is also explicitly permitted in C99 and later, making this the correct portable way to inspect floating-point bit patterns in C.

The more common use of unions in systems code is tagged unions — a struct containing a union and an enum tag that records which field is currently valid:

```c
typedef enum { MSG_TEMP, MSG_PRESSURE, MSG_STATUS } msg_type_t;

typedef struct {
    msg_type_t type;
    union {
        float    temperature;
        float    pressure;
        uint8_t  status_code;
    } data;
} message_t;

message_t m;
m.type            = MSG_TEMP;
m.data.temperature = 23.5f;

if (m.type == MSG_TEMP) {
    printf("temperature: %.1f\n", m.data.temperature);
}
```

This pattern appears in every protocol dispatcher, MQTT message handler, and sensor data pipeline you will build. The enum tag is the contract: if you write `MSG_TEMP`, you must read `.temperature`. Reading any other field is your bug.

---

## Bitfields — packing data into bits

A bitfield lets you specify the exact number of bits a struct field occupies. This is used for hardware register maps, protocol flags, and anywhere you need to pack multiple small values into a single byte or word.

```c
#include <stdio.h>
#include <stdint.h>

typedef struct {
    uint8_t present  : 1;   // 1 bit
    uint8_t writable : 1;   // 1 bit
    uint8_t priority : 3;   // 3 bits — values 0 through 7
    uint8_t reserved : 3;   // 3 bits — padding to fill the byte
} flags_t;                  // fits in 1 byte

int main(void) {
    flags_t f = {0};
    f.present  = 1;
    f.writable = 0;
    f.priority = 5;

    printf("size:     %zu\n", sizeof(f));   // 1
    printf("present:  %u\n",  f.present);
    printf("priority: %u\n",  f.priority);
    return 0;
}
```

Bitfields look clean but carry important caveats. The C standard does not specify the bit ordering within a storage unit — whether `present` occupies the most significant or least significant bit is implementation-defined. The compiler may also add padding between bitfields that cross storage unit boundaries. If you need to match a specific hardware register or protocol bit layout, verify the layout with `printf` on known values and, if necessary, use manual bit manipulation with masks and shifts instead:

```c
uint8_t flags = 0;
flags |=  (1 << 0);          // set bit 0 (present)
flags &= ~(1 << 1);          // clear bit 1 (writable)
flags |=  (5 << 2) & 0x1C;  // set bits 4:2 to value 5

uint8_t priority = (flags >> 2) & 0x07;   // extract bits 4:2
```

Manual bit manipulation is portable and unambiguous. Bitfields are convenient when you control both sides of the code and are not matching an external specification. In practice, experienced embedded developers often prefer manual manipulation for register access and bitfields for internal flags structures.

---

## Nested structs and arrays of structs

Structs can contain other structs, and arrays can hold structs. Both are common in real systems code:

```c
typedef struct {
    uint32_t seconds;
    uint32_t nanoseconds;
} timestamp_t;

typedef struct {
    timestamp_t      time;      // nested struct
    float            value;
    uint8_t          channel;
} sample_t;

sample_t buffer[256];           // array of structs — all contiguous in memory

buffer[0].time.seconds     = 1700000000;
buffer[0].time.nanoseconds = 500000000;
buffer[0].value            = 23.5f;
buffer[0].channel          = 3;
```

An array of structs lays every struct contiguously in memory — `buffer[0]` immediately followed by `buffer[1]`, and so on. This is cache-friendly for operations that process all samples in sequence, which is the common case in data pipelines.

---

## Practical exercise

Write a `packet_t` struct that models a simple serial protocol frame: a one-byte start marker (`0xAA`), a one-byte message type, a two-byte payload length, a payload buffer of up to 64 bytes, and a one-byte CRC. Use `__attribute__((packed))` and verify the size is exactly what you expect.

Then write two functions: `void pack_packet(packet_t *p, uint8_t type, const uint8_t *payload, uint16_t len)` fills in the struct fields and computes a trivial CRC as the XOR of all payload bytes. `void print_packet(const packet_t *p)` prints every field in hex.

Finally, declare a tagged union that can hold either a temperature reading (`float`), a pressure reading (`float`), or a device status code (`uint32_t`), tagged with an enum. Write a `print_message` function that switches on the tag and prints the appropriate field.

---

## What to carry forward

Structs group related data. Always pass them by pointer. Field order determines padding — order large-to-small to minimize waste. Use `__attribute__((packed))` only at binary format boundaries and be aware of alignment costs. Unions share memory between interpretations — use tagged unions to track which field is valid. Bitfields are convenient for flags but carry portability caveats for hardware register layouts.

Tomorrow: dynamic memory — the heap, malloc, free, and the discipline that keeps heap usage from destroying your program.