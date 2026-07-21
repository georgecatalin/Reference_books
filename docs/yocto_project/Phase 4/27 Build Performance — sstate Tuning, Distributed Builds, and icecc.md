[[Advanced Production]]
# Day 27: Build Performance — sstate Tuning, Distributed Builds, and `icecc`

Days 2, 5, and 8 covered sstate/parallelism basics. This day is dedicated build-speed engineering — genuinely useful once your layer count and rebuild frequency grow to where a slow build meaningfully costs developer time (CI turnaround, iteration speed during active BSP/kernel work).

## Diagnosing _why_ a build is slow before optimizing blindly

The first, most important discipline: measure before tuning. BitBake gives you real data:

```bash
bitbake mqtt-monitor-image -c listtasks 2>&1 | wc -l
bitbake -e mqtt-monitor-image > /tmp/env-dump.txt   # ground truth for any variable
```

For actual timing breakdown:

```bitbake
BUILDHISTORY_COMMIT = "1"
INHERIT += "buildstats"
```

`buildstats` (a real class worth always having on) writes per-task timing data into `tmp/buildstats/` — after a build, this tells you precisely which tasks/recipes consumed the most wall-clock time, which is the actual basis for deciding what's worth optimizing rather than guessing. A common finding: one or two large recipes (webkit-adjacent Qt components, a big C++ template-heavy library, or your own `mqtt-monitor-cpp` if it has a heavy build) dominate total build time far more than the sheer recipe count would suggest — optimize the actual bottleneck, not the recipe count.

```bash
bitbake -g mqtt-monitor-image   # Day 8's dependency graph tool, doubles as "what's on the critical path"
```

## sstate mirror server — sharing cache across a whole team/CI fleet, not just your own machine

Day 2/23 covered sharing `SSTATE_DIR` across your own build directories. The natural extension: an actual **shared sstate mirror** reachable over the network, so a CI runner (or a teammate's machine) benefits from cache populated by _any_ other machine's build, not just its own history:

```bitbake
SSTATE_MIRRORS = "file://.* http://sstate.internal.example.com/sstate-cache/PATH;downloadfilename=PATH"
```

Setting up the server side is genuinely simple — sstate artifacts are just files; a plain HTTP server (nginx serving a directory populated by CI's own `SSTATE_DIR`, or actively pushed via rsync after each CI build) is sufficient. The mechanism doesn't require anything fancier than static file serving, since BitBake's signature-based lookup (Day 8) does all the "is this actually valid for what I need" work client-side.

**Practical impact**: a fresh CI runner or a new team member's first build goes from "hours, full from-scratch compile" to "minutes, mostly cache hits" — this is frequently the single highest-leverage build performance investment for any team past one person, more impactful than most of the other tuning in this day combined.

## `icecc`/`distcc` — distributing actual compilation across multiple machines

For genuinely compute-heavy builds (large kernel configs, Qt, WebKit-style dependencies — less likely to matter much for your specific stack's scale, but worth knowing), BitBake can distribute individual compile jobs across a network of build machines:

```bitbake
INHERIT += "icecc"
ICECC_PATH = "/usr/bin/icecc"
ICECC_ENV_EXEC = "${STAGING_DIR_NATIVE}${bindir_native}/icecc-create-env"
ICECC_PARALLEL_MAKE = "-j 32"
```

This requires a running `icecc` scheduler + multiple compile-node machines on your network — genuinely worth the setup investment for a team doing frequent kernel/BSP work across many recipes on modest individual hardware, but overkill for a single developer or small team where `BB_NUMBER_THREADS`/`PARALLEL_MAKE` tuning (Day 5) plus a good sstate mirror already gets you most of the available speedup. Flagging this exists and roughly what it does; genuinely setting it up is an infrastructure project of its own, not a quick config addition.

## Disk I/O — an underrated bottleneck

Yocto builds are extremely I/O-heavy (unpacking, patching, compiling, packaging thousands of small files). Practical, high-leverage guidance:

- **`tmp/` on genuinely fast storage** (NVMe SSD, not spinning disk, not a network mount) — this alone is often a larger speedup than any BitBake-level tuning.
- **Avoid building inside a VM with a slow virtualized disk backend** if avoidable — I/O virtualization overhead compounds with Yocto's already I/O-heavy nature.
- **tmpfs for `tmp/work` on RAM-rich machines** is a real, aggressive option (`TMPDIR` pointed at a tmpfs mount) — genuinely fast, but every build's intermediate state vanishes on reboot/power loss, and you need enough RAM to spare (tens of GB) — a tradeoff, not a universal recommendation.

## `BB_HASHSERVE` — the hash equivalence server (newer mechanism worth knowing about)

```bitbake
BB_HASHSERVE = "auto"
BB_SIGNATURE_HANDLER = "OEEquivHash"
```

This is a refinement of Day 8's signature mechanism — sometimes two task signatures differ (a recipe's metadata changed in a way that would normally invalidate sstate) but the _actual output_ would be byte-identical (e.g., a comment-only change to a recipe, or a variable reference that resolves the same way despite textually differing). The hash equivalence server tracks "these two different signatures are known to produce equivalent output" and lets BitBake still hit sstate cache in these cases — meaningfully reduces unnecessary rebuilds beyond what pure textual signature matching alone would achieve. `BB_HASHSERVE = "auto"` (spin up a local one automatically) is a reasonable default; a shared/persistent hash equivalence server (matching your shared sstate mirror) is the more advanced team-wide version of this.

## Practical build performance checklist, roughly ordered by leverage

1. **`DL_DIR`/`SSTATE_DIR` shared across all your local build directories** (Day 2) — baseline, should already be done.
2. **A team/CI-shared `SSTATE_MIRRORS` server** — the single highest-leverage step past one person.
3. **Fast local storage for `tmp/`** — genuinely underrated, often larger impact than BitBake config tuning.
4. **`rm_work`** (Day 20) — not a speed optimization per se, but prevents disk exhaustion from eventually forcing slow cleanup/rebuild cycles.
5. **`BB_NUMBER_THREADS`/`PARALLEL_MAKE` tuned to actual core count/RAM** (Day 5) — real but secondary to the cache-sharing wins above.
6. **`BB_HASHSERVE`** — free, low-effort, worth always having on.
7. **`icecc`/distributed compilation** — genuine infrastructure investment, appropriate once you have real bottleneck data (via `buildstats`) showing compute-bound (not I/O-bound, not cache-miss-bound) large individual recipes as the actual constraint.

Notice the ordering: most of the highest-leverage items are about **not needlessly recompiling** (cache sharing) rather than **compiling faster** (distributed compilation) — this mirrors the general software performance principle that eliminating unnecessary work usually beats optimizing the work itself, and it's worth internalizing that order of investigation rather than reaching for `icecc` first because it sounds like the "serious" solution.

## Key takeaways

- Measure before tuning — `buildstats` + `bitbake -g` tell you the actual bottleneck rather than guessing.
- A shared `SSTATE_MIRRORS` server (simple HTTP file serving) is the single highest-leverage investment past a one-person project — new machines/CI runners go from hours to minutes on first build.
- Fast local storage for `tmp/` is an underrated, often larger win than BitBake-level parallelism tuning.
- `BB_HASHSERVE`/hash equivalence catches cache-hit opportunities pure textual signature matching misses — low-effort, generally worth enabling.
- `icecc`/distributed compilation is real infrastructure investment, appropriate specifically when `buildstats` data shows genuinely compute-bound large recipes as your bottleneck — not a default first move.
- The general principle: eliminating unnecessary recompilation (cache-sharing improvements) beats making necessary compilation faster (distributed builds) as your first line of investigation.

