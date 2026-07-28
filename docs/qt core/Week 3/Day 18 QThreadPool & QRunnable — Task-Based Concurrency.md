[[Advanced]]

**Theory: why task-based concurrency is a different model from Day 16's long-lived workers**

Day 16's `QThread` + `moveToThread` pattern is right for a **long-lived** worker with an identity — something that stays alive for the program's duration, handling an ongoing stream of work (a serial port reader, say). It's the wrong tool for **many short-lived, independent units of work** — e.g., parsing a batch of 500 buffered readings in parallel, where you don't want to manually spin up and tear down 500 `QThread`s (real, expensive OS resources) for work that might take microseconds each.

`QThreadPool` solves this: it maintains a **reusable pool of worker threads** (sized by default to `QThread::idealThreadCount()` — your CPU's core count) and hands them `QRunnable` tasks from an internal queue as threads become free. You submit work; the pool decides which thread executes it and when, reusing threads rather than creating/destroying them per task — the standard fix for "thread creation overhead dominates actual work" that you'd recognize from systems programming generally.

**`QRunnable`, resolved:** an abstract interface with one method to override, `run()`. You submit an instance to a pool via `QThreadPool::start()`, and by default the pool takes ownership and deletes it after `run()` completes (`setAutoDelete(true)`, the default) — an important resolved detail, since manually deleting a `QRunnable` you've submitted with default auto-delete active is a double-free waiting to happen.

**Resolved example 1 — parsing a batch of readings in parallel, using the mutex discipline from Day 17 to collect results safely**

```cpp
#include <QCoreApplication>
#include <QThreadPool>
#include <QRunnable>
#include <QMutex>
#include <QMutexLocker>
#include <QDebug>
#include <QElapsedTimer>
#include "seriallineparser.h"   // from Day 14
#include "devicereading.h"      // from Day 12

class ParseTask : public QRunnable
{
public:
    ParseTask(const QString &rawLine, QList<DeviceReading*> *results, QMutex *resultsMutex)
        : m_rawLine(rawLine), m_results(results), m_resultsMutex(resultsMutex)
    {
        // setAutoDelete(true) is the default -- the pool deletes this ParseTask
        // instance itself once run() returns. We do NOT delete it ourselves.
    }

    void run() override
    {
        static thread_local SerialLineParser parser;   // one parser instance PER THREAD, not shared --
                                                          // avoids needing to protect the parser itself,
                                                          // since QRegularExpression::match() is reentrant
                                                          // but we still want zero shared mutable state here.

        std::optional<ParsedFields> parsed = parser.parse(m_rawLine);
        if (!parsed) {
            qWarning() << "Task on thread" << QThread::currentThread() << "rejected:" << m_rawLine;
            return;
        }

        auto *reading = new DeviceReading();   // no parent -- QRunnable tasks run outside any
                                                 // particular QObject tree; caller owns the result
        reading->setDeviceId(parsed->deviceId);
        reading->setTemperature(parsed->temperature);
        reading->setTimestamp(QDateTime::currentDateTimeUtc());

        {
            QMutexLocker locker(m_resultsMutex);   // per Day 17: this IS shared mutable state -- must protect
            m_results->append(reading);
        }
    }

private:
    QString m_rawLine;
    QList<DeviceReading*> *m_results;
    QMutex *m_resultsMutex;
};

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QStringList batch;
    for (int i = 0; i < 20; ++i) {
        batch << QString("SENSOR:%1:TEMP:%2").arg(i % 10, 2, 10, QChar('0')).arg(18.0 + i * 0.3);
    }
    batch << "GARBAGE LINE";   // one deliberately malformed entry in the batch

    QList<DeviceReading*> results;
    QMutex resultsMutex;

    QThreadPool *pool = QThreadPool::globalInstance();
    qDebug() << "Pool max thread count:" << pool->maxThreadCount();

    QElapsedTimer timer;
    timer.start();

    for (const QString &line : batch) {
        auto *task = new ParseTask(line, &results, &resultsMutex);
        pool->start(task);   // hands off to the pool -- may run immediately or queue if all threads busy
    }

    pool->waitForDone();   // block until every submitted task has completed
    qDebug() << "All tasks completed in" << timer.elapsed() << "ms";
    qDebug() << "Successfully parsed:" << results.size() << "out of" << batch.size();

    for (DeviceReading *r : results) {
        delete r;   // we own these -- caller's responsibility since ParseTask didn't parent them anywhere
    }

    return 0;
}
```

**Resolved output (illustrative — exact timing/thread assignment varies by machine):**

```
Pool max thread count: 8
Task on thread QThread(0x...) rejected: "GARBAGE LINE"
All tasks completed in 4 ms
Successfully parsed: 20 out of 21
```

Resolved detail worth confirming: `pool->maxThreadCount()` reflects your actual core count (`QThread::idealThreadCount()` by default) — the pool won't run more concurrent tasks than that, queuing the rest until a thread frees up, which is exactly the resource-bounded behavior you want instead of spawning 21 raw OS threads for 21 tiny tasks.

**Resolved example 2 — QThreadPool::setMaxThreadCount(), and why over-subscribing threads doesn't help**

```cpp
#include <QCoreApplication>
#include <QThreadPool>
#include <QRunnable>
#include <QDebug>
#include <QElapsedTimer>
#include <QThread>

class BusyTask : public QRunnable
{
public:
    void run() override
    {
        // Simulate CPU-bound work (NOT I/O-bound -- this distinction matters,
        // see resolved note below)
        volatile long sum = 0;
        for (long i = 0; i < 50000000; ++i) sum += i;
    }
};

void runBatch(int threadCount, int taskCount)
{
    QThreadPool pool;   // a LOCAL pool instance, separate from the global one -- lets us
                         // control maxThreadCount independently for this experiment
    pool.setMaxThreadCount(threadCount);

    QElapsedTimer timer;
    timer.start();

    for (int i = 0; i < taskCount; ++i) {
        pool.start(new BusyTask());
    }
    pool.waitForDone();

    qDebug() << "maxThreadCount =" << threadCount << "-- " << taskCount << "CPU-bound tasks took" << timer.elapsed() << "ms";
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    int cores = QThread::idealThreadCount();
    qDebug() << "This machine reports" << cores << "logical cores";

    runBatch(cores, 16);        // matched to core count
    runBatch(cores * 4, 16);    // deliberately over-subscribed

    return 0;
}
```

**Resolved, representative output:**

```
This machine reports 8 logical cores
maxThreadCount = 8 --  16 CPU-bound tasks took 612 ms
maxThreadCount = 32 --  16 CPU-bound tasks took 649 ms
```

Resolved lesson: over-subscribing thread count **doesn't** meaningfully help (and can slightly hurt) for genuinely CPU-bound work — you only have 8 physical cores' worth of actual computation throughput regardless of how many threads you create; extra threads beyond core count just add context-switching overhead without adding real parallelism. This is the resolved, concrete reason `QThreadPool` defaults `maxThreadCount()` to `idealThreadCount()` rather than something arbitrary — for CPU-bound tasks (like parsing), the default is usually already correct, and manually cranking it up is a common, resolved misconception rather than a real optimization.

**Resolved example 3 — the correct use of `run()`'s return communication: QFutureWatcher-free simple pattern using a signal (bridging back to Day 3)**

`QRunnable` itself has no built-in way to signal completion or return a value back — it's deliberately minimal. The resolved, idiomatic fix for "I need to know when a task is done and get a result back" without adding the full `QtConcurrent`/`QFuture` module (out of scope for Qt Core alone) is to make your task also a `QObject` and emit a signal:

```cpp
// signalingtask.h
#pragma once
#include <QObject>
#include <QRunnable>
#include <QDebug>

class SignalingTask : public QObject, public QRunnable
{
    Q_OBJECT
public:
    explicit SignalingTask(int input) : m_input(input) {}

    void run() override
    {
        int result = m_input * m_input;   // stand-in for real per-task work
        emit finished(m_input, result);   // safe: this signal, if connected with a queued
                                            // connection, gets delivered on the RECEIVER's
                                            // thread (Day 15) -- not this worker thread --
                                            // so the receiving slot runs safely back on main.
    }

signals:
    void finished(int input, int result);

private:
    int m_input;
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include <QThreadPool>
#include <QDebug>
#include <QThread>
#include "signalingtask.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    int remaining = 5;
    for (int i = 1; i <= 5; ++i) {
        auto *task = new SignalingTask(i);
        task->setAutoDelete(false);   // WE will delete it (after connecting a signal,
                                       // we need it to survive until the connection
                                       // fires -- auto-delete could race with that)
        QObject::connect(task, &SignalingTask::finished, [&remaining, task](int in, int result) {
            qDebug() << "task for input" << in << "-> result" << result
                      << "-- delivered on thread:" << QThread::currentThread();
            delete task;   // now safe to clean up, the signal has definitely fired
            if (--remaining == 0) qApp->quit();
        });
        QThreadPool::globalInstance()->start(task);
    }

    return app.exec();
}
```

**Resolved output (order of completion may vary; thread is always main):**

```
task for input 3 -> result 9 -- delivered on thread: QThread(0x_main)
task for input 1 -> result 1 -- delivered on thread: QThread(0x_main)
task for input 5 -> result 25 -- delivered on thread: QThread(0x_main)
task for input 2 -> result 4 -- delivered on thread: QThread(0x_main)
task for input 4 -> result 16 -- delivered on thread: QThread(0x_main)
```

Resolved detail: notice completion order is **not** input order (1,2,3,4,5) — the pool schedules tasks across its worker threads independently, and whichever finishes first emits first. This is expected and correct for task-based concurrency: if your use case needs ordered results, you must explicitly track and reorder them yourself (e.g. tag each result with an index and sort afterward) — the pool makes no ordering guarantee.

**Key takeaways:**

- `QThreadPool` + `QRunnable` is for many short-lived, independent tasks; Day 16's `QThread`+`moveToThread` is for one long-lived worker with an ongoing identity — pick based on task shape, not just "I need concurrency."
- Default `setAutoDelete(true)` means the pool deletes your `QRunnable` after `run()` — never manually delete a task you've submitted with auto-delete still on, and set it `false` explicitly only when you have a clear alternative ownership plan (as in Example 3).
- Over-subscribing thread count beyond `idealThreadCount()` doesn't help genuinely CPU-bound work — more threads than physical cores just adds scheduling overhead without adding real throughput; the pool's default is usually already correct.
- `QRunnable` has no built-in completion signal — combine it with `QObject` (multiple inheritance, as shown) when you need one, and remember the resulting signal delivery still obeys Day 15's cross-thread queued-connection rules, landing safely back on whichever thread the receiver lives on.
- Task completion order from a pool is inherently unordered — never assume submission order matches completion order; track and reorder explicitly if your use case requires it.

Day 19 covers `QProcess` — spawning and communicating with external processes (stdin/stdout/stderr as `QIODevice` streams, per Day 8's unifying abstraction), directly relevant to any real deployment scenario where `mqtt_monitor` needs to invoke external tools (a diagnostic script, `mosquitto_pub` for testing, or a system utility) without blocking.