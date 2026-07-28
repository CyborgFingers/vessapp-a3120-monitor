#!/usr/bin/env bash
# install.sh - install the Promise VessApp / WebPAM Linux port onto a Debian
# target (the Vess A3120, or any Debian box for a fixture-mode dry run).
# Idempotent: safe to re-run; operator-edited configs are never clobbered.
#
#   sudo bash port/packaging/install.sh                  # OFF-DEVICE (fixture)
#   sudo bash port/packaging/install.sh --on-device /dev/sgN   # ON-DEVICE
#
# OFF-DEVICE (default): no I2BDG_SG_DEV -> the php_i2 extension and opasd seed
#   the mock substrate (demo enclosure + RAID data). Everything runs, no HW.
# ON-DEVICE: writes I2BDG_SG_DEV into /etc/promise/promise.env and grants the
#   FPM user /dev/sg* access. The operator then follows the Phase-6b bring-up
#   checklist in docs/PACKAGING.md (calibrate.sh, auth-hook binding).
#
# Layout installed:
#   /opt/promise/bin/            daemons + CLI tools
#   /opt/promise/lib/php/i2.so   the php_i2 extension
#   /opt/promise/webpam/htdocs   the WebPAM PROe web app (ported htdocs)
#   /etc/promise/                promise.env, emailsetting.xml, bgasched.conf
#   /etc/systemd/system/         promise-webpam.target + services (+FPM drop-in)
set -euo pipefail

PKG_DIR="$(cd "$(dirname "$0")" && pwd)"          # .../port/packaging
PORT_DIR="$(cd "$PKG_DIR/.." && pwd)"             # .../port
REPO_DIR="$(cd "$PORT_DIR/.." && pwd)"            # repo root
MODE=fixture
SG_DEV=""
if [ "${1:-}" = "--on-device" ]; then
  MODE=device
  SG_DEV="${2:?usage: install.sh --on-device /dev/sgN}"
fi

[ "$(id -u)" = 0 ] || { echo "run as root (sudo)"; exit 1; }
command -v systemctl >/dev/null || { echo "needs a systemd host"; exit 1; }

echo "== [1/8] PHP 7.x (the htdocs need PHP 7; the appliance shipped 7.3) =="
PHPV=""
for v in 7.4 7.3 7.2; do
  command -v "php-fpm$v" >/dev/null 2>&1 && { PHPV=$v; break; }
done
if [ -z "$PHPV" ]; then
  echo "   php-fpm 7.x not found; trying apt (Debian needs the sury.org repo for 7.4)..."
  apt-get update -qq || true
  if apt-get install -y -qq php7.4-fpm php7.4-dev php7.4-cli 2>/dev/null; then
    PHPV=7.4
  else
    cat <<'EOF'
FATAL: PHP 7.4 is not installable from the configured APT repos.
The WebPAM htdocs use PHP4-style constructors (removed in PHP 8), so the tier
runs on PHP 7.x. On Debian, add the sury.org PHP repo, then re-run:
  apt-get install -y apt-transport-https lsb-release ca-certificates curl
  curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
  echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
  apt-get update && apt-get install -y php7.4-fpm php7.4-dev php7.4-cli
EOF
    exit 1
  fi
fi
echo "   using PHP $PHPV (php-fpm$PHPV / phpize$PHPV)"

echo "== [2/8] distro packages (build + web + snmp; slp optional) =="
apt-get update -qq || true
apt-get install -y -qq build-essential cmake pkg-config nginx snmpd snmp \
  sg3-utils "php$PHPV-dev" "php$PHPV-cli" >/dev/null
apt-get install -y -qq openslp-server >/dev/null 2>&1 && SLP=yes || SLP=no
echo "   openslp-server: $SLP (SLP discovery is optional)"

echo "== [3/8] build the C stack (daemons, tools, subagent) =="
cmake -S "$PORT_DIR" -B "$PORT_DIR/build-device" -DCMAKE_BUILD_TYPE=Release >/dev/null
cmake --build "$PORT_DIR/build-device" -j"$(nproc)" >/dev/null
install -d /opt/promise/bin /opt/promise/lib/php /opt/promise/webpam
for b in opasd emaild bgasched pwrmon prevtlogd promise-encld promise_snmp promise_trap \
         getfan getelemcnt getenc gettmpr getvolt getled setled getrecled \
         setrecled getsysled setsysled alarm wdt vpd; do
  install -m 0755 "$PORT_DIR/build-device/$b" /opt/promise/bin/
done
echo "   installed $(ls /opt/promise/bin | wc -l) binaries to /opt/promise/bin"

echo "== [4/8] build the php_i2 extension (phpize$PHPV) =="
( cd "$PORT_DIR/php-ext"
  make clean >/dev/null 2>&1 || true
  "phpize$PHPV" --clean >/dev/null 2>&1 || true
  "phpize$PHPV" >/dev/null
  ./configure --enable-i2=shared --with-php-config="php-config$PHPV" >/dev/null
  make -j"$(nproc)" >/dev/null )
install -m 0644 "$PORT_DIR/php-ext/modules/i2.so" /opt/promise/lib/php/i2.so
"php$PHPV" -n -d extension=/opt/promise/lib/php/i2.so -m | grep -qx i2 \
  || { echo "FATAL: php_i2 failed to load on php$PHPV"; exit 1; }
echo "   /opt/promise/lib/php/i2.so loads on php$PHPV"

echo "== [5/8] web tier (htdocs + nginx + FPM pool + php ini) =="
rm -rf /opt/promise/webpam/htdocs
cp -a "$PORT_DIR/web/htdocs" /opt/promise/webpam/htdocs
install -m 0644 "$PKG_DIR/compat-prepend.php" /opt/promise/webpam/compat-prepend.php
install -m 0644 "$PKG_DIR/php-webpam.ini" "/etc/php/$PHPV/fpm/conf.d/90-promise-webpam.ini"
install -m 0644 "$PKG_DIR/fpm-webpam.conf" "/etc/php/$PHPV/fpm/pool.d/webpam.conf"
install -m 0644 "$PKG_DIR/nginx-webpam.conf" /etc/nginx/sites-available/promise-webpam.conf
ln -sfn /etc/nginx/sites-available/promise-webpam.conf /etc/nginx/sites-enabled/promise-webpam.conf

echo "== [6/8] /etc/promise (knob file + daemon configs; kept if present) =="
install -d /etc/promise
[ -f /etc/promise/promise.env ] || install -m 0644 "$PKG_DIR/promise.env" /etc/promise/promise.env
if [ ! -f /etc/promise/emailsetting.xml ]; then
  # install default = notifications OFF until the operator configures SMTP
  sed 's|<enabled>1</enabled>|<enabled>0</enabled>|' \
    "$PORT_DIR/src/emaild/emailsetting.xml" > /etc/promise/emailsetting.xml
fi
[ -f /etc/promise/bgasched.conf ] || install -m 0644 "$PORT_DIR/src/daemons/bgasched.conf" /etc/promise/bgasched.conf

# snmpd: our ported conf (original directives + pass_persist), path fixed to
# /opt/promise. The pre-existing conf is kept once as snmpd.conf.pre-promise.
if [ -f /etc/snmp/snmpd.conf ] && [ ! -f /etc/snmp/snmpd.conf.pre-promise ] \
   && ! grep -q promise_snmp /etc/snmp/snmpd.conf; then
  cp /etc/snmp/snmpd.conf /etc/snmp/snmpd.conf.pre-promise
fi
sed 's|/usr/local/bin/promise_snmp|/opt/promise/bin/promise_snmp|' \
  "$PORT_DIR/src/snmpd/snmpd.conf" > /etc/snmp/snmpd.conf
MIB="$REPO_DIR/windows-src/rootfs/PromiseApp/docs/SNMP and Events/MIBs/raidv4.mib"
if [ -f "$MIB" ]; then
  install -d /usr/share/snmp/mibs
  install -m 0644 "$MIB" /usr/share/snmp/mibs/raidv4.mib
  echo "   raidv4.mib -> /usr/share/snmp/mibs/"
else
  echo "   NOTE: raidv4.mib not found (windows-src/ not on this box) - snmpwalk works, names unresolved"
fi
if [ "$SLP" = yes ]; then
  # register WebPAM with the local OpenSLP SA (markers keep uninstall precise)
  if ! grep -q "BEGIN promise-webpam" /etc/slp.reg 2>/dev/null; then
    { echo "# BEGIN promise-webpam"; cat "$PORT_DIR/src/slp/promise.reg"; echo "# END promise-webpam"; } >> /etc/slp.reg
  fi
fi

echo "== [7/8] systemd units + promise-webpam.target =="
for u in opasd emaild prevtlogd bgasched pwrmon promise-encld; do
  install -m 0644 "$PKG_DIR/systemd/$u.service" "/etc/systemd/system/$u.service"
done
sed "s/php7\.4-fpm/php$PHPV-fpm/" "$PKG_DIR/systemd/promise-webpam.target" \
  > /etc/systemd/system/promise-webpam.target
install -d "/etc/systemd/system/php$PHPV-fpm.service.d"
install -m 0644 "$PKG_DIR/systemd/php-fpm-promise-dropin.conf" \
  "/etc/systemd/system/php$PHPV-fpm.service.d/promise.conf"
systemctl daemon-reload

echo "== [8/8] mode + services =="
if [ "$MODE" = device ]; then
  [ -e "$SG_DEV" ] || echo "   WARNING: $SG_DEV does not exist (yet?)"
  if grep -qE "^#?I2BDG_SG_DEV=" /etc/promise/promise.env; then
    sed -i -E "s|^#?I2BDG_SG_DEV=.*|I2BDG_SG_DEV=$SG_DEV|" /etc/promise/promise.env
  else
    echo "I2BDG_SG_DEV=$SG_DEV" >> /etc/promise/promise.env
  fi
  usermod -aG disk www-data   # FPM workers (www-data) must open /dev/sg*
  echo "   ON-DEVICE: I2BDG_SG_DEV=$SG_DEV written; www-data added to 'disk'"
  # promise-encld drives the real NCT7802Y blowers + nct6776 CPU fan and the
  # PCA9555 front-panel status LEDs (HDD/RAID health). It replaces - and
  # Conflicts with - the Python promise-hwmon driver, so retire that first.
  # Device-only: in fixture mode there is no /dev/i2c-0 to drive.
  systemctl disable --now promise-hwmon.service >/dev/null 2>&1 || true
  if systemctl enable --now promise-encld.service >/dev/null 2>&1; then
    echo "   promise-encld: fan + status-LED daemon enabled and started"
  else
    echo "   WARNING: promise-encld did not start (check: systemctl status promise-encld)"
  fi
else
  echo "   OFF-DEVICE (fixture): I2BDG_SG_DEV unset - mock substrate serves demo data"
fi
systemctl enable promise-webpam.target opasd emaild prevtlogd bgasched >/dev/null 2>&1
systemctl enable nginx "php$PHPV-fpm" snmpd >/dev/null 2>&1 || true
systemctl restart "php$PHPV-fpm" nginx snmpd
[ "$SLP" = yes ] && systemctl restart slpd 2>/dev/null || true
systemctl start promise-webpam.target

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
cat <<EOF

DONE. WebPAM:  http://${IP:-<this-host>}:8088/index.php
  login: administrator / any password  (fixture auth until the engine user-db
  hook is bound on-device - docs/PACKAGING.md, Phase 6b)
  SNMP:  snmpwalk -v2c -c VessApp localhost .1.3.6.1.4.1.7933.1.20
  stack: systemctl status promise-webpam.target
EOF
if [ "$MODE" = device ]; then
  cat <<'EOF'
NEXT (Phase 6b, on the A3120 - see docs/PACKAGING.md checklist):
  1. bash port/scripts/calibrate.sh      # read-only substrate verification
  2. verify WebPAM shows the REAL enclosure/arrays (not the demo fixture)
  3. bind the auth hook to the engine user-db (RE-php_i2.md §5)
EOF
fi
