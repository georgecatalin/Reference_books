[[Intermediate Concepts]]

This week's synthesis: a complete pipeline taking raw serial-style text lines, parsing them with regex (Day 11), validating and holding them in a proper `QObject`-derived model (Day 12), UTC-timestamping correctly (Day 13), and serializing to JSON (Day 10) — ready to hand off to file storage (Day 8) or, later, an MQTT publish call. This is structurally the ingestion half of `mqtt_monitor`.

**Design, resolved up front:**

- `SerialLineParser` — a plain class (not even a `QObject`; it has no state worth observing and no signals to emit) whose sole job is: raw line in, `std::optional<ParsedFields>` out. Malformed lines resolve to `std::nullopt` rather than a partially-populated struct.
- `DeviceReading` — reused directly from Day 12, but now populated _from_ parsed fields rather than by hand.
- `ReadingSerializer` — converts a `DeviceReading` to a `QJsonObject`, applying Day 13's UTC discipline explicitly at the JSON boundary.
- A small `main()` driving a batch of raw lines through the full pipeline, showing both success and rejection paths.

**Resolved code:**

```cpp
// seriallineparser.h
#pragma once
#include <QString>
#include <QRegularExpression>
#include <optional>

struct ParsedFields
{
    QString deviceId;
    double temperature;
};

class SerialLineParser
{
public:
    // Per Day 11: constructed once, reused across every call -- no recompilation per line.
    SerialLineParser()
        : m_pattern(R"(^SENSOR:(?<id>\d+):TEMP:(?<temp>-?\d+\.?\d*)$)")
    {
    }

    std::optional<ParsedFields> parse(const QString &rawLine) const
    {
        QRegularExpressionMatch match = m_pattern.match(rawLine);
        if (!match.hasMatch()) {
            return std::nullopt;   // resolved: malformed line -> caller decides what to do (skip, log, count)
        }

        ParsedFields fields;
        fields.deviceId = "sensor-" + match.captured("id");   // normalize to match Day 12's "sensor-NN" convention
        fields.temperature = match.captured("temp").toDouble();
        return fields;
    }

private:
    QRegularExpression m_pattern;
};
```

```cpp
// readingserializer.h
#pragma once
#include <QJsonObject>
#include "devicereading.h"   // from Day 12

class ReadingSerializer
{
public:
    static QJsonObject toJson(const DeviceReading &reading)
    {
        QJsonObject obj;
        obj["device_id"] = reading.deviceId();
        obj["temperature"] = reading.temperature();
        // Resolved per Day 13: ALWAYS serialize the UTC form explicitly here,
        // regardless of what timeSpec the QDateTime happens to carry --
        // this is the pipeline's single point of truth for timestamp correctness,
        // so a mistake anywhere upstream can't silently leak a local-time value out.
        obj["timestamp_utc"] = reading.timestamp().toUTC().toString(Qt::ISODateWithMs) + "Z";
        obj["online"] = reading.isOnline();
        return obj;
    }
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include <QJsonDocument>
#include <QDebug>
#include "seriallineparser.h"
#include "devicereading.h"
#include "readingserializer.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QStringList rawLines = {
        "SENSOR:07:TEMP:23.5",
        "SENSOR:12:TEMP:-4.2",
        "SENSOR:03:TEMP:19",
        "GARBAGE\x00\x01NOISE",              // malformed: total garbage
        "SENSOR:99:TEMP:abc",                 // malformed: non-numeric temperature
        "SENSOR:07:TEMP:23.5:EXTRA:field",    // malformed: unexpected trailing data
    };

    SerialLineParser parser;
    int acceptedCount = 0, rejectedCount = 0;

    for (const QString &line : rawLines) {
        std::optional<ParsedFields> parsed = parser.parse(line);

        if (!parsed) {
            qWarning() << "REJECTED (unparseable):" << line;
            ++rejectedCount;
            continue;   // resolved: no partial record, no guesswork -- move on
        }

        // Build the validated, typed model object (Day 12)
        DeviceReading reading;
        reading.setDeviceId(parsed->deviceId);
        reading.setTemperature(parsed->temperature);
        reading.setTimestamp(QDateTime::currentDateTimeUtc());   // resolved per Day 13: UTC, always
        reading.setOnline(true);

        // Serialize to JSON (Day 10), through the single serialization point of truth
        QJsonObject json = ReadingSerializer::toJson(reading);
        QJsonDocument doc(json);

        qDebug() << "ACCEPTED:" << doc.toJson(QJsonDocument::Compact);
        ++acceptedCount;
    }

    qDebug() << "\n--- Pipeline summary ---";
    qDebug() << "Accepted:" << acceptedCount << "  Rejected:" << rejectedCount;

    return 0;
}
```

**CMakeLists.txt:**

```cmake
cmake_minimum_required(VERSION 3.16)
project(day14_pipeline)
set(CMAKE_CXX_STANDARD 17)
set(CMAKE_AUTOMOC ON)
find_package(Qt6 REQUIRED COMPONENTS Core)
add_executable(day14_pipeline main.cpp devicereading.h seriallineparser.h readingserializer.h)
target_link_libraries(day14_pipeline Qt6::Core)
```

**Resolved output (timestamps illustrative):**

```
ACCEPTED: {"device_id":"sensor-07","online":true,"temperature":23.5,"timestamp_utc":"2026-07-22T14:45:12.001Z"}
ACCEPTED: {"device_id":"sensor-12","online":true,"temperature":-4.2,"timestamp_utc":"2026-07-22T14:45:12.001Z"}
ACCEPTED: {"device_id":"sensor-03","online":true,"temperature":19,"timestamp_utc":"2026-07-22T14:45:12.001Z"}
REJECTED (unparseable): "GARBAGE\u0000\u0001NOISE"
REJECTED (unparseable): "SENSOR:99:TEMP:abc"
REJECTED (unparseable): "SENSOR:07:TEMP:23.5:EXTRA:field"

--- Pipeline summary ---
Accepted: 3   Rejected: 3
```

**Resolved design decisions, explained explicitly:**

- **`SerialLineParser` returns `std::optional`, not a bool + out-parameter:** this is a deliberate, modern-C++ resolution — `std::optional<ParsedFields>` makes "might not have a result" part of the type signature itself, and forces the caller (via `if (!parsed)`) to handle the rejection path before touching `parsed->deviceId`, rather than risking use of an uninitialized struct if someone forgets to check a bool return.
- **Trailing-data line correctly rejected:** `"SENSOR:07:TEMP:23.5:EXTRA:field"` fails because the regex is anchored with `$` (Day 11) right after the temperature — anything trailing causes a full-line match failure. This is exactly the anchoring discipline from Day 11 doing its job: without it, this line might have partially matched and silently dropped the `EXTRA:field` suffix rather than flagging it as genuinely malformed.
- **`ReadingSerializer` as the single point of truth for timestamp serialization:** even though `DeviceReading::setTimestamp()` was called correctly with `currentDateTimeUtc()` in `main()`, the serializer _still_ calls `.toUTC()` defensively before formatting. This is intentional defense-in-depth: if some future code path ever sets a `DeviceReading`'s timestamp incorrectly (e.g. from a bug, or from user-provided local time), the JSON output is still guaranteed correct at the one place data actually leaves the pipeline — you don't have to audit every call site that constructs a `DeviceReading`, only this one serialization boundary.
- **No premature signal/slot wiring:** notice `DeviceReading`'s `NOTIFY` signals from Day 12 aren't used here at all — this pipeline is a straight-line batch transformation, not an event-driven live system. This is a deliberate, resolved point: Q_PROPERTY/NOTIFY earns its keep when something needs to _observe_ live changes (a dashboard, a log-on-change hook); a one-shot parse-and-serialize pipeline doesn't need that machinery, and forcing signal/slot wiring in everywhere regardless of whether anything's listening would be unnecessary complexity.

**Key takeaways (mini-project synthesis):**

- A real ingestion pipeline is a composition of single-responsibility pieces: parse (Day 11) → validate/model (Day 12) → correctly timestamp (Day 13) → serialize (Day 10) → (eventually) persist or transmit (Day 8, Week 4). Each stage has one job and a clear boundary with the next.
- `std::optional` for "parse might fail" is preferable to bool+out-param or throwing an exception for routine, expected-to-happen-sometimes rejection (as opposed to a truly exceptional condition) — this is a modern C++ idiom that composes cleanly with the rest of your systems background.
- Anchoring regexes (Day 11) is what correctly rejects "looks almost right but has unexpected trailing data" — a realistic corruption mode for real serial lines, not just a hypothetical.
- Put defensive correctness (like re-asserting UTC at serialization) at the single boundary where data leaves your system, rather than trusting every upstream call site got it right — this is cheap insurance against a whole class of future bugs.

Week 3 starts Day 15 with the theory of Qt's threading model — thread affinity, per-thread event loops, and exactly what "queued connection across threads" (mentioned back on Day 3) really does under the hood — before Day 16 gets into the correct `QThread` + `moveToThread` worker pattern that you'll use to move blocking work (like real serial I/O) off the main thread without breaking the event-loop discipline from Day 2.