

Everything you've built so far targets the machine you're working on. In embedded and IoT work, the machine you build on (x86-64 Linux) and the machine you run on (ARM Cortex-M, MIPS, RISC-V) are different. Cross-compilation bridges them. Today you learn CMake — the build system that handles this cleanly — and how to produce binaries for ARM targets from your development machine.

---

## Why CMake over Make for larger projects

Your hand-written Makefile from Day 10 works well for projects you control entirely. CMake is worth the transition when:

- You need to cross-compile for multiple targets from one codebase
- You're consuming or producing libraries that others use
- You want IDE integration (CLion, VS Code, Eclipse) without maintaining separate project files
- Your project has optional features, platform-specific code, or complex dependency graphs

CMake generates Makefiles (or Ninja files) — it's a build system generator, not a build system itself. You write `CMakeLists.txt`, run `cmake`, and get a `Makefile` or `build.ninja` that does the actual work.

---

## CMake fundamentals

```cmake
# CMakeLists.txt — minimum viable project
cmake_minimum_required(VERSION 3.16)
project(sensor_base VERSION 1.0.0 LANGUAGES C)

# Set C standard globally
set(CMAKE_C_STANDARD 11)
set(CMAKE_C_STANDARD_REQUIRED ON)
set(CMAKE_C_EXTENSIONS OFF)   # -std=c11, not -std=gnu11

# Warning flags
add_compile_options(
    -Wall
    -Wextra
    -Werror
    -Wformat-security
    -Wstack-protector
)

# Include directories available to all targets
include_directories(include)

# ── library target ────────────────────────────────────────────────
add_library(sensor_lib STATIC
    src/sensor.c
    src/errors.c
    src/log.c
    src/ringbuf.c
    src/protocol.c
    src/serial.c
)
target_include_directories(sensor_lib PUBLIC include)

# ── main executable ───────────────────────────────────────────────
add_executable(sensor_daemon src/main.c)
target_link_libraries(sensor_daemon PRIVATE sensor_lib)

# ── test executables ──────────────────────────────────────────────
enable_testing()

add_executable(test_sensor
    tests/test_sensor.c
    tests/unity/unity.c
)
target_link_libraries(test_sensor PRIVATE sensor_lib)
target_include_directories(test_sensor PRIVATE tests/unity)
add_test(NAME sensor COMMAND test_sensor)

add_executable(test_parser
    tests/test_parser.c
    tests/unity/unity.c
)
target_link_libraries(test_parser PRIVATE sensor_lib)
target_include_directories(test_parser PRIVATE tests/unity)
add_test(NAME parser COMMAND test_parser)
```

Build it:

```bash
mkdir build && cd build
cmake ..                    # configure — generates Makefile
make -j$(nproc)             # build
ctest --output-on-failure   # run tests
```

---

## Build types and compile options

CMake has four standard build types that map directly to your Day 10 Makefile targets:

```cmake
# Set build type from command line:
# cmake -DCMAKE_BUILD_TYPE=Debug ..
# cmake -DCMAKE_BUILD_TYPE=Release ..
# cmake -DCMAKE_BUILD_TYPE=RelWithDebInfo ..
# cmake -DCMAKE_BUILD_TYPE=MinSizeRel ..

# Custom flags per build type
set(CMAKE_C_FLAGS_DEBUG          "-g -O0 -fsanitize=address,undefined")
set(CMAKE_C_FLAGS_RELEASE        "-O2 -DNDEBUG")
set(CMAKE_C_FLAGS_RELWITHDEBINFO "-O2 -g -DNDEBUG")
set(CMAKE_C_FLAGS_MINSIZEREL     "-Os -DNDEBUG")

# Default to Debug if not specified
if(NOT CMAKE_BUILD_TYPE)
    set(CMAKE_BUILD_TYPE Debug)
endif()

message(STATUS "Build type: ${CMAKE_BUILD_TYPE}")

# Sanitizer link flags for Debug builds
if(CMAKE_BUILD_TYPE STREQUAL "Debug")
    target_link_options(sensor_daemon PRIVATE
        -fsanitize=address,undefined
    )
endif()
```

---

## Optional features and configuration

```cmake
# User-configurable options — set with -DENABLE_MQTT=ON
option(ENABLE_MQTT    "Build with paho-mqtt support"   OFF)
option(ENABLE_SERIAL  "Build with serial port support" ON)
option(BUILD_TESTS    "Build test suite"               ON)
option(VERBOSE_LOGS   "Enable debug log output"        OFF)

# Conditional compilation
if(ENABLE_MQTT)
    find_library(PAHO_LIB paho-mqtt3c REQUIRED)
    target_sources(sensor_lib PRIVATE src/mqtt_client.c)
    target_link_libraries(sensor_lib PUBLIC ${PAHO_LIB})
    target_compile_definitions(sensor_lib PUBLIC HAVE_MQTT=1)
    message(STATUS "MQTT support: enabled")
else()
    message(STATUS "MQTT support: disabled")
endif()

if(VERBOSE_LOGS)
    target_compile_definitions(sensor_lib PRIVATE LOG_LEVEL_DEFAULT=0)
else()
    target_compile_definitions(sensor_lib PRIVATE LOG_LEVEL_DEFAULT=1)
endif()

if(BUILD_TESTS)
    add_subdirectory(tests)
endif()
```

---

## Cross-compilation — the core concept

When you cross-compile, three machines are involved:

```
Build machine:  x86-64 Linux — where cmake and make run
Host machine:   ARM Linux — where the binary will run
Target machine: same as host for most embedded Linux work
                (differs for bare-metal toolchains)
```

CMake separates these through a **toolchain file** — a CMake script that tells CMake which compiler, linker, and system libraries to use instead of the defaults:

```cmake
# toolchains/arm-linux-gnueabihf.cmake
# Cross-compile for 32-bit ARM Linux (Raspberry Pi, BeagleBone, etc.)

# The target system
set(CMAKE_SYSTEM_NAME    Linux)
set(CMAKE_SYSTEM_PROCESSOR arm)

# The cross-compiler toolchain prefix
set(TOOLCHAIN_PREFIX arm-linux-gnueabihf)

# Compilers
set(CMAKE_C_COMPILER   ${TOOLCHAIN_PREFIX}-gcc)
set(CMAKE_CXX_COMPILER ${TOOLCHAIN_PREFIX}-g++)

# Binutils
set(CMAKE_AR           ${TOOLCHAIN_PREFIX}-ar)
set(CMAKE_RANLIB       ${TOOLCHAIN_PREFIX}-ranlib)
set(CMAKE_STRIP        ${TOOLCHAIN_PREFIX}-strip)

# Sysroot — the target system's libraries and headers
# Leave empty to use the toolchain's built-in sysroot
# set(CMAKE_SYSROOT /path/to/sysroot)

# Search behaviour:
# FIND_PROGRAM: only on build machine (we need build tools, not target tools)
# FIND_LIBRARY, FIND_INCLUDE: only in sysroot (we need target libraries)
set(CMAKE_FIND_ROOT_PATH_MODE_PROGRAM NEVER)
set(CMAKE_FIND_ROOT_PATH_MODE_LIBRARY ONLY)
set(CMAKE_FIND_ROOT_PATH_MODE_INCLUDE ONLY)
set(CMAKE_FIND_ROOT_PATH_MODE_PACKAGE ONLY)
```

```cmake
# toolchains/arm-none-eabi.cmake
# Bare-metal ARM Cortex-M (STM32, nRF52, etc.) — no OS, no libc

set(CMAKE_SYSTEM_NAME      Generic)   # no OS
set(CMAKE_SYSTEM_PROCESSOR arm)

set(TOOLCHAIN_PREFIX arm-none-eabi)
set(CMAKE_C_COMPILER   ${TOOLCHAIN_PREFIX}-gcc)
set(CMAKE_CXX_COMPILER ${TOOLCHAIN_PREFIX}-g++)
set(CMAKE_ASM_COMPILER ${TOOLCHAIN_PREFIX}-as)
set(CMAKE_AR           ${TOOLCHAIN_PREFIX}-ar)
set(CMAKE_RANLIB       ${TOOLCHAIN_PREFIX}-ranlib)
set(CMAKE_OBJCOPY      ${TOOLCHAIN_PREFIX}-objcopy)
set(CMAKE_SIZE         ${TOOLCHAIN_PREFIX}-size)

# Cortex-M4F specific flags
set(CPU_FLAGS "-mcpu=cortex-m4 -mthumb -mfpu=fpv4-sp-d16 -mfloat-abi=hard")

set(CMAKE_C_FLAGS_INIT   "${CPU_FLAGS} -ffunction-sections -fdata-sections")
set(CMAKE_EXE_LINKER_FLAGS_INIT "-Wl,--gc-sections --specs=nano.specs")

# No try-compile tests — cross-compiler can't run on build machine
set(CMAKE_TRY_COMPILE_TARGET_TYPE STATIC_LIBRARY)
```

---

## Installing the cross-toolchain

```bash
# ARM Linux (hard-float) — for Raspberry Pi, BeagleBone, etc.
sudo apt-get install gcc-arm-linux-gnueabihf \
                     binutils-arm-linux-gnueabihf

# ARM bare-metal — for STM32, nRF52, ESP32 (GCC port)
sudo apt-get install gcc-arm-none-eabi \
                     binutils-arm-none-eabi \
                     libnewlib-arm-none-eabi

# Verify installation
arm-linux-gnueabihf-gcc --version
arm-none-eabi-gcc --version

# AArch64 (64-bit ARM — Raspberry Pi 4 in 64-bit mode, etc.)
sudo apt-get install gcc-aarch64-linux-gnu
```

---

## Building for ARM Linux

```bash
# Configure for ARM Linux target
mkdir build-arm && cd build-arm
cmake .. \
    -DCMAKE_TOOLCHAIN_FILE=../toolchains/arm-linux-gnueabihf.cmake \
    -DCMAKE_BUILD_TYPE=Release \
    -DBUILD_TESTS=OFF        # tests won't run on build machine

make -j$(nproc)

# Verify the output is ARM
file sensor_daemon
# sensor_daemon: ELF 32-bit LSB executable, ARM, EABI5 version 1 (SYSV), ...

# Check binary size
arm-linux-gnueabihf-size sensor_daemon
#    text    data     bss     dec     hex filename
#   12480     532     128   13140    3354 sensor_daemon

# Strip for deployment — removes debug symbols
arm-linux-gnueabihf-strip sensor_daemon

# Copy to target (if connected)
scp sensor_daemon pi@raspberrypi.local:/home/pi/
```

---

## A complete CMakeLists.txt for a cross-platform project

```cmake
cmake_minimum_required(VERSION 3.16)

# Version from git tag if available
find_package(Git QUIET)
if(GIT_FOUND)
    execute_process(
        COMMAND ${GIT_EXECUTABLE} describe --tags --always --dirty
        OUTPUT_VARIABLE GIT_VERSION
        OUTPUT_STRIP_TRAILING_WHITESPACE
        ERROR_QUIET
    )
else()
    set(GIT_VERSION "unknown")
endif()

project(sensor_base
    VERSION 1.0.0
    DESCRIPTION "IoT sensor gateway"
    LANGUAGES C
)

set(CMAKE_C_STANDARD 11)
set(CMAKE_C_STANDARD_REQUIRED ON)
set(CMAKE_C_EXTENSIONS OFF)

# ── options ───────────────────────────────────────────────────────
option(ENABLE_MQTT    "paho-mqtt support"    OFF)
option(ENABLE_SERIAL  "Serial port support"  ON)
option(BUILD_TESTS    "Build test suite"     ON)
option(BUILD_SHARED   "Build shared library" OFF)

# ── compiler warnings ─────────────────────────────────────────────
add_compile_options(
    -Wall -Wextra -Werror
    -Wformat=2
    -Wformat-security
    -Wnull-dereference
    -Wstack-protector
    $<$<C_COMPILER_ID:GNU>:-Wdouble-promotion>
    $<$<C_COMPILER_ID:GNU>:-Wlogical-op>
)

# ── build type defaults ───────────────────────────────────────────
if(NOT CMAKE_BUILD_TYPE)
    set(CMAKE_BUILD_TYPE Debug CACHE STRING "Build type" FORCE)
endif()
message(STATUS "Build type: ${CMAKE_BUILD_TYPE}")
message(STATUS "Version:    ${GIT_VERSION}")
message(STATUS "Target:     ${CMAKE_SYSTEM_NAME} ${CMAKE_SYSTEM_PROCESSOR}")

# ── library ───────────────────────────────────────────────────────
set(LIB_SOURCES
    src/sensor.c
    src/errors.c
    src/log.c
    src/ringbuf.c
    src/protocol.c
    src/workqueue.c
)

if(ENABLE_SERIAL)
    list(APPEND LIB_SOURCES src/serial.c)
    add_compile_definitions(HAVE_SERIAL=1)
endif()

if(BUILD_SHARED)
    add_library(sensor_lib SHARED ${LIB_SOURCES})
else()
    add_library(sensor_lib STATIC ${LIB_SOURCES})
endif()

target_include_directories(sensor_lib
    PUBLIC  include          # public headers
    PRIVATE src              # private headers
)

target_compile_definitions(sensor_lib PRIVATE
    VERSION_STRING="${GIT_VERSION}"
)

# Platform-specific
if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
    target_link_libraries(sensor_lib PUBLIC pthread)
endif()

if(ENABLE_MQTT)
    find_library(PAHO_LIB paho-mqtt3c REQUIRED)
    find_path(PAHO_INCLUDE MQTTClient.h REQUIRED)
    target_sources(sensor_lib PRIVATE src/mqtt_client.c)
    target_include_directories(sensor_lib PRIVATE ${PAHO_INCLUDE})
    target_link_libraries(sensor_lib PUBLIC ${PAHO_LIB})
    target_compile_definitions(sensor_lib PUBLIC HAVE_MQTT=1)
endif()

# ── executable ────────────────────────────────────────────────────
# Only build main executable when not cross-compiling for bare-metal
if(NOT CMAKE_SYSTEM_NAME STREQUAL "Generic")
    add_executable(sensor_daemon src/main.c)
    target_link_libraries(sensor_daemon PRIVATE sensor_lib)

    # Hardening flags for Linux targets
    if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
        target_compile_options(sensor_daemon PRIVATE
            -fstack-protector-strong
            -fPIE
        )
        target_link_options(sensor_daemon PRIVATE
            -pie
            -Wl,-z,relro
            -Wl,-z,now
        )
    endif()

    # Sanitizers in Debug builds
    if(CMAKE_BUILD_TYPE STREQUAL "Debug" AND
       CMAKE_SYSTEM_PROCESSOR STREQUAL CMAKE_HOST_SYSTEM_PROCESSOR)
        target_compile_options(sensor_daemon PRIVATE
            -fsanitize=address,undefined
        )
        target_link_options(sensor_daemon PRIVATE
            -fsanitize=address,undefined
        )
    endif()

    # Install rules
    install(TARGETS sensor_daemon
        RUNTIME DESTINATION bin
    )
    install(FILES
        systemd/sensor_daemon.service
        DESTINATION /etc/systemd/system
        OPTIONAL
    )
endif()

# ── install library and headers ───────────────────────────────────
install(TARGETS sensor_lib
    ARCHIVE DESTINATION lib
    LIBRARY DESTINATION lib
)
install(DIRECTORY include/
    DESTINATION include
)

# ── tests ─────────────────────────────────────────────────────────
if(BUILD_TESTS AND
   CMAKE_SYSTEM_PROCESSOR STREQUAL CMAKE_HOST_SYSTEM_PROCESSOR)
    enable_testing()
    add_subdirectory(tests)
endif()
```

```cmake
# tests/CMakeLists.txt
set(UNITY_DIR ${CMAKE_CURRENT_SOURCE_DIR}/unity)
add_library(unity STATIC ${UNITY_DIR}/unity.c)
target_include_directories(unity PUBLIC ${UNITY_DIR})

function(add_unity_test TEST_NAME)
    add_executable(${TEST_NAME} ${TEST_NAME}.c)
    target_link_libraries(${TEST_NAME} PRIVATE sensor_lib unity)
    add_test(NAME ${TEST_NAME} COMMAND ${TEST_NAME})
    set_tests_properties(${TEST_NAME} PROPERTIES
        PASS_REGULAR_EXPRESSION "OK"
        FAIL_REGULAR_EXPRESSION "FAIL"
    )
endfunction()

add_unity_test(test_sensor)
add_unity_test(test_parser)
add_unity_test(test_ringbuf)
add_unity_test(test_workqueue)
add_unity_test(test_controller)
```

---

## pkg-config for dependency management

When your project links against system libraries (libpaho, libsqlite3, libssl), `pkg-config` provides the correct flags without hardcoding paths:

```cmake
# Find a library via pkg-config
find_package(PkgConfig REQUIRED)

pkg_check_modules(SQLITE3 REQUIRED sqlite3)
target_include_directories(sensor_lib PRIVATE ${SQLITE3_INCLUDE_DIRS})
target_link_libraries(sensor_lib PRIVATE ${SQLITE3_LIBRARIES})
target_compile_options(sensor_lib PRIVATE ${SQLITE3_CFLAGS_OTHER})

pkg_check_modules(OPENSSL openssl)
if(OPENSSL_FOUND)
    target_link_libraries(sensor_lib PRIVATE ${OPENSSL_LIBRARIES})
    target_compile_definitions(sensor_lib PRIVATE HAVE_TLS=1)
endif()
```

---

## Release vs debug vs size-optimised embedded builds

```bash
# Native debug — sanitizers, no optimisation
cmake -B build-debug -DCMAKE_BUILD_TYPE=Debug
cmake --build build-debug

# Native release — optimised
cmake -B build-release -DCMAKE_BUILD_TYPE=Release
cmake --build build-release

# ARM Linux release
cmake -B build-arm \
    -DCMAKE_TOOLCHAIN_FILE=toolchains/arm-linux-gnueabihf.cmake \
    -DCMAKE_BUILD_TYPE=Release \
    -DBUILD_TESTS=OFF
cmake --build build-arm

# ARM bare-metal — size optimised
cmake -B build-bare \
    -DCMAKE_TOOLCHAIN_FILE=toolchains/arm-none-eabi.cmake \
    -DCMAKE_BUILD_TYPE=MinSizeRel \
    -DBUILD_TESTS=OFF \
    -DENABLE_SERIAL=ON \
    -DENABLE_MQTT=OFF
cmake --build build-bare

# Check binary sizes
arm-none-eabi-size build-bare/libsensor_lib.a
```

---

## Day 29 exercise

1. Convert your `sensor_base` project from the hand-written Makefile to CMake. Verify that `cmake -B build && cmake --build build && ctest --test-dir build` produces identical test results. Confirm `-DCMAKE_BUILD_TYPE=Debug` adds sanitizer flags and `-DCMAKE_BUILD_TYPE=Release` produces an optimised binary.
    
2. Install `gcc-arm-linux-gnueabihf` and configure a cross-compilation build. Verify the output is an ARM ELF binary with `file`. If you have a Raspberry Pi or BeagleBone on your network, deploy and run it. If not, run it under QEMU: `qemu-arm -L /usr/arm-linux-gnueabihf ./sensor_daemon`.
    
3. Add a `VERSION_STRING` compile definition that embeds the git describe output into the binary. Add a `--version` flag to `main.c` that prints it. Verify it shows the correct version after a `git tag v1.0.0`.
    
4. Add an `install` target and a CPack configuration to produce a `.tar.gz` release archive:
    
    ```cmake
    include(CPack)
    set(CPACK_GENERATOR "TGZ")
    set(CPACK_PACKAGE_VERSION ${GIT_VERSION})
    ```
    
    Run `cmake --build build --target package` and verify the archive contains the binary and headers in the correct directory structure.
    

Day 30 is the capstone — a complete UART-to-TCP bridge daemon that uses every major technique from the course, with a full test suite, CMake build, and systemd service file.