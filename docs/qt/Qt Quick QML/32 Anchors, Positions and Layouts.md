[[Qt Quick QML]]

## Day 2: Anchors, Positioners, and Layouts — QML's Actual Layout System

### Concept: Three Different Layout Mechanisms, Not One — And None of Them Is Widgets' `QVBoxLayout`

QML has **three genuinely different** ways to arrange elements, and picking the right one for the job matters — conflating them is a common early mistake:

1. **Anchors** — bind an element's edges to another element's edges (`anchors.left: parent.left`). This is QML's most fundamental, most-used mechanism — closer to CSS's relative positioning than anything in Widgets.
2. **Positioners** (`Row`, `Column`, `Grid`, `Flow`) — automatically arrange **children** in a line/grid, similar in spirit to `QHBoxLayout`/`QVBoxLayout`, but implemented as actual QML item types you nest content inside, not a layout object attached to a container.
3. **Layouts** (`RowLayout`, `ColumnLayout`, `GridLayout` — from `QtQuick.Layouts`) — the closest real analog to Widgets' `QHBoxLayout`/`QGridLayout`, supporting size policies and stretch-like behavior (`Layout.fillWidth`, `Layout.preferredHeight`), and the right choice specifically when you need Widgets-style responsive resizing behavior.

**The practical rule, from experience**: use anchors for "this sits relative to that specific other thing" (a label pinned to its icon, a button pinned to the bottom-right of its parent). Use `Layouts` when you need genuine Widgets-style responsive resizing (the whole row should redistribute space on window resize). Use plain positioners only for simple, static, evenly-spaced arrangements. Reaching for the wrong one is the single most common source of "why won't this resize correctly" QML bugs.

### Anchors — The Mechanism You'll Use Constantly

```qml
import QtQuick

Rectangle {
    width: 300
    height: 200
    color: "#1e1e2e"

    Rectangle {
        id: header
        anchors.top: parent.top
        anchors.left: parent.left
        anchors.right: parent.right   // left + right anchored = stretches to fill width
        height: 40
        color: "#313244"

        Text {
            anchors.centerIn: parent   // shorthand for horizontalCenter + verticalCenter
            text: "Device Status"
            color: "white"
        }
    }

    Rectangle {
        id: content
        anchors.top: header.bottom     // anchored to ANOTHER element, not just parent —
                                         // this is the genuinely powerful part; anchors
                                         // form a dependency graph across sibling items
        anchors.bottom: parent.bottom
        anchors.left: parent.left
        anchors.right: parent.right
        anchors.margins: 8              // shorthand for margins on all anchored edges
        color: "#181825"
    }
}
```

**The critical constraint on anchors, stated plainly**: you can only anchor an item to its **parent** or its **siblings** (items sharing the same parent) — never to a child, and never to an unrelated item elsewhere in the tree. This is a real, hard rule, not a style guideline; violating it either fails silently (binding never resolves) or produces a QML warning at runtime about an invalid anchor. Keep the anchor relationships within one parent/sibling group in your head as you nest.

### `anchors.fill` — The One You'll Type Constantly

```qml
Rectangle {
    anchors.fill: parent   // shorthand for top+bottom+left+right all bound to parent —
                             // the QML equivalent of "this widget fills its container,"
                             // extremely common for a root content area
}
```

### Positioners — Simple, Automatic Arrangement

```qml
import QtQuick

Column {
    spacing: 8
    anchors.centerIn: parent

    Rectangle { width: 100; height: 30; color: "red" }
    Rectangle { width: 100; height: 30; color: "green" }
    Rectangle { width: 100; height: 30; color: "blue" }
    // Each child is automatically stacked vertically, 8px apart —
    // no explicit anchors needed between them at all
}
```

`Row`, `Grid`, and `Flow` work analogously (horizontal, grid, and wrapping-flow arrangement respectively). Positioners are genuinely simple — they don't support Widgets-style stretch factors or size policies; a child's size is just its own declared size, and the positioner only handles _position_, not _sizing behavior_.

### `Layouts` — The Real Widgets Equivalent, With Size Policies

```qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts

ApplicationWindow {
    width: 500
    height: 300
    visible: true

    RowLayout {
        anchors.fill: parent
        spacing: 8

        Rectangle {
            color: "#313244"
            Layout.preferredWidth: 150     // analogous to a Fixed-ish size policy
            Layout.fillHeight: true         // this item wants full available height
        }

        Rectangle {
            color: "#181825"
            Layout.fillWidth: true          // THIS is the Expanding equivalent —
            Layout.fillHeight: true          // takes remaining space, exactly like
                                              // Day 2 Widgets' QSizePolicy::Expanding
        }
    }
}
```

**The direct mapping worth memorizing, since it maps 1:1 onto what you already know**:

|Widgets (Day 2)|QML `Layouts` equivalent|
|---|---|
|`QSizePolicy::Fixed`|`Layout.preferredWidth`/`preferredHeight` set, `fillWidth`/`fillHeight: false`|
|`QSizePolicy::Expanding`|`Layout.fillWidth: true` / `Layout.fillHeight: true`|
|Stretch factor in `QHBoxLayout`|`Layout.fillWidth: true` on multiple children — but for _proportional_ division specifically, add explicit `Layout.preferredWidth` ratios, or reach for weighted `Layout.fillWidth` combined with `Layout.preferredWidth` hints|
|`QSplitter`|`SplitView` (from `QtQuick.Controls`) — user-draggable panel resizing, same concept|

### Annotated Code: A Real Dashboard Shell — Device List + Status Panel

This is today's actual deliverable — the direct QML analog of Day 2's Widgets dashboard shell, letting you compare the two approaches on the same real layout.

`DashboardShell.qml`:

```qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts

ApplicationWindow {
    width: 900
    height: 600
    visible: true
    title: "mqtt_monitor - QML Dashboard Shell"

    // SplitView is the direct analog of Day 2's QSplitter — user-draggable,
    // supports nesting, same mental model as before
    SplitView {
        anchors.fill: parent
        orientation: Qt.Horizontal

        // Left panel: device list
        Rectangle {
            SplitView.preferredWidth: 200
            SplitView.minimumWidth: 120
            color: "#181825"

            ListView {
                anchors.fill: parent
                anchors.margins: 4
                model: ["device-01 (BeagleBone)", "device-02 (RPi 4)", "device-03 (RPi Zero)"]
                delegate: Text {
                    text: modelData   // 'modelData' — the implicit variable name
                                       // for the current item when the model is
                                       // a plain list (Day 4 covers real models properly)
                    color: "#cdd6f4"
                    padding: 6
                }
            }
        }

        // Right side: nested vertical split — status table area + log area,
        // exactly mirroring Day 2's Widgets structure
        SplitView {
            SplitView.fillWidth: true
            orientation: Qt.Vertical

            Rectangle {
                SplitView.preferredHeight: 350
                color: "#1e1e2e"
                Text {
                    anchors.centerIn: parent
                    text: "Status table area (Day 4+ will make this real)"
                    color: "#6c7086"
                }
            }

            Rectangle {
                SplitView.fillHeight: true
                color: "#11111b"
                Text {
                    anchors.top: parent.top
                    anchors.left: parent.left
                    anchors.margins: 8
                    text: "[12:03:41] device-01 connected\n[12:03:42] device-01 published telemetry"
                    color: "#a6e3a1"
                    font.family: "monospace"
                }
            }
        }
    }
}
```

### Why This Matters

- **Anchors can only reference parent/siblings** — this constraint shapes how you structure nested QML; if two elements need to anchor to each other but live in different parents, you need to restructure the tree (move them to share a parent) rather than fight the constraint.
- **Positioners vs. `Layouts` is a genuine, consequential choice, not a stylistic one** — positioners give you simple static stacking with zero resize-awareness; `Layouts` gives you real Widgets-equivalent responsive behavior. Picking a `Column` where you actually needed a `ColumnLayout` is the single most common "why doesn't this resize properly" QML bug for people coming from Widgets.
- **`SplitView` is a near-exact conceptual match for `QSplitter`** — this is one of the more comforting mappings today, since the mental model transfers directly, just the property names differ (`SplitView.preferredWidth` vs. `setStretchFactor`).
- **`anchors.fill: parent` will appear in nearly every QML file you write** — it's the default "take up all available space" idiom, equivalent in spirit to a layout with zero margins filling its container in Widgets.

### Exercise

1. Rebuild today's `DashboardShell.qml` using plain `Column`/`Row` positioners instead of `SplitView`/`Layouts`, then resize the window and observe what breaks (hint: nothing resizes proportionally — items stay their declared size) — this is meant to make the positioner-vs-Layout distinction concrete rather than assumed.
2. Add a third nested panel to the vertical `SplitView` (a "connection settings" strip above the log), giving it a fixed `SplitView.preferredHeight` while the log area keeps `SplitView.fillHeight: true` — confirm only the log area actually grows/shrinks as you resize the window.
3. Take one `Rectangle` from today's layout and try anchoring it to a `Rectangle` that is NOT its sibling (nest one inside a `Column` two levels deep, and try anchoring an unrelated top-level item to it) — observe and read the actual QML runtime warning this produces, so you recognize this specific failure mode when you hit it for real later.

### Key Takeaways

- QML has three distinct layout mechanisms — anchors (relative positioning to parent/siblings), positioners (simple automatic stacking), and `Layouts` (genuine Widgets-equivalent responsive behavior with fill/preferred sizing) — and choosing the right one for the job matters, they're not interchangeable.
- Anchors can only target a parent or sibling item — never a child or an unrelated item elsewhere in the tree.
- `Layout.fillWidth`/`fillHeight` is QML's `QSizePolicy::Expanding` equivalent; `Layout.preferredWidth`/`Height` is the `Fixed`-size equivalent — this mapping directly reuses Day 2 Widgets intuition.
- `SplitView` is essentially `QSplitter` under a different name — one of the more direct 1:1 conceptual carryovers from the Widgets curriculum.
- `anchors.fill: parent` is QML's most common idiom — expect to write it constantly.

---

Say "next" for Day 3 (components, reusable custom QML types, and JavaScript integration done properly — the QML equivalent of Day 11's composite `DeviceCard` widget, plus the Signal/property patterns that make components genuinely reusable).