#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Closures serializadas exigem arquivo real de origem, não stdin.
./scripts/compose.sh cp scripts/queue-smoke.php web:/var/www/html/storage/framework/agent-center-queue-smoke.php
trap './scripts/compose.sh exec -T web rm -f /var/www/html/storage/framework/agent-center-queue-smoke.php' EXIT
./scripts/compose.sh exec -T web php /var/www/html/storage/framework/agent-center-queue-smoke.php
