"""Wait for ephemeral fixture credentials, then run the unmodified Agent entrypoint."""
import hashlib
import json
import os
import sys
import time
from pathlib import Path

config = Path('/lab/agent-private/config.json')
for _ in range(4500):
    if config.is_file():
        artifact = Path('/integration-candidate/agent.pyz')
        metadata = artifact.parent
        installed = Path('/opt/agent-center-agent/runtime/agent.pyz')
        if not installed.is_file():
            installed = Path('/run/routeros066-upgrade/base/runtime/agent.pyz')
        if installed.is_file():
            artifact = installed
            digest = hashlib.sha256(artifact.read_bytes()).hexdigest()
            metadata = next(directory for directory in (
                Path('/integration-baseline'), Path('/integration-bridge'), Path('/integration-candidate'),
                Path('/lab/panel-packages/restore'),
            ) if (directory / 'manifest.json').is_file()
                and json.loads((directory / 'manifest.json').read_text())['sha256'] == digest)
        if artifact.is_file():
            from agent_center.lifecycle.releases import verify
            bundle = metadata
            verify((bundle / 'manifest.json').read_bytes(),
                   (bundle / 'signature.base64').read_text().strip(),
                   (bundle / 'signer-public.pem').read_bytes(), artifact.read_bytes(),
                   minimum_sequence=0, platform='debian13')
        entry = ['-I', str(artifact)] if artifact.is_file() else ['-m', 'agent_center.main']
        os.execv(sys.executable, [sys.executable, *entry, '--config', str(config)])
    time.sleep(.2)
raise SystemExit('lab_fixture_timeout')
