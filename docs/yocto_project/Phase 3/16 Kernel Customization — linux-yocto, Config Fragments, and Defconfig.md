[[Practical Systems Work]]

# Day 16: Kernel Customization — `linux-yocto`, Config Fragments, and Defconfig

Day 15 referenced `PREFERRED_PROVIDER_virtual/kernel` without explaining what actually happens inside a kernel recipe. This day covers the real mechanics: how `linux-yocto` differs from a "just cross-compile mainline" approach, how to add/remove kernel config options without hand-editing a giant `.config`, and how to add out-of-tree drivers — directly relevant for anything RS485/serial/GPIO-specific in your MQTT monitor hardware.

## Why `linux-yocto` exists instead of "just fetch kernel.org and cross-compile it"

You _can_ write a kernel recipe that fetches a plain kernel.org tarball and treats it like any autotools-ish project. But `linux-yocto` (and vendor equivalents like `linux-imx`, `linux-ti-staging`) exist because real embedded kernel work needs: BSP-specific patches (SoC drivers not yet upstream), a maintainable way to layer config changes without hand-editing `.config`, and reproducible config generation from fragments rather than a single monolithic file that's painful to diff/merge across board variants.

## `linux-yocto`'s actual structure — kernel metadata, not just source

This is the part that's genuinely different from a normal recipe and confuses people coming from plain kernel-building experience:

```bitbake
SRC_URI = "git://git.yoctoproject.org/linux-yocto.git;branch=v6.6/standard/base;protocol=https"
SRCREV_machine = "abc123..."
SRCREV_meta = "def456..."

KBRANCH = "v6.6/standard/base"
KMACHINE = "qemux86-64"

LINUX_VERSION = "6.6.21"
```

`linux-yocto` fetches from a specially-maintained git tree that separates **kernel source** (`SRCREV_machine`) from **kernel metadata** (`SRCREV_meta`) — the metadata is a separate git branch containing `.scc` (series config control) and `.cfg` fragment files describing which config options apply for which `KMACHINE`/`KBRANCH` combination. This split exists specifically so config management doesn't require hand-maintaining monolithic `defconfig` files per board — you compose config from small, focused fragments instead.

## `.cfg` fragments — the actual unit of kernel config change

Instead of hand-editing `.config`, you write small fragment files, each toggling a focused set of related options:

```
# files/mqtt-monitor-serial.cfg
CONFIG_SERIAL_8250=y
CONFIG_SERIAL_8250_CONSOLE=y
CONFIG_SERIAL_8250_NR_UARTS=8
CONFIG_SERIAL_8250_RUNTIME_UARTS=8
```

```
# files/rs485-support.cfg
CONFIG_SERIAL_8250_RSA=y
# RS485 direction control via RTS toggling — requires this on many UART drivers
CONFIG_SERIAL_8250_DMA=y
```

Referenced from a `.bbappend`:

```bitbake
# linux-yocto_%.bbappend

FILESEXTRAPATHS:prepend := "${THISDIR}/files:"

SRC_URI:append = " file://mqtt-monitor-serial.cfg \
                   file://rs485-support.cfg"
```

That's the entire mechanism for adding config options — no `.scc` file needed for simple additive fragments; `linux-yocto.bbclass` automatically picks up `.cfg` files appended to `SRC_URI` and merges them into the final kernel config. This is the case you'll use 90% of the time.

## `.scc` files — for anything beyond simple additive config (patches + config together)

When you need to bundle **both** a source patch and its corresponding config options together (e.g., a driver patch that only makes sense with certain config enabled), `.scc` (series config control) files describe an ordered combination of patches and config fragments:

```
# files/mqtt-monitor-board.scc
define KMACHINE mqtt-monitor-board
define KTYPE standard
define KARCH arm64

include ktypes/standard/standard.scc
branch mqtt-monitor-board

patch 0001-add-custom-gpio-driver.patch
kconf non-hardware mqtt-monitor-serial.cfg
kconf non-hardware rs485-support.cfg
```

Referenced the same way via `SRC_URI +=` in the `.bbappend`. Practical guidance: don't reach for `.scc` files until you actually need patch+config bundling or genuine board-specific kernel branching — for the common case of "just enable/disable some config options," plain `.cfg` fragments via `SRC_URI` is simpler and sufficient, and you'll spend most of your real work here rather than writing `.scc` files.

## Verifying your config fragment actually applied — the essential check

This is the step people skip and then get confused later when a driver doesn't load:

```bash
bitbake linux-yocto -c menuconfig
```

This opens the actual kernel `menuconfig` ncurses UI, pre-loaded with your fragments already merged — you can navigate to confirm `CONFIG_SERIAL_8250_RSA` is actually set to `y`, not just trust that your `.cfg` file was syntactically correct and applied silently.

Alternative, non-interactive check:

```bash
bitbake linux-yocto -c kernel_configcheck
```

This runs Yocto's own config sanity checker — it reports fragments that requested an option that **didn't actually make it into the final `.config`** (common cause: the option depends on another config that isn't enabled, a classic kernel Kconfig dependency chain issue — `CONFIG_FOO` silently doesn't apply if `CONFIG_BAR` it depends on is off). This tool catches a real, common class of "I added the fragment but the driver still isn't there" bug that's otherwise very confusing to diagnose.

```bash
cat tmp/work/*/linux-yocto/*/build/.config | grep CONFIG_SERIAL_8250_RSA
```

Direct confirmation against the actual generated `.config` — the ground-truth check when `kernel_configcheck`'s report isn't conclusive enough.

## Adding an out-of-tree driver — the actual pattern

Say you have a custom kernel module for a specific sensor/peripheral not in mainline. Two approaches:

**Approach A — build it as part of the kernel tree itself** (patch it in, `.scc`-managed, becomes part of `vmlinux` or an in-tree loadable module):

```
patch 0001-add-custom-gpio-driver.patch    # adds drivers/misc/my-sensor.c + Kconfig/Makefile entries
kconf non-hardware my-sensor.cfg           # CONFIG_MY_SENSOR=m
```

**Approach B — a separate out-of-tree module recipe** (more common for something you maintain independently of kernel version bumps):

```bitbake
# recipes-kernel/my-sensor-driver/my-sensor-driver_1.0.bb

SUMMARY = "Out-of-tree driver for custom sensor"
LICENSE = "GPL-2.0-only"
LIC_FILES_CHKSUM = "file://COPYING;md5=..."

inherit module

SRC_URI = "git://github.com/georgeco/my-sensor-driver.git;protocol=https;branch=main"
SRCREV = "..."
S = "${WORKDIR}/git"

RPROVIDES:${PN} = "kernel-module-my-sensor"
```

`inherit module` provides the correct out-of-tree kernel module build pattern — handles finding the correct kernel build tree (`STAGING_KERNEL_DIR`), correct `KERNEL_SRC`/`KERNEL_VERSION` matching, and produces a properly versioned `.ko` matched to exactly the kernel you're building against (a genuinely fragile thing to get right manually — kernel modules must match the exact kernel ABI, and `inherit module` handles this correctly).

**Practical guidance on which approach**: Approach B (separate recipe, `inherit module`) is generally preferable unless the driver needs to be built statically into `vmlinux` (rare for genuinely custom peripheral drivers) — it decouples your driver's development/versioning from kernel version bumps, and is the more common pattern in real production BSPs for anything you're actively maintaining yourself.

## `KERNEL_MODULE_AUTOLOAD` and `KERNEL_MODULE_PROBECONF` — loading modules at boot

```bitbake
KERNEL_MODULE_AUTOLOAD += "my-sensor"
KERNEL_MODULE_PROBECONF += "my-sensor"
module_conf_my-sensor = "options my-sensor debug=1"
```

`KERNEL_MODULE_AUTOLOAD` ensures the module is actually loaded at boot (adds it to `/etc/modules-load.d/`) rather than just being present-but-unloaded on the filesystem — a common gap where a driver recipe builds/installs correctly but nothing ever loads it without this.

## Kernel image type and boot artifacts

```bitbake
KERNEL_IMAGETYPE = "Image"      # or "zImage", "uImage" — depends on bootloader expectations
KERNEL_IMAGETYPE:append = " Image.gz"   # sometimes you want both compressed and uncompressed available
```

Match this to what your bootloader (U-Boot, Day 17) actually expects to load — `uImage` requires U-Boot-specific header wrapping (via `mkimage`, handled automatically by the `uboot-sign`/`kernel-uimage` class machinery when selected), while `Image`/`zImage` are used more directly by newer U-Boot boot flows (e.g., via `booti`/generic distro boot). Getting this mismatched (bootloader expects `uImage`, recipe only produces `Image`) is a real, common "board doesn't boot, no obvious error" class of bug — check your bootloader's actual boot script/commands against what `KERNEL_IMAGETYPE` is set to produce.

## Key takeaways

- `linux-yocto` splits kernel source (`SRCREV_machine`) from kernel metadata/config (`SRCREV_meta`) — config is composed from `.cfg` fragments, not hand-edited monolithic files.
- Plain `.cfg` fragments via `SRC_URI +=` cover the common "enable/disable some config options" case; `.scc` files are for bundling patches with their corresponding config, used less often.
- Always verify fragments actually applied — `bitbake -c kernel_configcheck` catches the common "option didn't apply due to unmet Kconfig dependency" failure mode; `-c menuconfig` for interactive confirmation.
- Out-of-tree drivers: prefer a separate recipe with `inherit module` (decoupled from kernel version bumps) over patching directly into the kernel tree, unless static `vmlinux` linkage is genuinely required.
- `KERNEL_MODULE_AUTOLOAD` is required for a module to actually load at boot — building/installing it alone isn't sufficient.
- `KERNEL_IMAGETYPE` must match what your actual bootloader expects (`Image` vs `zImage` vs `uImage`) — a mismatch here is a classic silent boot failure.
