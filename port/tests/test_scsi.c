/* test_scsi - libi2 CDB/MicOpCode marshalling against the mock transport.
 * Pins the recovered vendor SCSI frame (docs/RE-fwinterf-pri2api.md). */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "i2.h"
#include "scsi_transport.h"
#include "i2_micops.h"

static int checks;
#define CHECK(c, ...) do { checks++; if(!(c)){ fprintf(stderr,"FAIL %s:%d: ",__FILE__,__LINE__); \
    fprintf(stderr,__VA_ARGS__); fprintf(stderr,"\n"); exit(1);} } while(0)

static const scsi_txn *txn_at_(scsi_mock *m, size_t i) {
    const scsi_txn *t = scsi_mock_txn(m, i);
    CHECK(t != NULL, "txn %zu missing", i);
    return t;
}

/* Every CDB must be the 16-byte Promise vendor frame: D8 BA <micop LE> .. */
static void assert_vendor(const scsi_txn *t, uint16_t micop) {
    CHECK(t->cdb[0] == 0xD8 && t->cdb[1] == 0xBA, "CDB[0..1] D8 BA got %02x %02x", t->cdb[0], t->cdb[1]);
    CHECK(scsi_txn_micop(t) == micop, "micop want 0x%04x got 0x%04x", micop, scsi_txn_micop(t));
}

static void test_read_cdbs(void) {
    scsi_mock *m = scsi_mock_open();
    i2_handle *h = i2_initialize_borrow(scsi_mock_iface(m));

    unsigned char page[0x400] = {0};
    CHECK(i2_getEnclosureInfo(h, page, 0x88) == I2_OK, "encinfo");
    const scsi_txn *t = txn_at_(m, 0);
    assert_vendor(t, I2_MICOP_GET_ENCL_INFO);
    CHECK(t->dir == SCSI_XFER_READ, "encinfo is a read");
    /* transfer length big-endian at CDB[10..11] == request length */
    CHECK(t->cdb[10] == 0x00 && t->cdb[11] == 0x88, "xferlen BE 0x0088 got %02x%02x", t->cdb[10], t->cdb[11]);

    CHECK(i2_getEnclosureStatus(h, page, 0x400) == I2_OK, "status");
    assert_vendor(scsi_mock_txn(m, 1), I2_MICOP_GET_ENCL_STATUS);
    CHECK(scsi_mock_txn(m, 1)->cdb[10] == 0x04 && scsi_mock_txn(m, 1)->cdb[11] == 0x00,
          "status xferlen BE 0x0400");

    i2_dispose(h);
    scsi_mock_free(m);
}

static void test_write_cdbs(void) {
    scsi_mock *m = scsi_mock_open();
    i2_handle *h = i2_initialize_borrow(scsi_mock_iface(m));

    /* setLED: MicOpCode 0x130F, fru_id @CDB[6], colour @CDB[7] (recovered). */
    CHECK(i2_setLedFruInfo(h, 0x10, 0x02, 0x1234) == I2_OK, "setled");
    const scsi_txn *t = scsi_mock_txn(m, 0);
    assert_vendor(t, I2_MICOP_SET_LED_FRU);
    CHECK(t->dir == SCSI_XFER_WRITE, "setled is a write");
    CHECK(t->cdb[6] == 0x10, "fru id @CDB[6] got 0x%x", t->cdb[6]);
    CHECK(t->cdb[7] == 0x02, "colour @CDB[7] got 0x%x", t->cdb[7]);

    /* VPD write: MicOpCode 0x8103, selector @CDB[6]. */
    unsigned char vpd[0x140] = {0};
    CHECK(i2_setCtrlexSettings(h, 5, vpd, sizeof vpd) == I2_OK, "setctrlex");
    assert_vendor(scsi_mock_txn(m, 1), I2_MICOP_SET_CTRLEX);
    CHECK(scsi_mock_txn(m, 1)->cdb[6] == 5, "selector @CDB[6]");

    i2_dispose(h);
    scsi_mock_free(m);
}

int main(void) {
    test_read_cdbs();
    test_write_cdbs();
    printf("ok - %s (%d checks)\n", __FILE__, checks);
    return 0;
}
