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
python3 scripts/backup-external.py
