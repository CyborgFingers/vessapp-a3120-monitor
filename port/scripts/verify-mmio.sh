#!/usr/bin/env bash
#
# verify-mmio.sh - READ-ONLY on-device check of the ASM2106B MMIO command path.
#
# Confirms the PCI device + register window are reachable, then runs `asmprobe`
# which issues ONLY the non-destructive GET commands (GetCapabilities /
# GetRAIDStatus / GetPortsStatus) and hex-dumps each ack. It NEVER issues
# IFCmdModeChange or any RAID-config write; the only register writes are the
# transient command-mailbox handshake required to solicit a status read.
#
# Use the ack hex dumps to resolve the payload field layout (currently
# UNRESOLVED - see docs/RE-asm2106b.md) before wiring structured RAID decode.
#
# Usage: sudo VESSAPP_ASM_PCI=0000:02:00.0 VESSAPP_MMIO_ACCESS=config ./verify-mmio.sh
#   VESSAPP_MMIO_ACCESS=config (default, PCI 0xD0/0xD4 backdoor) | bar5 (mmap resource5)
# Needs: root; asmprobe (built by ../build-in-container.sh -> build/asmprobe).
set -euo pipefail

SLOT="${VESSAPP_ASM_PCI:-0000:02:00.0}"
ACCESS="${VESSAPP_MMIO_ACCESS:-config}"
DEV="/sys/bus/pci/devices/$SLOT"

echo "=== verify-mmio.sh  (READ-ONLY - GET commands only, no ModeChange) ==="
echo "PCI slot: $SLOT   access method: $ACCESS"

if [ ! -d "$DEV" ]; then
  echo "ERROR: $DEV not found. List candidates:" >&2
  lspci -Dnn 2>/dev/null | grep -iE "asmedia|1b21:0612" || true
  exit 1
fi

echo
echo "--- device identity (read-only) ---"
printf "  vendor:device = %s:%s\n" "$(cat "$DEV/vendor")" "$(cat "$DEV/device")"
[ -r "$DEV/resource" ] && awk 'NR==6{printf "  BAR5 = %s (resource line 6)\n",$0}' "$DEV/resource"
if [ -e "$DEV/resource5" ]; then
  printf "  resource5 size = %s bytes\n" "$(stat -c %s "$DEV/resource5" 2>/dev/null || echo '?')"
fi

echo
echo "--- register-window reachability (read-only) ---"
if [ "$ACCESS" = "config" ]; then
  if command -v setpci >/dev/null 2>&1; then
    echo "  config BAR5 (0x24.l) = 0x$(setpci -s "$SLOT" 0x24.l 2>/dev/null || echo '????????')"
    echo "  vendor index reg 0xD0.l = 0x$(setpci -s "$SLOT" 0xd0.l 2>/dev/null || echo '????????')"
    echo "  (the 0xD0/0xD4 index/data pair is the command backdoor; see RE-asm2106b.md)"
  else
    echo "  (setpci not found - install pciutils for the config-space peek)"
  fi
else
  echo "  will mmap $DEV/resource5 (BAR5). NOTE: RE shows the mailbox lives at"
  echo "  offset 0x1000, which exceeds a 512-byte BAR5 - if this fails, the"
  echo "  config backdoor is the real path (VESSAPP_MMIO_ACCESS=config)."
fi

echo
echo "--- issuing GET commands (asmprobe) ---"
ASMPROBE="${ASMPROBE:-}"
if [ -z "$ASMPROBE" ]; then
  for c in ./build/asmprobe ../build/asmprobe "$(dirname "$0")/../build/asmprobe" \
           "$(dirname "$0")/../../build/asmprobe"; do
    [ -x "$c" ] && ASMPROBE="$c" && break
  done
fi
if [ -z "$ASMPROBE" ] || [ ! -x "$ASMPROBE" ]; then
  echo "asmprobe not found. Build it first:  (cd port && bash build-in-container.sh)"
  echo "then re-run, or pass ASMPROBE=/path/to/asmprobe."
  exit 2
fi

VESSAPP_ASM_PCI="$SLOT" VESSAPP_MMIO_ACCESS="$ACCESS" "$ASMPROBE"

echo "=== done (only GET commands issued; no RAID config changed) ==="
