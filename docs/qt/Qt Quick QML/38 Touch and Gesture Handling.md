[[Advanced]]

## Day 8: Touch and Gesture Handling — Designing for Fingers, Not a Mouse Pointer

### Concept: Touch Isn't "Mouse Events With a Finger" — The UX Assumptions Actually Change

Your entire Widgets dashboard assumed a mouse and keyboard: hover states (Day 6's QSS `:hover`), precise single-pixel clicks, right-click context menus, small click targets (an 8px-tall `QHeaderView` resize handle is fine for a mouse, unusable for a finger). A touchscreen panel breaks several of these assumptions outright, and pretending otherwise produces a dashboard that's technically running on a touchscreen but is actually miserable to use on one. Today is about the real, practical differences, not touch APIs in the abstract.

### The Concrete UX Differences That Actually Matter

1. **No hover state exists.** Any UI feedback that only appeared on `:hover` (Day 6 QSS) is simply invisible to a touch user — there is no "hovering," only "not touching" and "touching." Anything communicated via hover must have a touch-visible equivalent (a persistent visual state, or a tap-triggered reveal).
2. **Touch targets need real minimum size.** The generally-cited comfortable minimum is around 44×44 (device-independent) pixels — a `Button` sized for a mouse-precision Widgets dashboard is very often too small for a finger, especially on a Pi's touchscreen where a slight parallax/calibration offset compounds the problem.
3. **No right-click.** Context menus (Day 5, Widgets) need a touch-equivalent — typically a long-press.
4. **Fat-finger occlusion** — a finger covers more of the screen than a cursor does, including the thing it's touching. Small precise controls stacked closely together (a table's row of tiny action icons) are a genuinely worse experience on touch than on a mouse.

### `MouseArea` — Still Your Primary Tool, Touch-Aware by Default

Here's a genuinely reassuring fact: QML's `MouseArea` **already handles both mouse and touch input** by default — you don't need a separate "TouchArea" for basic tap/click handling. Everything from Day 3 (`Button`'s `onClicked`) already works on a touchscreen with zero changes. Today's actual new material is for **gestures beyond simple taps** — long-press, drag, pinch, swipe — which need explicit handling.

```qml
MouseArea {
    anchors.fill: parent
    onClicked: console.log("tap or click — same handler, works for both")

    // Long-press as the touch equivalent of right-click's context menu
    onPressAndHold: (mouse) => {
        console.log("Long press — show context menu here")
    }

    // Genuinely useful for touch UX: visual feedback that a touch was
    // registered, given there's no hover state to rely on
    onPressed: parent.opacity = 0.7
    onReleased: parent.opacity = 1.0
}
```

### Annotated Code: A Touch-Appropriate `DeviceCard` — Replacing the Small "Details" Button

Day 3's `DeviceCard.qml` had a small `Button` for "Details" — genuinely fine for a mouse, worth reconsidering for touch. The more touch-appropriate pattern: make the **whole card** tappable, with a long-press for secondary actions, rather than packing a small button into an already-modest card.

```qml
import QtQuick
import QtQuick.Controls

Rectangle {
    id: card
    property string deviceId: ""
    property real temperature: 0.0
    property bool online: true

    signal tapped(string deviceId)
    signal longPressed(string deviceId)

    width: 220
    height: 80          // slightly taller than Day 3's version — genuine
                          // touch-target sizing consideration, not arbitrary
    radius: 8
    color: mouseArea.pressed ? "#3a3d54" : "#313244"  // pressed-state
                                                          // feedback REPLACES
                                                          // the hover state
                                                          // that doesn't exist
                                                          // on touch

    Behavior on color { ColorAnimation { duration: 100 } } // quick, subtle —
                                                              // not a slow
                                                              // dashboard-style
                                                              // fade; touch
                                                              // feedback needs
                                                              // to feel instant

    Row {
        anchors.fill: parent
        anchors.margins: 12
        spacing: 12

        Rectangle {
            width: 16; height: 16; radius: 8
            anchors.verticalCenter: parent.verticalCenter
            color: card.online ? "#2ecc71" : "#e74c3c"
        }

        Column {
            anchors.verticalCenter: parent.verticalCenter
            spacing: 4
            Text { text: card.deviceId; color: "#cdd6f4"; font.pixelSize: 18; font.bold: true }
            Text { text: card.temperature.toFixed(1) + " °C"; color: "#a6adc8"; font.pixelSize: 16 }
        }
    }

    // The ENTIRE card is the touch target — no small button to miss
    MouseArea {
        id: mouseArea
        anchors.fill: parent
        onClicked: card.tapped(card.deviceId)

        // Long-press reveals what a right-click menu would have shown
        // in the Widgets version — this is the direct touch equivalent
        // of Day 5's QMenu context menu pattern
        onPressAndHold: card.longPressed(card.deviceId)
    }
}
```

### `PinchArea` — Pinch-to-Zoom on the Temperature Chart

If you port Day 22's chart to QML (a genuine, realistic touchscreen feature — zooming into a time range with two fingers), `PinchArea` handles the gesture recognition:

```qml
import QtQuick

Item {
    id: chartContainer
    width: 400; height: 250

    PinchArea {
        anchors.fill: parent
        pinch.target: chartContent
        pinch.minimumScale: 1.0
        pinch.maximumScale: 3.0

        onPinchFinished: {
            // Snap back or commit to a new zoomed time-range query here —
            // in a real chart, you'd translate chartContent.scale into an
            // actual data time-range and re-request history via ApiClient,
            // not just visually stretch the same fixed dataset
        }

        Item {
            id: chartContent
            anchors.fill: parent
            // your Shape-based chart content (Day 7) goes here — 'scale'
            // and 'x'/'y' get manipulated by PinchArea automatically
        }
    }
}
```

**A real caution, stated plainly**: `PinchArea`'s built-in scale/pan manipulation is a _visual_ transform — it does not, by itself, requery your actual SQLite/history data for the new zoom range. For a real chart, `onPinchFinished` needs to translate the resulting `scale`/pan into an actual time-range and call something like your `ApiClient.fetchHistory()` (Day 5) to get real data at that resolution — treat the gesture as _intent_, not as the data operation itself.

### `Flickable` — Touch-Native Scrolling (Not Just a Scrollbar)

Day 15's Widgets `QScrollArea` had a visible scrollbar the user dragged with a mouse. Touch users expect **direct-manipulation scrolling** — drag the content itself, with momentum/deceleration on release. `ListView`/`GridView` already give you this for free (they're built on `Flickable`), but for custom scrollable content:

```qml
Flickable {
    anchors.fill: parent
    contentHeight: column.height
    boundsBehavior: Flickable.DragOverBounds  // a slight rubber-band-style
                                                 // overscroll — a real,
                                                 // expected touch UX cue that
                                                 // "you've hit the end,"
                                                 // absent from mouse-driven
                                                 // scrolling entirely

    Column {
        id: column
        width: parent.width
        // ... content ...
    }
}
```

### Why This Matters

- **`MouseArea` already working for touch by default** is genuinely good news — most of what you built in Days 1–6 needs zero changes for basic tap interaction; today's material is specifically about _gestures beyond a simple tap_ and _touch-appropriate visual feedback_, not a wholesale rewrite.
- **The absence of hover state is the single most consequential UX difference** from your Widgets dashboard — anything that relied on `:hover` for feedback or discoverability needs an explicit touch-visible equivalent (pressed-state color, as shown above), or it simply won't exist for a touch user.
- **Long-press as the context-menu replacement** is the standard, expected touch idiom — users familiar with any modern touchscreen device already expect this mapping, so it's the correct default rather than inventing a novel gesture.
- **Gesture recognition (`PinchArea`) produces intent, not data** — the real data operation (requerying history at a new resolution) still has to happen explicitly in your handler; don't mistake the visual pinch/zoom transform for an actual completed feature.

### Exercise

1. Retrofit Day 3's `DeviceCard.qml` with today's touch-appropriate pattern (whole-card tap target, pressed-state color feedback replacing hover, long-press for a secondary action) — and specifically identify and fix at least one place in your existing QML where you were relying on a hover-only visual cue that a touch user would never see.
2. Build a `Flickable`-based device list (rather than `ListView`, for this specific exercise) containing 15+ `DeviceCard`s, and confirm real momentum-scroll/overscroll-rubber-band behavior — then compare directly against `ListView`'s built-in scrolling to internalize why `ListView` is almost always the better default (you get `Flickable`'s touch behavior AND delegate recycling, Day 7, together).
3. Add `PinchArea` around a placeholder `Rectangle` (standing in for a future chart), and log the actual `pinch.scale` value during a real pinch gesture on a touchscreen device if you have one available — or via your dev machine's trackpad pinch gesture, which Qt also recognizes as a pinch input, if no touchscreen is available.

### Key Takeaways

- `MouseArea` handles both mouse and touch input identically for basic tap/click interaction — no separate touch-specific control is needed for what you've already built in Days 1–6.
- Hover states have no touch equivalent — every hover-dependent visual cue in your QML needs an explicit touch-visible replacement (typically a pressed-state color change).
- Long-press is the standard, expected touch replacement for right-click context menus — use it as the default secondary-action pattern, not a custom gesture.
- Touch targets need real minimum sizing (~44px) — reconsider small buttons packed into compact Widgets-style layouts when the same content moves to a touchscreen.
- `PinchArea`/gesture recognition produces visual intent (scale/position deltas) — translating that into an actual data operation (re-querying history at a new time resolution) is still your explicit responsibility in the handler.

---

Say "next" for Day 9 (threading with QML — the same worker-object rules from Day 16/Widgets, now consumed from the QML side via signals reaching QML property bindings directly, plus `WorkerScript` for genuinely CPU-heavy QML-side work).