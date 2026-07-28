/* setrecled - mirror of i2bApiSample_SetRecLed.c. usage: setrecled <colour>
 * NOTE: firmware only supports Red(2) and Off(128) for the RECORD LED. */
#include <stdio.h>
#include <stdlib.h>
#include "i2bdg_api.h"
#include "tool_common.h"

static void help(void) {
    printf("usage: setrecled <colour>\n  2 - Red\n  128 - Off\n");
    printf("  (Green/Amber are rejected by firmware for the RECORD LED)\n");
}

int main(int argc, char *argv[]) {
    if (argc != 2) { help(); return 0; }
    tool_env_mock_install();
    int clr = atoi(argv[1]);
    int ret = i2bdg_setRecordLED((unsigned char)clr);
    if (ret != 0) { printf("setRecordLED failed: 0x%02X (only Red/Off supported)\n", ret); return 1; }
    printf("Record LED set to %s\n", clr == 2 ? "Red" : "Off");
    return 0;
}
