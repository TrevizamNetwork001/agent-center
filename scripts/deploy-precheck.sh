#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
./scripts/compose.sh config --quiet
./scripts/verify-pg-tls.sh
./scripts/compose.sh exec -T web php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment("production") || config("app.debug") || !config("app.key") || !config("session.secure") || config("database.connections.pgsql.sslmode")!=="verify-full" || !is_readable(config("database.connections.pgsql.sslrootcert"))) exit(1);
if (!Illuminate\Support\Facades\DB::selectOne("select ssl from pg_stat_ssl where pid=pg_backend_pid()")->ssl) exit(1);
echo "Production configuration and PDO TLS: PASS\n";'
./scripts/queue-smoke.sh
./scripts/compose.sh exec -T diagnostic-worker php artisan agent-center:worker-health
# Validate assets through the unprivileged Nginx process, including host bind permissions.
./scripts/compose.sh exec -T nginx sh -c 'test -r /var/www/html/public/agent-center.css && test -r /var/www/html/public/agent-center.js && wget -q -O /dev/null http://127.0.0.1:8080/agent-center.css && wget -q -O /dev/null http://127.0.0.1:8080/agent-center.js'
echo 'Public CSS/JS readable and served by Nginx: PASS'
