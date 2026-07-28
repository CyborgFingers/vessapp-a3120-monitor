/*
 * i2bdg_alarm.c - terminal alarm (buzzer) and terminal sensor state over libi2.
 *
 * Recovered (docs/RE-libi2bdg.md + RE-fwinterf-pri2api.md):
 *   - alarm payload frame: type byte 0x10, u16 state at +2, request datalen 0x44
 *       (libi2bdg set/getTermAlramState @0x4370/0x4270 ->
 *        i2_set/getEnclosureTerminalAlarmSettings, MicOpCode 0x230F/0x220F)
 *   - term sensor state: u16 at +2 (i2_getEnclosureTerminalSensorSettings 0x200F)
 * NOTE: vendor spells it "Alram" (sic) - matched exactly for drop-in linkage.
 */
#include "i2bdg_internal.h"
#include "i2c_map.h"

#include <string.h>

/* Frame: [0]=type 0x10, [1]=rsvd, [2]=state_lo, [3]=state_hi, padded to 0x44. */
int i2bdg_setTermAlramState(unsigned short state) {
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    unsigned char f[I2C_MAP_ALARM_REQ_SIZE] = {0};
    f[0] = I2C_MAP_ALARM_TYPE;
    f[I2C_MAP_ALARM_STATE_OFF]     = (unsigned char)(state & 0xff);
    f[I2C_MAP_ALARM_STATE_OFF + 1] = (unsigned char)(state >> 8);
    return i2bdg_map_i2_error(i2_setEnclosureTerminalAlarmSettings(h, f, sizeof f));
}

int i2bdg_getTermAlramState(unsigned short *p_state) {
    if (!p_state) return I2B_API_RET_PARAM_ERR;
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    unsigned char f[I2C_MAP_ALARM_REQ_SIZE] = {0};
    int rc = i2_getEnclosureTerminalAlarmSettings(h, f, sizeof f);
    if (rc != I2_OK) return i2bdg_map_i2_error(rc);
    *p_state = (unsigned short)(f[I2C_MAP_ALARM_STATE_OFF] | f[I2C_MAP_ALARM_STATE_OFF + 1] << 8);
    return I2B_API_RET_SUCCESS;
}

int i2bdg_getTermSensorState(unsigned short *p_state) {
    if (!p_state) return I2B_API_RET_PARAM_ERR;
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    unsigned char f[I2C_MAP_ALARM_REQ_SIZE] = {0};
    int rc = i2_getEnclosureTerminalSensorSettings(h, f, sizeof f);
    if (rc != I2_OK) return i2bdg_map_i2_error(rc);
    *p_state = (unsigned short)(f[I2C_MAP_ALARM_STATE_OFF] | f[I2C_MAP_ALARM_STATE_OFF + 1] << 8);
    return I2B_API_RET_SUCCESS;
}
