[[Intermediate Concepts]]

**Theory: QIODevice — the abstraction that unifies files, sockets, and processes**

`QFile` is not a standalone file-handling class — it's one of several concrete subclasses of `QIODevice`, an abstract base class defining a common read/write interface: `open()`, `close()`, `read()`, `write()`, `bytesAvailable()`, `atEnd()`, and — critically — the `readyRead()` **signal** (Day 3) for anything that supports asynchronous notification. `QTcpSocket` (Week 4), `QProcess` (Week 3), and `QFile` **all** derive from `QIODevice` and share this interface. This is deliberate: code you write against the `QIODevice` interface today (a generic line-reading loop, say) will work unchanged against a TCP socket or a subprocess's stdout later, which is exactly the pattern `mqtt_monitor`'s serial-ingestion code relies on — the same parsing logic applies whether data comes from a real serial device, a test file, or (eventually) a network stream.

**Two layers on top of `QIODevice`, resolved:**

- **`QTextStream`** — for **text**: handles encoding (UTF-8 by default in Qt6), line-based reading (`readLine()`), and formatted output (`<<` operators), similar in spirit to `std::iostream` but working with any `QIODevice`, not just files.
- **`QDataStream`** — for **binary**: serializes C++/Qt types (`int`, `double`, `QString`, `QList<T>`, etc.) into a well-defined, versioned binary format, and reads them back with full type fidelity — useful when you need compact, structured binary storage rather than human-readable text (e.g. caching parsed sensor readings to disk faster than JSON parsing would allow).

**Resolved example 1 — QFile + QTextStream: writing and reading text, explicit encoding**

```cpp
#include <QCoreApplication>
#include <QFile>
#include <QTextStream>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    const QString path = "readings.txt";

    // --- Writing ---
    {
        QFile file(path);
        if (!file.open(QIODevice::WriteOnly | QIODevice::Text)) {
            qWarning() << "open for write failed:" << file.errorString();
            return 1;
        }
        QTextStream out(&file);
        out.setEncoding(QStringConverter::Utf8);   // explicit; this is Qt6's default anyway, but stating intent matters
        out << "TEMP:23.5\n";
        out << "TEMP:24.1\n";
        out << "HUMIDITY:61\n";
        // file closes automatically when it goes out of scope (QFile's destructor calls close())
    }

    // --- Reading, line by line ---
    {
        QFile file(path);
        if (!file.open(QIODevice::ReadOnly | QIODevice::Text)) {
            qWarning() << "open for read failed:" << file.errorString();
            return 1;
        }
        QTextStream in(&file);
        int lineNum = 0;
        while (!in.atEnd()) {
            QString line = in.readLine();
            ++lineNum;
            qDebug() << "line" << lineNum << ":" << line;
        }
    }

    return 0;
}
```

**Resolved output:**

```
line 1 : "TEMP:23.5"
line 2 : "TEMP:24.1"
line 3 : "HUMIDITY:61"
```

Note the resolved detail on `QIODevice::Text` mode: on write, it normalizes line endings to the platform convention (`\n` on Linux, `\r\n` on Windows) — since your embedded targets (Day 5's context: Raspberry Pi, BeagleBone) are Linux, this is a no-op for you in practice, but it's why the flag exists and why cross-platform Qt code always includes it rather than assuming.

**Resolved example 2 — error handling done properly: `QFile::error()` / `errorString()`, and the permission-denied case**

```cpp
#include <QCoreApplication>
#include <QFile>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // Deliberately attempt to write somewhere we (likely) don't have permission
    QFile blocked("/root/should_fail.txt");
    bool opened = blocked.open(QIODevice::WriteOnly);

    if (!opened) {
        qDebug() << "open() returned false, as expected";
        qDebug() << "error code:" << blocked.error();          // QFileDevice::OpenError enum value
        qDebug() << "error string:" << blocked.errorString();   // human-readable OS message
    }

    // Resolved pattern for real code: NEVER assume open() succeeded.
    // Always branch on the bool return and log errorString() -- this is
    // the Qt Core equivalent of checking errno after a failed POSIX open().

    return opened ? 0 : 1;
}
```

**Resolved output (exact message is OS-dependent, but the pattern holds):**

```
open() returned false, as expected
error code: 5
error string: "Permission denied"
```

This resolves a habit worth locking in now: every `QFile::open()` (and, later, every `QIODevice::open()` on a socket or process) returns `bool`, and the failure path always has a real, inspectable reason via `errorString()` — there's no excuse for silently ignoring a failed open in service code that's supposed to run unattended.

**Resolved example 3 — QDataStream: binary serialization of a structured reading, round-tripped**

```cpp
#include <QCoreApplication>
#include <QFile>
#include <QDataStream>
#include <QDebug>
#include <QList>

struct Reading {
    QString deviceId;
    double value;
    qint64 timestampMs;
};

// QDataStream needs explicit operator<< / operator>> overloads to know how
// to serialize a custom struct -- this is the resolved boilerplate for that.
QDataStream &operator<<(QDataStream &out, const Reading &r)
{
    out << r.deviceId << r.value << r.timestampMs;
    return out;
}
QDataStream &operator>>(QDataStream &in, Reading &r)
{
    in >> r.deviceId >> r.value >> r.timestampMs;
    return in;
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QList<Reading> readings = {
        {"sensor-01", 23.5, 1753180800000},
        {"sensor-02", 19.8, 1753180801000},
        {"sensor-01", 23.7, 1753180802000},
    };

    const QString path = "readings.bin";

    // --- Write binary ---
    {
        QFile file(path);
        file.open(QIODevice::WriteOnly);
        QDataStream out(&file);
        out.setVersion(QDataStream::Qt_6_5);   // ALWAYS pin a version explicitly: ensures the binary
                                                 // format stays stable across Qt point releases, so a
                                                 // file written today is still readable after an upgrade.
        out << readings.size();
        for (const Reading &r : readings)
            out << r;
    }

    // --- Read binary back ---
    {
        QFile file(path);
        file.open(QIODevice::ReadOnly);
        QDataStream in(&file);
        in.setVersion(QDataStream::Qt_6_5);

        qsizetype count;
        in >> count;
        qDebug() << "reading" << count << "records back";

        for (qsizetype i = 0; i < count; ++i) {
            Reading r;
            in >> r;
            qDebug() << r.deviceId << r.value << r.timestampMs;
        }
    }

    return 0;
}
```

**Resolved output:**

```
reading 3 records back
"sensor-01" 23.5 1753180800000
"sensor-02" 19.8 1753180801000
"sensor-01" 23.7 1753180802000
```

Resolved rationale for `setVersion()`: `QDataStream`'s binary format has evolved across Qt versions (field widths, encodings), and if you don't pin a version explicitly, Qt uses whatever the current build's default is — meaning a file written by Qt 6.5 might not read back correctly under a future Qt 6.9 if the default silently changed. Pinning `Qt_6_5` explicitly means the format is frozen and portable regardless of what Qt version reads it later, as long as you keep using the same pinned version consistently across writer and reader.

###### Explanation

This program **writes a list of sensor readings to a binary file** using `QDataStream`, then **reads them back** and prints them.

## High-level flow

It does two phases:

1. **Write** 3 `Reading` objects into `readings.bin`
2. **Read** them back from `readings.bin`

---

## 1. The `Reading` struct

```cpp
struct Reading {
    QString deviceId;
    double value;
    qint64 timestampMs;
};
```

Each reading has:
- `deviceId` — sensor name like `"sensor-01"`
- `value` — measured value like `23.5`
- `timestampMs` — timestamp in milliseconds

So one record looks like:

```cpp
{"sensor-01", 23.5, 1753180800000}
```

---

## 2. Why operator overloads are needed

```cpp
QDataStream &operator<<(QDataStream &out, const Reading &r)
{
    out << r.deviceId << r.value << r.timestampMs;
    return out;
}
QDataStream &operator>>(QDataStream &in, Reading &r)
{
    in >> r.deviceId >> r.value >> r.timestampMs;
    return in;
}
```

`QDataStream` already knows how to read/write built-in Qt types like:
- `QString`
- `double`
- `qint64`

But it does **not** automatically know how to handle your custom `Reading` struct.

So you teach it:

- `operator<<` says how to **write** a `Reading`
- `operator>>` says how to **read** a `Reading`

### Important rule
The read order must match the write order exactly.

You write:

```cpp
deviceId, value, timestampMs
```

so you must read:

```cpp
deviceId, value, timestampMs
```

If the order changed, the data would be decoded incorrectly.

---

## 3. Creating sample data

```cpp
QList<Reading> readings = {
    {"sensor-01", 23.5, 1753180800000},
    {"sensor-02", 19.8, 1753180801000},
    {"sensor-01", 23.7, 1753180802000},
};
```

This creates a `QList` with 3 records.

---

## 4. Output file path

```cpp
const QString path = "readings.bin";
```

The binary data will be stored in a file named `readings.bin` in the current working directory.

---

## 5. Writing the binary file

```cpp
{
    QFile file(path);
    file.open(QIODevice::WriteOnly);
    QDataStream out(&file);
    out.setVersion(QDataStream::Qt_6_5);
    out << readings.size();
    for (const Reading &r : readings)
        out << r;
}
```

### Step by step

#### a) Open file for writing

```cpp
QFile file(path);
file.open(QIODevice::WriteOnly);
```

This creates/opens `readings.bin` for binary output.

#### b) Attach a `QDataStream`

```cpp
QDataStream out(&file);
```

Now `out` writes binary data into the file.

#### c) Pin the stream format version

```cpp
out.setVersion(QDataStream::Qt_6_5);
```

This tells Qt to use the Qt 6.5 serialization format.

Why this matters:
- binary serialization formats can vary by Qt version
- pinning the version makes reading/writing predictable

#### d) Write the number of records

```cpp
out << readings.size();
```

This writes `3` into the file first.

That way, when reading later, the program knows how many `Reading` objects follow.

#### e) Write each record

```cpp
for (const Reading &r : readings)
    out << r;
```

Each `Reading` is serialized using your custom `operator<<`.

So the file contains, in order:

1. count = `3`
2. first `Reading`
3. second `Reading`
4. third `Reading`

---

## 6. Reading the binary file back

```cpp
{
    QFile file(path);
    file.open(QIODevice::ReadOnly);
    QDataStream in(&file);
    in.setVersion(QDataStream::Qt_6_5);

    qsizetype count;
    in >> count;
    qDebug() << "reading" << count << "records back";

    for (qsizetype i = 0; i < count; ++i) {
        Reading r;
        in >> r;
        qDebug() << r.deviceId << r.value << r.timestampMs;
    }
}
```

### Step by step

#### a) Open file for reading

```cpp
QFile file(path);
file.open(QIODevice::ReadOnly);
```

Now the same file is opened for input.

#### b) Attach a `QDataStream`

```cpp
QDataStream in(&file);
```

Now `in` reads binary data from the file.

#### c) Use the same stream version

```cpp
in.setVersion(QDataStream::Qt_6_5);
```

This must match the write side.

#### d) Read the record count

```cpp
qsizetype count;
in >> count;
```

This reads the first value from the file — the number `3`.

Now the program knows it should read 3 `Reading` objects.

#### e) Print the count

```cpp
qDebug() << "reading" << count << "records back";
```

Output will look roughly like:

```cpp
reading 3 records back
```

#### f) Read each `Reading`

```cpp
for (qsizetype i = 0; i < count; ++i) {
    Reading r;
    in >> r;
    qDebug() << r.deviceId << r.value << r.timestampMs;
}
```

Each iteration:
- creates an empty `Reading`
- fills it using `operator>>`
- prints its fields

---

## 7. Expected output

Something like:

```cpp
reading 3 records back
"sensor-01" 23.5 1753180800000
"sensor-02" 19.8 1753180801000
"sensor-01" 23.7 1753180802000
```

---

## 8. Why this works

The write side and read side agree on:

- **same order**
- **same types**
- **same stream version**

### Written order:
```cpp
[count][Reading][Reading][Reading]
```

### Each `Reading`:
```cpp
[deviceId][value][timestampMs]
```

### Read order:
```cpp
count
then Reading
then Reading
then Reading
```

Because the reading logic matches the writing logic exactly, the data is reconstructed correctly.

---

## 9. About binary “separation”

The values are **not separated by text delimiters** like commas or newlines.

Instead:
- fixed-size types (`double`, `qint64`) occupy known byte lengths
- variable-size types like `QString` are written in a format that includes enough information to know how long they are
- `QDataStream` keeps track of where the next unread bytes begin

So this line:

```cpp
in >> count;
```

reads only the first encoded value and stops there, leaving the stream positioned at the first `Reading`.

---

## 10. Small weaknesses in this example

The code is fine for teaching, but in real code you should improve a few things.

### a) Check `open()` result

Right now:

```cpp
file.open(QIODevice::WriteOnly);
```

and

```cpp
file.open(QIODevice::ReadOnly);
```

ignore failure.

Safer:

```cpp
if (!file.open(QIODevice::WriteOnly)) {
    qDebug() << "Failed to open file for writing";
    return 1;
}
```

### b) Check stream status

You may also want to verify the read succeeded.

### c) Prefer fixed-width count type for file formats

Using:

```cpp
out << readings.size();
qsizetype count;
in >> count;
```

works, but `qsizetype` may vary by platform.

For portable file formats, better use a fixed-width integer:

```cpp
out << qint32(readings.size());

qint32 count;
in >> count;
```

---

## 11. In one sentence

This program stores a list of custom `Reading` structs in a binary file by first writing the number of records, then writing each record field-by-field with `QDataStream`, and later reconstructs them by reading the count first and then reading that many records in the same order.

If you want, I can also show:
1. the **exact conceptual byte layout** of one `Reading`, or  
2. a **text-file version vs binary-file version** of the same program.

*in the code above how are the binary values separated? why in >> count only pours the number of objects?*

They are **not separated by commas or special delimiters**. `QDataStream` works with a **structured binary format**, where each `<<` writes bytes in a known layout, and each `>>` reads them back in the same order.

## The key idea
These lines write data in sequence:

```cpp
out << readings.size();
for (const Reading &r : readings)
    out << r;
```

So the file contains:

1. the count
2. first `Reading`
3. second `Reading`
4. third `Reading`

And each `Reading` itself contains:

1. `deviceId`
2. `value`
3. `timestampMs`

because your overload says:

```cpp
out << r.deviceId << r.value << r.timestampMs;
```

## Why `in >> count` reads only the count
Because the stream has a **current read position**.

When this runs:

```cpp
in >> count;
```

`QDataStream` reads exactly the number of bytes needed for the type of `count` and advances the stream position by that amount.

It does **not** guess or scan. It simply says:
- “I am reading a `qsizetype` now”
- consume the bytes for one `qsizetype`
- leave the rest for later

Then the next reads start exactly where that one ended.

## Think of it like a cursor
Imagine the binary stream as a byte array:

```text
[count bytes][reading1 bytes][reading2 bytes][reading3 bytes]
 ^
 cursor starts here
```

After:

```cpp
in >> count;
```

the cursor moves:

```text
[count bytes][reading1 bytes][reading2 bytes][reading3 bytes]
            ^
```

So now the next `in >> r;` reads the first record.

## But how does it know where one value ends?
Because each type has a defined binary representation in `QDataStream`.

For example:
- `double` = fixed number of bytes
- `qint64` = fixed number of bytes
- `QString` = written in a format that includes length information, so the reader knows how many bytes belong to the string

So for a `Reading`:

```cpp
out << r.deviceId << r.value << r.timestampMs;
```

the stream writes:
- string length + string data
- 8 bytes for `double`
- 8 bytes for `qint64`

When reading:

```cpp
in >> r.deviceId >> r.value >> r.timestampMs;
```

it consumes those exact parts in the same order.

## Why order matters
Binary streams do not carry “field names”. They only carry bytes.

So this:

```cpp
out << r.deviceId << r.value << r.timestampMs;
```

must be matched by:

```cpp
in >> r.deviceId >> r.value >> r.timestampMs;
```

If you changed the read order, decoding would break.

## For `count` specifically
This line:

```cpp
out << readings.size();
```

writes one integer value at the beginning.

Then this line:

```cpp
in >> count;
```

reads that first integer value back.

It reads only that because:
- the type tells `QDataStream` how many bytes to consume
- the stream position starts at the beginning
- the rest of the bytes remain unread until the next `>>`

## Analogy
It’s like writing to a tape:

```text
[3][sensor-01,23.5,1753180800000][sensor-02,...][sensor-01,...]
```

The first read says: “read one integer”
So you get `3`.

Then the loop says: “read one `Reading`”
So it consumes exactly one serialized `Reading` each time.

## Important subtle point
This only works because the writer and reader agree on:
- type
- order
- stream version

That is why this matters:

```cpp
out.setVersion(QDataStream::Qt_6_5);
in.setVersion(QDataStream::Qt_6_5);
```

If those differ, the same bytes might be interpreted differently.

## Short answer
`in >> count` reads only the number of objects because the stream starts with exactly that value, and `QDataStream` knows exactly how many bytes belong to that type. Binary values are not separated by visible delimiters; they are parsed by type and order.



**Key takeaways:**

- `QFile`, `QTcpSocket`, and `QProcess` all derive from `QIODevice` — code written against the `QIODevice`/`QTextStream` interface generalizes directly to sockets and processes later in the course.
- `QTextStream` handles text encoding and line-based I/O; `QDataStream` handles versioned, typed binary serialization — pick text for human-readable/interoperable formats (config, JSON, logs), binary for compact structured storage where you control both writer and reader.
- Every `open()` call returns `bool` and must be checked — `errorString()` gives you the real, actionable reason for failure, and ignoring it in a long-running service means silent, undiagnosable failures later.
- Custom types need explicit `operator<<`/`operator>>` overloads to work with `QDataStream`; always pin `setVersion()` explicitly rather than relying on the ambient default.

Day 9 continues directly: `QSettings`, which uses this same `QIODevice`-adjacent world for config file parsing (INI format) but adds automatic type conversion via `QVariant` (Day 6) and a hierarchical key/group structure — directly relevant to configuring a real `mqtt_monitor` deployment (broker address, serial port, thresholds) without hand-rolling a parser.