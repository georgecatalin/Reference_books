[[Qt Quick QML]]

## Day 7: Performance on Embedded/GPU-Constrained Targets

### Concept: QML's Performance Model Is Fundamentally Different From Widgets' — Profile Differently, Too

Day 28 of the Widgets curriculum taught you to profile CPU time with `perf`. That's still relevant for QML, but QML introduces a **second dimension entirely**: GPU/scene-graph cost, which `perf`'s CPU-centric view doesn't directly show you. A QML app can have low CPU usage and still perform badly on a Pi because the scene graph is doing expensive GPU work every frame — a genuinely different failure mode than anything in your Widgets curriculum, and it needs QML-specific tools to actually see.

### Tool 1: `QSG_RENDER_TIMING` — The Fastest First Check

```bash
QSG_RENDER_TIMING=1 ./mqtt_monitor_qml
```

This environment variable makes the scene graph print per-frame timing breakdowns to stdout — sync time, render time, GPU time — with zero code changes. This is your equivalent of a quick `perf` sanity check, and it's the first thing to run on target hardware before reaching for anything heavier.

### Tool 2: Qt Quick's Built-In Visual Profiling — `QML Profiler` in Qt Creator

Qt Creator's QML Profiler (distinct from the general Performance Analyzer you used in Day 28) shows a timeline specifically of QML-level events: binding evaluations, JS execution, painting, and — critically — **which specific bindings are re-evaluating and how often**. This directly answers the Day 3 exercise's "how often does this binding actually re-evaluate" question, now with real tooling instead of a `console.log` count.

### Realistic Scenario 1: Excessive Item Count — The QML-Specific Version of Day 9's "Don't Rebuild Everything"

Every visible QML `Item` (and its children) is a real cost in the scene graph — this is roughly analogous to Day 22's "unbounded chart points" caution, but it applies to your entire visible UI tree, not just one chart. A `ListView`/`GridView` showing hundreds of `DeviceCard`s **does not** instantiate all of them at once — it uses **delegate recycling**, similar in spirit to Widgets' persistent-widget-map pattern from Day 15, but built into the view type itself:

```qml
ListView {
    anchors.fill: parent
    model: deviceModel
    delegate: DeviceCard { /* ... */ }

    // reuseItems is ON by default in Qt6 for ListView/GridView — delegates
    // scrolled off-screen are recycled (their properties reassigned to new
    // model data) rather than destroyed/recreated. Confirm it's not
    // accidentally disabled, since a common performance regression is
    // someone setting this false while debugging and forgetting to revert.
    reuseItems: true

    // cacheBuffer controls how much extra content (in pixels) is kept
    // instantiated just outside the visible viewport — trades memory for
    // scroll smoothness. Default is reasonable; don't crank it up
    // "just in case" on a memory-constrained embedded target.
    cacheBuffer: 200
}
```

**The practical check, mirroring Day 28's methodology exactly**: don't assume recycling is working — verify it. Add a temporary `Component.onCompleted: console.log("delegate created for", model.deviceId)` inside your delegate, scroll through a long list, and confirm you see far fewer creation messages than total items — if you see one creation message per item every time it scrolls into view, something's disabling recycling.

### Realistic Scenario 2: `Canvas` Is Expensive — Prefer Shapes/Scene Graph Items Where Possible

Day 6's exercise suggested building Day 23's gauge using `Canvas` (QML's `paintEvent`-equivalent, immediate-mode 2D drawing). Worth stating plainly: **`Canvas` is genuinely one of the more expensive QML primitives**, because it renders via a CPU-side `QPainter`-like API into a texture that then gets uploaded to the GPU every time it's invalidated — it does **not** get the same native scene-graph batching/acceleration that plain `Rectangle`/`Image`/`Shape` items get. For a gauge that updates frequently (temperature ticking every second), this is a real, measurable cost difference on a Pi.

The better-performing alternative for simple geometric shapes like a gauge arc is `QtQuick.Shapes`:

```qml
import QtQuick
import QtQuick.Shapes

Shape {
    width: 180; height: 180
    ShapePath {
        strokeWidth: 12
        strokeColor: "#e74c3c"
        fillColor: "transparent"
        capStyle: ShapePath.RoundCap

        PathAngleArc {
            centerX: 90; centerY: 90
            radiusX: 70; radiusY: 70
            startAngle: 225
            sweepAngle: -270 * (gaugeValue / 100)  // same angle math as Day 23's
                                                      // C++ version, same convention
        }
    }
}
```

`Shape` items are scene-graph-native — they participate in the same batched, GPU-accelerated rendering path as every other built-in QML item, rather than falling back to `Canvas`'s CPU-rasterize-then-upload approach. **The practical rule**: reach for `Canvas` only when you genuinely need arbitrary pixel-level drawing that `Shape`'s path/arc/curve vocabulary can't express — for gauges, arcs, and most dashboard-style geometric visuals, `Shape` is both simpler and meaningfully faster.

### Realistic Scenario 3: Binding Loops and Excessive Re-evaluation

A binding that reads a property it also (indirectly) affects creates a **binding loop** — QML detects and warns about direct cases, but indirect loops through several properties can slip through and cause continuous unnecessary re-evaluation, burning CPU for no visible benefit:

```qml
// A subtle, indirect binding loop — width affects x, x's binding reads width
Rectangle {
    width: parent.width - x
    x: parent.width - width   // circular dependency, engine will warn but
                                // the exact re-evaluation behavior can be
                                // surprising; avoid this shape entirely
}
```

Enable verbose binding warnings during development:

```bash
QML_DEBUG=1 QT_LOGGING_RULES="qt.qml.binding.removal.info=true" ./mqtt_monitor_qml
```

### Realistic Scenario 4: Verifying Your Actual `mqtt_monitor` QML Dashboard, Not a Toy Example

Same professional workflow as Day 28, applied to the QML build specifically:

1. Run `QSG_RENDER_TIMING=1` against your real dashboard with live MQTT data flowing and the device list populated with a realistic count, **on the actual Pi**, not just your dev workstation — the GPU cost story is meaningfully different on embedded GPU hardware than on a dev machine's discrete/integrated GPU.
2. If render time is high, check delegate recycling first (`reuseItems`, item creation counts) — this is the most common real cause.
3. If any `Canvas`-based gauges/custom drawing exist, compare against a `Shape`-based rewrite, measured, not assumed.
4. Re-verify with `QSG_RENDER_TIMING=1` again after any fix — same "always re-profile" discipline from Day 28, non-negotiable here too.

### Why This Matters

- **QML performance has a GPU dimension Widgets never asked you to think about** — `perf`'s CPU-only view genuinely won't show you scene-graph/GPU bottlenecks, which is exactly why `QSG_RENDER_TIMING` and Qt Creator's QML Profiler exist as distinct, necessary tools rather than redundant ones.
- **Delegate recycling is QML's built-in version of Day 15's `cardsByDeviceId` map discipline** — the underlying performance concern (don't destroy/recreate UI elements needlessly) is identical; QML just handles it automatically for `ListView`/`GridView` rather than requiring you to hand-roll it, provided you don't accidentally disable it.
- **`Canvas` vs. `Shape` is a genuinely consequential choice for embedded targets specifically** — this is exactly the kind of thing that's invisible on a beefy dev workstation and very visible on a Pi's GPU, mirroring Day 12's `QGraphicsDropShadowEffect` caution from the Widgets curriculum almost exactly.
- **Binding loops are a QML-specific bug category with no Widgets equivalent** — worth knowing the detection tooling exists, since these can silently burn CPU with no obvious visual symptom.

### Exercise

1. Run `QSG_RENDER_TIMING=1` against your Day 4 QML `ListView`-backed dashboard, feeding it realistic simulated MQTT load, and record the baseline render/sync/GPU timings — do this on your dev machine first, then (if you have hardware) on the actual Pi, and compare.
2. Build the Day 6 exercise's gauge twice — once with `Canvas`, once with `Shape` — and compare `QSG_RENDER_TIMING` output between the two under rapid `Behavior`-animated value changes, on the same hardware, to get a real measured answer rather than trusting today's claim on faith.
3. Deliberately disable `reuseItems` on your device `ListView`, add the `Component.onCompleted` creation-counter from today, scroll through a populated list, and confirm you see a spike in delegate creation events — then re-enable it and confirm the count drops back down, making the recycling behavior concrete rather than assumed.

### Key Takeaways

- QML performance profiling needs GPU-aware tools (`QSG_RENDER_TIMING`, Qt Creator's QML Profiler) in addition to CPU tools like `perf` — a low-CPU, high-GPU-cost app is a real and common QML failure mode Widgets never presented.
- Delegate recycling (`reuseItems`, on by default for `ListView`/`GridView`) is the automatic QML equivalent of Day 15's manual widget-reuse map — verify it's actually active rather than assuming, especially after any debugging session that might have toggled it off.
- Prefer `Shape` over `Canvas` for geometric drawing (gauges, arcs) — `Shape` is scene-graph-native and batches/accelerates like any built-in item; `Canvas` falls back to a CPU-rasterize-then-upload path that's genuinely more expensive, especially on embedded GPUs.
- Binding loops are a QML-specific performance/correctness issue with no direct Widgets analog — know the detection environment variables exist before you need them.
- The same re-profile-after-every-fix discipline from Day 28 applies here — measure, don't assume, especially since dev-workstation numbers can mislead you about actual embedded-target behavior.

---

Say "next" for Day 8 (touch/gesture handling — `MultiPointTouchArea`, `PinchArea`, swipe gestures, and the real UX differences between designing for touch versus the mouse/keyboard-oriented Widgets dashboard you already built).