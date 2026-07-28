/* raid_backend.c - VESSAPP_RAID_BACKEND resolver + the mockable command seam. */
#include "raid_backend.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <ctype.h>

static int g_cached = -1;   /* -1 = not resolved yet */

raid_backend_t raid_backend(void) {
    if (g_cached < 0) {
        const char *e = getenv("VESSAPP_RAID_BACKEND");
        if      (e && strcmp(e, "mdadm")   == 0) g_cached = RAID_MDADM;
        else if (e && strcmp(e, "fixture") == 0) g_cached = RAID_FIXTURE;
        else if (e && strcmp(e, "health")  == 0) g_cached = RAID_HEALTH;
        else                                     g_cached = RAID_SGIO;
    }
    return (raid_backend_t)g_cached;
}

void raid_backend_set(int b) {
    g_cached = b;   /* negative -> next raid_backend() re-reads the env */
}

/* ------------------------------------------------------------ command seam */
static raid_cmd_fn g_cmd_fn;

void raid_cmd_set(raid_cmd_fn fn) { g_cmd_fn = fn; }

void raid_cmd_slug(const char *cmd, char *out, size_t cap) {
    size_t i = 0;
    for (; cmd[i] && i < cap - 1; i++)
        out[i] = isalnum((unsigned char)cmd[i]) ? cmd[i] : '_';
    out[i] = '\0';
}

/* RAID_FIXTURE: canned tool output from $VESSAPP_RAID_FIXTURE/<slug(cmd)>. */
static int cmd_from_fixture(const char *cmd, char *out, size_t cap) {
    const char *dir = getenv("VESSAPP_RAID_FIXTURE");
    if (!dir || !*dir) return -1;
    char slug[256], path[512];
    raid_cmd_slug(cmd, slug, sizeof slug);
    snprintf(path, sizeof path, "%s/%s", dir, slug);
    FILE *f = fopen(path, "r");
    if (!f) return -1;
    size_t n = fread(out, 1, cap - 1, f);
    fclose(f);
    out[n] = '\0';
    return 0;
}

static int cmd_popen(const char *cmd, char *out, size_t cap) {
    char line[1024];
    snprintf(line, sizeof line, "%s 2>/dev/null", cmd);
    FILE *p = popen(line, "r");
    if (!p) return -1;
    size_t n = fread(out, 1, cap - 1, p);
    out[n] = '\0';
    int status = pclose(p);
    /* Empty output is a valid answer (e.g. `mdadm --detail --scan` with no
     * arrays exits 0 silently); smartctl exits non-zero with valid output when
     * SMART flags a fault. Fail only when the tool produced nothing AND exited
     * non-zero (missing binary, unreadable device). */
    if (n == 0 && status != 0) return -1;
    return 0;
}

int raid_cmd(const char *cmd, char *out, size_t cap) {
    if (!out || cap < 2) return -1;
    out[0] = '\0';
    if (g_cmd_fn) return g_cmd_fn(cmd, out, cap);
    if (raid_backend() == RAID_FIXTURE) return cmd_from_fixture(cmd, out, cap);
    return cmd_popen(cmd, out, cap);
}

/* Privileged variant: www-data can't run mdadm/smartctl/wipefs, so on the real
 * popen path prepend the setuid helper. The mock + fixture paths run BARE so
 * unit tests and container smokes see the exact bare tool command. */
int raid_cmd_priv(const char *cmd, char *out, size_t cap) {
    if (g_cmd_fn || raid_backend() == RAID_FIXTURE) return raid_cmd(cmd, out, cap);
    const char *h = getenv("PROMISE_RAID_HELPER");
    if (!h || !*h) h = "/opt/promise/bin/promise-raid-helper";
    char wrapped[1400];
    snprintf(wrapped, sizeof wrapped, "%s %s", h, cmd);
    return raid_cmd(wrapped, out, cap);
}
