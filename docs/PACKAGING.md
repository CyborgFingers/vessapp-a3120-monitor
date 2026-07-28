# Phase 6 — packaging + deployment (VessApp / WebPAM on Linux)

Turns the ported parts (Phases 1–5) into a deployable product. Everything lives
in `port/packaging/`. Two modes, one knob (`/etc/promise/promise.env`):

- **OFF-DEVICE (fixture, default)** — no `I2BDG_SG_DEV`: the substrate seeds the
  mock SCSI transport (demo enclosure + RAID data). Runs on any Linux box or
  container; nothing touches hardware.
- **ON-DEVICE (real Vess A3120)** — `I2BDG_SG_DEV=/dev/sgN`: the identical code
  paths drive the real controller via SG_IO. Guarded behind
  `install.sh --on-device` + the Phase-6b checklist (§6).

## 1. The production web tier (why nginx + PHP-FPM)

The Phase-4 tier ran under `php -S` (single-threaded). The post-login WebPAM
**frameset** (`screen.php` → header / tree / home / event / daemon frames)
issues parallel requests — including `daemon.php`, a 2-second event poller — so
frames starved and timed out in a browser. The packaged tier is
**nginx + PHP-FPM (pool of 4 static workers)**: real concurrency, frameset
loads. Config files (used verbatim by both the container check and the device
install):

| file | role | installs to (device) |
|---|---|---|
| `packaging/nginx-webpam.conf` | server block, HTTP :8088 (+ commented TLS :8443) | `/etc/nginx/sites-available/promise-webpam.conf` |
| `packaging/fpm-webpam.conf` | FPM pool `webpam` (unix socket, pm=static 4, `/fpm-status`) | `/etc/php/7.4/fpm/pool.d/webpam.conf` |
| `packaging/php-webpam.ini` | PHP posture mirrored from the shipped appliance php.ini (`output_buffering=On` is load-bearing), loads `i2.so`, file sessions | `/etc/php/7.4/fpm/conf.d/90-promise-webpam.ini` |
| `packaging/compat-prepend.php` | `apache_getenv()` shim (mod_php-only fn used by export/import pages) | `/opt/promise/webpam/compat-prepend.php` |

Two Phase-6 fixes make multi-worker FPM correct:

1. **Fork-shared session store** — the php_i2 dc/session tables
   (`port/src/opasd/opas_session.c`) live in `MAP_SHARED|MAP_ANONYMOUS` memory
   created at MINIT in the FPM **master**, so every forked worker shares them.
   A challenge issued by one worker validates on any other. PHP's own
   `$_SESSION` uses file sessions (cross-worker by nature).
2. **`getLastError()`** — registered in the extension (php_i2.dll provenance;
   16 call sites). The daemon frame reaches it on its first real poll — a path
   only exercisable once the frameset actually loads.

### HTTPS / TLS

The shipped appliance fronted PHP with a self-signed **stunnel**; Phase 4
relaxed that so plain HTTP works off-device. For production, mirror the
appliance posture with nginx TLS instead: generate a self-signed pair and
enable the commented `:8443 ssl` server block in `nginx-webpam.conf`:

```
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -keyout /etc/promise/webpam.key -out /etc/promise/webpam.crt \
  -subj "/CN=$(hostname)"
# then uncomment the ssl server block, and set session.cookie_secure=1
# in php-webpam.ini, and: systemctl reload nginx
```

## 2. Try the full stack off-device (browser check)

```
bash port/packaging/run-fpm.sh          # foreground; Ctrl-C stops
```

Prints the URL (Docker: `http://localhost:8088/index.php`; Apple `container`:
the container's own IP). Log in `administrator` / any password → the **complete
framed WebPAM** loads: tree (DA7 / SystemVolume / PD5 / enclosure), panels,
event bar, daemon poller.

Headless proof (CI-able):

```
bash port/packaging/fpm-smoke.sh
```

Boots the same stack with `pm.max_requests = 1` (every request is served by a
**brand-new worker process**) and asserts: multi-process pool up → login (dc
issued by one process, consumed by another) → frameset → **3 rounds of all
frames requested concurrently** (header/tree/home/event/daemon + arrays +
subsystem), every one HTTP 200, no PHP error, session held, fixture data
intact. This is exactly the workload `php -S` could not serve.

## 3. systemd layout

`port/packaging/systemd/` — grouped under **`promise-webpam.target`**
(`systemctl {start,stop,status} promise-webpam.target`):

| unit | what | type |
|---|---|---|
| `opasd.service` | OPAS engine API on `/run/promise/opasd.sock` | resident (`Restart=on-failure`) |
| — (snmpd) | Promise SNMP subagent = net-snmp **pass_persist** child of the stock `snmpd.service`, per `/etc/snmp/snmpd.conf` (`.1.3.6.1.4.1.7933.1.20` → `/opt/promise/bin/promise_snmp`) | stock unit, wanted by the target |
| `emaild.service` | event→SMTP alerts. **Scaffold**: one-shot; with the installed default (`<enabled>0</enabled>`) exits quietly; once SMTP is configured it sends the recovered test mail at stack start. Resident poll loop lands with the `i2_getEvents` calibration | oneshot, `RemainAfterExit` |
| `prevtlogd.service` | engine event log → syslog. **Scaffold**: same calibration gate | oneshot, `RemainAfterExit` |
| `bgasched.service` | background-activity scheduler; loads `/etc/promise/bgasched.conf`. **Scaffold** | oneshot, `RemainAfterExit` |
| `pwrmon.service` | graceful controller shutdown on power loss. **Trigger unit** — NOT in the target; wire the UPS notifier to it (NUT: `NOTIFYCMD "systemctl start pwrmon.service"`) | oneshot, no `[Install]` |
| `php-fpm-promise-dropin.conf` | drop-in for the stock `php7.4-fpm.service`: `EnvironmentFile=/etc/promise/promise.env` so the extension sees `I2BDG_SG_DEV` | drop-in |
| stock `nginx.service`, `php7.4-fpm.service`, `snmpd.service`, `slpd.service` | web server, FPM, SNMP, SLP discovery (optional) | wanted by the target |

All Promise units read `EnvironmentFile=-/etc/promise/promise.env` — the single
fixture/device switch.

## 4. install.sh / uninstall.sh

```
sudo bash port/packaging/install.sh                     # fixture (default)
sudo bash port/packaging/install.sh --on-device /dev/sgN
sudo bash port/packaging/uninstall.sh [--purge]
```

`install.sh` (idempotent; run from the repo checkout on the target):

1. finds PHP 7.x (`php-fpm7.4/7.3/7.2`) or apt-installs `php7.4-*`; if the repo
   lacks PHP 7 (Debian ≥ 12) it prints the exact sury.org setup and stops —
   the htdocs need PHP 7 (PHP4-style constructors, removed in PHP 8)
2. apt: build-essential, cmake, nginx, snmpd/snmp, sg3-utils (+ optional
   `openslp-server`)
3. cmake **Release** build → 21 binaries → `/opt/promise/bin`
4. `phpize7.x` build of `php_i2` → `/opt/promise/lib/php/i2.so` (+ load check)
5. htdocs → `/opt/promise/webpam/htdocs`; nginx site + FPM pool + php ini
6. `/etc/promise/{promise.env,emailsetting.xml,bgasched.conf}` (never clobbers
   operator edits; email default **disabled**), ported `snmpd.conf` (original
   directives + pass_persist; prior conf kept as `snmpd.conf.pre-promise`),
   `raidv4.mib` → `/usr/share/snmp/mibs/`, SLP registration (marker block in
   `/etc/slp.reg`)
7. systemd units + target + FPM drop-in, `daemon-reload`
8. mode: `--on-device` writes `I2BDG_SG_DEV` into `promise.env` and adds
   `www-data` to the `disk` group (FPM workers must open `/dev/sg*`); enables +
   starts `promise-webpam.target`, prints the URL

`uninstall.sh` reverses it (units, `/opt/promise`, web/snmp/slp config;
restores `snmpd.conf.pre-promise`), keeping `/etc/promise` unless `--purge`.

## 5. Verify after install

```
systemctl status promise-webpam.target        # everything green
curl -s http://localhost:8088/index.php | grep -o WebPAM | head -1
snmpwalk -v2c -c VessApp localhost .1.3.6.1.4.1.7933.1.20.1.2.1.4.1   # model
/opt/promise/bin/promise_trap -e 0x000D0002 -s 'Enclosure 1 Slot 3'   # test trap
/opt/promise/bin/emaild -f /etc/promise/emailsetting.xml --test       # test mail
```

## 6. Phase 6b — ON-DEVICE bring-up checklist (operator, on the real A3120)

Executed by the operator on the box; nothing here is faked off-device.

1. **Install Linux** (Debian x86-64) on the A3120; clone/copy this repo onto it.
2. **Find the controller node**: `lsscsi -g` / `sg_inq /dev/sgN` — the Promise
   RAID controller's SCSI-generic node. Probe SES first (may simplify enclosure
   paths): `sg_ses /dev/sgN`.
3. **Install on-device**: `sudo bash port/packaging/install.sh --on-device /dev/sgN`.
4. **Calibrate (read-only)**: `bash port/scripts/calibrate.sh` — the Phase-2b
   enumeration self-test against the real controller. Every RE-derived constant
   it flags is a calibration point; nothing writes.
5. **Confirm real reads in WebPAM**: log in — the tree must show the box's real
   enclosure/arrays/drives (not demo `DA7`/`SystemVolume`). `snmpwalk` the
   subtree; check fan RPM / temperature values against the front panel.
6. **Bind the auth hook** (the one remaining code TODO, `RE-php_i2.md` §5 /
   `RE-opas.md` §1.4): replace the fixture accept-any-password hook with the
   engine user-db credential check (`opas_session_set_auth`). Until then the
   web login accepts any password for `administrator` — do not expose the port
   beyond a trusted management LAN.
7. **Traps + mail on real events**: point `trap2sink` (snmpd.conf) at the NMS;
   enable + configure `/etc/promise/emailsetting.xml`; wire the UPS notifier to
   `pwrmon.service`. The event-driven loops (emaild/prevtlogd poll, bgasched
   schedules, pwrmon ShutdownHBA) activate as their engine opcodes are
   calibrated (`RE-snmp-daemons.md` §4 UNRESOLVED list).
8. **TLS**: enable the `:8443 ssl` block (§1) and `session.cookie_secure=1`.

## 7. What remains open (tracked, not hidden)

- Engine-side MicOpCodes: `i2_getEvents`, `ShutdownHBA`, `_getBGASchedules`
  (`RE-snmp-daemons.md` §4) — gate the resident daemon loops.
- Auth hook → engine user-db binding (§6.6).
- SLP vendor srvtype string (`src/slp/promise.reg` CALIBRATE).
- `i2_getSubsystemInfo` page offsets (serial/WWN placeholders).
