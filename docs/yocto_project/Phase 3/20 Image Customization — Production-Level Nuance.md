[[Practical Systems Work]]
# Day 20: Image Customization — Production-Level Nuance

Day 6 covered image recipe basics. This day goes deeper into the details that actually matter for a real shipped product image: precise control over what's excluded (not just included), rootfs size optimization, read-only considerations (preview of Day 26), and the diagnostic workflow for "why is this file/package in my image when I didn't ask for it."

## `IMAGE_INSTALL` completeness — what's actually in a "minimal" image by default

A common surprise: even `core-image-minimal` isn't as minimal as people expect on first look, because `core-image.bbclass` and `DISTRO_FEATURES` pull in defaults you didn't explicitly request. Before adding anything, inspect what's already there:

```bash
bitbake -e mqtt-monitor-image | grep ^IMAGE_INSTALL=
```

This shows the fully resolved list — often includes things like `packagegroup-core-boot`, `${VIRTUAL-RUNTIME_base-utils}`, `${VIRTUAL-RUNTIME_login_manager}` — these are **variable-indirected package groups**, not literal package names, meaning the actual installed content shifts based on `DISTRO_FEATURES`/`VIRTUAL-RUNTIME_*` settings (Day 5) rather than being a fixed list you can read directly off this one line. Trace further:

```bash
bitbake -e mqtt-monitor-image | grep ^VIRTUAL-RUNTIME_base-utils=
bitbake -e packagegroup-core-boot | grep ^RDEPENDS
```

## Excluding packages explicitly — `IMAGE_INSTALL:remove` and `PACKAGE_EXCLUDE`

Two distinct mechanisms, often confused:

```bitbake
IMAGE_INSTALL:remove = "packagegroup-core-full-cmdline"
```

`IMAGE_INSTALL:remove` — removes a package from _this specific image's_ install list. Local, image-recipe-scoped, the correct tool when you know exactly which image needs the exclusion.

```bitbake
PACKAGE_EXCLUDE = "perl python3-dev"
```

`PACKAGE_EXCLUDE` — a global, build-wide policy: this package must never appear in _any_ image built in this configuration, even if something's `RDEPENDS` would otherwise pull it in. This is a much bigger hammer — appropriate for genuinely hard project-wide policy ("we will never ship a Perl interpreter on any device, full stop") rather than per-image tuning. Using `PACKAGE_EXCLUDE` where you actually meant `IMAGE_INSTALL:remove` is a common way to break an unrelated image elsewhere in a multi-image build that legitimately needed that package.

## Size auditing — finding out what's actually taking up space

Before optimizing, measure:

```bash
bitbake mqtt-monitor-image
cat tmp/deploy/images/<machine>/mqtt-monitor-image-<machine>.rootfs.manifest
```

The `.manifest` file lists every package actually installed with its version — your ground-truth "what's really in this image" reference, more reliable than trying to reconstruct it from `IMAGE_INSTALL`'s resolved variable expansion mentally.

For actual disk usage breakdown:

```bash
bitbake -e mqtt-monitor-image | grep ^IMAGE_ROOTFS_SIZE=
oe-pkgdata-util read-value PKGSIZE <package-name>    # per-package installed size, Day 14's tool again
```

A genuinely useful practice for production: generate this manifest for every release build and diff it against the previous release's manifest — catches accidental package additions/version bumps that crept in without an explicit, reviewed decision (a dependency chain pulled in something new because an upstream recipe's `RDEPENDS` changed, for instance).

## `IMAGE_FEATURES` combinations worth understanding for a real product decision

```bitbake
# development image
EXTRA_IMAGE_FEATURES = "debug-tweaks tools-debug tools-profile ssh-server-openssh package-management"

# production image
IMAGE_FEATURES:remove = "debug-tweaks"
EXTRA_IMAGE_FEATURES = "ssh-server-openssh read-only-rootfs"
```

`tools-profile` (perf, strace-adjacent profiling tools) is worth knowing exists specifically for the "why is my embedded device's CPU usage higher than expected" class of problem — pull it in temporarily on a dev image variant, profile, remove for the shipped variant. Don't ship profiling tooling permanently; it's attack surface and wasted flash space with zero production value.

`read-only-rootfs` (fuller treatment Day 26) fundamentally changes how your application needs to handle writable state (logs, SQLite databases from your `mqtt_monitor` capstone specifically need a writable location even on an otherwise-read-only root) — flagging now because it's a decision that ripples back into your application's own filesystem assumptions, not just an image-build toggle.

## `IMAGE_LINGUAS` and locale bloat — a specific, common size trap

```bitbake
IMAGE_LINGUAS = "en-us"
GLIBC_GENERATE_LOCALES = "en_US.UTF-8"
```

Default glibc locale generation is genuinely large (every locale, every character set) — for an embedded IoT device with no user-facing locale-dependent UI, this is pure waste, frequently tens of megabytes for something never actually used. Restricting to exactly the locale(s) your device actually needs (often just `en_US.UTF-8` or whichever you actually use for logging/timestamps) is one of the higher-leverage, lowest-effort size optimizations available — check this before reaching for more invasive size-reduction work.

## `rm_work` — reclaiming build-machine disk space (not image size, build host size)

```bitbake
INHERIT += "rm_work"
RM_WORK_EXCLUDE += "mqtt-monitor linux-yocto"
```

Distinct concern from image size: `tmp/work/` (Day 2's anatomy) accumulates enormous amounts of intermediate build artifacts over time, especially across many recipes. `rm_work` automatically cleans up each recipe's `WORKDIR` after that recipe finishes building successfully (keeping only what's needed for sstate reuse, not the full working tree) — genuinely important for CI build machines that would otherwise slowly fill disk over many build cycles. `RM_WORK_EXCLUDE` lets you keep specific recipes' full work directories intact — useful for recipes you're actively developing/debugging (your own `mqtt-monitor`, or a kernel you're actively patching) where you want `devshell`/log access preserved, while everything stable gets cleaned automatically.

## Rootfs post-processing revisited — a realistic production example

Tying Day 6's `ROOTFS_POSTPROCESS_COMMAND` mechanism to an actual production concern — ensuring your SQLite database directory exists with correct permissions before first boot, independent of any single package's `do_install`:

```bitbake
mqtt_monitor_prep_datadir() {
    mkdir -p ${IMAGE_ROOTFS}/var/lib/mqtt-monitor
    chown 1000:1000 ${IMAGE_ROOTFS}/var/lib/mqtt-monitor
    chmod 0750 ${IMAGE_ROOTFS}/var/lib/mqtt-monitor
}
ROOTFS_POSTPROCESS_COMMAND += "mqtt_monitor_prep_datadir; "
```

(Though per Day 19, `StateDirectory=` in the systemd unit is actually the _cleaner_ mechanism for this specific case — this example is shown to illustrate `ROOTFS_POSTPROCESS_COMMAND`'s general applicability for anything that genuinely isn't a single package's concern, e.g., cross-package filesystem layout decisions, not to suggest it's preferred over `StateDirectory=` for this exact scenario.)

## Diagnosing "why is this package in my image" systematically

Realistic scenario: your production image contains `python3-dev` (headers/build tools) which you never explicitly requested and definitely don't want shipped (attack surface, wasted space). Diagnostic sequence:

```bash
grep python3-dev tmp/deploy/images/<machine>/*.manifest    # confirm it's really there
oe-pkgdata-util lookup-pkg python3-dev                      # Day 14's tool — what's it associated with
```

Then trace the dependency chain — often the cleanest way is temporarily building with verbose dependency logging or using `bitbake -g` (Day 8) against the image target and grep-ing the generated `pn-depends.dot` for the offending package, tracing which of your explicitly-requested packages actually pulled it in via `RDEPENDS`/`RRECOMMENDS`. Once identified, the fix is usually either `PACKAGE_EXCLUDE` (if genuinely never wanted anywhere) or investigating whether the pulling package has a `PACKAGECONFIG` (Day 11) toggle that would avoid needing the dev package in the first place (e.g., a `PACKAGECONFIG` that's building something against headers unnecessarily for a runtime-only deployment).

## Key takeaways

- `IMAGE_INSTALL:remove` (per-image) vs `PACKAGE_EXCLUDE` (global, build-wide policy) — using the global hammer for a local problem can break unrelated images sharing the same build configuration.
- The `.manifest` file in `tmp/deploy/images/` is ground truth for "what's actually in this image" — diff it release-to-release to catch unreviewed package drift.
- `IMAGE_LINGUAS`/`GLIBC_GENERATE_LOCALES` restriction is a high-leverage, low-effort size win most people miss on first pass — check locale generation before more invasive size work.
- `rm_work` addresses build-host disk consumption (not image size) — essential for CI longevity, with `RM_WORK_EXCLUDE` preserving debug access for recipes you're actively working on.
- `read-only-rootfs` is a decision that changes your _application's_ filesystem assumptions (where can it write logs/databases), not just an image toggle — flag it early rather than discovering the conflict at deployment time.
- Diagnosing unwanted packages: `.manifest` confirms presence, `oe-pkgdata-util lookup-pkg` + `bitbake -g`'s dependency graph traces _why_ — don't guess at the dependency chain manually.

