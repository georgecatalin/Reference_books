[[Foundations]]

## Day 2: Layouts, Sizing Policies, and Building a Multi-Panel Device Status View

### Concept: Layouts Are Not Positioning — They're Constraint Systems

Coming from embedded/systems work, the instinct is to think of UI in terms of fixed coordinates (like framebuffer pixel placement). Qt layouts are the opposite: you describe **relationships and policies**, and Qt's layout engine solves for actual pixel geometry at runtime, every time the window resizes. This matters because:

- You almost never call `setGeometry()` or `move()` on widgets inside a layout — the layout owns their position/size.
- Every widget has a **size policy** (`QSizePolicy`) on both axes: does it want to grow, shrink, stay fixed, or expand to fill available space? Layout bugs are almost always a size policy or stretch-factor problem, not a "wrong pixel" problem.

### The Four Layout Types You'll Actually Use

1. **`QVBoxLayout` / `QHBoxLayout`** — stack widgets vertically/horizontally. You used these Day 1.
2. **`QGridLayout`** — row/column placement, like a spreadsheet. Best for forms and dashboards.
3. **`QFormLayout`** — label/field pairs specifically, e.g. settings panels.
4. **`QSplitter`** — not technically a layout, but a container that lets the user _drag-resize_ panels. Critical for dashboard-style apps like `mqtt_monitor`'s GUI, where someone wants to resize the device list vs. the log panel.

### Size Policies — The Part Everyone Gets Wrong First

```cpp
widget->setSizePolicy(QSizePolicy::Expanding, QSizePolicy::Fixed);
```

Read this as: "horizontally, take as much space as available; vertically, stay at your `sizeHint()`." The common policies:

|Policy|Meaning|
|---|---|
|`Fixed`|Never grows/shrinks past `sizeHint()`|
|`Minimum`|Won't shrink below `sizeHint()`, can grow|
|`Maximum`|Won't grow past `sizeHint()`, can shrink|
|`Preferred`|Default — prefers `sizeHint()` but flexible both ways|
|`Expanding`|Wants as much space as possible, competes with siblings|

**Stretch factors** in box layouts are the other lever — they control how _extra_ space is divided among Expanding widgets:

```cpp
layout->addWidget(widgetA, /*stretch=*/1);
layout->addWidget(widgetB, /*stretch=*/2); // gets 2x the extra space of A
```

### Annotated Code: A Real Device-Status Dashboard Shell

This is the actual skeleton you'll grow into the `mqtt_monitor` GUI over the coming days. Today: layout structure only, no live data yet.

`mainwindow.h`:

```cpp
#pragma once
#include <QMainWindow>
#include <QListWidget>
#include <QTextEdit>
#include <QTableWidget>
#include <QSplitter>
#include <QLabel>

class MainWindow : public QMainWindow {
    Q_OBJECT
public:
    explicit MainWindow(QWidget *parent = nullptr);

private:
    void setupDeviceListPanel();
    void setupStatusTablePanel();
    void setupLogPanel();

    QListWidget *deviceList;
    QTableWidget *statusTable;
    QTextEdit *logView;
    QLabel *connectionIndicator;
};
```

`mainwindow.cpp`:

```cpp
#include "mainwindow.h"
#include <QVBoxLayout>
#include <QHBoxLayout>
#include <QHeaderView>

MainWindow::MainWindow(QWidget *parent) : QMainWindow(parent) {
    setWindowTitle("mqtt_monitor - Device Dashboard");
    resize(1000, 600);

    QWidget *central = new QWidget(this);
    setCentralWidget(central);

    auto *mainLayout = new QVBoxLayout(central);

    // Top bar: connection status indicator
    connectionIndicator = new QLabel("● Disconnected", central);
    connectionIndicator->setStyleSheet("color: red; font-weight: bold;");
    mainLayout->addWidget(connectionIndicator);

    // Main content: a horizontal splitter dividing device list (left)
    // from status table + logs (right, stacked vertically)
    auto *splitter = new QSplitter(Qt::Horizontal, central);

    setupDeviceListPanel();     // populates deviceList
    splitter->addWidget(deviceList);

    // Right side is itself a vertical splitter: table on top, log below
    auto *rightSplitter = new QSplitter(Qt::Vertical, splitter);
    setupStatusTablePanel();
    setupLogPanel();
    rightSplitter->addWidget(statusTable);
    rightSplitter->addWidget(logView);
    rightSplitter->setStretchFactor(0, 2); // table gets 2x the log's space
    rightSplitter->setStretchFactor(1, 1);

    splitter->addWidget(rightSplitter);
    splitter->setStretchFactor(0, 1); // device list
    splitter->setStretchFactor(1, 3); // right side gets 3x the space

    mainLayout->addWidget(splitter);
}

void MainWindow::setupDeviceListPanel() {
    deviceList = new QListWidget();
    deviceList->addItem("device-01 (BeagleBone)");
    deviceList->addItem("device-02 (RPi 4)");
    deviceList->addItem("device-03 (RPi Zero)");
    // Expanding vertically, but don't let it get absurdly wide
    deviceList->setSizePolicy(QSizePolicy::Preferred, QSizePolicy::Expanding);
}

void MainWindow::setupStatusTablePanel() {
    statusTable = new QTableWidget(3, 4);
    statusTable->setHorizontalHeaderLabels({"Device", "Last Seen", "Temp", "Status"});
    statusTable->horizontalHeader()->setSectionResizeMode(QHeaderView::Stretch);
    statusTable->setEditTriggers(QAbstractItemView::NoEditTriggers); // read-only

    // Placeholder rows — Day 9+ will replace this with a real model
    statusTable->setItem(0, 0, new QTableWidgetItem("device-01"));
    statusTable->setItem(0, 1, new QTableWidgetItem("12:03:41"));
    statusTable->setItem(0, 2, new QTableWidgetItem("42.1 C"));
    statusTable->setItem(0, 3, new QTableWidgetItem("OK"));
}

void MainWindow::setupLogPanel() {
    logView = new QTextEdit();
    logView->setReadOnly(true);
    logView->append("[12:03:41] device-01 connected");
    logView->append("[12:03:42] device-01 published telemetry");
}
```

`main.cpp` is unchanged from Day 1 (just update the include if needed).

### Why This Matters

- **`QSplitter` inside `QSplitter`** is a normal, idiomatic pattern for dashboard layouts — nest freely.
- **Stretch factors on splitters** (`setStretchFactor`) behave similarly to box layout stretch factors but govern _initial_ space distribution; the user can still drag to override. This is different from box layout stretch, which is enforced continuously.
- **`setEditTriggers(QAbstractItemView::NoEditTriggers)`** — a very common real bug: forgetting this means users can double-click and edit "read-only" data that only _looks_ immutable. Always be deliberate about this.
- **`QHeaderView::Stretch`** makes table columns fill available width proportionally — without it, columns default to a fixed pixel width and leave dead space on wide windows.

### Exercise

1. Add a fourth panel: a `QFormLayout`-based "connection settings" panel (broker host, port, client ID fields) that sits in a `QDockWidget` you can toggle from a menu (look up `QDockWidget` — it's the standard Qt idiom for detachable/dockable panels, very relevant for a monitoring dashboard).
2. Resize the window and observe: which widgets grow, which stay fixed? Deliberately break it — set `statusTable`'s size policy to `Fixed` and see what happens to the splitter behavior.
3. Add stretch factor `0` to the log view stretch pair instead of `1`, rebuild, and observe how dragging the splitter behaves differently. Get an intuitive feel for what stretch factors actually control vs. what the user's manual drag controls.

### Key Takeaways

- Layouts solve constraints, they don't position pixels — never fight this by manually setting geometry on managed widgets.
- Size policy (`QSizePolicy`) governs _whether_ a widget wants to grow/shrink; stretch factor governs _how much_ space it gets relative to siblings.
- `QSplitter` is your tool for user-resizable dashboard panels; nest them for grid-like arrangements.
- Table/list widgets need explicit `EditTriggers` and header resize-mode configuration, or you'll get subtly broken read-only behavior and ugly column sizing by default.

---

Say "next" for Day 3 (signals & slots in depth — queued vs. direct connections, custom signals, and why this matters enormously once threads enter the picture).