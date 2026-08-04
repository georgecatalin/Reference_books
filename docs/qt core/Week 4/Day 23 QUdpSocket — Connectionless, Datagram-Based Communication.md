[[Networking]]

**Theory: UDP vs TCP, and why datagrams solve the framing problem TCP created**

Day 22 ended on TCP's core limitation: it's a byte stream with no message boundaries, requiring explicit framing. UDP inverts this trade entirely: **each `writeDatagram()` call sends one discrete, self-contained packet**, and each `readyRead()` on a `QUdpSocket` corresponds to one complete datagram arriving — no accumulation buffer needed, no length-prefix framing required, because the network layer itself preserves message boundaries.

What you give up for that simplicity is real and must be designed around explicitly:

- **No delivery guarantee** — a datagram can simply vanish (dropped by a router, a full buffer, network congestion) with no notification to either side.
- **No ordering guarantee** — datagram B can arrive before datagram A even if A was sent first.
- **No automatic retransmission** — if you need "reading definitely arrived," you build that yourself (e.g., an application-level acknowledgment), or you use TCP instead.
- **Size limits** — practical UDP payloads should stay well under ~1400 bytes to avoid IP fragmentation, which reintroduces reliability problems UDP was supposed to avoid.

This makes UDP the right choice for **high-frequency, loss-tolerant** data — e.g., periodic sensor telemetry where losing one reading out of many doesn't matter (the next one arrives soon anyway) — and the wrong choice for anything requiring guaranteed, ordered delivery (a config command, a critical alert) unless you build reliability on top yourself.

**Resolved example 1 — a UDP sender/receiver pair, broadcasting periodic sensor telemetry**

```cpp
// udpsender.h
#pragma once
#include <QObject>
#include <QUdpSocket>
#include <QTimer>
#include <QJsonObject>
#include <QJsonDocument>
#include <QRandomGenerator>
#include <QDebug>

class TelemetrySender : public QObject
{
    Q_OBJECT
public:
    TelemetrySender(const QString &host, quint16 port, QObject *parent = nullptr)
        : QObject(parent), m_host(host), m_port(port)
    {
        connect(&m_timer, &QTimer::timeout, this, &TelemetrySender::sendReading);
        m_timer.start(200);   // simulate a sensor reporting 5x/second
    }

private slots:
    void sendReading()
    {
        QJsonObject reading;
        reading["device_id"] = "sensor-07";
        reading["temperature"] = 20.0 + QRandomGenerator::global()->bounded(100) / 10.0;
        reading["seq"] = ++m_seq;   // sequence number -- lets the receiver detect drops/reordering

        QByteArray datagram = QJsonDocument(reading).toJson(QJsonDocument::Compact);

        // ONE writeDatagram() call = ONE discrete packet -- no framing needed,
        // unlike Day 22's TCP stream, precisely because UDP preserves boundaries.
        qint64 sent = m_socket.writeDatagram(datagram, QHostAddress(m_host), m_port);
        if (sent == -1) {
            qWarning() << "Failed to send datagram:" << m_socket.errorString();
        }
    }

private:
    QUdpSocket m_socket;
    QTimer m_timer;
    QString m_host;
    quint16 m_port;
    int m_seq = 0;
};
```

```cpp
// udpreceiver.h
#pragma once
#include <QObject>
#include <QUdpSocket>
#include <QJsonDocument>
#include <QJsonObject>
#include <QDebug>

class TelemetryReceiver : public QObject
{
    Q_OBJECT
public:
    explicit TelemetryReceiver(quint16 port, QObject *parent = nullptr) : QObject(parent)
    {
        m_socket.bind(QHostAddress::Any, port);
        connect(&m_socket, &QUdpSocket::readyRead, this, &TelemetryReceiver::onReadyRead);
    }

private slots:
    void onReadyRead()
    {
        // Resolved: loop draining ALL pending datagrams -- multiple may have
        // arrived before this slot got scheduled, exactly like Day 22's
        // hasPendingConnections() loop for TCP.
        while (m_socket.hasPendingDatagrams()) {
            QByteArray datagram;
            datagram.resize(m_socket.pendingDatagramSize());
            QHostAddress sender;
            quint16 senderPort;
            m_socket.readDatagram(datagram.data(), datagram.size(), &sender, &senderPort);

            QJsonDocument doc = QJsonDocument::fromJson(datagram);
            if (!doc.isObject()) {
                qWarning() << "Received malformed datagram from" << sender.toString();
                continue;   // resolved: skip this one, keep processing the rest -- one bad
                            // packet shouldn't stall the whole receive loop
            }

            QJsonObject obj = doc.object();
            int seq = obj["seq"].toInt();

            // Resolved: detect drops/reordering using the sequence number --
            // this is exactly the kind of application-level check UDP requires
            // you to build yourself, since the protocol gives you nothing here.
            if (m_lastSeq != -1 && seq != m_lastSeq + 1) {
                qWarning() << "Sequence gap/reorder detected: expected" << (m_lastSeq + 1) << "got" << seq;
            }
            m_lastSeq = seq;

            qDebug() << "Received seq" << seq << "temp" << obj["temperature"].toDouble()
                      << "from" << sender.toString() << ":" << senderPort;
        }
    }

private:
    QUdpSocket m_socket;
    int m_lastSeq = -1;
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include "udpsender.h"
#include "udpreceiver.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    TelemetryReceiver receiver(9998);
    TelemetrySender sender("127.0.0.1", 9998);

    return app.exec();
}
```

**Resolved output (loopback traffic, so drops are rare but the detection code is exercised for real over an actual network):**

```
Received seq 1 temp 24.3 from "127.0.0.1" : 51234
Received seq 2 temp 21.7 from "127.0.0.1" : 51234
Received seq 3 temp 28.9 from "127.0.0.1" : 51234
Received seq 4 temp 22.1 from "127.0.0.1" : 51234
...
```

On loopback, drops are unlikely, so this typically runs gap-free — but the exact same code, deployed over a real wireless link between an embedded sensor node and a base station, would genuinely trigger the "Sequence gap/reorder detected" warning periodically. This is the resolved, realistic picture: UDP's simplicity is real, but so is its unreliability, and the sequence-number check above is the minimum viable way to _observe_ that unreliability rather than being silently unaware of it.

**Resolved example 2 — broadcast: one datagram reaching every listener on the local network, no per-recipient connection needed**

```cpp
#include <QCoreApplication>
#include <QUdpSocket>
#include <QTimer>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QUdpSocket socket;
    socket.bind(QHostAddress::AnyIPv4, 0);   // bind to any available local port for sending

    QTimer::singleShot(0, [&socket]() {
        QByteArray announcement = "MQTT_MONITOR_DISCOVERY:sensor-07:192.168.1.42";

        // Broadcast address sends to EVERY device on the local subnet listening
        // on this port -- no connection, no per-recipient loop needed, unlike
        // TCP where you'd need N separate connections for N recipients.
        qint64 sent = socket.writeDatagram(announcement, QHostAddress::Broadcast, 9997);
        qDebug() << "Broadcast sent," << sent << "bytes";
    });

    QTimer::singleShot(500, &app, &QCoreApplication::quit);
    return app.exec();
}
```

**Resolved rationale:** this is a realistic device-discovery pattern for IoT deployments — a new sensor node announcing its presence to whatever's listening on the local network, without needing to know any specific recipient's address in advance. TCP has no equivalent primitive; you'd need to already know who to connect to. This is a genuine structural advantage of UDP for discovery-style use cases specifically, separate from its throughput/overhead trade-offs.

**Key takeaways:**

- Each `writeDatagram()`/`readyRead()` pair corresponds to one discrete packet — UDP preserves message boundaries natively, eliminating the framing problem Day 22 required a length-prefix buffer to solve for TCP.
- UDP guarantees nothing beyond "the packet you sent, if it arrives, arrives as you sent it" — no ordering, no delivery confirmation, no retransmission. Any of those properties you need must be built at the application level (sequence numbers, as shown, or acknowledgments/retries for anything critical).
- Loop with `hasPendingDatagrams()` when reading, exactly like Day 22's `hasPendingConnections()` loop — multiple datagrams can arrive before your slot is scheduled to run.
- Choose UDP for high-frequency, loss-tolerant telemetry and discovery/broadcast use cases; choose TCP (Day 22) when you need guaranteed, ordered delivery and are willing to pay the framing and connection-management cost to get it.
- A malformed or unexpected datagram should be skipped and logged, not allowed to crash or stall the receive loop — one bad packet from a flaky sensor or network glitch is a certainty over time, not a hypothetical.

Day 24 covers `QNetworkAccessManager` — HTTP requests, relevant if `mqtt_monitor` (or a companion tool) ever needs to push readings to a REST backend or fetch remote configuration, building on the same async signal-driven pattern used for sockets, processes, and file watching throughout this course.