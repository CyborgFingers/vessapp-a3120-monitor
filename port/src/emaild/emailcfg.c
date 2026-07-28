/* emailcfg.c - tiny single-purpose XML reader for emailsetting.xml. See .h. */
#include "emailcfg.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

/* Copy the text of the first <tag>...</tag> into out[cap]. Returns 1 if found. */
static int tag_text(const char *xml, const char *tag, char *out, size_t cap) {
    char open[64]; snprintf(open, sizeof open, "<%s>", tag);
    const char *s = strstr(xml, open);
    if (!s) { if (cap) out[0] = '\0'; return 0; }
    s += strlen(open);
    const char *e = strstr(s, "</");
    if (!e) e = s + strlen(s);
    size_t n = (size_t)(e - s);
    while (n && (*s == ' ' || *s == '\n' || *s == '\t' || *s == '\r')) { s++; n--; }
    while (n && (s[n-1] == ' ' || s[n-1] == '\n' || s[n-1] == '\t' || s[n-1] == '\r')) n--;
    if (n >= cap) n = cap - 1;
    memcpy(out, s, n); out[n] = '\0';
    return 1;
}

int email_cfg_load(const char *path, email_cfg *out) {
    FILE *f = fopen(path, "rb");
    if (!f) return -1;
    char xml[8192]; size_t n = fread(xml, 1, sizeof xml - 1, f); fclose(f);
    xml[n] = '\0';

    /* defaults */
    memset(out, 0, sizeof *out);
    out->enabled = 1; out->port = 25; out->severity_threshold = 3;
    strcpy(out->server, "127.0.0.1");
    strcpy(out->sender, "vessapp@localhost");
    strcpy(out->subject_prefix, "[VessApp]");

    char v[128];
    if (tag_text(xml, "enabled", v, sizeof v))          out->enabled = atoi(v);
    if (tag_text(xml, "server", v, sizeof v) && v[0])   snprintf(out->server, sizeof out->server, "%s", v);
    if (tag_text(xml, "port", v, sizeof v) && v[0])     out->port = atoi(v);
    if (tag_text(xml, "sender", v, sizeof v) && v[0])   snprintf(out->sender, sizeof out->sender, "%s", v);
    tag_text(xml, "subjectPrefix", out->subject_prefix, sizeof out->subject_prefix);
    if (!out->subject_prefix[0]) strcpy(out->subject_prefix, "[VessApp]");
    if (tag_text(xml, "severityThreshold", v, sizeof v) && v[0]) out->severity_threshold = atoi(v);

    /* all <recipient>...</recipient> */
    const char *p = xml;
    while (out->nrcpt < EMAIL_MAX_RCPT) {
        const char *s = strstr(p, "<recipient>");
        if (!s) break;
        s += strlen("<recipient>");
        const char *e = strstr(s, "</recipient>");
        if (!e) break;
        size_t len = (size_t)(e - s);
        while (len && (*s == ' ' || *s == '\n' || *s == '\t' || *s == '\r')) { s++; len--; }
        while (len && (s[len-1] == ' ' || s[len-1] == '\n' || s[len-1] == '\t' || s[len-1] == '\r')) len--;
        if (len && len < sizeof out->rcpt[0]) {
            memcpy(out->rcpt[out->nrcpt], s, len); out->rcpt[out->nrcpt][len] = '\0';
            out->nrcpt++;
        }
        p = e + strlen("</recipient>");
    }
    return 0;
}
