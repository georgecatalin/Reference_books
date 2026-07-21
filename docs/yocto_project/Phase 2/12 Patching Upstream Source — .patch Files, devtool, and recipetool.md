[[Core Execution Mechanics]]

# Day 12: Patching Upstream Source — `.patch` Files, `devtool`, and `recipetool`

Sometimes you need to modify third-party source you don't control — fix a bug upstream hasn't released yet, adapt code for your specific hardware, or work around a cross-compilation quirk. This day covers doing that correctly, plus the two tools (`devtool`, `recipetool`) that make real-world recipe work dramatically less tedious than hand-writing everything from Days 10–11.

## Manual patching — the mechanics, done correctly

Say `mosquitto` needs a small fix for your BeagleBone serial setup that isn't upstream yet. The correct workflow:

```bash
# Get the exact source Yocto would fetch, to generate a patch against the right base
bitbake mosquitto -c unpack
cd tmp/work/*/mosquitto/*/git   # or wherever S resolved to
git diff  # if it's a git-fetched recipe, S is already a git repo — ideal case
```

If the source is git-based (most upstream projects now are), `${S}` is already a git working tree after unpack — this means you can literally edit files and use normal git tooling to produce a proper patch:

```bash
# make your edit to some source file
git diff > /tmp/serial-fixup.patch
```

**Better** — use `devtool` for this instead of manual `git diff` wrangling (below), but understanding the raw mechanism first matters for when `devtool` isn't available or for genuinely tiny one-line fixes where spinning up a full `devtool` workflow is overkill.

Once you have a `.patch` file, place it in your layer and reference it:

```
meta-mqtt-monitor/recipes-connectivity/mosquitto/
├── mosquitto_%.bbappend
└── files/
    └── 0001-fix-beaglebone-serial-timing.patch
```

```bitbake
FILESEXTRAPATHS:prepend := "${THISDIR}/files:"
SRC_URI += "file://0001-fix-beaglebone-serial-timing.patch"
```

That's it — BitBake's `do_patch` task (provided by `base.bbclass`, always present) applies patches in `SRC_URI` order automatically, using `git am` under the hood if the source is a git tree (preserving commit metadata/authorship) or plain `patch`/`quilt` otherwise. No additional configuration needed beyond adding it to `SRC_URI`.

**Patch naming convention**: numbered prefix (`0001-`, `0002-`) if you have multiple patches, since they apply in the order listed in `SRC_URI` — the number makes application order visually obvious to anyone reading the layer later, even though the actual order is determined by `SRC_URI` listing order, not the filename itself.

## `devtool` — the real workflow for iterative recipe development

This is the tool you'll actually use most often once past the learning-the-mechanics phase. `devtool` exists to solve a genuine pain point: iterating on a recipe (or the source it builds) by hand-editing files, re-running `bitbake`, checking logs, repeat, is slow and disconnects you from normal development tools (your editor, git, incremental compiles).

### `devtool modify` — edit an existing recipe's source interactively

```bash
devtool modify mosquitto
```

This does several things at once:

1. Extracts `mosquitto`'s source into a working directory (default: `build-qemu/workspace/sources/mosquitto`)
2. Sets up that directory as a proper git repo (even if the original fetch wasn't git-based)
3. Registers a **workspace layer** (`build-qemu/workspace/`) that temporarily overrides the real recipe to build from this local, editable source instead of `SRC_URI`

Now you just edit source normally:

```bash
cd build-qemu/workspace/sources/mosquitto
# edit files directly, using your normal editor
devtool build mosquitto
```

`devtool build` recompiles using your edited local source — genuinely fast, incremental, no re-fetch, no re-patch-application overhead. This is dramatically faster than the "edit patch file → `bitbake -c cleansstate` → rebuild" loop for active development.

When you're happy with changes:

```bash
devtool update-recipe mosquitto
```

This inspects your git history in the workspace source directory and **automatically generates `.patch` files** from your commits, placing them correctly in your layer with `SRC_URI` updated to reference them. This is the single biggest time-saver in this entire section — you never hand-write `git diff` output into a patch file; you commit your changes in the workspace repo with meaningful commit messages, and `devtool update-recipe` turns each commit into a properly named patch.

```bash
devtool reset mosquitto    # done — remove the workspace override, recipe now uses the layer's real (patched) version
```

**Practical git hygiene note**: since `devtool update-recipe` derives patch files from commit history, make your commits in the workspace source atomic and well-described — one commit per logical fix — because that's literally what becomes each `.patch` file's name and commit message header.

### `devtool add` — bootstrap a brand-new recipe from existing source

This is genuinely useful for your actual `mqtt_monitor` C++/Python capstones — instead of hand-writing the recipe skeleton from Day 11's patterns, let `devtool` infer as much as possible:

```bash
devtool add mqtt-monitor https://github.com/georgeco/mqtt-monitor-cpp.git
```

`devtool` fetches the source, inspects it (detects CMakeLists.txt → assumes `inherit cmake`; detects `setup.py`/`pyproject.toml` → assumes Python packaging class; etc.), and generates a first-draft recipe in the workspace. It's frequently not perfect — `DEPENDS` inference in particular is unreliable since it can't know what system libraries your CMakeLists.txt's `find_package` calls actually need on this build — but it gets you 70-80% of the way from zero, which is a real time savings over Day 11's fully-manual approach, especially for a project with many source files.

```bash
devtool edit-recipe mqtt-monitor   # opens the generated recipe for review/correction
devtool build mqtt-monitor          # test build the draft
devtool finish mqtt-monitor ../../meta-mqtt-monitor   # move the finished recipe into your real layer permanently
```

`devtool finish` is the step that actually commits the recipe into your named layer path — before that, everything lives in the temporary workspace and won't survive a workspace reset.

## `recipetool` — recipe generation without the full workspace workflow

`devtool add` actually uses `recipetool create` under the hood. Sometimes you want just the recipe skeleton without the full workspace/git integration:

```bash
recipetool create -o mqtt-monitor_1.0.bb https://github.com/georgeco/mqtt-monitor-cpp.git
```

This is a lighter-weight, one-shot version of the same inference `devtool add` does — useful for quick recipe drafts you'll hand-edit immediately rather than iterate on through the workspace mechanism.

`recipetool` also has standalone utility subcommands worth knowing:

```bash
recipetool appendfile meta-mqtt-monitor mosquitto.conf /etc/mosquitto/mosquitto.conf /path/to/local/mosquitto.conf
```

This generates a correctly-formed `.bbappend` (with `FILESEXTRAPATHS`, `SRC_URI +=`, `do_install:append()`) to install a single replacement file into an existing recipe's output — automating exactly the pattern you hand-wrote in Day 3's `.bbappend` example. Genuinely useful for quick "I just need to drop in one config file" cases.

## When to use which tool — practical decision guide

|Situation|Tool|
|---|---|
|Tiny, one-off patch to a stable upstream recipe|Manual `.patch` file + `SRC_URI +=`|
|Active iterative development on an existing recipe's source (bug hunting, feature dev)|`devtool modify` + `devtool build` loop, `devtool update-recipe` when done|
|Packaging a brand-new project (your own repos) for the first time|`devtool add`, review/correct, `devtool finish`|
|Just need a recipe skeleton to hand-edit immediately, no iteration needed|`recipetool create`|
|Adding one config/override file to an existing recipe without other changes|`recipetool appendfile`|

## Practical example: bringing your actual C++ mqtt_monitor into Yocto for the first time

Realistic sequence you'd actually run:

```bash
devtool add mqtt-monitor https://github.com/georgeco/mqtt-monitor-cpp.git
devtool edit-recipe mqtt-monitor
# inspect/fix the generated DEPENDS, add PACKAGECONFIG for TLS, add systemd inherit + SYSTEMD_SERVICE
devtool build mqtt-monitor
# iterate: edit source in workspace/sources/mqtt-monitor-cpp if bugs surface during Yocto-specific build
devtool build mqtt-monitor   # re-test
devtool finish mqtt-monitor ../../meta-mqtt-monitor
```

After `finish`, `meta-mqtt-monitor/recipes-mqtt/mqtt-monitor/mqtt-monitor_1.0.bb` exists as a real, permanent file in your layer — check it into git in your layer's own repo at that point, same as any other source file.

## Key takeaways

- Manual `.patch` files + `SRC_URI +=` is correct for small, static, one-off fixes — `do_patch` (always present via `base.bbclass`) applies them automatically, `git am`-style if source is a git tree.
- `devtool modify` + `devtool build` is the real iterative development loop — dramatically faster than repeated `bitbake -c cleansstate` cycles for active source changes.
- `devtool update-recipe` auto-generates patch files from your workspace git commits — write clean, atomic commits because they become your patch files directly.
- `devtool add` bootstraps new recipes from existing source repos (like your own `mqtt_monitor` capstones) with reasonable but imperfect inference — always review generated `DEPENDS` manually.
- `devtool finish` is the step that commits a workspace recipe permanently into your real layer — nothing survives past `devtool reset` until you run this.
- `recipetool appendfile` automates the exact `.bbappend` pattern from Day 3 for simple single-file config overrides.

