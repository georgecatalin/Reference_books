# Qt Quick / QML — Exercise Solutions, Days 19–26 (Phase 3: Advanced Qt Quick)

---

## Day 19 — Advanced Animations & Particles

**Exercises:** `discoveryFlash` wired to `rowsInserted`; `displayValue`/`displayColor` intermediary; guarded one-shot particle burst; continuous-emitter cost demonstration.

```qml
// DeviceCard.qml — discoveryFlash sequence
SequentialAnimation {
    id: discoveryFlash
    ParallelAnimation {
        NumberAnimation { target: card; property: "scale"; from: 1.0; to: 1.15; duration: 150; easing.type: Easing.OutQuad }
        ColorAnimation  { target: card; property: "color"; to: "#89b4fa"; duration: 150 }
    }
    ParallelAnimation {
        NumberAnimation { target: card; property: "scale"; from: 1.15; to: 1.0; duration: 250; easing.type: Easing.OutBack }
        ColorAnimation  { target: card; property: "color"; to: Theme.surface; duration: 250 }
    }
}

// Exercise 1 — wired to the model's built-in signal (fires from Day 14's beginInsertRows)
Connections {
    target: MqttManager.devices
    function onRowsInserted() { discoveryFlash.start() }
}
```

```qml
// GaugeArc.qml — exercise 2: true vs smoothed-for-display separation
property real displayValue: value
property color displayColor: value > 0.8 ? Theme.danger : Theme.success

Behavior on displayValue { NumberAnimation { duration: 300; easing.type: Easing.OutCubic } }
Behavior on displayColor { ColorAnimation { duration: 300 } }

// Proof the distinction matters:
// Label { text: gauge.value }        // updates INSTANTLY
// (gauge's own arc paints from displayValue)  // EASES toward the new value
```

```qml
// main.qml — exercise 3: guarded one-shot burst, only on genuine false->true transition
property bool wasConnected: false

Connections {
    target: ConnectionStateMachine
    function onCurrentStateChanged() {
        var isNowConnected = ConnectionStateMachine.currentState === "connected"
        if (isNowConnected && !wasConnected) {
            burstEmitter.burst(24)
        }
        wasConnected = isNowConnected
    }
}
```

**Exercise 4 (test procedure):** Set `Emitter.emitRate: 30` (continuous) instead of `emitRate: 0` + `.burst()`, leave the app running for a minute, and watch CPU in a system monitor — continuous emission holds ongoing render cost even when nothing visually interesting is happening, versus the burst version's cost returning to zero after ~600ms.

---

## Day 20 — Live Data Visualization

**Exercises:** live line chart wired to real MQTT data; unbounded-growth demonstration; multi-series `Repeater`; auto-scaling axis bounds.

```cpp
// chartdatamanager.h/.cpp
Q_INVOKABLE void ChartDataManager::appendTemperaturePoint(QObject *series, qint64 epochSeconds, double value)
{
    auto *xy = qobject_cast<QXYSeries*>(series);
    if (!xy) {
        qWarning() << "appendTemperaturePoint: not a valid series object";
        return;
    }
    xy->append(static_cast<qreal>(epochSeconds) * 1000.0, value);   // ms, not seconds

    // Exercise 2 — trimming logic; remove this block temporarily to observe
    // memory/CPU climb over a sustained test run, then restore it.
    const int maxPoints = 300;
    if (xy->count() > maxPoints)
        xy->remove(0);
}
```

```qml
// ChartsPage.qml — exercise 3: multi-series driven by the SAME DeviceListModel
ChartView {
    theme: ChartView.ChartThemeDark
    legend.visible: true

    ValueAxis { id: axisY; min: axisController.minRssi; max: axisController.maxRssi }  // exercise 4
    DateTimeAxis { id: axisX; format: "hh:mm" }

    Repeater {
        model: MqttManager.devices
        LineSeries {
            name: model.deviceId
            axisX: axisX
            axisY: axisY
        }
    }
}
```

```cpp
// Exercise 4 — auto-scaling bounds exposed as reactive properties (for RSSI/unknown-range metrics)
class ChartAxisController : public QObject
{
    Q_OBJECT
    QML_ELEMENT
    Q_PROPERTY(double minRssi READ minRssi NOTIFY boundsChanged)
    Q_PROPERTY(double maxRssi READ maxRssi NOTIFY boundsChanged)
public:
    void observe(double rssi) {
        bool changed = false;
        if (rssi < m_min) { m_min = rssi; changed = true; }
        if (rssi > m_max) { m_max = rssi; changed = true; }
        if (changed) emit boundsChanged();
    }
    double minRssi() const { return m_min; }
    double maxRssi() const { return m_max; }
signals:
    void boundsChanged();
private:
    double m_min = 0, m_max = -100;
};
// Comment: hardcoded -20/60 is fine for temperature (a known, bounded physical
// sensor range). RSSI and custom metrics have no fixed known bounds, so their
// axis should track observed min/max rather than a guessed constant.
```

---

## Day 21 — State Machines

**Exercises:** "authenticating" state inserted into the transition table; GoogleTest for `failed -> connecting`; boolean-tangle identification.

```cpp
// connectionstatemachine.h — additional member
QState *m_authenticatingState;
signals:
    void tokenRequired();

// connectionstatemachine.cpp — exercise 2: inserting a new phase without breaking existing paths
m_authenticatingState = new QState(&m_machine);

m_connectingState->addTransition(this, &ConnectionStateMachine::tokenRequired, m_authenticatingState);
m_authenticatingState->addTransition(this, &ConnectionStateMachine::connectionEstablished, m_connectedState);
m_authenticatingState->addTransition(this, &ConnectionStateMachine::connectionLost, m_reconnectingState);

connect(m_authenticatingState, &QState::entered, this, [this]() {
    setCurrentState("authenticating");
});
```

```cpp
// tests/cpp/tst_connectionstatemachine.cpp — exercise 3
#include <gtest/gtest.h>
#include <QCoreApplication>
#include "connectionstatemachine.h"

TEST(ConnectionStateMachineTest, FailedTransitionsToConnectingOnRequest)
{
    int argc = 0;
    QCoreApplication app(argc, nullptr);   // QStateMachine needs an event loop context

    ConnectionStateMachine machine;

    // Drive it into "failed" by exhausting retries
    machine.requestConnect();
    for (int i = 0; i < 6; ++i) {
        machine.notifyConnectionFailed();
        QCoreApplication::processEvents();
    }
    ASSERT_EQ(machine.currentState().toStdString(), "failed");

    // The actual assertion: failed -> connecting is a legal, working transition
    machine.requestConnect();
    QCoreApplication::processEvents();
    ASSERT_EQ(machine.currentState().toStdString(), "connecting");
}
```

**Exercise 4 (write-up):** In `MqttManager`, the offline-detection logic (Day 16) implicitly tracks state via a raw boolean (`online`) plus a timestamp comparison — this works for one binary flag, but if it ever needed to distinguish "confirmed offline via LWT" from "presumed offline via timeout" from "never seen," that's a 3-state distinction currently expressed only through comments and timing, not an explicit transition table. Sketch: `neverSeen -> onlineConfirmed -> {offlineTimeout, offlineLwt} -> onlineConfirmed` would make those distinctions legible the same way `ConnectionStateMachine` does for the broker link itself.

---

## Day 22 — Performance

**Exercises:** overdraw fix; indirect binding-loop fix; `Loader` vs `StackLayout` decision for Settings, justified.

```qml
// Panel.qml — exercise 1: overdraw fix
// BEFORE: Panel's own Rectangle background PLUS main.qml's ApplicationWindow
// background PLUS a per-tile Rectangle background inside — 3 stacked opaque
// layers redrawn every frame in the same screen region.
// FIX: only the innermost, actually-visible layer needs to be opaque; outer
// wrapping Rectangles that are always fully covered can have color: "transparent".
Rectangle {
    id: root
    color: "transparent"   // was solid; now only MetricTile's own Rectangle paints
}
```

```qml
// Exercise 2 — fixing the indirect binding loop by deriving both from a shared source
// BEFORE (cyclic):
//   box.width:     parent.width - sibling.width
//   sibling.width: box.width > 100 ? 50 : 80
// AFTER — both derive from a shared value instead of from each other:
property real totalWidth: parent.width
Rectangle { id: box;     width: totalWidth * 0.6 }
Rectangle { id: sibling; width: totalWidth * 0.4 }
```

```qml
// Exercise 3 — Settings tab as a Loader, then the actual decision
Loader {
    id: settingsLoader
    active: tabBar.currentIndex === 2
    sourceComponent: settingsFormComponent
}
// DECISION: reverted to StackLayout. Users expect in-progress edits in the
// Settings form (a partially-typed host field, an unconfirmed port change)
// to survive switching to another tab and back. A Loader with active: false
// would discard that state on every tab switch — the wrong trade-off for a
// form with editable fields, even though it would save memory. StackLayout's
// state preservation is worth more here than the memory savings.
```

---

## Day 23 — Multi-Window, Dialogs, Popups, Drawer

**Exercises:** confirm `Dialog` for device removal; hover `Popup`; `Drawer` conversion; `StackView` drill-down.

```qml
// DeviceListPage.qml — exercise 1: confirm dialog
Dialog {
    id: removeDialog
    title: qsTr("Remove Device")
    modal: true
    standardButtons: Dialog.Yes | Dialog.No
    anchors.centerIn: parent
    property string targetDeviceId: ""
    Label { text: qsTr("Stop monitoring %1?").arg(removeDialog.targetDeviceId); wrapMode: Text.WordWrap }
    onAccepted: MqttManager.devices.removeDevice(targetDeviceId)
}
```

```qml
// DeviceRow.qml — exercise 2: hover popup, non-blocking, coexisting with the row's own click
Popup {
    id: infoPopup
    width: 180; padding: 10
    closePolicy: Popup.CloseOnPressOutside
    Column {
        Label { text: root.deviceId; font.bold: true }
        Label { text: "RSSI: " + root.rssi + " dBm" }
    }
}

MouseArea {
    anchors.fill: parent
    hoverEnabled: true
    acceptedButtons: Qt.NoButton   // pure hover; does not intercept clicks meant for the row's own MouseArea
    onEntered: infoPopup.open()
    onExited: infoPopup.close()
}
```

```qml
// main.qml — exercise 3: Drawer + hamburger ToolBar replacing TabBar
ApplicationWindow {
    id: window
    Drawer {
        id: navDrawer
        width: 220; height: window.height; edge: Qt.LeftEdge
        ColumnLayout {
            anchors.fill: parent; anchors.margins: 8
            Repeater {
                model: ["Overview", "Devices", "Alerts", "History", "Logs", "Settings"]
                Button {
                    Layout.fillWidth: true
                    text: modelData
                    flat: true
                    onClicked: { stackView.replace(pageFor(modelData)); navDrawer.close() }
                }
            }
        }
    }
    header: ToolBar {
        ToolButton { text: "☰"; onClicked: navDrawer.open() }
    }
    StackView { id: stackView; anchors.fill: parent; initialItem: "OverviewPage.qml" }

    function pageFor(name) {
        return { "Overview": "OverviewPage.qml", "Devices": "DeviceListPage.qml",
                 "Alerts": "AlertsPage.qml", "History": "HistoryPage.qml",
                 "Logs": "LogsPage.qml", "Settings": "SettingsPage.qml" }[name]
    }
}
```

```qml
// exercise 4: StackView drill-down, push/pop preserving history and scroll position
ListView {
    model: MqttManager.devices
    delegate: DeviceRow {
        width: ListView.view.width
        onDeviceSelected: (id) => stackView.push("DeviceDetailPage.qml", { deviceId: id })
    }
}
// DeviceDetailPage.qml
Item {
    property string deviceId: ""
    Button { text: qsTr("Back"); onClicked: stackView.pop() }
}
```

---

## Day 24 — Localization and Accessibility

**Exercises:** `qsTr()` retrofit; keyboard focus + indicator on `DeviceRow`; `Accessible.name`; explicit date/time format.

```qml
// DeviceRow.qml — exercises 2 & 3: keyboard access, focus indicator, accessible status
Rectangle {
    id: root
    focus: true
    activeFocusOnTab: true
    border.width: root.activeFocus ? 2 : 0
    border.color: Theme.accent

    Keys.onReturnPressed: root.deviceSelected(root.deviceId)
    Keys.onSpacePressed: root.deviceSelected(root.deviceId)

    Rectangle {
        id: statusDot
        color: root.online ? "#a6e3a1" : "#f38ba8"
        Accessible.role: Accessible.Indicator
        Accessible.name: root.online ? qsTr("Device online") : qsTr("Device offline")
    }

    Label {
        text: qsTr("%1 dBm").arg(root.rssi)   // exercise 1 — qsTr + %1 placeholder, not concatenation
    }
}
```

```qml
// exercise 4 — explicit date/time format, not locale-dependent
Label {
    text: qsTr("Last seen: %1").arg(
        Qt.formatDateTime(new Date(root.lastSeenEpoch * 1000), "dd.MM.yyyy hh:mm:ss")
    )
}
```

---

## Day 25 — Deployment

Deployment is procedural (shell commands and config files) rather than exercise "code" in the usual sense — the deliverables are:

```bash
# Desktop AppImage build (exercise 1)
./linuxdeployqt-continuous-x86_64.AppImage build/appMonitor -qmldir=. -appimage
```

```bash
# Cross-compiled Pi deployment (exercise 2)
cmake -B build-rpi -DCMAKE_TOOLCHAIN_FILE=/path/to/qt6-rpi-toolchain.cmake -DCMAKE_BUILD_TYPE=Release
cmake --build build-rpi -j$(nproc)
rsync -avz build-rpi/appMonitor pi@raspberrypi.local:/opt/mqtt_monitor/
rsync -avz qt6-rpi-libs/ pi@raspberrypi.local:/opt/mqtt_monitor/lib/
```

```ini
# /etc/systemd/system/mqtt-monitor-gui.service — exercise 4
[Unit]
Description=mqtt_monitor Qt Quick Dashboard
After=network.target

[Service]
Environment=QT_QPA_PLATFORM=eglfs
Environment=LD_LIBRARY_PATH=/opt/mqtt_monitor/lib
ExecStart=/opt/mqtt_monitor/appMonitor
Restart=always
RestartSec=5
User=pi

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now mqtt-monitor-gui.service
# Test Restart=always resilience (exercise 4):
sudo pkill -9 appMonitor
# confirm it restarts within ~5s: journalctl -u mqtt-monitor-gui.service -f
```

**Exercise 3 (recognition, not code):** Running an x86_64-built binary on the Pi, or forgetting `QT_QPA_PLATFORM=eglfs` in a headless environment, produces respectively a "wrong ELF class" linker error or an immediate failure to create a window — both are the unambiguous signatures described in the lesson, not application bugs.

---

## Day 26 — Testing QML

**Exercises:** `SignalSpy` tests for `DeviceRow`; boundary tests for `formatUptime`/`signalQuality`; thin `ConnectionStateMachine` QML-surface test; `ctest` wiring.

```qml
// tests/qml/tst_devicerow.qml
import QtQuick
import QtTest
import MonitorApp

TestCase {
    name: "DeviceRowTests"

    Component {
        id: deviceRowComponent
        DeviceRow { deviceId: "test-device"; rssi: -60; online: true }
    }

    SignalSpy { id: spy }

    function test_emitsDeviceSelectedOnClick() {
        let row = createTemporaryObject(deviceRowComponent, null)
        spy.target = row
        spy.signalName = "deviceSelected"
        mouseClick(row)
        compare(spy.count, 1)
        compare(spy.signalArguments[0][0], "test-device")
    }

    function test_colorReflectsOnlineStateAfterBehaviorSettles() {
        let row = createTemporaryObject(deviceRowComponent, null)
        row.online = false
        wait(300)   // allow the Behavior/ColorAnimation to settle
        compare(row.online, false)
    }

    // Exercise 2 — boundary cases for the small JS helper functions
    function test_formatUptimeZeroSeconds() {
        let row = createTemporaryObject(deviceRowComponent, null)
        compare(row.formatUptime(0), "0h 0m")
    }

    function test_signalQualityBoundaries() {
        let row = createTemporaryObject(deviceRowComponent, null)
        compare(row.signalQuality(-59), "Excellent")
        compare(row.signalQuality(-60), "Good")     // boundary: exactly -60 is NOT > -60
        compare(row.signalQuality(-75), "Weak")      // boundary: exactly -75 is NOT > -75
        compare(row.signalQuality(-74), "Good")
    }
}

// Exercise 3 — thin QML-surface test for the C++ state machine
TestCase {
    name: "ConnectionStateMachineQmlSurface"

    function test_currentStateReflectsConnectRequest() {
        compare(ConnectionStateMachine.currentState, "disconnected")
        ConnectionStateMachine.requestConnect()
        wait(50)
        compare(ConnectionStateMachine.currentState, "connecting")
    }
}
```

```cmake
# CMakeLists.txt — exercise 4: wiring both suites into one ctest invocation
enable_testing()
add_test(NAME cpp_model_tests        COMMAND tst_devicelistmodel)
add_test(NAME cpp_statemachine_tests COMMAND tst_connectionstatemachine)
add_test(NAME qml_devicerow_tests    COMMAND tst_devicerow)
```

```bash
ctest --output-on-failure   # runs GoogleTest + Qt Quick Test suites together
```
