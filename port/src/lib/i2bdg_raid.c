/*
 * i2bdg_raid.c - RAID enumeration over libi2 (phydrv/logdrv/array/LD-stats).
 *
 * Replaces the Phase-1 UNSUPPORTED stubs for i2bApi_getDiskArrayInfo,
 * i2bApi_getHardDiskDriveInfo, i2bApi_getLogDrvInfo, i2bApi_getStatsLogDrv.
 *
 * Faithful to the vendor libi2bdg mapping (re/out/libi2bdg_x64.c): read a 0xffff
 * response page via i2_raid_*, then copy each fixed-stride record into the
 * documented struct at the RECOVERED offsets (i2_raid.h / docs/RE-i2-raid.md).
 * Record fields are little-endian (the vendor reads them with native loads on
 * x86). Fields with no recovered offset are left zero (CALIBRATE), matching the
 * Phase-1 discipline.
 */
#include "i2bdg_internal.h"
#include "i2_raid.h"
#include "encl_backend.h"
#include "mmio_raid.h"
#include "raid_backend.h"

#include <stdlib.h>
#include <string.h>
#include <stdio.h>

#define PAGE_MAX 0xffff   /* vendor malloc size; also max CDB[10..11] xferlen */

/* Little-endian record readers (pages come from the x86 controller). */
static inline unsigned le16(const unsigned char *p) { return p[0] | p[1] << 8; }
static inline unsigned le32(const unsigned char *p) {
    return (unsigned)p[0] | p[1] << 8 | (unsigned)p[2] << 16 | (unsigned)p[3] << 24;
}
static inline unsigned long long le64(const unsigned char *p) {
    return (unsigned long long)le32(p) | (unsigned long long)le32(p + 4) << 32;
}

/* Fetch a RAID response page into a caller-freed buffer via read_fn. Returns an
 * I2B_API_RET_* code; on success out_page owns a PAGE_MAX buffer to free. */
typedef int (*raid_read_fn)(i2_handle *, unsigned char *, uint32_t);

static int fetch_page(raid_read_fn read_fn, unsigned char **out_page) {
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    unsigned char *page = malloc(PAGE_MAX);
    if (!page) return I2B_API_RET_MEMALLOC_ERR;
    memset(page, 0, PAGE_MAX);
    int rc = i2bdg_map_i2_error(read_fn(h, page, PAGE_MAX));
    if (rc != I2B_API_RET_SUCCESS) { free(page); return rc; }
    *out_page = page;
    return I2B_API_RET_SUCCESS;
}

/* Record count = payload-length header / record stride, clamped to what actually
 * fits inside the fetched page (defends against a malformed length header). */
static unsigned rec_count(const unsigned char *page, unsigned len_off, unsigned rec) {
    unsigned long plen = le32(page + len_off);
    unsigned n = (unsigned)(plen / rec);
    unsigned fit = (PAGE_MAX - I2R_PAGE_HDR) / rec;
    return n < fit ? n : fit;
}

/* thin read_fn adapters (i2_raid_* take a start_id; enumeration starts at 0) */
static int rd_phydrv(i2_handle *h, unsigned char *p, uint32_t n) { return i2_raid_getPhyDrvInfo(h, 0, p, n); }
static int rd_logdrv(i2_handle *h, unsigned char *p, uint32_t n) { return i2_raid_getLogDrvInfo(h, 0, p, n); }
static int rd_array (i2_handle *h, unsigned char *p, uint32_t n) { return i2_raid_getDiskArrayInfo(h, 0, p, n); }
static int rd_stats (i2_handle *h, unsigned char *p, uint32_t n) { return i2_raid_getStatsLogDrv(h, p, n); }

/* ------------------------------------------------------------------ arrays */
I2B_API_RET_E i2bApi_getDiskArrayInfo(I2bArrayInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    if (raid_backend() == RAID_HEALTH) return raid_health_getDiskArrayInfo(out, buflen);
    if (raid_backend() != RAID_SGIO) return raid_mdadm_getDiskArrayInfo(out, buflen);
    if (encl_backend() == ENCL_MMIO) return mmio_getDiskArrayInfo(out, buflen);
    unsigned char *page;
    int rc = fetch_page(rd_array, &page);
    if (rc != I2B_API_RET_SUCCESS) return rc;

    unsigned n = rec_count(page, I2R_PAGE_LEN_OFF_ARR, I2R_REC_ARRAY);
    unsigned long need = sizeof(I2bArrayInfo_t) +
                         (n ? (n - 1) * sizeof(I2bDiskArrayData_t) : 0);
    if (buflen < need) { free(page); return I2B_API_RET_PARAM_ERR; }

    out->DACnt = (unsigned char)n;
    for (unsigned i = 0; i < n; i++) {
        const unsigned char *r = page + I2R_PAGE_HDR + (size_t)i * I2R_REC_ARRAY;
        memset(&out->DAData[i], 0, sizeof out->DAData[i]);   /* zero ArrayName/OpStatus health fields */
        out->DAData[i].DaId     = (unsigned short)le16(r + I2R_DA_ID);
        out->DAData[i].NumOfHdd = (unsigned short)le16(r + I2R_DA_NUMHDD);
    }
    free(page);
    return I2B_API_RET_SUCCESS;
}

/* ------------------------------------------------------------- phys drives */
I2B_API_RET_E i2bApi_getHardDiskDriveInfo(I2bHddInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    if (raid_backend() == RAID_HEALTH) return raid_health_getHardDiskDriveInfo(out, buflen);
    if (raid_backend() != RAID_SGIO) return raid_mdadm_getHardDiskDriveInfo(out, buflen);
    if (encl_backend() == ENCL_MMIO) return mmio_getHardDiskDriveInfo(out, buflen);
    unsigned char *page;
    int rc = fetch_page(rd_phydrv, &page);
    if (rc != I2B_API_RET_SUCCESS) return rc;

    unsigned n = rec_count(page, I2R_PAGE_LEN_OFF, I2R_REC_PHYDRV);
    if (buflen < (unsigned long)n * sizeof(I2bHddInfo_t)) { free(page); return I2B_API_RET_PARAM_ERR; }

    for (unsigned i = 0; i < n; i++) {
        const unsigned char *r = page + I2R_PAGE_HDR + (size_t)i * I2R_REC_PHYDRV;
        I2bHddInfo_t *d = &out[i];
        memset(d, 0, sizeof *d);
        d->NumOfPD       = n;
        d->PDId          = (unsigned short)(le16(r + I2R_PD_ID) + 1);      /* 1-based */
        d->drvType       = (unsigned short)le16(r + I2R_PD_DRVTYPE);
        memcpy(d->driveModelNo, r + I2R_PD_MODEL, sizeof d->driveModelNo);
        d->driveModelNo[sizeof d->driveModelNo - 1] = 0;
        d->confCapacity  = (unsigned long long)le16(r + I2R_PD_NUMBLOCKS) *
                           le64(r + I2R_PD_BLOCKSIZE);
        d->enclosureId   = (unsigned short)(le16(r + I2R_PD_ENCID) + 1);
        d->slotId        = (unsigned short)le16(r + I2R_PD_SLOT);
        d->opStatusFlag  = le32(r + I2R_PD_OPFLAG);
        d->arrayId       = d->opStatusFlag ? (unsigned short)le16(r + I2R_PD_ARRAYID) : 0;
        d->temperature   = r[I2R_PD_TEMP];
        /* opStatus string from the recovered state mask (concise, like Phase 1). */
        const char *s = "Unknown";
        switch (PHYDRV_STATE_FLAG(d->opStatusFlag)) {
            case PHYDRV_STATE_ONLINE:  s = "Online";     break;
            case PHYDRV_STATE_MISSING: s = "Missing";    break;
            case PHYDRV_STATE_REBUILD: s = "Rebuilding"; break;
        }
        strncpy((char *)d->opStatus, s, sizeof d->opStatus - 1);
        /* CALIBRATE (need i2_getControllerSettings page): temp thresholds,
         * controllerId, configStatus string, TempStatusFlag -> left 0. */
    }
    free(page);
    return I2B_API_RET_SUCCESS;
}

/* ------------------------------------------------------------ logical drvs */
static unsigned char ld_cache_policy(unsigned flags) {
    if (flags & 4) return I2BAPI_LDCACHEPOLICY_NOCACHE_WTHRU;      /* 0 */
    if (flags & 2) return (unsigned char)(3 + (flags & 1));       /* 3 or 4 */
    return (unsigned char)(1 + (flags & 1));                      /* 1 or 2 */
}

I2B_API_RET_E i2bApi_getLogDrvInfo(I2bLogDrvInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    if (raid_backend() == RAID_HEALTH) return raid_health_getLogDrvInfo(out, buflen);
    if (raid_backend() != RAID_SGIO) return raid_mdadm_getLogDrvInfo(out, buflen);
    if (encl_backend() == ENCL_MMIO) return mmio_getLogDrvInfo(out, buflen);
    unsigned char *page;
    int rc = fetch_page(rd_logdrv, &page);
    if (rc != I2B_API_RET_SUCCESS) return rc;

    unsigned n = rec_count(page, I2R_PAGE_LEN_OFF, I2R_REC_LOGDRV);
    unsigned long need = sizeof(I2bLogDrvInfo_t) +
                         (n ? (n - 1) * sizeof(I2bLogDrvData_t) : 0);
    if (buflen < need) { free(page); return I2B_API_RET_PARAM_ERR; }

    out->LDCnt = n;
    for (unsigned i = 0; i < n; i++) {
        const unsigned char *r = page + I2R_PAGE_HDR + (size_t)i * I2R_REC_LOGDRV;
        I2bLogDrvData_t *d = &out->LDData[i];
        memset(d, 0, sizeof *d);
        unsigned flag = le32(r + I2R_LD_OPFLAG);
        d->LdId        = (unsigned short)le16(r + I2R_LD_ID);
        d->OpStatusFlag = flag;
        d->RaidLvl     = r[I2R_LD_RAIDLVL];
        d->CachePolicy = ld_cache_policy(le32(r + I2R_LD_CACHEFLAGS));
        d->Capacity    = le64(r + I2R_LD_CAPBLOCKS) << 9;             /* blocks->bytes */
        d->StripeSz    = (unsigned long long)le32(r + I2R_LD_STRIPESECT) << 9;
        d->arrayId     = (unsigned short)le16(r + I2R_LD_ARRAYID);
        d->SYNCed      = (unsigned char)(~(flag >> I2R_LD_SYNC_BIT) & 1);
        memcpy(d->Alias, r + I2R_LD_ALIAS, sizeof d->Alias);
        d->Alias[sizeof d->Alias - 1] = 0;
        const char *s = "Unknown";
        switch (LOGDRV_STATE_FLAG(flag)) {
            case LOGDRV_STATE_ONLINE:   s = "Online";   break;
            case LOGDRV_STATE_OFFLINE:  s = "Offline";  break;
            case LOGDRV_STATE_CRITICAL: s = "Critical"; break;
            case LOGDRV_STATE_DEGRADED: s = "Degraded"; break;
        }
        strncpy((char *)d->OpStatus, s, sizeof d->OpStatus - 1);
        /* CALIBRATE: controllerId (from controller-settings page) left 0. */
    }
    free(page);
    return I2B_API_RET_SUCCESS;
}

/* ---------------------------------------------------------- LD statistics */
I2B_API_RET_E i2bApi_getStatsLogDrv(I2bLogDrvStatusInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    if (raid_backend() == RAID_HEALTH) return raid_health_getStatsLogDrv(out, buflen);
    if (raid_backend() != RAID_SGIO) return raid_mdadm_getStatsLogDrv(out, buflen);
    unsigned char *page;
    int rc = fetch_page(rd_stats, &page);
    if (rc != I2B_API_RET_SUCCESS) return rc;

    unsigned n = rec_count(page, I2R_PAGE_LEN_OFF, I2R_REC_STATS);
    unsigned long need = sizeof(I2bLogDrvStatusInfo_t) +
                         (n ? (n - 1) * sizeof(I2StatsLogicalDrive_t) : 0);
    if (buflen < need) { free(page); return I2B_API_RET_PARAM_ERR; }

    out->LDCnt = n;
    for (unsigned i = 0; i < n; i++) {
        const unsigned char *r = page + I2R_PAGE_HDR + (size_t)i * I2R_REC_STATS;
        I2StatsLogicalDrive_t *d = &out->LDStatsData[i];
        memset(d, 0, sizeof *d);
        d->sld_LogicalDriveId       = r[I2R_ST_LDID];
        d->sld_BlocksTransferred    = le64(r + I2R_ST_BLOCKS)  << 9;
        d->sld_ReadBlocksTransferred = le64(r + I2R_ST_RBLOCKS) << 9;
        d->sld_WriteBlocksTransferred = le64(r + I2R_ST_WBLOCKS) << 9;
        d->sld_Errors      = le32(r + I2R_ST_ERRORS);
        d->sld_ReadErrors  = le32(r + I2R_ST_RERRORS);
        d->sld_WriteErrors = le32(r + I2R_ST_WERRORS);
        d->sld_NonRWErrors = le32(r + I2R_ST_NRWERRORS);
        d->sld_IOReq       = le64(r + I2R_ST_IOREQ);
        d->sld_ReadIOReq   = le64(r + I2R_ST_RIOREQ);
        d->sld_WriteIOReq  = le64(r + I2R_ST_WIOREQ);
        d->sld_NonRWReq    = le64(r + I2R_ST_NRWREQ);
        d->sld_statsStartTime      = le64(r + I2R_ST_STARTTIME);
        d->sld_statsCollectionTime = le64(r + I2R_ST_COLLTIME);
    }
    free(page);
    return I2B_API_RET_SUCCESS;
}
