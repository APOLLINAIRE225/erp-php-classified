#!/usr/bin/env bash
set -euo pipefail

DOMAIN="esperanceh20.com"
MAIL_HOST="mail.esperanceh20.com"
USERS=(admin contact support)
PASSWORD_FILE="/root/esperanceh20_mail_users.txt"

postconf -e "myhostname = ${MAIL_HOST}"
postconf -e "mydomain = ${DOMAIN}"
postconf -e 'myorigin = $mydomain'
postconf -e "inet_interfaces = all"
postconf -e "inet_protocols = ipv4"
postconf -e 'mydestination = $myhostname, localhost.$mydomain, localhost, $mydomain'
postconf -e "home_mailbox = Maildir/"

printf '%s\n' "${DOMAIN}" > /etc/mailname

cp -n /etc/dovecot/conf.d/10-mail.conf /etc/dovecot/conf.d/10-mail.conf.bak.codex || true
sed -i 's/^mail_driver = .*/mail_driver = maildir/' /etc/dovecot/conf.d/10-mail.conf
sed -i 's|^mail_path = .*|mail_path = %{home}/Maildir|' /etc/dovecot/conf.d/10-mail.conf
sed -i 's|^mail_inbox_path = .*|mail_inbox_path = %{home}/Maildir|' /etc/dovecot/conf.d/10-mail.conf

cat > /etc/apache2/conf-available/roundcube-aliases.conf <<'EOF'
Alias /roundcube /var/lib/roundcube/public_html
Alias /webmail /var/lib/roundcube/public_html
<Directory /var/lib/roundcube/public_html/>
  Options +FollowSymLinks
  AllowOverride All
  Require all granted
</Directory>
EOF

a2enconf roundcube-aliases >/dev/null

: > "${PASSWORD_FILE}"
for user in "${USERS[@]}"; do
  if ! id -u "${user}" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" --shell /usr/sbin/nologin "${user}"
  fi
  install -d -m 700 -o "${user}" -g "${user}" \
    "/home/${user}/Maildir" \
    "/home/${user}/Maildir/cur" \
    "/home/${user}/Maildir/new" \
    "/home/${user}/Maildir/tmp"

  pass="$(openssl rand -base64 18 | tr -d '\n')"
  echo "${user}:${pass}" | chpasswd
  printf '%s %s\n' "${user}" "${pass}" >> "${PASSWORD_FILE}"
done
chmod 600 "${PASSWORD_FILE}"

service postfix restart
service dovecot restart
service apache2 restart
