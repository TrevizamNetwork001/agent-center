#!/bin/bash
# Install only reviewed backup tooling in the fixed production project, preserving secrets.
set -euo pipefail
umask 077
source_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
[ "$source_root" != /opt/agent-center ] || { echo 'Run from the reviewed source checkout'; exit 1; }
[ -f "$source_root/tools/backup-tools.json" ] || { echo 'Bootstrap project-local tools first'; exit 1; }
cd /opt/agent-center
for file in backup.sh backup-run.sh backup_r2.py backup-alert.py configure-r2-backup.py download_r2.py install-backup-tools.py r2_native_transport.py backup_ui.py backup-ui-request.sh; do
  install -m 750 "$source_root/scripts/$file" "scripts/$file"
done
for file in backup-requirements.lock r2-retention-plan.json backup-alert.example.json; do
  install -m 644 "$source_root/deploy/$file" "deploy/$file"
done
mkdir -p tools/usr/bin
chmod 700 tools
install -m 750 "$source_root/tools/usr/bin/age" tools/usr/bin/age
cp -R "$source_root/tools/backup-python" tools/
install -m 600 "$source_root/tools/backup-tools.json" tools/backup-tools.json
for file in agent-center-backup-alert.service agent-center-backup-watch.service agent-center-backup-watch.timer agent-center-backup-ui.service agent-center-backup-ui.timer; do
  install -m 644 "$source_root/deploy/$file" "deploy/$file"
  install -m 644 "$source_root/deploy/$file" "/etc/systemd/system/$file"
done
# Nothing writes r2-backup.json, r2-credentials.json or recipient material here.
# The operator supplies them through the interactive secure configuration tool.
systemd-analyze verify /etc/systemd/system/agent-center-backup-alert.service /etc/systemd/system/agent-center-backup-watch.service /etc/systemd/system/agent-center-backup-watch.timer
systemctl daemon-reload
systemctl enable --now agent-center-backup-watch.timer agent-center-backup-ui.timer
systemctl start agent-center-backup-watch.service
printf 'R2 backup tooling installed; existing application/schema/Agent/secrets preserved.\n'
