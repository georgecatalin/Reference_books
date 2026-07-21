[[Foundations]]

# Day 5 — Signals, Handlers, and Where JavaScript in QML Should Stop

You've written `onClicked`, `onTextChanged`, `onCheckedChanged` for four days without a formal explanation of what a signal actually is or how to define your own. Today: the signal/slot model in QML terms, custom signals, and — just as important — where inline JavaScript stops being convenient glue and starts being a maintenance liability.

## Concept: Signals are QML's event system — and you already know the C++ version

You know Qt's C++ signal/slot mechanism from your systems background. QML signals are the same underlying concept, exposed declaratively: a **signal** is an event announcement; a **handler** (`onSignalName`) is code that runs when it fires. Every signal automatically gets a matching `on<SignalName>` handler with the first letter capitalized: `clicked` → `onClicked`, `valueChanged` → `onValueChanged`.

**Defining your own signal:**

```qml
Item {
    id: deviceCard
    signal deviceSelected(string deviceId, int rssi)

    MouseArea {
        anchors.fill: parent
        onClicked: deviceCard.deviceSelected("esp32-04", -67)
    }
}
```

Anyone using this component can now write `onDeviceSelected: (id, rssi) => { ... }` — this is how you build components that _report events upward_ to a parent, rather than reaching into their internals. This is the correct pattern for `mqtt_monitor`'s device list: each device row emits `deviceSelected`, and the parent view decides what to do (open detail panel, highlight on map, whatever) — the row itself doesn't know or care.

## Concept: Property change signals are automatic

Every `property` you declare automatically gets a `<name>Changed` signal — this is _why_ `onTextChanged` exists on `TextField.text` without anyone writing it explicitly. You get this for free on your own custom properties too:

```qml
Item {
    property bool connected: false
    onConnectedChanged: console.log("Connection state is now:", connected)
}
```

This is genuinely important for debugging: if a value changes unexpectedly and you don't know why, adding a temporary `on<Prop>Changed: console.log(...)` handler is the fastest way to find _where_ — set a breakpoint mentality, QML-style.

## Concept: JS functions in QML — legitimate uses

Small, pure transformations belong inline:

```qml
function formatUptime(seconds) {
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    return h + "h " + m + "m"
}

Label { text: formatUptime(root.uptimeSeconds) }
```

This is fine — it's a pure display-formatting function with no side effects, no I/O, no state mutation beyond its own scope. Use JS freely for this category: formatting, simple math, string building, filtering a small local array.

## Concept: Where JS in QML becomes a code smell

Watch for these patterns — each is a signal you're doing application logic in the wrong layer:

**1. Manual HTTP/network calls inside QML**

```qml
// Don't do this in QML — it's application logic, not UI logic
function fetchDevices() {
    let xhr = new XMLHttpRequest()
    xhr.open("GET", "http://localhost:8000/devices")
    xhr.onreadystatechange = function() { /* ... */ }
    xhr.send()
}
```

This _works_, but it means your networking logic — retries, auth headers, error handling, timeouts — lives in a UI file with no unit test story and no access to your existing C++ MQTT/serial infrastructure. This belongs in C++ (Day 15/16), exposed to QML as a clean property/signal interface.

**2. Business logic / validation rules of any complexity** A one-line `enabled: port > 0 && port < 65536` is fine. A 30-line function computing whether a device reading is "critical" based on multiple thresholds, hysteresis, and historical averages is not — that's domain logic, put it in C++ where it's testable with GoogleTest, not buried in a `.qml` file with no test harness.

**3. Anything holding state beyond simple UI flags** A JS `var` array accumulating telemetry history inside QML is a trap — QML JS objects aren't reactive collections, don't integrate with `ListView` efficiently, and you lose all the model/view machinery (Day 6). Real data belongs in a proper model, ideally backed by C++ (Day 14) once it's non-trivial.

**Rule of thumb you can apply immediately:** if a JS function in your `.qml` file does I/O, holds meaningful state, or would benefit from a unit test — it's misplaced. Move it to C++, expose only the _result_ to QML.

## Annotated example: a device row using custom signals + a _legitimate_ small JS helper

```qml
import QtQuick
import QtQuick.Controls

Rectangle {
    id: root
    width: parent ? parent.width : 300
    height: 56
    color: mouseArea.containsMouse ? "#313244" : "#1e1e2e"

    property string deviceId: "esp32-04"
    property int rssi: -67
    property int uptimeSeconds: 16320

    signal deviceSelected(string deviceId)

    // Legitimate: pure, tiny, display-only — no I/O, no persistent state
    function formatUptime(seconds) {
        const h = Math.floor(seconds / 3600)
        const m = Math.floor((seconds % 3600) / 60)
        return h + "h " + m + "m"
    }

    function signalQuality(rssi) {
        if (rssi > -60) return "Excellent"
        if (rssi > -75) return "Good"
        return "Weak"
    }

    Row {
        anchors.verticalCenter: parent.verticalCenter
        anchors.left: parent.left
        anchors.leftMargin: 12
        spacing: 16

        Label { text: root.deviceId; color: "#cdd6f4"; font.bold: true }
        Label { text: root.signalQuality(root.rssi) + " (" + root.rssi + " dBm)"; color: "#a6adc8" }
        Label { text: "Up " + root.formatUptime(root.uptimeSeconds); color: "#a6adc8" }
    }

    MouseArea {
        id: mouseArea
        anchors.fill: parent
        hoverEnabled: true
        onClicked: root.deviceSelected(root.deviceId)
    }
}
```

Used from a parent:

```qml
DeviceRow {
    onDeviceSelected: (id) => console.log("Selected device:", id)
}
```

Note `hoverEnabled: true` on the `MouseArea` — without it, `containsMouse` never updates; another easy-to-forget flag.

## Exercise

1. Add a custom signal `signal connectionLost(string deviceId, string reason)` to a component, emitted from a `Timer` (look up `Timer { interval; running; repeat; onTriggered }` briefly — you'll want it) simulating a dropped connection after 5 seconds. Handle it in the parent by changing a status label.
2. Take the `signalQuality` function and deliberately make it "too complex" — add a fake weighted score combining RSSI, packet loss %, and uptime into a health category. Then, in a comment, describe (don't implement yet — that's Day 9+) how you'd move this to C++ and expose only `healthCategory` as a `Q_PROPERTY`.
3. Add an `onRssiChanged` handler that logs to console — trigger it by hooking a `Slider` to `rssi` temporarily, and confirm the automatic change-signal fires as expected.

## Key takeaways

- Every signal gets an automatic `on<Signal>` handler; every property gets an automatic `on<Prop>Changed` signal — you can lean on the latter for debugging.
- Custom signals (`signal foo(...)`) are how child components report events upward without the parent reaching into their internals — this is the correct pattern for list rows, cards, and reusable widgets.
- JS in QML is fine for pure, tiny, display-only transforms. It's a smell the moment it does I/O, holds real state, or contains logic complex enough to want a unit test — that logic belongs in C++.
- `MouseArea.hoverEnabled` must be explicitly set to `true` for `containsMouse` to work.

Say next for Day 6 — Models and Views (`ListModel`, `ListView`, delegates) — where your hardcoded JS arrays from Day 4 finally become real, scalable data structures.