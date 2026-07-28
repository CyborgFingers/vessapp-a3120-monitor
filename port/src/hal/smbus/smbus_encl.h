/*
 * smbus_encl.h - the SMBus enclosure backend for the A3120 bare-Linux box.
 *
 * Maps the port's LED FRU/colour semantics (i2bdg_api.h) onto PCA9555 green/red
 * pins (0x20 FrontPanel HDD slots, 0x21 NetworkPanel system LEDs), and sources
 * fans/temps/voltages from the NCT7802Y (i2c 0x2e) + kernel hwmon. Every mapping
 * reuses the proven Python driver's pin/register maps verbatim.
 *
 * i2bdg_led.c / i2bdg_sensors.c dispatch here when VESSAPP_BACKEND=smbus; the
 * public i2bdg validation (panel/hdd/record/system rules) stays with the caller,
 * so these entry points are the raw hardware write/read + sensor collection.
 */
#ifndef SMBUS_ENCL_H
#define SMBUS_ENCL_H

#include "i2bdg_api.h"
#include "i2c_bus.h"

/* ---- bus session (mirrors i2bdg_session) ---- */
void     smbus_encl_set_bus(i2c_bus *b);   /* inject (borrowed); NULL clears    */
i2c_bus *smbus_encl_bus(void);             /* lazy /dev/i2c-N; NULL if no bus    */
void     smbus_encl_reset(void);           /* drop cached bus + init state       */

/* ---- LED write/read (validation already done by the i2bdg caller) ----
 * Return I2B_API_RET_* codes. Unmapped FRUs -> I2B_API_RET_UNSUPPORTED. */
int smbus_led_write(unsigned char id, unsigned char color, unsigned short cause);
int smbus_led_read (unsigned char id, unsigned char *color, unsigned short *cause);

/* ---- sensor reads (mirror the i2bApi_* contract) ---- */
I2B_API_RET_E smbus_getElementCnt(I2bElementCount_t *out);
I2B_API_RET_E smbus_getFanInfo(I2bFanData_t *out, unsigned long buflen);
I2B_API_RET_E smbus_getThermalSensorInfo(I2bThermalSensorData_t *out, unsigned long buflen);
I2B_API_RET_E smbus_getVoltageSensorInfo(I2bVoltageSensorData_t *out, unsigned long buflen);

/* ---- fan control (curve + hard floor) ----
 * Not an i2bdg API - the enclosure firmware drove the fans on the appliance; on
 * the bare box a fan tick must. smbus_fan_pwm_for_temp is the pure step-curve
 * lookup (>= NCT7802Y_PWM_FLOOR); smbus_fan_apply also writes the blower PWM. */
int smbus_fan_pwm_for_temp(int temp_c, int profile_cpu);
int smbus_fan_apply(int temp_c, int profile_cpu);

#endif /* SMBUS_ENCL_H */
