/*
 * tool_common.h - shared helpers for the CLI tools.
 *
 * The vendor tools require the live i2 service; ours require the SEP bus.
 * For off-device demonstration/calibration dry-runs, setting env I2BDG_MOCK=1
 * installs a seeded in-memory enclosure so the tools produce representative
 * output without hardware. On a real A3120 leave I2BDG_MOCK unset.
 */
#ifndef TOOL_COMMON_H
#define TOOL_COMMON_H

/* If I2BDG_MOCK is set, install a seeded mock enclosure for this process. */
void tool_env_mock_install(void);

/* Seed the representative Vess A3120 enclosure INFO+STATUS pages onto a mock
 * transport (6 fans / 3 temps / 3 volts). Reused by the SNMP subagent fixture
 * so it need not duplicate the recovered status-page byte layout. */
struct scsi_mock;
void tool_seed_a3120_demo(struct scsi_mock *m);

/* Human-readable name for a colour byte (I2B_LED_STS_*). */
const char *tool_led_colour_name(unsigned char c);

#endif /* TOOL_COMMON_H */
