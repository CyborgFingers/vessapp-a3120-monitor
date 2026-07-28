#!/usr/bin/env bash
# install-webpam.sh - install ONLY the WebPAM web tier (php_i2 + htdocs +
# nginx + php-fpm) onto the live A3120 running Proxmox VE / Debian trixie,
# wired to the REAL enclosure via the SMBus backend (VESSAPP_BACKEND=smbus).
#
#   sudo bash port/packaging/install-webpam.sh
#
# Derived from the Phase-6 install.sh, reduced to the web tier:
#  - no C daemon stack (promise-encld is already deployed and running; the
#    opasd/emaild/snmp tier is not needed for the web UI - php_i2 links the
#    substrate in-process), so no cmake needed on the box.
#  - PHP is whatever trixie ships (php-fpm 8.4): the htdocs got Phase-4-style
#    PHP 8 fixes and php-webpam.ini forces short_open_tag=On.
#  - Proxmox-safe: everything WebPAM binds to :8088; pveproxy (:8006), the
#    pve services and promise-encld.service are never touched. If WE install
#    nginx fresh, the distro default :80 site is disabled so the hypervisor
#    grows no unexpected listener.
#
# Enclosure data: /etc/promise/promise.env gets VESSAPP_BACKEND=smbus +
# VESSAPP_I2C_BUS=0; php-fpm imports that file via a unit drop-in, so the
# php_i2 substrate reads the PCA9555 LEDs (0x20/0x21), the NCT7802Y (0x2e)
# and the kernel hwmon directly. The RAID/drive pages degrade to EMPTY
# tables until the ASM2106B runs RAID-mode firmware (I2BDG_SG_DEV unset).
#
# Idempotent: safe to re-run; operator-edited /etc/promise/promise.env keys
# other than the two VESSAPP_* lines are never touched.
#
# Container mode (webpam-trixie-smoke.sh): no systemd inside the container ->
# the systemctl/udev/service steps are skipped; the harness starts php-fpm and
# nginx itself. Everything else runs identically to the box.
set -euo pipefail

PKG_DIR="$(cd "$(dirname "$0")" && pwd)"          # .../port/packaging
PORT_DIR="$(cd "$PKG_DIR/.." && pwd)"             # .../port

[ "$(id -u)" = 0 ] || { echo "run as root (sudo)"; exit 1; }
HAVE_SYSTEMD=0
[ -d /run/systemd/system ] && command -v systemctl >/dev/null && HAVE_SYSTEMD=1

echo "== [1/7] distro packages (trixie: nginx + php-fpm + phpize toolchain) =="
HAD_NGINX=1
dpkg -s nginx >/dev/null 2>&1 || HAD_NGINX=0
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq || true
apt-get install -y -qq nginx php-fpm php-dev php-cli libpam0g-dev >/dev/null
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "   PHP $PHPV (php-fpm$PHPV / phpize$PHPV), nginx $(nginx -v 2>&1 | sed 's|.*/||')"
if [ "$HAD_NGINX" = 0 ] && [ -e /etc/nginx/sites-enabled/default ]; then
  # We brought nginx onto this hypervisor - do not leave its default :80 site.
  rm -f /etc/nginx/sites-enabled/default
  echo "   nginx was newly installed: disabled the distro default :80 site"
fi

echo "== [2/7] build php_i2 against PHP $PHPV (phpize; no cmake needed) =="
( cd "$PORT_DIR/php-ext"
  make clean >/dev/null 2>&1 || true
  "phpize$PHPV" --clean >/dev/null 2>&1 || true
  "phpize$PHPV" >/dev/null
  ./configure --enable-i2=shared --with-php-config="php-config$PHPV" >/dev/null
  make -j"$(nproc)" >/dev/null )
install -d /opt/promise/lib/php /opt/promise/webpam
install -m 0644 "$PORT_DIR/php-ext/modules/i2.so" /opt/promise/lib/php/i2.so
php -n -d extension=/opt/promise/lib/php/i2.so -m | grep -qx i2 \
  || { echo "FATAL: php_i2 failed to load on php$PHPV"; exit 1; }
echo "   /opt/promise/lib/php/i2.so loads on php$PHPV"

echo "== [3/7] htdocs -> /opt/promise/webpam =="
rm -rf /opt/promise/webpam/htdocs
cp -a "$PORT_DIR/web/htdocs" /opt/promise/webpam/htdocs
install -m 0644 "$PKG_DIR/compat-prepend.php" /opt/promise/webpam/compat-prepend.php
echo "   $(find /opt/promise/webpam/htdocs -name '*.php' | wc -l | tr -d ' ') PHP pages installed"

echo "== [4/7] nginx :8088 vhost + FPM pool + php ini (pveproxy :8006 untouched) =="
install -m 0644 "$PKG_DIR/php-webpam.ini" "/etc/php/$PHPV/fpm/conf.d/90-promise-webpam.ini"
install -m 0644 "$PKG_DIR/fpm-webpam.conf" "/etc/php/$PHPV/fpm/pool.d/webpam.conf"
install -m 0644 "$PKG_DIR/nginx-webpam.conf" /etc/nginx/sites-available/promise-webpam.conf
ln -sfn /etc/nginx/sites-available/promise-webpam.conf /etc/nginx/sites-enabled/promise-webpam.conf
if command -v ss >/dev/null 2>&1 && ss -ltn 2>/dev/null | grep -q ':8088 ' \
   && ! ss -ltnp 2>/dev/null | grep ':8088 ' | grep -q nginx; then
  echo "   WARNING: something other than nginx already listens on :8088"
fi

echo "== [5/7] /etc/promise/promise.env: SMBus enclosure backend =="
install -d /etc/promise
[ -f /etc/promise/promise.env ] || install -m 0644 "$PKG_DIR/promise.env" /etc/promise/promise.env
ensure_kv() {  # ensure_kv KEY VALUE - replace (commented or not) or append
  if grep -qE "^#?$1=" /etc/promise/promise.env; then
    sed -i -E "s|^#?$1=.*|$1=$2|" /etc/promise/promise.env
  else
    echo "$1=$2" >> /etc/promise/promise.env
  fi
}
# The web tier reads the enclosure DIRECTLY over SMBus - the same proven path
# as promise-encld: PCA9555 @0x20 (FrontPanel) / @0x21 (NetworkPanel), NCT7802Y
# @0x2e, all on /dev/i2c-0 (addresses are compile-time constants of the smbus
# backend, verified on this box). I2BDG_SG_DEV stays UNSET: the ASM2106B is in
# AHCI mode, so RAID reads fail cleanly and drive/array pages render empty.
ensure_kv VESSAPP_BACKEND smbus
ensure_kv VESSAPP_I2C_BUS 0
echo "   VESSAPP_BACKEND=smbus VESSAPP_I2C_BUS=0 (PCA9555 0x20/0x21, NCT7802Y 0x2e)"

# opas dc/session runtime dir. The php_i2 extension keeps its login-session table
# in a MAP_SHARED file here so it survives an fpm restart (redeploy) instead of
# logging every WebPAM user out. The webpam pool runs as www-data, so the dir MUST
# be www-data-owned - otherwise open() fails and each worker silently falls back to
# a private, unshared table, so the login dc issued on one worker never validates on
# the next and cross-worker login fails. Create it now for this boot; under systemd
# add a tmpfiles.d rule so it returns www-data-owned after a reboot (/run is tmpfs).
install -d -m 0755 -o www-data -g www-data /run/promise
if [ "${HAVE_SYSTEMD:-}" = 1 ]; then
  install -m 0644 "$PKG_DIR/systemd/promise-webpam-tmpfiles.conf" /etc/tmpfiles.d/promise-webpam.conf
  systemd-tmpfiles --create /etc/tmpfiles.d/promise-webpam.conf 2>/dev/null || true
fi

# Real chassis serial from DMI (root-only) -> promise.env so the www-data ext
# shows it on subsystem.php instead of the fixture placeholder. Only when the
# BIOS actually populated a value (many boards ship "To Be Filled By O.E.M.").
if command -v dmidecode >/dev/null 2>&1; then
  # First real serial across system/baseboard/chassis - many boards leave the
  # system serial "To Be Filled By O.E.M." but DO populate the baseboard serial.
  # || true: no match -> grep exit 1, which under `set -o pipefail` would abort.
  ser=""
  for _k in system-serial-number baseboard-serial-number chassis-serial-number; do
    ser=$(dmidecode -s "$_k" 2>/dev/null | grep -iEv 'to be filled|default|^none$|o\.e\.m\.|^0+$|^[[:space:]]*$' | head -1 || true)
    [ -n "$ser" ] && break
  done
  [ -n "$ser" ] && { ensure_kv VESSAPP_SERIAL "$ser"; echo "   VESSAPP_SERIAL=$ser (from DMI)"; } \
                 || echo "   (no real DMI serial - subsystem.php keeps the placeholder; set VESSAPP_SERIAL in promise.env to override)"
fi

# WebPAM login password. The php_i2 ext verifies the MD5 challenge against
# /etc/promise/webpam.passwd; with no entry the on-device auth FAILS SECURE
# (login denied) rather than accepting any password. Build the setter tool and,
# if no password is set, tell the operator to set one (never ship a default).
echo "== [5c/7] WebPAM login password =="
install -d /opt/promise/bin
cc -O2 -Wall -o /opt/promise/bin/promise-webpam-passwd "$PORT_DIR/src/tools/promise_webpam_passwd.c" -lcrypt \
  && echo "   promise-webpam-passwd built" || echo "   (promise-webpam-passwd build skipped)"
if [ -f /etc/promise/webpam.passwd ]; then
  echo "   /etc/promise/webpam.passwd present (change: sudo /opt/promise/bin/promise-webpam-passwd <pw>)"
else
  echo "   NO password set - WebPAM login is DENIED until you run:"
  echo "       sudo /opt/promise/bin/promise-webpam-passwd <password>"
fi

# Linux-credential login: allowlisted Linux users (/etc/promise/webpam.pamusers)
# authenticate through PAM. Two setuid-root helpers - promise-pam-auth
# (pam_authenticate; must read /etc/shadow) and promise-pam-user (manages the
# allowlist) - plus the PAM service file. www-data (php_i2 / the user pages)
# execs them; both refuse system/root accounts (uid < 1000).
cc -O2 -Wall -o /opt/promise/bin/promise-pam-auth "$PORT_DIR/src/tools/promise_pam_auth.c" -lpam \
  && { chown root:www-data /opt/promise/bin/promise-pam-auth; chmod 4750 /opt/promise/bin/promise-pam-auth;
       echo "   promise-pam-auth -> root:www-data 4750 (setuid, PAM verify)"; } \
  || echo "   (promise-pam-auth build skipped - libpam0g-dev missing?)"
cc -O2 -Wall -o /opt/promise/bin/promise-pam-user "$PORT_DIR/src/tools/promise_pam_user.c" \
  && { chown root:www-data /opt/promise/bin/promise-pam-user; chmod 4750 /opt/promise/bin/promise-pam-user;
       echo "   promise-pam-user -> root:www-data 4750 (setuid, allowlist)"; } \
  || echo "   (promise-pam-user build skipped)"
install -m 0644 "$PKG_DIR/pam-promise-webpam" /etc/pam.d/promise-webpam

# Self-signed TLS cert for the HTTPS listener (nginx-webpam.conf :8443). WebPAM is
# all-TLS - :8088 redirects to :8443 - so the password, session token and all data
# are encrypted end-to-end (this is also what lets Linux/PAM login send the
# password securely). Generated once on first install; replace with a CA-signed
# cert any time. Key is root:www-data 0640 (nginx runs as root; www-data can't
# read it), cert is world-readable.
if [ ! -s /etc/promise/webpam.crt ] || [ ! -s /etc/promise/webpam.key ]; then
  _IP=$(hostname -I 2>/dev/null | awk '{print $1}')
  _HN=$(hostname -f 2>/dev/null || hostname)
  if openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
       -keyout /etc/promise/webpam.key -out /etc/promise/webpam.crt \
       -subj "/CN=${_HN:-vess-a3120}" \
       -addext "subjectAltName=DNS:${_HN:-localhost}${_IP:+,IP:$_IP}" >/dev/null 2>&1; then
    chown root:www-data /etc/promise/webpam.key; chmod 0640 /etc/promise/webpam.key; chmod 0644 /etc/promise/webpam.crt
    echo "   self-signed TLS cert generated -> WebPAM is HTTPS on :8443 (:8088 redirects)"
  else
    echo "   WARNING: openssl cert generation failed - the :8443 HTTPS listener will not start"
  fi
else
  echo "   TLS cert present (/etc/promise/webpam.crt) - replace with a CA cert any time"
fi

# Software-Management service pages (email/snmp/webserver/slp).
echo "== [5d/7] Software-Management service control =="
# promise-svc: setuid helper so the service pages can start/stop/enable the
# systemd-backed services - www-data (php-fpm) has no systemctl rights.
cc -O2 -Wall -o /opt/promise/bin/promise-svc "$PORT_DIR/src/tools/promise_svc.c" \
  && { chown root:www-data /opt/promise/bin/promise-svc; chmod 4750 /opt/promise/bin/promise-svc;
       echo "   promise-svc -> root:www-data 4750 (setuid)"; } \
  || echo "   (promise-svc build skipped)"
# emailsetting.xml is emaild's -f config AND what email.php get/setEmailSetting
# edit, so php_i2 (www-data) must be able to rewrite it: root:www-data 0664.
if [ ! -f /etc/promise/emailsetting.xml ]; then
  install -m 0664 "$PORT_DIR/src/emaild/emailsetting.xml" /etc/promise/emailsetting.xml 2>/dev/null \
    || install -m 0664 "$PKG_DIR/emailsetting.xml.example" /etc/promise/emailsetting.xml
  echo "   seeded /etc/promise/emailsetting.xml"
fi
chgrp www-data /etc/promise/emailsetting.xml 2>/dev/null || true
chmod 0664 /etc/promise/emailsetting.xml 2>/dev/null || true
# webserver.conf: session timeout for webserver.php get/setWebserverSetting (www-data RW).
[ -f /etc/promise/webserver.conf ] || echo "sessiontimeout=3600" > /etc/promise/webserver.conf
chgrp www-data /etc/promise/webserver.conf 2>/dev/null || true
chmod 0664 /etc/promise/webserver.conf 2>/dev/null || true

# Enclosure sensors need their kernel modules: i2c-dev/i2c-i801 for the SMBus
# (PCA9555/NCT7802Y blower), and nct6775 for the nct6776 Super-I/O that carries
# the Controller temp (hwmon SYSTIN). Without nct6775 the Controller temp reads
# 0. Load now + persist. nct6775 may refuse to bind if ACPI claims the SIO
# resources - then add 'acpi_enforce_resources=lax' to the kernel cmdline.
for m in i2c-dev i2c-i801 nct6775 pcspkr; do modprobe "$m" 2>/dev/null || true; done
printf 'i2c-dev\ni2c-i801\nnct6775\npcspkr\n' > /etc/modules-load.d/promise-encl.conf
if for h in /sys/class/hwmon/hwmon*; do cat "$h/name" 2>/dev/null; done | grep -q nct6776; then
  echo "   nct6776 hwmon present (Controller temp sensor live)"
else
  echo "   WARN: nct6776 absent - add acpi_enforce_resources=lax to the kernel cmdline, else Controller temp reads 0"
fi

# WebPAM's Fan Control / Front Panel pages and promise-encld share these two
# /etc/promise files (the daemon hot-reloads both on mtime change). Seed only
# when absent - operator edits are never overwritten. php-fpm (www-data) must
# be able to WRITE them: the pages save from the web tier.
if [ ! -f /etc/promise/fanconfig.json ]; then
  if [ -f /var/lib/promise-hwmon/config/fanconfig.json ]; then
    cp /var/lib/promise-hwmon/config/fanconfig.json /etc/promise/fanconfig.json
    echo "   fanconfig.json seeded from the promise-hwmon live curves"
  else
    install -m 0644 "$PKG_DIR/fanconfig.json" /etc/promise/fanconfig.json
    echo "   fanconfig.json seeded with the FanControlPatch defaults"
  fi
fi
if [ ! -f /etc/promise/frontpanel.json ]; then
  cat > /etc/promise/frontpanel.json <<'EOF'
{
    "EthLed1": { "Interface": "", "Mac": "", "Mode": 0 },
    "EthLed2": { "Interface": "", "Mac": "", "Mode": 0 }
}
EOF
  echo "   frontpanel.json seeded (both network LEDs unassigned)"
fi
chgrp www-data /etc/promise/fanconfig.json /etc/promise/frontpanel.json 2>/dev/null || true
chmod 0664 /etc/promise/fanconfig.json /etc/promise/frontpanel.json
# Point promise-encld at the shared fanconfig unless the operator already
# chose a path (an existing uncommented PROMISE_FANCONFIG= line wins).
if ! grep -qE '^PROMISE_FANCONFIG=' /etc/promise/promise.env; then
  if grep -qE '^#PROMISE_FANCONFIG=' /etc/promise/promise.env; then
    sed -i -E 's|^#PROMISE_FANCONFIG=.*|PROMISE_FANCONFIG=/etc/promise/fanconfig.json|' /etc/promise/promise.env
  else
    echo 'PROMISE_FANCONFIG=/etc/promise/fanconfig.json' >> /etc/promise/promise.env
  fi
  echo "   PROMISE_FANCONFIG=/etc/promise/fanconfig.json (restart promise-encld to adopt it)"
fi

# --- mdadm RAID engine privilege helper (ONLY when this box runs the Linux-
# native software RAID, i.e. /etc/promise/promise.env has VESSAPP_RAID_BACKEND=
# mdadm). php_i2 runs as www-data and can't run mdadm/smartctl/wipefs or open raw
# disks; promise-raid-helper is the sole privileged path (verb whitelist + whole-
# disk validation + an in-use/OS-disk guard, docs/RAID-ENGINE-MDADM-PLAN.md). It
# is a single standalone .c, so cc it here (the php_i2 build above already proved
# a compiler). Deliberately NOT installed on sgio/AHCI boxes (the Proxmox
# hypervisor) - no setuid-root binary on a host that never calls it.
if grep -qE '^VESSAPP_RAID_BACKEND=mdadm([[:space:]]|$)' /etc/promise/promise.env 2>/dev/null; then
  echo "== [5b/7] mdadm RAID backend: build + install promise-raid-helper (setuid root) =="
  apt-get install -y -qq mdadm smartmontools util-linux snmp lvm2 >/dev/null 2>&1 || true
  install -d /opt/promise/bin
  cc -O2 -Wall -Wextra -o /opt/promise/bin/promise-raid-helper \
     "$PORT_DIR/src/raid/promise_raid_helper.c"
  chown root:www-data /opt/promise/bin/promise-raid-helper
  chmod 4750 /opt/promise/bin/promise-raid-helper
  echo "   /opt/promise/bin/promise-raid-helper -> root:www-data mode 4750 (setuid)"
  # mdadm --grow reshapes (P4 migrate) write a critical-section backup here.
  install -d -m 0700 -o root -g root /var/lib/promise-raid

  # P5 events: the mdadm --monitor handler + the SMTP/SNMP emitters + event log.
  install -m 0755 "$PKG_DIR/promise-mdmonitor" /opt/promise/bin/promise-mdmonitor
  cc -O2 -o /opt/promise/bin/emaild "$PORT_DIR/src/emaild/emaild.c" \
     "$PORT_DIR/src/emaild/smtp.c" "$PORT_DIR/src/emaild/emailcfg.c" \
     "$PORT_DIR/src/snmpd/event_map.c" -I"$PORT_DIR/src/emaild" -I"$PORT_DIR/src/snmpd" \
     2>/dev/null && echo "   emaild built" || echo "   (emaild build skipped)"
  cc -O2 -o /opt/promise/bin/promise_trap "$PORT_DIR/src/snmpd/promise_trap.c" \
     "$PORT_DIR/src/snmpd/event_map.c" -I"$PORT_DIR/src/snmpd" \
     2>/dev/null && echo "   promise_trap built" || echo "   (promise_trap build skipped)"
  # bgasched: scheduled media patrol / redundancy check (bgasched.timer runs it).
  cc -O2 -o /opt/promise/bin/bgasched "$PORT_DIR/src/daemons/bgasched.c" \
     "$PORT_DIR/src/daemons/daemon_util.c" -I"$PORT_DIR/src/daemons" \
     2>/dev/null && echo "   bgasched built" || echo "   (bgasched build skipped)"
  # promise-buzzer: PC-speaker fault alarm (promise-mdmonitor sounds it on a Major+ fault).
  cc -O2 -o /opt/promise/bin/promise-buzzer "$PORT_DIR/src/tools/promise_buzzer.c" \
     2>/dev/null && echo "   promise-buzzer built" || echo "   (promise-buzzer build skipped)"
  # buzzer mute/disable state - www-data (WebPAM buzzer.php) writes here to silence/disable.
  install -d -m 0770 -o root -g www-data /var/lib/promise-buzzer
  # fan calibration: www-data (fancalibrate.php) queues calibrate.request here;
  # promise-encld writes FanCalibration.json + calibrate.status. Shared dir.
  install -d -m 0755 /var/lib/promise-hwmon
  install -d -m 0770 -o root -g www-data /var/lib/promise-hwmon/data
  # event log: root-owned but group www-data so getEvents reads / clearEventLog truncates it.
  install -d -m 0755 /var/log/promise-raid
  [ -f /var/log/promise-raid/events.log ] || : > /var/log/promise-raid/events.log
  chgrp www-data /var/log/promise-raid/events.log 2>/dev/null || true
  chmod 0664 /var/log/promise-raid/events.log
  if [ "$HAVE_SYSTEMD" = 1 ]; then
    install -m 0644 "$PKG_DIR/systemd/promise-mdmonitor.service" \
      /etc/systemd/system/promise-mdmonitor.service
    { echo "DEVICE /dev/sd[a-z]"; mdadm --detail --scan 2>/dev/null; } > /etc/mdadm/mdadm.conf 2>/dev/null || true
    systemctl daemon-reload
    systemctl enable --now promise-mdmonitor.service >/dev/null 2>&1 \
      && echo "   promise-mdmonitor.service enabled (mdadm --monitor)" || true
    # Drive health: route smartd's SMART faults into the same event pipeline via
    # promise-smartd-notify (SMART_ERROR_RECEIVED). smartmontools is installed above;
    # the enabled unit reads /etc/smartd.conf, so back up any distro config once and
    # drop ours in, then restart to pick up the -M exec hook. Unit is smartmontools.
    # service on Debian (smartd.service is its alias); fall back if only the latter.
    install -m 0755 "$PKG_DIR/promise-smartd-notify" /opt/promise/bin/promise-smartd-notify
    [ -f /etc/smartd.conf ] && [ ! -f /etc/smartd.conf.pre-promise ] \
      && cp /etc/smartd.conf /etc/smartd.conf.pre-promise
    install -m 0644 "$PKG_DIR/smartd.conf" /etc/smartd.conf
    SMARTD_UNIT=smartmontools.service
    systemctl list-unit-files 2>/dev/null | grep -q '^smartmontools\.service' || SMARTD_UNIT=smartd.service
    systemctl enable "$SMARTD_UNIT" >/dev/null 2>&1 || true
    systemctl restart "$SMARTD_UNIT" >/dev/null 2>&1 \
      && echo "   $SMARTD_UNIT restarted (smartd -> promise-smartd-notify on SMART fault)" \
      || echo "   ($SMARTD_UNIT restart skipped)"
    if [ -x /opt/promise/bin/bgasched ]; then
      install -m 0644 "$PKG_DIR/systemd/bgasched.service" /etc/systemd/system/bgasched.service
      install -m 0644 "$PKG_DIR/systemd/bgasched.timer"   /etc/systemd/system/bgasched.timer
      systemctl daemon-reload
      systemctl enable --now bgasched.timer >/dev/null 2>&1 \
        && echo "   bgasched.timer enabled (monthly media patrol)" || true
    fi
  fi
fi

if [ "$HAVE_SYSTEMD" = 1 ]; then
  echo "== [6/7] php-fpm env drop-in + www-data i2c access =="
  install -d "/etc/systemd/system/php$PHPV-fpm.service.d"
  install -m 0644 "$PKG_DIR/systemd/php-fpm-promise-dropin.conf" \
    "/etc/systemd/system/php$PHPV-fpm.service.d/promise.conf"
  systemctl daemon-reload
  # FPM workers run as www-data and must open /dev/i2c-0 (reads + LED writes).
  getent group i2c >/dev/null || groupadd -r i2c
  usermod -aG i2c www-data
  cat > /etc/udev/rules.d/60-promise-i2c.rules <<'EOF'
# promise-webpam: php-fpm (www-data, group i2c) reads the enclosure over SMBus
KERNEL=="i2c-[0-9]*", GROUP="i2c", MODE="0660"
EOF
  udevadm control --reload-rules 2>/dev/null || true
  udevadm trigger --subsystem-match=i2c-dev 2>/dev/null || true
  for d in /dev/i2c-*; do [ -e "$d" ] && { chgrp i2c "$d"; chmod 0660 "$d"; }; done
  echo "   www-data in group i2c; /dev/i2c-* now root:i2c 0660 (udev rule persists it)"

  echo "== [7/7] services: enable + restart nginx and php$PHPV-fpm ONLY =="
  systemctl enable nginx "php$PHPV-fpm" >/dev/null 2>&1 || true
  systemctl restart "php$PHPV-fpm"
  nginx -t >/dev/null 2>&1 && systemctl restart nginx
  systemctl is-active --quiet pveproxy 2>/dev/null \
    && echo "   pveproxy still active on :8006 (untouched)" || true
  systemctl is-active --quiet promise-encld 2>/dev/null \
    && echo "   promise-encld still active (untouched)" || true
else
  echo "== [6/7]+[7/7] no systemd (container): service/udev steps skipped =="
fi

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
cat <<EOF

DONE. WebPAM:  http://${IP:-<this-host>}:8088/index.php
  login: administrator / the password set with promise-webpam-passwd
  (login is DENIED until a password is set: sudo promise-webpam-passwd <pw>)
  enclosure pages (Subsystem -> Enclosure/front view): REAL chassis data over
  SMBus; drive/array pages are EMPTY until the ASM2106B RAID-mode reboot.
  Proxmox UI stays on https://${IP:-<this-host>}:8006 (untouched).
  uninstall: sudo bash port/packaging/uninstall-webpam.sh
EOF
