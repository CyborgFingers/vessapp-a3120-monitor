# RE findings: ASM2106B command-window ACCESS method (`106BCommandLib.dll` + `raidmon`)

Recovery of the *access / mapping* method for the ASMedia **ASM2106B** RAID
controller (PCI `02:00.0`, `1b21:0612`, subsystem `1b21:1060`, bound to generic
`ahci`) so `asmprobe` can solicit a valid `2106BACK` reply on the bare Linux
A3120 boxes.

Binaries decompiled (Ghidra 12.1.2 headless, `re/scripts/decomp.sh`) from
`reference/raid-driver-stuff/raidmon/`:

| binary | decomp | role |
|---|---|---|
| `106BCommandLib.dll` (PE32 x86) | `re/out/106b_x86.c` | the ASM2106B command codec |
| `raidmon.exe` (PE32 x86) | `re/out/raidmon_x86.c` | the app that drives it |
| `asmiodll.dll` (PE32 x86) | `re/out/asmiodll_x86.c` | user->driver IOCTL shim |
| `AsmIo.sys` (PE32 x86 driver) | `re/out/asmio_sys_x86.c` | the kernel hardware primitive |

Everything below is cited to a function + RVA/IOCTL with a confidence. **This
supersedes the primary/alternate ordering in `RE-asm2106b.md`** (which had the
config backdoor as primary — it is the *alternate*).

---

## 0. TL;DR — what was wrong, what is right

- **The recovered access primitive is physical MMIO on BAR5**, not the config
  backdoor and not I/O ports. `raidmon.exe` runs the *memory-mapped* path.
- The base is **BAR5 physical** (PCI cfg `0x24-0x27`), and the mailbox is at
  **BAR5-phys + 0x1000**, handshake regs at **+0x867 / +0xd62 / +0xd66**.
- On the live box that fails because BAR5 is a **512-byte** AHCI ABAR — every
  vendor offset (even the first, `+0x867 = 2151`) is outside the decoded window.
  The *method* is faithful; the *window is simply not exposed in plain AHCI mode.*
- Windows reaches it with `MmMapIoSpace(BAR5phys, 0x2000)` — mapping the **raw
  physical page**, independent of the OS's BAR-size view. The Linux `bar5` mode
  used `/resource5`, which is hard-capped at the BAR size (→ `rc=-34`). The
  faithful port is **`/dev/mem` at BAR5-phys** (new `devmem` mode).
- **There is no software "enable" step.** `raidmon.exe` imports no write
  primitive at all; the only controller writes in the whole flow are the
  transient handshake+mailbox writes inside the command codec.

---

## 1. Base computation — BAR5 physical (HIGH confidence)

`GetASM2106BMmioBase` (`@100323e8`, exported `?GetASM2106BMmioBase@...`):

```c
b3 = PCI_Read_BYTE(bus,dev,fn, 0x27);   base  = b3 << 24;
b2 = PCI_Read_BYTE(bus,dev,fn, 0x26);   base |= b2 << 16;
b1 = PCI_Read_BYTE(bus,dev,fn, 0x25);   base |= b1 << 8;
b0 = PCI_Read_BYTE(bus,dev,fn, 0x24);   return base | b0;      /* raw, unmasked */
```

PCI config `0x24` is **BAR5**. On the live box that reads `0xdf110000`. It is
returned raw (low flag bits included; they are 0 for this 16-byte-aligned mem BAR).

`getMmioMapAddress` (`@1003214a`, the entry raidmon calls):

```c
base = GetASM2106BMmioBase(pci);      /* BAR5 phys */
va   = MapAsmIO(base, 0x2000);        /* map 8 KB there */
return va;
```

> **The `0x1000` mailbox offset applies to BAR5-phys.** `config 0x44 = 0x02116160`
> is a **red herring** — grep confirms neither `106BCommandLib` nor `raidmon`
> ever reads cfg `0x44`; it is not the base. The I/O-port BARs (`e000-e057`) are
> **never referenced anywhere in `windows-src`.**

## 2. Which access path is live — memory-mapped, mode = 1 (HIGH confidence)

Each `IFCmd*` takes a **mode selector** (2nd arg) and the **mapped base** (4th).
`IFCmdGetCapabilities` (`@10032c35`):

```c
if (mode == 0) { send = FUN_10035680;  recv = FUN_10037350; }   /* CONFIG backdoor */
else           { send = FUN_10035bc0;  recv = FUN_10036290; }   /* MEMORY-MAPPED   */
```

`raidmon.exe` (`FUN_00402330` GetRAIDStatus, `FUN_004024a0` GetPortsStatus):

```c
base = getMmioMapAddress(pci);                       /* maps BAR5 */
IFCmdGetRAIDStatus (cmdpkt, &pci, '\x01', ack, base);/* mode = 1  => MEMORY path */
IFCmdGetPortsStatus(cmdpkt, &pci, '\x01', ack, base);
releaseMmioMapAddress(base);
```

So the shipped, primary path is **memory-mapped BAR5** (`mode = 1`). The config
`0xD0/0xD4` backdoor is the *alternate* (`mode = 0`), for environments without
the mapped window.

Device discovery (`raidmon FUN_00401xxx @~805`): scans bus 0..0xff × dev 0..0x1f,
fn 0, reads cfg dword 0 (VID/DID), matching `1b21:{0620..0626,1601,1602,1611,1612}`.
(The live chip is `1b21:0612` — a sibling not in raidmon's own table, but the
`106BCommandLib` codec is the same family.)

## 3. The hardware primitive — `MmMapIoSpace` + `READ_REGISTER` (HIGH confidence)

`asmiodll.dll` IOCTLs → `AsmIo.sys` handler (`re/out/asmio_sys_x86.c`):

| asmiodll export | IOCTL | AsmIo.sys does |
|---|---|---|
| `_MapAsmIO@8` | `0x80002000` | **`MmMapIoSpace(phys, size)`** (line ~128) |
| `_ReadMEM@12` / `_WriteMEM@12` | `0x80002028` / `2c` | **`READ_REGISTER_UCHAR/USHORT/ULONG`** / `WRITE_REGISTER_*` (line ~294) |
| `_PCI_Read_DWORD@16` / `_PCI_Write_DWORD@20` | `0x80002034` / `38` | `WRITE_PORT_ULONG(0xCF8,addr)` + `READ_PORT_ULONG(0xCFC)` — legacy PCI cfg (line ~570) |
| `_ReadPortByte/Word/Long@4` | `0x80002008` | `READ_PORT_UCHAR/USHORT/ULONG` (line ~193) |

So `MapAsmIO`+`ReadMEM`/`WriteMEM` = **genuine MMIO on the raw physical BAR5
address** (`MmMapIoSpace` ignores the OS BAR-size view). The `ReadPort*`
primitives exist but are **not on the ASM2106B command path** — no I/O-port
command interface is used.

Core mem helpers in `106BCommandLib`:
`FUN_10036b50` (WriteMEM core): `MapAsmIO(physaddr,0x1000)` → `WriteMEM` → `UnmapAsmIO`.
`MmioReadDword` (`@10032a91`) → `FUN_10036f10` → `MapAsmIO`+`ReadMEM`.

## 4. Handshake + mailbox — memory-mapped send/recv (HIGH confidence)

`FUN_10035bc0` (mem SEND) computes every register as `GetASM2106BMmioBase + off`:

```
mailbox  = base + 0x1000     fw_ready = base + 0x867
doorbell = base + 0xd62      xdata    = base + 0xd66

poll  byte[0x867] == 1                              FW ready
set   byte[0xd66] = 1 ; poll == 1                   Xdata ready
for i: write32(0x1000 + i*4, cmd_dword[i])          command mailbox
set   byte[0xd62] = 1 ; Sleep(100) ; poll == 0      doorbell / complete
```

`FUN_10036290` (mem RECV): `for i: MmioReadDword(base + 0x1000 + i*4)`, then
clear `byte[0xd66]`. Frames are identical to the config path:

| bytes | command | ack |
|---|---|---|
| 0..7 | `"2106BCMD"` (`FUN_10035220`) | `"2106BACK"` (validated `thunk_FUN_10039b10`) |
| 8 | opcode | status (0 = OK) |
| 0x10.. | — | payload (`memcpy(out, ack+0x10, N)`) |

Opcodes (unchanged, HIGH): GetCapabilities `0x11`/payload `0x1e`; GetRAIDStatus
`0x15`/`0xa4`; GetPortsStatus `0x17`/`0x134`; **ModeChange `0xB0` = DESTRUCTIVE
write, never issued.** New calibration detail: the mem path inserts `Sleep(100)`
after the doorbell before polling (the config path does not) — the bounded spin
in `asm2106b.c` already covers this, raise `ASM_POLL_MAX` if a real FW is slower.

## 5. Is there an enable / mode step? — NO software enable (HIGH confidence)

- `raidmon.exe` imports from `asmiodll` **only** `PCI_Read_DWORD`, `LoadAsmIODriver`,
  `UnloadAsmIODriver` — **no write primitive exists in raidmon at all.** Its whole
  flow is read-only except the codec's transient handshake writes.
- No `PCI_Write` / `WriteMEM` / `WritePort` / `IFCmdModeChange` precedes the GETs.
- The closest thing to a gate is **read-only**: `guiSupport` (`@100328a2`) polls
  internal reg **`0x842`** for `(v & 4)==4 && (v & 8)==0` (vendor interface ready),
  retrying 5× / `Sleep(100)`. It writes nothing. `asmprobe` now reports `0x842`
  as a liveness signal.

**Conclusion:** the vendor MMIO window's presence is a function of the
controller's firmware/strap **mode**, not a software toggle we can safely flip.
The only state-changing command is the destructive `IFCmdModeChange (0xB0)` — do
**not** issue it (it would rewrite the array config). No `--enable` path is
implemented because none is warranted by the RE.

## 6. Why the live box is silent, and the fix candidates

`GetASM2106BMmioBase` → `0xdf110000`, then vendor offsets at `+0x867 … +0x1000`.
On the live box **BAR5 size = 512 (`0x200`)** — an ordinary AHCI ABAR. Note
`getMmioMapAddress` maps **`0x2000`** (8 KB) at that base: the vendor code itself
expects an ≥8 KB BAR5 (the vendor register window), which only exists when the
controller runs its **RAID/vendor firmware**. In plain AHCI mode BAR5 is the
512-byte ABAR and the mailbox physically isn't there. So:

- `config` mode → the `0xD0/0xD4` backdoor reads `0` (dead in AHCI mode) → the
  `0x867` poll never sees 1 → **FW handshake timeout**.
- `bar5` mode (`/resource5`, capped at 512) → offset `0x867/0x1000` out of range
  → **`rc=-34`**.

Candidate fixes, in order (all read-only to try):

1. **`devmem`** (new, faithful): map `/dev/mem` at BAR5-phys, len `0x2000` — the
   exact `MmMapIoSpace` behaviour, *not* size-capped. This answers **iff the
   ASM2106B actually decodes `BAR5phys+0x1000` on this board** (open question —
   the parent tests it read-only). If BAR5 truly decodes only 512 bytes in AHCI
   mode, this reads `0xffffffff` and the honest answer is "vendor window not
   exposed without RAID-mode firmware."
2. **`ioport`** (new, EXPERIMENTAL / UNPROVEN): an index/data backdoor over the
   controller's I/O BARs, by analogy to the cited cfg `0xD0/0xD4` mechanism —
   included **only** so the operator can rule the hypothesis in/out. No RE
   evidence supports it; the index/data port pair is a guess (defaults to BAR4
   base / +4, override with `VESSAPP_ASM_IOPORT_INDEX/_DATA`).

## 7. Ack struct decode — still UNRESOLVED

The `_TypeS_MmioAck_Get{Capabilities,RaidStatus,PortsStatus}` field layouts are
**not** in the decomp — they are `memcpy`'d blobs. Confirming this: `raidmon`'s
own dumper `FUN_00401710` just **hex-dumps** the payload (`"%04x-%04x:"` + `"%02x"`
+ `"(DATA length %d)"`) — the vendor tool never decodes fields either. So the raw
payloads (30 / 164 / 308 bytes) are HIGH confidence; per-field decode needs a
live ack dumped against a known array state. `mmio_raid.c` continues to report an
empty AHCI-mode view rather than fabricate records.

## 8. Provenance quick-reference

| item | source |
|---|---|
| base = BAR5 phys (cfg 0x24-0x27) | `GetASM2106BMmioBase @100323e8` |
| map 0x2000 @ base | `getMmioMapAddress @1003214a` |
| memory path chosen (mode=1) | `raidmon FUN_00402330 / FUN_004024a0`; `IFCmdGetCapabilities @10032c35` |
| MMIO = MmMapIoSpace + READ_REGISTER | `AsmIo.sys` IOCTL 0x80002000 / 0x80002028 |
| PCI cfg = 0xCF8/0xCFC | `AsmIo.sys` IOCTL 0x80002034 (~line 570) |
| config backdoor (alternate) | `FUN_100363c0/10036520/10036830/10037460` |
| handshake 0x867/0xd62/0xd66, mailbox 0x1000 | `FUN_10035bc0` (send) / `FUN_10036290` (recv) |
| no enable step / raidmon read-only | raidmon import table (asmiodll: Read/Load/Unload only) |
| readiness gate reg 0x842 (read-only) | `guiSupport @100328a2` |
| ack structs opaque; vendor hexdumps | `raidmon FUN_00401710` |
