# asmprobe on-box iteration — READ-ONLY steps to find a live `2106BACK`

For the parent to run on the live A3120 (`root@192.0.2.8`). **All steps below
are read-only** except where a step is explicitly the transient command
handshake (asmprobe's GET path, which never issues `ModeChange`). Do **not** run
anything that writes PCI config outside `0xD0/0xD4` indexing, and never issue
opcode `0xB0`. Background: `docs/RE-asm2106b-access.md`.

Set the slot once (default is `0000:02:00.0`):

```sh
export VESSAPP_ASM_PCI=0000:02:00.0
```

## 0. Confirm the geometry first (read-only)

```sh
lspci -nnvvs "$VESSAPP_ASM_PCI" | sed -n '1,30p'      # BARs, sizes, class
# BAR5 phys + size:
cat /sys/bus/pci/devices/$VESSAPP_ASM_PCI/resource | sed -n '6p'   # line 6 = BAR5 (start end flags)
```

Note BAR5's **size** = `end - start + 1`. If it is `0x1FF` (512) the vendor
mailbox at `+0x1000` is outside the ABAR — expected; that is the whole problem.

## 1. `devmem` — the faithful fix candidate (try this first)

Maps `/dev/mem` at BAR5-phys, length `0x2000` (the exact `MmMapIoSpace`
behaviour, not BAR-size-capped):

```sh
VESSAPP_MMIO_ACCESS=devmem ./asmprobe
```

- `guiSupport reg 0x842 = READY` and/or a `2106BACK` payload → **the window IS
  decoded physically; devmem is the answer.** Report the hexdump.
- If `asmprobe` can't even open (`cannot open ... register window`): `/dev/mem`
  mmap of BAR5 may be refused by `CONFIG_IO_STRICT_DEVMEM` because `ahci` has
  claimed the region. Check `zgrep IO_STRICT_DEVMEM /proc/config.gz` and
  `dmesg | grep -i devmem`. **Do NOT unbind ahci to work around this** (drops the
  SATA links on a live host). If blocked, the read-only `/dev/mem` python peek
  below (also `O_RDONLY`) may still be refused — then the only in-AHCI-mode
  evidence is step 2/3. A kernel booted with `iomem=relaxed` would allow it, but
  that needs a reboot — treat as a separate, scheduled action, not casual.

- `0x842 = 0x00`/`0xff` and timeouts → the ASM2106B does **not** decode
  `BAR5phys+0x1000` in AHCI mode. Note `/resource5` can't test this at all: it
  caps at one page and the mailbox at `0x1000` is exactly the next page.
  Confirm with a raw read-only peek:

```sh
# read-only dword dump of BAR5-phys .. +0x1010 (needs busybox devmem or python)
python3 - <<'PY'
import os,mmap,struct
base=0xdf110000   # <-- set to your BAR5 start from step 0
f=os.open("/dev/mem",os.O_RDONLY|os.O_SYNC)
m=mmap.mmap(f,0x2000,mmap.MAP_SHARED,mmap.PROT_READ,offset=base)
for off in (0x000,0x800,0x840,0x842,0x867,0xd60,0x1000,0x1004):
    print(hex(off), hex(struct.unpack_from("<I",m,off & ~3)[0]))
PY
```

`0xffffffff` across `0x840/0x867/0x1000` = window not present (RAID-mode
firmware absent). `0x00000000` everywhere = decoded but idle. Anything
structured (e.g. an ASCII-ish dword) at `0x1000` = the mailbox is live.

## 2. Re-check the config backdoor with correct indexing (read-only)

Verify the `0xD0/0xD4` backdoor really is dead (not a port bug):

```sh
setpci -s "$VESSAPP_ASM_PCI" D0.L=00000842   # index reg 0x842 (dword-aligned)
setpci -s "$VESSAPP_ASM_PCI" D4.L            # read data; byte lane 2 = reg 0x842
setpci -s "$VESSAPP_ASM_PCI" D0.L=00000864   # index reg 0x864
setpci -s "$VESSAPP_ASM_PCI" D4.L            # byte lane 3 = reg 0x867 (FW-ready)
```

All `00000000` → backdoor genuinely disabled in this mode (matches the RE
symptom). Then also try the codec through it:

```sh
VESSAPP_MMIO_ACCESS=config ./asmprobe
```

## 3. Inspect the I/O BARs (read-only) before trying `ioport`

The RE shows **no** I/O-port command path, so this is hypothesis-testing only.
First just *look* at the I/O windows (no writes):

```sh
# BAR0..BAR4 I/O bases from step 0's lspci (e050/e040/e030/e020/e000).
for p in 0xe000 0xe020 0xe030 0xe040 0xe050; do
  printf "%s: " $p
  # read-only inb via /dev/port (byte reads are safe on these SFF/BMIDE regs)
  dd if=/dev/port bs=1 count=1 skip=$((p)) 2>/dev/null | od -An -tx1
done
```

Then the experimental probe (defaults index=BAR4 base, data=+4). Sweep a few
candidate index/data pairs read-only via `asmprobe` (each run only reads 0x842
first; if it stays "not ready" the pair is wrong and no command is sent):

```sh
VESSAPP_MMIO_ACCESS=ioport ./asmprobe                                   # BAR4/BAR4+4
VESSAPP_MMIO_ACCESS=ioport VESSAPP_ASM_IOPORT_INDEX=0xe000 VESSAPP_ASM_IOPORT_DATA=0xe004 ./asmprobe
VESSAPP_MMIO_ACCESS=ioport VESSAPP_ASM_IOPORT_INDEX=0xe050 VESSAPP_ASM_IOPORT_DATA=0xe054 ./asmprobe
```

A `guiSupport reg 0x842 = READY` line under any pair = the I/O-port index/data
hypothesis holds; capture the pair. Otherwise the hypothesis is dead — stop.

## 4. What to report back

For each mode tried: the `guiSupport reg 0x842` line, whether a `2106BACK`
appeared, and any non-`0xff`/`0x00` dwords from the step-1 `/dev/mem` peek at
`0x800-0x1010`. That pins down whether the vendor window is (a) decoded at
BAR5-phys, (b) reachable via I/O ports, or (c) absent in AHCI mode (needs a
RAID-mode controller to answer at all).

## 5. Hard don'ts

- No `setpci` writes to anything but `D0.L` (indexing) — never `D4` writes here.
- Never run asmprobe's ModeChange (it has none) or opcode `0xB0` by any route.
- No `/dev/mem` **writes**. The peeks above are `O_RDONLY`.
