/*
 * smbus_encl.c - LED FRU->PCA9555 pin map, fan curve, and hwmon/NCT7802Y reads.
 *
 * LED-FRU -> PCA9555 pin table (green_pin, red_pin), reused verbatim from the
 * proven Python driver (ledctl.py). -1 = pin absent (single-colour LED).
 *
 *   FRU (i2bdg_api.h)      addr  green  red   panel line (ledctl.py)
 *   HDD_SLOT1  0x80        0x20    0     1    HDD1 green/red
 *   HDD_SLOT2  0x81        0x20    2     3    HDD2
 *   HDD_SLOT3  0x82        0x20    4     5    HDD3
 *   HDD_SLOT4  0x83        0x20    6     7    HDD4
 *   USB1       0x0d        0x21    0     1    USB green/red
 *   FAN1       0x02        0x21    2     3    Blower green/red
 *   RAID_STS   0x0c        0x21    4     5    RAID green/red
 *   PWR        0x14        0x21    6    -1    Power green (green-only)
 *   RECORD     0x0f        0x21   -1     7    Recording red (red-only)
 *   SYSTEM     0x10        0x21    8     9    System status green/red
 *   ETH_PORT1  0x12        0x21   11    10    Net1 solid(green) / flash(red slot)
 *   ETH_PORT2  0x13        0x21   13    12    Net2 solid / flash
 *
 * UNRESOLVED (not wired on this box's panels - the FrontPanel PCA9555 exposes 4
 * bays; the Python driver maps no pins for these): HDD_SLOT5..16, FAN2, USB2,
 * ETH_PORT3/4, GLOBAL_HDD_STS, HeartBeat. setLED on them -> UNSUPPORTED.
 *
 * Colour -> pins: GREEN=green on/red off, RED=green off/red on, AMBER=both,
 * OFF=both off. BLINK_* is set as the steady colour (autonomous blink needs a
 * software toggler - the Python _blink_loop - which is out of scope for setLED).
 */
#include "smbus_encl.h"
#include "pca9555.h"
#include "nct7802y.h"
#include "hwmon_sysfs.h"
#include "i2bdg.h"

#include <stdlib.h>
#include <string.h>
#include <stdio.h>

#define FP_ADDR 0x20   /* FrontPanel  */
#define NP_ADDR 0x21   /* NetworkPanel */

#define MAX_FANS  8
#define MAX_TEMPS 16
#define MAX_VOLTS 16
#define BLOWER_MIN_RPM 600   /* golden CLU healthy threshold "> 600 RPM" - reported as FanThrd */

/* ------------------------------------------------------------------ session */
static i2c_bus *g_bus;       /* cached (owned)          */
static i2c_bus *g_injected;  /* test override (borrowed) */
static int      g_opened;
static int      g_nct_inited;

void smbus_encl_set_bus(i2c_bus *b) {
    g_injected = b;
    g_nct_inited = 0;
}

void smbus_encl_reset(void) {
    if (g_bus) { i2c_bus_close(g_bus); g_bus = NULL; }
    g_opened = 0;
    g_nct_inited = 0;
}

i2c_bus *smbus_encl_bus(void) {
    if (g_injected) return g_injected;
    if (g_opened)   return g_bus;
    g_opened = 1;
    const char *e = getenv("VESSAPP_I2C_BUS");
    int busnum = (e && *e) ? atoi(e) : 0;   /* i801 is i2c-0 on this box */
    g_bus = i2c_bus_open_dev(busnum);
    return g_bus;
}

static void ensure_nct(i2c_bus *b) {
    if (!g_nct_inited) { nct7802y_init(b, NCT7802Y_ADDR); g_nct_inited = 1; }
}

/* ---------------------------------------------------------------- LED table */
typedef struct { unsigned char id; uint8_t addr; int8_t green; int8_t red; } led_map;

static const led_map LEDS[] = {
    { I2B_LED_HDD_SLOT1, FP_ADDR,  0,  1 },
    { I2B_LED_HDD_SLOT2, FP_ADDR,  2,  3 },
    { I2B_LED_HDD_SLOT3, FP_ADDR,  4,  5 },
    { I2B_LED_HDD_SLOT4, FP_ADDR,  6,  7 },
    { I2B_LED_USB1,      NP_ADDR,  0,  1 },
    { I2B_LED_FAN1,      NP_ADDR,  2,  3 },
    { I2B_LED_RAID_STS,  NP_ADDR,  4,  5 },
    { I2B_LED_PWR,       NP_ADDR,  6, -1 },
    { I2B_LED_RECORD,    NP_ADDR, -1,  7 },
    { I2B_LED_SYSTEM,    NP_ADDR,  8,  9 },
    { I2B_LED_ETH_PORT1, NP_ADDR, 11, 10 },
    { I2B_LED_ETH_PORT2, NP_ADDR, 13, 12 },
};

static const led_map *find_led(unsigned char id) {
    for (unsigned i = 0; i < sizeof LEDS / sizeof LEDS[0]; i++)
        if (LEDS[i].id == id) return &LEDS[i];
    return NULL;
}

static void color_to_pins(unsigned char color, int *g_on, int *r_on) {
    switch (color) {
        case I2B_LED_STS_GREEN: case I2B_LED_STS_BLINK_GREEN: *g_on = 1; *r_on = 0; break;
        case I2B_LED_STS_RED:   case I2B_LED_STS_BLINK_RED:   *g_on = 0; *r_on = 1; break;
        case I2B_LED_STS_AMBER: case I2B_LED_STS_BLINK_AMBER: *g_on = 1; *r_on = 1; break;
        case I2B_LED_STS_OFF: default:                        *g_on = 0; *r_on = 0; break;
    }
}

static unsigned char pins_to_color(int g_on, int r_on) {
    if (g_on && r_on) return I2B_LED_STS_AMBER;
    if (g_on)         return I2B_LED_STS_GREEN;
    if (r_on)         return I2B_LED_STS_RED;
    return I2B_LED_STS_OFF;
}

int smbus_led_write(unsigned char id, unsigned char color, unsigned short cause) {
    (void)cause;   /* SYSTEM cause has no dedicated pin on this panel (UNRESOLVED) */
    const led_map *m = find_led(id);
    if (!m) return I2B_API_RET_UNSUPPORTED;
    i2c_bus *b = smbus_encl_bus();
    if (!b) return I2B_API_RET_I2API_ERR;

    int g_on, r_on;
    color_to_pins(color, &g_on, &r_on);

    int rc;
    if (m->green >= 0 && m->red >= 0)
        rc = pca9555_set_pair(b, m->addr, (uint8_t)m->green, (uint8_t)m->red, g_on, r_on);
    else if (m->green >= 0)
        rc = pca9555_set_pin(b, m->addr, (uint8_t)m->green, g_on);   /* green-only */
    else
        rc = pca9555_set_pin(b, m->addr, (uint8_t)m->red, r_on);     /* red-only   */
    return rc < 0 ? I2B_API_RET_I2API_ERR : I2B_API_RET_SUCCESS;
}

int smbus_led_read(unsigned char id, unsigned char *color, unsigned short *cause) {
    const led_map *m = find_led(id);
    if (!m) return I2B_API_RET_UNSUPPORTED;
    i2c_bus *b = smbus_encl_bus();
    if (!b) return I2B_API_RET_I2API_ERR;

    int g_on = 0, r_on = 0;
    if (m->green >= 0) { int v = pca9555_get_pin(b, m->addr, (uint8_t)m->green);
                         if (v < 0) return I2B_API_RET_I2API_ERR; g_on = v; }
    if (m->red >= 0)   { int v = pca9555_get_pin(b, m->addr, (uint8_t)m->red);
                         if (v < 0) return I2B_API_RET_I2API_ERR; r_on = v; }
    if (color) *color = pins_to_color(g_on, r_on);
    if (cause) *cause = 0;   /* cause not stored in hardware (UNRESOLVED) */
    return I2B_API_RET_SUCCESS;
}

/* ---------------------------------------------------------------- fan curve */
typedef struct { int upper; int level; } fan_band;

/* fanctl.py DEFAULT_FANCONFIG, raw-PWM mode (no calibration file present).
 * CALIBRATE: if a FanCalibration.json is provided on-device these become
 * percentages of a measured RPM range - see fanctl.py. */
static const fan_band CPU_CURVE[] = {{59,130},{69,160},{79,190},{89,220},{100,250}};
static const fan_band SYS_CURVE[] = {{59, 40},{69, 90},{79,140},{89,190},{100,250}};

int smbus_fan_pwm_for_temp(int temp_c, int profile_cpu) {
    const fan_band *c = profile_cpu ? CPU_CURVE : SYS_CURVE;
    int n = 5, pwm = c[n - 1].level;   /* above all bands -> top level */
    for (int i = 0; i < n; i++)
        if (temp_c <= c[i].upper) { pwm = c[i].level; break; }
    if (pwm < NCT7802Y_PWM_FLOOR) pwm = NCT7802Y_PWM_FLOOR;  /* never below floor */
    return pwm;
}

int smbus_fan_apply(int temp_c, int profile_cpu) {
    i2c_bus *b = smbus_encl_bus();
    if (!b) return -1;
    ensure_nct(b);
    int pwm = smbus_fan_pwm_for_temp(temp_c, profile_cpu);
    return nct7802y_set_pwm(b, NCT7802Y_ADDR, 1, pwm);   /* FANCTL1+2, re-floored */
}

/* -------------------------------------------------------------- collectors */
typedef struct { char name[32]; int rpm; unsigned char sts; } fan_rec;

/* CALIBRATE (on-box 2026-07): the golden SEP view (3 blowers "Cooling Unit N",
 * CPU+Controller temps, four 1.0/5.0/12.0/3.3V "Controller" rails) only fully
 * populates via the SG_IO/SEP path once the controller runs in RAID mode.
 * Until then this backend reports what the bare board AUTHENTICALLY exposes -
 * the NCT7802Y blower plus filtered nct6776/coretemp readings - and never
 * pads the enclosure page with motherboard noise (unpopulated 0-RPM headers,
 * floating negative temp inputs, divider-mangled voltage pins). */

/* Per-fan enable from fanconfig.json's "FanEnable" object (written by the
 * fan-curve settings page). Absent key = enabled. A DISABLED slot is nulled -
 * excluded from the enclosure view and it never raises an alarm; an ENABLED
 * slot reading 0 RPM is a real fault (present but stopped). Targeted scan (the
 * file is machine-written json) - no dependency on the daemon's json helpers. */
static int fan_enabled(const char *name) {
    const char *path = getenv("PROMISE_FANCONFIG");
    if (!path || !*path) return 1;
    FILE *f = fopen(path, "r");
    if (!f) return 1;
    char buf[8192];
    size_t len = fread(buf, 1, sizeof buf - 1, f);
    fclose(f);
    buf[len] = 0;
    char *fe = strstr(buf, "\"FanEnable\"");
    if (!fe) return 1;
    char key[80];
    snprintf(key, sizeof key, "\"%s\"", name);
    char *k = strstr(fe, key);
    if (!k) return 1;                         /* not listed -> enabled */
    char *colon = strchr(k + strlen(key), ':');
    if (!colon) return 1;
    char *v = colon + 1;
    while (*v == ' ' || *v == '\t' || *v == '\n' || *v == '\r') v++;
    return strncmp(v, "false", 5) == 0 ? 0 : 1;
}

static int collect_fans(fan_rec *out, int max) {
    int n = 0;
    /* Fan 1 = CPU fan, from the nct6776 Super-I/O fan2 header (matches the vendor's
     * "Fan 1 = CPU"). The Super-I/O's other slot (nct6776_fan1 = System Fan) is not
     * fitted on this chassis, and any GPU/other hwmon fan is not chassis cooling -
     * so ONLY nct6776_fan2 is taken from hwmon. */
    hwmon_reading hw[MAX_FANS];
    int hn = hwmon_read_fans(hw, MAX_FANS);
    for (int i = 0; i < hn && n < max; i++) {
        if (strcmp(hw[i].label, "nct6776_fan2") != 0) continue;   /* CPU fan only */
        if (!fan_enabled("CPU Fan")) continue;
        snprintf(out[n].name, sizeof out[n].name, "CPU Fan");
        out[n].rpm = (int)hw[i].value;
        out[n].sts = hw[i].value > 0 ? FAN_STS_FUNCTIONAL : FAN_STS_MALFUNCTION;
        n++;
    }
    /* Fans 2/3 = the two chassis blowers on the NCT7802Y (ch1 + ch2). An enabled
     * blower reading 0 RPM is a real fault; the operator nulls a slot with the
     * FanEnable toggle in the fan settings page. */
    i2c_bus *b = smbus_encl_bus();
    if (b && fan_enabled("Blower")) {
        ensure_nct(b);
        for (int ch = 1; ch <= 2 && n < max; ch++) {
            int rpm = nct7802y_read_rpm(b, NCT7802Y_ADDR, ch);
            if (rpm < 0) continue;
            snprintf(out[n].name, sizeof out[n].name, "Blower");
            out[n].rpm = rpm;
            out[n].sts = rpm > 0 ? FAN_STS_FUNCTIONAL : FAN_STS_MALFUNCTION;
            n++;
        }
    }
    return n;
}

/* Golden A3120 temp model: exactly two sensors, "CPU" and "Controller",
 * healthy < 90 C (warn) / 100 C (crit). CPU <- coretemp "Package id 0"
 * (fallback "Core 0"); Controller <- nct6776 "SYSTIN" - the only valid board
 * sensor on this box (CPUTIN/AUXTIN float negative = unwired). */
#define TEMP_WARN_C 90
#define TEMP_CRIT_C 100

static int collect_temps(hwmon_reading *out, int max) {
    hwmon_reading t[MAX_TEMPS];
    int n = hwmon_read_temps(t, MAX_TEMPS);
    const hwmon_reading *cpu = NULL, *ctrl = NULL;
    for (int i = 0; i < n; i++) {
        if (t[i].value <= 0) continue;                 /* floating input = junk */
        if (!cpu  && strncmp(t[i].label, "Package id", 10) == 0) cpu  = &t[i];
        if (!ctrl && strcmp(t[i].label, "SYSTIN") == 0)          ctrl = &t[i];
    }
    if (!cpu)
        for (int i = 0; i < n; i++)
            if (t[i].value > 0 && strcmp(t[i].label, "Core 0") == 0) { cpu = &t[i]; break; }
    int c = 0;
    if (cpu && c < max) {
        out[c].value = cpu->value;
        snprintf(out[c].label, sizeof out[c].label, "CPU");
        c++;
    }
    if (ctrl && c < max) {
        out[c].value = ctrl->value;
        snprintf(out[c].label, sizeof out[c].label, "Controller");
        c++;
    }
    return c;
}

/* Rails the nct6776 reports in real millivolts on this board (the kernel
 * driver compensates the internal Vcore/AVCC/3VCC scaling; the other inN pins
 * sit behind unknown external dividers -> raw pin mV, not rail volts ->
 * dropped, and the chip cannot read a clean +5/+12 at all - never invent
 * them from motherboard readings). */
static const struct { const char *hwlabel; const char *rail; } VOLT_RAILS[] = {
    { "nct6776_in0", "Vcore" },
    { "nct6776_in2", "AVCC"  },
    { "nct6776_in3", "+3.3V" },
};

static int collect_volts(hwmon_reading *out, int max) {
    hwmon_reading v[MAX_VOLTS];
    int n = hwmon_read_volts(v, MAX_VOLTS);
    int c = 0;
    for (unsigned r = 0; r < sizeof VOLT_RAILS / sizeof VOLT_RAILS[0] && c < max; r++)
        for (int i = 0; i < n; i++)
            if (strcmp(v[i].label, VOLT_RAILS[r].hwlabel) == 0) {
                out[c].value = v[i].value;
                snprintf(out[c].label, sizeof out[c].label, "%s", VOLT_RAILS[r].rail);
                c++;
                break;
            }
    return c;
}

I2B_API_RET_E smbus_getElementCnt(I2bElementCount_t *out) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    memset(out, 0, sizeof *out);
    fan_rec f[MAX_FANS];
    hwmon_reading t[MAX_TEMPS], v[MAX_VOLTS];
    out->NumOfFans           = (unsigned char)collect_fans(f, MAX_FANS);
    out->NumOfTempSensors    = (unsigned char)collect_temps(t, MAX_TEMPS);
    out->NumOfVoltageSensors = (unsigned char)collect_volts(v, MAX_VOLTS);
    out->NumOfHDSlots        = 4;   /* FrontPanel exposes 4 bays (see LED table) */
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E smbus_getFanInfo(I2bFanData_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    fan_rec f[MAX_FANS];
    int n = collect_fans(f, MAX_FANS);
    if (buflen < (unsigned long)n * sizeof(I2bFanData_t)) return I2B_API_RET_PARAM_ERR;
    for (int i = 0; i < n; i++) {
        I2bFanData_t *d = &out[i];
        memset(d, 0, sizeof *d);
        d->FanId  = (unsigned long)(i + 1);
        d->FanSpd = (unsigned long)f[i].rpm;
        d->FanThrd = BLOWER_MIN_RPM;
        d->FanStsFlag = f[i].sts;
        snprintf((char *)d->FanLoc, sizeof d->FanLoc, "%s", f[i].name);
        const char *s = f[i].sts == FAN_STS_FUNCTIONAL  ? "Functional"
                      : f[i].sts == FAN_STS_MALFUNCTION ? "Malfunctioning"
                      : "Not Installed";
        strncpy((char *)d->FanSts, s, sizeof d->FanSts - 1);
    }
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E smbus_getThermalSensorInfo(I2bThermalSensorData_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    hwmon_reading t[MAX_TEMPS];
    int n = collect_temps(t, MAX_TEMPS);
    if (buflen < (unsigned long)n * sizeof(I2bThermalSensorData_t)) return I2B_API_RET_PARAM_ERR;
    for (int i = 0; i < n; i++) {
        I2bThermalSensorData_t *d = &out[i];
        memset(d, 0, sizeof *d);
        int degc = (int)(t[i].value / 1000);                /* milli-degC -> degC */
        d->SensorId = (unsigned char)(i + 1);
        d->CurTemp  = (unsigned char)degc;
        d->WarnThreshold = TEMP_WARN_C;    /* golden: ctrl warn 90 C  */
        d->CritThreshold = TEMP_CRIT_C;    /* golden: ctrl crit 100 C */
        d->StatusFlag = degc >= TEMP_CRIT_C ? ENC_TEMERATURE_STS_OVER_CRITICAL
                      : degc >= TEMP_WARN_C ? ENC_TEMERATURE_STS_OVER_WARNING
                                            : ENC_TEMERATURE_STS_NORMAL;
        snprintf((char *)d->Location, sizeof d->Location, "%s", t[i].label);
    }
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E smbus_getVoltageSensorInfo(I2bVoltageSensorData_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    /* CALIBRATE: the full golden 1.0/5.0/12.0/3.3V "Controller" rail set is a
     * SEP reading - it populates via the SG_IO path once the controller is in
     * RAID mode. Until then only the whitelisted board rails (VOLT_RAILS) are
     * reported, in real volts. */
    hwmon_reading v[MAX_VOLTS];
    int n = collect_volts(v, MAX_VOLTS);
    if (buflen < (unsigned long)n * sizeof(I2bVoltageSensorData_t)) return I2B_API_RET_PARAM_ERR;
    for (int i = 0; i < n; i++) {
        I2bVoltageSensorData_t *d = &out[i];
        memset(d, 0, sizeof *d);
        long dv = (v[i].value + 50) / 100;   /* milli-V -> tenths, rounded */
        unsigned whole  = (unsigned)(dv / 10);
        unsigned tenths = (unsigned)(dv % 10);
        d->SensorId = (unsigned char)(i + 1);
        d->CurVolt  = (unsigned char)(((whole & 0xf) << 4) | (tenths & 0xf)); /* hi.lo, PHP "%u.%uV" */
        d->StatusFlag = VOLTAGE_STS_NORMAL;
        strncpy((char *)d->Type, v[i].label, sizeof d->Type - 1);
    }
    return I2B_API_RET_SUCCESS;
}
