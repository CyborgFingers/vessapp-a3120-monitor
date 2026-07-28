/*
 * raid_mdadm.c - Linux-native (mdadm-backed) RAID READ backend, Phase 1.
 *
 * Fills the SAME Promise structs the WebPAM consumes (I2bHddInfo_t /
 * I2bArrayInfo_t / I2bLogDrvInfo_t / I2bLogDrvStatusInfo_t, i2bdg_api.h) from
 * the Linux view of the box:
 *
 *   Physical drives : lsblk -Pbn (identity/size, root-disk exclusion) +
 *                     smartctl -i -A -H (model/serial/firmware/temp/health)
 *   Disk arrays     : mdadm --detail --scan + mdadm --detail /dev/mdN
 *   Logical drives  : same mdadm detail + /proc/mdstat (sync state / %)
 *   Spares          : mdadm spare member devices -> PHYDRV_CONFIG_DEDICATEDSPARE
 *   LD statistics   : /sys/block/mdN/stat
 *
 * v1 model (docs/RAID-ENGINE-MDADM-PLAN.md): 1 md device = 1 DA carrying 1 LD.
 * Phase 2 adds the write path (bottom of this file): create -> mdadm --create,
 * delete -> --stop + --zero-superblock, init/synch -> sync_action scrubs, all
 * behind the same raid_cmd() seam and the root-disk/non-member guard.
 * Every external tool goes through the raid_cmd() seam (raid_backend.c), so
 * unit tests / the container smoke feed canned output with no hardware.
 */
#include "raid_backend.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <ctype.h>
#include <stdarg.h>

/* ponytail: fixed caps - the A3120 is a 4-bay box; 32/8 leaves headroom. */
#define MAX_PD   32
#define MAX_MD    8
#define CMD_CAP  16384

/* Golden A3120 PD temperature thresholds (CALIBRATION-A3120-golden.md). */
#define PD_TEMP_WARN 55
#define PD_TEMP_CRIT 65

typedef struct {
    char name[16];                  /* kernel name, e.g. "sda"   */
    char model[48];
    unsigned long long size;        /* bytes                     */
    int  temp;                      /* degC, 0 = unknown         */
    int  pfa;                       /* smartctl -H FAILED        */
    int  is_sas;
    int  md;                        /* member of mds[md], -1 = free */
    int  spare;                     /* spare member of its md    */
    int  faulty;                    /* md failed this member out of its array */
} pd_t;

typedef struct {
    char dev[32];                   /* "/dev/md0"                */
    int  id;                        /* DaId/LdId (md index)      */
    int  level;                     /* I2BAPI_LDRAIDLVL_* (-1 unknown) */
    unsigned long long cap;         /* bytes                     */
    unsigned long long used_dev;    /* bytes consumed per member */
    unsigned long long chunk;       /* bytes                     */
    int  degraded;
    int  rebuilding;                /* recovery in progress      */
    int  resyncing;                 /* resync/reshape/check      */
    int  sync_pct;                  /* -1 = n/a                  */
    int  nmemb;                     /* active + spare members    */
} md_t;

typedef struct {
    pd_t pd[MAX_PD]; unsigned npd;
    md_t md[MAX_MD]; unsigned nmd;
    char rootdisk[64];              /* kernel name of the OS disk, "" unknown */
} raid_snap_t;

/* --------------------------------------------------------------- utilities */

/* Value of KEY="..." in an lsblk -P line; 1 if found. */
static int pair_get(const char *line, const char *key, char *out, size_t cap) {
    char pat[32];
    snprintf(pat, sizeof pat, "%s=\"", key);
    const char *p = strstr(line, pat);
    if (!p) return 0;
    p += strlen(pat);
    size_t i = 0;
    for (; p[i] && p[i] != '"' && i < cap - 1; i++) out[i] = p[i];
    out[i] = '\0';
    return 1;
}

/* Rest-of-line (trimmed) after "Key :" in mdadm --detail / smartctl output. */
static int line_value(const char *buf, const char *key, char *out, size_t cap) {
    const char *p = strstr(buf, key);
    if (!p) return 0;
    p += strlen(key);
    while (*p == ' ' || *p == ':' || *p == '\t') p++;
    size_t i = 0;
    for (; p[i] && p[i] != '\n' && i < cap - 1; i++) out[i] = p[i];
    while (i && (out[i - 1] == ' ' || out[i - 1] == '\r')) i--;
    out[i] = '\0';
    return 1;
}

/* Number that may carry thousands separators ("4,000,787,030,016"). */
static unsigned long long comma_ull(const char *s) {
    unsigned long long v = 0;
    for (; *s; s++) {
        if (isdigit((unsigned char)*s)) v = v * 10 + (unsigned)(*s - '0');
        else if (*s != ',') break;
    }
    return v;
}

static int name_in_list(const char *name, const char *list) {
    size_t n = strlen(name);
    for (const char *p = list; p && *p; ) {
        while (*p == ',' || *p == ' ') p++;
        const char *e = p;
        while (*e && *e != ',' && *e != ' ') e++;
        if ((size_t)(e - p) == n && strncmp(p, name, n) == 0) return 1;
        p = e;
    }
    return 0;
}

/* ------------------------------------------------- physical drive discovery */

#define LSBLK_CMD "lsblk -Pbn -o NAME,TYPE,SIZE,MODEL,PKNAME,MOUNTPOINT"

/* Kernel name of the disk holding "/" (climbs PKNAME through partitions/dm).
 * ponytail: linear climb over the lsblk rows; multi-device (btrfs raid) roots
 * only exclude the first path - use VESSAPP_RAID_EXCLUDE for exotic layouts. */
static void find_root_disk(const char *lsblk, char *out, size_t cap) {
    out[0] = '\0';
    char cur[64] = "";
    for (const char *l = lsblk; l && *l; l = strchr(l, '\n') ? strchr(l, '\n') + 1 : NULL) {
        char mp[64];
        if (pair_get(l, "MOUNTPOINT", mp, sizeof mp) && strcmp(mp, "/") == 0) {
            pair_get(l, "NAME", cur, sizeof cur);
            break;
        }
    }
    for (int depth = 0; *cur && depth < 8; depth++) {          /* climb to disk */
        int found = 0;
        for (const char *l = lsblk; l && *l; l = strchr(l, '\n') ? strchr(l, '\n') + 1 : NULL) {
            char nm[64], pk[64], ty[16];
            if (!pair_get(l, "NAME", nm, sizeof nm) || strcmp(nm, cur) != 0) continue;
            found = 1;
            if (pair_get(l, "TYPE", ty, sizeof ty) && strcmp(ty, "disk") == 0) {
                snprintf(out, cap, "%s", cur);
                return;
            }
            if (pair_get(l, "PKNAME", pk, sizeof pk) && *pk) snprintf(cur, sizeof cur, "%s", pk);
            else return;                                        /* no parent */
            break;
        }
        if (!found) return;
    }
}

static void collect_smart(pd_t *d) {
    char cmd[64], *out = malloc(CMD_CAP);
    if (!out) return;
    snprintf(cmd, sizeof cmd, "smartctl -i -A -H /dev/%s", d->name);
    if (raid_cmd_priv(cmd, out, CMD_CAP) == 0 && *out) {
        char v[64];
        if (line_value(out, "Device Model", v, sizeof v) ||
            line_value(out, "Model Number", v, sizeof v)  ||    /* NVMe/SAS */
            line_value(out, "Product", v, sizeof v))
            snprintf(d->model, sizeof d->model, "%s", v);
        if (strstr(out, "Transport protocol:") && strstr(out, "SAS")) d->is_sas = 1;
        if (line_value(out, "User Capacity", v, sizeof v) && !d->size)
            d->size = comma_ull(v);
        if (strstr(out, "overall-health self-assessment test result: FAILED"))
            d->pfa = 1;
        /* ATA attr table: RAW_VALUE is the 10th column of the 194/190 row. */
        const char *t = strstr(out, "Temperature_Celsius");
        if (!t) t = strstr(out, "Airflow_Temperature");
        if (t) {
            int col = 0;
            for (const char *p = t; *p && *p != '\n'; ) {
                while (*p == ' ') p++;
                if (++col == 9) { d->temp = atoi(p); break; }   /* 9 cols after name */
                while (*p && *p != ' ' && *p != '\n') p++;
            }
        } else if (line_value(out, "Current Drive Temperature", v, sizeof v)) {
            d->temp = atoi(v);                                  /* SAS/SCSI form */
        }
    }
    free(out);
}

/* PDs = TYPE="disk" sd* rows minus the root disk minus VESSAPP_RAID_EXCLUDE. */
static void collect_pds(raid_snap_t *s, const char *lsblk) {
    const char *rootdisk = s->rootdisk;
    const char *excl = getenv("VESSAPP_RAID_EXCLUDE");
    for (const char *l = lsblk; l && *l && s->npd < MAX_PD;
         l = strchr(l, '\n') ? strchr(l, '\n') + 1 : NULL) {
        char nm[64], ty[16], sz[24], model[48];
        if (!pair_get(l, "NAME", nm, sizeof nm)) continue;
        if (!pair_get(l, "TYPE", ty, sizeof ty) || strcmp(ty, "disk") != 0) continue;
        if (strncmp(nm, "sd", 2) != 0) continue;                /* raw SATA/SAS only */
        if (*rootdisk && strcmp(nm, rootdisk) == 0) continue;   /* OS disk */
        if (excl && name_in_list(nm, excl)) continue;
        pd_t *d = &s->pd[s->npd++];
        memset(d, 0, sizeof *d);
        snprintf(d->name, sizeof d->name, "%s", nm);
        if (pair_get(l, "SIZE", sz, sizeof sz)) d->size = strtoull(sz, NULL, 10);
        if (pair_get(l, "MODEL", model, sizeof model)) snprintf(d->model, sizeof d->model, "%s", model);
        d->md = -1;
        collect_smart(d);
    }
}

/* --------------------------------------------------------- md array discovery */

static pd_t *pd_by_name(raid_snap_t *s, const char *name) {
    for (unsigned i = 0; i < s->npd; i++)
        if (strcmp(s->pd[i].name, name) == 0) return &s->pd[i];
    return NULL;
}

static int level_from_str(const char *v) {
    if (strstr(v, "raid0"))  return I2BAPI_LDRAIDLVL_RAID0;
    if (strstr(v, "raid10")) return I2BAPI_LDRAIDLVL_RAID10;   /* before raid1 */
    if (strstr(v, "raid1"))  return I2BAPI_LDRAIDLVL_RAID1;
    if (strstr(v, "raid5"))  return I2BAPI_LDRAIDLVL_RAID5;
    if (strstr(v, "raid6"))  return I2BAPI_LDRAIDLVL_RAID6;
    return -1;                        /* linear/raid4/... - shown as Unknown */
}

static void parse_detail(raid_snap_t *s, md_t *m, const char *detail) {
    char v[128];
    if (line_value(detail, "Raid Level", v, sizeof v)) m->level = level_from_str(v);
    if (line_value(detail, "Array Size", v, sizeof v)) m->cap = comma_ull(v) * 1024ULL; /* KiB */
    if (line_value(detail, "Used Dev Size", v, sizeof v)) m->used_dev = comma_ull(v) * 1024ULL;
    if (line_value(detail, "Chunk Size", v, sizeof v)) {
        unsigned long long c = strtoull(v, NULL, 10);
        if (strchr(v, 'K')) c *= 1024ULL;
        else if (strchr(v, 'M')) c *= 1024ULL * 1024ULL;
        m->chunk = c;
    }
    if (line_value(detail, "State", v, sizeof v) && strstr(v, "degraded")) m->degraded = 1;

    /* member rows: "<num> <maj> <min> <role> <state...> /dev/<name>" */
    for (const char *l = detail; l && *l; l = strchr(l, '\n') ? strchr(l, '\n') + 1 : NULL) {
        const char *eol = strchr(l, '\n');
        const char *dev = strstr(l, " /dev/");
        if (!dev || (eol && dev > eol)) continue;
        char nm[32];
        size_t i = 0;
        for (const char *p = dev + 6; *p && !isspace((unsigned char)*p) && i < sizeof nm - 1; p++)
            nm[i++] = *p;
        nm[i] = '\0';
        pd_t *d = pd_by_name(s, nm);
        if (!d) continue;
        d->md = (int)(m - s->md);
        const char *sp = strstr(l, "spare");            /* "spare" before the dev path */
        d->spare = (sp && sp < dev) ? 1 : 0;
        const char *fl = strstr(l, "faulty");           /* md kicked this member (failed) */
        d->faulty = (fl && fl < dev) ? 1 : 0;
        m->nmemb++;
    }
}

/* /proc/mdstat: sync operation + percent for this md ("recovery = 12.6%"). */
static void parse_mdstat(md_t *m, const char *mdstat) {
    char tag[24];
    const char *base = strrchr(m->dev, '/');
    snprintf(tag, sizeof tag, "%s : ", base ? base + 1 : m->dev);
    const char *sec = strstr(mdstat, tag);
    if (!sec) return;
    const char *end = strstr(sec, "\n\n");
    size_t span = end ? (size_t)(end - sec) : strlen(sec);
    static const struct { const char *word; int rebuild; } ops[] = {
        { "recovery", 1 }, { "resync", 0 }, { "reshape", 0 }, { "check", 0 },
    };
    for (size_t i = 0; i < sizeof ops / sizeof ops[0]; i++) {
        const char *p = sec;
        while ((p = strstr(p, ops[i].word)) != NULL && (size_t)(p - sec) < span) {
            const char *q = p + strlen(ops[i].word);        /* "recovery = 12.6%" */
            while (*q == ' ') q++;
            if (*q == '=') {
                if (ops[i].rebuild) m->rebuilding = 1; else m->resyncing = 1;
                m->sync_pct = atoi(q + 1);
                return;
            }
            p += strlen(ops[i].word);
        }
    }
}

static void collect_mds(raid_snap_t *s) {
    char *out = malloc(CMD_CAP);
    if (!out) return;
    if (raid_cmd_priv("mdadm --detail --scan", out, CMD_CAP) == 0 && *out) {
        for (const char *l = out; *l && s->nmd < MAX_MD;
             l = strchr(l, '\n') ? strchr(l, '\n') + 1 : "") {
            if (strncmp(l, "ARRAY ", 6) != 0) continue;
            md_t *m = &s->md[s->nmd];
            memset(m, 0, sizeof *m);
            size_t i = 0;
            for (const char *p = l + 6; *p && !isspace((unsigned char)*p) && i < sizeof m->dev - 1; p++)
                m->dev[i++] = *p;
            m->dev[i] = '\0';
            const char *digits = m->dev + strlen(m->dev);
            while (digits > m->dev && isdigit((unsigned char)digits[-1])) digits--;
            m->id = *digits ? atoi(digits) : (int)s->nmd;   /* md0 -> 0 */
            m->level = -1;
            m->sync_pct = -1;
            s->nmd++;
        }
    }
    char *det = malloc(CMD_CAP);
    if (det) {
        for (unsigned i = 0; i < s->nmd; i++) {
            char cmd[64];
            snprintf(cmd, sizeof cmd, "mdadm --detail %s", s->md[i].dev);
            if (raid_cmd_priv(cmd, det, CMD_CAP) == 0 && *det) parse_detail(s, &s->md[i], det);
        }
        if (s->nmd && raid_cmd("cat /proc/mdstat", det, CMD_CAP) == 0 && *det)
            for (unsigned i = 0; i < s->nmd; i++) parse_mdstat(&s->md[i], det);
        free(det);
    }
    free(out);
}

static void snapshot(raid_snap_t *s) {
    memset(s, 0, sizeof *s);
    char *lsblk = malloc(CMD_CAP);
    if (!lsblk) return;
    if (raid_cmd(LSBLK_CMD, lsblk, CMD_CAP) == 0 && *lsblk) {
        find_root_disk(lsblk, s->rootdisk, sizeof s->rootdisk);
        collect_pds(s, lsblk);
    }
    free(lsblk);
    collect_mds(s);
}

int raid_mdadm_pd_osname(unsigned short pd_id, char *out, size_t cap) {
    raid_snap_t s;
    snapshot(&s);
    if (pd_id < 1 || pd_id > s.npd) return 0;
    snprintf(out, cap, "%s", s.pd[pd_id - 1].name);   /* "sda" */
    return out[0] != '\0';
}

unsigned short raid_mdadm_osname_pd(const char *name) {
    raid_snap_t s;
    snapshot(&s);
    for (unsigned i = 0; i < s.npd; i++)
        if (strcmp(s.pd[i].name, name) == 0) return (unsigned short)(i + 1);
    return 0;   /* 1-based PDId, 0 = not a data PD */
}

/* Map a logical-drive id (the md index = DaId/LdId) to its kernel name ("md0");
 * 0 if no such array. Used by the LD statistics getter (i2_stats.c). */
int raid_mdadm_ld_osname(unsigned short ld_id, char *out, size_t cap) {
    raid_snap_t s;
    snapshot(&s);
    for (unsigned i = 0; i < s.nmd; i++)
        if (s.md[i].id == (int)ld_id) {
            const char *b = strrchr(s.md[i].dev, '/');
            snprintf(out, cap, "%s", b ? b + 1 : s.md[i].dev);
            return out[0] != '\0';
        }
    return 0;
}

/* ----------------------------------------------------------- struct filling */

I2B_API_RET_E raid_mdadm_getHardDiskDriveInfo(I2bHddInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    raid_snap_t s;
    snapshot(&s);
    unsigned n = s.npd;
    if (buflen < (unsigned long)n * sizeof(I2bHddInfo_t)) return I2B_API_RET_PARAM_ERR;
    for (unsigned i = 0; i < n; i++) {
        const pd_t *p = &s.pd[i];
        I2bHddInfo_t *d = &out[i];
        memset(d, 0, sizeof *d);
        d->NumOfPD      = n;
        d->PDId         = (unsigned short)(i + 1);              /* 1-based flat id */
        d->drvType      = p->is_sas ? I2BAPI_DRV_TYPE_SAS : I2BAPI_DRV_TYPE_SATA;
        snprintf((char *)d->driveModelNo, sizeof d->driveModelNo, "%s", p->model);
        d->confCapacity = p->size;
        d->enclosureId  = 0;   /* the A3120's single enclosure is _id 0 (getEnclosures);
                                * phydrvs.php filters PDs by pdi_EnclosureId == ssid(0),
                                * so a mismatched 1 hid every drive from the list. */
        /* ponytail: slot = enumeration order; the bay<->ata-port map is an
         * on-device calibration point (deploy step on .212). */
        d->slotId       = (unsigned short)i;
        unsigned flag = PHYDRV_STATE_ONLINE | PHYDRV_COND_PRESENT | PHYDRV_COND_ACCESSIBLE;
        const char *cfg = "Unconfigured";
        char op[32] = "OK";
        if (p->md >= 0 && p->spare) {
            flag |= PHYDRV_CONFIG_DEDICATEDSPARE;
            cfg = "Spare";
            d->arrayId = (unsigned short)s.md[p->md].id;
            if (s.md[p->md].rebuilding) {   /* spare rebuilding onto a failed member */
                flag = (flag & ~PHYDRV_STATE_ONLINE) | PHYDRV_STATE_REBUILD;
                snprintf(op, sizeof op, "Rebuilding (%d%%)",
                         s.md[p->md].sync_pct >= 0 ? s.md[p->md].sync_pct : 0);
            }
        } else if (p->md >= 0) {
            flag |= PHYDRV_CONFIG_CONFIGURED;
            cfg = "Array";
            d->arrayId = (unsigned short)s.md[p->md].id;
            if (p->faulty) {                /* md failed it out of the array; the A3120P shows red */
                flag &= ~PHYDRV_STATE_ONLINE;   /* i2_records derives _opStatus "Offline" -> red */
                strcpy(op, "Offline");          /* keep the PD-flags string in step */
            }
        }
        if (p->pfa && strcmp(op, "OK") == 0) {   /* SMART predicted-failure, still in the array */
            flag |= PHYDRV_COND_PFA;
            strcpy(op, "PFA");
        }
        d->opStatusFlag = flag;
        strncpy((char *)d->opStatus, op, sizeof d->opStatus - 1);
        strncpy((char *)d->configStatus, cfg, sizeof d->configStatus - 1);
        d->temperature        = (unsigned char)(p->temp > 0 ? p->temp : 0);
        d->HDDTempWarThreshold = PD_TEMP_WARN;
        d->HDDTempCriThreshold = PD_TEMP_CRIT;
        d->TempStatusFlag = p->temp >= PD_TEMP_CRIT ? PD_TEMPERATURE_STS_OVER_CRITICAL
                          : p->temp >= PD_TEMP_WARN ? PD_TEMPERATURE_STS_OVER_WARNING
                          : PD_TEMPERATURE_STS_NORMAL;
    }
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E raid_mdadm_getDiskArrayInfo(I2bArrayInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    raid_snap_t s;
    snapshot(&s);
    unsigned long need = sizeof(I2bArrayInfo_t) +
                         (s.nmd ? (s.nmd - 1) * sizeof(I2bDiskArrayData_t) : 0);
    if (buflen < need) return I2B_API_RET_PARAM_ERR;
    out->DACnt = (unsigned char)s.nmd;
    for (unsigned i = 0; i < s.nmd; i++) {
        memset(&out->DAData[i], 0, sizeof out->DAData[i]);   /* zero ArrayName/OpStatus health fields */
        out->DAData[i].DaId     = (unsigned short)s.md[i].id;
        out->DAData[i].NumOfHdd = (unsigned short)s.md[i].nmemb;
        /* Configurable = sum of member PD capacity; free = what the md does
         * not consume on each member (nonzero when created with --size). */
        unsigned long long cfg = 0, freeb = 0;
        for (unsigned p = 0; p < s.npd; p++) {
            if (s.pd[p].md != (int)i) continue;
            cfg += s.pd[p].size;
            if (s.pd[p].size > s.md[i].used_dev)
                freeb += s.pd[p].size - s.md[i].used_dev;
        }
        out->DAData[i].CfgCapacity  = cfg;
        out->DAData[i].FreeCapacity = freeb;
    }
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E raid_mdadm_getLogDrvInfo(I2bLogDrvInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    raid_snap_t s;
    snapshot(&s);
    unsigned long need = sizeof(I2bLogDrvInfo_t) +
                         (s.nmd ? (s.nmd - 1) * sizeof(I2bLogDrvData_t) : 0);
    if (buflen < need) return I2B_API_RET_PARAM_ERR;
    /* LDs are the LVs of each md's VG (docs/RAID-ENGINE-LVM-LD-PLAN.md). An md with
     * no VG keeps the legacy 1-LD = whole-md view until its first LD is created, so
     * fixtures/tests and existing pages are unchanged. LV LdIds carry a 0x8000 tag
     * (+ md id and ordinal) so deleteLogicalDrives can tell an lvremove from the
     * legacy array-stop; legacy LdId stays the bare md id. */
    unsigned total = 0;
    for (unsigned i = 0; i < s.nmd; i++) {
        const md_t *m = &s.md[i];
        unsigned flag = LOGDRV_CONFIG_CONFIGURED;
        char op[32] = "OK";
        if (m->degraded) {
            flag |= LOGDRV_STATE_CRITICAL;    /* vendor term for degraded-redundant */
            snprintf(op, sizeof op, "Critical");
        } else {
            flag |= LOGDRV_STATE_ONLINE | LOGDRV_COND_INITIALIZED;
        }
        if (m->rebuilding) {
            flag |= LOGDRV_OPERATION_REBUILDING;
            snprintf(op, sizeof op, "Rebuilding (%d%%)", m->sync_pct >= 0 ? m->sync_pct : 0);
        } else if (m->resyncing) {
            flag |= LOGDRV_OPERATION_SYNCHRONIZING;
            snprintf(op, sizeof op, "Synchronizing (%d%%)", m->sync_pct >= 0 ? m->sync_pct : 0);
        } else if (!m->degraded) {
            flag |= LOGDRV_COND_SYNCHRONIZED;
        }

        raid_lv_t lvs[64];
        unsigned nlv = raid_mdadm_lv_list((unsigned)m->id, lvs, 64);
        unsigned emit = nlv ? nlv : 1;        /* no VG -> one legacy LD (the whole md) */
        for (unsigned j = 0; j < emit; j++) {
            unsigned long need = sizeof(I2bLogDrvInfo_t) +
                                 (unsigned long)total * sizeof(I2bLogDrvData_t);
            if (need + sizeof(I2bLogDrvData_t) > buflen || total >= 255) { i = s.nmd; break; }
            I2bLogDrvData_t *d = &out->LDData[total];
            memset(d, 0, sizeof *d);
            if (nlv) {
                d->LdId = (unsigned short)(0x8000u | (((unsigned)m->id & 0x7fu) << 8) | (j & 0xffu));
                snprintf((char *)d->Alias, sizeof d->Alias, "%s", lvs[j].name);
                d->Capacity = lvs[j].bytes;
            } else {
                const char *base = strrchr(m->dev, '/');
                d->LdId = (unsigned short)m->id;
                snprintf((char *)d->Alias, sizeof d->Alias, "%s", base ? base + 1 : m->dev);
                d->Capacity = m->cap;
            }
            d->arrayId  = (unsigned short)m->id;
            d->RaidLvl  = (unsigned char)(m->level >= 0 ? m->level : 0xff);
            d->StripeSz = m->chunk;
            /* No controller cache on Linux software RAID - report NoCache/WThru. */
            d->CachePolicy = I2BAPI_LDCACHEPOLICY_NOCACHE_WTHRU;
            d->SYNCed = (unsigned char)((flag & LOGDRV_COND_SYNCHRONIZED)
                                        ? I2BAPI_LDSYNCED_YES : I2BAPI_LDSYNCED_NO);
            d->OpStatusFlag = flag;
            strncpy((char *)d->OpStatus, op, sizeof d->OpStatus - 1);
            total++;
        }
    }
    out->LDCnt = (unsigned char)total;
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E raid_mdadm_getStatsLogDrv(I2bLogDrvStatusInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    raid_snap_t s;
    snapshot(&s);
    unsigned long need = sizeof(I2bLogDrvStatusInfo_t) +
                         (s.nmd ? (s.nmd - 1) * sizeof(I2StatsLogicalDrive_t) : 0);
    if (buflen < need) return I2B_API_RET_PARAM_ERR;
    out->LDCnt = s.nmd;
    char stat[512];
    for (unsigned i = 0; i < s.nmd; i++) {
        I2StatsLogicalDrive_t *d = &out->LDStatsData[i];
        memset(d, 0, sizeof *d);
        d->sld_LogicalDriveId = (unsigned char)s.md[i].id;
        const char *base = strrchr(s.md[i].dev, '/');
        char cmd[80];
        snprintf(cmd, sizeof cmd, "cat /sys/block/%s/stat", base ? base + 1 : s.md[i].dev);
        if (raid_cmd(cmd, stat, sizeof stat) != 0 || !*stat) continue;
        /* block stat: r_ios r_merge r_sectors r_ticks w_ios w_merge w_sectors ... */
        unsigned long long f[8] = {0};
        const char *p = stat;
        for (int k = 0; k < 8 && *p; k++) {
            while (*p == ' ') p++;
            f[k] = strtoull(p, (char **)&p, 10);
        }
        d->sld_ReadIOReq  = f[0];
        d->sld_WriteIOReq = f[4];
        d->sld_IOReq      = f[0] + f[4];
        d->sld_ReadBlocksTransferred  = f[2] << 9;   /* sectors -> bytes */
        d->sld_WriteBlocksTransferred = f[6] << 9;
        d->sld_BlocksTransferred      = (f[2] + f[6]) << 9;
    }
    return I2B_API_RET_SUCCESS;
}

/* ======================= Phase 2: the write path =========================== */

static void set_err(char *err, size_t cap, const char *fmt, ...) {
    if (!err || !cap) return;
    va_list ap;
    va_start(ap, fmt);
    vsnprintf(err, cap, fmt, ap);
    va_end(ap);
}

/*
 * Root-disk / non-member guard. A device may be written to (mdadm --create
 * member, --zero-superblock target) ONLY if it is one of the enumerated data
 * PDs - which by construction excludes the OS disk, VESSAPP_RAID_EXCLUDE and
 * anything that is not a bare /dev/sd* disk. The explicit rootdisk compare is
 * defence in depth should the PD list ever drift.
 */
static pd_t *guard_member(raid_snap_t *s, const char *name, char *err, size_t errcap) {
    if (strncmp(name, "sd", 2) != 0 || !name[2]) {
        set_err(err, errcap, "refusing to touch /dev/%s: not a bare SATA/SAS data disk", name);
        return NULL;
    }
    for (const char *p = name + 2; *p; p++)
        if (!islower((unsigned char)*p)) {
            set_err(err, errcap, "refusing to touch /dev/%s: not a whole-disk device", name);
            return NULL;
        }
    if (s->rootdisk[0] && strcmp(name, s->rootdisk) == 0) {
        set_err(err, errcap, "refusing to touch /dev/%s: it is the OS disk", name);
        return NULL;
    }
    pd_t *d = pd_by_name(s, name);
    if (!d) {
        set_err(err, errcap,
                "refusing to touch /dev/%s: not one of the managed data disks", name);
        return NULL;
    }
    return d;
}

/* mdadm --level argument for the Promise level codes the engine supports. */
static const char *mdadm_level_arg(int lvl) {
    switch (lvl) {
        case I2BAPI_LDRAIDLVL_RAID0:  return "0";
        case I2BAPI_LDRAIDLVL_RAID1:  return "1";
        case I2BAPI_LDRAIDLVL_RAID5:  return "5";
        case I2BAPI_LDRAIDLVL_RAID6:  return "6";
        case I2BAPI_LDRAIDLVL_RAID10: return "10";
        default:                      return NULL;
    }
}

/* Data-bearing members for a level (capacity -> per-member --size math). */
static unsigned level_data_members(int lvl, unsigned n) {
    switch (lvl) {
        case I2BAPI_LDRAIDLVL_RAID0:  return n;
        case I2BAPI_LDRAIDLVL_RAID1:  return 1;
        case I2BAPI_LDRAIDLVL_RAID5:  return n - 1;
        case I2BAPI_LDRAIDLVL_RAID6:  return n - 2;
        case I2BAPI_LDRAIDLVL_RAID10: return n / 2;
        default:                      return n;
    }
}

static const struct { int lvl; unsigned min; } level_min[] = {
    { I2BAPI_LDRAIDLVL_RAID0, 1 }, { I2BAPI_LDRAIDLVL_RAID1,  2 },
    { I2BAPI_LDRAIDLVL_RAID5, 3 }, { I2BAPI_LDRAIDLVL_RAID6,  4 },
    { I2BAPI_LDRAIDLVL_RAID10, 4 },
};

/* Persist a just-created / just-deleted array in /etc/mdadm/mdadm.conf (via the
 * privileged helper - www-data can't write it) so it re-assembles as the active
 * /dev/mdN the UI created, not an auto-read-only md127, after a reboot. The
 * matching `update-initramfs -u` refreshes the early-boot copy so incremental
 * assembly names it the same. Best-effort: the array is already live, so a
 * persistence hiccup must not fail the create/delete. */
static void mdconf_persist(int mdn) {
    char cmd[128], scan[512], out[256];
    snprintf(cmd, sizeof cmd, "mdadm --detail --scan /dev/md%d", mdn);
    if (raid_cmd_priv(cmd, scan, sizeof scan) != 0 || !*scan) return;
    const char *u = strstr(scan, "UUID=");            /* ARRAY line carries UUID=<hex:...> */
    if (!u) return;
    u += 5;
    char uuid[64];
    size_t i = 0;
    for (; u[i] && i < sizeof uuid - 1 &&
           (isxdigit((unsigned char)u[i]) || u[i] == ':'); i++)
        uuid[i] = u[i];
    uuid[i] = '\0';
    if (i < 8) return;                                /* not a plausible md UUID */
    snprintf(cmd, sizeof cmd, "mdconf-add md%d %s", mdn, uuid);
    raid_cmd_priv(cmd, out, sizeof out);
    raid_cmd_priv("update-initramfs -u", out, sizeof out);
}

static void mdconf_unpersist(int mdn) {
    char cmd[64], out[256];
    snprintf(cmd, sizeof cmd, "mdconf-del md%d", mdn);
    raid_cmd_priv(cmd, out, sizeof out);
    raid_cmd_priv("update-initramfs -u", out, sizeof out);
}

int raid_mdadm_create(int raid_level, const unsigned short *pd_ids, unsigned npds,
                      unsigned chunk_kb, unsigned long long capacity,
                      char *err, size_t errcap) {
    set_err(err, errcap, "create failed");
    const char *lvl = mdadm_level_arg(raid_level);
    if (!lvl) {
        set_err(err, errcap, "RAID level 0x%02x is not supported by the Linux "
                "software RAID engine (RAID0/1/5/6/10)", raid_level);
        return -1;
    }
    if (!pd_ids || !npds || npds > MAX_PD) {
        set_err(err, errcap, "no physical drives given");
        return -1;
    }
    for (size_t i = 0; i < sizeof level_min / sizeof level_min[0]; i++)
        if (level_min[i].lvl == raid_level && npds < level_min[i].min) {
            set_err(err, errcap, "RAID%s needs at least %u drives, got %u",
                    lvl, level_min[i].min, npds);
            return -1;
        }

    raid_snap_t s;
    snapshot(&s);

    /* Resolve + guard every member BEFORE any command is issued. */
    pd_t *memb[MAX_PD];
    unsigned long long smallest = ~0ULL;
    for (unsigned i = 0; i < npds; i++) {
        unsigned id = pd_ids[i];
        if (id < 1 || id > s.npd) {
            set_err(err, errcap, "PD %u is not one of the managed data disks "
                    "(the OS disk is never available for arrays)", id);
            return -1;
        }
        pd_t *d = &s.pd[id - 1];
        if (!guard_member(&s, d->name, err, errcap)) return -1;
        if (d->md >= 0) {
            set_err(err, errcap, "PD %u (/dev/%s) is already part of %s",
                    id, d->name, s.md[d->md].dev);
            return -1;
        }
        for (unsigned j = 0; j < i; j++)
            if (memb[j] == d) {
                set_err(err, errcap, "PD %u listed twice", id);
                return -1;
            }
        memb[i] = d;
        if (d->size && d->size < smallest) smallest = d->size;
    }

    /* Next free md index -> DA/LD id (DA0 = /dev/md0, like the vendor). */
    int mdn = 0;
    for (int used = 1; used && mdn < 128; ) {
        used = 0;
        for (unsigned i = 0; i < s.nmd; i++)
            if (s.md[i].id == mdn) { used = 1; mdn++; break; }
    }

    char cmd[1024];
    int n = snprintf(cmd, sizeof cmd,
                     "mdadm --create /dev/md%d --metadata=1.2 --run --level=%s "
                     "--raid-devices=%u", mdn, lvl, npds);
    if (chunk_kb && raid_level != I2BAPI_LDRAIDLVL_RAID1)
        n += snprintf(cmd + n, sizeof cmd - (size_t)n, " --chunk=%u", chunk_kb);
    if (capacity) {
        /* Wizard asked for a smaller LD than the disks allow: cap the
         * per-member share (mdadm --size is in KiB). Full/over-size -> omit. */
        unsigned long long per = capacity / level_data_members(raid_level, npds);
        if (smallest != ~0ULL && per < smallest)
            n += snprintf(cmd + n, sizeof cmd - (size_t)n, " --size=%llu", per / 1024ULL);
    }
    for (unsigned i = 0; i < npds && (size_t)n < sizeof cmd; i++)
        n += snprintf(cmd + n, sizeof cmd - (size_t)n, " /dev/%s", memb[i]->name);
    if ((size_t)n >= sizeof cmd) {
        set_err(err, errcap, "command too long");
        return -1;
    }

    char out[512];
    /* Creating an array wipes its members - clear any stale partition table or
     * old RAID signature first (via the privileged helper), else mdadm --create
     * prompts "partition table exists" and aborts non-interactively. */
    for (unsigned i = 0; i < npds; i++) {
        char wcmd[64];
        snprintf(wcmd, sizeof wcmd, "wipefs -a /dev/%s", memb[i]->name);
        if (raid_cmd_priv(wcmd, out, sizeof out) != 0) {
            set_err(err, errcap, "wipefs /dev/%s failed", memb[i]->name);
            return -1;
        }
    }
    if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
        set_err(err, errcap, "failed: %s", cmd);
        return -1;
    }
    mdconf_persist(mdn);   /* record it so it comes back as active /dev/md%d after a reboot */
    set_err(err, errcap, "");
    return 0;
}

/* Member device names of one md, straight from `mdadm --detail` (the snapshot
 * only records members that are managed PDs; the delete guard must see ALL of
 * them, e.g. an OS partition hiding in an array). Returns the count. */
static unsigned detail_members(const char *dev, char names[][16], unsigned cap) {
    char *det = malloc(CMD_CAP);
    if (!det) return 0;
    char cmd[64];
    unsigned n = 0;
    snprintf(cmd, sizeof cmd, "mdadm --detail %s", dev);
    if (raid_cmd_priv(cmd, det, CMD_CAP) == 0 && *det) {
        for (const char *l = det; l && *l && n < cap;
             l = strchr(l, '\n') ? strchr(l, '\n') + 1 : NULL) {
            const char *eol = strchr(l, '\n');
            const char *d = strstr(l, " /dev/");
            if (!d || (eol && d > eol)) continue;
            size_t i = 0;
            for (const char *p = d + 6; *p && !isspace((unsigned char)*p) && i < 15; p++)
                names[n][i++] = *p;
            names[n][i] = '\0';
            if (i) n++;
        }
    }
    free(det);
    return n;
}

static md_t *md_by_id(raid_snap_t *s, unsigned id) {
    for (unsigned i = 0; i < s->nmd; i++)
        if (s->md[i].id == (int)id) return &s->md[i];
    return NULL;
}

int raid_mdadm_delete(unsigned id, char *err, size_t errcap) {
    raid_snap_t s;
    snapshot(&s);
    md_t *m = md_by_id(&s, id);
    if (!m) {
        set_err(err, errcap, "no such array (md%u)", id);
        return -1;
    }

    char names[MAX_PD][16];
    unsigned n = detail_members(m->dev, names, MAX_PD);
    if (!n) {
        set_err(err, errcap, "could not read the member list of %s", m->dev);
        return -1;
    }
    /* Guard EVERY member before the first destructive command. */
    for (unsigned i = 0; i < n; i++)
        if (!guard_member(&s, names[i], err, errcap)) return -1;

    char cmd[128], out[512];
    snprintf(cmd, sizeof cmd, "mdadm --stop %s", m->dev);
    if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
        set_err(err, errcap, "mdadm --stop %s failed", m->dev);
        return -1;
    }
    for (unsigned i = 0; i < n; i++) {
        snprintf(cmd, sizeof cmd, "mdadm --zero-superblock /dev/%s", names[i]);
        if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
            set_err(err, errcap, "mdadm --zero-superblock /dev/%s failed", names[i]);
            return -1;
        }
    }
    mdconf_unpersist(m->id);   /* drop its mdadm.conf line so it doesn't linger after a reboot */
    set_err(err, errcap, "");
    return 0;
}

/* echo <action> > /sys/block/mdN/md/sync_action via the raid_cmd seam (popen
 * runs through sh, so the redirect works; tests just see the exact string). */
static int sync_action(unsigned id, const char *action, char *err, size_t errcap) {
    raid_snap_t s;
    snapshot(&s);
    md_t *m = md_by_id(&s, id);
    if (!m) {
        set_err(err, errcap, "no such logical drive (md%u)", id);
        return -1;
    }
    const char *base = strrchr(m->dev, '/');
    char cmd[128], out[128];
    snprintf(cmd, sizeof cmd, "syncaction %s %s",
             base ? base + 1 : m->dev, action);
    if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
        set_err(err, errcap, "could not set sync_action=%s on md%u", action, id);
        return -1;
    }
    set_err(err, errcap, "");
    return 0;
}

int raid_mdadm_init_ld(unsigned id, int start, char *err, size_t errcap) {
    /* "Initialization" on an existing md = rewrite the redundancy: repair. */
    return sync_action(id, start ? "repair" : "idle", err, errcap);
}

int raid_mdadm_synch_ld(unsigned id, int start, char *err, size_t errcap) {
    return sync_action(id, start ? "check" : "idle", err, errcap);
}

/* ---- spares (P3): add / remove a hot spare -------------------------------- */
int raid_mdadm_add_spare(unsigned short pd_id, int array_id, char *err, size_t errcap) {
    raid_snap_t s;
    snapshot(&s);
    if (pd_id < 1 || pd_id > s.npd) {
        set_err(err, errcap, "PD %u is not one of the managed data disks "
                "(the OS disk is never available for spares)", pd_id);
        return -1;
    }
    pd_t *d = &s.pd[pd_id - 1];
    if (!guard_member(&s, d->name, err, errcap)) return -1;
    if (d->md >= 0) {
        set_err(err, errcap, "PD %u (/dev/%s) is already part of %s",
                pd_id, d->name, s.md[d->md].dev);
        return -1;
    }
    /* Resolve the array to attach the spare to. */
    md_t *m = NULL;
    if (array_id >= 0) {
        m = md_by_id(&s, (unsigned)array_id);
        if (!m) { set_err(err, errcap, "no such array (md%d)", array_id); return -1; }
    } else if (s.nmd == 1) {
        m = &s.md[0];                       /* Global + exactly one array = that array */
    } else if (s.nmd == 0) {
        set_err(err, errcap, "a spare must be attached to an array - create one first");
        return -1;
    } else {
        set_err(err, errcap, "a Global spare across multiple arrays needs spare-groups "
                "(not supported in v1) - choose a Dedicated array");
        return -1;
    }

    char cmd[128], out[256];
    snprintf(cmd, sizeof cmd, "mdadm --add %s /dev/%s", m->dev, d->name);
    if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
        set_err(err, errcap, "mdadm --add %s /dev/%s failed", m->dev, d->name);
        return -1;
    }
    set_err(err, errcap, "");
    return 0;
}

int raid_mdadm_remove_spare(unsigned array_id, char *err, size_t errcap) {
    raid_snap_t s;
    snapshot(&s);
    md_t *m = md_by_id(&s, array_id);
    if (!m) { set_err(err, errcap, "no such array (md%u)", array_id); return -1; }

    int found = 0;
    for (unsigned i = 0; i < s.npd; i++) {
        pd_t *d = &s.pd[i];
        if (d->md < 0 || s.md[d->md].id != (int)array_id || !d->spare) continue;
        if (!guard_member(&s, d->name, err, errcap)) return -1;   /* defense in depth */
        char cmd[128], out[256];
        snprintf(cmd, sizeof cmd, "mdadm --remove %s /dev/%s", m->dev, d->name);
        if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
            set_err(err, errcap, "mdadm --remove %s /dev/%s failed", m->dev, d->name);
            return -1;
        }
        found = 1;
    }
    if (!found) { set_err(err, errcap, "array md%u has no spare drive", array_id); return -1; }
    set_err(err, errcap, "");
    return 0;
}

/* ---- migrate / reshape (P4) ------------------------------------------------
 * OCE (add disks, same level) + the mdadm-supported level changes only; every
 * other transition is refused with a clear message (the plan's "don't fake").
 * The reshape runs in the background; /proc/mdstat reports its progress via the
 * existing read path (md->resyncing / sync_pct). */
#define MIGRATE_BACKUP_DIR "/var/lib/promise-raid"

static int migrate_level_ok(int cur, int want) {
    if (want == I2BAPI_LDRAIDLVL_RAID6 && cur == I2BAPI_LDRAIDLVL_RAID5) return 1;
    if (want == I2BAPI_LDRAIDLVL_RAID5 && cur == I2BAPI_LDRAIDLVL_RAID1) return 1;
    if (want == I2BAPI_LDRAIDLVL_RAID5 && cur == I2BAPI_LDRAIDLVL_RAID0) return 1;
    return 0;
}

int raid_mdadm_migrate(unsigned array_id, const unsigned short *pd_ids, unsigned npds,
                       int new_level, char *err, size_t errcap) {
    raid_snap_t s;
    snapshot(&s);
    md_t *m = md_by_id(&s, array_id);
    if (!m) { set_err(err, errcap, "no such array (md%u)", array_id); return -1; }
    if (!pd_ids || !npds || npds > MAX_PD) {
        set_err(err, errcap, "no target drives given for the migration");
        return -1;
    }

    /* current active (non-spare) member count of this md */
    unsigned cur = 0;
    for (unsigned i = 0; i < s.npd; i++)
        if (s.pd[i].md >= 0 && s.md[s.pd[i].md].id == (int)array_id && !s.pd[i].spare) cur++;

    /* resolve + guard the target set; collect the NEW (not-yet-member) disks */
    pd_t *newpds[MAX_PD]; unsigned nnew = 0;
    for (unsigned i = 0; i < npds; i++) {
        unsigned id = pd_ids[i];
        if (id < 1 || id > s.npd) {
            set_err(err, errcap, "PD %u is not one of the managed data disks", id);
            return -1;
        }
        pd_t *d = &s.pd[id - 1];
        if (!guard_member(&s, d->name, err, errcap)) return -1;
        int mine = (d->md >= 0 && s.md[d->md].id == (int)array_id);
        if (!mine) {
            if (d->md >= 0) {
                set_err(err, errcap, "PD %u (/dev/%s) is already part of %s",
                        id, d->name, s.md[d->md].dev);
                return -1;
            }
            if (nnew < MAX_PD) newpds[nnew++] = d;
        }
    }

    /* pd_ids are the disks to ADD (the migrate wizard's list2 offers only
     * unconfigured drives); the new width = current members + the not-yet-member
     * targets. Passing the full target set works too (its members resolve to
     * "mine" and are skipped, leaving the same nnew). */
    unsigned target = cur + nnew;
    int level_change = (new_level >= 0 && new_level != m->level);
    int expand = (nnew > 0);
    if (!expand && !level_change) {
        set_err(err, errcap, "migration changes nothing (no new drives, same RAID level)");
        return -1;
    }
    if (level_change && !migrate_level_ok(m->level, new_level)) {
        const char *cl = mdadm_level_arg(m->level), *wl = mdadm_level_arg(new_level);
        set_err(err, errcap, "migrating RAID%s -> RAID%s is not supported by the Linux "
                "software RAID engine (supported: same-level expansion, RAID5->RAID6, "
                "RAID1->RAID5, RAID0->RAID5)", cl ? cl : "?", wl ? wl : "?");
        return -1;
    }
    if (m->degraded || m->rebuilding || m->resyncing) {
        set_err(err, errcap, "the array is busy (rebuild/scrub) - let it finish before migrating");
        return -1;
    }

    char cmd[256], out[512];
    /* 1. bring the new disks in (as spares; the reshape absorbs them). */
    for (unsigned i = 0; i < nnew; i++) {
        snprintf(cmd, sizeof cmd, "mdadm --add %s /dev/%s", m->dev, newpds[i]->name);
        if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
            set_err(err, errcap, "mdadm --add /dev/%s failed", newpds[i]->name);
            return -1;
        }
    }
    /* 2. reshape to the new width / level, with a backup file for the critical
     * section (mdadm uses it only if the reshape needs one). */
    int n = snprintf(cmd, sizeof cmd, "mdadm --grow %s --raid-devices=%u", m->dev, target);
    if (level_change)
        n += snprintf(cmd + n, sizeof cmd - (size_t)n, " --level=%s", mdadm_level_arg(new_level));
    n += snprintf(cmd + n, sizeof cmd - (size_t)n, " --backup-file=%s/md%u.backup",
                  MIGRATE_BACKUP_DIR, array_id);
    if ((size_t)n >= sizeof cmd) { set_err(err, errcap, "command too long"); return -1; }
    if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
        set_err(err, errcap, "mdadm --grow failed: %s", cmd);
        return -1;
    }
    set_err(err, errcap, "");
    return 0;
}

/* Media patrol = a consistency scrub ('check' scan) of every array. */
int raid_mdadm_media_patrol(char *err, size_t errcap) {
    raid_snap_t s;
    snapshot(&s);
    if (s.nmd == 0) { set_err(err, errcap, "no arrays to scan"); return -1; }
    for (unsigned i = 0; i < s.nmd; i++) {
        const char *base = strrchr(s.md[i].dev, '/');
        char cmd[128], out[128];
        snprintf(cmd, sizeof cmd, "syncaction %s check", base ? base + 1 : s.md[i].dev);
        if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
            set_err(err, errcap, "could not start a media scan on %s", s.md[i].dev);
            return -1;
        }
    }
    set_err(err, errcap, "");
    return 0;
}

/* ======================================================================== *
 * LVM logical-drive layer (docs/RAID-ENGINE-LVM-LD-PLAN.md).
 * A Promise "logical drive within a disk array" is an LVM LV in a per-array VG
 * (vgmd<id>) on the md device; free fragments are the VG's free extents. Reads
 * (lvs/vgs/pvs) and writes (pv/vg/lvcreate, lvremove, lvextend) all run through
 * the privileged helper (raid_cmd_priv), whose whitelist permits these verbs.
 * VG/LV names are always vgmd<id> / vgmd<id>/<lv>, so nothing free-form reaches
 * the tool. An array with no VG keeps the legacy 1-md = 1-LD view (getLogDrvInfo)
 * until the first LD is created, which is when the VG is provisioned.
 * ======================================================================== */

/* Whitespace-delimited token present in lvs/vgs --noheadings output? */
static int lvm_has_token(const char *buf, const char *tok) {
    size_t n = strlen(tok);
    for (const char *p = buf; p && *p; ) {
        while (*p && isspace((unsigned char)*p)) p++;
        const char *e = p;
        while (*e && !isspace((unsigned char)*e)) e++;
        if ((size_t)(e - p) == n && strncmp(p, tok, n) == 0) return 1;
        if (e == p) break;
        p = e;
    }
    return 0;
}

/* User LD alias -> a valid LV name: [A-Za-z0-9_], must begin with a letter
 * (LVM rejects a leading '-'/digit-only name); empty -> "ld". */
static void lv_sanitize(const char *in, char *out, size_t cap) {
    if (cap < 8) { if (cap) out[0] = '\0'; return; }
    size_t j = 0;
    for (const char *p = in ? in : ""; *p && j < cap - 1; p++) {
        char c = *p;
        if ((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') ||
            (c >= '0' && c <= '9') || c == '_') out[j++] = c;
    }
    out[j] = '\0';
    if (j == 0 || !isalpha((unsigned char)out[0])) {
        char tmp[72];
        snprintf(tmp, sizeof tmp, "ld%s", out);
        snprintf(out, cap, "%s", tmp);
    }
}

int raid_mdadm_ensure_vg(unsigned md_id, char *err, size_t errcap) {
    char vg[32], out[CMD_CAP], cmd[128];
    snprintf(vg, sizeof vg, "vgmd%u", md_id);
    snprintf(cmd, sizeof cmd, "vgs --noheadings -o vg_name");
    if (raid_cmd_priv(cmd, out, sizeof out) == 0 && lvm_has_token(out, vg)) {
        set_err(err, errcap, ""); return 0;               /* VG already present */
    }
    char pvout[512];
    snprintf(cmd, sizeof cmd, "pvs --noheadings -o pv_name /dev/md%u", md_id);
    if (!(raid_cmd_priv(cmd, pvout, sizeof pvout) == 0 && strstr(pvout, "md"))) {
        snprintf(cmd, sizeof cmd, "pvcreate -y /dev/md%u", md_id);
        if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
            set_err(err, errcap, "pvcreate /dev/md%u failed", md_id); return -1;
        }
    }
    snprintf(cmd, sizeof cmd, "vgcreate %s /dev/md%u", vg, md_id);
    if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
        set_err(err, errcap, "vgcreate %s failed", vg); return -1;
    }
    set_err(err, errcap, ""); return 0;
}

unsigned raid_mdadm_lv_list(unsigned md_id, raid_lv_t *out, unsigned cap) {
    if (!out || cap == 0) return 0;
    char vg[32], cmd[160], buf[CMD_CAP];
    snprintf(vg, sizeof vg, "vgmd%u", md_id);
    snprintf(cmd, sizeof cmd,
             "lvs --noheadings --nosuffix --units b -o lv_name,lv_size %s", vg);
    unsigned n = 0;
    if (raid_cmd_priv(cmd, buf, sizeof buf) == 0) {
        for (const char *l = buf; l && *l && n < cap; ) {
            const char *eol = strchr(l, '\n');
            char nm[64]; unsigned long long by = 0;
            if (sscanf(l, " %63s %llu", nm, &by) == 2 && nm[0]) {
                snprintf(out[n].name, sizeof out[n].name, "%s", nm);
                out[n].bytes = by; n++;
            }
            l = eol ? eol + 1 : NULL;
        }
    }
    for (unsigned i = 0; i + 1 < n; i++)                   /* name-sort: stable ordinals */
        for (unsigned j = i + 1; j < n; j++)
            if (strcmp(out[i].name, out[j].name) > 0) {
                raid_lv_t t = out[i]; out[i] = out[j]; out[j] = t;
            }
    return n;
}

unsigned raid_mdadm_lv_free(unsigned md_id, raid_frag_t *out, unsigned cap) {
    if (!out || cap == 0) return 0;
    char vg[32], cmd[160], buf[512];
    snprintf(vg, sizeof vg, "vgmd%u", md_id);
    snprintf(cmd, sizeof cmd,
             "vgs --noheadings --nosuffix --units b -o vg_free,vg_size %s", vg);
    unsigned long long freeb = 0, sizeb = 0;
    if (raid_cmd_priv(cmd, buf, sizeof buf) == 0)
        sscanf(buf, " %llu %llu", &freeb, &sizeb);
    if (freeb == 0) return 0;
    out[0].addr  = sizeb > freeb ? sizeb - freeb : 0;     /* free begins after used */
    out[0].bytes = freeb;
    return 1;
}

unsigned raid_mdadm_da_free_fragments(unsigned md_id, raid_frag_t *out, unsigned cap) {
    if (!out || cap == 0) return 0;
    raid_snap_t s; snapshot(&s);
    md_t *m = md_by_id(&s, md_id);
    if (!m) return 0;
    unsigned nmemb = m->nmemb ? m->nmemb : 1;
    unsigned n = raid_mdadm_lv_free(md_id, out, cap);     /* VG free extents, if a VG exists */
    if (n == 0) {                                         /* no VG yet: the whole array is free */
        if (m->cap == 0) return 0;
        out[0].addr = 0; out[0].bytes = m->cap;
        n = 1;
    }
    for (unsigned i = 0; i < n; i++) {                    /* per-PD units (pages x count($pds)) */
        out[i].addr  /= nmemb;
        out[i].bytes /= nmemb;
    }
    return n;
}

int raid_mdadm_lv_create(unsigned md_id, const char *name,
                         unsigned long long bytes, char *err, size_t errcap) {
    if (bytes == 0) { set_err(err, errcap, "logical drive size is zero"); return -1; }
    if (raid_mdadm_ensure_vg(md_id, err, errcap) != 0) return -1;
    char base[64]; lv_sanitize(name, base, sizeof base);
    raid_lv_t lvs[64];
    unsigned nlv = raid_mdadm_lv_list(md_id, lvs, 64);
    char nm[80]; snprintf(nm, sizeof nm, "%s", base);     /* uniquify vs existing LVs */
    for (unsigned suffix = 1; suffix < 1000; suffix++) {
        int clash = 0;
        for (unsigned i = 0; i < nlv; i++)
            if (strcmp(lvs[i].name, nm) == 0) { clash = 1; break; }
        if (!clash) break;
        snprintf(nm, sizeof nm, "%s_%u", base, suffix);
    }
    char cmd[192], out[512];
    snprintf(cmd, sizeof cmd, "lvcreate -y -n %s -L %llub vgmd%u", nm, bytes, md_id);
    if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
        set_err(err, errcap, "lvcreate %s (%llu bytes) failed", nm, bytes); return -1;
    }
    set_err(err, errcap, ""); return 0;
}

int raid_mdadm_lv_delete(unsigned md_id, unsigned ordinal, char *err, size_t errcap) {
    raid_lv_t lvs[64];
    unsigned nlv = raid_mdadm_lv_list(md_id, lvs, 64);
    if (ordinal >= nlv) {
        set_err(err, errcap, "no such logical drive (md%u #%u)", md_id, ordinal); return -1;
    }
    char cmd[128], out[512];
    snprintf(cmd, sizeof cmd, "lvremove -f vgmd%u/%s", md_id, lvs[ordinal].name);
    if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
        set_err(err, errcap, "lvremove vgmd%u/%s failed", md_id, lvs[ordinal].name); return -1;
    }
    set_err(err, errcap, ""); return 0;
}

int raid_mdadm_lv_resize(unsigned md_id, unsigned ordinal,
                         unsigned long long bytes, char *err, size_t errcap) {
    raid_lv_t lvs[64];
    unsigned nlv = raid_mdadm_lv_list(md_id, lvs, 64);
    if (ordinal >= nlv) { set_err(err, errcap, "no such logical drive"); return -1; }
    if (bytes <= lvs[ordinal].bytes) { set_err(err, errcap, ""); return 0; }  /* grow-only */
    char cmd[160], out[512];
    snprintf(cmd, sizeof cmd, "lvextend -L %llub vgmd%u/%s", bytes, md_id, lvs[ordinal].name);
    if (raid_cmd_priv(cmd, out, sizeof out) != 0) {
        set_err(err, errcap, "lvextend vgmd%u/%s failed", md_id, lvs[ordinal].name); return -1;
    }
    set_err(err, errcap, ""); return 0;
}
