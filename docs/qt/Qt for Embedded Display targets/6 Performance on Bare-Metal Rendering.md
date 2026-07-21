[[Qt for Embedded Display targets]]

## Day 6: Performance on Bare-Metal Rendering — Vsync, Tearing, and Real GPU Driver Differences

### Concept: There's No Compositor to Hide Rendering Mistakes Anymore

On desktop Linux, a compositor (part of the window manager stack) sits between your app's rendering and the actual display output — it handles buffer swapping, vsync synchronization, and generally papers over a lot of timing sloppiness. Under `eglfs`, **your application talks directly to the display hardware** — there's no compositor absorbing timing issues. This means tearing, dropped frames, and vsync misconfiguration are now directly, visibly your problem, in a way Day 7 of the QML curriculum's `QSG_RENDER_TIMING` touched on but didn't fully explain the "why" behind.

### Vsync and Tearing — What's Actually Happening Without a Compositor

**Tearing** happens when the display shows part of one rendered frame and part of the next, because the frame buffer was updated mid-scan-out. A compositor normally prevents this by controlling exactly when buffer swaps happen relative to the display's refresh cycle. Under `eglfs`, Qt's own EGL buffer-swap timing takes over this responsibility directly:

```bash
# Confirm vsync is actually enabled — this is NOT automatic on every
# board/driver combination, and a missing vsync is one of the more
# common causes of visible tearing on embedded targets specifically
export QT_QPA_EGLFS_FORCEVSYNC=1
```

```bash
# The other real lever: some GPU drivers expose a swap-interval
# environment variable directly at the EGL level, beneath Qt's own
# handling — worth checking if QT_QPA_EGLFS_FORCEVSYNC alone doesn't
# resolve visible tearing on your specific board
export vblank_mode=1   # Mesa-driver-specific, relevant on VC4/V3D-driven
                         # (modern Pi GPU driver stack) boards specifically
```

**The genuinely important diagnostic habit**: tearing is a _visual_ symptom you should actively look for on real hardware, not something `QSG_RENDER_TIMING`'s numeric output alone will tell you about — pull up a fast-moving animation (Day 6/QML's `Behavior`-animated gauge value, or just drag a `Slider` quickly) and watch for a visible horizontal "seam" where the top and bottom of the screen appear briefly misaligned. If you see it, vsync configuration is the first thing to check, not application-level optimization.

### GPU Driver Differences Across Pi Generations — A Real, Concrete Gotcha

This is worth stating plainly rather than glossing over: **"Raspberry Pi" is not one GPU driver story**. The Pi 3 and earlier, and early Pi 4 firmware, commonly used the legacy, closed-source `vc4-fkms-v3d` "firmware KMS" driver path. Current Raspberry Pi OS on Pi 4/400/5 defaults to the full, open-source `vc4-kms-v3d`/`v3d` DRM driver — genuinely different code, different performance characteristics, different bug surface. **Code and configuration that works correctly on one can behave differently, or fail outright, on the other.**

```bash
# /boot/config.txt — confirming which driver stack is actually active,
# since this affects EVERYTHING from Day 1's device node onward, not
# just performance
cat /boot/config.txt | grep -i "dtoverlay=vc4"
# vc4-kms-v3d = modern, full KMS driver (what you want on Pi 4/400/5)
# vc4-fkms-v3d = legacy firmware-KMS path (older boards, or explicitly
#                 reverted configurations)
```

**The practical consequence, stated concretely**: if you develop and tune performance against one Pi generation's driver stack and then deploy to a different generation without re-verifying, you should genuinely expect to re-test rendering behavior, not just assume identical performance — this is the embedded-hardware equivalent of Day 28/Widgets' "profile on the actual target, not just your dev machine" lesson, now with an added wrinkle that "the actual target" can itself vary meaningfully across board revisions within the same product line.

### Profiling On-Device — Applying Day 28/Widgets and Day 7/QML's Tools Here, With No Compositor Fallback

The tools are the same ones you already know — `perf`, `QSG_RENDER_TIMING` — but the interpretation changes slightly because there's no compositor absorbing minor timing slips:

```bash
# Same perf workflow as Day 28/Widgets, run directly on the Pi
perf record -g -p $(pgrep mqtt_monitor_qml) -- sleep 30
perf report
```

```bash
# QSG_RENDER_TIMING (Day 7/QML), now interpreted with the understanding
# that there's genuinely nothing downstream smoothing over a slow frame —
# a slow render here is a slow, visible frame on the actual display,
# not something a compositor might have partially hidden
QSG_RENDER_TIMING=1 ./mqtt_monitor_qml
```

**The interpretation difference worth internalizing**: on a desktop deployment, an occasional slow frame might be absorbed or smoothed by compositor buffering — under direct-to-display rendering, every frame's timing is directly, immediately visible as-is. This makes `eglfs` deployment simultaneously a _stricter_ test of your QML's actual rendering performance (Day 7/QML's delegate-recycling and `Shape`-vs-`Canvas` discipline matters _more_ here, not less) and, done correctly, a _more responsive-feeling_ result, since you're not paying a compositor's overhead at all.

### A Genuinely Useful Board-Specific Check: `vcgencmd` on Raspberry Pi

```bash
# Pi-specific GPU/thermal diagnostics — not a Qt tool at all, but the
# actual first place to look when performance seems "randomly" bad
vcgencmd measure_temp        # thermal throttling is a REAL, common cause
                               # of degraded performance on a wall-mounted
                               # Pi in an enclosure with poor airflow —
                               # this is worth checking before assuming
                               # anything is wrong with your Qt code at all
vcgencmd get_throttled        # a non-zero result here means the Pi has
                               # throttled itself due to thermal or power
                               # issues — check this FIRST, always, before
                               # any QSG_RENDER_TIMING/perf investigation
vcgencmd measure_clock v3d    # confirms actual current GPU clock speed
```

**This deserves real emphasis, from direct field experience**: a wall-mounted Pi in an enclosure (very likely your actual `mqtt_monitor` deployment shape) with inadequate ventilation will thermal-throttle under sustained load, and the resulting performance degradation looks _exactly_ like a QML rendering problem from the application's perspective — stuttering animations, dropped frames — while the actual root cause is a completely different layer entirely. **`vcgencmd get_throttled` should be the very first check**, before any of Day 28/Widgets' or Day 7/QML's profiling tools, whenever performance seems to degrade specifically after the device has been running for a while (a strong thermal-throttling signature) rather than being consistently present from cold boot.

### Why This Matters

- **Vsync/tearing is now entirely your application's direct responsibility** — a compositor was silently handling this on every prior deployment target in both curricula; its absence here is a real, visible difference, not a subtle one.
- **"Raspberry Pi" spans genuinely different GPU driver implementations across generations/configurations** — code and performance characteristics validated on one aren't guaranteed to transfer to another without re-verification, a meaningfully sharper version of Day 28's "profile on real target hardware" advice.
- **Thermal throttling produces symptoms visually indistinguishable from application-level rendering problems** — `vcgencmd get_throttled` is a genuinely essential, cheap, first-line diagnostic specifically for this device category, and skipping it risks chasing an application-code performance bug that doesn't actually exist.
- **The absence of a compositor makes correct rendering practice matter more, not less** — Day 7/QML's delegate recycling and `Shape`-over-`Canvas` discipline directly translates into a more responsive-feeling device here, with no compositor overhead diluting either the cost of doing it wrong or the benefit of doing it right.

### Exercise

1. On real Pi hardware, check `cat /boot/config.txt | grep vc4` to confirm which GPU driver stack is actually active, and cross-reference against your Pi's specific model/revision — write down which one you're actually running, since this affects how you interpret every other performance result today.
2. Deliberately create a fast-moving visual (drag a `Slider` rapidly, or temporarily set a very short `Behavior` animation duration on a frequently-updating value) and look for visible tearing on the actual display — then toggle `QT_QPA_EGLFS_FORCEVSYNC` and confirm you can see a real, visible difference, not just a numeric one.
3. Run your actual `mqtt_monitor_qml` panel under sustained real load (live MQTT data, both curricula's full dashboard) for at least 20–30 minutes in whatever enclosure/mounting you actually intend to deploy in, periodically checking `vcgencmd measure_temp`/`get_throttled` — confirm whether thermal throttling is a real risk for your specific physical deployment before assuming any performance issue you observe is application-side.

### Key Takeaways

- There's no compositor under `eglfs` absorbing vsync/buffer-swap timing issues — tearing and frame timing are now directly, visibly your application's responsibility, configured via `QT_QPA_EGLFS_FORCEVSYNC` and driver-level swap-interval settings.
- Different Raspberry Pi generations/configurations run genuinely different GPU driver stacks (`vc4-fkms-v3d` vs. `vc4-kms-v3d`) — performance characteristics and even correctness don't automatically transfer across them without re-verification.
- `vcgencmd get_throttled`/`measure_temp` should be your first diagnostic check for any performance problem on real Pi hardware, especially one that appears only after sustained runtime — thermal throttling looks identical to a rendering bug from the application's perspective, but lives in a completely different layer.
- The absence of a compositor makes Day 7/QML's rendering-discipline lessons (delegate recycling, `Shape` over `Canvas`) matter more, not less — there's nothing downstream to dilute either the cost of getting this wrong or the benefit of getting it right.
