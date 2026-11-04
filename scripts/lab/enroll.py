import json
import sys
import time
from pathlib import Path

from agent_center.api import Client
from agent_center.identity import Identity
from agent_center.state import atomic_write

lab = Path(sys.argv[1])
code = json.loads((lab / "enrollment.json").read_text())["code"]
for _ in range(20):
    r = Client("http://127.0.0.1:18084", retries=0).call(
        "enroll",
        {
            "code": code,
            "hostname": "agent-lab",
            "version": "0.5.2",
            "capabilities": ["agent_info", "dns_lookup:v1", "tcp_connect:v1", "http_probe:v1"],
        },
    )
    if r.ok:
        break
    if r.status:
        raise SystemExit("Lab enrollment recusado: " + str(r.status))
    time.sleep(0.2)
else:
    raise SystemExit("Lab Laravel indisponível")
identity = Identity.validate(r.body)
(lab / "state").mkdir(mode=0o700)
identity.save(lab / "state" / "identity.json")
atomic_write(
    lab / "config.json",
    {
        "central_url": "http://127.0.0.1:18084",
        "allow_http_loopback": True,
        "state_dir": "/lab/state",
        "identity_file": "/lab/state/identity.json",
        "log_dir": "/lab/logs",
        "heartbeat_interval": 20,
        "poll_interval": 5,
        "timeout": 5,
        "device_operations_frozen": True,
        "allowed_networks": [],
    },
)
(lab / "enrollment.json").unlink()
# Exercise all contracts with the exact production client, against Laravel HTTP.
client = Client("http://127.0.0.1:18084", identity.token)
for operation, payload in [
    ("heartbeat", {"version": "0.5.2"}),
    ("capabilities", {"capabilities": ["agent_info"]}),
    ("claim", {}),
    ("reconcile", {"executions": []}),
]:
    outcome = client.call(operation, payload)
    assert outcome.ok, (operation, outcome.status)
for operation in ["start", "renew", "result"]:
    outcome = client.call(
        operation,
        {
            "task_id": "11111111-1111-4111-8111-111111111111",
            "execution_id": "22222222-2222-4222-8222-222222222222",
            "lease_token": "a" * 32,
        },
    )
    assert outcome.status == 409, (operation, outcome.status)
print("Contratos Python ↔ Laravel HTTP: PASS")
