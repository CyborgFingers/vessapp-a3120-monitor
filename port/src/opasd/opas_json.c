/* opas_json.c - see opas_json.h. */
#include "opas_json.h"
#include <stdio.h>
#include <string.h>

void json_init(json_buf *j, char *buf, size_t cap) {
    j->buf = buf; j->cap = cap; j->len = 0; j->ovf = 0;
    if (cap) buf[0] = '\0';
}

/* Append n bytes verbatim, tracking overflow. */
static void put(json_buf *j, const char *s, size_t n) {
    if (j->ovf) return;
    if (j->len + n + 1 > j->cap) { j->ovf = 1; return; }
    memcpy(j->buf + j->len, s, n);
    j->len += n;
    j->buf[j->len] = '\0';
}

void json_raw(json_buf *j, const char *s) { put(j, s, strlen(s)); }

/* Emit a JSON string value with the mandatory escapes (", \, control chars). */
static void put_escaped(json_buf *j, const char *s) {
    put(j, "\"", 1);
    for (; s && *s; s++) {
        unsigned char c = (unsigned char)*s;
        switch (c) {
            case '"':  put(j, "\\\"", 2); break;
            case '\\': put(j, "\\\\", 2); break;
            case '\n': put(j, "\\n", 2);  break;
            case '\r': put(j, "\\r", 2);  break;
            case '\t': put(j, "\\t", 2);  break;
            default:
                if (c < 0x20) {
                    char u[7];
                    snprintf(u, sizeof u, "\\u%04x", c);
                    put(j, u, 6);
                } else {
                    put(j, (const char *)&c, 1);
                }
        }
    }
    put(j, "\"", 1);
}

void json_key(json_buf *j, const char *key) {
    put_escaped(j, key);
    put(j, ":", 1);
}

void json_str(json_buf *j, const char *key, const char *val) {
    json_key(j, key);
    put_escaped(j, val);
    put(j, ",", 1);
}

void json_int(json_buf *j, const char *key, long long val) {
    char n[32];
    int k = snprintf(n, sizeof n, "%lld", val);
    json_key(j, key);
    put(j, n, (size_t)k);
    put(j, ",", 1);
}

void json_u64(json_buf *j, const char *key, unsigned long long val) {
    char n[32];
    int k = snprintf(n, sizeof n, "%llu", val);
    json_key(j, key);
    put(j, n, (size_t)k);
    put(j, ",", 1);
}

/* Drop one trailing ',' so the caller can close the object/array cleanly. */
void json_end_trim(json_buf *j) {
    if (!j->ovf && j->len && j->buf[j->len - 1] == ',') {
        j->len--;
        j->buf[j->len] = '\0';
    }
}
