/*
 * i2_records.c - substrate struct -> PHP object marshalling for the php_i2
 * extension. Mirrors opasd's do_enum* (opas_dispatch.c) but emits PHP objects
 * with the PHP-VISIBLE field names the htdocs read (pdi_FlatId, ldi_FlatId,
 * acd_ArrayID, ci_DeviceID, ... - recovered in RE-opas.md §2 / tree.php,
 * phydrvs.php, logdrvs.php, arrays.php). Reads ride the process-global i2bdg
 * session (fixture mock off-device, /dev/sg* on-device).
 *
 * Underscore-prefixed fields (_opStatus, _cfgStatus, _cfgSize, ...) are the
 * strings php_i2.dll DERIVES from the raw flag/capacity words; the derivations
 * here are documented approximations (a display calibration point), never a
 * faked engine value.
 */
#include "php_i2_int.h"

#include "i2bdg_api.h"
#include "i2bdg_internal.h"   /* i2bdg_session_i2 */
#include "i2.h"               /* i2_fw_cmd */
#include "i2_micops.h"        /* I2_MICOP_GET_CTRL_INFO */
#include "raid_backend.h"     /* raid_mdadm_pd_osname + raid_cmd_priv for SMART */

#include <string.h>
#include <stdlib.h>
#include <stdio.h>
#include <time.h>
#include <ifaddrs.h>          /* management-NIC read for net.php */
#include <net/if.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <dirent.h>           /* /sys/block/md* scan for the BGA monitor pages */

#define BIGBUF (64u * 1024u)

/* Trim trailing spaces/NULs from a fixed-width device string into dst. */
static void field_str(char *dst, size_t cap, const unsigned char *src, size_t n) {
    size_t i = 0;
    for (; i < n && i < cap - 1 && src[i]; i++) dst[i] = (char)src[i];
    while (i && dst[i - 1] == ' ') i--;
    dst[i] = '\0';
}

/* Human capacity string (GB/TB) from a byte count - matches common.php fmtSize. */
static void cap_str(char *dst, size_t cap, unsigned long long bytes) {
    const char *u[] = {"B","KB","MB","GB","TB","PB"};
    double v = (double)bytes; int i = 0;
    while (v >= 1024.0 && i < 5) { v /= 1024.0; i++; }
    snprintf(dst, cap, "%.2f%s", v, u[i]);
}

/* RAID level display string - the vendor DLL emitted ldi_RaidLevel as a string
 * ("RAID5"): logdrv.php compares it against 'RAID6', ldrct.php against 'RAID0'. */
static const char *raid_level_str(unsigned lvl) {
    switch (lvl) {
        case 0x00: return "RAID0";  case 0x01: return "RAID1";
        case 0x03: return "RAID3";  case 0x05: return "RAID5";
        case 0x06: return "RAID6";  case 0x10: return "RAID10";
        case 0x30: return "RAID30"; case 0x50: return "RAID50";
        case 0x51: return "RAID51"; case 0x55: return "RAID55";
        case 0x60: return "RAID60"; default:   return "Unknown";
    }
}

/* ---- physical drives (i2bApi_getHardDiskDriveInfo) ------------------------ */
void i2_records_physicalDrives(zval *return_value)
{
    array_init(return_value);
    I2bHddInfo_t *hdd = calloc(256, sizeof *hdd);
    if (!hdd) return;
    if (i2bApi_getHardDiskDriveInfo(hdd, 256 * sizeof *hdd) != I2B_API_RET_SUCCESS) { free(hdd); return; }
    unsigned n = hdd[0].NumOfPD; if (n > 256) n = 256;
    for (unsigned i = 0; i < n; i++) {
        char model[48]; field_str(model, sizeof model, hdd[i].driveModelNo, 40);
        unsigned flag = hdd[i].opStatusFlag;
        const char *op = (flag & PHYDRV_STATE_ONLINE) ? "OK"
                       : (flag & PHYDRV_STATE_REBUILD) ? "Rebuilding" : "Offline";
        const char *cfg = ((flag & DEVICE_CONFIG_MASK) == PHYDRV_CONFIG_UNCONFIGURED)
                        ? "Unconfigured" : "Array";
        char osn[24]; field_str(osn, sizeof osn, hdd[i].osName, 16);
        zval o; object_init(&o);
        add_property_long(&o, "pdi_FlatId", hdd[i].PDId);
        add_property_string(&o, "pdi_DriveModelNo", model);
        add_property_string(&o, "pdi_OsName", osn);   /* kernel dev name (health backend); empty otherwise */
        add_property_string(&o, "pdi_DriveTypeString", hdd[i].drvType == 4 ? "SAS" : "SATA");
        add_property_long(&o, "pdi_ConfigurableSize", (zend_long)hdd[i].confCapacity);
        add_property_long(&o, "_configurableSize", (zend_long)hdd[i].confCapacity);
        /* 0xFFFF = internal (no hot-swap bay): omit pdi_SlotId so front.php leaves
         * it out of the enclosure bays and phydrvs shows it as "Internal". */
        if (hdd[i].slotId != 0xFFFF)
            add_property_long(&o, "pdi_SlotId", hdd[i].slotId);
        add_property_long(&o, "pdi_EnclosureId", hdd[i].enclosureId);
        add_property_long(&o, "pdi_Flags", (zend_long)flag);
        add_property_long(&o, "pdi_ArrayId", hdd[i].arrayId);
        add_property_long(&o, "temperature", hdd[i].temperature);
        add_property_string(&o, "_opStatus", op);
        add_property_string(&o, "_cfgStatus", cfg);
        /* 0 = usable (free for a new array): getAccessiblePDCount() isset-checks
         * it and the create wizard (arrayc1.php:151) offers only !_cfgEnable
         * drives. A drive already in an array is IN USE -> 1, so it is neither
         * counted nor offered. (Was hardcoded 0, which marked every configured
         * drive as free -> the create wizard offered in-use drives, and
         * checkConfiguration bounced login to the create-array page.) */
        add_property_long(&o, "_cfgEnable",
            ((flag & DEVICE_CONFIG_MASK) == PHYDRV_CONFIG_UNCONFIGURED) ? 0 : 1);
        add_next_index_zval(return_value, &o);
    }
    free(hdd);
}

/* ---- logical drives (i2bApi_getLogDrvInfo) -------------------------------- */
void i2_records_logicalDrives(zval *return_value)
{
    array_init(return_value);
    unsigned char *buf = calloc(1, BIGBUF);
    if (!buf) return;
    I2bLogDrvInfo_t *l = (I2bLogDrvInfo_t *)buf;
    if (i2bApi_getLogDrvInfo(l, BIGBUF) != I2B_API_RET_SUCCESS) { free(buf); return; }
    unsigned n = (unsigned)l->LDCnt;
    for (unsigned i = 0; i < n; i++) {
        char alias[40]; field_str(alias, sizeof alias, l->LDData[i].Alias, 32);
        char sz[24]; cap_str(sz, sizeof sz, l->LDData[i].Capacity);
        unsigned flag = l->LDData[i].OpStatusFlag;
        const char *op = (flag & LOGDRV_STATE_ONLINE) ? "OK"
                       : (flag & LOGDRV_STATE_CRITICAL) ? "Critical"
                       : (flag & LOGDRV_STATE_DEGRADED) ? "Degraded" : "Offline";
        zval o; object_init(&o);
        add_property_long(&o, "ldi_FlatId", l->LDData[i].LdId);
        add_property_string(&o, "ldi_Alias", alias);
        add_property_string(&o, "_name", alias);
        add_property_string(&o, "ldi_RaidLevel", raid_level_str(l->LDData[i].RaidLvl));
        add_property_long(&o, "ldi_Capacity", (zend_long)l->LDData[i].Capacity);
        add_property_string(&o, "_cfgCapacity", sz);
        add_property_long(&o, "ldi_ArrayId", l->LDData[i].arrayId);
        add_property_long(&o, "ldi_Flags", (zend_long)flag);
        add_property_string(&o, "_opStatus", op);
        /* logdrvs.php reads ldi_LDName/ldi_Size/ldi_StripeSize/ldi_SectorSize
         * directly (the builder historically only emitted ldi_Alias/ldi_Capacity,
         * so the list showed blank name/size). Emit the read names too. */
        add_property_string(&o, "ldi_LDName", alias);
        add_property_string(&o, "ldi_Size", sz);
        char stripe[24];
        if (l->LDData[i].StripeSz > 0)
            snprintf(stripe, sizeof stripe, "%llu KB", (unsigned long long)(l->LDData[i].StripeSz / 1024));
        else
            snprintf(stripe, sizeof stripe, "N/A");
        add_property_string(&o, "ldi_StripeSize", stripe);
        add_property_string(&o, "ldi_SectorSize", "512 B");
        add_property_long(&o, "ldi_PreferredOwnerControllerId", 0);
        add_property_long(&o, "ldi_isNasLd", 0);
        add_next_index_zval(return_value, &o);
    }
    free(buf);
}

/* ---- disk arrays (i2bApi_getDiskArrayInfo) -------------------------------- */

/* AvailableRAIDLevels from the member count (RAID-ENGINE-MDADM-PLAN.md P2):
 * 1 drive -> RAID0; 2 -> +RAID1; >=3 -> +RAID5; >=4 -> +RAID6/RAID10.
 * Same rule as common.php getSupportedRAIDLevels (the wizard dropdown). */
static void raid_levels_from_count(unsigned n, char *dst, size_t cap) {
    snprintf(dst, cap, "%s%s%s%s",
             n >= 1 ? "RAID0" : "",
             n >= 2 ? " RAID1" : "",
             n >= 3 ? " RAID5" : "",
             n >= 4 ? " RAID6 RAID10" : "");
}

void i2_records_diskArrays(zval *return_value)
{
    array_init(return_value);
    unsigned char *buf = calloc(1, BIGBUF);
    if (!buf) return;
    I2bArrayInfo_t *a = (I2bArrayInfo_t *)buf;
    if (i2bApi_getDiskArrayInfo(a, BIGBUF) != I2B_API_RET_SUCCESS) { free(buf); return; }
    unsigned n = a->DACnt;

    /* Member/LD joins for the per-DA folds on array.php (only when DAs exist). */
    I2bHddInfo_t *hdd = NULL; unsigned nh = 0;
    unsigned char *lbuf = NULL; unsigned nl = 0;
    I2bLogDrvInfo_t *l = NULL;
    if (n) {
        hdd = calloc(256, sizeof *hdd);
        if (hdd && i2bApi_getHardDiskDriveInfo(hdd, 256 * sizeof *hdd) == I2B_API_RET_SUCCESS)
            nh = hdd[0].NumOfPD <= 256 ? hdd[0].NumOfPD : 256;
        lbuf = calloc(1, BIGBUF);
        l = (I2bLogDrvInfo_t *)lbuf;
        if (lbuf && i2bApi_getLogDrvInfo(l, BIGBUF) == I2B_API_RET_SUCCESS)
            nl = (unsigned)l->LDCnt;
    }

    for (unsigned i = 0; i < n; i++) {
        unsigned da = a->DAData[i].DaId;
        char name[40];
        if (a->DAData[i].ArrayName[0])   /* health backend: real pool/array name */
            snprintf(name, sizeof name, "%s", (const char *)a->DAData[i].ArrayName);
        else
            snprintf(name, sizeof name, "DiskArray%u", da);
        zval o; object_init(&o);
        add_property_long(&o, "acd_ArrayID", da);
        add_property_string(&o, "acd_ArrayName", name);
        add_property_long(&o, "_numOfHdd", a->DAData[i].NumOfHdd);
        add_property_long(&o, "acd_NoOfPhysicalDrives", a->DAData[i].NumOfHdd);
        /* Disk-array op-status: the DAData struct carries no state, so mirror the
         * matching logical drive (same md id), which reports the real
         * degraded/rebuilding state ("Critical"/"Rebuilding (n%)"/"OK"). Falls
         * back to OK when no LD row matches (e.g. the sgio record parse). */
        const char *daop = "OK";
        if (a->DAData[i].OpStatus[0])   /* health backend: real pool/array op-status */
            daop = (const char *)a->DAData[i].OpStatus;
        else
            for (unsigned j = 0; l && j < nl; j++)
                if (l->LDData[j].LdId == da && l->LDData[j].OpStatus[0]) {
                    daop = (const char *)l->LDData[j].OpStatus;
                    break;
                }
        add_property_string(&o, "_opStatus", daop);
        add_property_string(&o, "_opStatusEx", daop);
        /* Capacity + available levels: filled by the mdadm backend via the
         * CfgCapacity port extension; a zero (the sgio record parse) renders
         * exactly the Phase-1 output. */
        if (a->DAData[i].CfgCapacity) {
            char sz[24];
            cap_str(sz, sizeof sz, a->DAData[i].CfgCapacity);
            add_property_string(&o, "_cfgSize", sz);
            cap_str(sz, sizeof sz, a->DAData[i].FreeCapacity);
            add_property_string(&o, "_availableSize", sz);
            char lv[48];
            raid_levels_from_count(a->DAData[i].NumOfHdd, lv, sizeof lv);
            add_property_string(&o, "_raidLevels", lv);
        } else {
            add_property_string(&o, "_cfgSize", "N/A");
            add_property_string(&o, "_availableSize", "0B");
        }
        /* mdadm has no media patrol / PDM / power management concepts. */
        add_property_long(&o, "acd_ArrayFlags", 0);
        add_property_string(&o, "acd_PowerManagement", "N/A");

        /* acd_PDs: the member drives (array.php PD fold). */
        zval pds; array_init(&pds);
        for (unsigned p = 0; p < nh; p++) {
            if (hdd[p].arrayId != da ||
                !(hdd[p].opStatusFlag & PHYDRV_CONFIG_CONFIGURED)) continue;
            char sz[24]; cap_str(sz, sizeof sz, hdd[p].confCapacity);
            zval m; object_init(&m);
            add_property_long(&m, "pdd_PDFlatId", hdd[p].PDId);
            add_property_string(&m, "pdd_PDConfigurableSize", sz);
            add_property_string(&m, "pdd_PDFlags", (const char *)hdd[p].opStatus);
            add_next_index_zval(&pds, &m);
        }
        add_property_zval(&o, "acd_PDs", &pds);
        zval_ptr_dtor(&pds);

        /* acd_LDs: the logical drives on this DA (array.php LD fold). */
        unsigned nld = 0;
        unsigned long long cdd_start = 0;      /* running byte offset for cdd_Start */
        zval lds; array_init(&lds);
        for (unsigned k = 0; k < nl; k++) {
            if (l->LDData[k].arrayId != da) continue;
            nld++;
            char alias[40]; field_str(alias, sizeof alias, l->LDData[k].Alias, 32);
            char sz[24]; cap_str(sz, sizeof sz, l->LDData[k].Capacity);
            zval m; object_init(&m);
            add_property_long(&m, "ldd_LDFlatID", l->LDData[k].LdId);
            add_property_string(&m, "ldd_LDName", alias);
            add_property_string(&m, "ldd_RaidLevel", raid_level_str(l->LDData[k].RaidLvl));
            add_property_string(&m, "ldd_LDSizeInBlocks", sz);
            add_property_string(&m, "ldd_LDFlags", (const char *)l->LDData[k].OpStatus);
            /* numeric fields the LD create/migrate wizards read (logdrvc/logdrvc2/
             * migrate1/migrate2). Axles/sector-code aren't in the mdadm LD struct;
             * synthesize (1 axle, 512B sector) - RAID-ENGINE-LVM-LD-PLAN.md. */
            add_property_long(&m, "_LDSize", (zend_long)l->LDData[k].Capacity);
            add_property_long(&m, "_raidLevel", l->LDData[k].RaidLvl);
            add_property_long(&m, "cdd_Start", (zend_long)cdd_start);
            add_property_long(&m, "ldd_LDNoOfAxles", 1);
            add_property_long(&m, "ldd_StripeSizeInBlocks",
                              (zend_long)(l->LDData[k].StripeSz ? l->LDData[k].StripeSz / 512 : 0));
            add_property_long(&m, "ldd_SectorSizeCode", 1);
            add_property_long(&m, "ldd_isNasLd", 0);
            add_next_index_zval(&lds, &m);
            cdd_start += l->LDData[k].Capacity;
        }
        add_property_zval(&o, "acd_LDs", &lds);
        zval_ptr_dtor(&lds);
        add_property_long(&o, "acd_NoOfLogicalDrives", nld);

        /* No migration engine yet - array.php count()s this. */
        zval tr; array_init(&tr);
        add_property_zval(&o, "_transitionPDs", &tr);
        zval_ptr_dtor(&tr);

        add_next_index_zval(return_value, &o);
    }
    free(lbuf);
    free(hdd);
    free(buf);
}

/* ---- controllers (raw 0x0103 page; product @224 recovered) ---------------- */
/* "N Hours M Minutes" (or "N Days M Hours") from /proc/uptime for ci_Uptime. */
static void sys_uptime_str(char *out, size_t cap) {
    double up = 0;
    FILE *f = fopen("/proc/uptime", "r");
    if (f) { if (fscanf(f, "%lf", &up) != 1) up = 0; fclose(f); }
    long s = (long)up, d = s / 86400, h = (s % 86400) / 3600, m = (s % 3600) / 60;
    if (d) snprintf(out, cap, "%ld Day%s %ld Hour%s",   d, d == 1 ? "" : "s", h, h == 1 ? "" : "s");
    else   snprintf(out, cap, "%ld Hour%s %ld Minute%s", h, h == 1 ? "" : "s", m, m == 1 ? "" : "s");
}

/* Total system RAM in MB - the controller's working memory on this port. */
static long sys_mem_mb(void) {
    long kb = 0;
    FILE *f = fopen("/proc/meminfo", "r");
    if (f) { char l[128]; while (fgets(l, sizeof l, f)) if (sscanf(l, "MemTotal: %ld kB", &kb) == 1) break; fclose(f); }
    return kb / 1024;
}

static const char *env_or(const char *name, const char *fallback) {
    const char *v = getenv(name);
    return (v && *v) ? v : fallback;
}

static void one_controller(zval *o, long dev_id)
{
    object_init(o);
    char product[32] = "VessA3120";   /* base A3120; a real A3120P's RAID VPD overrides below */
    int raid_ok = 0;
    i2_handle *h = i2bdg_session_i2();
    if (h) {
        unsigned char page[0x458]; memset(page, 0, sizeof page);
        if (i2_fw_cmd(h, I2_MICOP_GET_CTRL_INFO, 0, 0, SCSI_XFER_READ, page, sizeof page) == I2_OK) {
            raid_ok = 1;
            char p[32]; field_str(p, sizeof p, page + 224, 16);
            if (p[0]) memcpy(product, p, sizeof product);
        }
    }
    if (!raid_ok) {
        /* No SCSI RAID engine (the ASM2106B runs AHCI). On Linux the RAID engine
         * is mdadm; when that backend is configured the RAID subsystem IS
         * accessible, so report Active rather than the misleading "Not Accessible". */
        const char *rb = getenv("VESSAPP_RAID_BACKEND");
        if (rb && (strcmp(rb, "mdadm") == 0 || strcmp(rb, "health") == 0)) raid_ok = 1;
    }
    /* --- identity (controller.php basic info + services.php _isCClassCtrl) ---
     * The same VPD-backed values the subsystem page shows (env-overridable on
     * real hardware, fixture defaults otherwise) so the two pages agree. */
    add_property_long(o,   "ci_DeviceID", dev_id);
    add_property_string(o, "ci_UserName", "");                       /* admin-settable alias */
    add_property_string(o, "_RaidState", raid_ok ? "OK" : "Not Accessible");
    add_property_string(o, "ci_RaidState", raid_ok ? "OK" : "Not Accessible");
    add_property_string(o, "ci_ReadinessStatus", "Active");
    add_property_long(o,   "ci_ProductID", 0x2900 /* Vess-class, non-C-class */);
    add_property_string(o, "ci_ProductIDString", product);
    add_property_string(o, "ci_CustModel", "Vess A3120");
    add_property_string(o, "ci_CustName", "Promise");
    add_property_string(o, "ci_CustPartNo",   env_or("VESSAPP_PARTNO", ""));   /* vendor VPD, blank off-device */
    add_property_string(o, "ci_CustSerialNo", env_or("VESSAPP_SERIAL", "A3120-FIXTURE-0001"));
    add_property_string(o, "ci_CustRev",      env_or("VESSAPP_HWREV", "A1"));
    add_property_string(o, "ci_WWN",          env_or("VESSAPP_WWN", "2000-0001-5566-0001"));
    add_property_string(o, "ci_MftDate", "");
    add_property_string(o, "ci_SCSIProtocolSupported", "SCSI-3");   /* vendor reports SCSI-3, not the drive bus */

    /* --- status / uptime / cache (mdadm has no controller cache) ------------ */
    char up[48]; sys_uptime_str(up, sizeof up);
    add_property_string(o, "ci_Uptime", up);
    add_property_long(o, "ci_CacheUsagePercent", 0);
    add_property_long(o, "ci_DirtyCachePercent", 0);
    add_property_long(o, "ci_IsMaster", 1);
    add_property_string(o, "ci_ActiveMode", "Active");
    add_property_long(o, "ci_MasterMode", 1);

    /* --- memory / flash (controller.php advanced info; ci2_ prefix) ---------- */
    char mem[32]; snprintf(mem, sizeof mem, "%ld MB", sys_mem_mb());
    add_property_string(o, "ci2_MemoryType", "DDR4");
    add_property_string(o, "ci2_MemorySize", mem);
    add_property_string(o, "ci2_FlashType", "NAND");
    add_property_string(o, "ci2_FlashSize", "128 MB");

    /* --- install package / firmware build (ci1_ prefix) --------------------- */
    add_property_string(o, "ci1_InstallPkgVersion", "1.00.0000.00");   /* the Linux port's own version */
    add_property_string(o, "ci1_InstallPkgBuildDate", "Jul 21, 2026");
    add_property_string(o, "ci1_PriSWBuildDate", "Jul 21 2026, 2026"); /* home.php copyright year */

    /* --- cache line / coercion / intervals ---------------------------------- */
    add_property_long(o, "ci_PreferedCacheLineSize", 64);
    add_property_long(o, "ci_CurrentCacheLineSize", 64);
    add_property_long(o, "ci_CoercionEnable", 0);
    add_property_long(o, "ci_CoercionMethod", 0);
    add_property_long(o, "ci_WriteBackCacheFlushInterval", 0);
    add_property_long(o, "ci_AdaptiveWriteBackCacheEnable", 0);
    add_property_long(o, "ci_EnclosurePollInterval", 15);
    add_property_string(o, "ci_SyncCacheEnable", "Enabled");
    add_property_string(o, "ci_ForcedReadAheadEnable", "Disabled");

    /* --- HDD power management (disabled on this port) ----------------------- */
    add_property_string(o, "ci_SpinDownType", "None");
    add_property_string(o, "ci_HDDPowerLevels", "Auto");
    add_property_long(o, "ci_HDDIdleTime", 0);
    add_property_long(o, "ci_HDDStandbyTime", 0);
    add_property_long(o, "ci_HDDStoppedTime", 0);

    /* --- temperature thresholds (golden CLU: 55 / 65) ----------------------- */
    add_property_long(o, "ci_PDTempThreshold", 55);
    add_property_long(o, "ci_PDCriTempThreshold", 65);

    /* --- background-activity rates (bga.php showBGARate, 0-100 = low/med/high) */
    add_property_long(o, "ci_RebuildRate", 50);
    add_property_long(o, "ci_BkSyncRate", 50);
    add_property_long(o, "ci_LDInitRate", 50);
    add_property_long(o, "ci_RedCheckRate", 50);
    add_property_long(o, "ci_MigrationRate", 50);
    add_property_long(o, "ci_PDMRate", 50);
    add_property_long(o, "ci_TransitionRate", 50);
    add_property_long(o, "ci_ReassignedBlockThreshold", 0);
    add_property_long(o, "ci_ErrorBlockThreshold", 0);

    /* --- media patrol / auto-rebuild ---------------------------------------- */
    add_property_long(o, "ci_MediaPatrolEnable", 1);         /* bgasched.timer */
    add_property_long(o, "ci_AutoRebuildEnable", 1);         /* mdadm rebuilds onto spares */
    add_property_string(o, "ci_MediaPatrolStartTime", "00:00");
    add_property_string(o, "ci_MediaPatrolStopTime", "00:00");

    /* --- capability maxima (A3120 1U4BAY) ----------------------------------- */
    add_property_long(o, "ci_MaxPhysicalDriveSupported", 4);
    add_property_long(o, "ci_MaxDiskArraySupported", 4);
    add_property_long(o, "ci_MaxLogicalDriveSupported", 8);
    add_property_long(o, "ci_MaxGlobalSparesSupported", 4);
    add_property_long(o, "ci_MaxDedicatedSparesSupported", 4);
    add_property_long(o, "ci_MaxTempGlobalSparesSupported", 4);
    add_property_long(o, "ci_MaxTempDedicatedSparesSupported", 4);
    add_property_long(o, "ci_MaxPortsSupported", 4);
    add_property_long(o, "ci_NumOfPortsFailed", 0);

    /* --- misc toggles (ctrl-settings / SMART pages) ------------------------- */
    add_property_long(o, "ci_SmartLogEnable", 1);
    add_property_long(o, "ci_SmartPollInterval", 10);
    add_property_long(o, "ci_LUNAffinityEnable", 0);
    add_property_long(o, "ci_LUNMasking", 0);
    add_property_long(o, "ci_LcdModuleEnable", 0);
    add_property_string(o, "ci_LcdModuleEnableStr", "Disabled");
    add_property_long(o, "ci_CommonWWNNEnable", 0);
    add_property_long(o, "ci_NCQDisable", 0);
    add_property_long(o, "ci_PortPowerCtrl", 0);
}

void i2_records_controllers(zval *return_value)
{
    array_init(return_value);
    zval o; one_controller(&o, 0);
    add_next_index_zval(return_value, &o);
}

void i2_records_controller(zval *return_value, long dev_id)
{
    one_controller(return_value, dev_id);
}

/* ---- software-management service list (services.php + each {name}.php) ------
 * getAllDaemons() -> { errorcode, daemonlist:[{daemonname,daemonstatus,startuptype}] }.
 * Systemd-backed services (Webserver/SSH/NTP) report their real state; the
 * event-driven relays (Email via postfix, SNMP trap) report armed; the legacy
 * Windows/telnet services are listed Stopped/Manual so their config tab is still
 * reachable, matching the vendor's service list. */
static int systemctl_is(const char *verb, const char *unit) {
    if (!unit || !*unit) return 0;
    char cmd[128]; snprintf(cmd, sizeof cmd, "systemctl %s %s 2>/dev/null", verb, unit);
    FILE *p = popen(cmd, "r"); if (!p) return 0;
    char out[32] = ""; if (fgets(out, sizeof out, p)) { /* value in out */ }
    pclose(p);
    /* is-active prints "active"; is-enabled prints "enabled" (reject inactive/disabled) */
    return strstr(out, "active") == out || strstr(out, "enabled") == out;
}

void i2_records_daemons(zval *return_value)
{
    object_init(return_value);
    add_property_long(return_value, "errorcode", 0);   /* PIERR_NO_ERROR */
    zval list; array_init(&list);

    /* Only the services that have a config page (services.php links to
     * {name}.php); listing pageless ones would dead-link. unit != NULL -> query
     * systemd; "Email" -> the emailsetting.xml <enabled> flag; SNMP armed; SLP off. */
    static const struct { const char *name, *unit; int running, autostart; } svcs[] = {
        { "Webserver", "nginx", 0, 0 },
        { "SNMP",      NULL,    1, 1 },   /* trap sender, armed via mdmonitor */
        { "Email",     NULL,    0, 0 },   /* status from svc_email_enabled() below */
        { "SLP",       NULL,    0, 0 },   /* not provided on this port */
    };
    for (unsigned i = 0; i < sizeof svcs / sizeof svcs[0]; i++) {
        int running, autostart;
        if (svcs[i].unit) {
            running   = systemctl_is("is-active",  svcs[i].unit);
            autostart = systemctl_is("is-enabled", svcs[i].unit);
        } else if (strcmp(svcs[i].name, "Email") == 0) {
            running = autostart = svc_email_enabled();
        } else {
            running = svcs[i].running; autostart = svcs[i].autostart;
        }
        zval d; object_init(&d);
        add_property_string(&d, "daemonname",   svcs[i].name);
        add_property_string(&d, "daemonstatus", running   ? "Running"   : "Stopped");
        add_property_string(&d, "startuptype",  autostart ? "Automatic" : "Manual");
        add_next_index_zval(&list, &d);
    }
    add_property_zval(return_value, "daemonlist", &list);
    zval_ptr_dtor(&list);
}

/* ---- management network port (net.php "Management Port" tab) ---------------
 * The A3120's iSCSI/data ports aren't managed here; net.php shows the box's own
 * management NIC. Report the default-route interface's IPv4/mask/MAC/link/speed
 * from /proc/net/route + getifaddrs + /sys/class/net (npis_ + pni_ fields). */
static int netattr(const char *ifc, const char *a, char *out, size_t cap) {
    char path[128]; snprintf(path, sizeof path, "/sys/class/net/%s/%s", ifc, a);
    FILE *f = fopen(path, "r"); if (!f) return 0;
    int ok = fgets(out, cap, f) != NULL; fclose(f);
    if (ok) out[strcspn(out, "\r\n")] = 0;
    return ok;
}

void i2_records_mgmtNet(zval *return_value)
{
    array_init(return_value);

    /* default-route interface = the management NIC */
    char ifc[IF_NAMESIZE] = "";
    FILE *r = fopen("/proc/net/route", "r");
    if (r) {
        char ln[256], name[IF_NAMESIZE]; unsigned dest;
        if (fgets(ln, sizeof ln, r)) { /* skip header */ }
        while (fgets(ln, sizeof ln, r))
            if (sscanf(ln, "%15s %x", name, &dest) == 2 && dest == 0) {
                snprintf(ifc, sizeof ifc, "%s", name); break;
            }
        fclose(r);
    }
    if (!ifc[0]) return;

    char ip[INET_ADDRSTRLEN] = "", mask[INET_ADDRSTRLEN] = "";
    struct ifaddrs *ifa;
    if (getifaddrs(&ifa) == 0) {
        for (struct ifaddrs *p = ifa; p; p = p->ifa_next) {
            if (!p->ifa_addr || p->ifa_addr->sa_family != AF_INET) continue;
            if (strcmp(p->ifa_name, ifc) != 0) continue;
            inet_ntop(AF_INET, &((struct sockaddr_in *)p->ifa_addr)->sin_addr, ip, sizeof ip);
            if (p->ifa_netmask)
                inet_ntop(AF_INET, &((struct sockaddr_in *)p->ifa_netmask)->sin_addr, mask, sizeof mask);
            break;
        }
        freeifaddrs(ifa);
    }

    char mac[24] = "", oper[16] = "", spd[16] = "";
    netattr(ifc, "address", mac, sizeof mac);
    netattr(ifc, "operstate", oper, sizeof oper);
    netattr(ifc, "speed", spd, sizeof spd);
    long speed = spd[0] ? atol(spd) : 0;
    if (speed < 0) speed = 0;                 /* /sys reports -1 when the link is down */

    zval o; object_init(&o);
    add_property_long(&o, "npis_PortId", 0);          /* net.php:91 renders +1 */
    add_property_string(&o, "npis_PortName", ifc);
    add_property_long(&o, "npis_PortEnabled", 1);
    add_property_string(&o, "npis_PortStatus", strcmp(oper, "up") == 0 ? "Up" : "Down");
    add_property_string(&o, "npis_IPAddressType", "Static");
    add_property_string(&o, "npis_PrimaryIPAddress", ip);
    add_property_string(&o, "npis_PrimarySubnetMask", mask);
    add_property_string(&o, "npis_PrimaryMACAddress", mac);
    add_property_long(&o, "pni_MaxSupportedSpeed", speed);
    add_property_long(&o, "activeCtrl", 0);

    /* net.php iterates $nets[0]->_ports[] - wrap the port in a net record, else
     * $net->_ports is null and count() fatals (net.php:87). */
    zval net; object_init(&net);
    zval ports; array_init(&ports);
    add_next_index_zval(&ports, &o);
    add_property_zval(&net, "_ports", &ports);
    zval_ptr_dtor(&ports);
    add_next_index_zval(return_value, &net);
}

/* ---- physical-drive SMART (phydrvSmart.php getPhysicalDriveSMARTInfo) -------
 * www-data can't issue ATA SMART commands, so read them through the setuid
 * helper (smartctl is a whitelisted, /dev-validated verb) and parse health,
 * support and temperature into the ->status object the page renders. mdadm
 * backend only; the sgio path keeps the vendor SMART CDB. */
#define SMART_CAP 8192

/* RAW_VALUE (10th column) of the first SMART temperature attribute; -1 if none. */
static int smart_temp(const char *out) {
    const char *p = strstr(out, "Temperature_Celsius");
    if (!p) p = strstr(out, "Airflow_Temperature_Cel");
    if (!p) return -1;
    while (p > out && p[-1] != '\n') p--;
    char line[256];
    const char *eol = strchr(p, '\n');
    size_t len = eol ? (size_t)(eol - p) : strlen(p);
    if (len >= sizeof line) len = sizeof line - 1;
    memcpy(line, p, len); line[len] = 0;
    int col = 0, raw = -1;
    for (char *t = strtok(line, " \t"); t; t = strtok(NULL, " \t"), col++)
        if (col == 9) { raw = atoi(t); break; }
    return raw;
}

/* Failure-predictor raw counts collected while parsing the attribute table. */
typedef struct { long realloc_c, pending, uncorr, reported; } smart_pf;

/* Parse the smartctl -A attribute table into ->attr[] + ->threshold[] (fills the
 * SMART Attributes table phydrvSmart.php renders, previously always empty) and
 * capture the failure-predictor raw counts. Columns (whitespace-separated):
 *   0 ID  1 NAME  2 FLAG  3 VALUE  4 WORST  5 THRESH ... 9 RAW_VALUE. */
static void smart_attr_table(zval *parent, const char *out, smart_pf *pf)
{
    zval attr, thr; array_init(&attr); array_init(&thr);
    memset(pf, 0, sizeof *pf);
    const char *p = strstr(out, "ID# ATTRIBUTE_NAME");
    if (p) p = strchr(p, '\n');
    while (p && *p == '\n') {
        p++;                                    /* first char of the next line   */
        if (*p == '\n' || *p == '\r' || *p == 0) break;   /* blank => table end  */
        char line[256];
        const char *eol = strchr(p, '\n');
        size_t len = eol ? (size_t)(eol - p) : strlen(p);
        if (len >= sizeof line) len = sizeof line - 1;
        memcpy(line, p, len); line[len] = 0;
        int col = 0, id = 0, value = 0, worst = 0, thresh = 0; long raw = 0;
        for (char *t = strtok(line, " \t"); t; t = strtok(NULL, " \t"), col++)
            switch (col) {
                case 0: id = atoi(t); break;    case 3: value = atoi(t); break;
                case 4: worst = atoi(t); break; case 5: thresh = atoi(t); break;
                case 9: raw = atol(t); break;   /* raw may carry trailing "(x/y)" */
            }
        if (id > 0 && col >= 10) {
            zval a; object_init(&a);
            add_property_long(&a, "id", id);      add_property_long(&a, "current", value);
            add_property_long(&a, "max", worst);  add_property_long(&a, "raw", raw);
            add_next_index_zval(&attr, &a);
            zval th; object_init(&th);
            add_property_long(&th, "id", id);     add_property_long(&th, "threshold", thresh);
            add_next_index_zval(&thr, &th);
            switch (id) {
                case 5:   pf->realloc_c = raw; break;   case 197: pf->pending  = raw; break;
                case 198: pf->uncorr    = raw; break;   case 187: pf->reported = raw; break;
            }
        }
        p = eol;                                /* NULL ends the outer while      */
    }
    add_property_zval(parent, "attr", &attr);
    add_property_zval(parent, "threshold", &thr);
    zval_ptr_dtor(&attr); zval_ptr_dtor(&thr);
}

/* Transparent health score from the SMART failure predictors: 100 minus deductions
 * for reallocated/pending/uncorrectable/reported-uncorrectable sectors, clamped to
 * critical when smartctl's overall self-assessment fails. Not a vendor score
 * (HDSentinel's model is proprietary + licensed) - a monotonic heuristic that drops
 * as the disk accrues the sector faults that actually predict failure.
 * ponytail: fixed weights; make them tunable only if a real drive needs recalibrating. */
static int smart_health_pct(const smart_pf *pf, const char *health)
{
    int h = 100;
    h -= pf->realloc_c > 20 ? 40 : (int)(pf->realloc_c * 2);   /* reallocated sectors  */
    h -= pf->pending   >  8 ? 40 : (int)(pf->pending   * 5);   /* pending realloc (worse) */
    h -= pf->uncorr    >  8 ? 40 : (int)(pf->uncorr    * 5);   /* offline uncorrectable   */
    h -= pf->reported  > 20 ? 20 : (int)pf->reported;          /* reported uncorrectable  */
    if (strcmp(health, "PASSED") && strcmp(health, "OK") && strcmp(health, "Unknown") && h > 10)
        h = 10;                                               /* overall SMART FAILED */
    return h < 0 ? 0 : h;
}

void i2_records_phySmart(zval *return_value, long pd_id)
{
    object_init(return_value);   /* no ->status -> page shows "No SMART status information" */
    char dev[16];
    if (raid_backend() != RAID_MDADM ||
        !raid_mdadm_pd_osname((unsigned short)pd_id, dev, sizeof dev))
        return;

    char cmd[64], *out = calloc(1, SMART_CAP);
    if (!out) return;
    snprintf(cmd, sizeof cmd, "smartctl -H -A -i /dev/%s", dev);
    if (raid_cmd_priv(cmd, out, SMART_CAP) != 0) { free(out); return; }

    int available = strstr(out, "SMART support is: Available") != NULL;
    int enabled   = strstr(out, "SMART support is: Enabled")   != NULL;
    char health[16] = "Unknown";
    const char *hp = strstr(out, "test result:");
    if (hp) sscanf(hp + (int)sizeof("test result:") - 1, "%15s", health);
    int temp = smart_temp(out);

    smart_pf pf;
    smart_attr_table(return_value, out, &pf);   /* ->attr + ->threshold + predictors */

    zval st; object_init(&st);
    add_property_long(&st, "IsPDSAS", 0);                       /* A3120 disks are SATA */
    add_property_long(&st, "PhysicalDriveID", pd_id);
    add_property_long(&st, "SMARTSupport", available || enabled);
    add_property_long(&st, "SMARTStatus", enabled);
    add_property_string(&st, "SMARTHealthStatus",
                        strcmp(health, "PASSED") == 0 ? "OK" : health);
    add_property_long(&st, "HealthPercent", smart_health_pct(&pf, health));
    if (temp >= 0) add_property_long(&st, "CurrentDrvTemperature", temp);
    add_property_zval(return_value, "status", &st);
    zval_ptr_dtor(&st);          /* add_property_zval took its own reference */
    free(out);
}

/* ---- background activity monitors (bga.php + rebuild/ldinit/ldsync/ldrc/mpatrol)
 * Surface the arrays whose mdadm sync_action is running, with live percent from
 * /sys (world-readable, no privilege). sync_action -> WebPAM op:
 *   recover -> Rebuild   resync -> Synchronization   check -> Redundancy Check
 *   repair -> Initialization   reshape -> Migration. Media Patrol is a check
 *   scrub (bgasched) so it reports check activity too. Idle arrays are skipped,
 *   so off-activity every page renders empty (correct). */
static void md_sysattr(int mdid, const char *a, char *out, size_t cap) {
    char p[96]; snprintf(p, sizeof p, "/sys/block/md%d/md/%s", mdid, a);
    out[0] = 0;
    FILE *f = fopen(p, "r"); if (!f) return;
    if (fgets(out, cap, f)) out[strcspn(out, "\r\n")] = 0;
    fclose(f);
}

static int md_sync_pct(int mdid) {
    char v[64]; md_sysattr(mdid, "sync_completed", v, sizeof v);
    unsigned long long a = 0, b = 0;
    if (sscanf(v, "%llu / %llu", &a, &b) == 2 && b) return (int)(a * 100 / b);
    return 0;
}

/* the md member currently being recovered (state lacks in_sync/faulty/spare) */
static void md_recovering_dev(int mdid, char *out, size_t cap) {
    out[0] = 0;
    char dir[64]; snprintf(dir, sizeof dir, "/sys/block/md%d/md", mdid);
    DIR *d = opendir(dir); if (!d) return;
    struct dirent *e;
    while ((e = readdir(d))) {
        if (strncmp(e->d_name, "dev-", 4)) continue;
        char sp[160], st[48] = "";
        snprintf(sp, sizeof sp, "%s/%s/state", dir, e->d_name);
        FILE *f = fopen(sp, "r"); if (!f) continue;
        if (fgets(st, sizeof st, f)) st[strcspn(st, "\r\n")] = 0;
        fclose(f);
        if (!strstr(st, "in_sync") && !strstr(st, "faulty") && !strstr(st, "spare")) {
            snprintf(out, cap, "%s", e->d_name + 4); break;
        }
    }
    closedir(d);
}

typedef struct { int ld, arrayId, pct; char alias[40], action[16]; } ld_bga;
#define BGA_MAX 32

static int ld_bga_scan(ld_bga *out, int cap) {
    unsigned char *buf = calloc(1, BIGBUF);
    if (!buf) return 0;
    I2bLogDrvInfo_t *l = (I2bLogDrvInfo_t *)buf;
    int n = 0;
    if (i2bApi_getLogDrvInfo(l, BIGBUF) == I2B_API_RET_SUCCESS) {
        unsigned cnt = (unsigned)l->LDCnt;
        for (unsigned i = 0; i < cnt && n < cap; i++) {
            int id = l->LDData[i].LdId;
            char act[16]; md_sysattr(id, "sync_action", act, sizeof act);
            if (!act[0] || strcmp(act, "idle") == 0) continue;
            out[n].ld = id;
            out[n].arrayId = l->LDData[i].arrayId;
            out[n].pct = md_sync_pct(id);
            field_str(out[n].alias, sizeof out[n].alias, l->LDData[i].Alias, 32);
            snprintf(out[n].action, sizeof out[n].action, "%s", act);
            n++;
        }
    }
    free(buf);
    return n;
}

/* getRebuilds()/getRebuildByLd($ld): recover activity (ld < 0 = all). */
void i2_records_rebuilds(zval *return_value, long ld_filter)
{
    array_init(return_value);
    ld_bga b[BGA_MAX]; int n = ld_bga_scan(b, BGA_MAX);
    for (int i = 0; i < n; i++) {
        if (strcmp(b[i].action, "recover")) continue;
        if (ld_filter >= 0 && b[i].ld != ld_filter) continue;
        char dev[16]; md_recovering_dev(b[i].ld, dev, sizeof dev);
        int pd = dev[0] ? raid_mdadm_osname_pd(dev) : 0;   /* 1-based, 0 = unknown */
        zval o; object_init(&o);
        add_property_long(&o, "rbpd_ArrayID", b[i].arrayId);
        add_property_long(&o, "rbpd_LDFlatId", b[i].ld);
        add_property_string(&o, "rbpd_LD", b[i].alias);
        add_property_long(&o, "rbpd_PDFlatId", pd > 0 ? pd - 1 : 0);   /* page renders +1 */
        add_property_string(&o, "rbpd_PD", dev);
        add_property_long(&o, "rbpd_Type", 0);                          /* rebuild */
        add_property_string(&o, "rbpd_State", "Rebuilding");
        add_property_long(&o, "rbpd_SeqNumber", 0);
        add_property_long(&o, "rbpd_PD_ProgressinPercentage", b[i].pct);
        add_next_index_zval(return_value, &o);
    }
}

void i2_records_inits(zval *return_value)     /* repair scrub */
{
    array_init(return_value);
    ld_bga b[BGA_MAX]; int n = ld_bga_scan(b, BGA_MAX);
    for (int i = 0; i < n; i++) {
        if (strcmp(b[i].action, "repair")) continue;
        zval o; object_init(&o);
        add_property_long(&o, "ldi_LdFlatId", b[i].ld);
        add_property_string(&o, "ldi_LdiState", "Initializing");
        add_property_long(&o, "ldi_ProgressinPercentage", b[i].pct);
        add_next_index_zval(return_value, &o);
    }
}

void i2_records_syncs(zval *return_value)     /* resync */
{
    array_init(return_value);
    ld_bga b[BGA_MAX]; int n = ld_bga_scan(b, BGA_MAX);
    for (int i = 0; i < n; i++) {
        if (strcmp(b[i].action, "resync")) continue;
        zval o; object_init(&o);
        add_property_long(&o, "bspd_LDFlatId", b[i].ld);
        add_property_string(&o, "bspd_LD", b[i].alias);
        add_property_string(&o, "bspd_State", "Synchronizing");
        add_property_long(&o, "bspd_LD_ProgressinPercentage", b[i].pct);
        add_next_index_zval(return_value, &o);
    }
}

void i2_records_rcs(zval *return_value)       /* check scrub */
{
    array_init(return_value);
    ld_bga b[BGA_MAX]; int n = ld_bga_scan(b, BGA_MAX);
    for (int i = 0; i < n; i++) {
        if (strcmp(b[i].action, "check")) continue;
        zval o; object_init(&o);
        add_property_long(&o, "rcpd_LDFlatId", b[i].ld);
        add_property_string(&o, "rcpd_LD", b[i].alias);
        add_property_string(&o, "rcpd_State", "Running");
        add_property_long(&o, "rcpd_LD_ProgressinPercentage", b[i].pct);
        add_next_index_zval(return_value, &o);
    }
}

void i2_records_migrates(zval *return_value)  /* reshape */
{
    array_init(return_value);
    ld_bga b[BGA_MAX]; int n = ld_bga_scan(b, BGA_MAX);
    for (int i = 0; i < n; i++) {
        if (strcmp(b[i].action, "reshape")) continue;
        zval o; object_init(&o);
        add_property_long(&o, "mp_ArrayID", b[i].arrayId);
        add_property_string(&o, "mp_State", "Migrating");
        add_property_long(&o, "mp_OverallPercentage", b[i].pct);
        add_next_index_zval(return_value, &o);
    }
}

/* getMediaPatrolStatus(): single object; mppd_State is left UNSET when no check
 * scrub is running (bga.php gates the row on isset). */
void i2_records_mediaPatrol(zval *return_value)
{
    object_init(return_value);
    ld_bga b[BGA_MAX]; int n = ld_bga_scan(b, BGA_MAX);
    for (int i = 0; i < n; i++) {
        if (strcmp(b[i].action, "check")) continue;
        add_property_string(return_value, "mppd_State", "Running");
        add_property_long(return_value, "mppd_OverallPercentage", b[i].pct);
        add_property_long(return_value, "mppd_ProgressinPercentage", b[i].pct);
        add_property_long(return_value, "mppd_PDFlatId", 0);
        add_property_long(return_value, "mppd_CompletedPDCount", 0);
        add_property_long(return_value, "mppd_QueuedPDCount", 0);
        break;
    }
}

/* ---- spare drives (P3): PDs configured as dedicated spares ---------------- */
static void one_spare(zval *o, const I2bHddInfo_t *h)
{
    object_init(o);
    char sz[24]; cap_str(sz, sizeof sz, h->confCapacity);
    unsigned flag = h->opStatusFlag;
    add_property_long(o, "pdd_PDFlatId", h->PDId);
    add_property_string(o, "pdd_PDConfigurableSize", sz);
    add_property_string(o, "_OpStatus", (flag & PHYDRV_STATE_ONLINE) ? "OK"
                                      : (flag & PHYDRV_STATE_REBUILD) ? "Rebuilding" : "Spare");
    add_property_long(o, "acd_ArrayID", h->arrayId);       /* the array it stands by for */
    add_property_long(o, "acd_ArrayFlags", 0);
    add_property_long(o, "acd_ArrayAttributes", 0);
}

void i2_records_spareDrives(zval *return_value)
{
    array_init(return_value);
    I2bHddInfo_t *hdd = calloc(256, sizeof *hdd);
    if (!hdd) return;
    if (i2bApi_getHardDiskDriveInfo(hdd, 256 * sizeof *hdd) != I2B_API_RET_SUCCESS) { free(hdd); return; }
    unsigned n = hdd[0].NumOfPD; if (n > 256) n = 256;
    for (unsigned i = 0; i < n; i++) {
        if ((hdd[i].opStatusFlag & DEVICE_CONFIG_MASK) != PHYDRV_CONFIG_DEDICATEDSPARE) continue;
        zval o; one_spare(&o, &hdd[i]);
        add_next_index_zval(return_value, &o);
    }
    free(hdd);
}

/* getSpareDrive($arrayId): the spare standing by for that array. Returns a
 * 0/1-element ARRAY (not a bare object): spare.php / sparet.php / sparecheck.php
 * read it with count()/[0], which is a fatal TypeError on a stdClass under PHP
 * 8. Mirrors i2_records_spareDrives (plural), filtered to one array. */
void i2_records_spareDrive(zval *return_value, long array_id)
{
    array_init(return_value);
    I2bHddInfo_t *hdd = calloc(256, sizeof *hdd);
    if (!hdd) return;
    if (i2bApi_getHardDiskDriveInfo(hdd, 256 * sizeof *hdd) != I2B_API_RET_SUCCESS) { free(hdd); return; }
    unsigned n = hdd[0].NumOfPD; if (n > 256) n = 256;
    for (unsigned i = 0; i < n; i++) {
        if ((hdd[i].opStatusFlag & DEVICE_CONFIG_MASK) != PHYDRV_CONFIG_DEDICATEDSPARE) continue;
        if ((long)hdd[i].arrayId != array_id) continue;
        zval o; one_spare(&o, &hdd[i]);
        add_next_index_zval(return_value, &o);
        break;
    }
    free(hdd);
}

/* ---- RAID events (P5): the promise-mdmonitor log -> events.php records ------
 * The mdadm --monitor handler appends tab lines: <ts> <event> <id> <sev> <md>
 * <dev>. We surface them as the vendor event records events.php reads. Kept a
 * small self-contained id->text table (the daemons' event_map.c is the fuller
 * source of truth for email/SNMP). */
#define RAID_EVENT_LOG "/var/log/promise-raid/events.log"

static const char *ev_sev_word(int s) {
    static const char *N[] = { "Unknown", "Other", "Info", "Warning",
                               "Minor", "Major", "Critical", "Fatal" };
    return (s >= 0 && s <= 7) ? N[s] : "Unknown";
}

static const char *ev_desc(unsigned long id) {
    switch (id) {
        case 0x000D0002UL: return "Physical Disk is marked as DEAD";
        case 0x000D0005UL: return "A hot spare has been activated to rebuild a degraded array";
        case 0x00130006UL: return "The array is incomplete - a physical drive is missing";
        case 0x00130002UL: return "Disk array rebuild has started";
        case 0x00130003UL: return "Disk array rebuild has completed";
        case 0x00090003UL: return "Logical drive has been placed offline";
        case 0x00100000UL: return "SMART error received";
        default:           return "RAID event";
    }
}

/* Unix epoch -> "YYYY-MM-DD HH:MM:SS" local time (events.php shows _Timestamp). */
static void fmt_ts(char *out, size_t cap, unsigned long ts) {
    time_t t = (time_t)ts; struct tm tm;
    if (ts && localtime_r(&t, &tm)) strftime(out, cap, "%Y-%m-%d %H:%M:%S", &tm);
    else snprintf(out, cap, "-");
}

void i2_records_events(zval *return_value, long max)
{
    array_init(return_value);
    FILE *f = fopen(RAID_EVENT_LOG, "r");
    if (!f) return;
    char line[512];

    /* Pass 1: count valid records so `max` returns the most-recent N. getEvents
     * and getLastEvents both pass a bound; ignoring it let the dashboard panel
     * grow without limit. */
    long total = 0;
    while (fgets(line, sizeof line, f)) {
        unsigned long ts, id; int sev; char ev[32], md[32], dev[32];
        if (sscanf(line, "%lu\t%31[^\t]\t%li\t%d\t%31[^\t]\t%31[^\n]",
                   &ts, ev, &id, &sev, md, dev) >= 4) total++;
    }
    long skip = (max > 0 && total > max) ? total - max : 0;
    rewind(f);

    long seq = 0;
    while (fgets(line, sizeof line, f)) {
        unsigned long ts = 0, id = 0; int sev = 2;
        char ev[32] = "", md[32] = "", dev[32] = "";
        if (sscanf(line, "%lu\t%31[^\t]\t%li\t%d\t%31[^\t]\t%31[^\n]",
                   &ts, ev, &id, &sev, md, dev) < 4) continue;
        seq++;
        if (seq <= skip) continue;              /* keep only the last `max`     */
        char spec[80], desc[160], when[24];
        snprintf(spec, sizeof spec, "%s%s%s", md, dev[0] ? " " : "", dev);
        snprintf(desc, sizeof desc, "%s (%s%s%s)", ev_desc(id), md,
                 dev[0] ? ", " : "", dev);
        fmt_ts(when, sizeof when, ts);
        zval o; object_init(&o);
        add_property_long(&o, "evt_SequenceNumber", seq);
        add_property_long(&o, "evt_DeviceId", 0);
        add_property_long(&o, "evt_VendorEventId", (zend_long)id);
        add_property_long(&o, "evt_defaultEventId", (zend_long)id);
        add_property_string(&o, "_Timestamp", when);      /* human date, was raw epoch */
        add_property_long(&o, "evt_Timestamp", (zend_long)ts);  /* raw epoch (sort key) */
        add_property_string(&o, "_Severity", ev_sev_word(sev));
        add_property_string(&o, "evt_Severity", ev_sev_word(sev));
        add_property_string(&o, "evt_specInfo", spec);
        add_property_string(&o, "evt_Desc", desc);
        add_next_index_zval(return_value, &o);
    }
    fclose(f);
}

/* ---- users (fixture operator; the auth gate already ran at login) --------- */
void i2_records_users(zval *return_value)
{
    const char *user = getenv("I2_FIXTURE_USER");
    if (!user || !*user) user = "administrator";
    object_init(return_value);
    add_property_long(return_value, "errorcode", 0 /* PIERR_NO_ERROR */);
    zval list; array_init(&list);

    /* built-in local administrator (webpam.passwd / fixture) */
    zval u; object_init(&u);
    add_property_string(&u, "username", user);
    add_property_long(&u, "userPrivilege", 4 /* USER_GROUP_SUPER */);
    /* users.php renders Status from ->enabled and Privilege from
     * getStringUserPrivilege(->userGroupId) - not ->userPrivilege - so set both,
     * else the admin row shows "Disabled / User". */
    add_property_long(&u, "userGroupId", 4 /* USER_GROUP_SUPER -> "Administrator" */);
    add_property_long(&u, "enabled", 1);
    add_property_string(&u, "displayName", user);
    add_property_string(&u, "email", "");
    add_property_long(&u, "isPamUser", 0);
    add_next_index_zval(&list, &u);

    /* Linux/PAM allowlist users (/etc/promise/webpam.pamusers "user:groupId"): each
     * logs in with their Linux password via PAM and carries its granted WebPAM
     * privilege. Listing them here makes a PAM user's session privilege resolve
     * (getUserPrivilege matches $_SESSION['username'] against this list) AND shows
     * them in the user-management page. isPamUser=1 marks a Linux grant. */
    FILE *pf = fopen("/etc/promise/webpam.pamusers", "r");
    if (pf) {
        char line[256];
        while (fgets(line, sizeof line, pf)) {
            line[strcspn(line, "\r\n")] = 0;
            char *colon = strchr(line, ':');
            if (!colon || colon == line) continue;
            *colon = 0;
            int gid = atoi(colon + 1);
            if (gid < 1 || gid > 4) continue;
            zval p; object_init(&p);
            add_property_string(&p, "username", line);
            add_property_long(&p, "userPrivilege", gid);
            add_property_long(&p, "userGroupId", gid);
            add_property_long(&p, "enabled", 1);
            add_property_string(&p, "displayName", line);
            add_property_string(&p, "email", "");
            add_property_long(&p, "isPamUser", 1);
            add_next_index_zval(&list, &p);   /* array takes ownership; no dtor */
        }
        fclose(pf);
    }

    add_property_zval(return_value, "userlist", &list);
    zval_ptr_dtor(&list);
}

/* ---- enclosure (i2bApi_getEncInfo + element counts + fans/temps/volts) ----
 * getEnclosures() must return an ARRAY of enclosure records: encls.php:20
 * count()s the no-arg call and reads [0] on the per-id call, phydrvs.php:24
 * foreach-es it - PHP 8 fatals on count(stdClass). The record carries the RAW
 * mpvs_ + max + underscore fields the vendor pages read (encls.php:78, encl.php
 * getEnclosureInfo + show*Fold, front.php). The numeric mpvs_EnclosureType IS
 * the chassis model byte: encl.php's own "case 0x57: // ENC_TYPE_VESSAPP_1U4BAY"
 * literals equal the i2c_map.h model bytes ('W' = 0x57 = A3120, confirmed on
 * the golden box - docs/RE-scsi-wire-capture.md). */

/* Chassis-model table: EncType string (i2bApi_getEncInfo) -> model byte + the
 * bay count front.php hardwires per model. Its per-model branch iterates the
 * grid's _flats unconditionally, so _slots must match the MODEL's bay grid
 * (the A3120 is the 0x57/4-bay grid; smbus reports the same 4 wired bays). */
static const struct { const char *suffix; long mpvs; long slots; } enc_models[] = {
    { "3U16BAY", 0x52, 16 },
    { "2U8BAY",  0x54,  8 },
    { "2U6BAY",  0x50,  6 },
    { "2U4BAY",  0x55,  4 },
    { "1U4BAY",  0x57,  4 },
};

void i2_records_enclosure(zval *return_value, long enc_id)
{
    array_init(return_value);

    I2bEncData_t enc; memset(&enc, 0, sizeof enc);
    if (i2bApi_getEncInfo(&enc, sizeof enc) != I2B_API_RET_SUCCESS)
        return;   /* no identity -> empty list -> encls.php "not accessible" */

    I2bElementCount_t cnt; memset(&cnt, 0, sizeof cnt);
    int have_cnt = i2bApi_getElementCnt(&cnt) == I2B_API_RET_SUCCESS;

    long mpvs = 0;
    long slots = (have_cnt && cnt.NumOfHDSlots > 0) ? cnt.NumOfHDSlots : 4;
    for (size_t m = 0; m < sizeof enc_models / sizeof enc_models[0]; m++)
        if (strstr((const char *)enc.EncType, enc_models[m].suffix)) {
            mpvs = enc_models[m].mpvs; slots = enc_models[m].slots; break;
        }

    zval o; object_init(&o);
    add_property_long(&o, "_id", enc_id);
    add_property_long(&o, "mpvs_EnclosureType", mpvs);
    /* A3120 controller type: front.php:227 / encl.php:595 key their A3120
     * branches on mpvs_CtrlType == 0x80B (vendor literal). Not VR/VJ C-class. */
    add_property_long(&o, "mpvs_CtrlType", 0x80B);
    add_property_string(&o, "_encType", (const char *)enc.EncType);
    add_property_string(&o, "_EnclosureTypeString", (const char *)enc.EncType);
    /* Display derivations (documented approximations, like _opStatus above):
     * the SEP overall-status decode is unrecovered -> OK once identity reads;
     * description is the product this chassis belongs to. */
    add_property_string(&o, "_EnclosureOverallStatus", "OK");
    add_property_string(&o, "_EnclosureDescription", "Vess A3120");
    add_property_long(&o, "es_u8PowerSwitch", 1);

    /* showEnclFold maxima: fixed chassis constants for the A3120 1U4BAY, matched
     * to the LIVE hardware (NCT7802Y tachs on 192.0.2.5): the 3 tach elements
     * are 1 CPU FAN (tach0, ~1.6k rpm) + 2 BLOWERS (tach1/2 "Cooling Unit", ~4.7k
     * rpm). So: 1 controller, 4 slots, 1 fan, 2 blowers, 2 temp sensors (CPU +
     * Controller), 1 PSU, 0 batteries, 4 voltage rails. */
    add_property_long(&o, "maxNumOfControllers", 1);
    add_property_long(&o, "maxNumOfPhyDrvSlots", slots);
    add_property_long(&o, "maxNumOfFans", 1);
    add_property_long(&o, "maxNumOfBlowers", 2);
    add_property_long(&o, "maxNumOfTempSensor", 2);
    add_property_long(&o, "maxNumPSU", 1);
    add_property_long(&o, "maxNumOfVoltageSensors", 4);
    add_property_long(&o, "_numFans", 1);
    add_property_long(&o, "_numBlowers", 2);
    add_property_long(&o, "_numTempSensors", have_cnt ? cnt.NumOfTempSensors : 0);
    add_property_long(&o, "_numVoltageSensors", have_cnt ? cnt.NumOfVoltageSensors : 0);

    enum { MAXE = 16 };

    /* temps: encl.php getTempInfo reads _location/_temp and derives status from
     * the record's warning/critical thresholds. Substrate thresholds are 0
     * until calibrated -> fall back to the golden A3120 thresholds (ctrl warn
     * 90 C / crit 100 C, CALIBRATION-A3120-golden.md). */
    I2bThermalSensorData_t ts[MAXE]; memset(ts, 0, sizeof ts);
    unsigned nt = (have_cnt && cnt.NumOfTempSensors <= MAXE) ? cnt.NumOfTempSensors : 0;
    if (nt && i2bApi_getThermalSensorInfo(ts, sizeof ts) != I2B_API_RET_SUCCESS) nt = 0;
    long warn = (nt && ts[0].WarnThreshold) ? ts[0].WarnThreshold : 90;
    long crit = (nt && ts[0].CritThreshold) ? ts[0].CritThreshold : 100;
    add_property_long(&o, "_tempWarning", warn);
    add_property_long(&o, "_tempCritical", crit);
    add_property_long(&o, "_ctrlTempWarning", warn);
    add_property_long(&o, "_ctrlTempCritical", crit);

    zval temps; array_init(&temps);
    for (unsigned i = 0; i < nt; i++) {
        char loc[20]; field_str(loc, sizeof loc, ts[i].Location, sizeof ts[i].Location);
        zval t; object_init(&t);
        add_property_string(&t, "_location", loc);
        add_property_long(&t, "_temp", ts[i].CurTemp);
        add_property_long(&t, "_healthtemp", ts[i].WarnThreshold ? ts[i].WarnThreshold : warn);
        add_next_index_zval(&temps, &t);
    }
    add_property_zval(&o, "_temps", &temps);
    zval_ptr_dtor(&temps);

    /* cooling: the A3120's 3 tach elements split into 1 CPU FAN (tach 0, "CPU",
     * ~1.6k rpm) and 2 BLOWERS (tach 1/2, "Cooling Unit 1/2", ~4.7k rpm), healthy
     * > 600 RPM - matched to the live NCT7802Y readings. getFanInfo feeds both;
     * showFanFold reads a fan's _fanid/_location/_speed, getBlowerInfo a blower's
     * _blowerid/_location/_speed. */
    zval fans; array_init(&fans);
    zval blowers; array_init(&blowers);
    I2bFanData_t fd[MAXE]; memset(fd, 0, sizeof fd);
    unsigned ntach = (have_cnt && cnt.NumOfFans <= MAXE) ? cnt.NumOfFans : 0;
    if (ntach && i2bApi_getFanInfo(fd, sizeof fd) == I2B_API_RET_SUCCESS) {
        int bnum = 0;
        for (unsigned i = 0; i < ntach; i++) {
            char sts[36]; field_str(sts, sizeof sts, fd[i].FanSts, sizeof fd[i].FanSts);
            zval x; object_init(&x);
            add_property_long(&x, "_status", fd[i].FanStsFlag);
            add_property_string(&x, "_statusStr", sts[0] ? sts : "Functional");
            add_property_long(&x, "_speed", (zend_long)fd[i].FanSpd);
            add_property_long(&x, "_speedThreshold",
                              fd[i].FanThrd ? (zend_long)fd[i].FanThrd : 600);
            if (i == 0) {                    /* tach 0 = the CPU fan */
                add_property_long(&x, "_fanid", 1);
                add_property_string(&x, "_location", "CPU");
                add_property_string(&x, "_psuid", "");
                add_next_index_zval(&fans, &x);
            } else {                         /* tach 1.. = the chassis blowers */
                char cu[8]; snprintf(cu, sizeof cu, "%d", ++bnum);
                add_property_long(&x, "_blowerid", bnum);
                add_property_string(&x, "_location", "Cooling Unit");
                add_property_string(&x, "_psuid", cu);
                add_next_index_zval(&blowers, &x);
            }
        }
    }
    add_property_zval(&o, "_fans", &fans);
    zval_ptr_dtor(&fans);
    add_property_zval(&o, "_blowers", &blowers);
    zval_ptr_dtor(&blowers);

    /* volts: showVoltageFold field names. Rail type + healthy window come from
     * the substrate's golden A3120 rail table; location is "Controller" for
     * all four rails (golden CLU). */
    zval volts; array_init(&volts);
    I2bVoltageSensorData_t vd[MAXE]; memset(vd, 0, sizeof vd);
    unsigned nv = (have_cnt && cnt.NumOfVoltageSensors <= MAXE) ? cnt.NumOfVoltageSensors : 0;
    if (nv && i2bApi_getVoltageSensorInfo(vd, sizeof vd) == I2B_API_RET_SUCCESS) {
        for (unsigned i = 0; i < nv; i++) {
            char typ[12]; field_str(typ, sizeof typ, vd[i].Type, sizeof vd[i].Type);
            char thr[28]; field_str(thr, sizeof thr, vd[i].Threshold, sizeof vd[i].Threshold);
            char vstr[12];
            snprintf(vstr, sizeof vstr, "%u.%uV", vd[i].CurVolt >> 4, vd[i].CurVolt & 0xf);
            zval v; object_init(&v);
            add_property_string(&v, "_location", "Controller");
            add_property_string(&v, "_sensorType", typ);
            add_property_string(&v, "_healthyThreshold", thr);
            add_property_string(&v, "_volt", vstr);
            add_next_index_zval(&volts, &v);
        }
    }
    add_property_zval(&o, "_volts", &volts);
    zval_ptr_dtor(&volts);

    /* PSU: the A3120 has exactly one PSU (golden). The SEP PSU status decode
     * is unrecovered, but a box that answers the identity read above is by
     * definition powered - the golden CLU string for that state is
     * "Powered On and Functional" (showPSUFold reads _psuid/_statusStr;
     * getPSUInfo reads _status, 0 = good). encl.php's A3120 back view keys on
     * count(_psus) == 1. */
    zval psus; array_init(&psus);
    zval psu; object_init(&psu);
    add_property_long(&psu, "_psuid", 1);
    add_property_long(&psu, "_status", 0);
    add_property_string(&psu, "_statusStr", "Powered On and Functional");
    add_next_index_zval(&psus, &psu);
    add_property_zval(&o, "_psus", &psus);
    zval_ptr_dtor(&psus);

    /* _slots: a bay per model-grid slot so front.php/phydrvs.php loop. */
    zval bays; array_init(&bays);
    for (long s = 0; s < slots; s++) {
        zval b; object_init(&b);
        add_property_long(&b, "_slotId", s);
        add_next_index_zval(&bays, &b);
    }
    add_property_zval(&o, "_slots", &bays);
    zval_ptr_dtor(&bays);

    add_next_index_zval(return_value, &o);
}
