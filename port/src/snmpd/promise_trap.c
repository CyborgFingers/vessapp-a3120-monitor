/*
 * promise_trap.c - emit a Promise raidv4TrapMessage for an engine event.
 *
 * Linux replacement for prsnmpagent.dll's SnmpExtensionTrap path. RECOVERED from
 * prsnmpagent.dll (.rdata strings) + raidv4.mib:
 *   - notification OID  raidv4TrapMessage = .1.3.6.1.4.1.7933.1.20.0.11.0.1
 *     (MIB: raidv4TrapsV2.1). The Windows SNMPv1 agent used enterprise
 *     .1.3.6.1.4.1.7933.1.20.0.10 + specificTrap=eventId (same varbinds).
 *   - 7 varbinds = raidv4Trap.1..7 (.0.10.1..7), in the MIB OBJECTS order:
 *     SequenceNo, Id, DefaultId, Severity, Source, TimeStamp, Description.
 * The one MIB notification is parameterised by TrapId/Severity/Source, so a
 * drive / array / enclosure fault is the SAME raidv4TrapMessage with different
 * varbind values (event_map.c: PD_MARKED_DEAD, DA_INCOMPLETE, PSU_FAN_*, ...).
 *
 * We build the varbinds and exec net-snmp `snmptrap` (reusing its wire encoder)
 * rather than re-implement SNMP PDU emission. Read-only helper; sends one trap.
 */
#include "event_map.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

#define OBJ  ".1.3.6.1.4.1.7933.1.20.0.10"          /* raidv4Trap.<n>          */
#define NOTIF ".1.3.6.1.4.1.7933.1.20.0.11.0.1"     /* raidv4TrapMessage       */

static void usage(void) {
    fprintf(stderr,
      "usage: promise_trap -e <eventId-hex> [-s source] [-q seq]\n"
      "                    [-h host] [-c community]\n"
      "  e.g. promise_trap -e 0x000D0002 -s 'Enclosure 1 Slot 3'\n");
}

int main(int argc, char **argv) {
    const char *host = "127.0.0.1", *community = "VessApp";
    const char *source = "";
    unsigned long eid = 0; long seq = 1; int have_e = 0;

    int c;
    while ((c = getopt(argc, argv, "e:s:q:h:c:")) != -1) {
        switch (c) {
            case 'e': eid = strtoul(optarg, NULL, 0); have_e = 1; break;
            case 's': source = optarg; break;
            case 'q': seq = strtol(optarg, NULL, 10); break;
            case 'h': host = optarg; break;
            case 'c': community = optarg; break;
            default: usage(); return 2;
        }
    }
    if (!have_e) { usage(); return 2; }

    const event_def *ev = event_lookup((uint32_t)eid);
    int severity = ev ? ev->severity : 0;                 /* unknown if absent */
    const char *desc = ev ? ev->description : "";

    char ts[32];
    time_t now = time(NULL); struct tm tm;
    localtime_r(&now, &tm);
    strftime(ts, sizeof ts, "%Y/%m/%d %H:%M:%S", &tm);

    /* varbind OIDs + scalar buffers */
    char o1[40],o2[40],o3[40],o4[40],o5[40],o6[40],o7[40];
    snprintf(o1,sizeof o1,"%s.1",OBJ); snprintf(o2,sizeof o2,"%s.2",OBJ);
    snprintf(o3,sizeof o3,"%s.3",OBJ); snprintf(o4,sizeof o4,"%s.4",OBJ);
    snprintf(o5,sizeof o5,"%s.5",OBJ); snprintf(o6,sizeof o6,"%s.6",OBJ);
    snprintf(o7,sizeof o7,"%s.7",OBJ);
    char bseq[16],bid[16],bdef[16],bsev[8];
    snprintf(bseq,sizeof bseq,"%ld",seq);
    snprintf(bid,sizeof bid,"%lu",eid);
    snprintf(bdef,sizeof bdef,"%lu",eid);
    snprintf(bsev,sizeof bsev,"%d",severity);

    /* snmptrap -v 2c -c <comm> <host> '' <notif> <oid type val>... */
    char *a[] = {
        "snmptrap","-v","2c","-c",(char*)community,(char*)host,"",NOTIF,
        o1,"i",bseq,
        o2,"i",bid,
        o3,"i",bdef,
        o4,"i",bsev,
        o5,"s",(char*)source,
        o6,"s",ts,
        o7,"s",(char*)desc,
        NULL
    };
    execvp("snmptrap", a);
    perror("execvp snmptrap");                            /* only on failure   */
    return 1;
}
