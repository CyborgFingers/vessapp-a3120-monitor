/* getsysled - read the SYSTEM error LED (colour + cause). */
#include <stdio.h>
#include "i2bdg_api.h"
#include "tool_common.h"

int main(void) {
    tool_env_mock_install();
    unsigned char color = 0; unsigned short cause = 0;
    int ret = i2bdg_getSysErrorLED(&color, &cause);
    if (ret != 0) { printf("getSysErrorLED failed: 0x%02X\n", ret); return 1; }
    printf("System LED: %s, cause 0x%04x\n", tool_led_colour_name(color), cause);
    return 0;
}
