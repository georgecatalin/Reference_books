[[Advanced Qt Quick]]

# Day 23 — Multi-Window Apps, Dialogs, Popups, and Drawer Navigation

Everything so far lived in one `ApplicationWindow`. Real dashboard workflows need secondary windows (a detached device detail view, an alerts panel), modal dialogs (confirm a destructive action), transient popups (a quick device tooltip), and drawer-based navigation (common for dashboards with many sections). Today covers all four, with clear guidance on when each is the right tool.

## Concept: `Dialog` — modal, blocking user attention until resolved

```qml
import QtQuick.Controls

Dialog {
    id: confirmDisconnect
    title: "Disconnect Device"
    modal: true
    standardButtons: Dialog.Yes | Dialog.No
    anchors.centerIn: parent

    property string deviceId: ""

    Label {
        text: "Disconnect " + confirmDisconnect.deviceId + "? This will stop monitoring until it reconnects."
        wrapMode: Text.WordWrap
    }

    onAccepted: MqttManager.devices.removeDevice(deviceId)
    onRejected: console.log("Disconnect cancelled")
}
```

```qml
// Triggering it
Button {
    text: "Remove Device"
    onClicked: {
        confirmDisconnect.deviceId = model.deviceId
        confirmDisconnect.open()
    }
}
```

`modal: true` blocks interaction with the rest of the window until resolved — the correct choice for anything destructive or requiring an explicit decision before proceeding. `standardButtons` gives you `Dialog.Yes/No/Ok/Cancel/etc.` pre-wired to `accepted`/`rejected` signals — don't hand-build a row of `Button`s for a standard confirm/cancel pattern; it duplicates behavior Qt already provides correctly (keyboard focus, Escape-to-cancel, platform button ordering conventions).

## Concept: `Popup` — non-modal, dismissible, lighter weight

```qml
import QtQuick.Controls

Popup {
    id: deviceTooltip
    width: 200
    padding: 12
    closePolicy: Popup.CloseOnPressOutside

    property string deviceId: ""
    property int rssi: 0

    ColumnLayout {
        Label { text: deviceTooltip.deviceId; font.bold: true }
        Label { text: "Signal: " + deviceTooltip.rssi + " dBm" }
    }
}
```

```qml
MouseArea {
    anchors.fill: parent
    hoverEnabled: true
    onEntered: {
        deviceTooltip.deviceId = model.deviceId
        deviceTooltip.rssi = model.rssi
        deviceTooltip.x = /* position near cursor/row */
        deviceTooltip.open()
    }
    onExited: deviceTooltip.close()
}
```

`Popup` (not `Dialog`) is right when the interaction is informational or quick, and the user shouldn't be blocked from the rest of the UI — a device info tooltip, a quick filter panel, a notification toast. `closePolicy: Popup.CloseOnPressOutside` is the typical choice for this kind of lightweight, dismiss-by-clicking-away UX; `Dialog`'s modality is the wrong feel here — it would interrupt workflow for something that's meant to be glanceable.

## Concept: `Drawer` — slide-out navigation, the standard pattern for dashboards with many sections

Your Day 8 `TabBar` works fine for 3 tabs; it won't scale cleanly to 8–10 sections (Overview, Devices, Alerts, History, Charts, Settings, Logs, About). `Drawer` is the standard answer:

```qml
ApplicationWindow {
    id: window
    width: 800; height: 600; visible: true

    Drawer {
        id: navDrawer
        width: 220
        height: window.height
        edge: Qt.LeftEdge

        ColumnLayout {
            anchors.fill: parent
            anchors.margins: 8

            Repeater {
                model: ["Overview", "Devices", "Alerts", "History", "Charts", "Settings"]
                Button {
                    Layout.fillWidth: true
                    text: modelData
                    flat: true
                    onClicked: {
                        stackView.currentIndex = index   // or push a Page, see below
                        navDrawer.close()
                    }
                }
            }
        }
    }

    header: ToolBar {
        ToolButton {
            text: "☰"
            onClicked: navDrawer.open()
        }
        Label { text: "mqtt_monitor"; anchors.centerIn: parent }
    }

    // main content area
}
```

`edge: Qt.LeftEdge` (vs `Qt.RightEdge`/etc.) determines which screen edge it slides from — left is the near-universal convention for primary navigation, don't deviate without a specific reason. The hamburger `ToolButton` in a `header: ToolBar` opening it is the standard trigger pattern users already recognize from virtually every mobile and modern desktop app.

## Concept: `StackView` — proper page-based navigation (vs `StackLayout`'s fixed-tab model)

Day 4's `StackLayout` assumes a fixed, known set of always-available pages. `StackView` is for genuine **navigation** — pushing a new page on top (with a back button/history), like drilling into a specific device's detail view from the device list:

```qml
StackView {
    id: stackView
    anchors.fill: parent
    initialItem: deviceListPage
}

Component {
    id: deviceListPage
    ListView {
        model: MqttManager.devices
        delegate: DeviceRow {
            width: ListView.view.width
            deviceId: model.deviceId
            onDeviceSelected: (id) => stackView.push(deviceDetailPage, { deviceId: id })
        }
    }
}

Component {
    id: deviceDetailPage
    Item {
        property string deviceId: ""
        // detail view content, using deviceId
        Button {
            text: "Back"
            onClicked: stackView.pop()
        }
    }
}
```

`stackView.push(component, { propertyOverrides })` — the second argument sets initial property values on the pushed page, the clean way to pass data forward (analogous to a constructor argument) rather than reaching back into the pusher's scope. `pop()` returns to the previous page with its state intact (it wasn't destroyed, just hidden) — this is a genuine navigation stack, not a `Loader` swap, and is the right tool specifically for "drill into a device, then come back" workflows your dashboard will want.

## Concept: A genuine secondary `Window` — for detachable panels

```qml
Window {
    id: alertsWindow
    width: 400; height: 300
    title: "Active Alerts"
    visible: false

    ListView {
        anchors.fill: parent
        model: AlertsModel   // a separate model, e.g. from a C++ singleton
    }
}

// Elsewhere in main.qml
Button {
    text: "Open Alerts Window"
    onClicked: alertsWindow.visible = true
}
```

A real second OS-level window (not a `Popup`/`Dialog` inside the main window) — appropriate when you genuinely want a user to be able to move it to a second monitor, keep it open alongside the main window independently, or have it survive being layered behind other apps. For a Pi-based kiosk-style deployment this is less common (usually single fullscreen window), but relevant if your GUI targets a desktop workstation as an admin/monitoring tool rather than an embedded kiosk display.

## Exercise

1. Add a `Dialog`-based confirm for removing a device from your Day 16 live device list, replacing any bare "click to remove" behavior with a proper confirm step.
2. Add a hover `Popup` showing device detail (RSSI, last-seen, uptime) on your `DeviceRow` delegate — confirm it dismisses correctly on `CloseOnPressOutside` without interfering with the row's own click handling underneath.
3. Convert your Day 8 `TabBar`+`StackLayout` navigation to a `Drawer` + `header: ToolBar` hamburger pattern, and add 2–3 more placeholder sections (Alerts, History, Logs) to prove it scales past 3 tabs cleanly.
4. Build a `StackView`-based drill-down: device list → device detail page (via `push`, passing `deviceId`) → back button (`pop`). Confirm scrolling to a specific point in the list, drilling in, and popping back preserves your scroll position (it should — `StackView` doesn't destroy the previous page).

## Key takeaways

- `Dialog` (modal, blocks interaction) for confirmations/decisions; `Popup` (non-modal, dismissible) for glanceable/informational content — don't use a blocking `Dialog` for something meant to be quickly dismissed by clicking away.
- Use `standardButtons` on `Dialog` rather than hand-building confirm/cancel buttons — you get correct keyboard/platform behavior for free.
- `Drawer` + hamburger `ToolButton` is the standard pattern once you exceed ~4-5 sections a `TabBar` can reasonably show.
- `StackView` (push/pop, page history, state preserved) is for genuine drill-down navigation; `StackLayout` (Day 4) is for a small fixed set of always-available tabs; `Loader` (Day 22) is for on-demand construction of rarely-used content. Three related but distinct tools — pick based on whether you need history, fixed tabs, or lazy one-off loading.
- A real secondary `Window` is for genuinely detachable, independently-positioned panels — less relevant for a Pi kiosk deployment, more relevant for a desktop admin tool.

