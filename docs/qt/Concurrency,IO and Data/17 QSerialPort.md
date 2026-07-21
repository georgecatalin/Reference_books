[[Concurrency]]
## Day 17: `QSerialPort` on a Worker Thread — Real Serial I/O with Correct Buffering

### Concept: Serial Data Arrives in Chunks, Not Lines — Buffering Is Not Optional

This is the concept beginners consistently get wrong with serial I/O in any framework, not just Qt: **`readyRead()` fires whenever _some_ bytes have arrived — not necessarily a complete line, and possibly more than one line at once.** A device sending `"device-01,42.1\n"` might deliver that across two, three, or more `readyRead()` signals, split at arbitrary byte boundaries, especially under load or with slow/jittery embedded serial hardware (directly relevant to your BeagleBone/RPi experience — this is exactly the kind of behavior you've likely already fought with raw termios in C).

The correct pattern: **accumulate incoming bytes into a buffer, and only process/emit complete lines once a delimiter (`\n`) is actually found**, leaving any partial trailing data in the buffer for the next `readyRead()`.

### `QSerialPort` Setup — The Non-Negotiable Configuration

```cpp
#include <QSerialPort>
#include <QSerialPortInfo>
```

Listing available ports (useful for a connection dialog, and for sanity-checking what Qt actually sees):

```cpp
for (const QSerialPortInfo &info : QSerialPortInfo::availablePorts()) {
    qDebug() << info.portName() << info.description() << info.manufacturer();
}
```

### Annotated Code: `SerialWorker` — Following Day 16's Pattern Exactly

`serialworker.h`:

```cpp
#pragma once
#include <QObject>
#include <QSerialPort>
#include <QByteArray>

class SerialWorker : public QObject {
    Q_OBJECT
public:
    explicit SerialWorker(const QString &portName, int baudRate, QObject *parent = nullptr);

public slots:
    void start();  // entry point, called via thread->started()
    void stop();   // requested shutdown

signals:
    void lineReceived(QString line);
    void errorOccurred(QString message);
    void connectionStateChanged(bool connected);
    void finished();

private slots:
    void onReadyRead();
    void onSerialError(QSerialPort::SerialPortError error);

private:
    QString portName;
    int baudRate;
    QSerialPort *serial = nullptr; // created in start(), not constructor — Day 16 rule
    QByteArray buffer;             // accumulates partial data across readyRead() calls
};
```

`serialworker.cpp`:

```cpp
#include "serialworker.h"

SerialWorker::SerialWorker(const QString &portName, int baudRate, QObject *parent)
    : QObject(parent), portName(portName), baudRate(baudRate) {}

void SerialWorker::start() {
    // Created here (worker thread), not in the constructor (GUI thread) —
    // QSerialPort has thread affinity just like QTimer did in Day 16.
    serial = new QSerialPort(this);
    serial->setPortName(portName);
    serial->setBaudRate(baudRate);
    serial->setDataBits(QSerialPort::Data8);
    serial->setParity(QSerialPort::NoParity);
    serial->setStopBits(QSerialPort::OneStop);
    serial->setFlowControl(QSerialPort::NoFlowControl);

    connect(serial, &QSerialPort::readyRead, this, &SerialWorker::onReadyRead);
    // Qt6 signature — errorOccurred replaced the old (and now removed) 'error' signal
    connect(serial, &QSerialPort::errorOccurred, this, &SerialWorker::onSerialError);

    if (!serial->open(QIODevice::ReadOnly)) {
        emit errorOccurred(QString("Failed to open %1: %2")
                            .arg(portName, serial->errorString()));
        emit connectionStateChanged(false);
        return; // don't emit finished() here — see note below
    }

    emit connectionStateChanged(true);
}

void SerialWorker::onReadyRead() {
    buffer.append(serial->readAll());

    // Process every complete line currently in the buffer. There could be
    // zero, one, or several — never assume exactly one line per readyRead().
    int newlineIndex;
    while ((newlineIndex = buffer.indexOf('\n')) != -1) {
        QByteArray lineBytes = buffer.left(newlineIndex);
        buffer.remove(0, newlineIndex + 1); // consume the line + the delimiter

        // Strip a trailing \r too — very common with embedded devices that
        // send \r\n even when you only explicitly handle \n as the delimiter
        if (lineBytes.endsWith('\r')) {
            lineBytes.chop(1);
        }

        QString line = QString::fromUtf8(lineBytes).trimmed();
        if (!line.isEmpty()) {
            emit lineReceived(line);
        }
    }
    // Any bytes remaining in 'buffer' (no \n found yet) are correctly left
    // in place for the next readyRead() call — this IS the buffering fix.
}

void SerialWorker::onSerialError(QSerialPort::SerialPortError error) {
    if (error == QSerialPort::NoError) return; // this signal fires with NoError too, ignore it

    emit errorOccurred(QString("Serial error on %1: %2").arg(portName, serial->errorString()));

    // ResourceError typically means the device was unplugged — a real,
    // common scenario with USB-serial adapters on embedded targets
    if (error == QSerialPort::ResourceError) {
        emit connectionStateChanged(false);
        stop();
    }
}

void SerialWorker::stop() {
    if (serial && serial->isOpen()) {
        serial->close();
    }
    emit connectionStateChanged(false);
    emit finished();
}
```

### Wiring Into `MainWindow` — Same Lifecycle Pattern as Day 16

```cpp
void MainWindow::setupSerialThread(const QString &portName, int baudRate) {
    auto *serialThread = new QThread(this);
    auto *serialWorker = new SerialWorker(portName, baudRate); // no parent — required for moveToThread

    serialWorker->moveToThread(serialThread);

    connect(serialThread, &QThread::started, serialWorker, &SerialWorker::start);

    connect(serialWorker, &SerialWorker::lineReceived, this, [this](const QString &line) {
        // Parsing happens HERE, on the GUI thread, after the queued signal
        // delivery — the worker's only job was correct buffering/line-splitting,
        // not application-level parsing. Keep worker responsibilities narrow.
        QStringList parts = line.split(',');
        if (parts.size() == 2) {
            bool ok;
            double temp = parts[1].toDouble(&ok);
            if (ok) {
                deviceModel->upsertReading({parts[0], QDateTime::currentDateTime(), temp, true});
            }
        }
    });

    connect(serialWorker, &SerialWorker::errorOccurred, this, [this](const QString &msg) {
        logView->append("[SERIAL ERROR] " + msg);
    });

    connect(serialWorker, &SerialWorker::connectionStateChanged, this, [this](bool connected) {
        connectionIndicator->setText(connected ? "● Connected (Serial)" : "● Disconnected");
        connectionIndicator->setStyleSheet(connected ? "color: green; font-weight: bold;"
                                                       : "color: red; font-weight: bold;");
    });

    connect(serialWorker, &SerialWorker::finished, serialThread, &QThread::quit);
    connect(serialThread, &QThread::finished, serialWorker, &QObject::deleteLater);
    connect(serialThread, &QThread::finished, serialThread, &QObject::deleteLater);

    serialThread->start();
}
```

### The `start()` Failure Case — Why It Doesn't `emit finished()`

Look again at `start()`: if `serial->open()` fails, it emits `errorOccurred` and `connectionStateChanged(false)`, then just `return`s — it does **not** emit `finished()`. This is deliberate: `finished()` triggers the thread-quit-and-delete chain, tearing the whole worker/thread down. If the port simply isn't available _yet_ (device not plugged in, permissions not yet granted, `/dev/ttyUSB0` not enumerated), you likely want the thread to stay alive so a **reconnect attempt** can be triggered later (e.g., via a retry timer or user action) without rebuilding the entire thread/worker pair from scratch. Whether you tear down immediately or keep the worker alive for retry is a real design decision — for `mqtt_monitor`'s embedded reality (devices get unplugged, USB-serial adapters flake), keeping the worker alive and retriable is usually the better call.

### Why This Matters

- **Line buffering via accumulate-then-split-on-delimiter** is the single correct pattern for any streaming text protocol over serial (or TCP, for that matter) — this is not Qt-specific wisdom, it's true of raw termios/`read()` too, but Qt's `readyRead()` signal doesn't change the fundamental fact that TCP/serial are byte streams, not message streams.
- **`errorOccurred` fires with `NoError` too** — a genuinely easy-to-miss Qt quirk; always guard against it explicitly or you'll log spurious "error: no error" messages.
- **`ResourceError` specifically signals device disconnection** in most USB-serial scenarios — worth having a distinct code path for, since it's the most common real-world failure mode on embedded hardware with physically removable connections.
- **The worker does buffering/framing, the GUI-thread slot does parsing** — this division of labor keeps the worker reusable and simple (it doesn't need to know your `device,temperature` CSV-ish format), and keeps parsing logic in one place, easy to test independently of any actual serial hardware.
- Reusing the Day 16 lifecycle pattern exactly (same `finished`/`quit`/`deleteLater` chain) means you're not learning threading twice — this is the same skeleton you'll also use for Day 19's MQTT worker.

### Exercise

1. Set up a loopback test without real hardware: create a virtual serial pair using `socat` (`socat -d -d pty,raw,echo=0 pty,raw,echo=0`, which prints two `/dev/pts/N` paths), point `SerialWorker` at one end, and write test lines to the other end with a simple script or `echo "device-01,42.1" > /dev/pts/N`. Confirm correct line parsing including a deliberately-slow write (e.g., writing `"device-01,4"` then pausing then writing `"2.1\n"` from two separate `echo` calls) to prove the buffering handles split writes correctly.
2. Add a reconnect mechanism: if `connectionStateChanged(false)` fires due to `ResourceError`, start a `QTimer` (created appropriately, per Day 16 rules) that retries `serial->open()` every 3 seconds until it succeeds, emitting `connectionStateChanged(true)` again on success.
3. Deliberately feed malformed lines through your loopback (missing comma, non-numeric temperature, empty line) and confirm the GUI-thread parsing lambda ignores them cleanly without crashing — this is the same defensive-parsing discipline from Day 13's dropped-file handling, now applied to a continuous data stream instead of a one-time file read.

### Key Takeaways

- Serial (and any streaming) data must be buffered and split on a delimiter — never assume one `readyRead()` equals one complete message.
- `QSerialPort`, like `QTimer`, has thread affinity — create it in `start()`, not the worker's constructor.
- `errorOccurred` can fire with `NoError`; always check before treating it as a real error.
- `ResourceError` is the practical signal for "device was unplugged" — worth a dedicated handling path on embedded targets with removable USB-serial hardware.
- Keep the worker's job narrow (I/O + framing); do application-level parsing in the GUI-thread slot that receives the emitted line — this keeps the worker reusable and the parsing logic testable independently.
- The exact same thread lifecycle skeleton from Day 16 (`started → start()`, `finished → quit → deleteLater`) applies unchanged here and will apply again for MQTT next.

---

Say "next" for Day 18 (a short continuation day: reconnection strategy patterns and a config-driven multi-device serial manager — running several `SerialWorker`s concurrently for multiple connected embedded boards, since real deployments rarely have just one device).