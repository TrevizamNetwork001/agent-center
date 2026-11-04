#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
./scripts/compose.sh exec -T postgres sh -eu -c '
export PGPASSWORD="$POSTGRES_PASSWORD" PGSSLMODE=verify-full PGSSLROOTCERT=/run/pg-tls/ca.crt
psql -h postgres -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc "SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()" | grep -qx t
# hostaddr preserves the transport endpoint while checking a deliberately invalid hostname.
if psql "host=wrong.invalid hostaddr=127.0.0.1 user=$POSTGRES_USER dbname=$POSTGRES_DB" -c "SELECT 1" >/dev/null 2>&1; then exit 1; fi
if PGSSLROOTCERT=/run/pg-tls/server.crt psql -h postgres -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT 1" >/dev/null 2>&1; then exit 1; fi
if PGSSLMODE=disable psql -h postgres -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT 1" >/dev/null 2>&1; then exit 1; fi
psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc "SELECT 1" | grep -qx 1
'
echo 'TLS positive, wrong hostname, wrong CA, plaintext rejection and local socket: PASS'
