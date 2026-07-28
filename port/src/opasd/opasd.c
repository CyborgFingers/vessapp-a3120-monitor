/*
 * opasd.c - the OPAS UNIX-socket daemon (Phase 3).
 *
 * Thin transport shell around opas_dispatch(): accept a connection, read one
 * request frame, dispatch against the substrate + session manager, write one
 * response frame. One connection served at a time - the substrate handle is the
 * serialization point (RE-fwinterf-pri2api.md §5; RE-opas.md §5).
 *
 * The real enclosure/RAID backend is the process-global session, which lazily
 * discovers the Promise controller on /dev/sg* ($I2BDG_SG_DEV overrides). The
 * credential-check auth hook defaults to DENY: the user-db is engine-internal
 * and not available off-device (RE-opas.md §1.4/§7). Bind a real hook on-device.
 *
 * Socket path: $OPASD_SOCK, else /tmp/opasd.sock.
 */
#include "opas_proto.h"
#include "opas_session.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <errno.h>
#include <signal.h>
#include <sys/socket.h>
#include <sys/un.h>

#define DEFAULT_SOCK "/tmp/opasd.sock"

static volatile sig_atomic_t g_stop;
static void on_signal(int s) { (void)s; g_stop = 1; }

/* Read exactly n bytes; 0 on success, -1 on EOF/error. */
static int read_full(int fd, void *buf, size_t n) {
    unsigned char *p = buf;
    while (n) {
        ssize_t r = read(fd, p, n);
        if (r == 0) return -1;
        if (r < 0) { if (errno == EINTR) continue; return -1; }
        p += r; n -= (size_t)r;
    }
    return 0;
}

static int write_full(int fd, const void *buf, size_t n) {
    const unsigned char *p = buf;
    while (n) {
        ssize_t w = write(fd, p, n);
        if (w < 0) { if (errno == EINTR) continue; return -1; }
        p += w; n -= (size_t)w;
    }
    return 0;
}

/* u32 LE frame header (matches the PHP opas_client). */
static uint32_t rd_u32le(const unsigned char *b) {
    return (uint32_t)b[0] | (uint32_t)b[1] << 8 | (uint32_t)b[2] << 16 | (uint32_t)b[3] << 24;
}
static void wr_u32le(unsigned char *b, uint32_t v) {
    b[0] = v & 0xff; b[1] = v >> 8 & 0xff; b[2] = v >> 16 & 0xff; b[3] = v >> 24 & 0xff;
}

/* Serve one framed request on an accepted connection. */
static void serve_conn(int fd, opas_session_mgr *mgr) {
    unsigned char hdr[8];
    if (read_full(fd, hdr, sizeof hdr) != 0) return;
    if (rd_u32le(hdr) != OPAS_MAGIC) return;              /* not our protocol   */
    uint32_t len = rd_u32le(hdr + 4);
    if (len > OPAS_MAX_BODY) return;

    char *req = malloc(len ? len : 1);
    if (!req) return;
    if (len && read_full(fd, req, len) != 0) { free(req); return; }

    static char resp[OPAS_MAX_BODY];
    size_t rlen = opas_dispatch(mgr, req, len, resp, sizeof resp);
    free(req);

    unsigned char rhdr[8];
    wr_u32le(rhdr, OPAS_MAGIC);
    wr_u32le(rhdr + 4, (uint32_t)rlen);
    if (write_full(fd, rhdr, sizeof rhdr) == 0)
        write_full(fd, resp, rlen);
}

int main(void) {
    signal(SIGPIPE, SIG_IGN);
    signal(SIGINT, on_signal);
    signal(SIGTERM, on_signal);

    const char *path = getenv("OPASD_SOCK");
    if (!path || !*path) path = DEFAULT_SOCK;

    struct sockaddr_un addr;
    if (strlen(path) >= sizeof addr.sun_path) {
        fprintf(stderr, "opasd: socket path too long: %s\n", path);
        return 1;
    }

    int srv = socket(AF_UNIX, SOCK_STREAM, 0);
    if (srv < 0) { perror("socket"); return 1; }

    memset(&addr, 0, sizeof addr);
    addr.sun_family = AF_UNIX;
    strncpy(addr.sun_path, path, sizeof addr.sun_path - 1);
    unlink(path);
    if (bind(srv, (struct sockaddr *)&addr, sizeof addr) < 0) { perror("bind"); return 1; }
    if (listen(srv, 8) < 0) { perror("listen"); return 1; }

    opas_session_mgr *mgr = opas_session_create();
    if (!mgr) { fprintf(stderr, "opasd: out of memory\n"); return 1; }
    /* Default auth hook stays NULL -> opas_login denies (RE-opas.md §1.4). */

    fprintf(stderr, "opasd: listening on %s\n", path);
    while (!g_stop) {
        int c = accept(srv, NULL, NULL);
        if (c < 0) { if (errno == EINTR) continue; break; }
        serve_conn(c, mgr);
        close(c);
    }

    opas_session_destroy(mgr);
    close(srv);
    unlink(path);
    return 0;
}
