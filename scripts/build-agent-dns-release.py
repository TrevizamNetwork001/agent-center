"""Verify the immutable historical 0.5.4 package; new releases use build-agent-release.py."""

import hashlib
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
artifact = root / 'artifacts/agentkit/0.5.4/agent.pyz'
expected = 'bb8c0274b9fc2121c283136b2ad66a9b67037c0c7d32e44f79f44b5d33f5cb19'
if not artifact.is_file() or hashlib.sha256(artifact.read_bytes()).hexdigest() != expected:
    raise SystemExit('historical_artifact_integrity_failed')
print(json.dumps({'version': '0.5.4', 'sha256': expected, 'immutable': True}))
