[[Advanced]]

## Day 28: Performance Profiling — Finding Real Bottlenecks in `mqtt_monitor`

### Concept: Profile Before You Optimize, Always — Intuition About "Slow Code" Is Often Wrong

The single most important professional habit in performance work: **measure first, then optimize the actual bottleneck** — not the part that _feels_ slow. Every prior day's performance notes (Day 9's `dataChanged` scoping, Day 22's chart point trimming, Day 23's cheap `paintEvent`) were reasonable defaults _in general_, but on your actual hardware, with your actual data volume, the real bottleneck might be somewhere you didn't expect — often it's not where intuition points. Today is about actually finding out, not guessing harder.

### Tool 1: `perf` — System-Level CPU Profiling (Linux, Works Great on ARM Too)

```bash
sudo apt install linux-tools-common linux-tools-generic

# Record CPU samples while exercising the app under realistic load
# (real or simulated MQTT/serial traffic, both views open, a chart open)
perf record -g -p $(pgrep mqtt_monitor_gui) -- sleep 30

# Generate a readable report
perf report
```

`perf report` gives you a call-graph-annotated breakdown of where CPU time is actually going, ranked by percentage. The `-g` flag captures call graphs, so you see not just "`QPainter::drawArc` is expensive" but the actual call chain leading there — critical for distinguishing "this function is inherently slow" from "this function is called far more often than it should be."

**On your Pi/BeagleBone targets specifically**: `perf` works natively on ARM Linux, and profiling _on the actual target hardware_ matters — a bottleneck that's negligible on your x86_64 dev workstation (say, `QGraphicsDropShadowEffect`'s offscreen rendering, flagged as a caution back in Day 12) can be a genuinely dominant cost on a Pi's weaker GPU/CPU. Dev-machine profiling is a useful first pass; target-hardware profiling is what actually tells you the truth for deployment.

### Tool 2: Qt Creator's Built-In Profiler (QML Profiler / Performance Analyzer)

If you're using Qt Creator as your IDE, its integrated profiler gives Qt-aware views — specifically useful for seeing signal/slot emission counts and timing directly, rather than raw stack samples you'd have to manually correlate back to Qt concepts. Worth using alongside `perf`, not instead of it — `perf` sees everything (including non-Qt code), Qt Creator's profiler is more legible for Qt-specific questions ("how many times did this signal fire in this 5-second window").

### Realistic Scenario 1: Are You Actually Over-Emitting `dataChanged`?

Day 9 flagged emitting `dataChanged` for a whole table on every update as a potential problem — today, verify whether you're actually doing this, rather than assuming Day 9's advice was followed correctly everywhere:

```cpp
// Add temporarily, directly in DeviceTableModel, to actually measure
// emission frequency under real load rather than assuming it from the code
void DeviceTableModel::upsertReading(const DeviceReading &reading) {
    static int emitCount = 0;
    static QElapsedTimer timer;
    if (!timer.isValid()) timer.start();

    // ... existing logic ...

    emitCount++;
    if (timer.elapsed() > 5000) {
        qDebug() << "dataChanged/rowsInserted emissions in last 5s:" << emitCount;
        emitCount = 0;
        timer.restart();
    }
}
```

Run this under real MQTT/serial load and check the actual number against your expected message rate — if you have 10 devices publishing once per second, you'd expect roughly 10 emissions/5s = 50 in that window; a number wildly higher suggests something (perhaps a bug, perhaps unintentional duplicate wiring) is causing extra updates.

### Realistic Scenario 2: Is the Grid View (`DeviceCard`s) Actually the Bottleneck, or Is It the Table?

A genuinely common surprise: people assume the custom-painted grid (`DeviceCard` + `StatusLedWidget`, Days 4/11) is more expensive than the standard `QTableView`, and it's sometimes the _opposite_ — a `QTableView` re-laying-out and repainting many cells on frequent `dataChanged` emissions can cost more than a handful of composite widgets that only repaint themselves individually. **You genuinely don't know until you profile both under the same load and compare.**

```bash
# Practical A/B test: profile with only the table view visible, then
# only the grid view visible (Day 15's QStackedWidget makes this trivial —
# just toggle it), same data load both times, compare perf report output
perf record -g -p $(pgrep mqtt_monitor_gui) -- sleep 15  # table view active
perf record -g -p $(pgrep mqtt_monitor_gui) -o grid.data -- sleep 15  # grid view active
perf report -i grid.data
```

### Realistic Scenario 3: SQLite Write Latency — Verifying Day 20's Batching Actually Helped

Don't just assume the batching change from Day 20 helped — measure the actual write latency before and after:

```cpp
// Temporarily added to PersistenceWorker::flushBatch()
QElapsedTimer flushTimer;
flushTimer.start();
db.transaction();
// ... existing batch insert loop ...
db.commit();
qDebug() << "Batch flush of" << pendingBatch.size() << "rows took" << flushTimer.elapsed() << "ms";
```

On your dev machine's SSD, batching might show a modest improvement. **On an actual SD-card-backed Pi, run this same instrumented build on the target hardware** — the difference between per-row commits and batched commits is very likely to be dramatically more pronounced there, which is exactly the point Day 20 was making, now backed by your own measured numbers instead of taken on faith.

### Realistic Scenario 4: Signal/Slot Overhead at Genuinely High Scale

Signal/slot dispatch has real (small) overhead per emission — usually irrelevant, but worth understanding when it stops being irrelevant. If you had, hypothetically, hundreds of devices each publishing multiple times per second, the _cumulative_ signal dispatch cost (queued cross-thread delivery involves event-loop posting, not a free function call) could become measurable. The practical mitigation, if you ever actually hit this scale: **batch multiple readings into one signal emission** rather than one signal per reading:

```cpp
// Instead of emit readingReady() once per line:
signals:
    void readingsBatchReady(QList<QPair<QString, double>> readings);

// Worker accumulates a small batch (e.g., every 100ms or every N readings)
// and emits once, rather than emitting for every single individual reading —
// trades a small amount of latency for a real reduction in cross-thread
// signal dispatch overhead at high message volume
```

**This is genuinely premature for `mqtt_monitor`'s realistic scale** (a monitoring dashboard for a reasonable number of embedded devices is very unlikely to hit signal-dispatch-overhead as an actual bottleneck) — included here so you recognize the pattern and the threshold at which it becomes worth reaching for, rather than reaching for it preemptively without evidence it's needed.

### The Actual Professional Workflow, Stated Plainly

1. Define what "slow" means concretely (dropped frames during rapid updates? CPU pegged on the Pi? SQLite writes visibly lagging behind incoming data?) — vague "make it faster" isn't profilable.
2. Profile under realistic load, on the actual target hardware if the concern is embedded-specific.
3. Identify the actual top consumer from the profile — not the part you assumed.
4. Fix that one thing.
5. **Re-profile to confirm the fix actually helped** — a genuinely common mistake is assuming a change helped because it seems like it should, without re-measuring. Sometimes a "fix" makes no measurable difference, or even regresses something else.
6. Repeat only if the original "slow" symptom is still present.

### Why This Matters

- **Profiling on target hardware (Pi/BeagleBone), not just your dev workstation**, is the difference between "performance work that's actually relevant to deployment" and "performance work that optimizes for a machine nobody will actually run the app on." Your dev workstation's CPU/GPU headroom can hide real problems that only surface on the actual embedded target.
- **The instinct that custom-painted widgets are automatically more expensive than standard views is often wrong** — measured comparison, not intuition, is what should drive a decision between the table view and grid view approaches if you ever needed to choose one over the other for a resource-constrained deployment.
- **Re-profiling after a fix is the step people skip most often** — "I made the change that should help" is not the same claim as "I measured that it helped," and shipping unverified performance changes is a real professional risk (you might've fixed nothing, or worse, traded one bottleneck for a different one).
- **Signal/slot overhead is real but has a genuinely high threshold before it matters** — knowing roughly where that threshold sits (hundreds of high-frequency emissions, not the tens/dozens realistic for `mqtt_monitor`) prevents you from prematurely optimizing something that was never actually a problem at your real scale.

### Exercise

1. Run `perf record`/`perf report` against your fully-integrated Day 24 build under realistic simulated load (multiple MQTT publishers via a script, or `socat` serial simulation), on your dev machine first, and identify the actual top 3 CPU consumers from the report — compare against what you would have guessed before looking.
2. If you have Pi/BeagleBone hardware available, repeat the same profiling run on-device, and compare the top consumers list against your dev-machine results — note any genuine surprises (something cheap on x86_64 that's expensive on ARM, or vice versa).
3. Pick one measured (not assumed) bottleneck from your own profiling data, apply a targeted fix, and re-profile to confirm — writing down the before/after numbers, not just "seems smoother." This is the actual discipline that separates real performance engineering from guesswork, and it's worth practicing deliberately at least once even if `mqtt_monitor` doesn't currently have a severe bottleneck to fix.

### Key Takeaways

- Always profile before optimizing — intuition about what's "slow" is frequently wrong, and fixing the wrong thing wastes effort while leaving the actual bottleneck untouched.
- `perf record -g` + `perf report` is your primary Linux CPU profiling tool, and it works natively on ARM — profile on actual target hardware when the concern is embedded-deployment-specific, not just on your dev workstation.
- Verify assumptions from earlier days (Day 9's emission scoping, Day 20's batching benefit) with actual measurements rather than trusting the reasoning alone — the reasoning tells you what to check, not that it's already true in your specific build.
- Re-profile after every fix to confirm it actually helped — this step is the one most often skipped, and skipping it means shipping unverified changes.
- Signal/slot dispatch overhead is real but has a high practical threshold — not a concern at `mqtt_monitor`'s realistic device/message scale, worth knowing about for when (if ever) it would become one.

---

Say "next" for Day 29 (Qt's plugin architecture — `QPluginLoader` and a simple plugin interface, useful if you ever want third-party or optional device-type support in `mqtt_monitor` without recompiling the core app; I'll keep this appropriately scoped given it's a more speculative/optional feature for your project).