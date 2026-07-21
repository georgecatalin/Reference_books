[[Advanced Production]]
# Day 30: Capstone — Full Custom Distro Layer for Your IoT Device

This is the synthesis day — no new mechanisms, everything from Days 1–29 assembled into one coherent, complete project structure. This is what a real, production-shaped Yocto BSP for your MQTT monitor product actually looks like end to end, as a reference architecture you can build from directly.

## The complete layer/repository structure

```
mqtt-monitor-yocto/                          # top-level project repo (contains the kas file + your own layer)
├── kas-mqtt-monitor.yml                     # Day 28 — declarative build config
├── kas-mqtt-monitor.lock.yml                 # Day 28 — pinned commits for releases
├── .github/workflows/build.yml               # Day 28 — CI pipeline
│
├── meta-mqtt-monitor-distro/                 # Day 23 — your distro policy layer
│   ├── conf/
│   │   ├── layer.conf
│   │   └── distro/
│   │       ├── mqtt-monitor-distro.conf
│   │       └── include/
│   │           └── mqtt-monitor-distro-common.inc
│
├── meta-mqtt-monitor-bsp/                     # Day 15 — your BSP layer
│   ├── conf/
│   │   ├── layer.conf
│   │   └── machine/
│   │       ├── include/
│   │       │   └── mqtt-monitor-common.inc     # Day 23 — shared machine config
│   │       ├── mqtt-monitor-rpi4.conf
│   │       ├── mqtt-monitor-bbb.conf
│   │       └── mqtt-monitor-custom-revb.conf
│   ├── recipes-kernel/
│   │   └── linux/
│   │       ├── linux-yocto_%.bbappend          # Day 16 — kernel config fragments
│   │       └── files/
│   │           ├── mqtt-monitor-serial.cfg
│   │           ├── rs485-support.cfg
│   │           ├── imx8mm-my-board-revb.dts     # Day 18 — device tree
│   │           └── imx8mm-my-board-pinctrl.dtsi
│   ├── recipes-bsp/
│   │   └── u-boot/
│   │       ├── u-boot-my-board_%.bbappend        # Day 17 — bootloader
│   │       └── files/
│   │           └── my-board-uboot-fixup.cfg
│   └── wic/
│       └── mqtt-monitor-board.wks                # Day 15/17/26 — partition layout
│
├── meta-mqtt-monitor-app/                      # Day 3/24 — application layer
│   ├── conf/
│   │   └── layer.conf
│   ├── recipes-mqtt/
│   │   ├── mqtt-monitor-cpp/
│   │   │   ├── mqtt-monitor-cpp_1.2.0.bb        # Day 24 — C++ service recipe
│   │   │   └── files/
│   │   ├── mqtt-monitor-py/
│   │   │   ├── mqtt-monitor-py_1.0.0.bb          # Day 24 — Python service recipe
│   │   │   └── files/
│   │   └── mosquitto/
│   │       ├── mosquitto_%.bbappend               # Day 3/11 — PACKAGECONFIG tweaks
│   │       └── files/
│   ├── recipes-devtools/
│   │   └── python/
│   │       └── python3-aiosqlite_0.20.0.bb        # Day 24 — missing PyPI recipe, self-authored
│   └── recipes-images/
│       └── images/
│           ├── mqtt-monitor-image-base.inc         # Day 23 — shared image content
│           ├── mqtt-monitor-image.bb                # production
│           ├── mqtt-monitor-image-dev.bb            # dev variant
│           └── mqtt-monitor-image-staging.bb        # staging variant
│
└── keys/                                        # Day 25 — signing keys (private key NOT actually committed here — see below)
    ├── mqtt-monitor-fit-key.crt
    └── rauc-cert.pem
```

**Critical note on that `keys/` directory**: the _public_ certs/keys used for verification can reasonably live in version control; the _private_ signing keys (Day 25's explicit warning) must never be committed to this repo — they live on a separate, access-controlled signing system, referenced by path/secret-manager reference in CI, not checked in alongside the layer source.

## The complete `kas-mqtt-monitor.yml`, final form

```yaml
header:
  version: 14

machine: mqtt-monitor-custom-revb
distro: mqtt-monitor-distro

repos:
  poky:
    url: "https://git.yoctoproject.org/poky"
    branch: scarthgap
    path: layers/poky

  meta-openembedded:
    url: "https://git.openembedded.org/meta-openembedded"
    branch: scarthgap
    path: layers/meta-openembedded
    layers:
      meta-oe:
      meta-python:
      meta-networking:

  meta-mqtt-monitor-distro:
    path: meta-mqtt-monitor-distro
  meta-mqtt-monitor-bsp:
    path: meta-mqtt-monitor-bsp
  meta-mqtt-monitor-app:
    path: meta-mqtt-monitor-app

local_conf_header:
  base: |
    PACKAGE_CLASSES = "package_ipk"
    BB_NUMBER_THREADS = "16"
    PARALLEL_MAKE = "-j 8"
    DL_DIR ?= "/opt/yocto-shared/downloads"
    SSTATE_DIR ?= "/opt/yocto-shared/sstate-cache"
    SSTATE_MIRRORS = "file://.* http://sstate.internal.example.com/sstate-cache/PATH;downloadfilename=PATH"
    BB_HASHSERVE = "auto"
    INHERIT += "rm_work buildstats"
    RM_WORK_EXCLUDE += "mqtt-monitor-cpp mqtt-monitor-py linux-yocto"
    BB_DISKMON_DIRS = "STOPTASKS,${TMPDIR},1G,100K STOPTASKS,${DL_DIR},1G,100K STOPTASKS,${SSTATE_DIR},1G,100K HALT,${TMPDIR},100M,1K"
```

Notice: this single file references essentially every mechanism from Days 2, 5, 8, 20, 23, 25, and 27 — the entire curriculum's configuration-level lessons collapse into roughly 15 lines because each represents a deliberate, understood decision rather than cargo-culted boilerplate. This is the actual mark of having internalized the material — not memorizing more options, but knowing precisely why each line is there.

## The distro policy file, final form

```bitbake
# meta-mqtt-monitor-distro/conf/distro/mqtt-monitor-distro.conf
require conf/distro/include/mqtt-monitor-distro-common.inc

DISTRO = "mqtt-monitor-distro"
DISTRO_NAME = "MQTT Monitor Product Distro"
DISTRO_VERSION = "2.1.0"

DISTRO_FEATURES:append = " systemd wifi"
DISTRO_FEATURES_BACKFILL_CONSIDERED += " sysvinit"
VIRTUAL-RUNTIME_init_manager = "systemd"

TCLIBC = "glibc"

PREFERRED_VERSION_linux-yocto ?= "6.6%"

INHERIT += "sign_package_feed"
PACKAGE_FEED_GPG_NAME = "mqtt-monitor-release-key"
```

## The production image recipe, final form

```bitbake
# meta-mqtt-monitor-app/recipes-images/images/mqtt-monitor-image.bb
require mqtt-monitor-image-base.inc

EXTRA_IMAGE_FEATURES += "read-only-rootfs"
INHERIT += "rauc"
RAUC_KEY_FILE = "${MQTT_MONITOR_SIGNING_KEY}"
RAUC_CERT_FILE = "${MQTT_MONITOR_SIGNING_CERT}"

CORE_IMAGE_EXTRA_INSTALL += "mqtt-monitor-cpp"
```

```bitbake
# mqtt-monitor-image-base.inc
inherit core-image

CORE_IMAGE_EXTRA_INSTALL += " \
    mosquitto \
    openssh \
    "

IMAGE_ROOTFS_EXTRA_SPACE = "1048576"
IMAGE_LINGUAS = "en-us"
IMAGE_FSTYPES = "wic.bz2"
```

## The complete build-to-field lifecycle, narrated end to end

This is the actual point of the capstone — tracing one complete cycle through everything the curriculum covered:

1. **Developer writes code** — commits land in `mqtt-monitor-cpp`'s own repo (Day 21's guidance: your own project, no Yocto patches, just `SRCREV` bumps).
2. **Recipe bump** — `meta-mqtt-monitor-app`'s `mqtt-monitor-cpp_1.2.0.bb` gets its `SRCREV` updated to the new commit, committed to the layer repo.
3. **CI triggers** (Day 28) — `kas build` runs on a self-hosted runner with persistent `SSTATE_DIR`, mostly cache hits except the actually-changed recipe and anything downstream of it (Day 8's signature mechanism at work).
4. **`testimage`/`oeqa` runs** (Day 28) — boots the built image in QEMU, verifies `mqtt-monitor-cpp.service` is active and actually processes a test MQTT message end to end (Day 24's manual check, now automated).
5. **On success**: image + `.raucb` bundle archived, `.manifest` archived (Day 20/28) — full audit trail of exactly what's in this build.
6. **Release tagged** — `kas dump --resolve-refs` locks exact commits (Day 28), the lockfile committed and tagged alongside the release version.
7. **Signing** (Day 25) — on a separate, access-controlled signing system, the `.raucb` bundle gets signed with the private release key, never exposed to the CI build machine itself.
8. **Deployment trigger** — your existing MQTT infrastructure signals devices that an update is available (Day 26's RAUC-over-MQTT pattern, chosen specifically because you already have this channel).
9. **Device applies update** — `rauc install`, writes to inactive bank, verifies signature against the public cert baked into the image, reboots.
10. **Health check** (Day 26) — RAUC's hook confirms `mqtt-monitor-cpp.service` reached active state and connected to the broker within the configured window; marks the new bank permanent, or rolls back automatically on failure.
11. **Field operation** — read-only rootfs (Day 20/26) means the running system can't drift from what was tested; the writable `/data` partition holds the SQLite telemetry database (Day 26's application-level adaptation).
12. **If something goes wrong in the field** — UART console access (Day 17) plus `journalctl`/`systemctl` (Day 19) on a debug-enabled unit gives you the same diagnostic tools used throughout development, because the fundamental system is identical to what you validated in Days 1–29, not a mysterious black box.

## What you've actually built, looking back across 30 days

Starting from Day 1's "Yocto is a build system, not a distro," you now have a complete, coherent mental model spanning: the metadata/task execution engine itself (BitBake, Days 4/8/9), the composition mechanisms that keep a multi-hardware/multi-variant product maintainable without configuration drift (layers, overrides, `.inc` factoring — Days 3/9/23), the full hardware bring-up chain (kernel, bootloader, device tree — Days 16/17/18), the application-integration layer that's directly your own capstones (Days 11/19/24), the production concerns that separate a working prototype from a shippable product (signing, read-only rootfs, OTA — Days 25/26), and the engineering discipline (performance, CI, systematic debugging — Days 27/28/29) that makes this sustainable as an ongoing practice rather than a one-time build.

## Where to go from here

A few genuine next steps worth naming, since the 30-day structure necessarily drew boundaries around real depth that exists beyond it:

- **`ptest` integration** — running your actual GoogleTest/pytest suites as part of the Yocto build itself (flagged briefly Day 11), worth a dedicated deep dive if CI-driven test coverage of your application logic (not just boot/smoke tests) becomes a priority.
- **Kubernetes/container workloads on embedded** — if your product ever needs multi-container orchestration on-device (less likely for a single MQTT monitor service, more relevant if the product grows additional independent services), this bridges from your existing Docker curriculum's Day 30 conceptual note into genuinely new territory (k3s on Yocto-built images is a real, common pattern).
- **Yocto Project's own documentation** (docs.yoctoproject.org) as ongoing reference — the mental models from these 30 days make that reference documentation genuinely readable now, in a way it likely wasn't as a cold start on Day 1.
- **The actual meta-freescale/meta-ti/meta-xilinx (or whichever SoC vendor you settle on for custom hardware) documentation** — Day 15/17/18's "start from reference BSP" guidance means your real next concrete step, once hardware is chosen, is that vendor's specific BSP layer documentation.

