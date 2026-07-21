[[Qt Quick QML]]

## Day 3: Custom Components — The QML Equivalent of `DeviceCard`

### Concept: A `.qml` File _Is_ a Reusable Component — No Extra Ceremony Required

This is the single biggest structural surprise coming from Widgets: in C++, making a reusable widget meant writing a whole class (`DeviceCard : public QWidget`, Day 11). In QML, **any `.qml` file automatically becomes a usable component type**, named after its filename. Create `DeviceCard.qml`, and `DeviceCard { }` is immediately usable anywhere else in your project — no registration step, no import statement beyond making sure the file's in the right directory. This is a much lower-ceremony process than Widgets' composite-widget pattern, and it's worth sitting with that contrast explicitly rather than assuming there's hidden complexity you're missing.

### Annotated Code: `DeviceCard.qml` — Direct Analog of Day 11's Composite Widget

`DeviceCard.qml`:

```qml
import QtQuick
import QtQuick.Controls

// The root item type becomes this component's "base type" — much like
// Day 11's DeviceCard inheriting from QWidget, this Rectangle-rooted
// component inherits Rectangle's properties (color, border, etc.)
Rectangle {
    id: card

    // Public properties — this component's actual interface, directly
    // analogous to Day 11's setTemperature()/setOnline() setters, but
    // expressed as plain bindable properties instead of setter methods
    property string deviceId: ""
    property real temperature: 0.0
    property bool online: true

    // A custom SIGNAL — the QML equivalent of Day 11's detailsRequested(),
    // declared with the 'signal' keyword, emitted explicitly in a handler
    signal detailsRequested(string deviceId)

    width: 220
    height: 70
    radius: 6
    color: "#313244"
    border.color: "#45475a"
    border.width: 1

    Row {
        anchors.fill: parent
        anchors.margins: 8
        spacing: 10

        // The status LED — a small custom-drawn indicator, direct analog
        // of Day 4 Widgets' StatusLedWidget, but expressed as a plain
        // declarative Rectangle with a COLOR BINDING instead of a
        // paintEvent() override
        Rectangle {
            width: 14
            height: 14
            radius: 7
            anchors.verticalCenter: parent.verticalCenter
            // Live binding, not an imperative setStatus() call — this
            // updates automatically the instant card.online changes
            color: card.online ? "#2ecc71" : "#e74c3c"
        }

        Column {
            anchors.verticalCenter: parent.verticalCenter
            spacing: 2

            Text {
                text: card.deviceId
                color: "#cdd6f4"
                font.bold: true
            }
            Text {
                text: card.temperature.toFixed(1) + " °C"
                color: "#a6adc8"
            }
        }

        Button {
            text: "Details"
            anchors.verticalCenter: parent.verticalCenter
            // Signal TRANSLATION — the exact same pattern as Day 11's
            // lambda that re-emitted the button's clicked() as the
            // card's own detailsRequested(deviceId). Same architectural
            // reasoning: callers of DeviceCard connect to detailsRequested,
            // never reaching into this internal Button directly.
            onClicked: card.detailsRequested(card.deviceId)
        }
    }
}
```

### Using It — Exactly Like Any Built-In QML Type

`main.qml`:

```qml
import QtQuick
import QtQuick.Controls

ApplicationWindow {
    width: 500
    height: 400
    visible: true

    Column {
        anchors.centerIn: parent
        spacing: 8

        // No import needed for DeviceCard.qml specifically — files in the
        // same directory are automatically visible as component types.
        // (Cross-directory reuse needs a proper QML module, Day 5 territory.)
        DeviceCard {
            deviceId: "device-01"
            temperature: 42.5
            online: true
            // Connecting to the custom signal — 'onDetailsRequested' is
            // automatically generated from 'signal detailsRequested(...)',
            // exactly the same automatic naming convention QML uses for
            // built-in signals like onClicked
            onDetailsRequested: (id) => console.log("Details requested for:", id)
        }

        DeviceCard {
            deviceId: "device-02"
            temperature: 91.2
            online: false
            onDetailsRequested: (id) => console.log("Details requested for:", id)
        }
    }
}
```

### The Automatic Signal Handler Naming Convention — Worth Stating Explicitly

Every signal `foo` you declare automatically gets a corresponding `onFoo` property you can assign a handler to — this isn't special-cased for built-in signals, it's a uniform language rule. `signal detailsRequested(string deviceId)` gives you `onDetailsRequested`, exactly the same mechanism that gives `Button`'s built-in `clicked()` signal its `onClicked` handler property. Internalizing that this is one general rule (not dozens of memorized special cases) will save you from treating every new component's signals as something new to look up.

### JavaScript Functions — QML's Equivalent of a Private Helper Method

For logic that's more than a one-line expression but still belongs conceptually to this component (not promoted to full C++), QML supports named JS functions directly in the component:

```qml
Rectangle {
    id: card
    // ... existing properties ...

    // A genuine function, not a binding — called explicitly, not
    // automatically re-evaluated on dependency changes. This is QML's
    // equivalent of a small private helper method.
    function statusText() {
        if (temperature > 80) return "ALERT";
        if (!online) return "OFFLINE";
        return "OK";
    }

    Text {
        text: card.statusText()   // called once per re-evaluation of THIS
                                    // binding — re-runs whenever temperature
                                    // or online change, since those are the
                                    // properties the function reads
        color: card.temperature > 80 ? "#e74c3c" : "#a6adc8"
    }
}
```

**The subtlety worth understanding**: `text: card.statusText()` still behaves like a binding — QML's binding engine detects that `statusText()` reads `temperature` and `online`, and re-evaluates the whole expression (calling the function again) whenever either changes. This is genuinely more implicit than Widgets' explicit `connect()` calls, and it's usually a feature — but it's also why keeping these functions small and side-effect-free matters: a function with side effects, called implicitly on every dependency change, is a real source of subtle bugs (Day 7 covers a performance-shaped version of this concern).

### Property Change Handlers — QML's `NOTIFY` Equivalent, Automatic

Recall Day 12's `Q_PROPERTY(... NOTIFY temperatureChanged)` — you had to declare that signal yourself in C++. In QML, **every property automatically gets a corresponding change signal and handler for free**, no declaration needed:

```qml
Rectangle {
    id: card
    property real temperature: 0.0

    // onTemperatureChanged fires automatically — you never declared
    // this signal, it's synthesized by the language for every property
    onTemperatureChanged: {
        if (temperature > 80) console.log("ALERT:", deviceId, temperature);
    }
}
```

This is a real, consequential simplification compared to Day 12's C++ `Q_PROPERTY` boilerplate — QML's property system assumes "everything is observable" as the default, rather than something you opt into per-property.

### Why This Matters

- **Files-as-components with zero registration ceremony** is a genuine productivity difference from Widgets — worth not overthinking; if you're looking for a "where do I register this component" step, there usually isn't one for same-directory reuse.
- **Signal translation (button's `clicked` → card's `detailsRequested`) is the identical architectural pattern from Day 11**, just with QML's lighter syntax — the reasoning (don't expose internal children, translate to a semantically meaningful signal) hasn't changed at all, only the code shape.
- **Automatic `onXChanged` handlers for every property** removes an entire category of Day 12-style boilerplate (`Q_PROPERTY` + manual `NOTIFY` signal) — QML assumes observability by default, C++/Qt's older property system required opting in.
- **JS functions used inside bindings still participate in the dependency-tracking system** — this blurs the line between "function call" and "binding" in a way that has no real Widgets equivalent, and is worth understanding precisely rather than by vague analogy.

### Exercise

1. Add a `Q_PROPERTY`-equivalent computed property to `DeviceCard.qml`: `readonly property bool isAlerting: temperature > 80` — bind the card's `border.color` to it (red when alerting, otherwise the normal border color), and confirm it updates live purely from `temperature` changing, no explicit handler needed.
2. Add a second custom signal, `thresholdChanged(real newThreshold)`, plus a `property real alertThreshold: 80.0` that the "ALERT" logic reads instead of a hardcoded `80`, and wire a `Slider` in `main.qml` that adjusts a card's `alertThreshold` live.
3. Deliberately put a `console.log()` side effect inside `statusText()` (today's JS function used in a binding) and click the card's Details button repeatedly while watching the console — observe exactly how often the binding actually re-evaluates versus how often you might have assumed, to build accurate intuition about binding re-evaluation frequency before Day 7's performance day makes this a real design concern.

### Key Takeaways

- Any `.qml` file is automatically a reusable component named after its filename — no class declaration or registration step required for same-directory use.
- Signal translation (internal control's signal → the component's own semantically named signal) is the same pattern as Day 11's composite widgets, just lighter syntactically.
- Every `signal foo(...)` automatically gets an `onFoo` handler property — one uniform rule covering both custom and built-in signals.
- Every property automatically gets a synthesized change signal (`onXChanged`) — QML assumes observability by default, unlike C++'s opt-in `Q_PROPERTY NOTIFY`.
- JS functions called from within a binding still participate in dependency tracking and re-evaluate on the same terms as a plain expression — understand this precisely, since it will matter for performance reasoning later.

---

Say "next" for Day 4 (real models: `ListModel` vs. exposing a C++ `QAbstractListModel` to QML — the actual production pattern for your device table, replacing today's hardcoded `["device-01", ...]` placeholder list).