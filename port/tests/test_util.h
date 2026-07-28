/* test_util.h - minimal assert harness + mock wiring for the unit tests.
 *
 * Phase 2: the mock is now the scsi_transport (vendor SCSI CDB level), injected
 * as the process enclosure session. Tests assert the exact CDB/MicOpCode bytes
 * each i2bdg and i2 call emits, and the parse of recorded response pages. */
#ifndef TEST_UTIL_H
#define TEST_UTIL_H

#include <stdio.h>
#include <stdlib.h>
#include "scsi_transport.h"
#include "i2c_map.h"
#include "i2_micops.h"
#include "i2bdg_internal.h"

static int        g_checks;
static scsi_mock *g_mock;

#define CHECK(cond, ...) do { \
    g_checks++; \
    if (!(cond)) { fprintf(stderr, "FAIL %s:%d: ", __FILE__, __LINE__); \
                   fprintf(stderr, __VA_ARGS__); fprintf(stderr, "\n"); exit(1); } \
} while (0)

/* Fresh mock, injected as the process enclosure session. */
static inline scsi_mock *mock_begin(void) {
    i2bdg_session_reset();
    g_mock = scsi_mock_open();
    i2bdg_session_set_transport(scsi_mock_iface(g_mock));
    return g_mock;
}
static inline void mock_end(void) {
    i2bdg_session_set_transport(NULL);
    if (g_mock) scsi_mock_free(g_mock);
    g_mock = NULL;
}

/* Assert the idx-th recorded command exists and return it. */
static inline const scsi_txn *txn_at(scsi_mock *m, size_t idx) {
    const scsi_txn *t = scsi_mock_txn(m, idx);
    CHECK(t != NULL, "expected command #%zu to exist (have %zu)",
          idx, scsi_mock_txn_count(m));
    return t;
}

/* Assert a recorded CDB is the Promise vendor frame with the given MicOpCode. */
static inline const scsi_txn *txn_micop(scsi_mock *m, size_t idx, uint16_t micop) {
    const scsi_txn *t = txn_at(m, idx);
    CHECK(t->cdb[0] == I2_CDB_OP0 && t->cdb[1] == I2_CDB_OP1,
          "CDB[0..1] must be D8 BA, got %02x %02x", t->cdb[0], t->cdb[1]);
    CHECK(scsi_txn_micop(t) == micop,
          "MicOpCode want 0x%04x got 0x%04x", micop, scsi_txn_micop(t));
    return t;
}

#define PASS() do { printf("ok - %s (%d checks)\n", __FILE__, g_checks); return 0; } while (0)

#endif /* TEST_UTIL_H */
