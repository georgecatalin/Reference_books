[[Qt Quick QML]]

## Day 6: States and Transitions — QML's Declarative Alternative to Imperative UI-State Management

### Concept: `State` Replaces "A Pile of If-Statements Changing Properties"

In Widgets, changing a widget's appearance based on some condition meant imperative code: `if (connected) { indicator->setStyleSheet(...); button->setEnabled(...); }` scattered wherever that condition changes (Day 5's `connectAction`/`disconnectAction` toggling is a good example — several separate property assignments, done together, by hand, every time). QML's `State` system lets you **declare each named state as a complete, self-contained set of property values**, then switch between them with one assignment — the engine handles applying every property change atomically, and (with `Transition`) animating between them.

This is a genuinely different way of thinking about "what does the UI look like when X" — instead of scattered imperative updates, you declare each named condition once, up front, as data.

### Annotated Code: A Connection Status Indicator With Real States

```qml
import QtQuick
import QtQuick.Controls

Rectangle {
    id: indicator
    width: 160
    height: 40
    radius: 4

    property bool connected: false
    property bool connecting: false

    Text {
        id: label
        anchors.centerIn: parent
        color: "white"
        font.bold: true
    }

    // 'state' is a built-in property every Item has — its value is just
    // a string naming which of the states below is currently active
    state: connecting ? "connecting" : (connected ? "connected" : "disconnected")

    states: [
        State {
            name: "disconnected"
            PropertyChanges {
                target: indicator
                color: "#e74c3c"
            }
            PropertyChanges {
                target: label
                text: "Disconnected"
            }
        },
        State {
            name: "connecting"
            PropertyChanges {
                target: indicator
                color: "#f39c12"
            }
            PropertyChanges {
                target: label
                text: "Connecting..."
            }
        },
        State {
            name: "connected"
            PropertyChanges {
                target: indicator
                color: "#2ecc71"
            }
            PropertyChanges {
                target: label
                text: "Connected"
            }
        }
    ]

    // Transitions define HOW the engine animates property changes when
    // moving between states — without this block, state changes are
    // instant/discrete (a valid choice too, not every transition needs
    // animation, same "use restraint" lesson as Day 12)
    transitions: [
        Transition {
            ColorAnimation { target: indicator; property: "color"; duration: 300 }
        }
    ]
}
```

Driving it from real data — this replaces Day 5's Widgets `connectionIndicator->setText()`/`setStyleSheet()` pairs entirely:

```qml
ConnectionIndicator {
    id: statusIndicator
    // Bound directly to real backend state — no manual state-tracking code
    // needed anywhere else; this indicator now reflects reality automatically
    connected: ApiClient.isHealthy   // assuming a Q_PROPERTY(bool isHealthy ...)
                                       // exposed from your real ApiClient
}
```

### `PropertyChanges` — What It Actually Does (and Doesn't Do)

`PropertyChanges` inside a `State` is **not** imperative code — it's a declaration of "when this state is active, these properties have these values," which the engine reconciles against whatever the properties' _base_ (non-state) values were. This means:

- Properties **not mentioned** in a `PropertyChanges` block simply keep their base/default value — you don't need to redundantly restate every property in every state, only the ones that actually differ.
- Switching `state` back to a state you've already left correctly restores those base values for anything not overridden — this is handled by the engine, not something you need to code defensively against (a real relief compared to Widgets, where forgetting to reset a property when leaving a code path is a classic bug).

### Real Application: `DeviceCard`'s Alert State — Replacing Day 12's Manual Color Animation

Recall Day 3's `DeviceCard.qml` with a `statusText()` JS function and manual border-color binding. States are the more idiomatic way to express genuinely distinct visual modes (normal / alerting / offline) rather than accumulating conditional bindings:

```qml
Rectangle {
    id: card
    property string deviceId: ""
    property real temperature: 0.0
    property bool online: true

    width: 220
    height: 70
    radius: 6
    border.width: 1

    state: !online ? "offline" : (temperature > 80 ? "alerting" : "normal")

    states: [
        State {
            name: "normal"
            PropertyChanges { target: card; color: "#313244"; border.color: "#45475a" }
        },
        State {
            name: "alerting"
            PropertyChanges { target: card; color: "#3d1f1f"; border.color: "#e74c3c" }
        },
        State {
            name: "offline"
            PropertyChanges { target: card; color: "#181825"; border.color: "#313244" }
        }
    ]

    transitions: Transition {
        ColorAnimation { properties: "color,border.color"; duration: 250 }
    }

    // ... rest of the card's content from Day 3, unchanged ...
}
```

**This is a direct, cleaner replacement for the pattern that was building up in Day 3's exercises** — rather than several independent bindings each computing their own condition (`color: temperature > 80 ? ... : ...`, `border.color: temperature > 80 ? ... : ...`, each repeating the same condition), one `state` expression names the mode once, and every associated property change groups together under it. This scales much better as the number of state-dependent properties grows — Day 3's approach becomes unwieldy past 2–3 conditional bindings; `states` doesn't.

### `Behavior` — Animating _Any_ Property Change, Without Explicit States

Sometimes you want smooth animation on a property change that isn't really a distinct named "state" — just a continuous value that should animate rather than jump (the direct QML equivalent of Day 23's `GaugeWidget` separating `targetValue` from `currentDisplayValue`):

```qml
Rectangle {
    id: gaugeArc
    property real value: 0.0

    // Behavior says "whenever 'value' changes, for ANY reason, animate
    // it smoothly" — no separate targetValue/currentDisplayValue split
    // needed, no QPropertyAnimation object to create/reuse/retarget
    // manually. The engine handles retargeting an in-flight animation
    // automatically if 'value' changes again before the previous
    // animation finishes — exactly the concern Day 23's exercise 2 asked
    // you to verify by hand in C++.
    Behavior on value {
        NumberAnimation { duration: 500; easing.type: Easing.OutCubic }
    }

    // ... arc drawing using 'value' ...
}
```

**This is a genuinely significant simplification over Day 23's C++ gauge code** — `Behavior` gives you the "animate this property smoothly, and correctly retarget on rapid updates" behavior _for free_, as a declarative one-liner, where the Widgets version needed an explicit `QPropertyAnimation` object, manual `stop()`/retarget logic, and a `Q_PROPERTY` declaration. This is one of the clearest illustrations of why QML exists as a distinct tool for this kind of UI, not just Widgets with different syntax.

### Why This Matters

- **States group related property changes under one named condition**, replacing scattered conditional bindings or imperative multi-line updates — this scales far better as visual complexity grows, and it's the correct default reach whenever a component has more than 2–3 genuinely distinct visual modes.
- **The engine handles restoring un-overridden properties when leaving a state** — you don't write "reset to normal" logic; it's structural, not something you can forget.
- **`Behavior` is QML's built-in answer to Day 23's `targetValue`/`currentDisplayValue`/manual animation-object-reuse pattern** — recognizing that `Behavior` replaces a genuine chunk of C++ boilerplate you already know well is worth sitting with, since it's one of the more concrete "this is why QML, not just Widgets with prettier syntax" moments in this curriculum.
- **`transitions` (state-to-state) and `Behavior` (any property change) are two different tools for animation** — `transitions` for discrete named-mode changes, `Behavior` for continuous value changes; conflating them is a common early mistake (trying to force a smoothly-varying gauge value into a `states`/`transitions` structure it doesn't fit).

### Exercise

1. Rebuild Day 3's `DeviceCard.qml` alert-coloring logic (currently separate conditional bindings, per that day's exercise 1) using today's `states`/`transitions` pattern instead — confirm the visual behavior is identical, and note how much less repetition exists once the condition is named once instead of re-evaluated in every property binding.
2. Add a `Behavior on temperature` block to `DeviceCard.qml` so the displayed temperature number itself animates (count up/down smoothly) between values, rather than jumping instantly — this is a nice, cheap dashboard touch and a direct rehearsal of the `Behavior` mechanism on a real property.
3. Build the gauge arc from Day 23 (the C++/`QPainter` version) as a QML equivalent using `Canvas` (QML's 2D drawing surface, roughly analogous to overriding `paintEvent`) combined with `Behavior on value` — compare the resulting code volume against Day 23's C++ version directly, to make the "QML meaningfully reduces this specific kind of code" claim concrete rather than asserted.

### Key Takeaways

- `State` + `PropertyChanges` groups related property values under one named condition — properties not mentioned in a state keep their base values automatically, with no manual "reset" logic required.
- `transitions` animates state-to-state changes; `Behavior` animates any property change continuously, without needing named states at all — these are two different tools for two different animation shapes, not interchangeable.
- `Behavior` is QML's built-in replacement for the entire manual `QPropertyAnimation`-object-reuse-and-retarget pattern from Day 23 — a real, substantial reduction in code for "smoothly animate this value whenever it changes."
- Reach for `states` once a component has more than a couple of genuinely distinct visual modes — scattered conditional bindings repeating the same condition is the signal you've outgrown that approach.

---

Say "next" for Day 7 (performance on embedded/GPU-constrained targets — the QML-specific profiling tools, common rendering pitfalls like excessive item counts and heavy `Canvas` usage, and what actually matters for a Pi-driven touchscreen deployment).
