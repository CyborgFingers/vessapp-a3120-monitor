#!/usr/bin/env bash
# webpam-trixie-smoke.sh - end-to-end proof that install-webpam.sh works on the
# live box's EXACT OS: runs the real installer inside debian:trixie-slim
# (amd64, the A3120/Proxmox userland), then drives the installed stack with
# VESSAPP_BACKEND=smbus exactly as the box will run it:
#   apt deps -> phpize build on trixie PHP 8.4 -> htdocs+nginx+FPM layout ->
#   dc -> login -> screen frameset -> Subsystem + Enclosure pages render with
#   NO PHP fatals, and the drive/array pages are EMPTY (no demo fixture) -
#   the graceful no-RAID-engine behaviour the box needs until the ASM2106B
#   RAID-mode reboot.
#
# Usage: bash port/packaging/webpam-trixie-smoke.sh [port]   (default 8088)
set -euo pipefail
PORT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${1:-8088}"
IMG=debian:trixie-slim
MOUNT=(-v "$PORT_DIR":/work -w /work)
INNER=(bash /work/packaging/_webpam-trixie-inner.sh "$PORT")

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  RUN=(docker run --rm --platform linux/amd64)
elif command -v container >/dev/null 2>&1; then
  RUN=(container run --rm --platform linux/amd64)
else
  echo "need a working docker daemon or Apple container" >&2; exit 1
fi
exec "${RUN[@]}" "${MOUNT[@]}" "$IMG" "${INNER[@]}"
