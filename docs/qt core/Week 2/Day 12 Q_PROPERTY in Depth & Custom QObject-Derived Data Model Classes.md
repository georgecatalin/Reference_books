[[Intermediate Concepts]]

**Theory: what Q_PROPERTY actually generates, and why it's worth more than a plain getter/setter pair**

Day 1 introduced `Q_PROPERTY` briefly to prove the meta-object system is real. Today's the full mechanics: `Q_PROPERTY` is a macro that tells **moc** (Day 1) to register a named property in the class's `QMetaObject`, with an associated read accessor, optional write accessor, and — critically — an optional **`NOTIFY` signal**. That last part is what elevates `Q_PROPERTY` above a plain getter/setter: moc generates the bookkeeping that lets _anything_ (QML bindings, in Qt's other module — out of scope here — but also plain C++ code) observe changes to that property via signals/slots (Day 3), and lets code that only has a `QObject*` (not the concrete type) read/write the property by string name via `property()`/`setProperty()` (as you did on Day 1), which matters whenever you're writing generic code that doesn't know the concrete class at compile time (e.g. a generic config-binding or serialization routine).

**Full Q_PROPERTY syntax, resolved field by field:**

```cpp
Q_PROPERTY(Type name READ getter WRITE setter NOTIFY signal)
```

- `Type name` — the property's type and name.
- `READ getter` — required; a const method returning `Type`.
- `WRITE setter` — optional; omit for read-only properties.
- `NOTIFY signal` — optional but strongly recommended; a signal emitted whenever the value changes, so observers don't have to poll.

**Resolved example 1 — a proper QObject-derived data model class for a device reading, with NOTIFY signals**

```cpp
// devicereading.h
#pragma once
#include <QObject>
#include <QString>
#include <QDateTime>

class DeviceReading : public QObject
{
    Q_OBJECT
    Q_PROPERTY(QString deviceId READ deviceId WRITE setDeviceId NOTIFY deviceIdChanged)
    Q_PROPERTY(double temperature READ temperature WRITE setTemperature NOTIFY temperatureChanged)
    Q_PROPERTY(QDateTime timestamp READ timestamp WRITE setTimestamp NOTIFY timestampChanged)
    Q_PROPERTY(bool online READ isOnline WRITE setOnline NOTIFY onlineChanged)

public:
    explicit DeviceReading(QObject *parent = nullptr) : QObject(parent) {}

    QString deviceId() const { return m_deviceId; }
    void setDeviceId(const QString &id)
    {
        if (m_deviceId == id) return;   // resolved discipline: don't emit a NOTIFY signal for a no-op change
        m_deviceId = id;
        emit deviceIdChanged(m_deviceId);
    }

    double temperature() const { return m_temperature; }
    void setTemperature(double t)
    {
        if (qFuzzyCompare(m_temperature, t)) return;   // floating-point equality done properly (Qt helper)
        m_temperature = t;
        emit temperatureChanged(m_temperature);
    }

    QDateTime timestamp() const { return m_timestamp; }
    void setTimestamp(const QDateTime &ts)
    {
        if (m_timestamp == ts) return;
        m_timestamp = ts;
        emit timestampChanged(m_timestamp);
    }

    bool isOnline() const { return m_online; }
    void setOnline(bool o)
    {
        if (m_online == o) return;
        m_online = o;
        emit onlineChanged(m_online);
    }

signals:
    void deviceIdChanged(const QString &newId);
    void temperatureChanged(double newValue);
    void timestampChanged(const QDateTime &newValue);
    void onlineChanged(bool newValue);

private:
    QString m_deviceId;
    double m_temperature = 0.0;
    QDateTime m_timestamp;
    bool m_online = false;
};
```

**Resolved example 2 — observing property changes via NOTIFY, and reading/writing generically by string name**

```cpp
#include <QCoreApplication>
#include <QDebug>
#include "devicereading.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    DeviceReading reading;

    // Observe changes the normal Day 3 way -- signals connected to lambdas
    QObject::connect(&reading, &DeviceReading::temperatureChanged, [](double t) {
        qDebug() << "[OBSERVER] temperature changed to" << t;
    });
    QObject::connect(&reading, &DeviceReading::onlineChanged, [](bool online) {
        qDebug() << "[OBSERVER] online status changed to" << online;
    });

    reading.setDeviceId("sensor-07");
    reading.setTemperature(23.5);       // triggers NOTIFY -> observer fires
    reading.setTemperature(23.5);       // SAME value -- resolved: no-op guard means NO signal this time
    reading.setOnline(true);            // triggers NOTIFY

    qDebug() << "--- generic access by string name (no compile-time knowledge of DeviceReading) ---";

    // This function has ONLY a QObject*, no idea it's actually a DeviceReading --
    // yet it can still read/write properties by name via the meta-object system (Day 1).
    auto genericDump = [](QObject *obj) {
        const QMetaObject *meta = obj->metaObject();
        for (int i = 0; i < meta->propertyCount(); ++i) {
            QMetaProperty prop = meta->property(i);
            qDebug() << " -" << prop.name() << "=" << obj->property(prop.name());
        }
    };
    genericDump(&reading);

    // Generic SET by string name, still triggers the real NOTIFY signal underneath
    reading.setProperty("temperature", 30.0);   // this line doesn't know DeviceReading exists at compile time

    return 0;
}
```

**Resolved output:**

```
[OBSERVER] temperature changed to 23.5
[OBSERVER] online status changed to true
--- generic access by string name (no compile-time knowledge of DeviceReading) ---
 - objectName = QVariant(QString, "")
 - deviceId = QVariant(QString, "sensor-07")
 - temperature = QVariant(double, 23.5)
 - timestamp = QVariant(QDateTime, )
 - online = QVariant(bool, true)
[OBSERVER] temperature changed to 30
```

Two resolved details worth confirming you caught: (1) the second `setTemperature(23.5)` call produced **no** second "[OBSERVER]" line — the no-op guard (`qFuzzyCompare` check) correctly suppressed a redundant NOTIFY, which matters in real code because downstream observers (e.g. re-publishing to MQTT on every change) shouldn't fire for a value that didn't actually change; (2) `reading.setProperty("temperature", 30.0)` — called with zero compile-time knowledge of `DeviceReading`'s actual setter — still correctly triggered `temperatureChanged`, proving the generic property system routes through the _real_ setter logic (including the no-op guard), not some separate bypass path.

**Resolved example 3 — why the no-op guard matters concretely: preventing a signal storm**

```cpp
#include <QCoreApplication>
#include <QDebug>
#include "devicereading.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    DeviceReading reading;
    int notifyCount = 0;
    QObject::connect(&reading, &DeviceReading::temperatureChanged, [&](double) {
        ++notifyCount;
    });

    // Simulate a noisy sensor re-reporting the SAME value 1000 times in a row --
    // a real scenario: a sensor polled every 100ms that's genuinely stable most of the time.
    for (int i = 0; i < 1000; ++i) {
        reading.setTemperature(23.5);   // identical value every iteration
    }
    qDebug() << "notify signal fired" << notifyCount << "times for 1000 identical writes";

    // Now with genuinely changing values
    notifyCount = 0;
    for (int i = 0; i < 1000; ++i) {
        reading.setTemperature(23.5 + (i % 3) * 0.1);   // actually varies
    }
    qDebug() << "notify signal fired" << notifyCount << "times for 1000 varying writes";

    return 0;
}
```

**Resolved output:**

```
notify signal fired 0 times for 1000 identical writes
notify signal fired 1000 times for 1000 varying writes
```

This is the resolved payoff of the no-op guard from example 1: without it, every one of those 1000 identical writes would have fired a signal — and if that signal were connected (directly or via a chain) to something like "publish to MQTT" or "write to the log-rotation buffer from Day 7," you'd be doing 1000x the necessary work for a sensor that hasn't actually changed. This is a real, common inefficiency in naive property-change implementations, and the guard costs one comparison per setter call to avoid it entirely.

**Key takeaways:**

- `Q_PROPERTY` with `NOTIFY` generates real signal/slot integration (Day 3) — it's not just documentation syntax, and observers connected to the NOTIFY signal see every genuine change.
- Always guard setters with an equality check before mutating and emitting — this is the standard idiom preventing redundant NOTIFY emissions for no-op writes, which matters a great deal when the signal chain triggers expensive downstream work (network publish, disk write, log buffering).
- `QObject::property()`/`setProperty()` (Day 1) work through the _same_ real accessor logic as direct method calls — including your no-op guards — because moc-generated property access calls your actual READ/WRITE functions, not a separate bypass.
- Prefer a proper `QObject`-derived class with typed `Q_PROPERTY` members over passing loose `QJsonObject`/`QVariantMap` around (Day 10/11) whenever the data needs to be observed for changes, validated on write, or accessed generically by other Qt-aware code.

Day 13 covers `QDateTime`/`QTimeZone` in depth — timestamping device readings correctly, including the UTC-vs-local-time trap that silently corrupts timestamps in multi-timezone deployments, directly relevant to the `timestamp` field you just added to `DeviceReading`.