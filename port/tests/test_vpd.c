/* test_vpd - MB/BP VPD read/write: selector, validation, checksum invariant.
 * VPD rides i2_getCtrlexInfo (0x8003) / i2_setCtrlexSettings (0x8103); the VPD
 * window is at +0x40 in the 0x140 ctrlex buffer; selector 4=MB, 5=BP @CDB[6]. */
#include "test_util.h"
#include "i2bdg_api.h"
#include <string.h>

static unsigned short sum_u16(const unsigned char *w, int off, int n) {
    unsigned short s = 0;
    for (int i = 0; i < n; i++) s = (unsigned short)(s + (w[off+2*i] | w[off+2*i+1] << 8));
    return s;
}

static void test_read_selector(void) {
    scsi_mock *m = mock_begin();
    /* seed a 0x8003 ctrlex page: VPD window at +0x40, byte 5 distinct */
    unsigned char page[I2C_MAP_VPD_BUF_SIZE] = {0};
    page[I2C_MAP_VPD_DATA_OFF + 5] = 0xAA;
    scsi_mock_seed(m, I2_MICOP_GET_CTRLEX_INFO, page, sizeof page);

    unsigned char out = 0;
    CHECK(i2bdg_getMBVPD(&out, 5, 1) == I2B_API_RET_SUCCESS && out == 0xAA, "MB read data");
    const scsi_txn *t = txn_micop(m, 0, I2_MICOP_GET_CTRLEX_INFO);
    CHECK(t->cdb[I2_CDB_OFF_ELEM] == I2C_MAP_VPD_SEL_MB, "MB selector 4 @CDB[6] got %u", t->cdb[6]);

    CHECK(i2bdg_getBPVPD(&out, 5, 1) == I2B_API_RET_SUCCESS && out == 0xAA, "BP read data");
    CHECK(txn_at(m, 1)->cdb[I2_CDB_OFF_ELEM] == I2C_MAP_VPD_SEL_BP, "BP selector 5 @CDB[6]");
    mock_end();
}

static void test_validation(void) {
    mock_begin();
    unsigned char buf[8] = {0};
    CHECK(i2bdg_getMBVPD(NULL, 0, 4) == I2B_API_RET_PARAM_ERR, "null");
    CHECK(i2bdg_getMBVPD(buf, 250, 10) == I2B_API_RET_PARAM_ERR, "read overrun");
    CHECK(i2bdg_setMBVPD(buf, 0, 4) == I2B_API_RET_PARAM_ERR, "MB write off<4");
    CHECK(i2bdg_setMBVPD(buf, 3, 4) == I2B_API_RET_PARAM_ERR, "MB write off=3");
    CHECK(i2bdg_setBPVPD(buf, 2, 4) == I2B_API_RET_PARAM_ERR, "BP write off<4");
    CHECK(i2bdg_setMBVPD(buf, 4, 20) == I2B_API_RET_PARAM_ERR, "write len>16");
    mock_end();
}

static void test_write_checksum(void) {
    scsi_mock *m = mock_begin();
    /* unseeded ctrlex read returns zeros -> a defined all-zero window */
    unsigned char payload[8] = { 1,2,3,4,5,6,7,8 };
    CHECK(i2bdg_setMBVPD(payload, 16, 8) == I2B_API_RET_SUCCESS, "MB write");

    /* the write emits a 0x8103 command carrying the patched 0x140 buffer;
     * the VPD window is at +0x40. Verify both checksum invariants + payload. */
    const scsi_txn *t = txn_micop(m, scsi_mock_txn_count(m) - 1, I2_MICOP_SET_CTRLEX);
    CHECK(t->dir == SCSI_XFER_WRITE, "ctrlex set is a write");
    CHECK(t->cdb[I2_CDB_OFF_ELEM] == I2C_MAP_VPD_SEL_MB, "MB selector on write");
    const unsigned char *win = t->data + I2C_MAP_VPD_DATA_OFF;
    CHECK(memcmp(win + 16, payload, 8) == 0, "payload landed at window+16");

    unsigned short s1 = sum_u16(win, I2C_MAP_VPD_CK1_BODY_OFF, I2C_MAP_VPD_CK1_BODY_U16);
    unsigned short c1 = (unsigned short)(win[I2C_MAP_VPD_CK1_OFF] | win[I2C_MAP_VPD_CK1_OFF+1] << 8);
    CHECK((unsigned short)(s1 + c1) == 0, "checksum1 invariant s=%u c=%u", s1, c1);

    unsigned short s2 = sum_u16(win, I2C_MAP_VPD_CK2_BODY_OFF, I2C_MAP_VPD_CK2_BODY_U16);
    unsigned short c2 = (unsigned short)(win[I2C_MAP_VPD_CK2_OFF] | win[I2C_MAP_VPD_CK2_OFF+1] << 8);
    CHECK((unsigned short)(s2 + c2) == 0, "checksum2 invariant s=%u c=%u", s2, c2);
    mock_end();
}

int main(void) {
    test_read_selector();
    test_validation();
    test_write_checksum();
    PASS();
}
