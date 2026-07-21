[[C++ QML Integrations and Data]]

# Day 17 — SQLite Integration: `QSqlDatabase` and Your Existing Schema

Day 16 gave you live data. Real dashboards also need history — device logs, past readings, uptime charts. Today: reading (and writing) your existing `mqtt_monitor` SQLite database directly from Qt, using the same schema your Python/C++ capstones already created — no migration, no duplicate storage.

## Concept: `QSqlDatabase` — a connection, not a query

Qt's SQL module separates the **connection** (`QSqlDatabase`) from **queries** (`QSqlQuery`). You open one named connection per database file, then run queries against it by connection name — this matters once you have more than one database or want connections per-thread (relevant in Day 18).

```cmake
find_package(Qt6 REQUIRED COMPONENTS Quick Sql)
target_link_libraries(appMonitor PRIVATE Qt6::Quick Qt6::Sql)
```

```cpp
// databasemanager.h
#pragma once
#include <QObject>
#include <QSqlDatabase>

class DatabaseManager : public QObject
{
    Q_OBJECT
    QML_ELEMENT
    QML_SINGLETON
    Q_PROPERTY(bool connected READ connected NOTIFY connectedChanged)

public:
    explicit DatabaseManager(QObject *parent = nullptr);

    bool connected() const { return m_connected; }
    Q_INVOKABLE bool openDatabase(const QString &path);
    Q_INVOKABLE QVariantList fetchDeviceHistory(const QString &deviceId, int limitRows = 100);

signals:
    void connectedChanged();

private:
    bool m_connected = false;
};
```

```cpp
// databasemanager.cpp
bool DatabaseManager::openDatabase(const QString &path)
{
    QSqlDatabase db = QSqlDatabase::addDatabase("QSQLITE", "mqtt_monitor_connection");
    db.setDatabaseName(path);

    if (!db.open()) {
        qWarning() << "Failed to open database:" << db.lastError().text();
        m_connected = false;
        emit connectedChanged();
        return false;
    }

    m_connected = true;
    emit connectedChanged();
    return true;
}
```

**`addDatabase("QSQLITE", "mqtt_monitor_connection")`** — that second string argument is a **connection name**, not a database filename. If you omit it, Qt uses `"qt_sql_default_connection"` implicitly, which becomes a real problem the moment you try to open a second connection (e.g., one per worker thread in Day 18) — you'd silently reuse/clobber the same default connection. Name your connections explicitly from day one, even with just one database — it costs nothing now and avoids a genuinely confusing bug later.

## Concept: `QSqlQuery` — parameterized queries, never string concatenation

You already know why SQL injection matters from your PHP work. Qt's parameterized query API is the direct equivalent of prepared statements you'd use there:

```cpp
QVariantList DatabaseManager::fetchDeviceHistory(const QString &deviceId, int limitRows)
{
    QVariantList results;

    QSqlDatabase db = QSqlDatabase::database("mqtt_monitor_connection");
    if (!db.isOpen()) {
        qWarning() << "fetchDeviceHistory called with no open database connection";
        return results;
    }

    QSqlQuery query(db);
    query.prepare(
        "SELECT rssi, temperature, timestamp FROM telemetry "
        "WHERE device_id = :deviceId "
        "ORDER BY timestamp DESC LIMIT :limitRows"
    );
    query.bindValue(":deviceId", deviceId);
    query.bindValue(":limitRows", limitRows);

    if (!query.exec()) {
        qWarning() << "Query failed:" << query.lastError().text();
        return results;
    }

    while (query.next()) {
        QVariantMap row;
        row["rssi"] = query.value("rssi");
        row["temperature"] = query.value("temperature");
        row["timestamp"] = query.value("timestamp");
        results.append(row);
    }

    return results;
}
```

**Never build `"SELECT * FROM telemetry WHERE device_id = '" + deviceId + "'"` by string concatenation** — same rule you already apply in your PHP ingestion scripts, same reasoning: user- or device-controlled strings reaching raw SQL is an injection vector, and `:namedParameter` + `bindValue` closes it entirely by construction, not by careful escaping.

## Concept: Returning `QVariantList`/`QVariantMap` to QML — the pragmatic bridge

Note `fetchDeviceHistory` returns a plain `QVariantList` of `QVariantMap`s, not a proper `QAbstractListModel` — deliberately, and worth explaining why this is a legitimate choice rather than a shortcut you should feel bad about. For **historical, one-shot query results** (load once, display, maybe refresh occasionally), a `Q_INVOKABLE` returning a `QVariantList` is simpler and entirely appropriate — you don't need `beginInsertRows` ceremony for data that isn't being incrementally mutated in place. Reserve full `QAbstractListModel` (Day 14) for **live, continuously-updating** collections like your device list. Use the lighter `QVariantList` return pattern for **query results, reports, historical dumps** — this is a real architectural distinction, not just "which one is easier."

```qml
import QtQuick
import MonitorApp

Item {
    property var historyRows: []

    function loadHistory(deviceId) {
        historyRows = DatabaseManager.fetchDeviceHistory(deviceId, 50)
    }

    ListView {
        anchors.fill: parent
        model: historyRows   // a QVariantList works directly as a ListView model
        delegate: Label {
            text: modelData.timestamp + " — " + modelData.temperature + "°C, RSSI " + modelData.rssi
        }
    }
}
```

Note `modelData` here (not named roles like `deviceId`/`rssi` from Day 14) — when a `ListView`'s model is a plain `QVariantList`/JS array rather than a `QAbstractListModel` with `roleNames()`, QML exposes each entry generically as `modelData`, and if it's a `QVariantMap`, you access fields off it directly (`modelData.temperature`). This is a different, simpler binding surface than Day 14's role-based delegate — know both, since you'll use each where appropriate.

## Concept: Writing back — inserting from a live MQTT message (tying Day 16 in)

```cpp
Q_INVOKABLE bool DatabaseManager::insertTelemetryReading(const QString &deviceId, int rssi, double temperature)
{
    QSqlDatabase db = QSqlDatabase::database("mqtt_monitor_connection");
    QSqlQuery query(db);
    query.prepare(
        "INSERT INTO telemetry (device_id, rssi, temperature, timestamp) "
        "VALUES (:deviceId, :rssi, :temperature, :timestamp)"
    );
    query.bindValue(":deviceId", deviceId);
    query.bindValue(":rssi", rssi);
    query.bindValue(":temperature", temperature);
    query.bindValue(":timestamp", QDateTime::currentSecsSinceEpoch());

    if (!query.exec()) {
        qWarning() << "Insert failed:" << query.lastError().text();
        return false;
    }
    return true;
}
```

Call this from `MqttManager::parseDevicePayload` (Day 16) alongside `m_deviceModel->addOrUpdateDevice(info)` — one MQTT message now updates both the live in-memory model (for the UI) _and_ persists to SQLite (for history), which is exactly the dual-write pattern your Python/C++ capstones already implement. The GUI joins that same pipeline rather than replacing it.

## A note on WAL mode, since your memory notes mention you already use it

If your existing `mqtt_monitor` database uses SQLite's WAL (write-ahead logging) mode for concurrent access (your Python capstone did) — Qt's SQLite driver respects WAL mode transparently, no special Qt-side configuration needed, **provided the database file was already set to WAL mode by whichever process created it** (`PRAGMA journal_mode=WAL;`). If the Qt app is a second/concurrent writer alongside your existing Python ingestion process, WAL mode is exactly what makes that safe — worth explicitly confirming it's still set if you're pointing this at a live production file rather than a copy.

## Exercise

1. Build `DatabaseManager` and point `openDatabase` at your actual `mqtt_monitor` SQLite file (or a copy — don't risk a live production DB while learning). Confirm `fetchDeviceHistory` returns real historical rows into a `ListView` via the `modelData` pattern.
2. Wire `insertTelemetryReading` into Day 16's `parseDevicePayload`, so every live MQTT message both updates the model and persists — confirm new rows appear via `mqtt_monitor`'s existing Python tooling too (proving it's genuinely the same database, not a parallel copy).
3. Deliberately open the database with a wrong/missing file path and confirm `openDatabase` returns `false` and logs `lastError()` cleanly rather than crashing — then confirm your QML properly reflects `connected: false` rather than silently proceeding as if data will arrive.
4. Add a second `Q_INVOKABLE` query using an aggregate (`SELECT AVG(rssi), MAX(temperature) FROM telemetry WHERE device_id = :deviceId AND timestamp > :since`) to compute a rolling stat — reinforcing that `QSqlQuery` handles arbitrary SQL, not just simple selects, exactly like you'd write directly against the file with the `sqlite3` CLI.

## Key takeaways

- Name your `QSqlDatabase` connections explicitly (`addDatabase("QSQLITE", "connectionName")`) from the start — avoids silent connection clobbering once you have more than one (Day 18 will add per-thread connections).
- Always use `query.prepare()` + `bindValue()` — never string-concatenate values into SQL, same discipline you already apply in PHP.
- `QVariantList`/`QVariantMap` return values are the right, lightweight tool for one-shot query results (history, reports); reserve full `QAbstractListModel` for live, incrementally-mutated collections.
- A `ListView` bound to a plain `QVariantList` exposes fields via generic `modelData`, not named roles — a different (simpler) binding surface than Day 14's `roleNames()` pattern.
- Qt's SQLite driver respects WAL mode transparently if the file's already configured for it — relevant since your existing Python ingestion likely already uses WAL for concurrent access.
