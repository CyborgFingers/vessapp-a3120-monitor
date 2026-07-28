#!/usr/bin/env bash
# daemon-smoke.sh - runnable check for the Phase-5 catalog daemons + SLP reg.
# Runs each scaffold once and asserts the implemented (trivial) path fires.
#   default (host)  self-containerises; `run` = in-container checks.
set -euo pipefail

run_checks() {
  BUILD=${BUILD:-build}
  fail=0
  ck() { if echo "$2" | grep -qF "$1"; then echo "  ok: $3"; else echo "  FAIL: $3"; fail=1; fi; }

  echo "== bgasched -f bgasched.conf =="
  O=$("$BUILD/bgasched" -f src/daemons/bgasched.conf)
  echo "$O"
  ck "would schedule 'media-patrol" "$O" "bgasched loads schedule"
  ck "3 activities loaded"          "$O" "bgasched activity count"

  echo "== prevtlogd --event 0x00090003 (LD_OFFLINE) =="
  O=$("$BUILD/prevtlogd" --event 0x00090003)
  echo "$O"
  ck "logged event 0x90003 (LD_OFFLINE)" "$O" "prevtlogd syslog write"

  echo "== pwrmon --power-fail =="
  O=$("$BUILD/pwrmon" --power-fail)
  echo "$O"
  ck "ShutdownHBA requested" "$O" "pwrmon shutdown notify"

  echo "== SLP registration (src/slp/promise.reg) =="
  if grep -qE '^service:[a-z0-9._:-]+://[^,]+,[a-z]+,[0-9]+' src/slp/promise.reg; then
    echo "  ok: OpenSLP slp.reg service-url line well-formed"
  else echo "  FAIL: promise.reg service-url malformed"; fail=1; fi

  [ $fail -eq 0 ] && echo "DAEMON SMOKE OK" || { echo "DAEMON SMOKE FAILED"; exit 1; }
}

if [ "${1:-}" = "run" ]; then run_checks; exit $?; fi

PORT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  RUN=(docker run --rm --platform linux/amd64)
elif command -v container >/dev/null 2>&1; then
  RUN=(container run --rm --platform linux/amd64)
else echo "need docker or Apple container" >&2; exit 1; fi

"${RUN[@]}" -v "$PORT_DIR":/work -w /work debian:bookworm-slim bash -euo pipefail -c '
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq build-essential cmake >/dev/null
  cmake -S . -B build -DCMAKE_BUILD_TYPE=Debug >/dev/null
  cmake --build build -j"$(nproc)" --target bgasched pwrmon prevtlogd >/dev/null
  bash src/daemons/daemon-smoke.sh run
'
