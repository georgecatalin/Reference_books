[[Core Execution Mechanics]]
# Day 14: Package Management Deep Dive — Backends, `PACKAGES`, `FILES`, and Splitting

Day 6 mentioned `do_package` splits `${D}` into multiple output packages automatically. This day covers exactly how that splitting works, how to control it, and how the three package backends actually differ in practice — directly relevant when you decide `ipk` vs `rpm` for your production image (Day 5 flagged this decision; here's the full reasoning).

## How automatic package splitting actually works

After `do_install` populates `${D}` (Day 4), `do_package` doesn't just wrap the whole thing into one package — it walks `${D}` and distributes files into multiple output packages based on `PACKAGES` and `FILES:<pkg>` rules, most of which are **default conventions** you don't have to write yourself:

```bitbake
PACKAGES = "${PN}-src ${PN}-dbg ${PN} ${PN}-doc ${PN}-locale ${PN}-dev ${PN}-staticdev"
```

This is roughly OE-Core's default `PACKAGES` ordering (defined in `bitbake.conf`/`package.bbclass`, inherited automatically — you don't write this line yourself typically, but you can reorder or extend it). Order matters: BitBake processes `FILES:<pkg>` rules for each package **in the order listed**, and once a file is claimed by an earlier package, later packages don't get it — this is why `-dev` and `-dbg` are positioned where they are, so they claim their specific patterns before the generic main package sweeps up everything else.

Default `FILES` patterns (again, defined once in `bitbake.conf`, inherited automatically):

```bitbake
FILES:${PN}-dev = "${includedir} ${libdir}/*.la ${libdir}/*.so ${libdir}/pkgconfig ${bindir}/*-config"
FILES:${PN}-dbg = "${bindir}/.debug ${libdir}/.debug ${sbindir}/.debug"
FILES:${PN}-doc = "${mandir} ${infodir} ${docdir}"
FILES:${PN}-staticdev = "${libdir}/*.a"
FILES:${PN} = "${bindir}/* ${sbindir}/* ${libdir}/*.so.* ${sysconfdir} ..."
```

This is _why_ Day 10's two-file recipe produced `-dbg`/`-doc`/`-src` packages automatically without you writing any `PACKAGES`/`FILES` logic — the defaults already cover the common conventions (headers → `-dev`, debug symbols → `-dbg`, man pages → `-doc`).

## When and how you customize this — your actual mqtt-monitor case

Say your C++ `mqtt-monitor` recipe installs a binary, a systemd unit, a default config file, and a small plugin `.so` that's genuinely optional (say, a camera-integration plugin most deployments don't need):

```bitbake
PACKAGES =+ "${PN}-camera-plugin"

FILES:${PN}-camera-plugin = "${libdir}/mqtt-monitor/plugins/camera.so"
FILES:${PN} += "${systemd_unitdir}/system/mqtt-monitor.service ${sysconfdir}/mqtt-monitor/*"

RRECOMMENDS:${PN} += "${PN}-camera-plugin"
```

- **`PACKAGES =+ "..."`**: the `=+` operator **prepends** with automatic space handling — critical here because package processing order matters (Day's earlier point) and you generally want your custom packages considered _before_ the generic catch-all `${PN}` package claims everything, or your plugin file gets swept into the main package instead of splitting out.
- **`RRECOMMENDS`**: a _soft_ runtime dependency — "install this too by default if possible, but don't hard-fail if it's unavailable/excluded," as distinct from `RDEPENDS` (hard requirement, `do_rootfs` fails if unsatisfiable). Use `RRECOMMENDS` for genuinely optional companion packages; `RDEPENDS` for things the software actually can't function without.

This lets a device image that doesn't need the camera plugin exclude it via `IMAGE_INSTALL:remove = "mqtt-monitor-camera-plugin"` (or simply never having `RRECOMMENDS` pull it if `PACKAGE_EXCLUDE` is set) — without needing a separate recipe or build variant. Real production value: one recipe, one build, multiple deployable package combinations depending on target hardware capability.

## `ipk` vs `rpm` vs `deb` — the actual practical differences

Revisiting Day 5's deferred decision now with full context:

|Aspect|ipk (opkg)|rpm (dnf/smart)|deb (apt)|
|---|---|---|---|
|Metadata overhead|Minimal — small embedded footprint|Heavier — richer metadata (fuller dependency graphs, versioned constraints, more sophisticated resolver)|Moderate|
|Dependency resolution|Basic, doesn't always auto-resolve complex conflicts elegantly|Full SAT-style resolver via dnf — handles complex version constraints well|Solid via apt, mature tooling|
|Field update tooling maturity|Simple, works, limited transaction rollback|Richer transaction/rollback semantics in dnf|Very mature, best tooling ecosystem (but heavier)|
|Typical use case|Small embedded/IoT images, minimal footprint priority|Larger/product-line images with genuinely complex package interdependencies, or teams wanting mature enterprise-style update tooling|Teams with existing Debian/Ubuntu tooling investment wanting familiarity|
|Yocto ecosystem maturity|Original/traditional choice, extremely well-tested|Increasingly common for more complex product deployments|Less common in embedded but fully supported|

For your actual situation — RPi/BeagleBone, MQTT monitor, moderate package count, not managing a huge fleet with complex per-device package variance yet — `ipk` remains the pragmatic default. The reasoning to switch to `rpm` would specifically be: you need robust rollback-capable field package updates at the _individual package_ level (as opposed to whole-image OTA, which Day 26 covers as often the better strategy for embedded anyway) or you have complex optional feature combinations across a large recipe set where a real SAT resolver earns its overhead. Absent those specific needs, don't add `rpm`'s overhead by default.

## Package feeds — serving packages to a running device for updates

Beyond the image-build-time package selection, Yocto can generate a **package feed** — a repository of `.ipk`/`.rpm`/`.deb` files that a running device's package manager can pull updates from over the network, independent of reflashing the whole image:

```bitbake
IMAGE_FEATURES += "package-management"
PACKAGE_FEED_URIS = "http://update-server.internal.example.com/feeds/mqtt-monitor"
```

`package-management` (mentioned in Day 5/6) is what keeps `opkg`/`rpm`/`apt` itself present and functional on-target rather than stripped after rootfs assembly — without it, a device can't apply _any_ package-level update post-deployment, only full image reflashing. Whether you actually want per-package field updates vs. whole-image OTA (Day 26) is a real architectural decision — per-package updates are lighter-weight and faster to apply but harder to guarantee a fully-tested, consistent system state; whole-image OTA is heavier but gives you exactly the tested combination you validated in CI. Many embedded teams land on whole-image OTA specifically to avoid the "which combination of package versions is actually running on device #4471 in the field" problem that per-package updates can create over time.

## Inspecting real package output — the diagnostic tools

```bash
oe-pkgdata-util list-pkgs mqtt-monitor          # what packages did this recipe produce
oe-pkgdata-util list-pkg-files mqtt-monitor      # what files are in the main package
oe-pkgdata-util lookup-pkg camera.so              # which package claimed a given file
oe-pkgdata-util read-value PKGSIZE mqtt-monitor   # installed size of a package
```

`lookup-pkg` specifically is valuable when you're confused about _why_ a file ended up in an unexpected package — rather than re-reading `FILES` rules and mentally tracing precedence, ask the tool directly what actually happened.

## `ALLOW_EMPTY` and packages that intentionally contain nothing

Sometimes a `PACKAGES` entry legitimately has zero files (e.g., a metapackage that exists purely to declare `RDEPENDS` bundling several other packages together, with no files of its own):

```bitbake
PACKAGES =+ "${PN}-full"
FILES:${PN}-full = ""
ALLOW_EMPTY:${PN}-full = "1"
RDEPENDS:${PN}-full = "${PN} ${PN}-camera-plugin mosquitto"
```

Without `ALLOW_EMPTY`, BitBake treats an empty package as an error (usually correctly indicating a missed `FILES` pattern) — this is your explicit "no, I meant this to be empty, it's a pure metapackage" declaration.

## Key takeaways

- Package splitting happens via `PACKAGES` (ordered list) + `FILES:<pkg>` (glob patterns, claimed in list order) — most of this is default/inherited, you extend it rather than writing it from scratch typically.
- `PACKAGES =+ "..."` (prepend, correct spacing) is how you insert custom split packages _before_ the generic catch-all claims everything.
- `RDEPENDS` (hard) vs `RRECOMMENDS` (soft, best-effort) — use `RRECOMMENDS` for genuinely optional companion packages.
- `ipk` remains the pragmatic default for moderate-complexity embedded/IoT work; `rpm`'s heavier resolver earns its cost only with genuinely complex per-device package variance or rollback requirements.
- Package feeds (`package-management` + `PACKAGE_FEED_URIS`) enable per-package field updates — but many teams deliberately choose whole-image OTA instead specifically to avoid field-state drift across a device fleet; this is an architecture decision, not just a technical toggle.
- `oe-pkgdata-util` (list-pkgs, list-pkg-files, lookup-pkg) is your ground-truth inspection tool — use it instead of manually re-deriving `FILES` precedence in your head.

