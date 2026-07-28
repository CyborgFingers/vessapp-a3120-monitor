#!/usr/bin/env bash
# run-fpm.sh - launch the FULL WebPAM stack (nginx + PHP-FPM + php_i2 fixture)
# in a php:7.4-fpm container for a live browser check of the complete framed UI
# (Phase 6). Unlike port/web/run-webtier.sh (single-threaded `php -S`, frameset
# times out), this is the production topology: the post-login frameset - header,
# tree, home, event and daemon frames in parallel - loads.
#
# Works with either Docker (publishes -p to localhost) OR Apple `container`
# (no host port mapping - the container gets its own 192.168.64.x IP, which this
# script resolves and prints). Ctrl-C stops it.
#
# Usage: bash port/packaging/run-fpm.sh [port]     (default 8088)
set -euo pipefail
PORT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${1:-8088}"
IMG=php:7.4-fpm
MOUNT=(-v "$PORT_DIR":/work -w /work)
INNER=(bash /work/packaging/_fpm-inner.sh serve "$PORT")
[ -t 0 ] && TTY=(-it) || TTY=()   # only allocate a TTY when attached to one

banner() {
  echo "+----------------------------------------------------------------------+"
  echo "  Promise Vess A3120 WebPAM - FULL stack (nginx + php-fpm, fixture mode)"
  echo "  URL:    http://$1:$PORT/index.php"
  echo "  Login:  username = administrator   password = (any - fixture auth)"
  echo "  After login the complete FRAMESET loads (tree + panels + event bar)."
  echo "  Note:   first launch builds the extension (~1 min)."
  echo "  Stop:   Ctrl-C"
  echo "+----------------------------------------------------------------------+"
}

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  banner localhost
  exec docker run --rm "${TTY[@]}" --platform linux/amd64 -p "$PORT:$PORT" "${MOUNT[@]}" "$IMG" "${INNER[@]}"
elif command -v container >/dev/null 2>&1; then
  # Apple `container` has no docker-style -p host mapping; use the container IP.
  NAME="atb-fpm-$PORT"
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
  banner "${IP:-<resolving - run: container list>}"
  wait "$RUNPID"
else
  echo "need a working docker daemon or Apple container" >&2; exit 1
fi
