/* gettmpr - mirror of i2bApiSample_GetTmpr.c */
#include <stdio.h>
#include <stdlib.h>
#include "i2bdg_api.h"
#include "tool_common.h"

int main(void) {
    tool_env_mock_install();
    I2bElementCount_t cnt;
    if (i2bApi_getElementCnt(&cnt) != I2B_API_RET_SUCCESS) { printf("Sensor count get failed\n"); return 1; }
    I2bThermalSensorData_t *ts = calloc(cnt.NumOfTempSensors ? cnt.NumOfTempSensors : 1, sizeof *ts);
    I2B_API_RET_E ret = i2bApi_getThermalSensorInfo(ts, (unsigned long)cnt.NumOfTempSensors * sizeof *ts);
    if (ret != I2B_API_RET_SUCCESS) { printf("Thermal sensor data get failed: %.02X\n", ret); free(ts); return 1; }
    for (int i = 0; i < cnt.NumOfTempSensors; i++) {
        printf("Temperate %2u:\n", ts[i].SensorId);
        printf("    Location: %s\n", ts[i].Location);
        printf("    Warning Threshold: %dC/%dF\n", ts[i].WarnThreshold, ts[i].WarnThreshold*9/5 + 32);
        printf("    Critical Threshold: %dC/%dF\n", ts[i].CritThreshold, ts[i].CritThreshold*9/5 + 32);
        printf("    Temperature: %dC/%dF\n", ts[i].CurTemp, ts[i].CurTemp*9/5 + 32);
        printf("    Status: 0x%x\n", ts[i].StatusFlag);
    }
    free(ts);
    return 0;
}
