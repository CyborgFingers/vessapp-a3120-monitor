#!/usr/bin/env bash
# _webpam-trixie-inner.sh - runs INSIDE debian:trixie-slim (cwd /work = port/).
# Executes the REAL install-webpam.sh (it detects the missing systemd and skips
# the service/udev steps), then boots php-fpm + nginx by hand with the same
# environment the systemd drop-in provides on the box (VESSAPP_BACKEND=smbus),
# and asserts the box flow: login -> frameset -> Subsystem/Enclosure pages
# render, drive/array pages are EMPTY-but-clean (no demo fixture, no fatals).
# In here /dev/i2c-0 and the hwmon tree do not exist, so the smbus reads
# return zero elements - which is exactly the "must not 500" degradation the
# enclosure pages have to survive.
#
# Not meant to be run on the host.
set -euo pipefail
PORT="${1:-8088}"
export DEBIAN_FRONTEND=noninteractive

echo "== run the REAL installer on trixie =="
bash /work/packaging/install-webpam.sh
# curl is a harness dep (the box has it; trixie-slim does not)
apt-get install -y -qq curl >/dev/null
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "== trixie PHP is $PHPV =="

echo "== boot the stack as the box's systemd would =="
# the php-fpm unit drop-in does EnvironmentFile=/etc/promise/promise.env
set -a; . /etc/promise/promise.env; set +a
[ "${VESSAPP_BACKEND:-}" = smbus ] || { echo "FATAL: promise.env lacks VESSAPP_BACKEND=smbus"; exit 1; }
[ "$PORT" = 8088 ] || sed -i "s/listen 8088;/listen $PORT;/" /etc/nginx/sites-available/promise-webpam.conf
mkdir -p /run/php
"php-fpm$PHPV" -F &
FPM=$!
trap 'kill $FPM 2>/dev/null || true; nginx -s stop 2>/dev/null || true' EXIT
nginx

BASE="http://127.0.0.1:$PORT"; CJ=/tmp/cj; T=/tmp/webpam-smoke; rm -rf "$CJ" "$T"; mkdir -p "$T"
for i in $(seq 1 40); do curl -fs -o /dev/null "$BASE/index.php" && break; sleep 0.25; done

fail() { echo "TRIXIE SMOKE FAIL: $1"; exit 1; }
has()  { echo "$2" | grep -qE "$1"; }

echo "== 0) php_i2 loaded on trixie PHP under the FPM config =="
# the installer targets the FPM SAPI only; scan its conf.d, not the CLI's
PHP_INI_SCAN_DIR="/etc/php/$PHPV/fpm/conf.d" php -m | grep -qx i2 \
  || fail "php_i2 did not load on PHP $PHPV (fpm conf.d)"

echo "== 1) GET index.php (login challenge) =="
IDX=$(curl -s -c "$CJ" "$BASE/index.php")
has "Fatal error|Parse error|Warning:" "$IDX" && fail "index.php emitted a PHP error"
has 'dynccode="[^"]' "$IDX" || fail "no dynamic code rendered on login page"
HN1=$(printf '%s' "$IDX" | sed -nE 's/.*id="hn1" value="([0-9]+)".*/\1/p' | head -1)
CSRF=$(printf '%s' "$IDX" | sed -nE 's/.*name="csrftoken" value="([-0-9]+)".*/\1/p' | head -1)
[ -n "$HN1" ] && [ -n "$CSRF" ] || fail "could not extract dc id / csrf"
echo "   dc id=$HN1 csrf=$CSRF"

echo "== 2) POST login (administrator / any password) =="
LOGIN=$(curl -s -b "$CJ" -c "$CJ" \
  --data-urlencode "login=administrator" --data-urlencode "md5password=deadbeef" \
  --data-urlencode "hn1=$HN1" --data-urlencode "csrftoken=$CSRF" \
  --data-urlencode "defaultpw=0" --data-urlencode "submit=Login" \
  "$BASE/index.php?ssl=0")
has "Fatal error|Parse error|Warning:" "$LOGIN" && fail "login emitted a PHP error"
has "loginfail|csrf" "$LOGIN" && fail "login rejected"
has "screen.php" "$LOGIN" || fail "login did not redirect to screen.php"
echo "   login OK -> screen.php"

echo "== 3) screen.php frameset =="
SCR=$(curl -s -b "$CJ" "$BASE/screen.php")
has "Fatal error|Parse error|Warning:" "$SCR" && fail "screen.php emitted a PHP error"
has "URL=index.php" "$SCR" && fail "screen.php bounced to login"
echo "   frameset OK"

echo "== 4) the box pages: tree + subsystem + ENCLOSURE (smbus) + empty RAID =="
# NOTE: front.php is an include-library (phydrvs.php et al include it); a direct
# GET fatals on the vendor stack too (top-level gprop() with no includes). The
# enclosure FRONT VIEW is asserted via phydrvs.php below.
PAGES="treeframe.php home.php event.php daemon.php subsystem.php?ssid=0 subsystems.php encls.php?ssid=0 encl.php?ssid=0&ecid=0 arrays.php?ssid=0 phydrvs.php?ssid=0 logdrvs.php?ssid=0 controllers.php?ssid=0 fancontrol.php?ssid=0&ecid=0 fancontrolt.php?ssid=0&ecid=0 fancalibrate.php?ssid=0&ecid=0 frontpanel.php?ssid=0&ecid=0"
for f in $PAGES; do
  out="$T/$(echo "$f" | tr '/?=&' '____')"
  CODE=$(curl -s -b "$CJ" -o "$out.body" -w '%{http_code}' "$BASE/$f")
  BODY=$(cat "$out.body")
  [ "$CODE" = 200 ] || fail "$f returned HTTP $CODE"
  [ -s "$out.body" ] || fail "$f returned an empty body"
  if has "Fatal error|Parse error|Warning:" "$BODY"; then
    echo "$BODY" | grep -E "Fatal error|Parse error|Warning" | sed 's/<[^>]*>//g' | head -4
    fail "$f emitted a PHP error"
  fi
  has "top.location.reload|URL=index.php" "$BODY" && fail "$f bounced to login (session lost)"
  echo "   $f: 200, no PHP errors"
done

echo "== 5) enclosure pages carry the REAL-chassis identity (smbus) =="
has "ENC_TYPE_VESS_1U4BAY" "$(cat "$T/encls.php_ssid_0.body")" \
  || fail "encls.php lost the enclosure identity"
has "ENC_TYPE_VESS_1U4BAY" "$(cat "$T/encl.php_ssid_0_ecid_0.body")" \
  || fail "encl.php lost the enclosure identity"
has "enclosureFrontView_1U4_A3120" "$(cat "$T/phydrvs.php_ssid_0.body")" \
  || fail "phydrvs.php did not render the 1U4 A3120 enclosure front view"
SLOTS=$(grep -c 'li id=pdimg' "$T/phydrvs.php_ssid_0.body" || true)
[ "$SLOTS" = 4 ] || fail "front view renders $SLOTS drive slots, want 4 (1U4BAY)"
has "Powered On and Functional" "$(cat "$T/encl.php_ssid_0_ecid_0.body")" \
  || fail "encl.php lost the golden PSU status"
has "Vess" "$(cat "$T/subsystem.php_ssid_0.body")" || fail "subsystem.php lost the model"

echo "== 6) NO demo fixture data anywhere (backend=smbus, no RAID engine) =="
for b in arrays.php_ssid_0 phydrvs.php_ssid_0 logdrvs.php_ssid_0 treeframe.php; do
  has "DA7|ST4000NM0023|SystemVolume" "$(cat "$T/$b.body")" \
    && fail "$b still shows demo fixture data"
done
echo "   drive/array pages render EMPTY (graceful no-drives)"

echo "== 7) patch pages: fan control + front-panel LED assignment =="
# the installer must have seeded the shared /etc/promise config files
[ -f /etc/promise/fanconfig.json ]   || fail "installer did not seed fanconfig.json"
[ -f /etc/promise/frontpanel.json ]  || fail "installer did not seed frontpanel.json"
grep -q '^PROMISE_FANCONFIG=/etc/promise/fanconfig.json' /etc/promise/promise.env \
  || fail "promise.env not pointed at the shared fanconfig"
has "CPU Fan Curve" "$(cat "$T/fancontrol.php_ssid_0_ecid_0.body")" \
  || fail "fancontrol.php lost the fan curves"
has "135" "$(cat "$T/fancontrol.php_ssid_0_ecid_0.body")" \
  || fail "fancontrol.php does not show the seeded CPU curve (Speedlevel 135)"
has "Fan Speed Profile" "$(cat "$T/fancontrolt.php_ssid_0_ecid_0.body")" \
  || fail "fancontrolt.php lost the profile editor"
has "Cooling Units" "$(cat "$T/fancontrolt.php_ssid_0_ecid_0.body")" \
  || fail "fancontrolt.php lost the fan enable/disable toggles"
has "Calibration Status" "$(cat "$T/fancalibrate.php_ssid_0_ecid_0.body")" \
  || fail "fancalibrate.php lost the calibration fold"
has "ETH 1 LED" "$(cat "$T/frontpanel.php_ssid_0_ecid_0.body")" \
  || fail "frontpanel.php lost the ETH LED folds"
# the enclosure tab bar must carry the two new tabs (common.php menu lines)
has "fancontrol.php" "$(cat "$T/encl.php_ssid_0_ecid_0.body")" \
  || fail "encl.php tab bar is missing the Fan Control tab"
has "frontpanel.php" "$(cat "$T/encl.php_ssid_0_ecid_0.body")" \
  || fail "encl.php tab bar is missing the Front Panel LEDs tab"
echo "   fan pages + frontpanel.php render with content; enclosure tabs wired"

echo "== 8) VESSAPP_RAID_BACKEND=fixture: the mdadm engine lists the .212 disks =="
# The mdadm-backed RAID backend (src/raid/, RAID-ENGINE-MDADM-PLAN.md P1) with
# its file-based command source: canned lsblk output shaped like the .212 box
# (HGST + 3x TOSHIBA data disks + an ADATA OS disk that must be EXCLUDED).
# No mdadm/smartctl in this container - identity falls back to the lsblk MODEL
# and there are no md arrays, i.e. the fresh-box result: 4 available PDs.
FIX=/tmp/raidfix; mkdir -p "$FIX"
cat > "$FIX/lsblk__Pbn__o_NAME_TYPE_SIZE_MODEL_PKNAME_MOUNTPOINT" <<'EOF'
NAME="sda" TYPE="disk" SIZE="4000787030016" MODEL="HGST HUS726T4TALE6L4" PKNAME="" MOUNTPOINT=""
NAME="sdb" TYPE="disk" SIZE="4000787030016" MODEL="TOSHIBA MG04ACA400E" PKNAME="" MOUNTPOINT=""
NAME="sdc" TYPE="disk" SIZE="4000787030016" MODEL="TOSHIBA MG04ACA400E" PKNAME="" MOUNTPOINT=""
NAME="sdd" TYPE="disk" SIZE="4000787030016" MODEL="TOSHIBA MG04ACA400E" PKNAME="" MOUNTPOINT=""
NAME="sde" TYPE="disk" SIZE="512110190592" MODEL="ADATA SU800" PKNAME="" MOUNTPOINT=""
NAME="sde2" TYPE="part" SIZE="511562242048" MODEL="" PKNAME="sde" MOUNTPOINT="/"
EOF
kill "$FPM" 2>/dev/null || true; wait "$FPM" 2>/dev/null || true
export VESSAPP_RAID_BACKEND=fixture VESSAPP_RAID_FIXTURE="$FIX"
"php-fpm$PHPV" -F &
FPM=$!
for i in $(seq 1 40); do curl -fs -o /dev/null "$BASE/index.php" && break; sleep 0.25; done
# the FPM restart recreated the extension's session store -> log in again
CJ2=/tmp/cj2; rm -f "$CJ2"
IDX=$(curl -s -c "$CJ2" "$BASE/index.php")
HN1=$(printf '%s' "$IDX" | sed -nE 's/.*id="hn1" value="([0-9]+)".*/\1/p' | head -1)
CSRF=$(printf '%s' "$IDX" | sed -nE 's/.*name="csrftoken" value="([-0-9]+)".*/\1/p' | head -1)
curl -s -b "$CJ2" -c "$CJ2" \
  --data-urlencode "login=administrator" --data-urlencode "md5password=deadbeef" \
  --data-urlencode "hn1=$HN1" --data-urlencode "csrftoken=$CSRF" \
  --data-urlencode "defaultpw=0" --data-urlencode "submit=Login" \
  "$BASE/index.php?ssl=0" >/dev/null
PHY=$(curl -s -b "$CJ2" "$BASE/phydrvs.php?ssid=0")
has "Fatal error|Parse error|Warning:" "$PHY" && fail "phydrvs.php (mdadm fixture) emitted a PHP error"
has "top.location.reload|URL=index.php" "$PHY" && fail "phydrvs.php (mdadm fixture) bounced to login"
has "HGST HUS726T4TALE6L4" "$PHY" || fail "mdadm backend: HGST data disk missing from phydrvs.php"
TOSH=$(printf '%s' "$PHY" | grep -o "TOSHIBA MG04ACA400E" | wc -l | tr -d ' ')
[ "$TOSH" = 3 ] || fail "mdadm backend: want 3 TOSHIBA drives on phydrvs.php, got $TOSH"
has "ADATA" "$PHY" && fail "mdadm backend: the OS disk leaked into phydrvs.php"
for f in "arrays.php?ssid=0" "logdrvs.php?ssid=0"; do
  BODY=$(curl -s -b "$CJ2" "$BASE/$f")
  has "Fatal error|Parse error|Warning:" "$BODY" && fail "$f (mdadm fixture) emitted a PHP error"
  has "DiskArray" "$BODY" && fail "$f (mdadm fixture): no md arrays exist, must be empty"
done
echo "   phydrvs.php lists the 4 data disks (OS disk excluded); arrays/logdrvs empty"

echo "== 9) the CREATE wizard drives the mdadm engine end to end (P2) =="
# Advanced create: arrayc.php -> arrayc1 (pick PDs) -> arrayc2 (define the LD)
# -> arrayc3 (submit -> createDiskArray -> raid_mdadm_create). The fixture
# command source has no answer for `mdadm --create ...` at first, so the
# engine fails GRACEFULLY (result=1, no fatal); then the ack file is seeded
# and the same submit succeeds; then the md0 read fixtures appear and
# arrays/logdrvs show DA0 + the RAID5 LD with real capacity - the whole
# create -> enumerate loop with zero hardware.
csrf_of() { printf '%s' "$1" | sed -nE 's/.*name="csrftoken" value="([-0-9]+)".*/\1/p' | head -1; }
errs_of() { echo "$1" | grep -E "Fatal error|Parse error|Warning|Deprecated" | sed 's/<[^>]*>//g' | head -4 || true; }

ARC=$(curl -s -b "$CJ2" "$BASE/arrayc.php?ssid=0")
has "Fatal error|Parse error|Warning:" "$ARC" && { errs_of "$ARC"; fail "arrayc.php emitted a PHP error"; }
ARC1=$(curl -s -b "$CJ2" "$BASE/arrayc1.php?start&act=create")
has "Fatal error|Parse error|Warning:" "$ARC1" && { errs_of "$ARC1"; fail "arrayc1.php emitted a PHP error"; }
NPD=$(printf '%s' "$ARC1" | grep -c 'option value=' || true)
[ "$NPD" = 4 ] || fail "arrayc1.php offers $NPD PDs, want the 4 data disks"

CODE=$(curl -s -b "$CJ2" -c "$CJ2" -o /tmp/arc1.post -D /tmp/arc1.hdr -w '%{http_code}' \
  -e "$BASE/arrayc1.php" \
  --data-urlencode "csrftoken=$(csrf_of "$ARC1")" \
  --data-urlencode "rpad_u8Name=DA0" \
  --data-urlencode "rpad_bMediaPatrlEnable=on" \
  --data-urlencode "list2[]=1" --data-urlencode "list2[]=2" \
  --data-urlencode "list2[]=3" --data-urlencode "list2[]=4" \
  --data-urlencode "next=Next" "$BASE/arrayc1.php")
[ "$CODE" = 302 ] || { { echo "-- resp headers:"; cat /tmp/arc1.hdr; echo "-- body ($(wc -c < /tmp/arc1.post) bytes):"; head -c 800 /tmp/arc1.post; echo; } || true; fail "arrayc1 next did not redirect (HTTP $CODE)"; }

ARC2=$(curl -s -b "$CJ2" "$BASE/arrayc2.php")
has "Fatal error|Parse error|Warning:" "$ARC2" && { errs_of "$ARC2"; fail "arrayc2.php emitted a PHP error"; }
has "RAID5" "$ARC2" || fail "arrayc2.php does not offer RAID5 for 4 drives"

# define the LD: RAID5, 64KB stripe, full capacity (14.55TB of 16003148120064)
ARC2B=$(curl -s -b "$CJ2" -c "$CJ2" -e "$BASE/arrayc2.php" \
  --data-urlencode "csrftoken=$(csrf_of "$ARC2")" \
  --data-urlencode "rpal_u8Name=LD0" \
  --data-urlencode "rpal_u16RaidLevel=5" \
  --data-urlencode "rpal_u32StripeSize=64" \
  --data-urlencode "_writePolicy=1" --data-urlencode "_readPolicy=2" \
  --data-urlencode "rpal_u64Size=14.55" --data-urlencode "rpal_u64Size1=4" \
  --data-urlencode "rpal_u64Size3=16003148120064" \
  --data-urlencode "_strSizeUnit=TB" \
  --data-urlencode "update=Update" "$BASE/arrayc2.php")
has "Fatal error|Parse error|Warning:" "$ARC2B" && { errs_of "$ARC2B"; fail "arrayc2 Update emitted a PHP error"; }
has "LD0" "$ARC2B" || fail "arrayc2 did not accept the RAID5 LD"

CODE=$(curl -s -b "$CJ2" -c "$CJ2" -o /dev/null -w '%{http_code}' \
  -e "$BASE/arrayc2.php" \
  --data-urlencode "csrftoken=$(csrf_of "$ARC2B")" \
  --data-urlencode "next=Next" "$BASE/arrayc2.php")
[ "$CODE" = 302 ] || fail "arrayc2 next did not redirect (HTTP $CODE)"

ARC3=$(curl -s -b "$CJ2" "$BASE/arrayc3.php")
has "Fatal error|Parse error|Warning:" "$ARC3" && { errs_of "$ARC3"; fail "arrayc3.php emitted a PHP error"; }
has "RAID5" "$ARC3" || fail "arrayc3 summary lost the RAID5 LD"

# submit WITHOUT a create ack in the fixture -> graceful engine failure
SUB=$(curl -s -b "$CJ2" -c "$CJ2" -e "$BASE/arrayc3.php" \
  --data-urlencode "csrftoken=$(csrf_of "$ARC3")" \
  --data-urlencode "submit=Submit" "$BASE/arrayc3.php")
has "Fatal error|Parse error|Warning:" "$SUB" && { errs_of "$SUB"; fail "create submit emitted a PHP error"; }
has "result=1" "$SUB" || fail "fixture without mdadm ack: create must fail gracefully"

# seed the ack + the md0 read fixtures -> the same submit now succeeds
CREATE_CMD="mdadm --create /dev/md0 --metadata=1.2 --run --level=5 --raid-devices=4 --chunk=64 /dev/sda /dev/sdb /dev/sdc /dev/sdd"
: > "$FIX/$(printf '%s' "$CREATE_CMD" | sed 's/[^A-Za-z0-9]/_/g')"
ARC3=$(curl -s -b "$CJ2" "$BASE/arrayc3.php")
SUB=$(curl -s -b "$CJ2" -c "$CJ2" -e "$BASE/arrayc3.php" \
  --data-urlencode "csrftoken=$(csrf_of "$ARC3")" \
  --data-urlencode "submit=Submit" "$BASE/arrayc3.php")
has "Fatal error|Parse error|Warning:" "$SUB" && { errs_of "$SUB"; fail "create submit (ack) emitted a PHP error"; }
has "golmm=1" "$SUB" || { { echo "-- fixture dir:"; ls -la "$FIX"; echo "-- expected ack: $(printf '%s' "$CREATE_CMD" | sed 's/[^A-Za-z0-9]/_/g')"; echo "-- submit body:"; printf '%s' "$SUB" | sed 's/<[^>]*>//g' | grep -vE '^[[:space:]]*$' | head -6; echo "-- fpm log:"; tail -12 /var/log/php*fpm*.log; } || true; fail "create with the mdadm ack did not succeed"; }

cat > "$FIX/mdadm___detail___scan" <<'EOF'
ARRAY /dev/md0 metadata=1.2 name=a3120:0 UUID=8c9a2f4e:11aa22bb:33cc44dd:55ee66ff
EOF
cat > "$FIX/mdadm___detail__dev_md0" <<'EOF'
/dev/md0:
           Version : 1.2
        Raid Level : raid5
        Array Size : 11720661504 (10.92 TiB 12.00 TB)
     Used Dev Size : 3906887168 (3.64 TiB 4.00 TB)
      Raid Devices : 4
             State : clean
        Chunk Size : 64K
    Number   Major   Minor   RaidDevice State
       0       8        0        0      active sync   /dev/sda
       1       8       16        1      active sync   /dev/sdb
       2       8       32        2      active sync   /dev/sdc
       3       8       48        3      active sync   /dev/sdd
EOF
cat > "$FIX/cat__proc_mdstat" <<'EOF'
Personalities : [raid6] [raid5] [raid4]
md0 : active raid5 sdd[3] sdc[2] sdb[1] sda[0]
      11720661504 blocks super 1.2 level 5, 64k chunk, algorithm 2 [4/4] [UUUU]

unused devices: <none>
EOF

ARR=$(curl -s -b "$CJ2" "$BASE/arrays.php?ssid=0")
has "Fatal error|Parse error|Warning:" "$ARR" && { errs_of "$ARR"; fail "arrays.php (created md0) emitted a PHP error"; }
has "DiskArray0" "$ARR" || fail "arrays.php does not list the created DA0"
has "N/A" "$ARR" && fail "arrays.php still shows N/A capacity for DA0"
has "14.55TB" "$ARR" || fail "arrays.php lost the DA0 configurable capacity"
LOG=$(curl -s -b "$CJ2" "$BASE/logdrvs.php?ssid=0")
has "Fatal error|Parse error|Warning:" "$LOG" && { errs_of "$LOG"; fail "logdrvs.php (created md0) emitted a PHP error"; }
has "RAID5" "$LOG" || fail "logdrvs.php does not show the created RAID5 LD"
ARRD=$(curl -s -b "$CJ2" "$BASE/arrayd.php")
has "Fatal error|Parse error|Warning:" "$ARRD" && { errs_of "$ARRD"; fail "arrayd.php emitted a PHP error"; }
has "DiskArray0" "$ARRD" || fail "arrayd.php does not offer DA0 for deletion"
echo "   wizard: PD pick -> RAID5 LD -> submit (fail-then-ack) -> DA0 + LD listed with capacity"

echo "TRIXIE SMOKE OK: installer works on debian:trixie ($PHPV); login + frameset +"
echo "  Subsystem/Enclosure pages render via the smbus backend with no PHP errors;"
echo "  RAID pages degrade to empty tables (no fixture data) as the box requires;"
echo "  VESSAPP_RAID_BACKEND=fixture lists the .212-shaped disks as available PDs;"
echo "  the create wizard drives the mdadm engine (P2) through create -> enumerate."
