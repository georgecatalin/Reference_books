[[Qt Quick QML]]

## Day 10: Capstone — The Touchscreen `mqtt_monitor` Panel, and QML vs. Widgets Going Forward

### Concept: Today Is Assembly, Not New Material — Same Spirit as Day 8/15/24 From the Widgets Curriculum

Everything needed already exists: real models (Day 4), reusable components (Day 3), states for visual modes (Day 6), touch-appropriate interaction (Day 8), performance discipline (Day 7), and a threading architecture that never needed to change (Day 9). Today wires it into one cohesive touchscreen panel and — just as importantly — closes with an honest decision framework for when you'd actually reach for QML versus Widgets on future work.

### Project Structure

```
mqtt_monitor_qml/
├── CMakeLists.txt
├── main_qml.cpp
├── qml.qrc
├── qml/
│   ├── main.qml
│   ├── DeviceCard.qml
│   ├── ConnectionIndicator.qml
│   └── DeviceDetailPanel.qml
└── (links against mqtt_monitor_core — Day 5's shared backend library:
     mqttworker.cpp/h, serialworker.cpp/h, apiclient.cpp/h,
     devicetablemodel.cpp/h — all completely unmodified from your
     Widgets integration build, aside from Day 4's roleNames() addition)
```

### `DeviceCard.qml` — Final Version, Consolidating Days 3, 6, and 8

```qml
import QtQuick

Rectangle {
    id: card
    property string deviceId: ""
    property string variable: ""
    property real value: 0.0
    property bool online: true
    property real alertThreshold: 80.0

    signal tapped(string deviceId)
    signal longPressed(string deviceId)

    width: 220
    height: 84
    radius: 8

    // States (Day 6) replace what would otherwise be several repeated
    // conditional bindings — one named condition per visual mode
    state: !online ? "offline" : (value > alertThreshold ? "alerting" : "normal")
    states: [
        State { name: "normal";    PropertyChanges { target: card; color: mouseArea.pressed ? "#3a3d54" : "#313244"; border.color: "#45475a" } },
        State { name: "alerting"; PropertyChanges { target: card; color: mouseArea.pressed ? "#4a2424" : "#3d1f1f"; border.color: "#e74c3c" } },
        State { name: "offline";  PropertyChanges { target: card; color: "#181825"; border.color: "#313244" } }
    ]
    transitions: Transition {
        ColorAnimation { properties: "color,border.color"; duration: 200 }
    }
    border.width: 1

    // Behavior (Day 6) — the displayed value animates smoothly on change,
    // replacing Day 23's entire manual QPropertyAnimation reuse/retarget
    // pattern with one declarative line
    Behavior on value {
        NumberAnimation { duration: 400; easing.type: Easing.OutCubic }
    }

    Row {
        anchors.fill: parent
        anchors.margins: 14
        spacing: 12

        Rectangle {
            width: 16; height: 16; radius: 8
            anchors.verticalCenter: parent.verticalCenter
            color: card.online ? "#2ecc71" : "#e74c3c"
        }

        Column {
            anchors.verticalCenter: parent.verticalCenter
            spacing: 4
            Text { text: card.deviceId; color: "#cdd6f4"; font.pixelSize: 18; font.bold: true }
            Text { text: card.variable + ": " + card.value.toFixed(1); color: "#a6adc8"; font.pixelSize: 16 }
        }
    }

    // Touch-first interaction (Day 8) — whole card tappable, long-press
    // for the secondary action, pressed-state color replaces hover
    MouseArea {
        id: mouseArea
        anchors.fill: parent
        onClicked: card.tapped(card.deviceId)
        onPressAndHold: card.longPressed(card.deviceId)
    }
}
```

### `main.qml` — The Full Dashboard

```qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts
import MqttMonitor 1.0

ApplicationWindow {
    id: window
    width: 800
    height: 480   // realistic 7" touchscreen resolution — a real, deliberate
                   // choice for a wall-mounted Pi panel, not an arbitrary size
    visible: true
    title: "mqtt_monitor — Panel"

    ColumnLayout {
        anchors.fill: parent
        spacing: 0

        // Top status bar — ApiClient singleton (Day 5) consumed directly,
        // no property-passing needed anywhere in this file
        Rectangle {
            Layout.fillWidth: true
            Layout.preferredHeight: 48
            color: "#11111b"

            RowLayout {
                anchors.fill: parent
                anchors.margins: 8

                Text {
                    text: ApiClient.isHealthy ? "● Backend OK" : "● Backend Down"
                    color: ApiClient.isHealthy ? "#2ecc71" : "#e74c3c"
                    font.pixelSize: 16
                }
                Item { Layout.fillWidth: true } // spacer, QML's "addStretch()" equivalent
                Text {
                    text: Qt.formatDateTime(new Date(), "hh:mm:ss")
                    color: "#a6adc8"
                    font.pixelSize: 16

                    Timer {
                        interval: 1000; running: true; repeat: true
                        onTriggered: parent.text = Qt.formatDateTime(new Date(), "hh:mm:ss")
                    }
                }
            }
        }

        // Main content — GridView with real delegate recycling (Day 7),
        // backed directly by the real C++ DeviceTableModel (Day 4),
        // fed by the real, unmodified MqttWorker (Day 9)
        GridView {
            id: grid
            Layout.fillWidth: true
            Layout.fillHeight: true
            cellWidth: 232
            cellHeight: 96
            reuseItems: true      // confirmed on, per Day 7's caution
            cacheBuffer: 200

            model: deviceModel     // exposed via setContextProperty, Day 4

            delegate: DeviceCard {
                deviceId: model.deviceId
                variable: model.variable
                value: model.value
                online: model.online

                onTapped: (id) => detailPanel.showDevice(id)
                onLongPressed: (id) => console.log("Long-press menu for", id)
            }
        }
    }

    // A slide-in detail panel — deliberately simple, demonstrating
    // Q_INVOKABLE (Day 5) triggering a real backend action from touch input
    DeviceDetailPanel {
        id: detailPanel
        anchors.fill: parent
        visible: false
    }
}
```

### `DeviceDetailPanel.qml` — `Q_INVOKABLE` in Real Use

```qml
import QtQuick
import QtQuick.Controls
import MqttMonitor 1.0

Rectangle {
    id: panel
    color: "#00000000" // transparent backdrop; the actual panel slides in below

    property string currentDeviceId: ""

    function showDevice(deviceId) {
        currentDeviceId = deviceId;
        visible = true;
        // Q_INVOKABLE call (Day 5) — triggers a real REST request against
        // your actual FastAPI backend, off the touch-tap that opened this panel
        ApiClient.fetchHistory(deviceId, "temperature", 50);
    }

    MouseArea {
        anchors.fill: parent
        onClicked: panel.visible = false // tap-outside-to-dismiss — a standard
                                            // touch idiom, direct analog of
                                            // Day 5's Widgets modeless dialogs
    }

    Rectangle {
        width: parent.width * 0.7
        height: parent.height
        anchors.right: parent.right
        color: "#181825"

        Column {
            anchors.fill: parent
            anchors.margins: 16
            spacing: 8

            Text {
                text: panel.currentDeviceId
                color: "#cdd6f4"
                font.pixelSize: 22
                font.bold: true
            }
            Text {
                text: "Loading history..."
                color: "#a6adc8"

                // Consuming the real signal from ApiClient (Day 9's
                // Q_PROPERTY/NOTIFY bridge, applied to a plain signal instead)
                Connections {
                    target: ApiClient
                    function onHistoryFetched(deviceId, variable, points) {
                        if (deviceId === panel.currentDeviceId) {
                            parent.text = "Loaded " + points.length + " points"
                            // A real chart (Shape-based, Day 7) would consume
                            // 'points' here to draw a history line
                        }
                    }
                }
            }
        }
    }

    // Prevent taps on the panel itself from dismissing it (only the
    // backdrop area should close it) — a small but real touch-UX detail
    MouseArea {
        width: parent.width * 0.7
        height: parent.height
        anchors.right: parent.right
        onClicked: {} // consumes the click, stops it reaching the backdrop MouseArea
    }
}
```

### `main_qml.cpp` — Wiring the Real Backend, Unchanged From Day 9

```cpp
#include <QGuiApplication>
#include <QQmlApplicationEngine>
#include <QQmlContext>
#include <QThread>
#include "mqttworker.h"
#include "devicetablemodel.h"
#include "apiclient.h"

int main(int argc, char *argv[]) {
    QGuiApplication app(argc, argv);

    DeviceTableModel deviceModel;
    static ApiClient apiClient("http://localhost:8000");

    qmlRegisterSingletonInstance("MqttMonitor", 1, 0, "ApiClient", &apiClient);

    auto *mqttThread = new QThread();
    auto *mqttWorker = new MqttWorker("localhost", 1883, "mqtt_monitor_panel");
    mqttWorker->moveToThread(mqttThread);
    QObject::connect(mqttThread, &QThread::started, mqttWorker, &MqttWorker::start);
    QObject::connect(mqttWorker, &MqttWorker::readingReceived, &deviceModel,
        [&deviceModel](const QString &id, const QString &var, double val) {
            deviceModel.upsertReading({id, var, val, QDateTime::currentDateTime(), true});
        });
    QObject::connect(mqttWorker, &MqttWorker::finished, mqttThread, &QThread::quit);
    QObject::connect(mqttThread, &QThread::finished, mqttWorker, &QObject::deleteLater);
    QObject::connect(mqttThread, &QThread::finished, mqttThread, &QObject::deleteLater);
    mqttThread->start();

    QQmlApplicationEngine engine;
    engine.rootContext()->setContextProperty("deviceModel", &deviceModel);
    engine.load(QUrl("qrc:/qml/main.qml"));
    if (engine.rootObjects().isEmpty()) return -1;

    apiClient.checkHealth();
    return app.exec();
}
```

### The Closing Decision Framework — QML vs. Widgets, Going Forward

This is the actual professional judgment this curriculum was building toward. Not "QML is better" or "Widgets is better" — a concrete decision framework:

|Signal|Choose Widgets|Choose QML|
|---|---|---|
|**Primary input device**|Mouse/keyboard, dev workstation|Touchscreen, wall-mounted panel|
|**Data density**|Dense tables, many columns, precise inspection|A handful of at-a-glance status cards/gauges|
|**Team/tooling familiarity**|You already have 30 days of muscle memory here|Worth the ramp-up specifically for touch/animation-heavy UI|
|**Target hardware GPU**|Works fine even without solid GPU accel|Needs a genuinely working GPU driver stack|
|**Animation/fluidity needs**|Occasional, tasteful (Day 12's restraint lesson)|Central to the experience (live gauges, smooth transitions)|
|**Debugging/admin tooling**|Natural fit — dense, functional, less "designed"|Awkward fit — over-engineering a debug tool|

**For `mqtt_monitor` concretely**: the Widgets dashboard remains the right tool for the dev-workstation/admin/debugging use case it was actually built for across 30 days — dense tables, precise interaction, no GPU dependency risk. This QML build is the right tool specifically **if and when** a wall-mounted or handheld touchscreen panel becomes a real deployment target — not a replacement for the Widgets work, a genuinely different tool for a genuinely different situation, both built on the exact same unmodified C++ backend.

### Final Integration Checklist

|#|Confirm|
|---|---|
|1|Real MQTT data flows into the QML `GridView` via the unmodified `MqttWorker`/`DeviceTableModel`, no polling|
|2|`ApiClient` singleton is reachable from any QML file without property-passing, and its `isHealthy` binding updates live|
|3|Cards show correct `states` (normal/alerting/offline) with smooth transitions, and pressed-state feedback replaces any hover assumption|
|4|`reuseItems` confirmed active via the Day 7 delegate-creation-count check under a realistic device count|
|5|Tapping a card opens the detail panel and triggers a real `fetchHistory()` call against your actual FastAPI backend|
|6|The Widgets build (from the 30-day curriculum) still compiles and runs unchanged against the same shared backend library|
