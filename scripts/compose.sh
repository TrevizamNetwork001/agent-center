#!/bin/sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
exec docker compose --env-file "${COMPOSE_ENV_FILE:-.env}" -f deploy/compose.yaml "$@"
