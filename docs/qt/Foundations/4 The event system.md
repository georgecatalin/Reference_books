[[Foundations]]

## Day 4: The Event System — `QEvent`, Event Filters, and Overriding Event Handlers

### Concept: Signals/Slots Sit _On Top Of_ the Event System, Not Instead Of It

Day 3 covered signals/slots — Qt's app-level messaging mechanism. But underneath that, **everything in Qt is actually an event**. Mouse clicks, key presses, paint requests, resize, focus changes — these arrive as `QEvent` objects delivered to a widget's `event()` method, which Qt's base classes then dispatch to specific virtual handlers (`mousePressEvent()`, `paintEvent()`, `resizeEvent()`, etc.).

In fact, `QPushButton::clicked()` — the signal you've used since Day 1 — is _itself implemented_ by the button receiving a `QMouseEvent`, handling it internally, and then emitting `clicked()` as a convenience. You're about to go one layer below that convenience.

Why you need this layer at all, given signals/slots exist:

- Not everything has a pre-made signal. If you want a custom widget (e.g., a live-updating temperature gauge, a serial-console-style scrolling display), you implement behavior by overriding event handlers directly.
- **Event filters** let you intercept events meant for _another_ widget before it sees them — useful for global shortcuts, input validation, or drag-and-drop zones without subclassing.
- Understanding this layer explains _why_ certain signals/slots behave the way they do, rather than treating Qt as a black box.

### The Event Flow (Mental Model)

```
OS event → Qt platform layer → QCoreApplication::notify()
    → target widget's event(QEvent*)
        → dispatches to specific handler (mousePressEvent, paintEvent, etc.)
            → base class default behavior, unless you override and consume it
```

Any event handler you override typically ends with either:

- `QWidget::mousePressEvent(event);` — call the base class, letting default behavior still happen, or
- `event->accept();` / not calling the base — consuming the event, stopping further propagation.

### Annotated Code: A Custom-Painted Status Indicator Widget

This is the kind of widget that has no pre-built Qt equivalent — a live status "LED" for a device, which you'll wire into the dashboard's device list. Real custom painting, not a styled QLabel.

`statusledwidget.h`:

```cpp
#pragma once
#include <QWidget>

class StatusLedWidget : public QWidget {
    Q_OBJECT
public:
    enum class Status { Online, Offline, Warning };

    explicit StatusLedWidget(QWidget *parent = nullptr);
    void setStatus(Status status);

protected:
    // Overriding the actual paint handler — this is where custom
    // rendering happens. Called by Qt whenever the widget needs
    // to redraw (resize, expose, or explicit update()).
    void paintEvent(QPaintEvent *event) override;

    // Demonstrates handling raw mouse input without a pre-made signal
    void mousePressEvent(QMouseEvent *event) override;

    // Qt calls this to know how big the widget wants to be by default —
    // layouts respect this when computing sizeHint-based policies (Day 2).
    QSize sizeHint() const override;

signals:
    void clicked(); // we're building our own "clicked" signal, manually

private:
    Status currentStatus = Status::Offline;
};
```

`statusledwidget.cpp`:

```cpp
#include "statusledwidget.h"
#include <QPainter>
#include <QMouseEvent>

StatusLedWidget::StatusLedWidget(QWidget *parent) : QWidget(parent) {
    // Without this, mouse events won't reliably repaint if needed;
    // also a reminder that widgets don't get input by default in all contexts.
    setFocusPolicy(Qt::StrongFocus);
}

void StatusLedWidget::setStatus(Status status) {
    currentStatus = status;
    update(); // schedules a repaint — does NOT paint immediately.
              // update() posts a paint event to the queue; Qt coalesces
              // multiple update() calls into a single repaint per frame.
}

QSize StatusLedWidget::sizeHint() const {
    return QSize(20, 20);
}

void StatusLedWidget::paintEvent(QPaintEvent * /*event*/) {
    QPainter painter(this); // QPainter is scoped to this widget's paint device
    painter.setRenderHint(QPainter::Antialiasing);

    QColor color;
    switch (currentStatus) {
        case Status::Online:  color = QColor(46, 204, 113); break; // green
        case Status::Offline: color = QColor(149, 165, 166); break; // gray
        case Status::Warning: color = QColor(230, 126, 34); break; // orange
    }

    painter.setBrush(color);
    painter.setPen(Qt::NoPen);
    painter.drawEllipse(rect().adjusted(2, 2, -2, -2)); // inset slightly
}

void StatusLedWidget::mousePressEvent(QMouseEvent *event) {
    if (event->button() == Qt::LeftButton) {
        emit clicked();
        event->accept(); // we've handled it — stop here
    } else {
        QWidget::mousePressEvent(event); // let base class handle other buttons
    }
}
```

Wire it into the device list row (conceptually — you'd integrate this into a custom list item widget later; for now, just drop one into the dashboard to see it work):

```cpp
#include "statusledwidget.h"

// in MainWindow constructor:
auto *led = new StatusLedWidget(central);
led->setStatus(StatusLedWidget::Status::Online);
connect(led, &StatusLedWidget::clicked, this, [this]() {
    logView->append("[UI] Status LED clicked");
});
mainLayout->addWidget(led);
```

### Annotated Code: An Event Filter (Intercepting Events for Another Widget)

Suppose you want a global shortcut: pressing `Escape` anywhere in the window clears the log, without wiring a keyPressEvent override into every widget.

```cpp
// in mainwindow.h, add:
protected:
    bool eventFilter(QObject *watched, QEvent *event) override;
```

```cpp
// in mainwindow.cpp constructor, near the end:
qApp->installEventFilter(this); // 'this' will now see ALL app events first
```

```cpp
bool MainWindow::eventFilter(QObject *watched, QEvent *event) {
    if (event->type() == QEvent::KeyPress) {
        auto *keyEvent = static_cast<QKeyEvent *>(event);
        if (keyEvent->key() == Qt::Key_Escape) {
            logView->clear();
            return true; // true = event consumed, stop propagation to 'watched'
        }
    }
    return QMainWindow::eventFilter(watched, event); // let everything else through
}
```

**Critical detail**: returning `true` from `eventFilter` swallows the event — the widget that was _supposed_ to receive it never does. Returning `false` (or calling the base class) lets it continue normally. This is easy to get backwards and cause widgets to silently stop responding to input.

### Why This Matters

- `update()` vs `repaint()`: `update()` is asynchronous (schedules via the event loop, coalesces multiple calls into one paint), `repaint()` is synchronous (paints immediately, blocking). **Always prefer `update()`** — `repaint()` is a niche tool for rare cases (e.g., painting immediately before a long blocking operation) and can cause visible tearing/performance problems if overused.
- `QPainter` must be constructed with the widget as its paint device, and only used inside `paintEvent()` (or with an explicit `QPixmap`/`QImage` target) — using it elsewhere is undefined behavior.
- Event filters installed on `qApp` see _every_ event in the entire application — powerful, but also a performance cost if the filter does non-trivial work, since it runs before every single event delivery.
- `sizeHint()` is what Day 2's size policies actually measure against — this connects directly back to layout behavior.

### Exercise

1. Extend `StatusLedWidget` to cycle through `Online → Warning → Offline → Online` on each click (instead of just emitting a signal), and observe how `update()` triggers `paintEvent()` again automatically.
2. Add a `QEvent::MouseButtonDblClick` case to the event filter that toggles the log panel's visibility (`logView->setVisible(!logView->isVisible())`), and verify your `Escape` handling still works alongside it.
3. Deliberately swap `return true` and `return false` in the event filter's Escape-key branch, rebuild, and confirm you understand _why_ Escape stops clearing the log (or starts leaking through to other widgets) in each case.

### Key Takeaways

- Signals/slots are a convenience layer built on top of the raw event system — `QEvent` + virtual handlers (`paintEvent`, `mousePressEvent`, etc.) is the actual foundation.
- Override specific `...Event()` methods for custom widget behavior; call the base class implementation unless you intend to fully consume the event.
- `update()` schedules an async repaint (preferred); `repaint()` paints synchronously and should be rare.
- Event filters intercept events meant for other objects — returning `true` consumes the event, `false` lets it propagate normally. Installed on `qApp`, they see everything, so keep them cheap.
- `sizeHint()` is the bridge between custom widgets and the layout/size-policy system from Day 2.

---

Say "next" for Day 5 (Dialogs, menus, and toolbars — building out the actual application chrome: File/Edit/Settings menus, modal vs. modeless dialogs, and QAction as the unifying abstraction behind menus/toolbars/shortcuts).