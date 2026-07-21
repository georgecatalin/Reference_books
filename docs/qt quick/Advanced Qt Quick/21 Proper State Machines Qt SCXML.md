[[Advanced Qt Quick]]

# Day 21 — Proper State Machines: Qt SCXML / `QStateMachine` for Complex Lifecycles

Day 7 used QML's `states:`/`state:` property for simple 2–3 mode UI (online/offline, normal/warning/critical). That's the right tool for _visual_ state. Today is different: a genuine **connection/device lifecycle** — connecting → connected → reconnecting → failed → disconnected, with retries, timeouts, and guards — has real transition logic that outgrows a `state:` ternary. This is where Qt's actual state machine framework belongs.

## Concept: Why `states:`/`state:` isn't the right tool here

Day 7's approach works when state is _derived_ from existing properties (`state: connected ? "online" : "offline"`) — it's declarative and reactive by nature. But a connection lifecycle has state that **isn't simply derivable from other properties** — it has its own transition rules, timeouts, and history (e.g., "if reconnect attempt #3 fails, go to a permanent failed state, not back to reconnecting"). Trying to force this into `state:` ternaries produces a tangle of boolean flags (`isConnecting`, `hasFailedTwice`, `isRetrying`) that's exactly the kind of ad-hoc state-as-booleans mess a real state machine exists to eliminate — the same reasoning that would make you reach for an actual state machine or enum-driven design in embedded C rather than a pile of flags.

## Concept: `QStateMachine` (C++) — the more common real-world choice for app logic

Qt offers two state machine approaches: **Qt SCXML** (XML-based, standard SCXML format, good if you need portability/tooling or the state chart is designed by non-programmers) and **`QStateMachine`** (pure C++, `QState`/`QFinalState`, built on Qt's own signal/event system). For a connection lifecycle owned entirely by your own code, `QStateMachine` is usually the more pragmatic choice — no XML file, no extra build step, and it composes naturally with the signals you're already emitting from `MqttManager`.

```cpp
// connectionstatemachine.h
#pragma once
#include <QObject>
#include <QStateMachine>
#include <QState>
#include <QTimer>

class ConnectionStateMachine : public QObject
{
    Q_OBJECT
    QML_ELEMENT
    Q_PROPERTY(QString currentState READ currentState NOTIFY currentStateChanged)

public:
    explicit ConnectionStateMachine(QObject *parent = nullptr);

    QString currentState() const { return m_currentState; }

    Q_INVOKABLE void requestConnect();
    Q_INVOKABLE void requestDisconnect();

    // Called from MqttManager's actual client signals
    void notifyConnected();
    void notifyConnectionFailed();
    void notifyDisconnected();

signals:
    void currentStateChanged();
    void connectRequested();
    void disconnectRequested();
    void connectionEstablished();
    void connectionLost();
    void retryExhausted();

private:
    QStateMachine m_machine;
    QState *m_disconnectedState;
    QState *m_connectingState;
    QState *m_connectedState;
    QState *m_reconnectingState;
    QState *m_failedState;
    QTimer m_retryTimer;
    int m_retryCount = 0;
    static constexpr int kMaxRetries = 5;

    QString m_currentState = "disconnected";
    void setCurrentState(const QString &name);
};
```

```cpp
// connectionstatemachine.cpp
ConnectionStateMachine::ConnectionStateMachine(QObject *parent) : QObject(parent)
{
    m_disconnectedState = new QState(&m_machine);
    m_connectingState   = new QState(&m_machine);
    m_connectedState    = new QState(&m_machine);
    m_reconnectingState = new QState(&m_machine);
    m_failedState       = new QState(&m_machine);

    // ---- Transitions: each addTransition connects a SIGNAL to a target state ----
    m_disconnectedState->addTransition(this, &ConnectionStateMachine::connectRequested, m_connectingState);
    m_connectingState->addTransition(this, &ConnectionStateMachine::connectionEstablished, m_connectedState);
    m_connectingState->addTransition(this, &ConnectionStateMachine::connectionLost, m_reconnectingState);
    m_connectedState->addTransition(this, &ConnectionStateMachine::connectionLost, m_reconnectingState);
    m_reconnectingState->addTransition(this, &ConnectionStateMachine::connectionEstablished, m_connectedState);
    m_reconnectingState->addTransition(this, &ConnectionStateMachine::retryExhausted, m_failedState);
    m_failedState->addTransition(this, &ConnectionStateMachine::connectRequested, m_connectingState);

    // ---- Entry/exit actions per state ----
    connect(m_connectingState, &QState::entered, this, [this]() {
        setCurrentState("connecting");
    });
    connect(m_connectedState, &QState::entered, this, [this]() {
        setCurrentState("connected");
        m_retryCount = 0;   // reset on genuine success
    });
    connect(m_reconnectingState, &QState::entered, this, [this]() {
        setCurrentState("reconnecting");
        m_retryTimer.start(2000 * (m_retryCount + 1));   // simple backoff
    });
    connect(m_failedState, &QState::entered, this, [this]() {
        setCurrentState("failed");
    });
    connect(m_disconnectedState, &QState::entered, this, [this]() {
        setCurrentState("disconnected");
    });

    connect(&m_retryTimer, &QTimer::timeout, this, [this]() {
        m_retryTimer.stop();
        m_retryCount++;
        if (m_retryCount >= kMaxRetries)
            emit retryExhausted();
        else
            emit connectRequested();   // triggers another attempt
    });

    m_machine.setInitialState(m_disconnectedState);
    m_machine.start();
}

void ConnectionStateMachine::setCurrentState(const QString &name)
{
    if (m_currentState == name) return;
    m_currentState = name;
    emit currentStateChanged();
}

void ConnectionStateMachine::requestConnect()    { emit connectRequested(); }
void ConnectionStateMachine::requestDisconnect()  { emit disconnectRequested(); }
void ConnectionStateMachine::notifyConnected()    { emit connectionEstablished(); }
void ConnectionStateMachine::notifyConnectionFailed() { emit connectionLost(); }
void ConnectionStateMachine::notifyDisconnected() { emit disconnectRequested(); }
```

**Read the transition table as the actual specification of your app's connection behavior** — `m_reconnectingState->addTransition(..., retryExhausted, m_failedState)` isn't just code, it's a legible statement of intent: "reconnecting only ever leads to connected or failed, never silently back to disconnected." This legibility is the entire value proposition over scattered boolean flags — a reviewer (or you, in six months) can read the transitions and know every legal path through your connection lifecycle, the same way a well-drawn state diagram in embedded firmware docs tells you what's actually possible versus what's merely coded.

## Concept: Wiring `MqttManager`'s real signals into the state machine

```cpp
// In MqttManager's constructor
m_stateMachine = new ConnectionStateMachine(this);

connect(m_client, &QMqttClient::stateChanged, this, [this](QMqttClient::ClientState state) {
    if (state == QMqttClient::Connected)
        m_stateMachine->notifyConnected();
    else if (state == QMqttClient::Disconnected && /* was previously connecting/connected */ true)
        m_stateMachine->notifyConnectionFailed();
});
```

The state machine doesn't replace `QMqttClient`'s own state — it sits _above_ it, translating raw client events into your app's richer lifecycle semantics (including retry counting and backoff that `QMqttClient` itself doesn't model).

## Using it from QML

```qml
Label {
    text: {
        switch (ConnectionStateMachine.currentState) {
            case "connected":    return "● Connected"
            case "connecting":   return "◐ Connecting…"
            case "reconnecting": return "◑ Reconnecting…"
            case "failed":       return "✕ Connection failed"
            default:             return "○ Disconnected"
        }
    }
    color: ConnectionStateMachine.currentState === "connected" ? Theme.success
         : ConnectionStateMachine.currentState === "failed"    ? Theme.danger
         : Theme.warning
}
```

Notice this QML is now trivial — a switch on a single string property. All the actual complexity (retry counts, backoff timing, legal transition paths) lives in testable C++, exactly where Day 5 said non-trivial logic belongs.

## Exercise

1. Build `ConnectionStateMachine` exactly as above, wire it into `MqttManager`, and drive it from your real broker — disconnect your network/broker deliberately and watch it walk through `reconnecting` → (retry backoff) → either `connected` (broker comes back) or `failed` (exhausted retries).
2. Add a `QState` for a genuinely distinct "authenticating" phase (imagine your broker requires a token refresh before reconnecting) between `connecting` and `connected` — practice extending the transition table without breaking existing paths.
3. Write a GoogleTest (you already have this tooling from your C++ course) that instantiates `ConnectionStateMachine` standalone (no real MQTT client) and asserts: from `failed`, calling `requestConnect()` correctly transitions to `connecting`, not silently ignored. This is the real payoff of moving lifecycle logic to C++ — it's unit-testable independent of QML or a live broker.
4. In a comment, identify one piece of "boolean flag tangle" logic from your **own** earlier days (Day 16/18's `MqttManager`, perhaps) that would be cleaner expressed as explicit states/transitions, and sketch (don't need to implement) what the transition table would look like.

## Key takeaways

- `state:`/`states:` (Day 7) is right for state _derived_ from existing properties; a genuine multi-step lifecycle with retries/backoff/history needs a real state machine (`QStateMachine`) — trying to force the latter into the former produces boolean-flag tangles.
- `QStateMachine`'s transition table is legible documentation of legal state paths, not just code — a reviewer can read `addTransition` calls and know exactly what's possible, the same value a state diagram provides in firmware design.
- The state machine sits _above_ lower-level signals (`QMqttClient::stateChanged`), translating raw events into richer app-level lifecycle semantics the lower-level object doesn't model itself (retry counts, backoff).
- Moving lifecycle logic into C++ makes it genuinely unit-testable (GoogleTest) independent of QML or a live broker — the real architectural payoff, not just "organization."
- QML consuming a state machine reduces to a trivial switch/ternary on one string property — all real complexity stays in testable C++.
