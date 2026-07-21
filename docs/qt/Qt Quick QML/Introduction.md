Good timing — QML was flagged back on Day 30 of the Widgets curriculum as the right tool if you ever need a touchscreen-first display (a wall-mounted status panel, not a desktop workstation), so this slots in as a genuine complementary skill, not a redundant one. Here's the roadmap, then straight into Day 1.

### The 10-Day QML/Qt Quick Curriculum

**Phase 1 — Foundations (Days 1–3)**  
QML's declarative model vs. Widgets' imperative model, the scene graph, anchors/positioners, basic components, and JavaScript integration — with an explicit map back to Widgets concepts you already know.

**Phase 2 — Real Application Structure (Days 4–6)**  
Models (`ListModel`, C++ `QAbstractListModel` exposed to QML), `Repeater`/`ListView`/`GridView`, states and transitions, and — critically — the correct C++/QML integration pattern (`QQmlApplicationEngine`, context properties, `Q_INVOKABLE`, exposing your existing C++ workers to QML).

**Phase 3 — Production Concerns (Days 7–9)**  
Custom styling (no QSS — a different system entirely), touch/gesture handling, performance on embedded/GPU-constrained targets (directly relevant to a Pi-driven touchscreen), and threading (same worker-object rules, different UI consumption side).

**Phase 4 — Capstone (Day 10)**  
A touchscreen-oriented `mqtt_monitor` status panel — reusing your actual C++ ingestion layer (`MqttWorker`, `DeviceTableModel` from the Widgets build) with a QML front end instead of Widgets, since the backend threading/data layer doesn't change at all, only the presentation layer does.