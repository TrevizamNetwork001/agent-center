import json
import sys
from pathlib import Path

from .capabilities import available, execute
from .config import Config
from .policy import Policy


def main(path):
    try:
        config = Config.load(Path(path))
        raw = sys.stdin.buffer.read(65537)
        if len(raw) > 65536:
            raise ValueError()
        task = json.loads(raw)
        policy = Policy(True, available(), True, True)
        result = execute(
            task["capability"],
            task["input"],
            policy,
            config.allowed_networks,
            config.allowed_dns_names,
        )
    except (OSError, ValueError, TypeError, KeyError):
        result = {"ok": False, "error": "internal_error"}
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1]))
