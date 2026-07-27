[[Foundations]]

**Theory: Qt's ownership model vs the RAII/smart-pointer discipline from your C++ course**

Your C++ course drilled RAII: an object's lifetime is tied to its scope or to a smart pointer (`unique_ptr`/`shared_ptr`), and destruction is deterministic and automatic. Qt predates widespread smart pointer use in C++ (it was designed in the early-90s, `unique_ptr` didn't exist until C++11) and solved the same problem — "who deletes this, and when?" — with a **tree-based ownership model** built into `QObject` itself, using the same infrastructure from Day 1.

The mechanics:

- Every `QObject` constructor takes an optional `QObject *parent`.
- If you pass a parent, the child registers itself in the parent's internal children list (`QObject::children()`).
- When a parent is destroyed, its destructor (`~QObject()`) walks that list and calls `delete` on every child, recursively — a child's own children get destroyed too, all the way down.
- A child removes itself from its parent's list automatically if it's destroyed independently first (e.g. a stack-allocated child going out of scope) — so there's no dangling pointer left in the parent.

This means: **if an object has a parent, you almost never call `delete` on it yourself** — the parent will do it for you when the parent goes away. This is a deliberate, different philosophy from `unique_ptr` (single deterministic owner, moved not copied) — it's closer to a _tree_ of ownership where deleting a subtree root cleans up everything beneath it, which maps naturally onto UI widget hierarchies (Qt's original use case) and, just as usefully, onto **service object graphs** like a device monitor's `SerialReader` → `Parser` → `Logger` chain.

**Critical rule Qt itself relies on: never give a heap object two parents, and never mix raw heap ownership with `unique_ptr` for anything with a QObject parent.** If a `QObject` has a parent, that parent owns it — full stop. Don't also wrap it in a `unique_ptr`, or you'll get a double-delete crash when both the parent's destructor and the `unique_ptr`'s destructor try to delete the same memory.

**Resolved example 1 — parent/child deletion, proven with destructor logging**

```cpp
#include <QCoreApplication>
#include <QObject>
#include <QDebug>

class Component : public QObject
{
    Q_OBJECT
public:
    Component(const QString &name, QObject *parent = nullptr)
        : QObject(parent), m_name(name)
    {
        qDebug() << "constructing" << m_name;
    }
    ~Component() override
    {
        qDebug() << "destroying" << m_name;   // proves destruction order below
    }
private:
    QString m_name;
};

#include "main.moc"

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    {
        // 'reader' is allocated on the heap but has no parent -- WE own it,
        // and it must be deleted manually or wrapped some other way. Since
        // it's the root of this object graph, that's fine and idiomatic.
        auto *reader = new Component("SerialReader", nullptr);

        // 'parser' and 'logger' are heap-allocated WITH a parent -- reader owns them.
        // We never call delete on these ourselves.
        auto *parser = new Component("Parser", reader);
        auto *logger = new Component("Logger", parser);   // logger's parent is parser, not reader directly

        qDebug() << "--- about to delete reader ---";
        delete reader;   // this alone cascades: reader destroys parser, parser destroys logger
        qDebug() << "--- reader deleted ---";
    }

    return 0;
}
```

**Resolved output:**

```
constructing SerialReader
constructing Parser
constructing Logger
--- about to delete reader ---
destroying SerialReader
destroying Parser
destroying Logger
--- reader deleted ---
```

Notice: **one `delete` call destroyed three objects**, in root-to-leaf order (Qt destroys the parent's own state first, then walks into children — the exact ordering is: `~Component()` for reader runs, and _within_ `~QObject()` which runs as part of unwinding, children are destroyed before `~QObject()` finishes). We never touched `parser` or `logger` with `delete` directly — doing so would be a bug (double-delete when `reader`'s destructor later tries to delete an already-freed child).

**Resolved example 2 — the double-delete bug, shown deliberately, then fixed**

```cpp
// BROKEN version -- do not do this
auto *parent = new Component("Parent", nullptr);
auto *child = new Component("Child", parent);

delete child;   // manually deleting a child that HAS a parent
delete parent;  // parent's destructor tries to delete 'child' again -> CRASH (double free)
```

**Resolved explanation:** when you call `delete child` manually, `child`'s destructor runs and — critically — its `QObject` base destructor removes it from `parent`'s children list as part of tearing itself down. So by the time `delete parent` runs, `parent`'s children list no longer contains `child`, and no double-delete actually occurs in _this_ exact ordering — Qt is specifically designed to make manual-then-parent deletion safe in this order. **The actual danger case** is reversed:

```cpp
// The REAL broken pattern:
auto *parent = new Component("Parent", nullptr);
auto *child = new Component("Child", parent);

delete parent;   // deletes 'child' as part of cascading destruction
delete child;     // CRASH: child's memory was already freed by parent's destructor
```

**Resolved fix:** simply never hold onto a raw pointer to a child past its parent's destruction, and structure ownership so exactly one `delete` call exists per object graph root:

```cpp
auto *parent = new Component("Parent", nullptr);
new Component("Child", parent);   // don't even keep the pointer if you won't need it independently

delete parent;   // single delete, cascades correctly, no dangling pointer ever touched
```

This is the resolution to internalize: **the bug isn't "deleting a child," it's holding a pointer to something whose owner might delete it before you do.** In real service code, this typically means: if `SerialReader` owns `Parser`, don't stash a raw `Parser*` somewhere that outlives your control over `SerialReader`'s lifetime — or use `QPointer<Parser>` (shown next) if you must observe it safely.

**Resolved example 3 — `QPointer`: a safe, auto-nulling weak reference to a QObject**

```cpp
#include <QCoreApplication>
#include <QPointer>
#include <QDebug>
#include "component.h"   // assume Component from example 1, moved to its own header

int main(int argc, char *argv[])
{
    QCoreApplication app(argc, argv);

    auto *parent = new Component("Parent");
    auto *rawChildPtr = new Component("Child", parent);
    QPointer<Component> safeChildRef(rawChildPtr);   // does NOT take ownership, just observes

    qDebug() << "before delete, safeChildRef is null?" << safeChildRef.isNull();   // false

    delete parent;   // destroys parent AND child via cascade

    // rawChildPtr is now a DANGLING raw pointer -- using it would be undefined behavior.
    // safeChildRef, however, is automatically set to nullptr by QObject's destructor logic.
    qDebug() << "after delete, safeChildRef is null?" << safeChildRef.isNull();   // true -- SAFE to check

    if (safeChildRef) {
        qDebug() << "would use it here";   // never reached, correctly
    } else {
        qDebug() << "safely detected the child is gone, skipping use";
    }

    return 0;
}
```

**Resolved output:**

```
before delete, safeChildRef is null? false
after delete, safeChildRef is null? true
safely detected the child is gone, skipping use
```

`QPointer<T>` is the Qt-native equivalent of a weak reference: it doesn't extend the object's lifetime (unlike `shared_ptr`), but unlike a raw pointer, it's automatically zeroed out when the observed `QObject` is destroyed — because `QPointer` registers itself with the object's destruction-tracking machinery (the same tree bookkeeping from example 1). This is the correct tool whenever you need to _hold onto but not own_ a `QObject` that something else might delete out from under you — e.g. a `Logger*` cached elsewhere that the `SerialReader` subtree might delete during a reconnect cycle.

**Key takeaways:**

- Passing `parent` to a `QObject` constructor hands ownership to that parent — its destructor will `delete` all children recursively. You should not manually `delete` an object that has a parent.
- Deleting a parent before a raw pointer you hold to one of its children is the actual danger — the fix is either not holding such pointers past the parent's lifetime, or using `QPointer<T>` for a safe, auto-nulling observation.
- This tree ownership model coexists with, but is philosophically different from, `unique_ptr`/`shared_ptr` — don't mix them for the same object (no `unique_ptr<QObject>` for something that also has a Qt parent).
- Objects with **no** parent (`nullptr`, or simply omitted) are your responsibility to delete — typically these are the root(s) of an object graph, or objects you manage with C++ RAII/smart pointers instead because they aren't meant to participate in Qt's tree at all.

Day 6 moves to Qt's containers — `QString`, `QList`, `QMap`, `QVariant` — and the **implicit sharing** (copy-on-write) theory that makes them behave differently from STL containers in ways that matter for performance-sensitive embedded code.