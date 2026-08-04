[[Networking]]

**Theory: sockets as QIODevice, and the connection lifecycle as signals**

Per Day 8's unifying theme, `QTcpSocket` is another `QIODevice` — same `read()`/`write()`/`readyRead()` interface you already know from files (Day 8) and processes (Day 19). What's new is the **connection lifecycle**, expressed entirely as signals: `connected()`, `disconnected()`, `readyRead()`, `errorOccurred()` — no blocking connect-and-wait unless you deliberately choose it (mirroring Day 19's "bounded synchronous wait is fine only for brief, justified cases").

`QTcpServer` is the listening side: it doesn't handle client connections itself — it emits `newConnection()` whenever a client connects, and you call `nextPendingConnection()` inside that slot to get the actual `QTcpSocket` for that specific client. Each connected client is then just another `QIODevice` you read/write via signals, exactly like everything else.

**Resolved example 1 — a minimal TCP server accepting multiple clients, each handled independently via signals**

```cpp
// echoserver.h
#pragma once
#include <QObject>
#include <QTcpServer>
#include <QTcpSocket>
#include <QDebug>

class EchoServer : public QObject
{
    Q_OBJECT
public:
    explicit EchoServer(quint16 port, QObject *parent = nullptr) : QObject(parent)
    {
        connect(&m_server, &QTcpServer::newConnection, this, &EchoServer::onNewConnection);

        if (!m_server.listen(QHostAddress::Any, port)) {
            qWarning() << "Failed to listen on port" << port << ":" << m_server.errorString();
            return;
        }
        qDebug() << "Listening on port" << port;
    }

private slots:
    void onNewConnection()
    {
        while (m_server.hasPendingConnections()) {
            QTcpSocket *client = m_server.nextPendingConnection();
            qDebug() << "New client connected:" << client->peerAddress().toString()
                      << ":" << client->peerPort();

            // Parent the client socket to the SERVER (Day 5) -- when EchoServer
            // is destroyed, all connected client sockets are cleaned up too.
            client->setParent(this);

            connect(client, &QTcpSocket::readyRead, this, [this, client]() {
                onClientDataReady(client);
            });
            connect(client, &QTcpSocket::disconnected, this, [this, client]() {
                qDebug() << "Client disconnected:" << client->peerAddress().toString();
                client->deleteLater();   // safe deferred deletion, per Day 16's deleteLater discipline
            });
        }
    }

    void onClientDataReady(QTcpSocket *client)
    {
        QByteArray data = client->readAll();
        qDebug() << "Received from" << client->peerAddress().toString() << ":" << data.trimmed();

        // Echo back, prefixed -- proves bidirectional I/O over the same socket
        client->write("ECHO: " + data);
    }

private:
    QTcpServer m_server;
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include "echoserver.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);
    EchoServer server(9999);
    return app.exec();
}
```

**Resolved test, using `nc` (netcat) from a separate terminal:** `echo "TEMP:23.5" | nc localhost 9999`

**Resolved server-side output:**

```
Listening on port 9999
New client connected: "127.0.0.1" : 54812
Received from "127.0.0.1" : "TEMP:23.5"
Client disconnected: "127.0.0.1"
```

Resolved point: `onNewConnection()` uses a `while` loop with `hasPendingConnections()` rather than assuming exactly one connection per signal — multiple clients can connect in a very short window, coalescing into one `newConnection()` signal delivery in rare cases; the loop drains all pending connections correctly rather than potentially missing one.

**Resolved example 2 — a TCP client connecting to that server, correct connection-state handling**

```cpp
#include <QCoreApplication>
#include <QTcpSocket>
#include <QDebug>
#include <QTimer>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    auto *socket = new QTcpSocket(&app);

    QObject::connect(socket, &QTcpSocket::connected, [socket]() {
        qDebug() << "Connected to server";
        socket->write("TEMP:24.1\n");
    });

    QObject::connect(socket, &QTcpSocket::readyRead, [socket]() {
        qDebug() << "Server replied:" << socket->readAll().trimmed();
        socket->disconnectFromHost();
    });

    QObject::connect(socket, &QTcpSocket::disconnected, [&app]() {
        qDebug() << "Disconnected from server";
        app.quit();
    });

    // Resolved: errorOccurred is essential -- without it, a failed connection
    // (server not running, wrong port) just silently never fires connected(),
    // and your program hangs forever with no diagnostic.
    QObject::connect(socket, &QTcpSocket::errorOccurred, [&app](QAbstractSocket::SocketError error) {
        qWarning() << "Socket error:" << error;
        app.quit();
    });

    qDebug() << "Connecting...";
    socket->connectToHost("localhost", 9999);   // non-blocking -- returns immediately

    return app.exec();
}
```

**Resolved output (server running):**

```
Connecting...
Connected to server
Server replied: "ECHO: TEMP:24.1"
Disconnected from server
```

**Resolved output (server NOT running — proving the errorOccurred path matters):**

```
Connecting...
Socket error: QAbstractSocket::ConnectionRefusedError
```

This is the resolved point worth internalizing as a standing discipline: exactly like Day 19's `QProcess::errorOccurred` (a process that fails to start never reaches `finished()`), **a `QTcpSocket` that fails to connect never reaches `connected()`** — if you only wire up the happy path, a connection failure produces total silence and an apparently-hung program, rather than an actionable error.

**Resolved example 3 — a length-prefixed message framing pattern, solving the "TCP has no message boundaries" problem**

A crucial, resolved theoretical point: **TCP is a byte stream, not a message stream.** `readyRead()` firing does _not_ mean "one complete message arrived" — it means "at least one more byte arrived," which could be a partial message, multiple messages concatenated, or anything in between. Naive code that assumes one `readyRead()` = one complete JSON payload (Day 10) will eventually break under real network conditions (slow links, large payloads split across TCP segments). The resolved fix: a length-prefix framing protocol.

```cpp
#include <QCoreApplication>
#include <QTcpSocket>
#include <QDataStream>
#include <QJsonDocument>
#include <QJsonObject>
#include <QDebug>

// Sending side: prefix each JSON message with its byte length (4-byte header)
void sendFramedMessage(QTcpSocket *socket, const QJsonObject &json)
{
    QByteArray payload = QJsonDocument(json).toJson(QJsonDocument::Compact);

    QByteArray frame;
    QDataStream stream(&frame, QIODevice::WriteOnly);   // per Day 8: QDataStream over a QIODevice-backed buffer
    stream.setVersion(QDataStream::Qt_6_5);
    stream << quint32(payload.size());   // 4-byte length header, fixed width
    frame.append(payload);

    socket->write(frame);
}

// Receiving side: a class that buffers incoming bytes until a COMPLETE frame is available
class FramedReader : public QObject
{
    Q_OBJECT
public:
    explicit FramedReader(QTcpSocket *socket, QObject *parent = nullptr)
        : QObject(parent), m_socket(socket)
    {
        connect(socket, &QTcpSocket::readyRead, this, &FramedReader::onReadyRead);
    }

signals:
    void messageReady(const QJsonObject &json);

private slots:
    void onReadyRead()
    {
        m_buffer.append(m_socket->readAll());

        // Resolved: loop, because multiple complete messages might have
        // arrived concatenated in this single readyRead() call.
        while (true) {
            if (m_expectedLength == 0) {
                if (m_buffer.size() < 4) return;   // haven't even received the length header yet
                QDataStream headerStream(m_buffer.left(4));
                headerStream.setVersion(QDataStream::Qt_6_5);
                headerStream >> m_expectedLength;
                m_buffer.remove(0, 4);
            }

            if (static_cast<quint32>(m_buffer.size()) < m_expectedLength) {
                return;   // resolved: partial message -- wait for more readyRead() calls
            }

            QByteArray messageData = m_buffer.left(m_expectedLength);
            m_buffer.remove(0, m_expectedLength);
            m_expectedLength = 0;   // reset for the next frame

            QJsonDocument doc = QJsonDocument::fromJson(messageData);
            if (doc.isObject()) {
                emit messageReady(doc.object());
            }
            // loop continues -- check if ANOTHER full message is already buffered
        }
    }

private:
    QTcpSocket *m_socket;
    QByteArray m_buffer;
    quint32 m_expectedLength = 0;
};
```

**Resolved rationale, stated explicitly:** without this framing, if a large JSON payload arrives split across two TCP segments, a naive `QJsonDocument::fromJson(socket->readAll())` on the first `readyRead()` gets a truncated, invalid JSON string — Day 10's `QJsonParseError` check would correctly flag it as malformed, but the actual second half of the message is now lost, since it arrives on a _later_ `readyRead()` call with no memory of the first half. The length-prefix buffer (`m_buffer`) is what correctly accumulates partial data across multiple `readyRead()` deliveries until a complete frame is available — this is the resolved, standard solution to "TCP has no message boundaries," and is exactly the kind of protocol-level framing MQTT itself implements internally (though you won't need to write MQTT's framing yourself if using a client library — this pattern is what such a library is doing under the hood).

**Key takeaways:**

- `QTcpSocket` is a `QIODevice` with an async connection lifecycle expressed as signals: `connected()`, `readyRead()`, `disconnected()`, `errorOccurred()` — always wire up `errorOccurred()`, since a failed connection silently never reaches `connected()`, exactly analogous to Day 19's `QProcess::errorOccurred`.
- `QTcpServer::newConnection()` signals that at least one client is pending; loop with `hasPendingConnections()`/`nextPendingConnection()` rather than assuming exactly one per signal.
- **TCP delivers a byte stream, not discrete messages** — `readyRead()` firing means "more bytes arrived," not "one complete message arrived." Any protocol over raw TCP needs explicit framing (length-prefix, as shown, or a delimiter) and a buffer that accumulates partial data across multiple `readyRead()` calls.
- This length-prefix buffering pattern generalizes directly to any streamed protocol parsing — the same "accumulate until a complete unit is available, then loop to check for more" logic applies whether you're framing JSON over TCP, or (as Day 11 touched on) handling multiple concatenated serial lines in one read.

Day 23 covers `QUdpSocket` — connectionless, datagram-based communication, contrasted directly against today's TCP material: what you gain (lower overhead, no connection setup) and what you give up (no delivery guarantee, no ordering, no automatic framing needed since datagrams _are_ discrete messages already).