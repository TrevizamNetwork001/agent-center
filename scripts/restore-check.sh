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
  IF (SELECT count(*) FROM pg_class WHERE relname IN ('users','tenants','audit_events','agents','agent_enrollments','skills','profiles','diagnostics','diagnostic_plans','diagnostic_steps','agent_tasks','agent_task_attempts','diagnostic_conclusions') AND relrowsecurity AND relforcerowsecurity) <> 13 THEN
    RAISE EXCEPTION 'RLS de identidade não restaurado';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'audit_immutable' AND NOT tgisinternal) THEN
    RAISE EXCEPTION 'Trigger de auditoria não restaurado';
  END IF;
  IF (SELECT count(*) FROM pg_trigger WHERE tgname IN ('plans_immutable','conclusions_immutable') AND NOT tgisinternal) <> 2 THEN RAISE EXCEPTION 'Plan/conclusion immutability missing'; END IF;
END $$;
SELECT 'tenants' AS restored_table, count(*) AS rows FROM tenants
UNION ALL SELECT 'users', count(*) FROM users
UNION ALL SELECT 'audit_events', count(*) FROM audit_events
UNION ALL SELECT 'agents', count(*) FROM agents
UNION ALL SELECT 'agent_enrollments', count(*) FROM agent_enrollments
UNION ALL SELECT 'skills', count(*) FROM skills
UNION ALL SELECT 'profiles', count(*) FROM profiles
UNION ALL SELECT 'diagnostics', count(*) FROM diagnostics
UNION ALL SELECT 'diagnostic_plans', count(*) FROM diagnostic_plans
UNION ALL SELECT 'diagnostic_steps', count(*) FROM diagnostic_steps
UNION ALL SELECT 'agent_tasks', count(*) FROM agent_tasks
UNION ALL SELECT 'agent_task_attempts', count(*) FROM agent_task_attempts
UNION ALL SELECT 'diagnostic_conclusions', count(*) FROM diagnostic_conclusions;
SQL
./scripts/db-grants.sh agent_center_restore_check
./scripts/compose.sh exec -T postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d agent_center_restore_check' <<'SQL'
\getenv runtime_user RUNTIME_DB_USER
SET ROLE :"runtime_user";
SELECT current_user AS restore_runtime_role, (SELECT count(*) FROM agents) AS agents_visible_without_context;
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM diagnostics) OR EXISTS (SELECT 1 FROM agent_tasks) OR EXISTS (SELECT 1 FROM agents) THEN RAISE EXCEPTION 'RLS sem contexto falhou'; END IF;
 IF EXISTS (SELECT 1 FROM pg_tables WHERE schemaname='public' AND tableowner=current_user) THEN RAISE EXCEPTION 'Runtime possui tabelas'; END IF;
END $$;
SQL
echo 'Restore em database separado: PASS'
