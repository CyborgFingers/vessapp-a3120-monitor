/*
 * bgasched - background-activity scheduler (Linux port of bgasched.exe).
 *
 * The vendor bgasched.exe reads per-schedule windows (keyed by power-on count)
 * and fires media-patrol / redundancy-check / rebuild / init at their times.
 * On the Linux/mdadm port the essential background activity is the periodic
 * REDUNDANCY CHECK / MEDIA PATROL: a full "check" scrub that reads every block
 * and verifies parity/mirror, surfacing latent bad sectors and parity
 * mismatches (promise-mdmonitor then raises the event/email/SNMP trap).
 *
 * Driven monthly by bgasched.timer, this triggers `check` on every active md
 * array that is currently idle (skips arrays already resyncing/rebuilding/
 * checking). Runs as root (system service) so it writes sync_action directly.
 *
 * NOTE: the WebPAM schedule.php custom-schedule UI is not wired to persist
 * per-user windows (the engine has no schedule opcodes on this backend); this
 * fixed monthly array scrub is the port's background-activity coverage.
 */
#include "daemon_util.h"

#include <ctype.h>
#include <stdio.h>
#include <string.h>
#include <syslog.h>

/* Arm a redundancy-check scrub on one array, but only if it is idle. */
static int scrub_if_idle(const char *md)
{
    char path[128], state[64] = "";
    snprintf(path, sizeof path, "/sys/block/%s/md/sync_action", md);
    FILE *r = fopen(path, "r");
    if (!r) return 0;                        /* not a redundant md array */
    if (fgets(state, sizeof state, r)) state[strcspn(state, "\r\n")] = '\0';
    fclose(r);
    if (strcmp(state, "idle") != 0) {        /* busy: resync/recover/check/... */
        syslog(LOG_INFO, "%s busy (%s) - skip", md, state);
        printf("bgasched: %s busy (%s) - skip\n", md, state);
        return 0;
    }
    FILE *w = fopen(path, "w");
    if (!w) { syslog(LOG_ERR, "%s: cannot arm check (%m)", md); return 0; }
    fputs("check\n", w);
    if (fclose(w) != 0) { syslog(LOG_ERR, "%s: arm check failed (%m)", md); return 0; }
    syslog(LOG_INFO, "%s: redundancy check (media patrol) started", md);
    printf("bgasched: %s check started\n", md);
    return 1;
}

int main(int argc, char **argv)
{
    (void)argc; (void)argv;                  /* legacy -f <conf> accepted, ignored */

    if (daemon_pidlock("bgasched") != 0) {
        fprintf(stderr, "Another bgasched already running\n");
        return 1;
    }
    openlog("bgasched", LOG_PID, LOG_DAEMON);
    syslog(LOG_INFO, "bgasched starts (scheduled media patrol)");

    /* Active arrays from /proc/mdstat - lines like "md0 : active raid5 ...". */
    FILE *f = fopen("/proc/mdstat", "r");
    int n = 0;
    if (f) {
        char line[512];
        while (fgets(line, sizeof line, f)) {
            if (strncmp(line, "md", 2) != 0) continue;
            char md[32]; size_t i = 0;
            while (line[i] && !isspace((unsigned char)line[i]) && i < sizeof md - 1) {
                md[i] = line[i]; i++;
            }
            md[i] = '\0';
            if (strstr(line, " active")) n += scrub_if_idle(md);
        }
        fclose(f);
    }
    syslog(LOG_INFO, "bgasched: %d array(s) scrubbing", n);
    printf("bgasched: %d array(s) scrubbing\n", n);
    closelog();
    return 0;
}
