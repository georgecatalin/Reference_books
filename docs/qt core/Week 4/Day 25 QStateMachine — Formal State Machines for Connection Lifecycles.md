[[Networking]]


**Theory: why a formal state machine beats an enum + switch statement**

A hand-rolled `enum class ConnectionState { Disconnected, Connecting, Connected, Reconnecting }` with a `switch` in every event handler works at first, but degrades badly as complexity grows: every event handler must independently know which transitions are valid from which states, duplicate guard logic proliferates, and it's easy to silently allow an invalid transition (e.g., handling a "data received" event while `Disconnected`) simply because nobody wrote a guard against it in that one specific handler. `QStateMachine` (part of Qt Core, not a GUI-only tool) makes states and transitions **first-class, declared objects** — invalid transitions are structurally impossible rather than "bugs waiting to happen if someone forgets a check."

**Core building blocks, resolved:**

- **`QState`** — represents one state. You add behavior via `entered()`/`exited()` signals (fired automatically on transition).
- **`QAbstractTransition`** subclasses (commonly `QSignalTransition` — "move to this state when this specific signal fires") — added to a source state, targeting a destination state.
- **`QStateMachine`** — owns the whole graph, has one active state (or, for hierarchical/parallel machines, a set), starts with `start()`.
- **Guarded transitions** — a `QSignalTransition` can have a guard condition (via a subclass overriding `eventTest()`) so the same signal only triggers the transition under specific conditions — e.g., "only transition to Reconnecting if retry count is under the max."

**Resolved example 1 — a device connection lifecycle, modeled formally**

```cpp
// deviceconnection.h
#pragma once
#include <QObject>
#include <QStateMachine>
#include <QState>
#include <QFinalState>
#include <QTimer>
#include <QDebug>

class DeviceConnection : public QObject
{
    Q_OBJECT
public:
    explicit DeviceConnection(QObject *parent = nullptr) : QObject(parent)
    {
        setupStates();
    }

    void start() { m_machine.start(); }

public slots:
    // These represent real events -- e.g. wired to actual QTcpSocket signals
    // (Day 22) in a real deployment; here triggered manually to demonstrate
    // the state machine's behavior in isolation.
    void requestConnect() { emit connectRequested(); }
    void simulateConnectionSuccess() { emit connectionEstablished(); }
    void simulateConnectionFailure() { emit connectionFailed(); }
    void simulateUnexpectedDisconnect() { emit disconnectedUnexpectedly(); }

signals:
    void connectRequested();
    void connectionEstablished();
    void connectionFailed();
    void disconnectedUnexpectedly();

private:
    void setupStates()
    {
        m_disconnected = new QState(&m_machine);
        m_connecting = new QState(&m_machine);
        m_connected = new QState(&m_machine);
        m_reconnecting = new QState(&m_machine);

        // entered()/exited() -- fired automatically by the machine itself,
        // no manual bookkeeping needed to know when a state becomes active.
        connect(m_disconnected, &QState::entered, [] { qDebug() << "[STATE] -> Disconnected"; });
        connect(m_connecting,   &QState::entered, [] { qDebug() << "[STATE] -> Connecting"; });
        connect(m_connected,    &QState::entered, [] { qDebug() << "[STATE] -> Connected"; m_retryCount = 0; });
        connect(m_reconnecting, &QState::entered, [this] {
            ++m_retryCount;
            qDebug() << "[STATE] -> Reconnecting (attempt" << m_retryCount << ")";
        });

        // Transitions: addTransition(signal, targetState) -- declarative,
        // and ONLY valid from the state they're added to. A signal fired
        // while a DIFFERENT state is active simply has no effect -- this is
        // the structural safety a hand-rolled switch statement doesn't give you.
        m_disconnected->addTransition(this, &DeviceConnection::connectRequested, m_connecting);
        m_connecting->addTransition(this, &DeviceConnection::connectionEstablished, m_connected);
        m_connecting->addTransition(this, &DeviceConnection::connectionFailed, m_reconnecting);
        m_connected->addTransition(this, &DeviceConnection::disconnectedUnexpectedly, m_reconnecting);

        // Reconnecting has an automatic timed retry, and a max-retry guard --
        // resolved using a plain QTimer (Day 4) fired on entry to the state.
        connect(m_reconnecting, &QState::entered, [this]() {
            if (m_retryCount > 3) {
                qDebug() << "[STATE] max retries exceeded -- staying in Reconnecting, giving up automatic retry";
                return;
            }
            QTimer::singleShot(1000, this, &DeviceConnection::requestRetry);
        });
        connect(this, &DeviceConnection::retryRequested, [this]() {
            emit connectRequested();   // loop back through Connecting again
        });
        m_reconnecting->addTransition(this, &DeviceConnection::retryRequested, m_connecting);

        m_machine.setInitialState(m_disconnected);
    }

signals:
    void retryRequested();

private slots:
    void requestRetry() { emit retryRequested(); }

private:
    QStateMachine m_machine;
    QState *m_disconnected;
    QState *m_connecting;
    QState *m_connected;
    QState *m_reconnecting;
    int m_retryCount = 0;
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include <QTimer>
#include <QDebug>
#include "deviceconnection.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    DeviceConnection device;
    device.start();

    // Scripted sequence of events to walk through the whole lifecycle
    QTimer::singleShot(200,  &device, &DeviceConnection::requestConnect);
    QTimer::singleShot(500,  &device, &DeviceConnection::simulateConnectionFailure);   // -> Reconnecting
    // Reconnecting auto-retries after 1000ms internally -> back to Connecting
    QTimer::singleShot(2000, &device, &DeviceConnection::simulateConnectionSuccess);   // -> Connected
    QTimer::singleShot(3000, &device, &DeviceConnection::simulateUnexpectedDisconnect); // -> Reconnecting again

    // Resolved: this event is fired while in the WRONG state on purpose --
    // proves invalid transitions are structurally ignored, not a crash or a bug.
    QTimer::singleShot(3100, &device, &DeviceConnection::simulateConnectionSuccess);
    // At t=3100 we're in Reconnecting (just entered at t=3000), not Connecting --
    // connectionEstablished() has NO transition registered from Reconnecting,
    // so this signal simply does nothing. This is the resolved payoff: the
    // "invalid event in current state" case requires ZERO defensive code from us.

    QTimer::singleShot(5000, &app, &QCoreApplication::quit);
    return app.exec();
}
```

**Resolved output:**

```
[STATE] -> Disconnected
[STATE] -> Connecting
[STATE] -> Reconnecting (attempt 1)
[STATE] -> Connecting
[STATE] -> Connected
[STATE] -> Reconnecting (attempt 2)
```

Resolved point, stated explicitly: the `simulateConnectionSuccess()` call fired at t=3100ms — while the machine was in `Reconnecting`, not `Connecting` — produced **no output, no crash, no incorrect state change.** `connectionEstablished()` has no transition registered from `m_reconnecting`, so the signal simply had no effect on the machine. This is the exact structural guarantee a hand-rolled `switch` statement doesn't give you for free: you'd have needed to remember to add an explicit "ignore this event unless we're in the right state" check in every handler, whereas here it's true by construction — the transition graph only contains the edges you declared.

**Resolved example 2 — a guarded transition using a custom QSignalTransition subclass (conditional routing based on data, not just "did the signal fire")**

```cpp
#include <QSignalTransition>
#include <QDebug>

// Resolved pattern: a transition that only fires if a condition holds --
// e.g. only transition to a "Degraded" state if the reported error is
// specifically a timeout, not any other kind of error.
class TimeoutOnlyTransition : public QSignalTransition
{
public:
    TimeoutOnlyTransition(QObject *sender, const char *signal)
        : QSignalTransition(sender, signal) {}

protected:
    bool eventTest(QEvent *event) override
    {
        if (!QSignalTransition::eventTest(event))   // first, the base check: did the right signal fire at all?
            return false;

        // Resolved: inspect the actual signal arguments carried by this event
        // to decide whether THIS specific occurrence should trigger the transition.
        auto *signalEvent = static_cast<QStateMachine::SignalEvent*>(event);
        QString errorType = signalEvent->arguments().at(0).toString();
        return errorType == "timeout";   // only timeouts route through this transition;
                                          // other error types are left for a different
                                          // transition (or none) to handle
    }
};
```

This is the resolved mechanism for "the same signal should sometimes cause a transition and sometimes not, depending on the data it carries" — rather than emitting different signals for every possible condition (which gets unwieldy fast), you inspect the actual event/argument data inside `eventTest()` and let the transition itself decide whether it applies.

**Key takeaways:**

- `QStateMachine` makes valid transitions explicit, declared edges in a graph — an event that has no registered transition from the current state is simply a no-op, with zero defensive code required from you, unlike a hand-rolled switch where every handler must independently guard against being called in the wrong state.
- `entered()`/`exited()` signals on `QState` are the natural place for state-specific setup/teardown logic (like resetting `m_retryCount` on entering `Connected`) — this centralizes what used to be scattered "did we just transition into X" checks.
- Combining `QState::entered` with a `QTimer::singleShot` (Day 4) is the resolved pattern for automatic, timed transitions (like an auto-retry after a delay) — the state machine and the event loop's timer mechanism compose cleanly, exactly as you'd expect given everything since Day 2.
- Custom `QSignalTransition` subclasses overriding `eventTest()` let you route the _same_ signal to different transitions based on its actual argument data — useful when distinguishing "this connection failure was a timeout" from "this connection failure was a refused connection" without needing a separate signal for every distinct failure reason.
- This exact lifecycle shape — Disconnected/Connecting/Connected/Reconnecting with bounded auto-retry — is directly the right model for `mqtt_monitor`'s MQTT broker connection or serial device connection state, replacing whatever enum-and-switch logic such reconnection handling might currently use.

Day 26 covers event filters and custom `QEvent` subclasses — a lower-level mechanism than signals/slots, useful when you need to intercept or observe events at another object's level (e.g., globally logging all events passing through a particular QObject) rather than just reacting to its published signals.