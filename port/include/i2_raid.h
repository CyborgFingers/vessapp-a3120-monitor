/*
 * i2_raid.h - libi2 RAID / array-management surface (Phase 2b).
 *
 * Thin wrappers that build the recovered storage-config CDBs (i2_micops.h
 * family 0x04 / 0x06 / 0x08 / 0x09 / 0x12) over the Phase-2 substrate, plus the
 * recovered RESPONSE-PAGE record offsets used by the parsers. Everything here is
 * RECOVERED from pri2apiv4.dll (CDBs) and libi2bdg.dll (record offsets); see
 * docs/RE-i2-raid.md for per-op provenance (function + RVA + decompiler line).
 *
 * WRITE-PATH SAFETY: the create/delete/init/synch wrappers marshal the recovered
 * CDB only. They are exercised by mock tests; never point them at a real device
 * without a Windows SCSI-trace confirmation of the DATA-OUT payload (CALIBRATE).
 */
#ifndef I2_RAID_H
#define I2_RAID_H

#include "i2.h"

/* ---- list-page framing (RECOVERED, high confidence) ---- */
#define I2R_PAGE_HDR         0x40   /* records begin at page+0x40             */
#define I2R_PAGE_LEN_OFF     0x24   /* payload byte length u32 (phydrv/logdrv/stats) */
#define I2R_PAGE_LEN_OFF_ARR 0x20   /* payload byte length u32 (disk array)   */

/* ---- per-record strides (RECOVERED) ---- */
#define I2R_REC_PHYDRV       0x188  /* verbosity 0xff fixed record            */
#define I2R_REC_LOGDRV       0xC8   /* decod_locateBufferLogDrvInfo +200      */
#define I2R_REC_ARRAY        0xB8   /* i2bApi_getDiskArrayInfo i*0xb8         */
#define I2R_REC_STATS        0x60   /* i2bApi_getStatsLogDrv i*0x18 uints     */

/* ---- physical-drive record offsets (I2bHddInfo_t source) ---- */
#define I2R_PD_ID            0x04   /* u16, +1 == PDId                        */
#define I2R_PD_ARRAYID       0x06   /* u16 (valid when opflag != 0)           */
#define I2R_PD_ENCID         0x14   /* u16, +1 == enclosureId                 */
#define I2R_PD_SLOT          0x16   /* u16                                    */
#define I2R_PD_BLOCKSIZE     0x30   /* u64                                    */
#define I2R_PD_NUMBLOCKS     0x48   /* u16; confCapacity = blocks*blocksize   */
#define I2R_PD_OPFLAG        0x54   /* u32 DEVICE_* masks                     */
#define I2R_PD_DRVTYPE       0x66   /* u16                                    */
#define I2R_PD_MODEL         0x68   /* 40-byte string                         */
#define I2R_PD_TEMP          0xb5   /* u8 degrees C                           */
#define I2R_PD_VERBOSITY_EXT 0xff   /* CDB[8] selector -> 0x188 records       */

/* ---- logical-drive record offsets (I2bLogDrvData_t source) ---- */
#define I2R_LD_ID            0x00   /* u16                                    */
#define I2R_LD_RAIDLVL       0x02   /* u8, already I2BAPI_LDRAIDLVL_*          */
#define I2R_LD_OPFLAG        0x04   /* u32 LOGDRV_* masks                     */
#define I2R_LD_CACHEFLAGS    0x08   /* u32 -> CachePolicy                     */
#define I2R_LD_CAPBLOCKS     0x0c   /* u64 blocks (<<9 == bytes)              */
#define I2R_LD_STRIPESECT    0x2c   /* u32 sectors (<<9 == bytes)             */
#define I2R_LD_ARRAYID       0x30   /* u16                                    */
#define I2R_LD_ALIAS         0x34   /* 32-byte string                         */
#define I2R_LD_SYNC_BIT      0x12   /* SYNCed = ~(OpFlag>>0x12)&1             */

/* ---- disk-array record offsets (I2bDiskArrayData_t source) ---- */
#define I2R_DA_ID            0x00   /* u16                                    */
#define I2R_DA_NUMHDD        0x2c   /* u16                                    */

/* ---- LD-stats record offsets (I2StatsLogicalDrive_t source) ---- */
#define I2R_ST_LDID          0x00   /* u8                                     */
#define I2R_ST_BLOCKS        0x08   /* u64 (<<9)                              */
#define I2R_ST_RBLOCKS       0x10   /* u64 (<<9)                              */
#define I2R_ST_WBLOCKS       0x18   /* u64 (<<9)                              */
#define I2R_ST_ERRORS        0x20   /* u32                                    */
#define I2R_ST_RERRORS       0x24   /* u32                                    */
#define I2R_ST_WERRORS       0x28   /* u32                                    */
#define I2R_ST_NRWERRORS     0x2c   /* u32                                    */
#define I2R_ST_IOREQ         0x30   /* u64                                    */
#define I2R_ST_RIOREQ        0x38   /* u64                                    */
#define I2R_ST_WIOREQ        0x40   /* u64                                    */
#define I2R_ST_NRWREQ        0x48   /* u64                                    */
#define I2R_ST_STARTTIME     0x50   /* u64                                    */
#define I2R_ST_COLLTIME      0x58   /* u64                                    */

/* =================  ENUMERATION reads (family 0x04 / 0x12)  ============== */
/* start_id = first element id to return (0 == from the beginning). page/len is
 * the response buffer; len is echoed at CDB[10..11] BE. Return 0 == I2_OK. */
int i2_raid_getPhyDrvInfo  (i2_handle *h, uint16_t start_id, void *page, uint32_t len);
int i2_raid_getLogDrvInfo  (i2_handle *h, uint16_t start_id, void *page, uint32_t len);
int i2_raid_getDiskArrayInfo(i2_handle *h, uint16_t start_id, void *page, uint32_t len);
int i2_raid_getSpareInfo   (i2_handle *h, uint16_t start_id, void *page, uint32_t len);
int i2_raid_getStatsLogDrv (i2_handle *h, void *page, uint32_t len);

/* =================  LIFECYCLE writes (mock-tested only)  ================= */
/* Create (apply-config, MicOpCode 0x0004): the payload is the recovered but
 * NOT-fully-decoded per-element config block (CALIBRATE). Caller supplies it. */
int i2_raid_applyConfig    (i2_handle *h, const void *payload, uint32_t len);
/* Delete: id at CDB[6..7] BE, count=1. Array/spare share 0x0104; LD is 0x0404. */
int i2_raid_deleteDiskArray(i2_handle *h, uint16_t array_id);
int i2_raid_deleteSpare    (i2_handle *h, uint16_t spare_id);
int i2_raid_deleteLogDrv   (i2_handle *h, uint16_t ld_id);
/* Background ops: init (family 0x06), synch/redundancy-check (family 0x09). */
int i2_raid_initLogDrv     (i2_handle *h, uint16_t ld_id, int start);
int i2_raid_synchLogDrv    (i2_handle *h, uint16_t ld_id, int start);

#endif /* I2_RAID_H */
