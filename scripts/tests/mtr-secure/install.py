import hashlib
import json
import os
import pathlib
import shutil
import subprocess

R = pathlib.Path("/root")
unit = pathlib.Path("/etc/systemd/system/agent-center-agent.service")
unit.parent.mkdir(parents=True, exist_ok=True)
shutil.copyfile("/source/agent/systemd/agent-center-agent.service", unit)
config = pathlib.Path("/etc/agent-center-agent/config.json")
config.parent.mkdir(exist_ok=True)
config.write_text(
    json.dumps({"allowed_dns_names": ["google.com"], "allowed_networks": ["8.8.8.8/32"]})
)
os.chown(config, 987, 987)
config.chmod(0o600)
identity = pathlib.Path("/var/lib/agent-center-agent/identity.json")
identity.parent.mkdir(exist_ok=True)
identity.write_text("LAB_FIXTURE_NO_VALID_CREDENTIAL")
os.chown(identity, 987, 987)
identity.chmod(0o600)
# A fixed fixture replaces only the container's manager CLI. No real services are started.
pathlib.Path("/usr/bin/systemctl").write_text("""#!/usr/bin/python3
import sys,json
from pathlib import Path
with open('/root/manager-calls','a') as f:f.write(json.dumps(sys.argv[1:])+'\\n')
if sys.argv[1]=='daemon-reload':raise SystemExit(0)
if sys.argv[1]!='show':raise SystemExit(2)
key=sys.argv[-1]
print({'User':'agent-center-agent','NoNewPrivileges':'yes','PrivateDevices':'yes','CapabilityBoundingSet':'','AmbientCapabilities':'','FragmentPath':'/etc/systemd/system/agent-center-agent.service'}[key])
""")
pathlib.Path("/usr/bin/systemctl").chmod(0o755)
policy = R / "reviewed-policy.json"
policy.write_text(
    json.dumps({"schema_version": 1, "allowed_dns_names": ["google.com"], "allowed_networks": []})
)
policy.chmod(0o600)
unauthorized = R / "unauthorized.json"
unauthorized.write_text(
    json.dumps(
        {"schema_version": 1, "allowed_dns_names": ["forbidden.invalid"], "allowed_networks": []}
    )
)
unauthorized.chmod(0o600)


def digest():
    return {str(p): hashlib.sha256(p.read_bytes()).hexdigest() for p in (unit, config, identity)}


before = digest()


def run(policy, kit="/kit"):
    r = subprocess.run(
        ["/usr/bin/python3", kit + "/install-helper.py", "--policy", str(policy)],
        capture_output=True,
        text=True,
        timeout=30,
    )
    try:
        body = json.loads(r.stdout)
    except Exception:
        raise RuntimeError("installer did not return typed result: " + r.stdout + r.stderr)
    return r.returncode, body


results = []
code, result = run(unauthorized)
assert code == 2 and result["error"] == "ceiling_exceeds_local_policy", result
results.append({"case": "unauthorized_ceiling", "result": result})
code, result = run(policy)
assert code == 0 and result["prepared"] and not result["started"], result
results.append({"case": "prepared", "result": result})
assert before == digest(), "principal or identity modified"
code, result = run(policy)
assert code == 2 and result["error"] == "existing_installation_refused", result
results.append({"case": "existing_refused", "result": result})
bad = R / "badkit"
shutil.copytree("/kit", bad)
(bad / "helper.pyz").write_bytes(b"tampered")
code, result = run(policy, str(bad))
assert code == 2 and result["error"] == "artifact_hash_mismatch", result
results.append({"case": "tamper_refused", "result": result})
# Documented helper-only rollback leaves principal data and account/state backups intact.
for p in (
    "/etc/systemd/system/agent-center-mtr.socket",
    "/etc/systemd/system/agent-center-mtr@.service",
    "/etc/agent-center-mtr/policy.json",
    "/opt/agent-center-mtr/helper.pyz",
    "/opt/agent-center-mtr/sysusers.conf",
    "/opt/agent-center-mtr/check-helper.py",
):
    pathlib.Path(p).unlink()
assert before == digest()
calls = [json.loads(line) for line in (R / "manager-calls").read_text().splitlines()]
assert all(c[0] in ("show", "daemon-reload") for c in calls), "installer started a service"
print(
    json.dumps(
        {
            "results": results,
            "principal_config_identity_preserved": True,
            "services_started": False,
            "rollback_file_guard": "PASS",
        }
    )
)
