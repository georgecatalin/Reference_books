[[Core Execution Mechanics]]
# Day 15: Machine Configuration — Writing a BSP Layer for Custom Hardware

This closes Phase 2. Everything so far assumed an existing `MACHINE` (qemux86-64, or a reference RPi/BeagleBone BSP). This day covers what a machine configuration file actually contains, and how you'd write one for genuinely custom hardware — relevant the moment you move beyond reference dev boards toward an actual custom PCB for your MQTT monitor product.

## What a BSP layer actually consists of

A BSP (Board Support Package) layer is a normal layer (Day 3 structure) with a specific additional directory that matters:

```
meta-mycompany-bsp/
├── conf/
│   ├── layer.conf
│   └── machine/
│       └── my-custom-board.conf       # THE machine definition file
├── recipes-kernel/
│   └── linux/
│       ├── linux-yocto_%.bbappend      # kernel config fragments/patches for this board
│       └── files/
│           └── my-board.cfg
├── recipes-bsp/
│   └── (bootloader configs, device tree overlays, etc.)
└── recipes-graphics/                    # if display-related, otherwise absent
```

## Anatomy of `conf/machine/my-custom-board.conf`

This is the file that, once you set `MACHINE = "my-custom-board"` in `local.conf`, drives everything: compiler tuning, kernel choice, bootloader, and hardware-specific defaults.

```bitbake
#@TYPE: Machine
#@NAME: My Custom Board
#@DESCRIPTION: Custom ARM board based on i.MX8M Mini, RS485 + CAN, MQTT monitor target

require conf/machine/include/arm/arch-armv8a.inc

DEFAULTTUNE = "cortexa53-crypto"

PREFERRED_PROVIDER_virtual/kernel = "linux-my-board"
PREFERRED_VERSION_linux-my-board = "6.6%"

KERNEL_IMAGETYPE = "Image"
KERNEL_DEVICETREE = "freescale/imx8mm-my-board.dtb"

PREFERRED_PROVIDER_virtual/bootloader = "u-boot-my-board"
UBOOT_MACHINE = "my_board_defconfig"

SERIAL_CONSOLES = "115200;ttymxc0"

MACHINE_FEATURES = "usbhost usbgadget rtc ext2 alsa"

IMAGE_FSTYPES ?= "wic.bz2"
WKS_FILE = "my-board.wks"

MACHINE_EXTRA_RRECOMMENDS = "kernel-modules"
```

Walking through each part, since these are the fields that actually matter:

- **`require conf/machine/include/arm/arch-armv8a.inc`**: pulls in a shared architecture-tuning include shipped by OE-Core — you almost never write CPU tuning flags (`TUNE_FEATURES`, `TUNE_CCARGS`) from scratch; you inherit the closest matching architecture include and adjust `DEFAULTTUNE` if needed. Writing these by hand is genuinely rare and error-prone (getting FPU/NEON flags wrong silently produces a kernel that crashes on real hardware but works fine in QEMU).
- **`DEFAULTTUNE`**: selects the specific CPU tuning profile (`cortexa53-crypto` = Cortex-A53 with crypto extensions enabled) — must match your actual SoC, found in the include file's supported tune list.
- **`PREFERRED_PROVIDER_virtual/kernel`**: which kernel recipe actually satisfies this machine's kernel build — this is where you point at your own `linux-my-board_6.6.bb` recipe (Day 16 covers kernel recipes in depth) rather than the generic `linux-yocto`.
- **`KERNEL_DEVICETREE`**: which `.dtb` (device tree blob, Day 18 territory) gets built and deployed for this board — must match a `.dts` file your kernel source tree actually provides (either upstream or one you've added).
- **`UBOOT_MACHINE`**: the U-Boot `make <this>_defconfig` target — U-Boot's own board-specific configuration name, distinct from Yocto's `MACHINE` name (they're often similar but are genuinely different namespaces — U-Boot has its own board naming convention).
- **`SERIAL_CONSOLES`**: format `"<baud>;<device> [<baud>;<device> ...]"` — this drives what gets added to the kernel command line and what getty gets started on that serial port at boot, essential for actually seeing boot output/getting a login prompt on real hardware over UART.
- **`MACHINE_FEATURES`**: analogous to `DISTRO_FEATURES` (Day 5) but describing hardware _capabilities_ rather than distro policy — recipes can conditionally include support based on what the machine actually has (no point building Bluetooth support into a base image if `MACHINE_FEATURES` doesn't include `bluetooth`).
- **`WKS_FILE`**: points at a `.wks` file (Day 6's `.wic` format, defined here) describing the actual partition layout — boot partition size, rootfs partition, any raw partition for a bootloader, etc.

## The `.wks` file — defining your actual partition layout

```
# my-board.wks
part /boot --source bootimg-partition --ondisk mmcblk --fstype=vfat --label boot --active --align 4 --size 64
part / --source rootfs --ondisk mmcblk --fstype=ext4 --label root --align 4
```

This describes: a 64MB FAT boot partition (holding kernel Image + device tree + U-Boot environment, depending on your boot flow) and an ext4 root partition with everything else. `wic` (Day 6) reads this at `do_image` time to actually assemble the final flashable disk image with real partition boundaries — this is genuinely what gets `dd`'d (or written via `bmaptool`/`etcher`) to an SD card or eMMC for real hardware.

## Bootloader integration — the machine-config side (Day 17 goes deeper into U-Boot itself)

```bitbake
PREFERRED_PROVIDER_virtual/bootloader = "u-boot-my-board"
UBOOT_MACHINE = "my_board_defconfig"
UBOOT_ENTRYPOINT = "0x40480000"
UBOOT_LOADADDRESS = "0x40480000"
```

`UBOOT_ENTRYPOINT`/`UBOOT_LOADADDRESS` are board/SoC-specific memory addresses (from your SoC's memory map / U-Boot board port documentation) — these are genuinely hardware-specific values you get from your SoC vendor's reference manual or an existing similar board's config, not values you can guess or copy from an unrelated board's `.conf` and expect to work.

## Starting from a reference BSP rather than from absolute scratch (the realistic path)

In practice, you almost never write a machine `.conf` completely from zero — you start from the closest existing reference machine and diverge:

```bash
cp meta-freescale/conf/machine/imx8mm-evk.conf \
   meta-mycompany-bsp/conf/machine/my-custom-board.conf
```

Then edit deliberately: change `KERNEL_DEVICETREE` to your board's specific `.dtb` (once you've added the corresponding device tree source, Day 18), adjust `MACHINE_FEATURES` for what your board actually has (remove `bluetooth` if you didn't populate that radio, add `can` if you have a CAN transceiver for industrial telemetry), and validate `UBOOT_MACHINE`/memory addresses against your SoC's actual reference design rather than the eval board's, since these frequently differ even on the "same" SoC family.

This "start from a reference BSP, diverge deliberately" pattern is the actual professional workflow — vendor BSP layers (`meta-freescale`, `meta-ti`, `meta-raspberrypi`, `meta-xilinx`) exist specifically so you're never starting from true zero; you're always adapting a known-working reference config to your specific board's deltas.

## Validating a new machine config — practical build sequence

```bash
bitbake-layers add-layer ../../meta-mycompany-bsp
# in local.conf:
MACHINE = "my-custom-board"

bitbake -e virtual/kernel | grep ^PN=     # confirm your kernel recipe is actually selected
bitbake -e virtual/bootloader | grep ^PN=  # confirm your U-Boot recipe is selected
bitbake core-image-minimal
```

If `virtual/kernel`/`virtual/bootloader` resolve to something unexpected (a generic `linux-yocto` instead of your `linux-my-board`), your `PREFERRED_PROVIDER` line in the machine `.conf` isn't taking effect — usually a layer priority issue (Day 3) or a typo in the provider name, verified again via `bitbake-layers show-recipes virtual/kernel`.

## Multiple machines, one product line — a preview of Day 23

Real product development frequently has multiple hardware revisions or variants (rev A prototype, rev B production, a cost-reduced variant with less RAM) — each gets its own `.conf` file, often sharing a common `.inc`:

```
conf/machine/include/my-board-common.inc    # shared: SoC tuning, common features
conf/machine/my-board-reva.conf              # require the .inc, add rev-A-specific overrides
conf/machine/my-board-revb.conf              # require the .inc, add rev-B-specific overrides
```

This mirrors the `require`-based `.inc` sharing pattern from Day 6's image recipes — same mechanism, applied at the machine-configuration layer instead. Day 23 covers the full multi-product-line strategy; for now, know the pattern exists and follows the same textual-inclusion logic you already understand.

## Key takeaways

- A BSP layer = normal layer structure + `conf/machine/<name>.conf`, the file that cascades into kernel choice, bootloader choice, CPU tuning, and hardware feature declarations.
- Never hand-write CPU tuning from scratch — `require` an architecture include (`arch-armv8a.inc` etc.) and set `DEFAULTTUNE` to the matching profile for your actual SoC.
- `PREFERRED_PROVIDER_virtual/kernel` and `virtual/bootloader` are how a machine config points at board-specific kernel/U-Boot recipes instead of generic defaults.
- `.wks` files define actual partition layout for the flashable disk image — boot partition size/type, rootfs partition, matter for real hardware boot ROM expectations.
- `UBOOT_MACHINE`, `UBOOT_ENTRYPOINT`, `UBOOT_LOADADDRESS` are genuinely hardware/SoC-specific — get them from vendor documentation or a closely related reference board, never guess.
- The realistic professional workflow starts from an existing vendor reference BSP (`meta-freescale`, `meta-ti`, etc.) and diverges deliberately — true from-scratch machine configs are rare even in production work.
- Multiple hardware revisions share a common `.inc`, each `.conf` requiring it and adding deltas — same pattern as image recipe variants (Day 6), applied one layer down.

This closes Phase 2 (Core Mechanics). Phase 3 (Practical Systems Work) 