#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
intent_state=${INTENT_LAB_STATE:-/root/intent-planning-private/ui}
test ! -e "$intent_state"
! docker container inspect intent-planning-v1-web >/dev/null 2>&1
umask 077
mkdir -p "$intent_state"
for kind in owner runtime; do
    sed -e 's/^DB_DATABASE=.*/DB_DATABASE=intent_planning_v1_lab/' -e 's|^APP_URL=.*|APP_URL=http://127.0.0.1:18820|' -e 's/^SESSION_SECURE_COOKIE=.*/SESSION_SECURE_COOKIE=false/' "/root/rag-v1-lab/$kind.env" > "$intent_state/$kind.env"
    echo INTENT_LAB_FIXTURE=1 >> "$intent_state/$kind.env"
done
openssl rand -hex 24 > "$intent_state/password"
docker exec rag-v1-postgres sh -c 'createdb -U "$POSTGRES_USER" -O "$APP_DB_USER" intent_planning_v1_lab'
mounts=(--mount "type=bind,src=$PWD/app,dst=/var/www/html" --mount "type=bind,src=$intent_state,dst=/lab")
docker run --rm --network rag-v1-lab --env-file "$intent_state/owner.env" "${mounts[@]}" agent-center-tooling:foundation php artisan migrate --force
docker exec -i rag-v1-postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d intent_planning_v1_lab -f /dev/stdin' < deploy/database-roles.sql
docker run --rm --network rag-v1-lab --env-file "$intent_state/runtime.env" "${mounts[@]}" agent-center-tooling:foundation php tests/Fixtures/intent-planning-seed.php
docker run -d --name intent-planning-v1-web --network rag-v1-lab -p 127.0.0.1:18820:8000 --env-file "$intent_state/runtime.env" "${mounts[@]}" agent-center-tooling:foundation php -S 0.0.0.0:8000 -t public tests/Fixtures/intent-planning-router.php
