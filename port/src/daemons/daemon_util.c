/* daemon_util.c - see daemon_util.h. */
#include "daemon_util.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <fcntl.h>
#include <unistd.h>
#include <signal.h>
#include <sys/file.h>

volatile int daemon_stop = 0;

static void on_signal(int sig) { (void)sig; daemon_stop = 1; }

int daemon_pidlock(const char *name) {
    char path[128];
    snprintf(path, sizeof path, "/tmp/%s.pid", name);   /* /run when packaged */
    int fd = open(path, O_CREAT | O_RDWR, 0644);
    if (fd < 0) return -1;
    if (flock(fd, LOCK_EX | LOCK_NB) != 0) { close(fd); return -1; } /* already running */
    char pid[16]; int n = snprintf(pid, sizeof pid, "%d\n", (int)getpid());
    if (ftruncate(fd, 0) != 0) { /* best-effort */ }
    if (write(fd, pid, (size_t)n) != n) { /* best-effort */ }
    /* fd intentionally leaked for process lifetime to hold the lock */
    return 0;
}

void daemon_install_signals(void) {
    struct sigaction sa; memset(&sa, 0, sizeof sa);
    sa.sa_handler = on_signal;
    sigaction(SIGTERM, &sa, NULL);
    sigaction(SIGINT,  &sa, NULL);
}
