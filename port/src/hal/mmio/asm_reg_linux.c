/*
 * asm_reg_linux.c - real ASM2106B register access on Linux.
 *
 *  config (default): the PCI config 0xD0/0xD4 index/data backdoor, byte-for-byte
 *    as FUN_100363c0/10036520/10036830/10037460 do it via /sys/.../config.
 *  devmem: mmap /dev/mem at BAR5-phys (read from cfg 0x24-0x27, like
 *    GetASM2106BMmioBase @100323e8), length 0x2000 - the FAITHFUL port of
 *    MapAsmIO/MmMapIoSpace + READ_REGISTER (the path raidmon.exe runs). Unlike
 *    bar5 it is not capped at the reported BAR size. See docs/RE-asm2106b.md.
 *  bar5:  mmap /sys/bus/pci/devices/<dev>/resource5 and touch off directly. This
 *    is capped at the BAR size; on the live 512-byte ABAR every reg >= 0x200
 *    -ERANGEs. Kept for completeness.
 *  ioport: EXPERIMENTAL x86 I/O-port index/data probe (asm_ioport.c) - a
 *    hypothesis instrument, NOT a recovered method. See docs/RE-asm2106b.md.
 *
 * Compiled on Linux only (see CMakeLists); a stub elsewhere.
 */
#include "asm_reg.h"

#include <stdlib.h>
#include <stdio.h>
#include <string.h>

#ifdef __linux__
#include <fcntl.h>
#include <unistd.h>
#include <errno.h>
#include <stdint.h>
#include <sys/mman.h>
#include <sys/stat.h>

#if defined(__i386__) || defined(__x86_64__)
#include <sys/io.h>       /* iopl + inb/inw/inl/outb/outw/outl for ioport mode */
#define ASM_HAVE_PORTIO 1
#endif

#define CFG_INDEX 0xD0   /* vendor index register (dword) */
#define CFG_DATA  0xD4   /* vendor data  register         */
#define ASM_MMIO_WINDOW 0x2000   /* getMmioMapAddress maps 0x2000 at BAR5-phys */

typedef struct {
    asm_reg  iface;
    int      cfg_fd;         /* config backend */
    volatile uint8_t *map;   /* usable window (bar5 / devmem)          */
    size_t   map_len;
    void    *map_raw;        /* real mmap base (page-aligned) for munmap */
    size_t   map_raw_len;
} asm_dev;

/* ---- config-space backdoor ---- */
static int cfg_wr32(int fd, uint32_t at, uint32_t v) {
    return pwrite(fd, &v, 4, at) == 4 ? 0 : -errno;
}
static int cfg_rd32(int fd, uint32_t at, uint32_t *out) {
    return pread(fd, out, 4, at) == 4 ? 0 : -errno;
}

static int cfg_read8(asm_reg *self, uint32_t off) {
    asm_dev *d = (asm_dev *)self;
    uint32_t v;
    if (cfg_wr32(d->cfg_fd, CFG_INDEX, off & ~3u) < 0) return -errno;
    if (cfg_rd32(d->cfg_fd, CFG_DATA, &v) < 0) return -errno;
    return (int)((v >> (8 * (off & 3))) & 0xff);
}
static int cfg_write8(asm_reg *self, uint32_t off, uint8_t val) {
    asm_dev *d = (asm_dev *)self;
    if (cfg_wr32(d->cfg_fd, CFG_INDEX, off & ~3u) < 0) return -errno;
    if (pwrite(d->cfg_fd, &val, 1, CFG_DATA + (off & 3)) != 1) return -errno;
    cfg_wr32(d->cfg_fd, CFG_INDEX, off & ~3u);   /* re-index + flush read */
    uint32_t tmp; cfg_rd32(d->cfg_fd, CFG_DATA, &tmp);
    return 0;
}
static uint32_t cfg_read32(asm_reg *self, uint32_t off) {
    asm_dev *d = (asm_dev *)self;
    uint32_t v = 0;
    if (cfg_wr32(d->cfg_fd, CFG_INDEX, off & ~3u) < 0) return 0;
    cfg_rd32(d->cfg_fd, CFG_DATA, &v);
    return v;
}
static int cfg_write32(asm_reg *self, uint32_t off, uint32_t val) {
    asm_dev *d = (asm_dev *)self;
    if (cfg_wr32(d->cfg_fd, CFG_INDEX, off & ~3u) < 0) return -errno;
    if (cfg_wr32(d->cfg_fd, CFG_DATA, val) < 0) return -errno;
    cfg_wr32(d->cfg_fd, CFG_INDEX, off & ~3u);
    uint32_t tmp; cfg_rd32(d->cfg_fd, CFG_DATA, &tmp);
    return 0;
}

/* ---- BAR5 mmap ---- */
static int bar_read8(asm_reg *self, uint32_t off) {
    asm_dev *d = (asm_dev *)self;
    if (off >= d->map_len) return -ERANGE;
    return d->map[off];
}
static int bar_write8(asm_reg *self, uint32_t off, uint8_t val) {
    asm_dev *d = (asm_dev *)self;
    if (off >= d->map_len) return -ERANGE;
    d->map[off] = val;
    return 0;
}
static uint32_t bar_read32(asm_reg *self, uint32_t off) {
    asm_dev *d = (asm_dev *)self;
    if (off + 4 > d->map_len) return 0;
    volatile uint32_t *p = (volatile uint32_t *)(d->map + (off & ~3u));
    return *p;
}
static int bar_write32(asm_reg *self, uint32_t off, uint32_t val) {
    asm_dev *d = (asm_dev *)self;
    if (off + 4 > d->map_len) return -ERANGE;
    volatile uint32_t *p = (volatile uint32_t *)(d->map + (off & ~3u));
    *p = val;
    return 0;
}

static void dev_close(asm_reg *self) {
    asm_dev *d = (asm_dev *)self;
    if (d->map_raw) munmap(d->map_raw, d->map_raw_len);
    if (d->cfg_fd >= 0) close(d->cfg_fd);
    free(d);
}

/* Read a little-endian dword from PCI config space at `at`. -1 on failure. */
static long cfg_bar(const char *slot, uint32_t at) {
    char path[256];
    snprintf(path, sizeof path, "/sys/bus/pci/devices/%s/config", slot);
    int fd = open(path, O_RDONLY);
    if (fd < 0) return -1;
    uint32_t v = 0;
    ssize_t n = pread(fd, &v, 4, at);
    close(fd);
    return n == 4 ? (long)v : -1;
}

/* Wire the mmap'd-window read/write ops (shared by bar5 + devmem). */
static void wire_mmap_ops(asm_dev *d) {
    d->iface.read8 = bar_read8;   d->iface.write8 = bar_write8;
    d->iface.read32 = bar_read32; d->iface.write32 = bar_write32;
}

/* mmap `len` bytes of `path` at physical `phys` (page-aligned under the hood).
 * Returns 0 and fills d->map/map_len/map_raw*, or -1. */
static int map_phys(asm_dev *d, const char *path, uint64_t phys, size_t len) {
    int fd = open(path, O_RDWR | O_SYNC);
    if (fd < 0) return -1;
    long ps = sysconf(_SC_PAGESIZE);
    uint64_t aligned = phys & ~((uint64_t)ps - 1);
    size_t   delta   = (size_t)(phys - aligned);
    d->map_raw_len = delta + len;
    d->map_raw = mmap(NULL, d->map_raw_len, PROT_READ | PROT_WRITE, MAP_SHARED, fd, (off_t)aligned);
    close(fd);
    if (d->map_raw == MAP_FAILED) { d->map_raw = NULL; return -1; }
    d->map = (volatile uint8_t *)d->map_raw + delta;
    d->map_len = len;
    return 0;
}

#ifdef ASM_HAVE_PORTIO
/* Real x86 port I/O seam for the EXPERIMENTAL ioport backend (asm_ioport.c). */
static uint32_t real_pin(void *ctx, uint16_t port, int width) {
    (void)ctx;
    if (width == 1) return inb(port);
    if (width == 2) return inw(port);
    return inl(port);
}
static void real_pout(void *ctx, uint16_t port, uint32_t val, int width) {
    (void)ctx;
    if (width == 1) outb((uint8_t)val, port);
    else if (width == 2) outw((uint16_t)val, port);
    else outl(val, port);
}
static uint16_t env_port(const char *name, uint16_t fallback) {
    const char *s = getenv(name);
    if (!s || !*s) return fallback;
    return (uint16_t)strtoul(s, NULL, 0);
}
#endif

/* EXPERIMENTAL: open the I/O-port index/data probe. Index/data default to BAR4
 * base (cfg 0x20) and +4; NOT a recovered mapping - see asm_ioport.c. */
static asm_reg *open_ioport(const char *slot) {
#ifdef ASM_HAVE_PORTIO
    long bar4 = cfg_bar(slot, 0x20);              /* BAR4: the /32 I/O window   */
    if (bar4 < 0) { fprintf(stderr, "asm ioport: cannot read cfg BAR4\n"); return NULL; }
    uint16_t iobase = (uint16_t)((uint32_t)bar4 & ~3u);   /* I/O BAR: low 2 bits flags */
    uint16_t index_port = env_port("VESSAPP_ASM_IOPORT_INDEX", iobase);
    uint16_t data_port  = env_port("VESSAPP_ASM_IOPORT_DATA",  (uint16_t)(iobase + 4));
    if (iopl(3) != 0) { perror("asm ioport: iopl(3) (need root/CAP_SYS_RAWIO)"); return NULL; }
    fprintf(stderr, "asm ioport: EXPERIMENTAL (UNPROVEN) index=0x%04x data=0x%04x "
                    "(override VESSAPP_ASM_IOPORT_INDEX/_DATA)\n", index_port, data_port);
    asm_port_io io = { real_pin, real_pout, NULL };
    return asm_reg_ioport_open(&io, index_port, data_port);
#else
    (void)slot;
    fprintf(stderr, "asm ioport: needs x86 port I/O; unavailable on this arch\n");
    return NULL;
#endif
}

asm_reg *asm_reg_open(void) {
    const char *slot = getenv("VESSAPP_ASM_PCI");
    if (!slot || !*slot) slot = "0000:02:00.0";
    const char *access = getenv("VESSAPP_MMIO_ACCESS");
    if (!access || !*access) access = "config";

    if (strcmp(access, "ioport") == 0) return open_ioport(slot);

    asm_dev *d = calloc(1, sizeof *d);
    if (!d) return NULL;
    d->cfg_fd = -1;

    char path[256];
    if (strcmp(access, "bar5") == 0) {
        /* BAR-size-capped mapping (kept for completeness). */
        snprintf(path, sizeof path, "/sys/bus/pci/devices/%s/resource5", slot);
        int fd = open(path, O_RDWR | O_SYNC);
        if (fd < 0) { free(d); return NULL; }
        struct stat st;
        size_t len = (fstat(fd, &st) == 0 && st.st_size > 0) ? (size_t)st.st_size : ASM_MMIO_WINDOW;
        d->map_raw = mmap(NULL, len, PROT_READ | PROT_WRITE, MAP_SHARED, fd, 0);
        close(fd);
        if (d->map_raw == MAP_FAILED) { d->map_raw = NULL; free(d); return NULL; }
        d->map = d->map_raw; d->map_len = d->map_raw_len = len;
        wire_mmap_ops(d);
    } else if (strcmp(access, "devmem") == 0) {
        /* Faithful MapAsmIO port: BAR5-phys from cfg 0x24-0x27, /dev/mem, 0x2000. */
        long bar5 = cfg_bar(slot, 0x24);
        if (bar5 < 0) { free(d); return NULL; }
        uint64_t phys = (uint32_t)bar5 & ~0xFu;   /* mem BAR: mask the 4 flag bits */
        if (phys == 0) { fprintf(stderr, "asm devmem: BAR5 unassigned\n"); free(d); return NULL; }
        if (map_phys(d, "/dev/mem", phys, ASM_MMIO_WINDOW) < 0) { free(d); return NULL; }
        wire_mmap_ops(d);
    } else {
        /* config (default): the PCI cfg 0xD0/0xD4 index/data backdoor. */
        snprintf(path, sizeof path, "/sys/bus/pci/devices/%s/config", slot);
        d->cfg_fd = open(path, O_RDWR);
        if (d->cfg_fd < 0) { free(d); return NULL; }
        d->iface.read8 = cfg_read8; d->iface.write8 = cfg_write8;
        d->iface.read32 = cfg_read32; d->iface.write32 = cfg_write32;
    }
    d->iface.close = dev_close;
    return &d->iface;
}

#else  /* non-Linux */
asm_reg *asm_reg_open(void) { return NULL; }
#endif
