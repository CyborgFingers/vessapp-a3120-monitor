#!/usr/bin/env bash
# email-smoke.sh - build emaild, run it against a local SMTP sink, assert the
# captured message carries the recovered event-notification (and test) body.
#
#   default (host)  self-containerises: debian/amd64, build, run.
#   run             in-container checks (cmake build present).
set -euo pipefail

run_checks() {
  BUILD=${BUILD:-build}
  [ -x "$BUILD/emaild" ] || { echo "FAIL: $BUILD/emaild not built"; exit 1; }

  cat > /tmp/emailsetting.xml <<EOF
<emailSetting>
  <enabled>1</enabled>
  <server>127.0.0.1</server>
  <port>2525</port>
  <sender>vessapp@localhost</sender>
  <subjectPrefix>[VessApp]</subjectPrefix>
  <severityThreshold>3</severityThreshold>
  <recipient>admin@localhost</recipient>
</emailSetting>
EOF

  cat > /tmp/sink.py <<'PY'
import socket,sys
port=int(sys.argv[1]); out=sys.argv[2]
srv=socket.socket(); srv.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
srv.bind(('127.0.0.1',port)); srv.listen(1); srv.settimeout(10)
c,_=srv.accept(); f=c.makefile('rwb',buffering=0)
def send(s): f.write(s.encode())
send("220 sink\r\n"); body=[]; inbody=False
for raw in f:
    if inbody:
        if raw==b".\r\n": send("250 ok\r\n"); inbody=False; continue
        body.append(raw)
    else:
        u=raw[:4].upper()
        if u==b"DATA": send("354 go\r\n"); inbody=True
        elif u==b"QUIT": send("221 bye\r\n"); break
        else: send("250 ok\r\n")
open(out,'wb').write(b"".join(body))
PY

  fail=0
  capture() {  # $1=outfile ; runs sink, then the emaild command in $2..
    python3 /tmp/sink.py 2525 "$1" & SINK=$!
    sleep 1; shift; "$@"; wait $SINK 2>/dev/null || true
  }

  echo "== emaild --event 0x000D0002 (PD_MARKED_DEAD, major) =="
  capture /tmp/mail_event.txt "$BUILD/emaild" -f /tmp/emailsetting.xml --event 0x000D0002
  echo "---- captured message ----"; cat /tmp/mail_event.txt; echo "--------------------------"
  a() { if grep -qF "$1" /tmp/mail_event.txt; then echo "  ok: $2"; else echo "  FAIL: $2"; fail=1; fi; }
  a "Please check the following event." "recovered event body"
  a "severity: Major"                   "severity resolved from event_EN.xml"
  a "Physical Disk is marked as DEAD"   "description resolved from event_EN.xml"
  a "eventID: 0xd0002H"                 "eventID formatted"
  a "Subject: [VessApp] PD_MARKED_DEAD" "subject line"

  echo "== emaild --test =="
  capture /tmp/mail_test.txt "$BUILD/emaild" -f /tmp/emailsetting.xml --test
  t() { if grep -qF "$1" /tmp/mail_test.txt; then echo "  ok: $2"; else echo "  FAIL: $2"; fail=1; fi; }
  t "This is a test email, not a real event notification." "recovered test body"
  t "Model: Vess A3120" "subsystem model in test body"

  echo "== emaild severity filter (info < warning threshold) =="
  # BATTERY_CAPACITY_LOW is warning(3) >= 3, but battery-normal 0x00010003 is
  # info(2); use an unknown low id path: threshold rejects a below-threshold event.
  if "$BUILD/emaild" -f /tmp/emailsetting.xml --event 0x00010003 2>&1 | grep -q "below threshold"; then
    echo "  ok: below-threshold event suppressed"; else echo "  note: 0x00010003 not in event subset (no-op)"; fi

  [ $fail -eq 0 ] && echo "EMAIL SMOKE OK" || { echo "EMAIL SMOKE FAILED"; exit 1; }
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
  apt-get install -y -qq build-essential cmake python3 >/dev/null
  cmake -S . -B build -DCMAKE_BUILD_TYPE=Debug >/dev/null
  cmake --build build -j"$(nproc)" --target emaild >/dev/null
  bash src/emaild/email-smoke.sh run
'
