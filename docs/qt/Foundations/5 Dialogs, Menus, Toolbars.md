[[Foundations]]

## Day 5: Dialogs, Menus, Toolbars, and `QAction` — Building Real Application Chrome

### Concept: `QAction` Is the Unifying Abstraction You Need to Know First

Beginners often build menus and toolbars as separate, disconnected things — a menu item here, a toolbar button there, each with its own `connect()` call, duplicating logic. Qt's actual idiom is: **you create one `QAction`, and attach it to as many places as you want** (menu, toolbar, keyboard shortcut) — they all stay in sync automatically. Disable the action once, and the menu item grays out _and_ the toolbar button grays out _and_ the shortcut stops firing — all from one line of code.

This is the correct mental model: `QAction` represents "a thing the user can do," independent of _how_ they trigger it.

### Annotated Code: Menus, Toolbar, and Actions Wired Together

`mainwindow.h` additions:

```cpp
private:
    void createActions();
    void createMenus();
    void createToolbar();
    void showConnectionDialog();
    void showAboutDialog();

    QAction *connectAction;
    QAction *disconnectAction;
    QAction *exitAction;
    QAction *aboutAction;
    QAction *toggleLogAction;
```

`mainwindow.cpp` additions (call these three from the constructor, after panel setup):

```cpp
#include <QMenuBar>
#include <QToolBar>
#include <QAction>
#include <QMessageBox>
#include <QStyle>

void MainWindow::createActions() {
    // QAction owns no UI by itself — it's pure "intent + metadata"
    connectAction = new QAction("&Connect...", this);
    connectAction->setShortcut(QKeySequence("Ctrl+K"));
    connectAction->setStatusTip("Connect to MQTT broker");
    connect(connectAction, &QAction::triggered, this, &MainWindow::showConnectionDialog);

    disconnectAction = new QAction("&Disconnect", this);
    disconnectAction->setEnabled(false); // starts disabled — no connection yet
    connect(disconnectAction, &QAction::triggered, this, [this]() {
        connectionIndicator->setText("● Disconnected");
        connectionIndicator->setStyleSheet("color: red; font-weight: bold;");
        disconnectAction->setEnabled(false);
        connectAction->setEnabled(true);
    });

    exitAction = new QAction("E&xit", this);
    exitAction->setShortcut(QKeySequence::Quit); // platform-correct shortcut
    connect(exitAction, &QAction::triggered, this, &QWidget::close);

    // Checkable action — toggles state, useful for view visibility toggles
    toggleLogAction = new QAction("Show &Log Panel", this);
    toggleLogAction->setCheckable(true);
    toggleLogAction->setChecked(true);
    connect(toggleLogAction, &QAction::toggled, this, [this](bool checked) {
        logView->setVisible(checked);
    });

    aboutAction = new QAction("&About", this);
    connect(aboutAction, &QAction::triggered, this, &MainWindow::showAboutDialog);

    // Using a standard icon from the current style, rather than shipping
    // a custom asset for something generic — cheap and theme-consistent
    connectAction->setIcon(style()->standardIcon(QStyle::SP_DialogYesButton));
    disconnectAction->setIcon(style()->standardIcon(QStyle::SP_DialogNoButton));
}

void MainWindow::createMenus() {
    QMenu *fileMenu = menuBar()->addMenu("&File");
    fileMenu->addAction(connectAction);
    fileMenu->addAction(disconnectAction);
    fileMenu->addSeparator();
    fileMenu->addAction(exitAction);

    QMenu *viewMenu = menuBar()->addMenu("&View");
    viewMenu->addAction(toggleLogAction);

    QMenu *helpMenu = menuBar()->addMenu("&Help");
    helpMenu->addAction(aboutAction);
}

void MainWindow::createToolbar() {
    QToolBar *toolbar = addToolBar("Main Toolbar");
    toolbar->setToolButtonStyle(Qt::ToolButtonTextBesideIcon);
    toolbar->addAction(connectAction);
    toolbar->addAction(disconnectAction);
    toolbar->addSeparator();
    toolbar->addAction(toggleLogAction);
}
```

Call these in the constructor:

```cpp
createActions();
createMenus();
createToolbar();
```

### Annotated Code: Modal Dialog (Connection Settings)

This is the standard pattern for a "settings" or "input" dialog — modal, blocking, returns accept/reject.

`connectiondialog.h`:

```cpp
#pragma once
#include <QDialog>
#include <QLineEdit>
#include <QSpinBox>

class ConnectionDialog : public QDialog {
    Q_OBJECT
public:
    explicit ConnectionDialog(QWidget *parent = nullptr);

    // Accessors read after exec() returns QDialog::Accepted
    QString brokerHost() const { return hostEdit->text(); }
    int brokerPort() const { return portSpin->value(); }
    QString clientId() const { return clientIdEdit->text(); }

private:
    QLineEdit *hostEdit;
    QSpinBox *portSpin;
    QLineEdit *clientIdEdit;
};
```

`connectiondialog.cpp`:

```cpp
#include "connectiondialog.h"
#include <QFormLayout>
#include <QDialogButtonBox>
#include <QVBoxLayout>

ConnectionDialog::ConnectionDialog(QWidget *parent) : QDialog(parent) {
    setWindowTitle("MQTT Broker Connection");
    setModal(true); // redundant with exec(), but explicit is good practice

    auto *form = new QFormLayout();
    hostEdit = new QLineEdit("localhost", this);
    portSpin = new QSpinBox(this);
    portSpin->setRange(1, 65535);
    portSpin->setValue(1883); // default mosquitto port
    clientIdEdit = new QLineEdit("mqtt_monitor_gui", this);

    form->addRow("Broker Host:", hostEdit);
    form->addRow("Port:", portSpin);
    form->addRow("Client ID:", clientIdEdit);

    // QDialogButtonBox gives you platform-correct OK/Cancel button
    // ordering and wiring for free — never hand-build this.
    auto *buttons = new QDialogButtonBox(
        QDialogButtonBox::Ok | QDialogButtonBox::Cancel, this);
    connect(buttons, &QDialogButtonBox::accepted, this, &QDialog::accept);
    connect(buttons, &QDialogButtonBox::rejected, this, &QDialog::reject);

    auto *layout = new QVBoxLayout(this);
    layout->addLayout(form);
    layout->addWidget(buttons);
}
```

Using it in `mainwindow.cpp`:

```cpp
#include "connectiondialog.h"

void MainWindow::showConnectionDialog() {
    ConnectionDialog dialog(this); // stack-allocated — fine for modal dialogs

    // exec() blocks here until the user closes the dialog.
    // This is a nested event loop — legal, but note it's the one
    // deliberate exception to "only one event loop" thinking.
    if (dialog.exec() == QDialog::Accepted) {
        QString host = dialog.brokerHost();
        int port = dialog.brokerPort();

        logView->append(QString("[CONFIG] Connecting to %1:%2").arg(host).arg(port));
        connectionIndicator->setText("● Connected");
        connectionIndicator->setStyleSheet("color: green; font-weight: bold;");
        connectAction->setEnabled(false);
        disconnectAction->setEnabled(true);
    }
    // If Rejected (Cancel or X button), we do nothing — dialog destructs
    // when it goes out of scope, no manual cleanup needed.
}

void MainWindow::showAboutDialog() {
    // QMessageBox::about is a convenience static function for the
    // extremely common "simple informational modal" case
    QMessageBox::about(this, "About mqtt_monitor",
        "mqtt_monitor GUI\nDevice monitoring dashboard\nBuilt with Qt6");
}
```

### Modal vs. Modeless — The Distinction That Matters

- **Modal** (`dialog.exec()`): blocks input to the rest of the app until closed. Correct for "must decide before continuing" — connection settings, confirmations, blocking forms.
- **Modeless** (`dialog.show()`): non-blocking, user can interact with both the dialog and the main window. Correct for tool palettes, find/replace bars, live-updating inspector panels.

A modeless dialog needs `Qt::WA_DeleteOnClose` set (`dialog->setAttribute(Qt::WA_DeleteOnClose)`) if you allocate it with `new` — otherwise you leak it, since nothing will `delete` it for you when the user closes it (no `exec()` to return from and clean up).

### Why This Matters

- `QAction` centralizing enable/disable/checked state is what makes real applications feel consistent — you set state once, not in three places.
- `QDialogButtonBox` handles OS-specific button ordering (OK/Cancel order differs between Windows and macOS conventions) — never hand-roll this.
- `exec()` on a modal dialog runs a **nested event loop** — the only sanctioned exception to the "one event loop per app" model from Day 1. Useful to know when debugging odd reentrancy issues later.
- Standard icons via `style()->standardIcon()` keep your app visually consistent with the OS theme without asset management overhead.

### Exercise

1. Add a "Clear Log" `QAction` with shortcut `Ctrl+L`, wired to both the File menu and the toolbar, that calls `logView->clear()`.
2. Convert `showAboutDialog` into your own custom modal `QDialog` subclass instead of using `QMessageBox::about` — include an app icon (`QLabel` with `QPixmap`) and a version string, laid out with `QHBoxLayout`.
3. Build a **modeless** "Live Stats" dialog: a small always-visible window with a `QLabel` counter that increments once per second via `QTimer`, opened via a new toolbar action, correctly using `Qt::WA_DeleteOnClose` and verified under AddressSanitizer that closing and reopening it repeatedly doesn't leak.

### Key Takeaways

- `QAction` is the single source of truth for "a thing the user can do" — attach one action to a menu item, toolbar button, and shortcut simultaneously; state changes propagate everywhere automatically.
- `QDialogButtonBox` is the correct, portable way to build OK/Cancel (or similar) button rows — never hand-build button ordering.
- Modal dialogs (`exec()`) block and run a nested event loop; modeless dialogs (`show()`) don't block and need `Qt::WA_DeleteOnClose` if heap-allocated.
- Toolbars and menus are just _views_ onto `QAction` objects — the action itself holds the real state and logic.

---

Say "next" for Day 6 (QSS styling — theming your app properly, the CSS-like subset Qt supports, its real limitations vs. actual CSS, and building a consistent dark-mode dashboard look).