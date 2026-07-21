[[C++ QML Integrations and Data]]
# Day 16 — MQTT in Qt: Live Telemetry from Your Broker

This is the day that connects everything. Days 9–14 built the C++/QML bridge and a real model; Day 15 gave you an alternative REST path. Today: talking directly to your mosquitto broker from C++ using the Qt MQTT module, and streaming live messages straight into `DeviceListModel` — the native-app architecture from Day 15's design decision.

## Concept: Qt MQTT — not part of the base install, and worth knowing why

Qt MQTT is a Qt add-on (not core Qt Quick), installed via the Qt Maintenance Tool or `aqtinstall` (`-m qtmqtt` from Day 1's setup). It implements MQTT 3.1/3.1.1/5.0 client behavior — connect, subscribe, publish, QoS handling — as Qt-style `QObject`s with signals, fitting the same pattern you've used all course. If it's not showing up, confirm it's installed; it's easy to assume it ships with base Qt Quick since it's so central to your use case.

```cmake
find_package(Qt6 REQUIRED COMPONENTS Quick Mqtt)
target_link_libraries(appMonitor PRIVATE Qt6::Quick Qt6::Mqtt)
```

## Concept: The `MqttManager` singleton — your app's one connection

Following Day 10's rule (one true global → `QML_SINGLETON`):

```cpp
// mqttmanager.h
#pragma once
#include <QObject>
#include <QMqttClient>
#include "devicelistmodel.h"

class MqttManager : public QObject
{
    Q_OBJECT
    QML_ELEMENT
    QML_SINGLETON
    Q_PROPERTY(bool connected READ connected NOTIFY connectedChanged)
    Q_PROPERTY(QString brokerHost READ brokerHost WRITE setBrokerHost NOTIFY brokerHostChanged)
    Q_PROPERTY(DeviceListModel* devices READ devices CONSTANT)

public:
    explicit MqttManager(QObject *parent = nullptr);

    bool connected() const;
    QString brokerHost() const { return m_brokerHost; }
    void setBrokerHost(const QString &host);
    DeviceListModel* devices() const { return m_deviceModel; }

    Q_INVOKABLE void connectToBroker();
    Q_INVOKABLE void disconnectFromBroker();

signals:
    void connectedChanged();
    void brokerHostChanged();

private:
    QMqttClient *m_client;
    DeviceListModel *m_deviceModel;
    QString m_brokerHost = "localhost";

    void handleMessage(const QMqttMessage &message);
    void parseDevicePayload(const QString &topic, const QByteArray &payload);
};
```

```cpp
// mqttmanager.cpp
MqttManager::MqttManager(QObject *parent) : QObject(parent)
{
    m_client = new QMqttClient(this);
    m_deviceModel = new DeviceListModel(this);   // both parented — CppOwnership, Day 10

    m_client->setHostname(m_brokerHost);
    m_client->setPort(1883);

    connect(m_client, &QMqttClient::stateChanged, this, [this](QMqttClient::ClientState state) {
        emit connectedChanged();
        if (state == QMqttClient::Connected) {
            auto subscription = m_client->subscribe(QMqttTopicFilter("devices/+/status"));
            if (!subscription) {
                qWarning() << "Failed to subscribe — check connection state";
                return;
            }
            connect(subscription, &QMqttSubscription::messageReceived,
                    this, [this](const QMqttMessage &msg) { handleMessage(msg); });
        }
    });
}

bool MqttManager::connected() const
{
    return m_client->state() == QMqttClient::Connected;
}

void MqttManager::connectToBroker()
{
    m_client->setHostname(m_brokerHost);
    m_client->connectToHost();
}

void MqttManager::disconnectFromBroker()
{
    m_client->disconnectFromHost();
}

void MqttManager::handleMessage(const QMqttMessage &message)
{
    parseDevicePayload(message.topic().name(), message.payload());
}
```

## Concept: Topic wildcards and parsing — connecting your existing topic scheme

You already have a topic convention from your PHP/Python `mqtt_monitor` work — something like `devices/<device_id>/status`. `QMqttTopicFilter("devices/+/status")` uses MQTT's `+` single-level wildcard exactly as you'd expect from mosquitto config — subscribing once catches every device without knowing device IDs in advance:

```cpp
void MqttManager::parseDevicePayload(const QString &topic, const QByteArray &payload)
{
    // Extract device ID from topic: "devices/esp32-04/status" -> "esp32-04"
    QStringList parts = topic.split('/');
    if (parts.size() < 3) {
        qWarning() << "Unexpected topic format:" << topic;
        return;
    }
    QString deviceId = parts.at(1);

    QJsonParseError parseError;
    QJsonDocument doc = QJsonDocument::fromJson(payload, &parseError);
    if (parseError.error != QJsonParseError::NoError) {
        qWarning() << "Malformed MQTT payload on" << topic << ":" << parseError.errorString();
        return;
    }

    QJsonObject obj = doc.object();
    DeviceInfo info;
    info.deviceId = deviceId;
    info.rssi = obj.value("rssi").toInt(-100);          // defensive default
    info.online = obj.value("online").toBool(true);
    info.lastSeenEpoch = QDateTime::currentSecsSinceEpoch();

    m_deviceModel->addOrUpdateDevice(info);
}
```

**Every `.toInt()`/`.toBool()` call above has a default value argument.** This is deliberate and important: MQTT payloads are just bytes — a firmware bug, a partial write, a version mismatch between device firmware and your parsing code can all produce malformed or missing fields. Defaulting rather than crashing or propagating garbage is the same defensive-boundary instinct from Day 13's Canvas clamping, applied to network input — and given your embedded background, you already know field devices send malformed data sometimes; this is that same reality reaching your GUI layer now.

## Concept: Threading — do you need it here?

A reasonable question given Day 9's warnings about lifetimes and Day 18's upcoming threading lesson: **`QMqttClient`'s callbacks already run on the Qt event loop via signals — you do not need a separate thread just to receive MQTT messages.** `messageReceived` fires asynchronously without blocking your UI, the same way `QNetworkReply::finished` did on Day 15. Threading becomes relevant when _processing_ a message is expensive (heavy parsing, database writes that might stall) — Day 18 covers offloading that specific work, not the MQTT connection itself. Don't reach for `QThread` prematurely; the event loop already gives you non-blocking I/O for free here.

## Using it from QML — the full payoff

```qml
import QtQuick
import QtQuick.Controls
import MonitorApp

ApplicationWindow {
    width: 640; height: 480; visible: true

    header: RowLayout {
        Label {
            text: MqttManager.connected ? "● Connected" : "● Disconnected"
            color: MqttManager.connected ? Theme.success : Theme.danger
        }
        Button {
            text: MqttManager.connected ? "Disconnect" : "Connect"
            onClicked: MqttManager.connected
                ? MqttManager.disconnectFromBroker()
                : MqttManager.connectToBroker()
        }
    }

    ListView {
        anchors.fill: parent
        clip: true
        model: MqttManager.devices    // the SAME DeviceListModel from Day 14

        delegate: DeviceRow {
            width: ListView.view.width
            deviceId: model.deviceId
            rssi: model.rssi
            online: model.online
        }
    }
}
```

**Stop and notice what actually happened here.** `DeviceRow` — unchanged since Day 8. The `ListView` delegate wiring — unchanged since Day 14. The only thing that changed across three weeks of lessons is the _source feeding the model_: hardcoded `ListElement`s → simulated C++ inserts → now genuine, live MQTT messages from your actual mosquitto broker, parsed defensively, dispatched into the exact same model/view chain. This is the entire architectural point of everything since Day 6, now fully realized.

## Exercise

1. Build `MqttManager` against your actual mosquitto broker (or a local test instance — `mosquitto_pub -t devices/esp32-04/status -m '{"rssi":-58,"online":true}'` from the CLI to simulate a device). Confirm a real MQTT publish appears live in your `ListView` with zero manual refresh.
2. Publish a deliberately malformed payload (`mosquitto_pub -t devices/test-01/status -m 'not json'`) and confirm your app logs a warning and doesn't crash — this is the defensive parsing from `parseDevicePayload` being exercised for real, not hypothetically.
3. Add an `offline` detection mechanism: a `QTimer` in `MqttManager` (C++-side, `QTimer`, not QML `Timer`) that periodically checks `lastSeenEpoch` on each device in the model and marks any device unseen for >30s as `online: false` — calling `addOrUpdateDevice` with the update, exercising the in-place-update path from Day 14.
4. Subscribe additionally to a retained "last will" topic pattern (`devices/+/lwt`) if your existing MQTT setup uses LWT (last will and testament) messages — if it doesn't yet, this is a good moment to add one to your broker config, since it's the standard MQTT mechanism for detecting ungraceful device disconnects, more reliable than the timer-based heuristic in step 3.

## Key takeaways

- Qt MQTT is an add-on module — install it explicitly, link `Qt6::Mqtt`.
- `QMqttTopicFilter` wildcards (`+`, `#`) work exactly like your existing mosquitto topic conventions — subscribe once, handle any device.
- MQTT payloads are untrusted network input — parse defensively with default values on every field access, exactly like you already do for serial/embedded data.
- `QMqttClient` message delivery is already async via the event loop — no `QThread` needed just to receive messages; threading (Day 18) is for expensive _processing_, not the I/O itself.
- The entire point of this course's Model/View/Delegate discipline (Day 6 onward) lands here: your UI delegate code hasn't changed since Day 8, only the data source has — three architectural layers (hardcoded → simulated → live MQTT) swapped in transparently.
