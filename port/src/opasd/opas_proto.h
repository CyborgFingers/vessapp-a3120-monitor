/*
 * opas_proto.h - opasd wire framing + dispatch entrypoint (Phase 3).
 *
 * The Linux replacement for the Windows php_i2 -> pri2apiv4 -> i2.exe path,
 * collapsed onto the Phase-2/2b substrate (libi2 / libi2bdg). See
 * docs/RE-opas.md for the recovered contract and provenance.
 *
 * FRAME (both directions), port-defined (the Windows PHP<->engine seam was a C
 * ABI, not a wire protocol - RE-opas.md §0/§2):
 *   [ magic:u32 LE = OPAS_MAGIC ][ len:u32 LE ][ body:len bytes ]
 * Request body : newline-delimited "key=value"; first line "method=NAME".
 * Response body: a JSON object, always with a "status" field (0 == OK).
 */
#ifndef OPAS_PROTO_H
#define OPAS_PROTO_H

#include <stddef.h>
#include <stdint.h>
#include "opas_session.h"

/* Recovered Promise IPC signature (i2_micops.h I2_IPC_SIGNATURE), reused as the
 * frame magic so the value is provenance-backed rather than arbitrary. */
#define OPAS_MAGIC        0xBABA0001u
#define OPAS_MAX_BODY     (256u * 1024u)  /* cap a single frame body            */

/* Status codes surfaced in responses. 0 == OK; the rest mirror the PHP-visible
 * PIERR_* / I2B_API_RET_* the callers already branch on (RE-opas.md §1.3). */
#define OPAS_OK                    0
#define OPAS_ERR_BAD_REQUEST       1     /* malformed / unknown method          */
#define OPAS_ERR_INACTIVE_SESSION  2     /* PIERR_INACTIVE_SESSION (auth gate)  */
#define OPAS_ERR_LOGIN_FAILED      3     /* bad credentials / consumed dc       */
#define OPAS_ERR_ENGINE            4     /* I2B_API_RET_I2API_ERR (no substrate)*/
#define OPAS_ERR_UNSUPPORTED       6     /* I2B_API_RET_UNSUPPORTED (cataloged) */

/*
 * The socket-free dispatch core (the unit-test boundary). Parses one request
 * body, runs it against the session manager + substrate, and writes a JSON
 * response body into out[cap]. Returns the response length (< cap), or 0 on a
 * hard buffer failure. Never touches sockets.
 */
size_t opas_dispatch(opas_session_mgr *mgr,
                     const char *req, size_t reqlen,
                     char *out, size_t cap);

#endif /* OPAS_PROTO_H */
