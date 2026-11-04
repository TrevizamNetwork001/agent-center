#!/usr/bin/env python3
"""Public-key age encryption, bucket-scoped S3 transport and full ciphertext readback."""
import argparse
import hashlib
import json
import logging
import os
import re
import stat
import subprocess
import sys
import tempfile
import uuid
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/backup-python'))


class BackupError(Exception):
    """A closed error code, never a remote body, key or URL."""


def private_file(path):
    path = Path(path)
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077 or info.st_uid not in (0, os.geteuid()):
        raise BackupError('private_file_permissions')
    return path


def atomic_json(path, data):
    path = Path(path)
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix='.r2-')
    try:
        with os.fdopen(fd, 'w') as stream:
            os.fchmod(stream.fileno(), 0o600)
            json.dump(data, stream, sort_keys=True)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def timestamp():
    return datetime.now(timezone.utc).isoformat()


def validate_config(config):
    account = config.get('account_id', '')
    if not re.fullmatch(r'[a-f0-9]{32}', account):
        raise BackupError('r2_account_id_required')
    endpoint = urlsplit(config.get('endpoint', ''))
    hosts = {f'{account}.r2.cloudflarestorage.com',
             f'{account}.eu.r2.cloudflarestorage.com',
             f'{account}.fedramp.r2.cloudflarestorage.com'}
    if (endpoint.scheme != 'https' or endpoint.hostname not in hosts
            or endpoint.username or endpoint.password or endpoint.port not in (None, 443)
            or endpoint.path not in ('', '/') or endpoint.query or endpoint.fragment):
        raise BackupError('r2_https_endpoint_required')
    bucket = config.get('bucket', '')
    if not re.fullmatch(r'[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]', bucket) or '..' in bucket or re.fullmatch(r'[0-9.]+', bucket):
        raise BackupError('authorized_bucket_required')
    prefix = config.get('prefix', '')
    if not re.fullmatch(r'[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*', prefix):
        raise BackupError('exclusive_prefix_required')
    if config.get('region') != 'auto':
        raise BackupError('r2_region_auto_required')
    return config


def load_config(path):
    config = json.loads(private_file(path).read_text())
    validate_config(config)
    credentials = json.loads(private_file(config['credentials_file']).read_text())
    if not all(isinstance(credentials.get(key), str) and len(credentials[key]) >= 16
               and not any(c.isspace() for c in credentials[key])
               for key in ['access_key_id', 'secret_access_key']):
        raise BackupError('r2_credentials_required')
    return config, credentials


def new_client(config, credentials):
    # Explicit credentials only: no host IAM discovery or credentials of another project.
    import boto3
    from botocore.config import Config
    logging.getLogger('boto3').setLevel(logging.CRITICAL)
    logging.getLogger('botocore').setLevel(logging.CRITICAL)
    return boto3.client('s3', endpoint_url=config['endpoint'], region_name='auto',
                        aws_access_key_id=credentials['access_key_id'],
                        aws_secret_access_key=credentials['secret_access_key'],
                        verify=True,
                        config=Config(signature_version='s3v4',
                                      connect_timeout=10, read_timeout=60,
                                      retries={'mode': 'standard', 'max_attempts': 3},
                                      request_checksum_calculation='when_required',
                                      response_checksum_validation='when_required',
                                      s3={'addressing_style': 'path'}))


def generation_members(root):
    candidates = sorted((root / 'backups/config').glob('*.sha256'))
    if not candidates:
        raise BackupError('local_generation_missing')
    manifest = candidates[-1]
    generation = manifest.stem
    if not re.fullmatch(r'\d{8}T\d{6}Z', generation):
        raise BackupError('invalid_generation')
    generated = datetime.strptime(generation, '%Y%m%dT%H%M%SZ').replace(tzinfo=timezone.utc)
    age = (datetime.now(timezone.utc) - generated).total_seconds()
    if age < 0 or age > 86400:
        raise BackupError('local_generation_stale')
    status = json.loads((root / 'backups/local-status.json').read_text())
    if (status.get('state') != 'VERDE' or status.get('generation') != generation
            or not status.get('storage_included') or status.get('project') != 'agent-center'):
        raise BackupError('complete_production_generation_required')
    members = []
    for line in manifest.read_text().splitlines():
        digest, name = line.split('  ', 1)
        path = Path(name)
        if (not re.fullmatch(r'[a-f0-9]{64}', digest) or path.is_absolute()
                or len(path.parts) != 2 or '..' in path.parts
                or path.parts[0] not in ('database', 'config', 'secrets', 'storage')
                or not path.name.startswith(generation + '.')):
            raise BackupError('invalid_manifest_member')
        source = root / 'backups' / path
        if source.is_symlink() or not source.is_file():
            raise BackupError('invalid_manifest_member')
        with source.open('rb') as stream:
            actual = hashlib.file_digest(stream, 'sha256').hexdigest()
        if actual != digest:
            raise BackupError('local_integrity_failed')
        members.append(name)
    if len(members) != len(set(members)) or not all(
            any(name.startswith(category + '/') for name in members)
            for category in ['database', 'config', 'secrets', 'storage']):
        raise BackupError('local_coverage_incomplete')
    return generation, [*members, 'config/' + manifest.name]


def encrypt_generation(root, config, members, output):
    recipient = Path(config['recipient_file'])
    lines = [line.strip() for line in recipient.read_text().splitlines()
             if line.strip() and not line.startswith('#')]
    # Native X25519 recipients only; never accept a recovery identity or passphrase file.
    if not lines or len(lines) > 5 or any(not re.fullmatch(r'age1[a-z0-9]{58}', line) for line in lines):
        raise BackupError('public_age_recipient_required')
    executable = root / 'tools/usr/bin/age'
    if not executable.is_file():
        raise BackupError('age_tool_missing')
    producer = subprocess.Popen(['tar', '-czf', '-', '-C', str(root / 'backups'), '--', *members],
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    try:
        result = subprocess.run([str(executable), '--encrypt', '--recipients-file', str(recipient),
                                 '--output', str(output)], stdin=producer.stdout,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        producer.stdout.close()
        if result.returncode or producer.wait():
            raise BackupError('authenticated_encryption_failed')
    finally:
        if producer.poll() is None:
            producer.kill()
            producer.wait()
    output.chmod(0o600)
    with output.open('rb') as stream:
        digest = hashlib.file_digest(stream, 'sha256').hexdigest()
    return digest, output.stat().st_size


def tiers_for(generated):
    tiers = ['daily']
    if generated.weekday() == 6:
        tiers.append('weekly')
    if generated.day == 1:
        tiers.append('monthly')
    return tiers


def object_keys(config, generation, unique=None):
    generated = datetime.strptime(generation, '%Y%m%dT%H%M%SZ').replace(tzinfo=timezone.utc)
    suffix = unique or uuid.uuid4().hex
    if not re.fullmatch(r'[a-f0-9]{32}', suffix):
        raise BackupError('invalid_object_nonce')
    return [f"{config['prefix']}/{tier}/{generated:%Y/%m/%d}/{generation}-{suffix}.tar.gz.age"
            for tier in tiers_for(generated)]


def verify_remote(client, bucket, key, digest, size, destination=None):
    head = client.head_object(Bucket=bucket, Key=key)
    if head.get('ContentLength') != size or head.get('Metadata', {}).get('sha256') != digest:
        raise BackupError('remote_head_integrity_failed')
    result = client.get_object(Bucket=bucket, Key=key)
    body = result['Body']
    checksum, received = hashlib.sha256(), 0
    output = None
    try:
        if result.get('ContentLength') != size:
            raise BackupError('remote_get_size_failed')
        if destination is not None:
            fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            output = os.fdopen(fd, 'wb')
        while chunk := body.read(1024 * 1024):
            received += len(chunk)
            if received > size:
                raise BackupError('remote_download_oversize')
            checksum.update(chunk)
            if output:
                output.write(chunk)
        if received != size or checksum.hexdigest() != digest:
            raise BackupError('remote_download_integrity_failed')
        if output:
            output.flush()
            os.fsync(output.fileno())
    except Exception:
        if output:
            output.close()
            Path(destination).unlink(missing_ok=True)
        raise
    finally:
        body.close()
        if output:
            output.close()
    return {'key': key, 'bytes': size, 'sha256': digest, 'head': True,
            'download': True, 'integrity': True, 'verified_at': timestamp()}


def upload(client, config, archive, generation, digest, size, progress=None):
    if size > 5 * 1024**3:
        raise BackupError('single_put_limit_exceeded')
    proofs = []
    for key in object_keys(config, generation):
        with archive.open('rb') as stream:
            # UUID plus a conditional PUT prevents replacing any existing object.
            client.put_object(Bucket=config['bucket'], Key=key, Body=stream,
                              ContentLength=size, ContentType='application/octet-stream',
                              Metadata={'sha256': digest, 'encryption': 'age-v1'},
                              IfNoneMatch='*')
        proofs.append(verify_remote(client, config['bucket'], key, digest, size))
        if progress:
            progress(proofs)
    return proofs


def status_update(root, state, reason, **fields):
    atomic_json(root / 'backups/external-status.json',
                dict(state=state, reason=reason, checked_at=timestamp(), backend='r2', **fields))


def run(root, config, credentials):
    generation, members = generation_members(root)
    stages = {'backup_executed': True, 'encrypted': False, 'upload_completed': False,
              'integrity_verified': False, 'retention_verified': False,
              'independent_restore_verified': False, 'external_key_custody_verified': False}
    status_update(root, 'ATENÇÃO', 'r2_backup_in_progress', generation=generation,
                  project='agent-center', stages=stages)
    receipt_path = root / 'backups/r2-receipts' / (generation + '-' + uuid.uuid4().hex + '.json')
    # No recovery identities are read, created or mounted by this program.
    with tempfile.TemporaryDirectory(prefix='r2-ciphertext-', dir=root / 'backups') as temporary:
        os.chmod(temporary, 0o700)
        archive = Path(temporary) / (generation + '.tar.gz.age')
        digest, size = encrypt_generation(root, config, members, archive)
        stages['encrypted'] = True
        status_update(root, 'ATENÇÃO', 'r2_transfer_in_progress', generation=generation,
                      project='agent-center', stages=stages)
        def progress(proofs):
            atomic_json(receipt_path, dict(generation=generation, bucket=config['bucket'],
                        endpoint=config['endpoint'], stages=stages, objects=proofs,
                        complete=False, sha256=digest, bytes=size))
        client = new_client(config, credentials)
        try:
            proofs = upload(client, config, archive, generation, digest, size, progress)
        finally:
            client.close()
    stages.update(upload_completed=True, integrity_verified=True)
    receipt = dict(generation=generation, bucket=config['bucket'], endpoint=config['endpoint'],
                   project='agent-center', encryption='age-v1', stages=stages, objects=proofs,
                   sha256=digest, bytes=size, verified_at=timestamp())
    atomic_json(receipt_path, receipt)
    status_update(root, 'VERDE', 'r2_encrypted_upload_download_verified', generation=generation,
                  project='agent-center', sha256=digest, bytes=size, stages=stages,
                  retention_verified=False, restore_from_external_verified=False)
    print('R2 upload, HEAD and full download integrity: PASS; independent restore remains required.')
    return receipt


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', type=Path, default=ROOT / 'secrets/r2-backup.json')
    args = parser.parse_args()
    if not args.config.exists():
        status_update(ROOT, 'ATENÇÃO', 'EXTERNAL BACKUP NOT CONFIGURED')
        print('EXTERNAL BACKUP NOT CONFIGURED: R2 credentials, bucket and public recipient required.')
        return 2
    status_update(ROOT, 'ATENÇÃO', 'r2_precheck_in_progress', stages={})
    try:
        config, credentials = load_config(args.config)
        run(ROOT, config, credentials)
    except Exception:
        previous = json.loads((ROOT / 'backups/external-status.json').read_text())
        stages = previous.get('stages', {})
        stages.update(upload_completed=False, integrity_verified=False)
        status_update(ROOT, 'NO-GO', 'r2_backup_failed', stages=stages)
        # Deliberately exclude SDK exceptions, URLs, request headers and provider response bodies.
        print('R2 backup FAILED; sensitive diagnostics withheld. Use protected configuration checks.')
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
