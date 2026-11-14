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
composer_network=()
mounts=(--mount "type=bind,src=$PWD/app,dst=/var/www/html")
# Optional ephemeral state for the isolated device protocol laboratory.
if [ -n "${TEST_DEVICE_PROTOCOL_STATE:-}" ]; then
 composer_network=(--network none)
 test -d "$TEST_DEVICE_PROTOCOL_STATE"
 test -f "$TEST_DEVICE_PROTOCOL_STATE/empty.env"
 mounts+=(--mount "type=bind,src=$TEST_DEVICE_PROTOCOL_STATE/empty.env,dst=/var/www/html/.env,readonly")
 mounts+=(--mount "type=bind,src=$TEST_DEVICE_PROTOCOL_STATE,dst=/lab")
fi
if [ -n "${TEST_AGENT_PRIVATE_VOLUME:-}" ]; then mounts+=(--mount "type=volume,src=$TEST_AGENT_PRIVATE_VOLUME,dst=/lab/agent-private"); fi
if [ -f "$test_ca" ]; then mounts+=(--mount "type=bind,src=$test_ca,dst=/run/pg-tls/ca.crt,readonly"); fi
docker run --rm "${composer_network[@]}" "${mounts[@]}" "$test_image" composer install --no-interaction --prefer-dist
docker run --rm --network "$test_network" --env-file "$test_owner_env" -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php artisan migrate --force
docker exec -i "$test_pg_container" sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d agent_center_test -f /dev/stdin' < deploy/database-roles.sql
docker run --rm --network "$test_network" --env-file "$test_runtime_env" -e APP_ENV=testing -e APP_DEBUG=false -e SESSION_DRIVER=array -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php vendor/bin/phpunit --display-warnings --fail-on-warning "$@"
if [ "${TEST_DEVICE_PROTOCOL_CONCURRENCY:-0}" = 1 ]; then
 docker run --rm --network "$test_network" --env-file "$test_runtime_env" -e APP_ENV=testing -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/device-readonly-concurrency.php
fi
if [ "${TEST_ROUTEROS_PROTOCOL_CONCURRENCY:-0}" = 1 ]; then
 docker run --rm --network "$test_network" --env-file "$test_runtime_env" -e APP_ENV=testing -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/mikrotik-protocol-concurrency.php
 test -s "$TEST_DEVICE_PROTOCOL_STATE/mikrotik-concurrency-result.json"
 docker run --rm --network "$test_network" --env-file "$test_owner_env" -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/mikrotik-protocol-rollback.php
fi
if [ "${TEST_DIAGNOSTIC_CONCURRENCY:-0}" = 1 ]; then
 docker run --rm --network "$test_network" --env-file "$test_runtime_env" -e APP_ENV=testing -e APP_DEBUG=false -e SESSION_DRIVER=array -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/diagnostic-concurrency.php
fi
if [ "${TEST_CONVERSATION_IDLE_CONCURRENCY:-0}" = 1 ]; then
 docker run --rm --network "$test_network" --env-file "$test_runtime_env" -e APP_ENV=testing -e APP_DEBUG=false -e SESSION_DRIVER=array -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/conversation-idle-concurrency.php
 docker run --rm --network "$test_network" --env-file "$test_runtime_env" -e APP_ENV=testing -e APP_DEBUG=false -e SESSION_DRIVER=array -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/preflight-concurrency.php
fi
if [ "${TEST_ROUTEROS_TASK_INTEGRATION:-0}" = 1 ]; then
 # The real Agent API observes committed fixtures. Reuse the existing populated-data guards.
 docker run --rm --network "$test_network" --env-file "$test_owner_env" -e APP_ENV=testing -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/mikrotik-protocol-rollback.php
 docker run --rm --network "$test_network" --env-file "$test_owner_env" -e APP_ENV=testing -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/device-readonly-rollback.php
elif [ "${TEST_DEVICE_PROTOCOL_CONCURRENCY:-0}" = 1 ] || [ "${TEST_ROUTEROS_PROTOCOL_CONCURRENCY:-0}" = 1 ]; then
 # The contention fixture is committed. Verify refusal to destroy its data.
 docker run --rm --network "$test_network" --env-file "$test_owner_env" -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php tests/Support/device-readonly-rollback.php
else
 docker run --rm --network "$test_network" --env-file "$test_owner_env" -e DB_DATABASE=agent_center_test "${mounts[@]}" "$test_image" php artisan migrate:rollback --force
fi
