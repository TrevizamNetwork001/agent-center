import argparse
import json
import os
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
    if a.apply and (
        os.geteuid() != 0
        or a.base != Path("/opt/agent-center-agent")
        or a.state != Path("/var/lib/agent-center-agent")
    ):
        print('{"error":"apply_requires_root_and_fixed_paths"}')
        return 2
    try:
        engine = Engine(a.base, a.state)
        if a.operation == "update":
            if not a.artifact or not a.sha256:
                raise ValueError()
            result = (
                engine.apply(a.artifact, a.sha256)
                if a.apply
                else engine.dry_run(a.artifact, a.sha256)
            )
        else:
            result = engine.rollback(not a.apply)
        print(json.dumps(result))
        return 0
    except Exception:
        print('{"error":"update_refused_or_failed","secrets_logged":false}')
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
