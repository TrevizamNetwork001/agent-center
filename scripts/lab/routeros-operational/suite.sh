#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
# Identical tests; their existing packaging path resolves to candidate sources.
suite_dir=$(mktemp -d /tmp/routeros066-suite.XXXXXXXX)
chmod 755 "$suite_dir"
mkdir "$suite_dir/packaging" "$suite_dir/src"
cp -R agent/tests "$suite_dir/tests"
cp agent/candidates/routeros-operational-v1/packaging/build.py "$suite_dir/packaging/"
docker run --rm --network none --user 65534:65534 \
  --mount "type=bind,src=$suite_dir,dst=/agent,readonly" \
  --mount "type=bind,src=$PWD/agent/candidates/routeros-operational-v1/src,dst=/agent/src,readonly" \
  -e PYTHONDONTWRITEBYTECODE=1 -e PYTHONPATH=/agent/src \
  agent-center-python-tools:0.5.2 python -m unittest discover -s /agent/tests -v
