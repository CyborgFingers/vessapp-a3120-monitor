# Vess A3120 WebPAM monitor on TrueNAS — Setup & Guide

A read-only drive/pool health dashboard with full enclosure (fan + LED) control
for the Promise Vess A3120, running as a TrueNAS **Custom App**. It reads the
host's own `zpool`/`smartctl` and drives the A3120 SMBus — it never writes to a
disk. Independent, unofficial; **not affiliated with Promise Technology**.

## Requirements

- **TrueNAS SCALE 24.10 (Electric Eel) or newer** (Docker-based apps).
- SSH enabled, and an account with `sudo` (e.g. `truenas_admin`).
- Enclosure fan/LED control needs real **A3120 hardware**; the health dashboard
  works on any box.

## Install — 3 commands

On the TrueNAS box (over SSH):

```bash
git clone https://github.com/CyborgFingers/vessapp-a3120-monitor
cd vessapp-a3120-monitor
sudo bash port/packaging/truenas-setup.sh
```

No `git`? Swap the first line for:

```bash
curl -fsSL https://github.com/CyborgFingers/vessapp-a3120-monitor/archive/refs/heads/main.tar.gz | tar xz && cd vessapp-a3120-monitor-main
```

The script builds the image (first run takes a few minutes), creates the
`webpam-monitor` app with the TrueNAS user-sync enabled, and prompts for the
admin password. The enclosure **drivers load themselves** when the app starts —
nothing else to install. Re-running the script just redeploys onto a fresh
build.

Then browse **`https://<nas-ip>:8443/`** and log in as **`administrator`** with
the password you set (`:8088` redirects to HTTPS; the cert is self-signed).

## Let your TrueNAS users in

The app creates a **`webpam`** group in TrueNAS. Anyone in that group — and any
TrueNAS admin — can sign in to WebPAM with their **TrueNAS username + password**;
no separate WebPAM account needed. Manage access from the TrueNAS group
(Credentials → Local Groups → `webpam` → Members); WebPAM follows it.

> The built-in `administrator` login always keeps working.

## Updates

When a newer release is published, WebPAM shows an **update banner** on login.
Click **Update now** — it pulls the new image and restarts the app; your settings
(login, fan calibration, front-panel config) are kept on the persistent volume.
If the in-app update doesn't finish, TrueNAS → Apps → `webpam-monitor` → **Update**
does the same thing.

## Email: route TrueNAS alerts through WebPAM

On WebPAM's **Email** page, set your SMTP server and tick **"Also route TrueNAS
system alerts through this SMTP server."** TrueNAS's own alert emails will then
go out through the same server.

## Rollback / uninstall

- **Rollback** (if an update misbehaves): the previous image is tagged
  `webpam-monitor:stable-patched` — point the app back at it and redeploy.
- **Uninstall**: TrueNAS → Apps → `webpam-monitor` → Delete. Optionally remove
  the `webpam` group afterwards.

## Legal

Independent and unofficial. Not affiliated with, endorsed by, or associated with
Promise Technology, Inc. "Promise", "Vess", "WebPAM", and "A3120" are trademarks
of their respective owners, used only to identify the hardware this interoperates
with. Licensed under the GNU GPL-3.0; no warranty.
