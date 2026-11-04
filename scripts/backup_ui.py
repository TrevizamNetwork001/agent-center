#!/usr/bin/env python3
"""Root backup runner consumes Laravel's fixed encrypted infrastructure configuration."""
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path

from backup_r2 import ROOT, atomic_json, load_config, run, status_update


def command(container, *args):
    return subprocess.run(['docker', 'exec', '--user', '0:33', container, *args],
                          capture_output=True, check=False, timeout=30)


def main():
    project = os.environ.get('COMPOSE_PROJECT_NAME', 'agent-center')
    if not re.fullmatch(r'agent-center(?:-[a-z0-9-]+)?', project):
        return 1
    container = project + '-web-1'
    # 4 denotes no UI configuration. Docker errors must never look like missing configuration.
    exists = command(container, 'sh', '-c',
                     'if [ -f storage/app/platform-backups/r2.enc ]; then exit 0; else exit 4; fi')
    if exists.returncode == 4:
        return 4
    if exists.returncode:
        status_update(ROOT, 'NO-GO', 'r2_ui_bridge_unavailable')
        return 1
    try:
        exported = command(container, 'php', 'artisan', 'agent-center:r2-export')
        if exported.returncode == 2:
            status_update(ROOT, 'ATENÇÃO', 'EXTERNAL BACKUP NOT CONFIGURED')
            print('R2 settings saved in UI; public recipient/custody or credentials remain incomplete.')
            return 2
        if exported.returncode:
            raise ValueError('ui_export_failed')
        with tempfile.TemporaryDirectory(prefix='r2-ui-private-', dir=ROOT / 'backups') as directory:
            os.chmod(directory, 0o700)
            copied = subprocess.run(['docker', 'cp', container + ':/run/agent-center-r2-export/.', directory],
                                    capture_output=True, timeout=30)
            if copied.returncode:
                raise ValueError('ui_export_copy_failed')
            target = Path(directory)
            config_path = target / 'r2-backup.json'
            config = json.loads(config_path.read_text())
            config['credentials_file'] = str(target / 'r2-credentials.json')
            config['recipient_file'] = str(target / 'backup-recipient.txt')
            atomic_json(config_path, config)
            config, credentials = load_config(config_path)
            run(ROOT, config, credentials)
    except Exception:
        status_update(ROOT, 'NO-GO', 'r2_ui_backup_failed')
        print('R2 backup from UI configuration FAILED; sensitive details withheld.')
        return 1
    finally:
        command(container, 'php', 'artisan', 'agent-center:r2-export', '--clear')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
