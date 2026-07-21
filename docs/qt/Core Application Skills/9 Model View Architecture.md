[[Core Application Skills]]

## Day 9: Model/View Architecture — `QAbstractTableModel` Replaces Manual `QTableWidgetItem` Population

### Concept: Model/View Separates Data From Presentation — And Why `QTableWidget` Doesn't Scale

Everything you've built so far with `QTableWidget` (Day 2) stores data _inside the widget itself_ — each cell is a `QTableWidgetItem` you manually create and update. This works for a static 3-row demo. It falls apart the moment you have live MQTT data streaming in: you'd be manually finding the right row, creating/updating `QTableWidgetItem`s, and keeping the widget in sync with your actual device state by hand — duplicated state, error-prone, and slow at scale.

**Model/View architecture** inverts this: your actual data lives in a plain C++ structure (a `QVector<DeviceReading>`, eventually backed by SQLite), and a **model** class exposes that data to Qt's view widgets through a well-defined interface. The **view** (`QTableView`, not `QTableWidget`) never owns data — it just asks the model "what's in row 3, column 1?" whenever it needs to paint. Update your underlying data, tell the model "this changed," and the view automatically repaints — no manual widget item manipulation at all.

This is the single most important architectural shift in Phase 2, and it maps directly onto backing `mqtt_monitor`'s device table with real SQLite rows later.

### The Three Widgets/Classes You Need to Stop Confusing

||Owns data?|Use for|
|---|---|---|
|`QTableWidget`|Yes (internally)|Quick static/small tables, prototypes|
|`QTableView` + `QAbstractTableModel`|No — model owns data|Real applications, live/large/changing data|
|`QAbstractItemModel`|(base of all models)|Trees/lists too, not just tables|

You'll use `QTableView` + a custom model from here forward. `QTableWidget` was a deliberate simplification for Days 1–8; it's not "wrong," it's just the wrong tool once data becomes dynamic and non-trivial.

### The Minimum Interface You Must Implement

`QAbstractTableModel` is abstract — you must override at minimum:

- `rowCount()` — how many rows exist
- `columnCount()` — how many columns exist
- `data(index, role)` — the actual value at a given cell, **for a given role** (this is the part beginners get wrong first — see below)
- `headerData(section, orientation, role)` — column/row headers

### The Role System — Why `data()` Takes More Than Just an Index

A single cell isn't just "a value" — a view might ask the model for the **display text**, the **background color**, the **tooltip**, the **alignment**, all for the _same cell_, via different `Qt::ItemDataRole` values passed into the same `data()` call. This is the part that trips people up: you write one `data()` function that branches on `role`, not one function per concern.

### Annotated Code: `DeviceTableModel`

`devicereading.h` — plain data struct, no Qt inheritance needed:

```cpp
#pragma once
#include <QString>
#include <QDateTime>

struct DeviceReading {
    QString deviceId;
    QDateTime lastSeen;
    double temperature;
    bool online;
};
```

`devicetablemodel.h`:

```cpp
#pragma once
#include <QAbstractTableModel>
#include <QVector>
#include "devicereading.h"

class DeviceTableModel : public QAbstractTableModel {
    Q_OBJECT
public:
    // Column indices as named constants — avoids magic numbers scattered
    // throughout data()/headerData(), and gives you one place to reorder columns
    enum Column { DeviceId = 0, LastSeen, Temperature, Status, ColumnCount };

    explicit DeviceTableModel(QObject *parent = nullptr);

    // --- Required overrides ---
    int rowCount(const QModelIndex &parent = QModelIndex()) const override;
    int columnCount(const QModelIndex &parent = QModelIndex()) const override;
    QVariant data(const QModelIndex &index, int role = Qt::DisplayRole) const override;
    QVariant headerData(int section, Qt::Orientation orientation,
                         int role = Qt::DisplayRole) const override;

    // --- Public API for updating data — this is how the "real" data source
    // (Day 17+ MQTT/serial handlers) will feed this model ---
    void upsertReading(const DeviceReading &reading);
    void setOffline(const QString &deviceId);

private:
    QVector<DeviceReading> readings;
    int indexOfDevice(const QString &deviceId) const; // -1 if not found
};
```

`devicetablemodel.cpp`:

```cpp
#include "devicetablemodel.h"

DeviceTableModel::DeviceTableModel(QObject *parent) : QAbstractTableModel(parent) {}

int DeviceTableModel::rowCount(const QModelIndex &parent) const {
    // Table models have no nested children — a valid parent index means
    // "give me children of this item," which never applies to a flat table.
    if (parent.isValid()) return 0;
    return readings.size();
}

int DeviceTableModel::columnCount(const QModelIndex &parent) const {
    if (parent.isValid()) return 0;
    return ColumnCount;
}

QVariant DeviceTableModel::data(const QModelIndex &index, int role) const {
    // Always validate bounds — views will sometimes query stale/edge indices
    if (!index.isValid() || index.row() >= readings.size()) {
        return QVariant();
    }

    const DeviceReading &r = readings.at(index.row());

    // DisplayRole = the text shown in the cell — the role you'll handle most
    if (role == Qt::DisplayRole) {
        switch (index.column()) {
            case DeviceId:    return r.deviceId;
            case LastSeen:    return r.lastSeen.toString("HH:mm:ss");
            case Temperature: return QString("%1 C").arg(r.temperature, 0, 'f', 1);
            case Status:      return r.online ? "Online" : "Offline";
        }
    }

    // BackgroundRole = cell background color — same cell, different concern,
    // answered in the same function via a different role check
    if (role == Qt::BackgroundRole && index.column() == Status) {
        return r.online ? QColor(46, 204, 113, 40) : QColor(231, 76, 60, 40);
    }

    // TextAlignmentRole — numeric columns look better right-aligned
    if (role == Qt::TextAlignmentRole && index.column() == Temperature) {
        return Qt::AlignRight + Qt::AlignVCenter;
    }

    // ToolTipRole — extra info on hover, doesn't clutter the cell itself
    if (role == Qt::ToolTipRole && index.column() == DeviceId) {
        return QString("Last full update: %1").arg(r.lastSeen.toString(Qt::ISODate));
    }

    return QVariant(); // unhandled role — Qt uses sensible defaults
}

QVariant DeviceTableModel::headerData(int section, Qt::Orientation orientation, int role) const {
    if (role != Qt::DisplayRole) return QVariant();

    if (orientation == Qt::Horizontal) {
        switch (section) {
            case DeviceId:    return "Device";
            case LastSeen:    return "Last Seen";
            case Temperature: return "Temperature";
            case Status:      return "Status";
        }
    }
    return QVariant(); // vertical headers (row numbers) use Qt's default
}

int DeviceTableModel::indexOfDevice(const QString &deviceId) const {
    for (int i = 0; i < readings.size(); ++i) {
        if (readings[i].deviceId == deviceId) return i;
    }
    return -1;
}

void DeviceTableModel::upsertReading(const DeviceReading &reading) {
    int existing = indexOfDevice(reading.deviceId);

    if (existing >= 0) {
        readings[existing] = reading;
        // Tell the view EXACTLY which cells changed — this is what
        // triggers a repaint of just that row, not the whole table.
        emit dataChanged(index(existing, 0), index(existing, ColumnCount - 1));
    } else {
        // beginInsertRows/endInsertRows bracket the actual mutation —
        // this is NON-NEGOTIABLE. Mutating 'readings' outside this
        // bracket will desync the view from the model and can crash it.
        int newRow = readings.size();
        beginInsertRows(QModelIndex(), newRow, newRow);
        readings.append(reading);
        endInsertRows();
    }
}

void DeviceTableModel::setOffline(const QString &deviceId) {
    int idx = indexOfDevice(deviceId);
    if (idx < 0) return;
    readings[idx].online = false;
    emit dataChanged(index(idx, Status), index(idx, Status)); // just the Status cell
}
```

### Wiring It Into `MainWindow` (Replacing the Day 2 `QTableWidget`)

`mainwindow.h` changes:

```cpp
#include <QTableView>
#include "devicetablemodel.h"

// replace:
// QTableWidget *statusTable;
// with:
QTableView *statusTable;
DeviceTableModel *deviceModel;
```

`mainwindow.cpp`:

```cpp
#include <QHeaderView>

void MainWindow::setupStatusTablePanel() {
    deviceModel = new DeviceTableModel(this);

    statusTable = new QTableView();
    statusTable->setModel(deviceModel);        // the ONE line that wires model to view
    statusTable->setEditTriggers(QAbstractItemView::NoEditTriggers);
    statusTable->horizontalHeader()->setSectionResizeMode(QHeaderView::Stretch);
    statusTable->setSelectionBehavior(QAbstractItemView::SelectRows);
    statusTable->verticalHeader()->setVisible(false); // hide row numbers, not useful here

    // Seed with data, exactly the way live MQTT readings will arrive later
    deviceModel->upsertReading({"device-01", QDateTime::currentDateTime(), 42.1, true});
    deviceModel->upsertReading({"device-02", QDateTime::currentDateTime(), 38.7, true});
    deviceModel->upsertReading({"device-03", QDateTime::currentDateTime(), 0.0, false});
}
```

Now connect it to Day 3's `DeviceMonitor` signals instead of manually logging — this is where the architecture starts paying off:

```cpp
connect(monitor, &DeviceMonitor::temperatureUpdated, this,
        [this](const QString &deviceId, double temp) {
    deviceModel->upsertReading({deviceId, QDateTime::currentDateTime(), temp, true});
});

connect(monitor, &DeviceMonitor::deviceWentOffline, this,
        [this](const QString &deviceId) {
    deviceModel->setOffline(deviceId);
});
```

Notice `onTemperatureUpdated` (the old slot that appended to `logView`) can now _also_ just update the model — the log view and the table both react to the same underlying signal, independently, exactly the decoupling Day 3 promised.

### Why This Matters

- **`beginInsertRows`/`endInsertRows`** (and their `Remove`/`Reset` counterparts) are not optional decoration — they're how the view knows to adjust its internal selection/scroll state correctly. Mutating the underlying container without these brackets is a common source of "the table looks corrupted" bugs and can trigger assertion failures in debug builds.
- **`dataChanged(topLeft, bottomRight)`** lets you signal a precise repaint region — emitting it for the whole table on every single update (instead of just the changed row/cell) causes real, measurable performance problems once you have hundreds of rows updating frequently, which is exactly your MQTT telemetry scenario.
- **Role-based `data()`** is genuinely the right way to separate "what is the value" from "how should it look" — this is what lets you add a background-color-by-status rule without touching layout code or subclassing the view.
- This model class has **zero Qt UI dependencies in its data logic** — `DeviceReading` and the upsert logic could be unit tested with `QTest` (Day 25) without ever creating a `QApplication` or showing a window.

### Exercise

1. Add a fifth column, "Alerts," that shows a count of how many times a device has exceeded a temperature threshold — track this as a field on `DeviceReading`, incremented inside `upsertReading()` when `temperature > 80.0`.
2. Implement `removeReading(deviceId)` using `beginRemoveRows`/`endRemoveRows`, and wire a right-click context menu action on the table (`QTableView::customContextMenuRequested`) to remove the selected device.
3. Deliberately mutate `readings` directly (e.g., `readings.append(...)` without the `beginInsertRows` bracket) in a test build, run it, and observe what breaks — a warning in the console, incorrect row count, or a crash under sanitizers. Then revert and confirm the correct pattern fixes it. This is worth seeing once so "the brackets matter" isn't just something you took on faith.

### Key Takeaways

- `QTableView` + `QAbstractTableModel` separates data ownership (model) from presentation (view) — the view never stores your data, it queries the model on demand.
- `data(index, role)` is one function branching on `Qt::ItemDataRole`, not separate functions per visual concern — `DisplayRole`, `BackgroundRole`, `TextAlignmentRole`, `ToolTipRole` all route through it.
- `beginInsertRows`/`endInsertRows` (and Remove/Reset equivalents) must bracket every structural mutation — skipping this desyncs the view and can crash.
- `dataChanged()` should target the _specific_ changed range, not the whole table — this matters a lot once real-time data volume increases.
- A well-built model has no UI dependencies in its core logic, making it independently unit-testable — directly relevant to your Day 25 QTest work later.

---

Say "next" for Day 10 (extending Model/View: sorting/filtering with `QSortFilterProxyModel`, and `QItemDelegate`/`QStyledItemDelegate` for custom cell rendering beyond what roles alone can express — e.g., an inline progress bar or colored status pill instead of plain text).