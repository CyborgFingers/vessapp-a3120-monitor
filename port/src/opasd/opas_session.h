/*
 * opas_session.h - opasd login/session envelope (Phase 3, RE-opas.md §1).
 *
 * Implements the recovered flow: getdynamiccode() -> challenge {id,code};
 * login(dc_id, username, md5password) -> engine-issued sessionId; authenticated
 * calls gated on the sessionId; logout.
 *
 * The credential CHECK itself is engine-internal and NOT recoverable off-device
 * (RE-opas.md §1.4), so it is delegated to an injected auth hook. This envelope
 * (challenge issue, one-time consume, token issue/validate/expire) is real and
 * fully port-owned; no crypto or user-db is invented here.
 *
 * The manager's state lives in fork-shared memory (MAP_SHARED|MAP_ANONYMOUS):
 * create it before forking (e.g. php-fpm MINIT runs in the master) and every
 * child works against the same dc/session table (Phase 6, multi-worker FPM).
 * Single-process use (opasd, tests) is unchanged.
 */
#ifndef OPAS_SESSION_H
#define OPAS_SESSION_H

#include <stddef.h>

typedef struct opas_session_mgr opas_session_mgr;

/*
 * Auth verify hook. Return 1 to authenticate, 0 to reject. `dc_code` is the
 * challenge string bound to the dc id the client logged in with (so a hook can
 * recompute MD5(MD5(MD5(code)+pw)+user) against a stored password). Real
 * deployments bind this to the RAID engine user-db (on-device / Phase 4); tests
 * inject a known-answer hook. Default: deny (no user-db available).
 */
typedef int (*opas_auth_fn)(const char *dc_code, const char *username,
                            const char *md5password, void *ctx);

opas_session_mgr *opas_session_create(void);
void              opas_session_destroy(opas_session_mgr *m);
void opas_session_set_auth(opas_session_mgr *m, opas_auth_fn fn, void *ctx);

/* Issue a dynamic code. Writes the id and the code string (into code[cap]).
 * Returns 0 on success. */
int opas_dc_issue(opas_session_mgr *m, unsigned *out_id, char *code, size_t cap);

/* Log in against the challenge `dc_id` (consumed one-time on success). On
 * success writes the session id and returns 0; else <0 (bad/expired dc or the
 * auth hook rejected). */
int opas_login(opas_session_mgr *m, unsigned dc_id, const char *username,
               const char *md5password, unsigned *out_sid);

/* 1 if the session id is live. */
int opas_session_valid(opas_session_mgr *m, unsigned sid);

/* Invalidate a session. Returns 0 if it existed. */
int opas_logout(opas_session_mgr *m, unsigned sid);

#endif /* OPAS_SESSION_H */
