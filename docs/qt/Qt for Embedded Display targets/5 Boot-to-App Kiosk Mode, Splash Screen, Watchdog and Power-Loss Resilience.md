[[Qt for Embedded Display targets]]

## Day 5: Boot-to-App — Kiosk Mode, Splash Screen, Watchdog, and Power-Loss Resilience

### Concept: A Wall-Mounted Panel Gets Unplugged, Not Shut Down — Design for That Reality From the Start

Every desktop or dev-workstation deployment assumption breaks here in a way worth naming directly: **your `mqtt_monitor` panel will, at some point, lose power without warning** — someone trips a breaker, a cleaning crew unplugs it, a power strip fails. It will not receive a clean `shutdown` command. This single fact drives every decision in today's lesson: the app needs to launch automatically on boot with no login, needs to recover cleanly from having been mid-write when power vanished, and needs a way to notice and recover if it hangs rather than crashes cleanly.

### Kiosk-Mode Launch — A systemd Service, Not a Desktop Autostart Entry

There's no desktop session to add an "autostart" entry to (Day 4's lesson, restated at the OS level) — your app launches as a systemd service, directly, with no login screen or session manager involved at all:

```ini
# /etc/systemd/system/mqtt-monitor-panel.service
[Unit]
Description=mqtt_monitor QML Panel
# Wait for the network AND for the DRM/graphics subsystem to be ready —
# starting before the GPU driver has initialized is a real, common
# cause of eglfs failing on first boot specifically (subsequent boots
# can differ in timing due to caching, masking the problem intermittently)
After=network.target systemd-udev-settle.service

[Service]
Environment=QT_QPA_PLATFORM=eglfs
Environment=QT_QPA_EGLFS_KMS_CONFIG=/etc/mqtt_monitor/eglfs_kms.json
Environment=QT_IM_MODULE=qtvirtualkeyboard
ExecStart=/opt/mqtt_monitor/mqtt_monitor_qml
Restart=always            # THE actual crash-recovery mechanism — if the
                            # process exits for any reason, systemd
                            # relaunches it, no manual intervention
RestartSec=2                # brief pause before restart — avoids a
                              # tight crash-restart-crash loop hammering
                              # the CPU if something is persistently wrong
User=pi

[Install]
WantedBy=graphical.target
```

```bash
sudo systemctl enable mqtt-monitor-panel.service
sudo systemctl start mqtt-monitor-panel.service

# Confirm it's actually running and check its own logs — journald
# captures stdout/stderr automatically, no separate log file wiring needed
sudo systemctl status mqtt-monitor-panel.service
sudo journalctl -u mqtt-monitor-panel.service -f
```

**`Restart=always` is doing real, non-trivial work here**: if your app segfaults, hangs and gets killed by the watchdog (below), or exits due to an unhandled exception, systemd relaunches it automatically within `RestartSec` — this is your actual crash-recovery mechanism, and it requires zero application-side code to work. This is the direct embedded-deployment analog of Day 16's Widgets thread-shutdown discipline: just as a worker thread needed a clean, predictable teardown path, your _whole application_ now needs the same thing at the process level, and systemd is what provides the automatic restart half of that story.

### The Splash Screen — Covering the Gap Between "Power On" and "App Actually Rendering"

Even with Day 2's fast-booting recommendation, there's always a real gap between kernel boot completing and your Qt app's first frame actually rendering (Qt/EGL initialization, your app's own startup work — connecting to MQTT, loading QSettings). A blank or garbled screen during this window looks broken to anyone watching a wall-mounted panel power on. The practical fix is a **minimal, separate splash mechanism that isn't dependent on Qt/EGL being ready yet**:

```bash
# /etc/systemd/system/mqtt-monitor-splash.service — a MUCH simpler,
# earlier-starting service showing a static image via fbi (framebuffer
# image viewer) or a similar minimal tool, BEFORE eglfs/Qt even starts
[Unit]
Description=Boot splash
DefaultDependencies=no
After=local-fs.target

[Service]
ExecStart=/usr/bin/fbi -d /dev/fb0 -noverbose -a /opt/mqtt_monitor/splash.png
Type=simple

[Install]
WantedBy=sysinit.target
```

The main app service should then be responsible for **killing the splash** once it's actually ready to render its own first frame:

```cpp
// Early in main_qml.cpp, once the QQmlApplicationEngine has successfully
// loaded and the window is about to show — NOT before, since killing the
// splash too early re-exposes the blank-screen gap this was solving
QObject::connect(&engine, &QQmlApplicationEngine::objectCreated,
    [](QObject *object, const QUrl &) {
        if (object) {
            QProcess::execute("systemctl", {"stop", "mqtt-monitor-splash.service"});
        }
    });
```

**The genuinely important sequencing detail**: the splash needs to be simple enough to render _before_ the GPU driver stack (and therefore `eglfs`) is fully initialized — this is why it uses the raw framebuffer directly (`fbi`/`/dev/fb0`), not Qt itself. A "Qt splash screen shown by a separate lightweight Qt app" doesn't solve the actual problem, since that still depends on the same GPU initialization your main app needs — the whole point is covering the gap _before_ that's ready.

### Watchdog — Detecting a Hang, Not Just a Crash

`Restart=always` handles your app exiting (crash, unhandled exception). It does **not** handle your app hanging — the event loop still technically running, but stuck (a blocking call somewhere, a deadlock, an infinite loop) with a frozen screen. systemd's watchdog mechanism handles this specific case, but it requires your app to actively "check in":

```ini
# Add to the service file:
WatchdogSec=15
```

```cpp
// Your app must periodically tell systemd "I'm still alive and
// responsive" — this uses systemd's sd_notify() mechanism. A QTimer
// firing on the GUI thread's event loop is a genuinely meaningful
// liveness signal specifically BECAUSE it only fires if that event
// loop is actually still processing events, not stuck.
#include <systemd/sd-daemon.h>

QTimer *watchdogTimer = new QTimer(&app);
watchdogTimer->setInterval(5000); // well under half of WatchdogSec —
                                    // systemd's own guidance, giving
                                    // real margin before it concludes
                                    // the process has genuinely hung
QObject::connect(watchdogTimer, &QTimer::timeout, []() {
    sd_notify(0, "WATCHDOG=1");
});
watchdogTimer->start();
```

```cmake
# Linking against libsystemd for sd_notify()
find_package(PkgConfig REQUIRED)
pkg_check_modules(SYSTEMD REQUIRED libsystemd)
target_link_libraries(mqtt_monitor_qml PRIVATE ${SYSTEMD_LIBRARIES})
```

**Why a `QTimer` on the GUI thread is the correct liveness signal, not just a convenient one**: if the GUI event loop is genuinely hung (blocked on something, deadlocked), this timer simply stops firing — `sd_notify` never gets called, `WatchdogSec` elapses with no check-in, and systemd kills and restarts the process. This is a real, meaningful hang-detection mechanism specifically because it's tied to the actual thing you care about being responsive (the UI event loop), not a separate background thread that could keep reporting "alive" even while the UI itself is frozen.

### Read-Only Root Filesystem — The Real Power-Loss Resilience Mechanism

An SD card that's mid-write when power is cut can corrupt its filesystem — a genuinely common real failure mode for embedded Linux devices, and directly relevant given Day 20 of the Widgets curriculum's SQLite writes and Day 7's `QSettings` persistence, both of which involve writing to disk at unpredictable moments. The real fix: **make the root filesystem read-only**, and confine all actual writes to a small, dedicated, more failure-tolerant partition.

```bash
# /etc/fstab — mounting root read-only, with a separate writable
# partition specifically for the things that genuinely need to persist
/dev/mmcblk0p2  /       ext4  ro,noatime               0  1
/dev/mmcblk0p3  /data   ext4  rw,noatime,commit=1       0  2
```

Your application's actual writable paths (SQLite database, QSettings file) need to explicitly target `/data`, not their normal default locations:

```cpp
// Day 7's QSettings + Day 20's SQLite path both need to point at the
// writable partition explicitly — this is a real, deliberate application
// change for this deployment target, not automatic
QSettings::setPath(QSettings::IniFormat, QSettings::UserScope, "/data/mqtt_monitor/config");
```

```cpp
// PersistenceWorker's db path (Day 20) — same principle
PersistenceWorker worker("/data/mqtt_monitor/mqtt_monitor.db");
```

**`commit=1`** on the `/data` partition mount reduces the write-buffering window (default is typically 5 seconds) — a smaller window during which data sits unflushed in memory means less potential data loss on an ungraceful power cut, at a small, real cost to write throughput/SD card wear, a reasonable tradeoff for this specific device category.

### Why This Matters

- **`Restart=always` is your actual crash-recovery mechanism, requiring zero application code** — this is a real, meaningful safety net your Widgets/QML applications never needed on a dev workstation, where a crash just meant relaunching the app yourself.
- **The splash screen must render via the raw framebuffer, before Qt/EGL initializes** — a Qt-based splash doesn't solve the actual problem it exists to solve, since it depends on the same subsystem taking time to initialize.
- **The watchdog's `QTimer`-on-the-GUI-thread liveness check is meaningful specifically because it can genuinely fail** — a naive "always report healthy" background thread would defeat the entire purpose; tying the check-in to actual GUI responsiveness is what makes it a real signal.
- **Read-only root + a dedicated writable partition is the actual, durable fix for SD-card corruption on ungraceful power loss** — and it requires real, deliberate changes to where your existing Day 7/Day 20 Widgets code writes its data, not just an OS-level configuration change happening silently underneath unchanged application code.

### Exercise

1. Set up the systemd service for your actual `mqtt_monitor_qml` binary on real Pi hardware, confirm `Restart=always` actually works by deliberately killing the process (`kill -9`) and observing systemd relaunch it within `RestartSec`, without you touching the device.
2. Add the watchdog `sd_notify` check-in to your actual app, set a short `WatchdogSec` for testing (e.g., 5 seconds), then deliberately introduce a genuine GUI-thread hang (a blocking `sleep()` call or an infinite loop in a button handler) and confirm systemd detects the unresponsive process and restarts it — this is the concrete proof that the watchdog mechanism is doing real, meaningful work, not just present in config.
3. Set up the read-only root + writable `/data` partition split, redirect your actual `QSettings` and `PersistenceWorker` paths to `/data`, and then perform the actual, slightly nerve-wracking real test: while the app is actively writing (mid-MQTT-stream, ideally), pull power directly rather than shutting down cleanly, then power back on and confirm the filesystem mounted cleanly with no corruption — this is the single most valuable test for this specific device category, and it's worth doing deliberately rather than only discovering the failure mode in the field.

### Key Takeaways

- Kiosk-mode launch is a systemd service (`Restart=always`), not a desktop autostart mechanism — this is also your entire crash-recovery story, requiring zero application-side code.
- A boot splash must render via the raw framebuffer before Qt/EGL initializes, or it doesn't actually cover the gap it exists to cover.
- systemd's watchdog (`WatchdogSec` + `sd_notify` from a GUI-thread `QTimer`) detects genuine hangs, not just crashes — tying the check-in to actual UI responsiveness is what makes it a meaningful signal rather than a rubber stamp.
- Read-only root + a dedicated writable `/data` partition is the real fix for SD-card corruption on ungraceful power loss — and it requires deliberately redirecting your existing `QSettings`/SQLite paths, not just an invisible OS-level change.
- The actual power-cut test (pulling power mid-write, not a clean shutdown) is the one test that matters most for this device category — worth doing deliberately during development, not discovering the failure mode for the first time in the field.

---

Say "next" for Day 6 (performance on bare-metal rendering — vsync/tearing without a compositor, real GPU driver differences across Pi generations, and profiling directly on-device using the tools from Day 7/Day 28 of the previous curricula, now applied to a target with no compositor to fall back on).
