# RAID engine — Logical Drives within a Disk Array (LVM)

Implements Promise's "multiple logical drives carved from one disk array" for the
mdadm backend. Complements `RAID-ENGINE-MDADM-PLAN.md` (which is the 1-md = 1-DA =
1-LD v1). Here the LD layer is **LVM on top of the md array**.

## Model

| Promise concept        | Linux implementation                                   |
|------------------------|--------------------------------------------------------|
| Disk Array (DA)        | the md RAID device `/dev/mdN` (redundancy layer)       |
| Array allocatable size | an LVM **VG** on the md (`mdN` → PV → VG `vgmdN`)       |
| Logical Drive (LD)     | an **LV** in that VG                                    |
| Free Fragment          | a contiguous run of free physical extents in the VG    |
| create / delete / grow | `lvcreate` / `lvremove` / `lvextend` (+ fs grow)       |

The VG is created lazily (`ensure_vg`) on the first free-fragment read or LD
create for an array that has no VG yet, so existing raw arrays migrate in with no
array-create change. A fresh array shows full free capacity + 0 LDs until LDs are
created (correct Promise semantics).

**LdId scheme (stable, reversible):** `LdId = (md_index << 16) | ordinal`, where
`ordinal` is the LV's position in the VG's name-sorted LV list. `md_index` = the N
in `mdN`. Delete/resize recover `(md_index, ordinal)` and re-enumerate to find the
LV. Alias/name = LV name; the create form's `rpal_u8Name` becomes the LV name
(sanitized to `[A-Za-z0-9_]`, uniquified).

## Data contract (from PHP, exact field names — do not rename)

**`$subsystem->getDiskArrayFreeFragments($daid)`** — NEW extension method
(currently unregistered → `__call` returns `[]`, so logdrvc shows
`nomorecapacityforcreateld`). Returns a numerically-keyed array of objects with
**per-PD units** (PHP multiplies `daff_u64Size` by `count($pds)` for whole-array):
- `daff_u64Addr` — free-region start offset (per PD)
- `daff_u64Size` — free size in bytes **per PD** = `VG_free_bytes / NumOfHdd`
- (`orgSize` is added by PHP, not us)
Callers: logdrvc.php:89, logdrvc2.php:48.

**`getFreeFragments($perPdFreeSize, $logicalDrives)`** — a bare **global** used only
by migrate2.php:120. Undefined anywhere on the port → migrate step 2 fatals. It is a
**synthetic PHP computation** (gaps between LDs + trailing free from the LD layout),
NOT a backend read. Implement in `common.php`. Returns `array<{daff_u64Addr,
daff_u64Size}>` in the same per-PD units.

**`addLogicalDrives($arrayId, $lds)`** — hard stub at i2_subsystem.c:522 (always
`RETURN_LONG(1)`). Real commit is logdrvc2.php:68. Per-LD spec fields (read with the
`obj_long()/obj_double()` helpers, cf. createDiskArray i2_subsystem.c:379):
`rpal_u8Name`, `rpal_u16RaidLevel`, `rpal_u16NoOfAxle`, `rpal_u32StripeSize` (KB),
`rpal_u8SectorSizeCode`, `rpal_u64Size` (bytes), `rpal_u16PreferredCtrlId`,
`rpal_u32LDFlags`, `rpal_u32LDAttrib`, `rpal_u64FragmentEndAddr`, `ffindex`,
`ldi_isNasLd`. v1: `lvcreate -n <name> -L <rpal_u64Size>B vgmdN` per element (raw LV,
no mkfs — host formats). Honor `MAX_LOGDRV_PER_ARRAY` (ldcfg.inc:165).

**`deleteLogicalDrives($daid, $ldiFlatIds)`** — i2_subsystem.c:410 currently loops
`raid_mdadm_delete(id)` which **stops the whole md**. Change to `lvremove` the LV(s)
by LdId; NEVER stop the md (the DA persists; array delete is a separate page).

**`getLogicalDrives()` LD object** (i2_records.c:105, `ldi_*`): `ldi_FlatId`,
`ldi_Alias`, `_name`, `ldi_RaidLevel`(str), `ldi_Capacity`, `_cfgCapacity`,
`ldi_ArrayId`, `ldi_Flags`, `_opStatus`. logdrvd.php filters on `ldi_ArrayId` and
deletes by `ldi_FlatId` (load-bearing); display cols `ldi_LDName/ldi_Size/…` are
cosmetic.

**`getDiskArray($id)->acd_LDs[]`** (i2_records.c:229) currently emits only
`ldd_LDFlatID, ldd_LDName, ldd_RaidLevel, ldd_LDSizeInBlocks, ldd_LDFlags`. Create/
migrate READ these too and they are **missing — must add**:
`cdd_Start` (running byte offset), `_LDSize` (numeric), `_raidLevel` (numeric),
`ldd_LDNoOfAxles`, `ldd_StripeSizeInBlocks` (512B blocks), `ldd_SectorSizeCode`,
`ldd_isNasLd`. Axles=1, sector-code=const, `cdd_Start`=running sum (not in the
`I2bLogDrvData_t` struct → synthesize during marshalling).

**Migrate** (P3): migrate1→migrate2→migrate3. migrate3.php:62 commits
`$subsystem->migrateDiskArray(acd_ArrayID, $newPDs, $migrateLDs)`. LV-level OCE
(expand an LD) = `lvextend` + fs grow; array reshape already exists
(`raid_mdadm_migrate`). Numeric RAID codes: 0,1,3,5,6,10=0x10,30,50,51,55,60.

## Backend additions

`raid_backend.h` + `raid_mdadm.c` — new seam fns (all tools via `raid_cmd_priv`):
- `int raid_mdadm_ensure_vg(unsigned md_id, char *err, size_t)` — if `mdN` has no
  PV/VG: `pvcreate /dev/mdN` + `vgcreate vgmdN /dev/mdN`.
- `raid_mdadm_lv_list(unsigned md_id, lv_t *out, unsigned *n)` — `lvs --noheadings -o
  lv_name,lv_size --units b --nosuffix vgmdN`, name-sorted.
- `I2B_API_RET_E raid_mdadm_getDiskArrayFreeFragments(unsigned da, frag_t *out, ...)`
  — `vgs -o vg_free --units b --nosuffix vgmdN`; one fragment (LVM free is usually
  contiguous), size divided by NumOfHdd for per-PD units.
- `raid_mdadm_lv_create(unsigned md_id, const char *name, u64 bytes, char *err,…)` —
  `lvcreate -n <name> -L <bytes>B vgmdN`.
- `raid_mdadm_lv_delete(unsigned md_id, unsigned ordinal, char *err,…)` — resolve LV
  by ordinal, `lvremove -f vgmdN/<lv>`.
- `raid_mdadm_lv_resize(unsigned md_id, unsigned ordinal, u64 newBytes, …)` (P3).
- Modify `raid_mdadm_getLogDrvInfo` (raid_mdadm.c:452): per md, emit one LD **per LV**
  (LdId=(md<<16)|ord, arrayId=md id, Capacity=lv size, RaidLvl=md level,
  StripeSz=md chunk). No VG → 0 LDs for that md.

`promise_raid_helper.c` — add verbs to the whitelist (§240): `pvcreate vgcreate
lvcreate lvremove lvextend lvs vgs pvs` → `/sbin/<v>` + `/usr/sbin/<v>`. Mark
`pvcreate/vgcreate/lvcreate/lvremove/lvextend` destructive so the OS-disk guard
covers any `/dev/` arg. VG/LV name args (`vgmdN`, `vgmdN/ld0`) are not `/dev/` paths
so they pass `is_ok_dev` untouched; `/dev/mdN` is already allowed.

`i2_subsystem.c` — register `PHP_METHOD(I2Subsystem, getDiskArrayFreeFragments)` in
the method table (§721) + impl (marshal frag_t → `{daff_u64Addr, daff_u64Size}`);
implement `addLogicalDrives` (read specs, `ensure_vg`, `lv_create` each);
rewrite `deleteLogicalDrives` (map LdId → lvremove, no md stop).

`i2_records.c` — marshal LVs in `getLogicalDrives` (already per-LD once
getLogDrvInfo emits per-LV) and add the missing `acd_LDs[]` fields (§229) with
synthesized `cdd_Start`/axles/sector-code.

`common.php` — define `getFreeFragments($perPdFreeSize, $logicalDrives)`.

## Phases
1. **Read** — helper LVM verbs; `ensure_vg`; `getDiskArrayFreeFragments` (+method);
   `getLogDrvInfo`→LVs; `acd_LDs` field gaps. Test: manual `pvcreate/vgcreate/lvcreate`
   on md0 → logdrvc shows free space, LDs list.
2. **Create + delete** — `addLogicalDrives`(lvcreate), `deleteLogicalDrives`(lvremove).
   Test: create 2 LDs via logdrvc/logdrvc2, list, delete one.
3. **Migrate** — `getFreeFragments` PHP global; `lv_resize` + wire migrate2/3.

## Test plan (.212, data expendable; OS disk = sde, never touch)
lvm2 installed; md0 = clean RAID5. Build helper + i2.so (backup the working i2.so,
verify the new one loads with `php -d extension=.../i2.so -m | grep i2` BEFORE
`systemctl restart php8.4-fpm`). Drive logdrvc → create LD → verify `lvs` + the LD
list → delete → migrate/expand.
