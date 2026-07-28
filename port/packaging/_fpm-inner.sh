#!/usr/bin/env bash
# _fpm-inner.sh - runs INSIDE the php:7.4-fpm container (cwd /work = port/).
# Builds the php_i2 extension against this PHP (7.4, matching the shipped 7.3),
# lays out the SAME /opt/promise structure install.sh creates on the device,
# installs the SAME packaging/{nginx-webpam.conf,fpm-webpam.conf,php-webpam.ini}
# verbatim, and runs the production pair: nginx + PHP-FPM (concurrent - this is
# what makes the post-login frameset work where single-threaded `php -S` hung).
#
#   mode=serve  - run the full stack in the foreground (browser check)
#   mode=smoke  - boot the stack, then assert login + the CONCURRENT frameset.
#                 The pool gets pm.max_requests=1, so every request is served
#                 by a brand-new worker process: the flow passing proves the
#                 dc/session store is truly cross-process (fork-shared mmap).
#
# Not meant to be run on the host.
set -euo pipefail
MODE="${1:-serve}"; PORT="${2:-8088}"
export DEBIAN_FRONTEND=noninteractive

echo "== deps (nginx + phpize toolchain) =="
apt-get update -qq
apt-get install -y -qq $PHPIZE_DEPS linux-libc-dev curl nginx >/dev/null

echo "== build php_i2 against PHP $(php -r 'echo PHP_VERSION;') =="
cd /work/php-ext
make clean >/dev/null 2>&1 || true
phpize --clean >/dev/null 2>&1 || true
phpize >/dev/null
./configure --enable-i2=shared >/dev/null
make -j"$(nproc)" >/dev/null 2>&1

# ---- the canonical /opt/promise layout (identical to install.sh) -------------
mkdir -p /opt/promise/webpam /opt/promise/lib/php
ln -sfn /work/web/htdocs /opt/promise/webpam/htdocs
cp /work/php-ext/modules/i2.so       /opt/promise/lib/php/i2.so
cp /work/packaging/compat-prepend.php /opt/promise/webpam/compat-prepend.php

# ---- PHP + FPM + nginx config (the shipped packaging files, verbatim) --------
cp /work/packaging/php-webpam.ini /usr/local/etc/php/conf.d/90-promise-webpam.ini
rm -f /usr/local/etc/php-fpm.d/*.conf              # drop the image's www pool
cp /work/packaging/fpm-webpam.conf /usr/local/etc/php-fpm.d/webpam.conf
if [ "$MODE" = smoke ]; then
  # Cross-process proof: each worker serves exactly ONE request then exits.
  echo "pm.max_requests = 1" >> /usr/local/etc/php-fpm.d/webpam.conf
fi
rm -f /etc/nginx/sites-enabled/default
cp /work/packaging/nginx-webpam.conf /etc/nginx/conf.d/webpam.conf
[ "$PORT" = 8088 ] || sed -i "s/listen 8088;/listen $PORT;/" /etc/nginx/conf.d/webpam.conf

php-fpm -t >/dev/null && nginx -t >/dev/null 2>&1
php -c /usr/local/etc/php -m | grep -qx i2 \
  || { echo "FATAL: php_i2 did not load on PHP $(php -r 'echo PHP_VERSION;')"; exit 1; }
echo "php_i2 loaded on PHP $(php -r 'echo PHP_VERSION;') under FPM config"

php-fpm -F &
FPM=$!
if [ "$MODE" = serve ]; then
  echo "serving WebPAM htdocs via nginx + php-fpm (4 workers, fixture substrate) on 0.0.0.0:$PORT"
  exec nginx -g "daemon off;"
fi

# ============================ mode=smoke ======================================
nginx
BASE="http://127.0.0.1:$PORT"; CJ=/tmp/cj; T=/tmp/fpm-smoke; rm -rf "$CJ" "$T"; mkdir -p "$T"
trap 'kill $FPM 2>/dev/null || true; nginx -s stop 2>/dev/null || true' EXIT
for i in $(seq 1 40); do curl -fs -o /dev/null "$BASE/index.php" && break; sleep 0.25; done

fail() { echo "FPM SMOKE FAIL: $1"; nginx -s stop 2>/dev/null||true; exit 1; }
has()  { echo "$2" | grep -qE "$1"; }

echo "== 0) /fpm-status: the pool really is multi-process =="
ST=$(curl -s "$BASE/fpm-status")
has "pool: *webpam" "$ST"            || fail "fpm-status did not answer for pool webpam"
NPROC=$(printf '%s\n' "$ST" | sed -nE 's/^total processes: *([0-9]+).*/\1/p')
[ "${NPROC:-0}" -ge 2 ]              || fail "expected a multi-worker pool, got '$NPROC'"
echo "   pool webpam up, total processes: $NPROC (pm.max_requests=1 -> every request = a fresh worker)"

echo "== 1) GET index.php (login challenge) =="
IDX=$(curl -s -c "$CJ" "$BASE/index.php")
has 'dynccode="[^"]' "$IDX" || fail "no dynamic code rendered on login page"
HN1=$(printf '%s' "$IDX" | sed -nE 's/.*id="hn1" value="([0-9]+)".*/\1/p' | head -1)
CSRF=$(printf '%s' "$IDX" | sed -nE 's/.*name="csrftoken" value="([-0-9]+)".*/\1/p' | head -1)
[ -n "$HN1" ] && [ -n "$CSRF" ] || fail "could not extract dc id / csrf"
echo "   dc id=$HN1 csrf=$CSRF"

echo "== 2) POST login (a DIFFERENT worker process must honour that dc) =="
LOGIN=$(curl -s -b "$CJ" -c "$CJ" \
  --data-urlencode "login=administrator" --data-urlencode "md5password=deadbeef" \
  --data-urlencode "hn1=$HN1" --data-urlencode "csrftoken=$CSRF" \
  --data-urlencode "defaultpw=0" --data-urlencode "submit=Login" \
  "$BASE/index.php?ssl=0")
has "Fatal error|Parse error|Warning:" "$LOGIN" && fail "login emitted a PHP error"
has "loginfail|csrf" "$LOGIN" && fail "login rejected (dc/session not shared across workers?)"
has "screen.php" "$LOGIN" || fail "login did not redirect to screen.php"
echo "   login OK -> screen.php (dc issued by one worker, consumed by another)"

echo "== 3) GET screen.php (the frameset itself) =="
SCR=$(curl -s -b "$CJ" "$BASE/screen.php")
has "Fatal error|Parse error|Warning:" "$SCR" && fail "screen.php emitted a PHP error"
has "URL=index.php" "$SCR" && fail "screen.php bounced to login (session lost)"
for f in header.php treeframe.php home.php event.php daemon.php; do
  has "$f" "$SCR" || fail "frameset missing frame $f"
done
echo "   frameset rendered: header + tree + home + event + daemon frames"

FRAMES="header.php treeframe.php home.php event.php daemon.php arrays.php?ssid=0 subsystem.php?ssid=0"
for round in 1 2 3; do
  echo "== 4.$round) ALL frames CONCURRENTLY (what php -S could not serve) =="
  pids=""
  for f in $FRAMES; do
    out="$T/$(echo "$f" | tr '/?=' '___')"
    curl -s -b "$CJ" -o "$out.body" -w '%{http_code}' "$BASE/$f" > "$out.code" &
    pids="$pids $!"
  done
  for p in $pids; do wait "$p" || fail "a concurrent frame request failed"; done
  for f in $FRAMES; do
    out="$T/$(echo "$f" | tr '/?=' '___')"
    CODE=$(cat "$out.code"); BODY=$(cat "$out.body")
    [ "$CODE" = 200 ] || fail "$f returned HTTP $CODE"
    [ -s "$out.body" ] || fail "$f returned an empty body"
    if has "Fatal error|Parse error|Warning:" "$BODY"; then
      echo "$BODY" | grep -E "Fatal error|Parse error|Warning" | sed 's/<[^>]*>//g' | head -4
      fail "$f emitted a PHP error"
    fi
    has "top.location.reload|URL=index.php" "$BODY" && fail "$f bounced to login (session lost across workers)"
  done
  echo "   all $(echo $FRAMES | wc -w | tr -d ' ') frames: HTTP 200, no PHP errors, session held"
done

has "DA7" "$(cat "$T/arrays.php_ssid_0.body")" || fail "arrays.php lost the fixture data (DA7)"
has "Vess A3120" "$(cat "$T/subsystem.php_ssid_0.body")" || fail "subsystem.php lost the model"
echo "   fixture data intact under concurrency: array DA7, model 'Vess A3120'"

echo "FPM SMOKE OK: nginx+php-fpm serve the FULL framed WebPAM concurrently;"
echo "  dc/login/session survive pm.max_requests=1 (every request its own worker process)"
