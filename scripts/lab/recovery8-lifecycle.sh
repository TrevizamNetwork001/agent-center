#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
[ "${COMPOSE_PROJECT_NAME:-}" = agent-center-recovery8 ] || { echo 'Isolated project required'; exit 1; }
export COMPOSE_ENV_FILE=${COMPOSE_ENV_FILE:-secrets/recovery8-lab/compose.env}
./scripts/compose.sh exec -T postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' <<'SQL'
CREATE TABLE recovery8_volume_probe(id integer PRIMARY KEY, marker text NOT NULL);
INSERT INTO recovery8_volume_probe VALUES(1,'RECOVERY8-PERSISTENCE');
SQL
for service in web worker postgres; do
  ./scripts/compose.sh up -d --no-deps --force-recreate --wait "$service"
  ./scripts/verify-pg-tls.sh
done
./scripts/compose.sh stop
./scripts/compose.sh up -d --wait
./scripts/compose.sh restart web worker diagnostic-worker postgres
./scripts/compose.sh up -d --wait
./scripts/verify-pg-tls.sh
./scripts/compose.sh exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc "SELECT marker FROM recovery8_volume_probe WHERE id=1"' | grep -qx RECOVERY8-PERSISTENCE
# Partial failure, then recovery; no production workload is stopped.
./scripts/compose.sh stop worker
./scripts/compose.sh up -d --wait worker
./scripts/queue-smoke.sh
./scripts/deploy-precheck.sh
./scripts/compose.sh exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "DROP TABLE recovery8_volume_probe"'
echo 'Startup/stop/restart/recreate/volume/TLS/partial failure recovery: PASS'
