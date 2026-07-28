/*
 * i2c_bus.h - mockable SMBus byte transport (the SMBus-backend substrate).
 *
 * The Linux equivalent of the Python driver's smbus2.SMBus: one /dev/i2c-N node,
 * SMBus read-byte-data / write-byte-data. Mirrors the scsi_transport HAL: a real
 * i2c-dev backend on Linux, and a recording mock that replays seeded register
 * reads and captures every write for byte-exact assertions.
 *
 * Only read/write byte-data are needed: PCA9555 (OUTPUT regs) and NCT7802Y
 * (tach/PWM/config regs) are all 8-bit register devices.
 */
#ifndef I2C_BUS_H
#define I2C_BUS_H

#include <stdint.h>
#include <stddef.h>

typedef struct i2c_bus i2c_bus;

struct i2c_bus {
    /* SMBus read byte data: returns 0..255, or negative errno on failure. */
    int (*read_byte)(i2c_bus *self, uint8_t addr, uint8_t reg);
    /* SMBus write byte data: returns 0 on success, negative errno otherwise. */
    int (*write_byte)(i2c_bus *self, uint8_t addr, uint8_t reg, uint8_t val);
    void (*close)(i2c_bus *self);
};

static inline int i2c_read_byte(i2c_bus *b, uint8_t addr, uint8_t reg) {
    return b->read_byte(b, addr, reg);
}
static inline int i2c_write_byte(i2c_bus *b, uint8_t addr, uint8_t reg, uint8_t v) {
    return b->write_byte(b, addr, reg, v);
}
static inline void i2c_bus_close(i2c_bus *b) { if (b && b->close) b->close(b); }

/* ---- Real backend: /dev/i2c-<busnum> (Linux i2c-dev + SMBus ioctls) ---- */
i2c_bus *i2c_bus_open_dev(int busnum);   /* NULL on open failure */

/* ---- Recording mock (unit tests + off-device) ---- */
typedef struct { uint8_t addr; uint8_t reg; uint8_t val; } i2c_write_rec;

i2c_bus *i2c_mock_open(void);
void     i2c_mock_free(i2c_bus *b);
/* Seed the value returned by a read of (addr,reg). Last seed wins. */
void     i2c_mock_seed(i2c_bus *b, uint8_t addr, uint8_t reg, uint8_t val);
/* Ordered log of every write. */
size_t                 i2c_mock_write_count(const i2c_bus *b);
const i2c_write_rec   *i2c_mock_write(const i2c_bus *b, size_t idx);
void                   i2c_mock_reset(i2c_bus *b);

#endif /* I2C_BUS_H */
