#!/usr/bin/env python3
"""Encrypt a verified generation before HTTPS PUT; read back every byte. No deletes."""
import argparse
import hashlib
import http.client
import json
import os
import re
import ssl
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]


def private_file(path):
    path = Path(path)
    if not path.is_file() or path.stat().st_mode & 0o077:
        raise ValueError('private_file_permissions')
    return path


def destination(url):
    parts = urlsplit(url)
    if (parts.scheme != 'https' or not parts.hostname or parts.username
            or parts.password or parts.query or parts.fragment):
        raise ValueError('https_destination_required')
    return parts


def record(state, reason, **fields):
    target = ROOT / 'backups/external-status.json'
    target.parent.mkdir(mode=0o700, exist_ok=True)
    data = dict(state=state, reason=reason,
                checked_at=datetime.now(timezone.utc).isoformat(), **fields)
    fd, temporary = tempfile.mkstemp(dir=target.parent)
    with os.fdopen(fd, 'w') as out:
        os.fchmod(out.fileno(), 0o600)
        json.dump(data, out)
    os.replace(temporary, target)


def transfer(url, token, archive, ca_file=None):
    parts = destination(url)
    context = ssl.create_default_context(cafile=ca_file)
    # No redirects, TLS bypass, HTTP fallback, DELETE, arbitrary commands or credentials in argv.
    connection = http.client.HTTPSConnection(parts.hostname, parts.port or 443,
                                           timeout=60, context=context)
    checksum = hashlib.sha256()
    with archive.open('rb') as stream:
        while chunk := stream.read(1024 * 1024):
            checksum.update(chunk)
    digest = checksum.hexdigest()
    size = archive.stat().st_size
    try:
        connection.putrequest('PUT', parts.path)
        connection.putheader('Authorization', 'Bearer ' + token)
        connection.putheader('Content-Type', 'application/octet-stream')
        connection.putheader('Content-Length', str(size))
        connection.putheader('If-None-Match', '*')
        connection.endheaders()
        with archive.open('rb') as stream:
            while chunk := stream.read(1024 * 1024):
                connection.send(chunk)
        response = connection.getresponse()
        if response.status not in (200, 201, 204):
            raise ValueError('upload_rejected')
        response.read()
    finally:
        connection.close()
    connection = http.client.HTTPSConnection(parts.hostname, parts.port or 443,
                                           timeout=60, context=context)
    try:
        connection.request('GET', parts.path, headers={'Authorization': 'Bearer ' + token})
        response = connection.getresponse()
        if response.status != 200:
            raise ValueError('readback_rejected')
        remote = hashlib.sha256()
        remote_size = 0
        while chunk := response.read(1024 * 1024):
            remote_size += len(chunk)
            if remote_size > size:
                raise ValueError('readback_size_mismatch')
            remote.update(chunk)
        if remote_size != size or remote.hexdigest() != digest:
            raise ValueError('readback_integrity_mismatch')
    finally:
        connection.close()
    return digest, size


def export(config):
    destination(config['base_url'])
    token = private_file(config['token_file']).read_text().strip()
    if not token or '\r' in token or '\n' in token:
        raise ValueError('invalid_token_file')
    recipient = Path(config['recipient_certificate'])
    # There must be no recipient private key on this host or inside this backup.
    if b'PRIVATE KEY' in recipient.read_bytes():
        raise ValueError('public_certificate_only')
    manifests = sorted((ROOT / 'backups/config').glob('*.sha256'))
    if not manifests:
        raise ValueError('backup_generation_missing')
    manifest = manifests[-1]
    stamp = manifest.stem
    if not re.fullmatch(r'\d{8}T\d{6}Z', stamp):
        raise ValueError('invalid_generation')
    generated = datetime.strptime(stamp, '%Y%m%dT%H%M%SZ').replace(tzinfo=timezone.utc)
    if (datetime.now(timezone.utc) - generated).total_seconds() > 86400:
        raise ValueError('backup_generation_stale')
    members = []
    for line in manifest.read_text().splitlines():
        digest, name = line.split('  ', 1)
        path = Path(name)
        if path.is_absolute() or '..' in path.parts or len(path.parts) != 2:
            raise ValueError('invalid_manifest_path')
        if path.parts[0] not in ('database', 'config', 'secrets', 'storage'):
            raise ValueError('invalid_manifest_category')
        with (ROOT / 'backups' / path).open('rb') as stream:
            actual = hashlib.file_digest(stream, 'sha256').hexdigest()
        if actual != digest:
            raise ValueError('local_integrity_mismatch')
        members.append(name)
    if not all(any(n.startswith(c + '/') for n in members)
               for c in ('database', 'config', 'secrets', 'storage')):
        raise ValueError('backup_coverage_incomplete')
    members.append('config/' + manifest.name)
    with tempfile.TemporaryDirectory(prefix='export-', dir=ROOT / 'backups') as temporary:
        os.chmod(temporary, 0o700)
        archive = Path(temporary) / (stamp + '.cms')
        producer = subprocess.Popen(['tar', '-czf', '-', '-C', str(ROOT / 'backups'),
                                     '--', *members], stdout=subprocess.PIPE,
                                    stderr=subprocess.DEVNULL)
        try:
            result = subprocess.run(['openssl', 'cms', '-encrypt', '-binary', '-aes-256-cbc',
                                     '-outform', 'DER', '-out', str(archive), str(recipient)],
                                    stdin=producer.stdout, stderr=subprocess.DEVNULL)
            producer.stdout.close()
            if result.returncode or producer.wait():
                raise ValueError('encryption_failed')
        finally:
            if producer.poll() is None:
                producer.kill()
                producer.wait()
        os.chmod(archive, 0o600)
        url = config['base_url'].rstrip('/') + '/' + archive.name
        digest, size = transfer(url, token, archive, config.get('tls_ca_file'))
    local_status_path = ROOT / 'backups/local-status.json'
    local_status = json.loads(local_status_path.read_text()) if local_status_path.exists() else {}
    project = local_status.get('project', 'unproven') if local_status.get('generation') == stamp else 'unproven'
    record('VERDE', 'encrypted_upload_readback_verified', generation=stamp, project=project,
           sha256=digest, bytes=size,
           retention_verified=False, restore_from_external_verified=False)
    print('External encrypted upload and complete readback: PASS; external restore still required.')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', type=Path, default=ROOT / 'secrets/external-backup.json')
    args = parser.parse_args()
    if not args.config.exists():
        record('ATENÇÃO', 'EXTERNAL BACKUP NOT CONFIGURED')
        print('EXTERNAL BACKUP NOT CONFIGURED')
        return 2
    try:
        config = json.loads(private_file(args.config).read_text())
        export(config)
    except Exception:
        # Do not log URLs, credentials, response bodies, command output or exception messages.
        record('NO-GO', 'external_backup_failed')
        print('External backup failed; consult configuration and provider through protected access.')
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
