[[Advanced]]

**Theory: what "thread affinity" actually means for a QObject**

Every `QObject` has a **thread affinity** — the specific thread it's considered to "live on," accessible via `QObject::thread()`. This isn't a vague notion; it's a concrete pointer to a `QThread` instance, set at construction time (to the thread that constructed it) and changeable exactly once safely, via `moveToThread()` (Day 16). Thread affinity determines **where a QObject's slots actually execute** when invoked via a queued connection (Day 3): a queued signal doesn't run the slot on the emitting thread — it posts an event to the _receiver's_ thread's event queue, and that receiver's own thread's event loop (each thread that runs `exec()` has its own independent loop) is what eventually dispatches it.

This is why, back on Day 2, the event loop mattered so much: **every thread that wants to receive queued signals or use QTimer must run its own event loop** (`exec()`) — a `QThread` that just does raw work in `run()` without calling `exec()` has no event loop, and any `QObject` living on it can't receive queued connections or use `QTimer` at all (timers need a running event loop to fire, per Day 4).

**The critical rule this sets up for Day 16:** you should almost never subclass `QThread` and override `run()` to do work directly — the idiomatic Qt6 pattern is to create a plain `QObject`-derived "worker," move it to a `QThread` instance via `moveToThread()`, and let that thread's default `run()` (which just calls `exec()`) provide the event loop the worker needs to receive its own queued slot invocations safely.

**Resolved example 1 — proving thread affinity is real and observable**

```cpp
#include <QCoreApplication>
#include <QThread>
#include <QDebug>
#include <QObject>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QObject mainThreadObject;
    qDebug() << "Main thread object's affinity:" << mainThreadObject.thread();
    qDebug() << "Current thread (QThread::currentThread()):" << QThread::currentThread();
    qDebug() << "Are they the same?" << (mainThreadObject.thread() == QThread::currentThread());

    // QCoreApplication itself has a well-known accessor for the thread it was constructed on
    qDebug() << "\napp's thread:" << app.thread();
    qDebug() << "Is app's thread the main thread?" << (app.thread() == QThread::currentThread());

    return 0;
}
```

**Resolved output:**

```
Main thread object's affinity: QThread(0x...)
Current thread (QThread::currentThread()): QThread(0x...)
Are they the same? true

app's thread: QThread(0x...)
Is app's thread the main thread? true
```

Resolved point: a freshly constructed `QObject` (with no explicit `moveToThread()` call) always has its thread affinity set to whatever thread constructed it — here, the main thread, since that's where `main()` runs. This is the default, and it silently stays true even for objects nested deep inside a call stack, unless something explicitly moves them.

**Resolved example 2 — a QThread with NO event loop (raw `run()` override) cannot receive queued signals or run a QTimer correctly**

```cpp
#include <QCoreApplication>
#include <QThread>
#include <QTimer>
#include <QDebug>

// Deliberately the WRONG pattern -- subclassing QThread and overriding run()
// to do work directly. Shown here specifically to demonstrate its limitation.
class BadWorkerThread : public QThread
{
    Q_OBJECT
protected:
    void run() override
    {
        qDebug() << "BadWorkerThread::run() executing on thread:" << QThread::currentThread();

        // Attempting to use a QTimer here is broken: there's no exec() call in
        // this run() override, so no event loop exists on this thread to ever
        // fire the timer's timeout() signal.
        QTimer timer;
        QObject::connect(&timer, &QTimer::timeout, [] {
            qDebug() << "this will NEVER print -- no event loop to dispatch it";
        });
        timer.start(500);

        // Without an event loop, we have to do something to prove the thread
        // is alive at all -- a raw sleep, which is fine here ONLY because this
        // thread has no event loop to block in the first place (no timers,
        // no queued signals depend on it staying responsive).
        QThread::sleep(2);
        qDebug() << "BadWorkerThread::run() finishing -- timer NEVER fired";
    }
};

#include "main.moc"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    BadWorkerThread worker;
    worker.start();
    worker.wait();   // block main thread until worker finishes, just for this demo

    qDebug() << "Main thread: worker finished, confirming the timer never printed anything above";

    return 0;
}
```

**Resolved output:**

```
BadWorkerThread::run() executing on thread: QThread(0x...)
BadWorkerThread::run() finishing -- timer NEVER fired
Main thread: worker finished, confirming the timer never printed anything above
```

The resolved proof: the `QTimer`'s `timeout()` signal never fires, because `run()` never calls `exec()` — there's no event loop on that thread to notice the timer expired and dispatch the event (recall Day 2's exact mechanism: timers fire _through_ the event loop, they're not independently-scheduled interrupts). This is exactly the trap Day 16 will show you the correct fix for.

**Resolved example 3 — a QThread that DOES run an event loop (default `run()`, unmodified) correctly supports QTimer and queued signals**

```cpp
#include <QCoreApplication>
#include <QThread>
#include <QTimer>
#include <QObject>
#include <QDebug>

class GoodWorker : public QObject
{
    Q_OBJECT
public slots:
    void startTicking()
    {
        qDebug() << "GoodWorker::startTicking() running on thread:" << QThread::currentThread();
        auto *timer = new QTimer(this);   // parented to 'this' -- per Day 5, cleaned up when GoodWorker is destroyed
        connect(timer, &QTimer::timeout, this, &GoodWorker::onTick);
        timer->start(500);
    }

signals:
    void tickOccurred(int count);

private slots:
    void onTick()
    {
        static int count = 0;
        ++count;
        qDebug() << "GoodWorker tick" << count << "on thread:" << QThread::currentThread();
        emit tickOccurred(count);
        if (count >= 3) qApp->quit();
    }
};

#include "main.moc"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    qDebug() << "Main thread is:" << QThread::currentThread();

    QThread workerThread;              // plain QThread, run() NOT overridden -- default run() just calls exec()
    GoodWorker worker;
    worker.moveToThread(&workerThread);   // reassigns worker's thread affinity -- full mechanics on Day 16

    // This connection crosses threads: main thread's start() signal to workerThread's slot.
    // Because sender (workerThread, whose started() signal fires ON workerThread once it begins)
    // and receiver (worker, now living on workerThread) are on the SAME thread as each other
    // at delivery time, Qt::AutoConnection (Day 3) resolves this as effectively direct,
    // but it still only runs once workerThread's event loop is up.
    QObject::connect(&workerThread, &QThread::started, &worker, &GoodWorker::startTicking);
    QObject::connect(&worker, &GoodWorker::tickOccurred, [](int c) {
        qDebug() << "[main thread observer] saw tick" << c << "via queued connection, on thread:" << QThread::currentThread();
    });

    workerThread.start();   // NOW workerThread runs its default run() -> calls exec() -> event loop is live

    int result = app.exec();   // main thread's OWN event loop, needed to receive the queued tickOccurred connection

    workerThread.quit();
    workerThread.wait();

    return result;
}
```

**Resolved output:**

```
Main thread is: QThread(0x_main)
GoodWorker::startTicking() running on thread: QThread(0x_worker)
GoodWorker tick 1 on thread: QThread(0x_worker)
[main thread observer] saw tick 1 via queued connection, on thread: QThread(0x_main)
GoodWorker tick 2 on thread: QThread(0x_worker)
[main thread observer] saw tick 2 via queued connection, on thread: QThread(0x_main)
GoodWorker tick 3 on thread: QThread(0x_worker)
[main thread observer] saw tick 3 via queued connection, on thread: QThread(0x_main)
```

This is the fully resolved picture Day 3 promised: `worker`'s `tickOccurred` signal is emitted **on the worker thread**, but the connected lambda observer — living implicitly on the main thread (it was connected from `main()`, and has no explicit QObject receiver of its own, so Qt treats it via the main thread context here) — actually executes **on the main thread**, automatically, correctly, and safely, with zero manual synchronization code from you. This is only possible because: (1) `workerThread` runs an event loop (default, un-overridden `run()`), enabling `GoodWorker`'s `QTimer` to work at all; (2) `worker` was moved to that thread via `moveToThread()`, so its slots execute there; (3) the connection to the main-thread lambda is auto-detected as cross-thread and delivered via `Qt::QueuedConnection`, going through the main thread's own event loop (`app.exec()`) — exactly the mechanism from Day 3, now actually crossing real threads instead of the same-thread illustration used there.

**Key takeaways:**

- Every `QObject` has a thread affinity (`QObject::thread()`), set at construction to the constructing thread, changeable via `moveToThread()`.
- A thread only has a usable event loop if something calls `exec()` on it — `QThread`'s **default**, un-overridden `run()` does exactly this; overriding `run()` to do raw work yourself removes that event loop unless you call `exec()` inside it explicitly.
- Without an event loop on a given thread, `QObject`s living there cannot use `QTimer` or receive queued-connection slot invocations — both mechanisms depend entirely on the event loop from Day 2 to actually dispatch anything.
- The idiomatic Qt6 threading pattern (previewed here, formalized Day 16) is: don't subclass `QThread`; instead, create a plain `QObject`-derived worker, `moveToThread()` it onto a plain `QThread` instance, and let signals/slots — correctly auto-detected as cross-thread — handle all communication safely, with the event loop machinery doing the actual cross-thread delivery work for you.

Day 16 formalizes this pattern fully: the complete `QThread` + `moveToThread` worker idiom, including correct startup/shutdown sequencing and why this is preferred over the `QThread` subclassing you saw deliberately misused in Example 2.