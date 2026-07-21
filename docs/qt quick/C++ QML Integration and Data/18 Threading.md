[[C++ QML Integrations and Data]]

# Day 18 — Threading: `QThread`, Worker Objects, and Keeping the UI Responsive

Day 16 established you don't need threading just to _receive_ MQTT messages — the event loop handles that. Today is about the case that genuinely does need it: what happens when _processing_ a message (heavy parsing, a slow SQLite write, a burst of 50 messages/second from a device firmware update flood) risks stalling the UI thread. This is also the day your systems programming background will feel most at home — but Qt's rules here are specific and unforgiving if you apply raw `std::thread` instincts without adjustment.

## Concept: The one rule that governs everything today

**QML/Qt Quick's scene graph and all `QObject`s with `QML_ELEMENT`/exposed to QML must only be touched from the thread that owns them — almost always the main/GUI thread.** You cannot safely call methods on `DeviceListModel` or emit changes to it directly from a worker thread. This isn't a style preference; it's a hard rule enforced by Qt's object affinity system (`QObject::thread()`), and violating it produces the class of bug you're likely already wary of from embedded work: something that "mostly works" and then corrupts state or crashes unpredictably under load — a race condition, just relocated into Qt's object model instead of raw memory.

The correct pattern is not "call the model directly from the worker thread" — it's **worker thread does the expensive work, then signals results back to the main thread, where the model is updated.** Qt's signal/slot mechanism handles this cross-thread hop safely _for you_, provided you use signals/slots (not direct calls) for the handoff — this is the payoff of Qt's whole architecture actually being designed for this from the ground up, unlike wiring raw mutexes yourself.

## Concept: The worker-object pattern — `moveToThread`, not subclassing `QThread`

This is the modern, correct Qt pattern, and it deliberately isn't "subclass `QThread` and override `run()`" — that older pattern (still all over old tutorials/StackOverflow) mixes the thread-management object with the work itself and causes real confusion about which thread `this` runs on. The current correct approach:

```cpp
// telemetryworker.h
#pragma once
#include <QObject>

class TelemetryWorker : public QObject
{
    Q_OBJECT
public:
    explicit TelemetryWorker(QObject *parent = nullptr) : QObject(parent) {}

public slots:
    void processPayload(const QString &deviceId, const QByteArray &payload);

signals:
    void deviceParsed(const QString &deviceId, int rssi, bool online, double temperature);
    void parseFailed(const QString &deviceId, const QString &reason);
};
```

```cpp
// telemetryworker.cpp
void TelemetryWorker::processPayload(const QString &deviceId, const QByteArray &payload)
{
    // Simulate expensive work: heavy validation, maybe a checksum, maybe
    // a slow SQLite write via a THREAD-LOCAL connection (see note below)
    QJsonParseError err;
    QJsonDocument doc = QJsonDocument::fromJson(payload, &err);
    if (err.error != QJsonParseError::NoError) {
        emit parseFailed(deviceId, err.errorString());
        return;
    }

    QJsonObject obj = doc.object();
    int rssi = obj.value("rssi").toInt(-100);
    bool online = obj.value("online").toBool(true);
    double temperature = obj.value("temperature").toDouble(0.0);

    emit deviceParsed(deviceId, rssi, online, temperature);   // crosses back to main thread
}
```

**Wiring it up in `MqttManager`:**

```cpp
MqttManager::MqttManager(QObject *parent) : QObject(parent)
{
    // ... existing MQTT client setup ...

    m_workerThread = new QThread(this);
    m_worker = new TelemetryWorker();          // deliberately NOT parented — see note
    m_worker->moveToThread(m_workerThread);

    connect(m_workerThread, &QThread::finished, m_worker, &QObject::deleteLater);
    connect(this, &MqttManager::payloadReady, m_worker, &TelemetryWorker::processPayload);
    connect(m_worker, &TelemetryWorker::deviceParsed, this, &MqttManager::onDeviceParsed);
    connect(m_worker, &TelemetryWorker::parseFailed, this, &MqttManager::onParseFailed);

    m_workerThread->start();
}
```

**Every `connect()` here is doing cross-thread-safe work automatically** because Qt detects the connected objects live on different threads and uses a **queued connection** by default (versus a **direct connection** when both objects share a thread) — the signal arguments are copied and delivered via the receiving thread's event loop, not called synchronously across threads. This is the mechanism that makes the entire pattern safe without you writing a single mutex.

## Concept: Why the worker is _not_ parented to `this`

`m_worker = new TelemetryWorker();` — no parent, deliberately, contradicting Day 10's usual "always parent for `CppOwnership`" advice. Here's why: a `QObject` parent/child relationship implies **same-thread affinity expectations** in several Qt internals, and mixing `moveToThread` with a QObject-tree parent from a different thread is a well-known source of subtle bugs. The lifetime is instead managed explicitly: `connect(m_workerThread, &QThread::finished, m_worker, &QObject::deleteLater)` ensures the worker is destroyed (safely, on its own thread) when the thread shuts down. This is one of the few deliberate exceptions to Day 10's parenting rule — flag it in your own notes as "worker objects moved via `moveToThread` manage their own lifetime via `deleteLater` tied to thread shutdown, not QObject parenting."

## Concept: Thread-local SQLite connections — tying Day 17 back in

If your worker also writes to SQLite (heavy inserts under message bursts), **it needs its own `QSqlDatabase` connection, opened on the worker thread, with its own connection name** — `QSqlDatabase` connections are not thread-safe to share across threads:

```cpp
void TelemetryWorker::ensureDbConnection()
{
    if (QSqlDatabase::contains("worker_connection"))
        return;
    QSqlDatabase db = QSqlDatabase::addDatabase("QSQLITE", "worker_connection");
    db.setDatabaseName(m_dbPath);
    db.open();
}
```

This is exactly why Day 17 emphasized naming connections explicitly — `"mqtt_monitor_connection"` on the main thread, `"worker_connection"` on the worker thread, both pointing at the same SQLite file (WAL mode is precisely what makes that concurrent access safe, closing the loop from yesterday's lesson).

## Concept: Graceful shutdown — don't skip this

```cpp
MqttManager::~MqttManager()
{
    m_workerThread->quit();
    m_workerThread->wait();   // block briefly for clean shutdown — acceptable in a destructor
}
```

`wait()` blocking is fine here specifically because it's app shutdown, not steady-state operation — the one place a brief block is acceptable, the same exception you'd apply to a `pthread_join` at program teardown in embedded C code.

## Exercise

1. Build `TelemetryWorker`, wire it into `MqttManager` as shown, and route Day 16's `parseDevicePayload` logic through it instead of parsing inline on the main thread.
2. Verify the threading is actually happening: add `qDebug() << "Processing on thread:" << QThread::currentThread();` inside `processPayload`, and a matching log in `MqttManager::onDeviceParsed` — confirm the two logs show different thread pointers, proving the cross-thread hop is real and not accidental same-thread execution.
3. Simulate a burst: publish 100 MQTT messages rapidly (`mosquitto_pub` in a loop) and confirm the UI (device list, any animations) stays responsive — no stutter — while messages process, versus doing the same test with parsing left on the main thread for comparison.
4. Deliberately try calling `m_deviceModel->addOrUpdateDevice(...)` directly from inside `TelemetryWorker::processPayload` (skipping the signal hop) and note (in comments — this is genuinely unsafe to leave in running code, don't ship this) what category of bug this represents, tying back to the "hard rule" at the top of today's lesson.

## Key takeaways

- QML-exposed `QObject`s must only be touched from their owning thread (almost always main/GUI) — cross-thread handoff happens via signals/slots (queued connections), never direct method calls into another thread's objects.
- Modern Qt threading = worker object + `moveToThread`, not subclassing `QThread` — the older subclass-and-override-`run()` pattern is legacy and easy to get wrong about which thread code executes on.
- Qt automatically uses queued (thread-safe) connections when signal and slot live on different threads — this is what makes the pattern safe without manual mutexes.
- Worker objects moved via `moveToThread` are a deliberate exception to "always parent for lifetime" — manage their lifetime via `deleteLater` tied to `QThread::finished`, not QObject parenting.
- `QSqlDatabase` connections aren't thread-safe to share — each thread needs its own named connection, safely coexisting via WAL mode on the same underlying file.
- A brief blocking `wait()` on thread shutdown is one of the few acceptable places to block — app teardown, not steady-state operation.

That closes Phase 2 — your GUI now has a real C++/QML bridge, live models, networking, persistence, and safe threading, all feeding the same UI you built in Phase 1. Say next for Day 19, opening Phase 3 (Advanced Qt Quick): advanced animations, `Behavior` composition, and where `ParticleSystem` is (and isn't) worth using.
