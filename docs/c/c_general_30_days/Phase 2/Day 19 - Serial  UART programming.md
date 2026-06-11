
Serial communication is the backbone of embedded and IoT work. Every sensor, microcontroller, GPS module, and industrial device that doesn't have Ethernet speaks UART at some level. On Linux, serial ports appear as `/dev/ttyUSB0`, `/dev/ttyS0`, or `/dev/ttyACM0` — they're just file descriptors, but configured through the `termios` API rather than socket options.

---

## What UART actually is

UART (Universal Asynchronous Receiver-Transmitter) sends data one bit at a time over a single wire in each direction. There's no clock wire — both sides agree on the bit rate (baud rate) in advance. A frame consists of a start bit, 5–8 data bits, an optional parity bit, and 1–2 stop bits. The most common configuration by far is 8N1: 8 data bits, no parity, 1 stop bit.

```
idle  start  D0  D1  D2  D3  D4  D5  D6  D7  stop  idle
 ─────┐      ┌───┐   ┌───────┐   ┌───┐       ┌─────
      └──────┘   └───┘       └───┘   └───────┘
      1 bit   <──── 8 data bits ────>         1 bit
```

The voltage levels (3.3V, 5V, RS-232's ±12V) don't affect the software API — the USB-serial adapter or UART hardware handles that. Your code sees bytes.

---

## The termios API

`termios` is the POSIX interface for configuring serial ports and terminals. It's old, slightly arcane, and the only correct way to do this on Linux.

```c
#include <termios.h>
#include <fcntl.h>
#include <unistd.h>
#include <errno.h>
#include <string.h>
#include <stdint.h>
#include "log.h"
#include "errors.h"

/*
 * Open and configure a serial port.
 *
 * path:     e.g. "/dev/ttyUSB0"
 * baudrate: B9600, B115200, B230400, etc. (termios constants)
 *
 * Returns fd on success, -1 on failure.
 */
int serial_open(const char *path, speed_t baudrate) {
    /*
     * O_RDWR   — read and write
     * O_NOCTTY — don't make this the process's controlling terminal
     * O_CLOEXEC — close on exec (Day 12 pattern)
     */
    int fd = open(path, O_RDWR | O_NOCTTY | O_CLOEXEC);
    if (fd < 0) {
        LOG_ERRNO("open");
        return -1;
    }

    /* Read current settings */
    struct termios tty;
    if (tcgetattr(fd, &tty) < 0) {
        LOG_ERRNO("tcgetattr");
        close(fd);
        return -1;
    }

    /* ── input flags ─────────────────────────────────────────── */
    tty.c_iflag &= ~IGNBRK;    /* don't ignore break condition */
    tty.c_iflag &= ~BRKINT;    /* break doesn't flush input    */
    tty.c_iflag &= ~PARMRK;    /* don't mark parity errors     */
    tty.c_iflag &= ~ISTRIP;    /* don't strip high bit         */
    tty.c_iflag &= ~INLCR;     /* don't map NL to CR           */
    tty.c_iflag &= ~IGNCR;     /* don't ignore CR              */
    tty.c_iflag &= ~ICRNL;     /* don't map CR to NL           */
    tty.c_iflag &= ~IXON;      /* disable XON/XOFF flow ctrl   */
    tty.c_iflag &= ~IXOFF;
    tty.c_iflag &= ~IXANY;     /* no resume on any char        */

    /* ── output flags ────────────────────────────────────────── */
    tty.c_oflag &= ~OPOST;     /* raw output — no processing   */

    /* ── control flags ───────────────────────────────────────── */
    tty.c_cflag &= ~PARENB;    /* no parity                    */
    tty.c_cflag &= ~CSTOPB;    /* 1 stop bit                   */
    tty.c_cflag &= ~CSIZE;     /* clear data size bits         */
    tty.c_cflag |=  CS8;       /* 8 data bits                  */
    tty.c_cflag &= ~CRTSCTS;   /* no hardware flow control     */
    tty.c_cflag |=  CREAD;     /* enable receiver              */
    tty.c_cflag |=  CLOCAL;    /* ignore modem status lines    */

    /* ── local flags ─────────────────────────────────────────── */
    tty.c_lflag &= ~ICANON;    /* raw mode — no line buffering */
    tty.c_lflag &= ~ECHO;      /* no echo                      */
    tty.c_lflag &= ~ECHOE;
    tty.c_lflag &= ~ECHONL;
    tty.c_lflag &= ~ISIG;      /* no signal generation (^C etc)*/

    /* ── baud rate ───────────────────────────────────────────── */
    cfsetispeed(&tty, baudrate);
    cfsetospeed(&tty, baudrate);

    /* ── read behaviour: blocking with timeout ───────────────── */
    /*
     * VMIN=0, VTIME=10:
     *   read() returns when data arrives OR after 1.0 second (VTIME × 0.1s)
     *   This is the "read with timeout" mode — ideal for protocol parsing.
     *
     * VMIN=1, VTIME=0:  block until at least 1 byte arrives (no timeout)
     * VMIN=0, VTIME=0:  non-blocking — return immediately with 0 bytes if empty
     * VMIN=N, VTIME=0:  block until exactly N bytes available
     */
    tty.c_cc[VMIN]  = 0;
    tty.c_cc[VTIME] = 10;   /* 1.0 second timeout */

    /* Apply settings — TCSANOW: immediately, no wait */
    if (tcsetattr(fd, TCSANOW, &tty) < 0) {
        LOG_ERRNO("tcsetattr");
        close(fd);
        return -1;
    }

    /* Flush any stale data in the kernel buffers */
    tcflush(fd, TCIOFLUSH);

    LOG_INFO("opened %s at baud %u", path, (unsigned)baudrate);
    return fd;
}

void serial_close(int fd) {
    if (fd >= 0) close(fd);
}
```

The flags above configure **raw mode** — the port behaves like a pipe of bytes with no interpretation. This is what you always want for binary protocols and most ASCII protocols. The alternative, canonical mode, processes input line by line — useful only when you're emulating a terminal.

---

## The four `VMIN`/`VTIME` read modes

This is the most misunderstood part of serial programming. `VMIN` and `VTIME` together define when `read()` returns:

|VMIN|VTIME|read() returns when|
|---|---|---|
|0|0|Immediately — 0 bytes if nothing available|
|1|0|At least 1 byte arrives (blocks indefinitely)|
|N|0|At least N bytes available (blocks indefinitely)|
|0|T|Data arrives OR T×0.1s elapses (whichever first)|
|N|T|N bytes arrive OR T×0.1s after first byte|

For protocol work, `VMIN=0, VTIME=10` (1 second) is the most practical — you get data when it arrives and a timeout when the device goes silent, without a separate timer or non-blocking loop.

---

## Reading with a timeout loop

A single `read()` call won't necessarily return a complete frame — the device may send it in pieces, or less data than requested may arrive before the timeout fires. Use `read_full` logic adapted for serial's timeout semantics:

```c
#include <stdint.h>
#include <string.h>

/*
 * Read exactly `len` bytes from a serial port.
 *
 * Returns:
 *   len    — success, buffer filled
 *   0      — timeout before any data
 *  -1      — error (check errno)
 *  < len   — partial read — device stopped sending mid-frame
 */
ssize_t serial_read_exact(int fd, void *buf, size_t len,
                          int max_retries) {
    uint8_t *ptr   = buf;
    size_t   total = 0;
    int      retries = 0;

    while (total < len) {
        ssize_t n = read(fd, ptr + total, len - total);

        if (n < 0) {
            if (errno == EINTR) continue;
            LOG_ERRNO("serial read");
            return -1;
        }

        if (n == 0) {
            /* Timeout — no data in this window */
            if (total == 0) return 0;   /* nothing received at all */

            retries++;
            if (retries >= max_retries) {
                LOG_WARN("partial frame: got %zu of %zu bytes", total, len);
                return (ssize_t)total;
            }
            continue;
        }

        total += (size_t)n;
        retries = 0;
    }

    return (ssize_t)total;
}

/*
 * Write bytes to serial — handles short writes.
 */
ssize_t serial_write(int fd, const void *buf, size_t len) {
    const uint8_t *ptr   = buf;
    size_t         total = 0;

    while (total < len) {
        ssize_t n = write(fd, ptr + total, len - total);
        if (n < 0) {
            if (errno == EINTR) continue;
            LOG_ERRNO("serial write");
            return -1;
        }
        total += (size_t)n;
    }

    /*
     * tcdrain() blocks until all written bytes have been transmitted.
     * Important when timing matters — e.g. before expecting a response.
     */
    tcdrain(fd);
    return (ssize_t)total;
}
```

---

## Binary protocol framing over serial

Raw serial is a byte stream with no concept of messages. Your protocol must provide framing — a way to find message boundaries in the stream. Three common approaches:

**1. Fixed-length frames** — simplest. Every message is exactly N bytes. Works when you control both ends.

**2. Start byte + length field** — a known magic byte starts every frame, followed by a length byte telling you how many more bytes to read.

**3. Delimiter framing** — a special byte (0x00, 0x0A, or a COBS-encoded boundary) marks the end of each message.

Here's a complete implementation of approach 2, which is what most embedded sensors use:

```c
#include <stdint.h>
#include <string.h>
#include <stdlib.h>
#include "log.h"
#include "errors.h"

#define FRAME_MAGIC    0xAA
#define FRAME_MAX_PAYLOAD 128

/*
 * Wire format:
 *   [0]     magic byte  (0xAA)
 *   [1]     frame type  (0x01 = sensor data, 0x02 = ack, 0xFF = error)
 *   [2]     payload_len (0–128)
 *   [3..N]  payload
 *   [N+1]   checksum    (XOR of bytes 0..N)
 */

typedef struct __attribute__((packed)) {
    uint8_t magic;
    uint8_t type;
    uint8_t payload_len;
} FrameHeader;

typedef struct {
    uint8_t type;
    uint8_t payload[FRAME_MAX_PAYLOAD];
    uint8_t payload_len;
} Frame;

/* Frame types */
#define FTYPE_SENSOR  0x01
#define FTYPE_ACK     0x02
#define FTYPE_ERROR   0xFF

static uint8_t checksum(const uint8_t *data, size_t len) {
    uint8_t xor = 0;
    for (size_t i = 0; i < len; i++) xor ^= data[i];
    return xor;
}

/*
 * Read one complete frame from the serial port.
 * Scans for magic byte, reads header, reads payload, validates checksum.
 *
 * Returns ERR_OK on success, ERR_BAD_PACKET on checksum/magic failure,
 * ERR_IO on read error, ERR_TIMEOUT if nothing arrives.
 */
Error serial_read_frame(int fd, Frame *out) {
    uint8_t byte;

    /* Scan for magic byte — re-sync after noise or partial frames */
    int sync_attempts = 0;
    do {
        ssize_t n = read(fd, &byte, 1);
        if (n < 0)  return ERR_IO;
        if (n == 0) return ERR_TIMEOUT;   /* VTIME elapsed */
        if (++sync_attempts > 256) {
            LOG_WARN("lost sync — no magic byte in 256 bytes");
            return ERR_BAD_PACKET;
        }
    } while (byte != FRAME_MAGIC);

    /* Read the rest of the header (type + payload_len) */
    uint8_t hdr_tail[2];
    ssize_t n = serial_read_exact(fd, hdr_tail, 2, 3);
    if (n != 2) return n == 0 ? ERR_TIMEOUT : ERR_IO;

    uint8_t type        = hdr_tail[0];
    uint8_t payload_len = hdr_tail[1];

    if (payload_len > FRAME_MAX_PAYLOAD) {
        LOG_ERROR("payload_len=%u exceeds max", payload_len);
        return ERR_BAD_PACKET;
    }

    /* Read payload */
    uint8_t payload[FRAME_MAX_PAYLOAD];
    if (payload_len > 0) {
        n = serial_read_exact(fd, payload, payload_len, 3);
        if (n != payload_len) return n == 0 ? ERR_TIMEOUT : ERR_IO;
    }

    /* Read and verify checksum */
    uint8_t rx_chk;
    n = serial_read_exact(fd, &rx_chk, 1, 3);
    if (n != 1) return ERR_IO;

    /* Compute over: magic + type + payload_len + payload */
    uint8_t chk_buf[3 + FRAME_MAX_PAYLOAD];
    chk_buf[0] = FRAME_MAGIC;
    chk_buf[1] = type;
    chk_buf[2] = payload_len;
    memcpy(chk_buf + 3, payload, payload_len);
    uint8_t expected = checksum(chk_buf, 3 + payload_len);

    if (rx_chk != expected) {
        LOG_ERROR("checksum mismatch: got 0x%02X expected 0x%02X",
                  rx_chk, expected);
        return ERR_BAD_PACKET;
    }

    out->type        = type;
    out->payload_len = payload_len;
    memcpy(out->payload, payload, payload_len);

    LOG_DEBUG("frame ok: type=0x%02X payload_len=%u", type, payload_len);
    return ERR_OK;
}

/*
 * Build and write one complete frame.
 */
Error serial_write_frame(int fd, uint8_t type,
                         const uint8_t *payload, uint8_t payload_len) {
    if (payload_len > FRAME_MAX_PAYLOAD) return ERR_INVALID_ARG;

    uint8_t buf[3 + FRAME_MAX_PAYLOAD + 1];
    buf[0] = FRAME_MAGIC;
    buf[1] = type;
    buf[2] = payload_len;
    if (payload_len > 0) memcpy(buf + 3, payload, payload_len);
    buf[3 + payload_len] = checksum(buf, 3 + payload_len);

    ssize_t n = serial_write(fd, buf, 4 + payload_len);
    return n == (ssize_t)(4 + payload_len) ? ERR_OK : ERR_IO;
}
```

---

## A complete sensor reader loop

Pulling everything together into a real sensor polling loop:

```c
#include <stdio.h>
#include <stdlib.h>
#include <signal.h>
#include <unistd.h>
#include <arpa/inet.h>
#include "log.h"
#include "errors.h"

static volatile sig_atomic_t g_quit = 0;
static void handle_quit(int s) { (void)s; g_quit = 1; }

/* Sensor data payload layout */
typedef struct __attribute__((packed)) {
    uint8_t  device_id;
    uint16_t raw_value;    /* big-endian */
    uint8_t  status;
} SensorPayload;

static void process_sensor_frame(const Frame *f) {
    if (f->type != FTYPE_SENSOR) return;
    if (f->payload_len < sizeof(SensorPayload)) {
        LOG_WARN("short sensor payload: %u bytes", f->payload_len);
        return;
    }

    SensorPayload p;
    memcpy(&p, f->payload, sizeof(p));
    p.raw_value = ntohs(p.raw_value);   /* big-endian → host */

    float celsius = (float)p.raw_value * 0.0625f;   /* example scaling */
    printf("device=%u temp=%.2f°C status=0x%02X\n",
           p.device_id, celsius, p.status);
}

int main(int argc, char *argv[]) {
    const char *port = argc > 1 ? argv[1] : "/dev/ttyUSB0";

    struct sigaction sa = { .sa_handler = handle_quit, .sa_flags = SA_RESTART };
    sigemptyset(&sa.sa_mask);
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    int fd = serial_open(port, B115200);
    if (fd < 0) return 1;

    LOG_INFO("reading from %s — Ctrl+C to stop", port);

    int consecutive_errors = 0;

    while (!g_quit) {
        Frame f;
        Error rc = serial_read_frame(fd, &f);

        switch (rc) {
        case ERR_OK:
            consecutive_errors = 0;
            process_sensor_frame(&f);
            break;

        case ERR_TIMEOUT:
            LOG_DEBUG("timeout — no frame received");
            consecutive_errors = 0;
            break;

        case ERR_BAD_PACKET:
            consecutive_errors++;
            LOG_WARN("bad packet (%d consecutive)", consecutive_errors);
            if (consecutive_errors >= 10) {
                LOG_ERROR("too many errors — re-opening port");
                serial_close(fd);
                sleep(1);
                fd = serial_open(port, B115200);
                if (fd < 0) { LOG_ERROR("reopen failed"); break; }
                consecutive_errors = 0;
            }
            break;

        case ERR_IO:
            LOG_ERROR("I/O error — exiting");
            goto done;

        default:
            break;
        }
    }

done:
    serial_close(fd);
    LOG_INFO("done");
    return 0;
}
```

The consecutive-error counter with auto-reopen is a pattern directly from production IoT firmware. USB-serial adapters can silently drop bytes, devices can reset mid-transmission, and cables can have intermittent connections. A robust reader expects all of these and recovers automatically.

---

## Testing without real hardware

You don't need a physical device to test serial code. Linux's `socat` utility creates virtual serial port pairs:

```bash
# Create a linked pair: /tmp/ttyV0 ↔ /tmp/ttyV1
socat PTY,link=/tmp/ttyV0,raw,echo=0 PTY,link=/tmp/ttyV1,raw,echo=0 &

# Run your reader on one end
./sensor_reader /tmp/ttyV0 &

# Send test frames from the other end with a small C program or Python
python3 -c "
import serial, struct, time
s = serial.Serial('/tmp/ttyV1', 115200)
while True:
    payload = struct.pack('>BHB', 1, 1024, 0x00)  # device=1, val=1024, ok
    frame   = bytes([0xAA, 0x01, len(payload)]) + payload
    chk     = 0
    for b in frame: chk ^= b
    s.write(frame + bytes([chk]))
    time.sleep(0.5)
"
```

This combination — `socat` for virtual ports + a Python frame generator — replaces a hardware device entirely for unit and integration testing.

---

## Day 19 exercise

1. Set up a `socat` virtual port pair and verify the loopback: write a program that opens both ends, writes 10 bytes to one end, and reads them back from the other. Confirm every byte arrives intact.
    
2. Implement `serial_open`, `serial_read_exact`, and `serial_write` from the lesson in a `serial.c` / `serial.h` module. Add it to your `sensor_base` project Makefile.
    
3. Implement `serial_read_frame` and `serial_write_frame`. Write a test that sends 100 frames through the `socat` loopback — 90 valid frames and 10 with deliberately corrupted checksum bytes — and verify the reader accepts exactly 90 and rejects exactly 10.
    
4. Extend the sensor reader main loop to write each successfully parsed `SensorPayload` to the binary log from Day 11 using `logwriter_append`. Run it against your frame generator and verify the log contains the correct number of entries.
    

Day 20 covers binary protocol parsing with `struct`, network byte order, CRC computation, and building a streaming state machine parser for variable-length framed data.