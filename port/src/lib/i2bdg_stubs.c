/*
 * i2bdg_stubs.c - symbols outside Phase 1 (enclosure HAL) scope.
 *
 * Present so the library is a complete drop-in (every vendor export resolves),
 * but deliberately NOT implemented here - they belong to later phases:
 *   - events, UART mode, SMART passthru -> later phases
 * Each returns UNSUPPORTED rather than a fabricated result.
 * (RAID/array reads DiskArray/HardDisk/LogDrv/Stats are now real -> i2bdg_raid.c.)
 */
#include "i2bdg_internal.h"

int i2bdg_getEvent(unsigned char a, unsigned short s, unsigned short c, I2bEventInfo_t *b)
    { (void)a; (void)s; (void)c; (void)b; return I2B_API_RET_UNSUPPORTED; }
int i2bdg_setEvent(unsigned short g, unsigned short c, unsigned short d, unsigned char *x)
    { (void)g; (void)c; (void)d; (void)x; return I2B_API_RET_UNSUPPORTED; }

int i2bdg_getUARTMode(unsigned char id, unsigned char *m)
    { (void)id; (void)m; return I2B_API_RET_UNSUPPORTED; }
int i2bdg_setUARTMode(unsigned char id, unsigned char m)
    { (void)id; (void)m; return I2B_API_RET_UNSUPPORTED; }

int i2bdg_SMARTReadData(I2b_smart_info_t *p)        { (void)p; return I2B_API_RET_UNSUPPORTED; }
int i2bdg_SMARTReadSelftestLog(I2b_smart_info_t *p) { (void)p; return I2B_API_RET_UNSUPPORTED; }
int i2bdg_SMARTReadErrorLog(I2b_smart_info_t *p)    { (void)p; return I2B_API_RET_UNSUPPORTED; }
int i2bdg_SMARTStatus(I2b_smart_info_t *p)          { (void)p; return I2B_API_RET_UNSUPPORTED; }
