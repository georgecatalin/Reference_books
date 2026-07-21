[[Core Execution Mechanics]]

# Day 10: Writing Recipes From Scratch — A Complete C Program Recipe

Days 4 and 7 showed recipe pieces. This day is the complete, correct process for taking an arbitrary piece of C source (no existing build system — the case that gives people the most trouble, since there's no `inherit autotools`/`inherit cmake` to lean on) and turning it into a properly packaged recipe, done right rather than minimally.

## The scenario

You have a small C utility — a serial line reader that reads frames and writes them to stdout, no Makefile, just source files, meant to be compiled directly. This is deliberately the "hardest case" (no build system to inherit from) so you understand the primitives before relying on classes to do it for you again in Day 11.

## Project layout (what you're packaging)

```
serial-frame-reader/
├── src/
│   ├── main.c
│   ├── frame.c
│   └── frame.h
├── LICENSE
└── README.md
```

## Step 1: Decide the fetch strategy

Two realistic options for source you control:

**Option A — git repo (preferred for anything under active development):**

```bitbake
SRC_URI = "git://github.com/georgeco/serial-frame-reader.git;protocol=https;branch=main"
SRCREV = "3f9a8b2c1d0e..."
S = "${WORKDIR}/git"
```

**Option B — local files bundled in the layer (fine for something small/static, or during early development before you've pushed to a repo):**

```bitbake
SRC_URI = "file://main.c \
           file://frame.c \
           file://frame.h \
           file://LICENSE"
S = "${WORKDIR}"
```

For Option B, those files live in `recipes-mqtt/serial-frame-reader/files/` in your layer. **Practical guidance**: use Option A once code is in a real repo (which yours already is, given your existing `mqtt_monitor` capstones) — it gives you `SRCREV` pinning for reproducibility and avoids duplicating source between your actual dev repo and the Yocto layer, which otherwise drifts out of sync silently.

## Step 2: License handling, done correctly (not the shortcut from Day 7)

Day 7 used the shortcut of pointing at OE-Core's bundled MIT license text. For your own project's `LICENSE` file (which may have your own copyright header, not a bare stock MIT text), you compute the checksum against _your actual file_:

```bash
md5sum LICENSE
```

```bitbake
LICENSE = "MIT"
LIC_FILES_CHKSUM = "file://LICENSE;md5=<actual-hash-of-your-file>"
```

If your `LICENSE` file's content changes even by one character (updated copyright year, reworded line), this checksum breaks the build until you update it — this is intentional, not a bug to work around. It's Yocto forcing an explicit acknowledgment any time license terms change, which matters if you ever do compliance audits on shipped firmware.

## Step 3: Write `do_compile` for a no-build-system project

```bitbake
do_compile() {
    ${CC} ${CFLAGS} ${LDFLAGS} -o serial-frame-reader \
        ${S}/main.c ${S}/frame.c -I${S}
}
```

Why `${CC}`/`${CFLAGS}`/`${LDFLAGS}` and never `gcc`/hardcoded flags directly: these variables are set by BitBake's cross-compilation machinery to the correct **target** toolchain triplet and target-appropriate flags (optimization level, target CPU tuning from `MACHINE`, hardening flags from `DISTRO_FEATURES` like `security-flags`). Hardcode `gcc` and you'll compile a host-architecture binary that silently fails or crashes on the actual target device — this is one of the most common "why doesn't my binary run on the Pi" bugs for people new to cross-compilation, and it's completely avoided by always using the variables.

Verify what these actually resolve to for your current machine:

```bash
bitbake -e serial-frame-reader | grep ^CC=
bitbake -e serial-frame-reader | grep ^CFLAGS=
```

You'll see something like `CC="arm-poky-linux-gnueabi-gcc ..."` for a 32-bit ARM target — a full cross-compiler invocation, not plain `gcc`.

## Step 4: Write `do_install` correctly

```bitbake
do_install() {
    install -d ${D}${bindir}
    install -m 0755 serial-frame-reader ${D}${bindir}/serial-frame-reader
}
```

`install -d` creates the directory (idempotent, safe if it already exists); `install -m 0755` copies with explicit permission bits set in one command (better than `cp` + separate `chmod`, and it's the idiomatic pattern used throughout every OE-Core recipe — matching convention matters for anyone else reading your layer later).

## Step 5: Full assembled recipe

```bitbake
SUMMARY = "Serial frame reader utility"
DESCRIPTION = "Reads and parses telemetry frames from a serial device"
HOMEPAGE = "https://github.com/georgeco/serial-frame-reader"
LICENSE = "MIT"
LIC_FILES_CHKSUM = "file://LICENSE;md5=7a1e04b2f9c3d8e0a1b5c6d7e8f9a0b1"

SRC_URI = "git://github.com/georgeco/serial-frame-reader.git;protocol=https;branch=main"
SRCREV = "3f9a8b2c1d0e4f5a6b7c8d9e0f1a2b3c4d5e6f7a"

S = "${WORKDIR}/git"

do_compile() {
    ${CC} ${CFLAGS} ${LDFLAGS} -o serial-frame-reader \
        ${S}/src/main.c ${S}/src/frame.c -I${S}/src
}

do_install() {
    install -d ${D}${bindir}
    install -m 0755 serial-frame-reader ${D}${bindir}/serial-frame-reader
}
```

Notice: no `inherit` line at all. Without inheriting `autotools`/`cmake`/etc., BitBake's `base.bbclass` (implicitly inherited by every recipe) still provides `do_fetch`/`do_unpack`/`do_patch`/`do_package`/`do_populate_sysroot` — you're only responsible for writing `do_configure` (skipped here, nothing to configure), `do_compile`, and `do_install` yourself.

## Step 6: Building and validating the package output, not just the compile

```bash
bitbake serial-frame-reader
```

Check what packages actually got produced — this matters because even a "simple" recipe generates multiple output packages by default:

```bash
oe-pkgdata-util list-pkgs | grep serial-frame-reader
```

Typically you'll see `serial-frame-reader`, `serial-frame-reader-dbg`, `serial-frame-reader-doc` (may be empty), `serial-frame-reader-src`. This automatic splitting (Day 14 goes deep on this) happens even for a two-file recipe — `-dbg` gets the unstripped debug symbols, main package gets the stripped binary. Confirm the main package actually contains your binary:

```bash
oe-pkgdata-util list-pkg-files serial-frame-reader
```

## Step 7: Debugging when `do_compile` fails — the actual workflow

This is the realistic failure mode you'll hit constantly with manually-written `do_compile` (no build system to give you sane error messages):

```bash
bitbake serial-frame-reader -c devshell
```

Inside the devshell:

```bash
echo $CC
echo $CFLAGS
cd ${S}/src   # actual path will be printed/known from your recipe
$CC $CFLAGS -o /tmp/test-compile main.c frame.c -I.
```

Running the exact compile line manually, inside the exact cross-compilation environment, surfaces the real compiler error immediately — missing header (check `DEPENDS`), undefined reference (check you're compiling all needed `.c` files), or a target-specific issue (wrong endianness assumption, etc.) that's much harder to diagnose from BitBake's wrapped log output alone.

## A common real mistake worth flagging explicitly: forgetting `-I` paths

If `frame.h` isn't found during compilation of `main.c`, and both live in the same `src/` directory, this isn't a Yocto-specific issue — it's the same `-I` include path rule as any C project. But it's worth stating because **Yocto's error output for this looks scarier than it is** — a wall of cross-compiler diagnostic noise around a completely mundane missing include path. Read the actual compiler error line inside the log (or better, the devshell), not just the fact that `do_compile` failed.

## When to graduate from manual `do_compile`/`do_install` to a Makefile

If this were a real multi-file project (which your actual `mqtt_monitor` C++ capstone is), writing every `.c`/`.cpp` file explicitly into `do_compile` doesn't scale. The realistic path: write a proper `Makefile` (or CMakeLists.txt) in the actual project repo, then use `inherit pkgconfig` plus a normal `Makefile`-aware pattern (`oe_runmake`) or graduate to `inherit cmake`/`autotools` entirely — which Day 11 covers in depth, since your real capstones almost certainly already use CMake.

```bitbake
# if the project has its own Makefile:
do_compile() {
    oe_runmake
}
```

`oe_runmake` is a wrapper around `make` that automatically passes the correct cross-compilation environment variables (`CC`, `CFLAGS`, `LDFLAGS`, `AR`, etc.) as make variables — the bridge case between "fully manual `${CC}` invocation" (this day) and "fully framework-managed" (`inherit cmake`, next day).

## Key takeaways

- With no `inherit` for a build framework, `base.bbclass` still gives you `do_fetch`/`do_unpack`/`do_patch`/`do_package`/`do_populate_sysroot` for free — you only write `do_compile`/`do_install` (and `do_configure` if needed).
- Always use `${CC}`/`${CFLAGS}`/`${LDFLAGS}` — never hardcode `gcc` or flags — this is the difference between a binary that runs on-target and one that doesn't (or worse, one built for the wrong architecture that fails confusingly).
- `LIC_FILES_CHKSUM` must match your actual file's real hash — recompute it whenever license text changes; this is a deliberate compliance gate, not friction to bypass.
- `install -d` + `install -m <mode>` is the idiomatic install pattern — matches every OE-Core recipe's convention.
- `devshell` is where you manually reproduce a failing compile command outside BitBake's log wrapping — the fastest path to the real error.
- Even a two-file manual recipe produces multiple split packages (`-dbg`, `-doc`, `-src`) automatically — `oe-pkgdata-util` is how you inspect what actually got generated.
- `oe_runmake` bridges manual compilation and full framework classes — reach for it when the project has its own Makefile but you don't want to inherit a heavier class.

