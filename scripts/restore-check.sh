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
./scripts/compose.sh exec -T postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d agent_center_restore_check' <<'SQL'
DO $$
BEGIN
  IF (SELECT count(*) FROM pg_class WHERE relname IN ('users','tenants','audit_events') AND relrowsecurity AND relforcerowsecurity) <> 3 THEN
    RAISE EXCEPTION 'RLS de identidade não restaurado';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'audit_immutable' AND NOT tgisinternal) THEN
    RAISE EXCEPTION 'Trigger de auditoria não restaurado';
  END IF;
END $$;
SELECT 'tenants' AS restored_table, count(*) AS rows FROM tenants
UNION ALL SELECT 'users', count(*) FROM users
UNION ALL SELECT 'audit_events', count(*) FROM audit_events;
SQL
echo 'Restore em database separado: PASS'
