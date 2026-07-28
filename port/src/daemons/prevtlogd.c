/*
 * prevtlogd - engine event-log daemon (Linux port scaffold of prevtlogd.exe).
 *
 * RECOVERED (prevtlogd.exe .rdata): persists the last-processed sequence in
 * prevtlogseq; polls the engine event log (_calculateSeqNum "no new events",
 * logEvents()) and writes each event to the OS event log (Windows EventLog via
 * prevtlogmsg.dll). Single-instance ("Another prevtlogd already running").
 *
 * Linux: poll i2_getEvents -> syslog(3) (+ an event spool that emaild / the SNMP
 * trap emitter consume). The i2_getEvents MicOpCode is UNRESOLVED, so the poll
 * is stubbed (CALIBRATE); the syslog write path is IMPLEMENTED and exercised via
 * `prevtlogd --event <id>` (resolves severity/description from event_EN.xml).
 */
#include "daemon_util.h"
#include "event_map.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <syslog.h>

/* on-device: read events since prevtlogseq via i2_getEvents. CALIBRATE. */
static int poll_engine_stub(uint32_t *out_eid) { (void)out_eid; return -1; }

int main(int argc, char **argv) {
    uint32_t eid = 0; int have = 0;
    for (int i = 1; i < argc; i++)
        if (!strcmp(argv[i], "--event") && i + 1 < argc) { eid = (uint32_t)strtoul(argv[++i], NULL, 0); have = 1; }

    if (daemon_pidlock("prevtlogd") != 0) { fprintf(stderr, "Another prevtlogd already running\n"); return 1; }
    daemon_install_signals();
    openlog("prevtlogd", LOG_PID, LOG_DAEMON);
    syslog(LOG_INFO, "prevtlogd starts");

    if (have) {
        const event_def *ev = event_lookup(eid);
        int pri = (ev && ev->severity >= 5) ? LOG_ERR : LOG_WARNING;
        syslog(pri, "event 0x%x %s severity=%s: %s", eid,
               ev ? ev->name : "UNKNOWN",
               ev ? event_severity_name(ev->severity) : "Unknown",
               ev ? ev->description : "");
        printf("prevtlogd: logged event 0x%x (%s) to syslog\n",
               eid, ev ? ev->name : "UNKNOWN");
    }
    (void)poll_engine_stub;   /* engine poll = CALIBRATE */
    closelog();
    return 0;
}
