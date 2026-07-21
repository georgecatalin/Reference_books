[[Foundations]]

# Day 7: Full Walkthrough — Building a Custom Image for QEMU, End to End

This day ties together Days 1–6 into one continuous, real build — from empty directory to a booted custom image with your own layer, your own recipe, and your own image definition. No new concepts here; this is deliberate practice of the full pipeline so the mechanics become muscle memory before we go deeper into BitBake internals on Day 8.

## Step 1: Environment setup

```bash
mkdir -p ~/yocto-shared/downloads ~/yocto-shared/sstate-cache
cd ~/
git clone -b scarthgap git://git.yoctoproject.org/poky
git clone -b scarthgap https://git.openembedded.org/meta-openembedded
cd poky
source oe-init-build-env build-qemu
```

## Step 2: Add layers

```bash
bitbake-layers add-layer ../../meta-openembedded/meta-oe
bitbake-layers add-layer ../../meta-openembedded/meta-python
bitbake-layers add-layer ../../meta-openembedded/meta-networking
bitbake-layers show-layers
```

Confirm output lists `core`, `yocto`, `openembedded-layer` (meta-oe), `meta-python`, `networking-layer`, each with a priority number — if any are missing, the `add-layer` command failed silently on a bad path; check again.

## Step 3: Create your own layer

Use the helper tool rather than hand-building the skeleton:

```bash
bitbake-layers create-layer ../../meta-mqtt-monitor
bitbake-layers add-layer ../../meta-mqtt-monitor
```

This generates `meta-mqtt-monitor/conf/layer.conf` pre-filled correctly (priority 6 by default, `LAYERSERIES_COMPAT` set to your current branch), plus an example recipe you can delete. Inspect what it made:

```bash
cat ../../meta-mqtt-monitor/conf/layer.conf
```

## Step 4: Write a real recipe — a minimal C serial-echo utility

For this walkthrough, a small self-contained program (rather than fetching your actual `mqtt_monitor` repo) so the mechanics are visible without git/network dependencies complicating the picture.

```bash
mkdir -p ../../meta-mqtt-monitor/recipes-mqtt/hello-monitor/files
```

`files/hello-monitor.c`:

```c
#include <stdio.h>
#include <unistd.h>

int main(void) {
    while (1) {
        printf("hello-monitor: heartbeat\n");
        fflush(stdout);
        sleep(5);
    }
    return 0;
}
```

`files/hello-monitor.service`:

```ini
[Unit]
Description=Hello Monitor heartbeat service

[Service]
ExecStart=/usr/bin/hello-monitor
Restart=always

[Install]
WantedBy=multi-user.target
```

Now the recipe itself, `recipes-mqtt/hello-monitor/hello-monitor_1.0.bb`:

```bitbake
SUMMARY = "Minimal heartbeat service for pipeline walkthrough"
LICENSE = "MIT"
LIC_FILES_CHKSUM = "file://${COMMON_LICENSE_DIR}/MIT;md5=0835ade698e0bcf8506ecda2f7b4f302"

SRC_URI = "file://hello-monitor.c \
           file://hello-monitor.service"

S = "${WORKDIR}"

inherit systemd

SYSTEMD_SERVICE:${PN} = "hello-monitor.service"
SYSTEMD_AUTO_ENABLE:${PN} = "enable"

do_compile() {
    ${CC} ${CFLAGS} ${LDFLAGS} -o hello-monitor ${WORKDIR}/hello-monitor.c
}

do_install() {
    install -d ${D}${bindir}
    install -m 0755 hello-monitor ${D}${bindir}/hello-monitor

    install -d ${D}${systemd_unitdir}/system
    install -m 0644 ${WORKDIR}/hello-monitor.service ${D}${systemd_unitdir}/system/hello-monitor.service
}
```

Points worth noticing, tying back to Day 4 concepts directly:

- No `inherit cmake`/`autotools` here — `do_compile`/`do_install` are written fully manually since this is a trivial single-file program. This is the "raw recipe" pattern you fall back to for anything that isn't a standard build system.
- `LIC_FILES_CHKSUM` points at `${COMMON_LICENSE_DIR}/MIT` — OE-Core ships standard license texts for common SPDX licenses; you reference those instead of shipping your own copy for well-known licenses.
- `inherit systemd` pulls in systemd packaging helpers; `SYSTEMD_SERVICE`/`SYSTEMD_AUTO_ENABLE` are the proper mechanism mentioned on Day 6 as preferable to manual `ROOTFS_POSTPROCESS_COMMAND` symlinking.

Verify BitBake sees it:

```bash
bitbake-layers show-recipes hello-monitor
```

## Step 5: Write the image recipe

```bash
mkdir -p ../../meta-mqtt-monitor/recipes-images/images
```

`recipes-images/images/mqtt-monitor-image.bb`:

```bitbake
SUMMARY = "Walkthrough image for MQTT monitor pipeline"
LICENSE = "MIT"

inherit core-image

CORE_IMAGE_EXTRA_INSTALL += " \
    hello-monitor \
    openssh \
    "

IMAGE_FEATURES += "ssh-server-openssh"

IMAGE_ROOTFS_EXTRA_SPACE = "524288"

IMAGE_FSTYPES = "ext4 wic.bz2"
```

## Step 6: Configure `local.conf`

```bitbake
MACHINE = "qemux86-64"
DISTRO = "poky"

DL_DIR ?= "/home/george/yocto-shared/downloads"
SSTATE_DIR ?= "/home/george/yocto-shared/sstate-cache"

PACKAGE_CLASSES = "package_ipk"

BB_NUMBER_THREADS = "8"
PARALLEL_MAKE = "-j 8"

BB_DISKMON_DIRS = "\
    STOPTASKS,${TMPDIR},1G,100K \
    STOPTASKS,${DL_DIR},1G,100K \
    STOPTASKS,${SSTATE_DIR},1G,100K \
    HALT,${TMPDIR},100M,1K"
```

## Step 7: Build

```bash
bitbake mqtt-monitor-image
```

Watch for parse-time errors first (these show up almost instantly, before any real building starts) — a missing `LICENSE`, a bad `SRC_URI` path, or a layer priority conflict will surface here. If parsing succeeds, BitBake moves into task execution — this is where the bulk of the time goes.

**If `do_compile` fails for `hello-monitor`:** run

```bash
bitbake hello-monitor -c devshell
```

and manually try `${CC} ${CFLAGS} -o hello-monitor hello-monitor.c` inside that shell — this immediately reveals whether it's a genuine compile error vs. an environment/path issue.

**If `do_rootfs` fails:** this is a package dependency resolution failure (per Day 6) — the error will explicitly name the unsatisfied dependency; check that the offending package is spelled correctly and provided by an active layer (`bitbake-layers show-recipes <name>`).

## Step 8: Boot and verify

```bash
runqemu qemux86-64 nographic
```

`nographic` gives you a console directly in your terminal instead of spawning a QEMU GUI window — usually the more practical mode when working over SSH or without a desktop environment.

Once logged in (root, no password):

```bash
systemctl status hello-monitor
journalctl -u hello-monitor -f
```

You should see the heartbeat log lines appearing every 5 seconds, confirming: recipe compiled correctly, systemd unit installed and auto-enabled correctly, and the full layer → recipe → image → boot pipeline works end to end.

Exit QEMU: `Ctrl-A` then `X` (in `nographic` console mode).

## What you just proved end-to-end

- A custom layer (`meta-mqtt-monitor`) correctly registered and prioritized
- A from-scratch recipe with manual `do_compile`/`do_install` (no framework class), systemd integration via `inherit systemd`
- A dedicated image recipe (not a `core-image-minimal` append) with `CORE_IMAGE_EXTRA_INSTALL`, `IMAGE_FEATURES`, `IMAGE_ROOTFS_EXTRA_SPACE`
- Debugging entry points: parse-time errors vs. `do_compile` failures (→ `devshell`) vs. `do_rootfs` failures (→ dependency errors)

This is the actual full loop you'll repeat, with more sophistication, for the rest of the curriculum — every subsequent day adds _capability_ to one or more of these same stages (better recipes, kernel/bootloader customization, real hardware BSPs, packaging your real Python/C++ `mqtt_monitor` code) rather than introducing a fundamentally different workflow.

## Key takeaways

- The full pipeline, concretely: layer registration → recipe (fetch/compile/install) → image recipe (package selection) → `do_rootfs`/`do_image` → boot.
- Parse-time errors (license, `SRC_URI` syntax, layer conflicts) surface immediately; task-execution errors (compile, rootfs assembly) surface during the actual build run — learn to distinguish which phase you're debugging.
- `devshell` for compile-level debugging, dependency error messages for rootfs-level debugging — these are your two primary diagnostic entry points and will remain so through the rest of the curriculum.
- Systemd integration via `inherit systemd` + `SYSTEMD_SERVICE`/`SYSTEMD_AUTO_ENABLE` is the correct pattern (vs. manual rootfs symlinking) — you'll formalize this further on Day 19.

