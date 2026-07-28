/* test_led - panel/record/system LED semantics + CDB/MicOpCode assertions. */
#include "test_util.h"
#include "i2bdg_api.h"
#include "i2bdg.h"

static void test_panel_set(void) {
    scsi_mock *m = mock_begin();
    CHECK(i2bdg_setLED(I2B_LED_FAN1, I2B_LED_STS_GREEN, 0) == I2BDG_ERR_SUCCESS, "setLED FAN1");
    const scsi_txn *t = txn_micop(m, 0, I2_MICOP_SET_LED_FRU);   /* 0x130F */
    CHECK(t->dir == SCSI_XFER_WRITE, "setLED is a write");
    CHECK(t->cdb[I2_CDB_OFF_ELEM] == I2B_LED_FAN1, "fru id @CDB[6]=0x%x", t->cdb[6]);
    CHECK(t->cdb[I2_CDB_OFF_SUBPARAM] == I2B_LED_STS_GREEN, "colour @CDB[7]=0x%x", t->cdb[7]);
    mock_end();
}

static void test_validation(void) {
    scsi_mock *m = mock_begin();
    /* HDD slot LEDs are read-only -> UNSUPPORTED on set, and emit no command */
    CHECK(i2bdg_setLED(I2B_LED_HDD_SLOT1, I2B_LED_STS_GREEN, 0) == I2B_API_RET_UNSUPPORTED, "hdd set unsupported");
    CHECK(i2bdg_setLED(0x55, I2B_LED_STS_GREEN, 0) == I2B_API_RET_PARAM_ERR, "bad id param err");
    CHECK(scsi_mock_txn_count(m) == 0, "rejected sets emit nothing");
    /* HDD slot getLED is allowed -> i2_getLedFruInfoByHdd (0x290F), slot=id-0x7f */
    unsigned char c = 0;
    CHECK(i2bdg_getLED(I2B_LED_HDD_SLOT1, &c, NULL) == I2BDG_ERR_SUCCESS, "hdd get ok");
    const scsi_txn *t = txn_micop(m, 0, I2_MICOP_GET_LED_FRU_HDD);
    CHECK(t->cdb[I2_CDB_OFF_ELEM] == I2B_LED_HDD_SLOT1 - I2C_MAP_LED_HDD_SLOT_BIAS, "slot=1 @CDB[6]");
    /* getLED requires colour ptr; SYSTEM requires cause ptr too */
    CHECK(i2bdg_getLED(I2B_LED_FAN1, NULL, NULL) == I2B_API_RET_PARAM_ERR, "null colour");
    CHECK(i2bdg_getLED(I2B_LED_SYSTEM, &c, NULL) == I2B_API_RET_PARAM_ERR, "system needs cause");
    mock_end();
}

static void test_panel_get(void) {
    scsi_mock *m = mock_begin();
    unsigned char rsp[8] = { I2B_LED_STS_AMBER, 0, 0, 0, 0, 0, 0, 0 };
    scsi_mock_seed(m, I2_MICOP_GET_LED_FRU, rsp, sizeof rsp);
    unsigned char color = 0; unsigned short cause = 0;
    CHECK(i2bdg_getLED(I2B_LED_RAID_STS, &color, &cause) == I2BDG_ERR_SUCCESS, "getLED");
    txn_micop(m, 0, I2_MICOP_GET_LED_FRU); /* 0x280F */
    CHECK(color == I2B_LED_STS_AMBER, "parsed colour got 0x%x", color);
    mock_end();
}

static void test_record(void) {
    scsi_mock *m = mock_begin();
    CHECK(i2bdg_setRecordLED(I2B_LED_STS_RED) == I2BDG_ERR_SUCCESS, "rec red ok");
    const scsi_txn *t = txn_micop(m, 0, I2_MICOP_SET_LED_FRU);
    CHECK(t->cdb[I2_CDB_OFF_ELEM] == I2C_MAP_LED_FRU_RECORD, "record FRU 0x0f @CDB[6]");
    CHECK(i2bdg_setRecordLED(I2B_LED_STS_OFF) == I2BDG_ERR_SUCCESS, "rec off ok");
    /* FW rejects green/amber for record LED (no command emitted) */
    CHECK(i2bdg_setRecordLED(I2B_LED_STS_GREEN) == I2B_API_RET_PARAM_ERR, "rec green rejected");
    CHECK(i2bdg_setRecordLED(I2B_LED_STS_AMBER) == I2B_API_RET_PARAM_ERR, "rec amber rejected");
    CHECK(scsi_mock_txn_count(m) == 2, "only the two valid sets emitted");
    mock_end();
}

static void test_system(void) {
    scsi_mock *m = mock_begin();
    CHECK(i2bdg_setSysErrorLED(I2B_LED_STS_RED, 0x1234) == I2BDG_ERR_SUCCESS, "sys set");
    const scsi_txn *t = txn_micop(m, 0, I2_MICOP_SET_LED_FRU);
    CHECK(t->cdb[I2_CDB_OFF_ELEM] == I2C_MAP_LED_FRU_SYSTEM, "SYSTEM FRU 0x10 @CDB[6]");
    CHECK(t->cdb[I2_CDB_OFF_SUBPARAM] == I2B_LED_STS_RED, "colour @CDB[7]");

    /* get SYSTEM: colour @rsp[0], cause @rsp[1..2] (LE). */
    unsigned char rsp[8] = { I2B_LED_STS_RED, 0x34, 0x12, 0, 0, 0, 0, 0 };
    scsi_mock_seed(m, I2_MICOP_GET_LED_FRU, rsp, sizeof rsp);
    unsigned char c = 0; unsigned short cause = 0;
    CHECK(i2bdg_getSysErrorLED(&c, &cause) == I2BDG_ERR_SUCCESS, "sys get");
    CHECK(c == I2B_LED_STS_RED && cause == 0x1234, "sys colour=0x%x cause=0x%x", c, cause);
    mock_end();
}

int main(void) {
    test_panel_set();
    test_validation();
    test_panel_get();
    test_record();
    test_system();
    PASS();
}
