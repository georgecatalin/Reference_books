[[Advanced]]

**Theory: QProcess as a QIODevice, and why async-by-default matters**

Recall Day 8's unifying point: `QFile`, sockets, and `QProcess` all derive from `QIODevice`. `QProcess` specifically wraps an external program's stdin/stdout/stderr as three separate, readable/writable streams, and — critically — integrates with the event loop (Day 2) exactly like everything else this course has covered: starting a process is **non-blocking**, and you're notified of output, errors, and completion via signals (`readyReadStandardOutput()`, `errorOccurred()`, `finished()`), not by blocking synchronously.

This matters concretely: if `mqtt_monitor` ever needs to shell out to `mosquitto_pub` for a diagnostic publish, or run a system utility to query serial port status, doing so **synchronously** (`waitForFinished()` with no timeout, called carelessly) blocks the calling thread's event loop — exactly Day 2's warning, now applied to child processes instead of slow slots. The correct default is to start the process and let signals tell you when there's output or when it's done.

**Resolved example 1 — running a simple command asynchronously, reading output as it arrives**

```cpp
#include <QCoreApplication>
#include <QProcess>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    auto *process = new QProcess(&app);   // parented to app (Day 5) -- cleaned up automatically at exit

    QObject::connect(process, &QProcess::readyReadStandardOutput, [process]() {
        QByteArray output = process->readAllStandardOutput();
        qDebug() << "[stdout]" << output.trimmed();
    });

    QObject::connect(process, &QProcess::readyReadStandardError, [process]() {
        QByteArray output = process->readAllStandardError();
        qDebug() << "[stderr]" << output.trimmed();
    });

    QObject::connect(process, &QProcess::finished,
                      [&app](int exitCode, QProcess::ExitStatus exitStatus) {
        qDebug() << "Process finished, exit code:" << exitCode
                  << "status:" << (exitStatus == QProcess::NormalExit ? "NormalExit" : "CrashExit");
        app.quit();
    });

    QObject::connect(process, &QProcess::errorOccurred, [](QProcess::ProcessError error) {
        qWarning() << "Process error:" << error;
    });

    qDebug() << "Starting process...";
    process->start("ls", QStringList{"-la", "/tmp"});   // non-blocking: returns immediately

    qDebug() << "start() returned -- process runs concurrently with our event loop";

    return app.exec();
}
```

**Resolved output (illustrative — actual `/tmp` contents vary):**

```
Starting process...
start() returned -- process runs concurrently with our event loop
[stdout] "total 24\ndrwxrwxrwt 5 user user 4096 Jul 22 14:45 .\ndrwxr-xr-x 20 root root 4096 Jul 20 09:12 .."
Process finished, exit code: 0 status: "NormalExit"
```

Resolved point: `"start() returned"` prints **before** any output arrives — proof `start()` is genuinely asynchronous, exactly like every other Qt Core operation this course has covered. The actual output is delivered later, through `readyReadStandardOutput()`, dispatched via the event loop (Day 2) just like a `QTimer::timeout()` or a socket's data-ready notification.

**Resolved example 2 — writing to a process's stdin, and the correct error-handling path for a command that doesn't exist**

```cpp
#include <QCoreApplication>
#include <QProcess>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // Example: piping data INTO a process's stdin -- e.g. feeding a diagnostic
    // tool some input, or (in a real mqtt_monitor context) invoking mosquitto_pub
    // with a payload written to its stdin via the -l flag.
    auto *process = new QProcess(&app);

    QObject::connect(process, &QProcess::readyReadStandardOutput, [process]() {
        qDebug() << "[stdout]" << process->readAllStandardOutput().trimmed();
    });
    QObject::connect(process, &QProcess::finished, [&app](int code, QProcess::ExitStatus) {
        qDebug() << "First process done, code" << code;

        // Resolved: NOW start a second process, deliberately invalid, to show
        // correct error-path handling.
        auto *badProcess = new QProcess(&app);
        QObject::connect(badProcess, &QProcess::errorOccurred, [&app](QProcess::ProcessError error) {
            if (error == QProcess::FailedToStart) {
                qWarning() << "Command not found or not executable -- this is the expected, resolved path";
            }
            app.quit();
        });
        badProcess->start("this_command_definitely_does_not_exist_12345");
    });

    // "cat" with stdin piped in: echoes back whatever we write to it, then EOF closes it
    process->start("cat", QStringList());
    process->write("TEMP:23.5\nHUMIDITY:61\n");
    process->closeWriteChannel();   // signals EOF on stdin -- cat will now finish, since it has nothing more to read

    return app.exec();
}
```

**Resolved output:**

```
[stdout] "TEMP:23.5\nHUMIDITY:61"
First process done, code 0
Command not found or not executable -- this is the expected, resolved path
```

Resolved detail: `closeWriteChannel()` is what actually lets `cat` terminate — without it, `cat` would keep waiting on stdin indefinitely (it never sees EOF), and `finished()` would never fire, silently hanging your program. This is a real, easy-to-miss gotcha: **a QProcess piping to a program that reads until EOF will hang forever unless you explicitly close the write channel** once you're done sending input — directly analogous to needing to close a file handle to signal "no more writes coming."

Also resolved: `errorOccurred(QProcess::FailedToStart)` fires when the executable genuinely doesn't exist or isn't runnable — this is the correct signal to check rather than assuming `finished()` will eventually fire with some sentinel exit code; a process that fails to start never reaches `finished()` at all.

**Resolved example 3 — the one legitimate synchronous case: short-lived, bounded-wait diagnostic call, done safely with a timeout**

Sometimes a script genuinely needs a quick, one-off synchronous result — e.g., a startup diagnostic check before the event loop is even running. Resolved: this is acceptable **only** with an explicit, bounded timeout, never an unbounded wait.

```cpp
#include <QCoreApplication>
#include <QProcess>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QProcess process;
    process.start("mosquitto", QStringList{"-h"});   // just checking the broker binary exists and runs

    // Resolved: waitForStarted/waitForFinished WITH an explicit millisecond
    // timeout is acceptable for a brief, one-off startup check -- NOT as a
    // general pattern once the event loop is running and other things
    // (timers, other I/O) need to stay responsive.
    if (!process.waitForStarted(2000)) {
        qWarning() << "mosquitto binary not found or failed to start within 2s -- check installation";
        return 1;
    }

    if (!process.waitForFinished(3000)) {
        qWarning() << "mosquitto -h did not exit within 3s -- killing it";
        process.kill();
        return 1;
    }

    qDebug() << "mosquitto binary check passed, exit code:" << process.exitCode();
    qDebug() << process.readAllStandardOutput();

    return 0;   // no app.exec() needed here -- this was a one-shot startup check, not a running service
}
```

**Resolved output (assuming mosquitto is installed):**

```
mosquitto binary check passed, exit code: 0
"mosquitto version 2.0.18\n"
```

Resolved rationale: this pattern is fine specifically because (1) it's a bounded wait with an explicit timeout — it can never hang forever, unlike Example 2's near-miss; (2) it happens **before** `app.exec()` — no event loop is yet running for this call to block, so there's nothing else on this thread being starved; (3) it's a one-off startup check, not something repeated in a running service's hot path. If this exact code ran _inside_ a slot after `app.exec()` had already started, it would reintroduce Day 2's event-loop-blocking problem — the async pattern from Examples 1–2 is still the correct default for anything happening during normal operation.

**Key takeaways:**

- `QProcess` is a `QIODevice` (Day 8) wrapping stdin/stdout/stderr — starting a process is non-blocking by default, with results delivered via signals through the event loop (Day 2), exactly like every other async Qt Core mechanism covered so far.
- `readyReadStandardOutput()`/`readyReadStandardError()` for incoming data, `finished(exitCode, exitStatus)` for completion, `errorOccurred()` for failures including "executable doesn't exist" (which never reaches `finished()` at all) — check all three, don't assume only the happy path.
- Writing to a process's stdin and expecting it to terminate on its own requires `closeWriteChannel()` to signal EOF — omitting this is a common, silent hang, not a crash, making it harder to notice in testing.
- `waitForStarted()`/`waitForFinished()` with an **explicit timeout** are acceptable only for brief, one-off checks that happen before the event loop is running or with clear justification — never as the general pattern once your service is live, for the same reason Day 2 warned against blocking slots.

Day 20 covers `QFileSystemWatcher` — reacting live to file/config changes (e.g., an operator editing `mqtt_monitor.ini` from Day 9 while the service is running, and picking up the change without a restart), closing out the individual-topic material before Day 21's multi-threaded mini-project.