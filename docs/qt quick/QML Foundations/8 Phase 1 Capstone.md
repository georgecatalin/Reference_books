[[Foundations]]

# Day 8 — Phase 1 Capstone: Integrated Device Monitor Panel

No new concepts today. This is a review day — the point is proving you can combine Days 1–7 into one cohesive, non-trivial screen without guidance, the same way your C++/Python courses had integration checkpoints before moving into harder material. I'll give you the full assembled reference build; use it to check your own work, not as something to copy first.

## What we're integrating

- Day 2: property-driven header with alias-based reusable component
- Day 3: Controls-based settings form
- Day 4: `GridLayout` + `Repeater`/model-driven telemetry grid, `TabBar`/`StackLayout` navigation
- Day 5: custom signals bubbling events upward from child components
- Day 6: `ListModel`/`ListView` for the device list, runtime mutation
- Day 7: `State`/`Transition`/`Behavior` for connection status and live value easing

## The brief

Build a single-window app with three tabs:

1. **Overview** — connection status header + a small telemetry grid
2. **Devices** — a `ListView` of devices, each row emitting a `deviceSelected` signal, clicking toggles online/offline with an animated color change
3. **Settings** — the Day 3 broker connection form

This mirrors the real shape of `mqtt_monitor`'s eventual GUI, minus the actual C++/MQTT backend (that starts Day 9).

## Reference build

**`main.qml`**

```qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts

ApplicationWindow {
    id: window
    width: 640
    height: 480
    visible: true
    title: "mqtt_monitor"
    color: "#181825"

    ListModel {
        id: deviceModel
        Component.onCompleted: {
            append({ deviceId: "esp32-04", rssi: -67, online: true })
            append({ deviceId: "rpi-monitor-01", rssi: -54, online: true })
            append({ deviceId: "beaglebone-sensor", rssi: -81, online: false })
        }
    }

    property bool brokerConnected: deviceModel.count > 0
        && (function() {
            for (let i = 0; i < deviceModel.count; i++)
                if (deviceModel.get(i).online) return true
            return false
        })()

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

        // ---- Overview ----
        ColumnLayout {
            spacing: 16

            ConnectionStatusHeader {
                Layout.fillWidth: true
                connected: window.brokerConnected
                deviceCount: deviceModel.count
            }

            GridLayout {
                Layout.fillWidth: true
                Layout.fillHeight: true
                columns: Math.max(1, Math.floor(width / 160))
                rowSpacing: 12
                columnSpacing: 12

                Repeater {
                    model: ListModel {
                        ListElement { label: "Avg RSSI"; value: "-67 dBm" }
                        ListElement { label: "Online"; value: "2 / 3" }
                        ListElement { label: "Uptime"; value: "4d 12h" }
                    }
                    delegate: Rectangle {
                        Layout.fillWidth: true
                        Layout.minimumHeight: 70
                        radius: 6
                        color: "#313244"
                        ColumnLayout {
                            anchors.centerIn: parent
                            Label { text: label; color: "#a6adc8"; font.pixelSize: 12; Layout.alignment: Qt.AlignHCenter }
                            Label { text: value; color: "#cdd6f4"; font.pixelSize: 18; font.bold: true; Layout.alignment: Qt.AlignHCenter }
                        }
                    }
                }
            }
        }

        // ---- Devices ----
        ListView {
            model: deviceModel
            spacing: 4
            clip: true

            delegate: DeviceRow {
                width: ListView.view.width
                deviceId: model.deviceId
                rssi: model.rssi
                online: model.online
                onDeviceSelected: (id) => deviceModel.setProperty(index, "online", !model.online)
            }
        }

        // ---- Settings ----
        BrokerSettingsForm {
            id: settingsForm
        }
    }
}
```

**`ConnectionStatusHeader.qml`** (Day 2 pattern, filename-as-component-name)

```qml
import QtQuick
import QtQuick.Layouts

Item {
    id: root
    height: 50
    property bool connected: false
    property int deviceCount: 0

    Rectangle {
        id: dot
        width: 14; height: 14; radius: 7
        anchors.left: parent.left
        anchors.verticalCenter: parent.verticalCenter
        color: root.connected ? "#a6e3a1" : "#f38ba8"
        Behavior on color { ColorAnimation { duration: 300 } }
    }

    Label {
        anchors.left: dot.right
        anchors.leftMargin: 8
        anchors.verticalCenter: parent.verticalCenter
        text: (root.connected ? "Connected" : "Disconnected") + " · " + root.deviceCount + " devices"
        color: "#cdd6f4"
        font.pixelSize: 15
    }
}
```

**`DeviceRow.qml`** (Days 5–7: custom signal + `Behavior`-eased color)

```qml
import QtQuick
import QtQuick.Controls

Rectangle {
    id: root
    height: 48
    radius: 4
    color: online ? "#313244" : "#1e1e2e"
    Behavior on color { ColorAnimation { duration: 250 } }

    property string deviceId: ""
    property int rssi: 0
    property bool online: false

    signal deviceSelected(string deviceId)

    Row {
        anchors.verticalCenter: parent.verticalCenter
        anchors.left: parent.left
        anchors.leftMargin: 12
        spacing: 16

        Rectangle {
            width: 10; height: 10; radius: 5
            anchors.verticalCenter: parent.verticalCenter
            color: root.online ? "#a6e3a1" : "#f38ba8"
            Behavior on color { ColorAnimation { duration: 250 } }
        }
        Label { text: root.deviceId; color: "#cdd6f4"; font.bold: true }
        Label { text: root.rssi + " dBm"; color: "#a6adc8" }
    }

    MouseArea {
        anchors.fill: parent
        onClicked: root.deviceSelected(root.deviceId)
    }
}
```

**`BrokerSettingsForm.qml`** — Day 3's form, lightly trimmed; reuse what you built.

## Deliberate design notes worth studying

- **`window.brokerConnected`** uses an IIFE inside a property binding to scan the model — this _works_ but is a small preview of Day 5's warning: this is exactly the kind of logic that should move to C++ once it's doing anything more than a toy scan. Flag it in your own build as a "Day 9+ candidate for backend logic," don't let it grow.
- **`DeviceRow.onDeviceSelected`** toggles `online` in the parent's model, not inside `DeviceRow` itself — the row _reports_, the parent _decides_. This is the Day 5 pattern paying off structurally.
- **Two `Behavior on color`** (dot and row background) both animate independently and correctly — no coordination needed, because each is a local reactive rule, not a manually orchestrated sequence.

## Your task

Build this yourself, from your own Day 1–7 files, without copying the reference wholesale — then diff your instincts against the reference above. Specifically check:

1. Does your `StackLayout` correctly preserve the Devices tab's list state (scroll position, toggled online/offline states) when you switch to Settings and back? (It should — that's what `StackLayout` guarantees over `Loader`.)
2. Did you remember `clip: true` on the `ListView`?
3. Does your device row's color transition smoothly, or does it jump? (If it jumps, you forgot the `Behavior`.)
4. Try breaking your own `brokerConnected` logic by making all devices offline — does the header update automatically? If not, you have a broken binding somewhere, not a signal-wiring problem — that's the debugging instinct this phase should have built.

## Key takeaways for Phase 1 as a whole

You now have the full declarative UI toolkit: properties/bindings (the core mental shift), Controls (interactive widgets), Layouts (reflowing arrangement), Models/Views/Delegates (data-driven rendering), and States/Transitions/Behaviors (polish). Everything from Day 9 onward is about connecting this UI layer to **real C++ logic and data** — your actual `mqtt_monitor` backend — instead of hardcoded `ListModel` placeholders and toy bindings.

Say next when you're ready for Day 9 — Phase 2 begins: exposing real C++ objects to QML via `Q_PROPERTY` and `Q_INVOKABLE`, which is where this stops being a UI toy and starts being your actual application.