/*
 * asm2106b.c - the 2106BCMD/2106BACK mailbox command cycle.
 * Faithful to FUN_10035680 (send) + FUN_10037350 (recv) in re/out/106b_x86.c.
 */
#include "asm2106b.h"

#include <string.h>

static const uint8_t CMD_SIG[8] = { '2','1','0','6','B','C','M','D' };
static const uint8_t ACK_SIG[8] = { '2','1','0','6','B','A','C','K' };

/* Bounded poll: on the mock the ready value is returned on iter 0; on real
 * hardware each read8 is a PCI transaction (microseconds), so this caps a hang
 * at well under a second. CALIBRATE: raise if a real FW is slower to settle. */
#define ASM_POLL_MAX 200000

static int poll_byte(asm_reg *r, uint32_t off, uint8_t want) {
    for (int i = 0; i < ASM_POLL_MAX; i++) {
        int v = asm_reg_read8(r, off);
        if (v < 0) return v;
        if ((uint8_t)v == want) return ASM_OK;
    }
    return ASM_ETIMEOUT;
}

static uint32_t le32(const uint8_t *b) {
    return (uint32_t)b[0] | (uint32_t)b[1] << 8 | (uint32_t)b[2] << 16 | (uint32_t)b[3] << 24;
}
static void put_le32(uint8_t *b, uint32_t v) {
    b[0] = (uint8_t)v; b[1] = (uint8_t)(v >> 8); b[2] = (uint8_t)(v >> 16); b[3] = (uint8_t)(v >> 24);
}

int asm2106b_command(asm_reg *r, uint8_t opcode, uint8_t *out, uint32_t payload_len,
                     uint8_t *status_out) {
    if (!r || !out) return ASM_EPARAM;

    /* 1. Build the 16-byte command frame. */
    uint8_t cmd[ASM_CMD_LEN] = {0};
    memcpy(cmd, CMD_SIG, 8);
    cmd[8] = opcode;   /* [9..15] already zero */

    /* 2. FW ready. */
    int rc = poll_byte(r, ASM_REG_FW_READY, 1);
    if (rc != ASM_OK) return rc;

    /* 3. Xdata handshake: set then poll. */
    if ((rc = asm_reg_write8(r, ASM_REG_XDATA, 1)) < 0) return rc;
    if ((rc = poll_byte(r, ASM_REG_XDATA, 1)) != ASM_OK) return rc;

    /* 4. Write the command mailbox (LE dwords). */
    for (uint32_t i = 0; i < ASM_CMD_LEN; i += 4)
        if ((rc = asm_reg_write32(r, ASM_REG_MAILBOX + i, le32(cmd + i))) < 0) return rc;

    /* 5. Doorbell: submit then poll for completion (byte == 0). */
    if ((rc = asm_reg_write8(r, ASM_REG_DOORBELL, 1)) < 0) return rc;
    if ((rc = poll_byte(r, ASM_REG_DOORBELL, 0)) != ASM_OK) {
        asm_reg_write8(r, ASM_REG_XDATA, 0);   /* release on timeout (FUN_10035680) */
        return rc;
    }

    /* 6. Read the ack frame (header + payload) as LE dwords. */
    uint32_t ack_len = ASM_ACK_HDR + payload_len;
    uint8_t ack[ASM_ACK_HDR + ASM_PAYLOAD_PORTS] = {0};   /* largest payload */
    if (ack_len > sizeof ack) return ASM_EPARAM;
    for (uint32_t i = 0; i < ack_len; i += 4)
        put_le32(ack + i, asm_reg_read32(r, ASM_REG_MAILBOX + i));

    /* 7. Release. */
    asm_reg_write8(r, ASM_REG_XDATA, 0);

    /* 8. Validate + copy payload. */
    if (memcmp(ack, ACK_SIG, 8) != 0) return ASM_ESIG;
    if (status_out) *status_out = ack[8];
    if (ack[8] != 0) return ASM_ESTATUS;
    memcpy(out, ack + ASM_ACK_HDR, payload_len);
    return ASM_OK;
}

int asm2106b_get_capabilities(asm_reg *r, uint8_t out[ASM_PAYLOAD_CAPS]) {
    return asm2106b_command(r, ASM_OP_GET_CAPABILITIES, out, ASM_PAYLOAD_CAPS, NULL);
}
int asm2106b_get_raid_status(asm_reg *r, uint8_t out[ASM_PAYLOAD_RAID]) {
    return asm2106b_command(r, ASM_OP_GET_RAID_STATUS, out, ASM_PAYLOAD_RAID, NULL);
}
int asm2106b_get_ports_status(asm_reg *r, uint8_t out[ASM_PAYLOAD_PORTS]) {
    return asm2106b_command(r, ASM_OP_GET_PORTS_STATUS, out, ASM_PAYLOAD_PORTS, NULL);
}
