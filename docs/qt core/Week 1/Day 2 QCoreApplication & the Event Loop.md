[[Foundations]]

This builds directly on Day 1: the event loop is how Qt actually _delivers_ things to QObjects — every timer firing, every signal dispatched via a queued connection, every bit of socket-ready notification arrives as an **event** posted to a QObject and picked up by the loop. Without QObject's meta-object system from Day 1, the loop would have nothing to dispatch to.

**Theory: what an "event" is, and why the loop exists**

In plain C++, your program's control flow is whatever you write in `main()`. Qt Core programs instead spend almost all their life inside `QCoreApplication::exec()`, which repeatedly:

1. Checks the platform's event queue (timers about to expire, sockets with data ready, POSIX signals converted into Qt events, etc.).
2. Wraps each occurrence in a `QEvent` (or subclass) object.
3. Calls `QCoreApplication::notify(receiver, event)`, which ultimately calls `receiver->event(event)` — a virtual method every `QObject` has (declared through the meta-object machinery from Day 1).
4. Returns to step 1, sleeping efficiently when there's nothing to do (it doesn't busy-loop burning CPU — the underlying OS mechanism, e.g. `select`/`epoll` on Linux, blocks until something is ready).

This is why blocking a slot (e.g. `sleep()`, a long synchronous computation) is so damaging: step 3 doesn't return until your handler returns, so step 4 never happens, so _nothing else_ — no other timer, no other socket — gets serviced until you're done. This is single-threaded cooperative multitasking, not preemptive; the loop cooperates with you, and you're expected to cooperate back by keeping handlers short.

`QCoreApplication` itself is the object that owns and runs this loop. You need exactly one instance per process, constructed before anything else that depends on events (timers, sockets, signal/slot **queued** delivery) is used.

**Resolved example 1 — proving the loop is the actual runtime, not a formality:**

```cpp
#include <QCoreApplication>
#include <QTimer>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    qDebug() << "Before exec() -- this runs immediately, outside the loop";

    // QTimer::singleShot is a static convenience function: it doesn't need
    // a QTimer object at all, it just posts a one-off callback after the delay.
    QTimer::singleShot(2000, [] {
        qDebug() << "Inside the loop -- fired after 2000ms";
        qApp->quit();   // qApp is a global macro expanding to the QCoreApplication
                         // instance pointer -- valid because we constructed one above.
    });

    qDebug() << "Calling exec() now -- program blocks here";
    int result = app.exec();   // <-- nothing below this line runs until quit() is called

    qDebug() << "exec() returned, result =" << result;
    return result;
}
```

**Output, in exact order:**

```
Before exec() -- this runs immediately, outside the loop
Calling exec() now -- program blocks here
Inside the loop -- fired after 2000ms
exec() returned, result = 0
```

Notice the _order_: the "Calling exec() now" line prints, then there's a genuine ~2 second pause, then the loop delivers the timer event, and only after `quit()` is called does control return to the line after `app.exec()`. This is the mental model to internalize: everything between `app.exec()` and its return is driven by events, not by your linear reading of the source file.

**Resolved example 2 — the failure mode: blocking the loop, and what it costs you**

```cpp
#include <QCoreApplication>
#include <QTimer>
#include <QDebug>
#include <QThread>   // for QThread::sleep -- deliberately misused here to show the failure

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QTimer heartbeat;
    int tick = 0;

    QObject::connect(&heartbeat, &QTimer::timeout, [&tick] {
        ++tick;
        qDebug() << "heartbeat tick" << tick << "at" << QTime::currentTime().toString("hh:mm:ss.zzz");

        if (tick == 3) {
            qDebug() << "Simulating a blocking operation for 4 seconds...";
            QThread::sleep(4);   // BAD: blocks the thread the event loop runs on
            qDebug() << "...blocking operation finished";
        }
    });
    heartbeat.start(1000);   // intended to tick once per second

    QTimer::singleShot(8000, [] { qApp->quit(); });

    return app.exec();
}
```

**What actually happens, resolved:**

```
heartbeat tick 1 at 10:00:01.002
heartbeat tick 2 at 10:00:02.004
heartbeat tick 3 at 10:00:03.005
Simulating a blocking operation for 4 seconds...
...blocking operation finished
heartbeat tick 4 at 10:00:07.010   <-- NOT at 10:00:04 as the 1-second interval would suggest
heartbeat tick 5 at 10:00:08.012
```

The explanation: `QThread::sleep(4)` blocks the **thread**, not just "that slot." Since this is a single-threaded program, the event loop itself is suspended for those 4 seconds — no timers can fire, because firing a timer requires the loop to be running to notice it expired and dispatch the event. Tick 4 doesn't happen "late" in the sense of being queued and delayed — Qt's timers are not guaranteed real-time and simply don't get evaluated at all while the loop is blocked; the next check after the sleep sees the timer is overdue and fires it immediately, then resumes normal 1-second spacing from there. This is the exact mechanism behind the vague real-world symptom "my Qt service occasionally stalls under load" — some slot somewhere is doing blocking work on the main thread. (The correct fix — moving that work to a worker thread — is Week 3 material; for now, the point is recognizing _why_ it happens.)

**Resolved example 3 — graceful shutdown on SIGINT/SIGTERM, the pattern you'll reuse in every long-running service from here on**

```cpp
#include <QCoreApplication>
#include <QTimer>
#include <QDebug>
#include <csignal>

// A raw OS signal (SIGINT/SIGTERM) arrives asynchronously and is NOT safe to
// handle with arbitrary Qt code directly inside the signal handler -- the
// handler can only safely do a few things, like setting a flag or calling
// quit(). QCoreApplication::quit() is documented safe to call this way:
// it just posts a QEvent::Quit to the event queue, which the loop picks up
// on its next iteration, so no Qt internals run inside the signal handler itself.

void handleTermination(int signum)
{
    qDebug() << "Received signal" << signum << "-- requesting clean shutdown";
    if (qApp) qApp->quit();
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    std::signal(SIGINT, handleTermination);
    std::signal(SIGTERM, handleTermination);

    QTimer heartbeat;
    QObject::connect(&heartbeat, &QTimer::timeout, [] {
        qDebug() << "service alive";
    });
    heartbeat.start(1000);

    qDebug() << "Service running. PID:" << QCoreApplication::applicationPid();

    int result = app.exec();

    qDebug() << "Clean shutdown complete, exit code" << result;
    return result;
}
```

Run this, send `SIGTERM` from another terminal (`kill -TERM <pid>`), and the resolution is: "service alive" lines stop, "Clean shutdown complete" prints, and the process exits with code 0 — as opposed to `kill -9`, which gives the process no chance to reach that line at all. This distinction (SIGTERM/SIGINT vs SIGKILL) matters directly for how you'll eventually run `mqtt_monitor`-style services under systemd, where the default stop mechanism sends SIGTERM and expects graceful exit within a timeout.

**Key takeaways:**

- `QCoreApplication::exec()` is not boilerplate — it _is_ the program's execution model from that line until `quit()`, driven entirely by dispatched events.
- Every timer firing, every queued-connection signal delivery, and every OS-level readiness notification is funneled through the same event-dispatch mechanism from Day 1's meta-object system (`QObject::event()`).
- Blocking any code running on the thread that owns the event loop — even inside "just one slot" — freezes every timer and every event on that thread, not just the slot you blocked. This is the root cause of most "my Qt service hangs intermittently" bugs in production.
- Signal handlers (`SIGINT`/`SIGTERM`) should do the absolute minimum — calling `quit()` is the documented-safe pattern — and this is the standard graceful-shutdown pattern for any long-running Qt Core service you deploy under systemd or similar.

Day 3 builds on this directly: signals & slots, now explained with the event loop as the actual delivery mechanism for **queued** connections (as opposed to **direct** connections, which bypass the loop and call the slot immediately, inline, on the emitting thread) — a distinction that only makes real sense now that you've seen what the loop actually does.