# Software RAID engine (mdadm-backed) for the Linux port

## Why
The A3120's RAID is a **Windows software construct** (the Promise driver + `i2`
engine present a virtual controller + `target 254` mgmt endpoint). On Linux the
drives are raw SATA disks — no controller, no SEP, no SG_IO engine, and no
Linux-readable array metadata (confirmed on the `.212` test box: `sg_ses` fails,
`mdadm --examine` finds no DDF/imsm). So the port must **implement** the RAID
engine on Linux, backed by **`mdadm`** (+ `smartctl`/`lsblk`/sysfs), and present
it through the existing Promise model so the WebPAM array/drive pages work
unchanged. (User decision: build the full engine, not just status.)

## Architecture — a selectable RAID backend (mirror the enclosure backend)
Today `i2_raid.c` marshals every RAID op to SG_IO via `i2_fw_cmd_ex`. Add a RAID
backend seam like `encl_backend.c`:
- `port/src/raid/raid_backend.{c,h}` — dispatch selected by
  **`VESSAPP_RAID_BACKEND=mdadm|sgio|fixture`** (default `sgio` for the appliance
  model; `mdadm` for the Linux-native boxes).
- `raid_sgio.c` — thin wrapper over the existing `i2_raid.c` (unchanged behaviour).
- `raid_mdadm.c` — NEW: produces the **same** Promise structs (`I2bHddInfo_t`,
  `I2bLogDrvInfo_t`, `I2bDiskArrayInfo_t`, spare — see `i2bdg_raid.c` +
  `i2bApi_x64` headers) from Linux, and maps writes to mdadm.
- `i2bdg_raid.c` / the php_i2 RAID methods call the backend, not `i2_raid.c`
  directly. php_i2, `arrays.php`/`logdrv*.php`/`phydrv*.php`/`spare*.php` unchanged.

## Model mapping (Promise → mdadm)
| Promise | Linux |
|---|---|
| **Physical Drive** (PD, `I2bHddInfo`) | `/dev/sd*` (exclude OS disk): model/serial/cap/temp/health from `smartctl -i/-A/-H` + sysfs; ConfigStatus = which md it's in / free / spare |
| **Disk Array** (DA, `I2bDiskArrayInfo`) | an md array's member set; CfgCapacity = Σ members; Free = unallocated; AvailableRAIDLevels from mdadm |
| **Logical Drive** (LD, `I2bLogDrvInfo`) | `/dev/mdN`: RAIDLevel, Capacity, StripeSize=chunk, SYNCed/rebuild% from `/proc/mdstat`, WWN synthesised, cache policy N/A |
| **Spare** | mdadm spare device |
| create array+LD | `mdadm --create /dev/mdN -l<lvl> -n<cnt> -c<chunk> <members>` |
| delete LD/DA | `mdadm --stop /dev/mdN` + `mdadm --zero-superblock` members |
| init / synch | full resync vs `--assume-clean`; scrub = `echo check > /sys/block/mdN/md/sync_action` |
| rebuild | automatic on spare/replace; state from `/proc/mdstat` |
| migrate (RAID level / OCE) | `mdadm --grow` (reshape; document the subset mdadm supports) |
| media patrol | periodic `check` scrub |

**v1 simplification:** 1 md device = 1 DA carrying 1 LD (the common A3120 case).
Multi-LD-per-array (Promise allows N LDs on one DA) is a later LVM-on-md layer;
note it, don't block v1.

## Phases
- **P1 — read backend (non-destructive):** `raid_mdadm.c` enumeration — PDs from
  `/dev/sd*`+smartctl, DAs+LDs from `mdadm --detail --scan`/`/proc/mdstat`, spares.
  Wire `VESSAPP_RAID_BACKEND` dispatch. WebPAM shows the real disks as available
  PDs (+ any md array as DA/LD). Container unit tests against fixture
  `mdadm`/`smartctl`/sysfs output. Deploy to `.212`, confirm the 4 drives appear.
- **P2 — create/delete (destructive — needs the array expendable):** apply-config
  → `mdadm --create`; delete → `--stop`+`--zero-superblock`. Test on `.212`:
  create a RAID5 across the 4×3.64TB, confirm it shows as DA0/LA0 like the vendor.
  **BUILT (container-validated):** the WebPAM already passes STRUCTURED params
  (`createDiskArray($da,$pds,$lds)` / `deleteDiskArrays` / `deleteLogicalDrives`
  / init+RC start-stop / auto+expressConfig) — php_i2 implements them against
  `raid_mdadm_create/delete/init_ld/synch_ld` (raid_mdadm.c), so the vendor's
  opaque apply-config payload is never involved on this backend. Hard root-disk
  / non-member guard on every destructive target; per-DA Cfg/Free capacity +
  AvailableRAIDLevels now populate arrays.php/array.php. sgio default is
  byte-identical (write verbs stay the cataloged empty-array no-op). Unit
  suite asserts the exact mdadm/sync_action argv + OS-disk rejection; trixie
  smoke step 9 drives the real wizard create→enumerate loop. Still pending:
  the destructive `.212` run.
- **P3 — spares, rebuild, init/synch:** add/remove spare; pull a disk → rebuild
  onto spare, watch state; init/scrub.
- **P4 — migrate/reshape + media patrol** (the mdadm-supported subset).
- **P5 — events:** `mdadm --monitor` (degraded/rebuild/fail/spare) → the port's
  event log + **emaild (SMTP)** + **promise_snmp (traps)**. Deploy the full daemon
  stack (`install.sh`) on `.212` and inject a fault to prove email + trap + event.

## Test rig = the `.212` box
Real A3120 enclosure hardware + the 4×3.64TB drives (raw), not production. Deploy
the full port (`install.sh` + WebPAM, `VESSAPP_RAID_BACKEND=mdadm`) and validate
each phase on real disks. ⚠️ P2+ wipe the drives — confirm the array data is
expendable first.

## Non-goals / honest limits
- mdadm can't reproduce every Promise migration path (some OCE/level changes) —
  document what's supported vs. rejected-with-a-clear-error, don't fake it.
- Cache policy / WBack-WThrough is a controller concept — reported as N/A or mapped
  to the md write-mostly/bitmap where sensible.
