## Widgets — Day 4 Solutions
Now build and actually simulate clicks/keypresses to verify all three exercises' claimed behavior:This is a clean, fully verified confirmation of the actual mechanism, not just the surface behavior:

- After `show()`: `paintEventCount = 1` (initial paint)
- **Immediately** after a click, _before_ `processEvents()`: still `1` — proves `update()` only _posts_ the repaint, it doesn't run it synchronously
- After `processEvents()`: `2` — the deferred paint actually runs
- **3 rapid clicks with no `processEvents()` between them**: count stays at `2` — all three are deferred
- After `processEvents()`: only `3` (not 5) — Qt genuinely **coalesces** the 3 pending updates into a single repaint, exactly as Day 4 claimed
- Status cycles correctly: Offline→Online→Warning→Offline→Online across the 4 total clicks — the click-cycling logic itself is correct

Exercise 3's real finding is subtler than "the crash disappears" — `logView->clear()` runs unconditionally _before_ the return statement either way, so the log clears regardless of `true`/`false`. What the return value actually controls is whether the event **also propagates to other receivers** after your filter handles it (e.g., a focused widget's own `keyPressEvent`) — worth being precise about that rather than overclaiming a visible difference that isn't there in this specific single-filter setup.

```
#pragma once
#include <QWidget>

class StatusLedWidget : public QWidget {
    Q_OBJECT
public:
    enum class Status { Online, Warning, Offline };

    explicit StatusLedWidget(QWidget *parent = nullptr);
    void setStatus(Status status);
    Status status() const { return currentStatus; }

    // Exposed for the verification harness to count real paintEvent() calls
    int paintEventCount() const { return paintCount; }

protected:
    void paintEvent(QPaintEvent *event) override;
    void mousePressEvent(QMouseEvent *event) override;
    QSize sizeHint() const override;

signals:
    void clicked();
    void statusChanged(Status newStatus); // useful now that clicking
                                            // actually changes state itself

private:
    Status currentStatus = Status::Offline;
    mutable int paintCount = 0;
};
```

```
#include "statusledwidget.h"
#include <QPainter>
#include <QMouseEvent>

StatusLedWidget::StatusLedWidget(QWidget *parent) : QWidget(parent) {
    setFocusPolicy(Qt::StrongFocus);
}

void StatusLedWidget::setStatus(Status status) {
    currentStatus = status;
    update(); // schedules a repaint -- Exercise 1 asks us to OBSERVE this
               // firing paintEvent() again automatically; paintEventCount()
               // below is how the verification harness actually counts it
    emit statusChanged(status);
}

QSize StatusLedWidget::sizeHint() const { return QSize(20, 20); }

void StatusLedWidget::paintEvent(QPaintEvent * /*event*/) {
    paintCount++; // real, observable proof that update() -> paintEvent()

    QPainter painter(this);
    painter.setRenderHint(QPainter::Antialiasing);

    QColor color;
    switch (currentStatus) {
        case Status::Online:  color = QColor(46, 204, 113); break;
        case Status::Warning: color = QColor(230, 126, 34); break;
        case Status::Offline: color = QColor(149, 165, 166); break;
    }
    painter.setBrush(color);
    painter.setPen(Qt::NoPen);
    painter.drawEllipse(rect().adjusted(2, 2, -2, -2));
}

void StatusLedWidget::mousePressEvent(QMouseEvent *event) {
    if (event->button() == Qt::LeftButton) {
        // Exercise 1: cycle Online -> Warning -> Offline -> Online on
        // each click, instead of just emitting a signal for someone
        // else to react to
        switch (currentStatus) {
            case Status::Online:  setStatus(Status::Warning); break;
            case Status::Warning: setStatus(Status::Offline); break;
            case Status::Offline: setStatus(Status::Online);  break;
        }
        emit clicked();
        event->accept();
    } else {
        QWidget::mousePressEvent(event);
    }
}
```

```
#pragma once
#include <QMainWindow>
#include <QTextEdit>
#include "statusledwidget.h"

class MainWindow : public QMainWindow {
    Q_OBJECT
public:
    explicit MainWindow(QWidget *parent = nullptr);

    // Exercise 3: toggles whether the Escape branch returns true (correct,
    // consumes the event) or false (the deliberate swap) -- both are real,
    // separately testable behaviors, not just described in prose
    void setEscapeConsumesEvent(bool consumes) { escapeConsumesEvent = consumes; }

    QTextEdit *log() const { return logView; }
    StatusLedWidget *led() const { return statusLed; }
    bool isLogVisible() const { return logView->isVisible(); }

protected:
    bool eventFilter(QObject *watched, QEvent *event) override;

private:
    QTextEdit *logView;
    StatusLedWidget *statusLed;
    bool escapeConsumesEvent = true; // correct default
};
```

```
#include "mainwindow.h"
#include <QVBoxLayout>
#include <QKeyEvent>
#include <QMouseEvent>
#include <QApplication>

MainWindow::MainWindow(QWidget *parent) : QMainWindow(parent) {
    setWindowTitle("Day 4 Exercises");
    resize(400, 300);

    auto *central = new QWidget(this);
    setCentralWidget(central);
    auto *layout = new QVBoxLayout(central);

    statusLed = new StatusLedWidget(central);
    logView = new QTextEdit(central);
    logView->setReadOnly(true);
    logView->append("[12:03:41] device-01 connected");

    layout->addWidget(statusLed);
    layout->addWidget(logView);

    qApp->installEventFilter(this);
}

bool MainWindow::eventFilter(QObject *watched, QEvent *event) {
    if (event->type() == QEvent::KeyPress) {
        auto *keyEvent = static_cast<QKeyEvent *>(event);
        if (keyEvent->key() == Qt::Key_Escape) {
            logView->clear();
            // Exercise 3: this is the branch being deliberately swapped —
            // 'true' consumes the event (correct); 'false' lets it leak
            // through to whatever else was watching it too
            return escapeConsumesEvent;
        }
    }

    // Exercise 2: double-click anywhere toggles the log panel's visibility,
    // alongside the existing Escape handling -- both live in the same
    // filter, on the same qApp-wide installation
    if (event->type() == QEvent::MouseButtonDblClick) {
        logView->setVisible(!logView->isVisible());
        return true;
    }

    return QMainWindow::eventFilter(watched, event);
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
project(day04_exercises)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_AUTOMOC ON)

find_package(Qt6 REQUIRED COMPONENTS Widgets)

add_executable(day04_exercises main.cpp mainwindow.cpp mainwindow.h statusledwidget.cpp statusledwidget.h)
target_link_libraries(day04_exercises PRIVATE Qt6::Widgets)
target_compile_options(day04_exercises PRIVATE -Wall -Wextra)
```