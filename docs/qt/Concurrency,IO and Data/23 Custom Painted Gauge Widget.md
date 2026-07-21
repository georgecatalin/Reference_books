[[Concurrency]]

## Day 23: Custom-Painted Gauge Widget — Combining `QPainter` (Day 4) + Animation (Day 12)

### Concept: When QtCharts Isn't the Right Tool

QtCharts is built for line/bar/scatter data over time or categories. A circular gauge/dial — the kind of at-a-glance "current value" indicator you'd want for a single device's live temperature, or an aggregate "system health" score — isn't a QtCharts series type at all. This is the genuine case for going back to raw `QPainter` work from Day 4, now combined with Day 12's animation system for smooth needle/arc transitions rather than jarring jumps.

This is also a good moment to consolidate two skills you've built separately: custom painting and property animation, applied together on one non-trivial widget.

### Design: A Circular Temperature Gauge

- An arc background (270° sweep, like a car speedometer, leaving a gap at the bottom)
- A colored value arc showing current reading as a proportion of the range
- A numeric readout in the center
- Smooth animated transition when the value changes (not a snap)

### Annotated Code: `GaugeWidget`

`gaugewidget.h`:

```cpp
#pragma once
#include <QWidget>
#include <QPropertyAnimation>

class GaugeWidget : public QWidget {
    Q_OBJECT
    // The animatable property — same mechanism as Day 12's DeviceCard::tempColor
    Q_PROPERTY(double displayValue READ displayValue WRITE setDisplayValue)

public:
    explicit GaugeWidget(QWidget *parent = nullptr);

    void setRange(double min, double max);
    void setValue(double value, bool animate = true); // the real API callers use
    void setUnit(const QString &unit) { unitText = unit; update(); }

    double displayValue() const { return currentDisplayValue; }
    void setDisplayValue(double value); // called by the animation, not callers directly

protected:
    void paintEvent(QPaintEvent *event) override;
    QSize sizeHint() const override { return QSize(180, 180); }

private:
    double minValue = 0.0;
    double maxValue = 100.0;
    double targetValue = 0.0;       // the actual current reading
    double currentDisplayValue = 0.0; // what's currently rendered — animates toward targetValue
    QString unitText = "°C";
    QPropertyAnimation *animation = nullptr;

    QColor colorForValue(double value) const;
};
```

`gaugewidget.cpp`:

```cpp
#include "gaugewidget.h"
#include <QPainter>
#include <QtMath>

GaugeWidget::GaugeWidget(QWidget *parent) : QWidget(parent) {
    setSizePolicy(QSizePolicy::Preferred, QSizePolicy::Preferred);
}

void GaugeWidget::setRange(double min, double max) {
    minValue = min;
    maxValue = max;
    update();
}

void GaugeWidget::setValue(double value, bool animate) {
    targetValue = qBound(minValue, value, maxValue); // clamp — never let the needle overshoot visually

    if (!animate) {
        setDisplayValue(targetValue); // immediate jump, e.g. for initial load, no animation needed
        return;
    }

    if (!animation) {
        animation = new QPropertyAnimation(this, "displayValue", this);
        animation->setDuration(500);
        animation->setEasingCurve(QEasingCurve::OutCubic); // same easing choice as Day 12,
                                                              // consistent motion feel across the app
    } else {
        animation->stop();
    }

    animation->setStartValue(currentDisplayValue);
    animation->setEndValue(targetValue);
    animation->start();
}

void GaugeWidget::setDisplayValue(double value) {
    currentDisplayValue = value;
    update(); // schedule repaint — this fires on every animation frame tick,
              // which is exactly why the paintEvent below needs to be cheap
}

QColor GaugeWidget::colorForValue(double value) const {
    double fraction = (value - minValue) / (maxValue - minValue);
    if (fraction > 0.8) return QColor(231, 76, 60);   // red — hot
    if (fraction > 0.6) return QColor(230, 126, 34);  // orange — warm
    return QColor(46, 204, 113);                       // green — normal
}

void GaugeWidget::paintEvent(QPaintEvent * /*event*/) {
    QPainter painter(this);
    painter.setRenderHint(QPainter::Antialiasing);

    int side = qMin(width(), height());
    QRectF gaugeRect((width() - side) / 2.0, (height() - side) / 2.0, side, side);
    gaugeRect.adjust(10, 10, -10, -10); // inset for pen width / breathing room

    // Arc geometry: Qt angles are in 1/16th-degree units, measured counter-
    // clockwise from 3 o'clock — this is the single most confusing part of
    // QPainter arc drawing if you haven't hit it before, worth internalizing
    // now rather than re-deriving it each time you touch this code
    const int startAngle = 225 * 16;  // start position, bottom-left
    const int spanAngle = -270 * 16;  // sweep 270° clockwise (negative = clockwise in Qt's convention)

    // Background arc — full range, dim color, always drawn first
    QPen backgroundPen(QColor(69, 71, 90), 12, Qt::SolidLine, Qt::RoundCap);
    painter.setPen(backgroundPen);
    painter.drawArc(gaugeRect, startAngle, spanAngle);

    // Value arc — proportion of the range currently displayed
    double fraction = (currentDisplayValue - minValue) / (maxValue - minValue);
    int valueSpanAngle = static_cast<int>(spanAngle * fraction);

    QPen valuePen(colorForValue(currentDisplayValue), 12, Qt::SolidLine, Qt::RoundCap);
    painter.setPen(valuePen);
    painter.drawArc(gaugeRect, startAngle, valueSpanAngle);

    // Center text — value + unit
    painter.setPen(QColor(205, 214, 244));
    QFont font = painter.font();
    font.setPointSize(side / 10);
    font.setBold(true);
    painter.setFont(font);
    QString text = QString("%1 %2").arg(currentDisplayValue, 0, 'f', 1).arg(unitText);
    painter.drawText(gaugeRect, Qt::AlignCenter, text);
}
```

### Wiring Into `MainWindow` — Alongside Existing Data Flow

```cpp
auto *gauge = new GaugeWidget(this);
gauge->setRange(0, 100);
gauge->setUnit("°C");

// Feed it from the same signal that already updates deviceModel and charts —
// same "one event, multiple representations" pattern from Day 15/22
connect(mqttWorker, &MqttWorker::messageReceived, this,
        [this, gauge](const QString &topic, const QByteArray &payload) {
    // ... existing parsing to get deviceId, temp ...
    if (deviceId == "device-01") { // e.g., a "primary device" summary gauge
        gauge->setValue(temp); // animates automatically
    }
});
```

### Why This Matters — The Real Engineering Lessons Here

- **`setDisplayValue()` is called every animation frame (roughly 60 times over a 500ms animation)** — this means `paintEvent()` runs that often too during a transition. The arc-drawing math here is deliberately kept cheap (a couple of `drawArc` calls and one text draw, no expensive per-pixel work) — if you were tempted to add gradient fills, multiple shadow layers, or complex path calculations here, that cost gets paid dozens of times per animated transition, not once. This is the same "keep it cheap" lesson as Day 4's event filter caution, now applied to paint performance specifically.
- **Qt's arc angle convention (1/16th-degree units, counter-clockwise-positive from 3 o'clock)** is a genuinely common source of "why is my arc backwards/rotated" bugs — there's no way to intuit this from first principles, it's just a Qt API convention you need to know and will otherwise burn 20 minutes on.
- **Separating `targetValue` (the real, current data value) from `currentDisplayValue` (what's actually rendered, mid-animation)** is the correct pattern for any animated data visualization — it's the same instinct as Day 22's chart trimming, generalized: rendering state and data state are related but distinct concerns, and conflating them causes bugs when updates arrive faster than the animation can settle (a naive implementation would just snap to each new value, defeating the animation entirely, if it didn't separate these).
- **`animate = false` as an explicit option** matters for initial load — you don't want the gauge doing a half-second animated sweep from 0 the very first time a widget appears with historical data; that's a real UX detail easy to overlook until you actually see it happen.
- **Reusing one `QPropertyAnimation` object, stopping and retargeting rather than creating new ones**, is the exact same discipline from Day 12 — worth noting this isn't a coincidence, it's a consistent Qt idiom you'll keep applying anywhere animated values update repeatedly.

### Exercise

1. Add tick marks and min/max labels around the gauge's arc (e.g., small radial lines every 10% of the range, with "0" and "100" labels at the arc's endpoints) — this requires computing points along the arc using `qCos`/`qSin` and the same angle convention, a good forcing function to actually internalize the angle math rather than just trust the copied code.
2. Trigger rapid-fire `setValue()` calls (simulate a burst of MQTT messages arriving faster than the 500ms animation can complete) and confirm the animation retargets smoothly rather than stuttering or restarting jarringly from 0 each time — this exercises the `animation->stop()` + retarget logic under realistic "fast data" conditions.
3. Build a small "System Health" composite gauge: instead of one device's temperature, feed it an aggregate value (e.g., percentage of devices currently online, computed from `deviceModel->rowCount()` vs. a count of online devices) — a good exercise in treating the gauge as a generic reusable component, not something hardwired to "temperature" specifically.

### Key Takeaways

- Custom-painted widgets are the right tool when the visual (a gauge/dial) has no equivalent in QtCharts or standard widgets — reach for raw `QPainter` deliberately, not by default.
- Keep `paintEvent()` cheap when it's driven by animation — it runs on every frame tick, and expensive per-pixel work there compounds fast.
- Qt's arc angle system (1/16th-degree units, CCW-positive from 3 o'clock, negative span = clockwise) is a memorizable convention, not something to re-derive each time.
- Separate the real data value from the currently-rendered/animated value — this is what makes rapid updates retarget smoothly instead of stuttering or skipping the animation.
- Reuse animation objects and support an explicit non-animated path for initial-load scenarios — both are recurring Qt idioms, not one-off tricks specific to this widget.

---

Say "next" for Day 24 (wrapping up Phase 3 with an integration day — pulling serial, MQTT, SQLite, charts, and the gauge together into the actual live-data-backed dashboard, replacing every remaining `DeviceMonitor::simulateReading()` call with real ingestion).