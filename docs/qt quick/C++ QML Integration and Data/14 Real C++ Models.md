[[C++ QML Integrations and Data]]

# Day 14 — Real C++ Models: `QAbstractListModel` for a Live Device List

Every device list so far has been a `ListModel` with hand-typed `ListElement`s. That's fine for mockups, wrong for a real app — your actual device data will come from MQTT messages and SQLite queries (Days 16–17), arriving asynchronously, with arbitrary numbers of devices, changing fields at unpredictable times. Today: `QAbstractListModel`, the real C++ bridge between your data and every `ListView`/`GridView`/`Repeater` you've built.

## Concept: Why `ListModel` doesn't scale to real data

`ListModel` is QML-native, convenient, but fundamentally a scripting convenience — it holds a JS-visible structure, has no efficient way to be populated from C++ collections, and doesn't integrate with things like `QSortFilterProxyModel` (Day 15+ territory) or Qt's SQL model classes. For anything backed by real application data — especially data arriving from another thread (MQTT callbacks, Day 18) — you need a proper C++ model.

## Concept: The four methods every `QAbstractListModel` subclass must implement

```cpp
// devicelistmodel.h
#pragma once
#include <QAbstractListModel>

struct DeviceInfo {
    QString deviceId;
    int rssi;
    bool online;
    qint64 lastSeenEpoch;
};

class DeviceListModel : public QAbstractListModel
{
    Q_OBJECT
    QML_ELEMENT

public:
    enum Roles {
        DeviceIdRole = Qt::UserRole + 1,
        RssiRole,
        OnlineRole,
        LastSeenRole
    };

    explicit DeviceListModel(QObject *parent = nullptr);

    // ---- Required overrides ----
    int rowCount(const QModelIndex &parent = QModelIndex()) const override;
    QVariant data(const QModelIndex &index, int role) const override;
    QHash<int, QByteArray> roleNames() const override;

    // ---- Mutation API, called from C++ (MQTT callbacks, SQLite loads) ----
    Q_INVOKABLE void addOrUpdateDevice(const DeviceInfo &info);
    void removeDevice(const QString &deviceId);

private:
    QVector<DeviceInfo> m_devices;
    int indexOfDevice(const QString &deviceId) const;
};
```

```cpp
// devicelistmodel.cpp
int DeviceListModel::rowCount(const QModelIndex &parent) const
{
    if (parent.isValid()) return 0;   // list models have no nested rows
    return m_devices.count();
}

QVariant DeviceListModel::data(const QModelIndex &index, int role) const
{
    if (!index.isValid() || index.row() >= m_devices.count())
        return {};

    const auto &device = m_devices.at(index.row());
    switch (role) {
        case DeviceIdRole:  return device.deviceId;
        case RssiRole:      return device.rssi;
        case OnlineRole:    return device.online;
        case LastSeenRole:  return device.lastSeenEpoch;
        default:            return {};
    }
}

QHash<int, QByteArray> DeviceListModel::roleNames() const
{
    return {
        { DeviceIdRole, "deviceId" },
        { RssiRole,     "rssi" },
        { OnlineRole,   "online" },
        { LastSeenRole, "lastSeen" }
    };
}
```

**Read this carefully — this is the part that differs fundamentally from ordinary C++ collection code:** `roleNames()` is what makes `deviceId`, `rssi`, `online` available _by name_ inside a QML delegate, exactly like `ListModel`'s `ListElement` fields were. The integer role constants (`Qt::UserRole + 1`, etc.) are C++-internal bookkeeping; QML never sees them directly, it sees the string names you map them to. Get the mapping wrong (typo in the string, or forget a role in `roleNames()`) and that field is simply `undefined` in QML — no error, no crash, just silently missing data. This is a real, common bug — when a QML binding to a model role shows blank/undefined, check `roleNames()` first.

## Concept: Mutating the model correctly — `beginInsertRows`/`endInsertRows`

This is the part that trips up everyone coming from plain C++ collections, including people with your background — **you cannot just push to the underlying `QVector` and expect the view to notice.** Qt's model/view framework requires you to bracket every structural change with the correct begin/end calls, so views know exactly what changed and can update efficiently (and correctly, for selection/scroll-position preservation) rather than re-rendering everything from scratch:

```cpp
void DeviceListModel::addOrUpdateDevice(const DeviceInfo &info)
{
    int idx = indexOfDevice(info.deviceId);

    if (idx >= 0) {
        // Existing device — update in place
        m_devices[idx] = info;
        QModelIndex modelIdx = index(idx, 0);
        emit dataChanged(modelIdx, modelIdx, {RssiRole, OnlineRole, LastSeenRole});
    } else {
        // New device — structural insert
        beginInsertRows(QModelIndex(), m_devices.count(), m_devices.count());
        m_devices.append(info);
        endInsertRows();
    }
}

void DeviceListModel::removeDevice(const QString &deviceId)
{
    int idx = indexOfDevice(deviceId);
    if (idx < 0) return;

    beginRemoveRows(QModelIndex(), idx, idx);
    m_devices.removeAt(idx);
    endRemoveRows();
}

int DeviceListModel::indexOfDevice(const QString &deviceId) const
{
    for (int i = 0; i < m_devices.count(); ++i)
        if (m_devices.at(i).deviceId == deviceId)
            return i;
    return -1;
}
```

Three distinct patterns here, and they matter for different reasons:

- **In-place update** (`idx >= 0`): no structural change (row count unchanged), so just mutate and emit `dataChanged` with the specific roles that changed — this is your Day 9 `NOTIFY` lesson applied at model-row granularity. Listing the exact roles (`{RssiRole, OnlineRole, LastSeenRole}`) rather than omitting the list lets views optimize — they only re-bind what actually changed.
- **Insert** (`idx < 0`): a genuinely new row — `beginInsertRows`/`endInsertRows` _must_ bracket the actual mutation (`m_devices.append`), not just be called before/after loosely. Get the row indices wrong (off-by-one) and you'll corrupt the view's internal bookkeeping, sometimes silently, sometimes with an assert crash in debug builds.
- **Remove**: same idea, `beginRemoveRows`/`endRemoveRows`.

This is exactly the situation you'll hit in Day 16: an MQTT message arrives for a device you've never seen → insert; for a device already known → in-place update. This method signature is designed for precisely that dispatch.

## Using it from QML — identical delegate code to Day 6

```qml
import QtQuick
import MonitorApp

ListView {
    anchors.fill: parent
    clip: true

    model: DeviceListModel {
        id: deviceListModel
        Component.onCompleted: {
            // Simulated — Day 16 replaces this with real MQTT arrivals
            addOrUpdateDevice({ deviceId: "esp32-04", rssi: -67, online: true, lastSeenEpoch: 0 })
            addOrUpdateDevice({ deviceId: "rpi-monitor-01", rssi: -54, online: true, lastSeenEpoch: 0 })
        }
    }

    delegate: DeviceRow {
        width: ListView.view.width
        deviceId: model.deviceId
        rssi: model.rssi
        online: model.online
    }
}
```

**This is the payoff of the whole Model/View/Delegate investment from Day 6**: your `DeviceRow` delegate is _completely unchanged_ from the `ListModel` version. Only the model source changed — from hand-typed `ListElement`s to a real C++ class that will eventually be driven by network I/O. Nothing about the view layer had to know or care.

## A note on `DeviceInfo` crossing the C++/QML boundary

`addOrUpdateDevice` is `Q_INVOKABLE` and takes a `DeviceInfo` struct — for this to work from QML (where you're passing a JS object literal `{ deviceId: ..., rssi: ... }`), Qt needs a `Q_DECLARE_METATYPE` registration and the struct needs to be convertible from a `QVariantMap`. In practice, for anything beyond simple prototyping, it's often cleaner to have `Q_INVOKABLE` methods take plain primitive arguments (`QString`, `int`, `bool`) rather than custom structs directly from QML — reserve custom-struct marshaling for pure C++-to-C++ calls (like Day 16's MQTT callback calling this same method internally with a real `DeviceInfo`). Keep the QML-facing surface simple and primitive-typed; keep the C++-internal surface as rich as you want.

## Exercise

1. Build `DeviceListModel` exactly as above, register it via `QML_ELEMENT`, and swap it in for Day 8's `ListModel` in your Devices tab. Confirm the `DeviceRow` delegate needs zero changes.
2. Add a `Q_INVOKABLE void simulateRandomUpdate()` method that picks a random existing device and mutates its `rssi` by a random delta, calling the in-place-update path (`dataChanged`, not insert). Hook it to a `Timer` and confirm the `ListView` updates the right row live, without re-rendering the whole list (you can verify this indirectly: add a scroll position, confirm it's preserved during updates — insert/remove would potentially need to preserve it too, but naive full-model resets would not).
3. Deliberately introduce a typo in one role name inside `roleNames()` (e.g., register `"rssii"` instead of `"rssi"`) and confirm the QML delegate silently shows blank/undefined for that field with no console error — this is the debugging instinct worth having burned in once.
4. Add a `Q_INVOKABLE int deviceCount() const` returning `m_devices.count()` and bind a `Label` to it — note that since `rowCount()` changes don't automatically notify a plain invokable method, you'd need to emit a custom signal for this to be reactive; as a lighter alternative, use the model's built-in `count` property that `QAbstractListModel`... — actually note in your own comment: `QAbstractListModel` doesn't expose a `count`/`rowCount` `Q_PROPERTY` by default; if you want `deviceListModel.count` reactive in QML, you must add your own `Q_PROPERTY(int count READ rowCount NOTIFY countChanged)`-style wrapper and emit `countChanged` alongside your insert/remove calls. Implement this.

## Key takeaways

- `QAbstractListModel` requires `rowCount()`, `data()`, `roleNames()` at minimum — `roleNames()` is what makes fields addressable by name in QML; get a role name wrong and QML shows silent `undefined`, not an error.
- Structural changes (insert/remove) **must** be bracketed by `beginInsertRows`/`endInsertRows` (or the remove equivalents) around the actual mutation — get the indices wrong and you corrupt view state, sometimes silently.
- In-place updates use `dataChanged(index, index, {specific roles})` — listing exact changed roles lets views optimize instead of re-binding everything.
- Your QML delegate code from Day 6 needed **zero changes** to work with a real C++ model — this is the entire point of the Model/View/Delegate separation paying off.
- `QAbstractListModel` has no built-in reactive `count`/`rowCount` `Q_PROPERTY` — you must add and emit your own if QML needs to bind to it live.
- Keep `Q_INVOKABLE` QML-facing signatures primitive-typed; reserve rich C++ structs for internal C++-to-C++ calls (like the MQTT callback in Day 16 calling this model directly).

