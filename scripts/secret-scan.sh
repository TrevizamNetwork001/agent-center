#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Lê apenas o índice Git, sem imprimir valores encontrados.
failed=0
while IFS= read -r -d '' path; do
  case "$path" in
    backups/.gitkeep|.env.example|*/.env.example) ;;
    .env|*/.env|.env.*|*/.env.*|backups/*|*.pem|*.key) echo "Arquivo proibido no índice: $path"; failed=1;;
  esac
  if git show ":$path" | LC_ALL=C grep -Eq '(base64:[A-Za-z0-9+/]{40,}=|-----BEGIN ([A-Z]+ )?PRIVATE KEY-----|AKIA[A-Z0-9]{16}|gh[pousr]_[A-Za-z0-9]{30,}|(POSTGRES_PASSWORD|APP_DB_PASSWORD|DB_PASSWORD)=[^[:space:]#]{8,})'; then
    echo "Possível secret no índice: $path"
    failed=1
  fi
done < <(git ls-files -z)
[ "$failed" -eq 0 ] || exit 1
echo 'Secret scan básico: PASS (índice Git; não substitui scanner especializado).'
