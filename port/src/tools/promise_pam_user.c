/*
 * promise-pam-user - manage the WebPAM Linux-user allowlist.
 *
 *   promise-pam-user add <username> <groupId>   grant / update access
 *   promise-pam-user del <username>             revoke access
 *   promise-pam-user list                       print "username:groupId" lines
 *
 * Writes /etc/promise/webpam.pamusers (root:www-data 0640). Setuid root - the
 * WebPAM UI runs as www-data and can't write /etc/promise. Only a real regular
 * Linux account (getpwnam, uid >= UID_MIN) may be granted, so an allowlisted
 * user can actually PAM-authenticate and root/system accounts can't be added.
 * groupId is the WebPAM privilege: 1=View 2=Maintenance 3=Power 4=Administrator.
 */
#include <pwd.h>
#include <grp.h>
#include <sys/stat.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#define PWFILE  "/etc/promise/webpam.pamusers"
#define TMPFILE "/etc/promise/webpam.pamusers.tmp"
#define UID_MIN 1000

static int valid_user(const char *u) {
    if (!*u || strlen(u) > 32 || strpbrk(u, ":/ \t\r\n")) return 0;
    struct passwd *pw = getpwnam(u);
    return pw && (pw->pw_uid == 0 || pw->pw_uid >= UID_MIN);  /* root or a regular user */
}

static void fix_perms(void) {
    struct group *g = getgrnam("www-data");
    if (g) { if (chown(PWFILE, 0, g->gr_gid) != 0) {/* best effort */} }
    chmod(PWFILE, 0640);                      /* root:www-data, ext reads via group */
}

/* Copy every line except <user>'s, then (add) append "user:gid". add==0 = delete. */
static int rewrite(const char *user, int add, int gid) {
    FILE *out = fopen(TMPFILE, "w");
    if (!out) { perror("promise-pam-user: open"); return 1; }
    FILE *in = fopen(PWFILE, "r");
    if (in) {
        char line[256]; size_t ulen = strlen(user);
        while (fgets(line, sizeof line, in)) {
            if (strncmp(line, user, ulen) == 0 && line[ulen] == ':') continue;
            fputs(line, out);
        }
        fclose(in);
    }
    if (add) fprintf(out, "%s:%d\n", user, gid);
    if (fclose(out) != 0)         { remove(TMPFILE); return 1; }
    if (rename(TMPFILE, PWFILE))  { perror("promise-pam-user: rename"); remove(TMPFILE); return 1; }
    fix_perms();
    return 0;
}

int main(int argc, char **argv) {
    if (geteuid() != 0) { fprintf(stderr, "promise-pam-user: must run as root\n"); return 1; }

    if (argc == 2 && strcmp(argv[1], "list") == 0) {
        FILE *f = fopen(PWFILE, "r");
        if (f) { char l[256]; while (fgets(l, sizeof l, f)) fputs(l, stdout); fclose(f); }
        return 0;
    }
    if (argc == 4 && strcmp(argv[1], "add") == 0) {
        const char *user = argv[2]; int gid = atoi(argv[3]);
        if (!valid_user(user)) {
            fprintf(stderr, "promise-pam-user: '%s' is not a regular Linux account\n", user); return 2; }
        if (gid < 1 || gid > 4) { fprintf(stderr, "promise-pam-user: groupId must be 1..4\n"); return 2; }
        if (rewrite(user, 1, gid) != 0) return 1;
        printf("promise-pam-user: granted WebPAM access to '%s' (privilege %d)\n", user, gid);
        return 0;
    }
    if (argc == 3 && strcmp(argv[1], "del") == 0) {
        const char *user = argv[2];
        if (!*user || strpbrk(user, ":/ \t\r\n")) return 2;
        if (rewrite(user, 0, 0) != 0) return 1;
        printf("promise-pam-user: revoked WebPAM access for '%s'\n", user);
        return 0;
    }
    fprintf(stderr, "usage: promise-pam-user add <user> <1..4> | del <user> | list\n");
    return 2;
}
