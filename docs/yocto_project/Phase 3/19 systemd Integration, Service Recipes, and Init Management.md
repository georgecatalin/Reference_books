[[Practical Systems Work]]
# Day 19: systemd Integration, Service Recipes, and Init Management

Days 6, 7, and 11 all used `inherit systemd` in passing. This day covers it completely — the full mechanism, service file conventions, dependency ordering, socket activation, and how to debug a service that "should" start but doesn't. This is directly the mechanism your production `mqtt_monitor` will rely on for both the C++ and Python builds.

## `inherit systemd` — what it actually provides

```bitbake
inherit systemd

SYSTEMD_SERVICE:${PN} = "mqtt-monitor.service"
SYSTEMD_AUTO_ENABLE:${PN} = "enable"
SYSTEMD_PACKAGES = "${PN}"
```

Mechanically, `systemd.bbclass`:

1. Ensures your `.service` file (wherever you installed it in `do_install`, typically `${systemd_unitdir}/system/`) is correctly claimed by the package named in `SYSTEMD_PACKAGES` (defaults to `${PN}` if unset, so you often don't need this line explicitly).
2. Wires `pkg_postinst`/`pkg_postrm` scriptlets that call `systemctl enable`/`systemctl disable` **at image-build rootfs time** (not first-boot) if `SYSTEMD_AUTO_ENABLE` is `"enable"` — this is what makes your service already-enabled the moment the device boots for the first time, without any first-boot provisioning step.
3. Adds appropriate `RDEPENDS`/ordering so your package correctly depends on systemd being present.

`SYSTEMD_AUTO_ENABLE` values: `"enable"` (enabled by default, boots and starts automatically), `"disable"` (installed but not enabled — a systemd unit that exists on-disk but requires explicit `systemctl enable` to activate). Use `"disable"` for genuinely optional services you want present but not auto-started (e.g., a diagnostic/debug service you only enable manually during bring-up).

## Writing a correct systemd unit for a real service — your actual mqtt-monitor case

```ini
[Unit]
Description=MQTT Device Monitor Service
Documentation=https://github.com/georgeco/mqtt-monitor-cpp
After=network-online.target mosquitto.service
Wants=network-online.target
Requires=mosquitto.service

[Service]
Type=notify
ExecStart=/usr/bin/mqtt-monitor --config /etc/mqtt-monitor/config.toml
Restart=on-failure
RestartSec=5
User=mqtt-monitor
Group=mqtt-monitor
WatchdogSec=30
StateDirectory=mqtt-monitor
LogsDirectory=mqtt-monitor

[Install]
WantedBy=multi-user.target
```

Fields worth understanding precisely, since these are the ones that actually determine correct real-world boot behavior:

- **`After=network-online.target mosquitto.service`**: ordering only — doesn't create a dependency, just says "if both are going to start anyway, start this after them." Without this, your service might race the MQTT broker and fail its first connection attempt at boot (a very common flaky-boot bug — service technically "works" but fails on cold boot because it started before its dependency was ready).
- **`Requires=mosquitto.service`**: an actual **dependency**, not just ordering — if `mosquitto.service` fails to start, systemd will not start (or will stop) this unit either. `Wants=` (used for `network-online.target` here) is the softer form — "try to start it too, but don't fail me if it doesn't."
- **`Type=notify`**: tells systemd this service will call `sd_notify(READY=1)` once actually initialized (not just "process exists" but "process has finished its own startup sequence and is genuinely ready") — this requires your application to link `libsystemd` and call the notify API explicitly. Worth doing for your `mqtt_monitor` C++ service specifically if other services depend on it being _fully_ ready (not just process-started) — `Type=simple` (the default) only tracks process existence, which can create races if downstream things assume readiness prematurely.
- **`WatchdogSec=30`**: pairs with `sd_notify(WATCHDOG=1)` calls your application makes periodically — if your app hangs and stops sending watchdog pings, systemd kills and restarts it after this timeout. This is a real production reliability feature worth wiring into your C++/Python monitor specifically because IoT/embedded services are exactly the class of software that benefits from automatic hang-recovery without manual intervention.
- **`StateDirectory=`/`LogsDirectory=`**: systemd automatically creates `/var/lib/mqtt-monitor/` and `/var/log/mqtt-monitor/` with correct ownership (matching `User=`/`Group=`) before starting your service — cleaner than manually creating these directories in `do_install` and getting permissions right yourself.

## Running as a non-root user — the correct Yocto pattern

Security-conscious production services shouldn't run as root. Yocto's mechanism for creating system users/groups at image-build time:

```bitbake
inherit useradd

USERADD_PACKAGES = "${PN}"
USERADD_PARAM:${PN} = "--system --no-create-home --shell /sbin/nologin mqtt-monitor"
GROUPADD_PARAM:${PN} = "--system mqtt-monitor"
```

`inherit useradd` wires `pkg_postinst` scriptlets that actually run `useradd`/`groupadd` with these exact parameters during rootfs assembly — meaning the user/group exists in `/etc/passwd`/`/etc/group` on the built image from first boot, without any runtime provisioning step. This is the correct mechanism — don't hand-roll `/etc/passwd` manipulation in `ROOTFS_POSTPROCESS_COMMAND`; `useradd.bbclass` handles UID/GID allocation correctly and interacts properly with `do_rootfs`'s ordering.

## Python service recipes — your Python `mqtt_monitor` capstone specifically

Packaging your Python service follows a related but distinct pattern — `inherit systemd` still applies for the unit file, but the Python packaging itself uses a different class:

```bitbake
inherit setuptools3 systemd

SRC_URI = "git://github.com/georgeco/mqtt-monitor-py.git;protocol=https;branch=main"
SRCREV = "..."
S = "${WORKDIR}/git"

RDEPENDS:${PN} += "python3-paho-mqtt python3-pydantic python3-fastapi python3-uvicorn"

SYSTEMD_SERVICE:${PN} = "mqtt-monitor-py.service"
SYSTEMD_AUTO_ENABLE:${PN} = "enable"

do_install:append() {
    install -d ${D}${systemd_unitdir}/system
    install -m 0644 ${S}/systemd/mqtt-monitor-py.service ${D}${systemd_unitdir}/system/mqtt-monitor-py.service
}
```

`inherit setuptools3` expects your project to have a working `setup.py`/`pyproject.toml` (per your existing Python curriculum's `pyproject.toml` usage) — it runs the equivalent of `pip install` targeting `${D}` with correct cross-target Python paths. `RDEPENDS` here is where each of your actual Python dependencies (paho-mqtt, pydantic, fastapi) needs a corresponding `python3-*` recipe to exist in an active layer (`meta-python` from `meta-openembedded`, per Day 3) — if a dependency doesn't have an existing recipe, you'd need to write one (typically also `inherit setuptools3`, following this same pattern) or vendor it, a real practical gap worth checking early for any less-common PyPI package.

```bash
bitbake-layers show-recipes python3-fastapi
bitbake-layers show-recipes python3-uvicorn
```

Run these checks before committing to a Python dependency list for your target image — not every PyPI package has a corresponding, maintained Yocto recipe, and discovering a gap late (after significant image work) is more costly than checking upfront.

## Debugging a service that doesn't start correctly on target

```bash
systemctl status mqtt-monitor
journalctl -u mqtt-monitor -b          # this boot's logs only
journalctl -u mqtt-monitor --since "-10min"
systemctl list-dependencies mqtt-monitor    # confirm actual resolved ordering/requirement graph
systemd-analyze verify mqtt-monitor.service  # catches unit file syntax errors before even trying to start it
```

`systemd-analyze verify` is worth running immediately after writing any new unit file — it catches typos and structural mistakes (wrong section names, invalid directive values) that otherwise manifest as confusing runtime failures rather than clear syntax errors.

**Common real failure pattern worth flagging explicitly**: unit file installed correctly, `SYSTEMD_AUTO_ENABLE = "enable"` set correctly in the recipe, but the service still isn't running on a freshly flashed device. Almost always traces to one of: `do_install` didn't actually place the file at `${systemd_unitdir}/system/` (check with `oe-pkgdata-util list-pkg-files`, Day 14's tool), the `.service` file has a typo in `[Install]`'s `WantedBy=` target, or the package genuinely didn't make it into `IMAGE_INSTALL` at all (verify with `opkg list-installed` on the running target, or `bitbake -e <image> | grep IMAGE_INSTALL` at build time).

## Key takeaways

- `inherit systemd` handles the enable/disable-at-rootfs-time mechanics; `SYSTEMD_SERVICE`/`SYSTEMD_AUTO_ENABLE`/`SYSTEMD_PACKAGES` are the three variables that control it.
- `After=`/`Wants=` are ordering/soft-dependency; `Requires=` is a hard dependency — conflating these causes real cold-boot race bugs where a service "works" in testing but fails intermittently on fresh boot.
- `Type=notify` + `WatchdogSec` is genuinely worth wiring into your production monitor services — real hang-recovery, not just process-exists tracking.
- `inherit useradd` is the correct mechanism for creating service-specific non-root users at rootfs-build time — don't hand-roll this.
- Python service recipes combine `inherit setuptools3 systemd` — verify every PyPI dependency has a corresponding `python3-*` recipe in an active layer _before_ committing to your dependency list, using `bitbake-layers show-recipes`.
- `systemd-analyze verify` catches unit file syntax errors immediately; `journalctl -u <service> -b` + `systemctl list-dependencies` are your primary runtime diagnostic tools.
- The most common "service doesn't start despite correct-looking recipe" bug traces to one of three things: file not actually installed at the right path, a unit file typo, or the package missing from `IMAGE_INSTALL` entirely — check all three systematically rather than guessing.
