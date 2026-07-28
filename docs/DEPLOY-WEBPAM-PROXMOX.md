# DEPLOY-WEBPAM-PROXMOX — the WebPAM web tier on the live A3120 (Proxmox VE)

The box: Debian 13 "trixie" userland under Proxmox VE 9.1, x86-64, `/dev/i2c-0`
(i801 SMBus) live, `promise-encld.service` already driving the real fans/LEDs.
No php / nginx / cmake preinstalled. The ASM2106B SATA controller runs **AHCI**
firmware, so there is **no RAID engine yet** — see "Drives light up later".

## Install (on the box, as root)

```sh
# from the Mac: ship the repo (same rsync lane as the encld deploy), then:
rsync -a --exclude reference/ --exclude windows-src/ --exclude 're/' \
    ~/Downloads/vessapp-linux/ root@192.0.2.8:/root/vessapp-linux/
ssh root@192.0.2.8 'bash /root/vessapp-linux/port/packaging/install-webpam.sh'
```

Then browse **http://192.0.2.8:8088/index.php** — login `administrator`,
any password (fixture auth until the engine user-db hook can be bound, which
needs the RAID engine).

## What install-webpam.sh does (and does not)

| step | what | notes |
|------|------|-------|
| 1 | `apt-get install nginx php-fpm php-dev php-cli` | trixie ships **PHP 8.4**; version auto-detected |
| 2 | `phpize8.4` build of `php_i2` from `port/php-ext` | no cmake; installs `/opt/promise/lib/php/i2.so`, asserts it loads |
| 3 | htdocs → `/opt/promise/webpam/htdocs` | + `compat-prepend.php` (apache_getenv shim) |
| 4 | nginx vhost **:8088** + FPM pool + php ini | pool = Phase-6 `fpm-webpam.conf` (fork-shared dc/session store); ini forces `short_open_tag=On` |
| 5 | `/etc/promise/promise.env`: `VESSAPP_BACKEND=smbus`, `VESSAPP_I2C_BUS=0` | replace-or-append; the rest of the operator file is untouched |
| 6 | php-fpm unit drop-in (`EnvironmentFile=/etc/promise/promise.env`) + `www-data` → group `i2c`, udev rule `60-promise-i2c.rules` (`/dev/i2c-* root:i2c 0660`) | FPM workers read the PCA9555/NCT7802Y directly |
| 7 | `systemctl enable/restart nginx php8.4-fpm` | **only** these two units |

Idempotent — safe to re-run after pulling a newer tree.

### Proxmox separation

- WebPAM binds **:8088** only. `pveproxy` keeps **:8006**; spiceproxy, pvedaemon,
  the pve cluster stack and `promise-encld.service` are never restarted,
  reconfigured or masked.
- If the installer is the one that brings nginx onto the box, it disables the
  distro default **:80** site so the hypervisor grows no surprise listener.
  A pre-existing nginx config is left exactly as found.
- Uninstall (`uninstall-webpam.sh`) removes only the vhost/pool/ini/drop-in/
  udev rule, `/opt/promise/webpam` and `i2.so`. Packages, `/etc/promise`
  (encld reads it) and `/opt/promise/bin` (encld lives there) stay.

## How the enclosure pages get REAL data

`VESSAPP_BACKEND=smbus` (from `promise.env`, injected into php-fpm by the unit
drop-in, passed to workers by `clear_env=no`) flips the php_i2 substrate's
`encl_backend()` dispatch:

- fans / temps / voltages / element counts → `smbus_encl.c`: NCT7802Y @0x2e
  (blower tach) + kernel hwmon (nct6776 CPU fan, temps, rails) on `/dev/i2c-0`
- LEDs → PCA9555 @0x20 (FrontPanel HDD bays) / @0x21 (NetworkPanel)
- enclosure identity → the RE-known A3120 chassis (`ENC_TYPE_VESS_3U16BAY`,
  model byte 'R'); there is no SEP info page without the RAID engine
- front view → the 4 wired FrontPanel bays (`NumOfHDSlots`)

Addresses are compile-time constants of the smbus backend — the same map the
deployed `promise-encld` uses. Both opening `/dev/i2c-0` concurrently is fine
(i2c-dev serialises per transaction; same coexistence as the Python driver era).

## Graceful no-drives behaviour (until the RAID-mode reboot)

`I2BDG_SG_DEV` stays **unset** and the backend is not SCSI, so:

- the php_i2 fixture does **not** seed its demo mock (that seeding now happens
  only in pure-fixture/SCSI mode) — no fake `ST4000NM0023`/`DA7` rows
- RAID reads find no engine and fail cleanly → `i2_records_*` return empty
  arrays → drive / logical-drive / array pages render **empty tables**, no
  PHP fatals, no 500s

### Drives light up after the RAID-mode reboot

When the ASM2106B is flashed to RAID-mode firmware (out of scope here), a
Promise SCSI target appears; set `I2BDG_SG_DEV=/dev/sgN` in
`/etc/promise/promise.env`, `systemctl restart php8.4-fpm`, and the same pages
enumerate the real drives/arrays over SG_IO. `calibrate.sh` first, as ever.

## PHP 8.4 (trixie) htdocs compatibility

The shipped app was PHP 7.3 and Phases 4/6 ran on 7.4. Trixie ships 8.4; the
tree needed exactly three kinds of fix (same minimal-edit discipline as
Phase 4):

1. `tree.php`/`tree2.php`: PHP4 method-name constructors → one-line
   `__construct` forwarders (14 classes).
2. `event.php`/`events.php`: `each()` (removed in 8.0) → `foreach` (3 loops).
3. `php-webpam.ini`: `short_open_tag = On` — the htdocs use `<?` everywhere;
   the php-docker images ship no php.ini (compiled default On) which is why
   this never surfaced before; Debian's packaged php.ini sets Off.

Pre-existing shipped-broken pages (fail identically on PHP 7, not referenced
by any served page): `servicet.php` (vendor quoting typo), `migrate2.php`
(call-time by-reference, dead since PHP 5.4).

## Verification

- `bash port/packaging/webpam-trixie-smoke.sh` — runs the real installer in a
  `debian:trixie-slim` (amd64) container, then: extension loads on 8.4, dc →
  login → screen frameset, Subsystem + Enclosure + front pages render with no
  PHP errors, and arrays/phydrvs/logdrvs render EMPTY (no fixture bleed). In
  the container the smbus reads see no `/dev/i2c-0`, which doubles as the
  proof the enclosure pages survive sensor loss without a 500.
- `bash port/build-in-container.sh` — the 10 C suites + php ext fixture smoke.
- On the box after install: `curl -s http://127.0.0.1:8088/index.php | grep -c
  dynccode` → 1, then log in from a browser and open Subsystem → Enclosure:
  fan RPM / temperatures / voltages are the live chassis (compare with
  `promise-encld --dry-run --once` output).

## Uninstall

```sh
ssh root@192.0.2.8 'bash /root/vessapp-linux/port/packaging/uninstall-webpam.sh'
```
