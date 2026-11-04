#!/bin/bash
# Explicit, fixed-target TLS hardening. Preserves the deployed application image/schema.
set -euo pipefail
umask 077
[ "$#" -eq 1 ] || exit 1
case "$1" in --new-internal-ca|--existing-pki) ;; *) exit 1;; esac
[ "$(id -u)" -eq 0 ] || exit 1
source_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd /opt/agent-center
if [ "$1" = --new-internal-ca ]; then
  [ ! -e secrets/postgres ] && [ ! -e secrets/postgres-ca ] || { echo 'PKI exists; refusing overwrite'; exit 1; }
else
  [ -f secrets/postgres/server.key ] && [ -f secrets/postgres/ca.crt ] || exit 1
fi
./scripts/backup.sh > backups/recovery8-before-tls.log 2>&1
rollback_dir="$PWD/backups/recovery8-before-tls-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 700 "$rollback_dir"
cp deploy/compose.yaml "$rollback_dir/compose.yaml"
cp .env.runtime .env.migrator "$rollback_dir/"
if [ "$1" = --new-internal-ca ]; then
mkdir -m 700 secrets/postgres secrets/postgres-ca
openssl req -x509 -newkey rsa:3072 -nodes -keyout secrets/postgres-ca/ca.key -out secrets/postgres/ca.crt -days 3650 -subj /CN=Agent-Center-Internal-PostgreSQL-CA >/dev/null 2>&1
openssl req -newkey rsa:3072 -nodes -keyout secrets/postgres/server.key -out secrets/postgres-ca/server.csr -subj /CN=postgres >/dev/null 2>&1
printf '%s\n' 'subjectAltName=DNS:postgres' 'extendedKeyUsage=serverAuth' > secrets/postgres-ca/extensions
openssl x509 -req -in secrets/postgres-ca/server.csr -CA secrets/postgres/ca.crt -CAkey secrets/postgres-ca/ca.key -CAcreateserial -out secrets/postgres/server.crt -days 365 -extfile secrets/postgres-ca/extensions >/dev/null 2>&1
cp "$source_root/deploy/postgres/pg_hba.hostssl.example" secrets/postgres/pg_hba.conf
fi
openssl verify -CAfile secrets/postgres/ca.crt -verify_hostname postgres secrets/postgres/server.crt >/dev/null
chown 999:999 secrets/postgres/server.key
chmod 600 secrets/postgres/server.key
chmod 755 secrets/postgres
chmod 644 secrets/postgres/ca.crt secrets/postgres/server.crt secrets/postgres/pg_hba.conf
rollback() {
  status=$?
  if [ "$status" -ne 0 ]; then
    cp "$rollback_dir/compose.yaml" deploy/compose.yaml
    cp "$rollback_dir/.env.runtime" .env.runtime
    cp "$rollback_dir/.env.migrator" .env.migrator
    ./scripts/compose.sh up -d --wait postgres web worker diagnostic-worker nginx > backups/recovery8-tls-rollback.log 2>&1 || true
    echo 'TLS deployment failed; previous configuration restored. Check restricted rollback log.'
  fi
}
trap rollback EXIT
python3 - <<'PY'
from pathlib import Path
p=Path('deploy/compose.yaml')
s=p.read_text()
old='command: [postgres, -c, timezone=Europe/Berlin, -c, log_statement=none]'
assert old in s and 'DB_SSLMODE: verify-full' not in s
s=s.replace('    DB_PORT:', '    DB_SSLMODE: verify-full\n    DB_SSLROOTCERT: /run/pg-tls/ca.crt\n    DB_PORT:',1)
s=s.replace('      DB_PORT:', '      DB_SSLMODE: verify-full\n      DB_SSLROOTCERT: /run/pg-tls/ca.crt\n      DB_PORT:')
s=s.replace('    - app_storage:/var/www/html/storage', '    - ../secrets/postgres/ca.crt:/run/pg-tls/ca.crt:ro\n    - app_storage:/var/www/html/storage',1)
s=s.replace('      - ../secrets/llm-master.key:', '      - ../secrets/postgres/ca.crt:/run/pg-tls/ca.crt:ro\n      - ../secrets/llm-master.key:')
s=s.replace(old, 'command: [postgres, -c, timezone=Europe/Berlin, -c, log_statement=none, -c, ssl=on, -c, ssl_min_protocol_version=TLSv1.2, -c, ssl_cert_file=/run/pg-tls/server.crt, -c, ssl_key_file=/run/pg-tls/server.key, -c, hba_file=/run/pg-tls/pg_hba.conf]')
s=s.replace('      - postgres_data:', '      - ../secrets/postgres:/run/pg-tls:ro\n      - postgres_data:')
s=s.replace("'pg_isready -U \"$$POSTGRES_USER\" -d \"$$POSTGRES_DB\"'", "'PGPASSWORD=\"$$POSTGRES_PASSWORD\" PGSSLMODE=verify-full PGSSLROOTCERT=/run/pg-tls/ca.crt psql -h postgres -U \"$$POSTGRES_USER\" -d \"$$POSTGRES_DB\" -Atqc \"SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()\" | grep -qx t'")
p.write_text(s)
for name in ['.env.runtime','.env.migrator']:
 p=Path(name)
 s=p.read_text()
 s+='\nDB_SSLMODE=verify-full\nDB_SSLROOTCERT=/run/pg-tls/ca.crt\n'
 p.write_text(s)
PY
./scripts/compose.sh config --quiet
./scripts/compose.sh stop web worker diagnostic-worker nginx > backups/recovery8-tls-deploy.log 2>&1
./scripts/compose.sh up -d --wait postgres web worker diagnostic-worker nginx >> backups/recovery8-tls-deploy.log 2>&1
install -m 750 "$source_root/scripts/verify-pg-tls.sh" scripts/verify-pg-tls.sh
install -m 750 "$source_root/scripts/deploy-precheck.sh" scripts/deploy-precheck.sh
./scripts/verify-pg-tls.sh
./scripts/queue-smoke.sh
./scripts/deploy-precheck.sh
trap - EXIT
echo 'Production TLS hardening: PASS; application image and schema preserved.'
