[[Capstone Project]]

# Day 27 — Capstone Kickoff: Architecture and Project Skeleton

Days 1–26 built every individual capability in isolation. The capstone (Days 27–30) assembles them into one real, cohesive `mqtt_monitor` Qt Quick application — not a bigger tutorial exercise, but the actual dashboard you'll plausibly keep running. Today: the architecture decision, the full project structure, and the skeleton that everything else plugs into.

## Concept: Making the architecture decision for real (Day 15's choice, now committed)

Day 15 laid out two options. For the capstone, commit to the **native C++ backend** path — direct MQTT (Day 16) and SQLite (Day 17) from C++, no separate FastAPI process. Reasoning, stated plainly: your target is a Raspberry Pi kiosk display (Day 25); a single compiled binary with no separate Python service to keep alive is operationally simpler for that specific deployment, and you've already built the Python REST version in your other capstone — building the native version here gives you the _other_ real architecture, not a redundant one. If your actual use case later favors REST-over-existing-backend, everything from Day 15 ports over with minimal change — the model/view layer doesn't care which backend feeds it.

## Concept: Project structure — the whole thing, laid out

```
mqtt_monitor_gui/
├── CMakeLists.txt
├── main.cpp
├── qtquickcontrols2.conf
├── backend/
│   ├── mqttmanager.h/.cpp          # Day 16
│   ├── devicelistmodel.h/.cpp      # Day 14
│   ├── databasemanager.h/.cpp      # Day 17
│   ├── telemetryworker.h/.cpp      # Day 18
│   ├── connectionstatemachine.h/.cpp  # Day 21
│   └── chartdatamanager.h/.cpp     # Day 20
├── qml/
│   ├── main.qml
│   ├── Theme.qml                   # Day 12
│   ├── qmldir
│   ├── components/
│   │   ├── DeviceRow.qml           # Day 5/8
│   │   ├── DeviceCard.qml          # Day 11
│   │   ├── MetricTile.qml          # Day 11
│   │   ├── GaugeArc.qml            # Day 13
│   │   ├── Sparkline.qml           # Day 13
│   │   ├── Panel.qml               # Day 11
│   │   └── ConnectionStatusHeader.qml
│   ├── pages/
│   │   ├── OverviewPage.qml
│   │   ├── DeviceListPage.qml
│   │   ├── DeviceDetailPage.qml    # Day 23 StackView target
│   │   ├── ChartsPage.qml          # Day 20
│   │   ├── HistoryPage.qml         # Day 17
│   │   └── SettingsPage.qml        # Day 3/9
│   └── styles/MyStyle/             # Day 12, optional
├── tests/
│   ├── cpp/ (GoogleTest — model, state machine, database)
│   └── qml/ (Qt Quick Test — Day 26)
└── translations/                   # Day 24, optional
```

`components/` (small, reusable, no navigation awareness) vs `pages/` (composed from components, aware of the app's navigation) is a deliberate split — the same instinct as separating a UI toolkit's widgets from an app's screens in any framework. `DeviceRow` doesn't know it's in a `ListView` inside a `Drawer`-navigated page; `DeviceListPage` does.

## Concept: `main.cpp` — wiring every backend singleton together

```cpp
#include <QGuiApplication>
#include <QQmlApplicationEngine>
#include <QQuickStyle>
#include <QTranslator>
#include "backend/mqttmanager.h"
#include "backend/databasemanager.h"

int main(int argc, char *argv[])
{
    QGuiApplication app(argc, argv);
    QGuiApplication::setApplicationName("mqtt_monitor");
    QGuiApplication::setOrganizationName("George");

    QQuickStyle::setStyle("Basic");
    QQuickStyle::setFallbackStyle("Basic");

    // Day 24 — cheap to leave in even with no translations shipped yet
    QTranslator translator;
    if (translator.load(QLocale(), "mqtt_monitor", "_", ":/translations"))
        app.installTranslator(&translator);

    QQmlApplicationEngine engine;

    QObject::connect(&engine, &QQmlApplicationEngine::objectCreationFailed,
                      &app, []() { QCoreApplication::exit(-1); }, Qt::QueuedConnection);

    engine.load(QUrl(u"qrc:/qml/main.qml"_qs));

    return app.exec();
}
```

Notice how little is here — `MqttManager`, `DatabaseManager`, `ConnectionStateMachine`, `ChartDataManager` are all `QML_SINGLETON`s (Day 10), so there's no manual `setContextProperty` wiring at all. This is the payoff of Day 10's guidance: the singletons register themselves via `QML_ELEMENT`/`QML_SINGLETON` in their own headers and become available the moment their module is imported in QML — `main.cpp` stays this thin regardless of how many backend objects you add.

## Concept: `main.qml` — the shell that everything plugs into

```qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts
import MonitorApp

ApplicationWindow {
    id: window
    width: 800
    height: 600
    visible: true
    title: qsTr("mqtt_monitor")
    color: Theme.background

    Drawer {
        id: navDrawer
        width: 220
        height: window.height
        edge: Qt.LeftEdge

        ColumnLayout {
            anchors.fill: parent
            anchors.margins: 8

            Repeater {
                model: [
                    { label: qsTr("Overview"), page: "qrc:/qml/pages/OverviewPage.qml" },
                    { label: qsTr("Devices"), page: "qrc:/qml/pages/DeviceListPage.qml" },
                    { label: qsTr("Charts"), page: "qrc:/qml/pages/ChartsPage.qml" },
                    { label: qsTr("History"), page: "qrc:/qml/pages/HistoryPage.qml" },
                    { label: qsTr("Settings"), page: "qrc:/qml/pages/SettingsPage.qml" }
                ]
                delegate: Button {
                    Layout.fillWidth: true
                    text: modelData.label
                    flat: true
                    onClicked: {
                        stackView.replace(modelData.page)
                        navDrawer.close()
                    }
                }
            }
        }
    }

    header: ToolBar {
        RowLayout {
            anchors.fill: parent
            ToolButton {
                text: "☰"
                onClicked: navDrawer.open()
            }
            Label {
                text: qsTr("mqtt_monitor")
                Layout.fillWidth: true
            }
            ConnectionStatusHeader {
                connected: ConnectionStateMachine.currentState === "connected"
                deviceCount: MqttManager.devices.rowCount()
            }
        }
    }

    StackView {
        id: stackView
        anchors.fill: parent
        initialItem: "qrc:/qml/pages/OverviewPage.qml"
    }
}
```

Notice **`stackView.replace()`, not `push()`, for drawer navigation** — a deliberate, easy-to-miss distinction from Day 23. `push()` builds navigation history (right for drill-down, like device-list → device-detail); `replace()` swaps the current top-level page without accumulating history (right for switching between sibling sections via the drawer — you don't want "Back" to cycle through every section you've ever visited via the drawer, only through genuine drill-downs like a device detail view).

## Your task for today

Before Day 28 assembles individual pages, get this skeleton building and running — an empty shell with working navigation, wired to your real backend singletons from Phase 2, showing genuinely live `ConnectionStateMachine.currentState` and `MqttManager.devices.rowCount()` in the header (both already real, from Days 16 and 21) even before the individual pages have real content.

## Exercise

1. Build this exact project structure (empty page stubs are fine — `Label { text: "Overview" }` placeholders) and confirm it compiles, runs, and the Drawer navigation correctly swaps pages via `replace()`.
2. Confirm the header's `ConnectionStatusHeader` genuinely reflects your real `MqttManager`/`ConnectionStateMachine` state — connect to your actual broker and watch the header update with zero page-specific code involved, proving the shell-level wiring is correct before you've built a single real page.
3. Add one real `StackView.push()` drill-down stub (e.g., a placeholder "Device Detail" page pushed from a button on the empty Overview stub) to confirm `push`/`pop` history behaves distinctly from the drawer's `replace()` — this distinction is worth proving to yourself now, before Day 29 builds the real device list → detail flow on top of it.
4. Set up `tests/cpp/` and `tests/qml/` directories with the CMake scaffolding from Days 21/26 (even with just one placeholder test each) so the test infrastructure exists from day one of the capstone, not bolted on afterward.

## Key takeaways

- Native C++ backend (committed for this capstone) vs REST-over-Python (Day 15's alternative) — both legitimate, this capstone builds the C++-native path since it matches your Pi deployment target.
- `components/` (reusable, navigation-unaware) vs `pages/` (composed, navigation-aware) is a deliberate structural split worth maintaining as the app grows.
- `QML_SINGLETON` backend objects (Day 10) mean `main.cpp` stays thin regardless of how many backend managers you add — no manual context-property wiring per object.
- `stackView.replace()` for drawer/sibling navigation vs `stackView.push()` for genuine drill-down — using the wrong one produces either a confusingly-growing back-history or a broken "can't go back" drill-down.
- Get the empty shell fully wired to real backend singletons _before_ building individual page content — this validates your architecture's plumbing independently of any specific page's complexity.

