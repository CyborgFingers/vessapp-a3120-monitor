/* event_map.c - see event_map.h. Values copied verbatim from event_EN.xml. */
#include "event_map.h"
#include <stddef.h>

/* Representative fault events (event_EN.xml). id | name | sev# | word | desc. */
static const event_def EVENTS[] = {
    { 0x00010002, "BATTERY_CAPACITY_LOW",   3, "Warning",
      "BBU is not able to retain data in cache memory for more than 72 hrs" },
    { 0x00050004, "PSU_FAN_MALFUNCTIONING", 4, "Minor",
      "Fan is malfunction or speed abnormal" },
    { 0x00090003, "LD_OFFLINE",             5, "Major",
      "Logical drive has been placed offline. Possible Data Loss" },
    { 0x000D0002, "PD_MARKED_DEAD",         5, "Major",
      "Physical Disk is marked as DEAD" },
    { 0x000D0005, "PD_SPARE_ACTIVATED",     3, "Warning",
      "A hot spare drive has been activated to rebuild a degraded array" },
    { 0x00100000, "SMART_ERROR_RECEIVED",   4, "Minor",
      "SMART error is received" },
    { 0x00130006, "DA_INCOMPLETE",          3, "Warning",
      "The array is incomplete. One or more physical drives are missing" },
    { 0x00130002, "DA_REBUILD_STARTED",     2, "Info",
      "Disk array rebuild has started" },
    { 0x00130003, "DA_REBUILD_DONE",        2, "Info",
      "Disk array rebuild has completed" },
};

const event_def *event_lookup(uint32_t id) {
    for (size_t i = 0; i < sizeof EVENTS / sizeof EVENTS[0]; i++)
        if (EVENTS[i].id == id) return &EVENTS[i];
    return NULL;
}

const char *event_severity_name(int sev) {
    static const char *N[] = { "Unknown", "Other", "Info", "Warning",
                               "Minor", "Major", "Critical", "Fatal" };
    return (sev >= 0 && sev <= 7) ? N[sev] : "Unknown";
}
