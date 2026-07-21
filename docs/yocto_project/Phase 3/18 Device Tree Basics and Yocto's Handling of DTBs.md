[[Practical Systems Work]]
# Day 18: Device Tree Basics and Yocto's Handling of DTBs

Days 15–17 referenced `KERNEL_DEVICETREE` and `.dtb` files without explaining what a device tree actually does. This day covers device tree fundamentals precisely enough to write and debug your own board's DT, plus exactly how Yocto builds and deploys DTBs — essential the moment you move from a reference dev board's DT (which already describes hardware you're not using) to your own custom PCB.

## What a device tree actually is, and why it exists

On x86, the kernel discovers hardware dynamically (PCI enumeration, ACPI tables) — it doesn't need to be told in advance what's connected. Most embedded ARM/SoC hardware has no such discovery mechanism for on-board peripherals (I2C devices, SPI devices, GPIO-connected sensors, specific UART routing) — the kernel has no way to know your board has an RS485 transceiver on UART3 with a GPIO-controlled direction pin unless something tells it. **Device tree is that "something"** — a data structure, compiled from human-readable `.dts` source into a binary `.dtb`, describing the hardware topology: what's connected to what, at what address, using which driver binding.

This is precisely why the same kernel binary (`Image`) can boot correctly on wildly different boards of the same SoC family — the kernel code is identical; only the `.dtb` passed alongside it differs, describing that specific board's actual hardware.

## Anatomy of a `.dts` file — the parts that matter for real board work

```dts
// my-board.dts
/dts-v1/;
#include "imx8mm.dtsi"
#include "imx8mm-my-board-pinctrl.dtsi"

/ {
    model = "MyCompany MQTT Monitor Board Rev B";
    compatible = "mycompany,my-board", "fsl,imx8mm";

    chosen {
        stdout-path = &uart3;
    };

    reserved-memory {
        /* ... */
    };
};

&uart3 {
    pinctrl-names = "default";
    pinctrl-0 = <&pinctrl_uart3>;
    status = "okay";
    linux,rs485-enabled-at-boot-time;
    rs485-rts-active-low;
};

&i2c1 {
    status = "okay";

    rtc@68: rtc@68 {
        compatible = "dallas,ds3231";
        reg = <0x68>;
    };
};

&gpio1 {
    status = "okay";
};
```

Key mechanics worth understanding precisely:

- **`#include "imx8mm.dtsi"`**: device trees are _layered/compositional_ just like Yocto recipes — `.dtsi` (device tree source **include**) files describe the SoC itself (all its peripherals, in their generic/disabled state); your board's `.dts` includes the SoC `.dtsi` and then _enables and configures_ the specific peripherals your board actually wires up. You never redefine the whole SoC from scratch — you diverge from the vendor-provided `.dtsi`, same philosophy as Day 15's "start from reference BSP and diverge."
- **`&uart3 { ... }`**: the `&` syntax is a **label reference** — this block doesn't define a new node, it _modifies_ the existing `uart3` node already defined in the included `.dtsi`, adding board-specific properties (pin muxing, RS485 mode) on top of the SoC-level generic definition. This is directly analogous to a Yocto `.bbappend` modifying a recipe without forking it — same compositional philosophy applied to hardware description instead of build metadata.
- **`status = "okay"`**: SoC `.dtsi` files typically define every peripheral the silicon supports with `status = "disabled"` by default (since not every board uses every peripheral) — your board `.dts` explicitly enables (`"okay"`) only what's actually present/wired on your specific PCB. Forgetting this is a very common "driver builds fine, module loads, but device never shows up" bug — the peripheral is disabled in DT even though the kernel driver and config are both correct.
- **`compatible = "mycompany,my-board", "fsl,imx8mm"`**: this string is what actually binds a kernel driver to a device — drivers declare which `compatible` strings they handle via `of_match_table` in their C source; the DT's `compatible` property is the lookup key the kernel uses to match hardware description to driver code. Get this wrong (typo, wrong vendor prefix) and the driver simply never binds, silently.
- **`rtc@68` with `reg = <0x68>`**: this is how I2C devices are described — the `@68` in the node name is convention (should match the address), `reg = <0x68>` is the actual I2C address the kernel driver will use. A wrong address here means the driver probes the wrong device and fails (or worse, talks to the wrong chip).

## Pin muxing — the `.dtsi` layer most custom boards actually need to touch

Nearly every custom board needs pin control (pinctrl) adjustments — telling the SoC which physical pins are muxed to which peripheral function (a single physical pin on these SoCs can typically serve as GPIO, UART, I2C, or SPI depending on mux configuration):

```dts
// imx8mm-my-board-pinctrl.dtsi
&iomuxc {
    pinctrl_uart3: uart3grp {
        fsl,pins = 
            MX8MM_IOMUXC_UART3_RXD_UART3_DCE_RX    0x140
            MX8MM_IOMUXC_UART3_TXD_UART3_DCE_TX    0x140
            MX8MM_IOMUXC_ECSPI1_SCLK_GPIO5_IO6      0x19  /* RS485 direction control GPIO */
        >;
    };
};
```

These pin macro names (`MX8MM_IOMUXC_UART3_RXD_UART3_DCE_RX`) and the hex mux/pad-config values are SoC-vendor-specific and documented in the SoC's reference manual/pinout tool (NXP provides a pin muxing tool for i.MX parts, for example) — this is genuinely hardware documentation lookup work, not something inferred generically. This is usually the single most tedious/error-prone part of bringing up custom hardware, and it's worth budgeting real time for it rather than treating it as a quick config step.

## How Yocto builds and deploys the DTB — the actual mechanism

Tying back to Day 16: `linux-yocto`'s build (via `kernel-devicetree.bbclass`, auto-inherited when needed) compiles `.dts`/`.dtsi` files using the kernel's own device tree compiler (`dtc`) as part of the normal kernel build, and `KERNEL_DEVICETREE` (Day 15's machine config) tells Yocto which resulting `.dtb` to actually stage into `tmp/deploy/images/<machine>/`.

```bitbake
KERNEL_DEVICETREE = "freescale/imx8mm-my-board.dtb"
```

For your **own** board's DT source (not present in mainline/vendor kernel), you add it via the same mechanism as Day 16's kernel patches — a `.bbappend` on your kernel recipe:

```bitbake
# linux-my-kernel_%.bbappend

FILESEXTRAPATHS:prepend := "${THISDIR}/files:"

SRC_URI:append = " file://imx8mm-my-board.dts \
                   file://imx8mm-my-board-pinctrl.dtsi"

do_configure:append() {
    cp ${WORKDIR}/imx8mm-my-board.dts ${S}/arch/${ARCH}/boot/dts/freescale/
    cp ${WORKDIR}/imx8mm-my-board-pinctrl.dtsi ${S}/arch/${ARCH}/boot/dts/freescale/
}
```

(Some BSP layer conventions handle this file placement more elegantly via `KERNEL_DEVICETREE` + a proper `.scc`-managed patch instead of a raw `do_configure:append` file copy — the copy approach shown is the more transparent/beginner-clear version; once comfortable, the `.scc`+patch approach from Day 16 is the cleaner production pattern since it version-controls the DT addition as a proper patch against the kernel tree rather than an out-of-band file copy.)

## Device tree overlays — for runtime-composable hardware (less common, worth knowing exists)

Raspberry Pi's ecosystem popularized **DT overlays** — small, separately-compiled `.dtbo` fragments applied _on top of_ a base DTB at boot time (via U-Boot or `config.txt`-driven mechanisms on RPi specifically), rather than requiring a full DTB recompile for every hardware add-on combination (HATs, capes). This matters if you're prototyping with RPi HATs/BeagleBone capes before your custom PCB is ready:

```bitbake
# meta-raspberrypi convention
RPI_KERNEL_DEVICETREE_OVERLAYS += "my-hat-overlay.dtbo"
```

For a genuinely fixed custom product board, you typically don't need overlays — your hardware configuration is fixed and known at build time, so a single complete `.dts` describing the final board is simpler and sufficient. Overlays earn their complexity specifically for "hardware configuration varies at runtime/deployment time" scenarios (interchangeable HATs/capes), not fixed custom designs.

## Debugging device tree problems — practical tools

```bash
# On the built target, inspect what the KERNEL actually sees, at runtime:
ls /proc/device-tree/
cat /proc/device-tree/soc/i2c@.../rtc@68/compatible

# Decompile a built .dtb back to human-readable form, to verify what actually got compiled:
dtc -I dtb -O dts tmp/deploy/images/my-board/imx8mm-my-board.dtb
```

`/proc/device-tree/` on a running target is ground truth — it's the _actual_ parsed device tree the currently-running kernel is using, which is invaluable when you're unsure whether your `.dts` change actually made it into the deployed DTB versus a stale build artifact still being used. `dtc -I dtb -O dts` (decompile) run against your build output is the build-time equivalent check — confirms your source changes actually compiled through correctly before you even flash to hardware.

## Key takeaways

- Device tree describes hardware topology to a generic kernel binary — same kernel, different `.dtb` per board, is exactly why Yocto treats kernel and DTB as somewhat separable build outputs.
- `.dtsi` (SoC-level, mostly-disabled peripherals) + board `.dts` (enables/configures specific peripherals via `&label` reference syntax) mirrors the same compositional philosophy as Yocto layers/`.bbappend` — diverge from a vendor base, don't rewrite from scratch.
- `status = "okay"` and `compatible = "..."` are the two properties most responsible for "driver exists, module loads, but device doesn't show up" bugs when wrong/missing.
- Pin muxing (`.dtsi` pinctrl blocks) is genuinely tedious, SoC-vendor-documentation-driven work — budget real time for it on custom hardware bring-up.
- `KERNEL_DEVICETREE` + a kernel recipe `.bbappend` (file copy or, better, `.scc`-managed patch) is how your own board's DT source enters the Yocto build.
- DT overlays solve "runtime-variable hardware configuration" (HATs/capes) — a fixed custom product board usually doesn't need them; a single complete `.dts` is simpler and correct for that case.
- `/proc/device-tree/` on-target and `dtc -I dtb -O dts` on build output are your two ground-truth verification tools — use them instead of assuming a DT change took effect.

