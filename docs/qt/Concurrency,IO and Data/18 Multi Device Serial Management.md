[[Concurrency]]

## Day 18: Multi-Device Serial Management — Running Several Workers Concurrently

### Concept: One Worker Per Device, Not One Worker Managing Many Ports

Real `mqtt_monitor` deployments will have multiple boards connected simultaneously (several BeagleBones/RPis on different `/dev/ttyUSB*` or `/dev/ttyACM*` nodes). The temptation is to write one clever worker that internally loops over multiple `QSerialPort` instances. **Resist this.** The correct, simple pattern: **one `SerialWorker` + one `QThread` pair per physical device**, all independent, all following the exact Day 16/17 skeleton unchanged. This isn't just "simpler to write" — it's also more robust: if one device's port hangs or errors, it doesn't affect the others' threads or event loops at all, since they're fully isolated.

This is a direct application of something you already know from Python asyncio (one task per connection) and from general systems design (isolate failure domains) — Qt threading rewards the same instinct.

### Annotated Code: A `SerialManager` — Owns the Collection, Not the I/O Logic

`serialmanager.h`:

```cpp
#pragma once
#include <QObject>
#include <QMap>
#include <QThread>
#include "serialworker.h"

// SerialManager owns the *collection* of worker/thread pairs and their
// lifecycle — it does NOT do any I/O itself. Clean separation: SerialWorker
// knows how to talk to one port; SerialManager knows how to manage many.
class SerialManager : public QObject {
    Q_OBJECT
public:
    explicit SerialManager(QObject *parent = nullptr);
    ~SerialManager(); // must ensure clean shutdown of all workers — see below

    void addDevice(const QString &portName, int baudRate);
    void removeDevice(const QString &portName);
    QStringList activeDevices() const;

signals:
    // Re-emitted from whichever worker produced them, tagged with source port,
    // so consumers (MainWindow) get ONE signal surface regardless of device count
    void lineReceived(QString portName, QString line);
    void errorOccurred(QString portName, QString message);
    void connectionStateChanged(QString portName, bool connected);

private:
    struct DeviceEntry {
        QThread *thread;
        SerialWorker *worker;
    };
    QMap<QString, DeviceEntry> devices; // keyed by port name
};
```

`serialmanager.cpp`:

```cpp
#include "serialmanager.h"

SerialManager::SerialManager(QObject *parent) : QObject(parent) {}

SerialManager::~SerialManager() {
    // Shut down every device cleanly on destruction — don't rely on the
    // app quitting to sort this out; be explicit, since dangling threads
    // during shutdown are a classic source of crash-on-exit reports
    const QStringList ports = devices.keys();
    for (const QString &port : ports) {
        removeDevice(port);
    }
}

void SerialManager::addDevice(const QString &portName, int baudRate) {
    if (devices.contains(portName)) {
        return; // already managing this port — don't double-add
    }

    auto *thread = new QThread(this);
    auto *worker = new SerialWorker(portName, baudRate); // no parent, per Day 16 rule

    worker->moveToThread(thread);

    connect(thread, &QThread::started, worker, &SerialWorker::start);

    // Re-tag every signal with the port name before forwarding — this is
    // the key piece of "management" logic: the manager's consumers don't
    // need to know or care how many devices exist, they just get
    // (portName, data) pairs regardless of source
    connect(worker, &SerialWorker::lineReceived, this,
            [this, portName](const QString &line) {
        emit lineReceived(portName, line);
    });
    connect(worker, &SerialWorker::errorOccurred, this,
            [this, portName](const QString &msg) {
        emit errorOccurred(portName, msg);
    });
    connect(worker, &SerialWorker::connectionStateChanged, this,
            [this, portName](bool connected) {
        emit connectionStateChanged(portName, connected);
    });

    connect(worker, &SerialWorker::finished, thread, &QThread::quit);
    connect(thread, &QThread::finished, worker, &QObject::deleteLater);
    connect(thread, &QThread::finished, thread, &QObject::deleteLater);

    // IMPORTANT: erase the map entry once the thread actually finishes,
    // not immediately — otherwise 'devices' could reference a thread/worker
    // pair that's mid-teardown, and a subsequent addDevice() for the same
    // port name could race against still-pending deleteLater() cleanup
    connect(thread, &QThread::finished, this, [this, portName]() {
        devices.remove(portName);
    });

    devices.insert(portName, {thread, worker});
    thread->start();
}

void SerialManager::removeDevice(const QString &portName) {
    if (!devices.contains(portName)) return;

    DeviceEntry entry = devices.value(portName);
    // Queued call across the thread boundary — same rule as Day 16's
    // closeEvent shutdown, never call worker->stop() directly here
    QMetaObject::invokeMethod(entry.worker, "stop", Qt::QueuedConnection);

    // Deliberately NOT calling thread->wait() here — removeDevice() might
    // be called from a UI action (e.g., "disconnect" button), and blocking
    // the GUI thread waiting for a serial port to close is bad UX. The
    // devices.remove() happens asynchronously via the finished() connection
    // above once teardown actually completes.
}

QStringList SerialManager::activeDevices() const {
    return devices.keys();
}
```

### Wiring the Manager Into `MainWindow` — One Connection Point, Any Number of Devices

```cpp
// MainWindow constructor:
serialManager = new SerialManager(this);

connect(serialManager, &SerialManager::lineReceived, this,
        [this](const QString &portName, const QString &line) {
    QStringList parts = line.split(',');
    if (parts.size() == 2) {
        bool ok;
        double temp = parts[1].toDouble(&ok);
        if (ok) {
            deviceModel->upsertReading({parts[0], QDateTime::currentDateTime(), temp, true});
        }
    }
});

connect(serialManager, &SerialManager::connectionStateChanged, this,
        [this](const QString &portName, bool connected) {
    logView->append(QString("[SERIAL] %1 %2").arg(portName, connected ? "connected" : "disconnected"));
});

// Adding devices — could come from a config file, a dialog, or auto-detection
serialManager->addDevice("/dev/ttyUSB0", 115200);
serialManager->addDevice("/dev/ttyUSB1", 115200);
```

### Auto-Detection — A Realistic Addition for Embedded Deployments

Rather than hardcoding port names, scan for likely candidates at startup:

```cpp
#include <QSerialPortInfo>

void MainWindow::autoDetectDevices() {
    for (const QSerialPortInfo &info : QSerialPortInfo::availablePorts()) {
        // Heuristic filter — adjust based on what your actual devices report;
        // USB-serial adapters commonly show as "ttyUSB*"/"ttyACM*" on Linux
        if (info.portName().contains("ttyUSB") || info.portName().contains("ttyACM")) {
            serialManager->addDevice(info.systemLocation(), 115200);
        }
    }
}
```

### Why This Matters

- **Failure isolation is the actual architectural win here**, not just code organization. One flaky USB-serial adapter hanging or erroring doesn't touch the other threads' event loops at all — each `QThread` runs its own independent event loop. This is materially different from (and safer than) trying to multiplex several serial ports through one worker's own polling/select logic.
- **Re-tagging signals with the source identifier (`portName`)** before re-emitting from the manager is the standard "fan-in" pattern — consumers get one unified signal surface instead of needing to track N separate worker objects and remember which one is which.
- **Not calling `thread->wait()` in `removeDevice()`** is a deliberate UX decision — blocking the GUI thread on a single device's teardown while other devices are still streaming data would freeze the whole app. The async `finished()`-triggered cleanup avoids this while still guaranteeing eventual correct teardown.
- **Guarding against double-add and handling the destructor's full teardown** are both small things that matter a lot in practice — embedded deployments will see devices unplugged/replugged, and configs re-applied at runtime; sloppy handling here is where "GUI works in the demo but flakes in the field" bugs come from.

### Exercise

1. Extend `SerialManager` with a `deviceStatuses()` method returning a `QMap<QString, bool>` (port name → connected state), and use it to populate the `deviceList` sidebar (Day 2) with live connect/disconnect status per port, rather than the static placeholder text from Day 2.
2. Simulate the failure-isolation claim directly: using `socat` loopback pairs (Day 17's exercise) for two "devices," deliberately close/kill one loopback pair mid-run and confirm — via logged output — that the second device's data keeps flowing uninterrupted while the first cleanly reports `connectionStateChanged(false)`.
3. Add a config-file-driven device list: read a JSON array of `{"port": "...", "baud": ...}` objects (reusing your Day 7 JSON-in-QSettings pattern, or a dedicated `devices.json`) and call `addDevice()` for each entry at startup instead of hardcoding paths.

### Key Takeaways

- One `SerialWorker` + `QThread` pair per physical device — don't build a single worker that internally multiplexes several ports; isolation of failure domains is the actual benefit, not just tidiness.
- A manager class re-tags and fans in signals from N workers into one unified signal surface (`lineReceived(portName, line)`), so consumers don't need per-device wiring.
- Avoid blocking calls (`thread->wait()`) in code paths that might run on the GUI thread in response to user actions — let asynchronous `finished()` chains handle actual teardown completion.
- Guard against double-adding a device and ensure the destructor cleanly tears down every managed worker/thread — sloppy lifecycle handling here is exactly where field bugs come from with removable embedded hardware.

---

Say "next" for Day 19 (the MQTT client — Qt MQTT module setup, connecting to your mosquitto broker, subscribing to topics, and applying the exact same worker-thread pattern, including the subtleties of QoS and reconnect-on-broker-loss).