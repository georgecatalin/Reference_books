[[Advanced Qt Quick]]
# Day 26 — Testing QML: Qt Quick Test

Your C++ and Python capstones both had real test suites (GoogleTest, pytest). The QML layer has been untested by comparison — reasonable during rapid UI iteration, but a genuine gap for anything you intend to maintain long-term. Today: Qt Quick Test, QML's native testing framework, applied pragmatically — testing the logic worth testing, not chasing 100% UI coverage for its own sake.

## Concept: What's actually worth testing in a QML layer

Be honest about this before writing any test: **most of your QML is declarative bindings and layout — genuinely low-value to unit test** (testing that `anchors.fill: parent` fills the parent is testing the framework, not your code). What _is_ worth testing:

- Custom logic in components (the `formatUptime`/`signalQuality` functions from Day 5)
- State transitions and their resulting property values (Day 7's states, Day 21's state machine's QML-facing surface)
- Signal emission — does clicking actually emit the right signal with the right arguments
- Model behavior as consumed from QML (though Day 14's `DeviceListModel` itself is better tested via GoogleTest on the C++ side — test it once, at the layer where the logic actually lives)

**This mirrors Day 5's "where does logic belong" lesson exactly**: if you moved complex logic to C++ like you were told to, there's genuinely less QML-side logic left to test — which is itself evidence the architecture is right, not a gap.

## Concept: Qt Quick Test setup

```cmake
find_package(Qt6 REQUIRED COMPONENTS QuickTest)

qt_add_executable(tst_devicerow tst_devicerow.cpp)
target_link_libraries(tst_devicerow PRIVATE Qt6::QuickTest)

# Copies your test .qml files alongside the test binary
qt_add_qml_module(tst_devicerow
    URI DeviceRowTests
    QML_FILES tst_devicerow.qml
)
```

```cpp
// tst_devicerow.cpp — genuinely this short, the framework does the rest
#include <QtQuickTest/quicktest.h>
QUICK_TEST_MAIN(devicerow)
```

## Concept: Writing a test — `TestCase` in QML itself

```qml
// tst_devicerow.qml
import QtQuick
import QtTest
import MonitorApp

TestCase {
    name: "DeviceRowTests"

    Component {
        id: deviceRowComponent
        DeviceRow {
            deviceId: "test-device"
            rssi: -60
            online: true
        }
    }

    property var row: null

    function init() {
        row = createTemporaryObject(deviceRowComponent, null)
    }

    function test_emitsDeviceSelectedOnClick() {
        let signalSpy = signalSpy_helper()   // see note below on SignalSpy
        row.deviceSelected.connect(function(id) {
            compare(id, "test-device")
        })
        mouseClick(row)
    }

    function test_colorReflectsOnlineState() {
        compare(row.online, true)
        row.online = false
        wait(300)   // allow the Behavior/ColorAnimation from Day 7 to settle
        compare(row.online, false)
    }
}
```

**`createTemporaryObject`** (not plain component instantiation) is the correct pattern here — it automatically destroys the created object at the end of each test function, preventing one test's leftover object from bleeding state into the next. `init()` runs before every `test_*` function, giving you a genuinely fresh object each time — same discipline as a `setUp()` in pytest or a GoogleTest fixture, just QML's naming convention for it.

## Concept: `SignalSpy` — properly asserting a signal fired with the right arguments

```qml
import QtQuick
import QtTest
import QtQml

TestCase {
    name: "DeviceRowSignalTests"

    Component { id: deviceRowComponent; DeviceRow { deviceId: "esp32-04" } }

    function test_deviceSelectedSignalFiresWithCorrectId() {
        let row = createTemporaryObject(deviceRowComponent, null)
        let spy = signalSpy.createObject(null, { target: row, signalName: "deviceSelected" })

        mouseClick(row)

        compare(spy.count, 1)
        compare(spy.signalArguments[0][0], "esp32-04")
    }

    SignalSpy {
        id: signalSpy
    }
}
```

`SignalSpy` records every emission of a target signal — `spy.count` tells you _how many times_ it fired (catching both "never fired" and "fired twice by accident" bugs — the latter being a real, easy-to-introduce bug if a click handler is accidentally wired twice), and `spy.signalArguments[n]` gives you the exact arguments of the nth emission, letting you assert not just "something happened" but "the right thing happened with the right data."

## Concept: Testing a state machine's QML-facing surface

Tying back to Day 21 — even though the _logic_ lives in C++ (and is GoogleTest-covered there), it's worth a lighter QML-side test confirming the property genuinely updates as QML observes it, since that's the actual contract QML code depends on:

```qml
TestCase {
    name: "ConnectionStateMachineQmlSurface"

    function test_currentStateReflectsConnectRequest() {
        compare(ConnectionStateMachine.currentState, "disconnected")
        ConnectionStateMachine.requestConnect()
        wait(50)   // allow the state machine's queued signal/slot dispatch to settle
        compare(ConnectionStateMachine.currentState, "connecting")
    }
}
```

This is deliberately a _thin_ test — it doesn't re-verify the full retry/backoff logic (that's GoogleTest's job on the C++ side, Day 21's exercise 3), it verifies the specific thing QML actually depends on: that the property updates and is observable. Testing the same logic twice at two layers is redundant effort; testing the _boundary_ between them once at each layer is the correct division.

## Concept: Running the tests

```bash
cmake --build build --target tst_devicerow
./build/tst_devicerow
# Or integrate into CTest, alongside your existing GoogleTest suite:
ctest --output-on-failure
```

`ctest` running both your GoogleTest C++ suite and your Qt Quick Test QML suite side by side, in one command, is worth setting up now — one CI pipeline (relevant since you already use GitHub Actions from your Python course) covering both layers, rather than two disconnected test invocations someone has to remember to run separately.

## Exercise

1. Set up the Qt Quick Test scaffolding (`tst_devicerow.cpp` + `.qml`) and write tests for `DeviceRow`: signal emission with correct arguments (via `SignalSpy`), and that `online` toggling produces the expected `color` after the `Behavior` settles (`wait()` appropriately).
2. Write a test for the `formatUptime`/`signalQuality` JS functions from Day 5 — confirm boundary cases explicitly (exactly 0 seconds, exactly -60 dBm at the RSSI threshold boundary) since boundary conditions are exactly where these small functions are most likely to have an off-by-one.
3. Add the thin `ConnectionStateMachine` QML-surface test above, and separately confirm (by checking your Day 21 exercise) that the _actual_ retry/backoff logic has real GoogleTest coverage on the C++ side — if it doesn't yet, add it now; this is the moment that gap becomes visible.
4. Wire `ctest` to run both your existing GoogleTest suite and this new Qt Quick Test suite in one invocation, and add both to your GitHub Actions workflow from the Python/Docker courses if you're running CI on this project.

## Key takeaways

- Most declarative QML (bindings, layout) isn't worth unit testing directly — focus effort on custom JS logic, signal emission correctness, and the QML-facing surface of C++ logic.
- `createTemporaryObject` (not manual instantiation) ensures each test gets a fresh object and cleans up automatically — the QML equivalent of a test fixture's setup/teardown.
- `SignalSpy` asserts both _how many times_ a signal fired and _what arguments_ it carried — catching both silent failures and accidental double-firing.
- When logic exists in C++ and is exposed to QML (Day 21's state machine), test the actual logic once in GoogleTest and test only the QML-facing property/signal surface in Qt Quick Test — don't duplicate coverage across layers.
- `ctest` can run both suites together — worth wiring into the same CI pipeline you already have from your Python/Docker courses rather than treating QML tests as a separate, easily-forgotten process.
