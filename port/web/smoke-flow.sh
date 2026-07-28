#!/usr/bin/env bash
# smoke-flow.sh - headless proof of the WebPAM web tier (Phase 4). Runs entirely
# inside a php:7.4-cli container (matching the shipped PHP 7.3): builds the
# php_i2 extension, serves the ported htdocs against the fixture substrate, then
# curls dc -> login -> arrays.php + subsystem.php and asserts the rendered data.
# No host port mapping and no browser needed. Exit 0 = pass.
#
# Usage: bash port/web/smoke-flow.sh [port]
set -euo pipefail
PORT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${1:-8088}"
IMG=php:7.4-cli

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  RUN=(docker run --rm --platform linux/amd64)
elif command -v container >/dev/null 2>&1; then
  RUN=(container run --rm --platform linux/amd64)
else
  echo "need a working docker daemon or Apple container" >&2; exit 1
fi

exec "${RUN[@]}" -v "$PORT_DIR":/work -w /work "$IMG" \
  bash /work/web/_webtier-inner.sh smoke "$PORT"
