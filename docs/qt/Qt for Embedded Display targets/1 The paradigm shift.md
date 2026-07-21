[[Qt for Embedded Display targets]]
## Day 1: The Paradigm Shift — No Desktop, No Window Manager, You _Are_ the Screen

### Concept: Every Assumption From the Last 40 Days Just Changed

Both prior curricula quietly assumed something was already running underneath your app: on the Widgets side, a Linux desktop with X11/Wayland and a window manager; on the QML side, the same, just with GPU-accelerated rendering. **Neither assumption holds here.** When Qt targets an embedded display directly, your application is not a window in someone else's desktop — it is the only thing that ever touches the screen, from the moment the bootloader hands off to the moment someone cuts power. This changes real, concrete things:

- **There is no window manager to composite, resize, or decorate your app.** Your `ApplicationWindow` doesn't get a title bar, doesn't get "minimized," doesn't share the screen with anything — because there's nothing else to share it with.
- **There is no X11 or Wayland display server at all.** Not "a minimal one" — genuinely none. Qt talks to the GPU/framebuffer directly through a different plugin architecture entirely.
- **Boot time is now part of your product's UX.** On a desktop, "the OS boots, then eventually you launch the app" is invisible to the end user. On a wall-mounted panel, the gap between power-on and your dashboard appearing _is_ the product's first impression — and it's now your problem to manage (Day 5).

### The Three Real Backend Choices — Qt Platform Abstraction (QPA) Plugins

Qt's rendering always goes through a **QPA plugin** — on desktop Linux this was `xcb` (X11) or `wayland`, invisibly, the whole time. For direct-to-display embedded targets, you choose from:

1. **`eglfs`** — the modern, correct default for GPU-accelerated embedded Linux. Talks directly to EGL/OpenGL ES on the framebuffer, no compositor. This is what your Raspberry Pi should be using.
2. **`linuxfb`** — raw framebuffer, CPU-rendered, no GPU acceleration at all. Genuinely last-resort — correct only for hardware with literally no usable GPU driver, or extremely simple, static UIs. **Do not reach for this by default** — it will make everything from Day 12's animations (Widgets) and Day 6's `Behavior` (QML) genuinely sluggish or unusable.
3. **`eglfs` with the `kms` (DRM/KMS) backend specifically** — the sub-variant that matters most on modern Raspberry Pi OS, since the older "legacy" GPU driver stack is being phased out in favor of DRM/KMS (Direct Rendering Manager/Kernel Mode Setting), the same kernel-level display stack modern desktop Linux uses under the hood. **This is the one you actually want on current Pi hardware.**

```bash
# Setting the QPA platform plugin — an environment variable, not a
# recompile. This is how you tell Qt "there is no X11/Wayland, talk to
# the display directly."
export QT_QPA_PLATFORM=eglfs
./mqtt_monitor_qml
```

**The practical diagnostic worth running immediately on real hardware**, before writing any embedded-specific code:

```bash
# Confirm which DRM/KMS device node your Pi actually exposes
ls /dev/dri/
# Typically card0 or card1 — eglfs needs to know which one if there's
# ambiguity (common on Pi boards with both an HDMI and a composite path)
```

### Configuring `eglfs` — The Part With Real, Board-Specific Gotchas

`eglfs` reads a JSON config file specifying which DRM device/connector to use — this is **not optional boilerplate**, it's the actual mechanism by which Qt finds your specific screen among possibly several outputs:

```json
{
    "device": "/dev/dri/card1",
    "outputs": [
        {
            "name": "HDMI1",
            "mode": "1024x600"
        }
    ]
}
```

```bash
export QT_QPA_EGLFS_KMS_CONFIG=/etc/mqtt_monitor/eglfs_kms.json
export QT_QPA_PLATFORM=eglfs
```

**A genuinely common real-world failure mode worth naming up front**: on some Pi images, the DRM device node is `card0`, on others (depending on whether the legacy `vc4-fkms-v3d` or the modern `vc4-kms-v3d` overlay is active in `/boot/config.txt`) it's `card1`, and getting this wrong doesn't crash cleanly — it typically produces a black screen with no obvious error, or the app silently rendering to a device nothing is displaying. This is the single most time-costly early mistake on this path, and the fix is always the same: run `ls /dev/dri/` fresh on the actual booted board, don't assume from documentation or a previous board's config.

### Why There's No Window Manager, and What Actually Replaces It

On desktop, the window manager handled: window placement, focus switching between apps, alt-tab, resize handles. On an embedded direct-display target, **your single QML/Widgets application _is_ the entire compositor's output** — there's exactly one "window," it's always fullscreen, and it never loses focus because nothing else exists to take focus. This is why:

- `ApplicationWindow`'s `visibility` in QML, or `showFullScreen()` in Widgets, becomes the _only_ meaningful window state — there's no "restore," no "minimize," those concepts don't apply.
- Any UI you built assuming the user could alt-tab away, resize the window, or run your app alongside something else (even Day 27's Widgets-admin-tool-alongside-QML-panel idea from the QML curriculum's Day 5) needs rethinking specifically for this target — **if this Pi is running your `eglfs` app, it is running nothing else on that screen, ever**, by construction, not by configuration choice you could easily change later.

### Annotated Code: Forcing Fullscreen, No-Decoration Behavior Explicitly

Even though there's no window manager to _add_ decorations, it's worth being explicit in code rather than relying on the platform plugin's defaults — this makes your intent clear and keeps the same QML file portable back to a desktop dev environment for testing:

```qml
import QtQuick
import QtQuick.Controls

ApplicationWindow {
    id: window
    visible: true

    // On eglfs, this is implicit (there's only ever one fullscreen
    // surface) — but declaring it explicitly means the SAME main.qml
    // still behaves sanely if you run it on your dev workstation
    // (Day 1-10 of the QML curriculum) for iteration before flashing
    // to hardware, rather than popping up as a small window there
    // and fullscreen only on-device with no code showing why.
    flags: Qt.Window | (Qt.platform.os === "linux" && width === Screen.width ? Qt.FramelessWintHint : 0)

    // Simpler and more directly honest about intent:
    Component.onCompleted: {
        if (Qt.platform.pluginName === "eglfs") {
            showFullScreen()
        }
    }
}
```

**The genuinely useful pattern here**: `Qt.platform.pluginName` lets your QML _know_ whether it's running under `eglfs` (real hardware) or `xcb`/`wayland`/`windows` (your dev workstation) — this is worth using deliberately so the same QML source works for both fast iterative development on your desktop and final deployment on the Pi, without maintaining two separate versions of `main.qml`.

### Why This Matters

- **QPA plugin selection is an environment variable, not a recompile** — your actual `mqtt_monitor_qml` binary from the previous curriculum doesn't need rebuilding to target `eglfs`; the same binary, told `QT_QPA_PLATFORM=eglfs` at launch, renders directly to the display instead of into an X11/Wayland window. This is a genuine relief: the QML curriculum's work wasn't wasted or desktop-specific, it just needs a different launch environment on-device.
- **The DRM device/connector mismatch is the single most common "black screen, no error" bug** on this path — always verify `/dev/dri/` freshly on the actual booted hardware rather than trusting a config file copied from documentation or another board.
- **There is structurally only one window, always fullscreen, never losing focus** — this isn't a configuration you dial in, it's what "direct-to-display" means by definition, and any UI assumptions built around a desktop's multi-window/focus-switching model need re-examining for this deployment target specifically.
- **`linuxfb` is a fallback, not a default** — reaching for it because "it sounds simpler than dealing with EGL/KMS config" trades away GPU acceleration entirely, which will make Day 6/QML's `Behavior`-based animations and Day 12/Widgets' `QPropertyAnimation` work genuinely feel bad, not just theoretically slower.

### Exercise

1. On a real Raspberry Pi (or any embedded Linux board with a display attached, if you have one available): run `ls /dev/dri/`, note the device node, then run your existing `mqtt_monitor_qml` binary from the previous curriculum with `QT_QPA_PLATFORM=eglfs` and confirm it renders directly to the screen with no desktop visible at any point before or after.
2. Deliberately misconfigure the `eglfs_kms.json` device path (point it at a nonexistent `/dev/dri/card9`) and observe exactly what happens — confirm for yourself whether it's a black screen, a clear error, or something in between, so you recognize this specific failure mode immediately if you hit it for real later without a curriculum telling you what's wrong.
3. Add the `Qt.platform.pluginName` check from today's example to your actual `main.qml`, and confirm the same file behaves correctly both on your dev workstation (windowed, for fast iteration) and — if you have hardware — fullscreen under `eglfs`, without maintaining two separate QML files.

### Key Takeaways

- Direct-to-display embedded Qt has no X11/Wayland/window manager — your app is the only thing that ever touches the screen, by construction, not by choice you dial in later.
- `eglfs` (ideally its DRM/KMS variant on modern Pi hardware) is the correct default for GPU-accelerated direct rendering; `linuxfb` is a CPU-only fallback for hardware with no usable GPU driver, not a simpler default choice.
- QPA plugin selection is an environment variable (`QT_QPA_PLATFORM`) — your existing compiled binaries from the Widgets/QML curricula don't need rebuilding to target this, just a different launch environment.
- DRM device/connector misconfiguration is the most common early failure, and it fails silently (black screen) rather than with a clear error — always verify `/dev/dri/` on the actual booted hardware.
- `Qt.platform.pluginName` lets one QML source file behave correctly both on a dev workstation (windowed) and on real embedded hardware (fullscreen), avoiding maintaining parallel versions.
