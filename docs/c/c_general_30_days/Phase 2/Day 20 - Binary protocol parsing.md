

Every IoT system eventually deals with binary data — sensor frames over serial, MQTT payloads, custom TCP protocols, Modbus registers. The difference between code that parses binary correctly and code that almost works is understanding byte order, struct alignment, safe casting rules, and how to build a parser that handles partial data correctly. Today you build all of it.

---

## Byte order — the foundation

Multi-byte integers have two possible orderings in memory. Getting this wrong produces numbers that are silently off by factors of 256 or more.

```
Value: 0x01020304

Big-endian (network byte order):    [01] [02] [03] [04]  — MSB first
Little-endian (x86, ARM default):   [04] [03] [02] [01]  — LSB first
```

The POSIX byte-swap functions handle conversion. Use them unconditionally on every multi-byte field that crosses a wire or file boundary:

```c
#include <arpa/inet.h>   /* htons, htonl, ntohs, ntohl */
#include <endian.h>      /* htobe16, htobe32, htobe64, be16toh, etc. */

/* Network byte order (big-endian) ↔ host */
uint16_t htons(uint16_t host_short);    /* host → network 16-bit */
uint16_t ntohs(uint16_t net_short);     /* network → host 16-bit */
uint32_t htonl(uint32_t host_long);     /* host → network 32-bit */
uint32_t ntohl(uint32_t net_long);      /* network → host 32-bit */

/* Explicit endian conversions — from <endian.h> */
uint16_t htobe16(uint16_t x);   /* host → big-endian 16 */
uint16_t le16toh(uint16_t x);   /* little-endian → host 16 */
uint32_t htobe32(uint32_t x);   /* host → big-endian 32 */
uint32_t le32toh(uint32_t x);   /* little-endian → host 32 */
uint64_t htobe64(uint64_t x);   /* host → big-endian 64 */
uint64_t le64toh(uint64_t x);   /* little-endian → host 64 */
```

**The rule**: always write `htons`/`htonl` before sending, always `ntohs`/`ntohl` after receiving. Never assume. Even on a little-endian system where these are no-ops today, the explicit calls document intent and survive architecture changes.

---

## Safe struct casting from a byte buffer

Casting a `uint8_t *` directly to a struct pointer is the tempting approach. It is also undefined behaviour on architectures that require aligned access, and produces subtly wrong results with padded structs:

```c
/* WRONG — undefined behaviour, alignment trap */
uint8_t buf[64];
/* ... receive data into buf ... */
MyStruct *s = (MyStruct *)buf;   /* may fault on ARM with strict alignment */
uint32_t val = ntohl(s->field);  /* packed field may be misaligned */

/* CORRECT — memcpy into a local struct, then byte-swap fields */
MyStruct s;
memcpy(&s, buf, sizeof(s));
s.field   = ntohl(s.field);
s.counter = ntohs(s.counter);
```

`memcpy` is always safe regardless of alignment. The compiler optimises it to a single load instruction when the struct is small enough — there is no performance penalty.

---

## A complete binary frame format

Design decisions made explicit and enforced at compile time:

```c
#include <stdint.h>
#include <string.h>
#include <arpa/inet.h>
#include <assert.h>

/*
 * Wire format — all multi-byte fields are big-endian:
 *
 * Offset  Size  Field
 * ──────  ────  ─────────────────────────────────────────
 *      0     2  magic       (0xBEEF)
 *      2     1  version     (0x01)
 *      3     1  type        (see PTYPE_* constants)
 *      4     2  sequence    (monotonic counter, wraps)
 *      6     2  payload_len (0–MAX_PAYLOAD bytes)
 *      8     4  timestamp   (unix seconds)
 *     12     N  payload     (N = payload_len)
 *   12+N     2  crc16       (CRC-16/CCITT over bytes 0..12+N-1)
 *
 * Minimum frame: 14 bytes (header + empty payload + crc)
 * Maximum frame: 14 + MAX_PAYLOAD bytes
 */

#define PROTO_MAGIC      0xBEEF
#define PROTO_VERSION    0x01
#define MAX_PAYLOAD      512
#define HEADER_SIZE      12
#define FRAME_OVERHEAD   14   /* header + crc */

/* Packet types */
#define PTYPE_SENSOR     0x01
#define PTYPE_CONFIG     0x02
#define PTYPE_ACK        0x03
#define PTYPE_ERROR      0xFF

/* On-wire header — packed, no padding */
typedef struct __attribute__((packed)) {
    uint16_t magic;
    uint8_t  version;
    uint8_t  type;
    uint16_t sequence;
    uint16_t payload_len;
    uint32_t timestamp;
} WireHeader;

static_assert(sizeof(WireHeader) == HEADER_SIZE,
              "WireHeader size mismatch — check padding");

/* In-memory parsed frame — host byte order, easy to use */
typedef struct {
    uint8_t  type;
    uint16_t sequence;
    uint16_t payload_len;
    uint32_t timestamp;
    uint8_t  payload[MAX_PAYLOAD];
} Frame;
```

Two separate types — `WireHeader` for the packed on-wire representation, `Frame` for the in-memory representation — is the correct pattern. You never directly access `WireHeader` fields except during serialisation and deserialisation.

---

## CRC-16 implementation

XOR checksums from Day 19 catch single-bit errors. CRC-16 catches burst errors up to 16 bits, detects all odd-number bit errors, and is the standard for serial and embedded protocols:

```c
/*
 * CRC-16/CCITT-FALSE (polynomial 0x1021, initial value 0xFFFF)
 * Used by: XMODEM, USB, Bluetooth, many industrial protocols
 */
uint16_t crc16(const uint8_t *data, size_t len) {
    uint16_t crc = 0xFFFF;
    for (size_t i = 0; i < len; i++) {
        crc ^= (uint16_t)data[i] << 8;
        for (int j = 0; j < 8; j++) {
            if (crc & 0x8000)
                crc = (crc << 1) ^ 0x1021;
            else
                crc <<= 1;
        }
    }
    return crc;
}

/*
 * Table-driven CRC-16 — 8× faster, used in production firmware.
 * Pre-compute the table once at startup or embed it as a constant.
 */
static uint16_t crc16_table[256];

void crc16_init_table(void) {
    for (int i = 0; i < 256; i++) {
        uint16_t crc = (uint16_t)i << 8;
        for (int j = 0; j < 8; j++)
            crc = (crc & 0x8000) ? (crc << 1) ^ 0x1021 : (crc << 1);
        crc16_table[i] = crc;
    }
}

uint16_t crc16_fast(const uint8_t *data, size_t len) {
    uint16_t crc = 0xFFFF;
    for (size_t i = 0; i < len; i++)
        crc = (crc << 8) ^ crc16_table[(crc >> 8) ^ data[i]];
    return crc;
}
```

---

## Serialise and deserialise

```c
#include <stdlib.h>
#include "log.h"
#include "errors.h"

/*
 * Serialise a Frame into a flat byte buffer ready to send.
 * out_buf must be at least FRAME_OVERHEAD + frame->payload_len bytes.
 * Returns total bytes written, or -1 on error.
 */
ssize_t frame_serialise(const Frame *f, uint8_t *out_buf, size_t out_cap) {
    size_t total = FRAME_OVERHEAD + f->payload_len;
    if (total > out_cap) {
        LOG_ERROR("output buffer too small: need %zu have %zu", total, out_cap);
        return -1;
    }
    if (f->payload_len > MAX_PAYLOAD) {
        LOG_ERROR("payload_len %u exceeds MAX_PAYLOAD", f->payload_len);
        return -1;
    }

    /* Build wire header in host memory, then byte-swap */
    WireHeader hdr;
    hdr.magic       = htons(PROTO_MAGIC);
    hdr.version     = PROTO_VERSION;
    hdr.type        = f->type;
    hdr.sequence    = htons(f->sequence);
    hdr.payload_len = htons(f->payload_len);
    hdr.timestamp   = htonl(f->timestamp);

    /* Copy header, then payload */
    memcpy(out_buf, &hdr, HEADER_SIZE);
    memcpy(out_buf + HEADER_SIZE, f->payload, f->payload_len);

    /* Append CRC over everything so far */
    uint16_t crc = crc16(out_buf, HEADER_SIZE + f->payload_len);
    uint16_t crc_be = htons(crc);
    memcpy(out_buf + HEADER_SIZE + f->payload_len, &crc_be, 2);

    return (ssize_t)total;
}

/*
 * Deserialise a flat byte buffer into a Frame.
 * buf must contain at least FRAME_OVERHEAD + payload_len bytes.
 * Returns ERR_OK on success.
 */
Error frame_deserialise(const uint8_t *buf, size_t buf_len, Frame *out) {
    if (buf_len < FRAME_OVERHEAD) {
        LOG_ERROR("buffer too short for header: %zu bytes", buf_len);
        return ERR_BAD_PACKET;
    }

    /* Copy and byte-swap header */
    WireHeader hdr;
    memcpy(&hdr, buf, HEADER_SIZE);

    uint16_t magic = ntohs(hdr.magic);
    if (magic != PROTO_MAGIC) {
        LOG_ERROR("bad magic: 0x%04X", magic);
        return ERR_BAD_PACKET;
    }
    if (hdr.version != PROTO_VERSION) {
        LOG_ERROR("unsupported version: %u", hdr.version);
        return ERR_BAD_PACKET;
    }

    uint16_t payload_len = ntohs(hdr.payload_len);
    if (payload_len > MAX_PAYLOAD) {
        LOG_ERROR("payload_len %u exceeds max", payload_len);
        return ERR_BAD_PACKET;
    }

    size_t expected = FRAME_OVERHEAD + payload_len;
    if (buf_len < expected) {
        LOG_ERROR("incomplete frame: have %zu need %zu", buf_len, expected);
        return ERR_BAD_PACKET;
    }

    /* Verify CRC over header + payload */
    uint16_t rx_crc;
    memcpy(&rx_crc, buf + HEADER_SIZE + payload_len, 2);
    rx_crc = ntohs(rx_crc);

    uint16_t calc_crc = crc16(buf, HEADER_SIZE + payload_len);
    if (rx_crc != calc_crc) {
        LOG_ERROR("CRC mismatch: got 0x%04X expected 0x%04X",
                  rx_crc, calc_crc);
        return ERR_BAD_PACKET;
    }

    /* Fill output frame — all fields in host byte order */
    out->type        = hdr.type;
    out->sequence    = ntohs(hdr.sequence);
    out->payload_len = payload_len;
    out->timestamp   = ntohl(hdr.timestamp);
    memcpy(out->payload, buf + HEADER_SIZE, payload_len);

    LOG_DEBUG("frame ok: type=0x%02X seq=%u ts=%u payload=%u bytes",
              out->type, out->sequence, out->timestamp, out->payload_len);
    return ERR_OK;
}
```

---

## The streaming state machine parser

In practice, data arrives in chunks — from a TCP socket, a serial port, or a pipe. A chunk might contain half a frame, one and a half frames, or a frame boundary in the middle. The streaming parser handles all of these correctly without ever requiring a complete frame to be available:

```c
/*
 * Streaming parser — handles data arriving in arbitrary-sized chunks.
 * Feed it bytes as they arrive; it emits complete frames.
 */

typedef enum {
    PARSE_HUNT,        /* scanning for magic byte pair */
    PARSE_HEADER,      /* accumulating header bytes */
    PARSE_PAYLOAD,     /* accumulating payload bytes */
    PARSE_CRC,         /* reading 2-byte CRC */
} ParseState;

typedef struct {
    ParseState state;
    uint8_t    buf[FRAME_OVERHEAD + MAX_PAYLOAD];
    size_t     buf_pos;     /* bytes accumulated so far */
    uint16_t   payload_len; /* parsed from header, 0 until header complete */
    size_t     frame_total; /* total expected frame bytes, 0 until known */
} Parser;

void parser_init(Parser *p) {
    memset(p, 0, sizeof(*p));
    p->state = PARSE_HUNT;
}

/*
 * Callback type — called when a complete valid frame is parsed.
 */
typedef void (*FrameCallback)(const Frame *f, void *userdata);

/*
 * Feed data to the parser.
 * Returns number of bytes consumed (always == len).
 */
size_t parser_feed(Parser *p, const uint8_t *data, size_t len,
                   FrameCallback cb, void *userdata) {
    for (size_t i = 0; i < len; i++) {
        uint8_t byte = data[i];

        switch (p->state) {

        case PARSE_HUNT:
            /*
             * Looking for the two-byte magic 0xBEEF.
             * Buffer first byte; if second matches, transition to HEADER.
             */
            if (p->buf_pos == 0) {
                if (byte == 0xBE) {
                    p->buf[p->buf_pos++] = byte;
                }
                /* else: discard — not start of magic */
            } else {
                /* buf_pos == 1, buf[0] == 0xBE */
                if (byte == 0xEF) {
                    p->buf[p->buf_pos++] = byte;
                    p->state = PARSE_HEADER;
                } else if (byte == 0xBE) {
                    /* 0xBE 0xBE — keep the second as potential start */
                    p->buf[0] = byte;
                    p->buf_pos = 1;
                } else {
                    /* No match — reset */
                    p->buf_pos = 0;
                }
            }
            break;

        case PARSE_HEADER:
            p->buf[p->buf_pos++] = byte;

            if (p->buf_pos == HEADER_SIZE) {
                /* Full header accumulated — extract payload_len */
                uint16_t pl;
                memcpy(&pl, p->buf + 6, 2);   /* payload_len at offset 6 */
                p->payload_len = ntohs(pl);

                if (p->payload_len > MAX_PAYLOAD) {
                    LOG_WARN("parser: payload_len %u too large — resyncing",
                             p->payload_len);
                    parser_init(p);
                    break;
                }

                p->frame_total = FRAME_OVERHEAD + p->payload_len;

                if (p->payload_len == 0) {
                    p->state = PARSE_CRC;
                } else {
                    p->state = PARSE_PAYLOAD;
                }
            }
            break;

        case PARSE_PAYLOAD:
            p->buf[p->buf_pos++] = byte;
            if (p->buf_pos == HEADER_SIZE + p->payload_len) {
                p->state = PARSE_CRC;
            }
            break;

        case PARSE_CRC:
            p->buf[p->buf_pos++] = byte;
            if (p->buf_pos == p->frame_total) {
                /* Complete frame in buf — deserialise and emit */
                Frame f;
                if (frame_deserialise(p->buf, p->buf_pos, &f) == ERR_OK) {
                    if (cb) cb(&f, userdata);
                } else {
                    LOG_WARN("parser: frame failed validation — discarding");
                }
                parser_init(p);
            }
            break;
        }
    }
    return len;
}
```

---

## Payload parsers for common sensor types

Once you have the frame, you still need to interpret the payload. Keep these separate from the framing layer:

```c
#include <arpa/inet.h>

/*
 * Sensor data payload (PTYPE_SENSOR):
 *   [0]    device_id    uint8
 *   [1]    sensor_type  uint8  (0=temp, 1=humidity, 2=pressure)
 *   [2-3]  raw_value    uint16 big-endian
 *   [4-7]  flags        uint32 big-endian
 */

typedef struct {
    uint8_t  device_id;
    uint8_t  sensor_type;
    uint16_t raw_value;
    uint32_t flags;
} SensorData;

#define SENSOR_TEMP      0
#define SENSOR_HUMIDITY  1
#define SENSOR_PRESSURE  2

/* Scaling factors for each sensor type */
static const float sensor_scale[] = {
    [SENSOR_TEMP]     = 0.0625f,   /* raw → degrees C */
    [SENSOR_HUMIDITY] = 0.1f,      /* raw → % RH */
    [SENSOR_PRESSURE] = 0.25f,     /* raw → hPa */
};

Error parse_sensor_payload(const uint8_t *payload, uint8_t len,
                           SensorData *out) {
    if (len < 8) return ERR_BAD_PACKET;

    out->device_id   = payload[0];
    out->sensor_type = payload[1];

    uint16_t rv; memcpy(&rv, payload + 2, 2); out->raw_value = ntohs(rv);
    uint32_t fl; memcpy(&fl, payload + 4, 4); out->flags     = ntohl(fl);

    return ERR_OK;
}

float sensor_to_float(const SensorData *s) {
    if (s->sensor_type >= 3) return 0.0f;
    return (float)s->raw_value * sensor_scale[s->sensor_type];
}

void on_frame(const Frame *f, void *userdata) {
    (void)userdata;
    if (f->type != PTYPE_SENSOR) return;

    SensorData sd;
    if (parse_sensor_payload(f->payload, f->payload_len, &sd) != ERR_OK)
        return;

    float val = sensor_to_float(&sd);
    const char *unit[] = {"°C", "%RH", "hPa"};
    printf("seq=%u dev=%u %s: %.2f %s\n",
           f->sequence, sd.device_id,
           sd.sensor_type < 3 ? "reading" : "?",
           val,
           sd.sensor_type < 3 ? unit[sd.sensor_type] : "?");
}
```

---

## End-to-end test

A self-contained test that exercises the full round-trip — serialise, corrupt some frames, feed through the streaming parser, verify counts:

```c
#include <stdio.h>
#include <stdlib.h>
#include <time.h>

typedef struct { int good; int bad; } ParseStats;

static void count_frame(const Frame *f, void *ud) {
    (void)f;
    ((ParseStats *)ud)->good++;
}

int main(void) {
    crc16_init_table();

    Parser     p;
    ParseStats stats = {0};
    parser_init(&p);

    uint8_t wire[FRAME_OVERHEAD + MAX_PAYLOAD];
    int total = 100, corrupt = 10;

    for (int i = 0; i < total; i++) {
        /* Build a sensor frame */
        uint8_t payload[8] = {
            [0] = (uint8_t)(i % 4),          /* device_id */
            [1] = (uint8_t)(i % 3),          /* sensor_type */
        };
        uint16_t rv = htons((uint16_t)(1000 + i));
        memcpy(payload + 2, &rv, 2);
        uint32_t fl = htonl(0);
        memcpy(payload + 4, &fl, 4);

        Frame f = {
            .type        = PTYPE_SENSOR,
            .sequence    = (uint16_t)i,
            .payload_len = 8,
            .timestamp   = (uint32_t)time(NULL),
        };
        memcpy(f.payload, payload, 8);

        ssize_t n = frame_serialise(&f, wire, sizeof(wire));
        if (n < 0) continue;

        /* Corrupt some frames — flip a payload byte */
        if (i < corrupt) {
            wire[HEADER_SIZE + 1] ^= 0xFF;
            stats.bad++;
        }

        /* Feed to streaming parser in random-sized chunks */
        size_t pos = 0;
        while (pos < (size_t)n) {
            size_t chunk = 1 + (size_t)(rand() % 7);
            if (chunk > (size_t)n - pos) chunk = (size_t)n - pos;
            parser_feed(&p, wire + pos, chunk, count_frame, &stats);
            pos += chunk;
        }
    }

    printf("total=%d good=%d bad=%d\n", total, stats.good, stats.bad);
    printf("expected: good=%d bad=%d\n", total - corrupt, corrupt);
    return (stats.good == total - corrupt) ? 0 : 1;
}
```

---

## Day 20 exercise

1. Implement `frame_serialise` and `frame_deserialise`. Write a round-trip test: serialise 50 frames, pipe the raw bytes through a byte-shuffler that randomly swaps adjacent bytes 5% of the time, deserialise, and verify the CRC catches every corrupted frame.
    
2. Implement `parser_init` and `parser_feed`. Test with the end-to-end test program. Then test with a pathological case: feed the parser one byte at a time for an entire 100-frame stream. Verify it produces identical output to feeding it all at once.
    
3. Add a `PARSE_HUNT` stress test: build a buffer that contains 512 random bytes followed by 10 valid frames followed by 256 more random bytes. Feed it to the parser and verify it finds exactly 10 frames.
    
4. Wire the streaming parser onto the serial port from Day 19: replace `serial_read_frame` with a `parser_feed` call inside the read loop. The serial reader should now handle fragmented frames transparently. Test with the `socat` virtual port pair.
    

---

## Phase 2 complete

Days 11–20 covered the full systems programming stack: file I/O and the POSIX fd model, process creation and supervision, signals and graceful shutdown, pipes and IPC, TCP sockets, non-blocking I/O with poll, threads and synchronisation, memory-mapped files, serial communication, and binary protocol parsing.

Every one of these topics used the Phase 1 foundation daily — structs for wire formats, error handling with goto cleanup, the Makefile with sanitizers, the log module. That compounding is intentional.

Phase 3 starts with Day 21 and moves into advanced territory: epoll for high-performance I/O, lock-free programming, dynamic libraries, GDB debugging, performance profiling, secure coding, embedded C patterns, testing, cross-compilation, and a capstone that ties everything together.