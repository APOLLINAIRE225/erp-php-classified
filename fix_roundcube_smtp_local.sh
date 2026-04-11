#!/usr/bin/env bash
set -euo pipefail

CONFIG="/etc/roundcube/config.inc.php"

cp -n "${CONFIG}" "${CONFIG}.bak.codex" || true
sed -i "s/\$config\['smtp_host'\] = 'localhost:587';/\$config['smtp_host'] = '127.0.0.1:25';/" "${CONFIG}"
sed -i "s/\$config\['smtp_user'\] = '%u';/\$config['smtp_user'] = '';/" "${CONFIG}"
sed -i "s/\$config\['smtp_pass'\] = '%p';/\$config['smtp_pass'] = '';/" "${CONFIG}"

apache2ctl configtest
service apache2 reload
