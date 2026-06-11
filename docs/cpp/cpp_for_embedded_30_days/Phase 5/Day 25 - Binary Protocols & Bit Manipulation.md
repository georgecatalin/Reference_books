

IoT devices speak in bytes. Every MQTT packet, Modbus frame, I2C register, and CAN message is a carefully arranged sequence of bits and bytes with precise meaning. C++ gives you the tools to work at this level safely and efficiently — bit fields, masks, shifts, endianness conversion, and struct packing. Today we build the full toolkit and apply it to a real Modbus RTU parser.

---

## 1. The Bit Manipulation Toolkit

Before protocols, the primitives. These operations form the vocabulary of every binary parser you'll write:

```cpp
#include <cstdint>

uint8_t  byte  = 0b10110101;
uint16_t word  = 0x1A2B;
uint32_t dword = 0xDEADBEEF;

// ---- Setting, clearing, toggling individual bits ----

// Set bit N
byte |= (1u << 3);          // set bit 3:  0b10111101

// Clear bit N
byte &= ~(1u << 3);         // clear bit 3: 0b10110101

// Toggle bit N
byte ^= (1u << 2);          // toggle bit 2: 0b10110001

// Test bit N — returns non-zero if set
bool is_set = (byte >> 3) & 1u;
bool faster = byte & (1u << 3);  // same — no shift needed for test

// ---- Extracting a bit field ----

// Extract bits [start, start+len)
uint8_t extract(uint8_t val, int start, int len) {
    return (val >> start) & ((1u << len) - 1);
}
// extract(0b10110101, 2, 3) == 0b101 == 5

// ---- Setting a bit field ----

uint8_t insert(uint8_t target, uint8_t value,
               int start, int len) {
    uint8_t mask = static_cast<uint8_t>(((1u << len) - 1) << start);
    return (target & ~mask) |
           (static_cast<uint8_t>(value << start) & mask);
}

// ---- Useful patterns ----

// Round up to next power of 2
uint32_t next_pow2(uint32_t v) {
    v--;
    v |= v >> 1; v |= v >> 2; v |= v >> 4;
    v |= v >> 8; v |= v >> 16;
    return v + 1;
}

// Check if power of 2
bool is_pow2(uint32_t v) { return v && !(v & (v - 1)); }

// Count set bits (popcount)
int popcount(uint32_t v) { return __builtin_popcount(v); }

// Find lowest set bit position
int lowest_set_bit(uint32_t v) { return __builtin_ctz(v); }

// Find highest set bit position (floor log2)
int highest_set_bit(uint32_t v) {
    return 31 - __builtin_clz(v);
}
```

---

## 2. Endianness — The Byte Order Problem

A `uint32_t` value `0x12345678` can be stored two ways:

```
Big-endian (network byte order, Modbus, MQTT):
Address:  0    1    2    3
Byte:    0x12 0x34 0x56 0x78   ← most significant byte first

Little-endian (x86, ARM Cortex-M default, RISC-V):
Address:  0    1    2    3
Byte:    0x78 0x56 0x34 0x12   ← least significant byte first
```

Most IoT protocols use big-endian (network byte order). Most hardware is little-endian. You're constantly converting between them.

```cpp
#include <cstdint>
#include <cstring>

// Detect host endianness at compile time
#if defined(__BYTE_ORDER__) && __BYTE_ORDER__ == __ORDER_BIG_ENDIAN__
    constexpr bool HOST_BIG_ENDIAN = true;
#else
    constexpr bool HOST_BIG_ENDIAN = false;
#endif

// Byte swap — use compiler intrinsics when available
constexpr uint16_t bswap16(uint16_t v) {
    return static_cast<uint16_t>((v << 8) | (v >> 8));
}

constexpr uint32_t bswap32(uint32_t v) {
    return ((v & 0xFF000000u) >> 24) |
           ((v & 0x00FF0000u) >>  8) |
           ((v & 0x0000FF00u) <<  8) |
           ((v & 0x000000FFu) << 24);
}

constexpr uint64_t bswap64(uint64_t v) {
    return ((v & 0xFF00000000000000ull) >> 56) |
           ((v & 0x00FF000000000000ull) >> 40) |
           ((v & 0x0000FF0000000000ull) >> 24) |
           ((v & 0x000000FF00000000ull) >>  8) |
           ((v & 0x00000000FF000000ull) <<  8) |
           ((v & 0x0000000000FF0000ull) << 24) |
           ((v & 0x000000000000FF00ull) << 40) |
           ((v & 0x00000000000000FFull) << 56);
}

// Or use GCC/Clang builtins (not constexpr but faster):
// __builtin_bswap16, __builtin_bswap32, __builtin_bswap64

// Host-to-network and network-to-host conversions
uint16_t hton16(uint16_t v) {
    return HOST_BIG_ENDIAN ? v : bswap16(v);
}
uint32_t hton32(uint32_t v) {
    return HOST_BIG_ENDIAN ? v : bswap32(v);
}
uint16_t ntoh16(uint16_t v) { return hton16(v); }  // symmetric
uint32_t ntoh32(uint32_t v) { return hton32(v); }

// Safe read/write without alignment or aliasing issues
uint16_t read_be16(const uint8_t* p) {
    return static_cast<uint16_t>(
        static_cast<uint16_t>(p[0]) << 8 | p[1]);
}

uint32_t read_be32(const uint8_t* p) {
    return static_cast<uint32_t>(p[0]) << 24 |
           static_cast<uint32_t>(p[1]) << 16 |
           static_cast<uint32_t>(p[2]) <<  8 |
           static_cast<uint32_t>(p[3]);
}

uint16_t read_le16(const uint8_t* p) {
    return static_cast<uint16_t>(p[0]) |
           static_cast<uint16_t>(p[1]) << 8;
}

uint32_t read_le32(const uint8_t* p) {
    return static_cast<uint32_t>(p[0])        |
           static_cast<uint32_t>(p[1]) <<  8  |
           static_cast<uint32_t>(p[2]) << 16  |
           static_cast<uint32_t>(p[3]) << 24;
}

void write_be16(uint8_t* p, uint16_t v) {
    p[0] = static_cast<uint8_t>(v >> 8);
    p[1] = static_cast<uint8_t>(v & 0xFF);
}

void write_be32(uint8_t* p, uint32_t v) {
    p[0] = static_cast<uint8_t>(v >> 24);
    p[1] = static_cast<uint8_t>(v >> 16);
    p[2] = static_cast<uint8_t>(v >>  8);
    p[3] = static_cast<uint8_t>(v & 0xFF);
}
```

Always use explicit byte-by-byte reads and writes for multi-byte protocol fields. Never dereference a `uint16_t*` cast from a `uint8_t*` buffer — that's a strict aliasing violation and an alignment hazard on ARM.

---

## 3. Bitfields — Layout Guarantees and Pitfalls

C++ bitfields pack multiple fields into a single integer. Attractive for hardware register maps — with serious caveats:

```cpp
// Bitfield struct
struct StatusRegister {
    uint8_t ready      : 1;   // bit 0
    uint8_t error      : 1;   // bit 1
    uint8_t overflow   : 1;   // bit 2
    uint8_t mode       : 2;   // bits 3-4
    uint8_t reserved   : 3;   // bits 5-7
};

StatusRegister sr{};
sr.ready    = 1;
sr.mode     = 0b10;
uint8_t raw = *reinterpret_cast<uint8_t*>(&sr);
// raw == 0b00010001 on most compilers — but NOT guaranteed
```

### The Bitfield Portability Problem

The C++ standard leaves several things implementation-defined for bitfields:

- Bit ordering within a storage unit (LSB-first or MSB-first)
- Whether they span storage unit boundaries
- Signedness of plain `int` bitfields

This means the same bitfield struct can produce different byte layouts on different compilers, different platforms, or even different optimization settings. **Do not use bitfields for protocol parsing on real hardware if portability matters.**

What bitfields are good for:

- Local in-memory representation of hardware register state
- Readability of flag fields
- Compact storage when layout doesn't need to match a wire format exactly

```cpp
// Safe use: in-memory flags, not wire format
struct SensorFlags {
    uint8_t connected  : 1;
    uint8_t calibrated : 1;
    uint8_t fault      : 1;
    uint8_t : 5;  // padding
};

// Unsafe use: mapping onto a protocol byte
// — don't do this for cross-platform code
struct BadProtocolByte {
    uint8_t type    : 4;   // might be low nibble or high nibble
    uint8_t version : 4;   // depends on compiler
};
```

For protocol fields that must match a wire format exactly, use explicit bit manipulation (shifts and masks) or the approach in Section 5.

---

## 4. Struct Packing

By default, the compiler adds padding between struct members for alignment. For protocol structs, you need exact layout control:

```cpp
// Default — padding inserted
struct Unpacked {
    uint8_t  type;       // byte 0
    // 3 bytes padding
    uint32_t length;     // bytes 4-7
    uint16_t checksum;   // bytes 8-9
    // 2 bytes padding
};
// sizeof(Unpacked) == 12 — not 7

// GCC/Clang attribute — removes all padding
struct __attribute__((packed)) Packed {
    uint8_t  type;       // byte 0
    uint32_t length;     // bytes 1-4 (UNALIGNED on ARM — dangerous)
    uint16_t checksum;   // bytes 5-6
};
// sizeof(Packed) == 7 — but uint32_t at offset 1 is misaligned

// Best approach: manual layout, largest first
struct WellOrdered {
    uint32_t length;     // bytes 0-3 — aligned to 4
    uint16_t checksum;   // bytes 4-5 — aligned to 2
    uint8_t  type;       // byte 6
    uint8_t  _pad;       // byte 7 — explicit, documented
};
// sizeof(WellOrdered) == 8 — aligned, no hidden padding
static_assert(sizeof(WellOrdered) == 8);
static_assert(offsetof(WellOrdered, checksum) == 4);
```

**Avoid `__attribute__((packed))` for structs you access via pointer.** Accessing a misaligned `uint32_t` on ARM Cortex-M (without unaligned access support) triggers a hardware fault. Even on Cortex-A with unaligned access enabled, it's slower and generates different code.

The correct approach for protocols: keep structs naturally aligned in memory, parse/serialize explicitly with byte-by-byte read/write functions.

---

## 5. `std::bitset` — Bit Arrays

`std::bitset<N>` is a fixed-size array of bits with set/test/flip operations and bitwise operators:

```cpp
#include <bitset>

std::bitset<8> flags;
flags.set(3);              // set bit 3
flags.set(7);              // set bit 7
flags[1] = 1;              // set bit 1
flags.reset(3);            // clear bit 3
flags.flip(0);             // toggle bit 0

flags.test(7);             // true — bit 7 is set
flags.count();             // number of set bits
flags.any();               // true if any bit set
flags.all();               // true if all bits set
flags.none();              // true if no bits set

flags.to_ulong();          // convert to unsigned long
flags.to_string();         // "10000010" — MSB first in string

// Bitwise operations
std::bitset<8> mask(0b11110000);
auto result = flags & mask;   // AND
result = flags | mask;        // OR
result = flags ^ mask;        // XOR
result = ~flags;              // NOT

// Useful for tracking which sensors have reported
std::bitset<16> reported;      // 16 sensors
reported.set(sensor_id);       // mark sensor as reported
if (reported.count() == 16) {  // all sensors reported
    process_complete_set();
}
```

---

## 6. Putting It Together — Modbus RTU Parser

Modbus RTU is the most common industrial protocol in IoT gateways. It runs over RS-485, uses big-endian byte order, and has strict CRC validation. A full implementation:

```cpp
// modbus_rtu.cpp
#include <cstdio>
#include <cstdint>
#include <cstring>
#include <cstdlib>
#include <array>
#include <span>
#include <vector>
#include <optional>
#include <string_view>
#include <cassert>
#include <bitset>

// ---- CRC-16 (Modbus polynomial 0x8005, reflected) ----
// Generated at compile time per Day 23

constexpr std::array<uint16_t, 256> make_modbus_crc_table() {
    std::array<uint16_t, 256> t{};
    for (uint32_t i = 0; i < 256; ++i) {
        uint16_t crc = static_cast<uint16_t>(i);
        for (int j = 0; j < 8; ++j) {
            crc = (crc & 1) ? (crc >> 1) ^ 0xA001u : (crc >> 1);
        }
        t[i] = crc;
    }
    return t;
}

constexpr auto MODBUS_CRC_TABLE = make_modbus_crc_table();

uint16_t modbus_crc16(std::span<const uint8_t> data) {
    uint16_t crc = 0xFFFF;
    for (uint8_t b : data) {
        crc = (crc >> 8) ^ MODBUS_CRC_TABLE[(crc ^ b) & 0xFF];
    }
    return crc;
}

// ---- Modbus RTU constants ----

namespace Modbus {
    // Function codes
    constexpr uint8_t FC_READ_COILS              = 0x01;
    constexpr uint8_t FC_READ_DISCRETE_INPUTS    = 0x02;
    constexpr uint8_t FC_READ_HOLDING_REGISTERS  = 0x03;
    constexpr uint8_t FC_READ_INPUT_REGISTERS    = 0x04;
    constexpr uint8_t FC_WRITE_SINGLE_COIL       = 0x05;
    constexpr uint8_t FC_WRITE_SINGLE_REGISTER   = 0x06;
    constexpr uint8_t FC_WRITE_MULTIPLE_COILS    = 0x0F;
    constexpr uint8_t FC_WRITE_MULTIPLE_REGS     = 0x10;

    // Exception flag — if set in function code, response is an exception
    constexpr uint8_t EXCEPTION_FLAG             = 0x80;

    // Exception codes
    constexpr uint8_t EX_ILLEGAL_FUNCTION        = 0x01;
    constexpr uint8_t EX_ILLEGAL_DATA_ADDRESS    = 0x02;
    constexpr uint8_t EX_ILLEGAL_DATA_VALUE      = 0x03;
    constexpr uint8_t EX_SLAVE_DEVICE_FAILURE    = 0x04;

    // Coil write values
    constexpr uint16_t COIL_ON                   = 0xFF00;
    constexpr uint16_t COIL_OFF                  = 0x0000;

    // Frame size constraints
    constexpr size_t MIN_FRAME_SIZE              = 4;  // addr + fc + 1 byte + 2 CRC
    constexpr size_t MAX_FRAME_SIZE              = 256;
    constexpr size_t MAX_REGISTERS               = 125;
    constexpr size_t MAX_COILS                   = 2000;
}

// ---- Big-endian helpers ----

uint16_t read_be16(const uint8_t* p) {
    return static_cast<uint16_t>(
        static_cast<uint16_t>(p[0]) << 8 | p[1]);
}

void write_be16(uint8_t* p, uint16_t v) {
    p[0] = static_cast<uint8_t>(v >> 8);
    p[1] = static_cast<uint8_t>(v & 0xFF);
}

// ---- Parsed request ----

struct ModbusRequest {
    uint8_t  device_address;
    uint8_t  function_code;
    uint16_t start_address;
    uint16_t count;          // quantity of registers/coils
    bool     valid;

    void print() const {
        if (!valid) { printf("  [INVALID]\n"); return; }
        printf("  ModbusRequest{addr=%u fc=0x%02X "
               "start=0x%04X count=%u}\n",
               device_address, function_code,
               start_address, count);
    }
};

// ---- Parsed response ----

struct ModbusResponse {
    uint8_t              device_address;
    uint8_t              function_code;
    bool                 is_exception;
    uint8_t              exception_code;
    std::vector<uint16_t> registers;   // for register reads
    std::bitset<2000>    coils;         // for coil reads
    uint16_t             coil_count;
    bool                 valid;

    void print() const {
        if (!valid) { printf("  [INVALID]\n"); return; }
        if (is_exception) {
            printf("  Exception{addr=%u fc=0x%02X code=0x%02X}\n",
                   device_address,
                   function_code & ~Modbus::EXCEPTION_FLAG,
                   exception_code);
            return;
        }
        printf("  Response{addr=%u fc=0x%02X",
               device_address, function_code);
        if (!registers.empty()) {
            printf(" registers=[");
            for (size_t i = 0; i < registers.size(); ++i) {
                if (i > 0) printf(", ");
                printf("0x%04X", registers[i]);
            }
            printf("]");
        }
        if (coil_count > 0) {
            printf(" coils(%u)=[", coil_count);
            for (uint16_t i = 0; i < coil_count && i < 16; ++i)
                printf("%u", coils.test(i) ? 1 : 0);
            if (coil_count > 16) printf("...");
            printf("]");
        }
        printf("}\n");
    }
};

// ---- Frame parser ----

ModbusRequest parse_request(std::span<const uint8_t> frame) {
    ModbusRequest req{};

    // Minimum: addr(1) + fc(1) + data(1+) + crc(2)
    if (frame.size() < Modbus::MIN_FRAME_SIZE) return req;
    if (frame.size() > Modbus::MAX_FRAME_SIZE) return req;

    // CRC check — last 2 bytes, little-endian
    size_t data_len = frame.size() - 2;
    uint16_t frame_crc = static_cast<uint16_t>(
        frame[data_len] | (frame[data_len + 1] << 8));  // LE
    uint16_t computed  = modbus_crc16(frame.first(data_len));
    if (frame_crc != computed) return req;

    req.device_address = frame[0];
    req.function_code  = frame[1];

    // Parse based on function code
    switch (req.function_code) {
        case Modbus::FC_READ_COILS:
        case Modbus::FC_READ_DISCRETE_INPUTS:
        case Modbus::FC_READ_HOLDING_REGISTERS:
        case Modbus::FC_READ_INPUT_REGISTERS:
            // Read request: start_address(2) + count(2) = 4 bytes data
            if (frame.size() < 2 + 4 + 2) return req;
            req.start_address = read_be16(frame.data() + 2);
            req.count         = read_be16(frame.data() + 4);
            break;

        case Modbus::FC_WRITE_SINGLE_COIL:
        case Modbus::FC_WRITE_SINGLE_REGISTER:
            // Write single: address(2) + value(2) = 4 bytes data
            if (frame.size() < 2 + 4 + 2) return req;
            req.start_address = read_be16(frame.data() + 2);
            req.count         = read_be16(frame.data() + 4);  // value
            break;

        case Modbus::FC_WRITE_MULTIPLE_REGS:
            // Write multiple: start(2) + count(2) + byte_count(1) + data
            if (frame.size() < 2 + 5 + 2) return req;
            req.start_address = read_be16(frame.data() + 2);
            req.count         = read_be16(frame.data() + 4);
            break;

        default:
            return req;  // unknown function code
    }

    req.valid = true;
    return req;
}

ModbusResponse parse_response(std::span<const uint8_t> frame) {
    ModbusResponse resp{};

    if (frame.size() < Modbus::MIN_FRAME_SIZE) return resp;

    // CRC check
    size_t data_len = frame.size() - 2;
    uint16_t frame_crc = static_cast<uint16_t>(
        frame[data_len] | (frame[data_len + 1] << 8));
    if (frame_crc != modbus_crc16(frame.first(data_len))) return resp;

    resp.device_address = frame[0];
    resp.function_code  = frame[1];

    // Exception response
    if (resp.function_code & Modbus::EXCEPTION_FLAG) {
        if (frame.size() < 2 + 1 + 2) return resp;
        resp.is_exception   = true;
        resp.exception_code = frame[2];
        resp.valid          = true;
        return resp;
    }

    // Normal response — parse based on function code
    switch (resp.function_code) {
        case Modbus::FC_READ_HOLDING_REGISTERS:
        case Modbus::FC_READ_INPUT_REGISTERS: {
            // byte_count(1) + data(byte_count) + crc(2)
            if (frame.size() < 2 + 1 + 2) return resp;
            uint8_t byte_count = frame[2];
            if (byte_count % 2 != 0) return resp;  // must be even
            if (frame.size() < static_cast<size_t>(2 + 1 + byte_count + 2))
                return resp;
            uint8_t num_regs = byte_count / 2;
            resp.registers.reserve(num_regs);
            for (int i = 0; i < num_regs; ++i) {
                resp.registers.push_back(
                    read_be16(frame.data() + 3 + i * 2));
            }
            break;
        }

        case Modbus::FC_READ_COILS:
        case Modbus::FC_READ_DISCRETE_INPUTS: {
            // byte_count(1) + coil_bytes(byte_count) + crc(2)
            if (frame.size() < 2 + 1 + 2) return resp;
            uint8_t byte_count = frame[2];
            if (frame.size() < static_cast<size_t>(2 + 1 + byte_count + 2))
                return resp;
            // Unpack bits from bytes (LSB first within each byte)
            resp.coil_count = byte_count * 8;
            for (int b = 0; b < byte_count; ++b) {
                uint8_t byte_val = frame[3 + b];
                for (int bit = 0; bit < 8; ++bit) {
                    if ((byte_val >> bit) & 1) {
                        resp.coils.set(
                            static_cast<size_t>(b * 8 + bit));
                    }
                }
            }
            break;
        }

        case Modbus::FC_WRITE_SINGLE_COIL:
        case Modbus::FC_WRITE_SINGLE_REGISTER:
        case Modbus::FC_WRITE_MULTIPLE_REGS:
            // Echo: start_address(2) + quantity(2) + crc(2)
            if (frame.size() < 2 + 4 + 2) return resp;
            resp.registers.push_back(read_be16(frame.data() + 2));
            resp.registers.push_back(read_be16(frame.data() + 4));
            break;

        default:
            return resp;
    }

    resp.valid = true;
    return resp;
}

// ---- Frame builder ----

// Build read register request
// Returns frame size
size_t build_read_registers(
    uint8_t  device_addr,
    uint16_t start_addr,
    uint16_t count,
    std::span<uint8_t> out)
{
    assert(out.size() >= 8);
    out[0] = device_addr;
    out[1] = Modbus::FC_READ_HOLDING_REGISTERS;
    write_be16(out.data() + 2, start_addr);
    write_be16(out.data() + 4, count);
    uint16_t crc = modbus_crc16(out.first(6));
    out[6] = static_cast<uint8_t>(crc & 0xFF);   // CRC little-endian
    out[7] = static_cast<uint8_t>(crc >> 8);
    return 8;
}

// Build write single register request
size_t build_write_register(
    uint8_t  device_addr,
    uint16_t reg_addr,
    uint16_t value,
    std::span<uint8_t> out)
{
    assert(out.size() >= 8);
    out[0] = device_addr;
    out[1] = Modbus::FC_WRITE_SINGLE_REGISTER;
    write_be16(out.data() + 2, reg_addr);
    write_be16(out.data() + 4, value);
    uint16_t crc = modbus_crc16(out.first(6));
    out[6] = static_cast<uint8_t>(crc & 0xFF);
    out[7] = static_cast<uint8_t>(crc >> 8);
    return 8;
}

// Build simulated read register response
size_t build_read_response(
    uint8_t device_addr,
    std::span<const uint16_t> registers,
    std::span<uint8_t> out)
{
    assert(registers.size() <= Modbus::MAX_REGISTERS);
    uint8_t byte_count = static_cast<uint8_t>(registers.size() * 2);
    size_t  frame_size = 2 + 1 + byte_count + 2;
    assert(out.size() >= frame_size);

    out[0] = device_addr;
    out[1] = Modbus::FC_READ_HOLDING_REGISTERS;
    out[2] = byte_count;

    for (size_t i = 0; i < registers.size(); ++i) {
        write_be16(out.data() + 3 + i * 2, registers[i]);
    }

    uint16_t crc = modbus_crc16(out.first(3 + byte_count));
    out[3 + byte_count]     = static_cast<uint8_t>(crc & 0xFF);
    out[3 + byte_count + 1] = static_cast<uint8_t>(crc >> 8);
    return frame_size;
}

// Build exception response
size_t build_exception(
    uint8_t  device_addr,
    uint8_t  function_code,
    uint8_t  exception_code,
    std::span<uint8_t> out)
{
    assert(out.size() >= 5);
    out[0] = device_addr;
    out[1] = function_code | Modbus::EXCEPTION_FLAG;
    out[2] = exception_code;
    uint16_t crc = modbus_crc16(out.first(3));
    out[3] = static_cast<uint8_t>(crc & 0xFF);
    out[4] = static_cast<uint8_t>(crc >> 8);
    return 5;
}

int main() {
    printf("=== Modbus RTU Parser ===\n\n");

    std::array<uint8_t, 256> buf{};

    // ---- Read holding registers request ----
    printf("--- Read Holding Registers Request ---\n");
    {
        size_t len = build_read_registers(0x11, 0x006B, 3, buf);
        printf("Frame (%zu bytes): ", len);
        for (size_t i = 0; i < len; ++i)
            printf("%02X ", buf[i]);
        printf("\n");

        auto req = parse_request(std::span{buf}.first(len));
        req.print();
        assert(req.valid);
        assert(req.device_address == 0x11);
        assert(req.function_code  == Modbus::FC_READ_HOLDING_REGISTERS);
        assert(req.start_address  == 0x006B);
        assert(req.count          == 3);
    }

    // ---- Read holding registers response ----
    printf("\n--- Read Holding Registers Response ---\n");
    {
        const uint16_t regs[] = {0x022B, 0x0000, 0x0064};
        size_t len = build_read_response(
            0x11, std::span{regs}, buf);

        printf("Frame (%zu bytes): ", len);
        for (size_t i = 0; i < len; ++i)
            printf("%02X ", buf[i]);
        printf("\n");

        auto resp = parse_response(std::span{buf}.first(len));
        resp.print();
        assert(resp.valid && !resp.is_exception);
        assert(resp.registers.size() == 3);
        assert(resp.registers[0] == 0x022B);
        assert(resp.registers[2] == 0x0064);
    }

    // ---- Write single register ----
    printf("\n--- Write Single Register ---\n");
    {
        size_t len = build_write_register(0x11, 0x0001, 0x0003, buf);
        printf("Frame (%zu bytes): ", len);
        for (size_t i = 0; i < len; ++i)
            printf("%02X ", buf[i]);
        printf("\n");

        auto req = parse_request(std::span{buf}.first(len));
        req.print();
        assert(req.valid);
        assert(req.function_code == Modbus::FC_WRITE_SINGLE_REGISTER);
    }

    // ---- Exception response ----
    printf("\n--- Exception Response ---\n");
    {
        size_t len = build_exception(
            0x11,
            Modbus::FC_READ_HOLDING_REGISTERS,
            Modbus::EX_ILLEGAL_DATA_ADDRESS,
            buf);

        printf("Frame (%zu bytes): ", len);
        for (size_t i = 0; i < len; ++i)
            printf("%02X ", buf[i]);
        printf("\n");

        auto resp = parse_response(std::span{buf}.first(len));
        resp.print();
        assert(resp.valid && resp.is_exception);
        assert(resp.exception_code == Modbus::EX_ILLEGAL_DATA_ADDRESS);
    }

    // ---- CRC corruption detection ----
    printf("\n--- CRC Corruption Detection ---\n");
    {
        size_t len = build_read_registers(0x01, 0x0000, 10, buf);
        auto good = parse_request(std::span{buf}.first(len));
        printf("Good frame: %s\n", good.valid ? "valid" : "INVALID");

        buf[3] ^= 0xFF;  // corrupt data byte
        auto bad = parse_request(std::span{buf}.first(len));
        printf("Corrupted:  %s\n", bad.valid ? "valid" : "rejected");
        assert(good.valid && !bad.valid);
    }

    // ---- Bit manipulation demos ----
    printf("\n--- Bit manipulation ---\n");
    {
        uint16_t status = 0b0000000000000000;

        // Set individual status bits using bit manipulation
        status |=  (1u << 0);  // CONNECTED
        status |=  (1u << 3);  // DATA_READY
        status &= ~(1u << 1);  // clear ERROR

        printf("Status register: 0x%04X = 0b", status);
        for (int i = 15; i >= 0; --i)
            printf("%u", (status >> i) & 1);
        printf("\n");

        printf("CONNECTED:  %u\n", (status >> 0) & 1);
        printf("ERROR:      %u\n", (status >> 1) & 1);
        printf("DATA_READY: %u\n", (status >> 3) & 1);

        // Pack mode (3 bits) and channel (4 bits) into one byte
        uint8_t mode    = 0b101;   // 3-bit field
        uint8_t channel = 0b1001;  // 4-bit field
        uint8_t packed  = static_cast<uint8_t>(
            (mode & 0x07) | ((channel & 0x0F) << 3));
        printf("packed mode=%u channel=%u → 0x%02X = 0b%08b\n",
               mode, channel, packed, packed);

        // Verify endianness conversion
        uint32_t host_val = 0x12345678;
        uint32_t net_val  = bswap32(host_val);
        printf("Host: 0x%08X → Network: 0x%08X\n",
               host_val, net_val);
        assert(bswap32(net_val) == host_val);  // round-trip
    }

    // ---- std::bitset for coil tracking ----
    printf("\n--- std::bitset coil state ---\n");
    {
        std::bitset<16> coils;
        coils.set(0);   // coil 0 ON
        coils.set(3);   // coil 3 ON
        coils.set(7);   // coil 7 ON
        coils.set(15);  // coil 15 ON

        printf("Coil state: %s\n",
               coils.to_string().c_str());
        printf("Active coils: %zu\n", coils.count());

        // Pack into Modbus coil bytes (LSB first in each byte)
        uint8_t coil_bytes[2]{};
        for (int i = 0; i < 16; ++i) {
            if (coils.test(static_cast<size_t>(i))) {
                coil_bytes[i / 8] |=
                    static_cast<uint8_t>(1u << (i % 8));
            }
        }
        printf("Coil bytes: %02X %02X\n",
               coil_bytes[0], coil_bytes[1]);
    }

    printf("\nAll assertions passed.\n");
    return 0;
}
```

```bash
g++ -std=c++17 -Wall -Wextra -O2 -fsanitize=address,undefined \
    -o modbus modbus_rtu.cpp
./modbus
```

### What to observe

The CRC table from Day 23 is reused here unchanged — compile-time generated, in `.rodata`, referenced by the runtime `modbus_crc16()` function. The parser is zero-allocation for the request path — `ModbusRequest` is a plain struct on the stack. The response parser uses `std::vector` for register data, which allocates; in a production embedded implementation you'd use a fixed-size array.

The corruption test corrupts one data byte after building a valid frame, then attempts to parse it. The CRC check catches it — `parse_request` returns `valid=false`. This is exactly the behavior Modbus requires: a corrupted frame must be silently discarded, not partially processed.

The coil byte packing at the end shows the Modbus coil bit ordering: within each byte, bit 0 is the lowest-addressed coil. This is LSB-first, which is why you pack with `1u << (i % 8)` rather than `1u << (7 - i % 8)`.

---

## Key Takeaways for Day 25

- Bit set/clear/toggle/test: `|= (1<<n)`, `&= ~(1<<n)`, `^= (1<<n)`, `& (1<<n)`. Memorize these — they appear in every register and protocol implementation
- Endianness is a wire-format property, not a hardware property. Most IoT protocols are big-endian. Most hardware is little-endian. Always convert explicitly with byte-by-byte read/write functions
- Never dereference a `uint16_t*` or `uint32_t*` cast from a `uint8_t*` buffer — strict aliasing violation and alignment hazard. Use `read_be16()`, `read_le32()` style functions always
- Bitfields in C++ have implementation-defined bit ordering — safe for in-memory flags, unsafe for wire-format structs. Use explicit bit manipulation for protocols
- `__attribute__((packed))` removes padding but creates misaligned accesses — dangerous on ARM without hardware unaligned access support. Order struct members largest-to-smallest instead
- `static_assert(sizeof(T) == N)` and `static_assert(offsetof(T, field) == N)` — mandatory on any struct that maps to a binary format. Catch layout surprises at compile time
- Modbus CRC is CRC-16 with polynomial 0xA001 (reflected 0x8005), initialized to 0xFFFF, CRC appended little-endian. The table is 256 × uint16_t = 512 bytes in flash
- `std::bitset<N>` for coil arrays — clean API for bit-level state, converts to/from byte arrays with explicit bit-order control

Day 26 covers design patterns for embedded C++ — Singleton, Observer, State Machine, and CRTP — all implemented without virtual functions and without heap allocation.