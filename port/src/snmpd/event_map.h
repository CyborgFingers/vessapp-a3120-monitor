/*
 * event_map.h - engine event-id -> {name, severity, description} lookup.
 *
 * RECOVERED from the shipped event catalogue
 *   windows-src/rootfs/PromiseApp/clitest/event_EN.xml
 * (the same file emaild/prevtlogd/prsnmpagent resolve event ids against). Only a
 * representative subset is compiled in here - the fault events used to drive the
 * SNMP traps + email alerts in fixture mode. The full 800+ entry catalogue is
 * the on-device source of truth; loading it is a prevtlogd concern (CALIBRATE).
 *
 * Severity numbers match BOTH event_EN.xml <severities> and the MIB
 * raidv4TrapSeverity ENUM: unknown0 other1 info2 warning3 minor4 major5
 * critical6 fatal7.
 */
#ifndef EVENT_MAP_H
#define EVENT_MAP_H

#include <stdint.h>

typedef struct {
    uint32_t    id;           /* event <id>, e.g. 0x000D0002                 */
    const char *name;         /* event <name>, e.g. "PD_MARKED_DEAD"         */
    int         severity;     /* raidv4TrapSeverity numeric (see above)      */
    const char *severity_str; /* "Major" etc. (event_EN.xml <severity> word) */
    const char *description;  /* event <description>                         */
} event_def;

/* Lookup by event id; NULL if not in the compiled subset. */
const event_def *event_lookup(uint32_t id);

/* severity number -> word ("Info".."Fatal"); "Unknown" if out of range. */
const char *event_severity_name(int sev);

#endif /* EVENT_MAP_H */
