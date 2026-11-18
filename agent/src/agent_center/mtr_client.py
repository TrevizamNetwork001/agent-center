"""Unprivileged, fixed Unix-socket protocol. No sudo or subprocess fallback."""

import json
import os
import socket
import stat
import time
from pathlib import Path

from . import mtr

SOCKET = Path("/run/agent-center-mtr.sock")
LIMIT = 65536
_READINESS = (0.0, False)


def exchange(request, path=SOCKET, *, timeout=19):
    try:
        # The socket owner can connect, but cannot replace it in a root-owned parent.
        parent = path.parent.stat()
        node = path.lstat()
        if (
            parent.st_uid != 0
            or parent.st_mode & 0o022
            or not stat.S_ISSOCK(node.st_mode)
            or node.st_uid != os.geteuid()
            or node.st_mode & 0o077
        ):
            raise mtr.MtrError("mtr_permission_denied")
        raw = json.dumps(request, separators=(",", ":")).encode() + b"\n"
        if len(raw) > 4096:
            raise mtr.MtrError("mtr_invalid_input")
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
            deadline = time.monotonic() + timeout
            client.settimeout(timeout)
            client.connect(str(path))
            client.settimeout(max(0.01, deadline - time.monotonic()))
            client.sendall(raw)
            output = bytearray()
            while not output.endswith(b"\n"):
                client.settimeout(max(0.01, deadline - time.monotonic()))
                chunk = client.recv(min(4096, LIMIT + 1 - len(output)))
                if not chunk or len(output) > LIMIT or time.monotonic() >= deadline:
                    raise mtr.MtrError("mtr_execution_failed")
                output.extend(chunk)
                if len(output) > LIMIT:
                    raise mtr.MtrError("mtr_output_limit")
        result = json.loads(output)
        if not isinstance(result, dict) or type(result.get("ok")) is not bool:
            raise mtr.MtrError("mtr_invalid_output")
        return result
    except mtr.MtrError:
        raise
    except (OSError, ValueError, TypeError, RecursionError):
        raise mtr.MtrError("mtr_execution_failed") from None


def ready():
    global _READINESS
    now = time.monotonic()
    if now - _READINESS[0] < 1:
        return _READINESS[1]
    try:
        value = exchange({"op": "ready", "protocol_version": 1}, timeout=1) == {
            "ok": True,
            "protocol_version": 1,
            "capability": mtr.CAPABILITY,
        }
    except mtr.MtrError:
        value = False
    _READINESS = (time.monotonic(), value)
    return value


def collect(args, networks, dns_names):
    host = mtr.authorize(args, networks, dns_names)
    result = exchange({"op": "collect", "input": args})
    if not result["ok"]:
        error = result.get("error")
        if error not in {
            "policy_denied",
            "mtr_permission_denied",
            "mtr_invalid_input",
            "mtr_rate_limited",
            "execution_timeout",
            "cancelled",
            "mtr_not_installed",
            "mtr_unsupported",
            "mtr_invalid_output",
            "mtr_output_limit",
            "mtr_execution_failed",
        }:
            error = "mtr_execution_failed"
        raise mtr.MtrError(error)
    data = result.get("data")
    if (
        not isinstance(data, dict)
        or any(data.get(key) != args[key] for key in ("destination", "protocol", "cycles"))
        or data.get("resolved_ip") != host
    ):
        raise mtr.MtrError("mtr_invalid_output")
    mtr.parse(json.dumps(data.get("raw_sanitized")), host, args["cycles"], args["max_hops"])
    return data
