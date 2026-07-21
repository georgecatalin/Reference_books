### The 30-Day Qt Curriculum

**Phase 1 — Foundations (Days 1–8)**  
Widget basics, layouts, signals & slots, the event loop, CMake + Qt project structure, dialogs, menus/toolbars, basic MVC with widgets.

**Phase 2 — Core Application Skills (Days 9–16)**  
Model/View architecture (QAbstractTableModel etc.), custom painting (QPainter), styling with QSS, settings persistence (QSettings), resource files, custom widgets, drag & drop, undo/redo framework.

**Phase 3 — Concurrency, I/O & Data (Days 17–24)**  
QThread and worker-object patterns, QSerialPort (your embedded background is directly useful here), networking (QTcpSocket/QNetworkAccessManager), Qt MQTT module, QSqlDatabase + SQLite, JSON handling, inter-thread communication done correctly (no shared-state races).

**Phase 4 — Advanced & Capstone (Days 25–30)**  
QTest unit testing, plugin architecture, performance profiling, deployment (linuxdeployqt / windeployqt, static linking), cross-compiling for Raspberry Pi/BeagleBone targets, and a capstone: building a **Qt Widgets dashboard frontend for `mqtt_monitor`** — live serial/MQTT data feed, SQLite-backed history view, device status table, connection management UI.

We'll skip QML/Qt Quick as a separate deep-dive — for a systems/embedded-dashboard use case, Qt Widgets + QPainter gets you further faster, and I'll flag where QML would matter if you ever need touchscreens.

Same format as your C++/Python/Docker curricula: concept → annotated code → exercise → key takeaways, single continuous page, exercises to work through after you've consumed the content.