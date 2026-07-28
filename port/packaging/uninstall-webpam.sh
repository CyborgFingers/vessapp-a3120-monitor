#!/usr/bin/env bash
# uninstall-webpam.sh - remove ONLY what install-webpam.sh installed.
# promise-encld (daemon, /opt/promise/bin, its promise.env keys), pveproxy and
# the distro packages (nginx, php-fpm) are all left alone.
#
#   sudo bash port/packaging/uninstall-webpam.sh
set -uo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root (sudo)"; exit 1; }

echo "== remove the web tier config =="
rm -f /etc/nginx/sites-enabled/promise-webpam.conf \
      /etc/nginx/sites-available/promise-webpam.conf
rm -f /etc/php/*/fpm/conf.d/90-promise-webpam.ini /etc/php/*/fpm/pool.d/webpam.conf
rm -f /etc/systemd/system/php*-fpm.service.d/promise.conf
rmdir /etc/systemd/system/php*-fpm.service.d 2>/dev/null
rm -f /etc/udev/rules.d/60-promise-i2c.rules

echo "== remove the WebPAM tree + extension (encld's /opt/promise/bin kept) =="
rm -rf /opt/promise/webpam
rm -f /opt/promise/lib/php/i2.so
rmdir /opt/promise/lib/php /opt/promise/lib 2>/dev/null

if [ -d /run/systemd/system ] && command -v systemctl >/dev/null; then
  systemctl daemon-reload
  systemctl restart 'php*-fpm' 2>/dev/null
  nginx -t >/dev/null 2>&1 && systemctl reload nginx 2>/dev/null
fi
echo "DONE. /etc/promise/promise.env kept (promise-encld reads it); the two"
echo "VESSAPP_* lines are harmless - delete them by hand if you want."
