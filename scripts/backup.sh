#!/bin/bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
backup_root="$PWD/backups"
retention_days=${BACKUP_LOCAL_DAYS:-7}
case "$retention_days" in ''|*[!0-9]*) echo 'Invalid retention'; exit 1;; esac
[ "$retention_days" -ge 1 ] && [ "$retention_days" -le 365 ] || exit 1
retention_age=$((retention_days - 1))
mkdir -p "$backup_root"
chmod 700 "$backup_root"
exec 9>"$backup_root/.backup.lock"
flock -n 9 || { echo 'Outro backup está em execução.' >&2; exit 1; }
stamp=$(date -u +%Y%m%dT%H%M%SZ)
python3 - <<'STATUS'
import json, os
from pathlib import Path
p=Path('backups/local-status.json')
p.write_text(json.dumps({'state':'NO-GO','reason':'backup_not_completed'}))
os.chmod(p,0o600)
STATUS
mkdir -p "$backup_root/storage" "$backup_root/database" "$backup_root/config" "$backup_root/secrets"
chmod 700 "$backup_root" "$backup_root"/{database,config,secrets,storage}
dump="$backup_root/database/$stamp.dump"
[ ! -e "$dump" ] && [ ! -e "$backup_root/config/$stamp.sha256" ] || { echo 'Generation already exists'; exit 1; }
trap 'rm -f "${dump}.partial"' EXIT
./scripts/compose.sh exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$dump.partial"
./scripts/compose.sh exec -T postgres pg_restore --list < "$dump.partial" > /dev/null
mv "$dump.partial" "$dump"
# Record the deployed runtime/schema separately from the checkout commit.
web_id=$(./scripts/compose.sh ps -q web)
docker inspect --format '{"image":{{json .Config.Image}},"id":{{json .Image}}}' "$web_id" > "$backup_root/config/$stamp.runtime.json"
./scripts/compose.sh exec -T postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atq' > "$backup_root/config/$stamp.schema.json" <<'SQL'
SELECT json_build_object('migrations',(SELECT json_agg(migration ORDER BY migration) FROM migrations),'force_rls_tables',(SELECT count(*) FROM pg_class WHERE relrowsecurity AND relforcerowsecurity));
SQL

tar -czf "$backup_root/config/$stamp.tar.gz" deploy scripts docs AGENTS.md README.md .env.example .gitignore .dockerignore
host_files=()
for file in etc/nginx/sites-available/agent-center.conf etc/nginx/snippets/agent-center-access.conf etc/nginx/conf.d/agent-center-observability.conf etc/logrotate.d/agent-center etc/systemd/system/agent-center-backup.service etc/systemd/system/agent-center-backup.timer etc/systemd/system/agent-center-backup-alert.service etc/systemd/system/agent-center-operations.service etc/systemd/system/agent-center-operations.timer; do
  if [ -f "/$file" ]; then host_files+=("$file"); fi
done
if [ "${#host_files[@]}" -gt 0 ]; then tar -czf "$backup_root/config/$stamp.host.tar.gz" -C / "${host_files[@]}"; fi
if [ -d agent/dist ]; then tar -czf "$backup_root/storage/$stamp.agent-kit.tar.gz" agent/dist; fi
if git rev-parse --verify HEAD >/dev/null 2>&1; then
  git rev-parse HEAD > "$backup_root/config/$stamp.commit"
  git bundle create "$backup_root/config/$stamp.bundle" --all
fi
# Persistent artifacts, including private framework state. Never send plaintext externally.
./scripts/compose.sh exec -T web tar -czf - -C /var/www/html storage > "$backup_root/storage/$stamp.tar.gz"
tar -czf "$backup_root/secrets/$stamp.environment.tar.gz" "${COMPOSE_ENV_FILE:-.env}" .env.runtime .env.migrator
cp "${COMPOSE_ENV_FILE:-.env}" "$backup_root/secrets/$stamp.env"
if [ -f secrets/llm-master.key ]; then cp secrets/llm-master.key "$backup_root/secrets/$stamp.llm-master.key"; chmod 600 "$backup_root/secrets/$stamp.llm-master.key"; tar --exclude=secrets/recovery8-lab --exclude=secrets/backup-recipient-private.key -czf "$backup_root/secrets/$stamp.llm-keyring.tar.gz" secrets; chmod 600 "$backup_root/secrets/$stamp.llm-keyring.tar.gz"; fi
if [ -r /etc/letsencrypt/live/agent-center/privkey.pem ]; then
  tar -chzf "$backup_root/secrets/$stamp.tls-agent-center.tar.gz" -C /etc/letsencrypt live/agent-center renewal/agent-center.conf
  chmod 600 "$backup_root/secrets/$stamp.tls-agent-center.tar.gz"
fi
chmod 600 "$backup_root/database/$stamp.dump" "$backup_root/config/$stamp.tar.gz" "$backup_root/secrets/$stamp.env"
# Include every member of this generation, including confidential secrets and bundle.
(cd "$backup_root" && find database config secrets storage -maxdepth 1 -type f -name "$stamp.*" ! -name "$stamp.sha256" -print0 | sort -z | xargs -0 sha256sum) > "$backup_root/config/$stamp.sha256"
chmod 600 "$backup_root/config/$stamp.sha256"
(cd "$backup_root" && sha256sum -c "config/$stamp.sha256" >/dev/null)
if [ -f "$backup_root/config/$stamp.bundle" ]; then git bundle verify "$backup_root/config/$stamp.bundle" >/dev/null 2>&1; fi
# Só remove arquivos deste backup, após concluir o novo conjunto. Janela local: 7 dias.
find "$backup_root/storage" -maxdepth 1 -type f -name '*.tar.gz' -mtime +"$retention_age" -delete
find "$backup_root/database" -maxdepth 1 -type f -name '*.dump' -mtime +"$retention_age" -delete
find "$backup_root/config" -maxdepth 1 -type f \( -name '*.tar.gz' -o -name '*.runtime.json' -o -name '*.schema.json' -o -name '*.sha256' -o -name '*.commit' -o -name '*.bundle' \) -mtime +"$retention_age" -delete
find "$backup_root/secrets" -maxdepth 1 -type f \( -name '*.environment.tar.gz' -o -name '*.env' -o -name '*.llm-master.key' -o -name '*.llm-keyring.tar.gz' -o -name '*.tls-agent-center.tar.gz' -o -name '*agent-state.tar.gz' \) -mtime +"$retention_age" -delete
printf 'Backup local concluído: %s. Replicação externa deve ser verificada separadamente.\n' "$stamp"
python3 - "$stamp" <<'STATUS'
import json, os, sys
from datetime import datetime, timezone
from pathlib import Path
p=Path('backups/local-status.json')
t=p.with_suffix('.pending')
t.write_text(json.dumps({'state':'VERDE','reason':'generation_integrity_verified','generation':sys.argv[1], 'checked_at':datetime.now(timezone.utc).isoformat(),'storage_included':True,'project':os.environ.get('COMPOSE_PROJECT_NAME','agent-center')}))
os.chmod(t,0o600)
os.replace(t,p)
STATUS
