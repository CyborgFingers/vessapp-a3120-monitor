/*
 * snmp_leaves.h - the Promise raidv4 OID subtree, materialised from the
 * substrate as a flat, lexicographically-sorted table of scalar leaves.
 *
 * This IS the recovered OID -> i2_* mapping, in code. Each leaf's OID and type
 * come from raidv4.mib; each value comes from the SAME i2bApi_* read that
 * prsnmpagent.dll drives via pri2apiv4's i2_get* (see docs/RE-snmp-daemons.md
 * for the per-table OID<->i2 provenance). A GETNEXT walk over this array is a
 * faithful snmpwalk of .1.3.6.1.4.1.7933.1.20.
 */
#ifndef SNMP_LEAVES_H
#define SNMP_LEAVES_H

#define SNMP_OID_ARCS 24      /* max sub-identifiers in a leaf OID           */
#define SNMP_OID_STR  112     /* ".1.3.6.1.4.1.7933.1.20.2.5.1.10.1.0" + NUL */
#define SNMP_VAL_STR  132     /* longest DisplayString value we serve        */

typedef struct {
    unsigned long arc[SNMP_OID_ARCS];
    int           narc;
    char          oid[SNMP_OID_STR];  /* dotted, leading '.'                 */
    char          type[10];           /* net-snmp pass type keyword          */
    char          val[SNMP_VAL_STR];
} snmp_leaf;

/* Install the seeded fixture (mock SCSI transport) as the i2bdg session
 * transport for this process. Idempotent-ish; call once at startup. */
void snmp_fixture_install(void);

/* Read the substrate and build the sorted leaf table. Returns the leaf count
 * and points *out at a module-owned static array. */
int snmp_build_leaves(const snmp_leaf **out);

#endif /* SNMP_LEAVES_H */
