[[Qt for Embedded Display targets]]

## Day 2: Platform Strategy — Boot2Qt vs. Yocto vs. Buildroot vs. Stock Raspberry Pi OS

### Concept: This Decision Costs Weeks If Made Wrong, and Almost Nobody Tells You the Real Tradeoffs

Every "Qt embedded" tutorial shows you `eglfs` running on stock Raspberry Pi OS and stops there, because it's the fastest path to a demo. That's a legitimate starting point, but it is **not** automatically the right choice for a product you intend to actually ship and maintain across multiple units, multiple board revisions, or multiple years. Today is a genuine decision framework, not a sales pitch for any one option — including the one I'll ultimately recommend for your situation.

### Option 1: Stock Raspberry Pi OS + `eglfs`

You install the normal Raspberry Pi OS (Debian-based, the one most people already know), disable the desktop environment from auto-starting, and run your Qt app directly via `eglfs` on top of it.

**Genuine advantages:**

- Zero custom build system to learn or maintain — `apt install` for anything you need, same package manager you already know from your dev machine.
- Fastest path from zero to a working embedded demo — hours, not weeks.
- Full Debian package ecosystem available if you need something beyond Qt (Python for a companion script, `mosquitto` itself running locally on the same board, standard debugging tools).

**Genuine costs, stated plainly:**

- **Boot time is slow** — a full Debian-derived OS boots in the 15–30+ second range on a Pi, even with the desktop disabled, because it's still booting a general-purpose distro's init sequence, not a purpose-built minimal image. If Day 5's "boot-to-app" experience matters to your product (a wall panel that should be usable within a few seconds of power applied), this is a real, hard-to-fully-fix constraint of this option specifically.
- **Harder to make genuinely read-only/power-loss-resilient** (Day 5's real concern for a device that might lose power ungracefully) — Debian's filesystem layout and package manager assume a writable root filesystem as a baseline assumption, and retrofitting read-only-root onto a general Debian install is real, fragile, non-standard work.
- **You're carrying a lot of OS you don't need** — a general-purpose desktop-oriented distro includes package management infrastructure, many services, and update mechanisms designed for a general computer, not a single-purpose appliance.

### Option 2: Yocto Project (the industry-standard embedded Linux build system)

Yocto is a **build system for constructing your own custom Linux distribution**, purpose-built for exactly your hardware and exactly your application — nothing more, nothing less. This is genuinely the industry-standard approach for shipped embedded Linux products at scale (companies building real commercial IoT/embedded-display products very often use this, or Qt's own Boot2Qt, which is itself built on Yocto).

**Genuine advantages:**

- **Minimal image, fast boot** — you include only what your product actually needs; boot times in the low single-digit seconds are realistic.
- **Read-only root filesystem is a first-class, well-supported pattern** — Yocto's whole design philosophy assumes purpose-built appliances, so power-loss resilience (Day 5) is a solved, documented problem here, not a fragile retrofit.
- **Reproducible builds** — a Yocto build is defined by recipes/layers in version control; two people building from the same layer set get bit-for-bit-comparable results, genuinely valuable once you have more than one person touching the embedded build.
- **You control exactly what's in the image** — no unnecessary services, no unnecessary attack surface, no unnecessary boot-time cost.

**Genuine costs, stated plainly, from real experience:**

- **The learning curve is real and non-trivial** — Yocto's layer/recipe/bitbake model is a genuinely different way of thinking about "what is a Linux distribution" than `apt install`, and the first successful custom image build for a new board is commonly a multi-day-to-multi-week undertaking the first time, not an afternoon.
- **Build times are long** — a full Yocto build from scratch can take hours on a capable build machine; this is a real iteration-speed cost during development that stock Raspberry Pi OS simply doesn't have.
- **Debugging is genuinely harder** — when something goes wrong in a Yocto-built image, you're debugging your own custom distribution, not a well-documented mainstream one with a huge community and Stack Overflow history behind it.

### Option 3: Buildroot (the lighter-weight alternative to Yocto)

Buildroot solves the same fundamental problem as Yocto — building a minimal, purpose-built embedded Linux image — with a genuinely simpler mental model (a single Kconfig-style menu-driven configuration, closer to configuring a Linux kernel build than Yocto's layer/recipe system) at the cost of being somewhat less flexible for very large or highly customized projects.

**The honest comparison, from practical experience**: Buildroot is the better choice when your embedded Linux needs are relatively contained (a handful of custom packages, one or two board targets) and you want to get to a minimal, fast-booting, read-only-capable image faster than Yocto's learning curve allows. Yocto is the better choice when the project is genuinely large, multi-board, multi-team, or needs Yocto-ecosystem-specific tooling (like the `meta-qt6` layer maintained alongside Qt itself, which gives you well-tested recipes for building Qt specifically for your target).

### Option 4: Qt's Own Boot2Qt

Boot2Qt is Qt Company's **commercial** embedded Linux offering — a Yocto-based distribution specifically pre-configured and tested for running Qt applications on supported boards (including several Raspberry Pi variants), with tooling integration in Qt Creator for deploying/debugging directly onto the device.

**Genuine advantages:**

- Removes essentially all of Yocto's learning-curve cost for the specific case of "I want a minimal, fast-booting Linux image that runs my Qt app well" — this is Boot2Qt's entire reason to exist.
- Qt Creator integration (one-click deploy/debug to the target) is genuinely convenient during active development.
- Commercially supported — if something's broken, there's a vendor to escalate to, which matters for some organizations' risk tolerance.

**Genuine costs, stated plainly**: **this requires a commercial Qt license** — it is not available under Qt's open-source licensing. For an individual developer or a small team not otherwise already paying for a commercial Qt license, this is very often a non-starter purely on cost grounds, independent of its technical merits. Worth knowing it exists and what it solves, but not a default recommendation unless commercial licensing is already part of your situation for other reasons.

### The Decision Framework

|Your situation|Recommendation|
|---|---|
|Prototyping, one-off, learning, "will this even work"|Stock Raspberry Pi OS + `eglfs`|
|Shipping a real product, solo/small team, contained scope|Buildroot|
|Shipping a real product, larger team, multiple boards, long-term maintenance|Yocto (`meta-qt6` layer)|
|Already have/willing to pay for commercial Qt licensing, want vendor support|Boot2Qt|

### The Concrete Recommendation for `mqtt_monitor` Specifically

Given everything established across the last two curricula — this is a solo/small-scale embedded monitoring dashboard, targeting Raspberry Pi hardware you already have experience with, currently at the "does this work at all as a real deployment" stage rather than "we are shipping thousands of units with a dedicated build engineer" — **the honest recommendation is: start with stock Raspberry Pi OS + `eglfs` for now, and treat Buildroot as the deliberate next step only once/if a specific real requirement forces it** (boot time genuinely matters for the deployment, or power-loss resilience becomes a real field problem rather than a theoretical one).

This mirrors the exact judgment call from Day 29 of the Widgets curriculum about plugin architecture: **don't adopt the more elaborate infrastructure preemptively because it's the "proper" industry answer** — adopt it when a concrete requirement you actually have demands it. Yocto's learning curve and build-time cost are real, ongoing taxes; paying them before you have a requirement that needs them is premature complexity, not diligence.

### Why This Matters

- **This decision is expensive to reverse** — switching from stock Raspberry Pi OS to Yocto/Buildroot later means rebuilding your entire deployment/testing/update workflow, not just recompiling your app. Worth making this call deliberately, in writing, rather than drifting into whichever option a tutorial happened to use.
- **Boot2Qt's licensing requirement is the single most common thing people don't realize until they're partway into evaluating it** — worth knowing upfront rather than investing evaluation time before discovering the cost barrier.
- **"Industry standard" (Yocto) isn't automatically "correct for you"** — the same over-engineering caution from Day 29's plugin architecture discussion applies here at the OS-selection level, and it's worth resisting the pull toward the more sophisticated-sounding option without a concrete requirement driving it.
- **Your existing Widgets/QML work is completely portable across all four options** — this decision affects the OS/build system underneath your app, not the Qt application code itself; nothing from the previous 40 days needs to change based on which platform choice you make here.

### Exercise

1. Write a one-paragraph note (same exercise pattern as Day 27/Widgets and Day 5/QML) making this decision explicitly for your actual `mqtt_monitor` deployment plan — which option, and specifically what concrete future requirement (not a hypothetical one) would change that decision.
2. If you have a spare SD card and a Pi, do the fastest possible validation of Option 1: flash stock Raspberry Pi OS Lite (the no-desktop variant — a smaller, more appropriate starting point than the full desktop image even for this "quick start" option), disable/skip the desktop entirely, and get to a shell prompt with no GUI ever having started — this is your actual Day 3 starting point.
3. Research (without necessarily installing) what `meta-qt6` actually provides in the Yocto ecosystem, specifically for Raspberry Pi targets — just enough to know it exists and roughly what problem it solves, so if a future requirement does push you toward Yocto, you're not starting that research from zero.

### Key Takeaways

- Four real options exist: stock Raspberry Pi OS + `eglfs` (fastest start, weakest boot-time/read-only story), Buildroot (lighter-weight custom-image path), Yocto (industry-standard, real learning-curve and build-time cost, best long-term maintainability), and Boot2Qt (Yocto-based, commercially licensed, removes most of Yocto's learning curve at real monetary cost).
- This decision is expensive to reverse — it affects your entire deployment/testing/update workflow, not just a build flag, so make it deliberately rather than by tutorial-default.
- For `mqtt_monitor` specifically, at its current stage: stock Raspberry Pi OS + `eglfs` is the honest, correct starting recommendation — treat Yocto/Buildroot as the deliberate next step once a concrete requirement (not a hypothetical one) actually demands it.
- None of your existing Widgets/QML application code is affected by this choice — it's purely an OS/build-system-layer decision sitting underneath work you've already done.
