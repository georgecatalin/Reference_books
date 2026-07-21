[[C++ QML Integrations and Data]]
# Day 11 — Designing Reusable QML Components Properly

You've been writing standalone `.qml` files as components since Day 2 (filename = component name), but so far mostly by accident — you exposed whatever properties happened to be convenient. Today: designing a component's **public API** deliberately, the way you'd design a class's public interface in C++ — thinking about what should be exposed, what should stay private, and how children compose inside a custom component.

## Concept: A QML component has an implicit public/private boundary — but QML doesn't enforce it

Unlike C++, QML has no `private:` keyword. Every property and function you declare in a component is technically visible to anything that instantiates it. This means **API design discipline is entirely on you** — a common beginner mistake is exposing internal implementation details (an inner `Rectangle`'s `id`, say) instead of a deliberate, minimal set of properties/signals. Treat every property you add to a component's top level as a permanent public contract, the same instinct you already apply to C++ header design.

**Bad — leaks internals, hard to refactor later:**

```qml
// DeviceCard.qml — anything using this can reach in and mess with innerRect directly
Item {
    Rectangle {
        id: innerRect
        anchors.fill: parent
    }
    Text {
        id: labelText
    }
}
```

Nothing stops a caller from writing `myDeviceCard.innerRect.color = "purple"` or `myDeviceCard.labelText.text = "..."` because `id`s are implicitly accessible from outside via the component's default property tree in some contexts — but more importantly, even where it's blocked, it signals you haven't thought about the interface at all.

**Better — deliberate, minimal public surface via `property alias` and typed properties:**

```qml
// DeviceCard.qml
Item {
    id: root
    width: 160
    height: 100

    // ---- Public API (deliberate) ----
    property string deviceName: ""
    property bool online: false
    property alias accentColor: statusBar.color
    signal cardClicked(string deviceName)

    // ---- Internals (not part of the contract) ----
    Rectangle {
        anchors.fill: parent
        color: "#313244"
        radius: 6
    }

    Rectangle {
        id: statusBar
        width: parent.width
        height: 4
        color: root.online ? "#a6e3a1" : "#f38ba8"
    }

    Label {
        anchors.centerIn: parent
        text: root.deviceName
        color: "#cdd6f4"
    }

    MouseArea {
        anchors.fill: parent
        onClicked: root.cardClicked(root.deviceName)
    }
}
```

The difference is intentional: `deviceName`, `online`, `accentColor`, and `cardClicked` are the **contract**. Everything else — the background `Rectangle`, the `Label`, the `MouseArea` — is implementation you're free to rewrite entirely later (swap the background for an image, restyle the status bar) without breaking anyone using `DeviceCard`. This is exactly the encapsulation discipline you already apply in C++; QML just doesn't force it on you, so you have to choose it.

## Concept: Default property and content insertion — building components that accept children

Sometimes a reusable component needs to accept arbitrary child content from the caller, not just fixed properties — think a generic "Panel" wrapper with a title bar that can contain _anything_ inside.

```qml
// Panel.qml
import QtQuick
import QtQuick.Layouts

Rectangle {
    id: root
    color: "#181825"
    radius: 8

    property string title: ""
    default property alias content: contentArea.data   // <-- the key trick

    ColumnLayout {
        anchors.fill: parent
        anchors.margins: 12
        spacing: 8

        Label {
            text: root.title
            font.bold: true
            color: "#cdd6f4"
        }

        Item {
            id: contentArea
            Layout.fillWidth: true
            Layout.fillHeight: true
        }
    }
}
```

Usage — anything nested inside `Panel { }` gets forwarded into `contentArea`:

```qml
Panel {
    title: "Live Telemetry"
    width: 300; height: 200

    // These children are NOT direct children of Panel visually —
    // they're redirected into contentArea via the default property alias
    GridLayout {
        anchors.fill: parent
        columns: 2
        Label { text: "Temp: 42°C" }
        Label { text: "Humidity: 58%" }
    }
}
```

`default property alias content: contentArea.data` is the trick: it redeclares _this component's_ default property (normally `data`, receiving direct children) to instead forward into `contentArea.data`. This is how virtually every real container component in Qt Quick Controls internally works (`ApplicationWindow`'s content area, `Page`'s content, etc.) — now you know the mechanism instead of it being magic.

## Concept: Attached properties — a brief but important pattern you'll recognize

You've already used these without naming them: `Layout.fillWidth`, `ListView.view`, `Component.onCompleted`. These are **attached properties/objects** — a way for a type (`Layout`, `ListView`) to attach extra properties onto _any_ child, without that child needing to know about `Layout` or `ListView` at all. You won't build your own attached properties yet (that's a C++-side `QQmlEngine` registration, somewhat advanced), but recognizing the pattern now means Day 14's model attached roles and Day 21's state machine won't feel unfamiliar.

## Annotated example: a properly-designed `MetricTile` for your telemetry grid

Replacing Day 4's inline delegate with a real, deliberately-designed reusable component:

```qml
// MetricTile.qml
import QtQuick
import QtQuick.Layouts

Rectangle {
    id: root
    radius: 6
    color: "#313244"

    // ---- Public API ----
    property string label: ""
    property string value: ""
    property color valueColor: "#cdd6f4"
    property bool critical: false

    // ---- Internal ----
    Behavior on color { ColorAnimation { duration: 300 } }
    color: root.critical ? "#45151f" : "#313244"

    ColumnLayout {
        anchors.centerIn: parent
        spacing: 2

        Label {
            text: root.label
            color: "#a6adc8"
            font.pixelSize: 12
            Layout.alignment: Qt.AlignHCenter
        }
        Label {
            text: root.value
            color: root.critical ? "#f38ba8" : root.valueColor
            font.pixelSize: 20
            font.bold: true
            Layout.alignment: Qt.AlignHCenter
        }
    }
}
```

Now Day 4's `GridLayout`/`Repeater` code becomes trivially cleaner and, more importantly, has a real interface:

```qml
Repeater {
    model: telemetryModel
    delegate: MetricTile {
        Layout.fillWidth: true
        Layout.minimumHeight: 70
        label: model.label
        value: model.value
        critical: model.critical
    }
}
```

## Exercise

1. Refactor Day 8's `DeviceRow` and `ConnectionStatusHeader` components explicitly: write out, as a comment block at the top of each file, what you consider the deliberate public API (properties + signals) versus internal implementation. Then check — does the actual component leak anything beyond that documented surface? Fix any that do.
2. Build the `Panel.qml` component above and use it to wrap both your Overview grid and Devices list from Day 8, replacing their ad-hoc containers.
3. Add a second `default property alias` scenario: build a `Sidebar.qml` that accepts a `default property alias items: itemColumn.data` so callers can drop arbitrary `Label`/`Button`/whatever directly inside `Sidebar { }` and have them stack vertically without the caller needing to know a `ColumnLayout` exists internally.
4. Deliberately try to reach into a component's internals from outside (e.g., `deviceCard.statusBar.color = "blue"` on a properly-encapsulated card where `statusBar` is not aliased) and confirm QML raises an error — this proves `id`s truly are file-scoped and not part of the external contract, reinforcing Day 2's point with a concrete failure case.

## Key takeaways

- QML has no enforced privacy — API discipline (deciding what's public) is entirely your responsibility, same instinct as C++ header design, just unenforced.
- `property alias` is your main tool for exposing _specific_ internal state deliberately, without exposing the whole internal tree.
- `default property alias someName: innerItem.data` is how container/wrapper components forward arbitrary children into an internal layout — this is the mechanism behind every Controls container you've used.
- Attached properties (`Layout.*`, `ListView.view`, `Component.onCompleted`) are a recognizable pattern now — you'll meet more of them, not build your own yet.
- Treat every top-level property/signal on a component as a permanent contract — design it as deliberately as you'd design a public C++ class interface.

