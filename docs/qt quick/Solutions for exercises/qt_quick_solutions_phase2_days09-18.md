# Qt Quick / QML — Exercise Solutions, Days 9–18 (Phase 2: C++/QML Integration)

---

## Day 9 — Q_PROPERTY, Q_INVOKABLE, Context Properties

**Exercises:** full `BrokerConnection` wired via context property; `NOTIFY` removal test; `statusSummary()` invokable; guard `setHost` against empty string.

```cpp
// brokerconnection.h
#pragma once
#include <QObject>

class BrokerConnection : public QObject
{
    Q_OBJECT
    Q_PROPERTY(bool connected READ connected NOTIFY connectedChanged)
    Q_PROPERTY(QString host READ host WRITE setHost NOTIFY hostChanged)
    // Exercise 2 — deliberately testing the failure mode: comment out NOTIFY
    // below, rebuild, click Connect, and confirm the UI does NOT update even
    // though m_connected changes correctly in C++ and emit still fires.
    // Q_PROPERTY(int deviceCount READ deviceCount)              // <- broken version
    Q_PROPERTY(int deviceCount READ deviceCount NOTIFY deviceCountChanged)  // correct version

public:
    explicit BrokerConnection(QObject *parent = nullptr) : QObject(parent) {}

    bool connected() const { return m_connected; }
    QString host() const { return m_host; }
    void setHost(const QString &host);
    int deviceCount() const { return m_deviceCount; }

    Q_INVOKABLE void connectToBroker();
    Q_INVOKABLE void disconnectFromBroker();
    Q_INVOKABLE QString statusSummary() const;   // exercise 3

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

```cpp
// brokerconnection.cpp
#include "brokerconnection.h"

void BrokerConnection::setHost(const QString &host)
{
    if (host.isEmpty())        // exercise 4 — guard against invalid writes
        return;
    if (m_host == host)
        return;
    m_host = host;
    emit hostChanged();
}

void BrokerConnection::connectToBroker()
{
    m_connected = true;
    emit connectedChanged();
    m_deviceCount = 3;
    emit deviceCountChanged();
}

void BrokerConnection::disconnectFromBroker()
{
    m_connected = false;
    emit connectedChanged();
    m_deviceCount = 0;
    emit deviceCountChanged();
}

QString BrokerConnection::statusSummary() const
{
    return (m_connected ? QStringLiteral("Connected to ") : QStringLiteral("Disconnected from "))
         + m_host + " (" + QString::number(m_deviceCount) + " devices)";
}
```

```cpp
// main.cpp
#include <QGuiApplication>
#include <QQmlApplicationEngine>
#include <QQmlContext>
#include "brokerconnection.h"

int main(int argc, char *argv[])
{
    QGuiApplication app(argc, argv);
    BrokerConnection brokerConnection;   // outlives engine — declared first, destroyed last

    QQmlApplicationEngine engine;
    engine.rootContext()->setContextProperty("brokerConnection", &brokerConnection);
    engine.load(QUrl(u"qrc:/main.qml"_qs));
    return app.exec();
}
```

```qml
// main.qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts

ApplicationWindow {
    width: 400; height: 220; visible: true

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

        // Exercise 3 — statusSummary() piggybacks on `connected`'s NOTIFY to re-evaluate.
        // Flagged deliberately: a proper Q_PROPERTY(QString statusSummary ...) with its
        // own NOTIFY would be the more correct long-term design.
        Label {
            text: brokerConnection.connected ? brokerConnection.statusSummary() : brokerConnection.statusSummary()
        }

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

---

## Day 10 — qmlRegisterType, Singletons, Ownership

**Exercises:** `BrokerConnection` converted to `QML_SINGLETON`; `DeviceMonitor` as `QML_ELEMENT` with 3 independent instances; unsafe vs safe `clone()`.

```cpp
// brokerconnection.h — singleton version (replaces Day 9's context-property registration)
#pragma once
#include <QObject>
#include <qqmlintegration.h>

class BrokerConnection : public QObject
{
    Q_OBJECT
    QML_ELEMENT
    QML_SINGLETON
    Q_PROPERTY(bool connected READ connected NOTIFY connectedChanged)
    Q_PROPERTY(QString host READ host WRITE setHost NOTIFY hostChanged)
    Q_PROPERTY(int deviceCount READ deviceCount NOTIFY deviceCountChanged)

public:
    explicit BrokerConnection(QObject *parent = nullptr) : QObject(parent) {}
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

```qml
// main.qml — no setContextProperty needed anymore; reference by type name
import MonitorApp

Label { text: BrokerConnection.connected ? "Connected" : "Disconnected" }
```

```cpp
// main.cpp — the setContextProperty call from Day 9 is removed entirely
QGuiApplication app(argc, argv);
QQmlApplicationEngine engine;
engine.load(QUrl(u"qrc:/main.qml"_qs));
return app.exec();
```

```cpp
// devicemonitor.h — QML_ELEMENT, multiple independent instances
#pragma once
#include <QObject>
#include <qqmlintegration.h>

class DeviceMonitor : public QObject
{
    Q_OBJECT
    QML_ELEMENT
    Q_PROPERTY(QString deviceId READ deviceId WRITE setDeviceId NOTIFY deviceIdChanged)
    Q_PROPERTY(bool online READ online NOTIFY onlineChanged)

public:
    explicit DeviceMonitor(QObject *parent = nullptr) : QObject(parent) {}

    QString deviceId() const { return m_deviceId; }
    void setDeviceId(const QString &id) {
        if (m_deviceId == id) return;
        m_deviceId = id;
        emit deviceIdChanged();
    }
    bool online() const { return m_online; }

    Q_INVOKABLE void ping() { m_online = true; emit onlineChanged(); }

    // Exercise 3a — UNSAFE: unparented, defaults to JavaScriptOwnership once
    // handed to QML. If C++ ever retained a raw pointer to the result, that
    // pointer could dangle the moment QML's GC decides it's unreferenced.
    Q_INVOKABLE DeviceMonitor* clone() const {
        auto *copy = new DeviceMonitor();
        copy->setDeviceId(m_deviceId + "-copy");
        return copy;
    }

    // Exercise 3b — SAFE: parented to `this`, so CppOwnership applies —
    // correct choice whenever C++ needs to retain a reference to the clone.
    Q_INVOKABLE DeviceMonitor* cloneSafe() {
        auto *copy = new DeviceMonitor(this);
        copy->setDeviceId(m_deviceId + "-copy");
        return copy;
    }

signals:
    void deviceIdChanged();
    void onlineChanged();

private:
    QString m_deviceId;
    bool m_online = false;
};
```

```qml
// main.qml — exercise 2: three independent instances
import QtQuick
import MonitorApp

Item {
    DeviceMonitor { id: dev1; deviceId: "esp32-04" }
    DeviceMonitor { id: dev2; deviceId: "rpi-monitor-01" }
    DeviceMonitor { id: dev3; deviceId: "beaglebone-sensor" }

    Component.onCompleted: {
        dev1.ping()
        dev2.ping()
        console.log(dev1.deviceId, dev1.online)   // esp32-04 true
        console.log(dev3.deviceId, dev3.online)   // beaglebone-sensor false — independent state
    }
}
```

**Exercise 4 (write-up, not code):** An object returned from a `Q_INVOKABLE` method with no `QObject` parent defaults to `JavaScriptOwnership` the moment QML touches it. If C++ also keeps a raw pointer to that same object, QML's garbage collector may delete it whenever it looks unreferenced from QML's side — leaving the C++-held pointer dangling, a genuine use-after-free the next time C++ dereferences it.

---

## Day 11 — Reusable Components

**Exercises:** documented public API for `DeviceRow`/`ConnectionStatusHeader`; `Panel.qml`; `Sidebar.qml` with `default property alias`; reach-into-internals failure.

```qml
// DeviceRow.qml — exercise 1: documented contract
// ---- PUBLIC API (contract) ----
//   properties: deviceId (string), rssi (int), online (bool)
//   signal:     deviceSelected(string deviceId)
// ---- INTERNAL (not part of the contract, free to change) ----
//   Row, Label instances, inner status-dot Rectangle, MouseArea
import QtQuick
import QtQuick.Controls

Rectangle {
    id: root
    height: 48
    radius: 4
    color: online ? "#313244" : "#1e1e2e"
    Behavior on color { ColorAnimation { duration: 250 } }

    property string deviceId: ""
    property int rssi: 0
    property bool online: false
    signal deviceSelected(string deviceId)

    Row {
        anchors.verticalCenter: parent.verticalCenter
        anchors.left: parent.left
        anchors.leftMargin: 12
        spacing: 16
        Rectangle {
            width: 10; height: 10; radius: 5
            color: root.online ? "#a6e3a1" : "#f38ba8"
        }
        Label { text: root.deviceId; color: "#cdd6f4"; font.bold: true }
        Label { text: root.rssi + " dBm"; color: "#a6adc8" }
    }

    MouseArea {
        anchors.fill: parent
        onClicked: root.deviceSelected(root.deviceId)
    }
}
```

```qml
// Panel.qml — exercise 2
import QtQuick
import QtQuick.Layouts

Rectangle {
    id: root
    color: "#181825"
    radius: 8

    property string title: ""
    default property alias content: contentArea.data

    ColumnLayout {
        anchors.fill: parent
        anchors.margins: 12
        spacing: 8

        Label { text: root.title; font.bold: true; color: "#cdd6f4" }

        Item {
            id: contentArea
            Layout.fillWidth: true
            Layout.fillHeight: true
        }
    }
}
```

```qml
// Sidebar.qml — exercise 3
import QtQuick
import QtQuick.Layouts

Rectangle {
    id: root
    color: "#181825"
    width: 200

    default property alias items: itemColumn.data

    ColumnLayout {
        id: itemColumn
        anchors.fill: parent
        anchors.margins: 8
        spacing: 6
    }
}
```

```qml
// Usage — arbitrary children stack vertically without knowing a ColumnLayout exists inside
Sidebar {
    Label { text: "Overview" }
    Button { text: "Devices" }
    Button { text: "Settings" }
}
```

```qml
// Exercise 4 — reach-into-internals failure, proving id-scoping
// DeviceCard.qml has an internal Rectangle with id: statusBar, NOT exposed via alias.
// Attempting from outside:
//     deviceCard.statusBar.color = "blue"
// Result: "TypeError: Cannot read property 'color' of undefined"
// This confirms statusBar's id is file-scoped and not part of DeviceCard's
// external contract — only aliased properties/signals are externally visible.
```

---

## Day 12 — Styling and Theming

**Exercises:** `Theme` singleton retrofit; custom `Button.qml` style; `Theme.criticalPulse`; Material Light/Dark comparison.

```qml
// Theme.qml
pragma Singleton
import QtQuick

QtObject {
    readonly property color background: "#181825"
    readonly property color surface: "#313244"
    readonly property color surfaceHover: "#45475a"
    readonly property color textPrimary: "#cdd6f4"
    readonly property color textSecondary: "#a6adc8"
    readonly property color success: "#a6e3a1"
    readonly property color warning: "#f9e2af"
    readonly property color danger: "#f38ba8"
    readonly property color accent: "#89b4fa"
    readonly property color criticalPulse: "#f38ba8"   // exercise 3

    readonly property int spacingSmall: 4
    readonly property int spacingMedium: 12
    readonly property int spacingLarge: 20
    readonly property int radiusDefault: 6
}
```

```
# qmldir
singleton Theme 1.0 Theme.qml
```

```qml
// styles/MyStyle/Button.qml — exercise 2
import QtQuick
import QtQuick.Controls.Basic
import QtQuick.Templates as T
import MonitorApp

T.Button {
    id: control
    implicitWidth: contentItem.implicitWidth + 24
    implicitHeight: 40

    contentItem: Text {
        text: control.text
        color: control.enabled ? Theme.textPrimary : "#6c7086"
        horizontalAlignment: Text.AlignHCenter
        verticalAlignment: Text.AlignVCenter
    }

    background: Rectangle {
        radius: Theme.radiusDefault
        color: control.pressed ? Theme.surfaceHover
             : control.hovered ? Theme.surface
             : Theme.background
        border.color: Theme.accent
        border.width: control.activeFocus ? 2 : 0
        Behavior on color { ColorAnimation { duration: 150 } }
    }
}
```

```cpp
// main.cpp
QQuickStyle::setStyle("MyStyle");
QQuickStyle::setFallbackStyle("Basic");
```

```qml
// CriticalCard.qml — exercise 3: wired to Theme instead of hardcoded color
SequentialAnimation {
    running: card.state === "critical"
    loops: Animation.Infinite
    NumberAnimation { target: dot; property: "opacity"; to: 0.5; duration: 400 }
    NumberAnimation { target: dot; property: "opacity"; to: 1.0; duration: 400 }
}
Rectangle {
    id: dot
    color: Theme.criticalPulse
}
```

**Exercise 4 (observation, not code):** Toggling `Material.theme` between `Material.Light` and `Material.Dark` on `ApplicationWindow` changes every descendant Control's background, text contrast, and ripple color simultaneously — a single attached property produces the same breadth of change that would otherwise require restyling every control individually, which is exactly the argument for theming centrally rather than per-control.

---

## Day 13 — Canvas and Custom Painting

**Exercises:** `GaugeArc` + `Sparkline` wired together; broken-mutation demonstration; center `Label` overlay; `strokeWidth` live tuning.

```qml
// GaugeArc.qml (from lesson, unchanged) — used below
```

```qml
// Sparkline.qml (from lesson) — exercise 2's broken variant shown for contrast
Canvas {
    id: root
    property var dataPoints: []
    property int maxPoints: 50

    // CORRECT — reassignment triggers onDataPointsChanged
    function pushValue(v) {
        var points = dataPoints.slice()
        points.push(v)
        if (points.length > maxPoints) points.shift()
        dataPoints = points
    }

    // Exercise 2 — deliberately BROKEN version (do not use in real code):
    function pushValueBroken(v) {
        dataPoints.push(v)
        // In-place mutation. onDataPointsChanged never fires because no
        // property assignment occurred — requestPaint() is never called,
        // and the sparkline silently stops updating.
    }
}
```

```qml
// main.qml — exercises 1, 3, 4 combined
import QtQuick
import QtQuick.Controls

Item {
    width: 220; height: 260

    GaugeArc {
        id: gauge
        anchors.top: parent.top
        anchors.horizontalCenter: parent.horizontalCenter
        width: 160; height: 160
        value: slider.value
        strokeWidth: widthSpin.value
        onStrokeWidthChanged: requestPaint()   // exercise 4 — every read property needs this

        // Exercise 3 — real Label overlay, not ctx.fillText
        Label {
            anchors.centerIn: parent
            text: Math.round(gauge.value * 100) + "%"
            color: "#cdd6f4"
            font.pixelSize: 20
            font.bold: true
        }
    }

    Slider { id: slider; anchors.top: gauge.bottom; anchors.topMargin: 8; width: parent.width; from: 0; to: 1 }
    SpinBox { id: widthSpin; anchors.top: slider.bottom; from: 4; to: 30; value: 12 }

    Sparkline {
        id: spark
        anchors.top: widthSpin.bottom
        anchors.topMargin: 8
        width: parent.width; height: 40

        Timer {
            interval: 500; running: true; repeat: true
            onTriggered: spark.pushValue(Math.random())
        }
    }
}
```

---

## Day 14 — QAbstractListModel

**Exercises:** full `DeviceListModel`; `simulateRandomUpdate()`; `roleNames()` typo demonstration; reactive `count` property.

```cpp
// devicelistmodel.h
#pragma once
#include <QAbstractListModel>
#include <QVector>
#include <QRandomGenerator>
#include <qqmlintegration.h>

struct DeviceInfo {
    QString deviceId;
    int rssi = 0;
    bool online = false;
    qint64 lastSeenEpoch = 0;
};

class DeviceListModel : public QAbstractListModel
{
    Q_OBJECT
    QML_ELEMENT
    Q_PROPERTY(int count READ rowCount NOTIFY countChanged)   // exercise 4

public:
    enum Roles { DeviceIdRole = Qt::UserRole + 1, RssiRole, OnlineRole, LastSeenRole };

    explicit DeviceListModel(QObject *parent = nullptr) : QAbstractListModel(parent) {}

    int rowCount(const QModelIndex &parent = QModelIndex()) const override {
        if (parent.isValid()) return 0;
        return m_devices.count();
    }

    QVariant data(const QModelIndex &index, int role) const override {
        if (!index.isValid() || index.row() >= m_devices.count()) return {};
        const auto &d = m_devices.at(index.row());
        switch (role) {
            case DeviceIdRole: return d.deviceId;
            case RssiRole:     return d.rssi;
            case OnlineRole:   return d.online;
            case LastSeenRole: return d.lastSeenEpoch;
            default: return {};
        }
    }

    QHash<int, QByteArray> roleNames() const override {
        return {
            { DeviceIdRole, "deviceId" },
            { RssiRole,     "rssi" },
            // Exercise 3 — deliberately typo this to "rssii" and rebuild:
            // QML delegates referencing `rssi` show `undefined` with NO
            // console error at all. This is the silent-failure mode to
            // recognize immediately in real debugging.
            { OnlineRole,   "online" },
            { LastSeenRole, "lastSeen" }
        };
    }

    Q_INVOKABLE void addOrUpdateDevice(const QString &deviceId, int rssi, bool online, qint64 lastSeen) {
        int idx = indexOfDevice(deviceId);
        if (idx >= 0) {
            m_devices[idx].rssi = rssi;
            m_devices[idx].online = online;
            m_devices[idx].lastSeenEpoch = lastSeen;
            QModelIndex mi = index(idx, 0);
            emit dataChanged(mi, mi, {RssiRole, OnlineRole, LastSeenRole});
        } else {
            beginInsertRows(QModelIndex(), m_devices.count(), m_devices.count());
            m_devices.append({deviceId, rssi, online, lastSeen});
            endInsertRows();
            emit countChanged();
        }
    }

    Q_INVOKABLE void removeDevice(const QString &deviceId) {
        int idx = indexOfDevice(deviceId);
        if (idx < 0) return;
        beginRemoveRows(QModelIndex(), idx, idx);
        m_devices.removeAt(idx);
        endRemoveRows();
        emit countChanged();
    }

    // Exercise 2 — exercises the in-place dataChanged path, not insert/remove
    Q_INVOKABLE void simulateRandomUpdate() {
        if (m_devices.isEmpty()) return;
        int idx = QRandomGenerator::global()->bounded(m_devices.count());
        int delta = QRandomGenerator::global()->bounded(-5, 6);
        m_devices[idx].rssi += delta;
        QModelIndex mi = index(idx, 0);
        emit dataChanged(mi, mi, {RssiRole});
    }

signals:
    void countChanged();

private:
    QVector<DeviceInfo> m_devices;
    int indexOfDevice(const QString &id) const {
        for (int i = 0; i < m_devices.count(); ++i)
            if (m_devices.at(i).deviceId == id) return i;
        return -1;
    }
};
```

```qml
// Usage — Q_PROPERTY(count) now reactive
Label { text: deviceListModel.count + " devices" }

Timer {
    interval: 2000; running: true; repeat: true
    onTriggered: deviceListModel.simulateRandomUpdate()
}
```

---

## Day 15 — Networking

**Exercises:** point `ApiClient` at a real/mock endpoint; unreachable-host error path; `connect()` context-object removal reasoning; POST "acknowledge alert" endpoint.

```cpp
// apiclient.cpp — exercise 4, POST endpoint following the same discipline as fetchDevices()
void ApiClient::acknowledgeAlert(int alertId)
{
    setLoading(true);

    QNetworkRequest request(QUrl(m_baseUrl + "/alerts/" + QString::number(alertId) + "/ack"));
    request.setHeader(QNetworkRequest::ContentTypeHeader, "application/json");

    QNetworkReply *reply = m_manager->post(request, QByteArray());

    connect(reply, &QNetworkReply::finished, this, [this, reply]() {
        setLoading(false);   // exercise 2 — fixed on BOTH the success and error paths
        if (reply->error() != QNetworkReply::NoError) {
            emit requestFailed(reply->errorString());
            reply->deleteLater();
            return;
        }
        emit alertAcknowledged(reply->property("alertId").toInt());
        reply->deleteLater();
    });
}
```

**Exercise 3 (write-up, not code):** Removing `this` as `connect()`'s third argument means the lambda's connection is no longer tied to `ApiClient`'s lifetime. If `ApiClient` is destroyed while a request is still in flight (e.g., during app shutdown), the lambda — which captured `this` — would still fire when `finished` emits, and would dereference a dangling `this` pointer inside the capture. Passing the context object is what makes Qt auto-disconnect the lambda when `ApiClient` is destroyed, preventing exactly this use-after-free.

---

## Day 16 — MQTT

**Exercises:** real broker wiring with malformed-payload test; offline-detection `QTimer`; LWT subscription.

```cpp
// mqttmanager.cpp — additions

void MqttManager::setupOfflineDetection()   // exercise 3
{
    auto *timer = new QTimer(this);
    timer->setInterval(5000);
    connect(timer, &QTimer::timeout, this, [this]() {
        qint64 now = QDateTime::currentSecsSinceEpoch();
        for (int i = 0; i < m_deviceModel->rowCount(); ++i) {
            QModelIndex idx = m_deviceModel->index(i, 0);
            qint64 lastSeen = m_deviceModel->data(idx, DeviceListModel::LastSeenRole).toLongLong();
            bool online = m_deviceModel->data(idx, DeviceListModel::OnlineRole).toBool();
            if (online && (now - lastSeen) > 30) {
                QString deviceId = m_deviceModel->data(idx, DeviceListModel::DeviceIdRole).toString();
                int rssi = m_deviceModel->data(idx, DeviceListModel::RssiRole).toInt();
                m_deviceModel->addOrUpdateDevice(deviceId, rssi, false, lastSeen);  // in-place update path
            }
        }
    });
    timer->start();
}

void MqttManager::subscribeToLastWill()   // exercise 4
{
    auto *sub = m_client->subscribe(QMqttTopicFilter("devices/+/lwt"));
    if (!sub) return;
    connect(sub, &QMqttSubscription::messageReceived, this, [this](const QMqttMessage &msg) {
        QStringList parts = msg.topic().name().split('/');
        if (parts.size() < 3) return;
        QString deviceId = parts.at(1);
        // LWT firing means the broker detected an ungraceful disconnect —
        // mark offline immediately, more reliable than the 30s timer heuristic
        m_deviceModel->addOrUpdateDevice(deviceId, -100, false, QDateTime::currentSecsSinceEpoch());
    });
}
```

**Exercise 2 (test procedure, not code):**
```bash
mosquitto_pub -t devices/test-01/status -m 'not json'
```
Confirms `parseDevicePayload`'s `QJsonParseError` check logs a warning and returns early — no crash, no garbage data reaching the model.

---

## Day 17 — SQLite

**Exercises:** open a real/copied database; wire `insertTelemetryReading` into the MQTT pipeline; bad-path test; aggregate query.

```cpp
// databasemanager.cpp — exercise 4, aggregate query
Q_INVOKABLE QVariantMap DatabaseManager::fetchDeviceStats(const QString &deviceId, qint64 sinceEpoch)
{
    QVariantMap result;
    QSqlDatabase db = QSqlDatabase::database("mqtt_monitor_connection");
    if (!db.isOpen()) {
        qWarning() << "fetchDeviceStats called with no open database connection";
        return result;
    }

    QSqlQuery query(db);
    query.prepare(
        "SELECT AVG(rssi) as avg_rssi, MAX(temperature) as max_temp "
        "FROM telemetry WHERE device_id = :deviceId AND timestamp > :since"
    );
    query.bindValue(":deviceId", deviceId);
    query.bindValue(":since", sinceEpoch);

    if (!query.exec()) {
        qWarning() << "Aggregate query failed:" << query.lastError().text();
        return result;
    }
    if (query.next()) {
        result["avgRssi"] = query.value("avg_rssi");
        result["maxTemperature"] = query.value("max_temp");
    }
    return result;
}
```

```cpp
// mqttmanager.cpp — exercise 2, dual-write wiring
void MqttManager::parseDevicePayload(const QString &deviceId, const QJsonObject &obj)
{
    int rssi = obj.value("rssi").toInt(-100);
    bool online = obj.value("online").toBool(true);
    double temperature = obj.value("temperature").toDouble(0.0);
    qint64 now = QDateTime::currentSecsSinceEpoch();

    m_deviceModel->addOrUpdateDevice(deviceId, rssi, online, now);              // live model (UI)
    DatabaseManager::instance()->insertTelemetryReading(deviceId, rssi, temperature);  // persisted history
}
```

**Exercise 3 (test procedure):**
```cpp
bool ok = databaseManager.openDatabase("/nonexistent/path/to.db");
// ok == false; db.lastError().text() logged via qWarning; DatabaseManager.connected == false.
// QML reflects `connected: false` correctly rather than proceeding as if data will arrive.
```

---

## Day 18 — Threading

**Exercises:** `TelemetryWorker` wired into `MqttManager`; thread-identity logging proof; burst test; unsafe direct-call comment.

```cpp
// telemetryworker.h / .cpp — as given in the lesson, plus thread-proof logging
void TelemetryWorker::processPayload(const QString &deviceId, const QByteArray &payload)
{
    qDebug() << "Processing on thread:" << QThread::currentThread();   // exercise 2 — worker thread

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
    emit deviceParsed(deviceId, rssi, online, temperature);
}
```

```cpp
// mqttmanager.cpp — main-thread side of the cross-thread hop
void MqttManager::onDeviceParsed(const QString &deviceId, int rssi, bool online, double temperature)
{
    qDebug() << "Processing on thread:" << QThread::currentThread();   // exercise 2 — main thread, DIFFERENT pointer
    qint64 now = QDateTime::currentSecsSinceEpoch();
    m_deviceModel->addOrUpdateDevice(deviceId, rssi, online, now);
    DatabaseManager::instance()->insertTelemetryReading(deviceId, rssi, temperature);
}

void MqttManager::onMqttMessage(const QString &topic, const QByteArray &payload)
{
    QStringList parts = topic.split('/');
    if (parts.size() < 3) return;
    emit payloadReady(parts.at(1), payload);   // crosses to the worker thread — QUEUED connection
}
```

**Exercise 4 (comment, not shippable code):**
```cpp
// UNSAFE — do NOT ship this. Shown only to recognize the failure category:
//
// void TelemetryWorker::processPayload(...) {
//     ...
//     m_deviceModel->addOrUpdateDevice(...);  // called directly from the WORKER thread
// }
//
// m_deviceModel belongs to the main/GUI thread. Mutating it from a worker
// thread is a cross-thread QObject access race — the Qt-object-model
// equivalent of an unsynchronized shared-memory write from two threads in
// raw C++. It may "mostly work" in testing and corrupt state or crash
// unpredictably under real load. The signal/slot hop (deviceParsed ->
// onDeviceParsed, both connected with `this` as context) is the only safe path.
```
