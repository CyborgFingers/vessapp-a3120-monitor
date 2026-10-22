/*
 * promise_encld.c - the A3120 enclosure runtime daemon: the C replacement for
 * the Python promise-hwmon fan/LED service on the bare-Linux box.
 *
 * Loop (default 5s): read the CPU temperature (coretemp "Core 0", like the
 * Python driver's config.yaml zones) -> EMA-smooth -> compute each fan zone's
 * PWM from its fanconfig.json curve (FanCalibration.json percentage mode when
 * usable, raw-PWM Speedlevels otherwise; DEFAULT_FANCONFIG when no file):
 *
 *   blower zone (SysFanLevelList) -> NCT7802Y i2c-0 0x2e FANCTL1+FANCTL2
 *   CPU fan zone (CpuFanLevelList) -> nct6776 hwmon pwm2 (manual mode)
 *
 * then refresh the status LEDs (PCA9555 0x21): Power green, Blower green/red
 * from tach health (<500 RPM for 60s = fault), System green/red; and drive
 * the network-panel ETH LEDs from the frontpanel.json NIC map (encld_netled).
 * fanconfig.json and frontpanel.json are hot-reloaded on mtime change, so the
 * WebPAM fan-curve editor and frontpanel page apply without a restart.
 *
 * SAFETY: both writers floor PWM at 20 - the fans are never stopped. On
 * SIGTERM/SIGINT the daemon exits leaving the fans safe: the blower is
 * re-commanded from the conservative built-in raw sys curve (>= the
 * calibrated value for the same temp) and the CPU fan's pwmN_enable is
 * returned to hardware-auto so the kernel keeps cooling with no daemon.
 *
 * Validation modes (see docs/DEPLOY-ONDEVICE.md):
 *   --dry-run [--temp C] [--once]  compute + print; NO i2c/sysfs writes at all
 *   --once                         one real control cycle, then the safe exit
 */
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <sys/stat.h>
#include <time.h>
#include <dirent.h>   /* /sys/block scan for HDD/RAID status LEDs */
#include <unistd.h>

#include "encld_curve.h"
#include "encld_netled.h"
#include "hwmon_sysfs.h"
#include "smbus_encl.h"
#include "nct7802y.h"
#include "i2bdg_api.h"

#define DEF_FANCONFIG   "/var/lib/promise-hwmon/config/fanconfig.json"
#define DEF_CALIBRATION "/var/lib/promise-hwmon/data/FanCalibration.json"
#define DEF_FRONTPANEL  "/etc/promise/frontpanel.json"
#define SYSNET_ROOT     "/sys/class/net"
#define DEF_SENSOR      "Core 0"   /* config.yaml fans[].sensor              */
#define DEF_CPU_HWMON   "nct6776"  /* config.yaml fan_controllers hwmon_name */
#define DEF_CPU_PWM_CH  2          /* config.yaml CpuFan pwm_channel         */
#define DEF_INTERVAL_S  5
#define BLOWER_MIN_RPM  500        /* config.yaml monitoring.blower_min_rpm  */
#define BLOWER_GRACE_S  60         /* config.yaml monitoring.blower_fail_grace */
#define MAX_TEMPS       32

static volatile sig_atomic_t g_stop;
static void on_signal(int sig) { (void)sig; g_stop = 1; }

static const char *envs(const char *k, const char *dflt) {
    const char *v = getenv(k);
    return (v && *v) ? v : dflt;
}

static double now_s(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (double)ts.tv_sec + ts.tv_nsec / 1e9;
}

/* ------------------------------------------------------------------ state */
typedef struct {
    int dry_run, once, verbose;
    double forced_temp;            /* --temp; NAN-ish sentinel via has_forced */
    int has_forced;
    int interval;

    encld_curve cpu_curve, sys_curve;
    encld_calib blower_cal, cpu_cal;
    const char *sensor, *cpu_hwmon_name;
    int cpu_pwm_ch;

    double ema; int ema_first;
    int blower_pwm, cpu_pwm;       /* last commanded (-1 = none yet)          */
    double blower_low_since;       /* monotonic ts of first low RPM, <0 = ok  */
    int blower_fault;
    int cpu_saved_enable;          /* pwmN_enable before we took over (-1 ?)  */
    int cpu_took_manual;           /* we flipped/were about to flip to manual */
    char cpu_dir[512];             /* resolved nct6776 hwmon dir ("" = none)  */
    int io_error;                  /* any write failure this tick             */

    /* hot-reload + network-LED map (WebPAM fan/frontpanel pages write these) */
    const char *fcfg_path;         /* fanconfig.json - reloaded on mtime change */
    time_t fcfg_mtime;
    const char *ccfg_path;         /* FanCalibration.json - written by a sweep  */
    const char *frontpanel_path;   /* frontpanel.json NIC->LED map            */
    time_t nl_mtime;
    int nl_loaded;
    netled_cfg nl;
    unsigned char nl_last[NETLED_COUNT];  /* last colour written (0xFF none)  */
} encld;

static time_t file_mtime(const char *path) {
    struct stat st;
    return (path && stat(path, &st) == 0) ? st.st_mtime : 0;
}

/* -------------------------------------------------------------- utilities */
static int resolve_cpu_dir(encld *e) {
    if (e->cpu_dir[0]) return 0;
    if (hwmon_find_by_name(e->cpu_hwmon_name, e->cpu_dir, sizeof e->cpu_dir) == 0) {
        e->cpu_saved_enable = hwmon_get_pwm_enable(e->cpu_dir, e->cpu_pwm_ch);
        printf("encld: cpu fan hwmon '%s' -> %s (pwm%d, prior enable=%d)\n",
               e->cpu_hwmon_name, e->cpu_dir, e->cpu_pwm_ch, e->cpu_saved_enable);
        return 0;
    }
    return -1;
}

/* Pick the control temperature in degC: --temp wins; else the named hwmon
 * label (case-insensitive); "auto" = max across all sensors. <-1000 = none. */
static double read_temp(const encld *e, char *src, int cap) {
    if (e->has_forced) {
        snprintf(src, (size_t)cap, "forced");
        return e->forced_temp;
    }
    hwmon_reading t[MAX_TEMPS];
    int n = hwmon_read_temps(t, MAX_TEMPS);
    if (strcasecmp(e->sensor, "auto") == 0) {
        double best = -10000.0;
        for (int i = 0; i < n; i++) {
            double c = t[i].value / 1000.0;
            if (c > best) { best = c; snprintf(src, (size_t)cap, "%.31s", t[i].label); }
        }
        return best;
    }
    for (int i = 0; i < n; i++)
        if (strcasecmp(t[i].label, e->sensor) == 0) {
            snprintf(src, (size_t)cap, "%.31s", t[i].label);
            return t[i].value / 1000.0;
        }
    return -10000.0;
}

static void set_led(encld *e, unsigned char id, unsigned char color, const char *what) {
    if (e->dry_run) return;
    if (smbus_led_write(id, color, 0) != I2B_API_RET_SUCCESS) {
        fprintf(stderr, "encld: LED write failed (%s)\n", what);
        e->io_error = 1;
    }
}

/* Blower tach health: <500 RPM continuously for 60s = confirmed fault
 * (ledctl.py check 22; the sudden-drop beeper check 23 lives with the full
 * Python stack, not this daemon). rpm<0 = unreadable (treated like low). */
static void blower_health(encld *e, int rpm) {
    if (rpm >= BLOWER_MIN_RPM) {
        if (e->blower_fault) printf("encld: blower recovered (%d RPM)\n", rpm);
        e->blower_low_since = -1.0;
        e->blower_fault = 0;
        return;
    }
    if (e->blower_low_since < 0) e->blower_low_since = now_s();
    if (!e->blower_fault && now_s() - e->blower_low_since >= BLOWER_GRACE_S) {
        e->blower_fault = 1;
        fprintf(stderr, "encld: BLOWER FAULT - %d RPM below %d for %ds\n",
                rpm, BLOWER_MIN_RPM, BLOWER_GRACE_S);
    }
}

/* Re-read fanconfig.json when its mtime changes - the WebPAM fan-curve editor
 * (fancontrolt.php) writes it live; no restart or signal needed. */
static void reload_fanconfig(encld *e) {
    time_t m = file_mtime(e->fcfg_path);
    if (m == e->fcfg_mtime) return;
    e->fcfg_mtime = m;
    encld_default_curves(&e->cpu_curve, &e->sys_curve);
    if (m) encld_load_fanconfig(e->fcfg_path, &e->cpu_curve, &e->sys_curve);
    printf("encld: fanconfig %s (%s)\n", m ? "reloaded" : "removed - defaults",
           e->fcfg_path);
}

/* Network-panel LEDs (ETH1/ETH2) track the mapped NICs' link state - the
 * frontsvc Windows service behaviour: unmapped/down/mode-off -> OFF, up ->
 * green (mode 1) or blink-green (mode 0; steady on the SMBus path). The map
 * is re-read when frontpanel.json changes (WebPAM frontpanel.php writes it). */
static void netled_tick(encld *e) {
    time_t m = file_mtime(e->frontpanel_path);
    if (m != e->nl_mtime) {
        e->nl_mtime = m;
        e->nl_loaded = m ? netled_load(e->frontpanel_path, &e->nl) : 0;
        memset(e->nl_last, 0xFF, sizeof e->nl_last);  /* force a rewrite */
        printf("encld: frontpanel map %s (%s)\n",
               e->nl_loaded ? "loaded" : "absent - net LEDs off",
               e->frontpanel_path);
    }
    static const unsigned char ids[NETLED_COUNT] =
        { I2B_LED_ETH_PORT1, I2B_LED_ETH_PORT2 };
    static const char *names[NETLED_COUNT] = { "net1", "net2" };
    /* In the TrueNAS container the daemon's own /sys/class/net is the container
     * netns (eth0 only); VESSAPP_NET_ROOT points at the entrypoint's mirror of
     * the HOST NICs (entrypoint.sh) so the panel LEDs track the real uplinks.
     * Unset (bare-metal host install) -> the real /sys/class/net. */
    static const char *net_root = NULL;
    if (!net_root) { net_root = getenv("VESSAPP_NET_ROOT"); if (!net_root || !*net_root) net_root = SYSNET_ROOT; }
    for (int i = 0; i < NETLED_COUNT; i++) {
        unsigned char want = I2B_LED_STS_OFF;
        if (e->nl_loaded && e->nl.led[i].mode != NETLED_MODE_OFF) {
            char ifn[64];
            if (netled_resolve(net_root, &e->nl.led[i], ifn, sizeof ifn))
                want = netled_color(netled_link_up(net_root, ifn),
                                    e->nl.led[i].mode);
        }
        if (want != e->nl_last[i]) {
            set_led(e, ids[i], want, names[i]);
            e->nl_last[i] = want;
        }
    }
}

/* -------------------------------------------------------------- main tick */
/* ---- front-panel HDD-slot + RAID status LEDs (mdadm health) ----------------
 * The RAID backend enumerates data disks via lsblk (TYPE=disk sd*, root disk
 * excluded); we match that order so slot N is the same drive WebPAM shows in
 * slot N. Colours mirror the A3120P: HDD OFF=empty / GREEN=in-sync / AMBER=
 * rebuilding / RED=faulty; RAID OFF=no array / GREEN=optimal / AMBER=degraded
 * or rebuilding / RED=offline. Read-only /sys, safe to poll every tick. */
static void led_root_disk(char *out, size_t cap) {
    out[0] = 0;
    FILE *p = popen("findmnt -no SOURCE / 2>/dev/null", "r");
    if (!p) return;
    char dev[80] = ""; if (fgets(dev, sizeof dev, p)) dev[strcspn(dev, "\r\n")] = 0;
    pclose(p);
    const char *b = strrchr(dev, '/'); b = b ? b + 1 : dev;      /* /dev/sdd1 -> sdd1 */
    size_t n = strlen(b);
    while (n && b[n-1] >= '0' && b[n-1] <= '9') n--;             /* strip partition #  */
    if (n >= cap) n = cap - 1;
    memcpy(out, b, n); out[n] = 0;
}

static int led_data_disks(char out[4][16]) {
    char root[16]; led_root_disk(root, sizeof root);
    char names[16][16]; int n = 0;
    DIR *d = opendir("/sys/block");
    if (d) {
        struct dirent *e;
        while ((e = readdir(d)) && n < 16) {
            if (strncmp(e->d_name, "sd", 2) != 0) continue;
            if (root[0] && strcmp(e->d_name, root) == 0) continue;   /* OS disk */
            snprintf(names[n++], 16, "%.15s", e->d_name);
        }
        closedir(d);
    }
    for (int i = 1; i < n; i++) {                                    /* insertion sort -> sda,sdb,... */
        char t[16]; memcpy(t, names[i], 16); int j = i - 1;
        while (j >= 0 && strcmp(names[j], t) > 0) { memcpy(names[j+1], names[j], 16); j--; }
        memcpy(names[j+1], t, 16);
    }
    int cnt = n < 4 ? n : 4;
    for (int i = 0; i < cnt; i++) memcpy(out[i], names[i], 16);
    return cnt;
}

/* mdadm member state of a data disk -> LED colour. In-sync member = GREEN, faulty
 * = RED, spare/recovering = AMBER. A present data disk that is NOT a member of any
 * array while an array is DEGRADED is the removed/ex-member (the drive that dropped
 * out) -> RED so its bay flags the fault; with no degraded array it's just an
 * unconfigured spare disk -> GREEN. */
static unsigned char led_disk_color(const char *disk, int degraded) {
    unsigned char color = I2B_LED_STS_GREEN;
    int member = 0;
    DIR *d = opendir("/sys/block");
    if (!d) return color;
    struct dirent *e;
    while ((e = readdir(d))) {
        if (strncmp(e->d_name, "md", 2) != 0) continue;
        char sp[320]; snprintf(sp, sizeof sp, "/sys/block/%s/md/dev-%s/state", e->d_name, disk);
        FILE *f = fopen(sp, "r"); if (!f) continue;
        member = 1;
        char st[64] = ""; if (fgets(st, sizeof st, f)) { /* member state */ } fclose(f);
        if (strstr(st, "faulty")) { color = I2B_LED_STS_RED; break; }
        if (!strstr(st, "in_sync")) color = I2B_LED_STS_AMBER;   /* spare/recovering */
    }
    closedir(d);
    if (!member && degraded) return I2B_LED_STS_RED;             /* dropped array member */
    return color;
}

static unsigned char led_raid_color(void) {
    DIR *d = opendir("/sys/block");
    if (!d) return I2B_LED_STS_OFF;
    unsigned char color = I2B_LED_STS_OFF; int have = 0;
    struct dirent *e;
    while ((e = readdir(d))) {
        if (strncmp(e->d_name, "md", 2) != 0) continue;
        have = 1;
        char p[320], v[32] = "", as[32] = "";
        snprintf(p, sizeof p, "/sys/block/%s/md/degraded", e->d_name);
        FILE *f = fopen(p, "r"); if (f) { if (fgets(v, sizeof v, f)) {} fclose(f); }
        snprintf(p, sizeof p, "/sys/block/%s/md/array_state", e->d_name);
        f = fopen(p, "r"); if (f) { if (fgets(as, sizeof as, f)) {} fclose(f); }
        if (strstr(as, "inactive") || strstr(as, "broken")) { closedir(d); return I2B_LED_STS_RED; }
        if (atoi(v) > 0) color = I2B_LED_STS_AMBER;
        else if (color != I2B_LED_STS_AMBER) color = I2B_LED_STS_GREEN;
    }
    closedir(d);
    return have ? color : I2B_LED_STS_OFF;
}

static void raid_led_tick(encld *e, unsigned char raid_color) {
    static const unsigned char slot_led[4] = {
        I2B_LED_HDD_SLOT1, I2B_LED_HDD_SLOT2, I2B_LED_HDD_SLOT3, I2B_LED_HDD_SLOT4
    };
    /* A degraded/rebuilding array is missing a member. Its bay is either a present
     * non-member disk (RED via led_disk_color) or an empty bay -> RED here so the
     * dropped drive's slot is flagged; an empty bay with a healthy array = OFF. */
    int degraded = (raid_color == I2B_LED_STS_AMBER || raid_color == I2B_LED_STS_RED);
    char disks[4][16];
    int nd = led_data_disks(disks);
    for (int i = 0; i < 4; i++) {
        unsigned char c = (i < nd) ? led_disk_color(disks[i], degraded)
                                   : (degraded ? I2B_LED_STS_RED : I2B_LED_STS_OFF);
        set_led(e, slot_led[i], c, "hdd");
    }
    set_led(e, I2B_LED_RAID_STS, raid_color, "raid");
}

/* ---- fan calibration (C port of calibration.py) --------------------------
 * A WebPAM "Run Calibration" writes <calibdir>/calibrate.request; the control
 * loop picks it up and sweeps each fan 5..100% in 5% steps, recording RPM, then
 * writes FanCalibration.json so Speedlevel percentages map to that fan's real
 * RPM range - per-fan, so any brand of fan works. Blocks the loop ~2-3 min. */
#define CAL_STEP_PCT       5
#define CAL_SETTLE_BLOWER  5     /* s/step - blower inertia is high (calibration.py) */
#define CAL_SETTLE_CPU     3     /* s/step - CPU fan responds faster                 */

static void calib_sibling(const encld *e, const char *name, char *out, size_t cap) {
    const char *p = e->ccfg_path ? e->ccfg_path : DEF_CALIBRATION;
    const char *slash = strrchr(p, '/');
    if (slash) snprintf(out, cap, "%.*s/%s", (int)(slash - p), p, name);
    else       snprintf(out, cap, "%s", name);
}

static void calib_status(const encld *e, const char *state) {
    char sp[600]; calib_sibling(e, "calibrate.status", sp, sizeof sp);
    FILE *f = fopen(sp, "w");
    if (!f) return;
    fprintf(f, "{\"state\":\"%s\",\"ts\":%ld}\n", state, (long)time(NULL));
    fclose(f);
}

static int calib_requested(const encld *e) {
    char rp[600]; calib_sibling(e, "calibrate.request", rp, sizeof rp);
    struct stat st;
    if (stat(rp, &st) != 0) return 0;
    remove(rp);                       /* consume the one-shot request */
    return 1;
}

/* Sweep one hardware fan; returns sample count. set_pwm/read_rpm are the two
 * hardware ops the tick already uses, passed in so blower (i2c) and CPU (hwmon)
 * share this loop. Aborts (returns negative count magnitude via *abort) if a
 * sensor crosses the emergency temperature mid-sweep. */
static void run_calibration(encld *e) {
    printf("encld: === fan calibration starting - fans will ramp to full speed ===\n");
    fflush(stdout);
    calib_status(e, "running");

    encld_cal_sample bmap[24], cmap[24];
    int nb = 0, nc = 0, aborted = 0;
    char src[64];

    i2c_bus *bus = smbus_encl_bus();
    if (bus) {
        for (int pct = CAL_STEP_PCT; pct <= 100 && !g_stop; pct += CAL_STEP_PCT) {
            int pwm = pct * 255 / 100; if (pwm < 1) pwm = 1; if (pwm > 255) pwm = 255;
            nct7802y_set_pwm(bus, NCT7802Y_ADDR, 1, pwm);
            sleep(CAL_SETTLE_BLOWER);
            int rpm = nct7802y_read_rpm(bus, NCT7802Y_ADDR, 1); if (rpm < 0) rpm = 0;
            bmap[nb].pct = pct; bmap[nb].pwm = pwm; bmap[nb].rpm = rpm; nb++;
            printf("encld: cal blower %3d%% pwm=%3d -> %d rpm\n", pct, pwm, rpm); fflush(stdout);
            if (read_temp(e, src, sizeof src) > ENCLD_EMERG_TEMP) { aborted = 1; break; }
        }
        int safe = nb ? bmap[0].pwm : ENCLD_PWM_FLOOR;   /* return to spin-safe minimum */
        for (int i = 0; i < nb; i++)
            if (bmap[i].rpm >= ENCLD_CAL_SAFE_RPM) { safe = bmap[i].pwm; break; }
        nct7802y_set_pwm(bus, NCT7802Y_ADDR, 1, safe);
    }

    if (!aborted && !g_stop && resolve_cpu_dir(e) == 0) {
        hwmon_set_pwm_enable(e->cpu_dir, e->cpu_pwm_ch, 1);       /* manual mode */
        for (int pct = CAL_STEP_PCT; pct <= 100 && !g_stop; pct += CAL_STEP_PCT) {
            int pwm = pct * 255 / 100; if (pwm < 1) pwm = 1; if (pwm > 255) pwm = 255;
            hwmon_set_pwm(e->cpu_dir, e->cpu_pwm_ch, pwm);
            sleep(CAL_SETTLE_CPU);
            int rpm = hwmon_read_fan(e->cpu_dir, e->cpu_pwm_ch); if (rpm < 0) rpm = 0;
            cmap[nc].pct = pct; cmap[nc].pwm = pwm; cmap[nc].rpm = rpm; nc++;
            printf("encld: cal cpu %3d%% pwm=%3d -> %d rpm\n", pct, pwm, rpm); fflush(stdout);
            if (read_temp(e, src, sizeof src) > ENCLD_EMERG_TEMP) { aborted = 1; break; }
        }
    }

    e->blower_pwm = e->cpu_pwm = -1;      /* force the next tick to re-derive PWM */

    if (aborted) {
        fprintf(stderr, "encld: calibration ABORTED (temp >= %.0fC) - not saved\n", ENCLD_EMERG_TEMP);
        calib_status(e, "aborted");
        return;
    }

    const char *path = e->ccfg_path ? e->ccfg_path : DEF_CALIBRATION;
    char date[32]; time_t now = time(NULL); struct tm tm;
    if (localtime_r(&now, &tm)) strftime(date, sizeof date, "%Y-%m-%d %H:%M:%S", &tm);
    else snprintf(date, sizeof date, "-");
    if (encld_calib_write(path, date, "WebPAM", bmap, nb, cmap, nc) == 0) {
        printf("encld: === calibration complete -> %s ===\n", path);
        encld_load_calibration(path, &e->blower_cal, &e->cpu_cal);   /* apply now */
        calib_status(e, "done");
    } else {
        fprintf(stderr, "encld: calibration write FAILED (%s)\n", path);
        calib_status(e, "error");
    }
}

static void tick(encld *e) {
    e->io_error = 0;
    reload_fanconfig(e);

    char src[64] = "?";
    double raw = read_temp(e, src, sizeof src);
    int have_temp = raw > -1000.0;
    if (have_temp) {
        e->ema = encld_ema(e->ema, raw, e->ema_first);
        e->ema_first = 0;
    }

    int blower_next = e->blower_pwm, cpu_next = e->cpu_pwm;
    int sys_level = -1, cpu_level = -1;
    if (have_temp) {
        sys_level   = encld_speedlevel_for_temp(&e->sys_curve, e->ema);
        cpu_level   = encld_speedlevel_for_temp(&e->cpu_curve, e->ema);
        blower_next = encld_zone_pwm(&e->sys_curve, &e->blower_cal, e->ema, e->blower_pwm);
        cpu_next    = encld_zone_pwm(&e->cpu_curve, &e->cpu_cal,   e->ema, e->cpu_pwm);
    } else {
        fprintf(stderr, "encld: sensor '%s' not found - holding fan PWM\n", e->sensor);
    }

    /* ---- writes (skipped wholesale in dry-run) ---- */
    int rpm = -1;
    if (!e->dry_run) {
        i2c_bus *bus = smbus_encl_bus();
        if (!bus) {
            fprintf(stderr, "encld: i2c bus unavailable\n");
            e->io_error = 1;
        } else {
            if (have_temp &&
                nct7802y_set_pwm(bus, NCT7802Y_ADDR, 1, blower_next) < 0) {
                fprintf(stderr, "encld: blower PWM write failed\n");
                e->io_error = 1;
            }
            rpm = nct7802y_read_rpm(bus, NCT7802Y_ADDR, 1);
            blower_health(e, rpm);
            /* expose blower ch1 state for promise-alerts (it owns the i2c read). */
            FILE *bs = fopen("/run/promise/blower.state", "w");
            if (bs) { fprintf(bs, "%d %d\n", rpm < 0 ? 0 : rpm, e->blower_fault ? 1 : 0); fclose(bs); }
        }
        if (have_temp) {
            if (resolve_cpu_dir(e) == 0) {
                e->cpu_took_manual = 1;
                if (hwmon_set_pwm(e->cpu_dir, e->cpu_pwm_ch, cpu_next) != 0) {
                    fprintf(stderr, "encld: cpu fan PWM write failed (%s pwm%d)\n",
                            e->cpu_dir, e->cpu_pwm_ch);
                    e->io_error = 1;
                }
            } else {
                fprintf(stderr, "encld: hwmon '%s' not found - cpu fan zone idle\n",
                        e->cpu_hwmon_name);
            }
        }
    }

    /* ---- status LEDs: Power always green; Blower + System track health. System
     * is the overall-health light: RED on a blower/IO fault OR a degraded/offline
     * RAID (a drive dropping out must light it red), else GREEN. ---- */
    unsigned char raid_color = led_raid_color();
    int raid_fault = (raid_color == I2B_LED_STS_AMBER || raid_color == I2B_LED_STS_RED);
    int sys_ok = !e->blower_fault && !e->io_error && !raid_fault;
    set_led(e, I2B_LED_PWR, I2B_LED_STS_GREEN, "power");
    set_led(e, I2B_LED_FAN1, e->blower_fault ? I2B_LED_STS_RED : I2B_LED_STS_GREEN, "fan");
    set_led(e, I2B_LED_SYSTEM, sys_ok ? I2B_LED_STS_GREEN : I2B_LED_STS_RED, "system");

    /* ---- network-panel LEDs: track the frontpanel.json NIC map ---- */
    netled_tick(e);

    /* ---- front-panel HDD-slot + RAID status LEDs: track mdadm health ---- */
    raid_led_tick(e, raid_color);

    int changed = (blower_next != e->blower_pwm) || (cpu_next != e->cpu_pwm);
    e->blower_pwm = blower_next;
    e->cpu_pwm    = cpu_next;

    if (changed || e->verbose || e->once || e->dry_run) {
        printf("encld: temp=%.1fC (%s, ema %.1f) | blower: level=%d -> pwm=%d rpm=%s%d %s"
               " | cpu: level=%d -> pwm=%d | leds: PWR=G FAN=%s SYS=%s%s\n",
               have_temp ? raw : -1.0, src, e->ema,
               sys_level, e->blower_pwm,
               rpm < 0 ? "n/a " : "", rpm < 0 ? 0 : rpm,
               e->blower_fault ? "FAULT" : "ok",
               cpu_level, e->cpu_pwm,
               e->blower_fault ? "R" : "G", sys_ok ? "G" : "R",
               e->dry_run ? " [dry-run: nothing written]" : "");
        fflush(stdout);
    }
}

/* ---- exit: leave the fans SAFE (running / hardware-auto), never stopped ---- */
static void safe_exit(encld *e) {
    if (e->dry_run) return;

    /* Blower: hold at least the built-in raw sys-curve value for the last
     * known temp (>= any calibrated value for the same temp; idle band = 40).
     * There is no hardware-auto on the NCT7802Y - it keeps the last PWM. */
    encld_curve sys_raw;
    encld_default_curves(NULL, &sys_raw);
    int hold = encld_speedlevel_for_temp(&sys_raw, e->ema_first ? 60.0 : e->ema);
    if (e->blower_pwm > hold) hold = e->blower_pwm;
    i2c_bus *bus = smbus_encl_bus();
    if (bus && nct7802y_set_pwm(bus, NCT7802Y_ADDR, 1, hold) == 0)
        printf("encld: exit - blower held at pwm=%d\n", hold);
    else
        fprintf(stderr, "encld: exit - blower hold write FAILED (fan keeps last pwm=%d)\n",
                e->blower_pwm);

    /* CPU fan: hand control back to the kernel/hardware. Restore the mode we
     * found at startup unless that was manual - then thermal cruise (2). */
    if (e->cpu_took_manual && e->cpu_dir[0]) {
        int mode = (e->cpu_saved_enable > HWMON_PWM_MANUAL) ? e->cpu_saved_enable
                                                            : HWMON_PWM_AUTO;
        if (hwmon_set_pwm_enable(e->cpu_dir, e->cpu_pwm_ch, mode) == 0)
            printf("encld: exit - cpu fan pwm%d_enable restored to %d (auto)\n",
                   e->cpu_pwm_ch, mode);
        else
            fprintf(stderr, "encld: exit - cpu fan enable restore FAILED "
                            "(fan keeps manual pwm=%d)\n", e->cpu_pwm);
    }
    /* Net LEDs are ours (frontsvc OnStop parity): leave them dark - the
     * daemon that keeps them live is going away. */
    set_led(e, I2B_LED_ETH_PORT1, I2B_LED_STS_OFF, "net1");
    set_led(e, I2B_LED_ETH_PORT2, I2B_LED_STS_OFF, "net2");
    /* LEDs: Power stays green - the box is still on. */
}

/* One-time: the LEDs this daemon does NOT manage (HDD/RAID/USB/record) would
 * otherwise stay frozen at whatever the Python driver last wrote. Turn them
 * off so the panel only shows live indications (ledctl preflight does the
 * same all-off sweep at startup). The net LEDs are managed by netled_tick,
 * whose first pass always writes them. */
static void clear_unmanaged_leds(encld *e) {
    static const struct { unsigned char id; const char *name; } offs[] = {
        { I2B_LED_HDD_SLOT1, "hdd1" }, { I2B_LED_HDD_SLOT2, "hdd2" },
        { I2B_LED_HDD_SLOT3, "hdd3" }, { I2B_LED_HDD_SLOT4, "hdd4" },
        { I2B_LED_USB1, "usb" },       { I2B_LED_RAID_STS, "raid" },
        { I2B_LED_RECORD, "record" },
    };
    for (size_t i = 0; i < sizeof offs / sizeof offs[0]; i++)
        set_led(e, offs[i].id, I2B_LED_STS_OFF, offs[i].name);
    printf("encld: unmanaged panel LEDs cleared (hdd/usb/raid/record)\n");
}

static void usage(void) {
    puts("promise-encld - Promise A3120 enclosure fan + status-LED daemon\n"
         "  --dry-run       compute and print decisions; write NOTHING\n"
         "  --once          run a single control cycle, then exit safely\n"
         "  --temp <C>      use this temperature instead of reading sensors\n"
         "  --interval <s>  loop interval (default 5, or PROMISE_ENCLD_INTERVAL)\n"
         "  --verbose       print every tick, not just changes\n"
         "env: PROMISE_FANCONFIG PROMISE_CALIBRATION PROMISE_FRONTPANEL\n"
         "     PROMISE_ENCLD_SENSOR PROMISE_CPU_HWMON PROMISE_CPU_PWM\n"
         "     VESSAPP_I2C_BUS");
}

int main(int argc, char **argv) {
    encld e;
    memset(&e, 0, sizeof e);
    e.ema_first = 1;
    e.blower_pwm = e.cpu_pwm = -1;
    e.blower_low_since = -1.0;
    e.cpu_saved_enable = -1;
    e.interval = atoi(envs("PROMISE_ENCLD_INTERVAL", "5"));
    if (e.interval <= 0) e.interval = DEF_INTERVAL_S;
    e.sensor         = envs("PROMISE_ENCLD_SENSOR", DEF_SENSOR);
    e.cpu_hwmon_name = envs("PROMISE_CPU_HWMON", DEF_CPU_HWMON);
    e.cpu_pwm_ch     = atoi(envs("PROMISE_CPU_PWM", "2"));
    if (e.cpu_pwm_ch <= 0) e.cpu_pwm_ch = DEF_CPU_PWM_CH;
    e.verbose        = atoi(envs("PROMISE_ENCLD_VERBOSE", "0"));

    for (int i = 1; i < argc; i++) {
        if      (!strcmp(argv[i], "--dry-run")) e.dry_run = 1;
        else if (!strcmp(argv[i], "--once"))    e.once = 1;
        else if (!strcmp(argv[i], "--verbose")) e.verbose = 1;
        else if (!strcmp(argv[i], "--temp") && i + 1 < argc) {
            e.forced_temp = atof(argv[++i]); e.has_forced = 1;
        }
        else if (!strcmp(argv[i], "--interval") && i + 1 < argc)
            e.interval = atoi(argv[++i]);
        else { usage(); return !strcmp(argv[i], "--help") ? 0 : 2; }
    }

    /* curves + calibration - the Python driver's live files by default */
    const char *fcfg = envs("PROMISE_FANCONFIG", DEF_FANCONFIG);
    const char *ccfg = envs("PROMISE_CALIBRATION", DEF_CALIBRATION);
    encld_default_curves(&e.cpu_curve, &e.sys_curve);
    int have_fcfg = encld_load_fanconfig(fcfg, &e.cpu_curve, &e.sys_curve);
    int have_ccfg = encld_load_calibration(ccfg, &e.blower_cal, &e.cpu_cal);
    e.fcfg_path = fcfg;
    e.ccfg_path = ccfg;                         /* run_calibration writes here */
    e.fcfg_mtime = file_mtime(fcfg);           /* reload_fanconfig watches it */
    e.frontpanel_path = envs("PROMISE_FRONTPANEL", DEF_FRONTPANEL);
    e.nl_mtime = (time_t)-1;                   /* first netled_tick always loads */
    memset(e.nl_last, 0xFF, sizeof e.nl_last);
    printf("encld: fanconfig %s (%s), calibration %s (blower %s, cpu %s)\n",
           fcfg, have_fcfg ? "loaded" : "absent - built-in defaults",
           have_ccfg ? ccfg : "absent",
           e.blower_cal.valid ? "pct mode" : "raw pwm",
           e.cpu_cal.valid ? "pct mode" : "raw pwm");
    printf("encld: frontpanel map %s (net LEDs track mapped NICs; hot-reload on change)\n",
           e.frontpanel_path);
    printf("encld: sensor '%s', interval %ds%s%s\n", e.sensor, e.interval,
           e.dry_run ? ", DRY-RUN" : "", e.once ? ", once" : "");

    struct sigaction sa;
    memset(&sa, 0, sizeof sa);
    sa.sa_handler = on_signal;             /* no SA_RESTART: interrupt sleeps */
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);

    if (!e.dry_run) {
        i2c_bus *bus = smbus_encl_bus();
        if (!bus) {
            fprintf(stderr, "encld: cannot open /dev/i2c-%s (root? modprobe i2c-dev,i2c-i801?)\n",
                    envs("VESSAPP_I2C_BUS", "0"));
            return 1;
        }
        if (nct7802y_init(bus, NCT7802Y_ADDR) < 0) {
            fprintf(stderr, "encld: NCT7802Y init failed - refusing to run\n");
            return 1;
        }
        clear_unmanaged_leds(&e);
    }

    while (!g_stop) {
        tick(&e);
        if (e.once) break;
        if (!e.dry_run && calib_requested(&e)) run_calibration(&e);  /* WebPAM-triggered sweep */
        for (int s = 0; s < e.interval * 2 && !g_stop; s++) {
            struct timespec half = { 0, 500 * 1000 * 1000 };
            nanosleep(&half, NULL);
        }
    }

    safe_exit(&e);
    printf("encld: stopped\n");
    return 0;
}
