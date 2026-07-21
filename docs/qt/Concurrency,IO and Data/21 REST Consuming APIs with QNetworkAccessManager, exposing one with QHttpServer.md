[[Concurrency]]

## Day 21: REST — Consuming APIs with `QNetworkAccessManager`, Exposing One with `QHttpServer`

### Concept: Two Different Directions, Two Different Classes

Your Python capstone already has a FastAPI layer serving `mqtt_monitor` data. Today covers both directions Qt can take:

1. **Consuming a REST API** (e.g., calling your existing FastAPI backend, or a third-party service) → `QNetworkAccessManager` + `QNetworkReply`, fully async, no threading needed (it's already non-blocking by design — a genuine exception to the "give it a worker thread" rule from Days 16–20).
2. **Exposing your own REST API directly from the Qt app** (skip Python/FastAPI entirely, serve JSON straight from the C++ GUI process) → `QHttpServer`, a genuinely newer, lighter-weight Qt module.

Given you already have a working FastAPI layer, direction #1 (Qt GUI _consuming_ it) is the more realistic architecture — the GUI becomes a client of your existing Python service rather than duplicating it. I'll cover #2 more briefly, since it's more relevant if you ever want the C++ side to be fully self-contained without a Python component at all.

### Why `QNetworkAccessManager` Doesn't Need a Worker Thread

This is worth pausing on, since it looks like it contradicts Days 16–20. It doesn't: `QNetworkAccessManager` is **already asynchronous** at the Qt event-loop level — it doesn't block your calling thread waiting for a response. It issues the request, returns immediately, and emits `finished()` on the `QNetworkReply` once the response arrives, safely delivered as a normal Qt signal. The "give I/O its own thread" rule from Days 16–20 exists specifically because `QSerialPort`/`QMqttClient`/`QSqlDatabase` have blocking or long-synchronous-call-shaped APIs underneath; `QNetworkAccessManager` was designed from the start to not need that.

### Annotated Code: Consuming Your FastAPI `mqtt_monitor` Backend

```cpp
#include <QNetworkAccessManager>
#include <QNetworkReply>
#include <QJsonDocument>
#include <QJsonArray>
#include <QJsonObject>
#include <QUrlQuery>
```

`mainwindow.h`:

```cpp
private:
    void fetchDeviceHistory(const QString &deviceId);

private:
    QNetworkAccessManager *networkManager; // one instance, reused for all requests
```

`mainwindow.cpp`:

```cpp
// Created once, in the constructor — QNetworkAccessManager is meant to be
// long-lived and reused across many requests, not recreated per call
networkManager = new QNetworkAccessManager(this);

void MainWindow::fetchDeviceHistory(const QString &deviceId) {
    QUrl url("http://localhost:8000/api/devices/" + deviceId + "/history");

    QUrlQuery query;
    query.addQueryItem("limit", "50");
    url.setQuery(query);

    QNetworkRequest request(url);
    request.setHeader(QNetworkRequest::ContentTypeHeader, "application/json");
    // If your FastAPI backend requires auth (a bearer token, say):
    // request.setRawHeader("Authorization", "Bearer " + token.toUtf8());

    QNetworkReply *reply = networkManager->get(request);

    // Connect to THIS reply's finished() — each request gets its own
    // QNetworkReply object, and you connect per-reply, not once globally
    connect(reply, &QNetworkReply::finished, this, [this, reply, deviceId]() {
        // CRITICAL: schedule the reply for deletion once you're done reading it.
        // QNetworkReply objects are NOT auto-deleted — forgetting this leaks
        // one object per request, which adds up fast on a polling dashboard.
        reply->deleteLater();

        if (reply->error() != QNetworkReply::NoError) {
            logView->append(QString("[HTTP] Request failed for %1: %2")
                             .arg(deviceId, reply->errorString()));
            return;
        }

        QByteArray responseData = reply->readAll();
        QJsonParseError parseError;
        QJsonDocument doc = QJsonDocument::fromJson(responseData, &parseError);

        if (parseError.error != QJsonParseError::NoError || !doc.isArray()) {
            logView->append("[HTTP] Malformed response for " + deviceId);
            return;
        }

        QJsonArray readings = doc.array();
        logView->append(QString("[HTTP] Loaded %1 history entries for %2")
                         .arg(readings.size()).arg(deviceId));
        // Feed into a chart (Day 22/23) or a details dialog here
    });
}
```

### The Reply Lifetime Rule — Where People Actually Leak Memory

`QNetworkReply` deserves its own callout because it's the single most common Qt-networking memory leak in real code: **you must call `reply->deleteLater()` yourself**, typically inside the `finished()` handler, after you're done reading from it. The manager doesn't clean these up for you, and on a dashboard that polls an API every few seconds, forgetting this turns into a slow, genuinely production-affecting memory leak rather than an obvious immediate crash — exactly the kind of bug that survives a demo and shows up a week into a real deployment.

### Handling Timeouts

`QNetworkReply` doesn't time out on its own by default (pre-Qt 6.7's `setTransferTimeout` addition — assume you should set this explicitly regardless of exact version, since defaults have shifted across Qt6 point releases):

```cpp
QNetworkRequest request(url);
request.setTransferTimeout(5000); // 5 second timeout, then finished() fires with an error
```

Without this, a hung/unresponsive server can leave a request pending indefinitely — worth setting deliberately for any network call on a monitoring dashboard, where you'd rather show "request timed out" than hang silently.

### POST Requests — Sending Data to Your FastAPI Backend

```cpp
void MainWindow::sendDeviceCommand(const QString &deviceId, const QString &command) {
    QUrl url("http://localhost:8000/api/devices/" + deviceId + "/command");
    QNetworkRequest request(url);
    request.setHeader(QNetworkRequest::ContentTypeHeader, "application/json");
    request.setTransferTimeout(5000);

    QJsonObject body;
    body["command"] = command;
    QByteArray payload = QJsonDocument(body).toJson(QJsonDocument::Compact);

    QNetworkReply *reply = networkManager->post(request, payload);
    connect(reply, &QNetworkReply::finished, this, [this, reply, deviceId]() {
        reply->deleteLater();
        if (reply->error() != QNetworkReply::NoError) {
            logView->append("[HTTP] Command failed: " + reply->errorString());
        } else {
            logView->append("[HTTP] Command sent to " + deviceId);
        }
    });
}
```

### Briefly: `QHttpServer` — If the GUI Should Expose Its Own API

For cases where you want the C++ GUI process itself to serve data (e.g., a lightweight status endpoint for external monitoring, bypassing Python entirely):

```cmake
find_package(Qt6 REQUIRED COMPONENTS Widgets HttpServer)
target_link_libraries(mqtt_monitor_gui PRIVATE Qt6::HttpServer)
```

```cpp
#include <QHttpServer>
#include <QTcpServer>

auto *httpServer = new QHttpServer(this);

httpServer->route("/api/devices", QHttpServerRequest::Method::Get,
    [this](const QHttpServerRequest &request) {
        QJsonArray devices;
        for (int r = 0; r < deviceModel->rowCount(); ++r) {
            QJsonObject dev;
            dev["deviceId"] = deviceModel->data(deviceModel->index(r, DeviceTableModel::DeviceId)).toString();
            dev["temperature"] = deviceModel->data(deviceModel->index(r, DeviceTableModel::Temperature), Qt::UserRole).toDouble();
            devices.append(dev);
        }
        return QHttpServerResponse(QJsonDocument(devices).toJson());
    });

auto *tcpServer = new QTcpServer(this);
if (!tcpServer->listen(QHostAddress::Any, 8080)) {
    logView->append("[HTTP] Failed to bind server port");
} else {
    httpServer->bind(tcpServer);
    logView->append("[HTTP] Serving API on port 8080");
}
```

**Important caveat worth being direct about**: this handler runs on the GUI thread by default, reading `deviceModel` directly is fine (same-thread, no cross-thread issue) — but it means **the request handler competes with the GUI event loop**, so keep handlers fast (this one just reads an in-memory model, which is fine) and never do blocking work (DB queries, file I/O) directly inside a route handler without dispatching it appropriately. Given you already have a mature FastAPI layer, `QHttpServer` is more of a "know it exists" tool than something `mqtt_monitor` actually needs — your Python API is the more natural place for this responsibility, and duplicating it in C++ would be redundant architecture, not an improvement.

### Why This Matters

- `QNetworkAccessManager` is the one I/O class in this curriculum that genuinely doesn't need a worker thread — knowing _why_ (already async under the hood) matters more than memorizing "network = no thread needed," since the reasoning is what tells you when an exception like this is legitimate versus when you're rationalizing skipping the pattern.
- `reply->deleteLater()` inside `finished()` is the single most common leak in real Qt networking code — a polling dashboard makes this leak fast and visible rather than a rare edge case.
- Explicit timeouts prevent silent hangs — don't rely on defaults, set `setTransferTimeout()` deliberately.
- `QHttpServer` handlers run on the GUI thread by default — fine for fast in-memory reads, wrong tool for anything blocking; and given your existing FastAPI investment, this is a "know it's there" capability rather than something to actually build out for `mqtt_monitor`.

### Exercise

1. Wire `fetchDeviceHistory()` to a "Load History" button on `DeviceCard`'s details action (Day 11's `detailsRequested` signal), pointed at your actual FastAPI backend's device-history endpoint, and confirm real data flows in.
2. Deliberately point the request at a wrong port (nothing listening) and confirm `errorOccurred`-equivalent handling in your `finished()` lambda logs a sensible message rather than the app hanging — then add `setTransferTimeout()` and confirm a genuinely unresponsive endpoint (not just a closed port, which fails fast anyway) actually times out instead of hanging indefinitely.
3. Run the app under a memory profiler (or just watch RSS via `top`) while polling `fetchDeviceHistory()` in a loop every second for a few minutes, first _without_ `reply->deleteLater()`, then _with_ it — make the leak concrete rather than theoretical, the same way Day 3 made the lambda-capture crash concrete.

### Key Takeaways

- `QNetworkAccessManager` + `QNetworkReply` is already fully async — no worker thread needed, unlike serial/MQTT/SQLite; the reasoning (non-blocking API by design) is what determines when this exception legitimately applies.
- Always `reply->deleteLater()` after reading a reply — this is the most common real-world Qt networking leak, and it's silent until it isn't.
- Set `setTransferTimeout()` explicitly; don't rely on there being a sane default.
- `QHttpServer` exists for exposing an API directly from the C++ process, but given your mature FastAPI layer, treat it as known-but-unused for this project — don't duplicate architecture that already exists and works.

---

Say "next" for Day 22 (data visualization — `QtCharts` for a live-updating temperature history chart per device, backed by the same SQLite history you built in Day 20).