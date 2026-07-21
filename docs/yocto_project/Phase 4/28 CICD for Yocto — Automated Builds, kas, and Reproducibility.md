[[Advanced Production]]
# Day 28: CI/CD for Yocto — Automated Builds, `kas`, and Reproducibility

Day 13 flagged that reproducibility requires pinning layer repository commits, not just recipe-level `SRCREV`/checksums, and deferred the tooling for managing that declaratively to this day. This is where the full picture comes together: `kas` as the standard tool for declarative, reproducible build configuration, plus the actual CI pipeline shape for a Yocto project.

## The problem `kas` solves

By this point in the curriculum, a real build for your product involves: a poky checkout at a specific branch, `meta-openembedded` at a matching branch, `meta-raspberrypi` (or your custom BSP layer), your own `meta-mqtt-monitor` layer, a specific `local.conf`, a specific `bblayers.conf` — and reproducing this exact setup on a fresh CI runner or a new teammate's machine currently means a README with a long list of manual `git clone`/`bitbake-layers add-layer` steps that inevitably drifts out of date or gets a step skipped. `kas` replaces that README with a single, version-controlled, machine-readable YAML file that _is_ the actual setup procedure — executable, not just descriptive.

## A real `kas` config file for your project

```yaml
# kas-mqtt-monitor.yml
header:
  version: 14

machine: mqtt-monitor-custom-revb
distro: mqtt-monitor-distro

repos:
  poky:
    url: "https://git.yoctoproject.org/poky"
    branch: scarthgap
    path: layers/poky

  meta-openembedded:
    url: "https://git.openembedded.org/meta-openembedded"
    branch: scarthgap
    path: layers/meta-openembedded
    layers:
      meta-oe:
      meta-python:
      meta-networking:

  meta-raspberrypi:
    url: "https://git.yoctoproject.org/meta-raspberrypi"
    branch: scarthgap
    path: layers/meta-raspberrypi

  meta-mqtt-monitor:
    url: "https://github.com/georgeco/meta-mqtt-monitor.git"
    branch: main
    path: layers/meta-mqtt-monitor

local_conf_header:
  mqtt-monitor-settings: |
    PACKAGE_CLASSES = "package_ipk"
    BB_NUMBER_THREADS = "16"
    PARALLEL_MAKE = "-j 8"
    DL_DIR ?= "/opt/yocto-shared/downloads"
    SSTATE_DIR ?= "/opt/yocto-shared/sstate-cache"
    SSTATE_MIRRORS = "file://.* http://sstate.internal.example.com/sstate-cache/PATH;downloadfilename=PATH"
```

Running an entire build from zero, on any machine with `kas` installed:

```bash
kas build kas-mqtt-monitor.yml
```

This single command clones every repo at the exact pinned branch/commit, generates the correct `bblayers.conf`/`local.conf`, and runs the build — no manual `source oe-init-build-env`, no manual `bitbake-layers add-layer` sequence, no README to keep in sync. The YAML file _is_ the build configuration, checked into version control alongside your actual layer.

## Pinning to exact commits — the reproducibility upgrade over branch names

The config above uses `branch: scarthgap` for the upstream layers — fine for active development, but per Day 13's reproducibility discussion, a genuine release build should pin exact commits:

```yaml
  poky:
    url: "https://git.yoctoproject.org/poky"
    refspec: "9d3b4e8f2a1c7d6e5b4a3928170695e4d3c2b1a"
    path: layers/poky
```

`kas-lock` (kas's own locking mechanism, analogous to a `package-lock.json`/`Cargo.lock` in other ecosystems) can generate a fully pinned lockfile from a branch-based config, capturing the exact commit each branch resolved to at a given point — the practical workflow: develop against floating branches day-to-day, then lock and tag a specific commit set at release time:

```bash
kas dump --resolve-refs kas-mqtt-monitor.yml > kas-mqtt-monitor.lock.yml
```

Committing this lockfile alongside a release tag gives you a genuinely reproducible "rebuild exactly what shipped in v1.2.0" artifact, indefinitely — the actual point of all of Day 13's checksum/SRCREV discipline, now extended to the entire layer stack rather than just your own recipes.

## `kas shell`/`kas menu` — interactive use, not just CI

```bash
kas shell kas-mqtt-monitor.yml
```

Drops you into an interactive shell with everything set up exactly as the automated build would use it — you can run `bitbake`, `devtool`, all the Day 21 workflows, from inside a `kas`-managed environment rather than a manually-`source`d one. This matters because it means your interactive development environment and your CI environment are defined by the _same_ file — eliminating an entire class of "works in CI but not locally" (or vice versa) configuration drift.

## CI pipeline shape — a realistic GitHub Actions example

```yaml
# .github/workflows/build.yml
name: Yocto Build
on: [push, pull_request]

jobs:
  build:
    runs-on: self-hosted   # genuinely needs real compute/disk, GitHub-hosted runners are too constrained
    steps:
      - uses: actions/checkout@v4

      - name: Build image
        run: |
          docker run --rm -v $(pwd):/work -v /opt/yocto-shared:/opt/yocto-shared \
            ghcr.io/siemens/kas:latest \
            kas build /work/kas-mqtt-monitor.yml

      - name: Archive manifest
        uses: actions/upload-artifact@v4
        with:
          name: build-manifest
          path: build/tmp/deploy/images/**/*.manifest

      - name: Archive image
        uses: actions/upload-artifact@v4
        with:
          name: mqtt-monitor-image
          path: build/tmp/deploy/images/**/*.wic.bz2
```

Practical notes on this shape:

- **`self-hosted` runner, not GitHub-hosted**: Day 2's disk/RAM requirements (100GB+, 16GB+ RAM) exceed what hosted CI runners typically provide, and a persistent `self-hosted` runner also lets you maintain a persistent `SSTATE_DIR` across CI runs (mounted, not re-populated from scratch every job) — genuinely essential given Day 27's sstate-sharing discussion; without this, every CI run pays full from-scratch build cost.
- **The official `kas` container image** (`ghcr.io/siemens/kas`) already has BitBake's host dependencies (Day 2) correctly installed — using it avoids re-solving host dependency setup in your own CI image.
- **Manifest archiving** (Day 20's `.manifest` file) as a build artifact, every single build — this is your audit trail; combined with git history of the `kas` lockfile, you can always answer "what exactly was in the image we shipped on date X."

## Automated testing — beyond "did it build"

A build succeeding doesn't mean the image actually works. Yocto has real testing infrastructure worth wiring into CI:

```bitbake
INHERIT += "testimage"
TEST_TARGET = "qemu"
TEST_SUITES = "ssh mqtt-monitor-smoke"
```

```bash
bitbake mqtt-monitor-image -c testimage
```

`testimage` boots the actual built image (in QEMU by default, or against real hardware via `TEST_TARGET = "simpleremote"` with a device's IP) and runs Python-based test cases against it — real boot verification, not just "did BitBake exit zero." A custom test case for your actual stack:

```python
# meta-mqtt-monitor/lib/oeqa/runtime/cases/mqtt_monitor_smoke.py
from oeqa.runtime.case import OERuntimeTestCase

class MqttMonitorSmokeTest(OERuntimeTestCase):
    def test_service_active(self):
        status, output = self.target.run('systemctl is-active mqtt-monitor-cpp')
        self.assertEqual(status, 0, msg=f"Service not active: {output}")

    def test_mqtt_publish_processed(self):
        self.target.run('mosquitto_pub -h localhost -t telemetry/test -m "ci-smoke-test"')
        status, output = self.target.run(
            'sqlite3 /var/lib/mqtt-monitor/monitor.db "SELECT COUNT(*) FROM readings"'
        )
        self.assertGreater(int(output), 0)
```

This closes the loop from Day 24's manual end-to-end verification into something CI runs automatically on every build — genuinely valuable, since it catches "image builds fine, but the service silently fails to start on real boot" regressions (a real, common class of bug that a successful `bitbake` invocation alone never catches) before they reach a device in the field.

## Key takeaways

- `kas` replaces manual multi-step layer setup with a single version-controlled YAML file — eliminates README drift and makes interactive development (`kas shell`) and CI use the identical configuration.
- Pin exact commits (via `kas-lock`/`kas dump --resolve-refs`) at release time, not just branch names — this extends Day 13's recipe-level reproducibility discipline to your entire layer stack.
- CI needs real compute/disk (`self-hosted` runner) and a persistent, mounted `SSTATE_DIR` — without cache persistence across CI runs, you pay full build cost every single time, defeating Day 27's entire cache-sharing strategy.
- Archive the `.manifest` file as a build artifact on every CI run — your ground-truth audit trail for "what shipped when."
- `testimage` + custom `oeqa` test cases boot the actual built image and verify real behavior (service active, MQTT message actually processed and persisted) — catches "builds fine but doesn't actually work" regressions that a successful BitBake exit code alone never would.
- This is the point where your entire curriculum's manual verification steps (Day 7's boot check, Day 19's systemd debugging, Day 24's end-to-end test) become permanent, automated regression protection rather than one-time validation.

