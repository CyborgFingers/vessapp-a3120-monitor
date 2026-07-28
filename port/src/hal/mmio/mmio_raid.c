/*
 * mmio_raid.c - ASM2106B session + RAID read wiring for the port.
 * The command codec (asm2106b.c) is the high-confidence part; the structured
 * RAID-record decode is UNRESOLVED, so the getters report an empty AHCI-mode
 * view once the controller is confirmed and expose the raw payloads.
 */
#include "mmio_raid.h"
#include "i2bdg.h"

#include <string.h>

/* ------------------------------------------------------------------ session */
static asm_reg *g_reg;        /* cached (owned)          */
static asm_reg *g_injected;   /* test override (borrowed) */
static int      g_opened;

void mmio_raid_set_reg(asm_reg *r) { g_injected = r; }

void mmio_raid_reset(void) {
    if (g_reg) { asm_reg_close(g_reg); g_reg = NULL; }
    g_opened = 0;
}

asm_reg *mmio_raid_reg(void) {
    if (g_injected) return g_injected;
    if (g_opened)   return g_reg;
    g_opened = 1;
    g_reg = asm_reg_open();
    return g_reg;
}

/* --------------------------------------------------------------- raw probes */
static int present_rc(int rc) { return rc == ASM_OK || rc == ASM_ESTATUS; }

int mmio_raid_capabilities(uint8_t out[ASM_PAYLOAD_CAPS], uint8_t *status) {
    asm_reg *r = mmio_raid_reg();
    if (!r) return ASM_ETIMEOUT;
    return asm2106b_command(r, ASM_OP_GET_CAPABILITIES, out, ASM_PAYLOAD_CAPS, status);
}
int mmio_raid_raw_raid(uint8_t out[ASM_PAYLOAD_RAID], uint8_t *status) {
    asm_reg *r = mmio_raid_reg();
    if (!r) return ASM_ETIMEOUT;
    return asm2106b_command(r, ASM_OP_GET_RAID_STATUS, out, ASM_PAYLOAD_RAID, status);
}
int mmio_raid_raw_ports(uint8_t out[ASM_PAYLOAD_PORTS], uint8_t *status) {
    asm_reg *r = mmio_raid_reg();
    if (!r) return ASM_ETIMEOUT;
    return asm2106b_command(r, ASM_OP_GET_PORTS_STATUS, out, ASM_PAYLOAD_PORTS, status);
}

int mmio_raid_controller_present(void) {
    uint8_t caps[ASM_PAYLOAD_CAPS], status = 0;
    return present_rc(mmio_raid_capabilities(caps, &status)) ? 1 : 0;
}

/* --------------------------------------------------------- port RAID wiring */
/* Structured RAID-record decode is UNRESOLVED. When the controller answers we
 * report an empty (AHCI-mode) RAID view - honest for this box - rather than
 * invent record offsets. I2API_ERR only if the controller is unreachable. */

I2B_API_RET_E mmio_getDiskArrayInfo(I2bArrayInfo_t *out, unsigned long buflen) {
    if (!out || buflen < sizeof(I2bArrayInfo_t)) return I2B_API_RET_PARAM_ERR;
    if (!mmio_raid_controller_present()) return I2B_API_RET_I2API_ERR;
    out->DACnt = 0;   /* no hardware arrays in AHCI mode (UNRESOLVED decode) */
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E mmio_getLogDrvInfo(I2bLogDrvInfo_t *out, unsigned long buflen) {
    if (!out || buflen < sizeof(I2bLogDrvInfo_t)) return I2B_API_RET_PARAM_ERR;
    if (!mmio_raid_controller_present()) return I2B_API_RET_I2API_ERR;
    out->LDCnt = 0;
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E mmio_getHardDiskDriveInfo(I2bHddInfo_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    (void)buflen;
    /* Per-port decode of GetPortsStatus is UNRESOLVED; the OS enumerates the
     * AHCI disks directly. Report presence only (0 records via the RAID path). */
    if (!mmio_raid_controller_present()) return I2B_API_RET_I2API_ERR;
    return I2B_API_RET_SUCCESS;
}
