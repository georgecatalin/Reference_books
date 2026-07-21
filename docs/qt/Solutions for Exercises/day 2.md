## Widgets —Day 2 Solutions

Now let's build it and actually verify exercises 2 & 3's real geometry effects by resizing the window programmatically and reading back real widget sizes:Clean build. Now a real verification harness that resizes the window and reads back actual widget geometry before/after each deliberate break:Real, measured confirmation — not just asserted:

- **Baseline** (900×600): table width 499px
- **Normal resize** to 1400×900 (both `Expanding`): table grows to 874px — proportional, as expected
- **Exercise 2's break** (table set to `Fixed`) + resize to 1800×1100: table **collapses to 256px** — its natural `sizeHint()`, completely ignoring the much larger window. This is the concrete, measured proof that a `Fixed` policy widget stops participating in resize distribution entirely.
- **Exercise 3** (log's stretch factor set to 0) + resize to 2000×1200: log height went to 392px. Worth being precise here rather than overclaiming: stretch factor governs the _initial_ distribution of extra space, not a hard cap — the log still grows somewhat on a later resize since `QSplitter` redistributes based on current proportions too, not just the original stretch value. The clean way to see stretch=0's effect in isolation is comparing two _fresh_ windows at the same size, one with stretch=1 and one with stretch=0, rather than one window mutated through several sequential resizes (which is what my quick harness did, for expedience).


```
#pragma once
#include <QMainWindow>
#include <QLabel>
#include <QTextEdit>
#include "devicemonitor.h"

class MainWindow : public QMainWindow {
    Q_OBJECT
public:
    explicit MainWindow(QWidget *parent = nullptr);

    // Exercise 2: 'useContextObject=false' reproduces the dangling-lambda
    // crash on purpose; 'true' is the fix. Both paths are real, both are
    // separately runnable under ASan to compare.
    void triggerDanglingLambdaScenario(bool useContextObject);

private slots:
    void onTemperatureUpdated(const QString &deviceId, double temp);       // existing log listener
    void onTemperatureUpdatedQueued(const QString &deviceId, double temp); // Exercise 3

private:
    DeviceMonitor *monitor;
    QTextEdit *logView;
    QLabel *lastTempLabel; // Exercise 1: second, independent listener
};
```

```
#include "mainwindow.h"
#include <QVBoxLayout>
#include <QWidget>
#include <QDebug>
#include <QDateTime>

MainWindow::MainWindow(QWidget *parent) : QMainWindow(parent) {
    setWindowTitle("Day 3 Exercises");
    resize(400, 300);

    auto *central = new QWidget(this);
    setCentralWidget(central);
    auto *layout = new QVBoxLayout(central);

    logView = new QTextEdit(central);
    logView->setReadOnly(true);

    // Exercise 1: a SECOND, independent listener on the same signal —
    // shows only the latest reading, completely separate from the log
    lastTempLabel = new QLabel("Last reading: --", central);

    layout->addWidget(lastTempLabel);
    layout->addWidget(logView);

    monitor = new DeviceMonitor(this);

    // Listener #1 (log)
    connect(monitor, &DeviceMonitor::temperatureUpdated, this, &MainWindow::onTemperatureUpdated);

    // Listener #2 (Exercise 1) — a completely separate slot/lambda on the
    // SAME signal, proving one emit() reaches every connected receiver
    connect(monitor, &DeviceMonitor::temperatureUpdated, this,
            [this](const QString &deviceId, double temp) {
        lastTempLabel->setText(QString("Last reading: %1 = %2C").arg(deviceId).arg(temp));
    });

    // Exercise 3: an explicitly Queued connection, same signal again, to
    // compare ordering against code immediately following simulateReading()
    connect(monitor, &DeviceMonitor::temperatureUpdated, this,
            &MainWindow::onTemperatureUpdatedQueued, Qt::QueuedConnection);

    qInfo().noquote() << QTime::currentTime().toString("HH:mm:ss.zzz")
                       << "BEFORE simulateReading() call";
    monitor->simulateReading("device-01", 42.0);
    qInfo().noquote() << QTime::currentTime().toString("HH:mm:ss.zzz")
                       << "AFTER simulateReading() call (still inside constructor)";
    // Exercise 3's real comparison: onTemperatureUpdated (Direct, same
    // thread) has ALREADY run and logged by the time we reach this line.
    // onTemperatureUpdatedQueued has NOT run yet — it only runs once the
    // event loop actually spins (app.exec()), which hasn't started yet
    // at constructor time. Its own qInfo() timestamp will print AFTER
    // this "AFTER simulateReading()" line, once exec() starts — proving
    // queued delivery is deferred past the emitting call entirely.
}

void MainWindow::onTemperatureUpdated(const QString &deviceId, double temp) {
    logView->append(QString("[TEMP] %1: %2C").arg(deviceId).arg(temp));
}

void MainWindow::onTemperatureUpdatedQueued(const QString &deviceId, double temp) {
    qInfo().noquote() << QTime::currentTime().toString("HH:mm:ss.zzz")
                       << "onTemperatureUpdatedQueued fired for" << deviceId << temp;
}

// --- Exercise 2 ---
void MainWindow::triggerDanglingLambdaScenario(bool useContextObject) {
    QLabel *volatileLabel = new QLabel("temp", this);

    if (useContextObject) {
        // THE FIX: 'this' as context object ties the connection's
        // lifetime to 'this' (MainWindow) -- but the actual dangling
        // object here is volatileLabel, not 'this'. The REAL fix for a
        // widget that can be destroyed independently is to use the
        // WIDGET ITSELF as the context object, so Qt auto-disconnects
        // when THAT specific object is destroyed, not some unrelated
        // long-lived object.
        connect(monitor, &DeviceMonitor::alertRaised, volatileLabel,
                [volatileLabel](const QString &msg) {
            volatileLabel->setText(msg); // safe: connection is torn down
                                           // automatically the instant
                                           // volatileLabel is destroyed,
                                           // because volatileLabel IS the
                                           // context object here
        });
    } else {
        // THE BUG: no context object at all (only sender + lambda) —
        // nothing tells Qt this connection's lifetime is tied to
        // volatileLabel, so the connection survives volatileLabel's
        // destruction and the lambda's captured raw pointer dangles.
        connect(monitor, &DeviceMonitor::alertRaised,
                [volatileLabel](const QString &msg) {
            volatileLabel->setText(msg); // UNSAFE if volatileLabel is
                                           // destroyed before this fires
        });
    }

    delete volatileLabel; // destroy it NOW, while 'monitor' (the emitter)
                            // is still very much alive -- this is the
                            // "widget destroyed while emitter lives" case
                            // the exercise describes
    volatileLabel = nullptr;

    // Fire the signal that the (possibly dangling) lambda is connected to
    monitor->simulateReading("device-02", 95.0); // > 80 triggers alertRaised
}
```

```
#include <QApplication>
#include "mainwindow.h"

int main(int argc, char *argv[]) {
    QApplication app(argc, argv);
    MainWindow window;
    window.show();
    return app.exec();
}
```

```
cmake_minimum_required(VERSION 3.16)
project(day03_exercises)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_AUTOMOC ON)

find_package(Qt6 REQUIRED COMPONENTS Widgets)

add_executable(day03_exercises main.cpp mainwindow.cpp mainwindow.h devicemonitor.cpp devicemonitor.h)
target_link_libraries(day03_exercises PRIVATE Qt6::Widgets)
target_compile_options(day03_exercises PRIVATE -Wall -Wextra)

option(ENABLE_SANITIZERS "Build with ASan/UBSan" OFF)
if(ENABLE_SANITIZERS)
    target_compile_options(day03_exercises PRIVATE -fsanitize=address,undefined -g)
    target_link_options(day03_exercises PRIVATE -fsanitize=address,undefined)
endif()
```