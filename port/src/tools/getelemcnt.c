/* getelemcnt - dump the enclosure element counts (I2bElementCount_t) */
#include <stdio.h>
#include "i2bdg_api.h"
#include "tool_common.h"

int main(void) {
    tool_env_mock_install();
    I2bElementCount_t c;
    I2B_API_RET_E ret = i2bApi_getElementCnt(&c);
    if (ret != I2B_API_RET_SUCCESS) { printf("getElementCnt failed: 0x%.02X\n", ret); return 1; }
    printf("MB=%u Sep=%u Psus=%u CUs=%u\n", c.NumOfMB, c.NumOfSep, c.NumOfPsus, c.NumOfCUs);
    printf("Fans=%u TempSensors=%u VoltSensors=%u AudibleAlarms=%u\n",
           c.NumOfFans, c.NumOfTempSensors, c.NumOfVoltageSensors, c.NumOfAudibleAlarms);
    printf("Batteries=%u HDSlots=%u\n", c.NumOfBatteries, c.NumOfHDSlots);
    return 0;
}
