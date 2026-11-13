#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
# This runner creates ONLY named disposable resources, never uses production env files.
lab_state=${DEVICE_READONLY_STATE:-/tmp/agent-center-device-readonly-v1}
lab_net=device-readonly-v1-isolated
lab_ssh=device-readonly-v1-ssh
lab_pg=device-readonly-v1-postgres
lab_image=agent-center-device-readonly-lab:local
test ! -e "$lab_state"
for name in "$lab_ssh" "$lab_pg"; do
    if docker container inspect "$name" >/dev/null 2>&1; then echo "Lab resource already exists: $name"; exit 1; fi
done
if docker network inspect "$lab_net" >/dev/null 2>&1; then echo 'Lab network already exists'; exit 1; fi
umask 077
mkdir -p "$lab_state/tls"
: > "$lab_state/empty.env"
python3 - "$lab_state" <<'PY'
import base64
import secrets
import sys
from pathlib import Path
p = Path(sys.argv[1])
owner, runtime, root = (secrets.token_hex(32) for _ in range(3))
key = base64.b64encode(secrets.token_bytes(32)).decode()
def write_env(name, values):
    (p/name).write_text('\n'.join(f'{key}={value}' for key, value in values.items())+'\n')
write_env('postgres.env', {'POSTGRES_DB': 'lab_bootstrap', 'POSTGRES_USER': 'lab_root',
    'POSTGRES_PASSWORD': root, 'APP_DB_USER': 'lab_owner', 'APP_DB_PASSWORD': owner,
    'RUNTIME_DB_USER': 'lab_runtime', 'RUNTIME_DB_PASSWORD': runtime})
for name, user, password in [('owner','lab_owner',owner), ('runtime','lab_runtime',runtime)]:
    write_env(name+'.env', {'APP_ENV': 'testing', 'APP_KEY': 'base64:'+key, 'APP_DEBUG': 'false',
        'DB_CONNECTION': 'pgsql', 'DB_HOST': 'device-readonly-v1-postgres', 'DB_DATABASE': 'agent_center_test',
        'DB_PORT': 5432, 'DB_USERNAME': user, 'DB_PASSWORD': password, 'DB_SSLMODE': 'verify-full',
        'DB_SSLROOTCERT': '/run/pg-tls/ca.crt', 'SESSION_DRIVER': 'array', 'SESSION_ENCRYPT': 'true',
        'SESSION_SECURE_COOKIE': 'true', 'CACHE_STORE': 'array', 'QUEUE_CONNECTION': 'sync',
        'DEVICE_PROTOCOL_LAB': 1, 'LLM_MASTER_KEY_FILE': '/lab/vault'})
PY
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$lab_state/tls/server.key" -out "$lab_state/tls/ca.crt" -days 1 -subj /CN=device-readonly-v1-postgres -addext subjectAltName=DNS:device-readonly-v1-postgres >/dev/null 2>&1
chmod 600 "$lab_state/tls/server.key"
chmod 644 "$lab_state/tls/ca.crt"
# TLS certificate public directory; private state parent remains mode 0700.
chmod 755 "$lab_state/tls"
DOCKER_CONFIG="$lab_state/docker-config" docker build -f scripts/lab/device-readonly/Dockerfile -t "$lab_image" scripts/lab/device-readonly
docker network create --internal "$lab_net" >/dev/null
cleanup() {
    docker rm -fv "$lab_ssh" "$lab_pg" >/dev/null 2>&1 || true
    docker network rm "$lab_net" >/dev/null 2>&1 || true
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
docker run -d --name "$lab_pg" --network "$lab_net" --env-file "$lab_state/postgres.env" \
    --mount "type=bind,src=$lab_state/tls,dst=/tls,readonly" \
    --mount "type=bind,src=$PWD/scripts/lab/device-readonly/postgres-init.sh,dst=/docker-entrypoint-initdb.d/10-app.sh,readonly" \
    --mount "type=bind,src=$PWD/deploy/database-roles.sql,dst=/opt/agent-center/database-roles.sql,readonly" \
    --entrypoint sh postgres:17-alpine -c 'cp /tls/server.key /tmp/lab-server.key && chown postgres:postgres /tmp/lab-server.key && chmod 600 /tmp/lab-server.key && exec docker-entrypoint.sh postgres -c timezone=Europe/Berlin -c ssl=on -c ssl_cert_file=/tls/ca.crt -c ssl_key_file=/tmp/lab-server.key' >/dev/null
docker run -d --name "$lab_ssh" --network "$lab_net" --cap-drop ALL --security-opt no-new-privileges \
    --mount "type=bind,src=$lab_state,dst=/lab" \
    --mount "type=bind,src=$PWD/app/resources/device-readonly-cli-fixtures.json,dst=/fixtures.json,readonly" \
    --mount "type=bind,src=$PWD/scripts/lab/device-readonly,dst=/protocol,readonly" "$lab_image" >/dev/null
for attempt in {1..30}; do
    if [ -f "$lab_state/ready" ] && docker exec "$lab_pg" pg_isready -h 127.0.0.1 -U lab_root -d lab_bootstrap >/dev/null; then break; fi
    sleep 1
done
test -f "$lab_state/ready"
docker network inspect "$lab_net" --format '{{.Internal}}' | rg -x true
docker exec "$lab_ssh" python3 /protocol/check-boundaries.py
TEST_PG_CONTAINER="$lab_pg" TEST_NETWORK="container:$lab_ssh" TEST_OWNER_ENV="$lab_state/owner.env" TEST_RUNTIME_ENV="$lab_state/runtime.env" TEST_CA_FILE="$lab_state/tls/ca.crt" \
TEST_IMAGE="$lab_image" TEST_DEVICE_PROTOCOL_STATE="$lab_state" \
    ./scripts/test.sh "$@"
