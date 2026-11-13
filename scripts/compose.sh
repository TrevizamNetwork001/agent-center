#!/bin/sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
exec docker compose --env-file .env -f deploy/compose.yaml -f deploy/compose.device-readonly-admin.yaml "$@"
