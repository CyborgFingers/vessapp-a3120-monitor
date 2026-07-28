/*
 * i2_micops.h - RECOVERED Promise in-band SCSI wire format (Vess A3120).
 *
 * SINGLE SOURCE OF TRUTH for the CDB / MicOpCode layer that the Windows stack
 * used to drive the RAID controller + enclosure SEP. Everything here was
 * decompiled from the vendor binaries (fwinterf.dll, pri2apiv4.dll); see
 * docs/RE-fwinterf-pri2api.md for full provenance.
 *
 * ===========================  THE TRANSPORT  ==============================
 * fwinterf!_ib_sendSCSIPassThruDirect issues, per operation:
 *   DeviceIoControl(\\.\scsiN:, IOCTL_SCSI_PASS_THROUGH_DIRECT,
 *                   SCSI_PASS_THROUGH_DIRECT{ CdbLength=16, SenseLen=64, ... })
 * with a 16-byte vendor CDB. The Linux equivalent is /dev/sgN + SG_IO (same
 * CDB, same sense). (fwinterf.dll FUN_180001070 @ RVA 0x1070, high confidence.)
 *
 * ===========================  THE CDB  ====================================
 *   byte 0    = 0xD8          vendor SCSI opcode   (sendCommand forces CDB[0..1]
 *   byte 1    = 0xBA                                = 0xBAD8; fwinterf @0x1cf0)
 *   byte 2..3 = MicOpCode     little-endian u16    (logged as "MicOpCode=%X")
 *   byte 6    = element/selector param  (enclosure id / FRU id / VPD selector)
 *   byte 7    = sub-param               (e.g. LED colour on set)
 *   byte 8..9 = request id/handle, big-endian       (op-specific; CALIBRATE)
 *   byte 10..11 = transfer length, big-endian u16    (== SG dxfer_len)
 *   byte 4..5,12..15 = 0 / op-specific
 * The SG_IO data buffer + dxfer_len carry the page (read) or payload (write);
 * SPTD.DataTransferLength mirrors CDB[10..11]. (pri2apiv4 CDB builders, high
 * confidence for [0..3],[6],[10..11]; [8..9] and remainder CALIBRATE.)
 */
#ifndef I2_MICOPS_H
#define I2_MICOPS_H

/* ---- vendor SCSI framing (RECOVERED, high confidence) ---- */
#define I2_CDB_LEN            16     /* CdbLength in the SPTD              */
#define I2_SENSE_LEN          64     /* SenseInfoLength in the SPTD        */
#define I2_CDB_OP0            0xD8   /* CDB[0] vendor opcode               */
#define I2_CDB_OP1            0xBA   /* CDB[1] (0xBAD8 word, LE)           */
#define I2_CDB_OFF_MICOP      2      /* CDB[2..3] MicOpCode (LE u16)       */
#define I2_CDB_OFF_ELEM       6      /* CDB[6] enclosure id / FRU / sel    */
#define I2_CDB_OFF_SUBPARAM   7      /* CDB[7] sub-param (LED colour)      */
#define I2_CDB_OFF_XFERLEN_BE 10     /* CDB[10..11] transfer length, BE u16*/

/* IOCTL codes fwinterf used, for reference / on-device cross-check only. */
#define I2_WIN_IOCTL_SPTD     0x4d014  /* IOCTL_SCSI_PASS_THROUGH_DIRECT   */
#define I2_WIN_IOCTL_MINIPORT 0x4d008  /* IOCTL_SCSI_MINIPORT ("FASTTRAK") */

/*
 * ---- MicOpCodes (RECOVERED, high confidence) ----
 * All enclosure ops share opcode-family byte 0x0F in CDB[2]; CDB[3] is the
 * sub-opcode. Controller ops use family 0x03. Value == the LE u16 at CDB[2],
 * i.e. (sub << 8) | family. Provenance: pri2apiv4.dll CDB-build immediates.
 */
#define I2_MICOP_GET_CTRL_INFO      0x0103 /* fwinterf discovery  @0x1220     */
#define I2_MICOP_GET_ENCL_INFO      0x000F /* i2_getEnclosureInfo @0x140b0    */
#define I2_MICOP_GET_ENCL_STATUS    0x010F /* i2_getEnclosureStatus @0x14220  */
#define I2_MICOP_SET_LED_FRU        0x130F /* i2_setLedFruInfo    @0x22d70    */
#define I2_MICOP_SET_WDT            0x190F /* i2_setWDTSettings   @0x14880    */
#define I2_MICOP_GET_TERM_SENSOR    0x200F /* i2_get..TerminalSensor @0x222f0 */
#define I2_MICOP_SET_TERM_SENSOR    0x210F /* i2_set..TerminalSensor @0x22440 */
#define I2_MICOP_GET_TERM_ALARM     0x220F /* i2_get..TerminalAlarm  @0x22590 */
#define I2_MICOP_SET_TERM_ALARM     0x230F /* i2_set..TerminalAlarm  @0x226e0 */
#define I2_MICOP_GET_PWR_SWITCH     0x260F /* i2_getEnclosurePowerSwitch      */
#define I2_MICOP_GET_LED_FRU        0x280F /* i2_getLedFruInfo    @0x22ad0    */
#define I2_MICOP_GET_LED_FRU_HDD    0x290F /* i2_getLedFruInfoByHdd @0x22c10  */
#define I2_MICOP_GET_CTRLEX_INFO    0x8003 /* i2_getCtrlexInfo (VPD read)     */
#define I2_MICOP_SET_CTRLEX         0x8103 /* i2_setCtrlexSettings (VPD write)*/

/*
 * ==========================  RAID / ARRAY SURFACE  ========================
 * Phase 2b. Same D8 BA + MicOpCode(LE u16 @ CDB[2..3]) transport. Recovered
 * from pri2apiv4.dll i2_* array/logdrv/phydrv/spare builders (all clean CONCAT
 * immediates in re/out/pri2apiv4_x64.c). See docs/RE-i2-raid.md for per-op
 * provenance (function + RVA + decompiler line). MicOpCode == (sub<<8)|family.
 *
 * Family byte (CDB[2], low byte of MicOpCode):
 *   0x04 = storage config (phydrv/logdrv/array/spare enumerate + create/delete)
 *   0x06 = logical-drive init / background-activity control
 *   0x08 = rebuild
 *   0x09 = synchronize / redundancy-check
 *   0x12 = statistics
 *   0x03 = controller (0x0103 info, done Phase 2), 0x02 = subsystem
 * The sub-opcode (CDB[3]) selects the operation within the family.
 */

/* ---- Storage-config family 0x04 ENUMERATION (READ, high confidence) ---- */
#define I2_MICOP_GET_PHYDRV_INFO    0x0804 /* i2_getPhyDrvInfo    @0x9420 */
#define I2_MICOP_GET_LOGDRV_INFO    0x0B04 /* i2_getLogDrvInfo (list) @0xa020 */
#define I2_MICOP_GET_LOGDRV_ONE     0x0C04 /* i2_getLogDrvInfo (by-id) @0xa020 */
#define I2_MICOP_GET_ARRAY_INFO     0x0504 /* i2_getDiskArrayInfo (list) @0xafd0 */
#define I2_MICOP_GET_ARRAY_ONE      0x0604 /* i2_getDiskArrayInfo (by-id) @0xafd0,
                                            * i2_getDiskArrayConfig @0xa940 */
#define I2_MICOP_GET_SPARE_INFO     0x0204 /* i2_getSpareInfo     @0xc530 */

/* ---- Storage-config family 0x04 LIFECYCLE (WRITE, high confidence) ----
 * DATA-OUT payload. WRONG opcodes here = data loss; every value below is a
 * literal immediate in the decompile (not inferred). Create ops share the
 * commit path FUN_180006780 (MicOpCode 0x0004, per-element 0x120 payload built
 * by FUN_180001210+FUN_180006540 -- payload internals CALIBRATE, see docs). */
#define I2_MICOP_CFG_APPLY          0x0004 /* create array/LD, add spare (commit)
                                            * FUN_180006780 @0x6780            */
#define I2_MICOP_CFG_DELETE         0x0104 /* delete disk array; delete spare
                                            * i2_delDiskArray @0xbc80,
                                            * i2_delSpare @0xca00              */
#define I2_MICOP_LOGDRV_DELETE      0x0404 /* i2_delLogDrv        @0xc1a0      */

/* ---- LD init / background-activity family 0x06 ---- */
#define I2_MICOP_LOGDRV_INIT_START  0x0606 /* i2_startLogDrvInit start @0xd3f0- */
#define I2_MICOP_LOGDRV_INIT_STOP   0x0106 /* i2_startLogDrvInit stop/pause     */
#define I2_MICOP_GET_INIT_PROGRESS  0x0506 /* i2_getLogDrvInitProgress @0xd3f0  */

/* ---- rebuild family 0x08 ---- */
#define I2_MICOP_GET_REBUILD_PROG   0x0508 /* i2_getRebuildProgress @0xcea0     */

/* ---- synchronize / redundancy-check family 0x09 ---- */
#define I2_MICOP_SYNCH_START        0x0109 /* i2_startLogDrvSynch start @0xd900- */
#define I2_MICOP_SYNCH_STOP         0x0209 /* i2_startLogDrvSynch stop/forced    */

/* ---- statistics family 0x12 (READ unless noted) ---- */
#define I2_MICOP_STATS_LOGDRV       0x0412 /* i2_getStatsLogDrv   @0x18760 */
#define I2_MICOP_STATS_LOGDRV_RESET 0x0712 /* i2_getStatsLogDrv reset (WRITE)   */
#define I2_MICOP_STATS_LOGDRV_EXT   0x0912 /* i2_getStatsLogDrv extended variant */
/* PD/controller stats opcodes are computed at runtime in the decompile (not a
 * clean immediate) -> CALIBRATE: see docs/RE-i2-raid.md.
 *   i2_getStatsPhyDrv   @0x18570 : MicOpCode = uVar3 (computed)  CALIBRATE
 *   i2_getStatsController @0x183d0 : dispatcher only            CALIBRATE  */

/* CDB field usage for the RAID surface (recovered):
 *   CDB[6..7] = element id (PD/LD/array), BIG-ENDIAN (byteswapped in builder)
 *   CDB[8]    = type/level selector on phydrv (param_3[2]: 0,1,3,0xff)
 *   CDB[10..11] = transfer length, BIG-ENDIAN (== data buffer length)
 * Per-element response record sizes (from the /record divisors in the builders):
 *   phydrv 0x108 (type 0) / 0x80 (type 1,3) / 0x188 (type 0xff)  [i2_getPhyDrvInfo]
 *   logdrv 0x70   disk-array 0xb8                                              */
#define I2_PHYDRV_REC_FULL   0x108   /* param_3[2]==0    */
#define I2_PHYDRV_REC_BRIEF  0x80    /* param_3[2]==1||3 */
#define I2_PHYDRV_REC_EXT    0x188   /* param_3[2]==0xff */
#define I2_LOGDRV_REC        0x70    /* i2_getLogDrvInfo /0x70 divisor  */
#define I2_ARRAY_REC         0xb8    /* i2_getDiskArrayInfo /0xb8       */
#define I2_INFO_HDR          0x40    /* list-page header before records */

/* Response/request IPC signature word (high 16 bits), pri2apiv4 @0x5530 and
 * libi2bdg i2_initialize struct (0xbaba0001). Windows loopback-IPC only; the
 * collapsed Linux data path (libi2 -> SG_IO) does not need it. Documented so
 * the Phase-3 i2d daemon protocol can reuse it. */
#define I2_IPC_SIGNATURE     0xbaba0001

/* Minimum response page sizes the FW enforces (pri2apiv4 length guards). */
#define I2_ENCL_INFO_MIN     0x88   /* i2_getEnclosureInfo param_5 >= 0x88   */
#define I2_ENCL_STATUS_LEN   0x400  /* i2_getEnclosureStatus fixed 0x400     */

#endif /* I2_MICOPS_H */
