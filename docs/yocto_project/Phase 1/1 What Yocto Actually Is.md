[[Foundations]]
## The core mental model

Forget "Linux distribution" for a second. Yocto is **not a distro** — it's a _build system that produces distros_. This distinction matters because it explains everything else.

When you build embedded Linux the "naive" way, you're manually cross-compiling a kernel, a bootloader, a C library, and a pile of userspace tools, then hand-assembling a root filesystem. That's what people did before Yocto (and what tools like Buildroot still do in a more limited way). Yocto exists to make that reproducible, maintainable, and scalable across product lines.

Four pieces you need in your head, in order of increasing abstraction:

### 1. BitBake — the task execution engine

BitBake is the actual build tool. It's like `make`, but instead of a Makefile, it executes **recipes** written in a metadata language that's part Python, part shell. BitBake's job: resolve dependencies between recipes, schedule tasks, execute them (often in parallel), and cache results.

You will almost never invoke BitBake directly for anything except:

```bash
bitbake <target>
```

Where `<target>` is a recipe name or image name. That's it. Everything else is what BitBake does _for_ you underneath.

### 2. Metadata — recipes, classes, and configuration

This is the actual content you write and maintain. Three file types:

- **`.bb` files (recipes)** — describe how to build _one piece of software_. E.g., `mosquitto_2.0.18.bb` describes how to fetch, configure, compile, and package the Mosquitto broker.
- **`.bbclass` files (classes)** — shared logic reused across recipes (e.g., `autotools.bbclass` gives every autotools-based recipe its `do_configure`/`do_compile` implementation for free).
- **`.conf` files (configuration)** — global settings: what machine you're targeting, what distro policy applies, where things get installed.

### 3. Layers — how metadata is organized and reused

A **layer** is just a directory with a specific structure (`conf/layer.conf` + `recipes-*/` subdirectories) that bundles related recipes/classes/config together. Layers are Yocto's modularity mechanism — instead of one giant monolithic tree, you compose functionality from layers:

- `meta` — OpenEmbedded-Core, the base layer (always present)
- `meta-poky` — Poky's distro policy layer
- `meta-yocto-bsp` — reference board support
- `meta-openembedded` — a huge community layer with extra recipes (Python packages, networking libs, etc. — you'll want `meta-python` and `meta-networking` from here for your MQTT work)
- Your own layers — e.g., `meta-mycompany`, `meta-mqtt-monitor`

Layers stack. Priority determines which layer "wins" if two layers provide a recipe with the same name (e.g., you override a recipe from `meta-openembedded` in your own layer).

### 4. Poky — the reference distribution

**Poky = OpenEmbedded-Core + BitBake + a default distro/machine configuration.** It's not something you use in production as-is; it's the reference/starting point Yocto ships so you have a working baseline to build from and diverge. When you clone "poky" from git, you're getting BitBake + meta + meta-poky + meta-yocto-bsp bundled together.

**Key distinction that trips people up:** "Yocto Project" is the overall project/ecosystem (governance, tools, docs). "OpenEmbedded" is the build system architecture and metadata layer collection that Yocto is built on top of (OpenEmbedded existed before Yocto; Yocto standardized around it). "Poky" is the reference distro/integration. In casual conversation everyone just says "Yocto" for the whole stack — that's fine, but knowing the actual pieces helps when you're reading docs or layer READMEs that say "OE-Core" or "OpenEmbedded compatible."

## The build flow, top to bottom

Here's the mental pipeline for a single recipe, which you'll see explicitly in Day 8, but you need the shape now:

```
SRC_URI (fetch source) 
   → do_unpack 
   → do_patch (apply .patch files) 
   → do_configure (autotools/cmake/custom) 
   → do_compile 
   → do_install (into a staging "image" dir per-recipe) 
   → do_package (split into packages: -dev, -dbg, -doc, main) 
   → do_package_write_ipk / _rpm / _deb 
   → (later) do_rootfs pulls selected packages into the final image
```

Every recipe goes through this. An **image recipe** (like `core-image-minimal`) doesn't compile anything itself — its job is to declare _which packages_ get installed into the root filesystem, then trigger `do_rootfs`, which assembles everything into a bootable image (ext4, wic, tar, etc. depending on `IMAGE_FSTYPES`).

## Why this matters for your work specifically

Your C++ `mqtt_monitor` and Python `mqtt_monitor` capstones were built and tested on a dev machine or against a generic embedded target. Yocto is how you'd turn that into: "here is a custom Linux image, purpose-built for our device, that boots straight into our monitor service via systemd, with only the packages we need, reproducibly, versioned, and buildable by CI." That's the production end-state this whole curriculum is walking toward (Day 24 and Day 30 specifically).

## Terminology reference (keep this handy — it's used constantly and inconsistently in casual docs)

|Term|What it actually means|
|---|---|
|`MACHINE`|Target hardware definition (e.g., `raspberrypi4-64`, `beaglebone-yocto`)|
|`DISTRO`|Distribution policy (C library choice, init system, features)|
|Recipe (`.bb`)|Build instructions for one software component|
|Append (`.bbappend`)|A file that _modifies_ an existing recipe from another layer without editing it directly|
|Class (`.bbclass`)|Shared/inherited build logic|
|Task|A discrete build step (`do_compile`, `do_install`, etc.)|
|Provider|The recipe that satisfies a given dependency name (there can be multiple; `PREFERRED_PROVIDER` picks one)|
|sstate (shared state cache)|Cached task outputs, keyed by input hash — this is why rebuilds are fast|
|BSP (Board Support Package)|A layer (or part of one) providing machine-specific kernel config, bootloader, device tree|
|WIC|Yocto's image partitioning/formatting tool (produces `.wic` disk images)|

## Key takeaways

- Yocto is a **build system**, not a distro. It produces distros.
- BitBake executes tasks defined by recipes; you rarely call anything but `bitbake <target>`.
- Layers are the unit of modularity — you compose your build from `meta` + `meta-openembedded` + BSP layers + your own layers.
- Poky is the reference bundle (BitBake + OE-Core + default policy), not a production template you ship as-is.
- Every recipe (including image recipes) walks the same task pipeline: fetch → patch → configure → compile → install → package.
- For your work: this is the layer between "code that runs" and "a bootable device image," which is the gap between your current MQTT monitor projects and a real product build.

Ready for Day 2 (build environment setup, `bitbake` invocation, and build directory anatomy) whenever you want to continue — say the word and I'll proceed straight through without pausing for confirmation each time, or you can tell me to slow down/batch multiple days per message if you'd prefer that pacing.