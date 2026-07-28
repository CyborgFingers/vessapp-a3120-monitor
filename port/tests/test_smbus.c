/*
 * test_smbus - SMBus enclosure backend: assert the exact PCA9555 register bytes
 * for each LED colour (via the i2bdg dispatch) and the NCT7802Y tach decode +
 * PWM floor. Mock i2c records writes and replays seeded reads.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>

#include "encl_backend.h"
#include "smbus_encl.h"
#include "i2c_bus.h"
#include "pca9555.h"
#include "nct7802y.h"
#include "i2bdg_api.h"
#include "i2bdg.h"

static int g_checks;
#define CHECK(cond, ...) do { g_checks++; if (!(cond)) { \
    fprintf(stderr, "FAIL %s:%d: ", __FILE__, __LINE__); \
    fprintf(stderr, __VA_ARGS__); fprintf(stderr, "\n"); exit(1); } } while (0)
#define PASS() do { printf("ok - %s (%d checks)\n", __FILE__, g_checks); return 0; } while (0)

static i2c_bus *g_bus;
static i2c_bus *begin(void) {
    g_bus = i2c_mock_open();
    smbus_encl_set_bus(g_bus);
    return g_bus;
}
static void end(void) { smbus_encl_set_bus(NULL); i2c_mock_free(g_bus); g_bus = NULL; }

/* Assert the last write was (addr,reg,val) and it was the only one. */
static void only_write(i2c_bus *b, uint8_t addr, uint8_t reg, uint8_t val) {
    CHECK(i2c_mock_write_count(b) == 1, "expected 1 write, got %zu", i2c_mock_write_count(b));
    const i2c_write_rec *w = i2c_mock_write(b, 0);
    CHECK(w->addr == addr && w->reg == reg && w->val == val,
          "want %02x/%02x=%02x got %02x/%02x=%02x", addr, reg, val, w->addr, w->reg, w->val);
}

/* ---- LEDs: FRU/colour -> PCA9555 pin bytes ---- */
static void test_led_colours(void) {
    /* RAID_STS GREEN: 0x21 pins g4/r5 (port0 reg 0x02) -> bit4 set = 0x10 */
    i2c_bus *b = begin();
    CHECK(i2bdg_setLED(I2B_LED_RAID_STS, I2B_LED_STS_GREEN, 0) == I2BDG_ERR_SUCCESS, "raid green");
    only_write(b, 0x21, PCA9555_REG_OUTPUT_0, 0x10);
    end();

    /* RAID_STS RED: red pin5 -> bit5 = 0x20 */
    b = begin();
    CHECK(i2bdg_setLED(I2B_LED_RAID_STS, I2B_LED_STS_RED, 0) == I2BDG_ERR_SUCCESS, "raid red");
    only_write(b, 0x21, PCA9555_REG_OUTPUT_0, 0x20);
    end();

    /* SYSTEM AMBER: 0x21 pins g8/r9 (port1 reg 0x03) -> bits 0,1 = 0x03 */
    b = begin();
    CHECK(i2bdg_setLED(I2B_LED_SYSTEM, I2B_LED_STS_AMBER, 0) == I2BDG_ERR_SUCCESS, "sys amber");
    only_write(b, 0x21, PCA9555_REG_OUTPUT_1, 0x03);
    end();

    /* SYSTEM OFF: both off = 0x00 */
    b = begin();
    i2c_mock_seed(b, 0x21, PCA9555_REG_OUTPUT_1, 0x03);   /* start amber */
    CHECK(i2bdg_setLED(I2B_LED_SYSTEM, I2B_LED_STS_OFF, 0) == I2BDG_ERR_SUCCESS, "sys off");
    only_write(b, 0x21, PCA9555_REG_OUTPUT_1, 0x00);
    end();

    /* HDD_SLOT1 is read-only on set (i2bdg rule) -> UNSUPPORTED, no i2c write */
    b = begin();
    CHECK(i2bdg_setLED(I2B_LED_HDD_SLOT1, I2B_LED_STS_GREEN, 0) == I2B_API_RET_UNSUPPORTED, "hdd ro");
    CHECK(i2c_mock_write_count(b) == 0, "no write on unsupported set");
    end();
}

/* ---- Record LED: red-only pin 7 on 0x21 ---- */
static void test_record(void) {
    i2c_bus *b = begin();
    CHECK(i2bdg_setRecordLED(I2B_LED_STS_RED) == I2BDG_ERR_SUCCESS, "record red");
    only_write(b, 0x21, PCA9555_REG_OUTPUT_0, 0x80);      /* bit7 */
    end();

    b = begin();
    i2c_mock_seed(b, 0x21, PCA9555_REG_OUTPUT_0, 0x80);   /* red on */
    CHECK(i2bdg_setRecordLED(I2B_LED_STS_OFF) == I2BDG_ERR_SUCCESS, "record off");
    only_write(b, 0x21, PCA9555_REG_OUTPUT_0, 0x00);
    end();
}

/* ---- getLED reconstructs colour from the output-register bits ---- */
static void test_led_read(void) {
    i2c_bus *b = begin();
    i2c_mock_seed(b, 0x21, PCA9555_REG_OUTPUT_0, 0x10);   /* RAID green pin4 */
    unsigned char c = 0; unsigned short cause = 0;
    CHECK(i2bdg_getLED(I2B_LED_RAID_STS, &c, &cause) == I2BDG_ERR_SUCCESS, "getLED raid");
    CHECK(c == I2B_LED_STS_GREEN, "raid reads green, got 0x%02x", c);
    end();

    b = begin();
    i2c_mock_seed(b, 0x20, PCA9555_REG_OUTPUT_0, 0x01);   /* HDD1 green pin0 */
    c = 0;
    CHECK(i2bdg_getLED(I2B_LED_HDD_SLOT1, &c, NULL) == I2BDG_ERR_SUCCESS, "getLED hdd1");
    CHECK(c == I2B_LED_STS_GREEN, "hdd1 reads green, got 0x%02x", c);
    end();

    /* both bits set -> AMBER */
    b = begin();
    i2c_mock_seed(b, 0x20, PCA9555_REG_OUTPUT_0, 0x03);   /* HDD1 g0+r1 */
    c = 0;
    CHECK(i2bdg_getLED(I2B_LED_HDD_SLOT1, &c, NULL) == I2BDG_ERR_SUCCESS, "getLED hdd1 amber");
    CHECK(c == I2B_LED_STS_AMBER, "hdd1 reads amber, got 0x%02x", c);
    end();
}

/* ---- NCT7802Y tach decode via getFanInfo ---- */
static void test_fan_rpm(void) {
    i2c_bus *b = begin();
    /* tach=25 -> RPM=1350000/(25*16)=3375. reg0x10=12, reg0x13=0x80:
     * ((12<<5)|(0x80>>3))>>4 = (384|16)>>4 = 400>>4 = 25. */
    i2c_mock_seed(b, NCT7802Y_ADDR, NCT7802Y_FAN_IN1_REG, 12);
    i2c_mock_seed(b, NCT7802Y_ADDR, NCT7802Y_FAN_LOW_REG, 0x80);

    I2bFanData_t fans[8];
    CHECK(i2bApi_getFanInfo(fans, sizeof fans) == I2B_API_RET_SUCCESS, "getFanInfo");
    CHECK(fans[0].FanSpd == 3375, "blower rpm got %lu", fans[0].FanSpd);
    CHECK(fans[0].FanStsFlag == FAN_STS_FUNCTIONAL, "blower functional");

    /* init sequence must have configured tach mode (reg 0x22 = 0x42) */
    int saw_cfg2 = 0;
    for (size_t i = 0; i < i2c_mock_write_count(b); i++) {
        const i2c_write_rec *w = i2c_mock_write(b, i);
        if (w->addr == NCT7802Y_ADDR && w->reg == NCT7802Y_CONFIG2_REG && w->val == 0x42) saw_cfg2 = 1;
    }
    CHECK(saw_cfg2, "nct7802y init wrote CONFIG2=0x42");
    end();
}

/* ---- Fan curve step lookup + hard PWM floor ---- */
static void test_fan_curve_and_floor(void) {
    CHECK(smbus_fan_pwm_for_temp(50, 1) == 130, "cpu 50C -> 130");
    CHECK(smbus_fan_pwm_for_temp(65, 1) == 160, "cpu 65C -> 160");
    CHECK(smbus_fan_pwm_for_temp(95, 1) == 250, "cpu 95C -> 250");
    CHECK(smbus_fan_pwm_for_temp(50, 0) == 40,  "sys 50C -> 40");
    CHECK(smbus_fan_pwm_for_temp(85, 0) == 190, "sys 85C -> 190");

    /* smbus_fan_apply writes the curve PWM to FANCTL1+FANCTL2. */
    i2c_bus *b = begin();
    CHECK(smbus_fan_apply(50, 0) == 0, "apply sys 50C");
    int saw60 = 0, saw61 = 0;
    for (size_t i = 0; i < i2c_mock_write_count(b); i++) {
        const i2c_write_rec *w = i2c_mock_write(b, i);
        if (w->addr == NCT7802Y_ADDR && w->reg == NCT7802Y_FANCTL1_REG && w->val == 40) saw60 = 1;
        if (w->addr == NCT7802Y_ADDR && w->reg == NCT7802Y_FANCTL2_REG && w->val == 40) saw61 = 1;
    }
    CHECK(saw60 && saw61, "FANCTL1+2 written with 40");
    end();

    /* Hard floor: a below-floor request is clamped to NCT7802Y_PWM_FLOOR, never 0. */
    b = begin();
    CHECK(nct7802y_set_pwm(b, NCT7802Y_ADDR, 1, 5) == 0, "set low pwm");
    const i2c_write_rec *w = i2c_mock_write(b, 0);
    CHECK(w->reg == NCT7802Y_FANCTL1_REG && w->val == NCT7802Y_PWM_FLOOR,
          "floor enforced: got %d (floor %d)", w->val, NCT7802Y_PWM_FLOOR);
    CHECK(nct7802y_set_pwm(b, NCT7802Y_ADDR, 1, 0) == 0, "set zero pwm");
    /* second call writes FANCTL1 at index 2 (after 0,1 from first call) */
    w = i2c_mock_write(b, 2);
    CHECK(w->val == NCT7802Y_PWM_FLOOR, "zero request floored to %d, got %d",
          NCT7802Y_PWM_FLOOR, w->val);
    end();
}

/* ---- hwmon sensor calibration: box-shaped fixture -> golden-shaped view ----
 * Fixture mirrors the live A3120's sysfs dump (nct6776 + pch_skylake +
 * coretemp). Asserts the enclosure decode drops motherboard noise: 0-RPM
 * unpopulated fan headers, floating negative temps, divider-mangled rails. */
static void put(const char *dir, const char *file, const char *val) {
    char p[512];
    snprintf(p, sizeof p, "%s/%s", dir, file);
    FILE *f = fopen(p, "w");
    CHECK(f != NULL, "fixture write %s", p);
    fprintf(f, "%s\n", val);
    fclose(f);
}

static const char *make_hwmon_fixture(void) {
    static char root[64] = "/tmp/vessapp-hwmon-XXXXXX";
    CHECK(mkdtemp(root) != NULL, "mkdtemp");
    char d[128];
    /* hwmon0 = nct6776 Super-I/O: fan1 unpopulated, fan2 = CPU fan, SYSTIN
     * valid, CPUTIN/AUXTIN floating negative, Vcore/AVCC/+3.3V real mV plus
     * a divider-mangled pin (in1) that must be dropped. */
    snprintf(d, sizeof d, "%s/hwmon0", root); mkdir(d, 0755);
    put(d, "name", "nct6776");
    put(d, "fan1_input", "0");
    put(d, "fan2_input", "2884");
    put(d, "temp1_input", "34000");  put(d, "temp1_label", "SYSTIN");
    put(d, "temp2_input", "-61000"); put(d, "temp2_label", "CPUTIN");
    put(d, "temp3_input", "-12000"); put(d, "temp3_label", "AUXTIN");
    put(d, "temp7_input", "46500");  put(d, "temp7_label", "PECI Agent 0");
    put(d, "in0_input", "576");
    put(d, "in1_input", "1688");     /* raw pin mV behind unknown divider */
    put(d, "in2_input", "3344");
    put(d, "in3_input", "3344");
    /* hwmon1 = pch_skylake: irrelevant motherboard temp - dropped */
    snprintf(d, sizeof d, "%s/hwmon1", root); mkdir(d, 0755);
    put(d, "name", "pch_skylake");
    put(d, "temp1_input", "44000");
    /* hwmon2 = coretemp */
    snprintf(d, sizeof d, "%s/hwmon2", root); mkdir(d, 0755);
    put(d, "name", "coretemp");
    put(d, "temp1_input", "54000"); put(d, "temp1_label", "Package id 0");
    put(d, "temp2_input", "46000"); put(d, "temp2_label", "Core 0");
    return root;
}

static void test_hwmon_calibration(void) {
    setenv("VESSAPP_HWMON_ROOT", make_hwmon_fixture(), 1);
    i2c_bus *b = begin();
    /* blower tach = 25 -> 1350000/(25*16) = 3375 RPM (see test_fan_rpm) */
    /* both NCT7802Y blower channels: tach 25 -> 3375 RPM (see test_fan_rpm) */
    i2c_mock_seed(b, NCT7802Y_ADDR, NCT7802Y_FAN_IN1_REG,  12);
    i2c_mock_seed(b, NCT7802Y_ADDR, NCT7802Y_FAN_LOW_REG,  0x80);
    i2c_mock_seed(b, NCT7802Y_ADDR, NCT7802Y_FAN_IN2_REG,  12);
    i2c_mock_seed(b, NCT7802Y_ADDR, NCT7802Y_FAN_LOW2_REG, 0x80);

    /* A3120 cooling: Fan 1 = CPU (nct6776 fan2), Fans 2/3 = the two NCT7802Y
     * blowers. The unfitted System Fan (nct6776 fan1) and any GPU fan are NOT
     * chassis cooling and are excluded. */
    I2bElementCount_t cnt;
    CHECK(i2bApi_getElementCnt(&cnt) == I2B_API_RET_SUCCESS, "elemcnt");
    CHECK(cnt.NumOfFans == 3, "CPU + 2 blowers, got %u", cnt.NumOfFans);
    CHECK(cnt.NumOfTempSensors == 2, "CPU + Controller only, got %u", cnt.NumOfTempSensors);
    CHECK(cnt.NumOfVoltageSensors == 3, "Vcore/AVCC/+3.3V only, got %u", cnt.NumOfVoltageSensors);

    I2bFanData_t fans[8];
    CHECK(i2bApi_getFanInfo(fans, sizeof fans) == I2B_API_RET_SUCCESS, "getFanInfo");
    CHECK(fans[0].FanSpd == 2884 && strcmp((char *)fans[0].FanLoc, "CPU Fan") == 0,
          "fan0 CPU Fan@2884, got '%s'@%lu", fans[0].FanLoc, fans[0].FanSpd);
    CHECK(fans[1].FanSpd == 3375 && strcmp((char *)fans[1].FanLoc, "Blower") == 0 &&
          fans[1].FanStsFlag == FAN_STS_FUNCTIONAL,
          "fan1 Blower@3375, got '%s'@%lu", fans[1].FanLoc, fans[1].FanSpd);
    CHECK(fans[2].FanSpd == 3375 && strcmp((char *)fans[2].FanLoc, "Blower") == 0,
          "fan2 Blower@3375, got '%s'@%lu", fans[2].FanLoc, fans[2].FanSpd);

    I2bThermalSensorData_t ts[8];
    CHECK(i2bApi_getThermalSensorInfo(ts, sizeof ts) == I2B_API_RET_SUCCESS, "getThermal");
    CHECK(ts[0].CurTemp == 54 && strcmp((char *)ts[0].Location, "CPU") == 0,
          "temp0 CPU 54C, got '%s' %uC", ts[0].Location, ts[0].CurTemp);
    CHECK(ts[1].CurTemp == 34 && strcmp((char *)ts[1].Location, "Controller") == 0,
          "temp1 Controller 34C (SYSTIN), got '%s' %uC", ts[1].Location, ts[1].CurTemp);
    CHECK(ts[0].WarnThreshold == 90 && ts[0].CritThreshold == 100,
          "golden 90/100 thresholds, got %u/%u", ts[0].WarnThreshold, ts[0].CritThreshold);
    CHECK(ts[0].StatusFlag == ENC_TEMERATURE_STS_NORMAL, "54C healthy (<90)");

    I2bVoltageSensorData_t vs[8];
    CHECK(i2bApi_getVoltageSensorInfo(vs, sizeof vs) == I2B_API_RET_SUCCESS, "getVolt");
    CHECK(vs[0].CurVolt == 0x06 && strcmp((char *)vs[0].Type, "Vcore") == 0,
          "Vcore 576mV -> 0.6V, got '%s' 0x%02x", vs[0].Type, vs[0].CurVolt);
    CHECK(vs[1].CurVolt == 0x33 && strcmp((char *)vs[1].Type, "AVCC") == 0,
          "AVCC 3344mV -> 3.3V, got '%s' 0x%02x", vs[1].Type, vs[1].CurVolt);
    CHECK(vs[2].CurVolt == 0x33 && strcmp((char *)vs[2].Type, "+3.3V") == 0,
          "+3.3V 3344mV -> 3.3V, got '%s' 0x%02x", vs[2].Type, vs[2].CurVolt);

    /* FanEnable: nulling a slot removes it entirely (no row, no alarm). Null the
     * CPU Fan -> only the two blowers remain. */
    const char *fcpath = "/tmp/vessapp-fanen-test.json";
    FILE *fc = fopen(fcpath, "w");
    CHECK(fc != NULL, "fanconfig temp write");
    fputs("{\"FanEnable\":{\"CPU Fan\":false}}", fc);
    fclose(fc);
    setenv("PROMISE_FANCONFIG", fcpath, 1);
    CHECK(i2bApi_getElementCnt(&cnt) == I2B_API_RET_SUCCESS, "elemcnt (fanen)");
    CHECK(cnt.NumOfFans == 2, "CPU Fan nulled -> 2 blowers, got %u", cnt.NumOfFans);
    CHECK(i2bApi_getFanInfo(fans, sizeof fans) == I2B_API_RET_SUCCESS, "getFanInfo (fanen)");
    CHECK(strcmp((char *)fans[0].FanLoc, "Blower") == 0 &&
          strcmp((char *)fans[1].FanLoc, "Blower") == 0,
          "nulled slot gone: got '%s','%s'", fans[0].FanLoc, fans[1].FanLoc);
    unsetenv("PROMISE_FANCONFIG");
    remove(fcpath);

    end();
    setenv("VESSAPP_HWMON_ROOT", "/nonexistent-vessapp-test", 1);
}

int main(void) {
    setenv("VESSAPP_HWMON_ROOT", "/nonexistent-vessapp-test", 1);  /* deterministic: no hwmon fans */
    encl_backend_set(ENCL_SMBUS);
    test_led_colours();
    test_record();
    test_led_read();
    test_fan_rpm();
    test_fan_curve_and_floor();
    test_hwmon_calibration();
    PASS();
}
