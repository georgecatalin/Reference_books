[[Advanced Production]]
# Day 23: Multi-Configuration Strategy — `MACHINE`/`DISTRO` Layering for Product Lines

Days 6, 15, and 20 each previewed pieces of this (image variants via `require`+`.inc`, machine variants via shared `.inc` files). This day treats it as a complete architectural strategy — how real companies structure Yocto builds across multiple hardware revisions, product tiers, and deployment environments (dev/staging/production) without an explosion of duplicated, drifting configuration.

## The actual problem this solves

Realistic scenario for your situation: you have (or will have) an RPi4-based prototype, a BeagleBone-based prototype, and eventually a custom PCB — each potentially with dev/production variants, and potentially a "lite" cost-reduced hardware tier down the line. Naively, this becomes N machines × M image variants × K distro policies = a combinatorial mess of near-duplicate files that drift out of sync as each gets edited independently. The actual discipline is: **factor out what's shared into `.inc` files, let each concrete `.conf`/`.bb` file be a thin composition of includes plus its genuine deltas.**

## Machine configuration hierarchy — the real pattern

```
conf/machine/include/
├── mqtt-monitor-common.inc          # truly shared across ALL your hardware
├── mqtt-monitor-arm-common.inc       # shared ARM-specific tuning/features
conf/machine/
├── mqtt-monitor-rpi4.conf             # require mqtt-monitor-common.inc + arm-common.inc
├── mqtt-monitor-bbb.conf              # require mqtt-monitor-common.inc + arm-common.inc
├── mqtt-monitor-custom-reva.conf      # require mqtt-monitor-common.inc + arm-common.inc
└── mqtt-monitor-custom-revb.conf      # require mqtt-monitor-common.inc + arm-common.inc
```

```bitbake
# mqtt-monitor-common.inc
DISTRO_FEATURES:append = " systemd"
MACHINE_FEATURES:append = " rtc"
PREFERRED_VERSION_linux-yocto = "6.6%"
IMAGE_FSTYPES ?= "wic.bz2 tar.bz2"
```

```bitbake
# mqtt-monitor-custom-revb.conf
require conf/machine/include/mqtt-monitor-common.inc
require conf/machine/include/mqtt-monitor-arm-common.inc

DEFAULTTUNE = "cortexa53-crypto"
PREFERRED_PROVIDER_virtual/kernel = "linux-my-board"
KERNEL_DEVICETREE = "freescale/imx8mm-my-board-revb.dtb"
UBOOT_MACHINE = "my_board_revb_defconfig"

# Rev B specific: added hardware watchdog vs rev A
MACHINE_FEATURES:append = " watchdog"
```

**The discipline that actually matters here**: when you find yourself editing the same value in two `.conf` files, stop and ask whether it belongs in the shared `.inc` instead. This is exactly analogous to any software engineering "don't repeat yourself" discipline — the failure mode is identical to copy-pasted code drifting out of sync, just at the configuration layer instead of source code.

## `DISTRO` layering — separating policy from hardware

A parallel hierarchy exists for distro policy (separate axis from machine — recall Day 5's `MACHINE`/`DISTRO` are independent variables):

```
conf/distro/
├── mqtt-monitor-distro.conf           # your company's base distro policy
└── include/
    └── mqtt-monitor-distro-common.inc
```

```bitbake
# mqtt-monitor-distro.conf
require conf/distro/include/mqtt-monitor-distro-common.inc

DISTRO = "mqtt-monitor-distro"
DISTRO_NAME = "MQTT Monitor Product Distro"
DISTRO_VERSION = "1.0.0"

PREFERRED_VERSION_linux-yocto ?= "6.6%"
DISTRO_FEATURES:append = " systemd wifi"

TCLIBC = "glibc"
```

Writing your **own** distro (rather than using `poky` directly, which Day 2 used as the reference starting point) is the actual production pattern — `poky.conf` is a reference/example distro, not something you ship as-is for a real product, since you'll want to control `DISTRO_VERSION` (tied to your own release versioning, not Yocto's), potentially swap `TCLIBC` (glibc vs musl, if flash size pressure ever genuinely demands it), and set organization-wide `DISTRO_FEATURES` policy in one place rather than scattered across `local.conf` files that don't travel with the layer.

```bitbake
DISTRO = "mqtt-monitor-distro"
```

This one line in `local.conf`, once your distro layer is authored, replaces `DISTRO = "poky"` — everything downstream (init system choice, package format defaults, feature policy) now flows from your own maintained policy file rather than the reference.

## Image variants — dev/staging/production, the full pattern

Extending Day 6's `require`+`.inc` preview into the complete picture:

```
recipes-images/images/
├── mqtt-monitor-image-base.inc         # shared package list, features
├── mqtt-monitor-image.bb                # production: require base.inc, minimal additions
├── mqtt-monitor-image-dev.bb            # require base.inc, add debug tooling
└── mqtt-monitor-image-staging.bb        # require base.inc, add staging-specific telemetry/logging verbosity
```

```bitbake
# mqtt-monitor-image-base.inc
inherit core-image

CORE_IMAGE_EXTRA_INSTALL += " \
    mqtt-monitor \
    mosquitto \
    python3-paho-mqtt \
    openssh \
    "

IMAGE_ROOTFS_EXTRA_SPACE = "1048576"
IMAGE_LINGUAS = "en-us"
```

```bitbake
# mqtt-monitor-image.bb  (production)
require mqtt-monitor-image-base.inc

EXTRA_IMAGE_FEATURES += "read-only-rootfs"
```

```bitbake
# mqtt-monitor-image-dev.bb
require mqtt-monitor-image-base.inc

EXTRA_IMAGE_FEATURES += "debug-tweaks tools-debug ssh-server-openssh package-management"
IMAGE_INSTALL:append = " strace gdb tcpdump htop"
```

Building any variant for any hardware target is now a two-variable selection:

```bitbake
MACHINE = "mqtt-monitor-custom-revb"
DISTRO = "mqtt-monitor-distro"
```

```bash
bitbake mqtt-monitor-image           # production, rev B hardware
bitbake mqtt-monitor-image-dev        # dev variant, same rev B hardware, just switch the target
```

This is the actual payoff of the whole factoring discipline — an N-hardware × M-variant combinatorial space collapses to N machine `.conf` files + M image `.bb` files (not N×M), because the shared `.inc` files carry everything common.

## Multiple build directories for parallel product-line work

Since `MACHINE` is set once per build directory (Day 2), managing several hardware targets simultaneously means several build directories, all sharing `DL_DIR`/`SSTATE_DIR` (Day 2's guidance, now genuinely essential rather than just convenient):

```bash
source oe-init-build-env build-rpi4-dev
# local.conf: MACHINE = "mqtt-monitor-rpi4", building mqtt-monitor-image-dev

source oe-init-build-env build-revb-prod
# local.conf: MACHINE = "mqtt-monitor-custom-revb", building mqtt-monitor-image
```

Because `SSTATE_DIR` is shared, anything genuinely common between these builds (the toolchain itself, any shared library at the same version/config) is only actually compiled once regardless of which build directory triggers it first — Day 8's sstate signature mechanism working exactly as designed across what looks like entirely separate build efforts.

## `BBMULTICONFIG` — building multiple configurations from _one_ bitbake invocation

For genuinely simultaneous multi-target builds (useful in CI, Day 28 territory) rather than separate manually-managed build directories:

```
conf/multiconfig/rpi4-dev.conf
conf/multiconfig/revb-prod.conf
```

```bitbake
# conf/multiconfig/revb-prod.conf
MACHINE = "mqtt-monitor-custom-revb"
DISTRO = "mqtt-monitor-distro"
```

```bitbake
# local.conf
BBMULTICONFIG = "rpi4-dev revb-prod"
```

```bash
bitbake mc:rpi4-dev:mqtt-monitor-image-dev mc:revb-prod:mqtt-monitor-image
```

One `bitbake` invocation builds both targets, still within a single build directory, sharing the parse cache and sstate more tightly than separate build directories would. This is genuinely more advanced/less commonly needed day-to-day than separate build directories, but it's the correct answer once you're building a genuine CI matrix across your product line and want it as one coordinated invocation rather than N separate scripted `bitbake` calls.

## Practical guidance on when to introduce this structure

Don't over-engineer this from day one — if you currently have exactly one hardware target and one image variant (your actual Day 7 QEMU walkthrough state), plain `local.conf` settings are completely fine. The `.inc`-factored hierarchy earns its complexity the moment you have a **second** genuine variant of either axis (second machine, or second image variant) — that's the actual trigger point, not a fixed project size or arbitrary timeline. Introducing this structure prematurely (for a single-hardware, single-image project) is pure unnecessary abstraction; introducing it reactively once you hit the second variant, retrofitting the first variant into the shared `.inc` pattern at that point, is the pragmatic real-world approach.

## Key takeaways

- The core discipline: factor anything genuinely shared into `.inc` files; concrete `.conf`/`.bb` files become thin compositions (`require` + real deltas) — same DRY principle as source code, applied to configuration.
- Write your **own** distro layer (not `poky` directly) for real product work — it's where organization-wide policy (version, features, libc choice) lives independent of scattered `local.conf` settings.
- Image variants (prod/dev/staging) follow the identical `require`+`.inc` pattern as machine variants — both axes are independent and compose the same way.
- Multiple build directories sharing `DL_DIR`/`SSTATE_DIR` is the standard way to work across several hardware targets in parallel without redundant compilation.
- `BBMULTICONFIG` is the single-invocation, CI-oriented answer to the same problem — more advanced, reach for it specifically when coordinating a genuine build matrix.
- Introduce this structure reactively, at the second genuine variant (of either machine or image axis) — not preemptively for a single-target project.

