/* encl_backend.c - VESSAPP_BACKEND resolver (read once, cached). */
#include "encl_backend.h"

#include <stdlib.h>
#include <string.h>

static int g_cached = -1;   /* -1 = not resolved yet */

encl_backend_t encl_backend(void) {
    if (g_cached < 0) {
        const char *e = getenv("VESSAPP_BACKEND");
        if      (e && strcmp(e, "smbus") == 0) g_cached = ENCL_SMBUS;
        else if (e && strcmp(e, "mmio")  == 0) g_cached = ENCL_MMIO;
        else                                   g_cached = ENCL_SCSI;
    }
    return (encl_backend_t)g_cached;
}

void encl_backend_set(int b) {
    g_cached = b;   /* negative -> next encl_backend() re-reads the env */
}
