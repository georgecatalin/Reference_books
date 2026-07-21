[[Advanced Qt Quick]]

# Day 24 — Localization and Accessibility Fundamentals

This is the shortest, most pragmatic day in the course — not because these topics don't matter, but because for a device-monitoring dashboard (likely used by you and maybe a small ops team, not shipped to millions of end users), the _correct_ level of investment is "don't accidentally block it," not "build a full i18n pipeline." Today: what's cheap and worth doing now, and what to defer until you actually need it.

## Concept: Localization — `qsTr()` and why to use it even if you never translate

```qml
Label {
    text: qsTr("Connected")
}
Label {
    text: qsTr("%1 devices online").arg(deviceCount)
}
```

**Wrap user-visible strings in `qsTr()` from the start, even if you have zero current plans to translate anything.** This costs nothing (it's a no-op at runtime if no translation is loaded — the string displays exactly as written) and means the _option_ to translate later is free. Retrofitting `qsTr()` across an entire existing app after the fact is tedious and error-prone (you will miss strings); doing it as you write each label is nearly free. This is the same "cheap now, expensive later" argument you already apply to things like structured logging in your C++ work — add the hook before you need it, because adding it after is real work.

`.arg(deviceCount)` (not string concatenation with `+`) matters specifically for translation: different languages reorder sentence components (`"%1 devices online"` might need to become `"online: %1 devices"` in another language), and `%1`/`%2` placeholders let translators reorder freely — string concatenation bakes English word order in permanently.

## Concept: The actual translation pipeline — for when/if you need it

```bash
lupdate main.qml devicerow.qml *.qml -ts translations/mqtt_monitor_ro.ts
# Translate the .ts file (Qt Linguist GUI, or by hand — it's XML)
lrelease translations/mqtt_monitor_ro.ts   # produces .qm binary
```

```cpp
// main.cpp
QTranslator translator;
if (translator.load(QLocale(), "mqtt_monitor", "_", ":/translations")) {
    app.installTranslator(&translator);
}
```

This is genuinely the whole pipeline — `lupdate` extracts every `qsTr()` string into a translatable `.ts` file, you (or a translator) fill in translations, `lrelease` compiles it, and `QTranslator` loads it at runtime based on system locale. You don't need to build this now — just know `qsTr()` today is what makes it possible later without a rewrite.

## Concept: Number/date formatting — the actually-relevant localization concern for your project

Given you're in Romania and might deploy this for local use: date/number formatting conventions differ regionally in ways that matter more immediately than full UI translation:

```qml
Label {
    text: Qt.formatDateTime(new Date(), "dd.MM.yyyy hh:mm")   // explicit format, not locale-dependent guessing
}
```

For a device monitoring tool, **being explicit about your date/time format** (rather than relying on system locale defaults that might differ between your dev machine and a deployed Pi) is more practically useful than full translation — ambiguous date formats (`03/04/2026` — March 4th or April 3rd?) cause real operational confusion in a monitoring context where "when did this device go offline" matters. Pick one explicit format for your whole app and use it consistently, rather than leaving it to locale defaults.

## Concept: Accessibility — keyboard navigation, the part that's genuinely cheap

Qt Quick Controls give you keyboard navigation (Tab/Shift+Tab, Enter/Space to activate, arrow keys in lists) **for free**, automatically — _provided you use actual Controls_ rather than raw `MouseArea`-only custom widgets for anything interactive. This is the single biggest accessibility lesson for your project: **every custom interactive component you've built with a bare `MouseArea` (device rows, custom buttons) has no keyboard access at all** unless you deliberately add it.

```qml
// DeviceRow.qml — adding keyboard access to something currently mouse-only
Rectangle {
    id: root
    focus: true            // participates in Tab-order
    activeFocusOnTab: true

    Keys.onReturnPressed: root.deviceSelected(root.deviceId)
    Keys.onSpacePressed: root.deviceSelected(root.deviceId)

    // Visual focus indicator — don't skip this, invisible focus is nearly as bad as none
    border.width: root.activeFocus ? 2 : 0
    border.color: Theme.accent

    MouseArea {
        anchors.fill: parent
        onClicked: root.deviceSelected(root.deviceId)
    }
}
```

**The visual focus indicator (`border` bound to `activeFocus`) is not optional decoration** — a keyboard user tabbing through your device list with no visual indication of which row is focused has no usable feedback at all, even though keyboard navigation technically "works." This is a two-line addition with real impact, squarely in "cheap, do it" territory rather than "defer."

## Concept: `Accessible` attached properties — screen reader support, done minimally

```qml
Rectangle {
    id: statusDot
    Accessible.role: Accessible.Indicator
    Accessible.name: root.online ? qsTr("Device online") : qsTr("Device offline")
}
```

For a color-coded status dot with no text label, `Accessible.name` is what a screen reader announces — without it, a purely color-based status indicator conveys literally nothing to a screen-reader user (and, worth noting even for sighted users: nothing to someone with red-green color blindness either — pairing color with a shape/icon/text label, not color alone, is good practice regardless of screen readers). This is a real, concrete instance of "don't encode information in a channel some users can't perceive" — directly relevant to a status-dot-heavy dashboard like yours.

## A pragmatic verdict for your project specifically

Given the realistic scope (an internal/personal IoT monitoring tool, not a consumer product with broad accessibility compliance requirements):

- **Do now, cheap**: `qsTr()` on all labels (near-zero cost), keyboard focus + visual indicator on custom interactive components, explicit consistent date/time formatting, pairing color status with text/shape (not color alone).
- **Defer until actually needed**: full translation pipeline (`lupdate`/`lrelease`/actual translated `.ts` files) — pointless effort translating strings nobody will ever switch away from English/Romanian for, until there's an actual second-language user.
- **Skip entirely unless requirements say otherwise**: full WCAG-level screen-reader compliance audit, RTL layout support — genuinely not proportionate effort for this project's realistic user base.

## Exercise

1. Go through your Day 8 capstone build and wrap every user-visible string literal in `qsTr()` — this should be mechanical, not conceptual; the value is in building the habit.
2. Add keyboard focus + visual focus indicator + `Keys.onReturnPressed`/`Keys.onSpacePressed` to your `DeviceRow` delegate, then genuinely test it: Tab through your device list using only the keyboard, confirm you can select a device without touching the mouse.
3. Add `Accessible.name` to your connection status dot and any other color-only indicators in your dashboard (the online/offline dots, the critical-state pulse from Day 7).
4. Pick one date/time display in your dashboard and make its format explicit (`Qt.formatDateTime(..., "dd.MM.yyyy hh:mm")`) rather than relying on default locale formatting — confirm it displays identically regardless of what locale the running machine is set to.

## Key takeaways

- `qsTr()` everywhere, from day one — free now, expensive to retrofit later. Use `.arg()` placeholders, not string concatenation, so word order isn't baked in.
- For an internal monitoring tool, explicit consistent date/time formatting matters more practically than full multi-language translation — invest there first.
- Custom `MouseArea`-only components have zero keyboard accessibility by default — real Qt Quick Controls get it for free; anything you hand-roll needs `focus`/`Keys.on...Pressed`/a visible focus indicator added deliberately.
- Never encode status information in color alone — pair with text or shape, both for screen readers and for colorblind users; directly relevant to your status-dot-heavy UI.
- Match your investment to your actual user base — full translation pipelines and WCAG audits are real effort disproportionate to an internal IoT tool; the cheap wins above are proportionate and worth doing regardless.
