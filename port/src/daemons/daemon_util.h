/*
 * daemon_util.h - minimal shared skeleton for the helper daemons (bgasched,
 * pwrmon, prevtlogd). Single-instance pidfile lock (mirrors the recovered
 * "Another <daemon> already running" guard) + SIGTERM/SIGINT stop flag.
 */
#ifndef DAEMON_UTIL_H
#define DAEMON_UTIL_H

extern volatile int daemon_stop;         /* set to 1 on SIGTERM/SIGINT        */

/* Acquire /run|/tmp/<name>.pid. Returns 0 on success, -1 if already held. */
int  daemon_pidlock(const char *name);

/* Install SIGTERM/SIGINT handlers that set daemon_stop. */
void daemon_install_signals(void);

#endif /* DAEMON_UTIL_H */
