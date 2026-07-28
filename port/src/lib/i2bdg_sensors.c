/*
 * i2bdg_sensors.c - element count, enclosure info, fan/temp/voltage over libi2.
 *
 * Faithful to the vendor API (i2bdg_api.h) and to the recovered page decode
 * (prdecode.dll, see docs/RE-fwinterf-pri2api.md):
 *   - getEncInfo maps the model byte @page+0x0e -> ENC_TYPE_* (RESOLVED).
 *   - fan speed is a raw tach byte: RPM = 1350000 / (raw * k) (decod_getEnclFanSpeed).
 *   - temperature byte = degrees Celsius (decod_getEnclTemperature).
 *   - voltage byte = hi-nibble.lo-nibble volts (decod_getStringVoltage).
 * Element counts are inline in the status page (page[0]=NumFans, page[4]=NumTemp);
 * per-region offsets add the running sum of preceding counts. The located
 * enclosure base and some count identities are CALIBRATE (i2c_map.h).
 */
#include "i2bdg_internal.h"
#include "i2c_map.h"
#include "encl_backend.h"
#include "smbus_encl.h"

#include <string.h>
#include <stdio.h>

#define STATUS_LEN I2_ENCL_STATUS_LEN   /* 0x400 */
#define INFO_LEN   I2_ENCL_INFO_MIN     /* 0x88  */

static int read_status(unsigned char page[STATUS_LEN]) {
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    memset(page, 0, STATUS_LEN);
    return i2bdg_map_i2_error(i2_getEnclosureStatus(h, page, STATUS_LEN));
}

static int read_info(unsigned char page[INFO_LEN]) {
    i2_handle *h = i2bdg_session_i2();
    if (!h) return I2B_API_RET_I2API_ERR;
    memset(page, 0, INFO_LEN);
    return i2bdg_map_i2_error(i2_getEnclosureInfo(h, page, INFO_LEN));
}

/* Running region-size sum that offsets an element region. Positions recovered
 * from decod_getEncl{Temperature,Voltage,FanSpeed} (page[0,1,2,4,8]); counts
 * are summed as an ordered prefix: temp=3 terms, volt=4, fan-speed=5. */
static unsigned region_sum(const unsigned char *p, int nterms) {
    static const int order[5] = {
        I2C_MAP_ENC_CNT_OFF_FANS, I2C_MAP_ENC_CNT_OFF_C1, I2C_MAP_ENC_CNT_OFF_C2,
        I2C_MAP_ENC_CNT_OFF_TEMP, I2C_MAP_ENC_CNT_OFF_VOLT,
    };
    unsigned s = 0;
    for (int i = 0; i < nterms; i++) s += p[order[i]];
    return s;
}

/* A3120 ('W' = 1U4BAY, golden-confirmed) fan tach -> RPM. raw==0/0xff => not
 * present (0). */
static unsigned long fan_rpm(unsigned char model, unsigned raw, unsigned idx) {
    if (raw == 0 || raw == 0xff) return 0;
    unsigned k = (model == I2C_MAP_ENCBYTE_1U4BAY)
                     ? (idx < I2C_MAP_FAN_LOW_COUNT ? I2C_MAP_FAN_K_A3120_LOW
                                                    : I2C_MAP_FAN_K_A3120_HIGH)
                     : I2C_MAP_FAN_K_A3120_HIGH; /* other models: CALIBRATE */
    return I2C_MAP_FAN_RPM_NUMERATOR / (raw * k);
}

I2B_API_RET_E i2bApi_getElementCnt(I2bElementCount_t *out) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    if (encl_backend() == ENCL_SMBUS) return smbus_getElementCnt(out);
    unsigned char p[STATUS_LEN];
    int rc = read_status(p);
    if (rc != I2B_API_RET_SUCCESS) return rc;
    memset(out, 0, sizeof *out);
    /* RECOVERED counts; others are CALIBRATE best-effort placements. */
    out->NumOfFans           = p[I2C_MAP_ENC_CNT_OFF_FANS]; /* RECOVERED */
    out->NumOfTempSensors    = p[I2C_MAP_ENC_CNT_OFF_TEMP]; /* RECOVERED */
    out->NumOfVoltageSensors = p[I2C_MAP_ENC_CNT_OFF_VOLT]; /* RECOVERED-derived */
    out->NumOfPsus           = p[I2C_MAP_ENC_CNT_OFF_C1];   /* CALIBRATE */
    out->NumOfCUs            = p[I2C_MAP_ENC_CNT_OFF_C2];   /* CALIBRATE */
    /* NumOfMB/Sep/Alarms/Batteries/HDSlots page positions: CALIBRATE (unset). */
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E i2bApi_getEncInfo(I2bEncData_t *out, unsigned long buflen) {
    if (!out || buflen < sizeof(I2bEncData_t)) return I2B_API_RET_PARAM_ERR;
    if (encl_backend() == ENCL_SMBUS) {
        /* SMBus backend = the bare A3120 chassis; there is no SEP info page to
         * read over SCSI, so report the golden-box identity (model byte 'W' ->
         * ENC_TYPE_VESS_1U4BAY, CALIBRATION-A3120-golden.md). EncId=1 as the
         * vendor hardcodes. */
        memset(out, 0, sizeof *out);
        out->EncId = 1;
        strncpy((char *)out->EncType, "ENC_TYPE_VESS_1U4BAY", sizeof out->EncType - 1);
        return I2B_API_RET_SUCCESS;
    }
    unsigned char p[INFO_LEN];
    int rc = read_info(p);
    if (rc != I2B_API_RET_SUCCESS) return rc;

    memset(out, 0, sizeof *out);
    out->EncId = 1; /* libi2bdg getEncInfo @0x1d20 hardcodes EncId=1 */
    const char *type;
    switch (p[I2C_MAP_ENC_INFO_OFF_MODEL]) {
        case I2C_MAP_ENCBYTE_3U16BAY: type = "ENC_TYPE_VESS_3U16BAY"; break;
        case I2C_MAP_ENCBYTE_2U6BAY:  type = "ENC_TYPE_VESS_2U6BAY";  break;
        case I2C_MAP_ENCBYTE_1U4BAY:  type = "ENC_TYPE_VESS_1U4BAY";  break;
        default:                      type = "Unknown";               break;
    }
    strncpy((char *)out->EncType, type, sizeof out->EncType - 1);
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E i2bApi_getFanInfo(I2bFanData_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    if (encl_backend() == ENCL_SMBUS) return smbus_getFanInfo(out, buflen);
    unsigned char p[STATUS_LEN];
    int rc = read_status(p);
    if (rc != I2B_API_RET_SUCCESS) return rc;

    unsigned n = p[I2C_MAP_ENC_CNT_OFF_FANS];
    if (buflen < (unsigned long)n * sizeof(I2bFanData_t)) return I2B_API_RET_PARAM_ERR;

    unsigned char model = p[I2C_MAP_ENC_INFO_OFF_MODEL];
    unsigned base = I2C_MAP_ENC_STATUS_BASE;
    unsigned spd_off = base + I2C_MAP_ENC_STATUS_OFF_SPEED + region_sum(p, 5);
    for (unsigned i = 0; i < n; i++) {
        I2bFanData_t *d = &out[i];
        memset(d, 0, sizeof *d);
        unsigned char sts = p[base + I2C_MAP_ENC_STATUS_OFF_FANSTS + i];
        unsigned char raw = p[spd_off + i];
        d->FanId  = i + 1;
        d->FanSpd = fan_rpm(model, raw, i);
        d->FanStsFlag = sts;
        d->FanThrd = 600; /* golden CLU healthy threshold: "> 600 RPM" */
        snprintf((char *)d->FanLoc, sizeof d->FanLoc, "Cooling Unit %u", i + 1);
        const char *s = sts == FAN_STS_FUNCTIONAL    ? "Functional"
                      : sts == FAN_STS_MALFUNCTION   ? "Malfunctioning"
                      : sts == FAN_STS_NOT_INSTALLED ? "Not Installed"
                      : "Unknown";
        strncpy((char *)d->FanSts, s, sizeof d->FanSts - 1);
    }
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E i2bApi_getThermalSensorInfo(I2bThermalSensorData_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    if (encl_backend() == ENCL_SMBUS) return smbus_getThermalSensorInfo(out, buflen);
    unsigned char p[STATUS_LEN];
    int rc = read_status(p);
    if (rc != I2B_API_RET_SUCCESS) return rc;

    unsigned n = p[I2C_MAP_ENC_CNT_OFF_TEMP];
    if (buflen < (unsigned long)n * sizeof(I2bThermalSensorData_t)) return I2B_API_RET_PARAM_ERR;

    unsigned off = I2C_MAP_ENC_STATUS_BASE + I2C_MAP_ENC_STATUS_OFF_TEMP + region_sum(p, 3);
    for (unsigned i = 0; i < n; i++) {
        I2bThermalSensorData_t *d = &out[i];
        memset(d, 0, sizeof *d);
        d->SensorId = i + 1;
        d->CurTemp  = p[off + i];       /* degrees Celsius (recovered) */
        d->WarnThreshold = 0;           /* CALIBRATE: threshold page positions */
        d->CritThreshold = 0;
        d->StatusFlag = ENC_TEMERATURE_STS_NORMAL;
        /* Golden A3120 sensor locations: 1 = CPU, 2 = Controller
         * (CALIBRATION-A3120-golden.md). */
        if (i == 0)      strncpy((char *)d->Location, "CPU", sizeof d->Location - 1);
        else if (i == 1) strncpy((char *)d->Location, "Controller", sizeof d->Location - 1);
        else snprintf((char *)d->Location, sizeof d->Location, "Temp %u", i + 1);
    }
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E i2bApi_getVoltageSensorInfo(I2bVoltageSensorData_t *out, unsigned long buflen) {
    if (!out) return I2B_API_RET_PARAM_ERR;
    if (encl_backend() == ENCL_SMBUS) return smbus_getVoltageSensorInfo(out, buflen);
    unsigned char p[STATUS_LEN];
    int rc = read_status(p);
    if (rc != I2B_API_RET_SUCCESS) return rc;

    unsigned n = p[I2C_MAP_ENC_CNT_OFF_VOLT]; /* CALIBRATE */
    if (buflen < (unsigned long)n * sizeof(I2bVoltageSensorData_t)) return I2B_API_RET_PARAM_ERR;

    /* Golden A3120 rail table (CALIBRATION-A3120-golden.md): 4 rails, all
     * located "Controller"; the SEP page carries only the raw reading, the
     * rail identity + healthy window are per-model constants in the vendor
     * decode. */
    static const struct { const char *type; const char *thr; } rail[] = {
        { "1.0V",  "(0.0 - 1.3) V"   },
        { "5.0V",  "(4.7 - 5.3) V"   },
        { "12.0V", "(11.4 - 12.6) V" },
        { "3.3V",  "(3.1 - 3.5) V"   },
    };
    unsigned off = I2C_MAP_ENC_STATUS_BASE + I2C_MAP_ENC_STATUS_OFF_SPEED + region_sum(p, 4);
    for (unsigned i = 0; i < n; i++) {
        I2bVoltageSensorData_t *d = &out[i];
        memset(d, 0, sizeof *d);
        d->SensorId = i + 1;
        d->CurVolt  = p[off + i];      /* hi-nibble.lo-nibble volts (recovered) */
        d->StatusFlag = VOLTAGE_STS_NORMAL;
        if (i < sizeof rail / sizeof rail[0]) {
            strncpy((char *)d->Type, rail[i].type, sizeof d->Type - 1);
            strncpy((char *)d->Threshold, rail[i].thr, sizeof d->Threshold - 1);
        } else {
            strncpy((char *)d->Type, "DC", sizeof d->Type - 1);
        }
    }
    return I2B_API_RET_SUCCESS;
}

I2B_API_RET_E i2bApi_dispose(void) {
    i2bdg_session_reset();
    return I2B_API_RET_SUCCESS;
}

int i2bdg_dispose(void) {
    i2bdg_session_reset();
    return I2BDG_ERR_SUCCESS;
}

/* Vendor helper: format a byte count as "<n><TB|GB|MB|KB|B>". */
void i2bApi_getByesToSizeString(const unsigned long long u64Size, char *buf, unsigned int sz) {
    if (!buf || sz == 0) return;
    const char *unit[] = {"B", "KB", "MB", "GB", "TB"};
    double v = (double)u64Size; int u = 0;
    while (v >= 1024.0 && u < 4) { v /= 1024.0; u++; }
    if (u == 0) snprintf(buf, sz, "%llu%s", u64Size, unit[0]);
    else        snprintf(buf, sz, "%.2f%s", v, unit[u]);
}
