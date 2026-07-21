[[Capstone Project]]

# Day 30 — Settings, End-to-End Integration, Final Deployment, and Course Wrap-Up

The last day. Completing the Settings page (tying Days 3, 9, 12, and 17 together), a genuine end-to-end integration pass across the whole app, a final Pi deployment checklist, and a real review of the 30-day arc — what you actually built, and what's deliberately left for you to extend beyond this course.

## Concept: `SettingsPage.qml` — the final piece, properly wired to real backends

```qml
// pages/SettingsPage.qml
import QtQuick
import QtQuick.Controls
import QtQuick.Layouts
import MonitorApp

Item {
    id: root

    ColumnLayout {
        anchors.fill: parent
        anchors.margins: 16
        spacing: 16

        Label {
            text: qsTr("Broker Connection")
            font.bold: true
            font.pixelSize: 16
            color: Theme.textPrimary
        }

        RowLayout {
            Layout.fillWidth: true
            Label { text: qsTr("Host:"); color: Theme.textSecondary; Layout.preferredWidth: 80 }
            TextField {
                id: hostField
                Layout.fillWidth: true
                text: MqttManager.brokerHost
                onEditingFinished: MqttManager.brokerHost = text
            }
        }

        RowLayout {
            Label {
                text: qsTr("Status:")
                color: Theme.textSecondary
                Layout.preferredWidth: 80
            }
            Label {
                text: ConnectionStateMachine.currentState
                color: ConnectionStateMachine.currentState === "connected" ? Theme.success
                     : ConnectionStateMachine.currentState === "failed" ? Theme.danger
                     : Theme.warning
            }
        }

        RowLayout {
            spacing: 8
            Button {
                text: ConnectionStateMachine.currentState === "connected" ? qsTr("Disconnect") : qsTr("Connect")
                onClicked: ConnectionStateMachine.currentState === "connected"
                    ? MqttManager.disconnectFromBroker()
                    : ConnectionStateMachine.requestConnect()
            }
            BusyIndicator {
                running: ConnectionStateMachine.currentState === "connecting"
                    || ConnectionStateMachine.currentState === "reconnecting"
                width: 24; height: 24
            }
        }

        Label {
            text: qsTr("Database")
            font.bold: true
            font.pixelSize: 16
            color: Theme.textPrimary
            Layout.topMargin: 12
        }

        RowLayout {
            Layout.fillWidth: true
            Label {
                text: DatabaseManager.connected ? qsTr("Connected") : qsTr("Not connected")
                color: DatabaseManager.connected ? Theme.success : Theme.danger
            }
            Item { Layout.fillWidth: true }
            Label {
                text: qsTr("%1 rows in telemetry table").arg(DatabaseManager.telemetryRowCount())
                color: Theme.textSecondary
            }
        }

        Item { Layout.fillHeight: true }   // pushes everything above to the top

        Label {
            text: qsTr("mqtt_monitor v1.0 — Qt %1").arg(qVersion())
            color: Theme.textSecondary
            font.pixelSize: 11
            Layout.alignment: Qt.AlignHCenter
        }
    }
}
```

Notice this page is almost entirely bindings to real `Q_PROPERTY`s from `MqttManager`, `ConnectionStateMachine`, and `DatabaseManager` — three separate singletons, none of them requiring any manual wiring in this file beyond `import MonitorApp`. This is the cumulative payoff of Day 10's registration discipline, now visible across an entire real page rather than a single example.

## Concept: End-to-end integration checklist — the actual test before calling this "done"

This is the pass that catches the bugs no single day's exercise would have surfaced, because they only show up when every layer runs together, live, for a sustained period:

1. **Cold start**: launch the app with no broker running. Confirm `ConnectionStateMachine` correctly walks `disconnected → connecting → reconnecting → failed` (Day 21) rather than hanging or crashing, and that every page (Overview's tiles, Charts' empty state, History's empty state) degrades gracefully rather than showing garbage or blank confusion.
2. **Live burst**: start your broker, publish a rapid burst of messages across several devices (Day 18's threading test, for real this time). Watch for UI stutter (Day 22's profiling), confirm `DeviceListModel` updates correctly under load, confirm the Overview tiles' `NOTIFY`-driven counts stay accurate throughout.
3. **Navigation stress**: drawer-navigate through every page repeatedly, drill into device detail and back (Day 23's `push`/`pop`), confirm no memory growth over repeated navigation (a `StackView` leak here would be a real bug worth catching now).
4. **Sustained runtime**: leave it running for at least an hour with live data flowing, watch memory in a system monitor — this is where an un-trimmed chart series (Day 20) or an accidentally continuous `ParticleSystem` emitter (Day 19) would reveal itself; a one-minute test would not.
5. **Network interruption recovery**: kill your broker mid-session, confirm the state machine's retry/backoff engages (Day 21), confirm it reconnects cleanly when the broker returns, without requiring an app restart.
6. **Pi deployment pass**: run the same checklist on actual Pi hardware (Day 25), not just your dev machine — profiling and behavior can genuinely differ, per Day 22's explicit warning.

Run this checklist and fix whatever it surfaces before considering the capstone complete — this is a materially different (and more valuable) activity than any single day's isolated exercise, because it's the only place integration bugs actually live.

## Concept: Final deployment checklist (Day 25, assembled)

```bash
# 1. Release build, not debug
cmake -B build-rpi -DCMAKE_TOOLCHAIN_FILE=/path/to/qt6-rpi-toolchain.cmake -DCMAKE_BUILD_TYPE=Release
cmake --build build-rpi -j$(nproc)

# 2. Deploy binary + libs
rsync -avz build-rpi/appMonitor pi@raspberrypi.local:/opt/mqtt_monitor/
rsync -avz qt6-rpi-libs/ pi@raspberrypi.local:/opt/mqtt_monitor/lib/

# 3. Install and enable the systemd service (Day 25)
sudo systemctl enable --now mqtt-monitor-gui.service

# 4. Confirm eglfs, not xcb — the most common silent Pi failure
journalctl -u mqtt-monitor-gui.service -f
```

## What this course actually built, and what it deliberately didn't

**Covered, and now genuinely yours**: the full declarative UI model (properties, bindings, layouts), model/view/delegate architecture from toy to production, the complete C++/QML bridge (properties, invokables, ownership, singletons), real backend integration (MQTT, SQLite, networking, threading), production concerns (styling, performance, testing, deployment) — and critically, _why_ each tool exists and when to reach for it versus its alternatives, not just syntax.

**Deliberately left for you to extend, because they're genuinely open-ended rather than a fixed 30-day scope**: Qt SCXML if your lifecycle logic outgrows `QStateMachine`'s C++ approach; a full translation pipeline if a second-language user ever actually shows up (Day 24's honest deferral); `QSortFilterProxyModel` if your device count ever genuinely outgrows the simple `visible:` filter (Day 28's judgment call, revisit if the premise changes); Kubernetes, if your Docker course's Day 30 conceptual bridge ever becomes relevant to how you deploy this app's backend services at scale; and static Qt linking (Day 25) if the dynamic library deployment ever proves fragile across Pi OS updates.

## Final exercise — the only one that matters today

Run the full end-to-end integration checklist above, on real hardware if you have it, against your real broker and database. Fix whatever it finds. That's the actual capstone deliverable — not new code, but proof the 30 days of individually-correct pieces genuinely cohere into one thing that stays up.

## Course-level key takeaways

- **The core mental shift (Day 1)** — bindings over imperative assignment — is the thread running through every single day since, from a status dot's color to a chart's data trimming to a filter field's visibility.
- **Model/View/Delegate discipline (Day 6)** paid off repeatedly and concretely: the same `DeviceListModel` fed a `ListView`, a `ComboBox`, and a `ChartView`'s series generation without duplicated logic anywhere — this is the single highest-leverage pattern in the whole course.
- **Where logic belongs (Day 5)** — C++ for anything stateful, testable, or non-trivial; QML for display and composition — resurfaced concretely at least four times (Day 9's invokables, Day 14's aggregate stats, Day 21's state machine, Day 26's test-layer split), each time as a real decision, not a rule recited for its own sake.
- **Defensive boundaries** — Day 13's canvas clamping, Day 16's MQTT payload validation, Day 17's parameterized queries — are the same instinct applied at every place untrusted or unpredictable data enters your app, matching the discipline you already bring from embedded/serial work.
- **Profile and test at the real boundary** — Day 22's Pi-vs-desktop performance gap and Day 26's "test once, at the layer where logic lives" both make the same point: verify where the real risk is, not where it's easiest to check.

You now have a genuinely production-shaped Qt Quick application, architecturally sound enough to keep extending, and — more importantly — the judgment for _when_ to reach for each tool, which is the part that doesn't show up in any single day's code sample. That was the actual goal of all thirty days.