/* getenc - mirror of i2bApiSample_GetEncInfo.c */
#include <stdio.h>
#include "i2bdg_api.h"
#include "tool_common.h"

int main(void) {
    tool_env_mock_install();
    I2bEncData_t enc;
    I2B_API_RET_E ret = i2bApi_getEncInfo(&enc, sizeof enc);
    if (ret != I2B_API_RET_SUCCESS) { printf("Enc info get failed: %.02X\n", ret); return 1; }
    printf("Type %s:\n\n", enc.EncType);
    return 0;
}
