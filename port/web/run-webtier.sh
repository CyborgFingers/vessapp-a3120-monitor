#!/usr/bin/env bash
# run-webtier.sh - launch the WebPAM (WebPAM PROe) web tier on Linux for a live
# browser check (Phase 4). Serves the ported htdocs from a php:7.4-cli container
# (matching the shipped PHP 7.3) with the php_i2 extension loaded against the
# fixture substrate (seeded enclosure + RAID data, no hardware).
#
# Works with either Docker (publishes -p to localhost) OR Apple `container`
# (no host port mapping - the container gets its own 192.168.64.x IP, which this
# script resolves and prints). Ctrl-C stops it.
#
# Usage: bash port/web/run-webtier.sh [port]     (default 8088)
set -euo pipefail
PORT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${1:-8088}"
IMG=php:7.4-cli
MOUNT=(-v "$PORT_DIR":/work -w /work)
INNER=(bash /work/web/_webtier-inner.sh serve "$PORT")
[ -t 0 ] && TTY=(-it) || TTY=()   # only allocate a TTY when attached to one

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  echo "WebPAM: http://localhost:$PORT/index.php   (login: administrator / any password)"
  echo "        first launch builds the extension (~1 min). Ctrl-C to stop."
  exec docker run --rm "${TTY[@]}" --platform linux/amd64 -p "$PORT:$PORT" "${MOUNT[@]}" "$IMG" "${INNER[@]}"
elif command -v container >/dev/null 2>&1; then
  # Apple `container` has no docker-style -p host mapping; use the container IP.
  NAME="atb-webtier-$PORT"
  container rm -f "$NAME" >/dev/null 2>&1 || true
  container run --rm --name "$NAME" --platform linux/amd64 "${MOUNT[@]}" "$IMG" "${INNER[@]}" &
  RUNPID=$!
  trap 'container rm -f "$NAME" >/dev/null 2>&1 || true; kill "$RUNPID" 2>/dev/null || true' EXIT INT TERM
  IP=""
  for _ in $(seq 1 30); do
    IP=$(container list 2>/dev/null | awk -v n="$NAME" '$0 ~ n {print $6}' | cut -d/ -f1 | head -1)
    [ -n "$IP" ] && break
    sleep 1
  done
  echo "+--------------------------------------------------------------------+"
  echo "  Promise Vess A3120 WebPAM - Linux web tier (Phase 4, fixture mode)"
  echo "  URL:    http://${IP:-<resolving - run: container list>}:$PORT/index.php"
  echo "  Login:  username = administrator   password = (any - fixture auth)"
  echo "  Note:   first launch builds the extension (~1 min)."
  echo "  Stop:   Ctrl-C"
  echo "+--------------------------------------------------------------------+"
  wait "$RUNPID"
else
  echo "need a working docker daemon or Apple container" >&2; exit 1
fi
