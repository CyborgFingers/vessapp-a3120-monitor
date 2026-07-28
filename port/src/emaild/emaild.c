/*
 * emaild.c - Promise event -> SMTP alert daemon (Linux port of clitest/emaild.exe).
 *
 * RECOVERED from emaild.exe (.rdata) + emailutil.c strings:
 *   - startup: "emaild starts" -> i2_initialize + i2_login ("login to i2 client
 *     for emaild"); reads \emailsetting.xml (getEmailSetting()); persists the
 *     last-processed event seq in emailseq.pid.
 *   - trigger: polls the engine event stream (i2_getEvents; same source as
 *     prsnmpagent/prevtlogd) - "emailutil_SendNotification()... get event SeqNo".
 *     Each new event is resolved against event_EN.xml (name/severity/desc) and,
 *     if severity passes the configured filter, a notification is mailed.
 *   - body: the VERBATIM recovered templates (see build_event_body/test).
 *   - send: Windows shelled to mail-go.exe; Linux speaks SMTP directly.
 *
 * This port implements the config + body + SMTP send, and drives it from a
 * fixture event id (or --test) since the engine event-log MicOpCode is not yet
 * recovered (i2_getEvents poll = CALIBRATE, stub in emaild_poll_stub()).
 */
#include "event_map.h"
#include "smtp.h"
#include "emailcfg.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

/* Subsystem identity in the mail body maps to i2_getSubsystemInfo on-device;
 * served as fixture constants here (offsets UNRESOLVED, CALIBRATE). */
#define FIX_ALIAS "Vess A3120"
#define FIX_MODEL "Vess A3120"
#define FIX_WWN   "2000000155a0b1c2"
#define FIX_IP    "127.0.0.1"

static void now_str(char *b, size_t n) {
    time_t t = time(NULL); struct tm tm; localtime_r(&t, &tm);
    strftime(b, n, "%Y/%m/%d %H:%M:%S", &tm);
}

/* RECOVERED event-notification body (emailutil_generateNotifyBodyFixPart). */
static void build_event_body(char *b, size_t n, const char *to, uint32_t eid) {
    const event_def *ev = event_lookup(eid);
    const char *sev  = ev ? event_severity_name(ev->severity) : "Unknown";
    const char *desc = ev ? ev->description : "(unknown event)";
    char ts[32]; now_str(ts, sizeof ts);
    snprintf(b, n,
        "Dear %s:\r\n\r\n"
        "    Please check the following event.\r\n"
        "    alias: %s\r\n"
        "    model: %s\r\n"
        "    eventTime: %s\r\n"
        "    eventID: 0x%xH or %d\r\n"
        "    severity: %s\r\n"
        "    source: %s\r\n"
        "    description: %s\r\n",
        to, FIX_ALIAS, FIX_MODEL, ts, eid, eid, sev, ev ? "Physical Disk" : "-", desc);
}

/* RECOVERED test-mail body ("This is a test email, not a real event ..."). */
static void build_test_body(char *b, size_t n, const char *to) {
    snprintf(b, n,
        "Dear %s:\r\n\r\n"
        "    This is a test email, not a real event notification.\r\n"
        "    WWN: %s\r\n"
        "    IP address: %s\r\n"
        "    Alias: %s\r\n"
        "    Model: %s\r\n",
        to, FIX_WWN, FIX_IP, FIX_ALIAS, FIX_MODEL);
}

/* On-device event poll (i2_getEvents). MicOpCode not yet recovered -> CALIBRATE.
 * Returns -1 (no events) so the daemon loop is a no-op off-device. */
static int emaild_poll_stub(uint32_t *out_eid) { (void)out_eid; return -1; }

static void usage(void) {
    fprintf(stderr,
      "usage: emaild -f emailsetting.xml (--event <id-hex> | --test) [--to addr]\n"
      "  --event 0x000D0002   send a notification for one engine event\n"
      "  --test               send the recovered test email\n");
}

int main(int argc, char **argv) {
    const char *cfgpath = NULL, *to_override = NULL;
    uint32_t eid = 0; int do_test = 0, have_event = 0;

    for (int i = 1; i < argc; i++) {
        if (!strcmp(argv[i], "-f") && i + 1 < argc)          cfgpath = argv[++i];
        else if (!strcmp(argv[i], "--event") && i + 1 < argc){ eid = (uint32_t)strtoul(argv[++i], NULL, 0); have_event = 1; }
        else if (!strcmp(argv[i], "--test"))                 do_test = 1;
        else if (!strcmp(argv[i], "--to") && i + 1 < argc)   to_override = argv[++i];
        else { usage(); return 2; }
    }
    if (!cfgpath || (!have_event && !do_test)) { usage(); return 2; }

    email_cfg cfg;
    if (email_cfg_load(cfgpath, &cfg) != 0) {
        fprintf(stderr, "emaild: getEmailSetting() failed: %s\n", cfgpath);
        return 1;
    }
    if (!cfg.enabled) { fprintf(stderr, "emaild: notifications disabled\n"); return 0; }
    if (cfg.nrcpt == 0) { fprintf(stderr, "emaild: no recipients\n"); return 1; }

    /* severity filter (event mode only) */
    if (have_event) {
        const event_def *ev = event_lookup(eid);
        int sev = ev ? ev->severity : 0;
        if (sev < cfg.severity_threshold) {
            fprintf(stderr, "emaild: event 0x%x severity %d below threshold %d; skipped\n",
                    eid, sev, cfg.severity_threshold);
            return 0;
        }
    }
    (void)emaild_poll_stub;   /* on-device trigger, CALIBRATE */

    const char *to0 = to_override ? to_override : cfg.rcpt[0];
    char subject[160], body[1200];
    if (do_test) {
        snprintf(subject, sizeof subject, "%s Test Email", cfg.subject_prefix);
        build_test_body(body, sizeof body, to0);
    } else {
        const event_def *ev = event_lookup(eid);
        snprintf(subject, sizeof subject, "%s %s (0x%x)",
                 cfg.subject_prefix, ev ? ev->name : "Event", eid);
        build_event_body(body, sizeof body, to0, eid);
    }

    const char *rcpts[EMAIL_MAX_RCPT];
    for (int i = 0; i < cfg.nrcpt; i++) rcpts[i] = cfg.rcpt[i];
    if (smtp_send(cfg.server, cfg.port, cfg.sender, rcpts, cfg.nrcpt,
                  subject, body) != 0) {
        fprintf(stderr, "sendEmailNotification() email failed to send\n");
        return 1;
    }
    fprintf(stderr, "sendEmailNotification() email is sent\n");
    return 0;
}
