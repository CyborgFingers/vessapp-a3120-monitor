/*
 * i2bdg_internal.h - shared internals for the libi2bdg reimplementation.
 *
 * The public surface is include/i2bdg_api.h (vendor-verbatim). Everything here
 * is private. Phase 2: the backend is now libi2 (i2_* over SG_IO), replacing
 * the Phase-1 direct-SMBus stand-in.
 */
#ifndef I2BDG_INTERNAL_H
#define I2BDG_INTERNAL_H

#include "i2.h"
#include "i2bdg_api.h"
#include "i2bdg.h"     /* I2BDG_ERR_* generic return codes */

/*
 * Session = the process-global enclosure connection. Mirrors the Windows
 * libi2bdg lazy-login helper (FUN_1800010d0 @ 0x10d0), which on first API call
 * did i2_initialize()+i2_login() (signature 0xbaba0001, tag "i2bdg") and cached
 * a client handle. Here it lazily discovers the Promise controller on /dev/sg*.
 *
 * Resolution order for the real handle:
 *   1. a transport injected by i2bdg_session_set_transport() (tests/calibration)
 *   2. env I2BDG_SG_DEV (e.g. "/dev/sg2")
 *   3. INQUIRY discovery across /dev/sg* (matches Promise "Vess")
 */
i2_handle *i2bdg_session_i2(void);              /* NULL if no controller found  */
void       i2bdg_session_set_transport(scsi_transport *t); /* test injection    */
void       i2bdg_session_reset(void);           /* drop cached handle (tests)   */

/* Map an i2/errno failure onto the vendor return-code space. A missing handle
 * is I2API error (matches Windows: failure to reach the engine). */
int i2bdg_map_i2_error(int rc);

/* Big-endian helper for parsing SEP pages. */
static inline unsigned be16(const unsigned char *p) {
    return (unsigned)p[0] << 8 | p[1];
}

#endif /* I2BDG_INTERNAL_H */
