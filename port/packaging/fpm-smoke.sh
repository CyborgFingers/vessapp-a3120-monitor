#!/usr/bin/env bash
# fpm-smoke.sh - the Phase-6 concurrency smoke (headless). Boots the production
# stack (nginx + PHP-FPM + php_i2 fixture) in a php:7.4-fpm container and:
#   - logs in (the dc is issued and consumed by DIFFERENT worker processes:
#     the pool runs pm.max_requests=1, so every request forks a fresh worker -
#     an airtight proof that the fork-shared dc/session store works),
#   - requests screen.php plus ALL of its frames CONCURRENTLY, three rounds,
#     asserting every frame returns 200 with no PHP error and the session holds
#     (exactly what timed out under single-threaded `php -S`).
#
# Usage: bash port/packaging/fpm-smoke.sh [port]     (default 8089)
set -euo pipefail
PORT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${1:-8089}"
IMG=php:7.4-fpm
MOUNT=(-v "$PORT_DIR":/work -w /work)
INNER=(bash /work/packaging/_fpm-inner.sh smoke "$PORT")

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  RUN=(docker run --rm --platform linux/amd64)
elif command -v container >/dev/null 2>&1; then
  RUN=(container run --rm --platform linux/amd64)
else
  echo "need a working docker daemon or Apple container" >&2; exit 1
fi
exec "${RUN[@]}" "${MOUNT[@]}" "$IMG" "${INNER[@]}"
