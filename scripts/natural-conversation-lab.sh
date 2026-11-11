#!/bin/bash
# Synthetic fixtures only; no production env, credentials, device or live API calls.
set -euo pipefail
cd "$(dirname "$0")/.."
natural_repo=$PWD
natural_state=${NATURAL_LAB_STATE:-/root/natural-conversation-state}
natural_source=${NATURAL_LAB_SOURCE:-/root/rag-v1-lab}
if [ -e "$natural_state" ] || docker container inspect natural-conversation-web >/dev/null 2>&1; then
 echo 'Refusing to overwrite an existing lab. Keep its evidence and choose an unused environment.' >&2
 exit 1
fi
docker container inspect rag-v1-postgres >/dev/null
docker network inspect rag-v1-lab >/dev/null
umask 077
mkdir -p "$natural_state"
for natural_kind in owner runtime; do
 sed -e 's/^DB_DATABASE=.*/DB_DATABASE=natural_conversation_lab/' -e 's|^APP_URL=.*|APP_URL=http://127.0.0.1:18766|' "$natural_source/$natural_kind.env" > "$natural_state/$natural_kind.env"
 echo NATURAL_LAB_FIXTURE=1 >> "$natural_state/$natural_kind.env"
done
openssl rand -hex 24 > "$natural_state/password"
openssl rand -base64 32 > "$natural_state/master-key"
docker exec rag-v1-postgres sh -c 'createdb -U "$POSTGRES_USER" -O "$APP_DB_USER" natural_conversation_lab'
natural_mount=(--mount "type=bind,src=$natural_repo/app,dst=/var/www/html" --mount "type=bind,src=$natural_state,dst=/lab")
mkdir -p app/storage/framework/{cache/data,sessions,views} app/storage/logs app/bootstrap/cache
docker run --rm "${natural_mount[@]}" agent-center-tooling:foundation composer install --no-interaction --prefer-dist
docker run --rm --mount "type=bind,src=$natural_repo/app,dst=/frontend" -w /frontend node:24-bookworm-slim sh -c 'npm ci --no-fund --no-audit && npm run build'
docker run --rm --network rag-v1-lab --env-file "$natural_state/owner.env" "${natural_mount[@]}" agent-center-tooling:foundation php artisan migrate --force
docker exec -i rag-v1-postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d natural_conversation_lab -f /dev/stdin' < deploy/database-roles.sql
docker run --rm --network rag-v1-lab --env-file "$natural_state/runtime.env" "${natural_mount[@]}" agent-center-tooling:foundation php tests/Fixtures/natural-lab-seed.php
docker run -d --name natural-conversation-web --network rag-v1-lab -p 127.0.0.1:18766:8000 --env-file "$natural_state/runtime.env" "${natural_mount[@]}" agent-center-tooling:foundation php -S 0.0.0.0:8000 -t public tests/Fixtures/natural-lab-router.php
mkdir -p docs/evidence/natural-conversation-20261111/browser
natural_browser=(--rm --network rag-v1-lab --mount "type=bind,src=$natural_state,dst=/lab,readonly" --mount "type=bind,src=$natural_repo/docs/evidence/natural-conversation-20261111/browser,dst=/evidence" --mount "type=bind,src=$natural_repo/app,dst=/var/www/html,readonly")
docker run "${natural_browser[@]}" agent-center-browser:recovery4 node /var/www/html/tests/Browser/natural-conversation.cjs
docker run --rm --network rag-v1-lab --env-file "$natural_state/runtime.env" "${natural_mount[@]}" agent-center-tooling:foundation php tests/Support/natural-lab-results.php > docs/evidence/natural-conversation-20261111/lab-transcripts.json
docker run "${natural_browser[@]}" -e NATURAL_CAPTURE_ONLY=1 agent-center-browser:recovery4 node /var/www/html/tests/Browser/natural-conversation.cjs
echo 'Natural Conversation LAB: http://127.0.0.1:18766; credentials remain in the private lab directory. No production rollout.'
