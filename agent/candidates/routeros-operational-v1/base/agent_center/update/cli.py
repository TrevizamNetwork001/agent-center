import argparse
import json
from pathlib import Path

from .engine import Engine


def main():
    p = argparse.ArgumentParser()
    p.add_argument("operation", choices=["update", "rollback"])
    p.add_argument("--artifact", type=Path)
    p.add_argument("--sha256")
    mode = p.add_mutually_exclusive_group()
    mode.add_argument("--apply", action="store_true")
    mode.add_argument("--dry-run", action="store_true")
    p.add_argument("--base", type=Path, default=Path("/opt/agent-center-agent"))
    p.add_argument("--state", type=Path, default=Path("/var/lib/agent-center-agent"))
    a = p.parse_args()
    if a.apply:
        # A local checksum is not release authorization. Installation and
        # rollback must use the authenticated, signed Lifecycle operation.
        print('{"error":"apply_requires_lifecycle"}')
        return 2
    try:
        engine = Engine(a.base, a.state)
        if a.operation == "update":
            if not a.artifact or not a.sha256:
                raise ValueError()
            result = engine.dry_run(a.artifact, a.sha256)
        else:
            result = engine.rollback(True)
        print(json.dumps(result))
        return 0
    except Exception:
        print('{"error":"update_refused_or_failed","secrets_logged":false}')
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
