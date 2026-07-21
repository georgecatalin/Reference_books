# Qt Quick / QML — Exercise Solutions, Days 27–30 (Capstone: mqtt_monitor Dashboard)

The capstone's "exercises" are largely *build the described artifact*, so the solution for most of these days is the assembled reference code already presented in each lesson. This document supplies the pieces that were exercises in the narrower sense — new code beyond the lesson's own reference build — plus pointers to where the rest lives.

---

## Day 27 — Skeleton and Architecture

**Exercise 1–2:** the full project skeleton and `main.qml` shell are given complete in the Day 27 lesson (`CMakeLists.txt` layout, `main.cpp`, `main.qml` with `Drawer` + `header: ToolBar` + `StackView`). Build exactly that; there is no additional code beyond it for these two items.

**Exercise 3 — a `push()`/`pop()` drill-down stub, to prove it behaves distinctly from the Drawer's `replace()`:**

```qml
// pages/OverviewPage.qml (placeholder stub for Day 27)
import QtQuick
import QtQuick.Controls

Item {
    Column {
        anchors.centerIn: parent
        spacing: 12
        Label { text: "Overview (placeholder)"; color: "#cdd6f4" }
        Button {
            text: "View sample device detail"
            onClicked: stackView.push("qrc:/qml/pages/DeviceDetailPage.qml", { deviceId: "esp32-04" })
        }
    }
}
```

```qml
// pages/DeviceDetailPage.qml (placeholder stub)
import QtQuick
import QtQuick.Controls

Item {
    property string deviceId: ""
    Column {
        anchors.centerIn: parent
        spacing: 12
        Label { text: "Detail for: " + deviceId; color: "#cdd6f4" }
        Button { text: "Back"; onClicked: stackView.pop() }
    }
}
```

Confirm: navigating Overview → Devices (via Drawer) uses `replace()` and does **not** add to history — pressing a hypothetical back button from Devices would not return to Overview. Pushing the Device Detail page from Overview **does** add history — `pop()` correctly returns to Overview with its state intact. This distinct behavior is the point of the exercise.

**Exercise 4 — test scaffolding present from day one of the capstone:**

```cmake
# tests/cpp/CMakeLists.txt
add_executable(tst_devicelistmodel tst_devicelistmodel.cpp)
target_link_libraries(tst_devicelistmodel PRIVATE gtest gtest_main Qt6::Core)
add_test(NAME cpp_devicelistmodel_placeholder COMMAND tst_devicelistmodel)
```

```cpp
// tests/cpp/tst_devicelistmodel.cpp — placeholder, expanded in later days
#include <gtest/gtest.h>
TEST(DeviceListModelTest, PlaceholderCompiles) {
    ASSERT_TRUE(true);   // scaffolding exists; real assertions added as the model solidifies
}
```

```cmake
# tests/qml/CMakeLists.txt
qt_add_executable(tst_placeholder tst_placeholder.cpp)
target_link_libraries(tst_placeholder PRIVATE Qt6::QuickTest)
```

```qml
// tests/qml/tst_placeholder.qml
import QtQuick
import QtTest

TestCase {
    name: "PlaceholderSuite"
    function test_frameworkWorks() { compare(1 + 1, 2) }
}
```

---

## Day 28 — Overview and Devices Pages

**Exercise 1 — the corrected, reactive `DeviceListModel` additions** (promoting `onlineCount`/`averageRssi` to real `Q_PROPERTY`s, the point of the exercise):

```cpp
// devicelistmodel.h — additions
Q_PROPERTY(int onlineCount READ onlineCount NOTIFY onlineCountChanged)
Q_PROPERTY(double averageRssi READ averageRssi NOTIFY averageRssiChanged)

public:
    int onlineCount() const {
        int count = 0;
        for (const auto &d : m_devices) if (d.online) count++;
        return count;
    }
    double averageRssi() const {
        if (m_devices.isEmpty()) return 0.0;
        double sum = 0.0;
        for (const auto &d : m_devices) sum += d.rssi;
        return sum / m_devices.count();
    }

signals:
    void onlineCountChanged();
    void averageRssiChanged();
```

```cpp
// devicelistmodel.cpp — emit at the point of actual mutation
void DeviceListModel::addOrUpdateDevice(const QString &deviceId, int rssi, bool online, qint64 lastSeen)
{
    int idx = indexOfDevice(deviceId);
    if (idx >= 0) {
        bool onlineChangedFlag = (m_devices[idx].online != online);
        m_devices[idx].rssi = rssi;
        m_devices[idx].online = online;
        m_devices[idx].lastSeenEpoch = lastSeen;
        QModelIndex mi = index(idx, 0);
        emit dataChanged(mi, mi, {RssiRole, OnlineRole, LastSeenRole});
        emit averageRssiChanged();
        if (onlineChangedFlag) emit onlineCountChanged();
    } else {
        beginInsertRows(QModelIndex(), m_devices.count(), m_devices.count());
        m_devices.append({deviceId, rssi, online, lastSeen});
        endInsertRows();
        emit countChanged();
        emit averageRssiChanged();
        emit onlineCountChanged();
    }
}

void DeviceListModel::removeDevice(const QString &deviceId)
{
    int idx = indexOfDevice(deviceId);
    if (idx < 0) return;
    bool wasOnline = m_devices[idx].online;
    beginRemoveRows(QModelIndex(), idx, idx);
    m_devices.removeAt(idx);
    endRemoveRows();
    emit countChanged();
    emit averageRssiChanged();
    if (wasOnline) emit onlineCountChanged();
}
```

```qml
// OverviewPage.qml — now genuinely reactive tiles (no manual refresh anywhere)
MetricTile {
    label: qsTr("Devices Online")
    value: MqttManager.devices.onlineCount + " / " + MqttManager.devices.count
}
MetricTile {
    label: qsTr("Avg Signal")
    value: MqttManager.devices.averageRssi.toFixed(1) + " dBm"
}
```

**Exercise 2 — `requestDeviceRefresh()` stub:**

```cpp
// mqttmanager.h
Q_INVOKABLE void requestDeviceRefresh();

// mqttmanager.cpp
void MqttManager::requestDeviceRefresh()
{
    if (m_client->state() == QMqttClient::Connected) {
        m_client->subscribe(QMqttTopicFilter("devices/+/status"));
    } else {
        qWarning() << "requestDeviceRefresh called while not connected";
    }
}
```

```qml
// DeviceListPage.qml
Button {
    text: qsTr("Refresh")
    onClicked: MqttManager.requestDeviceRefresh()
}
```

**Exercises 3–4** (filter test, right-click dialog) use exactly the `DeviceListPage.qml` code already given in full in the Day 28 lesson — build and test against that; no additional code is needed beyond it.

---

## Day 29 — Charts and History Pages

Both pages are given complete in the Day 29 lesson (`ChartsPage.qml`, `HistoryPage.qml`). The exercises are verification tasks against that code:

**Exercise 2 — deliberately breaking the reassignment rule, to prove it (the actual "solution" is recognizing the failure):**

```qml
// HistoryPage.qml — BROKEN variant, for the exercise only:
Button {
    text: qsTr("Load (broken)")
    onClicked: {
        var rows = DatabaseManager.fetchDeviceHistory(root.selectedDeviceId, limitSpin.value)
        root.historyRows.push(...rows)   // in-place mutation of a var-typed property
        // The ListView does NOT refresh — no property assignment occurred,
        // so no change signal fired. Confirms Day 13's array-reassignment
        // rule applies here too.
    }
}

// CORRECT version (already in the lesson):
Button {
    text: qsTr("Load")
    onClicked: root.historyRows = DatabaseManager.fetchDeviceHistory(root.selectedDeviceId, limitSpin.value)
}
```

**Exercise 4 — empty-state handling for a brand-new device with no history:**

```cpp
// DatabaseManager::fetchDeviceHistory already returns an empty QVariantList
// (not an error) when the query succeeds but matches zero rows — this is
// the correct behavior already, no change needed. Confirm in QML:
```

```qml
Label {
    visible: root.historyRows.length === 0
    text: qsTr("No history loaded. Select a device above.")
    color: Theme.textSecondary
}
```

---

## Day 30 — Settings, Integration, Final Wrap-Up

**`SettingsPage.qml`** is given complete in the Day 30 lesson, wired to `MqttManager`, `ConnectionStateMachine`, and `DatabaseManager` singletons with no additional code required.

The remaining "exercise" for Day 30 is procedural — the end-to-end integration checklist — captured here as a runnable test script rather than application code, since that's the actual deliverable for the final day:

```bash
#!/usr/bin/env bash
# integration_check.sh — a rough manual-QA companion script for Day 30's checklist
set -e

echo "1. Cold start (no broker) — launch and observe state machine walk:"
echo "   Expect: disconnected -> connecting -> reconnecting -> failed"
./build-rpi/appMonitor &
APP_PID=$!
sleep 15
echo "   Check ConnectionStateMachine.currentState via logs/UI now."

echo "2. Live burst test:"
for i in $(seq 1 100); do
    mosquitto_pub -t "devices/burst-test-$((i % 10))/status" \
        -m "{\"rssi\": $((RANDOM % 40 - 90)), \"online\": true, \"temperature\": $((RANDOM % 60))}"
done
echo "   Watch UI for stutter; confirm DeviceListModel updates correctly under load."

echo "3. Kill broker mid-session to test reconnect/backoff:"
sudo systemctl stop mosquitto
sleep 20
sudo systemctl start mosquitto
echo "   Confirm state machine reaches 'connected' again without an app restart."

echo "4. Sustained runtime — leave running, monitor memory:"
echo "   ps -o rss,vsz -p $APP_PID   (repeat every few minutes for at least an hour)"

kill $APP_PID
```

Run this (or the equivalent manual steps) against real hardware where possible, per Day 30's checklist — fixing whatever it surfaces is the actual capstone deliverable, not new source code.

---

## Summary

All code for Days 27–30 that constitutes genuine new material beyond each lesson's own fully-given reference build is included above:
- Day 27: placeholder pages proving `push`/`pop` vs `replace`, test scaffolding.
- Day 28: the corrected reactive `onlineCount`/`averageRssi` `Q_PROPERTY`s and `requestDeviceRefresh()`.
- Day 29: the broken-vs-correct reassignment contrast, and confirmation of existing empty-state handling.
- Day 30: the integration-check script standing in for the checklist itself.

The remainder of the capstone's four days is the assembled project already presented in full in each lesson — those files are the solutions for their respective "build this" exercises.
