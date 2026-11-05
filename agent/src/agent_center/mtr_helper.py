"""Socket-activated non-root MTR service; the principal Agent never gets raw sockets."""

import fcntl
import json
import os
import pwd
import select
import signal
import socket
import struct
import time
from pathlib import Path

from . import mtr

REQUEST_LIMIT = 4096
RESPONSE_LIMIT = 65536


def disconnected(connection):
    if not select.select([connection], [], [], 0)[0]:
        return False
    try:
        # Any extra request bytes also abort: exactly one operation per connection.
        connection.recv(1, socket.MSG_PEEK | socket.MSG_DONTWAIT)
        return True
    except BlockingIOError:
        return False


def receive(connection):
    connection.settimeout(2)
    raw = bytearray()
    while b"\n" not in raw:
        chunk = connection.recv(min(1024, REQUEST_LIMIT + 1 - len(raw)))
        if not chunk:
            raise mtr.MtrError("mtr_invalid_input")
        raw.extend(chunk)
        if len(raw) > REQUEST_LIMIT:
            raise mtr.MtrError("mtr_invalid_input")
    if raw.count(b"\n") != 1 or not raw.endswith(b"\n"):
        raise mtr.MtrError("mtr_invalid_input")
    request = json.loads(raw)
    if not isinstance(request, dict):
        raise mtr.MtrError("mtr_invalid_input")
    return request


def policies(credentials):
    # LoadCredential copies a fresh snapshot for every accepted connection; callers
    # cannot choose a file. No identity/token credential is ever loaded.
    def load(name):
        with (credentials / name).open("rb") as stream:
            raw = stream.read(65537)
        if len(raw) > 65536:
            raise mtr.MtrError("policy_denied")
        return json.loads(raw)

    local = load("local-policy")
    ceiling = load("ceiling")
    if (
        set(ceiling) != {"schema_version", "allowed_networks", "allowed_dns_names"}
        or type(ceiling["schema_version"]) is not int
        or ceiling["schema_version"] != 1
    ):
        raise mtr.MtrError("policy_denied")
    for policy in (local, ceiling):
        if (
            not isinstance(policy, dict)
            or not isinstance(policy.get("allowed_networks"), list)
            or not isinstance(policy.get("allowed_dns_names"), list)
        ):
            raise mtr.MtrError("policy_denied")
        if any(
            not isinstance(value, str) or len(value) > 253
            for key in ("allowed_networks", "allowed_dns_names")
            for value in policy[key]
        ):
            raise mtr.MtrError("policy_denied")
        if len(policy["allowed_networks"]) > 256 or len(policy["allowed_dns_names"]) > 256:
            raise mtr.MtrError("policy_denied")
    return local, ceiling


def locked_rate(state):
    fd = os.open(state / "rate", os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    handle = os.fdopen(fd, "r+")
    try:
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        previous = handle.read(256)
        boot = Path("/proc/sys/kernel/random/boot_id").read_text().strip()
        now = time.monotonic()
        if previous:
            stored = json.loads(previous)
            if stored["boot"] == boot and now - stored["at"] < 10:
                raise mtr.MtrError("mtr_rate_limited")
        handle.seek(0)
        handle.truncate()
        handle.write(json.dumps({"boot": boot, "at": now}))
        handle.flush()
        return handle
    except (BlockingIOError, ValueError, KeyError, TypeError):
        handle.close()
        raise mtr.MtrError("mtr_rate_limited") from None


def serve(connection, credentials, state, client_uid):
    deadline = time.monotonic() + 18
    peer = struct.unpack("3i", connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
    if os.geteuid() == 0 or peer[1] != client_uid or peer[1] == os.geteuid():
        raise mtr.MtrError("mtr_permission_denied")
    request = receive(connection)
    local, ceiling = policies(credentials)
    if request == {"op": "ready", "protocol_version": 1}:
        if not mtr.raw_ready():
            raise mtr.MtrError("mtr_permission_denied")
        return {"ok": True, "protocol_version": 1, "capability": mtr.CAPABILITY}
    if (
        set(request) != {"op", "input"}
        or request["op"] != "collect"
        or not isinstance(request["input"], dict)
    ):
        raise mtr.MtrError("mtr_invalid_input")
    args = request["input"]
    for policy in (local, ceiling):
        mtr.authorize(args, policy["allowed_networks"], policy["allowed_dns_names"])
    if not mtr.raw_ready():
        raise mtr.MtrError("mtr_permission_denied")
    rate = locked_rate(state)
    try:
        result = mtr.collect(
            args,
            local["allowed_networks"],
            local["allowed_dns_names"],
            cancel=lambda: disconnected(connection),
            deadline=deadline,
        )
        return {"ok": True, "data": result}
    finally:
        rate.close()


def main():
    # Unit provides these absolute trusted paths; the wire protocol has no paths.
    os.umask(0o077)
    connection = socket.socket(fileno=0)

    def expired(_signum, _frame):
        raise mtr.MtrError("execution_timeout")

    signal.signal(signal.SIGALRM, expired)
    signal.alarm(19)
    try:
        result = serve(
            connection,
            Path(os.environ["CREDENTIALS_DIRECTORY"]),
            Path("/var/lib/agent-center-mtr"),
            pwd.getpwnam("agent-center-agent").pw_uid,
        )
    except mtr.MtrError as error:
        result = {"ok": False, "error": str(error)}
    except (OSError, ValueError, KeyError, TypeError, RecursionError):
        result = {"ok": False, "error": "mtr_execution_failed"}
    try:
        connection.settimeout(1)
        raw = json.dumps(result, separators=(",", ":")).encode() + b"\n"
        if len(raw) > RESPONSE_LIMIT:
            raw = b'{"ok":false,"error":"mtr_output_limit"}\n'
        connection.sendall(raw)
    except OSError:
        pass
    finally:
        signal.alarm(0)
        connection.close()
    return 0
