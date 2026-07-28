/*
 * encld_curve.c - curve/calibration load + per-zone PWM math for promise-encld.
 *
 * The JSON reader is a tolerant key scanner, not a general parser: it handles
 * exactly the flat generated files the Python driver writes (fanconfig.json,
 * FanCalibration.json). ponytail: assumes no '{' '}' '[' ']' inside string
 * values - true for both files; upgrade to a real parser if they ever nest.
 */
#include "encld_curve.h"

#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

/* ------------------------------------------------------------- defaults */
void encld_default_curves(encld_curve *cpu, encld_curve *sys) {
    /* fanctl.py DEFAULT_FANCONFIG - raw PWM Speedlevels. */
    static const encld_band cpu_b[] = {
        {0, 59, 130}, {60, 69, 160}, {70, 79, 190}, {80, 89, 220}, {90, 100, 250},
    };
    static const encld_band sys_b[] = {
        {0, 59, 40}, {60, 69, 90}, {70, 79, 140}, {80, 89, 190}, {90, 100, 250},
    };
    if (cpu) { cpu->n = 5; memcpy(cpu->b, cpu_b, sizeof cpu_b); }
    if (sys) { sys->n = 5; memcpy(sys->b, sys_b, sizeof sys_b); }
}

/* ------------------------------------------------------------ json scan */
/* Slurp a whole file (these are ~1-4KB). NULL on error; caller frees. */
char *encld_json_slurp(const char *path, long *len_out) {
    FILE *f = fopen(path, "r");
    if (!f) return NULL;
    if (fseek(f, 0, SEEK_END) != 0) { fclose(f); return NULL; }
    long len = ftell(f);
    if (len < 0 || len > 256 * 1024) { fclose(f); return NULL; }
    rewind(f);
    char *buf = malloc((size_t)len + 1);
    if (!buf) { fclose(f); return NULL; }
    size_t rd = fread(buf, 1, (size_t)len, f);
    fclose(f);
    buf[rd] = 0;
    if (len_out) *len_out = (long)rd;
    return buf;
}

/* Find the quoted key within [s,end); return the char after the closing
 * quote, or NULL. Keys in these files are unambiguous (see header note). */
const char *encld_json_key(const char *s, const char *end, const char *key) {
    size_t klen = strlen(key);
    for (const char *p = s; p + klen + 2 <= end; p++) {
        if (p[0] == '"' && strncmp(p + 1, key, klen) == 0 && p[klen + 1] == '"')
            return p + klen + 2;
    }
    return NULL;
}

/* After a key, skip ':' and whitespace, parse an integer. 1 ok / 0 fail. */
static int int_after(const char *p, const char *end, long *out) {
    while (p < end && (isspace((unsigned char)*p) || *p == ':')) p++;
    if (p >= end || (!isdigit((unsigned char)*p) && *p != '-')) return 0;
    char *e = NULL;
    long v = strtol(p, &e, 10);
    if (e == p) return 0;
    *out = v;
    return 1;
}

/* Span of the {...} or [...] starting at the first `open` at/after p.
 * Returns start (at the opener) and sets *close_out just past the closer. */
const char *encld_json_span(const char *p, const char *end, char open,
                            char close, const char **close_out) {
    while (p < end && *p != open) p++;
    if (p >= end) return NULL;
    int depth = 0;
    for (const char *q = p; q < end; q++) {
        if (*q == open) depth++;
        else if (*q == close && --depth == 0) { *close_out = q + 1; return p; }
    }
    return NULL;
}

/* Read one int field inside an object span. 1 ok / 0 missing. */
int encld_json_int(const char *s, const char *end, const char *key, long *out) {
    const char *p = encld_json_key(s, end, key);
    return p ? int_after(p, end, out) : 0;
}

/* Read one string field inside a span: `"<key>": "<value>"`. 1 ok / 0 missing.
 * No escape handling - the values here are interface names and MACs. */
int encld_json_str(const char *s, const char *end, const char *key,
                   char *out, int cap) {
    const char *p = encld_json_key(s, end, key);
    if (!p) return 0;
    while (p < end && (isspace((unsigned char)*p) || *p == ':')) p++;
    if (p >= end || *p != '"') return 0;
    p++;
    int n = 0;
    while (p < end && *p != '"' && n < cap - 1) out[n++] = *p++;
    out[n] = 0;
    return p < end && *p == '"';
}

/* Parse `"<key>": [ {Lower,Upper,Speedlevel}, ... ]` into *c.
 * Returns the number of bands parsed (0 = leave caller's defaults alone). */
static int parse_level_list(const char *s, const char *end, const char *key,
                            encld_curve *c) {
    const char *p = encld_json_key(s, end, key);
    if (!p) return 0;
    const char *aend = NULL;
    const char *arr = encld_json_span(p, end, '[', ']', &aend);
    if (!arr) return 0;

    encld_curve tmp = { 0 };
    const char *q = arr + 1;
    while (tmp.n < ENCLD_MAX_BANDS) {
        const char *oend = NULL;
        const char *obj = encld_json_span(q, aend, '{', '}', &oend);
        if (!obj) break;
        long lo, up, lv;
        if (encld_json_int(obj, oend, "Lower", &lo) &&
            encld_json_int(obj, oend, "Upper", &up) &&
            encld_json_int(obj, oend, "Speedlevel", &lv)) {
            tmp.b[tmp.n].lower = (int)lo;
            tmp.b[tmp.n].upper = (int)up;
            tmp.b[tmp.n].level = (int)lv;
            tmp.n++;
        }
        q = oend;
    }
    if (tmp.n > 0) *c = tmp;
    return tmp.n;
}

int encld_load_fanconfig(const char *path, encld_curve *cpu, encld_curve *sys) {
    long len = 0;
    char *buf = encld_json_slurp(path, &len);
    if (!buf) return 0;
    const char *end = buf + len;
    /* Missing/empty lists keep the defaults (fanctl.py: fanconfig.get(key,
     * DEFAULT); an empty list would make Python return 128 - defaults are
     * the safer failure mode and are what a sane file never triggers). */
    parse_level_list(buf, end, "CpuFanLevelList", cpu);
    parse_level_list(buf, end, "SysFanLevelList", sys);
    free(buf);
    return 1;
}

/* Parse one calibration zone object: MinRPM/MaxRPM + PwmRpmMap[{PWM,RPM}]. */
static void parse_calib_zone(const char *s, const char *end, const char *zone,
                             encld_calib *out) {
    memset(out, 0, sizeof *out);
    const char *p = encld_json_key(s, end, zone);
    if (!p) return;
    const char *zend = NULL;
    const char *zobj = encld_json_span(p, end, '{', '}', &zend);
    if (!zobj) return;

    long minr = 0, maxr = 0;
    if (!encld_json_int(zobj, zend, "MinRPM", &minr)) return;
    if (!encld_json_int(zobj, zend, "MaxRPM", &maxr)) return;

    const char *m = encld_json_key(zobj, zend, "PwmRpmMap");
    if (!m) return;
    const char *aend = NULL;
    const char *arr = encld_json_span(m, zend, '[', ']', &aend);
    if (!arr) return;

    const char *q = arr + 1;
    while (out->n < ENCLD_MAX_MAP) {
        const char *oend = NULL;
        const char *obj = encld_json_span(q, aend, '{', '}', &oend);
        if (!obj) break;
        long pwm, rpm;
        if (encld_json_int(obj, oend, "PWM", &pwm) && encld_json_int(obj, oend, "RPM", &rpm)) {
            out->map[out->n].pwm = (int)pwm;
            out->map[out->n].rpm = (int)rpm;
            out->n++;
        }
        q = oend;
    }
    out->min_rpm = (int)minr;
    out->max_rpm = (int)maxr;
    /* calibration.pct_to_pwm_calibrated usability rule */
    out->valid = (out->n > 0 && out->max_rpm > out->min_rpm);
}

int encld_load_calibration(const char *path, encld_calib *blower,
                           encld_calib *cpufan) {
    if (blower) memset(blower, 0, sizeof *blower);
    if (cpufan) memset(cpufan, 0, sizeof *cpufan);
    long len = 0;
    char *buf = encld_json_slurp(path, &len);
    if (!buf) return 0;
    const char *end = buf + len;
    if (blower) parse_calib_zone(buf, end, "Blower", blower);
    if (cpufan) parse_calib_zone(buf, end, "CpuFan", cpufan);
    free(buf);
    return 1;
}

/* ---- calibration writer (C port of calibration.py _zone + save) ---------- */
void encld_calib_summarize(const encld_cal_sample *s, int n, encld_cal_zone *z) {
    z->min_pwm = n ? s[0].pwm      : ENCLD_PWM_FLOOR;
    z->max_pwm = n ? s[n - 1].pwm  : ENCLD_PWM_MAX;
    z->min_rpm = z->max_rpm = 0;
    int have = 0;
    for (int i = 0; i < n; i++) {
        if (s[i].rpm <= 0) continue;
        if (!have || s[i].rpm < z->min_rpm) z->min_rpm = s[i].rpm;
        if (s[i].rpm > z->max_rpm)          z->max_rpm = s[i].rpm;
        have = 1;
    }
    z->safe_min_pwm = z->min_pwm;                 /* first PWM that actually spins */
    for (int i = 0; i < n; i++)
        if (s[i].rpm >= ENCLD_CAL_SAFE_RPM) { z->safe_min_pwm = s[i].pwm; break; }
}

static void write_zone(FILE *f, const char *name,
                       const encld_cal_sample *s, int n) {
    encld_cal_zone z;
    encld_calib_summarize(s, n, &z);
    fprintf(f, "  \"%s\": {\n", name);
    fprintf(f, "    \"MinPWM\": %d,\n    \"MaxPWM\": %d,\n", z.min_pwm, z.max_pwm);
    fprintf(f, "    \"MinRPM\": %d,\n    \"MaxRPM\": %d,\n", z.min_rpm, z.max_rpm);
    fprintf(f, "    \"SafeMinPWM\": %d,\n", z.safe_min_pwm);
    fprintf(f, "    \"PwmRpmMap\": [\n");
    for (int i = 0; i < n; i++)
        fprintf(f, "      {\"Pct\": %d, \"PWM\": %d, \"RPM\": %d}%s\n",
                s[i].pct, s[i].pwm, s[i].rpm, i < n - 1 ? "," : "");
    fprintf(f, "    ]\n  }");
}

int encld_calib_write(const char *path, const char *date_str, const char *by,
                      const encld_cal_sample *blower, int nb,
                      const encld_cal_sample *cpu, int nc) {
    char tmp[600];
    snprintf(tmp, sizeof tmp, "%s.tmp", path);
    FILE *f = fopen(tmp, "w");
    if (!f) return -1;
    fprintf(f, "{\n  \"CalibratedDate\": \"%s\",\n", date_str ? date_str : "");
    if (by && *by) fprintf(f, "  \"CalibratedBy\": \"%s\",\n", by);
    write_zone(f, "Blower", blower, nb);
    fprintf(f, ",\n");
    write_zone(f, "CpuFan", cpu, nc);
    fprintf(f, "\n}\n");
    if (fclose(f) != 0)        { remove(tmp); return -1; }
    if (rename(tmp, path) != 0) { remove(tmp); return -1; }
    return 0;
}

/* ----------------------------------------------------------------- math */
int encld_speedlevel_for_temp(const encld_curve *c, double temp_c) {
    if (!c || c->n <= 0) return 128;             /* fanctl.py empty-list value */
    int t = (int)temp_c;                          /* see header: no band gaps  */
    for (int i = 0; i < c->n; i++)
        if (c->b[i].lower <= t && t <= c->b[i].upper) return c->b[i].level;
    return c->b[c->n - 1].level;                 /* above all bands -> top    */
}

int encld_pct_to_pwm(const encld_calib *cal, int pct) {
    if (!cal || !cal->valid) return -1;
    double target = cal->min_rpm +
                    (pct / 100.0) * (cal->max_rpm - cal->min_rpm);
    int best = 0;
    double best_d = -1.0;
    for (int i = 0; i < cal->n; i++) {
        double d = cal->map[i].rpm - target;
        if (d < 0) d = -d;
        if (best_d < 0 || d < best_d) { best_d = d; best = i; }  /* first wins */
    }
    return cal->map[best].pwm;
}

static int clamp_floor(int pwm) {
    if (pwm < ENCLD_PWM_FLOOR) return ENCLD_PWM_FLOOR;   /* NEVER below floor */
    if (pwm > ENCLD_PWM_MAX)   return ENCLD_PWM_MAX;
    return pwm;
}

int encld_zone_pwm(const encld_curve *c, const encld_calib *cal,
                   double temp_c, int prev_pwm) {
    if (temp_c >= ENCLD_EMERG_TEMP)
        return clamp_floor(ENCLD_EMERG_PWM);     /* emergency: no hysteresis */

    int level = encld_speedlevel_for_temp(c, temp_c);
    int mapped = encld_pct_to_pwm(cal, level);   /* calibrated: level is a % */
    int pwm = (mapped >= 0) ? mapped : level;    /* else: level is raw PWM   */

    if (prev_pwm >= 0) {                         /* fanctl.py 3% hysteresis  */
        int d = pwm - prev_pwm;
        if (d < 0) d = -d;
        if (d < ENCLD_HYST_PWM) pwm = prev_pwm;
    }
    return clamp_floor(pwm);
}

double encld_ema(double prev, double sample, int first) {
    if (first) return sample;
    return ENCLD_EMA_ALPHA * sample + (1.0 - ENCLD_EMA_ALPHA) * prev;
}
