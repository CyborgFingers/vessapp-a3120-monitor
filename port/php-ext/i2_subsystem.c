/*
 * i2_subsystem.c - the I2Subsystem native class (php_i2.dll "i2subsystem",
 * cf. zif_i2subsystem_getCurfrontendtype).
 *
 * Returned by I2Client::getSubsystem(). Carries the ssi_* subsystem-summary
 * properties and the read/enumerate methods the htdocs call. The enumerate/get
 * verbs delegate to i2_records.c (substrate -> PHP objects); the small config
 * verbs return the minimal fixture object each page reads. Write/config verbs
 * are cataloged: export() returns a nonzero engine status (UNSUPPORTED), never a
 * faked success (RE-opas.md §4).
 */
#include "php_i2_int.h"

#include "i2bdg_api.h"
#include "raid_backend.h"   /* Phase-2 RAID write path (mdadm backend) */
#include <fcntl.h>          /* buzzer state files (open/unlink) */
#include <sys/stat.h>
#include <unistd.h>
static int buzzer_enabled(void);   /* defined below; getController (above it) uses it */
static int buzzer_sounding(void);

zend_class_entry *i2_subsystem_ce;

ZEND_BEGIN_ARG_INFO_EX(arginfo_ss_none, 0, 0, 0)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_ss_one, 0, 0, 0)
    ZEND_ARG_INFO(0, a)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_ss_two, 0, 0, 0)
    ZEND_ARG_INFO(0, a)
    ZEND_ARG_INFO(0, b)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_ss_three, 0, 0, 0)
    ZEND_ARG_INFO(0, a)
    ZEND_ARG_INFO(0, b)
    ZEND_ARG_INFO(0, c)
ZEND_END_ARG_INFO()
ZEND_BEGIN_ARG_INFO_EX(arginfo_ss_four, 0, 0, 0)
    ZEND_ARG_INFO(0, a)
    ZEND_ARG_INFO(0, b)
    ZEND_ARG_INFO(0, c)
    ZEND_ARG_INFO(0, d)
ZEND_END_ARG_INFO()

/* ---- enumerate / get lists (delegate to i2_records.c) --------------------- */
PHP_METHOD(I2Subsystem, enumeratePhysicalDrives) { i2_records_physicalDrives(return_value); }
PHP_METHOD(I2Subsystem, getPhysicalDrives)       { i2_records_physicalDrives(return_value); }
PHP_METHOD(I2Subsystem, enumerateLogicalDrives)  { i2_records_logicalDrives(return_value); }
PHP_METHOD(I2Subsystem, getLogicalDrives)        { i2_records_logicalDrives(return_value); }
PHP_METHOD(I2Subsystem, enumerateDiskArrays)     { i2_records_diskArrays(return_value); }
PHP_METHOD(I2Subsystem, getDiskArrays)           { i2_records_diskArrays(return_value); }
PHP_METHOD(I2Subsystem, enumerateControllers)    { i2_records_controllers(return_value); }
PHP_METHOD(I2Subsystem, getControllers)          { i2_records_controllers(return_value); }
PHP_METHOD(I2Subsystem, enumerateSpareDrives)    { i2_records_spareDrives(return_value); }
PHP_METHOD(I2Subsystem, getSpareDrives)          { i2_records_spareDrives(return_value); }
PHP_METHOD(I2Subsystem, enumerateUpss)           { array_init(return_value); }
PHP_METHOD(I2Subsystem, getBatteries)            { array_init(return_value); }
PHP_METHOD(I2Subsystem, getLastEvents)                                          /* ($max,$lang) event.php:33 */
{
    zval *a = NULL, *b = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|zz", &a, &b) == FAILURE) RETURN_NULL();
    i2_records_events(return_value, a ? zval_get_long(a) : 0);
}
PHP_METHOD(I2Subsystem, getUsers)                { i2_records_users(return_value); }
PHP_METHOD(I2Subsystem, getiSCSINetInfo)         { i2_records_mgmtNet(return_value); }

/* getEnclosures($id = 0): returns an ARRAY of enclosure records - encls.php:20
 * count()s it, encls.php:73/encl.php:395 read [0], phydrvs.php:24 foreach-es
 * it (tree.php:512 calls it per-enclosure id). */
PHP_METHOD(I2Subsystem, getEnclosures)
{
    zend_long id = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &id) == FAILURE) RETURN_NULL();
    i2_records_enclosure(return_value, id);
}

/* getController($devId): home.php:52, tree2.php CController. */
PHP_METHOD(I2Subsystem, getController)
{
    zend_long dev = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &dev) == FAILURE) RETURN_NULL();
    i2_records_controller(return_value, dev);
    if (Z_TYPE_P(return_value) == IS_OBJECT)   /* buzzer.php status view reads this */
        add_property_long(return_value, "ci_BuzzerEnable", buzzer_enabled());
}

/* Software Management service list (services.php + each {name}.php config tab). */
PHP_METHOD(I2Subsystem, getAllDaemons)        { i2_records_daemons(return_value); }
PHP_METHOD(I2Subsystem, getEmailSetting)      { i2_svc_getEmailSetting(return_value); }
PHP_METHOD(I2Subsystem, setEmailSetting)
{
    zval *o;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "o", &o) == FAILURE) RETURN_LONG(1);
    RETURN_LONG(i2_svc_setEmailSetting(o));
}
PHP_METHOD(I2Subsystem, testEmail)
{
    zval *o = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|o", &o) == FAILURE) RETURN_LONG(1);
    RETURN_LONG(i2_svc_testEmail(o));
}
PHP_METHOD(I2Subsystem, setDaemonStartupType)
{
    zval *o;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "o", &o) == FAILURE) RETURN_LONG(1);
    RETURN_LONG(i2_svc_setDaemonStartupType(o));
}
PHP_METHOD(I2Subsystem, startStopDaemon)
{
    zval *o;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "o", &o) == FAILURE) RETURN_LONG(1);
    RETURN_LONG(i2_svc_startStopDaemon(o));
}
PHP_METHOD(I2Subsystem, getWebserverSetting)  { i2_svc_getWebserverSetting(return_value); }
PHP_METHOD(I2Subsystem, setWebserverSetting)
{
    zval *o;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "o", &o) == FAILURE) RETURN_LONG(1);
    RETURN_LONG(i2_svc_setWebserverSetting(o));
}

/* getDiskArray($id): a single array record (phydrvs.php:43, common.php). */
PHP_METHOD(I2Subsystem, getDiskArray)
{
    zend_long id = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &id) == FAILURE) RETURN_NULL();
    zval all; i2_records_diskArrays(&all);
    /* return the matching record, else an empty stdClass so property reads
     * don't fatal. */
    zval *hit = NULL, *e;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(all), e) {
        zval rv; zval *aid = I2_READ_PROP(NULL, e, "acd_ArrayID", sizeof("acd_ArrayID") - 1, &rv);
        if (aid && zval_get_long(aid) == id) { hit = e; break; }
    } ZEND_HASH_FOREACH_END();
    if (hit) { ZVAL_COPY(return_value, hit); }
    else     { object_init(return_value); }
    zval_ptr_dtor(&all);
}

/* getSpareDrive($arrayId): the spare standing by for that array (spare.php). */
PHP_METHOD(I2Subsystem, getSpareDrive)
{
    zend_long id = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &id) == FAILURE) RETURN_NULL();
    i2_records_spareDrive(return_value, id);
}

/* getPhysicalDriveSMARTInfo($pdId): phydrvSmart.php - SMART health/support/temp
 * read via the setuid smartctl helper (mdadm backend). */
PHP_METHOD(I2Subsystem, getPhysicalDriveSMARTInfo)
{
    zend_long pd = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &pd) == FAILURE) RETURN_NULL();
    i2_records_phySmart(return_value, pd);
}

/* I/O statistics (phydrvStat/logdrvStat/ctrlStat/perfStatsData.php) from
 * /proc/diskstats - see i2_stats.c. Each returns a 1-element array [statObj]. */
PHP_METHOD(I2Subsystem, getPhyDrvsStatistics)
{
    zend_long pd = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &pd) == FAILURE) RETURN_NULL();
    i2_stats_phyDrv(return_value, pd);
}
PHP_METHOD(I2Subsystem, getLogDrvsStatistics)
{
    zend_long ld = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &ld) == FAILURE) RETURN_NULL();
    i2_stats_logDrv(return_value, ld);
}
PHP_METHOD(I2Subsystem, getControllersStatistics)
{
    zend_long ct = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &ct) == FAILURE) RETURN_NULL();
    i2_stats_controller(return_value, ct);
}
PHP_METHOD(I2Subsystem, getPerfStats) { i2_stats_perf(return_value); }

/* Background-activity monitors (bga.php + rebuild/ldinit/ldsync/ldrc/mpatrol):
 * live mdadm sync_action progress. */
PHP_METHOD(I2Subsystem, getRebuilds)          { i2_records_rebuilds(return_value, -1); }
PHP_METHOD(I2Subsystem, getRebuildByLd)
{
    zend_long ld = -1;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &ld) == FAILURE) RETURN_NULL();
    i2_records_rebuilds(return_value, ld);
}
PHP_METHOD(I2Subsystem, getInitializations)   { i2_records_inits(return_value); }
PHP_METHOD(I2Subsystem, getSynchronizations)  { i2_records_syncs(return_value); }
PHP_METHOD(I2Subsystem, getRedundancyChecks)  { i2_records_rcs(return_value); }
PHP_METHOD(I2Subsystem, getArrayMigrates)     { i2_records_migrates(return_value); }
PHP_METHOD(I2Subsystem, getMediaPatrolStatus) { i2_records_mediaPatrol(return_value); }

/* getEvents($location, $count, $firstSeq, $lang) - events.php:71. Reads the
 * promise-mdmonitor RAID event log (P5). */
PHP_METHOD(I2Subsystem, getEvents)
{
    zval *a = NULL, *b = NULL, *c = NULL, *d = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|zzzz", &a, &b, &c, &d) == FAILURE) RETURN_NULL();
    i2_records_events(return_value, b ? zval_get_long(b) : 0);
}

/* getFirstEventSeqNo($location) - events.php:65 (records start at seq 1). */
PHP_METHOD(I2Subsystem, getFirstEventSeqNo)
{
    object_init(return_value);
    add_property_long(return_value, "firstSequenceNo", 0);
}

/* clearEventLog($location) - events.php:42: truncate the RAID event log. */
PHP_METHOD(I2Subsystem, clearEventLog)
{
    FILE *f = fopen("/var/log/promise-raid/events.log", "w");
    if (f) fclose(f);
    RETURN_LONG(0);
}

/* Buzzer (PC-speaker fault alarm) state, shared with promise-buzzer + the
 * promise-mdmonitor fault path via /var/lib/promise-buzzer (www-data-writable).
 * cs_BuzzerEnable mirrors the disabled flag; the WebPAM buzzer page toggles it
 * and mutes a sounding alarm. */
#define BUZZER_DISABLED "/var/lib/promise-buzzer/disabled"
#define BUZZER_MUTE     "/var/lib/promise-buzzer/mute"
#define BUZZER_SOUNDING "/var/lib/promise-buzzer/sounding"
static int buzzer_enabled(void) { struct stat s; return stat(BUZZER_DISABLED, &s) == 0 ? 0 : 1; }
static int buzzer_sounding(void) { struct stat s; return stat(BUZZER_SOUNDING, &s) == 0 ? 1 : 0; }
static void buzzer_touch(const char *p) { int fd = open(p, O_CREAT | O_WRONLY, 0664); if (fd >= 0) close(fd); }

/* getControllerSettings($id): arrays.php:19 reads ->cs_LUNMasking;
 * buzzer.php reads ->cs_BuzzerEnable. */
PHP_METHOD(I2Subsystem, getControllerSettings)
{
    object_init(return_value);
    add_property_long(return_value, "cs_LUNMasking", 0);
    add_property_long(return_value, "cs_BuzzerEnable", buzzer_enabled());
}

/* getBuzzers(): one buzzer on the single controller. buzzer.php loops these
 * (reading ->_ctrlId) and pairs each with getControllerSettings()->cs_BuzzerEnable. */
PHP_METHOD(I2Subsystem, getBuzzers)
{
    array_init(return_value);
    zval o; object_init(&o);
    add_property_long(&o, "_ctrlId", 0);
    add_property_string(&o, "_name", "Buzzer");
    add_property_long(&o, "_enabled", buzzer_enabled());
    add_property_long(&o, "bs_Sounding", buzzer_sounding());   /* mute button shows when sounding */
    add_next_index_zval(return_value, &o);
}

/* setBuzzerEnable($en): 1 clears the disabled flag; 0 sets it + mutes any
 * sounding alarm. Returns 0 on success (buzzer.php treats nonzero as fail). */
PHP_METHOD(I2Subsystem, setBuzzerEnable)
{
    zend_long en = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &en) == FAILURE) RETURN_LONG(1);
    if (en) unlink(BUZZER_DISABLED);
    else { buzzer_touch(BUZZER_DISABLED); buzzer_touch(BUZZER_MUTE); }
    RETURN_LONG(0);
}

/* turnBuzzerOnOff($on): false (WebPAM "buzzer off") mutes a sounding alarm;
 * true clears the mute. Returns 0 on success. */
PHP_METHOD(I2Subsystem, turnBuzzerOnOff)
{
    zend_bool on = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|b", &on) == FAILURE) RETURN_LONG(1);
    if (on) unlink(BUZZER_MUTE);
    else    buzzer_touch(BUZZER_MUTE);
    RETURN_LONG(0);
}

/* supportdualcontroller(): single-controller A3120 fixture. */
PHP_METHOD(I2Subsystem, supportdualcontroller)
{
    object_init(return_value);
    add_property_long(return_value, "DualCtrlSupport", 0);
}

/* getCliqueInfo(): only read on dual-controller boxes; return an empty-ish obj. */
PHP_METHOD(I2Subsystem, getCliqueInfo)
{
    object_init(return_value);
    add_property_string(return_value, "cgi_RedundancyType", "N/A");
    add_property_string(return_value, "cgi_CacheMirroring", "N/A");
    add_property_string(return_value, "cgi_RedundancyStatus", "N/A");
}

/* getDateAndTime(): subsystem.php:104 reads ->_strTime. */
PHP_METHOD(I2Subsystem, getDateAndTime)
{
    char buf[64]; time_t now = time(NULL);
    struct tm tmv; localtime_r(&now, &tmv);
    strftime(buf, sizeof buf, "%a, %b %d, %Y %H:%M:%S", &tmv);
    object_init(return_value);
    add_property_string(return_value, "_strTime", buf);
}

/* getCurrentCtrlId(): common.php reads ->currentCtrlId. */
PHP_METHOD(I2Subsystem, getCurrentCtrlId)
{
    object_init(return_value);
    add_property_long(return_value, "currentCtrlId", 0);
}

/* getCurfrontendtype(): common.php tabTitleNet reads ->curfrontendtype. */
PHP_METHOD(I2Subsystem, getCurfrontendtype)
{
    object_init(return_value);
    add_property_long(return_value, "curfrontendtype", 4 /* SAS */);
}

/* getSubsystemSettings(): stub object. */
PHP_METHOD(I2Subsystem, getSubsystemSettings) { object_init(return_value); }

/* syncsessionvalue($sessId, $sid): common.php SyncSession; no-op (the fixture
 * session store is keyed by the opas token already). */
PHP_METHOD(I2Subsystem, syncsessionvalue) { RETURN_NULL(); }

/* export($opts): cataloged config op -> nonzero engine status (not faked). */
PHP_METHOD(I2Subsystem, export) { RETURN_LONG(0x1006 /* != PIERR_NO_ERROR */); }

/* ==================== RAID write path (Phase 2, mdadm) ====================
 * Only the mdadm/fixture backends implement these; under the default sgio
 * backend each method returns the SAME empty array the __call catch-all
 * produced before (the vendor create payload is CALIBRATE - never sent), so
 * default behaviour is unchanged byte-for-byte. Engine failures return a
 * nonzero status (the pages redirect to their *fail note) and the reason goes
 * to the PHP error log, never into the page. */

static int raid_write_active(void) { return raid_backend() != RAID_SGIO; }

static zend_long obj_long(zval *obj, const char *name, zend_long dflt) {
    if (!obj || Z_TYPE_P(obj) != IS_OBJECT) return dflt;
    zval rv, *p = I2_READ_PROP(NULL, obj, name, strlen(name), &rv);
    if (!p || Z_TYPE_P(p) == IS_NULL) return dflt;
    return zval_get_long(p);
}

static double obj_double(zval *obj, const char *name, double dflt) {
    if (!obj || Z_TYPE_P(obj) != IS_OBJECT) return dflt;
    zval rv, *p = I2_READ_PROP(NULL, obj, name, strlen(name), &rv);
    if (!p || Z_TYPE_P(p) == IS_NULL) return dflt;
    return zval_get_double(p);
}

static void obj_str(zval *obj, const char *name, char *out, size_t cap) {
    if (cap) out[0] = '\0';
    if (!obj || Z_TYPE_P(obj) != IS_OBJECT) return;
    zval rv, *p = I2_READ_PROP(NULL, obj, name, strlen(name), &rv);
    if (p && Z_TYPE_P(p) == IS_STRING) snprintf(out, cap, "%s", Z_STRVAL_P(p));
}

static void raid_log(const char *op, const char *err) {
    char line[384];
    snprintf(line, sizeof line, "php_i2 %s: %s", op, err);
    php_log_err(line);
}

/* createDiskArray($diskArray, $pds, $logicalDrives) - arrayc3.php:55.
 * $pds = pdi_FlatId list, $logicalDrives = rpal_* objects (arrayc2.php). */
PHP_METHOD(I2Subsystem, createDiskArray)
{
    zval *da = NULL, *pds = NULL, *lds = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|zzz", &da, &pds, &lds) == FAILURE)
        RETURN_NULL();
    if (!raid_write_active()) { array_init(return_value); return; }
    if (!pds || Z_TYPE_P(pds) != IS_ARRAY || !lds || Z_TYPE_P(lds) != IS_ARRAY) {
        raid_log("createDiskArray", "malformed request (pds/lds not arrays)");
        RETURN_LONG(1);
    }
    if (zend_hash_num_elements(Z_ARRVAL_P(lds)) != 1) {
        /* v1 model: 1 md device = 1 DA carrying 1 LD (plan doc). */
        raid_log("createDiskArray",
                 "the Linux engine creates exactly one logical drive per array (v1)");
        RETURN_LONG(1);
    }
    unsigned short ids[64]; unsigned nids = 0;
    zval *e;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(pds), e) {
        if (nids < 64) ids[nids++] = (unsigned short)zval_get_long(e);
    } ZEND_HASH_FOREACH_END();

    zval *ld = zend_hash_index_find(Z_ARRVAL_P(lds), 0);
    if (!ld) { ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(lds), e) { ld = e; break; } ZEND_HASH_FOREACH_END(); }
    int level        = (int)obj_long(ld, "rpal_u16RaidLevel", -1);
    unsigned chunkkb = (unsigned)obj_long(ld, "rpal_u32StripeSize", 0);
    unsigned long long cap = (unsigned long long)obj_double(ld, "rpal_u64Size", 0);

    char err[256] = "";
    if (raid_mdadm_create(level, ids, nids, chunkkb, cap, err, sizeof err) != 0) {
        raid_log("createDiskArray", err);
        RETURN_LONG(1);
    }
    RETURN_LONG(0);
}

/* deleteDiskArrays($das) - arrayd.php:31 ($das = acd_ArrayID list). */
PHP_METHOD(I2Subsystem, deleteDiskArrays)
{
    zval *das = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|z", &das) == FAILURE) RETURN_NULL();
    if (!raid_write_active()) { array_init(return_value); return; }
    if (!das || Z_TYPE_P(das) != IS_ARRAY) RETURN_LONG(1);
    zval *e;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(das), e) {
        char err[256] = "";
        if (raid_mdadm_delete((unsigned)zval_get_long(e), err, sizeof err) != 0) {
            raid_log("deleteDiskArrays", err);
            RETURN_LONG(1);
        }
    } ZEND_HASH_FOREACH_END();
    RETURN_LONG(0);
}

/* deleteLogicalDrives($daId, $lds) - logdrvd.php:43. $lds = ldi_FlatId list. An LV
 * LD (LdId & 0x8000) is removed with lvremove and the array stays; a legacy 1-md =
 * 1-LD id stops the whole array (unchanged behaviour for VG-less arrays). */
PHP_METHOD(I2Subsystem, deleteLogicalDrives)
{
    zval *daid = NULL, *lds = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|zz", &daid, &lds) == FAILURE) RETURN_NULL();
    (void)daid;
    if (!raid_write_active()) { array_init(return_value); return; }
    if (!lds || Z_TYPE_P(lds) != IS_ARRAY) RETURN_LONG(1);
    zval *e;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(lds), e) {
        unsigned ldid = (unsigned)zval_get_long(e);
        char err[256] = "";
        int rc = (ldid & 0x8000u)
               ? raid_mdadm_lv_delete((ldid >> 8) & 0x7fu, ldid & 0xffu, err, sizeof err)
               : raid_mdadm_delete(ldid, err, sizeof err);
        if (rc != 0) { raid_log("deleteLogicalDrives", err); RETURN_LONG(1); }
    } ZEND_HASH_FOREACH_END();
    RETURN_LONG(0);
}

/* createSpareDrives($spareDrives) - sparec.php:43. $spareDrives = assoc arrays
 * (rps_u32PdId 1-based, rps_bDedicated, rps_u32ArrayIds). Dedicated -> add the
 * PD as a spare to each named array; Global -> the single array (v1). */
PHP_METHOD(I2Subsystem, createSpareDrives)
{
    zval *spares = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|z", &spares) == FAILURE) RETURN_NULL();
    if (!raid_write_active()) { array_init(return_value); return; }
    if (!spares || Z_TYPE_P(spares) != IS_ARRAY) RETURN_LONG(1);
    zval *e;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(spares), e) {
        if (Z_TYPE_P(e) != IS_ARRAY) continue;
        zval *pd   = zend_hash_str_find(Z_ARRVAL_P(e), "rps_u32PdId", sizeof("rps_u32PdId") - 1);
        zval *ded  = zend_hash_str_find(Z_ARRVAL_P(e), "rps_bDedicated", sizeof("rps_bDedicated") - 1);
        zval *aids = zend_hash_str_find(Z_ARRVAL_P(e), "rps_u32ArrayIds", sizeof("rps_u32ArrayIds") - 1);
        unsigned short pdid = pd ? (unsigned short)zval_get_long(pd) : 0;
        char err[256] = "";
        if (ded && zend_is_true(ded) && aids && Z_TYPE_P(aids) == IS_ARRAY
            && zend_hash_num_elements(Z_ARRVAL_P(aids)) > 0) {
            zval *a;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(aids), a) {
                if (raid_mdadm_add_spare(pdid, (int)zval_get_long(a), err, sizeof err) != 0) {
                    raid_log("createSpareDrives", err); RETURN_LONG(1);
                }
            } ZEND_HASH_FOREACH_END();
        } else if (raid_mdadm_add_spare(pdid, -1, err, sizeof err) != 0) {
            raid_log("createSpareDrives", err); RETURN_LONG(1);
        }
    } ZEND_HASH_FOREACH_END();
    RETURN_LONG(0);
}

/* deleteSpareDrives($sds) - spared.php:39 ($sds = acd_ArrayID list; a spare is
 * removed by the array it stands by for). */
PHP_METHOD(I2Subsystem, deleteSpareDrives)
{
    zval *sds = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|z", &sds) == FAILURE) RETURN_NULL();
    if (!raid_write_active()) { array_init(return_value); return; }
    if (!sds || Z_TYPE_P(sds) != IS_ARRAY) RETURN_LONG(1);
    zval *e;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(sds), e) {
        char err[256] = "";
        if (raid_mdadm_remove_spare((unsigned)zval_get_long(e), err, sizeof err) != 0) {
            raid_log("deleteSpareDrives", err); RETURN_LONG(1);
        }
    } ZEND_HASH_FOREACH_END();
    RETURN_LONG(0);
}

/* migrateDiskArray($daId, $pds, $migrateLDs) - migrate3.php:63. $pds = the target
 * member set (existing + new PD flat ids, 1-based); $migrateLDs[0].rpal_u16RaidLevel
 * is the new level (v1: one LD per array). Reshape via mdadm --add + --grow. */
PHP_METHOD(I2Subsystem, migrateDiskArray)
{
    zval *daid = NULL, *pds = NULL, *lds = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|zzz", &daid, &pds, &lds) == FAILURE) RETURN_NULL();
    if (!raid_write_active()) { array_init(return_value); return; }
    if (!daid || !pds || Z_TYPE_P(pds) != IS_ARRAY) {
        raid_log("migrateDiskArray", "malformed request (no array id / target drives)");
        RETURN_LONG(1);
    }
    unsigned md = (unsigned)zval_get_long(daid);
    unsigned short ids[64]; unsigned nids = 0;
    zval *e;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(pds), e) {
        if (nids < 64) ids[nids++] = (unsigned short)zval_get_long(e);
    } ZEND_HASH_FOREACH_END();
    int new_level = -1;   /* -1 = keep the current level (pure OCE) */
    if (lds && Z_TYPE_P(lds) == IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(lds)) > 0) {
        zval *ld = NULL;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(lds), e) {
            if (Z_TYPE_P(e) != IS_OBJECT) continue;
            if (!ld) ld = e;                       /* first LD -> array-level target level */
            /* LD-level OCE: grow an _oce LVM logical drive to its new size with
             * lvextend, using the VG's free extents (RAID-ENGINE-LVM-LD-PLAN.md). */
            unsigned ldid = (unsigned)obj_long(e, "rpal_u16LdId", 0);
            if ((ldid & 0x8000u) && obj_long(e, "_oce", 0) != 0) {
                unsigned long long bytes = (unsigned long long)obj_double(e, "rpal_u64Size", 0);
                char lerr[256] = "";
                if (raid_mdadm_lv_resize((ldid >> 8) & 0x7fu, ldid & 0xffu, bytes, lerr, sizeof lerr) != 0) {
                    raid_log("migrateDiskArray", lerr); RETURN_LONG(1);
                }
            }
        } ZEND_HASH_FOREACH_END();
        new_level = (int)obj_long(ld, "rpal_u16RaidLevel", -1);
    }
    /* Array-level reshape (add disks / change RAID level) only when disks are
     * actually being added; a pure LVM LD-expand needs no mdadm reshape. */
    if (nids > 0) {
        char err[256] = "";
        if (raid_mdadm_migrate(md, ids, nids, new_level, err, sizeof err) != 0) {
            raid_log("migrateDiskArray", err);
            RETURN_LONG(1);
        }
    }
    RETURN_LONG(0);
}

/* startMediaPatrol() - mpatrol.php:54. Scrub every array (mdadm 'check'). */
PHP_METHOD(I2Subsystem, startMediaPatrol)
{
    if (!raid_write_active()) { array_init(return_value); return; }
    char err[256] = "";
    if (raid_mdadm_media_patrol(err, sizeof err) != 0) {
        raid_log("startMediaPatrol", err);
        RETURN_LONG(1);
    }
    RETURN_LONG(0);
}

/* addLogicalDrives($daId, $lds) - logdrvc2.php:68. Each $lds element is an rpal_*
 * spec; create one LVM LV (rpal_u64Size bytes, named rpal_u8Name) per element in
 * the array's VG, provisioning the VG on the first LD (RAID-ENGINE-LVM-LD-PLAN.md). */
PHP_METHOD(I2Subsystem, addLogicalDrives)
{
    zval *daid = NULL, *lds = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|zz", &daid, &lds) == FAILURE) RETURN_NULL();
    if (!raid_write_active()) { array_init(return_value); return; }
    if (!daid || !lds || Z_TYPE_P(lds) != IS_ARRAY) {
        raid_log("addLogicalDrives", "malformed request (no array id / logical drives)");
        RETURN_LONG(1);
    }
    unsigned md = (unsigned)zval_get_long(daid);
    zval *e;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(lds), e) {
        if (Z_TYPE_P(e) != IS_OBJECT) continue;
        unsigned long long bytes = (unsigned long long)obj_double(e, "rpal_u64Size", 0);
        char name[64]; obj_str(e, "rpal_u8Name", name, sizeof name);
        char err[256] = "";
        if (raid_mdadm_lv_create(md, name, bytes, err, sizeof err) != 0) {
            raid_log("addLogicalDrives", err);
            RETURN_LONG(1);
        }
    } ZEND_HASH_FOREACH_END();
    RETURN_LONG(0);
}

/* getDiskArrayFreeFragments($daId) - logdrvc.php:89 / logdrvc2.php:48. The array's
 * allocatable free capacity as {daff_u64Addr, daff_u64Size} fragments in per-PD
 * units (the pages multiply daff_u64Size by count($pds) for the whole-array size):
 * the VG's free extents once a VG exists, else the whole array. */
PHP_METHOD(I2Subsystem, getDiskArrayFreeFragments)
{
    zval *daid = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|z", &daid) == FAILURE) RETURN_NULL();
    array_init(return_value);
    if (!raid_write_active() || !daid) return;
    raid_frag_t frags[16];
    unsigned n = raid_mdadm_da_free_fragments((unsigned)zval_get_long(daid), frags, 16);
    for (unsigned i = 0; i < n; i++) {
        zval f; object_init(&f);
        add_property_double(&f, "daff_u64Addr", (double)frags[i].addr);
        add_property_double(&f, "daff_u64Size", (double)frags[i].bytes);
        add_next_index_zval(return_value, &f);
    }
}

/* startInitialization($ldid, $quick, $quickSize, $pattern) - ldinitt.php:38.
 * On an md the equivalent of foreground init is the 'repair' scrub. */
PHP_METHOD(I2Subsystem, startInitialization)
{
    zval *id = NULL, *b = NULL, *c = NULL, *d = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|zzzz", &id, &b, &c, &d) == FAILURE)
        RETURN_NULL();
    if (!raid_write_active()) { array_init(return_value); return; }
    char err[256] = "";
    if (!id || raid_mdadm_init_ld((unsigned)zval_get_long(id), 1, err, sizeof err) != 0) {
        raid_log("startInitialization", err);
        RETURN_LONG(1);
    }
    RETURN_LONG(0);
}

PHP_METHOD(I2Subsystem, stopInitialization)
{
    zval *id = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|z", &id) == FAILURE) RETURN_NULL();
    if (!raid_write_active()) { array_init(return_value); return; }
    char err[256] = "";
    if (!id || raid_mdadm_init_ld((unsigned)zval_get_long(id), 0, err, sizeof err) != 0) {
        raid_log("stopInitialization", err);
        RETURN_LONG(1);
    }
    RETURN_LONG(0);
}

/* startRedundancyCheck($ldid, $autoFix, $pauseOnError) - ldrct.php:42.
 * Maps to the md 'check' scrub (autofix/pause are controller concepts). */
PHP_METHOD(I2Subsystem, startRedundancyCheck)
{
    zval *id = NULL, *b = NULL, *c = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|zzz", &id, &b, &c) == FAILURE)
        RETURN_NULL();
    if (!raid_write_active()) { array_init(return_value); return; }
    char err[256] = "";
    if (!id || raid_mdadm_synch_ld((unsigned)zval_get_long(id), 1, err, sizeof err) != 0) {
        raid_log("startRedundancyCheck", err);
        RETURN_LONG(1);
    }
    RETURN_LONG(0);
}

PHP_METHOD(I2Subsystem, stopRedundancyCheck)
{
    zval *id = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|z", &id) == FAILURE) RETURN_NULL();
    if (!raid_write_active()) { array_init(return_value); return; }
    char err[256] = "";
    if (!id || raid_mdadm_synch_ld((unsigned)zval_get_long(id), 0, err, sizeof err) != 0) {
        raid_log("stopRedundancyCheck", err);
        RETURN_LONG(1);
    }
    RETURN_LONG(0);
}

/* autoConfig() / expressConfig(...) - arraya.php. One best-fit array across
 * every unconfigured data PD: >=3 drives -> RAID5, 2 -> RAID1, 1 -> RAID0
 * (chunk/capacity = mdadm defaults). expressConfig's tuning checkboxes are
 * controller concepts; the Linux engine applies the same best-fit. */
static void raid_auto_config(INTERNAL_FUNCTION_PARAMETERS)
{
    if (!raid_write_active()) { array_init(return_value); return; }
    I2bHddInfo_t *hdd = calloc(256, sizeof *hdd);
    if (!hdd) RETURN_LONG(1);
    unsigned short ids[64]; unsigned nids = 0;
    if (i2bApi_getHardDiskDriveInfo(hdd, 256 * sizeof *hdd) == I2B_API_RET_SUCCESS) {
        unsigned n = hdd[0].NumOfPD <= 256 ? hdd[0].NumOfPD : 256;
        for (unsigned i = 0; i < n && nids < 64; i++)
            if ((hdd[i].opStatusFlag & DEVICE_CONFIG_MASK) == PHYDRV_CONFIG_UNCONFIGURED)
                ids[nids++] = hdd[i].PDId;
    }
    free(hdd);
    if (!nids) {
        raid_log("autoConfig", "no unconfigured physical drives");
        RETURN_LONG(1);
    }
    int level = nids >= 3 ? I2BAPI_LDRAIDLVL_RAID5
              : nids == 2 ? I2BAPI_LDRAIDLVL_RAID1 : I2BAPI_LDRAIDLVL_RAID0;
    char err[256] = "";
    if (raid_mdadm_create(level, ids, nids, 0, 0, err, sizeof err) != 0) {
        raid_log("autoConfig", err);
        RETURN_LONG(1);
    }
    RETURN_LONG(0);
}

PHP_METHOD(I2Subsystem, autoConfig)    { raid_auto_config(INTERNAL_FUNCTION_PARAM_PASSTHRU); }
PHP_METHOD(I2Subsystem, expressConfig) { raid_auto_config(INTERNAL_FUNCTION_PARAM_PASSTHRU); }

/* ---- singular getters the create/init/RC pages read ----------------------- */

/* getLogicalDrive($id): the matching LD record as an OBJECT (ldrct.php:31,
 * ldinitt.php:30, logdrv.php) - empty object when not found. */
PHP_METHOD(I2Subsystem, getLogicalDrive)
{
    zend_long id = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &id) == FAILURE) RETURN_NULL();
    zval all; i2_records_logicalDrives(&all);
    zval *hit = NULL, *e;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(all), e) {
        zval rv; zval *v = I2_READ_PROP(NULL, e, "ldi_FlatId", sizeof("ldi_FlatId") - 1, &rv);
        if (v && zval_get_long(v) == id) { hit = e; break; }
    } ZEND_HASH_FOREACH_END();
    if (hit) { ZVAL_COPY(return_value, hit); }
    else     { object_init(return_value); }
    zval_ptr_dtor(&all);
}

/* getPhysicalDrive($id): array holding the matching PD record (array.php:159
 * reads [0]) - empty array when not found. */
PHP_METHOD(I2Subsystem, getPhysicalDrive)
{
    zend_long id = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &id) == FAILURE) RETURN_NULL();
    array_init(return_value);
    zval all; i2_records_physicalDrives(&all);
    zval *e;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(all), e) {
        zval rv; zval *v = I2_READ_PROP(NULL, e, "pdi_FlatId", sizeof("pdi_FlatId") - 1, &rv);
        if (v && zval_get_long(v) == id) {
            Z_TRY_ADDREF_P(e);
            add_next_index_zval(return_value, e);
            break;
        }
    } ZEND_HASH_FOREACH_END();
    zval_ptr_dtor(&all);
}

/* getLunAffinitySupport(): single-controller A3120 - no LUN affinity. */
PHP_METHOD(I2Subsystem, getLunAffinitySupport)
{
    object_init(return_value);
    add_property_long(return_value, "isLunAffinitySupport", 0);
}

/* getMinimumAxle($pdCount, $level): axle floor for the multi-axle levels the
 * wizard offers on bigger chassis (RAID30/50/55/60) - constant 2. */
PHP_METHOD(I2Subsystem, getMinimumAxle)
{
    object_init(return_value);
    add_property_long(return_value, "minAxle", 2);
}

/* __call($name, $args): catch-all for the cataloged config/write methods this
 * phase does not implement (users/network/iSCSI/FC/SAS/services/events/schedules
 * - RE-opas.md §4; array/LD create-delete-init-synch are real methods now,
 * mdadm-backed - see the Phase-2 block above). Returns an EMPTY ARRAY rather than
 * fataling with "undefined method": the htdocs use two call conventions, and on
 * PHP 7.4 an array is benign for both - collection getters (getBuzzers(),
 * daemon.php:80) do count()/[$i] (FATAL on stdClass), while singular getters
 * read ->prop / isset(->prop) (suppressed notice / false on an array).
 * Never fabricates engine data. */
PHP_METHOD(I2Subsystem, __call)
{
    char *name = NULL; size_t nlen = 0; zval *args = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "sz", &name, &nlen, &args) == FAILURE)
        RETURN_NULL();
    array_init(return_value);   /* cataloged/unimplemented -> empty array */
}

/* ---- subsystem summary properties (set at getSubsystem() time) -------------
 * ssi_* are the PHP-visible names read by subsystem.php:88-98. Values are a
 * documented fixture for the A3120 (model is the real product name; serial/WWN/
 * part are placeholders). u32Ret == 0 => the pages proceed instead of redirect. */
void i2_subsystem_fill_props(zval *obj)
{
    add_property_long(obj, "u32Ret", 0);
    add_property_long(obj, "frontendType", 4);
    add_property_long(obj, "sysfrontendType", 4);
    add_property_long(obj, "ssi_JOBExpSSPresent", 0);
    add_property_long(obj, "ssi_MaxJOBExpSSSupported", 0);
    add_property_long(obj, "ssi_NumberOfCtlPresent", 1);
    add_property_string(obj, "ssi_UserName", "VessA3120");
    add_property_string(obj, "ssi_CustName", "Promise Technology,Inc.");
    add_property_string(obj, "ssi_CustModel", "Vess A3120");
    /* Real chassis serial/WWN/part come from DMI, which is root-only, so the
     * www-data ext can't read them directly. install-webpam captures them into
     * promise.env (VESSAPP_SERIAL/WWN/PARTNO); use those when present, else the
     * fixture placeholders. */
    { const char *v = getenv("VESSAPP_SERIAL");
      add_property_string(obj, "ssi_CustSerialNo", (v && *v) ? v : "A3120-FIXTURE-0001"); }
    { const char *v = getenv("VESSAPP_WWN");
      add_property_string(obj, "ssi_WWN", (v && *v) ? v : "2000-0001-5566-0001"); }
    { const char *v = getenv("VESSAPP_PARTNO");
      add_property_string(obj, "ssi_CustPartNo", (v && *v) ? v : ""); }   /* vendor VPD; blank off-device */
    add_property_string(obj, "ssi_CustRev", "A1");
}

static const zend_function_entry i2_subsystem_methods[] = {
    PHP_ME(I2Subsystem, enumeratePhysicalDrives, arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getPhysicalDrives,       arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, enumerateLogicalDrives,  arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getLogicalDrives,        arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, enumerateDiskArrays,     arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getDiskArrays,           arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, enumerateControllers,    arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getControllers,          arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getAllDaemons,           arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getEmailSetting,         arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, setEmailSetting,         arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, testEmail,               arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, setDaemonStartupType,    arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, startStopDaemon,         arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getWebserverSetting,     arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, setWebserverSetting,     arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, enumerateSpareDrives,    arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getSpareDrives,          arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getiSCSINetInfo,         arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getSpareDrive,           arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getEvents,               arginfo_ss_four, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getFirstEventSeqNo,      arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, clearEventLog,           arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, enumerateUpss,           arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getBatteries,            arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getLastEvents,           arginfo_ss_two,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getUsers,                arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getEnclosures,           arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getController,           arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getPhysicalDriveSMARTInfo, arginfo_ss_one, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getPhyDrvsStatistics,    arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getLogDrvsStatistics,    arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getControllersStatistics, arginfo_ss_one, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getPerfStats,            arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getRebuilds,             arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getRebuildByLd,          arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getInitializations,      arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getSynchronizations,     arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getRedundancyChecks,     arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getArrayMigrates,        arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getMediaPatrolStatus,    arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getDiskArray,            arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getControllerSettings,   arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getBuzzers,              arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, setBuzzerEnable,         arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, turnBuzzerOnOff,         arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, supportdualcontroller,   arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getCliqueInfo,           arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getDateAndTime,          arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getCurrentCtrlId,        arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getCurfrontendtype,      arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getSubsystemSettings,    arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, syncsessionvalue,        arginfo_ss_two,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, export,                  arginfo_ss_one,  ZEND_ACC_PUBLIC)
    /* RAID write path (Phase 2) + the singular getters its pages read */
    PHP_ME(I2Subsystem, createDiskArray,         arginfo_ss_three, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, deleteDiskArrays,        arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, deleteLogicalDrives,     arginfo_ss_two,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, createSpareDrives,       arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, deleteSpareDrives,       arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, migrateDiskArray,        arginfo_ss_three, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, startMediaPatrol,        arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, addLogicalDrives,        arginfo_ss_two,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getDiskArrayFreeFragments, arginfo_ss_one, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, startInitialization,     arginfo_ss_three, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, stopInitialization,      arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, startRedundancyCheck,    arginfo_ss_three, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, stopRedundancyCheck,     arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, autoConfig,              arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, expressConfig,           arginfo_ss_three, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getLogicalDrive,         arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getPhysicalDrive,        arginfo_ss_one,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getLunAffinitySupport,   arginfo_ss_none, ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, getMinimumAxle,          arginfo_ss_two,  ZEND_ACC_PUBLIC)
    PHP_ME(I2Subsystem, __call,                  arginfo_ss_two,  ZEND_ACC_PUBLIC)
    PHP_FE_END
};

void i2_subsystem_register(void)
{
    zend_class_entry ce;
    INIT_CLASS_ENTRY(ce, "I2Subsystem", i2_subsystem_methods);
    i2_subsystem_ce = zend_register_internal_class(&ce);
    i2_allow_dynamic_props(i2_subsystem_ce);
}
