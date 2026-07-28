/*
 * i2c_bus_mock.c - in-memory SMBus transport for tests + off-device tools.
 * Records every write byte-data and replays canned reads keyed by (addr,reg).
 * The mock write log mirrors the real chip's write-visible state so a read of a
 * register that was written returns the written value (read-modify-write works).
 */
#include "i2c_bus.h"

#include <stdlib.h>
#include <string.h>

#define MOCK_MAX_SEED 128
#define MOCK_MAX_LOG  512

typedef struct { uint8_t addr; uint8_t reg; uint8_t val; int used; } seed_entry;

typedef struct {
    i2c_bus       iface;
    seed_entry    seed[MOCK_MAX_SEED];
    i2c_write_rec log[MOCK_MAX_LOG];
    size_t        log_count;
} i2c_mock;

static seed_entry *find_seed(i2c_mock *m, uint8_t addr, uint8_t reg) {
    for (int i = 0; i < MOCK_MAX_SEED; i++)
        if (m->seed[i].used && m->seed[i].addr == addr && m->seed[i].reg == reg)
            return &m->seed[i];
    return NULL;
}

static seed_entry *alloc_seed(i2c_mock *m, uint8_t addr, uint8_t reg) {
    seed_entry *s = find_seed(m, addr, reg);
    if (s) return s;
    for (int i = 0; i < MOCK_MAX_SEED; i++)
        if (!m->seed[i].used) { m->seed[i].used = 1; m->seed[i].addr = addr;
                                m->seed[i].reg = reg; return &m->seed[i]; }
    return NULL;
}

static int mock_read(i2c_bus *self, uint8_t addr, uint8_t reg) {
    i2c_mock *m = (i2c_mock *)self;
    seed_entry *s = find_seed(m, addr, reg);
    return s ? s->val : 0;
}

static int mock_write(i2c_bus *self, uint8_t addr, uint8_t reg, uint8_t val) {
    i2c_mock *m = (i2c_mock *)self;
    if (m->log_count < MOCK_MAX_LOG) {
        i2c_write_rec *r = &m->log[m->log_count++];
        r->addr = addr; r->reg = reg; r->val = val;
    }
    /* Reflect the write so subsequent reads of the same reg see it. */
    seed_entry *s = alloc_seed(m, addr, reg);
    if (s) s->val = val;
    return 0;
}

static void mock_close(i2c_bus *self) { (void)self; /* i2c_mock_free owns it */ }

i2c_bus *i2c_mock_open(void) {
    i2c_mock *m = calloc(1, sizeof *m);
    if (!m) return NULL;
    m->iface.read_byte  = mock_read;
    m->iface.write_byte = mock_write;
    m->iface.close      = mock_close;
    return &m->iface;
}

void i2c_mock_free(i2c_bus *b) { free((i2c_mock *)b); }

void i2c_mock_seed(i2c_bus *b, uint8_t addr, uint8_t reg, uint8_t val) {
    seed_entry *s = alloc_seed((i2c_mock *)b, addr, reg);
    if (s) s->val = val;
}

size_t i2c_mock_write_count(const i2c_bus *b) {
    return ((const i2c_mock *)b)->log_count;
}
const i2c_write_rec *i2c_mock_write(const i2c_bus *b, size_t idx) {
    const i2c_mock *m = (const i2c_mock *)b;
    return idx < m->log_count ? &m->log[idx] : NULL;
}
void i2c_mock_reset(i2c_bus *b) { ((i2c_mock *)b)->log_count = 0; }
