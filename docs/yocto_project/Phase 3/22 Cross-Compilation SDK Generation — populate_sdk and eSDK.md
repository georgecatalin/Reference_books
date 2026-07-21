[[Practical Systems Work]]
# Day 22: Cross-Compilation SDK Generation — `populate_sdk` and eSDK

This closes Phase 3. So far, all development has happened _inside_ the Yocto build tree — editing recipes, running `bitbake`/`devtool` from within a poky checkout. That's correct for BSP/recipe/image work, but it's the wrong workflow for an **application developer** (possibly a teammate, possibly future-you working on just the `mqtt_monitor` application logic) who needs to cross-compile and test code without understanding or touching the full Yocto build system. SDKs solve this.

## The problem SDKs solve

Your `mqtt_monitor` C++ capstone has real application logic that changes far more frequently than the underlying BSP/kernel/image configuration. Requiring every application-level code change to go through a full `bitbake mqtt-monitor` cycle (parse the whole metadata tree, resolve the whole dependency graph, potentially rebuild sstate-invalidated things) is unnecessarily heavy for someone who just wants to compile `main.cpp`, link against the correct target libraries, and test the binary — they don't need to understand layers, recipes, or BitBake's task model at all.

An SDK packages _just_ the cross-compilation toolchain plus target sysroot (headers/libs matching exactly what's on your actual device image) into a self-contained installer, usable completely independently of the Yocto build tree.

## Standard SDK — `populate_sdk`

```bash
bitbake mqtt-monitor-image -c populate_sdk
```

This produces a self-extracting installer script in `tmp/deploy/sdk/`:

```
poky-glibc-x86_64-mqtt-monitor-image-cortexa53-raspberrypi4-64-toolchain-5.0.sh
```

The naming encodes: host arch (`x86_64`), the image it was generated _from_ (`mqtt-monitor-image` — meaning the sysroot matches exactly what's in that image, not a generic "everything OE-Core knows how to build"), target arch (`cortexa53`), and machine (`raspberrypi4-64`).

Installing it (on any machine, no Yocto/poky checkout needed at all):

```bash
./poky-glibc-x86_64-mqtt-monitor-image-cortexa53-raspberrypi4-64-toolchain-5.0.sh
# installs to /opt/poky/5.0/ by default
```

Using it — this is the part that matters for a teammate's actual daily workflow:

```bash
source /opt/poky/5.0/environment-setup-cortexa53-poky-linux
```

This single `source` command sets `CC`, `CXX`, `CFLAGS`, `LDFLAGS`, `PKG_CONFIG_PATH`, and a full `--sysroot` pointing at the target's headers/libs — after sourcing it, a completely standard build invocation cross-compiles correctly:

```bash
cd mqtt-monitor-cpp
mkdir build && cd build
cmake .. -DCMAKE_TOOLCHAIN_FILE=$OECMAKE_TOOLCHAIN_FILE
make
```

Notice: this is _not_ `bitbake` at all — it's a normal CMake invocation, just with environment variables correctly pointed at the cross-toolchain. This is precisely the point: someone working purely on `mqtt_monitor` application code needs zero Yocto/BitBake knowledge to build and test correctly against the target's actual headers/libraries, once handed this SDK.

## What's actually inside the sysroot — and why it must match the image

The SDK's sysroot contains exactly the headers/libraries corresponding to what `mqtt-monitor-image` actually installs (per `-c populate_sdk` being run _against_ that specific image target) — meaning if your image includes `mosquitto`'s `-dev` package (headers), the SDK sysroot has `mosquitto.h` available for `#include`; if it doesn't, compilation fails with a missing header, correctly reflecting that the actual deployed device wouldn't have that library either. This tight coupling is deliberate — it prevents the classic "compiles fine in the SDK, fails to link on the real device" class of bug that plagues less rigorous cross-compilation setups (e.g., a generic ARM cross-toolchain not tied to any specific target image's actual library set).

## The Extensible SDK (eSDK) — when the standard SDK isn't enough

The standard SDK is read-only in a meaningful sense: it gives you headers/libs to compile _against_, but if a teammate needs to add a new dependency, patch a library, or genuinely needs BitBake-level capability without a full poky checkout, the standard SDK can't do that.

```bash
bitbake mqtt-monitor-image -c populate_sdk_ext
```

The eSDK installer includes a **stripped-down but functional BitBake environment**, plus a curated subset of your layers/sstate cache — meaning the recipient actually has:

```bash
./poky-glibc-x86_64-mqtt-monitor-image-cortexa53-raspberrypi4-64-toolchain-ext-5.0.sh
source /opt/poky/5.0/environment-setup-cortexa53-poky-linux
devtool build mqtt-monitor
```

Real `devtool` commands work inside an eSDK installation — a teammate can `devtool modify mqtt-monitor`, edit source, `devtool build`, all without ever cloning the full poky/meta-openembedded/meta-raspberrypi layer set themselves. The eSDK pre-populates enough sstate cache that most builds are fast (cache hits) rather than triggering real compilation of the whole dependency chain from scratch.

## Practical decision: standard SDK vs eSDK vs full Yocto checkout

|Role|Right tool|
|---|---|
|Application developer, only touches `mqtt_monitor` source, never touches recipes/layers|Standard SDK|
|Application developer who occasionally needs to add a new library dependency or patch something, but shouldn't need the full layer stack|eSDK|
|BSP/recipe/kernel/systems engineer (your actual role for most of this curriculum)|Full poky checkout with all layers, as used throughout Days 1–21|

For your specific situation — you're likely to remain the person doing both BSP work _and_ application work for the foreseeable future — the full checkout remains your primary environment. The SDK/eSDK matters the moment this becomes a team effort: handing a teammate an SDK means they're productive on application code within minutes of installing one file, without needing to understand anything from Days 1–21 at all. This is a genuinely significant practical/organizational payoff, not just a technical nicety.

## `devtool` from _outside_ the build tree — combining Day 21 with this day

Worth explicitly noting: everything in Day 21's `devtool` workflow (`devtool modify`, `devtool build`, `devtool deploy-target`) works identically whether you're inside a full poky build tree or inside an eSDK installation. The eSDK is genuinely "the same devtool experience, minus the full layer checkout weight" — not a different/limited tool.

## SDK regeneration cadence — a practical maintenance note

The SDK is a snapshot tied to the image/recipe state at the moment you ran `populate_sdk`/`populate_sdk_ext`. If your BSP/kernel/dependency versions change meaningfully (a `mosquitto` version bump, a new library dependency added to the image), the SDK you handed out earlier is now stale — headers won't match, and worse, a teammate might not immediately realize their SDK predates a relevant change. Practical practice: regenerate and redistribute the SDK as part of your normal release/tagging process (Day 28's CI territory), not as an ad-hoc "someone asks for it" event — treat it as a build artifact with the same versioning discipline as the image itself.

## Key takeaways

- SDKs decouple application development from the full Yocto build tree — a teammate gets a working cross-toolchain + matching target sysroot from one self-extracting installer, no BitBake/layer knowledge required.
- The sysroot is generated _from a specific image target_ — it contains exactly what that image actually ships, which is what prevents "compiles in SDK, fails on real device" mismatches.
- Standard SDK (`populate_sdk`) = toolchain + sysroot only, standard build tool invocations (cmake/make) after sourcing the environment script.
- Extensible SDK (`populate_sdk_ext`) = adds a working `devtool`/BitBake environment plus curated sstate — for teammates who need to add dependencies or patch things without a full layer checkout.
- `devtool`'s workflow (Day 21) is identical inside an eSDK vs. a full build tree — same tool, lighter-weight environment.
- SDKs are snapshots, not living artifacts — regenerate and redistribute them as part of your normal release process, not reactively, or teammates will silently work against stale headers/libraries.

