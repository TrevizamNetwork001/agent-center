#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Criação deve falhar se o database já existir; nunca destruir banco preexistente.
./scripts/compose.sh exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" -O "$APP_DB_USER" agent_center_test'
cleanup() { ./scripts/compose.sh exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" agent_center_test'; }
trap cleanup EXIT
docker build --target tooling -t agent-center-tooling:foundation -f deploy/php/Dockerfile . >/dev/null
docker run --rm --mount type=bind,src="$PWD/app",dst=/var/www/html agent-center-tooling:foundation composer install --no-interaction --prefer-dist
test_php() {
  docker run --rm --network agent-center_backend --env-file .env -e APP_ENV=testing -e APP_DEBUG=false -e SESSION_DRIVER=array -e DB_DATABASE=agent_center_test --mount type=bind,src="$PWD/app",dst=/var/www/html --mount type=bind,src="$PWD/.env",dst=/var/www/html/.env,readonly agent-center-tooling:foundation php "$@"
}
test_php artisan migrate --force
test_php vendor/bin/phpunit --display-warnings --fail-on-warning
test_php artisan migrate:rollback --force
