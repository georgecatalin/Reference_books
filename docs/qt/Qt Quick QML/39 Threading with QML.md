[[Qt Quick QML]]

## Day 9: Threading With QML — Same Worker Rules, Consumed From the QML Side

### Concept: Nothing About Your Threading Architecture Changes — Only the Consumer Does

This is genuinely good news, and worth stating up front so today doesn't feel like relearning threading from scratch: **every worker-object rule from Day 16 of the Widgets curriculum (`moveToThread`, create thread-sensitive objects in `start()`, never touch UI from a worker thread, `finished`/`quit`/`deleteLater` shutdown chain) applies completely unchanged when QML is the front end instead of Widgets.** `MqttWorker`, `SerialWorker`, `PersistenceWorker` — none of them need to know or care whether a `QTableView` or a QML `ListView` is eventually consuming their signals. The only new material today is: how does a signal emitted from a worker thread actually reach a QML property binding, and what's `WorkerScript` for when the _heavy work itself_ needs to happen in QML/JS rather than C++.

### The Actual Mechanism: C++ Worker Signal → QML Property Binding

This is simpler than it might sound, precisely because you already did the hard part (Day 4's `DeviceTableModel` role names, `dataChanged` discipline). A worker's signal, connected to a slot on your exposed C++ object (the singleton `ApiClient`, or `DeviceTableModel` itself), which updates a `Q_PROPERTY` — QML's binding system picks up the change automatically, **exactly like Day 3's `onXChanged` behavior**, because QML properties and Qt's `Q_PROPERTY`/signal system are the same underlying mechanism.

```cpp
// apiclient.h — adding a genuinely QML-facing property, using standard
// Q_PROPERTY, same mechanism as Day 12's Widgets animation properties
class ApiClient : public QObject {
    Q_OBJECT
    Q_PROPERTY(bool isHealthy READ isHealthy NOTIFY healthChanged)

public:
    bool isHealthy() const { return healthy; }

signals:
    void healthChanged();
    // ... existing signals from the integration build ...

private:
    bool healthy = false;
};
```

```cpp
// In the existing checkHealth() handler (unchanged threading — this still
// runs via QNetworkAccessManager's already-async model, Day 21, no worker
// thread needed here specifically):
connect(reply, &QNetworkReply::finished, this, [this, reply]() {
    reply->deleteLater();
    bool wasHealthy = healthy;
    healthy = (reply->error() == QNetworkReply::NoError);
    if (healthy != wasHealthy) {
        emit healthChanged();  // THIS is what makes QML's binding re-evaluate
    }
    emit healthCheckResult(healthy, /* ... */);
});
```

```qml
// ANY QML file — this binding updates automatically the instant
// healthChanged() fires, with ZERO QML-side polling or manual refresh code
Rectangle {
    color: ApiClient.isHealthy ? "#2ecc71" : "#e74c3c"
}
```

**This is the whole mechanism.** For a genuinely cross-thread worker (`MqttWorker` on its own `QThread`, per Day 16/19's pattern, entirely unchanged), the same thing applies — the worker emits a signal, Qt's queued cross-thread delivery (still automatic, still correct, still exactly Day 3's connection-type rules) delivers it to a slot on the GUI-thread-resident object, that slot updates a `Q_PROPERTY` and emits its change signal, and QML picks it up. **No QML-specific threading concept exists here at all** — it's the identical mechanism as Day 16, with QML simply being one more consumer of ordinary Qt signals/properties.

### Wiring `DeviceTableModel` Live Updates Into QML — Confirming End-to-End

This closes the loop from Day 4 with your actual `MqttWorker`, unchanged from the integration build:

```cpp
// main_qml.cpp — same worker setup as your Widgets MainWindow, just no
// QMainWindow/QWidget anywhere in sight
QThread *mqttThread = new QThread();
MqttWorker *mqttWorker = new MqttWorker("localhost", 1883, "mqtt_monitor_qml");
mqttWorker->moveToThread(mqttThread);

connect(mqttThread, &QThread::started, mqttWorker, &MqttWorker::start);

DeviceTableModel deviceModel;
connect(mqttWorker, &MqttWorker::readingReceived, &deviceModel,
        [&deviceModel](const QString &deviceId, const QString &variable, double value) {
    // Cross-thread signal, queued delivery, exactly Day 16 — this lambda
    // runs on the GUI thread even though readingReceived was emitted from
    // mqttThread, because the receiver (&deviceModel) lives on the GUI thread
    deviceModel.upsertReading({deviceId, variable, value, QDateTime::currentDateTime(), true});
});

connect(mqttWorker, &MqttWorker::finished, mqttThread, &QThread::quit);
connect(mqttThread, &QThread::finished, mqttWorker, &QObject::deleteLater);
connect(mqttThread, &QThread::finished, mqttThread, &QObject::deleteLater);

engine.rootContext()->setContextProperty("deviceModel", &deviceModel);
mqttThread->start();
```

The QML `ListView` from Day 4, bound to `deviceModel`, updates live the instant real MQTT messages arrive — **nothing about this required new threading knowledge**, only correctly connecting a signal you already understand to a model you already built.

### `WorkerScript` — For When the Heavy Work Is _In_ QML/JS, Not C++

Everything above assumes your heavy lifting (parsing, I/O, persistence) lives in C++ workers, which is the right default and what your actual `mqtt_monitor` backend already does. `WorkerScript` exists for a **different, narrower case**: genuinely CPU-intensive work expressed in QML/JavaScript itself (not calling into C++) that would otherwise block QML's single-threaded JS engine and stall UI rendering — e.g., client-side processing of a large downloaded JSON blob before display, done entirely in JS for some reason (rare, but real).

```qml
WorkerScript {
    id: worker
    source: "processor.js"

    onMessage: (messageObject) => {
        // Result arrives back on the QML/GUI thread automatically —
        // WorkerScript handles the cross-thread hop for you, conceptually
        // similar to Day 16's queued signal delivery but JS-specific
        resultLabel.text = "Processed: " + messageObject.count
    }
}

Button {
    text: "Process Large Dataset"
    onClicked: worker.sendMessage({ data: largeDataArray })
}
```

`processor.js`:

```javascript
WorkerScript.onMessage = function(message) {
    // Runs on a SEPARATE JS engine instance, genuinely off the GUI thread —
    // this function CANNOT access any QML objects/properties directly,
    // only the plain-data message it was sent (numbers, strings, arrays,
    // plain objects — no QML Item references, no C++ object references)
    var result = message.data.length; // some actual CPU-bound work here
    WorkerScript.sendMessage({ count: result });
};
```

**The real limitation worth understanding precisely**: `WorkerScript`'s JS runs in a completely separate script engine with **no access to QML objects, C++ objects, or the main JS engine's state** — only plain data passed via `sendMessage`/`onMessage`. This is a much more restrictive isolation boundary than a C++ `QThread` worker, which (correctly used) can still hold references to `QObject`s, just not touch GUI widgets directly. **For `mqtt_monitor` specifically, you almost certainly never need `WorkerScript`** — your actual heavy lifting (MQTT I/O, serial parsing, SQLite writes) is already correctly in C++ workers from Day 16–20 of the Widgets curriculum, unchanged. `WorkerScript` is included today so you recognize it exists and know its narrow, real use case — not because your project needs it.

### Why This Matters

- **Zero new threading concepts exist today** — this is the actual point. The worker-object pattern, `moveToThread`, queued cross-thread signals, and the `finished`/`quit`/`deleteLater` shutdown chain are exactly as correct and exactly as necessary with a QML front end as with Widgets — you're not learning "QML threading," you're confirming that Day 16's architecture was genuinely UI-stack-agnostic all along, which was the whole premise motivating Day 5's shared-library structure.
- **`Q_PROPERTY` + `NOTIFY` is the actual bridge between C++ worker state and QML bindings** — same mechanism from Day 12's Widgets animation work, now serving a different consumer.
- **`WorkerScript`'s isolation (no QObject/QML access, plain-data-only messaging) is far more restrictive than a proper C++ `QThread` worker** — recognizing this distinction prevents reaching for `WorkerScript` when what you actually need is a normal C++ worker, which is the correct default for nearly everything in a real backend-driven application like yours.
- **For `mqtt_monitor` specifically, today's real takeaway is confirmation, not new construction** — your Day 16–20 threading work was already correctly UI-agnostic; today just proves it by successfully consuming it from QML with no changes to the worker code itself.

### Exercise

1. Take your actual `MqttWorker`/`DeviceTableModel` wiring from the integration build, run it against a real (or simulated) MQTT publisher with the QML `ListView` from Day 4 open, and confirm live updates arrive with zero polling — add a `qDebug() << QThread::currentThread()` in both the worker's message handler and the GUI-thread lambda that calls `upsertReading()`, exactly as Day 16's exercise did, to reconfirm the thread boundary is real and correct in this QML context too.
2. Add the `Q_PROPERTY(bool isHealthy ...)` pattern to your real `ApiClient` (not a toy copy), bind a QML `Rectangle`'s color to it as shown, and verify it updates automatically when you stop/restart your actual FastAPI backend — no QML-side timer or polling code needed beyond what `ApiClient`'s existing `healthCheckTimer` (from the Widgets integration) already does in C++.
3. Write a one-paragraph note confirming (or challenging, if you find a real counterexample in your own code) the claim that none of your Day 16–20 worker classes needed any modification to work correctly with a QML front end — this is the kind of architecture-validation habit worth exercising deliberately, mirroring Day 24's and Day 27's written-note exercises.

### Key Takeaways

- The worker-object threading pattern from Day 16 (Widgets) applies completely unchanged with a QML front end — QML is just another consumer of ordinary Qt signals and `Q_PROPERTY`/`NOTIFY`, not a reason to learn new threading rules.
- `Q_PROPERTY` + `NOTIFY`, exactly as used in Day 12, is the actual bridge mechanism between C++ worker state changes and live QML bindings.
- `WorkerScript` is for genuinely CPU-heavy QML/JavaScript-side work, with a much more restrictive isolation boundary (plain-data messaging only, no QObject/QML access) than a proper C++ `QThread` worker — a narrow tool your project likely never needs, since your real heavy lifting already correctly lives in C++.
- Today's actual value is confirming your existing backend architecture was UI-stack-agnostic all along — the same validation exercise as Day 5's shared-library extraction, now proven by successful QML consumption rather than just asserted.

---

Say "next" for Day 10 — the final day: the capstone touchscreen `mqtt_monitor` panel, wiring together everything from Days 1–9 (real models, components, states, touch-appropriate design, performance discipline, and your actual unchanged C++ backend) into one cohesive QML application, plus a closing comparison of when to reach for QML vs. Widgets going forward.