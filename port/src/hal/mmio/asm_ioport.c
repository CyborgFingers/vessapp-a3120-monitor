/*
 * asm_ioport.c - EXPERIMENTAL I/O-port index/data backend for the ASM2106B
 * command window.  Portable (no platform headers): the actual port in/out is
 * injected via an asm_port_io seam, so the index/data translation is
 * unit-testable on any host and the real inb/outl live in asm_reg_linux.c.
 *
 * WHY THIS EXISTS (read docs/RE-asm2106b.md first):
 *   The RECOVERED command path is memory-mapped (BAR5 phys + MmMapIoSpace), NOT
 *   I/O-port based - neither 106BCommandLib.dll nor raidmon.exe call the
 *   ReadPort_x / WritePort_x driver primitives for the ASM2106B path. This backend
 *   is a HYPOTHESIS instrument only: it lets the on-box operator test whether an
 *   ASMedia-style index/data window is exposed in the controller's I/O BARs
 *   (e000-e057) - by direct analogy to the CITED config-space backdoor
 *   (write index to INDEX, read/write data at DATA):
 *     FUN_100363c0 (read, byte-lane) / FUN_10036830 (write dword) /
 *     FUN_10036520 (write byte, lane) in re/out/106b_x86.c, which do exactly
 *     this over PCI config 0xD0/0xD4.  The only unknown transposed here is the
 *     transport (I/O ports instead of config space) and the index/data port
 *     pair - which is NOT observed in the RE and must be supplied by the caller.
 */
#include "asm_reg.h"

#include <stdlib.h>

typedef struct {
    asm_reg     iface;
    asm_port_io io;
    uint16_t    index_port;
    uint16_t    data_port;
} asm_ioport_dev;

/* index the internal register space (dword-aligned) then read the data dword,
 * exactly as FUN_100363c0 does over cfg 0xD0/0xD4. */
static uint32_t idx_rd32(asm_ioport_dev *d, uint32_t off) {
    d->io.out(d->io.ctx, d->index_port, off & ~3u, 4);
    return d->io.in(d->io.ctx, d->data_port, 4);
}

static int ioport_read8(asm_reg *self, uint32_t off) {
    asm_ioport_dev *d = (asm_ioport_dev *)self;
    uint32_t v = idx_rd32(d, off);
    return (int)((v >> (8 * (off & 3))) & 0xff);   /* byte lane (FUN_100363c0) */
}
static int ioport_write8(asm_reg *self, uint32_t off, uint8_t val) {
    asm_ioport_dev *d = (asm_ioport_dev *)self;
    d->io.out(d->io.ctx, d->index_port, off & ~3u, 4);
    d->io.out(d->io.ctx, (uint16_t)(d->data_port + (off & 3)), val, 1);
    d->io.out(d->io.ctx, d->index_port, off & ~3u, 4);   /* re-index + flush (FUN_10036520) */
    d->io.in(d->io.ctx, d->data_port, 4);
    return 0;
}
static uint32_t ioport_read32(asm_reg *self, uint32_t off) {
    return idx_rd32((asm_ioport_dev *)self, off);
}
static int ioport_write32(asm_reg *self, uint32_t off, uint32_t val) {
    asm_ioport_dev *d = (asm_ioport_dev *)self;
    d->io.out(d->io.ctx, d->index_port, off & ~3u, 4);
    d->io.out(d->io.ctx, d->data_port, val, 4);
    d->io.out(d->io.ctx, d->index_port, off & ~3u, 4);   /* re-index + flush (FUN_10036830) */
    d->io.in(d->io.ctx, d->data_port, 4);
    return 0;
}
static void ioport_close(asm_reg *self) { free(self); }

asm_reg *asm_reg_ioport_open(const asm_port_io *io, uint16_t index_port, uint16_t data_port) {
    if (!io || !io->in || !io->out) return NULL;
    asm_ioport_dev *d = calloc(1, sizeof *d);
    if (!d) return NULL;
    d->io = *io;
    d->index_port = index_port;
    d->data_port = data_port;
    d->iface.read8 = ioport_read8;   d->iface.write8 = ioport_write8;
    d->iface.read32 = ioport_read32; d->iface.write32 = ioport_write32;
    d->iface.close = ioport_close;
    return &d->iface;
}
