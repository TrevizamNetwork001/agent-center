import fcntl
import json
import sys
import time
from pathlib import Path

from .capabilities import available, execute
from .config import Config
from .policy import Policy
from .state import StateError


def main(path):
    try:
        config = Config.load(Path(path))
        raw = sys.stdin.buffer.read(65537)
        if len(raw) > 65536:
            raise ValueError()
        task = json.loads(raw)
        policy = Policy(True, available(), True, True)
        if task.get("capability") == "mtr_path_analysis:v1":
            # Private state file, cross-process exclusion and cooldown survive worker restarts.
            with (config.state_dir / "mtr-rate").open("a+") as rate:
                try:
                    fcntl.flock(rate, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    print(json.dumps({"ok": False, "error": "mtr_rate_limited"}))
                    return 0
                rate.seek(0)
                previous = rate.read(64)
                if previous and time.time() - float(previous) < 10:
                    print(json.dumps({"ok": False, "error": "mtr_rate_limited"}))
                    return 0
                rate.seek(0)
                rate.truncate()
                rate.write(str(time.time()))
                rate.flush()
                result = execute(
                    task["capability"],
                    task["input"],
                    policy,
                    config.allowed_networks,
                    config.allowed_dns_names,
                    False,
                )
                print(json.dumps(result))
                return 0
        result = execute(
            task["capability"],
            task["input"],
            policy,
            config.allowed_networks,
            config.allowed_dns_names,
            config.auto_public_targets,
        )
    except (OSError, ValueError, TypeError, KeyError, StateError):
        result = {"ok": False, "error": "internal_error"}
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1]))
