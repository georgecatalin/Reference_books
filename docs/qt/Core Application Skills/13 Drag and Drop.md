[[Core Application Skills]]

## Day 13: Drag & Drop — Reordering Cards and Accepting Dropped Config Files

### Concept: Two Separate Drag & Drop Systems in Qt, Don't Conflate Them

Qt actually has two different drag & drop mechanisms depending on what you're moving:

1. **Item view internal drag & drop** (reordering rows in a `QListWidget`/`QTableView`, or between two views) — mostly configured via flags and a handful of virtual methods, minimal manual event handling required.
2. **Generic widget drag & drop** (dragging a file from the OS file manager onto your window, or dragging arbitrary custom data between two widgets) — requires you to override `dragEnterEvent()`, `dragMoveEvent()`, and `dropEvent()` yourself, working with `QMimeData` directly.

`mqtt_monitor` needs both: reordering `DeviceCard`s in the grid view (case 1), and accepting a dropped `.json` config file to load connection settings (case 2).

### Part 1: Reordering Items in a `QListWidget`

This is the simplest case — Qt does almost all the work if you set the right drag/drop mode:

```cpp
deviceList->setDragDropMode(QAbstractItemView::InternalMove);
deviceList->setDefaultDropAction(Qt::MoveAction);
```

That's genuinely most of it for reordering rows within one list. `InternalMove` means "only allow reordering within this same widget," as opposed to `DragDrop` (allows drops from/to other widgets too) or `DragOnly`/`DropOnly` for one-directional cases.

For a `QTableView` backed by your Day 9 `DeviceTableModel`, reordering rows requires slightly more: your model needs to support `moveRows()`, since the underlying data (not just the visual widget) needs to actually reorder. This is beyond today's scope in depth, but worth knowing the model-level hook exists (`QAbstractItemModel::moveRows()`) if you ever need it for the table specifically, rather than just the sidebar list.

### Part 2: Accepting Dropped Files on the Main Window — `QMimeData` and the Three-Event Pattern

`mainwindow.h` additions:

```cpp
protected:
    void dragEnterEvent(QDragEnterEvent *event) override;
    void dropEvent(QDropEvent *event) override;
```

Enable drop acceptance on the widget (constructor):

```cpp
setAcceptDrops(true); // OFF by default — must opt in explicitly
```

`mainwindow.cpp`:

```cpp
#include <QDragEnterEvent>
#include <QDropEvent>
#include <QMimeData>
#include <QFileInfo>
#include <QFile>
#include <QJsonDocument>
#include <QJsonObject>

void MainWindow::dragEnterEvent(QDragEnterEvent *event) {
    // QMimeData carries whatever's being dragged — could be text, images,
    // URLs (files), or custom app-defined data. Always check BEFORE accepting.
    if (event->mimeData()->hasUrls()) {
        const QList<QUrl> urls = event->mimeData()->urls();

        // Only accept if it's exactly one local .json file — reject
        // multi-file drops or non-JSON files rather than accepting
        // blindly and failing later in dropEvent
        if (urls.size() == 1 && urls.first().isLocalFile()) {
            QString path = urls.first().toLocalFile();
            if (path.endsWith(".json", Qt::CaseInsensitive)) {
                event->acceptProposedAction(); // <-- without this, no drop indicator shows at all
                return;
            }
        }
    }
    event->ignore(); // explicit reject — shows the "not allowed" cursor
}

void MainWindow::dropEvent(QDropEvent *event) {
    const QList<QUrl> urls = event->mimeData()->urls();
    if (urls.isEmpty()) {
        event->ignore();
        return;
    }

    QString path = urls.first().toLocalFile();
    QFile file(path);
    if (!file.open(QIODevice::ReadOnly | QIODevice::Text)) {
        logView->append(QString("[ERROR] Could not open dropped file: %1").arg(path));
        event->ignore();
        return;
    }

    QByteArray raw = file.readAll();
    QJsonParseError parseError;
    QJsonDocument doc = QJsonDocument::fromJson(raw, &parseError);

    if (parseError.error != QJsonParseError::NoError) {
        logView->append(QString("[ERROR] Invalid JSON in dropped config: %1")
                         .arg(parseError.errorString()));
        event->ignore();
        return;
    }

    QJsonObject obj = doc.object();
    // Defensive extraction — never assume keys exist, this file came
    // from outside the application and could be malformed or unrelated
    if (obj.contains("brokerHost") && obj.contains("brokerPort")) {
        currentBrokerHost = obj["brokerHost"].toString();
        currentBrokerPort = obj["brokerPort"].toInt(1883);
        logView->append(QString("[CONFIG] Loaded from dropped file: %1:%2")
                         .arg(currentBrokerHost).arg(currentBrokerPort));
        event->acceptProposedAction();
    } else {
        logView->append("[ERROR] Dropped JSON missing required keys (brokerHost/brokerPort)");
        event->ignore();
    }
}
```

### The Part That's Easy to Get Backwards: `acceptProposedAction()` in Both Events

This is the most common bug: people override `dropEvent()` correctly but forget `dragEnterEvent()`, or vice versa. **Both matter, for different reasons**:

- `dragEnterEvent` not accepting → the drop indicator (cursor changes to "allowed"/"not allowed") never appears correctly, and **`dropEvent` will never even fire** — Qt won't deliver a drop to a widget that didn't accept the drag entering it.
- `dropEvent` not accepting → the drag _looks_ like it will work (cursor shows allowed), but the drop silently does nothing or the source application (e.g., a file manager) may show an incorrect "move" animation for an action that didn't actually happen.

You need both, and they can have different acceptance logic (e.g., `dragEnterEvent` does a cheap extension check, `dropEvent` does the expensive actual-parse-and-validate check).

### `QDragMoveEvent` — Only Needed for Fine-Grained Feedback

If you want to restrict _where within the widget_ a drop is valid (e.g., only in the top portion, not over the log panel), override `dragMoveEvent()` too — it fires continuously as the drag moves over your widget. For `mqtt_monitor`'s use case (whole-window config drop), you don't need this; the default behavior (whole widget accepts, inherited from your `dragEnterEvent` decision) is correct as-is.

### Bonus: Initiating a Drag Yourself (Custom Data, Not Just Files)

If you wanted to let a user drag a `DeviceCard` out of the grid onto, say, a "pinned favorites" panel, you'd start the drag manually from a `mousePressEvent`/`mouseMoveEvent` pair:

```cpp
// Inside DeviceCard, conceptually — not wired into today's exercise,
// shown for the mental model
void DeviceCard::mouseMoveEvent(QMouseEvent *event) {
    if (!(event->buttons() & Qt::LeftButton)) return;

    auto *drag = new QDrag(this);
    auto *mimeData = new QMimeData();
    mimeData->setText(id); // carry the device ID as plain text payload
    drag->setMimeData(mimeData);

    drag->exec(Qt::MoveAction); // blocks until drag completes or is cancelled
}
```

This is genuinely rare to need for a monitoring dashboard specifically — flagged here so you recognize the pattern if a future UI need calls for it, not because `mqtt_monitor` requires it today.

### Why This Matters

- `setAcceptDrops(true)` is opt-in and easy to forget — a widget silently ignoring all drops with no error is almost always this one missing line.
- Validating in `dragEnterEvent` (cheap checks: file extension, MIME type) separately from `dropEvent` (expensive checks: actually parse and validate the JSON) is the correct layered validation approach — don't do expensive work just to decide whether to show a drop cursor.
- Defensive JSON parsing on dropped files matters more than on your own internally-generated config — this file came from outside your control entirely (could be any `.json` the user has lying around), unlike the settings you write yourself in Day 7.
- `InternalMove` on `QListWidget` gets you free reordering; genuine model-backed table reordering needs `moveRows()` at the model level, which is a heavier lift you'd only take on if actually needed.

### Exercise

1. Wire the dropped config's `brokerHost`/`brokerPort` into actually pre-filling the `ConnectionDialog` (Day 5) the next time it's opened, rather than just logging the values.
2. Add a visual cue during drag-over: override `dragEnterEvent` to also call `connectionIndicator->setStyleSheet(...)` briefly highlighting the window border color while a valid drag is hovering, then restore it in `dropEvent` (or `dragLeaveEvent`, which you'll need to add for the "user dragged in then dragged back out without dropping" case).
3. Deliberately drop a malformed JSON file (missing keys, or genuinely broken JSON syntax) and a non-JSON file (e.g., a `.txt`) and confirm both are rejected cleanly with a sensible log message, not a crash — this is the same "defensive handling of external input" discipline from your Python/Pydantic validation work, just in C++.

### Key Takeaways

- Item-view reordering (`QListWidget`, etc.) mostly comes free via `setDragDropMode(InternalMove)`; genuine model-backed reordering needs `QAbstractItemModel::moveRows()`.
- Generic drag & drop requires overriding `dragEnterEvent()` and `dropEvent()` — both must call `acceptProposedAction()` (or equivalent) for the flow to work; missing either breaks it in a different way.
- `setAcceptDrops(true)` is required and easy to forget.
- Validate cheaply in `dragEnterEvent` (extension/MIME check), validate expensively in `dropEvent` (actual parse/schema check) — layered validation, not one giant check in one place.
- Dropped files are untrusted external input — parse defensively, same discipline as validating any external data in your Python work.

---

Say "next" for Day 14 (the undo/redo framework — `QUndoStack`/`QUndoCommand` — probably lower priority for a monitoring dashboard specifically, so I'll keep it brief and focused on where it'd actually matter, e.g., if you build an editable device-configuration screen).