#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
[ "$#" -eq 1 ] && [ -f "$1" ] || { echo 'Uso: restore-check.sh caminho.dump' >&2; exit 1; }
# Não substitui database existente; falha antes de instalar o trap se já existir.
./scripts/compose.sh exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" -O "$APP_DB_USER" agent_center_restore_check'
cleanup() { ./scripts/compose.sh exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" agent_center_restore_check'; }
trap cleanup EXIT
./scripts/compose.sh exec -T postgres sh -c 'pg_restore --no-owner --no-privileges --exit-on-error -U "$POSTGRES_USER" --role "$APP_DB_USER" -d agent_center_restore_check' < "$1"
./scripts/compose.sh exec -T postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d agent_center_restore_check -c "SELECT count(*) AS restored_migrations FROM migrations;"'
echo 'Restore em database separado: PASS'
