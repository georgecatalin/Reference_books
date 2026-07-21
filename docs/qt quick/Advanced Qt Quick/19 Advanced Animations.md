[[Advanced Qt Quick]]
# Day 19 — Advanced Animations: Composition, Sequencing, and Particle Systems (When They Earn Their Cost)

Phase 1 gave you `Behavior`, `State`/`Transition`, basic `NumberAnimation`/`ColorAnimation`. Today: composing multiple animations correctly (sequential vs parallel), animation controllers you can start/stop/reuse, and a clear-eyed look at `ParticleSystem` — genuinely useful for a couple of specific dashboard effects, overkill for most of what beginners reach for it for.

## Concept: `SequentialAnimation` vs `ParallelAnimation` — composing multiple steps

You've used single animations in isolation. Real polish often needs _sequences_ (do A, then B) or _simultaneous combinations_ (fade and scale together):

```qml
// A "new device discovered" flash — sequential: pulse, then settle
SequentialAnimation {
    id: discoveryFlash
    running: false

    ParallelAnimation {
        NumberAnimation { target: card; property: "scale"; from: 1.0; to: 1.15; duration: 150; easing.type: Easing.OutQuad }
        ColorAnimation  { target: card; property: "color"; to: "#89b4fa"; duration: 150 }
    }
    ParallelAnimation {
        NumberAnimation { target: card; property: "scale"; from: 1.15; to: 1.0; duration: 250; easing.type: Easing.OutBack }
        ColorAnimation  { target: card; property: "color"; to: Theme.surface; duration: 250 }
    }
}
```

Trigger it imperatively when a genuinely discrete event happens (not a continuous property change — that's `Behavior`'s job):

```qml
Connections {
    target: MqttManager.devices
    function onRowsInserted() { discoveryFlash.start() }
}
```

**The distinction that matters:** `ParallelAnimation` groups animations that should run _together_ (scale + color at once, both taking the group's max duration to complete); `SequentialAnimation` chains steps where one must _finish_ before the next starts (pulse up, then settle back down). Nesting them (a `ParallelAnimation` inside a `SequentialAnimation`, as above) is completely normal and is how most real "juicy" UI micro-interactions are actually built — not through one clever monolithic animation, but through small composed steps.

## Concept: `PropertyAnimation` reuse — named animations you `start()`/`stop()` on demand

Rather than a `Behavior` (which fires automatically on every change), sometimes you want an animation you trigger explicitly, possibly multiple times, possibly interrupting itself:

```qml
NumberAnimation {
    id: attentionPulse
    target: alertIcon
    property: "opacity"
    from: 1.0
    to: 0.3
    duration: 400
    loops: 3
    onFinished: alertIcon.opacity = 1.0   // ensure it settles cleanly, don't leave it mid-fade
}

// Elsewhere:
Button { onClicked: attentionPulse.restart() }
```

`restart()` (not `start()`) is worth knowing specifically — it stops any in-flight run and begins fresh, which matters if a user can trigger the same alert repeatedly before the first pulse finishes; `start()` alone on an already-running animation is a no-op, which surprises people expecting it to reset.

## Concept: `Behavior` composition — multiple behaviors on related properties, kept in sync

For your gauge/telemetry widgets, you'll often want several properties easing together — but naively adding a `Behavior` to each independently can look uncoordinated if they use different durations/easings:

```qml
// GaugeArc.qml extension — smoothing BOTH value and color together, deliberately matched timing
property real displayValue: value
property color displayColor: value > 0.8 ? Theme.danger : Theme.success

Behavior on displayValue { NumberAnimation { duration: 300; easing.type: Easing.OutCubic } }
Behavior on displayColor { ColorAnimation { duration: 300 } }   // SAME duration — deliberate
```

Using `displayValue`/`displayColor` as intermediary properties (rather than animating `value` directly, which is your public API/data source) is a real pattern worth internalizing: **separate the "true" data property from the "smoothed for display" property.** This keeps your public API (`value`) reflecting actual current state instantly (useful if other logic reads it), while the _visual_ representation eases — subtle but correct, and avoids a trap where something reads `gauge.value` expecting the real current number but gets a mid-animation interpolated value instead.

## Concept: `ParticleSystem` — when it's worth the weight, and when it isn't

`QtQuick.Particles` gives you `Emitter`, `ImageParticle`, `Affector`s (gravity, turbulence, etc.) for genuine particle effects. **Be honest about when this earns its complexity for a device monitoring dashboard**: it's real overhead (a dedicated render system, more moving parts to tune) and it's easy to reach for it because it looks impressive, not because it solves a problem your users have.

**Legitimate use for your project**: a brief "connection established" celebratory burst, or a subtle ambient background effect on an idle/empty state screen. **Not** a legitimate use: anything that needs to convey actual information (data should never be encoded only in a particle effect — accessibility and clarity both suffer).

```qml
import QtQuick
import QtQuick.Particles

Item {
    width: 200; height: 200

    ParticleSystem { id: sys }

    ImageParticle {
        system: sys
        source: "qrc:/particle.png"   // a small soft circle/dot asset
        color: Theme.success
        colorVariation: 0.1
    }

    Emitter {
        id: burstEmitter
        system: sys
        anchors.centerIn: parent
        emitRate: 0
        lifeSpan: 600
        velocity: AngleDirection { angle: 0; angleVariation: 360; magnitude: 80; magnitudeVariation: 40 }
        size: 8
        endSize: 0
    }

    function celebrateConnection() {
        burstEmitter.burst(24)   // one-shot burst of 24 particles, not a continuous emitRate
    }
}
```

`emitRate: 0` + calling `.burst(n)` on demand (rather than a continuous `emitRate`) is the right pattern for a discrete celebratory moment rather than an ongoing ambient effect — continuous emitters have real, sustained GPU/CPU cost that's easy to forget about once it's running in the background of a dashboard meant to stay open for hours.

## Exercise

1. Build the `discoveryFlash` sequential/parallel animation above and trigger it from `DeviceListModel`'s `rowsInserted` signal (inherited from `QAbstractListModel` — you get this for free, it fires whenever your Day 14 `beginInsertRows`/`endInsertRows` runs) — confirm a genuinely new device visually announces itself distinctly from a routine update.
2. Add the `displayValue`/`displayColor` intermediary pattern to your `GaugeArc` from Day 13, and prove the distinction matters: bind a `Label` to `gauge.value` directly (should update instantly) alongside the gauge's visual arc (should ease) — confirm they now behave differently on purpose, where before they'd have both jumped together.
3. Build the one-shot particle burst above, wire it to fire once when `MqttManager.connected` transitions to `true` (not on every reconnect-check, only on the actual state change — think about how you'd guard against re-firing on every property re-evaluation).
4. Deliberately set the emitter to `emitRate: 30` (continuous) instead of burst-based, leave it running, and watch your app's CPU usage (Activity Monitor/`top`) over a minute compared to the burst version — this is meant to make the cost concrete rather than theoretical.

## Key takeaways

- `SequentialAnimation`/`ParallelAnimation` compose small steps into real micro-interactions — most polished UI motion is several simple animations combined, not one complex one.
- `restart()` resets an in-flight animation; `start()` on an already-running animation is a no-op — use `restart()` for anything re-triggerable by repeated user action.
- Separate a "true" data property from a "smoothed for display" property when other logic needs the instant real value while the visual should ease — don't let visual smoothing corrupt what's semantically your actual current state.
- `ParticleSystem` is real overhead — reserve it for genuinely discrete, celebratory, non-informational moments (`burst()`), and be wary of continuous `emitRate` running unattended in a long-lived dashboard.
- `QAbstractListModel` gives you `rowsInserted`/`rowsRemoved`/`dataChanged` signals for free (Day 14's `begin/endInsertRows` calls trigger them) — these are excellent, already-available hooks for triggering discrete UI reactions like a discovery flash.

