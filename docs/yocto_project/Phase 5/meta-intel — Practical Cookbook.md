Given your curriculum, this layer has a genuinely different shape from meta-ti — x86 boot chain (EFI, not U-Boot/device-tree), and it's structurally simpler in most ways. Here's the practical picture.

# meta-intel — Practical Cookbook

## 1. What it actually is — much simpler dependency footprint than meta-ti

`meta-intel` primarily depends on **OE-Core alone** — no meta-arm equivalent, no separate distro layer required to get a booting image. Optional companion layers get pulled in only for specific extra capability: `meta-dpdk` (Data Plane Development Kit — high-throughput networking) and `meta-intel-qat` (QuickAssist crypto/compression accelerator support) are "recommended," not required.

```bash
git clone -b scarthgap https://git.yoctoproject.org/meta-intel
bitbake-layers add-layer ../meta-intel
```

That's genuinely the whole layer-setup step for the common case — no companion architecture-tuning repo to clone alongside it, unlike Day 15's ARM pattern.

## 2. Machine names — the `intel-<TUNE>-<BITS>` convention

```bitbake
MACHINE = "intel-corei7-64"
```

Naming: `intel-<TUNE>-<BITS>`, where `TUNE` is the GCC cpu-type target (`corei7`, historically `core2`) and `BITS` is 32 or 64. `intel-corei7-64` is the one you actually want for any modern x86_64 Intel target (NUC-class devices, industrial PCs, most gateway hardware) — it covers everything from Nehalem-era Core i-series through current Intel silicon at a shared, conservative tune level, similar in spirit to Day 15's "generic ARM tune" but for x86. Older `intel-core2-32`/`intel-quark` targeted specific legacy silicon (32-bit Core2-era, and Intel's discontinued Quark line) — not relevant unless you're maintaining genuinely old hardware.

**This is a materially different model from meta-ti's per-board machine files.** meta-ti gives you one `.conf` per physical board (BeagleBone, specific EVMs) because ARM SoCs need device-tree/pinmux specifics per board. Intel's `intel-*` machines are **tune-level abstractions covering broad hardware classes**, not per-board files — because x86 PCs discover hardware dynamically via ACPI/PCI (Day 18's exact point about why x86 doesn't need device tree) rather than needing a static hardware description compiled in. This is the single biggest conceptual difference from everything Days 15–18 covered — **there is no device tree, no `.dts`, no pinmuxing** in this entire layer, because the hardware model doesn't require it.

## 3. The boot chain — EFI, not U-Boot

This is the other major structural difference. Intel machines default to a **UEFI-based boot** via an ESP (EFI System Partition), not a U-Boot/MLO chain:

```bitbake
EFI_PROVIDER = "grub-efi"
# or
EFI_PROVIDER = "systemd-boot"
```

Both are legitimate choices, set per-machine or overridden in `local.conf`. `grub-efi` is the more traditional/flexible option (full GRUB menu, works across a broader range of firmware quirks); `systemd-boot` is minimal and fast, appropriate if you don't need GRUB's scripting/menu flexibility and want a leaner boot path. Actual boot flow: `Boot ROM → UEFI firmware (in flash, vendor-supplied, not something Yocto builds) → ESP-resident bootloader (grub-efi-bootx64.efi or systemd-bootx64.efi) → kernel`. Notice this is **shorter** than Day 17's ARM chain — there's no SPL/first-stage-bootloader-that-Yocto-builds step, because UEFI firmware itself (shipped by the board/motherboard vendor, analogous to how a PC's BIOS/UEFI is already there before you touch it) fills that role.

```bash
bitbake core-image-sato
dd if=core-image-sato-intel-corei7-64.wic of=/dev/sdX status=progress
```

For a real device (not a dev USB stick), `.wic` still applies (Day 6/15's mechanism), but the partition layout is GPT+ESP rather than the raw-offset-bootloader pattern from Day 17 — `wic`'s `bootimg-efi` source plugin builds the ESP with the correct `EFI/BOOT/bootx64.efi` path structure automatically once `EFI_PROVIDER` is set.

**Practical gotcha worth flagging explicitly**: some real hardware only implements 32-bit EFI even though the CPU itself is 64-bit (older Bay Trail-class tablets/boards being the classic example) — a real user-reported case where `intel-corei7-64` (64-bit kernel/rootfs) needed a 32-bit EFI bootloader (`bootia32.efi`) specifically because the firmware itself only speaks 32-bit EFI protocol, independent of what the CPU/OS actually run. If a board's UEFI menu straight-up won't launch your `bootx64.efi`, this firmware-bitness mismatch is the first thing to check — it's not a Yocto misconfiguration, it's a genuine mixed-mode firmware situation you build around by supplying the 32-bit EFI stub alongside your 64-bit kernel.

## 4. Kernel — `linux-intel`, a real but optional alternative to `linux-yocto`

meta-intel ships its own kernel recipe family, `linux-intel` (and `linux-intel-rt` for the PREEMPT_RT real-time variant) — described as bringing "better Intel hardware support to the current LTS kernel," meaning a base LTS kernel plus backported Intel driver fixes not yet in the specific LTS point release, packaged the same `linux-yocto`-style way (`.scc`/`.cfg` fragments, Day 16's exact mechanism, fully applicable here unchanged).

```bitbake
PREFERRED_PROVIDER_virtual/kernel = "linux-intel"
```

**You are not required to use it** — plain `linux-yocto` (Day 1–7's default) works fine on `intel-corei7-64` for the overwhelming majority of use cases, since Intel's mainline driver support is generally excellent already. Reach for `linux-intel` specifically when you need a very-recent hardware enablement backport (brand-new chipset, new integrated GPU generation) that hasn't landed in the LTS kernel branch `linux-yocto` tracks yet — otherwise it's an unnecessary divergence from the more universally-tested default.

`linux-intel-rt` exists specifically because OE-Core's own `linux-yocto-rt` naming is hardcoded elsewhere in ways that don't cleanly extend — meta-intel replicates the RT-kernel image recipe pattern (`core-image-rt`, `core-image-rt-sdk`) against its own kernel rather than trying to force-fit the generic RT mechanism. Relevant if your MQTT monitor's timing requirements (serial frame timing, RS485 turnaround) ever genuinely need PREEMPT_RT guarantees on an x86 gateway device rather than just "good enough" scheduling latency.

## 5. Microcode — a genuinely x86-specific concern with no ARM equivalent

```bitbake
MACHINE_FEATURES += "intel-ucode"
MACHINE_EXTRA_RRECOMMENDS = "intel-microcode iucode-tool"
```

CPU microcode updates correct silicon-level errata (security issues like Spectre/Meltdown-class mitigations, and plain correctness bugs) that would normally arrive via a motherboard BIOS update — often impractical for embedded/field-deployed hardware where you don't control firmware update cadence. The `intel-ucode` machine feature bundles microcode data directly into your image's initrd, applied by the kernel at early boot **before** any of your userspace even starts — meaning your device gets microcode-level fixes through your own normal OTA/image update mechanism (Day 26) instead of depending on a BIOS vendor's update process you likely don't control. `iucode_tool` is the userspace utility for inspecting/managing this. This is worth enabling by default on any real Intel-based deployment — there's no equivalent concern or mechanism on the ARM/meta-ti side, since microcode-level updatable errata is specifically an x86 CPU architecture feature.

## 6. Graphics stack — relevant only if your device has a display/video pipeline

meta-intel bundles the Intel media/graphics stack (`libva`, `intel-media-driver`/legacy `intel-vaapi-driver`, `intel-compute-runtime` for GPU compute) — genuinely useful if your product has a display output or does hardware video encode/decode (a camera-integration variant of your MQTT monitor, echoing Day 14's camera-plugin package-splitting example, would be the relevant case). **If your actual deployment is a headless gateway device** (no display, no camera pipeline) — which is the more likely shape for an MQTT monitor/telemetry gateway — you can and should exclude this entire stack via `PACKAGE_EXCLUDE`/`IMAGE_INSTALL:remove` (Day 20's exact mechanism) rather than carrying tens/hundreds of megabytes of unused graphics driver stack onto a device that will never render anything.

## 7. What's genuinely simpler here vs. meta-ti, summarized

|Concern|meta-ti (ARM)|meta-intel (x86)|
|---|---|---|
|Hardware description|Device tree, per-board `.dts`|None — ACPI/PCI dynamic discovery|
|Bootloader|U-Boot, MLO/SPL, board-specific|UEFI firmware (vendor-supplied) + grub-efi/systemd-boot|
|Pin muxing|Real, tedious, SoC-vendor-doc-driven|Not applicable|
|Machine granularity|Per physical board|Per CPU tune-class, covers many boards|
|Companion layers required|meta-arm (hard dependency)|None required; meta-dpdk/meta-intel-qat optional|
|CPU-level firmware updates|Not applicable (no microcode concept)|`intel-ucode`/`intel-microcode`, genuinely worth enabling|

## 8. Practical guidance for your situation

If your MQTT monitor product ever gets an x86-based gateway/edge-aggregator tier (something with more compute than an RPi/BeagleBone — aggregating several field devices' telemetry, running heavier local processing before forwarding upstream), `intel-corei7-64` is the correct starting machine, `PREFERRED_PROVIDER_virtual/kernel = "linux-yocto"` (default) is fine unless you hit a specific hardware-support gap, `EFI_PROVIDER = "grub-efi"` is the safer default for real hardware variety, and `intel-ucode` should be on by default for any real deployment. Everything else from your curriculum — systemd integration (Day 19), image variants (Day 23), your actual C++/Python recipes (Day 24), OTA strategy (Day 26) — carries over completely unchanged, since none of that depends on ARM vs. x86 at all; only the machine-configuration and boot-chain layer (Days 15–18's territory) differs, and it differs by being **structurally simpler**, not more complex, on the Intel side.