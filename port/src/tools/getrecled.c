/* getrecled - mirror of i2bApiSample_GetRecLed.c */
#include <stdio.h>
#include "i2bdg_api.h"
#include "tool_common.h"

int main(void) {
    tool_env_mock_install();
    unsigned char c = 0;
    i2bdg_getRecordLED(&c);
    printf("\n Record LED is: %s\n", tool_led_colour_name(c));
    return 0;
}
