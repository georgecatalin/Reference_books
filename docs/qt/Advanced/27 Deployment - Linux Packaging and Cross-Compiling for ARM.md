[[Advanced]]

## Day 27: Deployment — Linux Packaging and Cross-Compiling for ARM (Raspberry Pi/BeagleBone)

### Concept: Two Genuinely Different Deployment Problems

For `mqtt_monitor`, you have two real deployment scenarios that need different solutions:

1. **Distributing a self-contained Linux x86_64 binary** (e.g., a monitoring station running on a regular Linux desktop/server) — bundling Qt's shared libraries so the app runs on machines without a dev-equivalent Qt install.
2. **Cross-compiling for ARM targets** (Raspberry Pi, BeagleBone) — building _on_ your x86_64 dev machine _for_ the ARM architecture, since compiling directly on a Pi/BeagleBone is slow and sometimes memory-constrained for a full Qt app build.

These require different toolchains entirely — don't conflate them.

### Part 1: Self-Contained Linux Deployment — `linuxdeployqt`

The core problem: your app links against Qt's shared libraries (`.so` files), which may not exist (or exist in an incompatible version) on the target machine. `linuxdeployqt` bundles the app binary with all its actual runtime dependencies into a self-contained directory (or AppImage).

```bash
# Build in Release mode first — never ship a debug build (larger, slower,
# with debug symbols exposing internals unnecessarily)
mkdir build-release && cd build-release
cmake -DCMAKE_BUILD_TYPE=Release ..
cmake --build . -j$(nproc)
```

Get `linuxdeployqt` (typically a downloaded AppImage tool itself, not installed via package manager):

```bash
wget https://github.com/probonopd/linuxdeployqt/releases/download/continuous/linuxdeployqt-continuous-x86_64.AppImage
chmod +x linuxdeployqt-continuous-x86_64.AppImage
```

Run it against your built binary:

```bash
./linuxdeployqt-continuous-x86_64.AppImage ./mqtt_monitor_gui -appimage
```

This produces a single `.AppImage` file — a self-contained executable bundling your app plus every Qt library it actually links against, runnable on most Linux distributions without requiring Qt to be separately installed on the target machine.

**A real caveat worth stating plainly**: `linuxdeployqt` bundles Qt libraries but generally assumes glibc compatibility between build and target machines — building on a much newer distro than your deployment target can produce a binary that won't run due to glibc version mismatches. If your monitoring stations run an older/different Linux distro than your dev machine, either build on a matching-version container/VM, or consider static linking (below) to sidestep the dynamic glibc dependency more thoroughly — though full static linking against glibc itself has its own well-known complications and is generally avoided in favor of building in a matched environment.

### Part 2: Cross-Compiling for Raspberry Pi / BeagleBone

This is the deployment path that actually matters for your embedded targets, and it deserves the bulk of today's focus.

#### Setting Up a Cross-Compilation Toolchain

The realistic modern approach: use a pre-built Qt6-for-ARM cross-compilation environment rather than building Qt itself from source for ARM (which is a multi-hour build you generally want to avoid repeating). Options, roughly in order of how much setup they require:

1. **Raspberry Pi OS's own cross-compile SDK** (if targeting RPi specifically) — Raspberry Pi provides documented cross-compilation instructions and a matching sysroot.
2. **A Docker-based cross-compilation container** — genuinely the most maintainable approach for a project you'll rebuild repeatedly; you pin exact toolchain/Qt versions in a Dockerfile, and any dev machine can reproduce the same build environment.
3. **Buildroot/Yocto**, if `mqtt_monitor` is part of a larger custom embedded Linux image build (this is a bigger commitment — relevant if you're building a full custom OS image for these boards, overkill if you're deploying onto a standard Raspberry Pi OS install).

Given your BeagleBone/RPi background already involves embedded Linux toolchains, Option 2 (Docker-based, pinned toolchain) is the practical recommendation for keeping cross-builds reproducible across however many dev machines/CI runners you eventually use.

#### CMake Toolchain File — The Actual Mechanism

Cross-compiling in CMake means providing a **toolchain file** that tells CMake to use the ARM compiler/sysroot instead of your host's native one:

`arm-toolchain.cmake`:

```cmake
set(CMAKE_SYSTEM_NAME Linux)
set(CMAKE_SYSTEM_PROCESSOR arm)

# Paths depend entirely on your specific cross-toolchain installation —
# these are illustrative, adjust to your actual SDK's layout
set(CMAKE_C_COMPILER /opt/rpi-toolchain/bin/arm-linux-gnueabihf-gcc)
set(CMAKE_CXX_COMPILER /opt/rpi-toolchain/bin/arm-linux-gnueabihf-g++)

set(CMAKE_SYSROOT /opt/rpi-toolchain/sysroot)
set(CMAKE_FIND_ROOT_PATH /opt/rpi-toolchain/sysroot)

# Critical settings — search for programs on the HOST (they need to
# actually run during the build, e.g. moc itself), but search for
# libraries/headers only within the target SYSROOT, never accidentally
# picking up host x86_64 libraries and linking them into an ARM binary
set(CMAKE_FIND_ROOT_PATH_MODE_PROGRAM NEVER)
set(CMAKE_FIND_ROOT_PATH_MODE_LIBRARY ONLY)
set(CMAKE_FIND_ROOT_PATH_MODE_INCLUDE ONLY)

# Point at a Qt6 build that was itself configured/built for ARM —
# this is the Qt-for-ARM install, separate from your host Qt install
set(QT_HOST_PATH /usr) # host Qt install, needed for moc/uic/rcc,
                          # which must run on the BUILD machine (x86_64),
                          # not the target — this is a genuinely easy
                          # thing to get backwards
set(CMAKE_PREFIX_PATH /opt/qt6-arm)
```

Build with it:

```bash
mkdir build-arm && cd build-arm
cmake -DCMAKE_TOOLCHAIN_FILE=../arm-toolchain.cmake -DCMAKE_BUILD_TYPE=Release ..
cmake --build . -j$(nproc)
```

**The `QT_HOST_PATH` line deserves real emphasis**: `moc`, `uic`, and `rcc` (Day 1's code generators) are build tools — they need to run on your x86_64 build machine during compilation, producing generated C++ that then gets _compiled_ for ARM. Pointing `QT_HOST_PATH` at your ARM Qt install by mistake (a genuinely common first-attempt error) tries to run an ARM binary on your x86_64 machine and fails immediately and confusingly — worth understanding _why_ this split exists, not just copying the setting.

### Verifying and Deploying the Cross-Compiled Binary

```bash
file mqtt_monitor_gui
# Should report something like: ELF 32-bit LSB executable, ARM, ...
# — confirms you actually built for ARM, not accidentally native x86_64
```

Getting the binary (plus its Qt shared libraries, from the ARM sysroot, not your host) onto the actual device:

```bash
scp mqtt_monitor_gui pi@raspberrypi.local:/home/pi/mqtt_monitor/
scp /opt/qt6-arm/lib/libQt6*.so* pi@raspberrypi.local:/home/pi/mqtt_monitor/lib/
```

Running it on-device, pointing at the bundled libraries rather than relying on the device having a matching Qt install:

```bash
LD_LIBRARY_PATH=/home/pi/mqtt_monitor/lib ./mqtt_monitor_gui
```

### The Headless/No-Display Consideration

If any of your target boards run **headless** (no attached display, which is realistic for a BeagleBone acting purely as a data-collection node rather than a display station), your Qt Widgets GUI genuinely cannot run there directly — Widgets requires a display server (X11 or Wayland). Two real options if this applies:

1. **Split architecture**: the GUI runs on one machine (a monitoring workstation with a display), and headless boards run only your existing C++/Python `mqtt_monitor` **backend** (serial/MQTT ingestion, no GUI at all) — this is likely the more realistic actual deployment shape given your project's history, with the Qt GUI as a _viewer_ application, not something running on every embedded node.
2. **`offscreen` Qt platform plugin** (`QT_QPA_PLATFORM=offscreen`) — lets a Qt Widgets app run without any real display, useful for automated screenshot generation or testing, but not a real solution for a human-facing GUI on headless hardware.

Given the project's actual architecture (serial/MQTT ingestion feeding a dashboard), option 1 is very likely the correct mental model: **the Qt GUI is a viewer/dashboard application, deployed to whatever machine a human is actually looking at — not to every headless data-collecting board.** The Pi/BeagleBone boards remain data sources (feeding MQTT/serial as they already do), and the Qt dashboard runs wherever someone needs to watch the data, which could be the same dev workstation you've been building on all along.

### Why This Matters

- **`QT_HOST_PATH` vs. `CMAKE_PREFIX_PATH` confusion is the single most common cross-compilation mistake** — internalizing _why_ build tools (moc/uic/rcc) must run on the host while the actual compiled output targets ARM will save you real debugging time versus just copying a toolchain file without understanding the split.
- **glibc version mismatches between build and deployment environments** are a genuinely common, confusing failure mode for both the AppImage and cross-compiled paths — matching build environment to target environment (via a pinned Docker container, ideally) is the practical fix, not something to discover reactively after a deployment fails mysteriously.
- **Recognizing that a GUI likely doesn't belong on every headless embedded node** is an architecture decision as much as a deployment one — it's worth deciding explicitly now (dashboard-as-viewer, not dashboard-on-every-board) rather than only discovering the display-server requirement when a deployment attempt on a headless BeagleBone fails.
- **`file` command verification** (`ELF ... ARM` vs. accidentally still x86_64) is a cheap, worthwhile sanity check after any cross-compile — catching "I actually just built for my host by mistake" immediately rather than after copying a useless binary to a device and wondering why it won't execute.

### Exercise

1. Set up (or research the exact current steps for, if full hardware access isn't immediately available) a Docker-based ARM cross-compilation container for your specific target (RPi vs. BeagleBone — toolchains differ), and produce a `file`-verified ARM binary of `mqtt_monitor_gui`.
2. If you have physical hardware available: deploy the cross-compiled binary plus its Qt shared library dependencies to an actual Pi/BeagleBone with a connected display, and confirm it launches and successfully connects to your MQTT broker over the network.
3. Write out (as a personal architecture note, similar to Day 24's exercise) which of your physical boards will run the full Qt GUI (if any) versus which remain headless data sources only feeding MQTT/serial to wherever the dashboard actually runs — making the "viewer vs. data source" architecture decision explicit for your actual deployment plan, rather than leaving it implicit.

### Key Takeaways

- Self-contained x86_64 Linux deployment uses `linuxdeployqt` to bundle Qt shared libraries into an AppImage — watch for glibc version mismatches between build and target environments.
- Cross-compiling for ARM requires a CMake toolchain file, a matching ARM-built Qt install, and critical care around `QT_HOST_PATH` (host tools: moc/uic/rcc) vs. the target sysroot (actual compiled output) — this distinction is the most common source of cross-compile confusion.
- Docker-based, version-pinned cross-compilation environments are the most maintainable approach for repeated builds across dev machines/CI.
- Qt Widgets requires a display server — headless boards can't run the GUI directly; the realistic `mqtt_monitor` architecture is likely "GUI as viewer on a display-equipped machine, boards remain headless data sources," which is worth deciding explicitly rather than discovering as a deployment failure.

---

Say "next" for Day 28 (performance profiling — Qt Creator's profiler / `perf` on Linux, identifying real bottlenecks in paint events, signal/slot overhead at scale, and SQLite write patterns, grounded in the actual `mqtt_monitor` codebase you've built).