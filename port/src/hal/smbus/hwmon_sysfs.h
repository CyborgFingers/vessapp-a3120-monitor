/*
 * hwmon_sysfs.h - read temps / fans / voltages from /sys/class/hwmon.
 *
 * The A3120's board temperatures and the nct6776 fan tachs are exposed by the
 * kernel hwmon drivers (nct6776 = hwmon0, coretemp, ...). This mirrors the
 * proven Python driver's hwmon_sysfs_temps / hwmon_sysfs_fans. The sysfs root is
 * overridable via VESSAPP_HWMON_ROOT so it can be pointed at a test fixture.
 */
#ifndef HWMON_SYSFS_H
#define HWMON_SYSFS_H

typedef struct {
    char label[32];
    long value;    /* temps: milli-degC; fans: RPM; volts: milli-V */
} hwmon_reading;

/* Fill up to max readings; return the count found (>=0). */
int hwmon_read_temps(hwmon_reading *out, int max);
int hwmon_read_fans (hwmon_reading *out, int max);
int hwmon_read_volts(hwmon_reading *out, int max);

/* ---- PWM control (the CPU fan rides the kernel nct6776 hwmon driver) ----
 * Mirrors hwmon.py find_hwmon_by_name / hwmon_set_pwm_by_dir. */

#define HWMON_PWM_FLOOR   20   /* hwmon.py hard floor - never below ~8%      */
#define HWMON_PWM_MANUAL  1    /* pwmN_enable: manual                         */
#define HWMON_PWM_AUTO    2    /* pwmN_enable: thermal cruise (hardware auto) */

/* Resolve the hwmonN dir whose `name` file matches (survives reboots, the
 * index can move). Fills dir_out; returns 0 found / -1 not found. */
int hwmon_find_by_name(const char *name, char *dir_out, int cap);

/* Read pwm<ch>_enable; -1 if absent/unreadable. */
int hwmon_get_pwm_enable(const char *hwmon_dir, int ch);

/* Write pwm<ch>_enable. 0 ok / -1 error. */
int hwmon_set_pwm_enable(const char *hwmon_dir, int ch, int mode);

/* Write pwm<ch>, clamped to [HWMON_PWM_FLOOR,255] - NEVER stops the fan.
 * Switches pwm<ch>_enable to manual (1) first if it isn't already
 * (hwmon.py hwmon_set_pwm_by_dir). 0 ok / -1 error. */
int hwmon_set_pwm(const char *hwmon_dir, int ch, int value);

/* Read fan<ch>_input (RPM) from a specific hwmon dir. -1 on error. Used by the
 * calibration sweep to record the CPU fan's RPM at each PWM step. */
int hwmon_read_fan(const char *hwmon_dir, int ch);

#endif /* HWMON_SYSFS_H */
