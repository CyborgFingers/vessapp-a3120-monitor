/*
 * i2.c - libi2 marshalling: build recovered Promise CDBs, issue via transport.
 *
 * CDB layout (RECOVERED - fwinterf.dll @0x1070 + pri2apiv4.dll CDB builders):
 *   [0]=0xD8 [1]=0xBA  [2..3]=MicOpCode(LE)  [6]=elem  [7]=subparam
 *   [10..11]=transfer length(BE u16)   rest = 0
 * See i2_micops.h / docs/RE-fwinterf-pri2api.md.
 */
#include "i2.h"
#include <stdlib.h>
#include <string.h>

struct i2_handle {
    scsi_transport *t;   /* transport */
    int             owns; /* close t on dispose? */
};

static i2_handle *make_handle(scsi_transport *t, int owns) {
    if (!t) return NULL;
    i2_handle *h = calloc(1, sizeof *h);
    if (!h) return NULL;
    h->t = t;
    h->owns = owns;
    return h;
}

i2_handle *i2_initialize(scsi_transport *t)        { return make_handle(t, 1); }
i2_handle *i2_initialize_borrow(scsi_transport *t) { return make_handle(t, 0); }

i2_handle *i2_initialize_discover(void) {
    scsi_transport *t = scsi_transport_discover(NULL, 0);
    if (!t) return NULL;
    i2_handle *h = i2_initialize(t);
    if (!h) scsi_transport_close(t);
    return h;
}

void i2_dispose(i2_handle *h) {
    if (!h) return;
    if (h->owns) scsi_transport_close(h->t);
    free(h);
}

scsi_transport *i2_transport(i2_handle *h) { return h ? h->t : NULL; }

int i2_fw_cmd(i2_handle *h, uint16_t micopcode, uint8_t elem, uint8_t subparam,
              scsi_dir dir, void *data, uint32_t len) {
    if (!h || !h->t) return -1;
    uint8_t cdb[I2_CDB_LEN];
    memset(cdb, 0, sizeof cdb);
    cdb[0] = I2_CDB_OP0;
    cdb[1] = I2_CDB_OP1;
    cdb[I2_CDB_OFF_MICOP]     = (uint8_t)(micopcode & 0xff);
    cdb[I2_CDB_OFF_MICOP + 1] = (uint8_t)(micopcode >> 8);
    cdb[I2_CDB_OFF_ELEM]      = elem;
    cdb[I2_CDB_OFF_SUBPARAM]  = subparam;
    /* transfer length, big-endian, at CDB[10..11] (mirrors SPTD length). */
    cdb[I2_CDB_OFF_XFERLEN_BE]     = (uint8_t)(len >> 8);
    cdb[I2_CDB_OFF_XFERLEN_BE + 1] = (uint8_t)(len & 0xff);
    return scsi_cmd(h->t, cdb, dir, data, len);
}

/* RAID/array CDB primitive: element id is a BIG-ENDIAN u16 at CDB[6..7], with an
 * optional selector at CDB[8] and element count at CDB[14] (all recovered from
 * the pri2apiv4 i2_get and i2_del builders; see docs/RE-i2-raid.md). Distinct from
 * i2_fw_cmd, whose CDB[6]/CDB[7] are the enclosure single-byte elem/subparam. */
int i2_fw_cmd_ex(i2_handle *h, uint16_t micopcode, uint16_t id, uint8_t sel,
                 uint8_t count, scsi_dir dir, void *data, uint32_t len) {
    if (!h || !h->t) return -1;
    uint8_t cdb[I2_CDB_LEN];
    memset(cdb, 0, sizeof cdb);
    cdb[0] = I2_CDB_OP0;
    cdb[1] = I2_CDB_OP1;
    cdb[I2_CDB_OFF_MICOP]     = (uint8_t)(micopcode & 0xff);
    cdb[I2_CDB_OFF_MICOP + 1] = (uint8_t)(micopcode >> 8);
    cdb[6] = (uint8_t)(id >> 8);    /* element id, big-endian */
    cdb[7] = (uint8_t)(id & 0xff);
    cdb[8] = sel;                   /* verbosity/type selector (phydrv) */
    cdb[I2_CDB_OFF_XFERLEN_BE]     = (uint8_t)(len >> 8);
    cdb[I2_CDB_OFF_XFERLEN_BE + 1] = (uint8_t)(len & 0xff);
    cdb[14] = count;                /* element count (del/init/synch set 1) */
    return scsi_cmd(h->t, cdb, dir, data, len);
}

/* ---- enclosure read pages ----
 * elem = CDB[6] = enclosure id. 0 == the local enclosure (single-enclosure
 * A3120). CALIBRATE: confirm the FW expects 0 vs 1 here (Windows hardcodes the
 * *response* EncId=1; the request id is unconfirmed). */
int i2_getEnclosureInfo(i2_handle *h, void *page, uint32_t len) {
    return i2_fw_cmd(h, I2_MICOP_GET_ENCL_INFO, 0, 0, SCSI_XFER_READ, page, len);
}
int i2_getEnclosureStatus(i2_handle *h, void *page, uint32_t len) {
    return i2_fw_cmd(h, I2_MICOP_GET_ENCL_STATUS, 0, 0, SCSI_XFER_READ, page, len);
}

/* ---- LED FRU ---- */
int i2_getLedFruInfo(i2_handle *h, uint8_t fru_id, void *out, uint32_t len) {
    return i2_fw_cmd(h, I2_MICOP_GET_LED_FRU, fru_id, 0, SCSI_XFER_READ, out, len);
}
int i2_getLedFruInfoByHdd(i2_handle *h, uint8_t slot, void *out, uint32_t len) {
    return i2_fw_cmd(h, I2_MICOP_GET_LED_FRU_HDD, slot, 0, SCSI_XFER_READ, out, len);
}
int i2_setLedFruInfo(i2_handle *h, uint8_t fru_id, uint8_t color, uint16_t cause) {
    /* CDB carries fru_id@6 + colour@7 (recovered). The FRU payload buffer
     * (colour/cause echoed) layout is FW-defined -> CALIBRATE; we send a small
     * frame the SEP is expected to read. */
    uint8_t frame[4] = { fru_id, color, (uint8_t)(cause & 0xff), (uint8_t)(cause >> 8) };
    return i2_fw_cmd(h, I2_MICOP_SET_LED_FRU, fru_id, color,
                     SCSI_XFER_WRITE, frame, sizeof frame);
}

/* ---- terminal alarm / sensor ---- */
int i2_getEnclosureTerminalAlarmSettings(i2_handle *h, void *out, uint32_t len) {
    return i2_fw_cmd(h, I2_MICOP_GET_TERM_ALARM, 0, 0, SCSI_XFER_READ, out, len);
}
int i2_setEnclosureTerminalAlarmSettings(i2_handle *h, const void *in, uint32_t len) {
    return i2_fw_cmd(h, I2_MICOP_SET_TERM_ALARM, 0, 0, SCSI_XFER_WRITE, (void *)in, len);
}
int i2_getEnclosureTerminalSensorSettings(i2_handle *h, void *out, uint32_t len) {
    return i2_fw_cmd(h, I2_MICOP_GET_TERM_SENSOR, 0, 0, SCSI_XFER_READ, out, len);
}

/* ---- watchdog ---- */
int i2_setWDTSettings(i2_handle *h, const void *frame, uint32_t len) {
    return i2_fw_cmd(h, I2_MICOP_SET_WDT, 0, 0, SCSI_XFER_WRITE, (void *)frame, len);
}

/* ---- controller-extended info (VPD) ---- */
int i2_getCtrlexInfo(i2_handle *h, uint8_t selector, void *out, uint32_t len) {
    return i2_fw_cmd(h, I2_MICOP_GET_CTRLEX_INFO, selector, 0, SCSI_XFER_READ, out, len);
}
int i2_setCtrlexSettings(i2_handle *h, uint8_t selector, const void *in, uint32_t len) {
    return i2_fw_cmd(h, I2_MICOP_SET_CTRLEX, selector, 0, SCSI_XFER_WRITE, (void *)in, len);
}
