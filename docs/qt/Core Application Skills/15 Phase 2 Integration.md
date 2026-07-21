[[Core Application Skills]]

## Day 15: Phase 2 Integration — A Cohesive "Device Overview" Screen (Table + Grid Views)

### Concept: Today Is Assembly, Not New Material

Same spirit as Day 8. Phase 2 gave you: a real model (`DeviceTableModel`), sorting/filtering (`QSortFilterProxyModel`), custom cell rendering (`StatusDelegate`), a reusable composite widget (`DeviceCard`), and tasteful animation. Today wires all of it into one screen with a toggle between table view and grid view — both backed by the _same_ underlying data source, which is the actual payoff of Model/View discipline: one source of truth, multiple presentations.

### The Key Architectural Decision: One Model, Two Views

Your `DeviceTableModel` already doesn't care who's looking at it. The grid of `DeviceCard`s isn't itself a Qt view/model construct (cards are widgets, not model-driven), so you need a small synchronization layer — but it should **read from the same model**, not maintain independent state.

`mainwindow.h`:

```cpp
#include <QStackedWidget>
#include <QMap>
#include "devicecard.h"

private:
    void setupOverviewScreen();
    void syncCardsFromModel(); // rebuild/update grid from deviceModel
    void onViewToggled(bool showGrid);

    QStackedWidget *viewStack;    // switches between table and grid
    QWidget *gridContainer;
    QGridLayout *cardGrid;
    QMap<QString, DeviceCard*> cardsByDeviceId; // deviceId -> card, avoids rebuilding every time
```

`mainwindow.cpp`:

```cpp
#include <QStackedWidget>
#include <QScrollArea>
#include <QToolButton>

void MainWindow::setupOverviewScreen() {
    // --- Table view side (Days 9-10 content, unchanged) ---
    // statusTable + proxyModel + filterEdit already built in setupStatusTablePanel()

    // --- Grid view side (Day 11 content) ---
    gridContainer = new QWidget();
    cardGrid = new QGridLayout(gridContainer);
    cardGrid->setAlignment(Qt::AlignTop);

    auto *scrollArea = new QScrollArea();
    scrollArea->setWidget(gridContainer);
    scrollArea->setWidgetResizable(true);
    scrollArea->setFrameShape(QFrame::NoFrame);

    // --- Stack both views, toggle via a button ---
    viewStack = new QStackedWidget();
    viewStack->addWidget(statusTable);  // index 0
    viewStack->addWidget(scrollArea);   // index 1

    auto *toggleButton = new QToolButton();
    toggleButton->setText("Grid View");
    toggleButton->setCheckable(true);
    connect(toggleButton, &QToolButton::toggled, this, &MainWindow::onViewToggled);

    // Whenever the underlying model changes, keep the grid in sync too —
    // this is the ONE integration point that makes "two views, one source"
    // actually hold together correctly
    connect(deviceModel, &QAbstractItemModel::dataChanged,
            this, [this]() { syncCardsFromModel(); });
    connect(deviceModel, &QAbstractItemModel::rowsInserted,
            this, [this]() { syncCardsFromModel(); });
}

void MainWindow::onViewToggled(bool showGrid) {
    viewStack->setCurrentIndex(showGrid ? 1 : 0);
    if (showGrid) syncCardsFromModel(); // ensure grid reflects latest data on switch-in
}

void MainWindow::syncCardsFromModel() {
    int columns = 3;
    int row = 0, col = 0;

    for (int r = 0; r < deviceModel->rowCount(); ++r) {
        QModelIndex idIndex = deviceModel->index(r, DeviceTableModel::DeviceId);
        QModelIndex tempIndex = deviceModel->index(r, DeviceTableModel::Temperature);
        QModelIndex statusIndex = deviceModel->index(r, DeviceTableModel::Status);

        QString deviceId = deviceModel->data(idIndex, Qt::DisplayRole).toString();
        bool online = deviceModel->data(statusIndex, Qt::DisplayRole).toString() == "Online";

        DeviceCard *card = cardsByDeviceId.value(deviceId, nullptr);
        if (!card) {
            // First time seeing this device — create and register the card,
            // rather than destroying/recreating cards on every sync (which
            // would restart animations and thrash layout unnecessarily)
            card = new DeviceCard(deviceId, gridContainer);
            connect(card, &DeviceCard::detailsRequested, this, [this](const QString &id) {
                logView->append(QString("[UI] Details requested for %1").arg(id));
            });
            cardGrid->addWidget(card, row, col);
            cardsByDeviceId.insert(deviceId, card);
            col++;
            if (col >= columns) { col = 0; row++; }
        }

        // Extract raw temperature - the model stores formatted display
        // text, so for a real numeric read we'd want a Qt::UserRole
        // returning the raw double. Quick addition to DeviceTableModel::data():
        //
        //   if (role == Qt::UserRole && index.column() == Temperature)
        //       return r.temperature;
        //
        double rawTemp = deviceModel->data(tempIndex, Qt::UserRole).toDouble();
        card->setTemperature(rawTemp);
        card->setOnline(online);
    }
}
```

Add the raw-value role to `DeviceTableModel::data()` (Day 9's model, small addition):

```cpp
if (role == Qt::UserRole && index.column() == Temperature) {
    return r.temperature; // raw double, for consumers that need the number, not display text
}
```

This is worth calling out on its own: **`Qt::DisplayRole` gives formatted text for humans; `Qt::UserRole` (and above) is where you stash raw/internal values for other code to consume.** This is a very common real pattern — don't parse your own formatted display strings back into numbers elsewhere in the app; expose the raw value via a role instead.

### Wiring It All Into the Main Layout

```cpp
// In MainWindow constructor, replacing the old direct statusTable placement:
setupOverviewScreen();

auto *overviewLayout = new QVBoxLayout();
auto *toolbarRow = new QHBoxLayout();
toolbarRow->addWidget(filterEdit);
toolbarRow->addWidget(toggleButton);
toolbarRow->addStretch(); // pushes the two widgets left, leaves rest empty

overviewLayout->addLayout(toolbarRow);
overviewLayout->addWidget(viewStack);

// This whole overviewLayout replaces where statusTable was added directly
// in the original splitter from Day 2 — wrap it in a QWidget to add to the splitter
auto *overviewWidget = new QWidget();
overviewWidget->setLayout(overviewLayout);
rightSplitter->insertWidget(0, overviewWidget); // replaces old direct statusTable insert
```

### Why This Matters

- **`cardsByDeviceId`** existing as a persistent map (not rebuilt from scratch every sync) is the difference between a smooth dashboard and one that visibly flickers/rebuilds on every MQTT message — reuse existing card widgets, only create new ones for genuinely new devices.
- **`Qt::UserRole` for raw values** is a pattern you'll lean on constantly once views/other consumers need something other than the formatted display string — resist the urge to re-parse `"42.1 C"` back into a double somewhere else in the code; that's a maintenance trap and a locale/format bug waiting to happen (imagine someone later changes `LastSeen`'s format string and something downstream silently breaks).
- **Reacting to `dataChanged`/`rowsInserted` at the `MainWindow` level to resync the grid** keeps the synchronization explicit and centralized in one function (`syncCardsFromModel`), rather than scattered across many ad-hoc update call sites.
- This integration exercise is the direct blueprint for what happens in Phase 3: the same `deviceModel` will be fed by a real `QThread`-based MQTT/serial worker instead of `DeviceMonitor::simulateReading()` — the entire view/card/delegate layer built in Phase 2 doesn't change at all when the data source becomes real.

### Phase 2 Integration Checklist

|#|Check|
|---|---|
|1|Table view (with filter box + sortable headers) and grid view show the same devices, always in sync|
|2|Switching views via the toggle button doesn't lose or duplicate any device|
|3|Updating a device's temperature updates both the table cell _and_ the corresponding card, without you writing two separate update code paths for "the same" business event|
|4|`StatusDelegate` still renders correctly in the table even with the proxy model wrapping the source|
|5|You understand why raw values live behind `Qt::UserRole`, not parsed back out of `Qt::DisplayRole` text|

### Exercise

1. Add a live "device count" label above the toggle button, updated from `deviceModel->rowCount()` whenever `rowsInserted`/`rowsRemoved` fire — another small proof that the model is the single source of truth other UI elements can react to.
2. Trigger `monitor->simulateReading()` (Day 3) for 6 different device IDs in a loop with slightly staggered `QTimer::singleShot` delays, watch both views update live, and toggle between them mid-stream to confirm no view ever shows stale/missing data.
3. Deliberately introduce a bug: skip registering a card in `cardsByDeviceId` (comment out the `.insert()` call) and observe what breaks — duplicate cards created on every sync — to make concrete why that map exists.

### Key Takeaways

- One model, multiple views (table + custom widget grid) is the real payoff of Model/View separation from Day 9 — business logic and data ownership stay singular even as presentation multiplies.
- Persistent widget-tracking (`QMap<QString, DeviceCard*>`) prevents needless destroy/recreate churn when syncing a non-model-driven view (like a card grid) against a real model.
- `Qt::UserRole` (and custom roles above it) is where raw/internal values belong when a formatted `DisplayRole` string isn't suitable for programmatic consumption elsewhere.
- This integrated structure is exactly what Phase 3's real MQTT/serial data will plug into — no rearchitecting needed, just swapping `DeviceMonitor::simulateReading()` for real inbound data.

---

That's Phase 2 complete. Say "next" for Day 16 — the start of Phase 3: `QThread` and the worker-object pattern done _correctly_ (the single most consequential day in this curriculum for `mqtt_monitor`, since serial I/O and MQTT networking must never block the GUI thread).