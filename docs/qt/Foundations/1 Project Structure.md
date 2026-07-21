[[Foundations]]


## Day 1 Qt Fundamentals — Project Structure, the Object Model, and Your First Real Window

### Concept: What Qt Actually Is

Qt is not just a widget toolkit — it's a C++ extension with its own object model layered on top of standard C++. The core things that make Qt different from "plain" C++:

1. **QObject** — the base class for anything that participates in Qt's object model (parent/child ownership, signals/slots, event handling).
2. **The Meta-Object Compiler (moc)** — a code generator that runs before your compiler. It reads `Q_OBJECT` macros and generates the plumbing that makes signals/slots and introspection work. This is _not_ templates or macros doing runtime magic — it's actual generated C++ you can go read if you want.
3. **Parent/child ownership** — Qt has its own memory management convention: a `QObject` can own child `QObjects`, and when the parent is destroyed, so are the children. This exists _alongside_ RAII/smart pointers, not instead of them — you'll need to know when to use which.
4. **The event loop** — Qt apps are event-driven. Nothing happens until `QApplication::exec()` starts pumping events (paint events, input events, timers, custom events). This will feel familiar from asyncio's event loop, but it's single-threaded by default and GUI calls **must** happen on that thread.

Given your background, the mental model to build immediately: Qt's parent/child system is like a garbage collector for a subset of your objects, scoped by widget hierarchy. It's not universal — it only applies to `QObject`-derived types, and only if you actually set a parent.

### Setup

You'll use CMake (not qmake — CMake is the modern, actively-supported path and fits your existing toolchain).

```bash
sudo apt install qt6-base-dev qt6-tools-dev cmake build-essential
```

Verify:

```bash
qmake6 -v
cmake --version
```

### Annotated Code: Minimal Real Window

`CMakeLists.txt`:

```cmake
cmake_minimum_required(VERSION 3.16)
project(qt_day01)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# These three lines are Qt-specific build magic:
set(CMAKE_AUTOMOC ON)   # runs moc automatically on files with Q_OBJECT
set(CMAKE_AUTORCC ON)   # compiles .qrc resource files automatically
set(CMAKE_AUTOUIC ON)   # processes .ui files from Qt Designer automatically

find_package(Qt6 REQUIRED COMPONENTS Widgets)

add_executable(qt_day01 main.cpp mainwindow.cpp mainwindow.h)

target_link_libraries(qt_day01 PRIVATE Qt6::Widgets)
```

`mainwindow.h`:

```cpp
#pragma once
#include <QMainWindow>
#include <QPushButton>
#include <QLabel>

// Q_OBJECT is what triggers moc to generate signal/slot machinery.
// Any class that wants signals, slots, or Qt's property system needs this.
class MainWindow : public QMainWindow {
    Q_OBJECT   // <-- non-negotiable, must be the first line in the class body

public:
    explicit MainWindow(QWidget *parent = nullptr);

private slots:
    // "slots" are just member functions that can be connected to signals.
    // In modern Qt (5.15+/6) you don't strictly need this keyword for
    // connecting to plain functions, but for clarity and Designer
    // compatibility, keep marking real event-handling methods as slots.
    void onButtonClicked();

private:
    QPushButton *button;
    QLabel *label;
    int clickCount = 0;
};
```

`mainwindow.cpp`:

```cpp
#include "mainwindow.h"
#include <QVBoxLayout>
#include <QWidget>

MainWindow::MainWindow(QWidget *parent) : QMainWindow(parent) {
    setWindowTitle("Day 1 - mqtt_monitor GUI shell");
    resize(400, 200);

    // QMainWindow needs a central widget to hold your layout.
    // This is a common beginner trip-up: you cannot setLayout()
    // directly on QMainWindow.
    QWidget *central = new QWidget(this);   // 'this' = parent
    setCentralWidget(central);

    auto *layout = new QVBoxLayout(central); // layout is owned by 'central'

    label = new QLabel("Clicks: 0", central);
    button = new QPushButton("Click me", central);

    layout->addWidget(label);
    layout->addWidget(button);

    // The core idiom: connect a signal to a slot.
    // connect(sender, signal, receiver, slot)
    connect(button, &QPushButton::clicked, this, &MainWindow::onButtonClicked);
}

void MainWindow::onButtonClicked() {
    clickCount++;
    label->setText(QString("Clicks: %1").arg(clickCount));
}
```

`main.cpp`:

```cpp
#include <QApplication>
#include "mainwindow.h"

int main(int argc, char *argv[]) {
    QApplication app(argc, argv);   // must exist before any widget is created
    MainWindow window;
    window.show();                  // widgets are hidden by default
    return app.exec();              // starts the event loop; blocks here
}
```

Build and run:

```bash
mkdir build && cd build
cmake ..
cmake --build .
./qt_day01
```

### Why This Matters (the parts that bite people later)

- **Ownership**: `new QWidget(this)` passes `this` as parent. When `MainWindow` is destroyed, Qt destroys `central`, `label`, `button` automatically. You should almost never manually `delete` a parented QObject or wrap it in a `unique_ptr` — that's a double-free waiting to happen. This is the single biggest mental model shift from your existing RAII-first C++ instincts: **parented QObjects opt out of RAII deletion in favor of tree-based ownership.**
- **`app.exec()` blocks**: everything after it in `main()` runs only after the event loop exits (window closed). This is analogous to `asyncio.run()` in your Python work — same "nothing happens until the loop starts" idea.
- **Signals/slots are type-checked at compile time** with the modern function-pointer syntax (`&QPushButton::clicked`) — prefer this over the old string-based `SIGNAL()/SLOT()` macros, which only fail at runtime.

### Exercise

Extend today's app:

1. Add a second `QPushButton` labeled "Reset" that sets the click count back to 0.
2. Add a `QLabel` showing the current time, updated via a `QTimer` (look up `QTimer::singleShot` vs a repeating `QTimer` — you'll want the repeating kind, connected to a slot that calls `QTime::currentTime()`).
3. Deliberately create a parent/child ownership bug: allocate a `QLabel` with `new QLabel()` — no parent — and add it to the layout without ever giving it a parent object. Run it under AddressSanitizer (`-fsanitize=address`, per your usual toolchain) and see what happens when the window closes. This is worth doing once so the memory model is concrete, not theoretical.

### Key Takeaways

- Qt extends C++ with `QObject`, moc-generated code, and parent/child ownership — a second ownership model that runs alongside RAII.
- `Q_OBJECT` + moc is what makes signals/slots and introspection possible; it's a build-time code generator, not runtime magic.
- `QMainWindow` requires a central widget before you can lay anything out.
- Modern function-pointer `connect()` syntax gives you compile-time type checking — always prefer it.
- The event loop (`app.exec()`) is the heartbeat of every Qt GUI app; nothing runs without it.

---

Say "next" when you're ready for Day 2 (layouts in depth, widget geometry/sizing policies, and building a proper multi-panel device-status layout), or let me know if you'd rather I batch multiple days at once given how you've paced past curricula.