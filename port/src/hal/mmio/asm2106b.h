/*
 * asm2106b.h - ASM2106B MMIO command mailbox protocol (RAID/port status).
 *
 * Recovered from re/out/106b_x86.c (106BCommandLib.dll) - see docs/RE-asm2106b.md
 * for provenance and confidence. All constants below are cited to a function.
 *
 * Command frame (16 bytes, written to the mailbox as 4 LE dwords):
 *   [0..7]  "2106BCMD"  (FUN_10035220 writes 32 31 30 36 42 43 4D 44)
 *   [8]     opcode
 *   [9..15] 0
 * Ack frame (read back from the same mailbox):
 *   [0..7]  "2106BACK"
 *   [8]     status (0 = OK)
 *   [9..15] reserved
 *   [16..]  payload  (copied out by the Windows lib from ack+0x10)
 *
 * Handshake (FUN_10035680 send / FUN_10037350 recv, the no-wait path):
 *   poll  reg 0x867 (byte) == 1        FW ready
 *   set   reg 0xd66 (byte)  = 1; poll == 1   Xdata ready
 *   write mailbox dwords at 0x1000 + i*4
 *   set   reg 0xd62 (byte)  = 1; poll == 0   command complete
 *   read  mailbox dwords at 0x1000 + i*4
 *   clear reg 0xd66 (byte)  = 0
 */
#ifndef ASM2106B_H
#define ASM2106B_H

#include <stdint.h>
#include "asm_reg.h"

/* Opcodes (byte 8 of the command frame). */
#define ASM_OP_GET_CAPABILITIES 0x11   /* IFCmdGetCapabilities @10032c35 */
#define ASM_OP_GET_RAID_STATUS  0x15   /* IFCmdGetRAIDStatus    @10032f0f */
#define ASM_OP_GET_PORTS_STATUS 0x17   /* IFCmdGetPortsStatus   @10033450 */
#define ASM_OP_MODE_CHANGE      0xB0   /* IFCmdModeChange @10032947 - WRITE, NOT issued */

/* Payload lengths the Windows lib memcpy's out of ack+0x10. */
#define ASM_PAYLOAD_CAPS  0x1e   /* 30  bytes */
#define ASM_PAYLOAD_RAID  0xa4   /* 164 bytes */
#define ASM_PAYLOAD_PORTS 0x134  /* 308 bytes */

#define ASM_CMD_LEN   16
#define ASM_ACK_HDR   0x10       /* payload offset inside the ack frame */

/* MMIO register offsets (all cited in docs/RE-asm2106b.md). */
#define ASM_REG_FW_READY 0x867   /* FUN_10035680: poll==1 before send */
#define ASM_REG_XDATA    0xd66   /* Xdata-ready handshake             */
#define ASM_REG_DOORBELL 0xd62   /* command submit / poll==0 = done   */
#define ASM_REG_MAILBOX  0x1000  /* command + ack share this window   */

/* Return codes. */
#define ASM_OK          0
#define ASM_ETIMEOUT   -1   /* FW-ready / xdata / doorbell never settled */
#define ASM_ESIG       -2   /* ack signature != "2106BACK"              */
#define ASM_ESTATUS    -3   /* ack status byte != 0 (returns +status too via out) */
#define ASM_EPARAM     -4

/*
 * Issue one read command and copy its payload. payload_len must be the matching
 * ASM_PAYLOAD_* for the opcode; out must hold payload_len bytes. On ASM_ESTATUS
 * the firmware status byte is stored in *status_out (if non-NULL). Returns
 * ASM_OK or a negative ASM_E* code.
 */
int asm2106b_command(asm_reg *r, uint8_t opcode, uint8_t *out, uint32_t payload_len,
                     uint8_t *status_out);

/* Convenience wrappers (payload_len fixed to the recovered size). */
int asm2106b_get_capabilities(asm_reg *r, uint8_t out[ASM_PAYLOAD_CAPS]);
int asm2106b_get_raid_status (asm_reg *r, uint8_t out[ASM_PAYLOAD_RAID]);
int asm2106b_get_ports_status(asm_reg *r, uint8_t out[ASM_PAYLOAD_PORTS]);

#endif /* ASM2106B_H */
