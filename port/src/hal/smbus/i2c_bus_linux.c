/*
 * i2c_bus_linux.c - real SMBus transport over Linux i2c-dev (/dev/i2c-N).
 *
 * Implements SMBus read/write-byte-data with the raw I2C_SMBUS ioctl so no
 * libi2c dependency is needed (only kernel uapi headers, which ship with
 * linux-libc-dev). Equivalent to the Python driver's smbus2 read_byte_data /
 * write_byte_data. Compiled only on Linux (see CMakeLists); a stub elsewhere.
 */
#include "i2c_bus.h"

#include <stdlib.h>
#include <stdio.h>

#ifdef __linux__
#include <fcntl.h>
#include <unistd.h>
#include <errno.h>
#include <sys/ioctl.h>
#include <linux/i2c.h>
#include <linux/i2c-dev.h>

typedef struct { i2c_bus iface; int fd; int cur_addr; } i2c_dev;

/* Bind the node to a slave address. Fall back to FORCE if a kernel driver has
 * claimed it (e.g. a bound nct7802 hwmon) - matches `i2cget -f`. */
static int set_addr(i2c_dev *d, uint8_t addr) {
    if (d->cur_addr == addr) return 0;
    if (ioctl(d->fd, I2C_SLAVE, addr) < 0) {
        if (errno != EBUSY || ioctl(d->fd, I2C_SLAVE_FORCE, addr) < 0)
            return -errno;
    }
    d->cur_addr = addr;
    return 0;
}

static int xfer(i2c_dev *d, uint8_t rw, uint8_t reg, union i2c_smbus_data *data) {
    struct i2c_smbus_ioctl_data args = {
        .read_write = rw, .command = reg, .size = I2C_SMBUS_BYTE_DATA, .data = data,
    };
    return ioctl(d->fd, I2C_SMBUS, &args) < 0 ? -errno : 0;
}

static int dev_read(i2c_bus *self, uint8_t addr, uint8_t reg) {
    i2c_dev *d = (i2c_dev *)self;
    int rc = set_addr(d, addr);
    if (rc) return rc;
    union i2c_smbus_data data = {0};
    rc = xfer(d, I2C_SMBUS_READ, reg, &data);
    return rc ? rc : (int)(data.byte & 0xff);
}

static int dev_write(i2c_bus *self, uint8_t addr, uint8_t reg, uint8_t val) {
    i2c_dev *d = (i2c_dev *)self;
    int rc = set_addr(d, addr);
    if (rc) return rc;
    union i2c_smbus_data data = {0};
    data.byte = val;
    return xfer(d, I2C_SMBUS_WRITE, reg, &data);
}

static void dev_close(i2c_bus *self) {
    i2c_dev *d = (i2c_dev *)self;
    if (d->fd >= 0) close(d->fd);
    free(d);
}

i2c_bus *i2c_bus_open_dev(int busnum) {
    char path[32];
    snprintf(path, sizeof path, "/dev/i2c-%d", busnum);
    int fd = open(path, O_RDWR);
    if (fd < 0) return NULL;
    i2c_dev *d = calloc(1, sizeof *d);
    if (!d) { close(fd); return NULL; }
    d->fd = fd;
    d->cur_addr = -1;
    d->iface.read_byte  = dev_read;
    d->iface.write_byte = dev_write;
    d->iface.close      = dev_close;
    return &d->iface;
}

#else  /* non-Linux host: no i2c-dev */
i2c_bus *i2c_bus_open_dev(int busnum) { (void)busnum; return NULL; }
#endif
