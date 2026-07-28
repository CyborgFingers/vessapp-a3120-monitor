/* getfan - mirror of i2bApiSample_GetFan.c */
#include <stdio.h>
#include <stdlib.h>
#include "i2bdg_api.h"
#include "tool_common.h"

int main(void) {
    tool_env_mock_install();
    I2bElementCount_t cnt;
    if (i2bApi_getElementCnt(&cnt) != I2B_API_RET_SUCCESS) {
        printf("Fan count get failed\n"); return 1;
    }
    unsigned long len = (unsigned long)cnt.NumOfFans * sizeof(I2bFanData_t);
    I2bFanData_t *fans = calloc(cnt.NumOfFans ? cnt.NumOfFans : 1, sizeof *fans);
    I2B_API_RET_E ret = i2bApi_getFanInfo(fans, len);
    if (ret != I2B_API_RET_SUCCESS) { printf("Fan data get failed: %.02X\n", ret); free(fans); return 1; }
    for (int i = 0; i < cnt.NumOfFans; i++) {
        printf("Blower %2lu:\n", fans[i].FanId);
        printf("    Location: %s\n", fans[i].FanLoc);
        printf("    Status: %s, Flag:0x%x\n", fans[i].FanSts, fans[i].FanStsFlag);
        printf("    Threshold: %lu\n", fans[i].FanThrd);
        printf("    CurrentSpeed: %lu RPM\n", fans[i].FanSpd);
    }
    free(fans);
    return 0;
}
