# RE findings: RAID / array-management `i2_*` surface (Phase 2b)

Reverse engineering of Promise's in-band **RAID / array-management** path for the
**Vess A3120**, built on the Phase-2 substrate (`libi2` → 16-byte `D8 BA` vendor
CDB → `SG_IO`). This catalogs the `i2_*` storage-config family (physical drives,
logical drives, disk arrays, spares, LD statistics) and the RAID lifecycle
(create/delete/init/sync/rebuild).

Binaries (Ghidra 12.1.2 headless decompile in `re/out/`):
- `pri2apiv4.dll` (PE32+ x64) — the `i2_*` engine. `re/out/pri2apiv4_x64.c`.
- `prdecode.dll` — response-page decoders (`decod_*`). `re/out/prdecode_x64.c`.

Confidence: **high** = literal immediate/flow in the decompile; **med** =
decompiler stack recovery; **CALIBRATE** = not fully recoverable from the
binaries, confirm on the real A3120. **Never invent a write-path opcode.**

---

## 0. TL;DR

Same transport as Phase 2 (`docs/RE-fwinterf-pri2api.md`): `CDB[0]=0xD8`,
`CDB[1]=0xBA`, `CDB[2..3]=MicOpCode (LE u16)`, `CDB[10..11]=xferlen (BE)`.
`MicOpCode == (sub << 8) | family`, i.e. **family = CDB[2]**, **sub = CDB[3]**.
Every builder writes the family/sub as the dword `0x<sub><family>0000` (visible
as `CONCAT26(byteswap(id), 0x<sub><family>0000)` then `CONCAT62(.., 0xbad8)`).

The RAID surface adds these families to the Phase-2 map (0x0F enclosure, 0x03
controller): **0x04** storage-config, **0x06** LD-init/BGA, **0x08** rebuild,
**0x09** synchronize, **0x12** statistics, **0x02** subsystem.

For the RAID *element id* the builders put a **big-endian** id at `CDB[6..7]`
(`(ushort)id >> 8 ^ (ushort)id << 8` = byteswap). This is distinct from the
enclosure surface, which used `CDB[6]` as a single-byte element/FRU selector.

---

## 1. Opcode catalog

### Enumeration — family 0x04, READ (all HIGH confidence)

| `i2_*` fn | RVA | line | MicOp | family/sub | request CDB | rec size |
|-----------|-----|------|-------|-----------|-------------|----------|
| i2_getPhyDrvInfo      | 0x9420 | 6420 | **0x0804** | 04/08 | `[6..7]`=start PD id BE, `[8]`=type (0/1/3/0xff), `[10..11]`=len BE | 0x108/0x80/0x188 |
| i2_getLogDrvInfo (list)| 0xa020 | 7101 | **0x0B04** | 04/0B | `[6..7]`=start LD id BE, `[10..11]`=len BE | 0x70 |
| i2_getLogDrvInfo (one) | 0xa020 | 7101 | **0x0C04** | 04/0C | `[6..7]`=LD id BE | 0x70 |
| i2_getDiskArrayInfo (list)| 0xafd0 | 7864 | **0x0504** | 04/05 | `[6..7]`=start array id BE, `[10..11]`=len BE | 0xb8 |
| i2_getDiskArrayInfo (one)/Config | 0xafd0/0xa940 | 7864 | **0x0604** | 04/06 | `[6..7]`=array id BE | 0xb8 |
| i2_getSpareInfo       | 0xc530 | 8954 | **0x0204** | 04/02 | `[6..7]`=start spare id BE, `[10..11]`=len BE | — |

phydrv `CDB[8]` selects record verbosity (recovered branch in `i2_getPhyDrvInfo`
@ line 6469-6482): `0`→0x108-byte record, `1|3`→0x80, `0xff`→0x188. List pages
begin with a `0x40`-byte header, then `count` fixed-size records (the builder
computes `count = (buflen - 0x40) / recsize`).

### Lifecycle — WRITE (all HIGH confidence for the opcode)

| `i2_*` fn | RVA | line | MicOp | family/sub | dir | notes |
|-----------|-----|------|-------|-----------|-----|-------|
| create array / add LD / add spare | (commit) 0x6780 | 4262 | **0x0004** | 04/00 | WRITE | shared commit `FUN_180006780`; per-element **0x120** payload from `FUN_180006540`; `[10..11]`=payload len BE |
| i2_delDiskArray | 0xbc80 | 8521 | **0x0104** | 04/01 | WRITE | `[6..7]`=array id BE; loops the id list |
| i2_delSpare     | 0xca00 | 9211 | **0x0104** | 04/01 | WRITE | same op as array-delete (unconfigure PD) |
| i2_delLogDrv    | 0xc1a0 | 8782 | **0x0404** | 04/04 | WRITE | `[6..7]`=LD id BE |
| i2_startLogDrvInit (start) | 0xd3f0- | 9835 | **0x0606** | 06/06 | WRITE | initialize LD; `[6..7]`=LD id BE |
| i2_startLogDrvInit (stop)  | 0xd3f0- | 9835 | **0x0106** | 06/01 | WRITE | pause/stop init |
| i2_startLogDrvSynch (start)| 0xd900- | 10093 | **0x0109** | 09/01 | WRITE | redundancy check / sync |
| i2_startLogDrvSynch (stop) | 0xd900- | 10093 | **0x0209** | 09/02 | WRITE | stop / forced sync |

**Rebuild has no manual start op** in pri2apiv4 — only `i2_getRebuildProgress`
exists. Rebuild auto-starts on spare insertion / drive replacement (standard for
this controller). Same for **migration**: `i2_getMigrationProgress` exists but no
`i2_startMigration`; migration is triggered through the array-config commit
(0x0004) with a changed RAID level/member set. Marked in §3.

### Progress / status — READ (HIGH confidence)

| `i2_*` fn | RVA | line | MicOp | family/sub |
|-----------|-----|------|-------|-----------|
| i2_getRebuildProgress      | 0xcea0 | 9495 | **0x0508** | 08/05 |
| i2_getLogDrvInitProgress   | 0xd3f0 | 9763 | **0x0506** | 06/05 |
| i2_getLogDrvSynchProgress  | 0xd900 | 10023 | 0x05/06-09 | CALIBRATE (mirror of init) |

### Statistics — family 0x12

| `i2_*` fn | RVA | line | MicOp | conf |
|-----------|-----|------|-------|------|
| i2_getStatsLogDrv          | 0x18760 | 18213 | **0x0412** | high |
| i2_getStatsLogDrv (reset)  | 0x18760 | 18213 | **0x0712** (WRITE) | high |
| i2_getStatsLogDrv (ext)    | 0x18760 | 18213 | **0x0912** | high |
| i2_getStatsPhyDrv          | 0x18570 | 18147 | computed (`uVar3`) | CALIBRATE |
| i2_getStatsController      | 0x183d0 | 18056 | dispatcher-only in view | CALIBRATE |

### Controller / subsystem (from Phase 2, re-confirmed here)

| `i2_*` fn | RVA | line | MicOp | family/sub |
|-----------|-----|------|-------|-----------|
| i2_getCurrentCtrlInfo | 0x3620 | 1974 | **0x0103** | 03/01 (`local_38 = 0x103bad8`) |

---

## 2. Request/response struct map

### Request CDBs (recovered)
- **Enumeration**: id at `CDB[6..7]` BE, optional verbosity at `CDB[8]`, xferlen
  at `CDB[10..11]` BE. No DATA-OUT.
- **Delete (array/spare/LD)**: id at `CDB[6..7]` BE, no DATA-OUT (id in CDB). The
  del builders set `CDB[14]=1` (element count) via `local_60 = 0x1000000000000`.
- **Create/apply (0x0004)**: DATA-OUT. `FUN_180006780` loops over the caller's
  PD-descriptor list (`param_3`, stride 0x48 bytes / 0x12 uints, count at
  `param_3[0]`), builds a **0x120-byte** per-element payload via `FUN_180006540`,
  and commits with xferlen at `CDB[10..11]` BE. The 0x120 payload internal layout
  (RAID level, member map, stripe, cache policy, alias) is **CALIBRATE:
  UNRESOLVED** — `FUN_180001210`/`FUN_180006540` marshal it from the caller's
  config descriptor but the field offsets are not cleanly recoverable. Do **not**
  fabricate them; a real create must be validated against a Windows SCSI trace.

### Response pages → `i2bdg_api.h` structs
Parsed by `prdecode.dll` `decod_*`. Field offsets recovered in §2.1 below (filled
in as the parsers are implemented). Structs are vendor-verbatim in
`port/include/i2bdg_api.h`: `I2bHddInfo_t`, `I2bLogDrvData_t`/`I2bLogDrvInfo_t`,
`I2bDiskArrayData_t`/`I2bArrayInfo_t`, `I2StatsLogicalDrive_t`,
`I2bLogDrvStatusInfo_t`.

#### 2.1 Recovered response-page offsets

Authoritative source: the vendor **`libi2bdg`** `i2bApi_get*Info` functions
(`re/out/libi2bdg_x64.c`) which call `i2_get*Info` and copy the response records
into the documented structs. These give the exact record offsets (provenance =
libi2bdg RVA + decompiler line).

**List-page header** (all): payload byte length u32 at **page+0x24** (arrays:
**page+0x20**); records begin at **page+0x40**. `count = pagelen / recsize`.

**Physical-drive record** — verbosity 0xff, **fixed stride 0x188**
(`i2bApi_getHardDiskDriveInfo` @0x26d0, line 1394-1464). Offsets into record:

| field (`I2bHddInfo_t`) | rec off | type | note |
|------------------------|---------|------|------|
| PDId          | 0x04 | u16 | value **+1** (1-based) |
| enclosureId   | 0x14 | u16 | value **+1** |
| slotId        | 0x16 | u16 | |
| (block size)  | 0x30 | u64 | ×  |
| (num blocks)  | 0x48 | u16 | confCapacity = blocks × blocksize |
| opStatusFlag  | 0x54 | u32 | `DEVICE_*` masks (decod_getPhyDrvOpStatus) |
| arrayId       | 0x06 | u16 | only when opStatusFlag != 0 |
| drvType       | 0x66 | u16 | |
| driveModelNo  | 0x68 | 40  | decod_getStringPhyDrvModel |
| temperature   | 0xb5 | u8  | |

`HDDTempWar/CriThreshold`, `controllerId` come from a separate
`i2_getControllerSettings` page (ctrl+0x113 / +0x11a) — **CALIBRATE**, left 0.

**Logical-drive record** — **fixed stride 0xC8** (`i2bApi_getLogDrvInfo` @0x2ae0,
line 1562-1611; `decod_locateBufferLogDrvInfo` +200). Offsets into record:

| field (`I2bLogDrvData_t`) | rec off | type | note |
|---------------------------|---------|------|------|
| LdId         | 0x00 | u16 | |
| RaidLvl      | 0x02 | u8  | already `I2BAPI_LDRAIDLVL_*` |
| OpStatusFlag | 0x04 | u32 | `LOGDRV_*` masks |
| (cache flags)| 0x08 | u32 | CachePolicy: bit2→0, else bit1?3+bit0:1+bit0 |
| Capacity     | 0x0c | u64 | × (blocks) |
| Alias        | 0x34 | 32  | |
| StripeSz     | 0x2c | u32 | × (sectors) |
| arrayId      | 0x30 | u16 | |
| SYNCed       |  —   |  —  | `~(OpStatusFlag>>0x12)&1` |

**LD-stats record** — **fixed stride 0x60** (`i2bApi_getStatsLogDrv` @0x2d50,
line 1701-1719; `decod_locateBufferStatistics` type=3). Offsets → `I2StatsLogicalDrive_t`:
LogicalDriveId@0x00(u8); BlocksTransferred@0x08, Read@0x10, Write@0x18 (u64, ×);
Errors@0x20, ReadErrors@0x24, WriteErrors@0x28, NonRWErrors@0x2c (u32);
IOReq@0x30, ReadIOReq@0x38, WriteIOReq@0x40, NonRWReq@0x48, statsStartTime@0x50,
statsCollectionTime@0x58 (u64).

**Disk-array record** — **fixed stride 0xB8** (`i2bApi_getDiskArrayInfo` @0x25c0,
line 1269-1276): DaId@**0x00**(u16); NumOfHdd@**0x2c**(u16); count = len@+0x20 / 0xB8.

Any field not in these tables is **CALIBRATE** and left zero.

---

## 3. Explicit status: implemented vs cataloged vs unresolved

- **Implemented (RE + code + tests)**: enumeration reads (phydrv 0x0804, logdrv
  0x0B04/0x0C04, array 0x0504/0x0604, spare 0x0204, LD stats 0x0412), and the
  lifecycle **request marshalling** (create-commit 0x0004, delete 0x0104/0x0404,
  init 0x0606/0x0106, synch 0x0109/0x0209) with **mock-only** tests.
- **Cataloged (opcode/struct known, not wired to a device path)**: progress reads
  (0x0508/0x0506), stats reset/ext (0x0712/0x0912), controller info (0x0103).
- **Unresolved (CALIBRATE)**: the 0x120 create payload internal layout; PD/ctrl
  stats computed opcodes; synch-progress exact sub; response-record field offsets
  not cited by a `decod_*` function; `CDB[8..9]` request-handle (zeroed, as Phase
  2). Migration/rebuild manual-start opcodes **do not exist** (auto-triggered).

---

## 3b. Config-surface catalog (RE, NOT implemented — tabulated)

Beyond the RAID core, `pri2apiv4` exposes the rest of the appliance. These are
**cataloged** (function + RVA present; opcode where a clean immediate exists),
not implemented. Family byte in CDB[2]; sub in CDB[3]. Confidence: **high** =
clean CONCAT/imm; **med** = family clear, sub inferred; **CALIBRATE** = opcode
built by separate byte-stores / a helper, not a single recoverable immediate.

Newly identified families (this pass):
`0x0B` initiators / LUN-mapping / host, `0x11` audible/buzzer, `0x16` Fibre
Channel, `0x18` SAS, `0x20` LCD/front-panel. Battery + FRU ride enclosure `0x0F`;
date/time rides controller `0x03`.

| surface | representative fn | RVA | MicOp | conf |
|---------|-------------------|-----|-------|------|
| iSCSI/host initiators | i2_getInitiatorList | 0x170a0 | **0x000B** | high |
|  | i2_addInitiator | 0x17200 | **0x010B** | high |
|  | i2_getLMMTable (LUN map) | 0x174e0 | **0x030B** | high |
| Fibre Channel | i2_FCGetPortSetting | 0x1dcd0 | **0x0216** | high |
|  | i2_FCSetPortSetting | 0x1de40 | **0x0316 / 0x0516** | high |
| SAS | i2_SASPHYControl | 0x1fdd0 | **0x0418** | high |
|  | i2_getSASInitiatorList | 0x1e790 | family 0x18 | med |
| LCD panel | i2_getLCDInfo | 0x20d20 | **0x0120** | high |
| audible alarm | i2_getBuzzerStatus | 0x17ec0 | **0x0411** | high |
| battery | i2_getBatteryInfo | 0x14cc0 | **0x070F** (encl) | high |
| FRU | i2_getFRUInfo | 0x14fc0 | **0x060F** (encl) | high |
| date/time | i2_getDateAndTime | 0x18000 | **0x0703** | high |
|  | i2_setDateAndTime | 0x18170 | **0x0803** (WRITE) | high |
| controller stats | i2_getStatsController | 0x183d0 | family 0x12 | CALIBRATE |
| host info | i2_getHostInfo | 0xfac0 | — | CALIBRATE |
| users | i2_getAllUser / i2_addUser / i2_deleteUser | 0x15bb0 / 0x15cd0 / 0x16000 | — | CALIBRATE |
| sessions | i2_getCurrentSessionInfo / i2_forceSessionLogout | 0x11e70 / — | — | CALIBRATE |
| events | i2_getEvents / i2_getFirst/LastEvents / i2_clearEventLog | 0xe8c0 / … | — | CALIBRATE |
| BGA scheduler | i2_getBGASchedule / i2_addBGASchedule / i2_deleteBGASchedule | 0x11fb0 / 0x12050 / … | — | CALIBRATE |
| NTP | i2_getNtpConfig / i2_executeNtp | 0x138e0 / — | — | CALIBRATE |
| email | i2_getEmailConfig | 0x12dd0 | — | CALIBRATE |
| SNMP | i2_getSnmpConfig | 0x12950 | — | CALIBRATE |
| Bonjour / CIM / event-log cfg | i2_get{Bonjour,Cimserver,Evtlog}Config | 0x14402/… | — | CALIBRATE |

The CALIBRATE rows are functions whose CDB opcode is assembled by separate
family/sub byte writes (rendered as a bare `0xbad8` in the decompile) rather than
a single `CONCAT`/immediate; recover them per-function with a targeted Ghidra
decompile when that surface is implemented. **SSH/telnet** have no dedicated
`i2_*` op (managed outside the FW engine, on the appliance host).

Representative implemented from this section: **none** (RAID core was the depth
target); the two with a live path are the enclosure-family battery/FRU reads,
reachable today via the existing `i2_fw_cmd` (0x070F / 0x060F).

---

## 4. On-device TODO (hardware gate — `port/scripts/calibrate.sh`)

Read-only enumeration self-test runs list phydrv/logdrv/arrays (§ calibrate step
[5]). To close the write path you MUST capture a Windows SCSI trace of one create
and diff the 0x120 payload before enabling any create on real hardware. Confirm:
1. phydrv page: total count @ `[0..1]` BE, first record @ `+0x40`, model string
   offset, capacity, state/config flag dword (`I2bHddInfo_t.opStatusFlag`).
2. logdrv page: RAID level byte, cache-policy byte, capacity/stripe u64.
3. array page: `NumOfHdd`, member PD ids.
4. The create payload: RAID level / member map / stripe / alias offsets in the
   0x120 element block.
