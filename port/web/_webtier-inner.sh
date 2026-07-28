#!/usr/bin/env bash
# _webtier-inner.sh - runs INSIDE the php:7.4-cli container (cwd /work). Builds
# the php_i2 extension against this container's PHP (7.4, matching the shipped
# 7.3) and then either serves the WebPAM htdocs (mode=serve) or runs the headless
# login->data smoke (mode=smoke). Not meant to be run on the host.
set -euo pipefail
MODE="${1:-serve}"; PORT="${2:-8088}"
export DEBIAN_FRONTEND=noninteractive
INI=/work/web/php-fixture.ini
EXT=/work/php-ext/modules/i2.so
DOCROOT=/work/web/htdocs

# Build the extension fresh against this PHP (a .so from another PHP major would
# be ABI-incompatible). $PHPIZE_DEPS is provided by the official php image.
apt-get update -qq
apt-get install -y -qq $PHPIZE_DEPS linux-libc-dev curl >/dev/null
cd /work/php-ext
make clean >/dev/null 2>&1 || true
phpize --clean >/dev/null 2>&1 || true
phpize >/dev/null
./configure --enable-i2=shared >/dev/null
make -j"$(nproc)" >/dev/null 2>&1
php -c "$INI" -m | grep -qx i2 || { echo "FATAL: php_i2 did not load on PHP $(php -r 'echo PHP_VERSION;')"; exit 1; }
echo "php_i2 loaded on PHP $(php -r 'echo PHP_VERSION;')"

if [ "$MODE" = serve ]; then
  cd "$DOCROOT"
  echo "serving WebPAM htdocs (fixture substrate) on 0.0.0.0:$PORT"
  exec php -c "$INI" -S 0.0.0.0:"$PORT" -t "$DOCROOT"
fi

# ---- mode=smoke: start the server, drive dc -> login -> data pages ----------
BASE="http://127.0.0.1:$PORT"; CJ=/tmp/cj; rm -f "$CJ"
php -c "$INI" -S 127.0.0.1:"$PORT" -t "$DOCROOT" >/tmp/srv.log 2>&1 &
SVR=$!
trap 'kill $SVR 2>/dev/null || true' EXIT
for i in $(seq 1 40); do curl -fs -o /dev/null "$BASE/index.php" && break; sleep 0.25; done

fail() { echo "SMOKE FAIL: $1"; echo "--- server log ---"; tail -20 /tmp/srv.log; exit 1; }
has()  { echo "$2" | grep -qE "$1"; }

echo "== 1) GET index.php (login challenge) =="
IDX=$(curl -s -c "$CJ" "$BASE/index.php")
has 'dynccode="[^"]' "$IDX" || fail "no dynamic code rendered on login page"
HN1=$(printf '%s' "$IDX" | sed -nE 's/.*id="hn1" value="([0-9]+)".*/\1/p' | head -1)
CSRF=$(printf '%s' "$IDX" | sed -nE 's/.*name="csrftoken" value="([-0-9]+)".*/\1/p' | head -1)
[ -n "$HN1" ] || fail "could not extract dc id (hn1)"
[ -n "$CSRF" ] || fail "could not extract csrf token"
echo "   dc id=$HN1 csrf=$CSRF"

echo "== 2) POST login (administrator / fixture auth) =="
LOGIN=$(curl -s -b "$CJ" -c "$CJ" \
  --data-urlencode "login=administrator" \
  --data-urlencode "md5password=deadbeef" \
  --data-urlencode "hn1=$HN1" \
  --data-urlencode "csrftoken=$CSRF" \
  --data-urlencode "defaultpw=0" \
  --data-urlencode "submit=Login" \
  "$BASE/index.php?ssl=0")
has "Fatal error|Parse error|Warning:" "$LOGIN" && fail "login emitted a PHP error"
has "loginfail|csrf" "$LOGIN" && fail "login rejected"
has "screen.php" "$LOGIN" || fail "login did not redirect to screen.php (auth failed?)"
echo "   login OK -> redirect to screen.php"

echo "== 3) GET arrays.php (disk array data from substrate) =="
ARR=$(curl -s -b "$CJ" "$BASE/arrays.php?ssid=0")
has "Fatal error|Parse error|Warning:" "$ARR" && fail "arrays.php emitted a PHP error"
has "location.reload" "$ARR" && fail "arrays.php bounced to login (session lost)"
has "DA7" "$ARR" || fail "expected disk array DA7 not rendered"
has "DiskArray7" "$ARR" || fail "expected array name DiskArray7 not rendered"
echo "   arrays.php rendered array DA7 / DiskArray7"

echo "== 4) GET subsystem.php (subsystem summary) =="
SS=$(curl -s -b "$CJ" "$BASE/subsystem.php?ssid=0")
has "Fatal error|Parse error|Warning:" "$SS" && fail "subsystem.php emitted a PHP error"
has "Vess A3120" "$SS" || fail "expected model 'Vess A3120' not rendered"
echo "   subsystem.php rendered model 'Vess A3120'"

echo "== 5) GET treeframe.php (navigation tree) =="
TREE=$(curl -s -b "$CJ" "$BASE/treeframe.php?ssid=0")
has "Fatal error|Parse error|Warning:" "$TREE" && fail "treeframe.php emitted a PHP error"
has "insFld|gFld" "$TREE" || fail "navigation tree not generated"
echo "   treeframe.php built the navigation tree"

echo "== 6) GET home.php (landing content) =="
HOME=$(curl -s -b "$CJ" "$BASE/home.php?home")
has "Fatal error|Parse error|Warning:" "$HOME" && fail "home.php emitted a PHP error"
echo "   home.php rendered"

echo "SMOKE OK: dc -> login -> subsystem/arrays/tree/home render live fixture data, no PHP errors"
