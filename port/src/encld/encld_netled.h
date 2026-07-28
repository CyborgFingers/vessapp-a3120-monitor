/*
 * encld_netled.h - front-panel network-LED assignment for promise-encld.
 *
 * Linux reimplementation of the user's Windows FrontPanel patch (frontsvc):
 * a JSON map (/etc/promise/frontpanel.json, written by WebPAM frontpanel.php)
 * assigns a host NIC to each wired network-panel LED (ETH1 0x12 / ETH2 0x13 on
 * the PCA9555 @0x21 - see smbus_encl.c; ETH3/4 are not wired on this chassis).
 * Per LED: which interface, and a mode matching the Windows tool -
 *   0 = blink when connected (SMBus path renders blink as steady - see
 *       smbus_encl.c colour note), 1 = solid when connected, 2 = always off.
 * Link state comes from /sys/class/net/<if>/operstate (carrier fallback).
 *
 * Pure logic + sysfs/file reads (root parameterised) - unit-tests off-device.
 */
#ifndef ENCLD_NETLED_H
#define ENCLD_NETLED_H

#define NETLED_COUNT       2      /* wired panel LEDs: ETH1, ETH2            */
#define NETLED_MODE_BLINK  0      /* frontsvc Mode 0: blink when connected   */
#define NETLED_MODE_SOLID  1      /* frontsvc Mode 1: solid when connected   */
#define NETLED_MODE_OFF    2      /* frontsvc Mode 2: always off             */

typedef struct {
    char ifname[64];              /* configured interface name ("" = none)   */
    char mac[24];                 /* fallback match, aa:bb:cc:dd:ee:ff        */
    int  mode;                    /* NETLED_MODE_*                            */
} netled_entry;

typedef struct { netled_entry led[NETLED_COUNT]; } netled_cfg;

/* Load the JSON map {"EthLed1":{Interface,Mac,Mode},"EthLed2":{...}}.
 * Returns 1 if the file was read (missing keys leave zeroed entries -> LED
 * unassigned -> OFF, the safe state), 0 if it could not be read. */
int netled_load(const char *path, netled_cfg *cfg);

/* Resolve an entry to a live interface under root (/sys/class/net): the
 * configured name if it exists, else the interface whose `address` matches
 * mac (case-insensitive; '-' and ':' separators both accepted, matching the
 * Windows tool's MAC format). 1 = found (out filled), 0 = none. */
int netled_resolve(const char *root, const netled_entry *e, char *out, int cap);

/* 1 = operstate "up", or carrier 1 when operstate is unknown/absent. */
int netled_link_up(const char *root, const char *ifname);

/* frontsvc GetEthLedState: not up -> OFF; up -> mode colour
 * (I2B_LED_STS_BLINK_GREEN / _GREEN / _OFF). */
unsigned char netled_color(int link_up, int mode);

#endif /* ENCLD_NETLED_H */
