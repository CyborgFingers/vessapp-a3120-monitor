/*
 * i2bdg_led.c - LED get/set (panel, record, system-error) over libi2.
 *
 * Recovered behaviours preserved (see docs/RE-libi2bdg.md + RE-fwinterf-pri2api.md):
 *   - setLED accepts panel ids {0x02,0x03,0x0c..0x17}; HDD-slot ids 0x80..0x8f
 *     are read-only -> setLED returns UNSUPPORTED(0x06); anything else PARAM_ERR.
 *       (libi2bdg setLED @0x4090, getLED @0x3e70)
 *   - RECORD LED = FRU 0x0f; FW accepts only RED(0x02) or OFF(0x80). (@0x3b50)
 *   - SYSTEM LED = FRU 0x10 and carries a u16 cause. (@0x3d70/0x3c50)
 *   - getLED for id 0x10 also returns the cause and requires both out ptrs.
 *
 * Wire: set -> i2_setLedFruInfo (MicOpCode 0x130F, CDB carries fru_id@6 colour@7).
 *       get -> i2_getLedFruInfo (0x280F); HDD slots -> i2_getLedFruInfoByHdd
 *       (0x290F, slot = id - 0x7f).
 */
#include "i2bdg_internal.h"
#include "i2c_map.h"
#include "encl_backend.h"
#include "smbus_encl.h"

#include <string.h>

/* getLED response parse offsets (CALIBRATE: FRU-info-page byte positions are
 * FW-defined; confirm against a real i2_getLedFruInfo page on-device). */
#define LED_RSP_OFF_COLOR  0
#define LED_RSP_OFF_CAUSE  1

static int is_panel_led(unsigned char id) {
    switch (id) {
        case I2B_LED_FAN1: case I2B_LED_FAN2:
        case I2B_LED_RAID_STS: case I2B_LED_USB1: case I2B_LED_USB2:
        case I2B_LED_RECORD: case I2B_LED_SYSTEM: case I2B_LED_GLOBAL_HDD_STS:
        case I2B_LED_ETH_PORT1: case I2B_LED_ETH_PORT2: case I2B_LED_PWR:
        case I2B_LED_ETH_PORT3: case I2B_LED_ETH_PORT4: case I2B_LED_HeartBeat:
            return 1;
        default: return 0;
    }
}
static int is_hdd_led(unsigned char id) {
    return id >= I2C_MAP_LED_HDD_SLOT_FIRST && id <= I2C_MAP_LED_HDD_SLOT_LAST;
}

static int led_write(unsigned char id, unsigned char color, unsigned short cause) {
    if (encl_backend() == ENCL_SMBUS) return smbus_led_write(id, color, cause);
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    return i2bdg_map_i2_error(i2_setLedFruInfo(h, id, color, cause));
}

static int led_read(unsigned char id, unsigned char *color, unsigned short *cause) {
    if (encl_backend() == ENCL_SMBUS) return smbus_led_read(id, color, cause);
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    unsigned char f[8] = {0};
    int rc = is_hdd_led(id)
             ? i2_getLedFruInfoByHdd(h, (unsigned char)(id - I2C_MAP_LED_HDD_SLOT_BIAS), f, sizeof f)
             : i2_getLedFruInfo(h, id, f, sizeof f);
    if (rc != I2_OK) return i2bdg_map_i2_error(rc);
    if (color) *color = f[LED_RSP_OFF_COLOR];
    if (cause) *cause = (unsigned short)(f[LED_RSP_OFF_CAUSE] | f[LED_RSP_OFF_CAUSE + 1] << 8);
    return I2B_API_RET_SUCCESS;
}

int i2bdg_setLED(unsigned char id, unsigned char color, unsigned short cause) {
    if (is_hdd_led(id)) return I2B_API_RET_UNSUPPORTED; /* slots are read-only */
    if (!is_panel_led(id)) return I2B_API_RET_PARAM_ERR;
    return led_write(id, color, cause);
}

int i2bdg_getLED(unsigned char id, unsigned char *p_color, unsigned short *p_cause) {
    if (!p_color) return I2B_API_RET_PARAM_ERR;
    if (id == I2B_LED_SYSTEM && !p_cause) return I2B_API_RET_PARAM_ERR;
    if (!is_panel_led(id) && !is_hdd_led(id)) return I2B_API_RET_PARAM_ERR;
    unsigned short cause = 0;
    int rc = led_read(id, p_color, &cause);
    if (rc == I2B_API_RET_SUCCESS && id == I2B_LED_SYSTEM && p_cause) *p_cause = cause;
    return rc;
}

int i2bdg_setRecordLED(unsigned char color) {
    if (color != I2C_MAP_RECLED_ALLOW_RED && color != I2C_MAP_RECLED_ALLOW_OFF)
        return I2B_API_RET_PARAM_ERR; /* FW only supports Red/Off for RECORD */
    return led_write(I2B_LED_RECORD, color, 0);
}

int i2bdg_getRecordLED(unsigned char *p_color) {
    if (!p_color) return I2B_API_RET_PARAM_ERR;
    return led_read(I2B_LED_RECORD, p_color, NULL);
}

int i2bdg_setSysErrorLED(unsigned char color, unsigned short cause) {
    return led_write(I2B_LED_SYSTEM, color, cause);
}

int i2bdg_getSysErrorLED(unsigned char *p_color, unsigned short *p_cause) {
    if (!p_color || !p_cause) return I2B_API_RET_PARAM_ERR;
    return led_read(I2B_LED_SYSTEM, p_color, p_cause);
}
