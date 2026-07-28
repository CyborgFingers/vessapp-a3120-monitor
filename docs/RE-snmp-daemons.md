# RE — SNMP subagent + helper daemons (Phase 5)

Port of Promise's SNMP layer (`prsnmpagent.dll` + Promise RAID MIB) and the
helper daemons (`emaild`, `bgasched`, `pwrmon`, `prevtlogd`, SLP) to Linux.

**Provenance sources**
- MIB (OID tree + types, authoritative): `windows-src/rootfs/PromiseApp/docs/SNMP and Events/MIBs/raidv4.mib`
  (`PROMISE-RAIDV4-MIB`, `raidv4MIB ::= { ptProduct 20 }`, `ptProduct ::= { promiseTech 1 }`,
  `promiseTech ::= { enterprises 7933 }` → base OID **`.1.3.6.1.4.1.7933.1.20`**).
- Agent (OID→i2 mapping + traps): `windows-src/extracted/system32/prsnmpagent.dll`
  (x64, ImageBase `0x180000000`; PDB `...\vs2017\x64\Release\prsnmpagent.pdb`).
- Event catalogue (id→severity/description): `windows-src/rootfs/PromiseApp/clitest/event_EN.xml`.
- Daemons: `clitest/{emaild,bgasched,pwrmon,prevtlogd}.exe`, `slpreg.bat`, `slp.dll`, `htdocs/slp*.php`.
- Config: `windows-src/rootfs/usr/etc/snmp/snmpd.conf` + `snmptrapd.conf`.

Rule followed: **no invented OIDs / traps / engine fields**. OID + type come from
`raidv4.mib`; the i2 mapping from `prsnmpagent.dll`'s import table (below).
Fixture *values* are representative demo data (fixture mode, no hardware); gaps
are marked UNRESOLVED / CALIBRATE.

---

## 1. SNMP subagent

### 1.1 Architecture
`prsnmpagent.dll` is a Windows SNMP **extension agent** (exports
`SnmpExtensionInit/Query/Trap/Close`, imports `snmpapi.dll`/`mgmtapi.dll`) that
answers the Promise subtree by calling `pri2apiv4.dll`'s `i2_get*` engine API.

Linux replacement: **`port/src/snmpd/promise_snmp.c`**, a net-snmp
**`pass_persist`** handler. `snmpd.conf` routes the whole subtree to it:
```
pass_persist .1.3.6.1.4.1.7933.1.20  /usr/local/bin/promise_snmp
```
It serves the identical OID tree from the **same substrate** the AgentX subagent
used — the port's `i2bApi_*` (libi2bdg) are the drop-in for `pri2apiv4`'s
`i2_get*`. `pass_persist` avoids linking the net-snmp agent library and
re-registering 15 SMI tables; a GETNEXT walk over the sorted leaf table
(`snmp_leaves.c`) is a faithful `snmpwalk`. Read-only (`rocommunity VessApp`).

### 1.2 OID → i2 mapping (RECOVERED)
`prsnmpagent.dll` imports from `pri2apiv4.dll` (IAT RVAs are file offsets from
ImageBase `0x180000000`; dumped with `pefile`). Each MIB table maps to the
`i2_get*` that fills it, and to the port `i2bApi_*` that provides the value.

| MIB table · OID (rel. `.7933.1.20`) | raidv4 objects | prsnmpagent import (pri2apiv4) · IAT RVA | port substrate call |
|---|---|---|---|
| System.1 `InstanceTable` `.1.1` | `raidv4Instance*` (1–12) | `i2_getNumberOfHBA` `0xd4f0`, `i2_getNumberOfSubsystem` `0xd500`, `i2_getDateAndTime` `0xd548` | single subsys instance (fixture) |
| System.2 `SubsystemTable` `.1.2` | `raidv4Subsystem*` (1–8) | `i2_getSubsystemInfo` `0xd558` | fixture consts — offsets **UNRESOLVED** |
| System.3 `ControllerTable` `.1.3` | `raidv4Ctrl*` (1–62) | `i2_getControllerInfo` `0xd530`, `i2_getControllerSettings` `0xd538` | `i2_fw_cmd 0x0103` product@224 + counts computed from PD/LD/array reads |
| System.4 `ControllerStatsTable` `.1.4` | `raidv4CtrlStats*` | `i2_getStatsController` `0xd5c8` | available (not served in fixture) |
| System.10 `EnclosureTable` `.1.10` | `raidv4Enclosure*` (1–4) | `i2_getEnclosureInfo` `0xd510`, `i2_getEnclosureStatus` `0xd540` | `i2bApi_getEncInfo` |
| System.11 `CoolingDeviceTable` `.1.11` | `raidv4CoolingDevice*` (fans, 1–4) | `i2_getEnclosureStatus` `0xd540`, `i2_getEnclosureSettings` `0xd4f8` | `i2bApi_getElementCnt` + `getFanInfo` |
| System.12 `PowerSupplyTable` `.1.12` | `raidv4PowerSupply*` (1–2) | `i2_getEnclosureStatus` `0xd540` | count-driven; empty in demo fixture |
| System.13 `TemperatureSensorTable` `.1.13` | `raidv4TempSensor*` (1–3) | `i2_getEnclosureStatus` `0xd540` | `i2bApi_getThermalSensorInfo` |
| System.14 `VoltageSensorTable` `.1.14` | `raidv4VoltageSensor*` (1–2) | `i2_getEnclosureStatus` `0xd540` | `i2bApi_getVoltageSensorInfo` (BCD → "3.3") |
| Storage.1 `PhyDrvTable` `.2.1` | `raidv4Phydrv*` (1–20) | `i2_getPhyDrvInfo` `0xd5a0` | `i2bApi_getHardDiskDriveInfo` |
| Storage.4 `DiskArrayTable` `.2.4` | `raidv4Array*` (1–19) | `i2_getDiskArrayConfig` `0xd508` | `i2bApi_getDiskArrayInfo` |
| Storage.5 `LogDrvTable` `.2.5` | `raidv4Logdrv*` (1–16) | `i2_getLogDrvInfo` `0xd590` | `i2bApi_getLogDrvInfo` |
| Storage.6 `SpareDrvTable` `.2.6` | `raidv4Spare*` (1–10) | `i2_getAllSpareInfo` `0xd520` | available (no fixture spares) |
| Storage.7 `PhyDrvStatsTable` `.2.7` | `raidv4PhydrvStats*` | `i2_getStatsPhyDrv` `0xd4e8` | available |
| Storage.8 `LogDrvStatsTable` `.2.8` | `raidv4LogdrvStats*` (Counter64) | `i2_getStatsLogDrv` `0xd568` | `i2bApi_getStatsLogDrv` |
| — session — | (all reads gated) | `i2_login` `0xd5b8`, `i2_initialize` `0xd580`, `i2_getCurrentSessionInfo` `0xd578` | opasd session (Phase 3) |
| — trap source — | `raidv4TrapMessage` | `i2_getEvents` `0xd5d0` + `SnmpExtensionTrap` | `promise_trap` + `event_map` |

Other imports present but outside the served subtree: `i2_getBatteryInfo`
`0xd570` / `i2_getBatteryCapacity` `0xd528` (battery — surfaced only via traps),
`i2_getSASHostPortInfo` `0xd5c0`, `i2_getCliqueInfo` `0xd598`,
`i2_getFirmwareCapabilities` `0xd5b0`, `i2_getSnmpv3Config` `0xd5a8`,
`i2_getDiscoverPath` `0xd588`, `i2_getEventLocationStats` `0xd518`,
`i2_getFeatureBits` `0xd560`, `i2_logout` `0xd550`.

Encoded in code as the sorted leaf table in `port/src/snmpd/snmp_leaves.c`
(`snmp_build_leaves`). Table index arcs follow the MIB `INDEX` clauses exactly
(e.g. Cooling = `{InstanceIndex, EnclosureIndex, CoolingDeviceIndex}` → `.1.1.N`;
DiskArray = `{InstanceIndex, ArrayIndex}` → `.1.<DaId>`).

### 1.3 snmpwalk verification (fixture)
`port/src/snmpd/snmp-smoke.sh` boots `snmpd` + the subagent in a debian/amd64
container and `snmpwalk`s the subtree. Real fixture values asserted:
```
.1.3.6.1.4.1.7933.1.20.1.1.1.3.1  = STRING: "Promise Vess A3120"     (Instance description)
.1.3.6.1.4.1.7933.1.20.1.2.1.4.1  = STRING: "Vess A3120"             (Subsystem model)   ✔
.1.3.6.1.4.1.7933.1.20.1.2.1.3.1  = STRING: "Promise"                (Subsystem vendor)
.1.3.6.1.4.1.7933.1.20.1.3.1.5.1.1= STRING: "Vess A3120"             (Controller model, ctrl page@224)
.1.3.6.1.4.1.7933.1.20.2.4.1.2.1.7= STRING: "DA7"                    (Disk array alias)  ✔
.1.3.6.1.4.1.7933.1.20.2.4.1.2.1.9= STRING: "DA9"
.1.3.6.1.4.1.7933.1.20.2.1.1.4.1.1= STRING: "ST4000NM0023"           (PhyDrv model)
.1.3.6.1.4.1.7933.1.20.2.1.1.17.1.1=Counter64: 512000                (PhyDrv capacity)
.1.3.6.1.4.1.7933.1.20.2.5.1.2.1.0= STRING: "SystemVolume"           (LogDrv alias)
.1.3.6.1.4.1.7933.1.20.2.5.1.9.1.0= INTEGER: 5                       (LogDrv RAID5)
.1.3.6.1.4.1.7933.1.20.1.13.1.2.1.1=INTEGER: 34                      (Temp sensor °C)
.1.3.6.1.4.1.7933.1.20.1.11.1.4.1.1=INTEGER: <rpm>                   (Fan speed)
```
Result: **SNMP SMOKE OK** — model `Vess A3120` and array `DA7` served from the
substrate fixture.

### 1.4 Traps (RECOVERED)
`prsnmpagent.dll` `.rdata` shows the trap OIDs directly:
`.1.3.6.1.4.1.7933.1.20.0.10` (enterprise) + `specificTrap = <eventId>` and the
7 varbind OIDs `.0.10.1 … .0.10.7`. The MIB defines these as `raidv4Trap.1..7`
and the single SNMPv2 notification:

- **`raidv4TrapMessage`** = `raidv4TrapsV2.1` = **`.1.3.6.1.4.1.7933.1.20.0.11.0.1`**
- `OBJECTS { SequenceNo, Id, DefaultId, Severity, Source, TimeStamp, Description }`
  → varbinds, in order:

| varbind OID | object | type |
|---|---|---|
| `.0.10.1` | `raidv4TrapSequenceNo` | Integer32 |
| `.0.10.2` | `raidv4TrapId` (= eventId) | Integer32 |
| `.0.10.3` | `raidv4TrapDefaultId` (= eventId) | Integer32 |
| `.0.10.4` | `raidv4TrapSeverity` | INTEGER `{unknown0,other1,info2,warning3,minor4,major5,critical6,fatal7}` |
| `.0.10.5` | `raidv4TrapSource` | DisplayString |
| `.0.10.6` | `raidv4TrapTimeStamp` | DisplayString |
| `.0.10.7` | `raidv4TrapDescription` | DisplayString |

There is exactly **one** parameterised notification, so a drive / array /
enclosure fault is the *same* `raidv4TrapMessage` with different
`TrapId`/`Severity`/`Source`/`Description`. `port/src/snmpd/promise_trap.c`
builds it and execs net-snmp `snmptrap -v2c`. Key faults (ids/severity/desc from
`event_EN.xml`, in `event_map.c`):

| eventId | name | class | severity | serves as |
|---|---|---|---|---|
| `0x000D0002` | `PD_MARKED_DEAD` | physical_disk | major(5) | **drive fault** |
| `0x00090003` | `LD_OFFLINE` | logical_drive | major(5) | logical-drive fault |
| `0x00130006` | `DA_INCOMPLETE` | disk_array | warning(3) | **array fault** |
| `0x00050004` | `PSU_FAN_MALFUNCTIONING` | cooling_device | minor(4) | **enclosure/fan fault** |
| `0x00010002` | `BATTERY_CAPACITY_LOW` | battery | warning(3) | battery fault |

Smoke capture (UDP sink) of `promise_trap -e 0x000D0002 -s 'Enclosure 1 Slot 3'`:
a 282-byte trap PDU carrying `raidv4TrapSource="Enclosure 1 Slot 3"` and
`raidv4TrapDescription="Physical Disk is marked as DEAD"` — **verified**.

### 1.5 snmpd.conf (ported)
`port/src/snmpd/snmpd.conf` keeps the three original directives verbatim
(`syslocation`/`syscontact`/`rocommunity VessApp`) and replaces the Windows
AgentX subagent with the `pass_persist` line + a `trap2sink` template.

---

## 2. emaild — event → SMTP alert daemon (IMPLEMENTED)

Linux port: `port/src/emaild/` (`emaild.c` + reusable `smtp.c` + `emailcfg.c`,
reusing the shared `event_map`).

**Trigger (RECOVERED, `emaild.exe`/`emailutil.c` strings):** `"emaild starts"` →
`i2_initialize` + `i2_login` (`"login to i2 client for emaild"`); reads
`\emailsetting.xml` (`getEmailSetting()`); persists the last-processed sequence
in `emailseq.pid`; polls the engine event stream
(`emailutil_SendNotification()... get event SeqNo`), resolves each event against
`event_EN.xml`, and mails those passing a severity filter. Also supports a
**test email** (`"This is a test email, not a real event notification."`). Other
imports: `i2_getSubsystemInfo` (body alias/model/WWN), `i2_getAllUser`
(recipients), `i2_getControllerInfo`, `i2_getEvents`.

**Body (RECOVERED verbatim, `emailutil_generateNotifyBodyFixPart`):**
```
Dear <to>:

    Please check the following event.
    alias: <alias>
    model: <model>
    eventTime: <ts>
    eventID: 0x<id>H or <id>
    severity: <Info|Warning|Minor|Major|Critical|Fatal>
    source: <source>
    description: <event_EN.xml description>
```
Test body: `WWN / IP address / Alias / Model` under the test banner.

**Config** `emailsetting.xml` (**port-defined schema** — the Windows XML tags are
parsed via `libprxml` on-device and not in any shipped artifact; fields cover
what `emaild` needs): `server`, `port`, `sender`, `subjectPrefix`,
`severityThreshold` (raidv4 severity scale), repeated `recipient`. `auth`/TLS =
CALIBRATE (not implemented).

**Send:** Windows shelled to `mail-go.exe` (`%s -c "%s" -b "%s" -n "%s" -t "%s"`);
Linux speaks SMTP directly (`smtp.c`).

**Verification** `port/src/emaild/email-smoke.sh` runs `emaild` against a local
SMTP sink and captures the message — asserts the recovered event body,
`severity: Major`, `description: Physical Disk is marked as DEAD`, the
`0xd0002H` id format, the subject line, the test-mail body, and that a
below-threshold event is suppressed. **EMAIL SMOKE OK.**

**Fixture note:** subsystem identity in the body (`alias`/`model`/`WWN`) maps to
`i2_getSubsystemInfo` on-device; served here as fixture constants
(offsets UNRESOLVED). The event `source` is a per-event fixture string
(on-device it comes from the event record — CALIBRATE).

---

## 3. Catalog — bgasched / pwrmon / prevtlogd / SLP

Skeleton daemons (shared `port/src/daemons/daemon_util.c`: single-instance
pidlock + SIGTERM stop flag), each with a config and a runnable check
(`daemon-smoke.sh` → **DAEMON SMOKE OK**). Trivial paths implemented; deep i2
opcodes stubbed (CALIBRATE — the engine event-log / schedule / ShutdownHBA
MicOpCodes are not yet recovered).

| daemon | recovered behaviour (source) | Linux mapping | status |
|---|---|---|---|
| **prevtlogd** | event-log daemon: persists `prevtlogseq`, polls engine log (`_calculateSeqNum`, `logEvents()`), writes to Windows EventLog (`prevtlogmsg.dll`); single-instance | poll `i2_getEvents` → `syslog(3)` + event spool consumed by emaild/trap | **scaffolded**; syslog write path **implemented** (`--event <id>` resolves via `event_EN.xml`). Engine poll UNRESOLVED |
| **pwrmon** | "Promise Power Monitor Service"; on power loss `"Send ShutdownHBA command"`; `pwrmon.log`; `CreateEventA`/`SetEvent` signal | NUT `upsmon` NOTIFYCMD → `pwrmon --power-fail` → graceful controller shutdown | **scaffolded**; notify+log **implemented**; `i2 ShutdownHBA` op UNRESOLVED |
| **bgasched** | background-activity scheduler: `i2_login`, subscribes to events, `_getBGASchedules` keyed by `i2_getPowerOnNumber`; fires media-patrol/redundancy-check/rebuild/init; single-instance | scheduler daemon (systemd timer / internal loop) reading `bgasched.conf` | **scaffolded**; config load+log **implemented**; schedule read/apply ops UNRESOLVED |
| **SLP** | `slpreg.bat` runs `slpreg -once` via `slp.dll`; `slp.php` start/stop/scopes the SA; `slp.dll` strings: `service:service-agent`, `service:directory-agent`, `net.slp.useScopes` | OpenSLP `slpd` + `/etc/slp.reg` (`src/slp/promise.reg`) | **implemented** (registration file, well-formed). Exact Promise vendor **srvtype UNRESOLVED** — port registers WebPAM URL under a port-chosen type; confirm on-device with `slptool findsrvtypes` |

Systemd units (`.service` for snmpd override, emaild, prevtlogd, pwrmon,
bgasched, slpd) are Phase-6 packaging.

---

## 4. Status summary
- **SNMP subagent** — DONE + verified (`snmpwalk` of the full subtree; `Vess A3120`, `DA7`).
- **Traps** — DONE + verified (raidv4TrapMessage captured; drive/array/enclosure faults defined).
- **emaild** — DONE + verified (captured SMTP alert; recovered body/trigger/config).
- **prevtlogd / pwrmon / bgasched** — scaffolded + verified-runnable; engine i2 opcodes CALIBRATE.
- **SLP** — registration implemented; vendor srvtype UNRESOLVED.

### UNRESOLVED / CALIBRATE (on-device)
1. `i2_getSubsystemInfo` page offsets (subsystem model/serial/WWN) — fixture consts now.
2. Controller table beyond `ModelName`+counts (62 columns) — most CALIBRATE.
3. `i2_getEvents` / event-log MicOpCode — blocks the real prevtlogd/emaild/trap poll loop.
4. `i2 ShutdownHBA` MicOpCode (pwrmon) and `_getBGASchedules` (bgasched).
5. SLP Promise vendor srvtype string.
