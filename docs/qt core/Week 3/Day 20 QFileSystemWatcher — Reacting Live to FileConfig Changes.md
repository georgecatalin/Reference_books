[[Advanced]]

**Theory: event-driven file monitoring instead of polling**

A naive approach to "detect when the config file changes" is to poll it on a `QTimer` (Day 4) — stat the file every N seconds, compare modification time, reload if changed. This works, but it's wasteful (most polls find nothing changed) and has an inherent detection latency up to your poll interval. `QFileSystemWatcher` instead asks the OS to notify you directly (via `inotify` on Linux — the same kernel mechanism you'd use in raw C if you were watching files manually) the moment a watched file or directory changes, arriving as a signal (`fileChanged()` / `directoryChanged()`) through the event loop (Day 2), with no polling at all.

**Resolved caveats that matter in practice — these are the real gotchas, not edge cases:**

1. **Many editors don't modify a file in place.** They write a new temp file and rename it over the original (atomic-save pattern, used by vim, nano's default in some configs, and most GUI editors). `QFileSystemWatcher` watches a specific inode/path — after such a rename, the _original_ watched file may cease to exist as far as the watcher is concerned, silently dropping it from the watch list. The resolved fix: re-add the path to the watcher inside your `fileChanged()` handler, every time, unconditionally.
2. **A single `fileChanged()` signal can fire multiple times for one logical save** — some editors write, flush, and touch the file in several discrete steps. The resolved fix: debounce with a short `QTimer` (Day 4) rather than reloading on every single signal.

**Resolved example 1 — watching the Day 9 config file, correctly handling both caveats**

```cpp
#include <QCoreApplication>
#include <QFileSystemWatcher>
#include <QTimer>
#include <QDebug>
#include "configloader.h"   // from Day 9

class ConfigWatcher : public QObject
{
    Q_OBJECT
public:
    explicit ConfigWatcher(const QString &path, QObject *parent = nullptr)
        : QObject(parent), m_path(path)
    {
        m_watcher.addPath(m_path);
        connect(&m_watcher, &QFileSystemWatcher::fileChanged,
                this, &ConfigWatcher::onFileChanged);

        // Debounce timer: coalesce rapid-fire signals from a single logical save
        // into ONE reload, per the second caveat above.
        m_debounce.setSingleShot(true);
        m_debounce.setInterval(300);
        connect(&m_debounce, &QTimer::timeout, this, &ConfigWatcher::reloadConfig);

        reloadConfig();   // load once at startup, not just on change
    }

signals:
    void configReloaded(const MonitorConfig &config);

private slots:
    void onFileChanged(const QString &path)
    {
        qDebug() << "Raw fileChanged signal for:" << path;

        // Resolved fix for caveat 1: re-add the path unconditionally.
        // If the editor did an atomic rename-over-original, the watcher
        // silently stopped watching -- this re-establishes it. If the file
        // was modified in place, this is a harmless no-op re-add.
        if (!m_watcher.files().contains(m_path)) {
            qDebug() << "Path dropped from watch list (likely atomic save) -- re-adding";
            m_watcher.addPath(m_path);
        }

        // Resolved fix for caveat 2: restart the debounce timer rather than
        // reloading immediately -- multiple rapid signals collapse into one reload.
        m_debounce.start();
    }

    void reloadConfig()
    {
        qDebug() << "Reloading config from" << m_path;
        MonitorConfig cfg = ConfigLoader::load(m_path);
        emit configReloaded(cfg);
    }

private:
    QString m_path;
    QFileSystemWatcher m_watcher;
    QTimer m_debounce;
};

#include "main.moc"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    ConfigWatcher watcher("mqtt_monitor.ini");
    QObject::connect(&watcher, &ConfigWatcher::configReloaded, [](const MonitorConfig &cfg) {
        qDebug() << "[APPLIED] broker:" << cfg.brokerHost << ":" << cfg.brokerPort
                  << " temp threshold:" << cfg.tempHighThreshold;
    });

    qDebug() << "Watching for config changes. Edit mqtt_monitor.ini now to test.";
    return app.exec();
}
```

**Resolved output, when you edit and save the file externally (e.g. with `vim mqtt_monitor.ini`, changing `temp_high` from 30.5 to 35.0):**

```
Watching for config changes. Edit mqtt_monitor.ini now to test.
Reloading config from "mqtt_monitor.ini"
[APPLIED] broker: "192.168.1.50" : 1883  temp threshold: 30.5
Raw fileChanged signal for: "mqtt_monitor.ini"
Path dropped from watch list (likely atomic save) -- re-adding
Reloading config from "mqtt_monitor.ini"
[APPLIED] broker: "192.168.1.50" : 1883  temp threshold: 35
```

If you'd tested this with `vim`'s default atomic-save behavior and _not_ included the re-add logic, the resolved failure mode is: **the first edit works once**, then silently stops detecting any further changes at all — because the watcher lost track of the (new, renamed-in) file after the first atomic save, and nothing in your code told you it happened. This is a genuinely common, easy-to-miss production bug, and the fix (re-add unconditionally, every time) costs almost nothing.

**Resolved example 2 — watching a directory instead of a single file, useful for detecting new files appearing (e.g., a drop-folder for calibration files)**

```cpp
#include <QCoreApplication>
#include <QFileSystemWatcher>
#include <QDir>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    const QString watchDir = "./incoming_calibration";
    QDir().mkpath(watchDir);

    QFileSystemWatcher watcher;
    watcher.addPath(watchDir);

    // Track known contents ourselves -- directoryChanged() only tells you
    // "something in this directory changed," not WHAT changed. Diffing
    // against a known snapshot is the resolved way to find out what's new.
    QStringList knownFiles = QDir(watchDir).entryList(QDir::Files);

    QObject::connect(&watcher, &QFileSystemWatcher::directoryChanged,
                      [&](const QString &path) {
        QStringList currentFiles = QDir(path).entryList(QDir::Files);

        for (const QString &f : currentFiles) {
            if (!knownFiles.contains(f)) {
                qDebug() << "[NEW FILE]" << f;
            }
        }
        for (const QString &f : knownFiles) {
            if (!currentFiles.contains(f)) {
                qDebug() << "[REMOVED FILE]" << f;
            }
        }
        knownFiles = currentFiles;   // update the snapshot for next time
    });

    qDebug() << "Watching" << watchDir << "-- drop a file in there to test.";
    return app.exec();
}
```

**Resolved output, after copying a file `sensor-07-cal.json` into `./incoming_calibration/`:**

```
Watching ./incoming_calibration -- drop a file in there to test.
[NEW FILE] "sensor-07-cal.json"
```

Resolved point made explicit: `directoryChanged()` is a coarse "something happened in here" signal, with **no payload describing what** — the diffing-against-a-known-snapshot pattern shown here is the standard, necessary technique to turn that coarse notification into an actionable "this specific file appeared/vanished" event.

**Key takeaways:**

- `QFileSystemWatcher` uses OS-level notification (inotify on Linux) rather than polling — lower latency, no wasted work, delivered as a signal through the same event loop as everything else in this course.
- Atomic-save editors (common, not an edge case) can silently drop a watched file from the watch list after a save — always re-add the path unconditionally inside your `fileChanged()` handler as standard defensive practice.
- A single logical save can fire `fileChanged()` multiple times — debounce with a short `QTimer` rather than reloading on every raw signal.
- `directoryChanged()` tells you _that_ something changed in a directory, not _what_ — diff against a previously-known file listing to determine specifically which files appeared, were removed, or (less commonly detectable this way) were modified.
- This exact pattern — watch config, debounce, reload, emit a signal with the new config — is the correct way to give `mqtt_monitor` live config reload without a restart, directly usable with Day 9's `ConfigLoader`.

Day 21 is Week 3's mini-project: a multi-threaded worker pool (Day 18's `QThreadPool`/`QRunnable`) processing a batch of simulated sensor readings end-to-end — parsing (Day 11/14), thread-safe result collection (Day 17), and reporting — synthesizing everything from Days 15–20 into one complete component.