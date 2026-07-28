/*
 * promise_raid_helper.c - setuid-root privilege bridge for the mdadm RAID backend.
 *
 * The WebPAM php_i2 runs as www-data, which cannot open raw disks or run mdadm/
 * smartctl/wipefs. This tiny, auditable helper is installed setuid root
 * (root:www-data, mode 4750) and is the ONLY thing that runs privileged storage
 * tools. raid_mdadm.c invokes it as:  promise-raid-helper <tool|syncaction> <args...>
 *
 * Security model (defence in depth - never trusts the caller):
 *   - Whitelisted verb only: mdadm | smartctl | wipefs | syncaction. No shell,
 *     ever (execv, sanitized env, no system()/popen()).
 *   - Every /dev/ argument must be a whole disk /dev/sd[a-z]+ or /dev/md[0-9]+ -
 *     partitions, symlinks, .. and anything else are rejected.
 *   - Destructive ops (wipefs, mdadm --create/--zero-superblock) refuse ANY
 *     target disk that currently backs a mounted filesystem or active swap
 *     (this covers the OS/boot disk without having to trust a caller flag), plus
 *     the VESSAPP_RAID_EXCLUDE list.
 * On any check failure: print to stderr, exit 1, do nothing.
 */
#include <ctype.h>
#include <dirent.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <unistd.h>

static void die(const char *msg, const char *arg) {
    fprintf(stderr, "promise-raid-helper: %s%s%s\n", msg, arg ? " " : "", arg ? arg : "");
    exit(1);
}

/* A /dev/ token is allowed only as a bare whole disk (sd[a-z]+) or md[0-9]+. */
static int is_ok_dev(const char *p) {
    if (strncmp(p, "/dev/", 5) != 0) return 0;
    const char *n = p + 5;
    if (n[0] == 's' && n[1] == 'd') {
        n += 2;
        if (!*n) return 0;
        for (; *n; n++) if (!islower((unsigned char)*n)) return 0;   /* sd + letters only, no digits */
        return 1;
    }
    if (n[0] == 'm' && n[1] == 'd') {
        n += 2;
        if (!*n) return 0;
        for (; *n; n++) if (!isdigit((unsigned char)*n)) return 0;   /* md + digits only */
        return 1;
    }
    return 0;
}

/* basename after the last '/'. */
static const char *base(const char *p) {
    const char *s = strrchr(p, '/');
    return s ? s + 1 : p;
}

/* Whole-disk name for a device basename: sdXN -> sdX; dm-N/LVM handled by caller. */
static void whole_disk(const char *dev, char *out, size_t cap) {
    const char *b = base(dev);
    size_t len = strlen(b);
    /* strip a trailing partition number for sdXN / nvme...pN etc. */
    if (len && isdigit((unsigned char)b[len - 1])) {
        while (len && isdigit((unsigned char)b[len - 1])) len--;
        /* nvme0n1p1 -> drop the trailing 'p' too */
        if (len && b[len - 1] == 'p') len--;
    }
    if (len >= cap) len = cap - 1;
    memcpy(out, b, len);
    out[len] = '\0';
}

/* Add every in-use whole disk (from a mount/swap source device) to set[]. */
static int add_inuse_from(const char *dev, char set[][32], int n, int max) {
    if (strncmp(dev, "/dev/", 5) != 0) return n;
    const char *b = base(dev);
    if (strncmp(b, "dm-", 3) == 0 || strstr(dev, "/mapper/")) {
        /* LVM/device-mapper: add every underlying slave's whole disk. */
        char linkp[512], dm[64];
        /* resolve /dev/mapper/X -> dm-N basename */
        char sl[512];
        ssize_t r = readlink(dev, sl, sizeof sl - 1);
        if (r > 0) { sl[r] = '\0'; snprintf(dm, sizeof dm, "%.*s", (int)sizeof dm - 1, base(sl)); }
        else snprintf(dm, sizeof dm, "%.*s", (int)sizeof dm - 1, b);
        snprintf(linkp, sizeof linkp, "/sys/block/%s/slaves", dm);
        DIR *d = opendir(linkp);
        if (d) {
            struct dirent *e;
            while ((e = readdir(d)) && n < max) {
                if (e->d_name[0] == '.') continue;
                whole_disk(e->d_name, set[n], sizeof set[0]); n++;
            }
            closedir(d);
        }
        return n;
    }
    if (n < max) { whole_disk(b, set[n], sizeof set[0]); n++; }
    return n;
}

/* Build the set of in-use (mounted / swap) whole disks. */
static int inuse_disks(char set[][32], int max) {
    int n = 0;
    FILE *f = fopen("/proc/mounts", "r");
    char line[1024];
    if (f) { while (fgets(line, sizeof line, f)) { char dev[512]; if (sscanf(line, "%511s", dev) == 1) n = add_inuse_from(dev, set, n, max); } fclose(f); }
    f = fopen("/proc/swaps", "r");
    if (f) { if (fgets(line, sizeof line, f)) while (fgets(line, sizeof line, f)) { char dev[512]; if (sscanf(line, "%511s", dev) == 1) n = add_inuse_from(dev, set, n, max); } fclose(f); }
    /* VESSAPP_RAID_EXCLUDE: space/comma-separated disk names or /dev/ paths. */
    const char *ex = getenv("VESSAPP_RAID_EXCLUDE");
    if (ex) { char buf[512]; snprintf(buf, sizeof buf, "%s", ex); for (char *t = strtok(buf, " ,"); t && n < max; t = strtok(NULL, " ,")) { whole_disk(t, set[n], sizeof set[0]); n++; } }
    return n;
}

static int in_set(const char *disk, char set[][32], int n) {
    for (int i = 0; i < n; i++) if (strcmp(disk, set[i]) == 0) return 1;
    return 0;
}

/* --- mdadm.conf persistence (mdconf-add / mdconf-del) ---------------------- *
 * Runtime-created arrays must be recorded in /etc/mdadm/mdadm.conf, else after a
 * reboot they auto-assemble as an "auto-read-only" md127 instead of the active
 * /dev/mdN the UI created (early-boot incremental assembly, before the hostname
 * is up, treats an array with no ARRAY line as foreign). www-data can't write
 * the conf, so these root verbs upsert / remove a single, homehost-free
 * "ARRAY /dev/mdN UUID=<uuid>" line. raid_mdadm.c pairs each with
 * `update-initramfs -u`. Only two structured tokens ever cross the seam -
 * mdN (md[0-9]+) and a UUID ([0-9a-f:]+) - so nothing free-form reaches a shell;
 * both are re-validated here. */
static int is_mdname(const char *s) {
    if (s[0] != 'm' || s[1] != 'd' || !s[2]) return 0;
    for (const char *p = s + 2; *p; p++) if (!isdigit((unsigned char)*p)) return 0;
    return 1;
}

static int is_uuid(const char *s) {
    if (!*s) return 0;
    for (const char *p = s; *p; p++)
        if (!isxdigit((unsigned char)*p) && *p != ':') return 0;
    return 1;
}

/* Elevated == installed setuid AND invoked unprivileged (www-data -> root). Only
 * then do we pin the fixed system conf; an unprivileged self-run (the test suite)
 * may retarget PROMISE_MDADM_CONF - safe, because with no elevation the helper
 * can't write anything the caller couldn't already. */
static int elevated(void) { return geteuid() == 0 && getuid() != 0; }

static const char *conf_path(void) {
    if (!elevated()) { const char *p = getenv("PROMISE_MDADM_CONF"); if (p && *p) return p; }
    return "/etc/mdadm/mdadm.conf";
}

/* Drop this line on rewrite? True for an ARRAY line whose device == dev or whose
 * UUID == uuid (full-token match, so md0 != md01 and a uuid prefix can't alias). */
static int array_line_matches(const char *line, const char *dev, const char *uuid) {
    while (*line == ' ' || *line == '\t') line++;
    if (strncmp(line, "ARRAY", 5) != 0) return 0;
    if (dev) {
        const char *d = strstr(line, dev);
        if (d) { char a = d[strlen(dev)];
                 if (a==' '||a=='\t'||a=='\n'||a=='\r'||a=='\0') return 1; }
    }
    if (uuid && *uuid) {
        const char *u = strstr(line, "UUID=");
        if (u && strncasecmp(u + 5, uuid, strlen(uuid)) == 0) {
            char a = u[5 + strlen(uuid)];
            if (!isxdigit((unsigned char)a) && a != ':') return 1;
        }
    }
    return 0;
}

/* Atomically rewrite the conf = every line EXCEPT ARRAY lines matching dev/uuid,
 * then optionally append `add` (NULL = pure delete). Whole op as root. */
static void mdconf_rewrite(const char *dev, const char *uuid, const char *add) {
    if (elevated() && setuid(0) != 0) die("setuid failed", NULL);
    const char *path = conf_path();

    char tmp[600];
    snprintf(tmp, sizeof tmp, "%s.tmp.%d", path, (int)getpid());
    FILE *o = fopen(tmp, "w");
    if (!o) die("cannot write", tmp);

    FILE *in = fopen(path, "r");
    if (in) {
        char line[1024];
        while (fgets(line, sizeof line, in))
            if (!array_line_matches(line, dev, uuid)) fputs(line, o);
        fclose(in);
    }
    if (add) fprintf(o, "%s\n", add);
    if (fclose(o) != 0) { unlink(tmp); die("write failed", tmp); }
    if (rename(tmp, path) != 0) { unlink(tmp); die("rename failed", path); }
}

int main(int argc, char **argv) {
    if (argc < 2) die("usage: <mdadm|smartctl|wipefs|syncaction> <args...>", NULL);

    /* syncaction md<N> <check|idle|repair|resync>: write the md scrub control. */
    if (strcmp(argv[1], "syncaction") == 0) {
        if (argc != 4) die("syncaction: need <mdN> <action>", NULL);
        const char *md = argv[2], *act = argv[3];
        if (strncmp(md, "md", 2) != 0) die("syncaction: bad md", md);
        for (const char *p = md + 2; *p; p++) if (!isdigit((unsigned char)*p)) die("syncaction: bad md", md);
        if (strcmp(act, "check") && strcmp(act, "idle") && strcmp(act, "repair") && strcmp(act, "resync"))
            die("syncaction: bad action", act);
        char path[128];
        snprintf(path, sizeof path, "/sys/block/%s/md/sync_action", md);
        if (setuid(0) != 0) die("setuid failed", NULL);
        FILE *f = fopen(path, "w");
        if (!f) die("cannot open", path);
        fprintf(f, "%s\n", act);
        if (fclose(f) != 0) die("write failed", path);
        return 0;
    }

    /* mdconf-add md<N> <uuid>: upsert one ARRAY line (drop any stale line for the
     * same device or UUID first, then append the fresh one). */
    if (strcmp(argv[1], "mdconf-add") == 0) {
        if (argc != 4) die("mdconf-add: need <mdN> <uuid>", NULL);
        if (!is_mdname(argv[2])) die("mdconf-add: bad md", argv[2]);
        if (!is_uuid(argv[3]))   die("mdconf-add: bad uuid", argv[3]);
        char dev[32], add[128];
        snprintf(dev, sizeof dev, "/dev/%s", argv[2]);
        snprintf(add, sizeof add, "ARRAY %s UUID=%s", dev, argv[3]);
        mdconf_rewrite(dev, argv[3], add);
        return 0;
    }
    /* mdconf-del md<N>: remove the ARRAY line for this device. */
    if (strcmp(argv[1], "mdconf-del") == 0) {
        if (argc != 3) die("mdconf-del: need <mdN>", NULL);
        if (!is_mdname(argv[2])) die("mdconf-del: bad md", argv[2]);
        char dev[32];
        snprintf(dev, sizeof dev, "/dev/%s", argv[2]);
        mdconf_rewrite(dev, NULL, NULL);
        return 0;
    }

    /* Verb whitelist -> the tool's candidate binary paths (rejects bad verbs). */
    const char *tool = argv[1];
    const char *cands[2] = {NULL, NULL};
    if      (strcmp(tool, "mdadm")    == 0) { cands[0] = "/sbin/mdadm";        cands[1] = "/usr/sbin/mdadm"; }
    else if (strcmp(tool, "smartctl") == 0) { cands[0] = "/usr/sbin/smartctl"; cands[1] = "/sbin/smartctl"; }
    else if (strcmp(tool, "wipefs")   == 0) { cands[0] = "/sbin/wipefs";       cands[1] = "/usr/sbin/wipefs"; }
    else if (strcmp(tool, "update-initramfs") == 0) { cands[0] = "/usr/sbin/update-initramfs"; cands[1] = "/sbin/update-initramfs"; }
    else if (strcmp(tool, "pvcreate") == 0 || strcmp(tool, "vgcreate") == 0 ||
             strcmp(tool, "lvcreate") == 0 || strcmp(tool, "lvremove") == 0 ||
             strcmp(tool, "lvextend") == 0 || strcmp(tool, "lvs") == 0 ||
             strcmp(tool, "vgs") == 0 || strcmp(tool, "pvs") == 0) {
        /* LVM logical-drive layer on top of the md arrays. Only pvcreate touches a
         * raw device (/dev/mdN, validated + OS-guarded below); every other arg is a
         * VG/LV name (vgmdN, vgmdN/ldK) which raid_mdadm.c constrains to our arrays.
         * These can only ever destroy storage LVs, never the OS (sde1, not on LVM) -
         * the same blast radius the mdadm --stop/--zero-superblock verbs already have. */
        static char c0[64], c1[64];
        snprintf(c0, sizeof c0, "/sbin/%s", tool);
        snprintf(c1, sizeof c1, "/usr/sbin/%s", tool);
        cands[0] = c0; cands[1] = c1;
    }
    else die("verb not permitted", tool);

    /* Is this a destructive op (member wipe / array create / superblock zero /
     * add-a-member, which writes a superblock onto the new disk)? */
    int destructive = (strcmp(tool, "wipefs") == 0);
    for (int i = 2; i < argc; i++)
        if (strcmp(argv[i], "--create") == 0 || strcmp(argv[i], "-C") == 0 ||
            strcmp(argv[i], "--zero-superblock") == 0 ||
            strcmp(argv[i], "--add") == 0) destructive = 1;

    /* Validate every /dev/ argument BEFORE we even locate the tool - bad input is
     * refused regardless of whether the tool is installed on this host. */
    char inuse[64][32];
    int nin = destructive ? inuse_disks(inuse, 64) : 0;
    for (int i = 2; i < argc; i++) {
        if (strncmp(argv[i], "/dev/", 5) != 0) continue;   /* non-device arg (flag/value) */
        if (!is_ok_dev(argv[i])) die("device not allowed (must be a whole /dev/sdX or /dev/mdN)", argv[i]);
        if (destructive) {
            char wd[32]; whole_disk(argv[i], wd, sizeof wd);
            if (in_set(wd, inuse, nin)) die("refusing destructive op on in-use/OS disk", argv[i]);
        }
    }

    /* Args are good - only now resolve the tool binary. */
    const char *path = NULL;
    for (int i = 0; i < 2 && cands[i]; i++) if (access(cands[i], X_OK) == 0) { path = cands[i]; break; }
    if (!path) die("tool not installed", tool);

    /* Run the tool as root with the caller's args (argv[1]=tool name onward). */
    if (setuid(0) != 0) die("setuid failed", NULL);
    char *env[] = { "PATH=/usr/sbin:/sbin:/usr/bin:/bin", NULL };
    execve(path, &argv[1], env);
    die("exec failed", path);
    return 1;
}
