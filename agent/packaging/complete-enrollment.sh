#!/bin/sh
set -eu
[ "$#" -eq 0 ] || exit 2
[ "$(id -u)" -eq 0 ] || exit 2
/usr/sbin/runuser -u agent-center-agent -- /usr/bin/python3 -I /opt/agent-center-agent/runtime/agent.pyz --config /etc/agent-center-agent/config.json --enroll
/usr/bin/systemctl enable --now agent-center-agent.service
/usr/bin/python3 - <<'CHECK'
import json,time
from pathlib import Path
start=time.time()
for _ in range(60):
    p=Path('/var/lib/agent-center-agent/status.json')
    try:
        d=json.loads(p.read_text())
        if p.stat().st_mtime>=start and d.get('state')=='ONLINE' and d.get('heartbeat_successes',0)>0 and d.get('poll_successes',0)>0:
            print('{"state":"AGENT_READY","heartbeat_and_poll":true}')
            raise SystemExit(0)
    except (OSError,ValueError):
        pass
    time.sleep(1)
print('{"state":"NOT_READY","reason":"heartbeat_or_poll_not_confirmed"}')
raise SystemExit(1)
CHECK
