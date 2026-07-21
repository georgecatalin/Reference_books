[[Foundations]]

## Concept: What Qt Quick actually is (and how it differs from what you know)

You already know C++ imperative control flow: you write steps, the CPU executes them in order. Qt Quick asks you to think differently for the _UI layer_ — QML is **declarative**. You don't say "when the button is clicked, move the rectangle." You say "this rectangle's x-position **is** `slider.value * 2`" — and Qt maintains that relationship automatically, forever, via a binding. This is closer to a spreadsheet than to a procedural program: change one cell, everything depending on it updates.

This matters practically: most QML bugs beginners hit aren't syntax errors, they're **binding loops** or **accidentally breaking a binding** by assigning a value imperatively where you meant to declare a relationship. Keep this distinction in your head from day one — we'll come back to it constantly.

**The architecture, concretely:**

- **QML** — declarative UI description (what the interface looks like and how its pieces relate)
- **JavaScript** — glue logic _inside_ QML for small transformations (not your application logic)
- **C++** — your real logic, data, I/O, threading — exposed _into_ QML through a small number of well-defined bridges (Day 9 onward)

For `mqtt_monitor`, the split will look like: C++ handles serial ingestion, MQTT client, SQLite persistence, threading — QML handles rendering the device list, live gauges, and connection status. Same separation of concerns you already use between your `mqtt_monitor` core and its REST API — just applied to UI instead of network.

## Setup

Install Qt 6 via the official installer (aqtinstall is the scriptable alternative if you want it CI/reproducible — worth knowing since you're used to Docker-based reproducible envs):

```bash
# Scriptable install (good for reproducibility, similar to how you'd pin toolchains in Docker)
pip install aqtinstall --break-system-packages
python -m aqtinstall install-qt linux desktop 6.7.0 gcc_64 \
    -m qtquick3d qtcharts qtmqtt qtserialport
```

Or use the Qt Online Installer GUI and select: **Qt 6.7 (or latest LTS)**, and under **Additional Libraries**: Qt Quick, Qt Charts, Qt MQTT, Qt Serial Port — you'll need all three later for the capstone.

Verify:

```bash
qmake6 --version
# or
qtpaths6 --qt-version
```

## Project anatomy

A minimal Qt Quick app has three pieces. Don't use Qt Creator's wizard yet — build this by hand once so the structure isn't magic.

**`main.cpp`** — the C++ entry point that boots the QML engine:

```cpp
#include <QGuiApplication>
#include <QQmlApplicationEngine>

int main(int argc, char *argv[])
{
    QGuiApplication app(argc, argv);

    QQmlApplicationEngine engine;
    const QUrl url(u"qrc:/main.qml"_qs);
    QObject::connect(&engine, &QQmlApplicationEngine::objectCreationFailed,
                      &app, []() { QCoreApplication::exit(-1); }, Qt::QueuedConnection);
    engine.load(url);

    return app.exec();
}
```

Note: `QGuiApplication`, not `QApplication` — that's the Widgets-based one. Qt Quick apps don't need it.

**`main.qml`** — your first real QML:

```qml
import QtQuick
import QtQuick.Window

Window {
    id: root
    width: 640
    height: 480
    visible: true
    title: "mqtt_monitor — Day 1"

    Rectangle {
        anchors.fill: parent
        color: "#1e1e2e"

        Text {
            id: label
            anchors.centerIn: parent
            text: "Device Monitor Online"
            color: "#cdd6f4"
            font.pixelSize: 24
        }

        MouseArea {
            anchors.fill: parent
            onClicked: {
                label.text = label.text === "Device Monitor Online"
                    ? "Device Monitor Offline"
                    : "Device Monitor Online"
            }
        }
    }
}
```

**`CMakeLists.txt`** (you already know CMake from the C++ course — this is the same mental model, just Qt-flavored):

```cmake
cmake_minimum_required(VERSION 3.16)
project(mqtt_monitor_gui LANGUAGES CXX)

set(CMAKE_AUTOMOC ON)
set(CMAKE_CXX_STANDARD 17)

find_package(Qt6 REQUIRED COMPONENTS Quick)

qt_add_executable(appMonitor main.cpp)

qt_add_qml_module(appMonitor
    URI MonitorApp
    VERSION 1.0
    QML_FILES main.qml
)

target_link_libraries(appMonitor PRIVATE Qt6::Quick)
```

Build it exactly like a normal CMake C++ project:

```bash
cmake -B build -DCMAKE_PREFIX_PATH=/path/to/Qt/6.7.0/gcc_64
cmake --build build
./build/appMonitor
```

**What just happened, architecturally:** `qt_add_qml_module` compiles your QML into the binary via the Qt Resource System (`qrc:/main.qml` — that `qrc:` prefix means "baked into the executable," not "file on disk"). This is why deployment to a Pi later is simpler than you might expect for a GUI app — no separate asset directory to ship, in the simple case.

## Key things that will bite you if skipped

- **Case sensitivity matters everywhere**: `Rectangle` (type) vs `id: rectangle` (instance name) — QML is picky, unlike loosely-typed scripting you may be used to.
- **`anchors.fill: parent` vs `width/height` bindings** — anchors are relative positioning like CSS flex, not absolute geometry. Don't mix anchors and explicit x/y/width on the same edges; it causes silent conflicts.
- **Property assignment (`:`) vs signal handler (`on...:`)** — `color: "red"` declares a binding; `onClicked: { ... }` is imperative code that runs once, on an event. Confusing these two is the #1 beginner bug.

## Exercise

Extend `main.qml`:

1. Add a `Rectangle` "status LED" (a small colored circle using `radius: width/2`) in the top-right corner.
2. Bind its `color` property to a boolean-like state (`property bool deviceOnline: true`) — green when true, red when false — using a ternary in a binding, not a signal handler.
3. Make the `MouseArea` click toggle `deviceOnline` instead of directly setting `label.text`, and have `label.text` be a **binding** derived from `deviceOnline` (not set imperatively in `onClicked`).

This exercise is the whole point of Day 1: forcing you to replace an imperative text assignment with a declarative binding, so the difference stops being abstract.

## Key takeaways

- QML is declarative — you define _relationships_ (bindings), not sequences of steps. The moment you write `onClicked: label.text = "..."` for something that could instead be `text: someCondition ? "A" : "B"`, prefer the binding.
- `main.cpp` boots a `QQmlApplicationEngine` that loads compiled-in QML via `qrc:`.
- CMake integration (`qt_add_qml_module`) is structurally similar to what you already do — new syntax, familiar workflow.
- `QGuiApplication`, not `QApplication`, for Quick apps.

Do the exercise, and let me know when you're ready for Day 2 (QML syntax, properties, and the object tree in depth) — or tell me if you'd rather I front-load several days at once given how you worked through the other courses.