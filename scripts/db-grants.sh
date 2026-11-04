#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
# Database é argumento separado e validado; credenciais vêm só do ambiente do PostgreSQL.
database=${1:-agent_center}
case "$database" in agent_center|agent_center_test|agent_center_lab|agent_center_restore_check) ;; *) echo 'Database não permitido' >&2; exit 1;; esac
./scripts/compose.sh exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$1" -f /dev/stdin' -- "$database" < deploy/database-roles.sql
