# DEPLOY-ONDEVICE — promise-encld cutover on the live A3120 (Proxmox)

The runbook for replacing the Python `promise-hwmon` fan/LED control with the
port's `promise-encld` daemon on the live box (root@192.0.2.8). Every step
before the cutover is read-only or reversible; the rollback is one command.

**What encld does** (and doesn't): drives the two fan zones — NCT7802Y blower
(i2c-0 0x2e, FANCTL1+2) from the `SysFanLevelList` curve and the nct6776 CPU
fan (hwmon pwm2) from `CpuFanLevelList`, both floored at PWM 20 (never 0),
using the SAME fanconfig.json + FanCalibration.json the Python driver runs
today — and keeps Power (green), Blower (green/red from tach health: <500 RPM
for 60 s = fault) and System status LEDs live. It does NOT replace the Python
stack's web UI, HDD/RAID/net LED logic, drive health scans, buzzer, or sysmon
alarms — at startup it turns those unmanaged panel LEDs off so nothing shows
stale state.

## 0. Build the static binary (off-device, this repo)

```bash
bash port/scripts/build-encld-static.sh
file port/build/promise-encld   # ELF 64-bit x86-64, statically linked
```

Copy to the box (binary + unit only; nothing is enabled by this):

```bash
scp port/build/promise-encld root@192.0.2.8:/opt/promise/bin/promise-encld
scp port/packaging/systemd/promise-encld.service root@192.0.2.8:/etc/systemd/system/
scp port/packaging/promise.env root@192.0.2.8:/etc/promise/promise.env  # if not present yet
ssh root@192.0.2.8 'chmod +x /opt/promise/bin/promise-encld && systemctl daemon-reload'
```

`/etc/promise/promise.env` defaults already point at the Python driver's live
files (`/var/lib/promise-hwmon/config/fanconfig.json`,
`/var/lib/promise-hwmon/data/FanCalibration.json`) — no edits needed unless
those moved.

## 1. Validate: `--dry-run` (writes NOTHING — safe with promise-hwmon running)

```bash
modprobe i2c-dev i2c-i801   # if not already loaded
/opt/promise/bin/promise-encld --dry-run --once
```

Check the banner + decision line:
- `fanconfig ... (loaded)` and `calibration ... (blower pct mode, cpu pct mode)`
  — pct mode expected on the live box (FanCalibration.json exists). If it says
  `raw pwm`, the calibration path is wrong — STOP and fix promise.env.
- `temp=..C (Core 0, ...)` — a sane CPU temperature from coretemp.
- blower/cpu `pwm=` values ≥ 20 and plausible vs what the Python driver
  currently commands (compare `docker logs promise-hwmon` or the web UI).

Sweep the curve at forced temperatures (still zero writes):

```bash
for T in 45 65 75 85 95; do /opt/promise/bin/promise-encld --dry-run --once --temp $T; done
```

Expect monotonically rising PWM, 255 at ≥85 °C (emergency), never <20.

## 2. Validate: `--once` (ONE real control cycle, then a safe handoff)

Stop the Python driver FIRST so two writers never overlap, then run one cycle:

```bash
docker stop promise-hwmon        # container install (compose)
# or: systemctl stop promise-hwmon.service
/opt/promise/bin/promise-encld --once
```

`--once` writes the computed PWM to both zones + LEDs, prints the same
decision line, then performs the safe exit: blower re-commanded to the
conservative built-in hold (≥ the calibrated value; ≥40) and CPU fan handed
back to hardware-auto (`pwm2_enable=2`). Verify:

```bash
sensors | grep -iA2 fan                          # blower + cpu fan still spinning
cat /sys/class/hwmon/hwmon*/pwm2_enable 2>/dev/null   # nct6776 back to 2
```

Panel: Power green, Blower green, System green; HDD/RAID/net LEDs off
(unmanaged by encld — expected).

## 3. Cutover

```bash
docker update --restart=no promise-hwmon && docker stop promise-hwmon
# (systemd install instead: systemctl disable --now promise-hwmon.service)
systemctl enable --now promise-encld
systemctl status promise-encld
journalctl -u promise-encld -f    # startup banner, then lines only on PWM/fault changes
```

## 4. Verify (first 15 minutes)

- `journalctl -u promise-encld` — no `write failed` / `FAULT` lines.
- Fan RPMs steady and sane: `sensors` (or the blower rpm in the encld log line).
- Load test: `stress-ng --cpu $(nproc) --timeout 120` and watch PWM step up
  through the curve bands, then settle back down (EMA-smoothed, 3% hysteresis).
- Stop/start behaves safely: `systemctl stop promise-encld` → log shows
  `exit - blower held at pwm=..` and `pwm2_enable restored to 2`; fans keep
  running. `systemctl start promise-encld` resumes control.

## 5. Rollback (any time)

```bash
systemctl disable --now promise-encld
docker update --restart=unless-stopped promise-hwmon && docker start promise-hwmon
# (or: systemctl enable --now promise-hwmon.service)
```

The Python driver re-initialises the NCT7802Y and reclaims hwmon pwm2 on its
first tick — no other cleanup needed.

## 6. Later: removing the Python driver

Only after days of clean encld running. Keep the config/calibration files —
encld reads them in place:

```bash
docker rm -f promise-hwmon
# KEEP /var/lib/promise-hwmon/{config,data} (fanconfig.json, FanCalibration.json)
```

If you ever delete `/var/lib/promise-hwmon` entirely, encld falls back to the
built-in DEFAULT_FANCONFIG raw-PWM curves — safe (they run the fans harder
than the calibrated curves), just louder.

## Notes / limits

- encld intentionally does not implement: web UI/API, HDD bay + RAID + USB +
  network LED logic, PC-speaker alarms, drive health (HD Sentinel), sysmon
  checks, cpufreq limits, or the blower sudden-drop (30%) alarm — the tach
  grace check (<500 RPM for 60 s → Blower/System red) is the fan-health core.
  If any of that is still wanted, keep the Python stack for it and run encld
  only for fans/LEDs — but never both fan writers at once.
- fanconfig.json/FanCalibration.json are read once at startup (the Python
  driver re-read every tick). After editing curves: `systemctl restart
  promise-encld`.
- Band lookup truncates the smoothed temp to whole °C, so integer band edges
  (…59 | 60…) can't fall in a gap; fanctl.py would briefly command the top
  band's PWM for e.g. 59.5 °C with the built-in default (gapped) curves. The
  live all-`Lower:0` fanconfig never hits this either way.
