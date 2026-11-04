#!/usr/bin/env python3
"""Project-local age/SDK bootstrap. No system packages or service changes."""
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AGE_URL = 'https://deb.debian.org/debian/pool/main/a/age/age_1.2.1-1+b5_amd64.deb'
AGE_SHA256 = 'a87ab5ba69a99bc6d8c95935ae467fa877daef50d11d9dabe0520ae01411f85c'


def main():
    tools = ROOT / 'tools'
    tools.mkdir(mode=0o700, exist_ok=True)
    tools.chmod(0o700)
    with tempfile.TemporaryDirectory(dir=tools, prefix='bootstrap-') as directory:
        package = Path(directory) / 'age.deb'
        with urllib.request.urlopen(AGE_URL, timeout=60) as response, package.open('wb') as out:
            while data := response.read(1024 * 1024):
                out.write(data)
        with package.open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() != AGE_SHA256:
                raise ValueError('tool_integrity_failed')
        subprocess.run(['dpkg-deb', '-x', str(package), str(tools)], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    destination = tools / 'backup-python'
    # The container is used only to install pure-Python dependencies; it never sees secrets.
    subprocess.run(['docker', 'run', '--rm', '--cap-drop', 'ALL',
                    '--security-opt', 'no-new-privileges:true',
                    '--mount', f'type=bind,src={ROOT / "deploy/backup-requirements.lock"},dst=/requirements.lock,readonly',
                    '--mount', f'type=bind,src={tools},dst=/tools',
                    'agent-center-python-tools:0.5.2', 'python', '-m', 'pip', 'install',
                    '--index-url', 'https://pypi.org/simple', '--disable-pip-version-check',
                    '--no-cache-dir', '--require-hashes', '--only-binary=:all:',
                    '--target', '/tools/backup-python', '-r', '/requirements.lock'],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    metadata = dict(age_version='1.2.1', age_package_sha256=AGE_SHA256,
                    sdk_lock_sha256=hashlib.sha256((ROOT / 'deploy/backup-requirements.lock').read_bytes()).hexdigest())
    (tools / 'backup-tools.json').write_text(json.dumps(metadata) + '\n')
    os.chmod(tools / 'backup-tools.json', 0o600)
    if not destination.is_dir():
        raise ValueError('sdk_install_failed')
    print('Project-local age and pinned S3 SDK: installed. No credentials read.')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('Backup tooling installation FAILED; sensitive diagnostics withheld.')
        sys.exit(1)
