#!/bin/bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
# Service journal receives fixed status only; restricted log captures local tool diagnostics.
mkdir -p backups
chmod 700 backups
if ! ./scripts/backup.sh > backups/last-run.log 2>&1; then
  echo 'Agent Center local backup FAILED'
  exit 1
fi
# UI settings are authoritative when present. No Docker socket or root secrets are exposed to web.
set +e
python3 scripts/backup_ui.py
ui_result=$?
set -e
if [ "$ui_result" -ne 4 ]; then exit "$ui_result"; fi
if [ -f secrets/r2-backup.json ]; then
  exec python3 scripts/backup_r2.py
fi
exec python3 scripts/backup-external.py
