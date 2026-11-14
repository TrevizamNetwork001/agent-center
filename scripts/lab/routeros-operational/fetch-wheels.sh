#!/bin/bash
# Technical build step only. No Agent task invokes pip or this script.
set -euo pipefail
cd "$(dirname "$0")/../../.."
work=$(mktemp -d /tmp/routeros-wheel-build.XXXXXXXX)
chmod 755 "$work"
python3 - "$work/requirements.txt" <<'PY'
import json
import sys
from pathlib import Path
lock = json.loads(Path('agent/candidates/routeros-operational-v1/ssh-dependencies.lock.json').read_text())
Path(sys.argv[1]).write_text(''.join(f"{name}=={p['version']} --hash=sha256:{p['sha256']}\n" for name, p in sorted(lock['packages'].items())))
PY
docker run --rm --network bridge \
 --mount "type=bind,src=$work,dst=/wheels" \
 agent-center-routeros-debian13:lab \
 python3 -m pip download --only-binary=:all: --no-deps --require-hashes \
 --dest /wheels -r /wheels/requirements.txt
python3 scripts/lab/routeros-operational/bundle.py "$work" "$work/ssh-dependencies.tar" \
 agent/candidates/routeros-operational-v1/ssh-dependencies.lock.json
printf 'LAB dependency bundle: %s/ssh-dependencies.tar\n' "$work"
