[[Foundations]]

**Theory: what actually happens on `connect()` and `emit`**

From Day 1, you know signals and slots aren't a C++ language feature — they're ordinary methods with moc-generated plumbing. Here's precisely what that plumbing does:

- **`QObject::connect(sender, signal, receiver, slot)`** registers an entry in an internal table owned by `sender` (technically shared connection data). Each entry records: which signal, which receiver, which slot, and a **connection type**.
- **`emit signal(args)`** (or, in modern Qt, just calling the signal like a function) expands into moc-generated code that walks that table for this signal, and for each connected entry, invokes the slot — but _how_ it invokes it depends on the connection type.

**Connection types** (the `Qt::ConnectionType` enum, passed as an optional 5th argument to `connect()`):

- **`Qt::DirectConnection`** — the slot is called **immediately**, synchronously, as a plain function call, on whatever thread `emit` happened to run on. No event loop involved at all.
- **`Qt::QueuedConnection`** — the call is packaged as a `QMetaCallEvent` and **posted** to the receiver's thread's event queue. The slot only actually executes later, when that thread's event loop (from Day 2) gets around to processing it.
- **`Qt::AutoConnection`** (the default when you don't specify) — Qt checks at connect-time whether sender and receiver live on the same thread. Same thread → behaves as Direct. Different threads → behaves as Queued. This auto-detection is _why_ cross-thread signal/slot delivery "just works" in Qt without you manually posting events — but it's also why get single-threaded code and multi-threaded code can behave subtly differently even though the `connect()` call looks identical. We'll stress-test this properly in Week 3; for today, everything is single-threaded, so every connection below is effectively Direct regardless of which type you specify.

**Resolved example 1 — one signal, multiple slots; one slot, multiple signals**

```cpp
// sensor.h
#pragma once
#include <QObject>

class Sensor : public QObject
{
    Q_OBJECT
public:
    explicit Sensor(QObject *parent = nullptr) : QObject(parent) {}

    void takeReading(double value)
    {
        m_lastValue = value;
        emit readingChanged(value);          // signal #1
        if (value > m_threshold)
            emit thresholdExceeded(value);   // signal #2 -- can fire alongside #1
    }

    void setThreshold(double t) { m_threshold = t; }

signals:
    void readingChanged(double value);
    void thresholdExceeded(double value);

private:
    double m_lastValue = 0.0;
    double m_threshold = 30.0;
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include <QDebug>
#include "sensor.h"

void logReading(double v)  // a free function can be a slot target too -- doesn't need to be a QObject method
{
    qDebug() << "[LOG] reading:" << v;
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    Sensor sensor;
    sensor.setThreshold(30.0);

    // one signal -> two slots
    QObject::connect(&sensor, &Sensor::readingChanged, logReading);
    QObject::connect(&sensor, &Sensor::readingChanged, [](double v) {
        qDebug() << "[UI] would update display to:" << v;
    });

    // two DIFFERENT signals -> the same slot (a lambda here, but same principle applies to any slot)
    auto alert = [](double v) { qDebug() << "[ALERT] threshold event, value =" << v; };
    QObject::connect(&sensor, &Sensor::thresholdExceeded, alert);

    sensor.takeReading(22.0);   // only readingChanged fires -> both its slots run
    sensor.takeReading(35.0);   // BOTH signals fire -> three total slot calls

    return 0;
}
```

**Resolved output:**

```
[LOG] reading: 22
[UI] would update display to: 22
[LOG] reading: 35
[UI] would update display to: 35
[ALERT] threshold event, value = 35
```

This demonstrates both fan-out patterns explicitly: `readingChanged` alone drives two independent listeners, and a single `takeReading(35.0)` call cascades into three total slot invocations across two different signals — with zero coupling between `Sensor` and any of its listeners. `Sensor` has no idea `logReading`, the UI lambda, or `alert` exist.

**Resolved example 2 — connection type made visible, and why it matters even single-threaded**

```cpp
#include <QCoreApplication>
#include <QDebug>
#include <QObject>

class Emitter : public QObject
{
    Q_OBJECT
public:
    void fire() { emit ping(); }
signals:
    void ping();
};

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    Emitter emitter;

    // DirectConnection: slot runs INLINE, before fire() itself returns.
    QObject::connect(&emitter, &Emitter::ping, &emitter, [] {
        qDebug() << "direct slot running";
    }, Qt::DirectConnection);

    // QueuedConnection: slot is deferred to the event loop, even though
    // sender and receiver are on the same thread here.
    QObject::connect(&emitter, &Emitter::ping, &emitter, [] {
        qDebug() << "queued slot running";
    }, Qt::QueuedConnection);

    qDebug() << "before fire()";
    emitter.fire();
    qDebug() << "after fire(), before exec()";

    QTimer::singleShot(0, &app, &QCoreApplication::quit);  // quit as soon as the loop is idle
    return app.exec();
}
```

**Resolved output:**

```
before fire()
direct slot running
after fire(), before exec()
queued slot running
```

This is the resolution worth sitting with: **`fire()` returns before the queued slot ever runs**, because a queued connection doesn't call the slot at all during `emit` — it just posts an event and moves on. The direct slot, by contrast, executes synchronously as part of `fire()`'s call stack, finishing _before_ `fire()` returns. Even in a single-threaded program, connection type controls _when_ a slot runs relative to the code that triggered it — this is the seed of understanding cross-thread behavior in Week 3, where it becomes the difference between a slot running on the wrong thread (bug) and the right one (correct).

**Resolved example 3 — disconnecting, and connection lifetime tied to QObject destruction**

```cpp
#include <QCoreApplication>
#include <QDebug>
#include "sensor.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    Sensor sensor;

    // connect() returns a QMetaObject::Connection handle -- can be used to disconnect later
    QMetaObject::Connection conn = QObject::connect(&sensor, &Sensor::readingChanged, [](double v) {
        qDebug() << "listener A saw:" << v;
    });

    QObject::connect(&sensor, &Sensor::readingChanged, [](double v) {
        qDebug() << "listener B saw:" << v;
    });

    sensor.takeReading(10.0);   // both listeners fire

    QObject::disconnect(conn);  // explicitly remove listener A only
    sensor.takeReading(20.0);   // only listener B fires now

    return 0;
}
```

**Resolved output:**

```
listener A saw: 10
listener B saw: 10
listener B saw: 20
```

Note also (not shown in output, but important and worth stating explicitly): if `sensor` itself were destroyed, **every** connection where it's sender or receiver is automatically removed — this is handled by `~QObject()`, using the same parent/child and connection-tracking machinery from Day 1's meta-object system. This is why, in real service code, you generally don't need to manually disconnect every lambda before an object dies — only when you need to remove _one specific_ connection while the object stays alive, as shown above.

**Key takeaways:**

- `connect()` doesn't call anything — it registers an entry in a table. `emit` walks that table and invokes each connected slot according to its connection type.
- `Qt::DirectConnection` = synchronous inline call. `Qt::QueuedConnection` = deferred, delivered later via the event loop from Day 2. `Qt::AutoConnection` (default) picks based on sender/receiver thread affinity.
- Fan-out is symmetric: one signal can drive many slots, and one slot can be driven by many different signals — with zero compile-time coupling between the emitting class and its listeners.
- `connect()` returns a handle you can pass to `disconnect()` for surgical removal; QObject destruction auto-severs all its connections without manual bookkeeping.

Day 4 continues directly from here: QTimer, which you've been using informally in every example so far as `QTimer::singleShot` — now we'll cover the full `QTimer` object (repeating intervals, precision modes, `start()`/`stop()`, and how a `QTimer` itself is just a `QObject` emitting `timeout()` through the exact mechanism you just learned).