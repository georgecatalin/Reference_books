[[Qt Quick QML]]

## Day 5: Proper C++/QML Integration — `Q_INVOKABLE`, Singletons, `qmlRegisterType`, and When to Mix Widgets + QML

### Concept: `setContextProperty` Doesn't Scale — Know the Better Patterns

Day 4's `setContextProperty` is the simplest bridge, but it has real limits for a growing application: every context property is global to the engine, there's no per-instance lifecycle, and it doesn't compose well once you have several C++ services (an `MqttWorker`, an `ApiClient`, a settings object) all needing QML access. Today covers the actual patterns a production app uses instead.

### `Q_INVOKABLE` — Calling C++ Methods Directly From QML

Beyond exposing data via models, you often need QML to trigger real C++ actions — "connect to this broker," "fetch this device's history." `Q_INVOKABLE` makes a C++ method callable directly from QML, the same way properties are readable:

```cpp
// apiclient.h — your REAL ApiClient from the integration build, extended
class ApiClient : public QObject {
    Q_OBJECT
public:
    explicit ApiClient(const QString &baseUrl, QObject *parent = nullptr);

    // Q_INVOKABLE — makes this callable AS A FUNCTION directly from QML,
    // exactly like calling any JS function. This is distinct from a slot:
    // slots are for signal connections, Q_INVOKABLE is for direct QML calls.
    // (Slots are ALSO callable from QML, historically — but Q_INVOKABLE is
    // the explicit, intention-revealing choice for "this method is a QML API.")
    Q_INVOKABLE void fetchHistory(const QString &deviceId, const QString &variable, int limit);
    Q_INVOKABLE void checkHealth();

    // ... existing signals/methods from your integration build, unchanged ...
};
```

Using it from QML — no context property boilerplate needed beyond exposing the instance once:

```qml
Button {
    text: "Load History"
    onClicked: apiClient.fetchHistory(card.deviceId, "temperature", 100)
    // Reads exactly like calling a JS method — QML doesn't distinguish
    // syntactically between calling a Q_INVOKABLE C++ method and any
    // other function call
}
```

### QML Singletons — App-Wide Services Without Passing References Everywhere

For genuinely app-wide services (a settings object, your `ApiClient`, a connection-state tracker), passing the instance down through every component's properties is tedious and couples components unnecessarily. QML singletons solve this: **any QML file, anywhere in the module, can reference the singleton directly by type name, with zero property-passing.**

```cpp
// Registering a C++ class as a QML singleton — main.cpp
#include <QQmlContext>

int main(int argc, char *argv[]) {
    QGuiApplication app(argc, argv);

    QQmlApplicationEngine engine;

    // qmlRegisterSingletonInstance — the modern (Qt 6) way to expose ONE
    // C++ object as a genuine QML singleton type, accessible by name from
    // ANY .qml file in this engine, without manual context-property wiring
    // per-file
    static ApiClient apiClientInstance("http://localhost:8000");
    qmlRegisterSingletonInstance("MqttMonitor", 1, 0, "ApiClient", &apiClientInstance);

    engine.load(QUrl("qrc:/main.qml"));
    // ...
}
```

Using it from **any** QML file in the project, no property passing required:

```qml
import QtQuick
import MqttMonitor 1.0   // the module name/version from qmlRegisterSingletonInstance

Button {
    text: "Check Backend Health"
    onClicked: ApiClient.checkHealth()   // referenced directly BY TYPE NAME —
                                           // this works identically whether
                                           // this button lives in main.qml or
                                           // three component-nesting-levels deep
}
```

**This is the real answer to "how do I avoid passing my C++ services through ten layers of QML components"** — singletons are the correct tool specifically for genuinely app-wide, single-instance services. Don't reach for this pattern for anything that should have multiple instances (that's `qmlRegisterType`, next) or anything that's meaningfully scoped to one part of the UI (that stays a regular property).

### `qmlRegisterType` — When You Need Multiple Instances, Created _From_ QML

Singletons are for one shared instance. Sometimes you want QML itself to be able to **create** instances of a C++ type — less common for `mqtt_monitor` specifically (you'll usually create your workers once in `main.cpp`), but genuinely useful for, say, a reusable "countdown timer" C++ helper class multiple QML components might each want their own instance of:

```cpp
// Registering a TYPE (not an instance) — QML can now do "MyHelper { }"
// and get a fresh C++ object each time, same as instantiating any QML type
qmlRegisterType<CountdownHelper>("MqttMonitor", 1, 0, "CountdownHelper");
```

```qml
import MqttMonitor 1.0

CountdownHelper {
    id: timer
    seconds: 30
    onFinished: console.log("Countdown done")
}
```

**The genuine distinction to hold onto**: `qmlRegisterSingletonInstance` = "one shared C++ object, referenced everywhere by type name." `qmlRegisterType` = "a C++ class QML can instantiate itself, potentially many times, like any built-in QML type." Confusing these two is a real design mistake — making your `ApiClient` a `qmlRegisterType` instead of a singleton would mean every QML file that writes `ApiClient { }` gets its **own separate network client with its own connection state**, which is almost certainly not what you want for a single backend connection.

### The Real Decision: Widgets + QML in One Process, or Fully Separate Apps?

This is a genuine architecture decision worth making deliberately, not by default. Qt supports **both** in the same process via `QQuickWidget` (embed a QML scene inside a Widgets window) or `QWidget`-in-QML approaches, but mixing them has real costs:

**Reasons to mix (genuine, not hypothetical):**

- You want your existing Widgets admin/debug dashboard (the one you built across 30 days) _and_ a QML touchscreen panel, sharing the exact same backend objects (`MqttWorker`, `DeviceTableModel`) in one running process, rather than running two separate applications against the same broker/API independently.
- You have one specific screen (a live gauge cluster, a fluid animated view) that's genuinely better in QML, embedded inside an otherwise-Widgets-based application.

**Reasons to keep them fully separate (the more common real answer):**

- Two rendering systems in one process means two sets of GPU/CPU overhead, more startup cost, and genuinely more complex debugging (which stack does this frame time belong to?) — for a resource-constrained embedded target, this tax is real, not theoretical.
- Your actual deployment shape (Day 27 of the Widgets curriculum) was "GUI as viewer, wherever a human is" — if the QML touchscreen panel and the Widgets dashboard are genuinely different deployment targets (a wall-mounted Pi vs. a dev workstation), **they don't need to be the same process at all** — both can be separate executables, both built from the same C++ backend classes (`MqttWorker`, `DeviceTableModel`, `ApiClient` — none of which are Widgets- or QML-specific), just with different `main.cpp` entry points and different presentation layers.

**The practical recommendation for `mqtt_monitor` specifically**: keep them **separate executables sharing the same backend source files** (a shared static library or just shared `.cpp`/`.h` files compiled into both targets), rather than one process embedding both UI stacks. This avoids the real performance/complexity tax of mixing rendering systems, while still giving you "write the ingestion/model logic once, use it from two different front ends" — which was the entire value proposition motivating this QML curriculum in the first place.

### CMake Structure Reflecting This Decision

```cmake
# A shared library holding your UI-agnostic backend — MqttWorker,
# SerialWorker, ApiClient, DeviceTableModel, PersistenceWorker — NONE
# of which include <QWidget> or QML-specific headers
add_library(mqtt_monitor_core STATIC
    mqttworker.cpp
    serialworker.cpp
    apiclient.cpp
    devicetablemodel.cpp
    persistenceworker.cpp
)
target_link_libraries(mqtt_monitor_core PUBLIC Qt6::Core Qt6::Mqtt Qt6::SerialPort Qt6::Network Qt6::Sql)

# The Widgets front end (your existing 30-day build)
add_executable(mqtt_monitor_widgets main_widgets.cpp mainwindow.cpp mainwindow.h connectiondialog.cpp connectiondialog.h)
target_link_libraries(mqtt_monitor_widgets PRIVATE mqtt_monitor_core Qt6::Widgets)

# The QML front end (this curriculum's build) — separate executable,
# separate process, SAME backend library
add_executable(mqtt_monitor_qml main_qml.cpp qml.qrc)
target_link_libraries(mqtt_monitor_qml PRIVATE mqtt_monitor_core Qt6::Quick Qt6::Qml)
```

**This is genuinely the right structure** — `DeviceTableModel` doesn't care whether a `QTableView` or a QML `ListView` is reading it; it was never Widgets-specific to begin with (it only ever depended on `QAbstractItemModel`, part of Qt Core). Extracting it into a shared static library makes that fact structurally explicit instead of just true-but-unenforced.

### Why This Matters

- **`Q_INVOKABLE` vs. signals/slots**: slots historically doubled as QML-callable methods too, but `Q_INVOKABLE` is the clearer, intention-revealing choice specifically for "this method is part of my QML-facing API surface" — worth using deliberately rather than relying on the slot-callable-from-QML historical accident.
- **Singleton vs. registered type is a real design decision with real consequences** — get this backwards (registering a shared service as an instantiable type) and you'll silently end up with multiple independent instances where you needed exactly one, a genuinely confusing bug to track down since QML gives you no obvious warning when this happens.
- **The Widgets+QML mixing decision has real performance costs on embedded targets** — this isn't a purity argument, it's a concrete resource-budget argument specifically relevant given your Pi/BeagleBone deployment reality.
- **Extracting shared backend code into its own library/target** is what makes "one backend, multiple front ends" a structurally enforced fact rather than an accidental one — this is the same architectural discipline as Day 24's "one ingestion choke point," now applied at the build-system level rather than the function-call level.

### Exercise

1. Extract your actual integration build's `MqttWorker`, `SerialWorker`, `ApiClient`, and `DeviceTableModel` into a shared static library target, and confirm your existing Widgets executable still builds and runs correctly linking against it — this proves the "backend was never Widgets-specific" claim concretely rather than asserting it.
2. Register your real `ApiClient` as a QML singleton (not a context property) using today's pattern, and wire a QML button to call `ApiClient.checkHealth()` — confirm the health-check signal still reaches a QML `Text` element correctly (Day 4's role-name/binding mechanisms apply identically to consuming signals from a singleton).
3. Write a one-paragraph note (mirroring Day 27's architecture-decision exercise) on whether `mqtt_monitor` will run its QML and Widgets front ends as fully separate deployed executables, or ever embed one inside the other — make the call explicitly for your actual project rather than leaving it implicit.

### Key Takeaways

- `Q_INVOKABLE` is the explicit, intention-revealing way to make a C++ method directly callable from QML as if it were a JS function.
- `qmlRegisterSingletonInstance` exposes one shared C++ object by type name to every QML file in the module — the correct tool for genuinely app-wide services (your `ApiClient`, a settings object).
- `qmlRegisterType` lets QML instantiate its own instances of a C++ class — correct only when multiple independent instances are actually wanted; using it for a shared service is a real, easy-to-make design mistake.
- Mixing Widgets and QML in one process is possible (`QQuickWidget`) but has real performance/complexity costs on embedded targets — for `mqtt_monitor`, separate executables sharing a common backend static library is the more defensible default.
- Extracting UI-agnostic backend code into its own library target structurally enforces "one backend, multiple front ends" rather than leaving it as an unenforced convention.

---

Say "next" for Day 6 (states and transitions — QML's declarative alternative to imperative UI-state management, plus real animations done the QML way, mapped against Day 12's `QPropertyAnimation` work).