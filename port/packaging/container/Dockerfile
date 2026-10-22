# webpam-monitor - read-only WebPAM drive/pool health monitor, as a container.
#
# Targets TrueNAS SCALE (and any Docker host). The web tier runs IN the container;
# the health data comes from the HOST's own zpool/smartctl, reached via nsenter
# into PID 1 (so tool versions always match the host) - hence the container must
# run privileged with pid:host (see docker-compose.yml). It only ever READS.
#
# Build (from the webpam-monitor clone root):
#   docker build -f port/packaging/container/Dockerfile -t webpam-monitor:local .

# ---------- build stage: compile php_i2 (health backend) ----------
FROM debian:trixie AS build
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
      php-dev php-cli build-essential autoconf pkg-config libcrypt-dev libpam0g-dev cmake \
 && rm -rf /var/lib/apt/lists/*
COPY port /src/port
RUN set -eux; cd /src/port/php-ext; \
    find . -name '*.dep' -delete || true; find . -name '*.lo' -delete || true; \
    phpize; \
    ./configure --enable-i2=shared --with-php-config="$(command -v php-config)"; \
    make -j"$(nproc)"; \
    test -f modules/i2.so; \
    cc -O2 -o /promise-webpam-passwd /src/port/src/tools/promise_webpam_passwd.c -lcrypt; \
    cc -O2 -o /promise-pam-auth-shim /src/port/src/tools/promise_pam_auth_shim.c
# enclosure daemon: drives the A3120 fans + front-panel LEDs over SMBus
RUN set -eux; cd /src/port; \
    cmake -B build-encld -DCMAKE_BUILD_TYPE=Release >/dev/null 2>&1; \
    cmake --build build-encld --target promise_encld -j"$(nproc)"; \
    cp "$(find build-encld -name promise-encld -type f | head -1)" /promise-encld

# ---------- runtime stage ----------
FROM debian:trixie-slim
ENV DEBIAN_FRONTEND=noninteractive
# apt -y upgrade patches the base image's packages (PHP included) to the latest
# trixie security level at build time; rebuilding on each release keeps it current.
RUN apt-get update && apt-get -y upgrade && apt-get install -y --no-install-recommends \
      nginx php8.4-fpm php8.4-cli python3 util-linux smartmontools openssl ca-certificates curl \
 && rm -rf /var/lib/apt/lists/*
# extension + password tool
COPY --from=build /src/port/php-ext/modules/i2.so /opt/promise/lib/php/i2.so
COPY --from=build /promise-webpam-passwd          /opt/promise/bin/promise-webpam-passwd
COPY --from=build /promise-encld                  /opt/promise/bin/promise-encld
COPY port/packaging/fanconfig.json                /opt/promise/share/fanconfig.json
COPY VERSION                                      /opt/promise/share/VERSION
# htdocs + probe + the shipped web-tier configs (used verbatim, like _fpm-inner.sh)
COPY port/web/htdocs                       /opt/promise/webpam/htdocs
# php-webpam.ini sets auto_prepend_file to this SAPI-compat shim; EVERY page
# require()s it, so it must be present or all pages fatal.
COPY port/packaging/compat-prepend.php     /opt/promise/webpam/compat-prepend.php
COPY port/packaging/promise-health-probe   /opt/promise/bin/promise-health-probe
# TrueNAS user sync + midclt auth backend (sign in with your TrueNAS login). The
# setuid-root shim lands at the hardcoded promise-pam-auth path php_i2 execs; it
# re-execs the python backend as root so it can nsenter -> the host's `midclt`.
COPY --from=build /promise-pam-auth-shim          /opt/promise/bin/promise-pam-auth
COPY port/packaging/promise-midclt-auth           /opt/promise/bin/promise-midclt-auth
COPY port/packaging/promise-webpam-group-sync     /opt/promise/bin/promise-webpam-group-sync
COPY port/packaging/promise-webpam-update         /opt/promise/bin/promise-webpam-update
COPY port/packaging/promise-webpam-mail-sync      /opt/promise/bin/promise-webpam-mail-sync
COPY port/packaging/php-webpam.ini         /etc/php/8.4/fpm/conf.d/90-promise-webpam.ini
COPY port/packaging/fpm-webpam.conf        /etc/php/8.4/fpm/pool.d/webpam.conf
COPY port/packaging/nginx-webpam.conf      /etc/nginx/conf.d/webpam.conf
COPY port/packaging/container/entrypoint.sh /entrypoint.sh
# NB: php-webpam.ini (90-promise-webpam.ini) already has `extension=...i2.so`,
# so do NOT add a second extension= line here (double-load warning).
RUN set -eux; \
    rm -f /etc/php/8.4/fpm/pool.d/www.conf /etc/nginx/sites-enabled/default; \
    chmod +x /opt/promise/bin/promise-health-probe /opt/promise/bin/promise-encld /entrypoint.sh; \
    chown root:root /opt/promise/bin/promise-pam-auth /opt/promise/bin/promise-midclt-auth /opt/promise/bin/promise-webpam-group-sync /opt/promise/bin/promise-webpam-update /opt/promise/bin/promise-webpam-mail-sync; \
    chmod 4755 /opt/promise/bin/promise-pam-auth; \
    chmod 0755 /opt/promise/bin/promise-midclt-auth /opt/promise/bin/promise-webpam-group-sync /opt/promise/bin/promise-webpam-update /opt/promise/bin/promise-webpam-mail-sync; \
    php8.4 -n -d extension=/opt/promise/lib/php/i2.so -m | grep -qx i2; \
    mkdir -p /run/promise /run/php /opt/promise/share; \
    printf '%s\n' '{ "EthLed1": { "Interface": "", "Mac": "", "Mode": 0 }, "EthLed2": { "Interface": "", "Mac": "", "Mode": 0 } }' > /opt/promise/share/frontpanel.json
ENV VESSAPP_RAID_BACKEND=health \
    VESSAPP_HEALTH_FILE=/run/promise/health.dat \
    VESSAPP_PROBE_NSENTER=1 \
    VESSAPP_BACKEND=smbus \
    VESSAPP_I2C_BUS=0 \
    VESSAPP_ENCLOSURE=1 \
    VESSAPP_NET_ROOT=/run/promise/net \
    PROMISE_CALIBRATION=/etc/promise/hwmon/FanCalibration.json \
    HEALTH_REFRESH=60
EXPOSE 8443 8088
ENTRYPOINT ["/entrypoint.sh"]
