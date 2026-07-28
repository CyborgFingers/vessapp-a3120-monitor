/*
 * asm_reg_mock.c - recording mock for the ASM2106B register seam.
 * Reads return a seeded sparse byte map (independent of writes) so the FW
 * handshake and ack mailbox can be simulated; writes are logged for assertions.
 */
#include "asm_reg.h"

#include <stdlib.h>
#include <string.h>

#define MOCK_MAX_SEED 2048   /* sparse (off,byte) read map                    */
#define MOCK_MAX_LOG  1024

typedef struct { uint32_t off; uint8_t val; } seed_byte;

typedef struct {
    asm_reg           iface;
    seed_byte         seed[MOCK_MAX_SEED];
    size_t            seed_n;   /* high-water: only [0,seed_n) are used */
    asm_reg_write_rec log[MOCK_MAX_LOG];
    size_t            log_count;
} asm_mock;

static seed_byte *find_seed(asm_mock *m, uint32_t off) {
    for (size_t i = 0; i < m->seed_n; i++)
        if (m->seed[i].off == off) return &m->seed[i];
    return NULL;
}
static void put_seed(asm_mock *m, uint32_t off, uint8_t v) {
    seed_byte *s = find_seed(m, off);
    if (!s && m->seed_n < MOCK_MAX_SEED) { s = &m->seed[m->seed_n++]; s->off = off; }
    if (s) s->val = v;
}
static uint8_t get_seed(asm_mock *m, uint32_t off) {
    seed_byte *s = find_seed(m, off);
    return s ? s->val : 0;
}

static int mock_read8(asm_reg *self, uint32_t off) {
    return get_seed((asm_mock *)self, off);
}
static uint32_t mock_read32(asm_reg *self, uint32_t off) {
    asm_mock *m = (asm_mock *)self;
    off &= ~3u;
    uint32_t d = 0;
    for (uint32_t k = 0; k < 4; k++) d |= (uint32_t)get_seed(m, off + k) << (8 * k);
    return d;
}
static void log_write(asm_mock *m, uint32_t off, uint32_t val, int width) {
    if (m->log_count < MOCK_MAX_LOG) {
        m->log[m->log_count].off = off;
        m->log[m->log_count].val = val;
        m->log[m->log_count].width = width;
        m->log_count++;
    }
}
static int mock_write8(asm_reg *self, uint32_t off, uint8_t val) {
    log_write((asm_mock *)self, off, val, 1);
    return 0;
}
static int mock_write32(asm_reg *self, uint32_t off, uint32_t val) {
    log_write((asm_mock *)self, off, val, 4);
    return 0;
}
static void mock_close(asm_reg *self) { (void)self; }

asm_reg *asm_reg_mock_open(void) {
    asm_mock *m = calloc(1, sizeof *m);
    if (!m) return NULL;
    m->iface.read8   = mock_read8;
    m->iface.write8  = mock_write8;
    m->iface.read32  = mock_read32;
    m->iface.write32 = mock_write32;
    m->iface.close   = mock_close;
    return &m->iface;
}
void asm_reg_mock_free(asm_reg *r) { free((asm_mock *)r); }

void asm_reg_mock_seed8(asm_reg *r, uint32_t off, uint8_t val) {
    put_seed((asm_mock *)r, off, val);
}
void asm_reg_mock_seed_bytes(asm_reg *r, uint32_t off, const uint8_t *b, uint32_t len) {
    asm_mock *m = (asm_mock *)r;
    for (uint32_t i = 0; i < len; i++) put_seed(m, off + i, b[i]);
}
size_t asm_reg_mock_write_count(const asm_reg *r) { return ((const asm_mock *)r)->log_count; }
const asm_reg_write_rec *asm_reg_mock_write(const asm_reg *r, size_t idx) {
    const asm_mock *m = (const asm_mock *)r;
    return idx < m->log_count ? &m->log[idx] : NULL;
}
void asm_reg_mock_reset(asm_reg *r) { ((asm_mock *)r)->log_count = 0; }
