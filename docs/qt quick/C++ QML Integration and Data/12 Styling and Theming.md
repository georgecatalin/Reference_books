[[C++ QML Integrations and Data]]

# Day 12 — Styling & Theming: Material/Universal Styles and Consistent Customization

Day 3 you overrode one `CheckBox`'s `contentItem` by hand. That doesn't scale — a real app needs consistent theming across dozens of controls without repeating style code everywhere. Today: Qt Quick Controls' style system properly, and how to build one coherent theme for your `mqtt_monitor` dashboard.

## Concept: Controls styles — a build-time/runtime selectable backend

Qt Quick Controls ships several built-in styles: `Basic` (default, minimal, most customizable), `Material`, `Universal`, `Fusion`, `imagine` (asset-based). The style determines the _default_ look of every Control — but critically, **switching styles doesn't change your QML code**, only how controls render. This matters because it means you can prototype fast with `Material` and later swap to `Basic` + full custom styling for a distinctive product look, without rewriting logic.

**Setting the style — three ways, pick one per project (don't mix):**

```bash
# Environment variable (good for quick testing)
export QT_QUICK_CONTROLS_STYLE=Material
```

```cpp
// C++, in main.cpp — most reliable for shipped apps
QQuickStyle::setStyle("Material");
```

```qml
// qtquickcontrols2.conf file in your resources — declarative, no code
[Controls]
Style=Material
```

For a real product, the `.conf` file approach or C++ call is preferred over the environment variable — the env var is fine for your own dev testing but shouldn't be something your shipped app depends on existing.

## Concept: `Material` style attached properties — theming without per-control overrides

```qml
import QtQuick
import QtQuick.Controls
import QtQuick.Controls.Material

ApplicationWindow {
    width: 400; height: 300; visible: true

    Material.theme: Material.Dark
    Material.accent: Material.LightBlue
    Material.primary: Material.BlueGrey

    Button {
        text: "Connect"
        // Automatically Dark themed, LightBlue accent — no per-control styling needed
    }
}
```

`Material.theme`/`Material.accent`/`Material.primary` are **attached properties** (Day 11 introduced the concept) — set once on `ApplicationWindow` (or any ancestor), and every descendant Control inherits it automatically. This is the correct way to theme an entire app: set attached properties high in the tree, not per-widget.

## Concept: When built-in styles aren't enough — custom `Basic` style overrides

For a distinctive dashboard look (which `mqtt_monitor` should have, rather than looking like generic Material), the real pattern is: use `Basic` style as your foundation, then override each control type's visual delegates **once, centrally** — not scattered per-instance like Day 3's one-off `contentItem`.

**The mechanism: a custom style directory.** Qt Quick Controls lets you provide your own QML files matching control names in a folder, and it uses yours instead of the built-in ones automatically:

```
styles/
  MyStyle/
    Button.qml
    CheckBox.qml
    TextField.qml
    qmldir
```

```qml
// styles/MyStyle/Button.qml
import QtQuick
import QtQuick.Controls.Basic

T.Button {
    id: control
    implicitWidth: contentItem.implicitWidth + 24
    implicitHeight: 40

    contentItem: Text {
        text: control.text
        color: control.enabled ? "#cdd6f4" : "#6c7086"
        horizontalAlignment: Text.AlignHCenter
        verticalAlignment: Text.AlignVCenter
    }

    background: Rectangle {
        radius: 6
        color: control.pressed ? "#45475a"
             : control.hovered ? "#313244"
             : "#1e1e2e"
        border.color: "#89b4fa"
        border.width: control.activeFocus ? 2 : 0
        Behavior on color { ColorAnimation { duration: 150 } }
    }
}
```

`import QtQuick.Controls.Basic` and extending `T.Button` (the `QtQuick.Templates` base, imported as `T`) is the correct low-level pattern — Templates define _behavior_ (pressed/hovered/focus states, keyboard handling) with zero built-in visuals, and you supply 100% of the look via `contentItem`/`background`. This is genuinely how Qt's own built-in styles are implemented internally — you're not doing anything hacky, you're using the same mechanism Qt uses for `Material`/`Universal` themselves.

**Registering the style:**

```cpp
QQuickStyle::setStyle("MyStyle");
QQuickStyle::setFallbackStyle("Basic");  // for any control you haven't overridden
```

`setFallbackStyle` matters — you rarely override _every_ control type on day one; unstyled controls silently fall back to `Basic` (or whatever you set) instead of erroring, so you can style incrementally.

## Concept: A theme singleton — centralizing color/spacing constants

Rather than repeating `"#313244"`, `"#cdd6f4"` as string literals across every file (which you've been doing since Day 1 — time to fix it), centralize them:

```qml
// Theme.qml — a QML singleton (simpler variant, no C++ needed for pure constants)
pragma Singleton
import QtQuick

QtObject {
    readonly property color background: "#181825"
    readonly property color surface: "#313244"
    readonly property color surfaceHover: "#45475a"
    readonly property color textPrimary: "#cdd6f4"
    readonly property color textSecondary: "#a6adc8"
    readonly property color success: "#a6e3a1"
    readonly property color warning: "#f9e2af"
    readonly property color danger: "#f38ba8"
    readonly property color accent: "#89b4fa"

    readonly property int spacingSmall: 4
    readonly property int spacingMedium: 12
    readonly property int spacingLarge: 20
    readonly property int radiusDefault: 6
}
```

`pragma Singleton` is the **QML-only** singleton mechanism (as opposed to Day 10's `QML_SINGLETON` C++ macro) — appropriate here because this object has no logic, just constants; no need to involve C++ for it. Register it in `qmldir`:

```
singleton Theme 1.0 Theme.qml
```

Now every component references the theme instead of hardcoded hex values:

```qml
Rectangle {
    color: Theme.surface
    radius: Theme.radiusDefault
}
Label {
    color: Theme.textPrimary
}
```

Change `Theme.background` in one place, and your entire app's dark palette updates — this is the payoff for the discipline, and it's the same instinct as centralizing magic numbers/constants in a C++ header rather than scattering literals through `.cpp` files.

## Exercise

1. Retrofit every color literal in your Day 8 capstone build (`ConnectionStatusHeader`, `DeviceRow`, `BrokerSettingsForm`, the telemetry tiles) to reference a `Theme` singleton instead of inline hex strings. This should be a pure refactor — no visual change, but now themeable from one file.
2. Build a custom `Button.qml` style override (as shown above) and apply it via `QQuickStyle::setStyle("MyStyle")` in `main.cpp`. Confirm your Day 3 Connect button now uses it automatically with zero QML changes to the button's usage site.
3. Add a `Theme.criticalPulse` boolean-driving color and wire Day 7's critical-state pulse animation to reference it instead of a hardcoded color, proving the theme singleton composes with animation/state logic cleanly.
4. Try switching `Material.theme` between `Material.Light` and `Material.Dark` on your `ApplicationWindow` (temporarily, before you fully commit to the custom style) and observe how much visual change one attached property produces — useful intuition for how much styling work built-in themes save you versus a fully custom style.

## Key takeaways

- Set style via `QQuickStyle::setStyle()` in C++ or a `.conf` file for shipped apps — the env var is dev-only convenience.
- Material/Universal attached properties (`Material.theme`, `Material.accent`) set once on an ancestor cascade to all descendant Controls — theme centrally, not per-control.
- For a distinctive custom look, override `Basic` style templates (`T.Button` + custom `contentItem`/`background`) — this is the same mechanism Qt's own built-in styles use, not a hack.
- `setFallbackStyle` lets you override controls incrementally without unstyled ones erroring.
- A `pragma Singleton` QML object (not the C++ `QML_SINGLETON`) is the right tool for pure-constant theme values — no C++ needed unless the singleton has real logic.

