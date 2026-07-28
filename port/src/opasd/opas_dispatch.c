/*
 * opas_dispatch.c - opasd request-parse -> substrate dispatch -> JSON response.
 *
 * The socket-free core (the unit-test boundary, RE-opas.md §3). Reuses the
 * Phase-2/2b substrate: i2bApi_* (enclosure + RAID reads over the process-global
 * session) and a raw i2_fw_cmd for the controller-info page. No wire format is
 * invented; field names follow the recovered PHP-visible names (RE-opas.md §2).
 */
#include "opas_proto.h"
#include "opas_json.h"

#include "i2bdg_api.h"
#include "i2bdg_internal.h"   /* i2bdg_session_i2() */
#include "i2.h"               /* i2_fw_cmd, i2_handle */
#include "i2_micops.h"        /* I2_MICOP_GET_CTRL_INFO */

#include <string.h>
#include <stdlib.h>
#include <stdio.h>

/* ------------------------------------------------------------------ request */

/* Copy the value of `key` from a "key=value\n" body into out[cap] (NUL-term).
 * Returns 1 if found. Whitespace/CR at end of line is trimmed. */
static int req_get(const char *req, size_t n, const char *key,
                   char *out, size_t cap) {
    size_t klen = strlen(key);
    const char *p = req, *end = req + n;
    while (p < end) {
        const char *nl = memchr(p, '\n', (size_t)(end - p));
        const char *line_end = nl ? nl : end;
        if ((size_t)(line_end - p) > klen && memcmp(p, key, klen) == 0 && p[klen] == '=') {
            const char *v = p + klen + 1;
            size_t vlen = (size_t)(line_end - v);
            while (vlen && (v[vlen - 1] == '\r' || v[vlen - 1] == ' ')) vlen--;
            if (vlen >= cap) vlen = cap - 1;
            memcpy(out, v, vlen);
            out[vlen] = '\0';
            return 1;
        }
        p = nl ? nl + 1 : end;
    }
    if (cap) out[0] = '\0';
    return 0;
}

static unsigned req_get_uint(const char *req, size_t n, const char *key) {
    char v[32];
    return req_get(req, n, key, v, sizeof v) ? (unsigned)strtoul(v, NULL, 10) : 0;
}

/* Copy a fixed-width, possibly-unterminated device string field, trimmed. */
static void copy_field(char *dst, size_t cap, const unsigned char *src, size_t srclen) {
    size_t i = 0;
    for (; i < srclen && i < cap - 1 && src[i]; i++) dst[i] = (char)src[i];
    while (i && (dst[i - 1] == ' ')) i--;
    dst[i] = '\0';
}

/* ----------------------------------------------------------------- helpers */

static size_t reply_status(char *out, size_t cap, int status) {
    json_buf j; json_init(&j, out, cap);
    json_raw(&j, "{"); json_int(&j, "status", status);
    json_end_trim(&j); json_raw(&j, "}");
    return j.len;
}

/* Map an I2B_API_RET_* into the opasd status space (0 stays 0). */
static int map_ret(int rc) {
    switch (rc) {
        case I2B_API_RET_SUCCESS:     return OPAS_OK;
        case I2B_API_RET_UNSUPPORTED: return OPAS_ERR_UNSUPPORTED;
        case I2B_API_RET_PARAM_ERR:   return OPAS_ERR_BAD_REQUEST;
        default:                      return OPAS_ERR_ENGINE;
    }
}

/* --------------------------------------------------------------- auth+login */

static size_t do_dc(opas_session_mgr *mgr, char *out, size_t cap) {
    unsigned id; char code[32];
    json_buf j; json_init(&j, out, cap);
    json_raw(&j, "{");
    if (opas_dc_issue(mgr, &id, code, sizeof code) == 0) {
        json_int(&j, "err", 0);          /* login.php branches on $dc['err']   */
        json_str(&j, "code", code);
        json_int(&j, "id", id);
    } else {
        json_int(&j, "err", OPAS_ERR_ENGINE);
    }
    json_end_trim(&j); json_raw(&j, "}");
    return j.len;
}

static size_t do_login(opas_session_mgr *mgr, const char *req, size_t n,
                       char *out, size_t cap) {
    char user[64], md5[128];
    unsigned dc = req_get_uint(req, n, "dc");
    req_get(req, n, "username", user, sizeof user);
    req_get(req, n, "password", md5, sizeof md5);   /* the md5password chain    */
    unsigned sid = 0;
    if (opas_login(mgr, dc, user, md5, &sid) != 0)
        return reply_status(out, cap, OPAS_ERR_LOGIN_FAILED);
    json_buf j; json_init(&j, out, cap);
    json_raw(&j, "{");
    json_int(&j, "status", OPAS_OK);
    json_int(&j, "sessionId", sid);
    json_end_trim(&j); json_raw(&j, "}");
    return j.len;
}

static size_t do_logout(opas_session_mgr *mgr, const char *req, size_t n,
                        char *out, size_t cap) {
    opas_logout(mgr, req_get_uint(req, n, "sid"));
    return reply_status(out, cap, OPAS_OK);
}

/* --------------------------------------------------------------- read verbs */

#define BIGBUF (64u * 1024u)

static size_t do_getSubsystem(char *out, size_t cap) {
    I2bEncData_t enc; I2bElementCount_t cnt;
    int r1 = i2bApi_getEncInfo(&enc, sizeof enc);
    int r2 = i2bApi_getElementCnt(&cnt);
    if (r1 != I2B_API_RET_SUCCESS) return reply_status(out, cap, map_ret(r1));
    if (r2 != I2B_API_RET_SUCCESS) return reply_status(out, cap, map_ret(r2));
    json_buf j; json_init(&j, out, cap);
    json_raw(&j, "{");
    json_int(&j, "status", OPAS_OK);
    json_str(&j, "model", (const char *)enc.EncType);
    json_str(&j, "ssi_CustModel", (const char *)enc.EncType); /* derived; engine subsystem field cataloged */
    json_int(&j, "numFans", cnt.NumOfFans);
    json_int(&j, "numTempSensors", cnt.NumOfTempSensors);
    json_int(&j, "numVoltageSensors", cnt.NumOfVoltageSensors);
    json_end_trim(&j); json_raw(&j, "}");
    return j.len;
}

static size_t do_enumEnclosure(char *out, size_t cap) {
    I2bEncData_t enc; I2bElementCount_t cnt;
    int rc = i2bApi_getEncInfo(&enc, sizeof enc);
    if (rc != I2B_API_RET_SUCCESS) return reply_status(out, cap, map_ret(rc));
    rc = i2bApi_getElementCnt(&cnt);
    if (rc != I2B_API_RET_SUCCESS) return reply_status(out, cap, map_ret(rc));

    I2bFanData_t fans[16]; I2bThermalSensorData_t temps[16]; I2bVoltageSensorData_t volts[16];
    unsigned nf = cnt.NumOfFans > 16 ? 16 : cnt.NumOfFans;
    unsigned nt = cnt.NumOfTempSensors > 16 ? 16 : cnt.NumOfTempSensors;
    unsigned nv = cnt.NumOfVoltageSensors > 16 ? 16 : cnt.NumOfVoltageSensors;
    if (nf) i2bApi_getFanInfo(fans, nf * sizeof fans[0]);
    if (nt) i2bApi_getThermalSensorInfo(temps, nt * sizeof temps[0]);
    if (nv) i2bApi_getVoltageSensorInfo(volts, nv * sizeof volts[0]);

    json_buf j; json_init(&j, out, cap);
    json_raw(&j, "{");
    json_int(&j, "status", OPAS_OK);
    json_key(&j, "enclosures"); json_raw(&j, "[{");
    json_int(&j, "encId", enc.EncId);
    json_str(&j, "type", (const char *)enc.EncType);
    json_key(&j, "fans"); json_raw(&j, "[");
    for (unsigned i = 0; i < nf; i++) {
        json_raw(&j, "{");
        json_int(&j, "fanId", fans[i].FanId);
        json_u64(&j, "rpm", fans[i].FanSpd);
        json_int(&j, "statusFlag", fans[i].FanStsFlag);
        json_end_trim(&j); json_raw(&j, "},");
    }
    json_end_trim(&j); json_raw(&j, "],");
    json_key(&j, "temps"); json_raw(&j, "[");
    for (unsigned i = 0; i < nt; i++) {
        json_raw(&j, "{");
        json_int(&j, "sensorId", temps[i].SensorId);
        json_int(&j, "celsius", temps[i].CurTemp);
        json_end_trim(&j); json_raw(&j, "},");
    }
    json_end_trim(&j); json_raw(&j, "],");
    json_key(&j, "volts"); json_raw(&j, "[");
    for (unsigned i = 0; i < nv; i++) {
        json_raw(&j, "{");
        json_int(&j, "sensorId", volts[i].SensorId);
        json_int(&j, "raw", volts[i].CurVolt);
        json_end_trim(&j); json_raw(&j, "},");
    }
    json_end_trim(&j); json_raw(&j, "]");
    json_raw(&j, "}]");
    json_raw(&j, "}");
    return j.len;
}

static size_t do_enumControllers(char *out, size_t cap) {
    /* Controller-info page 0x0103 (fwinterf discovery): product string @224 is
     * the one recovered offset (RE-fwinterf-pri2api.md §1); deeper fields are
     * CALIBRATE. Raw read via the substrate handle. */
    i2_handle *h = i2bdg_session_i2();
    if (!h) return reply_status(out, cap, OPAS_ERR_ENGINE);
    unsigned char page[0x458];
    memset(page, 0, sizeof page);
    int rc = i2_fw_cmd(h, I2_MICOP_GET_CTRL_INFO, 0, 0, SCSI_XFER_READ, page, sizeof page);
    if (rc != I2_OK) return reply_status(out, cap, OPAS_ERR_ENGINE);
    char product[32];
    copy_field(product, sizeof product, page + 224, 16);
    json_buf j; json_init(&j, out, cap);
    json_raw(&j, "{");
    json_int(&j, "status", OPAS_OK);
    json_key(&j, "controllers"); json_raw(&j, "[{");
    json_int(&j, "ci_DeviceID", 0);
    json_str(&j, "product", product);
    json_end_trim(&j); json_raw(&j, "}]");
    json_raw(&j, "}");
    return j.len;
}

static size_t do_enumPhysicalDrives(char *out, size_t cap) {
    I2bHddInfo_t *hdd = calloc(256, sizeof *hdd);
    if (!hdd) return reply_status(out, cap, OPAS_ERR_ENGINE);
    int rc = i2bApi_getHardDiskDriveInfo(hdd, 256 * sizeof *hdd);
    if (rc != I2B_API_RET_SUCCESS) { free(hdd); return reply_status(out, cap, map_ret(rc)); }
    unsigned n = hdd[0].NumOfPD;
    if (n > 256) n = 256;
    json_buf j; json_init(&j, out, cap);
    json_raw(&j, "{");
    json_int(&j, "status", OPAS_OK);
    json_key(&j, "physicaldrives"); json_raw(&j, "[");
    for (unsigned i = 0; i < n; i++) {
        json_raw(&j, "{");
        json_int(&j, "pdi_FlatId", hdd[i].PDId);
        json_int(&j, "drvType", hdd[i].drvType);
        json_str(&j, "model", (const char *)hdd[i].driveModelNo);
        json_u64(&j, "capacity", hdd[i].confCapacity);
        json_int(&j, "enclosureId", hdd[i].enclosureId);
        json_int(&j, "slotId", hdd[i].slotId);
        json_u64(&j, "opStatusFlag", hdd[i].opStatusFlag);
        json_int(&j, "arrayId", hdd[i].arrayId);
        json_int(&j, "temperature", hdd[i].temperature);
        json_end_trim(&j); json_raw(&j, "},");
    }
    json_end_trim(&j); json_raw(&j, "]");
    json_raw(&j, "}");
    free(hdd);
    return j.len;
}

static size_t do_enumLogicalDrives(char *out, size_t cap) {
    unsigned char *buf = calloc(1, BIGBUF);
    if (!buf) return reply_status(out, cap, OPAS_ERR_ENGINE);
    I2bLogDrvInfo_t *l = (I2bLogDrvInfo_t *)buf;
    int rc = i2bApi_getLogDrvInfo(l, BIGBUF);
    if (rc != I2B_API_RET_SUCCESS) { free(buf); return reply_status(out, cap, map_ret(rc)); }
    unsigned n = (unsigned)l->LDCnt;
    json_buf j; json_init(&j, out, cap);
    json_raw(&j, "{");
    json_int(&j, "status", OPAS_OK);
    json_key(&j, "logicaldrives"); json_raw(&j, "[");
    for (unsigned i = 0; i < n; i++) {
        json_raw(&j, "{");
        json_int(&j, "ldi_FlatId", l->LDData[i].LdId);
        json_str(&j, "alias", (const char *)l->LDData[i].Alias);
        json_int(&j, "raidLevel", l->LDData[i].RaidLvl);
        json_u64(&j, "capacity", l->LDData[i].Capacity);
        json_u64(&j, "stripe", l->LDData[i].StripeSz);
        json_int(&j, "arrayId", l->LDData[i].arrayId);
        json_int(&j, "synced", l->LDData[i].SYNCed);
        json_int(&j, "cachePolicy", l->LDData[i].CachePolicy);
        json_u64(&j, "opStatusFlag", l->LDData[i].OpStatusFlag);
        json_end_trim(&j); json_raw(&j, "},");
    }
    json_end_trim(&j); json_raw(&j, "]");
    json_raw(&j, "}");
    free(buf);
    return j.len;
}

static size_t do_enumDiskArrays(char *out, size_t cap) {
    unsigned char *buf = calloc(1, BIGBUF);
    if (!buf) return reply_status(out, cap, OPAS_ERR_ENGINE);
    I2bArrayInfo_t *a = (I2bArrayInfo_t *)buf;
    int rc = i2bApi_getDiskArrayInfo(a, BIGBUF);
    if (rc != I2B_API_RET_SUCCESS) { free(buf); return reply_status(out, cap, map_ret(rc)); }
    unsigned n = a->DACnt;
    json_buf j; json_init(&j, out, cap);
    json_raw(&j, "{");
    json_int(&j, "status", OPAS_OK);
    json_key(&j, "arrays"); json_raw(&j, "[");
    for (unsigned i = 0; i < n; i++) {
        json_raw(&j, "{");
        json_int(&j, "acd_ArrayID", a->DAData[i].DaId);
        json_int(&j, "numOfHdd", a->DAData[i].NumOfHdd);
        json_end_trim(&j); json_raw(&j, "},");
    }
    json_end_trim(&j); json_raw(&j, "]");
    json_raw(&j, "}");
    free(buf);
    return j.len;
}

static size_t do_statsLogDrv(char *out, size_t cap) {
    unsigned char *buf = calloc(1, BIGBUF);
    if (!buf) return reply_status(out, cap, OPAS_ERR_ENGINE);
    I2bLogDrvStatusInfo_t *s = (I2bLogDrvStatusInfo_t *)buf;
    int rc = i2bApi_getStatsLogDrv(s, BIGBUF);
    if (rc != I2B_API_RET_SUCCESS) { free(buf); return reply_status(out, cap, map_ret(rc)); }
    unsigned n = (unsigned)s->LDCnt;
    json_buf j; json_init(&j, out, cap);
    json_raw(&j, "{");
    json_int(&j, "status", OPAS_OK);
    json_key(&j, "stats"); json_raw(&j, "[");
    for (unsigned i = 0; i < n; i++) {
        json_raw(&j, "{");
        json_int(&j, "ldId", s->LDStatsData[i].sld_LogicalDriveId);
        json_u64(&j, "blocks", s->LDStatsData[i].sld_BlocksTransferred);
        json_u64(&j, "ioReq", s->LDStatsData[i].sld_IOReq);
        json_u64(&j, "errors", s->LDStatsData[i].sld_Errors);
        json_u64(&j, "collectionTime", s->LDStatsData[i].sld_statsCollectionTime);
        json_end_trim(&j); json_raw(&j, "},");
    }
    json_end_trim(&j); json_raw(&j, "]");
    json_raw(&j, "}");
    free(buf);
    return j.len;
}

/* --------------------------------------------------------------- top-level */

size_t opas_dispatch(opas_session_mgr *mgr, const char *req, size_t reqlen,
                     char *out, size_t cap) {
    char method[48];
    if (!req_get(req, reqlen, "method", method, sizeof method) || !method[0])
        return reply_status(out, cap, OPAS_ERR_BAD_REQUEST);

    /* Unauthenticated verbs. */
    if (strcmp(method, "dc") == 0)     return do_dc(mgr, out, cap);
    if (strcmp(method, "login") == 0)  return do_login(mgr, req, reqlen, out, cap);
    if (strcmp(method, "logout") == 0) return do_logout(mgr, req, reqlen, out, cap);

    /* Everything else needs a live session (RE-opas.md §1.3). */
    if (!opas_session_valid(mgr, req_get_uint(req, reqlen, "sid")))
        return reply_status(out, cap, OPAS_ERR_INACTIVE_SESSION);

    if (strcmp(method, "getSubsystem") == 0)       return do_getSubsystem(out, cap);
    if (strcmp(method, "enumEnclosure") == 0)      return do_enumEnclosure(out, cap);
    if (strcmp(method, "enumControllers") == 0)    return do_enumControllers(out, cap);
    if (strcmp(method, "enumPhysicalDrives") == 0) return do_enumPhysicalDrives(out, cap);
    if (strcmp(method, "enumLogicalDrives") == 0)  return do_enumLogicalDrives(out, cap);
    if (strcmp(method, "enumDiskArrays") == 0)     return do_enumDiskArrays(out, cap);
    if (strcmp(method, "statsLogDrv") == 0)        return do_statsLogDrv(out, cap);

    /* Config/write verbs are cataloged, not implemented (RE-opas.md §4). Return
     * UNSUPPORTED rather than fake a result. */
    if (strcmp(method, "export") == 0) return reply_status(out, cap, OPAS_ERR_UNSUPPORTED);

    return reply_status(out, cap, OPAS_ERR_BAD_REQUEST);
}
