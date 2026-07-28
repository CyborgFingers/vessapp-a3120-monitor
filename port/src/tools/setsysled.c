/* setsysled - set the SYSTEM error LED. usage: setsysled <colour> [cause] */
#include <stdio.h>
#include <stdlib.h>
#include "i2bdg_api.h"
#include "tool_common.h"

int main(int argc, char *argv[]) {
    if (argc < 2 || argc > 3) { printf("usage: setsysled <colour> [cause]\n"); return 0; }
    tool_env_mock_install();
    unsigned char color = (unsigned char)atoi(argv[1]);
    unsigned short cause = (unsigned short)(argc == 3 ? atoi(argv[2]) : 0);
    int ret = i2bdg_setSysErrorLED(color, cause);
    if (ret != 0) { printf("setSysErrorLED failed: 0x%02X\n", ret); return 1; }
    printf("System LED set to %s, cause 0x%04x\n", tool_led_colour_name(color), cause);
    return 0;
}
