#!/bin/bash
# Disposable LAB only. No deployment, production DB defaults or real Telegram transport.
set -euo pipefail
cd "$(dirname "$0")/.."
: "${TEST_PG_CONTAINER:?explicit laboratory PostgreSQL container required}"
: "${TEST_NETWORK:?explicit laboratory network required}"
: "${TEST_OWNER_ENV:?explicit laboratory migrator env file required}"
: "${TEST_RUNTIME_ENV:?explicit laboratory runtime env file required}"
: "${TELEGRAM_LAB_DIR:?private absolute laboratory artifact directory required}"
[[ "$TELEGRAM_LAB_DIR" = /* && "$TEST_PG_CONTAINER" != agent-center-postgres-1 ]] || exit 1
umask 077
mkdir -p "$TELEGRAM_LAB_DIR/evidence/browser"
chmod 700 "$TELEGRAM_LAB_DIR"
image=${TEST_IMAGE:-agent-center-tooling:foundation}
args=(--network "$TEST_NETWORK" --env-file "$TEST_RUNTIME_ENV" -e APP_ENV=local -e APP_DEBUG=false -e DB_DATABASE=agent_center_telegram_lab -e TELEGRAM_ENABLED=true -e TELEGRAM_LAB_FIXTURE=1 -e QUEUE_CONNECTION=database -e APP_URL=https://telegram-v1-web:8000 -e SESSION_SECURE_COOKIE=false -e LLM_MASTER_KEY_FILE=/lab/master-key -v "$PWD/app:/var/www/html" -v "$TELEGRAM_LAB_DIR:/lab")
case "${1:-}" in
 start)
  # Fails closed if this database already exists; never drop preexisting data.
  docker exec "$TEST_PG_CONTAINER" sh -c 'createdb -U "$POSTGRES_USER" -O "$APP_DB_USER" agent_center_telegram_lab'
  openssl rand -hex 24 > "$TELEGRAM_LAB_DIR/password"
  openssl rand -base64 32 > "$TELEGRAM_LAB_DIR/master-key"
  docker run --rm --network "$TEST_NETWORK" --env-file "$TEST_OWNER_ENV" -e DB_DATABASE=agent_center_telegram_lab -v "$PWD/app:/var/www/html" "$image" php artisan migrate --force
  docker exec -i "$TEST_PG_CONTAINER" sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d agent_center_telegram_lab -f /dev/stdin' < deploy/database-roles.sql
  docker run --rm "${args[@]}" "$image" php tests/Fixtures/telegram-seed.php
  docker run -d --name telegram-v1-web "${args[@]}" "$image" php -S 0.0.0.0:8000 -t public tests/Fixtures/telegram-router.php
  ;;
 worker) docker run --rm "${args[@]}" "$image" php tests/Fixtures/telegram-worker.php ;;
 browser)
  docker run --rm --network "$TEST_NETWORK" -e PHASE="${2:?link, approve, screenshots or revoke}" -v "$TELEGRAM_LAB_DIR:/lab" -v "$TELEGRAM_LAB_DIR/evidence/browser:/evidence" -v "$PWD/app/tests/Browser/telegram.cjs:/validation/browser.cjs:ro" agent-center-browser:recovery4 node /validation/browser.cjs
  ;;
 *) echo 'Use start, worker or browser PHASE; explicit LAB env and artifact directory required.' >&2; exit 1 ;;
esac
