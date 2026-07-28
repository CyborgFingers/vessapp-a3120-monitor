/* setled - mirror of i2bApiSample_GetSetLed.c (set path). usage: setled <id> <colour> */
#include <stdio.h>
#include <stdlib.h>
#include "i2bdg_api.h"
#include "i2bdg.h"
#include "tool_common.h"

static void help(void) {
    printf("usage: setled <LED ID> <colour>\n");
    printf("  colour: 1 Green 2 Red 4 Amber 8 GreenBlink 16 RedBlink 32 AmberBlink 128 Off\n");
}

int main(int argc, char *argv[]) {
    if (argc != 3) { help(); return 0; }
    tool_env_mock_install();
    unsigned char id  = (unsigned char)atoi(argv[1]);
    unsigned char clr = (unsigned char)atoi(argv[2]);
    int ret = i2bdg_setLED(id, clr, 0);
    if (ret == I2BDG_ERR_SUCCESS) printf("\n Led %d colour set to: 0x%02X\n", id, clr);
    else                         printf("\n Error: 0x%08X\n", ret);
    return ret == I2BDG_ERR_SUCCESS ? 0 : 1;
}
