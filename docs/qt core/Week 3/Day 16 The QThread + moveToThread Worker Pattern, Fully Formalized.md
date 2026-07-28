[[Advanced]]

Day 15 previewed this pattern in Example 3. Today: the complete idiom, including correct startup/shutdown sequencing, passing data safely to and from a worker, and precisely why this beats subclassing `QThread`.

**Theory: why moveToThread over subclassing, stated precisely**

Subclassing `QThread` and overriding `run()` (Day 15's deliberately-broken example) conflates two distinct concerns: `QThread` is a **thread control object** — it exists to manage a thread's lifecycle (start, quit, wait) from the _outside_, typically from the thread that created it. It is not meant to be a place to put your actual work. When you override `run()`, your work executes on the new thread, but the `QThread` object itself still lives on (has thread affinity to) the _original_ thread that constructed it — an easy-to-miss inconsistency: `myThread.thread()` returns the creating thread, not the thread `myThread` manages. This mismatch is exactly why Day 15's `BadWorkerThread` couldn't just add a `QObject` member and expect it to behave correctly on the new thread — nothing you construct as a member automatically gets the new thread's affinity.

The correct idiom fully separates the two roles:

- **`QThread` instance** — unmodified, default `run()` (just calls `exec()`), used purely as a lifecycle handle: `start()`, `quit()`, `wait()`.
- **Worker object** — a plain `QObject`-derived class holding your actual logic, explicitly moved onto the `QThread` via `moveToThread()`. Its slots then execute on that thread because of the mechanism from Day 15 (thread affinity determines where queued slots run).

**Resolved example 1 — the complete, correct pattern with proper startup, work dispatch, and clean shutdown**

```cpp
// serialworker.h
#pragma once
#include <QObject>
#include <QString>
#include <QRandomGenerator>
#include <QTimer>

// This models a worker that would, in real mqtt_monitor code, own the actual
// blocking serial port read loop -- kept off the main thread so it never
// stalls the main event loop (Day 2's warning, now properly addressed).
class SerialWorker : public QObject
{
    Q_OBJECT
public:
    explicit SerialWorker(QObject *parent = nullptr) : QObject(parent) {}

public slots:
    // Called once the worker has been moved to its thread and that thread has started.
    void initialize()
    {
        qDebug() << "SerialWorker::initialize() on thread:" << QThread::currentThread();
        // In real code: open the serial port here, NOT in the constructor --
        // the constructor may run on the wrong thread if the object is
        // constructed before moveToThread() is called (see resolved note below).
        auto *pollTimer = new QTimer(this);   // parented to 'this' (Day 5) -- lives and dies with the worker
        connect(pollTimer, &QTimer::timeout, this, &SerialWorker::simulateRead);
        pollTimer->start(300);
    }

    // Called from the main thread via a queued connection to request work --
    // this is how you SEND commands INTO the worker thread correctly.
    void setPollInterval(int ms)
    {
        qDebug() << "SerialWorker::setPollInterval(" << ms << ") on thread:" << QThread::currentThread();
        // (would reconfigure the real timer here)
    }

    void shutdown()
    {
        qDebug() << "SerialWorker::shutdown() on thread:" << QThread::currentThread();
        emit finished();   // tell the QThread it's safe to quit()
    }

signals:
    void lineReady(const QString &line);   // how results get OUT of the worker thread
    void finished();

private slots:
    void simulateRead()
    {
        double fakeTemp = 20.0 + QRandomGenerator::global()->bounded(100) / 10.0;
        emit lineReady(QString("TEMP:%1").arg(fakeTemp));
    }
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include <QThread>
#include <QTimer>
#include <QDebug>
#include "serialworker.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    qDebug() << "Main thread:" << QThread::currentThread();

    // Step 1: create the QThread (lifecycle handle) and the worker (logic),
    // BOTH still on the main thread at this point -- this is fine and expected.
    auto *workerThread = new QThread();
    auto *worker = new SerialWorker();   // NOTE: no parent -- objects moved to another
                                          // thread must NOT have a parent in a different
                                          // thread; Qt enforces this, parenting across
                                          // threads is not allowed. We manage its lifetime manually.

    // Step 2: move the worker's thread affinity to workerThread.
    // From this point on, ALL of worker's slots execute on workerThread,
    // per Day 15's thread-affinity mechanism.
    worker->moveToThread(workerThread);

    // Step 3: wire up the startup sequence -- QThread::started fires once
    // exec() begins on the new thread, which is the correct, safe moment
    // to call the worker's initialize() (NOT the constructor -- the
    // constructor ran on the main thread, before the move).
    QObject::connect(workerThread, &QThread::started, worker, &SerialWorker::initialize);

    // Step 4: wire up clean shutdown -- worker signals finished(), which
    // triggers workerThread->quit() (stops its event loop), and once the
    // thread object itself is done, we deleteLater() both objects.
    QObject::connect(worker, &SerialWorker::finished, workerThread, &QThread::quit);
    QObject::connect(workerThread, &QThread::finished, worker, &QObject::deleteLater);
    QObject::connect(workerThread, &QThread::finished, workerThread, &QObject::deleteLater);

    // Step 5: observe results arriving from the worker thread
    int lineCount = 0;
    QObject::connect(worker, &SerialWorker::lineReady, [&](const QString &line) {
        ++lineCount;
        qDebug() << "[main, count" << lineCount << "] received:" << line
                  << "-- observer running on thread:" << QThread::currentThread();
        if (lineCount >= 5) {
            // Requesting shutdown MUST go through a queued call (invokeMethod),
            // NOT a direct call to worker->shutdown() -- that would run shutdown()
            // ON THE MAIN THREAD by accident, which is wrong: we want it to run
            // on workerThread, where 'worker' actually lives.
            QMetaObject::invokeMethod(worker, "shutdown", Qt::QueuedConnection);
        }
    });

    // Step 6: NOW start the thread -- this is what triggers QThread::started above
    workerThread->start();

    QObject::connect(workerThread, &QThread::finished, &app, &QCoreApplication::quit);

    return app.exec();
}
```

**Resolved output:**

```
Main thread: QThread(0x7f_main)
SerialWorker::initialize() on thread: QThread(0x7f_worker)
[main, count 1] received: "TEMP:24.3" -- observer running on thread: QThread(0x7f_main)
[main, count 2] received: "TEMP:21.7" -- observer running on thread: QThread(0x7f_main)
[main, count 3] received: "TEMP:28.9" -- observer running on thread: QThread(0x7f_main)
[main, count 4] received: "TEMP:22.1" -- observer running on thread: QThread(0x7f_main)
[main, count 5] received: "TEMP:26.4" -- observer running on thread: QThread(0x7f_main)
SerialWorker::shutdown() on thread: QThread(0x7f_worker)
```

Program then exits cleanly — `shutdown()` emits `finished()`, which triggers `workerThread->quit()`, whose own `finished()` signal triggers both `deleteLater()` calls and, via the final connection, `app.quit()`.

**Resolved detail worth isolating: why `QMetaObject::invokeMethod(..., Qt::QueuedConnection)` and not `worker->shutdown()` directly**

This is the single most common mistake in real-world Qt threading code, so it's worth being explicit about the resolved reasoning: `worker->shutdown()` is an ordinary C++ method call — it has no idea about thread affinity or connection types at all (those only apply to signal/slot _connections_, per Day 3). Calling it directly from the main thread's lambda would execute `shutdown()`'s body **on the main thread**, synchronously — even though `worker` "lives" on `workerThread` — because a direct method call always runs on whatever thread called it. `QMetaObject::invokeMethod` with `Qt::QueuedConnection` is what correctly posts the call as an event to `worker`'s actual thread (`workerThread`), ensuring `shutdown()`'s body genuinely executes there, matching the `QThread::currentThread()` output shown above. Getting this wrong doesn't necessarily crash — it just silently makes your "thread-safe" worker not actually thread-safe, since you're now touching its internals from the wrong thread.

**Resolved example 2 — the parenting trap: why the worker has no parent until it's on its thread**

```cpp
// BROKEN attempt:
auto *someOwner = new QObject();               // lives on main thread
auto *worker = new SerialWorker(someOwner);     // parented to someOwner -- ALSO main thread
worker->moveToThread(workerThread);              // Qt will emit a runtime WARNING here:
// "QObject::moveToThread: Cannot move objects with a parent"
// The move is refused; worker stays on the main thread; nothing above actually works as intended.
```

**Resolved fix:** never give a to-be-moved object a parent before moving it (as shown correctly in Example 1 — `worker` is constructed with no parent). If you need `worker` to be owned by something for lifetime purposes, use the `deleteLater()` pattern shown in Example 1's Step 4 instead — cleanup triggered by a signal, not by Qt's parent/child tree, since that tree assumes everything in it shares one thread affinity.

**Key takeaways:**

- `QThread` and your worker logic are two separate objects with two separate responsibilities: `QThread` is a lifecycle handle (start/quit/wait), the worker holds the actual logic and gets moved onto it via `moveToThread()`.
- Do initialization that must run _on the new thread_ (like opening a serial port) in a slot connected to `QThread::started`, not in the worker's constructor — the constructor runs before the move, on the original thread.
- An object cannot be `moveToThread()`'d if it currently has a parent — Qt refuses the move and warns at runtime. Manage cross-thread object lifetime via `deleteLater()` connected to `QThread::finished`, not the parent/child tree.
- To call a method on a worker living on another thread, use `QMetaObject::invokeMethod(obj, "methodName", Qt::QueuedConnection)` (or a signal connected across threads) — never call the method directly, since a direct call always executes on the _caller's_ thread regardless of the object's actual affinity.
- The full lifecycle — `started` → `initialize()`, work happens via signals in/out, `finished()` → `quit()` → `finished()` → `deleteLater()` ×2 — is the standard, reusable shutdown sequence you'll want for every worker-thread component in `mqtt_monitor`.

Day 17 covers `QMutex` and `QReadWriteLock` — for the cases where two threads genuinely need to touch the _same_ shared data structure directly (rather than communicating purely through signals/slots, as this pattern has done so far), and exactly when that's actually necessary versus when signals/slots alone already give you enough safety.
