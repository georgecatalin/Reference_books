[[Core Execution Mechanics]]
# Day 13: `SRC_URI` Mechanics — Fetchers, Checksums, Mirrors, and Offline Builds

`SRC_URI` has appeared constantly since Day 4, always in passing. This day covers it as its own subsystem — the fetcher backends, integrity verification, mirror configuration, and how to make builds reproducible and functional without live internet access (genuinely important for CI and for any air-gapped production build environment).

## Fetcher backends — what the URL scheme actually selects

BitBake's fetcher is dispatched by the URL scheme prefix. Each has its own parameter syntax:

```bitbake
# git
SRC_URI = "git://github.com/georgeco/mqtt-monitor-cpp.git;protocol=https;branch=main"
SRCREV = "abc123..."

# plain HTTP/HTTPS tarball
SRC_URI = "https://example.org/releases/libfoo-1.4.2.tar.gz"
SRC_URI[sha256sum] = "..."

# local file (relative to layer's files/ dirs via FILESEXTRAPATHS)
SRC_URI = "file://config.patch"

# subversion, less common now but still exists
SRC_URI = "svn://example.org/repo;module=trunk;protocol=https"

# multiple entries — combined and applied in order
SRC_URI = "git://github.com/georgeco/mqtt-monitor-cpp.git;protocol=https;branch=main \
           file://0001-fix.patch \
           file://mosquitto.conf"
```

**`protocol=https` on `git://` URLs is easy to misread.** The `git://` prefix is the _fetcher selection_ (tells BitBake "use the git fetcher"), while `protocol=https` is a parameter telling that fetcher to actually use HTTPS transport underneath, rather than the literal unencrypted `git://` protocol. This distinction confuses people the first time — you're not contradicting yourself by writing `git://...;protocol=https`, you're correctly selecting the git fetcher with HTTPS transport. Always use `protocol=https` explicitly for any git remote reachable over HTTPS — the bare `git://` protocol (port 9418, unauthenticated, often firewalled) should be avoided for anything you actually depend on.

## Checksums — what's mandatory and why

For **non-version-controlled fetches** (plain URL tarballs, zips), BitBake requires an explicit checksum or refuses to build:

```bitbake
SRC_URI[md5sum] = "..."      # legacy, still works, weaker
SRC_URI[sha256sum] = "..."   # preferred — always use this for new recipes
```

If you omit both, BitBake's first fetch attempt actually **fails with an error message giving you the correct checksum to add** — a genuinely helpful failure mode, since it means you never have to compute the hash yourself; run the fetch once, copy the value BitBake reports, done. Still — always cross-check that reported value against the upstream project's own published checksums/release page when you can, rather than blindly trusting whatever the first fetch attempt reports, since that protects against fetching from a compromised mirror on your very first attempt.

For **git fetches**, `SRCREV` (an exact commit hash) _is_ your integrity/reproducibility guarantee — there's no separate checksum needed because the commit hash itself is cryptographically tied to the tree content. This is why `SRCREV = "${AUTOREV}"` (floating to HEAD) genuinely breaks reproducibility in a way that plain tarball fetches without a checksum don't even allow you to attempt — always pin an exact `SRCREV` for anything beyond active local development.

## Multiple checksums and `SRC_URI` with named entries

For recipes with multiple non-git downloads, you name each entry to disambiguate which checksum belongs to which URL:

```bitbake
SRC_URI = "https://example.org/libfoo-1.4.2.tar.gz;name=libfoo \
           https://example.org/libfoo-docs-1.4.2.tar.gz;name=libfoo-docs"

SRC_URI[libfoo.sha256sum] = "..."
SRC_URI[libfoo-docs.sha256sum] = "..."
```

## `DL_DIR` — where fetched content actually lives, and why sharing it matters beyond convenience

Introduced in Day 2 as a "share across builds" tip; the deeper reason: `DL_DIR` stores not just the raw fetched tarball/git-clone, but also a `.done` stamp file and (for git) a bare mirror clone. If you have `DL_DIR` populated and later lose network access entirely, builds referencing already-fetched `SRCREV`s/checksums succeed with **zero** network activity — BitBake checks `DL_DIR` before attempting any network fetch. This is the actual mechanism behind fully offline/air-gapped Yocto builds, which matters for CI systems that intentionally run without internet access for reproducibility/security reasons (Day 28 territory).

```bash
BB_NO_NETWORK = "1"
```

Setting this in `local.conf` **forces** BitBake to fail loudly if anything would require network access, rather than silently attempting a fetch — the correct way to verify your `DL_DIR` is actually complete for an air-gapped deployment, rather than assuming it is and finding out at a worse time.

## Premirrors and mirrors — controlling _where_ fetches come from

```bitbake
SOURCE_MIRROR_URL ?= "https://mirror.internal.example.com/sources/"
INHERIT += "own-mirrors"

PREMIRRORS:prepend = "\
    git://.*/.* http://mirror.internal.example.com/git2_archive/ \n \
    https://.*/.* http://mirror.internal.example.com/sources/ \n \
    "
```

- **`PREMIRRORS`**: checked _before_ the URL in `SRC_URI` — useful for an internal cache/mirror that should always be tried first (faster, and works even if upstream is later deleted or rate-limits you).
- **`MIRRORS`**: checked _after_ the original `SRC_URI` URL fails — a fallback, not a first choice.

For a production/CI setup, standing up an internal source mirror (many teams just use a simple HTTP server serving a directory populated by their own `DL_DIR` over time) is a real practice worth adopting once you're not the only person building this — it protects your whole team against upstream repos disappearing, rate-limiting, or being temporarily down, and it makes CI builds dramatically faster since your mirror is on local network rather than the public internet.

## `BB_GENERATE_MIRROR_TARBALLS` and building your own mirror

```bitbake
BB_GENERATE_MIRROR_TARBALLS = "1"
```

For git-fetched recipes, this makes BitBake also generate a plain tarball snapshot of the fetched git content in `DL_DIR`, in addition to the bare git mirror clone — useful groundwork if you intend to later serve your own `DL_DIR` contents as a `PREMIRRORS` source for other build machines/CI runners, since tarballs are simpler to serve/sync than bare git repos.

## Practical reproducibility checklist (what "properly reproducible" actually requires)

Given everything above, a build is only genuinely reproducible if:

1. Every git `SRC_URI` has an explicit `SRCREV` (never `${AUTOREV}`)
2. Every tarball/zip `SRC_URI` has `SRC_URI[sha256sum]` set
3. `DISTRO`/`MACHINE`/layer versions (i.e., your layer git repos' own commits) are pinned/tagged, not floating on a branch tip
4. `local.conf`/`bblayers.conf` themselves are version-controlled (often via `kas`, Day 28 — plain files are easy to lose track of otherwise)

Point 3 is easy to overlook: even with perfect `SRCREV`/checksum discipline in your own recipes, if `meta-openembedded` or `meta-raspberrypi` are checked out on a floating branch rather than a pinned commit/tag, your build isn't actually reproducible build-to-build — a `git pull` on those layers between builds silently changes what "the same build command" produces. Real production setups pin layer repo commits explicitly (again, `kas` — Day 28 — is the standard tool for managing this declaratively rather than by discipline alone).

## Debugging fetch failures — the actual triage sequence

```bash
bitbake mqtt-monitor -c fetch -f     # force re-run just the fetch task, verbose
```

Common failure categories and what they actually mean:

- **Checksum mismatch**: either upstream silently changed the tarball at that URL (happens more than people expect — some projects re-tag releases), or you have a stale/wrong checksum in the recipe. Don't reflexively "fix" this by copying whatever new hash appears — investigate why it changed first.
- **`SRCREV` not found**: the commit doesn't exist on the remote — check for a typo, or whether history was rewritten/force-pushed upstream (rare but happens, another argument for mirroring anything you depend on long-term).
- **Network/DNS failure inside fetch**: check `BB_NO_NETWORK`, proxy environment variables (`http_proxy`/`https_proxy` need to be set in the shell _before_ sourcing `oe-init-build-env` in corporate/restricted network environments — a common setup gap).

## Key takeaways

- URL scheme selects the fetcher (`git://`, `https://`, `file://`); parameters like `protocol=https` configure that fetcher's transport, not a separate scheme selection — don't confuse the two.
- Tarball/zip fetches require `SRC_URI[sha256sum]` (or BitBake refuses to build); git fetches use `SRCREV` as their integrity mechanism instead — never use `${AUTOREV}` outside active local development.
- `DL_DIR` is what makes offline/air-gapped builds possible — `BB_NO_NETWORK = "1"` is how you verify completeness rather than assume it.
- `PREMIRRORS` (tried first) vs `MIRRORS` (fallback after `SRC_URI` fails) — standing up an internal mirror is a real production practice, not overkill, once more than one person/machine depends on the same upstream sources.
- Real reproducibility requires pinning your _own_ recipes' `SRCREV`/checksums **and** your layer repositories' own commits — floating layer branches silently break reproducibility even with perfect recipe-level discipline.
