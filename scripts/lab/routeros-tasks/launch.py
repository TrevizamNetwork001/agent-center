"""Wait for ephemeral fixture credentials, then run the unmodified Agent entrypoint."""
import os
import sys
import time
from pathlib import Path

config = Path('/lab/agent-private/config.json')
for _ in range(600):
    if config.is_file():
        os.execv(sys.executable, [sys.executable, '-m', 'agent_center.main', '--config', str(config)])
    time.sleep(.2)
raise SystemExit('lab_fixture_timeout')
