[[Foundations]]

# Day 3: Layers Deep Dive

## What a layer actually is, mechanically

A layer is nothing magical — it's a directory with a specific required structure that BitBake knows how to scan. The one mandatory file:

```
meta-mylayer/
├── conf/
│   └── layer.conf          # REQUIRED — this is what makes it a "layer"
├── recipes-example/
│   └── example/
│       └── example_1.0.bb
├── recipes-mqtt/
│   └── mosquitto/
│       └── mosquitto_%.bbappend
└── classes/                 # optional, custom .bbclass files
```

The `recipes-*` naming is a **convention, not a requirement** — BitBake doesn't care what you call subdirectories; it recursively globs for `.bb`/`.bbappend`/`.conf` files under paths declared in `layer.conf`. But follow the convention anyway — every layer in the ecosystem uses it (`recipes-core`, `recipes-kernel`, `recipes-connectivity`, `recipes-graphics`, etc.), and diverging just makes your layer confusing to anyone else who opens it.

## `conf/layer.conf` — the file that defines a layer

```bitbake
# conf/layer.conf
BBPATH .= ":${LAYERDIR}"

BBFILES += "${LAYERDIR}/recipes-*/*/*.bb \
            ${LAYERDIR}/recipes-*/*/*.bbappend"

BBFILE_COLLECTIONS += "mylayer"
BBFILE_PATTERN_mylayer = "^${LAYERDIR}/"
BBFILE_PRIORITY_mylayer = "6"

LAYERDEPENDS_mylayer = "core"
LAYERSERIES_COMPAT_mylayer = "scarthgap"
```

Breaking down what each line actually does, since this is the file you'll write from scratch constantly:

- **`BBFILES`**: glob patterns telling BitBake where to find `.bb`/`.bbappend` files in _this_ layer. Without this, your recipes exist on disk but BitBake never sees them.
- **`BBFILE_COLLECTIONS`**: registers this layer's name (`mylayer`) into the global list of active collections — this name is what you reference elsewhere (priority, dependencies).
- **`BBFILE_PATTERN_mylayer`**: a regex identifying which files "belong" to this collection — used internally when BitBake needs to know which layer a given `.bb` file came from.
- **`BBFILE_PRIORITY_mylayer`**: the number that matters for conflict resolution (see below).
- **`LAYERDEPENDS_mylayer`**: declares that this layer requires `core` (OE-Core) to be present — BitBake errors out at parse time if a dependency layer isn't in `bblayers.conf`.
- **`LAYERSERIES_COMPAT_mylayer`**: declares which Yocto release codename(s) this layer is compatible with. BitBake will refuse to parse the layer if your poky release isn't listed here — this is the mechanism behind "mismatched branch" errors mentioned Day 2.

## `bblayers.conf` — what's active in _this build_

Lives in your build directory (`build-qemu/conf/bblayers.conf`), not in any layer itself:

```bitbake
POKY_BBLAYERS_CONF_VERSION = "2"

BBPATH = "${TOPDIR}"
BBFILES ?= ""

BBLAYERS ?= " \
  /home/george/poky/meta \
  /home/george/poky/meta-poky \
  /home/george/poky/meta-yocto-bsp \
  /home/george/meta-openembedded/meta-oe \
  /home/george/meta-openembedded/meta-python \
  /home/george/meta-openembedded/meta-networking \
  /home/george/meta-raspberrypi \
  /home/george/meta-mqtt-monitor \
  "
```

This is a **per-build-directory list** — different build directories (e.g. `build-qemu` vs `build-rpi4`) can have completely different layer sets pointing at the same layer checkouts on disk. That's normal and expected: one checkout of `meta-openembedded`, referenced from multiple build configs.

**Managing this file by hand is error-prone** (path typos, forgetting a dependency). In practice you use the helper tool instead:

```bash
bitbake-layers add-layer /home/george/meta-openembedded/meta-oe
bitbake-layers add-layer /home/george/meta-openembedded/meta-python
bitbake-layers show-layers      # lists active layers + priorities
bitbake-layers show-recipes mosquitto   # shows which layer(s) provide a recipe
bitbake-layers show-appends     # shows which .bbappend applies to which .bb
```

`bitbake-layers show-recipes <name>` is something you'll run constantly once you have several layers — it tells you exactly which layer is providing a given recipe and at what version, which is essential once you have overlapping providers (see below).

## Layer priority — how conflicts actually resolve

If two layers both provide a recipe with the same `PN` (package name) — say, `meta-openembedded` ships `mosquitto_2.0.15.bb` and your own layer ships `mosquitto_2.0.18.bb` — BitBake needs to decide which wins.

Two independent axes:

1. **Version**: BitBake prefers the highest version number _unless_ `PREFERRED_VERSION_<pn>` says otherwise.
2. **Layer priority** (`BBFILE_PRIORITY_<collection>`): if there's ambiguity at the same version, or you need to force which layer's recipe is used regardless of version, higher priority number wins.

In practice, you almost never rely on implicit priority resolution for something important — you make it explicit:

```bitbake
# in your local.conf or distro conf
PREFERRED_VERSION_mosquitto = "2.0.18"
PREFERRED_PROVIDER_virtual/kernel = "linux-yocto"
```

`PREFERRED_PROVIDER` is the mechanism for **virtual providers** — cases where multiple recipes could satisfy the same abstract dependency (most commonly `virtual/kernel`, `virtual/bootloader`, `virtual/libc`). BSP layers declare which concrete recipe satisfies these virtuals for a given machine.

## `.bbappend` — modifying a recipe without forking it

This is the mechanism you'll use constantly and is worth understanding precisely, since it's central to almost every customization task from here forward.

Say OE-Core ships `meta/recipes-connectivity/mosquitto/mosquitto_2.0.18.bb` and you want to add a config file and a systemd unit override. You do **not** edit that file directly (it'll get overwritten/conflict on layer updates). Instead, in your own layer:

```
meta-mqtt-monitor/
└── recipes-connectivity/
    └── mosquitto/
        ├── mosquitto_%.bbappend
        └── files/
            └── mosquitto.conf
```

The `%` wildcard in the filename matches any version of the base recipe — `mosquitto_%.bbappend` applies regardless of whether the underlying recipe is `2.0.15` or `2.0.18`. You can also pin an append to an exact version (`mosquitto_2.0.18.bbappend`) if you need version-specific behavior, but the wildcard form is the common default.

Content of the `.bbappend`:

```bitbake
FILESEXTRAPATHS:prepend := "${THISDIR}/files:"

SRC_URI += "file://mosquitto.conf"

do_install:append() {
    install -m 0644 ${WORKDIR}/mosquitto.conf ${D}${sysconfdir}/mosquitto/mosquitto.conf
}
```

Three mechanics worth internalizing here, because they show up in nearly every recipe you'll customize:

- **`FILESEXTRAPATHS:prepend := "${THISDIR}/files:"`** — tells BitBake to also look in this layer's local `files/` directory when resolving `file://` entries in `SRC_URI`. The `:=` (immediate expansion) matters — this needs to be evaluated at parse time, not deferred, because `THISDIR` changes meaning otherwise.
- **`SRC_URI +=`** — appends an additional local file source to the original recipe's `SRC_URI`, without touching the original recipe.
- **`do_install:append()`** — this is the modern (post-3.4/Kirkstone) override syntax. It runs _additional_ shell code after the original recipe's `do_install` completes. The colon-syntax (`:append`, `:prepend`, `:remove`) replaced the old underscore syntax (`_append`, `_prepend`) — if you see `_append` in any tutorial or Stack Overflow answer, it's older documentation; both still parse in most current releases via a compatibility layer, but colon syntax is what you should write.

## `.conf` files — distro and machine configuration layers

Not all `.conf` files are `local.conf`/`bblayers.conf`. Layers themselves ship configuration:

- **`conf/machine/*.conf`** — machine definitions (`raspberrypi4-64.conf`, `beaglebone-yocto.conf`). Sets `TUNE_FEATURES`, `PREFERRED_PROVIDER_virtual/kernel`, serial console, etc.
- **`conf/distro/*.conf`** — distro policy (`poky.conf`, or your own `mydistro.conf`). Sets C library choice (glibc vs musl), init system, package format, feature backends.

You select these via `local.conf`:

```
MACHINE = "raspberrypi4-64"
DISTRO = "poky"
```

Both `MACHINE` and `DISTRO` names correspond to filenames (minus `.conf`) that must exist somewhere in an active layer's `conf/machine/` or `conf/distro/` directory — if you set `MACHINE = "raspberrypi5"` but no layer provides `raspberrypi5.conf`, you get an immediate, clear parse error.

## Practical workflow for your situation

For your MQTT monitor / BeagleBone / RPi work, your real layer stack will look like:

```
meta                      (OE-Core — always)
meta-poky                 (Poky distro policy — or your own distro layer replacing this)
meta-yocto-bsp            (only if targeting genuine reference machines)
meta-openembedded/meta-oe
meta-openembedded/meta-python     (needed: paho-mqtt, pydantic, fastapi packaging)
meta-openembedded/meta-networking (needed: mosquitto, other networking recipes)
meta-raspberrypi          (RPi BSP — separate git repo, not part of meta-openembedded)
meta-mqtt-monitor          (yours — application recipes + customizations)
```

`meta-raspberrypi` and `meta-openembedded` are **separate git repositories** you clone independently, each with their own branch that must match your poky branch:

```bash
git clone -b scarthgap git://git.yoctoproject.org/meta-raspberrypi
git clone -b scarthgap https://git.openembedded.org/meta-openembedded
```

## Key takeaways

- A layer = directory + `conf/layer.conf`. `BBFILES`, `BBFILE_COLLECTIONS`, `BBFILE_PRIORITY`, `LAYERDEPENDS`, `LAYERSERIES_COMPAT` are the fields that matter.
- `bblayers.conf` lives per-build-directory and lists which layers are active for that specific build — manage it with `bitbake-layers add-layer`, not by hand.
- Conflicts between layers resolve via version (`PREFERRED_VERSION`) and priority (`BBFILE_PRIORITY`); virtual dependencies resolve via `PREFERRED_PROVIDER`.
- `.bbappend` is how you customize recipes without forking — `FILESEXTRAPATHS`, `SRC_URI +=`, and `:append`/`:prepend`/`:remove` task overrides are the three mechanics you'll use in nearly every customization.
- Always match layer branches to your poky branch — `LAYERSERIES_COMPAT` enforces this at parse time.
- `bitbake-layers show-layers` / `show-recipes` / `show-appends` are your diagnostic tools — use them before guessing.
