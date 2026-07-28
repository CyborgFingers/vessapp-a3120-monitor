/* smtp.c - see smtp.h. Blocking, single message, explicit error returns. */
#include "smtp.h"

#include <stdio.h>
#include <string.h>
#include <unistd.h>
#include <netdb.h>
#include <sys/socket.h>
#include <time.h>

/* Read one reply and check its 3-digit code. SMTP multiline replies use
 * "250-..." continuations then "250 ..."; consume until the space form. */
static int expect(int fd, const char *code) {
    char buf[512];
    for (;;) {
        int n = 0; char c;
        while (n < (int)sizeof buf - 1) {          /* read one CRLF line */
            int r = read(fd, &c, 1);
            if (r <= 0) return -1;
            buf[n++] = c;
            if (c == '\n') break;
        }
        buf[n] = '\0';
        if (strncmp(buf, code, 3) != 0) return -1; /* wrong class -> fail */
        if (n >= 4 && buf[3] == ' ') return 0;     /* final line          */
        /* else continuation: keep reading */
    }
}

static int sendline(int fd, const char *s) {
    size_t len = strlen(s);
    return write(fd, s, len) == (ssize_t)len ? 0 : -1;
}

static int cmd(int fd, const char *line, const char *code) {
    if (sendline(fd, line) != 0) return -1;
    if (sendline(fd, "\r\n") != 0) return -1;
    return expect(fd, code);
}

int smtp_send(const char *host, int port, const char *from,
              const char **rcpt, int nrcpt,
              const char *subject, const char *body) {
    char portstr[8]; snprintf(portstr, sizeof portstr, "%d", port);
    struct addrinfo hints, *res = NULL;
    memset(&hints, 0, sizeof hints);
    hints.ai_family = AF_UNSPEC; hints.ai_socktype = SOCK_STREAM;
    if (getaddrinfo(host, portstr, &hints, &res) != 0 || !res) return -1;

    int fd = socket(res->ai_family, res->ai_socktype, res->ai_protocol);
    if (fd < 0) { freeaddrinfo(res); return -1; }
    int rc = connect(fd, res->ai_addr, res->ai_addrlen);
    freeaddrinfo(res);
    if (rc != 0) { close(fd); return -1; }

    char line[1400];
    int ok = expect(fd, "220") == 0
          && cmd(fd, "HELO vessapp.local", "250") == 0;

    if (ok) { snprintf(line, sizeof line, "MAIL FROM:<%s>", from); ok = cmd(fd, line, "250") == 0; }
    for (int i = 0; ok && i < nrcpt; i++) {
        snprintf(line, sizeof line, "RCPT TO:<%s>", rcpt[i]);
        ok = cmd(fd, line, "250") == 0;
    }
    if (ok) ok = cmd(fd, "DATA", "354") == 0;

    if (ok) {
        char date[64]; time_t t = time(NULL); struct tm tm; gmtime_r(&t, &tm);
        strftime(date, sizeof date, "%a, %d %b %Y %H:%M:%S +0000", &tm);
        char rcptline[512] = "";
        for (int i = 0; i < nrcpt; i++) {
            strncat(rcptline, rcpt[i], sizeof rcptline - strlen(rcptline) - 2);
            if (i + 1 < nrcpt) strncat(rcptline, ", ", sizeof rcptline - strlen(rcptline) - 1);
        }
        snprintf(line, sizeof line,
            "From: %s\r\nTo: %s\r\nSubject: %s\r\nDate: %s\r\n"
            "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\n",
            from, rcptline, subject, date);
        ok = sendline(fd, line) == 0 && sendline(fd, body) == 0;
        /* body ends with CRLF; terminate DATA with ".\r\n" -> <CRLF>.<CRLF> */
        if (ok) ok = cmd(fd, ".", "250") == 0;
    }
    cmd(fd, "QUIT", "221");     /* best-effort */
    close(fd);
    return ok ? 0 : -1;
}
