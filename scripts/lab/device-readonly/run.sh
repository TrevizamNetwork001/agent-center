#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
# This runner creates ONLY named disposable resources, never uses production env files.
lab_state=${DEVICE_READONLY_STATE:-/tmp/agent-center-device-readonly-v1}
lab_net=device-readonly-v1-isolated
lab_ssh=device-readonly-v1-ssh
lab_pg=device-readonly-v1-postgres
lab_image=agent-center-device-readonly-lab:local
lab_central=routeros-task-lab-central
lab_candidate=routeros-task-lab-candidate
lab_private=routeros-task-lab-private
lab_private_created=0
lab_tls=routeros-panel-lab-tls
lab_tls_private=routeros-panel-lab-tls-private
lab_tls_created=0
test ! -e "$lab_state"
for name in "$lab_ssh" "$lab_pg" "$lab_central" "$lab_candidate" "$lab_tls"; do
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
docker network create --internal --subnet 198.18.77.0/28 "$lab_net" >/dev/null
cleanup() {
    if [ "${ROUTEROS_TASK_LAB:-0}" = 1 ]; then
      docker logs "$lab_candidate" > "$lab_state/candidate.log" 2>&1 || true
      docker logs "$lab_central" > "$lab_state/central.log" 2>&1 || true
    fi
    if [ "${PANEL_TRANSITION_LAB:-0}" = 1 ]; then
      docker exec "$lab_candidate" journalctl -u agent-center-updater.service --no-pager > "$lab_state/updater.log" 2>&1 || true
      docker exec "$lab_candidate" journalctl -u agent-center-agent.service --no-pager > "$lab_state/agent-service.log" 2>&1 || true
      docker rm -f "$lab_tls" >/dev/null 2>&1 || true
      if [ "$lab_tls_created" = 1 ]; then docker volume rm "$lab_tls_private" >/dev/null 2>&1 || true; fi
    fi
    docker rm -fv "$lab_candidate" "$lab_central" "$lab_ssh" "$lab_pg" >/dev/null 2>&1 || true
    if [ "$lab_private_created" = 1 ]; then docker volume rm "$lab_private" >/dev/null 2>&1 || true; fi
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
docker exec "$lab_ssh" python3 /protocol/check-isolation.py
docker exec "$lab_ssh" python3 /protocol/check-boundaries.py
if [ "${ROUTEROS_TASK_LAB:-0}" = 1 ]; then
    printf 'SIMULATED_ROUTEROS_TASK_ENGINE_V1\n' > "$lab_state/routeros-agent-lab"
    mkdir -p "$lab_state/agent-private"
    chmod 700 "$lab_state/agent-private"
    # Traverse the mount only; credential files and private subdirectory remain restricted.
    chmod 711 "$lab_state"
    chmod 644 "$lab_state/routeros-agent-lab"
    if [ "${ROUTEROS_SYSTEMD_LAB:-0}" != 1 ]; then
      DOCKER_CONFIG="$lab_state/docker-config" docker build -f scripts/lab/routeros-tasks/Dockerfile -t agent-center-routeros-candidate:lab .
    fi
    if docker volume inspect "$lab_private" >/dev/null 2>&1; then echo 'Lab private volume already exists'; exit 1; fi
    docker volume create "$lab_private" >/dev/null
    lab_private_created=1
    docker run --rm --network none --user 0 --mount "type=volume,src=$lab_private,dst=/private" agent-center-routeros-candidate:lab sh -c 'chmod 700 /private && mkdir -p /private/state && chmod 700 /private/state && chown 65534:65534 /private /private/state'
    export TEST_AGENT_PRIVATE_VOLUME="$lab_private"
    export TEST_ROUTEROS_TASK_INTEGRATION=1
    if [ "${PANEL_TRANSITION_LAB:-0}" = 1 ]; then
      export TEST_PANEL_TRANSITION_LAB=1
      cp -R "${PANEL_TRANSITION_PACKAGES:?}" "$lab_state/panel-packages"
      # These bundles contain public artifacts/signatures only; private signer is
      # deliberately outside the copied directory. The non-root launcher verifies
      # the restoration manifest as it does the two directly mounted packages.
      chmod 755 "$lab_state/panel-packages"
      for stage in bridge target restore; do
        if [ -d "$lab_state/panel-packages/$stage" ]; then
          chmod 755 "$lab_state/panel-packages/$stage"
          chmod 644 "$lab_state/panel-packages/$stage/manifest.json" "$lab_state/panel-packages/$stage/signature.base64" "$lab_state/panel-packages/$stage/signer-public.pem"
        fi
      done
      printf 'PANEL_TRANSITION_LAB=1\n' >> "$lab_state/runtime.env"
    fi
    if [ "${ROUTEROS_SYSTEMD_UPGRADE_LAB:-0}" = 1 ]; then
        export TEST_ROUTEROS_SYSTEMD_LIFECYCLE=1
    fi
    docker run -d --name "$lab_central" --network "container:$lab_ssh" --env-file "$lab_state/runtime.env" \
      --mount "type=bind,src=$PWD/app,dst=/var/www/html" --mount "type=bind,src=$lab_state/empty.env,dst=/var/www/html/.env,readonly" \
      --mount "type=bind,src=$lab_state,dst=/lab" --mount "type=bind,src=$lab_state/tls/ca.crt,dst=/run/pg-tls/ca.crt,readonly" \
      --mount "type=bind,src=$PWD/scripts/lab/routeros-tasks,dst=/integration,readonly" \
      "$lab_image" php -S 127.0.0.1:8089 /integration/central.php >/dev/null
    candidate_mount=()
    if [ -n "${ROUTEROS_PACKAGED_CANDIDATE:-}" ]; then
        case "$ROUTEROS_PACKAGED_CANDIDATE" in /tmp/*/agent.pyz) ;; *) echo 'Candidate must be a temporary laboratory bundle'; exit 1;; esac
        test -f "$ROUTEROS_PACKAGED_CANDIDATE"
        test ! -L "$ROUTEROS_PACKAGED_CANDIDATE"
        candidate_bundle=${ROUTEROS_PACKAGED_CANDIDATE%/agent.pyz}
        for metadata in manifest.json signature.base64 signer-public.pem; do
            test -f "$candidate_bundle/$metadata"
            test ! -L "$candidate_bundle/$metadata"
            candidate_mount+=(--mount "type=bind,src=$candidate_bundle/$metadata,dst=/integration-candidate/$metadata,readonly")
        done
        candidate_mount+=(--mount "type=bind,src=$ROUTEROS_PACKAGED_CANDIDATE,dst=/integration-candidate/agent.pyz,readonly")
        if [ -d "$candidate_bundle/ssh" ]; then
            candidate_mount+=(--mount "type=bind,src=$candidate_bundle/ssh,dst=/integration-candidate/ssh,readonly")
        fi
    fi
    if [ "${ROUTEROS_SYSTEMD_LAB:-0}" = 1 ]; then
      test -n "${ROUTEROS_PACKAGED_CANDIDATE:-}"
      if [ "${PANEL_TRANSITION_LAB:-0}" != 1 ]; then test -d "$candidate_bundle/ssh"; fi
      cat > "$lab_state/agent-center-agent.service" <<'UNIT'
[Unit]
Description=Isolated RouterOS 0.6.6 laboratory Agent
[Service]
User=65534
Group=65534
Environment=PYTHONPATH=/candidate/src PYTHONDONTWRITEBYTECODE=1
ExecStart=/usr/bin/python3 /integration/launch.py
NoNewPrivileges=yes
CapabilityBoundingSet=
AmbientCapabilities=
PrivateDevices=yes
PrivateTmp=yes
ProtectSystem=strict
ReadWritePaths=/lab/agent-private
RestrictAddressFamilies=AF_UNIX AF_INET
UMask=0077
[Install]
WantedBy=multi-user.target
UNIT
      chmod 644 "$lab_state/agent-center-agent.service"
      if [ "${ROUTEROS_SYSTEMD_UPGRADE_LAB:-0}" = 1 ]; then
        test -n "${ROUTEROS_BASELINE_BUNDLE:-}"
        test -f "$ROUTEROS_BASELINE_BUNDLE/agent.pyz"
        candidate_mount+=(--mount "type=bind,src=$ROUTEROS_BASELINE_BUNDLE,dst=/integration-baseline,readonly")
        candidate_mount+=(--mount "type=bind,src=$PWD/scripts/lab/routeros-operational,dst=/operational-lab,readonly")
        printf 'ROUTEROS_EXPECTED_VERSION=0.6.6\n' >> "$lab_state/runtime.env"
      fi
      if [ "${PANEL_TRANSITION_LAB:-0}" = 1 ]; then
        test -n "${ROUTEROS_BASELINE_BUNDLE:-}"
        openssl req -x509 -newkey rsa:2048 -nodes -keyout "$lab_state/tls/panel-server.key" -out "$lab_state/tls/panel-server.crt" -days 1 -subj /CN=127.0.0.1 -addext subjectAltName=IP:127.0.0.1 >/dev/null 2>&1
        chmod 644 "$lab_state/tls/panel-server.crt"
        if docker volume inspect "$lab_tls_private" >/dev/null 2>&1; then echo 'TLS fixture volume already exists'; exit 1; fi
        docker volume create "$lab_tls_private" >/dev/null
        lab_tls_created=1
        docker run --rm --network none --user 0:0 --mount "type=bind,src=$lab_state/tls/panel-server.key,dst=/source.key,readonly" --mount "type=volume,src=$lab_tls_private,dst=/private" agent-center-routeros-debian13:lab sh -c 'cp /source.key /private/server.key && chown 65534:65534 /private/server.key && chmod 600 /private/server.key'
        docker run --rm --network none --entrypoint cat agent-center-routeros-debian13:lab /etc/ssl/certs/ca-certificates.crt > "$lab_state/panel-trust.pem"
        cat "$lab_state/tls/panel-server.crt" >> "$lab_state/panel-trust.pem"
        docker run --rm --network none --entrypoint cat agent-center-routeros-debian13:lab /etc/passwd > "$lab_state/panel-passwd"
        printf 'agent-center-agent:x:65534:65534:Lab Agent:/nonexistent:/usr/sbin/nologin\n' >> "$lab_state/panel-passwd"
        chmod 644 "$lab_state/panel-trust.pem" "$lab_state/panel-passwd"
        cat > "$lab_state/panel-nginx.conf" <<'NGINX'
pid /tmp/nginx.pid;
error_log /dev/stderr warn;
events { worker_connections 64; }
http {
 access_log off;
 fastcgi_temp_path /tmp/fastcgi;
 uwsgi_temp_path /tmp/uwsgi;
 scgi_temp_path /tmp/scgi;
 client_body_temp_path /tmp/client-body;
 proxy_temp_path /tmp/proxy;
 server {
  listen 127.0.0.1:8443 ssl;
  ssl_certificate /panel-tls/panel-server.crt;
  ssl_certificate_key /panel-private/server.key;
  ssl_protocols TLSv1.2 TLSv1.3;
  location / { proxy_pass http://127.0.0.1:8089; proxy_set_header Host $host; proxy_set_header X-Forwarded-Proto https; }
 }
}
NGINX
        chmod 644 "$lab_state/panel-nginx.conf"
        docker run -d --name "$lab_tls" --network "container:$lab_ssh" --user 65534:65534 --cap-drop ALL --security-opt no-new-privileges --read-only --tmpfs /tmp \
          --mount "type=bind,src=$lab_state/tls,dst=/panel-tls,readonly" \
          --mount "type=volume,src=$lab_tls_private,dst=/panel-private,readonly" \
          --mount "type=bind,src=$lab_state/panel-nginx.conf,dst=/etc/nginx/nginx.conf,readonly" \
          --entrypoint /usr/sbin/nginx nginxinc/nginx-unprivileged:stable-alpine -g 'daemon off;' >/dev/null
        candidate_mount+=(--mount "type=bind,src=$ROUTEROS_BASELINE_BUNDLE,dst=/integration-baseline,readonly")
        candidate_mount+=(--mount "type=bind,src=$PANEL_TRANSITION_PACKAGES/bridge,dst=/integration-bridge,readonly")
        candidate_mount+=(--mount "type=bind,src=$PWD/agent/systemd,dst=/native-units,readonly")
        candidate_mount+=(--mount "type=bind,src=$PWD/scripts/lab/routeros-operational,dst=/operational-lab,readonly")
        candidate_mount+=(--mount "type=bind,src=$lab_state/panel-trust.pem,dst=/etc/ssl/certs/ca-certificates.crt,readonly")
        candidate_mount+=(--mount "type=bind,src=$lab_state/panel-passwd,dst=/etc/passwd,readonly")
        candidate_mount+=(--mount "type=volume,src=$lab_private,dst=/var/lib/agent-center-agent,volume-subpath=state")
        candidate_mount+=(--tmpfs /opt/agent-center-agent:exec,mode=755 --tmpfs /etc/agent-center-agent:mode=755 --tmpfs /var/lib/agent-center-updater:mode=700)
      fi
      # Privileges belong only to isolated PID 1; the Agent unit has none.
      docker run -d --name "$lab_candidate" --network "container:$lab_ssh" \
        --privileged --cgroupns private --read-only --tmpfs /run:exec,mode=755 --tmpfs /run/lock --tmpfs /tmp:exec,mode=1777 \
        --mount "type=volume,src=$lab_private,dst=/lab/agent-private" \
        --mount "type=bind,src=$PWD/agent/candidates/routeros-operational-v1,dst=/candidate,readonly" \
        --mount "type=bind,src=$lab_state,dst=/lab,readonly" \
        --mount "type=bind,src=$PWD/scripts/lab/routeros-tasks,dst=/integration,readonly" \
        --mount "type=bind,src=$lab_state/agent-center-agent.service,dst=/etc/systemd/system/agent-center-agent.service,readonly" \
        "${candidate_mount[@]}" agent-center-routeros-debian13:lab >/dev/null
      for attempt in {1..20}; do
        if docker exec "$lab_candidate" systemctl list-units --no-pager >/dev/null 2>&1; then break; fi
        sleep 1
      done
      if [ "${ROUTEROS_SYSTEMD_UPGRADE_LAB:-0}" = 1 ]; then
        docker exec "$lab_candidate" python3 /operational-lab/integrated-lifecycle.py prepare
      fi
      if [ "${PANEL_TRANSITION_LAB:-0}" = 1 ]; then
        docker exec "$lab_candidate" python3 /operational-lab/panel-native.py prepare
      fi
      docker exec "$lab_candidate" systemctl start agent-center-agent.service
      if [ "${ROUTEROS_SYSTEMD_UPGRADE_LAB:-0}" = 1 ]; then
        docker exec -d "$lab_candidate" python3 /operational-lab/integrated-lifecycle.py install
      fi
    else
    docker run -d --name "$lab_candidate" --network "container:$lab_ssh" --user 65534:65534 --cap-drop ALL --security-opt no-new-privileges \
      -e PYTHONPATH=/candidate/src -e PYTHONDONTWRITEBYTECODE=1 \
      --mount "type=volume,src=$lab_private,dst=/lab/agent-private" \
      --mount "type=bind,src=$PWD/agent,dst=/candidate,readonly" --mount "type=bind,src=$lab_state,dst=/lab" \
      --mount "type=bind,src=$PWD/scripts/lab/routeros-tasks,dst=/integration,readonly" \
      "${candidate_mount[@]}" agent-center-routeros-candidate:lab python /integration/launch.py >/dev/null
    fi
fi
TEST_PG_CONTAINER="$lab_pg" TEST_NETWORK="container:$lab_ssh" TEST_OWNER_ENV="$lab_state/owner.env" TEST_RUNTIME_ENV="$lab_state/runtime.env" TEST_CA_FILE="$lab_state/tls/ca.crt" \
TEST_IMAGE="$lab_image" TEST_DEVICE_PROTOCOL_STATE="$lab_state" \
    ./scripts/test.sh "$@"

if [ "${ROUTEROS_TASK_LAB:-0}" = 1 ] && [ "${ROUTEROS_SYSTEMD_UPGRADE_LAB:-0}" != 1 ] && [ "${PANEL_TRANSITION_LAB:-0}" != 1 ]; then
    docker exec --user 65534:65534 -e PYTHONPATH=/candidate/src:/integration-candidate/ssh "$lab_candidate" python3 /integration/journal-proof.py > "$lab_state/routeros-task-journal.json"
    if [ "${ROUTEROS_SYSTEMD_LAB:-0}" = 1 ]; then
        docker exec "$lab_candidate" systemctl show agent-center-agent.service \
          -p ActiveState -p SubState -p MainPID -p User -p Group -p NoNewPrivileges \
          -p CapabilityBoundingSet -p AmbientCapabilities -p PrivateDevices > "$lab_state/systemd-agent.txt"
        docker exec "$lab_candidate" cat /proc/1/comm > "$lab_state/systemd-pid1.txt"
        docker exec "$lab_candidate" systemctl restart agent-center-agent.service
        docker exec --user 65534:65534 -e PYTHONPATH=/candidate/src:/integration-candidate/ssh "$lab_candidate" python3 /integration/journal-proof.py > "$lab_state/routeros-task-journal-restarted.json"
        if [ "${ROUTEROS_SYSTEMD_UPGRADE_LAB:-0}" = 1 ]; then
            docker exec "$lab_candidate" python3 /operational-lab/integrated-lifecycle.py rollback > "$lab_state/integrated-lifecycle.json"
        fi
    fi
fi
