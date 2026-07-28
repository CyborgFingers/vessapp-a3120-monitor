/*
 * promise-svc - privileged systemd control for the WebPAM Software-Management
 * service pages (webserver/ssh/ntp start-stop + startup type).
 *
 *   promise-svc <unit> <start|stop|restart|enable|disable>
 *
 * Installed setuid root, owned root:www-data mode 4750 - the php_i2 ext (php-fpm,
 * www-data) is the only caller. It is the sole privileged path: only the
 * whitelisted management units and verbs are permitted, so a compromised www-data
 * cannot drive arbitrary systemctl. Unit and verb are matched against fixed lists
 * BEFORE exec, and systemctl is called by absolute path with an argv (no shell),
 * so there is no argument- or PATH-injection surface.
 */
#include <stdio.h>
#include <string.h>
#include <unistd.h>

/* Services the WebPAM service pages manage on this port. Email/SNMP are
 * event-driven (config-flag, handled in the ext) and deliberately NOT here. */
static const char *const UNITS[] = { "nginx", "ssh", "systemd-timesyncd", "postfix", NULL };
static const char *const VERBS[] = { "start", "stop", "restart", "enable", "disable", NULL };

static int allowed(const char *v, const char *const *list) {
    for (int i = 0; list[i]; i++) if (strcmp(v, list[i]) == 0) return 1;
    return 0;
}

int main(int argc, char **argv) {
    if (argc != 3) {
        fprintf(stderr, "usage: promise-svc <unit> <start|stop|restart|enable|disable>\n");
        return 2;
    }
    if (!allowed(argv[1], UNITS)) { fprintf(stderr, "promise-svc: unit not permitted: %s\n", argv[1]); return 3; }
    if (!allowed(argv[2], VERBS)) { fprintf(stderr, "promise-svc: verb not permitted: %s\n", argv[2]); return 3; }

    if (setgid(0) != 0 || setuid(0) != 0) {   /* real=effective root (setuid binary) */
        fprintf(stderr, "promise-svc: not privileged (install setuid root)\n");
        return 1;
    }
    char *const args[] = { "/usr/bin/systemctl", (char *)argv[2], (char *)argv[1], NULL };
    execv("/usr/bin/systemctl", args);
    perror("promise-svc: execv /usr/bin/systemctl");
    return 1;
}
