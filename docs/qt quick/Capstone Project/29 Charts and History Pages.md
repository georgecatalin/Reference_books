[[Capstone Project]]
# Day 29 — Charts and History Pages

Two remaining major screens: `ChartsPage.qml` (live, real-time visualization) and `HistoryPage.qml` (query-driven, historical). These deliberately use different data patterns — live model-driven vs one-shot query-driven — reinforcing the distinction Day 17 drew between `QAbstractListModel` and `QVariantList` returns, now in genuine side-by-side use.

## Concept: `ChartsPage.qml` — live multi-device telemetry, Day 20 assembled properly

```qml
// pages/ChartsPage.qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts
import QtCharts
import MonitorApp

Item {
    id: root

    property string selectedDeviceId: MqttManager.devices.rowCount() > 0
        ? MqttManager.devices.data(MqttManager.devices.index(0, 0), 257) // DeviceIdRole
        : ""

    ColumnLayout {
        anchors.fill: parent
        anchors.margins: 16
        spacing: 12

        ComboBox {
            id: deviceSelector
            Layout.fillWidth: true
            model: MqttManager.devices
            textRole: "deviceId"
            onActivated: root.selectedDeviceId = currentText
        }

        ChartView {
            Layout.fillWidth: true
            Layout.fillHeight: true
            theme: ChartView.ChartThemeDark
            antialiasing: true
            legend.visible: true

            ValueAxis {
                id: axisY
                min: -20
                max: 60
                titleText: qsTr("Temperature (°C)")
            }
            DateTimeAxis {
                id: axisX
                format: "hh:mm:ss"
                titleText: qsTr("Time")
            }

            LineSeries {
                id: tempSeries
                name: root.selectedDeviceId
                axisX: axisX
                axisY: axisY
            }

            Connections {
                target: MqttManager
                function onTelemetryReceived(deviceId, epochSeconds, temperature) {
                    if (deviceId === root.selectedDeviceId)
                        ChartDataManager.appendTemperaturePoint(tempSeries, epochSeconds, temperature)
                }
            }

            // Clear and reload recent history when switching devices, so the
            // chart isn't empty right after a selection change
            Connections {
                target: root
                function onSelectedDeviceIdChanged() {
                    tempSeries.clear()
                    let recent = DatabaseManager.fetchDeviceHistory(root.selectedDeviceId, 100)
                    for (let i = 0; i < recent.length; i++)
                        ChartDataManager.appendTemperaturePoint(
                            tempSeries, recent[i].timestamp, recent[i].temperature
                        )
                }
            }
        }
    }
}
```

**Two things worth flagging deliberately.** First, `ComboBox { model: MqttManager.devices; textRole: "deviceId" }` — binding a `ComboBox` directly to your live `DeviceListModel` and pointing `textRole` at the `deviceId` role name (Day 14's `roleNames()` mapping) means the device selector dropdown updates automatically as devices join/leave, with zero manual sync code — another instance of one model feeding yet another view type, the pattern from Day 20 now paying off a third time.

Second, **on device switch, the chart clears and reloads from `DatabaseManager.fetchDeviceHistory`** (Day 17) before live points continue arriving — this is the deliberate seam between historical and live data: SQLite gives you the "already happened" context, live MQTT continues it forward. Getting this handoff right (no gap, no duplicate points at the boundary) is a real detail — note that if a live point arrives for the _new_ timestamp range while the history fetch is still in flight (unlikely given Day 15/17's synchronous local query, but worth the awareness), you'd want to guard against double-inserting the same point; for local SQLite queries this race is negligible, but it's the same category of concern you'd take seriously if `fetchDeviceHistory` were ever swapped for a network call.

## Concept: `HistoryPage.qml` — query-driven, `QVariantList`/`modelData` pattern

```qml
// pages/HistoryPage.qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts
import MonitorApp

Item {
    id: root
    property string selectedDeviceId: ""
    property var historyRows: []

    ColumnLayout {
        anchors.fill: parent
        anchors.margins: 16
        spacing: 12

        RowLayout {
            Layout.fillWidth: true
            ComboBox {
                id: deviceSelector
                Layout.fillWidth: true
                model: MqttManager.devices
                textRole: "deviceId"
                onActivated: {
                    root.selectedDeviceId = currentText
                    root.historyRows = DatabaseManager.fetchDeviceHistory(root.selectedDeviceId, 200)
                }
            }
            SpinBox {
                id: limitSpin
                from: 10; to: 1000; value: 200; stepSize: 10
                editable: true
            }
            Button {
                text: qsTr("Load")
                enabled: root.selectedDeviceId.length > 0
                onClicked: root.historyRows = DatabaseManager.fetchDeviceHistory(
                    root.selectedDeviceId, limitSpin.value
                )
            }
        }

        Label {
            visible: root.historyRows.length === 0
            text: qsTr("No history loaded. Select a device above.")
            color: Theme.textSecondary
        }

        ListView {
            Layout.fillWidth: true
            Layout.fillHeight: true
            clip: true
            visible: root.historyRows.length > 0
            model: root.historyRows

            delegate: Rectangle {
                width: ListView.view.width
                height: 36
                color: index % 2 === 0 ? Theme.background : Theme.surface

                RowLayout {
                    anchors.fill: parent
                    anchors.margins: 8

                    Label {
                        text: Qt.formatDateTime(new Date(modelData.timestamp * 1000), "dd.MM.yyyy hh:mm:ss")
                        color: Theme.textSecondary
                        Layout.preferredWidth: 160
                    }
                    Label {
                        text: modelData.temperature + "°C"
                        color: Theme.textPrimary
                        Layout.fillWidth: true
                    }
                    Label {
                        text: modelData.rssi + " dBm"
                        color: Theme.textSecondary
                    }
                }
            }
        }
    }
}
```

**`new Date(modelData.timestamp * 1000)`** — same milliseconds gotcha from Day 20's `DateTimeAxis`, now hitting JS's native `Date` constructor instead of Qt Charts' axis: your SQLite `timestamp` column stores Unix epoch _seconds_ (Day 17's `insertTelemetryReading`), and JS's `Date` constructor expects _milliseconds_ — the exact same unit mismatch, appearing in a second, unrelated place. Worth explicitly noting as a pattern: **anywhere epoch time crosses from your storage/C++ layer into a JS-facing API (Charts axis, `Date` constructor, anything else), check units** — this recurring gotcha across two separate days is the kind of thing worth a one-line comment convention in your own code (`// epoch SECONDS — multiply by 1000 for JS/Qt Charts APIs`) rather than re-discovering it a third time somewhere else.

**`root.historyRows = DatabaseManager.fetchDeviceHistory(...)`** — reassignment (not mutation) of a `var`-typed property, exactly Day 13's array lesson (`dataPoints = points`, not `.push()`) — the same reactivity rule, now governing whether your History page's `ListView` actually refreshes after a query, rather than a sparkline's repaint.

## Exercise

1. Build both pages exactly as above. Confirm `ChartsPage` seamlessly transitions from historical (SQLite) points to live (MQTT) points when you switch to a device that's actively publishing — watch for any visible gap or duplicate point at the seam.
2. Confirm `HistoryPage`'s device-switch reload and the "Load" button with a custom row limit (`SpinBox`) both correctly reassign `historyRows` (not attempt in-place mutation) — deliberately break it by trying `root.historyRows.push(...)` somewhere and confirm the list silently fails to update, one more concrete instance of the reassignment rule.
3. Add a `qsTr()`-wrapped empty state message (already included above) and confirm it appears/disappears correctly based on `historyRows.length` — this ties Day 24's localization habit directly into a real screen rather than a standalone label.
4. Deliberately request `fetchDeviceHistory` for a `deviceId` with no historical rows (a brand-new device that's never had a SQLite write) and confirm the empty state displays cleanly rather than an error or blank confusion.

## Key takeaways

- `ComboBox` bound directly to `DeviceListModel` via `textRole` gives you a live-updating device selector with zero manual sync — the third distinct view type (list, chart legend, dropdown) fed by the same one model.
- The historical→live data handoff (SQLite seed, then MQTT continuation) is a real seam worth being deliberate about — clear and reload on switch, watch for gap/duplication at the boundary.
- The epoch-seconds-vs-milliseconds gotcha recurs at every JS-facing time API boundary (Qt Charts axis, JS `Date`) — worth a standing code convention/comment, not a one-off fix.
- `QVariantList`/`modelData`-based pages (History) and `QAbstractListModel`/named-role pages (Devices, Charts) coexist naturally in the same app — use each where it fits, per Day 17's guidance, without forcing one pattern everywhere.
- Reassignment-not-mutation for `var`-typed properties governs reactivity everywhere a JS array/object is the property type — Day 13's sparkline lesson and today's History page are the same underlying rule in two different screens.

