[[Concurrency]]
## Day 22: `QtCharts` — Live-Updating Temperature History, Backed by SQLite

### Concept: Charts Are Views Too — Same Model/View Instincts Apply, With a Twist

`QtCharts` gives you `QChart`, `QChartView` (the widget), and series types (`QLineSeries`, `QSplineSeries`, etc.) that hold plotted points. The twist compared to Day 9's `QAbstractTableModel` work: chart series aren't full `QAbstractItemModel`-based by default — you generally push data into a series directly (`series->append(x, y)`), rather than backing it with a custom model class. There _is_ a `QAbstractSeries`-model-binding path (`QVXYModelMapper`) for genuinely model-driven charts, but for a live telemetry chart, direct series manipulation is simpler, more common, and what you'll use today.

The real engineering problem today isn't "how do I draw a line chart" (that's a few lines) — it's **how do you keep a live-updating chart performant as data grows**, especially on embedded hardware. That's the actual focus.

### Setup

```cmake
find_package(Qt6 REQUIRED COMPONENTS Widgets Charts)
target_link_libraries(mqtt_monitor_gui PRIVATE Qt6::Widgets Qt6::Charts)
```

### Annotated Code: A Per-Device Temperature History Chart

`temperaturechart.h`:

```cpp
#pragma once
#include <QWidget>
#include <QChart>
#include <QChartView>
#include <QLineSeries>
#include <QDateTimeAxis>
#include <QValueAxis>

class TemperatureChart : public QWidget {
    Q_OBJECT
public:
    explicit TemperatureChart(QWidget *parent = nullptr);

    void addReading(const QDateTime &timestamp, double temperature);
    void loadHistory(const QList<QPair<QDateTime, double>> &points); // from SQLite
    void setMaxPoints(int max) { maxPoints = max; }

private:
    void trimOldPoints();

    QChart *chart;
    QChartView *chartView;
    QLineSeries *series;
    QDateTimeAxis *axisX;
    QValueAxis *axisY;
    int maxPoints = 300; // roughly 5 minutes at 1 reading/sec — bounded, not unbounded
};
```

`temperaturechart.cpp`:

```cpp
#include "temperaturechart.h"
#include <QVBoxLayout>

TemperatureChart::TemperatureChart(QWidget *parent) : QWidget(parent) {
    series = new QLineSeries();
    series->setName("Temperature");

    chart = new QChart();
    chart->addSeries(series);
    chart->legend()->hide(); // one series, single-device chart — legend is just noise here
    chart->setMargins(QMargins(4, 4, 4, 4)); // tight margins, this is a dashboard widget,
                                               // not a standalone report figure

    axisX = new QDateTimeAxis();
    axisX->setFormat("HH:mm:ss");
    axisX->setTitleText("Time");
    chart->addAxis(axisX, Qt::AlignBottom);
    series->attachAxis(axisX);

    axisY = new QValueAxis();
    axisY->setTitleText("°C");
    axisY->setRange(0, 100); // fixed range for a temperature dashboard — avoids the
                              // chart auto-rescaling and visually jumping around
                              // every time a new point arrives, which is distracting
                              // and actively unhelpful for at-a-glance monitoring
    chart->addAxis(axisY, Qt::AlignLeft);
    series->attachAxis(axisY);

    chartView = new QChartView(chart, this);
    chartView->setRenderHint(QPainter::Antialiasing);

    auto *layout = new QVBoxLayout(this);
    layout->setContentsMargins(0, 0, 0, 0);
    layout->addWidget(chartView);
}

void TemperatureChart::addReading(const QDateTime &timestamp, double temperature) {
    series->append(timestamp.toMSecsSinceEpoch(), temperature);
    trimOldPoints();

    // Keep the X axis window sliding — show the last N points' time range,
    // not the entire history since app start
    if (series->count() > 0) {
        QDateTime earliest = QDateTime::fromMSecsSinceEpoch(series->at(0).x());
        axisX->setRange(earliest, timestamp);
    }
}

void TemperatureChart::trimOldPoints() {
    // CRITICAL for long-running embedded deployments: without this, the
    // series grows unbounded over hours/days of continuous telemetry,
    // and both memory use AND per-frame render cost grow with it —
    // a slow, insidious degradation, not an immediate crash, which makes
    // it the kind of bug that only shows up after the demo, in the field
    while (series->count() > maxPoints) {
        series->remove(0); // remove oldest point
    }
}

void TemperatureChart::loadHistory(const QList<QPair<QDateTime, double>> &points) {
    // Bulk-load from SQLite (Day 20) — replace, not append, since this is
    // meant to seed initial chart state, e.g., when a device details view opens
    series->clear();
    for (const auto &point : points) {
        series->append(point.first.toMSecsSinceEpoch(), point.second);
    }
    trimOldPoints();
    if (series->count() > 0) {
        axisX->setRange(QDateTime::fromMSecsSinceEpoch(series->at(0).x()),
                         QDateTime::fromMSecsSinceEpoch(series->at(series->count() - 1).x()));
    }
}
```

### Wiring Into `MainWindow` — One Chart Per Device, On Demand

```cpp
#include "temperaturechart.h"

// In MainWindow, a map similar to Day 15's cardsByDeviceId
QMap<QString, TemperatureChart*> chartsByDeviceId;

void MainWindow::showDeviceHistoryChart(const QString &deviceId) {
    TemperatureChart *chart = chartsByDeviceId.value(deviceId, nullptr);
    if (!chart) {
        chart = new TemperatureChart();
        chartsByDeviceId.insert(deviceId, chart);

        // Seed with historical data from SQLite (Day 20's persistWorker)
        QMetaObject::invokeMethod(persistWorker, "queryRecentReadings", Qt::QueuedConnection,
                                   Q_ARG(QString, deviceId), Q_ARG(int, 300));
    }

    // Show in a dialog, dock widget, or dedicated tab — a modeless dialog
    // (Day 5) is a reasonable fit here, letting the user keep it open
    // alongside the main dashboard
    auto *dialog = new QDialog(this);
    dialog->setWindowTitle("History: " + deviceId);
    dialog->setAttribute(Qt::WA_DeleteOnClose, false); // reuse the dialog, don't destroy the chart with it
    auto *layout = new QVBoxLayout(dialog);
    layout->addWidget(chart);
    dialog->resize(600, 400);
    dialog->show();
}

// Feed live readings into the chart alongside deviceModel updates —
// same event, multiple representations, the Day 15 pattern again
connect(mqttWorker, &MqttWorker::messageReceived, this,
        [this](const QString &topic, const QByteArray &payload) {
    // ... existing parsing ...
    QString deviceId = /* extracted */;
    double temp = /* parsed */;

    deviceModel->upsertReading({deviceId, QDateTime::currentDateTime(), temp, true});

    if (chartsByDeviceId.contains(deviceId)) {
        chartsByDeviceId[deviceId]->addReading(QDateTime::currentDateTime(), temp);
    }
    // Chart only updates if its dialog has been opened at least once —
    // no point maintaining chart state for devices nobody's looking at
});

// Handle the async history load coming back from SQLite
connect(persistWorker, &PersistenceWorker::readingsLoaded, this,
        [this](const QString &deviceId, const QList<ReadingRecord> &records) {
    if (!chartsByDeviceId.contains(deviceId)) return;

    QList<QPair<QDateTime, double>> points;
    for (const auto &r : records) {
        points.append({r.recordedAt, r.temperature});
    }
    // Records came back newest-first (DESC in Day 20's query) — reverse
    // for correct chronological chart order
    std::reverse(points.begin(), points.end());
    chartsByDeviceId[deviceId]->loadHistory(points);
});
```

### Why This Matters

- **`trimOldPoints()` — bounding the series size — is the single most important performance consideration** for any long-running live chart. Every point added without bound is both a memory cost and, worse, a per-repaint rendering cost (QtCharts redraws the whole visible series on update) — this is the charting equivalent of Day 9's "don't rebuild the whole model on every change," just for a different widget category.
- **Fixed Y-axis range** (`setRange(0, 100)`) is a deliberate monitoring-dashboard UX decision, not a technical limitation — auto-scaling axes are appropriate for exploratory data analysis, but actively harmful for at-a-glance operational monitoring, where a consistent visual scale lets you eyeball "is this normal" without reading axis labels every time.
- **Only maintaining/updating charts for devices whose history dialog has actually been opened** (`chartsByDeviceId.contains(deviceId)` guard) avoids doing chart-update work for potentially dozens of devices nobody is currently looking at — directly relevant given embedded/lower-power deployment targets.
- **Loading history asynchronously via the existing `PersistenceWorker`** (not a new blocking read) means opening a device's chart doesn't introduce a fresh GUI-thread-blocking-I/O bug on top of everything Day 20 already fixed.
- **`QChartView::setRenderHint(QPainter::Antialiasing)`** is a real, measurable CPU cost tradeoff — smoother lines at some render expense; worth being aware you're making that tradeoff explicitly rather than by accident, especially relevant again for lower-power targets where you might reasonably choose to disable it.

### Exercise

1. Add a second series (`QLineSeries`) to the same chart for a rolling average (compute a simple moving average over the last 10 points as new readings arrive) overlaid on the raw readings — this reintroduces the legend, since now there are two series worth distinguishing.
2. Add a horizontal threshold line (a flat `QLineSeries` at y=80, or look up `QChart`'s support for a simple reference line) so the alert threshold is visually obvious on the chart itself, not just implied by color elsewhere in the UI.
3. Deliberately set `maxPoints` very high (e.g., 100,000) temporarily, feed the chart synthetic data rapidly in a tight loop, and observe the point at which the UI visibly starts to lag — then reduce it back to a sane bound and confirm smooth behavior. This makes the "unbounded growth degrades performance" point concrete rather than assumed, mirroring the pattern of several earlier days' "break it deliberately, then fix it" exercises.

### Key Takeaways

- Chart series (`QLineSeries`) are populated directly (`append`/`remove`), not typically backed by a full custom `QAbstractItemModel` for live telemetry use cases — simpler and sufficient here.
- Bound series size explicitly (`trimOldPoints()`) — unbounded growth is a real, gradual performance degradation on long-running deployments, not a rare edge case.
- Fixed axis ranges are usually the right call for monitoring dashboards specifically — stable visual scale over auto-fit convenience.
- Only maintain chart state for devices actually being viewed — don't do update work for UI nobody has opened, especially relevant on embedded/lower-power targets.
- Feed historical data in via the existing async `PersistenceWorker`, not a new ad-hoc blocking query — reuse the threading discipline you already built in Day 20.

---

Say "next" for Day 23 (custom-painted dashboard visuals beyond QtCharts — a gauge/dial widget combining Day 4's `QPainter` skills with Day 12's animation, for a genuinely custom look QtCharts doesn't provide out of the box).
