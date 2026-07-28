/*
 * nct7802y.c - NCT7802Y tach read + PWM write over the i2c_bus seam.
 * Faithful to hwmon.py (nct7802y_init / read_fan_rpm / set_pwm).
 */
#include "nct7802y.h"

static int wr(i2c_bus *b, uint8_t a, uint8_t reg, uint8_t v) {
    return i2c_write_byte(b, a, reg, v);
}

int nct7802y_init(i2c_bus *b, uint8_t addr) {
    int rc;
    if ((rc = wr(b, addr, NCT7802Y_BANK_SEL_REG,     0x00)) < 0) return rc;
    if ((rc = wr(b, addr, NCT7802Y_CONFIG_START_REG, 0x00)) < 0) return rc; /* stop */
    if ((rc = wr(b, addr, NCT7802Y_CONFIG2_REG,      0x42)) < 0) return rc; /* tach mode */
    if ((rc = wr(b, addr, NCT7802Y_TACH_DIV1_REG,    0x10)) < 0) return rc;
    if ((rc = wr(b, addr, NCT7802Y_TACH_DIV2_REG,    0x10)) < 0) return rc;
    if ((rc = wr(b, addr, NCT7802Y_FAN_ENABLE_REG,   0x03)) < 0) return rc; /* ch1+ch2 */
    if ((rc = wr(b, addr, NCT7802Y_FAN_OUTPUT_TYPE,  0x00)) < 0) return rc; /* PWM */
    if ((rc = wr(b, addr, NCT7802Y_FAN_OUTPUT_MODE,  0x00)) < 0) return rc; /* manual */
    if ((rc = wr(b, addr, NCT7802Y_CONFIG_START_REG, 0x01)) < 0) return rc; /* start */
    return 0;
}

int nct7802y_read_rpm(i2c_bus *b, uint8_t addr, int channel) {
    uint8_t hi_reg = (channel == 2) ? NCT7802Y_FAN_IN2_REG  : NCT7802Y_FAN_IN1_REG;
    uint8_t lo_reg = (channel == 2) ? NCT7802Y_FAN_LOW2_REG : NCT7802Y_FAN_LOW_REG;
    int rc = wr(b, addr, NCT7802Y_BANK_SEL_REG, 0x00);   /* always bank 0 */
    if (rc < 0) return rc;
    int h8 = i2c_read_byte(b, addr, hi_reg);
    if (h8 < 0) return h8;
    int lo = i2c_read_byte(b, addr, lo_reg);
    if (lo < 0) return lo;
    if (h8 == 0xFF && lo == 0xF8) return 0;              /* stopped sentinel */
    unsigned tach = (((unsigned)h8 << 5) | ((unsigned)lo >> 3)) >> 4;
    if (tach == 0 || tach == 0xFF) return 0;
    return (int)(NCT7802Y_RPM_NUMERATOR / (tach * 16u));
}

int nct7802y_read_pwm(i2c_bus *b, uint8_t addr, int channel) {
    uint8_t reg = (channel == 3) ? NCT7802Y_FANCTL3_REG : NCT7802Y_FANCTL1_REG;
    return i2c_read_byte(b, addr, reg);
}

int nct7802y_set_pwm(i2c_bus *b, uint8_t addr, int channel, int pwm) {
    if (pwm < NCT7802Y_PWM_FLOOR) pwm = NCT7802Y_PWM_FLOOR;  /* never stop fans */
    if (pwm > NCT7802Y_PWM_MAX)   pwm = NCT7802Y_PWM_MAX;
    if (channel == 1 || channel == 2) {
        int rc = wr(b, addr, NCT7802Y_FANCTL1_REG, (uint8_t)pwm);
        if (rc < 0) return rc;
        return wr(b, addr, NCT7802Y_FANCTL2_REG, (uint8_t)pwm);
    }
    if (channel == 3)
        return wr(b, addr, NCT7802Y_FANCTL3_REG, (uint8_t)pwm);
    return -1;
}
