#include "tool_common.h"
#include "i2c_map.h"
#include "i2bdg_internal.h"
#include "scsi_transport.h"

#include <stdlib.h>
#include <string.h>

/* Golden Vess A3120 (1U/4-bay) enclosure pages for off-device demos, shaped
 * per CALIBRATION-A3120-golden.md: 3 blower tachs / 1 PSU / 2 temps / 4 volts.
 * Regions are laid out per the recovered status-page model (i2c_map.h). */
void tool_seed_a3120_demo(scsi_mock *m) {
    /* enclosure INFO page (MicOpCode 0x000F): model byte 'W' at +0x0e. */
    unsigned char info[I2_ENCL_INFO_MIN] = {0};
    info[I2C_MAP_ENC_INFO_OFF_MODEL] = I2C_MAP_ENCBYTE_1U4BAY; /* 'W' */
    scsi_mock_seed(m, I2_MICOP_GET_ENCL_INFO, info, sizeof info);

    /* enclosure STATUS page (MicOpCode 0x010F). */
    unsigned char st[I2_ENCL_STATUS_LEN] = {0};
    st[I2C_MAP_ENC_CNT_OFF_FANS] = 3;   /* blower tach elements */
    st[I2C_MAP_ENC_CNT_OFF_C1]   = 1;   /* PSU count (golden: 1) */
    st[I2C_MAP_ENC_CNT_OFF_TEMP] = 2;   /* NumTemp   */
    st[I2C_MAP_ENC_CNT_OFF_VOLT] = 4;   /* NumVolt   */
    st[I2C_MAP_ENC_INFO_OFF_MODEL] = I2C_MAP_ENCBYTE_1U4BAY;

    for (int i = 0; i < 3; i++)         /* blower status: all functional */
        st[I2C_MAP_ENC_STATUS_OFF_FANSTS + i] = FAN_STS_FUNCTIONAL;
    /* golden RPMs 3013/5625/5625: blowers 0,1 k=16 (raw 28,15), 2+ k=2 (raw 120) */
    unsigned base_speed = 0x44 + (3 + 1 + 0 + 2 + 4); /* == region_sum(5) */
    st[base_speed + 0] = 28; st[base_speed + 1] = 15; st[base_speed + 2] = 120;

    unsigned base_temp = 0x42 + (3 + 1 + 0);          /* == region_sum(3) */
    st[base_temp + 0] = 39;   /* CPU (golden) */
    st[base_temp + 1] = 24;   /* Controller   */

    unsigned char vv[4] = { 0x02, 0x50, 0xC3, 0x33 }; /* 0.2 / 5.0 / 12.3 / 3.3 V */
    unsigned base_volt = 0x44 + (3 + 1 + 0 + 2);      /* == region_sum(4) */
    for (int i = 0; i < 4; i++) st[base_volt + i] = vv[i];

    scsi_mock_seed(m, I2_MICOP_GET_ENCL_STATUS, st, sizeof st);
}

void tool_env_mock_install(void) {
    const char *v = getenv("I2BDG_MOCK");
    if (!v || !*v || v[0] == '0') return;
    scsi_mock *m = scsi_mock_open();
    if (!m) return;
    tool_seed_a3120_demo(m);
    i2bdg_session_set_transport(scsi_mock_iface(m)); /* leaked for process life - fine */
}

const char *tool_led_colour_name(unsigned char c) {
    switch (c) {
        case I2B_LED_STS_GREEN:       return "Green";
        case I2B_LED_STS_RED:         return "Red";
        case I2B_LED_STS_AMBER:       return "Amber";
        case I2B_LED_STS_BLINK_GREEN: return "Green Blink";
        case I2B_LED_STS_BLINK_RED:   return "Red Blink";
        case I2B_LED_STS_BLINK_AMBER: return "Amber Blink";
        case I2B_LED_STS_OFF:         return "Off";
        default:                      return "Off";
    }
}
