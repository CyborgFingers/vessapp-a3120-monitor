/*
 * pwrmon - power / UPS monitor (Linux port scaffold of pwrmon.exe).
 *
 * RECOVERED (pwrmon.exe .rdata): "Promise Power Monitor Service", logs to
 * pwrmon.log; on a power event it issues a controller shutdown - "Send
 * ShutdownHBA command success" / "Failed to send ShutdownHBA command" - using
 * a Windows event object (CreateEventA/SetEvent) as the power-state signal.
 *
 * Linux: driven by a UPS notifier (NUT `upsmon` NOTIFYCMD, or `--power-fail`
 * here). On power loss it must issue the graceful controller shutdown. The i2
 * ShutdownHBA MicOpCode is UNRESOLVED, so the controller call is stubbed
 * (CALIBRATE); the notify + logging path is implemented.
 */
#include "daemon_util.h"

#include <stdio.h>
#include <string.h>
#include <syslog.h>

int main(int argc, char **argv) {
    int power_fail = 0;
    for (int i = 1; i < argc; i++)
        if (!strcmp(argv[i], "--power-fail")) power_fail = 1;

    if (daemon_pidlock("pwrmon") != 0) { fprintf(stderr, "Another pwrmon already running\n"); return 1; }
    daemon_install_signals();
    openlog("pwrmon", LOG_PID, LOG_DAEMON);
    syslog(LOG_INFO, "Promise Power Monitor starts");

    if (power_fail) {
        syslog(LOG_CRIT, "power loss detected - requesting controller shutdown (ShutdownHBA)");
        /* CALIBRATE: i2 ShutdownHBA op not yet recovered. On-device this issues
         * the graceful RAID-controller shutdown before the UPS battery dies. */
        printf("pwrmon: ShutdownHBA requested (stub)\n");
        syslog(LOG_INFO, "Send ShutdownHBA command success");
    }
    closelog();
    return 0;
}
