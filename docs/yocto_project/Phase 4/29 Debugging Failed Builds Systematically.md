[[Advanced Production]]
# Day 29: Debugging Failed Builds Systematically

Every previous day has used individual debugging tools in context (`devshell` on Day 4/10, `bitbake -e` on Day 8/9, `bitbake-diffsigs` on Day 8, `kernel_configcheck` on Day 16). This day assembles them into one coherent triage methodology — the actual decision process for "build failed, now what," rather than a grab-bag of tools.

## The first question: what phase failed?

Before touching any specific tool, classify the failure — this determines which entire toolset is even relevant:

```
Parse error (happens in seconds, before any task execution)
   → recipe syntax, missing LICENSE, layer conflict, bad override syntax
Fetch failure
   → network, checksum mismatch, SRCREV not found, proxy config
Configure failure
   → missing DEPENDS, wrong PACKAGECONFIG, cross-compile toolchain issue
Compile failure
   → actual source code bug, missing header (still often a DEPENDS issue), ABI mismatch
Install failure
   → do_install script bug, wrong paths, permissions
Package/rootfs failure
   → FILES misconfiguration, RDEPENDS unsatisfiable, PACKAGE_EXCLUDE conflict
```

BitBake's own error output names the failing task explicitly (`ERROR: Task (.../mosquitto_2.0.18.bb:do_compile) failed`) — read this line first, always, before doing anything else. It tells you which phase, which immediately narrows which of the tool categories below actually applies.

## `bitbake -D` — verbose debug output, and when it's actually useful

```bash
bitbake mqtt-monitor -D -D -D
```

Each `-D` increases verbosity (up to `-DDD` typically useful; beyond that, output volume usually exceeds signal). This surfaces BitBake's own internal decision-making — which tasks it's considering, why a particular sstate cache entry was or wasn't reused, dependency resolution steps. **Practical guidance**: `-D` is for _BitBake-level_ mysteries (why didn't this task rebuild, why is this dependency being pulled in) — it is generally _not_ the right tool for a straightforward compile error, where the actual `log.do_compile` file (below) is far more directly useful and far less noisy. Reach for `-D` when the confusion is "why did BitBake decide to do X" rather than "why did the compiler fail."

## The log files — always the first concrete artifact to check

```bash
bitbake mqtt-monitor -c compile 2>&1 | tail -50
```

BitBake's own failure output tells you the exact log path:

```
ERROR: Logfile of failure stored in: /home/george/poky/build-qemu/tmp/work/cortexa53-poky-linux/mqtt-monitor/1.2.0/temp/log.do_compile
```

```bash
cat tmp/work/*/mqtt-monitor/*/temp/log.do_compile | tail -100
```

**Read from the bottom up, not top down** — the actual fatal error is almost always near the end; a wall of preceding warnings is usually noise (deprecation warnings, informational compiler notes) that people waste time reading through before reaching the real problem. This is a simple habit that saves real time across hundreds of debugging sessions.

## Devshell — the escalation point for compile/configure failures

Covered extensively (Days 4, 10, 11) — worth stating as the actual decision rule now: **any time a log file's error message is ambiguous or you need to test a hypothesis interactively** (does this header actually exist in the sysroot? does manually adding this flag fix it? is this actually the compiler being invoked or a wrapper script issue?), stop reading logs and drop into `devshell` instead:

```bash
bitbake mqtt-monitor -c devshell
```

The single most common mistake at this stage: forgetting you're in a **cross-compilation environment**, not your host shell. Running `which gcc` inside devshell shows your host compiler, but `$CC` is the actual cross-compiler BitBake will use — always test with the variables, not assumed familiar commands.

## `bitbake -e` — for configuration-shaped confusion, not compile errors

Reiterating Day 8/9's guidance as part of the systematic method: if the failure is "wrong file installed," "wrong flag passed," "override didn't apply" — this is a **variable resolution** problem, and `bitbake -e` is strictly more reliable than reading recipe/class source and mentally tracing inheritance:

```bash
bitbake -e mqtt-monitor | grep ^EXTRA_OECMAKE=
bitbake -e mqtt-monitor | grep ^DEPENDS=
bitbake -e mqtt-monitor | grep ^FILES:${PN}=
```

## `do_rootfs` failures — reading package manager errors correctly

Per Day 6/20: this failure category has a distinct signature — an actual dependency-resolution error message from `opkg`/`rpm`, not a compile error:

```
Collected errors:
 * satisfy_dependencies_for: Cannot satisfy the following dependencies for mqtt-monitor-camera-plugin:
 *     libcamera-dev *
```

Read this literally as a package manager telling you it can't find something — the fix is almost always: the missing package's recipe doesn't exist in any active layer (`bitbake-layers show-recipes libcamera-dev`), or a `PACKAGECONFIG`/`DEPENDS` typo produced a package name that doesn't actually exist.

## `bitbake-diffsigs`/`bitbake -S` — for "why did this rebuild when I didn't expect it to"

Day 8's tool, now placed in the systematic flow: this is specifically for **unexpected rebuild scope**, not build failures per se — but it's a debugging category worth including here since "the build succeeded but took way longer / rebuilt way more than expected" is a real, common variant of "something's wrong" that isn't a hard failure.

```bash
bitbake -S none mqtt-monitor-image      # compute/report signatures without building
bitbake-diffsigs -t mosquitto do_compile
```

## Patch application failures — a distinct, common category

```
ERROR: mosquitto-2.0.18: Fetcher failure: Unable to apply patch...
Patch failed! Please fix... and then run 'quilt refresh'.
```

This means a `.patch` file (Day 12) no longer applies cleanly — usually because upstream source changed (version bump) in a way that conflicts with your patch's context lines. Recovery path:

```bash
bitbake mqtt-monitor -c patch -f
cd tmp/work/*/mqtt-monitor/*/git
# quilt is often already set up here for git-unaware patch application; check
quilt push -f    # force-apply to see exactly where it diverges
# manually resolve, then regenerate the patch — or better, use devtool upgrade (Day 21) instead of manual patch surgery
```

**Practical guidance**: manual patch-conflict resolution (shown above) is the mechanical fallback, but per Day 21, `devtool upgrade` is almost always the better path when this happens as part of a version bump — it handles the "reapply existing patches to new source, flag conflicts" workflow far more cleanly than manual `quilt` wrangling.

## The "it works on QEMU but not on real hardware" category — a distinct debugging path entirely

Worth flagging as genuinely different from everything above: if BitBake succeeds and QEMU boots fine, but real hardware doesn't, the problem is very likely **not** a Yocto/BitBake issue at all — it's Day 17's boot-chain diagnosis (UART console output, which stage stopped) or Day 18's device tree issue (peripheral not enabled/wrong `compatible` string). Don't keep re-running `bitbake` hoping for a different result when the actual build succeeded — the investigation needs to move to the hardware/serial-console domain entirely, per Day 17's methodology.

## A complete triage flowchart, assembled

```
Build failed
├── Failed in seconds (parse time)?
│   → check LICENSE/LIC_FILES_CHKSUM, layer.conf syntax, override syntax typos
├── Failed during fetch?
│   → check SRCREV validity, checksum correctness, network/proxy, BB_NO_NETWORK
├── Failed during configure/compile?
│   → read log.do_compile bottom-up FIRST
│   → ambiguous? → devshell, test hypothesis interactively with real $CC/$CFLAGS
│   → suspect wrong variable/flag? → bitbake -e, grep the specific variable
├── Failed during install?
│   → check do_install script logic, paths use ${D}/${bindir} etc. correctly
├── Failed during do_rootfs?
│   → read as a package-manager dependency error, literally
│   → bitbake-layers show-recipes <missing-thing>
├── Succeeded but rebuilt way more than expected?
│   → bitbake -S / bitbake-diffsigs, find what actually changed
├── Patch application failure?
│   → devtool upgrade (preferred) or manual quilt resolution (fallback)
└── Succeeded, boots in QEMU, fails on real hardware?
    → this isn't a BitBake problem anymore — Day 17 (boot chain/UART) or Day 18 (device tree)
```

## Key takeaways

- Classify the failure phase first (parse/fetch/configure/compile/install/package) — this determines which entire toolset is relevant, before touching any specific command.
- Read `log.do_<task>` bottom-up — the real error is at the end; don't wade through preceding warning noise top-down.
- `devshell` for interactive hypothesis-testing on ambiguous compile/configure errors; `bitbake -e` for configuration/variable-resolution confusion; these solve genuinely different categories of problem, not interchangeable first resorts.
- `do_rootfs` failures are literal package-manager dependency errors — read them as such, don't treat them like compile errors.
- `devtool upgrade` is almost always better than manual `quilt`-based patch conflict resolution for version-bump-triggered patch failures.
- "Works in QEMU, fails on real hardware" is categorically not a BitBake debugging problem anymore — the investigation moves entirely to Day 17/18's boot-chain and device-tree domain.
- `-D` verbosity is for BitBake's own decision-making mysteries, not a first-resort for straightforward compile errors — it's frequently the wrong tool reached for too early.
