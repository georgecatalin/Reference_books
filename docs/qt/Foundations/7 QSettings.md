[[Foundations]]

## Day 7: `QSettings` — Persisting Preferences, Window State, and Why the Backend Choice Matters

### Concept: `QSettings` Is a Key-Value Store With a Platform-Native Backend You Don't Usually Choose Explicitly

`QSettings` gives you persistent application configuration — window size/position, last-used broker host, theme choice — without you managing file paths or formats yourself. The critical thing to understand: **by default, the storage backend is platform-specific** — Windows Registry on Windows, `.plist` on macOS, INI-style files under `~/.config` on Linux. You usually don't need to care _where_ it lives, but you do need to understand the format implications, especially for a project you might later containerize or run headless on a Raspberry Pi.

For `mqtt_monitor` targeting embedded Linux, you'll almost always want to force the **INI format explicitly** rather than rely on platform defaults — it's portable, human-readable, diffable, and doesn't depend on a registry-like system that doesn't exist on embedded targets anyway.

### Annotated Code: Correct `QSettings` Usage

`main.cpp` — set the app identity **before** creating any `QSettings` instance. This is the part everyone forgets and then wonders why settings aren't found:

```cpp
#include <QApplication>
#include <QSettings>
#include "mainwindow.h"

int main(int argc, char *argv[]) {
    QApplication app(argc, argv);

    // These MUST be set before any QSettings() default-constructor call
    // anywhere in the app — QSettings uses these to build the storage path.
    QCoreApplication::setOrganizationName("GeorgeLabs");
    QCoreApplication::setApplicationName("mqtt_monitor");

    // Force INI format explicitly — portable across Linux/embedded targets,
    // avoids registry dependence on Windows, avoids plist quirks on macOS.
    QSettings::setDefaultFormat(QSettings::IniFormat);

    MainWindow window;
    window.show();
    return app.exec();
}
```

`mainwindow.h` additions:

```cpp
protected:
    void closeEvent(QCloseEvent *event) override; // save on exit

private:
    void loadSettings();
    void saveSettings();
```

`mainwindow.cpp`:

```cpp
#include <QSettings>
#include <QCloseEvent>

void MainWindow::loadSettings() {
    // Default-constructed QSettings() uses the org/app name set in main()
    QSettings settings;

    // beginGroup/endGroup namespaces keys — avoids collisions between
    // unrelated settings sections ("window/geometry" vs "mqtt/host")
    settings.beginGroup("window");
    restoreGeometry(settings.value("geometry").toByteArray());
    restoreState(settings.value("state").toByteArray()); // toolbar/dock positions
    settings.endGroup();

    settings.beginGroup("mqtt");
    // .value(key, defaultValue) — always provide a sensible default;
    // first run of the app has no saved settings at all
    QString host = settings.value("brokerHost", "localhost").toString();
    int port = settings.value("brokerPort", 1883).toInt();
    settings.endGroup();

    logView->append(QString("[CONFIG] Loaded settings: %1:%2").arg(host).arg(port));

    // Store loaded values as members if you need them elsewhere
    // (e.g., to pre-fill ConnectionDialog next time it opens)
}

void MainWindow::saveSettings() {
    QSettings settings;

    settings.beginGroup("window");
    settings.setValue("geometry", saveGeometry()); // QByteArray, opaque but stable
    settings.setValue("state", saveState());
    settings.endGroup();

    settings.beginGroup("mqtt");
    settings.setValue("brokerHost", "localhost"); // replace with actual current value
    settings.setValue("brokerPort", 1883);
    settings.endGroup();

    // No explicit "save" or "flush" call needed for normal exit —
    // QSettings writes to disk on destruction. But see the crash note below.
}

void MainWindow::closeEvent(QCloseEvent *event) {
    saveSettings();
    event->accept(); // allow the window to actually close
}
```

Call `loadSettings()` in the constructor, **after** all widgets exist (since `restoreState()` needs toolbars/dockwidgets already created):

```cpp
MainWindow::MainWindow(QWidget *parent) : QMainWindow(parent) {
    // ... all your Day 1-6 setup (panels, actions, menus, toolbar) ...

    loadSettings(); // must come after createToolbar() etc.
}
```

### The Crash/Power-Loss Caveat

`QSettings` normally batches writes and flushes on destruction or periodically. If your process could be killed abruptly — which, running on embedded Linux targets with `mqtt_monitor`, is a completely realistic scenario (power loss, `kill -9`, watchdog reset) — call `settings.sync()` explicitly after critical writes:

```cpp
settings.setValue("mqtt/lastKnownGoodBroker", host);
settings.sync(); // force immediate flush to disk, don't wait for destruction
```

Use this sparingly — `sync()` is a synchronous disk write, not something you want in a hot path (like after every single MQTT message), only after meaningful state changes.

### Storing Structured/Complex Data

`QSettings` handles basic types (`QString`, `int`, `bool`, `QByteArray`) and `QVariant`-compatible types natively, but for a list of recent broker connections (a `QList<ConnectionProfile>` in your own struct), you have two real options:

1. **Serialize to JSON, store as a string** — simplest, and given your Python/FastAPI/Pydantic background, this will feel completely natural:

```cpp
#include <QJsonDocument>
#include <QJsonArray>
#include <QJsonObject>

QJsonArray recentConnections;
QJsonObject conn;
conn["host"] = "localhost";
conn["port"] = 1883;
recentConnections.append(conn);

settings.setValue("mqtt/recentConnections",
                   QJsonDocument(recentConnections).toJson(QJsonDocument::Compact));
```

```cpp
// reading back:
QByteArray raw = settings.value("mqtt/recentConnections").toByteArray();
QJsonDocument doc = QJsonDocument::fromJson(raw);
QJsonArray array = doc.array();
for (const auto &val : array) {
    QJsonObject obj = val.toObject();
    QString host = obj["host"].toString();
    int port = obj["port"].toInt();
}
```

2. **`beginWriteArray`/`beginReadArray`** — QSettings' own native array support, less flexible than JSON but avoids a dependency on the JSON module for simple cases. In practice, given you already need `QJsonDocument` elsewhere in `mqtt_monitor` (message payloads), **option 1 (JSON) is the more consistent choice for this project** — one serialization approach used everywhere.

### Why This Matters

- Setting org/app name **before** any `QSettings` instantiation is the single most common bootstrapping bug — get it wrong and settings silently write to the wrong location or `applicationName`-less defaults.
- `saveGeometry()`/`restoreGeometry()` and `saveState()`/`restoreState()` are opaque `QByteArray` blobs — don't try to inspect or hand-edit them; they encode multi-monitor DPI-aware geometry correctly, which you do not want to reimplement yourself.
- Forcing `IniFormat` explicitly is the right call for a project with embedded Linux deployment targets — predictable location (`~/.config/GeorgeLabs/mqtt_monitor.ini`), human-inspectable, and works identically across every platform including headless CI test runners.
- `closeEvent()` is the correct hook for "save on exit" — not the destructor, which runs too late relative to other cleanup and doesn't let you veto the close (`event->ignore()`) if you needed to, e.g., prompt "unsaved changes."

### Exercise

1. Wire up the `ConnectionDialog` from Day 5 to actually load its default host/port from `QSettings` (via `loadSettings()`'s stored values) instead of hardcoded `"localhost"`/`1883`, and save whatever the user enters back on dialog acceptance.
2. Add persistence for `toggleLogAction`'s checked state (Day 5) — save it in `closeEvent()`, restore it in `loadSettings()`, so the log panel visibility choice survives a restart.
3. Implement the JSON-based "recent connections" list: store the last 5 distinct broker host/port pairs the user has connected to, and populate a `QComboBox` in `ConnectionDialog` with them on open. This is a good rehearsal for `mqtt_monitor`'s real config needs.

### Key Takeaways

- Set `setOrganizationName`/`setApplicationName` before constructing any `QSettings` — this determines the storage path/backend key.
- Force `QSettings::IniFormat` explicitly for cross-platform, embedded-Linux-friendly, diffable config files — don't rely on platform-default backends.
- `saveGeometry`/`saveState` + their restore counterparts are the correct, DPI-safe way to persist window/toolbar/dock layout — treat the `QByteArray` as opaque.
- Use `closeEvent()` (not the destructor) as your "persist on exit" hook, and call `sync()` explicitly after critical writes if abrupt process termination is a realistic risk (very relevant for embedded targets).
- For structured/nested settings data, serialize to JSON via `QJsonDocument` — consistent with the JSON handling you'll need elsewhere in `mqtt_monitor` anyway.

---

Say "next" for Day 8 (wrapping up Phase 1 with a review/integration day: pulling Days 1-7 together into one cohesive, buildable application skeleton — the actual foundation you'll build all of Phase 2 on top of).