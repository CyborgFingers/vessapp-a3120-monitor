/* opas_session.c - see opas_session.h. */
#include "opas_session.h"

#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include <time.h>
#include <sys/mman.h>
#include <unistd.h>
#include <fcntl.h>
#include <sys/stat.h>

/* ponytail: fixed-cap ring tables. A single-box management daemon has a handful
 * of live sessions; oldest is overwritten if the cap is exceeded. Upgrade to a
 * hash map only if a real deployment ever needs hundreds of concurrent admins. */
#define DC_CAP        32
#define SESS_CAP      32
#define CODE_LEN      17   /* 16 hex chars + NUL */

typedef struct { unsigned id; char code[CODE_LEN]; int used; } dc_slot;
typedef struct { unsigned id; int used; } sess_slot;

struct opas_session_mgr {
    dc_slot     dc[DC_CAP];
    sess_slot   sess[SESS_CAP];
    unsigned    next_id;      /* monotonic id source (dc + sessions share it)  */
    unsigned    rng_state;    /* challenge source; shared so ids stay unique   */
    volatile int lock;        /* cross-process spinlock guarding this struct   */
    opas_auth_fn auth;
    void        *auth_ctx;
};

/*
 * The manager lives in MAP_SHARED|MAP_ANONYMOUS memory: forked children (the
 * php-fpm master runs the extension's MINIT, then forks its workers) all see
 * ONE dc/session table, so a challenge issued by one worker validates on any
 * other - the Phase-6 requirement for the concurrent frameset under FPM. For
 * single-process users (opasd, tests, php -S) the semantics are unchanged.
 * The auth fn pointer is set pre-fork (MINIT) and is valid in every child of
 * that process image.
 *
 * ponytail: spinlock + steal-after-2s instead of a robust pthread mutex - the
 * critical sections are 32-entry array scans (microseconds) and this needs no
 * -lpthread in either build system (cmake + phpize). Upgrade to
 * PTHREAD_MUTEX_ROBUST if a worker ever provably dies inside the lock.
 */
/* auth is a live CODE pointer, so it must stay PROCESS-LOCAL - never in the
 * shared/persisted mmap. A second process image mapping the same file (another
 * fpm pool, or the next restart under ASLR) would otherwise call a pointer that
 * is garbage in its own address space -> segfault. Each process sets its own at
 * MINIT (set_auth), inherited by its fork()ed workers. */
static opas_auth_fn s_auth;
static void        *s_auth_ctx;

static void mgr_lock(struct opas_session_mgr *m) {
    for (int spins = 0; __atomic_test_and_set(&m->lock, __ATOMIC_ACQUIRE); spins++) {
        if (spins > 20000) { m->lock = 0; continue; }   /* steal: holder died */
        usleep(100);
    }
}
static void mgr_unlock(struct opas_session_mgr *m) {
    __atomic_clear(&m->lock, __ATOMIC_RELEASE);
}

static unsigned rng_next(struct opas_session_mgr *m) {
    /* Non-crypto challenge source. The dynamic-code *algorithm* is engine-side
     * and UNRESOLVED off-device (RE-opas.md §1.4); this only has to be unique
     * and unpredictable enough for the envelope. LCG (glibc constants). */
    m->rng_state = m->rng_state * 1103515245u + 12345u;
    return m->rng_state;
}

opas_session_mgr *opas_session_create(void) {
    /* File-backed shared mapping so the dc/session table survives an fpm restart
     * (redeploy / crash) instead of logging every WebPAM user out. The file lives
     * on tmpfs by default: it persists across an fpm restart but clears on reboot.
     * Falls back to anonymous shared memory - the old volatile behaviour - if the
     * file can't be opened (opasd, tests, php -S, or a read-only /run). */
    const char *path = getenv("VESSAPP_SESSION_FILE");
    if (!path || !*path) path = "/run/promise/opas-sessions.dat";

    opas_session_mgr *m = MAP_FAILED;
    int fresh = 1;
    char dir[512];
    snprintf(dir, sizeof dir, "%s", path);
    char *slash = strrchr(dir, '/');
    if (slash && slash != dir) { *slash = '\0'; mkdir(dir, 0755); }
    int fd = open(path, O_RDWR | O_CREAT, 0600);
    if (fd >= 0) {
        struct stat st;
        fresh = (fstat(fd, &st) != 0) || st.st_size < (off_t)sizeof *m;
        if (ftruncate(fd, (off_t)sizeof *m) == 0)
            m = mmap(NULL, sizeof *m, PROT_READ | PROT_WRITE, MAP_SHARED, fd, 0);
        close(fd);                    /* mapping keeps its own reference */
    }
    if (m == MAP_FAILED) {            /* fallback: volatile anonymous shared mem */
        m = mmap(NULL, sizeof *m, PROT_READ | PROT_WRITE,
                 MAP_SHARED | MAP_ANONYMOUS, -1, 0);
        fresh = 1;
    }
    if (m == MAP_FAILED) return NULL;

    if (fresh) {
        memset(m, 0, sizeof *m);
        m->next_id = 1;
        m->rng_state = (unsigned)time(NULL) ^ (unsigned)getpid()
                     ^ (unsigned)(size_t)m;
    }
    /* The lock is process-shared but stale after a crash/restart, so clear it (no
     * live process holds it in a fresh image; the steal-after-2s spinlock is the
     * backstop). auth lives in the process-local s_auth, set by MINIT - the shared
     * struct's auth/auth_ctx fields are no longer read. */
    m->lock = 0;
    return m;
}

void opas_session_destroy(opas_session_mgr *m) {
    if (m) munmap(m, sizeof *m);
}

void opas_session_set_auth(opas_session_mgr *m, opas_auth_fn fn, void *ctx) {
    (void)m;                 /* auth is process-local, not in the shared map */
    s_auth = fn; s_auth_ctx = ctx;
}

static dc_slot *dc_find(opas_session_mgr *m, unsigned id) {
    for (int i = 0; i < DC_CAP; i++)
        if (m->dc[i].used && m->dc[i].id == id) return &m->dc[i];
    return NULL;
}
static dc_slot *dc_alloc(opas_session_mgr *m) {
    for (int i = 0; i < DC_CAP; i++) if (!m->dc[i].used) return &m->dc[i];
    return &m->dc[m->next_id % DC_CAP];   /* overwrite oldest-ish */
}

int opas_dc_issue(opas_session_mgr *m, unsigned *out_id, char *code, size_t cap) {
    if (!m || !out_id || !code || cap < CODE_LEN) return -1;
    mgr_lock(m);
    dc_slot *s = dc_alloc(m);
    s->used = 1;
    s->id   = m->next_id++;
    snprintf(s->code, sizeof s->code, "%08x%08x", rng_next(m), rng_next(m));
    *out_id = s->id;
    memcpy(code, s->code, CODE_LEN);
    mgr_unlock(m);
    return 0;
}

static sess_slot *sess_alloc(opas_session_mgr *m) {
    for (int i = 0; i < SESS_CAP; i++) if (!m->sess[i].used) return &m->sess[i];
    return &m->sess[m->next_id % SESS_CAP];
}

int opas_login(opas_session_mgr *m, unsigned dc_id, const char *username,
               const char *md5password, unsigned *out_sid) {
    if (!m || !username || !md5password || !out_sid) return -1;
    mgr_lock(m);
    dc_slot *dc = dc_find(m, dc_id);
    if (!dc) { mgr_unlock(m); return -1; }    /* unknown / already-consumed dc  */

    int ok = s_auth ? s_auth(dc->code, username, md5password, s_auth_ctx) : 0;
    dc->used = 0;                             /* one-time: consume the challenge */
    if (!ok) { mgr_unlock(m); return -2; }    /* auth hook rejected             */

    sess_slot *s = sess_alloc(m);
    s->used = 1;
    s->id   = m->next_id++;
    *out_sid = s->id;
    mgr_unlock(m);
    return 0;
}

int opas_session_valid(opas_session_mgr *m, unsigned sid) {
    if (!m || sid == 0) return 0;
    mgr_lock(m);
    for (int i = 0; i < SESS_CAP; i++)
        if (m->sess[i].used && m->sess[i].id == sid) { mgr_unlock(m); return 1; }
    mgr_unlock(m);
    return 0;
}

int opas_logout(opas_session_mgr *m, unsigned sid) {
    if (!m) return -1;
    mgr_lock(m);
    for (int i = 0; i < SESS_CAP; i++)
        if (m->sess[i].used && m->sess[i].id == sid) {
            m->sess[i].used = 0; mgr_unlock(m); return 0;
        }
    mgr_unlock(m);
    return -1;
}
