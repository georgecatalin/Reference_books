[[Qt Quick QML]]
## Day 1: The Declarative Model — Why QML Is Not "Widgets With Different Syntax"

### Concept: Declarative vs. Imperative Is the Actual Mental Shift

Every day of your Widgets curriculum, you _constructed_ UI imperatively: `new QPushButton()`, `layout->addWidget()`, `connect()`. QML describes _what the UI should look like given the current state_, and a separate engine (the **scene graph**) figures out how to actually render and update it. This isn't a syntax preference — it changes how you think about UI updates entirely:

- **Widgets**: "when X happens, call `label->setText(newValue)`" — you write the update logic.
- **QML**: "this text's content _is_ `someProperty`" — you declare a binding once, and the engine re-evaluates it automatically whenever `someProperty` changes, for the lifetime of that binding.

This is genuinely closer to reactive UI frameworks you may have encountered conceptually (React's declarative rendering, Vue's reactivity) than to anything in Qt Widgets — worth naming explicitly since it reframes a lot of "how do I update the UI" instincts you built over the last 30 days.

### The Scene Graph — What's Actually Rendering This

QML doesn't use `QPainter`/widget compositing at all. It uses a separate, GPU-accelerated **scene graph** (`QQuickWindow`'s rendering backend, typically OpenGL/Vulkan/Metal/Direct3D depending on platform) — this is _why_ QML is the right choice for touchscreen/embedded displays: it's built for smooth, GPU-driven animation and transitions in a way Widgets' CPU-based `QPainter` compositing isn't. This is also why QML has real hardware requirements Widgets doesn't — a target needs a working GPU driver, which matters for your Pi/BeagleBone deployment planning (flagged again on Day 7).

### Setup

```bash
sudo apt install qt6-declarative-dev qml6-module-qtquick-controls
```

### Annotated Code: Your First Real QML File

`main.qml`:

```qml
import QtQuick
import QtQuick.Controls

// ApplicationWindow is QML's rough equivalent of QMainWindow — a top-level
// window with room for a menu bar, header, footer, and central content
ApplicationWindow {
    id: window                     // 'id' is QML's object-reference mechanism —
                                    // NOT the same as objectName in Widgets;
                                    // this is a compile-time-resolved identifier
                                    // usable anywhere else in this same file
    width: 400
    height: 300
    visible: true
    title: "Day 1 - QML Shell"

    // A property is declared with ': type' or inferred; 'property int'
    // creates genuine, bindable state — the QML analog of a member variable,
    // but one that other elements can bind to directly
    property int clickCount: 0

    Column {
        anchors.centerIn: parent    // 'anchors' — QML's primary layout
                                     // mechanism, distinct from Widgets'
                                     // QVBoxLayout/QHBoxLayout (Day 2 covers
                                     // this properly)
        spacing: 12

        Text {
            // THIS is the actual declarative binding — not "set the text
            // once," but "this text IS ALWAYS clickCount, as a string."
            // Whenever window.clickCount changes, this line re-evaluates
            // automatically. No signal, no slot, no manual update call.
            text: "Clicks: " + window.clickCount
            font.pixelSize: 20
        }

        Button {
            text: "Click me"
            // onClicked is a QML "signal handler" — syntactically similar
            // to a Widgets connect(), but written inline as a property
            // of the object that emits the signal, not a separate
            // connect() call elsewhere
            onClicked: window.clickCount++
        }
    }
}
```

Running it (no separate C++ `main.cpp` needed yet for a pure-QML app):

```bash
qml6 main.qml
```

### The Part That Actually Matters: Property Bindings Are Live, Not One-Time Assignments

This is worth an isolated, concrete example, because it's the single biggest thing to internalize before anything else:

```qml
Rectangle {
    id: box
    width: 100
    height: 100
    color: slider.value > 50 ? "red" : "green"   // a BINDING, not an assignment
}

Slider {
    id: slider
    from: 0
    to: 100
}
```

`box.color` is never explicitly set again anywhere in your code — yet it changes live as the user drags the slider. The expression `slider.value > 50 ? "red" : "green"` is re-evaluated by the engine automatically every time `slider.value` changes, because QML's property system tracks dependencies between bound expressions. **This has no equivalent in Widgets** — the closest analog is Day 12's `QPropertyAnimation`, but that was Qt _animating_ a value over time; this is Qt _deriving_ a value continuously from other live values, which is a different and much more pervasive mechanism in QML.

### `id` vs. Widgets' Object Pointers — A Genuine Difference, Not Just Naming

In Widgets, you reference another widget through an actual C++ pointer (`this->logView->append(...)`). In QML, `id` gives you a **compile-time-resolved reference scoped to the same QML file** (or component) — `window.clickCount` above works because `window` is an `id`, not a variable holding an object reference in the C++ sense. `id`s are **not** strings, can't be reassigned, and are only visible within the file/component where declared — a very different scoping model from Widgets' pointer-based object graph.

### JavaScript Integration — QML's Escape Hatch for Real Logic

Simple bindings and inline expressions cover a lot, but genuine logic needs actual JavaScript, which QML embeds directly:

```qml
Button {
    text: "Reset"
    onClicked: {
        // A full JS block — this is genuinely JavaScript, running in
        // Qt's QML JS engine, not a DSL that merely looks like it
        window.clickCount = 0;
        console.log("Reset triggered at", new Date().toISOString());
    }
}
```

**Important scoping/performance note, said plainly**: JavaScript in QML is fine for small event-handler logic (as above) — it becomes a real problem if you start putting substantial business logic (parsing, data transformation, anything beyond trivial) directly in `.qml` files. That logic becomes hard to test (no `QTest` equivalent for QML JS in isolation, practically speaking) and hard to reuse. The correct pattern, which Day 5 covers properly, is: **substantial logic stays in C++, exposed to QML via context properties or invokable methods — QML's JS is for UI glue, not application logic.** This is the QML analog of Day 9's "keep business logic out of paint code" lesson, now stated for the whole language.

### Why This Matters

- **Bindings are the actual paradigm shift** — everything else in QML (positioners, states, animations) builds on "properties can be expressions that re-evaluate automatically," not manual update calls. Miss this on Day 1 and every later day will feel like memorized syntax instead of a coherent model.
- **The scene graph's GPU dependency is a real deployment constraint**, not a footnote — a headless BeagleBone with no GPU/display genuinely cannot run a QML app, same as it couldn't run Widgets, but QML's performance benefits (the whole reason to choose it) specifically depend on GPU acceleration being present and working, more so than Widgets does.
- **`id` scoping being file/component-local**, not global, prevents a whole class of "which object is this actually referring to" confusion once you have multiple QML files — worth getting right conceptually now rather than being surprised later when an `id` from one file isn't visible in another.
- **Keeping substantial logic out of QML's JavaScript** is a professional discipline decision, not a limitation to work around — it's what keeps a real application's business logic testable and reusable, mirroring the exact same instinct that made your Widgets model/view code testable back on Day 25.

### Exercise

1. Build today's example, then add a second `Text` element bound to a derived expression (e.g., `"Even" if clickCount is even else "Odd"`) — confirm it updates live with no signal/slot code written anywhere.
2. Add a `Rectangle` whose `color` binding depends on `clickCount` crossing a threshold (e.g., red past 5 clicks) — this is the direct QML equivalent of Day 23's gauge color-threshold logic, now expressed as a pure binding instead of C++ code in a `paintEvent`.
3. Deliberately try to reference an `id` from a second, separate `.qml` file loaded via `Loader` (don't worry about getting `Loader` fully right yet — just observe the failure) to concretely see that `id` scoping really is file/component-local, not global — this will matter a lot once Day 4's multi-file structure begins.

### Key Takeaways

- QML is declarative: you describe relationships between properties (bindings), and the engine keeps them live — this is fundamentally different from Widgets' imperative "update on event" model, not just a syntax change.
- Rendering goes through the GPU-accelerated scene graph, not `QPainter`/widget compositing — this is both QML's performance advantage and a real hardware dependency for deployment.
- `id` is a compile-time-scoped reference local to its file/component, not a global object pointer or an `objectName` string.
- JavaScript in QML is for UI glue (event handlers, small expressions) — substantial application logic belongs in C++, exposed to QML deliberately, not written directly in `.qml` files.
- Property bindings (live, continuously-derived values) are the single most important concept to internalize before anything else in this curriculum — everything from Day 2 onward assumes this model is second nature.

---

Say "next" for Day 2 (anchors, positioners, and layouts — QML's actual layout system, mapped explicitly against what you already know from Day 2 of the Widgets curriculum).