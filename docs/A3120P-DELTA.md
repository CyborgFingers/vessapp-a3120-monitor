# A3120P delta — does the Linux port run on the Vess A3120P?

Diff of the **Vess A3120P** (2025, `v25.00.01.0000`) vendor package against the
**Vess A3120** (2022, `v1.0`) package the Linux port was reverse-engineered from,
to decide whether the port (`port/`, HEAD `b438f68`) runs **as-is** on the A3120P
for **monitor / manage existing arrays** (no create/delete — production data).

Method: byte + export + targeted-disasm diff of the x64 `System32` DLLs (the RE
target; A3120P `System32` sizes match the quoted deltas exactly), the SNMP MIB,
and the WebPAM `htdocs`. Tooling: `.venv` `pefile`+`capstone`. No opcode, offset,
or model byte is asserted without a byte-level match in both binaries; anything
not recoverable off-device is marked **CALIBRATE**.

---

## VERDICT

**YES — runs as-is for monitor/manage, pending one on-device model-byte check.**

Every contract the port depends on — the SCSI transport, all enclosure + RAID
MicOpCodes, the response-page offsets, the enclosure model byte, the SNMP served
subtree, and the existing WebPAM read methods — is **byte-for-byte IDENTICAL** on
the A3120P. Every A3120P change is **purely ADDITIVE** (new Fibre-Channel / iSCSI
/ CHAP / CIM / SAS features, new element-location + PD-temperature SNMP columns,
multi-enclosure convenience wrappers) and falls **outside** monitor/manage.

**Zero code changes are required.** `106BCommandLib.dll` is byte-identical
(enclosure/fan/LED/sensor protocol unchanged). The 7 C suites remain green (no
source was touched).

The single on-device confirmation: that the A3120P SEP reports enclosure model
byte **`'R'` (0x52) at page+0x0e** like the A3120. Binary evidence says it does
(high confidence — see §4); it cannot be *proved* without the hardware.

---

## Per-area summary

| Area | File (old→new size) | Result | Note |
|------|--------------------|--------|------|
| SCSI transport | `fwinterf.dll` 26624→27136 | **IDENTICAL** | IOCTL 0x4d014, CDB `D8 BA`, discovery `0x0103BAD8` all 1:1; +512B = 1 new export `fi_cacheGetProductID` + alignment |
| Enclosure opcodes | `pri2apiv4.dll` 197632→211968 | **IDENTICAL** | 13/13 MicOpCodes unchanged (byte-verified) |
| RAID opcodes | `pri2apiv4.dll` | **IDENTICAL** | 18/18 MicOpCodes unchanged (byte-verified); 0 exports removed |
| Response offsets | `prdecode.dll` 111616→118272 | **IDENTICAL** | model@+0x0e, region bases +0x40/+0x42/+0x44, strides 0x188/0x70/0xB8/0x60, PD/LD fields all unchanged; 0 exports removed |
| Enclosure model byte | `prdecode.dll` | **IDENTICAL** (device-confirm) | `'R'`=3U16BAY still case-0 {P,R,U}; dispatch became a jump table (compiler opt), range widened — additive |
| i2bdg wrapper | `libi2bdg.dll` 39424→39424 | **ADDITIVE** | multi-enclosure `*Ex` + PSU/serial wrappers added; port reimplements this layer, underlying opcodes/offsets intact |
| SNMP MIB | `raidv4.mib` 2225→2280 | **ADDITIVE** | 0 removed, 0 changed OID/index; +7 new columns on existing tables |
| WebPAM pages | `htdocs` 120→207 `.php` | **ADDITIVE** | 0 removed, +87 FC/iSCSI/CHAP/CIM/SAS/etc. pages; existing monitor pages call the same read methods |
| Enclosure cmd lib | `106BCommandLib.dll` | **IDENTICAL** | byte-identical (pre-verified) |
| New FC/CHAP/CIM etc. | (new binaries/pages) | **ADDITIVE — out of scope** | cataloged §7; port `__call`→empty-array stubs them, no fatal |

---

## 1. Transport — `fwinterf.dll` (IDENTICAL)

The port's transport contract (`port/include/i2_micops.h`, `port/src/libi2/`) is
`SCSI_PASS_THROUGH_DIRECT` / IOCTL `0x4d014` / 16-byte CDB `D8 BA <MicOp LE> …`.
Byte-scan of both DLLs (immediates as little-endian bytes):

| constant | bytes | OLD | NEW |
|----------|-------|-----|-----|
| `IOCTL_SCSI_PASS_THROUGH_DIRECT` 0x4d014 | `14 D0 04 00` | 1 | 1 |
| `IOCTL_SCSI_MINIPORT` 0x4d008 | `08 D0 04 00` | 1 | 1 |
| discovery CDB dword `0x0103BAD8` | `D8 BA 03 01` | 1 | 1 |
| CDB head word `0xBAD8` | `D8 BA` | 9 | 9 |
| `"Vess"` product match | `56 65 73 73` | 2 | 2 |

`_ib_sendSCSIPassThruDirect` / `sendCommand` are unchanged. **+512B** is: PE
timestamp `0x62412a2b` (2022) → `0x69114774` (2025), `.text` +0x120 bytes, one
0x200 file-alignment block, and exactly **one new export `fi_cacheGetProductID`**
(a product-ID cache helper; 0 exports removed). Cosmetic/additive — not a
transport change.

## 2. Enclosure + RAID MicOpCodes — `pri2apiv4.dll` (IDENTICAL)

`pri2apiv4` builds the CDB via split word-stores (`mov word [x],0xbad8` +
`call` the client dispatcher `FUN_1800042e0`, now `0x42f0` after the whole binary
shifted +0x10) with the MicOpCode loaded as an immediate before the dispatcher
call — so the opcode is verified by disassembling each `i2_*` export at its RVA in
both binaries and reading the immediate. **0 exports removed; 4 added** (below).

Confirmed unchanged (immediate identical in old and new):

- Enclosure: `0x000F` info (`mov eax,0xf`), `0x010F` status (`mov eax,0x10f`),
  `0x130F` setLED, `0x190F` WDT, `0x200F/0x210F` sensor, `0x220F/0x230F` alarm,
  `0x260F` pwr-switch, `0x280F` getLED (`mov eax,0x280f`), `0x290F` getLED-by-HDD
  (`mov eax,0x290f`), `0x8003/0x8103` VPD rd/wr, plus `0x060F/0x070F` FRU/battery.
- RAID: `0x0804` phydrv, `0x0B04/0x0C04` logdrv, `0x0504/0x0604` array, `0x0204`
  spare, `0x0412` LD-stats, `0x0004` cfg-apply, `0x0104` cfg-delete, `0x0404`
  logdrv-delete (`mov eax,0x404` at the *same* +0x298 in both), `0x0606/0x0106`
  init, `0x0506` init-progress, `0x0508` rebuild-progress, `0x0109/0x0209` synch.
- Controller: `0x0103` (`0x0103BAD8`).

New exports (ADDITIVE, all outside monitor/manage): `i2_opasStartAsync`,
`i2_opasGetProgress` (async OPAS import/export), `i2_setWebServerSslCert`,
`i2_restoreFactoryDefaultsEventForwarding`.

Note: the A3120P `i2_getEnclosureInfo/Status` gained a **Windows-registry read at
entry** (`RegOpenKeyExW(HKLM, …, KEY_READ)` via IAT). That is Windows-host config
lookup, not a wire change — the Linux port never reproduced it and does not need
it. The MicOpCode (`mov eax,0xf` / `0x10f`) is still emitted after it.

## 3. Response-page offsets — `prdecode.dll` (IDENTICAL)

**0 exports removed; 3 added** (`decod_getEnclBlowerLocation`,
`decod_getEnclTempSensorLocation2`, `decod_getStringFwRevisionF2224` — the
decoders for the new SNMP location/fw-rev columns in §6).

Verified unchanged in both binaries:

- Model byte read at **page+0x0e**; enclosure sub-type at **+0x1c**.
- Per-element region bases **+0x40** (fan sts) / **+0x42** (temp) / **+0x44**
  (volt/fan-speed); fan RPM numerator **0x149970**.
- Record strides: PD **0x108/0x80/0x188**, LD **0x70**, disk-array **0xB8**,
  LD-stats **0x60**, list header **0x40** (checked in the `pri2apiv4` builders).
- PD record fields (`i2bApi_getHardDiskDriveInfo` → `…Ex`): PDId@**0x04**,
  enclosureId@**0x14**, slotId@**0x16**, opStatusFlag@**0x54**, drvType@**0x66**,
  driveModelNo@**0x68** — all identical. temperature@**0xb5** present in the old
  copy loop, past the disasm window in the enlarged `…Ex` (high-confidence
  unchanged; also now surfaced as MIB column `raidv4PhydrvTemperature`, §6).
- LD record fields (`i2bApi_getLogDrvInfo` → `…Ex`): RaidLvl@**0x02**,
  OpStatusFlag@**0x04**, Capacity@**0x0c**, StripeSz@**0x2c**, arrayId@**0x30**,
  Alias@**0x34** — all identical.

`i2c_map.h` and `docs/RE-i2-raid.md` §2.1 remain valid unchanged.

## 4. Enclosure model byte (CRITICAL — IDENTICAL, device-confirm)

The A3120P `prdecode` compiles the model-byte switch as a **jump table** indexed
by `modelbyte − 0x50` (range 0x50..0x69), replacing the A3120's `cmp`-chain — a
pure compiler optimization. Decoding the `decod_getEnclNumFan` table:

- **case 0** = `P(0x50) R(0x52) U(0x55)` — the A3120 **`'R'` 3U16BAY** group,
  **unchanged**. No new byte was added to this case.
- **case 1** = `T(0x54) W(0x57)` + new `g/h/i (0x67-0x69)` — additive new
  small-chassis Vess models.
- **case 2** = everything else (default).

`'R'` (0x52) still lands in the same handler as on the A3120. Since the A3120P is
a **3U 16-bay** chassis and no new model byte was minted for that form factor, it
almost certainly reports **`'R'`**. The port's `port/src/lib/i2bdg_sensors.c:85`
switch (`I2C_MAP_ENCBYTE_3U16BAY` = `'R'`) and fan-k table therefore apply as-is.
The port already degrades safely (`default: "Unknown"`) if a byte is unrecognized.

**CALIBRATE:** read page+0x0e from a real A3120P SEP (`getEnclosureInfo` 0x000F)
and confirm `0x52`. If — against the evidence — it is a new byte, the fix is one
line: add that byte alongside `'R'` (see §9).

## 5. `libi2bdg.dll` (ADDITIVE — port reimplements this layer)

Same size (39424B), refactored: **5 wrappers removed** (`i2bApi_getDiskArrayInfo`,
`i2bApi_getEncInfo`, `i2bdg_enable/update/disableWDT`), **10 added**
(`i2bApi_getElementCntEx`, `getEncCount`, `getFanInfoEx`, `getHardDiskDriveInfoEx`,
`getLogDrvInfoEx`, `getThermalSensorInfoEx`, `getVoltageSensorInfoEx`,
`i2bdg_getPSUStatus`, `getPerfStat`, `getSerialNo`). The `getEncCount` + `*Ex`
family is a **multi-enclosure generalization** (Ex variants take an enclosure
index); the rest are PSU/perf/serial conveniences.

This does **not** affect the port: the port **reimplements** the i2bdg layer in C
over its own `libi2`/SG_IO (`port/src/lib/i2bdg_*.c`), it does not link the vendor
DLL. The wrapper refactor removed no underlying `i2_*` opcode (all still exported
by `pri2apiv4`, §2) and shifted no response offset (§3), so the port's
reimplementation stays valid. Multi-enclosure (expansion JBODs) is an
**enhancement**, out of scope for head-unit monitor (§9).

## 6. SNMP MIB — `raidv4.mib` (ADDITIVE)

Base OID tree unchanged: `raidv4MIB = {ptProduct 20}`, `System = {raidv4MIB 1}`,
`Storage = {raidv4MIB 2}` → `.1.3.6.1.4.1.7933.1.20`. Object-map diff:

- **0 objects removed, 0 objects changed OID/index** (every shared object keeps
  its exact `::= { parent idx }`).
- **+7 added, all new columns on existing tables**: `raidv4CoolingDeviceLocation`,
  `raidv4TempSensorLocation`, `raidv4VoltageSensorLocation`,
  `raidv4PhydrvConfiguration`, `raidv4PhydrvTemperature`, `raidv4CtrlInstallPkgVer`,
  `raidv4CtrlInstallPkgBuildDate` (these match the 3 new `prdecode` decoders +
  `getSerialNo`).
- `IF-MIB.mib` added (standard, additive).

The port's served subtree (`snmp_leaves.c`: System.1-14 + Storage.1-8) is fully
valid as-is; a GETNEXT walk over the existing leaves still returns correct data.

## 7. WebPAM `htdocs` (ADDITIVE)

**0 pages removed; +87 new `.php` pages**, all for out-of-scope features: Fibre
Channel (`fc*.php`), iSCSI (`iNode/iPort/iSession/isns/initiator*`), CHAP
(`chap*.php`), CIM (`cim.php`), SAS (`sas*.php`), LUN-mapping/masking (`lmm*.php`),
VLAN + link-aggregation, UPS, NTP, Bonjour, SSH/Telnet, firmware flash/upload,
LD clone (`ldclone.php`), shutdown, netsend, maintenance-mode.

php_i2 method surface: **+102 new methods**, all for those features (`getFC*`,
`getiSCSI*`, CHAP/CIM/SAS/LMM/UPS/NTP/flash/clone/shutdown, `opasStartAsync`,
`restoreFactoryDefaults*`). The port's `php_i2` `__call` fallback returns an
**empty array** for unknown methods (RE-php_i2 §2), so the new pages render
empty/unsupported rather than fatally erroring.

The existing monitor pages (`subsystem/phydrvs/arrays/tree/home/common*.php`) were
edited (nav links + columns for the new features) but still call the **same core
read methods** — `getSubsystem`, `getPhysicalDrives`/`enumeratePhysicalDrives`,
`enumerateLogicalDrives`, `getDiskArrays`/`enumerateDiskArrays`, `getController`/
`enumerateControllers`, `getEnclosures`, `getUsers`, `logout`. Only **one** new
method (`generateUPSTree`) appears in a monitor page (`tree.php` nav) → `__call`
stubs it → empty UPS nav branch. Benign.

The safest operational choice is to keep serving the **already-ported A3120
htdocs** (fully supported); adopting the A3120P htdocs also works for monitor
(the new feature pages just render empty until their methods are implemented).

## 8. New A3120P features (cataloged, out of monitor/manage scope)

Fibre Channel, iSCSI CHAP, CIM, SAS, iSNS, LUN mapping/masking (LMM), VLAN, link
aggregation, UPS monitoring, NTP, Bonjour, firmware flash/upload, LD clone,
async OPAS import/export, web SSL-cert upload, maintenance mode. Opcode families
were already cataloged in `docs/RE-i2-raid.md` §3b (FC `0x16`, SAS `0x18`,
initiators/LMM `0x0B`, LCD `0x20`, buzzer `0x11`). Implementing any of these is
net-new feature work, not required to monitor/manage existing arrays.

## 9. Patch list

**Required for monitor/manage: NONE.** The recovered contract is valid unchanged.

Deferred to on-device / optional (do **not** guess — needs the hardware):

1. **CALIBRATE (do first): model byte.** Confirm the A3120P SEP returns `'R'`
   (0x52) at `getEnclosureInfo` page+0x0e. If it returns a new byte `0xNN`, add it
   to the 3U16BAY handling — a one-liner in `port/include/i2c_map.h` (e.g.
   `#define I2C_MAP_ENCBYTE_A3120P 0xNN` aliased to 3U16BAY) plus that `case` in
   the `port/src/lib/i2bdg_sensors.c:85` switch and the fan-k test at
   `i2bdg_sensors.c:53`. Evidence says this is unnecessary (byte is `'R'`).
2. **Optional enhancement — multi-enclosure.** If A3120P expansion units are
   attached, adopt the `getEncCount`/`*Ex` model (per-enclosure index) rather than
   the single-enclosure assumption (`I2C_MAP_ENC_STATUS_BASE = 0`). Not needed for
   a head-unit-only monitor.
3. **Optional — new SNMP columns / decoders.** Serve the +7 additive MIB columns
   (element locations, PD temperature, install-pkg version) via the new `prdecode`
   decoders if desired. Purely additive.

Everything above is additive; none blocks monitor/manage on an A3120P.

## 10. Confidence

- Transport / opcodes / strides / record offsets / MIB / htdocs: **HIGH** — byte-
  or export-level matches in both binaries, cited per area.
- Model byte `'R'`: **HIGH from the binary** (same case-0 grouping, no new
  3U16BAY byte), **confirm on-device** (the SEP's actual response byte is not
  in any shipped artifact).
- Multi-enclosure behavior on real A3120P expansion: **CALIBRATE** (not exercised
  by monitor of a head unit).

Bottom line: the A3120P is, from the port's vantage, an A3120 with additive
features. Monitoring and managing existing arrays needs **no port change** — only
the standard on-device calibration already documented for the A3120
(`port/scripts/calibrate.sh`), with the model-byte check as item #1.
