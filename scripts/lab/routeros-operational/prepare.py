"""Build/sign a disposable laboratory candidate only. No publication or remote calls."""
import base64
import importlib.util
import json
import os
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
CANDIDATE = ROOT / 'agent/candidates/routeros-operational-v1'


def prepare(output, wheels):
    output = Path(output)
    output.mkdir(mode=0o755, parents=True, exist_ok=False)
    spec = importlib.util.spec_from_file_location('candidate_build', CANDIDATE / 'packaging/build.py')
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    digest, source = builder.build(output / 'agent.pyz', wheels=wheels)
    payload = json.dumps({'schema_version': 1, 'sequence': 2, 'version': '0.6.6',
                          'platform': 'debian13', 'sha256': digest,
                          'size': (output / 'agent.pyz').stat().st_size}, sort_keys=True).encode()
    (output / 'manifest.json').write_bytes(payload)
    # Keep the throwaway lab key outside the distributable bundle, mode 0600.
    private = output.parent / (output.name + '-lab-signing.pem')
    fd = os.open(private, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    os.close(fd)
    subprocess.run(['/usr/bin/openssl', 'genpkey', '-algorithm', 'ED25519', '-out', str(private)],
                   check=True, capture_output=True)
    subprocess.run(['/usr/bin/openssl', 'pkey', '-in', str(private), '-pubout', '-out',
                    str(output / 'signer-public.pem')], check=True, capture_output=True)
    signed = subprocess.run(['/usr/bin/openssl', 'pkeyutl', '-sign', '-rawin', '-inkey',
                             str(private), '-in', str(output / 'manifest.json')],
                            check=True, capture_output=True).stdout
    (output / 'signature.base64').write_text(base64.b64encode(signed).decode() + '\n')
    print(json.dumps({'sha256': digest, 'source_sha256': source, 'laboratory_only': True}))


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('output', type=Path)
    parser.add_argument('wheels', type=Path)
    args = parser.parse_args()
    prepare(args.output, args.wheels)
