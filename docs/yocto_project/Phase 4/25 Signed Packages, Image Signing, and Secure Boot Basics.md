[[Advanced Production]]
# Day 25: Signed Packages, Image Signing, and Secure Boot Basics

This day covers the trust chain for production IoT devices — ensuring packages haven't been tampered with in transit, that images are authenticated before flashing, and the (much deeper) hardware-rooted secure boot concept. This is genuinely a security-engineering topic with real depth beyond Yocto mechanics alone — treat this day as "what Yocto's tooling provides" plus "what's a genuinely separate hardware/crypto concern you'd need dedicated expertise for" rather than a complete security curriculum.

## Why this matters for a real MQTT/IoT deployment specifically

Your device authenticates to an MQTT broker and persists telemetry — a legitimate attacker target for tampering (inject false telemetry, exfiltrate data, pivot to other network segments). Package/image signing addresses one specific slice of the threat model: **ensuring the software running on the device is exactly what you built and shipped**, not something modified in transit (compromised update server, MITM during OTA, physical tampering with storage media before/during flashing). It does _not_ by itself address runtime compromise, network security, or physical access to a running device — those are separate concerns (network segmentation, TLS for MQTT itself per your existing `PACKAGECONFIG[tls]`, physical security).

## Package feed signing — GPG-based, the more mature Yocto-native mechanism

```bitbake
INHERIT += "sign_package_feed"

PACKAGE_FEED_GPG_NAME = "mqtt-monitor-release-key"
PACKAGE_FEED_GPG_PASSPHRASE_FILE = "/home/george/.gnupg-passphrase-file"
GPG_PATH = "/home/george/.gnupg"
```

Generating the key pair (standard GPG, done once, kept genuinely secret):

```bash
gpg --full-generate-key
# choose RSA, 4096-bit, no expiration or a deliberate long expiration for a release-signing key
gpg --list-keys
```

With `sign_package_feed` inherited, every package in your feed (Day 14's package feed concept) gets a corresponding `.sig` file, and the on-target package manager (`opkg`, configured with your **public** key) verifies each package's signature before installation — rejecting anything that doesn't verify. This protects the "per-package field update" deployment model (Day 14's discussion) against a compromised or spoofed update server serving tampered packages.

**Critical operational point**: the private signing key must never be on a build machine that's routinely exposed/networked in a way that risks compromise — a genuinely production-grade setup keeps release-signing keys on a dedicated, access-controlled signing machine (or an HSM), with your CI build producing unsigned artifacts that get signed in a separate, more tightly controlled step. This is a real organizational/process control, not just a BitBake config line — get this wrong and the entire signing mechanism provides zero actual security benefit (a stolen key on a routinely-accessible build server defeats the entire point).

## Image signing — for whole-image OTA verification (ties to Day 26)

If you've decided on whole-image OTA (Day 14/20's discussion favored this for avoiding field package-state drift), you need the **image** itself signed/verified, not individual packages:

```bitbake
IMAGE_FSTYPES += "wic.bz2"
UBOOT_SIGN_ENABLE = "1"
UBOOT_SIGN_KEYDIR = "/home/george/keys"
UBOOT_SIGN_KEYNAME = "mqtt-monitor-fit-key"
FIT_SIGN_INDIVIDUAL = "1"
```

This ties into **FIT images** (Flattened Image Tree — a U-Boot-native container format bundling kernel + DTB + optionally rootfs/ramdisk, with built-in signature verification support) — U-Boot, configured correctly, refuses to boot a FIT image whose signature doesn't verify against a public key **compiled into U-Boot itself** at build time (not stored on writable/tamperable storage, which would defeat the purpose). This is a genuinely deeper topic than package signing — the security property you're actually gaining depends heavily on where the verification key lives and whether U-Boot itself is protected from modification (which is where secure boot, below, actually matters).

## Secure boot — the hardware-rooted concern, correctly scoped

This is the part worth being precise about, because it's commonly oversimplified: **software-level image signing (above) only provides real security if the bootloader doing the verification cannot itself be tampered with or bypassed.** If an attacker with physical/flash access can simply replace U-Boot itself with a version that skips signature checking, FIT image signing provides no protection at all — you've just moved the trust problem one level down without resolving it.

Genuine secure boot requires **hardware root of trust** — SoC-vendor-specific mechanisms (NXP's HAB - High Assurance Boot, TI's secure boot, etc.) where the SoC's Boot ROM (in silicon, immutable) verifies the _first_ stage bootloader's signature against a key whose hash is burned into one-time-programmable (OTP) fuses on the chip itself, before executing anything. This creates an actual chain of trust: Boot ROM (immutable, hardware) verifies SPL → SPL verifies U-Boot proper → U-Boot verifies FIT image (kernel+DTB) → kernel verifies rootfs (dm-verity, below) — each stage cryptographically verifying the next before executing it, rooted in something genuinely unmodifiable.

**This is real, vendor-specific work outside Yocto's scope proper** — Yocto's BSP layers for SoCs with this capability (`meta-freescale`'s HAB support, for instance) provide the _build-time_ tooling to sign images correctly for your vendor's specific secure boot mechanism, but the actual fuse-burning, key management, and hardware enablement process is genuinely SoC-vendor documentation territory, and mistakes here are **literally unrecoverable** (burn the wrong key hash into OTP fuses and that specific chip is permanently bricked for secure boot purposes). This is not something to experiment with casually on hardware you can't afford to lose — practice thoroughly on genuinely expendable/development hardware first, and treat vendor documentation as authoritative over any generic tutorial including this one.

## `dm-verity` — read-only rootfs integrity verification (pairs with Day 26)

For verifying rootfs integrity _at runtime_ (not just at flash-time), `dm-verity` (a device-mapper target) provides block-level cryptographic verification of a read-only filesystem against a hash tree, checked on every read:

```bitbake
IMAGE_FSTYPES += "ext4"
INHERIT += "verity-image"
```

(Actual `dm-verity` integration in Yocto typically involves a dedicated meta layer like `meta-secure-core`'s verity support rather than a single trivial `INHERIT` line — flagging the concept and its purpose here; treat the exact recipe/class names as something to verify against current documentation for your specific Yocto release, since this area evolves and varies more by BSP than the more stable mechanisms covered earlier in this curriculum.) The practical value: even if an attacker gains a foothold and tries to modify files on a mounted rootfs, `dm-verity` detects the mismatch against the expected hash tree at the block level and the kernel can be configured to refuse the read (or panic, depending on policy) — this is what makes "read-only rootfs" (Day 20's flag) a genuine security property rather than just a "prevents accidental writes" convenience feature.

## Practical, scoped guidance for where to actually invest effort

Given your current stage (RPi4/BeagleBone prototyping, moving toward custom hardware), realistic prioritization:

1. **Package feed signing (GPG)** — genuinely achievable now, meaningful protection for any per-package update mechanism, straightforward Yocto-native tooling.
2. **TLS for MQTT itself** (your existing `PACKAGECONFIG[tls]`) — arguably higher priority than image signing at this stage, since network-transport security protects against a more immediately exploitable class of attack (MITM on your actual telemetry data) than boot-chain tampering, which requires physical access.
3. **FIT image signing + U-Boot verification** — worth doing once you're on custom hardware with a deliberate OTA strategy, genuinely achievable with Yocto's built-in tooling.
4. **Full hardware-rooted secure boot (fuse-based)** — a deliberate, well-resourced effort requiring direct SoC vendor engagement/documentation, appropriate once you have a genuine production security requirement (regulatory, contractual, or a specific threat model that justifies it) — not something to bolt on casually, and definitely not something to practice on hardware you can't afford to lose.

## Key takeaways

- Package signing (GPG, `sign_package_feed`) protects per-package field updates against a compromised/spoofed feed server — genuinely achievable with Yocto-native tooling now.
- Image signing (FIT + U-Boot verification) only provides real security if the bootloader itself can't be tampered with — otherwise you've moved the trust problem, not solved it.
- Genuine secure boot requires SoC-vendor hardware root of trust (fuse-burned key hashes, Boot ROM verification) — this is real vendor-specific engineering outside Yocto's scope, with irreversible failure modes; never experiment on hardware you can't afford to lose.
- `dm-verity` provides runtime block-level rootfs integrity checking, making "read-only rootfs" a genuine security property rather than just an accidental-write guard.
- Practical prioritization for your current stage: package signing and MQTT TLS now; FIT signing once on custom hardware with a real OTA strategy; fuse-based secure boot only once a genuine production security requirement justifies the resourcing and irreversibility risk.
- Never keep private release-signing keys on routinely-networked build machines — this is a process/organizational control, not just a config setting, and getting it wrong defeats the entire mechanism.

