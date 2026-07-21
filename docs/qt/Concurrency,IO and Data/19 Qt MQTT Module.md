[[Concurrency]]
## Day 19: MQTT Client — Qt MQTT Module, Worker-Thread Pattern, QoS, and Reconnection

### Concept: Qt MQTT Is Not Part of Qt Widgets/Core — It's a Separate Module You Must Add

Unlike `QSerialPort` (Qt SerialPort module, still fairly core-adjacent), Qt MQTT lives in the Qt MQTT module, which historically has had a slightly different distribution story — it's not always bundled with a default Qt install and sometimes needs building from source or installing via the Qt Maintenance Tool / vcpkg, depending on your Qt6 distribution. Worth checking availability before writing code:

```bash
find / -iname "*qtmqtt*" 2>/dev/null   # check if it's already present
```

If unavailable via your package manager, it's built from the `qtmqtt` module in the Qt source repo (same CMake-based build as the rest of Qt6). Given you already have a working mosquitto broker from your prior projects, I'll assume the module gets installed — flag it to me if you hit install issues and we'll troubleshoot the actual environment specifics.

`CMakeLists.txt` addition:

```cmake
find_package(Qt6 REQUIRED COMPONENTS Widgets Mqtt SerialPort)
target_link_libraries(mqtt_monitor_gui PRIVATE Qt6::Widgets Qt6::Mqtt Qt6::SerialPort)
```

### The Core Class: `QMqttClient`

Same worker-thread discipline as Days 16–18 applies — `QMqttClient` is a `QObject` with thread affinity, so it follows the identical pattern: create it inside `start()`, not the worker's constructor, communicate only via signals/slots across the thread boundary.

### Annotated Code: `MqttWorker`

`mqttworker.h`:

```cpp
#pragma once
#include <QObject>
#include <QMqttClient>
#include <QTimer>

class MqttWorker : public QObject {
    Q_OBJECT
public:
    MqttWorker(const QString &host, quint16 port, const QString &clientId,
               QObject *parent = nullptr);

public slots:
    void start();
    void stop();
    void publish(const QString &topic, const QByteArray &payload, quint8 qos = 0);

signals:
    void messageReceived(QString topic, QByteArray payload);
    void connectionStateChanged(bool connected);
    void errorOccurred(QString message);
    void finished();

private slots:
    void onConnected();
    void onDisconnected();
    void onErrorChanged(QMqttClient::ClientError error);
    void attemptReconnect();

private:
    QString host;
    quint16 port;
    QString clientId;
    QMqttClient *client = nullptr;   // created in start(), thread-affinity rule applies
    QTimer *reconnectTimer = nullptr;
    bool intentionalDisconnect = false; // distinguishes "we called stop()" from "broker dropped us"
};
```

`mqttworker.cpp`:

```cpp
#include "mqttworker.h"

MqttWorker::MqttWorker(const QString &host, quint16 port, const QString &clientId, QObject *parent)
    : QObject(parent), host(host), port(port), clientId(clientId) {}

void MqttWorker::start() {
    client = new QMqttClient(this); // created here, worker-thread affinity, per Day 16 rule
    client->setHostname(host);
    client->setPort(port);
    client->setClientId(clientId);

    // Keep-alive: broker disconnects us if it hears nothing (ping or otherwise)
    // for 1.5x this interval — 60s is a reasonable default for a monitoring app,
    // not so short it spams pings, not so long that a dead connection lingers unnoticed
    client->setKeepAlive(60);

    reconnectTimer = new QTimer(this); // also created here, same thread-affinity reasoning
    reconnectTimer->setInterval(5000); // retry every 5s
    connect(reconnectTimer, &QTimer::timeout, this, &MqttWorker::attemptReconnect);

    connect(client, &QMqttClient::connected, this, &MqttWorker::onConnected);
    connect(client, &QMqttClient::disconnected, this, &MqttWorker::onDisconnected);
    connect(client, &QMqttClient::errorChanged, this, &MqttWorker::onErrorChanged);

    connect(client, &QMqttClient::messageReceived, this,
            [this](const QByteArray &message, const QMqttTopicName &topic) {
        emit messageReceived(topic.name(), message);
    });

    client->connectToHost();
}

void MqttWorker::onConnected() {
    emit connectionStateChanged(true);
    reconnectTimer->stop(); // successful connect — stop any pending retry attempts

    // Subscribe once actually connected — subscribing before connection
    // completes is a no-op/error, this ordering matters
    auto *subscription = client->subscribe(QMqttTopicFilter("devices/+/telemetry"), /*qos=*/1);
    if (!subscription) {
        emit errorOccurred("Failed to create subscription");
    }
    // '+' is a single-level MQTT wildcard — matches devices/device-01/telemetry,
    // devices/device-02/telemetry, etc., in one subscription rather than one per device
}

void MqttWorker::onDisconnected() {
    emit connectionStateChanged(false);

    if (!intentionalDisconnect) {
        // Broker dropped us unexpectedly (network blip, broker restart) —
        // start retrying. If WE called stop(), don't reconnect.
        emit errorOccurred("Disconnected from broker, will retry...");
        reconnectTimer->start();
    }
}

void MqttWorker::onErrorChanged(QMqttClient::ClientError error) {
    if (error == QMqttClient::NoError) return; // same "fires with NoError too" quirk as Day 17
    emit errorOccurred(QString("MQTT error: %1").arg(static_cast<int>(error)));
}

void MqttWorker::attemptReconnect() {
    if (client->state() == QMqttClient::Disconnected) {
        client->connectToHost(); // onConnected()/onDisconnected() handle the outcome as before
    }
}

void MqttWorker::publish(const QString &topic, const QByteArray &payload, quint8 qos) {
    if (!client || client->state() != QMqttClient::Connected) {
        emit errorOccurred("Cannot publish: not connected");
        return;
    }
    client->publish(QMqttTopicName(topic), payload, qos);
}

void MqttWorker::stop() {
    intentionalDisconnect = true;
    reconnectTimer->stop();
    if (client && client->state() != QMqttClient::Disconnected) {
        client->disconnectFromHost();
    }
    emit finished();
}
```

### QoS — What the Levels Actually Mean (Not Academic, Genuinely Affects Behavior)

|QoS|Guarantee|When to use for `mqtt_monitor`|
|---|---|---|
|0|At most once — fire and forget, no ack|High-frequency telemetry where an occasional dropped reading doesn't matter (e.g., temperature every second — the next reading supersedes it anyway)|
|1|At least once — acked, but duplicates possible|Alerts/state-change events where you must not silently lose a message, and your consumer logic is safe to receive a duplicate (idempotent handling)|
|2|Exactly once — full handshake, higher overhead|Rare for telemetry; reserve for genuinely critical commands (e.g., a remote shutdown command) where duplicates would be actively harmful|

For your `mqtt_monitor` telemetry stream specifically: **QoS 0 or 1 for readings, QoS 1 for alert-type messages** is the realistic choice. QoS 2's handshake overhead is rarely worth it for a monitoring dashboard's data volume.

### Wiring Into `MainWindow` — Same Pattern, Third Time

```cpp
void MainWindow::setupMqttThread() {
    auto *mqttThread = new QThread(this);
    auto *mqttWorker = new MqttWorker("localhost", 1883, "mqtt_monitor_gui"); // no parent

    mqttWorker->moveToThread(mqttThread);
    connect(mqttThread, &QThread::started, mqttWorker, &MqttWorker::start);

    connect(mqttWorker, &MqttWorker::messageReceived, this,
            [this](const QString &topic, const QByteArray &payload) {
        // Topic shape: devices/device-01/telemetry — extract the device ID
        // from the topic itself, don't assume it's only in the payload
        QStringList topicParts = topic.split('/');
        if (topicParts.size() >= 2) {
            QString deviceId = topicParts[1];

            QJsonParseError err;
            QJsonDocument doc = QJsonDocument::fromJson(payload, &err);
            if (err.error == QJsonParseError::NoError && doc.isObject()) {
                QJsonObject obj = doc.object();
                double temp = obj.value("temperature").toDouble();
                deviceModel->upsertReading({deviceId, QDateTime::currentDateTime(), temp, true});
            } else {
                logView->append(QString("[MQTT] Malformed payload on %1").arg(topic));
            }
        }
    });

    connect(mqttWorker, &MqttWorker::connectionStateChanged, this, [this](bool connected) {
        connectionIndicator->setText(connected ? "● Connected (MQTT)" : "● Disconnected");
        connectionIndicator->setStyleSheet(connected ? "color: green; font-weight: bold;"
                                                       : "color: red; font-weight: bold;");
    });

    connect(mqttWorker, &MqttWorker::errorOccurred, this, [this](const QString &msg) {
        logView->append("[MQTT] " + msg);
    });

    connect(mqttWorker, &MqttWorker::finished, mqttThread, &QThread::quit);
    connect(mqttThread, &QThread::finished, mqttWorker, &QObject::deleteLater);
    connect(mqttThread, &QThread::finished, mqttThread, &QObject::deleteLater);

    mqttThread->start();
}
```

### Publishing From the GUI Thread — The Same Cross-Thread Rule Applies

If a user clicks a "Send Command" button, you cannot call `mqttWorker->publish(...)` directly:

```cpp
connect(sendCommandButton, &QPushButton::clicked, this, [this, mqttWorker]() {
    QMetaObject::invokeMethod(mqttWorker, "publish", Qt::QueuedConnection,
                               Q_ARG(QString, "devices/device-01/command"),
                               Q_ARG(QByteArray, QByteArray("restart")),
                               Q_ARG(quint8, 1));
});
```

`Q_ARG` is required here because `QMetaObject::invokeMethod` needs to pass typed arguments through the queued-call machinery — this is the multi-argument version of the same pattern Day 16 used for the parameterless `stop()` call.

### Why This Matters

- **The same three-thread-boundary rules from Day 16 apply identically here**: create `QMqttClient` in `start()`, never call worker methods directly from the GUI thread, communicate only via signals or `QMetaObject::invokeMethod`. You're not learning new threading rules for MQTT — you're applying the exact same discipline to a new I/O source.
- **`intentionalDisconnect` distinguishing user-requested stop from broker-dropped-connection** is the detail that separates "reconnects correctly when the broker restarts" from "keeps trying to reconnect forever after the user explicitly disconnected" — a real, easy-to-miss bug.
- **Subscribing only after `connected()` fires** — not immediately after calling `connectToHost()` — is required; the connection is asynchronous, and subscribing before the handshake completes either silently fails or errors depending on the client's internal state.
- **Extracting the device ID from the MQTT topic, not just the payload**, mirrors good REST API design (resource identity in the URL/topic, not buried only in the body) — and means a malformed/incomplete payload still lets you at least know _which_ device sent something wrong.
- **QoS choice is a real design decision with actual tradeoffs**, not a default to leave untouched — matching it to your data's actual loss-tolerance (telemetry vs. alerts vs. commands) is the correct practical approach, not "always use the highest QoS to be safe" (which adds real overhead for no benefit on tolerant data).

### Exercise

1. Build and test end-to-end against your actual mosquitto broker: publish test messages via `mosquitto_pub -t devices/device-01/telemetry -m '{"temperature": 45.2}'` from the command line and confirm they flow into `deviceModel` and both views (Day 15) update live.
2. Test the reconnect path for real: stop your mosquitto broker (`sudo systemctl stop mosquitto`) while the GUI is running, confirm `connectionStateChanged(false)` fires and the log shows retry attempts, then restart the broker and confirm the worker reconnects and resubscribes automatically without any manual intervention.
3. Add a "Publish Test Command" button wired via `QMetaObject::invokeMethod` + `Q_ARG` as shown, and verify via `mosquitto_sub -t devices/+/command` in a terminal that the message actually arrives at the broker with the correct QoS.

### Key Takeaways

- Qt MQTT module must be explicitly added to `find_package`/`target_link_libraries` — it's not bundled with Widgets/Core by default in all distributions.
- `QMqttClient` follows the identical worker-thread discipline as `QTimer`/`QSerialPort` — create in `start()`, cross-thread calls via signals or `QMetaObject::invokeMethod`.
- Track whether a disconnect was user-requested vs. broker-initiated (`intentionalDisconnect` flag) to get reconnect behavior correct — auto-retry on broker loss, but not after an intentional stop.
- Subscribe only after `connected()` fires, not immediately after `connectToHost()`.
- QoS is a genuine per-message-type design decision (0 for tolerant telemetry, 1 for alerts, 2 rarely) — not a "set once and forget" default.
- Extract identity (device ID) from the topic structure itself, not solely from payload contents, for resilience against malformed payloads.

---

Say "next" for Day 20 (SQLite persistence via `QSqlDatabase` — schema design, prepared statements, and critically, doing database writes off the GUI thread too, since disk I/O has the exact same blocking-thread problem as serial/network I/O).