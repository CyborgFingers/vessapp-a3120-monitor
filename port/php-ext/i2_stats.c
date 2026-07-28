/*
 * i2_stats.c - WebPAM I/O statistics for ctrlStat / logdrvStat / phydrvStat.php,
 * sourced from the Linux block layer's /proc/diskstats counters.
 *
 * The vendor controller keeps cumulative per-PD / per-LD / per-controller I/O
 * counters; on the mdadm backend we read the kernel's equivalent block-layer
 * counters (accumulated since boot). Request-level *error* counters are not
 * exposed by /proc/diskstats, so the *Errors fields are reported 0
 * (ponytail: Linux has no per-request error counter at this layer - don't
 * fabricate one; SMART surfaces media health separately on phydrvSmart.php).
 *
 * Page contract: getPhyDrvsStatistics($pdid) / getLogDrvsStatistics($ldid) /
 * getControllersStatistics($ctid) each return a ONE-element array [stat-object]
 * (the pages read element [0]); fields carry the spd_ / sld_ / scntr_ prefix.
 * pd/ld ids resolve through the same raid_mdadm_*_osname mapping getPhysicalDrive
 * and getPhysicalDriveSMARTInfo use, so a page shows stats for the drive it names.
 */
#include "php_i2_int.h"
#include "raid_backend.h"

#include <string.h>
#include <stdlib.h>
#include <stdio.h>
#include <time.h>

typedef struct {
    unsigned long long reads, sectors_read, writes, sectors_written, nonrw;
} dstat_t;

/* Parse /proc/diskstats (VESSAPP_DISKSTATS overrides the path for tests) for one
 * block device; 1 if that device's line was found. Field order is the kernel's
 * Documentation/admin-guide/iostats: reads,rd_merged,rd_sectors,rd_ms, writes,
 * wr_merged,wr_sectors,wr_ms, ..., discards(12), ..., flushes(16). */
static int diskstats_read(const char *name, dstat_t *o)
{
    const char *path = getenv("VESSAPP_DISKSTATS");
    FILE *f = fopen(path && *path ? path : "/proc/diskstats", "r");
    if (!f) return 0;
    char line[512];
    int found = 0;
    while (fgets(line, sizeof line, f)) {
        char nm[64];
        unsigned long long v[17] = {0};
        int n = sscanf(line,
            "%*u %*u %63s %llu %llu %llu %llu %llu %llu %llu %llu %llu %llu %llu"
            " %llu %llu %llu %llu %llu %llu",
            nm, &v[0], &v[1], &v[2], &v[3], &v[4], &v[5], &v[6], &v[7], &v[8],
            &v[9], &v[10], &v[11], &v[12], &v[13], &v[14], &v[15], &v[16]);
        if (n < 11 || strcmp(nm, name) != 0) continue;
        o->reads = v[0];  o->sectors_read = v[2];
        o->writes = v[4]; o->sectors_written = v[6];
        o->nonrw = v[11] + v[15];   /* discards + flushes; 0 on older kernels */
        found = 1;
        break;
    }
    fclose(f);
    return found;
}

static void fmt_epoch(time_t t, char *out, size_t cap)
{
    struct tm tm;
    if (localtime_r(&t, &tm)) strftime(out, cap, "%a %b %d %H:%M:%S %Y", &tm);
    else snprintf(out, cap, "n/a");
}

/* diskstats accumulate since boot -> report boot time as the collection start. */
static time_t boot_epoch(void)
{
    double up = 0;
    FILE *f = fopen("/proc/uptime", "r");
    if (f) { if (fscanf(f, "%lf", &up) != 1) up = 0; fclose(f); }
    return time(NULL) - (time_t)up;
}

/* Emit one { <pfx>ReadIOReq, ... } object onto the return array. */
static void emit(zval *arr, const char *pfx, const dstat_t *d)
{
    zval o;
    object_init(&o);
    char k[48];
#define PL(suf, val) do { snprintf(k, sizeof k, "%s%s", pfx, suf); \
                          add_property_long(&o, k, (zend_long)(val)); } while (0)
    PL("ReadIOReq",              d->reads);
    PL("WriteIOReq",             d->writes);
    PL("IOReq",                  d->reads + d->writes);
    PL("ReadBlocksTransferred",  d->sectors_read);
    PL("WriteBlocksTransferred", d->sectors_written);
    PL("BlocksTransferred",      d->sectors_read + d->sectors_written);
    PL("ReadErrors",             0);
    PL("WriteErrors",            0);
    PL("Errors",                 0);
    PL("NonRWReq",               d->nonrw);
    PL("NonRWErrors",            0);
#undef PL
    char ts[40];
    fmt_epoch(boot_epoch(), ts, sizeof ts);
    snprintf(k, sizeof k, "%sstatsStartTime", pfx);
    add_property_string(&o, k, ts);
    fmt_epoch(time(NULL), ts, sizeof ts);
    snprintf(k, sizeof k, "%sstatsCollectionTime", pfx);
    add_property_string(&o, k, ts);
    add_next_index_zval(arr, &o);
}

void i2_stats_phyDrv(zval *return_value, long pd_id)
{
    array_init(return_value);
    char dev[16];
    dstat_t d = {0};
    if (raid_backend() != RAID_MDADM ||
        !raid_mdadm_pd_osname((unsigned short)pd_id, dev, sizeof dev) ||
        !diskstats_read(dev, &d))
        return;
    emit(return_value, "spd_", &d);
}

void i2_stats_logDrv(zval *return_value, long ld_id)
{
    array_init(return_value);
    char dev[16];
    dstat_t d = {0};
    if (raid_backend() != RAID_MDADM ||
        !raid_mdadm_ld_osname((unsigned short)ld_id, dev, sizeof dev) ||
        !diskstats_read(dev, &d))
        return;
    emit(return_value, "sld_", &d);
}

void i2_stats_controller(zval *return_value, long ct_id)
{
    (void)ct_id;   /* single-controller A3120: aggregate every data drive */
    array_init(return_value);
    if (raid_backend() != RAID_MDADM) return;
    dstat_t total = {0};
    char dev[16];
    /* ponytail: one snapshot per drive - a handful of bays, on a stats page. */
    for (unsigned short pid = 1; raid_mdadm_pd_osname(pid, dev, sizeof dev); pid++) {
        dstat_t d = {0};
        if (!diskstats_read(dev, &d)) continue;
        total.reads           += d.reads;
        total.writes          += d.writes;
        total.sectors_read    += d.sectors_read;
        total.sectors_written += d.sectors_written;
        total.nonrw           += d.nonrw;
    }
    emit(return_value, "scntr_", &total);
}

/* perfStatsData.php echoes ->data raw and gates on ->result == PIERR_NO_ERROR(0).
 * The live performance graphs need a time-series sampling collector we don't
 * have; return a valid empty envelope so the page renders without error rather
 * than a graph. */
void i2_stats_perf(zval *return_value)
{
    object_init(return_value);
    add_property_long(return_value, "result", 0);   /* PIERR_NO_ERROR */
    add_property_string(return_value, "data", "");
}
