/*
 * promise-pam-auth - verify a Linux user's password via PAM for WebPAM login.
 *
 *   promise-pam-auth <username>        password read from stdin (one line)
 *   exit 0 = authenticated, 1 = denied, 2 = usage/bad input
 *
 * php_i2's auth callback execs this for users on the WebPAM PAM allowlist
 * (/etc/promise/webpam.pamusers). It is setuid root because PAM's pam_unix must
 * read /etc/shadow. The plaintext arrives on STDIN - never argv - so it is not
 * visible in the process table, and it is wiped before exit. PAM service is
 * /etc/pam.d/promise-webpam (falls back to "login" if that file is absent).
 *
 * Guard: only login accounts - root (uid 0) or a regular user (uid >= UID_MIN).
 * The service accounts in between (1..999: www-data, daemon, ...) are never login
 * users and are refused, so a compromised www-data can't probe them. The allowlist
 * (fixture_auth) is the primary gate; this is defence in depth. NOTE: root is
 * eligible by request - keep a strong root password (+ pam_faillock if desired),
 * since its password can be verified through this path once root is allowlisted.
 */
#include <security/pam_appl.h>
#include <pwd.h>
#include <unistd.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define UID_MIN 1000
#define PW_MAX  256

static char g_pw[PW_MAX];

/* Answer every echo-off prompt (pam_unix's "Password:") with the stdin secret. */
static int conv_fn(int n, const struct pam_message **msg,
                   struct pam_response **resp, void *data) {
    (void)data;
    if (n <= 0 || n > 32) return PAM_CONV_ERR;
    struct pam_response *r = calloc((size_t)n, sizeof *r);
    if (!r) return PAM_BUF_ERR;
    for (int i = 0; i < n; i++)
        if (msg[i]->msg_style == PAM_PROMPT_ECHO_OFF)
            r[i].resp = strdup(g_pw);           /* PAM frees these */
    *resp = r;
    return PAM_SUCCESS;
}

int main(int argc, char **argv) {
    if (argc != 2) { fprintf(stderr, "usage: promise-pam-auth <username>\n"); return 2; }
    const char *user = argv[1];
    if (!*user || strlen(user) > 32 || strpbrk(user, ":/ \t\r\n")) return 2;

    /* login accounts only: root (uid 0) or regular users (uid >= UID_MIN); the
     * service accounts in between (1..999) are never login users and stay out. */
    struct passwd *pw = getpwnam(user);
    if (!pw || (pw->pw_uid != 0 && pw->pw_uid < UID_MIN)) return 1;

    if (!fgets(g_pw, sizeof g_pw, stdin)) return 2;
    g_pw[strcspn(g_pw, "\r\n")] = 0;
    if (!g_pw[0]) { memset(g_pw, 0, sizeof g_pw); return 1; }   /* no empty passwords */

    struct pam_conv pc = { conv_fn, NULL };
    pam_handle_t *ph = NULL;
    const char *svc = access("/etc/pam.d/promise-webpam", F_OK) == 0
                      ? "promise-webpam" : "login";
    int rc = pam_start(svc, user, &pc, &ph);
    if (rc == PAM_SUCCESS) {
        rc = pam_authenticate(ph, PAM_DISALLOW_NULL_AUTHTOK);
        if (rc == PAM_SUCCESS)
            rc = pam_acct_mgmt(ph, PAM_DISALLOW_NULL_AUTHTOK);  /* not expired/locked */
        pam_end(ph, rc);
    }
    memset(g_pw, 0, sizeof g_pw);
    return rc == PAM_SUCCESS ? 0 : 1;
}
