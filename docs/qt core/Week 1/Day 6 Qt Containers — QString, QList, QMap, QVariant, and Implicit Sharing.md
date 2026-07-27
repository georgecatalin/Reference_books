[[Foundations]]

**Theory: implicit sharing (copy-on-write) — the single biggest behavioral difference from STL**

In standard C++, copying a `std::vector` or `std::string` always deep-copies the underlying buffer — that's just how value semantics work. Qt's containers (`QString`, `QList`, `QMap`, `QByteArray`, and others) use **implicit sharing**: copying one of these objects is a cheap operation that just increments a reference count and shares the same underlying data block. The actual deep copy only happens **lazily, the moment one of the copies is modified** — this is "copy-on-write" (COW).

Why this matters practically:

- Passing a `QString` or `QList` by value into a function is _not_ the performance concern it would be with `std::string`/`std::vector` — no data is copied unless the callee mutates it.
- Two variables can silently share the same underlying buffer until a write happens — this is invisible in normal single-threaded code, but has **real, serious implications in multi-threaded code** (Week 3): implicit sharing's reference counting is thread-safe for the counting itself, but concurrent _reads and writes_ to what look like "separate" copies from different threads can still race if you're not careful, precisely because they might be the same underlying buffer until a write triggers a detach.
- `QVariant` is Qt's type-erased "any value" container — it can hold practically any type (including your own, if registered), and is how Qt Core APIs like `QSettings` and `QObject::setProperty()` (Day 1) pass around values of unknown static type.

**Resolved example 1 — proving copy-on-write actually happens, with `QList`**

```cpp
#include <QCoreApplication>
#include <QList>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QList<int> original = {1, 2, 3, 4, 5};
    QList<int> copy = original;   // NOT a deep copy yet -- just shares the same internal buffer

    qDebug() << "Before mutation:";
    qDebug() << "original data ptr:" << static_cast<const void*>(original.constData());
    qDebug() << "copy data ptr:    " << static_cast<const void*>(copy.constData());
    // Resolved: these two pointers are IDENTICAL -- proof they share one buffer right now.

    copy.append(6);   // this WRITE triggers the actual deep copy (a "detach") right here

    qDebug() << "After mutation:";
    qDebug() << "original data ptr:" << static_cast<const void*>(original.constData());
    qDebug() << "copy data ptr:    " << static_cast<const void*>(copy.constData());
    // Resolved: these pointers now DIFFER -- copy detached into its own buffer on write.

    qDebug() << "original:" << original;   // unaffected: {1,2,3,4,5}
    qDebug() << "copy:    " << copy;       // {1,2,3,4,5,6}

    return 0;
}
```

**Resolved output (addresses illustrative):**

```
Before mutation:
original data ptr: 0x55d3a2b1c8a0
copy data ptr:     0x55d3a2b1c8a0
After mutation:
original data ptr: 0x55d3a2b1c8a0
copy data ptr:     0x55d3a2b1c9f0
original: (1, 2, 3, 4, 5)
copy:     (1, 2, 3, 4, 5, 6)
```

This is the resolved proof: assignment (`copy = original`) is O(1) — just a reference-count bump — and the O(n) deep copy only happens at the exact moment `append()` needs to mutate shared data. Reading (`constData()`, iteration, `at()`) never triggers a detach; only non-const mutation does.

**Resolved example 2 — `QString` behaves identically, plus the const-correctness trap that avoids accidental detaches**

```cpp
#include <QCoreApplication>
#include <QString>
#include <QDebug>

void processReadOnly(const QString &s)   // pass by const-ref: no copy, no detach, ever
{
    qDebug() << "processing (read-only):" << s.length() << "chars";
}

QString processAndModify(QString s)   // pass by value: cheap COW copy in, detach happens on first write below
{
    s[0] = 'X';   // non-const operator[] -- this forces a detach if 's' was still sharing a buffer
    return s;
}

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    QString original = "TEMP:23.5";

    processReadOnly(original);          // cheap: no detach, original untouched
    QString modified = processAndModify(original);   // detach happens INSIDE the function, original still untouched

    qDebug() << "original:" << original;   // "TEMP:23.5" -- unaffected
    qDebug() << "modified:" << modified;   // "Xemp:23.5" -- only the local copy changed

    return 0;
}
```

**Resolved output:**

```
processing (read-only): 9 chars
original: "TEMP:23.5"
modified: "Xemp:23.5"
```

Resolution worth internalizing: `original` is genuinely unaffected in both calls, even though `processAndModify` received "a copy." The trap to avoid is calling **non-const** methods (`operator[]`, `data()` as non-const, etc.) on a `QString`/`QList` you _think_ is just being read — each such call risks an unnecessary detach, which is a real, measurable cost in a hot path (e.g. a serial-line parser called thousands of times a second). Prefer `constData()`, `at()`, and const-ref parameters when you're not intentionally mutating.

**Resolved example 3 — `QMap` for structured lookups, and `QVariant` as the type-erased glue**

```cpp
#include <QCoreApplication>
#include <QMap>
#include <QVariant>
#include <QDebug>

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    // QMap keeps keys SORTED automatically (it's a red-black tree internally,
    // same complexity guarantees as std::map: O(log n) lookup/insert).
    QMap<QString, QVariant> deviceReading;
    deviceReading["device_id"] = "sensor-07";
    deviceReading["temperature"] = 23.5;        // double, stored type-erased
    deviceReading["humidity"] = 61;             // int
    deviceReading["online"] = true;             // bool
    deviceReading["last_seen"] = QDateTime::currentDateTime();

    // Iterating a QMap gives keys in sorted order -- deterministic, unlike QHash
    qDebug() << "--- reading, keys sorted alphabetically ---";
    for (auto it = deviceReading.constBegin(); it != deviceReading.constEnd(); ++it) {
        qDebug() << it.key() << "->" << it.value();
    }

    // Extracting back to a concrete type from QVariant -- resolved, both success and failure paths:
    bool ok = false;
    double temp = deviceReading.value("temperature").toDouble(&ok);
    qDebug() << "temperature extracted:" << temp << "success:" << ok;

    int wrongTypeAttempt = deviceReading.value("device_id").toInt(&ok);
    qDebug() << "attempting toInt() on a string:" << wrongTypeAttempt << "success:" << ok;
    // Resolved: "sensor-07" isn't numeric, so toInt() returns 0 and ok is false --
    // ALWAYS check the bool out-param when the source type isn't guaranteed, rather
    // than trusting the returned value blindly.

    // Missing key -- resolved behavior:
    QVariant missing = deviceReading.value("pressure");   // key doesn't exist
    qDebug() << "missing key isValid():" << missing.isValid();   // false -- default-constructed QVariant

    return 0;
}
```

**Resolved output:**

```
--- reading, keys sorted alphabetically ---
"device_id" -> QVariant(QString, "sensor-07")
"humidity" -> QVariant(int, 61)
"last_seen" -> QVariant(QDateTime, ...)
"online" -> QVariant(bool, true)
"temperature" -> QVariant(double, 23.5)
temperature extracted: 23.5 success: true
attempting toInt() on a string: 0 success: false
missing key isValid(): false
```

Note the resolved ordering: `QMap` printed keys alphabetically (`device_id`, `humidity`, `last_seen`, `online`, `temperature`) regardless of insertion order — this is a real, guaranteed property of `QMap` (backed by a balanced tree), distinct from `QHash` (same interface, but unordered, faster average-case lookup, no sorting guarantee). For a device-reading record you intend to serialize deterministically (e.g. for a stable JSON key order, or for repeatable test output), `QMap` is the correct choice over `QHash` specifically _because_ of this ordering guarantee — a decision with concrete consequences we'll return to on Day 10 (JSON).

**Key takeaways:**

- Qt containers use implicit sharing (COW): copies are O(1) reference-count bumps; deep copies only happen lazily on the first write ("detach"). This makes pass-by-value far cheaper than the STL equivalent, but means you should avoid gratuitous non-const method calls that trigger needless detaches.
- Prefer `const &` parameters and const accessor methods (`constData()`, `at()`) whenever you're only reading — this guarantees zero detach cost.
- `QMap` keeps keys sorted (tree-based, deterministic iteration order); `QHash` doesn't but is faster on average — choose based on whether iteration order matters to you.
- `QVariant` is the type-erased container underlying `QObject::setProperty()` (Day 1), `QSettings` (Day 9), and JSON conversion (Day 10) — always check the `bool *ok` out-parameter on `toXxx()` conversions rather than trusting an unchecked return value, since a failed conversion silently returns a default value instead of throwing.

Day 7 is this week's mini-project: a log-rotating file writer driven by `QTimer`, combining QObject ownership (Day 5), signals/slots (Day 3), and QTimer (Day 4) into your first small complete Qt Core component — before moving into Week 2's file I/O and JSON material that it'll set up nicely.