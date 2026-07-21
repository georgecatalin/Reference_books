[[Capstone Project]]

# Day 28 — Building Real Pages: Overview and Devices

The skeleton from Day 27 runs and navigates. Today: replacing the placeholder stubs in `OverviewPage.qml` and `DeviceListPage.qml` with genuinely complete screens, pulling together the models, components, and visual polish from every earlier day into working, live-data pages.

## Concept: `OverviewPage.qml` — the at-a-glance summary screen

This page's job is answering "is everything okay?" in under two seconds of looking at it — a status header, a telemetry summary grid, and a compact device list preview, not the full device management UI (that's `DeviceListPage`'s job).

```qml
// pages/OverviewPage.qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts
import MonitorApp
import "../components"

Item {
    id: root

    ColumnLayout {
        anchors.fill: parent
        anchors.margins: 16
        spacing: 16

        // ---- Top summary tiles (Day 11's MetricTile, Day 14's model) ----
        GridLayout {
            Layout.fillWidth: true
            columns: Math.max(1, Math.floor(width / 160))
            rowSpacing: 12
            columnSpacing: 12

            MetricTile {
                Layout.fillWidth: true
                Layout.minimumHeight: 70
                label: qsTr("Devices Online")
                value: MqttManager.devices.onlineCount() + " / " + MqttManager.devices.rowCount()
            }
            MetricTile {
                Layout.fillWidth: true
                Layout.minimumHeight: 70
                label: qsTr("Connection")
                value: ConnectionStateMachine.currentState
                critical: ConnectionStateMachine.currentState === "failed"
            }
            MetricTile {
                Layout.fillWidth: true
                Layout.minimumHeight: 70
                label: qsTr("Avg Signal")
                value: MqttManager.devices.averageRssi() + " dBm"
            }
        }

        // ---- Compact device preview (Panel wrapper from Day 11) ----
        Panel {
            title: qsTr("Recent Activity")
            Layout.fillWidth: true
            Layout.fillHeight: true

            ListView {
                anchors.fill: parent
                clip: true
                model: MqttManager.devices
                spacing: 4

                delegate: DeviceRow {
                    width: ListView.view.width
                    deviceId: model.deviceId
                    rssi: model.rssi
                    online: model.online
                    onDeviceSelected: (id) => stackView.push(
                        "qrc:/qml/pages/DeviceDetailPage.qml", { deviceId: id }
                    )
                }
            }
        }
    }
}
```

**`MqttManager.devices.onlineCount()` and `averageRssi()`** — these are new `Q_INVOKABLE` methods you're adding to `DeviceListModel` today, not something from Day 14's original build. This is deliberate: aggregate summary stats (online count, average signal) are exactly the kind of small, real logic that belongs on the model itself (testable, single source of truth) rather than recomputed ad-hoc in QML by iterating the model from JS — the Day 5 lesson, applied to a genuinely new need that only became obvious once you were building a real summary page.

```cpp
// Added to DeviceListModel
Q_INVOKABLE int onlineCount() const
{
    int count = 0;
    for (const auto &d : m_devices)
        if (d.online) count++;
    return count;
}

Q_INVOKABLE double averageRssi() const
{
    if (m_devices.isEmpty()) return 0.0;
    double sum = 0.0;
    for (const auto &d : m_devices)
        sum += d.rssi;
    return sum / m_devices.count();
}
```

**Important reactivity note**: since these are `Q_INVOKABLE` (not `Q_PROPERTY`), QML bindings referencing them won't auto-update just because the model's data changed underneath — same gotcha as Day 9's exercise 3. Fix it the correct way here, not the piggyback workaround: connect the summary tiles' visibility/refresh to the model's own `dataChanged`/`rowsInserted`/`rowsRemoved` signals explicitly, or better, promote these to genuine `Q_PROPERTY`s with `NOTIFY` signals emitted from inside `addOrUpdateDevice`/`removeDevice` where the underlying counts actually change:

```cpp
// The more correct version — proper Q_PROPERTY
Q_PROPERTY(int onlineCount READ onlineCount NOTIFY onlineCountChanged)
Q_PROPERTY(double averageRssi READ averageRssi NOTIFY averageRssiChanged)

// In addOrUpdateDevice, after mutating m_devices:
emit onlineCountChanged();
emit averageRssiChanged();
```

Do this properly today — it's a real instance of the Day 9 lesson resurfacing in your actual capstone, not a hypothetical exercise anymore.

## Concept: `DeviceListPage.qml` — the full management view

```qml
// pages/DeviceListPage.qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts
import MonitorApp
import "../components"

Item {
    id: root

    ColumnLayout {
        anchors.fill: parent
        anchors.margins: 16
        spacing: 12

        RowLayout {
            Layout.fillWidth: true
            TextField {
                id: filterField
                Layout.fillWidth: true
                placeholderText: qsTr("Filter by device ID…")
            }
            Button {
                text: qsTr("Refresh")
                onClicked: MqttManager.requestDeviceRefresh()
            }
        }

        ListView {
            Layout.fillWidth: true
            Layout.fillHeight: true
            clip: true
            reuseItems: true   // Day 22 — long list, worth setting explicitly
            model: MqttManager.devices
            spacing: 4

            delegate: DeviceRow {
                width: ListView.view.width
                visible: filterField.text.length === 0
                    || deviceId.toLowerCase().includes(filterField.text.toLowerCase())
                deviceId: model.deviceId
                rssi: model.rssi
                online: model.online
                focus: true
                activeFocusOnTab: true   // Day 24 — keyboard accessible

                onDeviceSelected: (id) => stackView.push(
                    "qrc:/qml/pages/DeviceDetailPage.qml", { deviceId: id }
                )

                MouseArea {
                    anchors.fill: parent
                    acceptedButtons: Qt.RightButton
                    onClicked: removeDialog.open()
                }
                Dialog {
                    id: removeDialog
                    title: qsTr("Remove Device")
                    modal: true
                    standardButtons: Dialog.Yes | Dialog.No
                    anchors.centerIn: parent
                    Label { text: qsTr("Stop monitoring %1?").arg(deviceId); wrapMode: Text.WordWrap }
                    onAccepted: MqttManager.devices.removeDevice(deviceId)
                }
            }
        }
    }
}
```

**Notice the filter is a simple `visible:` binding (Day 6's exercise flagged this as "not ideal for large datasets")** — for a realistic device count (tens, not thousands), this is genuinely fine; don't reach for `QSortFilterProxyModel` prematurely for a dataset this size. This is a real judgment call worth making deliberately rather than defaulting to the "more sophisticated" tool — the simple approach is correct here, not merely acceptable.

**A right-click `MouseArea` layered on top of the delegate's own click handling** — `acceptedButtons: Qt.RightButton` scopes it to only intercept right-clicks, leaving the underlying `DeviceRow`'s own left-click `deviceSelected` signal (wired to the `MouseArea` inside `DeviceRow` itself) completely undisturbed. Two `MouseArea`s stacked, each scoped to different buttons, is a legitimate pattern — not a conflict, because they don't compete for the same input.

## Exercise

1. Build `OverviewPage.qml` and `DeviceListPage.qml` exactly as above, promoting `onlineCount`/`averageRssi` to proper `Q_PROPERTY`s with correct `NOTIFY` emission — confirm the Overview tiles update live as devices connect/disconnect, with zero manual refresh anywhere.
2. Add the `Q_INVOKABLE void requestDeviceRefresh()` stub to `MqttManager` (can just re-subscribe to the topic filter for now) and wire the Refresh button.
3. Test the filter field with a live device list: type a partial device ID, confirm only matching rows remain visible, confirm clearing the field restores the full list.
4. Right-click a device row, confirm the removal `Dialog` opens without triggering the row's own `deviceSelected` signal — proving the two scoped `MouseArea`s coexist correctly.

## Key takeaways

- `OverviewPage` answers "is everything okay" at a glance; `DeviceListPage` is full management — distinct page responsibilities, not one page trying to do both.
- Aggregate stats derived from a model's contents (`onlineCount`, `averageRssi`) belong as proper `Q_PROPERTY`s with `NOTIFY` emitted at the point of mutation — the Day 9 lesson, now resurfacing in real capstone code rather than a hypothetical.
- A simple `visible:` filter binding is the right tool for realistic (tens of rows) device counts — don't reach for `QSortFilterProxyModel` until you actually have a dataset size problem.
- Multiple `MouseArea`s scoped to different `acceptedButtons` coexist without conflict — a legitimate layering pattern, not a hack.

