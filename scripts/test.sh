#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Explicit overrides support a disposable lab without touching production databases.
test_pg_container=${TEST_PG_CONTAINER:-agent-center-postgres-1}
test_network=${TEST_NETWORK:-agent-center_backend}
test_owner_env=${TEST_OWNER_ENV:-$PWD/.env.migrator}
test_runtime_env=${TEST_RUNTIME_ENV:-$PWD/.env.runtime}
test_ca=${TEST_CA_FILE:-$PWD/secrets/postgres/ca.crt}
test_image=${TEST_IMAGE:-agent-center-tooling:foundation}
# Creation fails when the database already exists; never remove preexisting data.
docker exec "$test_pg_container" sh -c 'createdb -U "$POSTGRES_USER" -O "$APP_DB_USER" agent_center_test'
cleanup() { docker exec "$test_pg_container" sh -c 'dropdb -U "$POSTGRES_USER" agent_center_test'; }
trap cleanup EXIT
mounts=(--mount "type=bind,src=$PWD/app,dst=/var/www/html")
if [ -f "$test_ca" ]; then mounts+=(--mount "type=bind,src=$test_ca,dst=/run/pg-tls/ca.crt,readonly"); fi
docker run --rm "${mounts[@]}" "$test_image" composer install --no-interaction --prefer-dist
docker run --rm --network "$test_network" --env-file "$test_owner_env" -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php artisan migrate --force
docker exec -i "$test_pg_container" sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d agent_center_test -f /dev/stdin' < deploy/database-roles.sql
docker run --rm --network "$test_network" --env-file "$test_runtime_env" -e APP_ENV=testing -e APP_DEBUG=false -e SESSION_DRIVER=array -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php vendor/bin/phpunit --display-warnings --fail-on-warning "$@"
if [ "${TEST_DIAGNOSTIC_CONCURRENCY:-0}" = 1 ]; then
 docker run --rm --network "$test_network" --env-file "$test_runtime_env" -e APP_ENV=testing -e APP_DEBUG=false -e SESSION_DRIVER=array -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/diagnostic-concurrency.php
fi
if [ "${TEST_CONVERSATION_IDLE_CONCURRENCY:-0}" = 1 ]; then
 docker run --rm --network "$test_network" --env-file "$test_runtime_env" -e APP_ENV=testing -e APP_DEBUG=false -e SESSION_DRIVER=array -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/conversation-idle-concurrency.php
fi
docker run --rm --network "$test_network" --env-file "$test_owner_env" -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php artisan migrate:rollback --force
