[[Foundations]]
## Host system requirements (get this right or waste hours debugging phantom errors)

Yocto builds are picky about the host. Real requirements, not the sanitized docs version:

- **Ubuntu 22.04/24.04 LTS** is the path of least resistance (matches your existing tooling). Yocto tracks specific "supported" distros per release — always check the release notes for the exact version you're using (Kirkstone, Scarthgap, etc.), since package names for host dependencies shift.
- **Disk space: budget 100–150GB per build directory**, not per project. A `core-image-minimal` build alone can hit 20-30GB with sstate cache; adding Qt, multiple machines, or multiple images balloons fast. This is the #1 thing people underprovision.
- **RAM**: 16GB minimum for comfortable parallel builds, 32GB+ if you want `BB_NUMBER_THREADS` to not choke your machine.
- Don't build on a filesystem mounted with restrictive options (no `noexec`, careful with network drives — NFS in particular causes case-sensitivity and locking issues).

## Installing host dependencies (Ubuntu)

```bash
sudo apt update
sudo apt install -y gawk wget git diffstat unzip texinfo gcc build-essential \
  chrpath socat cpio python3 python3-pip python3-pexpect xz-utils debianutils \
  iputils-ping python3-git python3-jinja2 python3-subunit zstd liblz4-tool \
  file locales libacl1
sudo locale-gen en_US.UTF-8
```

That `locale-gen` step is not optional cruft — BitBake will throw obscure encoding errors during parsing if your locale isn't set to UTF-8. This bites people constantly on minimal server installs / containers.

## Getting Poky

```bash
git clone -b scarthgap git://git.yoctoproject.org/poky
cd poky
```

I'm anchoring this curriculum on **Scarthgap (5.0 LTS)**, current as of my knowledge — it's the actively maintained LTS branch. Branch names matter enormously in Yocto: recipes, syntax (`:append` vs `_append`), and available classes differ meaningfully release to release. Always match your layers' branch to your poky branch — mismatched branches is the single most common cause of "recipe not found" or syntax errors for newcomers.

## Initializing the build environment

This is the step that confuses people coming from normal build tools, because it's not a build command — it's an environment setup script that also _creates_ your build directory:

```bash
source oe-init-build-env [build-dir-name]
```

Two critical things happen here:

1. It **sources into your current shell** (not executes as subprocess) — this is why it's `source` and not `./oe-init-build-env`. It exports environment variables and adds BitBake to your `PATH`.
2. It creates (or, if it exists, just enters) a build directory — defaults to `build/`, but you should name it explicitly per project/config, e.g. `build-rpi4`, `build-qemu`.

Every new terminal session, you must re-source this before running `bitbake`. This trips people up constantly — "bitbake: command not found" after opening a new terminal almost always means you forgot to re-source.

```bash
# Every new shell:
cd poky
source oe-init-build-env build-qemu
```

## Build directory anatomy

Once sourced, you're `cd`'d into `build-qemu/`. Here's what's actually in there and why it matters:

```
build-qemu/
├── conf/
│   ├── local.conf          # Machine-specific + build-specific settings (THE file you edit most)
│   ├── bblayers.conf        # Which layers are active in this build
│   └── templateconf.cfg     # Points back to the template these were generated from
├── cache/                   # BitBake's parsed metadata cache
├── downloads/                # Fetched upstream source tarballs — SHARE this across builds
├── sstate-cache/             # Shared state cache — SHARE this across builds
└── tmp/
    ├── deploy/
    │   ├── images/<machine>/  # Final built images, kernels, wic files — end product
    │   ├── ipk|rpm|deb/       # Package output
    │   └── licenses/
    ├── work/                  # Per-recipe build directories — THIS is where you debug
    │   └── <arch>/<recipe-name>/<version>/
    │       ├── temp/           # log.do_compile, log.do_configure, etc. — your debugging goldmine
    │       ├── build/          # actual compile-time working directory (out-of-tree builds)
    │       └── image/          # this recipe's do_install output
    ├── sysroots-components/    # Staged headers/libs from dependencies, per-recipe
    └── work-shared/
```

**Practical habit to build immediately:** `downloads/` and `sstate-cache/` should live _outside_ any single build directory and be shared across all your builds (multiple machines, multiple projects). Set this in `local.conf`:

```
DL_DIR ?= "/home/george/yocto-shared/downloads"
SSTATE_DIR ?= "/home/george/yocto-shared/sstate-cache"
```

Without this, every new build directory re-downloads and re-compiles everything from scratch — the difference between a 20-minute incremental build and a 4-hour full rebuild.

**Where you'll actually spend debugging time:** `tmp/work/<arch>/<recipe>/<version>/temp/log.do_<task>`. When a build fails, BitBake tells you the exact log path in its error output — go there first, always, before guessing.

## Your first real build

```bash
# Inside build-qemu, with oe-init-build-env sourced:
bitbake core-image-minimal
```

First build: expect 1–4 hours depending on hardware/bandwidth (it's compiling a toolchain, kernel, and full minimal userspace from source). This is normal — it's not going to be like this again once sstate is populated.

While it runs, watch the console output shape — it shows parsing progress, then task execution with a live counter (`Tasks Summary: Attempted X tasks of Y`). If you see it jump into `WARNING:` lines about "no provider" or "layer not found," stop — that's a configuration problem, not something to let run and hope resolves.

Once done:

```bash
ls tmp/deploy/images/qemux86-64/
```

You'll see `core-image-minimal-qemux86-64.rootfs.ext4`, a kernel image, and a few symlinks with "current" build tags. Boot it:

```bash
runqemu qemux86-64
```

This launches an actual QEMU instance booting your freshly built image. You'll get a login prompt — default is `root`, no password, for `core-image-minimal`.

## Key `local.conf` settings worth understanding day 1 (not exhaustive — Day 5 goes deep)

```
MACHINE ?= "qemux86-64"          # what hardware/target you're building for
DL_DIR ?= "..."                   # shared download cache (see above)
SSTATE_DIR ?= "..."                # shared sstate cache (see above)
BB_NUMBER_THREADS = "8"            # parallel recipe tasks (usually = CPU cores)
PARALLEL_MAKE = "-j 8"             # parallel make jobs within a single recipe compile
```

`BB_NUMBER_THREADS` and `PARALLEL_MAKE` are separate knobs controlling different parallelism layers — the first is "how many recipes build simultaneously," the second is "how many compile jobs within one recipe's make invocation." Both matter for build speed; conflating them is a common misunderstanding.

## Key takeaways

- `source oe-init-build-env <dir>` — must be **sourced**, not executed, and re-run every new shell.
- The build directory is disposable/regenerable in principle, but `downloads/` and `sstate-cache/` are expensive to lose — always externalize and share them.
- `tmp/deploy/images/<machine>/` is your final output. `tmp/work/<arch>/<recipe>/<version>/temp/*.log` is your debugging output — go there first on any failure.
- `local.conf` is where machine selection, thread tuning, and cache paths live — you'll be editing this constantly early on.
- First build is slow (cold cache); every build after is fast if sstate/downloads are preserved.