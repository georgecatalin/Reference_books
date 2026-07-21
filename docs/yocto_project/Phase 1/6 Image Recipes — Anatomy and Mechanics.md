[[Foundations]]

# Day 6: Image Recipes — Anatomy and Mechanics

## Why image recipes are structurally different

Every recipe you wrote or modified in Days 3–5 produces packages destined for a filesystem. An **image recipe** is the recipe that actually assembles those packages into a bootable root filesystem. Mechanically it's still a `.bb` file and still goes through a task pipeline — but the pipeline's shape is different, and it inherits a different class.

```bitbake
inherit core-image
```

or, for a more minimal starting point:

```bitbake
inherit image
```

`core-image.bbclass` (which itself inherits `image.bbclass`) is what `core-image-minimal`, `core-image-full-cmdline`, `core-image-sato`, etc. are built from. Understanding the difference matters: `image.bbclass` gives you the bare mechanics of rootfs assembly; `core-image.bbclass` adds the `IMAGE_FEATURES` abstraction layer (dbg-pkgs, ssh-server-*, tools-debug, package-management, etc.) on top.

## The image-specific task pipeline

```
do_rootfs         → the core task: installs selected packages into a staged rootfs dir
   ├── package manager backend runs (opkg/rpm/apt) to resolve + install IMAGE_INSTALL packages
   ├── post-install package scripts run
   ├── ROOTFS_POSTPROCESS_COMMAND hooks run (your custom rootfs tweaks — see below)
   └── package manager metadata cleaned up (unless package-management feature kept)
do_image           → converts the assembled rootfs into IMAGE_FSTYPES formats (ext4, wic, tar, etc.)
do_image_complete  → final packaging/compression, signing hooks if configured
do_deploy          → copies final artifacts to tmp/deploy/images/<machine>/
```

`do_rootfs` is the one worth understanding deeply because it's where most "why isn't my package showing up on target" bugs live. It resolves `IMAGE_INSTALL` (plus feature-derived package lists) through the actual package manager backend you selected (`PACKAGE_CLASSES`), meaning: if a package's own `RDEPENDS` can't be satisfied (missing recipe, wrong feed), `do_rootfs` fails with a dependency resolution error — not a generic build failure. Read that error like an `apt`/`opkg` dependency error, because that's literally what it is.

## Writing a real custom image recipe

Instead of just appending to `core-image-minimal` via `local.conf` (fine for quick iteration, not great for a real product), you write your own image recipe. This is the actual pattern used in production:

```bitbake
# recipes-images/images/mqtt-monitor-image.bb

SUMMARY = "Custom image for MQTT device monitor deployment"
LICENSE = "MIT"

inherit core-image

IMAGE_FEATURES += "ssh-server-openssh package-management"

CORE_IMAGE_EXTRA_INSTALL += " \
    mqtt-monitor \
    mosquitto \
    python3-paho-mqtt \
    python3-pydantic \
    openssh \
    sqlite3 \
    "

IMAGE_ROOTFS_EXTRA_SPACE = "1048576"

IMAGE_FSTYPES = "wic.bz2 tar.bz2"
```

Why a dedicated image recipe over `local.conf` appends: it's version-controlled, shareable, and composable — you can have `mqtt-monitor-image.bb` for production and `mqtt-monitor-image-dev.bb` (which `require`s the base and adds debug tooling) without touching shared config. This is the pattern that scales to multiple product variants (Day 23 territory).

`IMAGE_ROOTFS_EXTRA_SPACE` is worth flagging specifically — Yocto by default sizes the rootfs to exactly what's needed plus a small margin. Without headroom, your device runs out of disk space the first time it writes a log file, downloads an OTA payload, or your SQLite database (from your `mqtt_monitor` capstone) grows. Set this deliberately based on real expected runtime disk usage, not the build-time default.

## `require` vs `inherit` — a distinction you need precisely

```bitbake
# mqtt-monitor-image-dev.bb
require mqtt-monitor-image.bb

EXTRA_IMAGE_FEATURES += "debug-tweaks tools-debug dbg-pkgs"
IMAGE_INSTALL:append = " strace gdb tcpdump"
```

- **`require`**: textually includes another `.bb`/`.inc` file's content at that point — this is how you build variant images sharing a common base without duplicating content. If the required file is missing, this is a hard parse error.
- **`include`**: same mechanism but doesn't hard-fail if the file is missing (softer — mostly used for optional config fragments).
- **`inherit`**: pulls in a `.bbclass`, which provides _behavior_ (task definitions, default variable values) — conceptually different from `require`/`include`, which just textually splice in more recipe content.

Common convention: put shared logic in a `.inc` file, `require` it from multiple `.bb` variants:

```
mqtt-monitor-image.inc      # shared IMAGE_INSTALL, features
mqtt-monitor-image.bb       # require .inc, production-specific bits
mqtt-monitor-image-dev.bb   # require .inc, dev-specific bits
```

## `ROOTFS_POSTPROCESS_COMMAND` — post-processing the assembled rootfs

Sometimes you need to run arbitrary logic against the fully-assembled rootfs after packages are installed but before it's converted into an image format — e.g., enabling a systemd service by default, removing an unwanted file, fixing permissions:

```bitbake
mqtt_monitor_enable_service() {
    mkdir -p ${IMAGE_ROOTFS}${sysconfdir}/systemd/system/multi-user.target.wants
    ln -sf ${systemd_unitdir}/system/mqtt-monitor.service \
        ${IMAGE_ROOTFS}${sysconfdir}/systemd/system/multi-user.target.wants/mqtt-monitor.service
}

ROOTFS_POSTPROCESS_COMMAND += "mqtt_monitor_enable_service; "
```

`${IMAGE_ROOTFS}` here is the equivalent of `${D}` in a normal recipe — the staged rootfs root, at image-build time rather than package-build time. Note this manual systemd-enable pattern is a fallback — the cleaner mechanism is `SYSTEMD_AUTO_ENABLE = "enable"` set inside the _service's own recipe_ (Day 19 covers this properly); reach for `ROOTFS_POSTPROCESS_COMMAND` for rootfs-level tweaks that don't belong to any single package.

## `IMAGE_INSTALL` vs `CORE_IMAGE_EXTRA_INSTALL` — when to use which

Both add packages to the image, but:

- `IMAGE_INSTALL` — the base variable, use in `local.conf` or when you want to fully control/override the list (`IMAGE_INSTALL = "..."` without `:append` replaces it entirely — dangerous if done carelessly, since `core-image.bbclass` seeds a default list you'd be discarding).
- `CORE_IMAGE_EXTRA_INSTALL` — safer additive variable specifically meant for use _inside_ image recipes that inherit `core-image`, designed not to clobber the base default set.

Practical rule: inside an image `.bb` file, use `CORE_IMAGE_EXTRA_INSTALL +=`. In `local.conf` for quick dev iteration, `IMAGE_INSTALL:append =` (never plain `IMAGE_INSTALL =`).

## Building and verifying your custom image

```bash
bitbake mqtt-monitor-image
runqemu qemux86-64 <or your machine's runqemu profile if it has one>
```

Once booted, verify the actual content landed correctly:

```bash
opkg list-installed | grep mqtt
systemctl status mqtt-monitor    # once you've done Day 19's systemd integration
```

## A note on `core-image-minimal` vs building fully custom

`core-image-minimal` is a fine starting point for Day 1's QEMU test, but for real product work you generally do **not** build on top of `core-image-minimal` forever — you either write your own base image recipe inheriting `core-image` directly (as shown above), or, for genuinely minimal footprints, inherit `image.bbclass` directly and hand-pick everything (no default package set at all). The latter is more work but gives you precise control over image size — relevant if you're flashing small eMMC/SD targets and every megabyte matters.

## Key takeaways

- Image recipes inherit `core-image` (feature-aware) or bare `image` (fully manual) — different task pipeline centered on `do_rootfs` → `do_image` → `do_image_complete` → `do_deploy`.
- `do_rootfs` failures are package manager dependency-resolution errors — read them as such.
- Write dedicated image recipes for real products instead of permanently appending to `local.conf` — enables variant images (prod/dev) via `require` + `.inc` shared fragments.
- `IMAGE_ROOTFS_EXTRA_SPACE` — set deliberately; default sizing has no headroom for logs, databases, or OTA staging.
- `CORE_IMAGE_EXTRA_INSTALL` is the safe additive variable inside image recipes; avoid `IMAGE_INSTALL = "..."` (bare assignment) since it silently discards the class's default set.
- `ROOTFS_POSTPROCESS_COMMAND` is for rootfs-wide post-processing that doesn't belong to a single package's recipe.

Continuing to Day 7 (full walkthrough: building your first fully custom image for QEMU, tying Days 1–6 together end to end) next.