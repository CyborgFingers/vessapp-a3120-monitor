/*
 * i2bdg_vpd.c - motherboard (MB) and backplane (BP) VPD read/write over libi2.
 *
 * Recovered (docs/RE-libi2bdg.md), from libi2bdg get/setMBVPD @0x3470/0x3590
 * and get/setBPVPD @0x3760/0x3880 -> i2_getCtrlexInfo / i2_setCtrlexSettings
 * (MicOpCode 0x8003 / 0x8103):
 *   - selector byte 4=MB, 5=BP; 0x140-byte ctrlex buffer; VPD data at +0x40;
 *     256-byte window
 *   - read: slice [off,len] from the window (off<=0xff, off+len<=0x100)
 *   - write: off in [4,0xfd], off+len<=0xfe; patch, recompute BOTH checksums,
 *            write back. MB and BP writes are identical bar the selector.
 */
#include "i2bdg_internal.h"
#include "i2c_map.h"

#include <string.h>

static uint8_t vpd_sel(int is_bp) { return is_bp ? I2C_MAP_VPD_SEL_BP : I2C_MAP_VPD_SEL_MB; }

/* Fetch the 0x140 ctrlex buffer; the VPD window is at +0x40. */
static int ctrlex_read(int is_bp, unsigned char buf[I2C_MAP_VPD_BUF_SIZE]) {
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    memset(buf, 0, I2C_MAP_VPD_BUF_SIZE);
    return i2bdg_map_i2_error(i2_getCtrlexInfo(h, vpd_sel(is_bp), buf, I2C_MAP_VPD_BUF_SIZE));
}

static int ctrlex_write(int is_bp, const unsigned char buf[I2C_MAP_VPD_BUF_SIZE]) {
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    return i2bdg_map_i2_error(i2_setCtrlexSettings(h, vpd_sel(is_bp), buf, I2C_MAP_VPD_BUF_SIZE));
}

/* Store -(sum of `n` LE u16 at body) as an LE u16 at ck_off (window-relative). */
static void vpd_fix_checksum(unsigned char *win, int body_off, int n, int ck_off) {
    unsigned short sum = 0;
    for (int i = 0; i < n; i++)
        sum = (unsigned short)(sum + (win[body_off + 2 * i] | win[body_off + 2 * i + 1] << 8));
    unsigned short ck = (unsigned short)(~sum + 1);
    win[ck_off]     = (unsigned char)(ck & 0xff);
    win[ck_off + 1] = (unsigned char)(ck >> 8);
}

static int vpd_get(int is_bp, unsigned char *p_data, int off, int len) {
    if (!p_data || off < 0 || off > 0xff || len <= 0 || off + len > I2C_MAP_VPD_SIZE)
        return I2B_API_RET_PARAM_ERR;
    unsigned char buf[I2C_MAP_VPD_BUF_SIZE] = {0};
    int rc = ctrlex_read(is_bp, buf);
    if (rc != I2B_API_RET_SUCCESS) return rc;
    memcpy(p_data, buf + I2C_MAP_VPD_DATA_OFF + off, len);
    return I2B_API_RET_SUCCESS;
}

static int vpd_set(int is_bp, const unsigned char *p_data, int off, int len) {
    /* off in [4,0xfd], off+len <= 0xfe (recovered guards). */
    if (!p_data || off < I2C_MAP_VPD_MB_WRITE_MIN || off > 0xfd ||
        len <= 0 || len > I2C_MAP_VPD_WRITE_MAX_LEN || off + len > 0xfe)
        return I2B_API_RET_PARAM_ERR;
    unsigned char buf[I2C_MAP_VPD_BUF_SIZE] = {0};
    int rc = ctrlex_read(is_bp, buf);
    if (rc != I2B_API_RET_SUCCESS) return rc;
    unsigned char *win = buf + I2C_MAP_VPD_DATA_OFF;
    memcpy(win + off, p_data, len);
    vpd_fix_checksum(win, I2C_MAP_VPD_CK1_BODY_OFF, I2C_MAP_VPD_CK1_BODY_U16, I2C_MAP_VPD_CK1_OFF);
    vpd_fix_checksum(win, I2C_MAP_VPD_CK2_BODY_OFF, I2C_MAP_VPD_CK2_BODY_U16, I2C_MAP_VPD_CK2_OFF);
    return ctrlex_write(is_bp, buf);
}

int i2bdg_getMBVPD(unsigned char *p, int off, int len) { return vpd_get(0, p, off, len); }
int i2bdg_getBPVPD(unsigned char *p, int off, int len) { return vpd_get(1, p, off, len); }
int i2bdg_setMBVPD(unsigned char *p, int off, int len) { return vpd_set(0, p, off, len); }
int i2bdg_setBPVPD(unsigned char *p, int off, int len) { return vpd_set(1, p, off, len); }
