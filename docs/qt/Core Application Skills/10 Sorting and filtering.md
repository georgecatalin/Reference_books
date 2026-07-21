[[Core Application Skills]]

## Day 10: Sorting/Filtering with `QSortFilterProxyModel`, and Custom Rendering with `QStyledItemDelegate`

### Concept: Two Different Problems, Two Different Classes — Don't Conflate Them

Day 9 gave you a model that owns data and a view that displays it. Today adds two more pieces that beginners often try to solve in the wrong place:

1. **"I want to sort/filter what's shown, without touching my actual data"** → `QSortFilterProxyModel`. This sits _between_ your model and the view, transparently. Your `DeviceTableModel` stays completely unaware that sorting/filtering exists — it just answers `data()` calls as always.
2. **"I want a cell to render as something richer than text"** (a progress bar, a colored status pill, an icon+text combo) → `QStyledItemDelegate`. This controls _how_ a cell paints, independent of what the model returns.

The common mistake: trying to bake sorting logic into the model itself, or trying to change cell appearance by manipulating `data()` roles alone when the visual is genuinely non-textual. Keep these separated — it keeps each class simple and reusable.

### Annotated Code: `QSortFilterProxyModel` — Filtering + Sorting Without Touching `DeviceTableModel`

```cpp
#include <QSortFilterProxyModel>
```

`mainwindow.h`:

```cpp
QSortFilterProxyModel *proxyModel;
QLineEdit *filterEdit; // search box above the table
```

`mainwindow.cpp`, replacing the direct `setModel(deviceModel)` call:

```cpp
void MainWindow::setupStatusTablePanel() {
    deviceModel = new DeviceTableModel(this);

    // The proxy wraps the real model — it forwards rowCount/data/etc calls
    // through, but reorders/hides rows according to its own rules first.
    proxyModel = new QSortFilterProxyModel(this);
    proxyModel->setSourceModel(deviceModel);
    proxyModel->setFilterCaseSensitivity(Qt::CaseInsensitive);
    proxyModel->setFilterKeyColumn(DeviceTableModel::DeviceId); // which column text-filters against

    statusTable = new QTableView();
    statusTable->setModel(proxyModel);   // <-- view now points at the PROXY, not the real model
    statusTable->setSortingEnabled(true); // clicking headers now sorts, for free
    statusTable->setEditTriggers(QAbstractItemView::NoEditTriggers);
    statusTable->horizontalHeader()->setSectionResizeMode(QHeaderView::Stretch);
    statusTable->setSelectionBehavior(QAbstractItemView::SelectRows);
    statusTable->verticalHeader()->setVisible(false);

    filterEdit = new QLineEdit();
    filterEdit->setPlaceholderText("Filter by device ID...");
    connect(filterEdit, &QLineEdit::textChanged, this, [this](const QString &text) {
        // setFilterFixedString does a plain substring match;
        // setFilterRegularExpression exists if you need patterns
        proxyModel->setFilterFixedString(text);
    });

    // seeding data is unchanged — goes through deviceModel, NOT proxyModel
    deviceModel->upsertReading({"device-01", QDateTime::currentDateTime(), 42.1, true});
    deviceModel->upsertReading({"device-02", QDateTime::currentDateTime(), 38.7, true});
    deviceModel->upsertReading({"device-03", QDateTime::currentDateTime(), 0.0, false});
}
```

Add `filterEdit` above the table in your layout (inside `setupStatusTablePanel`'s caller, or directly here if you restructure slightly):

```cpp
// wherever statusTable is added to a layout:
auto *tableContainer = new QVBoxLayout();
tableContainer->addWidget(filterEdit);
tableContainer->addWidget(statusTable);
```

### The Critical Gotcha: Proxy Indices vs. Source Indices

This is the single most common bug once a proxy is introduced: **row 2 in the view is not necessarily row 2 in your real model** — sorting/filtering means the proxy's row order can differ entirely from the source. Any code that reacts to a _selection_ or _click_ in the view gets a **proxy index**, and must be explicitly mapped back to the source model before touching your actual data:

```cpp
connect(statusTable, &QTableView::doubleClicked, this, [this](const QModelIndex &proxyIndex) {
    // WRONG: deviceModel->someMethod(proxyIndex.row()) — this row number
    // means nothing in terms of deviceModel's actual internal storage.

    QModelIndex sourceIndex = proxyModel->mapToSource(proxyIndex); // CORRECT
    int realRow = sourceIndex.row();
    // now realRow is safe to use against deviceModel directly
});
```

Going the other direction (source → proxy, e.g., "scroll to and select this device after an update") uses `mapFromSource()`.

### Annotated Code: `QStyledItemDelegate` — A Custom Status Pill Instead of Plain Text

Delegates override `paint()` (how a cell is drawn) and optionally `sizeHint()` (how much space it needs). This is the tool for genuinely non-textual cell content.

`statusdelegate.h`:

```cpp
#pragma once
#include <QStyledItemDelegate>

class StatusDelegate : public QStyledItemDelegate {
    Q_OBJECT
public:
    explicit StatusDelegate(QObject *parent = nullptr);

    void paint(QPainter *painter, const QStyleOptionViewItem &option,
               const QModelIndex &index) const override;
    QSize sizeHint(const QStyleOptionViewItem &option,
                   const QModelIndex &index) const override;
};
```

`statusdelegate.cpp`:

```cpp
#include "statusdelegate.h"
#include <QPainter>

StatusDelegate::StatusDelegate(QObject *parent) : QStyledItemDelegate(parent) {}

void StatusDelegate::paint(QPainter *painter, const QStyleOptionViewItem &option,
                            const QModelIndex &index) const {
    // Pull the same DisplayRole text the default delegate would have used —
    // the delegate doesn't own data either, it queries the model just like a view does
    QString status = index.data(Qt::DisplayRole).toString();
    bool online = (status == "Online");

    painter->save(); // ALWAYS save/restore — you're painting into a shared painter
                      // used across every cell; leaking state corrupts other cells

    // Draw selection/hover background first, via the style, so it still
    // looks native/consistent — don't skip this or selection highlighting breaks
    QStyleOptionViewItem opt = option;
    initStyleOption(&opt, index);
    opt.text.clear(); // suppress default text painting, we're drawing our own
    QApplication::style()->drawControl(QStyle::CE_ItemViewItem, &opt, painter);

    QColor pillColor = online ? QColor(46, 204, 113) : QColor(231, 76, 60);
    QRect pillRect = option.rect.adjusted(8, 6, -8, -6);

    painter->setRenderHint(QPainter::Antialiasing);
    painter->setBrush(pillColor);
    painter->setPen(Qt::NoPen);
    painter->drawRoundedRect(pillRect, 8, 8);

    painter->setPen(Qt::white);
    painter->drawText(pillRect, Qt::AlignCenter, status);

    painter->restore();
}

QSize StatusDelegate::sizeHint(const QStyleOptionViewItem &option, const QModelIndex &index) const {
    return QStyledItemDelegate::sizeHint(option, index).expandedTo(QSize(60, 24));
}
```

Wiring it in:

```cpp
#include "statusdelegate.h"

// in setupStatusTablePanel(), after setModel():
statusTable->setItemDelegateForColumn(DeviceTableModel::Status, new StatusDelegate(this));
```

### Why This Matters

- **Proxy models are non-invasive by design** — this is deliberate architecture, not an accident. Your `DeviceTableModel` will later be backed by SQLite (Phase 3), and it still won't need to know or care that a search box and column sorting exist above it. Layering like this is what keeps each class testable in isolation.
- **`mapToSource`/`mapFromSource` are not optional when a proxy is in play** — forgetting this is probably the single most common bug in real-world Qt apps that add search/sort after the fact to an existing table. If you see a "double click opens the wrong row" bug in _any_ Qt app, this is almost always the cause.
- **Delegates use the same `data()`/role system as models** — they call `index.data(role)` themselves, so a delegate and a plain role-based render can coexist on different columns of the same table without conflict.
- **`painter->save()`/`restore()`** in a delegate isn't optional housekeeping — a `QPainter` is reused across every single cell paint call in a table; forgetting to restore state (pen, brush, transform) after modifying it will visibly corrupt rendering in _subsequent_ cells, not just this one.

### Exercise

1. Add a numeric filter: a `QComboBox` with "All / Online only / Offline only" that calls `proxyModel->setFilterRole()`/a custom filter approach — actually, the cleaner path here is subclassing `QSortFilterProxyModel` and overriding `filterAcceptsRow()` to check the online/offline boolean directly from the source model. Implement that subclass.
2. Build a second delegate for the `Temperature` column that draws a small horizontal bar (like a mini progress/gauge) proportional to temperature (e.g., 0–100°C mapped to 0–100% bar width), with the numeric text overlaid.
3. Deliberately click a column header to sort, then double-click a row, and print both the proxy index row and the `mapToSource()` row to `logView` to see them diverge in practice — confirming the gotcha isn't just theoretical.

### Key Takeaways

- `QSortFilterProxyModel` sits between model and view, providing sort/filter without modifying the source model — set it up with `setSourceModel()`, point the view at the _proxy_, not the original model.
- Any index coming from view interaction (click, selection, current index) is a **proxy index** and must be converted via `mapToSource()`/`mapFromSource()` before being used against your real model's data.
- `QStyledItemDelegate::paint()` is the tool for non-textual cell rendering (pills, bars, icons) — it reads from the model via `index.data(role)` just like a view does, so it composes cleanly with role-based styling on other columns.
- Always `save()`/`restore()` around custom painting in a delegate — the painter is shared across every cell in the view.
- Custom filtering logic beyond simple substring matching means subclassing `QSortFilterProxyModel` and overriding `filterAcceptsRow()`.

---

Say "next" for Day 11 (custom widgets composed from smaller widgets — building a proper reusable "DeviceCard" component with its own layout, signals, and property system, the pattern for anything you'll reuse more than once in the UI).