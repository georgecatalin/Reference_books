[[C++ QML Integrations and Data]]
# Day 13 — Canvas and Custom Painting: Gauges, Sparklines, and Custom Indicators

Controls and rectangles cover forms and lists, but a real telemetry dashboard needs custom visuals — a circular gauge, a sparkline trend graph, a signal-strength arc. Today: two approaches to custom drawing, when to use each, and building the visual primitives your capstone dashboard actually needs.

## Concept: Two ways to draw custom graphics — `Canvas` (QML/JS) vs `QQuickPaintedItem` (C++)

**`Canvas`** — an immediate-mode 2D drawing surface exposed to QML, using a JavaScript API deliberately modeled on the HTML5 Canvas API (if you've ever touched web canvas, this transfers directly). Good for: prototyping, infrequently-redrawn visuals, anything where "good enough performance" is fine.

**`QQuickPaintedItem`** — a C++ base class you subclass, overriding `paint(QPainter*)`. Uses Qt's `QPainter` (which you may already know from Qt Widgets, if you've touched that side of Qt before). Good for: anything redrawn frequently (live telemetry updating multiple times per second), anything performance-sensitive, anything where the drawing logic is complex enough to want real C++ testing/structure.

**Rule for your project**: prototype a new visual with `Canvas` first — it's faster to iterate on since it's QML-only, no rebuild needed. If it ends up redrawing on every MQTT message (which, for a live dashboard, gauges likely will), port it to `QQuickPaintedItem` once the design is settled. Today covers `Canvas`; `QQuickPaintedItem` gets a proper look once you're doing real live data in Phase 2's later days — for now, know it exists and why you'd switch.

## Concept: `Canvas` basics — the `onPaint` handler and `Context2D`

```qml
import QtQuick

Canvas {
    id: canvas
    width: 200
    height: 200

    property real value: 0.65   // 0.0–1.0

    onPaint: {
        var ctx = getContext("2d")
        ctx.reset()

        var cx = width / 2
        var cy = height / 2
        var radius = Math.min(width, height) / 2 - 10

        // Background track
        ctx.beginPath()
        ctx.arc(cx, cy, radius, 0, Math.PI * 2)
        ctx.lineWidth = 12
        ctx.strokeStyle = "#313244"
        ctx.stroke()

        // Value arc
        ctx.beginPath()
        var startAngle = -Math.PI / 2
        var endAngle = startAngle + (Math.PI * 2 * canvas.value)
        ctx.arc(cx, cy, radius, startAngle, endAngle)
        ctx.lineWidth = 12
        ctx.strokeStyle = canvas.value > 0.8 ? "#f38ba8" : "#a6e3a1"
        ctx.lineCap = "round"
        ctx.stroke()
    }

    onValueChanged: requestPaint()   // CRITICAL — see note below
}
```

**`onValueChanged: requestPaint()` is not optional and easy to miss.** Unlike a `Rectangle`'s `color` binding (which QML's scene graph updates automatically), `Canvas` is **imperative** — `onPaint` only runs when you explicitly call `requestPaint()`. Changing a property that `onPaint` reads does _not_ automatically trigger a repaint. This is the single most common "my gauge doesn't update" bug with `Canvas` — you have to manually request repainting whenever anything the paint code depends on changes. This is genuinely different from everything you've learned so far in this course (all declarative, auto-updating) — `Canvas` is a deliberate escape hatch back into imperative drawing, and it doesn't inherit the reactive behavior you've gotten used to.

## Concept: Building a reusable `GaugeArc` component

```qml
// GaugeArc.qml
import QtQuick

Canvas {
    id: root
    width: 160
    height: 160

    // ---- Public API ----
    property real value: 0        // 0.0 to 1.0
    property color trackColor: "#313244"
    property color valueColor: "#a6e3a1"
    property real strokeWidth: 12

    onValueChanged: requestPaint()
    onTrackColorChanged: requestPaint()
    onValueColorChanged: requestPaint()

    onPaint: {
        var ctx = getContext("2d")
        ctx.reset()

        var cx = width / 2
        var cy = height / 2
        var radius = Math.min(width, height) / 2 - strokeWidth

        ctx.beginPath()
        ctx.arc(cx, cy, radius, 0, Math.PI * 2)
        ctx.lineWidth = strokeWidth
        ctx.strokeStyle = trackColor
        ctx.stroke()

        ctx.beginPath()
        var start = -Math.PI / 2
        var end = start + (Math.PI * 2 * Math.max(0, Math.min(1, value)))
        ctx.arc(cx, cy, radius, start, end)
        ctx.lineWidth = strokeWidth
        ctx.lineCap = "round"
        ctx.strokeStyle = valueColor
        ctx.stroke()
    }
}
```

Note `Math.max(0, Math.min(1, value))` — clamping defensively inside the paint code. If live MQTT data ever sends an out-of-range value (a sensor glitch, a bad parse), you want the gauge to clamp visually rather than draw garbage or throw — defensive coding at the rendering boundary, same instinct as validating input at any system boundary in your embedded work.

## Concept: A sparkline — drawing a data series

```qml
// Sparkline.qml
import QtQuick

Canvas {
    id: root
    height: 40

    property var dataPoints: []     // array of numbers
    property color lineColor: "#89b4fa"
    property int maxPoints: 50

    onDataPointsChanged: requestPaint()

    function pushValue(v) {
        var points = dataPoints.slice()
        points.push(v)
        if (points.length > maxPoints)
            points.shift()
        dataPoints = points   // reassigning triggers onDataPointsChanged
    }

    onPaint: {
        var ctx = getContext("2d")
        ctx.reset()
        if (dataPoints.length < 2) return

        var minVal = Math.min.apply(null, dataPoints)
        var maxVal = Math.max.apply(null, dataPoints)
        var range = (maxVal - minVal) || 1

        ctx.beginPath()
        ctx.strokeStyle = lineColor
        ctx.lineWidth = 2

        for (var i = 0; i < dataPoints.length; i++) {
            var x = (i / (dataPoints.length - 1)) * width
            var y = height - ((dataPoints[i] - minVal) / range) * height
            if (i === 0) ctx.moveTo(x, y)
            else ctx.lineTo(x, y)
        }
        ctx.stroke()
    }
}
```

**`dataPoints = points` (reassigning the whole array) rather than `dataPoints.push(v)` directly** — this matters and is worth understanding, not just copying. QML property change detection for `var`/array-typed properties is based on **reassignment**, not in-place mutation. If you called `dataPoints.push(v)` directly, the array _content_ changes but QML has no way to know the property "changed" (no setter was invoked), so `onDataPointsChanged` never fires and your sparkline never repaints. This is the array-equivalent of Day 9's `NOTIFY` lesson — mutation without a proper change signal is invisible to the binding system, in JS objects same as in C++ properties.

## Exercise

1. Build `GaugeArc.qml` and `Sparkline.qml` exactly as above, then wire a `Slider` to drive `GaugeArc.value` and a `Timer` to periodically call `sparkline.pushValue(Math.random())` — confirm both update live.
2. Deliberately break the sparkline by changing `pushValue` to call `dataPoints.push(v)` directly without reassignment — confirm it silently stops updating, reinforcing the mutation-vs-reassignment lesson concretely rather than by description alone.
3. Extend `GaugeArc` with a center `Text` (or `Label` overlay) showing the numeric value as a percentage — this requires layering a `Label` on top of the `Canvas` (they're just siblings with `anchors.centerIn: parent`), not painting text via `ctx.fillText` — a deliberate choice: use `Canvas` for shapes/arcs, use real QML `Text`/`Label` for actual readable text (better font rendering, accessibility, and it's simpler).
4. Add a `strokeWidth` binding to a `SpinBox` so you can tune it live, confirming `onStrokeWidthChanged: requestPaint()` (add this handler) is needed too — every property `onPaint` reads needs its own repaint trigger; there's no way around enumerating them.

## Key takeaways

- `Canvas` is imperative — nothing auto-repaints. Every property your `onPaint` depends on needs an explicit `onXChanged: requestPaint()` — this is the opposite of everything else you've learned about QML's reactivity, and it's the #1 source of "my custom graphic won't update" bugs.
- Array/`var`-typed properties need **reassignment** (`arr = newArr`), not in-place mutation (`arr.push()`), to trigger their change signal — same underlying principle as Day 9's `NOTIFY`, applied to JS collections.
- Prefer `Canvas` for prototyping and infrequent redraws; move to `QQuickPaintedItem` (C++, `QPainter`) once something redraws frequently or needs real performance — coming properly once you're wiring live MQTT data.
- Mix `Canvas` (for arcs/shapes) with real `Text`/`Label` overlays (for actual text) rather than `ctx.fillText` — better rendering quality and simpler code.
- Always clamp/validate values defensively inside paint code — it's a rendering-layer boundary just like any other input boundary.
