# Vess A3120 — Read-Only Health Monitor (unofficial)

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE) ![Platform: TrueNAS · Proxmox](https://img.shields.io/badge/platform-TrueNAS%20%C2%B7%20Proxmox-informational) ![Disks: read-only](https://img.shields.io/badge/disks-read--only-success)

> **⚠️ Independent, unofficial project — NOT affiliated with, endorsed by, or associated with Promise Technology, Inc.** See [Legal & Trademarks](#legal--trademarks).
>
> **📜 Free software — GPL-3.0.** Use, study, modify, and redistribute under the [GNU General Public License v3.0](LICENSE). Provided with no warranty.

A drive / pool / SMART / temperature **health dashboard** with full **enclosure
fan + front-panel-LED control** for the **Promise Vess A3120**, built to run
safely **alongside** TrueNAS or Proxmox on the same chassis. It reads the host's
own `zpool` / `smartctl` and drives the A3120 SMBus — and it **cannot touch your
data**: there is no create / delete / migrate / rebuild path anywhere in it.

---

## 🚀 Install on TrueNAS — 3 commands

SSH into the NAS as an admin account and run:

```bash
git clone https://github.com/CyborgFingers/vessapp-a3120-monitor
cd vessapp-a3120-monitor
sudo bash port/packaging/truenas-setup.sh
```

That's it. The script **builds the image, deploys it as a managed Custom App,
loads the enclosure drivers, and turns on TrueNAS login** — it just asks for the
admin password. Then open **`https://<nas-ip>:8443/`** and sign in as
`administrator`.

<sub>No `git`? → `curl -fsSL https://github.com/CyborgFingers/vessapp-a3120-monitor/archive/refs/heads/main.tar.gz | tar xz && cd vessapp-a3120-monitor-main`  ·  Prefer the UI? → paste [`docker-compose.yml`](port/packaging/container/docker-compose.yml) in **Apps → Install via YAML**  ·  Proxmox / Debian host? → `sudo bash port/packaging/install-webpam-monitor.sh`</sub>

**Full walkthrough: [`docs/TRUENAS.md`](docs/TRUENAS.md).**

---

## What you get

| | |
|---|---|
| 🩺 **Health, ZFS *and* mdadm/LVM** | Pools, arrays, logical & physical drives with SMART + temperature. Onboard/OS disks show as **Internal**, not in empty bays. |
| 🌀 **Enclosure control** | CPU-fan and chassis-blower curves with **1% granularity** + optional PWM→RPM calibration, front-panel status & network LEDs, and the fault buzzer. |
| 👤 **Sign in with TrueNAS** | Members of the auto-created `webpam` group — and any TrueNAS admin — log in with their **TrueNAS credentials**. No separate password to manage. |
| ⬆️ **One-click updates** | WebPAM checks for new releases on login and updates **in place**, keeping your settings. |
| 💾 **Drive health scoring** | Per-drive **Health %**, temperature and power-on hours from **[Hard Disk Sentinel](https://www.hdsentinel.com)** (auto-installed on first run), shown on the drive pages with alerts when a disk degrades. |
| 📋 **System event log** | One page merging TrueNAS system alerts, the enclosure sensor faults WebPAM detects, and the host syslog — with email on each new fault. |
| ✉️ **Alerts through your mail server** | Point TrueNAS's own alert emails at the SMTP server you set in WebPAM. |

## Why it's safe on a live NAS

Read-only is **structural**, not just hidden buttons:

- a dedicated health backend fills every view from a periodic, read-only snapshot
  (`zpool` / `smartctl` / `lsblk` / `mdstat`) — it **never writes** to a disk;
- **no RAID write-helper is installed**, so the web tier has no privileged path
  to alter an array; drive-management pages are additionally nav-stripped and guarded;
- the **enclosure** (fans / LEDs / temperatures / buzzer) stays fully functional
  on real A3120 hardware.

## Using it

- **Log in** as `administrator` (the password you set), or as any TrueNAS user in
  the `webpam` group / any TrueNAS admin, with their TrueNAS password.
- **Add people:** TrueNAS → Credentials → Local Groups → `webpam` → Members.
- **Update:** click the banner that appears on login → **Update now**.
- **Email relay:** WebPAM **Email** page → set your SMTP → tick *"route TrueNAS
  alerts through this SMTP server."*
- **Rollback:** the prior image is kept as `webpam-monitor:stable-patched`.

See [`docs/TRUENAS.md`](docs/TRUENAS.md) for the full guide.

## Requirements

- **TrueNAS SCALE 24.10 (Electric Eel) or newer** (Docker-based apps), or a
  Proxmox / Debian host.
- Enclosure fan/LED control needs real **A3120 hardware**; the health dashboard
  works anywhere.

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
Promise Technology source code**. All product and company names are the property
of their respective owners.

## License

**GNU General Public License v3.0** — © 2026 [CyborgFingers](https://github.com/CyborgFingers).
You may use, study, modify, and redistribute this software under the terms of the
[GPL-3.0](LICENSE). Distributed in the hope it will be useful, but **with no
warranty**.

The GPL covers copyright only and grants **no rights in any Promise Technology
name or trademark** — see [Legal & Trademarks](#legal--trademarks).

## Acknowledgements

Drive **Health %** scoring is provided by **[Hard Disk Sentinel](https://www.hdsentinel.com)**
© János Máthé — a separate, proprietary freeware tool. It is **not bundled** with
this GPL project; when enabled, the free Linux console build is downloaded from
hdsentinel.com into the app's own volume on first run, and WebPAM reads its
output. All credit for the health analysis belongs to Hard Disk Sentinel. If the
tool is absent, the drive pages fall back to native `smartctl` status.
