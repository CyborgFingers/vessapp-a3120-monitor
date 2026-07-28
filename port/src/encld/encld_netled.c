/*
 * encld_netled.c - NIC -> front-panel network-LED mapping for promise-encld.
 *
 * Behaviour is the user's Windows frontsvc (frontsvc.decompiled.cs):
 * per LED a NIC match + mode, evaluated every poll; disconnected or
 * unmatched -> OFF; the map file is tiny and re-read whenever it changes.
 * ponytail: no netlink - operstate/carrier polling every daemon tick is
 * exactly what frontsvc did (5-10s), and is plenty for a panel LED.
 */
#include "encld_netled.h"
#include "encld_curve.h"
#include "i2bdg_api.h"

#include <ctype.h>
#include <dirent.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>

int netled_load(const char *path, netled_cfg *cfg) {
    memset(cfg, 0, sizeof *cfg);
    long len = 0;
    char *buf = encld_json_slurp(path, &len);
    if (!buf) return 0;
    const char *end = buf + len;
    static const char *keys[NETLED_COUNT] = { "EthLed1", "EthLed2" };
    for (int i = 0; i < NETLED_COUNT; i++) {
        const char *p = encld_json_key(buf, end, keys[i]);
        if (!p) continue;
        const char *oend = NULL;
        const char *obj = encld_json_span(p, end, '{', '}', &oend);
        if (!obj) continue;
        netled_entry *e = &cfg->led[i];
        encld_json_str(obj, oend, "Interface", e->ifname, sizeof e->ifname);
        encld_json_str(obj, oend, "Mac", e->mac, sizeof e->mac);
        long mode = 0;
        if (encld_json_int(obj, oend, "Mode", &mode) &&
            mode >= NETLED_MODE_BLINK && mode <= NETLED_MODE_OFF)
            e->mode = (int)mode;
    }
    free(buf);
    return 1;
}

/* Read the first line of root/ifname/leaf into out (trimmed). 1 ok / 0 no. */
static int read_net_attr(const char *root, const char *ifname,
                         const char *leaf, char *out, int cap) {
    char path[512];
    snprintf(path, sizeof path, "%s/%s/%s", root, ifname, leaf);
    FILE *f = fopen(path, "r");
    if (!f) return 0;
    if (!fgets(out, cap, f)) { fclose(f); return 0; }
    fclose(f);
    out[strcspn(out, "\r\n")] = 0;
    return 1;
}

/* Case-insensitive MAC compare; ':' and '-' separators are equivalent
 * (the Windows tool stored dash-separated MACs). */
static int mac_eq(const char *a, const char *b) {
    while (*a && *b) {
        char ca = (char)tolower((unsigned char)*a);
        char cb = (char)tolower((unsigned char)*b);
        if (ca == '-') ca = ':';
        if (cb == '-') cb = ':';
        if (ca != cb) return 0;
        a++; b++;
    }
    return *a == *b;
}

int netled_resolve(const char *root, const netled_entry *e, char *out, int cap) {
    if (e->ifname[0]) {
        char path[512];
        struct stat st;
        snprintf(path, sizeof path, "%s/%s", root, e->ifname);
        if (stat(path, &st) == 0) {
            snprintf(out, (size_t)cap, "%s", e->ifname);
            return 1;
        }
    }
    if (!e->mac[0]) return 0;
    DIR *d = opendir(root);
    if (!d) return 0;
    struct dirent *de;
    while ((de = readdir(d)) != NULL) {
        if (de->d_name[0] == '.') continue;
        char addr[64];
        if (read_net_attr(root, de->d_name, "address", addr, sizeof addr) &&
            mac_eq(addr, e->mac)) {
            snprintf(out, (size_t)cap, "%s", de->d_name);
            closedir(d);
            return 1;
        }
    }
    closedir(d);
    return 0;
}

int netled_link_up(const char *root, const char *ifname) {
    char v[32];
    if (read_net_attr(root, ifname, "operstate", v, sizeof v)) {
        if (strcmp(v, "up") == 0) return 1;
        if (strcmp(v, "unknown") != 0) return 0;
    }
    /* operstate absent or "unknown": fall back to the carrier bit */
    if (read_net_attr(root, ifname, "carrier", v, sizeof v))
        return v[0] == '1';
    return 0;
}

unsigned char netled_color(int link_up, int mode) {
    if (!link_up || mode == NETLED_MODE_OFF) return I2B_LED_STS_OFF;
    return mode == NETLED_MODE_SOLID ? I2B_LED_STS_GREEN
                                     : I2B_LED_STS_BLINK_GREEN;
}
