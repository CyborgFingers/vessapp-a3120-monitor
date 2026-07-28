/*
 * scsi_transport_mock.c - in-memory vendor-SCSI transport for tests + tools.
 * Records every CDB and replays canned READ responses keyed by MicOpCode.
 */
#include "scsi_transport.h"
#include <stdlib.h>
#include <string.h>
#include <errno.h>

#define MOCK_MAX_SEED 32
#define MOCK_MAX_LOG  256
#define MOCK_SEED_CAP 1024

typedef struct {
    uint16_t micop;
    uint32_t len;
    uint8_t  data[MOCK_SEED_CAP];
    int      used;
} seed_entry;

struct scsi_mock {
    scsi_transport iface;
    seed_entry     seed[MOCK_MAX_SEED];
    scsi_txn       log[MOCK_MAX_LOG];
    size_t         log_count;
};

static seed_entry *find_seed(struct scsi_mock *m, uint16_t micop) {
    for (int i = 0; i < MOCK_MAX_SEED; i++)
        if (m->seed[i].used && m->seed[i].micop == micop) return &m->seed[i];
    return NULL;
}

static int mock_command(scsi_transport *self, const uint8_t cdb[I2_CDB_LEN],
                        scsi_dir dir, void *data, uint32_t len,
                        uint8_t *sense, uint8_t sense_cap) {
    struct scsi_mock *m = (struct scsi_mock *)self;
    (void)sense; (void)sense_cap;

    if (m->log_count < MOCK_MAX_LOG) {
        scsi_txn *t = &m->log[m->log_count++];
        memset(t, 0, sizeof *t);
        memcpy(t->cdb, cdb, I2_CDB_LEN);
        t->dir = dir;
        t->data_len = len;
        if (dir == SCSI_XFER_WRITE && data && len) {
            uint32_t n = len > sizeof t->data ? (uint32_t)sizeof t->data : len;
            memcpy(t->data, data, n);
        }
    }

    if (dir == SCSI_XFER_READ && data) {
        uint16_t micop = (uint16_t)(cdb[I2_CDB_OFF_MICOP] | cdb[I2_CDB_OFF_MICOP + 1] << 8);
        memset(data, 0, len);
        seed_entry *s = find_seed(m, micop);
        if (s) {
            uint32_t n = s->len < len ? s->len : len;
            memcpy(data, s->data, n);
        }
    }
    return 0;
}

static void mock_close(scsi_transport *self) { (void)self; /* scsi_mock_free */ }

scsi_mock *scsi_mock_open(void) {
    struct scsi_mock *m = calloc(1, sizeof *m);
    if (!m) return NULL;
    m->iface.command = mock_command;
    m->iface.close   = mock_close;
    return m;
}

scsi_transport *scsi_mock_iface(scsi_mock *m) { return &m->iface; }
void            scsi_mock_free(scsi_mock *m)  { free(m); }

void scsi_mock_seed(scsi_mock *m, uint16_t micop, const uint8_t *data, uint32_t len) {
    if (len > MOCK_SEED_CAP) len = MOCK_SEED_CAP;
    seed_entry *s = find_seed(m, micop);
    if (!s) for (int i = 0; i < MOCK_MAX_SEED; i++) if (!m->seed[i].used) { s = &m->seed[i]; s->used = 1; break; }
    if (!s) return;
    s->micop = micop; s->len = len;
    memset(s->data, 0, sizeof s->data);
    if (data && len) memcpy(s->data, data, len);
}

size_t          scsi_mock_txn_count(const scsi_mock *m) { return m->log_count; }
const scsi_txn *scsi_mock_txn(const scsi_mock *m, size_t i) {
    return i < m->log_count ? &m->log[i] : NULL;
}
void            scsi_mock_reset(scsi_mock *m) { m->log_count = 0; }
