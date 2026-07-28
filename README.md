# Vess A3120 — Read-Only Health Monitor (unofficial)

> **⚠️ Independent, unofficial project — NOT affiliated with, endorsed by, or associated with Promise Technology, Inc.** See [Legal & Trademarks](#legal--trademarks).
>
> **🔒 Proprietary — All Rights Reserved.** Access to this source does **not** grant any license. No use, modification, or redistribution is permitted without prior **written permission**. See [LICENSE](LICENSE).

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

- **TrueNAS SCALE:** build the image and install it as a Custom App — see
  [`port/packaging/container/README.md`](port/packaging/container/README.md).
- **Proxmox / Debian host:** `sudo bash port/packaging/install-webpam-monitor.sh`

Then browse `https://<host>:8443/` (container) or `http://<host>:8088/`
(host install) and log in as `administrator` with the password you set
(`promise-webpam-passwd <password>`).

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

**Proprietary — All Rights Reserved.** © 2026 Joshua McNabb-Moss.
See [LICENSE](LICENSE). No use, modification, or redistribution without prior
written permission. To request permission, contact the copyright holder via
[github.com/CyborgFingers](https://github.com/CyborgFingers).
