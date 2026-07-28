/* getvolt - mirror of i2bApiSample_GetVolt.c */
#include <stdio.h>
#include <stdlib.h>
#include "i2bdg_api.h"
#include "tool_common.h"

int main(void) {
    tool_env_mock_install();
    I2bElementCount_t cnt;
    if (i2bApi_getElementCnt(&cnt) != I2B_API_RET_SUCCESS) { printf("Sensor count get failed\n"); return 1; }
    I2bVoltageSensorData_t *vs = calloc(cnt.NumOfVoltageSensors ? cnt.NumOfVoltageSensors : 1, sizeof *vs);
    I2B_API_RET_E ret = i2bApi_getVoltageSensorInfo(vs, (unsigned long)cnt.NumOfVoltageSensors * sizeof *vs);
    if (ret != I2B_API_RET_SUCCESS) { printf("Voltage sensor data get failed: %.02X\n", ret); free(vs); return 1; }
    for (int i = 0; i < cnt.NumOfVoltageSensors; i++) {
        printf("Voltage %2u:\n", vs[i].SensorId);
        printf("    Type: %s\n", vs[i].Type);
        printf("    Threshold: %s\n", vs[i].Threshold);
        printf("    Voltage: %2d.%dV\n", (vs[i].CurVolt >> 4), (vs[i].CurVolt & 0x0F));
        printf("    Status Flag: 0x%x\n", vs[i].StatusFlag);
    }
    free(vs);
    return 0;
}
