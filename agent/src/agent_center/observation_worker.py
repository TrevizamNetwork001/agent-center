"""Fixed read-only inspector, isolated so DNS/helper stalls cannot hold heartbeat."""

import json
import os
import signal
import subprocess
import sys
import tempfile
from pathlib import Path

from . import observability

LIMIT = 32768


def inspect(operation, values, timeout):
    entry = Path(sys.argv[0])
    command = (
        [sys.executable, "-I", str(entry), "--observation-worker"]
        if entry.suffix == ".pyz"
        else [sys.executable, "-m", "agent_center.observation_worker"]
    )
    raw = json.dumps({"operation": operation, "values": values}).encode()
    if len(raw) > 65536:
        return None
    child = None
    try:
        # File-backed output avoids pipe deadlock and unbounded communicate buffers.
        with tempfile.TemporaryFile() as output:
            child = subprocess.Popen(
                command, stdin=subprocess.PIPE, stdout=output,
                stderr=subprocess.DEVNULL, start_new_session=True,
            )
            child.communicate(raw, timeout=timeout)
            if child.returncode or output.tell() > LIMIT:
                return None
            output.seek(0)
            value = json.loads(output.read(LIMIT + 1))
            return value if isinstance(value, dict) else None
    except (OSError, ValueError, TypeError, RecursionError, subprocess.SubprocessError):
        return None
    finally:
        if child is not None:
            # Close the group even if its leader already exited.
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            child.wait(timeout=3)


def bounded_snapshot(state_dir, policy, *, timeout=2.0):
    value = None
    if state_dir is not None:
        value = inspect("snapshot", {"state_dir": str(state_dir), "policy": policy}, timeout)
    return value or {
        "schema_version": observability.SCHEMA,
        "collected_at": observability.timestamp(),
        "last_error": "STATUS_UNKNOWN",
    }


def bounded_check(request, config_path, policy, agent_id, *, timeout=2.0):
    value = inspect("check", {
        "request": request, "config_path": config_path,
        "policy": policy, "agent_id": agent_id,
    }, timeout)
    if value is not None:
        return value
    if not isinstance(request, dict) or not isinstance(request.get("id"), str):
        return None
    return {
        "id": request["id"], "state": "UNKNOWN", "reason": "STATUS_UNKNOWN",
        "layer": None, "checked_at": observability.timestamp(),
        "revision": None, "digest": None,
    }


def main():
    if os.geteuid() == 0:
        return 2
    try:
        raw = sys.stdin.buffer.read(65537)
        if len(raw) > 65536:
            return 2
        payload = json.loads(raw)
        values = payload["values"]
        if payload["operation"] == "snapshot":
            result = observability.snapshot(Path(values["state_dir"]), values["policy"])
        elif payload["operation"] == "check":
            result = observability.check(
                values["request"], values["config_path"], values["policy"], values["agent_id"]
            )
        else:
            return 2
        encoded = json.dumps(result).encode()
        if len(encoded) > LIMIT:
            return 2
        sys.stdout.buffer.write(encoded)
        return 0
    except (OSError, ValueError, TypeError, KeyError, RecursionError):
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
