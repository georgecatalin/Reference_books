### 30-Day Qt Core Syllabus

**Week 1 — Foundations**

1. Qt's object model: QObject, meta-object system, moc — the theory underpinning everything else
2. QCoreApplication & the event loop
3. Signals & slots — mechanism and connection types
4. QTimer — single-shot, repeating, precise timers
5. QObject parent/child ownership & memory management
6. Qt containers (QString, QList, QMap, QVariant) vs STL — implicit sharing theory
7. **Mini-project:** log-rotating file writer driven by QTimer

**Week 2 — Data & Configuration**  
8. QFile, QIODevice, QTextStream, QDataStream  
9. QSettings (ini/native config)  
10. QJsonDocument / QJsonObject / QJsonArray  
11. QRegularExpression  
12. Q_PROPERTY and custom data model classes  
13. QDateTime, QTimeZone  
14. **Mini-project:** serial-line parser → structured JSON record

**Week 3 — Concurrency & Processes**  
15. Qt's threading model — theory: affinity, event loops per thread  
16. QThread — worker + moveToThread pattern  
17. Queued vs direct connections across threads (revisited with real threading)  
18. QMutex, QReadWriteLock  
19. QThreadPool & QRunnable  
20. QProcess  
21. **Mini-project:** multi-threaded worker pool processing simulated sensor readings

**Week 4 — Networking, State, Integration**  
22. QTcpSocket / QTcpServer  
23. QUdpSocket  
24. QNetworkAccessManager  
25. QStateMachine — theory of hierarchical state machines, then application  
26. Event filters & custom QEvent subclasses  
27. QLoggingCategory & idiomatic Qt error handling  
28. QtTest unit testing  
29. CMake structure & deployment for embedded targets  
30. **Capstone:** Qt Core serial→JSON→network relay with threading, JSON, sockets, state machine lifecycle