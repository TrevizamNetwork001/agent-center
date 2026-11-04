#!/bin/bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/../.."
lab_dir=/tmp/agent-center-recovery3-lab
[ ! -e "$lab_dir" ] || { echo 'Lab já existe; não sobrescrever.' >&2; exit 1; }
mkdir -m 700 "$lab_dir"
python3 scripts/create-migrator-env.py
./scripts/compose.sh exec -T postgres sh -c 'createdb -U "$POSTGRES_USER" -O "$APP_DB_USER" agent_center_lab'
docker run --rm --network agent-center_backend --env-file .env.migrator -e DB_DATABASE=agent_center_lab agent-center-central:recovery3 php artisan migrate --force
./scripts/db-grants.sh agent_center_lab
docker run --rm -i --network agent-center_backend --env-file .env.runtime -e DB_DATABASE=agent_center_lab agent-center-central:recovery3 php < scripts/lab/create-enrollment.php > "$lab_dir/enrollment.json"
docker run -d --name agent-center-recovery3-laravel-lab --network agent-center_backend --network agent-center_frontend --env-file .env.runtime -e DB_DATABASE=agent_center_lab -p 127.0.0.1:18084:8080 agent-center-central:recovery3 php -S 0.0.0.0:8080 -t public public/index.php >/dev/null
PYTHONPATH=agent/src python3 scripts/lab/enroll.py "$lab_dir"
chown -R 65532:65532 "$lab_dir"
docker run -d --name agent-center-recovery3-agent-lab --user 65532:65532 --cap-drop ALL --security-opt no-new-privileges:true --network host --mount type=bind,src="$PWD/agent",dst=/source,readonly --mount type=bind,src="$lab_dir",dst=/lab agent-center-python-tools:0.5.2 python -m agent_center.main --config /lab/config.json >/dev/null
echo 'Lab isolado iniciado; nenhum secret é impresso.'
