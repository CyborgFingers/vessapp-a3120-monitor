/*
 * promise-buzzer - sound the A3120 fault alarm on the mainboard PC speaker.
 *
 * The vendor sounds an audible alarm on a drive/enclosure fault; on Linux the
 * buzzer is the PC speaker (pcspkr), driven via the KIOCSOUND console ioctl
 * (~1 kHz) - the same mechanism the reference driver's ledctl.py uses. This is
 * the piece the event path was missing: promise-mdmonitor calls
 * `promise-buzzer alarm` on a Major+ RAID fault.
 *
 * usage:
 *   promise-buzzer alarm [seconds]  beep 1s-on/1s-off until <seconds> elapse
 *                                   (default 300) OR a mute/disable appears.
 *                                   No-op if /etc/promise/buzzer.disabled.
 *   promise-buzzer mute             silence a running alarm.
 *   promise-buzzer test             one 1-second beep.
 *
 * Single-instance (flock) so overlapping faults don't stack beeps.
 */
#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/file.h>
#include <sys/ioctl.h>
#include <sys/stat.h>
#include <unistd.h>

#define KIOCSOUND     0x4B2F
#define BEEP_DIVISOR  (1193180 / 1000)          /* ~1000 Hz */
/* mute/disable live in a www-data-writable dir so the WebPAM buzzer page (which
 * runs as www-data) can silence/disable the alarm; the lock stays root-only. */
#define STATE_DIR     "/var/lib/promise-buzzer"
#define MUTE_FILE     STATE_DIR "/mute"
#define DISABLE_FILE  STATE_DIR "/disabled"
#define SOUNDING_FILE STATE_DIR "/sounding"     /* present while an alarm beeps -> getBuzzers()->bs_Sounding */
#define LOCK_FILE     "/run/promise-buzzer.lock"
#define ALARM_DEFAULT 300                        /* 5 min, like the vendor */

static int exists(const char *p) { struct stat s; return stat(p, &s) == 0; }

static int beep_fd(void)
{
    const char *devs[] = { "/dev/tty0", "/dev/tty1", "/dev/console", NULL };
    for (int i = 0; devs[i]; i++) {
        int fd = open(devs[i], O_WRONLY | O_NOCTTY);
        if (fd >= 0) return fd;
    }
    return -1;
}

static void beep(int fd, int on) { ioctl(fd, KIOCSOUND, on ? BEEP_DIVISOR : 0); }

int main(int argc, char **argv)
{
    if (argc < 2) { fprintf(stderr, "usage: promise-buzzer alarm [sec] | mute | test\n"); return 2; }

    if (!strcmp(argv[1], "mute")) {
        int fd = open(MUTE_FILE, O_CREAT | O_WRONLY, 0644);
        if (fd >= 0) close(fd);
        return 0;
    }

    int fd = beep_fd();
    if (fd < 0) { perror("promise-buzzer: open console"); return 1; }

    if (!strcmp(argv[1], "test")) { beep(fd, 1); sleep(1); beep(fd, 0); close(fd); return 0; }

    if (strcmp(argv[1], "alarm")) { fprintf(stderr, "promise-buzzer: unknown command\n"); close(fd); return 2; }

    if (exists(DISABLE_FILE)) { close(fd); return 0; }          /* master-disabled */

    /* one alarm at a time */
    int lk = open(LOCK_FILE, O_CREAT | O_WRONLY, 0644);
    if (lk < 0 || flock(lk, LOCK_EX | LOCK_NB) != 0) { close(fd); return 0; }

    unlink(MUTE_FILE);                                          /* a fresh fault clears a stale mute */
    { int s = open(SOUNDING_FILE, O_CREAT | O_WRONLY, 0664); if (s >= 0) close(s); }
    int secs = (argc > 2) ? atoi(argv[2]) : ALARM_DEFAULT;
    if (secs <= 0) secs = ALARM_DEFAULT;

    for (int t = 0; t < secs; t += 2) {
        if (exists(MUTE_FILE) || exists(DISABLE_FILE)) break;
        beep(fd, 1); sleep(1);
        beep(fd, 0); sleep(1);
    }
    beep(fd, 0);
    unlink(SOUNDING_FILE);
    close(fd);
    return 0;
}
