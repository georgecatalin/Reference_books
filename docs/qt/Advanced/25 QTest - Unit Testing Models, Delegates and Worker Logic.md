[[Advanced]]

## Day 25: `QTest` — Unit Testing Models, Delegates, and Worker Logic

### Concept: Most of Your Qt Code Is More Testable Than It Looks

The instinct coming from raw GUI work is "you can't really unit test a GUI app." That's true of pixel-perfect visual correctness, but **almost everything you built in Phases 2–3 has zero actual dependency on a visible window** — `DeviceTableModel`'s upsert logic, `SerialWorker`'s line-buffering, `PersistenceWorker`'s SQL — none of it needs a shown widget to verify. This traces directly back to a design principle flagged back in Day 9: keeping business logic out of paint/widget code specifically so it stays testable in isolation. Today is where that discipline pays off.

### The Three Test Categories You Actually Need

1. **Pure logic, no `QObject` at all** — e.g., `DeviceReading` struct helpers, buffering algorithms extracted as free functions. Fastest to test, no Qt test infrastructure overhead.
2. **`QObject`-based logic, no GUI** — `DeviceTableModel`, `SerialWorker`'s buffering (via `QSignalSpy`, not real hardware), `PersistenceWorker`'s SQL logic. Needs a `QCoreApplication` (not `QApplication` — no GUI event loop needed) since signals/slots and the event system require it.
3. **Widget/painting-dependent tests** — genuinely needs `QApplication` and can render offscreen. Reserve for the few cases where visual/interaction behavior itself is what you're testing.

### Setup: CMake + CTest Integration

```cmake
enable_testing()
find_package(Qt6 REQUIRED COMPONENTS Test)

add_executable(test_devicetablemodel tests/test_devicetablemodel.cpp devicetablemodel.cpp devicetablemodel.h)
target_link_libraries(test_devicetablemodel PRIVATE Qt6::Core Qt6::Test)
add_test(NAME DeviceTableModelTest COMMAND test_devicetablemodel)

add_executable(test_serialworker tests/test_serialworker.cpp serialworker.cpp serialworker.h)
target_link_libraries(test_serialworker PRIVATE Qt6::Core Qt6::SerialPort Qt6::Test)
add_test(NAME SerialWorkerTest COMMAND test_serialworker)
```

Run everything with `ctest --output-on-failure` from your build directory — same workflow shape as your GoogleTest usage in the C++ course, different underlying framework.

### Annotated Code: Testing `DeviceTableModel` (Category 2 — No GUI Needed)

`tests/test_devicetablemodel.cpp`:

```cpp
#include <QtTest>
#include "../devicetablemodel.h"

// QObject-derived test class — QTest uses introspection (moc again!) to
// find and run every private slot as a separate test case
class TestDeviceTableModel : public QObject {
    Q_OBJECT

private slots:
    // QTest naming convention: init()/cleanup() run before/after EACH test;
    // initTestCase()/cleanupTestCase() run once for the whole class
    void init();      // fresh model before every test — avoid cross-test state bleed
    void cleanup();

    void insertNewDevice_increasesRowCount();
    void upsertExistingDevice_updatesInPlace_notDuplicate();
    void data_returnsCorrectDisplayText();
    void data_returnsRawTemperatureViaUserRole();
    void dataChanged_emittedOnUpdate();
    void rowsInserted_emittedOnNewDevice();

private:
    DeviceTableModel *model = nullptr;
};

void TestDeviceTableModel::init() {
    model = new DeviceTableModel();
}

void TestDeviceTableModel::cleanup() {
    delete model;
    model = nullptr;
}

void TestDeviceTableModel::insertNewDevice_increasesRowCount() {
    QCOMPARE(model->rowCount(), 0); // QCOMPARE gives readable failure output,
                                      // showing both actual and expected values
    model->upsertReading({"device-01", QDateTime::currentDateTime(), 42.0, true});
    QCOMPARE(model->rowCount(), 1);
}

void TestDeviceTableModel::upsertExistingDevice_updatesInPlace_notDuplicate() {
    model->upsertReading({"device-01", QDateTime::currentDateTime(), 42.0, true});
    model->upsertReading({"device-01", QDateTime::currentDateTime(), 55.0, true});

    QCOMPARE(model->rowCount(), 1); // still one row — this is the actual
                                      // regression this test guards against;
                                      // a naive upsert bug would duplicate rows
    QModelIndex tempIndex = model->index(0, DeviceTableModel::Temperature);
    QCOMPARE(model->data(tempIndex, Qt::UserRole).toDouble(), 55.0);
}

void TestDeviceTableModel::data_returnsCorrectDisplayText() {
    model->upsertReading({"device-01", QDateTime::currentDateTime(), 42.567, true});
    QModelIndex tempIndex = model->index(0, DeviceTableModel::Temperature);
    QCOMPARE(model->data(tempIndex, Qt::DisplayRole).toString(), QString("42.6 C"));
    // Confirms the rounding/formatting behavior specifically — this is
    // exactly the kind of detail that's easy to silently break later
    // while refactoring formatting code, and QCOMPARE catches it immediately
}

void TestDeviceTableModel::data_returnsRawTemperatureViaUserRole() {
    model->upsertReading({"device-01", QDateTime::currentDateTime(), 42.567, true});
    QModelIndex tempIndex = model->index(0, DeviceTableModel::Temperature);
    QCOMPARE(model->data(tempIndex, Qt::UserRole).toDouble(), 42.567);
    // This is the Day 15 pattern's correctness guarantee — if someone later
    // "cleans up" data() and accidentally removes the UserRole branch,
    // this test fails immediately instead of silently breaking the grid sync
}

void TestDeviceTableModel::dataChanged_emittedOnUpdate() {
    model->upsertReading({"device-01", QDateTime::currentDateTime(), 42.0, true});

    // QSignalSpy: the standard tool for asserting a signal actually fired,
    // how many times, and with what arguments — without needing a real
    // slot/lambda connected just to observe it
    QSignalSpy spy(model, &QAbstractItemModel::dataChanged);
    model->upsertReading({"device-01", QDateTime::currentDateTime(), 60.0, true});

    QCOMPARE(spy.count(), 1); // fired exactly once for this single update
}

void TestDeviceTableModel::rowsInserted_emittedOnNewDevice() {
    QSignalSpy spy(model, &QAbstractItemModel::rowsInserted);
    model->upsertReading({"device-01", QDateTime::currentDateTime(), 42.0, true});
    QCOMPARE(spy.count(), 1);

    // Inspect the actual arguments the signal carried — spy stores each
    // emission as a QList<QVariant>, in emission order
    QList<QVariant> arguments = spy.takeFirst();
    QCOMPARE(arguments.at(1).toInt(), 0); // first row inserted at index 0
    QCOMPARE(arguments.at(2).toInt(), 0); // ...and last row is also 0 (single row insert)
}

QTEST_MAIN(TestDeviceTableModel) // generates a main() that runs all the slots above;
                                    // this is what QTEST_MAIN buys you — no manual
                                    // test runner boilerplate
#include "test_devicetablemodel.moc" // required when Q_OBJECT is used in a .cpp
                                       // directly rather than a paired .h — moc needs
                                       // this include to find the generated code
```

### Annotated Code: Testing `SerialWorker`'s Buffering Logic (Without Real Hardware)

This is the test that actually matters most from Day 17 — verifying the line-buffering logic handles split writes correctly, without needing `socat` or real hardware in your CI pipeline.

```cpp
#include <QtTest>
#include <QSignalSpy>
#include "../serialworker.h"

class TestSerialWorker : public QObject {
    Q_OBJECT
private slots:
    void completeLine_emitsImmediately();
    void splitAcrossTwoChunks_emitsOnlyAfterComplete();
    void multipleLinesInOneChunk_emitsEachSeparately();
    void trailingPartialData_notEmittedUntilNewlineArrives();

private:
    // Expose the buffering logic for direct testing — see note below
    // about testability requiring a small refactor
};

// NOTE: SerialWorker as written in Day 17 buffers internally inside
// onReadyRead(), which reads directly from a real QSerialPort — to test
// the buffering LOGIC specifically (not the actual serial port interaction),
// it's worth refactoring the line-splitting into a small, standalone,
// dependency-free function:
//
//   QStringList extractCompleteLines(QByteArray &buffer);
//
// ...that both onReadyRead() and this test can call directly. This is a
// good real-world example of "testability considerations changing your
// production code's shape slightly" — not a compromise, a genuine improvement,
// since it also makes the buffering logic reusable if you add a TCP-based
// ingestion source later that needs the same line-framing behavior.

void TestSerialWorker::completeLine_emitsImmediately() {
    QByteArray buffer;
    buffer.append("device-01,42.1\n");
    QStringList lines = extractCompleteLines(buffer);

    QCOMPARE(lines.size(), 1);
    QCOMPARE(lines.first(), QString("device-01,42.1"));
    QCOMPARE(buffer, QByteArray()); // buffer fully consumed
}

void TestSerialWorker::splitAcrossTwoChunks_emitsOnlyAfterComplete() {
    QByteArray buffer;
    buffer.append("device-01,4");
    QStringList lines = extractCompleteLines(buffer);
    QCOMPARE(lines.size(), 0); // no newline yet — nothing should emit
    QCOMPARE(buffer, QByteArray("device-01,4")); // partial data preserved

    buffer.append("2.1\n"); // the rest arrives in a "second readyRead()"
    lines = extractCompleteLines(buffer);
    QCOMPARE(lines.size(), 1);
    QCOMPARE(lines.first(), QString("device-01,42.1"));
    // This test is the direct automated version of Day 17's manual
    // socat exercise — same scenario, now regression-proof and runnable in CI
}

void TestSerialWorker::multipleLinesInOneChunk_emitsEachSeparately() {
    QByteArray buffer;
    buffer.append("device-01,42.1\ndevice-02,38.7\n");
    QStringList lines = extractCompleteLines(buffer);

    QCOMPARE(lines.size(), 2);
    QCOMPARE(lines.at(0), QString("device-01,42.1"));
    QCOMPARE(lines.at(1), QString("device-02,38.7"));
}

void TestSerialWorker::trailingPartialData_notEmittedUntilNewlineArrives() {
    QByteArray buffer;
    buffer.append("device-01,42.1\ndevice-02,3"); // second line incomplete
    QStringList lines = extractCompleteLines(buffer);

    QCOMPARE(lines.size(), 1); // only the complete first line
    QCOMPARE(buffer, QByteArray("device-02,3")); // partial second line retained
}

QTEST_MAIN(TestSerialWorker)
#include "test_serialworker.moc"
```

### `QSignalSpy` — Worth Understanding Properly, You'll Use It Constantly

`QSignalSpy` attaches to a signal and records every emission (as a list of argument lists) without you writing a slot. This is the standard tool for asserting "did X fire, how many times, with what data" in Qt tests — the alternative (connecting a lambda that sets a bool/counter) works but is more boilerplate for the same result. Use `spy.count()` for occurrence counts, `spy.takeFirst()`/`spy.at(n)` to inspect arguments of a specific emission, and `spy.wait(timeoutMs)` when testing something asynchronous that might not have fired yet by the time you check (relevant for testing worker-thread code, Day 26 territory).

### Why This Matters

- **The refactor to extract `extractCompleteLines()` as a standalone function** is the single most important lesson today: testability isn't just "write tests after the fact," it's a design pressure that, when followed, tends to produce cleaner, more reusable code anyway. The buffering logic didn't get worse by being extracted — it got more reusable (works for any streaming byte source, not just `QSerialPort` specifically) and dramatically easier to verify exhaustively (every edge case — split writes, multiple lines, trailing partial data — became a 4-line test instead of a manual `socat` session).
- **`init()`/`cleanup()` running before/after every test** guards against test-order-dependent bugs — a classic, subtle testing mistake is one test's leftover state accidentally making a later test pass or fail incorrectly; fresh state per test eliminates this category of flakiness entirely.
- **Testing `dataChanged`/`rowsInserted` emission directly via `QSignalSpy`**, not just the resulting data, catches a whole class of bug that pure state-checking would miss — e.g., a correct final data value but a missing signal emission (which would mean the view never actually refreshes, a real and easy-to-introduce regression when someone edits `upsertReading()` later).
- **This test suite requires no `QApplication`, no visible window, no real hardware** — it can run in CI on a headless build server, which matters enormously for a project you'll want to keep correct over time without manually re-verifying GUI behavior by hand after every change.

### Exercise

1. Refactor `SerialWorker::onReadyRead()` to use the extracted `extractCompleteLines()` function (rather than duplicating the logic inline), confirming the real serial path and the test path now share identical logic — not just similar-looking copies.
2. Write an equivalent test suite for `PersistenceWorker`'s SQL logic, using an **in-memory SQLite database** (`db.setDatabaseName(":memory:")`) instead of a real file — this gives you fast, disk-I/O-free tests for your insert/query logic, another genuinely useful Qt/SQLite testing pattern worth knowing.
3. Add a test for `DeviceTableModel::removeReading()` (from Day 9's exercise, if you built it) verifying `beginRemoveRows`/`endRemoveRows` fire correctly via `QSignalSpy` on `rowsRemoved`, and that the row count and remaining data are correct afterward — a good test of exactly the "did I bracket the mutation correctly" concern Day 9 flagged as a common bug source.

### Key Takeaways

- Most of your Qt business logic (models, buffering, SQL) needs no GUI at all to test — only `QCoreApplication`, not `QApplication`, and sometimes not even that for pure-logic pieces.
- `QTEST_MAIN` + the `.moc` include is required boilerplate whenever `Q_OBJECT` lives directly in a `.cpp` test file rather than a paired header.
- `init()`/`cleanup()` per-test (not just once per class) prevents cross-test state contamination — a real, common source of flaky test suites.
- `QSignalSpy` is the standard tool for verifying signal emission count and arguments without writing throwaway slots.
- Testability pressure (extracting `extractCompleteLines()` as a standalone function) tends to genuinely improve code design, not just accommodate testing as an afterthought — treat friction in writing a test as a signal to reconsider the code's shape, not just push through it.
- In-memory SQLite (`:memory:`) is the right tool for fast, disk-free database logic tests.

---

Say "next" for Day 26 (testing multi-threaded worker code properly — using `QSignalSpy::wait()` and a test-only `QThread` harness to verify `SerialWorker`/`MqttWorker`/`PersistenceWorker` behavior end-to-end without needing real hardware or a real broker).