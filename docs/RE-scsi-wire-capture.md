# SCSI wire capture — live from the golden A3120 (StorPort ETW)

Captured on the blank vendor A3120 (Windows, `192.0.2.211`) by tracing
`Microsoft-Windows-StorPort` while running one `clitest -C enclosure -a list -v`
+ `-C phydrv -a list -v`. Confirms + calibrates the Phase-2 `fwinterf`/`pri2apiv4`
RE with real bytes-on-the-wire.

## Confirmed: the management transport

- **Opcode `0xD8`** — the Promise vendor "MicOpCode" management command (matches the RE).
- **Endpoint: `Port 1 / Bus 0 / Target 254 (0xFE) / LUN 0`** — the controller's
  in-band management target (NOT a data disk). 32 `0xD8` commands in a single
  enclosure+phydrv read.
- **Data array is separate**: `0x28`/`0x2A` (READ10/WRITE10) + `0x00` (TUR) to
  `Port 0 / Bus 1 / Target 0` = the RAID volume `LA0`. This is I/O noise, not mgmt.
- The ASMedia direct-MMIO path (`AsmIo.sys`/`106BCommandLib`) is **not used** —
  `ASMLog.txt`: "Not find the ASM Device … stop RAID Monitor Service". All
  management rides the SCSI-passthru `0xD8` path. (Retroactively explains the
  Proxmox ASM2106B dead-end: the vendor never used that path.)

## `0xD8` sub-command fingerprints (transfer sizes, in order)

From `enclosure -a list -v` then `phydrv -a list -v` (Scsi=0x0 Srb=0x1 = OK):

```
256, 1112, 208, 464, 136, 136, 1112, 68, 68, 68, 68, 91,   <- enclosure read
  [0/CHECK-COND x2 = absent-component probes],
320, 1112, 208, 464, 1120, 456, 104, 168 (x repeating), 72  <- phydrv read
```

Each distinct size is one i2 sub-command's response buffer (getEncInfo,
getFanInfo, getThermal, getVoltage, getPSU, getHddInfo, …). The 68-byte × repeats
line up with the per-fan / per-sensor loops; 1112 = a table (drives/slots).

## Linux mapping (SG_IO)

The whole path = `ioctl(fd, SG_IO, ...)` on the controller's `/dev/sg*` node with:
- CDB[0] = `0xD8`, addressed to the mgmt LUN (Linux target/lun for HBA Target 254).
- Direction: SG_DXFER_FROM_DEV, `dxfer_len` = the per-command size above.
- The sub-opcode + params live in CDB[1..] (from the `fwinterf` RE); response is
  the data buffer (decoded per `prdecode` / `i2bdg_api.h` structs).

## LIVE byte-exact capture (Frida `DeviceIoControl` hook) — the whole read surface

Hooked `KERNELBASE!DeviceIoControl` in `i2.exe` (PID owning `fwinterf.dll`),
filtered `IOCTL_SCSI_PASS_THROUGH_DIRECT` (0x4D014), dumped `Cdb[16]` +
`DataBuffer` for `subsys/ctrl/enclosure/phydrv/array/logdrv -a list -v`. Raw:
[`samples/scsi-frida-capture.log`](samples/scsi-frida-capture.log), parsed
[`samples/scsi-micopcodes.txt`](samples/scsi-micopcodes.txt).

### The 16-byte CDB — definitive (bytes, not inferred)

```
[0]  0xD8            vendor opcode
[1]  0xBA
[2]  family          01 03 04 07 0F 12
[3]  sub-opcode
[4]  mode flag       0 / 1        (0x0204 array uses [4]=1 for "by id")
[5]  element/index-hi (enclosure element 0/1/5; array id)
[6]  object index    physical-drive id 0..3 (increments per drive)
[7]  0
[8]  part selector   00/01/02 = multi-part; 0xFF = whole record
[9]  param           (e.g. 0x2C on 0x0501)
[10..11] xfer length BE u16   0xFFFF = "return everything"
[12..13] 0
[14] read flag       mostly 0x01
[15] 0
```

Direction is IN (read) for all list ops; `SG_IO` `dxfer_len` = CDB[10..11].

### MicOpCode map — CONFIRMED live (fam = CDB[2], sub = CDB[3])

| MicOp | fam/sub | resp bytes | operation |
|-------|---------|-----------|-----------|
| `0x0403` | 03/04 | 576 | **subsystem** info (vendor/model/SN/WWN) |
| `0x0103` | 03/01 | 1560+656+912, or `FFFF` | **controller** info (parts 0/1/2, or [8]=FF=all) |
| `0x0203` | 03/02 | all | subsystem logical-drive summary |
| `0x000F` | 0F/00 | 136 / all | **enclosure info** (model byte @ +0x0e → 1U4BAY) |
| `0x010F` | 0F/01 | **1024 (0x400)** | **enclosure status** — fans/temps/volts/PSU |
| `0x060F` | 0F/06 | 320×n | enclosure element (by CDB[5]=0,1,5) |
| `0x200F` | 0F/20 | 68 | terminal **sensor** settings |
| `0x220F` | 0F/22 | 68 | terminal **alarm** settings |
| `0x240F` | 0F/24 | 68 | enclosure/alarm setting |
| `0x260F` | 0F/26 | 68 | **power switch** |
| `0x0C07` | 07/0C | 256 | enclosure slot / phy map |
| `0x0204` | 04/02 | 57408 / `FFFF` | **disk-array** info (all / by id, [4]=1) |
| `0x0804` | 04/08 | 50240 / 2000 | **physical-drive** info (table / per-drive CDB[6]) |
| `0x0904` | 04/09 | 2000 | physical-drive extended (per-drive) |
| `0x0E04` | 04/0E | 17168 | physical-drive statistics table |
| `0x0B04` | 04/0B | all | **logical-drive** info |
| `0x0512` | 12/05 | 328 | per-drive SMART / detail (per drive) |
| `0x0501` | 01/05 | 80 | array param/spare |
| `0x0601` | 01/06 | 0 | array action (no data) |

Families: **01** array-aux · **03** controller/subsystem · **04** RAID objects
(array/LD/PD) · **07** enclosure-slot map · **0F** enclosure/SEP · **12** per-drive
detail. The `0x0F` enclosure family matches the Phase-2 RE §2 exactly; the `0x04`
RAID family (array/LD/PD) is **newly pinned here** — it is what the drive/array
pages need once the Proxmox controller is in RAID mode.

### Response payloads (spot-decoded, validates the decoders)

- `0x0403` (subsystem, 576B): ASCII `PROMISE` / `Promise Technology,Inc.` /
  `Vess A3120`, SN `WR0093003581`, WWN bytes `20 00 00 01 55 5f 93 16`.
- `0x0103` (controller): `VessA3120 B/P`, SN `B53L18426200176`, `A1`, mem/flash counts.
- These match the CLU + `CALIBRATION-A3120-golden.md` field-for-field.

The read surface for the Linux SG_IO backend is now fully specified from real bytes.

### Write / command capture (locate — LIVE) + the rest (RE-recovered)

Triggered via `clitest -C phydrv/enclosure -a locate` under the same hook
(`samples/scsi-write-capture.log`):

| command | MicOp | CDB | notes |
|---------|-------|-----|-------|
| drive-carrier **locate** | `0x020F` | `D8 BA 0F 02 00 00 00 00 00 <slot> 00 …` | **CDB[9] = slot (0-based)**, xfer=0; captured drives 1-4 → slot 00/01/02/03 |
| enclosure **locate** | `0x0F0F` | `D8 BA 0F 0F 00 …` | xfer=0, whole-enclosure blink |
| (drive lookup first) | `0x0804` | `D8 BA 04 08 00 00 00 <id> …` | **CDB[6] = drive id (0-based)** |

Byte-level confirmation from the `0x000F` enclosure-info payload: model byte =
**`0x57` ('W') = `ENC_TYPE_VESS_1U4BAY`** (matches the RE `'W'=1U4BAY` and the CLU).

The remaining writes — direct FRU-LED set `0x130F` (CDB[6]=FRU, CDB[7]=colour),
alarm `0x230F`, sensor `0x210F`, WDT `0x190F`, VPD `0x8103` — are **not exposed by
the CLU** (they are internal fault-indication / config APIs) so were not live-
triggered; they are RE-recovered in [`RE-fwinterf-pri2api.md`](RE-fwinterf-pri2api.md) §2.
The dangerous ones (WDT can reset the box; VPD write can brick identity) were
deliberately left alone.
