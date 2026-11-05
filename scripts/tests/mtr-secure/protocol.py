"""Disposable lab harness emulates Accept=yes and LoadCredential with distinct UIDs."""

import json
import os
import pathlib
import resource
import signal
import socket
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor

ROOT = pathlib.Path("/labwork")
ROOT.mkdir(exist_ok=True)
ROOT.chmod(0o755)
CRED = ROOT / "credentials"
CRED.mkdir(exist_ok=True)
CRED.chmod(0o700)
os.chown(CRED, 988, 988)
HOST = socket.getaddrinfo("google.com", 443, socket.AF_INET, socket.SOCK_STREAM)[0][4][0]
policy = {"allowed_networks": [HOST + "/32"], "allowed_dns_names": ["google.com"]}
for name, doc in [("local-policy", policy), ("ceiling", {"schema_version": 1, **policy})]:
    p = CRED / name
    p.write_text(json.dumps(doc))
    os.chown(p, 988, 988)
    p.chmod(0o400)
SOCK = ROOT / "probe.sock"
try:
    SOCK.unlink()
except FileNotFoundError:
    pass
listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
listener.bind(str(SOCK))
os.chown(SOCK, 987, 987)
SOCK.chmod(0o600)
listener.listen(2)
HELPER = [
    "setpriv",
    "--reuid",
    "988",
    "--regid",
    "988",
    "--clear-groups",
    "--inh-caps",
    "+net_raw",
    "--ambient-caps",
    "+net_raw",
    "--bounding-set",
    "-all,+net_raw",
    "/usr/bin/python3",
    "-I",
    "/build/helper.pyz",
]
CLIENT = [
    "setpriv",
    "--reuid",
    "987",
    "--regid",
    "987",
    "--clear-groups",
    "--bounding-set",
    "-all",
    "/usr/bin/python3",
    "-c",
]
args = {
    "target": HOST,
    "destination": HOST,
    "protocol": "icmp",
    "cycles": 5,
    "max_hops": 16,
    "interval": 1,
}
results = []


def serve():
    conn, _ = listener.accept()
    child = subprocess.Popen(
        HELPER,
        stdin=conn,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
        env={"PATH": "/usr/bin:/usr/local/bin:/bin", "CREDENTIALS_DIRECTORY": str(CRED)},
    )
    conn.close()
    code = child.wait(timeout=22)
    try:
        os.killpg(child.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    return code


def invoke(request, disconnect=False):
    script = 'import json,sys,socket,os;sys.path.insert(0,"/source");from agent_center.mtr_client import exchange;from pathlib import Path;print(json.dumps({"uid":os.getuid(),"caps":open("/proc/self/status").read().split("CapEff:")[1].splitlines()[0].strip(),"result":exchange(json.loads(sys.argv[1]),Path(sys.argv[2]))}))'
    if disconnect:
        script = 'import socket,sys,time;s=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM);s.connect(sys.argv[2]);s.sendall(sys.argv[1].encode()+b"\\n");time.sleep(1);s.close()'
    started = time.monotonic()
    with ThreadPoolExecutor(1) as pool:
        future = pool.submit(serve)
        client = subprocess.run(
            CLIENT + [script, json.dumps(request), str(SOCK)],
            capture_output=True,
            text=True,
            timeout=23,
        )
        code = future.result()
    if client.returncode:
        raise RuntimeError("client failed: " + client.stderr)
    return {
        "elapsed_seconds": round(time.monotonic() - started, 3),
        "service_exit": code,
        "client": json.loads(client.stdout) if not disconnect else "disconnected",
    }


# Successful collection is routed over the socket; principal always has zero capabilities.
for protocol in ("icmp", "tcp"):
    args["protocol"] = protocol
    result = invoke({"op": "collect", "input": args})
    results.append({"case": protocol, **result})
    pathlib.Path("/var/lib/agent-center-mtr/rate").unlink(missing_ok=True)
# No arbitrary operations, private targets or extra flags, even when client skips local validation.
for request in (
    {"op": "shell", "command": "id"},
    {"op": "collect", "input": {**args, "target": "127.0.0.1", "destination": "127.0.0.1"}},
    {"op": "collect", "input": {**args, "flags": "--udp"}},
):
    results.append({"case": "denied", **invoke(request)})
results.append({"case": "cancel", **invoke({"op": "collect", "input": args}, True)})
# Underlying helper mutex refuses a second overlapping or recent execution.
results.append({"case": "rate", **invoke({"op": "collect", "input": args})})
listener.close()
print(
    json.dumps(
        {
            "target_authorized_in_lab": True,
            "results": results,
            "children_usage": {
                "cpu_seconds": resource.getrusage(resource.RUSAGE_CHILDREN).ru_utime
                + resource.getrusage(resource.RUSAGE_CHILDREN).ru_stime,
                "peak_rss_kib": resource.getrusage(resource.RUSAGE_CHILDREN).ru_maxrss,
            },
        }
    )
)
