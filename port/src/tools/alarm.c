/* alarm - get/set the terminal (buzzer) alarm state.
 * usage: alarm get | alarm set <state>   (state is a u16) */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "i2bdg_api.h"
#include "tool_common.h"

int main(int argc, char *argv[]) {
    tool_env_mock_install();
    if (argc == 2 && !strcmp(argv[1], "get")) {
        unsigned short st = 0;
        int ret = i2bdg_getTermAlramState(&st);
        if (ret != 0) { printf("get alarm failed: 0x%02X\n", ret); return 1; }
        printf("Alarm state: 0x%04x (%s)\n", st, st ? "ON" : "OFF");
        return 0;
    }
    if (argc == 3 && !strcmp(argv[1], "set")) {
        unsigned short st = (unsigned short)atoi(argv[2]);
        int ret = i2bdg_setTermAlramState(st);
        if (ret != 0) { printf("set alarm failed: 0x%02X\n", ret); return 1; }
        printf("Alarm state set to 0x%04x\n", st);
        return 0;
    }
    printf("usage: alarm get | alarm set <state>\n");
    return 0;
}
