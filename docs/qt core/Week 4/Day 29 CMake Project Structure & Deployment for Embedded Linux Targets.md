[[Build]]

**Theory: why a real project isn't "one CMakeLists.txt per day's example"**

Every day so far used an isolated `CMakeLists.txt` per example — fine for learning, wrong for a real codebase. A production Qt Core project (like `mqtt_monitor`'s eventual C++ incarnation) needs: (1) shared logic (parser, models, config loader) built **once** as a library, (2) multiple executables (the main service, plus test binaries) linking against that library rather than recompiling it, and (3) a build that can target your development machine _and_ cross-compile for Raspberry Pi/BeagleBone without restructuring anything.

**Resolved project layout:**

```
mqtt_monitor/
├── CMakeLists.txt              # top-level: orchestrates everything below
├── src/
│   ├── CMakeLists.txt          # builds the shared library
│   ├── seriallineparser.h/.cpp
│   ├── devicereading.h/.cpp
│   ├── configloader.h/.cpp
│   └── readingserializer.h/.cpp
├── app/
│   ├── CMakeLists.txt          # builds the actual service executable
│   └── main.cpp
├── tests/
│   ├── CMakeLists.txt          # builds test binaries, links the library
│   ├── test_seriallineparser.cpp
│   └── test_devicereading.cpp
└── cmake/
    └── toolchain-rpi.cmake     # cross-compilation toolchain file, Day 29's focus
```

**Resolved top-level `CMakeLists.txt`:**

```cmake
cmake_minimum_required(VERSION 3.16)
project(mqtt_monitor VERSION 1.0.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_AUTOMOC ON)

# Resolved: -Wall -Wextra as a project-wide default, matching your existing
# C++ course discipline -- applied here, not per-file, so nothing forgets it.
add_compile_options(-Wall -Wextra)

option(BUILD_TESTING "Build test binaries" ON)

find_package(Qt6 REQUIRED COMPONENTS Core Network)

add_subdirectory(src)     # builds libmqtt_monitor_core
add_subdirectory(app)     # builds the actual service binary

if(BUILD_TESTING)
    enable_testing()
    add_subdirectory(tests)
endif()
```

**Resolved `src/CMakeLists.txt` — the shared library, built once:**

```cmake
add_library(mqtt_monitor_core STATIC
    seriallineparser.cpp
    devicereading.cpp
    configloader.cpp
    readingserializer.cpp
)

target_include_directories(mqtt_monitor_core PUBLIC ${CMAKE_CURRENT_SOURCE_DIR})
target_link_libraries(mqtt_monitor_core PUBLIC Qt6::Core Qt6::Network)

# Resolved: PUBLIC on both calls above means anything linking against
# mqtt_monitor_core automatically inherits the include path AND the Qt6
# dependency -- app/ and tests/ don't need to repeat find_package or
# target_include_directories themselves for these headers.
```

**Resolved `app/CMakeLists.txt` — the actual service, linking the library:**

```cmake
add_executable(mqtt_monitor main.cpp)
target_link_libraries(mqtt_monitor PRIVATE mqtt_monitor_core)
```

**Resolved `tests/CMakeLists.txt` — reusing the SAME compiled library, not recompiling the sources:**

```cmake
find_package(Qt6 REQUIRED COMPONENTS Test)

add_executable(test_seriallineparser test_seriallineparser.cpp)
target_link_libraries(test_seriallineparser PRIVATE mqtt_monitor_core Qt6::Test)
add_test(NAME SerialLineParserTests COMMAND test_seriallineparser)

add_executable(test_devicereading test_devicereading.cpp)
target_link_libraries(test_devicereading PRIVATE mqtt_monitor_core Qt6::Test)
add_test(NAME DeviceReadingTests COMMAND test_devicereading)
```

Resolved payoff, made concrete: `seriallineparser.cpp` compiles **exactly once**, into `mqtt_monitor_core`, regardless of how many executables (the app, and each test binary) use it — versus Day 28's isolated test setup, which would have recompiled the parser separately for every test binary if you'd had several. For a project this size the compile-time difference is small, but the structural correctness (single source of truth for build flags, one place to add a new source file) matters as the codebase grows past what any single day's example represents.

**Resolved cross-compilation toolchain file for Raspberry Pi (`cmake/toolchain-rpi.cmake`):**

```cmake
# Resolved: this file tells CMake to use the ARM cross-compiler instead of
# your host machine's compiler, and where to find the Pi's Qt6 libraries
# (assumed already installed in a sysroot you've prepared separately --
# building that sysroot itself is a real, separate undertaking, typically
# via a Yocto/Buildroot image or Raspberry Pi's own cross-compilation docs).

set(CMAKE_SYSTEM_NAME Linux)
set(CMAKE_SYSTEM_PROCESSOR arm)

set(CMAKE_C_COMPILER   arm-linux-gnueabihf-gcc)
set(CMAKE_CXX_COMPILER arm-linux-gnueabihf-g++)

# The sysroot contains the Pi's actual root filesystem, including its Qt6
# libraries and headers, mirrored onto your development machine.
set(CMAKE_SYSROOT /opt/rpi-sysroot)

set(CMAKE_FIND_ROOT_PATH ${CMAKE_SYSROOT})

# Resolved, critical settings: programs (like moc, which must run on YOUR
# machine during the build) are found on the HOST; libraries and headers
# (which must match the TARGET architecture) are found only in the sysroot.
set(CMAKE_FIND_ROOT_PATH_MODE_PROGRAM NEVER)
set(CMAKE_FIND_ROOT_PATH_MODE_LIBRARY ONLY)
set(CMAKE_FIND_ROOT_PATH_MODE_INCLUDE ONLY)
set(CMAKE_FIND_ROOT_PATH_MODE_PACKAGE ONLY)
```

**Resolved build invocation, host vs cross-compiled:**

```bash
# Normal build, on your development machine, for testing:
cmake -B build -S .
cmake --build build

# Cross-compiled build, targeting the Raspberry Pi:
cmake -B build-rpi -S . -DCMAKE_TOOLCHAIN_FILE=cmake/toolchain-rpi.cmake
cmake --build build-rpi
```

Resolved explanation of the trickiest setting: **`moc` (Day 1's meta-object compiler) must run on your development machine**, since it's a code-generation step happening _during_ the build — it's not part of the final ARM binary at all. But the Qt6 _libraries_ your code links against, and the headers it includes, must be the **ARM versions** matching the Pi's actual runtime. This is exactly why `CMAKE_FIND_ROOT_PATH_MODE_PROGRAM` is set to `NEVER` (meaning: look for programs like `moc` on the host system as normal, ignore the sysroot for that) while `LIBRARY`/`INCLUDE` are set to `ONLY` (meaning: look _exclusively_ inside the ARM sysroot for headers/libs, never accidentally link against your host's x86-64 Qt6 by mistake). Getting this backwards is a real, resolved failure mode: either `moc` fails to run at all (if CMake tries to run an ARM-compiled `moc` binary on your x86 machine), or your binary links against the wrong-architecture Qt libraries and fails to run on the Pi (or worse, appears to build fine but crashes immediately on the target).

**Resolved deployment note: systemd service file, tying back to Day 2's graceful shutdown discipline**

```ini
# /etc/systemd/system/mqtt_monitor.service
[Unit]
Description=MQTT Device Monitor
After=network.target

[Service]
ExecStart=/usr/local/bin/mqtt_monitor
Restart=on-failure
RestartSec=5
# Resolved: systemd sends SIGTERM by default on stop/restart, and waits
# TimeoutStopSec (default 90s) before escalating to SIGKILL -- this is
# EXACTLY why Day 2's SIGTERM handler calling quit() (rather than nothing,
# or exit()) matters: without it, systemd would eventually SIGKILL the
# process after the timeout, skipping any cleanup (like Day 7's log flush
# on destruction) entirely.

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable mqtt_monitor
sudo systemctl start mqtt_monitor
sudo journalctl -u mqtt_monitor -f   # live log tail -- and if you route
                                       # QLoggingCategory (Day 27) output
                                       # through the default handler, it
                                       # integrates with journald automatically
```

**Key takeaways:**

- Structure a real project as a shared library (`src/`) linked by both the actual app (`app/`) and every test binary (`tests/`) — compile shared logic once, not per-executable, and keep one source of truth for build flags and dependencies.
- `PUBLIC` vs `PRIVATE` in `target_link_libraries`/`target_include_directories` controls whether dependent targets automatically inherit that dependency — use `PUBLIC` for anything consumers of your library need to also see (headers, Qt6 itself), `PRIVATE` for implementation details they shouldn't need to know about.
- Cross-compiling for embedded targets (Raspberry Pi, BeagleBone) requires a toolchain file specifying the target compiler and a sysroot containing the target's actual libraries/headers — and critically, telling CMake to still find build-time tools like `moc` on the _host_, while sourcing libraries/headers _only_ from the target sysroot.
- Day 2's graceful-shutdown SIGTERM handling isn't academic — it's exactly what makes a systemd-managed service (`Restart=on-failure`, default SIGTERM-then-SIGKILL-after-timeout stop behavior) actually flush buffered data (Day 7) and clean up correctly on every restart or stop, rather than being SIGKILLed after systemd's timeout elapses.

Day 30 is the course capstone: a Qt Core serial→JSON→network relay integrating threading (Days 15–18), JSON (Day 10), sockets (Days 22–23), and the state machine (Day 25) into one complete, structurally sound component — the fullest synthesis of everything covered across all 30 days.