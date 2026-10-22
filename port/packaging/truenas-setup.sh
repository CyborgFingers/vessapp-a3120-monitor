#!/usr/bin/env bash
# truenas-setup.sh - one-shot installer for the Vess A3120 WebPAM monitor on
# TrueNAS. Builds the container image and deploys it as a managed Custom App with
# the TrueNAS user-sync enabled, so WebPAM, the enclosure drivers (loaded by the
# app at start via nsenter), TrueNAS login, the updater and the email relay all
# come up on their own.
#
#   Run on the TrueNAS box:   sudo bash port/packaging/truenas-setup.sh
#   Non-interactive:          sudo WEBPAM_PASSWORD=yourpw bash port/packaging/truenas-setup.sh
#
# Re-running is safe: an existing app is redeployed onto the freshly built image.
set -euo pipefail

APP=webpam-monitor
IMAGE=webpam-monitor:local
HTTPS_PORT=${HTTPS_PORT:-8443}
HTTP_PORT=${HTTP_PORT:-8088}
PASSWORD=${WEBPAM_PASSWORD:-}

[ "$(id -u)" = 0 ] || { echo "Please run with sudo."; exit 1; }
command -v docker >/dev/null || { echo "docker not found - needs TrueNAS SCALE 24.10+ (Electric Eel) or newer."; exit 1; }
command -v midclt >/dev/null || { echo "midclt not found - run this on the TrueNAS host."; exit 1; }

cd "$(dirname "$0")/../.."   # repo root

if [ -z "$PASSWORD" ]; then
  read -rsp "Set the WebPAM 'administrator' password: " PASSWORD; echo
fi
[ -n "$PASSWORD" ] || { echo "A password is required."; exit 1; }

echo "[setup] building $IMAGE (first run pulls Debian + compiles the extension; a few minutes)..."
docker build -f port/packaging/container/Dockerfile -t "$IMAGE" .

COMPOSE_FILE=$(mktemp)
cat > "$COMPOSE_FILE" <<YAML
services:
  $APP:
    image: $IMAGE
    pull_policy: never
    container_name: $APP
    restart: unless-stopped
    privileged: true
    pid: host
    ports:
      - "$HTTPS_PORT:8443"
      - "$HTTP_PORT:8088"
    environment:
      WEBPAM_PASSWORD: "$PASSWORD"
      VESSAPP_TRUENAS_SYNC: "1"
    volumes:
      - webpam_monitor_cfg:/etc/promise
volumes:
  webpam_monitor_cfg:
YAML

if midclt call app.query "[[\"id\",\"=\",\"$APP\"]]" | grep -q "\"id\""; then
  echo "[setup] app '$APP' exists -> redeploying onto the new image"
  midclt call app.redeploy "$APP" >/dev/null
else
  echo "[setup] creating Custom App '$APP'"
  # NOTE: verify the app.create arg shape for your TrueNAS version if this fails;
  # the fallback is to paste port/packaging/container/docker-compose.yml via
  # Apps -> Discover -> Custom App -> Install via YAML.
  ARG=$(python3 -c 'import json,sys; print(json.dumps({"custom_app": True, "app_name": sys.argv[1], "custom_compose_config_string": open(sys.argv[2]).read()}))' "$APP" "$COMPOSE_FILE")
  if ! midclt call app.create "$ARG" >/dev/null 2>&1; then
    echo "[setup] app.create failed on this TrueNAS version."
    echo "[setup] Fallback: Apps -> Discover -> Custom App -> Install via YAML, and paste:"
    echo "----------------------------------------------------------------"
    cat "$COMPOSE_FILE"
    echo "----------------------------------------------------------------"
    rm -f "$COMPOSE_FILE"; exit 1
  fi
fi
rm -f "$COMPOSE_FILE"

echo "[setup] done."
echo "[setup] Open https://<nas-ip>:$HTTPS_PORT and log in as 'administrator' (the password you set)."
echo "[setup] TrueNAS admins + members of the 'webpam' group can also sign in with their TrueNAS login."
