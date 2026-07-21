[[Advanced Production]]
# Day 26: Read-Only Rootfs and OTA Update Strategies — Mender/RAUC Overview

Days 20 and 25 both flagged `read-only-rootfs` and whole-image OTA as decisions with ripple effects, deferred to this day. This is where those threads resolve — what read-only rootfs actually requires from your application, and the two dominant OTA frameworks in the Yocto ecosystem, covered at the depth needed to make an informed architectural choice for your MQTT monitor deployment.

## What `read-only-rootfs` actually changes, concretely

```bitbake
EXTRA_IMAGE_FEATURES += "read-only-rootfs"
```

This mounts `/` as read-only at boot. Immediate practical consequence for your `mqtt_monitor` stack specifically: **your SQLite database and any logs cannot live under a path that's part of the read-only root** — `/var/lib/mqtt-monitor/monitor.db` (from Day 19's `StateDirectory=`) would fail to open for writes at all. This isn't a Yocto configuration detail to tune around — it's an application architecture decision that has to be made consciously, not discovered at first boot of a read-only image.

The standard pattern: a separate, genuinely writable partition/overlay mounted at specific paths, with everything else truly immutable:

```bitbake
# in your image recipe or a rootfs postprocess
VOLATILE_LOG_DIR = "0"
```

Practically, this usually means: `/var` (or specifically `/var/lib`, `/var/log`) is either a separate writable partition (defined in your `.wks` file, Day 15/17) mounted at boot, or a tmpfs-backed overlay if the data is genuinely ephemeral and doesn't need to survive reboot (not appropriate for your SQLite telemetry database, which clearly needs persistence — but might be appropriate for, say, transient debug logs).

```
# extended .wks example
part /boot --source bootimg-partition --ondisk mmcblk --fstype=vfat --label boot --active --size 64
part / --source rootfs --ondisk mmcblk --fstype=ext4 --label root --align 4
part /data --ondisk mmcblk --fstype=ext4 --label data --align 4 --size 512
```

Your `mqtt-monitor-cpp.service`'s `StateDirectory=mqtt-monitor` (Day 19) would then need to actually resolve to somewhere under this `/data` partition (via a bind mount configured in fstab/systemd, or by changing the application's configured database path) rather than assuming `/var/lib` is writable — a concrete, necessary change to your existing service unit and possibly your application's default config path, prompted directly by this architectural decision.

## Why read-only rootfs is worth the complexity — the actual value proposition

Three genuine benefits, worth being clear about since the added complexity needs to be justified:

1. **Corruption resistance**: an unexpected power loss (genuinely common for embedded/IoT field deployments — no graceful shutdown, someone unplugs it) can't corrupt the root filesystem itself if it's never being written to; only the isolated writable data partition is at risk, and that can be designed with its own resilience (journaling filesystem, or even a much simpler/smaller writable footprint that's faster to fsck/recover).
2. **Security property** (Day 25's `dm-verity` connection): a read-only rootfs backed by `dm-verity` genuinely can't be persistently modified by an attacker with a foothold — reboot returns to a known-good state.
3. **Predictability**: every device of the same image version has byte-identical root filesystem content — eliminates an entire class of "works on device A, not device B" field debugging mysteries caused by accumulated filesystem drift.

## OTA strategy — the actual choice: Mender vs RAUC vs "roll your own"

Both are mature, Yocto-integrated **A/B (dual-bank) update systems** — the core mechanism worth understanding before comparing tools.

## The A/B update mechanism, precisely

Rather than updating files in place (fragile — a power loss mid-update can brick the device with a half-updated filesystem), A/B systems maintain **two complete copies** of the rootfs (bank A, bank B) on separate partitions. An update writes the _entire new rootfs_ to the currently-inactive bank while the device keeps running normally from the active bank; only after the new bank is fully written and verified does the bootloader get told "boot from the other bank next time." If the new image fails to boot successfully (crash loop, failed health check), the bootloader falls back to the previous known-good bank automatically.

This is precisely why whole-image OTA (Day 14/20/25's recurring theme) pairs naturally with read-only rootfs and dual-bank updates — the entire strategy is "never modify a running/bootable filesystem in place, always write a complete new one and atomically switch," which is the same underlying philosophy as `dm-verity`'s "verify before trust" and read-only rootfs's "never write to what you're currently running from."

## Mender — the more commonly adopted option for straightforward fleet management

```bitbake
INHERIT += "mender-full"
MENDER_ARTIFACT_NAME = "mqtt-monitor-v1.2.0"
MENDER_STORAGE_TOTAL_SIZE_MB = "4096"
```

Mender provides: a client running on-device (as a systemd service) that polls a server (Mender's own hosted/self-hosted server, or their open-core management server) for available updates, handles the A/B bank-switching mechanics, and includes a rollback health-check mechanism (the new image must self-report "I booted successfully and I'm healthy" within a configurable window, or automatic rollback triggers). It also handles delta updates (transferring only the binary diff between old/new images rather than the full image every time) — genuinely valuable for bandwidth-constrained IoT deployments (cellular-connected devices, for instance), since full image transfers can be hundreds of megabytes.

Mender's server component (fleet management dashboard, deployment scheduling, device grouping) is a real practical advantage if you're managing more than a handful of devices — rolling your own fleet management/deployment coordination is a substantial undertaking Mender has already solved.

## RAUC — the more minimal, embeddable, "just the update mechanism" option

```bitbake
INHERIT += "rauc"
RAUC_KEY_FILE = "${TOPDIR}/../keys/rauc-key.pem"
RAUC_CERT_FILE = "${TOPDIR}/../keys/rauc-cert.pem"
RAUC_SLOT_A = "1"
RAUC_SLOT_B = "1"
```

RAUC does the same core A/B mechanism (dual-bank writes, bootloader integration, rollback on health-check failure) but deliberately doesn't include a hosted fleet-management server — it's a well-designed, X.509-cert-based update _mechanism_ that you integrate into your own update distribution approach (your own server serving `.raucb` update bundles, triggered however fits your existing infrastructure — could genuinely be triggered _over your existing MQTT infrastructure_, which is a notable synergy for your specific stack: your devices are already MQTT-connected for telemetry, so signaling "update available" over that same channel rather than standing up an entirely separate polling mechanism is a legitimate, commonly-used pattern).

## Practical decision framework for your situation

|Consideration|Favors Mender|Favors RAUC|
|---|---|---|
|Fleet size/management need|Managing many devices, want a dashboard/scheduling UI without building one|Small fleet, or you already have/want your own management plane|
|Existing infrastructure|Starting fresh, no strong opinion on update-trigger mechanism|Already have MQTT-based device coordination (your actual situation) you'd rather reuse|
|Bandwidth constraints|Cellular/constrained links, delta updates matter|Less bandwidth-sensitive, or you'll build delta transfer yourself|
|Integration depth desired|Want a mostly turnkey solution|Want a minimal mechanism you compose into your own architecture|

Given your existing MQTT-centric architecture specifically, **RAUC integrated with your existing MQTT infrastructure for update-triggering** is a coherent, non-redundant architectural fit — you're not standing up a parallel communication channel purely for update coordination when you already have one that works and that you've built real operational familiarity with through your capstones. This isn't a strong universal recommendation (Mender is excellent and the right choice for many teams) — it's specifically informed by what you've already built.

## Practical build/deployment loop with RAUC

```bash
bitbake mqtt-monitor-image     # produces a .raucb bundle in tmp/deploy/images/<machine>/, alongside the normal image
```

On-device, applying an update (this would normally be automated, triggered by your MQTT-based signaling mechanism rather than run manually):

```bash
rauc install /path/to/mqtt-monitor-image-v1.3.0.raucb
rauc status    # confirm bank state, which is currently active/inactive
reboot
```

After reboot, the device is running from the newly-updated bank; RAUC's health-check hook (which you configure to check, e.g., that `mqtt-monitor-cpp.service` actually reached active/running state and successfully connected to the broker within N seconds) determines whether this boot is marked "good" (making it permanent) or triggers automatic rollback to the previous bank.

## Key takeaways

- Read-only rootfs requires an explicit, conscious application-level change — your SQLite database/logs need a genuinely writable partition path, discovered and designed for deliberately, not encountered as a first-boot surprise.
- A/B (dual-bank) updates are the core mechanism behind both Mender and RAUC — write the complete new rootfs to the inactive bank, verify, atomically switch, with automatic rollback on health-check failure. This pairs naturally with the "never modify what's currently running" philosophy shared with read-only rootfs and `dm-verity`.
- Mender: more turnkey, includes fleet management server + delta updates — favors teams wanting a mostly-complete solution and managing a genuine device fleet.
- RAUC: minimal, composable mechanism without an opinionated server component — favors teams (like your current situation) with existing infrastructure (MQTT) they'd rather reuse for update triggering than stand up a parallel channel.
- Both integrate with Yocto via straightforward `INHERIT +=` classes — the harder design work is the application-level read-only rootfs adaptation and your own update-triggering/health-check policy, not the Yocto mechanics themselves.
- This decision (Mender vs RAUC vs neither yet) is worth making deliberately once you're actually planning field deployment — not urgent while still in RPi4/QEMU prototyping, but worth having architected mentally before your first real device ships.
