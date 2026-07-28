# VessApp / WebPAM → Linux port

Full-parity port of Promise's **VessApp** (WebPAM PROe) management stack for the
**Vess A3120** from Windows to Linux. Goal: everything works the same on Linux.

Source of truth = the decompiled Windows package in `../windows-src/` (extracted
from `vessapp windows channel update package 20220328-1.exe`, a 7-Zip SFX).

## What the package actually is

Not a kernel driver — a complete on-box RAID/enclosure **management appliance**
that Promise shipped to run on Windows *on the A3120 itself* (XAMPP + services +
I²C libs, installed under `C:\PromiseApp`). Components:

| # | Layer | Windows artifact(s) | Talks to | Linux target |
|---|-------|--------------------|----------|--------------|
| 1 | **Enclosure HW** — fans, LEDs, front panel, buzzer, temp/volt sensors, watchdog, VPD | `libi2bdg.dll` + `i2bApi_x64/i2bdg_api.h` (+ sample `.c`) | **in-band SCSI-passthru** → RAID ctrl FW → SEP (NOT raw I²C — see §Phase-1 finding) | `libi2bdg` → `/dev/sg*` `SG_IO` (rides the Phase-2 substrate) |
| 2 | **RAID/array core** — arrays, phys/log drives, RAID create/migrate/rebuild, iSCSI/FC/SAS, users, net, sessions, events | `clitest/i2.exe`, `pri2apiv4.dll`, `106BCommandLib.dll`, `fwinterf.dll` | RAID controller **FW-IOCTL** | `i2d` daemon |
| 3 | **API/IPC service** (OPAS) | `opas/opas.exe`, `opas/opassvc.exe`, `htdocs/api/opas.php` | IPC (shared mem + sockets) to #2 | `opasd` daemon |
| 4 | **Web UI** (WebPAM PROe) | ~130 PHP in `WebServer/htdocs/` + `oem/sw/gui/` | HTTP → #3 | Apache/nginx + PHP-FPM |
| 5 | **SNMP** | net-snmp (`usr/bin/snmp*.exe`) + `prsnmpagent.dll` + Promise MIB | AgentX → #2 | Linux net-snmp + AgentX subagent |
| 6 | **Helper daemons** | `emaild.exe` (alerts), `bgasched.exe` (bg-activity sched), `pwrmon.exe` (power), `prevtlogd` (event log), SLP (`slp.dll`) | #2 / SMTP / network | port each |

Support libs: `prconfig/prdefaults/prcommon/prswmgt/prparseXML/libprxml`
(config + XML mgmt protocol), OpenSSL 1.1, embedded **Python 3.3** (`python33.dll`
— locate its consumer during Phase 2).

## The I²C API surface (from `i2bdg_api.h` — already documented by Promise)

- `i2bApi_getElementCnt` → counts (fans, temp/volt sensors, batteries, HD slots…)
- `i2bApi_getEncInfo`, `getFanInfo` (RPM+status), `getThermalSensorInfo`,
  `getVoltageSensorInfo`, `getHardDiskDriveInfo`, `getLogDrvInfo`,
  `getDiskArrayInfo`, `getStatsLogDrv`
- LEDs: `i2bdg_get/setLED(id,color,cause)`, `get/setRecordLED`, `get/setSysErrorLED`
  - IDs: FAN1/2, RAID_STS, USB1/2, RECORD, SYSTEM, GLOBAL_HDD_STS, ETH1-4, PWR,
    HeartBeat, HDD_SLOT1-16 (0x80-0x8F)
  - Colors/states: OFF/GREEN/RED/AMBER + BLINK variants
- Alarm/buzzer: `i2bdg_get/setTermAlarmState`; sensors: `getTermSensorState`
- Watchdog: `i2bdg_enableWDT/updateWDT/disableWDT`
- VPD: `i2bdg_get/setMBVPD`, `get/setBPVPD` (motherboard / backplane)
- SMART passthrough: `i2bdg_SMARTReadData/Selftest/ErrorLog/Status`

**Gap:** the header gives the API, not the wire protocol. The I²C bus number,
slave address(es), and per-call register/opcode sequences live *inside*
`libi2bdg.dll` and must be recovered by decompilation (Phase 1).

## Two hard gates (designed around, not hidden)

1. **Reverse-engineering.** #1 (`libi2bdg.dll`) and #2 (`pri2apiv4.dll` FW-IOCTL)
   have undocumented wire protocols. Recover via Ghidra/radare2 decompilation.
2. **Hardware calibration.** Exact I²C addresses can only be *confirmed* on the
   real A3120 running Linux (`i2cdetect -y N`, `i2cdump`). Build behind a mockable
   HAL so it compiles + unit-tests off-device; mark every RE-derived constant as a
   calibration point the operator verifies on the box.

## Phase-1 finding (CONFIRMED) — corrects the transport assumption

The enclosure is **not** on a raw OS-level I²C node. `libi2bdg` → `pri2apiv4`
(loopback TCP 127.0.0.1 + shared-mem IPC) → `fwinterf` opens `\\.\scsi%d:` and
issues **`SCSI_PASS_THROUGH_DIRECT` with a Promise `MicOpCode`** to the RAID
controller FW, which drives the SEP (enclosure processor) over its own sideband.
The literal I²C bus/addr/registers live in controller firmware — in no shipped
binary. **Linux equivalent of the whole path = `/dev/sg*` + `SG_IO`** (what
`sg3_utils` does). So enclosure + RAID share ONE Linux transport substrate; the
Phase-1 `/dev/i2c` HAL is a faithful stand-in whose real backend is built in
Phase 2. See `RE-libi2bdg.md`.

## Assumptions (correct if wrong)

- The Linux port runs **on the A3120** (x86-64) running a stock Linux distro; the
  Promise RAID controller enumerates as `/dev/sg*` and is reached via `SG_IO`.
- **Possible shortcut:** if the SEP is a standard **SES-2** device, `sg_ses`
  (sg3_utils) drives fans/LEDs with no proprietary RE. Probe on-device
  (`sg_ses /dev/sgN`) before committing to full MicOpCode RE.
- Storage RAID may be Intel RST/VROC (`iastorav.inf`, `raid_driver` in the package)
  and/or Promise FW — determine in Phase 2; Linux side is `mdadm --imsm` vs. a
  Promise FW-IOCTL shim.

## Phased plan (each phase has an off-device verify gate)

**Phase 1 — Enclosure HAL (`libi2bdg` on Linux). ✅ DONE + verified.** Recovered
the full API→`i2_*` contract (LED/WDT/alarm/VPD/enclosure semantics, high
confidence; raw-I²C bytes flagged UNRESOLVED — in firmware). Drop-in `libi2bdg`
(all 36 exports) + mockable HAL + 14 CLI tools + TDD; 4/4 tests green in amd64
container; `calibrate.sh` emitted. The real backend (SG_IO) is wired in Phase 2.

**Phase 2 — In-band substrate (`/dev/sg*` SG_IO + Promise MicOpCode) + `i2_*`
marshalling.** The true foundation for everything. RE `fwinterf.dll`
(`_ib_sendSCSIPassThruDirect`: the SCSI_PASS_THROUGH_DIRECT struct, CDB/MicOpCode
format, `_ib_discover_hba`) + `pri2apiv4.dll` (`i2_*` request/response, FW-IOCTL
struct) + `prdecode.dll` decode of enclosure pages, using `i2.exe` as the richest
reference. Build Linux `libi2` (SG_IO transport + `i2_*` marshalling) and **prove
it by giving Phase-1 `libi2bdg` its real backend** — enclosure read + LED set
end-to-end. First on-device step: probe for **standard SES-2** (may sidestep
MicOpCode entirely for fans/LEDs). *Verify:* container build + unit tests against
recorded SG_IO fixtures; on-device enumeration matches vendor sample output.

**Phase 3 — OPAS API/IPC (`opasd`).** RE the opas IPC contract (shared-mem +
socket, session mgmt) between `htdocs/api/opas.php` and `i2.exe`. Reimplement so
the existing PHP talks to `opasd` unchanged. *Verify:* `api/login.php` +
`api/opas.php` round-trip against `opasd` returns expected XML/JSON.

**Phase 4 — Web tier.** Stand up Apache/nginx + PHP-FPM serving `htdocs/`; fix
Windows-isms (paths, XAMPP assumptions). *Verify:* WebPAM loads, login works,
enclosure/fan/LED/array pages render live data via OPAS.

**Phase 5 — SNMP + helper daemons. ✅ DONE + verified.** Recovered the
`raidv4.mib` OID→`i2_*` mapping + traps from `prsnmpagent.dll` (import table +
`.rdata`). SNMP served as a net-snmp **`pass_persist`** handler over the same
libi2bdg substrate (not a linked AgentX module) — `snmpwalk` of
`.1.3.6.1.4.1.7933.1.20` returns the full Promise tree with fixture values
(`Vess A3120`, array `DA7`); `raidv4TrapMessage` drive/array/enclosure faults
captured. `emaild` (event→SMTP) verified against a local sink with the recovered
body/config/trigger. `bgasched`/`pwrmon`/`prevtlogd` scaffolded (trivial paths
implemented, engine i2 opcodes CALIBRATE); SLP = OpenSLP `slpd` + `promise.reg`.
See `RE-snmp-daemons.md`. 7/7 C suites still green + `snmp-smoke`/`email-smoke`/
`daemon-smoke`.

**Phase 6 — Packaging + on-device bring-up. ✅ off-device half DONE + verified.**
Production web tier = **nginx + PHP-FPM** (the frameset needs concurrency that
`php -S` can't give; `daemon.php` polls every 2 s). Made multi-worker FPM
correct: the php_i2 dc/session store is **fork-shared** (`MAP_SHARED` mmap at
MINIT in the FPM master) and `getLastError()` was recovered + implemented
(daemon-frame path). systemd units grouped under `promise-webpam.target`;
idempotent `install.sh`/`uninstall.sh` with a guarded `--on-device /dev/sgN`
switch (fixture is the default). Verified: `port/packaging/fpm-smoke.sh` —
login under `pm.max_requests=1` (every request a fresh worker process) + 3
rounds of ALL frames concurrently, all 200/no-error/session-held; 7/7 C suites
still green. Browser check: `bash port/packaging/run-fpm.sh`. Deployment guide
+ the **Phase-6b on-device operator checklist**: `docs/PACKAGING.md`.

## Reference locations

- Decompiled Windows stack: `../windows-src/extracted/`, `../windows-src/rootfs/`
- I²C SDK + samples: `../windows-src/rootfs/PromiseApp/i2bApi_x64/`
- PHP web app: `../windows-src/rootfs/PromiseApp/WebServer/htdocs/`
- RE targets: `.../system32/{libi2bdg,pri2apiv4,fwinterf}.dll`,
  `clitest/i2.exe`, `opas/opas*.exe`
- New Linux code goes in `../port/`.
