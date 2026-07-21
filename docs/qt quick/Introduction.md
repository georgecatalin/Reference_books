
## The 30-Day Roadmap

**Phase 1 — QML Foundations (Days 1–8)**

1. Qt ecosystem, Qt Quick vs Widgets, project anatomy, first QML app
2. QML syntax: properties, IDs, the object tree, anchors
3. Qt Quick Controls 2: buttons, text fields, checkboxes, basic forms
4. Layouts: RowLayout/ColumnLayout/GridLayout vs anchors — when to use which
5. Signals, handlers, and JavaScript in QML (and where JS should _stop_)
6. Models & Views: ListModel, ListView, delegates
7. States, transitions, and basic property animations
8. Mini project: a device settings/config panel (review day)

**Phase 2 — C++/QML Integration & Data (Days 9–18)** 9. Exposing C++ objects to QML: `Q_PROPERTY`, `Q_INVOKABLE`, context properties 10. `qmlRegisterType`, singletons, ownership rules (this trips up everyone with a C background) 11. Building custom, reusable QML components 12. Styling: Controls customization, Material/Universal, building a consistent theme 13. Canvas & custom painting — gauges, sparklines, custom widgets 14. Feeding QML from C++ models: `QAbstractListModel` for live device lists 15. Networking basics: `QNetworkAccessManager`, REST calls from Qt 16. MQTT in Qt: Qt MQTT module vs wrapping mosquitto — connecting to your existing broker 17. SQLite from Qt (`QSqlDatabase`) — reading your `mqtt_monitor` schema directly 18. Threading: `QThread` + worker objects so serial/MQTT I/O never blocks the UI

**Phase 3 — Advanced Qt Quick (Days 19–26)** 19. Advanced animations, `Behavior`, easing, particle basics 20. Live data visualization: QtCharts/QtGraphs for telemetry streams 21. State machines (QML states vs SCXML) for device/connection states 22. Performance: profiling, item caching, asynchronous loading, avoiding binding loops 23. Multi-window apps, dialogs, popups, Drawer/navigation patterns 24. Localization & accessibility fundamentals 25. Deployment: Linux desktop packaging, and Qt for embedded Linux (Boot to Qt) targeting Raspberry Pi 26. Testing QML: Qt Quick Test

**Phase 4 — Capstone (Days 27–30)** A full **Qt Quick MQTT Device Monitor Dashboard**: C++ backend bridge to your broker/DB, live telemetry views, device list with status, alerting, packaged and deployed to a Pi — the GUI layer for the same system you've already built in Python and C++.

---

One quick check before I write Day 1 in full: do you want me to assume **Qt 6** throughout (current, and what you'd use on a Pi target today), or do you have a specific Qt version constraint from existing work?