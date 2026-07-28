/*
 * smtp.h - minimal SMTP client (no AUTH, no TLS) for emaild.
 *
 * The Windows emaild shelled to mail-go.exe; on Linux we speak SMTP directly to
 * the configured relay. AUTH/STARTTLS are the on-device hardening path (the
 * emailsetting.xml <auth> field) and are CALIBRATE - not implemented here.
 */
#ifndef SMTP_H
#define SMTP_H

/* Send one message. Returns 0 on success, -1 on socket/protocol error. */
int smtp_send(const char *host, int port, const char *from,
              const char **rcpt, int nrcpt,
              const char *subject, const char *body);

#endif /* SMTP_H */
