[[Practical Systems Work]]

# Day 21: `devtool`/`recipetool` — The Full Iterative Development Loop

Day 12 introduced `devtool modify`/`devtool add`/`recipetool create` as individual commands. This day treats them as a complete, realistic daily workflow — the actual loop you'll live in once past initial bring-up, including workspace management, upgrading recipes to new upstream versions, and integrating `devtool`'s output back into CI-friendly layer structure.

## The `devtool` workspace — understanding what it actually is

Every `devtool modify`/`devtool add` operation registers into a **workspace layer** — `build-qemu/workspace/` — which `devtool` automatically adds to `bblayers.conf` with a very high priority, ensuring anything in the workspace takes precedence over the "real" version in your other layers. This is why `devtool build <recipe>` picks up your local edits without any manual layer priority fiddling.

```bash
bitbake-layers show-layers
```

Run this after your first `devtool modify` — you'll see `workspacelayer` appear with a high `BBFILE_PRIORITY`. Understanding this exists (rather than treating `devtool` as pure magic) matters because it explains why forgetting to `devtool reset` leaves your build in a state where the workspace version silently wins over your "real" layer's recipe — a genuine source of confusion if you don't realize the workspace is still active.

```bash
devtool status
```

Run this anytime you're unsure what's currently active in your workspace — lists every recipe currently being modified/added and where its workspace source lives. Good habit: run this at the start of any session where you're not 100% sure what state you left things in.

## `devtool upgrade` — the realistic version-bump workflow

A very common recurring task: upstream (`mosquitto`, or your own `mqtt-monitor-cpp`) cuts a new release, and you need to bump the recipe's `SRCREV`/`PV` while preserving any existing patches:

```bash
devtool upgrade mosquitto --srcrev <new-commit-hash>
```

or, for a tag-based/version-numbered upgrade:

```bash
devtool upgrade mosquitto -V 2.0.20
```

This fetches the new source version, applies your **existing** patches against it (flagging any that fail to apply cleanly — a real, common outcome when upstream has changed the code your patch touches), and stages everything in the workspace for you to resolve conflicts and test before finalizing:

```bash
cd build-qemu/workspace/sources/mosquitto
git status                        # see what devtool staged
git log --oneline                 # your preserved patch commits, now on top of new upstream
# resolve any patch-apply conflicts manually if flagged
devtool build mosquitto
devtool finish mosquitto ../../meta-mqtt-monitor
```

**This is the actual professional workflow for dependency version bumps** — doing this manually (re-generating patches by hand against new upstream source, re-verifying each still applies) is exactly the tedious, error-prone process `devtool upgrade` exists to eliminate. Worth explicitly noting: always `devtool build` and genuinely test before `devtool finish` — an upgrade that "applies cleanly" patch-wise can still behave differently at runtime if upstream changed behavior, not just code structure.

## `devtool deploy-target` — the fast hardware iteration loop

For actual hardware (not QEMU), re-flashing an entire image for every source change during active development is far too slow. `devtool deploy-target` pushes just the rebuilt package directly to a running target over SSH:

```bash
devtool modify mqtt-monitor
# edit source in workspace/sources/mqtt-monitor-cpp
devtool build mqtt-monitor
devtool deploy-target mqtt-monitor root@192.168.1.50
```

This copies the updated binary/files directly onto the running device's filesystem (via SSH/rsync under the hood) and, if it's a systemd service, you manually restart it (`ssh root@192.168.1.50 systemctl restart mqtt-monitor`) to pick up the change — no image rebuild, no reflash, genuinely fast iteration once you have real hardware on your bench. This is the actual daily-driver workflow for hardware bring-up and active feature development once you're past initial image bring-up (Day 7's QEMU walkthrough) and working against your real RPi/BeagleBone target.

```bash
devtool deploy-target -s mqtt-monitor root@192.168.1.50
```

The `-s` flag strips symbols before deploying — smaller/faster transfer for a device on a slow network link, at the cost of losing debug symbols on-target for that iteration (fine when you're validating a fix, not ideal if you specifically need on-target `gdb`).

```bash
devtool undeploy-target mqtt-monitor root@192.168.1.50    # revert target back to the original package version
```

## `devtool` for exploring/understanding an unfamiliar recipe

A genuinely useful non-editing use case — you've inherited a layer with recipes you didn't write, and need to actually understand what a recipe does structurally:

```bash
devtool modify some-unfamiliar-recipe
cd build-qemu/workspace/sources/some-unfamiliar-recipe
git log --oneline    # if patches were auto-imported as commits, this shows you exactly what each patch changes
```

Since `devtool modify` imports existing patches as individual git commits (reversing what `devtool update-recipe` does going the other direction), this gives you a genuinely readable history of "what has been changed from vanilla upstream and why" — often clearer than reading `.patch` files directly, especially for a recipe with several accumulated patches from different points in time.

## When `devtool add`'s inference gets your `mqtt_monitor` recipe wrong — realistic corrections

Following up on Day 12's caveat about imperfect `DEPENDS` inference — realistic corrections you'll actually make after `devtool add` on your own C++ project:

```bash
devtool edit-recipe mqtt-monitor
```

Opens the generated recipe in your `$EDITOR`. Common fixes needed:

- `DEPENDS` often needs manual additions for anything discovered via CMake's `find_package()` that isn't obvious from source scanning alone (e.g., `mosquitto`, `sqlite3`, `boost` — devtool's static analysis frequently misses these since they're resolved at CMake-configure-time, not visible from simple source inspection).
- `inherit systemd` + `SYSTEMD_SERVICE`/`SYSTEMD_AUTO_ENABLE` almost always needs adding manually — `devtool add` has no way to infer "this project also ships a systemd unit" unless it's extremely explicit in the repo structure.
- `LICENSE`/`LIC_FILES_CHKSUM` — `devtool add` makes a best-effort guess from a `LICENSE`/`COPYING` file if present, but **always verify** this rather than trusting it blindly; a wrong `LICENSE` string is a real compliance issue, not just a cosmetic recipe imperfection.

## Recipe upgrade checking across your whole layer — `layerindex`/`devtool check-upgrade-status`

For staying current across many dependencies over time (a real production maintenance concern, not just initial development):

```bash
devtool check-upgrade-status mosquitto sqlite3 mqtt-monitor
```

Reports whether newer upstream versions are available for each named recipe, without actually doing anything — a lightweight "what's out of date" audit you can run periodically (worth scripting into a periodic CI job) rather than discovering staleness only when a CVE announcement forces urgent attention.

## Practical workflow summary — a realistic week of `mqtt-monitor` development against real hardware

```bash
# Monday: new feature work
devtool modify mqtt-monitor
# ... edit source over several days, committing locally in workspace/sources/mqtt-monitor-cpp ...
devtool build mqtt-monitor
devtool deploy-target mqtt-monitor root@192.168.1.50
# ... iterate: edit, build, deploy-target, test on real hardware, repeat ...

# Friday: feature complete, finalize
devtool update-recipe mqtt-monitor    # if patches needed (unlikely for your own repo — see below)
devtool build mqtt-monitor             # final verification build
devtool finish mqtt-monitor ../../meta-mqtt-monitor
git -C ../../meta-mqtt-monitor add -A
git -C ../../meta-mqtt-monitor commit -m "Bump mqtt-monitor to include new telemetry batching feature"
```

Worth noting explicitly: for **your own** repository (as opposed to patching third-party upstream code), the realistic pattern is usually that your actual feature commits land directly in `mqtt-monitor-cpp`'s own git history (pushed normally, outside Yocto entirely) — and the Yocto recipe simply bumps `SRCREV` to point at your new commit, rather than generating `.patch` files at all. `devtool update-recipe` and patch generation matter most for modifying _third-party_ code you don't control the upstream of (mosquitto, sqlite3) — for your own project, bumping `SRCREV` after a normal push is the whole story.

## Key takeaways

- The workspace layer (`build-qemu/workspace/`) is a real, high-priority layer `devtool` manages automatically — `devtool status` shows what's active; forgetting `devtool reset` leaves stale workspace overrides silently winning over your real layer.
- `devtool upgrade` is the correct professional workflow for version bumps on third-party dependencies — preserves and reapplies your existing patches, flags conflicts explicitly rather than silently.
- `devtool deploy-target`/`undeploy-target` is the fast hardware iteration loop once you have real RPi/BeagleBone hardware on the bench — skip full image reflashing for routine source changes.
- `devtool modify` on an unfamiliar existing recipe is a legitimate exploration technique — imported patches become readable git commits, often clearer than raw `.patch` file review.
- `devtool add`'s inference reliably misses `find_package()`-resolved `DEPENDS`, systemd integration, and sometimes gets `LICENSE` wrong — always manually review via `devtool edit-recipe` before `finish`.
- For your **own** repositories, `SRCREV` bumps (no patches at all) are the realistic ongoing pattern — `devtool update-recipe`'s patch-generation matters primarily for third-party code you're modifying without upstream commit access.

