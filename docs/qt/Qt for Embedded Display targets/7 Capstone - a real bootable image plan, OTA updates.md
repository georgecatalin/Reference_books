[[Qt for Embedded Display targets]]

## Day 7: Capstone — A Real Bootable Image Plan, OTA Updates, and the Full Three-Curriculum Decision Framework

### Concept: Today Assembles Decisions, Not Code — The Same Spirit as Every Capstone Before It

Days 1–6 gave you individual pieces: the QPA plugin choice, the platform strategy, cross-compilation, input handling, boot resilience, and performance discipline. Today's job is turning those into one concrete, written plan for your actual `mqtt_monitor` deployment — plus the one piece not yet covered: **how does a device that's already out on a wall get updated** without you physically retrieving the SD card.

### The Concrete Image Plan for `mqtt_monitor`, Given Everything Established

Following Day 2's honest recommendation (stock Raspberry Pi OS + `eglfs`, Yocto/Buildroot only once a concrete requirement forces it), here's what an actual build-and-flash pipeline looks like:

```
1. Base: Raspberry Pi OS Lite (64-bit), no desktop environment installed at all
2. Boot config: vc4-kms-v3d overlay confirmed active (Day 6), eglfs_kms.json
   pointed at the verified /dev/dri device (Day 1)
3. Partition layout: root read-only, /data read-write, commit=1 (Day 5)
4. Your app: /opt/mqtt_monitor/mqtt_monitor_qml (cross-compiled per Day 3,
   or built directly on-device if distro Qt6 already sufficed)
5. Config/DB paths: redirected to /data (Day 5) — QSettings and
   PersistenceWorker both point there explicitly
6. Boot sequence: splash.service (framebuffer, starts earliest) →
   mqtt-monitor-panel.service (Restart=always, WatchdogSec=15) → splash
   killed once QQmlApplicationEngine::objectCreated fires (Day 5)
7. Input: libinput backend, calibration matrix applied via udev hwdb
   if your specific touchscreen unit needs it (Day 4)
```

This is the actual deliverable worth writing down as a real document for your project — not because the curriculum says so, but because six months from now, reflashing a replacement SD card after a failure without this written down means re-deriving every one of these decisions from scratch under time pressure.

### OTA Updates — The One Genuinely New Piece

A wall-mounted panel you can't easily walk over to with a new SD card needs a way to receive updates remotely. Three real approaches, in order of complexity:

**1. Simple: `systemd` + a pull-based update script, cron-triggered**

```bash
#!/bin/bash
# /opt/mqtt_monitor/update.sh — checks a known URL for a newer binary,
# downloads it to /data (writable), verifies it, swaps it in
CURRENT_VERSION=$(cat /opt/mqtt_monitor/VERSION)
LATEST_VERSION=$(curl -sf https://updates.example.com/mqtt_monitor/latest_version.txt)

if [ "$CURRENT_VERSION" != "$LATEST_VERSION" ]; then
    curl -sf -o /data/mqtt_monitor_qml.new \
        "https://updates.example.com/mqtt_monitor/mqtt_monitor_qml-${LATEST_VERSION}"
    # Verify before trusting it — a corrupted/partial download replacing
    # your working binary is a real, avoidable self-inflicted failure
    if sha256sum -c /data/mqtt_monitor_qml.new.sha256; then
        sudo mount -o remount,rw /
        cp /data/mqtt_monitor_qml.new /opt/mqtt_monitor/mqtt_monitor_qml
        echo "$LATEST_VERSION" > /opt/mqtt_monitor/VERSION
        sudo mount -o remount,ro /
        sudo systemctl restart mqtt-monitor-panel.service
    fi
fi
```

Genuinely fine for a small number of units, low update frequency. Honest limitation: **no rollback if the new version is broken** — it just replaces the working binary, and `Restart=always` will happily keep restarting a crashing new version forever.

**2. Better: A/B partition update (the pattern Android and most serious embedded products actually use)** — two complete root filesystem copies, update writes to the _inactive_ one, a bootloader flag switches which one boots next, and **only marks the update successful after confirming the new version actually boots and runs correctly** — automatic rollback to the known-good partition if it doesn't. This is real, non-trivial infrastructure (RAUC and mender.io are the two most common off-the-shelf tools implementing this pattern for embedded Linux) — genuinely worth adopting once you have more than a couple of deployed units where "walk over with an SD card" stops being a reasonable fallback.

**3. The honest recommendation for `mqtt_monitor` at its current stage**: start with option 1, explicitly accepting its rollback limitation, and treat RAUC/mender as the deliberate next step **only once you have enough physically-remote deployed units that a bad update becomes a real operational risk** rather than a hypothetical one — this is the exact same judgment call as Day 2's platform choice and Day 29/Widgets' plugin architecture, applied a third time to the same underlying principle: infrastructure earns its complexity from a concrete requirement, not from being the more sophisticated-sounding answer.

### The Full Three-Curriculum Decision Framework

You've now built genuine, working command of three distinct layers:

|Layer|What you built|When it's the right tool|
|---|---|---|
|**Widgets** (30 days)|Dense dashboard, model/view, threaded MQTT/serial/SQLite backend|Dev workstation, admin/debug tooling, mouse-precision interaction|
|**QML** (10 days)|Touch-first panel, same unmodified backend, GPU-accelerated UI|Touchscreen deployment, wherever fluid animation is central to the UX|
|**Embedded deployment** (7 days)|Direct-to-display rendering, boot resilience, OTA strategy|Whenever the QML panel becomes a physically deployed, unattended device rather than something running in a window on your desk|

**The throughline worth stating explicitly, since it's the actual point of doing all three**: `MqttWorker`, `SerialWorker`, `ApiClient`, and `DeviceTableModel` — the genuine engineering core of this whole project — were written once, in the first 30 days, and never modified for either the QML front end or this week's embedded deployment work, beyond Day 4/QML's additive `roleNames()`. Everything from Day 1 of this embedded week onward was about **where and how the presentation layer runs**, not about rewriting application logic. That separation held because it was designed to hold from Day 16 of the very first curriculum onward — the worker-object threading discipline, the single ingestion choke point, model/view separation. This week's real lesson is confirmation, a third time, that the original architecture was sound enough to survive not just a second UI stack, but an entirely different deployment reality underneath it.

### Exercise (Closing)

1. Write the actual image plan above as a real `DEPLOYMENT.md` for your project — filled in with your real device paths, your real touchscreen's calibration values (if applicable), your real update URL — not the placeholder values shown here.
2. Make the OTA decision explicitly, in writing: option 1 for now, with the specific unit-count or incident that would push you toward option 2 — same pattern as every other architecture-decision exercise across all three curricula.
3. If you haven't already, do the single most valuable end-to-end test available to you: flash a real SD card following your actual `DEPLOYMENT.md`, boot it with no monitor/keyboard attached beyond the touchscreen itself, and confirm the whole chain — splash, panel launch, MQTT connection, touch input — works from cold power-on with zero manual intervention. That's the actual bar this device needs to clear.

### Key Takeaways

- The concrete image plan for `mqtt_monitor` combines every prior day's decision into one written deployment document — worth doing as real project documentation, not just a curriculum exercise.
- OTA updates start simple (pull-based script replacing a binary, no rollback) and only earn A/B-partition complexity (RAUC/mender) once a concrete number of remotely-deployed units makes rollback-free updates a real operational risk — the same infrastructure-earns-its-complexity judgment call made repeatedly across all three curricula.
- The three curricula together demonstrate one throughline: a correctly-architected backend (worker-object threading, single ingestion choke point, model/view separation) survives a second UI stack and a completely different deployment reality with almost no modification — that was the actual point of building it that way from Day 16 of the very first curriculum.
- The real bar for this device: cold power-on, zero manual intervention, working dashboard — that's the test that matters more than any individual day's lesson in isolation.
