/*
 * raid_backend.h - selects which RAID engine the i2bApi_* RAID reads use.
 *
 * The A3120's RAID is a Windows-software construct: on Linux the drives are
 * raw SATA disks with no controller/SEP engine (docs/RAID-ENGINE-MDADM-PLAN.md).
 * So the port implements the RAID engine on Linux backed by mdadm, selectable
 * at runtime (mirrors encl_backend.h / VESSAPP_BACKEND):
 *
 *   VESSAPP_RAID_BACKEND = sgio  (default) - vendor SCSI CDB path over libi2
 *                                            (i2_raid.c). Unchanged behaviour.
 *                          mdadm          - Linux-native software RAID view:
 *                                           PDs from lsblk+smartctl, DAs/LDs/
 *                                           spares from mdadm + /proc/mdstat.
 *                          fixture        - the mdadm code paths, but tool
 *                                           output read from canned files in
 *                                           $VESSAPP_RAID_FIXTURE (container
 *                                           smokes; no mdadm/hardware needed).
 *
 * The selector is read once (getenv) and cached. Default sgio means every
 * existing caller and unit test behaves exactly as before.
 */
#ifndef RAID_BACKEND_H
#define RAID_BACKEND_H

#include <stddef.h>
#include "i2bdg_api.h"

typedef enum {
    RAID_SGIO    = 0,
    RAID_MDADM   = 1,
    RAID_FIXTURE = 2,
    RAID_HEALTH  = 3,   /* webpam-monitor: read-only ZFS/generic health from a snapshot file */
} raid_backend_t;

/* Active backend (VESSAPP_RAID_BACKEND, cached). Defaults to RAID_SGIO. */
raid_backend_t raid_backend(void);

/* Test override; pass a negative value to clear the cache and re-read env. */
void raid_backend_set(int b);

/*
 * Command seam: every external tool the mdadm backend consults goes through
 * raid_cmd() (same idea as the i2c/scsi mocks). Returns 0 with the tool's
 * stdout NUL-terminated in out (possibly empty), -1 if the tool could not be
 * run at all. Resolution order:
 *   1. a function injected by raid_cmd_set() (unit tests)
 *   2. RAID_FIXTURE backend: read $VESSAPP_RAID_FIXTURE/<slug(cmd)>
 *   3. popen(cmd) capture
 */
typedef int (*raid_cmd_fn)(const char *cmd, char *out, size_t cap);
void raid_cmd_set(raid_cmd_fn fn);            /* NULL restores default        */
int  raid_cmd(const char *cmd, char *out, size_t cap);

/* Like raid_cmd() but for PRIVILEGED tools (mdadm/smartctl/wipefs/syncaction):
 * on the real popen path the command is prefixed with the setuid helper
 * (promise-raid-helper; path from PROMISE_RAID_HELPER, else /opt/promise/bin/).
 * Under the mock (raid_cmd_set) and RAID_FIXTURE it runs BARE, so unit tests and
 * container fixtures see the exact bare tool command they always did. */
int  raid_cmd_priv(const char *cmd, char *out, size_t cap);

/* Fixture filename for a command line: [A-Za-z0-9] kept, everything else '_'.
 * Exposed so tests and the container smoke write the same names. */
void raid_cmd_slug(const char *cmd, char *out, size_t cap);

/* mdadm/fixture struct-level readers (raid_mdadm.c) - same contracts as the
 * i2bApi_* getters they stand in for (READ-only enumeration, Phase 1). */
I2B_API_RET_E raid_mdadm_getDiskArrayInfo(I2bArrayInfo_t *out, unsigned long buflen);
I2B_API_RET_E raid_mdadm_getHardDiskDriveInfo(I2bHddInfo_t *out, unsigned long buflen);
I2B_API_RET_E raid_mdadm_getLogDrvInfo(I2bLogDrvInfo_t *out, unsigned long buflen);
I2B_API_RET_E raid_mdadm_getStatsLogDrv(I2bLogDrvStatusInfo_t *out, unsigned long buflen);

/* health struct-level readers (raid_health.c) - read-only ZFS/generic enumeration
 * from the promise-health snapshot file (webpam-monitor, VESSAPP_RAID_BACKEND=health). */
I2B_API_RET_E raid_health_getDiskArrayInfo(I2bArrayInfo_t *out, unsigned long buflen);
I2B_API_RET_E raid_health_getHardDiskDriveInfo(I2bHddInfo_t *out, unsigned long buflen);
I2B_API_RET_E raid_health_getLogDrvInfo(I2bLogDrvInfo_t *out, unsigned long buflen);
I2B_API_RET_E raid_health_getStatsLogDrv(I2bLogDrvStatusInfo_t *out, unsigned long buflen);

/* Kernel device name ("sda") of physical drive <pd_id> (1-based), for the
 * privileged smartctl read behind getPhysicalDriveSMARTInfo. 1 if found. */
int raid_mdadm_pd_osname(unsigned short pd_id, char *out, size_t cap);

/* Inverse: 1-based PDId of kernel name "sda" (0 if not a data PD), for mapping
 * the mdadm recovering member to a Promise PD id on the BGA monitor pages. */
unsigned short raid_mdadm_osname_pd(const char *name);

/* Kernel device name ("md0") of logical-drive <ld_id> (md index), for the LD
 * I/O statistics getter. 1 if found. */
int raid_mdadm_ld_osname(unsigned short ld_id, char *out, size_t cap);

/*
 * Write path (Phase 2, docs/RAID-ENGINE-MDADM-PLAN.md). Only ever called when
 * the mdadm/fixture backend is selected - the default sgio path is untouched.
 * All tool invocations go through raid_cmd() (mock/fixture-able). Every
 * destructive op takes an EXPLICIT id/member list and enforces the root-disk /
 * non-member guard: a target that is not one of the enumerated data PDs
 * (which by construction exclude the OS disk and VESSAPP_RAID_EXCLUDE) is
 * refused with a clear message in err. Return 0 on success, -1 on failure
 * (err carries an operator-readable reason, always NUL-terminated).
 */
int raid_mdadm_create(int raid_level,               /* I2BAPI_LDRAIDLVL_*      */
                      const unsigned short *pd_ids, /* PDId list (1-based)     */
                      unsigned npds,
                      unsigned chunk_kb,            /* 0 = mdadm default       */
                      unsigned long long capacity,  /* LD bytes; 0 = whole disk */
                      char *err, size_t errcap);
/* Delete DA/LD <id> (v1 model: 1 md = 1 DA = 1 LD, so both ids are the md
 * index): mdadm --stop + --zero-superblock on each member. */
int raid_mdadm_delete(unsigned id, char *err, size_t errcap);
/* Initialization = rewrite parity/mirror ("repair" scrub); start=0 -> idle. */
int raid_mdadm_init_ld(unsigned id, int start, char *err, size_t errcap);
/* Synchronize / Redundancy Check = "check" scrub; start=0 -> idle. */
int raid_mdadm_synch_ld(unsigned id, int start, char *err, size_t errcap);

/* Spare (P3). Add data PD <pd_id> (1-based) as a spare to array <array_id>
 * (mdadm --add /dev/md<array_id> /dev/sd<pd>). array_id < 0 = "Global": v1
 * requires exactly one array to attach it to (spare-group migration across
 * arrays is a P5/monitor concern). Same root-disk / non-member guard as create;
 * a busy PD (already an array member) is refused. */
int raid_mdadm_add_spare(unsigned short pd_id, int array_id, char *err, size_t errcap);
/* Remove the spare drive(s) of array <array_id> (mdadm --remove). Keyed by
 * array id because the WebPAM spare record is (deleteSpareDrives = acd_ArrayID). */
int raid_mdadm_remove_spare(unsigned array_id, char *err, size_t errcap);

/* Migrate / reshape (P4). Reshape array <array_id> to the target member set
 * <pd_ids> (1-based; existing + any new disks) and, if new_level >= 0 and
 * different, the new RAID level. Supported: same-level expansion (OCE) and the
 * mdadm reshapes RAID5->RAID6 / RAID1->RAID5 / RAID0->RAID5; anything else is
 * refused with a clear message. New disks are mdadm --add'd then mdadm --grow. */
int raid_mdadm_migrate(unsigned array_id, const unsigned short *pd_ids, unsigned npds,
                       int new_level, char *err, size_t errcap);
/* Media patrol = 'check' scrub of every array (mdadm sync_action). */
int raid_mdadm_media_patrol(char *err, size_t errcap);

/* --- LVM logical-drive layer (docs/RAID-ENGINE-LVM-LD-PLAN.md) -------------- *
 * A Promise "logical drive within a disk array" = an LVM LV in a VG (vgmd<id>)
 * on the md device. Free fragments = the VG's free extents. All tools go through
 * raid_cmd()/raid_cmd_priv() (mock/fixture-able); the read helpers use raid_cmd
 * (lvs/vgs are readable through the setuid helper), writes use raid_cmd_priv. */
typedef struct { char name[64]; unsigned long long bytes; } raid_lv_t;
typedef struct { unsigned long long addr; unsigned long long bytes; } raid_frag_t;

/* Ensure array <md_id> has a PV+VG (vgmd<md_id>). Lazy, idempotent. */
int      raid_mdadm_ensure_vg(unsigned md_id, char *err, size_t errcap);
/* Name-sorted LVs of array <md_id>'s VG -> out[]; returns the count (0 = no VG). */
unsigned raid_mdadm_lv_list(unsigned md_id, raid_lv_t *out, unsigned cap);
/* Free extents of array <md_id>'s VG as whole-array {addr,bytes} fragments. */
unsigned raid_mdadm_lv_free(unsigned md_id, raid_frag_t *out, unsigned cap);
/* Free space available to create LDs on array <md_id> as whole-array fragments:
 * the VG's free extents if a VG exists, else the whole array capacity (no VG yet).
 * Read-only - never creates a VG (that happens on the first lv_create). */
unsigned raid_mdadm_da_free_fragments(unsigned md_id, raid_frag_t *out, unsigned cap);
/* Create LD (LV) <name> of <bytes> in array <md_id>'s VG (ensures the VG first). */
int      raid_mdadm_lv_create(unsigned md_id, const char *name,
                              unsigned long long bytes, char *err, size_t errcap);
/* Delete the LD (LV) at name-sorted <ordinal> in array <md_id>'s VG. */
int      raid_mdadm_lv_delete(unsigned md_id, unsigned ordinal, char *err, size_t errcap);
/* Grow the LD (LV) at <ordinal> to <bytes> (P3 migrate / OCE). */
int      raid_mdadm_lv_resize(unsigned md_id, unsigned ordinal,
                              unsigned long long bytes, char *err, size_t errcap);

#endif /* RAID_BACKEND_H */
