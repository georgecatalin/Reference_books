# Qt Quick / QML — Exercise Solutions, Days 1–8 (Phase 1: Foundations)

---

## Day 1 — First App

**Exercises:** status LED, bind color to `deviceOnline`, toggle via binding not imperative assignment.

```qml
// main.qml
import QtQuick
import QtQuick.Window

Window {
    id: root
    width: 640
    height: 480
    visible: true
    title: "mqtt_monitor — Day 1"

    property bool deviceOnline: true

    Rectangle {
        anchors.fill: parent
        color: "#1e1e2e"

        Text {
            id: label
            anchors.centerIn: parent
            // Exercise 3: text is a BINDING derived from deviceOnline, not set in onClicked
            text: root.deviceOnline ? "Device Monitor Online" : "Device Monitor Offline"
            color: "#cdd6f4"
            font.pixelSize: 24
        }

        // Exercise 1 & 2: status LED, color bound to deviceOnline
        Rectangle {
            id: statusLed
            width: 16
            height: 16
            radius: width / 2
            anchors.top: parent.top
            anchors.right: parent.right
            anchors.margins: 12
            color: root.deviceOnline ? "#a6e3a1" : "#f38ba8"
        }

        MouseArea {
            anchors.fill: parent
            onClicked: root.deviceOnline = !root.deviceOnline   // toggles state; label/LED update via binding
        }
    }
}
```

---

## Day 2 — Properties, Object Tree, Anchors

**Exercises:** standalone reusable component, `property alias`, anchor-conflict test, constructor-style properties.

```qml
// DeviceStatusHeader.qml
import QtQuick

Item {
    id: root
    width: 400
    height: 60

    // ---- Public API ----
    property bool connected: true
    property int deviceCount: 0
    property string lastUpdate: ""
    property alias dotColor: statusDot.color   // exercise 2: alias forwarding

    Rectangle {
        anchors.fill: parent
        color: "#181825"
    }

    Rectangle {
        id: statusDot
        width: 14
        height: 14
        radius: width / 2
        color: root.connected ? "#a6e3a1" : "#f38ba8"
        anchors.left: parent.left
        anchors.leftMargin: 12
        anchors.verticalCenter: parent.verticalCenter

        // Exercise 3 — deliberate anchor conflict (uncomment to observe):
        // width: 100
        // anchors.right: parent.right
        // Result: the explicit width: 100 is SILENTLY IGNORED. Because both
        // anchors.left and anchors.right are set, the anchors control width
        // entirely — the item stretches to fill the horizontal span between
        // them regardless of the width: property. This confirms Day 2's rule:
        // never mix an explicit width/height with two opposing anchors.
    }

    Text {
        id: statusLabel
        anchors.left: statusDot.right
        anchors.leftMargin: 8
        anchors.verticalCenter: parent.verticalCenter
        text: root.connected ? "Connected" : "Disconnected"
        color: "#cdd6f4"
        font.pixelSize: 16
    }

    Text {
        anchors.right: parent.right
        anchors.rightMargin: 12
        anchors.verticalCenter: parent.verticalCenter
        text: root.deviceCount + " devices · updated " + root.lastUpdate
        color: "#a6adc8"
        font.pixelSize: 13
    }
}
```

```qml
// main.qml — exercise 4: two independent instances, proving separate state
import QtQuick

Item {
    width: 420; height: 160

    DeviceStatusHeader {
        y: 0
        deviceCount: 5
        lastUpdate: "12:00:00"
        dotColor: "orange"        // overridden independently via alias
    }

    DeviceStatusHeader {
        y: 70
        connected: false
        deviceCount: 2
        lastUpdate: "12:05:00"
        // dotColor left at default — proves the first instance's override
        // did not leak into this one
    }
}
```

---

## Day 3 — Qt Quick Controls 2

**Exercises:** QoS `ComboBox`, port validation, `alias` replacing manual sync, custom `Button.background`.

```qml
// BrokerSettingsForm.qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts

Item {
    id: root
    width: 380
    height: 380

    // Exercise 3: alias directly replaces the manual onTextChanged: root.x = text wiring
    property alias brokerHost: hostField.text
    property int brokerPort: 1883
    property bool useTls: false
    property int qosLevel: 0
    property string statusMessage: ""

    readonly property bool portValid: brokerPort >= 1 && brokerPort <= 65535

    Rectangle { anchors.fill: parent; color: "#181825" }

    ColumnLayout {
        anchors.fill: parent
        anchors.margins: 16
        spacing: 12

        Label { text: "MQTT Broker Settings"; font.pixelSize: 18; font.bold: true; color: "#cdd6f4" }

        RowLayout {
            Layout.fillWidth: true
            Label { text: "Host:"; color: "#a6adc8"; Layout.preferredWidth: 60 }
            TextField {
                id: hostField
                Layout.fillWidth: true
                placeholderText: "broker address"
                text: "localhost"
                // no onTextChanged handler needed — the alias above keeps
                // root.brokerHost in sync automatically
            }
        }

        RowLayout {
            Layout.fillWidth: true
            Label { text: "Port:"; color: "#a6adc8"; Layout.preferredWidth: 60 }
            SpinBox {
                id: portSpin
                from: 1; to: 65535
                value: root.brokerPort
                editable: true
                onValueChanged: root.brokerPort = value
            }
        }

        // Exercise 2: validation feedback via binding, not inside onClicked
        Label {
            text: "Port must be between 1 and 65535"
            color: "#f38ba8"
            visible: !root.portValid
        }

        // Exercise 1: QoS ComboBox
        RowLayout {
            Label { text: "QoS:"; color: "#a6adc8"; Layout.preferredWidth: 60 }
            ComboBox {
                id: qosCombo
                model: [0, 1, 2]
                currentIndex: root.qosLevel
                onActivated: root.qosLevel = currentIndex
            }
        }

        CheckBox {
            id: tlsCheck
            text: "Use TLS"
            checked: root.useTls
            onCheckedChanged: root.useTls = checked
        }

        // Exercise 4: custom background styling
        Button {
            id: connectButton
            text: "Connect"
            Layout.fillWidth: true
            enabled: hostField.text.length > 0 && root.portValid
            onClicked: root.statusMessage = "Connecting to " + root.brokerHost + ":" + root.brokerPort
                + " QoS " + root.qosLevel + (root.useTls ? " (TLS)" : "")

            background: Rectangle {
                radius: 6
                color: connectButton.pressed ? "#45475a"
                     : connectButton.hovered ? "#313244"
                     : "#1e1e2e"
                border.color: "#89b4fa"
                border.width: connectButton.enabled ? 1 : 0
                Behavior on color { ColorAnimation { duration: 150 } }
            }
        }

        Label { text: root.statusMessage; color: "#89b4fa"; wrapMode: Text.WordWrap; Layout.fillWidth: true }
    }
}
```

---

## Day 4 — Layouts

**Exercises:** responsive `columns`, 3-tab `TabBar`+`StackLayout`, anchors-on-layout-child test.

```qml
// main.qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts

ApplicationWindow {
    width: 600; height: 450; visible: true

    header: TabBar {
        id: tabBar
        TabButton { text: "Overview" }
        TabButton { text: "Devices" }
        TabButton { text: "Settings" }
    }

    StackLayout {
        anchors.fill: parent
        anchors.margins: 16
        currentIndex: tabBar.currentIndex

        // ---- Overview: responsive telemetry grid (exercise 1) ----
        GridLayout {
            id: telemetryGrid
            columns: Math.max(1, Math.floor(width / 160))   // reflows as the window resizes
            rowSpacing: 12
            columnSpacing: 12

            Repeater {
                model: ListModel {
                    ListElement { label: "Temperature"; value: "42.3°C" }
                    ListElement { label: "Humidity"; value: "58%" }
                    ListElement { label: "Voltage"; value: "3.31V" }
                    ListElement { label: "Signal"; value: "-67 dBm" }
                }
                delegate: Rectangle {
                    Layout.fillWidth: true
                    Layout.minimumHeight: 70
                    radius: 6
                    color: "#313244"

                    // Exercise 3 — deliberately mixed anchor on a layout child:
                    // anchors.left: parent.left
                    // Result: has NO effect. GridLayout fully controls this
                    // item's position; anchors are ignored on layout children.

                    ColumnLayout {
                        anchors.centerIn: parent
                        Label { text: label; color: "#a6adc8"; font.pixelSize: 12; Layout.alignment: Qt.AlignHCenter }
                        Label { text: value; color: "#cdd6f4"; font.pixelSize: 18; font.bold: true; Layout.alignment: Qt.AlignHCenter }
                    }
                }
            }
        }

        // ---- Devices tab (exercise 2) ----
        Loader { source: "BrokerSettingsForm.qml" }

        // ---- Settings tab ----
        Loader { source: "BrokerSettingsForm.qml" }
    }
}
```

---

## Day 5 — Signals and Handlers

**Exercises:** `connectionLost` via `Timer`, deliberately-too-complex `signalQuality`, `onRssiChanged` logging via `Slider`.

```qml
// DeviceRow.qml (extended)
import QtQuick
import QtQuick.Controls

Rectangle {
    id: root
    width: 320; height: 100
    color: "#1e1e2e"

    property string deviceId: "esp32-04"
    property int rssi: -60
    property real packetLossPct: 2.0
    property int uptimeSeconds: 0

    signal deviceSelected(string deviceId)
    signal connectionLost(string deviceId, string reason)   // exercise 1

    Timer {
        interval: 5000
        running: true
        repeat: false
        onTriggered: root.connectionLost(root.deviceId, "no heartbeat received")
    }

    Connections {
        target: root
        function onConnectionLost(deviceId, reason) {
            statusLabel.text = "Lost: " + reason
        }
    }

    // Exercise 2 — deliberately "too complex" logic.
    // NOTE: in the real app this belongs in C++ as a Q_PROPERTY(QString healthCategory ...)
    // computed once and testable with GoogleTest — not recomputed ad hoc in QML JS.
    function healthCategory() {
        var score = 0
        score += rssi > -60 ? 40 : rssi > -75 ? 25 : 10
        score += packetLossPct < 1 ? 30 : packetLossPct < 5 ? 15 : 0
        score += uptimeSeconds > 3600 ? 30 : 15
        if (score >= 80) return "Excellent"
        if (score >= 50) return "Fair"
        return "Poor"
    }

    function formatUptime(seconds) {
        var h = Math.floor(seconds / 3600)
        var m = Math.floor((seconds % 3600) / 60)
        return h + "h " + m + "m"
    }

    function signalQuality(r) {
        if (r > -60) return "Excellent"
        if (r > -75) return "Good"
        return "Weak"
    }

    Column {
        anchors.left: parent.left; anchors.leftMargin: 12; anchors.verticalCenter: parent.verticalCenter
        Label { id: statusLabel; text: root.deviceId; color: "#cdd6f4" }
        Slider {
            id: rssiSlider
            from: -100; to: -30
            value: root.rssi
            onValueChanged: root.rssi = value   // exercise 3 trigger
        }
    }

    // Exercise 3 — automatic property-change signal, used for debugging
    onRssiChanged: console.log("rssi changed to:", rssi)
}
```

---

## Day 6 — Models and Views

**Exercises:** `GridView` + `ListModel` telemetry, `Timer`-driven mutation, `clip` removal test, substring filter.

```qml
// main.qml
import QtQuick
import QtQuick.Controls

Item {
    width: 500; height: 350

    ListModel {
        id: telemetryModel
        Component.onCompleted: {
            append({ label: "Temperature", value: 42.3 })
            append({ label: "Humidity", value: 58 })
            append({ label: "Voltage", value: 3.31 })
            append({ label: "Signal", value: -67 })
        }
    }

    TextField {
        id: filterField
        placeholderText: "Filter by label…"
        width: parent.width
    }

    GridView {
        id: grid
        anchors.top: filterField.bottom
        anchors.left: parent.left
        anchors.right: parent.right
        anchors.bottom: parent.bottom
        clip: true   // exercise 3: remove this and overflow bleeds visibly outside the grid
        cellWidth: 150; cellHeight: 100
        model: telemetryModel

        delegate: Rectangle {
            width: 140; height: 90
            radius: 6
            color: "#313244"
            // exercise 4 — simple visible: filter; fine at this scale (revisited Day 28)
            visible: filterField.text.length === 0
                || label.toLowerCase().indexOf(filterField.text.toLowerCase()) !== -1

            Column {
                anchors.centerIn: parent
                Label { text: label; color: "#a6adc8"; font.pixelSize: 12 }
                Label { text: value; color: "#cdd6f4"; font.pixelSize: 18; font.bold: true }
            }
        }
    }

    // Exercise 2 — simulated live updates via setProperty (the real MQTT pattern later)
    Timer {
        interval: 2000; running: true; repeat: true
        onTriggered: {
            if (telemetryModel.count === 0) return
            var idx = Math.floor(Math.random() * telemetryModel.count)
            var delta = (Math.random() * 10) - 5
            telemetryModel.setProperty(idx, "value", telemetryModel.get(idx).value + delta)
        }
    }
}
```

---

## Day 7 — States, Transitions, Animations

**Exercises:** `Behavior on color` for device row, animated signal-strength bar, genuine 3-state critical card with infinite pulse.

```qml
// DeviceRow.qml — exercise 1
Rectangle {
    id: root
    property bool online: true
    color: online ? "#313244" : "#1e1e2e"
    Behavior on color { ColorAnimation { duration: 250 } }
}
```

```qml
// SignalBar.qml — exercise 2
import QtQuick

Rectangle {
    id: bar
    property int rssi: -70
    width: Math.max(0, Math.min(100, rssi + 100))   // maps -100..0 dBm to 0..100 px
    height: 8
    radius: 2
    color: "#89b4fa"
    Behavior on width { NumberAnimation { duration: 250; easing.type: Easing.OutCubic } }
}
```

```qml
// CriticalCard.qml — exercise 3 & 4
import QtQuick

Rectangle {
    id: card
    width: 160; height: 90
    radius: 6

    property real temperature: 45

    state: temperature > 80 ? "critical" : temperature > 60 ? "warning" : "normal"

    states: [
        State { name: "normal";   PropertyChanges { target: card; color: "#313244"; scale: 1.0 } },
        State { name: "warning";  PropertyChanges { target: card; color: "#f9e2af"; scale: 1.03 } },
        State { name: "critical"; PropertyChanges { target: card; color: "#f38ba8"; scale: 1.05 } }
    ]

    transitions: Transition {
        ColorAnimation { duration: 300 }
        NumberAnimation { property: "scale"; duration: 200 }
    }

    SequentialAnimation {
        running: card.state === "critical"   // only pulses in the critical state
        loops: Animation.Infinite
        NumberAnimation { target: card; property: "opacity"; to: 0.5; duration: 400 }
        NumberAnimation { target: card; property: "opacity"; to: 1.0; duration: 400 }
    }

    Label {
        anchors.centerIn: parent
        text: card.temperature.toFixed(1) + "°C"
        color: "#cdd6f4"
    }
}
```

---

## Day 8 — Phase 1 Capstone (Integration)

Day 8's exercise is verification/integration of the reference build already provided in the lesson — no new isolated code beyond confirming the four checks. One useful verification snippet worth adding to catch the exact failure mode described:

```qml
// Deliberately breaking the reference build's brokerConnected binding to see the
// debugging instinct pay off — the fix is always "a broken binding", not signal wiring:
property bool brokerConnected: false   // <- if hardcoded like this instead of the
                                        //    IIFE scan over deviceModel, the header
                                        //    will NOT update when devices go offline —
                                        //    proving it's a binding problem, not a
                                        //    missing-signal problem.
```

Everything else for Day 8 is the fully assembled reference build already shown in the lesson (`main.qml`, `ConnectionStatusHeader.qml`, `DeviceRow.qml`, `BrokerSettingsForm.qml`) — build it from your own Day 1–7 files and diff against that reference.
