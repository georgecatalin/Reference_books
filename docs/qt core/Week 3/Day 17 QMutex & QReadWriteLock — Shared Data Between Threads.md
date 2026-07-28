[[Advanced]]

**Theory: when you actually need a mutex, versus when signals/slots already protect you**

Day 16's pattern — communication purely via signals/slots across thread boundaries — is deliberately the _preferred_ Qt Core approach, because a queued connection's event posting is itself a safe hand-off: the data being passed as signal arguments is copied into the event object, so there's no concurrent access to shared memory at all. **If two threads never touch the same variable directly, you don't need a mutex.**

You need `QMutex` specifically when:

- Multiple threads must read or write the **same in-memory data structure directly** (not via signal arguments) — e.g., a shared cache, a counter incremented from both a worker thread and the main thread, or a connection pool.
- You're using `QThreadPool`/`QRunnable` (Day 19), where tasks genuinely share state by design rather than communicating via one-off signals.

**`QMutex` vs `QReadWriteLock`, resolved:**

- `QMutex` — exclusive lock; only one thread in the protected section at a time, for reading _or_ writing.
- `QReadWriteLock` — allows **many simultaneous readers**, but writers get exclusive access. Correct choice when reads vastly outnumber writes (e.g., a shared config/cache read constantly, updated rarely) — using a plain `QMutex` there would serialize reads unnecessarily.

**Resolved example 1 — the race condition, demonstrated with a deliberately unprotected shared counter**

```cpp
#include <QCoreApplication>
#include <QThread>
#include <QDebug>
#include <atomic>

// Deliberately BROKEN: a plain int incremented from two threads with no protection.
int g_unprotectedCounter = 0;

void incrementManyTimes()
{
    for (int i = 0; i < 100000; ++i) {
        ++g_unprotectedCounter;   // NOT atomic: read-modify-write, can interleave between threads
    }
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QThread t1, t2;
    QObject::connect(&t1, &QThread::started, &incrementManyTimes);
    QObject::connect(&t2, &QThread::started, &incrementManyTimes);

    t1.start();
    t2.start();
    t1.wait();
    t2.wait();

    qDebug() << "Expected: 200000, Actual:" << g_unprotectedCounter;
    // Resolved: actual is almost always LESS than 200000 -- some increments were lost
    // because ++g_unprotectedCounter is not a single atomic CPU instruction; two threads
    // can both read the same old value before either writes back the incremented result.

    return 0;
}
```

**Resolved, representative output (exact number varies run to run — that's the point):**

```
Expected: 200000, Actual: 187342
```

This is the resolved core problem: `++g_unprotectedCounter` is really three steps (read, add one, write back), and if thread A reads `500` and thread B also reads `500` before either writes back `501`, one increment is silently lost. Run this program multiple times and you'll get a _different_ wrong number each time — a classic, resolved signature of a genuine data race, not a deterministic bug.

**Resolved example 2 — the fix, with QMutex and QMutexLocker (RAII-style, per your existing C++ RAII background)**

```cpp
#include <QCoreApplication>
#include <QThread>
#include <QMutex>
#include <QMutexLocker>
#include <QDebug>

int g_protectedCounter = 0;
QMutex g_counterMutex;

void incrementManyTimesSafely()
{
    for (int i = 0; i < 100000; ++i) {
        QMutexLocker locker(&g_counterMutex);   // RAII: locks on construction, unlocks on scope exit --
                                                  // exactly the same discipline as a C++ lock_guard,
                                                  // so it's exception-safe and can't be forgotten.
        ++g_protectedCounter;
        // locker destructs here at end of each loop iteration -- unlocks immediately
    }
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QThread t1, t2;
    QObject::connect(&t1, &QThread::started, &incrementManyTimesSafely);
    QObject::connect(&t2, &QThread::started, &incrementManyTimesSafely);

    t1.start();
    t2.start();
    t1.wait();
    t2.wait();

    qDebug() << "Expected: 200000, Actual:" << g_protectedCounter;

    return 0;
}
```

**Resolved output (deterministic, every run):**

```
Expected: 200000, Actual: 200000
```

Resolved point: with `QMutexLocker` guarding the critical section, exactly one thread executes `++g_protectedCounter` at a time — the other blocks until the lock is released. This is correct every time, at the cost of the two threads now genuinely serializing on that one line (locking has real overhead — worth noting for a hot path, but correctness always comes first).

**Resolved example 3 — QReadWriteLock: many readers, exclusive writer, for a shared config cache**

```cpp
#include <QCoreApplication>
#include <QThread>
#include <QReadWriteLock>
#include <QMap>
#include <QString>
#include <QDebug>
#include <QRandomGenerator>

class SharedConfigCache
{
public:
    QVariant get(const QString &key) const
    {
        QReadLocker locker(&m_lock);   // RAII read-lock: multiple threads can hold this simultaneously
        return m_data.value(key);
    }

    void set(const QString &key, const QVariant &value)
    {
        QWriteLocker locker(&m_lock);   // RAII write-lock: exclusive -- blocks ALL readers and writers
        m_data[key] = value;
    }

private:
    mutable QReadWriteLock m_lock;   // 'mutable' because get() is const but still needs to lock
    QMap<QString, QVariant> m_data;
};

SharedConfigCache g_config;

void readerWork(int readerId)
{
    for (int i = 0; i < 5; ++i) {
        QVariant v = g_config.get("threshold");
        qDebug() << "reader" << readerId << "read threshold =" << v;
        QThread::msleep(50);
    }
}

void writerWork()
{
    for (int i = 0; i < 3; ++i) {
        QThread::msleep(120);
        double newThreshold = 25.0 + i;
        g_config.set("threshold", newThreshold);
        qDebug() << "*** writer updated threshold to" << newThreshold << "***";
    }
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    g_config.set("threshold", 20.0);   // initial value, before any threads start

    QThread reader1, reader2, writer;
    QObject::connect(&reader1, &QThread::started, []{ readerWork(1); });
    QObject::connect(&reader2, &QThread::started, []{ readerWork(2); });
    QObject::connect(&writer, &QThread::started, &writerWork);

    reader1.start();
    reader2.start();
    writer.start();

    reader1.wait();
    reader2.wait();
    writer.wait();

    return 0;
}
```

**Resolved, representative output (interleaving varies, but the pattern is stable):**

```
reader 1 read threshold = 20
reader 2 read threshold = 20
reader 1 read threshold = 20
reader 2 read threshold = 20
*** writer updated threshold to 25 ***
reader 1 read threshold = 25
reader 2 read threshold = 25
...
*** writer updated threshold to 26 ***
...
*** writer updated threshold to 27 ***
...
```

The resolved property worth confirming: both readers can (and do) read simultaneously without blocking each other — only the writer's `set()` call briefly excludes everyone. If this had used a plain `QMutex` instead, the two readers would have serialized against each other too, needlessly, since neither actually conflicts with a concurrent read.

**Key takeaways:**

- Don't reach for a mutex by default — Day 16's signals/slots pattern already avoids shared-memory races entirely for most inter-thread communication, since arguments are copied into the posted event.
- Reach for `QMutex` specifically when multiple threads must touch the _same_ in-memory structure directly (shared cache, counter, connection pool) — this is the actual definition of "need a mutex," not just "using threads."
- Always use `QMutexLocker`/`QReadLocker`/`QWriteLocker` (RAII, matching your C++ course's `lock_guard` discipline) rather than manual `lock()`/`unlock()` calls — manual unlocking is forgotten or skipped on exception paths in real code, silently reintroducing races.
- `QReadWriteLock` beats a plain `QMutex` specifically when reads vastly outnumber writes and reads don't need to block each other — using a plain mutex in that situation serializes work that didn't need to be serialized, for no correctness benefit.
- An unprotected shared read-modify-write (like `++counter`) produces a genuine, nondeterministic data race — different wrong answers on different runs — which is the diagnostic signature that distinguishes "needs a mutex" bugs from ordinary deterministic logic bugs.

Day 18 covers `QThreadPool` and `QRunnable` — task-based concurrency for when you have many short-lived units of work (e.g., parsing a batch of incoming readings in parallel) rather than one or two long-lived worker threads, and this is exactly where the mutex discipline from today becomes routine rather than exceptional.