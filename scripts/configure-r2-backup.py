#!/usr/bin/env python3
"""Run by the operator over SSH in a real terminal. Secrets never echo or enter argv."""
import getpass
import os
import re
import sys
import warnings
from pathlib import Path

from backup_r2 import atomic_json, validate_config


def prompt(label):
    return getpass.getpass(label + ': ').strip()


def main():
    if not sys.stdin.isatty():
        print('Real interactive terminal required; do not pipe credentials or pass them as arguments.')
        return 1
    warnings.simplefilter('error', getpass.GetPassWarning)
    root = Path(__file__).resolve().parents[1]
    secret_dir = root / 'secrets'
    secret_dir.mkdir(mode=0o700, exist_ok=True)
    secret_dir.chmod(0o700)
    config_file = secret_dir / 'r2-backup.json'
    credentials_file = secret_dir / 'r2-credentials.json'
    recipient_file = secret_dir / 'backup-recipient.txt'
    if any(path.exists() for path in [config_file, credentials_file, recipient_file]):
        print('Existing configuration preserved. Review/replace through protected operator access.')
        return 1
    account = prompt('Cloudflare Account ID')
    bucket = prompt('Authorized existing R2 bucket (agent-center)')
    if bucket != 'agent-center':
        print('This operation is authorized only for bucket agent-center.')
        return 1
    jurisdiction = prompt('Jurisdiction global/eu/fedramp')
    if jurisdiction not in ['global', 'eu', 'fedramp']:
        return 1
    suffix = '' if jurisdiction == 'global' else jurisdiction + '.'
    prefix = prompt('Exclusive authorized prefix, e.g. agent-center')
    recipient = prompt('Public age recipient generated and held outside this VPS')
    if not re.fullmatch(r'age1[a-z0-9]{58}', recipient):
        print('Native public age recipient required; never enter a recovery private key.')
        return 1
    access = prompt('R2 Access Key ID, bucket-scoped')
    secret = prompt('R2 Secret Access Key')
    if any(len(value) < 16 or any(c.isspace() for c in value) for value in [access, secret]):
        return 1
    config = dict(account_id=account, endpoint=f'https://{account}.{suffix}r2.cloudflarestorage.com',
                  bucket=bucket, prefix=prefix, region='auto',
                  credentials_file=str(credentials_file), recipient_file=str(recipient_file))
    validate_config(config)
    # One file per secret; no secrets in stdout, shell history, process arguments or logs.
    fd = os.open(recipient_file, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as stream:
        stream.write(recipient + '\n')
    atomic_json(credentials_file, {'access_key_id': access, 'secret_access_key': secret})
    atomic_json(config_file, config)
    print('Protected R2 configuration written. No upload performed; custody/retention/restore still unverified.')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (Exception, KeyboardInterrupt):
        print('Configuration not completed; sensitive details withheld.')
        raise SystemExit(1) from None
