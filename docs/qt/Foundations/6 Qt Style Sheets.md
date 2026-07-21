[[Foundations]]

## Day 6: QSS (Qt Style Sheets) — Theming Correctly, and Where It Actually Breaks Down

### Concept: QSS Looks Like CSS But Is Not CSS

QSS uses CSS-like syntax deliberately, so it _feels_ familiar, but it's a much smaller, Qt-specific subset with real gaps. The biggest mental model correction: **QSS styles the box model of widgets, not arbitrary layout.** There's no flexbox, no grid, no `display: flex`. You're styling colors, borders, padding, fonts, and a handful of widget-specific sub-controls — layout itself is still entirely governed by Day 2's layout system, completely separate from QSS.

The other critical thing beginners miss: **QSS selectors match on the C++ class hierarchy**, including custom subclasses, and specificity/cascading rules are simplified compared to real CSS — no cascade layers, limited specificity math, and `!important`-equivalent behavior barely exists.

### Where to Apply QSS

Three levels, in order of what you should actually use:

1. **`qApp->setStyleSheet(...)`** — application-wide, set once in `main.cpp`. This is what you want for consistent theming (your dark mode dashboard).
2. **`widget->setStyleSheet(...)`** — per-widget override, for one-off exceptions. Overuse of this is a maintenance trap — you end up with styling scattered across 15 files instead of one theme.
3. **External `.qss` file loaded at startup** — same as #1 but kept in a separate file, loaded via `QFile` + `QTextStream`. This is the correct production pattern — never hardcode a large stylesheet string in C++.

### Annotated Code: A Real Dark Theme for the Dashboard

`resources/dark_theme.qss`:

```css
/* QSS selector syntax mirrors CSS but matches Qt class names.
   QMainWindow, QWidget, QPushButton etc. are literal C++ class names. */

QMainWindow, QWidget {
    background-color: #1e1e2e;
    color: #cdd6f4;
    font-family: "Segoe UI", sans-serif;
    font-size: 10pt;
}

QPushButton {
    background-color: #313244;
    border: 1px solid #45475a;
    border-radius: 4px;
    padding: 6px 12px;
}

/* Pseudo-states: hover, pressed, disabled — a small fixed set,
   not the full CSS pseudo-class spec */
QPushButton:hover {
    background-color: #45475a;
}

QPushButton:pressed {
    background-color: #585b70;
}

QPushButton:disabled {
    color: #6c7086;
    background-color: #292c3c;
}

/* Targeting a specific object by objectName — this is QSS's
   equivalent of a CSS ID selector, and it's the mechanism you'll
   use constantly for "style this ONE widget differently" */
QLabel#connectionIndicator {
    font-weight: bold;
    font-size: 11pt;
}

/* Sub-control syntax: widgets like QTableWidget/QHeaderView expose
   internal parts via ::part-name — this is QSS-specific, not CSS */
QTableWidget {
    background-color: #181825;
    gridline-color: #313244;
    border: none;
}

QHeaderView::section {
    background-color: #313244;
    color: #cdd6f4;
    padding: 4px;
    border: none;
}

QTextEdit {
    background-color: #181825;
    border: 1px solid #313244;
    border-radius: 4px;
}

QSplitter::handle {
    background-color: #313244;
}

QSplitter::handle:horizontal {
    width: 3px;
}

QSplitter::handle:vertical {
    height: 3px;
}

/* Scrollbars are notoriously fiddly in QSS — sub-control heavy */
QScrollBar:vertical {
    background: #1e1e2e;
    width: 10px;
}

QScrollBar::handle:vertical {
    background: #45475a;
    border-radius: 5px;
    min-height: 20px;
}

QScrollBar::add-line:vertical, QScrollBar::sub-line:vertical {
    height: 0px; /* hides the up/down arrow buttons */
}
```

Loading it in `main.cpp`:

```cpp
#include <QApplication>
#include <QFile>
#include <QTextStream>
#include "mainwindow.h"

int main(int argc, char *argv[]) {
    QApplication app(argc, argv);

    QFile styleFile(":/dark_theme.qss"); // loaded from Qt resource system
    if (styleFile.open(QFile::ReadOnly | QFile::Text)) {
        QTextStream stream(&styleFile);
        app.setStyleSheet(stream.readAll());
    }
    // If the file fails to open, app just falls back to native style —
    // fail gracefully, don't crash the app over a missing theme file.

    MainWindow window;
    window.show();
    return app.exec();
}
```

To load `:/dark_theme.qss` from the Qt resource system, add a `.qrc` file:

`resources.qrc`:

```xml
<RCC>
    <qresource prefix="/">
        <file>dark_theme.qss</file>
    </qresource>
</RCC>
```

And in `CMakeLists.txt` (you already have `CMAKE_AUTORCC ON` from Day 1 — just add the file):

```cmake
add_executable(qt_day06 main.cpp mainwindow.cpp mainwindow.h resources.qrc)
```

Set the object name so the `#connectionIndicator` selector actually matches:

```cpp
connectionIndicator = new QLabel("● Disconnected", central);
connectionIndicator->setObjectName("connectionIndicator"); // required for QSS #id selector
```

### Where QSS Actually Breaks Down (Know This Before You Fight It)

- **No layout properties.** `margin`/`padding` work on the widget's own box, but you cannot use QSS to control spacing _between_ widgets in a layout — that's still `layout->setSpacing()` / `setContentsMargins()` in C++.
- **Custom-painted widgets ignore QSS entirely** unless you explicitly opt in. Your `StatusLedWidget` from Day 4 draws with raw `QPainter` — QSS background-color rules won't touch it unless you add `setAttribute(Qt::WA_StyledBackground, true)` and manually paint the QSS-provided background in your `paintEvent` via `QStyleOption` — most people don't bother and just draw colors in C++ directly for fully custom widgets.
- **Performance**: large stylesheets applied via `qApp->setStyleSheet()` get re-evaluated on many style-related events; it's not "compiled once and forgotten" the way a browser might optimize CSS. For most desktop apps this is a non-issue, but avoid setting stylesheets in tight loops or on every paint.
- **No cascading media queries, no CSS variables** (until Qt 6.something added limited theming APIs — don't rely on this; treat QSS as "flat colors and static rules," not a dynamic theming engine on its own). For actual runtime theme switching (light/dark toggle), the real pattern is: load a different `.qss` file and call `setStyleSheet()` again, not CSS variables.

### Why This Matters

- Keeping styling in one external `.qss` file (not scattered `setStyleSheet()` calls per-widget) is what separates a maintainable app from one where nobody can find where a color is defined.
- `objectName` + `#selector` is your primary tool for "style this specific instance differently" — this is the QSS equivalent of an HTML `id`.
- Knowing that custom-painted widgets (Day 4's `StatusLedWidget`) don't automatically respect QSS saves you from a confusing debugging session where "my stylesheet isn't working" turns out to be "this widget was never listening to QSS in the first place."

### Exercise

1. Wire up the dark theme fully into your Day 2 dashboard shell — verify the splitter handles, table headers, and scrollbars all pick up the theme.
2. Add a second stylesheet, `light_theme.qss`, and a `QAction` (using Day 5's pattern) under a new "Theme" menu that swaps between them at runtime by calling `qApp->setStyleSheet()` again with the other file's contents loaded fresh.
3. Attempt to style `StatusLedWidget` via QSS (`StatusLedWidget { background-color: red; }`) and confirm it has zero visual effect — then fix it using `Qt::WA_StyledBackground` + drawing the style-provided background in `paintEvent` via `QStyleOption`, to see the actual mechanism required.

### Key Takeaways

- QSS is a Qt-specific subset resembling CSS syntactically, not a CSS engine — no flexbox/grid, limited specificity/cascade rules, and layout spacing stays in C++ layout code.
- Load theme stylesheets from an external `.qss` file via the Qt resource system, applied once at `qApp` level — don't scatter per-widget `setStyleSheet()` calls.
- `objectName` + `#id` selector is how you target one specific widget instance.
- Custom-painted widgets (raw `QPainter`) don't respect QSS automatically — you must opt in via `Qt::WA_StyledBackground` and paint the style's background yourself.
- Runtime theme switching means swapping and reapplying whole stylesheets, not dynamic CSS variables.

---

Say "next" for Day 7 (QSettings — persisting window geometry, user preferences, and connection settings across app restarts, plus the correct cross-platform storage locations and INI vs. native registry backends).