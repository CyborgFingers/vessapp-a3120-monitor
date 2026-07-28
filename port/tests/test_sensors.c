/* test_sensors - enclosure info/status page parse + CDB assertions.
 * End-to-end proof for i2bApi_getEncInfo and i2bApi_getFanInfo. */
#include "test_util.h"
#include "i2bdg_api.h"
#include <string.h>

/* Build a status page (MicOpCode 0x010F response) with the recovered layout. */
static void seed_status(scsi_mock *m, unsigned char *page /*[0x400]*/) {
    scsi_mock_seed(m, I2_MICOP_GET_ENCL_STATUS, page, 0x400);
}

static void test_encinfo(void) {
    struct { char b; const char *want; } cases[] = {
        {'R', "ENC_TYPE_VESS_3U16BAY"}, {'P', "ENC_TYPE_VESS_2U6BAY"},
        {'W', "ENC_TYPE_VESS_1U4BAY"},  {'Z', "Unknown"},
    };
    for (unsigned i = 0; i < sizeof cases / sizeof cases[0]; i++) {
        scsi_mock *m = mock_begin();
        unsigned char info[0x88] = {0};
        info[I2C_MAP_ENC_INFO_OFF_MODEL] = (unsigned char)cases[i].b;
        scsi_mock_seed(m, I2_MICOP_GET_ENCL_INFO, info, sizeof info);

        I2bEncData_t e;
        CHECK(i2bApi_getEncInfo(&e, sizeof e) == I2B_API_RET_SUCCESS, "getEncInfo");
        txn_micop(m, 0, I2_MICOP_GET_ENCL_INFO); /* 0x000F, one read */
        CHECK(e.EncId == 1, "EncId=1");
        CHECK(strcmp((char *)e.EncType, cases[i].want) == 0,
              "model '%c' -> '%s' (got '%s')", cases[i].b, cases[i].want, e.EncType);
        mock_end();
    }
    mock_begin();
    I2bEncData_t e;
    CHECK(i2bApi_getEncInfo(&e, 4) == I2B_API_RET_PARAM_ERR, "short buf");
    mock_end();
}

static void test_elemcnt(void) {
    scsi_mock *m = mock_begin();
    unsigned char st[0x400] = {0};
    st[I2C_MAP_ENC_CNT_OFF_FANS] = 6;
    st[I2C_MAP_ENC_CNT_OFF_TEMP] = 3;
    st[I2C_MAP_ENC_CNT_OFF_VOLT] = 3;
    seed_status(m, st);

    I2bElementCount_t c;
    CHECK(i2bApi_getElementCnt(&c) == I2B_API_RET_SUCCESS, "getElementCnt");
    txn_micop(m, 0, I2_MICOP_GET_ENCL_STATUS); /* 0x010F */
    CHECK(c.NumOfFans == 6, "NumFans got %u", c.NumOfFans);
    CHECK(c.NumOfTempSensors == 3, "NumTemp got %u", c.NumOfTempSensors);
    CHECK(c.NumOfVoltageSensors == 3, "NumVolt got %u", c.NumOfVoltageSensors);
    CHECK(i2bApi_getElementCnt(NULL) == I2B_API_RET_PARAM_ERR, "null param");
    mock_end();
}

static void test_fan(void) {
    scsi_mock *m = mock_begin();
    unsigned char st[0x400] = {0};
    st[I2C_MAP_ENC_CNT_OFF_FANS] = 2;                   /* NumFans = 2 */
    st[I2C_MAP_ENC_INFO_OFF_MODEL] = I2C_MAP_ENCBYTE_1U4BAY; /* 'W' (A3120) */
    st[I2C_MAP_ENC_STATUS_OFF_FANSTS + 0] = FAN_STS_FUNCTIONAL;
    st[I2C_MAP_ENC_STATUS_OFF_FANSTS + 1] = FAN_STS_MALFUNCTION;
    /* fan speed region: 0x44 + (fans=2 +0+0+0+0) = 0x46. RPM=1350000/(raw*16). */
    st[0x46 + 0] = 25;   /* -> 1350000/(25*16) = 3375 rpm */
    st[0x46 + 1] = 0;    /* raw 0 -> not present -> 0 rpm  */
    seed_status(m, st);

    I2bFanData_t fans[2];
    CHECK(i2bApi_getFanInfo(fans, sizeof fans) == I2B_API_RET_SUCCESS, "getFanInfo");
    txn_micop(m, 0, I2_MICOP_GET_ENCL_STATUS); /* 0x010F, one read */
    CHECK(fans[0].FanId == 1 && fans[0].FanSpd == 3375, "fan0 rpm got %lu", fans[0].FanSpd);
    CHECK(fans[0].FanStsFlag == FAN_STS_FUNCTIONAL, "fan0 status");
    CHECK(fans[1].FanSpd == 0 && fans[1].FanStsFlag == FAN_STS_MALFUNCTION, "fan1 status");
    CHECK(i2bApi_getFanInfo(fans, sizeof(I2bFanData_t)) == I2B_API_RET_PARAM_ERR, "small buf");
    mock_end();
}

static void test_tmpr_volt(void) {
    scsi_mock *m = mock_begin();
    unsigned char st[0x400] = {0};
    st[I2C_MAP_ENC_CNT_OFF_TEMP] = 1;   /* NumTemp = 1, at 0x42 (region_sum=0) */
    st[I2C_MAP_ENC_CNT_OFF_VOLT] = 1;   /* NumVolt = 1, at 0x44 + (0+0+0+1)=0x45 */
    st[0x42] = 70;                      /* 70 C */
    st[0x45] = 0x33;                    /* 3.3 V packed */
    seed_status(m, st);

    I2bThermalSensorData_t ts;
    CHECK(i2bApi_getThermalSensorInfo(&ts, sizeof ts) == I2B_API_RET_SUCCESS, "getThermal");
    CHECK(ts.CurTemp == 70, "temp C got %u", ts.CurTemp);

    I2bVoltageSensorData_t vs;
    CHECK(i2bApi_getVoltageSensorInfo(&vs, sizeof vs) == I2B_API_RET_SUCCESS, "getVolt");
    CHECK((vs.CurVolt >> 4) == 3 && (vs.CurVolt & 0xf) == 3, "3.3V packed got 0x%x", vs.CurVolt);
    mock_end();
}

int main(void) {
    test_encinfo();
    test_elemcnt();
    test_fan();
    test_tmpr_volt();
    PASS();
}
