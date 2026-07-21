[[Advanced Qt Quick]]
# Day 25 — Deployment: Linux Desktop Packaging and Boot to Qt on Raspberry Pi

Everything so far ran from `cmake --build` on your dev machine. Today: getting a real, distributable binary — both for a Linux desktop (your dev/admin workstation) and for your actual Pi deployment target, which is a meaningfully different process worth understanding properly rather than treating as an afterthought.

## Concept: Two genuinely different deployment targets, two different processes

**Desktop Linux deployment** — your app runs on a general-purpose Linux system that may or may not have Qt installed; you need to bundle Qt's shared libraries and QML modules alongside your binary.

**Embedded Linux (Raspberry Pi) deployment** — you're cross-compiling for a different architecture (ARM, likely aarch64 on modern Pi models) from your x86_64 dev machine, targeting a minimal OS image, often without a desktop environment at all — your app _is_ the display, full-screen, kiosk-style. This is the more relevant path for `mqtt_monitor`'s actual purpose.

## Concept: Desktop packaging — `linuxdeployqt` / `windeployqt`-equivalent

```bash
# linuxdeployqt bundles your binary + all Qt dependencies + QML modules into an AppImage
wget -c "https://github.com/probonopd/linuxdeployqt/releases/download/continuous/linuxdeployqt-continuous-x86_64.AppImage"
chmod +x linuxdeployqt-continuous-x86_64.AppImage

./linuxdeployqt-continuous-x86_64.AppImage build/appMonitor \
    -qmldir=. \
    -appimage
```

`-qmldir=.` is essential and easy to forget — without it, `linuxdeployqt` only sees C++ library dependencies, not the QML modules your app imports (`QtQuick.Controls`, `QtCharts`, `MonitorApp` itself) — the resulting AppImage would launch and immediately fail with "module not found" errors on a machine without a full Qt dev install, exactly the machine you're trying to support by bundling in the first place.

The output `.AppImage` is a single self-contained executable — genuinely simple to distribute (copy one file, `chmod +x`, run) for a desktop admin tool use case, no installer needed.

## Concept: Cross-compiling for Raspberry Pi — the real path

You have two realistic options, and the right choice depends on how much control you want over the base OS:

**Option A — Boot to Qt** (Qt's own commercial embedded Linux distribution, via the Qt Online Installer's embedded targets). Gives you a purpose-built, minimal, Qt-optimized image — least friction, but requires appropriate licensing for commercial use (open-source Qt terms apply if your project qualifies).

**Option B — Raspberry Pi OS + cross-compiled Qt** (fully open, more setup work, complete control). This is more likely your actual path given your existing Raspberry Pi OS-based embedded work:

```bash
# On your x86_64 dev machine — set up a cross-compilation toolchain
# (This assumes you've built or obtained a Qt6 cross-compiled for aarch64,
#  either via Qt's official Raspberry Pi build instructions or a pre-built SDK)

cmake -B build-rpi \
    -DCMAKE_TOOLCHAIN_FILE=/path/to/qt6-rpi-toolchain.cmake \
    -DCMAKE_BUILD_TYPE=Release

cmake --build build-rpi -j$(nproc)
```

**The toolchain file is the crux of the whole cross-compilation setup** — it tells CMake to use the ARM cross-compiler, ARM-target Qt libraries, and ARM sysroot instead of your host's native x86_64 tools. Getting this wrong (mixing host and target libraries) produces binaries that either fail to link or crash immediately on the Pi with cryptic "wrong ELF class" errors — worth knowing that specific error message, since it's the unambiguous signal of a host/target architecture mismatch, not a logic bug in your code.

## Concept: Deploying to the device — what actually needs to be there

```bash
# Copy your compiled binary + any bundled Qt libs not already on the Pi's rootfs
rsync -avz build-rpi/appMonitor pi@raspberrypi.local:/opt/mqtt_monitor/
rsync -avz /path/to/qt6-rpi-libs/ pi@raspberrypi.local:/opt/mqtt_monitor/lib/

# On the Pi, set library path so the binary finds the bundled Qt libs
export LD_LIBRARY_PATH=/opt/mqtt_monitor/lib:$LD_LIBRARY_PATH
export QT_QPA_PLATFORM=eglfs   # full-screen, no window manager — the standard embedded platform plugin
./appMonitor
```

**`QT_QPA_PLATFORM=eglfs`** is the specific detail that matters most here — `eglfs` is Qt's platform plugin for rendering directly to the framebuffer/DRM without a windowing system (X11/Wayland), which is the standard, correct choice for a kiosk-style Pi deployment where your app _is_ the entire display, full-screen, with no desktop underneath it. Trying to run with the default (`xcb`, assuming X11) on a headless/kiosk Pi setup will simply fail to start — this single environment variable is the most common "works on desktop, won't even launch on the Pi" issue.

## Concept: Running as a systemd service — you already know this pattern

Given your existing systemd deployment experience from the Docker/Python courses, this transfers directly:

```ini
# /etc/systemd/system/mqtt-monitor-gui.service
[Unit]
Description=mqtt_monitor Qt Quick Dashboard
After=network.target

[Service]
Environment=QT_QPA_PLATFORM=eglfs
Environment=LD_LIBRARY_PATH=/opt/mqtt_monitor/lib
ExecStart=/opt/mqtt_monitor/appMonitor
Restart=always
RestartSec=5
User=pi

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable mqtt-monitor-gui.service
sudo systemctl start mqtt-monitor-gui.service
```

`Restart=always` + `RestartSec=5` — the same resilience pattern you'd apply to any long-running embedded service, ensuring a crashed GUI (network hiccup, unexpected exception) comes back automatically rather than leaving a Pi with a blank screen until manual intervention — genuinely important for something meant to run unattended.

## Concept: A leaner alternative worth knowing — static linking

For embedded targets specifically, a **statically linked** Qt build (rather than bundling `.so` files alongside the binary) produces a single larger binary with no separate library deployment step — trading binary size for deployment simplicity. This requires building Qt itself with `-static` at Qt-build time (not something you configure after the fact) — worth knowing this option exists if the `rsync`-the-lib-directory approach above starts feeling fragile across Pi OS updates, but not something to set up today; the dynamic approach above is the standard starting point.

## Exercise

1. Build a desktop AppImage of your Day 8/16/20 assembled dashboard using `linuxdeployqt`, copy it to a clean VM or container without Qt installed, and confirm it launches standalone.
2. If you have Pi hardware available (per Day 22's note): set up cross-compilation (or use Qt's official Raspberry Pi build guide if a toolchain isn't already prepared from your embedded course work), cross-compile your app, and deploy it via the `rsync` + `LD_LIBRARY_PATH` + `eglfs` approach above.
3. Confirm the specific failure mode described above yourself: try running your cross-compiled ARM binary without setting `QT_QPA_PLATFORM=eglfs` (if you have a desktop environment on the Pi to compare against) or try running an x86_64 binary on the Pi by mistake, and observe the "wrong ELF class" error — recognize it on sight going forward.
4. Set up the systemd service file, confirm the app starts on boot without manual intervention, and test the `Restart=always` resilience by deliberately killing the process (`kill -9`) and confirming it restarts within `RestartSec`.

## Key takeaways

- Desktop deployment (`linuxdeployqt` + `-qmldir`) bundles Qt libraries and QML modules into a single distributable AppImage — don't forget `-qmldir`, or QML modules silently won't be found on a target machine without a full Qt install.
- Raspberry Pi deployment is a genuine cross-compilation problem (different CPU architecture), not just "copy the binary over" — the toolchain file is the crux of getting this right.
- "Wrong ELF class" errors on the Pi are the unambiguous signature of an architecture mismatch (x86_64 binary on ARM, or vice versa) — recognize this immediately rather than debugging it as an application bug.
- `QT_QPA_PLATFORM=eglfs` is the standard platform plugin for kiosk-style, windowless embedded deployment — the most common reason a desktop-tested build won't even launch on a headless Pi.
- systemd service deployment (`Restart=always`) transfers directly from your existing embedded Linux service experience — same resilience pattern, same tooling.
- Static linking is a viable alternative to library bundling for embedded targets, trading binary size for deployment simplicity — worth knowing exists, not needed to adopt immediately.
