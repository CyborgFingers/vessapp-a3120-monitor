> **SUPERSEDED IN PART — read `docs/RE-asm2106b-access.md` first.** A later pass
> that added `raidmon.exe` + `asmiodll.dll` + `AsmIo.sys` to the RE corrected the
> *access* method: the **primary path is physical MMIO on BAR5** (raidmon runs
> `IFCmd*` with mode=1), and the config `0xD0/0xD4` backdoor is the *alternate*
> (mode=0) — this file has them inverted in §1. The command frame / opcodes /
> handshake offsets (§2-§4) remain correct. The base is BAR5-phys (cfg 0x24-0x27)
> and the mailbox is BAR5-phys+0x1000; on the live 512-byte ABAR that window is
> not exposed, which is why neither recovered path answers. See the new doc.

# RE findings: ASM2106B MMIO command mailbox (`106BCommandLib.dll`)

Reverse engineering of Promise's `106BCommandLib.dll` (the RAID-controller command
library shipped in `reference/raid-driver-stuff/raidmon/`) to drive the **ASMedia
ASM2106B** RAID controller natively on the user's bare Linux A3120 boxes, where the
controller is at PCI `02:00.0` (`1b21:0612`, subsystem `1b21:1060`) bound to generic
`ahci`.

Binaries analysed (Ghidra 12.1.2 headless): `106BCommandLib.dll` (PE32 x86) decomp in
`re/out/106b_x86.c`; `asmiodll.dll` export table. Everything below is cited to a
function + RVA and marked with a confidence.

Implemented in `port/src/hal/mmio/` (`asm_reg*.c` + `asm2106b.c` + `mmio_raid.c`),
selected by `VESSAPP_BACKEND=mmio`.

---

## 1. Register access — a PCI config-space index/data backdoor (PRIMARY)

The command library reaches the ASM2106B's internal register space (handshake regs +
the command/ack mailbox) **two** ways. The complete no-wait command cycle uses one of
them exclusively:

**(a) PCI config 0xD0 / 0xD4 index/data backdoor — HIGH confidence.**
`FUN_100363c0` (read), `FUN_10036520` (write byte), `FUN_10036830` (write dword),
`FUN_10037460` (read dword) all do the same thing: write the target offset
(dword-aligned) to **PCI config register 0xD0**, then read/write the data at **PCI
config register 0xD4**. Byte accesses select the lane at `0xD4 + (off & 3)`.

```
read_reg(off):   cfg[0xD0] = off & ~3 ; return cfg[0xD4] (lane off&3 for byte)
write_reg(off):  cfg[0xD0] = off & ~3 ; cfg[0xD4(+lane)] = val ; re-index ; flush-read
```

This is why offsets far past 512 bytes (0x867, 0xd62, 0xd66, 0x1000+) work despite the
small BAR5 — **they are indices into the controller's internal register space via the
config backdoor, not BAR5 offsets.**

Linux port: `asm_reg_linux.c` (`config` mode) does exactly this over
`/sys/bus/pci/devices/<slot>/config` with `pread`/`pwrite` at 0xD0/0xD4. **Default.**

**(b) direct BAR5 memory map — ALTERNATE, used only by the with-wait ack path.**
`GetASM2106BMmioBase` (`@100323e8`) reads BAR5 from PCI config **0x24-0x27**;
`getMmioMapAddress` (`@1003214a`) maps **0x2000** bytes there; `MmioReadDword`
(`@10032a91` → `FUN_10036f10`) reads BAR5-phys + offset via the AsmIo driver
(`_MapAsmIO_8`, `_ReadMEM_12`). The `asmiodll.dll` exports confirm the driver surface:
`_MapAsmIO@8`, `_ReadMEM@12`, `_ASMIO_Read_Memory@36`, `_ReadPortByte/Word/Long`.

Linux port: `asm_reg_linux.c` (`bar5` mode, `VESSAPP_MMIO_ACCESS=bar5`) mmaps
`/sys/bus/pci/devices/<slot>/resource5` — the path the task named.

> **UNRESOLVED / safety-relevant.** The live box reports **BAR5 size = 512**, but the
> with-wait ack path maps BAR5-phys **+ 0x1000** (`FUN_10036290`), and the mailbox the
> config path writes at index 0x1000 is the *same* mailbox. A 512-byte BAR5 cannot back
> an offset 0x1000 mailbox. Either the sysfs size is misreported or the mailbox is only
> reachable via the config backdoor. **The config backdoor is therefore the default and
> the trustworthy path; `bar5` mode is provided but must be confirmed on-device**
> (`verify-mmio.sh` dumps both read-only before any writes).

---

## 2. Command / ack frame — HIGH confidence

Command frame (16 bytes, written to the mailbox as 4 little-endian dwords):

| bytes | value | source |
|---|---|---|
| 0..7 | `"2106BCMD"` (32 31 30 36 42 43 4D 44) | `FUN_10035220` @10035220 |
| 8 | opcode | each IFCmd* sets `uStack_228` |
| 9..15 | 0 | — |

Ack frame (read back from the same mailbox at 0x1000):

| bytes | meaning | source |
|---|---|---|
| 0..7 | `"2106BACK"` signature (validated) | `thunk_FUN_10039b10(..,"2106BACK")` |
| 8 | status (0 = OK) | `if (ack[8] == 0)` in every IFCmd* |
| 9..15 | reserved | — |
| 0x10.. | payload (`memcpy(out, ack+0x10, N)`) | each IFCmd* |

## 3. Opcodes — HIGH confidence

| Command | opcode @8 | cmd len | ack read | payload (from ack+0x10) | RVA |
|---|---|---|---|---|---|
| GetCapabilities | **0x11** | 0x10 | 0x2e | 0x1e (30) | `IFCmdGetCapabilities @10032c35` |
| GetRAIDStatus | **0x15** | 0x10 | 0xb4 | 0xa4 (164) | `IFCmdGetRAIDStatus @10032f0f` |
| GetPortsStatus | **0x17** | 0x10 | 0x144 | 0x134 (308) | `IFCmdGetPortsStatus @10033450` |
| ModeChange (WRITE) | **0xB0** | 0x84 | 0x10 | — | `IFCmdModeChange @10032947` |

`ack_read = 0x10 header + payload`. **ModeChange (0xB0) is a destructive write** (32-byte
`_RAID_PARAMETER` block at cmd+0x0e); it is **documented but never issued** by the port —
`mmio_raid.c` only calls the GET opcodes.

## 4. Handshake state machine — HIGH confidence

From `FUN_10035680` (send) + `FUN_10037350` (recv), the no-wait path:

```
poll  reg 0x867 (byte) == 1              FW ready
write reg 0xd66 (byte)  = 1 ; poll == 1  Xdata ready
for i in 0..3: write32(0x1000 + i*4, cmd_dword[i])   command mailbox
write reg 0xd62 (byte)  = 1 ; poll == 0  command complete (doorbell/result)
for i: read32(0x1000 + i*4)              ack mailbox (same window)
write reg 0xd66 (byte)  = 0              release
```

| reg | role | source |
|---|---|---|
| **0x867** | FW-ready flag (poll==1) | `FUN_10035680` `local_24 = 0x867` |
| **0xd66** | Xdata-ready handshake | `thunk_FUN_10036520(.,0xd66,1)` |
| **0xd62** | command doorbell / result (poll==0) | `thunk_FUN_10036520(.,0xd62,1)` |
| **0x1000** | command + ack mailbox (shared) | write loop `+0x1000`; `FUN_10036290`/`FUN_10037460` read `+0x1000` |

`asm2106b.c` implements this verbatim. The poll bound (`ASM_POLL_MAX`) is a CALIBRATE
knob (the Windows code used `clock()` deadlines; here a bounded spin, instant on the mock).

## 5. UNRESOLVED (needs a live ack dump — `verify-mmio.sh`)

- **Payload field layouts.** The 30/164/308-byte GetCapabilities/RAIDStatus/PortsStatus
  payloads are `memcpy`'d into FW-defined structs (`_TypeS_MmioAck_Get*`) whose members
  are **not expanded** in the decomp. So the raw payloads are recovered with high
  confidence, but per-field decode (drive/port/array records) is **UNRESOLVED**. Do not
  invent offsets — dump a real ack with `verify-mmio.sh` and diff against a known state.
- **BAR5 size vs. mailbox offset** (see §1) — confirm which access method is live.
- Because of the above, `mmio_raid.c` reports an **empty (AHCI-mode) RAID view** once the
  controller is confirmed present (honest for this box, which runs the ASM2106B in AHCI),
  rather than fabricate array/logical-drive records. The structured decode is a follow-up
  gated on the live dump.
