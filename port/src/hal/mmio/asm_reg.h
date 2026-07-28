/*
 * asm_reg.h - mockable register access to the ASM2106B command window.
 *
 * The 106BCommandLib reaches the ASM2106B's internal register space (handshake
 * regs 0x867/0xd62/0xd66 + the command/ack mailbox at 0x1000) TWO ways; both are
 * recovered from re/out/106b_x86.c (see docs/RE-asm2106b.md). raidmon.exe uses
 * the memory-mapped path (IFCmd* mode arg = 1, FUN_00402330/004024a0):
 *
 *   (a) physical MMIO on BAR5  <-- PRIMARY (the path raidmon.exe actually runs).
 *       GetASM2106BMmioBase (@100323e8) reads BAR5 from PCI cfg 0x24-0x27;
 *       FUN_10035bc0/10036290 touch BAR5-phys + {0x867,0xd62,0xd66,0x1000} via
 *       MapAsmIO+ReadMEM/WriteMEM. AsmIo.sys backs those with MmMapIoSpace +
 *       READ_REGISTER_x/WRITE_REGISTER_x - i.e. genuine MMIO on the RAW physical
 *       BAR5 address, independent of the OS BAR-size view. The Linux-faithful
 *       equivalent is /dev/mem (NOT /resource5, which is capped at the BAR size).
 *   (b) PCI config-space index/data backdoor  <-- ALTERNATE (IFCmd* mode arg = 0).
 *       FUN_100363c0/10036520/10036830/10037460: write the byte-offset to PCI
 *       config 0xD0, read/write the data at PCI config 0xD4. Handshake regs are
 *       single-BYTE accesses at a lane within the dword; the mailbox is DWORD.
 *
 * NEITHER path uses the controller's I/O-port BARs (e000-e057): the ReadPort_x /
 * WritePort_x asmiodll primitives exist but are not called for the ASM2106B
 * command path. See asm_ioport.c for an EXPERIMENTAL I/O-port probe.
 *
 * The seam mirrors the Windows primitives exactly: read8/write8 for the byte-lane
 * handshake regs, read32/write32 for the mailbox. Every access method drives the
 * identical command codec (asm2106b.c) and it is unit-tested against the mock.
 *
 * SAFETY: reads are harmless. The read commands only ever write the handshake
 * regs + the command mailbox (never a destructive ModeChange). raidmon.exe holds
 * NO write primitive at all (imports only PCI_Read_DWORD/Load/Unload from
 * asmiodll) - there is no software "enable" step to reproduce.
 */
#ifndef ASM_REG_H
#define ASM_REG_H

#include <stdint.h>
#include <stddef.h>

typedef struct asm_reg asm_reg;

struct asm_reg {
    int      (*read8)  (asm_reg *self, uint32_t off);                 /* 0..255 / <0 */
    int      (*write8) (asm_reg *self, uint32_t off, uint8_t val);    /* 0 / <0      */
    uint32_t (*read32) (asm_reg *self, uint32_t off);                 /* dword-aligned */
    int      (*write32)(asm_reg *self, uint32_t off, uint32_t val);   /* 0 / <0      */
    void     (*close)  (asm_reg *self);
};

static inline int      asm_reg_read8 (asm_reg *r, uint32_t o)            { return r->read8(r, o); }
static inline int      asm_reg_write8(asm_reg *r, uint32_t o, uint8_t v) { return r->write8(r, o, v); }
static inline uint32_t asm_reg_read32(asm_reg *r, uint32_t o)            { return r->read32(r, o); }
static inline int      asm_reg_write32(asm_reg *r, uint32_t o, uint32_t v){ return r->write32(r, o, v); }
static inline void     asm_reg_close(asm_reg *r) { if (r && r->close) r->close(r); }

/* ---- Real backend (Linux). Access method from VESSAPP_MMIO_ACCESS:
 *   "config" (default) -> PCI config 0xD0/0xD4 backdoor (the alternate path)
 *   "devmem"           -> mmap /dev/mem at BAR5-phys (cfg 0x24-0x27), len 0x2000.
 *                         The FAITHFUL port of MapAsmIO/MmMapIoSpace and the best
 *                         candidate on this box - unlike "bar5" it is NOT capped
 *                         at the reported BAR size, so it can reach +0x1000 IF the
 *                         controller decodes it (the on-box open question).
 *   "bar5"             -> mmap /sys/bus/pci/devices/<dev>/resource5 (BAR-size
 *                         capped; on a 512-byte ABAR every reg past 0x200 -ERANGEs)
 *   "ioport"           -> EXPERIMENTAL I/O-port index/data probe (x86 only, needs
 *                         iopl). Index/data ports default to BAR4 base (cfg 0x20)
 *                         and +4; override with VESSAPP_ASM_IOPORT_INDEX / _DATA.
 *                         NOT a recovered method - a hypothesis instrument only.
 * Device slot from VESSAPP_ASM_PCI (default "0000:02:00.0"). NULL on failure. */
asm_reg *asm_reg_open(void);

/* ---- EXPERIMENTAL I/O-port index/data backend (asm_ioport.c). The port in/out
 * is injected so the index/data translation is testable without hardware. This
 * is a HYPOTHESIS probe, not a recovered access method - see asm_ioport.c. ---- */
typedef struct {
    uint32_t (*in) (void *ctx, uint16_t port, int width);              /* width 1/2/4 */
    void     (*out)(void *ctx, uint16_t port, uint32_t val, int width);
    void     *ctx;
} asm_port_io;
asm_reg *asm_reg_ioport_open(const asm_port_io *io, uint16_t index_port, uint16_t data_port);

/* ---- Recording mock. Reads return seeded values (NOT reflected from writes,
 * so the FW handshake + ack mailbox can be simulated); writes are logged. ---- */
typedef struct { uint32_t off; uint32_t val; int width; } asm_reg_write_rec; /* width 1 or 4 */

asm_reg *asm_reg_mock_open(void);
void     asm_reg_mock_free(asm_reg *r);
void     asm_reg_mock_seed8(asm_reg *r, uint32_t off, uint8_t val);
/* Seed a byte stream (read8 returns each byte; read32 unpacks LE). */
void     asm_reg_mock_seed_bytes(asm_reg *r, uint32_t off, const uint8_t *b, uint32_t len);
size_t                    asm_reg_mock_write_count(const asm_reg *r);
const asm_reg_write_rec  *asm_reg_mock_write(const asm_reg *r, size_t idx);
void                      asm_reg_mock_reset(asm_reg *r);

#endif /* ASM_REG_H */
