[[Foundations]]

# Day 7 — States, Transitions, and Animations

Everything so far has been static or instantly-changing (a color flips, a label updates). Today: QML's formal **state machine** for UI, and how to animate the transitions between states — the difference between a UI that _works_ and one that _feels_ considered.

## Concept: `State` — named configurations of property values

A `State` is a named bundle of property overrides applied to one or more items. Instead of scattering `if (connected) { color = ... } else { color = ... }` logic, you declare named states and switch between them:

```qml
Rectangle {
    id: statusDot
    width: 14; height: 14; radius: 7
    color: "#6c7086"   // default/base state

    state: root.connected ? "online" : (root.reconnecting ? "reconnecting" : "offline")

    states: [
        State {
            name: "online"
            PropertyChanges { target: statusDot; color: "#a6e3a1" }
        },
        State {
            name: "reconnecting"
            PropertyChanges { target: statusDot; color: "#f9e2af" }
        },
        State {
            name: "offline"
            PropertyChanges { target: statusDot; color: "#f38ba8" }
        }
    ]
}
```

Notice: this could've been written as a ternary binding on `color` directly (and for 2 states, that's often simpler — don't over-engineer). States earn their keep when you have **3+ configurations affecting multiple properties simultaneously** (position, opacity, scale, color all changing together for one logical mode) — that's when a ternary chain becomes unreadable and named states become clearer.

## Concept: `Transition` — animating the _change_ between states

Without a `Transition`, state changes are instant (a property just jumps to its new value). A `Transition` intercepts that jump and animates it:

```qml
Rectangle {
    id: statusDot
    width: 14; height: 14; radius: 7

    state: root.connected ? "online" : "offline"

    states: [
        State { name: "online"; PropertyChanges { target: statusDot; color: "#a6e3a1"; scale: 1.2 } },
        State { name: "offline"; PropertyChanges { target: statusDot; color: "#f38ba8"; scale: 1.0 } }
    ]

    transitions: [
        Transition {
            ColorAnimation { duration: 300 }
            NumberAnimation { property: "scale"; duration: 200; easing.type: Easing.OutBack }
        }
    ]
}
```

`ColorAnimation` and `NumberAnimation` are type-specific animators — QML picks reasonable defaults for interpolation, but specifying the right animation type (`ColorAnimation` for colors, `NumberAnimation` for numeric properties like `x`, `opacity`, `scale`) avoids surprises versus the generic `PropertyAnimation`.

## Concept: `Behavior` — the lightweight alternative for a _single_ property

For animating one property continuously (not tied to discrete named states), `Behavior` is simpler and usually what you want:

```qml
Rectangle {
    id: gauge
    width: 100
    height: barHeight
    color: "#89b4fa"

    property real barHeight: 40

    Behavior on barHeight {
        NumberAnimation { duration: 250; easing.type: Easing.OutCubic }
    }
}
```

Now every time `barHeight` changes — from a slider, from live telemetry, from anywhere — it animates smoothly instead of jumping. This is exactly what you'll use for live gauges reacting to MQTT sensor readings (Day 20): the value changes discretely (a new MQTT message arrives), but the _display_ eases toward it rather than jumping, which reads as far more polished and legible during rapid updates.

**Rule of thumb:** `Behavior` for "this one property should always ease when it changes, regardless of why." `State`/`Transition` for "this item has distinct named modes, each affecting several properties, and I want to control the animation per-transition-pair" (e.g., a different animation going online→offline than offline→online).

## Concept: `Timer`-driven vs event-driven animation — don't animate on a poll loop

A common anti-pattern from imperative backgrounds: setting up a `Timer` to repeatedly re-check a value and manually animate toward it. Don't — bindings + `Behavior` already give you continuous reactive animation for free. Reserve `Timer` for things that are genuinely time-based (simulated data, periodic re-fetch, timeouts), not for "keep checking if something changed."

## Annotated example: connection status indicator with proper transition asymmetry

```qml
import QtQuick

Item {
    id: root
    width: 160
    height: 40
    property bool connected: false

    Rectangle {
        id: dot
        width: 14; height: 14; radius: 7
        anchors.verticalCenter: parent.verticalCenter
        anchors.left: parent.left

        state: root.connected ? "online" : "offline"

        states: [
            State { name: "online"; PropertyChanges { target: dot; color: "#a6e3a1" } },
            State { name: "offline"; PropertyChanges { target: dot; color: "#f38ba8" } }
        ]

        transitions: [
            Transition {
                from: "offline"; to: "online"
                ColorAnimation { duration: 400; easing.type: Easing.OutCubic }
            },
            Transition {
                from: "online"; to: "offline"
                ColorAnimation { duration: 150 }   // faster — losing connection should feel immediate
            }
        ]
    }

    Label {
        anchors.left: dot.right
        anchors.leftMargin: 10
        anchors.verticalCenter: parent.verticalCenter
        text: root.connected ? "Connected" : "Disconnected"
    }

    MouseArea {
        anchors.fill: parent
        onClicked: root.connected = !root.connected
    }
}
```

The asymmetric transition durations (`from`/`to` targeting specific direction pairs) are a small but real UX detail: things appearing/succeeding can animate in gently; things failing/disconnecting should register faster, because delayed negative feedback reads as sluggish, not smooth. This is the kind of detail that separates "technically animated" from "well designed" — worth internalizing now since it costs nothing extra to do correctly.

## Exercise

1. Take Day 6's device row delegate and add a `Behavior on color` so the online/offline background color change (currently instant via `setProperty`) eases smoothly.
2. Build a live "signal strength bar" — a `Rectangle` whose `width` is bound to `rssi` (mapped/clamped into a 0–100 range), with a `Behavior on width` using `NumberAnimation`. Hook it to the same random RSSI `Timer` from Day 6's exercise and watch it visually settle rather than jump.
3. Add a genuine 3-state example: a device card with `states: ["normal", "warning", "critical"]` based on a temperature threshold (`< 60` normal, `60–80` warning, `> 80` critical), each changing **both** color and a subtle `scale` pulse — this is the case where states earn their complexity over a plain ternary.
4. For the critical state, add a repeating pulse using `SequentialAnimation` + `NumberAnimation` on `opacity` set to `loops: Animation.Infinite` — triggered only while in the "critical" state (hint: `running: dot.state === "critical"`).

## Key takeaways

- `State`/`PropertyChanges` bundle multiple property overrides under a named configuration — worth it at 3+ properties/states, overkill for a simple 2-way ternary.
- `Transition` animates the _change_ between states, and can be asymmetric (`from`/`to` specific pairs) — fast for negative/failure transitions, gentler for positive ones, is a real and cheap UX win.
- `Behavior on property` is the simpler tool for "always ease this one value regardless of why it changed" — this is what you'll use for live telemetry gauges.
- Don't poll-and-manually-animate; bindings + `Behavior` already react continuously and correctly to any source of change.
- `ColorAnimation`/`NumberAnimation` are type-specific — prefer them over the generic `PropertyAnimation` for clarity and correct interpolation.

