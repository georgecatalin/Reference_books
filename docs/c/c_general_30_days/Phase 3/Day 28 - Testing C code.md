

Untested C is broken C — you just haven't found out where yet. Testing embedded and systems C has a reputation for being hard, but the difficulty is almost entirely artificial: people try to test code that was never designed to be tested. Code written with dependency injection and thin hardware abstraction layers tests easily. Today you build a complete test suite using the Unity framework, learn to mock hardware dependencies, measure coverage, and wire everything into CI.

---

## The testability problem in C

The reason most embedded C is hard to test is coupling — business logic mixed directly with hardware access:

```c
/* Hard to test — hardware access baked in */
void process_sensor(void) {
    uint16_t raw = ADC1->DR;           /* reads hardware register */
    float temp   = raw * 0.0625f;
    if (temp > 80.0f) {
        GPIOB->BSRR = (1u << 5);       /* sets hardware pin */
        uart_send_alert("OVERHEAT");    /* blocks on UART hardware */
    }
}

/* Easy to test — dependencies injected */
void process_sensor(uint16_t raw_adc,
                    void (*set_alarm)(void),
                    void (*send_alert)(const char *)) {
    float temp = raw_adc * 0.0625f;
    if (temp > 80.0f) {
        set_alarm();
        send_alert("OVERHEAT");
    }
}
```

The second version is testable because every external dependency is a parameter. In tests you pass mock functions. In production you pass the real hardware functions. The logic under test is identical.

---

## Unity test framework

Unity is a minimal C testing framework — a single `.c` file and two headers — designed for embedded targets. No dynamic allocation, no C++ required, runs on bare metal.

```bash
# Get Unity (single file)
wget https://raw.githubusercontent.com/ThrowTheSwitch/Unity/master/src/unity.c
wget https://raw.githubusercontent.com/ThrowTheSwitch/Unity/master/src/unity.h
wget https://raw.githubusercontent.com/ThrowTheSwitch/Unity/master/src/unity_internals.h
```

```c
/* test_sensor.c — a complete Unity test file */
#include "unity.h"
#include "sensor.h"    /* the module under test */

/* setUp and tearDown run before/after EACH test */
void setUp(void) {
    /* reset any global state before each test */
}

void tearDown(void) {
    /* clean up after each test */
}

/* ── assertion reference ──────────────────────────────────────── */
/*
TEST_ASSERT_TRUE(condition)
TEST_ASSERT_FALSE(condition)
TEST_ASSERT_NULL(pointer)
TEST_ASSERT_NOT_NULL(pointer)
TEST_ASSERT_EQUAL_INT(expected, actual)
TEST_ASSERT_EQUAL_UINT32(expected, actual)
TEST_ASSERT_EQUAL_FLOAT(expected, actual)
TEST_ASSERT_EQUAL_STRING(expected, actual)
TEST_ASSERT_EQUAL_MEMORY(expected, actual, len)
TEST_ASSERT_EQUAL_HEX8_ARRAY(expected, actual, num_elements)
TEST_ASSERT_INT_WITHIN(delta, expected, actual)
TEST_ASSERT_FLOAT_WITHIN(delta, expected, actual)
*/

/* ── actual tests ─────────────────────────────────────────────── */

void test_sensor_create_sets_id(void) {
    Sensor *s = sensor_create(42, "temp");
    TEST_ASSERT_NOT_NULL(s);
    TEST_ASSERT_EQUAL_UINT8(42, s->id);
    sensor_destroy(s);
}

void test_sensor_create_copies_name(void) {
    Sensor *s = sensor_create(1, "humidity");
    TEST_ASSERT_NOT_NULL(s);
    TEST_ASSERT_EQUAL_STRING("humidity", s->name);
    sensor_destroy(s);
}

void test_sensor_update_stores_reading(void) {
    Sensor *s = sensor_create(1, "temp");
    TEST_ASSERT_NOT_NULL(s);
    bool ok = sensor_update(s, 23.4f);
    TEST_ASSERT_TRUE(ok);
    TEST_ASSERT_FLOAT_WITHIN(0.001f, 23.4f, s->last_reading);
    sensor_destroy(s);
}

void test_sensor_create_null_name_returns_null(void) {
    Sensor *s = sensor_create(1, NULL);
    TEST_ASSERT_NULL(s);
}

void test_sensor_update_null_sensor_returns_false(void) {
    bool ok = sensor_update(NULL, 23.4f);
    TEST_ASSERT_FALSE(ok);
}

void test_sensor_update_increments_count(void) {
    Sensor *s = sensor_create(1, "temp");
    sensor_update(s, 20.0f);
    sensor_update(s, 21.0f);
    sensor_update(s, 22.0f);
    TEST_ASSERT_EQUAL_UINT32(3, s->read_count);
    sensor_destroy(s);
}

/* main() for Unity */
int main(void) {
    UNITY_BEGIN();
    RUN_TEST(test_sensor_create_sets_id);
    RUN_TEST(test_sensor_create_copies_name);
    RUN_TEST(test_sensor_update_stores_reading);
    RUN_TEST(test_sensor_create_null_name_returns_null);
    RUN_TEST(test_sensor_update_null_sensor_returns_false);
    RUN_TEST(test_sensor_update_increments_count);
    return UNITY_END();
}
```

```makefile
# Makefile test target
UNITY_SRC = tests/unity/unity.c
TEST_SRCS  = $(wildcard tests/test_*.c)
TEST_BINS  = $(TEST_SRCS:tests/%.c=build/tests/%)

test: $(TEST_BINS)
	@for t in $^; do echo "--- $$t ---"; ./$$t; done

build/tests/%: tests/%.c src/sensor.c src/errors.c src/log.c $(UNITY_SRC)
	@mkdir -p $(@D)
	$(CC) $(CFLAGS) -Itests/unity -Iinclude -o $@ $^

.PHONY: test
```

---

## Mocking hardware with function pointers

The cleanest approach to hardware mocking in C is function pointer injection. No linker tricks needed — just pass a different implementation in tests:

```c
/* hal.h — hardware abstraction layer */
#pragma once
#include <stdint.h>
#include <stddef.h>

/* Function pointer types — the HAL interface */
typedef uint16_t (*adc_read_fn)(uint8_t channel);
typedef void     (*gpio_write_fn)(uint8_t pin, uint8_t val);
typedef int      (*uart_write_fn)(const uint8_t *buf, size_t len);

/* HAL struct — injectable dependency */
typedef struct {
    adc_read_fn   adc_read;
    gpio_write_fn gpio_write;
    uart_write_fn uart_write;
} HAL;

/* Real HAL — calls actual hardware */
extern const HAL hal_real;
```

```c
/* controller.h */
#pragma once
#include "hal.h"
#include <stdbool.h>

typedef struct {
    const HAL *hal;
    float      temp_threshold;
    uint32_t   alarm_count;
} TempController;

void temp_controller_init(TempController *c, const HAL *hal,
                           float threshold);
void temp_controller_tick(TempController *c);
bool temp_controller_alarm_active(const TempController *c);
```

```c
/* controller.c */
#include "controller.h"

void temp_controller_init(TempController *c, const HAL *hal,
                           float threshold) {
    c->hal             = hal;
    c->temp_threshold  = threshold;
    c->alarm_count     = 0;
}

void temp_controller_tick(TempController *c) {
    uint16_t raw  = c->hal->adc_read(0);
    float    temp = (float)raw * 0.0625f;

    if (temp > c->temp_threshold) {
        c->hal->gpio_write(5, 1);          /* alarm LED on */
        uint8_t msg[] = "OVERHEAT\n";
        c->hal->uart_write(msg, sizeof(msg) - 1);
        c->alarm_count++;
    } else {
        c->hal->gpio_write(5, 0);          /* alarm LED off */
    }
}

bool temp_controller_alarm_active(const TempController *c) {
    return c->alarm_count > 0;
}
```

```c
/* tests/test_controller.c */
#include "unity.h"
#include "controller.h"
#include <stdint.h>
#include <stdbool.h>
#include <string.h>

/* ── mock state ───────────────────────────────────────────────── */
static uint16_t mock_adc_value   = 0;
static uint8_t  mock_gpio_pin    = 0xFF;
static uint8_t  mock_gpio_val    = 0xFF;
static char     mock_uart_buf[64];
static int      mock_uart_bytes  = 0;
static int      mock_adc_calls   = 0;

/* ── mock implementations ─────────────────────────────────────── */
static uint16_t mock_adc_read(uint8_t channel) {
    (void)channel;
    mock_adc_calls++;
    return mock_adc_value;
}

static void mock_gpio_write(uint8_t pin, uint8_t val) {
    mock_gpio_pin = pin;
    mock_gpio_val = val;
}

static int mock_uart_write(const uint8_t *buf, size_t len) {
    if (len >= sizeof(mock_uart_buf)) len = sizeof(mock_uart_buf) - 1;
    memcpy(mock_uart_buf, buf, len);
    mock_uart_buf[len] = '\0';
    mock_uart_bytes += (int)len;
    return (int)len;
}

static const HAL mock_hal = {
    .adc_read   = mock_adc_read,
    .gpio_write = mock_gpio_write,
    .uart_write = mock_uart_write,
};

/* ── test fixture ─────────────────────────────────────────────── */
static TempController g_ctrl;

void setUp(void) {
    mock_adc_value  = 0;
    mock_gpio_pin   = 0xFF;
    mock_gpio_val   = 0xFF;
    mock_uart_bytes = 0;
    mock_adc_calls  = 0;
    memset(mock_uart_buf, 0, sizeof(mock_uart_buf));
    temp_controller_init(&g_ctrl, &mock_hal, 80.0f);
}

void tearDown(void) { }

/* ── tests ────────────────────────────────────────────────────── */

void test_no_alarm_below_threshold(void) {
    mock_adc_value = 1000;   /* 1000 * 0.0625 = 62.5°C — below 80 */
    temp_controller_tick(&g_ctrl);
    TEST_ASSERT_EQUAL_UINT8(5,   mock_gpio_pin);
    TEST_ASSERT_EQUAL_UINT8(0,   mock_gpio_val);   /* LED off */
    TEST_ASSERT_EQUAL_INT(0,     mock_uart_bytes); /* no UART message */
    TEST_ASSERT_FALSE(temp_controller_alarm_active(&g_ctrl));
}

void test_alarm_triggers_above_threshold(void) {
    mock_adc_value = 1400;   /* 1400 * 0.0625 = 87.5°C — above 80 */
    temp_controller_tick(&g_ctrl);
    TEST_ASSERT_EQUAL_UINT8(5,   mock_gpio_pin);
    TEST_ASSERT_EQUAL_UINT8(1,   mock_gpio_val);   /* LED on */
    TEST_ASSERT_TRUE(mock_uart_bytes > 0);
    TEST_ASSERT_TRUE(strstr(mock_uart_buf, "OVERHEAT") != NULL);
    TEST_ASSERT_TRUE(temp_controller_alarm_active(&g_ctrl));
}

void test_alarm_count_increments_per_tick(void) {
    mock_adc_value = 1400;
    temp_controller_tick(&g_ctrl);
    temp_controller_tick(&g_ctrl);
    temp_controller_tick(&g_ctrl);
    TEST_ASSERT_EQUAL_UINT32(3, g_ctrl.alarm_count);
}

void test_adc_read_called_on_each_tick(void) {
    temp_controller_tick(&g_ctrl);
    temp_controller_tick(&g_ctrl);
    TEST_ASSERT_EQUAL_INT(2, mock_adc_calls);
}

void test_led_turns_off_when_temperature_drops(void) {
    mock_adc_value = 1400;   /* above threshold */
    temp_controller_tick(&g_ctrl);
    TEST_ASSERT_EQUAL_UINT8(1, mock_gpio_val);

    mock_adc_value = 800;    /* back below threshold */
    temp_controller_tick(&g_ctrl);
    TEST_ASSERT_EQUAL_UINT8(0, mock_gpio_val);   /* LED off again */
}

int main(void) {
    UNITY_BEGIN();
    RUN_TEST(test_no_alarm_below_threshold);
    RUN_TEST(test_alarm_triggers_above_threshold);
    RUN_TEST(test_alarm_count_increments_per_tick);
    RUN_TEST(test_adc_read_called_on_each_tick);
    RUN_TEST(test_led_turns_off_when_temperature_drops);
    return UNITY_END();
}
```

---

## Testing the frame parser from Day 20

The parser has no hardware dependencies — it operates on byte buffers. Pure logic tests are straightforward:

```c
/* tests/test_parser.c */
#include "unity.h"
#include "protocol.h"   /* frame_serialise, frame_deserialise, crc16 */
#include <string.h>
#include <stdint.h>

void setUp(void)    { crc16_init_table(); }
void tearDown(void) { }

static Frame make_sensor_frame(uint8_t device, float val) {
    Frame f = {
        .type        = PTYPE_SENSOR,
        .sequence    = 1,
        .payload_len = 5,
        .timestamp   = 1700000000,
    };
    f.payload[0] = device;
    memcpy(f.payload + 1, &val, sizeof(val));
    return f;
}

void test_serialise_deserialise_roundtrip(void) {
    Frame original = make_sensor_frame(1, 23.4f);
    uint8_t wire[256];
    ssize_t n = frame_serialise(&original, wire, sizeof(wire));

    TEST_ASSERT_TRUE(n > 0);

    Frame decoded;
    Error rc = frame_deserialise(wire, (size_t)n, &decoded);
    TEST_ASSERT_EQUAL_INT(ERR_OK, rc);
    TEST_ASSERT_EQUAL_UINT8(original.type,        decoded.type);
    TEST_ASSERT_EQUAL_UINT16(original.sequence,   decoded.sequence);
    TEST_ASSERT_EQUAL_UINT32(original.timestamp,  decoded.timestamp);
    TEST_ASSERT_EQUAL_UINT16(original.payload_len,decoded.payload_len);
    TEST_ASSERT_EQUAL_MEMORY(original.payload, decoded.payload,
                              original.payload_len);
}

void test_bad_magic_rejected(void) {
    Frame f = make_sensor_frame(1, 20.0f);
    uint8_t wire[256];
    ssize_t n = frame_serialise(&f, wire, sizeof(wire));

    wire[0] = 0xDE; wire[1] = 0xAD;   /* corrupt magic */

    Frame out;
    Error rc = frame_deserialise(wire, (size_t)n, &out);
    TEST_ASSERT_EQUAL_INT(ERR_BAD_PACKET, rc);
}

void test_corrupt_crc_rejected(void) {
    Frame f = make_sensor_frame(2, 55.0f);
    uint8_t wire[256];
    ssize_t n = frame_serialise(&f, wire, sizeof(wire));

    wire[n - 1] ^= 0xFF;   /* flip bits in last CRC byte */

    Frame out;
    Error rc = frame_deserialise(wire, (size_t)n, &out);
    TEST_ASSERT_EQUAL_INT(ERR_BAD_PACKET, rc);
}

void test_truncated_buffer_rejected(void) {
    Frame f = make_sensor_frame(1, 20.0f);
    uint8_t wire[256];
    ssize_t n = frame_serialise(&f, wire, sizeof(wire));

    Frame out;
    /* Feed only half the frame */
    Error rc = frame_deserialise(wire, (size_t)n / 2, &out);
    TEST_ASSERT_EQUAL_INT(ERR_BAD_PACKET, rc);
}

void test_payload_len_overflow_rejected(void) {
    uint8_t wire[256] = {0};
    wire[0] = 0xBE; wire[1] = 0xEF;   /* magic */
    wire[2] = 0x01;                     /* version */
    wire[3] = PTYPE_SENSOR;
    /* payload_len = 0xFFFF — way too large */
    wire[6] = 0xFF; wire[7] = 0xFF;

    Frame out;
    Error rc = frame_deserialise(wire, sizeof(wire), &out);
    TEST_ASSERT_EQUAL_INT(ERR_BAD_PACKET, rc);
}

void test_crc16_known_value(void) {
    /* CRC-16/CCITT-FALSE of "123456789" = 0x29B1 — standard test vector */
    uint8_t data[] = "123456789";
    uint16_t crc = crc16(data, 9);
    TEST_ASSERT_EQUAL_HEX16(0x29B1, crc);
}

void test_streaming_parser_single_byte_feed(void) {
    Frame original = make_sensor_frame(3, 42.0f);
    uint8_t wire[256];
    ssize_t n = frame_serialise(&original, wire, sizeof(wire));

    Parser p;
    int frames_received = 0;

    /* Callback counts frames */
    /* In real tests: use a file-scope counter with a static callback */
    parser_init(&p);
    for (ssize_t i = 0; i < n; i++) {
        /* Feed one byte at a time */
        parser_feed(&p, wire + i, 1, test_count_cb, &frames_received);
    }
    TEST_ASSERT_EQUAL_INT(1, frames_received);
}

int main(void) {
    UNITY_BEGIN();
    RUN_TEST(test_serialise_deserialise_roundtrip);
    RUN_TEST(test_bad_magic_rejected);
    RUN_TEST(test_corrupt_crc_rejected);
    RUN_TEST(test_truncated_buffer_rejected);
    RUN_TEST(test_payload_len_overflow_rejected);
    RUN_TEST(test_crc16_known_value);
    RUN_TEST(test_streaming_parser_single_byte_feed);
    return UNITY_END();
}
```

---

## Code coverage with gcov/lcov

Coverage tells you which lines your tests don't exercise. Lines never reached in tests are either dead code or gaps in your test suite:

```bash
# Compile with coverage instrumentation
gcc -Wall -Wextra -g -O0 --coverage -Iinclude -Itests/unity \
    -o build/tests/test_sensor \
    tests/test_sensor.c src/sensor.c src/errors.c src/log.c \
    tests/unity/unity.c

# Run tests — this generates .gcda coverage data files
./build/tests/test_sensor

# Generate coverage report
gcov -b src/sensor.c          # text report in sensor.c.gcov

# lcov — prettier HTML report
lcov --capture --directory . --output-file coverage.info \
     --exclude '*/unity/*' --exclude '*/tests/*'
genhtml coverage.info --output-directory coverage_html
# Open coverage_html/index.html in a browser
```

Add a coverage target to your Makefile:

```makefile
coverage: CFLAGS += -g -O0 --coverage
coverage: LDFLAGS += --coverage
coverage: test
	lcov --capture --directory . \
	     --output-file coverage.info \
	     --exclude '*/unity/*' \
	     --exclude '*/tests/*'
	genhtml coverage.info --output-directory coverage_html
	@echo "Coverage report: coverage_html/index.html"

.PHONY: coverage
```

A useful coverage target for IoT systems code is 80% line coverage on the core protocol and state machine modules. 100% is often counterproductive — it requires testing error paths that only fire on memory allocation failure, which is usually not worth the effort.

---

## CI integration with GitHub Actions

```yaml
# .github/workflows/test.yml
name: Build and test

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Install dependencies
        run: |
          sudo apt-get update
          sudo apt-get install -y gcc make valgrind lcov cppcheck

      - name: Build debug
        run: make debug

      - name: Run tests
        run: make test

      - name: Run tests under Valgrind
        run: |
          for t in build/tests/*; do
            valgrind --error-exitcode=1 \
                     --leak-check=full \
                     --show-leak-kinds=all \
                     $t
          done

      - name: Static analysis
        run: |
          cppcheck --enable=all \
                   --error-exitcode=1 \
                   --suppress=missingIncludeSystem \
                   -Iinclude src/

      - name: Coverage report
        run: |
          make coverage
          lcov --list coverage.info

      - name: Upload coverage
        uses: actions/upload-artifact@v4
        with:
          name: coverage-report
          path: coverage_html/
```

---

## The complete test Makefile

```makefile
CC        = gcc
CFLAGS    = -Wall -Wextra -Werror -std=c11 -Iinclude -Itests/unity
SRC_DIR   = src
TEST_DIR  = tests
BUILD_DIR = build

# Source files (excluding main.c)
LIB_SRCS = $(filter-out $(SRC_DIR)/main.c, $(wildcard $(SRC_DIR)/*.c))
UNITY    = $(TEST_DIR)/unity/unity.c

# Test binaries
TEST_SRCS = $(wildcard $(TEST_DIR)/test_*.c)
TEST_BINS = $(patsubst $(TEST_DIR)/%.c, $(BUILD_DIR)/tests/%, $(TEST_SRCS))

# ── targets ───────────────────────────────────────────────────────
test: $(TEST_BINS)
	@echo "=== Running tests ==="
	@pass=0; fail=0; \
	for t in $(TEST_BINS); do \
	    if ./$$t; then pass=$$((pass+1)); \
	    else fail=$$((fail+1)); fi; \
	done; \
	echo "=== $$pass passed, $$fail failed ===";\
	[ $$fail -eq 0 ]

$(BUILD_DIR)/tests/%: $(TEST_DIR)/%.c $(LIB_SRCS) $(UNITY)
	@mkdir -p $(@D)
	$(CC) $(CFLAGS) -o $@ $^

# Debug test build with sanitizers
test-debug: CFLAGS += -g -O0 -fsanitize=address,undefined
test-debug: test

# Coverage
coverage: CFLAGS  += -g -O0 --coverage
coverage: LDFLAGS += --coverage
coverage: test
	lcov --capture --directory $(BUILD_DIR) \
	     --output-file coverage.info \
	     --exclude '*/unity/*' --exclude '*/tests/*'
	genhtml coverage.info --output-directory coverage_html
	@echo "Report: coverage_html/index.html"

# Lint
lint:
	cppcheck --enable=all --error-exitcode=1 \
	         --suppress=missingIncludeSystem \
	         -Iinclude $(SRC_DIR)/

clean:
	rm -rf $(BUILD_DIR) coverage.info coverage_html

.PHONY: test test-debug coverage lint clean
```

---

## Day 28 exercise

1. Download Unity and add it to your `sensor_base` project. Write at least 8 tests for `sensor.c` covering: create with valid args, create with NULL name, update reading, update with NULL sensor, reading count increments, and the ring buffer from Day 8 (push/pop/overflow/underflow). Verify all pass with `make test`.
    
2. Add the `TempController` with the `HAL` interface to your project. Write the 5 mock tests from the lesson. Then add 3 more: test that a threshold of exactly the temperature value (boundary condition) does not trigger the alarm, test that `alarm_count` resets correctly if you reinitialise the controller, and test that `uart_write` is called with a non-empty message when the alarm fires.
    
3. Add the `test-debug` target to your Makefile and run all tests under AddressSanitizer and UndefinedBehaviorSanitizer. Fix any findings.
    
4. Add the `coverage` target and run it against your full test suite. Identify the three functions with the lowest line coverage and write additional tests to bring each above 80%. Generate the HTML report and examine which branches are still uncovered.
    

Day 29 covers build systems and cross-compilation — CMake, toolchain files for ARM, and producing binaries for embedded targets from your development machine.