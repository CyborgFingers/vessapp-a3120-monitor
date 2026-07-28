/*
 * i2_fixture.c - process-global session envelope + fixture substrate for the
 * php_i2 extension (Phase 4).
 *
 * MINIT wires the Phase-3 session manager (getdynamiccode / login / logout) and,
 * off-device, seeds a mock SCSI transport with the same canned pages the opasd
 * dispatch test uses (docs/RE-opas.md §3) and injects it into the process-global
 * i2bdg session. So i2bApi_* / i2_fw_cmd return seeded enclosure + RAID data with
 * no hardware. On a real A3120 set I2BDG_SG_DEV and the identical calls hit
 * /dev/sg* instead (the mock is simply not injected).
 */
#include "php_i2_int.h"

#include "encl_backend.h"     /* skip the mock when a real backend is selected */
#include "scsi_transport.h"
#include "i2bdg_internal.h"   /* i2bdg_session_set_transport */
#include "i2bdg_api.h"        /* I2BAPI_DRV_TYPE_SAS, PHYDRV_ / LOGDRV_ flags */
#include "i2_micops.h"        /* I2_MICOP_* */
#include "i2c_map.h"          /* I2C_MAP_ENC_* seed offsets */
#include "i2_raid.h"          /* I2R_* record offsets */

#include <crypt.h>              /* crypt_r + struct crypt_data: verify local password */

#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include <unistd.h>            /* fork/exec/pipe: PAM verify via promise-pam-auth */
#include <sys/wait.h>

static opas_session_mgr *g_mgr;
static scsi_mock        *g_mock;

opas_session_mgr *i2_fixture_mgr(void) { return g_mgr; }

/* ---- auth hook (RE-opas.md §1.4) --------------------------------------------
 * The client sends md5 = MD5(MD5(MD5(code)+pw)+user) (js/md5.js, index.php:124).
 * That protects the password in transit but the server must know the plaintext
 * to recompute it, so the real check reads it from /etc/promise/webpam.passwd
 * ("user:password" lines, root:www-data 0640; set with promise-webpam-passwd).
 * When a password is stored we ENFORCE the chain. When none is: on a real
 * backend (VESSAPP_BACKEND=smbus/mmio) we FAIL SECURE; off-device (mock/fixture)
 * we keep the any-password fixture so the ctests + `php -S` dev flow still work. */
#define WEBPAM_PASSWD "/etc/promise/webpam.passwd"

/* Linux-credential login: users on the allowlist (webpam.pamusers "user:groupId")
 * authenticate against PAM via the setuid promise-pam-auth helper. The plaintext
 * is threaded here from I2ClientEx (the login POST, sent over HTTPS) through a
 * request-scoped stash - each php-fpm worker serves ONE request at a time and the
 * stash is wiped the instant fixture_auth consumes it, so it never crosses
 * requests. Local users stay on the MD5 challenge and their plaintext is unused. */
#define PAMUSERS     "/etc/promise/webpam.pamusers"
#define PAM_AUTH_BIN "/opt/promise/bin/promise-pam-auth"

static char g_login_pw[128];
void i2_fixture_set_login_pw(const char *pw) {
    snprintf(g_login_pw, sizeof g_login_pw, "%s", pw ? pw : "");
}

/* WebPAM groupId (1..4) for an allowlisted PAM user, else 0 (not on the list). */
static int pam_allowlist_priv(const char *user) {
    FILE *f = fopen(PAMUSERS, "r");
    if (!f) return 0;
    char line[256]; size_t ulen = strlen(user); int gid = 0;
    while (fgets(line, sizeof line, f)) {
        line[strcspn(line, "\r\n")] = 0;
        if (strncmp(line, user, ulen) == 0 && line[ulen] == ':') { gid = atoi(line + ulen + 1); break; }
    }
    fclose(f);
    return (gid >= 1 && gid <= 4) ? gid : 0;
}

/* Verify `plain` for `user` through the setuid PAM helper (password on its
 * stdin). 1 = authenticated. The helper itself refuses uid<1000, so a PAM login
 * can never reach root/system accounts even if one were wrongly allowlisted. */
static int pam_verify(const char *user, const char *plain) {
    if (!plain || !*plain) return 0;
    int pfd[2];
    if (pipe(pfd) != 0) return 0;
    pid_t pid = fork();
    if (pid < 0) { close(pfd[0]); close(pfd[1]); return 0; }
    if (pid == 0) {                                     /* child: stdin <- pipe */
        dup2(pfd[0], STDIN_FILENO);
        close(pfd[0]); close(pfd[1]);
        execl(PAM_AUTH_BIN, "promise-pam-auth", user, (char *)NULL);
        _exit(127);
    }
    close(pfd[0]);
    size_t n = strlen(plain);
    if (write(pfd[1], plain, n) != (ssize_t)n || write(pfd[1], "\n", 1) != 1) {
        /* best effort: the child fails auth on a short read */
    }
    close(pfd[1]);
    int st = 0;
    if (waitpid(pid, &st, 0) < 0) return 0;
    return WIFEXITED(st) && WEXITSTATUS(st) == 0;
}

/* Copy the stored password hash for `user` into out[cap]; 1 if found. */
static int webpam_password(const char *user, char *out, size_t cap) {
    FILE *f = fopen(WEBPAM_PASSWD, "r");
    if (!f) return 0;
    char line[256]; size_t ulen = strlen(user); int found = 0;
    while (fgets(line, sizeof line, f)) {
        line[strcspn(line, "\r\n")] = 0;
        if (strncmp(line, user, ulen) == 0 && line[ulen] == ':') {
            snprintf(out, cap, "%s", line + ulen + 1);
            found = 1; break;
        }
    }
    fclose(f);
    return found;
}

static int fixture_auth(const char *code, const char *user,
                        const char *md5, void *ctx) {
    (void)ctx; (void)code; (void)md5;   /* MD5 challenge retired: all-TLS + hashed store */
    int result;

    /* Linux/PAM allowlist users: verify the plaintext (POSTed over HTTPS) via PAM.
     * Their WebPAM privilege comes from the allowlist (PHP getUserPrivilege), so
     * here we only authenticate. Checked BEFORE the local path so a name that is
     * both allowlisted and in webpam.passwd uses PAM. */
    if (pam_allowlist_priv(user)) {
        result = pam_verify(user, g_login_pw);
    } else {
        char stored[256];
        if (webpam_password(user, stored, sizeof stored)) {
            /* Verify the TLS-delivered plaintext against the stored value. Normal
             * installs store a one-way crypt() hash ('$' prefix - yescrypt/bcrypt,
             * the /etc/shadow scheme); a legacy plaintext entry is compared directly
             * (a migration bridge until re-set with promise-webpam-passwd). The
             * password always arrives over HTTPS - the MD5 challenge is gone. */
            if (stored[0] == '$') {
                struct crypt_data cd; memset(&cd, 0, sizeof cd);
                char *h = crypt_r(g_login_pw, stored, &cd);
                result = (h && strcmp(h, stored) == 0);
            } else {
                result = (g_login_pw[0] && strcmp(g_login_pw, stored) == 0);
            }
        } else if (encl_backend() != ENCL_SCSI) {
            result = 0;                          /* on-device, no password: fail secure */
        } else {
            const char *want = getenv("I2_FIXTURE_USER"); /* dev/fixture: any password */
            if (!want || !*want) want = "administrator";
            result = strcmp(user, want) == 0;
        }
    }
    memset(g_login_pw, 0, sizeof g_login_pw);    /* always wipe the request secret */
    return result;
}

/* little-endian pokes (mirror tests/test_opas.c) */
static void pl16(unsigned char *p, unsigned o, unsigned v){ p[o]=v&0xff; p[o+1]=(v>>8)&0xff; }
static void pl32(unsigned char *p, unsigned o, unsigned v){ pl16(p,o,v&0xffff); pl16(p,o+2,v>>16); }
static void pl64(unsigned char *p, unsigned o, unsigned long long v){ pl32(p,o,(unsigned)v); pl32(p,o+4,(unsigned)(v>>32)); }

/* Enclosure info (0x000F) + status (0x010F): golden A3120 1U/4-bay head unit
 * (CALIBRATION-A3120-golden.md) - 3 blower tachs / 1 PSU / 2 temps / 4 volts.
 * Identical seed to test_opas seed_enclosure(). */
static void seed_enclosure(scsi_mock *m) {
    unsigned char info[0x88] = {0};
    info[I2C_MAP_ENC_INFO_OFF_MODEL] = I2C_MAP_ENCBYTE_1U4BAY;   /* 'W' */
    scsi_mock_seed(m, I2_MICOP_GET_ENCL_INFO, info, sizeof info);

    unsigned char st[0x400] = {0};
    st[I2C_MAP_ENC_INFO_OFF_MODEL] = I2C_MAP_ENCBYTE_1U4BAY;
    st[I2C_MAP_ENC_CNT_OFF_FANS] = 3;   /* blower tach elements */
    st[I2C_MAP_ENC_CNT_OFF_C1]   = 1;   /* PSU count (golden: 1) */
    st[I2C_MAP_ENC_CNT_OFF_TEMP] = 2;
    st[I2C_MAP_ENC_CNT_OFF_VOLT] = 4;
    st[0x40] = 0; st[0x41] = 0; st[0x42] = 0;  /* blower status: functional */
    /* region_sum: temp=0x42+4=0x46, volt=0x44+6=0x4a, speed=0x44+10=0x4e */
    st[0x46] = 39;                /* CPU 39 C (golden) */
    st[0x47] = 24;                /* Controller 24 C */
    st[0x4a] = 0x02;              /* 1.0V rail reads 0.2V (golden) */
    st[0x4b] = 0x50;              /* 5.0V */
    st[0x4c] = 0xC3;              /* 12.3V */
    st[0x4d] = 0x33;              /* 3.3V */
    st[0x4e] = 28;                /* 1350000/(28*16) = 3013 rpm (golden) */
    st[0x4f] = 15;                /* 1350000/(15*16) = 5625 rpm */
    st[0x50] = 120;               /* 1350000/(120*2) = 5625 rpm */
    scsi_mock_seed(m, I2_MICOP_GET_ENCL_STATUS, st, sizeof st);
}

/* One PD / one LD / one array / one LD-stats row (mirrors test_opas). */
static void seed_raid(scsi_mock *m) {
    unsigned char pd[1024] = {0};
    pl32(pd, I2R_PAGE_LEN_OFF, 1 * I2R_REC_PHYDRV);
    unsigned char *r = pd + I2R_PAGE_HDR;
    pl16(r, I2R_PD_ID, 4);                 /* PDId = 5 */
    pl16(r, I2R_PD_DRVTYPE, I2BAPI_DRV_TYPE_SAS);
    memcpy(r + I2R_PD_MODEL, "ST4000NM0023", 12);
    pl64(r, I2R_PD_BLOCKSIZE, 512);
    pl16(r, I2R_PD_NUMBLOCKS, 1000);       /* cap 512000 */
    pl16(r, I2R_PD_SLOT, 3);
    pl32(r, I2R_PD_OPFLAG, PHYDRV_STATE_ONLINE | PHYDRV_CONFIG_CONFIGURED);
    pl16(r, I2R_PD_ARRAYID, 7);
    r[I2R_PD_TEMP] = 38;
    scsi_mock_seed(m, I2_MICOP_GET_PHYDRV_INFO, pd, 1024);

    unsigned char ld[1024] = {0};
    pl32(ld, I2R_PAGE_LEN_OFF, 1 * I2R_REC_LOGDRV);
    r = ld + I2R_PAGE_HDR;
    pl16(r, I2R_LD_ID, 2);
    r[I2R_LD_RAIDLVL] = I2BAPI_LDRAIDLVL_RAID5;
    pl32(r, I2R_LD_OPFLAG, LOGDRV_STATE_ONLINE | LOGDRV_COND_SYNCHRONIZED);
    pl64(r, I2R_LD_CAPBLOCKS, 2048);       /* 1048576 bytes */
    pl16(r, I2R_LD_ARRAYID, 7);
    memcpy(r + I2R_LD_ALIAS, "SystemVolume", 12);
    scsi_mock_seed(m, I2_MICOP_GET_LOGDRV_INFO, ld, 1024);

    unsigned char ar[1024] = {0};
    pl32(ar, I2R_PAGE_LEN_OFF_ARR, 1 * I2R_REC_ARRAY);
    r = ar + I2R_PAGE_HDR;
    pl16(r, I2R_DA_ID, 7); pl16(r, I2R_DA_NUMHDD, 4);
    scsi_mock_seed(m, I2_MICOP_GET_ARRAY_INFO, ar, 1024);

    unsigned char stt[1024] = {0};
    pl32(stt, I2R_PAGE_LEN_OFF, 1 * I2R_REC_STATS);
    r = stt + I2R_PAGE_HDR;
    r[I2R_ST_LDID] = 2;
    pl64(r, I2R_ST_BLOCKS, 100);
    pl32(r, I2R_ST_ERRORS, 3);
    pl64(r, I2R_ST_IOREQ, 4096);
    scsi_mock_seed(m, I2_MICOP_STATS_LOGDRV, stt, 1024);
}

int i2_fixture_minit(void) {
    g_mgr = opas_session_create();
    if (!g_mgr) return FAILURE;
    opas_session_set_auth(g_mgr, fixture_auth, NULL);

    /* On-device: I2BDG_SG_DEV set -> do NOT inject a mock; the session opens
     * /dev/sg* lazily and the same reads hit real hardware. */
    const char *dev = getenv("I2BDG_SG_DEV");
    if (dev && *dev) return SUCCESS;

    /* Real enclosure backend selected (VESSAPP_BACKEND=smbus/mmio): sensors +
     * LEDs come from the hardware, so do NOT inject the demo mock either - the
     * RAID reads (no engine until the controller runs RAID-mode firmware) fail
     * cleanly and the drive/array pages render empty instead of fixture data. */
    if (encl_backend() != ENCL_SCSI) return SUCCESS;

    g_mock = scsi_mock_open();
    if (!g_mock) return SUCCESS;   /* no fixture -> reads just return empty */
    seed_enclosure(g_mock);
    seed_raid(g_mock);
    i2bdg_session_set_transport(scsi_mock_iface(g_mock));
    return SUCCESS;
}

void i2_fixture_mshutdown(void) {
    if (g_mgr)  { opas_session_destroy(g_mgr); g_mgr = NULL; }
    if (g_mock) { i2bdg_session_set_transport(NULL); scsi_mock_free(g_mock); g_mock = NULL; }
}
