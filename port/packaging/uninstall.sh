#!/usr/bin/env bash
# uninstall.sh - remove the Promise VessApp / WebPAM Linux port installed by
# install.sh. Keeps /etc/promise (operator config) unless --purge is given.
# Idempotent; distro packages (nginx, php-fpm, snmpd) are left installed.
#
#   sudo bash port/packaging/uninstall.sh [--purge]
set -uo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root (sudo)"; exit 1; }
PURGE="${1:-}"

echo "== stop + disable the stack =="
systemctl disable --now promise-webpam.target opasd emaild prevtlogd bgasched pwrmon 2>/dev/null

echo "== remove systemd units =="
rm -f /etc/systemd/system/promise-webpam.target \
      /etc/systemd/system/{opasd,emaild,prevtlogd,bgasched,pwrmon}.service
rm -f /etc/systemd/system/php*-fpm.service.d/promise.conf
rmdir /etc/systemd/system/php*-fpm.service.d 2>/dev/null
systemctl daemon-reload

echo "== remove web tier config =="
rm -f /etc/nginx/sites-enabled/promise-webpam.conf \
      /etc/nginx/sites-available/promise-webpam.conf
rm -f /etc/php/*/fpm/conf.d/90-promise-webpam.ini /etc/php/*/fpm/pool.d/webpam.conf

echo "== restore snmpd.conf (pre-promise backup, if any) =="
if [ -f /etc/snmp/snmpd.conf.pre-promise ]; then
  mv /etc/snmp/snmpd.conf.pre-promise /etc/snmp/snmpd.conf
elif grep -q promise_snmp /etc/snmp/snmpd.conf 2>/dev/null; then
  rm -f /etc/snmp/snmpd.conf
fi
rm -f /usr/share/snmp/mibs/raidv4.mib

echo "== remove SLP registration (marker block) =="
if [ -f /etc/slp.reg ]; then
  sed -i '/# BEGIN promise-webpam/,/# END promise-webpam/d' /etc/slp.reg
fi

echo "== remove /opt/promise =="
rm -rf /opt/promise

if [ "$PURGE" = "--purge" ]; then
  echo "== purge /etc/promise (operator config) =="
  rm -rf /etc/promise
fi

systemctl restart nginx 2>/dev/null
systemctl restart 'php*-fpm' 2>/dev/null
systemctl try-restart snmpd 2>/dev/null
echo "DONE. (/etc/promise $([ "$PURGE" = --purge ] && echo purged || echo kept).)"
