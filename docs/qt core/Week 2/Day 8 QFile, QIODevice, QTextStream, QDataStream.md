[[Intermediate Concepts]]

**Theory: QIODevice — the abstraction that unifies files, sockets, and processes**

`QFile` is not a standalone file-handling class — it's one of several concrete subclasses of `QIODevice`, an abstract base class defining a common read/write interface: `open()`, `close()`, `read()`, `write()`, `bytesAvailable()`, `atEnd()`, and — critically — the `readyRead()` **signal** (Day 3) for anything that supports asynchronous notification. `QTcpSocket` (Week 4), `QProcess` (Week 3), and `QFile` **all** derive from `QIODevice` and share this interface. This is deliberate: code you write against the `QIODevice` interface today (a generic line-reading loop, say) will work unchanged against a TCP socket or a subprocess's stdout later, which is exactly the pattern `mqtt_monitor`'s serial-ingestion code relies on — the same parsing logic applies whether data comes from a real serial device, a test file, or (eventually) a network stream.

**Two layers on top of `QIODevice`, resolved:**

- **`QTextStream`** — for **text**: handles encoding (UTF-8 by default in Qt6), line-based reading (`readLine()`), and formatted output (`<<` operators), similar in spirit to `std::iostream` but working with any `QIODevice`, not just files.
- **`QDataStream`** — for **binary**: serializes C++/Qt types (`int`, `double`, `QString`, `QList<T>`, etc.) into a well-defined, versioned binary format, and reads them back with full type fidelity — useful when you need compact, structured binary storage rather than human-readable text (e.g. caching parsed sensor readings to disk faster than JSON parsing would allow).

**Resolved example 1 — QFile + QTextStream: writing and reading text, explicit encoding**

```cpp
#include <QCoreApplication>
#include <QFile>
#include <QTextStream>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    const QString path = "readings.txt";

    // --- Writing ---
    {
        QFile file(path);
        if (!file.open(QIODevice::WriteOnly | QIODevice::Text)) {
            qWarning() << "open for write failed:" << file.errorString();
            return 1;
        }
        QTextStream out(&file);
        out.setEncoding(QStringConverter::Utf8);   // explicit; this is Qt6's default anyway, but stating intent matters
        out << "TEMP:23.5\n";
        out << "TEMP:24.1\n";
        out << "HUMIDITY:61\n";
        // file closes automatically when it goes out of scope (QFile's destructor calls close())
    }

    // --- Reading, line by line ---
    {
        QFile file(path);
        if (!file.open(QIODevice::ReadOnly | QIODevice::Text)) {
            qWarning() << "open for read failed:" << file.errorString();
            return 1;
        }
        QTextStream in(&file);
        int lineNum = 0;
        while (!in.atEnd()) {
            QString line = in.readLine();
            ++lineNum;
            qDebug() << "line" << lineNum << ":" << line;
        }
    }

    return 0;
}
```

**Resolved output:**

```
line 1 : "TEMP:23.5"
line 2 : "TEMP:24.1"
line 3 : "HUMIDITY:61"
```

Note the resolved detail on `QIODevice::Text` mode: on write, it normalizes line endings to the platform convention (`\n` on Linux, `\r\n` on Windows) — since your embedded targets (Day 5's context: Raspberry Pi, BeagleBone) are Linux, this is a no-op for you in practice, but it's why the flag exists and why cross-platform Qt code always includes it rather than assuming.

**Resolved example 2 — error handling done properly: `QFile::error()` / `errorString()`, and the permission-denied case**

```cpp
#include <QCoreApplication>
#include <QFile>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // Deliberately attempt to write somewhere we (likely) don't have permission
    QFile blocked("/root/should_fail.txt");
    bool opened = blocked.open(QIODevice::WriteOnly);

    if (!opened) {
        qDebug() << "open() returned false, as expected";
        qDebug() << "error code:" << blocked.error();          // QFileDevice::OpenError enum value
        qDebug() << "error string:" << blocked.errorString();   // human-readable OS message
    }

    // Resolved pattern for real code: NEVER assume open() succeeded.
    // Always branch on the bool return and log errorString() -- this is
    // the Qt Core equivalent of checking errno after a failed POSIX open().

    return opened ? 0 : 1;
}
```

**Resolved output (exact message is OS-dependent, but the pattern holds):**

```
open() returned false, as expected
error code: 5
error string: "Permission denied"
```

This resolves a habit worth locking in now: every `QFile::open()` (and, later, every `QIODevice::open()` on a socket or process) returns `bool`, and the failure path always has a real, inspectable reason via `errorString()` — there's no excuse for silently ignoring a failed open in service code that's supposed to run unattended.

**Resolved example 3 — QDataStream: binary serialization of a structured reading, round-tripped**

```cpp
#include <QCoreApplication>
#include <QFile>
#include <QDataStream>
#include <QDebug>
#include <QList>

struct Reading {
    QString deviceId;
    double value;
    qint64 timestampMs;
};

// QDataStream needs explicit operator<< / operator>> overloads to know how
// to serialize a custom struct -- this is the resolved boilerplate for that.
QDataStream &operator<<(QDataStream &out, const Reading &r)
{
    out << r.deviceId << r.value << r.timestampMs;
    return out;
}
QDataStream &operator>>(QDataStream &in, Reading &r)
{
    in >> r.deviceId >> r.value >> r.timestampMs;
    return in;
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QList<Reading> readings = {
        {"sensor-01", 23.5, 1753180800000},
        {"sensor-02", 19.8, 1753180801000},
        {"sensor-01", 23.7, 1753180802000},
    };

    const QString path = "readings.bin";

    // --- Write binary ---
    {
        QFile file(path);
        file.open(QIODevice::WriteOnly);
        QDataStream out(&file);
        out.setVersion(QDataStream::Qt_6_5);   // ALWAYS pin a version explicitly: ensures the binary
                                                 // format stays stable across Qt point releases, so a
                                                 // file written today is still readable after an upgrade.
        out << readings.size();
        for (const Reading &r : readings)
            out << r;
    }

    // --- Read binary back ---
    {
        QFile file(path);
        file.open(QIODevice::ReadOnly);
        QDataStream in(&file);
        in.setVersion(QDataStream::Qt_6_5);

        qsizetype count;
        in >> count;
        qDebug() << "reading" << count << "records back";

        for (qsizetype i = 0; i < count; ++i) {
            Reading r;
            in >> r;
            qDebug() << r.deviceId << r.value << r.timestampMs;
        }
    }

    return 0;
}
```

**Resolved output:**

```
reading 3 records back
"sensor-01" 23.5 1753180800000
"sensor-02" 19.8 1753180801000
"sensor-01" 23.7 1753180802000
```

Resolved rationale for `setVersion()`: `QDataStream`'s binary format has evolved across Qt versions (field widths, encodings), and if you don't pin a version explicitly, Qt uses whatever the current build's default is — meaning a file written by Qt 6.5 might not read back correctly under a future Qt 6.9 if the default silently changed. Pinning `Qt_6_5` explicitly means the format is frozen and portable regardless of what Qt version reads it later, as long as you keep using the same pinned version consistently across writer and reader.

**Key takeaways:**

- `QFile`, `QTcpSocket`, and `QProcess` all derive from `QIODevice` — code written against the `QIODevice`/`QTextStream` interface generalizes directly to sockets and processes later in the course.
- `QTextStream` handles text encoding and line-based I/O; `QDataStream` handles versioned, typed binary serialization — pick text for human-readable/interoperable formats (config, JSON, logs), binary for compact structured storage where you control both writer and reader.
- Every `open()` call returns `bool` and must be checked — `errorString()` gives you the real, actionable reason for failure, and ignoring it in a long-running service means silent, undiagnosable failures later.
- Custom types need explicit `operator<<`/`operator>>` overloads to work with `QDataStream`; always pin `setVersion()` explicitly rather than relying on the ambient default.

Day 9 continues directly: `QSettings`, which uses this same `QIODevice`-adjacent world for config file parsing (INI format) but adds automatic type conversion via `QVariant` (Day 6) and a hierarchical key/group structure — directly relevant to configuring a real `mqtt_monitor` deployment (broker address, serial port, thresholds) without hand-rolling a parser.