[[Advanced]]

## Day 26: Testing Worker-Thread Code — `QSignalSpy::wait()` and Test Harnesses

### Concept: Testing Across a Real Thread Boundary Requires Waiting, Not Assuming

Day 25's tests were all single-threaded — call a method, check the result immediately, done. Worker-thread code is different: when you invoke something on a worker running on an actual separate `QThread`, the result comes back **asynchronously**, via a queued signal. A test that checks a result immediately after triggering it will usually fail, not because the code is broken, but because the response genuinely hasn't arrived yet — the test's own execution outraces the worker thread. This is a real category of test bug, not a Qt quirk to work around superficially: you need to actually **wait** for the signal, with a bounded timeout, exactly the way `QSignalSpy::wait()` is built for.

### Annotated Code: Testing `PersistenceWorker` End-to-End on a Real Thread

This test genuinely spins up a `QThread`, moves the worker onto it, and verifies the full async round-trip — closer to an integration test than a pure unit test, but still fast, hardware-free, and CI-friendly (in-memory SQLite from Day 25's exercise).

```cpp
#include <QtTest>
#include <QSignalSpy>
#include <QThread>
#include "../persistenceworker.h"

class TestPersistenceWorkerThreaded : public QObject {
    Q_OBJECT
private slots:
    void initTestCase(); // once for the whole class — register the custom type
    void init();          // fresh thread/worker per test
    void cleanup();

    void saveReading_thenQuery_returnsRecord();
    void queryNonexistentDevice_returnsEmptyList();

private:
    QThread *thread = nullptr;
    PersistenceWorker *worker = nullptr;
};

void TestPersistenceWorkerThreaded::initTestCase() {
    // Registration (Day 20) must happen before any queued call carries
    // this type — doing it once here, before any test runs, is correct
    qRegisterMetaType<ReadingRecord>("ReadingRecord");
    qRegisterMetaType<QList<ReadingRecord>>("QList<ReadingRecord>");
}

void TestPersistenceWorkerThreaded::init() {
    thread = new QThread();
    worker = new PersistenceWorker(":memory:"); // in-memory, per-test isolation —
                                                  // each test gets a genuinely fresh,
                                                  // empty database, no cross-test bleed
    worker->moveToThread(thread);
    QObject::connect(thread, &QThread::started, worker, &PersistenceWorker::start);
    thread->start();

    // Give the worker a moment to actually open the DB and run its schema —
    // start() runs asynchronously once the thread's event loop begins, so
    // we can't assume it's ready the instant thread->start() returns
    QTest::qWait(50); // small fixed wait is acceptable here since start()
                        // has no signal to spy on directly; for anything
                        // with an observable signal, prefer QSignalSpy::wait()
                        // over a fixed sleep — see the test below for why
}

void TestPersistenceWorkerThreaded::cleanup() {
    QMetaObject::invokeMethod(worker, "stop", Qt::QueuedConnection);
    thread->quit();
    thread->wait(2000); // bounded — a hung worker shouldn't hang the whole test suite
    delete worker;       // safe now — thread has fully stopped
    delete thread;
}

void TestPersistenceWorkerThreaded::saveReading_thenQuery_returnsRecord() {
    QSignalSpy loadedSpy(worker, &PersistenceWorker::readingsLoaded);

    ReadingRecord record{"device-01", 42.5, QDateTime::currentDateTime(), true};
    QMetaObject::invokeMethod(worker, "saveReading", Qt::QueuedConnection,
                               Q_ARG(ReadingRecord, record));

    QMetaObject::invokeMethod(worker, "queryRecentReadings", Qt::QueuedConnection,
                               Q_ARG(QString, "device-01"), Q_ARG(int, 10));

    // THE key call: wait up to 1000ms for the signal to actually arrive,
    // rather than assuming it already has. Returns true immediately once
    // the signal fires (doesn't wait the full timeout if it arrives sooner),
    // false if the timeout elapses with no emission — a real failure signal,
    // not a flaky guess.
    bool arrived = loadedSpy.wait(1000);
    QVERIFY(arrived); // QVERIFY for boolean conditions, QCOMPARE for value equality

    QCOMPARE(loadedSpy.count(), 1);
    QList<QVariant> args = loadedSpy.takeFirst();
    QCOMPARE(args.at(0).toString(), QString("device-01"));

    auto records = args.at(1).value<QList<ReadingRecord>>();
    QCOMPARE(records.size(), 1);
    QCOMPARE(records.first().temperature, 42.5);
}

void TestPersistenceWorkerThreaded::queryNonexistentDevice_returnsEmptyList() {
    QSignalSpy loadedSpy(worker, &PersistenceWorker::readingsLoaded);

    QMetaObject::invokeMethod(worker, "queryRecentReadings", Qt::QueuedConnection,
                               Q_ARG(QString, "device-nonexistent"), Q_ARG(int, 10));

    QVERIFY(loadedSpy.wait(1000));
    auto records = loadedSpy.takeFirst().at(1).value<QList<ReadingRecord>>();
    QCOMPARE(records.size(), 0); // empty, not an error — a query for a device
                                   // with no readings is a valid, normal case,
                                   // not a failure condition
}

QTEST_MAIN(TestPersistenceWorkerThreaded)
#include "test_persistenceworker_threaded.moc"
```

### `QSignalSpy::wait()` vs. `QTest::qWait()` — Know the Difference

- **`spy.wait(timeout)`** — waits specifically for the _next emission_ of the signal the spy is attached to, returns as soon as it fires (or `false` at timeout). This is the correct tool whenever you're waiting for a specific async event.
- **`QTest::qWait(ms)`** — a fixed, unconditional pause of exactly `ms` milliseconds, regardless of what happens during that time. Use only when there's genuinely no signal to wait on (like "give the thread a moment to start up" in `init()` above) — and even then, prefer restructuring so there _is_ something to wait on, if practical, since fixed waits are either too short (flaky) or wastefully too long (slow test suite) depending on timing variance.

**The practical rule**: reach for `QSignalSpy::wait()` first, always; fall back to `QTest::qWait()` only when no observable signal exists for the specific moment you need to synchronize on.

### Testing `MqttWorker` Without a Real Broker — Dependency Injection for Testability

Testing `MqttWorker` genuinely end-to-end would require a real (or embedded-test) mosquitto broker — heavier than you usually want for a unit test suite. The more valuable, lighter-weight test target is the **logic around** the MQTT client — specifically the `intentionalDisconnect` reconnect behavior from Day 19, which is exactly the kind of subtle state-tracking bug that benefits most from an automated regression test:

```cpp
// This targets the reconnect DECISION logic specifically, not real MQTT I/O.
// A worthwhile refactor: expose the decision as a small testable method,
// separate from the actual QMqttClient interaction:
//
//   bool MqttWorker::shouldAttemptReconnect() const {
//       return !intentionalDisconnect;
//   }
//
// This mirrors Day 25's SerialWorker refactor lesson exactly: pull the
// actual DECISION logic out from underneath the hardware/network-dependent
// parts, so it's testable without needing the real dependency at all.

void TestMqttWorkerLogic::intentionalStop_doesNotReconnect() {
    MqttWorker worker("localhost", 1883, "test-client");
    QVERIFY(worker.shouldAttemptReconnect()); // default state: would reconnect if dropped

    worker.simulateStopForTest(); // a test-only method setting intentionalDisconnect = true,
                                    // WITHOUT touching a real QMqttClient at all
    QVERIFY(!worker.shouldAttemptReconnect());
}
```

**A real judgment call worth naming**: adding a `simulateStopForTest()` method purely for testability is a legitimate, common pattern — but it's also a smell if overused, since it means production code now has test-only surface area. The better long-term fix, if this class grows more such cases, is separating the **connection-state-tracking logic** into its own small, dependency-free class that `MqttWorker` uses internally — fully testable on its own, with `MqttWorker` itself becoming a thinner integration layer around it plus the real `QMqttClient`. Today's approach is a reasonable pragmatic middle ground for one flag; don't over-engineer a full extraction for a single boolean, but recognize the pattern for when a worker accumulates several such stateful decisions.

### Why This Matters

- **Fixed sleeps (`QTest::qWait`) as async test synchronization are a recognized anti-pattern** — they make test suites either flaky (timing too short on a loaded CI machine) or slow (padding every wait generously to avoid flakiness). `QSignalSpy::wait()` solves this correctly: it returns the instant the signal fires, so tests stay both fast and reliable.
- **In-memory SQLite per test (`init()`/`cleanup()` creating and tearing down a fresh thread+worker each time)** gives you genuine test isolation even for stateful, threaded, I/O-touching code — this is a meaningfully more rigorous test than Day 25's pure-logic tests, and it's still fast and hardware-free.
- **Extracting decision logic (`shouldAttemptReconnect()`) from I/O-entangled code** is the same testability-driven refactor lesson as Day 25's `extractCompleteLines()`, now applied to state-machine-like logic rather than parsing logic — worth recognizing as a repeating pattern, not a one-off trick.
- **`cleanup()` properly stopping and waiting on the thread with a bounded timeout** prevents test-suite hangs from a single misbehaving test — the same "always bound your waits" discipline from Day 16's shutdown logic, now applied inside the test harness itself.

### Exercise

1. Write a similar threaded test for `SerialWorker`, but instead of a real serial port, point it at one end of a `socat` loopback pair (same setup as Day 17's manual exercise) started/stopped from the test's `init()`/`cleanup()` via `QProcess` — this turns your manual Day 17 verification into a fully automated, repeatable test.
2. Extend `TestPersistenceWorkerThreaded` with a test for the batching/transaction behavior from Day 20's exercise: save 50 readings in quick succession, and verify (via a direct query after a `QSignalSpy::wait()` on the flush completing, or a small delay matching your flush timer interval) that they all land correctly despite being batched rather than written individually.
3. Deliberately introduce a bug — comment out the `reconnectTimer->stop()` call in `MqttWorker::onConnected()` (Day 19) — and confirm a test targeting `shouldAttemptReconnect()`-style logic (or an equivalent state check) actually catches the regression, rather than only relying on manual re-testing to notice it.

### Key Takeaways

- Testing across a real thread boundary requires waiting for the async result — `QSignalSpy::wait()` is the correct, non-flaky way to do this; fixed sleeps (`QTest::qWait`) are a fallback for the rare case with no observable signal, not a default tool.
- In-memory SQLite plus a fresh `QThread`/worker pair per test gives genuine isolation for stateful, async, I/O-touching code without sacrificing speed.
- Extract decision/state-tracking logic away from hardware/network-dependent code when it accumulates complexity — the same lesson as Day 25's parsing extraction, now applied to connection-state logic.
- Bound every wait in test harnesses (`thread->wait(timeout)`) — an unbounded wait in a test's cleanup can hang your entire CI pipeline on a single bad test run.

---

Say "next" for Day 27 (deployment — `linuxdeployqt`/static linking for a self-contained Linux binary, and cross-compiling the same codebase for Raspberry Pi/BeagleBone ARM targets, since that's the actual deployment reality for `mqtt_monitor`).