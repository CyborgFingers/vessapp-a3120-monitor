/* getled - mirror of i2bApiSample_GetSetLed.c (get path). usage: getled <led_id> */
#include <stdio.h>
#include <stdlib.h>
#include "i2bdg_api.h"
#include "i2bdg.h"
#include "tool_common.h"

int main(int argc, char *argv[]) {
    if (argc != 2) { printf("usage: getled <LED ID>\n"); return 0; }
    tool_env_mock_install();
    unsigned char id = (unsigned char)atoi(argv[1]);
    unsigned char color = 0; unsigned short cause = 0;
    int ret = i2bdg_getLED(id, &color, &cause);
    if (ret == I2BDG_ERR_SUCCESS)
        printf("\n Led %d is: %s (cause 0x%04x)\n", id, tool_led_colour_name(color), cause);
    else
        printf("\n Error: 0x%08X\n", ret);
    return 0;
}
