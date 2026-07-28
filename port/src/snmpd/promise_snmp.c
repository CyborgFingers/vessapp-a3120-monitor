/*
 * promise_snmp.c - net-snmp pass_persist handler for the Promise raidv4 subtree.
 *
 * The Linux stand-in for prsnmpagent.dll's AgentX subagent. Rather than link the
 * net-snmp agent library and re-register 15 SMI tables, we serve the identical
 * OID subtree via net-snmp's native `pass_persist` line protocol (snmpd.conf:
 *   pass_persist .1.3.6.1.4.1.7933.1.20  <this program> ). snmpd routes every
 * GET/GETNEXT under that root to us; we answer from the substrate-materialised
 * leaf table (snmp_leaves.c). Read-only (rocommunity), matching snmpd.conf.
 *
 * pass_persist protocol (man snmpd.conf): stdin gives "PING" | "get" | "getnext"
 * | "set" each followed by an OID line; we reply PONG, or <oid>/<type>/<value>,
 * or "NONE", or "not-writable". fflush after every reply (snmpd blocks on it).
 */
#include "snmp_leaves.h"
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>

static const snmp_leaf *g_leaf;
static int              g_n;

/* Parse a dotted numeric OID into arcs. Returns arc count. */
static int parse_oid(const char *s, unsigned long *arc, int cap) {
    int n = 0;
    for (const char *p = s; *p; ) {
        if (*p == '.' || *p == ' ' || *p == '\n' || *p == '\r') { p++; continue; }
        char *end; unsigned long v = strtoul(p, &end, 10);
        if (end == p) break;
        if (n < cap) arc[n++] = v;
        p = end;
    }
    return n;
}

/* Lexicographic numeric OID compare. */
static int cmp(const unsigned long *a, int an, const unsigned long *b, int bn) {
    int n = an < bn ? an : bn;
    for (int i = 0; i < n; i++) if (a[i] != b[i]) return a[i] < b[i] ? -1 : 1;
    return an - bn;
}

/* Exact-match leaf for a GET (NULL if none). */
static const snmp_leaf *find_exact(const unsigned long *q, int qn) {
    for (int i = 0; i < g_n; i++)
        if (cmp(g_leaf[i].arc, g_leaf[i].narc, q, qn) == 0) return &g_leaf[i];
    return NULL;
}

/* First leaf strictly greater than q for a GETNEXT (leaves are sorted).
 * ponytail: linear scan, O(n) per call; n<200 so a whole walk is trivial. */
static const snmp_leaf *find_next(const unsigned long *q, int qn) {
    for (int i = 0; i < g_n; i++)
        if (cmp(g_leaf[i].arc, g_leaf[i].narc, q, qn) > 0) return &g_leaf[i];
    return NULL;
}

static void reply(const snmp_leaf *L) {
    if (!L) { printf("NONE\n"); return; }
    printf("%s\n%s\n%s\n", L->oid, L->type, L->val);
}

/* read a line without trailing newline; returns 0 on EOF */
static int getline_trim(char *buf, size_t cap) {
    if (!fgets(buf, (int)cap, stdin)) return 0;
    size_t n = strlen(buf);
    while (n && (buf[n-1] == '\n' || buf[n-1] == '\r')) buf[--n] = '\0';
    return 1;
}

int main(void) {
    if (getenv("I2BDG_MOCK")) snmp_fixture_install();  /* off-device fixture   */
    g_n = snmp_build_leaves(&g_leaf);

    setvbuf(stdout, NULL, _IONBF, 0);
    char cmd[64], oidline[256];
    unsigned long q[SNMP_OID_ARCS];

    while (getline_trim(cmd, sizeof cmd)) {
        if (strcasecmp(cmd, "PING") == 0) { printf("PONG\n"); continue; }

        int is_get = strcasecmp(cmd, "get") == 0;
        if (is_get || strcasecmp(cmd, "getnext") == 0) {
            if (!getline_trim(oidline, sizeof oidline)) break;
            int qn = parse_oid(oidline, q, SNMP_OID_ARCS);
            reply(is_get ? find_exact(q, qn) : find_next(q, qn));
            continue;
        }
        if (strcasecmp(cmd, "set") == 0) {
            getline_trim(oidline, sizeof oidline);        /* oid  */
            getline_trim(oidline, sizeof oidline);        /* type value */
            printf("not-writable\n");
            continue;
        }
        /* unknown verb: ignore per protocol */
    }
    return 0;
}
