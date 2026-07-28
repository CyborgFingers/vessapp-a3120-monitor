# User custom patches → native port integration

Two custom patches (from `~/Desktop/Promise Patches`, authored by the user) add
functionality on top of vendor WebPAM. Decompiled with ilspycmd; extracted source
staged in `reference/promise-patches/` (gitignored). Both are to be built **natively**
into the Linux port so the final product has this functionality.

## 1. Fan Control patch (`FanControlPatch_A3120_v1.1.exe`)

**What it is (Windows):** 3 PHP pages + a one-line `common.php` menu entry + a
`FanMonitoringService.exe` (reads CPU/SYS temps via OpenHardwareMonitor, applies
PWM curves from `fanconfig.json`) + `FanCalibration.exe`.

**Pages** (`reference/promise-patches/fan/`):
- `fancontrol.php` — status view (service state, current temps, CPU + Blower/System curves)
- `fancontrolt.php` — the interactive **curve editor** (18 KB; writes `fanconfig.json`)
- `fancalibrate.php` — fan calibration
- `common.php` menu line: `array('Fan Control', 'fancontrol.php', 'Fan Control Settings'),`

**Config format** = `{ CpuFanLevelList:[{Lower,Upper,Speedlevel}], SysFanLevelList:[…] }`
— **identical to what `promise-encld` already consumes**. So the .NET service is NOT
ported; the pages drive the existing daemon.

**Native port:**
- Copy the 3 pages into `port/web/htdocs/` (they use the vendor `tree.php`/`common.php`
  helpers + `getServer()->getSubsystem()->getEnclosures()` which the port's `php_i2`
  already provides).
- Repoint Windows paths → Linux: config `C:\PromiseApp\clitest\fanconfig.json` →
  **`/etc/promise/fanconfig.json`** (the path `promise-encld` reads); service-status
  check (was `C:\ProgramData\Promise\FanMonitor.log` freshness) → **`promise-encld`**
  (`systemctl is-active promise-encld` / its status file).
- The editor's POST handler writes `/etc/promise/fanconfig.json`; `promise-encld` picks
  it up (add a reload path — SIGHUP or file-watch — if not already present).
- Add the `common.php` menu line.

## 2. Front Panel patch (`FrontPanelPatch_A3120_v1.0.exe`)

**What it is (Windows):** NO PHP. A service (`frontsvc`, `reference/promise-patches/
frontpanel/frontsvc.decompiled.cs`) that watches each NIC's
`NetworkInterface.OperationalStatus` and drives the **front-panel ETH LEDs via the
i2bdg enclosure LED API**, using a JSON NIC→LED map; plus a console config tool
(`frontcfg`) that enumerates NICs and writes the map.

**Native port** (reimplement the logic — no binary to reuse):
- **Monitor**: a small Linux component (fold into `promise-encld` or a sibling) that
  reads each NIC's link state from `/sys/class/net/<if>/operstate` (+ carrier) and sets
  the corresponding front-panel **network-panel LED** — via the port's existing SMBus
  path (PCA9555 @ `0x21` NetworkPanel in `smbus_encl.c`) or the SG_IO `setLED` (`0x130F`).
- **Config UI**: the Windows patch used a WinForms tool; give the Linux port a **native
  `frontpanel.php`** page (list NICs ↔ front-panel network LEDs, editable) writing a
  JSON map the monitor reads, + a `common.php` menu line. This is the "native pages"
  the user asked for.

## Sequencing

Build these **after** the in-flight 1U4BAY correction lands (it edits `htdocs` +
`php_i2` + `common.php`), so the patch pages sit on the corrected 4-bay base and the two
efforts don't collide. Validate via `port/packaging/webpam-trixie-smoke.sh` (add the new
pages to its page list) + `port/build-in-container.sh`.

## Status: IMPLEMENTED (on the 1U4BAY-corrected base)

- **Fan Control**: `fancontrol.php` / `fancontrolt.php` / `fancalibrate.php` +
  shared `fanutil.php` in `port/web/htdocs/`; config = `PROMISE_FANCONFIG`
  (install-webpam points it at `/etc/promise/fanconfig.json` and seeds the
  file); service check = promise-encld via the systemd invocation link;
  calibration display/mapping uses the promise-hwmon `FanCalibration.json`
  PwmRpmMap with the daemon's nearest-RPM rule. Saved Speedlevels follow encld
  semantics (percent when the zone is calibrated, raw PWM otherwise).
  `promise-encld` hot-reloads fanconfig.json on mtime change.
- **Front Panel**: `frontpanel.php` assigns a NIC to each of the two wired
  network-panel LEDs (ETH1 0x12 / ETH2 0x13 - the chassis wires two, exactly
  what the Windows patch drove) writing `/etc/promise/frontpanel.json`; the
  monitor is folded into `promise-encld` (`src/encld/encld_netled.c`):
  operstate/carrier -> PCA9555 @0x21 via `smbus_led_write`, frontsvc modes
  (0 blink / 1 solid / 2 off; blink renders steady on the SMBus path),
  write-on-change, LEDs off on daemon exit, map hot-reloaded.
- Both tabs live in `tabTitleEncl` (`common.php`); the trixie smoke asserts
  the pages render and the tab bar carries them; `test_encld` covers the
  netled module. The Windows RecLed-follows-a-service feature was NOT ported
  (out of the asked scope - no page/monitor hook for it yet).
