/*
 * encl_backend.h - selects which enclosure HAL the i2bdg_* / i2bApi_* calls use.
 *
 * The Vess appliance answers enclosure ops IN-BAND over a vendor SCSI CDB
 * (the SCSI transport, unchanged). The user's bare A3120/Proxmox boxes reach
 * the same hardware natively two ways, so the port picks a backend at runtime:
 *
 *   VESSAPP_BACKEND = scsi  (default) - vendor SCSI CDB path (SEP over the
 *                                       Promise controller). Unchanged.
 *                     smbus           - LEDs (PCA9555 0x20/0x21) + fans/sensors
 *                                       (nct7802y 0x2e / nct6776 hwmon) over
 *                                       /dev/i2c-N. See src/hal/smbus/.
 *                     mmio            - RAID/port status from the ASM2106B via
 *                                       its MMIO command mailbox. See src/hal/mmio/.
 *
 * The selector is read once (getenv) and cached. Default scsi means every
 * existing caller and unit test behaves exactly as before.
 */
#ifndef ENCL_BACKEND_H
#define ENCL_BACKEND_H

typedef enum {
    ENCL_SCSI  = 0,
    ENCL_SMBUS = 1,
    ENCL_MMIO  = 2,
} encl_backend_t;

/* Active backend (VESSAPP_BACKEND, cached). Defaults to ENCL_SCSI. */
encl_backend_t encl_backend(void);

/* Test override; pass a negative value to clear the cache and re-read env. */
void encl_backend_set(int b);

#endif /* ENCL_BACKEND_H */
