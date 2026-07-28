/* raid_health.c - VESSAPP_RAID_BACKEND=health: a READ-ONLY drive/pool health
 * source for the webpam-monitor fork.
 *
 * It fills the SAME I2bHddInfo_t / I2bArrayInfo_t / I2bLogDrvInfo_t records the
 * mdadm backend fills (so phydrvs.php / arrays.php / logdrvs.php render live
 * health), but reads them from the snapshot file that promise-health.timer
 * refreshes - promise-health-probe emits pipe-delimited PD/ARRAY/LD lines from
 * `zpool status` + `smartctl` (ZFS) or `smartctl` + /proc/mdstat + `lvs`
 * (generic). There is NO write path in this backend: array/LD create, delete,
 * migrate and spare ops simply do not exist here, so the web tier cannot touch a
 * disk no matter what page is reached.
 *
 * Snapshot grammar (one record per line, "|k=v" tokens):
 *   SUBSYS|backend=..|host=..|enclosure=0|1
 *   ARRAY|id|name|level|state|size|free|members
 *   PD|id|arrayid|osname|model|serial|size|state|temp|smart
 *   LD|id|arrayid|name|size|used|state
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "i2bdg_api.h"
#include "raid_backend.h"

#define PD_TEMP_WARN 55          /* same thresholds as raid_mdadm.c / the golden CLU */
#define PD_TEMP_CRIT 65
#define H_MAX_ARR 64
#define H_MAX_PD  128
#define H_MAX_LD  256

typedef struct { int id, members; char name[64], level[16], state[24];
                 unsigned long long size, freeb; } h_arr_t;
typedef struct { int id, arrayid, temp, bay; char osname[32], model[48], serial[40],
                 state[24], smart[12]; unsigned long long size; } h_pd_t;
typedef struct { int id, arrayid; char name[64], state[24];
                 unsigned long long size, used; } h_ld_t;
typedef struct {
    h_arr_t arr[H_MAX_ARR]; int narr;
    h_pd_t  pd[H_MAX_PD];   int npd;
    h_ld_t  ld[H_MAX_LD];   int nld;
    int enclosure;
} h_snap_t;

static const char *health_file(void) {
    const char *e = getenv("VESSAPP_HEALTH_FILE");
    return (e && *e) ? e : "/run/promise/health.dat";
}

/* copy the value of key `k` from a "|a=1|b=2" line into dst (bounded). */
static void kv(const char *line, const char *k, char *dst, size_t cap) {
    dst[0] = 0;
    size_t klen = strlen(k);
    const char *p = line;
    while ((p = strchr(p, '|')) != NULL) {
        p++;
        if (strncmp(p, k, klen) == 0 && p[klen] == '=') {
            const char *v = p + klen + 1;
            const char *end = strchr(v, '|');
            size_t n = end ? (size_t)(end - v) : strlen(v);
            if (n >= cap) n = cap - 1;
            memcpy(dst, v, n);
            dst[n] = 0;
            return;
        }
    }
}
static long long kvll(const char *line, const char *k) {
    char b[32];
    kv(line, k, b, sizeof b);
    return b[0] ? strtoll(b, NULL, 10) : 0;
}
static int kvi(const char *line, const char *k) { return (int)kvll(line, k); }

static void h_snapshot(h_snap_t *s) {
    memset(s, 0, sizeof *s);
    FILE *f = fopen(health_file(), "r");
    if (!f) return;
    char line[1024];
    while (fgets(line, sizeof line, f)) {
        line[strcspn(line, "\r\n")] = 0;
        if (strncmp(line, "SUBSYS|", 7) == 0) {
            s->enclosure = kvi(line, "enclosure");
        } else if (strncmp(line, "ARRAY|", 6) == 0 && s->narr < H_MAX_ARR) {
            h_arr_t *a = &s->arr[s->narr++];
            a->id = kvi(line, "id");
            kv(line, "name", a->name, sizeof a->name);
            kv(line, "level", a->level, sizeof a->level);
            kv(line, "state", a->state, sizeof a->state);
            a->size = (unsigned long long)kvll(line, "size");
            a->freeb = (unsigned long long)kvll(line, "free");
            a->members = kvi(line, "members");
        } else if (strncmp(line, "PD|", 3) == 0 && s->npd < H_MAX_PD) {
            h_pd_t *p = &s->pd[s->npd++];
            p->id = kvi(line, "id");
            p->arrayid = kvi(line, "arrayid");
            { char bb[16]; kv(line, "bay", bb, sizeof bb); p->bay = bb[0] ? atoi(bb) : -2; }
            kv(line, "osname", p->osname, sizeof p->osname);
            kv(line, "model", p->model, sizeof p->model);
            kv(line, "serial", p->serial, sizeof p->serial);
            p->size = (unsigned long long)kvll(line, "size");
            kv(line, "state", p->state, sizeof p->state);
            p->temp = kvi(line, "temp");
            kv(line, "smart", p->smart, sizeof p->smart);
        } else if (strncmp(line, "LD|", 3) == 0 && s->nld < H_MAX_LD) {
            h_ld_t *l = &s->ld[s->nld++];
            l->id = kvi(line, "id");
            l->arrayid = kvi(line, "arrayid");
            kv(line, "name", l->name, sizeof l->name);
            l->size = (unsigned long long)kvll(line, "size");
            l->used = (unsigned long long)kvll(line, "used");
            kv(line, "state", l->state, sizeof l->state);
        }
    }
    fclose(f);
}

/* pool/array redundancy string -> vendor RAID-level code (best fit). */
static unsigned char level_code(const char *lv) {
    if (!lv || !*lv) return 0xff;
    if (strstr(lv, "raidz2") || strstr(lv, "raidz3") || strstr(lv, "raid6")) return I2BAPI_LDRAIDLVL_RAID6;
    if (strstr(lv, "raid10")) return I2BAPI_LDRAIDLVL_RAID10;
    if (strstr(lv, "raidz")  || strstr(lv, "raid5")) return I2BAPI_LDRAIDLVL_RAID5;
    if (strstr(lv, "mirror") || strstr(lv, "raid1")) return I2BAPI_LDRAIDLVL_RAID1;
    if (strstr(lv, "stripe") || strstr(lv, "raid0")) return I2BAPI_LDRAIDLVL_RAID0;
    return 0xff;
}

/* health state word -> vendor op-status vocabulary (arrays.php/logdrvs colour). */
static const char *op_word(const char *st) {
    if (!st || strcmp(st, "OK") == 0)   return "OK";
    if (strcmp(st, "Degraded") == 0)    return "Critical";
    if (strcmp(st, "Failed") == 0 ||
        strcmp(st, "Offline") == 0)     return "Offline";
    return st;                          /* "Rebuilding", etc. pass through */
}

/* ------------------------------------------------------------ struct filling */

I2B_API_RET_E raid_health_getHardDiskDriveInfo(I2bHddInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    h_snap_t s;
    h_snapshot(&s);
    unsigned n = (unsigned)s.npd;
    if (buflen < (unsigned long)n * sizeof(I2bHddInfo_t)) return I2B_API_RET_PARAM_ERR;
    for (unsigned i = 0; i < n; i++) {
        const h_pd_t *p = &s.pd[i];
        I2bHddInfo_t *d = &out[i];
        memset(d, 0, sizeof *d);
        d->NumOfPD = n;
        d->PDId = (unsigned short)(i + 1);
        d->drvType = I2BAPI_DRV_TYPE_SATA;      /* probe does not split SAS/SATA; SATA default */
        snprintf((char *)d->driveModelNo, sizeof d->driveModelNo, "%s", p->model);
        snprintf((char *)d->osName, sizeof d->osName, "%s", p->osname);
        d->confCapacity = p->size;
        d->enclosureId = 0;    /* MUST be 0: phydrvs.php filters PDs on pdi_EnclosureId == ssid(0) */
        /* bay -1 = internal boot disk (no hot-swap bay) -> 0xFFFF so the PD record
         * builder omits pdi_SlotId and the front-view leaves it out of the bays. */
        if      (p->bay == -1) d->slotId = 0xFFFF;
        else if (p->bay >= 0)  d->slotId = (unsigned short)p->bay;
        else                   d->slotId = (unsigned short)i;   /* snapshot w/o bay: enum order */
        unsigned flag = PHYDRV_STATE_ONLINE | PHYDRV_COND_PRESENT | PHYDRV_COND_ACCESSIBLE;
        const char *cfg = (p->arrayid >= 0) ? "Array" : "Unconfigured";
        char op[32] = "OK";
        if (p->arrayid >= 0) {
            flag |= PHYDRV_CONFIG_CONFIGURED;
            d->arrayId = (unsigned short)p->arrayid;
        }
        if (strcmp(p->state, "Failed") == 0 || strcmp(p->state, "Offline") == 0) {
            flag &= ~PHYDRV_STATE_ONLINE;
            strcpy(op, "Offline");
        } else if (strncmp(p->state, "Rebuild", 7) == 0) {
            flag = (flag & ~PHYDRV_STATE_ONLINE) | PHYDRV_STATE_REBUILD;
            strcpy(op, "Rebuilding");
        }
        if (strcmp(p->smart, "FAILED") == 0 && strcmp(op, "OK") == 0) {  /* SMART predicted failure */
            flag |= PHYDRV_COND_PFA;
            strcpy(op, "PFA");
        }
        d->opStatusFlag = flag;
        strncpy((char *)d->opStatus, op, sizeof d->opStatus - 1);
        strncpy((char *)d->configStatus, cfg, sizeof d->configStatus - 1);
        d->temperature = (unsigned char)(p->temp > 0 ? p->temp : 0);
        d->HDDTempWarThreshold = PD_TEMP_WARN;
        d->HDDTempCriThreshold = PD_TEMP_CRIT;
        d->TempStatusFlag = p->temp >= PD_TEMP_CRIT ? PD_TEMPERATURE_STS_OVER_CRITICAL
                          : p->temp >= PD_TEMP_WARN ? PD_TEMPERATURE_STS_OVER_WARNING
                          : PD_TEMPERATURE_STS_NORMAL;
    }
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E raid_health_getDiskArrayInfo(I2bArrayInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    h_snap_t s;
    h_snapshot(&s);
    unsigned nmd = (unsigned)s.narr;
    unsigned long need = sizeof(I2bArrayInfo_t) +
                         (nmd ? (nmd - 1) * sizeof(I2bDiskArrayData_t) : 0);
    if (buflen < need) return I2B_API_RET_PARAM_ERR;
    out->DACnt = (unsigned char)nmd;
    for (unsigned i = 0; i < nmd; i++) {
        I2bDiskArrayData_t *d = &out->DAData[i];
        memset(d, 0, sizeof *d);
        d->DaId = (unsigned short)s.arr[i].id;
        d->NumOfHdd = (unsigned short)s.arr[i].members;
        d->CfgCapacity = s.arr[i].size;
        d->FreeCapacity = s.arr[i].freeb;
        snprintf((char *)d->ArrayName, sizeof d->ArrayName, "%s", s.arr[i].name);
        snprintf((char *)d->OpStatus, sizeof d->OpStatus, "%s", op_word(s.arr[i].state));
    }
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E raid_health_getLogDrvInfo(I2bLogDrvInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    h_snap_t s;
    h_snapshot(&s);
    unsigned total = 0;
    for (unsigned i = 0; i < (unsigned)s.nld; i++) {
        unsigned long need = sizeof(I2bLogDrvInfo_t) + (unsigned long)total * sizeof(I2bLogDrvData_t);
        if (need + sizeof(I2bLogDrvData_t) > buflen || total >= 255) break;
        const h_ld_t *l = &s.ld[i];
        I2bLogDrvData_t *d = &out->LDData[total];
        memset(d, 0, sizeof *d);
        d->LdId = (unsigned short)l->id;
        snprintf((char *)d->Alias, sizeof d->Alias, "%s", l->name);
        d->Capacity = l->size;
        d->arrayId = (unsigned short)l->arrayid;
        /* raid level comes from the owning pool/array */
        const char *lv = "";
        for (int a = 0; a < s.narr; a++)
            if (s.arr[a].id == l->arrayid) { lv = s.arr[a].level; break; }
        d->RaidLvl = level_code(lv);
        d->CachePolicy = I2BAPI_LDCACHEPOLICY_NOCACHE_WTHRU;
        unsigned flag = LOGDRV_CONFIG_CONFIGURED;
        if (strcmp(op_word(l->state), "OK") == 0) {
            flag |= LOGDRV_STATE_ONLINE | LOGDRV_COND_INITIALIZED | LOGDRV_COND_SYNCHRONIZED;
            d->SYNCed = I2BAPI_LDSYNCED_YES;
        } else {
            flag |= LOGDRV_STATE_CRITICAL;
            d->SYNCed = I2BAPI_LDSYNCED_NO;
        }
        d->OpStatusFlag = flag;
        strncpy((char *)d->OpStatus, op_word(l->state), sizeof d->OpStatus - 1);
        total++;
    }
    out->LDCnt = (unsigned char)total;
    return I2B_API_RET_SUCCESS;
}

/* LD I/O stats: a monitor has no per-LD throughput counters - report none. */
I2B_API_RET_E raid_health_getStatsLogDrv(I2bLogDrvStatusInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    if (buflen < sizeof(I2bLogDrvStatusInfo_t)) return I2B_API_RET_PARAM_ERR;
    out->LDCnt = 0;
    return I2B_API_RET_SUCCESS;
}
