#!/usr/bin/env bash
# install-webpam-monitor.sh - install the READ-ONLY WebPAM health monitor.
#
#   sudo bash port/packaging/install-webpam-monitor.sh
#
# This is the monitor fork of install-webpam.sh. It shows drive/pool health,
# SMART, temperatures and (on A3120 hardware) the enclosure/fan/LED pages, but
# it CANNOT manage drives:
#   - VESSAPP_RAID_BACKEND=health: the drive/array/LD pages are filled from a
#     read-only snapshot (/run/promise/health.dat) that promise-health.timer
#     refreshes via promise-health-probe (zpool/smartctl/lsblk - never a write).
#   - promise-raid-helper is NOT installed: there is no privileged path by which
#     the web tier could create/delete/resize/migrate an array. The drive-mgmt
#     pages are also nav-stripped and guarded (common.php MONITOR_BLOCKED_PAGES).
#   - Enclosure (fans/front-panel LEDs) IS kept and stays controllable, but only
#     lights up on real A3120 hardware (an SMBus bus at /dev/i2c-0); on a generic
#     box those pages are simply empty.
#
# Targets a Debian/Proxmox host. TrueNAS SCALE is detected and warned about: it
# is an appliance (host package installs are unsupported and wiped on update),
# so nginx/php-fpm likely cannot be apt-installed there - run the monitor as a
# container/App instead. Everything else (probe, snapshot, backend) is identical.
set -euo pipefail

PKG_DIR="$(cd "$(dirname "$0")" && pwd)"
PORT_DIR="$(cd "$PKG_DIR/.." && pwd)"

[ "$(id -u)" = 0 ] || { echo "run as root (sudo)"; exit 1; }
HAVE_SYSTEMD=0
[ -d /run/systemd/system ] && command -v systemctl >/dev/null && HAVE_SYSTEMD=1

IS_TRUENAS=0
{ [ -f /etc/version ] && grep -qi truenas /etc/version 2>/dev/null; } && IS_TRUENAS=1
command -v midclt >/dev/null 2>&1 && IS_TRUENAS=1
if [ "$IS_TRUENAS" = 1 ]; then
  cat <<'W'
  ============================================================================
  WARNING: TrueNAS SCALE detected. TrueNAS is an appliance - host package
  installs (nginx, php-fpm) are NOT supported and are wiped on every update.
  This host installer will TRY, but if apt is locked or nginx/php are missing
  afterwards it will stop and tell you. The supported way to run this monitor
  on TrueNAS is as a container/App. Proceeding best-effort in 5s (Ctrl-C to stop)...
  ============================================================================
W
  sleep 5
fi

echo "== [1/7] packages (nginx + php-fpm + phpize toolchain + smartmontools + python3) =="
HAD_NGINX=1; dpkg -s nginx >/dev/null 2>&1 || HAD_NGINX=0
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq || echo "   (apt update failed - continuing; TrueNAS/offline?)"
# smartmontools -> smartctl (SMART), python3 -> the probe. zfsutils only where the
# host actually uses ZFS but is not TrueNAS (Proxmox-on-ZFS); harmless if present.
apt-get install -y -qq nginx php-fpm php-dev php-cli libpam0g-dev smartmontools python3 >/dev/null 2>&1 \
  || echo "   (apt install failed - continuing with whatever is already present)"
command -v python3 >/dev/null || { echo "FATAL: python3 is required for the health probe"; exit 1; }
command -v smartctl >/dev/null || echo "   WARNING: smartctl absent - SMART health/temperature will be blank"
command -v php >/dev/null || { echo "FATAL: php-cli is not installed and could not be apt-installed."; exit 1; }
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
if ! command -v "php-fpm$PHPV" >/dev/null 2>&1 && [ ! -x "/usr/sbin/php-fpm$PHPV" ]; then
  echo "FATAL: php-fpm$PHPV is not installed and could not be apt-installed."
  [ "$IS_TRUENAS" = 1 ] && echo "       On TrueNAS run the monitor as a container/App instead of a host install."
  exit 1
fi
echo "   PHP $PHPV, nginx $(nginx -v 2>&1 | sed 's|.*/||' || echo '?')"
if [ "$HAD_NGINX" = 0 ] && [ -e /etc/nginx/sites-enabled/default ]; then
  rm -f /etc/nginx/sites-enabled/default
  echo "   nginx was newly installed: disabled the distro default :80 site"
fi

echo "== [2/7] build php_i2 (health backend compiled in) =="
( cd "$PORT_DIR/php-ext"
  make clean >/dev/null 2>&1 || true
  "phpize$PHPV" --clean >/dev/null 2>&1 || true
  # drop any stale dep/object files carried in from another build tree (their
  # absolute source paths would otherwise poison the regenerated Makefile).
  find . -name '*.dep' -delete 2>/dev/null || true
  find . -name '*.lo' -delete 2>/dev/null || true
  "phpize$PHPV" >/dev/null
  ./configure --enable-i2=shared --with-php-config="php-config$PHPV" >/dev/null
  make -j"$(nproc)" >/dev/null )
install -d /opt/promise/lib/php /opt/promise/webpam /opt/promise/bin
install -m 0644 "$PORT_DIR/php-ext/modules/i2.so" /opt/promise/lib/php/i2.so
php -n -d extension=/opt/promise/lib/php/i2.so -m | grep -qx i2 \
  || { echo "FATAL: php_i2 failed to load on php$PHPV"; exit 1; }
echo "   /opt/promise/lib/php/i2.so loads on php$PHPV"

echo "== [3/7] htdocs -> /opt/promise/webpam =="
rm -rf /opt/promise/webpam/htdocs
cp -a "$PORT_DIR/web/htdocs" /opt/promise/webpam/htdocs
install -m 0644 "$PKG_DIR/compat-prepend.php" /opt/promise/webpam/compat-prepend.php 2>/dev/null || true
echo "   $(find /opt/promise/webpam/htdocs -name '*.php' | wc -l | tr -d ' ') PHP pages installed"

echo "== [4/7] nginx :8088/:8443 vhost + FPM pool + php ini =="
install -m 0644 "$PKG_DIR/php-webpam.ini" "/etc/php/$PHPV/fpm/conf.d/90-promise-webpam.ini"
install -m 0644 "$PKG_DIR/fpm-webpam.conf" "/etc/php/$PHPV/fpm/pool.d/webpam.conf"
install -m 0644 "$PKG_DIR/nginx-webpam.conf" /etc/nginx/sites-available/promise-webpam.conf
ln -sfn /etc/nginx/sites-available/promise-webpam.conf /etc/nginx/sites-enabled/promise-webpam.conf

echo "== [5/7] promise.env: health backend + enclosure autodetect =="
install -d /etc/promise
[ -f /etc/promise/promise.env ] || install -m 0644 "$PKG_DIR/promise.env" /etc/promise/promise.env 2>/dev/null || : > /etc/promise/promise.env
ensure_kv() {
  if grep -qE "^#?$1=" /etc/promise/promise.env; then
    sed -i -E "s|^#?$1=.*|$1=$2|" /etc/promise/promise.env
  else
    echo "$1=$2" >> /etc/promise/promise.env
  fi
}
ensure_kv VESSAPP_RAID_BACKEND health
ensure_kv VESSAPP_HEALTH_FILE /run/promise/health.dat
# Enclosure (fans / front-panel LEDs) only where an SMBus bus exists (real A3120).
if [ -e /dev/i2c-0 ]; then
  ensure_kv VESSAPP_BACKEND smbus
  ensure_kv VESSAPP_I2C_BUS 0
  ensure_kv VESSAPP_ENCLOSURE 1
  echo "   enclosure: SMBus @/dev/i2c-0 (fan/LED pages active)"
else
  ensure_kv VESSAPP_ENCLOSURE 0
  # no A3120 enclosure - leave the enclosure backend unset so those pages render
  # empty instead of probing absent hardware. Drive health is unaffected.
  sed -i -E 's|^VESSAPP_BACKEND=.*|# VESSAPP_BACKEND= (no enclosure on this host)|' /etc/promise/promise.env 2>/dev/null || true
  echo "   enclosure: none (no /dev/i2c-0) - enclosure/fan pages will be empty"
fi
echo "   VESSAPP_RAID_BACKEND=health"

echo "== [5b/7] read-only health probe + snapshot timer =="
install -m 0755 "$PORT_DIR/packaging/promise-health-probe" /opt/promise/bin/promise-health-probe
# runtime dir for the snapshot (www-data reads it; root's timer writes it)
install -d -m 0755 -o www-data -g www-data /run/promise 2>/dev/null || install -d -m 0755 /run/promise
if [ "$HAVE_SYSTEMD" = 1 ]; then
  [ -f "$PKG_DIR/systemd/promise-webpam-tmpfiles.conf" ] && \
    install -m 0644 "$PKG_DIR/systemd/promise-webpam-tmpfiles.conf" /etc/tmpfiles.d/promise-webpam.conf && \
    systemd-tmpfiles --create /etc/tmpfiles.d/promise-webpam.conf 2>/dev/null || true
  install -m 0644 "$PKG_DIR/systemd/promise-health.service" /etc/systemd/system/promise-health.service
  install -m 0644 "$PKG_DIR/systemd/promise-health.timer"   /etc/systemd/system/promise-health.timer
  systemctl daemon-reload
  systemctl enable --now promise-health.timer >/dev/null 2>&1 && echo "   promise-health.timer enabled (60s refresh)" || true
fi
# populate the snapshot immediately so the first page load has data
/usr/bin/python3 /opt/promise/bin/promise-health-probe > /run/promise/health.dat.tmp 2>/dev/null \
  && chmod 0644 /run/promise/health.dat.tmp && mv -f /run/promise/health.dat.tmp /run/promise/health.dat \
  && echo "   /run/promise/health.dat: $(grep -c '^PD|' /run/promise/health.dat) drives, $(grep -c '^ARRAY|' /run/promise/health.dat) arrays" \
  || echo "   WARNING: initial probe run produced no snapshot (check smartctl/zpool perms)"

echo "== [5c/7] WebPAM login password + TLS (all-HTTPS) =="
cc -O2 -Wall -o /opt/promise/bin/promise-webpam-passwd "$PORT_DIR/src/tools/promise_webpam_passwd.c" -lcrypt 2>/dev/null \
  && echo "   promise-webpam-passwd built" || echo "   (promise-webpam-passwd build skipped)"
[ -f /etc/promise/webpam.passwd ] || echo "   NO password set - login DENIED until: sudo /opt/promise/bin/promise-webpam-passwd <pw>"
# PAM (Linux-credential) login helpers - read-only auth, kept as in the base tier.
cc -O2 -Wall -o /opt/promise/bin/promise-pam-auth "$PORT_DIR/src/tools/promise_pam_auth.c" -lpam 2>/dev/null \
  && { chown root:www-data /opt/promise/bin/promise-pam-auth; chmod 4750 /opt/promise/bin/promise-pam-auth; } || true
cc -O2 -Wall -o /opt/promise/bin/promise-pam-user "$PORT_DIR/src/tools/promise_pam_user.c" 2>/dev/null \
  && { chown root:www-data /opt/promise/bin/promise-pam-user; chmod 4750 /opt/promise/bin/promise-pam-user; } || true
install -m 0644 "$PKG_DIR/pam-promise-webpam" /etc/pam.d/promise-webpam 2>/dev/null || true
if [ ! -s /etc/promise/webpam.crt ] || [ ! -s /etc/promise/webpam.key ]; then
  _HN=$(hostname -f 2>/dev/null || hostname); _IP=$(hostname -I 2>/dev/null | awk '{print $1}')
  openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -keyout /etc/promise/webpam.key -out /etc/promise/webpam.crt \
    -subj "/CN=${_HN:-vess-monitor}" -addext "subjectAltName=DNS:${_HN:-localhost}${_IP:+,IP:$_IP}" >/dev/null 2>&1 \
    && { chown root:www-data /etc/promise/webpam.key; chmod 0640 /etc/promise/webpam.key; chmod 0644 /etc/promise/webpam.crt; \
         echo "   self-signed TLS cert generated (HTTPS :8443)"; } \
    || echo "   WARNING: cert generation failed - :8443 will not start"
fi

echo "== [6/7] enclosure kernel modules + www-data i2c access (A3120 only) =="
if [ -e /dev/i2c-0 ] || [ "$IS_TRUENAS" = 0 ]; then
  for m in i2c-dev i2c-i801 nct6775; do modprobe "$m" 2>/dev/null || true; done
fi
if [ "$HAVE_SYSTEMD" = 1 ]; then
  install -d "/etc/systemd/system/php$PHPV-fpm.service.d"
  install -m 0644 "$PKG_DIR/systemd/php-fpm-promise-dropin.conf" "/etc/systemd/system/php$PHPV-fpm.service.d/promise.conf" 2>/dev/null || true
  getent group i2c >/dev/null || groupadd -r i2c
  usermod -aG i2c www-data 2>/dev/null || true
  for d in /dev/i2c-*; do [ -e "$d" ] && { chgrp i2c "$d" 2>/dev/null; chmod 0660 "$d" 2>/dev/null; }; done
  systemctl daemon-reload
fi

echo "== [7/7] restart nginx + php$PHPV-fpm =="
if [ "$HAVE_SYSTEMD" = 1 ]; then
  systemctl enable nginx "php$PHPV-fpm" >/dev/null 2>&1 || true
  systemctl restart "php$PHPV-fpm" || echo "   (php-fpm restart failed)"
  nginx -t >/dev/null 2>&1 && systemctl restart nginx || echo "   (nginx config test/restart failed)"
else
  echo "   no systemd (container): start php-fpm + nginx via the harness"
fi

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
cat <<EOF

DONE. WebPAM monitor:  https://${IP:-<this-host>}:8443/index.php  (:8088 redirects)
  login: administrator / password set via: sudo /opt/promise/bin/promise-webpam-passwd <pw>
  READ-ONLY: drive/pool/SMART health + (on A3120) fan/LED control. No drive management.
  snapshot refresh: systemctl status promise-health.timer ; cat /run/promise/health.dat
EOF
