# Vess A3120 — Read-Only Health Monitor (unofficial)

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE) ![Platform: TrueNAS · Proxmox](https://img.shields.io/badge/platform-TrueNAS%20%C2%B7%20Proxmox-informational) ![Disks: read-only](https://img.shields.io/badge/disks-read--only-success)

> **⚠️ Independent, unofficial project — NOT affiliated with, endorsed by, or associated with Promise Technology, Inc.** See [Legal & Trademarks](#legal--trademarks).
>
> **📜 Free software — GPL-3.0.** Use, study, modify, and redistribute under the [GNU General Public License v3.0](LICENSE). Provided with no warranty.

A **read-only health-monitor** edition of the Vess A3120 Linux stack, built to
run safely alongside an existing OS on the **same A3120 chassis** — designed for
**TrueNAS SCALE** and **Proxmox** hosts. It shows drive / pool / SMART /
temperature health and provides full **enclosure fan + front-panel-LED control**,
but **cannot manage drives** — there is no create / delete / migrate / rebuild
path at all. Contains **no vendor source code**; it is an independent
implementation for interoperability with hardware you own.

## Why it is safe to run on a live NAS

Read-only is structural, not just "buttons hidden":

- A dedicated **health backend** fills the drive / array / logical-drive views
  from a periodically-refreshed read-only snapshot (`zpool` / `smartctl` /
  `lsblk` / `mdstat`) — it **never writes** to a disk.
- **No RAID write-helper is installed**, so the web tier has no privileged path
  to alter an array. Drive-management pages are additionally nav-stripped and
  guarded.
- The **enclosure** (fans / LEDs / temperatures / buzzer) stays fully functional
  on real A3120 hardware.

## Editions included

| Edition | For | How |
|---------|-----|-----|
| **TrueNAS App** | TrueNAS SCALE | Custom App / container — `port/packaging/container/`. Privileged + `pid: host`; reads the host's own `zpool`/`smartctl` via `nsenter` and drives the chassis SMBus. |
| **Host install** | Proxmox / Debian | `sudo bash port/packaging/install-webpam-monitor.sh` — nginx + php-fpm + the enclosure daemon, on the host. |

## Features

- Drive / pool / SMART / temperature health for **ZFS** *and* **mdadm / LVM**.
- Enclosure fan curves with **1% granularity** and optional PWM→RPM
  **calibration**; independent CPU-fan and blower control.
- Front-panel **status LEDs** and **network LEDs** (map host NICs to the panel),
  plus the audible **fault buzzer**.
- Onboard / OS disks correctly identified as **Internal** rather than shown in
  empty hot-swap bays.

## Quick start

- **TrueNAS (easy — 3 commands):**
  ```bash
  git clone https://github.com/CyborgFingers/vessapp-a3120-monitor
  cd vessapp-a3120-monitor
  sudo bash port/packaging/truenas-setup.sh
  ```
  Builds the image, deploys the Custom App, and enables TrueNAS login. Full
  walkthrough: [`docs/TRUENAS.md`](docs/TRUENAS.md). (Or paste
  [`docker-compose.yml`](port/packaging/container/docker-compose.yml) via
  Apps → Install via YAML.)
- **Proxmox / Debian host:** `sudo bash port/packaging/install-webpam-monitor.sh`

Then browse `https://<host>:8443/` (container) or `http://<host>:8088/`
(host install) and log in as `administrator` with the password you set
(`promise-webpam-passwd <password>`).

**TrueNAS users:** the app creates a `webpam` group in TrueNAS — anyone in it, or
any TrueNAS admin, signs in with their TrueNAS login. **Updates:** WebPAM checks
for new releases on login and updates in place (settings kept) from the
**Update now** button.

> ⚠️ Read-only for **disks**, but it still drives real chassis **fans and LEDs**.
> Provided **with no warranty** — see [LICENSE](LICENSE). Keep backups.

## Related project

- **vessapp-a3120-linux** — the **full management** edition (create / delete /
  migrate / rebuild arrays). More capable, and correspondingly more dangerous.

## Legal & Trademarks

This project is **independent and unofficial**. It is **not** affiliated with,
authorized by, endorsed by, sponsored by, or associated with **Promise
Technology, Inc.** or any of its subsidiaries or affiliates. *"Promise"*,
*"Vess"*, *"WebPAM"*, *"A3120"*, and all related names and marks are trademarks
or registered trademarks of Promise Technology, Inc. and/or their respective
owners, and are used here **only** to identify the third-party hardware this
software interoperates with (nominative fair use). This project contains **no
Promise Technology source code**. All product and company names are the
property of their respective owners.

## License

**GNU General Public License v3.0** — © 2026 [CyborgFingers](https://github.com/CyborgFingers).
You may use, study, modify, and redistribute this software under the terms of
the [GPL-3.0](LICENSE). Distributed in the hope it will be useful, but **with no
warranty**.

The GPL covers copyright only and grants **no rights in any Promise Technology
name or trademark** — see [Legal & Trademarks](#legal--trademarks).
