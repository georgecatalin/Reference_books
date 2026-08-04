[[Intermediate Concepts]]

**Theory: Qt's JSON types and how they relate to QVariant**

Qt Core's JSON support centers on three value types: `QJsonValue` (a tagged union — null, bool, double, string, array, or object), `QJsonObject` (an ordered string-keyed map of `QJsonValue`s — note: **insertion order preserved**, unlike `QMap` from Day 6), and `QJsonArray` (an ordered list of `QJsonValue`s). `QJsonDocument` is the top-level wrapper used only for serializing to/from raw bytes (`toJson()`/`fromJson()`).

Critically: **JSON's number type has no int/double distinction** — every JSON number is stored internally as a `double` in `QJsonValue`, even if it looks like an integer in the source text. This matters for anything like a device ID or count that must stay an exact integer: values beyond `2^53` lose precision, and code that does `jsonValue.toInt()` on a value that was actually written as `2.0` gets `2` back fine, but you should know _why_ that conversion is happening rather than assume JSON preserved "int-ness" natively — it didn't; `toInt()` is just truncating a double.

The bridge to `QVariant` (Day 6) is direct: `QJsonValue::toVariant()` and `QJsonValue::fromVariant()` convert between the two systems, which is how `QSettings` (Day 9) values or `QObject` properties (Day 1) end up serialized to JSON without manual field-by-field conversion.

**Resolved example 1 — building a device reading as JSON, serializing, and the int/double gotcha made visible**

```cpp
#include <QCoreApplication>
#include <QJsonObject>
#include <QJsonDocument>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QJsonObject reading;
    reading["device_id"] = "sensor-07";
    reading["temperature"] = 23.5;
    reading["humidity"] = 61;            // written as an int literal...
    reading["online"] = true;
    reading["reading_count"] = 9007199254740993LL;  // deliberately beyond 2^53 -- see resolved output below

    QJsonDocument doc(reading);

    // Compact vs indented -- resolved: use Compact for network payloads (MQTT publish),
    // Indented only for human-readable debug output or config files.
    QByteArray compact = doc.toJson(QJsonDocument::Compact);
    QByteArray indented = doc.toJson(QJsonDocument::Indented);

    qDebug() << "Compact (what you'd publish to MQTT):";
    qDebug().noquote() << compact;

    qDebug() << "\nIndented (debug-friendly):";
    qDebug().noquote() << indented;

    // Resolved: humidity comes back as a double internally, even though we wrote an int literal
    qDebug() << "\nhumidity value type:" << reading["humidity"].type();   // QJsonValue::Double, NOT Int
    qDebug() << "humidity as double:" << reading["humidity"].toDouble();
    qDebug() << "humidity as int:" << reading["humidity"].toInt();

    // Resolved: precision loss beyond 2^53
    qDebug() << "\nreading_count as double:" << reading["reading_count"].toDouble();
    qDebug() << "reading_count as int (may have lost precision):" << reading["reading_count"].toVariant().toLongLong();

    return 0;
}
```

**Resolved output:**

```
Compact (what you'd publish to MQTT):
{"device_id":"sensor-07","humidity":61,"online":true,"reading_count":9007199254740992,"temperature":23.5}

Indented (debug-friendly):
{
    "device_id": "sensor-07",
    "humidity": 61,
    "online": true,
    "reading_count": 9007199254740992,
    "temperature": 23.5
}

humidity value type: QJsonValue::Double
humidity as double: 61
humidity as int: 61

reading_count as double: 9.00719925474099e+15
reading_count as int (may have lost precision): 9007199254740992
```

Note the resolved, deliberately-triggered precision loss: `9007199254740993` (2^53 + 1) was stored, and came back as `9007199254740992` (2^53) — off by one, silently, because `QJsonValue` stores every number as a IEEE 754 double, which can't exactly represent every integer beyond 2^53. For device reading counts, this is unlikely to bite in practice (you'd need ~9 quadrillion readings), but for something like a **timestamp in nanoseconds** or a **hash value**, this precision boundary is a real, resolved trap: if exact integer fidelity beyond 2^53 matters, don't rely on JSON's native number type — encode as a string instead and parse explicitly.

##### About QByteArray (all you need to know)

**TL;DR:** `QByteArray` is Qt's dynamic byte array class. It holds raw 8-bit bytes (0x00 to 0xFF) and binary data, making it the standard container in Qt for file I/O, network sockets, serial ports, and raw strings (like ASCII or UTF-8).

## Key Characteristics

- **Binary-Safe:** Unlike standard C-strings (`char*`), `QByteArray` can contain null bytes (`\0`) anywhere inside the buffer without truncating the data.
    
- **Implicit Sharing (Copy-on-Write):** Copying a `QByteArray` is extremely fast (constant time $O(1)$) because Qt only duplicates the pointer. The actual memory is only copied if one of the instances is modified.
    
- **Dual Role:** It acts both as a **raw byte container** (for binary payloads) and as a **C-style string wrapper** with methods like `.toInt()`, `.toHex()`, `.startsWith()`, etc.
    

## When to Use `QByteArray` vs. `QString`

|**Feature**|**QByteArray**|**QString**|
|---|---|---|
|**Data Type**|Raw 8-bit bytes (`char` / `uint8_t`)|16-bit Unicode characters (`QChar` / UTF-16)|
|**Primary Use**|Network packets, files, hardware protocols, JSON/XML raw buffers|UI text, localized user strings, internal application text|
|**Null Bytes (`\0`)**|Allowed anywhere|Represents end of string in C-API contexts|

## Common Use Cases & Code Examples

### 1. Network & Hardware Protocols (MQTT, Sockets, Serial)

C++

```
// Reading raw bytes from a network socket or serial port
QByteArray requestData = socket->readAll();

if (requestData.startsWith("\x02")) { // Checking for STX (Start of Text) byte
    qDebug() << "Received valid frame of size:" << requestData.size();
}
```

### 2. Converting Hex and Base64

C++

```
QByteArray binaryData = QByteArray::fromHex("47656f726765"); 
qDebug().noquote() << binaryData; // Output: George

QByteArray base64Encoded = binaryData.toBase64();
qDebug().noquote() << base64Encoded; // Output: R2Vvcmdl
```

### 3. Converting Between `QByteArray` and `QString`

C++

```
QString text = "Hello World";

// QString to QByteArray (UTF-8 encoded)
QByteArray utf8Bytes = text.toUtf8();

// QByteArray back to QString
QString restoredText = QString::fromUtf8(utf8Bytes);
```

### 4. Direct Buffer Access (C-API Interop)

C++

```
QByteArray buffer;
buffer.resize(1024);

// Pass raw pointer to a C library function
int bytesRead = legacy_c_api_read(buffer.data(), buffer.size());
buffer.resize(bytesRead); // Truncate to actual read size
```





**Resolved example 2 — parsing incoming JSON (simulating an MQTT payload), with proper error handling**

```cpp
#include <QCoreApplication>
#include <QJsonDocument>
#include <QJsonObject>
#include <QJsonParseError>
#include <QDebug>

void handleIncomingPayload(const QByteArray &payload)
{
    QJsonParseError parseError;
    QJsonDocument doc = QJsonDocument::fromJson(payload, &parseError);

    if (parseError.error != QJsonParseError::NoError) {
        qWarning() << "JSON parse failed:" << parseError.errorString()
                   << "at byte offset" << parseError.offset;
        return;   // resolved: never assume a network payload is well-formed -- always check this first
    }

    if (!doc.isObject()) {
        qWarning() << "Expected a JSON object at top level, got something else";
        return;
    }

    QJsonObject obj = doc.object();

    // Resolved pattern: check key existence AND type before extracting,
    // rather than assuming the payload matches your expected schema.
    if (!obj.contains("device_id") || !obj["device_id"].isString()) {
        qWarning() << "Missing or invalid 'device_id' field";
        return;
    }
    if (!obj.contains("temperature") || !obj["temperature"].isDouble()) {
        qWarning() << "Missing or invalid 'temperature' field";
        return;
    }

    QString deviceId = obj["device_id"].toString();
    double temperature = obj["temperature"].toDouble();

    qDebug() << "Parsed OK -- device:" << deviceId << "temp:" << temperature;
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // Case 1: well-formed payload
    handleIncomingPayload(R"({"device_id":"sensor-07","temperature":23.5})");

    // Case 2: malformed JSON (missing closing brace)
    handleIncomingPayload(R"({"device_id":"sensor-07","temperature":23.5)");

    // Case 3: valid JSON, but wrong schema (temperature is a string, not a number)
    handleIncomingPayload(R"({"device_id":"sensor-07","temperature":"warm"})");

    // Case 4: valid JSON, but missing a required field entirely
    handleIncomingPayload(R"({"device_id":"sensor-07"})");

    return 0;
}
```

**Resolved output:**

```
Parsed OK -- device: "sensor-07" temp: 23.5
JSON parse failed: "unterminated object" at byte offset 43
Missing or invalid 'temperature' field
Missing or invalid 'temperature' field
```

This is the resolved discipline that matters most for real MQTT ingestion code: **three distinct failure modes** (malformed JSON syntax, wrong field type, missing field) each need their own check — a naive implementation that just does `obj["temperature"].toDouble()` without validation would silently return `0.0` for both the malformed-schema case and a genuinely-zero temperature reading, making a real sensor fault indistinguishable from a parsing bug. This is precisely the kind of silent-failure trap Day 6 flagged with `QVariant::toDouble(&ok)` — `QJsonValue` has the same trap, and the resolved fix is the same: check before you trust.

**Resolved example 3 — QJsonArray, and QVariant as the bridge for round-tripping**

```cpp
#include <QCoreApplication>
#include <QJsonArray>
#include <QJsonObject>
#include <QJsonDocument>
#include <QVariantMap>
#include <QVariantList>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // A batch of readings, as would be published together to reduce MQTT message overhead
    QJsonArray batch;
    batch.append(QJsonObject{{"device_id", "sensor-01"}, {"temperature", 23.5}});
    batch.append(QJsonObject{{"device_id", "sensor-02"}, {"temperature", 19.8}});
    batch.append(QJsonObject{{"device_id", "sensor-03"}, {"temperature", 25.1}});

    QJsonDocument doc(batch);
    qDebug().noquote() << doc.toJson(QJsonDocument::Compact);

    // Resolved: iterating a QJsonArray and extracting each as an object
    double total = 0;
    for (const QJsonValue &val : batch) {
        QJsonObject obj = val.toObject();
        total += obj["temperature"].toDouble();
    }
    qDebug() << "average temperature:" << (total / batch.size());

    // Resolved: QVariant bridge -- useful when passing JSON-derived data into
    // QSettings (Day 9) or QObject::setProperty() (Day 1), which don't speak QJsonValue directly
    QVariant asVariant = batch.toVariantList();
    QVariantList list = asVariant.toList();
    qDebug() << "first entry via QVariant bridge:" << list.first().toMap()["device_id"].toString();

    return 0;
}
```

**Resolved output:**

```
[{"device_id":"sensor-01","temperature":23.5},{"device_id":"sensor-02","temperature":19.8},{"device_id":"sensor-03","temperature":25.1}}]
average temperature: 22.8
first entry via QVariant bridge: "sensor-01"
```

**Key takeaways:**

- `QJsonObject` preserves insertion order (unlike `QMap`); all JSON numbers are stored as `double` internally regardless of how they were written — exact integer fidelity is only guaranteed up to 2^53, a real concern for IDs, counts, or timestamps that could exceed it.
- Always check `QJsonParseError` after `fromJson()` — malformed input from a network source is a certainty eventually, not an edge case.
- Validate both key **existence** and value **type** before extracting — a missing or wrong-typed field silently returns a default (`0.0`, `""`, `false`) rather than erroring, which is indistinguishable from a legitimately zero/empty/false value unless you check first.
- `QJsonValue::toVariant()` / `QJsonArray::toVariantList()` bridge cleanly into the `QVariant` world from Day 6, letting JSON-derived data flow into `QSettings`, `QObject` properties, or anywhere else `QVariant` is the common currency.

Day 11 covers `QRegularExpression` — parsing raw serial line formats (like the `"TEMP:23.5"` strings used throughout this course) into structured data properly, with named capture groups, rather than manual string splitting.