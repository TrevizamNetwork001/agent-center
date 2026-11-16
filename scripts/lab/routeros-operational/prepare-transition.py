"""Build a signed Python-only transition + SSH target for disposable panel E2E."""
import base64
import hashlib
import importlib.util
import io
import json
import os
import subprocess
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
CANDIDATE = ROOT / 'agent/candidates/routeros-operational-v1'


def prepare(output, wheels, fault=False, baseline=None):
    output.mkdir(mode=0o755, parents=True, exist_ok=False)
    spec = importlib.util.spec_from_file_location('candidate_build', CANDIDATE / 'packaging/build.py')
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    private = output.parent / (output.name + '-private.pem')
    descriptor = os.open(private, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    os.close(descriptor)
    subprocess.run(['/usr/bin/openssl', 'genpkey', '-algorithm', 'ED25519', '-out', str(private)], check=True, capture_output=True)
    public = subprocess.run(['/usr/bin/openssl', 'pkey', '-in', str(private), '-pubout'], check=True, capture_output=True).stdout
    public_raw = base64.b64decode(b''.join(public.splitlines()[1:-1]))[-32:]
    (output / 'public-raw.base64').write_text(base64.b64encode(public_raw).decode())
    bridge_sha = None
    stages = [('bridge', 7), ('target', 8)] + ([('restore', 9)] if baseline else [])
    for name, sequence in stages:
        bundle = output / name
        bundle.mkdir(mode=0o755)
        if name == 'restore':
            original = (baseline / 'agent.pyz').read_bytes()
            if hashlib.sha256(original).hexdigest() != 'a2de84beb6cb519047c6123423fb420d9d410b41b85c6c9b4a49df63b562d315':
                raise ValueError('original_065_required')
            # The catalog keys artifacts by digest. Preserve every original member,
            # while distinguishing this newly authorized restoration publication.
            with zipfile.ZipFile(io.BytesIO(original)) as source:
                restored = io.BytesIO()
                restored.write(b'#!/usr/bin/env python3\n')
                with zipfile.ZipFile(restored, 'w') as archive:
                    for member in source.infolist():
                        archive.writestr(member, source.read(member))
                    archive.comment = b'Agent Center signed restoration 0.6.5 sequence 9'
            (bundle / 'agent.pyz').write_bytes(restored.getvalue())
        else:
            builder.build(bundle / 'agent.pyz', wheels=wheels if name == 'target' else None)
        if name == 'target' and fault:
            # Signed laboratory-only startup failure; validation still runs normally.
            source = (bundle / 'agent.pyz').read_bytes()
            out = io.BytesIO()
            out.write(b'#!/usr/bin/env python3\n')
            with zipfile.ZipFile(io.BytesIO(source)) as old, zipfile.ZipFile(out, 'w') as new:
                for member in old.infolist():
                    data = old.read(member)
                    if member.filename == '__main__.py':
                        data = b'import sys\nif "--self-check" not in sys.argv:\n    raise SystemExit(2)\n' + data
                    new.writestr(member, data)
            (bundle / 'agent.pyz').write_bytes(out.getvalue())
        data = (bundle / 'agent.pyz').read_bytes()
        sha = hashlib.sha256(data).hexdigest()
        doc = {'schema_version': 1, 'sequence': sequence, 'version': '0.6.5' if name == 'restore' else '0.6.6', 'platform': 'debian13', 'sha256': sha, 'size': len(data)}
        if name == 'bridge':
            bridge_sha = sha
        elif name == 'target':
            doc.update(runtime_package_protocol=2, bootstrap_sha256=bridge_sha)
        manifest = json.dumps(doc, sort_keys=True).encode()
        (bundle / 'manifest.json').write_bytes(manifest)
        signature = subprocess.run(['/usr/bin/openssl', 'pkeyutl', '-sign', '-rawin', '-inkey', str(private), '-in', str(bundle / 'manifest.json')], check=True, capture_output=True).stdout
        (bundle / 'signature.base64').write_text(base64.b64encode(signature).decode() + '\n')
        (bundle / 'signer-public.pem').write_bytes(public)
    target = json.loads((output / 'target/manifest.json').read_bytes())
    print(json.dumps({'bridge_sha256': bridge_sha, 'target_sha256': target['sha256'], 'laboratory_only': True, 'startup_fault': fault}))


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('output', type=Path)
    parser.add_argument('wheels', type=Path)
    parser.add_argument('--fault', action='store_true')
    parser.add_argument('--baseline', type=Path)
    args = parser.parse_args()
    prepare(args.output, args.wheels, args.fault, args.baseline)
