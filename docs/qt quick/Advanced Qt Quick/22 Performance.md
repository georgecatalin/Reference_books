[[Advanced Qt Quick]]
# Day 22 — Performance: Profiling, Binding Loops, Caching, and Async Loading

Everything so far has been "does it work correctly." Today: "does it stay smooth for hours on a Raspberry Pi under real MQTT load" — a genuinely different question, and the one that determines whether your capstone is a demo or a deployable product.

## Concept: Qt Quick Profiler — measure before you guess

Don't optimize blind. Qt Creator ships a QML/Qt Quick Profiler (Analyze → QML Profiler) that records, per-frame: binding evaluation time, painting time, JavaScript execution time, and scene graph work. Run it against your actual dashboard under simulated MQTT load _before_ touching anything — the same discipline you already apply to C++ profiling (you wouldn't optimize a hot loop without `perf` telling you it's actually hot).

```bash
# Command-line alternative if you're not in Qt Creator's GUI
QSG_RENDERER_DEBUG=render ./appMonitor
QML_COMPAT_RESOLVE_URLS_ON_ASSIGNMENT=1 QSG_VISUALIZE=overdraw ./appMonitor
```

`QSG_VISUALIZE=overdraw` is worth knowing specifically — it color-codes areas of your UI redrawn multiple times per frame (heavily overlapping semi-transparent rectangles, stacked unnecessary backgrounds), visually surfacing exactly the kind of accidental waste that accumulates in a UI built incrementally over 20+ days like yours has been.

## Concept: Binding loops — the correctness AND performance problem

You saw a warning about these back on Day 3. Now the performance angle: a binding loop (property A's binding reads property B, which reads property A) doesn't just produce unpredictable values — the engine detects and breaks the cycle, but the detection and repeated re-evaluation itself costs real per-frame overhead if it's happening continuously rather than once.

```qml
// A SUBTLE, indirect binding loop — not the obvious direct A-reads-B-reads-A case
Rectangle {
    id: box
    width: parent.width - sibling.width   // depends on sibling.width
}
Rectangle {
    id: sibling
    width: box.width > 100 ? 50 : 80      // depends on box.width — CYCLE
}
```

Neither line looks obviously wrong in isolation — this is exactly why the Profiler matters more than code review alone for catching these. Watch the Application Output panel in Qt Creator for `QML ... Binding loop detected` at runtime; treat every occurrence as a bug to fix, not noise to ignore.

## Concept: `Loader` for expensive, infrequently-shown content

Day 4 mentioned `StackLayout` (keeps everything alive) vs `Loader` (creates/destroys on demand) without detail. Now the concrete guidance: any tab/page/panel that's **heavy to construct and rarely visited** (an "Advanced Settings" page with dozens of controls, a rarely-opened historical report view) should be a `Loader`, not always-instantiated:

```qml
Loader {
    id: advancedSettingsLoader
    active: false   // nothing constructed until this flips
    anchors.fill: parent
    source: "AdvancedSettingsPage.qml"
}

Button {
    text: "Advanced Settings"
    onClicked: advancedSettingsLoader.active = true
}
```

`active: false` (rather than omitting `source` until needed) is the correct idiom — it guarantees nothing is constructed, no bindings evaluated, no memory held, until explicitly activated. Toggling `active` back to `false` destroys the loaded item entirely, freeing its memory — appropriate for something genuinely rare, at the cost of losing its internal state (scroll position, unsaved form input) each time, which is the real trade-off against `StackLayout` from Day 4.

**Asynchronous loading** for anything heavy enough to cause a visible stutter on load:

```qml
Loader {
    asynchronous: true    // construct off the main thread's frame budget, avoid a hitch
    source: "HeavyHistoricalReportView.qml"
}
```

`asynchronous: true` doesn't move QML execution to another OS thread in the way Day 18's worker objects did — it spreads component instantiation across multiple frames so a single frame doesn't blow its budget and cause a visible hitch. Use it for genuinely heavy components (large charts, big lists with complex delegates); it's unnecessary overhead for anything already cheap to construct.

## Concept: Delegate recycling — `ListView.reuseItems`

For long device/log lists, Qt Quick can reuse delegate instances as items scroll in/out rather than destroying and recreating them constantly:

```qml
ListView {
    model: deviceListModel
    delegate: DeviceRow { /* ... */ }
    reuseItems: true   // Qt 6 default for many cases, but set explicitly — don't rely on assumption
}
```

When `reuseItems` is active, a delegate instance getting reused for a _different_ model row can carry stale bound-to-old-data visual state for one frame in some edge cases (particularly with `Behavior`-animated properties, relevant given how much you've used those) — if you see a brief visual "flash" of wrong data while scrolling fast, this is usually why. The fix is generally ensuring delegate properties are always freshly bound (not left partially imperative), reinforcing Day 1's core lesson about bindings over assignment once again, now for a genuinely performance-driven reason rather than a purity one.

## Concept: `Item.layer.enabled` and caching — for expensive-to-render, rarely-changing visuals

If you have a complex `Canvas`/`Shape`/nested-item visual that rarely changes but sits inside something else that redraws often (e.g., a static icon inside an otherwise-animating card), caching it as a texture avoids re-rendering its internals every frame:

```qml
Canvas {
    id: complexIcon
    layer.enabled: true   // renders to a cached texture, reused until the item itself changes
    // ... expensive onPaint content that rarely needs to change ...
}
```

Use sparingly and deliberately — `layer.enabled` has its own memory cost (an offscreen texture buffer) and is a net loss if the content actually changes frequently, since you'd pay both the caching overhead and the redraw cost. Right for "expensive to draw, cheap to leave cached"; wrong for "changes every frame anyway."

## Concept: The Raspberry Pi–specific consideration

Given your actual deployment target: Pi-class GPUs are far more sensitive to overdraw and unnecessary layering than a development desktop. Things that are invisible cost on your dev machine (stacked semi-transparent `Rectangle`s, unnecessary `layer.enabled` on frequently-changing items, un-trimmed chart series from Day 20) become visible stutter on a Pi. **Profile on the actual target hardware before declaring something "fast enough"** — desktop performance is not a reliable proxy, the same lesson you already internalized doing embedded C work where dev-machine timing never told the whole story.

## Exercise

1. Run the Qt Quick Profiler (or `QSG_VISUALIZE=overdraw`) against your full Day 8/16/20 assembled dashboard. Identify at least one place with visible overdraw (stacked backgrounds) and fix it — likely candidates: nested `Rectangle` backgrounds inside `Panel.qml` (Day 11) that duplicate a parent's already-opaque background.
2. Deliberately construct the subtle indirect binding-loop example above, confirm the runtime warning appears, then fix it by breaking the cycle (compute one side from a value that doesn't depend on the other, e.g., both derive from a shared `parent.width` split rather than from each other).
3. Convert your Settings tab (Day 8/3) from a `StackLayout` page to a `Loader { active: false }`, confirm it now only constructs when the tab is first opened — then decide, and justify in a comment, whether this is actually the right trade-off for a Settings page you might want state-preserved on (probably not — revert if you conclude `StackLayout` was correct there, and note why).
4. If you have Pi hardware available, deploy the current build and run the same overdraw/profiler check on-device; note any place where dev-machine "fine" becomes Pi "visibly stuttering," and treat it as your priority optimization target over anything the desktop profiler alone suggested.

## Key takeaways

- Profile before optimizing — `QSG_VISUALIZE=overdraw` and Qt Creator's QML Profiler surface real waste instead of guessed waste.
- Binding loops can be indirect/subtle (A↔B via unrelated-looking expressions) — watch the Application Output for the runtime warning, treat every instance as a bug.
- `Loader { active: false }` for genuinely rare, heavy, state-losable content; `StackLayout` when state preservation across tab switches matters more than memory — this is a deliberate trade-off, not a default.
- `asynchronous: true` on `Loader` spreads construction across frames to avoid a hitch — for genuinely heavy components only.
- `reuseItems: true` on `ListView` avoids delegate churn but can show stale-frame flashes with recycled `Behavior`-animated delegates — ensure delegate properties are always freshly bound, never left imperative.
- `layer.enabled` caches expensive, rarely-changing visuals as a texture — a net loss if applied to frequently-changing content.
- Desktop performance is not a reliable proxy for Pi performance — profile on actual target hardware before calling anything "fast enough," same lesson as your embedded C experience.

