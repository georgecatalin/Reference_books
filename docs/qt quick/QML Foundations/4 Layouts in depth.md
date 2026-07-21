[[Foundations]]

# Day 4 — Layouts in Depth: Anchors vs RowLayout/ColumnLayout/GridLayout

You've used both anchors (Day 2) and layouts (Day 3, `ColumnLayout`/`RowLayout`) without a clear rule for choosing between them. Today: the actual decision criteria, plus `GridLayout` and `StackLayout`, which you haven't seen yet.

## Concept: Anchors vs Layouts — the real distinction

**Anchors** describe a _fixed relationship_ between specific edges of specific items. They're cheap, predictable, and best for: pinning something to a screen edge, centering one item relative to another, or building a component's _internal_ fixed geometry (like Day 2's status header).

**Layouts** (`RowLayout`, `ColumnLayout`, `GridLayout`) manage a _set of children as a group_, redistributing space automatically when the group changes — items added/removed, window resized, content changes size. They're built on `Layout` attached properties (`Layout.fillWidth`, `Layout.preferredWidth`, `Layout.minimumWidth`, `Layout.alignment`, etc.).

**The rule that actually matters:** if you're arranging _a variable or resizable set of siblings_ that should reflow together, use a Layout. If you're positioning _one item relative to another fixed reference_, use anchors. Mixing them on the _same item_ is usually a mistake — a child inside a `RowLayout` should use `Layout.*` properties, not `anchors.*`, because the layout is what's positioning it; anchors on a layout child are ignored or fight the layout engine.

```qml
// WRONG — anchors on a layout child do nothing useful; the layout controls position
RowLayout {
    Rectangle { anchors.left: parent.left; width: 50; height: 50 }
}

// RIGHT — layout children use Layout.* attached properties
RowLayout {
    Rectangle { Layout.preferredWidth: 50; Layout.preferredHeight: 50 }
}
```

## Concept: `Layout.fillWidth` vs `Layout.preferredWidth` vs `implicitWidth`

Three competing signals decide an item's size inside a layout, in this priority order:

1. `Layout.minimumWidth` / `Layout.maximumWidth` — hard constraints, always respected
2. `Layout.preferredWidth` — explicit request
3. `implicitWidth` (the item's natural size, e.g. a `Text`'s content width) — fallback if nothing else specified
4. `Layout.fillWidth: true` — "take all remaining space after others are sized" (competes proportionally with other `fillWidth` siblings)

This is genuinely similar to flexbox's `flex-grow`/`flex-basis` if you've touched CSS — worth mentioning since the mental model transfers directly if you have any web background.

## Annotated example: `GridLayout` for a device telemetry panel

This is directly toward your capstone — a grid of live sensor readings, which needs to reflow as you add/remove device metrics.

```qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts

Item {
    id: root
    width: 500
    height: 300

    // Simulated telemetry — will come from a C++ model on Day 14
    property var metrics: [
        { label: "Temperature", value: "42.3°C" },
        { label: "Humidity", value: "58%" },
        { label: "Voltage", value: "3.31V" },
        { label: "Signal", value: "-67 dBm" },
        { label: "Uptime", value: "4d 12h" },
        { label: "Free RAM", value: "128 MB" }
    ]

    Rectangle {
        anchors.fill: parent
        color: "#181825"
    }

    GridLayout {
        anchors.fill: parent
        anchors.margins: 16
        columns: 3
        rowSpacing: 12
        columnSpacing: 12

        Repeater {
            model: root.metrics

            Rectangle {
                Layout.fillWidth: true
                Layout.fillHeight: true
                Layout.minimumWidth: 120
                radius: 6
                color: "#313244"

                ColumnLayout {
                    anchors.centerIn: parent
                    spacing: 2

                    Label {
                        text: modelData.label
                        color: "#a6adc8"
                        font.pixelSize: 12
                        Layout.alignment: Qt.AlignHCenter
                    }

                    Label {
                        text: modelData.value
                        color: "#cdd6f4"
                        font.pixelSize: 20
                        font.bold: true
                        Layout.alignment: Qt.AlignHCenter
                    }
                }
            }
        }
    }
}
```

**Notice:** `Repeater` inside a `GridLayout` — each repeated item automatically becomes a layout child and gets its `Layout.*` properties honored. This combination (`Repeater` + `GridLayout`/`RowLayout`) is extremely common and is your bridge toward proper model-driven UI on Day 6, where `metrics` becomes a real model instead of a hardcoded JS array.

`GridLayout.columns: 3` with 6 items auto-wraps into 2 rows — resize the window and watch cells resize while maintaining the grid, something you'd have to hand-calculate with anchors.

## Concept: `StackLayout` — for mutually-exclusive views

```qml
import QtQuick.Controls
import QtQuick.Layouts

ColumnLayout {
    anchors.fill: parent

    TabBar {
        id: tabBar
        Layout.fillWidth: true
        TabButton { text: "Overview" }
        TabButton { text: "Devices" }
        TabButton { text: "Logs" }
    }

    StackLayout {
        Layout.fillWidth: true
        Layout.fillHeight: true
        currentIndex: tabBar.currentIndex

        Item { /* Overview page content */ }
        Item { /* Devices page content */ }
        Item { /* Logs page content */ }
    }
}
```

`StackLayout` keeps all children instantiated but shows only `currentIndex` — good when switching is frequent and you want state (like scroll position) preserved. Contrast with `Loader` (later) which destroys/recreates on demand — better when pages are heavy and rarely revisited. This distinction matters for a real app: a live telemetry tab you switch away from and back to constantly should probably be `StackLayout` (state preserved); a rarely-visited "About" or "Advanced Settings" page might be a `Loader` (memory not held for something barely used).

## Exercise

1. Convert the telemetry panel above so `columns` is not hardcoded to 3, but computed from `width` — e.g., `columns: Math.max(1, Math.floor(width / 160))` — and resize the window to confirm it reflows responsively. This is a real technique, not just an exercise gimmick.
2. Build a 3-tab layout (`TabBar` + `StackLayout`) with "Overview," "Devices," "Settings" tabs, where the Settings tab is your Day 3 connection form and Devices tab is your Day 4 telemetry grid.
3. Deliberately mix an `anchors.left` onto a `RowLayout` child and observe/note what happens — confirm for yourself that layout children ignore anchors, rather than taking my word for it.

## Key takeaways

- Anchors = fixed relationship between specific edges; Layouts = manage a reflowing group of siblings. Don't mix `anchors.*` and `Layout.*` on the same child.
- Size priority inside a layout: min/max constraints → `Layout.preferredWidth` → `implicitWidth` → `Layout.fillWidth` distributes leftovers.
- `Repeater` + `GridLayout`/`RowLayout` is the standard pattern for model-driven reflowing grids — and is where Day 6's real models will plug in.
- `StackLayout` keeps hidden pages alive (state preserved, memory held); `Loader` destroys/recreates (memory-light, state lost) — choose based on whether the page is revisited frequently.

Say next for Day 5 — signals, handlers, and where JavaScript in QML should stop being "just JS" and start becoming a code smell.