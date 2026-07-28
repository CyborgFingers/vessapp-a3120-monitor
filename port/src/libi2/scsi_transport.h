/*
 * scsi_transport.h - mockable vendor-SCSI transport HAL (the Phase-2 substrate).
 *
 * The Linux equivalent of fwinterf.dll's in-band path: issue a 16-byte vendor
 * CDB to the Promise RAID controller and move a data buffer in/out. On real
 * hardware this is /dev/sgN + the SG_IO ioctl; the mock replays recorded
 * CDB->response fixtures and records every emitted CDB for assertion.
 *
 * This HAL is deliberately general (RAID + OPAS phases reuse it) and owns the
 * single device handle, so a thin serializing daemon (i2d, Phase 3) can wrap it
 * without touching callers. See docs/RE-fwinterf-pri2api.md.
 */
#ifndef SCSI_TRANSPORT_H
#define SCSI_TRANSPORT_H

#include <stdint.h>
#include <stddef.h>
#include "i2_micops.h"

/* Data direction of a command (maps to SG_DXFER_* / SPTD.DataIn). */
typedef enum {
    SCSI_XFER_NONE  = 0,
    SCSI_XFER_READ  = 1,  /* device -> host (get*)  SG_DXFER_FROM_DEV */
    SCSI_XFER_WRITE = 2,  /* host -> device (set*)  SG_DXFER_TO_DEV   */
} scsi_dir;

typedef struct scsi_transport scsi_transport;

struct scsi_transport {
    /*
     * Issue one vendor CDB. cdb is I2_CDB_LEN bytes. For READ, up to data_len
     * bytes are filled into data; for WRITE, data_len bytes are sent. Returns
     * 0 on success (GOOD status), or a negative errno / positive vendor code.
     * On non-zero SCSI status the sense buffer (if any) is populated.
     */
    int (*command)(scsi_transport *self, const uint8_t cdb[I2_CDB_LEN],
                   scsi_dir dir, void *data, uint32_t data_len,
                   uint8_t *sense, uint8_t sense_cap);
    void (*close)(scsi_transport *self);
};

static inline int scsi_cmd(scsi_transport *t, const uint8_t *cdb, scsi_dir dir,
                           void *data, uint32_t len) {
    return t->command(t, cdb, dir, data, len, NULL, 0);
}
static inline void scsi_transport_close(scsi_transport *t) {
    if (t && t->close) t->close(t);
}

/* ---- Real transport: /dev/sgN + SG_IO ----
 * Opens dev_path (e.g. "/dev/sg2"). Returns NULL on open failure. */
scsi_transport *scsi_transport_open_sg(const char *dev_path);

/*
 * Discover the Promise controller by INQUIRY across /dev/sg*. Matches the
 * vendor "Vess" product id the Windows _ib_discover_hba keyed on (also accepts
 * "Promise" vendor). Writes the chosen path into out_path[out_cap] and returns
 * an open transport, or NULL if none found. out_path may be NULL.
 */
scsi_transport *scsi_transport_discover(char *out_path, size_t out_cap);

/* ---- Mock transport (unit tests + off-device tools) ---- */

typedef struct {
    uint8_t  cdb[I2_CDB_LEN];
    scsi_dir dir;
    uint32_t data_len;
    uint8_t  data[512];   /* WRITE payload captured (truncated to 512)      */
} scsi_txn;

typedef struct scsi_mock scsi_mock;

scsi_mock      *scsi_mock_open(void);
scsi_transport *scsi_mock_iface(scsi_mock *m);
void            scsi_mock_free(scsi_mock *m);

/* Seed a canned READ response keyed by MicOpCode (CDB[2..3]). Copied. */
void scsi_mock_seed(scsi_mock *m, uint16_t micopcode,
                    const uint8_t *data, uint32_t len);

/* Recorded command log, in order. */
size_t          scsi_mock_txn_count(const scsi_mock *m);
const scsi_txn *scsi_mock_txn(const scsi_mock *m, size_t idx);
void            scsi_mock_reset(scsi_mock *m);

/* Convenience: MicOpCode of a recorded CDB (LE u16 at CDB[2]). */
static inline uint16_t scsi_txn_micop(const scsi_txn *t) {
    return (uint16_t)(t->cdb[I2_CDB_OFF_MICOP] | t->cdb[I2_CDB_OFF_MICOP + 1] << 8);
}

#endif /* SCSI_TRANSPORT_H */
