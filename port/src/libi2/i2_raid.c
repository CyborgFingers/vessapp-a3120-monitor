/*
 * i2_raid.c - libi2 RAID / array-management CDB marshalling (Phase 2b).
 *
 * Each function builds one recovered storage-config CDB (see i2_micops.h +
 * docs/RE-i2-raid.md) and issues it through the shared scsi_transport via
 * i2_fw_cmd_ex. No response parsing here - that lives in i2bdg_raid.c against
 * the documented structs.
 *
 * READ ops (enumeration) are safe on real hardware. WRITE ops (create/delete/
 * init/synch) are marshalling-only and mock-tested; the create DATA-OUT payload
 * layout is CALIBRATE (never send to a real device unconfirmed).
 */
#include "i2_raid.h"

/* ---- enumeration (READ) ---- */
int i2_raid_getPhyDrvInfo(i2_handle *h, uint16_t start_id, void *page, uint32_t len) {
    /* CDB[8] = 0xff verbosity -> fixed 0x188 records (matches the libi2bdg consumer). */
    return i2_fw_cmd_ex(h, I2_MICOP_GET_PHYDRV_INFO, start_id,
                        I2R_PD_VERBOSITY_EXT, 0, SCSI_XFER_READ, page, len);
}
int i2_raid_getLogDrvInfo(i2_handle *h, uint16_t start_id, void *page, uint32_t len) {
    return i2_fw_cmd_ex(h, I2_MICOP_GET_LOGDRV_INFO, start_id, 0, 0,
                        SCSI_XFER_READ, page, len);
}
int i2_raid_getDiskArrayInfo(i2_handle *h, uint16_t start_id, void *page, uint32_t len) {
    return i2_fw_cmd_ex(h, I2_MICOP_GET_ARRAY_INFO, start_id, 0, 0,
                        SCSI_XFER_READ, page, len);
}
int i2_raid_getSpareInfo(i2_handle *h, uint16_t start_id, void *page, uint32_t len) {
    return i2_fw_cmd_ex(h, I2_MICOP_GET_SPARE_INFO, start_id, 0, 0,
                        SCSI_XFER_READ, page, len);
}
int i2_raid_getStatsLogDrv(i2_handle *h, void *page, uint32_t len) {
    return i2_fw_cmd_ex(h, I2_MICOP_STATS_LOGDRV, 0, 0, 0,
                        SCSI_XFER_READ, page, len);
}

/* ---- lifecycle (WRITE, mock-tested only) ----
 * These change on-disk RAID state. Marshalling is separated from the read path
 * on purpose; callers gate them behind an explicit confirmation. */
int i2_raid_applyConfig(i2_handle *h, const void *payload, uint32_t len) {
    /* MicOpCode 0x0004; DATA-OUT payload internals CALIBRATE: UNRESOLVED. */
    return i2_fw_cmd_ex(h, I2_MICOP_CFG_APPLY, 0, 0, 0,
                        SCSI_XFER_WRITE, (void *)payload, len);
}
int i2_raid_deleteDiskArray(i2_handle *h, uint16_t array_id) {
    return i2_fw_cmd_ex(h, I2_MICOP_CFG_DELETE, array_id, 0, 1,
                        SCSI_XFER_WRITE, NULL, 0);
}
int i2_raid_deleteSpare(i2_handle *h, uint16_t spare_id) {
    /* same op as array-delete (unconfigure PD), recovered i2_delSpare @0xca00. */
    return i2_fw_cmd_ex(h, I2_MICOP_CFG_DELETE, spare_id, 0, 1,
                        SCSI_XFER_WRITE, NULL, 0);
}
int i2_raid_deleteLogDrv(i2_handle *h, uint16_t ld_id) {
    return i2_fw_cmd_ex(h, I2_MICOP_LOGDRV_DELETE, ld_id, 0, 1,
                        SCSI_XFER_WRITE, NULL, 0);
}
int i2_raid_initLogDrv(i2_handle *h, uint16_t ld_id, int start) {
    uint16_t op = start ? I2_MICOP_LOGDRV_INIT_START : I2_MICOP_LOGDRV_INIT_STOP;
    return i2_fw_cmd_ex(h, op, ld_id, 0, 1, SCSI_XFER_WRITE, NULL, 0);
}
int i2_raid_synchLogDrv(i2_handle *h, uint16_t ld_id, int start) {
    uint16_t op = start ? I2_MICOP_SYNCH_START : I2_MICOP_SYNCH_STOP;
    return i2_fw_cmd_ex(h, op, ld_id, 0, 1, SCSI_XFER_WRITE, NULL, 0);
}
