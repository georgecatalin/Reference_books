[[Foundations]]

# Day 4: Recipe Anatomy — Variables, Tasks, and the `.bb` File

## What a recipe file actually is

A `.bb` file is metadata parsed by BitBake — a mix of variable assignments (shell-like syntax) and Python/shell function definitions. It is **not** a script that runs top-to-bottom like a shell script; BitBake parses the entire file to build a dependency graph of tasks, then executes tasks in dependency order. Understanding this distinction matters: variable assignments can appear in any order in the file because BitBake resolves them lazily, but if you're thinking "this runs first" based on file position, you'll misdiagnose bugs.

## A real, complete recipe — annotated line by line

Let's use something relevant to you: packaging a simple C++ serial-reader utility (a stripped-down piece of your `mqtt_monitor`).

```bitbake
SUMMARY = "Serial telemetry reader utility"
DESCRIPTION = "Reads telemetry frames from a serial device and republishes over MQTT"
HOMEPAGE = "https://github.com/georgeco/serial-reader"
LICENSE = "MIT"
LIC_FILES_CHKSUM = "file://LICENSE;md5=0835ade698e0bcf8506ecda2f7b4f302"

SRC_URI = "git://github.com/georgeco/serial-reader.git;protocol=https;branch=main"
SRCREV = "a1b2c3d4e5f6..."

S = "${WORKDIR}/git"

DEPENDS = "mosquitto"

inherit cmake

FILES:${PN} += "${sysconfdir}/serial-reader/*"
```

Go through each variable, because these are the ones you'll write in nearly every recipe:

- **`SUMMARY`/`DESCRIPTION`**: metadata only, shows up in package manager listings (`opkg info`, etc.). Not functional.
- **`LICENSE`**: mandatory in modern Yocto — BitBake will refuse to build without it. Must match an SPDX identifier or a custom license name with a corresponding file in the layer's `files/` for `LIC_FILES_CHKSUM` to verify against.
- **`LIC_FILES_CHKSUM`**: this is not decorative — BitBake computes an MD5 (or SHA256) of the referenced license file and **fails the build if it changes**, forcing you to acknowledge a license change explicitly by updating the checksum. This is a real compliance mechanism, not boilerplate.
- **`SRC_URI`**: where source comes from. Fetcher is inferred from the URL scheme (`git://`, `http://`, `file://`, `https://`). For git, you also need `SRCREV` — the exact commit hash BitBake pins to. **`SRCREV = "${AUTOREV}"`** exists for always-latest-HEAD builds, but you should treat that as a development convenience only — never use it for anything you consider a reproducible/release build, since it breaks build reproducibility (two builds on different days silently pull different code).
- **`S`**: tells BitBake where, after unpacking, the actual source root is. For most fetchers this defaults sensibly, but for git fetches it lands in `${WORKDIR}/git` by convention, which is why it's set explicitly here.
- **`DEPENDS`**: **build-time** dependencies — other recipes that must be built and staged before this one's `do_configure`/`do_compile` can run. This does NOT control what's installed at runtime — that's `RDEPENDS` (below).
- **`inherit cmake`**: pulls in `cmake.bbclass`, which provides ready-made `do_configure`/`do_compile`/`do_install` implementations tailored for CMake projects. This is why most recipes are short — you're not writing build logic from scratch, you're inheriting a class and just tweaking specifics.
- **`FILES:${PN} +=`** : controls what ends up in which output package (see packaging section below).

## `DEPENDS` vs `RDEPENDS` — the distinction people get wrong constantly

This is one of the most common sources of confusion, so get it precise now:

- **`DEPENDS`**: build-time. "I need `mosquitto`'s headers/libs staged in my sysroot to compile against." Affects build order and what's available in `do_configure`/`do_compile`.
- **`RDEPENDS:${PN}`**: run-time. "The compiled binary needs `mosquitto` (the daemon/package) installed on the target device to function correctly." Affects what gets pulled into the final image/package manager dependency graph — has **zero** effect on the build itself.

A recipe compiling against `libmosquitto-dev` headers needs `DEPENDS = "mosquitto"` (assuming the recipe splits dev/runtime packages appropriately — see Day 14). If your compiled binary dynamically links against `libmosquitto.so` at runtime, BitBake's shared-library dependency scanner (`shlibs`) usually auto-detects and injects the correct `RDEPENDS` automatically — you often don't need to declare it manually for shared library links. You _do_ need explicit `RDEPENDS` for things that aren't detectable by binary scanning: a Python script's runtime imports, a shell script that calls out to another binary, a systemd service that needs another daemon running.

## Task functions — how recipes actually build software

Classes like `cmake`, `autotools`, `setuptools3` provide default task implementations. You override or extend them like this:

```bitbake
do_configure:prepend() {
    echo "About to configure with custom flags"
}

do_install() {
    install -d ${D}${bindir}
    install -m 0755 ${B}/serial-reader ${D}${bindir}/serial-reader

    install -d ${D}${sysconfdir}/serial-reader
    install -m 0644 ${S}/config/default.conf ${D}${sysconfdir}/serial-reader/default.conf

    install -d ${D}${systemd_unitdir}/system
    install -m 0644 ${S}/systemd/serial-reader.service ${D}${systemd_unitdir}/system/serial-reader.service
}
```

Key path variables you need memorized, since they appear in nearly every `do_install`:

|Variable|Meaning|
|---|---|
|`${S}`|Source directory (after unpack/patch)|
|`${B}`|Build directory (where compilation actually happens — can differ from `${S}` for out-of-tree builds)|
|`${D}`|**Destination root** — the staging root for `do_install`; treat this as "the future root filesystem," everything installed under here with real absolute paths (`${D}${bindir}/foo`, not `${D}/foo`)|
|`${WORKDIR}`|The per-recipe scratch directory (temp files, patches, `${S}` and `${B}` both usually live under here)|
|`${bindir}`, `${sysconfdir}`, `${libdir}`, `${systemd_unitdir}`|Standard FHS-style path variables, resolved per-distro-policy — **always use these, never hardcode `/usr/bin` or `/etc`**|

Why never hardcode paths: distro policy or multilib configurations can remap these (e.g., `/usr/local` prefix variants, cross-multilib `/usr/lib64`). Using the variables means your recipe stays correct across configurations without changes.

## Full task pipeline, precisely

```
do_fetch        → downloads SRC_URI content into DL_DIR
do_unpack       → extracts into WORKDIR
do_patch        → applies any .patch files in SRC_URI, in order
do_prepare_recipe_sysroot → stages DEPENDS' sysroots for this recipe to build against
do_configure    → runs configure step (autotools ./configure, cmake, or custom)
do_compile      → runs the actual build (make, ninja, etc.)
do_install      → installs build output into ${D}, using real target paths
do_package      → splits ${D} into logical packages (main, -dev, -dbg, -doc, -staticdev)
do_package_write_ipk/rpm/deb → produces installable package files
do_populate_sysroot → stages this recipe's headers/libs for OTHER recipes that DEPENDS on it
```

Two tasks worth flagging specifically because they're invisible until something breaks:

- **`do_prepare_recipe_sysroot`**: this is _why_ `DEPENDS` works — before your `do_configure` runs, BitBake has already populated a recipe-specific sysroot from everything you depend on. If a header isn't found during compile, the fix is almost never "add an include path hack" — it's "check whether `DEPENDS` actually lists the right recipe."
- **`do_populate_sysroot`**: the mirror operation — after _your_ recipe builds, this stages your headers/libs so _other_ recipes' `DEPENDS` on you will work. If you write a library recipe and something downstream can't find your headers, check this task's output, not the downstream recipe.

## Running individual tasks (essential debugging skill, use constantly)

You don't have to run the full recipe every time:

```bash
bitbake serial-reader -c compile        # run up through do_compile only
bitbake serial-reader -c compile -f     # force re-run even if sstate says it's cached
bitbake serial-reader -c cleansstate    # nuke this recipe's sstate + force full rebuild
bitbake serial-reader -c devshell       # drop into an interactive shell with the exact build environment
```

**`devshell` is the single most useful debugging tool you'll use.** It drops you into a shell with `${S}`, `${B}`, `PATH`, `CC`, all cross-compilation environment variables set up exactly as BitBake would during `do_compile` — so you can manually run `./configure`, `make`, inspect what's actually failing, without BitBake's output buffering hiding the real error. When a build fails and the log isn't enough, `devshell` is where you go next.

## Recipe naming and versioning conventions

Filename encodes name + version: `mosquitto_2.0.18.bb` → `PN = "mosquitto"`, `PV = "2.0.18"` (BitBake derives these automatically from the filename — you don't set them manually in the common case).

`PR` (package revision) — bump this when you change the _recipe_ (not the underlying software) in a way that should trigger a rebuild — e.g., you changed `do_install` logic but `PV` is unchanged. Convention: `PR = "r0"`, increment to `r1`, `r2`, etc.

## Key takeaways

- A recipe is metadata, not a linear script — BitBake builds a task dependency graph from it.
- `LICENSE` + `LIC_FILES_CHKSUM` are enforced, not decorative — license file changes break the build until acknowledged.
- `DEPENDS` = build-time, `RDEPENDS` = run-time. Confusing these is the most common recipe bug for newcomers.
- Never hardcode filesystem paths — use `${bindir}`, `${sysconfdir}`, `${systemd_unitdir}`, etc.
- `${D}` is the staging destination root for `do_install`; `${S}`/`${B}` are source/build dirs; `${WORKDIR}` is the scratch parent.
- `bitbake <recipe> -c devshell` is your primary debugging tool when logs alone don't explain a failure.
- `inherit <class>` is how most recipes stay short — you're almost always extending a class's default tasks, not writing `do_compile` from scratch.

Continuing to Day 5 (`local.conf`/`bblayers.conf` deep dive — the configuration knobs that actually matter in practice) next.