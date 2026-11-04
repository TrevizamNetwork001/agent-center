#!/bin/bash
# Fixed request -> fixed service mapping; no user command, host, path or arguments.
set -euo pipefail
if systemctl is-active --quiet agent-center-backup.service; then exit 0; fi
set +e
docker exec --user 0:33 agent-center-web-1 php artisan agent-center:consume-backup-request >/dev/null 2>&1
request_result=$?
set -e
case "$request_result" in
  0) systemctl start --no-block agent-center-backup.service;;
  2) exit 0;;
  *) echo 'Backup request consumer unavailable'; exit 1;;
esac
