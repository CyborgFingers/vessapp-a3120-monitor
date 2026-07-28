# DEPLOY-FULL-STACK — the whole VessApp/WebPAM port on one Linux A3120

The single runbook for standing up the **complete** port on a production Vess
A3120 running Linux: enclosure (fans/LEDs/sensors) + mdadm RAID + the WebPAM web
tier + RAID event monitoring + email + SNMP-trap delivery + scheduled media
patrol. Fragment docs it ties together: [DEPLOY-ONDEVICE.md](DEPLOY-ONDEVICE.md)
(the `promise-encld` fan cutover in detail), [PACKAGING.md](PACKAGING.md),
[DEPLOY-WEBPAM-PROXMOX.md](DEPLOY-WEBPAM-PROXMOX.md).

## What gets installed

| Component | Unit | Backend / notes |
|-----------|------|-----------------|
| WebPAM web tier | `nginx` + `phpX.Y-fpm` | serves `/opt/promise/webpam/htdocs` |
| `php_i2` engine | (php extension) | links the substrate; `VESSAPP_*` selects backends |
| Enclosure (fans/LEDs/temp/volt) | `promise-encld.service` | SMBus (`VESSAPP_BACKEND=smbus`) |
| RAID | `promise-raid-helper` (setuid) | mdadm (`VESSAPP_RAID_BACKEND=mdadm`) |
| RAID events | `promise-mdmonitor.service` | `mdadm --monitor` → log/email/trap |
| Email alerts | `emaild` + local postfix relay | plain SMTP → relay AUTH+TLS upstream |
| SNMP traps | `promise_trap` | Promise `raidv4TrapMessage` (OID 7933) |
| Scheduled media patrol | `bgasched.timer` → `bgasched.service` | monthly `check` scrub on all arrays |

## 0. Prerequisites

- Debian 12/13 on the A3120, root SSH.
- The 4 data disks are **raw SATA** (the A3120 "RAID" is a Windows-software
  construct; on Linux the ASM2106B binds `ahci`). Identify the **OS disk** — it
  must never be in an array.
- `install-webpam.sh` apt-installs what it needs (`nginx php-fpm php-dev php-cli`
  for the web tier; `mdadm smartmontools util-linux snmp` for RAID). The mail
  relay step additionally installs `postfix libsasl2-modules`.

## 1. Copy the tree

```sh
rsync -az --exclude .git --exclude reference --exclude graphify-out \
  port/ root@<A3120>:/root/vessapp-port/
```

## 2. Configure `/etc/promise/promise.env`

`install-webpam.sh` seeds it and sets the enclosure keys; confirm/adjust:

```sh
VESSAPP_BACKEND=smbus          # real enclosure over SMBus (PCA9555 0x20/0x21, NCT7802Y 0x2e)
VESSAPP_I2C_BUS=0
VESSAPP_RAID_BACKEND=mdadm     # REQUIRED to build the setuid helper + RAID daemons
VESSAPP_RAID_EXCLUDE=<os-disk> # e.g. sdd — belt-and-suspenders OS-disk guard
PROMISE_FANCONFIG=/etc/promise/fanconfig.json
PROMISE_CALIBRATION=/etc/promise/FanCalibration.json
```

> The engine also guards the OS disk automatically (it keys off the `/`
> mountpoint + PD ids, robust across the disk-letter shuffle on reboot), but set
> `VESSAPP_RAID_EXCLUDE` too.

## 3. Install

```sh
cd /root/vessapp-port
VESSAPP_BACKEND=smbus VESSAPP_RAID_BACKEND=mdadm bash packaging/install-webpam.sh
```

Idempotent. It builds `php_i2`, installs htdocs, builds+installs the setuid
`promise-raid-helper` (root:www-data 4750), `emaild`, `promise_trap`,
`bgasched`, installs `promise-mdmonitor.service` + `bgasched.timer`, and
restarts nginx + php-fpm. Expect: `i2.so loads`, `NNN PHP pages installed`,
`promise-raid-helper -> ... 4750`, `promise-mdmonitor.service enabled`,
`bgasched.timer enabled`.

## 4. Enclosure cutover (fans/LEDs)

Bring up `promise-encld` per **[DEPLOY-ONDEVICE.md](DEPLOY-ONDEVICE.md)**
(`--dry-run` → `--once` → cutover, with auto-rollback). It replaces any prior
custom fan driver. Thermal safety: never-0 PWM floor, emergency ramp ≥85 °C.

> **Enclosure-sensor kernel modules.** The blower/LEDs come over SMBus
> (`i2c-dev`, `i2c-i801`) and the **Controller temp** from the nct6776 Super-I/O
> (`nct6775`). install-webpam loads + persists these (`/etc/modules-load.d/
> promise-encl.conf`). If the Controller temp still reads 0 after install,
> `nct6775` didn't bind — add `acpi_enforce_resources=lax` to the kernel cmdline
> and reboot. Verify: `promise-encld --once` prints `blower … rpm=NNNN` and the
> WebPAM enclosure page shows CPU + Controller temps.

## 5. Email-alert delivery (optional but recommended)

`emaild` speaks plain SMTP, so it sends to a local postfix relay that does
SASL AUTH + STARTTLS upstream. Provide the upstream via env (the password only
ever lands in `/etc/postfix/sasl_passwd`, 0600 — never in the repo):

```sh
SMTP_HOST=mail.example.com SMTP_PORT=587 SMTP_USER=noreply@example.com \
SMTP_PASS='...' MAIL_FROM=noreply@example.com MAIL_TO=admin@example.com \
bash packaging/setup-mail-relay.sh
# test: /opt/promise/bin/emaild --event 0x000D0002 -f /etc/promise/emailsetting.xml
```

Tune the alert threshold in `/etc/promise/emailsetting.xml`
(`severityThreshold`, 0 Info .. 7 Fatal; the RAID handler emits 2/3/5).

## 6. SNMP-trap destination (optional)

Point traps at your NMS (else the built-in `127.0.0.1` / community `VessApp`):

```sh
cat > /etc/promise/snmptrap.conf <<'EOF'
SNMP_TRAP_HOST=nms.example.com
SNMP_TRAP_COMMUNITY=VessApp
EOF
```

Load `raidv4.mib` on the receiver to decode the 7 varbinds.

## 7. Verify

```sh
# web: log in (administrator), Subsystem shows "Vess A3120"
# RAID: create an array in WebPAM (Disk Arrays → Create), confirm md0:
mdadm --detail /dev/md0
# events + delivery: fail a member (EXPENDABLE data only!) and watch the chain:
mdadm --fail /dev/md0 /dev/sdX
tail -1 /var/log/promise-raid/events.log        # Fail 0x000D0002 (PD_MARKED_DEAD, Major)
journalctl -u postfix | tail                    # status=sent to MAIL_TO (if step 5)
# scheduled scrub:
systemctl list-timers bgasched.timer            # next run scheduled
/opt/promise/bin/bgasched                        # arms a check now; cat /sys/block/md0/md/last_sync_action == check
```

## 8. On-device calibration (only if a NEW chassis revision)

The A3120 is `1U4BAY`, model byte `'W'` (0x57). If a unit reports a different
SEP model byte, add a one-line alias in `port/include/i2c_map.h` and the switch
in `port/src/lib/i2bdg_sensors.c`. Enclosure base offsets / SET payload layout
are hardware-confirmed on the golden reference; re-check with
`port/scripts/calibrate.sh` if sensors read wrong.

## Power loss & the legacy Windows daemons

Two vendor daemons are **superseded on Linux** and are not wired/installed by
`install-webpam.sh`:

- **`prevtlogd`** polled the engine event log and forwarded to the Windows
  EventLog. On the mdadm backend that job is done better by
  `promise-mdmonitor` (mdadm's own event stream → `events.log` + email + trap),
  so prevtlogd is redundant.
- **`pwrmon`** issued a Windows controller `ShutdownHBA` on power loss. The
  Linux equivalent is a **UPS + NUT**: configure `nut`/`upsmon` for your UPS so
  low-battery triggers `systemctl poweroff`; systemd's normal shutdown flushes
  and stops the md arrays cleanly. The port cannot auto-configure NUT (UPS
  model-specific). Do this if the box isn't already on a managed UPS.

## Known gaps

- `schedule.php`'s custom-schedule UI is not wired to persist per-user windows;
  the monthly `bgasched.timer` scrub is the background-activity coverage.
- Config pages for hardware the A3120 lacks (iSCSI/FC/SAS/CIM) render but are
  inert.
