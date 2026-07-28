/*
 * scsi_transport_sgio.c - real vendor-SCSI transport over /dev/sgN + SG_IO.
 *
 * The Linux equivalent of fwinterf!_ib_sendSCSIPassThruDirect: same 16-byte
 * vendor CDB, same 64-byte sense, data in/out via the SG_IO ioctl. Discovery
 * mirrors _ib_discover_hba (scan ports, identify the Promise "Vess" device) but
 * via a standard INQUIRY - the portable, driver-agnostic Linux idiom (the
 * Windows "FASTTRAK" miniport handshake has no Linux analogue).
 */
#include "scsi_transport.h"

#include <fcntl.h>
#include <unistd.h>
#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/ioctl.h>
#include <scsi/sg.h>

#define SG_TIMEOUT_MS   60000   /* fwinterf SPTD TimeOutValue was ~60s */
#define INQ_LEN         96

typedef struct {
    scsi_transport iface;
    int fd;
} sg_bus;

static int sg_command(scsi_transport *self, const uint8_t cdb[I2_CDB_LEN],
                      scsi_dir dir, void *data, uint32_t data_len,
                      uint8_t *sense, uint8_t sense_cap) {
    sg_bus *b = (sg_bus *)self;
    uint8_t local_sense[I2_SENSE_LEN];
    sg_io_hdr_t io;

    memset(&io, 0, sizeof io);
    io.interface_id = 'S';
    io.cmd_len      = I2_CDB_LEN;
    io.cmdp         = (unsigned char *)cdb;
    io.mx_sb_len    = sense ? sense_cap : (uint8_t)sizeof local_sense;
    io.sbp          = sense ? sense : local_sense;
    io.timeout      = SG_TIMEOUT_MS;
    io.dxferp       = data;
    io.dxfer_len    = data_len;
    switch (dir) {
        case SCSI_XFER_READ:  io.dxfer_direction = SG_DXFER_FROM_DEV; break;
        case SCSI_XFER_WRITE: io.dxfer_direction = SG_DXFER_TO_DEV;   break;
        default:              io.dxfer_direction = SG_DXFER_NONE;     break;
    }
    if (data_len == 0) io.dxfer_direction = SG_DXFER_NONE;

    if (ioctl(b->fd, SG_IO, &io) < 0) return -errno;
    if (io.status != 0 /* GOOD */ || io.host_status != 0)
        return (io.status << 8) | io.host_status; /* positive: SCSI/host error */
    return 0;
}

static void sg_close(scsi_transport *self) {
    sg_bus *b = (sg_bus *)self;
    if (b->fd >= 0) close(b->fd);
    free(b);
}

static scsi_transport *wrap_fd(int fd) {
    sg_bus *b = calloc(1, sizeof *b);
    if (!b) { close(fd); return NULL; }
    b->iface.command = sg_command;
    b->iface.close   = sg_close;
    b->fd = fd;
    return &b->iface;
}

scsi_transport *scsi_transport_open_sg(const char *dev_path) {
    if (!dev_path) return NULL;
    int fd = open(dev_path, O_RDWR);
    if (fd < 0) return NULL;
    return wrap_fd(fd);
}

/* Standard INQUIRY (0x12): returns 0 and fills vendor[8]/product[16] on GOOD. */
static int do_inquiry(int fd, char vendor[9], char product[17]) {
    uint8_t cdb[6] = { 0x12, 0, 0, 0, INQ_LEN, 0 };
    uint8_t buf[INQ_LEN];
    uint8_t sense[32];
    sg_io_hdr_t io;

    memset(&io, 0, sizeof io);
    io.interface_id = 'S';
    io.dxfer_direction = SG_DXFER_FROM_DEV;
    io.cmd_len = sizeof cdb;
    io.cmdp = cdb;
    io.mx_sb_len = sizeof sense;
    io.sbp = sense;
    io.dxferp = buf;
    io.dxfer_len = sizeof buf;
    io.timeout = 5000;
    memset(buf, 0, sizeof buf);
    if (ioctl(fd, SG_IO, &io) < 0) return -errno;
    if (io.status != 0) return 1;

    memcpy(vendor, buf + 8, 8);  vendor[8] = 0;
    memcpy(product, buf + 16, 16); product[16] = 0;
    return 0;
}

scsi_transport *scsi_transport_discover(char *out_path, size_t out_cap) {
    /* Scan /dev/sg0../dev/sg31; match Promise vendor or Vess product id. */
    for (int i = 0; i < 32; i++) {
        char path[32];
        snprintf(path, sizeof path, "/dev/sg%d", i);
        int fd = open(path, O_RDWR);
        if (fd < 0) continue;

        char vendor[9] = {0}, product[17] = {0};
        if (do_inquiry(fd, vendor, product) == 0 &&
            (strncmp(vendor, "Promise", 7) == 0 || strncmp(product, "Vess", 4) == 0)) {
            if (out_path && out_cap) { snprintf(out_path, out_cap, "%s", path); }
            return wrap_fd(fd);
        }
        close(fd);
    }
    return NULL;
}
