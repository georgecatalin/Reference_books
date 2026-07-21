[[Advanced Qt Quick]]

# Day 20 — Live Data Visualization: Qt Charts for Time-Series Telemetry

Day 13's `Sparkline` was a legitimate hand-rolled tool — good for a tiny inline trend indicator. Real telemetry dashboards need proper axes, legends, zooming, multiple series, and correct handling of continuously-appended time-series data without leaking memory or degrading performance over hours of runtime. Today: Qt Charts, done the way a production dashboard actually needs it.

## Concept: Qt Charts — another add-on module, QML-native

```cmake
find_package(Qt6 REQUIRED COMPONENTS Quick Charts)
target_link_libraries(appMonitor PRIVATE Qt6::Quick Qt6::Charts)
```

```qml
import QtQuick
import QtCharts
```

Qt Charts gives you `ChartView` as a container, with series types (`LineSeries`, `ScatterSeries`, `BarSeries`, etc.) and axis types (`ValueAxis`, `DateTimeAxis`, `CategoryAxis`) — all QML-native `QObject`-based types following the exact same property/signal patterns you already know.

## Concept: A basic live line chart

```qml
import QtQuick
import QtCharts

ChartView {
    id: chart
    width: 500; height: 300
    theme: ChartView.ChartThemeDark
    antialiasing: true
    legend.visible: true

    ValueAxis {
        id: axisY
        min: -20
        max: 60
        titleText: "Temperature (°C)"
    }

    DateTimeAxis {
        id: axisX
        format: "hh:mm:ss"
        titleText: "Time"
    }

    LineSeries {
        id: tempSeries
        name: "Temperature"
        axisX: axisX
        axisY: axisY
    }
}
```

**`DateTimeAxis` expects milliseconds-since-epoch on the X values** — this is a real gotcha: `append(x, y)` on a series takes plain numbers, and if you pass seconds where Qt Charts expects milliseconds, your points cluster at the epoch origin instead of spreading across the intended time range. Always multiply Unix-epoch-seconds by 1000 when feeding a `DateTimeAxis`-backed series.

## Concept: Appending points efficiently — and the memory leak you must not create

```cpp
// In MqttManager, or a dedicated ChartDataManager
Q_INVOKABLE void appendTemperaturePoint(QObject *series, qint64 epochSeconds, double temperature)
{
    auto *lineSeries = qobject_cast<QXYSeries*>(series);
    if (!lineSeries) {
        qWarning() << "appendTemperaturePoint: not a valid series object";
        return;
    }
    lineSeries->append(static_cast<qreal>(epochSeconds) * 1000.0, temperature);

    // CRITICAL for a long-running dashboard — trim old points, don't accumulate forever
    const int maxPoints = 300;
    if (lineSeries->count() > maxPoints)
        lineSeries->remove(0);
}
```

**This is the single most important lesson for a dashboard meant to run for hours or days (exactly your use case):** a series that only ever appends and never trims will grow its point count unboundedly. This isn't a leak in the classic C++ sense (no dangling memory), but it's a real, slow performance degradation — rendering cost and memory both climb over a multi-day runtime on a Pi with limited resources, and it will eventually visibly stutter. Always cap and trim. `remove(0)` for a sliding window is the simplest correct approach; for very high-frequency data, batching removals (trim every N appends rather than every single one) reduces overhead further.

## Concept: Calling from QML — passing the series object into C++

```qml
LineSeries {
    id: tempSeries
    name: "Temperature"
    axisX: axisX
    axisY: axisY
}

Connections {
    target: MqttManager
    function onTelemetryReceived(deviceId, epochSeconds, temperature) {
        if (deviceId === currentDeviceId)
            ChartDataManager.appendTemperaturePoint(tempSeries, epochSeconds, temperature)
    }
}
```

Passing `tempSeries` (a QML-declared object) _into_ a C++ `Q_INVOKABLE` method as a `QObject*` argument is a legitimate and common pattern — QML objects are real `QObject`s underneath, C++ can operate on them directly via `qobject_cast`. This is a cleaner architecture than trying to expose chart-manipulation logic entirely from QML-side JS (Day 5's lesson about where JS belongs, applied here: "trim to 300 points, handle multi-series routing" is real logic, better placed in C++ where you can unit test the trimming behavior).

## Concept: Multi-series — one chart, several devices

```qml
ChartView {
    id: chart
    theme: ChartView.ChartThemeDark
    legend.visible: true

    ValueAxis { id: axisY; min: -20; max: 60 }
    DateTimeAxis { id: axisX; format: "hh:mm" }

    Repeater {
        model: MqttManager.devices   // reuse Day 14's model!
        LineSeries {
            name: model.deviceId
            axisX: axisX
            axisY: axisY
            // populated separately as each device's readings arrive
        }
    }
}
```

Note: **`Repeater` inside a `ChartView` to generate one series per device** reuses your existing `DeviceListModel` — the same model driving your `ListView` device list (Day 14/16) also drives which chart series exist. This is another instance of the Model/View discipline paying off: one source of truth for "what devices exist," consumed by two completely different visual representations (list rows and chart legend entries) without duplication.

## Concept: `Repeater` + dynamically-created series — a real gotcha with object identity

Because `Repeater`-created `LineSeries` objects are recreated whenever the underlying model changes structurally (a device added/removed), **you cannot hold a long-lived C++-side raw pointer to one of these series across a model change** — if a device is removed and re-added, you get a _new_ `LineSeries` QObject, not the same one. If your architecture needs stable, addressable series per device that survive independently of the device list's insert/remove churn, consider managing series lifecycle explicitly in C++ instead of via `Repeater` (a `QMap<QString, QXYSeries*>` keyed by device ID that you add/remove to explicitly) — flag this as a design decision to make deliberately once you're past prototyping, not something to default into.

## Exercise

1. Build the basic live line chart above, wire it to Day 16's live MQTT temperature data via `ChartDataManager.appendTemperaturePoint`, and confirm points stream in with correct time-axis positioning (double-check you multiplied by 1000).
2. Deliberately remove the `maxPoints` trimming logic, let it run for several minutes while publishing rapid test messages, and watch memory/CPU climb in a system monitor — then re-add trimming and confirm it plateaus. Concrete proof of the lesson, not just taking it on faith.
3. Build the multi-series `Repeater` version, confirm adding a new device (a fresh MQTT publish to a never-seen device ID) automatically adds a new chart legend entry and series — again, zero manual "add a chart line" step, all driven by the same model.
4. Add a `ValueAxis.max`/`min` auto-scaling behavior: bind them to the running min/max of visible data (computed in C++, exposed as properties) rather than hardcoded `-20`/`60`, and note in a comment why hardcoded axis bounds are fine for a known sensor range (temperature) but wrong for something like RSSI or a custom metric with unknown bounds.

## Key takeaways

- Qt Charts is an add-on module (`Qt6::Charts`) with QML-native types (`ChartView`, `LineSeries`, `ValueAxis`, `DateTimeAxis`) following familiar property/signal patterns.
- `DateTimeAxis` expects **milliseconds** since epoch — a silent, common bug if you pass seconds directly.
- Unbounded point accumulation on a long-running dashboard is a real, slow-onset performance problem — always cap and trim (`remove(0)` for a sliding window), especially critical on Pi-class hardware.
- Passing a QML-declared object (like a `LineSeries`) into a `Q_INVOKABLE` C++ method as a `QObject*` is legitimate and lets you keep trimming/routing logic testable in C++ rather than scattered in QML JS.
- `Repeater`-generated series are recreated on model structural changes — don't hold long-lived C++ pointers across those changes; manage series explicitly in a `QMap` if you need stability independent of list churn.
- Reusing `DeviceListModel` to drive both the device list _and_ chart series generation is the Model/View discipline paying off again — one source of truth, multiple views.
