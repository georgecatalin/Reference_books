[[Advanced]]

This week's synthesis: a complete pipeline that generates a batch of raw serial-style lines, distributes parsing across a `QThreadPool` (Day 18), safely aggregates results with a mutex (Day 17), applies the Day 20 config-watching pattern to make the temperature threshold live-reloadable mid-run, and reports a final summary — combining every threading concept from this week into one cohesive component.

**Design, resolved up front:**

- `ReadingBatchProcessor` — a `QObject` that owns the whole pipeline: generates/accepts raw lines, submits `QRunnable` parse tasks to a pool, collects results thread-safely, and emits a `batchCompleted` signal with aggregate stats.
- Reuses `SerialLineParser` (Day 14), `DeviceReading` (Day 12), and the `ParseTask` shape from Day 18 — now folded into a proper component rather than living loose in `main()`.
- A live-reloadable threshold (Day 20's pattern, simplified — no actual file here, just proving the same "reconfigure safely while workers are running" discipline applies) — protected by a `QReadWriteLock` (Day 17) since it's read constantly by many worker threads and written rarely from the main thread.

**Resolved code:**

```cpp
// readingbatchprocessor.h
#pragma once
#include <QObject>
#include <QThreadPool>
#include <QRunnable>
#include <QMutex>
#include <QMutexLocker>
#include <QReadWriteLock>
#include <QAtomicInt>
#include <QList>
#include <QDebug>
#include "seriallineparser.h"
#include "devicereading.h"

class ReadingBatchProcessor : public QObject
{
    Q_OBJECT
public:
    explicit ReadingBatchProcessor(QObject *parent = nullptr) : QObject(parent) {}

    ~ReadingBatchProcessor() override
    {
        qDeleteAll(m_results);   // we own these -- clean up whatever's left
    }

    // Thread-safe threshold accessors -- called from BOTH worker threads (read,
    // frequently) and the main thread (write, rarely). QReadWriteLock (Day 17)
    // is the correct choice here, not a plain QMutex, given that read/write ratio.
    double highTempThreshold() const
    {
        QReadLocker locker(&m_thresholdLock);
        return m_highTempThreshold;
    }

    void setHighTempThreshold(double t)
    {
        QWriteLocker locker(&m_thresholdLock);
        qDebug() << "[main] threshold updated to" << t;
        m_highTempThreshold = t;
    }

    void processBatch(const QStringList &rawLines)
    {
        m_expectedCount = rawLines.size();
        m_completedCount.storeRelaxed(0);   // QAtomicInt: lock-free counter, safe across threads
        m_results.clear();
        m_rejectedCount.storeRelaxed(0);

        QThreadPool *pool = QThreadPool::globalInstance();
        qDebug() << "Submitting" << rawLines.size() << "lines to pool with"
                  << pool->maxThreadCount() << "max threads";

        for (const QString &line : rawLines) {
            auto *task = new ParseTask(line, this);
            pool->start(task);
        }
    }

    // Called by each ParseTask (Day 18 pattern) when it finishes -- this runs
    // ON A WORKER THREAD, so everything it touches must be protected.
    void reportResult(DeviceReading *reading)   // nullptr means rejected/unparseable
    {
        if (reading) {
            QMutexLocker locker(&m_resultsMutex);   // Day 17: protecting shared QList mutation
            m_results.append(reading);
        } else {
            m_rejectedCount.fetchAndAddRelaxed(1);
        }

        int done = m_completedCount.fetchAndAddRelaxed(1) + 1;
        if (done == m_expectedCount) {
            // Last task to finish -- report the summary. Note: this callback
            // itself is still running on a WORKER thread here, so we emit a
            // signal to hand the summary back across to whichever thread the
            // receiver actually lives on (Day 15's cross-thread delivery rule).
            emit batchCompleted(m_results.size(), m_rejectedCount.loadRelaxed());
        }
    }

    double thresholdSnapshot() const { return highTempThreshold(); }

signals:
    void batchCompleted(int successCount, int rejectedCount);

private:
    QMutex m_resultsMutex;
    QList<DeviceReading*> m_results;
    QAtomicInt m_completedCount{0};
    QAtomicInt m_rejectedCount{0};
    int m_expectedCount = 0;

    mutable QReadWriteLock m_thresholdLock;
    double m_highTempThreshold = 30.0;

    friend class ParseTask;
};

class ParseTask : public QRunnable
{
public:
    ParseTask(const QString &rawLine, ReadingBatchProcessor *owner)
        : m_rawLine(rawLine), m_owner(owner) {}

    void run() override
    {
        static thread_local SerialLineParser parser;   // per Day 18: one per thread, no shared state to protect

        std::optional<ParsedFields> parsed = parser.parse(m_rawLine);
        if (!parsed) {
            m_owner->reportResult(nullptr);
            return;
        }

        auto *reading = new DeviceReading();
        reading->setDeviceId(parsed->deviceId);
        reading->setTemperature(parsed->temperature);
        reading->setTimestamp(QDateTime::currentDateTimeUtc());

        // Reading the shared threshold from a WORKER thread -- protected by
        // QReadWriteLock inside highTempThreshold(), safe even if main thread
        // writes it concurrently mid-batch.
        if (reading->temperature() > m_owner->highTempThreshold()) {
            qDebug() << "[worker thread]" << reading->deviceId() << "EXCEEDS threshold:" << reading->temperature();
        }

        m_owner->reportResult(reading);
    }

private:
    QString m_rawLine;
    ReadingBatchProcessor *m_owner;
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include <QDebug>
#include <QThread>
#include <QTimer>
#include "readingbatchprocessor.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    ReadingBatchProcessor processor;

    QObject::connect(&processor, &ReadingBatchProcessor::batchCompleted,
                      [&](int success, int rejected) {
        qDebug() << "\n--- Batch complete, delivered on thread:" << QThread::currentThread() << "---";
        qDebug() << "Success:" << success << " Rejected:" << rejected;
        qApp->quit();
    });

    // Build a batch, including a few deliberately over-threshold and malformed lines
    QStringList batch;
    for (int i = 0; i < 30; ++i) {
        double temp = 18.0 + (i % 15) * 1.2;   // some will exceed the 30.0 default threshold
        batch << QString("SENSOR:%1:TEMP:%2").arg(i % 10, 2, 10, QChar('0')).arg(temp, 0, 'f', 1);
    }
    batch << "MALFORMED" << "SENSOR:ab:TEMP:x";

    // Simulate an operator lowering the threshold PARTWAY through processing --
    // this races against worker threads reading it, and is exactly why
    // QReadWriteLock protection matters here, not just as an academic exercise.
    QTimer::singleShot(0, [&processor]() {
        processor.setHighTempThreshold(25.0);
    });

    processor.processBatch(batch);

    return app.exec();
}
```

**CMakeLists.txt:**

```cmake
cmake_minimum_required(VERSION 3.16)
project(day21_batch_processor)
set(CMAKE_CXX_STANDARD 17)
set(CMAKE_AUTOMOC ON)
find_package(Qt6 REQUIRED COMPONENTS Core)
add_executable(day21_batch_processor main.cpp readingbatchprocessor.h seriallineparser.h devicereading.h)
target_link_libraries(day21_batch_processor Qt6::Core)
```

**Resolved output (illustrative — thread assignment and exact interleaving vary by run, which is expected and fine):**

```
Submitting 32 lines to pool with 8 max threads
[main] threshold updated to 25
[worker thread] "sensor-02" EXCEEDS threshold: 25.2
[worker thread] "sensor-03" EXCEEDS threshold: 26.4
[worker thread] "sensor-04" EXCEEDS threshold: 27.6
[worker thread] "sensor-05" EXCEEDS threshold: 28.8
[worker thread] "sensor-06" EXCEEDS threshold: 30
...

--- Batch complete, delivered on thread: QThread(0x_main) ---
Success: 30  Rejected: 2
```

**Resolved design decisions, explained explicitly:**

- **`QAtomicInt` for the completion counter, not a mutex:** since it's a single integer being incremented, `QAtomicInt::fetchAndAddRelaxed()` is a lock-free atomic operation — cheaper than a full `QMutexLocker` for this specific case, where the only operation is "add one and get the previous value back atomically." This is a deliberate escalation from Day 17's mutex-only framing: a mutex is the general tool, but a single counter has a cheaper, correct, lock-free alternative worth knowing.
- **`QReadWriteLock` for the threshold, matching Day 17's exact guidance:** many worker threads read it (every single task), the main thread writes it rarely (once, mid-batch, in this demo) — precisely the read-heavy/write-rare shape `QReadWriteLock` is built for.
- **The "last task reports completion" pattern:** rather than the main thread polling or blocking on `waitForDone()` (Day 18), each `ParseTask` checks after reporting its own result whether it was the _last_ one (`done == m_expectedCount`), and only that one task emits `batchCompleted`. This avoids any blocking wait entirely — the main thread's event loop stays fully responsive throughout, discovering completion via a signal rather than a blocking call.
- **The signal crossing back to the main thread correctly, even though emitted from a worker:** `emit batchCompleted(...)` happens on whichever worker thread happened to finish last — but since the connected lambda was set up from the main thread with no explicit receiver context tying it elsewhere, Qt's auto-connection detection (Day 3/15) delivers it via a queued connection, landing safely on the main thread, as the "delivered on thread" debug line confirms.
- **Deliberately racing the threshold write against reads, rather than avoiding it:** this is the resolved point of including `QTimer::singleShot(0, ...)` to change the threshold immediately — it's not a hidden bug being avoided, it's the exact scenario `QReadWriteLock` exists for, made deliberately visible rather than left to chance.

**Key takeaways (mini-project synthesis):**

- A single component can legitimately combine several concurrency primitives at once, each for its own specific reason: `QThreadPool`/`QRunnable` for task distribution, `QMutex` for a mutated shared list, `QAtomicInt` for a simple shared counter, `QReadWriteLock` for read-heavy shared config — using a single mutex for everything would be simpler to write but slower and conceptually muddier than matching each primitive to its actual access pattern.
- "Last task reports completion" (rather than blocking on `waitForDone()`) keeps the main thread's event loop responsive the entire time — the correct default for any component embedded in a larger service that can't afford to block on a batch operation.
- Live-reconfiguring shared state while worker threads are actively reading it is a real, common requirement (not a hypothetical) — and it's exactly why Day 17's lock disciplines exist, rather than being an academic exercise disconnected from real system design.

Week 4 starts Day 22 with `QTcpSocket`/`QTcpServer` — async client-server networking, the natural next step now that you're comfortable with the event-loop-driven, signal-based async style this course has used consistently since Day 2, applied now to real network I/O rather than timers, processes, or file changes.