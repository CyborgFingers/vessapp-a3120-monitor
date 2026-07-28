/*
 * i2bdg_session.c - process-global enclosure connection (lazy open).
 * Analog of the Windows libi2bdg login helper FUN_1800010d0 @ 0x10d0
 * (i2_initialize signature 0xbaba0001, client tag "i2bdg").
 */
#include "i2bdg_internal.h"

#include <stdlib.h>
#include <errno.h>

static i2_handle *g_i2;        /* cached connection (owned or borrowed)      */
static i2_handle *g_injected;  /* test/calibration override (borrowed)       */
static int        g_opened;    /* attempted real discovery already           */

void i2bdg_session_set_transport(scsi_transport *t) {
    if (g_injected) { i2_dispose(g_injected); g_injected = NULL; }
    if (t) g_injected = i2_initialize_borrow(t); /* does not own t */
}

void i2bdg_session_reset(void) {
    if (g_i2) { i2_dispose(g_i2); g_i2 = NULL; }
    g_opened = 0;
}

i2_handle *i2bdg_session_i2(void) {
    if (g_injected) return g_injected;
    if (g_opened)   return g_i2;
    g_opened = 1;

    const char *dev = getenv("I2BDG_SG_DEV");
    if (dev && *dev) {
        scsi_transport *t = scsi_transport_open_sg(dev);
        g_i2 = t ? i2_initialize(t) : NULL;
    } else {
        g_i2 = i2_initialize_discover();
    }
    return g_i2;
}

int i2bdg_map_i2_error(int rc) {
    if (rc == I2_OK) return I2B_API_RET_SUCCESS;
    if (rc == -ENOMEM) return I2B_API_RET_MEMALLOC_ERR;
    return I2B_API_RET_I2API_ERR; /* could not reach the enclosure engine */
}
