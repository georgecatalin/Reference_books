[[Advanced]]

## Day 24: Phase 3 Integration — Real Data, End to End

### Concept: Today Is Wiring, Not New Material — And a Real Milestone

This is the point where `mqtt_monitor` stops being a simulated demo and becomes a genuinely functioning application: serial and MQTT ingestion, both off the GUI thread, feeding a shared model that drives the table, grid, charts, and gauge simultaneously, with every reading durably persisted to SQLite. `DeviceMonitor::simulateReading()` — your Day 3 placeholder — gets fully retired today.

### The Complete Data Flow (What You're Actually Assembling)

```
SerialManager (N SerialWorkers, N threads)  ─┐
                                              ├─→ MainWindow ingestion lambdas
MqttWorker (1 thread)                       ─┘         │
                                                         ├─→ deviceModel->upsertReading()  → table + grid views
                                                         ├─→ chartsByDeviceId[id]->addReading() (if open)
                                                         ├─→ gauge->setValue() (if it's the tracked device)
                                                         └─→ persistWorker->saveReading() (via invokeMethod) → SQLite
```

Every arrow here already exists from Days 9–23 individually. Today's actual work is making sure they're wired from the **same real ingestion point**, not scattered across simulated and real paths inconsistently.

### Annotated Code: One Consolidated Ingestion Handler

Rather than duplicating parsing logic in both the MQTT lambda and the serial lambda separately (Days 17–19 each had their own inline parsing), factor it into one shared method — this is the cleanup that matters today.

`mainwindow.h`:

```cpp
private:
    void handleIncomingReading(const QString &deviceId, double temperature, bool online);
```

`mainwindow.cpp`:

```cpp
void MainWindow::handleIncomingReading(const QString &deviceId, double temperature, bool online) {
    QDateTime now = QDateTime::currentDateTime();

    // 1. Update the model — drives table view (Day 9) and, via its
    // dataChanged/rowsInserted signals, the grid sync (Day 15)
    deviceModel->upsertReading({deviceId, now, temperature, online});

    // 2. Update chart, only if a history dialog for this device is open
    if (chartsByDeviceId.contains(deviceId)) {
        chartsByDeviceId[deviceId]->addReading(now, temperature);
    }

    // 3. Update the primary gauge, if this is the currently-tracked device
    if (deviceId == trackedGaugeDeviceId && primaryGauge) {
        primaryGauge->setValue(temperature);
    }

    // 4. Persist — cross-thread call to the SQLite worker (Day 20)
    ReadingRecord record{deviceId, temperature, now, online};
    QMetaObject::invokeMethod(persistWorker, "saveReading", Qt::QueuedConnection,
                               Q_ARG(ReadingRecord, record));
}
```

Now every ingestion source becomes a thin adapter that extracts `(deviceId, temperature, online)` and calls this one function:

```cpp
// MQTT (Day 19) — replaces the earlier inline parsing lambda
connect(mqttWorker, &MqttWorker::messageReceived, this,
        [this](const QString &topic, const QByteArray &payload) {
    QStringList topicParts = topic.split('/');
    if (topicParts.size() < 2) return;
    QString deviceId = topicParts[1];

    QJsonParseError err;
    QJsonDocument doc = QJsonDocument::fromJson(payload, &err);
    if (err.error != QJsonParseError::NoError || !doc.isObject()) {
        logView->append("[MQTT] Malformed payload on " + topic);
        return;
    }
    double temp = doc.object().value("temperature").toDouble();
    handleIncomingReading(deviceId, temp, true);
});

// Serial, via SerialManager (Day 18) — replaces the earlier inline parsing lambda
connect(serialManager, &SerialManager::lineReceived, this,
        [this](const QString &portName, const QString &line) {
    QStringList parts = line.split(',');
    if (parts.size() != 2) {
        logView->append(QString("[SERIAL] Malformed line from %1: %2").arg(portName, line));
        return;
    }
    bool ok;
    double temp = parts[1].toDouble(&ok);
    if (!ok) {
        logView->append(QString("[SERIAL] Non-numeric temperature from %1").arg(portName));
        return;
    }
    handleIncomingReading(parts[0], temp, true);
});

// Offline detection — from either source going quiet, or explicit disconnect signals
connect(serialManager, &SerialManager::connectionStateChanged, this,
        [this](const QString &portName, bool connected) {
    if (!connected) {
        // Note: portName isn't necessarily deviceId — depends on your
        // port-to-device mapping; adapt if you maintain that mapping separately
        logView->append(QString("[SERIAL] %1 disconnected").arg(portName));
    }
});
```

### Retiring `DeviceMonitor` and `simulateReading()`

`DeviceMonitor` (Day 3) served its purpose as a learning stand-in — it's genuinely fine to now either delete it entirely or repurpose its signals for something else (e.g., internal application-level alerts derived from `deviceModel` state, decoupled from any specific data source). If you want to keep the class around as an "internal event bus" for alerts:

```cpp
// Example repurposing: DeviceMonitor now watches deviceModel for threshold
// crossings, rather than being a fake data source itself
connect(deviceModel, &QAbstractItemModel::dataChanged, this,
        [this](const QModelIndex &topLeft, const QModelIndex &) {
    double temp = deviceModel->data(
        deviceModel->index(topLeft.row(), DeviceTableModel::Temperature),
        Qt::UserRole).toDouble();
    if (temp > 80.0) {
        QString deviceId = deviceModel->data(
            deviceModel->index(topLeft.row(), DeviceTableModel::DeviceId)).toString();
        emit monitor->alertRaised(QString("%1 exceeded threshold: %2°C").arg(deviceId).arg(temp));
    }
});
```

This is a reasonable design decision worth naming explicitly: alert logic now lives as a **reaction to model state**, not baked into either ingestion source — meaning a threshold breach gets caught identically whether it came from serial or MQTT, without duplicating the check in two places. This is the payoff of funneling everything through `handleIncomingReading()` → `deviceModel` as the single choke point.

### Startup Sequence — Order Matters

```cpp
MainWindow::MainWindow(QWidget *parent) : QMainWindow(parent) {
    // UI construction first (Days 1-15) — table, grid, chart containers, gauge, chrome
    setupDeviceListPanel();
    setupOverviewScreen();
    createActions();
    createMenus();
    createToolbar();

    // Then background threads (Days 16-20) — created after UI exists, since
    // some ingestion lambdas reference UI elements (logView, deviceModel)
    // that must already exist before any signal could possibly fire
    setupPersistenceThread();  // start this FIRST — other threads may want to persist immediately
    setupSerialThread();       // or serialManager->addDevice(...) calls
    setupMqttThread();

    // Settings restore last, as established in Day 7/8
    loadSettings();
}
```

**The ordering reasoning worth internalizing**: persistence starts first because both serial and MQTT ingestion may fire `handleIncomingReading()` almost immediately after their threads start (a device could already be mid-stream), and that function unconditionally calls into `persistWorker`. If `persistWorker` isn't ready yet, you'd be invoking a method on a worker whose `start()` (and thus its `QSqlDatabase` connection) hasn't run yet — `QMetaObject::invokeMethod` with `QueuedConnection` will still queue the call safely rather than crash (it's just an event waiting in that thread's queue), but it's cleaner and more predictable to have persistence ready first regardless.

### Shutdown Sequence — Also Matters, Symmetrically

```cpp
void MainWindow::closeEvent(QCloseEvent *event) {
    saveSettings();

    // Stop ingestion sources first — no point accepting new data
    // while everything is tearing down
    QMetaObject::invokeMethod(mqttWorker, "stop", Qt::QueuedConnection);
    serialManager->deleteLater(); // its destructor (Day 18) cleanly tears down all managed devices

    // Persistence stops last — anything still in flight from the above
    // gets a chance to actually write before its connection closes
    QMetaObject::invokeMethod(persistWorker, "stop", Qt::QueuedConnection);

    // Give everything a bounded window to actually finish, same reasoning as Day 16
    for (QThread *t : {mqttThread, persistThread}) {
        if (t && t->isRunning() && !t->wait(3000)) {
            t->terminate();
            t->wait();
        }
    }

    event->accept();
}
```

### Phase 3 Integration Checklist

|#|Check|
|---|---|
|1|Real MQTT messages (via `mosquitto_pub`) and/or real serial data flow into `deviceModel`, both through the same `handleIncomingReading()`|
|2|Table, grid, chart (when open), and gauge (for the tracked device) all stay in sync from the same underlying events|
|3|Every reading appears in SQLite (`sqlite3 mqtt_monitor.db "SELECT COUNT(*) FROM readings;"` climbs over time)|
|4|Closing the app cleanly stops all threads within the timeout, no `terminate()` fallback triggered in normal operation|
|5|Reopening the app and loading a device's chart correctly pulls historical data from SQLite, not an empty chart|
|6|`DeviceMonitor`/`simulateReading()` no longer appears anywhere in the live data path|

### Why This Matters

- **Funneling all ingestion sources through one `handleIncomingReading()` function** is the actual architectural payoff of everything from Days 16–23 — alert logic, persistence, and UI updates are each written _once_, regardless of how many data sources exist or get added later (a future Day-25-and-beyond addition of, say, a WebSocket ingestion source would just be another thin adapter calling the same function).
- **Startup/shutdown ordering isn't pedantic busywork** — it reflects real dependency relationships (ingestion depends on persistence being ready to _receive_ calls, even if those calls queue safely) and prevents a class of "works most of the time, occasionally does something weird at startup/shutdown" bugs that are genuinely hard to reproduce and debug later if you don't get the ordering principle right now.
- **Retiring the simulated data path entirely**, rather than leaving it dormant "just in case," keeps the codebase honest about what's actually driving the application — dead simulation code lingering alongside real ingestion is a maintenance trap and a common source of "wait, why did that fire, is this the fake data path or the real one" confusion during future debugging.

### Exercise

1. Run the fully-integrated app for an extended period (an hour or more) with both a real MQTT publisher and a real (or `socat`-simulated) serial device feeding it simultaneously, and confirm no memory growth (`top`/`ps` RSS tracking), no thread pile-up, and steady SQLite row growth.
2. Kill and restart your mosquitto broker mid-run, and separately unplug/replug a serial device mid-run, and confirm both ingestion sources recover independently without needing an app restart — this is the actual payoff of Days 17–19's reconnection logic, now proven under Phase 3's fully integrated conditions rather than in isolation.
3. Write up (even just as a personal note, not a formal doc) the complete startup/shutdown ordering and why each step is positioned where it is — this is good practice for the kind of design-decision documentation that matters on a real production system, and directly mirrors the "explain your architecture" muscle from your prior curricula's capstones.

### Key Takeaways

- All ingestion sources should funnel through one shared handler (`handleIncomingReading()`) that updates model, chart, gauge, and persistence — this is what makes adding/removing data sources later a small, isolated change rather than a scattered one.
- Startup and shutdown order reflect real dependencies (persistence ready before ingestion starts firing; ingestion stopped before persistence stops) — get this right deliberately, don't leave it to chance.
- Retire simulated/placeholder data paths fully once real ingestion works — don't leave dead code that could confuse future debugging about which path is actually live.
- This integration is the direct realization of the Day 16 promise: the entire UI/model/view layer built in Phase 2 required zero changes to accommodate real, multi-threaded, multi-source data — only the ingestion adapters changed.

---

That's Phase 3 complete — `mqtt_monitor`'s GUI is now a real, live-data-backed application. Say "next" for Day 25, the start of Phase 4: `QTest` — unit testing your model, delegate, and worker logic properly, including how to test `QObject`-based code without ever creating a full `QApplication` GUI where possible.