[[Foundations]]

# Day 5: `local.conf` Deep Dive — The Configuration Knobs That Actually Matter

Day 2 covered basic anatomy. Now the real content: what's actually worth setting, what's noise, and what production builds get wrong by leaving at defaults.

## Machine and distro selection

```bitbake
MACHINE = "raspberrypi4-64"
DISTRO = "poky"
```

These aren't just cosmetic — they cascade into hundreds of downstream variable defaults (compiler tuning, kernel choice, C library, init system). Never set `MACHINE` to something a layer doesn't provide (`conf/machine/<name>.conf` must exist in an active layer, per Day 3).

For **BeagleBone**, note there are two candidate machine names depending on layer: `beaglebone-yocto` (ships in OE-Core itself, generic/reference) vs `beaglebone-black` or similar names in dedicated BSP layers (e.g., meta-ti) with more complete hardware support (PRU, specific peripherals). For serial/GPIO-heavy work like yours, the dedicated BSP layer is usually worth the extra setup over the generic reference machine — the reference one deliberately keeps hardware-specific enablement minimal.

## Package format — decide this early, it's expensive to change later

```bitbake
PACKAGE_CLASSES = "package_ipk"
```

Options: `package_ipk`, `package_rpm`, `package_deb`. This determines what package format `do_package_write_*` produces and what's available on-target for runtime package management (`opkg`, `rpm`/`dnf`, `apt`).

**Practical guidance**: `ipk` is the lightweight, traditional embedded choice — smaller metadata overhead, simpler. `rpm` gives you SMART/DNF-style dependency resolution which matters more if you're doing field updates with complex dependency graphs. For your MQTT monitor deployment (RPi/BeagleBone, likely simple image updates or read-only rootfs with OTA at the image level rather than per-package), `ipk` is the pragmatic default unless you have a specific reason for RPM's heavier tooling.

**Why "decide early"**: switching `PACKAGE_CLASSES` after a build essentially invalidates your sstate cache for anything package-format-specific and forces substantial rebuilding. Not catastrophic, but wasteful — pick once, deliberately.

## Parallelism — the two knobs, precisely

```bitbake
BB_NUMBER_THREADS = "8"
PARALLEL_MAKE = "-j 8"
```

Reiterating Day 2 because this is where people actually misconfigure things: `BB_NUMBER_THREADS` controls how many **recipes** BitBake processes in parallel (its own task scheduler across the whole dependency graph). `PARALLEL_MAKE` controls the `-j` flag passed to `make` **within a single recipe's compile step**.

Practical formula: if you have N cores, `BB_NUMBER_THREADS = N` and `PARALLEL_MAKE = "-j N"` is a reasonable starting point — but they multiply in the worst case (N recipes each spawning N compile jobs = N² processes), so on RAM-constrained machines, dial both down rather than maxing both. A common tuning approach on a 16-core/32GB box: `BB_NUMBER_THREADS = "16"`, `PARALLEL_MAKE = "-j 8"` — biasing toward more recipe-level parallelism since not every recipe compiles heavy C++ simultaneously.

## Disk space guards — not optional, prevents silent corruption

```bitbake
BB_DISKMON_DIRS = "\
    STOPTASKS,${TMPDIR},1G,100K \
    STOPTASKS,${DL_DIR},1G,100K \
    STOPTASKS,${SSTATE_DIR},1G,100K \
    HALT,${TMPDIR},100M,1K"
```

Without this, a build that runs out of disk mid-task can leave corrupted intermediate state that's genuinely confusing to diagnose later (looks like a build bug, is actually "you ran out of space three tasks ago"). This is a "set it once in every project template and forget it" line — always include it.

## `IMAGE_INSTALL` vs `IMAGE_FEATURES` — controlling image content

```bitbake
IMAGE_INSTALL:append = " mosquitto mqtt-monitor python3-paho-mqtt openssh"
EXTRA_IMAGE_FEATURES += "debug-tweaks ssh-server-openssh"
```

- **`IMAGE_INSTALL`**: literal list of packages to install into the rootfs. This is where your application packages go.
- **`IMAGE_FEATURES`/`EXTRA_IMAGE_FEATURES`**: higher-level toggles that expand into predefined package sets and behaviors — `debug-tweaks` (passwordless root, useful in dev, **must be removed before production** since it disables root password enforcement), `ssh-server-openssh`, `package-management` (leaves opkg/rpm/apt usable on-target instead of stripping it after rootfs build), `read-only-rootfs` (Day 26 territory).

`debug-tweaks` specifically is worth flagging because it's easy to forget it's in your dev `local.conf` and accidentally carry it into a "release" build — it's a real security hole (empty root password) if shipped.

## `IMAGE_FSTYPES` — what output formats you actually get

```bitbake
IMAGE_FSTYPES = "ext4 wic.bz2 tar.bz2"
```

- `ext4` — raw filesystem image, useful for QEMU or direct partition flashing.
- `wic` — Yocto's own partitioned disk image format (bootloader + boot partition + rootfs partition, all in one file) — this is generally what you actually flash to an SD card for RPi/BeagleBone, not raw `ext4`.
- `tar.bz2` — useful as an intermediate/for NFS-root development setups.

For RPi/BeagleBone SD card deployment specifically, you want a `.wic` (or `.wic.bz2` compressed) — it already contains the partition table Boot ROM expects.

## `EXTRA_IMAGE_FEATURES` for development vs the "clean it up" checklist

Common dev-time additions you'll want, but must consciously strip for anything resembling production:

```
EXTRA_IMAGE_FEATURES += "tools-debug dbg-pkgs"
```

`tools-debug` pulls in gdb/strace-type tooling; `dbg-pkgs` installs the `-dbg` split package variants (unstripped symbols) system-wide — useful when you're actively debugging a crash on-target, wasteful/bloated for shipped images.

## `PREFERRED_VERSION` and `PREFERRED_PROVIDER` — pinning explicitly

```bitbake
PREFERRED_VERSION_linux-yocto = "6.6%"
PREFERRED_PROVIDER_virtual/kernel = "linux-yocto"
```

The `%` wildcard in a version pin means "this major/minor line, whatever patch." Use this for anything where you need reproducibility guarantees but don't want to chase exact patch versions manually — pin the line, let point releases float within it.

## `DISTRO_FEATURES` — the variable with the widest blast radius

```bitbake
DISTRO_FEATURES:append = " systemd"
DISTRO_FEATURES_BACKFILL_CONSIDERED += " sysvinit"
VIRTUAL-RUNTIME_init_manager = "systemd"
VIRTUAL-RUNTIME_initscripts = ""
VIRTUAL-RUNTIME_login_manager = "shadow-base"
```

`DISTRO_FEATURES` is a space-separated list controlling which optional subsystems get built into the base system at all — `systemd`, `sysvinit`, `wifi`, `bluetooth`, `x11`, `wayland`, `pam`, `usrmerge`, etc. This isn't per-package selection (that's `IMAGE_INSTALL`) — it's "does the _distro itself_ support this capability at all." A package's recipe can check `DISTRO_FEATURES` and conditionally build differently (e.g., mosquitto's recipe may enable/disable TLS support depending on whether certain features are present).

Switching init systems (sysvinit → systemd) is a `DISTRO_FEATURES` change plus the `VIRTUAL-RUNTIME_*` overrides shown above — this is a big-blast-radius change (affects nearly every service-providing recipe's packaging), not something you toggle casually mid-project. Decide it once, early (Day 19 covers systemd integration specifics).

## A realistic `local.conf` for your MQTT monitor / RPi work, assembled

```bitbake
MACHINE = "raspberrypi4-64"
DISTRO = "poky"

DL_DIR ?= "/home/george/yocto-shared/downloads"
SSTATE_DIR ?= "/home/george/yocto-shared/sstate-cache"

PACKAGE_CLASSES = "package_ipk"

BB_NUMBER_THREADS = "16"
PARALLEL_MAKE = "-j 8"

BB_DISKMON_DIRS = "\
    STOPTASKS,${TMPDIR},1G,100K \
    STOPTASKS,${DL_DIR},1G,100K \
    STOPTASKS,${SSTATE_DIR},1G,100K \
    HALT,${TMPDIR},100M,1K"

IMAGE_FSTYPES = "wic.bz2 tar.bz2"

IMAGE_INSTALL:append = " mqtt-monitor mosquitto openssh python3-paho-mqtt"
EXTRA_IMAGE_FEATURES += "ssh-server-openssh package-management"

PREFERRED_VERSION_linux-yocto = "6.6%"
```

Note: no `debug-tweaks`, no `tools-debug`/`dbg-pkgs` — this is written as a "clean" config from the start, with the assumption you'd add those two temporarily and manually during active debugging sessions rather than baking them in permanently.

## Key takeaways

- `MACHINE`/`DISTRO` cascade into hundreds of defaults — always verify the name exists in an active layer before assuming a typo isn't the problem.
- `PACKAGE_CLASSES` is expensive to change later — decide `ipk` vs `rpm` vs `deb` deliberately, once.
- `BB_NUMBER_THREADS` (cross-recipe) vs `PARALLEL_MAKE` (within-recipe compile) are different knobs — don't max both blindly on RAM-constrained machines.
- `BB_DISKMON_DIRS` isn't optional boilerplate — running out of disk mid-build causes real corruption, not just a clean failure.
- `IMAGE_INSTALL` = literal packages; `IMAGE_FEATURES` = higher-level bundles/toggles. `debug-tweaks` must never ship.
- `.wic`/`.wic.bz2` is what you flash to an SD card, not raw `.ext4`, for RPi/BeagleBone.
- `DISTRO_FEATURES` has the widest blast radius of any config variable here — changing init system or major subsystem support affects packaging across the whole build, decide early.

Continuing to Day 6 (image recipes — what makes an image recipe different from a package recipe, `.bb` image anatomy, `IMAGE_INSTALL` mechanics in depth) next.