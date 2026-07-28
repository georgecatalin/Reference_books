[[Intermediate Concepts]]

**Theory: the UTC-vs-local trap, and why it silently corrupts data**

`QDateTime::currentDateTime()` returns the current moment in the **system's local timezone**. This is almost never what you want to _store_ — if `mqtt_monitor` ever runs on devices in different timezones (or the same device has its system timezone changed, or DST shifts), timestamps recorded as local time become ambiguous and non-comparable: "2026-07-22 14:30:00" means a different instant depending on which machine wrote it and what its local offset was at that moment. The resolved discipline: **always store timestamps as UTC** (`QDateTime::currentDateTimeUtc()`), and only convert to local time at the point of _display_, never for storage or comparison.

A second, subtler trap: **DST transitions create genuinely ambiguous or nonexistent local times** — e.g., in a "spring forward" transition, the local time "02:30" might not exist at all that day; in "fall back," "01:30" occurs _twice_. Storing local time means your data can contain timestamps that are ambiguous or impossible to unambiguously order — a real, resolved reason to never use local time as your source of truth.

**Resolved example 1 — proving the local-vs-UTC discrepancy, and correct storage practice**

```cpp
#include <QCoreApplication>
#include <QDateTime>
#include <QTimeZone>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QDateTime localNow = QDateTime::currentDateTime();
    QDateTime utcNow = QDateTime::currentDateTimeUtc();

    qDebug() << "Local now:" << localNow.toString(Qt::ISODateWithMs);
    qDebug() << "UTC now:  " << utcNow.toString(Qt::ISODateWithMs);
    qDebug() << "Local timezone offset from UTC:" << localNow.offsetFromUtc() << "seconds";

    // Resolved: these represent the SAME INSTANT, just expressed in different zones --
    // proof: converting one to the other's frame gives an identical result.
    qDebug() << "Local now, converted to UTC:" << localNow.toUTC().toString(Qt::ISODateWithMs);
    qDebug() << "UTC now, converted to local:" << utcNow.toLocalTime().toString(Qt::ISODateWithMs);

    // The actual storage discipline: ALWAYS serialize timestamps as UTC with
    // an explicit, unambiguous format -- ISO 8601 with a 'Z' suffix, or epoch milliseconds.
    qint64 epochMs = utcNow.toMSecsSinceEpoch();
    qDebug() << "Stored form (epoch ms, timezone-independent):" << epochMs;
    qDebug() << "Stored form (ISO 8601 UTC):" << utcNow.toString(Qt::ISODateWithMs) + "Z";
    // Note: Qt's toString(Qt::ISODateWithMs) on a UTC QDateTime does NOT automatically
    // append 'Z' -- you must add it yourself if you want a strictly RFC 3339-compliant string.
    // Resolved fix for that exact issue below in example 2.

    return 0;
}
```

**Resolved output (illustrative — depends on your system's local offset; Bucharest is UTC+3 in summer/EEST):**

```
Local now: "2026-07-22T17:45:12.345"
UTC now:   "2026-07-22T14:45:12.345"
Local timezone offset from UTC: 10800 seconds
Local now, converted to UTC: "2026-07-22T14:45:12.345"
UTC now, converted to local: "2026-07-22T17:45:12.345"
Stored form (epoch ms, timezone-independent): 1753195512345
Stored form (ISO 8601 UTC): "2026-07-22T14:45:12.345Z"
```

Resolved proof: `localNow.toUTC()` and `utcNow` produce identical output — they represent the same instant. The 10800-second (3-hour) offset is EEST (Eastern European Summer Time); this is exactly the kind of offset that silently differs by season (EET is UTC+2 in winter) and would corrupt naive local-time-based comparisons across a DST boundary.

**Resolved example 2 — RFC 3339-correct serialization using `QTimeZone::utc()`, and round-tripping through `QDateTime::fromString`**

```cpp
#include <QCoreApplication>
#include <QDateTime>
#include <QTimeZone>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // Explicitly construct a QDateTime tagged with the UTC timezone --
    // this is the resolved, unambiguous way, rather than relying on toString()
    // formatting quirks to imply UTC.
    QDateTime reading(QDate(2026, 7, 22), QTime(14, 45, 12, 345), QTimeZone::UTC);

    // Qt::ISODateWithMs on a QDateTime that KNOWS it's UTC correctly appends 'Z' or '+00:00'
    QString serialized = reading.toString(Qt::ISODateWithMs);
    qDebug() << "Correctly-tagged UTC serialization:" << serialized;

    // Round-trip: parse it back and confirm it's recognized as UTC, not local
    QDateTime parsed = QDateTime::fromString(serialized, Qt::ISODateWithMs);
    qDebug() << "Parsed back, timeSpec:" << parsed.timeSpec();   // Qt::UTC
    qDebug() << "Parsed back, matches original:" << (parsed == reading);

    // Resolved: what happens if you forget to tag timezone at construction --
    // this is the actual bug this whole lesson is about.
    QDateTime untagged(QDate(2026, 7, 22), QTime(14, 45, 12, 345));   // no QTimeZone argument
    qDebug() << "\nUntagged datetime timeSpec:" << untagged.timeSpec();   // Qt::LocalTime -- NOT what you meant!
    qDebug() << "Untagged, misinterpreted as local, converted to UTC:" << untagged.toUTC().toString(Qt::ISODateWithMs);
    // Resolved: this is now WRONG by 3 hours if your intent was for 14:45:12 to BE the UTC instant --
    // Qt assumed it was local time (EEST, UTC+3) and "corrected" it to 11:45:12 UTC, silently.

    return 0;
}
```

**Resolved output:**

```
Correctly-tagged UTC serialization: "2026-07-22T14:45:12.345Z"
Parsed back, timeSpec: 1
Parsed back, matches original: true

Untagged datetime timeSpec: 2
Untagged, misinterpreted as local, converted to UTC: "2026-07-22T11:45:12.345Z"
```

(`timeSpec()` returns the `Qt::TimeSpec` enum: `1` = `Qt::UTC`, `2` = `Qt::LocalTime`.) This is the resolved core lesson made concrete: **the untagged constructor silently defaulted to local time**, and the exact same wall-clock numbers (`14:45:12.345`) end up representing a genuinely different instant — 3 hours apart — purely because of a missing `QTimeZone::UTC` argument. This is precisely the class of bug that's invisible in testing (if you only ever test on one machine, in one timezone, during one DST season) and surfaces in production only when a device runs in a different timezone or a DST boundary is crossed — exactly the deployment risk for something like `mqtt_monitor` running across multiple sites.

**Resolved example 3 — applying this to `DeviceReading` from Day 12, correctly**

```cpp
// Corrected usage of Day 12's DeviceReading class:

DeviceReading reading;
reading.setDeviceId("sensor-07");
reading.setTemperature(23.5);

// WRONG (the trap):
// reading.setTimestamp(QDateTime::currentDateTime());   // local time -- ambiguous across deployments

// RIGHT:
reading.setTimestamp(QDateTime::currentDateTimeUtc());   // always UTC, always unambiguous, always comparable

qDebug() << "Reading timestamp (UTC, correct):" << reading.timestamp().toString(Qt::ISODateWithMs);
```

**Resolved output:**

```
Reading timestamp (UTC, correct): "2026-07-22T14:45:12.345Z"
```

Given `DeviceReading` will eventually feed both SQLite storage and JSON-over-MQTT publishing, this single discipline — always call `currentDateTimeUtc()`, never `currentDateTime()`, when timestamping a reading for storage or transmission — is the one-line fix that prevents an entire category of "readings from different sensors don't compare correctly" bugs from ever existing in the first place.

**Key takeaways:**

- `QDateTime::currentDateTime()` returns local time; `QDateTime::currentDateTimeUtc()` returns UTC. For anything stored or transmitted (device readings, log timestamps, database rows), always use the UTC variant — convert to local only at final display time, if at all.
- DST transitions make local time genuinely ambiguous (a repeated hour) or invalid (a skipped hour) once a year each way — this is a structural reason UTC is the only safe storage representation, not just a style preference.
- Constructing a `QDateTime` without an explicit `QTimeZone` defaults to `Qt::LocalTime` — a common, silent bug source; always pass `QTimeZone::UTC` explicitly when you mean UTC, rather than relying on later `.toUTC()` calls to "fix" a value that was already tagged with the wrong zone at construction.
- `Qt::ISODateWithMs` serialization only correctly appends timezone info (`Z` or an offset) if the `QDateTime` itself is properly tagged — an untagged/local `QDateTime` serializes and round-trips _consistently_ with itself, but represents the wrong absolute instant if your actual intent was UTC.

Day 14 is this week's mini-project: combining Days 8–13 into a serial-line parser → structured JSON record pipeline — taking raw text lines (Day 11's regex), building a validated `DeviceReading`-style record (Day 12), correctly UTC-timestamped (Day 13), and serializing to JSON (Day 10) ready for either file storage (Day 8) or, eventually, MQTT publishing.