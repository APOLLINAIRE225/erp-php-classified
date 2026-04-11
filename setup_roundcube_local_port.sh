#!/usr/bin/env bash
set -euo pipefail

PORT="8081"

cp -n /etc/apache2/ports.conf /etc/apache2/ports.conf.bak.codex || true
sed -i 's/^Listen 80$/# Listen 80/' /etc/apache2/ports.conf
sed -i 's/^[[:space:]]*Listen 443$/# Listen 443/' /etc/apache2/ports.conf
sed -i '/^Listen 8081$/d' /etc/apache2/ports.conf
sed -i '/^Listen 127\.0\.0\.1:8081$/d' /etc/apache2/ports.conf
if ! grep -q "^Listen 127.0.0.1:${PORT}$" /etc/apache2/ports.conf; then
  printf '\nListen 127.0.0.1:%s\n' "${PORT}" >> /etc/apache2/ports.conf
fi

cat > /etc/apache2/sites-available/roundcube-local.conf <<EOF
<VirtualHost 127.0.0.1:${PORT}>
    ServerName localhost
    DocumentRoot /var/lib/roundcube/public_html

    Alias /roundcube /var/lib/roundcube/public_html
    Alias /webmail /var/lib/roundcube/public_html

    <Directory /var/lib/roundcube/public_html/>
        Options +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/roundcube-local-error.log
    CustomLog \${APACHE_LOG_DIR}/roundcube-local-access.log combined
</VirtualHost>
EOF

a2dissite 000-default >/dev/null || true
a2ensite roundcube-local >/dev/null

apache2ctl configtest

if pgrep -x apache2 >/dev/null 2>&1; then
  service apache2 reload
else
  apache2ctl start
fi
