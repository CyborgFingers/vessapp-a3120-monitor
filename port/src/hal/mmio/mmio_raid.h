/*
 * mmio_raid.h - feed the port's RAID read paths from the ASM2106B MMIO client.
 *
 * On the user's bare box the ASM2106B is bound to generic AHCI (per the hardware
 * survey), so there are no hardware-RAID arrays/logical-drives to enumerate via
 * the vendor RAID command - the OS sees the SATA disks directly. What the MMIO
 * client CAN do with high confidence is run the recovered command cycle and
 * validate the ack; the per-record decode of the GetRAIDStatus (164B) and
 * GetPortsStatus (308B) payloads is UNRESOLVED (no struct definition survives in
 * 106BCommandLib - see docs/RE-asm2106b.md), so the raw payloads are exposed for
 * on-device calibration and the structured getters report an empty (AHCI-mode)
 * RAID view once the controller is confirmed present.
 */
#ifndef MMIO_RAID_H
#define MMIO_RAID_H

#include "i2bdg_api.h"
#include "asm_reg.h"
#include "asm2106b.h"

/* asm_reg session (mirrors i2bdg_session / smbus_encl). */
void     mmio_raid_set_reg(asm_reg *r);   /* inject (borrowed); NULL clears */
asm_reg *mmio_raid_reg(void);             /* lazy asm_reg_open(); NULL if none */
void     mmio_raid_reset(void);

/* 1 if the controller answers GetCapabilities with a valid 2106BACK, else 0. */
int mmio_raid_controller_present(void);

/* Raw payload accessors (calibration / verify-mmio.sh). Return asm2106b rc. */
int mmio_raid_capabilities(uint8_t out[ASM_PAYLOAD_CAPS], uint8_t *status);
int mmio_raid_raw_raid(uint8_t out[ASM_PAYLOAD_RAID], uint8_t *status);
int mmio_raid_raw_ports(uint8_t out[ASM_PAYLOAD_PORTS], uint8_t *status);

/* Port RAID read wiring (dispatched from i2bdg_raid.c when VESSAPP_BACKEND=mmio). */
I2B_API_RET_E mmio_getDiskArrayInfo(I2bArrayInfo_t *out, unsigned long buflen);
I2B_API_RET_E mmio_getHardDiskDriveInfo(I2bHddInfo_t *out, unsigned long buflen);
I2B_API_RET_E mmio_getLogDrvInfo(I2bLogDrvInfo_t *out, unsigned long buflen);

#endif /* MMIO_RAID_H */
