[[Foundations]]

# Day 2 — Properties, the Object Tree, and Anchors in Depth

Yesterday you saw bindings work. Today we go under the hood: what a "property" actually is in QML, how the parent/child object tree behaves (it's not quite like C++ object composition), and anchoring rules that will save you hours of layout debugging later.

## Concept: Properties are typed, and QML infers or requires types

Every QML object has **built-in properties** (from its type, e.g. `Rectangle.color`) and can declare **custom properties** with `property <type> name: value`. Unlike a loosely-typed scripting property bag, QML properties are statically typed at declaration — this matters because type mismatches fail at compile/load time, not silently at runtime like plain JS would.

```qml
Item {
    property int deviceCount: 0        // typed
    property string lastSeen: ""       // typed
    property var payload: null         // 'var' = untyped, use sparingly
    property alias childColor: inner.color  // alias: exposes a child's property as your own
}
```

`alias` is important and underused by beginners: it lets a parent component expose an internal child's property to the outside world without duplicating state. You'll use this constantly when building reusable components (Day 11) — e.g., a `DeviceCard.qml` component exposing `alias deviceName: nameLabel.text` so callers can set it like a normal property.

**Default property**: every QML type has one property marked as "default" (usually `data` or `children` for visual items), which is why you can nest items without writing `children: [...]` explicitly:

```qml
Rectangle {
    // These two Text items are implicitly added to Rectangle's default "data" property
    Text { text: "A" }
    Text { text: "B" }
}
```

## Concept: The object tree vs the visual parent — two different hierarchies

This is the single most confusing thing for people coming from C++ composition models. QML has:

1. **QObject parent/child tree** — ownership, for memory management (destroy parent → destroys children). This is what you're used to from Qt C++ (`QObject::setParent`).
2. **Visual parent (`parent` property on `Item`)** — determines **coordinate system**, clipping, and z-ordering. This is _not_ always the same as the QObject tree.

Practically: when you write nested `Item`/`Rectangle`/etc., you're setting both simultaneously — but once you start using `Loader`, `Repeater`, or reparenting items dynamically (`someItem.parent = otherItem`), these two can diverge. Keep this in mind now so it's not a mystery in Week 3.

**`id` is not a property** — it's a compile-time reference, unique per QML document (file scope), resolved at load time. You can't do `item.id` at runtime as a string lookup the way you might expect from a dictionary key. If you need runtime lookup by identifier, that's what `objectName` + `parent.children` traversal or a proper model (Day 6) is for.

## Concept: Anchors — the rules that prevent conflicts

Anchors are relationships between an item's edges and another item's edges (usually its parent or a sibling). Six anchor lines exist: `left`, `right`, `top`, `bottom`, `horizontalCenter`, `verticalCenter`, plus the shortcut `fill` and `centerIn`.

**The rule that prevents 90% of layout bugs**: an item's `width` is determined by _either_ explicit `width:` _or_ two horizontal anchors (e.g. `anchors.left` + `anchors.right`) — never set both, the anchor wins silently and you'll wonder why your `width: 200` did nothing.

```qml
Rectangle {
    id: statusPanel
    anchors.top: parent.top
    anchors.left: parent.left
    anchors.right: parent.right     // left+right anchors ⇒ width is implied
    anchors.margins: 8
    height: 60                      // fine — no vertical anchor conflict
    color: "#313244"
}
```

Anchoring to a **non-parent, non-sibling** item is illegal — QML can only anchor to your parent or to a sibling that shares the same parent. This trips people coming from absolute-positioning backgrounds (or CSS, where you can reference arbitrary ancestors more flexibly).

## Annotated example: a device status header

This builds directly toward your capstone — a header bar showing connection state, device count, and last-update timestamp, all via properties and anchors, no imperative code yet.

```qml
import QtQuick

Item {
    id: root
    width: 400
    height: 60

    property bool connected: true
    property int deviceCount: 12
    property string lastUpdate: "12:04:33"

    Rectangle {
        anchors.fill: parent
        color: "#181825"
    }

    Rectangle {
        id: statusDot
        width: 14
        height: 14
        radius: width / 2
        color: root.connected ? "#a6e3a1" : "#f38ba8"   // binding, not assignment
        anchors.left: parent.left
        anchors.leftMargin: 12
        anchors.verticalCenter: parent.verticalCenter
    }

    Text {
        id: statusLabel
        anchors.left: statusDot.right      // anchored to a SIBLING, valid because same parent
        anchors.leftMargin: 8
        anchors.verticalCenter: parent.verticalCenter
        text: root.connected ? "Connected" : "Disconnected"
        color: "#cdd6f4"
        font.pixelSize: 16
    }

    Text {
        anchors.right: parent.right
        anchors.rightMargin: 12
        anchors.verticalCenter: parent.verticalCenter
        text: root.deviceCount + " devices · updated " + root.lastUpdate
        color: "#a6adc8"
        font.pixelSize: 13
    }
}
```

Notice: **nothing here is imperative**. Every visual fact (`statusDot.color`, `statusLabel.text`) is a live binding to `root.connected`. Change `root.connected` from anywhere — a button, a C++ signal later — and the whole header updates itself with zero extra code. This is the payoff of Day 1's lesson made concrete.

## Exercise

Build `DeviceStatusHeader.qml` as a standalone, reusable component (its own file, not nested in `main.qml`):

1. Move the code above into `DeviceStatusHeader.qml`. In QML, **filename = component name** — this alone makes it usable as `DeviceStatusHeader { }` elsewhere. No registration needed for same-directory use.
2. Add `property alias dotColor: statusDot.color` and use it to prove alias forwarding works — instantiate the component in `main.qml` twice with different `dotColor` overrides and confirm both work independently (proving each instance has its own state, not shared).
3. Deliberately create an anchor conflict — set both `width: 100` and `anchors.left/right` on the same item — and observe what actually happens (does your explicit width apply? which one wins, and why?). Note it in a comment for your own reference.
4. Add a `deviceCount` and `lastUpdate` as constructor-style properties set from `main.qml` when instantiating, e.g. `DeviceStatusHeader { deviceCount: 5; connected: false }`.

## Key takeaways

- Custom properties are typed (`property int`, `property string`, etc.); `alias` exposes a child's property as your own — critical for building reusable components.
- Two hierarchies exist: QObject parent/child (ownership/memory) and visual `parent` (coordinates/clipping/z-order) — usually aligned, not guaranteed to stay that way once you use `Loader`/`Repeater`/reparenting.
- `id` is a compile-time file-scoped reference, not a runtime-queryable string key.
- Anchors: never mix an explicit `width`/`height` with two opposing anchors on the same axis — the anchor silently wins.
- Anchoring only works to your parent or a sibling — not arbitrary ancestors.
- Filename = component name — this is how QML does "no-registration" reuse within a project.

Let me know when you want Day 3 (Qt Quick Controls 2 — buttons, text fields, forms) — or say the word if you want me to batch multiple days ahead like you did in earlier courses.