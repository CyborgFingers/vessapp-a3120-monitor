# WebPAM monitor — TrueNAS container

A read-only drive/pool health dashboard (drives, SMART, temps, pool state) with
**no drive-management** ability. On TrueNAS, run it as a **Custom App** (host
installs get wiped on TrueNAS updates).

It reads the host's own `zpool` / `smartctl` via `nsenter` into PID 1, so the
tool versions always match the host and no ZFS userland ships in the image. It
therefore needs **`privileged: true` + `pid: host`** — and it only ever *reads*.

## Install (no build) — TrueNAS

**Apps → Discover Apps → Custom App → "Install via YAML"**, paste
`docker-compose.yml` from this directory, set `WEBPAM_PASSWORD`, and install.
TrueNAS pulls the published image
(`ghcr.io/weta-technologies/vessapp-a3120-monitor:latest`) — **no build step** —
starts it, and keeps it running.

Then open `https://<nas-ip>:8443/` and log in as `administrator` with that
password (:8088 redirects to 8443; the cert is self-signed, accept the warning).

### Sign in with your TrueNAS login
With `VESSAPP_TRUENAS_SYNC: "1"` (default in the compose), on start the app
creates a **`webpam`** group in TrueNAS and lets anyone in that group — and any
TrueNAS admin — sign in to WebPAM with their **TrueNAS username + password**.
Manage access from the TrueNAS group; WebPAM follows it. The built-in
`administrator` login keeps working.

### Updating
When a newer release is published, WebPAM shows an **update banner** on login;
**Update now** pulls the new image and restarts the app — settings (login, fan
calibration, front-panel config) are kept on the persistent volume. If the in-app
apply doesn't complete, use TrueNAS **Apps → Update** for this app.

## Build locally instead (development)
From the `webpam-monitor` clone root:
```bash
docker build -f port/packaging/container/Dockerfile -t webpam-monitor:local .
```
then set `image: webpam-monitor:local` + `pull_policy: never` in the compose, or
run `docker compose up -d` from this directory on any Docker host.

## Notes
- **Why privileged?** Only to `nsenter` into the host for `zpool`/`smartctl` (and
  `midclt` for the TrueNAS login/updater). An unprivileged, API-key data source
  can be added on request.
- **Refresh cadence:** `HEALTH_REFRESH` seconds (default 60).
- **Persistence:** TLS cert + password + calibration live in the
  `webpam_monitor_cfg` volume (`/etc/promise`), surviving updates/restarts.
- **Plain Docker host** (no TrueNAS `midclt`): leave `VESSAPP_TRUENAS_SYNC` unset
  — login stays local (`administrator`); the update check still works (apply is
  TrueNAS-specific).
