[[Networking]]

**Theory: events versus signals — a distinction worth being precise about**

Every prior mechanism in this course — timers, sockets, processes — ultimately delivers its notifications by posting a `QEvent` to a `QObject`'s `event()` method (Day 2's foundational mechanic), and _most_ built-in Qt classes then translate that event into a more convenient signal for you (`QTimer` turns a timer event into `timeout()`, for instance). But the underlying event layer is itself accessible directly, and useful when:

1. You need to **observe or intercept events destined for an object you don't own** — you can't just add a new signal to a class you didn't write, but you _can_ install an **event filter** on any `QObject` and see its events before it does.
2. You want to define your **own custom event type**, carrying arbitrary data, and post it to be delivered asynchronously through the event loop (Day 2) — an alternative to a signal when you specifically want event-loop-queued delivery semantics without the sender/receiver needing a compile-time-known signal/slot connection.

**Resolved example 1 — an event filter, globally observing events on an object you don't control**

```cpp
#include <QCoreApplication>
#include <QObject>
#include <QTimerEvent>
#include <QDebug>
#include <QBasicTimer>

// Imagine this is a third-party or legacy class you can't modify --
// it starts an internal timer using the LOW-LEVEL QBasicTimer/timerEvent()
// mechanism instead of QTimer+signals, which some real Qt-adjacent code does
// for efficiency when many timers are needed without QTimer's per-instance overhead.
class LegacyPoller : public QObject
{
    Q_OBJECT
public:
    LegacyPoller(QObject *parent = nullptr) : QObject(parent)
    {
        m_timerId = startTimer(500);   // low-level: no QTimer object, no timeout() signal at all
    }

protected:
    void timerEvent(QTimerEvent *event) override
    {
        if (event->timerId() == m_timerId) {
            // does its own internal work here -- NO signal is emitted, by design;
            // this class simply wasn't written to notify anyone externally.
            static int count = 0;
            ++count;
        }
    }

private:
    int m_timerId;
};

// Resolved: an event filter installed on LegacyPoller lets us observe its
// timer events from OUTSIDE, without modifying LegacyPoller's source at all.
class ExternalObserver : public QObject
{
    Q_OBJECT
public:
    bool eventFilter(QObject *watched, QEvent *event) override
    {
        if (event->type() == QEvent::Timer) {
            qDebug() << "[FILTER] observed a QEvent::Timer on" << watched;
            // Resolved: returning false lets the event continue to its normal
            // destination (LegacyPoller::timerEvent) -- we're OBSERVING, not
            // consuming. Returning true would swallow the event entirely,
            // preventing LegacyPoller from ever seeing it -- rarely what you want
            // for a passive observer, but occasionally correct (see Example 2).
        }
        return false;
    }
};

#include "main.moc"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    auto *poller = new LegacyPoller(&app);
    auto *observer = new ExternalObserver();

    poller->installEventFilter(observer);   // now 'observer' sees every event bound for 'poller' first

    QTimer::singleShot(1800, &app, &QCoreApplication::quit);
    return app.exec();
}
```

**Resolved output:**

```
[FILTER] observed a QEvent::Timer on LegacyPoller(0x...)
[FILTER] observed a QEvent::Timer on LegacyPoller(0x...)
[FILTER] observed a QEvent::Timer on LegacyPoller(0x...)
```

Resolved point: `eventFilter()` runs **before** `LegacyPoller::timerEvent()` gets the event — this is Qt's actual event dispatch order (`QCoreApplication::notify()`, from Day 2's dispatch mechanism, checks installed filters first, then calls the target's own `event()`). This lets you observe, log, or (via returning `true`) suppress events on an object without touching that object's own class definition at all — genuinely useful for legacy code you can't modify, or for cross-cutting concerns like a global input/event logger.

**Resolved example 2 — a custom QEvent subclass, posted asynchronously across threads without a formal signal/slot connection**

```cpp
#include <QCoreApplication>
#include <QEvent>
#include <QCoreApplication>
#include <QThread>
#include <QDebug>

// Resolved: a custom event carrying arbitrary application data.
// QEvent::registerEventType() reserves a unique type ID at runtime,
// avoiding collisions with Qt's own built-in event type numbers.
class SensorAlertEvent : public QEvent
{
public:
    static const QEvent::Type TypeId;

    explicit SensorAlertEvent(const QString &deviceId, double temperature)
        : QEvent(TypeId), m_deviceId(deviceId), m_temperature(temperature) {}

    QString deviceId() const { return m_deviceId; }
    double temperature() const { return m_temperature; }

private:
    QString m_deviceId;
    double m_temperature;
};

const QEvent::Type SensorAlertEvent::TypeId =
    static_cast<QEvent::Type>(QEvent::registerEventType());

class AlertHandler : public QObject
{
    Q_OBJECT
protected:
    bool event(QEvent *e) override
    {
        if (e->type() == SensorAlertEvent::TypeId) {
            auto *alertEvent = static_cast<SensorAlertEvent*>(e);
            qDebug() << "Handling alert for" << alertEvent->deviceId()
                      << "temp:" << alertEvent->temperature()
                      << "on thread:" << QThread::currentThread();
            return true;   // resolved: return true to indicate WE handled this event type;
                            // false would mean "pass it up to QObject::event() for default handling"
        }
        return QObject::event(e);   // resolved: always delegate anything we don't recognize
                                      // to the base implementation -- never just swallow unknown events
    }
};

#include "main.moc"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    auto *handler = new AlertHandler();

    qDebug() << "Posting event from thread:" << QThread::currentThread();

    // QCoreApplication::postEvent() queues the event for asynchronous delivery
    // through the event loop -- similar in spirit to a queued signal (Day 3),
    // but without needing any signal/slot connection declared at all; useful
    // when the sender genuinely doesn't know or care about a specific receiver
    // class's signal signature, just that it accepts SensorAlertEvent.
    QCoreApplication::postEvent(handler, new SensorAlertEvent("sensor-07", 31.2));

    QTimer::singleShot(100, [&app, handler]() {
        delete handler;
        app.quit();
    });

    return app.exec();
}
```

**Resolved output:**

```
Posting event from thread: QThread(0x_main)
Handling alert for "sensor-07" temp: 31.2 on thread: QThread(0x_main)
```

Resolved detail: `postEvent()` takes ownership of the event object you pass it (note we didn't delete `new SensorAlertEvent(...)` ourselves) — Qt deletes it automatically once dispatch completes, similar in spirit to `QRunnable`'s default auto-delete behavior from Day 18. Also resolved: `QCoreApplication::postEvent()` is explicitly documented as thread-safe — you can call it from any thread to deliver an event to an object living on a different thread, making this a legitimate (if less common than signals/slots) alternative to Day 15/16's cross-thread communication pattern, useful specifically when you want to post to an object without a pre-declared signal/slot connection.

**Key takeaways:**

- Every notification mechanism in this course (timers, sockets, processes) is built on the same underlying `QEvent`/`event()` dispatch from Day 2 — signals are a convenience layer most Qt classes build on top of it, not a separate mechanism.
- `installEventFilter()` lets you observe (and optionally suppress, by returning `true`) events destined for an object you don't own or can't modify — the correct tool for cross-cutting concerns (logging, legacy-code observation) that signals/slots can't address since you can't add a signal to someone else's class.
- Custom `QEvent` subclasses, given a unique type via `QEvent::registerEventType()`, let you define arbitrary application-specific asynchronous notifications delivered through the standard event loop — `postEvent()` is thread-safe and takes ownership of the event, deleting it automatically after dispatch.
- Always delegate unrecognized event types to `QObject::event(e)` in an overridden `event()` method — silently swallowing event types you don't explicitly handle breaks the object's normal built-in behavior (e.g., you'd break its ability to still process timer events, close events, etc., if you forgot this fallback).

Day 27 covers `QLoggingCategory` and idiomatic Qt error handling more broadly — structured, filterable logging categories (rather than plain `qDebug()` everywhere) and the deliberate Qt convention of return-value/error-string-based error signaling over C++ exceptions, and why that convention exists throughout everything you've used since Day 1.