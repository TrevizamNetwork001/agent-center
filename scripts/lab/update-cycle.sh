#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
php_fixture() {
 docker run --rm -i --network agent-center_backend --env-file .env.runtime -e DB_DATABASE=agent_center_lab --mount type=bind,src="$PWD/app",dst=/var/www/html --mount type=bind,src="$PWD/scripts/lab/update-operation.php",dst=/fixture.php,readonly agent-center-tooling:foundation php /fixture.php "$1"
}
companion() {
 docker run --rm --network host --mount type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock --mount type=bind,src="$PWD/agent",dst=/source,readonly --mount type=bind,src="$PWD/scripts/lab/remote-updater.py",dst=/run-companion.py,readonly --mount type=bind,src=/tmp/recovery7-update-lab,dst=/lab --mount type=bind,src=/tmp/recovery7-update-lab/layout/opt/agent-center-agent,dst=/install agent-center-python-tools:0.5.2 python /run-companion.py
}
for kind in update rollback; do
 php_fixture "$kind"
 companion
 php_fixture approve
 companion
 docker exec agent-center-recovery7-update-agent-lab python -c 'import json,sqlite3;from pathlib import Path;s=json.loads(Path("/lab/state/status.json").read_text());d=sqlite3.connect("file:/lab/state/journal.sqlite3?mode=ro",uri=True);assert d.execute("PRAGMA integrity_check").fetchone()[0]=="ok";assert d.execute("SELECT count(*) FROM executions WHERE state=\"uncertain\"").fetchone()[0]==1;print(json.dumps(dict(version=s["version"],state=s["state"],journal_preserved=True,identity_reused=True)));d.close()'
done
