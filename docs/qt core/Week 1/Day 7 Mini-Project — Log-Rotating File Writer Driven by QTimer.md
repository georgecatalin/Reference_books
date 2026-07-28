[[Foundations]]

This is the week's synthesis exercise: a self-contained `QObject`-derived component that buffers incoming log lines, flushes them to disk on a timer, and rotates to a new file once a size threshold is exceeded — the exact shape of component you'd embed in `mqtt_monitor` for durable, non-blocking logging of device readings. Everything here uses only what you've built across Days 1–6: QObject ownership, signals/slots, QTimer, and QString/QList.

**Design, resolved up front:**

- `RotatingLogWriter` is a `QObject`. It owns nothing external — you'll parent it to whatever service object creates it (Day 5).
- It exposes a slot `writeLine(const QString &line)` — the intended entry point (connectable to any signal, per Day 3's decoupling philosophy: a `SerialReader`'s `lineReceived` signal could feed straight into this).
- Internally, lines are buffered in a `QStringList` (Day 6), **not written to disk immediately** — writing is deferred to a `QTimer`-driven flush (Day 4), so a burst of incoming lines doesn't do a disk write per line.
- On each flush, if the current file has grown past a size threshold, it closes the file and opens a new timestamped one — "rotation."
- It emits a signal `fileRotated(const QString &newPath)` so listeners (e.g. a status dashboard, or just a log line itself) can react — again, pure decoupling per Day 3.

**Resolved code:**

```cpp
// rotatinglogwriter.h
#pragma once
#include <QObject>
#include <QStringList>
#include <QTimer>
#include <QFile>
#include <QDir>
#include <QDateTime>

class RotatingLogWriter : public QObject
{
    Q_OBJECT
public:
    explicit RotatingLogWriter(const QString &directory,
                                qint64 maxBytesPerFile = 4096,   // small for demo purposes
                                int flushIntervalMs = 2000,
                                QObject *parent = nullptr)
        : QObject(parent)
        , m_directory(directory)
        , m_maxBytesPerFile(maxBytesPerFile)
    {
        QDir().mkpath(m_directory);   // ensure the log directory exists

        m_flushTimer.setInterval(flushIntervalMs);
        m_flushTimer.setTimerType(Qt::CoarseTimer);   // per Day 4: flushing isn't timing-critical
        connect(&m_flushTimer, &QTimer::timeout, this, &RotatingLogWriter::flush);
        m_flushTimer.start();

        openNewFile();   // start with a fresh file immediately
    }

    ~RotatingLogWriter() override
    {
        flush();   // don't lose buffered lines on shutdown
        if (m_currentFile.isOpen())
            m_currentFile.close();
    }

public slots:
    // Intended connection target for something like SerialReader::lineReceived
    void writeLine(const QString &line)
    {
        m_buffer.append(QDateTime::currentDateTime().toString(Qt::ISODate) + " " + line);
    }

signals:
    void fileRotated(const QString &newPath);

private slots:
    void flush()
    {
        if (m_buffer.isEmpty())
            return;   // nothing buffered -- avoid a pointless disk write (Day 4 discipline: cheap no-ops)

        QTextStream out(&m_currentFile);
        for (const QString &line : std::as_const(m_buffer)) {
            out << line << '\n';
        }
        out.flush();
        m_buffer.clear();   // per Day 6: clearing a QList that's not shared elsewhere is cheap, no detach drama here

        if (m_currentFile.size() >= m_maxBytesPerFile) {
            rotate();
        }
    }

    void rotate()
    {
        m_currentFile.close();
        openNewFile();
        emit fileRotated(m_currentFile.fileName());
    }

private:
    void openNewFile()
    {
        const QString name = QString("log_%1.txt")
            .arg(QDateTime::currentDateTime().toString("yyyyMMdd_hhmmss_zzz"));
        m_currentFile.setFileName(m_directory + "/" + name);
        if (!m_currentFile.open(QIODevice::WriteOnly | QIODevice::Text)) {
            qWarning() << "Failed to open log file:" << m_currentFile.fileName();
        }
    }

    QString m_directory;
    qint64 m_maxBytesPerFile;
    QStringList m_buffer;
    QFile m_currentFile;
    QTimer m_flushTimer;
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include <QTimer>
#include <QDebug>
#include "rotatinglogwriter.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // Parented to nothing explicit -- app's implicit lifetime covers the whole run;
    // in a real service this would be parented to your top-level service object (Day 5).
    auto *writer = new RotatingLogWriter("./logs", /*maxBytesPerFile=*/300, /*flushIntervalMs=*/1000);

    QObject::connect(writer, &RotatingLogWriter::fileRotated, [](const QString &path) {
        qDebug() << "[ROTATED] now writing to:" << path;
    });

    // Simulate a SerialReader emitting lines faster than the flush interval,
    // to prove buffering + periodic flush + rotation all work together.
    QTimer lineGenerator;
    int counter = 0;
    QObject::connect(&lineGenerator, &QTimer::timeout, [&]() {
        ++counter;
        writer->writeLine(QString("TEMP:%1").arg(20.0 + (counter % 10) * 0.5));
    });
    lineGenerator.start(150);   // faster than the 1000ms flush -- several lines buffer per flush

    QTimer::singleShot(6000, &app, &QCoreApplication::quit);   // run for 6 seconds, then exit
    int result = app.exec();

    delete writer;   // manual delete here since it has no parent (per Day 5 rules) -- flushes remaining lines in destructor
    return result;
}
```

**CMakeLists.txt:**

```cmake
cmake_minimum_required(VERSION 3.16)
project(day7_logwriter)
set(CMAKE_CXX_STANDARD 17)
set(CMAKE_AUTOMOC ON)
find_package(Qt6 REQUIRED COMPONENTS Core)
add_executable(day7_logwriter main.cpp rotatinglogwriter.h)
target_link_libraries(day7_logwriter Qt6::Core)
```

**Resolved run output (illustrative):**

```
[ROTATED] now writing to: "./logs/log_20260722_140503_812.txt"
[ROTATED] now writing to: "./logs/log_20260722_140505_814.txt"
[ROTATED] now writing to: "./logs/log_20260722_140507_816.txt"
```

And `./logs/` ends up containing several small `log_*.txt` files, each under ~300 bytes, each containing several ISO-timestamped `TEMP:xx.x` lines — proof that lines generated every 150ms were correctly buffered and only written in batches every 1000ms, and that crossing the 300-byte threshold correctly triggered rotation into a fresh file rather than growing one file indefinitely.

**Why this design choice, resolved explicitly (the "why," not just the "what"):**

- **Buffering + timer-driven flush, rather than writing on every `writeLine()` call:** a disk write is comparatively slow and, if done synchronously inside a slot invoked at high frequency (recall Day 2's warning about blocking the event loop), risks stalling everything else on that thread. Batching amortizes that cost.
- **`QStringList` for the buffer:** per Day 6, `m_buffer.clear()` after flush is cheap — no detach cost — because nothing else holds a reference to that list. If you'd instead passed copies of the buffer around to other objects before clearing, COW would still protect you from data corruption, but you'd pay a detach on the next mutation.
- **`QTimer::CoarseTimer` for the flush interval:** exactly per Day 4's guidance — flush timing has no correctness requirement down to the millisecond, so the cheaper coarse timer is the right choice, freeing precise timers for cases that actually need them (e.g. a serial protocol's byte-timeout).
- **Manual `delete writer` at the end:** `writer` was constructed with no parent, so per Day 5's rules, nothing else will clean it up — and doing so explicitly also guarantees its destructor's `flush()` call runs before the program exits, so no buffered-but-unflushed lines are silently lost on shutdown. This is the resolved answer to "what happens to data still in the buffer when the timer hasn't fired yet at shutdown time" — a real concern for any production logging component.

**Key takeaways (mini-project synthesis):**

- A real Qt Core component is rarely "one signal, one slot" — it's several of Week 1's pieces composed: ownership (Day 5) determines who's responsible for cleanup, timers (Day 4) drive periodic behavior without blocking, signals (Day 3) decouple the component from whatever's listening for rotation events, and containers (Day 6) hold buffered state cheaply.
- Explicit destructor logic (flushing before close) matters whenever a component buffers anything — timer-driven periodic work always needs a corresponding "flush on shutdown" path, or you lose whatever hadn't been flushed yet.
- This exact shape — buffer, periodic flush, threshold-triggered rotation, signal on rotation — generalizes directly to database write batching, network message batching, or anything else in `mqtt_monitor` where "don't do a slow operation per event, batch it on a timer" applies.

Week 2 starts on Day 8 with `QFile`/`QIODevice`/`QTextStream`/`QDataStream` in more depth — you've already used `QFile` and `QTextStream` here somewhat superficially; Day 8 will cover the full `QIODevice` abstraction underneath them (why `QFile`, `QTcpSocket`, and `QProcess` all share a common read/write interface), which matters directly for Week 4's networking and Week 3's `QProcess` material.