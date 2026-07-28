/*
 * opas_json.h - minimal append-only JSON builder for opasd responses.
 * ponytail: hand-rolled emitter (no cJSON dependency) - opasd responses are flat
 * objects and arrays of flat objects. Truncates safely if the buffer fills.
 */
#ifndef OPAS_JSON_H
#define OPAS_JSON_H

#include <stddef.h>

typedef struct {
    char  *buf;
    size_t cap;
    size_t len;   /* bytes written (excludes NUL); == cap on overflow          */
    int    ovf;   /* set once the buffer overflowed                            */
} json_buf;

void json_init(json_buf *j, char *buf, size_t cap);
void json_raw(json_buf *j, const char *s);              /* literal, no escaping */
void json_key(json_buf *j, const char *key);            /* "key":              */
void json_str(json_buf *j, const char *key, const char *val); /* "key":"esc",  */
void json_int(json_buf *j, const char *key, long long val);   /* "key":N,      */
void json_u64(json_buf *j, const char *key, unsigned long long val);
void json_end_trim(json_buf *j);   /* drop a trailing comma (before } or ])     */

#endif /* OPAS_JSON_H */
