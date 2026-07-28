#!/usr/bin/env bash
# setup-mail-relay.sh - configure a local postfix relay so emaild (which speaks
# PLAIN SMTP to 127.0.0.1:25) delivers fault alerts through an authenticated
# upstream mail server (SASL AUTH + STARTTLS). Run as root on the appliance.
#
# Provide the upstream via env vars - NEVER commit the password:
#   SMTP_HOST=mail.example.com SMTP_PORT=587 SMTP_USER=noreply@example.com \
#   SMTP_PASS='...' MAIL_FROM=noreply@example.com MAIL_TO=admin@example.com \
#   bash setup-mail-relay.sh
set -eu
: "${SMTP_HOST:?set SMTP_HOST}"; : "${SMTP_PORT:=587}"
: "${SMTP_USER:?set SMTP_USER}"; : "${SMTP_PASS:?set SMTP_PASS}"
: "${MAIL_FROM:=$SMTP_USER}"; : "${MAIL_TO:?set MAIL_TO (only this recipient gets alerts)}"
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 1; }

export DEBIAN_FRONTEND=noninteractive
echo "postfix postfix/main_mailer_type select Satellite system" | debconf-set-selections
echo "postfix postfix/relayhost string [$SMTP_HOST]:$SMTP_PORT" | debconf-set-selections
apt-get install -y -qq postfix libsasl2-modules

postconf -e "relayhost = [$SMTP_HOST]:$SMTP_PORT" \
  "smtp_sasl_auth_enable = yes" \
  "smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd" \
  "smtp_sasl_security_options = noanonymous" \
  "smtp_sasl_mechanism_filter = login, plain" \
  "smtp_tls_security_level = encrypt" \
  "smtp_tls_CApath = /etc/ssl/certs" \
  "inet_interfaces = loopback-only" "inet_protocols = ipv4"

umask 077                                   # sasl_passwd holds the secret -> root only
printf '[%s]:%s %s:%s\n' "$SMTP_HOST" "$SMTP_PORT" "$SMTP_USER" "$SMTP_PASS" > /etc/postfix/sasl_passwd
postmap /etc/postfix/sasl_passwd
chmod 0600 /etc/postfix/sasl_passwd /etc/postfix/sasl_passwd.db
systemctl restart postfix

install -d /etc/promise
sed -e "s|<sender>.*</sender>|<sender>$MAIL_FROM</sender>|" \
    -e "s|<recipient>.*</recipient>|<recipient>$MAIL_TO</recipient>|" \
    "$(dirname "$0")/emailsetting.xml.example" > /etc/promise/emailsetting.xml
chmod 0644 /etc/promise/emailsetting.xml
echo "mail relay ready (127.0.0.1:25 -> [$SMTP_HOST]:$SMTP_PORT AUTH+TLS)."
echo "test:  /opt/promise/bin/emaild --event 0x000D0002 -f /etc/promise/emailsetting.xml"
