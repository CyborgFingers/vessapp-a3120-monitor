#!/usr/bin/env bash
# snmp-smoke.sh - boot snmpd + the Promise pass_persist subagent in a container,
# snmpwalk the raidv4 subtree, assert fixture values, and capture a trap.
#
# Two modes:
#   (default, from host/macOS)  self-containerises: debian/amd64, installs
#                               build-essential cmake snmp snmpd, builds, runs.
#   run                         in-container checks (tools + ./build present).
#
# Usage: bash port/src/snmpd/snmp-smoke.sh
set -euo pipefail

ROOT=".1.3.6.1.4.1.7933.1.20"
COMM=VessApp

# --------------------------------------------------------------- in-container
run_checks() {
  BUILD=${BUILD:-build}
  [ -x "$BUILD/promise_snmp" ] || { echo "FAIL: $BUILD/promise_snmp not built"; exit 1; }
  [ -x "$BUILD/promise_trap" ] || { echo "FAIL: $BUILD/promise_trap not built"; exit 1; }
  cp "$BUILD/promise_snmp" /usr/local/bin/promise_snmp
  TRAP="$BUILD/promise_trap"

  cat > /tmp/smoke-snmpd.conf <<EOF
rocommunity $COMM
pass_persist $ROOT /usr/local/bin/promise_snmp
EOF

  pkill -f 'snmpd .*smoke-snmpd' 2>/dev/null || true
  # I2BDG_MOCK=1 in snmpd's env is inherited by the pass_persist child.
  env I2BDG_MOCK=1 snmpd -f -Lf /tmp/snmpd.log -C -c /tmp/smoke-snmpd.conf \
      udp:127.0.0.1:16100 &
  SNMPD=$!
  trap 'kill $SNMPD 2>/dev/null || true' EXIT
  sleep 1

  export MIBS=   # numeric only; no MIB files needed
  echo "== snmpwalk $ROOT =="
  OUT=$(snmpwalk -v2c -c "$COMM" -On -t 3 -r 1 127.0.0.1:16100 "$ROOT")
  echo "$OUT"

  fail=0
  assert() { if echo "$OUT" | grep -qF "$1"; then echo "  ok: $2"; else echo "  FAIL: $2 ($1)"; fail=1; fi; }
  # subsystem model + vendor (System.2 = SubsystemTable); snmpwalk quotes STRINGs
  assert "$ROOT.1.2.1.4.1 = STRING: \"Vess A3120\""  "subsystem model = Vess A3120"
  assert "$ROOT.1.2.1.3.1 = STRING: \"Promise\""     "subsystem vendor = Promise"
  # disk array DA7 (Storage.4 alias col, index 1.7)
  assert "$ROOT.2.4.1.2.1.7 = STRING: \"DA7\""       "disk array DA7"
  assert "$ROOT.2.4.1.2.1.9 = STRING: \"DA9\""       "disk array DA9"
  # physical drive model + capacity Counter64 (Storage.1)
  assert "ST4000NM0023"                              "phys drive model"
  assert "$ROOT.2.1.1.17.1.1 = Counter64: 512000"    "phys drive capacity"
  # logical drive alias + RAID level 5 (Storage.5)
  assert "SystemVolume"                              "logical drive alias"
  assert "$ROOT.2.5.1.9.1.0 = INTEGER: 5"            "logical drive RAID5"
  # enclosure fan speed + temperature (System.11 / System.13)
  assert "$ROOT.1.13.1.2"                            "temperature sensor present"
  assert "Cooling Fan"                               "cooling fan present"
  # controller model from the ctrl-info page product@224 (System.3)
  assert "$ROOT.1.3.1.5.1.1 = STRING: \"Vess A3120\"" "controller model (ctrl page@224)"

  echo "== trap: raidv4TrapMessage (PD_MARKED_DEAD 0x000D0002) =="
  # dependency-free UDP sink: capture one datagram, assert ASCII varbinds.
  python3 - <<'PY' &
import socket
s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM); s.bind(('127.0.0.1',16200))
s.settimeout(6)
try:
    d,_=s.recvfrom(65535)
    open('/tmp/trap.bin','wb').write(d)
except Exception as e:
    open('/tmp/trap.bin','wb').write(b'')
PY
  SINK=$!
  sleep 1
  "$TRAP" -e 0x000D0002 -s 'Enclosure 1 Slot 3' \
      -q 42 -h 127.0.0.1:16200 -c "$COMM" || { echo "  FAIL: promise_trap exec"; fail=1; }
  wait $SINK 2>/dev/null || true
  printable=$(tr -c '[:print:]' ' ' < /tmp/trap.bin 2>/dev/null || true)
  tassert() { if printf '%s' "$printable" | grep -qF "$1"; then echo "  ok: $2"; else echo "  FAIL: $2"; fail=1; fi; }
  [ -s /tmp/trap.bin ] && echo "  (captured $(wc -c </tmp/trap.bin) byte trap PDU)" || { echo "  FAIL: no trap captured"; fail=1; }
  tassert "Enclosure 1 Slot 3"          "trap carries raidv4TrapSource"
  tassert "Physical Disk is marked as DEAD" "trap carries raidv4TrapDescription"

  [ $fail -eq 0 ] && echo "SNMP SMOKE OK" || { echo "SNMP SMOKE FAILED"; exit 1; }
}

# --------------------------------------------------------------- host wrapper
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
  apt-get install -y -qq build-essential cmake snmp snmpd sg3-utils libsgutils2-dev python3 >/dev/null
  cmake -S . -B build -DCMAKE_BUILD_TYPE=Debug >/dev/null
  cmake --build build -j"$(nproc)" --target promise_snmp promise_trap >/dev/null
  bash src/snmpd/snmp-smoke.sh run
'
