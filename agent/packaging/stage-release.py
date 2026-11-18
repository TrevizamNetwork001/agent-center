#!/usr/bin/env python3
"""Explicit operator-only staging. No downloads; no service restart or update."""

import argparse
import json
import os
from pathlib import Path

from agent_center.state import InstanceLock, atomic_write
from agent_center.update.engine import Engine, UpdateError, validate
from agent_center.update.remote import trusted


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--artifact", type=Path, required=True)
    p.add_argument("--sha256", required=True)
    p.add_argument("--version", required=True)
    args = p.parse_args()
    if os.geteuid() != 0:
        return 2
    base = Path("/opt/agent-center-agent")
    os.umask(0o077)
    lock = None
    try:
        trusted(base / "releases/catalog.json")
        engine = Engine(base, Path("/var/lib/agent-center-agent"))
        engine.dry_run(args.artifact, args.sha256)
        from agent_center.update.engine import self_check

        # dry_run executes a private snapshot with dropped privileges; never execute the original path.
        data = validate(args.artifact, args.sha256)
        lock = InstanceLock(base / "catalog.lock")
        destination = base / "releases" / (args.sha256 + ".pyz")
        if destination.exists():
            return 2
        destination.write_bytes(data)
        destination.chmod(0o644)
        checked = self_check(destination)
        if checked["version"] != args.version:
            destination.unlink()
            raise UpdateError("version_mismatch")
        catalog = json.loads((base / "releases/catalog.json").read_text())
        catalog.append(
            dict(version=args.version, sha256=args.sha256, device_operations_frozen=True)
        )
        atomic_write(
            base / "releases/catalog.json", catalog
        )  # atomic_write accepts JSON arrays as well.
        print('{"staged":true,"applied":false}')
        return 0
    except Exception:
        print('{"event":"stage_release_failed"}')
        return 2
    finally:
        if lock:
            lock.close()


if __name__ == "__main__":
    raise SystemExit(main())
