/* pca9555.c - OUTPUT-register read-modify-write for the panel LED expanders. */
#include "pca9555.h"

static uint8_t out_reg(uint8_t pin) {
    return (pin < 8) ? PCA9555_REG_OUTPUT_0 : PCA9555_REG_OUTPUT_1;
}

int pca9555_set_pin(i2c_bus *b, uint8_t addr, uint8_t pin, int on) {
    uint8_t reg = out_reg(pin);
    uint8_t bit = (uint8_t)(pin & 7);
    int cur = i2c_read_byte(b, addr, reg);
    if (cur < 0) return cur;
    uint8_t v = (uint8_t)cur;
    if (on) v |=  (uint8_t)(1u << bit);
    else    v &= (uint8_t)~(1u << bit);
    return i2c_write_byte(b, addr, reg, v);
}

int pca9555_get_pin(i2c_bus *b, uint8_t addr, uint8_t pin) {
    int cur = i2c_read_byte(b, addr, out_reg(pin));
    if (cur < 0) return cur;
    return (cur >> (pin & 7)) & 1;
}

int pca9555_set_pair(i2c_bus *b, uint8_t addr, uint8_t green_pin, uint8_t red_pin,
                     int green_on, int red_on) {
    uint8_t reg = out_reg(green_pin);
    /* The A3120 map keeps each green/red pair inside one 8-bit port. If a caller
     * ever passes a cross-port pair, fall back to two single-pin writes. */
    if (out_reg(red_pin) != reg) {
        int rc = pca9555_set_pin(b, addr, green_pin, green_on);
        if (rc < 0) return rc;
        return pca9555_set_pin(b, addr, red_pin, red_on);
    }
    int cur = i2c_read_byte(b, addr, reg);
    if (cur < 0) return cur;
    uint8_t v = (uint8_t)cur;
    uint8_t gm = (uint8_t)(1u << (green_pin & 7));
    uint8_t rm = (uint8_t)(1u << (red_pin   & 7));
    if (green_on) v |= gm; else v &= (uint8_t)~gm;
    if (red_on)   v |= rm; else v &= (uint8_t)~rm;
    return i2c_write_byte(b, addr, reg, v);
}
