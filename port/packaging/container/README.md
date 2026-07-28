# WebPAM monitor — TrueNAS SCALE container

A read-only drive/pool health dashboard (drives, SMART, temps, pool state) with
**no drive-management** ability. On TrueNAS SCALE, run it as a **Custom App**
instead of a host install (host installs get wiped on TrueNAS updates).

It reads the host's own `zpool` / `smartctl` via `nsenter` into PID 1, so the
tool versions always match the host and no ZFS userland ships in the image. It
therefore needs **`privileged: true` + `pid: host`** — and it only ever *reads*
(there is no create/delete/migrate code path anywhere in this build).

## 1. Build the image

On the TrueNAS box (or any Docker host), from the `webpam-monitor` clone root:

```bash
docker build -f port/packaging/container/Dockerfile -t webpam-monitor:local .
```

(On TrueNAS SCALE: `ssh admin@nas`, then `sudo docker build ...`. Building an
image does not touch your pools.)

## 2. Install as a TrueNAS Custom App

**Apps → Discover Apps → Custom App → "Install via YAML"**, and paste
`docker-compose.yml` from this directory. Set `WEBPAM_PASSWORD` to your chosen
login password first. That's it — TrueNAS starts it and keeps it running.

Or on any Docker host:

```bash
cd port/packaging/container && docker compose up -d
```

## 3. Set the login password (if you didn't use WEBPAM_PASSWORD)

```bash
docker exec webpam-monitor /opt/promise/bin/promise-webpam-passwd 'your-password'
```

## 4. Open it

`https://<nas-ip>:8443/` — log in as `administrator` with the password above.
(:8088 redirects to 8443. The cert is self-signed; accept the browser warning.)

You'll see the pools/arrays, physical drives with SMART + temperature, and the
fan/enclosure pages (empty unless the box is Promise A3120 hardware). Every
drive-management action is removed and blocked.

## Notes

- **Why privileged?** Only to `nsenter` into the host for `zpool`/`smartctl`.
  If you'd rather run **unprivileged**, the alternative is an API-based data
  source (probe talks to the TrueNAS middleware with an API key instead of
  nsenter) — ask and it can be added; it trades one API key for dropping
  `privileged`/`pid: host`.
- **Refresh cadence:** `HEALTH_REFRESH` seconds (default 60). `smartctl` runs
  once per cycle on the host, never on a page load.
- **Persistence:** the TLS cert + password live in the `webpam_monitor_cfg`
  volume, so they survive app updates/restarts.
