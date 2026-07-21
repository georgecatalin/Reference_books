[[Qt for Embedded Display targets]]

## Day 3: Cross-Compiling Qt Itself — Beyond Day 27's Application-Only Cross-Compile

### Concept: Day 27 Assumed a Pre-Built Qt-for-ARM Already Existed — Today Is Building That Thing

Day 27 of the Widgets curriculum showed you a toolchain file and `QT_HOST_PATH`, but it quietly assumed `/opt/qt6-arm` — a Qt6 build already configured and compiled for ARM — already existed, handed to you as a given. For a stock Raspberry Pi OS deployment (Day 2's recommendation), you actually have a much simpler option available first, worth stating clearly before diving into building Qt from source: **Raspberry Pi OS's own apt repositories often already carry a Qt6 build usable on-device**, and cross-compiling Qt itself is only necessary when you need EGLFS-specific configuration options, a newer Qt version than the distro ships, or modules the distro packaging omits. Today covers both the "do you even need this" decision and the real mechanics when you do.

### First: Do You Actually Need to Build Qt From Source?

```bash
# On the Pi itself, check what's already available
apt-cache search qt6-base
apt list --installed 2>/dev/null | grep qt6
```

If Raspberry Pi OS's packaged Qt6 already includes `eglfs` support (check for `libqt6gui6` built with EGL support — the package descriptions or `qtdiag` on-device will confirm) and the version is recent enough for your needs, **you can skip building Qt from source entirely** and just cross-compile your _application_ against that on-device Qt install, using Day 27's existing pattern with the sysroot pointed at the Pi's actual filesystem (via `rsync`-ing the Pi's `/usr` into your build machine's sysroot, or building directly on-device if the Pi's compile performance is tolerable for your app's size).

**The honest recommendation, consistent with Day 2's judgment call**: only build Qt from source when a specific, real requirement forces it — a distro Qt version too old for a module you need (Qt Mqtt's packaging availability varies by distro release), or EGLFS build options not enabled in the distro package. Building Qt itself is real, multi-hour, genuinely fiddly work; don't take it on preemptively.

### When You Do Need It: The Real Mechanics

Building Qt for a target means configuring Qt's own build system (`configure`, which itself wraps CMake in Qt6) with a target sysroot and the specific platform plugins you need enabled:

```bash
# Working from Qt6's source tree, cross-compiling for the Pi's ARM target
./configure \
    -release \
    -opengl es2 \
    -eglfs \
    -no-opengl-desktop \
    -device linux-rasp-pi-aarch64-g++ \
    -device-option CROSS_COMPILE=/opt/rpi-toolchain/bin/aarch64-linux-gnu- \
    -sysroot /opt/rpi-toolchain/sysroot \
    -prefix /opt/qt6-arm \
    -skip qtwebengine \
    -nomake examples \
    -nomake tests
```

**Each flag deserves explanation, since getting these wrong is the actual source of most Qt-cross-compile pain:**

- **`-device linux-rasp-pi-aarch64-g++`** — Qt ships **device profiles**, pre-defined configuration templates for common embedded targets, including several actual Raspberry Pi variants. Using the matching device profile handles a large amount of board-specific configuration (correct GPU/EGL library paths, correct default QPA plugin) that you'd otherwise have to discover and specify manually, error-prone, one flag at a time.
- **`-eglfs` + `-opengl es2` + `-no-opengl-desktop`** — explicitly building the EGLFS platform plugin and OpenGL ES (not desktop OpenGL, which doesn't exist on Pi's GPU) — omitting these is the single most common reason a from-source Qt build then fails to run under `eglfs` at all on-device, with confusing "platform plugin not found" errors.
- **`-skip qtwebengine`** — genuinely important for build time and complexity; Chromium-based `QtWebEngine` is an enormous, separately-complex build that `mqtt_monitor` has no use for at all — skip modules you don't need explicitly, don't build everything by default.
- **`-nomake examples -nomake tests`** — trims real build time; you don't need Qt's own example/test suite compiled for your target.

```bash
cmake --build . --parallel $(nproc)
cmake --install .
```

**Time expectation, stated honestly**: a full Qt6 cross-compile with a reasonable module set, on a capable multi-core build machine, is realistically **2–5+ hours**, not minutes. This is exactly why Day 2's "only do this if you actually need it" framing matters — this is a real, repeated cost every time you need a different Qt version or module configuration, not a one-time tax.

### The Sysroot — Getting This Right Is 90% of the Battle

The **sysroot** is a copy of the target's actual root filesystem (libraries, headers) that your cross-compiler references instead of your host machine's own libraries — this is what makes "compiled on x86_64, runs correctly on ARM" actually work, since the compiler needs ARM-compatible library versions to link against, not your host's.

```bash
# The practical, reliable way to build an accurate sysroot: copy it
# directly FROM the actual target device, rather than trying to
# reconstruct an equivalent set of packages manually on your build machine
rsync -avz --rsync-path="sudo rsync" \
    pi@raspberrypi.local:/lib /opt/rpi-toolchain/sysroot/
rsync -avz --rsync-path="sudo rsync" \
    pi@raspberrypi.local:/usr/include /opt/rpi-toolchain/sysroot/usr/
rsync -avz --rsync-path="sudo rsync" \
    pi@raspberrypi.local:/usr/lib /opt/rpi-toolchain/sysroot/usr/
```

**Why pulling the sysroot directly from the device, not reconstructing it, is the reliable approach**: the actual on-device library versions (glibc, the GPU driver's userspace libraries — Broadcom/VideoCore libraries on Pi specifically) need to match exactly what your compiled app will link against at runtime. Assembling an equivalent sysroot by hand from generic ARM packages risks a subtle version mismatch that manifests as a confusing runtime crash rather than a build-time error — pulling directly from the actual booted device sidesteps this entire class of problem.

### Verifying the Result — Don't Assume, Confirm

```bash
# On your BUILD machine, after cross-compiling Qt itself:
file /opt/qt6-arm/lib/libQt6Gui.so.6
# Should report: ELF 64-bit LSB shared object, ARM aarch64 — the same
# ARM-verification discipline from Day 27, now applied to Qt itself,
# not just your application binary

# Confirm the EGLFS plugin actually got built
ls /opt/qt6-arm/plugins/platforms/ | grep eglfs
# libqeglfs.so should be present — if it's missing, the -eglfs
# configure flag didn't take effect, and eglfs will fail with
# "could not find the platform plugin" at runtime on-device
```

### Why This Matters

- **Checking whether the distro's packaged Qt6 already suffices is the actual first step**, not an afterthought — this is the same "don't build infrastructure you don't need yet" discipline from Day 2 and Day 29/Widgets, now applied specifically to "do I need to cross-compile Qt at all."
- **Device profiles (`-device linux-rasp-pi-...`) exist precisely because manually specifying every board-specific EGL/GPU path is genuinely error-prone** — using the matching profile when one exists saves real, otherwise-easy-to-get-wrong configuration work.
- **Pulling the sysroot directly from the booted device**, rather than reconstructing an equivalent one, sidesteps an entire category of subtle version-mismatch bugs that are genuinely hard to diagnose after the fact (a crash on-device with no obvious build-time signal).
- **Verifying the EGLFS plugin actually built (`libqeglfs.so` present)** is the direct Qt-cross-compile analog of Day 27's `file` command check — cheap, and it catches a real, common mistake (forgetting `-eglfs`) before you've copied gigabytes of a wrong build onto a device and wondered why it won't start.

### Exercise

1. On your actual Raspberry Pi target, run `apt-cache search qt6-base` and `qtdiag` (if available) to genuinely determine whether the distro-packaged Qt6 already meets your needs — do this before committing to a from-source build, and write down the actual version/module findings rather than assuming either way.
2. If a from-source build turns out to be genuinely necessary, pull a real sysroot from your actual device via `rsync` as shown, and confirm (via `file`) that at least one core Qt library you'd eventually build is a plausible target for that sysroot's actual architecture.
3. If you do complete a full cross-compiled Qt build, run both verification checks (the `file` architecture check and the `libqeglfs.so` presence check) before copying anything to the device — treat both as required gates, not optional sanity checks, given how many hours a from-scratch rebuild costs if either is wrong.

### Key Takeaways

- Check whether the distro's packaged Qt6 already suffices before cross-compiling Qt from source — this is real, multi-hour work each time, and often unnecessary for a stock-OS deployment (Day 2's recommendation).
- Qt's device profiles (`-device linux-rasp-pi-...`) handle a meaningful amount of board-specific configuration that would otherwise be manual and error-prone — use the matching profile when one exists.
- `-eglfs -opengl es2 -no-opengl-desktop` are the flags that actually matter for embedded-display targets; omitting `-eglfs` specifically is the most common reason a from-source build then fails to find the platform plugin at runtime.
- Pull the sysroot directly from the booted target device via `rsync` rather than reconstructing an equivalent one — this avoids subtle library version mismatches that are hard to diagnose after the fact.
- Verify both the target architecture (`file`) and the presence of `libqeglfs.so` before deploying a cross-compiled Qt build to hardware — cheap checks against a very expensive rebuild if skipped.
