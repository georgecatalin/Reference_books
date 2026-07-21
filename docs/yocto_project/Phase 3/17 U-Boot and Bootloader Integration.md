[[Practical Systems Work]]
# Day 17: U-Boot and Bootloader Integration

Day 15 touched `UBOOT_MACHINE`/`virtual/bootloader` from the machine-config side; Day 16 flagged `KERNEL_IMAGETYPE` matching bootloader expectations. This day is U-Boot itself — recipe structure, environment configuration, boot scripts, and the actual boot flow from power-on to kernel handoff, since debugging a board that "doesn't boot" almost always means understanding exactly where in this chain things stopped.

## The real boot flow, precisely (what actually happens before Linux even starts)

```
SoC Boot ROM (fixed, in silicon)
   → loads SPL/first-stage bootloader from fixed offset on boot media
   → SPL initializes minimal DRAM, loads full U-Boot
   → U-Boot proper: initializes more hardware, reads boot environment
   → U-Boot loads kernel Image + device tree blob into RAM
   → U-Boot hands off to kernel (via booti/bootz/bootm depending on image type)
   → Kernel takes over, mounts rootfs, starts init/systemd
```

Understanding this precisely matters because "board won't boot" bugs cluster into distinct categories depending on _where_ in this chain things stop — no UART output at all (Boot ROM/SPL never ran — usually a boot media/wiring/fuse issue, not a Yocto problem), UART shows U-Boot banner but hangs (U-Boot config/environment issue), U-Boot loads kernel but nothing further (kernel image type mismatch or device tree issue, ties back to Day 16), kernel boots but no login prompt (rootfs/init issue, further downstream). Being able to say "which of these five things happened" from console output is the actual practical debugging skill here — much more useful than guessing.

## U-Boot recipe structure

```bitbake
SRC_URI = "git://git.denx.de/u-boot.git;branch=master;protocol=https"
SRCREV = "abc123..."

PV = "2024.01"

inherit uboot-config deploy

UBOOT_MACHINE = "my_board_defconfig"
```

`inherit uboot-config` (in addition to the machine-config-level settings from Day 15) provides the actual build task logic — runs `make <UBOOT_MACHINE>` (U-Boot's own defconfig target), then `make` to build, producing `u-boot.bin`/`u-boot.img`/`u-boot-spl.bin` depending on your SoC's boot requirements. This is a genuinely different build system from CMake/autotools (U-Boot has its own Kconfig-based build, similar in spirit to the Linux kernel's) — `uboot-config.bbclass` bridges it into BitBake's task model the same way `cmake.bbclass` bridges CMake.

## U-Boot config fragments — same pattern as kernel `.cfg` files

U-Boot itself uses Kconfig (same underlying mechanism as the Linux kernel), so the fragment-based customization pattern from Day 16 applies here too:

```
# files/my-board-uboot-fixup.cfg
CONFIG_BOOTDELAY=1
CONFIG_ENV_IS_IN_MMC=y
CONFIG_SYS_MMC_ENV_DEV=0
```

```bitbake
FILESEXTRAPATHS:prepend := "${THISDIR}/files:"
SRC_URI:append = " file://my-board-uboot-fixup.cfg"
```

`inherit uboot-config` (via `kconfig.bbclass` underneath, shared machinery with kernel config handling) picks these up and merges them the same way `linux-yocto.bbclass` merges kernel `.cfg` fragments — the mental model from Day 16 transfers directly, since both are Kconfig-based build systems under the hood.

## U-Boot environment — where boot behavior actually gets configured

This is the part that trips people up most: **U-Boot's boot behavior at runtime is controlled by its environment variables**, which are distinct from the `Kconfig`-time build configuration above. Build config determines _what U-Boot is capable of_; the environment determines _what it actually does on this specific boot_.

```
# example U-Boot environment (viewed via `printenv` at the U-Boot prompt, or in an env script)
bootcmd=run mmc_boot
mmc_boot=mmc dev 0; load mmc 0:1 ${kernel_addr_r} Image; load mmc 0:1 ${fdt_addr_r} imx8mm-my-board.dtb; booti ${kernel_addr_r} - ${fdt_addr_r}
bootargs=console=ttymxc0,115200 root=/dev/mmcblk0p2 rootwait rw
```

- **`bootcmd`**: the command U-Boot runs automatically after `bootdelay` expires (if you don't interrupt it by hitting a key) — this is your actual "what boots this board" script.
- **`bootargs`**: the Linux kernel command line — `console=` must match your actual serial console device (from Day 15's `SERIAL_CONSOLES`), `root=` must match your actual rootfs partition device, and getting either wrong produces a kernel that boots but never reaches a usable console or can't mount its root filesystem (a very common "board boots to nothing" bug that's actually just a wrong `bootargs` string).

You can set default environment content from the Yocto side via a recipe-provided environment script rather than expecting someone to type it manually at a serial console every time (impractical for real deployment):

```bitbake
# in your BSP layer, u-boot .bbappend or environment recipe

SRC_URI:append = " file://my-board-uboot-env.txt"
```

Or, more commonly, U-Boot's default environment is compiled directly into the binary via `CONFIG_EXTRA_ENV_SETTINGS` in the Kconfig-level `.cfg` fragment — meaning your board boots correctly out of the box without any manual environment configuration step, which is what you want for anything you're actually shipping.

## `wic`'s role in bootloader deployment — tying back to Day 15's `.wks`

The `.wks` file doesn't just define partitions — it also determines _where the bootloader binary itself gets written_, which for many SoCs is at a fixed raw offset on the boot media _before_ any partition table even starts (since Boot ROM reads from a fixed offset, it has no concept of "partitions" at that stage):

```
# my-board.wks, extended from Day 15
bootloader --ptable gpt

part u-boot-spl --source rawcopy --sourceparams="file=u-boot-spl.bin" --ondisk mmcblk --no-table --align 1 --size 1
part u-boot --source rawcopy --sourceparams="file=u-boot.itb" --ondisk mmcblk --no-table --align 69 --size 2047
part /boot --source bootimg-partition --ondisk mmcblk --fstype=vfat --label boot --align 4 --size 64
part / --source rootfs --ondisk mmcblk --fstype=ext4 --label root --align 4
```

The `rawcopy` source type + explicit `--align`/offset values here are genuinely SoC-specific — these values come from your SoC vendor's boot media layout documentation (i.MX8M's Boot ROM expects SPL at a specific byte offset, for example), not something you derive generically. This is exactly the kind of detail you copy from a working reference BSP's `.wks` file for your SoC family and adjust only if your board's storage layout genuinely differs (different boot media size, added a raw partition for something board-specific).

## Practical debugging — a board that hangs after the U-Boot banner

Realistic diagnostic sequence, since this is one of the more common "I have real hardware now" problems:

```
# at the U-Boot prompt (interrupt boot by hitting a key during bootdelay)
=> printenv                    # inspect actual current environment
=> mmc info                    # confirm MMC/SD card is actually detected
=> ls mmc 0:1                  # confirm boot partition is readable, files are actually present
=> load mmc 0:1 ${kernel_addr_r} Image    # try the load step manually, see if it errors
=> bdinfo                      # confirm DRAM size/layout matches what CONFIG expects
```

This mirrors the `devshell`-style "manually reproduce the failing step" debugging philosophy from earlier days — instead of guessing why automated boot fails, drop to the interactive prompt and run each step of `bootcmd` by hand until you find exactly which one fails or produces unexpected output.

## Key takeaways

- The boot chain (Boot ROM → SPL → U-Boot proper → kernel → init) has distinct failure signatures at each stage — learn to read UART console output to identify _which_ stage stopped, rather than treating "won't boot" as one undifferentiated problem.
- `inherit uboot-config` bridges U-Boot's own Kconfig-based build into BitBake, structurally parallel to how `linux-yocto.bbclass` handles kernel config (Day 16) — same fragment-based `.cfg` customization pattern applies.
- Build-time config (Kconfig, what U-Boot is _capable_ of) is distinct from runtime environment (`bootcmd`/`bootargs`, what U-Boot _actually does_) — both must be correct, and confusing which one you need to change is a common source of wasted debugging time.
- `bootargs`' `console=` and `root=` values are frequent sources of "boots but produces nothing useful" bugs — verify these match your actual hardware/partition layout exactly.
- `.wks` raw-offset bootloader placement values (SPL/U-Boot proper location on boot media) are genuinely SoC-specific — source them from a working reference BSP for your SoC family, don't derive them generically.
- When hardware hangs post-banner, interrupt into the U-Boot prompt and manually replay `bootcmd`'s steps one at a time — same debugging philosophy as `devshell`, applied to the bootloader stage.
