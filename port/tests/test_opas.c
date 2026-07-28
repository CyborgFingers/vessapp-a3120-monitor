/* test_opas - opasd dispatch core: login/session envelope + read verbs, driven
 * against the mock scsi_transport with fixture requests copied from the PHP
 * shapes (RE-opas.md §3). Proves request-parse -> i2_* dispatch -> JSON format
 * end to end without any socket. */
#include "test_util.h"
#include "i2_raid.h"
#include "opas_proto.h"
#include "opas_session.h"
#include <string.h>
#include <stdio.h>

/* little-endian pokes (same as test_raid) */
static void pl16(unsigned char *p, unsigned off, unsigned v) { p[off]=v&0xff; p[off+1]=(v>>8)&0xff; }
static void pl32(unsigned char *p, unsigned off, unsigned v) { pl16(p,off,v&0xffff); pl16(p,off+2,v>>16); }
static void pl64(unsigned char *p, unsigned off, unsigned long long v){ pl32(p,off,(unsigned)v); pl32(p,off+4,(unsigned)(v>>32)); }

/* Dispatch a "key=value\n" request body; return the (static) JSON response. */
static const char *disp(opas_session_mgr *m, const char *body) {
    static char out[70000];
    size_t n = opas_dispatch(m, body, strlen(body), out, sizeof out);
    if (n >= sizeof out) n = sizeof out - 1;
    out[n] = '\0';
    return out;
}

/* Extract the integer after "key": in a JSON blob, or -1. */
static long jint(const char *s, const char *key) {
    char pat[64]; snprintf(pat, sizeof pat, "\"%s\":", key);
    const char *p = strstr(s, pat);
    if (!p) return -1;
    return strtol(p + strlen(pat), NULL, 10);
}

/* The injected credential-check hook (RE-opas.md §1.4): stands in for the engine
 * user-db. Authenticates the known admin; proves the envelope wiring. */
static int auth_hook(const char *code, const char *user, const char *md5, void *ctx) {
    (void)code; (void)md5; (void)ctx;
    return strcmp(user, "administrator") == 0;
}

/* dc + login -> return a live session id (asserts the flow on the way). */
static unsigned login_ok(opas_session_mgr *m) {
    const char *dc = disp(m, "method=dc\n");
    CHECK(jint(dc, "err") == 0, "dc err: %s", dc);
    long id = jint(dc, "id");
    CHECK(id > 0, "dc id: %s", dc);
    CHECK(strstr(dc, "\"code\":\"") != NULL, "dc code: %s", dc);

    char body[128];
    snprintf(body, sizeof body, "method=login\ndc=%ld\nusername=administrator\npassword=deadbeef\n", id);
    const char *lg = disp(m, body);
    CHECK(jint(lg, "status") == OPAS_OK, "login status: %s", lg);
    long sid = jint(lg, "sessionId");
    CHECK(sid > 0, "sessionId: %s", lg);
    return (unsigned)sid;
}

/* ---- login / session envelope + auth gate ---- */
static void test_login_flow(void) {
    scsi_mock *m = mock_begin(); (void)m;
    opas_session_mgr *mgr = opas_session_create();
    opas_session_set_auth(mgr, auth_hook, NULL);

    /* happy path */
    unsigned sid = login_ok(mgr);

    /* login against an unknown/consumed dc id fails */
    const char *bad = disp(mgr, "method=login\ndc=999999\nusername=administrator\npassword=x\n");
    CHECK(jint(bad, "status") == OPAS_ERR_LOGIN_FAILED, "bad dc: %s", bad);

    /* a dc is one-time: re-login with the same id is rejected */
    const char *dc = disp(mgr, "method=dc\n");
    long id = jint(dc, "id");
    char b[128];
    snprintf(b, sizeof b, "method=login\ndc=%ld\nusername=administrator\npassword=x\n", id);
    CHECK(jint(disp(mgr, b), "status") == OPAS_OK, "first use ok");
    snprintf(b, sizeof b, "method=login\ndc=%ld\nusername=administrator\npassword=x\n", id);
    CHECK(jint(disp(mgr, b), "status") == OPAS_ERR_LOGIN_FAILED, "dc reuse rejected");

    /* wrong user rejected by the hook */
    dc = disp(mgr, "method=dc\n"); id = jint(dc, "id");
    snprintf(b, sizeof b, "method=login\ndc=%ld\nusername=intruder\npassword=x\n", id);
    CHECK(jint(disp(mgr, b), "status") == OPAS_ERR_LOGIN_FAILED, "wrong user rejected");

    /* auth gate: a read without a valid sid is INACTIVE_SESSION */
    CHECK(jint(disp(mgr, "method=enumPhysicalDrives\n"), "status") == OPAS_ERR_INACTIVE_SESSION, "no sid gate");
    CHECK(jint(disp(mgr, "method=enumPhysicalDrives\nsid=424242\n"), "status") == OPAS_ERR_INACTIVE_SESSION, "bad sid gate");

    /* logout invalidates the session */
    char lo[64]; snprintf(lo, sizeof lo, "method=logout\nsid=%u\n", sid);
    CHECK(jint(disp(mgr, lo), "status") == OPAS_OK, "logout ok");
    char rd[64]; snprintf(rd, sizeof rd, "method=enumPhysicalDrives\nsid=%u\n", sid);
    CHECK(jint(disp(mgr, rd), "status") == OPAS_ERR_INACTIVE_SESSION, "post-logout gate");

    /* unknown method (with a valid session, so it clears the auth gate first) */
    unsigned sid2 = login_ok(mgr);
    char un[64]; snprintf(un, sizeof un, "method=frobnicate\nsid=%u\n", sid2);
    CHECK(jint(disp(mgr, un), "status") == OPAS_ERR_BAD_REQUEST, "unknown method");

    opas_session_destroy(mgr);
    mock_end();
}

/* Seed the golden A3120 enclosure info (0x000F) + status (0x010F) page pair
 * (1U/4-bay, CALIBRATION-A3120-golden.md). */
static void seed_enclosure(scsi_mock *m) {
    unsigned char info[0x88] = {0};
    info[I2C_MAP_ENC_INFO_OFF_MODEL] = I2C_MAP_ENCBYTE_1U4BAY;    /* 'W' */
    scsi_mock_seed(m, I2_MICOP_GET_ENCL_INFO, info, sizeof info);

    unsigned char st[0x400] = {0};
    st[I2C_MAP_ENC_INFO_OFF_MODEL]   = I2C_MAP_ENCBYTE_1U4BAY;    /* fan RPM k */
    st[I2C_MAP_ENC_CNT_OFF_FANS]     = 3;   /* blower tach elements */
    st[I2C_MAP_ENC_CNT_OFF_C1]       = 1;   /* PSU count (golden: 1) */
    st[I2C_MAP_ENC_CNT_OFF_TEMP]     = 2;   /* NumTempSensors */
    st[I2C_MAP_ENC_CNT_OFF_VOLT]     = 4;   /* NumVolt */
    st[0x40] = 0; st[0x41] = 0; st[0x42] = 0;  /* blower status: functional */
    /* region_sum: temp=0x42+4=0x46, volt=0x44+6=0x4a, speed=0x44+10=0x4e */
    st[0x46] = 39;                          /* CPU 39 C (golden) */
    st[0x47] = 24;                          /* Controller 24 C */
    st[0x4a] = 0x02;                        /* 1.0V rail reads 0.2V */
    st[0x4b] = 0x50; st[0x4c] = 0xC3; st[0x4d] = 0x33;
    st[0x4e] = 28;                          /* 1350000/(28*16) = 3013 rpm */
    st[0x4f] = 15;                          /* 5625 rpm */
    st[0x50] = 120;                         /* 5625 rpm (k=2 from idx 2) */
    scsi_mock_seed(m, I2_MICOP_GET_ENCL_STATUS, st, sizeof st);
}

/* ---- enclosure reads (getSubsystem, enumEnclosure) ---- */
static void test_enclosure(void) {
    scsi_mock *m = mock_begin();
    seed_enclosure(m);
    opas_session_mgr *mgr = opas_session_create();
    opas_session_set_auth(mgr, auth_hook, NULL);
    unsigned sid = login_ok(mgr);
    char b[96];

    snprintf(b, sizeof b, "method=getSubsystem\nsid=%u\n", sid);
    const char *ss = disp(mgr, b);
    CHECK(jint(ss, "status") == OPAS_OK, "getSubsystem: %s", ss);
    CHECK(strstr(ss, "ENC_TYPE_VESS_1U4BAY") != NULL, "model: %s", ss);
    CHECK(jint(ss, "numFans") == 3, "numFans: %s", ss);

    snprintf(b, sizeof b, "method=enumEnclosure\nsid=%u\n", sid);
    const char *en = disp(mgr, b);
    CHECK(jint(en, "status") == OPAS_OK, "enumEnclosure: %s", en);
    CHECK(strstr(en, "ENC_TYPE_VESS_1U4BAY") != NULL, "encl type: %s", en);
    CHECK(strstr(en, "\"rpm\":3013") != NULL, "blower rpm: %s", en);
    CHECK(strstr(en, "\"celsius\":39") != NULL, "temp: %s", en);

    opas_session_destroy(mgr);
    mock_end();
}

/* ---- RAID reads (phydrv/logdrv/array/stats) ---- */
static void test_raid_reads(void) {
    scsi_mock *m = mock_begin();

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
    pl64(r, I2R_ST_BLOCKS, 100);           /* 51200 bytes */
    pl32(r, I2R_ST_ERRORS, 3);
    pl64(r, I2R_ST_IOREQ, 4096);
    scsi_mock_seed(m, I2_MICOP_STATS_LOGDRV, stt, 1024);

    opas_session_mgr *mgr = opas_session_create();
    opas_session_set_auth(mgr, auth_hook, NULL);
    unsigned sid = login_ok(mgr);
    char b[96];

    snprintf(b, sizeof b, "method=enumPhysicalDrives\nsid=%u\n", sid);
    const char *p = disp(mgr, b);
    CHECK(jint(p, "status") == OPAS_OK, "phydrv: %s", p);
    CHECK(strstr(p, "\"pdi_FlatId\":5") != NULL, "pd id: %s", p);
    CHECK(strstr(p, "ST4000NM0023") != NULL, "pd model: %s", p);
    CHECK(strstr(p, "\"capacity\":512000") != NULL, "pd cap: %s", p);
    CHECK(strstr(p, "\"arrayId\":7") != NULL, "pd array: %s", p);

    snprintf(b, sizeof b, "method=enumLogicalDrives\nsid=%u\n", sid);
    const char *l = disp(mgr, b);
    CHECK(jint(l, "status") == OPAS_OK, "logdrv: %s", l);
    CHECK(strstr(l, "\"ldi_FlatId\":2") != NULL, "ld id: %s", l);
    CHECK(strstr(l, "SystemVolume") != NULL, "ld alias: %s", l);
    CHECK(strstr(l, "\"capacity\":1048576") != NULL, "ld cap: %s", l);
    CHECK(strstr(l, "\"raidLevel\":5") != NULL, "ld raid: %s", l);

    snprintf(b, sizeof b, "method=enumDiskArrays\nsid=%u\n", sid);
    const char *a = disp(mgr, b);
    CHECK(jint(a, "status") == OPAS_OK, "array: %s", a);
    CHECK(strstr(a, "\"acd_ArrayID\":7") != NULL, "array id: %s", a);
    CHECK(strstr(a, "\"numOfHdd\":4") != NULL, "array hdd: %s", a);

    snprintf(b, sizeof b, "method=statsLogDrv\nsid=%u\n", sid);
    const char *s = disp(mgr, b);
    CHECK(jint(s, "status") == OPAS_OK, "stats: %s", s);
    CHECK(strstr(s, "\"ldId\":2") != NULL, "stats ldid: %s", s);
    CHECK(strstr(s, "\"blocks\":51200") != NULL, "stats blocks: %s", s);

    opas_session_destroy(mgr);
    mock_end();
}

/* ---- cataloged write verb returns UNSUPPORTED, not a fake result ---- */
static void test_export_unsupported(void) {
    scsi_mock *m = mock_begin(); (void)m;
    opas_session_mgr *mgr = opas_session_create();
    opas_session_set_auth(mgr, auth_hook, NULL);
    unsigned sid = login_ok(mgr);
    char b[96]; snprintf(b, sizeof b, "method=export\nsid=%u\n", sid);
    CHECK(jint(disp(mgr, b), "status") == OPAS_ERR_UNSUPPORTED, "export unsupported");
    opas_session_destroy(mgr);
    mock_end();
}

int main(void) {
    test_login_flow();
    test_enclosure();
    test_raid_reads();
    test_export_unsupported();
    PASS();
}
