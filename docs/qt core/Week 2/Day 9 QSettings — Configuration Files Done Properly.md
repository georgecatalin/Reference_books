[[Intermediate Concepts]]

**Theory: why QSettings exists instead of hand-rolling a config parser**

You mentioned prior familiarity with PHP-based ingestion scripts — likely reading config via manual `parse_ini_file()`-style calls or hand-rolled key=value parsing. `QSettings` solves the same problem inside Qt Core, but with three things a hand-rolled parser typically lacks:

1. **Automatic type round-tripping via `QVariant`** (Day 6) — you write an `int`, a `bool`, a `QStringList`, and read back the same type, without manual string-to-type conversion scattered through your code.
2. **Hierarchical grouping** (`[Section]`-style in INI, but with a proper API — `beginGroup()`/`endGroup()`) — avoids string-concatenation key names like `"mqtt_broker_host"` in favor of structured `"mqtt/broker_host"`.
3. **Platform-appropriate storage** — on Linux, `QSettings::NativeFormat` defaults to files under `~/.config/`, following the freedesktop.org convention, without you writing that path logic yourself. You can also force `QSettings::IniFormat` for a fully portable, explicit file path — which is what you'll want for a deployed service like `mqtt_monitor` where you control the exact config file location (e.g. `/etc/mqtt_monitor/config.ini`).

**Resolved example 1 — writing and reading typed config values, INI format, explicit path**

```cpp
#include <QCoreApplication>
#include <QSettings>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    const QString configPath = "mqtt_monitor.ini";

    // --- Write config ---
    {
        QSettings settings(configPath, QSettings::IniFormat);

        settings.beginGroup("mqtt");
        settings.setValue("broker_host", "192.168.1.50");
        settings.setValue("broker_port", 1883);
        settings.setValue("use_tls", false);
        settings.endGroup();

        settings.beginGroup("serial");
        settings.setValue("device_path", "/dev/ttyUSB0");
        settings.setValue("baud_rate", 115200);
        settings.endGroup();

        settings.beginGroup("thresholds");
        settings.setValue("temp_high", 30.5);
        settings.setValue("sensor_ids", QStringList{"sensor-01", "sensor-02", "sensor-03"});
        settings.endGroup();

        // settings.sync() forces an immediate flush to disk -- normally happens
        // automatically at destruction, but calling it explicitly here proves
        // the file exists before we open a second QSettings instance below.
        settings.sync();
    }

    // --- Read config back, with type-correct retrieval and sensible defaults ---
    {
        QSettings settings(configPath, QSettings::IniFormat);

        settings.beginGroup("mqtt");
        QString host = settings.value("broker_host", "localhost").toString();
        int port = settings.value("broker_port", 1883).toInt();
        bool tls = settings.value("use_tls", false).toBool();
        settings.endGroup();

        qDebug() << "MQTT broker:" << host << ":" << port << "TLS:" << tls;

        settings.beginGroup("serial");
        QString devicePath = settings.value("device_path").toString();
        int baud = settings.value("baud_rate", 9600).toInt();   // default used only if key missing
        settings.endGroup();

        qDebug() << "Serial device:" << devicePath << "at" << baud << "baud";

        settings.beginGroup("thresholds");
        double tempHigh = settings.value("temp_high").toDouble();
        QStringList sensorIds = settings.value("sensor_ids").toStringList();
        settings.endGroup();

        qDebug() << "High temp threshold:" << tempHigh;
        qDebug() << "Monitored sensors:" << sensorIds;

        // Resolved: a key that was never written, with an explicit default
        int missingRetries = settings.value("network/max_retries", 3).toInt();
        qDebug() << "max_retries (using default, key never set):" << missingRetries;
    }

    return 0;
}
```

**Resolved output:**

```
MQTT broker: "192.168.1.50" : 1883 TLS: false
Serial device: "/dev/ttyUSB0" at 115200 baud
High temp threshold: 30.5
Monitored sensors: ("sensor-01", "sensor-02", "sensor-03")
max_retries (using default, key never set): 3
```

**Resolved contents of `mqtt_monitor.ini`** (worth seeing exactly what got written, since it's a real file you could hand-edit or deploy):

```ini
[mqtt]
broker_host=192.168.1.50
broker_port=1883
use_tls=false

[serial]
device_path=/dev/ttyUSB0
baud_rate=115200

[thresholds]
temp_high=30.5
sensor_ids=sensor-01, sensor-02, sensor-03
```

Note the resolved detail: `QStringList` round-trips through `setValue()`/`toStringList()` automatically — you never manually split/join a comma-separated string yourself; `QVariant`'s type system (Day 6) handles it.

**Resolved example 2 — the correct pattern for a typed config struct, avoiding string-key typos scattered through the codebase**

A real risk with `QSettings` used directly everywhere: typo a key string (`"broker_hst"`) in one of twenty places that reads it, and you silently get the default value with no error — `QSettings` has no way to catch this at compile time, since keys are just strings. The resolved fix: centralize all reads into one loader function that populates a plain struct, so the rest of your codebase never touches `QSettings` or raw key strings directly.

```cpp
// config.h
#pragma once
#include <QString>
#include <QStringList>

struct MonitorConfig
{
    QString brokerHost;
    int brokerPort;
    bool useTls;
    QString serialDevicePath;
    int baudRate;
    double tempHighThreshold;
    QStringList sensorIds;
};
```

```cpp
// configloader.h
#pragma once
#include <QSettings>
#include "config.h"

class ConfigLoader
{
public:
    static MonitorConfig load(const QString &path)
    {
        QSettings settings(path, QSettings::IniFormat);
        MonitorConfig cfg;

        settings.beginGroup("mqtt");
        cfg.brokerHost = settings.value("broker_host", "localhost").toString();
        cfg.brokerPort = settings.value("broker_port", 1883).toInt();
        cfg.useTls = settings.value("use_tls", false).toBool();
        settings.endGroup();

        settings.beginGroup("serial");
        cfg.serialDevicePath = settings.value("device_path", "/dev/ttyUSB0").toString();
        cfg.baudRate = settings.value("baud_rate", 9600).toInt();
        settings.endGroup();

        settings.beginGroup("thresholds");
        cfg.tempHighThreshold = settings.value("temp_high", 30.0).toDouble();
        cfg.sensorIds = settings.value("sensor_ids").toStringList();
        settings.endGroup();

        return cfg;   // one function, one place where key-string typos could hide --
                       // everywhere else in the codebase, it's just cfg.brokerHost, compiler-checked.
    }
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include <QDebug>
#include "configloader.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    MonitorConfig cfg = ConfigLoader::load("mqtt_monitor.ini");

    qDebug() << "Loaded config -- broker:" << cfg.brokerHost << ":" << cfg.brokerPort;
    qDebug() << "Serial:" << cfg.serialDevicePath << "@" << cfg.baudRate;
    qDebug() << "Sensors tracked:" << cfg.sensorIds.size();

    return 0;
}
```

**Resolved output:**

```
Loaded config -- broker: "192.168.1.50" : 1883
Serial: "/dev/ttyUSB0" @ 115200
Sensors tracked: 3
```

This is the resolved architectural point: `QSettings`' string-key nature is a real risk in a codebase of any size, and the fix isn't "be careful" — it's **structural isolation**: exactly one function touches raw key strings, everything downstream uses a compiler-checked struct. This is the pattern you'd use to load `mqtt_monitor`'s actual deployment config (broker address, serial device, thresholds) once, at startup, before constructing any of your service objects.

**Key takeaways:**

- `QSettings` gives typed, hierarchical config storage via the same `QVariant` machinery from Day 6 — no manual string-to-type conversion.
- Use `QSettings::IniFormat` with an explicit path for deployed services where you control the exact config file location; `NativeFormat` is more appropriate for desktop-style per-user settings you don't want to manage the path for yourself.
- `value(key, default)` — always supply a sensible default; a missing key silently returns it rather than erroring, so defaults are your only protection against a missing or freshly-added config key.
- Centralize all `QSettings` reads into one loader producing a plain struct — this converts a class of silent, hard-to-find string-typo bugs into compile-time-checked struct field access everywhere else in the codebase.

Day 10 moves to `QJsonDocument`/`QJsonObject`/`QJsonArray` — directly relevant to your MQTT work, since device readings published to a broker are almost always JSON payloads, and you'll see how `QVariant` (Day 6) bridges cleanly into and out of Qt's JSON types.