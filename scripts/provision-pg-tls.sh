#!/bin/bash
# Creates a private CA for an isolated test only. Production accepts an approved PKI.
set -euo pipefail
umask 077
[ "$#" = 2 ] && [ "$1" = --lab ] || { echo 'Usage: provision-pg-tls.sh --lab NEW_DIRECTORY'; exit 1; }
[ ! -e "$2" ] || { echo 'Refusing existing directory'; exit 1; }
mkdir -m 700 "$2"
openssl req -x509 -newkey rsa:3072 -nodes -keyout "$2/ca.key" -out "$2/ca.crt" -days 2 -subj /CN=Agent-Center-RECOVERY8-LAB-CA >/dev/null 2>&1
openssl req -newkey rsa:3072 -nodes -keyout "$2/server.key" -out "$2/server.csr" -subj /CN=postgres >/dev/null 2>&1
printf '%s\n' 'subjectAltName=DNS:postgres' 'extendedKeyUsage=serverAuth' > "$2/extensions"
openssl x509 -req -in "$2/server.csr" -CA "$2/ca.crt" -CAkey "$2/ca.key" -CAcreateserial -out "$2/server.crt" -days 2 -extfile "$2/extensions" >/dev/null 2>&1
cp deploy/postgres/pg_hba.hostssl.example "$2/pg_hba.conf"
# UID from the pinned official PostgreSQL image, verified by the lab precheck.
chown 999:999 "$2/server.key"
chmod 600 "$2/server.key"
chmod 755 "$2"
chmod 644 "$2/ca.crt" "$2/server.crt" "$2/pg_hba.conf"
echo 'LAB PKI created; never install these short-lived test certificates in production.'
