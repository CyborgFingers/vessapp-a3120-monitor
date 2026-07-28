/*
 * i2.h - libi2: the in-band i2 engine (Linux port of the pri2apiv4 i2_* path).
 *
 * Builds the recovered Promise vendor CDBs (see i2_micops.h) and issues them
 * through a scsi_transport (real SG_IO or mock). This is the Phase-2 substrate
 * the enclosure (libi2bdg) and later RAID/OPAS layers ride on.
 *
 * Ownership: an i2_handle wraps one scsi_transport. The handle is NOT
 * thread-safe by itself - the Phase-3 i2d daemon is the serialization point
 * (Windows used a shared service; here a clean handle boundary is enough).
 *
 * Return convention: 0 == success (I2_OK); negative == transport/errno;
 * positive == vendor/SCSI status. Callers map these into the i2bdg space.
 */
#ifndef I2_H
#define I2_H

#include <stdint.h>
#include "scsi_transport.h"

#define I2_OK 0

typedef struct i2_handle i2_handle;

/* Wrap a transport (takes ownership: i2_dispose closes it). */
i2_handle *i2_initialize(scsi_transport *t);
/* Wrap a transport WITHOUT taking ownership (tests/calibration inject a mock
 * they free themselves). i2_dispose frees the handle but not the transport. */
i2_handle *i2_initialize_borrow(scsi_transport *t);
/* Discover the Promise controller on /dev/sg* and wrap it. NULL if not found. */
i2_handle *i2_initialize_discover(void);
void       i2_dispose(i2_handle *h);

scsi_transport *i2_transport(i2_handle *h);   /* borrow, for diagnostics */

/*
 * Low-level primitive: emit one vendor CDB.
 *   CDB = D8 BA <micop LE> .. <elem@6> <subparam@7> .. <len BE @10..11> ..
 * dir selects data direction; data/len is the page (READ) or payload (WRITE).
 * Every enclosure call below is a thin wrapper over this.
 */
int i2_fw_cmd(i2_handle *h, uint16_t micopcode, uint8_t elem, uint8_t subparam,
              scsi_dir dir, void *data, uint32_t len);

/* RAID/array variant: id = BE u16 @ CDB[6..7], sel @ CDB[8], count @ CDB[14].
 * Used by the RAID surface (libi2 i2_raid.c). See docs/RE-i2-raid.md. */
int i2_fw_cmd_ex(i2_handle *h, uint16_t micopcode, uint16_t id, uint8_t sel,
                 uint8_t count, scsi_dir dir, void *data, uint32_t len);

/* ---- Enclosure read pages ---- */
int i2_getEnclosureInfo(i2_handle *h, void *page, uint32_t len);   /* >= 0x88 */
int i2_getEnclosureStatus(i2_handle *h, void *page, uint32_t len); /* 0x400   */

/* ---- LED FRU ---- */
int i2_getLedFruInfo(i2_handle *h, uint8_t fru_id, void *out, uint32_t len);
int i2_getLedFruInfoByHdd(i2_handle *h, uint8_t slot, void *out, uint32_t len);
int i2_setLedFruInfo(i2_handle *h, uint8_t fru_id, uint8_t color, uint16_t cause);

/* ---- Terminal alarm (buzzer) + sensor ---- */
int i2_getEnclosureTerminalAlarmSettings(i2_handle *h, void *out, uint32_t len);
int i2_setEnclosureTerminalAlarmSettings(i2_handle *h, const void *in, uint32_t len);
int i2_getEnclosureTerminalSensorSettings(i2_handle *h, void *out, uint32_t len);

/* ---- Watchdog ---- */
int i2_setWDTSettings(i2_handle *h, const void *frame, uint32_t len);

/* ---- Controller-extended info (VPD MB=4 / BP=5 selector) ---- */
int i2_getCtrlexInfo(i2_handle *h, uint8_t selector, void *out, uint32_t len);
int i2_setCtrlexSettings(i2_handle *h, uint8_t selector, const void *in, uint32_t len);

#endif /* I2_H */
