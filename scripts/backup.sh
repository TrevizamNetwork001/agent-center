#!/bin/bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
backup_root="$PWD/backups"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
exec 9>"$backup_root/.backup.lock"
flock -n 9 || { echo 'Outro backup está em execução.' >&2; exit 1; }
mkdir -p "$backup_root/database" "$backup_root/config" "$backup_root/secrets"
chmod 700 "$backup_root" "$backup_root"/{database,config,secrets}
dump="$backup_root/database/$stamp.dump"
trap 'rm -f "${dump}.partial"' EXIT
./scripts/compose.sh exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$dump.partial"
./scripts/compose.sh exec -T postgres pg_restore --list < "$dump.partial" > /dev/null
mv "$dump.partial" "$dump"
tar -czf "$backup_root/config/$stamp.tar.gz" deploy scripts docs AGENTS.md README.md .env.example .gitignore .dockerignore
if git rev-parse --verify HEAD >/dev/null 2>&1; then
  git rev-parse HEAD > "$backup_root/config/$stamp.commit"
  git bundle create "$backup_root/config/$stamp.bundle" --all
fi
cp .env "$backup_root/secrets/$stamp.env"
if [ -f secrets/llm-master.key ]; then cp secrets/llm-master.key "$backup_root/secrets/$stamp.llm-master.key"; chmod 600 "$backup_root/secrets/$stamp.llm-master.key"; tar -czf "$backup_root/secrets/$stamp.llm-keyring.tar.gz" secrets; chmod 600 "$backup_root/secrets/$stamp.llm-keyring.tar.gz"; fi
if [ -r /etc/letsencrypt/live/agent-center/privkey.pem ]; then
  tar -chzf "$backup_root/secrets/$stamp.tls-agent-center.tar.gz" -C /etc/letsencrypt live/agent-center renewal/agent-center.conf
  chmod 600 "$backup_root/secrets/$stamp.tls-agent-center.tar.gz"
fi
chmod 600 "$backup_root/database/$stamp.dump" "$backup_root/config/$stamp.tar.gz" "$backup_root/secrets/$stamp.env"
# Include every member of this generation, including confidential secrets and bundle.
(cd "$backup_root" && find database config secrets -maxdepth 1 -type f -name "$stamp.*" ! -name "$stamp.sha256" -print0 | sort -z | xargs -0 sha256sum) > "$backup_root/config/$stamp.sha256"
chmod 600 "$backup_root/config/$stamp.sha256"
(cd "$backup_root" && sha256sum -c "config/$stamp.sha256" >/dev/null)
if [ -f "$backup_root/config/$stamp.bundle" ]; then git bundle verify "$backup_root/config/$stamp.bundle" >/dev/null 2>&1; fi
# Só remove arquivos deste backup, após concluir o novo conjunto. Janela local: 7 dias.
find "$backup_root/database" -maxdepth 1 -type f -name '*.dump' -mtime +6 -delete
find "$backup_root/config" -maxdepth 1 -type f \( -name '*.tar.gz' -o -name '*.sha256' -o -name '*.commit' -o -name '*.bundle' \) -mtime +6 -delete
find "$backup_root/secrets" -maxdepth 1 -type f \( -name '*.env' -o -name '*.llm-master.key' -o -name '*.llm-keyring.tar.gz' -o -name '*.tls-agent-center.tar.gz' -o -name '*agent-state.tar.gz' \) -mtime +6 -delete
printf 'Backup local concluído: %s. Replicação externa deve ser verificada separadamente.\n' "$stamp"
