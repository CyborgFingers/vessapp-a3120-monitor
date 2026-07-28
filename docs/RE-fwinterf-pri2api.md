# RE findings: `fwinterf.dll` + `pri2apiv4.dll` + `prdecode.dll` (Phase 2)

Reverse engineering of Promise's in-band SCSI path for the **Vess A3120**, to
build the Linux substrate `libi2` (`/dev/sg*` + `SG_IO`) and give the Phase-1
`libi2bdg` its real backend. Binaries (Ghidra 12.1.2 headless + capstone):

- `windows-src/extracted/system32/fwinterf.dll` (PE32+ x64) — the SCSI passthru
- `windows-src/extracted/system32/pri2apiv4.dll` (PE32+ x64) — the `i2_*` engine
- `windows-src/extracted/system32/prdecode.dll` (PE32+ x64) — page decoders
- `clitest/i2.exe` — the service that owns the FW-IOCTL loop (reference)

Decompiler dumps: `re/out/{fwinterf,pri2apiv4,prdecode}_x64.c`.

Confidence: **high** = literal constant/flow in the decompiled code or a
confirmed disassembly immediate; **med** = decompiler stack recovery; **CALIBRATE**
= not fully recoverable from the binaries, confirm on the real A3120.

---

## 0. TL;DR for the port

Each enclosure call is: `libi2bdg` → `i2_*` (libi2) → a **16-byte Promise vendor
CDB** → `SG_IO` to the Promise controller → SEP. The CDB is
`D8 BA <MicOpCode LE> .. <elem@6> <colour@7> .. <xferlen BE @10..11> ..`. All the
enclosure MicOpCodes were recovered (§2). The transport struct (§1) is a stock
`SCSI_PASS_THROUGH_DIRECT`, i.e. `SG_IO` on Linux. **SES-2 verdict: the vendor
software does NOT use standard SES; but the hardware might — probe with `sg_ses`
first (§6).**

---

## 1. The transport — `_ib_sendSCSIPassThruDirect` (fwinterf.dll @ RVA 0x1070)

`sendCommand` (export ord 23 @ 0x1cf0) → `_ib_sendSCSIPassThruDirect` builds a
`SCSI_PASS_THROUGH_DIRECT` and issues `DeviceIoControl(\\.\scsiN:, 0x4d014, ...)`.
`0x4d014` = `IOCTL_SCSI_PASS_THROUGH_DIRECT`. **Linux equivalent = `SG_IO`.**

Recovered SPTD field writes (high confidence — literal offsets in @0x1070):

| SPTD field           | off  | value                                  |
|----------------------|------|----------------------------------------|
| Length               | 0x00 | 0x38 (sizeof SPTD, x64)                |
| ScsiStatus           | 0x02 | (out)                                  |
| PathId / TargetId / Lun | 0x03/04/05 | from `_ib_discover_hba` (bus:tgt:lun) |
| CdbLength            | 0x06 | **0x10 (16-byte CDB)**                 |
| SenseInfoLength      | 0x07 | **0x40 (64-byte sense)**              |
| DataIn               | 0x08 | 0=OUT (write), 1=IN (read)            |
| DataTransferLength   | 0x0C | data buffer length                     |
| TimeOutValue         | 0x10 | ~10-60 s                               |
| DataBuffer           | 0x18 | ptr                                    |
| SenseInfoOffset      | 0x20 | 0x3c                                   |
| Cdb[16]              | 0x24 | the 16-byte CDB (below)               |

`0x06/0x07` come from the single store `param_5[3]=0x4010` → CdbLength=0x10,
SenseInfoLength=0x40. Direction map (via i2.exe `_converRWControl`): pri2 code
1=read → SPTD DataIn=1 (`SG_DXFER_FROM_DEV`); pri2 code 2=write → DataIn=0
(`SG_DXFER_TO_DEV`). On non-zero ScsiStatus, `formPMICErrorCode(status, sense,
senselen)` decodes the Promise "PMIC" error from sense (fwinterf imports
`isPMICErrorCode`/`getPMICErrorAscq`/`formPMICErrorCode` from `prerrlog.dll`).

### The CDB (high confidence)

`sendCommand` forces `*(u16*)cdb = 0xBAD8` → **CDB[0]=0xD8, CDB[1]=0xBA**
(disassembly: `mov [rbp+0x210], 0x103bad8` in the discovery path confirms the
`..BAD8` dword). The `MicOpCode=%X` log prints `*(u16*)(cdb+2)`, so:

```
CDB[0]  = 0xD8            vendor SCSI opcode
CDB[1]  = 0xBA
CDB[2..3] = MicOpCode     little-endian u16   <-- the operation selector
CDB[6]  = element/selector (enclosure id / FRU id / VPD selector)  [high]
CDB[7]  = sub-param (LED colour on set)                            [high]
CDB[8..9]  = request id/handle, big-endian                        [CALIBRATE]
CDB[10..11] = transfer length, big-endian u16 (== SG dxfer_len)   [high]
CDB[4..5],[12..15] = 0 / op-specific                              [CALIBRATE]
```

Implemented in `port/src/libi2/i2.c` (`i2_fw_cmd`) + `port/include/i2_micops.h`.

### HBA discovery — `_ib_discover_hba` / `_getCtrlInfo` (fwinterf @ 0x1220, 0x1940)

Scans `\\.\scsi0:`..`\\.\scsi15:`. For each: (a) `IOCTL_SCSI_MINIPORT` (0x4d008)
with SRB signature **"FASTTRAK"** (driver-version check — Windows-only), then
(b) a vendor GetControllerInfo (`MicOpCode 0x0103`, 0x458-byte response) and
**matches the product string `"Vess"`** at response offset 224. The Linux port
replaces the "FASTTRAK" handshake with a **standard INQUIRY** (portable) and
matches vendor `"Promise"` / product `"Vess"` — `scsi_transport_discover` in
`port/src/libi2/scsi_transport_sgio.c`.

PDB path in fwinterf: `D:\home\Evan\3120_channel\vessapp_sw_win\...\fwinterf.pdb`
(confirms this build targets the A3120).

---

## 2. `i2_*` MicOpCodes (pri2apiv4.dll — high confidence)

Every enclosure `i2_*` builds a 16-byte CDB then calls the client dispatcher
`FUN_1800042e0` (internal req-type `0x1103`) → IPC to the i2 service. The CDB
`D8 BA` + MicOpCode is identical to what the service issues, so the Linux port
issues it directly (collapsing the IPC — see §5). MicOpCodes (LE u16 @ CDB[2]):

| `i2_*` function                          | RVA     | MicOpCode | family/sub | dir  |
|------------------------------------------|---------|-----------|------------|------|
| (fwinterf GetControllerInfo, discovery)  | 0x1220  | **0x0103**| 03 / 01    | read |
| i2_getEnclosureInfo                      | 0x140b0 | **0x000F**| 0F / 00    | read |
| i2_getEnclosureStatus                    | 0x14220 | **0x010F**| 0F / 01    | read |
| i2_setLedFruInfo                         | 0x22d70 | **0x130F**| 0F / 13    | write|
| i2_setWDTSettings                        | 0x14880 | **0x190F**| 0F / 19    | write|
| i2_getEnclosureTerminalSensorSettings    | 0x222f0 | **0x200F**| 0F / 20    | read |
| i2_setEnclosureTerminalSensorSettings    | 0x22440 | **0x210F**| 0F / 21    | write|
| i2_getEnclosureTerminalAlarmSettings     | 0x22590 | **0x220F**| 0F / 22    | read |
| i2_setEnclosureTerminalAlarmSettings     | 0x226e0 | **0x230F**| 0F / 23    | write|
| i2_getEnclosurePowerSwitch               | 0x14a30 | **0x260F**| 0F / 26    | read |
| i2_getLedFruInfo                         | 0x22ad0 | **0x280F**| 0F / 28    | read |
| i2_getLedFruInfoByHdd                    | 0x22c10 | **0x290F**| 0F / 29    | read |
| i2_getCtrlexInfo (VPD read)              | 0x22eb0 | **0x8003**| 03 / 80    | read |
| i2_setCtrlexSettings (VPD write)         | 0x22fd0 | **0x8103**| 03 / 81    | write|

Family byte in CDB[2]: **0x0F = enclosure/SEP**, **0x03 = controller**. Sub-opcode
in CDB[3]. Recovered as clean immediates, e.g. `local_38 = 0x230fbad8` (set alarm),
`mov eax, 0x10f` (status), `CONCAT16(param_3[2], 0x130f0000)` (set LED).

Per-op params in the CDB (recovered where confident):
- **set LED** (0x130F): CDB[6]=FRU id, CDB[7]=colour, CDB[10..11]=xferlen BE.
- **get LED by HDD** (0x290F): CDB[6]=slot; slot 0xff special-cased in @0x22c10.
- **VPD** (0x8003/0x8103): CDB[6]=selector (4=MB, 5=BP).
- **WDT** (0x190F): payload marker 0xAD; CDB carries 0xAD@6 and **0x57@7**
  (`local_38 = 0x57ad0000190f0000` in @0x14880).
- **info/status**: CDB[10..11] = page length BE (0x0088 min / 0x0400 fixed).

### Request/response framing (documented, not on the Linux data path)

`i2_initialize` takes an init struct with **signature `0xbaba0001`**, timeout
`0x3c` (60), client tag `"i2bdg"` (libi2bdg @0x10d0). The response header carries
the same signature (`pri2apiv4 @0x5530`: `(*(u32*)(hdr+2) & 0xffff0000) !=
0xbaba0000` → reject). pri2apiv4 reaches the service two ways (`WS2_32`
socket/connect to 127.0.0.1, and `_clientReq2ipcSharedMemory`); i2.exe runs
`_processRequestFwIoctl` → `_preProcessFwIoctl` + `_sendFwIoctlCmd`
(`_converRWControl` + `fwinterf!sendCommand`) + `_postProcessFwIoctl`
(`getSession`/`checkFwLock`). **The Linux port does not reproduce this IPC** — it
is documented here as the Phase-3 `i2d` daemon protocol reference.

---

## 3. Enclosure page decode (prdecode.dll — resolves Phase-1 UNRESOLVED)

Fields decoded from the `i2_getEnclosureStatus` (0x400) and `i2_getEnclosureInfo`
(≥0x88) pages. These RESOLVE the Phase-1 UNRESOLVED "direct-SMBus" offsets (which
were fictional — no such registers exist).

| item | recovered | source (prdecode RVA) | conf |
|------|-----------|-----------------------|------|
| model byte | page **+0x0e**: 'R'(0x52)=A3120, 'P'(0x50)=2U6BAY, 'W'(0x57)=1U4BAY (+'S'/'T'/'U') | 0x98e0, 0xd070, 0x9d40, 0xa240 | high |
| encl sub-type u16 | page **+0x1c** | 0x98e0 etc. | high |
| NumFans | page **[0]** | decod_getEnclNumFan 0xd070 | high |
| NumTempSensors | page **[4]** | decod_getEnclNumTempSensor 0xd610 | high |
| NumVolt | page **[8]** (== fan-speed region offset term) | derived from 0x9b30 | med |
| fan status | base **+0x40** + fanIdx (0=Functional,1=Malfunction,2=NotInstalled) | decod_getEnclFanStatus 0x98e0 | high |
| temperature | base **+0x42** + Σ(page[0,1,2]) + idx; value = **°C** (F=C*9/5+32) | decod_getEnclTemperature 0x9d40 | high |
| voltage | base **+0x44** + Σ(page[0,1,2,4]) + idx; value = **hi.lo nibble V** | decod_getEnclVoltage 0xa240 / getStringVoltage 0xa270 | high |
| fan speed | base **+0x44** + Σ(page[0,1,2,4,8]) + idx; **RPM = 1350000/(raw*k)** | decod_getEnclFanSpeed 0x9b30 | high |
| fan k (A3120 'R') | fans 0-1 → k=16, fans 2+ → k=2; raw 0/0xff = not present | 0x9b30 | med |
| temp status | cur<warn→normal, cur<crit→warning, else critical | 0x9df0 | high |

**Phase-1 was WRONG that fan speed is a stored u16 RPM** — it is a raw tach byte
(RPM = 1350000/(raw*k)). Phase-1 voltage nibble-packing was RIGHT.

Constants live in `port/include/i2c_map.h` (RECOVERED / CALIBRATE split kept).

---

## 4. What each `i2bdg_*` now does (the re-plumb)

`port/src/lib/i2bdg_*.c` call `libi2` instead of the retired SMBus HAL:
- `getEncInfo` → `i2_getEnclosureInfo`, model @+0x0e.
- `getElementCnt`/`getFanInfo`/`getThermal`/`getVoltage` → `i2_getEnclosureStatus`,
  parse counts + per-region bytes (§3).
- `setLED`/`getLED`/record/system → `i2_setLedFruInfo`(0x130F) /
  `i2_getLedFruInfo`(0x280F) / `i2_getLedFruInfoByHdd`(0x290F).
- alarm/sensor → `i2_*EnclosureTerminal{Alarm,Sensor}Settings` (0x220F/0x230F/0x200F).
- WDT → `i2_setWDTSettings`(0x190F).
- VPD MB/BP → `i2_getCtrlexInfo`(0x8003)/`i2_setCtrlexSettings`(0x8103), selector 4/5.
All Phase-1 recovered semantics (validation rules, RECORD red/off, WDT floor,
alarm type 0x10/state@+2, VPD dual checksums) are preserved.

---

## 5. `i2d` daemon boundary (decision)

The Windows model is a shared **service** (i2.exe) that owns the SCSI handle and
serializes PHP/CLI/SNMP clients over loopback IPC. Phase 2 builds `libi2` with a
clean handle boundary (`i2_handle` owns one `scsi_transport`) so a thin
serializing daemon **`i2d`** can wrap it in Phase 3 without touching callers. The
daemon is **not built now** (a library boundary suffices). When built, `i2d`
should reuse the `0xbaba0001` framing (§2) for its wire protocol.

---

## 6. SES-2 verdict *(flag)*

**The vendor stack does NOT use standard SES-2.** No `RECEIVE/SEND DIAGNOSTIC`,
no "enclosure services" strings in any binary; the enclosure is driven entirely
by the proprietary `0xD8` + MicOpCode path above. The only SES trace anywhere is
the literal `"SES Expender"` (sic) descriptor string in `prdecode.dll` — evidence
the SEP *can* present as a SES expander, but the software chose the vendor path.

**Consequence:** it is **plausible** the A3120 SEP also answers standard SES-2 in
parallel (common for RAID enclosures), which would let stock `sg_ses` read fan
speeds / temps and set LEDs with **no MicOpCode RE at all**. This is decided on
hardware, not from the binaries — `calibrate.sh` step [2] probes `sg_ses` FIRST
and reports it prominently. If it works, the standard-SES path should be preferred
for the enclosure surface; the MicOpCode path (fully recovered here) remains the
fallback and is required for the RAID/controller surface regardless.

---

## 7. On-device TODO (the hardware gate — run `port/scripts/calibrate.sh`)

1. **SES-2 probe** (`sg_ses $SG`) — if it answers Enclosure Status + Element
   Descriptor pages, prefer it; may retire most enclosure MicOpCode work.
2. Confirm INQUIRY vendor/product identify the controller (`sg_inq`).
3. MicOpCode round-trip: `GetEnclosureInfo` (0x000F) via `sg_raw`; confirm the
   model byte at page **+0x0e** reads `'R'` (0x52).
4. Dump a real 0x400 status page and pin the **CALIBRATE** items:
   - located enclosure base (`I2C_MAP_ENC_STATUS_BASE`, assumed 0),
   - identities of `page[1],[2]` region counts and the exact voltage-count byte,
   - the LED/WDT/alarm DATA-OUT payload-buffer layout (CDB positions are known).
5. Confirm CDB[8..9] (request id/handle) — currently zeroed.
6. Re-run `build-in-container.sh` after every `i2_micops.h` / `i2c_map.h` edit.

Nothing below the recovered layer was invented: recovered items cite a
function + RVA; everything else is flagged `CALIBRATE`.
