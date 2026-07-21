[[Core Execution Mechanics]]

# Day 8: BitBake Task Execution Model — Dependency Graphs, `addtask`, and sstate

Day 4 gave you the task pipeline as a fixed sequence. That was a simplification. The real model: tasks form a **directed dependency graph**, not a linear list, and BitBake computes execution order dynamically from declared dependencies. Understanding this precisely is what lets you actually debug why a task ran (or didn't run) when you expected the opposite.

## How tasks are actually declared

Every task is a shell or Python function with a `do_` prefix, registered via `addtask`:

```bitbake
addtask compile after do_configure before do_install
```

This is the raw mechanism — but you rarely write `addtask` directly, because classes like `autotools`, `cmake`, `image` already declare the standard tasks and their ordering. You mostly interact with the _dependency flags_ on tasks, not `addtask` itself.

## The two dependency flag types that actually matter

```bitbake
do_compile[depends] += "zlib:do_populate_sysroot"
do_configure[deptask] = "do_populate_sysroot"
```

- **`[depends]`** — explicit task-to-task dependency across recipes: "before _this_ task in _this_ recipe runs, task X in recipe Y must have completed." This is what `DEPENDS` (Day 4) actually compiles down to under the hood — `DEPENDS = "zlib"` expands into dependency flags roughly equivalent to the line above, ensuring zlib's sysroot is populated before your `do_configure`/`do_compile` runs.
- **`[deptask]`** — a shorthand meaning "for every recipe _this_ recipe DEPENDS on, also wait for their `do_X` task specifically" — used less often directly; mostly you rely on `DEPENDS`/`RDEPENDS` and let BitBake generate the underlying flags.

**Practical implication**: when you see a build fail with a missing header or symbol, the actual root cause is almost always "the dependency graph doesn't include an edge that should be there" — i.e., you forgot to declare `DEPENDS`, not that some invisible ordering bug occurred. BitBake's scheduler is deterministic and correct given the graph you provide; the graph is usually where the bug lives.

## Task signatures — why BitBake knows what to rebuild

This is the mechanism that makes incremental builds fast and correct, and it's worth understanding exactly, since it explains sstate behavior that otherwise looks like "magic caching."

Every task has a computed **signature** — a hash derived from:

1. The task's own function body (shell/Python code)
2. Values of every variable that function references
3. The signatures of all tasks it depends on (transitively)

If any input to this hash changes — you edit the recipe, change a variable, or a dependency's own signature changes — the signature changes, and BitBake knows this task (and everything depending on it) must re-run. If the signature is unchanged, BitBake can pull the task's previous output straight from **sstate cache** instead of re-executing it.

```bash
bitbake-diffsigs -t hello-monitor do_compile
```

This tool tells you _exactly what changed_ between two signature versions of a task — invaluable when you're confused why something you thought was unrelated triggered a rebuild. `bitbake -S` (signature comparison mode) and `bitbake-dumpsig` are the deeper tools here, but `bitbake-diffsigs` is what you'll reach for 90% of the time in practice.

## sstate cache — precisely what it stores and restores

A common misconception: sstate is not "cached source code" or "a ccache-like compiler cache." It's **per-task output artifacts**, keyed by the task signature above. For `do_populate_sysroot`, that's staged headers/libs. For `do_package`, that's the split package outputs. Each task that matters for reuse (`do_compile` is often _not_ directly sstate-cached in a way that skips re-linking — it's typically `do_populate_sysroot` and `do_package`/`do_package_write_*` that give you the big wins) gets a compressed tarball in `SSTATE_DIR`, named by signature hash.

Why this matters practically: if you have two build directories (`build-qemu` and `build-rpi4`) sharing the same `SSTATE_DIR`, and both need to build `zlib` at the same version with the same configuration, the **second** build directory gets `zlib`'s sysroot output for free from cache — no recompilation — because the task signature matches regardless of which build directory triggered it first. This is why "always share `SSTATE_DIR` across build directories" (Day 2/5) isn't just a convenience tip — it's the mechanism that makes multi-machine, multi-product builds practical instead of each one paying full compile cost independently.

## `bitbake -c cleansstate` vs `bitbake -c clean` — precise difference

```bash
bitbake hello-monitor -c clean          # removes this recipe's WORKDIR output — forces re-run of tasks, but sstate cache entries remain
bitbake hello-monitor -c cleansstate    # ALSO removes this recipe's sstate cache entries — genuinely forces full rebuild from scratch
```

`clean` alone is often _not enough_ to force a true rebuild if sstate still has a valid matching signature — BitBake will just repopulate from cache. If you're debugging something where you suspect stale cache is masking a real change (rare, but happens with certain non-declarative build systems that write timestamps or absolate paths into outputs), `cleansstate` is the heavier hammer.

## Visualizing the dependency graph for real

```bash
bitbake -g hello-monitor
```

Generates `task-depends.dot` (and `pn-depends.dot`, `pn-buildlist`) in your build directory — an actual Graphviz dependency graph. For anything non-trivial, rendering this (`dot -Tpng task-depends.dot -o deps.png`) is far more useful than reading BitBake's textual dependency errors, especially once your recipe count grows past a handful.

```bash
bitbake -g mqtt-monitor-image
dot -Tpng task-depends.dot -o /tmp/deps.png
```

## `bitbake -e` — inspecting resolved variable values (essential daily tool)

Recipes reference dozens of inherited/default variables you didn't write. When something doesn't behave as expected, don't guess — inspect the actual resolved value:

```bash
bitbake -e hello-monitor | grep ^SRC_URI=
bitbake -e hello-monitor | grep ^FILESEXTRAPATHS=
bitbake -e mqtt-monitor-image | grep ^IMAGE_INSTALL=
```

`bitbake -e <recipe>` dumps every variable as BitBake _actually resolved it_ for that recipe, after all layers, classes, and overrides have been applied — this is ground truth, more reliable than reading source files and mentally tracing inheritance/override chains yourself. Use this constantly; it's the single highest-leverage debugging command in the entire toolchain, more so than log files for configuration-shaped problems (as opposed to compile-shaped problems, where the `temp/log.do_*` files are still primary).

## `bitbake -c listtasks` and dry-run inspection

```bash
bitbake -c listtasks hello-monitor    # shows every task this recipe will execute, in order
bitbake -n mqtt-monitor-image          # dry-run — shows what WOULD run without actually running it
```

`-n` (dry run / "no-execute") is worth using before any build where you're unsure whether you've accidentally triggered a much bigger rebuild than intended (e.g., after a `DISTRO_FEATURES` change, which can invalidate huge swaths of the dependency graph) — it tells you the task count before you commit to a multi-hour rebuild.

## Putting it together: a realistic debugging scenario

Say you change `mosquitto`'s recipe (custom `.bbappend`) and rebuild — but you observe way more gets rebuilt than expected, including seemingly unrelated recipes. Diagnostic sequence:

```bash
bitbake -n mqtt-monitor-image                     # see scope of what would rebuild, first
bitbake-diffsigs -t mosquitto do_compile           # confirm what specifically changed in mosquitto's signature
bitbake -g mqtt-monitor-image                       # if the scope seems wrong, inspect the actual dependency graph
```

Nine times out of ten, "unexpectedly wide rebuild" traces to a variable referenced in a task function that's _also_ referenced broadly — e.g., you edited something in a shared `.bbclass` or a distro-level variable, and everything inheriting that class picked up a new signature. `bitbake-diffsigs` will show you precisely which variable changed value, which usually immediately explains the blast radius.

## Key takeaways

- Tasks form a dependency graph, not a fixed sequence; `DEPENDS`/`RDEPENDS` compile down into `[depends]` flags that define graph edges.
- Task **signatures** (hash of function body + referenced variables + upstream task signatures) determine sstate cache hits/misses — this is the real mechanism behind "incremental builds are fast."
- sstate caches specific task _outputs_ (notably `do_populate_sysroot`, `do_package*`), not source code or a generic compiler cache — and it's shared safely across build directories keyed purely by signature match.
- `clean` removes WORKDIR but not sstate; `cleansstate` removes both — know which one you actually need.
- `bitbake -e <recipe>` is your primary configuration-debugging tool — resolved ground truth beats manual inheritance tracing.
- `bitbake -g` + Graphviz rendering is worth doing the first time a dependency problem isn't obvious from error text alone.
- `bitbake -n` before any build where rebuild scope is uncertain — cheap insurance against multi-hour surprises.

