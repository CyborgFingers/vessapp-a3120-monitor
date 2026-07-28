/*
 * emailcfg.h - emaild settings, parsed from emailsetting.xml.
 *
 * The Windows emaild read \emailsetting.xml (getEmailSetting()). Its exact tag
 * schema is not in any shipped artifact (parsed via libprxml on-device), so this
 * is the PORT-DEFINED schema over the fields emaild demonstrably needs: SMTP
 * relay, sender, recipients, and a severity filter. See docs/RE-snmp-daemons.md.
 */
#ifndef EMAILCFG_H
#define EMAILCFG_H

#define EMAIL_MAX_RCPT 16

typedef struct {
    int  enabled;
    char server[128];
    int  port;
    char sender[128];
    char subject_prefix[64];
    int  severity_threshold;              /* send events with severity >= this */
    char rcpt[EMAIL_MAX_RCPT][128];
    int  nrcpt;
} email_cfg;

/* Load + defaults. Returns 0 on success, -1 if the file can't be read. */
int email_cfg_load(const char *path, email_cfg *out);

#endif /* EMAILCFG_H */
