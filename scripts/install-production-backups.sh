#!/bin/bash
# Fixed Agent Center operational tooling. Does not promote application/schema.
set -euo pipefail
umask 077
source_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd /opt/agent-center
for file in backup.sh backup-external.py backup-run.sh collect-operations.py create-migrator-env.py export-backup.sh; do
  install -m 750 "$source_root/scripts/$file" "scripts/$file"
done
for file in agent-center-backup.service agent-center-backup.timer agent-center-backup-alert.service agent-center-operations.service agent-center-operations.timer; do
  install -m 644 "$source_root/deploy/$file" "/etc/systemd/system/$file"
done
# Only fixed status/duration fields; no IPs, paths, query strings, cookies or headers.
cp /etc/nginx/sites-available/agent-center.conf backups/recovery8-before-observability.conf
install -m 644 "$source_root/deploy/nginx/host/agent-center-observability.conf" /etc/nginx/conf.d/agent-center-observability.conf
python3 - <<'PY'
from pathlib import Path
p=Path('/etc/nginx/sites-available/agent-center.conf')
s=p.read_text()
s=s.replace('    server_tokens off;\n    access_log off;', '    server_tokens off;\n    access_log /var/log/nginx/agent-center-status.log agent_center_status_only;')
p.write_text(s)
PY
if ! nginx -t; then
  cp backups/recovery8-before-observability.conf /etc/nginx/sites-available/agent-center.conf
  exit 1
fi
systemctl reload nginx
systemctl daemon-reload
systemd-analyze verify /etc/systemd/system/agent-center-backup.service /etc/systemd/system/agent-center-backup.timer /etc/systemd/system/agent-center-backup-alert.service
systemctl enable --now agent-center-backup.timer agent-center-operations.timer
systemctl start agent-center-backup.service || true
systemctl start agent-center-operations.service
systemctl show agent-center-backup.timer agent-center-operations.timer -p Id -p ActiveState -p NextElapseUSecRealtime
systemctl show agent-center-backup.service -p Result -p ExecMainStatus
