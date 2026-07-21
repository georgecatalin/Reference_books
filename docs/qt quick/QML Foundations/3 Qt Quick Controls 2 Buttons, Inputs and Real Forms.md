[[Foundations]]

# Day 3 — Qt Quick Controls 2: Buttons, Inputs, and Real Forms

Days 1–2 were raw QML primitives (`Rectangle`, `Text`, `MouseArea`). Today you get the actual UI toolkit — pre-built, themeable, accessible widgets. This is the equivalent of moving from raw `<div>`s to a component library. For `mqtt_monitor`, this is what you'll use for device config panels, connection settings, and control forms.

## Concept: Controls vs raw QML — why both exist

`Rectangle` + `MouseArea` gives you _anything_, but you rebuild focus handling, keyboard nav, hover states, and platform look-and-feel from scratch every time. `QtQuick.Controls` gives you `Button`, `TextField`, `CheckBox`, `ComboBox`, `Slider`, `SpinBox`, etc. — all with built-in states (`pressed`, `hovered`, `focus`, `enabled`), keyboard navigation, and a **style system** (Day 12) so you can reskin without touching logic.

Rule of thumb: use Controls for anything interactive a user directly manipulates (buttons, inputs, toggles). Use raw `Rectangle`/`Canvas`/custom painting for visual display elements (gauges, custom indicators) — you'll mix both constantly.

## Concept: `import` versions and namespace collisions

```qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts
```

Note there's a `Text` in `QtQuick` (raw) and controls sometimes shadow similar names across modules — always double check which module a type comes from when something doesn't behave as expected. In Qt 6, you no longer need explicit version numbers on imports (unlike Qt 5's `import QtQuick.Controls 2.15`) — the tooling resolves against your Qt install version automatically.

## Annotated example: a device connection settings form

```qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts

Item {
    id: root
    width: 360
    height: 320

    property string brokerHost: "localhost"
    property int brokerPort: 1883
    property bool useTls: false
    property string statusMessage: ""

    Rectangle {
        anchors.fill: parent
        color: "#181825"
    }

    ColumnLayout {
        anchors.fill: parent
        anchors.margins: 16
        spacing: 12

        Label {
            text: "MQTT Broker Settings"
            font.pixelSize: 18
            font.bold: true
            color: "#cdd6f4"
        }

        RowLayout {
            Layout.fillWidth: true
            spacing: 8

            Label {
                text: "Host:"
                color: "#a6adc8"
                Layout.preferredWidth: 60
            }

            TextField {
                id: hostField
                Layout.fillWidth: true
                text: root.brokerHost
                placeholderText: "broker address"
                onTextChanged: root.brokerHost = text
            }
        }

        RowLayout {
            Layout.fillWidth: true
            spacing: 8

            Label {
                text: "Port:"
                color: "#a6adc8"
                Layout.preferredWidth: 60
            }

            SpinBox {
                id: portSpin
                from: 1
                to: 65535
                value: root.brokerPort
                editable: true
                onValueChanged: root.brokerPort = value
            }
        }

        CheckBox {
            id: tlsCheck
            text: "Use TLS"
            checked: root.useTls
            onCheckedChanged: root.useTls = checked
            contentItem: Label {
                text: tlsCheck.text
                color: "#cdd6f4"
                leftPadding: tlsCheck.indicator.width + 6
                verticalAlignment: Text.AlignVCenter
            }
        }

        Button {
            text: "Connect"
            Layout.fillWidth: true
            enabled: hostField.text.length > 0
            onClicked: {
                root.statusMessage = "Connecting to " + root.brokerHost
                                     + ":" + root.brokerPort
                                     + (root.useTls ? " (TLS)" : "")
            }
        }

        Label {
            text: root.statusMessage
            color: "#89b4fa"
            wrapMode: Text.WordWrap
            Layout.fillWidth: true
        }
    }
}
```

**Things to notice, deliberately:**

- `onTextChanged: root.brokerHost = text` — this is a **two-way sync pattern** written by hand. It's verbose on purpose today; Day 5 covers signals properly and later you'll see cleaner patterns (`property alias`, or eventually proper C++ backend properties in Day 9 that make this manual wiring unnecessary). Understand the manual version first so the shortcuts later don't feel like magic.
- `SpinBox.editable: true` — without this, users can only click arrows, not type. Easy to forget, commonly wanted for a port number field.
- `CheckBox` custom `contentItem` — the default checkbox label doesn't always match your theme; overriding `contentItem` is the normal way to restyle a single control without a full style plugin (Day 12 covers app-wide theming).
- `Button.enabled` bound to a validation condition (`hostField.text.length > 0`) rather than checked imperatively inside `onClicked` — validate via binding, act via handler. This split (declarative validation, imperative action) is the correct general pattern for forms.

## A critical gotcha: `TextField.text` binding loops

If you write this instead:

```qml
TextField {
    text: root.brokerHost
    onTextChanged: root.brokerHost = text
}
```

this is actually fine here because `text: root.brokerHost` only evaluates once the binding is broken by direct user edits triggering `onTextChanged` — but if you ever bind `text` to something that itself depends on `TextField.text` in the same expression, you get a **binding loop** Qt will warn about at runtime (`QML TextField: Binding loop detected`). Watch your console output for these warnings — they're silent otherwise and just mean "unpredictable ordering," not a crash.

## Exercise

Extend the settings form:

1. Add a `ComboBox` for QoS level (0, 1, 2) bound to a new `property int qosLevel: 0`.
2. Add input validation: if the port field would be outside 1–65535, disable the Connect button and show a red-colored `Label` warning (use a binding on `color` and `visible`, not an `onClicked` check).
3. Replace the manual `onTextChanged: root.x = ...` wiring for **one** field with `property alias` instead (e.g., alias `brokerHost` directly to `hostField.text`) and observe that you can delete the corresponding handler entirely — this proves alias eliminates the manual sync boilerplate you wrote for the others.
4. Style the `Connect` button's `background` property with a custom `Rectangle` (radius, color change on `pressed`) — this is your first taste of Controls customization, expanded properly on Day 12.

## Key takeaways

- Qt Quick Controls gives you interaction/state/accessibility for free; use raw QML for visual-only display elements.
- Qt 6 imports don't need version numbers — Qt 5 code you find online will still show `2.15` etc.; that's legacy syntax you can drop.
- Manual `onXChanged: root.y = x` two-way sync is the "long form" — `property alias` often replaces it entirely when you're just mirroring a single value.
- Split validation (declarative, via bindings on `enabled`/`visible`/`color`) from action (imperative, in `onClicked`) — don't validate inside the click handler.
- Watch the console for binding-loop warnings; they're silent failures otherwise.

Ready for Day 4 (Layouts in depth — anchors vs RowLayout/ColumnLayout/GridLayout, and when each is the right tool) whenever you say next.