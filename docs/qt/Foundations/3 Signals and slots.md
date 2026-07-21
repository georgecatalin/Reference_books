[[Foundations]]

## Day 3: Signals & Slots In Depth — Connection Types, Custom Signals, and Why This Becomes Critical With Threads

### Concept: Signals/Slots Are a Decoupling Mechanism, Not Just "Callbacks"

You've been using `connect()` since Day 1 without really seeing what it buys you. The key insight: **the emitting object never knows who's listening, or how many listeners there are.** A `QPushButton` emitting `clicked()` has zero knowledge of what's connected to it — could be 0 slots, could be 5, could be lambdas, could be objects that don't even exist yet at compile time. This is real decoupling, not just syntactic sugar over function pointers.

This matters enormously for `mqtt_monitor`: your MQTT client, your serial reader, and your SQLite writer can all be completely unaware of each other. They just emit signals; the GUI (or anything else) connects to them.

### The Part That Actually Matters: Connection Types

This is where most Qt beginners get hurt later, especially once threads are involved (Day 17+ territory, but you need the concept now). `connect()` takes an optional last argument, `Qt::ConnectionType`:

|Type|Behavior|
|---|---|
|`Qt::AutoConnection` (default)|Direct if same thread, Queued if different threads|
|`Qt::DirectConnection`|Slot runs **immediately**, synchronously, in the emitter's thread|
|`Qt::QueuedConnection`|Slot call is **posted as an event** to the receiver's thread's event loop, runs later, asynchronously|
|`Qt::BlockingQueuedConnection`|Like Queued, but emitter blocks until the slot finishes (deadlock risk if same thread)|

Right now, everything you've built is single-threaded, so `AutoConnection` always resolves to `DirectConnection` — the slot runs synchronously, in-line, like a normal function call. **This will change the moment you add a QThread for serial/MQTT I/O (Day 17-19)**, so building the correct mental model now saves you from confusing bugs later: when a signal crosses a thread boundary, Qt automatically switches to queued delivery, and the slot doesn't run until the receiving thread's event loop next spins.

### Annotated Code: Custom Signals + Connection Patterns

`devicemonitor.h`:

```cpp
#pragma once
#include <QObject>
#include <QString>

// A plain QObject with no UI — this models a piece of business logic
// (stand-in for what will become your real MQTT/serial handler).
class DeviceMonitor : public QObject {
    Q_OBJECT
public:
    explicit DeviceMonitor(QObject *parent = nullptr);

    void simulateReading(const QString &deviceId, double temperature);

signals:
    // Signals are declarations only — moc generates the emit machinery.
    // You never write a body for a signal.
    void temperatureUpdated(const QString &deviceId, double temperature);
    void deviceWentOffline(const QString &deviceId);
    void alertRaised(const QString &message); // no receiver assumed

public slots:
    void onResetRequested(); // slots CAN be public, private, or protected
};
```

`devicemonitor.cpp`:

```cpp
#include "devicemonitor.h"

DeviceMonitor::DeviceMonitor(QObject *parent) : QObject(parent) {}

void DeviceMonitor::simulateReading(const QString &deviceId, double temperature) {
    emit temperatureUpdated(deviceId, temperature);

    if (temperature > 80.0) {
        emit alertRaised(QString("Device %1 overheating: %2C").arg(deviceId).arg(temperature));
    }
    if (temperature < -40.0) { // sentinel for "sensor fault" in this toy example
        emit deviceWentOffline(deviceId);
    }
}

void DeviceMonitor::onResetRequested() {
    // real implementation would clear internal state
}
```

Wiring it up in `mainwindow.cpp` (add to constructor):

```cpp
#include "devicemonitor.h"

// ... inside MainWindow constructor, after panel setup:

auto *monitor = new DeviceMonitor(this); // parented -> lifetime tied to window

// 1. Standard member-function slot connection
connect(monitor, &DeviceMonitor::temperatureUpdated,
        this, &MainWindow::onTemperatureUpdated);

// 2. Lambda as a slot — extremely common in real Qt code, avoids
//    boilerplate for small reactions. Capture 'this' carefully (see below).
connect(monitor, &DeviceMonitor::alertRaised, this, [this](const QString &msg) {
    logView->append("[ALERT] " + msg);
});

// 3. Signal-to-signal connection — yes, this is legal and common.
//    deviceWentOffline can directly re-trigger another signal without
//    an intermediate slot at all.
connect(monitor, &DeviceMonitor::deviceWentOffline,
        this, &MainWindow::deviceOfflineDetected); // MainWindow's own signal

// 4. Explicit connection type — force queued even though same thread,
//    useful for breaking up long call chains or deferring work to
//    "after the current event finishes"
connect(monitor, &DeviceMonitor::temperatureUpdated,
        this, &MainWindow::onTemperatureUpdated,
        Qt::QueuedConnection);

// Trigger it for testing
monitor->simulateReading("device-01", 85.0);
```

Add the slot and signal to `mainwindow.h`:

```cpp
signals:
    void deviceOfflineDetected(const QString &deviceId);

private slots:
    void onTemperatureUpdated(const QString &deviceId, double temp);
```

```cpp
void MainWindow::onTemperatureUpdated(const QString &deviceId, double temp) {
    logView->append(QString("[TEMP] %1: %2C").arg(deviceId).arg(temp));
}
```

### The Lambda Capture Trap (Read This Twice)

```cpp
connect(monitor, &DeviceMonitor::alertRaised, this, [this](const QString &msg) {
    logView->append("[ALERT] " + msg);
});
```

The **third argument** (`this` before the lambda) is the **context object**. This is not optional if your lambda touches `this` or any Qt object — it tells Qt "if `this` (the MainWindow) is destroyed, automatically disconnect and never call this lambda." Without a context object:

```cpp
// DANGEROUS: no context object
connect(monitor, &DeviceMonitor::alertRaised, [this](const QString &msg) {
    logView->append("[ALERT] " + msg); // use-after-free if MainWindow is destroyed
                                          // but monitor outlives it
});
```

This is a real, common source of crashes in production Qt apps — a dangling `this` captured in a lambda whose connection was never cleaned up. Always pass a context object when your lambda captures anything with a lifetime.

### Disconnecting

```cpp
disconnect(monitor, &DeviceMonitor::temperatureUpdated, this, &MainWindow::onTemperatureUpdated);
```

In practice, you rarely call this manually — Qt auto-disconnects when either endpoint (sender or receiver/context) is destroyed. Manual `disconnect()` is mostly for toggling behavior at runtime (e.g., pausing a UI update temporarily).

### Why This Matters for `mqtt_monitor`

Your eventual architecture: an MQTT client object and a serial reader object, each on their own `QThread`, emitting signals like `messageReceived(topic, payload)`. The GUI thread connects to those signals normally — Qt handles the cross-thread queued delivery for you, automatically, correctly, with no manual mutex/condvar work on your part _as long as you only communicate via signals/slots and don't touch shared QObjects directly across threads_. This is the single biggest reason Qt is genuinely good for this kind of multi-threaded I/O + GUI application — you get thread-safe message passing for free, provided you respect the pattern.

### Exercise

1. Add a second listener to `temperatureUpdated` — a `QLabel` that just shows the _last_ temperature reading, separate from the log. Confirm both slots fire from one `emit`.
2. Deliberately create the dangling-lambda bug: connect a lambda with no context object that touches a widget, then find a way to destroy that widget (e.g., conditionally skip creating it, or delete it manually) while the emitter still lives, and observe the crash under `-fsanitize=address`. Then fix it by adding the context object and watch the crash disappear.
3. Change the `Qt::QueuedConnection` in example #4 to log a timestamp inside `onTemperatureUpdated`, and compare — even in a single-threaded app — whether it prints before or after code that comes immediately after `simulateReading()` in the constructor. This makes the "queued = deferred to the event loop" behavior concrete rather than abstract.

### Key Takeaways

- Signals/slots decouple emitter from receiver completely — the emitter has zero knowledge of listeners.
- Connection type (`Direct` vs `Queued`) determines _when_ a slot runs relative to the emit call — same-thread defaults to Direct (synchronous); cross-thread defaults to Queued (asynchronous, event-loop-scheduled).
- Always pass a context object to lambda connections that touch object state — it ties the connection's lifetime to that object and prevents dangling calls.
- Signal-to-signal connections are legal and idiomatic for propagating events without intermediate slots.
- This mechanism is _the_ reason Qt's threading model will feel much safer than raw `std::thread` + mutex work once you get to Day 17+ — cross-thread signal emission is handled for you.

---

Say "next" for Day 4 (the event system underneath signals/slots: `QEvent`, event filters, and overriding `event()`/`paintEvent()` — the layer below signals/slots that governs input, painting, and custom widget behavior).