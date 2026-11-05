"""ROOT-ONLY DISPOSABLE LAB: prepared rootfs under /root; never point at a real Agent root.
Temporary units use only the agent-center-mtr-secure-lab prefix and are removed on exit.
"""

import json
import os
import pathlib
import shutil
import socket
import subprocess
import time

W = pathlib.Path("/root/agent-center-mtr-secure")
R = W / "rootfs"
P = W / "stage/agent/mtr-debian13"
U = pathlib.Path("/run/systemd/system")
PREFIX = "agent-center-mtr-secure-lab"


def cmd(args, check=True, timeout=30):
    return subprocess.run(args, text=True, capture_output=True, check=check, timeout=timeout)


def ctl(*args, **kw):
    return cmd(["/usr/bin/systemctl", *args], **kw)


state = W / "native-state"
state.mkdir(exist_ok=True)
state.chmod(0o777)
creds = W / "native-credentials"
creds.mkdir(exist_ok=True)
creds.chmod(0o700)
host = socket.getaddrinfo("google.com", 443, socket.AF_INET, socket.SOCK_STREAM)[0][4][0]
policy = {"allowed_networks": [host + "/32"], "allowed_dns_names": []}
for name, doc in [("local", policy), ("ceiling", {"schema_version": 1, **policy})]:
    f = creds / (name + ".json")
    f.write_text(json.dumps(doc))
    f.chmod(0o600)
os.chown(creds / "local.json", 65534, 65534)
passwd = R / "etc/passwd"
data = passwd.read_text()
data = (
    "\n".join(
        ":".join([*(line.split(":")[:2]), "65534", "65534", *line.split(":")[4:]])
        if line.startswith("agent-center-agent:")
        else line
        for line in data.splitlines()
    )
    + "\n"
)
passwd.write_text(data)
for name in ("helper.pyz", "agent.pyz"):
    dest = R / "opt/agent-center-mtr" / name
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(W / "stage/artifacts/mtr-secure-debian13" / name, dest)
    dest.chmod(0o644)
# Reproduce Debian's file capability on the *laboratory rootfs* only.
cmd(["/usr/sbin/setcap", "cap_net_raw=ep", str(R / "usr/bin/mtr-packet")])
source = (P / "agent-center-mtr@.service").read_text()
source = (
    source.replace("User=agent-center-mtr", "User=agent-center-mtr-secure-lab\nDynamicUser=yes")
    .replace("Group=agent-center-mtr\n", "")
    .replace("StateDirectory=agent-center-mtr\n", "")
    .replace("StateDirectoryMode=0700\n", "")
    .replace(
        "LoadCredential=local-policy:/etc/agent-center-agent/config.json",
        f"LoadCredential=local-policy:{creds}/local.json",
    )
    .replace(
        "LoadCredential=ceiling:/etc/agent-center-mtr/policy.json",
        f"LoadCredential=ceiling:{creds}/ceiling.json",
    )
)
source += f"RootDirectory={R}\nMountAPIVFS=yes\nBindPaths={state}:/var/lib/agent-center-mtr\nReadWritePaths=/var/lib/agent-center-mtr\n"
unit = U / (PREFIX + "@.service")
sockunit = U / (PREFIX + ".socket")
socketpath = W / "native.sock"
sock = (
    (P / "agent-center-mtr.socket")
    .read_text()
    .replace("/run/agent-center-mtr.sock", str(socketpath))
    .replace("SocketUser=agent-center-agent", "SocketUser=nobody")
    .replace("SocketGroup=agent-center-agent", "SocketGroup=nogroup")
)
# Matching native socket/template names permit normal Accept=yes activation.
if R.is_symlink() or R.resolve() == pathlib.Path("/") or unit.exists() or sockunit.exists():
    raise RuntimeError("unsafe_or_preexisting_lab")
unit.write_text(source)
sockunit.write_text(sock)
agentstate = R / "var/lib/agent-center-agent"
agentstate.mkdir(exist_ok=True)
os.chown(agentstate, 65534, 65534)
agentstate.chmod(0o700)
base = [
    "-p",
    f"RootDirectory={R}",
    "-p",
    "MountAPIVFS=yes",
    "-p",
    "User=nobody",
    "-p",
    "Group=nogroup",
    "-p",
    "NoNewPrivileges=yes",
    "-p",
    "CapabilityBoundingSet=",
    "-p",
    "AmbientCapabilities=",
    "-p",
    "ProtectSystem=strict",
    "-p",
    "PrivateDevices=yes",
    "-p",
    f"BindPaths={socketpath}:/run/agent-center-mtr.sock {agentstate}:/var/lib/agent-center-agent",
]
script = R / "opt/agent-center-mtr/client.py"
script.write_text("""import json,os,socket,sys,time
sys.path.insert(0,'/opt/agent-center-mtr/helper.pyz')
from agent_center.mtr_client import exchange
c=json.load(open('/opt/agent-center-mtr/request.json'))
if c.get('action_worker'):
 import subprocess
 r=subprocess.run(['/usr/bin/python3','-I','/opt/agent-center-mtr/agent.pyz','--config','/etc/agent-center-agent/config.json','--action-worker'],input=json.dumps({'capability':'mtr_path_analysis:v1','input':c['request']['input']}),capture_output=True,text=True,timeout=22)
 if r.returncode:raise RuntimeError('worker failed')
 print(json.dumps({'uid':os.getuid(),'cap_eff':open('/proc/self/status').read().split('CapEff:')[1].splitlines()[0].strip(),'result':json.loads(r.stdout)}));raise SystemExit(0)
if c.get('disconnect'):
 s=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM);s.connect('/run/agent-center-mtr.sock');s.sendall(json.dumps(c['request']).encode()+b'\\n');time.sleep(1);s.close();print(json.dumps({'cancelled_by_client':True}));raise SystemExit(0)
try:r=exchange(c['request'])
except Exception:r={'ok':False,'error':'transport_closed'}
print(json.dumps({'uid':os.getuid(),'cap_eff':open('/proc/self/status').read().split('CapEff:')[1].splitlines()[0].strip(),'result':r}))
""")
script.chmod(0o644)
args = {
    "target": host,
    "destination": host,
    "protocol": "icmp",
    "cycles": 5,
    "max_hops": 16,
    "interval": 1,
}
results = []


def request(case, req, disconnect=False, action_worker=False):
    (R / "opt/agent-center-mtr/request.json").write_text(
        json.dumps({"request": req, "disconnect": disconnect, "action_worker": action_worker})
    )
    start = time.monotonic()
    p = subprocess.Popen(
        [
            "/usr/bin/systemd-run",
            "--quiet",
            "--wait",
            "--pipe",
            "--collect",
            "--unit",
            PREFIX + "-client",
            *base,
            "/usr/bin/python3",
            "-I",
            "/opt/agent-center-mtr/client.py",
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    observation = None
    if req.get("op") == "collect" and not disconnect:
        for _ in range(25):
            active = ctl(
                "list-units", PREFIX + "@*.service", "--plain", "--no-legend", "--state=running"
            ).stdout.splitlines()
            if active:
                name = active[0].split()[0]
                values = ctl(
                    "show",
                    name,
                    "-p",
                    "User",
                    "-p",
                    "NoNewPrivileges",
                    "-p",
                    "CapabilityBoundingSet",
                    "-p",
                    "AmbientCapabilities",
                    "-p",
                    "PrivateDevices",
                    "-p",
                    "MainPID",
                ).stdout
                observation = values.strip().splitlines()
                break
            time.sleep(0.1)
    stdout, stderr = p.communicate(timeout=25)
    if p.returncode:
        raise RuntimeError("client_unit_failed: " + stderr)
    result = {
        "case": case,
        "seconds": round(time.monotonic() - start, 3),
        "client": json.loads(stdout),
        "helper_properties": observation,
    }
    results.append(result)
    return result


try:
    ctl("daemon-reload")
    ctl("start", PREFIX + ".socket")
    request("ready", {"op": "ready", "protocol_version": 1})
    for protocol in ("icmp", "tcp"):
        (state / "rate").unlink(missing_ok=True)
        args["protocol"] = protocol
        r = request(protocol, {"op": "collect", "input": args})
        assert r["client"]["uid"] == 65534 and int(r["client"]["cap_eff"], 16) == 0
        assert r["client"]["result"]["ok"], r
    # Exercise the packaged Agent worker, including repeated availability checks, with zero capabilities.
    agentstate = R / "var/lib/agent-center-agent"
    agentstate.mkdir(exist_ok=True)
    os.chown(agentstate, 65534, 65534)
    agentstate.chmod(0o700)
    config = R / "etc/agent-center-agent/config.json"
    config.parent.mkdir(exist_ok=True)
    effective = {
        **policy,
        "central_url": "https://central.invalid",
        "state_dir": "/var/lib/agent-center-agent",
        "log_dir": "/var/log/agent-center-agent",
        "identity_file": "/var/lib/agent-center-agent/identity.json",
        "device_operations_frozen": True,
    }
    config.write_text(json.dumps(effective))
    os.chown(config, 65534, 65534)
    config.chmod(0o600)
    (creds / "local.json").write_text(json.dumps(effective))
    os.chown(creds / "local.json", 65534, 65534)
    (creds / "local.json").chmod(0o600)
    (state / "rate").unlink(missing_ok=True)
    r = request("packaged_agent_worker", {"op": "collect", "input": args}, action_worker=True)
    assert r["client"]["result"]["ok"], r
    # Two actual socket-activated helpers: one collector, the other denied by flock.
    for _ in range(30):
        if not ctl(
            "list-units",
            PREFIX + "@*.service",
            "--plain",
            "--no-legend",
            "--state=running,deactivating",
        ).stdout.strip():
            break
        time.sleep(0.1)
    (state / "rate").unlink(missing_ok=True)
    (R / "opt/agent-center-mtr/request.json").write_text(
        json.dumps({"request": {"op": "collect", "input": args}, "disconnect": False})
    )
    jobs = []
    for suffix in ("a", "b"):
        jobs.append(
            subprocess.Popen(
                [
                    "/usr/bin/systemd-run",
                    "--quiet",
                    "--wait",
                    "--pipe",
                    "--collect",
                    "--unit",
                    PREFIX + "-concurrent-" + suffix,
                    *base,
                    "/usr/bin/python3",
                    "-I",
                    "/opt/agent-center-mtr/client.py",
                ],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
            )
        )
    parallel = []
    for job in jobs:
        out, err = job.communicate(timeout=25)
        assert job.returncode == 0, err
        parallel.append(json.loads(out))
    assert sum(r["result"]["ok"] for r in parallel) == 1, parallel
    assert [r["result"].get("error") for r in parallel if not r["result"]["ok"]] == [
        "mtr_rate_limited"
    ], parallel
    results.append(
        {
            "case": "concurrent",
            "seconds": None,
            "client": {"result": {"ok": True, "outcomes": parallel}},
            "helper_properties": None,
        }
    )
    (state / "rate").unlink(missing_ok=True)
    request("cancel", {"op": "collect", "input": args}, True)
    for _ in range(30):
        if not ctl(
            "list-units",
            PREFIX + "@*.service",
            "--plain",
            "--no-legend",
            "--state=running,deactivating",
        ).stdout.strip():
            break
        time.sleep(0.1)
    else:
        raise AssertionError("cancel_left_running")
    request("rate", {"op": "collect", "input": args})
    request(
        "unauthorized",
        {"op": "collect", "input": {**args, "target": "127.0.0.1", "destination": "127.0.0.1"}},
    )
    request("arbitrary_operation", {"op": "exec", "command": "id"})
    # Same unit with raw networking explicitly removed must refuse before any probe.
    unit.write_text(source + "CapabilityBoundingSet=\nAmbientCapabilities=\n")
    ctl("daemon-reload")
    r = request("no_permission", {"op": "ready", "protocol_version": 1})
    assert r["client"]["result"]["error"] == "mtr_permission_denied"
    unit.write_text(source + "RuntimeMaxSec=2\n")
    ctl("daemon-reload")
    (state / "rate").unlink(missing_ok=True)
    r = request("global_timeout", {"op": "collect", "input": {**args, "cycles": 10}})
    assert not r["client"]["result"]["ok"] and r["seconds"] < 5
    secret = R / "var/lib/agent-center-agent/identity.json"
    secret.parent.mkdir(exist_ok=True)
    secret.write_text("LAB_ONLY_NO_TOKEN")
    os.chown(secret, 65534, 65534)
    secret.chmod(0o600)
    isolate = R / "opt/agent-center-mtr/isolation.py"
    isolate.write_text("""import json,os,socket
checks={}
try:os.setuid(0);checks['setuid_root_denied']=False
except PermissionError:checks['setuid_root_denied']=True
try:open('/var/lib/agent-center-agent/identity.json').read();checks['identity_read_denied']=False
except PermissionError:checks['identity_read_denied']=True
try:open('/opt/agent-center-mtr/helper.pyz','ab').close();checks['code_write_denied']=False
except (PermissionError,OSError):checks['code_write_denied']=True
try:s=socket.socket(socket.AF_PACKET,socket.SOCK_RAW);s.close();checks['packet_capture_family_denied']=False
except OSError:checks['packet_capture_family_denied']=True
checks['nonroot']=os.getuid()!=0
checks['effective_cap_net_raw_only']=int(open('/proc/self/status').read().split('CapEff:')[1].splitlines()[0].strip(),16)==8192
print(json.dumps(checks))
raise SystemExit(0 if all(checks.values()) else 2)
""")
    props = []
    for line in source.splitlines():
        if "=" in line and line.split("=", 1)[0] not in (
            "Description",
            "ExecStart",
            "StandardInput",
            "StandardOutput",
            "StandardError",
            "LoadCredential",
            "ReadWritePaths",
            "BindPaths",
        ):
            props += ["-p", line]
    iso = cmd(
        [
            "/usr/bin/systemd-run",
            "--quiet",
            "--wait",
            "--pipe",
            "--collect",
            "--unit",
            PREFIX + "-isolation",
            *props,
            "/usr/bin/python3",
            "-I",
            "/opt/agent-center-mtr/isolation.py",
        ]
    )
    results.append(
        {
            "case": "isolation",
            "seconds": None,
            "client": {"result": {"ok": True, "checks": json.loads(iso.stdout)}},
            "helper_properties": None,
        }
    )
    print(
        json.dumps(
            {
                "os": "Debian 13 rootfs",
                "systemd_manager": cmd(["/usr/bin/systemctl", "--version"]).stdout.splitlines()[0],
                "filecap": cmd(["/usr/sbin/getcap", str(R / "usr/bin/mtr-packet")]).stdout.strip(),
                "results": results,
            }
        )
    )
finally:
    (W / "native-partial.json").write_text(json.dumps({"results": results}))
    ctl("stop", PREFIX + ".socket", check=False)
    ctl("stop", PREFIX + "@*.service", check=False)
    ctl("reset-failed", PREFIX + "@*.service", check=False)
    unit.unlink(missing_ok=True)
    sockunit.unlink(missing_ok=True)
    ctl("daemon-reload")
