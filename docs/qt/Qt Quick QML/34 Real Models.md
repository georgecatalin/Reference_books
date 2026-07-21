[[Qt Quick QML]]

## Day 4: Real Models — `ListModel` vs. Exposing a C++ `QAbstractListModel` to QML

### Concept: QML Has Its Own Toy Model, and a Bridge to Your Real C++ Model — Know Which One You're Actually Using

Day 2's `ListView` used a plain JS array (`["device-01", ...]`) as its model — fine for a placeholder, not how a real application works. QML gives you two genuine model options, and picking the wrong one for the job is a common mistake:

1. **`ListModel`** — a QML-native, in-QML-file data container. Fine for small, UI-only state (a settings list, a static menu) that has no reason to exist in C++. **Not** what you want for `mqtt_monitor`'s device data, since that data already has a perfectly good home: your existing `DeviceTableModel` from the Widgets build.
2. **Exposing a C++ `QAbstractListModel`/`QAbstractItemModel` to QML** — the real production pattern. Your existing `DeviceTableModel` (Day 9/Widgets, adapted for variable-based readings in your actual integration) can be exposed to QML **directly, unmodified** — this is the single most valuable fact today: **you do not rewrite your model for QML.** The same `QAbstractItemModel` subclass Widgets used works for QML too, because QML's model/view system is built on the same underlying Qt model classes.

### `ListModel` — When It's Actually the Right Tool

```qml
import QtQuick

ListView {
    anchors.fill: parent
    model: ListModel {
        id: settingsModel
        ListElement { label: "Dark theme"; enabled: true }
        ListElement { label: "Auto-reconnect"; enabled: true }
        ListElement { label: "Sound alerts"; enabled: false }
    }
    delegate: Text { text: label + ": " + enabled }
}
```

This is genuinely fine for UI-only toggle lists that have no reason to live in C++ — a settings panel's static options, for instance. **It is not the right tool for live telemetry data**, which is exactly the mistake to avoid: reaching for `ListModel` out of familiarity, then hand-syncing it from C++ signals, when a real `QAbstractItemModel` bridge does this correctly and automatically.

### Exposing Your Real `DeviceTableModel` to QML — The Actual Production Pattern

This is today's real work. Three things need to happen: (1) your model needs a `Q_INVOKABLE`/role-name setup QML can actually consume, (2) it needs to be instantiated in C++ and handed to the QML engine, (3) QML's `TableView`/`ListView` needs to know how to read its roles.

**Step 1 — Role names.** QML's views read model data by **role name** (a string), not by column index the way `QTableView` did. Your existing `data(index, role)` override already returns values per role — you just need to register **named roles** via `roleNames()`:

```cpp
// Add to DeviceTableModel.h (your real, adapted-for-variables model
// from the integration work, unchanged otherwise):

protected:
    QHash<int, QByteArray> roleNames() const override;

// New role constants, above Qt::UserRole territory:
enum Roles {
    DeviceIdRole = Qt::UserRole + 1,
    VariableRole,
    ValueRole,
    LastSeenRole,
    OnlineRole
};
```

```cpp
// DeviceTableModel.cpp

QHash<int, QByteArray> DeviceTableModel::roleNames() const {
    return {
        {DeviceIdRole, "deviceId"},
        {VariableRole, "variable"},
        {ValueRole, "value"},
        {LastSeenRole, "lastSeen"},
        {OnlineRole, "online"}
    };
}
```

Extend `data()` to answer these new roles (in addition to the `Qt::DisplayRole`/`Qt::UserRole` branches your Widgets code already has — nothing there needs to be removed):

```cpp
QVariant DeviceTableModel::data(const QModelIndex &index, int role) const {
    if (!index.isValid() || index.row() >= readings.size()) return QVariant();
    const DeviceReading &r = readings.at(index.row());

    switch (role) {
        case DeviceIdRole: return r.deviceId;
        case VariableRole: return r.variable;
        case ValueRole:    return r.value;
        case LastSeenRole: return r.lastSeen.toString("HH:mm:ss");
        case OnlineRole:   return r.online;
    }

    // ... your existing Qt::DisplayRole / Qt::UserRole / Qt::BackgroundRole
    // branches from the Widgets build stay exactly as they are — this
    // model now serves BOTH a QTableView (Widgets) and a QML ListView,
    // simultaneously, from the same class, same live data.
    return QVariant();
}
```

**This is worth pausing on**: your model now has a genuine dual-purpose role — `Qt::DisplayRole`/`Qt::UserRole` for the `QTableView` you already built, and named roles for QML. Nothing about this is a hack; it's the intended, correct way for one model to serve both UI stacks, which matters enormously if you ever want a Widgets debug/admin view _and_ a QML touchscreen display both reading the exact same live data.

**Step 2 — Getting the C++ model instance into QML.** This is done via `setContextProperty` on the QML engine, in your application's `main.cpp`:

```cpp
#include <QGuiApplication>
#include <QQmlApplicationEngine>
#include "devicetablemodel.h"

int main(int argc, char *argv[]) {
    QGuiApplication app(argc, argv); // NOTE: QGuiApplication, not QApplication —
                                       // QML apps don't need Widgets at all unless
                                       // you're mixing both UI stacks (Day 5 covers this)

    DeviceTableModel deviceModel; // same class as your Widgets build,
                                    // literally unmodified aside from
                                    // today's roleNames()/role additions

    QQmlApplicationEngine engine;

    // Exposes the C++ object to EVERY QML file loaded by this engine,
    // under the name "deviceModel" — QML code just refers to it directly,
    // no import needed for this specific mechanism
    engine.rootContext()->setContextProperty("deviceModel", &deviceModel);

    engine.load(QUrl("qrc:/main.qml"));
    if (engine.rootObjects().isEmpty()) return -1;

    // Feed it real data exactly like your Widgets MainWindow does —
    // your MqttWorker/thread setup from the integration work is
    // COMPLETELY UNCHANGED here; only the presentation layer differs
    deviceModel.upsertReading({"device-01", "temperature", 42.5, QDateTime::currentDateTime(), true});

    return app.exec();
}
```

**Step 3 — Consuming it in QML**, replacing Day 2's hardcoded array:

```qml
import QtQuick
import QtQuick.Controls

ApplicationWindow {
    width: 500
    height: 400
    visible: true

    ListView {
        anchors.fill: parent
        anchors.margins: 8
        spacing: 4

        // deviceModel — the exact C++ object exposed via setContextProperty,
        // referenced here as if it were any other QML model
        model: deviceModel

        delegate: DeviceCard {
            width: ListView.view.width
            // Role names from roleNames() become directly available
            // properties on each delegate instance — 'deviceId', 'variable',
            // 'value', 'online' are all just... there, no boilerplate
            deviceId: model.deviceId
            temperature: model.value
            online: model.online

            onDetailsRequested: (id) => console.log("Details for", id)
        }
    }
}
```

### The Live-Update Payoff — Confirming the Whole Point of This Exercise

Because `DeviceTableModel::upsertReading()` still calls `beginInsertRows()`/`dataChanged()` exactly as it did for the `QTableView` in your Widgets build, **the QML `ListView` updates automatically the instant new data arrives** — no QML-side polling, no manual refresh call. This is the direct payoff of Day 9/Widgets' discipline about correctly bracketing model mutations: that same discipline is _why_ this now works for free in QML too. If your Widgets `dataChanged`/`rowsInserted` emissions were ever sloppy, this is exactly where it would surface as a QML view failing to refresh.

### Why This Matters

- **You do not rewrite your data model for QML** — this is the single most important practical fact today. The same `QAbstractItemModel` subclass serves both UI stacks, provided you add named roles alongside (not instead of) whatever your Widgets code already relies on.
- **`roleNames()` is the actual bridge** — QML views have no concept of "column index," only role names, so this override is non-optional if you want a real C++ model to work in QML at all.
- **`setContextProperty` is the simplest, most direct way to expose a C++ object to QML** — there's a more structured module/singleton-registration approach (Day 5 covers this) for larger applications, but `setContextProperty` is the right starting point and often sufficient for an application this size.
- **`QGuiApplication` vs. `QApplication`** matters: pure-QML apps don't need Widgets at all, and using the lighter `QGuiApplication` is correct unless you're deliberately mixing both UI stacks in one process (a real, supported pattern, covered properly on Day 5, relevant if you want a hybrid Widgets-admin-panel + QML-touchscreen-display application sharing one backend).

### Exercise

1. Add the role-name additions to your actual integration build's `DeviceTableModel` (not a toy copy) — confirm the existing Widgets `QTableView` still works completely unchanged after adding `roleNames()`/new role constants, proving the dual-purpose claim for yourself rather than taking it on faith.
2. Wire a QML `ListView` using `DeviceCard.qml` (Day 3) against the real model, feed it live data via your actual `MqttWorker` (unchanged from the integration build), and confirm cards appear/update live as MQTT messages arrive — no QML-side code needed to make this happen beyond the `model: deviceModel` binding itself.
3. Add a `ListModel`-based "connection presets" list (broker host/port pairs a user might switch between) purely in QML — a deliberate, correct use of `ListModel`, since this data has no reason to live in C++ and never needs cross-thread signal delivery.

### Key Takeaways

- `ListModel` is for small, UI-only, QML-native data (settings, static lists) — not for live application data that already has a C++ home.
- Your existing C++ `QAbstractItemModel` (`DeviceTableModel`) works in QML with zero rewriting — add `roleNames()` and named-role branches in `data()` alongside your existing Widgets-facing role handling, don't replace it.
- `setContextProperty` is the direct mechanism for exposing a C++ object instance to QML — every QML file loaded by that engine can reference it by the given name.
- The same `beginInsertRows`/`dataChanged` discipline from Day 9/Widgets is exactly what makes QML views update live for free — this isn't new QML-specific work, it's the same correctness requirement paying off a second time.
- `QGuiApplication` (not `QApplication`) is correct for pure-QML apps; only reach for `QApplication` when deliberately mixing Widgets and QML in one process.

---

Say "next" for Day 5 (proper C++/QML integration patterns — `Q_INVOKABLE` methods, QML singletons for app-wide services, `qmlRegisterType`, and the real decision of when to mix Widgets and QML in one application versus keeping them separate).