/*
 * i2_services.c - Software-Management service pages (services.php + email.php /
 * snmp.php / webserver.php / slp.php). Getters/setters the config pages call:
 *   getEmailSetting()      -> object (SMTP host/port/from/subject/auth ...)
 *   setEmailSetting($o)    -> PIERR   (persist emailsetting.xml)
 *   testEmail($o)          -> PIERR   (emaild --test)
 *   setDaemonStartupType($d)/startStopDaemon($d) -> PIERR (Auto/Manual, start/stop)
 *
 * Email config round-trips /etc/promise/emailsetting.xml (emaild's -f file; see
 * emailsetting.xml.example). emaild speaks PLAIN SMTP to a local relay by design,
 * so encryption/auth are surfaced for the form but do not drive emaild - the
 * relay (postfix) owns AUTH/TLS. Daemon start/stop/enable for the systemd-backed
 * services goes through the setuid promise-svc helper (www-data has no systemctl
 * rights); Email start/stop is the <enabled> flag in the XML.
 */
#include "php_i2_int.h"

#include <string.h>
#include <stdlib.h>
#include <stdio.h>
#include <unistd.h>

#define PIERR_OK      0
#define PIERR_FAIL    1
#define SVC_HELPER    "/opt/promise/bin/promise-svc"
#define EMAILD_BIN    "/opt/promise/bin/emaild"

static const char *email_cfg_path(void) {
    const char *p = getenv("VESSAPP_EMAIL_CFG");
    return (p && *p) ? p : "/etc/promise/emailsetting.xml";
}

/* ---- tiny flat-XML helpers (the file is one flat <emailSetting> element) ---- */
static int xml_tag(const char *buf, const char *tag, char *out, size_t cap) {
    char open[40], close[40];
    snprintf(open, sizeof open, "<%s>", tag);
    snprintf(close, sizeof close, "</%s>", tag);
    const char *a = strstr(buf, open);
    if (!a) { out[0] = 0; return 0; }
    a += strlen(open);
    const char *b = strstr(a, close);
    if (!b) { out[0] = 0; return 0; }
    size_t n = (size_t)(b - a);
    if (n >= cap) n = cap - 1;
    memcpy(out, a, n);
    out[n] = 0;
    return 1;
}

/* dynamic property off a stdClass, or NULL */
static zval *prop(zval *obj, const char *name) {
    if (!obj || Z_TYPE_P(obj) != IS_OBJECT) return NULL;
    return zend_hash_str_find(Z_OBJPROP_P(obj), name, strlen(name));
}
static void prop_str(zval *obj, const char *name, char *out, size_t cap, const char *def) {
    zval *v = prop(obj, name);
    if (v && Z_TYPE_P(v) == IS_STRING)      snprintf(out, cap, "%s", Z_STRVAL_P(v));
    else if (v && Z_TYPE_P(v) == IS_LONG)   snprintf(out, cap, ZEND_LONG_FMT, Z_LVAL_P(v));
    else                                    snprintf(out, cap, "%s", def);
}
static long prop_long(zval *obj, const char *name, long def) {
    zval *v = prop(obj, name);
    if (v && Z_TYPE_P(v) == IS_LONG)   return Z_LVAL_P(v);
    if (v && Z_TYPE_P(v) == IS_STRING) return atol(Z_STRVAL_P(v));
    return def;
}

/* ---- email settings ---------------------------------------------------------- */

/* 1 if the alert-email <enabled> flag is set (also getAllDaemons' Email status). */
int svc_email_enabled(void) {
    FILE *f = fopen(email_cfg_path(), "r");
    if (!f) return 0;
    char buf[4096]; size_t n = fread(buf, 1, sizeof buf - 1, f); buf[n] = 0; fclose(f);
    char v[16]; xml_tag(buf, "enabled", v, sizeof v);
    return atoi(v) != 0;
}

void i2_svc_getEmailSetting(zval *ret) {
    object_init(ret);
    add_property_long(ret, "errorcode", PIERR_OK);
    /* defaults match emailsetting.xml.example (local relay) */
    char server[128] = "127.0.0.1", port[8] = "25", sender[160] = "",
         subject[160] = "[VessApp A3120]", enabled[8] = "1", sev[8] = "2", rcpt[256] = "";
    FILE *f = fopen(email_cfg_path(), "r");
    if (f) {
        char buf[4096]; size_t n = fread(buf, 1, sizeof buf - 1, f); buf[n] = 0; fclose(f);
        char v[256];
        if (xml_tag(buf, "server", v, sizeof v))            snprintf(server, sizeof server, "%s", v);
        if (xml_tag(buf, "port", v, sizeof v))              snprintf(port, sizeof port, "%s", v);
        if (xml_tag(buf, "sender", v, sizeof v))            snprintf(sender, sizeof sender, "%s", v);
        if (xml_tag(buf, "subjectPrefix", v, sizeof v))     snprintf(subject, sizeof subject, "%s", v);
        if (xml_tag(buf, "enabled", v, sizeof v))           snprintf(enabled, sizeof enabled, "%s", v);
        if (xml_tag(buf, "severityThreshold", v, sizeof v)) snprintf(sev, sizeof sev, "%s", v);
        if (xml_tag(buf, "recipient", v, sizeof v))         snprintf(rcpt, sizeof rcpt, "%s", v);
    }
    add_property_string(ret, "smtphost", server);
    add_property_string(ret, "smtpport", port);
    add_property_string(ret, "from", sender);
    add_property_string(ret, "subject", subject);
    /* emaild uses a plain-SMTP local relay; the relay owns AUTH/TLS */
    add_property_long(ret, "encryption", 0);
    add_property_long(ret, "authmethod", 3 /* EMAIL_AUTH_NONE */);
    add_property_string(ret, "username", "");
    add_property_string(ret, "password", "");
    /* preserved across setEmailSetting (not shown on the form) */
    add_property_long(ret, "enabled", atol(enabled));
    add_property_long(ret, "severityThreshold", atol(sev));
    add_property_string(ret, "recipient", rcpt);
}

long i2_svc_setEmailSetting(zval *obj) {
    char server[128], port[16], sender[160], subject[160], rcpt[256];
    long enabled = prop_long(obj, "enabled", 1);
    long sev     = prop_long(obj, "severityThreshold", 2);
    prop_str(obj, "smtphost", server, sizeof server, "127.0.0.1");
    prop_str(obj, "smtpport", port, sizeof port, "25");
    prop_str(obj, "from", sender, sizeof sender, "");
    prop_str(obj, "subject", subject, sizeof subject, "[VessApp A3120]");
    prop_str(obj, "recipient", rcpt, sizeof rcpt, "");

    char tmp[300]; snprintf(tmp, sizeof tmp, "%s.tmp", email_cfg_path());
    FILE *f = fopen(tmp, "w");
    if (!f) return PIERR_FAIL;
    fprintf(f,
        "<emailSetting>\n"
        "  <enabled>%ld</enabled>\n"
        "  <server>%s</server>\n"
        "  <port>%s</port>\n"
        "  <sender>%s</sender>\n"
        "  <subjectPrefix>%s</subjectPrefix>\n"
        "  <severityThreshold>%ld</severityThreshold>\n"
        "  <recipient>%s</recipient>\n"
        "</emailSetting>\n",
        enabled, server, port, sender, subject, sev, rcpt);
    fclose(f);
    return rename(tmp, email_cfg_path()) == 0 ? PIERR_OK : PIERR_FAIL;
}

/* emaild -f <cfg> --test  (emaild sends to the XML recipient) */
long i2_svc_testEmail(zval *obj) {
    (void)obj;   /* recipient comes from the persisted config, not the request */
    char cmd[600];
    snprintf(cmd, sizeof cmd, "%s -f '%s' --test", EMAILD_BIN, email_cfg_path());
    return (system(cmd) == 0) ? PIERR_OK : PIERR_FAIL;
}

/* ---- webserver settings ------------------------------------------------------
 * Session timeout persists + applies (webserver.php also sets $_SESSION['timeout']
 * on save). The HTTP/HTTPS ports are shown read-only at their real values - auto-
 * reconfiguring nginx's listen port would risk locking the operator out of WebPAM,
 * so a port change is deliberately not applied (persist-safe). */
static const char *webserver_cfg_path(void) {
    const char *p = getenv("VESSAPP_WEBSERVER_CFG");
    return (p && *p) ? p : "/etc/promise/webserver.conf";
}
static long webserver_timeout(void) {
    FILE *f = fopen(webserver_cfg_path(), "r");
    if (!f) return 3600;
    char line[64]; long t = 3600, v;
    while (fgets(line, sizeof line, f)) if (sscanf(line, "sessiontimeout=%ld", &v) == 1) t = v;
    fclose(f);
    return t;
}

void i2_svc_getWebserverSetting(zval *ret) {
    object_init(ret);
    add_property_long(ret, "errorcode", PIERR_OK);
    const char *hp = getenv("VESSAPP_HTTP_PORT");
    add_property_long(ret, "httpport", atol((hp && *hp) ? hp : "8088"));
    add_property_long(ret, "httpsport", 443);        /* nominal; this port is HTTP-only */
    add_property_long(ret, "sessiontimeout", webserver_timeout());
    add_property_long(ret, "sslenabled", 0);
}

long i2_svc_setWebserverSetting(zval *obj) {
    long timeout = prop_long(obj, "sessiontimeout", 3600);
    if (timeout < 60)    timeout = 60;         /* webserver.php validates 1..1440 min */
    if (timeout > 86400) timeout = 86400;
    char tmp[300]; snprintf(tmp, sizeof tmp, "%s.tmp", webserver_cfg_path());
    FILE *f = fopen(tmp, "w");
    if (!f) return PIERR_FAIL;
    fprintf(f, "sessiontimeout=%ld\n", timeout);   /* port changes are intentionally not applied */
    fclose(f);
    return rename(tmp, webserver_cfg_path()) == 0 ? PIERR_OK : PIERR_FAIL;
}

/* ---- daemon start/stop/startup ---------------------------------------------- */

/* systemd unit for a WebPAM service name, or NULL for the config-flag/inert ones */
static const char *svc_unit(const char *name) {
    if (!strcmp(name, "Webserver")) return "nginx";
    if (!strcmp(name, "SSH"))       return "ssh";
    if (!strcmp(name, "NTP"))       return "systemd-timesyncd";
    return NULL;   /* Email/SNMP = config flag; SLP/Telnet/Netsend = not provided */
}
static long svc_ctl(const char *unit, const char *verb) {
    char cmd[160];
    snprintf(cmd, sizeof cmd, "%s %s %s", SVC_HELPER, unit, verb);
    return (system(cmd) == 0) ? PIERR_OK : PIERR_FAIL;
}

/* rewrite emailsetting.xml with a new <enabled> flag, preserving the rest */
static long email_set_enabled(int on) {
    zval cur;
    i2_svc_getEmailSetting(&cur);
    /* overwrite the enabled property, then persist */
    add_property_long(&cur, "enabled", on ? 1 : 0);
    long rc = i2_svc_setEmailSetting(&cur);
    zval_ptr_dtor(&cur);
    return rc;
}

long i2_svc_setDaemonStartupType(zval *daemon) {
    char name[32]; prop_str(daemon, "daemonname", name, sizeof name, "");
    long type = prop_long(daemon, "startuptype", 1);   /* 1 AUTO, 2 MANUAL */
    const char *unit = svc_unit(name);
    if (unit) return svc_ctl(unit, type == 1 ? "enable" : "disable");
    if (!strcmp(name, "Email")) return email_set_enabled(type == 1);
    return PIERR_OK;   /* SNMP/SLP/etc: nothing to persist yet */
}

long i2_svc_startStopDaemon(zval *daemon) {
    char name[32]; prop_str(daemon, "daemonname", name, sizeof name, "");
    long st = prop_long(daemon, "daemonstatus", 1);    /* 1 STARTED, 2 STOPPED, 3 RESTART */
    const char *unit = svc_unit(name);
    if (unit) return svc_ctl(unit, st == 2 ? "stop" : (st == 3 ? "restart" : "start"));
    if (!strcmp(name, "Email")) return email_set_enabled(st != 2);
    return PIERR_OK;
}
