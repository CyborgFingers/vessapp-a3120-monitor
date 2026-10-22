#!/usr/bin/env bash
# webpam-monitor container entrypoint: refresh the health snapshot from the HOST
# (root, via nsenter) on a loop, and serve the read-only UI (php-fpm + nginx).
set -uo pipefail
# /run/promise MUST be www-data-owned: the php_i2 opas session store is a
# MAP_SHARED file the www-data FPM workers create here. Root-owned -> workers
# fall back to private per-worker tables and cross-worker login fails.
install -d -m 0755 -o www-data -g www-data /run/promise 2>/dev/null || mkdir -p /run/promise
mkdir -p /run/php /etc/promise
# Fan calibration: WebPAM (www-data) drops calibrate.request here; promise-encld
# (root) sweeps the fans and writes FanCalibration.json back. Lives under the
# PERSISTED /etc/promise volume (PROMISE_CALIBRATION points here for both tiers) +
# www-data-writable, so "Run Calibration" works AND the result SURVIVES a redeploy
# (it was ephemeral under /var/lib/promise-hwmon, wiped on every app update).
install -d -m 0775 -o www-data -g www-data /etc/promise/hwmon 2>/dev/null || true

# --- TLS cert (persisted when /etc/promise is a mounted volume) ---------------
if [ ! -s /etc/promise/webpam.crt ] || [ ! -s /etc/promise/webpam.key ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
    -keyout /etc/promise/webpam.key -out /etc/promise/webpam.crt \
    -subj "/CN=${WEBPAM_CN:-webpam-monitor}" >/dev/null 2>&1 \
    && echo "[entrypoint] generated self-signed TLS cert" \
    || echo "[entrypoint] WARNING: openssl cert generation failed - :8443 will not start"
fi
chown root:www-data /etc/promise/webpam.key 2>/dev/null || true
chmod 0640 /etc/promise/webpam.key 2>/dev/null || true
chmod 0644 /etc/promise/webpam.crt 2>/dev/null || true

# --- login password: from WEBPAM_PASSWORD on first run, else prompt to set it --
if [ ! -f /etc/promise/webpam.passwd ]; then
  if [ -n "${WEBPAM_PASSWORD:-}" ]; then
    /opt/promise/bin/promise-webpam-passwd "$WEBPAM_PASSWORD" \
      && echo "[entrypoint] login password set from WEBPAM_PASSWORD"
  else
    echo "[entrypoint] NO login password set - login is DENIED until you run:"
    echo "[entrypoint]   docker exec <container> /opt/promise/bin/promise-webpam-passwd <password>"
  fi
fi

# --- health snapshot: initial run + background refresh loop (root, nsenter) ----
refresh() {
  /usr/bin/python3 /opt/promise/bin/promise-health-probe > /run/promise/health.dat.tmp 2>/dev/null \
    && chmod 0644 /run/promise/health.dat.tmp \
    && mv -f /run/promise/health.dat.tmp /run/promise/health.dat
}
if refresh; then
  echo "[entrypoint] health snapshot: $(grep -c '^PD|' /run/promise/health.dat 2>/dev/null || echo 0) drives, $(grep -c '^ARRAY|' /run/promise/health.dat 2>/dev/null || echo 0) arrays"
else
  echo "[entrypoint] WARNING: probe produced no snapshot. Needs 'privileged: true' + 'pid: host'"
  echo "[entrypoint]          so nsenter can read the host's zpool/smartctl. Pages will be empty."
fi
# Hard Disk Sentinel drive-health bridge (opt-in). HD Sentinel is PROPRIETARY
# freeware -> not bundled in this GPL image; fetched from hdsentinel.com into the
# persisted /etc/promise volume on first run. Slow cadence (drive health changes
# slowly + avoid hammering SMART). Writes /run/promise/hdsentinel.json for the
# drive pages + promise-alerts. Dormant if fetch fails -> pages fall back to smartctl.
if [ "${VESSAPP_HDSENTINEL:-0}" = 1 ]; then
  /usr/bin/python3 -I /opt/promise/bin/promise-hdsentinel --fetch >/dev/null 2>&1 || true
  hds_refresh() { /usr/bin/python3 -I /opt/promise/bin/promise-hdsentinel >/dev/null 2>&1 || true; }
  hds_refresh
  ( while true; do sleep "${HDS_REFRESH:-900}"; hds_refresh; done ) &
fi

# System Events snapshot: TrueNAS alert.list + enclosure sensor faults + syslog
# tail -> /run/promise/alerts.json for events.php; emails new enclosure faults
# through the TrueNAS mail relay (midclt mail.send). Root, same nsenter path.
alerts_refresh() { /usr/bin/python3 -I /opt/promise/bin/promise-alerts >/dev/null 2>&1 || true; }
alerts_refresh
( while true; do sleep "${HEALTH_REFRESH:-60}"; refresh || true; alerts_refresh; done ) &

# --- host NIC mirror: the container has its OWN netns (eth0/lo only), but the
# front-panel network LEDs + frontpanel.php must see the HOST's physical NICs.
# www-data can't nsenter (no CAP_SYS_ADMIN), so mirror the host NICs here as root
# into $VESSAPP_NET_ROOT (name/mac/operstate/carrier, same layout as sysfs);
# frontpanel.php lists them, promise-encld tracks their link state for the LEDs.
if [ -n "${VESSAPP_NET_ROOT:-}" ] && [ "${VESSAPP_PROBE_NSENTER:-0}" = 1 ]; then
  mirror_nics() {
    local tmp="${VESSAPP_NET_ROOT}.tmp"
    rm -rf "$tmp" 2>/dev/null; mkdir -p "$tmp"
    # -m -n: enter host mount+net ns so /sys/class/net is the HOST's. Keep real
    # uplinks: a physical NIC has /device; a bond/LAGG master (TrueNAS teaming)
    # has /bonding. lo/bridge/veth/docker0 have neither -> skipped. Bond members
    # keep /device so they still list too - assign the bond or a member per LED.
    nsenter -t 1 -m -n -- sh -c '
      for d in /sys/class/net/*; do
        [ -e "$d/device" ] || [ -e "$d/bonding" ] || continue
        n=${d##*/}
        printf "%s\t%s\t%s\t%s\n" "$n" "$(cat "$d/address" 2>/dev/null)" \
          "$(cat "$d/operstate" 2>/dev/null)" "$(cat "$d/carrier" 2>/dev/null)"
      done' 2>/dev/null | while IFS="$(printf '\t')" read -r n mac st carr; do
        [ -n "$n" ] || continue
        mkdir -p "$tmp/$n"
        printf '%s\n' "$mac"  > "$tmp/$n/address"
        printf '%s\n' "$st"   > "$tmp/$n/operstate"
        printf '%s\n' "$carr" > "$tmp/$n/carrier"
      done
    chmod -R a+rX "$tmp" 2>/dev/null || true
    # ponytail: rm+mv swap has a sub-ms window with no dir; readers fall back to
    # the container /sys (eth0) for that instant - harmless on a 4s refresh.
    rm -rf "$VESSAPP_NET_ROOT" 2>/dev/null; mv "$tmp" "$VESSAPP_NET_ROOT" 2>/dev/null
  }
  mirror_nics   # initial pass BEFORE promise-encld + php-fpm start below
  ( while true; do sleep 4; mirror_nics || true; done ) &
  echo "[entrypoint] host NIC mirror -> ${VESSAPP_NET_ROOT} ($(ls "${VESSAPP_NET_ROOT}" 2>/dev/null | tr '\n' ' '))"
fi

# --- enclosure (A3120 SMBus): fans + front-panel LEDs -------------------------
if [ "${VESSAPP_BACKEND:-}" = smbus ]; then
  BUS="${VESSAPP_I2C_BUS:-0}"
  # Load the host i2c drivers (host kernel, via nsenter) so /dev/i2c-<bus> exists,
  # then materialise the device node in THIS (privileged) container so php_i2 and
  # promise-encld can open the SMBus (PCA9555 LEDs @0x20/0x21, NCT7802Y @0x2e).
  # -a: load ALL THREE as modules. Without it, modprobe reads "i2c-i801 nct6775"
  # as PARAMETERS to i2c-dev, so the nct6775 Super-I/O (CPU fan + system voltages)
  # never loads and the sensor page shows no CPU fan / empty voltages.
  nsenter -t 1 -m -u -i -n -p -- modprobe -a i2c-dev i2c-i801 nct6775 2>/dev/null || true
  for n in 0 1 2 3 4 5; do [ -e "/dev/i2c-$n" ] || mknod "/dev/i2c-$n" c 89 "$n" 2>/dev/null || true; done
  # php-fpm workers (www-data) read the NCT7802Y blowers over SMBus via php_i2, so
  # www-data needs rw on the node - mknod leaves it root-only, which silently drops
  # the 2 chassis blowers (the CPU fan survives, it's read from hwmon sysfs). Must
  # run BEFORE php-fpm starts below, or a worker caches the EACCES until reloaded.
  chgrp www-data /dev/i2c-* 2>/dev/null || true
  chmod 0660 /dev/i2c-* 2>/dev/null || true
  # fan + front-panel config live in the /etc/promise volume (WebPAM edits them,
  # promise-encld re-reads on mtime change); seed defaults on first run.
  [ -f /etc/promise/fanconfig.json ]  || cp /opt/promise/share/fanconfig.json  /etc/promise/fanconfig.json  2>/dev/null || true
  [ -f /etc/promise/frontpanel.json ] || cp /opt/promise/share/frontpanel.json /etc/promise/frontpanel.json 2>/dev/null || true
  # WebPAM (www-data) writes these from the Fan Control / Front Panel LED pages;
  # the cp above (or a volume seeded by an older image) leaves them root-owned ->
  # Save fails with "could not write". promise-encld reads them as root either
  # way. Run every start so an already-root-owned volume file is corrected too.
  chown www-data /etc/promise/fanconfig.json /etc/promise/frontpanel.json 2>/dev/null || true
  chmod 0664 /etc/promise/fanconfig.json /etc/promise/frontpanel.json 2>/dev/null || true
  if [ -e "/dev/i2c-$BUS" ]; then
    # CPU-fan control sensor. The vendor default "Core 0" tracks ONE core, which on
    # a multi-core host idles well below the CPU package (e.g. Core 0 ~57C while the
    # package is 70C) -> the fan sits at the low-temp curve band while the CPU is
    # actually hot. "auto" tracks the hottest real sensor (the CPU package/PECI here,
    # ignoring the negative/zero garbage channels and the cooler drives) and is
    # hardware-label-agnostic. Override with PROMISE_ENCLD_SENSOR=<label> if desired.
    PROMISE_FANCONFIG=/etc/promise/fanconfig.json PROMISE_FRONTPANEL=/etc/promise/frontpanel.json \
    PROMISE_ENCLD_SENSOR="${PROMISE_ENCLD_SENSOR:-auto}" \
      /opt/promise/bin/promise-encld >/var/log/promise-encld.log 2>&1 &
    echo $! > /run/promise/encld.pid
    echo "[entrypoint] promise-encld started (pid $(cat /run/promise/encld.pid)) on /dev/i2c-$BUS - fans + LEDs live"
  else
    echo "[entrypoint] WARNING: /dev/i2c-$BUS absent - SMBus enclosure unavailable (fans/LEDs off)"
  fi
fi

# --- TrueNAS user sync: mirror the `webpam` group (+ TrueNAS admins) into the
# WebPAM allowlist so those users sign in with their TrueNAS password. Opt-in via
# VESSAPP_TRUENAS_SYNC=1 (set in the TrueNAS compose; left off on a plain Docker
# host, which has no midclt). Runs as root here, so nsenter can reach midclt. ----
if [ "${VESSAPP_TRUENAS_SYNC:-0}" = 1 ]; then
  /opt/promise/bin/promise-webpam-group-sync \
    || echo "[entrypoint] WARNING: TrueNAS group sync failed (is midclt reachable via nsenter?)"
  ( while true; do sleep "${WEBPAM_SYNC_REFRESH:-300}"; \
      /opt/promise/bin/promise-webpam-group-sync >/dev/null 2>&1 || true; done ) &
fi

# --- updater: apply a queued update (root). update.php (www-data) drops the
# target tag in /run/promise/update.request; we perform the pull + restart via the
# host app updater, so the unprivileged web tier never updates itself. -----------
( while true; do
    if [ -f /run/promise/update.request ]; then
      /opt/promise/bin/promise-webpam-update >/dev/null 2>&1 || true
    fi
    if [ -f /run/promise/mailsync.request ]; then
      /opt/promise/bin/promise-webpam-mail-sync >/dev/null 2>&1 || true
    fi
    sleep 10
  done ) &

# --- serve (php-fpm workers = www-data read the snapshot; nginx front) ---------
echo "[entrypoint] starting php-fpm + nginx (read-only monitor) on :8443 (:8088 redirects)"
php-fpm8.4 -F &
FPM=$!
trap 'kill $FPM 2>/dev/null || true; nginx -s stop 2>/dev/null || true' TERM INT
exec nginx -g "daemon off;"
