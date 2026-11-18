import hashlib
import http.client
import json
from pathlib import Path

from agent_center.api import Client
from agent_center.update.engine import Engine
from agent_center.update.remote import Remote

NAME = "agent-center-recovery7-update-agent-lab"


class DockerConnection(http.client.HTTPConnection):
    def connect(self):
        import socket

        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(40)
        self.sock.connect("/var/run/docker.sock")


def request(method, operation):
    conn = DockerConnection("localhost")
    conn.request(method, "/containers/" + NAME + "/" + operation)
    response = conn.getresponse()
    data = response.read()
    status = response.status
    conn.close()
    if status not in (200, 204, 304):
        raise RuntimeError("dedicated_lab_service_failure")
    return json.loads(data) if data else None


class Service:
    def active(self):
        return request("GET", "json")["State"]["Running"]

    def stop(self):
        request("POST", "stop?t=15")

    def start(self):
        request("POST", "start")


identity = json.loads(Path("/lab/state/identity.json").read_text())
catalog = json.loads(Path("/install/releases/catalog.json").read_text())
remote = Remote(
    Engine("/install", "/lab/state", Service()),
    Client("http://127.0.0.1:18084", identity["token"], retries=0),
    catalog,
    Path("/lab/control"),
    Path("/lab/configuration"),
)
proof = Path("/lab/control/identity-before.sha256")
current = hashlib.sha256(Path("/lab/state/identity.json").read_bytes()).hexdigest()
if not proof.exists():
    proof.write_text(current)
assert remote.run(), "companion_did_not_confirm_report"
assert (
    hashlib.sha256(Path("/lab/state/identity.json").read_bytes()).hexdigest()
    == proof.read_text()
), "identity_changed"
print("Identity preservation verified")
print("Live companion HTTP round-trip completed; no credentials emitted")
