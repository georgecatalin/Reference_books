

Embedded C is not a different language — it's the same C used with a different set of constraints. You have no virtual memory, often no OS, a few kilobytes of RAM, and code that runs directly on hardware. The patterns in this lesson exist because those constraints are real: a heap allocation failure on a Linux server prints an error; on a microcontroller it silently corrupts the heap and causes a crash three minutes later in completely unrelated code.

---

## `volatile` — the embedded developer's essential tool

On a desktop system `volatile` rarely matters. On an MCU it matters constantly. The compiler is allowed to optimise away any read or write it believes has no effect. For hardware registers and ISR-shared variables, that optimisation is catastrophically wrong.

```c
#include <stdint.h>

/*
 * Without volatile, the compiler may optimise this loop away entirely.
 * It sees: "x is never written in this loop body, so the condition
 * never changes, so this is either infinite or never executes —
 * I can remove it."
 */
uint32_t *STATUS_REG = (uint32_t *)0x40020000;

/* WRONG — compiler may hoist the read out of the loop or remove it */
while (*STATUS_REG & 0x01) { }   /* wait for bit 0 to clear */

/* CORRECT — volatile forces a read on every iteration */
volatile uint32_t *STATUS_REG_V = (volatile uint32_t *)0x40020000;
while (*STATUS_REG_V & 0x01) { }

/*
 * ISR-shared variables — same problem.
 * The main loop reads g_tick. The ISR writes it.
 * Without volatile, the compiler caches g_tick in a register
 * and the main loop never sees the ISR's updates.
 */
static volatile uint32_t g_tick = 0;

/* Called from a hardware timer ISR */
void TIM2_IRQHandler(void) {
    g_tick++;   /* volatile — main loop will see this */
}

void main_loop(void) {
    uint32_t last = g_tick;
    while (1) {
        if (g_tick != last) {
            last = g_tick;
            do_periodic_work();
        }
    }
}
```

`volatile` tells the compiler: "every read must fetch from memory, every write must store to memory — do not cache, do not reorder, do not eliminate." It does not provide atomicity — a 32-bit volatile read on a 16-bit bus is still two bus cycles and can be torn. For atomic access you need C11 atomics (Day 22) or critical sections.

---

## Hardware register access

Microcontrollers expose peripherals as memory-mapped registers — specific addresses that control hardware when read or written. The pattern is a volatile pointer cast to a fixed address:

```c
#include <stdint.h>

/*
 * STM32-style GPIO register map.
 * Each peripheral is a struct of volatile registers at a fixed base address.
 */
typedef struct {
    volatile uint32_t MODER;    /* mode register: input/output/alt/analog */
    volatile uint32_t OTYPER;   /* output type: push-pull or open-drain */
    volatile uint32_t OSPEEDR;  /* output speed */
    volatile uint32_t PUPDR;    /* pull-up/pull-down */
    volatile uint32_t IDR;      /* input data register — read only */
    volatile uint32_t ODR;      /* output data register */
    volatile uint32_t BSRR;     /* bit set/reset register — write only */
    volatile uint32_t LCKR;     /* configuration lock */
    volatile uint32_t AFR[2];   /* alternate function registers */
} GPIO_TypeDef;

/* Map the struct to the hardware base address */
#define GPIOA  ((GPIO_TypeDef *)0x48000000)
#define GPIOB  ((GPIO_TypeDef *)0x48000400)

/* Bit manipulation macros — never use magic numbers */
#define GPIO_PIN_5    (1u << 5)
#define GPIO_MODER_OUTPUT  0x01u

/* Configure PA5 as output */
void gpio_configure_output(GPIO_TypeDef *port, uint32_t pin_mask) {
    uint32_t pin = __builtin_ctz(pin_mask);   /* find bit position */
    port->MODER &= ~(0x3u << (pin * 2));      /* clear mode bits */
    port->MODER |=  (GPIO_MODER_OUTPUT << (pin * 2)); /* set output mode */
}

/* Set, clear, toggle */
static inline void gpio_set(GPIO_TypeDef *port, uint32_t pin_mask) {
    port->BSRR = pin_mask;            /* write upper half clears, lower sets */
}
static inline void gpio_clear(GPIO_TypeDef *port, uint32_t pin_mask) {
    port->BSRR = (pin_mask << 16);
}
static inline void gpio_toggle(GPIO_TypeDef *port, uint32_t pin_mask) {
    port->ODR ^= pin_mask;
}
static inline uint32_t gpio_read(GPIO_TypeDef *port, uint32_t pin_mask) {
    return (port->IDR & pin_mask) != 0;
}

/* Usage */
void led_blink(void) {
    gpio_configure_output(GPIOA, GPIO_PIN_5);
    while (1) {
        gpio_set(GPIOA, GPIO_PIN_5);
        delay_ms(500);
        gpio_clear(GPIOA, GPIO_PIN_5);
        delay_ms(500);
    }
}
```

The `static inline` functions compile to single instructions — no function call overhead. The volatile struct ensures every access goes to hardware, not a cached register value. The explicit bit manipulation with named constants makes the intent readable and auditable.

---

## Interrupt service routines — the hard constraints

An ISR runs asynchronously, can interrupt any point in main code, and has strict limitations:

```c
#include <stdint.h>
#include <stdbool.h>

/*
 * ISR rules — violate these and you get non-deterministic failures:
 *
 * 1. No dynamic allocation (malloc/free touch global state)
 * 2. No blocking calls (UART, I2C, SPI blocking transfers)
 * 3. No floating point unless FPU context is saved (toolchain-specific)
 * 4. Keep it short — every microsecond in the ISR delays other ISRs
 * 5. All shared variables must be volatile
 * 6. All multi-byte reads of shared variables need critical sections
 */

/* Shared state between ISR and main — all volatile */
static volatile uint32_t g_adc_raw       = 0;
static volatile bool     g_adc_ready     = false;
static volatile uint32_t g_overflow_count = 0;

/* Ring buffer for ISR → main data transfer (from Day 22) */
#define ISR_BUF_SIZE 64   /* must be power of two */
static volatile uint8_t  g_uart_buf[ISR_BUF_SIZE];
static volatile uint32_t g_uart_head = 0;   /* ISR writes */
static volatile uint32_t g_uart_tail = 0;   /* main reads */

/*
 * ADC conversion complete ISR — fires when hardware ADC finishes
 */
void ADC1_IRQHandler(void) {
    /* Read result register immediately — clears the interrupt flag */
    uint32_t raw = ADC1->DR;   /* single volatile read */

    g_adc_raw   = raw;
    g_adc_ready = true;   /* signal to main loop */
    /* Do NOT process the value here — just store and flag */
}

/*
 * UART receive ISR — fires on each received byte
 */
void USART2_IRQHandler(void) {
    uint8_t byte = USART2->RDR;   /* read clears interrupt */

    uint32_t next_head = (g_uart_head + 1) & (ISR_BUF_SIZE - 1);
    if (next_head != g_uart_tail) {
        g_uart_buf[g_uart_head] = byte;
        g_uart_head = next_head;   /* publish after data written */
    } else {
        g_overflow_count++;   /* buffer full — count drops */
    }
}

/*
 * Main loop reads from the UART ring buffer
 */
bool uart_read_byte(uint8_t *out) {
    if (g_uart_tail == g_uart_head) return false;   /* empty */
    *out = g_uart_buf[g_uart_tail];
    g_uart_tail = (g_uart_tail + 1) & (ISR_BUF_SIZE - 1);
    return true;
}

/*
 * Critical section — disable/enable interrupts around multi-byte reads
 * Prevents torn reads of 32-bit values on 8/16-bit buses
 * Toolchain-specific: ARM Cortex-M example
 */
#define CRITICAL_ENTER()  __asm volatile ("cpsid i" ::: "memory")
#define CRITICAL_EXIT()   __asm volatile ("cpsie i" ::: "memory")

uint32_t get_tick_safe(void) {
    uint32_t val;
    CRITICAL_ENTER();
    val = g_tick;
    CRITICAL_EXIT();
    return val;
}
```

The "write data, then write flag" ordering in `ADC1_IRQHandler` is essential. If the flag were set first and the compiler or CPU reordered the write, the main loop could read an uninitialized value. The `volatile` qualifier on both variables prevents compiler reordering; on CPUs with weak memory models a memory barrier (`__dmb()` on ARM) is also needed.

---

## State machines for embedded control loops

State machines are the standard architecture for embedded control software. They produce deterministic behaviour, are straightforward to test, and avoid the deep call stacks that overflow constrained memory:

```c
#include <stdint.h>
#include <stdbool.h>
#include <string.h>
#include "log.h"

/*
 * Sensor reader state machine.
 * Replaces blocking read loops with a non-blocking, ISR-friendly design.
 */

typedef enum {
    STATE_IDLE,
    STATE_SEND_CMD,
    STATE_WAIT_RESPONSE,
    STATE_PARSE_RESPONSE,
    STATE_ERROR,
    STATE_COUNT
} SensorState;

typedef enum {
    EVT_TICK,          /* periodic timer tick */
    EVT_CMD_SENT,      /* UART transmit complete */
    EVT_BYTE_RECEIVED, /* UART receive complete */
    EVT_TIMEOUT,       /* response deadline exceeded */
    EVT_RESET,         /* explicit reset request */
    EVT_COUNT
} SensorEvent;

typedef struct {
    SensorState  state;
    uint32_t     timeout_ticks;   /* countdown to EVT_TIMEOUT */
    uint8_t      rx_buf[32];
    uint8_t      rx_count;
    float        last_reading;
    uint32_t     error_count;
    uint32_t     read_count;
} SensorSM;

/* Forward declarations */
static void on_idle_tick(SensorSM *sm);
static void on_send_cmd(SensorSM *sm);
static void on_wait_byte(SensorSM *sm, uint8_t byte);
static void on_parse(SensorSM *sm);
static void on_error(SensorSM *sm);

/* State entry actions — called once when entering a state */
static void enter_idle(SensorSM *sm) {
    sm->timeout_ticks = 0;
    sm->rx_count      = 0;
}

static void enter_send_cmd(SensorSM *sm) {
    /* Non-blocking UART transmit — ISR signals EVT_CMD_SENT when done */
    static const uint8_t CMD_READ_TEMP[] = {0xAA, 0x01, 0x00, 0xAB};
    uart_transmit_async(CMD_READ_TEMP, sizeof(CMD_READ_TEMP));
    sm->timeout_ticks = 100;   /* 100ms timeout */
}

static void enter_wait_response(SensorSM *sm) {
    sm->rx_count      = 0;
    sm->timeout_ticks = 50;   /* 50ms to receive response */
}

static void enter_error(SensorSM *sm) {
    sm->error_count++;
    sm->timeout_ticks = 200;   /* 200ms back-off before retry */
    LOG_WARN("sensor SM error #%u", sm->error_count);
}

/* Transition table — state × event → (action, next_state) */
typedef void (*StateAction)(SensorSM *sm);
typedef struct {
    StateAction  action;
    SensorState  next_state;
} Transition;

/* NULL action means: stay in current state, no action */
static const Transition transitions[STATE_COUNT][EVT_COUNT] = {
    [STATE_IDLE] = {
        [EVT_TICK]         = { on_idle_tick,    STATE_SEND_CMD },
        [EVT_RESET]        = { enter_idle,      STATE_IDLE },
    },
    [STATE_SEND_CMD] = {
        [EVT_CMD_SENT]     = { enter_wait_response, STATE_WAIT_RESPONSE },
        [EVT_TIMEOUT]      = { enter_error,         STATE_ERROR },
        [EVT_TICK]         = { NULL,                STATE_SEND_CMD },
    },
    [STATE_WAIT_RESPONSE] = {
        [EVT_BYTE_RECEIVED]= { on_wait_byte,    STATE_WAIT_RESPONSE },
        [EVT_TIMEOUT]      = { enter_error,     STATE_ERROR },
    },
    [STATE_PARSE_RESPONSE] = {
        [EVT_TICK]         = { on_parse,        STATE_IDLE },
    },
    [STATE_ERROR] = {
        [EVT_TIMEOUT]      = { enter_idle,      STATE_IDLE },
        [EVT_TICK]         = { NULL,            STATE_ERROR },
        [EVT_RESET]        = { enter_idle,      STATE_IDLE },
    },
};

void sensor_sm_init(SensorSM *sm) {
    memset(sm, 0, sizeof(*sm));
    sm->state = STATE_IDLE;
    enter_idle(sm);
}

/*
 * Process one event — called from main loop or ISR.
 * For ISR calls: the SM must be lock-free or called only from one context.
 */
void sensor_sm_event(SensorSM *sm, SensorEvent evt, uint8_t data) {
    if (sm->state >= STATE_COUNT || evt >= EVT_COUNT) return;

    const Transition *tr = &transitions[sm->state][evt];

    /* No transition defined — ignore event */
    if (tr->action == NULL && tr->next_state == sm->state) return;

    /* Execute action */
    if (tr->action) {
        /* Pass data byte for EVT_BYTE_RECEIVED */
        if (evt == EVT_BYTE_RECEIVED) {
            on_wait_byte(sm, data);   /* special case */
        } else {
            tr->action(sm);
        }
    }

    sm->state = tr->next_state;
}

/* Tick function — call from timer ISR or main loop every 1ms */
void sensor_sm_tick(SensorSM *sm) {
    if (sm->timeout_ticks > 0) {
        sm->timeout_ticks--;
        if (sm->timeout_ticks == 0) {
            sensor_sm_event(sm, EVT_TIMEOUT, 0);
            return;
        }
    }
    sensor_sm_event(sm, EVT_TICK, 0);
}

/* Action implementations */
static void on_idle_tick(SensorSM *sm) {
    enter_send_cmd(sm);
}

static void on_send_cmd(SensorSM *sm) {
    (void)sm;
    /* triggered by EVT_CMD_SENT from UART ISR */
}

static void on_wait_byte(SensorSM *sm, uint8_t byte) {
    if (sm->rx_count < sizeof(sm->rx_buf)) {
        sm->rx_buf[sm->rx_count++] = byte;
    }
    /* Check for complete response (device-specific framing) */
    if (sm->rx_count >= 4 && sm->rx_buf[sm->rx_count-1] == 0xFF) {
        sm->state = STATE_PARSE_RESPONSE;
    }
}

static void on_parse(SensorSM *sm) {
    if (sm->rx_count < 4) { enter_error(sm); return; }
    /* Parse raw ADC value from response bytes 1–2 */
    uint16_t raw = ((uint16_t)sm->rx_buf[1] << 8) | sm->rx_buf[2];
    sm->last_reading = (float)raw * 0.0625f;
    sm->read_count++;
    enter_idle(sm);
}

static void on_error(SensorSM *sm) {
    enter_error(sm);
}
```

The transition table approach separates what happens from when it happens. Adding a new event or state means adding entries to the table — the dispatch logic doesn't change. Testing means calling `sensor_sm_event` directly with crafted events without any hardware.

---

## Static allocation — replacing malloc in constrained systems

On MCUs with 8KB of RAM, heap fragmentation is not a performance concern — it's a correctness concern. A fragmented heap may have 4KB free but no contiguous 2KB block. The fix is to not use the heap at all:

```c
#include <stdint.h>
#include <string.h>
#include <stdbool.h>
#include <assert.h>

/*
 * Static pool allocator — fixed-size blocks, no fragmentation.
 * All memory is allocated at compile time.
 */
#define SENSOR_POOL_SIZE 8

typedef struct {
    uint8_t  id;
    char     name[16];
    float    last_reading;
    bool     active;
} Sensor;

typedef struct {
    Sensor  pool[SENSOR_POOL_SIZE];
    bool    used[SENSOR_POOL_SIZE];
    uint8_t count;
} SensorPool;

static SensorPool g_sensor_pool;   /* zero-initialised at startup */

Sensor *sensor_alloc(void) {
    for (int i = 0; i < SENSOR_POOL_SIZE; i++) {
        if (!g_sensor_pool.used[i]) {
            g_sensor_pool.used[i] = true;
            g_sensor_pool.count++;
            memset(&g_sensor_pool.pool[i], 0, sizeof(Sensor));
            return &g_sensor_pool.pool[i];
        }
    }
    return NULL;   /* pool exhausted — handle at call site */
}

void sensor_free(Sensor *s) {
    if (!s) return;
    int idx = (int)(s - g_sensor_pool.pool);
    /* Bounds check — defensive */
    if (idx < 0 || idx >= SENSOR_POOL_SIZE) return;
    g_sensor_pool.used[idx] = false;
    g_sensor_pool.count--;
}

uint8_t sensor_pool_count(void) { return g_sensor_pool.count; }

/*
 * Stack-based frame buffer — fixed size, no allocation.
 * Define the maximum frame size at compile time.
 */
#define MAX_FRAME_BYTES 128

typedef struct {
    uint8_t  data[MAX_FRAME_BYTES];
    uint16_t len;
} FrameBuf;

/* Pass by pointer — never by value for anything > 8 bytes on embedded */
void frame_clear(FrameBuf *f) {
    f->len = 0;
}

bool frame_append(FrameBuf *f, const uint8_t *data, uint16_t len) {
    if (f->len + len > MAX_FRAME_BYTES) return false;
    memcpy(f->data + f->len, data, len);
    f->len += len;
    return true;
}

/*
 * Static ring buffer — no heap, no pointers to heap
 */
#define UART_RX_SIZE 256   /* power of two */

typedef struct {
    uint8_t  buf[UART_RX_SIZE];
    uint16_t head;
    uint16_t tail;
} StaticRing;

static StaticRing g_uart_rx;

bool static_ring_push(StaticRing *r, uint8_t byte) {
    uint16_t next = (r->head + 1) & (UART_RX_SIZE - 1);
    if (next == r->tail) return false;   /* full */
    r->buf[r->head] = byte;
    r->head = next;
    return true;
}

bool static_ring_pop(StaticRing *r, uint8_t *out) {
    if (r->head == r->tail) return false;   /* empty */
    *out = r->buf[r->tail];
    r->tail = (r->tail + 1) & (UART_RX_SIZE - 1);
    return true;
}
```

---

## Avoiding common embedded pitfalls

```c
/* 1. Never use float in ISRs unless you save/restore FPU context */
void BAD_ISR(void) {
    float temp = adc_read() * 0.0625f;   /* may corrupt FPU state */
}
void GOOD_ISR(void) {
    g_adc_raw = adc_read();   /* store raw integer — convert in main */
}

/* 2. Avoid recursion — use iterative solutions or explicit stacks */
/* On an MCU with 2KB of stack, 10 recursive calls × 200 bytes each = crash */
uint32_t bad_factorial(uint32_t n) {
    return n <= 1 ? 1 : n * bad_factorial(n - 1);   /* risky */
}
uint32_t good_factorial(uint32_t n) {
    uint32_t result = 1;
    while (n > 1) result *= n--;   /* no stack growth */
    return result;
}

/* 3. No printf in production embedded code — use a fixed-size log */
/* printf pulls in ~20KB of code and uses heap for formatting */
#ifdef DEBUG
  #define DBG_PRINT(msg) uart_puts(msg)   /* fixed string only */
#else
  #define DBG_PRINT(msg)                  /* compiles to nothing */
#endif

/* 4. Use fixed-width types everywhere */
/* 'int' is 16-bit on some MCUs — never assume 32-bit */
uint32_t counter;   /* explicit width */
int      x;         /* WRONG on embedded */

/* 5. Mark read-only data as const — linker places it in flash, not RAM */
const uint8_t CALIBRATION_TABLE[16] = { 0, 1, 2, 3, 4, 5, 6, 7,
                                         8, 9,10,11,12,13,14,15 };
/* Without const: 16 bytes copied from flash to RAM at startup — wasteful */
```

---

## Day 27 exercise

1. Write a bare-metal GPIO blink simulation: define a `GPIO_TypeDef` struct mapped to a regular `uint32_t` variable (not a real hardware address — just simulate it). Implement `gpio_set`, `gpio_clear`, `gpio_toggle`, and `gpio_read`. Verify with a test that calling `gpio_set` then `gpio_read` returns 1, and `gpio_clear` then `gpio_read` returns 0.
    
2. Implement `SensorSM` fully and write a test harness that drives it through a complete successful read cycle by injecting events in order: `EVT_TICK` → `EVT_CMD_SENT` → four `EVT_BYTE_RECEIVED` events → `EVT_TICK` (parse). Verify `last_reading` is set correctly and `state` returns to `STATE_IDLE`.
    
3. Then test the error path: drive the SM to `STATE_WAIT_RESPONSE`, inject `EVT_TIMEOUT`, verify transition to `STATE_ERROR`, inject 200 `EVT_TICK` events to expire the backoff, verify return to `STATE_IDLE`.
    
4. Implement `SensorPool` and write a test that allocates all 8 slots, verifies the 9th returns NULL, frees slot 3, allocates again and verifies it gets slot 3 back. Then write a `sensor_pool_defrag_check` function that scans the pool and logs a warning if more than half the slots have been allocated and freed more than 1000 times — an embedded equivalent of fragmentation monitoring.
    

Day 28 covers testing C code — Unity or CMocka, hardware mocking, code coverage, and building a test suite that runs in CI without real hardware.