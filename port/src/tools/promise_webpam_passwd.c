/*
 * promise-webpam-passwd - set a WebPAM login password.
 *
 *   promise-webpam-passwd [user] <password>      (default user: administrator)
 *
 * Writes /etc/promise/webpam.passwd ("user:hash" lines, root:www-data 0640).
 * The password is stored as a ONE-WAY hash - libxcrypt crypt() with the system's
 * preferred method (yescrypt on modern Debian), the same scheme /etc/shadow uses -
 * never plaintext. WebPAM (all-TLS) sends the password over HTTPS and the php_i2
 * ext crypt()-verifies it against this hash. Keep it 0640, root-owned.
 */
#include <grp.h>
#include <crypt.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

#define PWFILE  "/etc/promise/webpam.passwd"
#define TMPFILE "/etc/promise/webpam.passwd.tmp"

int main(int argc, char **argv)
{
    const char *user, *pass;
    if (argc == 2)      { user = "administrator"; pass = argv[1]; }
    else if (argc == 3) { user = argv[1];         pass = argv[2]; }
    else { fprintf(stderr, "usage: promise-webpam-passwd [user] <password>\n"); return 2; }

    if (geteuid() != 0) { fprintf(stderr, "promise-webpam-passwd: must run as root\n"); return 1; }
    if (!*user || !*pass || strchr(user, ':') || strpbrk(pass, "\r\n")) {
        fprintf(stderr, "promise-webpam-passwd: invalid user or password\n"); return 2;
    }

    mkdir("/etc/promise", 0755);
    FILE *out = fopen(TMPFILE, "w");
    if (!out) { perror("promise-webpam-passwd: open"); return 1; }

    /* carry over every other user's entry, replace this user's */
    FILE *in = fopen(PWFILE, "r");
    if (in) {
        char line[512]; size_t ulen = strlen(user);
        while (fgets(line, sizeof line, in))
            if (!(strncmp(line, user, ulen) == 0 && line[ulen] == ':'))
                fputs(line, out);
        fclose(in);
    }
    /* Store a one-way hash, never the plaintext. crypt_gensalt(NULL,...) picks the
     * system's strongest method (yescrypt) with a fresh random salt. */
    char *salt = crypt_gensalt(NULL, 0, NULL, 0);
    char *hash = salt ? crypt(pass, salt) : NULL;
    if (!hash || hash[0] == '*' || hash[0] == '\0') {
        fprintf(stderr, "promise-webpam-passwd: crypt() failed\n");
        fclose(out); remove(TMPFILE); return 1;
    }
    fprintf(out, "%s:%s\n", user, hash);
    fclose(out);

    struct group *g = getgrnam("www-data");   /* the ext (php-fpm) must read it */
    if (g) { if (chown(TMPFILE, 0, g->gr_gid) != 0) perror("chown"); }
    chmod(TMPFILE, 0640);
    if (rename(TMPFILE, PWFILE) != 0) { perror("promise-webpam-passwd: rename"); return 1; }

    printf("promise-webpam-passwd: WebPAM password set for '%s'\n", user);
    return 0;
}
