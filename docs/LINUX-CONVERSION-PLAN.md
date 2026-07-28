# Full-port validation: convert the A3120 test box to Linux

Goal: prove the **whole** port (not just the enclosure surface) on real A3120
hardware with the RAID controller in **RAID mode**, before production. This
unlocks the SG_IO/SEP engine path that is gated on the AHCI/production Proxmox
box: drive/array management, engine-driven events, SMTP/SNMP alerts, and the
full daemon stack.

Box: the golden vendor A3120 (`192.0.2.211`, currently Windows). OS SSD =
ADATA 128GB (~40GB used); the 4×3.64TB RAID5 array lives on the ASM2106B, not
the SSD.

## 1. Backup (DONE via SSH — the extractable reference)

Captured to `reference/promise-vendor-golden/` (gitignored) + the committed docs:
- `clu-comprehensive.txt` — every CLU class (`clitest -C <class> -a list -v`).
- `vendor-ref.zip` — `docs/` (QSG PDF, EventTraps XLS, raidv4/IF MIBs), the full
  `WebServer/htdocs` PHP source, and vendor configs (userdb/oem/settings).
- Already committed: `docs/RE-scsi-wire-capture.md` + `docs/samples/` (StorPort +
  Frida wire captures), `docs/CALIBRATION-A3120-golden.md` (golden sensor values).

## 2. Disk image + install (OPERATOR, at the console)

The bootable full backup + OS install must be done on-site (can't wipe/install
safely over SSH). During that session, **before** installing Debian:
1. **Image the OS SSD offline** — boot Clonezilla (or a Linux USB) and image the
   ADATA SSD to your NAS/USB (`clonezilla` device-image, or `dd if=/dev/sdX |
   gzip > a3120-windows.img.gz`). ~40GB used → a small image. This is the
   restore path for the vendor Windows stack.
2. **Leave the controller in RAID mode** in the BIOS (as it is for Windows) — so
   Linux sees the RAID volume + the SG_IO management endpoint.
3. **Install Debian 13 (trixie)** on the SSD (matches the Proxmox prod box).
   Preserve the array (install only to the SSD).
4. Enable SSH + add the key `~/.ssh/vessapp_windows.pub` (or a fresh one) so I can
   resume remotely.

⚠️ The array (4×3.64TB RAID5) has data — confirm it is expendable test data
before any RAID-management testing (§3 step 4), or back it up first.

## 3. Post-install (ME, remote — the real validation)

1. **Confirm the SG_IO mgmt path** — the genuine unknown: does the ASM2106B in
   RAID mode expose a `/dev/sg*` management endpoint to Linux the way it does to
   Windows storport (target 254)? Run `port/scripts/calibrate.sh` + `sg_inq`/
   `sg_ses`/`lsscsi -g`; look for the Promise controller + the mgmt LUN. If it
   does NOT appear, that's driver work to do (and exactly what this test exists
   to find).
2. **Deploy the full port**: `port/packaging/install.sh` (the whole daemon stack:
   opasd + emaild + promise_snmp + prevtlogd + bgasched/pwrmon + WebPAM) with
   `I2BDG_SG_DEV=/dev/sgN` in `/etc/promise/promise.env` (the SG_IO engine path,
   not `VESSAPP_BACKEND=smbus`).
3. **Test every feature end-to-end** against the real controller + array and
   validate parity vs. the captured reference (§1):
   - Drive/array/logical-drive read + (if array expendable) create/migrate/rebuild.
   - Events + the event log (prevtlogd) driven by real engine events.
   - SMTP alerts (emaild) + SNMP walk/traps (promise_snmp) on a real fault.
   - Enclosure/fans/temps/volts/LEDs over the engine path (cross-check vs. the
     SMBus path we already run).
   - The CLI story (the full CLU is not yet ported — decide whether to port it
     once the engine path is proven).
4. Fix whatever the real hardware surfaces; iterate to exact parity → then the
   port is proven for production.

## Notes
- The Proxmox prod box stays as-is (AHCI, VMs) — no reboot needed there for this.
- Keep `reference/promise-vendor-golden/` until the Linux port is validated; it's
  the parity ground-truth once Windows is gone.
