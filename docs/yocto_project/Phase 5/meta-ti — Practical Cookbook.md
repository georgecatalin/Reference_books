Given your BeagleBone target from Day 15 onward, this is the layer that actually replaces the generic reference BSP guidance with real TI-specific mechanics. Here's the complete practical picture, current as of what I could verify.

# meta-ti — Practical Cookbook

## 1. What it actually is — the layer split

"meta-ti" isn't one layer, it's a repo containing two:

- **`meta-ti-bsp`** — the actual hardware enablement: machine `.conf` files, kernel/U-Boot recipes, device trees, bootloader firmware packaging.
- **`meta-ti-extras`** — TI SDK components layered on top (codecs, TI's graphics/DSP offload stacks, additional userspace tools) that aren't strictly required to boot.

This layer depends on OE-Core (meta) and meta-arm (meta-arm) — you need `meta-arm`/`meta-arm-toolchain` cloned alongside it, which is easy to miss since it's not one of the "obvious" dependencies like `meta-openembedded`.

```bash
git clone -b scarthgap https://git.yoctoproject.org/meta-arm
git clone -b scarthgap https://git.yoctoproject.org/meta-ti
bitbake-layers add-layer ../meta-arm/meta-arm
bitbake-layers add-layer ../meta-arm/meta-arm-toolchain
bitbake-layers add-layer ../meta-ti/meta-ti-bsp
bitbake-layers add-layer ../meta-ti/meta-ti-extras
```

Branch matching (Day 3's `LAYERSERIES_COMPAT` discipline) applies exactly as usual — pin `meta-ti`, `meta-arm`, and your poky checkout to the same release codename.

## 2. There's a reference distro too: `meta-arago`

Just like Poky is OE-Core's reference distro, **Arago** is TI's reference distro built on top of meta-ti. meta-arago-distro pulls in meta-arago-extras, meta-networking, meta-python, meta-qt5, meta-ti-bsp, meta-ti-extras, and meta-clang, plus optionally meta-selinux/meta-chromium. You don't need Arago — you can use `meta-ti-bsp` directly with `DISTRO = "poky"` — but Arago is worth knowing about since a lot of TI's own documentation and forum answers assume it, and its `.conf`/recipe patterns are a good reference for "how TI itself configures these boards."

There's also **`meta-tisdk`** (TexasInstruments/meta-tisdk on GitHub) — the Yocto layer for TI's Foundational/Processor SDK Linux for Sitara MPU and Jacinto devices. This is the more "productized, officially supported" wrapper — if you ever need TI's actual support channel rather than community meta-ti, this is the layer they'll point you at. For your own product work, meta-ti-bsp directly (your own distro layer, per Day 23) is the leaner, more controllable choice.

## 3. The architectural fork you need to know before picking a machine

This is the single most important thing meta-ti does differently from Day 15's generic BSP model: **TI's SoC families split into two genuinely different boot architectures**, and meta-ti's machine configs reflect that split directly.

**Legacy Sitara (AM335x/AM437x — your actual BeagleBone Black target):** Single ARM core (Cortex-A8), single-stage bootloader chain: ROM → MLO (a stripped-down first-stage U-Boot, "SPL") → full U-Boot → kernel. This is structurally identical to the generic Day 17 boot-chain model.

**K3 family (AM62x, AM64x, AM65x, J721E/J7xx, etc. — newer TI SoCs, relevant if you ever move beyond BeagleBone):** Heterogeneous multi-core from boot: a **Cortex-R5F** core boots _first_ and runs TI's System Firmware (SYSFW)/TI-SCI stack before the main Cortex-A cores even come out of reset. This means a K3 Yocto build is actually building **two separate firmware images for two different cores** — meta-ti handles this via `BBMULTICONFIG` (Day 23's mechanism, used here for a genuinely different reason than product variants):

```bitbake
# conf/multiconfig/k3r5-gp.conf (shipped by meta-ti itself)
MACHINE = "am62xx-evm-k3r5-gp"
```

```bash
bitbake am62xx-evm    # triggers the R5 multiconfig build automatically as a dependency
```

SYSFW_SOC, SYSFW_CONFIG, SYSFW_SUFFIX drive which System Firmware variant gets built, and a separate `ti-sci-fw` recipe and `k3r5.inc`/`am62xx.inc` machine includes handle the R5-side firmware. **If you're on BeagleBone (AM335x), you can ignore all of this** — it's single-core, single-stage, no SYSFW/TI-SCI involved. I'm flagging it because if you ever look at a K3-based board's build output and see two completely separate `tmp/deploy/images/` worth of artifacts for what looks like "one board," this is why.

## 4. GP vs HS-FS vs HS-SE — a real hardware security tier, not a build option

On K3 boards specifically, machine configs are split by device security tier — GP (General Purpose), HS-FS (HS Field-Securable), HS-SE (HS Security-Enforcing), e.g. `am62xx-evm-k3r5-gp.conf` vs `am62xx-evm-k3r5-hs-fs.conf`. This isn't a software toggle — it corresponds to which physical silicon variant you have (GP chips can't do hardware secure boot at all; HS-SE chips enforce it via fused keys, directly tying back to Day 25's fuse-based secure boot discussion). Building the wrong variant's config for your actual chip either fails outright or silently doesn't give you the security properties you think it does — check your chip's actual part marking/datasheet against which tier it is before picking a `.conf`.

## 5. Picking the right machine for BeagleBone specifically

`beaglebone.conf` requires `conf/machine/include/beagle.inc` and `conf/machine/include/ti33x.inc` — the `ti33x.inc` is the shared AM335x-family tuning/config (analogous to Day 15's `arch-armv8a.inc` pattern, just TI's own SoC-family include instead of a generic ARM one).

```bitbake
MACHINE = "beaglebone"
```

There's also `am335x-evm` (TI's own eval board — building for this actually produces DTBs for **three** boards at once: EVM, EVM-SK, and BeagleBone, since am335x-evm's config covers all three and building `beaglebone` specifically only produces the one DTB) and `am335x-hs-evm` (the HS/secure variant of the eval board, same GP/HS distinction as K3 above, just for the older single-core architecture).

**A real gotcha worth knowing**: older `beaglebone.conf` used to hardcode `PREFERRED_PROVIDER_virtual/kernel = "linux-bb.org"` (BeagleBoard.org's own community kernel fork, not TI's). A patch removed that override to centralize settings via the new `beagle.inc` — meaning **current meta-ti's beaglebone.conf no longer defaults to the bb.org kernel** the way older tutorials/forum answers assume. If you're following an older guide that references `linux-bb.org` behavior and it doesn't match what you're seeing, this is why — check what `PREFERRED_PROVIDER_virtual/kernel` actually resolves to for your checked-out branch with `bitbake -e virtual/kernel | grep ^PN=` (Day 15's exact diagnostic) rather than assuming.

## 6. Kernel — TI's own tree, not vanilla linux-yocto

meta-ti generally doesn't use plain `linux-yocto` — it points at **TI's own kernel tree** (`ti-linux-kernel`, hosted at git.ti.com), which carries TI-specific driver support (PRU, ICSSG, TI-SCI, DSP/IPC remoteproc drivers) that isn't upstream yet or is TI-maintained downstream. Branches follow the pattern `ti-linux-6.6.y` etc., tracking specific kernel versions. Day 16's `.cfg` fragment / `.scc` mechanism applies identically once you're inside this recipe — it's still `linux-yocto`-class machinery underneath in newer meta-ti versions, just fetching from TI's tree with TI-specific config fragments pre-supplied.

**PRU support specifically** (directly relevant if your MQTT monitor's serial/RS485 work ever wants PRU-based bit-banging or deterministic timing — a genuine PRU use case): the `prueth-fw`/PRU firmware recipes exist in meta-ti, but a real user report describes hitting boot failures (device dropping to emergency mode) trying to combine meta-ti+meta-arago at the kirkstone branch with PRU ethernet support, and TI's own response indicates AM335x/AM437x PRUETH driver porting to newer kernel branches (6.6.y) was still in progress at that point. **Practical guidance**: don't assume PRU peripheral support is uniformly solid across every kernel branch meta-ti offers — check the specific branch/kernel-version combination against current TI E2E forum activity and meta-ti's own recipe for your target peripheral before committing, rather than assuming "meta-ti supports PRU" means "any branch, any PRU feature, works."

## 7. Bootloader artifacts — what actually gets deployed, per architecture

**AM335x/BeagleBone (legacy, single-stage):**

```
MLO                    # first-stage bootloader (SPL), goes at a fixed location on the FAT boot partition
u-boot.img             # full U-Boot, also on the FAT partition
uImage / zImage        # kernel
am335x-bone*.dtb       # device tree
```

A real deployment sequence: copy MLO to the FAT partition as literally `MLO`, copy u-boot's image as `u-boot.img`, extract the rootfs tarball, extract kernel modules, and copy the DTB to `/boot/<name>.dtb` in the rootfs — with the exact DTB filename mattering because U-Boot's environment expects a specific name. This is Day 17's `bootargs`/environment-matching lesson made concrete — get the DTB filename wrong and U-Boot loads fine but can't find its device tree.

**K3 (AM62x/AM64x/J721E etc.):**

```
tiboot3.bin            # R5 SPL + SYSFW, first thing Boot ROM loads
tispl.bin               # A-core U-Boot SPL + ATF + OP-TEE, second stage
u-boot.img              # full U-Boot proper
Image + .dtb             # kernel + device tree
```

Notice this is a strictly longer chain than Day 17's generic model (ROM → tiboot3.bin → tispl.bin → u-boot.img → kernel) — the extra stages exist specifically to hand off from the R5 (running SYSFW) to the A-cores (running ATF/OP-TEE for TrustZone, then U-Boot proper). If you move to a K3-based custom board later, Day 17's "which stage stopped" UART diagnosis method still applies, just with two more stages to distinguish between.

## 8. `.wks` / wic implications

For BeagleBone, meta-ti's own `.wks` (or the one you write per Day 15/17) needs the boot partition formatted such that `MLO` and `u-boot.img` land as literal files at the FAT partition root (not a raw-offset write like the K3/i.MX examples from Day 17 — AM335x's Boot ROM reads FAT-formatted MLO by filename, a genuinely different mechanism than raw-offset SPL placement). This is worth double-checking against meta-ti's actual shipped `.wks` files for your machine rather than assuming Day 17's raw-offset pattern transfers directly — AM335x's boot mechanism is FAT-filename-based, K3's is closer to the raw-offset pattern Day 17 showed.

## 9. Practical gotcha checklist

- **Kernel provider assumption**: don't assume `linux-bb.org` — verify with `bitbake -e virtual/kernel` for your actual checked-out branch.
- **DTB filename must match U-Boot's environment expectation exactly** (`am335x-bone.dtb` or whatever your board's env specifies) — a silent "boots U-Boot fine, kernel never loads" failure otherwise.
- **PRU/PRUETH support is branch-dependent, not universally available** — check current status for your specific kernel branch before committing to a PRU-based design.
- **GP vs HS chip variant must match your actual silicon** (K3 boards especially) — building the wrong tier's `.conf` doesn't just fail cleanly, it can silently give you the wrong security posture.
- **meta-arm is a hard dependency**, easy to forget since it's not obviously TI-related by name.
- **`am335x-evm` vs `beaglebone`**: building the EVM target gets you three boards' worth of DTBs; building `beaglebone` directly gets you exactly one — pick based on whether you want the broader EVM family's DTBs staged or not.

## 10. How this maps back to your curriculum

Everything from Days 15–18 (machine config anatomy, kernel `.cfg` fragments, U-Boot integration, device tree) is the correct mental model — meta-ti is "that model, with TI's actual production values already filled in," plus the K3 multiconfig wrinkle if you ever go beyond AM335x. Your actual next concrete step for BeagleBone specifically: clone meta-ti + meta-arm at your poky's branch, set `MACHINE = "beaglebone"`, run `bitbake core-image-minimal`, and diagnose any boot issues with Day 17's UART methodology — the mechanism is identical, only the artifact names (`MLO` instead of a generic SPL binary name) differ from the generic walkthrough.