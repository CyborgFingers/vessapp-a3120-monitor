# RE findings: `libi2bdg.dll` (Vess A3120 enclosure HAL)

Phase-1 reverse engineering of Promise's `libi2bdg.dll`, to reproduce its API
on Linux. Binaries analysed (Ghidra 12.1.2 headless + pefile):

- `windows-src/extracted/system32/libi2bdg.dll` (PE32+ x64, primary)
- `windows-src/extracted/SysWOW64/libi2bdg.dll` (PE32 x86, cross-check - identical model)
- `windows-src/extracted/system32/pri2apiv4.dll`, `.../fwinterf.dll` (for the transport chain)

Decompilation of all 36 exports is in `re/out/libi2bdg_x64.c`.

---

## 1. Transport model — **(c) it calls into `pri2apiv4.dll`** (in-band, via the RAID controller)

libi2bdg does **NOT** touch an I2C bus directly. It is a thin client of the i2
RAID engine. The full chain, all evidence-backed:

```
libi2bdg.dll   (public i2bdg_api / i2bApi_* - THIS binary)
   │  imports 28 i2_* fns from pri2apiv4.dll: i2_initialize, i2_login,
   │  i2_getEnclosureInfo/Status, i2_get/setLedFruInfo, i2_getLedFruInfoByHdd,
   │  i2_get/setEnclosureTerminalAlarmSettings, i2_setWDTSettings,
   │  i2_getCtrlexInfo, i2_setCtrlexSettings, i2_sendPassThruCommand …
   ▼
pri2apiv4.dll  (i2 client engine + IPC)
   │  reaches the engine two ways (WS2_32 + shared mem):
   │    _sendI2SocketRequest()  -> TCP 127.0.0.1   (socket/connect/inet_addr)
   │    _clientReq2ipcSharedMemory / _sendIpcSharedMemory
   │  and issues FW-IOCTL:  icc_sendFwIoctlRequest(iic_piccCommunicator, …)
   ▼
fwinterf.dll   ("firmware interface" - where real device I/O lives)
   │  CreateFileA("\\.\scsi%d:")  (scans SCSI ports 0..15)
   │  DeviceIoControl( SCSI_PASS_THROUGH_DIRECT )   with a Promise MicOpCode
   │  _ib_sendSCSIPassThruDirect(...)     (ib_ = IN-BAND)
   ▼
RAID controller firmware  →  enclosure processor (SEP), over the controller's
                             own I2C sideband (bytes live in FW, not shipped)
```

**Evidence:**
- libi2bdg imports (pefile): 28 `i2_*` from `pri2apiv4.dll`, 21 `decod_*` from
  `prdecode.dll`. Its only `KERNEL32` imports are CRT/exception boilerplate —
  **no `DeviceIoControl`, no `CreateFile`, no `\\.\` device, no inpout32/WinIo.**
- libi2bdg log strings: `sc query i2service`, `net start i2service`,
  `taskkill /f /im i2.exe`, "try to login a new i2dbg" → it depends on the i2
  service being up. A login-mode string table (`inband`, plus `raspberry_pi`,
  `golang+redis+beego`) sits at RVA 0x5a78; `inband` matches fwinterf's `_ib_`.
- `pri2apiv4.dll` imports `WS2_32` (socket/connect/send/recv) and strings
  `_sendI2SocketRequest`, `_clientReq2ipcSharedMemory`,
  `icc_sendFwIoctlRequest(...iic_piccCommunicator...)`.
- `fwinterf.dll` imports `DeviceIoControl`+`CreateFileA`, strings `\\.\scsi%d:`,
  `_ib_sendSCSIPassThruDirect() ... DeviceIoControl() ... MicOpCode=%X`,
  `_ib_discover_hba() ... scaning scsi ports from 0 to 15`, and the PDB path
  `D:\home\Evan\3120_channel\vessapp_sw_win\...\fwinterf.pdb` (A3120 build).

**Consequence for the port (decision needed — see §4):** the literal I2C bus#,
SEP slave address, and per-element register/opcode bytes are **inside the RAID
controller firmware** and are **not present in any shipped binary** — they
cannot be recovered by decompiling this package. What *is* recoverable, and what
the Linux libi2bdg must reproduce at this layer, is the **API → i2_ call
contract** below.

Every API function first calls the lazy-login helper `FUN_1800010d0 @0x10d0`
(does `createErrorHandler` + `i2_initialize` with signature word `0xbaba0001`,
timeout `0x3c`=60, client tag "i2bdg"), then mallocs a buffer, calls one `i2_*`,
and parses the result (often via `prdecode`). The Linux analog is
`i2bdg_session.c`.

---

## 2. Per-function map (what each API actually does)

Confidence: **high** = literal constant/flow in the decompiled code; **med** =
derived from decompiler stack-variable recovery; **UNRESOLVED** = not in binary.

| API (i2bdg_api.h) | RVA | Backing `i2_*` call | Recovered constants / behaviour | Conf |
|---|---|---|---|---|
| `i2bApi_getElementCnt` | 0x1c40 | `i2_getEnclosureInfo` (0xffff buf) | counts parsed from enclosure-info page into `I2bElementCount_t` | high (flow) / UNRESOLVED (page offsets) |
| `i2bApi_getEncInfo` | 0x1d20 | `i2_getEnclosureInfo`+`i2_getEnclosureStatus` | model byte @page+0x0e: **'R'→3U16BAY (A3120)**, 'P'→2U6BAY, 'W'→1U4BAY; `EncId=1` | high (mapping) |
| `i2bApi_getFanInfo` | 0x2340 | `i2_getEnclosureInfo`+`i2_getEnclosureStatus` | loop NumOfFans, stride 0x50, decode via `decod_getEnclFanSpeed/Status` | high (flow) / UNRESOLVED (offsets) |
| `i2bApi_getThermalSensorInfo` | 0x1e70 | same + `decod_getEnclTemperature` | per-sensor temp/warn/crit | high/UNRESOLVED |
| `i2bApi_getVoltageSensorInfo` | 0x20c0 | same + `decod_getEnclVoltage` | `CurVolt` packed hi-nibble.lo-nibble (also in sample) | high |
| `i2bdg_setLED` | 0x4090 | `i2_setLedFruInfo` (req 0x44) | panel ids `{0x02,0x03,0x0c..0x17}`; **HDD ids 0x80..0x8f → UNSUPPORTED(6)**; else PARAM_ERR(1); frame {id,color,cause} | high |
| `i2bdg_getLED` | 0x3e70 | `i2_getLedFruInfo` / **`i2_getLedFruInfoByHdd`** (slot=id-0x7f) | id 0x10 also returns u16 cause; needs both out ptrs for 0x10 | high |
| `i2bdg_set/getRecordLED` | 0x3b50/0x3a50 | `i2_set/getLedFruInfo` | **RECORD = FRU 0x0f**; FW accepts only **RED(0x02)/OFF(0x80)** | high |
| `i2bdg_set/getSysErrorLED` | 0x3d70/0x3c50 | `i2_set/getLedFruInfo` | **SYSTEM = FRU 0x10**, carries u16 cause | high |
| `i2bdg_set/getTermAlramState` | 0x4370/0x4270 | `i2_set/getEnclosureTerminalAlarmSettings` | req 0x44; sub-type byte **0x10**; state u16 at frame **+2** | high |
| `i2bdg_getTermSensorState` | 0x4450 | `i2_getEnclosureTerminalSensorSettings` | state u16 at +2 | high |
| `i2bdg_enableWDT` | 0x4730 | `i2_setWDTSettings` (req 0x4a) | frame word `0x0001ad00` → b1=**0xAD** marker, b2=**0x01** enable | high |
| `i2bdg_updateWDT` | 0x4820 | `i2_setWDTSettings` | b2=**0x02** update, b3=interval; **interval floored to 5 when ≤2** | high |
| `i2bdg_disableWDT` | 0x4910 | `i2_setWDTSettings` | b2=**0x03** disable | high |
| `i2bdg_get/setMBVPD` | 0x3470/0x3590 | `i2_getCtrlexInfo` / `i2_setCtrlexSettings` | **selector 4=MB**; 0x140 buf; VPD data at **+0x40**; read off≤0xff & off+len≤0x100; write off∈[4,0xfd] | high |
| `i2bdg_get/setBPVPD` | 0x3760/0x3880 | `i2_getCtrlexInfo` / `i2_setCtrlexSettings` | **selector 5=BP**; otherwise identical to MB | high |
| VPD write checksums | 0x3590/0x3880 | — | two 2's-complement 16-bit short-sums: **ck1@+2** over 62 u16 (off 4..127), **ck2@+254** over 63 u16 (off 128..253); recomputed on every write | med |

Out of Phase-1 scope (RAID/events/UART/SMART): `i2bApi_getDiskArray/HardDisk/
LogDrv/StatsLogDrv`, `i2bdg_get/setEvent`, `i2bdg_get/setUARTMode`,
`i2bdg_SMART*` — these route through `pri2apiv4` too (`i2_getDiskArrayInfo`,
`i2_getEvents`, `i2_get/setEnclosureUartSettings`, `i2_sendPassThruCommand`) and
belong to Phase 2+. Stubbed as `UNSUPPORTED` in `src/lib/i2bdg_stubs.c`.

---

## 3. Constant map (mirrors `port/include/i2c_map.h`)

**RECOVERED (real — describe API semantics):** enclosure model bytes R/P/W; LED
FRU types RECORD=0x0f / SYSTEM=0x10; RecordLED colours RED/OFF only; panel-LED
id set and HDD-slot read-only rule; WDT marker 0xAD + actions 1/2/3 + interval
floor 5; alarm sub-type 0x10 + state@+2; VPD selectors 4/5, data@+0x40, 256-byte
window, dual 2's-complement checksums, write-offset ≥ 4.

**UNRESOLVED (not in any binary — on-device only):** the I2C bus number, the SEP
7-bit slave address, and every SEP register/opcode byte and in-frame data offset.
These exist only in controller firmware; the placeholder values in `i2c_map.h`
are marked `CALIBRATE: UNRESOLVED` and are internally consistent (mock + parser
share them) purely so the driver logic is unit-testable off-device. **They are
not claims of fact.**

---

## 4. Needs on-device confirmation (the hardware gate)

Run `port/scripts/calibrate.sh` on the real A3120 (Linux). TODO list:

1. **Does a direct /dev/i2c path to the SEP even exist?** The Windows stack is
   in-band (SCSI-passthru to the controller). If `i2cdetect` finds no SEP on any
   bus, the direct-SMBus backend does not apply → drive the enclosure through the
   Phase-2 in-band engine (`i2d`, the Linux port of `pri2apiv4`/`i2.exe`). **This
   is the one design decision that a wrong RE guess must not pre-empt — hence it
   is left explicitly open.**
2. If a direct path exists: confirm `I2C_MAP_BUS` and `I2C_MAP_ADDR_SEP`.
3. Confirm each `I2C_MAP_REG_*` and the in-frame offsets (`*_OFF_*`) by dumping
   the SEP with `i2cdump`/`i2cget` against a known enclosure state and diffing.
4. Confirm the enclosure-info model byte reads `'R'` on the A3120.
5. Re-run `build-in-container.sh` after every `i2c_map.h` edit so the unit tests
   stay honest against the confirmed values.

Nothing below the `i2_*` layer was invented: recovered items are cited to a
function + RVA above; everything else is flagged UNRESOLVED.
