[[Foundations]]

# Day 6 — Models and Views: `ListModel`, `ListView`, and Delegates

Day 4's `metrics` array and Day 5's hardcoded device data were placeholders. Today: the real model/view pattern QML is built around — this is the single most important day for `mqtt_monitor`, since your entire device list and telemetry history will be built on this.

## Concept: Model/View/Delegate — three distinct roles

- **Model** — the data (a `ListModel`, a JS array, a C++ `QAbstractListModel` later)
- **View** — the container that arranges items (`ListView`, `GridView`, `TableView`, `Repeater`)
- **Delegate** — the template used to visually render _each_ item in the model

This separation is the same principle as MVC/MVVM you've likely encountered conceptually — the view has no idea what the data actually is, it just knows "for each row, instantiate this delegate and bind it to that row's fields." This is what makes it trivial to later swap a hardcoded `ListModel` for a live C++ model (Day 14) without touching a single line of UI code — the delegate doesn't change, only the model source does.

## Concept: `ListModel` — QML's built-in dynamic model

```qml
ListModel {
    id: deviceModel
    ListElement { deviceId: "esp32-04"; rssi: -67; online: true }
    ListElement { deviceId: "rpi-monitor-01"; rssi: -54; online: true }
    ListElement { deviceId: "beaglebone-sensor"; rssi: -81; online: false }
}
```

Each `ListElement`'s fields become properties accessible in the delegate via `model.deviceId`, `model.rssi`, etc. — or, more concisely, directly by name in scope (`deviceId`, `rssi`) if there's no naming conflict.

**Mutating a `ListModel` at runtime** — this is the part beginners miss, since `ListElement` looks static:

```qml
deviceModel.append({ deviceId: "new-node-09", rssi: -70, online: true })
deviceModel.setProperty(0, "online", false)   // update by index
deviceModel.remove(2)                          // remove by index
```

This matters enormously for MQTT: when a device publishes a status update, you'll call `setProperty` on the matching row — and because the view is bound to the model, the UI updates itself. No manual "redraw the list" step.

## Concept: `ListView` + delegate

```qml
import QtQuick
import QtQuick.Controls

Item {
    width: 400
    height: 300

    ListModel {
        id: deviceModel
        ListElement { deviceId: "esp32-04"; rssi: -67; online: true }
        ListElement { deviceId: "rpi-monitor-01"; rssi: -54; online: true }
        ListElement { deviceId: "beaglebone-sensor"; rssi: -81; online: false }
    }

    ListView {
        anchors.fill: parent
        model: deviceModel
        spacing: 4
        clip: true   // IMPORTANT — see note below

        delegate: Rectangle {
            width: ListView.view.width
            height: 48
            color: online ? "#313244" : "#1e1e2e"
            radius: 4

            Row {
                anchors.verticalCenter: parent.verticalCenter
                anchors.left: parent.left
                anchors.leftMargin: 12
                spacing: 16

                Rectangle {
                    width: 10; height: 10; radius: 5
                    color: online ? "#a6e3a1" : "#f38ba8"
                    anchors.verticalCenter: parent.verticalCenter
                }

                Label { text: deviceId; color: "#cdd6f4"; font.bold: true }
                Label { text: rssi + " dBm"; color: "#a6adc8" }
            }

            MouseArea {
                anchors.fill: parent
                onClicked: deviceModel.setProperty(index, "online", !online)
            }
        }
    }
}
```

**`clip: true` on `ListView`** — without it, delegates that overflow the view's bounds render outside it visibly (they're not clipped by default, unlike most frameworks you may have assumed clip-by-default from). Always set this on scrollable containers unless you specifically want overflow visible.

**`index` inside a delegate** is automatically available — it's the model row number, useful for `setProperty(index, ...)` exactly as shown, or for alternating row colors (`color: index % 2 === 0 ? "#1e1e2e" : "#242438"`).

**`ListView.view.width`** inside the delegate — a delegate doesn't automatically know its container's width; `ListView.view` is an attached property giving you a handle back to the containing `ListView`. This is a very common "why didn't my delegate fill the width" bug.

## Concept: `Component.onCompleted` — populate a model when it appears

For pulling in "starting" data (later replaced by real MQTT data):

```qml
ListModel {
    id: deviceModel
    Component.onCompleted: {
        append({ deviceId: "esp32-04", rssi: -67, online: true })
        append({ deviceId: "rpi-monitor-01", rssi: -54, online: true })
    }
}
```

`Component.onCompleted` fires once, after the object and its children finish initializing — the QML equivalent of a constructor-body-after-member-init-list moment. Useful for any one-time setup that can't be expressed as a static declaration.

## Concept: `GridView` — same pattern, grid layout instead of vertical list

```qml
GridView {
    anchors.fill: parent
    model: deviceModel
    cellWidth: 150
    cellHeight: 100
    clip: true
    delegate: /* same idea, sized to cellWidth/cellHeight */
}
```

Use `GridView` for card-style dashboards (device tiles), `ListView` for row-style data (logs, event history).

## Exercise

1. Rebuild Day 4's telemetry `Repeater` example using `ListModel` + `GridView` instead of a hardcoded JS array + `Repeater`. Confirm you can `append`/`setProperty` at runtime and watch the grid update live.
2. Add a `Timer` (`interval: 2000; repeat: true; running: true`) that, on each tick, picks a random row and mutates its `rssi` by ±5 via `setProperty` — this simulates live telemetry updates and is the closest you've gotten yet to what real MQTT message handling will look like structurally (Day 16).
3. Deliberately remove `clip: true` from a `ListView` inside a smaller `Item`, add enough rows to overflow it, and observe the visual bleed — confirm for yourself why the flag matters rather than taking it on faith.
4. Add a `Connections`-free simple filter: a `TextField` above the `ListView` that filters visible rows by `deviceId` substring match. (Hint: you'll need a second model or a `DelegateModel`/`filterOnGroup` — if this feels like too much machinery for Day 6, a simpler valid approach is binding delegate `visible: deviceId.indexOf(filterField.text) !== -1`; note in a comment why this isn't ideal for large datasets — real filtering comes with `QSortFilterProxyModel` in Day 14.)

## Key takeaways

- Model/View/Delegate separation means swapping data sources later (Day 14: real C++ models) requires zero UI rewrites — the delegate stays identical.
- `ListModel.append/setProperty/remove` mutate at runtime and the bound view updates automatically — no manual refresh step, ever.
- `clip: true` is not default — always set it on scrollable/bounded containers.
- `index` and `ListView.view` are implicitly available inside delegates — commonly needed, easy to forget exist.
- `Component.onCompleted` is your one-time initialization hook, analogous to post-construction setup.
- `GridView` for tile/card layouts, `ListView` for row-style data — same underlying pattern.

Say next for Day 7 — States, Transitions, and property animations, which is where your UI stops looking static and starts feeling like a real product.
