[[Concurrency]]

## Day 20: SQLite via `QSqlDatabase` — Schema, Prepared Statements, and Why Writes Need Their Own Thread Too

### Concept: Disk I/O Blocks Just Like Network/Serial I/O — People Forget This

Days 16–19 drilled into you that serial and MQTT I/O must not block the GUI thread. The same is true of disk I/O, and it's the one people forget, because "just write to SQLite" feels lightweight compared to "manage a socket." It isn't free: SQLite writes involve disk sync calls (especially with proper durability settings), and on an SD-card-based embedded target (your actual Raspberry Pi/BeagleBone deployment scenario), disk I/O latency can be **surprisingly high and unpredictable** — nowhere near as fast as writing to an SSD on a dev workstation. A GUI that stutters every time a telemetry batch gets persisted is a real, observed failure mode, not a theoretical one.

So: **SQLite access gets its own worker thread, following the exact same pattern as Days 16–19**, with one added wrinkle specific to `QSqlDatabase` — connection naming across threads.

### The `QSqlDatabase` Threading Wrinkle You Must Know

`QSqlDatabase` connections are **not** meant to be shared across threads — each thread that touches the database needs its **own connection**, added via a unique connection name:

```cpp
QSqlDatabase db = QSqlDatabase::addDatabase("QSQLITE", "worker_connection");
```

The second argument (connection name) is the part beginners skip, then get bitten by `QSqlDatabase::addDatabase()` silently overwriting/reusing the default unnamed connection across threads, causing genuinely confusing intermittent corruption/errors. **Always name your connection explicitly, and create it inside the worker thread**, not before `moveToThread()`.

### Annotated Code: Schema Setup

`schema.sql` (kept as a reference/documentation artifact — you'll embed the actual `CREATE TABLE` statements in code):

```sql
CREATE TABLE IF NOT EXISTS readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id TEXT NOT NULL,
    temperature REAL NOT NULL,
    recorded_at TEXT NOT NULL,   -- ISO8601 string; SQLite has no native datetime type
    online INTEGER NOT NULL      -- SQLite has no native boolean; store as 0/1
);

CREATE INDEX IF NOT EXISTS idx_readings_device_time
    ON readings(device_id, recorded_at);

CREATE TABLE IF NOT EXISTS devices (
    device_id TEXT PRIMARY KEY,
    friendly_name TEXT,
    alert_threshold REAL DEFAULT 80.0
);
```

Note the two SQLite-specific realities baked into this schema: **no native datetime type** (store as ISO8601 text, consistently, so lexicographic sorting still equals chronological sorting) and **no native boolean** (store as `INTEGER` 0/1) — this is a common surprise if you're coming from Postgres/MySQL habits.

### Annotated Code: `PersistenceWorker`

`persistenceworker.h`:

```cpp
#pragma once
#include <QObject>
#include <QSqlDatabase>
#include <QString>
#include <QDateTime>

struct ReadingRecord {
    QString deviceId;
    double temperature;
    QDateTime recordedAt;
    bool online;
};

class PersistenceWorker : public QObject {
    Q_OBJECT
public:
    explicit PersistenceWorker(const QString &dbPath, QObject *parent = nullptr);

public slots:
    void start();
    void stop();
    void saveReading(ReadingRecord record);
    void queryRecentReadings(const QString &deviceId, int limit);

signals:
    void readingsLoaded(QString deviceId, QList<ReadingRecord> records);
    void errorOccurred(QString message);
    void finished();

private:
    QString dbPath;
    QSqlDatabase db;
    QString connectionName; // unique per worker instance, see below
};
```

`persistenceworker.cpp`:

```cpp
#include "persistenceworker.h"
#include <QSqlQuery>
#include <QSqlError>
#include <QUuid>

PersistenceWorker::PersistenceWorker(const QString &dbPath, QObject *parent)
    : QObject(parent), dbPath(dbPath) {
    // Unique connection name generated once, used consistently — must be
    // unique across the whole app, not just this class, since QSqlDatabase's
    // connection registry is process-global
    connectionName = "persistence_" + QUuid::createUuid().toString(QUuid::WithoutBraces);
}

void PersistenceWorker::start() {
    // Connection created HERE (worker thread), not the constructor —
    // exact same thread-affinity rule as QTimer/QSerialPort/QMqttClient
    db = QSqlDatabase::addDatabase("QSQLITE", connectionName);
    db.setDatabaseName(dbPath);

    if (!db.open()) {
        emit errorOccurred("Failed to open database: " + db.lastError().text());
        return;
    }

    // WAL mode — you've already used this in your Python/SQLAlchemy work,
    // same reasoning applies here: allows concurrent readers while a
    // writer is active, meaningfully better for a monitoring app that
    // both writes telemetry continuously AND serves read queries for
    // historical views
    QSqlQuery pragma(db);
    pragma.exec("PRAGMA journal_mode=WAL;");
    pragma.exec("PRAGMA synchronous=NORMAL;"); // reasonable durability/speed
                                                 // tradeoff for telemetry data,
                                                 // vs FULL which is slower and
                                                 // more paranoid than this use
                                                 // case needs

    QSqlQuery schema(db);
    schema.exec(
        "CREATE TABLE IF NOT EXISTS readings ("
        "  id INTEGER PRIMARY KEY AUTOINCREMENT,"
        "  device_id TEXT NOT NULL,"
        "  temperature REAL NOT NULL,"
        "  recorded_at TEXT NOT NULL,"
        "  online INTEGER NOT NULL"
        ")"
    );
    schema.exec(
        "CREATE INDEX IF NOT EXISTS idx_readings_device_time "
        "ON readings(device_id, recorded_at)"
    );
}

void PersistenceWorker::saveReading(ReadingRecord record) {
    // ALWAYS use prepared statements with bound values — never string-format
    // SQL, even for "trusted" internal data. This is the same discipline as
    // parameterized queries in your Python/SQLAlchemy work; SQL injection
    // isn't the only reason (correctness with special characters, numeric
    // formatting, and NULL handling all benefit too)
    QSqlQuery query(db);
    query.prepare(
        "INSERT INTO readings (device_id, temperature, recorded_at, online) "
        "VALUES (:deviceId, :temperature, :recordedAt, :online)"
    );
    query.bindValue(":deviceId", record.deviceId);
    query.bindValue(":temperature", record.temperature);
    query.bindValue(":recordedAt", record.recordedAt.toString(Qt::ISODate));
    query.bindValue(":online", record.online ? 1 : 0);

    if (!query.exec()) {
        emit errorOccurred("Insert failed: " + query.lastError().text());
    }
}

void PersistenceWorker::queryRecentReadings(const QString &deviceId, int limit) {
    QSqlQuery query(db);
    query.prepare(
        "SELECT temperature, recorded_at, online FROM readings "
        "WHERE device_id = :deviceId "
        "ORDER BY recorded_at DESC LIMIT :limit"
    );
    query.bindValue(":deviceId", deviceId);
    query.bindValue(":limit", limit);

    if (!query.exec()) {
        emit errorOccurred("Query failed: " + query.lastError().text());
        return;
    }

    QList<ReadingRecord> results;
    while (query.next()) {
        ReadingRecord r;
        r.deviceId = deviceId;
        r.temperature = query.value(0).toDouble();
        r.recordedAt = QDateTime::fromString(query.value(1).toString(), Qt::ISODate);
        r.online = query.value(2).toInt() != 0;
        results.append(r);
    }

    emit readingsLoaded(deviceId, results); // crosses back to GUI thread via queued connection
}

void PersistenceWorker::stop() {
    db.close();
    // Removing the connection is important cleanup — otherwise the named
    // connection lingers in QSqlDatabase's process-global registry even
    // after this worker/thread is destroyed
    db = QSqlDatabase(); // clear the local handle first — required before removeDatabase,
                          // since a live QSqlDatabase object referencing the connection
                          // would otherwise print a warning
    QSqlDatabase::removeDatabase(connectionName);
    emit finished();
}
```

### Wiring Into `MainWindow` — Same Pattern, Fourth Time, Plus Batching

```cpp
void MainWindow::setupPersistenceThread() {
    auto *persistThread = new QThread(this);
    auto *persistWorker = new PersistenceWorker("mqtt_monitor.db"); // no parent

    persistWorker->moveToThread(persistThread);
    connect(persistThread, &QThread::started, persistWorker, &PersistenceWorker::start);

    connect(persistWorker, &PersistenceWorker::readingsLoaded, this,
            [this](const QString &deviceId, const QList<ReadingRecord> &records) {
        logView->append(QString("[DB] Loaded %1 historical readings for %2")
                         .arg(records.size()).arg(deviceId));
        // Feed these into a history chart later (Day 22/23 territory)
    });

    connect(persistWorker, &PersistenceWorker::errorOccurred, this, [this](const QString &msg) {
        logView->append("[DB ERROR] " + msg);
    });

    connect(persistWorker, &PersistenceWorker::finished, persistThread, &QThread::quit);
    connect(persistThread, &QThread::finished, persistWorker, &QObject::deleteLater);
    connect(persistThread, &QThread::finished, persistThread, &QObject::deleteLater);

    persistThread->start();
    this->persistWorker = persistWorker; // stored for saveReading() calls elsewhere
}

// Called from the MQTT/serial ingestion lambdas alongside deviceModel->upsertReading():
void MainWindow::persistReading(const QString &deviceId, double temp, bool online) {
    ReadingRecord record{deviceId, temp, QDateTime::currentDateTime(), online};
    QMetaObject::invokeMethod(persistWorker, "saveReading", Qt::QueuedConnection,
                               Q_ARG(ReadingRecord, record));
}
```

**Important detail**: passing a custom struct (`ReadingRecord`) through `QMetaObject::invokeMethod`/queued connections requires registering it with the meta-object system first, or the queued call will fail silently:

```cpp
// In main.cpp, before QApplication event loop starts, or in a static init block:
#include <QMetaType>
qRegisterMetaType<ReadingRecord>("ReadingRecord");
qRegisterMetaType<QList<ReadingRecord>>("QList<ReadingRecord>");
```

This registration step is the queued-connection equivalent of what `Q_ARG` needs to actually marshal your custom type across the thread boundary — skipping it is a very common silent-failure point the first time someone passes a non-Qt-builtin type through a queued call.

### Batching Writes — A Real Performance Consideration for High-Frequency Telemetry

If devices publish every second and you have a dozen of them, that's a write every ~80ms — each one as a separate transaction is real overhead (SQLite commits involve fsync-level durability work by default). The practical fix: **batch inserts inside an explicit transaction**, flushed periodically rather than per-message:

```cpp
// In PersistenceWorker, add a QTimer-based batch flush (created in start(), as usual)
// and change saveReading() to append to a pending buffer instead of writing immediately:

QList<ReadingRecord> pendingBatch;

void PersistenceWorker::saveReading(ReadingRecord record) {
    pendingBatch.append(record);
    // flushed by a timer, not immediately — see flushBatch() below
}

void PersistenceWorker::flushBatch() {
    if (pendingBatch.isEmpty()) return;

    db.transaction(); // wrap the whole batch in ONE transaction — this is
                       // what actually reduces fsync overhead dramatically
    QSqlQuery query(db);
    query.prepare(
        "INSERT INTO readings (device_id, temperature, recorded_at, online) "
        "VALUES (:deviceId, :temperature, :recordedAt, :online)"
    );
    for (const auto &record : pendingBatch) {
        query.bindValue(":deviceId", record.deviceId);
        query.bindValue(":temperature", record.temperature);
        query.bindValue(":recordedAt", record.recordedAt.toString(Qt::ISODate));
        query.bindValue(":online", record.online ? 1 : 0);
        query.exec();
    }
    db.commit();

    pendingBatch.clear();
}
```

Wire a `QTimer` (created in `start()`, per the established rule) to call `flushBatch()` every 1–2 seconds — this single change is often the difference between an embedded SD-card-backed SQLite setup that keeps up fine and one that visibly falls behind under load.

### Why This Matters

- **Database I/O is genuinely blocking I/O, same category as serial/network** — the fact that it "feels" local and fast on a dev machine is misleading; SD card I/O on real embedded targets is a completely different latency profile, and this is exactly the kind of assumption that works in development and fails in the field.
- **Named connections per thread** is non-negotiable with `QSqlDatabase` — the default/unnamed connection is process-wide shared state, and multiple threads touching it without explicit per-thread connections is a real source of intermittent, hard-to-reproduce corruption.
- **Prepared statements with bound values**, not string formatting, mirror the exact discipline you already practice in SQLAlchemy — same underlying reasoning (correctness and safety), different API surface.
- **Transaction batching** is often the single highest-leverage performance fix for embedded SQLite workloads — an order-of-magnitude difference between per-row commits and a batched transaction is completely realistic on SD-card storage.
- **`qRegisterMetaType`** is required infrastructure the moment you pass anything beyond Qt's built-in types through a queued connection — this is genuinely easy to forget and fails silently (no crash, the call just never happens), so it's worth checking early if a queued custom-type call seems to do nothing.

### Exercise

1. Wire `persistReading()` calls into both the MQTT (Day 19) and serial (Day 17/18) ingestion lambdas in `MainWindow`, so every live reading gets both displayed (via `deviceModel`) and durably persisted, and confirm rows actually appear via `sqlite3 mqtt_monitor.db "SELECT * FROM readings ORDER BY id DESC LIMIT 10;"` from a terminal while the app runs.
2. Implement the batching change above fully (pending buffer + periodic `flushBatch()` timer), and compare — using `strace -c` or simply observed CPU/IO behavior — the difference between per-row commits and batched commits under a simulated burst of 500 readings in quick succession.
3. Add a `deleteOldReadings(int olderThanDays)` slot that runs a `DELETE FROM readings WHERE recorded_at < :cutoff` on a periodic (e.g., daily) timer, so the database doesn't grow unbounded on a long-running embedded deployment — a real operational concern, not a hypothetical one.

### Key Takeaways

- SQLite access needs its own worker thread, following the identical `moveToThread()` pattern as serial/MQTT — disk I/O blocks the GUI thread exactly like network/serial I/O does, and embedded SD-card storage makes this worse, not better, than a dev workstation.
- `QSqlDatabase` connections must be uniquely named per thread and created inside the worker's `start()`, never shared or created before `moveToThread()`.
- No native datetime/boolean types in SQLite — store ISO8601 text and 0/1 integers consistently.
- Always use prepared statements with bound parameters, never string-formatted SQL.
- Batch writes inside explicit transactions on a periodic flush timer — this is usually the highest-leverage performance change for high-frequency telemetry on embedded storage.
- Custom structs passed through queued connections must be registered via `qRegisterMetaType` first, or the call silently does nothing.

---

Say "next" for Day 21 (a REST API layer using Qt's networking — either exposing `mqtt_monitor`'s data via `QHttpServer`/manual socket handling for a lightweight local API, or consuming an external REST API via `QNetworkAccessManager`; I'll cover both since your Python capstone already has a FastAPI layer this might complement or mirror).
