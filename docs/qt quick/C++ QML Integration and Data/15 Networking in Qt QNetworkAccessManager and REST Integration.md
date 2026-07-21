[[C++ QML Integrations and Data]]

# Day 15 — Networking in Qt: `QNetworkAccessManager` and REST Integration

Your Python `mqtt_monitor` capstone already exposed a FastAPI REST layer. Today: how the Qt Quick GUI talks to that same backend over HTTP — useful if you want the GUI as a thin client against your existing Python service, rather than (or alongside) direct MQTT/SQLite access in C++.

## Concept: `QNetworkAccessManager` — Qt's async HTTP client

Everything in Qt networking is **asynchronous by design**, which fits naturally with QML's reactive model — no blocking calls, no manual thread juggling for simple requests. You fire a request, get a `QNetworkReply*` back immediately, and connect to its `finished` signal.

```cpp
// apiclient.h
#pragma once
#include <QObject>
#include <QNetworkAccessManager>
#include <QNetworkReply>

class ApiClient : public QObject
{
    Q_OBJECT
    QML_ELEMENT
    QML_SINGLETON
    Q_PROPERTY(QString baseUrl READ baseUrl WRITE setBaseUrl NOTIFY baseUrlChanged)
    Q_PROPERTY(bool loading READ loading NOTIFY loadingChanged)

public:
    explicit ApiClient(QObject *parent = nullptr);

    QString baseUrl() const { return m_baseUrl; }
    void setBaseUrl(const QString &url);
    bool loading() const { return m_loading; }

    Q_INVOKABLE void fetchDevices();

signals:
    void baseUrlChanged();
    void loadingChanged();
    void devicesReceived(const QVariantList &devices);
    void requestFailed(const QString &errorMessage);

private:
    QNetworkAccessManager *m_manager;
    QString m_baseUrl = "http://localhost:8000";
    bool m_loading = false;

    void setLoading(bool loading);
};
```

```cpp
// apiclient.cpp
#include "apiclient.h"
#include <QJsonDocument>
#include <QJsonArray>
#include <QNetworkRequest>

ApiClient::ApiClient(QObject *parent) : QObject(parent)
{
    m_manager = new QNetworkAccessManager(this);   // parented — CppOwnership, Day 10 lesson applied
}

void ApiClient::setLoading(bool loading)
{
    if (m_loading == loading) return;
    m_loading = loading;
    emit loadingChanged();
}

void ApiClient::fetchDevices()
{
    setLoading(true);

    QNetworkRequest request(QUrl(m_baseUrl + "/devices"));
    request.setHeader(QNetworkRequest::ContentTypeHeader, "application/json");

    QNetworkReply *reply = m_manager->get(request);

    connect(reply, &QNetworkReply::finished, this, [this, reply]() {
        setLoading(false);

        if (reply->error() != QNetworkReply::NoError) {
            emit requestFailed(reply->errorString());
            reply->deleteLater();
            return;
        }

        QByteArray data = reply->readAll();
        QJsonDocument doc = QJsonDocument::fromJson(data);
        emit devicesReceived(doc.array().toVariantList());

        reply->deleteLater();   // CRITICAL — see note below
    });
}
```

## Concept: `reply->deleteLater()` — the one memory rule you must never skip

`QNetworkReply` objects are **heap-allocated by the manager and never auto-deleted** — you own cleanup. `deleteLater()` (not plain `delete`) is required specifically because you're inside a signal handler still executing on the reply's own event — deleting it immediately (`delete reply`) while its `finished` signal is mid-dispatch is undefined behavior. `deleteLater()` schedules deletion for the next event loop iteration, safely after the current signal handling completes. This is a direct extension of your C++ RAII instincts, adapted for Qt's event-driven deletion timing — forgetting this specific call is a slow, hard-to-notice memory leak (each request leaks one `QNetworkReply`), not a crash, which makes it easy to miss in testing and only show up under sustained real usage.

## Concept: Lambda captures and object lifetime — a real danger here

Notice `connect(reply, &QNetworkReply::finished, this, [this, reply]() {...})` — the **third argument, `this`**, is the _context object_. This isn't just a stylistic choice: it means the lambda connection is automatically disconnected if `this` (the `ApiClient`) is destroyed before the reply finishes — protecting you from a callback firing into a dangling `this` pointer if the object's lifetime ends mid-request (e.g., app shutdown while a request is in flight). **Always pass a context object to `connect()` when your lambda captures `this` or any object whose lifetime isn't guaranteed to outlive the async operation** — omitting it is a real crash waiting to happen under the wrong timing, not a theoretical concern.

## Using it from QML

```qml
import QtQuick
import QtQuick.Controls
import MonitorApp

Item {
    ListModel { id: deviceListModel }

    Connections {
        target: ApiClient
        function onDevicesReceived(devices) {
            deviceListModel.clear()
            for (var i = 0; i < devices.length; i++)
                deviceListModel.append(devices[i])
        }
        function onRequestFailed(errorMessage) {
            console.log("API error:", errorMessage)
        }
    }

    BusyIndicator {
        running: ApiClient.loading
        anchors.centerIn: parent
    }

    Button {
        text: "Refresh"
        onClicked: ApiClient.fetchDevices()
    }

    ListView {
        anchors.fill: parent
        model: deviceListModel
        // delegate unchanged from Day 6/14
    }
}
```

## Concept: `Connections` — the QML way to listen to a singleton/context property's signals

You've been handling signals via `onSignalName:` directly on a component you own. `ApiClient` here is a singleton you _don't_ own the instantiation of (Day 10) — `Connections { target: ApiClient; function onDevicesReceived(devices) {...} }` is the correct pattern for listening to signals from an object elsewhere in the tree, especially singletons and context properties. It's more explicit and more refactor-safe than trying to attach `onDevicesReceived` directly (which doesn't work the same way on a type-referenced singleton as it does on a directly-instantiated child).

## A design decision worth stating explicitly for your project

You now have two viable architectures for `mqtt_monitor`'s GUI:

1. **Thin client over REST** (today's lesson) — Qt Quick GUI ↔ your existing FastAPI service ↔ Python MQTT/SQLite backend. Reuses your Python capstone entirely; GUI is "just a view."
2. **Native C++ backend** (Days 9–14, continuing into 16–18) — Qt Quick GUI ↔ C++ `MqttClient`/`DatabaseModel` directly, no Python/FastAPI layer, single compiled binary.

Both are legitimate, and real production systems use either depending on constraints. REST-over-existing-backend is faster to stand up and lets you keep evolving your Python service independently; native C++ is a single deployable artifact with no separate service to run (relevant if you're targeting a Pi and want one binary, not a Python process alongside a Qt process). Nothing in Days 16–18 (MQTT, SQLite, threading in C++) is wasted if you end up choosing REST — the same techniques apply if you ever build a Rust/Go/C++ backend service instead of FastAPI. Keep both in mind as you go; the capstone (Days 27–30) will let you pick.

## Exercise

1. Point `ApiClient.baseUrl` at your actual running `mqtt_monitor` FastAPI service (or a mock `/devices` endpoint if it's not running) and confirm real data flows into a `ListView` end to end.
2. Deliberately point `baseUrl` at an unreachable host, click Refresh, and confirm `requestFailed` fires and displays an error — then check that `loading` correctly returns to `false` in the failure path too (a common bug: forgetting `setLoading(false)` on the error branch, leaving a spinner stuck forever).
3. Remove the `this` context argument from the `connect()` call, and in a comment, describe the failure scenario this reintroduces (you don't need to reproduce a live crash — reasoning through it is the point, referencing what you now know about lambda captures and object lifetime).
4. Add a POST request (`m_manager->post(request, jsonBody)`) for a hypothetical "acknowledge alert" endpoint, following the same `deleteLater()` and context-object discipline as `fetchDevices()`.

## Key takeaways

- `QNetworkAccessManager` requests are async; you get a `QNetworkReply*` and connect to `finished` — never block waiting for a reply.
- `reply->deleteLater()` (not `delete`) is mandatory, called from inside the `finished` handler — skipping it leaks one reply per request, silently.
- Always pass a context object (`this` or similar) as `connect()`'s third argument when the lambda captures `this` — protects against the object being destroyed mid-request.
- `Connections { target: SomeSingleton; function onSignal(...) {...} }` is the correct pattern for listening to a singleton/context property's signals from QML, distinct from `onSignalName:` on directly-owned children.
- Your GUI can be a thin REST client over your existing Python backend, or talk to a native C++ MQTT/SQLite layer directly — both are legitimate architectures; the capstone lets you choose.
