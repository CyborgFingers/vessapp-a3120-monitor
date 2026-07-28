/*
 * encld_curve.h - promise-encld fan-curve core: fanconfig.json band curves,
 * FanCalibration.json percentage->PWM mapping, and the per-zone PWM decision
 * (emergency / curve / calibration / hysteresis / floor).
 *
 * Semantics are the Python driver's (fanctl.py + calibration.py), which the
 * live A3120 runs today:
 *   - fanconfig.json {Cpu,Sys}FanLevelList bands {Lower,Upper,Speedlevel}.
 *   - With a usable FanCalibration.json zone (MinRPM<MaxRPM + PwmRpmMap),
 *     Speedlevel is a PERCENTAGE of the calibrated RPM range; the PWM is the
 *     map entry whose RPM is nearest the target. Without calibration,
 *     Speedlevel is raw PWM (0-255) - the DEFAULT_FANCONFIG mode.
 *   - Emergency: temp >= 85C -> full PWM, bypassing hysteresis.
 *   - Hysteresis: hold previous PWM when the change is < 3% (7 counts).
 *   - SAFETY: every result is floored at ENCLD_PWM_FLOOR - never 0.
 *
 * Pure logic - no I2C/sysfs here - so it unit-tests off-device (test_encld).
 */
#ifndef ENCLD_CURVE_H
#define ENCLD_CURVE_H

#define ENCLD_MAX_BANDS   16
#define ENCLD_MAX_MAP     48
#define ENCLD_PWM_FLOOR   20     /* == NCT7802Y_PWM_FLOOR / hwmon.py floor  */
#define ENCLD_PWM_MAX     255
#define ENCLD_EMERG_TEMP  85.0   /* config.yaml emergency_temp              */
#define ENCLD_EMERG_PWM   255    /* emergency_speed 100% -> pct_to_pwm=255  */
#define ENCLD_HYST_PWM    7      /* hysteresis 3% -> int(3*255/100)         */
#define ENCLD_EMA_ALPHA   0.2    /* fanctl.py TEMP_EMA_ALPHA                */

typedef struct { int lower, upper, level; } encld_band;
typedef struct { int n; encld_band b[ENCLD_MAX_BANDS]; } encld_curve;

typedef struct { int pwm, rpm; } encld_map_ent;
typedef struct {
    int valid;                   /* usable: min_rpm<max_rpm and n>0          */
    int min_rpm, max_rpm;
    int n;
    encld_map_ent map[ENCLD_MAX_MAP];
} encld_calib;

#define ENCLD_CAL_SAFE_RPM 500   /* calibration.py: SafeMinPWM = first PWM >= this RPM */

/* One swept calibration point (a PWM step and the RPM it produced). */
typedef struct { int pct, pwm, rpm; } encld_cal_sample;
/* Summary of a swept zone (calibration.py _zone()). */
typedef struct { int min_pwm, max_pwm, min_rpm, max_rpm, safe_min_pwm; } encld_cal_zone;

/* fanctl.py DEFAULT_FANCONFIG (raw-PWM Speedlevels). */
void encld_default_curves(encld_curve *cpu, encld_curve *sys);

/* Load fanconfig.json. A list that is missing or unparseable keeps the
 * default already in *cpu / *sys. Returns 1 if the file was read, else 0. */
int encld_load_fanconfig(const char *path, encld_curve *cpu, encld_curve *sys);

/* Load FanCalibration.json zones "Blower" -> *blower, "CpuFan" -> *cpufan.
 * Unusable/missing zones come back valid=0. Returns 1 if the file was read. */
int encld_load_calibration(const char *path, encld_calib *blower,
                           encld_calib *cpufan);

/* Summarise swept samples into a zone (min/max PWM+RPM, SafeMinPWM). Pure. */
void encld_calib_summarize(const encld_cal_sample *s, int n, encld_cal_zone *out);

/* Write FanCalibration.json from swept samples (schema encld_load_calibration
 * reads: Blower/CpuFan zones with MinRPM/MaxRPM + PwmRpmMap[{PWM,RPM}], plus
 * Pct + summary fields the WebPAM pages show). Atomic (tmp+rename). date_str is
 * "YYYY-MM-DD HH:MM:SS" (caller formats - keeps this file clock-free); by may be
 * NULL. Returns 0 on success, -1 on write error. */
int encld_calib_write(const char *path, const char *date_str, const char *by,
                      const encld_cal_sample *blower, int nb,
                      const encld_cal_sample *cpu, int nc);

/* fanctl.speedlevel_for_temp: first band with Lower<=t<=Upper, else the last
 * band's level (128 for an empty curve). The temperature is truncated to
 * whole degrees first so integer band edges (…59 | 60…) can't be skipped by
 * a fractional EMA value (fanctl.py would briefly jump to the top band). */
int encld_speedlevel_for_temp(const encld_curve *c, double temp_c);

/* calibration.pct_to_pwm_calibrated: pct of [MinRPM..MaxRPM] -> PWM of the
 * nearest-RPM map entry (first wins on ties). -1 if the zone is unusable. */
int encld_pct_to_pwm(const encld_calib *cal, int pct);

/* One zone decision. prev_pwm < 0 = no previous value (first tick).
 * Result is always within [ENCLD_PWM_FLOOR, ENCLD_PWM_MAX]. */
int encld_zone_pwm(const encld_curve *c, const encld_calib *cal,
                   double temp_c, int prev_pwm);

/* Exponential moving average (first!=0 seeds with the sample). */
double encld_ema(double prev, double sample, int first);

/* ---- tolerant JSON scanner (shared with encld_netled) --------------------
 * Key/span/int/string lookups for the flat generated files this daemon reads
 * (fanconfig / FanCalibration / frontpanel). Same caveats as the .c header
 * note: no braces or escapes inside string values. */
char *encld_json_slurp(const char *path, long *len_out);      /* caller frees */
const char *encld_json_key(const char *s, const char *end, const char *key);
const char *encld_json_span(const char *p, const char *end, char open,
                            char close, const char **close_out);
int encld_json_int(const char *s, const char *end, const char *key, long *out);
int encld_json_str(const char *s, const char *end, const char *key,
                   char *out, int cap);

#endif /* ENCLD_CURVE_H */
