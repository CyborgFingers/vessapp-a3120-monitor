/*
 * hwmon_sysfs.c - minimal /sys/class/hwmon reader (temps/fans/voltages).
 * Mirrors hwmon.py's hwmon_sysfs_* enumeration. Best-effort: unreadable nodes
 * are skipped. Root overridable via VESSAPP_HWMON_ROOT (test fixtures).
 */
#include "hwmon_sysfs.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <dirent.h>

static const char *root(void) {
    const char *r = getenv("VESSAPP_HWMON_ROOT");
    return (r && *r) ? r : "/sys/class/hwmon";
}

static int read_str(const char *path, char *buf, int cap) {
    FILE *f = fopen(path, "r");
    if (!f) return -1;
    int n = fgets(buf, cap, f) ? (int)strlen(buf) : -1;
    fclose(f);
    if (n > 0 && buf[n - 1] == '\n') buf[--n] = 0;
    return n;
}

static long read_long(const char *path, int *ok) {
    char b[64];
    if (read_str(path, b, sizeof b) < 0) { *ok = 0; return 0; }
    char *end = NULL;
    long v = strtol(b, &end, 10);
    *ok = (end != b);
    return v;
}

/* Generic enumerator over hwmonN/<kind>M_input, labelled by <kind>M_label or
 * "<name>_<kind>M". prefix e.g. "temp"/"fan"/"in". */
static int collect(const char *prefix, hwmon_reading *out, int max) {
    int n = 0;
    DIR *d = opendir(root());
    if (!d) return 0;
    struct dirent *e;
    while (n < max && (e = readdir(d))) {
        if (strncmp(e->d_name, "hwmon", 5) != 0) continue;
        char base[512];
        snprintf(base, sizeof base, "%s/%s", root(), e->d_name);

        char namebuf[32] = "";
        char npath[600];
        snprintf(npath, sizeof npath, "%s/name", base);
        read_str(npath, namebuf, sizeof namebuf);

        for (int idx = 0; idx <= 32 && n < max; idx++) {  /* in* starts at 0 (in0=Vcore) */
            char ipath[640];
            snprintf(ipath, sizeof ipath, "%s/%s%d_input", base, prefix, idx);
            int ok = 0;
            long v = read_long(ipath, &ok);
            if (!ok) continue;

            char lpath[640], lbl[32] = "";
            snprintf(lpath, sizeof lpath, "%s/%s%d_label", base, prefix, idx);
            if (read_str(lpath, lbl, sizeof lbl) <= 0)
                snprintf(lbl, sizeof lbl, "%s_%s%d",
                         namebuf[0] ? namebuf : e->d_name, prefix, idx);

            snprintf(out[n].label, sizeof out[n].label, "%s", lbl);
            out[n].value = v;
            n++;
        }
    }
    closedir(d);
    return n;
}

int hwmon_read_temps(hwmon_reading *out, int max) { return collect("temp", out, max); }
int hwmon_read_fans (hwmon_reading *out, int max) { return collect("fan",  out, max); }
int hwmon_read_volts(hwmon_reading *out, int max) { return collect("in",   out, max); }

/* ---------------------------------------------------------- PWM control */
int hwmon_find_by_name(const char *name, char *dir_out, int cap) {
    DIR *d = opendir(root());
    if (!d) return -1;
    struct dirent *e;
    while ((e = readdir(d))) {
        if (strncmp(e->d_name, "hwmon", 5) != 0) continue;
        char npath[600], nbuf[64];
        snprintf(npath, sizeof npath, "%s/%s/name", root(), e->d_name);
        if (read_str(npath, nbuf, sizeof nbuf) > 0 && strcmp(nbuf, name) == 0) {
            snprintf(dir_out, (size_t)cap, "%s/%s", root(), e->d_name);
            closedir(d);
            return 0;
        }
    }
    closedir(d);
    return -1;
}

static int write_int(const char *path, int v) {
    FILE *f = fopen(path, "w");
    if (!f) return -1;
    int rc = fprintf(f, "%d", v) > 0 ? 0 : -1;
    if (fclose(f) != 0) rc = -1;
    return rc;
}

int hwmon_get_pwm_enable(const char *hwmon_dir, int ch) {
    char p[600];
    snprintf(p, sizeof p, "%s/pwm%d_enable", hwmon_dir, ch);
    int ok = 0;
    long v = read_long(p, &ok);
    return ok ? (int)v : -1;
}

int hwmon_set_pwm_enable(const char *hwmon_dir, int ch, int mode) {
    char p[600];
    snprintf(p, sizeof p, "%s/pwm%d_enable", hwmon_dir, ch);
    return write_int(p, mode);
}

int hwmon_set_pwm(const char *hwmon_dir, int ch, int value) {
    if (value < HWMON_PWM_FLOOR) value = HWMON_PWM_FLOOR;  /* never stop fan */
    if (value > 255)             value = 255;
    /* hwmon.py: flip to manual mode only when it isn't already. */
    int mode = hwmon_get_pwm_enable(hwmon_dir, ch);
    if (mode >= 0 && mode != HWMON_PWM_MANUAL &&
        hwmon_set_pwm_enable(hwmon_dir, ch, HWMON_PWM_MANUAL) != 0)
        return -1;
    char p[600];
    snprintf(p, sizeof p, "%s/pwm%d", hwmon_dir, ch);
    return write_int(p, value);
}

int hwmon_read_fan(const char *hwmon_dir, int ch) {
    char p[600];
    snprintf(p, sizeof p, "%s/fan%d_input", hwmon_dir, ch);
    int ok = 0;
    long v = read_long(p, &ok);
    return ok ? (int)v : -1;
}
