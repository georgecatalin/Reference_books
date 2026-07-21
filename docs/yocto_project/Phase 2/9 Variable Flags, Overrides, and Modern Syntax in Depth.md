[[Core Execution Mechanics]]

# Day 9: Variable Flags, Overrides, and Modern Syntax in Depth

Day 3 and Day 4 used `:append`/`:prepend` in passing. Now the complete picture — override syntax, conditional variable expansion, and the mechanics that let one recipe/class behave differently per machine, distro, or build configuration without branching logic scattered everywhere.

## The old vs new syntax — know both, write only the new

Pre-Kirkstone (before ~2022), override syntax used underscores, which created real ambiguity with normal variable names:

```bitbake
# OLD (deprecated, still parses via compatibility layer in most current releases)
do_install_append() { ... }
IMAGE_INSTALL_append = " foo"
SRC_URI_append_qemux86-64 = " file://extra.patch"
```

Modern syntax (Kirkstone onward, what you should always write):

```bitbake
do_install:append() { ... }
IMAGE_INSTALL:append = " foo"
SRC_URI:append:qemux86-64 = " file://extra.patch"
```

The colon syntax exists because underscore-based overrides were genuinely ambiguous — `FOO_BAR_append` could be parsed as "append to `FOO_BAR`" or "append to `FOO` with override `BAR`" depending on context, and BitBake had to guess via variable name matching against known variables. Colon syntax removes the ambiguity entirely: everything after the first colon is unambiguously an operation or override, never part of the base variable name.

**If you see underscore syntax in a tutorial, blog post, or older layer** — it's pre-Kirkstone content. It'll often still work due to a compatibility shim, but don't write new code that way, and be aware some newer OE-Core releases are tightening/removing the shim over time.

## The four override operators, precisely

```bitbake
VAR = "base"
VAR:append = " appended"      # → "base appended"  (note: no auto space-insertion; you write the space)
VAR:prepend = "prepended "    # → "prepended base appended"
VAR:remove = "base"           # → " prepended  appended" (base removed, leaves surrounding whitespace as-is)
```

Critical detail people miss: **`:append`/`:prepend` do NOT insert whitespace for you.** If you forget the leading/trailing space, you get `"baseappended"` — a broken concatenated string, not an error. This is an extremely common silent bug, especially with things like `SRC_URI:append = "file://foo.patch"` (missing leading space) silently merging with the previous URI entry. Always explicitly include the space.

Also critical: **`:append`/`:prepend`/`:remove` are weak operators evaluated at parse-time in a specific order relative to conditional overrides** (below) — they apply _after_ all machine/distro-specific overrides for the same base variable have been resolved. This ordering occasionally surprises people who expect strict textual/file order to determine precedence; it doesn't — override resolution has its own precedence rules independent of file read order.

## Conditional overrides — machine/distro/class-specific values

This is the mechanism that lets one recipe behave correctly across many machines without conditional logic:

```bitbake
SRC_URI:append:qemux86-64 = " file://qemu-only.patch"
SRC_URI:append:raspberrypi4-64 = " file://rpi-only.patch"

CFLAGS:append:beaglebone-yocto = " -DUSE_PRU_SUPPORT"
```

`:qemux86-64`, `:raspberrypi4-64`, `:beaglebone-yocto` here are **overrides** — BitBake automatically knows the _current_ value of `MACHINE` (and `DISTRO`, and a few other built-in override classes) and only applies the override-suffixed assignment if it matches. This is driven by the `OVERRIDES` variable, which BitBake populates automatically based on `MACHINE`, `DISTRO`, `TARGET_ARCH`, and a handful of other context variables — you rarely set `OVERRIDES` directly, but understanding it exists explains _why_ `:qemux86-64` "just works" without you registering that string anywhere.

```bash
bitbake -e hello-monitor | grep ^OVERRIDES=
```

Run this once against a real recipe to see the actual override list BitBake is matching against for your current build — it's genuinely illuminating the first time, since it includes more context than most people expect (machine, distro, target arch, class-extension context, and more).

## Class-extension overrides — `:class-native`, `:class-target`

Recipes can be built to run **on the build host** (native tools, e.g. a code generator you need during the build) vs **for the target** (the actual embedded device). This is the `-native` recipe variant mechanism:

```bitbake
DEPENDS = "protobuf-native"
```

`protobuf-native` here means "build protobuf _for the host architecture_ so its compiler binary can run during this build," as distinct from plain `protobuf` (built for the target). You'll use this constantly for build-time code generators, tools, and anything that needs to _execute_ during the build rather than just link into the final binary.

Overrides matching this context:

```bitbake
EXTRA_OECONF:class-native = "--disable-target-specific-flag"
EXTRA_OECONF:class-target = "--enable-target-specific-flag"
```

This lets a single recipe file define both the native and target build variants' differences without duplicating the whole recipe — relevant if you ever write a recipe for a tool you need both cross-compiled (runs on-device) and native (runs during build, e.g. code-gen for that same protocol).

## `OVERRIDES` precedence and multiple simultaneous matches

If multiple override-suffixed assignments could apply simultaneously (e.g., both a distro-level and machine-level override exist for the same variable), BitBake resolves via the order overrides appear in the `OVERRIDES` variable itself — later entries in `OVERRIDES` take precedence over earlier ones when both match. In practice this rarely needs manual reasoning because conflicting simultaneous overrides for the same variable are uncommon and usually a sign the config is more tangled than it should be — if you find yourself needing to carefully reason about override ordering, that's often a signal to restructure rather than to memorize the exact precedence table.

## `bitbake -e` again — but specifically for override debugging

This is the single best way to confirm your override actually applied as intended, rather than assuming:

```bash
bitbake -e hello-monitor | grep "^SRC_URI="
```

If your `:append:qemux86-64` didn't show up in the resolved value, either: `MACHINE` isn't actually what you think it is in this build context, or you have a typo in the override suffix (machine names are exact strings — `qemux86_64` vs `qemux86-64` is a real, easy-to-make mistake, hyphen vs underscore).

## `python()` anonymous functions — for logic beyond variable assignment

Sometimes conditional logic is too complex for override syntax alone (e.g., "set this variable based on a computed condition, not just a fixed machine/distro match"). Recipes can embed inline Python:

```bitbake
python () {
    if d.getVar('MACHINE') == 'raspberrypi4-64':
        d.setVar('SOME_VAR', 'rpi-specific-value')
    else:
        d.setVar('SOME_VAR', 'default-value')
}
```

This runs at parse time, with `d` being BitBake's datastore object (the same abstraction `bitbake -e` dumps). This is an escape hatch — reach for override syntax first since it's more declarative and easier for others to read; drop to `python()` blocks only when the logic genuinely can't be expressed as simple overrides (computed values, string manipulation, reading external files at parse time).

## Practical example tying it together — a recipe that behaves differently across your actual targets

```bitbake
# recipes-mqtt/mqtt-monitor/mqtt-monitor_1.0.bb

SUMMARY = "MQTT device monitor service"
LICENSE = "MIT"
LIC_FILES_CHKSUM = "file://LICENSE;md5=..."

SRC_URI = "git://github.com/georgeco/mqtt-monitor.git;protocol=https;branch=main"
SRCREV = "abc123..."
S = "${WORKDIR}/git"

inherit cmake systemd

DEPENDS = "mosquitto sqlite3"
DEPENDS:append:raspberrypi4-64 = " userland"

SRC_URI:append:beaglebone-yocto = " file://beaglebone-serial-fixup.patch"

EXTRA_OECMAKE:append:raspberrypi4-64 = " -DENABLE_CAMERA_SUPPORT=ON"

SYSTEMD_SERVICE:${PN} = "mqtt-monitor.service"
SYSTEMD_AUTO_ENABLE:${PN} = "enable"
```

This single recipe correctly builds for QEMU (base behavior), RPi4 (adds `userland` VideoCore dependency + camera cmake flag), and BeagleBone (adds a serial fixup patch) — without branches, without duplicated recipe files, purely through override-suffixed variable assignments. This is the actual production pattern for multi-machine layers, and it's why Day 3's layer/machine structure matters — this recipe works correctly regardless of which `MACHINE` your `local.conf` currently selects.

## Key takeaways

- Always write colon syntax (`:append`, `:prepend`, `:remove`, `:machine-name`) — underscore syntax is legacy and ambiguous.
- `:append`/`:prepend` do not insert whitespace automatically — a missing leading/trailing space is a common silent bug, not an error.
- Override matching is driven by the automatically-populated `OVERRIDES` variable (from `MACHINE`, `DISTRO`, `TARGET_ARCH`, class-extension context) — inspect it directly with `bitbake -e` rather than assuming.
- `-native` recipe variants (`DEPENDS = "foo-native"`) build a tool for the host to run during the build, distinct from the target-architecture build of the same software.
- `python()` anonymous blocks are an escape hatch for logic override syntax can't express — prefer declarative overrides first.
- One well-structured recipe with override-suffixed variables can correctly serve multiple machines — this is the actual pattern for maintainable multi-target layers, directly relevant to your QEMU/RPi/BeagleBone targets.

Continuing to Day 10 (writing recipes from scratch — a simple C program recipe, done properly and completely) next.