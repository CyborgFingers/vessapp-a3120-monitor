/*
 * pca9555.h - NXP PCA9555 / TI TCA6416A 16-bit I2C GPIO expander.
 *
 * Drives the A3120 front/network panel LEDs. Register map and behaviour are
 * taken verbatim from the proven Python driver (ledctl.py PCA9555):
 *   0x00/0x01 Input port 0/1, 0x02/0x03 Output port 0/1, 0x06/0x07 Config 0/1.
 * The panel pins are already configured as outputs by the board, so - like the
 * Python driver - we NEVER write the config registers; we read-modify-write only
 * the OUTPUT registers. pin = port*8 + bit (pins 0..15).
 */
#ifndef PCA9555_H
#define PCA9555_H

#include <stdint.h>
#include "i2c_bus.h"

#define PCA9555_REG_INPUT_0  0x00
#define PCA9555_REG_INPUT_1  0x01
#define PCA9555_REG_OUTPUT_0 0x02
#define PCA9555_REG_OUTPUT_1 0x03
#define PCA9555_REG_CONFIG_0 0x06
#define PCA9555_REG_CONFIG_1 0x07

/* Read-modify-write a single output pin. Returns 0 on success, <0 on I2C error. */
int pca9555_set_pin(i2c_bus *b, uint8_t addr, uint8_t pin, int on);

/* Read the cached (last-written) state of an output pin: 1 on, 0 off, <0 error. */
int pca9555_get_pin(i2c_bus *b, uint8_t addr, uint8_t pin);

/*
 * Set a green/red pin pair in one read-modify-write of the shared output port.
 * Every LED pair in the A3120 map lives within a single port, so the two bits
 * change atomically (no visible intermediate colour). Returns 0 / <0.
 */
int pca9555_set_pair(i2c_bus *b, uint8_t addr, uint8_t green_pin, uint8_t red_pin,
                     int green_on, int red_on);

#endif /* PCA9555_H */
