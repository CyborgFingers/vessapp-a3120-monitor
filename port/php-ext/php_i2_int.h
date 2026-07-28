/*
 * php_i2_int.h - shared internals for the Linux php_i2 extension (Phase 4).
 *
 * The extension recreates the Windows php_i2.dll native surface the WebPAM
 * htdocs call: two classes (I2Client, I2Subsystem), three factory functions
 * (getdynamiccode, I2Client, I2ClientEx), and the engine constants. Field names
 * follow the PHP-visible names recovered in docs/RE-opas.md / RE-php_i2.md.
 */
#ifndef PHP_I2_INT_H
#define PHP_I2_INT_H

#include "php.h"
#include "opas_session.h"

/* Class entries (defined in i2_client.c / i2_subsystem.c, registered at MINIT). */
extern zend_class_entry *i2_client_ce;
extern zend_class_entry *i2_subsystem_ce;

/* ---- PHP 7.4 <-> 8.x source compatibility ---------------------------------
 * The shipped appliance ran PHP 7.3 (php7ts.dll) and the htdocs use PHP4-style
 * (method-name) constructors, so the web tier runs UNCHANGED on PHP 7.4. The
 * extension also builds on 8.x (the container load-check). Two seams differ:
 *   - zend_read_property() takes zval* (7.x) vs zend_object* (8.0+).
 *   - ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES exists only on 8.2+ (7.x/8.0/8.1 allow
 *     dynamic properties on internal-class objects by default). */
#if PHP_VERSION_ID >= 80000
#  define I2_READ_PROP(scope, zv, name, len, rv) \
     zend_read_property((scope), Z_OBJ_P(zv), (name), (len), 1, (rv))
#else
#  define I2_READ_PROP(scope, zv, name, len, rv) \
     zend_read_property((scope), (zv), (name), (len), 1, (rv))
#endif

static zend_always_inline void i2_allow_dynamic_props(zend_class_entry *ce) {
#ifdef ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES
    ce->ce_flags |= ZEND_ACC_ALLOW_DYNAMIC_PROPERTIES;
#else
    (void)ce;
#endif
}

/* ---- fixture / session substrate (i2_fixture.c) ---- */

/* Process-global session manager (dc + token envelope, Phase-3 opas_session).
 * NULL until i2_fixture_minit() runs. */
opas_session_mgr *i2_fixture_mgr(void);
/* Stash the login plaintext (I2ClientEx, over HTTPS) for the PAM auth hook to
 * consume; wiped the instant fixture_auth reads it. "" clears it. */
void i2_fixture_set_login_pw(const char *pw);

/* One-time module init: create the session mgr, install the fixture auth hook,
 * and (off-device) seed + inject the mock SCSI transport so i2bApi_* returns
 * canned pages with no hardware. Real deployments set I2BDG_SG_DEV instead and
 * the same i2bApi_* calls drive /dev/sg*. Returns SUCCESS/FAILURE. */
int  i2_fixture_minit(void);
void i2_fixture_mshutdown(void);

/* Registration entry points (called from MINIT). */
void i2_client_register(void);
void i2_subsystem_register(void);
void i2_register_constants(int module_number);

/* ---- record marshalling (i2_records.c): substrate struct -> PHP object ----
 * Each fills *return_value with the shape the corresponding htdocs page reads.
 * On a substrate error they still return a (possibly empty) array so the PHP
 * loops run without fataling; the engine status rides on the object where the
 * page checks it. */
void i2_records_physicalDrives(zval *return_value);
void i2_records_logicalDrives(zval *return_value);
void i2_records_mgmtNet(zval *return_value);
void i2_records_phySmart(zval *return_value, long pd_id);
/* I/O statistics from /proc/diskstats (i2_stats.c) - ctrl/logdrv/phydrvStat.php. */
void i2_stats_phyDrv(zval *return_value, long pd_id);
void i2_stats_logDrv(zval *return_value, long ld_id);
void i2_stats_controller(zval *return_value, long ct_id);
void i2_stats_perf(zval *return_value);
void i2_records_rebuilds(zval *return_value, long ld_filter);
void i2_records_inits(zval *return_value);
void i2_records_syncs(zval *return_value);
void i2_records_rcs(zval *return_value);
void i2_records_migrates(zval *return_value);
void i2_records_mediaPatrol(zval *return_value);
void i2_records_diskArrays(zval *return_value);
void i2_records_controllers(zval *return_value);
void i2_records_spareDrives(zval *return_value);
void i2_records_spareDrive(zval *return_value, long array_id);
void i2_records_events(zval *return_value, long max);
void i2_records_enclosure(zval *return_value, long enc_id);
void i2_records_controller(zval *return_value, long dev_id);
void i2_records_daemons(zval *return_value);

/* i2_services.c - Software-Management service config pages */
void i2_svc_getEmailSetting(zval *ret);
long i2_svc_setEmailSetting(zval *obj);
long i2_svc_testEmail(zval *obj);
long i2_svc_setDaemonStartupType(zval *daemon);
long i2_svc_startStopDaemon(zval *daemon);
int  svc_email_enabled(void);           /* also getAllDaemons' Email status */
void i2_svc_getWebserverSetting(zval *ret);
long i2_svc_setWebserverSetting(zval *obj);
void i2_records_users(zval *return_value);

/* Populate the ssi_* subsystem summary properties on an I2Subsystem object. */
void i2_subsystem_fill_props(zval *obj);

#endif /* PHP_I2_INT_H */
