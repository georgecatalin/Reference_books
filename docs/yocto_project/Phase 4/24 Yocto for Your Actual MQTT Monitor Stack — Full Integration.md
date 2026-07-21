[[Advanced Production]]
# Day 24: Yocto for Your Actual MQTT Monitor Stack — Full Integration

This day ties Days 1–23 directly to your real capstones — both the C++ and Python `mqtt_monitor` implementations — as a complete, production-shaped Yocto integration. Less new mechanism, more "here's exactly how everything so far assembles into your actual product."

## The full recipe for your C++ `mqtt_monitor`, production-complete

Pulling together CMake packaging (Day 11), systemd integration (Day 19), non-root user (Day 19), and multi-machine overrides (Day 9):

```bitbake
# recipes-mqtt/mqtt-monitor-cpp/mqtt-monitor-cpp_1.2.0.bb

SUMMARY = "MQTT device monitor — serial ingestion, SQLite persistence, MQTT publishing"
HOMEPAGE = "https://github.com/georgeco/mqtt-monitor-cpp"
LICENSE = "MIT"
LIC_FILES_CHKSUM = "file://LICENSE;md5=<actual-hash>"

SRC_URI = "git://github.com/georgeco/mqtt-monitor-cpp.git;protocol=https;branch=main"
SRCREV = "<pinned-commit>"
PV = "1.2.0+git"
S = "${WORKDIR}/git"

DEPENDS = "mosquitto sqlite3 boost"
DEPENDS:append:raspberrypi4-64 = " userland"

inherit cmake pkgconfig systemd useradd

PACKAGECONFIG ??= "tls"
PACKAGECONFIG[tls] = "-DENABLE_MQTT_TLS=ON,-DENABLE_MQTT_TLS=OFF,openssl"
PACKAGECONFIG[tests] = "-DBUILD_TESTS=ON,-DBUILD_TESTS=OFF,gtest"

EXTRA_OECMAKE = " \
    -DCMAKE_BUILD_TYPE=Release \
    "
EXTRA_OECMAKE:append:raspberrypi4-64 = " -DENABLE_CAMERA_SUPPORT=ON"

SYSTEMD_SERVICE:${PN} = "mqtt-monitor-cpp.service"
SYSTEMD_AUTO_ENABLE:${PN} = "enable"

USERADD_PACKAGES = "${PN}"
USERADD_PARAM:${PN} = "--system --no-create-home --shell /sbin/nologin mqtt-monitor"
GROUPADD_PARAM:${PN} = "--system mqtt-monitor"

do_install:append() {
    install -d ${D}${systemd_unitdir}/system
    install -m 0644 ${S}/systemd/mqtt-monitor-cpp.service ${D}${systemd_unitdir}/system/mqtt-monitor-cpp.service

    install -d ${D}${sysconfdir}/mqtt-monitor
    install -m 0644 ${S}/config/default.toml ${D}${sysconfdir}/mqtt-monitor/config.toml
}

FILES:${PN} += " \
    ${systemd_unitdir}/system/mqtt-monitor-cpp.service \
    ${sysconfdir}/mqtt-monitor/config.toml \
    "

CONFFILES:${PN} += "${sysconfdir}/mqtt-monitor/config.toml"
```

One new detail worth flagging precisely: **`CONFFILES`**. This tells the package manager "this file is user-editable configuration, not just a regular installed file" — on package upgrade, `opkg`/`rpm` will preserve a locally-modified config file rather than blindly overwriting it (typically saving the new version as `.orig` or prompting, depending on backend). Without this, a package update to `mqtt-monitor-cpp` would silently clobber any on-device configuration changes a field technician made — a real, easy-to-miss production concern that only surfaces the first time you actually ship an update to deployed hardware.

Also worth noting: **`PV = "1.2.0+git"`** — the `+git` suffix is the standard convention signaling "this version string is a human-readable release tag, but the actual content is pinned by `SRCREV`, not derived purely from the version number" — makes clear to anyone reading `PV` later that git pinning, not tag-based fetching, is the actual reproducibility mechanism in play here.

## The full recipe for your Python `mqtt_monitor`, production-complete

Building on Day 19's introduction, with the pieces your actual Python capstone would need (FastAPI, pytest as a dev-only concern, systemd, config file handling):

```bitbake
# recipes-mqtt/mqtt-monitor-py/mqtt-monitor-py_1.0.0.bb

SUMMARY = "MQTT device monitor — Python asyncio implementation"
LICENSE = "MIT"
LIC_FILES_CHKSUM = "file://LICENSE;md5=<actual-hash>"

SRC_URI = "git://github.com/georgeco/mqtt-monitor-py.git;protocol=https;branch=main"
SRCREV = "<pinned-commit>"
S = "${WORKDIR}/git"

inherit setuptools3 systemd useradd

RDEPENDS:${PN} += " \
    python3-paho-mqtt \
    python3-pydantic \
    python3-fastapi \
    python3-uvicorn \
    python3-aiosqlite \
    python3-pyserial \
    "

SYSTEMD_SERVICE:${PN} = "mqtt-monitor-py.service"
SYSTEMD_AUTO_ENABLE:${PN} = "enable"

USERADD_PACKAGES = "${PN}"
USERADD_PARAM:${PN} = "--system --no-create-home --shell /sbin/nologin mqtt-monitor-py"

do_install:append() {
    install -d ${D}${systemd_unitdir}/system
    install -m 0644 ${S}/systemd/mqtt-monitor-py.service ${D}${systemd_unitdir}/system/mqtt-monitor-py.service

    install -d ${D}${sysconfdir}/mqtt-monitor-py
    install -m 0644 ${S}/config/default.toml ${D}${sysconfdir}/mqtt-monitor-py/config.toml
}

FILES:${PN} += " \
    ${systemd_unitdir}/system/mqtt-monitor-py.service \
    ${sysconfdir}/mqtt-monitor-py/config.toml \
    "
CONFFILES:${PN} += "${sysconfdir}/mqtt-monitor-py/config.toml"
```

## The real gap you will hit: not every PyPI package has a recipe

This is the single most likely practical obstacle for your Python capstone specifically. Check systematically, per Day 19's guidance, before committing:

```bash
bitbake-layers show-recipes python3-aiosqlite
bitbake-layers show-recipes python3-pyserial
```

`python3-pyserial` and `python3-paho-mqtt` are common enough to likely exist in `meta-python`. `python3-aiosqlite` is a plausible gap — smaller/newer packages are exactly where `meta-python`'s coverage thins out. When a recipe genuinely doesn't exist, you write one — and this is dramatically easier than Days 10–11's from-scratch C work, because Python packaging classes handle nearly everything:

```bitbake
# recipes-devtools/python/python3-aiosqlite_0.20.0.bb

SUMMARY = "asyncio bridge to SQLite"
HOMEPAGE = "https://github.com/omnilib/aiosqlite"
LICENSE = "MIT"
LIC_FILES_CHKSUM = "file://LICENSE;md5=<hash-from-pypi-sdist>"

SRC_URI = "https://files.pythonhosted.org/packages/source/a/aiosqlite/aiosqlite-${PV}.tar.gz"
SRC_URI[sha256sum] = "<sha256-from-pypi>"

inherit pypi setuptools3

RDEPENDS:${PN} += "python3-typing-extensions"
```

**`inherit pypi`** is the class that matters here — it auto-derives `SRC_URI`/download URL structure from the package name and version (you often don't even need to write the full URL by hand; `inherit pypi` with just `PYPI_PACKAGE = "aiosqlite"` can compute it), pulling from PyPI's standard source-distribution layout. Combined with `setuptools3` (or `python_poetry_core`/`python_hatchling` if the package uses those build backends instead — check the package's actual `pyproject.toml` `[build-system]` section to know which), this is usually a 10-15 line recipe for the overwhelming majority of pure-Python PyPI packages — genuinely one of the lighter recipe-writing tasks in the whole curriculum, specifically because Python packaging metadata is already so structured that BitBake's classes can infer almost everything.

```bash
recipetool create -o python3-aiosqlite_0.20.0.bb https://pypi.org/project/aiosqlite/
```

`recipetool` (Day 12) is particularly effective specifically for PyPI packages — its inference quality here is much higher than for arbitrary C/C++ projects, since Python packaging metadata is standardized in a way generic source trees aren't.

## Both services coexisting — or choosing one

A realistic question at this stage: do you ship both the C++ and Python implementations on the same image, or pick one per deployment? Practical guidance: if both are genuinely still active development/comparison targets (consistent with your stated learning approach across both curricula), it's completely reasonable to include both in a **dev** image variant (Day 23's pattern) for side-by-side testing, while your actual **production** image variant includes only whichever one you've decided is the shipping implementation:

```bitbake
# mqtt-monitor-image-dev.bb
CORE_IMAGE_EXTRA_INSTALL += "mqtt-monitor-cpp mqtt-monitor-py"

# mqtt-monitor-image.bb (production)
CORE_IMAGE_EXTRA_INSTALL += "mqtt-monitor-cpp"
```

Running both simultaneously in dev is fine (they'd need distinct MQTT client IDs/database paths — an application-level concern, not a Yocto one) for genuine comparison testing; shipping both in production is generally not a real architectural choice you'd make (redundant resource usage, unclear which is authoritative) — worth deciding deliberately rather than by default inertia.

## Verifying the full integration on real hardware

```bash
bitbake mqtt-monitor-image-dev
runqemu qemux86-64 nographic
```

On target:

```bash
systemctl status mqtt-monitor-cpp mqtt-monitor-py
journalctl -u mqtt-monitor-cpp -f &
mosquitto_pub -h localhost -t "telemetry/test" -m "hello"
# confirm your monitor actually picks up and processes the message, per its own application logic
sqlite3 /var/lib/mqtt-monitor/monitor.db "SELECT * FROM readings LIMIT 5;"
```

This last check matters — it's confirmation that the _entire_ stack (Yocto-built binary → systemd-managed service → correct user/permissions → actual application logic → SQLite persistence) works end to end on a genuinely Yocto-built image, not just that the binary compiled. This is the point where the abstraction gap between "code that runs on my dev machine" and "code that runs on a device I'd actually ship" closes completely.

## Key takeaways

- Your full production recipe combines nearly every mechanism covered so far: `inherit cmake/setuptools3 systemd useradd`, `PACKAGECONFIG` for optional features, `CONFFILES` for safe config-file upgrade behavior, multi-machine overrides for hardware-specific options.
- `CONFFILES` is a small but genuinely important production detail — prevents package updates from silently clobbering field-modified configuration.
- The realistic obstacle for your Python capstone is missing `python3-*` recipes for less-common PyPI dependencies — but `inherit pypi` + `setuptools3`/`python_poetry_core` makes writing these recipes dramatically easier than C/C++ from-scratch work (Day 10).
- `recipetool create` against a PyPI URL has notably higher inference quality than against arbitrary source repos — lean on it for this specific case.
- Running both C++ and Python implementations side-by-side is reasonable for dev/comparison; shipping both to production is a deliberate architectural decision, not a default.
- The true integration test isn't "does it compile" — it's the full loop: MQTT message in → your application logic → SQLite persistence, verified against a genuinely Yocto-built and systemd-managed image.
