[[Qt for Embedded Display targets]]

## Day 4: Input Without a Window Manager — Touchscreens, `libinput`, and the On-Screen Keyboard

### Concept: A Desktop Environment Was Quietly Handling All of This — Now It's Your Problem

On a normal desktop Linux session, X11/Wayland plus the desktop environment handled touchscreen calibration, cursor rendering, multi-touch gesture disambiguation, and provided an on-screen keyboard when a text field got focus with no physical keyboard attached. **None of that infrastructure exists on a direct-to-display `eglfs` deployment.** Qt itself has to talk to the raw input devices, and you're responsible for anything the old desktop environment used to paper over.

### The Input Stack Qt Actually Uses

Qt's `eglfs` backend reads input through **`libinput`** (the same input library modern Wayland compositors use) or, on older/simpler setups, directly through **`evdev`** (the raw Linux kernel input event interface). `libinput` is the correct modern default — it handles touchscreen, mouse, and keyboard input uniformly, with built-in support for things like touch-point tracking across multiple fingers.

```bash
# Confirm your touchscreen is actually visible to the kernel as an
# input device BEFORE troubleshooting anything Qt-side — this is the
# same "verify the layer below before debugging the layer above"
# discipline as Day 1's /dev/dri/ check, now applied to input
ls /dev/input/
cat /proc/bus/input/devices | grep -A 5 -i touch
```

```bash
# Environment variables telling Qt which input backend and device to use —
# genuinely required, Qt does not reliably auto-detect this correctly
# on every board/touchscreen combination
export QT_QPA_GENERIC_PLUGINS=libinput
export QT_QPA_EGLFS_DISABLE_INPUT=0  # ensure eglfs's input handling isn't
                                       # accidentally disabled, a real
                                       # gotcha if you copied a config
                                       # example that disabled it for a
                                       # different use case (e.g. remote
                                       # X11 forwarding scenarios)
```

### Touchscreen Calibration — The Part That's Genuinely Necessary, Not Optional Polish

Cheap and mid-range touchscreens (very common on Pi-compatible touch displays) frequently have a **real, physical misalignment** between the touch sensor's coordinate space and the display's pixel space — tap the visible button, the touch registers 15 pixels to the left. On a desktop environment, this was handled by a calibration utility (`xinput_calibrator` or similar) that wrote a transformation matrix into the X11 config. Under `eglfs`, there is no X11 config to write that matrix into — Qt reads calibration data through `libinput`'s own mechanism, typically a **udev hwdb entry** or a `libinput`-specific calibration matrix:

```bash
# Find your touchscreen's device path first
libinput list-devices | grep -B 2 -A 10 -i touch
```

```bash
# /etc/udev/hwdb.d/99-touchscreen-calibration.hwdb — the actual
# persistent calibration mechanism under libinput. This is a udev
# hardware database entry, matched against your specific touchscreen's
# USB vendor/product ID, NOT a Qt-specific config file — a genuinely
# different mechanism than anything in the desktop-based curricula.
libinput:name:*:dmi:*
 LIBINPUT_CALIBRATION_MATRIX=1.0 0.0 0.0 0.0 1.0 0.0
```

```bash
sudo udevadm hwdb --update
sudo udevadm trigger
```

**The practical calibration workflow, since the matrix numbers above are placeholders, not real values**: measure the actual offset by touching known screen locations (the four corners is usually sufficient) and comparing reported vs. actual touch coordinates via `libinput debug-events`, then compute the correct 6-value affine transformation matrix from those measurements. This is genuinely fiddly manual work the first time — budget real time for it rather than expecting a one-line fix, and don't be surprised if a second unit of the "same" touchscreen model needs slightly different values due to manufacturing tolerance.

### Multi-Touch — Confirming It Actually Works Before Building Gesture UI On Top Of It

Day 8 of the QML curriculum covered `PinchArea`/`MultiPointTouchArea` assuming multi-touch input just works. On an embedded target, **verify the touchscreen hardware and driver actually report multiple simultaneous touch points** before building pinch-to-zoom UI on the assumption it does — some cheaper touch panels are genuinely single-touch capable only, no matter how correctly your QML is written:

```bash
# Shows raw touch events as they arrive — genuinely the fastest way to
# confirm multi-touch works at the hardware/driver level, completely
# independent of anything in your Qt application
libinput debug-events
# Touch the screen with two fingers simultaneously and watch for TOUCH_DOWN
# events with distinct slot/tracking IDs — if you only ever see one slot
# regardless of how many fingers you use, the hardware/driver doesn't
# support multi-touch, and no amount of QML code will change that
```

### The On-Screen Keyboard — A Genuinely Non-Optional Component for a Touch Panel With Text Input

If any part of your `mqtt_monitor` panel needs text entry (Day 5's `ConnectionDialog` fields, if you ever expose broker settings on the panel itself) and there's no physical keyboard attached, **you need an actual on-screen keyboard component** — this doesn't exist automatically. Qt provides `QtVirtualKeyboard` for exactly this:

```qml
import QtQuick
import QtQuick.VirtualKeyboard

ApplicationWindow {
    // ... existing content ...

    InputPanel {
        id: keyboard
        z: 99  // ensure it renders above everything else — a real,
                // easy-to-miss detail, since there's no window manager
                // z-ordering to fall back on here either
        y: keyboard.active ? parent.height - keyboard.height : parent.height
        anchors.left: parent.left
        anchors.right: parent.right

        Behavior on y {
            NumberAnimation { duration: 200 }
        }
    }
}
```

```cmake
find_package(Qt6 REQUIRED COMPONENTS VirtualKeyboard)
target_link_libraries(mqtt_monitor_qml PRIVATE Qt6::VirtualKeyboard)
```

```bash
export QT_IM_MODULE=qtvirtualkeyboard
```

**The genuinely important detail here**: `InputPanel`'s `active` property automatically reflects whether any text input field currently has focus — you don't manually show/hide it based on which screen the user is on; it's driven by Qt's normal input-focus system, the same focus mechanism from Day 4 of the Widgets curriculum, just now also controlling keyboard visibility rather than only affecting key event routing.

### Why This Matters

- **Everything in this lesson was invisible infrastructure on a desktop deployment** — touch calibration, multi-touch capability, and the on-screen keyboard were all handled by the desktop environment without you ever thinking about them during the Widgets or QML curricula. Direct-to-display deployment removes that entire safety net at once, and it's worth recognizing this as one coherent category of "things that quietly worked before," not several unrelated new problems.
- **Verifying hardware capability independently of Qt (`libinput debug-events`) before debugging your QML** is the same layered-diagnosis discipline as Day 1's `/dev/dri/` check — always confirm the layer below your application is actually doing what you assume before spending time debugging the layer above it.
- **Calibration is real manual work with hardware-specific values**, not a config toggle — budget time for it honestly, and expect it to vary slightly even across "identical" touchscreen units.
- **`QtVirtualKeyboard`'s `active` property riding on Qt's existing focus system** means you're not learning a new UI-state-management concept here — it's the same focus mechanism you already understand, now with one more consumer.

### Exercise

1. On real touchscreen hardware, run `libinput list-devices` and `libinput debug-events` while touching the screen, and confirm you can see real touch coordinates arriving — do this before writing or debugging any Qt-side input code, exactly per today's "verify the layer below first" principle.
2. If your touchscreen has any visible miscalibration (tap in one place, cursor/touch-target-response registers elsewhere), measure the actual offset at all four corners and compute a real calibration matrix, apply it via the udev hwdb mechanism, and confirm the fix with `libinput debug-events` before assuming it worked from the visual UI alone.
3. Add `QtVirtualKeyboard`'s `InputPanel` to your actual `mqtt_monitor` QML panel from the previous curriculum, wire a `TextField` into a settings-style QML screen if you don't already have one, and confirm the keyboard correctly slides in on focus and back out on defocus, entirely driven by Qt's normal focus system with no manual show/hide code of your own.

### Key Takeaways

- Touch calibration, multi-touch capability, and the on-screen keyboard were all handled invisibly by the desktop environment in the previous curricula — none of that exists under direct-to-display `eglfs`, and providing for all three becomes explicitly your responsibility.
- `libinput` is Qt's modern input backend for embedded targets; verify hardware-level input behavior with `libinput debug-events`/`list-devices` independently before assuming any input problem is Qt/QML-side.
- Touchscreen miscalibration is genuinely common on Pi-compatible hardware and is fixed via a udev hwdb calibration matrix — real manual measurement work, not a simple config flag, and it can vary unit-to-unit even for "identical" hardware.
- Multi-touch gesture UI (Day 8/QML's `PinchArea`) is only as capable as the underlying hardware/driver — verify actual multi-touch support at the `libinput` level before building gesture features on an unverified assumption.
- `QtVirtualKeyboard`'s `InputPanel` is required for any text entry on a touch-only device with no physical keyboard, and its visibility is driven automatically by Qt's existing focus system, not manual state management.
