#!/usr/bin/env python3
"""Create a fixed administrative overlay; never copy the workspace or device runtime wholesale."""
import hashlib
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = [
    'app/app/Noc/ReadOnlyAdmin.php',
    'app/resources/views/noc/readonly-admin.blade.php',
    'app/app/Http/Requests/NocDeviceRequest.php',
    'app/resources/views/noc/form.blade.php',
    'app/public/noc-inventory-form.js',
    'app/public/noc-inventory-form.css',
    'app/app/Noc/Analysis/Evidence.php',
    'app/app/Noc/Analysis/Interpretation.php',
    'app/resources/noc-catalog.json',
    'app/app/Llm/SecretVault.php',
]


def prepare():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('destination', type=Path)
    args = parser.parse_args()
    args.destination.mkdir(mode=0o700, parents=True, exist_ok=False)
    manifest = {}
    for name in FILES:
        data = (ROOT / name).read_bytes()
        target = args.destination / 'overlay' / name.removeprefix('app/')
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        target.chmod(0o644)
        manifest[name.removeprefix('app/')] = hashlib.sha256(data).hexdigest()
    # Preserve the published inventory view and all its existing behavior.
    # Only add the administrative panel to the immutable baseline image.
    baseline = subprocess.check_output([
        'docker', 'run', '--rm', '--network', 'none',
        'agent-center-central:device-analysis-web-20261113',
        'cat', '/var/www/html/resources/views/noc/show.blade.php',
    ])
    anchor = b"@include('noc.analysis')"
    if baseline.count(anchor) != 1 or b'readonly-lab' in baseline:
        raise RuntimeError('Published inventory view does not match the reviewed baseline')
    data = baseline.replace(anchor, b"@include('noc.readonly-admin')\n" + anchor)
    target = args.destination / 'overlay/resources/views/noc/show.blade.php'
    target.write_bytes(data)
    target.chmod(0o644)
    manifest['resources/views/noc/show.blade.php'] = hashlib.sha256(data).hexdigest()
    (args.destination / 'Dockerfile').write_bytes((ROOT / 'deploy/device-readonly-admin/Dockerfile').read_bytes())
    (args.destination / 'manifest.json').write_text(json.dumps(manifest, indent=2)+'\n')
    print(json.dumps({'administrative_files': len(manifest), 'python_runtime': False,
                      'transport': False, 'credential_migration': False, 'route_changes': False}))


if __name__ == '__main__':
    prepare()
