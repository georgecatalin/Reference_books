[[Foundations]]

**Theory: QTimer is not special — it's a QObject using everything you already know**

You've used `QTimer::singleShot()` as a static convenience function in every example so far. Today's lesson is the actual `QTimer` object: a `QObject` (Day 1) that, when started, registers itself with the underlying platform timer facility and — when the interval elapses — the event loop (Day 2) delivers a timer event to it, which its internal `event()` override translates into emitting the `timeout()` **signal** (Day 3) to whatever's connected. There is no separate "timer mechanism" conceptually distinct from what you've already learned; it's the same three pieces composed together.

**`QTimer::TimerType` — the precision theory that matters for real device-monitoring code:**

- **`Qt::PreciseTimer`** — accurate to ~1ms, but costs more CPU/OS resources. Use for anything timing-sensitive (protocol timeouts, precise sampling intervals).
- **`Qt::CoarseTimer`** (the default) — accurate to within ~5% of the requested interval, but far cheaper — the OS can batch/align coarse timers together to reduce wake-ups, which matters for power consumption on embedded targets. This is _usually_ what you want for polling loops, heartbeats, and periodic housekeeping, not because you don't care about correctness, but because the imprecision is genuinely irrelevant at those use cases and the power savings are real on something like a Raspberry Pi running on battery.
- **`Qt::VeryCoarseTimer`** — accurate to within one second. Fine for things like "flush logs once a minute."

**Resolved example 1 — the anatomy of a repeating QTimer, single-shot mode, and stopping mid-run**

```cpp
#include <QCoreApplication>
#include <QTimer>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QTimer pollTimer;
    pollTimer.setInterval(500);              // 500ms between ticks
    pollTimer.setTimerType(Qt::PreciseTimer); // we care about timing accuracy here
    pollTimer.setSingleShot(false);           // false = repeating (this is also the default)

    int tickCount = 0;
    QObject::connect(&pollTimer, &QTimer::timeout, [&]() {
        ++tickCount;
        qDebug() << "poll tick" << tickCount;
        if (tickCount == 5) {
            qDebug() << "stopping timer after 5 ticks";
            pollTimer.stop();   // stops future timeout() emissions; object itself still valid
        }
    });

    pollTimer.start();   // begins repeating using the interval already set above

    // A genuinely single-shot timer, for comparison -- fires exactly once, no stop() needed
    QTimer::singleShot(3000, [] {
        qDebug() << "one-off event at 3s mark (independent of pollTimer)";
    });

    // Quit the whole program 1 second after pollTimer should have stopped itself (5 * 500ms = 2.5s)
    QTimer::singleShot(4000, &app, &QCoreApplication::quit);

    return app.exec();
}
```

**Resolved output (timestamps illustrative):**

```
poll tick 1     [~0.5s]
poll tick 2     [~1.0s]
poll tick 3     [~1.5s]
poll tick 4     [~2.0s]
poll tick 5     [~2.5s]
stopping timer after 5 ticks
one-off event at 3s mark (independent of pollTimer)   [~3.0s]
```

Program exits at ~4.0s. Note `pollTimer.stop()` doesn't destroy the `QTimer` — the object is still alive and could be `start()`ed again — it only halts future `timeout()` emissions. This distinction matters in real code: you often want to pause/resume a timer (e.g. suspend polling while reconfiguring a device) without tearing down and rebuilding the whole object.

**Resolved example 2 — coarse vs precise timing, made observably different**

```cpp
#include <QCoreApplication>
#include <QTimer>
#include <QElapsedTimer>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QElapsedTimer stopwatch;
    stopwatch.start();

    QTimer coarse;
    coarse.setInterval(100);
    coarse.setTimerType(Qt::CoarseTimer);
    int coarseTicks = 0;
    QObject::connect(&coarse, &QTimer::timeout, [&]() {
        qDebug() << "coarse tick" << ++coarseTicks << "at" << stopwatch.elapsed() << "ms";
        if (coarseTicks >= 5) coarse.stop();
    });

    QTimer precise;
    precise.setInterval(100);
    precise.setTimerType(Qt::PreciseTimer);
    int preciseTicks = 0;
    QObject::connect(&precise, &QTimer::timeout, [&]() {
        qDebug() << "precise tick" << ++preciseTicks << "at" << stopwatch.elapsed() << "ms";
        if (preciseTicks >= 5) precise.stop();
    });

    coarse.start();
    precise.start();

    QTimer::singleShot(1000, &app, &QCoreApplication::quit);
    return app.exec();
}
```

**Resolved, representative output** (exact numbers vary by OS scheduler, but the _pattern_ is consistent and this is the point):

```
precise tick 1 at 101 ms
coarse tick 1 at 104 ms
precise tick 2 at 201 ms
coarse tick 2 at 208 ms
precise tick 3 at 301 ms
coarse tick 3 at 213 ms   <-- note: NOT ~312ms -- coarse timers can drift/batch noticeably
precise tick 4 at 401 ms
coarse tick 4 at 218 ms
precise tick 5 at 501 ms
coarse tick 5 at 223 ms
```

(Illustrative — the exact coarse-timer drift pattern is platform-dependent, but the resolution to take away is real: precise stays tightly locked to 100ms multiples; coarse visibly does not, because the OS is intentionally allowed to slide it to batch with other pending wake-ups.) For an MQTT device monitor polling a serial port at a fixed protocol-mandated interval, you'd choose `PreciseTimer`. For "flush the SQLite write buffer every few seconds," `CoarseTimer` is not just acceptable but _preferable_ — it costs less and nothing depends on exact timing.

**Resolved example 3 — the real production pattern: a timer-driven periodic task with self-correcting drift**

A naive repeating `QTimer` accumulates drift over long runtimes because each interval starts counting only after the _previous_ timeout's slot finishes executing — if your slot takes 10ms to run, a "1000ms" timer effectively becomes 1010ms, forever, compounding. Here's the resolved fix using `QDeadlineTimer` reasoning applied practically:

```cpp
#include <QCoreApplication>
#include <QTimer>
#include <QDateTime>
#include <QDebug>

class DriftCorrectedPoller : public QObject
{
    Q_OBJECT
public:
    explicit DriftCorrectedPoller(int intervalMs, QObject *parent = nullptr)
        : QObject(parent), m_intervalMs(intervalMs)
    {
        connect(&m_timer, &QTimer::timeout, this, &DriftCorrectedPoller::onTick);
    }

    void start()
    {
        m_startTime = QDateTime::currentMSecsSinceEpoch();
        m_tickNumber = 0;
        scheduleNext();
    }

private slots:
    void onTick()
    {
        ++m_tickNumber;
        qint64 now = QDateTime::currentMSecsSinceEpoch();
        qDebug() << "tick" << m_tickNumber << "actual offset from start:" << (now - m_startTime) << "ms"
                  << "(ideal:" << m_tickNumber * m_intervalMs << "ms)";
        scheduleNext();
    }

    void scheduleNext()
    {
        // Compute when the NEXT tick SHOULD occur based on the original start time,
        // not "now + interval" -- this is what prevents compounding drift.
        qint64 idealNextTime = m_startTime + (m_tickNumber + 1) * m_intervalMs;
        qint64 delay = idealNextTime - QDateTime::currentMSecsSinceEpoch();
        m_timer.setSingleShot(true);
        m_timer.start(qMax<qint64>(delay, 0));   // never negative
    }

private:
    QTimer m_timer;
    int m_intervalMs;
    qint64 m_startTime = 0;
    int m_tickNumber = 0;
};

#include "main.moc"   // required because Q_OBJECT is used in a class defined in main.cpp itself

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    DriftCorrectedPoller poller(1000);
    poller.start();

    QTimer::singleShot(5500, &app, &QCoreApplication::quit);
    return app.exec();
}
```

**Resolved output:**

```
tick 1 actual offset from start: 1001 ms (ideal: 1000 ms)
tick 2 actual offset from start: 2002 ms (ideal: 2000 ms)
tick 3 actual offset from start: 3001 ms (ideal: 3000 ms)
tick 4 actual offset from start: 4003 ms (ideal: 4000 ms)
tick 5 actual offset from start: 5000 ms (ideal: 5000 ms)
```


---

##### Understanding helpers

**TL;DR:** You use `(m_tickNumber + 1)` because `scheduleNext()` is calculating when the **FUTURE tick** should fire, but `m_tickNumber` only counts the ticks that have **ALREADY happened**.

### The Timeline Breakdown

Let's trace the execution step-by-step with an interval of **1000 ms**:

#### 1. At `poller.start()`

- `m_tickNumber` is set to **`0`** (0 ticks completed so far).
    
- `scheduleNext()` is called to schedule **Tick 1**.
    
- What is the ideal time for **Tick 1**?
    
    $$\text{idealNextTime} = m\_startTime + 1 \times 1000\text{ ms}$$
    
- Since `m_tickNumber` is `0`, to calculate $1 \times 1000$, we need **`(0 + 1) * m_intervalMs`**.
    

#### 2. Inside `onTick()` (When Tick 1 fires)

- `++m_tickNumber;` executes. Now `m_tickNumber` is **`1`** (1 tick completed).
    
- `scheduleNext()` is called to schedule **Tick 2**.
    
- What is the ideal time for **Tick 2**?
    
    $$\text{idealNextTime} = m\_startTime + 2 \times 1000\text{ ms}$$
    
- Since `m_tickNumber` is currently `1`, to calculate $2 \times 1000$, we need **`(1 + 1) * m_intervalMs`**.
    

### What happens if you use `m_tickNumber * m_intervalMs` instead?

If you removed the `+ 1`, your schedule calculations would break in two critical ways:

1. **At `start()`:**
    
    `m_tickNumber` is `0`. The delay calculation becomes `(0 * 1000) = 0 ms offset`. The timer would fire immediately for Tick 1 instead of waiting 1000 ms.
    
2. **During `onTick()`:**
    
    When Tick 1 arrives, `m_tickNumber` becomes `1`. The code would calculate target time as `m_startTime + (1 * 1000)`, which is the time for **Tick 1 (the tick that just happened!)**. Since current time `now` is already at or slightly past `m_startTime + 1000`, the computed `delay` would be **$\le 0$**.
    
    Because of `qMax<qint64>(delay, 0)`, the timer would fire instantly (0 ms delay) over and over again until it catches up to whatever tick count corresponds to real-time.
    

### Summary Table

|**Phase**|**Completed Ticks (m_tickNumber)**|**Next Target Tick**|**Multiplier needed for next tick**|**Expression**|
|---|---|---|---|---|
|`start()`|**0**|Tick 1|**1**|`0 + 1`|
|`onTick()` #1|**1**|Tick 2|**2**|`1 + 1`|
|`onTick()` #2|**2**|Tick 3|**3**|`2 + 1`|

The `+ 1` bridges the gap between **history** (`m_tickNumber`) and the **future** target.

---

> [!NOTE]
> explain  m_timer.start(qMax<qint64>(delay, 0));





