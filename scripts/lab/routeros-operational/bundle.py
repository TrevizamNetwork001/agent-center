"""Build a deterministic offline SSH wheel bundle; never install or contact devices."""
import hashlib
import json
import tarfile
from email.parser import BytesParser
from io import BytesIO
from pathlib import Path
from zipfile import ZipFile

PINS = {"paramiko": "3.5.1", "cryptography": "46.0.3", "bcrypt": "5.0.0",
        "pynacl": "1.6.0", "cffi": "2.0.0", "pycparser": "2.23"}


def inventory(directory):
    packages = {}
    for path in sorted(directory.glob('*.whl')):
        with ZipFile(path) as wheel:
            names = wheel.namelist()
            metadata = [name for name in names if name.endswith('.dist-info/METADATA')]
            if len(metadata) != 1:
                raise ValueError('invalid_wheel_metadata')
            doc = BytesParser().parsebytes(wheel.read(metadata[0]))
            name, version = doc['Name'].lower(), doc['Version']
            if PINS.get(name) != version or name in packages:
                raise ValueError('unexpected_dependency')
            licenses = [n for n in names if 'license' in n.lower() or 'copying' in n.lower()]
            if not licenses:
                raise ValueError('missing_license')
            packages[name] = {'version': version, 'wheel': path.name,
                'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
                'bytes': path.stat().st_size, 'requires_python': doc['Requires-Python'],
                'requires_dist': doc.get_all('Requires-Dist', []),
                'license_files': {n: hashlib.sha256(wheel.read(n)).hexdigest() for n in licenses}}
    if set(packages) != set(PINS):
        raise ValueError('incomplete_dependency_set')
    return {'schema_version': 1, 'platform': 'debian13-amd64', 'python': '3.13',
            'packages': packages, 'lifecycle_installation_validated': False}


def build(directory, output, lock):
    doc = inventory(directory)
    if lock.exists() and json.loads(lock.read_text()) != doc:
        raise ValueError('dependency_lock_mismatch')
    lock_data = (json.dumps(doc, indent=2, sort_keys=True)+'\n').encode()
    requirements = ''.join(f"{name}=={p['version']} --hash=sha256:{p['sha256']}\n"
                           for name, p in sorted(doc['packages'].items())).encode()
    with tarfile.open(output, 'w', format=tarfile.USTAR_FORMAT) as archive:
        files = {'lock.json': lock_data, 'requirements.txt': requirements}
        files.update({p['wheel']: (directory / p['wheel']).read_bytes()
                      for p in doc['packages'].values()})
        for name, data in sorted(files.items()):
            info = tarfile.TarInfo(name)
            info.size, info.mode, info.mtime = len(data), 0o644, 0
            archive.addfile(info, BytesIO(data))
    lock.write_bytes(lock_data)
    return hashlib.sha256(output.read_bytes()).hexdigest()


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('wheels', type=Path)
    parser.add_argument('output', type=Path)
    parser.add_argument('lock', type=Path)
    args = parser.parse_args()
    print(build(args.wheels, args.output, args.lock))
