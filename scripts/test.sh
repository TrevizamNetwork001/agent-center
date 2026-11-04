#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
role_dir=$(mktemp -d)
ROLE_ENV_DIR="$role_dir" python3 scripts/create-migrator-env.py
network="${COMPOSE_PROJECT_NAME:-agent-center}_backend"
tls_dir=$(python3 - <<'TLS'
import os
from pathlib import Path
v=dict(line.split('=',1) for line in Path(os.environ.get('COMPOSE_ENV_FILE','.env')).read_text().splitlines() if '=' in line and not line.startswith('#'))
print(Path(v.get('PG_TLS_DIR','secrets/postgres')).resolve())
TLS
)
tls_args=()
if [ -f "$tls_dir/ca.crt" ]; then
  tls_args=(--mount "type=bind,src=$tls_dir/ca.crt,dst=/run/pg-tls/ca.crt,readonly" -e DB_SSLMODE=verify-full -e DB_SSLROOTCERT=/run/pg-tls/ca.crt)
fi
# Criação deve falhar se o database já existir; nunca destruir banco preexistente.
./scripts/compose.sh exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" -O "$APP_DB_USER" agent_center_test'
cleanup() { rm -rf "$role_dir"; ./scripts/compose.sh exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" agent_center_test'; }
trap cleanup EXIT
docker build --target tooling -t agent-center-tooling:foundation -f deploy/php/Dockerfile . >/dev/null
docker run --rm --mount type=bind,src="$PWD/app",dst=/var/www/html agent-center-tooling:foundation composer install --no-interaction --prefer-dist
test_php() {
  docker run --rm --network "$network" --env-file "$role_dir/.env.runtime" "${tls_args[@]}" -e APP_ENV=testing -e APP_DEBUG=false -e SESSION_DRIVER=array -e DB_DATABASE=agent_center_test --mount type=bind,src="$PWD/app",dst=/var/www/html -e LARAVEL_STORAGE_PATH=/tmp/agent-center-testing agent-center-tooling:foundation sh -c 'mkdir -p /tmp/agent-center-testing/framework/cache /tmp/agent-center-testing/framework/sessions /tmp/agent-center-testing/framework/views /tmp/agent-center-testing/logs; exec php "$@"' -- "$@"
}
docker run --rm --network "$network" --env-file "$role_dir/.env.migrator" "${tls_args[@]}" -e DB_DATABASE=agent_center_test --mount type=bind,src="$PWD/app",dst=/var/www/html agent-center-tooling:foundation php artisan migrate --force
./scripts/db-grants.sh agent_center_test
test_php vendor/bin/phpunit --display-warnings --fail-on-warning "$@"
docker run --rm --network "$network" --env-file "$role_dir/.env.migrator" "${tls_args[@]}" -e DB_DATABASE=agent_center_test --mount type=bind,src="$PWD/app",dst=/var/www/html agent-center-tooling:foundation php artisan migrate:rollback --force
