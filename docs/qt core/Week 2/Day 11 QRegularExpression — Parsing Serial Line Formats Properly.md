[[Intermediate Concepts]]

**Theory: why regex over manual string splitting, and what QRegularExpression gives you over raw PCRE**

Every prior example used `"TEMP:23.5"` as a stand-in for real serial data, extracted via naive string operations you'd have to write by hand (`split(':')`, index into the result, hope nothing's malformed). Real serial protocols are rarely that clean — you'll see variable whitespace, optional fields, multiple message formats interleaved, and outright garbage bytes from noise on the line. `QRegularExpression` (Qt6's regex engine, built on PCRE2) handles this robustly, and critically supports **named capture groups**, which turn "capture group 3" into `.captured("value")` — self-documenting and immune to breaking silently when you insert a new group and shift all the indices.

**Resolved example 1 — parsing a well-defined line format with named captures**

```cpp
#include <QCoreApplication>
#include <QRegularExpression>
#include <QRegularExpressionMatch>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // Format: "SENSOR:<id>:TEMP:<value>:TS:<epoch_ms>"
    // e.g.    "SENSOR:07:TEMP:23.5:TS:1753180800123"
    QRegularExpression re(
        R"(^SENSOR:(?<id>\d+):TEMP:(?<temp>-?\d+\.?\d*):TS:(?<ts>\d+)$)"
    );

    QStringList lines = {
        "SENSOR:07:TEMP:23.5:TS:1753180800123",
        "SENSOR:12:TEMP:-4.2:TS:1753180801456",
        "SENSOR:03:TEMP:19:TS:1753180802789",         // integer temp, no decimal point -- still valid per the regex
        "GARBAGE DATA \x00\x01 not a real line",       // simulated line noise
        "SENSOR:99:TEMP:abc:TS:1753180803000",         // malformed: non-numeric temp
    };

    for (const QString &line : lines) {
        QRegularExpressionMatch match = re.match(line);
        if (match.hasMatch()) {
            QString id = match.captured("id");
            double temp = match.captured("temp").toDouble();
            qint64 ts = match.captured("ts").toLongLong();
            qDebug() << "OK  -- sensor" << id << "temp" << temp << "ts" << ts;
        } else {
            qDebug() << "SKIP -- unparseable line:" << line;
        }
    }

    return 0;
}
```

**Resolved output:**

```
OK  -- sensor "07" temp 23.5 ts 1753180800123
OK  -- sensor "12" temp -4.2 ts 1753180801456
OK  -- sensor "03" temp 19 ts 1753180802789
SKIP -- unparseable line: "GARBAGE DATA \x00\x01 not a real line"
SKIP -- unparseable line: "SENSOR:99:TEMP:abc:TS:1753180803000"
```

Resolved detail worth internalizing: the anchors `^` and `$` matter — without them, the regex would happily match a _substring_ buried inside garbage (e.g. if the noise line accidentally contained a valid-looking fragment), silently accepting corrupted input as if it were a clean line. Anchoring the whole pattern to the full line is the correct default for line-oriented protocols unless you specifically intend partial matching.

**Resolved example 2 — QRegularExpression::globalMatch for extracting multiple readings from one blob, plus the compiled-pattern reuse pattern**

```cpp
#include <QCoreApplication>
#include <QRegularExpression>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // Simulating a burst of concatenated readings arriving in one serial read() call --
    // a real scenario: the OS buffer coalesces several lines before your read() returns.
    QString blob =
        "TEMP:23.5;HUMIDITY:61;TEMP:24.1;HUMIDITY:58;TEMP:22.9;HUMIDITY:63;";

    // Resolved best practice: construct QRegularExpression ONCE, reuse the same
    // instance for every match call. Internally it caches the compiled pattern
    // (JIT-compiled by PCRE2 on first use) -- recompiling per-call in a hot loop
    // (e.g. once per incoming serial line, thousands of times a second) is wasted work.
    static const QRegularExpression re(R"((?<type>TEMP|HUMIDITY):(?<value>-?\d+\.?\d*))");

    QRegularExpressionMatchIterator it = re.globalMatch(blob);
    int tempCount = 0, humidityCount = 0;
    double tempSum = 0, humiditySum = 0;

    while (it.hasNext()) {
        QRegularExpressionMatch m = it.next();
        QString type = m.captured("type");
        double value = m.captured("value").toDouble();

        if (type == "TEMP") {
            tempSum += value;
            ++tempCount;
        } else {
            humiditySum += value;
            ++humidityCount;
        }
    }

    qDebug() << "avg temp:" << (tempSum / tempCount) << "over" << tempCount << "readings";
    qDebug() << "avg humidity:" << (humiditySum / humidityCount) << "over" << humidityCount << "readings";

    return 0;
}
```

**Resolved output:**

```
avg temp: 23.5 over 3 readings
avg humidity: 60.6667 over 3 readings
```

The resolved architectural point: `static const QRegularExpression re(...)` at function scope means the pattern is compiled exactly once across the entire program's lifetime, not once per call — a real, measurable difference in a serial-ingestion hot path processing thousands of lines. This is the direct regex analog of Day 6's "don't do unnecessary work in a hot path" discipline.

**Resolved example 3 — validating and rejecting malformed input strictly, with partial-match diagnostics for debugging**

```cpp
#include <QCoreApplication>
#include <QRegularExpression>
#include <QDebug>

bool validateDeviceId(const QString &id, QString *errorOut = nullptr)
{
    // Real-world constraint: device IDs must be exactly "sensor-" followed by
    // 2 digits -- e.g. "sensor-07", NOT "sensor-7" or "sensor-123" or "Sensor-07".
    static const QRegularExpression re(R"(^sensor-\d{2}$)");

    QRegularExpressionMatch m = re.match(id);
    if (m.hasMatch()) {
        return true;
    }

    if (errorOut) {
        // Resolved diagnostic: distinguish WHY it failed, rather than a flat "invalid" --
        // genuinely useful when this validates config-file input (Day 9) a human typed by hand.
        if (id.length() != 9) {
            *errorOut = QString("expected 9 characters (sensor-NN), got %1").arg(id.length());
        } else if (!id.startsWith("sensor-")) {
            *errorOut = "must start with 'sensor-' (lowercase)";
        } else {
            *errorOut = "trailing part after 'sensor-' must be exactly 2 digits";
        }
    }
    return false;
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QStringList testIds = {"sensor-07", "sensor-7", "Sensor-07", "sensor-123", "sensor-ab"};

    for (const QString &id : testIds) {
        QString error;
        bool valid = validateDeviceId(id, &error);
        if (valid)
            qDebug() << id << "-> VALID";
        else
            qDebug() << id << "-> INVALID:" << error;
    }

    return 0;
}
```

**Resolved output:**

```
"sensor-07" -> VALID
"sensor-7" -> INVALID: expected 9 characters (sensor-NN), got 8
"Sensor-07" -> INVALID: must start with 'sensor-' (lowercase)
"sensor-123" -> INVALID: expected 9 characters (sensor-NN), got 10
"sensor-ab" -> INVALID: trailing part after 'sensor-' must be exactly 2 digits
```

This resolves a practical UX point for anything validating human-entered config (Day 9's `sensor_ids` list, say): a bare "invalid format" error is much less useful in production than telling the operator specifically what's wrong — worth the extra few lines whenever the validated input originates from a human, as opposed to a machine-generated protocol line (Example 1) where "SKIP -- unparseable" is sufficient since no human is meant to read or fix the raw wire format.

**Key takeaways:**

- Always anchor (`^...$`) a regex meant to validate an _entire_ line, not just find a match somewhere inside it — otherwise garbage-with-a-valid-looking-fragment silently passes validation.
- Named capture groups (`(?<name>...)`) plus `.captured("name")` are self-documenting and immune to breaking when you add/reorder groups — always prefer them over positional `.captured(1)`, `.captured(2)`.
- Construct `QRegularExpression` instances once (`static const`) and reuse them — recompiling a pattern per call is wasted work in any hot path, directly analogous to the container-detach discipline from Day 6.
- `globalMatch()` + `QRegularExpressionMatchIterator` is the correct pattern for extracting multiple matches from one blob — realistic for buffered serial reads where several lines arrive in a single `read()` call.
- For human-facing validation (config values), diagnosing _why_ a match failed is worth the extra code; for machine-generated protocol parsing, a flat reject-and-skip is sufficient since there's no human to correct the input anyway.

Day 12 covers `Q_PROPERTY` in full depth (you saw it introduced lightly on Day 1) and building custom `QObject`-derived data model classes — the natural next step now that you can parse (Day 11) and validate structured data, and need a proper typed object to hold it rather than passing loose `QJsonObject`/`QVariantMap` values around.