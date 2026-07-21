[[Core Application Skills]]

## Day 12: `QPropertyAnimation`, the Property System, and `QGraphicsEffect` — Used Tastefully

### Concept: Animations Operate on Qt Properties, Not Arbitrary Member Variables

`QPropertyAnimation` doesn't know anything about your class internals — it only knows how to call a `READ`/`WRITE` pair exposed via `Q_PROPERTY`, on a timer, interpolating values between a start and end point. This is why Day 11's exercise had you add `Q_PROPERTY(double temperature ...)` — without a real Qt property, there's nothing for the animation system to actually animate. This is the same reflection/introspection machinery from Day 1 (moc-generated), now put to concrete use.

**The practical warning up front, from someone who's shipped Qt apps**: animations are extremely easy to overuse and make an application feel like a demo reel instead of a monitoring tool. For `mqtt_monitor`, the right amount of animation is: a smooth color transition on status change, maybe a subtle highlight flash on new data — not motion for its own sake. Alert fatigue is a real UX problem in monitoring dashboards; don't compound it with visual noise.

### `Q_PROPERTY` — The Full Syntax, Explained

```cpp
Q_PROPERTY(double temperature READ temperature WRITE setTemperature NOTIFY temperatureChanged)
```

- `READ temperature` — the getter Qt calls to read current value (also usable from QML, if you ever go there, though we're not in this curriculum)
- `WRITE setTemperature` — the setter `QPropertyAnimation` calls repeatedly during the animation
- `NOTIFY temperatureChanged` — a signal emitted whenever the value changes, so other things can react (this must exist and actually be emitted from your setter)

### Annotated Code: Animating `DeviceCard`'s Temperature Label Color

Building on Day 11's `DeviceCard`, adding a real animated property.

`devicecard.h` additions:

```cpp
#include <QColor>

class DeviceCard : public QWidget {
    Q_OBJECT
    // Real Qt property — this is what QPropertyAnimation will target
    Q_PROPERTY(QColor tempColor READ tempColor WRITE setTempColor)

public:
    // ... existing interface ...
    QColor tempColor() const { return currentTempColor; }
    void setTempColor(const QColor &color);

private:
    QColor currentTempColor = QColor(205, 214, 244); // default text color
    QPropertyAnimation *colorAnimation = nullptr;
};
```

`devicecard.cpp` additions:

```cpp
#include <QPropertyAnimation>

void DeviceCard::setTempColor(const QColor &color) {
    currentTempColor = color;
    tempLabel->setStyleSheet(QString("color: %1;").arg(color.name()));
}

void DeviceCard::setTemperature(double celsius) {
    tempLabel->setText(QString("%1 C").arg(celsius, 0, 'f', 1));

    QColor targetColor = (celsius > 80.0) ? QColor(231, 76, 60)   // red — alert
                                            : QColor(205, 214, 244); // normal

    if (targetColor == currentTempColor) return; // don't restart an identical animation

    // Lazily create the animation object once, reuse it — creating a new
    // QPropertyAnimation on every single temperature update is wasteful
    // and can cause overlapping/competing animations on rapid updates
    if (!colorAnimation) {
        colorAnimation = new QPropertyAnimation(this, "tempColor", this);
        colorAnimation->setDuration(400); // milliseconds — fast, not a demo-reel fade
        colorAnimation->setEasingCurve(QEasingCurve::OutCubic);
    } else {
        colorAnimation->stop(); // stop any in-flight animation before retargeting
    }

    colorAnimation->setStartValue(currentTempColor);
    colorAnimation->setEndValue(targetColor);
    colorAnimation->start();
}
```

**Note the property name string**: `"tempColor"` in `new QPropertyAnimation(this, "tempColor", this)` must exactly match the `Q_PROPERTY` name. This is one of the few places Qt still relies on a string identifier rather than a compile-time-checked pointer — get the string wrong and the animation silently does nothing (no compiler error, no runtime crash, just a no-op). Double check spelling if an animation "isn't working."

### `QPropertyAnimation` on Built-In Properties (No Custom `Q_PROPERTY` Needed)

Many Qt widgets already expose animatable properties out of the box — `windowOpacity`, `geometry`, `pos` on `QWidget` itself. You don't need custom properties for these:

```cpp
// A dialog that fades in instead of appearing abruptly — tasteful,
// commonly seen in production apps, not gratuitous
auto *fadeIn = new QPropertyAnimation(dialog, "windowOpacity");
fadeIn->setDuration(200);
fadeIn->setStartValue(0.0);
fadeIn->setEndValue(1.0);
fadeIn->start(QAbstractAnimation::DeleteWhenStopped); // auto-cleanup, no manual delete needed
```

`QAbstractAnimation::DeleteWhenStopped` is worth calling out: it tells the animation object to delete itself once finished, which is the correct memory-management choice for fire-and-forget animations you don't need to reference again — avoids you having to manually track and delete every animation object.

### `QGraphicsEffect` — Subtle Shadow/Glow, Sparingly

`QGraphicsEffect` subclasses (`QGraphicsDropShadowEffect`, `QGraphicsBlurEffect`, `QGraphicsColorizeEffect`) apply a post-processing visual effect to an entire widget. Real use case for `mqtt_monitor`: a subtle drop shadow to lift `DeviceCard`s off the background slightly, or a brief colorize/glow on a card that just went into an alert state.

```cpp
#include <QGraphicsDropShadowEffect>

// A permanent, subtle "lifted card" look — applied once at construction
auto *shadow = new QGraphicsDropShadowEffect(this);
shadow->setBlurRadius(12);
shadow->setOffset(0, 2);
shadow->setColor(QColor(0, 0, 0, 100)); // semi-transparent black
setGraphicsEffect(shadow); // applies to the whole DeviceCard widget
```

**Important limitation**: a `QWidget` can only have **one** `QGraphicsEffect` active at a time — setting a second replaces the first. If you want both a permanent shadow _and_ a temporary alert glow, you need to swap the effect object at the right moments, not stack two.

```cpp
void DeviceCard::flashAlert() {
    auto *glow = new QGraphicsColorizeEffect(this);
    glow->setColor(QColor(231, 76, 60));
    glow->setStrength(0.6);
    setGraphicsEffect(glow); // replaces the drop shadow temporarily

    // Restore the normal shadow after a brief moment — QTimer::singleShot
    // is the correct lightweight tool for "do this once, later," without
    // needing a full animation object
    QTimer::singleShot(600, this, [this]() {
        auto *shadow = new QGraphicsDropShadowEffect(this);
        shadow->setBlurRadius(12);
        shadow->setOffset(0, 2);
        shadow->setColor(QColor(0, 0, 0, 100));
        setGraphicsEffect(shadow); // back to normal
    });
}
```

**Performance caution, said plainly from experience**: `QGraphicsEffect` forces the widget to render into an offscreen buffer every repaint, which is meaningfully more expensive than normal widget painting. Fine for a handful of cards; if you ever have a grid of 100+ cards all with permanent drop shadows, you will feel it in CPU usage on lower-power targets (relevant given your Raspberry Pi/BeagleBone deployment targets) — reserve effects for genuinely important visual emphasis, not decoration applied uniformly everywhere.

### `QPropertyAnimation` Groups — When Multiple Things Animate Together

```cpp
#include <QParallelAnimationGroup>

auto *group = new QParallelAnimationGroup(this);
group->addAnimation(colorAnimation);
group->addAnimation(fadeIn);
group->start(QAbstractAnimation::DeleteWhenStopped);
```

`QParallelAnimationGroup` runs animations concurrently; `QSequentialAnimationGroup` runs them one after another. You won't need these often for a monitoring dashboard, but it's worth knowing they exist rather than manually chaining `QTimer::singleShot` calls to sequence animations by hand.

### Why This Matters

- Animations only work on real `Q_PROPERTY` declarations (or Qt's own built-in ones) — there's no way to animate an arbitrary private member directly, which is a deliberate design constraint that keeps the animation system decoupled from your class internals, same philosophy as signals/slots.
- The property name is passed as a **string**, one of Qt's few remaining stringly-typed APIs — a silent no-op on typo is the main failure mode to watch for.
- Reuse animation objects instead of creating new ones per update — this avoids both memory churn and overlapping/competing animations fighting over the same property.
- `QGraphicsEffect` is one-effect-per-widget and meaningfully more expensive to render — appropriate for occasional emphasis, not blanket decoration, especially given your target hardware includes lower-power embedded boards.
- The instinct to restrain animation use is itself part of "correct fundamentals" for a monitoring tool — this is a judgment call worth internalizing now, not just a technical detail.

### Exercise

1. Add a brief "new data" flash to `DeviceCard`: when `setTemperature()` is called (regardless of whether it crosses the alert threshold), briefly flash the card's background lighter for 150ms and back, using the same reusable-animation-object pattern shown above. Keep it subtle enough that it wouldn't be annoying with updates arriving every few seconds.
2. Wire `flashAlert()` to trigger from the `DeviceMonitor::alertRaised` signal (Day 3), and confirm — by triggering multiple alerts on the same card in quick succession — that your `QTimer::singleShot` restoration logic doesn't leave the card stuck in the "glow" state if a second alert fires before the first 600ms window ends. Fix it if it does (hint: track whether a restore is already pending).
3. Time-box a "before/after" comparison: build one version of the dashboard with animations on 20 simultaneously-updating `DeviceCard`s, and profile rough CPU usage (even just via `top`) with and without `QGraphicsDropShadowEffect` applied to all of them. This is meant to make the performance caution concrete rather than theoretical, especially relevant if you plan to eventually run this GUI on a Pi rather than just a dev workstation.

### Key Takeaways

- `QPropertyAnimation` requires a real `Q_PROPERTY` (custom or built-in like `windowOpacity`/`geometry`) with working READ/WRITE — there's no way around this requirement.
- The property name argument is a string and typo-prone; a silent no-op (not a crash) is the telltale sign of a mismatched name.
- Reuse animation objects across updates rather than constructing new ones each time; use `DeleteWhenStopped` for genuinely fire-and-forget cases.
- `QGraphicsEffect` is one-per-widget and rendering-expensive — use sparingly, especially on resource-constrained embedded targets you may eventually deploy to.
- Restraint in animation usage is a legitimate design decision for a monitoring dashboard, not a missed opportunity — alert fatigue and visual noise actively hurt the tool's job.

---

Say "next" for Day 13 (drag & drop — reordering device cards/list items, and accepting dropped files, e.g., dragging a config `.json` onto the window to load connection settings).