/* test_alarm_wdt - buzzer alarm + watchdog CDB/payload assertions. */
#include "test_util.h"
#include "i2bdg_api.h"

static void test_alarm(void) {
    scsi_mock *m = mock_begin();
    CHECK(i2bdg_setTermAlramState(1) == I2B_API_RET_SUCCESS, "set alarm");
    const scsi_txn *t = txn_micop(m, 0, I2_MICOP_SET_TERM_ALARM); /* 0x230F */
    CHECK(t->dir == SCSI_XFER_WRITE, "alarm set is a write");
    CHECK(t->data_len == I2C_MAP_ALARM_REQ_SIZE, "alarm datalen 0x44 got 0x%x", t->data_len);
    CHECK(t->data[0] == I2C_MAP_ALARM_TYPE, "alarm type byte 0x10 got 0x%x", t->data[0]);
    CHECK(t->data[I2C_MAP_ALARM_STATE_OFF] == 1, "state at +2");

    /* get alarm: seed a 0x220F page with state at +2 */
    unsigned char ap[I2C_MAP_ALARM_REQ_SIZE] = {0}; ap[I2C_MAP_ALARM_STATE_OFF] = 3;
    scsi_mock_seed(m, I2_MICOP_GET_TERM_ALARM, ap, sizeof ap);
    unsigned short st = 0;
    CHECK(i2bdg_getTermAlramState(&st) == I2B_API_RET_SUCCESS && st == 3, "alarm get st=%u", st);
    CHECK(i2bdg_getTermAlramState(NULL) == I2B_API_RET_PARAM_ERR, "null state");

    /* term sensor is a separate MicOpCode (0x200F) */
    unsigned char sd[I2C_MAP_ALARM_REQ_SIZE] = {0}; sd[I2C_MAP_ALARM_STATE_OFF] = 7;
    scsi_mock_seed(m, I2_MICOP_GET_TERM_SENSOR, sd, sizeof sd);
    unsigned short ss = 0;
    CHECK(i2bdg_getTermSensorState(&ss) == I2B_API_RET_SUCCESS && ss == 7, "term sensor st=%u", ss);
    mock_end();
}

static void check_wdt(scsi_mock *m, size_t idx, unsigned char action, unsigned char interval) {
    const scsi_txn *t = txn_micop(m, idx, I2_MICOP_SET_WDT); /* 0x190F */
    CHECK(t->dir == SCSI_XFER_WRITE, "wdt is a write");
    CHECK(t->data[0] == 0x00, "wdt b0");
    CHECK(t->data[1] == I2C_MAP_WDT_MARKER, "wdt marker 0xAD got 0x%x", t->data[1]);
    CHECK(t->data[2] == action, "wdt action %u got %u", action, t->data[2]);
    CHECK(t->data[3] == interval, "wdt interval %u got %u", interval, t->data[3]);
}

static void test_wdt(void) {
    scsi_mock *m = mock_begin();
    CHECK(i2bdg_enableWDT() == I2B_API_RET_SUCCESS, "enable");
    check_wdt(m, 0, I2C_MAP_WDT_ACTION_ENABLE, 0);
    CHECK(i2bdg_updateWDT(10) == I2B_API_RET_SUCCESS, "update 10");
    check_wdt(m, 1, I2C_MAP_WDT_ACTION_UPDATE, 10);
    CHECK(i2bdg_updateWDT(1) == I2B_API_RET_SUCCESS, "update floor");
    check_wdt(m, 2, I2C_MAP_WDT_ACTION_UPDATE, 5); /* <=2 floored to 5 (recovered) */
    CHECK(i2bdg_disableWDT() == I2B_API_RET_SUCCESS, "disable");
    check_wdt(m, 3, I2C_MAP_WDT_ACTION_DISABLE, 0);
    mock_end();
}

int main(void) {
    test_alarm();
    test_wdt();
    PASS();
}
