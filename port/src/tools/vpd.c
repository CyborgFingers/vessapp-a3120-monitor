/* vpd - mirror of vpdrw.c
 * usage: vpd r mb|bp [off_hex len]        (read; default whole 256B)
 *        vpd w mb|bp <off_hex> <len> <hexbytes...>   (write, len<=16) */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "i2bdg_api.h"
#include "i2bdg.h"
#include "tool_common.h"

static void help(void) {
    printf("usage:\n  vpd r mb|bp [off_hex len]\n  vpd w mb|bp <off_hex> <len> <hexbyte>...\n");
}

int main(int argc, char *argv[]) {
    tool_env_mock_install();
    if (argc < 3) { help(); return 1; }
    int is_read = (argv[1][0] == 'r' || argv[1][0] == 'R');
    int is_bp;
    if      (!strcmp(argv[2], "mb") || !strcmp(argv[2], "MB")) is_bp = 0;
    else if (!strcmp(argv[2], "bp") || !strcmp(argv[2], "BP")) is_bp = 1;
    else { printf("Invalid type (mb|bp)\n"); return 1; }

    int off = 0, len = 256;
    unsigned char data[256] = {0};

    if (argc > 3) {
        off = (int)strtol(argv[3], NULL, 16);
        if (argc < 5) { printf("must specify length\n"); return 1; }
        len = atoi(argv[4]);
    }

    if (is_read) {
        int ret = is_bp ? i2bdg_getBPVPD(data, off, len) : i2bdg_getMBVPD(data, off, len);
        if (ret != I2BDG_ERR_SUCCESS) { printf("I2 process failed (0x%02X)\n", ret); return 1; }
        printf("Dump read vpd data:\n");
        for (int i = 0; i < len; i++) {
            printf("%02x ", data[i]);
            if (i > 1 && i % 16 == 15) printf("\n");
        }
        printf("\n");
        return 0;
    }

    /* write: need len bytes */
    if (argc - 5 != len) { printf("input write bytes count mismatch\n"); return 1; }
    for (int i = 0; i < len; i++) data[i] = (unsigned char)strtol(argv[5 + i], NULL, 16);
    int ret = is_bp ? i2bdg_setBPVPD(data, off, len) : i2bdg_setMBVPD(data, off, len);
    if (ret != I2BDG_ERR_SUCCESS) { printf("I2 process failed (0x%02X)\n", ret); return 1; }
    printf("VPD write OK (%d bytes at 0x%02x)\n", len, off);
    return 0;
}
