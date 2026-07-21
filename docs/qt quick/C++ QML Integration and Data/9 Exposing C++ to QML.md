[[C++ QML Integrations and Data]]

# Day 9 — Exposing C++ to QML: `Q_PROPERTY`, `Q_INVOKABLE`, and Context Properties

This is the day everything changes. Up to now, "connection status" and "device list" were fake — `ListModel` placeholders and a toggled boolean. From here, QML becomes a _view_ onto real C++ objects: your actual `mqtt_monitor` logic, serial data, MQTT client, SQLite records. This is the single most important day in the entire course for your use case.

## Concept: The three ways C++ reaches QML

1. **Context properties** — register a C++ object instance into the QML engine's root context; QML sees it as a global-ish named object. Simple, works everywhere, slightly discouraged for large apps (harder to reason about scope) but perfectly fine and common for app-level singletons like a connection manager.
2. **`qmlRegisterType`** — register a C++ _class_ so QML can instantiate it directly as a QML type (`MyClass { }`), like any other component. Better for reusable, instantiable-many-times objects.
3. **Singletons via `QML_SINGLETON`** — a C++ class QML imports and uses without instantiation, ideal for one true global (a config manager, a device registry).

Today: context properties, the simplest bridge, and enough to get a real object into your UI. Day 10 covers `qmlRegisterType` and singletons properly.

## Concept: `Q_PROPERTY` — the macro that makes a C++ member bindable in QML

```cpp
// brokerconnection.h
#pragma once
#include <QObject>

class BrokerConnection : public QObject
{
    Q_OBJECT
    Q_PROPERTY(bool connected READ connected NOTIFY connectedChanged)
    Q_PROPERTY(QString host READ host WRITE setHost NOTIFY hostChanged)
    Q_PROPERTY(int deviceCount READ deviceCount NOTIFY deviceCountChanged)

public:
    explicit BrokerConnection(QObject *parent = nullptr);

    bool connected() const { return m_connected; }
    QString host() const { return m_host; }
    void setHost(const QString &host);
    int deviceCount() const { return m_deviceCount; }

    Q_INVOKABLE void connectToBroker();
    Q_INVOKABLE void disconnectFromBroker();

signals:
    void connectedChanged();
    void hostChanged();
    void deviceCountChanged();

private:
    bool m_connected = false;
    QString m_host = "localhost";
    int m_deviceCount = 0;
};
```

**Read this macro carefully — every clause matters:**

- `READ connected` — the getter QML calls whenever it evaluates a binding referencing `connected`
- `WRITE setHost` — only needed if QML should be able to _assign_ to it (`host: "192.168.1.5"` from QML, or two-way binding to a `TextField.text`)
- `NOTIFY connectedChanged` — **this is the part beginners skip and then can't figure out why their UI doesn't update.** Without a `NOTIFY` signal fired every time the underlying value actually changes, QML's binding system has no way to know it needs to re-evaluate. A `Q_PROPERTY` without `NOTIFY` is _readable once_ but **not reactive** — QML will show a stale value forever after the first read if you forget this, or forget to emit it.

```cpp
// brokerconnection.cpp
void BrokerConnection::setHost(const QString &host)
{
    if (m_host == host)
        return;  // avoid redundant notify — cheap guard, always do this
    m_host = host;
    emit hostChanged();   // THIS is what makes QML bindings re-evaluate
}

void BrokerConnection::connectToBroker()
{
    // Real implementation later wraps an MQTT client (Day 16)
    m_connected = true;
    emit connectedChanged();
    m_deviceCount = 3;
    emit deviceCountChanged();
}
```

**`Q_INVOKABLE`** exposes a normal C++ method as callable _from_ QML — `Q_INVOKABLE void connectToBroker()` becomes `brokerConnection.connectToBroker()` in QML. Without `Q_INVOKABLE` (or being a `slot`), QML simply cannot see or call the method — this is the other classic beginner gotcha, "why can't QML see my function," answer: you forgot the macro.

## Concept: Registering the object as a context property

```cpp
// main.cpp
#include <QGuiApplication>
#include <QQmlApplicationEngine>
#include <QQmlContext>
#include "brokerconnection.h"

int main(int argc, char *argv[])
{
    QGuiApplication app(argc, argv);

    BrokerConnection brokerConnection;

    QQmlApplicationEngine engine;
    engine.rootContext()->setContextProperty("brokerConnection", &brokerConnection);

    engine.load(QUrl(u"qrc:/main.qml"_qs));
    return app.exec();
}
```

`setContextProperty("brokerConnection", &brokerConnection)` — the string is the name QML will use. From here, `brokerConnection` is available as if it were a global object in every QML file loaded by this engine.

**Critical lifetime note, and this matters given your C++ background**: `brokerConnection` here is a **stack object in `main`**, and we pass a raw pointer. This is intentional and correct _as long as it outlives the QML engine_ — since it's declared before `engine` and both live for the duration of `main`, destruction order (reverse of construction) ensures the engine is destroyed first. If you instead `new` a `BrokerConnection` and never manage its lifetime, or destroy it before the engine, QML will hold a dangling pointer and crash on next property access. Don't let QML take ownership casually — you're explicitly bridging two lifetime models here (Qt's parent-ownership tree and the QML engine's reference), and getting this wrong is the most common cause of a "works then randomly crashes" bug in real Qt apps.

## Using it from QML

```qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts

ApplicationWindow {
    width: 400; height: 200; visible: true

    ColumnLayout {
        anchors.fill: parent
        anchors.margins: 16

        Label {
            text: brokerConnection.connected
                ? "Connected to " + brokerConnection.host
                : "Disconnected"
            color: brokerConnection.connected ? "#a6e3a1" : "#f38ba8"
        }

        Label { text: "Devices: " + brokerConnection.deviceCount }

        TextField {
            Layout.fillWidth: true
            text: brokerConnection.host
            onEditingFinished: brokerConnection.host = text
        }

        Button {
            text: brokerConnection.connected ? "Disconnect" : "Connect"
            onClicked: brokerConnection.connected
                ? brokerConnection.disconnectFromBroker()
                : brokerConnection.connectToBroker()
        }
    }
}
```

Notice: **no `ListModel`, no fake JS array, no toggled local boolean.** `brokerConnection.connected` is a live binding straight into a real C++ object. When `connectToBroker()` eventually does real MQTT work (Day 16) and emits `connectedChanged()` from a background thread callback, this exact QML — unchanged — reflects it. This is the payoff of the whole model/view/binding investment from Phase 1.

## `CMakeLists.txt` update

```cmake
qt_add_executable(appMonitor
    main.cpp
    brokerconnection.cpp
    brokerconnection.h
)
```

## Exercise

1. Build `BrokerConnection` exactly as above, wire it up, and confirm: click Connect, watch the label, host field, and button text all update — with **zero QML code checking or polling** anything, purely from `NOTIFY` signals firing.
2. Deliberately remove `NOTIFY connectedChanged` from the `Q_PROPERTY` declaration (but keep emitting the signal in C++). Rebuild, click Connect, and confirm the UI does _not_ update even though the C++ state changed correctly — prove to yourself this failure mode exists so you recognize it instantly in real debugging later.
3. Add a `Q_INVOKABLE QString statusSummary() const` method returning a formatted string combining `connected`/`host`/`deviceCount`, and call it from a `Label` binding: `text: brokerConnection.statusSummary()`. Note: since this isn't a `Q_PROPERTY`, it won't auto-update on change — call it from inside a binding that also references a `Q_PROPERTY` (e.g. concatenate with `brokerConnection.connected` in the same expression) so the binding re-evaluates when _that_ changes, and observe that the invokable's return value updates as a side effect. This is a real, common pattern — but flag in a comment why a proper `Q_PROPERTY` would be more correct than piggybacking on another property's notify.
4. Guard `setHost` against being set to an empty string (return early, don't emit) — confirm from QML that assigning `""` is silently ignored rather than corrupting state.

## Key takeaways

- `Q_PROPERTY` needs `READ` (always), `WRITE` (only if QML should assign), and **`NOTIFY`** — forgetting `NOTIFY` (or forgetting to `emit` it) is the single most common "my QML won't update" bug, and it fails silently.
- `Q_INVOKABLE` (or declaring as a `slot`) is required for QML to call a C++ method at all — no macro, no visibility.
- Context properties (`setContextProperty`) are the simplest C++→QML bridge — a named global-ish object, fine for one app-level object like a connection manager.
- Lifetime matters: the C++ object must outlive the QML engine using it. You're bridging two different ownership models — be deliberate, don't leave it to chance.
- Always guard setters against redundant/invalid writes before emitting `NOTIFY` — cheap correctness habit, avoids needless re-binding churn too.

Say next for Day 10 — `qmlRegisterType`, QML singletons, and the ownership/ QQmlEngine::ObjectOwnership rules that decide who destroys what.