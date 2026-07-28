/*
 * asmprobe - READ-ONLY ASM2106B status dump for on-device validation.
 *
 * Opens the ASM2106B register window (VESSAPP_MMIO_ACCESS=config|devmem|bar5|
 * ioport, VESSAPP_ASM_PCI=<slot>) and issues ONLY the non-destructive GET
 * commands (GetCapabilities/GetRAIDStatus/GetPortsStatus), hex-dumping each ack.
 *
 *   config (default) PCI cfg 0xD0/0xD4 backdoor (the IFCmd* mode-0 path)
 *   devmem           /dev/mem @ BAR5-phys, len 0x2000 - faithful MapAsmIO port
 *                    (the path raidmon.exe runs; best candidate on this box)
 *   bar5             /sys/.../resource5 (capped at the reported BAR size)
 *   ioport           EXPERIMENTAL x86 I/O-port index/data probe (UNPROVEN)
 *
 * It performs NO destructive writes: the only register writes are the transient
 * command-mailbox handshake required to solicit a status read (never
 * IFCmdModeChange). raidmon.exe itself issues no enable step. Safe to run on a
 * live box. Needs root (PCI config / /dev/mem / iopl).
 */
#include <stdio.h>
#include "asm_reg.h"
#include "asm2106b.h"

/* guiSupport @100328a2 polls internal reg 0x842 for (v&4)==4 && (v&8)==0 as the
 * vendor-interface-ready gate. Read-only; a useful liveness signal per access. */
#define ASM_REG_GUI_READY 0x842

static void hexdump(const char *tag, const uint8_t *b, int n) {
    printf("%s (%d bytes):\n", tag, n);
    for (int i = 0; i < n; i++) {
        printf("%02x%s", b[i], (i % 16 == 15 || i == n - 1) ? "\n" : " ");
    }
}

static void run(asm_reg *r, const char *name, uint8_t op, int plen) {
    uint8_t out[ASM_PAYLOAD_PORTS] = {0};
    uint8_t status = 0xff;
    int rc = asm2106b_command(r, op, out, (uint32_t)plen, &status);
    printf("== %s (opcode 0x%02x) rc=%d status=0x%02x ==\n", name, op, rc, status);
    if (rc == ASM_OK) hexdump("payload", out, plen);
    else if (rc == ASM_ETIMEOUT) printf("(FW handshake timeout - controller not answering)\n");
    else if (rc == ASM_ESIG)     printf("(ack signature mismatch)\n");
    printf("\n");
}

int main(void) {
    asm_reg *r = asm_reg_open();
    if (!r) {
        fprintf(stderr, "asmprobe: cannot open ASM2106B register window.\n"
                        "  need root; set VESSAPP_ASM_PCI (default 0000:02:00.0)\n"
                        "  and VESSAPP_MMIO_ACCESS=config|devmem|bar5|ioport (default config).\n"
                        "  (devmem may be refused if ahci owns BAR5 + IO_STRICT_DEVMEM)\n");
        return 1;
    }
    printf("asmprobe: READ-ONLY GET commands only (no ModeChange).\n\n");

    /* Read-only liveness probe: the guiSupport gate reg (0x842). If the vendor
     * window isn't decoded at the chosen base this reads 0x00/0xff. */
    int gui = asm_reg_read8(r, ASM_REG_GUI_READY);
    if (gui < 0)
        printf("guiSupport reg 0x842: read error %d\n\n", gui);
    else
        printf("guiSupport reg 0x842 = 0x%02x  (ready wants bit2=1,bit3=0 -> %s)\n\n",
               gui, ((gui & 4) == 4 && (gui & 8) == 0) ? "READY" : "not ready");

    run(r, "GetCapabilities", ASM_OP_GET_CAPABILITIES, ASM_PAYLOAD_CAPS);
    run(r, "GetRAIDStatus",   ASM_OP_GET_RAID_STATUS,  ASM_PAYLOAD_RAID);
    run(r, "GetPortsStatus",  ASM_OP_GET_PORTS_STATUS, ASM_PAYLOAD_PORTS);
    asm_reg_close(r);
    return 0;
}
