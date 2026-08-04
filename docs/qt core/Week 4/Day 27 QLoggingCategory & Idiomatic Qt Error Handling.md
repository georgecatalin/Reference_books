[[Networking]]

**Theory: why Qt avoids exceptions internally, and what it uses instead**

You've now used dozens of Qt Core APIs across this course, and — worth noticing explicitly — **not one of them threw an exception on failure.** `QFile::open()` returns `bool` (Day 8). `QJsonDocument::fromJson()` sets a `QJsonParseError` out-parameter (Day 10). `QProcess` and `QNetworkReply` signal failure via `errorOccurred()`/`error()` (Days 19, 24). This is a deliberate, consistent Qt design convention, not an oversight: **exceptions unwind the stack, and Qt's own internals (much of it C-heritage, event-loop-based, sometimes crossing into C-linked platform code) are not universally exception-safe.** Qt Core APIs signal failure through return values, out-parameters, and error signals — and idiomatic Qt code follows the same convention for its own error handling, for consistency with the framework it's built on, even though C++ exceptions work fine in your _own_ code that doesn't cross Qt's internals.

This doesn't mean "never use exceptions in a Qt Core codebase" — it means: **don't expect exceptions to propagate through Qt's event loop or signal/slot dispatch**, and match Qt's own convention (checked return values, error signals) at any boundary where your code interacts directly with a Qt API that uses that convention, rather than wrapping every Qt call in try/catch expecting it to throw.

**`QLoggingCategory` — resolved: structured logging beyond plain `qDebug()`**

Every `qDebug()` call you've written all course prints unconditionally, with no way to selectively silence one subsystem's noise while keeping another's. `QLoggingCategory` fixes this: you declare named categories (e.g., `mqtt_monitor.serial`, `mqtt_monitor.network`), log through them instead of bare `qDebug()`, and control verbosity per-category at runtime via an environment variable or config file — without recompiling or manually deleting log statements.

**Resolved example 1 — declaring and using logging categories**

```cpp
// logging.h
#pragma once
#include <QLoggingCategory>

// Declared once, used everywhere -- Q_DECLARE_LOGGING_CATEGORY in the header,
// Q_LOGGING_CATEGORY (the actual definition) in exactly one .cpp file.
Q_DECLARE_LOGGING_CATEGORY(logSerial)
Q_DECLARE_LOGGING_CATEGORY(logNetwork)
Q_DECLARE_LOGGING_CATEGORY(logDatabase)
```

```cpp
// logging.cpp
#include "logging.h"

// The string argument is the category's runtime-configurable NAME --
// this is what you'd reference in QT_LOGGING_RULES to control verbosity.
Q_LOGGING_CATEGORY(logSerial, "mqtt_monitor.serial")
Q_LOGGING_CATEGORY(logNetwork, "mqtt_monitor.network")
Q_LOGGING_CATEGORY(logDatabase, "mqtt_monitor.database")
```

```cpp
// main.cpp
#include <QCoreApplication>
#include "logging.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // qCDebug/qCWarning/qCCritical -- the "C" variants take a CATEGORY as
    // their first argument, instead of the bare qDebug()/qWarning() you've
    // used all course, which implicitly log under a default, unfiltered category.
    qCDebug(logSerial) << "Opening serial port /dev/ttyUSB0";
    qCWarning(logSerial) << "Serial read timeout, retrying";
    qCDebug(logNetwork) << "Connecting to MQTT broker";
    qCCritical(logDatabase) << "Failed to open SQLite database file";

    return 0;
}
```

**Resolved output, default run (everything enabled by default except qCDebug of custom categories, which Qt disables by default unless configured):**

```
mqtt_monitor.serial: Serial read timeout, retrying
mqtt_monitor.database: Failed to open SQLite database file
```

Notice: `qCDebug` output is **silent by default** for custom categories — Qt's default logging rules only show `qCWarning`/`qCCritical` for categories you declare, unless you explicitly enable debug-level output. This is itself the resolved point: you get quiet-by-default detailed logging that's there when you need it, without needing to comment/uncomment debug statements in source.

**Resolved: enabling verbose logging at runtime, no recompilation, via `QT_LOGGING_RULES`**

```bash
QT_LOGGING_RULES="mqtt_monitor.serial.debug=true" ./day27_logging
```

**Resolved output with that environment variable set:**

```
mqtt_monitor.serial: Opening serial port /dev/ttyUSB0
mqtt_monitor.serial: Serial read timeout, retrying
mqtt_monitor.database: Failed to open SQLite database file
```

Now `logSerial`'s debug output appears too, while `logNetwork` and `logDatabase` debug-level messages remain suppressed (network module never logged a warning/critical in this run, so nothing from it appears; database's critical still shows regardless, since criticals aren't gated the way debug messages are). This is the resolved, practical payoff: in a deployed `mqtt_monitor` service, you can turn on detailed serial-subsystem logging **in production, on a running system, via one environment variable**, without a rebuild or a restart-with-different-flags — invaluable when diagnosing an intermittent field issue you can't reproduce locally.

**Resolved example 2 — the idiomatic Qt error-handling convention applied to your own class, matching the framework's own style**

```cpp
// devicereader.h
#pragma once
#include <QObject>
#include <QString>
#include "logging.h"

class DeviceReader : public QObject
{
    Q_OBJECT
public:
    explicit DeviceReader(QObject *parent = nullptr) : QObject(parent) {}

    // Resolved: matches QFile::open()'s own convention exactly -- bool return,
    // and a separate errorString() for the human-readable reason, rather than
    // throwing a custom exception type. This is idiomatic precisely BECAUSE
    // it matches the convention of the QFile-based code (Day 8) this class
    // likely wraps internally.
    bool openDevice(const QString &path)
    {
        if (path.isEmpty()) {
            m_lastError = "Device path cannot be empty";
            qCWarning(logSerial) << m_lastError;
            return false;
        }

        // (real implementation would attempt QSerialPort::open() or similar here)
        bool simulatedSuccess = path.startsWith("/dev/");
        if (!simulatedSuccess) {
            m_lastError = QString("Invalid device path format: %1").arg(path);
            qCWarning(logSerial) << m_lastError;
            return false;
        }

        qCDebug(logSerial) << "Device opened successfully:" << path;
        return true;
    }

    QString lastError() const { return m_lastError; }

private:
    QString m_lastError;
};
```

```cpp
// main.cpp usage
DeviceReader reader;

if (!reader.openDevice("bad_path")) {
    qCritical() << "Startup failed:" << reader.lastError();
    return 1;   // resolved: fail fast and clearly at startup, rather than
                // continuing into an inconsistent running state
}
```

**Resolved output:**

```
mqtt_monitor.serial: Invalid device path format: "bad_path"
Startup failed: "Invalid device path format: \"bad_path\""
```

Resolved rationale for matching this convention in your own code, stated explicitly: `DeviceReader` will eventually be used alongside `QSerialPort`, `QFile`, `QTcpSocket` — all Qt classes using bool-return + error-string. If `DeviceReader` alone threw exceptions while everything around it used checked returns, callers would need two completely different error-handling styles side by side in the same function — genuinely worse ergonomics than just matching the surrounding convention, even though "use exceptions" is perfectly reasonable advice in a pure-C++ codebase with no Qt interop at all.

**Key takeaways:**

- Qt Core's own APIs consistently signal failure via checked return values, out-parameters, or error signals — never exceptions — because Qt's internals aren't universally exception-safe across the event loop and C-heritage code paths; matching this convention in your own Qt-adjacent classes avoids mixing two incompatible error-handling styles in the same codebase.
- `QLoggingCategory` gives you named, independently filterable logging channels — `qCDebug`/`qCWarning`/`qCCritical` with a category argument, versus the bare `qDebug()` you've used all course, which logs to an unfiltered default category.
- Debug-level category output is silent by default; enable it selectively and at runtime via `QT_LOGGING_RULES`, with zero recompilation — genuinely valuable for diagnosing a live, deployed service without restarting it under different build flags.
- This structured logging is the direct replacement for the ad-hoc `qDebug()` calls scattered through every example so far in this course — in real `mqtt_monitor` code, each module (serial, network, database) would get its own category, giving you fine-grained runtime control over log verbosity per subsystem.

Day 28 covers `QtTest` — writing real unit tests for the classes and pipelines built across this course (the parser, the state machine, the batch processor), including how to properly test asynchronous, signal-driven code rather than just synchronous return values.