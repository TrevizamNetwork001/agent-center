"""Wait for ephemeral fixture credentials, then run the unmodified Agent entrypoint."""
import os
import sys
import time
from pathlib import Path

config = Path('/lab/agent-private/config.json')
for _ in range(600):
    if config.is_file():
        artifact = Path('/integration-candidate/agent.pyz')
        if artifact.is_file():
            from agent_center.lifecycle.releases import verify
            bundle = artifact.parent
            verify((bundle / 'manifest.json').read_bytes(),
                   (bundle / 'signature.base64').read_text().strip(),
                   (bundle / 'signer-public.pem').read_bytes(), artifact.read_bytes(),
                   minimum_sequence=0, platform='debian13')
        entry = ['-I', str(artifact)] if artifact.is_file() else ['-m', 'agent_center.main']
        os.execv(sys.executable, [sys.executable, *entry, '--config', str(config)])
    time.sleep(.2)
raise SystemExit('lab_fixture_timeout')
