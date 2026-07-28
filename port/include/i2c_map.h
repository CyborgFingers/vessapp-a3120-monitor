/*
 * i2c_map.h - RE-derived constant map for the Vess A3120 enclosure (SEP).
 *
 * SINGLE SOURCE OF TRUTH for the enclosure PAYLOAD/PAGE semantics recovered
 * from the vendor stack, plus the values that still need confirming on real
 * hardware. Every entry is a calibration knob (see scripts/calibrate.sh).
 *
 * The CDB/MicOpCode wire format lives in i2_micops.h; this file is the layer
 * above it: what the SEP request/response *pages* contain.
 *
 * ============================ TRANSPORT REALITY ============================
 * Phase 2 RE (docs/RE-fwinterf-pri2api.md) resolved the Phase-1 open question:
 * the enclosure is driven IN-BAND, not over a raw I2C node. Each i2bdg_* call
 * becomes an i2_* call (libi2) -> a 16-byte Promise vendor CDB (0xD8 0xBA +
 * MicOpCode) -> SG_IO to the RAID controller -> SEP. The old "direct-SMBus"
 * register model (I2C_MAP_REG_*, I2C_MAP_ADDR_SEP, per-register frame offsets)
 * was a Phase-1 stand-in and is now RETIRED: those registers never existed.
 * Enclosure data instead arrives as PAGES (i2_getEnclosureInfo >= 0x88 bytes,
 * i2_getEnclosureStatus == 0x400 bytes) decoded at the offsets below.
 *
 * Provenance: (from <dll> <func> @ <RVA>)  confidence: high/med/low
 * ==========================================================================
 */
#ifndef I2C_MAP_H
#define I2C_MAP_H

/* ------------------------------------------------------------------ *
 *  RECOVERED - enclosure INFO/STATUS page offsets (real; from prdecode)
 * ------------------------------------------------------------------ */

/* Enclosure model byte -> type string. RESOLVED in Phase 2: prdecode reads it
 * at page offset 0x0e in FOUR decoders (decod_getEnclFanStatus @0x98e0,
 * decod_getEnclNumFan @0xd070, decod_getEnclTemperature @0x9d40,
 * decod_getEnclVoltage @0xa240). confidence: high (offset + mapping). */
#define I2C_MAP_ENC_INFO_OFF_MODEL 0x0e /* RESOLVED (was UNRESOLVED in P1)     */
#define I2C_MAP_ENCBYTE_3U16BAY    'R'  /* 0x52 ENC_TYPE_VESS_3U16BAY          */
#define I2C_MAP_ENCBYTE_2U6BAY     'P'  /* 0x50 ENC_TYPE_VESS_2U6BAY           */
#define I2C_MAP_ENCBYTE_1U4BAY     'W'  /* 0x57 ENC_TYPE_VESS_1U4BAY (A3120 -
                                         * wire-confirmed on the golden box,
                                         * docs/RE-scsi-wire-capture.md)       */
/* prdecode also branches on 'S'(0x53),'T'(0x54),'U'(0x55) for other Vess
 * models (out of scope). Enclosure sub-type u16 lives at page+0x1c. */
#define I2C_MAP_ENC_INFO_OFF_SUBTYPE 0x1c /* confidence: high (prdecode)       */

/* Enclosure STATUS page (0x400) per-element regions. The decoders index from a
 * located enclosure base (param_3) plus cumulative preceding element counts;
 * for the single-enclosure A3120 the base is small. The per-element BASE
 * offsets are RECOVERED (high); the base+count arithmetic is CALIBRATE (needs
 * a real 0x400 status-page dump to pin the count field positions).
 * (from prdecode decod_getEnclFanStatus/Temperature/FanSpeed/Voltage.)      */
#define I2C_MAP_ENC_STATUS_OFF_FANSTS 0x40 /* fan status byte base  @0x98e0   */
#define I2C_MAP_ENC_STATUS_OFF_TEMP   0x42 /* temperature byte base @0x9d40   */
#define I2C_MAP_ENC_STATUS_OFF_SPEED  0x44 /* fan-speed/voltage base @0x9b30  */

/* Element counts live inline in the status page. RECOVERED positions:
 * page[0]=NumFans (decod_getEnclNumFan @0xd070), page[4]=NumTempSensors
 * (decod_getEnclNumTempSensor @0xd610). The temp/volt/fan-speed regions are
 * offset by the running sum of preceding region sizes at page[0,1,2,4,8]
 * (decod_getEncl{Temperature,Voltage,FanSpeed}). Because fan-speed offset ==
 * voltage offset + page[8], page[8] is the voltage region size (== NumVolt),
 * med confidence. Identities of page[1],[2] are CALIBRATE. */
#define I2C_MAP_ENC_CNT_OFF_FANS   0    /* RECOVERED (NumFans)                */
#define I2C_MAP_ENC_CNT_OFF_C1     1    /* RECOVERED sum term; id CALIBRATE   */
#define I2C_MAP_ENC_CNT_OFF_C2     2    /* RECOVERED sum term; id CALIBRATE   */
#define I2C_MAP_ENC_CNT_OFF_TEMP   4    /* RECOVERED (NumTempSensors)         */
#define I2C_MAP_ENC_CNT_OFF_VOLT   8    /* RECOVERED-derived (NumVolt); med   */
#define I2C_MAP_ENC_STATUS_BASE    0    /* param_3 located base; single encl  */
                                        /* -> 0 assumed (CALIBRATE)           */

/* Fan speed is a raw tach byte, NOT a stored RPM (Phase-1 assumed u16 rpm -
 * WRONG). RPM = NUMERATOR / (raw * k); k depends on model+index. For the A3120
 * ('W' = 1U4BAY, golden-confirmed): fans 0..1 -> k=16, fans 2+ -> k=2.
 * (decod_getEnclFanSpeed @0x9b30; the RE first attributed this k table to 'R'
 * before the golden box proved the A3120 model byte is 'W'.)
 * confidence: high (formula + numerator); k table med. */
#define I2C_MAP_FAN_RPM_NUMERATOR   1350000 /* 0x149970                       */
#define I2C_MAP_FAN_K_A3120_LOW     16      /* fans 0,1 on the A3120          */
#define I2C_MAP_FAN_K_A3120_HIGH    2       /* fans 2+  on the A3120          */
#define I2C_MAP_FAN_LOW_COUNT       2       /* boundary index                 */

/* Temperature byte = degrees Celsius directly; F = C*9/5 + 32. Status:
 * cur<warn -> normal; cur<crit -> warning; else critical.
 * (decod_getEnclTemperature/getStringTemperaturStatus.) confidence: high. */

/* Voltage byte packed hi-nibble.lo-nibble volts (e.g. 0x33 = 3.3V).
 * (decod_getStringVoltage @0xa270.) confidence: high. CONFIRMS Phase-1. */

/* Fan/PSU status codes (decod_getStringFanStatus): 0 Functional, 1
 * Malfunction, 2 Not Installed. Matches i2bdg_api.h FAN_STS_*. */

/* ------------------------------------------------------------------ *
 *  RECOVERED - SEP request/response PAYLOAD semantics (from libi2bdg) *
 *  These describe the data buffers exchanged with the SEP and MUST be *
 *  preserved. They are transport-independent (survived Phase 1->2).   *
 * ------------------------------------------------------------------ */

/* LED FRU "type" ids in the i2_*LedFruInfo request.
 * (from libi2bdg set/getRecordLED @0x3b50/0x3a50, set/getSysErrorLED
 * @0x3d70/0x3c50) confidence: high. */
#define I2C_MAP_LED_FRU_RECORD     0x0f /* RECORD LED FRU                     */
#define I2C_MAP_LED_FRU_SYSTEM     0x10 /* SYSTEM LED FRU (carries u16 cause) */

/* Record LED accepts ONLY these colours in FW. (libi2bdg setRecordLED @0x3b50)
 * confidence: high - explicit param==0x80 || param==0x02 guard. */
#define I2C_MAP_RECLED_ALLOW_RED   0x02 /* I2B_LED_STS_RED                    */
#define I2C_MAP_RECLED_ALLOW_OFF   0x80 /* I2B_LED_STS_OFF                    */

/* Panel LED ids setLED accepts; HDD-slot LEDs (0x80..0x8f) are read-only ->
 * setLED returns UNSUPPORTED, getLED uses i2_getLedFruInfoByHdd(slot=id-0x7f).
 * (libi2bdg setLED/getLED @0x4090/0x3e70.) confidence: high. */
#define I2C_MAP_LED_HDD_SLOT_FIRST 0x80
#define I2C_MAP_LED_HDD_SLOT_LAST  0x8f
#define I2C_MAP_LED_HDD_SLOT_BIAS  0x7f /* slot number = led_id - bias (1..16)*/

/* Watchdog request frame bytes. (libi2bdg enable/update/disableWDT
 * @0x4730/0x4820/0x4910 -> i2_setWDTSettings.) The SEP payload frame is
 * byte0=0x00 byte1=WDT_MARKER byte2=ACTION byte3=interval; the CDB additionally
 * carries marker 0xAD @CDB[6] and 0x57 @CDB[7] (pri2apiv4 @0x14880).
 * confidence: high for bytes, med for field meaning. */
#define I2C_MAP_WDT_MARKER         0xad
#define I2C_MAP_WDT_ACTION_ENABLE  0x01
#define I2C_MAP_WDT_ACTION_UPDATE  0x02
#define I2C_MAP_WDT_ACTION_DISABLE 0x03
#define I2C_MAP_WDT_UPDATE_MIN     3    /* sample enforces 3..255; DLL floors */
#define I2C_MAP_WDT_REQ_SIZE       0x4a /* i2_setWDTSettings request length   */
#define I2C_MAP_WDT_CDB_SUBMARK    0x57 /* CDB[7] on WDT set (pri2apiv4)       */

/* Alarm / terminal-sensor payload frame. (libi2bdg set/getTermAlramState
 * @0x4370/0x4270.) confidence: high. size 0x44; type byte 0x10; state u16 @+2.*/
#define I2C_MAP_ALARM_TYPE         0x10
#define I2C_MAP_ALARM_REQ_SIZE     0x44 /* i2_set*TerminalAlarm datalen 0x44  */
#define I2C_MAP_ALARM_STATE_OFF    2    /* u16 state at +2 in the frame       */

/* VPD via i2_getCtrlexInfo / i2_setCtrlexSettings. Selector byte 4=MB, 5=BP;
 * 0x140 ctrlex buffer; VPD data at +0x40; 256-byte window.
 * (libi2bdg get/setMBVPD @0x3470/0x3590, get/setBPVPD @0x3760/0x3880.)
 * confidence: high. */
#define I2C_MAP_VPD_SEL_MB         4
#define I2C_MAP_VPD_SEL_BP         5
#define I2C_MAP_VPD_BUF_SIZE       0x140
#define I2C_MAP_VPD_DATA_OFF       0x40
#define I2C_MAP_VPD_SIZE           256
#define I2C_MAP_VPD_MB_WRITE_MIN   4
#define I2C_MAP_VPD_WRITE_MAX_LEN  16

/* VPD dual 2's-complement 16-bit short-sum checksums, recomputed on write.
 * (libi2bdg setMBVPD @0x3590 / setBPVPD @0x3880.) confidence: med (offsets from
 * decompiler stack recovery). Invariant: sum_LE_u16(body) + checksum == 0. */
#define I2C_MAP_VPD_CK1_OFF        2
#define I2C_MAP_VPD_CK1_BODY_OFF   4
#define I2C_MAP_VPD_CK1_BODY_U16   62
#define I2C_MAP_VPD_CK2_BODY_OFF   128
#define I2C_MAP_VPD_CK2_BODY_U16   63
#define I2C_MAP_VPD_CK2_OFF        254

/* ------------------------------------------------------------------ *
 *  CALIBRATE: UNRESOLVED - confirm on the real A3120 (calibrate.sh)   *
 * ------------------------------------------------------------------ */
/* - The enclosure-status base + which page bytes hold the element counts that
 *   offset the temp/fan-speed/voltage regions (base + Sum(counts) + idx).
 * - Whether the SEP also answers standard SES-2 (sg_ses): if so, fans/LEDs may
 *   be drivable WITHOUT the proprietary MicOpCode. See docs (SES-2 verdict).
 * - The exact SEP LED/WDT/alarm payload-buffer byte layout (CDB positions are
 *   recovered; the DATA-OUT buffer contents are partly FW-defined). */

#endif /* I2C_MAP_H */
