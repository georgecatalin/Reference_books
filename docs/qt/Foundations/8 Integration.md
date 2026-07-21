[[Foundations]]

## Day 8: Phase 1 Integration — One Cohesive, Buildable Application Skeleton

### Concept: Consolidation Before Moving Forward

Before Phase 2 adds real data (models, custom painting at scale, persistence-backed views), today is about making sure Days 1–7 aren't seven disconnected snippets in your head — they're one buildable project. This is also the point where you'll feel the first real payoff of Qt's architecture: menus, toolbar, dialogs, theming, and settings all cooperating through the same `QAction`/signal-slot/QObject patterns, without any of them knowing about each other directly.

No new concepts today — just correct file structure, a working build, and a checklist of what should genuinely "click" before Phase 2.

### Full Project Structure

```
mqtt_monitor_gui/
├── CMakeLists.txt
├── main.cpp
├── mainwindow.h
├── mainwindow.cpp
├── connectiondialog.h
├── connectiondialog.cpp
├── devicemonitor.h
├── devicemonitor.cpp
├── statusledwidget.h
├── statusledwidget.cpp
├── resources.qrc
└── resources/
    ├── dark_theme.qss
    └── light_theme.qss
```

### `CMakeLists.txt` (final, Phase-1-complete version)

```cmake
cmake_minimum_required(VERSION 3.16)
project(mqtt_monitor_gui)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

set(CMAKE_AUTOMOC ON)
set(CMAKE_AUTORCC ON)
set(CMAKE_AUTOUIC ON)

# Widgets covers everything so far; Core is implicit. We'll add
# Network, SerialPort, Sql, Mqtt in Phase 3 as those modules are needed.
find_package(Qt6 REQUIRED COMPONENTS Widgets)

add_executable(mqtt_monitor_gui
    main.cpp
    mainwindow.cpp
    mainwindow.h
    connectiondialog.cpp
    connectiondialog.h
    devicemonitor.cpp
    devicemonitor.h
    statusledwidget.cpp
    statusledwidget.h
    resources.qrc
)

target_link_libraries(mqtt_monitor_gui PRIVATE Qt6::Widgets)

# Keep sanitizers available as a build option, matching your C++
# course conventions — off by default for normal runs, on for testing.
option(ENABLE_SANITIZERS "Build with ASan/UBSan" OFF)
if(ENABLE_SANITIZERS)
    target_compile_options(mqtt_monitor_gui PRIVATE -fsanitize=address,undefined -g)
    target_link_options(mqtt_monitor_gui PRIVATE -fsanitize=address,undefined)
endif()

target_compile_options(mqtt_monitor_gui PRIVATE -Wall -Wextra)
```

Build with sanitizers on, matching your usual workflow, to sanity-check everything from Days 1–7 together (especially the Day 3/4 ownership and lambda-capture exercises):

```bash
mkdir build && cd build
cmake -DENABLE_SANITIZERS=ON ..
cmake --build .
./mqtt_monitor_gui
```

### `main.cpp` (final)

```cpp
#include <QApplication>
#include <QFile>
#include <QTextStream>
#include <QSettings>
#include "mainwindow.h"

int main(int argc, char *argv[]) {
    QApplication app(argc, argv);

    // App identity MUST be set before any QSettings instantiation (Day 7)
    QCoreApplication::setOrganizationName("GeorgeLabs");
    QCoreApplication::setApplicationName("mqtt_monitor");
    QSettings::setDefaultFormat(QSettings::IniFormat);

    // Theme loaded once at startup (Day 6) — swapped at runtime via QAction
    QFile styleFile(":/dark_theme.qss");
    if (styleFile.open(QFile::ReadOnly | QFile::Text)) {
        QTextStream stream(&styleFile);
        app.setStyleSheet(stream.readAll());
    }

    MainWindow window;
    window.show();
    return app.exec();
}
```

### `mainwindow.h` (consolidated — this is the shape your Phase 2 work builds on)

```cpp
#pragma once
#include <QMainWindow>
#include <QListWidget>
#include <QTextEdit>
#include <QTableWidget>
#include <QLabel>
#include <QAction>
#include <QCloseEvent>

class DeviceMonitor;

class MainWindow : public QMainWindow {
    Q_OBJECT
public:
    explicit MainWindow(QWidget *parent = nullptr);

protected:
    void closeEvent(QCloseEvent *event) override;
    bool eventFilter(QObject *watched, QEvent *event) override;

private:
    // Setup (Days 1-2)
    void setupDeviceListPanel();
    void setupStatusTablePanel();
    void setupLogPanel();

    // Chrome (Day 5)
    void createActions();
    void createMenus();
    void createToolbar();
    void showConnectionDialog();
    void showAboutDialog();

    // Persistence (Day 7)
    void loadSettings();
    void saveSettings();

private slots:
    void onTemperatureUpdated(const QString &deviceId, double temp);

signals:
    void deviceOfflineDetected(const QString &deviceId);

private:
    QListWidget *deviceList;
    QTableWidget *statusTable;
    QTextEdit *logView;
    QLabel *connectionIndicator;

    QAction *connectAction;
    QAction *disconnectAction;
    QAction *exitAction;
    QAction *aboutAction;
    QAction *toggleLogAction;

    DeviceMonitor *monitor;

    QString currentBrokerHost = "localhost";
    int currentBrokerPort = 1883;
};
```

### Phase 1 Integration Checklist — Confirm Each of These Actually Clicked

Work through this against your running build. If any item feels shaky, it's worth revisiting that day's exercise rather than pushing forward — Phase 2 assumes all of this is solid.

|#|Check|Which Day|
|---|---|---|
|1|You can explain why `new QWidget(this)` never needs a matching `delete`|Day 1|
|2|You can predict which widgets grow/shrink on window resize just by reading size policies + stretch factors|Day 2|
|3|You know the difference between Direct and Queued connections, and why AutoConnection resolves differently across threads|Day 3|
|4|You've seen a lambda-capture crash happen and fixed it with a context object|Day 3|
|5|You can explain why `StatusLedWidget` needs `paintEvent()` instead of a stylesheet for its circle color|Day 4|
|6|You understand why `eventFilter` returning `true` vs `false` changes behavior|Day 4|
|7|You can add a new menu item + toolbar button + shortcut from one `QAction` in under 2 minutes|Day 5|
|8|You know when to use `exec()` vs `show()` for a dialog, and what `Qt::WA_DeleteOnClose` is for|Day 5|
|9|You know why `StatusLedWidget` doesn't respect QSS by default|Day 6|
|10|You know where `~/.config/GeorgeLabs/mqtt_monitor.ini` lives and what's in it after closing the app|Day 7|
|11|You know why `closeEvent()`, not the destructor, is the right save hook|Day 7|

### Exercise (Today's Is a Build Exercise, Not a New Feature)

1. Assemble the full project from Days 1–7 into the structure above (copy the accumulated code from each day's lesson — this is intentionally a consolidation task, not new material).
2. Build with `ENABLE_SANITIZERS=ON`, run it, exercise every feature (resize, connect dialog, theme, toolbar actions, close/reopen to verify settings persist), and confirm zero sanitizer output.
3. Fix any integration seams that don't compile cleanly — likely candidates: forward-declaring `DeviceMonitor` in `mainwindow.h` vs. including it in `.cpp`, and making sure `loadSettings()` is called _after_ `createToolbar()` in the constructor (state restoration needs the toolbar/dockwidgets to already exist).

### Key Takeaways (Phase 1, in one place)

- **Ownership**: parent/child (Qt tree) vs. RAII (your C++ instincts) are two coexisting models — know which applies to which object.
- **Layout**: size policy = _can this grow_, stretch factor = _how much extra space does it get_. Layouts solve constraints; you don't hand-position widgets.
- **Signals/slots**: decoupled messaging, connection type determines _when_ a slot runs, context objects prevent dangling lambda calls — this is the foundation for safe threading later.
- **Events**: the substrate underneath signals/slots; override `...Event()` methods for custom behavior, use event filters sparingly and cheaply.
- **Chrome**: `QAction` is the single source of truth behind menus/toolbars/shortcuts; `QDialogButtonBox` for correct button ordering; modal vs. modeless dialogs have different lifetime management needs.
- **Theming**: QSS is a flat, non-cascading subset of CSS-like syntax; custom-painted widgets need explicit opt-in to respect it.
- **Persistence**: `QSettings` with forced INI format, org/app name set before first use, `closeEvent()` as the save hook, JSON for structured data.

---

That's Phase 1 complete. Say "next" for Day 9 — the start of Phase 2: Model/View architecture, starting with `QAbstractTableModel`, where your `statusTable` stops being manually-populated `QTableWidgetItem`s and becomes a real data-driven view backed by a model you control (the pattern you'll eventually back with live SQLite data).