/*
 * test_encld - promise-encld decision core: curve->PWM math + floor for both
 * fan zones (blower/NCT7802Y curve values and CPU/hwmon PWM writes), the
 * fanconfig.json + FanCalibration.json readers (live file shapes), calibrated
 * percentage mapping, hysteresis, EMA, the hwmon PWM control used for the
 * CPU fan (manual-mode flip + floor) against a sysfs fixture tree, and the
 * frontpanel.json NIC->network-LED mapping (encld_netled) against a
 * /sys/class/net fixture tree.
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>

#include "encld_curve.h"
#include "encld_netled.h"
#include "hwmon_sysfs.h"
#include "i2bdg_api.h"

static int g_checks;
#define CHECK(cond, ...) do { g_checks++; if (!(cond)) { \
    fprintf(stderr, "FAIL %s:%d: ", __FILE__, __LINE__); \
    fprintf(stderr, __VA_ARGS__); fprintf(stderr, "\n"); exit(1); } } while (0)
#define PASS() do { printf("ok - %s (%d checks)\n", __FILE__, g_checks); return 0; } while (0)

static void write_file(const char *path, const char *text) {
    FILE *f = fopen(path, "w");
    CHECK(f != NULL, "open %s for write", path);
    fputs(text, f);
    fclose(f);
}

static char *read_file(const char *path, char *buf, size_t cap) {
    FILE *f = fopen(path, "r");
    CHECK(f != NULL, "open %s for read", path);
    size_t n = fread(buf, 1, cap - 1, f);
    fclose(f);
    buf[n] = 0;
    return buf;
}

/* ---- default (raw PWM) curves: values straight from DEFAULT_FANCONFIG ---- */
static void test_default_curves(void) {
    encld_curve cpu, sys;
    encld_default_curves(&cpu, &sys);

    /* CPU zone */
    CHECK(encld_zone_pwm(&cpu, NULL, 50.0, -1) == 130, "cpu 50C -> 130");
    CHECK(encld_zone_pwm(&cpu, NULL, 65.0, -1) == 160, "cpu 65C -> 160");
    CHECK(encld_zone_pwm(&cpu, NULL, 79.0, -1) == 190, "cpu 79C -> 190");
    CHECK(encld_zone_pwm(&cpu, NULL, 120.0, -1) == 255, "cpu 120C -> emergency 255");
    /* Sys/blower zone */
    CHECK(encld_zone_pwm(&sys, NULL, 45.0, -1) == 40,  "sys 45C -> 40");
    CHECK(encld_zone_pwm(&sys, NULL, 72.0, -1) == 140, "sys 72C -> 140");
    CHECK(encld_zone_pwm(&sys, NULL, 84.9, -1) == 190, "sys 84.9C -> 190 (band, not emergency)");
    CHECK(encld_zone_pwm(&sys, NULL, 85.0, -1) == 255, "sys 85C -> emergency 255");

    /* fractional temp inside the integer band edge gap: truncation keeps the
     * lower band (fanctl.py would fall through to the top band) */
    CHECK(encld_zone_pwm(&cpu, NULL, 59.5, -1) == 130, "cpu 59.5C -> 130 (no gap jump)");

    /* empty curve -> fanctl.py fallback 128 */
    encld_curve empty = { 0 };
    CHECK(encld_speedlevel_for_temp(&empty, 50.0) == 128, "empty curve -> 128");
}

/* ---- floor: a curve commanding below 20 is clamped, NEVER 0 ---- */
static void test_floor(void) {
    encld_curve low = { .n = 2, .b = { {0, 59, 5}, {60, 100, 0} } };
    CHECK(encld_zone_pwm(&low, NULL, 40.0, -1) == ENCLD_PWM_FLOOR,
          "level 5 floored to %d", ENCLD_PWM_FLOOR);
    CHECK(encld_zone_pwm(&low, NULL, 70.0, -1) == ENCLD_PWM_FLOOR,
          "level 0 floored to %d", ENCLD_PWM_FLOOR);
    encld_curve high = { .n = 1, .b = { {0, 100, 999} } };
    CHECK(encld_zone_pwm(&high, NULL, 40.0, -1) == 255, "level 999 capped to 255");
}

/* ---- hysteresis: hold previous PWM when |delta| < 7; emergency bypasses ---- */
static void test_hysteresis(void) {
    encld_curve c = { .n = 2, .b = { {0, 59, 130}, {60, 100, 136} } };
    CHECK(encld_zone_pwm(&c, NULL, 65.0, 130) == 130, "delta 6 < 7 holds prev");
    encld_curve c2 = { .n = 2, .b = { {0, 59, 130}, {60, 100, 137} } };
    CHECK(encld_zone_pwm(&c2, NULL, 65.0, 130) == 137, "delta 7 moves");
    encld_curve cpu, sys;
    encld_default_curves(&cpu, &sys);
    CHECK(encld_zone_pwm(&cpu, NULL, 90.0, 250) == 255, "emergency ignores hysteresis");
}

/* ---- EMA smoothing (alpha 0.2, seeded on first sample) ---- */
static void test_ema(void) {
    double e = encld_ema(0.0, 50.0, 1);
    CHECK(e == 50.0, "seeded with first sample");
    e = encld_ema(e, 60.0, 0);
    CHECK(e > 51.9 && e < 52.1, "0.2*60+0.8*50 = 52, got %f", e);
}

/* ---- fanconfig.json reader: the LIVE file shape (tabs, Lower always 0) ---- */
static const char *LIVE_FANCONFIG =
    "{\n"
    "\t\"_note\": \"Speedlevel is a percentage (0-100) of the calibrated RPM range. Requires FanCalibration.json.\",\n"
    "\t\"CpuFanLevelList\": [\n"
    "\t\t{ \"Lower\": 0, \"Speedlevel\": 30, \"Upper\": 59 },\n"
    "\t\t{ \"Lower\": 0, \"Speedlevel\": 45, \"Upper\": 69 },\n"
    "\t\t{ \"Lower\": 0, \"Speedlevel\": 55, \"Upper\": 79 },\n"
    "\t\t{ \"Lower\": 0, \"Speedlevel\": 72, \"Upper\": 89 },\n"
    "\t\t{ \"Lower\": 0, \"Speedlevel\": 100, \"Upper\": 100 }\n"
    "\t],\n"
    "\t\"SysFanLevelList\": [\n"
    "\t\t{ \"Lower\": 0, \"Speedlevel\": 10, \"Upper\": 59 },\n"
    "\t\t{ \"Lower\": 0, \"Speedlevel\": 20, \"Upper\": 69 },\n"
    "\t\t{ \"Lower\": 0, \"Speedlevel\": 35, \"Upper\": 79 },\n"
    "\t\t{ \"Lower\": 0, \"Speedlevel\": 55, \"Upper\": 89 },\n"
    "\t\t{ \"Lower\": 0, \"Speedlevel\": 90, \"Upper\": 100 }\n"
    "\t]\n"
    "}\n";

static void test_fanconfig_load(char *dir) {
    char path[512];
    snprintf(path, sizeof path, "%s/fanconfig.json", dir);
    write_file(path, LIVE_FANCONFIG);

    encld_curve cpu, sys;
    encld_default_curves(&cpu, &sys);
    CHECK(encld_load_fanconfig(path, &cpu, &sys) == 1, "fanconfig read");
    CHECK(cpu.n == 5 && sys.n == 5, "5 bands each, got %d/%d", cpu.n, sys.n);
    CHECK(encld_speedlevel_for_temp(&cpu, 45.0) == 30,  "live cpu 45C -> 30%%");
    CHECK(encld_speedlevel_for_temp(&cpu, 65.0) == 45,  "live cpu 65C -> 45%%");
    CHECK(encld_speedlevel_for_temp(&sys, 45.0) == 10,  "live sys 45C -> 10%%");
    CHECK(encld_speedlevel_for_temp(&sys, 95.0) == 90,  "live sys 95C -> 90%%");
    /* live shape: Lower is always 0, so first-match must pick the earliest band */
    CHECK(encld_speedlevel_for_temp(&sys, 59.9) == 10,  "live sys 59.9C stays band1");

    /* missing file -> defaults untouched, returns 0 */
    encld_curve cpu2, sys2;
    encld_default_curves(&cpu2, &sys2);
    CHECK(encld_load_fanconfig("/nonexistent/fanconfig.json", &cpu2, &sys2) == 0,
          "missing file -> 0");
    CHECK(encld_speedlevel_for_temp(&cpu2, 50.0) == 130, "defaults kept");

    /* file present but a list missing -> that list keeps defaults */
    snprintf(path, sizeof path, "%s/partial.json", dir);
    write_file(path, "{ \"SysFanLevelList\": [ {\"Lower\":0,\"Upper\":100,\"Speedlevel\":77} ] }");
    encld_curve cpu3, sys3;
    encld_default_curves(&cpu3, &sys3);
    CHECK(encld_load_fanconfig(path, &cpu3, &sys3) == 1, "partial read");
    CHECK(encld_speedlevel_for_temp(&cpu3, 50.0) == 130, "cpu list keeps default");
    CHECK(encld_speedlevel_for_temp(&sys3, 50.0) == 77,  "sys list replaced");
}

/* ---- FanCalibration.json reader + percentage->PWM mapping ---- */
static const char *CALIB_JSON =
    "{\n"
    "  \"CalibratedDate\": \"2025-01-01 00:00:00\",\n"
    "  \"Blower\": {\n"
    "    \"MinPWM\": 13, \"MaxPWM\": 255,\n"
    "    \"MinRPM\": 1000, \"MaxRPM\": 3000,\n"
    "    \"SafeMinPWM\": 25,\n"
    "    \"PwmRpmMap\": [\n"
    "      { \"Pct\": 10, \"PWM\": 25,  \"RPM\": 1000 },\n"
    "      { \"Pct\": 50, \"PWM\": 128, \"RPM\": 2000 },\n"
    "      { \"Pct\": 100, \"PWM\": 255, \"RPM\": 3000 }\n"
    "    ]\n"
    "  },\n"
    "  \"CpuFan\": {\n"
    "    \"MinPWM\": 13, \"MaxPWM\": 255,\n"
    "    \"MinRPM\": 800, \"MaxRPM\": 1800,\n"
    "    \"SafeMinPWM\": 13,\n"
    "    \"PwmRpmMap\": [\n"
    "      { \"Pct\": 10, \"PWM\": 13,  \"RPM\": 800 },\n"
    "      { \"Pct\": 60, \"PWM\": 150, \"RPM\": 1400 },\n"
    "      { \"Pct\": 100, \"PWM\": 255, \"RPM\": 1800 }\n"
    "    ]\n"
    "  }\n"
    "}\n";

static void test_calibration(char *dir) {
    char path[512];
    snprintf(path, sizeof path, "%s/FanCalibration.json", dir);
    write_file(path, CALIB_JSON);

    encld_calib bl, cpu;
    CHECK(encld_load_calibration(path, &bl, &cpu) == 1, "calibration read");
    CHECK(bl.valid && bl.n == 3 && bl.min_rpm == 1000 && bl.max_rpm == 3000,
          "blower zone parsed (valid=%d n=%d %d-%d)", bl.valid, bl.n, bl.min_rpm, bl.max_rpm);
    CHECK(cpu.valid && cpu.n == 3, "cpufan zone parsed");

    /* calibration.py: target = min + pct/100*(max-min); nearest-RPM entry. */
    CHECK(encld_pct_to_pwm(&bl, 0) == 25,    "0%% -> 1000rpm -> PWM 25");
    CHECK(encld_pct_to_pwm(&bl, 50) == 128,  "50%% -> 2000rpm -> PWM 128");
    CHECK(encld_pct_to_pwm(&bl, 100) == 255, "100%% -> 3000rpm -> PWM 255");
    CHECK(encld_pct_to_pwm(&bl, 30) == 128,  "30%% -> 1600rpm -> nearest 2000 -> PWM 128");
    CHECK(encld_pct_to_pwm(&bl, 25) == 25,   "25%% -> 1500rpm tie -> first entry (PWM 25)");

    /* missing/unusable -> -1 */
    encld_calib none = { 0 };
    CHECK(encld_pct_to_pwm(&none, 50) == -1, "invalid zone -> -1");
    encld_calib b2, c2;
    CHECK(encld_load_calibration("/nonexistent/cal.json", &b2, &c2) == 0, "missing file");
    CHECK(!b2.valid && !c2.valid, "zones invalid when file missing");

    /* calibrated zone decision: live sys curve 45C -> 10%% -> PWM 25 (>=floor) */
    encld_curve cpu_curve, sys_curve;
    encld_default_curves(&cpu_curve, &sys_curve);
    char fpath[512];
    snprintf(fpath, sizeof fpath, "%s/fanconfig.json", dir);
    CHECK(encld_load_fanconfig(fpath, &cpu_curve, &sys_curve) == 1, "reload live fanconfig");
    CHECK(encld_zone_pwm(&sys_curve, &bl, 45.0, -1) == 25, "calibrated sys 45C -> PWM 25");
    CHECK(encld_zone_pwm(&cpu_curve, &cpu, 45.0, -1) == 20,
          "calibrated cpu 45C: 30%% -> map PWM 13 -> FLOORED to 20");
    CHECK(encld_zone_pwm(&sys_curve, &bl, 90.0, -1) == 255, "calibrated sys 90C -> emergency 255");
}

/* ---- hwmon PWM control against a sysfs fixture (CPU fan zone) ---- */
static void test_hwmon_pwm(char *dir) {
    char hw[512], sub[512], p[600], buf[64];
    snprintf(hw, sizeof hw, "%s/hwmonroot", dir);
    snprintf(sub, sizeof sub, "%s/hwmon0", hw);
    mkdir(hw, 0755);
    mkdir(sub, 0755);
    snprintf(p, sizeof p, "%s/name", sub);        write_file(p, "nct6776\n");
    snprintf(p, sizeof p, "%s/pwm2", sub);        write_file(p, "128\n");
    snprintf(p, sizeof p, "%s/pwm2_enable", sub); write_file(p, "2\n");

    setenv("VESSAPP_HWMON_ROOT", hw, 1);

    char found[512];
    CHECK(hwmon_find_by_name("nct6776", found, sizeof found) == 0, "find nct6776");
    CHECK(strcmp(found, sub) == 0, "resolved dir %s", found);
    CHECK(hwmon_find_by_name("nope", found, sizeof found) == -1, "unknown name");

    CHECK(hwmon_get_pwm_enable(sub, 2) == 2, "enable reads 2 (auto)");

    /* below-floor write: flips enable to manual(1) and floors the value */
    CHECK(hwmon_set_pwm(sub, 2, 3) == 0, "set pwm 3");
    snprintf(p, sizeof p, "%s/pwm2_enable", sub);
    CHECK(atoi(read_file(p, buf, sizeof buf)) == HWMON_PWM_MANUAL, "enable flipped to manual");
    snprintf(p, sizeof p, "%s/pwm2", sub);
    CHECK(atoi(read_file(p, buf, sizeof buf)) == HWMON_PWM_FLOOR,
          "pwm2 floored to %d, got %s", HWMON_PWM_FLOOR, buf);

    /* normal write passes through; over-range capped */
    CHECK(hwmon_set_pwm(sub, 2, 200) == 0, "set pwm 200");
    CHECK(atoi(read_file(p, buf, sizeof buf)) == 200, "pwm2 = 200");
    CHECK(hwmon_set_pwm(sub, 2, 999) == 0, "set pwm 999");
    CHECK(atoi(read_file(p, buf, sizeof buf)) == 255, "pwm2 capped 255");

    /* exit path: restore hardware-auto */
    CHECK(hwmon_set_pwm_enable(sub, 2, HWMON_PWM_AUTO) == 0, "restore auto");
    CHECK(hwmon_get_pwm_enable(sub, 2) == HWMON_PWM_AUTO, "enable back to 2");

    unsetenv("VESSAPP_HWMON_ROOT");
}

/* ---- frontpanel.json NIC->LED map against a /sys/class/net fixture ---- */
static void test_netled(char *dir) {
    char root[512], sub[600], p[700];
    /* fixture: eth9 up, ethdown down, ethunk operstate unknown + carrier 1 */
    snprintf(root, sizeof root, "%s/net", dir);
    mkdir(root, 0755);
    snprintf(sub, sizeof sub, "%s/eth9", root);    mkdir(sub, 0755);
    snprintf(p, sizeof p, "%s/operstate", sub);    write_file(p, "up\n");
    snprintf(p, sizeof p, "%s/address", sub);      write_file(p, "aa:bb:cc:dd:ee:01\n");
    snprintf(sub, sizeof sub, "%s/ethdown", root); mkdir(sub, 0755);
    snprintf(p, sizeof p, "%s/operstate", sub);    write_file(p, "down\n");
    snprintf(p, sizeof p, "%s/address", sub);      write_file(p, "aa:bb:cc:dd:ee:02\n");
    snprintf(sub, sizeof sub, "%s/ethunk", root);  mkdir(sub, 0755);
    snprintf(p, sizeof p, "%s/operstate", sub);    write_file(p, "unknown\n");
    snprintf(p, sizeof p, "%s/carrier", sub);      write_file(p, "1\n");

    /* load: the shape frontpanel.php writes. EthLed2 has a stale interface
     * name + a Windows-style dash MAC - must fall back to the MAC match. */
    char cfgp[600];
    snprintf(cfgp, sizeof cfgp, "%s/frontpanel.json", dir);
    write_file(cfgp,
        "{\n"
        "  \"EthLed1\": { \"Interface\": \"eth9\",  \"Mac\": \"aa:bb:cc:dd:ee:01\", \"Mode\": 1 },\n"
        "  \"EthLed2\": { \"Interface\": \"gone0\", \"Mac\": \"AA-BB-CC-DD-EE-02\", \"Mode\": 0 }\n"
        "}\n");
    netled_cfg cfg;
    CHECK(netled_load(cfgp, &cfg) == 1, "frontpanel map read");
    CHECK(strcmp(cfg.led[0].ifname, "eth9") == 0 &&
          cfg.led[0].mode == NETLED_MODE_SOLID, "led1 fields");
    CHECK(strcmp(cfg.led[1].mac, "AA-BB-CC-DD-EE-02") == 0 &&
          cfg.led[1].mode == NETLED_MODE_BLINK, "led2 fields");

    netled_cfg missing;
    CHECK(netled_load("/nonexistent/frontpanel.json", &missing) == 0,
          "missing map -> 0");

    /* missing keys leave the zeroed (unassigned -> off) entry */
    snprintf(p, sizeof p, "%s/frontpanel-partial.json", dir);
    write_file(p, "{ \"EthLed2\": { \"Interface\": \"eth9\", \"Mode\": 2 } }");
    netled_cfg part;
    CHECK(netled_load(p, &part) == 1, "partial map read");
    CHECK(part.led[0].ifname[0] == 0 && part.led[0].mac[0] == 0,
          "absent EthLed1 stays unassigned");
    CHECK(part.led[1].mode == NETLED_MODE_OFF, "EthLed2 mode 2");

    /* resolve: by name; stale name -> MAC fallback (dashes + case folded) */
    char ifn[64];
    CHECK(netled_resolve(root, &cfg.led[0], ifn, sizeof ifn) == 1 &&
          strcmp(ifn, "eth9") == 0, "resolve by name");
    CHECK(netled_resolve(root, &cfg.led[1], ifn, sizeof ifn) == 1 &&
          strcmp(ifn, "ethdown") == 0, "stale name -> MAC match");
    netled_entry none;
    memset(&none, 0, sizeof none);
    CHECK(netled_resolve(root, &none, ifn, sizeof ifn) == 0, "unassigned -> none");

    /* link state: operstate rules + the carrier fallback */
    CHECK(netled_link_up(root, "eth9") == 1,     "eth9 up");
    CHECK(netled_link_up(root, "ethdown") == 0,  "ethdown down");
    CHECK(netled_link_up(root, "ethunk") == 1,   "unknown + carrier 1 -> up");
    CHECK(netled_link_up(root, "missing0") == 0, "missing iface -> down");

    /* frontsvc GetEthLedState colour matrix */
    CHECK(netled_color(0, NETLED_MODE_BLINK) == I2B_LED_STS_OFF, "down -> OFF");
    CHECK(netled_color(1, NETLED_MODE_BLINK) == I2B_LED_STS_BLINK_GREEN, "up mode0 -> blink");
    CHECK(netled_color(1, NETLED_MODE_SOLID) == I2B_LED_STS_GREEN, "up mode1 -> solid");
    CHECK(netled_color(1, NETLED_MODE_OFF) == I2B_LED_STS_OFF, "mode2 -> always OFF");
}

/* The calibration writer (run_calibration's persistence) must summarise a sweep
 * like calibration.py _zone and produce a file the reader round-trips. */
static void test_calib_write(char *dir) {
    /* synthetic blower sweep: starts spinning at 15% (600 rpm); CPU spins throughout */
    encld_cal_sample bl[5] = {
        {5, 12, 0}, {10, 25, 0}, {15, 38, 600}, {20, 51, 1200}, {25, 63, 1800} };
    encld_cal_sample cp[5] = {
        {5, 12, 400}, {10, 25, 800}, {15, 38, 1500}, {20, 51, 2200}, {25, 63, 3000} };

    encld_cal_zone z;
    encld_calib_summarize(bl, 5, &z);
    CHECK(z.min_pwm == 12 && z.max_pwm == 63, "blower pwm range %d-%d", z.min_pwm, z.max_pwm);
    CHECK(z.min_rpm == 600 && z.max_rpm == 1800, "blower rpm range (nonzero) %d-%d", z.min_rpm, z.max_rpm);
    CHECK(z.safe_min_pwm == 38, "SafeMinPWM = first pwm >= 500 rpm (38), got %d", z.safe_min_pwm);

    char path[512];
    snprintf(path, sizeof path, "%s/FanCalibration_written.json", dir);
    CHECK(encld_calib_write(path, "2026-07-22 12:00:00", "test", bl, 5, cp, 5) == 0, "calib write ok");

    /* round-trip: the reader must accept our own writer's output */
    encld_calib rbl, rcpu;
    CHECK(encld_load_calibration(path, &rbl, &rcpu) == 1, "written calib re-read");
    CHECK(rbl.valid && rbl.min_rpm == 600 && rbl.max_rpm == 1800,
          "blower round-trip (valid=%d %d-%d)", rbl.valid, rbl.min_rpm, rbl.max_rpm);
    CHECK(rcpu.valid && rcpu.min_rpm == 400 && rcpu.max_rpm == 3000, "cpufan round-trip");
    CHECK(encld_pct_to_pwm(&rbl, 100) == 63, "blower 100%% -> max-rpm entry pwm 63");
    CHECK(encld_pct_to_pwm(&rcpu, 0)  == 12, "cpufan 0%% -> min-rpm entry pwm 12");
}

int main(void) {
    char dir[] = "/tmp/encldtestXXXXXX";
    CHECK(mkdtemp(dir) != NULL, "mkdtemp");

    test_default_curves();
    test_floor();
    test_hysteresis();
    test_ema();
    test_fanconfig_load(dir);
    test_calibration(dir);
    test_calib_write(dir);
    test_hwmon_pwm(dir);
    test_netled(dir);
    PASS();
}
