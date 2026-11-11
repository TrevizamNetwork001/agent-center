#!/bin/bash
# Disposable lab only. Never reads production env, keys, data or sessions.
set -euo pipefail
cd "$(dirname "$0")/.."
rag_repo=$PWD
rag_state=${RAG_LAB_STATE:-/root/rag-v1-repro-lab}
if [ -e "$rag_state" ] || docker container inspect rag-v1-postgres >/dev/null 2>&1 || docker container inspect rag-v1-web >/dev/null 2>&1 || docker network inspect rag-v1-lab >/dev/null 2>&1; then
 echo 'Refusing to overwrite an existing lab. Preserve its data and use an empty host/workspace.' >&2; exit 1
fi
umask 077
mkdir -p "$rag_state"
openssl rand -hex 24 > "$rag_state/password"
openssl rand -base64 32 > "$rag_state/master-key"
{
 echo POSTGRES_USER=rag_owner; echo POSTGRES_DB=rag_lab; echo APP_DB_USER=rag_owner
 printf POSTGRES_PASSWORD=; cat "$rag_state/password"
 echo RUNTIME_DB_USER=rag_runtime; printf RUNTIME_DB_PASSWORD=; cat "$rag_state/password"
} > "$rag_state/postgres.env"
{
 echo APP_ENV=local; echo APP_DEBUG=false; echo TZ=Europe/Berlin
 printf APP_KEY=base64:; openssl rand -base64 32
 echo APP_URL=http://127.0.0.1:18765
 echo DB_CONNECTION=pgsql; echo DB_HOST=rag-v1-postgres; echo DB_PORT=5432
 echo DB_DATABASE=rag_lab; echo DB_USERNAME=rag_owner
 printf DB_PASSWORD=; cat "$rag_state/password"
 echo DB_SSLMODE=disable; echo CACHE_STORE=database; echo SESSION_DRIVER=file
 echo SESSION_ENCRYPT=true; echo QUEUE_CONNECTION=sync
 echo LLM_MASTER_KEY_FILE=/lab/master-key; echo LLM_ALLOWED_HOSTS=provider.example.test
 echo RAG_LAB_FIXTURE=1
} > "$rag_state/owner.env"
sed s/DB_USERNAME=rag_owner/DB_USERNAME=rag_runtime/ "$rag_state/owner.env" > "$rag_state/runtime.env"
docker network create rag-v1-lab >/dev/null
docker run -d --name rag-v1-postgres --network rag-v1-lab --env-file "$rag_state/postgres.env" postgres:17-alpine -c timezone=Europe/Berlin >/dev/null
for attempt in $(seq 1 30); do
 if docker exec rag-v1-postgres pg_isready -U rag_owner >/dev/null 2>&1; then break; fi
 sleep 1
done
mkdir -p app/storage/framework/{cache/data,sessions,views} app/storage/logs app/bootstrap/cache
rag_mount=(--mount "type=bind,src=$rag_repo/app,dst=/var/www/html" --mount "type=bind,src=$rag_state,dst=/lab")
docker run --rm "${rag_mount[@]}" agent-center-tooling:foundation composer install --no-interaction --prefer-dist
docker run --rm --mount "type=bind,src=$rag_repo/app,dst=/frontend" -w /frontend node:24-bookworm-slim sh -c 'npm ci --no-fund --no-audit && npm run build'
docker run --rm --network rag-v1-lab --env-file "$rag_state/owner.env" "${rag_mount[@]}" agent-center-tooling:foundation php artisan migrate --force
docker exec -i rag-v1-postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d rag_lab -f /dev/stdin' < deploy/database-roles.sql
docker run --rm --network rag-v1-lab --env-file "$rag_state/runtime.env" "${rag_mount[@]}" agent-center-tooling:foundation php tests/Fixtures/rag-lab-seed.php
docker run -d --name rag-v1-web --network rag-v1-lab -p 127.0.0.1:18765:8000 --env-file "$rag_state/runtime.env" "${rag_mount[@]}" agent-center-tooling:foundation php -S 0.0.0.0:8000 -t public tests/Fixtures/rag-lab-router.php >/dev/null
mkdir -p docs/evidence/rag-v1
docker run --rm --network rag-v1-lab --mount "type=bind,src=$rag_state,dst=/lab,readonly" --mount "type=bind,src=$rag_repo/docs/evidence/rag-v1,dst=/evidence" --mount "type=bind,src=$rag_repo/app,dst=/var/www/html,readonly" agent-center-browser:recovery4 node /var/www/html/tests/Browser/rag-v1.cjs
# Leave data intact for review. Stop only these two lab containers when finished.
echo 'RAG lab is local at 127.0.0.1:18765. Credentials are only in the restricted lab state directory.'
