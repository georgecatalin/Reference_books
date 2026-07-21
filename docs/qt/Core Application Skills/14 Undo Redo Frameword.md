[[Core Application Skills]]

## Day 14: Undo/Redo Framework — Brief, Applied Only Where It Matters

### Concept: This Framework Is for Editable State, Not Live Telemetry

`QUndoStack`/`QUndoCommand` exist for applications where users make **deliberate, reversible edits** — text editors, drawing apps, configuration screens. It does **not** apply to live telemetry data (you don't "undo" a temperature reading arriving from MQTT). For `mqtt_monitor`, the one place this is genuinely relevant: if you build an editable "device configuration" screen (renaming devices, setting per-device alert thresholds, editing MQTT topic mappings) where a user might want to undo a mistaken edit.

Given that's a smaller, optional part of your project, today is intentionally brief.

### The Core Pattern

`QUndoCommand` is a small class representing one reversible action — you implement `redo()` (perform/re-perform the action) and `undo()` (reverse it). `QUndoStack` holds a history of these and manages the pointer/position.

```cpp
#include <QUndoCommand>

class SetThresholdCommand : public QUndoCommand {
public:
    SetThresholdCommand(DeviceConfig *config, double oldVal, double newVal)
        : config(config), oldValue(oldVal), newValue(newVal) {
        setText(QString("Set alert threshold to %1").arg(newVal)); // shown in Edit menu
    }

    void redo() override { config->setAlertThreshold(newValue); }
    void undo() override { config->setAlertThreshold(oldValue); }

private:
    DeviceConfig *config;
    double oldValue;
    double newValue;
};
```

Using it:

```cpp
auto *undoStack = new QUndoStack(this);

// Instead of calling config->setAlertThreshold(newVal) directly:
undoStack->push(new SetThresholdCommand(config, oldVal, newVal));
// push() calls redo() immediately, then stores it in history
```

Wiring standard Undo/Redo menu actions (this is the one line that makes it feel "free"):

```cpp
QAction *undoAction = undoStack->createUndoAction(this, "&Undo");
undoAction->setShortcut(QKeySequence::Undo); // Ctrl+Z, platform-correct
QAction *redoAction = undoStack->createRedoAction(this, "&Redo");
redoAction->setShortcut(QKeySequence::Redo);

editMenu->addAction(undoAction);
editMenu->addAction(redoAction);
```

`createUndoAction`/`createRedoAction` automatically keep the action's enabled state and label text (e.g., "Undo Set alert threshold to 85") in sync with the stack — you don't manage that yourself.

### Why This Matters (Kept Short)

- **Never bypass the stack** once you're using it — if some code path calls `config->setAlertThreshold()` directly instead of pushing a command, the undo history silently desyncs from actual state. Every mutation to undo-tracked state must go through a command.
- **Command granularity matters**: one keystroke per undo command in a text field would be annoying; batch related edits into one command (e.g., "finished editing this field" rather than "each character typed") — look at `QUndoCommand::mergeWith()` if you need this later.
- This framework is deliberately **not** for your live device table/telemetry — don't be tempted to wrap MQTT data updates in undo commands; that's solving a problem that doesn't exist for that data.

### Exercise (Optional — Only If You Build the Config Screen)

If/when you build a device-config editing screen: implement threshold-editing via `QUndoCommand`, wire up Undo/Redo actions, and verify pushing 3 edits then hitting Undo twice leaves you at the state after edit #1 — confirming the stack behaves as expected before you rely on it further.

### Key Takeaways

- `QUndoStack`/`QUndoCommand` is for deliberate, user-reversible edits — not live/streaming data.
- All mutations to undo-tracked state must go through `push()`, never bypass the stack directly.
- `createUndoAction`/`createRedoAction` give you correctly-synced, correctly-labeled menu items for free.
- For `mqtt_monitor` specifically, this is optional scope — only relevant if a config-editing screen gets built.

---

Say "next" for Day 15 (a real integration/practice day for Phase 2: combining Model/View, delegates, proxy filtering, composite cards, and light animation into one cohesive "Device Overview" screen with both table and grid views toggleable).