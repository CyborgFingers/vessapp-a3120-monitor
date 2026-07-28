/* wdt - mirror of i2bApiSample_WatchDogTimerCtrl.c
 * usage: wdt e | wdt u <interval 3..255> | wdt d */
#include <stdio.h>
#include <stdlib.h>
#include "i2bdg_api.h"
#include "tool_common.h"

static void help(void) {
    printf("usage: wdt e | wdt u <interval> | wdt d\n");
    printf("  e - enable   u - update (interval 3..255)   d - disable\n");
}

int main(int argc, char *argv[]) {
    tool_env_mock_install();
    if (argc == 2 && argv[1][0] == 'e') {
        int r = i2bdg_enableWDT();
        if (r) { printf("i2bdg_enableWDT failed, ret = %x\n", r); return 1; }
        printf("WDT enabled\n"); return 0;
    }
    if (argc == 2 && argv[1][0] == 'd') {
        int r = i2bdg_disableWDT();
        if (r) { printf("i2bdg_disableWDT failed, ret = %x\n", r); return 1; }
        printf("WDT disabled\n"); return 0;
    }
    if (argc == 3 && argv[1][0] == 'u') {
        int iv = atoi(argv[2]);
        if (iv < 3 || iv > 255) { printf("Invalid interval.\n"); help(); return 1; }
        int r = i2bdg_updateWDT((unsigned char)iv);
        if (r) { printf("i2bdg_updateWDT failed, ret = %x\n", r); return 1; }
        printf("WDT updated, interval=%d\n", iv); return 0;
    }
    help();
    return 0;
}
