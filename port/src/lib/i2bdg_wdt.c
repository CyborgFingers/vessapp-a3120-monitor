/*
 * i2bdg_wdt.c - IT8720F watchdog timer enable / update / disable over libi2.
 *
 * Recovered (docs/RE-libi2bdg.md), from libi2bdg enable/update/disableWDT
 * @0x4730/0x4820/0x4910 -> i2_setWDTSettings (MicOpCode 0x190F):
 *   payload frame byte0=0x00 byte1=0xAD(marker) byte2=action byte3=interval
 *   action: enable=0x01, update=0x02, disable=0x03
 *   updateWDT floors the interval: uses 5 when caller passes <=2
 *   (the DLL does interval = (param>2)?param:5; sample enforces 3..255).
 */
#include "i2bdg_internal.h"
#include "i2c_map.h"

static I2B_API_RET_E wdt_write(unsigned char action, unsigned char interval) {
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    unsigned char f[4] = { 0x00, I2C_MAP_WDT_MARKER, action, interval };
    return (I2B_API_RET_E)i2bdg_map_i2_error(i2_setWDTSettings(h, f, sizeof f));
}

I2B_API_RET_E i2bdg_enableWDT(void) {
    return wdt_write(I2C_MAP_WDT_ACTION_ENABLE, 0);
}

I2B_API_RET_E i2bdg_updateWDT(unsigned char interval) {
    unsigned char eff = interval > 2 ? interval : 5; /* DLL floor (see header) */
    return wdt_write(I2C_MAP_WDT_ACTION_UPDATE, eff);
}

I2B_API_RET_E i2bdg_disableWDT(void) {
    return wdt_write(I2C_MAP_WDT_ACTION_DISABLE, 0);
}
