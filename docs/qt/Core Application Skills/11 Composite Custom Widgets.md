[[Core Application Skills]]

## Day 11: Composite Custom Widgets — Building a Reusable `DeviceCard` Component

### Concept: Most Real Widgets Are Compositions, Not Custom-Painted From Scratch

Day 4 showed you `paintEvent()`-based custom painting for a widget with no built-in equivalent (the LED). But that's actually the _less common_ case in real applications. Far more often, "a custom widget" means **composing existing widgets together into a reusable unit** — labels, buttons, layouts, bundled into one class with its own clean public interface. This is the same idea as writing a Python class that wraps several `Pydantic` models into one cohesive service object: you're not reinventing primitives, you're packaging them.

A `DeviceCard` — showing a device's ID, status LED, temperature, and a "details" button, reusable anywhere you want to show a device summary — is exactly this kind of component. This matters for `mqtt_monitor` because you'll want this same card in at least two places: a grid overview and possibly a compact sidebar list.

### The Three Things a Good Composite Widget Needs

1. **Its own layout, built in the constructor** — same as any widget, but now the widget _is_ the container.
2. **A clean public interface** — setters like `setDeviceId()`, `setTemperature()`, not exposing its internal child widgets to callers.
3. **Its own signals** — re-emitting or translating internal child signals (like a button click) into a meaningful signal at the card's level (`detailsRequested()`), not making callers reach into the card's internals to connect to a raw button.

### Annotated Code: `DeviceCard`

`devicecard.h`:

```cpp
#pragma once
#include <QWidget>
#include <QLabel>
#include <QPushButton>
#include "statusledwidget.h" // from Day 4

class DeviceCard : public QWidget {
    Q_OBJECT
public:
    explicit DeviceCard(const QString &deviceId, QWidget *parent = nullptr);

    // Clean public interface — callers never touch internal child widgets directly
    void setTemperature(double celsius);
    void setOnline(bool online);
    QString deviceId() const { return id; }

signals:
    // Card-level signal, not "here's my internal button, connect to it yourself"
    void detailsRequested(const QString &deviceId);

protected:
    // Custom widgets that are meant to look distinct (a "card" visual)
    // often still need a light paintEvent override for the border/background,
    // even though most of the content is composed child widgets, not raw painting.
    void paintEvent(QPaintEvent *event) override;

private:
    QString id;
    StatusLedWidget *led;
    QLabel *idLabel;
    QLabel *tempLabel;
    QPushButton *detailsButton;
};
```

`devicecard.cpp`:

```cpp
#include "devicecard.h"
#include <QHBoxLayout>
#include <QVBoxLayout>
#include <QPainter>
#include <QStyleOption>

DeviceCard::DeviceCard(const QString &deviceId, QWidget *parent)
    : QWidget(parent), id(deviceId)
{
    // Fixed vertical size policy is common for card-style widgets in a grid —
    // you want predictable card height, growing width, not organic resizing
    setSizePolicy(QSizePolicy::Preferred, QSizePolicy::Fixed);

    auto *mainLayout = new QHBoxLayout(this);
    mainLayout->setContentsMargins(12, 8, 12, 8);

    led = new StatusLedWidget(this);
    mainLayout->addWidget(led);

    auto *textLayout = new QVBoxLayout();
    idLabel = new QLabel(deviceId, this);
    idLabel->setStyleSheet("font-weight: bold;");
    tempLabel = new QLabel("-- C", this);
    textLayout->addWidget(idLabel);
    textLayout->addWidget(tempLabel);
    mainLayout->addLayout(textLayout, /*stretch=*/1); // text area takes extra space

    detailsButton = new QPushButton("Details", this);
    // Connect the CHILD's raw signal to a LAMBDA that re-emits the
    // CARD's own signal — this is the translation step that keeps
    // the card's public interface clean and widget-agnostic
    connect(detailsButton, &QPushButton::clicked, this, [this]() {
        emit detailsRequested(id);
    });
    mainLayout->addWidget(detailsButton);
}

void DeviceCard::setTemperature(double celsius) {
    tempLabel->setText(QString("%1 C").arg(celsius, 0, 'f', 1));
}

void DeviceCard::setOnline(bool online) {
    led->setStatus(online ? StatusLedWidget::Status::Online
                           : StatusLedWidget::Status::Offline);
}

void DeviceCard::paintEvent(QPaintEvent *event) {
    // Composite widgets that need a background/border still need this
    // boilerplate — a plain QWidget doesn't auto-paint a background even
    // if you set a stylesheet, unless you opt in like this (echoes Day 6's
    // WA_StyledBackground point, shown here in its actual working form)
    QStyleOption opt;
    opt.initFrom(this);
    QPainter p(this);
    style()->drawPrimitive(QStyle::PE_Widget, &opt, &p, this);

    QWidget::paintEvent(event); // let children still paint themselves normally
}
```

For the QSS border/background to apply, set this once in the constructor:

```cpp
setAttribute(Qt::WA_StyledBackground, true);
setObjectName("deviceCard");
```

And add to your theme file:

```css
QWidget#deviceCard {
    background-color: #313244;
    border: 1px solid #45475a;
    border-radius: 6px;
}
```

### Using It: A Grid of Cards

```cpp
#include "devicecard.h"
#include <QGridLayout>
#include <QScrollArea>

// Somewhere in MainWindow setup — an alternative/complementary view
// to the table, e.g., a "Grid View" tab
QWidget *cardContainer = new QWidget();
auto *grid = new QGridLayout(cardContainer);

QStringList deviceIds = {"device-01", "device-02", "device-03", "device-04"};
int columns = 2;
for (int i = 0; i < deviceIds.size(); ++i) {
    auto *card = new DeviceCard(deviceIds[i], cardContainer);
    card->setTemperature(35.0 + i * 3.2);
    card->setOnline(i != 2);

    connect(card, &DeviceCard::detailsRequested, this, [this](const QString &id) {
        logView->append(QString("[UI] Details requested for %1").arg(id));
    });

    grid->addWidget(card, i / columns, i % columns);
}

// Wrap in a scroll area — grids of cards will exceed viewport height
// once you have more than a handful of devices
auto *scrollArea = new QScrollArea();
scrollArea->setWidget(cardContainer);
scrollArea->setWidgetResizable(true); // critical — without this, the
                                        // inner widget won't resize with the scroll area
```

### Why This Matters

- **The signal-translation pattern** (`detailsButton`'s `clicked()` → card's own `detailsRequested(deviceId)`) is what makes the card genuinely reusable. If callers had to `card->findChild<QPushButton*>("detailsButton")` and connect to _that_, you'd have broken encapsulation — any internal restructuring of `DeviceCard` (swap the button for something else) would break every caller. This is directly analogous to not exposing internal implementation details across a module boundary in your Python/C++ work.
- **`QWidget::paintEvent` + `WA_StyledBackground` + `PE_Widget`** is the actual correct boilerplate for "a composite widget that wants QSS styling to apply to its own background/border" — this closes the loop from Day 6's mention of this exact mechanism, now with real code.
- **`scrollArea->setWidgetResizable(true)`** is a very commonly forgotten line — without it, your inner widget stays at its `sizeHint()` regardless of the scroll area's actual viewport size, leading to a confusing "why doesn't my grid fill the window" bug.
- **`setSizePolicy(Preferred, Fixed)` on the card** ties directly back to Day 2 — cards in a grid should have predictable height and flexible width, and this is exactly the size policy combination that expresses that intent.

### Exercise

1. Add a `QPropertyAnimation` (a brief preview of Day 12 territory, but worth trying now) that fades the temperature label's color from a normal shade to red when the temperature exceeds 80°C, using `setOnline`/`setTemperature` calls as the trigger point.
2. Wire `DeviceCard` up to the real `DeviceMonitor` signals from Day 3/9 — when `temperatureUpdated`/`deviceWentOffline` fire, find the matching card (by `deviceId()`) in a `QVector<DeviceCard*>` and update it, rather than only updating the table model. This demonstrates one signal source, multiple independent UI representations — the payoff of the decoupling from Day 3.
3. Refactor `DeviceCard` to expose its online/offline/temperature state as real Qt **properties** (`Q_PROPERTY`) instead of plain setters — look up `Q_PROPERTY` syntax and add at least one (e.g., `Q_PROPERTY(double temperature READ temperature WRITE setTemperature NOTIFY temperatureChanged)`). This is preparation for Day 12's animation framework, which operates on Qt properties specifically.

### Key Takeaways

- Most reusable custom widgets are **compositions** of existing widgets in a layout, not raw-painted from scratch — reserve `paintEvent()` overrides for genuinely custom visuals or card-style backgrounds.
- A composite widget's public interface should be its own methods and signals — never expose internal child widgets for callers to reach into and connect against directly.
- Translate child widget signals into the composite's own semantically meaningful signals (`clicked()` → `detailsRequested(deviceId)`).
- `WA_StyledBackground` + a `paintEvent` override calling `style()->drawPrimitive(QStyle::PE_Widget, ...)` is the correct pattern for a composite widget that wants its QSS background/border to actually render.
- `QScrollArea::setWidgetResizable(true)` is required for the inner widget to properly fill/resize with the scroll area's viewport.

---

Say "next" for Day 12 (animations with `QPropertyAnimation` and the property system properly — smooth transitions for status changes, and `QGraphicsEffect` for things like a subtle glow/shadow on alert states, used tastefully rather than gratuitously).