[[9 Exposing C++ to QML]]

# Day 10 — `qmlRegisterType`, Singletons, and Ownership Rules

Day 9 used a context property — fine for one global connection object, but it doesn't scale to "give me a new `DeviceModel` instance per window" or "expose a reusable type other QML files can instantiate directly." Today: registering actual QML _types_ from C++, singletons done properly, and the ownership rules that decide who's responsible for destroying what — which matters a lot once QML starts creating C++ objects itself.

## Concept: `qmlRegisterType` — making a C++ class instantiable from QML

Instead of one pre-made instance handed in via context property, you register the _class_, and QML can write `DeviceMonitor { }` like any built-in type — including multiple independent instances.

```cpp
// devicemonitor.h
#pragma once
#include <QObject>

class DeviceMonitor : public QObject
{
    Q_OBJECT
    QML_ELEMENT
    Q_PROPERTY(QString deviceId READ deviceId WRITE setDeviceId NOTIFY deviceIdChanged)
    Q_PROPERTY(bool online READ online NOTIFY onlineChanged)

public:
    explicit DeviceMonitor(QObject *parent = nullptr);

    QString deviceId() const { return m_deviceId; }
    void setDeviceId(const QString &id);
    bool online() const { return m_online; }

    Q_INVOKABLE void ping();

signals:
    void deviceIdChanged();
    void onlineChanged();

private:
    QString m_deviceId;
    bool m_online = false;
};
```

**`QML_ELEMENT`** is the modern Qt 6 way to register a type — it's a marker macro that the build system's meta-object compiler picks up automatically, _if_ your `CMakeLists.txt` QML module is set up correctly (see below). This replaced the older, more manual `qmlRegisterType<DeviceMonitor>("MyModule", 1, 0, "DeviceMonitor")` call from Qt 5 — you may see that pattern in older tutorials/StackOverflow answers; `QML_ELEMENT` is preferred in Qt 6 and far less boilerplate.

**`CMakeLists.txt`** — the `qt_add_qml_module` call from Day 1 needs the sources included so the QML type registration system can find `QML_ELEMENT`:

```cmake
qt_add_qml_module(appMonitor
    URI MonitorApp
    VERSION 1.0
    QML_FILES main.qml
    SOURCES devicemonitor.cpp devicemonitor.h
)
```

**Using it from QML** — import your module's URI, then instantiate freely:

```qml
import QtQuick
import MonitorApp

Item {
    DeviceMonitor {
        id: dev1
        deviceId: "esp32-04"
    }
    DeviceMonitor {
        id: dev2
        deviceId: "rpi-monitor-01"
    }

    Component.onCompleted: dev1.ping()
}
```

Each `DeviceMonitor { }` block creates a genuinely separate C++ object — this is the key difference from Day 9's context property, which gave you exactly one shared instance. Use `qmlRegisterType`/`QML_ELEMENT` when QML needs to create N independent instances (e.g., if each row in a device list needed its own live monitor object rather than just reading fields off a shared model — in practice for `mqtt_monitor` you'll more often want one central manager, but the pattern matters for things like a reusable custom `Timer`-like utility type, or per-window state objects).

## Concept: Singletons — one true global, QML-native

For your actual use case — one MQTT connection manager, one config object — a **singleton** is more correct than either a context property or a plain registered type instantiated once by convention:

```cpp
// appconfig.h
#pragma once
#include <QObject>

class AppConfig : public QObject
{
    Q_OBJECT
    QML_ELEMENT
    QML_SINGLETON
    Q_PROPERTY(QString brokerHost READ brokerHost WRITE setBrokerHost NOTIFY brokerHostChanged)

public:
    explicit AppConfig(QObject *parent = nullptr);
    QString brokerHost() const { return m_brokerHost; }
    void setBrokerHost(const QString &host);

signals:
    void brokerHostChanged();

private:
    QString m_brokerHost = "localhost";
};
```

```qml
import MonitorApp

Label {
    text: "Broker: " + AppConfig.brokerHost   // note: no instantiation, used by TYPE NAME
}
```

`QML_SINGLETON` combined with `QML_ELEMENT` means QML never writes `AppConfig { }` — it references `AppConfig` directly as if it were a namespace/module-level object, and Qt guarantees exactly one instance exists per QML engine. This is the correct tool for exactly the kind of object you'll build in Day 16 (a single `MqttClient` the whole app shares) — better than Day 9's context property because it's discoverable via `import`, doesn't require manual wiring in `main.cpp`, and is enforced as a true singleton rather than "just one instance by convention."

**When to choose which (concrete rule for your project):**

- **Context property** (Day 9) — fine for quick prototyping, or a single object that genuinely needs C++-side setup logic in `main()` before QML loads (e.g., reading a config file to construct it).
- **`QML_ELEMENT`** (registered type) — QML needs to create multiple independent instances.
- **`QML_SINGLETON`** — exactly one app-wide instance, and you want it clean, importable, and enforced at the type-system level. **This is what `MqttClient`, `DatabaseManager`, and `AppConfig` should be in your real app.**

## Concept: Ownership — who destroys what, and why it matters

This is where your C++ background actually helps, but also where a specific Qt/QML rule can surprise you. Qt tracks ownership via `QQmlEngine::ObjectOwnership`, with two values:

- **`CppOwnership`** — C++ manages the object's lifetime; QML will never delete it. Default for objects registered as context properties or returned from `Q_INVOKABLE` methods **if** they have a QObject parent already.
- **`JavaScriptOwnership`** — the QML/JS garbage collector owns it; it can be destroyed automatically when unreferenced. Default for objects **created directly in QML** via `qmlRegisterType`/`QML_ELEMENT` instantiation (`DeviceMonitor { }` in QML _is_ JS-owned by default).

**The gotcha**: if a `Q_INVOKABLE` C++ method returns a _newly heap-allocated_ `QObject*` with no parent, QML's garbage collector may take ownership and delete it whenever it looks unreferenced — including while C++ still holds a raw pointer to it elsewhere. This is a real, subtle memory-safety bug category unique to the C++/QML boundary. The fix is either: give the returned object a QObject parent (then `CppOwnership` applies, C++ tree governs it), or explicitly set ownership:

```cpp
Q_INVOKABLE DeviceMonitor* createMonitor(const QString &id)
{
    auto *monitor = new DeviceMonitor(this);   // parented to `this` → CppOwnership, safe
    monitor->setDeviceId(id);
    return monitor;
}
```

If you ever need to hand QML a genuinely unparented object and _want_ it garbage collected when QML's done with it, that's fine too — just do it deliberately:

```cpp
QQmlEngine::setObjectOwnership(monitor, QQmlEngine::JavaScriptOwnership);
```

**Rule to internalize**: if C++ retains any raw pointer to an object it also hands to QML, that object must be `CppOwnership` (parented) — never leave a QObject C++ still references without a parent when it crosses into QML's world. This is the same discipline as your general C++ ownership instincts, just applied across a boundary where the "other side" is a garbage collector instead of another piece of your own code.

## Exercise

1. Convert Day 9's `BrokerConnection` from a context property to a `QML_SINGLETON`. Update `main.qml` to use `import MonitorApp` and reference it by type name, remove the `setContextProperty` call from `main.cpp` entirely, and confirm it still works identically from the QML side.
2. Build the `DeviceMonitor` class above as a plain `QML_ELEMENT` (not singleton), instantiate three of them in `main.qml` with different `deviceId` values, and confirm each has independent state.
3. Add a `Q_INVOKABLE DeviceMonitor* clone() const` method to `DeviceMonitor` that returns a _new_, unparented copy. Call it from QML, and — deliberately — do nothing with C++'s side to retain a reference. Then add a second version that parents the clone to `this` instead, and in a comment explain which one is safe if C++ later needs to keep a raw pointer to the clone around, and why.
4. Read (don't need to implement) about `QQmlEngine::ObjectOwnership` in the Qt docs briefly, specifically confirming: objects created via `new SomeType()` and returned from `Q_INVOKABLE` with **no parent** default to `JavaScriptOwnership` the moment QML touches them — write one sentence in your notes confirming you understand why this could cause a use-after-free if C++ also held a raw pointer.

## Key takeaways

- `QML_ELEMENT` (Qt 6) replaces manual `qmlRegisterType<>()` calls — requires the class listed in `qt_add_qml_module`'s `SOURCES`.
- `QML_ELEMENT` = QML can instantiate multiple independent objects; `QML_SINGLETON` = exactly one, referenced by type name, no instantiation syntax.
- For your real app: `MqttClient`, `DatabaseManager`, `AppConfig` should be `QML_SINGLETON`s — one true instance, cleanly discoverable via `import`.
- Ownership crosses a real boundary at the C++/QML line: unparented `QObject*` handed to QML defaults to `JavaScriptOwnership` (can be GC'd); parented objects stay `CppOwnership`. If C++ retains a raw pointer to something also given to QML, it **must** be parented — otherwise you have a live use-after-free risk, not a theoretical one.
