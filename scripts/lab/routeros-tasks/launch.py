"""Wait for ephemeral fixture credentials, then run the unmodified Agent entrypoint."""
import os
import sys
import time
from pathlib import Path

config = Path('/lab/agent-private/config.json')
for _ in range(4500):
    if config.is_file():
        artifact = Path('/integration-candidate/agent.pyz')
        metadata = artifact.parent
        installed = Path('/run/routeros066-upgrade/base/runtime/agent.pyz')
        if installed.is_file():
            artifact = installed
            metadata = (Path('/integration-baseline') if __import__('hashlib').sha256(artifact.read_bytes()).hexdigest()
                        == 'a2de84beb6cb519047c6123423fb420d9d410b41b85c6c9b4a49df63b562d315'
                        else Path('/integration-candidate'))
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
