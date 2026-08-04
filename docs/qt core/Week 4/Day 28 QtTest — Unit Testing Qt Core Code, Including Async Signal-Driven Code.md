[[Testing]]

**Theory: why testing signal-driven code needs more than a plain assert**

Most of this course's components (Day 14's parser, Day 25's state machine, Day 21's batch processor) communicate results via signals, not return values — so a naive unit test that calls a method and immediately asserts on some return value **misses async behavior entirely**. `QtTest` (Qt's testing module, `QT += testlib`) provides `QSignalSpy` specifically for this: it attaches to a signal and records every emission, letting your test assert "this signal fired N times, with these arguments" — even for genuinely asynchronous emissions crossing the event loop or threads (Days 15–16).

**Resolved example 1 — testing Day 14's SerialLineParser: pure synchronous logic, straightforward assertions**

```cpp
// test_seriallineparser.cpp
#include <QtTest>
#include "seriallineparser.h"

class TestSerialLineParser : public QObject
{
    Q_OBJECT

private slots:
    // Each private slot named test* is automatically discovered and run by QtTest.

    void parsesValidLine()
    {
        SerialLineParser parser;
        auto result = parser.parse("SENSOR:07:TEMP:23.5");

        QVERIFY(result.has_value());                          // resolved: confirms parse succeeded at all
        QCOMPARE(result->deviceId, QString("sensor-07"));      // resolved: exact value check
        QCOMPARE(result->temperature, 23.5);
    }

    void rejectsGarbageLine()
    {
        SerialLineParser parser;
        auto result = parser.parse("GARBAGE DATA");
        QVERIFY(!result.has_value());   // resolved: confirms REJECTION is the correct behavior here
    }

    void rejectsTrailingData()
    {
        SerialLineParser parser;
        // Per Day 14's design: anchored regex correctly rejects unexpected trailing content
        auto result = parser.parse("SENSOR:07:TEMP:23.5:EXTRA:field");
        QVERIFY(!result.has_value());
    }

    void handlesNegativeTemperature()
    {
        SerialLineParser parser;
        auto result = parser.parse("SENSOR:12:TEMP:-4.2");
        QVERIFY(result.has_value());
        QCOMPARE(result->temperature, -4.2);
    }

    // QtTest also supports DATA-DRIVEN tests -- resolved pattern for testing
    // many similar cases without duplicating test method bodies.
    void parsesVariousFormats_data()
    {
        QTest::addColumn<QString>("input");
        QTest::addColumn<bool>("shouldSucceed");

        QTest::newRow("valid integer temp") << "SENSOR:01:TEMP:20" << true;
        QTest::newRow("valid decimal temp") << "SENSOR:02:TEMP:20.5" << true;
        QTest::newRow("empty string") << "" << false;
        QTest::newRow("missing temp value") << "SENSOR:03:TEMP:" << false;
        QTest::newRow("non-numeric id") << "SENSOR:ab:TEMP:20" << false;
    }

    void parsesVariousFormats()
    {
        QFETCH(QString, input);
        QFETCH(bool, shouldSucceed);

        SerialLineParser parser;
        auto result = parser.parse(input);
        QCOMPARE(result.has_value(), shouldSucceed);
    }
};

QTEST_MAIN(TestSerialLineParser)   // resolved: generates a full, self-contained main() for this test binary
#include "test_seriallineparser.moc"
```

**CMakeLists.txt addition for tests:**

```cmake
find_package(Qt6 REQUIRED COMPONENTS Core Test)
enable_testing()

add_executable(test_seriallineparser test_seriallineparser.cpp)
target_link_libraries(test_seriallineparser Qt6::Core Qt6::Test)
add_test(NAME SerialLineParserTests COMMAND test_seriallineparser)
```

**Resolved output (running `ctest` or the binary directly):**

```
********* Start testing of TestSerialLineParser *********
PASS   : TestSerialLineParser::parsesValidLine()
PASS   : TestSerialLineParser::rejectsGarbageLine()
PASS   : TestSerialLineParser::rejectsTrailingData()
PASS   : TestSerialLineParser::handlesNegativeTemperature()
PASS   : TestSerialLineParser::parsesVariousFormats(valid integer temp)
PASS   : TestSerialLineParser::parsesVariousFormats(valid decimal temp)
PASS   : TestSerialLineParser::parsesVariousFormats(empty string)
PASS   : TestSerialLineParser::parsesVariousFormats(missing temp value)
PASS   : TestSerialLineParser::parsesVariousFormats(non-numeric id)
Totals: 9 passed, 0 failed, 0 skipped, 0 blacklisted
********* Finished testing of TestSerialLineParser *********
```

Resolved point on the data-driven approach: five distinct input cases, one test method body — adding a sixth case is a one-line addition to `_data()`, not a new copy-pasted test method. This is the standard, idiomatic way to test the same logic against many inputs in QtTest.

**Resolved example 2 — testing Day 4/16-style async signal behavior with QSignalSpy**

```cpp
// test_devicereading.cpp
#include <QtTest>
#include <QSignalSpy>
#include "devicereading.h"

class TestDeviceReading : public QObject
{
    Q_OBJECT

private slots:
    void notifySignalFiresOnChange()
    {
        DeviceReading reading;

        // QSignalSpy attaches to a signal and records every emission --
        // works regardless of whether the emission is synchronous or
        // arrives later via a queued cross-thread connection (Day 15/16).
        QSignalSpy spy(&reading, &DeviceReading::temperatureChanged);

        reading.setTemperature(23.5);

        QCOMPARE(spy.count(), 1);                     // resolved: fired exactly once
        QList<QVariant> arguments = spy.takeFirst();   // resolved: inspect the actual emitted argument
        QCOMPARE(arguments.at(0).toDouble(), 23.5);
    }

    void notifySignalDoesNotFireOnNoOpChange()
    {
        // Per Day 12's no-op guard discipline -- this test EXISTS specifically
        // to catch a regression if someone later removes that guard.
        DeviceReading reading;
        reading.setTemperature(23.5);   // first set -- establishes the value

        QSignalSpy spy(&reading, &DeviceReading::temperatureChanged);
        reading.setTemperature(23.5);   // SAME value again

        QCOMPARE(spy.count(), 0);   // resolved: the no-op guard must suppress this
    }

    void waitsForAsyncSignalWithTimeout()
    {
        // Demonstrating the pattern for genuinely delayed signals (e.g. a
        // QTimer::singleShot-driven emission, or a cross-thread queued signal) --
        // QSignalSpy::wait() blocks the TEST (not the object under test) until
        // the signal fires or a timeout elapses, returning false on timeout.
        DeviceReading reading;
        QSignalSpy spy(&reading, &DeviceReading::temperatureChanged);

        QTimer::singleShot(100, [&reading]() {
            reading.setTemperature(99.9);   // fires 100ms from now, asynchronously
        });

        bool signalArrived = spy.wait(500);   // waits up to 500ms, pumping the event loop internally
        QVERIFY(signalArrived);
        QCOMPARE(spy.count(), 1);
    }
};

QTEST_MAIN(TestDeviceReading)
#include "test_devicereading.moc"
```

**Resolved output:**

```
PASS   : TestDeviceReading::notifySignalFiresOnChange()
PASS   : TestDeviceReading::notifySignalDoesNotFireOnNoOpChange()
PASS   : TestDeviceReading::waitsForAsyncSignalWithTimeout()
Totals: 3 passed, 0 failed, 0 skipped, 0 blacklisted
```

Resolved detail worth isolating: `QSignalSpy::wait()` is the correct tool for testing anything that emits **later**, not immediately — it internally runs a nested event loop (safe specifically because it's designed for exactly this purpose, unlike ad-hoc nested `exec()` calls elsewhere) until the signal fires or the timeout expires. Without it, a test asserting on a signal that hasn't fired yet (because it's genuinely still pending on a timer or another thread) would simply fail — not because the code is wrong, but because the test didn't wait for the async operation to complete.

**Resolved example 3 — a deliberately-introduced regression, caught by the no-op guard test**

To make the practical value of `notifySignalDoesNotFireOnNoOpChange()` concrete, resolved: imagine someone "simplifies" `DeviceReading::setTemperature()` by removing the equality guard:

```cpp
// REGRESSION -- guard removed
void setTemperature(double t)
{
    m_temperature = t;
    emit temperatureChanged(m_temperature);   // now fires EVERY call, even for identical values
}
```

**Resolved test output after this regression is introduced:**

```
PASS   : TestDeviceReading::notifySignalFiresOnChange()
FAIL!  : TestDeviceReading::notifySignalDoesNotFireOnNoOpChange() Compared values are not the same
   Actual   (spy.count()): 1
   Expected (0)           : 0
   Loc: [test_devicereading.cpp(24)]
PASS   : TestDeviceReading::waitsForAsyncSignalWithTimeout()
Totals: 2 passed, 1 failed, 0 skipped, 0 blacklisted
```

This is the resolved, concrete payoff of writing this specific test in the first place: Day 12's no-op guard was presented as important but easy to accidentally remove during a later refactor — this test exists precisely to catch that exact regression automatically, the moment it's introduced, rather than relying on someone noticing a signal-storm problem much later in production (recall Day 12 Example 3's 1000-identical-writes scenario — this is the automated test that would have caught a regression there).

**Key takeaways:**

- `QTEST_MAIN(ClassName)` + `#include "file.moc"` generates a complete, runnable test binary — each `private slot` named starting with lowercase becomes an auto-discovered test case.
- `QCOMPARE`/`QVERIFY` are QtTest's assertion macros — prefer `QCOMPARE` when checking equality, since its failure output shows both actual and expected values automatically (visible in the regression example above), unlike a bare `QVERIFY(a == b)`.
- Data-driven tests (`_data()` + `QFETCH`) test many input cases against one test body — the standard idiom for avoiding duplicated near-identical test methods.
- `QSignalSpy` is the correct tool for asserting on signal emissions, including counting emissions, inspecting arguments, and — via `wait()` — correctly testing signals that fire asynchronously (timers, cross-thread) without the test racing ahead of the emission.
- Write tests specifically for the subtle correctness properties this course has flagged as easy to regress (no-op guards, anchored regex rejection, threshold edge cases) — these are exactly the kind of "looks fine, silently breaks" issues automated tests catch that manual testing and code review often miss.

Day 29 covers CMake project structure and deployment considerations for embedded Linux targets — organizing a multi-file Qt Core project properly (separating library code from the test/app executables you've been building day by day) and cross-compilation/packaging concerns specific to Raspberry Pi and BeagleBone deployment.