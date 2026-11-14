"""Recover exact 0.6.5 sources from the locally archived, pinned signed production release.

Never contacts the operational Agent and never uses a quarantined release.
"""
import base64
import hashlib
import io
import json
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'agent/src'))
from agent_center.lifecycle.releases import verify

EXPECTED = 'a2de84beb6cb519047c6123423fb420d9d410b41b85c6c9b4a49df63b562d315'
ANCHOR = '5f16350383c280c1b39d4f7257f09ecbe1002b1d59a1721ceb8bf4d5091195e1'


def recover(source, destination):
    public = (source / 'release-public.pem').read_bytes()
    der = base64.b64decode(b''.join(public.splitlines()[1:-1]), validate=True)
    if len(der) != 44 or der[:12].hex() != '302a300506032b6570032100' or hashlib.sha256(der[-32:]).hexdigest() != ANCHOR:
        raise ValueError('untrusted_operational_anchor')
    artifact = (source / 'agent.pyz').read_bytes()
    if hashlib.sha256(artifact).hexdigest() != EXPECTED:
        raise ValueError('published_artifact_mismatch')
    doc = verify((source / 'manifest.json').read_bytes(), (source / 'signature.base64').read_text().strip(), public, artifact, minimum_sequence=5, platform='debian13')
    if doc['version'] != '0.6.5' or doc['sequence'] != 6:
        raise ValueError('unexpected_operational_release')
    destination.mkdir(parents=True, exist_ok=False)
    hashes, comparison = {}, {}
    with zipfile.ZipFile(io.BytesIO(artifact)) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)):
            raise ValueError('duplicate_source')
        for name in names:
            path = Path(name)
            if path.is_absolute() or '..' in path.parts or path.suffix != '.py' or (name != '__main__.py' and path.parts[0] != 'agent_center'):
                raise ValueError('unexpected_source_member')
            data = archive.read(name)
            compile(data, name, 'exec')
            hashes[name] = hashlib.sha256(data).hexdigest()
            if name == '__main__.py':
                continue
            target = destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
            local = ROOT / 'agent/src' / name
            comparison[name] = 'identical' if local.is_file() and local.read_bytes() == data else ('different' if local.is_file() else 'absent_locally')
    report = {'version': doc['version'], 'sequence': doc['sequence'], 'artifact_sha256': EXPECTED, 'public_key_raw_sha256': ANCHOR, 'signature_verified': True, 'source_from_exact_archive': True, 'source_members': hashes, 'comparison_to_local': comparison, 'quarantined_sequences_not_used': [3, 4], 'operational_agent_contacted': False}
    return report


if __name__ == '__main__':
    report = recover(Path('/root/preflight-production-final-private/release-065-seq6'), ROOT / 'agent/candidates/routeros-operational-v1/base')
    (ROOT / 'agent/candidates/routeros-operational-v1/provenance.json').write_text(json.dumps(report, indent=2)+'\n')
    print(json.dumps({'signature_verified': True, 'version':'0.6.5', 'members':len(report['source_members']), 'comparison':report['comparison_to_local']},indent=2))
