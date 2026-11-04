#!/bin/bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
[ "$#" -eq 2 ] || { echo 'Uso: export-backup.sh certificado-publico.pem diretorio-saida' >&2; exit 1; }
recipient=$(realpath "$1")
[ -f "$recipient" ] || exit 1
mkdir -p "$2"
output=$(realpath "$2")
chmod 700 "$output"
for category in database config secrets storage; do
  tar -czf - -C backups "$category" | openssl cms -encrypt -binary -aes-256-cbc -outform DER -out "$output/$category-$(date -u +%Y%m%dT%H%M%SZ).cms" "$recipient"
done
echo 'Exportação criptografada concluída; envio e verificação externos ainda necessários.'
