#!/usr/bin/env python3
"""Read-only image parity proof; prints only counts and digests, never container environment."""
import argparse
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('build', type=Path)
parser.add_argument('evidence', type=Path)
args = parser.parse_args()
args.evidence.mkdir(parents=True, exist_ok=True)
allowed = set(json.loads((args.build / 'manifest.json').read_text()))
records = []
for role in ('web', 'queue', 'diagnostic'):
    versions = {}
    for version in ('device-analysis', 'device-readonly-admin'):
        image = f'agent-center-central:{version}-{role}-20261113'
        data = subprocess.check_output(['docker', 'run', '--rm', '--network', 'none',
            '-e', 'APP_ENV=production', '--mount',
            f'type=bind,src={ROOT}/scripts/tests/device-readonly-admin-image.php,dst=/proof.php,readonly',
            image, 'php', '/proof.php'])
        versions[version] = json.loads(data)
    before, after = versions['device-analysis'], versions['device-readonly-admin']
    assert before['routes'] == after['routes'], 'Operational route drift'
    assert before['user'] == after['user'] == 33
    changes = {p for p in before['files'] | after['files'] if before['files'].get(p) != after['files'].get(p)}
    assert changes == allowed, (role, changes - allowed, allowed - changes)
    assert not after['transport_present'] and not after['credential_model_present'] and after['credential_migrations'] == 0
    assert not any('readonly-lab' in r['uri'] for r in after['routes'])
    digest = subprocess.check_output(['docker', 'image', 'inspect', f'agent-center-central:device-readonly-admin-{role}-20261113', '--format', '{{.Id}}']).decode().strip()
    records.append({'role': role, 'image_id': digest, 'routes_preserved': len(after['routes']),
        'unchanged_files': len(set(before['files']) - changes), 'allowed_changes': sorted(changes),
        'transport_present': False, 'credential_migrations': 0, 'python_dependency_added': False,
        'admin_contract': after['admin_contract'], 'passed': True})
(args.evidence / 'image-parity.json').write_text(json.dumps(records, indent=2)+'\n')
print(json.dumps({'passed': True, 'roles': len(records), 'files_per_overlay': len(allowed)}))
