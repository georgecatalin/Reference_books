
## 30-Day Yocto Project Curriculum — Syllabus

**Phase 1: Foundations (Days 1–7)**

1. What Yocto actually is, and the poky/layer/recipe/BitBake mental model
2. Build environment setup, `bitbake` basics, build directory anatomy
3. Layers deep dive: `bblayers.conf`, layer priority, layer dependencies
4. Recipes: anatomy of a `.bb` file, tasks, variables, `do_` functions
5. `local.conf` and `bblayers.conf` — the real configuration knobs that matter
6. Image recipes: what makes an image vs. a package recipe
7. Building your first custom image for QEMU — full walkthrough

**Phase 2: Core Mechanics (Days 8–15)** 8. BitBake task execution model: `do_fetch` → `do_patch` → `do_configure` → `do_compile` → `do_install` → `do_package` 9. Variable flags, overrides, and `:append`/`:prepend`/`:remove` syntax (post-3.4 syntax) 10. Writing recipes from scratch: a simple C program recipe 11. Writing recipes for autotools/CMake-based projects 12. Patching upstream source: `.patch` files, `devtool`, and `recipetool` 13. SRC_URI mechanics: fetchers, checksums, mirrors, and offline builds 14. Package management: RPM/IPK/DEB backends, `PACKAGES`, `FILES`, split packages 15. Machine configuration: writing a BSP layer for custom hardware

**Phase 3: Practical Systems Work (Days 16–22)** 16. Kernel customization: `linux-yocto` recipe, `.scc`/`.cfg` fragments, defconfig 17. U-Boot and bootloader integration 18. Device tree basics and Yocto's handling of DTBs 19. systemd integration, service recipes, and init management in Yocto images 20. Image customization: `IMAGE_INSTALL`, `IMAGE_FEATURES`, rootfs post-processing 21. `devtool` and `recipetool` workflows for real iterative development 22. Cross-compilation SDK generation (`bitbake -c populate_sdk`) and eSDK

**Phase 4: Advanced / Production (Days 23–30)** 23. Multiple configurations, `MACHINE`/`DISTRO` layering strategy for product lines 24. Yocto for your MQTT monitor stack: packaging Python (`bitbake-layers`, `python3-pip` class) and C++ services as recipes 25. Signed packages, image signing, secure boot basics 26. Read-only rootfs, OTA update strategies (Mender/RAUC overview) 27. Build performance: shared state cache (sstate), `PREMIRRORS`, distributed builds, `icecc` 28. CI/CD for Yocto: automated builds, `kas`, reproducibility 29. Debugging failed builds: `bitbake -D`, dev-shell, common failure patterns and how to actually diagnose them 30. Capstone: full custom distro layer for an IoT device — BSP + kernel config + systemd services + your MQTT monitor packaged in, from scratch

This mirrors the shape of your C++/Python/Docker curricula: concept → real code/config → key takeaways, no gating exercises, dense technical content. Let's start.
