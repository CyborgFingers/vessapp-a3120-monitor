/*
 * php_i2.c - module entry + factory functions for the Linux php_i2 extension.
 *
 * Recreates the three native functions the WebPAM login path calls (recovered in
 * docs/RE-opas.md §1 and confirmed against php_i2.dll: strings "getdynamiccode",
 * "i2client", "i2clientex"):
 *   getdynamiccode()               -> array {err, code, id}   (login challenge)
 *   I2ClientEx($user,$pw,$dcid)    -> I2Client {sessionId,status}  (login)
 *   I2Client($user,"",$sid)        -> I2Client (reconnect to a live session)
 * plus the engine constants the htdocs reference. The read object model lives on
 * the returned I2Client / I2Subsystem objects (i2_client.c / i2_subsystem.c).
 */
#include "php_i2_int.h"
#include "ext/standard/info.h"
#include "i2bdg_api.h"        /* PHYDRV_ / LOGDRV_ / DEVICE_CONFIG_MASK values */

#include <stdlib.h>

/* ---- arginfo -------------------------------------------------------------- */
ZEND_BEGIN_ARG_INFO_EX(arginfo_i2_none, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_i2_client, 0, 0, 0)
    ZEND_ARG_INFO(0, username)
    ZEND_ARG_INFO(0, password)
    ZEND_ARG_INFO(0, sid)
ZEND_END_ARG_INFO()

/* I2ClientEx: as arginfo_i2_client + an optional plaintext (PAM login, HTTPS). */
ZEND_BEGIN_ARG_INFO_EX(arginfo_i2_clientex, 0, 0, 3)
    ZEND_ARG_INFO(0, username)
    ZEND_ARG_INFO(0, password)
    ZEND_ARG_INFO(0, sid)
    ZEND_ARG_INFO(0, plainpw)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_i2_one, 0, 0, 0)
    ZEND_ARG_INFO(0, a)
ZEND_END_ARG_INFO()

/* ---- getLastError(): last engine status of this request ---------------------
 * php_i2.dll string "getlasterror"; 16 htdocs call sites (daemon.php:39,
 * events.php, ...). Pages compare it against PIERR_NO_ERROR /
 * PIERR_INACTIVE_SESSION after an engine call. Per-request (reset in RINIT),
 * mirroring the per-request thread state of the Windows php7ts module. */
#define I2_PIERR_INACTIVE_SESSION 0x1005   /* FIXTURE value, = the K() below */
static zend_long i2_last_error = 0;

PHP_FUNCTION(getLastError)
{
    RETURN_LONG(i2_last_error);
}

/* ---- getdynamiccode(): the login challenge (index.php:230, login.php:19) --- */
PHP_FUNCTION(getdynamiccode)
{
    unsigned id = 0; char code[32] = {0};
    array_init(return_value);
    if (i2_fixture_mgr() && opas_dc_issue(i2_fixture_mgr(), &id, code, sizeof code) == 0) {
        add_assoc_long(return_value, "err", 0);
        add_assoc_string(return_value, "code", code);
        add_assoc_long(return_value, "id", (zend_long)id);
    } else {
        add_assoc_long(return_value, "err", 1);
    }
}

/* Build an I2Client object carrying sessionId + status (+ a nonzero frontendType
 * so tree.php uses getSubsystem($flag) directly). */
static void make_client(zval *out, zend_long sid, zend_long status)
{
    object_init_ex(out, i2_client_ce);
    add_property_long(out, "sessionId", sid);
    add_property_long(out, "status", status);
    /* frontendType: fixture host-interface type (see i2_register_constants). */
    add_property_long(out, "frontendType", 4 /* PI_FB_HOST_INTERFACE_SAS */);
    add_property_string(out, "model", "");
}

/* ---- I2ClientEx($user,$pw,$dcid): verify the challenge, issue a session ----
 * tree.php:144 CSessionService::loginex. $sid arg is the dc id (hn1), NOT a
 * token. On success sessionId = the engine-issued token, status = 0. */
PHP_FUNCTION(I2ClientEx)
{
    char *user = NULL, *pw = NULL, *plain = NULL;
    size_t ul = 0, pl = 0, ppl = 0; zend_long dcid = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ssl|s",
                              &user, &ul, &pw, &pl, &dcid, &plain, &ppl) == FAILURE)
        RETURN_NULL();

    /* Stash the plaintext (POSTed over HTTPS) for the PAM auth hook. Harmless for
     * local users: fixture_auth wipes it and uses the MD5 challenge instead. */
    i2_fixture_set_login_pw(plain ? plain : "");

    unsigned sid = 0;
    int rc = i2_fixture_mgr()
           ? opas_login(i2_fixture_mgr(), (unsigned)dcid, user, pw, &sid)
           : -1;
    /* status 0 == success; nonzero surfaces as a login failure in index.php. */
    make_client(return_value, rc == 0 ? (zend_long)sid : 0, rc == 0 ? 0 : 1);
}

/* ---- I2Client($user,"",$sid): reconnect to an existing session -------------
 * tree.php:157 CSessionService::connect. $sid is a live session token. */
PHP_FUNCTION(I2Client)
{
    char *user = NULL, *pw = NULL; size_t ul = 0, pl = 0; zend_long sid = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ssl", &user, &ul, &pw, &pl, &sid) == FAILURE)
        RETURN_NULL();

    int valid = i2_fixture_mgr() && opas_session_valid(i2_fixture_mgr(), (unsigned)sid);
    if (!valid) i2_last_error = I2_PIERR_INACTIVE_SESSION;
    make_client(return_value, sid, valid ? 0 : 1);
}

/* ---- CClassCtrlType($ctrlType): vendor php_i2.dll global native (the DLL
 * carries the lowercase zend name "cclassctrltype"; encl.php:865 calls it at
 * top level - without it PHP 8 fatals with "undefined function"). It returns
 * the C-class (VR/VJ R-series) controller model-code string; the A3120's
 * 0x80B is not a C-class controller, so the faithful answer here is the empty
 * string (encl.php's substr($s,-2,1)==1 layout tweak stays off). The per-model
 * code table is CALIBRATE - C-class boxes only, never this chassis. */
PHP_FUNCTION(CClassCtrlType)
{
    zend_long t = 0;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &t) == FAILURE)
        RETURN_EMPTY_STRING();
    (void)t;
    RETURN_EMPTY_STRING();
}

/* ---- engine constants ------------------------------------------------------
 * The device-flag masks (PHYDRV_, LOGDRV_, DEVICE_CONFIG_MASK) are RECOVERED
 * (port/include/i2bdg_api.h). The engine enums (USER_GROUP_*, PI_FB_*, PIERR_*,
 * IMEXPORT_*, ENC_TYPE_*, RBD_/BGAS_/SCHEDULE_/ISCSI_VLAN_*) live as numeric
 * literals inside php_i2.dll/pri2apiv4 and are NOT string-recoverable off-device;
 * they are registered here with documented FIXTURE values so the htdocs load
 * without fataling on an undefined constant. Exact values are an on-device
 * CALIBRATE point (they only affect branch selection, not the login path). */
#define K(name, val) REGISTER_LONG_CONSTANT(#name, (val), CONST_CS | CONST_PERSISTENT)
#define KS(name, val) REGISTER_STRING_CONSTANT(#name, (val), CONST_CS | CONST_PERSISTENT)

void i2_register_constants(int module_number)
{
    (void)module_number;
    /* device-flag masks (RECOVERED) */
    K(DEVICE_CONFIG_MASK, DEVICE_CONFIG_MASK);
    K(PHYDRV_STATE_ONLINE, PHYDRV_STATE_ONLINE);
    K(PHYDRV_STATE_MISSING, PHYDRV_STATE_MISSING);
    K(PHYDRV_STATE_REBUILD, PHYDRV_STATE_REBUILD);
    K(PHYDRV_COND_PFA, PHYDRV_COND_PFA);
    K(PHYDRV_COND_FORCED, PHYDRV_COND_FORCED);
    K(PHYDRV_COND_TEMPSPARE, PHYDRV_COND_TEMPSPARE);
    K(PHYDRV_COND_ACCESSIBLE, PHYDRV_COND_ACCESSIBLE);
    K(PHYDRV_OPERATION_MASK, PHYDRV_OPERATION_MASK);
    K(PHYDRV_OPERATION_PDM, PHYDRV_OPERATION_PDM);
    K(PHYDRV_CONFIG_UNCONFIGURED, PHYDRV_CONFIG_UNCONFIGURED);
    K(PHYDRV_CONFIG_CONFIGURED, PHYDRV_CONFIG_CONFIGURED);
    K(PHYDRV_CONFIG_GLOBALSPARE, PHYDRV_CONFIG_GLOBALSPARE);
    K(PHYDRV_CONFIG_DEDICATEDSPARE, PHYDRV_CONFIG_DEDICATEDSPARE);
    K(PHYDRV_CONFIG_STALE, PHYDRV_CONFIG_STALE);
    K(LOGDRV_STATE_ONLINE, LOGDRV_STATE_ONLINE);
    K(LOGDRV_STATE_CRITICAL, LOGDRV_STATE_CRITICAL);
    K(LOGDRV_STATE_DEGRADED, LOGDRV_STATE_DEGRADED);
    K(LOGDRV_COND_SYNCHRONIZED, LOGDRV_COND_SYNCHRONIZED);

    /* privilege levels (vendor values from php_i2.dll: USER < MAINTENANCE <
     * POWER < SUPER). The admin fixture user is SUPER = max (i2_records.c). */
    K(USER_GROUP_USER, 1);
    K(USER_GROUP_MAINTENANCE, 2);
    K(USER_GROUP_POWER, 3);
    K(USER_GROUP_SUPER, 4);

    /* UI limits: sparec.php's head <script> loops spare rows up to MAX_SPARES;
     * an undefined constant is a fatal Error in PHP 8. Vendor value (bounds a
     * DOM scan; the A3120 has 4 bays). */
    K(MAX_SPARES, 256);

    /* Every constant below carries the EXACT value the vendor's php_i2.dll
     * registers (recovered from a3120p-src/.../System32/php_i2.dll by parsing
     * its zend_register_long_constant calls), so the config/display pages branch
     * exactly as WebPAM PROe does - no longer CALIBRATE guesses. */

    /* event-log locations (events.php selects RAM/NVRAM/flash). */
    K(EVENT_LOCATION_RAM, 1);
    K(EVENT_LOCATION_MDD, 2);
    K(EVENT_LOCATION_NVRAM, 4);

    /* controller redundancy type (subsystemt.php enables the DCM checkbox only
     * for ACTIVE_ACTIVE). Single-controller A3120 => ACTIVE_STANDBY. */
    K(CLRT_ACTIVE_ACTIVE, 1);
    K(CLRT_ACTIVE_STANDBY, 2);
    K(CLRT_ACTIVE_ACTIVE_DCM, 3);

    /* PD temperature-threshold settable ranges (controllert.php "[MIN-MAX] C"). */
    K(CS_PD_TEMP_THRESHOLD_MIN, 50);
    K(CS_PD_TEMP_THRESHOLD_MAX, 55);
    K(CS_PD_CRI_TEMP_THRESHOLD_MIN, 58);
    K(CS_PD_CRI_TEMP_THRESHOLD_MAX, 65);

    /* event classes (evtsubscribe.php / event filtering) - vendor enum. */
    K(EVT_CLASS_BATTERY,1); K(EVT_CLASS_BBU,2); K(EVT_CLASS_REDUNDANCY_CHECK,3);
    K(EVT_CLASS_CONTROLLER,4); K(EVT_CLASS_COOLING_DEVICE,5); K(EVT_CLASS_SEP,6);
    K(EVT_CLASS_FRONT_END_CONTROLLER,7); K(EVT_CLASS_INITIALIZATION,8);
    K(EVT_CLASS_LOGICAL_DRIVE,9); K(EVT_CLASS_MDD,10); K(EVT_CLASS_MEDIA_PATROL,11);
    K(EVT_CLASS_RAID_MIGRATION,12); K(EVT_CLASS_PHYSICAL_DISK,13); K(EVT_CLASS_PSU,14);
    K(EVT_CLASS_REBUILD,15); K(EVT_CLASS_SMART,16); K(EVT_CLASS_SUBSYSTEM,17);
    K(EVT_CLASS_SYNCHRONIZATION,18); K(EVT_CLASS_DISK_ARRAY,19);
    K(EVT_CLASS_BACK_END_CONTROLLER,20); K(EVT_CLASS_ONLINE_CAPACITY_EXPANSION,21);
    K(EVT_CLASS_RAID_LEVEL_MIGRATION,22); K(EVT_CLASS_STRIPE_LEVEL_MIGRATION,23);
    K(EVT_CLASS_BLOWER,26); K(EVT_CLASS_PDM,27); K(EVT_CLASS_SPARE_CHECK,29);
    K(EVT_CLASS_TEMPERATURE,30); K(EVT_CLASS_POWER_MANAGEMENT,39);

    /* event severities (== raidv4 scale the event log uses; NONE is a 0xFF sentinel). */
    K(EVT_SEVERITY_NONE,255); K(EVT_SEVERITY_INFORMATION,2); K(EVT_SEVERITY_WARNING,3);
    K(EVT_SEVERITY_MINOR,4); K(EVT_SEVERITY_MAJOR,5); K(EVT_SEVERITY_CRITICAL,6);
    K(EVT_SEVERITY_FATAL,7);

    /* config-import types (import.php), BGA-schedule recurrency/range end
     * (schedule.php), error-table row types (errtable.php). */
    K(IMPORT_TYPE_USERDATABASE,0); K(IMPORT_TYPE_CONFIGURATION,1);
    K(SCHEDULE_RECURRENCY_DAILY,1); K(SCHEDULE_RECURRENCY_WEEKLY,2); K(SCHEDULE_RECURRENCY_MONTHLY,3);
    K(SCHEDULE_RANGE_END_NONE,1); K(SCHEDULE_RANGE_END_COUNT,2); K(SCHEDULE_RANGE_END_DATE,3);
    K(MONTHLY_DAY_PATTERN_DAY,0); K(MONTHLY_DAY_PATTERN_ORDINAL,1);
    K(RCT_ENTRY,1); K(WCT_ENTRY,2); K(IBT_ENTRY,3);   /* errtable.php row types */
    K(EVT_CURRENT_MAX_CLASSES,42);

    /* daemon control (email/snmp/services/slp/webserver config pages).
     * getAllDaemons() returns no daemons on the Linux port, so the daemon-TYPE
     * ids are inert (the vendor registers those as string constants, not longs);
     * the state/starttype values below are the vendor's. */
    K(DAEMON_STOPPED,2); K(DAEMON_STARTED,1); K(DAEMON_RESTART,3);
    K(DAEMON_STARTTYPE_MANUAL,2); K(DAEMON_STARTTYPE_AUTO,1);
    /* Service-name constants: STRINGS matching getAllDaemons' daemonname, so
     * each {service}.php can do `$daemon->daemonname == DAEMON_X` (email.php:31,
     * snmp.php, webserver.php, slp.php). Registering them as longs made those
     * comparisons silently false, so the page acted on the wrong daemon. */
    KS(DAEMON_EMAIL,"Email"); KS(DAEMON_SNMP,"SNMP"); KS(DAEMON_SLP,"SLP");
    KS(DAEMON_WEBSERVER,"Webserver"); KS(DAEMON_TELNET,"Telnet"); KS(DAEMON_SSH,"SSH");
    KS(DAEMON_NETSEND,"Netsend"); KS(DAEMON_NTP,"NTP"); KS(DAEMON_CIM,"CIM");
    K(EMAIL_AUTH_LOGIN,1); K(EMAIL_AUTH_PLAIN,2); K(EMAIL_AUTH_NONE,3); K(EMAIL_AUTH_AUTO,4);
    /* iSCSI SLP flags (slpt.php) - bitflags; the A3120 is DAS-only. */
    K(SLP_FLAGS_USE_DA,1); K(SLP_FLAGS_ENABLE_SA,2); K(SLP_FLAGS_GET_DA_THRU_DHCP,4); K(SLP_FLAGS_ENABLE_SLP,8);
    K(MAX_TRAP_SINKS,32);                             /* snmp.php trap-destination rows */

    /* host interface type (FIXTURE) */
    K(PI_FB_HOST_INTERFACE_SCSI, 1);
    K(PI_FB_HOST_INTERFACE_FC, 2);
    K(PI_FB_HOST_INTERFACE_ISCSI, 3);
    K(PI_FB_HOST_INTERFACE_SAS, 4);
    K(PI_FB_HOST_INTERFACE_NAS, 5);

    /* engine error codes (PIERR_NO_ERROR = 0 is certain; others FIXTURE) */
    K(PIERR_NO_ERROR, 0);
    K(PIERR_INACTIVE_SESSION, I2_PIERR_INACTIVE_SESSION);
    K(PIERR_PHYDRV_NOT_FOUND, 0x1010);

    /* import/export selectors (FIXTURE) */
    K(IMEXPORT_SUBSYSTEM, 0);
    K(IMEXPORT_USERDATABASE, 1);
    K(IMEXPORT_CONFIGSCRIPT, 2);
    K(IMEXPORT_HTTP, 3);

    /* RAID level codes (create wizard: arrayc2/arrayc3/ldcfg.inc). RECOVERED:
     * the values are the I2BAPI_LDRAIDLVL_* codes - ldcfg.inc's own JS
     * literals equal them (RAID50==80/0x50, RAID55==85, RAID60==96/0x60).
     * JBOD/RAID01 appear only in comparisons; distinct FIXTURE values. */
    K(RAID0, 0x00); K(RAID1, 0x01); K(RAID3, 0x03); K(RAID5, 0x05);
    K(RAID6, 0x06); K(RAID10, 0x10); K(RAID30, 0x30); K(RAID50, 0x50);
    K(RAID51, 0x51); K(RAID55, 0x55); K(RAID60, 0x60);
    K(RAID01, 0x0e); K(JBOD, 0x0f);
    /* wizard limits + DA/LD flag bits the create pages reference (display
     * only on this engine; FIXTURE values) */
    K(MAX_LOGDRV_PER_ARRAY, 32);
    K(ARRAY_FLAG_MP_ENABLE, 1);
    K(ARRAY_FLAG_PDM_ENABLE, 2);
    K(LD_NAS_USED, 1);

    /* enclosure type enum. The Vess-family values ARE the chassis model byte:
     * encl.php/front.php compare mpvs_EnclosureType against 0x50/0x52/0x54/
     * 0x55/0x57 literals whose vendor comments name these constants, and the
     * bytes match i2c_map.h ('W' = 0x57 = A3120 1U4BAY, confirmed byte-exact
     * on the golden box - docs/RE-scsi-wire-capture.md). Non-Vess families
     * keep documented FIXTURE values - on this box only inequality vs the
     * Vess bytes matters (exact values CALIBRATE). */
    K(ENC_TYPE_VESS_2U6BAY, 0x50);
    K(ENC_TYPE_VESS_3U16BAY, 0x52);
    K(ENC_TYPE_VESS_2U8BAY, 0x54);
    K(ENC_TYPE_VESS_2U4BAY, 0x55);
    K(ENC_TYPE_VESS_1U4BAY, 0x57);
    K(ENC_TYPE_J300S, 2);
    K(ENC_TYPE_E3X0_2U12BAY, 3);
    K(ENC_TYPE_E3X0_3U16BAY, 4);
    K(ENC_TYPE_VR1000Y_1U4BAY_S, 0x60);   /* encl.php:1076 evaluates it live */
    K(ENC_TYPE_VR1000Y_3U16BAY_S, 0x61);
    K(ENC_TYPE_VR1000Y_3U16BAY_R, 0x62);
    K(CTRL_J300, 0x2000);
    K(CTRL_J310_J610, 0x2001);

    /* controller-family series ids: _isCClassCtrl/_isCClassJbod (common.php:923)
     * compare ($ctrlType >> 8) against these >> 8. The A3120's mpvs_CtrlType
     * 0x80B and ci_ProductID 0x2900 must land in NEITHER series (it is not a
     * C-class box). FIXTURE values, CALIBRATE. */
    K(PT_VR1000_SERIES_SUBSYSTEM, 0x1200);
    K(PT_VJ1000_SERIES_SUBSYSTEM, 0x1300);

    /* FRU locate targets + LED states (locateFRU args; front.php/fru.php
     * showFRULEDs switch arms - evaluated only once getLedFruInfo serves real
     * LED fields). FIXTURE ordering, CALIBRATE. */
    K(FRU_TYPE_MB, 1); K(FRU_TYPE_PSU, 2); K(FRU_TYPE_FAN, 3);
    K(FRU_TYPE_BP, 4); K(FRU_TYPE_SEP, 5); K(FRU_TYPE_DAUGHTERCARD, 6);
    K(FRU_TYPE_BBU, 7);
    K(FRU_LED_STATE_OFF, 0);
    K(FRU_LED_STATE_GREEN, 1);
    K(FRU_LED_STATE_RED, 2);
    K(FRU_LED_STATE_AMBER, 3);
    K(FRU_LED_STATE_GREEN_BLINK, 4);
    K(FRU_LED_STATE_RED_BLINK, 5);
    K(FRU_LED_STATE_AMBER_BLINK, 6);
    K(LCD_PANEL_PRESENT, 1);

    /* background-activity + schedule + rebuild action selectors (FIXTURE) */
    K(BGAS_TYPE_MP, 0); K(BGAS_TYPE_RC, 1); K(BGAS_TYPE_SC, 2); K(BGAS_TYPE_BRC, 3);
    K(RBD_ACTION_RBD, 0); K(RBD_ACTION_PDM, 1); K(RBD_ACTION_TRANSITION, 2);
    K(ISCSI_VLAN_IF_NONE, 0); K(ISCSI_VLAN_IF_TYPE_PHY, 1); K(ISCSI_VLAN_IF_TYPE_TRUNK, 2);
}

/* ---- module wiring -------------------------------------------------------- */
static const zend_function_entry i2_functions[] = {
    PHP_FE(getdynamiccode, arginfo_i2_none)
    PHP_FE(getLastError,   arginfo_i2_none)
    PHP_FE(I2Client,       arginfo_i2_client)
    PHP_FE(I2ClientEx,     arginfo_i2_clientex)
    PHP_FE(CClassCtrlType, arginfo_i2_one)
    PHP_FE_END
};

PHP_MINIT_FUNCTION(i2)
{
    i2_client_register();
    i2_subsystem_register();
    i2_register_constants(module_number);
    if (i2_fixture_minit() != SUCCESS)
        return FAILURE;
    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(i2)
{
    (void)type;
    i2_fixture_mshutdown();
    return SUCCESS;
}

PHP_RINIT_FUNCTION(i2)
{
    (void)type; (void)module_number;
    i2_last_error = 0;   /* getLastError() is per-request state */
    return SUCCESS;
}

PHP_MINFO_FUNCTION(i2)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "Promise i2 (WebPAM) support", "enabled");
    php_info_print_table_row(2, "mode", getenv("I2BDG_SG_DEV") ? "device (/dev/sg*)" : "fixture (mock substrate)");
    php_info_print_table_end();
}

zend_module_entry i2_module_entry = {
    STANDARD_MODULE_HEADER,
    "i2",
    i2_functions,
    PHP_MINIT(i2),
    PHP_MSHUTDOWN(i2),
    PHP_RINIT(i2),
    NULL,               /* RSHUTDOWN*/
    PHP_MINFO(i2),
    "0.4.0-phase4",
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_I2
ZEND_GET_MODULE(i2)
#endif
