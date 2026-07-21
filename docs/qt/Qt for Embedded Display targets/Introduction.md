[[Qt for Embedded Display targets]]

Good call — this is genuinely a different problem than anything in the previous 40 days. Both prior curricula assumed _some other OS layer_ (a desktop environment, a window manager, X11/Wayland) was already there hosting your app. "Qt for embedded display targets directly" means **your Qt app is the only thing running on the screen** — no desktop, no window manager, sometimes no filesystem writeback at all. That's a narrower, much more infrastructure-and-deployment-shaped topic than API surface to learn, so I'm scoping this at **7 days**, not 30 or 10 — there isn't 30 days of new API here, there's about a week of genuinely different platform decisions, each with real consequences if you get them wrong on hardware you can't easily re-flash from your desk.

## The 7-Day Embedded Display Curriculum

**Day 1** — The paradigm shift: direct-to-display rendering, why X11/Wayland disappear entirely, the three real backend choices (EGLFS, LinuxFB, DRM/KMS) **Day 2** — Platform strategy: Boot2Qt vs. Yocto vs. Buildroot vs. stock Raspberry Pi OS + EGLFS — real cost/maintenance/licensing tradeoffs, and a concrete recommendation for your situation **Day 3** — Cross-compiling _Qt itself_ for the target (not just your app) — this is new territory beyond Day 27 of the Widgets curriculum, which only cross-compiled the application **Day 4** — Input without a window manager: touchscreen calibration, `libinput`/`evdev` directly, multi-touch, on-screen keyboard **Day 5** — Boot-to-app: kiosk-mode systemd service, splash screen, watchdog/crash recovery, read-only rootfs for power-loss resilience **Day 6** — Performance on bare-metal rendering: vsync/tearing without a compositor, real GPU driver differences across Pi generations, profiling on-device **Day 7** — Capstone: turning your actual QML `mqtt_monitor` panel into a real bootable embedded image plan, plus OTA update strategy and a closing decision framework

Let's start.

