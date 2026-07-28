/*
 * nct7802y.h - Nuvoton NCT7802Y hardware monitor (backplane blower controller).
 *
 * Register map, init sequence, tach formula and PWM floor are taken verbatim
 * from the proven Python driver (hwmon.py), itself cross-validated against the
 * Windows Prom_Enclosure calibration tool. I2C addr 0x2e on the A3120.
 *
 * RPM = 1350000 / (tach * 16), tach = ((reg0x10 << 5) | (reg0x13 >> 3)) >> 4.
 *
 * SAFETY: the PWM floor (NCT7802Y_PWM_FLOOR) is a thermal guard - the fans are
 * NEVER driven below it. Do not lower or bypass it.
 */
#ifndef NCT7802Y_H
#define NCT7802Y_H

#include <stdint.h>
#include "i2c_bus.h"

#define NCT7802Y_ADDR             0x2e

#define NCT7802Y_BANK_SEL_REG     0x00
#define NCT7802Y_FAN_IN1_REG      0x10   /* blower tach high byte (ch1)        */
#define NCT7802Y_FAN_LOW_REG      0x13   /* ch1 tach low bits (7:3)            */
#define NCT7802Y_FAN_IN2_REG      0x11   /* blower tach high byte (ch2)        */
#define NCT7802Y_FAN_LOW2_REG     0x14   /* ch2 tach low bits (7:3)            */
#define NCT7802Y_CONFIG_START_REG 0x21   /* 0 stop / 1 start monitoring        */
#define NCT7802Y_CONFIG2_REG      0x22   /* 0x42 = correct tach mode           */
#define NCT7802Y_FAN_ENABLE_REG   0x24   /* 0x03 = enable ch1+ch2              */
#define NCT7802Y_TACH_DIV1_REG    0x55   /* 0x10                               */
#define NCT7802Y_TACH_DIV2_REG    0x56   /* 0x10                               */
#define NCT7802Y_FAN_OUTPUT_TYPE  0x5e   /* 0 = PWM                            */
#define NCT7802Y_FAN_OUTPUT_MODE  0x5f   /* 0 = manual                         */
#define NCT7802Y_FANCTL1_REG      0x60   /* PWM blower1                        */
#define NCT7802Y_FANCTL2_REG      0x61   /* PWM blower2                        */
#define NCT7802Y_FANCTL3_REG      0x62   /* PWM mid-plane (A3120P)             */

#define NCT7802Y_RPM_NUMERATOR    1350000
#define NCT7802Y_PWM_FLOOR        20      /* ~8% - hard thermal floor, never 0  */
#define NCT7802Y_PWM_MAX          255

/* Run the init sequence (stop, set tach mode/divisors, enable ch1+ch2, PWM
 * manual, start). Idempotent. Returns 0 on success, <0 on the first I2C error. */
int nct7802y_init(i2c_bus *b, uint8_t addr);

/* Blower RPM from the combined 13-bit tach counter. 0 = stopped / not present,
 * <0 = I2C error. */
int nct7802y_read_rpm(i2c_bus *b, uint8_t addr, int channel);   /* channel 1 or 2 */

/* Read a raw FANCTL PWM value (0..255), or <0 on error. */
int nct7802y_read_pwm(i2c_bus *b, uint8_t addr, int channel);

/*
 * Set PWM duty (clamped to [NCT7802Y_PWM_FLOOR, 255] - NEVER stops the fans).
 * channel 1/2 writes FANCTL1+FANCTL2 together (both blowers share the value on
 * the A3120); channel 3 writes FANCTL3. Returns 0 / <0.
 */
int nct7802y_set_pwm(i2c_bus *b, uint8_t addr, int channel, int pwm);

#endif /* NCT7802Y_H */
