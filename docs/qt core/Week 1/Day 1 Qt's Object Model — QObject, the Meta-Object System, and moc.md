[[Foundations]]


## Day 1: Qt's Object Model — QObject, the Meta-Object System, and moc

Everything else in this course — signals/slots, properties, QTimer, threading — is built on this. Skipping it is why most self-taught Qt developers hit confusing errors later ("undefined reference to vtable") without knowing why.

**Theory: why Qt needed its own object model**

Standard C++ has no built-in **reflection** — at runtime, an object doesn't know its own class name, its list of methods, or which of its members are meant to notify others of change. Qt needed all of that to make signals/slots, properties, and dynamic introspection work, without waiting for C++ to grow real reflection (which it still doesn't have, even in C++23). Qt's solution, designed in the early 1990s and still in use today:

1. **QObject** — the base class every "Qt-aware" class derives from. It gives an object an identity in Qt's world: a parent/child tree, an event-handling interface, and — critically — a `QMetaObject*` describing its own type.
2. **The `Q_OBJECT` macro** — placed in the class body, this declares (but does not define) extra methods the meta-object system needs: `metaObject()`, `qt_metacast()`, `qt_metacall()`, plus storage for the class's static `QMetaObject` instance.
3. **moc (Meta-Object Compiler)** — a code generator that runs _before_ your normal C++ compiler. It scans headers for `Q_OBJECT`, `signals:`, `slots:` (or `Q_SLOT`), and `Q_PROPERTY`, and generates a `moc_yourclass.cpp` file containing the real C++ implementations of those declared-but-undefined methods — including the signal emission machinery and the property/method reflection tables.

This means: **signals and slots are not a C++ language feature.** They're ordinary C++ methods with extra generated plumbing. `emit mySignal(x)` literally expands (via macro, though modern Qt lets you drop `emit`) into a call to code moc wrote for you, which looks up connections in an internal table and invokes the connected slots.

**Why CMake's `AUTOMOC ON` matters:** it tells the build system to run moc automatically on every header with `Q_OBJECT` before compiling. If you forget `Q_OBJECT` in a class that needs it, or forget to enable AUTOMOC, the compiler compiles fine but the **linker** fails with something like `undefined reference to vtable for YourClass` — because moc never generated the virtual table entries the class declaration promised. That error is _always_ a `Q_OBJECT`/moc problem, never a "your code is wrong" problem. Recognizing this immediately, instead of debugging your class logic, will save you real time.

**Resolved example — demonstrating the object model directly, with introspection:**

```cpp
// device.h
#pragma once
#include <QObject>
#include <QString>

// Every class that wants signals, slots, or Q_PROPERTY reflection
// MUST derive from QObject and contain Q_OBJECT.
class Device : public QObject
{
    Q_OBJECT   // <-- triggers moc to generate metaObject(), qt_metacast(), etc.

    // Q_PROPERTY registers 'name' in the meta-object system: it becomes
    // introspectable at runtime (readable via metaObject(), settable via
    // setProperty("name", ...) using only a string, no compile-time reference
    // to the setter). We'll use Q_PROPERTY properly in Day 12; for now this
    // just proves the reflection is real and not just documentation.
    Q_PROPERTY(QString name READ name WRITE setName)

public:
    explicit Device(const QString &name, QObject *parent = nullptr)
        : QObject(parent), m_name(name) {}

    QString name() const { return m_name; }
    void setName(const QString &name) { m_name = name; }

private:
    QString m_name;
};
```

```cpp
// main.cpp
#include <QCoreApplication>
#include <QDebug>
#include "device.h"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    Device sensor("TempSensor1");

    // Proof the meta-object system is real, not just a naming convention:
    const QMetaObject *meta = sensor.metaObject();

    qDebug() << "Class name via reflection:" << meta->className();
    // Output: Class name via reflection: Device
    // Note: this string comes from moc-generated code, NOT from typeid(sensor).name(),
    // which would give a compiler-mangled name instead.

    qDebug() << "Property count:" << meta->propertyCount();
    // Output includes QObject's own baseline properties (like "objectName")
    // plus our declared "name" property.

    for (int i = 0; i < meta->propertyCount(); ++i) {
        qDebug() << " -" << meta->property(i).name();
    }
    // Output includes:
    //  - objectName
    //  - name

    // Set a property purely by string name -- no compile-time call to setName().
    // This is only possible because Q_PROPERTY registered it with moc.
    sensor.setProperty("name", "TempSensor1-Renamed");
    qDebug() << "After setProperty by string:" << sensor.name();
    // Output: After setProperty by string: TempSensor1-Renamed

    return 0;   // no app.exec() needed -- no event loop used in this example,
                // since nothing here depends on it (no signals, no timers).
    // QCoreApplication is still required to exist because some Qt Core
    // internals (like translation/locale handling) assume an app instance.
}
```

**CMakeLists.txt:**

```cmake
cmake_minimum_required(VERSION 3.16)
project(day1)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_AUTOMOC ON)   # runs moc automatically on headers containing Q_OBJECT

find_package(Qt6 REQUIRED COMPONENTS Core)
add_executable(day1 main.cpp device.h)
target_link_libraries(day1 Qt6::Core)
```

**Deliberate failure, resolved — the error you must learn to recognize instantly:**

If you comment out `Q_OBJECT` in `device.h` but keep `Q_PROPERTY`, the build fails at the **compiler** stage (not linker) with an error like:

```
error: 'staticMetaObject' is not a member of 'Device'
```

because `Q_PROPERTY` itself expands into code that references machinery `Q_OBJECT` would have declared. If instead you keep `Q_OBJECT` but your build system doesn't run moc (e.g. `AUTOMOC` isn't set and you didn't manually add the generated file), the class **compiles** fine but the **linker** fails with:

```
undefined reference to `vtable for Device'
```

Both are shown here resolved rather than left as a puzzle: **compiler error mentioning a missing meta-object member → check `Q_OBJECT` is present. Linker error mentioning a missing vtable → check moc actually ran (AUTOMOC on, or the generated `moc_*.cpp` is compiled in).** These two errors account for the overwhelming majority of "Qt won't build" problems you'll hit in real projects, and now you have both memorized against their causes rather than needing to rediscover them.

**Key takeaways:**

- QObject + `Q_OBJECT` + moc together give C++ a lightweight, hand-rolled reflection system that plain C++ lacks natively.
- Signals/slots and Q_PROPERTY are not special syntax — they're macros and generated code sitting on top of ordinary C++.
- The meta-object system is genuinely introspectable at runtime (`metaObject()`, `setProperty()` by string), which is what makes Qt's dynamic behaviors (like QML binding, though out of scope here) possible.
- Missing `Q_OBJECT` → compiler error about a missing meta-object symbol. Missing moc generation → linker error about a missing vtable. These are distinct failure modes with distinct causes — don't debug your logic when you see either; check the object model plumbing first.

Day 2 will build directly on this: QCoreApplication and the event loop, now framed in terms of _why_ QObject's parent/child and event-delivery mechanisms depend on the meta-object system you just learned.