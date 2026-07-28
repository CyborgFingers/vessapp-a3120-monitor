/*
 * test_mmio - ASM2106B command codec: assert the exact 2106BCMD packet dwords +
 * handshake writes, and the 2106BACK parse (signature/status/payload) for each
 * opcode, plus error paths and the port RAID wiring. Mock register seam.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "asm_reg.h"
#include "asm2106b.h"
#include "mmio_raid.h"
#include "encl_backend.h"
#include "i2bdg_api.h"
#include "i2bdg.h"

static int g_checks;
#define CHECK(cond, ...) do { g_checks++; if (!(cond)) { \
    fprintf(stderr, "FAIL %s:%d: ", __FILE__, __LINE__); \
    fprintf(stderr, __VA_ARGS__); fprintf(stderr, "\n"); exit(1); } } while (0)
#define PASS() do { printf("ok - %s (%d checks)\n", __FILE__, g_checks); return 0; } while (0)

/* Seed a valid FW handshake: FW ready, Xdata ready, doorbell clears to 0. */
static void seed_handshake(asm_reg *r) {
    asm_reg_mock_seed8(r, ASM_REG_FW_READY, 1);
    asm_reg_mock_seed8(r, ASM_REG_XDATA, 1);
    /* ASM_REG_DOORBELL defaults to 0 -> poll-for-0 succeeds */
}

static void build_ack(uint8_t *ack, uint8_t status, const uint8_t *payload, int plen) {
    memcpy(ack, "2106BACK", 8);
    ack[8] = status;
    memset(ack + 9, 0, 7);
    if (payload) memcpy(ack + 16, payload, plen);
    else         memset(ack + 16, 0, plen);
}

/* Find the value of a logged write at off with the given width (1 or 4). */
static int find_write(asm_reg *r, uint32_t off, int width, uint32_t *val) {
    for (size_t i = 0; i < asm_reg_mock_write_count(r); i++) {
        const asm_reg_write_rec *w = asm_reg_mock_write(r, i);
        if (w->off == off && w->width == width) { *val = w->val; return 1; }
    }
    return 0;
}

/* ---- command packet bytes + ack payload extraction ---- */
static void test_get_capabilities(void) {
    asm_reg *r = asm_reg_mock_open();
    seed_handshake(r);

    uint8_t payload[ASM_PAYLOAD_CAPS];
    for (int i = 0; i < ASM_PAYLOAD_CAPS; i++) payload[i] = (uint8_t)(0xA0 + i);
    uint8_t ack[ASM_ACK_HDR + ASM_PAYLOAD_CAPS];
    build_ack(ack, 0, payload, ASM_PAYLOAD_CAPS);
    asm_reg_mock_seed_bytes(r, ASM_REG_MAILBOX, ack, sizeof ack);

    uint8_t out[ASM_PAYLOAD_CAPS] = {0};
    CHECK(asm2106b_get_capabilities(r, out) == ASM_OK, "get caps ok");
    CHECK(memcmp(out, payload, ASM_PAYLOAD_CAPS) == 0, "caps payload copied from ack+0x10");

    /* command mailbox dwords: "2106BCMD" + opcode 0x11 */
    uint32_t v;
    CHECK(find_write(r, ASM_REG_MAILBOX + 0, 4, &v) && v == 0x36303132, "mailbox[0]='2106' got 0x%08x", v);
    CHECK(find_write(r, ASM_REG_MAILBOX + 4, 4, &v) && v == 0x444d4342, "mailbox[4]='BCMD' got 0x%08x", v);
    CHECK(find_write(r, ASM_REG_MAILBOX + 8, 4, &v) && (v & 0xff) == ASM_OP_GET_CAPABILITIES,
          "mailbox[8] opcode 0x11 got 0x%08x", v);
    CHECK(find_write(r, ASM_REG_MAILBOX + 12, 4, &v) && v == 0, "mailbox[12]=0");

    /* handshake writes */
    CHECK(find_write(r, ASM_REG_XDATA, 1, &v) && v == 1, "xdata set 1");
    CHECK(find_write(r, ASM_REG_DOORBELL, 1, &v) && v == 1, "doorbell set 1");

    asm_reg_mock_free(r);
}

static void test_opcodes(void) {
    struct { uint8_t op; int plen; } cases[] = {
        { ASM_OP_GET_RAID_STATUS,  ASM_PAYLOAD_RAID  },
        { ASM_OP_GET_PORTS_STATUS, ASM_PAYLOAD_PORTS },
    };
    for (unsigned k = 0; k < 2; k++) {
        asm_reg *r = asm_reg_mock_open();
        seed_handshake(r);
        uint8_t ack[ASM_ACK_HDR + ASM_PAYLOAD_PORTS];
        build_ack(ack, 0, NULL, cases[k].plen);
        for (int i = 0; i < cases[k].plen; i++) ack[ASM_ACK_HDR + i] = (uint8_t)(i & 0xff);
        asm_reg_mock_seed_bytes(r, ASM_REG_MAILBOX, ack, (uint32_t)(ASM_ACK_HDR + cases[k].plen));

        uint8_t out[ASM_PAYLOAD_PORTS] = {0};
        int rc = asm2106b_command(r, cases[k].op, out, (uint32_t)cases[k].plen, NULL);
        CHECK(rc == ASM_OK, "opcode 0x%02x ok", cases[k].op);
        uint32_t v;
        CHECK(find_write(r, ASM_REG_MAILBOX + 8, 4, &v) && (v & 0xff) == cases[k].op,
              "opcode 0x%02x in mailbox[8]", cases[k].op);
        CHECK(out[0] == 0 && out[cases[k].plen - 1] == (uint8_t)((cases[k].plen - 1) & 0xff),
              "payload copied for 0x%02x", cases[k].op);
        asm_reg_mock_free(r);
    }
}

/* ---- error paths ---- */
static void test_errors(void) {
    /* signature mismatch */
    asm_reg *r = asm_reg_mock_open();
    seed_handshake(r);
    uint8_t ack[ASM_ACK_HDR + ASM_PAYLOAD_CAPS];
    build_ack(ack, 0, NULL, ASM_PAYLOAD_CAPS);
    memcpy(ack, "XXXXXXXX", 8);
    asm_reg_mock_seed_bytes(r, ASM_REG_MAILBOX, ack, sizeof ack);
    uint8_t out[ASM_PAYLOAD_CAPS];
    CHECK(asm2106b_get_capabilities(r, out) == ASM_ESIG, "bad signature -> ESIG");
    asm_reg_mock_free(r);

    /* status byte != 0 */
    r = asm_reg_mock_open();
    seed_handshake(r);
    build_ack(ack, 5, NULL, ASM_PAYLOAD_CAPS);
    asm_reg_mock_seed_bytes(r, ASM_REG_MAILBOX, ack, sizeof ack);
    uint8_t status = 0;
    CHECK(asm2106b_command(r, ASM_OP_GET_CAPABILITIES, out, ASM_PAYLOAD_CAPS, &status) == ASM_ESTATUS,
          "nonzero status -> ESTATUS");
    CHECK(status == 5, "status byte surfaced = %d", status);
    asm_reg_mock_free(r);

    /* FW never ready -> timeout */
    r = asm_reg_mock_open();   /* no seed_handshake: 0x867 stays 0 */
    CHECK(asm2106b_get_capabilities(r, out) == ASM_ETIMEOUT, "no FW ready -> ETIMEOUT");
    asm_reg_mock_free(r);
}

/* ---- port RAID wiring under VESSAPP_BACKEND=mmio ---- */
static void test_raid_wiring(void) {
    encl_backend_set(ENCL_MMIO);
    asm_reg *r = asm_reg_mock_open();
    seed_handshake(r);
    uint8_t ack[ASM_ACK_HDR + ASM_PAYLOAD_CAPS];
    build_ack(ack, 0, NULL, ASM_PAYLOAD_CAPS);
    asm_reg_mock_seed_bytes(r, ASM_REG_MAILBOX, ack, sizeof ack);
    mmio_raid_set_reg(r);

    CHECK(mmio_raid_controller_present() == 1, "controller present on valid ACK");

    I2bArrayInfo_t arr;
    CHECK(i2bApi_getDiskArrayInfo(&arr, sizeof arr) == I2B_API_RET_SUCCESS, "mmio getDiskArrayInfo");
    CHECK(arr.DACnt == 0, "AHCI-mode: 0 arrays (structured decode UNRESOLVED)");

    mmio_raid_set_reg(NULL);
    asm_reg_mock_free(r);
    encl_backend_set(-1);   /* restore default */
}

/* ---- EXPERIMENTAL ioport index/data backend: the port sequence + byte lane ----
 * Emulates an index/data register file over the injected port seam and asserts
 * asm_ioport.c reproduces the cited cfg-0xD0/0xD4 backdoor semantics (index the
 * dword-aligned offset, then read/write DATA; byte accesses pick the lane). */
typedef struct {
    uint16_t index_port, data_port;
    uint32_t last_index;
    uint32_t regfile[0x2000 / 4];
    int      out_index_seen, out_data_seen;   /* count port ops for the sequence */
} portmock;

static uint32_t pm_in(void *ctx, uint16_t port, int width) {
    portmock *m = ctx;
    (void)width;
    if (port == m->data_port) return m->regfile[m->last_index / 4];
    return 0;
}
static void pm_out(void *ctx, uint16_t port, uint32_t val, int width) {
    portmock *m = ctx;
    if (port == m->index_port) { m->last_index = val & ~3u; m->out_index_seen++; return; }
    if (port >= m->data_port && port < m->data_port + 4) {
        m->out_data_seen++;
        uint32_t *slot = &m->regfile[m->last_index / 4];
        if (width == 4) { *slot = val; return; }
        int lane = port - m->data_port;              /* byte lane within the dword */
        *slot = (*slot & ~(0xffu << (8 * lane))) | ((val & 0xff) << (8 * lane));
    }
}

static void test_ioport_backdoor(void) {
    portmock m = { .index_port = 0xe000, .data_port = 0xe004 };
    asm_port_io io = { pm_in, pm_out, &m };
    asm_reg *r = asm_reg_ioport_open(&io, m.index_port, m.data_port);
    CHECK(r != NULL, "ioport backend opens");

    /* read8(0x867): index 0x864, then DATA byte lane 3 */
    m.regfile[0x864 / 4] = 0xAABBCC01;   /* lane 3 = 0xAA */
    m.out_index_seen = 0;
    CHECK(asm_reg_read8(r, ASM_REG_FW_READY) == 0xAA, "read8 0x867 picks byte lane 3");
    CHECK(m.last_index == 0x864, "read8 indexed 0x864 (dword-aligned)");
    CHECK(m.out_index_seen == 1, "read8 wrote INDEX once");

    /* read32(0x1000): whole dword from the mailbox slot */
    m.regfile[ASM_REG_MAILBOX / 4] = 0x12345678;
    CHECK(asm_reg_read32(r, ASM_REG_MAILBOX) == 0x12345678, "read32 mailbox returns DATA dword");

    /* write32(0x1000, X): index, DATA dword, re-index, flush-read */
    m.out_data_seen = 0;
    asm_reg_write32(r, ASM_REG_MAILBOX, 0xDEADBEEF);
    CHECK(m.regfile[ASM_REG_MAILBOX / 4] == 0xDEADBEEF, "write32 mailbox stores dword via DATA");
    CHECK(m.out_data_seen == 1, "write32 wrote DATA once");

    /* write8(0xd66, 1): index 0xd64, DATA lane 2 = 1 */
    m.regfile[0xd64 / 4] = 0;
    asm_reg_write8(r, ASM_REG_XDATA, 1);
    CHECK(m.last_index == 0xd64, "write8 0xd66 indexed 0xd64");
    CHECK(((m.regfile[0xd64 / 4] >> 16) & 0xff) == 1, "write8 0xd66 set byte lane 2");

    asm_reg_close(r);
}

int main(void) {
    test_get_capabilities();
    test_opcodes();
    test_errors();
    test_raid_wiring();
    test_ioport_backdoor();
    PASS();
}
