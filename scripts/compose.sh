#!/bin/sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
exec docker compose --env-file "${COMPOSE_ENV_FILE:-.env}" -f deploy/compose.yaml -f deploy/compose.device-readonly-admin.yaml -f deploy/compose.panel-updater.yaml -f deploy/compose.telegram-repair.yaml "$@"
