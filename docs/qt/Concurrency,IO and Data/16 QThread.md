[[Concurrency]]

## Day 16: `QThread` Done Correctly — The Worker-Object Pattern

### Concept: The #1 Qt Threading Mistake, Stated Up Front

The single most common wrong approach, which you will find in countless outdated tutorials: **subclassing `QThread` and overriding `run()`**. This is not how modern Qt threading is meant to work in the vast majority of cases, and it's the source of endless confused bug reports online. The correct, current pattern — and what Qt's own documentation has recommended for years — is:

> **`QThread` represents a thread of execution. Your actual work belongs in a plain `QObject` ("worker") that you `moveToThread()` onto a `QThread` instance. You do not subclass `QThread` for business logic.**

This matters enormously for `mqtt_monitor`: your serial reader and MQTT client are exactly the kind of long-running, event-driven I/O work that needs to happen off the GUI thread, and doing it wrong here creates subtle crashes and race conditions that are miserable to debug.

### Why Subclassing `QThread` Is Wrong (The Part Tutorials Skip)

When you subclass `QThread` and override `run()`, code inside `run()` executes on the new thread — but the `QThread` object itself, and any slots you define on that subclass, still live on the thread that _created_ it (usually the GUI thread), unless you're careful. This creates a confusing split: some of your object's code runs on one thread, some on another, depending on _how it's called_ rather than anything visible in the code. It's a well-known correctness trap. The worker-object pattern avoids this entirely by keeping the ownership question simple: **the worker object and everything it touches lives entirely on the worker thread, full stop.**

### The Correct Pattern, Step by Step

1. Write your work as a plain `QObject` subclass (the "worker") — no `QThread` inheritance at all.
2. Create a `QThread` instance (not subclassed, used as-is).
3. `worker->moveToThread(thread)` — this reassigns which thread's event loop delivers events/queued signals to the worker.
4. Connect `thread->started()` to a worker slot that kicks off the actual work — this is what runs _on_ the new thread, triggered once the thread's event loop starts.
5. Communicate **only** via signals/slots across the thread boundary — never call worker methods directly from the GUI thread, and never touch GUI widgets directly from the worker.
6. Handle shutdown carefully — this is where most remaining bugs live.

### Annotated Code: A Worker Object Skeleton (Serial/MQTT-Shaped, Simulated for Now)

This models the shape your real serial reader (Day 18) and MQTT client (Day 19) will take — today's version simulates the I/O with a timer so the pattern is isolated from any actual hardware/network complexity.

`ingestionworker.h`:

```cpp
#pragma once
#include <QObject>
#include <QTimer>

// Plain QObject — NOT a QThread subclass. This is the entire point.
class IngestionWorker : public QObject {
    Q_OBJECT
public:
    explicit IngestionWorker(QObject *parent = nullptr);

public slots:
    // Called once the worker has been moved to its thread and that
    // thread's event loop has started — this is the actual "entry point"
    void start();

    // Called from the GUI thread (via queued connection) to request shutdown
    void stop();

signals:
    // The ONLY way data crosses back to the GUI thread — never a direct call
    void readingReady(QString deviceId, double temperature);
    void errorOccurred(QString message);
    void finished(); // tells the QThread it can quit()

private slots:
    void simulateNextReading(); // runs periodically ON the worker thread

private:
    QTimer *simTimer = nullptr;
    int counter = 0;
};
```

`ingestionworker.cpp`:

```cpp
#include "ingestionworker.h"
#include <QRandomGenerator>

IngestionWorker::IngestionWorker(QObject *parent) : QObject(parent) {}

void IngestionWorker::start() {
    // CRITICAL: QTimer created HERE, not in the constructor. The
    // constructor may run on the GUI thread (before moveToThread), but
    // start() is guaranteed to run on the worker thread, since it's only
    // invoked via the thread->started() connection. Any QObject you create
    // must be created on the thread it will live/operate on — creating the
    // QTimer in the constructor would silently attach it to the wrong thread.
    simTimer = new QTimer(this); // 'this' as parent — thread-affinity matches worker
    connect(simTimer, &QTimer::timeout, this, &IngestionWorker::simulateNextReading);
    simTimer->start(1000); // one simulated reading per second
}

void IngestionWorker::simulateNextReading() {
    counter++;
    QString deviceId = QString("device-%1").arg((counter % 3) + 1, 2, 10, QChar('0'));
    double temp = 30.0 + QRandomGenerator::global()->bounded(20.0);

    // emit is safe here regardless of who's connected or on what thread —
    // Qt handles the cross-thread queued delivery automatically (Day 3)
    emit readingReady(deviceId, temp);
}

void IngestionWorker::stop() {
    if (simTimer) {
        simTimer->stop();
    }
    emit finished(); // signal the thread it's safe to quit its event loop
}
```

### Wiring It Up: The Full Lifecycle in `MainWindow`

`mainwindow.h`:

```cpp
#include <QThread>
#include "ingestionworker.h"

private:
    void setupIngestionThread();

private:
    QThread *ingestionThread = nullptr;
    IngestionWorker *ingestionWorker = nullptr;
```

`mainwindow.cpp`:

```cpp
void MainWindow::setupIngestionThread() {
    ingestionThread = new QThread(this); // the QThread object itself stays
                                           // on the GUI thread (its parent's thread) —
                                           // that's correct and expected
    ingestionWorker = new IngestionWorker(); // NOTE: no parent here —
                                               // an object with a parent cannot be
                                               // moved to another thread via moveToThread()

    ingestionWorker->moveToThread(ingestionThread);

    // Step 1: when the thread starts, kick off the worker's real entry point
    connect(ingestionThread, &QThread::started, ingestionWorker, &IngestionWorker::start);

    // Step 2: worker's results flow back to the GUI thread automatically
    // via queued connection (different threads -> AutoConnection resolves to Queued)
    connect(ingestionWorker, &IngestionWorker::readingReady, this,
            [this](const QString &deviceId, double temp) {
        deviceModel->upsertReading({deviceId, QDateTime::currentDateTime(), temp, true});
    });

    connect(ingestionWorker, &IngestionWorker::errorOccurred, this,
            [this](const QString &msg) {
        logView->append("[INGESTION ERROR] " + msg);
    });

    // Step 3: proper shutdown chain — worker signals finished() when done,
    // which tells the thread to quit its event loop, which triggers cleanup
    connect(ingestionWorker, &IngestionWorker::finished, ingestionThread, &QThread::quit);
    connect(ingestionThread, &QThread::finished, ingestionWorker, &QObject::deleteLater);
    connect(ingestionThread, &QThread::finished, ingestionThread, &QObject::deleteLater);

    ingestionThread->start(); // starts the thread's event loop; started() fires next
}
```

Call `setupIngestionThread()` once during `MainWindow` construction.

### Shutdown — The Part That Actually Causes Crashes If Done Wrong

```cpp
void MainWindow::closeEvent(QCloseEvent *event) {
    saveSettings();

    if (ingestionThread && ingestionThread->isRunning()) {
        // Ask the worker to stop — via QUEUED call across the thread boundary,
        // NOT a direct call to ingestionWorker->stop() from this thread
        QMetaObject::invokeMethod(ingestionWorker, "stop", Qt::QueuedConnection);

        // Wait for the thread to actually finish before the app tears down —
        // without this, the app can start destroying objects the worker
        // thread is still using, a classic use-after-free during shutdown
        if (!ingestionThread->wait(3000)) { // 3 second timeout, don't hang forever
            ingestionThread->terminate(); // last resort — genuinely dangerous,
                                            // only acceptable as a shutdown safety net
            ingestionThread->wait();
        }
    }

    event->accept();
}
```

**`QMetaObject::invokeMethod(..., Qt::QueuedConnection)`** deserves explanation: you cannot call `ingestionWorker->stop()` directly from the GUI thread — that would execute `stop()`'s body on the _calling_ thread (the GUI thread), touching `simTimer` (which lives on the worker thread) from the wrong thread — undefined behavior. `QMetaObject::invokeMethod` with `QueuedConnection` posts the call as an event to the worker thread's queue, exactly like a signal/slot connection would, ensuring `stop()`'s body actually executes on the worker thread where `simTimer` lives.

### Why This Matters — The Rules, Stated Plainly

1. **Never create a `QObject` in a worker's constructor if the worker will be `moveToThread()`'d afterward.** Create thread-affinity-sensitive objects (`QTimer`, `QTcpSocket`, `QSerialPort` in later days) inside a slot connected to `started()`, so they're constructed on the correct thread.
2. **Never give the worker a parent before calling `moveToThread()`.** `moveToThread()` fails (silently, with a runtime warning) on objects that have a parent — parented objects move with their parent, not independently.
3. **Never call worker methods directly from the GUI thread** — always go through signals/slots or `QMetaObject::invokeMethod` with `QueuedConnection`, so the call is properly delivered to the worker thread's event loop rather than executing on the wrong thread.
4. **Never touch GUI widgets from the worker thread** — this is the cardinal Qt threading rule. All widget access must happen on the main/GUI thread. The worker only ever `emit`s; the GUI thread's slots (connected with the implicit queued cross-thread connection) do the actual widget updates.
5. **The `finished()` → `quit()` → `deleteLater()` chain is the standard, correct shutdown sequence** — worker signals it's done, thread quits its event loop, and _then_ both worker and thread objects are scheduled for deletion via `deleteLater()` (never plain `delete`, since other queued events might still reference them).
6. **`terminate()` is a last resort**, not a normal shutdown tool — it can leave the worker in a genuinely corrupted state (mid-operation, holding a lock, mid-file-write) since it forcibly kills the thread without letting it clean up. A well-behaved `stop()`/`finished()` handshake with a reasonable `wait()` timeout should make `terminate()` essentially unreachable in practice; it's there as a safety net against a badly-hung worker, not a routine path.

### Exercise

1. Build and run today's code, confirm `readingReady` updates flow into `deviceModel` and both table/grid views (Day 15) update live, with the ingestion happening entirely off the GUI thread. Add a `qDebug() << QThread::currentThread();` inside both `IngestionWorker::simulateNextReading()` and the lambda handling `readingReady` in `MainWindow`, and confirm they print _different_ thread pointers — make the thread boundary concrete rather than assumed.
2. Deliberately break rule #1: move the `QTimer` creation from `start()` into `IngestionWorker`'s constructor, rebuild, and observe the warning Qt prints about a timer being used from the wrong thread (or the timer simply never firing correctly). Then revert.
3. Deliberately break rule #3: replace the `QMetaObject::invokeMethod(..., Qt::QueuedConnection)` shutdown call with a direct `ingestionWorker->stop()` call, run it under `-fsanitize=thread` (a new sanitizer flag for you — add `-fsanitize=thread` to your `ENABLE_SANITIZERS` CMake option, though note it can't combine with ASan in the same build, so use a separate build directory), and observe what it reports about the cross-thread access. This is worth doing once under TSan specifically — it's the tool that actually catches this class of bug reliably, unlike casual visual inspection.

### Key Takeaways

- Never subclass `QThread` for business logic — use a plain `QObject` worker moved to a plain `QThread` instance via `moveToThread()`.
- Create thread-sensitive objects (timers, sockets, serial ports) inside a slot triggered by `started()`, never in the worker's constructor.
- Never give a worker a parent before `moveToThread()` — parented objects can't be moved independently.
- Cross-thread calls must go through signals/slots or `QMetaObject::invokeMethod(..., Qt::QueuedConnection)` — never a direct method call.
- GUI widgets are touched only from the GUI thread; the worker only emits signals, GUI-thread slots handle the actual UI updates.
- Shutdown sequence: worker emits `finished()` → connected to `thread->quit()` → connected to `deleteLater()` for both worker and thread. `terminate()` is an emergency fallback, not routine cleanup.
- Build and test under `-fsanitize=thread` at least once — it's the correct tool for catching cross-thread violations that are otherwise easy to miss by inspection alone.

---

Say "next" for Day 17 (applying this exact pattern to real hardware: `QSerialPort` on a worker thread, reading line-delimited data from a Raspberry Pi/BeagleBone-style serial device, with correct buffering for partial reads).